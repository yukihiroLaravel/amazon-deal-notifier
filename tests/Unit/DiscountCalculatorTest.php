<?php

declare(strict_types=1);

namespace DealRadar\Tests\Unit;

use DateTimeImmutable;
use DealRadar\Analysis\DiscountCalculator;
use DealRadar\Domain\Product;
use PHPUnit\Framework\TestCase;

final class DiscountCalculatorTest extends TestCase
{
    public static function product(?int $price, ?int $basePrice, ?int $savings = null, string $asin = 'B0TEST0001'): Product
    {
        return new Product($asin, 'テスト商品', 'Brand', "https://www.amazon.co.jp/dp/{$asin}", null, 'Electronics', $price, $basePrice, null, $savings, null, new DateTimeImmutable('2026-10-13 10:00:00'));
    }

    public function testCalculatesRateFromBasePrice(): void
    {
        $deal = (new DiscountCalculator())->evaluate(self::product(7980, 12800));

        self::assertNotNull($deal);
        self::assertSame(4820, $deal->savingsAmount);
        self::assertSame(37.6, $deal->discountRate); // 37.656… は切り捨て
    }

    public function testRateIsFlooredNotRounded(): void
    {
        // 1/3 = 33.333… → 33.3、2/3 = 66.666… → 66.6（四捨五入で 66.7 にしない）
        self::assertSame(33.3, (new DiscountCalculator())->evaluate(self::product(2000, 3000))->discountRate);
        self::assertSame(66.6, (new DiscountCalculator())->evaluate(self::product(1000, 3000))->discountRate);
    }

    public function testFallsBackToSavingsWhenBasePriceMissing(): void
    {
        $deal = (new DiscountCalculator())->evaluate(self::product(6000, null, 4000));

        self::assertSame(10000, $deal->basePrice);
        self::assertSame(40.0, $deal->discountRate);
    }

    public function testNoDiscountCasesAreExcluded(): void
    {
        $calc = new DiscountCalculator();

        self::assertNull($calc->evaluate(self::product(null, 10000)), '価格不明');
        self::assertNull($calc->evaluate(self::product(0, 10000)), '価格0');
        self::assertNull($calc->evaluate(self::product(5000, null)), '参考価格なし');
        self::assertNull($calc->evaluate(self::product(5000, 5000)), '参考価格と同額');
        self::assertNull($calc->evaluate(self::product(5500, 5000)), '参考価格より高い');
    }
}
