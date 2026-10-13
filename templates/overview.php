<?php

use DealRadar\Dashboard\View as V;
use DealRadar\Domain\ResultRow;

/** @var DealRadar\Dashboard\DashboardData $data */

$last = $data->lastRun();
$results = $data->results();
$history = $data->history();
$runs = array_slice($data->runLogs(), 0, 6);
$settings = $data->settings();
$rules = $data->rules();
$activeRules = array_filter($rules, static fn ($r) => $r->enabled);
$next = $data->nextRunAt();

$notifiedNow = array_filter($results, static fn (ResultRow $r) => in_array($r->verdict, ['新規通知', '値下げ更新', '再通知', '再送'], true));
$top = $results;
usort($top, static fn (ResultRow $a, ResultRow $b) => $b->discountRate <=> $a->discountRate);
$top = array_slice($top, 0, 8);
$best = $top[0] ?? null;
$sentTotal = array_sum(array_map(static fn ($h) => $h->count, $history));
?>
<div class="page-head">
  <div>
    <h1>概要</h1>
    <p>指定カテゴリーの商品を定期的に取得し、割引率の高い商品を Slack へ重複なく通知します。</p>
  </div>
  <div class="head-meta">
    <span class="chip"><?= V::icon('clock', 14) ?>実行間隔 <?= $settings->intervalHours ?>時間</span>
    <span class="chip"><?= V::icon('filter', 14) ?>有効なルール <?= count($activeRules) ?>件</span>
  </div>
</div>

