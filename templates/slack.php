<?php

use DealRadar\Dashboard\View as V;

/** @var DealRadar\Dashboard\DashboardData $data */

$previews = $data->slackPreviews();
$keys = array_keys($previews);
$selected = (string) ($_GET['msg'] ?? ($keys[0] ?? ''));
$preview = $previews[$selected] ?? null;
$rules = [];
foreach ($data->rules() as $rule) {
    $rules[$rule->id] = $rule->name;
}
$labelFor = static function (string $key) use ($rules): string {
    if ($key === 'error-latest') {
        return '⚠️ エラー通知';
    }
    $id = substr($key, strlen('deals-'));

    return $rules[$id] ?? $id;
};

/** Block Kit の一部（header / context / divider / section）を Slack 風に描画する */
$renderBlocks = static function (array $blocks): string {
    $html = '';
    foreach ($blocks as $block) {
        $html .= match ($block['type'] ?? '') {
            'header' => '<div class="sb-header">' . V::mrkdwn((string) ($block['text']['text'] ?? '')) . '</div>',
            'divider' => '<div class="sb-divider"></div>',
            'context' => '<div class="sb-context">' . implode(' ', array_map(static fn ($e) => V::mrkdwn((string) ($e['text'] ?? '')), $block['elements'] ?? [])) . '</div>',
            'section' => '<div class="sb-section"><div>'
                . (isset($block['text']) ? V::mrkdwn((string) $block['text']['text']) : '')
                . (isset($block['fields']) ? '<div class="sb-fields">' . implode('', array_map(static fn ($f) => '<div>' . V::mrkdwn((string) $f['text']) . '</div>', $block['fields'])) . '</div>' : '')
                . '</div>'
                . (isset($block['accessory']['image_url']) ? '<img class="sb-accessory" src="' . V::e($block['accessory']['image_url']) . '" alt="' . V::e($block['accessory']['alt_text'] ?? '') . '">' : '')
                . '</div>',
            default => '',
        };
    }

    return $html;
};
?>
<div class="page-head">
  <div>
    <h1>Slack 通知プレビュー</h1>
    <p>直近に送信した（または Webhook 未設定時に保存した）メッセージを、Slack での見た目に近い形で表示します。</p>
  </div>
  <span class="chip"><span class="dot <?= $data->slackConfigured() ? 'on' : 'off' ?>"></span><?= $data->slackConfigured() ? 'Incoming Webhook に送信中' : 'Webhook 未設定（プレビューのみ）' ?></span>
</div>

<?php if ($preview === null): ?>
  <section class="card"><div class="empty">まだ通知はありません。ジョブを実行すると、ここにプレビューが表示されます。</div></section>
<?php else: ?>
  <nav class="slack-tabs filters" style="padding: 0; border: 0" aria-label="メッセージを選択">
    <?php foreach ($keys as $key): ?>
      <a href="?page=slack&amp;msg=<?= urlencode($key) ?>" class="<?= $key === $selected ? 'active' : '' ?>"><?= V::e($labelFor($key)) ?></a>
    <?php endforeach; ?>
  </nav>

  <div class="slack-window">
    <div class="slack-bar"><span class="hash">#</span>amazon-deals<span class="status"><?= $preview['sent'] ? '送信済み' : 'プレビュー（未送信）' ?> · <?= V::dt(new DateTimeImmutable((string) $preview['savedAt'])) ?></span></div>
    <div class="slack-msg">
      <div class="slack-avatar"><?= V::icon('tag', 20) ?></div>
      <div style="min-width: 0">
        <div class="slack-name">Deal Radar<span class="app-badge">アプリ</span><time><?= V::dt(new DateTimeImmutable((string) $preview['savedAt']), 'H:i') ?></time></div>
        <?= $renderBlocks($preview['payload']['blocks'] ?? []) ?>
      </div>
    </div>
  </div>

  <details class="json-toggle">
    <summary>送信した JSON（Block Kit）を表示</summary>
    <pre class="code"><?= V::e(json_encode($preview['payload'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>
  </details>
<?php endif; ?>
