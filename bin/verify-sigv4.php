<?php

declare(strict_types=1);

/**
 * AWS 公式の SigV4 テストスイートで署名処理を検証する
 *
 *   php bin/verify-sigv4.php
 */

use DealRadar\Paapi\SigV4TestSuite;

require dirname(__DIR__) . '/vendor/autoload.php';

$results = SigV4TestSuite::default()->run();
$passed = count(array_filter($results, static fn ($r) => $r->passed()));
$mark = static fn (bool $ok): string => $ok ? "\033[32m✓\033[0m" : "\033[31m✗\033[0m";

echo "\033[1mAWS Signature Version 4 テストスイート\033[0m（tests/fixtures/aws4_testsuite）" . PHP_EOL;
echo '  正規リクエスト / 署名対象文字列 / Authorization の3点を、AWS が公開している期待値と照合します' . PHP_EOL . PHP_EOL;

foreach ($results as $r) {
    printf("  %s %s %s  %s\n", $mark($r->canonicalRequestOk), $mark($r->stringToSignOk), $mark($r->authorizationOk), $r->name);
}

$color = $passed === count($results) ? "\033[32m" : "\033[31m";
echo PHP_EOL . "{$color}{$passed} / " . count($results) . " 件合格\033[0m" . PHP_EOL;

exit($passed === count($results) ? 0 : 1);
