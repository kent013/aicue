<?php

declare(strict_types=1);

namespace App\Services\Onboarding;

use App\Enums\PlanCode;
use App\Models\Organization;
use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Request;

/**
 * 料金表 → 登録 → Onboarding Checkout の経路で「料金表で選んだプラン意図」を
 * 一貫保持する責務に集約する。
 *
 * 2 キー設計:
 *   - pending (組織未確定): `onboarding.intended_plan.pending`
 *   - org-scoped:           `onboarding.intended_plan.org.{id}`
 *
 * 書き込み規約 (2 種類):
 *
 * 1. pending 系 (`rememberPendingFromXxx`): **常に書き換える** (新規 register/SSO 開始
 *    ごとに必ず最新値で置換)。 前回 OAuth 中断などで残った stale pending が次の plan
 *    なし新規登録に promote される事故を防ぐため、 不在 / null / 空文字 / 改ざんは
 *    すべて forget する。
 *      - 不在 / null / 空文字 → forget
 *      - 有効 (Personal/Starter/Standard/Business) → put
 *      - 無効 / Enterprise / 改ざん (配列等) → forget
 *
 * 2. org-scoped 系 (`rememberForOrganizationFromXxx`): **不在は no-op** (Onboarding
 *    Checkout のリロード耐性を保つため、 plan なしの単なるリロードで session を破壊
 *    しない)。
 *      - 不在 → no-op
 *      - 有効 → put
 *      - 無効 / Enterprise / 改ざん → forget
 *
 * `?plan=` はユーザー入力である。tenant/actor キーは一切受け取らず、値は必ず
 * `PlanCode` allowlist に照合してから session に載せる (セキュリティ不変条件 #1)。
 */
final class IntendedPlanResolver
{
    public const PENDING_KEY = 'onboarding.intended_plan.pending';

    public function __construct(private readonly Session $session) {}

    public static function orgKey(Organization $organization): string
    {
        return "onboarding.intended_plan.org.{$organization->id}";
    }

    /**
     * 文字列 → `PlanCode` 正規化の single source。
     * Enterprise はセルフサーブ契約フローに乗らない（お問い合わせ営業導線）ため null 化する。
     * Personal は普通の契約フローに露出する選択肢のため受理する
     * (料金表 `?plan=personal` 経由の意図もそのまま引き継ぐ)。
     */
    public static function normalizeRaw(mixed $value): ?PlanCode
    {
        if (! is_string($value)) {
            return null;
        }
        $code = PlanCode::tryFrom(strtolower(trim($value)));
        if ($code === null || $code === PlanCode::Enterprise) {
            return null;
        }

        return $code;
    }

    // --- pending (組織未確定): 常に書き換える ---

    public function rememberPendingFromQuery(Request $request): void
    {
        if (! $request->has('plan')) {
            $this->forgetPending();

            return;
        }
        $this->putPendingFromValue($request->query('plan'));
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function rememberPendingFromForm(array $input): void
    {
        // `array_key_exists` で「key 不在」と「明示 null」を区別する。
        // key 不在ならフォーム送信者が plan を意図していない (= forget で fresh state)。
        if (! array_key_exists('intended_plan', $input)) {
            $this->forgetPending();

            return;
        }
        $this->putPendingFromValue($input['intended_plan']);
    }

    public function peekPending(): ?PlanCode
    {
        $raw = $this->session->get(self::PENDING_KEY);

        return is_string($raw) ? self::normalizeRaw($raw) : null;
    }

    public function forgetPending(): void
    {
        $this->session->forget(self::PENDING_KEY);
    }

    // --- org-scoped: 不在は no-op (リロード耐性) ---

    public function rememberForOrganizationFromQuery(Request $request, Organization $organization): void
    {
        $key = self::orgKey($organization);
        if (! $request->has('plan')) {
            return;
        }
        $normalized = self::normalizeRaw($request->query('plan'));
        if ($normalized === null) {
            $this->session->forget($key);

            return;
        }
        $this->session->put($key, $normalized->value);
    }

    public function peekForOrganization(Organization $organization): ?PlanCode
    {
        $raw = $this->session->get(self::orgKey($organization));

        return is_string($raw) ? self::normalizeRaw($raw) : null;
    }

    public function forgetForOrganization(Organization $organization): void
    {
        $this->session->forget(self::orgKey($organization));
    }

    /**
     * pending → org-scoped に移送（登録完了直後）。
     * pending は常に forget で消費する。pending に値がなければ org key は触らない。
     */
    public function promotePendingToOrganization(Organization $organization): void
    {
        $code = $this->peekPending();
        $this->forgetPending();
        if ($code === null) {
            return;
        }
        $this->session->put(self::orgKey($organization), $code->value);
    }

    // --- 内部 ---

    private function putPendingFromValue(mixed $value): void
    {
        $normalized = self::normalizeRaw($value);
        if ($normalized === null) {
            $this->forgetPending();

            return;
        }
        $this->session->put(self::PENDING_KEY, $normalized->value);
    }
}
