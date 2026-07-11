<?php

declare(strict_types=1);

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * LP (トップ /)。HomeController が LandingPageDto (typed array) を供給する。
 * SEO full 分類 (canonical / og / JSON-LD) の検証は SeoHeadCompositionTest。
 */

test('guest は LP の page props (signup grant / contact / 未認証) を受け取る', function (): void {
    $this->get('/')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Welcome')
            ->where('page.signupGrantTickets', 10)
            ->where('page.contactUrl', '/contact?source=landing')
            ->where('page.contactIsExternal', false)
            ->where('page.isAuthenticated', false));
});

test('認証済みユーザーには isAuthenticated=true が渡る', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Welcome')
            ->where('page.isAuthenticated', true));
});

test('外部フォーム設定時は contactUrl に source を付与せず external フラグが立つ', function (): void {
    config()->set('services.marketing.contact_url', 'https://forms.example.com/contact');

    $this->get('/')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.contactUrl', 'https://forms.example.com/contact')
            ->where('page.contactIsExternal', true));
});

test('LP の JSON-LD は Free プラン開始可能 (lowPrice 0) の offers を含む', function (): void {
    $html = (string) $this->get('/')->getContent();

    expect($html)->toContain('AggregateOffer')
        ->toContain('"lowPrice":0');
});
