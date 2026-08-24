<?php

declare(strict_types=1);

namespace App\Services\Organization;

use App\DataTransferObjects\Account\AccountDeletionAuditContext;
use App\DataTransferObjects\Account\AccountDeletionStateDto;
use App\DataTransferObjects\Invitations\PendingInvitationForUserDto;
use App\DataTransferObjects\Organizations\AccountDeletionBlockerDto;
use App\Enums\AccountDeletionBlockReason;
use App\Enums\AdminConsoleRole;
use App\Enums\OrganizationRole;
use App\Enums\Security\OrgAccessRevocationReason;
use App\Enums\SecurityEventType;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\User;
use App\Notifications\Account\AccountDeletionRequestedNotification;
use App\Notifications\OrganizationInvitationNotification;
use App\Services\Billing\AccountDeletionBillingGuard;
use App\Services\EnterpriseSso\EnterpriseUserProvisioner;
use App\Services\Notification\NotificationCenterService;
use App\Services\OAuth\OrganizationAccessRevoker;
use App\Services\Project\DefaultProjectResolver;
use App\Services\Security\SecurityEventRecorder;
use App\Support\Account\AccountDeletionGrace;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Webmozart\Assert\Assert;

/**
 * 組織メンバーシップ操作の唯一の窓口 (招待 / 受諾 / ロール変更 / 削除 / オーナー移譲)。
 *
 * - ロール操作は必ず laratrust_team_id を明示する (strict_check=true)
 * - 招待 token は平文を保存せず sha256 (token_hash) のみ。平文はメールにだけ載る
 * - 既存メンバー / 既存招待への再招待はアカウント列挙対策の中立メッセージで拒否する
 */
class OrganizationMembershipService
{
    /** 招待の有効期限 (日) */
    private const EXPIRES_DAYS = 7;

    /**
     * 移譲先が組織メンバーでないときの文言。Controller の org 相対解決と
     * ロック下の再検証が**同一文言**であることが存在オラクル不成立の条件なので、
     * 文字列リテラルを 2 箇所に置かない (aicue:T118)。
     */
    public const MEMBER_REQUIRED_MESSAGE = '移譲先は組織のメンバーである必要があります。';

    public function __construct(
        private readonly SecurityEventRecorder $recorder,
        private readonly DefaultProjectResolver $defaultProjects,
        private readonly NotificationCenterService $notifications,
        private readonly AccountDeletionBillingGuard $billingGuard,
        private readonly OrganizationAccessRevoker $accessRevoker,
    ) {}

    /**
     * メンバー招待。招待レコード生成 + 受諾 URL 付きメール送信。
     * ロールは**組織ロール 2 値 (管理者 / メンバー)**。Owner は招待で付与できない
     * (Owner 昇格は transferOwnership のみという不変条件の型表現)。
     * 編集者 / 撮影者 (Default Project の pivot ロール) は参加後に applyConsoleRole で割り当てる
     * (裁定 AG-079 で役割付き招待を撤去したため)。
     *
     * @throws ValidationException 既存メンバー / 有効な既存招待 (中立メッセージ)
     */
    public function inviteMember(Organization $organization, User $invitedBy, string $email, OrganizationRole $role): OrganizationInvitation
    {
        // Owner は FormRequest の Rule::enum(...)->except() で構造的に弾かれるが、
        // Service を直接呼ぶ経路 (テスト・将来のバッチ) でも不変条件を守る
        Assert::notSame($role, OrganizationRole::Owner, 'Owner は招待で付与できない');

        if ($this->emailBelongsToMember($organization, $email) || $this->hasPendingInvitation($organization, $email)) {
            // 既存メンバーか既存招待かを開示しない中立メッセージ (アカウント列挙対策)
            throw ValidationException::withMessages([
                'email' => ['このメールアドレスには招待を送信できません。'],
            ]);
        }

        $plainToken = OrganizationInvitation::generateToken();

        $invitation = new OrganizationInvitation(['email' => $email]);
        $invitation->organization()->associate($organization);
        $invitation->invitedBy()->associate($invitedBy);
        // role / token_hash / expires_at は明示代入 (mass-assignment させない)
        $invitation->forceFill([
            'role' => $role->value,
            'token_hash' => OrganizationInvitation::hashToken($plainToken),
            'expires_at' => now()->addDays(self::EXPIRES_DAYS),
        ]);
        $invitation->save();

        // 受諾はログイン必須 (auth ミドルウェア) のため署名なし URL でよい。平文 token は保存しない
        Notification::route('mail', $email)->notify(new OrganizationInvitationNotification(
            organizationName: $organization->name,
            acceptUrl: url('/invitations/accept?token='.$plainToken),
        ));

        // 既存ユーザーが宛先ならアプリ内でも気づけるようにする (メールの補完。平文 token は含めない)
        $this->notifications->notifyInvitationReceived($invitation);

        return $invitation;
    }

    /**
     * 招待受諾。ログイン中ユーザーが受諾する。
     * **受諾者の email は招待の宛先 email と一致しなければならない**。register 経路
     * (acceptInvitationIfValid) / アプリ内受諾 (acceptPendingInvitation) と同じ email 境界を
     * token POST 経路にも適用する。email 同一性規則は acceptInvitationIfValid と同一
     * (CipherSweet 復号後平文の厳密比較)。最終権威は joinOrganization のロック下再照合。
     *
     * @throws ValidationException token 不正 / 取り消し済み / 失効 / 受諾済み / 宛先 email 不一致 / 既メンバー
     */
    public function acceptInvitation(string $plainToken, User $user): Organization
    {
        $invitation = OrganizationInvitation::query()
            ->where('token_hash', OrganizationInvitation::hashToken($plainToken))
            ->first();

        // 取り消し済みは「無効」と区別しない (取り消された事実を token 保持者に開示しない)
        if ($invitation === null || $invitation->isRevoked()) {
            throw ValidationException::withMessages(['token' => ['この招待は無効です。']]);
        }
        if ($invitation->isAccepted()) {
            throw ValidationException::withMessages(['token' => ['この招待は既に使用されています。']]);
        }
        if ($invitation->isExpired()) {
            throw ValidationException::withMessages(['token' => ['この招待は有効期限が切れています。']]);
        }

        // 宛先 email の早期照合 (UX 用の明示メッセージ + 高速拒否)。生存判定 (取消/受諾済/失効) の後・
        // 既メンバー判定の前に置き、どの分岐も join より前 = 状態を一切変えずに拒否する。
        // 権威はロック下再照合 (joinOrganization) 側で、規則は OrganizationInvitation::isAddressedTo に集約。
        if (! $invitation->isAddressedTo($user)) {
            throw ValidationException::withMessages([
                'token' => ['この招待は別のメールアドレス宛に送信されています。招待先のメールアドレスでログインし直してください。'],
            ]);
        }

        $organization = $invitation->organization;
        Assert::isInstanceOf($organization, Organization::class);

        if ($organization->users()->whereKey($user->getKey())->exists()) {
            throw ValidationException::withMessages(['token' => ['既にこの組織のメンバーです。']]);
        }

        $role = OrganizationRole::from($invitation->role);

        if (! $this->joinOrganization($invitation, $organization, $user, $role)) {
            // ロック下再検証で受諾不能になった (並行受諾 / 取り消し / 期限到来)。
            // 事前検証と同じ中立メッセージへ畳む (取り消された事実を token 保持者に開示しない)
            throw ValidationException::withMessages(['token' => ['この招待は無効です。']]);
        }

        return $organization;
    }

