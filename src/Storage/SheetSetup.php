<?php

declare(strict_types=1);

namespace DealRadar\Storage;

use DealRadar\Domain\Settings;
use Google\Service\Sheets\BatchUpdateSpreadsheetRequest;
use Google\Service\Sheets\Request;
use Google\Service\Sheets\ValueRange;

/**
 * スプレッドシートの初期設定。タブの作成、見出し、初期データ、書式（列幅・表示形式・
 * 入力規則・条件付き書式）をまとめて整える。何度実行しても同じ状態になる（既存データは消さない）。
 */
final class SheetSetup
{
    private const HEADER_BG = '#1E293B';
    private const EDITABLE_BG = '#FFFBEB';
    private const BAND_BG = '#F8FAFC';
    private const TAB_COLORS = [
        SheetSchema::RULES => '#4F46E5',
        SheetSchema::SETTINGS => '#4F46E5',
        SheetSchema::RESULTS => '#EA580C',
        SheetSchema::HISTORY => '#0F766E',
        SheetSchema::RUN_LOG => '#64748B',
    ];

    public function __construct(private readonly GoogleSheetsStorage $storage)
    {
    }

    /** @return list<string> 実行内容のログ */
    public function run(): array
    {
        $log = [];
        $service = $this->storage->service();
        $id = $this->storage->spreadsheetId();

        // 1. 足りないタブを作成
        $sheets = $this->sheetInfo();
        $requests = [];
        foreach (array_keys(SheetSchema::tabs()) as $index => $title) {
            if (!isset($sheets[$title])) {
                $requests[] = ['addSheet' => ['properties' => ['title' => $title, 'index' => $index]]];
                $log[] = "タブを作成: {$title}";
            }
        }
        $this->batch($requests);
        $sheets = $this->sheetInfo();

        // 2. 見出し行と初期データ
        foreach (SheetSchema::tabs() as $title => $def) {
            $service->spreadsheets_values->update($id, "'{$title}'!A1", new ValueRange(['values' => [$def['headers']]]), ['valueInputOption' => 'RAW']);
        }
        if ($this->isEmpty(SheetSchema::RULES)) {
            $rows = array_map(static fn (array $r): array => array_map(
                static fn (string $key): mixed => $r[$key] ?? '',
                SheetSchema::RULE_KEYS,
            ), SheetSchema::defaultRules());
            $service->spreadsheets_values->update($id, "'" . SheetSchema::RULES . "'!A2", new ValueRange(['values' => $rows]), ['valueInputOption' => 'USER_ENTERED']);
            $log[] = '抽出ルールの初期値を書き込み';
        }
        if ($this->isEmpty(SheetSchema::SETTINGS)) {
            $rows = [];
            foreach (Settings::DEFINITIONS as $key => [$label, $default, $description]) {
                $rows[] = [$key, $label, $default, $description];
            }
            $service->spreadsheets_values->update($id, "'" . SheetSchema::SETTINGS . "'!A2", new ValueRange(['values' => $rows]), ['valueInputOption' => 'USER_ENTERED']);
            $log[] = '基本設定の初期値を書き込み';
        }

        // 3. 書式
        $requests = [];
        foreach (SheetSchema::tabs() as $title => $def) {
            $sheet = $sheets[$title];
            $requests = [...$requests, ...$this->formatRequests($title, $sheet, $def)];
        }

        // 4. 新規作成時の既定タブ（シート1）を削除
        foreach ($sheets as $title => $sheet) {
            if (in_array($title, ['シート1', 'Sheet1'], true) && $this->isEmpty($title)) {
                $requests[] = ['deleteSheet' => ['sheetId' => $sheet['id']]];
                $log[] = "既定のタブを削除: {$title}";
            }
        }

        $this->batch($requests);
        $log[] = '書式（見出し・列幅・表示形式・入力規則・条件付き書式）を適用';

        return $log;
    }

