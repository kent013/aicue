# 詳細設計: bugfix-bughunt-infra (bug-hunt 基盤整備: F-05 Stripe fake 配線 / F-13 Filament アセット / seeder subscription)

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

### 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)

### コーディングルール

- **PHPStan level 10** 必須（`composer phpstan`）
- **Pest**テストフレームワーク（`composer test`）
- **RefreshDatabase** + `--parallel` 並列実行（`tests/Pest.php` でグローバル適用、個別 `DatabaseTransactions` 使用禁止）
- **テストデータは必ずFactoryで生成**（`Model::create()` 手組み禁止。ただし Cashier subscription 行は
  Factory が存在せず、既存の正規手法が `tests/Pest.php::createFakeSubscription()`（billable relation
  経由の create）であるため、同流儀に従う）
- **DTO + JsonResource** パターン（AGENTS.md参照）
- **アーリーリターン** 推奨
- **コードフォーマット**: `composer fix`（Pint）/ `pnpm lint:fix`
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- `declare(strict_types=1)` + 日本語コメント。保護キーは forceFill / relation で明示代入

## 概念設計リファレンス

`devnotes/20260712-0949-bugfix-bughunt-infra/conceptual-design.md`（Round 3 で APPROVED）

- 施策群 A: external fake wiring（A1〜A3）
- 施策群 B: bughunt billing fixtures（B1）
- 施策群 C: admin/assets provisioning（C1〜C2）
- 実装順序: A → B → C（B/C は A1 の config 新設に依存。各施策は fail するテストを先に置く）

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| A1 | fake externals capability flag の正式導入 | `config/testing.php`（新規）、`app/Support/ProductionEnvGuard.php`、`.env.bughunt.local.example`、`tests/Feature/Support/ProductionEnvGuardTest.php` | 高 |
| A2 | サブスク checkout / portal の gateway 抽象化 | `app/DataTransferObjects/Billing/ExternalBillingRedirect.php`（新規）、`app/Services/Billing/SubscriptionCheckoutGateway.php`（新規）、`app/Services/Billing/CashierSubscriptionCheckoutGateway.php`（新規）、`app/Http/Controllers/Billing/BillingController.php`、`app/Providers/AppServiceProvider.php` | 高 |
| A3 | fake 実装と条件付き bind（FakeExternalsServiceProvider） | `app/Services/Billing/Fakes/FakeTicketCheckoutGateway.php`（新規）、`app/Services/Billing/Fakes/FakeSubscriptionCheckoutGateway.php`（新規）、`app/Services/Billing/Fakes/FakeExternalUrl.php`（新規）、`app/Providers/FakeExternalsServiceProvider.php`（新規）、`bootstrap/providers.php`、`tests/Feature/Providers/FakeExternalsServiceProviderTest.php`（新規）、`tests/Unit/Billing/FakeTicketCheckoutGatewayTest.php`（新規）、`tests/Feature/Billing/BillingPageTest.php`、`tests/Feature/Billing/TicketCheckoutTest.php` | 高 |
| B1 | BughuntBillingSeeder（standard 組織のみ active sub + 初期チケット） | `database/seeders/Concerns/DetectsBughuntDatabase.php`（新規 trait）、`database/seeders/BughuntBillingSeeder.php`（新規）、`database/seeders/BughuntOAuthSeeder.php`（regex を trait へ集約）、`scripts/bug-hunt-shard.sh`（seed 列）、`tests/Feature/Database/BughuntBillingSeederTest.php`（新規）、`tests/Feature/Database/BughuntOAuthSeederGuardTest.php`（新規） | 高 |
| C1 | AdminUserSeeder の bughunt.local 対応（DB 名 guard 付き） | `database/seeders/AdminUserSeeder.php`、`tests/Feature/Admin/AdminUserSeederTest.php` | 中 |
| C2 | provision への Filament アセット publish（冪等） | `scripts/bug-hunt-shard.sh` | 中 |

---

## 施策 A1: fake externals capability flag の正式導入

### 変更箇所

- `config/testing.php`（新規）
- `app/Support/ProductionEnvGuard.php`（`violations()` に 1 項目追加、docblock 追記）
- `.env.bughunt.local.example`（L52-55 のコメント更新: 「fake 基盤未導入」の記述を現状に合わせる）
- `tests/Feature/Support/ProductionEnvGuardTest.php`（baseline + 新テスト）

### 波及変更

- TypeScript型定義: なし
- API Resource/DTO: なし
- テストファイル: `ProductionEnvGuardTest.php` の `beforeEach` baseline に
  `config(['testing.fake_externals' => false])` を追加（既存テストの独立性維持）
- **挙動変更の明示**: 本 config 新設により既存 `BughuntOAuthSeeder` の第 1 ガードが bughunt 環境で
  成立し始める（意図された点火。三重 guard により bughunt DB 外では従来どおり no-op。
  B1 で guard no-op 回帰テストを追加して固定する）

### 現行コード

`config/testing.php` は存在しない。`config('testing.fake_externals')` は常に null。

```php
// app/Support/ProductionEnvGuard.php (抜粋: DEBUG_LOGIN 検査の直後、TrustHosts 検査の前)
        if ($debugUser !== '' || $debugPassword !== '') {
            $errors[] = 'DEBUG_LOGIN_USER and DEBUG_LOGIN_PASSWORD must be empty in production '
                .'(both are local-dev only; presence indicates dangerous misconfiguration).';
        }
```

### 変更後コード

```php
// config/testing.php (新規)
<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | 外部サービス fake 化の capability flag
    |--------------------------------------------------------------------------
    |
    | true のとき FakeExternalsServiceProvider が外部サービス (Stripe) の
    | gateway を fake 実装に bind する (bughunt / local 検証用)。
    | 有効化は allowlist 環境 (local / testing / bughunt.local) に限定され、
    | production では ProductionEnvGuard が true を deploy 時 fail-fast で拒否する。
    | 既定 false = 本 flag 未設定の環境では完全 no-op。
    |
    */

    'fake_externals' => (bool) env('TESTING_FAKE_EXTERNALS', false),

];
```