    /**
     * 登録 (register) 経路の招待受諾。CreateNewUser から呼ぶ。
     *
     * acceptInvitation (ログイン後経路) と異なり、失敗しても例外を投げず null を返す
     * (登録そのものは成功させ、呼び出し側が個人組織へ fallback するため)。register 経路は
     * 招待 email と登録 email の一致を要求する (MatchesInvitationEmail rule と対で二重防御)。
     *
     * ★組織文脈は URL だけで決まる (家系裁定 AG-037) ので、受諾は**どこにも状態を保存しない**。
     *   受諾後にどの組織の URL へ着地するかは呼び出し側が返り値から決める。
     *
     * @return Organization|null 参加した組織 / 招待が受諾不能 (不在・失効・受諾済・取消・
     *                           email 不一致・既メンバー) なら null
     */
    public function acceptInvitationIfValid(string $plainToken, User $user): ?Organization
    {
        // active (未受諾・未失効・期限内) 解決は findActiveByPlainToken に集約 (単一解決口)。
        $invitation = OrganizationInvitation::findActiveByPlainToken($plainToken);
        if ($invitation === null) {
            return null;
        }

        // 招待 email と登録 email が一致しない場合は join しない
        // (email 同一性規則は OrganizationInvitation::isAddressedTo に集約)
        if (! $invitation->isAddressedTo($user)) {
            return null;
        }

        $organization = $invitation->organization;
        Assert::isInstanceOf($organization, Organization::class);

        // 既メンバー (race 等) は個人組織へ fallback
        if ($organization->users()->whereKey($user->getKey())->exists()) {
            return null;
        }

        if (! $this->joinOrganization($invitation, $organization, $user, OrganizationRole::from($invitation->role))) {
            return null;
        }

        return $organization;
    }

    /**
     * register 画面のメール prefill 用に、session の invitation_token から
     * 「active な招待の招待先 email」を解決する。fail-secure:
     *  - session 値が非文字列/空 → forget して null
     *  - findActiveByPlainToken が null (不在/失効/取消/受諾済) → session から forget して null
     *    (GET 時点で stale/invalid な token を破棄し「UI は通常登録・サーバは招待フロー」の
     *    不整合を除去する)
     *  - active → 招待先 email (CipherSweet 自動復号後は string) を返す
     *
     * 平文 email 検索は行わない (token_hash 照合のみ)。列挙面を広げない。
     * 正常系 (active) では forget しない: 後続 POST の CreateNewUser が受諾に token を使う。
     *
     * **戻り契約**: 非 null を返す場合は必ず非空の email 文字列である (空文字は null に潰す)。
     * 呼び出し側 (Fortify registerView の no-store 判定 / frontend の isInvited) はこの契約に依存する。
     */
    public function resolveRegisterPrefillEmail(Session $session): ?string
    {
        $raw = $session->get('invitation_token');

        if (! is_string($raw) || $raw === '') {
            if ($raw !== null) {
                $session->forget('invitation_token'); // 汚染値を除去
            }

            return null;
        }

        $invitation = OrganizationInvitation::findActiveByPlainToken($raw);
        if ($invitation === null) {
            $session->forget('invitation_token'); // stale/invalid を GET 時点で破棄

            return null;
        }

        // CipherSweet 復号後の email。空文字 (想定外の欠損) は fail-secure に握り、
        // token を破棄して null 返却する (prefill しない)。
        $email = $invitation->email;
        if ($email === '') {
            $session->forget('invitation_token');

            return null;
        }

        return $email;
    }

    /**
     * 招待の取り消し (論理失効)。行削除ではなく revoked_at を立てる (監査痕跡を残す)。
     * 既に失効/受諾済みなら冪等 no-op (二重取り消しを例外にしない)。
     */
    public function revokeInvitation(OrganizationInvitation $invitation): void
    {
        if ($invitation->isRevoked() || $invitation->isAccepted()) {
            return;
        }

        $invitation->forceFill(['revoked_at' => now()])->save();
    }

    /**
     * **受信者視点の pending 集合クエリの唯一の起点**。
     *
     * 裁定 AG-113 の必須要素 (b)(c) をここ 1 箇所で満たす:
     *  (b) 受諾の解決・一覧・件数がすべてこのメソッドを通る (絞り込みが 1 本 = drift しない)
     *  (c) 未ログイン / 未 verified / email 空は **null を返し DB を一切引かない**
     *      (共有 prop は全リクエストで評価されるため、この early return が実効的な負荷契約になる)
     *
     * @return Builder<OrganizationInvitation>|null null = 引くべきでない (クエリを組み立てない)
     */
    private function pendingInvitationsQuery(?User $user): ?Builder
    {
        if ($user === null || ! $user->hasVerifiedEmail()) {
            return null;
        }

        $email = $user->email; // CipherSweet 復号後
        // ★企業 SSO でしか入れない利用者は使えるメールを持たない (T253 / A3)。
        //   宛先が無いので招待の引き当ても行わない。
        if ($email === null || $email === '') {
            return null;
        }

        return OrganizationInvitation::query()->activePendingForEmail($email);
    }

    /**
     * 自分宛の受諾可能な招待の一覧 (受信者視点 DTO)。表示専用でロックしない。
     *
     * @return list<PendingInvitationForUserDto>
     */
    public function pendingInvitationsFor(?User $user): array
    {
        $query = $this->pendingInvitationsQuery($user);
        if ($query === null) {
            return [];
        }

        // N+1 回避に with('organization') を付ける (DTO が organization->name を読む)
        $invitations = $query->with('organization')->orderBy('expires_at')->get();

        $rows = [];
        foreach ($invitations as $invitation) {
            $rows[] = PendingInvitationForUserDto::fromInvitation($invitation);
        }

        return $rows;
    }

