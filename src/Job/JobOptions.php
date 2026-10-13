<?php

declare(strict_types=1);

namespace DealRadar\Job;

use DateTimeImmutable;

final class JobOptions
{
    public function __construct(
        public readonly bool $force = false,
        public readonly bool $dryRun = false,
        public readonly ?string $ruleId = null,
        public readonly ?DateTimeImmutable $now = null,
        // 手動取得：実行間隔は無視するが、手動取得の最短間隔（連打防止）は守る
        public readonly bool $manual = false,
    ) {
    }
}
