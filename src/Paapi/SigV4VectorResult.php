<?php

declare(strict_types=1);

namespace DealRadar\Paapi;

final class SigV4VectorResult
{
    public function __construct(
        public readonly string $name,
        public readonly string $group,
        public readonly bool $canonicalRequestOk,
        public readonly bool $stringToSignOk,
        public readonly bool $authorizationOk,
        public readonly string $expectedAuthorization,
        public readonly string $actualAuthorization,
    ) {
    }

    public function passed(): bool
    {
        return $this->canonicalRequestOk && $this->stringToSignOk && $this->authorizationOk;
    }
}
