<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\DataTransferObjects\Auth\TwoFactorRequiredDto;
use App\Enums\TwoFactorStatus;
use App\Http\Resources\Auth\TwoFactorRequiredResource;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

/**
 * 「2FA 必須」組織に所属する未準拠ユーザーのアカウント全体ゲート。
 *
 * 契約: 1 つでも two_factor_required な組織に所属する 2FA 未完了 (disabled/pending)
 * ユーザーは ALLOWED_ROUTE_NAMES 以外の web 経路すべてから 2FA 設定ページ
 * (settings.security) へ 302 (XHR は 409 + {code, message, redirect}) される。
 * 組織スコープの部分制限は採らない (2FA はアカウント全体の属性のため)。
 *
 * 評価コスト: 準拠 (enabled) ユーザーは attribute 判定のみで追加クエリゼロ。未準拠
 * ユーザーのみ所属組織の 1 クエリ (flash 用に組織名が要るため first)。
 *
 * web group append (= StartSession 後)。auth は route middleware だが session guard は
 * lazy 解決のため $request->user() はここで利用可能。未認証は素通し (login 等は対象外)。
 */
final class RequireTwoFactorForEnforcedOrganizations
{
    /**
     * ゲート中でも到達可能な route name => 必要理由。
     * この表が正であり、(a) 全 name の実在 + 理由非空 (TwoFactorEnforcementAllowlistTest)、
     * (b) ゲート中の到達可能性 (TwoFactorEnforcementTest dataset) を同表から検証する。
     * two-factor.disable は意図的に含めない (ゲート解除手段にならず、pending 巻き戻しの
     * 濫用面になる。self-disable は BlockTwoFactorDisableForEnforcedOrganizations も参照)。
     *
     * @var array<string, string>
     */
    public const ALLOWED_ROUTE_NAMES = [
        'settings.security' => '準拠達成の入口 (2FA 設定ページ)',
        'settings' => '設定 index (2FA 設定ページへの導線)',
        'two-factor.enable' => 'enrollment 開始 (POST /user/two-factor-authentication)',
        'two-factor.confirm' => 'TOTP 確認 = 準拠達成 (POST /user/confirmed-two-factor-authentication)',
        'two-factor.qr-code' => 'QR 表示 (設定ページの fetch)',
        'two-factor.secret-key' => '手動入力キー表示 (設定ページの fetch)',
        'two-factor.recovery-codes' => 'リカバリコード表示 (設定完了直後の保存)',
        'two-factor.regenerate-recovery-codes' => 'リカバリコード再生成',
        // 応答は { authenticated: bool } のみ (PII も操作も含まない) ため、ゲート中に
        // 200 を返しても情報露出にならない。逆に遮断すると bfcache 復元後の guard が
        // 「プローブ失敗」に倒れ、秘匿が解除できないまま再試行ループになる
        'session.status' => 'bfcache 復元時のセッション有効性プローブ (秘匿解除の唯一の判定源)',
        'recent-auth.confirm' => '機微操作前の step-up 画面 (2FA 設定動線が要求し得る)',
        'recent-auth.status' => 'step-up 状態の確認 (XHR precheck)',
        'recent-auth.password' => 'password による step-up 完了',
        // passkey による step-up (T124)。2FA 必須ゲート下の未準拠ユーザーは enrollment
        // (two-factor.enable / qr-code / secret-key) に step-up を要求されるため、
        // satisfier を password と再SSO だけに絞ると **passkey-only ユーザー**
        // (password 未設定・SSO 未連携) が enrollment の入口で手段ゼロになり詰む。
        // これらは satisfier 側であり、通すこと自体は 2FA ゲートの解除にならない
        // (準拠判定は two_factor_confirmed_at のみが決める)。
        'passkey.confirm-options' => 'passkey による step-up の challenge 発行',
        'passkey.confirm' => 'passkey による step-up 完了',
        // {intent} は login/register/link/step-up 共用だが、認証済みユーザーの主用途は
        // step-up (SSO-only ユーザーの再認証)。link を許してもゲート解除にはならない
        'social.redirect' => 'SSO step-up の開始 (SSO-only ユーザーの再認証)',
        'social.callback' => 'SSO step-up の callback',
        'logout' => '離脱は常に可能',
        // 退会予約の**取消**は「業務の利用」ではなく**誤操作の救済**である (bug-hunt F-4-01)。
        // 凍結側 (AccountDeletionFreezeAllowance::DeletionRequestDestroy) は同じ問いに
        // 「凍結中に必ず実行できなければ猶予期間を設けた意味が消える」と結論しており、
        // 実行順が先の本ゲートだけがそれを覆していた (priority list で本ゲートが凍結より前)。
        // 通しても (a) 業務面には到達できないまま (b) 認証手段は増減しない
        // (c) 準拠判定は two_factor_confirmed_at のみが決める、ため 2FA 必須の効力は変わらない。
        // 逆に塞ぐと deletion_purge_after (絶対時刻) が走り続け、本ゲートが
        // **不可逆な物理削除の後押し**になる (「使えない」を超えて「消える」に化ける)。
        // ★**予約 (…deletion-request.store) と即時削除 (settings.account.destroy) は入れない**
        //   — どちらも救済ではなく、遮断されても失われるのは意思表示だけである。
        'settings.account.deletion-request.destroy' => '退会予約の取消 (誤操作救済。凍結 allowlist と判断を揃える)',
        'verification.notice' => 'verified middleware との redirect 競合回避',
        'verification.verify' => 'メール検証リンクの踏破',
        'verification.send' => '検証メール再送',
    ];

