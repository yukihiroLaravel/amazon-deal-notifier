<?php

declare(strict_types=1);

namespace DealRadar\Tests\Unit;

use DateTimeImmutable;
use DealRadar\Analysis\Deduplicator;
use DealRadar\Analysis\DiscountCalculator;
use DealRadar\Domain\Deal;
use DealRadar\Domain\NotificationRecord;
use DealRadar\Domain\Settings;
use DealRadar\Domain\Verdict;
use PHPUnit\Framework\TestCase;

final class DeduplicatorTest extends TestCase
{
    private const NOW = '2026-10-13 12:00:00';

    private static function deal(float $rate, string $asin = 'B0TEST0001'): Deal
    {
        // 参考価格 10,000円で、指定した割引率になる価格
        return (new DiscountCalculator())->evaluate(DiscountCalculatorTest::product((int) (10000 - $rate * 100), 10000, null, $asin));
    }

    private static function record(float $rate, string $notifiedAt, string $status = NotificationRecord::STATUS_SENT): NotificationRecord
    {
        return new NotificationRecord('R1', 'B0TEST0001', 'テスト商品', $rate, 6000, new DateTimeImmutable($notifiedAt), 1, $status, '新規通知');
    }

    private static function dedup(array $records, array $settings = []): Deduplicator
    {
        return new Deduplicator(Settings::fromArray($settings + ['cooldownDays' => 7, 'renotifyDeltaPt' => 10]), new DateTimeImmutable(self::NOW), $records);
    }

    public function testNewProductIsNotified(): void
    {
        self::assertSame(Verdict::New, self::dedup([])->decide(DealFilterTest::rule(), self::deal(40))->verdict);
    }

    public function testSameProductWithinCooldownIsSkipped(): void
    {
        $decision = self::dedup([self::record(40, '2026-10-10 12:00:00')])->decide(DealFilterTest::rule(), self::deal(45));

        self::assertSame(Verdict::Duplicate, $decision->verdict);
        self::assertFalse($decision->verdict->shouldNotify());
    }

    public function testDeeperDiscountBeyondThresholdIsNotifiedAsPriceDrop(): void
    {
        $dedup = self::dedup([self::record(40, '2026-10-12 12:00:00')]);

        self::assertSame(Verdict::Duplicate, $dedup->decide(DealFilterTest::rule(), self::deal(49.9))->verdict, '+9.9pt は通知しない');
        self::assertSame(Verdict::PriceDrop, $dedup->decide(DealFilterTest::rule(), self::deal(50))->verdict, '+10pt で値下げ更新');
    }

    public function testThresholdZeroDisablesPriceDropNotification(): void
    {
        $dedup = self::dedup([self::record(40, '2026-10-12 12:00:00')], ['renotifyDeltaPt' => 0]);

        self::assertSame(Verdict::Duplicate, $dedup->decide(DealFilterTest::rule(), self::deal(80))->verdict);
    }

    public function testRenotifiedAfterCooldown(): void
    {
        $dedup = self::dedup([self::record(40, '2026-10-06 12:00:00')]);

        self::assertSame(Verdict::Renotify, $dedup->decide(DealFilterTest::rule(), self::deal(40))->verdict);
    }

    public function testFailedOrPendingRecordIsRetried(): void
    {
        $failed = self::dedup([self::record(40, '2026-10-13 11:00:00', NotificationRecord::STATUS_FAILED)]);
        $pending = self::dedup([self::record(40, '2026-10-13 11:00:00', NotificationRecord::STATUS_PENDING)]);

        self::assertSame(Verdict::Retry, $failed->decide(DealFilterTest::rule(), self::deal(40))->verdict);
        self::assertSame(Verdict::Retry, $pending->decide(DealFilterTest::rule(), self::deal(40))->verdict);
    }

    public function testHistoryIsScopedPerRule(): void
    {
        $decision = self::dedup([self::record(40, '2026-10-12 12:00:00')])->decide(DealFilterTest::rule(['id' => 'R2']), self::deal(40));

        self::assertSame(Verdict::New, $decision->verdict, '別ルールでの通知は重複扱いしない');
    }

    public function testNotificationsAreCappedByMaxItems(): void
    {
        $deals = [self::deal(60, 'B0A'), self::deal(50, 'B0B'), self::deal(40, 'B0C')];

        $decisions = self::dedup([])->decideAll(DealFilterTest::rule(['maxItems' => 2]), $deals);

        self::assertSame([Verdict::New, Verdict::New, Verdict::OverLimit], array_map(static fn ($d) => $d->verdict, $decisions));
    }
}
