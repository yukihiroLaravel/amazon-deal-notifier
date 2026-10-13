<?php

declare(strict_types=1);

namespace DealRadar\Http;

final class HttpRequest
{
    /**
     * @param list<array{0: string, 1: string}> $headers
     */
    public function __construct(
        public readonly string $method,
        public readonly string $url,
        public readonly array $headers,
        public readonly string $body = '',
    ) {
    }

    public function header(string $name): ?string
    {
        foreach ($this->headers as [$headerName, $value]) {
            if (strcasecmp($headerName, $name) === 0) {
                return $value;
            }
        }

        return null;
    }
}
