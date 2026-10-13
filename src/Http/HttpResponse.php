<?php

declare(strict_types=1);

namespace DealRadar\Http;

final class HttpResponse
{
    /** @param array<string, string> $headers 小文字のヘッダー名 => 値 */
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly array $headers = [],
    ) {
    }

    public function isSuccess(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /** @return array<string, mixed>|null */
    public function json(): ?array
    {
        $decoded = json_decode($this->body, true);

        return is_array($decoded) ? $decoded : null;
    }
}