```php
// app/Support/ProductionEnvGuard.php (violations() に追加。DEBUG_LOGIN 検査の直後)
        // 外部 fake flag は非本番専用。production で true なら課金 (Stripe) が fake に
        // 差し替わり得る危険設定のため fail-fast する (FakeExternalsServiceProvider の
        // allowlist で bind 自体は起きないが、設定として存在すること自体を拒否する)
        if (config('testing.fake_externals') === true) {
            $errors[] = 'TESTING_FAKE_EXTERNALS must be false in production '
                .'(external fakes must never be enabled in production).';
        }
```

docblock の検査項目リストにも `- TESTING_FAKE_EXTERNALS=false (外部 fake の本番混入防止)` を追記する。

```diff
# .env.bughunt.local.example (コメント更新のみ、値は不変)
 # 外部サービス (LLM/Stripe/Captcha/SSO 等) を fake 化する capability flag。
-# config('testing.fake_externals') を通して fake セットを有効化する前提
-# (fake 基盤が未導入のテンプレートでは各 fake は no-op。導入後に有効化される)。
+# config('testing.fake_externals') を通して fake セットを有効化する
+# (Stripe: FakeExternalsServiceProvider が checkout/portal gateway を fake に bind。
+#  fake は決済せず中立帰還する。課金状態の正本は BughuntBillingSeeder)。
 TESTING_FAKE_EXTERNALS=true
```

### PHPStan適合チェック

- [x] 戻り値の型が明示されている（`violations(): array` は既存シグネチャ不変）
- [x] null安全（`config() === true` の厳密比較のみ。mixed からの安全な絞り込み）
- [x] DTOを返している（該当なし: config/guard のみ）
- [x] Genericsの型パラメータが正しい（該当なし）

### テスト計画

- [ ] 先に fail するテスト: `ProductionEnvGuardTest` に
  「`testing.fake_externals=true` なら violation（メッセージに TESTING_FAKE_EXTERNALS を含む）」
  を追加 → guard 実装前は fail
- [ ] `beforeEach` baseline に `config(['testing.fake_externals' => false])` を追加し、
  「全項目充足で violations 空」テストが引き続き green であること
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認

### リスク

- config ファイル追加は他機能に影響しない（既定 false）。`BughuntOAuthSeeder` の点火は
  B1 の回帰テストで境界を固定する。
- config cache 運用: bughunt provision は `clear_stale_config` 済みのため cache 起因の
  値ズレは起きない。production は仮に cache 済みでも `ProductionEnvGuard` が起動時に
  実効 config 値 (`config('testing.fake_externals')`) を検査するため fail-fast が機能する。

---

## 施策 A2: サブスク checkout / portal の gateway 抽象化

### 変更箇所

- `app/DataTransferObjects/Billing/ExternalBillingRedirect.php`（新規 DTO）
- `app/Services/Billing/SubscriptionCheckoutGateway.php`（新規 interface）
- `app/Services/Billing/CashierSubscriptionCheckoutGateway.php`（新規実装。ロジックは
  `BillingController` からの移動のみ = 挙動不変）
- `app/Http/Controllers/Billing/BillingController.php`（`checkout()` / `portal()` を gateway 経由に）
- `app/Providers/AppServiceProvider.php`（bind 追加）

### 波及変更

- TypeScript型定義: なし（レスポンス形不変: `Inertia::location` のまま）
- API Resource/DTO: `ExternalBillingRedirect` 新規（内部 DTO。フロントへは露出しない）
- テストファイル: `BillingPageTest.php` は既存テスト無変更で green（認可・validation 失敗経路は
  gateway 到達前）。happy path テストは A3 で追加

### 現行コード

```php
// app/Http/Controllers/Billing/BillingController.php L83-94 (checkout)
        $checkout = $organization
            ->newSubscription('default', $price->stripe_price_id)
            ->checkout([
                'success_url' => route('billing.index'),
                'cancel_url' => route('billing.index'),
            ]);

        $url = $checkout->asStripeCheckoutSession()->url;
        Assert::string($url, 'Checkout Session に URL がありません (ui_mode: hosted のみ対応)');

        // 外部 URL への遷移は Inertia::location (full page redirect)
        return Inertia::location($url);

// 同 L98-110 (portal)
    public function portal(Request $request): SymfonyResponse
    {
        $organization = $this->resolveCurrentOrganization($request);
        Gate::authorize('manageBilling', $organization);

        // configuration id (billing:ensure-portal-configuration で生成) が設定されていれば
        // subscription_update 無効の spec 準拠 configuration で portal session を作る
        // (未設定なら Dashboard 既定 configuration。PortalConfigurationSpec 参照)
        return Inertia::location($organization->billingPortalUrl(
            route('billing.index'),
            PortalConfigurationSpec::sessionOptions(config('cashier.portal_configuration_id')),
        ));
    }
```

```php
// app/Providers/AppServiceProvider.php L103-104
        // チケットスポット購入の Stripe Checkout 抽象 (T007)。テストは fake を bind する
        $this->app->bind(TicketCheckoutGateway::class, CashierTicketCheckoutGateway::class);
```

### 変更後コード

```php
// app/DataTransferObjects/Billing/ExternalBillingRedirect.php (新規)
<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Billing;

use Webmozart\Assert\Assert;

/**
 * 課金系外部ページ (Stripe Checkout / Customer Portal) への遷移先。
 *
 * gateway (SubscriptionCheckoutGateway) の戻り値契約。Response 化
 * (Inertia::location) は Controller の責務で、gateway は URL のみ返す。
 */
final readonly class ExternalBillingRedirect
{
    public function __construct(
        public string $url,
    ) {
        Assert::stringNotEmpty($url, '外部遷移先 URL が空です');
    }
}
```

