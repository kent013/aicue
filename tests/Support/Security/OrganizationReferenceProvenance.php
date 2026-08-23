<?php

declare(strict_types=1);

namespace Tests\Support\Security;

/**
 * 機械が使う経路で「組織をどう確定したか」の**由来** (家系裁定 AG-047 / 不変条件 I14)。
 *
 * ★正典は「機械が使う経路は**不変の内部識別子**で組織を指す」と定める。
 *   識別名 (改名で変わる) や表示名で組織を引く形は、改名の瞬間に機械の参照が壊れる。
 * ★`NotOrganizationScoped` は**この enum に入れない** — 「解決点の由来」と
 *   「そもそも解決点を持たない入口」は別の概念であり、混ぜると
 *   「由来を書いていない」と「持たないことを検査した」の区別が消える
 *   (入口の分類は `MachinePlaneEntryClassification` が持つ)。
 */
enum OrganizationReferenceProvenance: string
{
    /**
     * route binding の内部主キーだけ (Filament の `{record}` を含む)。
     *
     * request body / query string の tenant キー受け取りは**この分類では許さない**
     * (セキュリティ不変条件 1: tenant キー不信)。
     */
    case PrimaryKeyBinding = 'primary_key_binding';

    /**
     * 認証済み credential (API キー / OAuth token / MCP consent) の帰属から確定する
     * request attribute。利用者入力を経由しない。
     */
    case ActorDerived = 'actor_derived';

    /**
     * **信頼済みの親**から tenant-scoped relation だけを辿って確定する。
     *
     * 親は `PrimaryKeyBinding` / `ActorDerived` / **別の `RelationScoped`** のいずれでもよいが、
     * 親鎖が最終的に `PrimaryKeyBinding` か `ActorDerived` へ到達することを gate が検証する。
     * 自己参照・循環・親不在は fail-closed で落ちる (再帰的 provenance)。
     */
    case RelationScoped = 'relation_scoped';
}