    /** 自分宛の受諾可能な招待の件数 (共有 prop 用。一覧と同一 scope を再利用する)。 */
    public function pendingInvitationCountFor(?User $user): int
    {
        return $this->pendingInvitationsQuery($user)?->count() ?? 0;
    }

    /**
     * **アプリ内受諾** (メールの URL を根拠にしない受諾。裁定 AG-113 標準形 v1)。
     *
     * 受諾の根拠は「auth 済み ∧ email 確認済み ∧ ログイン者 email = 招待宛先」であり、
     * その全部が pendingInvitationsQuery() の 1 本に畳まれている。
     *
     * **戻り値契約**: 業務上の受諾不能 (宛先不一致 / 不在 / 期限切れ / 取消済 / 受諾済 /
     * 組織削除済 / ロック下再検証での敗北) は例外にせず null を返す (呼び出し側が一律 404)。
     * DB 障害・インフラ障害・プログラム不整合の例外は**捕捉せず伝播させる** (500 のまま。
     * 404 に化けさせない)。この分離により、将来この分岐へ理由を足しても情報が漏れない。
     *
     * **ロックと最終権威**:
     *  1. 下見 (ロック無し) で organization_id を得る
     *  2. canonical 順序 (users 昇順 → organizations) で lockForMembershipWrite
     *     — 組織の soft-delete は同じ organizations 行の UPDATE なのでここで直列化される
     *  3. **ロック下で同一 scope を再解決** — ここが組織 soft-delete / 取消 / 期限に対する権威
     *  4. joinOrganization() が招待行を lockForUpdate して最終再検証 (取消の割り込みはここが閉じる。
     *     revokeInvitation は membership ロックを取らないため 3 と 4 の間に窓があるが、
     *     取り消し側の UPDATE も同じ招待行を取るため直列化される)
     * joinOrganization() は同一 tx 内で同じ行の lockForMembershipWrite を再取得するが、
     * 取得済み行の再取得は no-op でロック順序も変わらない (新しい順序を作らない
     * = デッドロックを導入しない)。
     *
     * @param  string  $invitationId  route parameter (未検証の文字列。pattern で 1-18 桁数値に制約済み)
     */
    public function acceptPendingInvitation(?User $user, string $invitationId): ?Organization
    {
        if ($user === null) {
            return null;
        }

        return DB::transaction(function () use ($user, $invitationId): ?Organization {
            // 1. 下見 (ロック前)。ここで null なら DB もロックも最小で終わる
            $preliminary = $this->pendingInvitationsQuery($user)?->whereKey($invitationId)->first();
            if ($preliminary === null) {
                return null;
            }

            // 2. canonical 順序でロック (users 昇順 → organizations)
            $organizationId = $preliminary->getAttribute('organization_id');
            Assert::integer($organizationId);
            $this->lockForMembershipWrite([$this->keyOf($user)], [$organizationId]);

            // 3. ロック下で同一 scope を再解決 (下見の結果は信用しない)
            $invitation = $this->pendingInvitationsQuery($user)?->whereKey($invitationId)->first();
            if ($invitation === null) {
                return null;
            }

            $organization = $invitation->organization;
            Assert::isInstanceOf($organization, Organization::class);

            // 4. 変換本体 (token 経路と共有)。false = 招待行ロック下の再検証で受諾不能
            if (! $this->joinOrganization($invitation, $organization, $user, OrganizationRole::from($invitation->role))) {
                return null;
            }

            // 現在組織は切り替えない (POST 受諾の既存契約と揃える。驚き最小)
            return $organization;
        });
    }

