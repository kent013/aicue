# Codex 詳細設計レビュー依頼: bugfix-bughunt-infra (Round 1)

## アプリの使命 (North Star)

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 標準作業を起点に AI が教材設計し撮影を指示する（撮影者・教える人のスキルに品質を依存させない）。

## 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(migrate:fresh 等)をエージェント判断で実行すること
4. response()->json() の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(app/Prompts/ の factory 経由のみ)
6. prompt 文字列のコード直書き
7. 操作系 POST の応答での redirect()->intended()
8. 必須条件未充足を理由にボタンを disabled にする UI

## セキュリティ不変条件 (アプリ都合で緩めない)

1. tenant キー不信 2. 子は親に属する(nested route 不整合は 404) 3. cross-org 不可
4. untrusted 文字列は UserInput 型経由のみ 5. 権限判定は laratrust_team_id 明示
6. PII は CipherSweet + whereBlind 7. 課金の冪等性 (webhook 冪等マシン / reserve→commit/release)
8. 外部 URL 取得は SSRF 検査経由

【思考原則 — 全議論に適用】
まず仮説を立てろ。データに真摯に向き合え。先人の知恵を探せ。機能の名前に立ち返れ。仕組みが機能していない段階で値を弄るな。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

あなたは経験豊富なWebアプリケーションアーキテクトです。Laravel + Svelte アプリケーション改善の詳細設計をレビューしてください。

【前提環境】
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- PHPStan level 10
- Pestテストフレームワーク
- DTO + JsonResource パターン
- Laratrust RBAC（Organization → Team → Project階層）
- bug-hunt 専用環境: APP_ENV=bughunt.local / DB bug_hunt(_N) / TESTING_FAKE_EXTERNALS=true。
  scripts/bug-hunt-shard.sh が provision する。worktree は composer install --no-scripts。
- 概念設計は gpt-5.4 との合議で APPROVED 済み
  (devnotes/20260712-0949-bugfix-bughunt-infra/conceptual-design.md)

【レビュー観点】
1. コードの正確性（ロジックエラー、エッジケース、null安全性）
2. 既存コードとの整合性（命名規約、パターン、API）
3. PHPStan level 10 適合性（型安全性、generics、Assert使用）
4. テスト計画の網羅性（各施策にPestテスト、RefreshDatabaseグローバル適用に従う）
5. DTO/JsonResource パターンの遵守
6. Inertia Props vs API Responseの使い分け
7. 副作用・後退リスク（特に fake の production 漏れ、既存テスト・self-test の後退、dev DB 防御）
8. 波及変更の網羅性（TypeScript型定義、API Resource、テストが変更対象に含まれているか）
9. セキュリティ（認可チェック、入力バリデーション、OWASP Top 10、AGENTS.md のセキュリティ不変条件）
10. DESIGN.md準拠（UI/frontend 変更を含む場合）— 本設計は UI 変更なし
11. Atomic Design準拠（UI/frontend 変更を含む場合）— 本設計は UI 変更なし

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

---

## 詳細設計書

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
| A3 | fake 実装と条件付き bind（FakeExternalsServiceProvider） | `app/Services/Billing/Fakes/FakeTicketCheckoutGateway.php`（新規）、`app/Services/Billing/Fakes/FakeSubscriptionCheckoutGateway.php`（新規）、`app/Providers/FakeExternalsServiceProvider.php`（新規）、`bootstrap/providers.php`、`tests/Feature/Providers/FakeExternalsServiceProviderTest.php`（新規）、`tests/Feature/Billing/BillingPageTest.php`、`tests/Feature/Billing/TicketCheckoutTest.php` | 高 |
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
    /** Stripe Checkout を開始し、Checkout URL へリダイレクトする */
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
        // idempotency key から決定的に導出 (同一 attempt の再送は同一 session に収束)
        $token = str_replace('purchase:', '', $idempotencyKey);

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

use App\Services\Billing\CashierTicketCheckoutGateway;
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
instanceof 検証で足りる。`use App\Services\Billing\CashierTicketCheckoutGateway;` は不要）

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
        return Plan::query()->orderBy('sort_order')->get()
            ->filter(fn (Plan $plan): bool => $plan->currentPrice(PlanPriceKind::Base) !== null)
            ->pluck('code')
            ->values()
            ->all();
    }

    /**
     * active な default subscription を保証する (payload はここに集約)。
     * 作成は billable relation 経由 (organization_id は FK 自動設定 = guarded 不侵)。
     * stripe_id は決定論 (Stripe には到達しない fixture)。
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
- [ ] 新規 `BughuntOAuthSeederGuardTest`: 既定の testing env では `BughuntOAuthSeeder` が
  no-op（oauth_clients / oauth_sessions が増えない）— A1 の config 点火の境界固定
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
    if [[ -n "${version}" && -f "${FILAMENT_ASSET_MARKER}" \
        && "$(cat "${FILAMENT_ASSET_MARKER}")" == "${version}" ]] && filament_assets_present; then
        return 0
    fi
    echo ">>> filament assets missing/stale → filament:assets"
    artisan_for_shard "${db}" "${url}" filament:assets
    filament_assets_present \
        || die 1 "filament:assets 実行後も必須アセットが無い (${FILAMENT_REQUIRED_ASSETS[*]})。filament の publish 先変更を疑うこと"
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


---

## 関連する現行コード

### app/Http/Controllers/Billing/BillingController.php (現行全文)
```
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Billing;

use App\Enums\Billing\PlanPriceKind;
use App\Http\Concerns\ResolvesCurrentOrganization;
use App\Http\Controllers\Controller;
use App\Http\Requests\Billing\BillingCheckoutRequest;
use App\Models\Billing\Plan;
use App\Models\User;
use App\Services\Billing\PortalConfigurationSpec;
use App\Services\Billing\TicketLedgerService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Webmozart\Assert\Assert;

/**
 * 課金画面 (current org スコープ)。
 *
 * - プラン変更は Stripe Checkout / Customer Portal 経由のみ (アプリは plan_code を
 *   直接書かない。organizations.plan_code は webhook で同期される)
 * - 閲覧は組織メンバー全員、Checkout / Portal は manageBilling (owner / admin) のみ
 */
class BillingController extends Controller
{
    use ResolvesCurrentOrganization;

