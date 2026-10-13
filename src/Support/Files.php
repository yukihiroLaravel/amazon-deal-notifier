<?php

declare(strict_types=1);

namespace DealRadar\Support;

/** ファイル読み書きの共通処理 */
final class Files
{
    /**
     * 読み込みに失敗したら少し待って再試行する。
     * Docker Desktop（macOS）のバインドマウントでは、書き込み中のファイルを読むと
     * 一時的に EDEADLK（Resource deadlock avoided）で失敗することがあるため。
     */
    public static function read(string $path, int $attempts = 5): ?string
    {
        for ($i = 0; $i < $attempts; $i++) {
            $content = @file_get_contents($path);
            if ($content !== false) {
                return $content;
            }
            usleep(50_000 * ($i + 1));
        }

        return null;
    }

    /** @return array<mixed>|null */
    public static function readJson(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }
        $data = json_decode((string) self::read($path), true);

        return is_array($data) ? $data : null;
    }

    /** 一時ファイルに書いてから置き換える（読み込み側が書きかけの内容を見ないように） */
    public static function writeAtomic(string $path, string $content): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        file_put_contents($tmp, $content);
        rename($tmp, $path);
    }
}
