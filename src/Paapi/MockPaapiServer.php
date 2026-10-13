<?php

declare(strict_types=1);

namespace DealRadar\Paapi;

use DateTimeImmutable;
use DateTimeZone;
use DealRadar\Http\HttpRequest;
use DealRadar\Http\HttpResponse;
use DealRadar\Http\Transport;

/**
 * PA-API 5.0 の SearchItems を模したデモ用サーバー（Transport として差し込む）。
 *
 * 本物の API と同じく、受け取ったリクエストの SigV4 署名を検証してから応答する。
 * レスポンスは fixtures/paapi/catalog.json の架空商品から PA-API 5.0 と同じ JSON 構造で生成し、
 * 価格は1時間ごとに変動させる（毎回の実行で新しい割引・値下げが発生するように）。
 */
final class MockPaapiServer implements Transport
{
    /** @var list<array<string, mixed>> */
    private array $catalog;

    /** @var array<string, int> スロットル再現用：リクエストごとの試行回数 */
    private array $attempts = [];

    /**
     * @param string|null $simulateError 'throttle'＝初回だけ TooManyRequests、SearchIndex 名＝そのカテゴリーで常に失敗
     */
    public function __construct(
        private readonly PaapiConfig $config,
        ?string $catalogPath = null,
        private readonly ?string $priceSeed = null,
        private readonly ?string $simulateError = null,
    ) {
        $path = $catalogPath ?? dirname(__DIR__, 2) . '/fixtures/paapi/catalog.json';
        $this->catalog = json_decode((string) file_get_contents($path), true)['items'];
    }

