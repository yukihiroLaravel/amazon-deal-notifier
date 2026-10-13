<?php

declare(strict_types=1);

use DealRadar\Dashboard\DashboardData;
use DealRadar\Support\App;

require dirname(__DIR__) . '/vendor/autoload.php';

$app = App::boot();

// 画面に PHP の警告を出さない（ログには残る）
ini_set('display_errors', '0');

// Basic 認証（DASHBOARD_USER と DASHBOARD_PASSWORD の両方を設定したときだけ有効）
$user = App::env('DASHBOARD_USER');
$password = App::env('DASHBOARD_PASSWORD');
if ($user !== null && $password !== null) {
    $ok = hash_equals($user, $_SERVER['PHP_AUTH_USER'] ?? '') && hash_equals($password, $_SERVER['PHP_AUTH_PW'] ?? '');
    if (!$ok) {
        header('WWW-Authenticate: Basic realm="Deal Radar"');
        http_response_code(401);
        exit('認証が必要です');
    }
}

$pages = [
    'overview' => ['概要', 'overview'],
    'results' => ['抽出結果', 'results'],
    'history' => ['通知履歴', 'history'],
    'runs' => ['実行ログ', 'runs'],
    'rules' => ['抽出条件・設定', 'rules'],
    'paapi' => ['PA-API 接続検証', 'paapi'],
    'slack' => ['Slack 通知プレビュー', 'slack'],
];
$page = (string) ($_GET['page'] ?? 'overview');
if (!isset($pages[$page])) {
    http_response_code(404);
    $page = 'overview';
}

$data = new DashboardData($app);

ob_start();
require dirname(__DIR__) . "/templates/{$page}.php";
$content = (string) ob_get_clean();

require dirname(__DIR__) . '/templates/layout.php';
