<?php

declare(strict_types=1);

namespace DealRadar\Tests\Unit;

use DateTimeImmutable;
use DealRadar\Analysis\DealFilter;
use DealRadar\Analysis\DiscountCalculator;
use DealRadar\Domain\NotificationRecord;
use DealRadar\Domain\RunLog;
use DealRadar\Domain\Verdict;
use DealRadar\Http\HttpRequest;
use DealRadar\Http\HttpResponse;
use DealRadar\Http\Transport;
use DealRadar\Job\JobOptions;
use DealRadar\Job\JobRunner;
use DealRadar\Notify\SlackMessageBuilder;
use DealRadar\Notify\SlackNotifier;
use DealRadar\Paapi\MockPaapiServer;
use DealRadar\Paapi\PaapiClient;
use DealRadar\Paapi\PaapiConfig;
use DealRadar\Paapi\PaapiProductSource;
use DealRadar\Paapi\PaapiResponseParser;
use DealRadar\Storage\LocalJsonStorage;
use DealRadar\Support\Logger;
use PHPUnit\Framework\TestCase;

/** 取得 → 計算 → 抽出 → 重複判定 → 通知 → 記録 の一連の流れ */
final class JobRunnerTest extends TestCase
{
    private string $dir;
    private SlackRecorder $slack;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/deal-radar-test-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/data', 0775, true);
        $this->slack = new SlackRecorder();
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private function runner(?string $simulateError = null, bool $slackFails = false): JobRunner
    {
        $config = PaapiConfig::demo();
        $source = new PaapiProductSource(
            new PaapiClient($config, new MockPaapiServer($config, null, 'fixed-seed', $simulateError), 0.0, 3, static fn () => null),
            new PaapiResponseParser(),
            true,
        );
        $this->slack->fail = $slackFails;

        return new JobRunner(
            storage: new LocalJsonStorage($this->dir . '/data'),
            source: $source,
            calculator: new DiscountCalculator(),
            filter: new DealFilter(),
            slack: new SlackNotifier($this->slack, 'https://hooks.slack.com/services/TEST', null, $this->dir . '/slack'),
            messages: new SlackMessageBuilder(true),
            logger: new Logger($this->dir . '/logs', false),
            stateDir: $this->dir . '/data',
        );
    }

    public function testFirstRunNotifiesAndSecondRunSkipsDuplicates(): void
    {
        $first = $this->runner()->run(new JobOptions(force: true, now: new DateTimeImmutable('2026-10-13 10:00:00')));

        self::assertSame(RunLog::SUCCESS, $first->log->status);
        self::assertGreaterThan(0, $first->log->notified);
        self::assertSame(0, $first->log->skippedDuplicates);
        self::assertNotEmpty($this->slack->requests);

        // 同じ価格のまま再実行 → 通知済みの商品はスキップされ、二重に通知されない
        $second = $this->runner()->run(new JobOptions(force: true, now: new DateTimeImmutable('2026-10-13 11:00:00')));

        self::assertSame($first->log->notified, $second->log->skippedDuplicates);
        $sentFirst = self::notifiedKeys($first->results);
        $sentSecond = self::notifiedKeys($second->results);
        self::assertSame([], array_intersect($sentFirst, $sentSecond), '同じ商品が2回通知されていない');

        $history = (new LocalJsonStorage($this->dir . '/data'))->loadHistory();
        self::assertCount($first->log->notified + $second->log->notified, $history);
        foreach ($history as $record) {
            self::assertSame(NotificationRecord::STATUS_SENT, $record->status);
        }
    }

    /** @return list<string> */
    private static function notifiedKeys(array $results): array
    {
        $keys = [];
        foreach ($results as $row) {
            if (Verdict::fromLabel($row->verdict)?->shouldNotify()) {
                $keys[] = $row->ruleId . '|' . $row->asin;
            }
        }

        return $keys;
    }

    public function testResultsAreSortedByDiscountWithinEachRule(): void
    {
        $result = $this->runner()->run(new JobOptions(force: true));

        $byRule = [];
        foreach ($result->results as $row) {
            $byRule[$row->ruleId][] = $row->discountRate;
        }
        self::assertNotEmpty($byRule);
        foreach ($byRule as $rates) {
            $sorted = $rates;
            rsort($sorted);
            self::assertSame($sorted, $rates);
        }
    }

    public function testIntervalSkipsRunUnlessForced(): void
    {
        $this->runner()->run(new JobOptions(force: true, now: new DateTimeImmutable('2026-10-13 10:00:00')));

        $skipped = $this->runner()->run(new JobOptions(now: new DateTimeImmutable('2026-10-13 10:30:00')));
        $due = $this->runner()->run(new JobOptions(now: new DateTimeImmutable('2026-10-13 10:57:00')));

        self::assertTrue($skipped->skipped, '実行間隔1時間に対して30分後はスキップ');
        self::assertFalse($due->skipped, 'cron のずれ（5分以内）は許容して実行');
    }

    public function testOneFailingCategoryIsReportedAsPartialAndOthersContinue(): void
    {
        $result = $this->runner('Computers')->run(new JobOptions(force: true));

        self::assertSame(RunLog::PARTIAL, $result->log->status);
        self::assertStringContainsString('[R2]', $result->log->message);
        self::assertStringContainsString('InternalFailure', $result->log->message);
        self::assertGreaterThan(0, $result->log->notified, '他のルールは通知される');
        self::assertTrue($this->slack->sentErrorNotification(), 'エラー通知が送られる');
    }

    public function testSlackFailureIsRecordedAndRetriedNextRun(): void
    {
        $failed = $this->runner(slackFails: true)->run(new JobOptions(force: true));

        self::assertNotSame(RunLog::SUCCESS, $failed->log->status);
        $history = (new LocalJsonStorage($this->dir . '/data'))->loadHistory();
        self::assertNotEmpty($history);
        self::assertSame(NotificationRecord::STATUS_FAILED, $history[0]->status);

        // Slack が復旧した次の実行では「再送」として通知される
        $retry = $this->runner()->run(new JobOptions(force: true));
        self::assertSame(Verdict::Retry->label(), $retry->results[0]->verdict);
        self::assertGreaterThan(0, $retry->log->notified);
    }

    public function testDryRunDoesNotWriteOrNotify(): void
    {
        $result = $this->runner()->run(new JobOptions(dryRun: true));

        self::assertSame(RunLog::DRY_RUN, $result->log->status);
        self::assertSame([], $this->slack->requests);
        self::assertSame([], (new LocalJsonStorage($this->dir . '/data'))->loadRunLogs());
    }
}

/** Slack Webhook の代わりに送信内容を記録する */
final class SlackRecorder implements Transport
{
    /** @var list<array<string, mixed>> */
    public array $requests = [];
    public bool $fail = false;

    public function send(HttpRequest $request): HttpResponse
    {
        $payload = json_decode($request->body, true);
        if ($this->fail && !str_contains($payload['text'] ?? '', '実行エラー')) {
            return new HttpResponse(500, 'internal_error');
        }
        $this->requests[] = $payload;

        return new HttpResponse(200, 'ok');
    }

    public function sentErrorNotification(): bool
    {
        foreach ($this->requests as $payload) {
            if (str_contains($payload['text'] ?? '', '実行エラー')) {
                return true;
            }
        }

        return false;
    }
}