    public function send(HttpRequest $request): HttpResponse
    {
        $path = (string) parse_url($request->url, PHP_URL_PATH);
        if ($request->method !== 'POST' || $path !== '/paapi5/searchitems') {
            return self::error(404, 'UnknownOperation', 'The requested operation is not supported.');
        }
        if (!$this->signatureIsValid($request, $path)) {
            return self::error(401, 'InvalidSignature', 'The request has not been correctly signed.');
        }

        $payload = json_decode($request->body, true) ?? [];
        $searchIndex = (string) ($payload['SearchIndex'] ?? 'All');

        if ($this->simulateError === 'throttle') {
            $key = md5($request->body);
            $this->attempts[$key] = ($this->attempts[$key] ?? 0) + 1;
            if ($this->attempts[$key] === 1) {
                return self::error(429, 'TooManyRequests', 'The request was denied due to request throttling.');
            }
        } elseif ($this->simulateError !== null && $this->simulateError !== '' && strcasecmp($this->simulateError, $searchIndex) === 0) {
            return self::error(500, 'InternalFailure', 'The request processing has failed because of an unknown error.');
        }

        return new HttpResponse(200, (string) json_encode($this->searchItems($payload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), [
            'content-type' => 'application/json',
            'x-mock-signature-check' => 'SigV4 署名を検証済み（MockPaapiServer）',
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function searchItems(array $payload): array
    {
        $searchIndex = (string) ($payload['SearchIndex'] ?? 'All');
        $keywords = trim((string) ($payload['Keywords'] ?? ''));
        $minSaving = (int) ($payload['MinSavingPercent'] ?? 0);
        $minPrice = isset($payload['MinPrice']) ? (int) $payload['MinPrice'] : null;
        $maxPrice = isset($payload['MaxPrice']) ? (int) $payload['MaxPrice'] : null;
        $page = max(1, (int) ($payload['ItemPage'] ?? 1));
        $count = max(1, min(10, (int) ($payload['ItemCount'] ?? 10)));
        $tag = (string) ($payload['PartnerTag'] ?? $this->config->partnerTag);

        $items = [];
        foreach ($this->catalog as $entry) {
            if ($searchIndex !== 'All' && $entry['searchIndex'] !== $searchIndex) {
                continue;
            }
            if ($keywords !== '' && !self::matchesKeywords($entry, $keywords)) {
                continue;
            }

            $item = $this->buildItem($entry, $tag);
            $listing = $item['Offers']['Listings'][0] ?? null;
            $price = $listing['Price']['Amount'] ?? null;
            $percentage = $listing['Price']['Savings']['Percentage'] ?? 0;

            if ($minSaving > 0 && $percentage < $minSaving) {
                continue;
            }
            if ($price !== null && (($minPrice !== null && $price < $minPrice) || ($maxPrice !== null && $price > $maxPrice))) {
                continue;
            }
            $items[] = $item;
        }

        $total = count($items);
        $pageItems = array_slice($items, ($page - 1) * $count, $count);
        if ($pageItems === []) {
            return ['Errors' => [['Code' => 'NoResults', 'Message' => 'No results found for your request.']]];
        }

        return [
            'SearchResult' => [
                'Items' => $pageItems,
                'SearchURL' => 'https://www.amazon.co.jp/s?i=' . rawurlencode($searchIndex) . '&tag=' . rawurlencode($tag),
                'TotalResultCount' => $total,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $entry
     * @return array<string, mixed>
     */
    private function buildItem(array $entry, string $tag): array
    {
        $asin = $entry['asin'];
        $item = [
            'ASIN' => $asin,
            'DetailPageURL' => "https://www.amazon.co.jp/dp/{$asin}?tag={$tag}&linkCode=ogi&th=1&psc=1",
            'ItemInfo' => [
                'ByLineInfo' => ['Brand' => ['DisplayValue' => $entry['brand'], 'Label' => 'Brand', 'Locale' => 'ja_JP']],
                'Title' => ['DisplayValue' => $entry['title'], 'Label' => 'Title', 'Locale' => 'ja_JP'],
            ],
        ];
        if (!empty($entry['outOfStock'])) {
            return $item;
        }

        $list = (int) $entry['listPrice'];
        $discount = $this->discountFor($asin, $entry['discountBand']);
        $price = (int) (round($list * (100 - $discount) / 100 / 10) * 10);

        $listing = [
            'Id' => strtoupper(substr(md5($asin), 0, 24)),
            'ViolatesMAP' => false,
            'Price' => ['Amount' => $price, 'Currency' => 'JPY', 'DisplayAmount' => self::yen($price)],
        ];

        // 参考価格を持たない商品（noBasis）は、値引き中でも SavingBasis が返らない
        if ($price < $list && empty($entry['noBasis'])) {
            $savings = $list - $price;
            $percent = (int) round($savings / $list * 100);
            $listing['Price']['Savings'] = ['Amount' => $savings, 'Currency' => 'JPY', 'DisplayAmount' => self::yen($savings) . " ({$percent}%)", 'Percentage' => $percent];
            $listing['SavingBasis'] = ['Amount' => $list, 'Currency' => 'JPY', 'DisplayAmount' => self::yen($list), 'PriceType' => 'LIST_PRICE', 'PriceTypeLabel' => '参考価格'];
        }
        $item['Offers'] = ['Listings' => [$listing]];

        return $item;
    }

    /**
     * 価格スロット（既定は1時間単位）と ASIN から決まる疑似乱数で割引率を決める。
     * 同じ時間帯なら何度実行しても同じ価格になる。
     *
     * @param array{0: int, 1: int} $band
     */
    private function discountFor(string $asin, array $band): int
    {
        $slot = $this->priceSeed ?? (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format('YmdH');
        $hash = crc32("{$asin}|{$slot}");

        // 約2割の時間帯は値引きなし
        if ($hash % 100 < 20) {
            return 0;
        }

        [$low, $high] = $band;

        return $low + intdiv($hash, 100) % ($high - $low + 1);
    }

    private function signatureIsValid(HttpRequest $request, string $path): bool
    {
        $authorization = $request->header('Authorization');
        $amzDate = $request->header('X-Amz-Date');
        if ($authorization === null || $amzDate === null) {
            return false;
        }

        $headers = array_values(array_filter($request->headers, static fn (array $h): bool => strcasecmp($h[0], 'Authorization') !== 0));
        $time = DateTimeImmutable::createFromFormat('Ymd\THis\Z', $amzDate, new DateTimeZone('UTC'));
        if ($time === false) {
            return false;
        }

        $signer = new SigV4Signer($this->config->accessKey, $this->config->secretKey, $this->config->region, PaapiClient::SERVICE);
        $expected = $signer->sign('POST', $path, '', $headers, $request->body, $time);

        return hash_equals($expected->authorization, $authorization);
    }

    /** @param array<string, mixed> $entry */
    private static function matchesKeywords(array $entry, string $keywords): bool
    {
        $haystack = mb_strtolower($entry['title'] . ' ' . $entry['brand']);
        foreach (preg_split('/[\s　]+/u', mb_strtolower($keywords)) ?: [] as $word) {
            if ($word !== '' && !str_contains($haystack, $word)) {
                return false;
            }
        }

        return true;
    }

    private static function yen(int $amount): string
    {
        return '￥' . number_format($amount);
    }

    private static function error(int $status, string $code, string $message): HttpResponse
    {
        return new HttpResponse($status, (string) json_encode([
            '__type' => "com.amazon.paapi5#{$code}Exception",
            'Errors' => [['Code' => $code, 'Message' => $message]],
        ]), ['content-type' => 'application/json']);
    }
}
