<?php

declare(strict_types=1);

/*
 * 入力の妥当性は FormRequest 層で 422 になる (500 にしない。家系裁定 AG-039)。
 *
 * domain 例外 (`InvalidOrganizationSlugException` / `ReservedOrganizationSlugException`) は
 * HTTP を知らない。素のまま Controller まで届くと 500 になるため、
 * **FormRequest のカスタムルール**で 422 へ変換する。
 */

test('構文違反は 422', function (string $slug): void {
    [$organization, $owner] = createOrganizationWithOwner();

    $this->actingAs($owner)
        ->patch("/organizations/{$organization->slug}/slug", ['slug' => $slug])
        ->assertSessionHasErrors('slug');
})->with([
    '先頭ハイフン' => '-acme',
    '末尾ハイフン' => 'acme-',
    '連続ハイフン' => 'ac--me',
    'アンダースコア' => 'acme_corp',
    '日本語' => '日本語',
    'スラッシュ' => 'a/b',
]);

test('予約語は 422', function (string $slug): void {
    [$organization, $owner] = createOrganizationWithOwner();

    $this->actingAs($owner)
        ->patch("/organizations/{$organization->slug}/slug", ['slug' => $slug])
        ->assertSessionHasErrors('slug');
})->with(['admin', 'create', 'www', 'support']);

test('空の識別名は 422', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();

    $this->actingAs($owner)
        ->patch("/organizations/{$organization->slug}/slug", ['slug' => ''])
        ->assertSessionHasErrors('slug');
});

test('保護キーを payload に混ぜたら 422 (tenant キー不信)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();

    $this->actingAs($owner)
        ->patch("/organizations/{$organization->slug}/slug", [
            'slug' => 'ok-slug',
            'organization_id' => 999,
        ])
        ->assertSessionHasErrors();
});
