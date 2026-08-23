<?php

declare(strict_types=1);

use App\Models\EmailPromotion;
use App\Models\EnterpriseIdentity;
use Illuminate\Support\Facades\DB;

/*
 * 申告メール / 昇格中メールの暗号化 (A2 / E1。AGENTS.md セキュリティ不変条件 6)。
 */

test('身元の申告メールが平文で保存されない', function (): void {
    $identity = EnterpriseIdentity::factory()->create(['claimed_email_encrypted' => 'worker@corp.example']);

    /** @var object{claimed_email_encrypted: string} $raw */
    $raw = DB::table('enterprise_identities')->where('id', $identity->id)->first();

    expect($raw->claimed_email_encrypted)->not->toContain('worker@corp.example');
    expect($identity->fresh()?->claimed_email_encrypted)->toBe('worker@corp.example');
});

test('身元の申告メールは null でもよい (メールを出さない IdP がある)', function (): void {
    $identity = EnterpriseIdentity::factory()->create(['claimed_email_encrypted' => null]);

    expect($identity->fresh()?->claimed_email_encrypted)->toBeNull();
});

test('昇格中のメールが平文で保存されない', function (): void {
    $promotion = EmailPromotion::factory()->create(['email_encrypted' => 'new@corp.example']);

    /** @var object{email_encrypted: string} $raw */
    $raw = DB::table('email_promotions')->where('id', $promotion->id)->first();

    expect($raw->email_encrypted)->not->toContain('new@corp.example');
    expect($promotion->fresh()?->email_encrypted)->toBe('new@corp.example');
});

test('昇格中のメールにも blind index を作らない', function (): void {
    $promotion = EmailPromotion::factory()->create();

    expect(DB::table('blind_indexes')
        ->where('indexable_type', EmailPromotion::class)
        ->where('indexable_id', $promotion->id)
        ->count())->toBe(0);
});
