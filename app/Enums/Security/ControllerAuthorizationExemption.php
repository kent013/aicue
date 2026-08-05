<?php

declare(strict_types=1);

namespace App\Enums\Security;

/**
 * 変更系 (POST/PUT/PATCH/DELETE) route が「認可判断 (Gate) を持たないことが正しい」
 * と裁定された理由の分類。
 *
 * `tests/Architecture/ControllerAuthorizationGateTest.php` が deny-by-default で
 * 「認可あり」か「本 enum + 具体的根拠付きの exemption」かを機械強制する
 * (テストクラスへの {@see} 参照は app → tests の import を生むため書かない)。
 *
 * ★分類は「汎用に見えるものほど適用条件を狭く」定義する。
 *   条件に当てはまらない route を無理に既存 case へ押し込むと gate が形骸化する。
 *   当てはまる case が無ければ、それは「認可を足すべき route」である。
 */
enum ControllerAuthorizationExemption: string
{
    /**
     * membership 判定そのものが認可である。
     *
     * 適用条件 (全て満たすこと):
     * - 対象リソースが `MembershipScopedOrganizationBinder` 等で membership スコープ解決される
     * - 「所属していれば誰でもよい」がロール非依存の**仕様**である
     *   (owner/admin/member を区別する必要が無い)
     * - Policy を足すと membership の**二重判定**にしかならない
     */
    case MembershipIsTheAuthorization = 'membership_is_the_authorization';

    /**
     * 認可の対象となる既存リソースが存在しない (新規作成そのもの)。
     *
     * 適用条件: route に対象リソースを指す parameter が無く、
     * 作成対象の親テナントも存在しない (= 誰の何に対する権限か、が定義できない)。
     */
    case NoAuthorizableSubject = 'no_authorizable_subject';

    /**
     * 対象が常に「認証中の自分自身」に閉じる。
     *
     * 適用条件 (全て満たすこと):
     * - route に**他者を指せる parameter が 1 つも無い**、または
     *   parameter が `$user->relation()` 経由でのみ解決され cross-user が構造的に 404
     * - 他者のリソースへ到達する経路がコード上存在しない
     */
    case SelfScopedResource = 'self_scoped_resource';

    /**
     * 認可主体が「有効なトークンの保持者」であり、トークン検証が認可を兼ねる。
     *
     * 適用条件: 対象組織の**非メンバー**が正当に実行する操作であり、
     * 組織 Policy を通すと構造的に必ず拒否になる (招待受諾など)。
     */
    case TokenBearerIsTheSubject = 'token_bearer_is_the_subject';

    /**
     * API トークンの scope 判定が明示的な 403 を担っている。
     *
     * 適用条件: controller 内に `abort_unless($actor->hasScope(...), 403)` 等の
     * **明示的な 403 判定**があり、かつ対象が actor 自身のリソースに閉じる。
     */
    case ScopeIsTheAuthorization = 'scope_is_the_authorization';

    /** 未認証の公開エンドポイント (認可すべき主体が存在しない)。 */
    case PublicUnauthenticated = 'public_unauthenticated';

    /**
     * 署名検証済みの machine-to-machine webhook (人間の actor が存在しない)。
     *
     * 適用条件: 署名検証 middleware + 送信元 allowlist (fail-closed) が防御線であること。
     */
    case SignatureVerified = 'signature_verified';

    /**
     * local / テスト実行時のみ **route 登録自体が起きない**デバッグ用 route。
     *
     * 適用条件: `routes/*.php` 側で `app()->isLocal() || app()->runningUnitTests()`
     * 等により登録が囲われ、かつ `LocalOnly` 相当の middleware が二重防御であること。
     */
    case LocalOnlyDebugRoute = 'local_only_debug_route';
}
