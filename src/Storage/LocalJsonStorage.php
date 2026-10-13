<?php

declare(strict_types=1);

namespace DealRadar\Storage;

use DealRadar\Support\Files;
use DealRadar\Domain\NotificationRecord;
use DealRadar\Domain\ResultRow;
use DealRadar\Domain\Rule;
use DealRadar\Domain\RunLog;
use DealRadar\Domain\Settings;

/**
 * ローカル JSON ファイルへの保存（Google の認証情報なしでデモを動かすため）。
 * 行の形式はスプレッドシートと同じ（RowMapper）にしてあり、保存先だけが異なる。
 */
final class LocalJsonStorage implements Storage
{
    public function __construct(private readonly string $dir)
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
    }

    public function label(): string
    {
        return 'ローカル JSON（storage/data）';
    }

    public function loadSettings(): Settings
    {
        return Settings::fromArray($this->read('settings', Settings::defaults()->toArray()));
    }

    public function loadRules(): array
    {
        return array_map(Rule::fromArray(...), $this->read('rules', SheetSchema::defaultRules()));
    }

    public function saveResults(array $rows): void
    {
        $this->write('results', array_map(RowMapper::resultToRow(...), $rows));
    }

    public function loadResults(): array
    {
        return array_map(RowMapper::rowToResult(...), $this->read('results', []));
    }

    public function loadHistory(): array
    {
        return array_map(RowMapper::rowToHistory(...), $this->read('history', []));
    }

    public function saveHistory(array $records): void
    {
        $this->write('history', array_map(RowMapper::historyToRow(...), $records));
    }

    public function appendRunLog(RunLog $log): void
    {
        $rows = $this->read('run_log', []);
        $rows[] = RowMapper::runLogToRow($log);
        $this->write('run_log', array_slice($rows, -500));
    }

    public function loadRunLogs(int $limit = 50): array
    {
        $rows = array_reverse($this->read('run_log', []));

        return array_map(RowMapper::rowToRunLog(...), array_slice($rows, 0, $limit));
    }

    /** ルール・設定をファイルに書き出す（設定を手で編集したいとき用） */
    public function seedConfig(): void
    {
        if (!is_file($this->path('rules'))) {
            $this->write('rules', SheetSchema::defaultRules());
        }
        if (!is_file($this->path('settings'))) {
            $this->write('settings', Settings::defaults()->toArray());
        }
    }

    private function path(string $name): string
    {
        return "{$this->dir}/{$name}.json";
    }

    /** @return array<mixed> */
    private function read(string $name, array $default): array
    {
        return Files::readJson($this->path($name)) ?? $default;
    }

    /** @param array<mixed> $data */
    private function write(string $name, array $data): void
    {
        // 書き込み途中で落ちても壊れないよう、一時ファイルに書いてから置き換える
        Files::writeAtomic($this->path($name), (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    }
}
