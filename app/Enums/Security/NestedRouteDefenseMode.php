<?php

declare(strict_types=1);

namespace App\Enums\Security;

/**
 * route parameter ごとの IDOR / 存在オラクル 防御方式。
 *
 * URL で受ける各 parameter が「その id は必ず URL 上の親 (またはテナント) に属する」不変条件を
 * どの機構で担保しているかを明示分類する。`NestedRouteIdorDefenseTest` の inventory が本 enum を
 * 値に持ち、**1 個以上**の route parameter を取る named route を deny-by-default で分類漏れ・
 * drift から守る。さらに `TenantBoundaryOrderingTest` が、モードごとに要求される
 * **解決後 middleware の順序不変条件**を機械検証する。
 *
 * テンプレートは `Route::scopeBindings()` を既定 (主防御) とする (親 relation 経由で子を解決し、
 * 不整合は認可より前に 404)。model binding にならない子 (payload 由来・文字列 token 等) や
 * 解決順序の都合で scopeBindings に乗らない route のみ他方式を使う。
 * アプリ固有の防御方式が必要になったら case を追加し、docs/template-divergence.md に記録する。
 *
 * **例外機構は設けない**。テナント防御が要る param を「対象外」と宣言して逃がすと
 * 存在オラクルがそのまま再発するため、非テナントモードの宣言には必ず理由の登録を要求する
 * (`NestedRouteIdorDefenseTest` の reason 突合)。
 */
enum NestedRouteDefenseMode: string
{
    // --- テナント防御モード (id が親テナントに属することを担保する) ---

    /** Route::scopeBindings() (親 relation 経由で子を解決、不整合は 404)。テンプレートの主防御。 */
    case ScopeBindings = 'scope_bindings';

    /** Route::bind() の explicit binder が actor スコープで解決する (不整合は binding 段で 404)。 */
    case ScopedBinder = 'scoped_binder';

    /** テナント guard middleware (project.in-current-org / api.project-in-org) が担う。 */
    case TenantGuardMiddleware = 'tenant_guard_middleware';

    /** implicit binding を使わず controller が owner-scoped relation から手動解決する。 */
    case ManualOwnerScopedResolution = 'manual_owner_scoped_resolution';

    /** route-model binding + inline 親子整合 guard (authorize より前に検査し不整合は 404)。 */
    case UrlIntegrityGuard = 'url_integrity_guard';

    // --- 非テナントモード (テナント防御の対象ではないことを明示宣言する) ---

    /**
     * テナント親子関係の対象にならない param。
     * 固定集合 (provider / intent)、署名付き URL の構成要素 (id / hash / token)、
     * 非モデル文字列 (path)、local 専用 debug 経路の対象指定などが該当する。
     */
    case NonResourceParameter = 'non_resource_parameter';

    /** テナントに属さない公開リソース。 */
    case PublicGlobalResource = 'public_global_resource';

    /** テナント防御モードか (順序不変条件の検査対象か)。 */
    public function isTenantDefense(): bool
    {
        return match ($this) {
            self::NonResourceParameter, self::PublicGlobalResource => false,
            default => true,
        };
    }
}
