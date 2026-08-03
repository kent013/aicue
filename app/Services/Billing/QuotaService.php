<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\QuotaKey;
use App\Exceptions\Billing\QuotaExceededException;
use App\Models\Organization;
use Webmozart\Assert\Assert;

/**
 * 多次元 Quota の唯一の判定窓口 (docs 07 ガイド §4)。
 *
 * - 既定値: config/quota.php の plan_code → limits map
 *   (plan_code null は fallback_plan = personal)
 * - override: organization_quotas.limits が key 単位で既定値を上書きする
 * - チェックは本 Service 経由のみ (コントローラに直書きしない)。超過は
 *   QuotaExceededException (web では back + error flash に変換される)
 * - 規約: limits に無い key は無制限扱い。プラン名での分岐は書かない
 *   (能力は limits の「値」で表現する)
 */
class QuotaService
{
    /**
     * 組織の有効 limits (プラン既定 + override)。
     *
     * @return array<string, int>
     */
    public function limits(Organization $organization): array
    {
        $planCode = $organization->plan_code ?? config()->string('quota.fallback_plan');

        $planLimits = config()->array('quota.plans')[$planCode] ?? [];
        Assert::isArray($planLimits);

        // ?? は isset 文脈のため quota が null でも安全 (override 無し = 空配列)
        $override = $organization->quota()->first()->limits ?? [];

        $limits = [];
        foreach ([...$planLimits, ...$override] as $key => $value) {
            Assert::string($key);
            Assert::integer($value, "quota limits の値は int のみ ({$key})");
            $limits[$key] = $value;
        }

        return $limits;
    }

    /**
     * 上限チェック。currentCount が limits[key] に達していたら QuotaExceededException。
     * limits に key が無ければ無制限として通す。key は QuotaKey enum 経由のみ
     * (文字列直書きを型で禁止。キー追加時は enum にも case を足す)。
     */
    public function check(Organization $organization, QuotaKey $key, int $currentCount): void
    {
        $limit = $this->limits($organization)[$key->value] ?? null;
        if ($limit === null) {
            return;
        }

        if ($currentCount >= $limit) {
            throw QuotaExceededException::forLimit($key, $limit);
        }
    }

    /**
     * 加算量つき上限チェック (容量セマンティクス。概念設計 D3)。
     * check() の「件数が上限に達したら拒否 (current >= limit)」に対し、こちらは
     * 「加算後合計が上限を超過するなら拒否 (current + addition > limit)」。
     * limits に key が無ければ無制限。判定窓口の一元化規約 (docs 07 §4) を維持する。
     */
    public function checkAddition(Organization $organization, QuotaKey $key, int $current, int $addition): void
    {
        // 事前条件は無制限プラン (limit 無し) でも保証する (契約の明確化)
        Assert::greaterThanEq($current, 0);
        Assert::greaterThanEq($addition, 0);

        $limit = $this->limits($organization)[$key->value] ?? null;
        if ($limit === null) {
            return;
        }

        // int overflow ガード: 加算が PHP_INT_MAX を跨ぐ場合は超過として扱う (意図を算術で明示)
        if ($current > PHP_INT_MAX - $addition || $current + $addition > $limit) {
            throw QuotaExceededException::forLimit($key, $limit);
        }
    }
}