    /**
     * 招待受諾の確定処理 (attach + ロール付与 + accepted_at)。全受諾経路の共通コア。
     * accepted_at は $fillable 外のため forceFill で明示代入する。
     *
     * 並行受諾への防御は 2 層:
     * 1. **招待行の lockForUpdate**: 同一招待 (同一トークン二重送信) の並行受諾を直列化し、
     *    accepted_at / revoked_at / expires_at の判定をロック下で再実行する (TOCTOU 封じ。
     *    呼び出し元の事前検証は第 1 層として維持)
     * 2. **organization_user の原子的 INSERT (insertOrIgnore)**: 別招待経由の並行 join
     *    (同一 user × 同一 org) でも unique 違反にならず、勝った側だけが role/pivot を付与する
     *    (affected rows = 0 なら join 済みと判断してスキップ)。値はすべてサーバ側モデル由来
     *    (organization/user は relation 解決済み) で、payload 不信の保護キー規約に反しない。
     *    organization_user は (organization_id, user_id) UNIQUE + timestamps のみの pivot。
     *
     * 招待は「組織に入れる」ことだけを意味する (役割付き招待は裁定 AG-079 で撤去)。
     * 編集者 / 撮影者の割当は参加後に applyConsoleRole で行う。
     *
     * @return bool true = ロック下再検証を通り変換が完了した (既 join の冪等 no-op を含む) /
     *              false = ロック下で受諾不能 (受諾済 / 取消済 / 期限切れ) だった。
     *              **false は全呼び出し元が必ず消費する** (成功扱いで返さない)。
     */
    private function joinOrganization(OrganizationInvitation $invitation, Organization $organization, User $user, OrganizationRole $role): bool
    {
        return DB::transaction(function () use ($organization, $user, $role, $invitation): bool {
            // canonical 共通ロック境界 (users 昇順 → organizations)。並行メンバー追加を
            // deleteAccount 等と直列化する (招待行ロックの手前で org/user 行ロックを取る)。
            $this->lockForMembershipWrite([$this->keyOf($user)], [$this->keyOf($organization)]);

            // 1. 招待行ロック + 受諾可能状態のロック下再検証 (並行受諾に敗れた側は冪等 no-op)
            /** @var OrganizationInvitation $locked */
            $locked = OrganizationInvitation::query()->whereKey($invitation->id)->lockForUpdate()->firstOrFail();
            if ($locked->isAccepted() || $locked->isRevoked() || $locked->isExpired()) {
                return false; // 期限境界の TOCTOU も含めロック下で完全再検証 (敗者は受諾不能)
            }

            // 1b. 宛先 email のロック下再照合 (最終権威。受諾中の email 変更 TOCTOU / stale user を封じる)。
            //     ロック**読み**した User インスタンスで照合する ($user->fresh() は非ロック SELECT で
            //     MVCC スナップショット版を返しうるため使わない)。users 行は lockForMembershipWrite が
            //     canonical 順序で既にロック済みのため、同一行の lockForUpdate 再取得は no-op re-acquire
            //     (新しいロック順序を作らない = デッドロックを導入しない。上の $locked 招待行 reload と同じ流儀)。
            //     3 経路 (token / register / in-app) 共通コアに入るため全経路がロック下 email 境界を得る
            //     (register / in-app は元から pre-lock で email 一致を保証済みのため挙動は不変)。
            /** @var User $lockedUser */
            $lockedUser = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
            if (! $locked->isAddressedTo($lockedUser)) {
                return false; // 宛先不一致は受諾不能へ畳む (既存の false 契約と同じ neutral 扱い)
            }

            // 2. org 参加の原子的 INSERT。0 行 = 別経路で join 済み (role は変更しない。
            //    非正規状態が残る場合も「未割当」として可視化され管理画面から修復できる)
            $joined = DB::table('organization_user')->insertOrIgnore([
                'organization_id' => $organization->id,
                'user_id' => $user->getKey(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($joined === 1) {
                $user->addRole($role->value, $organization->laratrust_team_id);
            }

            $locked->forceFill(['accepted_at' => now()])->save();

            return true;
        });
    }

    /**
     * ロール遷移コマンドの適用 (概念設計 D2(b))。1 トランザクションで最終状態を保証する:
     * - Admin:   org Admin + org 配下 project pivot detach (stale 掃除)
     * - Editor:  org Member + Default Project pivot role=project_admin (sync)
     * - Shooter: org Member + Default Project pivot role=project_member (sync)
     * changeRole 再利用により非メンバー拒否・最終 Owner 保護を継承する
     * (DB::transaction のネストは savepoint 扱いのため、changeRole の ValidationException は
     * そのまま外へ伝播し外側 tx ごと rollback される)。
     *
     * 失効 (組織アクセスの資格情報) は**自分では呼ばない**。呼ぶと 1 操作で 2 回失効させる
     * ことになるため、委譲先 (normalizeOrganizationRole / changeRole) が呼ぶ。
     * よって Editor / Shooter コマンドでは順序が
     * 「組織ロールの入れ替え → 失効 → プロジェクト側の pivot 更新」になる。
     * 失効が最後でないのは、**プロジェクト側の役割を失効の境界に入れていない**からである
     * (トークンの結び付き先は組織であり、pivot の更新は資格情報の広さを変えない)。
     * 後続の pivot 更新が失敗すれば外側のひとまとまりごと巻き戻るので、
     * 「失効だけが残る」中間状態は生まれない。
     *
     * @param  User|null  $actor  操作した人 (監査用。HTTP 外 = バッチ・コンソールは null が正常値)
     *
     * @throws ValidationException 非メンバー / 最終 Owner 保護 / Default Project 不在
     */
    public function applyConsoleRole(Organization $organization, User $target, AdminConsoleRole $role, ?User $actor): void
    {
        DB::transaction(function () use ($organization, $target, $role, $actor): void {
            // canonical 共通ロック境界 (users 昇順 → organizations)。normalizeOrganizationRole の
            // 直接 addRole 経路も含めロック下で直列化する。
            $this->lockForMembershipWrite([$this->keyOf($target)], [$this->keyOf($organization)]);

            $projectRole = $role->projectRole();

            if ($projectRole === null) {
                // Admin コマンド: org ロール正規化 → stale pivot 掃除
                // (org 配下 project に限定 = cross-org 不変条件)
                $this->normalizeOrganizationRole($organization, $target, $role, $actor);
                $this->detachProjectMemberships($organization, $target);

                return;
            }

            // Editor/Shooter コマンド: 書き込み用解決を先に行う (行ロック保持。
            // 取得〜pivot 更新まで削除競合を排除 + 不在エラーをロール変更より前に確定)
            $project = $this->defaultProjects->resolveForUpdate($organization);
            if ($project === null) {
                throw ValidationException::withMessages([
                    'role' => ['編集者・撮影者を割り当てるには、先にプロジェクトを作成してください。'],
                ]);
            }

            $this->normalizeOrganizationRole($organization, $target, $role, $actor);
            $project->members()->syncWithoutDetaching([
                $target->id => ['role' => $projectRole->value],
            ]);
        });
    }

    /**
     * 遷移コマンドの org ロール正規化。attach 済みかつ Laratrust ロール未付与の異常行 (表示状態は
     * 「未割当」= MemberRoleState::derive(null, ...)) は changeRole が「非メンバー」として
     * 拒否するため、修復経路として addRole で直接付与する (管理画面から正規化できる契約)。
     *
     * **本メソッドは applyConsoleRole が張ったトランザクションの内側でしか呼ばれない**
     * (private かつ呼び出し元が 1 箇所)。修復の枝で失効を呼ぶのはこの前提に依存する。
     *
     * @param  User|null  $actor  操作した人 (監査用)
     *
     * @throws ValidationException 非メンバー / 最終 Owner 保護 (changeRole 継承)
     */
    private function normalizeOrganizationRole(Organization $organization, User $target, AdminConsoleRole $role, ?User $actor): void
    {
        if ($target->organizationRole($organization) === null) {
            // 非 attach は changeRole と同じ契約で拒否 (第 1 層は Controller の URL 整合 guard = 404)
            if (! $organization->users()->whereKey($target->getKey())->exists()) {
                throw ValidationException::withMessages(['role' => ['このユーザーは組織のメンバーではありません。']]);
            }
            $target->addRole($role->organizationRole()->value, $organization->laratrust_team_id);

            // 修復も役割の付与である。changeRole を経ない唯一の枝なので、ここにも置く
            // (置かないと「管理画面から役割を直したのに古いトークンが生きている」経路が残る)。
            $this->accessRevoker->revoke(
                $organization,
                $target,
                OrgAccessRevocationReason::RoleChanged,
                $actor,
            );

            return;
        }

        // 同値なら changeRole 内で早期 return = 冪等。最終 Owner 保護も継承
        $this->changeRole($organization, $target, $role->organizationRole(), $actor);
    }

    /**
     * ロール変更。Owner への昇格は transferOwnership のみが正規経路
     * (Controller 側のバリデーションが Owner 指定を拒否する)。
     *
     * **役割の入れ替えの後、同じトランザクションの中で**その人のこの組織における
     * 機械クライアント向け資格情報を失効させる (家系の正典 v2)。昇格でも切れる —
     * 役割の集合の差分で判断すると、権限ライブラリの役割キャッシュ依存になり
     * 取りこぼしたときに通してしまう側へ倒れるためである。
     *
     * @param  User|null  $actor  操作した人 (監査用。HTTP 外は null が正常値)
     *
     * @throws ValidationException 非メンバー / 最後の Owner の降格
     */
    public function changeRole(Organization $organization, User $target, OrganizationRole $newRole, ?User $actor): void
    {
        // [TOCTOU 封じ] 事前チェックを撤廃し、検証をすべてロック取得後・ロック下で行う。
        DB::transaction(function () use ($organization, $target, $newRole, $actor): void {
            // canonical 共通ロック境界 (users 昇順 → organizations)。deleteAccount 等と直列化。
            $this->lockForMembershipWrite([$this->keyOf($target)], [$this->keyOf($organization)]);

            // ロック下で最新状態を再取得 (laratrust のロールキャッシュも fresh で破棄)
            $freshTarget = $target->fresh();
            Assert::isInstanceOf($freshTarget, User::class);

            $currentRole = $freshTarget->organizationRole($organization);
            if ($currentRole === null) {
                throw ValidationException::withMessages(['role' => ['このユーザーは組織のメンバーではありません。']]);
            }
            if ($currentRole === $newRole) {
                return; // 冪等 (何も変わっていないので失効もしない)
            }
            // Owner を降格させる場合は他に Owner がいることを要求 (Owner 不在の組織を作らない)
            if ($currentRole === OrganizationRole::Owner && ! $this->hasAnotherOwner($organization, $freshTarget)) {
                throw ValidationException::withMessages([
                    'role' => ['最後のオーナーは降格できません。先にオーナーを移譲してください。'],
                ]);
            }
            $freshTarget->removeRole($currentRole->value, $organization->laratrust_team_id);
            $freshTarget->addRole($newRole->value, $organization->laratrust_team_id);

            // 役割の入れ替えの**後**・同一トランザクション内
            $this->accessRevoker->revoke(
                $organization,
                $freshTarget,
                OrgAccessRevocationReason::RoleChanged,
                $actor,
            );
        });
    }

    /**
     * メンバー削除。Owner は削除不可 (先に transferOwnership が必要)。
     *
     * 除名の**後**、同じトランザクションの中でその人のこの組織における機械クライアント向け
     * 資格情報を失効させる (家系の正典 v2)。
     *
     * @param  User|null  $actor  操作した人 (監査用。HTTP 外は null が正常値)
     *
     * @throws ValidationException 非メンバー / Owner
     */
    public function removeMember(Organization $organization, User $target, ?User $actor): void
    {
        // [TOCTOU 封じ] 検証をロック取得後・ロック下で行う。
        DB::transaction(function () use ($organization, $target, $actor): void {
            // canonical 共通ロック境界 (users 昇順 → organizations)。deleteAccount 等と直列化。
            $this->lockForMembershipWrite([$this->keyOf($target)], [$this->keyOf($organization)]);

            if (! $organization->users()->whereKey($target->getKey())->exists()) {
                throw ValidationException::withMessages(['member' => ['このユーザーは組織のメンバーではありません。']]);
            }
            $freshTarget = $target->fresh();
            Assert::isInstanceOf($freshTarget, User::class);
            $role = $freshTarget->organizationRole($organization);
            if ($role === OrganizationRole::Owner) {
                throw ValidationException::withMessages([
                    'member' => ['オーナーは削除できません。先にオーナーを移譲してください。'],
                ]);
            }
            $organization->users()->detach($freshTarget->getKey());
            if ($role !== null) {
                $freshTarget->removeRole($role->value, $organization->laratrust_team_id);
            }
            // project pivot 掃除 (org 配下 project に限定。別 org の pivot は維持)
            $this->detachProjectMemberships($organization, $freshTarget);

            // 除名の後・同一トランザクション内
            $this->accessRevoker->revoke(
                $organization,
                $freshTarget,
                OrgAccessRevocationReason::MemberRemoved,
                $actor,
            );
        });
    }

    /**
     * org 配下 project の pivot を一括 detach する。対象 project id は必ず
     * $organization->projects() (org-scoped relation) から解決する (cross-org 不変条件)。
     * project_members は pivot テーブルで対応する Eloquent モデル・モデルイベントを持たないため、
     * 意図的に素の delete を使う (belongsToMany::detach も pivot イベントは発火しない = 等価)。
     * 挙動契約は ConsoleRoleTransitionTest が固定する。
     */
    private function detachProjectMemberships(Organization $organization, User $target): void
    {
        /** @var list<int> $projectIds */
        $projectIds = $organization->projects()->pluck('projects.id')->all();
        if ($projectIds === []) {
            return;
        }

        DB::table('project_members')
            ->whereIn('project_id', $projectIds)
            ->where('user_id', $target->getKey())
            ->delete();
    }

    /**
     * オーナー移譲。organization_user の両者の行を lockForUpdate で直列化し、
     * 並行移譲による Owner 0 人 / 2 人の中間状態を防ぐ (spirux 方式)。
     *
     * 役割の入れ替えの後、同じトランザクションの中で**譲り手と受け手の両方**の
     * 機械クライアント向け資格情報を失効させる (家系の正典 v2)。受け手は昇格だが、
     * 役割の集合の差分で判断しないという設計判断の帰結として同じように切れる。
     *
     * @throws ValidationException from が Owner でない / to が非メンバー / 自己移譲
     */
    public function transferOwnership(Organization $organization, User $from, User $to): void
    {
        if ($from->getKey() === $to->getKey()) {
            throw ValidationException::withMessages(['user_id' => ['自分自身には移譲できません。']]);
        }

        DB::transaction(function () use ($organization, $from, $to): void {
            // canonical 共通ロック境界 (users 昇順 → organizations)。deleteAccount 等と直列化。
            // 従来の pivot 行ロックを users 行ロックへ置換し、移譲の直列化基点を統一する。
            $this->lockForMembershipWrite([$this->keyOf($from), $this->keyOf($to)], [$this->keyOf($organization)]);

            // ロック下で最新インスタンスを再取得して検証 (事前取得モデル・stale org を信用しない)
            /** @var Organization $freshOrg */
            $freshOrg = Organization::query()->whereKey($organization->getKey())->firstOrFail();
            /** @var User $freshFrom */
            $freshFrom = User::query()->whereKey($from->getKey())->firstOrFail();
            /** @var User $freshTo */
            $freshTo = User::query()->whereKey($to->getKey())->firstOrFail();

            // 両者が組織メンバーであることをロック下で確認 (organization_user は
            // (organization_id, user_id) UNIQUE のため最大 2 行)。
            $memberUserIds = DB::table('organization_user')
                ->where('organization_id', $freshOrg->id)
                ->whereIn('user_id', [$freshFrom->getKey(), $freshTo->getKey()])
                ->pluck('user_id')
                ->all();
            if (count($memberUserIds) < 2) {
                throw ValidationException::withMessages([
                    'user_id' => [self::MEMBER_REQUIRED_MESSAGE],
                ]);
            }

            // ロック取得後に最新状態で Owner を再確認する (TOCTOU 防止)
            if ($freshFrom->organizationRole($freshOrg) !== OrganizationRole::Owner) {
                throw ValidationException::withMessages(['user_id' => ['オーナーのみ移譲できます。']]);
            }

            $teamId = $freshOrg->laratrust_team_id;
            $toRole = $freshTo->organizationRole($freshOrg);

            $freshFrom->removeRole(OrganizationRole::Owner->value, $teamId);
            $freshFrom->addRole(OrganizationRole::Admin->value, $teamId);

            if ($toRole !== null) {
                $freshTo->removeRole($toRole->value, $teamId);
            }
            $freshTo->addRole(OrganizationRole::Owner->value, $teamId);

            // 役割の入れ替えの後・同一トランザクション内。操作した人は譲り手 ($freshFrom)。
            // 受け手も切る (昇格でも切る = 差分で判断しないという設計判断の帰結)。
            $this->accessRevoker->revoke($freshOrg, $freshFrom, OrgAccessRevocationReason::OwnershipTransferredFrom, $freshFrom);
            $this->accessRevoker->revoke($freshOrg, $freshTo, OrgAccessRevocationReason::OwnershipTransferredTo, $freshFrom);
        });

        $this->recorder->record(SecurityEventType::OwnershipTransferred, $from, [
            'organization_id' => $organization->id,
            'from_user_id' => $from->getKey(),
            'to_user_id' => $to->getKey(),
        ]);
    }

    /**
     * 退会の予約 (猶予期間つき削除)。**凍結方式**なので users 行の生死は変えない。
     *
     * 冪等: 既に予約中なら **`purge_after` を延長せず**既存の予約をそのまま返す
     * (二重送信で猶予が伸び続けるのを防ぐ。取消 → 再予約は明示操作)。
     * この冪等 no-op が「予約操作からの通知 job 生成は最大 1 件」の一回性も担う
     * (AGENTS.md ドメイン規約 6: 結果の一回性は永続状態遷移が担う)。
     *
     * **予約時にブロッカーを評価しない**。予約は退会の意思表示であって削除ではなく、
     * ブロックされている人が予約すらできないと「解約待ちの間は退会予約もできない」詰みになる。
     * 権威判定は執行時 (deleteAccount のロック下再評価) が担う。
     *
     * @return AccountDeletionStateDto 予約後の状態 (通知とレスポンスが同じ値を見る)
     */
    public function requestAccountDeletion(User $user): AccountDeletionStateDto
    {
        return DB::transaction(function () use ($user): AccountDeletionStateDto {
            // canonical 共通ロック境界 (users 昇順 → organizations 昇順)。organizations は不要だが
            // 順序の起点を deleteAccount と揃える (新しいロック順序を作らない)。
            $this->lockForMembershipWrite([$this->keyOf($user)], []);

            $fresh = $user->fresh();
            Assert::isInstanceOf($fresh, User::class);

            $state = AccountDeletionStateDto::fromUser($fresh);
            if ($state->isPending()) {
                return $state; // 冪等 no-op (延長しない / 通知も発火しない)
            }

            // 秒精度で確定させる (DB の timestamp(0) と in-memory 値のズレで
            // 通知側の一致検査 matches() が偽陰性にならないようにする)。
            $requestedAt = CarbonImmutable::now()->startOfSecond();
            // 猶予日数の解決は AccountDeletionGrace 1 箇所だけ。Service は config を直読しない。
            $purgeAfter = AccountDeletionGrace::purgeAfter($requestedAt);
            $fresh->forceFill([
                'deletion_requested_at' => $requestedAt,
                'deletion_purge_after' => $purgeAfter,
            ])->save();

            $this->recorder->record(SecurityEventType::AccountDeletionRequested, $fresh);

            // AGENTS.md ドメイン規約 11: 業務状態の保存とキュー投入は**同一トランザクション内**で
            // 行う (afterCommit に依存しない)。通知側は送信直前に予約の生存を再確認する。
            $fresh->notify(new AccountDeletionRequestedNotification($requestedAt, $purgeAfter));
            $this->notifications->notifyAccountDeletionRequested($fresh, $purgeAfter);

            return AccountDeletionStateDto::fromUser($fresh);
        });
    }

    /**
     * 退会予約の取消。**誤操作救済の本体**であり、ブロッカーの有無に関わらず必ず成功する。
     * 冪等: 予約が無ければ no-op。
     */
    public function cancelAccountDeletion(User $user): AccountDeletionStateDto
    {
        return DB::transaction(function () use ($user): AccountDeletionStateDto {
            $this->lockForMembershipWrite([$this->keyOf($user)], []);

            $fresh = $user->fresh();
            Assert::isInstanceOf($fresh, User::class);

            if (! AccountDeletionStateDto::fromUser($fresh)->isPending()) {
                return AccountDeletionStateDto::fromUser($fresh); // 冪等 no-op
            }

            $fresh->forceFill([
                'deletion_requested_at' => null,
                'deletion_purge_after' => null,
            ])->save();

            $this->recorder->record(SecurityEventType::AccountDeletionCancelled, $fresh);

            return AccountDeletionStateDto::fromUser($fresh);
        });
    }

    /**
     * 予約の執行 (日次バッチ専用)。**期限到来をロック下で再確認してから**既存の
     * `deleteAccount()` をそのまま呼ぶ (判定コードを分岐させない = 課金ガードの
     * ロック下再評価をそのまま継承する)。
     *
     * @return bool true = 削除した / false = 期限未到来 or 予約が消えていた (抽出後の取消)
     *
     * @throws ValidationException 退会ブロッカーが立っている (呼び出し側が「業務上の保留」として捌く)
     */
    public function executeAccountDeletionRequest(User $user): bool
    {
        $executed = false;

        $this->deleteAccount($user, null, function (User $locked) use (&$executed): bool {
            // deleteAccount のロック取得後・ガード評価**前**に呼ばれる前提条件フック。
            $executed = AccountDeletionStateDto::fromUser($locked)->isDue(CarbonImmutable::now());

            return $executed;
        }, AccountDeletionAuditContext::nonHttp());

        return $executed;
    }

    /**
     * 退会をブロックしている組織と理由。
     *
     * 述語:
     *   soleOwned(user, org) := user が Owner かつ 他に Owner がいない
     *   reasons:
     *     - OwnerlessMembers : 他メンバーが 1 人以上残る (孤児化するメンバーが居る)
     *     - ActiveBilling    : 生きた課金責務が残る (AccountDeletionBillingGuard)
     *   blocked := soleOwned かつ reasons が非空
     *
     * 個人組織 (自分だけがメンバー) でも **課金責務があれば blocker になる**。
     * 退会後の組織は Owner 不在で存続し (User 削除では organizations 行は消えない)、
     * アプリには組織削除も解約の主体も無いため、課金が宙づりになるため。
     *
     * 読み取り専用判定 (ロックしない。表示スナップショット用)。**通常のアプリ経路の**権威判定は
     * deleteAccount がロック下で再評価する。課金状態の読み取りを組織行ロック取得**後**に行うのは
     * membership 側の race を封じるためであり、**Cashier (vendor) の WebhookController が
     * subscription 行を作る経路との完全排他ではない**。漏れは daily の
     * billing:detect-orphan-billing-organizations が second layer として拾う。
     *
     * 性能: **先に「唯一 Owner の組織」へ絞ってから課金を引く** (逆にすると全所属組織で
     * 課金クエリが走る)。
     *
     * @return Collection<int, AccountDeletionBlockerDto>
     */
    public function organizationsBlockingDeletion(User $user): Collection
    {
        return $user->organizations()
            ->withCount('users')
            ->get()
            ->filter(fn (Organization $organization): bool => $user->organizationRole($organization) === OrganizationRole::Owner
                && ! $this->hasAnotherOwner($organization, $user))
            ->map(function (Organization $organization): ?AccountDeletionBlockerDto {
                // withCount('users') 派生属性。PHPStan は型を知らないため integerish で narrowing。
                $usersCount = $organization->getAttribute('users_count');
                Assert::integerish($usersCount);

                $reasons = [];
                if ((int) $usersCount > 1) {
                    $reasons[] = AccountDeletionBlockReason::OwnerlessMembers;
                }
                if ($this->billingGuard->hasLiveBillingObligation($organization)) {
                    $reasons[] = AccountDeletionBlockReason::ActiveBilling;
                }
                if ($reasons === []) {
                    return null;
                }

                return AccountDeletionBlockerDto::build($organization, $reasons);
            })
            // PHPStan level 10 では引数無し filter() が ?Dto → Dto に narrow しきらないため明示する
            ->filter(fn (?AccountDeletionBlockerDto $blocker): bool => $blocker !== null)
            ->values();
    }

    /**
     * Owner が 1 人も居ない組織 (通常は 0 件。異常系の検知用)。
     * 読み取り専用でロックしない。role_user は laratrust_team_id で突き合わせる
     * (権限判定は常に team を明示する不変条件)。
     *
     * 列名の対応: Laratrust の pivot は role_user でその team 列は team_id、
     * organizations 側は laratrust_team_id (先例: PersonalPlanService)。
     *
     * @return Collection<int, Organization>
     */
    public function organizationsWithoutOwner(): Collection
    {
        return Organization::query()
            ->whereDoesntHave('users', function (Builder $query): void {
                $query->whereHas('roles', function (Builder $roleQuery): void {
                    $roleQuery->where('name', OrganizationRole::Owner->value)
                        ->whereColumn('role_user.team_id', 'organizations.laratrust_team_id');
                });
            })
            ->get();
    }

    /**
     * 企業 SSO の初回ログインで作られた利用者を、接続が属する組織へ最小権限で所属させる (T253 / C1)。
     *
     * ★**ロール書き込みの単一窓口**である本サービスに置く。
     *   {@see EnterpriseUserProvisioner} から呼ばれ、
     *   `MembershipWriteLockInventoryTest` の「Laratrust の書き込みはロック済みサービス経由のみ」
     *   という直列化の前提を崩さない (ロール書き込みを企業 SSO の側へ持ち出さない)。
     *
     * ★呼び出し元は既に**接続の行**を `lockForUpdate()` した同一トランザクションの中にいる。
     *   ここで取るロックの順序は「接続 → users → organizations」であり、
     *   接続の行より先に他の行をロックする経路は存在しない (D1 は接続の行しかロックしない) ので
     *   既存のロック順序と循環しない。
     *
     * ★利用者は直前に作られた新規行なので、この付与が**既存組織の owner 集合を変えることはない**
     *   (付与するのは常に最小権限の Member である)。
     */
    public function attachJustInTimeMember(Organization $organization, User $user, OrganizationRole $role): void
    {
        $this->lockForMembershipWrite([$this->keyOf($user)], [$this->keyOf($organization)]);

        $joined = DB::table('organization_user')->insertOrIgnore([
            'organization_id' => $organization->id,
            'user_id' => $user->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($joined === 1) {
            // ★権限判定は常に laratrust_team_id を明示する (AGENTS.md セキュリティ不変条件 5)。
            $user->addRole($role->value, $organization->laratrust_team_id);
        }
    }

    /**
     * メンバーシップ書き込みの共通ロック境界。canonical 順序で行ロックを取り、
     * デッドロックを構造的に排除する: **users(id 昇順) → organizations(id 昇順)**。
     * ロック取得後は呼び出し側が最新状態を DB から再取得して判定すること (事前取得値を信用しない)。
     *
     * @param  list<int>  $userIds
     * @param  list<int>  $organizationIds
     */
    private function lockForMembershipWrite(array $userIds, array $organizationIds): void
    {
        $sortedUserIds = collect($userIds)->unique()->sort()->values()->all();
        if ($sortedUserIds !== []) {
            DB::table('users')->whereIn('id', $sortedUserIds)->orderBy('id')->lockForUpdate()->get();
        }
        $sortedOrgIds = collect($organizationIds)->unique()->sort()->values()->all();
        if ($sortedOrgIds !== []) {
            DB::table('organizations')->whereIn('id', $sortedOrgIds)->orderBy('id')->lockForUpdate()->get();
        }
    }

    /**
     * モデルの主キーを int として取得する (getKey() の mixed を PHPStan L10 で narrowing)。
     * 本アプリのメンバーシップ関連モデル (User / Organization) は bigint auto-increment 主キー。
     */
    private function keyOf(Model $model): int
    {
        $key = $model->getKey();
        Assert::integer($key);

        return $key;
    }

    /**
     * アカウント削除。ガードと削除を同一トランザクション + 行ロックで直列化する。
     * 削除するとその組織を Owner 不在で残す組織があれば拒否する
     * (メンバーの孤児化防止 + 課金責務の宙づり防止・最終権威)。
     *
     * 直列化の仕組み (owner 判定は role_user を読むが role_user を直接ロックはしない):
     * 組織の owner 集合を変える書き込み経路 (changeRole / transferOwnership / removeMember /
     * applyConsoleRole / joinOrganization) はすべて自 tx 冒頭で `lockForMembershipWrite`
     * により対象 organizations 行をロックする (施策7 の drift-guard が新経路の登録を強制し、
     * 施策8b の role-grant sole-gateway テストが本サービス外の owner 付与を禁止する)。
     * よって「organizations 行」が owner 集合変更の共通 mutex となり、deleteAccount が自分の
     * 所属組織行をすべてロックしている間は、それらの組織の owner 数を変える並行書き込みは
     * ブロックされる (集約ルート行ロックで子テーブル書き込みを直列化する既存パターン。
     * cf. AGENTS.md ドメイン規約1 の VideoManual lockForUpdate)。step1 の user 行ロックは
     * 「新組織への owner 移譲で所属集合そのものが増える」race を封じる。
     *
     * $beforeDelete はガード通過後・削除直前 (user 行が存在するうち・ロック下) に実行する
     * フック。呼び出し側のセッション破棄 (Auth::logout) をここで行うことで、ログアウトが
     * 発火する監査イベント (logout) を user 行が存在する間に記録できる (削除後だと user_id の
     * FK 違反になり記録が失われる)。ブロック時はガードが先に例外を投げ、フックは実行されない
     * (ブロックされたユーザーはログアウトされない)。**フックは例外を投げてはならない**
     * (投げると削除トランザクション全体が rollback する)。
     *
     * $precondition はロック取得直後・**ガード評価前**に呼ばれる前提条件フックである。
     * false を返すと**ブロッカー判定に入らず**削除もせずに正常終了する。日次執行バッチが
     * 「抽出後に取り消された予約」を検出する口で、ここでブロッカー例外を出さないのは
     * 「取消済みユーザーを業務上の保留と誤分類しない」ためである (null なら常に true)。
     *
     * @param  (\Closure(): void)|null  $beforeDelete  例外を投げないこと (投げると削除全体が rollback)
     * @param  (\Closure(User): bool)|null  $precondition  ロック取得直後・ガード評価前の前提条件
     *
     * @throws ValidationException 唯一 Owner かつ (他メンバーが残る ∨ 生きた課金責務がある) 組織がある
     */
    public function deleteAccount(User $user, ?\Closure $beforeDelete, ?\Closure $precondition, AccountDeletionAuditContext $auditContext): void
    {
        DB::transaction(function () use ($user, $beforeDelete, $precondition, $auditContext): void {
            // 1. 対象 User 行を最初にロック (この後の所属列挙を安定させる。列挙前に user を
            //    ロックしないと、列挙〜user ロック取得の間に別 txn が新組織 B の Owner を user へ
            //    移譲し、B を未検査のまま削除する race が残る)。
            $this->lockForMembershipWrite([$this->keyOf($user)], []);

            // 2. user ロック下で所属組織を列挙 → organizations 行を昇順ロック
            //    (メンバー追加/移譲経路も user 行をロックするため、ここで列挙は安定する)
            /** @var list<int> $organizationIds */
            $organizationIds = $user->organizations()
                ->orderBy('organizations.id')
                ->pluck('organizations.id')
                ->map(function (mixed $id): int {
                    Assert::integer($id);

                    return $id;
                })
                ->values()
                ->all();
            $this->lockForMembershipWrite([], $organizationIds);

            // 3. ロック下で述語を再評価 (fresh。事前取得値は信用しない。null フォールバック禁止)
            $freshUser = $user->fresh();
            Assert::isInstanceOf($freshUser, User::class);

            // 3a. 前提条件フック (ロック下・**ブロッカー判定より前**)。false = 削除しないで正常終了。
            //     ★判定の**前**でなければならない: 後ろに置くと、抽出後に予約を取り消した
            //       ユーザーに対してブロッカー例外が出て、バッチが「保留」と誤分類する。
            if ($precondition !== null && $precondition($freshUser) !== true) {
                return;
            }

            $blockers = $this->organizationsBlockingDeletion($freshUser);
            if ($blockers->isNotEmpty()) {
                // Inertia の resolveValidationErrors() は field ごとに先頭 1 件しかクライアントへ
                // 渡さない (withAllErrors=false 既定) ため、要約を 1 本にまとめる。
                // 組織ごとの詳細・導線は redirect back 後に再評価される props が持つ。
                $requirements = $blockers
                    ->map(fn (AccountDeletionBlockerDto $blocker): string => $blocker->requirementLabel())
                    ->implode('、');

                throw ValidationException::withMessages([
                    'account' => ["次の対応が完了するまで退会できません: {$requirements}"],
                ]);
            }

            // 4. ガード通過後・削除直前のフック (呼び出し側のセッション破棄等。user 行が
            //    存在するうちに認証イベントを発火させる)。
            if ($beforeDelete !== null) {
                $beforeDelete();
            }

            // 5. 監査記録 (純 DB insert。user_id は nullOnDelete で削除時に null 化される)。
            //    **削除実行時点の凍結状態と到達経路を残す** (T160 / bug-hunt F-4-Q1)。
            //    再現しなかった「凍結中なのに削除された」観測に対し、再発時に原因へ到達できるようにする。
            //    ★観測であって防御ではない — この値で分岐する処理は 1 つも無い。
            $this->recorder->record(SecurityEventType::AccountDeleted, $freshUser, [
                // 行ロック下で読み直した $freshUser から取る (削除と同一トランザクション内)
                'deletion_requested' => $freshUser->deletion_requested_at !== null,
                // 呼び出し元が渡す。HTTP 外 (日次執行・コンソール) は null が正常値
                'route' => $auditContext->route,
                'method' => $auditContext->method,
            ]);
            $freshUser->delete();
        });
    }

    /**
     * email がこの組織の既存メンバーのものか (blind index 照合)。
     */
    private function emailBelongsToMember(Organization $organization, string $email): bool
    {
        /** @var User|null $user */
        $user = User::whereBlind('email', 'email_index', $email)->first();
        if ($user === null) {
            return false;
        }

        return $organization->users()->whereKey($user->getKey())->exists();
    }

    /**
     * 有効な (未失効・未受諾の) 既存招待があるか。
     */
    private function hasPendingInvitation(Organization $organization, string $email): bool
    {
        return $organization->invitations()
            ->whereBlind('email', 'email_index', $email)
            ->whereNull('accepted_at')
            ->where('expires_at', '>', now())
            ->exists();
    }

    /**
     * target 以外に Owner がいるか。
     */
    private function hasAnotherOwner(Organization $organization, User $target): bool
    {
        return $organization->users()
            ->whereKeyNot($target->getKey())
            ->get()
            ->contains(
                fn (User $member): bool => $member->organizationRole($organization) === OrganizationRole::Owner,
            );
    }
}
