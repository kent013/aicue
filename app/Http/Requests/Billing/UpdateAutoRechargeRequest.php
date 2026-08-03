<?php

declare(strict_types=1);

namespace App\Http\Requests\Billing;

use App\Http\Requests\Concerns\ProhibitsProtectedKeys;
use App\Models\Billing\TicketVolumePrice;
use Illuminate\Foundation\Http\FormRequest;

/**
 * P8a: オートリチャージ設定更新。
 *
 * - max_count > threshold_count (gt) + 上限は config billing.auto_recharge.max_count
 *   (= TicketVolumePrice::PURCHASE_MAX_COUNT と単一真実源。超過は tier 解決で例外死するため
 *   入口で拘束する)
 * - enabled=true のとき consent_version 必須。version の現行一致・再同意要否の判定は
 *   Service 側 (サーバ状態依存のため)
 * - 認可は Controller の Gate::authorize('manageBilling') が担う
 */
final class UpdateAutoRechargeRequest extends FormRequest
{
    use ProhibitsProtectedKeys;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_replace([
            'enabled' => ['required', 'boolean'],
            'threshold_count' => ['required', 'integer', 'min:0'],
            'max_count' => [
                'required',
                'integer',
                'gt:threshold_count',
                'max:'.$this->maxCountLimit(),
            ],
            'consent_version' => ['required_if_accepted:enabled', 'string', 'max:16'],
        ], $this->protectedKeyMissingRules());
    }

    private function maxCountLimit(): int
    {
        return min(
            config()->integer('billing.auto_recharge.max_count'),
            TicketVolumePrice::PURCHASE_MAX_COUNT,
        );
    }

    /**
     * 'enabled' は 2 段階認証必須化フォームと同名キーのため、本フォーム専用ラベルで上書きする。
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'enabled' => 'オートリチャージ',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'max_count.gt' => 'リチャージ後の残高は開始残高より大きい値を指定してください。',
            'consent_version.required_if_accepted' => '自動購入への同意が必要です。',
        ];
    }
}
