<?php

declare(strict_types=1);

use App\Models\User;

/*
 * bug-hunt F-2-01 の根本原因を「仕様」として固定する回帰テスト。
 *
 * `/onboarding/checkout` は routes/web.php の `['auth','verified']` group 配下にあり、
 * メール未認証ユーザーは Laravel 標準の `verified` middleware により必ず
 * `verification.notice` へ差し戻される。これは意図した配置である
 * (未検証メールで Personal 無料枠付与 / Stripe Checkout の入口へ到達させない)。
 *
 * したがって verify notice 画面から `onboarding.checkout` へ進む CTA を出してはならない
 * (表示条件と踏破条件が食い違う = 恒常的に失敗する導線になる)。本テストはその
 * 「出してはならない根拠」をサーバ側の事実として固定する。
 */

test('メール未認証ユーザーの GET /onboarding/checkout は verification.notice へ差し戻される', function (): void {
    [, $owner] = createOrganizationWithOwner(grandfatherFreePlan: false);
    // email_verified_at は $fillable 外の状態キーのため forceFill で明示代入する
    $owner->forceFill(['email_verified_at' => null])->save();

    $this->actingAs($owner->fresh())
        ->get('/onboarding/checkout')
        ->assertRedirect(route('verification.notice'));
});

test('認証済み owner は GET /onboarding/checkout に到達できる (ゲートを締めすぎていない)', function (): void {
    [, $owner] = createOrganizationWithOwner(grandfatherFreePlan: false);

    expect($owner->hasVerifiedEmail())->toBeTrue();

    $this->actingAs($owner)
        ->get('/onboarding/checkout')
        ->assertOk();
});

test('未認証ユーザーは verify notice 画面へ着地し状況説明を受け取る (行き先のない詰みにしない)', function (): void {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)
        ->followingRedirects()
        ->get('/onboarding/checkout')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Auth/VerifyEmail'));
});
