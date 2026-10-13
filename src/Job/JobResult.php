<?php

declare(strict_types=1);

namespace DealRadar\Job;

use DealRadar\Domain\ResultRow;
use DealRadar\Domain\RunLog;

final class JobResult
{
    /** @param list<ResultRow> $results */
    public function __construct(
        public readonly RunLog $log,
        public readonly array $results,
        public readonly bool $skipped,
    ) {
    }

    public function exitCode(): int
    {
        return $this->log->status === RunLog::FAILED ? 1 : 0;
    }
}
