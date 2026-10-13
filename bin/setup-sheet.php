<?php

declare(strict_types=1);

/**
 * Google スプレッドシートの初期設定（タブ作成・見出し・初期データ・書式）
 *
 *   php bin/setup-sheet.php
 *
 * 事前に .env の STORAGE=sheets / SPREADSHEET_ID / 認証情報を設定し、
 * サービスアカウントのメールアドレスをスプレッドシートの編集者として共有しておく。
 */

use DealRadar\Storage\GoogleSheetsStorage;
use DealRadar\Storage\SheetSetup;
use DealRadar\Support\App;

require dirname(__DIR__) . '/vendor/autoload.php';

$app = App::boot();
$storage = $app->storage();
if (!$storage instanceof GoogleSheetsStorage) {
    fwrite(STDERR, "STORAGE=sheets が設定されていません（.env を確認してください）\n");
    exit(1);
}

foreach ((new SheetSetup($storage))->run() as $line) {
    echo "  ✓ {$line}" . PHP_EOL;
}
echo PHP_EOL . '完了: ' . $storage->url() . PHP_EOL;