    /**
     * @param array{id: int, banded: bool, conditional: int} $sheet
     * @param array{headers: list<string>, widths: list<int>, formats: list<?string>} $def
     * @return list<array<string, mixed>>
     */
    private function formatRequests(string $title, array $sheet, array $def): array
    {
        $sid = $sheet['id'];
        $cols = count($def['headers']);
        $requests = [
            ['updateSheetProperties' => [
                'properties' => ['sheetId' => $sid, 'gridProperties' => ['frozenRowCount' => 1], 'tabColorStyle' => ['rgbColor' => self::rgb(self::TAB_COLORS[$title])]],
                'fields' => 'gridProperties.frozenRowCount,tabColorStyle',
            ]],
            // 見出し行
            ['repeatCell' => [
                'range' => ['sheetId' => $sid, 'startRowIndex' => 0, 'endRowIndex' => 1, 'startColumnIndex' => 0, 'endColumnIndex' => $cols],
                'cell' => ['userEnteredFormat' => [
                    'backgroundColorStyle' => ['rgbColor' => self::rgb(self::HEADER_BG)],
                    'textFormat' => ['bold' => true, 'fontSize' => 10, 'foregroundColorStyle' => ['rgbColor' => self::rgb('#FFFFFF')]],
                    'verticalAlignment' => 'MIDDLE',
                    'padding' => ['left' => 8, 'right' => 8],
                    'wrapStrategy' => 'CLIP',
                ]],
                'fields' => 'userEnteredFormat(backgroundColorStyle,textFormat,verticalAlignment,padding,wrapStrategy)',
            ]],
            ['updateDimensionProperties' => [
                'range' => ['sheetId' => $sid, 'dimension' => 'ROWS', 'startIndex' => 0, 'endIndex' => 1],
                'properties' => ['pixelSize' => 34],
                'fields' => 'pixelSize',
            ]],
            // データ行
            ['repeatCell' => [
                'range' => ['sheetId' => $sid, 'startRowIndex' => 1, 'startColumnIndex' => 0, 'endColumnIndex' => $cols],
                'cell' => ['userEnteredFormat' => ['verticalAlignment' => 'MIDDLE', 'textFormat' => ['fontSize' => 10], 'wrapStrategy' => 'CLIP']],
                'fields' => 'userEnteredFormat(verticalAlignment,textFormat.fontSize,wrapStrategy)',
            ]],
        ];

        foreach ($def['widths'] as $i => $width) {
            $requests[] = ['updateDimensionProperties' => [
                'range' => ['sheetId' => $sid, 'dimension' => 'COLUMNS', 'startIndex' => $i, 'endIndex' => $i + 1],
                'properties' => ['pixelSize' => $width],
                'fields' => 'pixelSize',
            ]];
        }
        foreach ($def['formats'] as $i => $pattern) {
            if ($pattern === null) {
                continue;
            }
            $type = match (true) {
                $pattern === '@' => 'TEXT',
                str_contains($pattern, 'yyyy') => 'DATE_TIME',
                str_contains($pattern, '¥') => 'CURRENCY',
                default => 'NUMBER',
            };
            $requests[] = ['repeatCell' => [
                'range' => self::column($sid, $i),
                'cell' => ['userEnteredFormat' => ['numberFormat' => ['type' => $type, 'pattern' => $pattern], 'horizontalAlignment' => $type === 'TEXT' ? 'LEFT' : 'RIGHT']],
                'fields' => 'userEnteredFormat(numberFormat,horizontalAlignment)',
            ]];
        }

        if (!$sheet['banded']) {
            $requests[] = ['addBanding' => ['bandedRange' => [
                'range' => ['sheetId' => $sid, 'startRowIndex' => 1, 'endRowIndex' => 1000, 'startColumnIndex' => 0, 'endColumnIndex' => $cols],
                'rowProperties' => ['firstBandColorStyle' => ['rgbColor' => self::rgb('#FFFFFF')], 'secondBandColorStyle' => ['rgbColor' => self::rgb(self::BAND_BG)]],
            ]]];
        }

        // 既存の条件付き書式は作り直す（重複して増えないように）
        for ($i = $sheet['conditional'] - 1; $i >= 0; $i--) {
            $requests[] = ['deleteConditionalFormatRule' => ['sheetId' => $sid, 'index' => $i]];
        }

        return [...$requests, ...match ($title) {
            SheetSchema::RULES => $this->rulesTab($sid),
            SheetSchema::SETTINGS => $this->settingsTab($sid),
            SheetSchema::RESULTS => [
                self::gradient($sid, 11),
                ...self::textColors($sid, 4, ['新規通知' => '#DCFCE7', '値下げ更新' => '#FFEDD5', '再通知' => '#E0E7FF', '再送' => '#FEF9C3', '通知済み' => '#F1F5F9', '件数上限' => '#F1F5F9']),
            ],
            SheetSchema::HISTORY => [
                self::gradient($sid, 3),
                ...self::textColors($sid, 7, ['送信失敗' => '#FEE2E2', '送信中' => '#FEF9C3']),
            ],
            SheetSchema::RUN_LOG => self::textColors($sid, 4, ['成功' => '#DCFCE7', '一部失敗' => '#FEF3C7', '失敗' => '#FEE2E2', 'スキップ' => '#F1F5F9', 'ドライラン' => '#E0E7FF']),
            default => [],
        }];
    }

