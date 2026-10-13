<?php

declare(strict_types=1);

namespace DealRadar\Paapi;

/** PA-API 5.0 の接続設定（既定値は amazon.co.jp） */
final class PaapiConfig
{
    public function __construct(
        public readonly string $accessKey,
        public readonly string $secretKey,
        public readonly string $partnerTag,
        public readonly string $host = 'webservices.amazon.co.jp',
        public readonly string $region = 'us-west-2',
        public readonly string $marketplace = 'www.amazon.co.jp',
    ) {
    }

    /** デモ用：AWS テストスイートの例示用キーで署名する */
    public static function demo(): self
    {
        return new self(SigV4TestSuite::ACCESS_KEY, SigV4TestSuite::SECRET_KEY, 'demo-22');
    }

    public function endpoint(string $operation): string
    {
        return 'https://' . $this->host . '/paapi5/' . strtolower($operation);
    }
}