    /** 課金ページ (現在プラン / チケット残高 / プラン一覧) */
    public function index(Request $request, TicketLedgerService $tickets): Response
    {
        $organization = $this->resolveCurrentOrganization($request);
        Gate::authorize('view', $organization);

        $user = $request->user();
        Assert::isInstanceOf($user, User::class);

        $plans = Plan::query()->orderBy('sort_order')->get()
            ->map(function (Plan $plan): array {
                $price = $plan->currentPrice(PlanPriceKind::Base);

                return [
                    'code' => $plan->code,
                    'name' => $plan->name,
                    'monthlyTicketGrant' => $plan->monthly_ticket_grant,
                    'price' => $price === null ? null : [
                        'unitAmount' => $price->amount,
                        'currency' => $price->currency,
                    ],
                ];
            })
            ->values()
            ->all();

        return Inertia::render('Billing/Index', [
            'plans' => $plans,
            'currentPlanCode' => $organization->plan_code,
            'ticketBalance' => $tickets->balance($organization),
            'canManageBilling' => $user->can('manageBilling', $organization),
        ]);
    }

    /** Stripe Checkout を開始し、Checkout URL へリダイレクトする */
    public function checkout(BillingCheckoutRequest $request): SymfonyResponse|RedirectResponse
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
    }

    /** Stripe Customer Portal へリダイレクトする (支払い方法・解約の自己管理) */
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
}

```

### app/Providers/AppServiceProvider.php (現行 register() 抜粋 L61-111)
```
    public function register(): void
    {
        // イベントリスナは本 Provider の boot() で明示登録する (SSOT)。Laravel 13 の framework
        // 既定はイベント自動 discovery (app/Listeners 走査) が ON のため、明示登録と重複して
        // 各リスナが二重発火する (RecordSecurityEvent の二重記録・FilterSuppressedRecipients の
        // 二重適用等)。discovery を切って登録経路を明示登録一本に統一する。
        EventServiceProvider::disableEventDiscovery();

        // Stripe Price Catalog への read-only 境界 (StripePriceCatalogClient) の依存。
        // StripeClient 全体ではなく PriceService のみを束縛し、テストでのモックを容易にする
        $this->app->bind(
            PriceService::class,
            static fn (): PriceService => Cashier::stripe()->prices,
        );

        // SES/SNS バウンス・苦情処理。
        // - SnsClient: SubscriptionConfirmation の confirmSubscription に使う (services.ses の認証情報を流用)。
        // - SnsSignatureVerifier: SNS 署名検証 (暗号検証は AWS SDK MessageValidator に委譲)。
        $this->app->singleton(SnsClient::class, function (Application $app): SnsClient {
            /** @var array<string, mixed> $ses */
            $ses = (array) config('services.ses', []);
            $config = [
                'version' => 'latest',
                'region' => is_string($ses['region'] ?? null) ? $ses['region'] : 'us-east-1',
            ];
            $key = $ses['key'] ?? null;
            $secret = $ses['secret'] ?? null;
            if (is_string($key) && $key !== '' && is_string($secret) && $secret !== '') {
                $config['credentials'] = ['key' => $key, 'secret' => $secret];
            }

            return new SnsClient($config);
        });
        $this->app->bind(SnsSignatureVerifier::class, AwsSnsSignatureVerifier::class);

        // Critical Action 実行中フラグ。scoped() で HTTP request scope に閉じる
        // (queue worker / artisan は別 container のため context は継承されない)
        $this->app->scoped(CriticalActionContext::class);

        // 動画合成の抽象 (doc/09 §9.7)。v1 は ffmpeg 実装。テストは fake 実装へ swap する
        $this->app->bind(VideoComposer::class, FfmpegVideoComposer::class);

        // チケットスポット購入の Stripe Checkout 抽象 (T007)。テストは fake を bind する
        $this->app->bind(TicketCheckoutGateway::class, CashierTicketCheckoutGateway::class);

        // アプリ内通知 (T008): database channel を薄い拡張へ差し替え、AppNotification の
        // organization_id を notifications テーブルの first-class 列として書き込む
        // (ChannelManager::createDatabaseDriver は container 解決のため binding が効く。
        // AppNotification 以外の通知は素通し = 後方互換)
        $this->app->bind(DatabaseChannel::class, OrganizationScopedDatabaseChannel::class);
    }

```

### bootstrap/providers.php (現行)
```
<?php

use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\McpPassportServiceProvider;
use App\Providers\SeoServiceProvider;

return [
    AppServiceProvider::class,
    AdminPanelProvider::class,
    FortifyServiceProvider::class,
    // Passport は composer.json の dont-discover で自動 discovery を無効化し、
    // grant / repository を差し替えた本 Provider を唯一の登録点にする (WP23)
    McpPassportServiceProvider::class,
    SeoServiceProvider::class,
];

```

### app/Services/Billing/TicketCheckoutGateway.php (現行)
```
<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\DataTransferObjects\Billing\CreatedCheckoutSession;
use App\Models\Organization;

/**
 * チケットスポット購入の Stripe Checkout 抽象 (実装: CashierTicketCheckoutGateway。
 * テストは fake を bind する)。Stripe 呼び出しを本 interface に閉じる。
 */
interface TicketCheckoutGateway
{
    /**
     * one-time Checkout Session を作る (mode=payment / card のみ / promo・tax なし)。
     * $idempotencyKey により同一 attempt の再送は Stripe 側で同一 session に収束する。
     *
     * @param  array<string, string>  $metadata  照合専用 (認可・org 解決には使わない)
     */
    public function createTicketCheckout(
        Organization $organization,
        string $stripePriceId,
        int $quantity,
        string $successUrl,
        string $cancelUrl,
        string $idempotencyKey,
        array $metadata,
    ): CreatedCheckoutSession;

    /**
     * Stripe 側 session を expire する。
     *
     * @return string expire 後の session status ('expired'|'complete'|...)
     */
    public function expireCheckoutSession(string $sessionId): string;
}

```

### app/Services/Billing/CashierTicketCheckoutGateway.php (現行)
```
<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\DataTransferObjects\Billing\CreatedCheckoutSession;
use App\Models\Organization;
use Carbon\CarbonImmutable;
use Laravel\Cashier\Cashier;
use Webmozart\Assert\Assert;

/**
 * TicketCheckoutGateway の Cashier (Stripe SDK) 実装。
 *
 * Cashier の checkout() ヘルパは per-request idempotency key を公開しないため、
 * Cashier の Stripe クライアント ($organization->stripe()) を直接使う。
 */
