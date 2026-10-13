<?php

declare(strict_types=1);

use DealRadar\Dashboard\DashboardData;
use DealRadar\Domain\RunLog;
use DealRadar\Job\JobOptions;
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

session_start();
$_SESSION['csrf'] ??= bin2hex(random_bytes(16));

// 「今すぐ取得」ボタン（.env の MANUAL_FETCH=on のときだけ表示・受付）。最短間隔（基本設定）と実行中ロックで、連打しても実行は1回に抑えられる
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'run') {
    if (App::env('MANUAL_FETCH') !== 'on') {
        http_response_code(404);
        exit('「今すぐ取得」は無効になっています（.env の MANUAL_FETCH=on で有効）');
    }
    if (!hash_equals($_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''))) {
        http_response_code(403);
        exit('画面を再読み込みしてから、もう一度お試しください');
    }
    $result = $app->jobRunner(false)->run(new JobOptions(manual: true));
    $log = $result->log;
    $_SESSION['flash'] = match (true) {
        $result->skipped => ['tone' => 'warning', 'title' => '取得しませんでした', 'text' => $log->message],
        $log->status === RunLog::SUCCESS => ['tone' => 'success', 'title' => "取得しました（通知 {$log->notified}件）", 'text' => $log->message],
        default => ['tone' => 'danger', 'title' => "取得しました（{$log->status}）", 'text' => $log->message],
    };
    header('Location: ?page=' . urlencode($page), true, 303);
    exit;
}
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$data = new DashboardData($app);

ob_start();
require dirname(__DIR__) . "/templates/{$page}.php";
$content = (string) ob_get_clean();

require dirname(__DIR__) . '/templates/layout.php';
