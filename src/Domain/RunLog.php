<?php

declare(strict_types=1);

namespace DealRadar\Domain;

use DateTimeImmutable;

/** 実行ログ（スプレッドシート「実行ログ」シートの1行） */
final class RunLog
{
    public const SUCCESS = '成功';
    public const PARTIAL = '一部失敗';
    public const FAILED = '失敗';
    public const SKIPPED = 'スキップ';
    public const DRY_RUN = 'ドライラン';

    public function __construct(
        public readonly string $runId,
        public readonly DateTimeImmutable $startedAt,
        public readonly ?DateTimeImmutable $finishedAt,
        public readonly string $status,
        public readonly string $source,
        public readonly int $fetched,
        public readonly int $matched,
        public readonly int $notified,
        public readonly int $skippedDuplicates,
        public readonly string $message,
    ) {
    }

    public function durationSeconds(): ?float
    {
        if ($this->finishedAt === null) {
            return null;
        }

        return round((float) $this->finishedAt->format('U.u') - (float) $this->startedAt->format('U.u'), 1);
    }

    /** 実行間隔の判定に使う「実際に処理した」実行か */
    public function countsAsRun(): bool
    {
        return in_array($this->status, [self::SUCCESS, self::PARTIAL], true);
    }
}
