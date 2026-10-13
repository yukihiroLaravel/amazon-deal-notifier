<?php

declare(strict_types=1);

namespace DealRadar\Domain;

use DateTimeImmutable;

/** 通知履歴（ルール×商品ごとに1行。重複防止の判定に使う） */
final class NotificationRecord
{
    public const STATUS_PENDING = '送信中';
    public const STATUS_SENT = '送信済み';
    public const STATUS_FAILED = '送信失敗';

    public function __construct(
        public readonly string $ruleId,
        public readonly string $asin,
        public readonly string $title,
        public readonly float $discountRate,
        public readonly int $price,
        public readonly DateTimeImmutable $notifiedAt,
        public readonly int $count,
        public readonly string $status,
        public readonly string $verdict,
    ) {
    }

    public function key(): string
    {
        return self::makeKey($this->ruleId, $this->asin);
    }

    public static function makeKey(string $ruleId, string $asin): string
    {
        return $ruleId . '|' . $asin;
    }

    public function withStatus(string $status): self
    {
        return new self($this->ruleId, $this->asin, $this->title, $this->discountRate, $this->price, $this->notifiedAt, $this->count, $status, $this->verdict);
    }
}
