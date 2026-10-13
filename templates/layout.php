<?php

use DealRadar\Dashboard\View as V;

/** @var DealRadar\Dashboard\DashboardData $data */
/** @var array<string, array{0: string, 1: string}> $pages */
/** @var string $page */
/** @var string $content */

$sig = $data->sigV4Summary();
$sheetUrl = $data->spreadsheetUrl();
$lastRun = $data->lastRun();
?>
<!doctype html>
<html lang="ja">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= V::e($pages[$page][0]) ?>｜Deal Radar</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500&family=Noto+Sans+JP:wght@400;500;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/assets/app.css?v=<?= filemtime(__DIR__ . '/../public/assets/app.css') ?>">
  <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><rect width='32' height='32' rx='8' fill='%234f46e5'/><circle cx='16' cy='16' r='8' fill='none' stroke='white' stroke-width='2.5'/><circle cx='16' cy='16' r='2.5' fill='%23fb923c'/></svg>">
</head>
<body>
<div class="app">
  <aside class="sidebar">
    <div class="brand">
      <div class="brand-mark">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9" opacity=".55"/><circle cx="12" cy="12" r="5"/><path d="M12 12 18.4 5.6"/><circle cx="12" cy="12" r="1.4" fill="#fff"/></svg>
      </div>
      <div>
        <div class="brand-name">Deal Radar</div>
        <div class="brand-sub">Amazon 割引通知ツール</div>
      </div>
    </div>

    <nav class="nav" aria-label="メニュー">
      <div class="nav-label">モニター</div>
      <?php foreach (['overview', 'results', 'history', 'runs'] as $key): ?>
        <a href="?page=<?= $key ?>" class="<?= $page === $key ? 'active' : '' ?>"><?= V::icon($pages[$key][1]) ?><?= V::e($pages[$key][0]) ?></a>
      <?php endforeach; ?>
      <div class="nav-label">設定・連携</div>
      <?php foreach (['rules', 'paapi', 'slack'] as $key): ?>
        <a href="?page=<?= $key ?>" class="<?= $page === $key ? 'active' : '' ?>"><?= V::icon($pages[$key][1]) ?><?= V::e($pages[$key][0]) ?>
          <?php if ($key === 'paapi'): ?><span class="count"><?= $sig['passed'] ?>/<?= $sig['total'] ?></span><?php endif; ?>
        </a>
      <?php endforeach; ?>
    </nav>

    <?php if ($sheetUrl): ?>
      <a class="sheet-link" href="<?= V::e($sheetUrl) ?>" target="_blank" rel="noopener">
        <span class="sheet-link-icon"><?= V::icon('sheet', 18) ?></span>
        <span><strong>スプレッドシートを開く</strong><small>条件の変更・結果の確認</small></span>
        <?= V::icon('external', 15) ?>
      </a>
    <?php endif; ?>

    <div class="sidebar-foot">
      <div class="env-row"><span>データ取得</span><span><span class="dot <?= $data->isDemo() ? 'demo' : 'on' ?>"></span><?= $data->isDemo() ? 'PA-API（デモ）' : 'PA-API 5.0' ?></span></div>
      <div class="env-row"><span>保存先</span><span><?= $sheetUrl ? 'スプレッドシート' : 'ローカル JSON' ?></span></div>
      <div class="env-row"><span>Slack</span><span><span class="dot <?= $data->slackConfigured() ? 'on' : 'off' ?>"></span><?= $data->slackConfigured() ? '接続済み' : 'プレビューのみ' ?></span></div>
      <div class="env-row"><span>最終実行</span><span><?= V::ago($lastRun?->startedAt) ?></span></div>
    </div>
  </aside>

  <main class="main">
    <?php if ($data->isDemo()): ?>
      <div class="banner" role="note">
        <?= V::icon('flask', 20) ?>
        <div class="banner-text">
          <strong>デモモードで動作中</strong> — 商品データは PA-API 5.0 と同じ形式のテストデータです（商品・ASIN は架空）。
          <small>リクエストの SigV4 署名は AWS 公式テストスイートで検証済み。認証キーを設定すれば本番の PA-API に切り替わります。</small>
        </div>
        <a class="chip" href="?page=paapi"><?= V::icon('paapi', 14) ?>署名検証 <?= $sig['passed'] ?>/<?= $sig['total'] ?> 合格</a>
      </div>
    <?php endif; ?>
    <?php if ($data->storageError() !== null): ?>
      <div class="banner error" role="alert">
        <?= V::icon('alert', 20) ?>
        <div class="banner-text"><strong>保存先に接続できません</strong><small><?= V::e($data->storageError()) ?></small></div>
      </div>
    <?php endif; ?>

    <?= $content ?>
  </main>
</div>
</body>
</html>
