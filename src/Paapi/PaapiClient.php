<?php

declare(strict_types=1);

namespace DealRadar\Paapi;

use Closure;
use DateTimeImmutable;
use DealRadar\Domain\Rule;
use DealRadar\Http\HttpRequest;
use DealRadar\Http\Transport;
use DealRadar\Http\TransportException;

/**
 * PA-API 5.0 SearchItems の呼び出し。
 *
 * - SigV4 で署名して送信
 * - 1秒1リクエストの制限を守るため、呼び出し間隔を空ける
 * - TooManyRequests／5xx は指数バックオフで再試行
 */
final class PaapiClient
{
    public const SERVICE = 'ProductAdvertisingAPI';
    public const ITEMS_PER_PAGE = 10;
    public const RESOURCES = [
        'BrowseNodeInfo.BrowseNodes',
        'Images.Primary.Medium',
        'ItemInfo.ByLineInfo',
        'ItemInfo.Title',
        'Offers.Listings.Price',
        'Offers.Listings.SavingBasis',
    ];

    private float $lastRequestAt = 0.0;

    /** @var array<string, mixed>|null ダッシュボード表示用の直近のやり取り */
    private ?array $lastExchange = null;

    private readonly Closure $sleep;
    private readonly Closure $clock;

    public function __construct(
        private readonly PaapiConfig $config,
        private readonly Transport $transport,
        private readonly float $minIntervalSeconds = 1.0,
        private readonly int $maxRetries = 3,
        ?Closure $sleep = null,
        ?Closure $clock = null,
    ) {
        $this->sleep = $sleep ?? static fn (float $seconds) => usleep((int) ($seconds * 1_000_000));
        $this->clock = $clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable();
    }

    /** @return array<string, mixed> SearchItems のレスポンス（JSON をデコードしたもの） */
    public function searchItems(Rule $rule, int $page = 1): array
    {
        $payload = $this->buildSearchItemsPayload($rule, $page);

        for ($attempt = 0; ; $attempt++) {
            try {
                return $this->call('SearchItems', $payload);
            } catch (PaapiException|TransportException $e) {
                $retryable = $e instanceof TransportException || $e->isRetryable();
                if (!$retryable || $attempt >= $this->maxRetries) {
                    throw $e;
                }
                ($this->sleep)(2 ** $attempt);
            }
        }
    }

    /** @return array<string, mixed> */
    public function buildSearchItemsPayload(Rule $rule, int $page): array
    {
        $payload = [
            'SearchIndex' => $rule->searchIndex !== '' ? $rule->searchIndex : 'All',
            'ItemCount' => self::ITEMS_PER_PAGE,
            'ItemPage' => $page,
            'Resources' => self::RESOURCES,
            'PartnerTag' => $this->config->partnerTag,
            'PartnerType' => 'Associates',
            'Marketplace' => $this->config->marketplace,
        ];
        if ($rule->keywords !== '') {
            $payload['Keywords'] = $rule->keywords;
        }
        if ($rule->browseNodeId !== '') {
            $payload['BrowseNodeId'] = $rule->browseNodeId;
        }
        // 割引率の下限は API 側でも絞り込み、取得件数とリクエスト数を節約する
        if ($rule->minDiscount >= 1) {
            $payload['MinSavingPercent'] = (int) floor($rule->minDiscount);
        }
        // MinPrice／MaxPrice は通貨の最小単位（円）で指定する
        if ($rule->minPrice !== null) {
            $payload['MinPrice'] = $rule->minPrice;
        }
        if ($rule->maxPrice !== null) {
            $payload['MaxPrice'] = $rule->maxPrice;
        }

        return $payload;
    }

    /** @return array<string, mixed>|null */
    public function lastExchange(): ?array
    {
        return $this->lastExchange;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function call(string $operation, array $payload): array
    {
        $this->throttle();

        $body = (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $headers = [
            ['host', $this->config->host],
            ['content-type', 'application/json; charset=utf-8'],
            ['content-encoding', 'amz-1.0'],
            ['x-amz-target', 'com.amazon.paapi5.v1.ProductAdvertisingAPIv1.' . $operation],
        ];

        $signer = new SigV4Signer($this->config->accessKey, $this->config->secretKey, $this->config->region, self::SERVICE);
        $path = '/paapi5/' . strtolower($operation);
        $signed = $signer->sign('POST', $path, '', $headers, $body, ($this->clock)());

        $response = $this->transport->send(new HttpRequest('POST', $this->config->endpoint($operation), $signed->headers, $body));
        $json = $response->json() ?? [];

        $this->lastExchange = [
            'operation' => $operation,
            'endpoint' => $this->config->endpoint($operation),
            'headers' => array_map(fn (array $h): array => [$h[0], $this->mask($h[1])], $signed->headers),
            'payload' => $payload,
            'canonicalRequest' => $signed->canonicalRequest,
            'stringToSign' => $signed->stringToSign,
            'authorization' => $this->mask($signed->authorization),
            'status' => $response->status,
            'verifiedBy' => $response->headers['x-mock-signature-check'] ?? null,
            'itemCount' => count($json['SearchResult']['Items'] ?? []),
            'at' => ($this->clock)()->format(DATE_ATOM),
        ];

        $error = $json['Errors'][0] ?? null;
        if (!$response->isSuccess() || $error !== null) {
            $code = (string) ($error['Code'] ?? 'HttpError');
            // 該当商品なしはエラーではなく0件として扱う
            if ($code === 'NoResults') {
                return ['SearchResult' => ['TotalResultCount' => 0, 'Items' => []]];
            }

            throw new PaapiException($code, (string) ($error['Message'] ?? "HTTP {$response->status}"), $response->status);
        }

        return $json;
    }

    private function throttle(): void
    {
        $elapsed = microtime(true) - $this->lastRequestAt;
        if ($this->lastRequestAt > 0 && $elapsed < $this->minIntervalSeconds) {
            ($this->sleep)($this->minIntervalSeconds - $elapsed);
        }
        $this->lastRequestAt = microtime(true);
    }

    /** 画面やログにアクセスキーを出さない（デモ用の例示キーはそのまま表示） */
    private function mask(string $value): string
    {
        if ($this->config->accessKey === SigV4TestSuite::ACCESS_KEY) {
            return $value;
        }

        return str_replace($this->config->accessKey, substr($this->config->accessKey, 0, 4) . '****', $value);
    }
}