final class CashierTicketCheckoutGateway implements TicketCheckoutGateway
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
        $organization->createOrGetStripeCustomer();

        $session = $organization->stripe()->checkout->sessions->create(
            $this->buildSessionPayload($organization, $stripePriceId, $quantity, $successUrl, $cancelUrl, $metadata),
            ['idempotency_key' => $idempotencyKey],
        );

        // hosted mode では url / expires_at が常に返る (欠落は SDK/設定異常として fail-fast)
        Assert::string($session->url, 'Checkout Session に URL がありません (ui_mode: hosted のみ対応)');
        Assert::integer($session->expires_at, 'Checkout Session に expires_at がありません');

        return new CreatedCheckoutSession(
            sessionId: $session->id,
            url: $session->url,
            expiresAt: CarbonImmutable::createFromTimestamp($session->expires_at),
        );
    }

    public function expireCheckoutSession(string $sessionId): string
    {
        // 決済主体は organization だが expire は session id 単独で完結する
        // (呼び出し側が自 org 行の session id のみ渡す契約)
        $session = Cashier::stripe()->checkout->sessions->expire($sessionId);

        return is_string($session->status) ? $session->status : 'expired';
    }

    /**
     * Checkout Session payload (pure)。
     *
     * invariant (TicketPurchaseWebhookTest / gateway ユニットテストで固定):
     * `allow_promotion_codes` / `automatic_tax` を含まない。webhook の金額照合
     * `amount_subtotal === count × unit` はこの前提に依存する。promo/tax を将来
     * 有効化する場合は照合式を amount_total 系へ移行し、invariant テストの更新が変更の入口。
     *
     * @param  array<string, string>  $metadata
     * @return array{
     *   mode: 'payment',
     *   customer: string,
     *   line_items: array{array{price: string, quantity: int}},
     *   payment_method_types: array{'card'},
     *   success_url: string,
     *   cancel_url: string,
     *   metadata: array<string, string>
     * }
     */
    public function buildSessionPayload(
        Organization $organization,
        string $stripePriceId,
        int $quantity,
        string $successUrl,
        string $cancelUrl,
        array $metadata,
    ): array {
        // createOrGetStripeCustomer() 後は必ず存在する (欠落は設定異常として fail-fast)
        $customerId = $organization->stripe_id;
        Assert::stringNotEmpty($customerId, 'Stripe customer 未作成の組織では Checkout を作れません');

        return [
            'mode' => 'payment',
            'customer' => $customerId,
            'line_items' => [
                [
                    'price' => $stripePriceId,
                    'quantity' => $quantity,
                ],
            ],
            // 即時決済のみ (非同期決済を許可すると「completed = 決済済み」の前提が壊れる)
            'payment_method_types' => ['card'],
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'metadata' => $metadata,
        ];
    }
}

```

### tests/Support/FakeTicketCheckoutGateway.php (現行 spy、無変更で残す)
```
<?php

declare(strict_types=1);

namespace Tests\Support;

use App\DataTransferObjects\Billing\CreatedCheckoutSession;
use App\Models\Organization;
use App\Services\Billing\TicketCheckoutGateway;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * TicketCheckoutGateway のテスト用 fake (Stripe に到達しない)。
 *
 * - createTicketCheckout: 呼び出しを記録し、idempotency key から決定的な
 *   session id / URL を返す (Stripe の idempotency replay と同じ収束特性を再現)
 * - expireCheckoutSession: 呼び出しを記録し、$expireResult を返す
 */
final class FakeTicketCheckoutGateway implements TicketCheckoutGateway
{
    /** @var list<array{organizationId: int, stripePriceId: string, quantity: int, successUrl: string, cancelUrl: string, idempotencyKey: string, metadata: array<string, string>}> */
    public array $created = [];

    /** @var list<string> expire を要求された session id */
    public array $expired = [];

    /** expireCheckoutSession の返り値 ('expired' / 'complete' 等) */
    public string $expireResult = 'expired';

    /** true にすると createTicketCheckout が throw する (Stripe 障害の再現) */
    public bool $failOnCreate = false;

    public function createTicketCheckout(
        Organization $organization,
        string $stripePriceId,
        int $quantity,
        string $successUrl,
        string $cancelUrl,
        string $idempotencyKey,
        array $metadata,
    ): CreatedCheckoutSession {
        if ($this->failOnCreate) {
            throw new RuntimeException('fake gateway: create 失敗');
        }

        $this->created[] = [
            'organizationId' => $organization->id,
            'stripePriceId' => $stripePriceId,
            'quantity' => $quantity,
            'successUrl' => $successUrl,
            'cancelUrl' => $cancelUrl,
            'idempotencyKey' => $idempotencyKey,
            'metadata' => $metadata,
        ];

        // idempotency key から決定的に導出 (同一 attempt の再送は同一 session に収束)
        $token = str_replace('purchase:', '', $idempotencyKey);

        return new CreatedCheckoutSession(
            sessionId: "cs_test_{$token}",
            url: "https://checkout.stripe.test/c/pay/cs_test_{$token}",
            expiresAt: CarbonImmutable::now()->addDay(),
        );
    }

    public function expireCheckoutSession(string $sessionId): string
    {
        $this->expired[] = $sessionId;

        return $this->expireResult;
    }
}

```

### app/Services/Billing/BillingAccess.php (現行、非接触)
```
<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\Organization;

/**
 * 組織が業務機能を利用してよいか (課金ゲート) の判定。
 *
 * **課金による利用可否の判定は必ず本クラスを経由する** (middleware / controller /
 * service での subscription 直参照は禁止)。判定基準を 1 クラスに閉じ込めることで、
 * アプリ側は本クラスの書き換え (または container での差し替え bind) だけで
 * gate 方針を変更できる (例: 専用の billing 状態カラムでの判定や、
 * entitlement 導出への差し替え)。
 *
 * テンプレート既定は最小実装: Cashier の `subscription('default')` が
 * active / trialing なら許可。past_due / canceled / incomplete 等は不許可
 * (未契約と同じ扱いで billing へ誘導する)。
 */
class BillingAccess
{
    /** アクセスを許可する Stripe subscription status */
    private const array GRANTING_STATUSES = ['active', 'trialing'];

    public function hasActiveAccess(Organization $organization): bool
    {
        $subscription = $organization->subscription('default');

        // subscription 不在 (未契約) は fail-closed で不許可
        return $subscription !== null
            && in_array($subscription->stripe_status, self::GRANTING_STATUSES, true);
    }
}

