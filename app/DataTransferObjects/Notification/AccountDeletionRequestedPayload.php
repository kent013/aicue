<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Notification;

/**
 * 退会予約 (猶予期間つき削除) のアプリ内通知の表示用 payload。
 *
 * `purgeAfter` は ISO 8601 文字列 (発火時点のスナップショット)。取消で予約が消えても
 * 通知行は履歴として残るため、**この値は「予約した時点の予定日」であって現在の状態ではない**
 * (現在の状態は /settings の props が持つ)。
 */
final readonly class AccountDeletionRequestedPayload
{
    public function __construct(
        public string $purgeAfter,
        public int $graceDays,
    ) {}

    /**
     * @return array{purge_after: string, grace_days: int}
     */
    public function toArray(): array
    {
        return [
            'purge_after' => $this->purgeAfter,
            'grace_days' => $this->graceDays,
        ];
    }

    /**
     * 読み出し側の検証復元。型不整合は null 返し (= フロントは fallback 表示)。
     *
     * @param  array<array-key, mixed>  $data
     */
    public static function tryFromArray(array $data): ?self
    {
        $purgeAfter = $data['purge_after'] ?? null;
        $graceDays = $data['grace_days'] ?? null;
        if (! is_string($purgeAfter) || ! is_int($graceDays)) {
            return null;
        }

        return new self($purgeAfter, $graceDays);
    }
}
