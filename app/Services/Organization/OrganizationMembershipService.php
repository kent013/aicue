<?php

declare(strict_types=1);

namespace App\Services\Organization;

use App\Enums\OrganizationRole;
use App\Enums\SecurityEventType;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\User;
use App\Notifications\OrganizationInvitationNotification;
use App\Services\Security\SecurityEventRecorder;
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

    public function __construct(
        private readonly SecurityEventRecorder $recorder,
    ) {}

    /**
     * メンバー招待。招待レコード生成 + 受諾 URL 付きメール送信。
     *
     * @throws ValidationException 既存メンバー / 有効な既存招待 (中立メッセージ)
     */
    public function inviteMember(Organization $organization, User $invitedBy, string $email, OrganizationRole $role): OrganizationInvitation
    {
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
     * @return Organization|null 参加した組織 / 招待が受諾不能 (不在・失効・受諾済・取消・
     *                           email 不一致・既メンバー) なら null
     */
    public function acceptInvitationIfValid(string $plainToken, User $user): ?Organization
    {
        $invitation = OrganizationInvitation::query()
            ->where('token_hash', OrganizationInvitation::hashToken($plainToken))
            ->first();

        // active (未受諾・未失効・期限内) でなければ join しない
        if ($invitation === null || $invitation->isRevoked() || $invitation->isAccepted() || $invitation->isExpired()) {
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

        return $organization;
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
     * 招待受諾の確定処理 (attach + ロール付与 + accepted_at)。両受諾経路の共通コア。
     * accepted_at は $fillable 外のため forceFill で明示代入する。
     */
    private function joinOrganization(OrganizationInvitation $invitation, Organization $organization, User $user, OrganizationRole $role): void
    {
        DB::transaction(function () use ($organization, $user, $role, $invitation): void {
            $organization->users()->attach($user);
            $user->addRole($role->value, $organization->laratrust_team_id);
            $invitation->forceFill(['accepted_at' => now()])->save();
        });
    }

    /**
     * ロール変更。Owner への昇格は transferOwnership のみが正規経路
     * (Controller 側のバリデーションが Owner 指定を拒否する)。
     *
     * @throws ValidationException 非メンバー / 最後の Owner の降格
     */
    public function changeRole(Organization $organization, User $target, OrganizationRole $newRole): void
    {
        $currentRole = $target->organizationRole($organization);
        if ($currentRole === null) {
            throw ValidationException::withMessages(['role' => ['このユーザーは組織のメンバーではありません。']]);
        }
        if ($currentRole === $newRole) {
            return;
        }

        // Owner を降格させる場合は他に Owner がいることを要求 (Owner 不在の組織を作らない)
        if ($currentRole === OrganizationRole::Owner && ! $this->hasAnotherOwner($organization, $target)) {
            throw ValidationException::withMessages([
                'role' => ['最後のオーナーは降格できません。先にオーナーを移譲してください。'],
            ]);
        }

        DB::transaction(function () use ($organization, $target, $currentRole, $newRole): void {
            $target->removeRole($currentRole->value, $organization->laratrust_team_id);
            $target->addRole($newRole->value, $organization->laratrust_team_id);
        });
    }

    /**
     * メンバー削除。Owner は削除不可 (先に transferOwnership が必要)。
     *
     * @throws ValidationException 非メンバー / Owner
     */
    public function removeMember(Organization $organization, User $target): void
    {
        if (! $organization->users()->whereKey($target->getKey())->exists()) {
            throw ValidationException::withMessages(['member' => ['このユーザーは組織のメンバーではありません。']]);
        }

        $role = $target->organizationRole($organization);
        if ($role === OrganizationRole::Owner) {
            throw ValidationException::withMessages([
                'member' => ['オーナーは削除できません。先にオーナーを移譲してください。'],
            ]);
        }

        DB::transaction(function () use ($organization, $target, $role): void {
            $organization->users()->detach($target->getKey());
            if ($role !== null) {
                $target->removeRole($role->value, $organization->laratrust_team_id);
            }
            // 削除した組織を current にしていた場合は外す (次回アクセス時に選び直す)
            if ($target->current_organization_id === $organization->id) {
                $target->forceFill(['current_organization_id' => null])->save();
            }
        });
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
            // 両者のメンバーシップ行をロック (並行する移譲・削除を直列化)。
            // count() + FOR UPDATE は pgsql が集約関数との併用を拒否するため、行を
            // 取得してロードした上で PHP 側で件数を確認する (organization_user は
            // (organization_id, user_id) UNIQUE のため最大 2 行)。
            $lockedUserIds = DB::table('organization_user')
                ->where('organization_id', $organization->id)
                ->whereIn('user_id', [$from->getKey(), $to->getKey()])
                ->lockForUpdate()
                ->pluck('user_id')
                ->all();
            if (count($lockedUserIds) < 2) {
                throw ValidationException::withMessages([
                    'user_id' => ['移譲先は組織のメンバーである必要があります。'],
                ]);
            }

            // ロック取得後に最新状態で Owner を再確認する (TOCTOU 防止)
            if ($from->organizationRole($organization) !== OrganizationRole::Owner) {
                throw ValidationException::withMessages(['user_id' => ['オーナーのみ移譲できます。']]);
            }

            $teamId = $organization->laratrust_team_id;
            $toRole = $to->organizationRole($organization);

            $from->removeRole(OrganizationRole::Owner->value, $teamId);
            $from->addRole(OrganizationRole::Admin->value, $teamId);

            if ($toRole !== null) {
                $to->removeRole($toRole->value, $teamId);
            }
            $to->addRole(OrganizationRole::Owner->value, $teamId);
        });

        $this->recorder->record(SecurityEventType::OwnershipTransferred, $from, [
            'organization_id' => $organization->id,
            'from_user_id' => $from->getKey(),
            'to_user_id' => $to->getKey(),
        ]);
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
