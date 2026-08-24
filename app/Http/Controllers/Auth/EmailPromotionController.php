<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Exceptions\Auth\EmailPromotionConflictException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ConfirmEmailPromotionRequest;
use App\Http\Requests\Auth\StoreEmailPromotionRequest;
use App\Models\User;
use App\Services\Auth\EmailPromotionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Webmozart\Assert\Assert;

/**
 * 企業 SSO でしか入れない利用者が、自分で使えるメールアドレスを持つための昇格。
 *
 * ★**認可は「自分の資源」である**。Gate を通さず `Auth::id()` (= `$request->user()`) だけを使う
 *   (`ControllerAuthorizationGateTest` の exemption へ理由付きで登録する)。
 * ★確認は **GET の画面 + POST の確定**に割る。署名付き GET のリンクだけだと、
 *   メールクライアントの先読みやプレビューで**利用者が意図せず確定してしまう**。
 * ★確認画面は **standalone Blade** である (`Inertia::render` を呼ばない)。
 *   Inertia は page object を `history.state` へ載せるため、prop へ置いた瞬間に
 *   **トークンがブラウザの履歴に残る**。
 * ★失敗しても `withInput()` を使わない (トークンを old input に残さない)。
 *
 * ## 保証しないもの (誇張しない)
 *
 * リバースプロキシや CDN のアクセスログ、ブラウザの履歴、利用者が URL を他人へ貼ることに
 * よる露出は防げない。緩和は **60 分の期限**と **一回だけの consume** であり、
 * 露出しても**使われる窓が短く、1 回しか効かない**ことに寄せている。
 */
class EmailPromotionController extends Controller
{
    /** 確定・失敗のどちらでも同じ行き先 (存在を漏らさない)。 */
    private const string SETTINGS_ROUTE = ConfirmEmailPromotionRequest::FAILURE_ROUTE;

    public function __construct(private readonly EmailPromotionService $promotions) {}

    /** 発行 (確認メールを送る)。 */
    public function store(StoreEmailPromotionRequest $request): RedirectResponse
    {
        return $this->issue($request, '確認メールを送信しました。メール内のリンクから登録を完了してください。');
    }

    /** 再送 (**発行と同じ入口**。旧トークンは失効する)。 */
    public function resend(StoreEmailPromotionRequest $request): RedirectResponse
    {
        return $this->issue($request, '確認メールを再送しました。');
    }

    /**
     * 発行・再送の共通の入口。
     *
     * ★**既にメールを持つ利用者は対象外**である (押下時にエラーを表示する = 禁止事項 8)。
     *   既存のメール変更経路 (監査 + 旧アドレスへの通知つき) を迂回させない。
     */
    private function issue(StoreEmailPromotionRequest $request, string $success): RedirectResponse
    {
        if (! $this->promotions->issue($this->currentUser($request), $request->emailValue())) {
            return back()->withErrors([
                ConfirmEmailPromotionRequest::ERROR_KEY => 'この操作はメールアドレスをまだ登録していない場合にのみ使えます。'
                    .'変更はプロフィール設定から行ってください。',
            ]);
        }

        return back()->with('success', $success);
    }

    /**
     * 確認画面 (GET)。
     *
     * ★**状態を変えない**。トークンを画面へ渡し、利用者が明示のボタンで POST する。
     * ★**トークンの有効・無効で画面を変えない** (一様。存在の探り当てを作らない)。
     */
    public function showConfirm(Request $request): Response
    {
        return response()->view('auth.email-promotion.confirm', [
            'token' => $request->string('token')->value(),
        ]);
    }

    /** 確定 (POST のみ)。 */
    public function confirm(ConfirmEmailPromotionRequest $request): RedirectResponse
    {
        try {
            $confirmed = $this->promotions->confirm($this->currentUser($request), $request->tokenValue());
        } catch (EmailPromotionConflictException) {
            // ★衝突の応答は**一様**である (既存利用者の存在を漏らさない)。
            //   既存利用者は一切変更せず・併合せず・昇格も行わない。
            return redirect()->route(self::SETTINGS_ROUTE)->withErrors([
                ConfirmEmailPromotionRequest::ERROR_KEY => ConfirmEmailPromotionRequest::FAILURE_MESSAGE,
            ]);
        }

        if (! $confirmed) {
            // ★validation の失敗と**同じ**応答である (どちらで落ちたかを外から区別できない)。
            return redirect()->route(self::SETTINGS_ROUTE)->withErrors([
                ConfirmEmailPromotionRequest::ERROR_KEY => ConfirmEmailPromotionRequest::FAILURE_MESSAGE,
            ]);
        }

        return redirect()->route(self::SETTINGS_ROUTE)
            ->with('success', 'メールアドレスを登録しました。');
    }

    private function currentUser(Request $request): User
    {
        $user = $request->user();
        Assert::isInstanceOf($user, User::class);

        return $user;
    }
}
