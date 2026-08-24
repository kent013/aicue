<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Enums\Auth\AuthMethodChangeEvent;
use App\Enums\SecurityEventType;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Auth\EmailTrust\EmailTrustPolicyResolver;
use App\Services\Organization\OrganizationProvisioningService;
use App\Services\Security\AuthMethodChangeNotifier;
use App\Services\Security\SecurityEventRecorder;
use App\Support\Legal\LegalConsent;
use Illuminate\Support\Facades\DB;
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
        private readonly EmailTrustPolicyResolver $emailTrust,
        private readonly AuthMethodChangeNotifier $notifier,
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

            // IdP が email 所有を検証している provider のみ検証済みとして扱う
            // (nOAuth 対策の継ぎ目)。宣言は config('template.social_providers.*.email_trust') で、
            // 未宣言は Unconfirmed に倒れる (fail-closed)。google は confirmed 宣言のため
            // 従来どおり email_verified_at が立つ (挙動不変)。
            $verifiedAt = $this->emailTrust->for($provider)->trustsEmail($socialiteUser)
                ? now()
                : null;

            // SSO 登録は password を **持たない** (null のまま)。
            // users.password は nullable であり、password 経路の可否は User::hasPassword() が
            // fail-closed で判定する契約 (0001_01_01_000000_create_users_table.php)。
            // ランダム値 (旧 Str::password(32)) を入れると hasPassword() が常に true になり、
            // recent-auth の passwordSet と EnsureLoginMethodRemains の双方が形骸化する。
            // **前方修正のみ**: 既存 SSO ユーザーの phantom password は遡及是正しない
            // (password 登録後に SSO 連携したユーザーの実パスワード消失リスクのため)。
            // → docs/template-divergence.md D13。
            $user = (new User([
                'name' => $socialiteUser->getName() ?? $email,
                'email' => $email,
            ]))->forceFill([
                'terms_accepted_at' => now(),
                'consent_version' => LegalConsent::version(),
                'email_verified_at' => $verifiedAt,
            ]);
            $user->save();

            $this->link($provider, $socialiteUser, $user);

            $this->provisioning->provisionInitialOrganization($user);

            return $user;
        });
    }

    /**
     * 連携追加。既に他ユーザーに連携済みの場合は false を返す。
     *
     * **通知は本メソッドだけが行う** (`register()` 内部の初回連携では呼ばない)。
     * 新規 SSO 登録は「既存アカウントが新しい認証手段を獲得した」わけではなく、
     * 本人がその場で作ったばかりのアカウントに「連携しました」と知らせるのは
     * 一般的な慣行にも無い冗長な通知になるため (T110 概念設計「制約・前提」)。
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

        $this->notifier->notify($user, AuthMethodChangeEvent::SocialAccountLinked, $this->providerLabel($provider));

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

    /** config の label を使う。未宣言なら provider 識別子そのものを使う (fail-closed ではなく表示のみのため許容)。 */
    private function providerLabel(string $provider): string
    {
        $label = config("template.social_providers.{$provider}.label");

        return is_string($label) && $label !== '' ? $label : $provider;
    }
}
