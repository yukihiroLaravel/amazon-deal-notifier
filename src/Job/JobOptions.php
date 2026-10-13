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
    ) {
    }
}
