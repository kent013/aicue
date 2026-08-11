<?php

declare(strict_types=1);

use App\DataTransferObjects\Billing\AutoRechargeConsentDto;
use App\Enums\Billing\AutoRechargeAttemptStatus;
use App\Models\Billing\TicketAutoRechargeAttempt;
use App\Models\Organization;
use App\Models\User;
use App\Services\Billing\AutoRechargeService;
use App\Services\Billing\Contracts\AutoRechargeGatewayInterface;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\FakeAutoRechargeGateway;

/*
|--------------------------------------------------------------------------
| ShouldBeUnique 撤去後の「結果の一回性」 (AG-114 確定 1 / AGENTS.md ドメイン規約 6)
|--------------------------------------------------------------------------
|
| 入口排他 (ShouldBeUnique) は撤去された。一回性を担うのは 3 点:
|  (1) maybeCreateAttempt の organizations 行ロック + pending 存在検査
|  (2) tar_attempts_org_pending_unique (partial unique) — DB の最終防衛
|  (3) unique violation の no-op 収束 (呼び出し側へ例外を漏らさない)
|
| ★ (3) の判定は当初 SQLSTATE だけを見て制約名を識別しなかった (T140)。
|   現在は期待制約 tar_attempts_org_pending_unique **1 本だけ**を握り、それ以外は再送出する
|   (fail-closed)。下の attempt_ulid テストがその境界を固定する。
*/

beforeEach(function (): void {
    // ★ 実 jobs 表へ積むだけの構成に固定する。sync レーン (after_commit=true) のままだと
    //   起票と同一 tx で投入された ExecuteAutoRechargeAttemptJob が commit 直後に
    //   インライン実行され、attempt が pending から動いてしまう
    //   (「pending があるから 2 件目は no-op」を見ているつもりが別要因で緑になる偽グリーン)。
    config()->set('queue.default', 'database');
    $this->gateway = new FakeAutoRechargeGateway;
    app()->instance(AutoRechargeGatewayInterface::class, $this->gateway);
});

/**
 * 閾値割れ + enabled な組織。
 *
 * @return array{Organization, User}
 */
function attemptUniquenessContext(): array
{
    [$organization, $owner] = createOrganizationWithOwner();
    /** @var FakeAutoRechargeGateway $gateway */
    $gateway = app(AutoRechargeGatewayInterface::class);
    $gateway->withDefaultPaymentMethod();
    app(AutoRechargeService::class)->updateSettings(
        $organization,
        $owner,
        enabled: true,
        threshold: 5,
        max: 50,
        consent: new AutoRechargeConsentDto(config()->string('billing.auto_recharge.consent_version')),
    );

    return [$organization, $owner];
}

test('pending attempt があるとき maybeCreateAttempt は null を返し attempt が増えない', function (): void {
    [$organization] = attemptUniquenessContext();

    $first = app(AutoRechargeService::class)->maybeCreateAttempt($organization);
    expect($first)->not->toBeNull();
    expect(TicketAutoRechargeAttempt::query()->count())->toBe(1);

    $second = app(AutoRechargeService::class)->maybeCreateAttempt($organization->refresh());

    expect($second)->toBeNull();
    expect(TicketAutoRechargeAttempt::query()->count())->toBe(1);
    // 1 件目が pending のまま残っていること (= no-op の理由が pending 検査であること) まで固定する
    expect(TicketAutoRechargeAttempt::query()->firstOrFail()->status)
        ->toBe(AutoRechargeAttemptStatus::Pending);
});

test('同一 org の 2 件目の pending 行は tar_attempts_org_pending_unique が拒否する', function (): void {
    [$organization] = attemptUniquenessContext();
    $first = app(AutoRechargeService::class)->maybeCreateAttempt($organization);
    expect($first)->not->toBeNull();

    // pending 検査を迂回して直接 INSERT する = DB 制約が最終防衛であることの固定。
    // ★ PostgreSQL は失敗した文でトランザクション全体を abort させるため、
    //   savepoint (ネストした DB::transaction) の中で起こして外側を巻き込まない。
    expect(fn () => DB::transaction(fn () => DB::table('ticket_auto_recharge_attempts')->insert([
        'organization_id' => $organization->getKey(),
        'attempt_ulid' => strtolower((string) Str::ulid()),
        'status' => AutoRechargeAttemptStatus::Pending->value,
        'quantity' => 10,
        'unit_amount' => 70,
        'stripe_price_id' => 'price_test',
        'created_at' => now(),
        'updated_at' => now(),
    ])))->toThrow(QueryException::class);

    expect(TicketAutoRechargeAttempt::query()->count())->toBe(1);
});

test('unique violation は no-op へ収束し呼び出し側へ例外が漏れない', function (): void {
    [$organization] = attemptUniquenessContext();

    // pending 検査の**後**・INSERT の**直前**に別経路で pending 行が生まれた状況
    // (= 並行起票の敗者側) を模す。DB::table は model event を発火しないため再入しない。
    TicketAutoRechargeAttempt::creating(function () use ($organization): void {
        DB::table('ticket_auto_recharge_attempts')->insert([
            'organization_id' => $organization->getKey(),
            'attempt_ulid' => strtolower((string) Str::ulid()),
            'status' => AutoRechargeAttemptStatus::Pending->value,
            'quantity' => 10,
            'unit_amount' => 70,
            'stripe_price_id' => 'price_race',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    $result = app(AutoRechargeService::class)->maybeCreateAttempt($organization);

    // 例外は漏れず null に収束し、tx ごと巻き戻るため attempt 行も残らない
    expect($result)->toBeNull();
    expect(TicketAutoRechargeAttempt::query()->count())->toBe(0);
});

test('別の unique 制約 (attempt_ulid) の違反は no-op へ収束させず再送出する', function (): void {
    [$organization] = attemptUniquenessContext();
    [$otherOrganization] = createOrganizationWithOwner();

    // pending 検査の**後**・INSERT の**直前**に、**別 org**で**同じ attempt_ulid** の行を作る。
    // 別 org なので部分 unique (org 単位) には触れず、attempt_ulid unique **だけ**が違反する。
    // DB::table は model event を発火しないため再入しない。
    TicketAutoRechargeAttempt::creating(function (TicketAutoRechargeAttempt $attempt) use ($otherOrganization): void {
        DB::table('ticket_auto_recharge_attempts')->insert([
            'organization_id' => $otherOrganization->getKey(),
            'attempt_ulid' => $attempt->attempt_ulid,   // ← 衝突させたい 1 本
            'status' => AutoRechargeAttemptStatus::Pending->value,
            'quantity' => 10,
            'unit_amount' => 70,
            'stripe_price_id' => 'price_other',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    // 握ると AutoRechargeTriggerJob が structured no-op として黙り、その組織のリチャージが
    // 起票されないまま誰も気づかない。期待制約以外は fail-closed で再送出する。
    expect(fn () => app(AutoRechargeService::class)->maybeCreateAttempt($organization))
        ->toThrow(UniqueConstraintViolationException::class);
});
