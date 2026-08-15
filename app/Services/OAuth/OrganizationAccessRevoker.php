<?php

declare(strict_types=1);

namespace App\Services\OAuth;

use App\DataTransferObjects\Security\OrgAccessRevocationResult;
use App\Enums\Security\OrgAccessRevocationReason;
use App\Enums\SecurityEventType;
use App\Models\Organization;
use App\Models\User;
use App\Services\Security\SecurityEventRecorder;
use Illuminate\Support\Facades\DB;
use Webmozart\Assert\Assert;

/**
 * 組織アクセスの失効の**唯一の窓口**。
 *
 * ある組織における、ある利用者の「人に委ねられた資格情報」をまとめて失効させる。
 * 失効の境界は **「役割を変える操作が成功したこと」** であり、役割の集合の差分は取らない。
 * 差分を取ると権限ライブラリの役割キャッシュ (本番で 1 時間有効) に依存した判定になり、
 * 取りこぼしたときに通してしまう側へ倒れるためである (家系の正典 v2 / 裁定 AG-125)。
 *
 * **必ず呼び出し元のトランザクションの内側で呼ぶ**。役割の変更と失効が同じひとまとまりに
 * 入っていないと、「役割は下がったのにトークンは生きている」中間状態と、
 * 確定直後にプロセスが落ちて失効が無言で消える隙間の両方が生まれる。
 * 外から呼ばれた場合は実行時に例外で拒否する (説明文とテストだけに頼らない)。
 *
 * **3 家族を途中で打ち切らない**。1 家族目が 0 件でも残りは必ず失効させる。
 *
 * 触らないもの:
 *  - 組織が持つ API キー (`api_keys`) — 組織の資産であり、人の所属で消さない
 *    (発行した管理者が抜けた瞬間に組織の自動連携が全部止まる事故を作らない)。
 *    **誇張しない**: 退会者が発行したキーで**書き込み**を叩くと、認可
 *    (app/Policies/ProjectPolicy.php) が発行者の現在の組織ロールを評価するので 403 になるが、
 *    **読み取りは通る** (app/Http/Middleware/ResolveApiActor.php は発行者の所属を
 *    再評価しない)。鍵を止めるのは組織管理者の操作 (API キー画面) である。
 *    ★この 2 つはクラス参照ではなくファイル名で書く。`{@see}` で書くと整形器が
 *    import を足し、退会経路の依存閉包 (AccountDeletionPathGateTest) が
 *    説明のためだけに広がってしまうためである。
 *  - プロジェクト単位の役割 — トークンの結び付き先は組織であり、その人はまだメンバーである。
 *
 * 保証しないこと:
 *  - 失効の選択と確定の間に新しい資格情報が発行される隙間は閉じない
 *    (発行の経路は組織行・利用者行のロックを取らない)。最後の拒否線は要求ごとの再評価である。
 */
final class OrganizationAccessRevoker
{
    public function __construct(
        private readonly SecurityEventRecorder $recorder,
    ) {}

    /**
     * 対象 (組織, 利用者) の資格情報を失効させ、監査を 1 行残す。
     *
     * @param  User|null  $actor  操作した人 (HTTP 外 = バッチ・コンソールは null が正常値)
     */
    public function revoke(
        Organization $organization,
        User $target,
        OrgAccessRevocationReason $reason,
        ?User $actor,
    ): OrgAccessRevocationResult {
        // 呼び出し元のひとまとまりの内側であることの実行時強制。
        // ここを説明文だけに頼ると、外から呼ぶ経路が静かに生まれる。
        Assert::greaterThan(
            DB::transactionLevel(),
            0,
            'OrganizationAccessRevoker::revoke() は役割変更と同一のトランザクション内から呼ぶこと',
        );

        $organizationId = $organization->getKey();
        Assert::integer($organizationId);
        $userId = $target->getKey();
        Assert::integer($userId);

        // 家族 1: セッション行 (表示・actor 解決の判定に使う失効印)
        $sessions = DB::table('oauth_sessions')
            ->where('organization_id', $organizationId)
            ->where('user_id', $userId)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now(), 'updated_at' => now()]);

        // 家族 2: 利用トークンと、それに紐づく更新トークン。
        // ★session_id では絞らない。絞ると「セッション行を持たないトークン」
        //   (古い MCP トークン等) が生き残る。
        // ★母集団を「まだ失効していない利用トークン」に絞らない。更新トークンは
        //   親の利用トークン経由でしか辿れないので、親が既に失効済みで子が未失効という
        //   不整合行があると、絞った瞬間にその子が生き残る (= 再発行の経路が残る)。
        //   絞るのは**件数を数える更新文の側だけ**にする。
        /** @var list<string> $tokenIds */
        $tokenIds = DB::table('oauth_access_tokens')
            ->where('organization_id', $organizationId)
            ->where('user_id', $userId)
            ->pluck('id')
            ->all();

        $accessTokens = 0;
        $refreshTokens = 0;
        if ($tokenIds !== []) {
            $accessTokens = DB::table('oauth_access_tokens')
                ->whereIn('id', $tokenIds)
                // 主キーで絞った後でも所有権の条件を残す (監査上の意図の明示 + 取り違えの保険)
                ->where('organization_id', $organizationId)
                ->where('user_id', $userId)
                ->where('revoked', false)
                ->update(['revoked' => true]);
            $refreshTokens = DB::table('oauth_refresh_tokens')
                ->whereIn('access_token_id', $tokenIds)
                ->where('revoked', false)
                ->update(['revoked' => true]);
        }

        // 家族 3: 未交換の認可コード。
        // これを落とすと、失効の直前に発行された認可コードを失効の後に交換して
        // 新しいトークンを得る経路が残る。
        $authCodes = DB::table('oauth_auth_codes')
            ->where('organization_id', $organizationId)
            ->where('user_id', $userId)
            ->where('revoked', false)
            ->update(['revoked' => true]);

        $result = new OrgAccessRevocationResult(
            sessions: $sessions,
            accessTokens: $accessTokens,
            refreshTokens: $refreshTokens,
            authCodes: $authCodes,
        );

        // 監査は握り潰さない。書けなければ役割の変更ごと巻き戻る。
        // 失効 0 件でも 1 行残す (「対象が無かった」ことも監査上の事実である)。
        $this->recorder->recordOrFail(SecurityEventType::OrganizationAccessRevoked, $target, [
            'organization_id' => $organizationId,
            'actor_user_id' => $actor?->getKey(),
            'reason' => $reason->value,
            'revoked' => $result->toArray(),
        ]);

        return $result;
    }
}
