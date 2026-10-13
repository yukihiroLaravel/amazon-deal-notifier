<?php

declare(strict_types=1);

namespace DealRadar\Job;

use DealRadar\Support\Files;
use DateTimeImmutable;
use DealRadar\Analysis\DealFilter;
use DealRadar\Analysis\Deduplicator;
use DealRadar\Analysis\DiscountCalculator;
use DealRadar\Domain\Decision;
use DealRadar\Domain\NotificationRecord;
use DealRadar\Domain\ResultRow;
use DealRadar\Domain\Rule;
use DealRadar\Domain\RunLog;
use DealRadar\Domain\Settings;
use DealRadar\Domain\Verdict;
use DealRadar\Notify\SlackMessageBuilder;
use DealRadar\Notify\SlackNotifier;
use DealRadar\Paapi\PaapiProductSource;
use DealRadar\Storage\Storage;
use DealRadar\Support\Logger;
use Throwable;

/**
 * 1回分の処理：取得 → 割引率計算 → 条件抽出 → 重複判定 → Slack 通知 → 記録。
 *
 * 1つのルールで失敗しても他のルールは続行し、状態を「一部失敗」として記録・通知する。
 */
final class JobRunner
{
    /** cron の起動時刻のずれを吸収するための余裕（秒） */
    private const INTERVAL_TOLERANCE = 300;

    public function __construct(
        private readonly Storage $storage,
        private readonly PaapiProductSource $source,
        private readonly DiscountCalculator $calculator,
        private readonly DealFilter $filter,
        private readonly SlackNotifier $slack,
        private readonly SlackMessageBuilder $messages,
        private readonly Logger $logger,
        private readonly string $stateDir,
    ) {
    }

    public function run(JobOptions $options = new JobOptions()): JobResult
    {
        $now = $options->now ?? new DateTimeImmutable();
        $timer = microtime(true);
        $runId = $now->format('Ymd-His') . '-' . bin2hex(random_bytes(2));
        $errors = [];
        $fetched = $matched = $notified = $duplicates = 0;
        $results = [];
        $succeededRules = 0;
        $rules = [];

        $this->logger->info("実行開始 {$runId}", ['source' => $this->source->label(), 'storage' => $this->storage->label(), 'dryRun' => $options->dryRun]);

        try {
            $settings = $this->storage->loadSettings();

            if (!$options->force && !$options->dryRun && ($skip = $this->skipReason($settings, $now)) !== null) {
                $this->logger->info($skip);

                return new JobResult(new RunLog($runId, $now, self::finishedAt($now, $timer), RunLog::SKIPPED, $this->source->label(), 0, 0, 0, 0, $skip), [], true);
            }

            $rules = $this->activeRules($options, $errors);
            $history = $this->pruneHistory($this->storage->loadHistory(), $settings, $now);
            $deduplicator = new Deduplicator($settings, $now, $history);

            /** @var array<string, list<Decision>> $toNotify ルールID => 通知する判定 */
            $toNotify = [];
            foreach ($rules as $rule) {
                try {
                    $products = $this->source->fetch($rule, $settings->pagesPerRule);
                    $deals = $this->filter->apply($rule, $this->calculator->evaluateAll($products));
                    $decisions = $deduplicator->decideAll($rule, $deals);

                    $fetched += count($products);
                    $matched += count($deals);
                    foreach ($decisions as $i => $decision) {
                        $results[] = ResultRow::fromDecision($decision, $i + 1);
                        if ($decision->verdict->shouldNotify()) {
                            $toNotify[$rule->id][] = $decision;
                        } elseif ($decision->verdict === Verdict::Duplicate) {
                            $duplicates++;
                        }
                    }
                    $succeededRules++;
                    $this->logger->info("[{$rule->id}] {$rule->name}: 取得 " . count($products) . '件 / 該当 ' . count($deals) . '件 / 通知対象 ' . count($toNotify[$rule->id] ?? []) . '件');
                } catch (Throwable $e) {
                    $errors[] = "[{$rule->id}] {$rule->name}: " . $e->getMessage();
                    $this->logger->error("[{$rule->id}] 取得に失敗しました", ['error' => $e->getMessage()]);
                }
            }
            $this->saveLastExchange();

            if ($options->dryRun) {
                $this->logger->info('ドライランのため、保存と通知は行いません');
                $notified = array_sum(array_map('count', $toNotify));
            } else {
                $this->storage->saveResults($results);
                $notified = $this->notify($toNotify, $history, $now, $runId, $errors);
            }
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
            $this->logger->error('実行中にエラーが発生しました', ['error' => $e->getMessage(), 'at' => $e->getFile() . ':' . $e->getLine()]);
        }

        $status = match (true) {
            $options->dryRun && $errors === [] => RunLog::DRY_RUN,
            $errors === [] => RunLog::SUCCESS,
            $succeededRules > 0 => RunLog::PARTIAL,
            default => RunLog::FAILED,
        };
        $summary = sprintf('ルール %d件 / 取得 %d件 / 該当 %d件 / 通知 %d件 / 重複スキップ %d件', count($rules), $fetched, $matched, $notified, $duplicates);
        $log = new RunLog($runId, $now, self::finishedAt($now, $timer), $status, $this->source->label(), $fetched, $matched, $notified, $duplicates, $errors === [] ? $summary : implode(' / ', $errors));

        $this->finish($log, $errors, $options->dryRun);

        return new JobResult($log, $results, false);
    }

