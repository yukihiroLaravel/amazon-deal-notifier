<?php

use DealRadar\Dashboard\View as V;
use DealRadar\Domain\Settings;

/** @var DealRadar\Dashboard\DashboardData $data */

$rules = $data->rules();
$settings = $data->settings()->toArray();
$sheetUrl = $data->spreadsheetUrl();
?>
<div class="page-head">
  <div>
    <h1>抽出条件・設定</h1>
    <p>対象カテゴリー・割引率の条件・実行間隔は、<?= $sheetUrl ? 'Google スプレッドシート' : 'スプレッドシート（ローカル実行時は storage/data/*.json）' ?>で変更します。次回の実行から反映されます。</p>
  </div>
  <?php if ($sheetUrl): ?>
    <a class="btn primary" href="<?= V::e($sheetUrl) ?>" target="_blank" rel="noopener"><?= V::icon('sheet', 15) ?>スプレッドシートで編集</a>
  <?php endif; ?>
</div>

<div class="stack">
  <section class="card">
    <div class="card-head">
      <div>
        <h2>抽出ルール</h2>
        <p>「抽出ルール」シート。1行が1つの通知条件で、有効なルールだけが実行されます。</p>
      </div>
    </div>
    <div class="table-wrap">
      <table>
        <thead>
          <tr><th class="center">有効</th><th>ID</th><th>ルール名</th><th>カテゴリー</th><th>条件</th><th class="right">最大通知件数</th><th>メモ</th></tr>
        </thead>
        <tbody>
          <?php foreach ($rules as $rule): $problems = $rule->validate(); ?>
            <tr>
              <td class="center"><span class="switch <?= $rule->enabled ? 'on' : '' ?>" role="img" aria-label="<?= $rule->enabled ? '有効' : '無効' ?>"></span></td>
              <td><span class="chip"><?= V::e($rule->id) ?></span></td>
              <td><strong><?= V::e($rule->name) ?></strong>
                <?php foreach ($problems as $p): ?><div class="msg error"><?= V::e($p) ?></div><?php endforeach; ?>
              </td>
              <td><span class="mono"><?= V::e($rule->searchIndex) ?></span><?php if ($rule->browseNodeId !== ''): ?><div class="muted mono" style="font-size: 11.5px">BrowseNode <?= V::e($rule->browseNodeId) ?></div><?php endif; ?></td>
              <td><?= V::e($rule->conditionLabel()) ?></td>
              <td class="right num"><?= $rule->maxItems ?>件</td>
              <td class="muted" style="font-size: 12.5px"><?= V::e($rule->memo) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="card-foot">PA-API の SearchItems はキーワードかカテゴリーID（BrowseNodeId）のどちらかが必須です。割引率の下限は API 側（MinSavingPercent）でも絞り込み、リクエスト数を節約しています。</div>
  </section>

  <div class="grid-even">
    <section class="card">
      <div class="card-head">
        <div>
          <h2>基本設定</h2>
          <p>「基本設定」シート</p>
        </div>
      </div>
      <dl class="dl">
        <?php foreach (Settings::DEFINITIONS as $key => [$label, $default, $description]): ?>
          <div><dt><?= V::e($label) ?></dt></div>
          <div><dd class="num"><?= V::e($settings[$key]) ?><small><?= V::e($description) ?></small></dd></div>
        <?php endforeach; ?>
      </dl>
    </section>

    <section class="card">
      <div class="card-head">
        <div>
          <h2>定期実行</h2>
          <p>GitHub Actions（サーバー不要・無料枠内）</p>
        </div>
      </div>
      <div class="card-body stack">
        <div>
          <div class="code-label">.github/workflows/run.yml<span>毎時0分に起動</span></div>
          <pre class="code">on:
  schedule:
    - cron: <span class="hl">'0 * * * *'</span>   <span class="k"># 毎時（UTC）</span>
  workflow_dispatch:          <span class="k"># 手動実行ボタン</span></pre>
        </div>
        <p class="muted" style="margin: 0; font-size: 13px">起動は毎時ですが、実際に処理するのは「実行間隔（時間）」が経過したときだけです。間隔の変更はスプレッドシートだけで完結し、コードの変更は不要です。レンタルサーバーの cron でも同じコマンドで動きます。</p>
        <pre class="code">php bin/run.php            <span class="k"># 実行間隔に従って実行</span>
php bin/run.php --force    <span class="k"># 今すぐ実行</span>
php bin/run.php --dry-run  <span class="k"># 通知せずに結果だけ確認</span></pre>
      </div>
    </section>
  </div>
</div>
