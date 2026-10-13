<?php

declare(strict_types=1);

namespace DealRadar\Domain;

use DateTimeImmutable;

/** 抽出結果シートの1行 */
final class ResultRow
{
    public function __construct(
        public readonly DateTimeImmutable $fetchedAt,
        public readonly string $ruleId,
        public readonly string $ruleName,
        public readonly int $rank,
        public readonly string $verdict,
        public readonly string $asin,
        public readonly string $title,
        public readonly string $brand,
        public readonly int $price,
        public readonly int $basePrice,
        public readonly int $savingsAmount,
        public readonly float $discountRate,
        public readonly string $url,
        public readonly string $searchIndex = '',
    ) {
    }

    public static function fromDecision(Decision $decision, int $rank): self
    {
        $deal = $decision->deal;

        return new self(
            fetchedAt: $deal->product->fetchedAt,
            ruleId: $decision->rule->id,
            ruleName: $decision->rule->name,
            rank: $rank,
            verdict: $decision->verdict->label(),
            asin: $deal->product->asin,
            title: $deal->product->title,
            brand: $deal->product->brand,
            price: $deal->price,
            basePrice: $deal->basePrice,
            savingsAmount: $deal->savingsAmount,
            discountRate: $deal->discountRate,
            url: $deal->product->url,
            searchIndex: $deal->product->searchIndex,
        );
    }
}
