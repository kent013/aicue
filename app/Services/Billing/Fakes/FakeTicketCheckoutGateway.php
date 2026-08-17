<?php

declare(strict_types=1);

namespace App\Services\Billing\Fakes;

use App\DataTransferObjects\Billing\CreatedCheckoutSession;
use App\Models\Organization;
use App\Services\Billing\TicketCheckoutGateway;
use Carbon\CarbonImmutable;

/**
 * TicketCheckoutGateway の runtime fake (fake_externals 環境専用。Stripe に到達しない)。
 *
 * 契約 = 「外部ステップを skip した中立帰還」:
 * - session id は idempotency key から決定的に導出 (Stripe の idempotency replay と同じ収束特性)
 * - 遷移先はアプリ内帰還画面 ($cancelUrl) + 観測用 marker query `fake_external=stripe`。
 *   アプリはこの query を一切解釈しない (purchased 偽装なし / cancel の意味付けもなし)
 * - 決済・チケット付与・状態変更は一切行わない (課金状態の正本は BughuntStripeSyncSeeder)
 *
 * テスト専用 spy (Tests\Support\FakeTicketCheckoutGateway) とは責務が異なる:
 * spy は呼び出し記録と失敗注入を持つが、本クラスは無状態 stub (serve プロセスで動く前提)。
 */
final class FakeTicketCheckoutGateway implements TicketCheckoutGateway
{
    /**
     * @param  array<string, string>  $metadata  照合専用 (fake は参照しない)
     */
    public function createTicketCheckout(
        Organization $organization,
        string $stripePriceId,
        int $quantity,
        string $successUrl,
        string $cancelUrl,
        string $idempotencyKey,
        array $metadata,
    ): CreatedCheckoutSession {
        // idempotency key から決定的に導出 (同一 attempt の再送は同一 session に収束)。
        // key の文字種・長さに依存しないよう sha256 の先頭 32 桁で固定長トークン化する
        // (stripe_session_id 列・URL への混入安全性)
        $token = substr(hash('sha256', $idempotencyKey), 0, 32);

        return new CreatedCheckoutSession(
            sessionId: "cs_bughuntfake_{$token}",
            url: FakeExternalUrl::neutralReturn($cancelUrl),
            expiresAt: CarbonImmutable::now()->addDay(), // Stripe hosted checkout の既定 24h に合わせる
        );
    }

    public function expireCheckoutSession(string $sessionId): string
    {
        return 'expired';
    }
}
