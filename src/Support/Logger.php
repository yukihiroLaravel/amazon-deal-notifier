<?php

declare(strict_types=1);

namespace DealRadar\Support;

/** 1行1JSON のファイルログ（storage/logs/app-YYYY-MM-DD.log）＋コンソール出力 */
final class Logger
{
    public function __construct(
        private readonly string $dir,
        private readonly bool $echo = true,
    ) {
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
    }

    /** @param array<string, mixed> $context */
    public function info(string $message, array $context = []): void
    {
        $this->write('INFO', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function warning(string $message, array $context = []): void
    {
        $this->write('WARNING', $message, $context);
    }

    /** @param array<string, mixed> $context */
    public function error(string $message, array $context = []): void
    {
        $this->write('ERROR', $message, $context);
    }

    /** @param array<string, mixed> $context */
    private function write(string $level, string $message, array $context): void
    {
        $now = date('Y-m-d H:i:s');
        $line = json_encode(['time' => $now, 'level' => $level, 'message' => $message] + ($context === [] ? [] : ['context' => $context]), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        file_put_contents("{$this->dir}/app-" . date('Y-m-d') . '.log', $line . PHP_EOL, FILE_APPEND | LOCK_EX);

        if ($this->echo) {
            $color = ['INFO' => "\033[36m", 'WARNING' => "\033[33m", 'ERROR' => "\033[31m"][$level];
            $suffix = $context === [] ? '' : '  ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            fwrite($level === 'ERROR' ? STDERR : STDOUT, "{$color}[{$level}]\033[0m {$message}{$suffix}" . PHP_EOL);
        }
    }
}
