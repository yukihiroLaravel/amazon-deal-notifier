<?php

declare(strict_types=1);

namespace DealRadar\Paapi;

final class SignedRequest
{
    /**
     * @param list<array{0: string, 1: string}> $headers Authorization を含む送信用ヘッダー
     */
    public function __construct(
        public readonly string $canonicalRequest,
        public readonly string $stringToSign,
        public readonly string $signature,
        public readonly string $authorization,
        public readonly array $headers,
    ) {
    }
}