```php
// app/Services/Billing/SubscriptionCheckoutGateway.php (新規)
<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\DataTransferObjects\Billing\ExternalBillingRedirect;
use App\Models\Organization;

/**
 * サブスクリプションの Stripe Checkout / Customer Portal 抽象
 * (実装: CashierSubscriptionCheckoutGateway。fake_externals 時は fake を bind)。
 * Stripe 呼び出しを本 interface に閉じ、Controller は戻り値 DTO の URL へ
 * Inertia::location するのみ。
 */
interface SubscriptionCheckoutGateway
{
    /**
     * subscription (type=default) の hosted Checkout Session を作り遷移先を返す。
     */
    public function createSubscriptionCheckout(
        Organization $organization,
        string $stripePriceId,
        string $successUrl,
        string $cancelUrl,
    ): ExternalBillingRedirect;

    /**
     * Customer Portal セッションを作り遷移先を返す
     * (configuration は PortalConfigurationSpec 準拠。実装側で解決する)。
     */
    public function portalRedirect(Organization $organization, string $returnUrl): ExternalBillingRedirect;
}
```

```php
// app/Services/Billing/CashierSubscriptionCheckoutGateway.php (新規)
<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\DataTransferObjects\Billing\ExternalBillingRedirect;
use App\Models\Organization;
use Webmozart\Assert\Assert;

/**
 * SubscriptionCheckoutGateway の Cashier (Stripe SDK) 実装。
 * ロジックは BillingController から移動 (挙動不変)。
 * PortalConfigurationSpec は同一名前空間 (App\Services\Billing) のため use 不要。
 */
final class CashierSubscriptionCheckoutGateway implements SubscriptionCheckoutGateway
{
    public function createSubscriptionCheckout(
        Organization $organization,
        string $stripePriceId,
        string $successUrl,
        string $cancelUrl,
    ): ExternalBillingRedirect {
        $checkout = $organization
            ->newSubscription('default', $stripePriceId)
            ->checkout([
                'success_url' => $successUrl,
                'cancel_url' => $cancelUrl,
            ]);

        $url = $checkout->asStripeCheckoutSession()->url;
        Assert::string($url, 'Checkout Session に URL がありません (ui_mode: hosted のみ対応)');

        return new ExternalBillingRedirect($url);
    }

    public function portalRedirect(Organization $organization, string $returnUrl): ExternalBillingRedirect
    {
        // configuration id (billing:ensure-portal-configuration で生成) が設定されていれば
        // subscription_update 無効の spec 準拠 configuration で portal session を作る
        // (未設定なら Dashboard 既定 configuration。PortalConfigurationSpec 参照)
        return new ExternalBillingRedirect($organization->billingPortalUrl(
            $returnUrl,
            PortalConfigurationSpec::sessionOptions(config('cashier.portal_configuration_id')),
        ));
    }
}
```

```php
// app/Http/Controllers/Billing/BillingController.php (checkout / portal のみ変更。
// use 追加: App\Services\Billing\SubscriptionCheckoutGateway / 削除: PortalConfigurationSpec)
    /**
     * Stripe Checkout を開始し、Checkout URL へリダイレクトする
     * (戻り型に RedirectResponse を含むのは price 不在時の back() 分岐のため)
     */
    public function checkout(BillingCheckoutRequest $request, SubscriptionCheckoutGateway $gateway): SymfonyResponse|RedirectResponse
    {
        $organization = $this->resolveCurrentOrganization($request);
        Gate::authorize('manageBilling', $organization);

        $planCode = $request->validated('plan_code');
        Assert::string($planCode);
        $plan = Plan::query()->where('code', $planCode)->firstOrFail();

        $price = $plan->currentPrice(PlanPriceKind::Base);
        if ($price === null) {
            return back()->with('error', '選択したプランは現在お申し込みいただけません。');
        }

        $redirect = $gateway->createSubscriptionCheckout(
            $organization,
            $price->stripe_price_id,
            route('billing.index'),
            route('billing.index'),
        );

        // 外部 URL への遷移は Inertia::location (full page redirect)
        return Inertia::location($redirect->url);
    }

    /** Stripe Customer Portal へリダイレクトする (支払い方法・解約の自己管理) */
    public function portal(Request $request, SubscriptionCheckoutGateway $gateway): SymfonyResponse
    {
        $organization = $this->resolveCurrentOrganization($request);
        Gate::authorize('manageBilling', $organization);

        return Inertia::location($gateway->portalRedirect($organization, route('billing.index'))->url);
    }
```

```php
// app/Providers/AppServiceProvider.php register() (TicketCheckoutGateway bind の直後に追加)
        // サブスク Checkout / Customer Portal の Stripe 抽象。fake_externals 時は
        // FakeExternalsServiceProvider が fake に rebind する (providers.php で後勝ち)
        $this->app->bind(SubscriptionCheckoutGateway::class, CashierSubscriptionCheckoutGateway::class);
```

### PHPStan適合チェック

- [x] 戻り値の型が明示されている（interface / 実装とも `ExternalBillingRedirect` 固定）
- [x] null安全（`Assert::string($url)` を実装内に維持、DTO で `stringNotEmpty`）
- [x] DTOを返している（gateway は DTO、Controller は Inertia Response）
- [x] Genericsの型パラメータが正しい（該当なし）
- 補足: `$price->stripe_price_id` は既存 `PlanPrice` モデルの string プロパティ（現行の
  `CashierTicketCheckoutGateway` 呼び出しと同型）

### テスト計画

- [ ] 既存 `BillingPageTest`（member 403 / 未知 plan_code 422 / 保護キー 422 / current org なし 404）
  が無変更で green（gateway 到達前の経路のため）
- [ ] happy path テストは A3 の fake で追加（本施策単独では Stripe 未設定のためテスト不能。
  A2+A3 を同一ブランチで連続実装し、A3 のテストが A2 の回帰を固定する）
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認

### リスク

- ロジック移動のみだが、Cashier `newSubscription()->checkout()` の呼び出し位置が Controller →
  gateway に変わるため、例外の発生箇所（Stripe 未設定時の 500 等）のスタックが変わる。
  挙動（レスポンスコード・遷移先）は不変。

---

## 施策 A3: fake 実装と条件付き bind（FakeExternalsServiceProvider）

### 変更箇所

- `app/Services/Billing/Fakes/FakeTicketCheckoutGateway.php`（新規）
- `app/Services/Billing/Fakes/FakeSubscriptionCheckoutGateway.php`（新規）
- `app/Providers/FakeExternalsServiceProvider.php`（新規）
- `bootstrap/providers.php`（`AppServiceProvider` より後に登録）
- `tests/Feature/Providers/FakeExternalsServiceProviderTest.php`（新規）
- `tests/Feature/Billing/BillingPageTest.php`（happy path 追記）
- `tests/Feature/Billing/TicketCheckoutTest.php`（marker query 非解釈テスト追記）

