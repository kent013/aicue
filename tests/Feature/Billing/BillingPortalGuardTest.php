<?php

declare(strict_types=1);

/*
 * P8b (bs-11): Customer Portal の事前ガード。
 *
 * Portal は Stripe customer + サブスク前提。free personal (canceled サブスク行が残る
 * paid→free を含む) / 未契約 org は Cashier の assertCustomerExists() 例外 (= 500) に
 * 到達させず、error flash で back する (fail-closed)。
 */

test('未契約 org (サブスク行なし) の owner は portal に到達せず error flash で戻る', function (): void {
    [$organization, $owner] = createOrganizationWithOwner(grandfatherFreePlan: false);
    // Fake gateway を bind しておき「呼ばれない」ことを到達判定に使う
    enableFakeExternals();

    $response = $this->from("/organizations/{$organization->slug}/billing")->actingAs($owner)->post("/organizations/{$organization->slug}/billing/portal");

    $response->assertRedirect("/organizations/{$organization->slug}/billing");
    $response->assertSessionHas('error', 'お支払い管理画面は有償プラン契約後にご利用いただけます。');
    // Stripe (fake) に到達していない = 外部 URL への遷移になっていない
    expect($response->headers->get('Location'))->not->toContain('fake_external=stripe');
});

test('ActiveFreePlan (canceled サブスク行が残る) org の owner も portal に到達しない', function (): void {
    [$organization, $owner] = createOrganizationWithOwner(); // free_plan_code='personal'
    createFakeSubscription($organization, status: 'canceled');
    enableFakeExternals();

    $response = $this->from("/organizations/{$organization->slug}/billing")->actingAs($owner)->post("/organizations/{$organization->slug}/billing/portal");

    $response->assertRedirect("/organizations/{$organization->slug}/billing");
    $response->assertSessionHas('error', 'お支払い管理画面は有償プラン契約後にご利用いただけます。');
});

test('有償サブスクを持つ owner は従来どおり Portal URL へ遷移する', function (): void {
    [$organization, $owner] = createOrganizationWithOwner(grandfatherFreePlan: false);
    contractPaidPlan($organization);
    enableFakeExternals();

    $response = $this->actingAs($owner)->post("/organizations/{$organization->slug}/billing/portal");

    $response->assertStatus(302);
    expect($response->headers->get('Location'))->toContain('fake_external=stripe');
});
