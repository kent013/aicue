<?php

declare(strict_types=1);

use App\Models\User;

/*
 * 組織文脈を持たない入口の分岐 (家系裁定 AG-037)。
 *
 * ★**状態を一切保存しない**。所属が 1 組織ならその組織へ転送、複数なら選ぶ画面、
 *   0 件なら組織作成へ。**自動選択はしない** (自動選択は保持列の再発明である)。
 * ★遷移先は入口ごとの固定表から選ぶ。query string で操作できない (open redirect を作らない)。
 */

test('/go: 所属 0 件は組織作成へ', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/go')->assertRedirect('/organizations/create');
});

test('/go: 所属 1 件はその組織のダッシュボードへ (正確な URL)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();

    $this->actingAs($owner)->get('/go')
        ->assertRedirect("/organizations/{$organization->slug}/dashboard");
});

test('/go: 複数所属は選ぶ画面 (自動選択しない)', function (): void {
    [$first, $user] = createOrganizationWithOwner('あ組織');
    [$second] = createOrganizationWithOwner('い組織');
    $second->users()->attach($user);

    $this->actingAs($user)->get('/go')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Organizations/Choose')
            ->where('target', 'dashboard')
            ->where('organizations.0.slug', $first->slug)
            ->where('organizations.1.slug', $second->slug));
});

test('/app: 所属 1 件はその組織の撮影ホームへ (正確な URL)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();

    $this->actingAs($owner)->get('/app')
        ->assertRedirect("/organizations/{$organization->slug}/app");
});

test('/app: 複数所属は選ぶ画面 (target=capture)', function (): void {
    [$organization, $user] = createOrganizationWithOwner('あ組織');
    [$second] = createOrganizationWithOwner('い組織');
    $second->users()->attach($user);

    $this->actingAs($user)->get('/app')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Organizations/Choose')
            ->where('target', 'capture'));
});

test('/app: 所属 0 件は組織作成へ', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/app')->assertRedirect('/organizations/create');
});

test('未ログインは login へ', function (): void {
    $this->get('/go')->assertRedirect('/login');
    $this->get('/app')->assertRedirect('/login');
});

test('遷移先は query string で操作できない (open redirect を作らない)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();

    $this->actingAs($owner)->get('/go?target=capture&redirect=https://evil.example.com')
        ->assertRedirect("/organizations/{$organization->slug}/dashboard");
});