### 波及変更

- TypeScript型定義: なし
- API Resource/DTO: なし（既存 `CreatedCheckoutSession` / 新規 `ExternalBillingRedirect` を使用）
- テストファイル: 上記 3 ファイル。`Tests\Support\FakeTicketCheckoutGateway`（spy）は無変更で残す
  （record / failure-injection はテスト専用機能。runtime fake は状態を持たない stub で責務が異なる）

### 現行コード

fake の runtime bind は存在しない（`AppServiceProvider` が常に Cashier 実装を bind）。

```php
// bootstrap/providers.php (現行)
return [
    AppServiceProvider::class,
    AdminPanelProvider::class,
    FortifyServiceProvider::class,
    McpPassportServiceProvider::class,
    SeoServiceProvider::class,
];
```

### 変更後コード

```php
// app/Services/Billing/Fakes/FakeTicketCheckoutGateway.php (新規)
<?php

declare(strict_types=1);

namespace App\Services\Billing\Fakes;

use App\DataTransferObjects\Billing\CreatedCheckoutSession;
use App\Models\Organization;
use App\Services\Billing\TicketCheckoutGateway;
use Carbon\CarbonImmutable;

/**
 * TicketCheckoutGateway の runtime fake (fake_externals 環境専用。Stripe に到達しない)。
 *
 * 契約 = 「外部ステップを skip した中立帰還」:
 * - session id は idempotency key から決定的に導出 (Stripe の idempotency replay と同じ収束特性)
 * - 遷移先はアプリ内帰還画面 ($cancelUrl) + 観測用 marker query `fake_external=stripe`。
 *   アプリはこの query を一切解釈しない (purchased 偽装なし / cancel の意味付けもなし)
 * - 決済・チケット付与・状態変更は一切行わない (課金状態の正本は BughuntBillingSeeder)
 *
 * テスト専用 spy (Tests\Support\FakeTicketCheckoutGateway) とは責務が異なる:
 * spy は呼び出し記録と失敗注入を持つが、本クラスは無状態 stub (serve プロセスで動く前提)。
 */
final class FakeTicketCheckoutGateway implements TicketCheckoutGateway
{
    public function createTicketCheckout(
        Organization $organization,
        string $stripePriceId,
        int $quantity,
        string $successUrl,
        string $cancelUrl,
        string $idempotencyKey,
        array $metadata,
    ): CreatedCheckoutSession {
        // idempotency key から決定的に導出 (同一 attempt の再送は同一 session に収束)。
        // key の文字種・長さに依存しないよう sha256 の先頭 32 桁で固定長トークン化する
        // (stripe_session_id 列・URL への混入安全性)
        $token = substr(hash('sha256', $idempotencyKey), 0, 32);

        return new CreatedCheckoutSession(
            sessionId: "cs_bughuntfake_{$token}",
            url: FakeExternalUrl::neutralReturn($cancelUrl),
            expiresAt: CarbonImmutable::now()->addDay(), // Stripe hosted checkout の既定 24h に合わせる
        );
    }

    public function expireCheckoutSession(string $sessionId): string
    {
        return 'expired';
    }
}
```

```php
// app/Services/Billing/Fakes/FakeExternalUrl.php (新規: marker 付与の共通化)
<?php

declare(strict_types=1);

namespace App\Services\Billing\Fakes;

use Webmozart\Assert\Assert;

/**
 * fake externals の中立帰還 URL (アプリ内画面 + 観測用 marker query)。
 * marker はアプリが解釈しない (TicketCheckoutTest が purchased=false を固定)。
 * bug-hunt のブラウザログから「外部ステップを skip した」ことを観測するためだけの query。
 */
final class FakeExternalUrl
{
    public const string MARKER = 'fake_external=stripe';

    public static function neutralReturn(string $appUrl): string
    {
        Assert::stringNotEmpty($appUrl, '中立帰還先のアプリ内 URL が空です');

        return $appUrl.(str_contains($appUrl, '?') ? '&' : '?').self::MARKER;
    }
}
```

```php
// app/Services/Billing/Fakes/FakeSubscriptionCheckoutGateway.php (新規)
<?php

declare(strict_types=1);

namespace App\Services\Billing\Fakes;

use App\DataTransferObjects\Billing\ExternalBillingRedirect;
use App\Models\Organization;
use App\Services\Billing\SubscriptionCheckoutGateway;

/**
 * SubscriptionCheckoutGateway の runtime fake (fake_externals 環境専用)。
 * 契約は FakeTicketCheckoutGateway と同じ「中立帰還」。subscription 状態は変更しない
 * (active subscription の正本は BughuntBillingSeeder)。
 */
final class FakeSubscriptionCheckoutGateway implements SubscriptionCheckoutGateway
{
    public function createSubscriptionCheckout(
        Organization $organization,
        string $stripePriceId,
        string $successUrl,
        string $cancelUrl,
    ): ExternalBillingRedirect {
        return new ExternalBillingRedirect(FakeExternalUrl::neutralReturn($cancelUrl));
    }

    public function portalRedirect(Organization $organization, string $returnUrl): ExternalBillingRedirect
    {
        return new ExternalBillingRedirect(FakeExternalUrl::neutralReturn($returnUrl));
    }
}
```

