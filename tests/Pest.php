<?php

declare(strict_types=1);

use App\DataTransferObjects\Recovery\StreamSweepResultDto;
use App\Enums\OrganizationRole;
use App\Enums\ProjectRole;
use App\Enums\Recovery\RecoveryOutcome;
use App\Enums\Recovery\RecoveryStream;
use App\Models\ApiKey;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Providers\BughuntFakesServiceProvider;
use App\Services\AI\Testing\CannedPromptFakeRegistrar;
use App\Services\Billing\PersonalPlanService;
use App\Services\Organization\OrganizationProvisioningService;
use App\Services\Recovery\StuckWorkRecoverySweeper;
use App\Services\Recovery\StuckWorkStreamRegistry;
use App\Services\Storage\Fakes\FakeObjectStore;
use App\Support\ExternalFakes\ExternalFakeDeclaration;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\Str;
use Kent013\PrismPrompt\Prompt;
use Laravel\Cashier\Subscription;
use Tests\Support\Cache\PlainDataCacheGuard;
use Tests\Support\StrayHttpRequestGuard;
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

        // 未 fake の外向き HTTP を fail-fast させる guard (裁定 AG-105)。
        // レーン既定として Http::preventStrayRequests() を常時 ON にし、
        // 自機宛て loopback だけを Http::allowStrayRequests([...]) で明示許可する。
        // テスト本体で Http::fake([...]) を呼ぶと該当 URL は透過する
        // (Factory::fake() は prevent フラグを reset しないため共存する)。
        StrayHttpRequestGuard::install($this->app);

        // キャッシュ guard は Tests\TestCase::createApplication() の bootstrap 前に結線済み。
        // ここでは**結線が効いていること**だけを確認する (accumulator には触らない。
        // 触ると起動中に記録された違反が消える)。
        PlainDataCacheGuard::assertInstalled($this->app);
    })
    ->afterEach(function (): void {
        try {
            // stray call が記録されていれば test を fail させる (Service 層の
            // try/catch fallback で guard 例外が握り潰されてもここで必ず赤くなる)
            //
            // ★3 つの guard は順に flush する。**同時発生時は先に throw した guard の
            //   詳細だけが表示される** (他方の accumulator は finally の reset で
            //   捨てられる)。test は既に赤いので「静かに緑」にはならず、検出目的は達成される。
            //   すべてを集約する仕組みは入れない (今必要なものだけ作る)。
            StrayLlmCallGuard::flushAndFailIfStray();
            StrayHttpRequestGuard::flushAndFailIfStray();
            PlainDataCacheGuard::flushAndFailIfStray();
        } finally {
            // flush が throw しても次テストへ accumulator / Prompt::$fake を漏らさない
            if (Prompt::isFaking()) {
                Prompt::stopFaking();
            }
            StrayLlmCallGuard::reset();
            StrayHttpRequestGuard::reset();
            PlainDataCacheGuard::reset();
        }
    })
    ->in('Feature', 'Unit');

