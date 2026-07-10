<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Browser スモークテスト (pest-plugin-browser / Playwright)
|--------------------------------------------------------------------------
|
| Browser lane の共通基盤 (in-process サーバ + 実ブラウザ + RefreshDatabase +
| actingAs) が機能することの最小検証。実行は `composer test:browser`
| (scripts/run-browser-test.sh 経由。前提・規約は docs/testing-browser.md)。
|
| 実ブラウザは public/build のビルド済アセットを読むため、UI 変更後は
| `pnpm build` を先に実行すること。
|
*/

test('ゲストがトップページを JS エラーなしで表示できる', function (): void {
    $page = visit('/');

    $page->assertPathIs('/')
        ->assertSee(config()->string('app.name'))
        ->assertNoJavaScriptErrors();
});

test('ゲストは /dashboard に到達できず /login へリダイレクトされる', function (): void {
    visit('/dashboard')->assertPathIs('/login');
});

test('actingAs が実ブラウザの session で効き dashboard を表示できる', function (): void {
    // 組織 provisioning 済みの owner を使う (dashboard は current org 前提の共有 props を読む)
    [, $owner] = createOrganizationWithOwner();

    $this->actingAs($owner);

    visit('/dashboard')
        ->assertPathIs('/dashboard')
        ->assertNoJavaScriptErrors();
});
