<?php

declare(strict_types=1);

namespace DealRadar\Notify;

use DealRadar\Support\Files;
use DealRadar\Http\HttpRequest;
use DealRadar\Http\Transport;
use RuntimeException;

/**
 * Slack Incoming Webhook へ送信する。
 *
 * Webhook URL が未設定のときは送信せず、プレビューの保存だけ行う
 * （ダッシュボードの「Slack プレビュー」で見た目を確認できる）。
 */
final class SlackNotifier
{
    public function __construct(
        private readonly Transport $transport,
        private readonly ?string $webhookUrl,
        private readonly ?string $errorWebhookUrl,
        private readonly string $previewDir,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->webhookUrl !== null && $this->webhookUrl !== '';
    }

    /** @param array<string, mixed> $payload */
    public function sendDeals(array $payload, string $key): void
    {
        $this->savePreview('deals', $key, $payload);
        if ($this->isConfigured()) {
            $this->post((string) $this->webhookUrl, $payload);
        }
    }

    /** @param array<string, mixed> $payload */
    public function sendError(array $payload): void
    {
        $this->savePreview('error', 'latest', $payload);
        $url = $this->errorWebhookUrl ?: $this->webhookUrl;
        if ($url !== null && $url !== '') {
            $this->post($url, $payload);
        }
    }

    /** @param array<string, mixed> $payload */
    private function post(string $url, array $payload): void
    {
        $body = (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $response = $this->transport->send(new HttpRequest('POST', $url, [['Content-Type', 'application/json; charset=utf-8']], $body));
            if ($response->isSuccess()) {
                return;
            }
            // レート制限時は Retry-After 秒待って再送する
            if ($response->status === 429) {
                sleep(min(30, (int) ($response->headers['retry-after'] ?? 1)));
                continue;
            }

            throw new RuntimeException("Slack への送信に失敗しました（HTTP {$response->status}: {$response->body}）");
        }

        throw new RuntimeException('Slack への送信がレート制限により失敗しました');
    }

    /** @param array<string, mixed> $payload */
    private function savePreview(string $type, string $key, array $payload): void
    {
        if (!is_dir($this->previewDir)) {
            mkdir($this->previewDir, 0775, true);
        }
        $file = $this->previewDir . "/{$type}-" . preg_replace('/[^A-Za-z0-9_-]/', '_', $key) . '.json';
        Files::writeAtomic($file, (string) json_encode([
            'savedAt' => date(DATE_ATOM),
            'sent' => $type === 'error' ? ($this->errorWebhookUrl ?: $this->webhookUrl) !== null : $this->isConfigured(),
            'payload' => $payload,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    }
}
