<?php

declare(strict_types=1);

namespace App\Services\Organization;

use App\DataTransferObjects\Organizations\AccountDeletionBlockerDto;
use App\Enums\AccountDeletionBlockReason;
use App\Enums\AdminConsoleRole;
use App\Enums\OrganizationRole;
use App\Enums\ProjectRole;
use App\Enums\SecurityEventType;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\User;
use App\Notifications\OrganizationInvitationNotification;
use App\Services\Billing\AccountDeletionBillingGuard;
use App\Services\Notification\NotificationCenterService;
use App\Services\Project\DefaultProjectResolver;
use App\Services\Security\SecurityEventRecorder;
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
    ) {}

    /**
     * メンバー招待 (3 値ロールコマンド)。招待レコード生成 + 受諾 URL 付きメール送信。
     * 編集者/撮影者は Default Project 存在が必須 (不在は ValidationException = Inertia error bag)。
     *
     * @throws ValidationException 既存メンバー / 有効な既存招待 (中立メッセージ) / project 不在
     */
    public function inviteMember(Organization $organization, User $invitedBy, string $email, AdminConsoleRole $role): OrganizationInvitation
    {
        if ($this->emailBelongsToMember($organization, $email) || $this->hasPendingInvitation($organization, $email)) {
            // 既存メンバーか既存招待かを開示しない中立メッセージ (アカウント列挙対策)
            throw ValidationException::withMessages([
                'email' => ['このメールアドレスには招待を送信できません。'],
            ]);
        }

        // 編集者/撮影者は Default Project が前提 (送信時点の静的確認。受諾時の最終確認は
        // joinOrganization が resolveForUpdate で行い、不在なら未割当に落とす)
        if ($role->projectRole() !== null && $this->defaultProjects->resolve($organization) === null) {
            throw ValidationException::withMessages([
                'role' => ['編集者・撮影者を招待するには、先にプロジェクトを作成してください。'],
            ]);
        }

        $plainToken = OrganizationInvitation::generateToken();

        $invitation = new OrganizationInvitation(['email' => $email]);
        $invitation->organization()->associate($organization);
        $invitation->invitedBy()->associate($invitedBy);
        // role / project_role / token_hash / expires_at は明示代入 (mass-assignment させない)
        $invitation->forceFill([
            'role' => $role->organizationRole()->value,
            'project_role' => $role->projectRole()?->value,
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
     * 招待受諾。ログイン中ユーザーが受諾する (招待 email と user の email の一致は要求しない)。
     *
     * @throws ValidationException token 不正 / 取り消し済み / 失効 / 受諾済み / 既メンバー
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

        $organization = $invitation->organization;
        Assert::isInstanceOf($organization, Organization::class);

        if ($organization->users()->whereKey($user->getKey())->exists()) {
            throw ValidationException::withMessages(['token' => ['既にこの組織のメンバーです。']]);
        }

        $role = OrganizationRole::from($invitation->role);

        $this->joinOrganization($invitation, $organization, $user, $role);

        return $organization;
    }

    /**
     * 登録 (register) 経路の招待受諾。CreateNewUser から呼ぶ。
     *
     * acceptInvitation (ログイン後経路) と異なり、失敗しても例外を投げず null を返す
     * (登録そのものは成功させ、呼び出し側が個人組織へ fallback するため)。register 経路は
     * 招待 email と登録 email の一致を要求する (MatchesInvitationEmail rule と対で二重防御)。
     *
     * **register 経路専用 (再利用禁止)**: join 成立時、参加した招待組織を
     * current_organization_id へ **無条件で確定する副作用** を持つ (登録直後の user は
     * current 未設定のため「招待成立 ⇒ current = 招待先」を強制できる)。この副作用は
     * 「呼び出し元の user が登録直後で current 未確定」であることに依存するため、
     * **ログイン中経路 (既存 current を持つ user) から再利用してはならない**
     * (既存 current を無条件上書きしてしまう)。POST 受諾は current を切り替えない
     * acceptInvitation を使い、共通コア joinOrganization は current を触らない
     * (InvitationTest が POST 受諾の current 非変更を固定する)。
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
        if ($invitation->email !== $user->email) {
            return null;
        }

        $organization = $invitation->organization;
        Assert::isInstanceOf($organization, Organization::class);

        // 既メンバー (race 等) は個人組織へ fallback
        if ($organization->users()->whereKey($user->getKey())->exists()) {
            return null;
        }

        $this->joinOrganization($invitation, $organization, $user, OrganizationRole::from($invitation->role));

        // [register 経路限定] 参加した招待組織をこの新規ユーザーの「現在組織」として確定する。
        // - 本メソッドは register 経路専用 (呼び出し元は CreateNewUser のみ。POST 受諾は acceptInvitation)。
        //   よって現在組織の確定は POST 受諾経路 (現在組織を切り替えない契約) に波及しない。
        // - 個人組織パスが provision() 内で現在組織を据えるのと対称に、招待参加も本サービス内で
        //   「join + 現在組織確定」を 1 ユースケースとして閉じる (呼び出し元の登録 tx 内で連続実行され、
        //   「join 済だが現在組織未設定」の中間状態を残さない)。
        // - この user は登録直後で現在組織が未確定のため、招待先組織を無条件に現在組織にする
        //   (register 責務として「招待成立 ⇒ 現在組織 = 招待先」を強制)。current_organization_id は
        //   mass-assignment 保護キーのためサーバ導出値を forceFill で明示代入する (tenant キー不信)。
        $user->forceFill(['current_organization_id' => $organization->id])->save();

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
     * 招待受諾の確定処理 (attach + ロール付与 + pivot attach + accepted_at)。両受諾経路の共通コア。
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
     * project_role 付き招待は Default Project (resolveForUpdate = 行ロック) へ pivot attach。
     * 受諾時に project が消えていた場合は org 参加のみ = 「未割当」表示状態に落ちる (可視 degrade)。
     */
    private function joinOrganization(OrganizationInvitation $invitation, Organization $organization, User $user, OrganizationRole $role): void
    {
        DB::transaction(function () use ($organization, $user, $role, $invitation): void {
            // canonical 共通ロック境界 (users 昇順 → organizations)。並行メンバー追加を
            // deleteAccount 等と直列化する (招待行ロックの手前で org/user 行ロックを取る)。
            $this->lockForMembershipWrite([$this->keyOf($user)], [$this->keyOf($organization)]);

            // 1. 招待行ロック + 受諾可能状態のロック下再検証 (並行受諾に敗れた側は冪等 no-op)
            /** @var OrganizationInvitation $locked */
            $locked = OrganizationInvitation::query()->whereKey($invitation->id)->lockForUpdate()->firstOrFail();
            if ($locked->isAccepted() || $locked->isRevoked() || $locked->isExpired()) {
                return; // 期限境界の TOCTOU も含めロック下で完全再検証 (敗者は冪等 no-op)
            }

            // 2. org 参加の原子的 INSERT。0 行 = 別経路で join 済み (role/pivot は変更しない。
            //    非正規状態が残る場合も「未割当」として可視化され管理画面から修復できる)
            $joined = DB::table('organization_user')->insertOrIgnore([
                'organization_id' => $organization->id,
                'user_id' => $user->getKey(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($joined === 1) {
                $user->addRole($role->value, $organization->laratrust_team_id);

                $projectRole = $locked->project_role;
                if ($projectRole instanceof ProjectRole) {
                    $project = $this->defaultProjects->resolveForUpdate($organization);
                    $project?->members()->syncWithoutDetaching([
                        $user->id => ['role' => $projectRole->value],
                    ]);
                }
            }

            $locked->forceFill(['accepted_at' => now()])->save();
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
     * @throws ValidationException 非メンバー / 最終 Owner 保護 / Default Project 不在
     */
    public function applyConsoleRole(Organization $organization, User $target, AdminConsoleRole $role): void
    {
        DB::transaction(function () use ($organization, $target, $role): void {
            // canonical 共通ロック境界 (users 昇順 → organizations)。normalizeOrganizationRole の
            // 直接 addRole 経路も含めロック下で直列化する。
            $this->lockForMembershipWrite([$this->keyOf($target)], [$this->keyOf($organization)]);

            $projectRole = $role->projectRole();

            if ($projectRole === null) {
                // Admin コマンド: org ロール正規化 → stale pivot 掃除
                // (org 配下 project に限定 = cross-org 不変条件)
                $this->normalizeOrganizationRole($organization, $target, $role);
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

            $this->normalizeOrganizationRole($organization, $target, $role);
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
     * @throws ValidationException 非メンバー / 最終 Owner 保護 (changeRole 継承)
     */
    private function normalizeOrganizationRole(Organization $organization, User $target, AdminConsoleRole $role): void
    {
        if ($target->organizationRole($organization) === null) {
            // 非 attach は changeRole と同じ契約で拒否 (第 1 層は Controller の URL 整合 guard = 404)
            if (! $organization->users()->whereKey($target->getKey())->exists()) {
                throw ValidationException::withMessages(['role' => ['このユーザーは組織のメンバーではありません。']]);
            }
            $target->addRole($role->organizationRole()->value, $organization->laratrust_team_id);

            return;
        }

        // 同値なら changeRole 内で早期 return = 冪等。最終 Owner 保護も継承
        $this->changeRole($organization, $target, $role->organizationRole());
    }

    /**
     * ロール変更。Owner への昇格は transferOwnership のみが正規経路
     * (Controller 側のバリデーションが Owner 指定を拒否する)。
     *
     * @throws ValidationException 非メンバー / 最後の Owner の降格
     */
    public function changeRole(Organization $organization, User $target, OrganizationRole $newRole): void
    {
        // [TOCTOU 封じ] 事前チェックを撤廃し、検証をすべてロック取得後・ロック下で行う。
        DB::transaction(function () use ($organization, $target, $newRole): void {
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
                return; // 冪等
            }
            // Owner を降格させる場合は他に Owner がいることを要求 (Owner 不在の組織を作らない)
            if ($currentRole === OrganizationRole::Owner && ! $this->hasAnotherOwner($organization, $freshTarget)) {
                throw ValidationException::withMessages([
                    'role' => ['最後のオーナーは降格できません。先にオーナーを移譲してください。'],
                ]);
            }
            $freshTarget->removeRole($currentRole->value, $organization->laratrust_team_id);
            $freshTarget->addRole($newRole->value, $organization->laratrust_team_id);
        });
    }

    /**
     * メンバー削除。Owner は削除不可 (先に transferOwnership が必要)。
     *
     * @throws ValidationException 非メンバー / Owner
     */
    public function removeMember(Organization $organization, User $target): void
    {
        // [TOCTOU 封じ] 検証をロック取得後・ロック下で行う。
        DB::transaction(function () use ($organization, $target): void {
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
            // 削除した組織を current にしていた場合は外す (次回アクセス時に選び直す)
            if ($freshTarget->current_organization_id === $organization->id) {
                $freshTarget->forceFill(['current_organization_id' => null])->save();
            }
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
        });

        $this->recorder->record(SecurityEventType::OwnershipTransferred, $from, [
            'organization_id' => $organization->id,
            'from_user_id' => $from->getKey(),
            'to_user_id' => $to->getKey(),
        ]);
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
        $currentOrganizationId = $user->current_organization_id;

        return $user->organizations()
            ->withCount('users')
            ->get()
            ->filter(fn (Organization $organization): bool => $user->organizationRole($organization) === OrganizationRole::Owner
                && ! $this->hasAnotherOwner($organization, $user))
            ->map(function (Organization $organization) use ($currentOrganizationId): ?AccountDeletionBlockerDto {
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

                return AccountDeletionBlockerDto::build(
                    $organization,
                    $reasons,
                    $organization->getKey() === $currentOrganizationId,
                );
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
     * @param  (\Closure(): void)|null  $beforeDelete  例外を投げないこと (投げると削除全体が rollback)
     *
     * @throws ValidationException 唯一 Owner かつ (他メンバーが残る ∨ 生きた課金責務がある) 組織がある
     */
    public function deleteAccount(User $user, ?\Closure $beforeDelete = null): void
    {
        DB::transaction(function () use ($user, $beforeDelete): void {
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

            // 5. 監査記録 (純 DB insert。user_id は nullOnDelete で削除時に null 化される)
            $this->recorder->record(SecurityEventType::AccountDeleted, $freshUser);
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
