<?php

declare(strict_types=1);

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
 *   resolve.api-actor  <  api-key.ability:*  <  api.project-in-org  <  idempotent
 *
 * | 破られる契約 | 起きること |
 * |---|---|
 * | resolve.api-actor が api.project-in-org **より後** | 'organization' attribute 未設定で Assert が
 *   発火し **全 API {project} route が 500** |
 * | api-key.ability:* が api.project-in-org **より後** | ability 不足の判定 (403) より先に
 *   テナント境界の 404 が返り、エラー契約 (insufficient_ability) が route ごとにぶれる |
 * | idempotent が api.project-in-org **より前** | **cross-org リクエストで idempotency 行が作られる**
 *   (cross-org の副作用 = 不変条件 3 に抵触) |
 *
 * 注意: gatherMiddleware() が返すのは **宣言順** (group middleware → route middleware)。
 * Laravel の middleware priority ($middlewarePriority) を導入すると最終的な実行順が
 * 並べ替えられうるが、現行構成では本テストが検査する custom middleware
 * (resolve.api-actor / api.project-in-org / idempotent) はいずれも priority リストに
 * 含まれないため宣言順 = 実行順である。priority を導入する際は本テストの前提を見直すこと。
 */
test('API の {project} route は middleware 順序契約を守る', function (): void {
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
        $middleware = $route->gatherMiddleware();
        $indexOf = static fn (string $needle): int|false => array_search($needle, $middleware, true);

        $guard = $indexOf('api.project-in-org');
        $actor = $indexOf('resolve.api-actor');
        $idempotent = $indexOf('idempotent');
        // ability middleware は `api-key.ability:read` のようにパラメータ付きで並ぶ
        $ability = false;
        foreach ($middleware as $index => $entry) {
            if (is_string($entry) && str_starts_with($entry, 'api-key.ability:')) {
                $ability = $index;

                break;
            }
        }

        if ($guard === false) {
            $violations[] = "{$name}: api.project-in-org が無い";

            continue;
        }
        if ($actor === false || $actor > $guard) {
            $violations[] = "{$name}: resolve.api-actor が api.project-in-org より後 "
                .'(organization attribute 未設定で 500 になります)';
        }
        if ($ability === false || $ability > $guard) {
            $violations[] = "{$name}: api-key.ability:* が api.project-in-org より後 "
                .'(ability 不足の 403 より前にテナント境界の 404 が返り、エラー契約がぶれます)';
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
