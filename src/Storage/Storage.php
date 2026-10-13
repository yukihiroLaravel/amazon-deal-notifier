<?php

declare(strict_types=1);

namespace DealRadar\Storage;

use DealRadar\Domain\NotificationRecord;
use DealRadar\Domain\ResultRow;
use DealRadar\Domain\Rule;
use DealRadar\Domain\RunLog;
use DealRadar\Domain\Settings;

/** 設定・抽出結果・通知履歴・実行ログの保存先（Google スプレッドシート／ローカル JSON） */
interface Storage
{
    public function label(): string;

    public function loadSettings(): Settings;

    /** @return list<Rule> */
    public function loadRules(): array;

    /** @param list<ResultRow> $rows 抽出結果は毎回上書きする */
    public function saveResults(array $rows): void;

    /** @return list<ResultRow> */
    public function loadResults(): array;

    /** @return list<NotificationRecord> */
    public function loadHistory(): array;

    /** @param list<NotificationRecord> $records 通知履歴は全件を書き戻す（ルール×商品で1行） */
    public function saveHistory(array $records): void;

    public function appendRunLog(RunLog $log): void;

    /** @return list<RunLog> 新しい順 */
    public function loadRunLogs(int $limit = 50): array;
}
