<?php

declare(strict_types=1);

namespace DealRadar\Tests\Unit;

use DateTimeImmutable;
use DealRadar\Analysis\DiscountCalculator;
use DealRadar\Domain\Decision;
use DealRadar\Domain\NotificationRecord;
use DealRadar\Domain\Verdict;
use DealRadar\Notify\SlackMessageBuilder;
use PHPUnit\Framework\TestCase;

final class SlackMessageBuilderTest extends TestCase
{
    public function testDealMessageContainsRequiredFields(): void
    {
        $rule = DealFilterTest::rule();
        $deal = (new DiscountCalculator())->evaluate(DiscountCalculatorTest::product(7980, 12800));
        $previous = new NotificationRecord('R1', 'B0TEST0001', 'テスト商品', 25.0, 9600, new DateTimeImmutable('2026-10-12'), 1, NotificationRecord::STATUS_SENT, '新規通知');

        $payload = (new SlackMessageBuilder())->deals($rule, [new Decision($rule, $deal, Verdict::PriceDrop, $previous)], new DateTimeImmutable('2026-10-13 10:00'), 'run-1');
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        // 商品名・価格・割引率・商品ページURL（要件の4項目）
        self::assertStringContainsString('<https://www.amazon.co.jp/dp/B0TEST0001|テスト商品>', $json);
        self::assertStringContainsString('¥7,980', $json);
        self::assertStringContainsString('-37.6%', $json);
        self::assertStringContainsString('~¥12,800~', $json);
        self::assertStringContainsString('値下げ更新', $json);
        self::assertStringContainsString('前回 25%', $json);
        self::assertStringContainsString('取得時点', $json, '価格の取得時刻を明記する');
        self::assertNotEmpty($payload['text'], '通知のプレビュー用テキスト');
        self::assertLessThanOrEqual(50, count($payload['blocks']), 'Slack の上限は50ブロック');
    }

    public function testDemoMessageIsLabeled(): void
    {
        $rule = DealFilterTest::rule();
        $deal = (new DiscountCalculator())->evaluate(DiscountCalculatorTest::product(5000, 10000));

        $payload = (new SlackMessageBuilder(true))->deals($rule, [new Decision($rule, $deal, Verdict::New, null)], new DateTimeImmutable(), 'run-1');

        self::assertStringContainsString('デモモード', json_encode($payload, JSON_UNESCAPED_UNICODE));
    }

    public function testTitleIsEscapedForMrkdwn(): void
    {
        $rule = DealFilterTest::rule();
        $product = DiscountCalculatorTest::product(5000, 10000);
        $product = new \DealRadar\Domain\Product($product->asin, 'A<B>&C', '', $product->url, null, 'Electronics', 5000, 10000, null, null, null, new DateTimeImmutable());
        $deal = (new DiscountCalculator())->evaluate($product);

        $payload = (new SlackMessageBuilder())->deals($rule, [new Decision($rule, $deal, Verdict::New, null)], new DateTimeImmutable(), 'run-1');

        self::assertStringContainsString('A&lt;B&gt;&amp;C', json_encode($payload, JSON_UNESCAPED_UNICODE));
    }
}
