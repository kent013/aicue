<?php

declare(strict_types=1);

namespace App\Http\Controllers\Organizations;

use App\DataTransferObjects\Organizations\SsoConnectionSummary;
use App\Exceptions\EnterpriseSso\EnterpriseSsoAttemptRejectedException;
use App\Exceptions\EnterpriseSso\OidcConnectionTransitionException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organizations\StoreSsoConnectionRequest;
use App\Http\Requests\Organizations\UpdateSsoConnectionRequest;
use App\Models\Organization;
use App\Models\OrganizationOidcConnection;
use App\Services\EnterpriseSso\OidcConnectionTransitionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * 組織の企業 OIDC 接続の管理 (一覧・登録・更新・確認・有効化・無効化・削除)。
 *
 * ★**接続の秘密を扱う前面は登録・更新フォーム 1 本だけ**である (正典 v1 / I4)。
 *   画面は 1 枚 (一覧 + フォーム) で、秘密を扱う前面を 2 枚に割らない。
 *   一覧の生成は **秘密を一度も復号しない** ({@see SsoConnectionSummary} が
 *   `hasClientSecret` の bool しか持たない)。
 *
 * ★操作系はすべて `back()->with(...)` で完結させる (`redirect()->intended()` は
 *   ログイン直後フロー専用 = 禁止事項 7)。
 *
 * ★`{oidcConnection}` は `Organization::oidcConnections()` 経由で `scopeBindings()` が解決する。
 *   親に属さない id は **binding 段で 404** (認可より前。AGENTS.md 不変条件 2 / 10)。
 *
 * ★**`verify` だけはトランザクションの張り方が違う**。D1 の三段構成
 *   (ロックなしでスナップショット → ロックなしで外向き取得 → ロック下で再確認) を
 *   controller 側が壊さないよう、**外向き取得を包むトランザクションをここで張らない**。
 */
class OrganizationSsoConnectionController extends Controller
{
    public function __construct(
        private readonly OidcConnectionTransitionService $transitions,
    ) {}

    /**
     * 一覧 (閲覧も owner / admin に限る)。
     *
     * ★秘密を一度も復号しない。身元の有無は**まとめて数える** (N+1 を作らない)。
     */
    public function index(Organization $organization): Response
    {
        Gate::authorize('viewAny', [OrganizationOidcConnection::class, $organization]);

        $connections = $organization->oidcConnections()
            ->withCount('identities')
            ->orderBy('id')
            ->get()
            ->map(fn (OrganizationOidcConnection $connection): array => SsoConnectionSummary::fromModel(
                $connection,
                hasIdentities: ($connection->identities_count ?? 0) > 0,
            )->toArray())
            ->values()
            ->all();

        return Inertia::render('Organizations/Sso/Index', [
            'organization' => [
                'id' => $organization->id,
                'name' => $organization->name,
                'slug' => $organization->slug,
            ],
            'connections' => $connections,
            // 利用者に案内する戻り口の URL (IdP 側へ登録してもらう値)。
            'callbackUrl' => route('enterprise-sso.callback'),
        ]);
    }

    /** 登録 (常に `Draft` から始まる)。 */
    public function store(StoreSsoConnectionRequest $request, Organization $organization): RedirectResponse
    {
        Gate::authorize('create', [OrganizationOidcConnection::class, $organization]);

        $this->transitions->create(
            $organization,
            $request->loginSlugValue(),
            $request->displayNameValue(),
            $request->issuerValue(),
            $request->clientIdValue(),
            $request->clientSecretValue(),
        );

        return back()->with('success', '接続を登録しました。「確認」を押して接続先情報を取得してください。');
    }

    /** 更新 (認証材料を変えたら必ず `Draft` へ戻る)。 */
    public function update(
        UpdateSsoConnectionRequest $request,
        Organization $organization,
        OrganizationOidcConnection $oidcConnection,
    ): RedirectResponse {
        Gate::authorize('update', $oidcConnection);

        try {
            $this->transitions->update(
                $organization,
                $oidcConnection->id,
                $request->displayNameValue(),
                $request->issuerValue(),
                $request->clientIdValue(),
                $request->clientSecretValue(),
            );
        } catch (OidcConnectionTransitionException $e) {
            // ★押下時にエラーを表示する (ボタンを disabled にしない = 禁止事項 8)。
            return back()->withErrors(['sso_connection' => $e->rejection->message()]);
        }

        return back()->with('success', '接続を更新しました。');
    }

    /**
     * 確認 (接続先情報を実際に取りに行く)。
     *
     * ★**外向きの取得を伴う唯一の管理操作**なので専用の流量制限を持つ。
     * ★結果は**一様にしない** — 認可を通った運営操作なので理由を具体的に伝える。
     */
    public function verify(Organization $organization, OrganizationOidcConnection $oidcConnection): RedirectResponse
    {
        Gate::authorize('update', $oidcConnection);

        try {
            $outcome = $this->transitions->verify($organization, $oidcConnection);
        } catch (EnterpriseSsoAttemptRejectedException $e) {
            // ★取得の失敗で接続の状態は変わらない (可用性の後退を作らない)。
            //   理由コードは画面へ出さない (外部由来の情報を運営画面へ持ち込まない)。
            return back()->withErrors([
                'sso_connection' => '接続先情報を取得できませんでした。発行者 URL と IdP 側の設定を確認してください。',
            ]);
        } catch (OidcConnectionTransitionException $e) {
            return back()->withErrors(['sso_connection' => $e->rejection->message()]);
        }

        if (! $outcome->succeeded()) {
            return back()->withErrors(['sso_connection' => $outcome->message()]);
        }

        return back()->with('success', $outcome->message());
    }

    /** 有効化 (ここから企業ログインが使えるようになる)。 */
    public function activate(Organization $organization, OrganizationOidcConnection $oidcConnection): RedirectResponse
    {
        Gate::authorize('update', $oidcConnection);

        try {
            $this->transitions->activate($organization, $oidcConnection->id);
        } catch (OidcConnectionTransitionException $e) {
            return back()->withErrors(['sso_connection' => $e->rejection->message()]);
        }

        return back()->with('success', '接続を有効にしました。');
    }

    /** 無効化 (運用の推奨経路。**身元は残る**ので再び有効にすれば同じ利用者へ戻る)。 */
    public function disable(Organization $organization, OrganizationOidcConnection $oidcConnection): RedirectResponse
    {
        Gate::authorize('update', $oidcConnection);

        try {
            $this->transitions->disable($organization, $oidcConnection->id);
        } catch (OidcConnectionTransitionException $e) {
            return back()->withErrors(['sso_connection' => $e->rejection->message()]);
        }

        return back()->with('success', '接続を無効にしました。次回以降この IdP ではログインできません。');
    }

    /** 削除 (★**身元が 1 件でもあれば拒否**。運用は無効化で行う)。 */
    public function destroy(Organization $organization, OrganizationOidcConnection $oidcConnection): RedirectResponse
    {
        Gate::authorize('delete', $oidcConnection);

        try {
            $this->transitions->destroy($organization, $oidcConnection->id);
        } catch (OidcConnectionTransitionException $e) {
            return back()->withErrors(['sso_connection' => $e->rejection->message()]);
        }

        return back()->with('success', '接続を削除しました。');
    }
}
