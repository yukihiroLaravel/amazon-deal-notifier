<?php

declare(strict_types=1);

namespace DealRadar\Domain;

use DateTimeImmutable;

/** PA-API から取得した商品情報（割引率の計算前） */
final class Product
{
    public function __construct(
        public readonly string $asin,
        public readonly string $title,
        public readonly string $brand,
        public readonly string $url,
        public readonly ?string $imageUrl,
        public readonly string $searchIndex,
        public readonly ?int $price,
        public readonly ?int $basePrice,
        public readonly ?string $basePriceType,
        public readonly ?int $savingsAmount,
        public readonly ?int $savingsPercentage,
        public readonly DateTimeImmutable $fetchedAt,
    ) {
    }
}
