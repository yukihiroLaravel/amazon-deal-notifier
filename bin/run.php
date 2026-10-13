<?php

declare(strict_types=1);

/**
 * 定期実行の入口（cron / GitHub Actions から呼ぶ）
 *
 *   php bin/run.php            実行間隔の設定に従って実行
 *   php bin/run.php --force    実行間隔を無視して実行
 *   php bin/run.php --dry-run  取得・抽出のみ（保存・通知しない）
 *   php bin/run.php --rule=R1  指定したルールだけ実行（無効のルールも可）
 */

use DealRadar\Job\JobOptions;
use DealRadar\Support\App;

require dirname(__DIR__) . '/vendor/autoload.php';

$opts = getopt('', ['force', 'dry-run', 'rule:', 'help']);
if (isset($opts['help'])) {
    echo "使い方: php bin/run.php [--force] [--dry-run] [--rule=R1]\n";
    exit(0);
}

$app = App::boot();
$result = $app->jobRunner()->run(new JobOptions(
    force: isset($opts['force']),
    dryRun: isset($opts['dry-run']),
    ruleId: isset($opts['rule']) ? (string) $opts['rule'] : null,
));

if ($result->results !== []) {
    echo PHP_EOL . "\033[1m抽出結果（割引率の高い順）\033[0m" . PHP_EOL;
    foreach ($result->results as $row) {
        printf(
            "  %-3s %2d. %5.1f%%  ¥%8s  %-10s  %s  %s\n",
            $row->ruleId,
            $row->rank,
            $row->discountRate,
            number_format($row->price),
            $row->verdict,
            $row->asin,
            mb_strimwidth($row->title, 0, 48, '…'),
        );
    }
    echo PHP_EOL;
}

exit($result->exitCode());
