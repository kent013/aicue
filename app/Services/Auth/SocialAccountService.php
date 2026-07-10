<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Enums\SecurityEventType;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Organization\OrganizationProvisioningService;
use App\Services\Security\SecurityEventRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Socialite\Contracts\User as SocialiteUser;

/**
 * SSO (Socialite) の callback 処理。intent (login / register / link) ごとに
 * SocialAccount の解決・作成を行う。
 *
 * - login: 連携済みアカウントがあればその User を返す。なければ null (登録誘導)
 * - register: 連携済みならその User (ログイン扱い)。未連携ならメール一致ユーザーへの
 *   自動リンクはせず (アカウント乗っ取り防止)、新規 User + SocialAccount を作成
 * - link: ログイン中ユーザーに連携を追加 (他ユーザーに連携済みなら拒否)
 */
class SocialAccountService
{
    public function __construct(
        private readonly SecurityEventRecorder $recorder,
        private readonly OrganizationProvisioningService $provisioning,
    ) {}

    public function findLinkedUser(string $provider, SocialiteUser $socialiteUser): ?User
    {
        $account = SocialAccount::query()
            ->where('provider', $provider)
            ->where('provider_user_id', $socialiteUser->getId())
            ->first();

        return $account?->user;
    }

    /**
     * SSO 登録。利用規約同意の証跡は呼び出し側 (controller) が検証済みの前提。
     */
    public function register(string $provider, SocialiteUser $socialiteUser): User
    {
        return DB::transaction(function () use ($provider, $socialiteUser): User {
            $email = $socialiteUser->getEmail();
            if (! is_string($email) || $email === '') {
                throw new \RuntimeException('SSO プロバイダから email が取得できませんでした');
            }

            $user = (new User([
                'name' => $socialiteUser->getName() ?? $email,
                'email' => $email,
                // SSO 登録はパスワードを持たない (ランダム値をハッシュ化して保存)
                'password' => Str::password(32),
            ]))->forceFill([
                'terms_accepted_at' => now(),
                'consent_version' => config()->string('legal.consent_version'),
                // IdP 側で検証済みの email として扱う
                'email_verified_at' => now(),
            ]);
            $user->save();

            $this->link($provider, $socialiteUser, $user);

            $this->provisioning->provisionPersonalOrganization($user);

            return $user;
        });
    }

    /**
     * 連携追加。既に他ユーザーに連携済みの場合は false を返す。
     */
    public function linkToUser(string $provider, SocialiteUser $socialiteUser, User $user): bool
    {
        $existing = SocialAccount::query()
            ->where('provider', $provider)
            ->where('provider_user_id', $socialiteUser->getId())
            ->first();

        if ($existing !== null) {
            return $existing->user_id === $user->id;
        }

        $this->link($provider, $socialiteUser, $user);

        return true;
    }

    private function link(string $provider, SocialiteUser $socialiteUser, User $user): void
    {
        $account = new SocialAccount([
            'provider' => $provider,
            'provider_user_id' => (string) $socialiteUser->getId(),
        ]);
        $account->user()->associate($user);
        $account->save();

        $this->recorder->record(SecurityEventType::SocialAccountLinked, $user, [
            'provider' => $provider,
        ]);
    }
}
