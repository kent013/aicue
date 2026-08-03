<?php

declare(strict_types=1);

namespace App\Exceptions\Billing;

use RuntimeException;
use Throwable;

/**
 * プラン変更が Stripe 側の障害 / 想定外の subscription 構成で失敗した。
 *
 * **`getMessage()` は常に利用者向けの固定文言**にする (Controller が
 * `back()->with('error', $e->getMessage())` に流すため、内部識別子を漏らさない)。
 * 診断情報は `$reason` に持たせ、Service が log にだけ落とす。
 *
 * 変換対象は **想定された外部障害だけ** (実装バグは 500 のまま調査対象にする)。
 * 生成は名前付きコンストラクタ経由に限定し、文言の再発明を防ぐ。
 */
final class PlanChangeFailedException extends RuntimeException
{
    public const USER_MESSAGE = 'プラン変更に失敗しました。時間をおいて再度お試しください。';

    private function __construct(public readonly string $reason, ?Throwable $previous = null)
    {
        parent::__construct(self::USER_MESSAGE, previous: $previous);
    }

    /** Stripe API 障害 (ApiErrorException) の変換。SDK 例外は previous に格納する。 */
    public static function stripeApiError(string $stripeSubscriptionId, Throwable $previous): self
    {
        return new self(
            "stripe_api_error: subscription={$stripeSubscriptionId} / {$previous->getMessage()}",
            $previous,
        );
    }

    /**
     * remote subscription の item 構成が AI-CUE の前提 (base 1 item・quantity=1) と違うとき。
     * 席課金を持たない本アプリでは発生しない想定だが、**無言で潰さず fail-closed** にする。
     */
    public static function unexpectedShape(string $stripeSubscriptionId, int $itemCount, ?int $quantity): self
    {
        return new self(
            "unexpected_shape: subscription={$stripeSubscriptionId} / items={$itemCount} / "
            .'quantity='.($quantity ?? 'null'),
        );
    }
}