/*
| Architecture lane はファイル走査中心で DB を使わないが、HTTP 出口の既定拒否は
| **全レーン一律**にする (レーンごとに既定が違うと「どのレーンなら外へ出られるか」を
| 覚える必要が生まれ、gate も分岐だらけになる)。Tests\TestCase は
| Illuminate\Foundation\Testing\TestCase 継承で Laravel app 上を走るため install できる。
*/
pest()->extend(TestCase::class)
    ->beforeEach(function (): void {
        $this->withoutVite();
        StrayHttpRequestGuard::install($this->app);
        PlainDataCacheGuard::assertInstalled($this->app);
    })
    ->afterEach(function (): void {
        try {
            StrayHttpRequestGuard::flushAndFailIfStray();
            PlainDataCacheGuard::flushAndFailIfStray();
        } finally {
            StrayHttpRequestGuard::reset();
            PlainDataCacheGuard::reset();
        }
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

        // in-process サーバ (Amp) はテストプロセス自身の HttpKernel / container を使うため、
        // ブラウザ経由リクエストの処理中に出る Laravel HTTP client の呼び出しにも効く。
        // in-process サーバは常に 127.0.0.1 に bind するので、許可パターンの loopback
        // リテラルで自機宛ては通る。
        // ★ただし Playwright のブラウザ自身が出す外部フォント / CDN 取得は**捕捉できない**
        //   (別プロセスのため)。保証範囲は docs/testing-browser.md に明記している。
        StrayHttpRequestGuard::install($this->app);

        // Browser lane は Prompt を常時 canned fake 化する (SystemMessage signature 別の
        // 決定論応答。未登録の Prompt から呼ばれると fail-fast)。canned PromptFake は
        // Browser lane と bughunt 実行時の両方で共有 (registrar 参照)。install() 内の
        // stopFaking の後に上書きインストールするのが load-bearing。
        app(CannedPromptFakeRegistrar::class)->install();

        PlainDataCacheGuard::assertInstalled($this->app);
    })
    ->afterEach(function (): void {
        try {
            StrayLlmCallGuard::flushAndFailIfStray();
            StrayHttpRequestGuard::flushAndFailIfStray();
            PlainDataCacheGuard::flushAndFailIfStray();
        } finally {
            if (Prompt::isFaking()) {
                Prompt::stopFaking();
            }
            StrayLlmCallGuard::reset();
            StrayHttpRequestGuard::reset();
            PlainDataCacheGuard::reset();
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
 * 既定では grandfathering backfill 相当 (`free_plan_code='personal'` / declarer NULL) を付与し、
 * 課金ゲート (require-active-subscription) を `ActiveFreePlan` で通る既存組織を再現する。
 * PersonalPlanService::activate() は**呼ばない** (signup grant が発火して残高期待が壊れ、
 * declarer の partial unique index にも触れるため)。
 *
 * `$grandfatherFreePlan: false` は真の未契約組織 (free_plan_code NULL = 業務 route が
 * onboarding へ遮断される) を作る。ゲート / onboarding のテストで使う。
 * 有償プラン契約状態を検証するテストは contractPaidPlan() を併用する。
 *
 * @return array{Organization, User} [organization, owner]
 */
function createOrganizationWithOwner(string $name = 'テスト組織', bool $grandfatherFreePlan = true): array
{
    $owner = User::factory()->create();
    $organization = app(OrganizationProvisioningService::class)->provision($owner, $name);

    if ($grandfatherFreePlan) {
        $organization->forceFill([
            'free_plan_code' => PersonalPlanService::FREE_PLAN_CODE,
            'free_plan_activated_at' => CarbonImmutable::now(),
        ])->save();
    }

    return [$organization, $owner];
}

/**
 * 滞留回収を 1 系列ぶん実行する (実際に回収する指定)。
 *
 * 定期実行と同じ経路 (registry → sweeper → stream) を通すので、テストが
 * 系列ごとの内部実装ではなく**運用で実際に走る形**を叩くことになる。
 */
function sweepStuckWorkStream(RecoveryStream $stream): StreamSweepResultDto
{
    return app(StuckWorkRecoverySweeper::class)->sweep(
        app(StuckWorkStreamRegistry::class)->get($stream),
        apply: true,
    );
}

/** 滞留した解析ジョブを回収し、実際に前へ進めた件数を返す */
function recoverStaleAnalysisJobs(): int
{
    return sweepStuckWorkStream(RecoveryStream::AnalysisJob)->count(RecoveryOutcome::Recovered);
}

/** 滞留したレンダジョブを回収し、実際に前へ進めた件数を返す */
function recoverStaleRenderJobs(): int
{
    return sweepStuckWorkStream(RecoveryStream::RenderJob)->count(RecoveryOutcome::Recovered);
}

/** 期限切れのチケット予約を解放し、実際に解放した件数を返す */
function releaseStaleTicketReservations(): int
{
    return sweepStuckWorkStream(RecoveryStream::TicketReservation)->count(RecoveryOutcome::Recovered);
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

/**
 * storage fake を有効化する (Feature テスト用)。
 *
 * config('testing.fake_storage')=true にした上で **provider 自身を再実走** させ、
 * bind と signed route を確立する (手動 bind/route 再実装は provider の欠陥を隠すため禁止)。
 * app env は phpunit.xml の testing + runningUnitTests()===true のため FakeStorageGate が成立する。
 * s3_fake disk は Storage::fake で tmp へ隔離し、実 s3 disk は放置 =
 * もし実 S3 に触れたら即例外になる (fake が実 S3 非依存であることの negative 担保)。
 *
 * 各テストは setUp の refreshApplication で fresh app + fresh config を得るため、
 * 明示的な env/config の後始末は不要 (テスト間リークしない)。
 */
function enableFakeStorage(): void
{
    config()->set(ExternalFakeDeclaration::STORAGE_FLAG, true);

    $provider = new BughuntFakesServiceProvider(app());
    $provider->register();
    $provider->boot();
    // provider の register()/boot() は本来 bootstrap 時に走り、フレームワークが route 読込後に
    // name lookup を再構築する。テストでは boot を後追いで実行するため、その最終手順のみ明示的に補う
    // (route 自体は provider が登録済み = 配線ロジックは provider を実走して検証している)。
    app('router')->getRoutes()->refreshNameLookups();

    Storage::fake(FakeObjectStore::DISK);
}

/**
 * 宣言された外部サービスの偽物 (決済 gateway / 人間性確認 / 外部ログインの解決点) を
 * レーンで有効にする。
 *
 * ★レーン側で個別の偽物を container へ直接結ばない。差し替えの入口は
 *   「宣言 (ExternalFakeDeclaration) + 配線 provider」の 1 本だけであり、
 *   レーンもその 1 本を共有する (LaneExternalFakeBindingTest が直結を静的に禁じる)。
 * ★有効になるのは宣言のうち EXTERNALS_FLAG を持つ差し替え**全部**である
 *   (1 つだけ選んで立てる口は用意しない = 宣言と実際の差し替えを一致させるため)。
 *
 * 各テストは setUp の refreshApplication で fresh app + fresh config を得るため、
 * 明示的な後始末は不要 (テスト間リークしない)。
 */
function enableFakeExternals(): void
{
    config()->set(ExternalFakeDeclaration::EXTERNALS_FLAG, true);

    (new BughuntFakesServiceProvider(app()))->register();
}
