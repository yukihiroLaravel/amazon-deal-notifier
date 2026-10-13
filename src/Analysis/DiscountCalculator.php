<?php

declare(strict_types=1);

namespace DealRadar\Analysis;

use DealRadar\Domain\Deal;
use DealRadar\Domain\Product;

/**
 * 販売価格と参考価格（PA-API の SavingBasis）から割引率を計算する。
 *
 * - 参考価格がない場合は、販売価格 + 割引額（Savings.Amount）から逆算する
 * - 割引率は小数第1位で切り捨て（「38.46%」を「38.5%」と過大に見せない）
 * - 価格不明・参考価格なし・値引きなしの商品は割引対象外（null）
 */
final class DiscountCalculator
{
    public function evaluate(Product $product): ?Deal
    {
        $price = $product->price;
        if ($price === null || $price <= 0) {
            return null;
        }

        $basePrice = $product->basePrice;
        if ($basePrice === null && $product->savingsAmount !== null && $product->savingsAmount > 0) {
            $basePrice = $price + $product->savingsAmount;
        }
        if ($basePrice === null || $basePrice <= $price) {
            return null;
        }

        $savings = $basePrice - $price;
        $rate = floor($savings / $basePrice * 1000) / 10;

        return new Deal($product, $price, $basePrice, $savings, $rate);
    }

    /**
     * @param iterable<Product> $products
     * @return list<Deal>
     */
    public function evaluateAll(iterable $products): array
    {
        $deals = [];
        foreach ($products as $product) {
            $deal = $this->evaluate($product);
            if ($deal !== null) {
                $deals[] = $deal;
            }
        }

        return $deals;
    }
}
