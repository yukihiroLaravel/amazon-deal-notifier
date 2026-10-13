<?php

declare(strict_types=1);

namespace DealRadar\Support;

use DealRadar\Analysis\DealFilter;
use DealRadar\Analysis\DiscountCalculator;
use DealRadar\Http\CurlTransport;
use DealRadar\Job\JobRunner;
use DealRadar\Notify\SlackMessageBuilder;
use DealRadar\Notify\SlackNotifier;
use DealRadar\Paapi\MockPaapiServer;
use DealRadar\Paapi\PaapiClient;
use DealRadar\Paapi\PaapiConfig;
use DealRadar\Paapi\PaapiProductSource;
use DealRadar\Paapi\PaapiResponseParser;
use DealRadar\Storage\GoogleSheetsStorage;
use DealRadar\Storage\LocalJsonStorage;
use DealRadar\Storage\Storage;
use Dotenv\Dotenv;
use RuntimeException;

/** 環境変数（.env）から各部品を組み立てる */
final class App
{
    private ?Storage $storage = null;

    public function __construct(public readonly string $root)
    {
    }

    public static function boot(): self
    {
        $root = dirname(__DIR__, 2);
        Dotenv::createImmutable($root)->safeLoad();
        date_default_timezone_set(self::env('APP_TIMEZONE') ?? 'Asia/Tokyo');

        return new self($root);
    }

    public static function env(string $key): ?string
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        return ($value === false || $value === '') ? null : (string) $value;
    }

    /** PA-API の認証情報がなければデモモード（MockPaapiServer）で動く */
    public function isDemo(): bool
    {
        return strtolower(self::env('DATA_SOURCE') ?? 'mock') !== 'paapi';
    }

    public function storage(): Storage
    {
        if ($this->storage !== null) {
            return $this->storage;
        }

        if (strtolower(self::env('STORAGE') ?? 'local') === 'sheets') {
            $id = self::env('SPREADSHEET_ID') ?? throw new RuntimeException('SPREADSHEET_ID が未設定です');
            $credentials = GoogleSheetsStorage::credentialsFrom(
                $this->resolvePath(self::env('GOOGLE_APPLICATION_CREDENTIALS')),
                self::env('GOOGLE_SERVICE_ACCOUNT_JSON'),
            );

            return $this->storage = new GoogleSheetsStorage($id, $credentials);
        }

        return $this->storage = new LocalJsonStorage($this->root . '/storage/data');
    }

    public function source(): PaapiProductSource
    {
        $transport = new CurlTransport();
        if ($this->isDemo()) {
            $config = PaapiConfig::demo();
            $transport = new MockPaapiServer($config, null, self::env('MOCK_PRICE_SEED'), self::env('MOCK_SIMULATE_ERROR'));
            $interval = 0.0;
        } else {
            $config = new PaapiConfig(
                accessKey: self::env('PAAPI_ACCESS_KEY') ?? throw new RuntimeException('PAAPI_ACCESS_KEY が未設定です'),
                secretKey: self::env('PAAPI_SECRET_KEY') ?? throw new RuntimeException('PAAPI_SECRET_KEY が未設定です'),
                partnerTag: self::env('PAAPI_PARTNER_TAG') ?? throw new RuntimeException('PAAPI_PARTNER_TAG が未設定です'),
                host: self::env('PAAPI_HOST') ?? 'webservices.amazon.co.jp',
                region: self::env('PAAPI_REGION') ?? 'us-west-2',
                marketplace: self::env('PAAPI_MARKETPLACE') ?? 'www.amazon.co.jp',
            );
            $interval = 1.0;
        }

        return new PaapiProductSource(new PaapiClient($config, $transport, $interval), new PaapiResponseParser(), $this->isDemo());
    }

    public function slack(): SlackNotifier
    {
        return new SlackNotifier(
            new CurlTransport(),
            self::env('SLACK_WEBHOOK_URL'),
            self::env('SLACK_ERROR_WEBHOOK_URL'),
            $this->root . '/storage/slack',
        );
    }

    public function logger(bool $echo = true): Logger
    {
        return new Logger($this->root . '/storage/logs', $echo);
    }

    public function jobRunner(bool $echo = true): JobRunner
    {
        return new JobRunner(
            storage: $this->storage(),
            source: $this->source(),
            calculator: new DiscountCalculator(),
            filter: new DealFilter(),
            slack: $this->slack(),
            messages: new SlackMessageBuilder($this->isDemo()),
            logger: $this->logger($echo),
            stateDir: $this->root . '/storage/data',
        );
    }

    private function resolvePath(?string $path): ?string
    {
        if ($path === null) {
            return null;
        }

        return str_starts_with($path, '/') ? $path : $this->root . '/' . $path;
    }
}
