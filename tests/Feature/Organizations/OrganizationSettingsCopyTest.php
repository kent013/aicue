<?php

declare(strict_types=1);

/**
 * 組織設定フォームのバリデーション文言 (bug-hunt F-02 由来)。
 *
 * organizations.update の 404 境界は OrganizationBoundaryNotFoundTest が専任のため、
 * 「ユーザーに何が表示されるか」の検証は責務の異なる本ファイルに置く。
 */

// UpdateOrganizationRequest::attributes() の局所上書きが効き、グローバルの「名前」ではなく
// UI ラベル (Organizations/Settings.svelte「組織名」) 準拠の「組織名」で表示されることを
// 厳密一致で検証する (表示文言そのものが検証対象)
test('組織名が空だと局所上書きされた日本語ラベルのエラー文言が返る', function (): void {
    // .env.testing は APP_LOCALE=en のため、日本語文言の検証対象ロケールを明示する
    $this->app->setLocale('ja');

    [$organization, $owner] = createOrganizationWithOwner();

    $response = $this->actingAs($owner)
        ->from(route('organizations.settings', $organization))
        ->patch(route('organizations.update', $organization), ['name' => '']);

    $response->assertSessionHasErrors(['name' => '組織名は必須項目です。']);
});
