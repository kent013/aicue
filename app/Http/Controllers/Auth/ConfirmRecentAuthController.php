<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\DataTransferObjects\Auth\RecentAuthProviderDto;
use App\DataTransferObjects\Auth\RecentAuthStatusDto;
use App\Enums\ProviderCapability;
use App\Http\Controllers\Controller;
use App\Http\Resources\Auth\RecentAuthStatusResource;
use App\Models\User;
use App\Security\RecentAuthState;
use App\Security\RecentAuthWindow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Laravel\Fortify\Features;
use Webmozart\Assert\Assert;

/**
 * generic recent-auth (step-up) の confirm 画面と password satisfier。
 *
 * satisfier:
 *   - password 再入力 (`confirmPassword`、XHR=204 / Inertia=intended redirect)。
 *     SSO-only (password 未設定) は **fail-closed** で拒否。
 *   - 再SSO は SocialAuthController の step-up intent (`/auth/{provider}/redirect/step-up`)。
 *     成立時の鮮度更新はそちらで RecentAuthState 経由で行う。
 *   - パスキー検証 (`POST /passkeys/confirm`)。成立時の鮮度更新は
 *     StampRecentAuthOnPasskeyVerified が行う。**passkey しか持たないユーザーを
 *     この画面で詰ませない**ため、passkeyAvailable は canSatisfy に算入する。
 *
 * `status` はクライアント主導モーダル (precheck) の UI 補助。最終 gate は RequireRecentAuth。
 */
final class ConfirmRecentAuthController extends Controller
{
    public function __construct(
        private readonly RecentAuthState $recentAuthState,
    ) {}

    /**
     * 鮮度切れ時の 302 フォールバック確認画面 (直接遷移・非 XHR 用)。
     */
    public function show(Request $request): InertiaResponse
    {
        $status = $this->buildStatus($this->currentUser($request));

        return Inertia::render('Auth/ConfirmRecentAuth', [
            'passwordSet' => $status->passwordSet,
            'availableProviders' => array_map(
                static fn (RecentAuthProviderDto $p): array => [
                    'provider' => $p->provider,
                    'capability' => $p->capability->value,
                    'reauthUrl' => $p->reauthUrl,
                ],
                $status->availableProviders,
            ),
            'passkeyAvailable' => $status->passkeyAvailable,
            'canSatisfy' => $status->canSatisfy,
        ]);
    }

    /**
     * クライアント主導モーダルの precheck。no-store。
     */
    public function status(Request $request): JsonResponse
    {
        $status = $this->buildStatus($this->currentUser($request));

        return RecentAuthStatusResource::make($status)
            ->response()
            ->withHeaders(['Cache-Control' => 'no-store, private']);
    }

