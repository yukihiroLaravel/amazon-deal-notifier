<?php

declare(strict_types=1);

namespace DealRadar\Storage;

/** スプレッドシートのタブ構成・見出し・列幅・表示形式の定義（setup-sheet と保存処理で共有） */
final class SheetSchema
{
    public const RULES = '抽出ルール';
    public const SETTINGS = '基本設定';
    public const RESULTS = '抽出結果';
    public const HISTORY = '通知履歴';
    public const RUN_LOG = '実行ログ';

    public const RULE_KEYS = ['enabled', 'id', 'name', 'searchIndex', 'browseNodeId', 'keywords', 'minDiscount', 'minPrice', 'maxPrice', 'maxItems', 'memo'];

    /** PA-API（amazon.co.jp）の主な SearchIndex（プルダウン用） */
    public const SEARCH_INDEXES = [
        'All', 'Electronics', 'Computers', 'HomeAndKitchen', 'Appliances', 'Beauty', 'HealthPersonalCare',
        'GroceryAndGourmetFood', 'Toys', 'VideoGames', 'SportsAndOutdoors', 'OfficeProducts', 'PetSupplies',
        'Fashion', 'Books', 'ToolsAndHomeImprovement', 'Automotive', 'Baby',
    ];

    /**
     * タブ名 => [見出し, 列幅(px), 列ごとの表示形式(null は既定)]
     *
     * @return array<string, array{headers: list<string>, widths: list<int>, formats: list<?string>}>
     */
    public static function tabs(): array
    {
        $yen = '"¥"#,##0';
        $pct = '0.0"%"';
        $dt = 'yyyy/mm/dd hh:mm:ss';

        return [
            self::RULES => [
                'headers' => ['有効', 'ルールID', 'ルール名', 'カテゴリー（SearchIndex）', 'カテゴリーID（BrowseNodeId）', 'キーワード（任意）', '最低割引率（%）', '最低価格（円）', '最高価格（円）', '最大通知件数', 'メモ'],
                'widths' => [56, 84, 200, 190, 200, 170, 120, 120, 120, 110, 260],
                'formats' => [null, null, null, null, '@', null, '0.0', $yen, $yen, '0', null],
            ],
            self::SETTINGS => [
                'headers' => ['設定キー', '項目', '値', '説明'],
                'widths' => [150, 240, 100, 560],
                'formats' => [null, null, null, null],
            ],
            self::RESULTS => [
                'headers' => ['取得日時', 'ルールID', 'ルール名', '順位', '判定', 'ASIN', '商品名', 'ブランド', '販売価格', '参考価格', '割引額', '割引率', '商品URL'],
                'widths' => [150, 76, 180, 56, 96, 110, 380, 120, 100, 100, 100, 84, 300],
                'formats' => [$dt, null, null, '0', null, null, null, null, $yen, $yen, $yen, $pct, null],
            ],
            self::HISTORY => [
                'headers' => ['ルールID', 'ASIN', '商品名', '通知時の割引率', '通知時の価格', '最終通知日時', '通知回数', '状態', '判定'],
                'widths' => [76, 110, 380, 120, 110, 150, 84, 96, 110],
                'formats' => [null, null, null, $pct, $yen, $dt, '0', null, null],
            ],
            self::RUN_LOG => [
                'headers' => ['実行ID', '開始', '終了', '所要秒', '状態', 'データソース', '取得件数', '該当件数', '通知件数', '重複スキップ', 'メッセージ'],
                'widths' => [150, 150, 150, 70, 90, 190, 80, 80, 80, 96, 480],
                'formats' => [null, $dt, $dt, '0.0', null, null, '0', '0', '0', '0', null],
            ],
        ];
    }

    /** @return list<array<string, mixed>> 初期状態の抽出ルール（デモ用） */
    public static function defaultRules(): array
    {
        return [
            ['enabled' => true, 'id' => 'R1', 'name' => '家電・カメラ 30%OFF以上', 'searchIndex' => 'Electronics', 'browseNodeId' => '3210981', 'keywords' => '', 'minDiscount' => 30, 'minPrice' => 3000, 'maxPrice' => null, 'maxItems' => 5, 'memo' => 'BrowseNodeId は amazon.co.jp「家電&カメラ」'],
            ['enabled' => true, 'id' => 'R2', 'name' => 'PC周辺機器 35%OFF以上', 'searchIndex' => 'Computers', 'browseNodeId' => '2127209051', 'keywords' => '', 'minDiscount' => 35, 'minPrice' => null, 'maxPrice' => 30000, 'maxItems' => 5, 'memo' => '3万円以下に限定'],
            ['enabled' => true, 'id' => 'R3', 'name' => 'キッチン家電 40%OFF以上', 'searchIndex' => 'HomeAndKitchen', 'browseNodeId' => '3828871', 'keywords' => '', 'minDiscount' => 40, 'minPrice' => null, 'maxPrice' => null, 'maxItems' => 5, 'memo' => ''],
            ['enabled' => false, 'id' => 'R4', 'name' => '（例）イヤホン キーワード指定', 'searchIndex' => 'Electronics', 'browseNodeId' => '', 'keywords' => 'イヤホン', 'minDiscount' => 20, 'minPrice' => null, 'maxPrice' => null, 'maxItems' => 3, 'memo' => '有効にチェックを入れると実行対象になります'],
        ];
    }
}