```php
// app/Providers/FakeExternalsServiceProvider.php (新規)
<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Billing\Fakes\FakeSubscriptionCheckoutGateway;
use App\Services\Billing\Fakes\FakeTicketCheckoutGateway;
use App\Services\Billing\SubscriptionCheckoutGateway;
use App\Services\Billing\TicketCheckoutGateway;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

/**
 * 外部サービス fake の配線 (config('testing.fake_externals') が capability flag)。
 *
 * bootstrap/providers.php で AppServiceProvider より後に登録する (後勝ち rebind)。
 * fail-secure 二軸:
 * 1. flag === true (既定 false = 完全 no-op)
 * 2. 環境 allowlist (local / testing / bughunt.local)。denylist (非 production) ではなく
 *    allowlist で倒す = staging 等の未知環境で flag が誤設定されても fake しない
 *    (warning ログで検出可能にする)。production は加えて ProductionEnvGuard が
 *    flag=true を deploy 時 fail-fast で拒否する (二重防御)。
 */
class FakeExternalsServiceProvider extends ServiceProvider
{
    /** fake bind を許可する環境 allowlist */
    private const array ALLOWED_ENVIRONMENTS = ['local', 'testing', 'bughunt.local'];

    public function register(): void
    {
        if (config('testing.fake_externals') !== true) {
            return;
        }

        $environment = $this->app->environment();
        if (! in_array($environment, self::ALLOWED_ENVIRONMENTS, true)) {
            Log::warning('TESTING_FAKE_EXTERNALS=true ですが allowlist 外の環境のため fake を bind しません。', [
                'environment' => $environment,
            ]);

            return;
        }

        // Stripe 到達点を fake へ rebind (課金状態の正本は BughuntBillingSeeder)
        $this->app->bind(TicketCheckoutGateway::class, FakeTicketCheckoutGateway::class);
        $this->app->bind(SubscriptionCheckoutGateway::class, FakeSubscriptionCheckoutGateway::class);
    }
}
```

（provider は `register()` のみ。診断用メソッドは追加しない — テストは container 解決の
instanceof 検証で足りる。Pest はテスト毎に app を再構築するため、テスト内での
`register()` 再実行による container 汚染はテスト間に漏れない）

```php
// bootstrap/providers.php (変更後)
return [
    AppServiceProvider::class,
    AdminPanelProvider::class,
    FortifyServiceProvider::class,
    // Passport は composer.json の dont-discover で自動 discovery を無効化し、
    // grant / repository を差し替えた本 Provider を唯一の登録点にする (WP23)
    McpPassportServiceProvider::class,
    SeoServiceProvider::class,
    // 外部 fake の条件付き rebind (flag 既定 false = no-op)。
    // AppServiceProvider の実装 bind を後勝ちで上書きするため必ず末尾側に置く
    FakeExternalsServiceProvider::class,
];
```

### PHPStan適合チェック

- [x] 戻り値の型が明示されている
- [x] null安全（config は `!== true` 厳密比較、environment() は string を in_array strict）
- [x] DTOを返している（fake も実装と同一の DTO 契約）
- [x] Genericsの型パラメータが正しい（該当なし）
- 補足: `$this->app->environment()` は引数なしで string を返す（Laravel 12 の
  `Application::environment()`）。PHPStan が `string|bool` と推論する場合は
  `Assert::string()` で絞る

### テスト計画

- [ ] 先に fail するテスト → 実装の順で進める
- [ ] 新規 `tests/Feature/Providers/FakeExternalsServiceProviderTest.php`:
  1. 既定（flag=false）では `TicketCheckoutGateway` / `SubscriptionCheckoutGateway` が
     Cashier 実装に解決される（現状固定）
  2. `config(['testing.fake_externals' => true])` + provider `register()` 再実行
     （env=testing は allowlist 内）で両 gateway が fake に解決される
  3. `$this->app['env'] = 'production'` + flag=true + `register()` 再実行では
     **fake に bind されない**（Cashier 実装のまま）かつ `Log::warning` が発火する
     （`Log::spy()` で検証）。テスト末尾で `$this->app['env']` を復元（try/finally）
- [ ] `BillingPageTest` 追記（happy path。`$this->app->bind(SubscriptionCheckoutGateway::class,
  FakeSubscriptionCheckoutGateway::class)` で fake を明示 bind）:
  4. owner の `POST /billing/checkout {plan_code: standard}` → 302 リダイレクトで
     遷移先 URL が `billing.index` + `fake_external=stripe` を含む
  5. owner の `POST /billing/portal` → 302 リダイレクトで同様
- [ ] `TicketCheckoutTest` 追記（marker query 非解釈の固定）:
  6. `GET /purchase-tickets?fake_external=stripe` → `page.purchased === false`
     （marker が purchased 表示に転用されないこと）
- [ ] 新規 `tests/Unit/Billing/FakeTicketCheckoutGatewayTest.php`（fake の不変条件固定）:
  7. 同一 idempotency key からは同一 `sessionId` が返る（決定論収束）
  8. 異なる key からは異なる `sessionId` が返る
  9. `sessionId` が `^cs_bughuntfake_[0-9a-f]{32}$` に一致する（固定長トークン化の退行検出）
  10. 戻り URL が cancel URL ベースで `fake_external=stripe` marker が付与される
      （既存 query がある URL では `&`、無ければ `?` で連結）
- [ ] `ProductionEnvGuardTest`（A1）で production の flag=true 拒否を固定
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認

### リスク

- provider の register は毎リクエスト走るが、判定は config 読みと in_array のみで
  オーバーヘッドは無視できる
- phpunit（env=testing）は flag 既定 false のため既存テストへの影響なし。
  fake を使いたいテストは明示 bind（従来どおり）

---

## 施策 B1: BughuntBillingSeeder（standard 組織のみ active sub + 初期チケット）

### 変更箇所

- `database/seeders/Concerns/DetectsBughuntDatabase.php`（新規 trait: DB 名 regex の SSOT）
- `database/seeders/BughuntBillingSeeder.php`（新規）
- `database/seeders/BughuntOAuthSeeder.php`（`BUGHUNT_DB_REGEX` 定数を trait へ集約する
  最小リファクタ。guard の挙動不変）
- `scripts/bug-hunt-shard.sh`（`cmd_provision` / `cmd_reseed` の seed 列に追加）
- `tests/Feature/Database/BughuntBillingSeederTest.php`（新規）
- `tests/Feature/Database/BughuntOAuthSeederGuardTest.php`（新規: guard no-op 回帰）

### 波及変更

- TypeScript型定義: なし
- API Resource/DTO: なし
- テストファイル: 上記 2 新規。既存 `ManualTestSeederTest` は無変更（ManualTestSeeder 非接触）

### 現行コード

