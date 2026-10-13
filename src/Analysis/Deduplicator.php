<?php

declare(strict_types=1);

namespace DealRadar\Analysis;

use DateTimeImmutable;
use DealRadar\Domain\Deal;
use DealRadar\Domain\Decision;
use DealRadar\Domain\NotificationRecord;
use DealRadar\Domain\Rule;
use DealRadar\Domain\Settings;
use DealRadar\Domain\Verdict;

/**
 * 通知履歴と照合して、通知するかどうかを判定する。
 *
 * | 状況                                              | 判定       |
 * |---------------------------------------------------|------------|
 * | 履歴なし                                          | 新規通知   |
 * | 前回が送信失敗／送信中のまま                      | 再送       |
 * | 再通知日数内 かつ 割引率が前回+しきい値以上       | 値下げ更新 |
 * | 再通知日数内                                      | 通知済み   |
 * | 再通知日数を経過                                  | 再通知     |
 */
final class Deduplicator
{
    /** @var array<string, NotificationRecord> */
    private array $history = [];

    /** @param iterable<NotificationRecord> $records */
    public function __construct(
        private readonly Settings $settings,
        private readonly DateTimeImmutable $now,
        iterable $records,
    ) {
        foreach ($records as $record) {
            $this->history[$record->key()] = $record;
        }
    }

    public function decide(Rule $rule, Deal $deal): Decision
    {
        $previous = $this->history[NotificationRecord::makeKey($rule->id, $deal->product->asin)] ?? null;

        return new Decision($rule, $deal, $this->verdictFor($deal, $previous), $previous);
    }

    /**
     * 割引率の高い順に判定し、通知対象がルールの最大件数を超えた分は「件数上限」にする
     *
     * @param list<Deal> $deals
     * @return list<Decision>
     */
    public function decideAll(Rule $rule, array $deals): array
    {
        $decisions = [];
        $toNotify = 0;
        foreach ($deals as $deal) {
            $decision = $this->decide($rule, $deal);
            if ($decision->verdict->shouldNotify()) {
                if ($toNotify >= $rule->maxItems) {
                    $decision = $decision->withVerdict(Verdict::OverLimit);
                } else {
                    $toNotify++;
                }
            }
            $decisions[] = $decision;
        }

        return $decisions;
    }

    private function verdictFor(Deal $deal, ?NotificationRecord $previous): Verdict
    {
        if ($previous === null) {
            return Verdict::New;
        }
        if ($previous->status !== NotificationRecord::STATUS_SENT) {
            return Verdict::Retry;
        }

        $elapsedSeconds = $this->now->getTimestamp() - $previous->notifiedAt->getTimestamp();
        if ($elapsedSeconds >= $this->settings->cooldownDays * 86400) {
            return Verdict::Renotify;
        }
        // しきい値0は「値下げ再通知なし」として扱う
        $delta = $this->settings->renotifyDeltaPt;
        if ($delta > 0 && $deal->discountRate >= $previous->discountRate + $delta) {
            return Verdict::PriceDrop;
        }

        return Verdict::Duplicate;
    }
}
