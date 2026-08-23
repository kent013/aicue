<?php

declare(strict_types=1);

namespace App\Services\EnterpriseSso;

use App\DataTransferObjects\EnterpriseSso\VerifiedIdTokenClaims;
use App\Enums\OrganizationRole;
use App\Models\EnterpriseIdentity;
use App\Models\Organization;
use App\Models\OrganizationOidcConnection;
use App\Models\User;
use App\Services\Organization\OrganizationMembershipService;
use Webmozart\Assert\Assert;

/**
 * 初回ログインでの利用者の自動作成 (always-JIT)。
 *
 * ★**メールアドレスで利用者を引かない** (正典 v1 / I1)。
 *   引き当ての鍵は **接続 id と生の subject** だけである
 *   (列の照合順序が `COLLATE "C"` なので**バイト一致**)。
 *   ★**指紋 (HMAC) にしない** — 鍵に依存する値を鍵にすると、APP_KEY をローテートした瞬間に
 *     既存の身元へ到達できなくなり**アカウントが分裂する**。列の照合順序なら鍵に依存しない。
 *   申告メールは {@see EnterpriseIdentity} に暗号化して持つが、**引き当てには使わない**。
 *
 * ## 作る利用者の姿 (A3 と一体)
 *
 *  - `email` = **null** (企業 SSO でしか入れない利用者は使えるメールを持たない。
 *    仮のメール文字列を作らない — 偽のメールは衝突と誤送の温床である)
 *  - `email_verified_at` = **now()** (「IdP が本人確認した。確認すべきメールが無い」の意味。
 *    既存の `verified` middleware の意味論を変えずに通す)
 *  - `password` = **null** (パスワードは持たない。初回設定は既存の settings.password.store が担う)
 *  - `name` = ID トークンの `name` claim があればそれ、無ければ表示用の既定値
 *  - 所属は **接続が属する組織のみ**、役割は **{@see OrganizationRole::Member}** (最小)。
 *    付与のすべてで **組織の team id を明示する** (AGENTS.md セキュリティ不変条件 5)
 *
 * ## 並行初回ログインの競合
 *
 * ★**競合制御は C2 が張る接続の行ロックが唯一の担い手である**。
 *   同一接続の callback は行ロックで直列化されるので、事前検索 → 作成の間に
 *   別の要求が割り込むことがない。
 *  - 利用者・身元・組織所属の作成は **C2 が開いた 1 トランザクション**の中で行う
 *  - `enterprise_identities_connection_subject_unique` は**最後の防波堤として残す**が、
 *    **捕まえない** (握り潰すと「直列化が壊れた」という重大な事実が競合として隠れる)
 *  - 失敗すればトランザクション全体が巻き戻るので**孤児は残らない**
 */
final readonly class EnterpriseUserProvisioner
{
    /** `name` claim を持たない IdP のための表示名。 */
    private const string FALLBACK_NAME = '未設定';

    public function __construct(private OrganizationMembershipService $memberships) {}

    /**
     * ★本メソッドは **C2 が張った接続の行ロックの中**で呼ばれる (線形化点は C2 が持つ)。
     *   ここでトランザクションを開き直さない。
     */
    public function resolve(OrganizationOidcConnection $connection, VerifiedIdTokenClaims $claims): User
    {
        // ★relation 起点で引く。クラス起点で書かない — 組織スコープの出所を型と relation で
        //   固定する (AGENTS.md セキュリティ不変条件 3)。
        //   引き当ての鍵は subject の値そのもの (列の照合が COLLATE "C" なので byte 一致)。
        $existing = $connection->identities()->where('subject', $claims->subject)->first();

        if ($existing !== null) {
            $existing->forceFill(['last_login_at' => now()])->save();

            $user = $existing->user;
            Assert::isInstanceOf($user, User::class);

            return $user;   // アーリーリターン
        }

        // ★一意制約違反を**捕まえない**。理由は 2 つ:
        //   (1) C2 が接続の行を lockForUpdate() しているので、同一接続の callback は既に
        //       直列化されており、正規経路でこの競合は起きない
        //   (2) pgsql は一度 SQL エラーが出るとトランザクション全体が aborted になり、
        //       **同じトランザクションの中では再検索できない** = 「catch して引き当て直す」は
        //       そもそも動かない
        return $this->createUserWithIdentityAndMembership($connection, $claims);
    }

    private function createUserWithIdentityAndMembership(
        OrganizationOidcConnection $connection,
        VerifiedIdTokenClaims $claims,
    ): User {
        $organization = $connection->organization;
        // ★接続は必ず組織に属する (FK が cascade で担保)。null は不変条件の破れなので fail-fast する。
        Assert::isInstanceOf($organization, Organization::class);

        $user = new User;
        // ★保護キーは forceFill で明示代入する ($fillable 経由で受けない)。
        $user->forceFill([
            'name' => $claims->name ?? self::FALLBACK_NAME,
            'email' => null,
            'email_verified_at' => now(),
            'password' => null,
        ])->save();

        $identity = new EnterpriseIdentity;
        $identity->forceFill([
            'organization_oidc_connection_id' => $connection->id,
            'user_id' => $user->id,
            'subject' => $claims->subject,
            'claimed_email_encrypted' => $claims->claimedEmail,
            'last_login_at' => now(),
        ])->save();

        // 所属は接続が属する組織だけ、役割は最小の Member。
        // ★ロール書き込みは**単一窓口**の OrganizationMembershipService を通す
        //   (ロール書き込みをロック済みサービス経由に限る直列化の前提を崩さない。
        //    team id の明示 = AGENTS.md セキュリティ不変条件 5 もそちらが担う)。
        $this->memberships->attachJustInTimeMember($organization, $user, OrganizationRole::Member);

        return $user;
    }
}
