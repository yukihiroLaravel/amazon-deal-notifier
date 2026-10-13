<?php

declare(strict_types=1);

namespace DealRadar\Analysis;

use DealRadar\Domain\Deal;
use DealRadar\Domain\Rule;

/** ルールの条件で絞り込み、割引率の高い順に並べる */
final class DealFilter
{
    /**
     * @param list<Deal> $deals
     * @return list<Deal>
     */
    public function apply(Rule $rule, array $deals): array
    {
        $matched = array_values(array_filter($deals, static function (Deal $deal) use ($rule): bool {
            if ($deal->discountRate < $rule->minDiscount) {
                return false;
            }
            if ($rule->minPrice !== null && $deal->price < $rule->minPrice) {
                return false;
            }
            if ($rule->maxPrice !== null && $deal->price > $rule->maxPrice) {
                return false;
            }

            return true;
        }));

        // 同じ ASIN が複数ページに出た場合は1件にまとめる
        $unique = [];
        foreach ($matched as $deal) {
            $unique[$deal->product->asin] ??= $deal;
        }
        $matched = array_values($unique);

        // 割引率の高い順 → 割引額の大きい順 → ASIN（並びを安定させる）
        usort($matched, static fn (Deal $a, Deal $b): int => [$b->discountRate, $b->savingsAmount, $a->product->asin]
            <=> [$a->discountRate, $a->savingsAmount, $b->product->asin]);

        return $matched;
    }
}
