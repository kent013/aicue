<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\ItemController;
use App\Http\Controllers\Api\V1\Me\RevokeSessionController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\ProjectController;
use App\Http\Controllers\Api\V1\VersionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| REST API v1 Routes
|--------------------------------------------------------------------------
|
| `/api` prefix 配下 (bootstrap/app.php の withRouting)。認証は dual guard
| `auth:api-key,api-oauth` (組織スコープの API キー + OAuth user token。guard 順序は
| api-key 先 = 自動化トラフィックが先に解決)。middleware の順序契約:
|
|   auth:api-key,api-oauth → throttle:{bucket} → resolve.api-actor
|     → api-key.ability:{ability} → api.project-in-org → (idempotent) → controller
|
| api.project-in-org (EnsureProjectBelongsToApiOrganization) は URL 上の {project} が
| actor の組織に属さなければ 404 にする層 2a。**FormRequest より前**に走ることが本質で、
| これが無いと「cross-org の実在 project + 不正 payload = 422 / 不在 project = 404」の
| 差分が project の存在オラクルになる。順序契約は 2 点とも不可侵:
|   - resolve.api-actor **より後** ('organization' attribute が前提。前に置くと全 project
|     route が Assert 発火で 500)
|   - idempotent **より前** (cross-org リクエストで idempotency 行を作らせない)
| 網羅性と順序は tests/Architecture/ProjectRouteCurrentOrgGuardTest が機械固定する。
|
| resolve.api-actor が両経路を ApiActorContext (request attribute 'api_actor') に
| 正規化する (OAuth 経路の cli:use scope / session 束縛 / membership 再検証もここ)。
| throttle を ability の前に置くことで、ability 不足のリクエストも throttle
| カウントに入る (認証通過後のコスト系エンドポイントへの DoS 耐性)。
| rate limit バケットは 4 つ (AppServiceProvider 定義): api-read / api-write /
| api-status / api-mcp。新バケットの追加は要件に明示的根拠があるときだけ
| (docs/app-integration-guide.md §5)。
|
| guard 3 分類 (dual / oauth 単独 / public) は
| tests/Architecture/ApiGuardAllowlistInvariantTest が deny-by-default で固定する。
| route 名規約: `api.v1.{resource}.{action}`。パラメータ付き route は
| tests/Architecture/NestedRouteIdorDefenseTest の inventory に防御モードを登録する。
| binding param の型制約 (旧 ->whereNumber) は App\Http\Routing\RouteBindingTypes に集約
| (route 個別の where は書かない。18 桁上限で 22P02 / 22003 の両方を塞ぐ)。
| MCP エンドポイント (/api/v1/mcp) は routes/ai.php で登録される。
*/

// 公開 (未認証) エンドポイント
Route::prefix('v1')
    ->middleware(['throttle:api-status'])
    ->group(function (): void {
        Route::get('/version', [VersionController::class, 'show'])
            ->name('api.v1.version');
    });

// OAuth user token 単独 (API キーにセッション概念は無いため dual guard に含めない。
// API キー Bearer は guard 段 401 でエラー契約を混線させない)
Route::prefix('v1')
    ->middleware(['auth:api-oauth', 'throttle:api-write', 'resolve.api-actor'])
    ->group(function (): void {
        Route::delete('/me/session', [RevokeSessionController::class, 'destroy'])
            ->name('api.v1.me.session.revoke');
    });

// 読み取り (read ability)
Route::prefix('v1')
    ->middleware(['auth:api-key,api-oauth', 'throttle:api-read', 'resolve.api-actor', 'api-key.ability:read',
        'api.project-in-org'])
    ->group(function (): void {
        Route::get('/me', [MeController::class, 'show'])
            ->name('api.v1.me');

        Route::get('/projects', [ProjectController::class, 'index'])
            ->name('api.v1.projects.index');
        Route::get('/projects/{project}', [ProjectController::class, 'show'])
            ->name('api.v1.projects.show');

        Route::get('/projects/{project}/items', [ItemController::class, 'index'])
            ->name('api.v1.projects.items.index');
    });

// 書き込み (write ability)。全 write エンドポイントに Idempotency-Key を配線する
Route::prefix('v1')
    ->middleware(['auth:api-key,api-oauth', 'throttle:api-write', 'resolve.api-actor', 'api-key.ability:write',
        'api.project-in-org', 'idempotent'])
    ->group(function (): void {
        Route::post('/projects/{project}/items', [ItemController::class, 'store'])
            ->name('api.v1.projects.items.store');
        // {item} ∈ {project} を **routing 層** (SubstituteBindings) で解決する。
        // FormRequest より前に 404 が確定し、web 側 (routes/web.php) と同じ構造になる。
        Route::scopeBindings()->group(function (): void {
            Route::patch('/projects/{project}/items/{item}', [ItemController::class, 'update'])
                ->name('api.v1.projects.items.update');
            Route::delete('/projects/{project}/items/{item}', [ItemController::class, 'destroy'])
                ->name('api.v1.projects.items.destroy');
        });
    });
