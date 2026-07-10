<?php

declare(strict_types=1);

use App\Enums\Security\NestedRouteDefenseMode;
use Illuminate\Support\Facades\Route;

/**
 * nested route (親子) IDOR 防御の網羅性 invariant。
 *
 * 「子リソースを URL で受ける route は、子が必ず URL 親/テナントに属することを構造的に担保し、
 * 不整合は認可より前に 404 (403 で存在を漏らさない)」という不変条件を、各 route が
 * どの防御方式 (NestedRouteDefenseMode) で守っているかを deny-by-default で機械検証する。
 *
 * 本テストは「分類漏れ・drift を落とす」役割に限定する (inline guard の静的正当性は証明しない)。
 * 実挙動 (不整合→404 等) は scopeBindings の Routing 層 enforcement と各 Feature テスト
 * (UrlIntegrityGuardTest / OrganizationBoundaryNotFoundTest 等) が担保する。
 *
 * 2 個以上の route パラメータを取る named route を全て候補とし、inventory (防御方式付き) か
 * prefixExemptAllowlist (親子テナントでない理由付き) のどちらかに必ず分類させる。
 */

/**
 * route 名 => 防御方式の明示 inventory (型付き)。
 *
 * @return array<string, NestedRouteDefenseMode>
 */
function nestedRouteIdorInventory(): array
{
    $s = NestedRouteDefenseMode::ScopeBindings;
    $g = NestedRouteDefenseMode::UrlIntegrityGuard;

    return [
        // --- Route::scopeBindings() (親 relation 経由で子を解決、不整合は 404) ---
        // {apiKey} は $organization->apiKeys() 経由 ({organization} 自体は
        // MembershipScopedOrganizationBinder が membership スコープで解決)
        'organizations.api-keys.revoke' => $s,
        // {oauthSession} は $organization->oauthSessions() 経由 (WP24。controller 内の
        // organization_id 再検査は二重防御)
        'organizations.api-keys.sessions.revoke' => $s,
        // {invitation} は $organization->invitations() 経由 (招待取り消し。cross-org は 404)
        'organizations.invitations.revoke' => $s,
        // {item} は $project->items() 経由 ({project} ∈ current org は
        // project.in-current-org middleware + controller inline guard の 2 層)
        'projects.items.update' => $s,
        'projects.items.destroy' => $s,
        // {category} は $project->categories() 経由 ({project} ∈ current org は
        // project.in-current-org middleware + controller inline guard の 2 層。
        // FormRequest の DB ルール (unique) より前の 404 は ProjectRouteCurrentOrgGuardTest 参照)
        'projects.categories.update' => $s,
        'projects.categories.destroy' => $s,
        // {manual} は $project->manuals() 経由 (relation 名は route パラメータ {manual} の
        // scopeBindings 推論と一致させた manuals()。{project} ∈ current org は
        // project.in-current-org middleware + inline guard の 2 層)
        'projects.manuals.show' => $s,
        'projects.manuals.edit' => $s,
        'projects.manuals.update' => $s,
        // シナリオ document 保存 (PUT)。{manual} は $project->manuals() 経由 (scopeBindings)
        'projects.manuals.scenario.update' => $s,
        'projects.manuals.destroy' => $s,
        // SOP アップロード / AI 解析 / job ポーリング ({manual} は $project->manuals()、
        // {analysisJob} は $manual->analysisJobs() 経由。不整合は認可より前に 404)
        'projects.manuals.source-documents.store' => $s,
        'projects.manuals.analyze' => $s,
        'projects.manuals.jobs.show' => $s,
        // 撮影 PWA (/app/*。doc/10 §10.8-3)。{manual}∈{project}, {cut}∈{manual}, {take}∈{cut} は
        // scopeBindings + 各書き込み Service の tx 内連鎖再解決 (二重防御)。
        // {project} ∈ current org は project.in-current-org middleware + inline guard の 2 層
        'capture.manuals.show' => $s,
        'capture.manuals.sync' => $s,
        'capture.takes.upload-url' => $s,
        'capture.takes.store' => $s,
        'capture.takes.update' => $s,
        'capture.takes.destroy' => $s,
        'capture.takes.adopt' => $s,
        'capture.takes.downloaded' => $s,
        // --- inline 親子整合 guard (authorize 前に 子∈親テナント を検査、不整合は 404) ---
        // OrganizationMemberController::resolveOrganizationMember (非 member は 404)
        'organizations.members.update' => $g,
        'organizations.members.destroy' => $g,
        'organizations.members.two-factor.reset' => $g,
        // ProjectMemberController::destroy (org 越境 {user} は 404)
        'projects.members.destroy' => $g,
        // REST API v1: API キーの組織 relation からの org-scoped 解決
        // (ResolvesApiOrganization。cross-org は認可より前に 404)
        'api.v1.projects.items.update' => $g,
        'api.v1.projects.items.destroy' => $g,
    ];
}

