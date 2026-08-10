<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Services\Billing\Contracts\StripeGatewayInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * 決済事業者側 customer の redaction (非表示化) **実施記録** コマンド。
 *
 * ★このコマンドは決済事業者 API を呼ばない。人手 (ダッシュボード操作) で行った redaction を
 *   自 DB に記録するだけである (退会経路から決済事業者 API を呼ばない原則 = T115 / 標準形 v1)。
 * ★記録は 2 列セット (`stripe_customer_redacted_at` / `stripe_customer_redacted_id`)。
 *   日時だけでは「**どの** customer を redact したか」が事後に検証できないため。
 *   両列同時の不変条件は**アプリ層だけでなく DB の CHECK 制約**でも守る。
 */

test('dry-run は列を書かない', function (): void {
    $organization = Organization::factory()->withStripeCustomer('cus_dryrun')->create();

    $this->artisan('billing:mark-stripe-customer-redacted', ['organization' => (string) $organization->getKey()])
        ->expectsOutputToContain('dry-run')
        ->assertExitCode(0);

    $organization->refresh();
    expect($organization->stripe_customer_redacted_at)->toBeNull()
        ->and($organization->stripe_customer_redacted_id)->toBeNull();
});

test('--apply で実施日時と customer id が両方入る', function (): void {
    $organization = Organization::factory()->withStripeCustomer('cus_applied')->create();

    $this->artisan('billing:mark-stripe-customer-redacted', [
        'organization' => (string) $organization->getKey(),
        '--apply' => true,
    ])->assertExitCode(0);

    $organization->refresh();
    expect($organization->stripe_customer_redacted_at)->not->toBeNull()
        ->and($organization->stripe_customer_redacted_id)->toBe('cus_applied');
});

test('片列だけの UPDATE は DB の CHECK 制約が拒否する (アプリ層を迂回しても守られる)', function (): void {
    $organization = Organization::factory()->withStripeCustomer('cus_check')->create();

    // ★RefreshDatabase のテスト用トランザクションを巻き添えに abort させないよう、
    //   違反する UPDATE は入れ子トランザクション (savepoint) の中で起こす。
    $update = fn (array $values) => DB::transaction(
        fn () => DB::table('organizations')->where('id', $organization->getKey())->update($values),
    );

    // 日時だけ入れる
    expect(fn () => $update(['stripe_customer_redacted_at' => now()]))->toThrow(QueryException::class);

    // customer id だけ入れる
    expect(fn () => $update(['stripe_customer_redacted_id' => 'cus_check']))->toThrow(QueryException::class);

    $organization->refresh();
    expect($organization->stripe_customer_redacted_at)->toBeNull()
        ->and($organization->stripe_customer_redacted_id)->toBeNull();
});

test('両列同時の UPDATE は CHECK 制約を通る (制約が正当な書き込みまで塞いでいない)', function (): void {
    $organization = Organization::factory()->withStripeCustomer('cus_both')->create();

    DB::table('organizations')
        ->where('id', $organization->getKey())
        ->update([
            'stripe_customer_redacted_at' => now(),
            'stripe_customer_redacted_id' => 'cus_both',
        ]);

    $organization->refresh();
    expect($organization->stripe_customer_redacted_id)->toBe('cus_both');
});

test('二重実行は no-op で既記録日を表示する (SUCCESS)', function (): void {
    $organization = Organization::factory()->withStripeCustomer('cus_twice')->create();
    $arguments = ['organization' => (string) $organization->getKey(), '--apply' => true];

    $this->artisan('billing:mark-stripe-customer-redacted', $arguments)->assertExitCode(0);
    $organization->refresh();
    $recordedAt = $organization->stripe_customer_redacted_at;
    expect($recordedAt)->not->toBeNull();

    $this->travel(1)->days();

    $this->artisan('billing:mark-stripe-customer-redacted', $arguments)
        ->expectsOutputToContain('記録済み')
        ->assertExitCode(0);

    $organization->refresh();
    // 2 回目で日時が上書きされない (最初の実施日が監査証跡として残る)
    expect($organization->stripe_customer_redacted_at?->toIso8601String())
        ->toBe($recordedAt?->toIso8601String());
});

test('stripe_id が無い組織では FAILURE で 1 列も書かない (fail-closed)', function (): void {
    $organization = Organization::factory()->create();
    expect($organization->stripe_id)->toBeNull();

    $this->artisan('billing:mark-stripe-customer-redacted', [
        'organization' => (string) $organization->getKey(),
        '--apply' => true,
    ])->assertExitCode(1);

    $organization->refresh();
    expect($organization->stripe_customer_redacted_at)->toBeNull()
        ->and($organization->stripe_customer_redacted_id)->toBeNull();
});

test('存在しない組織 ID は FAILURE', function (): void {
    $this->artisan('billing:mark-stripe-customer-redacted', ['organization' => '999999', '--apply' => true])
        ->assertExitCode(1);
});

test('決済事業者 API を 1 回も呼ばない', function (): void {
    $organization = Organization::factory()->withStripeCustomer('cus_no_api')->create();
    // 期待を設定しない mock = 1 度でも呼ばれたら fail (AccountDeletionTest と同じ形)
    $this->mock(StripeGatewayInterface::class);

    $this->artisan('billing:mark-stripe-customer-redacted', [
        'organization' => (string) $organization->getKey(),
        '--apply' => true,
    ])->assertExitCode(0);

    $organization->refresh();
    expect($organization->stripe_customer_redacted_id)->toBe('cus_no_api');
});
