<?php

declare(strict_types=1);

use App\Enums\CheckoutIntent;
use App\Enums\OrganizationRole;
use App\Models\Billing\BillingCheckoutSession;
use App\Services\Billing\AutoRechargeService;
use App\Services\Billing\Contracts\AutoRechargeGatewayInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\FakeAutoRechargeGateway;

/*
|--------------------------------------------------------------------------
| startSetupCheckout の unique 握りは「書きたかった行が既に在る」ときだけ
|--------------------------------------------------------------------------
|
| 旧実装は SQLSTATE (23505 / 23000) だけを見て握っていたため、**別の unique 制約**の
| 違反も「同一 attempt_token の replay」として黙って正常終了していた
| (= Stripe session はあるのに台帳行が無い状態が成功として通る)。
|
| 制約名 ($e->index) では判定しない。正規 replay では 3 本の unique
| (org+intent+attempt_token / idempotency_key / stripe_session_id) が**同時に**違反し、
| PostgreSQL が報告する 1 本は index の作成順 (OID 昇順) で決まるためである
| (詳細設計 E-7 の実測)。代わりに**自然キーで既存行を読み直して同一性を確認**する。
*/

beforeEach(function (): void {
    $this->gateway = new FakeAutoRechargeGateway;
    app()->instance(AutoRechargeGatewayInterface::class, $this->gateway);
});

test('別の unique 制約 (stripe_session_id) の違反は握り潰さず再送出する', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $service = app(AutoRechargeService::class);
    $token = strtolower((string) Str::ulid());

    // 1 回目: 正規に台帳行を作る (session id S は idempotency key から決定的に導出される)
    $service->startSetupCheckout($organization, $owner, 'https://ok.test', 'https://ng.test', $token);
    $row = BillingCheckoutSession::query()->sole();

    // 2 回目が「stripe_session_id **だけ**衝突する」状況を作る:
    //   既存行の attempt_token / idempotency_key を別値へ退避し、stripe_session_id は S のまま残す。
    //   → 同じ $token で再実行すると (org, intent, attempt_token) と idempotency_key は衝突せず、
    //      stripe_session_id **1 本だけ**が違反する (E-7 の同時違反問題を踏まない)。
    // fake の導出式をテストへ写さないため、S は 1 回目の実行に作らせている。
    DB::table('billing_checkout_sessions')->where('id', $row->id)->update([
        'attempt_token' => strtolower((string) Str::ulid()),
        'idempotency_key' => 'unrelated:'.strtolower((string) Str::ulid()),
    ]);

    expect(fn () => $service->startSetupCheckout($organization, $owner, 'https://ok.test', 'https://ng.test', $token))
        ->toThrow(UniqueConstraintViolationException::class);
});

test('同一 attempt_token の replay は例外を漏らさず結果を返し行も増えない', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $service = app(AutoRechargeService::class);
    $token = strtolower((string) Str::ulid());

    $first = $service->startSetupCheckout($organization, $owner, 'https://ok.test', 'https://ng.test', $token);
    $second = $service->startSetupCheckout($organization, $owner, 'https://ok.test', 'https://ng.test', $token);

    // 成功時の振る舞いを変えていないことの基準 (fail-closed 化で replay を壊していない)
    expect($second['id'])->toBe($first['id']);
    expect(BillingCheckoutSession::query()->count())->toBe(1);
});

test('既存行の stripe_session_id が今回の値と食い違うなら replay として握らない', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $service = app(AutoRechargeService::class);
    $token = strtolower((string) Str::ulid());

    $service->startSetupCheckout($organization, $owner, 'https://ok.test', 'https://ng.test', $token);
    $row = BillingCheckoutSession::query()->sole();

    // 自然キー (org, intent, attempt_token) では**見つかる**が、内容が食い違う状態を作る。
    // = 台帳が壊れている状態。これを replay として飲むと障害が正常終了として通る。
    DB::table('billing_checkout_sessions')->where('id', $row->id)->update([
        'stripe_session_id' => 'cs_setup_tampered_'.strtolower((string) Str::ulid()),
    ]);

    expect(fn () => $service->startSetupCheckout($organization, $owner, 'https://ok.test', 'https://ng.test', $token))
        ->toThrow(UniqueConstraintViolationException::class);
});

test('同一 org の別 billing 管理者が同じ attempt_token を送っても replay として握る (actor は問わない)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    // 2 人目にも実際に manageBilling を持たせる (= 両者とも認可済みという設計根拠を固定するため
    // Service 直呼びではなく Controller 経由で叩く)
    $secondManager = attachOrganizationMember($organization, OrganizationRole::Admin);
    $secondManager->forceFill(['current_organization_id' => $organization->id])->save();

    $token = strtolower((string) Str::ulid());

    // 前段が失敗していると後段の失敗として見えてしまうので、1 回目も着地まで固定する
    $this->actingAs($owner)
        ->post('/billing/auto-recharge/setup', ['attempt_token' => $token])
        ->assertRedirect($this->gateway->setupUrl);

    // 同一性判定に initiated_by_user_id を**入れない**契約 (詳細設計「保証しないもの」§14)。
    // 入れると benign なこの replay が 500 になる = 契約が load-bearing であることの固定。
    $this->actingAs($secondManager)
        ->post('/billing/auto-recharge/setup', ['attempt_token' => $token])
        ->assertRedirect($this->gateway->setupUrl); // 500 にならず checkout へ送られる

    $sessions = BillingCheckoutSession::query()
        ->where('organization_id', $organization->id)
        ->where('intent', CheckoutIntent::SetupPaymentMethod->value)
        ->get();

    // 行は増えず、initiated_by_user_id は先行ユーザーのまま (attempt を起こした記録として正しい)
    expect($sessions)->toHaveCount(1)
        ->and($sessions->firstOrFail()->initiated_by_user_id)->toBe($owner->id);
});