    /** @return list<array<string, mixed>> */
    private function rulesTab(int $sid): array
    {
        return [
            ['setDataValidation' => ['range' => self::column($sid, 0, 200), 'rule' => ['condition' => ['type' => 'BOOLEAN'], 'strict' => true]]],
            ['setDataValidation' => ['range' => self::column($sid, 3, 200), 'rule' => [
                'condition' => ['type' => 'ONE_OF_LIST', 'values' => array_map(static fn (string $v): array => ['userEnteredValue' => $v], SheetSchema::SEARCH_INDEXES)],
                'showCustomUi' => true,
                'strict' => true,
            ]]],
            ['setDataValidation' => ['range' => self::column($sid, 6, 200), 'rule' => [
                'condition' => ['type' => 'NUMBER_BETWEEN', 'values' => [['userEnteredValue' => '0'], ['userEnteredValue' => '99']]],
                'inputMessage' => '0〜99 の数値（例: 30 → 30%以上）',
                'strict' => true,
            ]]],
            ['setDataValidation' => ['range' => self::column($sid, 9, 200), 'rule' => [
                'condition' => ['type' => 'NUMBER_BETWEEN', 'values' => [['userEnteredValue' => '1'], ['userEnteredValue' => '20']]],
                'inputMessage' => '1回の通知に載せる最大件数（1〜20）',
                'strict' => true,
            ]]],
            ['repeatCell' => [
                'range' => self::column($sid, 0, 200),
                'cell' => ['userEnteredFormat' => ['horizontalAlignment' => 'CENTER']],
                'fields' => 'userEnteredFormat.horizontalAlignment',
            ]],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function settingsTab(int $sid): array
    {
        return [
            // 設定キー列はプログラム用なので隠す
            ['updateDimensionProperties' => [
                'range' => ['sheetId' => $sid, 'dimension' => 'COLUMNS', 'startIndex' => 0, 'endIndex' => 1],
                'properties' => ['hiddenByUser' => true],
                'fields' => 'hiddenByUser',
            ]],
            ['repeatCell' => [
                'range' => self::column($sid, 2, 20),
                'cell' => ['userEnteredFormat' => ['backgroundColorStyle' => ['rgbColor' => self::rgb(self::EDITABLE_BG)], 'textFormat' => ['bold' => true], 'horizontalAlignment' => 'CENTER']],
                'fields' => 'userEnteredFormat(backgroundColorStyle,textFormat.bold,horizontalAlignment)',
            ]],
            ['repeatCell' => [
                'range' => self::column($sid, 3, 20),
                'cell' => ['userEnteredFormat' => ['wrapStrategy' => 'WRAP', 'textFormat' => ['foregroundColorStyle' => ['rgbColor' => self::rgb('#475569')]]]],
                'fields' => 'userEnteredFormat(wrapStrategy,textFormat.foregroundColorStyle)',
            ]],
        ];
    }

    /** @return array<string, mixed> 割引率の列を白→オレンジのグラデーションに */
    private static function gradient(int $sid, int $col): array
    {
        return ['addConditionalFormatRule' => ['index' => 0, 'rule' => [
            'ranges' => [self::column($sid, $col)],
            'gradientRule' => [
                'minpoint' => ['type' => 'NUMBER', 'value' => '0', 'colorStyle' => ['rgbColor' => self::rgb('#FFFFFF')]],
                'midpoint' => ['type' => 'NUMBER', 'value' => '30', 'colorStyle' => ['rgbColor' => self::rgb('#FED7AA')]],
                'maxpoint' => ['type' => 'NUMBER', 'value' => '60', 'colorStyle' => ['rgbColor' => self::rgb('#FB923C')]],
            ],
        ]]];
    }

    /**
     * @param array<string, string> $colors 値 => 背景色
     * @return list<array<string, mixed>>
     */
    private static function textColors(int $sid, int $col, array $colors): array
    {
        $requests = [];
        foreach ($colors as $text => $bg) {
            $requests[] = ['addConditionalFormatRule' => ['index' => 0, 'rule' => [
                'ranges' => [self::column($sid, $col)],
                'booleanRule' => [
                    'condition' => ['type' => 'TEXT_EQ', 'values' => [['userEnteredValue' => $text]]],
                    'format' => ['backgroundColorStyle' => ['rgbColor' => self::rgb($bg)], 'textFormat' => ['bold' => true]],
                ],
            ]]];
        }

        return $requests;
    }

    /** @return array<string, int> */
    private static function column(int $sid, int $col, int $endRow = 2000): array
    {
        return ['sheetId' => $sid, 'startRowIndex' => 1, 'endRowIndex' => $endRow, 'startColumnIndex' => $col, 'endColumnIndex' => $col + 1];
    }

    /** @return array{red: float, green: float, blue: float} */
    private static function rgb(string $hex): array
    {
        [$r, $g, $b] = sscanf($hex, '#%02x%02x%02x');

        return ['red' => $r / 255, 'green' => $g / 255, 'blue' => $b / 255];
    }

    /** @return array<string, array{id: int, banded: bool, conditional: int}> */
    private function sheetInfo(): array
    {
        $spreadsheet = $this->storage->service()->spreadsheets->get($this->storage->spreadsheetId(), [
            'fields' => 'sheets(properties(sheetId,title),bandedRanges(bandedRangeId),conditionalFormats(ranges))',
        ]);
        $info = [];
        foreach ($spreadsheet->getSheets() as $sheet) {
            $info[$sheet->getProperties()->getTitle()] = [
                'id' => $sheet->getProperties()->getSheetId(),
                'banded' => count($sheet->getBandedRanges() ?? []) > 0,
                'conditional' => count($sheet->getConditionalFormats() ?? []),
            ];
        }

        return $info;
    }

    private function isEmpty(string $tab): bool
    {
        $values = $this->storage->service()->spreadsheets_values->get($this->storage->spreadsheetId(), "'{$tab}'!A2:B")->getValues();

        return $values === null || $values === [];
    }

    /** @param list<array<string, mixed>> $requests */
    private function batch(array $requests): void
    {
        if ($requests === []) {
            return;
        }
        $this->storage->service()->spreadsheets->batchUpdate(
            $this->storage->spreadsheetId(),
            new BatchUpdateSpreadsheetRequest(['requests' => array_map(static fn (array $r): Request => new Request($r), $requests)]),
        );
    }
}
