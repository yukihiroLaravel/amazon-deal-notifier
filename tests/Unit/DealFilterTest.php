<?php

declare(strict_types=1);

namespace DealRadar\Tests\Unit;

use DealRadar\Analysis\DealFilter;
use DealRadar\Analysis\DiscountCalculator;
use DealRadar\Domain\Rule;
use PHPUnit\Framework\TestCase;

final class DealFilterTest extends TestCase
{
    public static function rule(array $overrides = []): Rule
    {
        return Rule::fromArray($overrides + [
            'enabled' => true, 'id' => 'R1', 'name' => 'テスト', 'searchIndex' => 'Electronics', 'browseNodeId' => '3210981',
            'keywords' => '', 'minDiscount' => 30, 'minPrice' => null, 'maxPrice' => null, 'maxItems' => 5,
        ]);
    }

    public function testFiltersByMinDiscountAndSortsDescending(): void
    {
        $deals = (new DiscountCalculator())->evaluateAll([
            DiscountCalculatorTest::product(7000, 10000, null, 'B0A'), // 30%
            DiscountCalculatorTest::product(8000, 10000, null, 'B0B'), // 20% → 除外
            DiscountCalculatorTest::product(5000, 10000, null, 'B0C'), // 50%
            DiscountCalculatorTest::product(6000, 10000, null, 'B0D'), // 40%
        ]);

        $result = (new DealFilter())->apply(self::rule(), $deals);

        self::assertSame(['B0C', 'B0D', 'B0A'], array_map(static fn ($d) => $d->product->asin, $result));
    }

    public function testFiltersByPriceRange(): void
    {
        $deals = (new DiscountCalculator())->evaluateAll([
            DiscountCalculatorTest::product(2000, 4000, null, 'B0CHEAP'),
            DiscountCalculatorTest::product(20000, 40000, null, 'B0MID'),
            DiscountCalculatorTest::product(60000, 120000, null, 'B0HIGH'),
        ]);

        $result = (new DealFilter())->apply(self::rule(['minPrice' => 3000, 'maxPrice' => 50000]), $deals);

        self::assertSame(['B0MID'], array_map(static fn ($d) => $d->product->asin, $result));
    }

    public function testTieBreaksBySavingsAmountAndRemovesDuplicates(): void
    {
        $deals = (new DiscountCalculator())->evaluateAll([
            DiscountCalculatorTest::product(5000, 10000, null, 'B0SMALL'),
            DiscountCalculatorTest::product(50000, 100000, null, 'B0BIG'),
            DiscountCalculatorTest::product(50000, 100000, null, 'B0BIG'),
        ]);

        $result = (new DealFilter())->apply(self::rule(), $deals);

        self::assertSame(['B0BIG', 'B0SMALL'], array_map(static fn ($d) => $d->product->asin, $result));
    }
}
