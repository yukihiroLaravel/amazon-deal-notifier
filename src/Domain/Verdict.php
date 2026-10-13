<?php

declare(strict_types=1);

namespace DealRadar\Domain;

/** 重複判定の結果 */
enum Verdict: string
{
    case New = 'new';
    case PriceDrop = 'price_drop';
    case Renotify = 'renotify';
    case Retry = 'retry';
    case Duplicate = 'duplicate';
    case OverLimit = 'over_limit';

    public function label(): string
    {
        return match ($this) {
            self::New => '新規通知',
            self::PriceDrop => '値下げ更新',
            self::Renotify => '再通知',
            self::Retry => '再送',
            self::Duplicate => '通知済み',
            self::OverLimit => '件数上限',
        };
    }

    public function shouldNotify(): bool
    {
        return match ($this) {
            self::New, self::PriceDrop, self::Renotify, self::Retry => true,
            self::Duplicate, self::OverLimit => false,
        };
    }

    public static function fromLabel(string $label): ?self
    {
        foreach (self::cases() as $case) {
            if ($case->label() === $label) {
                return $case;
            }
        }

        return null;
    }
}
