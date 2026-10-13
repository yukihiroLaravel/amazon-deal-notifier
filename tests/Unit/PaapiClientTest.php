<?php

declare(strict_types=1);

namespace DealRadar\Tests\Unit;

use DateTimeImmutable;
use DealRadar\Http\HttpRequest;
use DealRadar\Http\HttpResponse;
use DealRadar\Http\Transport;
use DealRadar\Paapi\MockPaapiServer;
use DealRadar\Paapi\PaapiClient;
use DealRadar\Paapi\PaapiConfig;
use DealRadar\Paapi\PaapiException;
use DealRadar\Paapi\PaapiResponseParser;
use PHPUnit\Framework\TestCase;

final class PaapiClientTest extends TestCase
{
    private static function client(Transport $transport, PaapiConfig $config, array &$sleeps = []): PaapiClient
    {
        return new PaapiClient($config, $transport, 0.0, 3, static function (float $s) use (&$sleeps): void {
            $sleeps[] = $s;
        });
    }

    public function testBuildsSearchItemsPayloadFromRule(): void
    {
        $payload = self::client(new MockPaapiServer(PaapiConfig::demo()), PaapiConfig::demo())
            ->buildSearchItemsPayload(DealFilterTest::rule(['minDiscount' => 30.5, 'maxPrice' => 30000, 'keywords' => 'イヤホン']), 2);

        self::assertSame('Electronics', $payload['SearchIndex']);
        self::assertSame('3210981', $payload['BrowseNodeId']);
        self::assertSame('イヤホン', $payload['Keywords']);
        self::assertSame(30, $payload['MinSavingPercent']);
        self::assertSame(30000, $payload['MaxPrice']);
        self::assertSame(2, $payload['ItemPage']);
        self::assertSame('Associates', $payload['PartnerType']);
        self::assertSame('www.amazon.co.jp', $payload['Marketplace']);
        self::assertContains('Offers.Listings.SavingBasis', $payload['Resources']);
    }

    public function testSignedRequestIsAcceptedByMockServer(): void
    {
        $client = self::client(new MockPaapiServer(PaapiConfig::demo(), null, 'fixed-seed'), PaapiConfig::demo());

        $response = $client->searchItems(DealFilterTest::rule(['minDiscount' => 0]), 1);

        self::assertNotEmpty($response['SearchResult']['Items']);
        $exchange = $client->lastExchange();
        self::assertSame(200, $exchange['status']);
        self::assertStringStartsWith('AWS4-HMAC-SHA256 Credential=AKIDEXAMPLE/', $exchange['authorization']);
        self::assertStringContainsString('/us-west-2/ProductAdvertisingAPI/aws4_request', $exchange['authorization']);
        self::assertStringContainsString("x-amz-target:com.amazon.paapi5.v1.ProductAdvertisingAPIv1.SearchItems\n", $exchange['canonicalRequest']);
        self::assertNotNull($exchange['verifiedBy']);
    }

    public function testRequestSignedWithWrongSecretIsRejected(): void
    {
        $wrong = new PaapiConfig('AKIDEXAMPLE', 'wrong-secret', 'demo-22');

        $this->expectException(PaapiException::class);
        $this->expectExceptionMessage('InvalidSignature');
        self::client(new MockPaapiServer(PaapiConfig::demo()), $wrong)->searchItems(DealFilterTest::rule(), 1);
    }

    public function testThrottledRequestIsRetriedWithBackoff(): void
    {
        $sleeps = [];
        $client = self::client(new MockPaapiServer(PaapiConfig::demo(), null, 'fixed-seed', 'throttle'), PaapiConfig::demo(), $sleeps);

        $response = $client->searchItems(DealFilterTest::rule(['minDiscount' => 0]), 1);

        self::assertNotEmpty($response['SearchResult']['Items']);
        self::assertSame([1.0], $sleeps, '1回目の TooManyRequests の後に1秒待って再試行');
    }

    public function testNonRetryableErrorIsThrownImmediately(): void
    {
        $sleeps = [];
        $transport = new class implements Transport {
            public int $calls = 0;

            public function send(HttpRequest $request): HttpResponse
            {
                $this->calls++;

                return new HttpResponse(400, '{"Errors":[{"Code":"InvalidParameterValue","Message":"bad"}]}');
            }
        };

        try {
            self::client($transport, PaapiConfig::demo(), $sleeps)->searchItems(DealFilterTest::rule(), 1);
            self::fail('例外が発生するはず');
        } catch (PaapiException $e) {
            self::assertSame('InvalidParameterValue', $e->errorCode);
        }
        self::assertSame(1, $transport->calls);
        self::assertSame([], $sleeps);
    }

    public function testNoResultsIsTreatedAsEmpty(): void
    {
        $client = self::client(new MockPaapiServer(PaapiConfig::demo(), null, 'fixed-seed'), PaapiConfig::demo());

        $response = $client->searchItems(DealFilterTest::rule(['keywords' => '存在しない商品名xyz']), 1);

        self::assertSame([], $response['SearchResult']['Items']);
    }

    public function testParserReadsPaapiResponseShape(): void
    {
        $response = json_decode(<<<'JSON'
            {"SearchResult":{"TotalResultCount":2,"Items":[
              {"ASIN":"B0EXAMPLE1","DetailPageURL":"https://www.amazon.co.jp/dp/B0EXAMPLE1?tag=demo-22",
               "ItemInfo":{"Title":{"DisplayValue":"商品A"},"ByLineInfo":{"Brand":{"DisplayValue":"ブランドA"}}},
               "Images":{"Primary":{"Medium":{"URL":"https://m.media-amazon.com/images/I/a.jpg"}}},
               "Offers":{"Listings":[{"Price":{"Amount":7980,"Currency":"JPY","Savings":{"Amount":4820,"Percentage":38}},
                                      "SavingBasis":{"Amount":12800,"Currency":"JPY","PriceType":"LIST_PRICE"}}]}},
              {"ASIN":"B0EXAMPLE2","ItemInfo":{"Title":{"DisplayValue":"在庫なし商品"}}}
            ]}}
            JSON, true);

        $products = (new PaapiResponseParser())->parse($response, 'Electronics', new DateTimeImmutable());

        self::assertCount(2, $products);
        self::assertSame('B0EXAMPLE1', $products[0]->asin);
        self::assertSame(7980, $products[0]->price);
        self::assertSame(12800, $products[0]->basePrice);
        self::assertSame(38, $products[0]->savingsPercentage);
        self::assertSame('LIST_PRICE', $products[0]->basePriceType);
        self::assertSame('https://m.media-amazon.com/images/I/a.jpg', $products[0]->imageUrl);
        self::assertNull($products[1]->price, 'Offers がない商品は価格 null');
    }
}