```php
// database/seeders/BughuntOAuthSeeder.php L38-39
    /** bug-hunt DB 名の許容 regex (bug-hunt 隔離規約と一致)。 */
    private const string BUGHUNT_DB_REGEX = '/^bug_hunt(_[1-8])?$/';
```

```bash
# scripts/bug-hunt-shard.sh cmd_provision (b) / cmd_reseed (同一列)
    artisan_for_shard "${db}" "${url}" migrate:fresh --seed --force
    artisan_for_shard "${db}" "${url}" db:seed --class=ManualTestSeeder --force
    artisan_for_shard "${db}" "${url}" db:seed --class=AdminUserSeeder --force
    artisan_for_shard "${db}" "${url}" db:seed --class=BughuntOAuthSeeder --force
```

### 変更後コード

```php
// database/seeders/Concerns/DetectsBughuntDatabase.php (新規)
<?php

declare(strict_types=1);

namespace Database\Seeders\Concerns;

use Illuminate\Support\Facades\DB;

/**
 * bug-hunt DB 名判定の SSOT (bug-hunt 隔離規約 ^bug_hunt(_[1-8])?$ と一致)。
 * bughunt 系 seeder の fail-secure guard から参照する。
 */
trait DetectsBughuntDatabase
{
    /** bug-hunt DB 名の許容 regex (scripts/bug-hunt-shard.sh の guard と一致させる)。 */
    private const string BUGHUNT_DB_REGEX = '/^bug_hunt(_[1-8])?$/';

    private function isBughuntDatabase(): bool
    {
        return preg_match(self::BUGHUNT_DB_REGEX, DB::connection()->getDatabaseName()) === 1;
    }
}
```

```php
// database/seeders/BughuntOAuthSeeder.php (最小リファクタ: 定数を trait へ)
class BughuntOAuthSeeder extends Seeder
{
    use Concerns\DetectsBughuntDatabase;

    // private const string BUGHUNT_DB_REGEX = ... は削除 (trait へ集約)

    public function run(): void
    {
        // fail-secure 三軸: fake_externals かつ bughunt.local かつ DB 名 bug_hunt* の全成立時のみ。
        if (
            config('testing.fake_externals') !== true
            || ! app()->environment('bughunt.local')
            || ! $this->isBughuntDatabase()
        ) {
            $this->command->warn('BughuntOAuthSeeder: fake_externals / bughunt.local / bug_hunt DB のいずれか不成立のため skip (production/dev safety)。');

            return;
        }
        // 以下不変
```

```php
// database/seeders/BughuntBillingSeeder.php (新規)
<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Billing\PlanPriceKind;
use App\Models\Billing\Plan;
use App\Models\Billing\Subscription;
use App\Models\Organization;
use App\Services\Billing\TicketLedgerService;
use Illuminate\Database\Seeder;

/**
 * bug-hunt env 専用: 有料プラン組織に active subscription + 初期チケットを付与する。
 *
 * 目的: BillingAccess::hasActiveAccess (subscription default が active/trialing) を
 * 有料プラン組織で true にし、業務ルート (/projects, /app) を bug-hunt で走行可能にする。
 * チケット消費系ジャーニー (AI 解析 / レンダ) のため初期残高も付与する。
 *
 * ★ free 組織には何も付与しない: 「課金なし経路」(billing redirect / 残高ゼロ) を
 *   bug-hunt 環境内に温存し、課金ゲート系バグの検出能力を落とさない (概念設計 施策 4)。
 *
 * 三重 fail-secure (BughuntOAuthSeeder と同一): (1) config('testing.fake_externals') === true、
 * (2) app()->environment('bughunt.local')、(3) DB 名 ^bug_hunt(_[1-8])?$。
 * いずれか欠ければ no-op (production/dev DB に課金状態をばら撒かない)。
 *
 * 冪等 = 「探索前提の active 状態を毎回回復する」。stripe_status が active 以外に変わって
 * いても reseed で active を再保証する。チケットは grantMonthly の idempotency_key で二重付与しない。
 *
 * 依存は Seeder の method injection (run() 引数) で受ける (Laravel 公式作法。型安全)。
 */
class BughuntBillingSeeder extends Seeder
{
    use Concerns\DetectsBughuntDatabase;

    /** 初期チケット付与枚数 (S3 の解析/レンダ探索に十分な決定論値)。 */
    private const int INITIAL_TICKET_GRANT = 100;

    public function run(TicketLedgerService $tickets): void
    {
        if (
            config('testing.fake_externals') !== true
            || ! app()->environment('bughunt.local')
            || ! $this->isBughuntDatabase()
        ) {
            $this->command->warn('BughuntBillingSeeder: fake_externals / bughunt.local / bug_hunt DB のいずれか不成立のため skip (production/dev safety)。');

            return;
        }

        $paidPlanCodes = $this->paidPlanCodes();
        if ($paidPlanCodes === []) {
            $this->command->warn('BughuntBillingSeeder: 有料プランが無いため skip。先に PlanSeeder を流すこと。');

            return;
        }

        $organizations = Organization::query()->whereIn('plan_code', $paidPlanCodes)->orderBy('id')->get();
        foreach ($organizations as $organization) {
            $this->ensureActiveSubscription($organization);
            // 冪等キーで二重付与を防ぐ (reseed は migrate:fresh 後だが、単独再実行にも安全)
            $tickets->grantMonthly(
                $organization,
                self::INITIAL_TICKET_GRANT,
                null,
                "bughunt:initial-grant:{$organization->id}",
                'bug-hunt 初期チケット (探索用)',
            );
        }

        $this->command->info("BughuntBillingSeeder: {$organizations->count()} 組織に active subscription + チケット".self::INITIAL_TICKET_GRANT.' 枚を付与。');
    }

    /**
     * base price を持つプラン (= 有料プラン) の code 一覧。
     *
     * @return list<string>
     */
    private function paidPlanCodes(): array
    {
        // pluck は list<mixed> になるため map で list<string> を実型でも保証する (PHPStan level 10)
        return Plan::query()->orderBy('sort_order')->get()
            ->filter(fn (Plan $plan): bool => $plan->currentPrice(PlanPriceKind::Base) !== null)
            ->map(fn (Plan $plan): string => $plan->code)
            ->values()
            ->all();
    }

    /**
     * active な default subscription を保証する (payload はここに集約)。
     * 作成は billable relation 経由 (organization_id は FK 自動設定 = guarded 不侵)。
     * stripe_id は決定論 fixture `sub_bughunt_{org id}` = org 単位一意
     * (subscriptions.stripe_id UNIQUE と両立。Stripe には到達しない)。
     */
    private function ensureActiveSubscription(Organization $organization): void
    {
        $existing = $organization->subscription('default');

        if ($existing instanceof Subscription) {
            if ($existing->stripe_status !== 'active') {
                // 探索で past_due 等に変わっていても active を回復 (冪等 = active 再保証)
                $existing->forceFill(['stripe_status' => 'active'])->save();
            }

            return;
        }

        $organization->subscriptions()->create([
            'type' => 'default',
            'stripe_id' => "sub_bughunt_{$organization->id}",
            'stripe_status' => 'active',
            'quantity' => 1,
        ]);
    }
}
```

