<?php

declare(strict_types=1);

/**
 * 組織作成フォームのバリデーション文言 (T012 レビュー指摘由来)。
 *
 * 更新経路 (organizations.update) は OrganizationSettingsCopyTest が担う。
 * 作成経路 (organizations.store) も同一エンティティ・同一ラベル (Organizations/Create.svelte
 * 「組織名」) のため、StoreOrganizationRequest::attributes() の局所上書きで語彙を揃える。
 */

// StoreOrganizationRequest::attributes() の局所上書きが効き、グローバルの「名前」ではなく
// UI ラベル (Organizations/Create.svelte「組織名」) 準拠の「組織名」で表示されることを
// 厳密一致で検証する (表示文言そのものが検証対象)
test('組織作成で組織名が空だと局所上書きされた日本語ラベルのエラー文言が返る', function (): void {
    // .env.testing は APP_LOCALE=en のため、日本語文言の検証対象ロケールを明示する
    $this->app->setLocale('ja');

    [, $owner] = createOrganizationWithOwner();

    $response = $this->actingAs($owner)
        ->from(route('organizations.create'))
        ->post(route('organizations.store'), ['name' => '']);

    $response->assertSessionHasErrors(['name' => '組織名は必須項目です。']);
});
