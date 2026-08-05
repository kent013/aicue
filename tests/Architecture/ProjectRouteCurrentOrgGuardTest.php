<?php

declare(strict_types=1);

use App\Http\Middleware\EnsureProjectBelongsToApiOrganization;
use App\Http\Middleware\IdempotentRequest;
use App\Http\Middleware\RequireApiKeyAbility;
use App\Http\Middleware\ResolveApiActor;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;

/*
 * `{project}` を受ける route は URL 整合 guard を **middleware 層**に必ず持つ invariant。
 *
 * cross-org の {project} は「FormRequest の DB ルール (unique/exists) を含む
 * あらゆるアプリコードより前に 404」でなければならない (存在オラクル防止)。
 * controller の inline guard (resolveOrganizationProject) は認可より前の 404 を担うが、
 * FormRequest のバリデーションは controller メソッド解決時 (= inline guard より前) に走るため、
 * middleware 層の guard が無いと「cross-org の実在 project + 不正 payload = 422 /
 * 不在 project = 404」の差分がクロステナントの存在オラクルになる (T001 / T103 レビュー指摘)。
 * 本テストは deny-by-default で「{project} を受ける route に middleware が付いていること」を
 * 機械検証し、将来の route 追加での guard 漏れを構造的に落とす。
 *
 * 組織の解決元が違うため middleware は web / API で 2 本立てになる:
 *  - web (`project.in-current-org` = EnsureProjectBelongsToCurrentOrganization):
 *    セッションの current org。API に付けてはならない (API はセッションを持たない)
 *  - API v1 (`api.project-in-org` = EnsureProjectBelongsToApiOrganization):
 *    API キー / OAuth token から確定した request attribute 'organization'
 */

test('web の {project} route は project.in-current-org / API は api.project-in-org を必ず持つ', function (): void {
    $checked = 0;
    $violations = [];

    foreach (Route::getRoutes() as $route) {
        if (! in_array('project', $route->parameterNames(), true)) {
            continue;
        }

        $name = $route->getName() ?? $route->uri();
        $middleware = $route->gatherMiddleware();

        if (str_starts_with($route->uri(), 'api/')) {
            // API は web セッション (current org) を持たない。誤配線は全 API project route を
            // 404 に落とすため、付いていたら fail させる
            if (in_array('project.in-current-org', $middleware, true)) {
                $violations[] = "API route {$name} に web セッション前提の project.in-current-org が付いている";
            }
            // API 版の URL 整合 guard は必須 (FormRequest より前に cross-org を 404 に落とす)
            if (! in_array('api.project-in-org', $middleware, true)) {
                $violations[] = "API route {$name} に api.project-in-org middleware が無い"
                    .' (cross-org {project} が FormRequest より前に 404 になりません)';
            }
            $checked++;

            continue;
        }

        if (! in_array('project.in-current-org', $middleware, true)) {
            $violations[] = "web route {$name} に project.in-current-org middleware が無い"
                .' (cross-org {project} が FormRequest の DB ルールより前に 404 になりません)';
        }
        $checked++;
    }

    expect($violations)->toBe([]);
    // route が 1 本も検査されない (= {project} route が消えた/リネームされた) 場合も fail させ、
    // テスト自体の空振り drift を検知する
    expect($checked)->toBeGreaterThan(0);
});

/*
 * API の middleware 順序契約 (docblock ではなく機械で固定する):
 *
 *   resolve.api-actor  <  SubstituteBindings  <  api.project-in-org  <  api-key.ability:*  <  idempotent
 *
 * | 破られる契約 | 起きること |
 * |---|---|
 * | resolve.api-actor が api.project-in-org **より後** | 'organization' attribute 未設定で Assert が
 *   発火し **全 API {project} route が 500** |
 * | api-key.ability:* が api.project-in-org **より前** | **ability 不足時に cross-org の実在が
 *   403 で漏れる** (他組織に実在 = 403 / 不在 = 404 の存在オラクル。audit-cycle-2 High-1) |
 * | idempotent が api.project-in-org **より前** | **cross-org リクエストで idempotency 行が作られる**
 *   (cross-org の副作用 = 不変条件 3 に抵触) |
 *
 * 注意: **順序の正本は bootstrap/app.php の priority list** であり route の宣言順ではない。
 * したがって本テストは gatherMiddleware() (宣言順の alias 文字列) ではなく
 * Router::gatherRouteMiddleware() (priority 適用後の具象クラス列) を測る。
 * 宣言順を見ていたことが audit-cycle-2 で穴が見えなかった直接の原因である。
 * binding 直後であることまで含めた不変条件は TenantBoundaryOrderingTest が担う。
 */
test('API の {project} route は middleware 順序契約を守る (解決後の実行順)', function (): void {
    /** @var Router $router */
    $router = app('router');
    $checked = 0;
    $violations = [];

    foreach (Route::getRoutes() as $route) {
        if (! str_starts_with($route->uri(), 'api/')) {
            continue;
        }
        if (! in_array('project', $route->parameterNames(), true)) {
            continue;
        }

        $name = $route->getName() ?? $route->uri();
        // `Class:param` の parameter を落とし、解決後の具象クラス名で比較する
        $resolved = array_map(
            static fn (mixed $m): string => is_string($m) ? explode(':', $m, 2)[0] : '(closure)',
            $router->gatherRouteMiddleware($route),
        );
        $indexOf = static fn (string $class): int|false => array_search($class, $resolved, true);

        $guard = $indexOf(EnsureProjectBelongsToApiOrganization::class);
        $actor = $indexOf(ResolveApiActor::class);
        $binding = $indexOf(SubstituteBindings::class);
        $ability = $indexOf(RequireApiKeyAbility::class);
        $idempotent = $indexOf(IdempotentRequest::class);

        if ($guard === false) {
            $violations[] = "{$name}: api.project-in-org が無い";

            continue;
        }
        if ($actor === false || $actor > $guard) {
            $violations[] = "{$name}: resolve.api-actor が api.project-in-org より後 "
                .'(organization attribute 未設定で 500 になります)';
        }
        if ($binding === false || $binding > $guard) {
            $violations[] = "{$name}: SubstituteBindings が api.project-in-org より後 "
                .'(guard が binding 済みの Project を読めません)';
        }
        if ($ability === false || $ability < $guard) {
            $violations[] = "{$name}: api-key.ability:* が api.project-in-org より前 "
                .'(ability 不足時に cross-org の実在が 403 で漏れます)';
        }
        if ($idempotent !== false && $idempotent < $guard) {
            $violations[] = "{$name}: idempotent が api.project-in-org より前 "
                .'(cross-org リクエストで idempotency 行が作られます)';
        }
        $checked++;
    }

    expect($violations)->toBe([]);
    expect($checked)->toBeGreaterThan(0); // 空振り drift ガード
});
