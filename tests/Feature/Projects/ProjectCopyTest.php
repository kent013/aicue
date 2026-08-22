<?php

declare(strict_types=1);

use App\Models\Project;

/**
 * プロジェクト作成・更新フォームのバリデーション文言 (T012 レビュー指摘由来)。
 *
 * Projects/Create.svelte:35 / Edit.svelte:40 のフォームラベルは「プロジェクト名」のため、
 * Store/UpdateProjectRequest::attributes() の局所上書きでグローバルの「名前」ではなく
 * ラベル準拠の語彙でエラー文言を返す (語彙ズレ禁止 = lang/ja/validation.php の規約)。
 */
test('プロジェクト作成で名前が空だと UI ラベル準拠のエラー文言が返る', function (): void {
    // .env.testing は APP_LOCALE=en のため、日本語文言の検証対象ロケールを明示する
    $this->app->setLocale('ja');

    [$organization, $owner] = createOrganizationWithOwner();

    $response = $this->actingAs($owner)
        ->from(route('projects.create', ['organization' => $organization->slug]))
        ->post(route('projects.store', ['organization' => $organization->slug]), ['name' => '']);

    $response->assertSessionHasErrors(['name' => 'プロジェクト名は必須項目です。']);
});

test('プロジェクト更新で名前が空だと UI ラベル準拠のエラー文言が返る', function (): void {
    $this->app->setLocale('ja');

    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();

    $response = $this->actingAs($owner)
        ->from(route('projects.edit', $project))
        ->patch(route('projects.update', $project), ['name' => '']);

    $response->assertSessionHasErrors(['name' => 'プロジェクト名は必須項目です。']);
});
