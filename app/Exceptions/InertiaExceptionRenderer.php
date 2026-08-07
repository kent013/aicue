<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\DataTransferObjects\Http\ErrorScreenData;
use App\Enums\Http\InertiaErrorScreenPassthrough;
use App\Enums\Http\InertiaErrorScreenStatus;
use App\Http\Middleware\HandleInertiaRequests;
use App\Support\Http\AdminPanelPath;
use App\Support\Http\ErrorScreenCachePolicy;
use App\Support\Http\ErrorScreenDestinations;
use App\Support\Http\RetryAfterSeconds;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Support\Header;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Inertia XHR (X-Inertia 付き) の 4xx/5xx を **Error 画面 (Inertia ページ)** へ差し替える。
 *
 * これが無いと @inertiajs/core は x-inertia ヘッダの無い応答を
 * handleNonInertiaResponse() → dialog_default.show() = エラーモーダルに流し込み、
 * 利用者は SPA から出られなくなる (URL も履歴も動かないため戻り先が無い)。
 *
 * ApiExceptionRenderer と対になる位置づけ (api/* は封筒 JSON、Inertia は Error 画面)。
 * **bootstrap/app.php に直書きしない**理由は 2 つ:
 *   1. tests/Architecture/InertiaRenderPageExistsInvariantTest の走査対象が app/ と routes/ だけで、
 *      bootstrap/ に Inertia::render を書くと「ページ実在」gate が効かない
 *   2. Controller (と例外ハンドラ) は薄く保つ (AGENTS.md 実装規約)
 *
 * **deny-by-default**: 差し替えるのは passthroughReason() が null を返す応答だけ。
 */
final class InertiaExceptionRenderer
{
    /**
     * 差し替え**しない**理由。null なら差し替えてよい。
     *
     * 判定順は「壊してはいけないものから」。呼び出し側 (bootstrap) の早期 return と
     * 重複する条件も**あえて再掲する** (この関数単体で安全側に閉じる = 呼び出し位置に依存しない)。
     *
     * ★expectsJson() を X-Inertia より**先**に見るのは意図的である。
     *   実ブラウザの Inertia client (@inertiajs/core 3.3.1) は
     *   `Accept: text/html, application/xhtml+xml` を送るため expectsJson() は偽になり、
     *   通常の SPA 遷移が誤って素通しになることはない
     *   (expectsJson は ajax()+acceptsAnyContentType または wantsJson。どちらも成立しない)。
     *   一方 `X-Inertia` を付けつつ `Accept: application/json` を送るクライアントは
     *   「JSON を期待している」と明言しているのだから、画面 HTML ではなく JSON を返すのが正しい。
     */
    public static function passthroughReason(Response $response, Request $request): ?InertiaErrorScreenPassthrough
    {
        $status = $response->getStatusCode();

        if ($status < 400) {
            return InertiaErrorScreenPassthrough::SuccessOrRedirectStatus;
        }

        if ($request->is('api/*') || $request->expectsJson()) {
            return InertiaErrorScreenPassthrough::MachineReadableEnvelope;
        }

        $adminPath = AdminPanelPath::resolve();
        if ($request->is($adminPath) || $request->is($adminPath.'/*')) {
            return InertiaErrorScreenPassthrough::OperatorFacingSurface;
        }

        if ($request->header(Header::INERTIA) === null) {
            return InertiaErrorScreenPassthrough::NonInertiaRequest;
        }

        if (! self::assetVersionMatches($request)) {
            return InertiaErrorScreenPassthrough::StaleAssetVersion;
        }

        if ($response->headers->has('Location') || $response->headers->has(Header::LOCATION)) {
            return InertiaErrorScreenPassthrough::InertiaProtocolRedirect;
        }

        $screenStatus = InertiaErrorScreenStatus::tryFrom($status);
        if ($screenStatus === null) {
            return InertiaErrorScreenPassthrough::UnlistedStatus;
        }

        if ($screenStatus->isServerError() && config('app.debug') === true) {
            return InertiaErrorScreenPassthrough::DebugServerError;
        }

        return null;
    }

    /**
     * 差し替え後の応答。差し替えない場合と、生成に失敗した場合は null
     * (呼び出し側が原応答をそのまま返す = 今日の挙動より悪くならない)。
     */
    public static function render(Response $response, Request $request): ?Response
    {
        try {
            if (self::passthroughReason($response, $request) !== null) {
                return null;
            }

            $status = InertiaErrorScreenStatus::from($response->getStatusCode());

            $retryAfterSeconds = $status->showsRetryAfter()
                ? RetryAfterSeconds::parse($response->headers->get('Retry-After'))
                : null;

            // ★419 では認証状態を**評価しない**。PHP は引数を呼び出し前に評価するため、
            //   ErrorScreenDestinations::for($status, $request->user() !== null) と書くと
            //   D1 (419 は認証状態を問わない) が真でも user resolver が走る。
            //   セッションが壊れている 419 で resolver が throw すると、
            //   本来最も救いたい画面が report() + Blade fallback に落ちてしまう。
            $authenticated = $status->forcesGuestDestinations()
                ? false
                : $request->user() !== null;

            $data = new ErrorScreenData(
                status: $status,
                retryAfterSeconds: $retryAfterSeconds,
                destinations: ErrorScreenDestinations::for($status, $authenticated),
            );

            $rendered = Inertia::render('Error', $data->toInertiaProps())
                ->toResponse($request)
                ->setStatusCode($status->value);

            // ヘッダ移植は allowlist (deny-by-default)。原値をそのまま写すのではなく、
            // RetryAfterSeconds が解釈できた値だけを正規化して再設定する
            // (本文 / API details / HTTP ヘッダの三者が同じ SoT を通る)。
            if ($retryAfterSeconds !== null) {
                $rendered->headers->set('Retry-After', (string) $retryAfterSeconds);
            }

            // キャッシュ表現の契約 (Vary + no-store + private)。
            // **原応答ではなく生成した応答**に適用する (原応答に適用しても契約は成立しない)。
            ErrorScreenCachePolicy::apply($rendered);

            return $rendered;
        } catch (Throwable $e) {
            // version 解決 (manifest 読み) / route 解決 / props 生成 / toResponse の
            // **どの段で失敗しても**原応答 (自己完結 Blade) を残す。
            //
            // ★ただし黙って握り潰さない。ここが恒常的に失敗すると
            //   「Error 画面が一度も出ないまま Blade に落ち続ける」= 改善が死んでいるのに
            //   誰も気づかない状態になる。利用者への応答は原応答へ戻しつつ、
            //   運用には report() で必ず届ける。
            report($e);

            return null;
        }
    }

    /**
     * リクエストの asset version が現在の build と一致するか (配備境界)。
     *
     * 一致 = そのタブは現在の build から asset を読み込んでいる
     *      = その bundle に resources/js/pages/Error.svelte が含まれている。
     * 不一致のタブへ component 'Error' を返すと resolvePage() が throw して SPA が無反応になる
     * (= 今日のモーダル表示より悪化する)。
     *
     * ★**両辺が非空文字列のときだけ一致とみなす** (null === null を「同じ build」と読まない)。
     * ★version の取得元は HandleInertiaRequests::version()。Inertia::getVersion() は
     *   同 middleware の handle() が走った後でないと空文字になり、テナント guard 404 のように
     *   middleware より前で例外が出る経路で誤って不一致になる。
     */
    private static function assetVersionMatches(Request $request): bool
    {
        $requested = $request->header(Header::VERSION);
        if (! is_string($requested) || $requested === '') {
            return false;
        }

        $current = app(HandleInertiaRequests::class)->version($request);

        return is_string($current) && $current !== '' && $current === $requested;
    }
}
