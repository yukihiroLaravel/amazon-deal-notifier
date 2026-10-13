<?php

declare(strict_types=1);

namespace DealRadar\Notify;

use DateTimeImmutable;
use DealRadar\Domain\Decision;
use DealRadar\Domain\Rule;
use DealRadar\Domain\Verdict;

/** Slack の Block Kit メッセージを組み立てる */
final class SlackMessageBuilder
{
    public function __construct(private readonly bool $demo = false)
    {
    }

    /**
     * @param list<Decision> $decisions 通知対象（割引率の高い順）
     * @return array<string, mixed>
     */
    public function deals(Rule $rule, array $decisions, DateTimeImmutable $fetchedAt, string $runId): array
    {
        $count = count($decisions);
        $top = $decisions[0]->deal->discountRate ?? 0;

        $blocks = [
            [
                'type' => 'header',
                'text' => ['type' => 'plain_text', 'text' => "割引商品 {$count}件｜{$rule->name}", 'emoji' => true],
            ],
            [
                'type' => 'context',
                'elements' => [[
                    'type' => 'mrkdwn',
                    'text' => sprintf('*条件* %s　*最大割引* %s%%　*取得* %s', $rule->conditionLabel(), self::rate($top), $fetchedAt->format('Y/m/d H:i')),
                ]],
            ],
            ['type' => 'divider'],
        ];

        foreach ($decisions as $i => $decision) {
            $deal = $decision->deal;
            $product = $deal->product;
            $badge = match ($decision->verdict) {
                Verdict::PriceDrop => sprintf('　:chart_with_downwards_trend: *値下げ更新*（前回 %s%%）', self::rate($decision->previous?->discountRate ?? 0)),
                Verdict::Renotify => '　:repeat: 再通知',
                Verdict::Retry => '　:arrows_counterclockwise: 再送',
                default => '　:new: 新着',
            };

            $section = [
                'type' => 'section',
                'text' => [
                    'type' => 'mrkdwn',
                    'text' => sprintf(
                        "*%d. <%s|%s>*\n*-%s%%*　*¥%s*　~¥%s~　（¥%s OFF）%s%s",
                        $i + 1,
                        $product->url,
                        self::escape(mb_strimwidth($product->title, 0, 120, '…')),
                        self::rate($deal->discountRate),
                        number_format($deal->price),
                        number_format($deal->basePrice),
                        number_format($deal->savingsAmount),
                        $product->brand !== '' ? "\n" . self::escape($product->brand) . '　' : "\n",
                        ltrim($badge),
                    ),
                ],
            ];
            if ($product->imageUrl !== null) {
                $section['accessory'] = ['type' => 'image', 'image_url' => $product->imageUrl, 'alt_text' => mb_strimwidth($product->title, 0, 60, '…')];
            }
            $blocks[] = $section;
        }

        $footer = '価格・在庫は取得時点（' . $fetchedAt->format('Y/m/d H:i') . '）のもので、変動する場合があります。　実行ID ' . $runId;
        if ($this->demo) {
            $footer = ':test_tube: *デモモード*：PA-API 5.0 形式のテストデータです（架空の商品）。　' . $footer;
        }
        $blocks[] = ['type' => 'divider'];
        $blocks[] = ['type' => 'context', 'elements' => [['type' => 'mrkdwn', 'text' => $footer]]];

        return [
            'text' => "割引商品 {$count}件（{$rule->name}）最大 -" . self::rate($top) . '%',
            'blocks' => $blocks,
        ];
    }

    /** @return array<string, mixed> */
    public function error(string $runId, string $status, string $summary, array $errors, DateTimeImmutable $at): array
    {
        $lines = array_map(static fn (string $e): string => '• ' . self::escape($e), array_slice($errors, 0, 10));

        return [
            'text' => "実行エラー（{$status}）: {$summary}",
            'blocks' => [
                ['type' => 'header', 'text' => ['type' => 'plain_text', 'text' => ":warning: 実行エラー（{$status}）", 'emoji' => true]],
                [
                    'type' => 'section',
                    'fields' => [
                        ['type' => 'mrkdwn', 'text' => "*実行ID*\n{$runId}"],
                        ['type' => 'mrkdwn', 'text' => "*発生日時*\n" . $at->format('Y/m/d H:i:s')],
                    ],
                ],
                ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => '*内容*' . "\n" . implode("\n", $lines)]],
                ['type' => 'context', 'elements' => [['type' => 'mrkdwn', 'text' => '詳細はスプレッドシートの「実行ログ」シート、またはダッシュボードの実行ログで確認できます。']]],
            ],
        ];
    }

    private static function rate(float $rate): string
    {
        return rtrim(rtrim(number_format($rate, 1, '.', ''), '0'), '.');
    }

    /** Slack の mrkdwn で特別な意味を持つ文字をエスケープ */
    private static function escape(string $text): string
    {
        return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $text);
    }
}
