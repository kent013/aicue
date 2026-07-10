<?php

declare(strict_types=1);

namespace App\Enums\Security;

/**
 * nested route (親子) の IDOR 防御方式。
 *
 * 子リソースを URL で受ける route が「子は必ず URL 上の親 (またはテナント) に属する」不変条件を
 * どの機構で担保しているかを明示分類する。`NestedRouteIdorDefenseTest` の inventory が本 enum を
 * 値に持ち、2 個以上の route パラメータを取る named route を deny-by-default で分類漏れ・drift
 * から守る。
 *
 * テンプレートは `Route::scopeBindings()` を既定 (主防御) とする (親 relation 経由で子を解決し、
 * 不整合は認可より前に 404)。model binding にならない子 (payload 由来・文字列 token 等) や
 * 解決順序の都合で scopeBindings に乗らない route のみ inline guard を使う。
 * アプリ固有の防御方式が必要になったら case を追加し、docs/template-divergence.md に記録する。
 */
enum NestedRouteDefenseMode: string
{
    /** Route::scopeBindings() (親 relation 経由で子を解決、不整合は 404)。テンプレートの主防御。 */
    case ScopeBindings = 'scope_bindings';

    /** route-model binding + inline 親子整合 guard (authorize より前に子∈親/テナントを検査し不整合は 404)。 */
    case UrlIntegrityGuard = 'url_integrity_guard';
}
