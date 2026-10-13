<?php

declare(strict_types=1);

/**
 * デモ用の実行履歴を作る（ローカル保存・デモモード専用）
 *
 *   php bin/seed-demo.php
 *
 * 価格の異なる4回分の実行を、3時間おきに行ったものとして記録する。
 * 2回目は同じ価格のため全件が重複スキップ、3回目は1カテゴリーで API エラー（一部失敗）、
 * 4回目は値下げ・新着が発生して通知される。
 */

use DealRadar\Job\JobOptions;
use DealRadar\Support\App;

require dirname(__DIR__) . '/vendor/autoload.php';

$app = App::boot();
if (!$app->isDemo() || strtolower(App::env('STORAGE') ?? 'local') !== 'local') {
    fwrite(STDERR, "デモモード（DATA_SOURCE=mock）かつローカル保存（STORAGE=local）のときだけ実行できます\n");
    exit(1);
}

passthru(PHP_BINARY . ' ' . escapeshellarg(__DIR__ . '/reset-demo.php'));

$now = new DateTimeImmutable();
$scenario = [
    ['-9 hours', 'A', null, '初回：割引商品を通知'],
    ['-6 hours', 'A', null, '同じ価格：すべて重複としてスキップ'],
    ['-3 hours', 'B', 'Computers', 'PC周辺機器で API エラー（一部失敗・エラー通知）'],
    ['now', 'C', null, '値下げ・新着を通知'],
];

foreach ($scenario as [$offset, $seed, $error, $label]) {
    $_ENV['MOCK_PRICE_SEED'] = "demo-{$seed}";
    $_ENV['MOCK_SIMULATE_ERROR'] = $error ?? '';
    $result = $app->jobRunner(false)->run(new JobOptions(force: true, now: $now->modify($offset)));
    printf("  %s  %-6s %s\n", $result->log->startedAt->format('m/d H:i'), $result->log->status, $label);
}

echo PHP_EOL . 'ダッシュボードで確認できます（docker compose up -d web）' . PHP_EOL;
