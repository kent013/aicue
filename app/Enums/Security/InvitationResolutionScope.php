<?php

declare(strict_types=1);

namespace App\Enums\Security;

/**
 * OrganizationInvitation を解決する経路の分類 (存在秘匿の視点別)。
 *
 * 招待は「誰の視点から引くか」で開示していい情報が変わる。視点を混ぜると
 * 受信者向けの経路が管理者向けの絞り込みを使ってしまい、他人宛の招待に到達できる
 * = 存在オラクルになる。**例外機構は設けない** (分類外は fail)。
 */
enum InvitationResolutionScope: string
{
    /**
     * 受信者視点。認証ユーザー宛の有効 pending 集合
     * (OrganizationInvitation::scopeActivePendingForEmail) からのみ解決する。
     * 不成立はすべて 0 件 = 呼び出し側は一律 404 に畳める。
     */
    case RecipientScopedPendingSet = 'recipient_scoped_pending_set';

    /** 平文 token の sha256 照合で解決する (署名なし token URL 経路)。 */
    case TokenHashLookup = 'token_hash_lookup';

    /** 管理者視点。$organization->invitations() の relation 経由でのみ解決する。 */
    case OrganizationScoped = 'organization_scoped';

    /** モデル自身が持つ解決口 / scope の定義そのもの。 */
    case ModelInternal = 'model_internal';

    /**
     * **既に他の経路で解決済み**の招待を、同一トランザクション内で主キー指定して
     * 行ロック付きで読み直すだけの経路 (`whereKey($invitation->id)->lockForUpdate()`)。
     *
     * 新しい到達経路を増やさない (id の出所が上位 4 分類のいずれかで既に絞り込み済み)
     * ため存在秘匿の視点を持たないが、**クエリ起点であることに変わりはない**ので
     * 目録には現れる。この分類を「未解決の外部入力 id で引く」用途に使ってはならない
     * (その場合は受信者視点 / 管理者視点のどちらかへ寄せる)。
     */
    case LockedRowReload = 'locked_row_reload';
}
