<?php

declare(strict_types=1);

namespace App\Http\Requests\Billing;

use App\Enums\PlanCode;
use App\Http\Requests\Concerns\ProhibitsProtectedKeys;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 契約中プランの変更。Policy 検証 (manageBilling) は Controller 側 (Gate::authorize)。
 *
 * - `plan_code`: 変更先。「ユーザーがどのプランへ変えるか」の選択値であり状態キーではない
 *   (`organizations.plan_code` への反映は webhook 同期のみ)。
 * - `current_plan_code`: **stale UI 検知専用**の期待値で、認可・対象決定には使わない。
 *   送信元は画面の `planChangeExpectedPlanCode` (= サーバの `organizations.plan_code` そのもの。
 *   表示用の `currentPlanCode` ではない)。変更元には personal 等も入りうるため PlanCode 全集合で
 *   domain 制約をかける。`organizations.plan_code` は null になりうるため
 *   `present` + `nullable` (キー欠落は 422、値 null は許容)。
 * - `plan_change_token`: 画面 render ごとの ULID。Stripe idempotency key
 *   `change-plan:{token}:{plan_code}` の素で、同一 render からの二重送信を収束させる。
 */
class ChangePlanRequest extends FormRequest
{
    use ProhibitsProtectedKeys;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return array_merge([
            'plan_code' => ['required', 'string', 'exists:plans,code'],
            // 送信元は画面の planChangeExpectedPlanCode (= organizations.plan_code そのもの)。
            // 当該列は null になりうるため **キーの送信は必須 (present) / 値は null 可**
            // = 送信漏れは 422 で検出しつつ、正当な null で恒常 422 を作らない。
            'current_plan_code' => ['present', 'nullable', 'string', Rule::enum(PlanCode::class)],
            // Str::ulid() は大文字 Crockford base32 を含むため 'ulid' ルールを使う
            // (subscription_attempt_token と同じ作法)。
            'plan_change_token' => ['required', 'ulid'],
        ], $this->protectedKeyMissingRules());
    }
}
