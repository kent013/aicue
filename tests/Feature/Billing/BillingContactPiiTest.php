<?php

declare(strict_types=1);

use App\Models\Organization;
use Illuminate\Support\Facades\DB;

/*
 * P9 (セキュリティ不変条件 #6): 請求先連絡先は email / name とも CipherSweet で暗号化する。
 * 平文 where は hit しない = 検索は whereBlind のみ (email だけが blind index を持つ)。
 */

test('PATCH 後、organizations の生値は両列とも平文と一致しない (model 経由では復号される)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();

    $this->actingAs($owner)->patch('/billing/contact', [
        'billing_contact_email' => 'billing@example.test',
        'billing_contact_name' => '経理部 御中',
    ])->assertRedirect();

    /** @var object{billing_contact_email: string, billing_contact_name: string} $raw */
    $raw = DB::table('organizations')->where('id', $organization->id)->firstOrFail();
    expect($raw->billing_contact_email)->not->toBe('billing@example.test');
    expect($raw->billing_contact_name)->not->toBe('経理部 御中');
    // CipherSweet の ciphertext は backend prefix 付き ('nacl:' / 'brng:')
    expect($raw->billing_contact_email)->toMatch('/^(nacl|brng|fips):/');

    $fresh = $organization->fresh();
    expect($fresh?->billing_contact_email)->toBe('billing@example.test');
    expect($fresh?->billing_contact_name)->toBe('経理部 御中');
});

test('平文 where は hit せず whereBlind が該当 org を引く', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();

    $this->actingAs($owner)->patch('/billing/contact', [
        'billing_contact_email' => 'billing@example.test',
    ])->assertRedirect();

    expect(Organization::query()->where('billing_contact_email', 'billing@example.test')->exists())->toBeFalse();

    $found = Organization::whereBlind(
        'billing_contact_email',
        'organization_billing_contact_email_index',
        'billing@example.test',
    )->first();
    expect($found?->id)->toBe($organization->id);
});

test('billing_contact_name の blind index 行は作られない (検索契約が存在しない)', function (): void {
    [, $owner] = createOrganizationWithOwner();

    $this->actingAs($owner)->patch('/billing/contact', [
        'billing_contact_email' => 'billing@example.test',
        'billing_contact_name' => '経理部 御中',
    ])->assertRedirect();

    $indexNames = DB::table('blind_indexes')->distinct()->pluck('name')->all();
    expect($indexNames)->toContain('organization_billing_contact_email_index');
    expect($indexNames)->not->toContain('organization_billing_contact_name_index');
});

test('大文字混じり入力は正規化後の小文字で whereBlind が hit する', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();

    $this->actingAs($owner)->patch('/billing/contact', [
        'billing_contact_email' => '  Billing@Example.TEST  ',
    ])->assertRedirect();

    expect($organization->fresh()?->billing_contact_email)->toBe('billing@example.test');

    $found = Organization::whereBlind(
        'billing_contact_email',
        'organization_billing_contact_email_index',
        'billing@example.test',
    )->first();
    expect($found?->id)->toBe($organization->id);
});