```bash
# scripts/bug-hunt-shard.sh cmd_provision (b) / cmd_reseed (両方同じ列に追加)
    artisan_for_shard "${db}" "${url}" migrate:fresh --seed --force
    artisan_for_shard "${db}" "${url}" db:seed --class=ManualTestSeeder --force
    # 有料プラン組織に active subscription + 初期チケットを付与 (三重ガード付き)。
    # free 組織は未契約のまま = 課金なし経路の探索能力を温存する。
    artisan_for_shard "${db}" "${url}" db:seed --class=BughuntBillingSeeder --force
    artisan_for_shard "${db}" "${url}" db:seed --class=AdminUserSeeder --force
    artisan_for_shard "${db}" "${url}" db:seed --class=BughuntOAuthSeeder --force
```

### PHPStan適合チェック

- [x] 戻り値の型が明示されている（`paidPlanCodes(): array` + `@return list<string>`）
- [x] null安全（`$existing instanceof Subscription` で絞り込み。`subscription('default')` の
  戻り値は Cashier の `?Subscription`）
- [x] DTOを返している（該当なし: seeder）
- [x] Genericsの型パラメータが正しい（Collection の filter/pluck は list<string> へ正規化）
- 補足: `pluck('code')` の mixed を `->all()` 前に string へ絞る必要があれば
  `->map(fn (Plan $plan): string => $plan->code)` へ置換して型を固定する

### テスト計画

（`DB::connection()->setDatabaseName()` で接続を張り替えず DB 名のみ差し替える。
**try/finally で必ず復元**する。Round 3 レビューの Suggestion 反映）

- [ ] 先に fail するテストを置く（seeder 未実装で class not found → red）
- [ ] 新規 `BughuntBillingSeederTest`:
  1. guard 不成立（既定の testing env / 非 bughunt DB 名）では subscription もチケットも
     作られない（no-op 固定）
  2. env=bughunt.local + fake_externals=true + DB 名 `bug_hunt`（setDatabaseName 差し替え）で:
     standard 組織に active subscription（`hasActiveAccess()` true）とチケット 100 が付与される /
     **free 組織には subscription もチケットも付与されない** / 再実行しても
     subscription 1 行・残高 100 のまま増えない（冪等）
  3. 既存 subscription が `past_due` の場合、再実行で `active` に回復する
  4. **fake_externals=true でも env=testing のままなら no-op**（flag 単独では点火しない境界の固定）
- [ ] 新規 `BughuntOAuthSeederGuardTest`: 既定の testing env では `BughuntOAuthSeeder` が
  no-op（oauth_clients / oauth_sessions が増えない）+ **fake_externals=true でも env=testing
  なら no-op** — A1 の config 点火の境界固定
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認

### リスク

- `setDatabaseName` 差し替えは同一 PDO 接続のままのため実 DB は test DB のまま（安全）。
  復元漏れは try/finally で防ぐ
- ManualTestSeeder / DatabaseSeeder は非接触（dev/prod seed 方針不変）

---

## 施策 C1: AdminUserSeeder の bughunt.local 対応（DB 名 guard 付き）

### 変更箇所

- `database/seeders/AdminUserSeeder.php`（guard 拡張 + docblock 更新）
- `tests/Feature/Admin/AdminUserSeederTest.php`（2 ケース追記）

### 波及変更

- TypeScript型定義: なし / API Resource/DTO: なし
- テストファイル: `AdminUserSeederTest` のみ（既存 3 テストは無変更で green）
- `scripts/bug-hunt-shard.sh` は既に `AdminUserSeeder` を明示 seed 済み（変更不要）

### 現行コード

```php
    public function run(): void
    {
        // local 専用 (本番の初期 admin は admin:create コマンドで発行する)
        if (! app()->environment('local')) {
            return;
        }
```

### 変更後コード

```php
    // class docblock にも「local は無条件 / bughunt.local は DB 名 guard 必須」の
    // 二段構えを追記する (将来の差分レビュー容易化)
    use Concerns\DetectsBughuntDatabase;

    public function run(): void
    {
        // local (無条件) と bughunt.local (bug_hunt DB のみ) 専用。
        // bughunt.local でも DB 名を検証するのは、誤って dev DB を向いた
        // APP_ENV=bughunt.local 実行で既知資格情報の admin を dev DB に作らないため
        // (bughunt seeder 群の fail-secure と同強度)。本番の初期 admin は admin:create。
        if (! $this->shouldSeed()) {
            return;
        }
        // 以下不変

    private function shouldSeed(): bool
    {
        if (app()->environment('local')) {
            return true;
        }

        return app()->environment('bughunt.local') && $this->isBughuntDatabase();
    }
```

### PHPStan適合チェック

- [x] 戻り値の型が明示されている（`shouldSeed(): bool`）
- [x] null安全 / DTO / Generics: 該当なし

### テスト計画

- [ ] 先に fail するテスト: 「bughunt.local ∧ bug_hunt DB 名なら AdminUser を作成する」→ red
- [ ] `AdminUserSeederTest` 追記:
  1. `$this->app['env'] = 'bughunt.local'` + `setDatabaseName('bug_hunt')`（try/finally 復元）
     → admin@example.com が作成される
  2. `$this->app['env'] = 'bughunt.local'`（DB 名は test のまま）→ 作成されない（dev DB 防御）
