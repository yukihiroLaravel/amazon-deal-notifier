<?php

use DealRadar\Dashboard\View as V;

/** @var DealRadar\Dashboard\DashboardData $data */

$results = $data->sigV4Results();
$summary = $data->sigV4Summary();
$percent = $summary['total'] > 0 ? round($summary['passed'] / $summary['total'] * 100) : 0;
$exchange = $data->lastExchange();
$tick = static fn (bool $ok, string $label): string => sprintf('<span class="tick %s" title="%s">%s</span>', $ok ? 'ok' : 'ng', V::e($label), V::icon($ok ? 'check' : 'x', 12));

/** 正規リクエストの各行に意味を添えて表示する */
$annotate = static function (string $canonical): string {
    $lines = explode("\n", $canonical);
    $out = [];
    foreach ($lines as $i => $line) {
        $escaped = V::e($line);
        $out[] = match (true) {
            $i === 0 => '<span class="hl">' . $escaped . '</span>',
            $i === count($lines) - 1 => '<span class="k">' . $escaped . '</span>',
            default => $escaped,
        };
    }

    return implode("\n", $out);
};
?>
<div class="page-head">
  <div>
    <h1>PA-API 接続検証</h1>
    <p>Amazon Product Advertising API 5.0 は AWS Signature Version 4 で認証します。署名処理を AWS の公式テストデータで検証しています。</p>
  </div>
</div>

<div class="stack">
  <section class="card">
    <div class="verify-hero">
      <div class="ring" style="--p: <?= $percent ?>">
        <div class="ring-inner"><div><strong class="num"><?= $summary['passed'] ?>/<?= $summary['total'] ?></strong><span>合格</span></div></div>
      </div>
      <div>
        <h2>AWS Signature Version 4 テストスイート</h2>
        <p>AWS が公開している署名のテストデータ（<span class="mono">tests/fixtures/aws4_testsuite</span>）を、本ツールの署名処理 <span class="mono">SigV4Signer</span> に通し、3つの中間結果がすべて期待値と一致することを確認しています。この画面を開くたびに再計算しています。</p>
        <div class="checks">
          <span class="chip">① 正規リクエスト（Canonical Request）</span>
          <span class="chip">② 署名対象文字列（String to Sign）</span>
          <span class="chip">③ Authorization ヘッダー</span>
        </div>
      </div>
    </div>
    <div class="card-head" style="border-top: 1px solid var(--border)">
      <div><h2>テストベクター</h2><p>パスの正規化・クエリの並べ替え・UTF-8・重複ヘッダー・セッショントークンなどのケースを含みます</p></div>
      <div class="legend"><span>① ② ③ の順に表示</span></div>
    </div>
    <div class="vectors">
      <?php foreach ($results as $r): ?>
        <div class="vector">
          <span class="vector-name" title="<?= V::e($r->name) ?>"><?= V::e($r->name) ?></span>
          <span class="vector-checks"><?= $tick($r->canonicalRequestOk, '正規リクエスト') ?><?= $tick($r->stringToSignOk, '署名対象文字列') ?><?= $tick($r->authorizationOk, 'Authorization') ?></span>
        </div>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="card">
    <div class="card-head">
      <div>
        <h2>直近の PA-API リクエスト（SearchItems）</h2>
        <p><?= ($exchange['demo'] ?? true) ? 'デモでは AWS テストスイートの例示用キー（AKIDEXAMPLE）で署名し、デモサーバーが受信側として署名を再計算して照合しています' : '本番の PA-API への直近のリクエスト（アクセスキーは一部を伏せています）' ?></p>
      </div>
      <?php if ($exchange): ?>
        <span class="pill <?= $exchange['status'] === 200 ? 'success' : 'danger' ?>">HTTP <?= (int) $exchange['status'] ?></span>
      <?php endif; ?>
    </div>
    <?php if (!$exchange): ?>
      <div class="empty">まだリクエストがありません。ジョブを実行すると表示されます。</div>
    <?php else: ?>
      <div class="card-body stack">
        <div class="flow">
          <div class="flow-item done"><b>1. リクエスト作成</b><span>SearchIndex・BrowseNodeId・MinSavingPercent など</span></div>
          <div class="flow-item done"><b>2. SigV4 署名</b><span>us-west-2 / ProductAdvertisingAPI</span></div>
          <div class="flow-item done"><b>3. 送信・署名検証</b><span><?= V::e($exchange['verifiedBy'] ?? 'PA-API が検証') ?></span></div>
          <div class="flow-item done"><b>4. レスポンス解析</b><span><?= (int) $exchange['itemCount'] ?>件の商品（Offers.Listings）</span></div>
        </div>

        <div class="grid-even">
          <div>
            <div class="code-label">リクエスト<span><?= V::e($exchange['at'] ?? '') ?></span></div>
            <table class="kv">
              <tr><td>POST</td><td><?= V::e($exchange['endpoint']) ?></td></tr>
              <?php foreach ($exchange['headers'] as [$name, $value]): ?>
                <tr><td><?= V::e($name) ?></td><td><?= V::e($value) ?></td></tr>
              <?php endforeach; ?>
            </table>
          </div>
          <div>
            <div class="code-label">リクエストボディ<span>JSON</span></div>
            <pre class="code"><?= V::e(json_encode($exchange['payload'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>
          </div>
        </div>

        <div class="grid-even">
          <div>
            <div class="code-label">① 正規リクエスト<span>最終行はボディの SHA-256</span></div>
            <pre class="code"><?= $annotate($exchange['canonicalRequest']) ?></pre>
          </div>
          <div class="stack" style="align-content: start">
            <div>
              <div class="code-label">② 署名対象文字列</div>
              <pre class="code"><?= V::e($exchange['stringToSign']) ?></pre>
            </div>
            <div>
              <div class="code-label">③ Authorization ヘッダー</div>
              <pre class="code" style="white-space: pre-wrap; word-break: break-all"><?= V::e($exchange['authorization']) ?></pre>
            </div>
          </div>
        </div>
      </div>
    <?php endif; ?>
  </section>

  <section class="card">
    <div class="card-head">
      <div>
        <h2>本番の PA-API への切り替え</h2>
        <p>コードの変更は不要です。<span class="mono">.env</span>（GitHub Actions では Secrets）を設定するだけで切り替わります</p>
      </div>
    </div>
    <div class="card-body grid-even">
      <pre class="code">DATA_SOURCE=<span class="hl">paapi</span>
PAAPI_ACCESS_KEY=<span class="k">（アソシエイト管理画面で発行）</span>
PAAPI_SECRET_KEY=<span class="k">（同上）</span>
PAAPI_PARTNER_TAG=<span class="k">xxxxx-22</span></pre>
      <div class="note" style="align-self: start">
        <?= V::icon('alert', 16) ?>
        <div>PA-API の利用には Amazon アソシエイトの承認と売上実績が必要です（Amazon Business のアカウントとは別）。また Amazon は後継の Creators API への移行を進めているため、仕様が変わった場合は <span class="mono">PaapiClient</span> と <span class="mono">PaapiResponseParser</span> の修正だけで対応できる構成にしています。</div>
      </div>
    </div>
  </section>
</div>
