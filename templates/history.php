<?php

use DealRadar\Dashboard\View as V;

/** @var DealRadar\Dashboard\DashboardData $data */

$history = $data->history();
$settings = $data->settings();
?>
<div class="page-head">
  <div>
    <h1>通知履歴</h1>
    <p>ルール × 商品ごとに最後の通知を記録し、同じ商品が繰り返し通知されるのを防ぎます。</p>
  </div>
  <div class="head-meta">
    <span class="chip">再通知まで <?= $settings->cooldownDays ?>日</span>
    <span class="chip">値下げ再通知 +<?= rtrim(rtrim(number_format($settings->renotifyDeltaPt, 1), "0"), ".") ?>pt</span>
    <span class="chip">保持 <?= $settings->historyRetentionDays ?>日</span>
  </div>
</div>

<div class="stack">
  <div class="note">
    <?= V::icon('paapi', 16) ?>
    <div>送信前に「送信中」として記録し、送信できたら「送信済み」に更新します。Slack の障害などで送れなかった商品は次回の実行で自動的に<strong>再送</strong>されるため、通知漏れも二重通知も起きません。</div>
  </div>

  <section class="card">
    <?php if ($history === []): ?>
      <div class="empty">通知履歴はまだありません</div>
    <?php else: ?>
      <div class="table-wrap">
        <table>
          <thead>
            <tr><th>最終通知</th><th>ルール</th><th>商品</th><th class="right">通知時の割引率</th><th class="right">通知時の価格</th><th class="center">回数</th><th>判定</th><th>状態</th></tr>
          </thead>
          <tbody>
            <?php foreach ($history as $h): ?>
              <tr>
                <td class="num nowrap"><?= V::dt($h->notifiedAt) ?><div class="muted" style="font-size: 12px"><?= V::ago($h->notifiedAt) ?></div></td>
                <td><span class="chip"><?= V::e($h->ruleId) ?></span></td>
                <td class="cell-title"><span style="font-weight: 600"><?= V::e($h->title) ?></span><div class="sub mono"><?= V::e($h->asin) ?></div></td>
                <td class="right num"><strong style="color: var(--deal)"><?= V::pct($h->discountRate) ?></strong></td>
                <td class="right num"><?= V::yen($h->price) ?></td>
                <td class="center num"><?= $h->count ?></td>
                <td><span class="pill <?= V::verdictTone($h->verdict) ?>"><?= V::e($h->verdict) ?></span></td>
                <td><span class="pill <?= V::verdictTone($h->status) ?>"><?= V::e($h->status) ?></span></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>
</div>
