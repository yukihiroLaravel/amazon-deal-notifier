<?php

declare(strict_types=1);

namespace DealRadar\Domain;

/** 全体設定（スプレッドシート「基本設定」シート） */
final class Settings
{
    /** キー => [項目名, 既定値, 説明] */
    public const DEFINITIONS = [
        'intervalHours' => ['実行間隔（時間）', 1, '前回の実行からこの時間が経過していれば実行します（定期実行は1時間ごとに起動）'],
        'cooldownDays' => ['再通知までの日数', 7, '同じ商品は、この日数が経過するまで再通知しません'],
        'renotifyDeltaPt' => ['値下げ再通知のしきい値（pt）', 10, '前回通知時より割引率がこのポイント以上深くなったら、日数内でも再通知します'],
        'pagesPerRule' => ['1ルールあたりの取得ページ数', 2, 'PA-API の SearchItems は1ページ10件。最大10ページまで'],
        'historyRetentionDays' => ['通知履歴の保持日数', 90, 'これより古い通知履歴は自動で削除します'],
    ];

    public function __construct(
        public readonly int $intervalHours,
        public readonly int $cooldownDays,
        public readonly float $renotifyDeltaPt,
        public readonly int $pagesPerRule,
        public readonly int $historyRetentionDays,
    ) {
    }

    public static function defaults(): self
    {
        return self::fromArray([]);
    }

    /** @param array<string, mixed> $values */
    public static function fromArray(array $values): self
    {
        $get = static fn (string $key): mixed => ($values[$key] ?? '') === '' ? self::DEFINITIONS[$key][1] : $values[$key];

        return new self(
            intervalHours: max(1, (int) $get('intervalHours')),
            cooldownDays: max(0, (int) $get('cooldownDays')),
            renotifyDeltaPt: max(0.0, (float) $get('renotifyDeltaPt')),
            pagesPerRule: max(1, min(10, (int) $get('pagesPerRule'))),
            historyRetentionDays: max(1, (int) $get('historyRetentionDays')),
        );
    }

    /** @return array<string, int|float> */
    public function toArray(): array
    {
        return [
            'intervalHours' => $this->intervalHours,
            'cooldownDays' => $this->cooldownDays,
            'renotifyDeltaPt' => $this->renotifyDeltaPt,
            'pagesPerRule' => $this->pagesPerRule,
            'historyRetentionDays' => $this->historyRetentionDays,
        ];
    }
}