    /**
     * 遮断されたのが**書き込み要求**だったときに文頭へ付ける固定文。
     *
     * 本 middleware は controller より前で短絡するため、この主張は構造的に真である
     * (= 対象 controller に到達しておらず、ドメイン状態は変化していない)。
     * ★**「副作用が一切ない」ことは主張しない** — session 書き込み・throttle 記録・
     *   CSRF 検証はこの短絡でも起こりうる。ここで言う「操作」は controller が行う業務処理を指す。
     * ★route 名 → 日本語の操作名の写像表は**持たない** (二重管理になり route 追加のたびに腐る)。
     *   伝えるのは「起きなかった」ことだけで、「何をしようとしたか」は伝えない。
     */
    public const string BLOCKED_WRITE_PREFIX = '直前の操作は実行されていません。';

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user instanceof User || $user->twoFactorStatus() === TwoFactorStatus::Enabled) {
            return $this->proceed($request, $next);
        }

        $routeName = $request->route()?->getName();
        if ($routeName !== null && array_key_exists($routeName, self::ALLOWED_ROUTE_NAMES)) {
            return $this->proceed($request, $next);
        }

        // ここに到達するのは未準拠 (disabled/pending) ユーザーのみ。
        // 状態非依存の単一述語 firstTwoFactorRequiringOrganization() で必須組織を引く
        $enforcingOrganization = $user->firstTwoFactorRequiringOrganization();
        if ($enforcingOrganization === null) {
            return $this->proceed($request, $next);
        }

        $message = "組織「{$enforcingOrganization->name}」は 2 段階認証を必須としています。設定が完了するまで他のページはご利用いただけません。";

        // 安全メソッド (GET/HEAD/OPTIONS/TRACE) は「ページに来られない」だけなので既存文言のまま。
        // 非安全メソッドはユーザーがボタンを押した結果なので、**何が起きなかったか**を先に言う
        // (F-4-01: 押した結果が分からないまま予約が生き残る、が High の理由)。
        if (! $request->isMethodSafe()) {
            $message = self::BLOCKED_WRITE_PREFIX.$message;
        }

        // XHR/JSON は RequireRecentAuth と同形の 409 + { code, message, redirect } (no-store)。
        // SPA の非画面 fetch に HTML リダイレクトを返さない
        if ($request->expectsJson()) {
            return TwoFactorRequiredResource::make(new TwoFactorRequiredDto(
                message: $message,
                redirect: route('settings.security'),
            ))
                ->response()
                ->setStatusCode(409)
                ->withHeaders(['Cache-Control' => 'no-store']);
        }

        return redirect()
            ->route('settings.security')
            ->with('info', $message);
    }

    /** @param  Closure(Request): mixed  $next */
    private function proceed(Request $request, Closure $next): Response
    {
        $response = $next($request);
        if (! $response instanceof Response) {
            throw new LogicException('Expected Symfony Response from middleware $next, got '.get_debug_type($response));
        }

        return $response;
    }
}
