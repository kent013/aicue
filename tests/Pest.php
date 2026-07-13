<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Enums\ProjectRole;
use App\Models\ApiKey;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\AI\Testing\CannedPromptFakeRegistrar;
use App\Services\Organization\OrganizationProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\Str;
use Kent013\PrismPrompt\Prompt;
use Laravel\Cashier\Subscription;
use Tests\Support\StrayLlmCallGuard;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Feature / Unit は TestCase + RefreshDatabase。
| Architecture はファイル走査中心のため DB を使わない (TestCase のみ)。
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function (): void {
        // Vite manifest 不在でも view が描画できるよう test では Vite をスタブする
        $this->withoutVite();

        // 未 fake の LLM 呼び出しを fail-fast させる guard。
        // (1) accumulator clear → (2) Prompt::stopFaking() → (3) PrismManager 差し替え
        // の 3 段で前テスト残留状態を一掃しつつ install する。テスト本体で
        // Prism::fake([...]) / Prompt::fake([...]) を呼ぶと guard は透過される。
        // Prism 基盤を直接テストする稀な Unit テストのみ
        // StrayLlmCallGuard::uninstallForTest($this->app) で opt-out できる。
        StrayLlmCallGuard::install($this->app);
    })
    ->afterEach(function (): void {
        try {
            // stray call が記録されていれば test を fail させる (Service 層の
            // try/catch fallback で guard 例外が握り潰されてもここで必ず赤くなる)
            StrayLlmCallGuard::flushAndFailIfStray();
        } finally {
            // flush が throw しても次テストへ accumulator / Prompt::$fake を漏らさない
            if (Prompt::isFaking()) {
                Prompt::stopFaking();
            }
            StrayLlmCallGuard::reset();
        }
    })
    ->in('Feature', 'Unit');

pest()->extend(TestCase::class)
    ->beforeEach(function (): void {
        $this->withoutVite();
    })
    ->in('Architecture');

/*
| Browser lane (pest-plugin-browser / Playwright)。phpunit.browser.xml +
| scripts/run-browser-test.sh (composer test:browser) 経由でのみ動く
| (既定 phpunit.xml の testsuite には含まれない)。in-process サーバが
| テストプロセス自身の HttpKernel を叩くため TestCase + RefreshDatabase で動く。
| 詳細は docs/testing-browser.md。
*/
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function (): void {
        // Browser は実ブラウザが public/build のビルド済アセットを読むため
        // withoutVite() は絶対に適用しない (pnpm build 前提)。代わりに、dev の
        // vite dev server が出す public/hot を読んで白画面になる事故を防ぐため
        // hot file の参照先を存在しないパスへ逃がす。
        Vite::useHotFile(storage_path('framework/testing/vite-hot-disabled'));

        // Feature/Unit と同じ stray LLM guard を適用する (in-process サーバの
        // リクエスト処理はテストプロセス内で走るため、未 fake の LLM 呼び出しは
        // accumulator に記録され afterEach で fail する)。
        StrayLlmCallGuard::install($this->app);

        // Browser lane は Prompt を常時 canned fake 化する (SystemMessage signature 別の
        // 決定論応答。未登録の Prompt から呼ばれると fail-fast)。canned PromptFake は
        // Browser lane と bughunt 実行時の両方で共有 (registrar 参照)。install() 内の
        // stopFaking の後に上書きインストールするのが load-bearing。
        app(CannedPromptFakeRegistrar::class)->install();
    })
    ->afterEach(function (): void {
        try {
            StrayLlmCallGuard::flushAndFailIfStray();
        } finally {
            if (Prompt::isFaking()) {
                Prompt::stopFaking();
            }
            StrayLlmCallGuard::reset();
        }
    })
    ->in('Browser');

/*
|--------------------------------------------------------------------------
| Expectations / Functions
|--------------------------------------------------------------------------
|
| カスタム expectation・ヘルパ関数はここに追加する。
|
*/

