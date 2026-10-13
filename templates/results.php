<?php

use DealRadar\Dashboard\View as V;
use DealRadar\Domain\ResultRow;

/** @var DealRadar\Dashboard\DashboardData $data */

$all = $data->results();
$selected = (string) ($_GET['rule'] ?? '');
$byRule = [];
foreach ($all as $row) {
    $byRule[$row->ruleId] ??= ['name' => $row->ruleName, 'count' => 0];
    $byRule[$row->ruleId]['count']++;
}
$rows = $selected === '' ? $all : array_values(array_filter($all, static fn (ResultRow $r) => $r->ruleId === $selected));
$fetchedAt = $all[0]->fetchedAt ?? null;
?>
<div class="page-head">
  <div>
    <h1>抽出結果</h1>
    <p>条件に合致した商品を、ルールごとに割引率の高い順で表示しています（実行のたびに上書き）。</p>
  </div>
  <div class="head-meta">
    <span class="chip"><?= V::icon('clock', 14) ?>取得 <?= V::dt($fetchedAt) ?></span>
    <?php if ($url = $data->spreadsheetUrl()): ?><a class="btn" href="<?= V::e($url) ?>" target="_blank" rel="noopener"><?= V::icon('sheet', 15) ?>スプレッドシートで開く</a><?php endif; ?>
  </div>
</div>

<section class="card">
  <nav class="filters" aria-label="ルールで絞り込み">
    <a href="?page=results" class="<?= $selected === '' ? 'active' : '' ?>">すべて<span class="n"><?= count($all) ?></span></a>
    <?php foreach ($byRule as $id => $info): ?>
      <a href="?page=results&amp;rule=<?= urlencode($id) ?>" class="<?= $selected === $id ? 'active' : '' ?>"><?= V::e($info['name']) ?><span class="n"><?= $info['count'] ?></span></a>
    <?php endforeach; ?>
  </nav>
  <?php if ($rows === []): ?>
    <div class="empty">表示できる抽出結果がありません</div>
  <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th class="center">順位</th>
            <th>商品</th>
            <th class="right">販売価格</th>
            <th class="right">参考価格</th>
            <th class="right">割引額</th>
            <th>割引率</th>
            <th>判定</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $row): ?>
            <tr>
              <td class="center num"><strong><?= $row->rank ?></strong></td>
              <td class="cell-title">
                <a href="<?= V::e($row->url) ?>" target="_blank" rel="noopener" title="<?= V::e($row->title) ?>"><?= V::e($row->title) ?></a>
                <div class="sub"><span class="mono"><?= V::e($row->asin) ?></span> · <?= V::e($row->brand) ?> · <?= V::e($row->ruleName) ?></div>
              </td>
              <td class="right num"><strong><?= V::yen($row->price) ?></strong></td>
              <td class="right num muted"><?= V::yen($row->basePrice) ?></td>
              <td class="right num"><?= V::yen($row->savingsAmount) ?></td>
              <td>
                <div class="bar">
                  <div class="bar-track"><div class="bar-fill" style="width: <?= min(100, $row->discountRate / 70 * 100) ?>%"></div></div>
                  <span class="bar-value num"><?= V::pct($row->discountRate) ?></span>
                </div>
              </td>
              <td><span class="pill <?= V::verdictTone($row->verdict) ?>"><?= V::e($row->verdict) ?></span></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
  <div class="card-foot">判定：<strong>新規通知</strong> 初めて通知 ／ <strong>値下げ更新</strong> 前回より割引率が深くなった ／ <strong>通知済み</strong> 再通知日数内のため通知しない ／ <strong>件数上限</strong> 1回の通知件数を超えた分（次回以降に通知）</div>
</section>