```

### app/Support/ProductionEnvGuard.php (現行)
```
<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;
use Throwable;

/**
 * production env に必要な必須項目を検査し、違反があれば fail-fast する SSOT。
 *
 * AppServiceProvider::boot() (production 起動時) と production:preflight コマンドの
 * 双方から参照される。検査項目:
 * - APP_KEY / CIPHERSWEET_KEY 非空 (暗号化キー未設定の起動防止)
 * - STRIPE_WEBHOOK_SECRET 非空 (Cashier の署名検証 silent skip 防止)
 * - SESSION_SECURE_COOKIE=true (HTTPS Cookie 必須)
 * - APP_DEBUG=false (stack trace / 設定露出防止)
 * - SECURITY_HSTS_ENABLED / SECURITY_CSP_ENABLED=true (セキュリティヘッダ必須)
 * - DEBUG_LOGIN_USER / DEBUG_LOGIN_PASSWORD が空 (local 専用機構の誤投入防止)
 * - TrustHosts allowlist (Host header injection 防御の allowlist 非空・書式)
 */
class ProductionEnvGuard
{
    /**
     * production env に必要な必須項目を検査し、違反メッセージのリストを返す。
     *
     * @return list<string>
     */
    public function violations(): array
    {
        $errors = [];

        $appKeyValue = config('app.key');
        $appKey = is_string($appKeyValue) ? $appKeyValue : '';
        if ($appKey === '') {
            $errors[] = 'APP_KEY is required in production.';
        }

        $cipherKeyValue = config('ciphersweet.providers.string.key');
        $cipherKey = is_string($cipherKeyValue) ? $cipherKeyValue : '';
        if ($cipherKey === '') {
            $errors[] = 'CIPHERSWEET_KEY is required in production (PII encryption key).';
        }

        $stripeSecretValue = config('cashier.webhook.secret');
        $stripeSecret = is_string($stripeSecretValue) ? $stripeSecretValue : '';
        if ($stripeSecret === '') {
            $errors[] = 'STRIPE_WEBHOOK_SECRET is required in production '
                .'(Cashier silently skips signature verification when missing).';
        }

        if (config('session.secure') !== true) {
            $errors[] = 'SESSION_SECURE_COOKIE must be true in production '
                .'(current: '.var_export(config('session.secure'), true).').';
        }

        // APP_DEBUG=true は本番で stack trace / env 露出を招くため禁止。
        if (config('app.debug') === true) {
            $errors[] = 'APP_DEBUG must be false in production '
                .'(true leaks stack traces and configuration via error pages).';
        }

        if (config('security.hsts.enabled') !== true) {
            $errors[] = 'SECURITY_HSTS_ENABLED must be true in production.';
        }

        if (config('security.csp.enabled') !== true) {
            $errors[] = 'SECURITY_CSP_ENABLED must be true in production.';
        }

        $debugUserValue = config('debug.login.user');
        $debugPasswordValue = config('debug.login.password');
        $debugUser = is_string($debugUserValue) ? $debugUserValue : '';
        $debugPassword = is_string($debugPasswordValue) ? $debugPasswordValue : '';
        if ($debugUser !== '' || $debugPassword !== '') {
            $errors[] = 'DEBUG_LOGIN_USER and DEBUG_LOGIN_PASSWORD must be empty in production '
                .'(both are local-dev only; presence indicates dangerous misconfiguration).';
        }

        // Host header injection 防御の TrustHosts allowlist を起動時検証。
        // 純粋クラス TrustedHostsConfigValidator に委譲し、throw を violation メッセージへ写像する。
        $exact = $this->stringList(config('trusted_hosts.exact_hosts', []));
        $wildcard = $this->stringList(config('trusted_hosts.wildcard_suffixes', []));
        $rawWildcards = $this->stringList(config('trusted_hosts.raw_wildcard_suffixes', []), keepEmpty: true);
        try {
            (new TrustedHostsConfigValidator)->validateForProduction($exact, $wildcard, $rawWildcards);
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }

        return $errors;
    }

    /**
     * production 起動時に違反があれば例外で fail-fast。
     */
    public function enforce(): void
    {
        $errors = $this->violations();
        if ($errors !== []) {
            throw new RuntimeException(
                "Production env baseline violations:\n- ".implode("\n- ", $errors)
            );
        }
    }

    /**
     * config 値を string list へ正規化する (非 string 要素を除外)。
     *
     * @return list<string>
     */
    private function stringList(mixed $value, bool $keepEmpty = false): array
    {
        if (! is_array($value)) {
            return [];
        }

        $result = [];
        foreach ($value as $item) {
            if (! is_string($item)) {
                continue;
            }
            if (! $keepEmpty && $item === '') {
                continue;
            }
            $result[] = $item;
        }

        return $result;
    }
}

```

### database/seeders/BughuntOAuthSeeder.php (現行 L1-100 抜粋)
```
<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\OAuth\OAuthClientKind;
use App\Models\OauthSession;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Client;
use Webmozart\Assert\Assert;

/**
 * bug-hunt env 専用: CLI OAuth client / CLI session / legacy MCP token を seed する。
 *
 * 目的 (検証カバレッジ拡充):
 *  - public CLI OAuth client を 1 件作り `/api/v1/version` が `cli_oauth_client_id` を
 *    advertise できるようにする (= CLI の `login --no-browser` の client-id 解決)。
 *  - 代表 user に active な CLI session + legacy MCP token を付与し
 *    セッション revoke 導線 (sessions.destroy / legacy.destroy) を踏めるようにする。
 *
 * 三重 fail-secure: (1) `config('testing.fake_externals') === true`、(2) `app()->environment('bughunt.local')`、
 * (3) 接続先 DB 名が `^bug_hunt(_[1-8])?$` の全成立時のみ実行。いずれか欠ければ no-op
 * (production/dev DB で誤実行しても認証状態をばら撒かない fail-secure)。
 *
 * ★ 有効化条件: 本 seeder は OAuth 基盤 (Passport + oauth_sessions / CliOAuthScope / cli:client command) を
 *   前提にする。外部 fake 基盤 (config('testing.fake_externals')) が未導入のテンプレートでは
 *   第 1 ガードで常に no-op になり、provision から呼ばれても副作用を持たない (安全な同梱)。
 *
 * 冪等 = 「探索前提の active 状態を毎回回復する」。revoke 後の reseed でも active を再保証する。
 */