    /**
     * password 再入力 satisfier。
     *
     * レスポンス契約:
     *   - Inertia リクエスト (standalone confirm 画面の form.post、X-Inertia あり)
     *     → `redirect()->intended(dashboard)`。RequireRecentAuth が保持した元 URL へ戻す。
     *   - 非 Inertia XHR (インラインモーダルの fetch、X-Inertia なし) → 204 No Content。
     *     クライアントはモーダルを閉じて pending action を再実行する。
     */
    public function confirmPassword(Request $request): Response|RedirectResponse
    {
        $user = $this->currentUser($request);

        $request->validate([
            'password' => ['required', 'string'],
        ]);

        // fail-closed: password 未設定 (SSO-only) は password 経路で step-up できない。
        if (! $user->hasPassword()) {
            throw ValidationException::withMessages([
                'password' => 'このアカウントはパスワードが設定されていません。SSO で再認証してください。',
            ]);
        }

        $passwordHash = $user->password;
        Assert::string($passwordHash); // hasPassword() true ⇒ 非 null string。PHPStan narrowing。

        $password = $request->string('password')->value();
        if (! Hash::check($password, $passwordHash)) {
            throw ValidationException::withMessages([
                'password' => 'パスワードが正しくありません。',
            ]);
        }

        $this->recentAuthState->confirm(method: 'password');

        // 302 fallback 経路で mutation を破棄していた場合 (RequireRecentAuth の one-shot flag)、
        // intended へ戻した画面で再操作を促す (サイレント喪失の防止)。204 経路 (インライン
        // モーダル) はクライアントが pending action を自前で再開するため読み捨てる
        // (両経路で必ず消費し、次回 step-up に持ち越さない)。
        // 注: RecentAuthState::confirm() の session migrate はデータ保持のため flag/intended は
        // 失われない。
        $droppedMutation = $request->session()->pull('recent_auth.dropped_mutation') === true;

        // standalone 画面 (Inertia) は 204 を処理できず詰むため、intended (RequireRecentAuth が
        // 保持した元 URL、無ければ dashboard) へ戻す。この分岐は Inertia protocol
        // (X-Inertia ヘッダ) のレスポンス契約用であり、Accept 等の他シグナルで判定しない。
        if ($request->hasHeader('X-Inertia')) {
            $redirect = redirect()->intended(route('dashboard'));
            if ($droppedMutation) {
                $redirect->with('info', '再認証が完了しました。先ほどの操作はまだ実行されていません。お手数ですがもう一度操作してください。');
            }

            return $redirect;
        }

        return response()->noContent()->withHeaders(['Cache-Control' => 'no-store, private']);
    }

    private function buildStatus(User $user): RecentAuthStatusDto
    {
        $passwordSet = $user->hasPassword();

        /** @var list<RecentAuthProviderDto> $providers */
        $providers = [];
        foreach ($user->socialAccounts()->pluck('provider') as $provider) {
            if (! is_string($provider)) {
                continue; // 想定外の型は候補にしない (fail-closed)
            }
            $capability = $this->capabilityFor($provider);
            // step-up satisfier 可能な provider のみ再SSO 候補に含める。
            if (! $capability->isStepUpSatisfier()) {
                continue;
            }
            $providers[] = new RecentAuthProviderDto(
                provider: $provider,
                capability: $capability,
                reauthUrl: route('social.redirect', ['provider' => $provider, 'intent' => 'step-up']),
            );
        }

        // パスキーは登録済みなら **TOTP の有無に関係なく** 再認証に使える
        // (PasskeyLoginPolicy が縛るのは login のみ)。feature off では route ごと消えるため
        // 手段として数えない (fail-closed)。
        $passkeyAvailable = Features::enabled(Features::passkeys()) && $user->passkeys()->exists();

        $canSatisfy = $passwordSet || $providers !== [] || $passkeyAvailable;

        $recentAuthAt = session()->get('recent_auth_at');
        $recent = RecentAuthWindow::isFresh($recentAuthAt);
        // 契約: recent===true ⇒ confirmedAt は int / recent===false ⇒ null (fail-closed)。
        // recent===true のとき isFresh() の前提から $recentAuthAt は必ず int。
        $confirmedAt = ($recent && is_int($recentAuthAt)) ? $recentAuthAt : null;

        return new RecentAuthStatusDto(
            recent: $recent,
            passwordSet: $passwordSet,
            availableProviders: $providers,
            passkeyAvailable: $passkeyAvailable,
            canSatisfy: $canSatisfy,
            confirmedAt: $confirmedAt,
        );
    }

    /**
     * provider の step-up capability を config (template.social_providers.{provider}.capability)
     * から解決する。未宣言・解釈不能は IdentityOnly (= satisfier 不可) に倒す (fail-closed)。
     */
    private function capabilityFor(string $provider): ProviderCapability
    {
        $raw = config('template.social_providers.'.$provider.'.capability');

        return (is_string($raw) ? ProviderCapability::tryFrom($raw) : null)
            ?? ProviderCapability::IdentityOnly;
    }

    private function currentUser(Request $request): User
    {
        $user = $request->user();
        Assert::isInstanceOf($user, User::class);

        return $user;
    }
}
