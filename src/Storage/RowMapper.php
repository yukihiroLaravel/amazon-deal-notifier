<?php

declare(strict_types=1);

namespace DealRadar\Storage;

use DateTimeImmutable;
use DealRadar\Domain\NotificationRecord;
use DealRadar\Domain\ResultRow;
use DealRadar\Domain\RunLog;

/**
 * ドメインオブジェクト ⇔ シートの1行（値の配列）の変換。
 * 列の並びは SheetSchema の見出しと一致させる。ローカル JSON 保存でも同じ形式を使う。
 */
final class RowMapper
{
    public const DATETIME = 'Y/m/d H:i:s';

    /** @return list<mixed> */
    public static function resultToRow(ResultRow $r): array
    {
        return [
            $r->fetchedAt->format(self::DATETIME), $r->ruleId, $r->ruleName, $r->rank, $r->verdict,
            $r->asin, $r->title, $r->brand, $r->price, $r->basePrice, $r->savingsAmount, $r->discountRate, $r->url,
        ];
    }

    /** @param list<mixed> $row */
    public static function rowToResult(array $row): ResultRow
    {
        $row = array_pad($row, 13, '');

        return new ResultRow(
            fetchedAt: self::date($row[0]),
            ruleId: (string) $row[1],
            ruleName: (string) $row[2],
            rank: (int) $row[3],
            verdict: (string) $row[4],
            asin: (string) $row[5],
            title: (string) $row[6],
            brand: (string) $row[7],
            price: (int) $row[8],
            basePrice: (int) $row[9],
            savingsAmount: (int) $row[10],
            discountRate: (float) $row[11],
            url: (string) $row[12],
        );
    }

    /** @return list<mixed> */
    public static function historyToRow(NotificationRecord $h): array
    {
        return [
            $h->ruleId, $h->asin, $h->title, $h->discountRate, $h->price,
            $h->notifiedAt->format(self::DATETIME), $h->count, $h->status, $h->verdict,
        ];
    }

    /** @param list<mixed> $row */
    public static function rowToHistory(array $row): NotificationRecord
    {
        $row = array_pad($row, 9, '');

        return new NotificationRecord(
            ruleId: (string) $row[0],
            asin: (string) $row[1],
            title: (string) $row[2],
            discountRate: (float) $row[3],
            price: (int) $row[4],
            notifiedAt: self::date($row[5]),
            count: (int) $row[6],
            status: (string) $row[7],
            verdict: (string) $row[8],
        );
    }

    /** @return list<mixed> */
    public static function runLogToRow(RunLog $l): array
    {
        return [
            $l->runId, $l->startedAt->format(self::DATETIME), $l->finishedAt?->format(self::DATETIME) ?? '',
            $l->durationSeconds() ?? '', $l->status, $l->source, $l->fetched, $l->matched, $l->notified,
            $l->skippedDuplicates, $l->message,
        ];
    }

    /** @param list<mixed> $row */
    public static function rowToRunLog(array $row): RunLog
    {
        $row = array_pad($row, 11, '');

        return new RunLog(
            runId: (string) $row[0],
            startedAt: self::date($row[1]),
            finishedAt: $row[2] === '' ? null : self::date($row[2]),
            status: (string) $row[4],
            source: (string) $row[5],
            fetched: (int) $row[6],
            matched: (int) $row[7],
            notified: (int) $row[8],
            skippedDuplicates: (int) $row[9],
            message: (string) $row[10],
        );
    }

    /** シートから読んだ日時（書式付き文字列 or シリアル値）を DateTime に戻す */
    public static function date(mixed $value): DateTimeImmutable
    {
        if (is_int($value) || is_float($value)) {
            // スプレッドシートのシリアル値（1899-12-30 起点の日数）
            return (new DateTimeImmutable('1899-12-30 00:00:00'))->modify('+' . (int) round($value * 86400) . ' seconds');
        }

        $parsed = DateTimeImmutable::createFromFormat(self::DATETIME, (string) $value)
            ?: DateTimeImmutable::createFromFormat('Y/m/d H:i', (string) $value);

        return $parsed ?: new DateTimeImmutable((string) $value ?: 'now');
    }
}