class BughuntOAuthSeeder extends Seeder
{
    /** bug-hunt DB 名の許容 regex (bug-hunt 隔離規約と一致)。 */
    private const string BUGHUNT_DB_REGEX = '/^bug_hunt(_[1-8])?$/';

    /** legacy MCP token の決定論 id (冪等キー)。char(80) PK に収まる固定値。 */
    private const string LEGACY_MCP_TOKEN_ID_PREFIX = 'bughunt-legacy-mcp-token';

    /** CLI session の決定論 UUID (冪等キー)。 */
    private const string CLI_SESSION_ID = '00000000-0000-4000-8000-000000000001';

    /** CLI session 配下 access token の決定論 id prefix。 */
    private const string CLI_ACCESS_TOKEN_ID_PREFIX = 'bughunt-cli-access-token';

    public function run(): void
    {
        // fail-secure 三軸: fake_externals かつ bughunt.local かつ DB 名 bug_hunt* の全成立時のみ。
        $dbName = DB::connection()->getDatabaseName();
        if (
            config('testing.fake_externals') !== true
            || ! app()->environment('bughunt.local')
            || preg_match(self::BUGHUNT_DB_REGEX, $dbName) !== 1
        ) {
            $this->command->warn('BughuntOAuthSeeder: fake_externals / bughunt.local / bug_hunt DB のいずれか不成立のため skip (production/dev safety)。');

            return;
        }

        // 代表 user: current organization を持つ最初のユーザー (ManualTestSeeder 投入前提)。
        // アプリのテストアカウント email 規則に依存しないよう関係で解決する。
        $user = $this->resolveRepresentativeUser();
        if (! $user instanceof User) {
            $this->command->warn('BughuntOAuthSeeder: current organization を持つ user が無いため skip。先に ManualTestSeeder を流すこと。');

            return;
        }

        $org = $user->currentOrganization;
        if (! $org instanceof Organization) {
            $this->command->warn('BughuntOAuthSeeder: 代表 user に current organization が無いため skip。');

            return;
        }

        $cliClientId = $this->seedCliClient();
        $this->seedCliSession($user, $org, $cliClientId);
        $this->seedLegacyMcpToken($user, $org);

        $this->command->info('BughuntOAuthSeeder: seeded CLI OAuth client + CLI session + legacy MCP token.');
    }

    /**
     * current organization を持つ代表ユーザーを 1 件解決する (email 規則非依存)。
     */
    private function resolveRepresentativeUser(): ?User
    {
        return User::query()
            ->whereNotNull('current_organization_id')
            ->orderBy('id')
            ->first();
    }

