<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
 * web の `{project}` route は project.in-current-org middleware
 * (EnsureProjectBelongsToCurrentOrganization) を必ず持つ invariant。
 *
 * cross-org の {project} は「FormRequest の DB ルール (unique/exists) を含む
 * あらゆるアプリコードより前に 404」でなければならない (存在オラクル防止)。
 * controller の inline guard (resolveOrganizationProject) は認可より前の 404 を担うが、
 * FormRequest のバリデーションは controller メソッド解決時 (= inline guard より前) に走るため、
 * middleware 層の guard が無いと project スコープの DB ルールがクロステナントの
 * 存在オラクルになる (T001 レビュー指摘)。本テストは deny-by-default で
 * 「{project} を受ける web route に middleware が付いていること」を機械検証し、
 * 将来の route 追加での guard 漏れを構造的に落とす。
 *
 * API v1 (`api/*`) は org を API キーから確定する別レイヤー (ResolvesApiOrganization) の
 * 責務のため対象外 (web セッション前提の本 middleware を付けてはならない)。
 */
test('web の {project} route は project.in-current-org middleware を必ず持つ (API は持たない)', function (): void {
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