    /**
     * 送信前に「送信中」で記録 → 送信 → 「送信済み／送信失敗」に更新する。
     * 途中で処理が落ちても「送信中」が残るため、次回は再送扱いになり通知漏れを防げる。
     *
     * @param array<string, list<Decision>> $toNotify
     * @param list<NotificationRecord> $history
     * @param list<string> $errors
     */
    private function notify(array $toNotify, array $history, DateTimeImmutable $now, string $runId, array &$errors): int
    {
        if ($toNotify === []) {
            $this->storage->saveHistory($history);

            return 0;
        }

        $records = [];
        foreach ($history as $record) {
            $records[$record->key()] = $record;
        }
        foreach ($toNotify as $decisions) {
            foreach ($decisions as $d) {
                $key = NotificationRecord::makeKey($d->rule->id, $d->deal->product->asin);
                $records[$key] = new NotificationRecord(
                    ruleId: $d->rule->id,
                    asin: $d->deal->product->asin,
                    title: $d->deal->product->title,
                    discountRate: $d->deal->discountRate,
                    price: $d->deal->price,
                    notifiedAt: $now,
                    count: ($d->previous?->count ?? 0) + 1,
                    status: NotificationRecord::STATUS_PENDING,
                    verdict: $d->verdict->label(),
                );
            }
        }
        $this->storage->saveHistory(array_values($records));

        $sent = 0;
        foreach ($toNotify as $decisions) {
            $rule = $decisions[0]->rule;
            $status = NotificationRecord::STATUS_SENT;
            try {
                $this->slack->sendDeals($this->messages->deals($rule, $decisions, $decisions[0]->deal->product->fetchedAt, $runId), $rule->id);
                $sent += count($decisions);
                $this->logger->info("[{$rule->id}] Slack に " . count($decisions) . '件を通知' . ($this->slack->isConfigured() ? '' : '（Webhook 未設定のためプレビューのみ保存）'));
            } catch (Throwable $e) {
                $status = NotificationRecord::STATUS_FAILED;
                $errors[] = "[{$rule->id}] Slack 通知に失敗: " . $e->getMessage();
                $this->logger->error("[{$rule->id}] Slack 通知に失敗しました", ['error' => $e->getMessage()]);
            }
            foreach ($decisions as $d) {
                $key = NotificationRecord::makeKey($rule->id, $d->deal->product->asin);
                $records[$key] = $records[$key]->withStatus($status);
            }
        }
        $this->storage->saveHistory(array_values($records));

        return $sent;
    }

    /** @param list<string> $errors */
    private function finish(RunLog $log, array $errors, bool $dryRun): void
    {
        if (!$dryRun) {
            try {
                $this->storage->appendRunLog($log);
            } catch (Throwable $e) {
                $this->logger->error('実行ログの保存に失敗しました', ['error' => $e->getMessage()]);
            }
        }

        if ($errors !== [] && !$dryRun) {
            try {
                $this->slack->sendError($this->messages->error($log->runId, $log->status, $log->message, $errors, $log->startedAt));
            } catch (Throwable $e) {
                $this->logger->error('エラー通知の送信に失敗しました', ['error' => $e->getMessage()]);
            }
        }

        $level = $log->status === RunLog::FAILED ? 'error' : ($log->status === RunLog::PARTIAL ? 'warning' : 'info');
        $this->logger->{$level}("実行終了 {$log->runId}（{$log->status}・{$log->durationSeconds()}秒）", ['summary' => $log->message]);
    }

    private function skipReason(Settings $settings, DateTimeImmutable $now): ?string
    {
        foreach ($this->storage->loadRunLogs(20) as $log) {
            if (!$log->countsAsRun()) {
                continue;
            }
            $elapsed = $now->getTimestamp() - $log->startedAt->getTimestamp();
            if ($elapsed < $settings->intervalHours * 3600 - self::INTERVAL_TOLERANCE) {
                return sprintf('前回の実行（%s）から%d時間が経過していないためスキップします（--force で強制実行）', $log->startedAt->format('Y/m/d H:i'), $settings->intervalHours);
            }

            return null;
        }

        return null;
    }

    /**
     * @param list<string> $errors
     * @return list<Rule>
     */
    private function activeRules(JobOptions $options, array &$errors): array
    {
        $rules = [];
        foreach ($this->storage->loadRules() as $rule) {
            if ($options->ruleId !== null ? $rule->id !== $options->ruleId : !$rule->enabled) {
                continue;
            }
            $problems = $rule->validate();
            if ($problems !== []) {
                $errors[] = "[{$rule->id}] 設定に不備があります: " . implode('、', $problems);
                continue;
            }
            $rules[] = $rule;
        }

        if ($rules === [] && $errors === []) {
            $errors[] = $options->ruleId !== null ? "ルール {$options->ruleId} が見つかりません" : '有効な抽出ルールがありません';
        }

        return $rules;
    }

    /**
     * @param list<NotificationRecord> $history
     * @return list<NotificationRecord>
     */
    private function pruneHistory(array $history, Settings $settings, DateTimeImmutable $now): array
    {
        $limit = $now->getTimestamp() - $settings->historyRetentionDays * 86400;

        return array_values(array_filter($history, static fn (NotificationRecord $r): bool => $r->notifiedAt->getTimestamp() >= $limit));
    }

    /** 終了時刻は「開始時刻 + 経過時間」で求める（開始時刻を指定して実行した場合も所要時間が正しくなる） */
    private static function finishedAt(DateTimeImmutable $startedAt, float $timer): DateTimeImmutable
    {
        $seconds = (float) $startedAt->format('U.u') + (microtime(true) - $timer);

        return DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', $seconds))->setTimezone($startedAt->getTimezone());
    }

    private function saveLastExchange(): void
    {
        $exchange = $this->source->lastExchange();
        if ($exchange === null) {
            return;
        }
        $exchange['demo'] = $this->source->isDemo();
        Files::writeAtomic($this->stateDir . '/paapi_last_exchange.json', (string) json_encode($exchange, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    }
}