    /** @param list<string> $scopes */
    private function encodeScopes(array $scopes): string
    {

```

### database/seeders/AdminUserSeeder.php (現行)
```
<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\AdminUser;
use Illuminate\Database\Seeder;

/**
 * local 開発専用の固定 AdminUser を投入する Seeder (冪等)。
 *
 * テンプレート判断: local 開発 DX のため固定値 seeder を維持するが、
 * 正式な admin 発行経路は `php artisan admin:create` コマンドである
 * (env ADMIN_INITIAL_* による初期 admin 投入は廃止済み)。
 * **本番では本 Seeder を使わず admin:create を使うこと**。
 * 誤って production / staging / CI で db:seed されても作成しないよう
 * local 環境以外では skip する。
 */
class AdminUserSeeder extends Seeder
{
    private const EMAIL = 'admin@example.com';

    private const PASSWORD = 'password12345';

    private const NAME = 'Local Admin';

    public function run(): void
    {
        // local 専用 (本番の初期 admin は admin:create コマンドで発行する)
        if (! app()->environment('local')) {
            return;
        }

        // email は CipherSweet 暗号化カラムのため firstOrCreate(['email' => ...]) では
        // 既存行に hit しない。blind index (whereBlind) で冪等化する
        // (再実行してもパスワードは上書きしない)
        $admin = AdminUser::whereBlind('email', 'email_index', self::EMAIL)->first()
            ?? AdminUser::create([
                'email' => self::EMAIL,
                'name' => self::NAME,
                'password' => self::PASSWORD, // hashed cast が自動でハッシュ化する
            ]);

        $this->command->info(sprintf(
            'AdminUser (local 開発用): %s (id=%d, password=%s)',
            self::EMAIL,
            $admin->id,
            self::PASSWORD,
        ));
    }
}

```

### database/seeders/ManualTestSeeder.php (現行 L1-100 抜粋、非接触)
```
<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\OrganizationRole;
use App\Models\Billing\Plan;
use App\Models\Organization;
use App\Models\User;
use App\Services\Organization\OrganizationProvisioningService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * 手動テスト用シーダー (ドメイン非依存の汎用版)。
 * プランごとに組織を 1 つ作り、各組織ロールのユーザーを総当たりで投入する。
 * さらに複数組織所属ユーザーとメール未認証ユーザーを 1 人ずつ作る。
 *
 * 実行: php artisan db:seed --class=ManualTestSeeder
 * 全ユーザーのパスワード: password123
 * email 規則: {role}-{plan_code}@example.com (例: owner-free@example.com)
 */
class ManualTestSeeder extends Seeder
{
    private const PASSWORD = 'password123';

    public function run(): void
    {
        // 前提の参照データ (すべて冪等)
        $this->call([
            RoleSeeder::class,
            PermissionSeeder::class,
            RolePermissionSeeder::class,
            PlanSeeder::class,
        ]);

        $plans = Plan::query()->orderBy('sort_order')->get();
        if ($plans->isEmpty()) {
            $this->command->warn('ManualTestSeeder: plans が空のため skip しました。');

            return;
        }

        // 冪等チェック (最初のプランの Owner ユーザーが居れば投入済みとみなす)
        $firstEmail = $this->email(OrganizationRole::Owner, $plans->first());
        if (User::whereBlind('email', 'email_index', $firstEmail)->exists()) {
            $this->command->info('ManualTestSeeder: 投入済みのため skip しました。');

            return;
        }

        $rows = [];
        $organizations = [];

        foreach ($plans as $plan) {
            $organization = null;

            foreach (OrganizationRole::cases() as $role) {
                $email = $this->email($role, $plan);
                $user = $this->createUser($this->displayName($role, $plan), $email);

                if ($organization === null) {
                    // 最初のユーザー (Owner) を creator として組織を provisioning する
                    // (Default Team + Owner ロール + current_organization_id まで揃う)
                    $organization = $this->createOrganization($user, $plan);
                } else {
                    $this->addToOrganization($user, $organization, $role, current: true);
                }

                $rows[] = [$email, $organization->name, $role->label(), $plan->code];
            }

            $organizations[] = $organization;
        }

        // 複数組織所属ユーザー (全プラン組織に Member で所属。組織切替の手動テスト用)
        $multi = $this->createUser('Multi Org User', 'multi-org@example.com');
        foreach ($organizations as $index => $organization) {
            $this->addToOrganization($multi, $organization, OrganizationRole::Member, current: $index === 0);
        }
        $rows[] = ['multi-org@example.com', '全プラン組織', OrganizationRole::Member->label(), '-'];

        // メール未認証ユーザー (メール認証フローの手動テスト用)
        $unverified = $this->createUser('Unverified User', 'unverified@example.com', verified: false);
        $this->addToOrganization($unverified, $organizations[0], OrganizationRole::Member, current: true);
        $rows[] = ['unverified@example.com', $organizations[0]->name, OrganizationRole::Member->label().' (未認証)', $plans->first()->code];

        $this->command->info('ManualTestSeeder: 投入完了 (パスワードは全員 '.self::PASSWORD.')');
        $this->command->table(['Email', 'Organization', 'Role', 'Plan'], $rows);
    }

    /**
     * email 規則: {role}-{plan_code}@example.com (role は enum 値の末尾セグメント)。
     */
    private function email(OrganizationRole $role, Plan $plan): string
    {
        return Str::afterLast($role->value, '_')."-{$plan->code}@example.com";
    }


```

### tests/Pest.php (createFakeSubscription helper 抜粋 L120-155)
```
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

```

### /workspace/app/Models/Billing/Subscription.php
```
<?php

declare(strict_types=1);

namespace App\Models\Billing;

use App\Enums\Billing\ScheduleSetupStatus;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Laravel\Cashier\Subscription as CashierSubscription;

/**
 * Cashier Subscription のテンプレート拡張 (AppServiceProvider の
 * Cashier::useSubscriptionModel で差し替え登録)。
 *
 * 追加列:
 * - current_period_end: 次回更新日時 (renewal reminder の真実源。
 *   StripeWebhookProcessor が customer.subscription.created/updated から同期する)
 * - stripe_schedule_id / schedule_setup_status: Subscription Schedule の
 *   2 段 API call (create → update phases) の部分完了追跡
 *   (billing:reconcile-schedules が復旧する。ScheduleSetupStatus 参照)
 *
 * schedule 列は状態キーのため markSchedule* / clearSchedule 経由でのみ変更する。
 *
 * @property int $id
 * @property int $organization_id
 * @property string $stripe_id
 * @property string $stripe_status
 * @property Carbon|null $current_period_end
 * @property string|null $stripe_schedule_id
 * @property ScheduleSetupStatus $schedule_setup_status
 */
class Subscription extends CashierSubscription
{
    /**
     * Cashier 既定は $guarded=[] (全開放) だが、テナント/所有権キーを payload から
     * 信頼しない不変条件 (MassAssignmentSafetyTest) に合わせて id / organization_id を
     * guard する。organization_id は billable relation (Organization->subscriptions()) が
     * FK として自動設定し、Cashier は mass-assign しないため課金経路は不変。
     *
     * @var list<string>
     */
    protected $guarded = ['id', 'organization_id'];

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** schedule 生成 (create API 成功) を記録する。phases 未設定の部分完了状態。 */
    public function markScheduleCreated(string $scheduleId): void
    {
        $this->forceFill([
            'stripe_schedule_id' => $scheduleId,
            'schedule_setup_status' => ScheduleSetupStatus::Created,
        ])->save();
    }

    /** phases 設定完了 (update API 成功) を記録する。 */
    public function markScheduleConfigured(): void
    {
        $this->forceFill([
            'schedule_setup_status' => ScheduleSetupStatus::Configured,
        ])->save();
    }

    /** schedule の解除 (release / remote 消失) を記録する。 */
    public function clearSchedule(): void
    {
        $this->forceFill([
            'stripe_schedule_id' => null,
            'schedule_setup_status' => ScheduleSetupStatus::None,
        ])->save();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'current_period_end' => 'datetime',
            'schedule_setup_status' => ScheduleSetupStatus::class,
        ];
    }
}

```

### /workspace/app/DataTransferObjects/Billing/CreatedCheckoutSession.php
```
<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Billing;

use Carbon\CarbonImmutable;

/**
 * Gateway が作成した Stripe Checkout Session の snapshot (hosted mode 前提)。
 */
final readonly class CreatedCheckoutSession
{
    public function __construct(
        public string $sessionId,
        public string $url,
        public CarbonImmutable $expiresAt,
    ) {}
}

```

### scripts/bug-hunt-shard.sh (asset freshness guard + cmd_provision + cmd_reseed 抜粋 L404-600, L712-752)
```
# --- asset freshness guard (stale public/build による配信ドリフトを塞ぐ) --------
# setup-worktree.sh が親の build を cp -r で複製する。存在のみ判定だと stale-but-present な
# manifest を fresh 扱いして古い配信物を配ってしまう。ビルド入力の content fingerprint +
# manifest chunk 実在で鮮度判定する。

BUILD_INPUT_PATHS=(resources package.json pnpm-lock.yaml vite.config.* svelte.config.* tailwind.config.* postcss.config.* tsconfig*.json)

compute_build_fingerprint() {
    {
        local p
        for p in "${BUILD_INPUT_PATHS[@]}"; do
            [[ -e "${p}" ]] || continue
            if [[ -d "${p}" ]]; then
                find "${p}" -type f -print0 | LC_ALL=C sort -z | xargs -0 -r sha256sum --
            else
                sha256sum -- "${p}"
            fi
        done
    } | sha256sum | awk '{print $1}'
}

build_inputs_dirty() {
    git rev-parse --is-inside-work-tree >/dev/null 2>&1 || return 1
    [[ -n "$(git status --porcelain -- "${BUILD_INPUT_PATHS[@]}" 2>/dev/null)" ]]
}

manifest_chunks_present() {
    [[ -s public/build/manifest.json ]] || return 1
    php -r '
        $dir = "public/build";
        $m = json_decode(file_get_contents("$dir/manifest.json"), true);
        if (!is_array($m)) { exit(1); }
        $files = [];
        $seen = [];
        $collect = function ($key, $entry) use (&$files, &$seen, $m, &$collect) {
            if (isset($seen[$key])) { return; }
            $seen[$key] = true;
            if (!empty($entry["file"])) { $files[$entry["file"]] = true; }
            foreach (["css", "assets"] as $list) {
                if (!empty($entry[$list])) { foreach ($entry[$list] as $a) { $files[$a] = true; } }
            }
            foreach (["imports", "dynamicImports"] as $rel) {
                if (!empty($entry[$rel])) {
                    foreach ($entry[$rel] as $k) {
                        if (!isset($m[$k])) { fwrite(STDERR, "dangling ref: $k\n"); exit(1); }
                        $collect($k, $m[$k]);
                    }
                }
            }
        };
        foreach ($m as $k => $entry) { if (is_array($entry)) { $collect($k, $entry); } }
        foreach (array_keys($files) as $f) {
            if (!is_file("$dir/$f")) { fwrite(STDERR, "missing chunk: $f\n"); exit(1); }
        }
        exit(0);
    ' >/dev/null 2>&1
}

# 配信物 (public/build) が現行ソースと不一致か (= rebuild が必要か) の判定 SoT。
# 副作用なし。stale (= 要 build) なら 0、fresh なら 1。
assets_are_stale() {
    local fp_file=public/build/.bughunt-build-fingerprint
    local current saved=""
    current="$(compute_build_fingerprint)"
    [[ -f "${fp_file}" ]] && saved="$(cat "${fp_file}")"

    [[ ! -s public/build/manifest.json ]] && return 0
    [[ -z "${saved}" || "${saved}" != "${current}" ]] && return 0
    build_inputs_dirty && return 0
    manifest_chunks_present || return 0
    return 1
}

ensure_fresh_assets() {
    is_dryrun && return 0

    # bug-hunt は配信物 (public/build) を使う。dev server marker が残ると Vite が hot を参照するため除去する。
    if [[ -e public/hot ]]; then
        echo ">>> bug-hunt uses built assets; removing public/hot"
        rm -f public/hot
    fi

    if assets_are_stale; then
        echo ">>> assets stale → pnpm build"
        pnpm build
        compute_build_fingerprint > public/build/.bughunt-build-fingerprint
    fi
}

# 配信物 (public/build) が現行ソースと一致するかを検査する read-only ゲート。
# 契約: build しない / public/hot を触らない / DB に触らない / fingerprint を書かない。exit: fresh=0 / stale=1。
cmd_assets_check() {
    if [[ -e public/hot ]]; then
        echo "error: assets-check: public/hot が存在 (Vite dev-server マーカー)。bug-hunt は built assets を使うため hot 経由だと配信物が現行ソースと乖離する。" >&2
        echo "  対処: 再 provision する (ensure_fresh_assets が public/hot を除去し rebuild する)。" >&2
        return 1
    fi
    if ! assets_are_stale; then
        echo "assets-check: public/build は現行ソースと一致 (fresh)"
        return 0
    fi
    if [[ ! -s public/build/manifest.json ]]; then
        echo "error: assets-check: public/build/manifest.json が無い/空。対処: 再 provision (または pnpm build)。" >&2
    elif [[ ! -s public/build/.bughunt-build-fingerprint ]]; then
        echo "error: assets-check: fingerprint 記録 (.bughunt-build-fingerprint) が無い/空。対処: 再 provision (または pnpm build) で記録を生成。" >&2
    elif [[ "$(cat public/build/.bughunt-build-fingerprint)" != "$(compute_build_fingerprint)" ]]; then
        echo "error: assets-check: public/build が現行ソースと不一致 (fingerprint mismatch = ソース更新後に未 rebuild)。対処: --keep-db を外して再 provision するか pnpm build 後に serve 再起動。" >&2
    elif build_inputs_dirty; then
        echo "error: assets-check: ビルド入力に未コミット変更 (dirty)。対処: 作業ツリーを整理し再 provision する。" >&2
    elif ! manifest_chunks_present; then
        echo "error: assets-check: manifest が指す chunk が public/build に不在 (壊れた/部分 build)。対処: 再 provision (または pnpm build)。" >&2
    else
        echo "error: assets-check: public/build が現行ソースと不一致 (要再 build)。対処: 再 provision するか pnpm build 後に serve 再起動。" >&2
    fi
    return 1
}

cmd_keepdb_check() {
    local shard=$1
    cmd_assets_check || die 1 "--keep-db reuse 中止: アセットが stale (上記理由)。provision をスキップせず再 provision してください。"
    local url code
    url="$(shard_url "${shard}")"
    code="$(curl -s -o /dev/null -w '%{http_code}' "${url}/login" || true)"
    [[ "${code}" == "200" || "${code}" == "302" ]] \
        || die 1 "--keep-db reuse 中止: serve (${url}) 応答 ${code} (200/302 期待)。serve 未起動の可能性。"
    echo "keepdb-check: assets fresh + serve ${code} (reuse 可)"
}

# --- worktree 文脈ガード -------------------------------------------------------
# bug-hunt provision を worktree 外 (main checkout) から起動するのを in-script で fail-closed 拒否する。
assert_worktree_context() {
    is_dryrun && return 0
    if [[ -n "${BUGHUNT_ALLOW_MAIN:-}" ]]; then
        echo "warning: BUGHUNT_ALLOW_MAIN=1 で worktree 外 (main) 走行を許可。skill Phase 0a を意図的にスキップ — todo/ ブランチ隔離なし・main を直接汚す" >&2
        return 0
    fi
    local gd cgd
    gd="$(cd "${WORKSPACE}" 2>/dev/null && cd "$(git rev-parse --absolute-git-dir 2>/dev/null)" 2>/dev/null && pwd -P)" \
        || die 1 "worktree 判定不能: ${WORKSPACE} が git リポジトリでない。skill (app-bug-hunt) Phase 0a で worktree を切ってから走らせること"
    cgd="$(cd "${WORKSPACE}" 2>/dev/null && cd "$(git rev-parse --git-common-dir 2>/dev/null)" 2>/dev/null && pwd -P)" \
        || die 1 "worktree 判定不能: ${WORKSPACE} の git-common-dir を解決できない"
    if [[ "${gd}" == "${cgd}" ]]; then
        die 1 "bug-hunt を worktree 外 (main: ${WORKSPACE}) から起動しようとしています。\
skill (app-bug-hunt) の Phase 0a は worktree 既定です — main を直接汚さず todo/ ブランチに隔離するため。\
正しい起動: /app-bug-hunt 経由、または \`scripts/setup-worktree.sh bughunt-<date>\` で worktree を切り、その中で本スクリプトを実行する。\
意図的な main 走行 (--keep-db 連続再走など asset 既存の単発確認) のみ BUGHUNT_ALLOW_MAIN=1 を付ける。"
    fi
}

# --- provision ----------------------------------------------------------------

cmd_provision() {
    local shard=$1 run_id=$2
    require_orchestrator "provision"
    assert_worktree_context
    local db port url
    db="$(shard_db "${shard}")"; port="$(shard_port "${shard}")"; url="$(shard_url "${shard}")"
    mkdir -p "$(shard_report_dir "${shard}" "${run_id}")/screenshots" "${TMP_BASE}" \
        "$(shard_profile_dir "${shard}")" "$(shard_download_dir "${shard}")" "$(shard_trace_dir "${shard}")"

    if is_dryrun; then
        manifest_update "${run_id}" "${shard}" \
            "db=\"${db}\"" "port=${port}" "app_url=\"${url}\"" \
            "log_offset=0" "serve_pid=0" "stories=\"(dryrun)\"" \
            "coverage=$( [[ -n "${COVERAGE:-}" ]] && echo true || echo false )"
        generate_wrapper "${shard}" "${run_id}"
        return 0
    fi

    [[ -f "${ENV_FILE}" ]] || die 1 "${ENV_FILE} が無い。先に \`cp .env.bughunt.local.example .env.bughunt.local\` と \`APP_ENV=bughunt.local php artisan key:generate --env=bughunt.local\` を実行すること"
    command -v psql >/dev/null || die 1 "psql クライアントが無い (postgresql-client を導入すること)"

    env_file_required APP_KEY > /dev/null
    env_file_required DB_HOST > /dev/null
    env_file_required DB_PORT > /dev/null
    env_file_required BUGHUNT_ADMIN_USER > /dev/null
    [[ "$(env_file_get APP_ENV)" == "bughunt.local" ]] || die 1 "${ENV_FILE} の APP_ENV が bughunt.local でない"
    [[ "$(env_file_get DB_USERNAME)" == "bughunt" ]] || die 1 "${ENV_FILE} の DB_USERNAME は bughunt 固定"

    clear_stale_config
    ensure_fresh_assets

    # (a) DB 作成 (admin 経路。既存なら skip。中身は次の migrate:fresh が正本)
    if ! pg_owner_for_shard exists "${db}" | grep -q 1; then
        pg_admin_for_provision createdb "${db}"
    fi

    # (b) migrate:fresh + seed (runtime 経路、自 DB のみ)。テンプレート共通シーダーのみ実行する
    #     (ドメイン固有シーダーはアプリ側で本ブロックに追記する)。
    artisan_for_shard "${db}" "${url}" migrate:fresh --seed --force
    artisan_for_shard "${db}" "${url}" db:seed --class=ManualTestSeeder --force
    # 管理画面 (Filament admin) 探索用 admin user。AdminUserSeeder は local 限定 (DatabaseSeeder が
    # local でしか呼ばない) のため bughunt では明示 seed する。admin MFA は .env.bughunt.local の
    # ADMIN_MFA_REQUIRED=false で無効化済 (email+password ログイン可)。
    artisan_for_shard "${db}" "${url}" db:seed --class=AdminUserSeeder --force
    # CLI OAuth client + CLI session + legacy MCP token を直付与 (fake_externals かつ bughunt.local かつ
    # bug_hunt DB の三重ガード付き。config('testing.fake_externals') 未導入なら seeder 側で no-op)。

```

### scripts/bug-hunt-shard.sh (cmd_reseed 抜粋 L740-752)
```
# --- 子セッション safe subcommands ---------------------------------------------

cmd_reseed() {
    local shard=$1 run_id=$2
    local db url
    db="$(shard_db "${shard}")"; url="$(shard_url "${shard}")"
    artisan_for_shard "${db}" "${url}" migrate:fresh --seed --force
    artisan_for_shard "${db}" "${url}" db:seed --class=ManualTestSeeder --force
    artisan_for_shard "${db}" "${url}" db:seed --class=AdminUserSeeder --force
    artisan_for_shard "${db}" "${url}" db:seed --class=BughuntOAuthSeeder --force
    echo "reseeded: ${db}"
}


```

### tests/Feature/Admin/AdminUserSeederTest.php (現行)
```
<?php

declare(strict_types=1);

use App\Models\AdminUser;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Support\Facades\Hash;

/*
 * AdminUserSeeder: local 開発専用の固定 AdminUser を作成する
 * (email は暗号化カラムのため whereBlind で冪等化)。
 * local 以外の環境では skip する (本番の初期 admin は admin:create コマンド)。
 */

test('local 以外の環境 (testing) では AdminUser を作成しない (skip)', function (): void {
    $this->seed(AdminUserSeeder::class);

    expect(AdminUser::query()->count())->toBe(0);
});

test('local 環境では固定 AdminUser を作成し、再実行しても増えない (冪等)', function (): void {
    $this->app['env'] = 'local';

    $this->seed(AdminUserSeeder::class);
    $this->seed(AdminUserSeeder::class);

    $admins = AdminUser::whereBlind('email', 'email_index', 'admin@example.com')->get();
    expect($admins)->toHaveCount(1);

    $admin = $admins->first();
    expect($admin?->name)->toBe('Local Admin');
    expect(Hash::check('password12345', $admin?->password ?? ''))->toBeTrue();
});

test('再実行時に既存 AdminUser のパスワードを上書きしない', function (): void {
    $this->app['env'] = 'local';
    $this->seed(AdminUserSeeder::class);

    // 運用 (開発) で変更されたパスワードを seeder の再実行が巻き戻さないこと
    $admin = AdminUser::whereBlind('email', 'email_index', 'admin@example.com')->firstOrFail();
    $admin->password = 'ChangedPassword456';
    $admin->save();

    $this->seed(AdminUserSeeder::class);

    $admin->refresh();
    expect(Hash::check('ChangedPassword456', $admin->password))->toBeTrue();
});

```
