<?php

declare(strict_types=1);

use App\Enums\Billing\PlanPriceKind;
use App\Models\Billing\Plan;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;
use Webmozart\Assert\Assert as WebmozartAssert;

/*
 * 公開料金表 (/pricing)。PricingController が PricingPageDto (typed array) を供給する。
 * standard の基本料はリテラルではなく seed 済み plan_prices から導出して検証する
 * (価格改定コミットでこのテストの修正を不要にする)。
 */

/** seed 済み standard プランの current base 額 */
function seededStandardBaseAmount(): int
{
    $price = Plan::query()->where('code', 'standard')->firstOrFail()
        ->currentPrice(PlanPriceKind::Base);
    WebmozartAssert::notNull($price, 'standard プランの current base price が未 seed');

    return $price->amount;
}

test('guest は plans (free/standard) と quota limits 反映の能力値を受け取る', function (): void {
    $standardAmount = seededStandardBaseAmount();

    $this->get('/pricing')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Pricing')
            ->has('page.plans', 2)
            ->where('page.plans.0.code', 'free')
            ->where('page.plans.0.baseAmountJpy', null)
            ->where('page.plans.0.monthlyTicketGrant', 10)
            ->where('page.plans.0.maxProjects', 1)
            ->where('page.plans.0.maxMembers', 3)
            ->where('page.plans.0.maxStorageGb', 1) // GiB 切り捨て規則 (intdiv(bytes, 1024**3))
            ->where('page.plans.1.code', 'standard')
            ->where('page.plans.1.baseAmountJpy', $standardAmount)
            ->where('page.plans.1.monthlyTicketGrant', 100)
            ->where('page.plans.1.maxProjects', 10)
            ->where('page.plans.1.maxMembers', 10)
            ->where('page.plans.1.maxStorageGb', 50)
            ->where('page.isAuthenticated', false));
});

test('ticketTiers は seeder の 7 段が昇順で供給され spot 単価は 100 円', function (): void {
    $this->get('/pricing')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('page.ticketTiers', 7)
            ->where('page.ticketTiers.0.minCount', 1)
            ->where('page.ticketTiers.0.unitAmount', 100)
            ->where('page.ticketTiers.1.minCount', 20)
            ->where('page.ticketTiers.1.unitAmount', 80)
            ->where('page.ticketTiers.6.minCount', 500)
            ->where('page.ticketTiers.6.unitAmount', 50)
            ->where('page.spotUnitAmountJpy', 100));
});

test('signup grant とチケットコストの表示値は config から供給される', function (): void {
    $this->get('/pricing')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.signupGrantTickets', 10)
            ->where('page.signupGrantExpiryDays', 30)
            ->where('page.analysisTicketCost', 1)
            ->where('page.renderTicketCost', 3)
            ->where('page.contactUrl', '/contact?source=pricing')
            ->where('page.contactIsExternal', false));
});

test('認証済みユーザーには isAuthenticated=true が渡る', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/pricing')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('page.isAuthenticated', true));
});

test('/pricing は SEO full 分類でサーバ描画される (canonical + offers + index 可)', function (): void {
    config([
        'seo.base_url' => 'https://app.example',
        'seo.site_name' => 'Acme',
        'seo.default_title' => 'Acme',
        'seo.title_separator' => ' | ',
    ]);

    $response = $this->get('/pricing');
    $response->assertOk();
    $html = (string) $response->getContent();

    expect($html)->toContain('<title>料金プラン | Acme</title>')
        ->toContain('<link rel="canonical" href="https://app.example/pricing">')
        ->toContain('"@type":"SoftwareApplication"')
        ->toContain('AggregateOffer')
        ->and($html)->not->toContain('noindex');
});
