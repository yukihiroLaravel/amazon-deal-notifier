<?php

declare(strict_types=1);

namespace DealRadar\Domain;

final class Decision
{
    public function __construct(
        public readonly Rule $rule,
        public readonly Deal $deal,
        public readonly Verdict $verdict,
        public readonly ?NotificationRecord $previous,
    ) {
    }

    public function withVerdict(Verdict $verdict): self
    {
        return new self($this->rule, $this->deal, $verdict, $this->previous);
    }
}