- [ ] 既存 3 テスト（testing skip / local 冪等 / パスワード非上書き）が無変更で green

### リスク

- guard は拡張のみ（local 挙動不変、testing/production 従来どおり skip）。
  bughunt.local ブランチは DB 名 regex で dev DB を構造的に除外

---

## 施策 C2: provision への Filament アセット publish（冪等）

### 変更箇所

- `scripts/bug-hunt-shard.sh`: `ensure_filament_assets()` helper 新設（asset freshness guard
  セクション末尾）+ `cmd_provision()` の seed ブロック直後で呼び出し

### 波及変更

- テストファイル: なし（bash）。`scripts/bug-hunt-shard.sh self-test` の pass を維持
  （self-test のフィクスチャ・`cmd_assets_check` は非接触。helper は `is_dryrun` guard 付きで
  provision 実パスのみで動く）

### 現行コード

```bash
# cmd_provision (b) の末尾 → (c) 実効 env 検証 の間に何も無い
    artisan_for_shard "${db}" "${url}" db:seed --class=BughuntOAuthSeeder --force

    # (c) 実効 env 検証 (不一致 fail-fast)
```

### 変更後コード

```bash
# --- filament assets guard (worktree は composer install --no-scripts のため -----
#     post-autoload-dump の filament:upgrade が走らず public/*/filament が欠落する)

FILAMENT_ASSET_MARKER=public/js/filament/.bughunt-filament-version
FILAMENT_REQUIRED_ASSETS=(public/js/filament/filament/app.js public/css/filament/filament/app.css)

filament_version_from_lock() {
    php -r '
        $lock = json_decode((string) file_get_contents("composer.lock"), true);
        foreach (($lock["packages"] ?? []) as $p) {
            if (($p["name"] ?? "") === "filament/filament") { echo $p["version"] ?? ""; return; }
        }
    ' 2>/dev/null
}

filament_assets_present() {
    local f
    for f in "${FILAMENT_REQUIRED_ASSETS[@]}"; do
        [[ -s "${f}" ]] || return 1
    done
    return 0
}

# 冪等 publish: marker (composer.lock の filament version) 一致 ∧ 必須アセット実在なら skip。
# marker は filament:assets 成功後にのみ書く (失敗時は残さず次回再実行)。
# 並列 fan-out (provision-all) は shard を直列 provision するため race しない。
# 将来 provision を並列化する場合は本 helper を worktree 単位の事前フェーズへ移すこと。
ensure_filament_assets() {
    local db=$1 url=$2
    is_dryrun && return 0
    local version; version="$(filament_version_from_lock)"
    [[ -z "${version}" ]] \
        && echo "warning: composer.lock から filament/filament version を解決できない (marker skip 不可 = 毎回 publish 判定)" >&2
    if [[ -n "${version}" && -f "${FILAMENT_ASSET_MARKER}" \
        && "$(cat "${FILAMENT_ASSET_MARKER}")" == "${version}" ]] && filament_assets_present; then
        return 0
    fi
    echo ">>> filament assets missing/stale → filament:assets"
    artisan_for_shard "${db}" "${url}" filament:assets
    filament_assets_present \
        || die 1 "filament:assets 実行後も必須アセットが無い (${FILAMENT_REQUIRED_ASSETS[*]})。filament の publish 先変更を疑い、artisan filament:assets の出力を確認すること"
    [[ -n "${version}" ]] && printf '%s' "${version}" > "${FILAMENT_ASSET_MARKER}"
    return 0
}
```

```bash
# cmd_provision: (b) seed 列の直後に追加
    # (b2) Filament 静的アセット publish (F-13 対策)。冪等 (marker + 実在確認で skip)。
    ensure_filament_assets "${db}" "${url}"

    # (c) 実効 env 検証 (不一致 fail-fast)
```

### PHPStan適合チェック

- 該当なし（bash。php -r は composer.lock の read-only パース）

### テスト計画

- [ ] `scripts/bug-hunt-shard.sh self-test` が pass を維持（helper は self-test 経路から
  呼ばれない。`bash -n scripts/bug-hunt-shard.sh` の構文チェックも実施）
- [ ] 手動検証（実装フェーズの verify）: worktree で provision → `public/js/filament/filament/app.js`
  が生成される / 再 provision で skip ログ（">>> filament assets" が出ない）/
  `/admin/login` が 200 で CSS 404 が無い

### リスク

- `filament:assets` の publish 先レイアウトが filament のメジャー更新で変わる可能性 →
  実行後の実在確認 + die で fail-fast（黙って無スタイルに戻さない）
- marker は gitignore 済みディレクトリ配下（`/public/js/filament/` は .gitignore 対象）のため
  リポジトリを汚さない

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | standalone |
| 判断根拠 | 施策間依存が強い（A1 の config に A3/B1 が依存、A2 に A3 が依存）ため 1 worktree で A→B→C の順に段階コミットするのが安全。bash (bug-hunt-shard.sh) と PHP の両面に跨る変更で、分割すると中間状態が bughunt 環境を壊す |
| 競合リスク | G1（free ゲート整合）設計と同時期になる場合、`BillingAccess` / `RequireActiveSubscription` は本設計非接触のため直接衝突しない。`BillingController::checkout` 周辺（A2）は G1 が UI/導線を触る場合に merge 競合し得る → 実装順を本設計先行にする |

## 検証コマンド（実装完了条件）

- `composer test`（新規 + 既存全 green）/ `composer phpstan` / `vendor/bin/pint --test`
- `pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm build`（フロント変更なしだが回帰確認）
- `bash -n scripts/bug-hunt-shard.sh` + `scripts/bug-hunt-shard.sh self-test`
- 実機 verify（worktree）: provision 後に (1) owner-standard で `/projects` が 200、
  (2) owner-free は `/billing` redirect のまま、(3) `/purchase-tickets/checkout` `/billing/checkout`
  `/billing/portal` が 500 にならず `fake_external=stripe` 付き URL へ帰還、
  (4) `/admin/login` がスタイル付きで表示され admin@example.com / password12345 でログイン可能
