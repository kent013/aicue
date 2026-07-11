<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Notification;

/**
 * チケット残高低下通知の表示用 payload。
 *
 * balance は Reserved 拘束を含む「実効残高」(ユーザーが今トリガーできるかに一致する値。
 * クロス判定のセマンティクスは TicketLedgerService::reserve のコメント参照)。
 */
final readonly class TicketBalanceLowPayload
{
    public function __construct(
        public string $organizationName,
        public int $balance,
        public int $threshold,
    ) {}

    /**
     * @return array{organization_name: string, balance: int, threshold: int}
     */
    public function toArray(): array
    {
        return [
            'organization_name' => $this->organizationName,
            'balance' => $this->balance,
            'threshold' => $this->threshold,
        ];
    }

    /**
     * 読み出し側の検証復元。型不整合は null 返し (= フロントは fallback 表示)。
     *
     * @param  array<array-key, mixed>  $data
     */
    public static function tryFromArray(array $data): ?self
    {
        $organizationName = $data['organization_name'] ?? null;
        $balance = $data['balance'] ?? null;
        $threshold = $data['threshold'] ?? null;

        if (! is_string($organizationName) || ! is_int($balance) || ! is_int($threshold)) {
            return null;
        }

        return new self($organizationName, $balance, $threshold);
    }
}
