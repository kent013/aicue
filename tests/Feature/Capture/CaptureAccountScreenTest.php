<?php

declare(strict_types=1);

use App\Enums\ProjectRole;
use App\Models\Project;
use App\Models\User;
use App\Support\Seo\SeoTitle;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * 撮影 PWA のアカウント確認画面 (doc/05 §5.1 / §5.2)。
 * 到達条件 (current org 所属 = 200 / 未設定・非所属 = 認可より前に 404) と、
 * 撮影者ロールでも 200 になることを固定する。
 * 表示値は共有 props (auth.user / currentOrganization) なので、
 * 「画面がその共有 props を伴って返る」ことまでをサーバ側の契約とする。
 */

test('current org 所属なら 200 で Capture/Account を返す', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();

    $this->actingAs($owner)->get('/app/account')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Capture/Account')
            ->where('auth.user.email', $owner->email)
            ->where('auth.user.name', $owner->name)
            ->where('currentOrganization.name', $organization->name)
        );
});

/*
 * この route は project 非依存なので、到達条件は「current org に在籍していること」だけである。
 * project role (撮影者 = project_member) は**この route の認可条件ではない**。
 * それでも撮影者を作るのは、現場で実際にこの画面へ来る人物像で 200 を確かめるためである。
 */
test('組織メンバー (撮影者ロールの利用者) でも 200 — project role は条件ではない', function (): void {
    [$organization] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $member = attachOrganizationMember($organization);
    // attachOrganizationMember は current_organization_id を設定しない
    // (既存 TakeUploadUrlTest と同じ手順で明示代入する)
    $member->forceFill(['current_organization_id' => $organization->id])->save();
    attachProjectMember($project, $member, ProjectRole::Member);

    $this->actingAs($member)->get('/app/account')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Capture/Account'));
});

test('current org 未設定なら 404 (組織の有無を露出しない)', function (): void {
    $user = User::factory()->create(); // 組織に属さない

    $this->actingAs($user)->get('/app/account')->assertNotFound();
});

test('current org に非所属なら 404 (認可より前)', function (): void {
    [$organization] = createOrganizationWithOwner();
    [, $stranger] = createOrganizationWithOwner('別組織');

    // 他組織の owner の current org を、**非所属の**組織に向ける
    // (current_organization_id が退会後も残存する不整合を模す)
    $stranger->forceFill(['current_organization_id' => $organization->id])->save();

    // 前提: stranger は $organization に在籍していない (この前提が崩れるとテストが空振りする)
    expect($organization->users()->whereKey($stranger->getKey())->exists())->toBeFalse();

    $this->actingAs($stranger)->get('/app/account')->assertNotFound();
});

test('未認証はログインへ redirect する', function (): void {
    $this->get('/app/account')->assertRedirect('/login');
});

/*
 * タブ名は共有端末で「今どの画面を開いているか」の唯一の識別子になる (capture.manuals.index と
 * 同じ理由)。静的名なので config('seo.app_titles') が正本で、controller は何も供給しない。
 * ここが空欄に戻るとサイト名だけのタイトルになり、bug-hunt 目録の画面名も空になる。
 */
test('タブ名は config の静的名から解決される', function (): void {
    [, $owner] = createOrganizationWithOwner();

    $this->actingAs($owner)->get('/app/account')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('title', SeoTitle::compose('アカウント'))
        );
});
