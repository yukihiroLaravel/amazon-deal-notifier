<?php

declare(strict_types=1);

/**
 * ローカル保存（storage/data）の抽出結果・通知履歴・実行ログと Slack プレビューを消去する
 *
 *   php bin/reset-demo.php
 */

$root = dirname(__DIR__);
$removed = 0;
foreach ([...glob("{$root}/storage/data/*.json"), ...glob("{$root}/storage/slack/*.json")] as $file) {
    // ルール・基本設定は手で編集している可能性があるので残す
    if (in_array(basename($file), ['rules.json', 'settings.json'], true)) {
        continue;
    }
    unlink($file);
    $removed++;
}

echo "デモデータを消去しました（{$removed}ファイル）" . PHP_EOL;
