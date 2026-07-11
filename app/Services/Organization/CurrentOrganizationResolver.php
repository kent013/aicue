<?php

declare(strict_types=1);

namespace App\Services\Organization;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Webmozart\Assert\Assert;

/**
 * current organization の「所属再確認つき」解決 + 自己修復 (概念設計 表示組織の解決規則)。
 *
 * removeMember は current org からの除名時に current_organization_id を null 化するが
 * 「選び直す」実装は本 Service が初出。v1 の呼び出し元は DashboardController のみ
 * (他画面への展開は後続。ResolvesCurrentOrganization trait は従来どおり null=404)。
 *
 * 競合契約 (概念レビュー Round 2-4 で確定):
 * - 表示の安全性は「読み出し時の所属再確認」で担保する。current が指す org は常に
 *   pivot relation で所属を再確認してから返す = 非所属 org (dangling) を描画に出さない
 * - 書き込みは best-effort の冪等修復。単一の条件付き UPDATE
 *   (current IS NULL または観測した dangling 値のまま、かつ所属 pivot が存続) のみ
 * - UPDATE 成否によらず fresh 再取得 → 所属再確認 1 回のみ。解決不能なら null (無限再試行しない)
 */
class CurrentOrganizationResolver
{
    /** 表示組織を解決する。null = 所属組織 0 件 (または競合で解決不能) */
    public function resolve(User $user): ?Organization
    {
        // 1. current の所属再確認つき読み出し (dangling は null 扱いに倒す)
        $current = $this->membershipVerified($user, $user->current_organization_id);
        if ($current !== null) {
            return $current;
        }

        // 2. 自己修復: 決定的候補 (organizations.id 昇順の先頭)
        $observed = $user->current_organization_id; // null または dangling 値
        $candidateId = $user->organizations()->orderBy('organizations.id')->value('organizations.id');
        if ($candidateId === null) {
            return null; // 所属 0 件 → setup 表示
        }
        Assert::integerish($candidateId);

        $this->heal($user, $observed, (int) $candidateId);

        // 3. 成否によらず relation キャッシュ破棄 + fresh 再取得 → 所属再確認 (1 回のみ)
        $user->refresh();

        return $this->membershipVerified($user, $user->current_organization_id);
    }

    /**
     * 原子的条件付き UPDATE による自己修復 (内部 API。テストが競合分岐を直接固定できる seam)。
     * 観測値のまま + 所属存続のときのみ設定:
     * - 除名 tx が先に commit していれば whereHas (EXISTS) が偽 → 0 件更新 = 修復しない
     * - 観測後に別 org へ変更済みなら WHERE 不一致 → 上書きしない
     *
     * current_organization_id は保護キーだが、この UPDATE は fillable を経由しない
     * サーバ導出のみの書き込み (payload 値は一切使わない)。
     *
     * @return int 更新行数 (0 = 競合により不発。正常系の一種)
     */
    public function heal(User $user, ?int $observed, int $candidateId): int
    {
        $updated = User::query()
            ->whereKey($user->getKey())
            ->where(function (Builder $query) use ($observed): void {
                $query->whereNull('current_organization_id');
                if ($observed !== null) {
                    $query->orWhere('current_organization_id', $observed);
                }
            })
            ->whereHas('organizations', fn (Builder $query) => $query->whereKey($candidateId))
            ->update(['current_organization_id' => $candidateId]);

        // 監査ログ (GET 内の自己修復を追跡可能にする)。更新 0 件は正常な競合のため
        // debug に落としログ量を抑える (詳細レビュー Round 2 対応)
        Log::log($updated > 0 ? 'info' : 'debug', 'current organization self-heal', [
            'user_id' => $user->getKey(),
            'observed' => $observed,
            'candidate' => $candidateId,
            'updated_rows' => $updated,
        ]);

        return $updated;
    }

    /** 所属再確認つき読み出し (pivot relation 経由 = cross-org を構造的に排除) */
    private function membershipVerified(User $user, ?int $organizationId): ?Organization
    {
        if ($organizationId === null) {
            return null;
        }

        /** @var Organization|null */
        return $user->organizations()->whereKey($organizationId)->first();
    }
}
