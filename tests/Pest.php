<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Enums\ProjectRole;
use App\Models\ApiKey;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\AI\Testing\BrowserPromptFakeRegistrar;
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

        // Browser lane は Prompt を常時 canned fake 化する (クラス別の決定論応答。
        // 未登録の Prompt から呼ばれると fail-fast)。install() 内の stopFaking の
        // 後に上書きインストールするのが load-bearing。
        app(BrowserPromptFakeRegistrar::class)->install();
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
 * 業務 route (/projects 等) は require-active-subscription で gate されるため、
 * 既定で active な default subscription を持たせる。未契約状態を検証するテストは
 * `subscribed: false` で生成する (RequireActiveSubscriptionMiddlewareTest 参照)。
 *
 * @return array{Organization, User} [organization, owner]
 */
function createOrganizationWithOwner(string $name = 'テスト組織', bool $subscribed = true): array
{
    $owner = User::factory()->create();
    $organization = app(OrganizationProvisioningService::class)->provision($owner, $name);

    if ($subscribed) {
        createFakeSubscription($organization);
    }

    return [$organization, $owner];
}

/**
 * テスト用の Cashier subscription 行を直接作成する (Stripe には到達しない)。
 * BillingAccess (課金ゲート) は stripe_status が active / trialing のとき許可する。
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
