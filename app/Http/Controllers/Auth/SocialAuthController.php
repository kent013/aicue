<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\User;
use App\Security\RecentAuthState;
use App\Services\Auth\SocialAccountService;
use App\Services\Onboarding\IntendedPlanResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirectResponse;
use Webmozart\Assert\Assert;

/**
 * SSO (Socialite) フロー。
 *
 * 開始導線は GET の anchor リンクであること (form POST にしない)。
 * form POST だと 302 リダイレクト先 (IdP) にも CSP form-action が適用され
 * ブロックされる (devnotes/20260611-template-extraction/14 §3)。
 *
 * intent (login / register / link / step-up) は session に保存し、callback で分岐する。
 * step-up は recent-auth (再認証) の SSO satisfier: ログイン済ユーザーが自分に連携済みの
 * provider で OAuth round-trip を完了すると RecentAuthState が鮮度を stamp する。
 */
class SocialAuthController extends Controller
{
    private const INTENTS = ['login', 'register', 'link', 'step-up'];

    public function __construct(
        private readonly IntendedPlanResolver $intendedPlanResolver,
    ) {}

    public function redirect(Request $request, string $provider, string $intent): RedirectResponse|SymfonyRedirectResponse
    {
        $this->ensureProviderEnabled($provider);
        abort_unless(in_array($intent, self::INTENTS, true), 404);

        if ($intent === 'link' || $intent === 'step-up') {
            abort_unless($request->user() !== null, 403);
        }

        // register のみ規約同意が必要 (query で受けて server 側でも検証)
        if ($intent === 'register' && $request->query('terms_accepted') !== '1') {
            return redirect()->route('register')
                ->withErrors(['terms_accepted' => '利用規約への同意が必要です。']);
        }

        // 料金表由来のプラン意図。register 開始では ?plan= を pending に書き換え (不在は forget)、
        // login 開始では常に forget する (前回中断の stale pending を次の登録へ持ち越さない)。
        // link / step-up は登録経路ではないため触らない。
        if ($intent === 'register') {
            $this->intendedPlanResolver->rememberPendingFromQuery($request);
        } elseif ($intent === 'login') {
            $this->intendedPlanResolver->forgetPending();
        }

        $request->session()->put('social_auth_intent', $intent);

        $driver = Socialite::driver($provider);

        // step-up は IdP に再認証を促す (OIDC 標準の prompt=login)。RP 側で auth_time は
        // 検証しない最小実装 (capability=fresh_auth_prompt_only)。対応しない provider では
        // 単に無視される。
        if ($intent === 'step-up' && method_exists($driver, 'with')) {
            $driver->with(['prompt' => 'login']);
        }

        return $driver->redirect();
    }

    public function callback(Request $request, string $provider, SocialAccountService $service, RecentAuthState $recentAuthState): RedirectResponse
    {
        $this->ensureProviderEnabled($provider);

        $intent = $request->session()->pull('social_auth_intent');
        if (! is_string($intent) || ! in_array($intent, self::INTENTS, true)) {
            return redirect()->route('login')->withErrors([
                'email' => 'ログインフローが無効です。もう一度お試しください。',
            ]);
        }

        $socialiteUser = Socialite::driver($provider)->user();

        if ($intent === 'step-up') {
            return $this->completeStepUp($request, $provider, $socialiteUser->getId(), $recentAuthState);
        }

        if ($intent === 'link') {
            $user = $request->user();
            Assert::isInstanceOf($user, User::class);
            $linked = $service->linkToUser($provider, $socialiteUser, $user);

            return $linked
                ? redirect()->route('settings.security')->with('success', 'アカウントを連携しました')
                : redirect()->route('settings.security')->withErrors([
                    'social' => 'このアカウントは既に別のユーザーに連携されています。',
                ]);
        }

        $linkedUser = $service->findLinkedUser($provider, $socialiteUser);

        if ($linkedUser !== null) {
            // login / register どちらの intent でも、連携済みならログイン扱い
            Auth::login($linkedUser, remember: true);
            $request->session()->regenerate();

            return redirect()->intended(route('dashboard'));
        }

        if ($intent === 'login') {
            // 未連携: 自動登録はしない (明示的な register 経由を要求)
            $this->intendedPlanResolver->forgetPending();

            return redirect()->route('login')->withErrors([
                'email' => 'このアカウントは登録されていません。新規登録からやり直してください。',
            ]);
        }

        // register: メール一致ユーザーへの自動リンクはしない (乗っ取り防止)。
        // 同一 email の既存ユーザーがいる場合は中立メッセージで弾く。
        $email = $socialiteUser->getEmail();
        if (is_string($email) && User::whereBlind('email', 'email_index', $email)->exists()) {
            // 登録拒否分岐: stale pending を残さない。
            $this->intendedPlanResolver->forgetPending();

            return redirect()->route('register')->withErrors([
                'email' => 'このメールアドレスではアカウントを作成できません。',
            ]);
        }

        $user = $service->register($provider, $socialiteUser);
        Auth::login($user, remember: true);
        $request->session()->regenerate();

        // pending → 個人組織へ移送 (pending は必ず forget で消費される)。
        // 個人組織が無い (= 招待経由等) 場合は promote 対象が存在しないため pending だけ落とす。
        $personalOrganization = $user->organizations()->where('is_personal', true)->first();
        if ($personalOrganization instanceof Organization) {
            $this->intendedPlanResolver->promotePendingToOrganization($personalOrganization);
        } else {
            $this->intendedPlanResolver->forgetPending();
        }

        return redirect()->route('dashboard');
    }

    /**
     * recent-auth の SSO satisfier (step-up intent)。
     *
     * 本人性バインド: callback の provider_user_id が **現在ログイン中ユーザーの**連携済み
     * SocialAccount と一致した場合のみ鮮度を stamp する (他人のアカウントでの
     * round-trip 完了を成立させない)。成立後は RequireRecentAuth が保持した intended へ戻す。
     */
    private function completeStepUp(Request $request, string $provider, mixed $providerUserId, RecentAuthState $recentAuthState): RedirectResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            // OAuth round-trip 中にセッションが切れた等。再ログインへ (fail-closed)。
            return redirect()->route('login');
        }

        $bound = $user->socialAccounts()
            ->where('provider', $provider)
            ->where('provider_user_id', $providerUserId)
            ->exists();

        if (! $bound) {
            return redirect()->route('recent-auth.confirm')->withErrors([
                'password' => '現在ログイン中のアカウントに連携されたソーシャルアカウントで再認証してください。',
            ]);
        }

        $recentAuthState->confirm(method: 'sso', provider: $provider);

        // 302 fallback 経路で mutation を破棄していた場合は再操作を促す
        // (RequireRecentAuth の one-shot flag。password satisfier と同じ契約)。
        $droppedMutation = $request->session()->pull('recent_auth.dropped_mutation') === true;

        $redirect = redirect()->intended(route('dashboard'));
        if ($droppedMutation) {
            $redirect->with('info', '再認証が完了しました。先ほどの操作はまだ実行されていません。お手数ですがもう一度操作してください。');
        }

        return $redirect;
    }

    private function ensureProviderEnabled(string $provider): void
    {
        abort_unless(
            array_key_exists($provider, config()->array('template.social_providers')),
            404,
        );
    }
}
