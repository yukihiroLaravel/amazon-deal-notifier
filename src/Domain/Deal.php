<?php

declare(strict_types=1);

namespace DealRadar\Domain;

/** 割引率を計算済みの商品 */
final class Deal
{
    public function __construct(
        public readonly Product $product,
        public readonly int $price,
        public readonly int $basePrice,
        public readonly int $savingsAmount,
        public readonly float $discountRate,
    ) {
    }
}