/**
 * Owner 付きの組織を provisioning 経由で生成する (Default Team 込み)。
 * owner の current_organization_id はこの組織になる。
 *
 * 生成される組織は Free (未契約 = plan_code null) — 業務 route は free でも通る
 * (BillingAccess の entitlement 判定)。有償プラン契約状態を検証するテストは
 * contractPaidPlan() を併用する (RequireActiveSubscriptionMiddlewareTest 参照)。
 *
 * @return array{Organization, User} [organization, owner]
 */
function createOrganizationWithOwner(string $name = 'テスト組織'): array
{
    $owner = User::factory()->create();
    $organization = app(OrganizationProvisioningService::class)->provision($owner, $name);

    return [$organization, $owner];
}

/**
 * recent-auth (step-up) を確実に満たす fresh session 値。
 * 窓は config('auth.recent_auth_timeout')(既定 900s)。注入時点の elapsed≈0 で窓に対し十分 fresh。
 * recent-auth を要する route を「step-up 済み相当」で叩くテストは withSession() でこれを注入する。
 *
 * @return array{recent_auth_at: int}
 */
function freshRecentAuthSession(): array
{
    return ['recent_auth_at' => now()->timestamp];
}

/**
 * 組織を有償プラン契約状態にする (plan_code + Cashier subscription 行)。
 * plan_code は $fillable 外の状態キー (webhook 同期のみ) のため forceFill で明示代入。
 * BillingAccess は plan_code 非 null の組織にのみ active/trialing subscription を要求する。
 *
 * plan_code は PlanSeeder が投入する有償プラン code ('standard') を使う
 * (プラン名分岐ではなく seeded fixture の参照。アプリコードには入らない)。
 */
function contractPaidPlan(Organization $organization, string $status = 'active'): Subscription
{
    $organization->forceFill(['plan_code' => 'standard'])->save();

    return createFakeSubscription($organization, status: $status);
}

/**
 * テスト用の Cashier subscription 行を直接作成する (Stripe には到達しない)。
 * BillingAccess (課金ゲート) は plan_code 非 null の組織に対して stripe_status が
 * active / trialing のとき許可する (plan_code null = free tier は行の有無に依らず許可)。
 */
function createFakeSubscription(
    Organization $organization,
    string $status = 'active',
    string $type = 'default',
): Subscription {
    /** @var Subscription $subscription */
    $subscription = $organization->subscriptions()->create([
        'type' => $type,
        'stripe_id' => 'sub_test_'.Str::random(24),
        'stripe_status' => $status,
        'quantity' => 1,
    ]);

    return $subscription;
}

/**
 * 組織にメンバーを追加する (attach + laratrust_team_id 明示のロール付与)。
 */
function attachOrganizationMember(
    Organization $organization,
    OrganizationRole $role = OrganizationRole::Member,
): User {
    $user = User::factory()->create();
    $organization->users()->attach($user);
    $user->addRole($role->value, $organization->laratrust_team_id);

    return $user;
}

/**
 * 組織スコープの API キーを発行する (REST API / MCP テスト用。平文付きで返す)。
 *
 * @param  list<string>  $abilities
 * @return array{ApiKey, string} [apiKey, plainKey]
 */
function issueApiKey(
    Organization $organization,
    User $createdBy,
    array $abilities = ['read', 'write'],
    string $name = 'テストキー',
): array {
    $generated = ApiKey::generatePlainKey();
    $apiKey = ApiKey::createForOrganization(
        $organization,
        $createdBy,
        $name,
        $abilities,
        $generated['prefix'],
        ApiKey::hashSecret($generated['secret']),
    );

    return [$apiKey, $generated['plain']];
}

/**
 * プロジェクトにメンバーを追加する (project_members pivot にロール付きで attach)。
 * プロジェクトロールは組織メンバーであることが前提 (Policy 側でも組織所属を確認する)。
 */
function attachProjectMember(
    Project $project,
    User $user,
    ProjectRole $role = ProjectRole::Member,
): void {
    $project->members()->attach($user, ['role' => $role->value]);
}