<div class="stack">
  <section class="kpis">
    <div class="card kpi">
      <div class="kpi-label"><?= V::icon('runs', 15) ?>最終実行</div>
      <div class="kpi-value num"><?= $last ? V::dt($last->startedAt, 'H:i') : '—' ?><small><?= $last ? V::dt($last->startedAt, 'n/j') : '' ?></small></div>
      <div class="kpi-sub">
        <?php if ($last): ?><span class="pill <?= V::statusTone($last->status) ?>"><?= V::e($last->status) ?></span><?= V::ago($last->startedAt) ?><?php else: ?>まだ実行されていません<?php endif; ?>
      </div>
    </div>
    <div class="card kpi">
      <div class="kpi-label"><?= V::icon('send', 15) ?>今回の通知</div>
      <div class="kpi-value num"><?= count($notifiedNow) ?><small>件</small></div>
      <div class="kpi-sub">重複スキップ <?= $last?->skippedDuplicates ?? 0 ?>件</div>
    </div>
    <div class="card kpi">
      <div class="kpi-label"><?= V::icon('tag', 15) ?>最大割引率</div>
      <div class="kpi-value num" style="color: var(--deal)"><?= $best ? V::pct($best->discountRate) : '—' ?></div>
      <div class="kpi-sub"><?= $best ? V::e(mb_strimwidth($best->title, 0, 34, '…')) : '該当なし' ?></div>
    </div>
    <div class="card kpi">
      <div class="kpi-label"><?= V::icon('history', 15) ?>累計通知</div>
      <div class="kpi-value num"><?= number_format($sentTotal) ?><small>件</small></div>
      <div class="kpi-sub">次回実行 <?= $next ? V::dt($next, 'n/j H:i') : '—' ?></div>
    </div>
  </section>

  <section class="card" aria-label="処理の流れ">
    <div class="card-head">
      <div>
        <h2>処理の流れ（直近の実行）</h2>
        <p>取得 → 割引率の計算 → 条件で抽出 → 重複判定 → 通知 を1回の実行で行います</p>
      </div>
      <?php if ($last): ?><span class="mono muted"><?= V::e($last->runId) ?></span><?php endif; ?>
    </div>
    <div class="pipeline">
      <div class="step">
        <div class="step-no">STEP 1</div>
        <div class="step-title">商品を取得</div>
        <div class="step-value num"><?= $last?->fetched ?? 0 ?><small>件</small></div>
        <div class="step-desc">PA-API 5.0 SearchItems</div>
      </div>
      <div class="step">
        <div class="step-no">STEP 2</div>
        <div class="step-title">割引率を計算</div>
        <div class="step-value num"><small>最大</small><?= $best ? V::pct($best->discountRate) : '—' ?></div>
        <div class="step-desc">(参考価格 − 販売価格) ÷ 参考価格</div>
      </div>
      <div class="step">
        <div class="step-no">STEP 3</div>
        <div class="step-title">条件で抽出</div>
        <div class="step-value num"><?= $last?->matched ?? 0 ?><small>件</small></div>
        <div class="step-desc">割引率・価格帯・高い順</div>
      </div>
      <div class="step">
        <div class="step-no">STEP 4</div>
        <div class="step-title">重複を除外</div>
        <div class="step-value num"><?= $last?->skippedDuplicates ?? 0 ?><small>件</small></div>
        <div class="step-desc">通知済み <?= $settings->cooldownDays ?>日以内は除外</div>
      </div>
      <div class="step">
        <div class="step-no">STEP 5</div>
        <div class="step-title">Slack へ通知</div>
        <div class="step-value num" style="color: var(--success)"><?= $last?->notified ?? 0 ?><small>件</small></div>
        <div class="step-desc"><?= $data->slackConfigured() ? 'Incoming Webhook' : 'プレビューを保存' ?></div>
      </div>
    </div>
  </section>

  <div class="grid-2">
    <section class="card">
      <div class="card-head">
        <div>
          <h2>割引率の高い商品</h2>
          <p>直近の取得結果から、全ルール合わせて上位 <?= count($top) ?> 件</p>
        </div>
        <a class="btn" href="?page=results">すべて見る<?= V::icon('arrow', 15) ?></a>
      </div>
      <?php if ($top === []): ?>
        <div class="empty">まだデータがありません。<br><code>docker compose run --rm app php bin/run.php --force</code> で実行してください。</div>
      <?php else: ?>
        <ul class="deals">
          <?php foreach ($top as $row): [$mono, $hue] = V::monogram($row->brand, $row->title); ?>
            <li class="deal">
              <div class="thumb" style="--h: <?= $hue ?>"><?= V::e($mono) ?></div>
              <div style="min-width: 0">
                <a class="deal-title" href="<?= V::e($row->url) ?>" target="_blank" rel="noopener" title="<?= V::e($row->title) ?>"><?= V::e($row->title) ?></a>
                <div class="deal-meta">
                  <span class="pill <?= V::verdictTone($row->verdict) ?>"><?= V::e($row->verdict) ?></span>
                  <span><?= V::e($row->ruleName) ?></span>
                  <span class="mono"><?= V::e($row->asin) ?></span>
                </div>
              </div>
              <div class="deal-side">
                <div class="deal-price num">
                  <div class="price"><?= V::yen($row->price) ?></div>
                  <div class="was"><?= V::yen($row->basePrice) ?></div>
                </div>
                <span class="discount <?= V::dealLevel($row->discountRate) ?> num">-<?= V::pct($row->discountRate) ?></span>
              </div>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section>

    <section class="card">
      <div class="card-head">
        <div>
          <h2>実行ログ</h2>
          <p>定期実行の結果とエラー</p>
        </div>
        <a class="btn" href="?page=runs">詳細<?= V::icon('arrow', 15) ?></a>
      </div>
      <?php if ($runs === []): ?>
        <div class="empty">実行履歴はまだありません</div>
      <?php else: ?>
        <div class="table-wrap">
          <table>
            <thead><tr><th>開始</th><th>状態</th><th class="right">取得</th><th class="right">通知</th></tr></thead>
            <tbody>
              <?php foreach ($runs as $run): ?>
                <tr>
                  <td class="num nowrap"><?= V::dt($run->startedAt, 'n/j H:i') ?></td>
                  <td><span class="pill <?= V::statusTone($run->status) ?>"><?= V::e($run->status) ?></span></td>
                  <td class="right num"><?= $run->fetched ?></td>
                  <td class="right num"><strong><?= $run->notified ?></strong></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
      <div class="card-foot">エラー時は Slack にも通知され、スプレッドシートの「実行ログ」シートに記録されます。</div>
    </section>
  </div>
</div>
