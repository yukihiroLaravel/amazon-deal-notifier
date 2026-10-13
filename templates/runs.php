<?php

use DealRadar\Dashboard\View as V;
use DealRadar\Domain\RunLog;

/** @var DealRadar\Dashboard\DashboardData $data */

$runs = $data->runLogs(100);
$failures = count(array_filter($runs, static fn (RunLog $r) => in_array($r->status, [RunLog::FAILED, RunLog::PARTIAL], true)));
?>
<div class="page-head">
  <div>
    <h1>実行ログ</h1>
    <p>定期実行ごとの件数・所要時間・エラー内容です。詳細なログは <span class="mono">storage/logs</span> にも出力されます。</p>
  </div>
  <div class="head-meta">
    <span class="chip">直近 <?= count($runs) ?>回</span>
    <span class="chip" style="<?= $failures ? 'color: var(--danger-text)' : '' ?>"><?= V::icon('alert', 14) ?>エラー <?= $failures ?>回</span>
  </div>
</div>

<section class="card">
  <?php if ($runs === []): ?>
    <div class="empty">実行履歴はまだありません</div>
  <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead>
          <tr><th>開始</th><th>状態</th><th class="right">所要</th><th class="right">取得</th><th class="right">該当</th><th class="right">通知</th><th class="right">重複</th><th>内容</th></tr>
        </thead>
        <tbody>
          <?php foreach ($runs as $run): $isError = in_array($run->status, [RunLog::FAILED, RunLog::PARTIAL], true); ?>
            <tr>
              <td class="nowrap"><span class="num"><?= V::dt($run->startedAt, 'Y/m/d H:i:s') ?></span><div class="mono muted" style="font-size: 11.5px"><?= V::e($run->runId) ?></div></td>
              <td><span class="pill <?= V::statusTone($run->status) ?>"><?= V::e($run->status) ?></span></td>
              <td class="right num muted nowrap"><?= $run->durationSeconds() !== null ? number_format($run->durationSeconds(), 1) . '秒' : '—' ?></td>
              <td class="right num"><?= $run->fetched ?></td>
              <td class="right num"><?= $run->matched ?></td>
              <td class="right num"><strong><?= $run->notified ?></strong></td>
              <td class="right num muted"><?= $run->skippedDuplicates ?></td>
              <td><div class="msg <?= $isError ? 'error' : '' ?>"><?= V::e($run->message) ?></div><div class="muted" style="font-size: 11.5px"><?= V::e($run->source) ?></div></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>
