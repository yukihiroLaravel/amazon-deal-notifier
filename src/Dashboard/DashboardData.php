<?php

declare(strict_types=1);

namespace DealRadar\Dashboard;

use DateTimeImmutable;
use DealRadar\Domain\NotificationRecord;
use DealRadar\Domain\ResultRow;
use DealRadar\Domain\Rule;
use DealRadar\Domain\RunLog;
use DealRadar\Domain\Settings;
use DealRadar\Paapi\SigV4TestSuite;
use DealRadar\Paapi\SigV4VectorResult;
use DealRadar\Storage\GoogleSheetsStorage;
use DealRadar\Support\App;
use Throwable;

/** ダッシュボード表示用のデータ取得（閲覧専用。設定の変更はスプレッドシートで行う） */
final class DashboardData
{
    private ?string $storageError = null;

    /** @var array<string, mixed> */
    private array $cache = [];

    public function __construct(private readonly App $app)
    {
    }

    public function isDemo(): bool
    {
        return $this->app->isDemo();
    }

    public function storageLabel(): string
    {
        try {
            return $this->app->storage()->label();
        } catch (Throwable) {
            return '未接続';
        }
    }

    public function spreadsheetUrl(): ?string
    {
        try {
            $storage = $this->app->storage();

            return $storage instanceof GoogleSheetsStorage ? $storage->url() : null;
        } catch (Throwable) {
            return null;
        }
    }

    public function slackConfigured(): bool
    {
        return App::env('SLACK_WEBHOOK_URL') !== null;
    }

    public function storageError(): ?string
    {
        return $this->storageError;
    }

    public function settings(): Settings
    {
        return $this->load('settings', fn () => $this->app->storage()->loadSettings(), Settings::defaults());
    }

    /** @return list<Rule> */
    public function rules(): array
    {
        return $this->load('rules', fn () => $this->app->storage()->loadRules(), []);
    }

    /** @return list<ResultRow> */
    public function results(): array
    {
        return $this->load('results', fn () => $this->app->storage()->loadResults(), []);
    }

    /** @return list<NotificationRecord> 新しい順 */
    public function history(): array
    {
        $history = $this->load('history', fn () => $this->app->storage()->loadHistory(), []);
        usort($history, static fn (NotificationRecord $a, NotificationRecord $b): int => $b->notifiedAt <=> $a->notifiedAt);

        return $history;
    }

    /** @return list<RunLog> 新しい順 */
    public function runLogs(int $limit = 50): array
    {
        return $this->load("runs{$limit}", fn () => $this->app->storage()->loadRunLogs($limit), []);
    }

    public function lastRun(): ?RunLog
    {
        return $this->runLogs()[0] ?? null;
    }

    public function nextRunAt(): ?DateTimeImmutable
    {
        foreach ($this->runLogs() as $log) {
            if ($log->countsAsRun()) {
                // 定期実行は毎時0分に起動し、実行間隔を満たしていれば処理する
                $due = $log->startedAt->modify('+' . $this->settings()->intervalHours . ' hours')->modify('-5 minutes');
                $next = $due->setTime((int) $due->format('H'), 0);

                return $next < $due ? $next->modify('+1 hour') : $next;
            }
        }

        return null;
    }

    /** @return list<SigV4VectorResult> */
    public function sigV4Results(): array
    {
        return $this->cache['sigv4'] ??= SigV4TestSuite::default()->run();
    }

    /** @return array{passed: int, total: int} */
    public function sigV4Summary(): array
    {
        $results = $this->sigV4Results();

        return ['passed' => count(array_filter($results, static fn (SigV4VectorResult $r): bool => $r->passed())), 'total' => count($results)];
    }

    /** @return array<string, mixed>|null */
    public function lastExchange(): ?array
    {
        $file = $this->app->root . '/storage/data/paapi_last_exchange.json';

        return is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
    }

    /** @return array<string, array{savedAt: string, sent: bool, payload: array<string, mixed>}> ファイル名 => プレビュー */
    public function slackPreviews(): array
    {
        $previews = [];
        foreach (glob($this->app->root . '/storage/slack/*.json') ?: [] as $file) {
            $data = json_decode((string) file_get_contents($file), true);
            if (is_array($data)) {
                $previews[basename($file, '.json')] = $data;
            }
        }
        ksort($previews);

        return $previews;
    }

    /**
     * @template T
     * @param callable(): T $loader
     * @param T $fallback
     * @return T
     */
    private function load(string $key, callable $loader, mixed $fallback): mixed
    {
        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }
        try {
            return $this->cache[$key] = $loader();
        } catch (Throwable $e) {
            $this->storageError ??= $e->getMessage();

            return $this->cache[$key] = $fallback;
        }
    }
}
