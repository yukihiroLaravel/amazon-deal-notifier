<?php

declare(strict_types=1);

namespace DealRadar\Domain;

/** 抽出ルール（スプレッドシート「抽出ルール」シートの1行） */
final class Rule
{
    public const MAX_ITEMS_LIMIT = 20;

    public function __construct(
        public readonly string $id,
        public readonly bool $enabled,
        public readonly string $name,
        public readonly string $searchIndex,
        public readonly string $browseNodeId,
        public readonly string $keywords,
        public readonly float $minDiscount,
        public readonly ?int $minPrice,
        public readonly ?int $maxPrice,
        public readonly int $maxItems,
        public readonly string $memo = '',
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromArray(array $row): self
    {
        $intOrNull = static fn (mixed $v): ?int => ($v === null || $v === '') ? null : (int) $v;

        return new self(
            id: trim((string) ($row['id'] ?? '')),
            enabled: filter_var($row['enabled'] ?? false, FILTER_VALIDATE_BOOL),
            name: trim((string) ($row['name'] ?? '')),
            searchIndex: trim((string) ($row['searchIndex'] ?? 'All')),
            browseNodeId: trim((string) ($row['browseNodeId'] ?? '')),
            keywords: trim((string) ($row['keywords'] ?? '')),
            minDiscount: (float) ($row['minDiscount'] ?? 0),
            minPrice: $intOrNull($row['minPrice'] ?? null),
            maxPrice: $intOrNull($row['maxPrice'] ?? null),
            maxItems: max(1, min(self::MAX_ITEMS_LIMIT, (int) ($row['maxItems'] ?? 10))),
            memo: (string) ($row['memo'] ?? ''),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'enabled' => $this->enabled,
            'id' => $this->id,
            'name' => $this->name,
            'searchIndex' => $this->searchIndex,
            'browseNodeId' => $this->browseNodeId,
            'keywords' => $this->keywords,
            'minDiscount' => $this->minDiscount,
            'minPrice' => $this->minPrice,
            'maxPrice' => $this->maxPrice,
            'maxItems' => $this->maxItems,
            'memo' => $this->memo,
        ];
    }

    /** @return list<string> 設定値の不備（空なら有効） */
    public function validate(): array
    {
        $errors = [];
        if ($this->id === '') {
            $errors[] = 'ルールIDが空です';
        }
        if ($this->keywords === '' && $this->browseNodeId === '') {
            // PA-API の SearchItems は Keywords か BrowseNodeId のどちらかが必須
            $errors[] = 'キーワードかカテゴリーID（BrowseNodeId）のどちらかを指定してください';
        }
        if ($this->minDiscount < 0 || $this->minDiscount >= 100) {
            $errors[] = "最低割引率は0〜99の範囲で指定してください（{$this->minDiscount}）";
        }
        if ($this->minPrice !== null && $this->maxPrice !== null && $this->minPrice > $this->maxPrice) {
            $errors[] = '最低価格が最高価格を上回っています';
        }

        return $errors;
    }

    public function conditionLabel(): string
    {
        $parts = ["割引率{$this->formatNumber($this->minDiscount)}%以上"];
        if ($this->minPrice !== null || $this->maxPrice !== null) {
            $min = $this->minPrice !== null ? '¥' . number_format($this->minPrice) : '';
            $max = $this->maxPrice !== null ? '¥' . number_format($this->maxPrice) : '';
            $parts[] = "価格 {$min}〜{$max}";
        }
        if ($this->keywords !== '') {
            $parts[] = "キーワード「{$this->keywords}」";
        }

        return implode(' / ', $parts);
    }

    private function formatNumber(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.');
    }
}