/**
 * 2+param だが「親子 IDOR の対象外」と明示する route (理由付き、真の deny-by-default sentinel)。
 *
 * @return array<string, string>
 */
function nestedRoutePrefixExemptAllowlist(): array
{
    return [
        'social.redirect' => 'auth/{provider}/redirect/{intent}: いずれも config 由来の固定集合で検証・テナント親子でない',
        'verification.verify' => 'email/verify/{id}/{hash}: 署名付き URL (MustVerifyEmail)・テナント親子でない',
    ];
}

/** @return list<Illuminate\Routing\Route> parameterNames>=2 の候補 route (パッケージ内部 route は除外)。 */
function nestedRouteCandidates(): array
{
    $candidates = [];
    foreach (Route::getRoutes() as $route) {
        if (count($route->parameterNames()) < 2) {
            continue;
        }

        // パッケージ管理ルート (Filament/Livewire/Passport 内部) はパッケージ側が防御を担うため
        // 対象外。アプリが定義するルートのみ検査する。
        $name = $route->getName();
        if (str_starts_with($route->uri(), 'livewire')
            || ($name !== null && (str_starts_with($name, 'filament.')
                || str_starts_with($name, 'livewire.')
                || str_starts_with($name, 'passport.')))) {
            continue;
        }

        $candidates[] = $route;
    }

    return $candidates;
}

test('2+param 候補 route は inventory か exemptAllowlist に明示分類されている (未知は fail)', function (): void {
    $inventory = nestedRouteIdorInventory();
    $allow = nestedRoutePrefixExemptAllowlist();
    $violations = [];

    foreach (nestedRouteCandidates() as $route) {
        $name = $route->getName();
        if ($name === null) {
            $violations[] = '無名の 2+param route: '.$route->uri().' (name を付け inventory 登録してください)';

            continue;
        }
        if (array_key_exists($name, $inventory) || array_key_exists($name, $allow)) {
            continue;
        }
        $violations[] = $name.' ('.$route->uri().') が未分類';
    }

    expect($violations)->toBe([],
        '未分類の親子候補 route があります。nestedRouteIdorInventory() に防御方式を登録するか、'
        .'親子テナントでなければ nestedRoutePrefixExemptAllowlist() に理由付きで登録してください。'
        .PHP_EOL.implode(PHP_EOL, $violations));
});

test('inventory/allowlist の key は現存 named route (逆方向整合・stale 検出)', function (): void {
    $named = [];
    foreach (Route::getRoutes() as $route) {
        $n = $route->getName();
        if ($n !== null) {
            $named[$n] = true;
        }
    }

    $stale = [];
    foreach ([
        ...array_keys(nestedRouteIdorInventory()),
        ...array_keys(nestedRoutePrefixExemptAllowlist()),
    ] as $key) {
        if (! isset($named[$key])) {
            $stale[] = $key;
        }
    }

    expect($stale)->toBe([], 'inventory/allowlist に現存しない route 名 (削除/rename 済): '.implode(', ', $stale));
});

test('inventory の各値は NestedRouteDefenseMode', function (): void {
    foreach (nestedRouteIdorInventory() as $mode) {
        expect($mode)->toBeInstanceOf(NestedRouteDefenseMode::class);
    }
});
