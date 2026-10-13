<?php

declare(strict_types=1);

namespace DealRadar\Paapi;

use DateTimeImmutable;
use DealRadar\Domain\Product;

/** SearchItems のレスポンスを Product に変換する（欠けている項目は null のまま渡す） */
final class PaapiResponseParser
{
    /**
     * @param array<string, mixed> $response
     * @return list<Product>
     */
    public function parse(array $response, string $searchIndex, DateTimeImmutable $fetchedAt): array
    {
        $products = [];
        foreach ($response['SearchResult']['Items'] ?? [] as $item) {
            $asin = (string) ($item['ASIN'] ?? '');
            if ($asin === '') {
                continue;
            }

            $listing = $item['Offers']['Listings'][0] ?? [];
            $price = $listing['Price'] ?? [];
            $basis = $listing['SavingBasis'] ?? [];
            $savings = $price['Savings'] ?? [];

            $products[] = new Product(
                asin: $asin,
                title: (string) ($item['ItemInfo']['Title']['DisplayValue'] ?? '(タイトル不明)'),
                brand: (string) ($item['ItemInfo']['ByLineInfo']['Brand']['DisplayValue'] ?? ''),
                url: (string) ($item['DetailPageURL'] ?? "https://www.amazon.co.jp/dp/{$asin}"),
                imageUrl: $item['Images']['Primary']['Medium']['URL'] ?? null,
                searchIndex: $searchIndex,
                price: self::amount($price),
                basePrice: self::amount($basis),
                basePriceType: $basis['PriceType'] ?? null,
                savingsAmount: self::amount($savings),
                savingsPercentage: isset($savings['Percentage']) ? (int) $savings['Percentage'] : null,
                fetchedAt: $fetchedAt,
            );
        }

        return $products;
    }

    /** @param array<string, mixed> $money */
    private static function amount(array $money): ?int
    {
        return isset($money['Amount']) ? (int) round((float) $money['Amount']) : null;
    }
}
