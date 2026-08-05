<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\DataTransferObjects\Auth\LoginMethodRemoval;
use App\DataTransferObjects\Auth\LoginMethodRequiredDto;
use App\Http\Resources\Auth\LoginMethodRequiredResource;
use App\Models\Passkey;
use App\Models\User;
use App\Services\Auth\LoginMethodInventory;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

/**
 * ログイン手段を減らす操作の前に「実行後も最低 1 つ手段が残る」ことを保証する関門。
 * alias: `ensure-login-method`。
 *
 * **評価するのは現在状態ではなく「操作が成功した後の投影状態」**。
 * 素朴に現在を数えると削除対象自身が残存手段として数えられ、
 * 「唯一の passkey を削除できてしまう」= 意図と正反対の挙動になる。
 *
 * **直列化規約 (TOCTOU 対策)**:
 *   投影が正しくても、確認と削除が別トランザクションなら破れる
 *   (passkey 2 件のユーザーが別々の passkey を同時削除 → 両方が「もう片方が残る」と判定 → 0 件)。
 *   そこで本 middleware が
 *     (1) DB::transaction() を開き
 *     (2) 対象 User 行を lockForUpdate() で取得し
 *     (3) **ロック取得後に** 投影を評価し
 *     (4) **同一トランザクション内で $next() を実行**して vendor の削除まで完了させる。
 *   ロック取得順序は User → credential に固定する。
 *   本アプリのドメイン固有規約 1「シナリオ整合の共有ロック規約」と同型の作法。
 *
 * **単一の直列化点であること**が不変条件であり、
 * tests/Architecture/LoginMethodRemovalRouteTest が deny-by-default で強制する
 * (付与漏れだけでなく **allowlist 外 route への付与**も fail させる)。
 *
 * ⚠ **適用条件 (この middleware を新しい route に付ける前に必ず読むこと)**:
 *   `$next()` を transaction 内で実行するため、controller だけでなく
 *   **同期 event listener / Responsable 変換 / redirect + flash** まで transaction に入る。
 *   したがって次を含む route には付けてはならない:
 *     - streamed / downloadable response (transaction を長時間保持する)
 *     - 外部 I/O (HTTP・S3 等。ロック保持中に外部レイテンシを持ち込む)
 *     - `afterCommit` でない queue dispatch (ロールバック時に job だけ残る)
 *   これらが必要な route を保護する場合は、本 middleware の transaction 方式を
 *   「Service 内 transaction + 判定の再評価」へ再設計すること。
 */
final class EnsureLoginMethodRemains
{
    public function __construct(
        private readonly LoginMethodInventory $inventory,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return $this->pass($next, $request);   // 未認証は auth middleware の責務
        }

        return DB::transaction(function () use ($request, $next, $user): Response {
            // (2) 対象 User 行をロック (以降の投影評価はこのロック下でのみ有効)
            $locked = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

            // (3) ロック取得後に投影を評価する
            $remaining = $this->inventory->remainingAfter($locked, $this->removalFor($request, $locked));

            if ($remaining->isEmpty()) {
                return $this->reject($request);
            }

            // (4) 同一トランザクション内で削除まで完了させる
            return $this->pass($next, $request);
        });
    }

    /**
     * route から「今から何を除去しようとしているか」を決める。
     *
     * 対象 passkey が当該 User に属することは **binder が 404 で確定済み**
     * (App\Http\Routing\SelfScopedPasskeyBinder)。DTO 側でも二重に assert する。
     */
    private function removalFor(Request $request, User $user): LoginMethodRemoval
    {
        $passkey = $request->route('passkey');
        if ($passkey instanceof Passkey) {
            return LoginMethodRemoval::passkey($passkey, $user);
        }

        // 将来の除去 route (password 削除 / SSO 解除) はここに分岐を足す。
        // 未知の除去 route を素通しさせないため fail-closed で落とす
        // (LoginMethodRemovalRouteTest が「middleware を付けたのに分岐が無い」を先に検出する)。
        throw new LogicException(
            'EnsureLoginMethodRemains: 除去対象を決定できない route です。removalFor() に分岐を追加してください。',
        );
    }

    /**
     * 拒否応答。
     *
     * **Inertia には 422 JSON を返さない** (Inertia protocol 違反になり、
     * router が応答を解釈できず無言失敗する)。Inertia は 302 + errors を native に
     * 処理するため `back()->withErrors()` にして Svelte 側は `$page.props.errors` で読む。
     * 禁止事項 7 (操作系 POST は `back()->with(...)` で完結) とも整合する。
     *
     * 判別子に `expectsJson()` を使えるのは、Inertia が
     * `Accept: text/html, application/xhtml+xml` を送るため (X-Inertia は立つが Accept は HTML)。
     * 純粋な XHR (fetch + Accept: application/json) のみ 422 JSON になる。
     */
    private function reject(Request $request): Response
    {
        // settingsUrl は持たせない (削除済み)。理由:
        // - Inertia 経路は back()->withErrors() で message しか運ばず、URL はどのクライアントも消費していない
        // - 指していた settings.security にはパスワード設定 UI が無く、フロントの遷移先 (/settings) とも
        //   食い違っていた (phantom 契約)。踏破可能な CTA は画面側 (PasskeySection → /settings) が持つ
        $dto = new LoginMethodRequiredDto(
            message: 'この操作を行うと、ログインする手段がなくなります。先に別のログイン手段（パスワードの設定、ソーシャル連携、他のパスキー）を追加してください。',
        );

        if ($request->expectsJson()) {
            return LoginMethodRequiredResource::make($dto)
                ->response()
                ->setStatusCode(422)
                ->withHeaders(['Cache-Control' => 'no-store']);
        }

        return back()->withErrors(['login_method' => $dto->message]);
    }

    /**
     * @param  Closure(Request): mixed  $next
     */
    private function pass(Closure $next, Request $request): Response
    {
        $response = $next($request);
        if (! $response instanceof Response) {
            throw new LogicException('Expected Symfony Response from middleware $next, got '.get_debug_type($response));
        }

        return $response;
    }
}
