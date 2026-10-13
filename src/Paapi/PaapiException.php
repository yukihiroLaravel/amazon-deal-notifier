<?php

declare(strict_types=1);

namespace DealRadar\Paapi;

use RuntimeException;

final class PaapiException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus = 0,
    ) {
        parent::__construct("[{$errorCode}] {$message}");
    }

    public function isRetryable(): bool
    {
        return $this->errorCode === 'TooManyRequests' || $this->httpStatus >= 500;
    }
}
