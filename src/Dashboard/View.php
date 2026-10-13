<?php

declare(strict_types=1);

namespace DealRadar\Dashboard;

use DateTimeImmutable;
use DealRadar\Domain\RunLog;

/** テンプレート用のヘルパー */
final class View
{
    public static function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function yen(?int $amount): string
    {
        return $amount === null ? '—' : '¥' . number_format($amount);
    }

    public static function pct(float $rate): string
    {
        return rtrim(rtrim(number_format($rate, 1, '.', ''), '0'), '.') . '%';
    }

    public static function dt(?DateTimeImmutable $at, string $format = 'Y/m/d H:i'): string
    {
        return $at === null ? '—' : $at->format($format);
    }

    public static function ago(?DateTimeImmutable $at): string
    {
        if ($at === null) {
            return '—';
        }
        $diff = time() - $at->getTimestamp();

        return match (true) {
            $diff < 0 => 'これから',
            $diff < 60 => 'たった今',
            $diff < 3600 => intdiv($diff, 60) . '分前',
            $diff < 86400 => intdiv($diff, 3600) . '時間前',
            default => intdiv($diff, 86400) . '日前',
        };
    }

    public static function statusTone(string $status): string
    {
        return match ($status) {
            RunLog::SUCCESS => 'success',
            RunLog::PARTIAL => 'warning',
            RunLog::FAILED => 'danger',
            RunLog::DRY_RUN => 'accent',
            default => 'neutral',
        };
    }

    public static function verdictTone(string $verdict): string
    {
        return match ($verdict) {
            '新規通知' => 'success',
            '値下げ更新' => 'drop',
            '再通知' => 'accent',
            '再送' => 'warning',
            '送信失敗' => 'danger',
            '送信中' => 'warning',
            '送信済み' => 'success',
            default => 'neutral',
        };
    }

    /** 割引率の強さ（バッジの色の段階） */
    public static function dealLevel(float $rate): string
    {
        return match (true) {
            $rate >= 50 => 'hot',
            $rate >= 35 => 'warm',
            default => 'mild',
        };
    }

    /** ブランド名から商品サムネイルの代わりになるイニシャルと色相を作る */
    public static function monogram(string $brand, string $fallback): array
    {
        $source = $brand !== '' ? $brand : $fallback;
        $initials = mb_strtoupper(mb_substr(preg_replace('/[^\p{L}\p{N}]/u', '', $source) ?: '?', 0, 2));

        return [$initials, crc32($source) % 360];
    }

    public static function icon(string $name, int $size = 18): string
    {
        $paths = [
            'overview' => '<rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/>',
            'results' => '<path d="M3 6h.01M3 12h.01M3 18h.01M8 6h13M8 12h13M8 18h13"/>',
            'history' => '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/>',
            'runs' => '<path d="M22 12h-4l-3 9L9 3l-3 9H2"/>',
            'rules' => '<path d="M21 4h-7M10 4H3M21 12h-9M8 12H3M21 20h-5M12 20H3M14 2v4M8 10v4M16 18v4"/>',
            'paapi' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/>',
            'slack' => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
            'external' => '<path d="M15 3h6v6M10 14 21 3M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>',
            'check' => '<path d="M20 6 9 17l-5-5"/>',
            'x' => '<path d="M18 6 6 18M6 6l12 12"/>',
            'arrow' => '<path d="M5 12h14M13 6l6 6-6 6"/>',
            'flask' => '<path d="M9 3h6M10 3v6.5L4.5 19a1.5 1.5 0 0 0 1.3 2.2h12.4a1.5 1.5 0 0 0 1.3-2.2L14 9.5V3"/><path d="M7 15h10"/>',
            'sheet' => '<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M3 15h18M9 3v18"/>',
            'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
            'alert' => '<path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/><path d="M12 9v4M12 17h.01"/>',
            'download' => '<path d="M12 3v12M7 10l5 5 5-5M5 21h14"/>',
            'tag' => '<path d="M20.6 13.4 13.4 20.6a2 2 0 0 1-2.8 0L3 13V3h10l7.6 7.6a2 2 0 0 1 0 2.8z"/><circle cx="7.5" cy="7.5" r="1.5"/>',
            'filter' => '<path d="M3 5h18l-7 8v6l-4 2v-8z"/>',
            'send' => '<path d="m22 2-7 20-4-9-9-4z"/><path d="M22 2 11 13"/>',
            'copy' => '<rect x="9" y="9" width="12" height="12" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>',
        ];

        return sprintf(
            '<svg class="icon" width="%d" height="%d" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">%s</svg>',
            $size,
            $size,
            $paths[$name] ?? '',
        );
    }

    /** Slack の mrkdwn（太字・取り消し線・コード・リンク・一部の絵文字）を HTML に変換する */
    public static function mrkdwn(string $text): string
    {
        $emoji = [
            ':new:' => '🆕', ':chart_with_downwards_trend:' => '📉', ':repeat:' => '🔁', ':arrows_counterclockwise:' => '🔄',
            ':warning:' => '⚠️', ':test_tube:' => '🧪', ':fire:' => '🔥', ':white_check_mark:' => '✅',
        ];

        $format = static function (string $plain) use ($emoji): string {
            $html = self::e(html_entity_decode($plain, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $html = strtr($html, $emoji);
            $html = preg_replace('/`([^`]+)`/u', '<code>$1</code>', $html);
            $html = preg_replace('/(?<![\w*])\*([^*\n]+)\*(?![\w*])/u', '<strong>$1</strong>', $html);
            $html = preg_replace('/(?<![\w~])~([^~\n]+)~(?![\w~])/u', '<del>$1</del>', $html);

            return nl2br($html, false);
        };

        // リンクを先に退避してから全体を整形する（「*<url|商品名>*」のように太字がリンクをまたぐため）
        $links = [];
        $text = (string) preg_replace_callback('/<([^|>]+)\|([^>]+)>/u', static function (array $m) use (&$links): string {
            $links[] = $m;

            return "\x00" . (count($links) - 1) . "\x00";
        }, $text);

        $html = $format($text);

        return (string) preg_replace_callback('/\x00(\d+)\x00/', static function (array $m) use ($links, $format): string {
            [, $url, $label] = $links[(int) $m[1]];

            return '<a href="' . self::e(html_entity_decode($url)) . '" target="_blank" rel="noopener">' . $format($label) . '</a>';
        }, $html);
    }
}
