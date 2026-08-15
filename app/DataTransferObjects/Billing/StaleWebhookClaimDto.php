<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Billing;

use App\Enums\Billing\WebhookRecoveryReason;
use App\Enums\Billing\WebhookStaleClaimOutcome;

/**
 * 滞留 webhook 1 件の受理結果 (読み取り専用スナップショット)。
 *
 * **Eloquent の Model をトランザクションの外へ持ち出さない**ための型
 * (在メモリ状態と永続状態を混ぜない)。commit 後の処理 —— 再実行と通知 —— に要る値だけを持つ。
 *
 * 生成は名前付きコンストラクタ経由のみ。`reason` が非 NULL になるのは
 * `MovedToRecoveryPending` のときだけで、その対応は型の側で閉じている。
 *
 * `payload` に中身が入るのは `ClaimedForReplay` のときだけである
 * (回収待ちへ置く場合は再実行しないので保持しない = 1 件分の payload を無用に持ち回らない)。
 */
final readonly class StaleWebhookClaimDto
{
    /**
     * @param  int  $attempts  受理**後**の値 (この世代を握っている印)
     * @param  array<mixed>  $payload  保存済み payload (Model の cast をそのまま渡す)。
     *                                 ClaimedForReplay 以外では空配列
     */
    private function __construct(
        public WebhookStaleClaimOutcome $outcome,
        public string $eventId,
        public string $type,
        public int $attempts,
        public array $payload,
        public ?WebhookRecoveryReason $reason,
    ) {}

    /**
     * @param  array<mixed>  $payload
     */
    public static function claimedForReplay(
        string $eventId,
        string $type,
        int $attempts,
        array $payload,
    ): self {
        return new self(
            WebhookStaleClaimOutcome::ClaimedForReplay,
            $eventId,
            $type,
            $attempts,
            $payload,
            null,
        );
    }

    public static function movedToRecoveryPending(
        string $eventId,
        string $type,
        int $attempts,
        WebhookRecoveryReason $reason,
    ): self {
        return new self(
            WebhookStaleClaimOutcome::MovedToRecoveryPending,
            $eventId,
            $type,
            $attempts,
            [], // 再実行しないので payload は持たない
            $reason,
        );
    }

    /**
     * 通知・ログの構造化 context (payload 本体は載せない = 外部由来の可変データを運用ログへ流さない)。
     *
     * @return array{
     *     event_id: string,
     *     type: string,
     *     attempts: int,
     *     outcome: string,
     *     reason: string|null,
     * }
     */
    public function logContext(): array
    {
        return [
            'event_id' => $this->eventId,
            'type' => $this->type,
            'attempts' => $this->attempts,
            'outcome' => $this->outcome->value,
            'reason' => $this->reason?->value,
        ];
    }
}
