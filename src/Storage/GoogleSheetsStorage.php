<?php

declare(strict_types=1);

namespace DealRadar\Storage;

use DealRadar\Domain\Rule;
use DealRadar\Domain\RunLog;
use DealRadar\Domain\Settings;
use Google\Client;
use Google\Service\Sheets;
use Google\Service\Sheets\ClearValuesRequest;
use Google\Service\Sheets\ValueRange;
use RuntimeException;

/**
 * Google スプレッドシートへの保存（Sheets API v4 + サービスアカウント。GAS は使わない）。
 *
 * 準備：サービスアカウントのメールアドレスをスプレッドシートの「編集者」として共有する。
 */
final class GoogleSheetsStorage implements Storage
{
    private readonly Sheets $sheets;

    public function __construct(
        private readonly string $spreadsheetId,
        string|array $credentials,
    ) {
        $client = new Client();
        $client->setApplicationName('Deal Radar');
        $client->setScopes([Sheets::SPREADSHEETS]);
        $client->setAuthConfig($credentials);
        $this->sheets = new Sheets($client);
    }

    public function service(): Sheets
    {
        return $this->sheets;
    }

    public function spreadsheetId(): string
    {
        return $this->spreadsheetId;
    }

    public function label(): string
    {
        return 'Google スプレッドシート';
    }

    public function url(): string
    {
        return "https://docs.google.com/spreadsheets/d/{$this->spreadsheetId}/edit";
    }

    public function loadSettings(): Settings
    {
        $values = [];
        foreach ($this->readRows(SheetSchema::SETTINGS) as $row) {
            if (($row[0] ?? '') !== '') {
                $values[(string) $row[0]] = $row[2] ?? '';
            }
        }

        return Settings::fromArray($values);
    }

    public function loadRules(): array
    {
        $rules = [];
        foreach ($this->readRows(SheetSchema::RULES) as $row) {
            if (trim((string) ($row[1] ?? '')) === '') {
                continue;
            }
            $rules[] = Rule::fromArray(array_combine(SheetSchema::RULE_KEYS, array_pad(array_slice($row, 0, count(SheetSchema::RULE_KEYS)), count(SheetSchema::RULE_KEYS), '')));
        }

        return $rules;
    }

    public function saveResults(array $rows): void
    {
        $this->replaceRows(SheetSchema::RESULTS, array_map(RowMapper::resultToRow(...), $rows));
    }

    public function loadResults(): array
    {
        return array_map(RowMapper::rowToResult(...), $this->readRows(SheetSchema::RESULTS));
    }

    public function loadHistory(): array
    {
        return array_map(RowMapper::rowToHistory(...), $this->readRows(SheetSchema::HISTORY));
    }

    public function saveHistory(array $records): void
    {
        $this->replaceRows(SheetSchema::HISTORY, array_map(RowMapper::historyToRow(...), $records));
    }

    public function appendRunLog(RunLog $log): void
    {
        $this->sheets->spreadsheets_values->append(
            $this->spreadsheetId,
            self::range(SheetSchema::RUN_LOG, 'A2'),
            new ValueRange(['values' => self::escapeFormulas([RowMapper::runLogToRow($log)])]),
            ['valueInputOption' => 'USER_ENTERED', 'insertDataOption' => 'INSERT_ROWS'],
        );
    }

    public function loadRunLogs(int $limit = 50): array
    {
        $rows = array_reverse($this->readRows(SheetSchema::RUN_LOG));

        return array_map(RowMapper::rowToRunLog(...), array_slice($rows, 0, $limit));
    }

    /** @return list<list<mixed>> 見出し行を除いたデータ行 */
    private function readRows(string $tab): array
    {
        $response = $this->sheets->spreadsheets_values->get($this->spreadsheetId, self::range($tab, 'A2:Z'), [
            'valueRenderOption' => 'UNFORMATTED_VALUE',
            'dateTimeRenderOption' => 'SERIAL_NUMBER',
        ]);

        return array_values(array_filter(
            $response->getValues() ?? [],
            static fn (array $row): bool => implode('', array_map('strval', $row)) !== '',
        ));
    }

    /** @param list<list<mixed>> $rows */
    private function replaceRows(string $tab, array $rows): void
    {
        $this->sheets->spreadsheets_values->clear($this->spreadsheetId, self::range($tab, 'A2:Z'), new ClearValuesRequest());
        if ($rows === []) {
            return;
        }
        $this->sheets->spreadsheets_values->update(
            $this->spreadsheetId,
            self::range($tab, 'A2'),
            new ValueRange(['values' => self::escapeFormulas($rows)]),
            ['valueInputOption' => 'USER_ENTERED'],
        );
    }

    /**
     * 商品名などが「=」で始まると数式として解釈されるため、文字列として書き込む
     *
     * @param list<list<mixed>> $rows
     * @return list<list<mixed>>
     */
    private static function escapeFormulas(array $rows): array
    {
        return array_map(static fn (array $row): array => array_map(
            static fn (mixed $v): mixed => is_string($v) && preg_match('/^[=+\-@]/', $v) ? "'" . $v : $v,
            $row,
        ), $rows);
    }

    private static function range(string $tab, string $cells): string
    {
        return "'" . str_replace("'", "''", $tab) . "'!{$cells}";
    }

    /** @return array<string, mixed> 認証情報（ファイルパス or JSON 文字列）を配列にする */
    public static function credentialsFrom(?string $path, ?string $json): array
    {
        if ($json !== null && trim($json) !== '') {
            $decoded = json_decode(str_starts_with(trim($json), '{') ? $json : (string) base64_decode($json), true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        if ($path !== null && is_file($path)) {
            return json_decode((string) file_get_contents($path), true);
        }

        throw new RuntimeException('Google サービスアカウントの認証情報が見つかりません（GOOGLE_APPLICATION_CREDENTIALS または GOOGLE_SERVICE_ACCOUNT_JSON）');
    }
}
