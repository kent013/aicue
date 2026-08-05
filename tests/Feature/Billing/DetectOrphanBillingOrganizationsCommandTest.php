<?php

declare(strict_types=1);

use App\Models\Organization;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Mockery\MockInterface;

/*
 * 課金孤児 (Owner 不在かつ生きた課金責務が残る組織) の検知バッチ。
 * 退会ガード (予防) で漏れた分と、ガード導入前から存在する孤児を daily で拾う second layer。
 *
 * 報告契約: 1 実行につき集約して 1 回だけ report() し、内容は件数と organization id のみ
 * (組織名・メール等の PII を載せない)。
 */

/** report() 経路 (運用アラート) を観測する spy を差し込む */
function spyOnExceptionHandler(): MockInterface
{
    $handler = Mockery::spy(ExceptionHandler::class);
    app()->instance(ExceptionHandler::class, $handler);

    return $handler;
}

test('課金孤児が無ければ report されない', function (): void {
    [$organization] = createOrganizationWithOwner(); // Owner 在籍 + 課金中
    createFakeSubscription($organization, status: 'active');
    Organization::factory()->create();               // Owner 不在だが課金なし

    $handler = spyOnExceptionHandler();

    $this->artisan('billing:detect-orphan-billing-organizations')
        ->expectsOutputToContain('課金孤児なし')
        ->assertExitCode(0);

    $handler->shouldNotHaveReceived('report');
});

test('Owner 不在かつ課金中の組織は集約して 1 回だけ report される', function (): void {
    $orphanA = Organization::factory()->create();
    createFakeSubscription($orphanA, status: 'active');
    $orphanB = Organization::factory()->create();
    createFakeSubscription($orphanB, status: 'past_due');

    $handler = spyOnExceptionHandler();

    $this->artisan('billing:detect-orphan-billing-organizations')->assertExitCode(0);

    $handler->shouldHaveReceived('report')->once();
});

test('report の内容は件数と organization id のみで PII を含まない', function (): void {
    $orphan = Organization::factory()->create(['name' => '秘密の現場']);
    createFakeSubscription($orphan, status: 'active');

    $handler = spyOnExceptionHandler();

    $this->artisan('billing:detect-orphan-billing-organizations')->assertExitCode(0);

    $handler->shouldHaveReceived('report')
        ->once()
        ->withArgs(function (Throwable $exception) use ($orphan): bool {
            $message = $exception->getMessage();

            return str_contains($message, 'count=1')
                && str_contains($message, (string) $orphan->id)
                && ! str_contains($message, '秘密の現場');
        });
});

test('Owner 不在でも解約予約済み (ends_at あり) の組織は report されない', function (): void {
    $organization = Organization::factory()->create();
    $subscription = createFakeSubscription($organization, status: 'active');
    $subscription->forceFill(['ends_at' => now()->addDays(10)])->save();

    $handler = spyOnExceptionHandler();

    $this->artisan('billing:detect-orphan-billing-organizations')->assertExitCode(0);

    $handler->shouldNotHaveReceived('report');
});
