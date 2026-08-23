<?php

declare(strict_types=1);
use App\Models\Project;
use Illuminate\Routing\Exceptions\UrlGenerationException;

/*
 * 主要画面の**生成 URL に識別名が含まれる** (家系裁定 AG-037)。
 *
 * 「organization 引数を渡し忘れた」は旧 URL 検査では拾えない
 * (`route()` は足りない引数を query string へ落とすか例外にするだけで、
 *  リポジトリ内に旧 URL 文字列は現れない)。生成側で固定する。
 */

test('主要 route の生成 URL に組織の識別名が入る', function (string $name): void {
    [$organization] = createOrganizationWithOwner();

    $url = route($name, ['organization' => $organization->slug], absolute: false);

    expect($url)->toStartWith("/organizations/{$organization->slug}/");
})->with([
    'dashboard',
    'projects.index',
    'capture.home',
    'billing.index',
    'billing.tickets.show',
    'notifications.index',
    'manage.users.index',
    'onboarding.checkout',
]);

test('子リソースの生成 URL も組織配下になる (位置引数のずれを名前付きで防ぐ)', function (): void {
    [$organization] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();

    $url = route('projects.show', [
        'organization' => $organization->slug,
        'project' => $project->getKey(),
    ], absolute: false);

    expect($url)->toBe("/organizations/{$organization->slug}/projects/{$project->getKey()}");
});

test('組織を渡さないと生成できない (黙って旧 URL にはならない)', function (): void {
    expect(fn (): string => route('projects.index', [], absolute: false))
        ->toThrow(UrlGenerationException::class);
});
