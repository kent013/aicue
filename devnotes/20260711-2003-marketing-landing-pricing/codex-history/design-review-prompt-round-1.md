# 使命・禁止事項・思考原則（AGENTS.md より挿入）

## アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> v1 スコープ: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

## 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)

## セキュリティ不変条件（アプリ都合で緩めない）

1. **tenant キー不信**: ownership/actor/tenant キーを payload から受け取らない
2. **子は親に属する**: nested route の不整合は認可より前に 404
3. **cross-org 不可**: 組織を跨ぐ read/write をしない
4. **untrusted 文字列は UserInput 型経由でのみ prompt に入れる**
5. **権限判定は常に `laratrust_team_id` を明示**
6. **PII は CipherSweet。検索は `whereBlind()`**
7. **課金の冪等性**: webhook は冪等マシン経由、チケットは reserve→commit/release の 2 フェーズ
8. **外部 URL 取得は SSRF 検査経由**

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

# system: 役割・タスク

あなたは経験豊富なWebアプリケーションアーキテクトです。Laravel + Svelte アプリケーション改善の詳細設計をレビューしてください。

【前提環境】
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- PHPStan level 10
- Pestテストフレームワーク（RefreshDatabase は tests/Pest.php でグローバル適用・--parallel）
- DTO + JsonResource パターン
- Laratrust RBAC（Organization → Team → Project階層）
- Stripe は Laravel Cashier（Billable = Organization）。webhook は StripeWebhookProcessor（冪等マシン: event_id UNIQUE claim + failed→received 復帰 + attempts 上限 8 で terminal-ack）

【レビュー観点】
1. コードの正確性（ロジックエラー、エッジケース、null安全性）
2. 既存コードとの整合性（命名規約、パターン、API）
3. PHPStan level 10 適合性（型安全性、generics、Assert使用）
4. テスト計画の網羅性（各施策にPestテスト、RefreshDatabaseグローバル適用に従う）
5. DTO/JsonResource パターンの遵守
6. Inertia Props vs API Responseの使い分け
7. 副作用・後退リスク
8. 波及変更の網羅性（TypeScript型定義、API Resource、テストが変更対象に含まれているか）
9. セキュリティ（認可チェック、入力バリデーション、OWASP Top 10、AGENTS.md のセキュリティ不変条件: tenant キー不信 / cross-org 不可 / 課金の冪等性）
10. DESIGN.md準拠（DS token 経由の参照か、hex 直書きを増やさないか）
11. Atomic Design準拠（atoms/molecules/organisms/templates の責務分離、Lucide 前提で SVG 直書き新設なし）

【補足】本設計は概念設計の Codex 合議（gpt-5.4、Round 4 APPROVED）を経ている。ドナー /tmp/aigenba の構造・価格値の移植はユーザー明示指示。必要ならリポジトリのファイルを読んでよい（読み込みのみ）。

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

---

# user: 詳細設計書

# 詳細設計: marketing-landing-pricing（LP + 料金表 + チケットリチャージ）

作成: 2026-07-11 / 概念設計: `devnotes/20260711-2003-marketing-landing-pricing/conceptual-design.md`（Codex 概念レビュー Round 4 APPROVED）

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

### 禁止事項（AGENTS.md）

1. テストなしの実装完了報告（不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」）
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作（`migrate:fresh` 等）をエージェント判断で実行すること
4. `response()->json()` の直書き（DTO / JsonResource / Inertia を使う）
5. LLM 呼び出しの Prism 直呼び（本設計は LLM 非関与）
6. prompt 文字列のコード直書き（本設計は prompt 非関与）
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI（押下時にエラー表示する）

### セキュリティ不変条件（本設計で特に効くもの）

- **tenant キー不信**: 購入 POST の payload は `count` / `attempt_token` のみ。金額・Price ID・organization_id は受け取らない（`ProhibitsProtectedKeys`）。webhook の metadata / customer は照合専用。
- **cross-org 不可**: 購入・残高参照は `ResolvesCurrentOrganization` の current org のみ。
- **課金の冪等性**: webhook は既存冪等マシン（`StripeWebhookProcessor` claim + 台帳 idempotency_key UNIQUE）経由。fail-closed は retryable failure（例外 throw）で実現。

### コーディングルール

- **PHPStan level 10** 必須（`composer phpstan`）
- **Pest**（`composer test`。`RefreshDatabase` は `tests/Pest.php` でグローバル適用、個別 `DatabaseTransactions` 禁止、`--parallel`）
- **テストデータは必ず Factory で生成**（`Model::create()` 手組み禁止）。新モデル `TicketCheckoutSession` は Factory を同時作成
- **DTO + JsonResource** パターン / **アーリーリターン** / `declare(strict_types=1)` + 日本語コメント
- Controller は薄く（Service 委譲）、transaction は Service 内。保護キーは forceFill / relation / 明示代入
- フォーマット: `composer fix`（Pint）/ `pnpm lint:fix`。PHP 8.4 + Laravel 12 + Svelte 5 runes + Inertia + TS
- フロント: DS token のみ（ds-purity）/ Lucide のみ / Atomic 単方向 import / disabled 禁止
- 新規モデル追加時は `docs/architecture.md` / `docs/factories.md` へ追記

## 概念設計リファレンス

`devnotes/20260711-2003-marketing-landing-pricing/conceptual-design.md`（Round 1〜4 のレビュー履歴・対応マトリクスは同ディレクトリ `codex-history/`）

## 前提事実（現行コード調査結果）

- `ContactUrl`（`app/Services/Marketing/ContactUrl.php`）は **AI-CUE に既存**（`resolve(): string` + `kind(): ContactDestinationKind`、scheme allowlist fail-close 済み）。aigenba からの移植は不要で**そのまま再利用**する。source attribution は呼び出し側が `?source=` を付与する（内部 route のときのみ）。
- `InquirySource` は `landing` / `billing` のみ → `pricing` case を追加する（`normalize()` は cases 由来なので自動追随）。
- `TicketVolumePrice::currentTierFor()` / `TicketVolumePriceSeeder`（1〜¥100 / 20〜¥80 / 50〜¥70 / 100〜¥65 / 200〜¥60 / 300〜¥55 / 500〜¥50）/ `config/billing.php`（signup 10 枚・30 日・floor ¥50）は既存・aigenba と同値。**Seeder は DatabaseSeeder 未登録**（オプトイン）→ 登録する。
- `TicketLedgerService::grantPurchased(Organization, int $amount, string $stripeSessionId, ?string $paymentIntentId, ?int $purchaseAmount)` は idempotency_key `purchase:{sessionId}` の冪等 insert 済み。`clawbackPurchasedByPaymentIntent` も既存。
- `StripeWebhookProcessor` は claim（event_id UNIQUE + failed→received 復帰 + attempts 上限 8 の terminal-ack）を備え、`HandledStripeWebhookEvent::CheckoutSessionCompleted` は **no-op 拡張点**。
- 課金ゲート `require-active-subscription` は gated group への opt-in。billing.* は group 外に個別登録（新 route も同じ位置に置く）。
- 402 は `InsufficientTicketsException` → `InsufficientTicketsResource`（`{code:'insufficient_tickets', message}`、message に「必要 N / 残高 M」）。UI は `AnalysisPanel` / `RenderPanel` の `extractMessage` 表示。
- `Organization` は Cashier `Billable`。one-time Checkout は `$organization->stripe()->checkout->sessions->create($payload, ['idempotency_key' => ...])` で idempotency key を渡せる（Cashier の `checkout()` ヘルパは per-request idempotency key を公開しないため、gateway 実装で Cashier のクライアントを直接使う）。
- `Welcome.svelte` は `appName` prop のみ / `tests/js/pages/Welcome.test.ts` あり。`Pricing.svelte` は props なし。`GuestLayout`（appName + nav/footerLinks snippet）既存。
- Standard プランの現行価格 ¥1,980 の参照点: `database/seeders/PlanSeeder.php` / `stripe/fixtures/plan_standard.json` / `tests/Feature/Billing/BillingPageTest.php` L29 / `VerifyStripePricesCommandTest.php` / `SyncStripePricesCommandTest.php`。

## 施策一覧

| # | 施策名 | 主な変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | マーケ DTO 基盤 + InquirySource 拡張 | `app/DataTransferObjects/Marketing/*`（新規 4 DTO）, `app/Enums/Inquiry/InquirySource.php`, `resources/js/types/marketing.ts`（新規） | P1 |
| 2 | LP（トップ）実装 | `app/Http/Controllers/HomeController.php`, `resources/js/pages/Welcome.svelte`, `tests/js/pages/Welcome.test.ts` | P1 |
| 3 | 料金表実装 | `app/Services/Marketing/PricingService.php`（新規）, `app/Services/Billing/TicketPricingService.php`（新規）, `app/Http/Controllers/Marketing/PricingController.php`（新規）, `routes/web.php`, `database/seeders/DatabaseSeeder.php`, `resources/js/pages/Pricing.svelte`, `resources/js/components/molecules/PricingPlanCard.svelte`（新規） | P1 |
| 4 | チケット購入バックエンド（冪等 Checkout） | migration（新規）, `app/Models/Billing/TicketCheckoutSession.php`（新規）, `app/Enums/Billing/TicketCheckoutSessionStatus.php`（新規）, `app/Services/Billing/TicketCheckoutGateway.php`（新規 interface）+ `CashierTicketCheckoutGateway.php`（新規）, `app/Services/Billing/TicketCheckoutService.php`（新規）, `app/Http/Controllers/Billing/TicketPurchaseController.php`（新規）, `app/Http/Requests/Billing/TicketCheckoutRequest.php`（新規）, `routes/web.php`, `app/Support/Security/MassAssignmentProtectedKeys.php`, `database/factories/Billing/TicketCheckoutSessionFactory.php`（新規） | P1 |
| 5 | webhook 冪等付与（checkout.session.completed） | `app/Services/Billing/StripeWebhookProcessor.php` | P1 |
| 6 | 購入フロント + 残高不足導線 | `resources/js/pages/Billing/PurchaseTickets.svelte`（新規）, `resources/js/types/billing.ts`（新規）, `AnalysisPanel.svelte`, `RenderPanel.svelte`, `resources/js/pages/Billing/Index.svelte`, Vitest 3 本 | P1 |
| 7 | 価格改定（Standard ¥1,980→¥4,980、独立コミット） | `database/seeders/PlanSeeder.php`, `stripe/fixtures/plan_standard.json`, 影響テスト 3 本 | P2 |

依存関係: 1 → 2,3 / 4 → 5,6 / 7 は独立（最後に別コミット）。

---

## 施策1: マーケ DTO 基盤 + InquirySource 拡張

### 変更箇所

- 新規: `app/DataTransferObjects/Marketing/LandingPageDto.php`
- 新規: `app/DataTransferObjects/Marketing/PricingPageDto.php`
- 新規: `app/DataTransferObjects/Marketing/PricingPlanDto.php`
- 新規: `app/DataTransferObjects/Billing/PurchaseTierDto.php`
- 変更: `app/Enums/Inquiry/InquirySource.php`（`case Pricing = 'pricing';` 追加）
- 新規: `resources/js/types/marketing.ts`

### 波及変更

- TypeScript 型定義: `resources/js/types/marketing.ts`（本施策で新規作成。PHP shape と exact 対）
- API Resource/DTO: なし（新規のみ）
- テストファイル: `tests/Feature/Inquiry/*`（InquirySource の cases 列挙に依存するテストがあれば期待値追加。`normalize('pricing')` の受理テストを contact 系テストに追加）
- Filament 管理画面等で InquirySource の label 表示があれば追随（`rg "InquirySource" app/Filament` で確認。match 式に arm 追加が必要なら本施策に含める）

### 変更後コード（形状定義 = 単一真実源）

```php
// app/DataTransferObjects/Marketing/LandingPageDto.php
/**
 * @phpstan-type LandingPageShape array{
 *   signupGrantTickets: int,
 *   contactUrl: string,
 *   contactIsExternal: bool,
 *   isAuthenticated: bool
 * }
 */
final readonly class LandingPageDto
{
    public function __construct(
        public int $signupGrantTickets,
        public string $contactUrl,
        public bool $contactIsExternal,
        public bool $isAuthenticated,
    ) {}

    /** @return LandingPageShape */
    public function toArray(): array { /* 全プロパティを同名 key で返す */ }
}
```

```php
// app/DataTransferObjects/Marketing/PricingPlanDto.php（pricing 表示専用。Billing 内部 DTO と責務分離）
/**
 * @phpstan-type PricingPlanShape array{
 *   code: string,
 *   name: string,
 *   baseAmountJpy: int|null,        // null = 価格なし（Free）
 *   monthlyTicketGrant: int,
 *   maxProjects: int|null,          // null = 無制限（quota limits に key なし）
 *   maxMembers: int|null,
 *   maxStorageGb: int|null          // GiB 換算の表示値
 * }
 */
final readonly class PricingPlanDto { /* コンストラクタ + toArray(): PricingPlanShape */ }
```

```php
// app/DataTransferObjects/Billing/PurchaseTierDto.php（slim: Stripe Price ID / lookup_key は出さない）
/**
 * @phpstan-type PurchaseTierShape array{minCount: int, unitAmount: int}
 */
final readonly class PurchaseTierDto
{
    public function __construct(public int $minCount, public int $unitAmount) {}

    public static function fromTier(TicketVolumeTier $tier): self
    {
        return new self($tier->minCount, $tier->unitAmount);
    }

    /** @return PurchaseTierShape */
    public function toArray(): array { /* ... */ }
}
```

```php
// app/DataTransferObjects/Marketing/PricingPageDto.php
/**
 * @phpstan-import-type PricingPlanShape from PricingPlanDto
 * @phpstan-import-type PurchaseTierShape from PurchaseTierDto
 *
 * @phpstan-type PricingPageShape array{
 *   plans: list<PricingPlanShape>,
 *   ticketTiers: list<PurchaseTierShape>,
 *   spotUnitAmountJpy: int,
 *   signupGrantTickets: int,
 *   signupGrantExpiryDays: int,
 *   analysisTicketCost: int,
 *   renderTicketCost: int,
 *   isAuthenticated: bool,
 *   contactUrl: string,
 *   contactIsExternal: bool
 * }
 */
final readonly class PricingPageDto { /* list<PricingPlanDto> $plans / list<PurchaseTierDto> $ticketTiers ... + toArray() */ }
```

`analysisTicketCost` / `renderTicketCost` は `config('manual.analysis_ticket_cost')` / `config('manual.render_ticket_cost')`（doc/10 §10.5 の COST_ANALYSIS=1 / COST_RENDER=3）を FAQ・チケット説明の表示値として供給する（ハードコードしない）。

```ts
// resources/js/types/marketing.ts（PHP shape と exact 対）
export interface LandingPageProps {
    signupGrantTickets: number;
    contactUrl: string;
    contactIsExternal: boolean;
    isAuthenticated: boolean;
}
export interface PurchaseTierShape { minCount: number; unitAmount: number }
export interface PricingPlanShape {
    code: string; name: string; baseAmountJpy: number | null; monthlyTicketGrant: number;
    maxProjects: number | null; maxMembers: number | null; maxStorageGb: number | null;
}
export interface PricingPageProps {
    plans: PricingPlanShape[]; ticketTiers: PurchaseTierShape[]; spotUnitAmountJpy: number;
    signupGrantTickets: number; signupGrantExpiryDays: number;
    analysisTicketCost: number; renderTicketCost: number;
    isAuthenticated: boolean; contactUrl: string; contactIsExternal: boolean;
}
```

`contactIsExternal` は `ContactUrl::kind() === ContactDestinationKind::External` から導出（mailto は false = 同タブでメーラ起動、aigenba と同値）。

### PHPStan適合チェック

- [x] 戻り値の型が明示されている（全 DTO に `@phpstan-type` shape + `toArray(): Shape`）
- [x] null 安全（baseAmountJpy 等の nullable を shape で明示）
- [x] DTO を返している（連想配列の裸返却なし。Inertia へは `toArray()` の typed array）
- [x] Generics（`list<PricingPlanDto>` 等の型パラメータを docblock で明示）

### テスト計画

- [ ] `tests/Feature/Marketing/` の props 形状検証（施策 2 / 3 のテストに内包: `AssertableInertia` で key/型を固定）
- [ ] `InquirySource::normalize('pricing')` が `Pricing` を返す（既存 contact テストへ 1 ケース追加）

### リスク

- InquirySource case 追加により、cases() を全数比較する既存テストがあれば期待値更新（実装時に `rg "InquirySource::cases"` で確認）。

---

## 施策2: LP（トップ）実装

### 変更箇所

- `app/Http/Controllers/HomeController.php`（`__invoke(Request $request)` 化 + DTO 供給 + lowPriceJpy=0）
- `resources/js/pages/Welcome.svelte`（10 行雛形 → 実 LP。render 先名は `Welcome` のまま）

### 波及変更

- TypeScript 型定義: `types/marketing.ts` の `LandingPageProps`（施策1）
- テストファイル: `tests/js/pages/Welcome.test.ts`（全面書き換え）、`tests/Feature/Seo/SeoHeadCompositionTest.php`（home の JSON-LD 期待に offers が加わる場合は期待値更新）
- なし: ルート（`/` は既存のまま）

### 現行コード（要点）

```php
// HomeController::__invoke(): softwareApplication の lowPriceJpy は null プレースホルダ
JsonLd::softwareApplication($siteName, $this->url->base(), Config::string('seo.default_description'), null),
// ...
return Inertia::render('Welcome', ['appName' => config('app.name')]);
```

### 変更後コード

```php
public function __invoke(Request $request): InertiaResponse
{
    $siteName = Config::string('seo.site_name');

    $this->seo->set(
        SeoMeta::default($this->url, '/')
            ->withTitle($siteName)
            ->withJsonLd([
                JsonLd::organization($siteName, $this->url->base(), $this->url->to('/images/logo.svg')),
                JsonLd::website($siteName, $this->url->base()),
                // Free プランで開始可能 = lowPriceJpy 0 (「無料開始 + チケット制」訴求と一致させる)
                JsonLd::softwareApplication($siteName, $this->url->base(), Config::string('seo.default_description'), 0),
            ]),
    );

    $contact = $this->contactUrl; // constructor injection (ContactUrl)
    $contactUrl = $contact->resolve();
    // 内部フォームのときだけ source attribution を付与 (外部 URL / mailto には付けない)
    if ($contact->kind() === ContactDestinationKind::Internal) {
        $contactUrl .= '?source='.InquirySource::Landing->value;
    }

    $dto = new LandingPageDto(
        signupGrantTickets: $this->ticketPricing->signupGrantTickets(), // 施策3 の TicketPricingService
        contactUrl: $contactUrl,
        contactIsExternal: $contact->kind() === ContactDestinationKind::External,
        isAuthenticated: $request->user() !== null,
    );

    return Inertia::render('Welcome', [
        'appName' => config('app.name'),
        'page' => $dto->toArray(),
    ]);
}
```

### Welcome.svelte（構成 — aigenba Guest/Landing.svelte のセクション骨格を AI-CUE 文言で移植）

Props: `{ appName: string; page: LandingPageProps }`。`GuestLayout`（既存）+ `Button` atom + Lucide のみ。セクション:

1. **Hero**（`bg-neutral`・2 カラム grid）: バッジ「AI がカットを設計するマニュアル動画」/ H1「動画マニュアルを、<br>手順書から。」/ リード文（SOP を渡せば AI が撮るべきカットを設計。スマホのナビに従って撮るだけで、編集ゼロで字幕付きマニュアル動画が完成）/ CTA: 未認証 =「無料で始める」(`/register`) +「仕組みを見る」(`#how`)、認証済 =「ダッシュボードへ」(`/dashboard`)。右カラムは**撮影ナビ画面の静的モック**（カット一覧 + 「カット 3/8: バルブを閉める」ガイド + 録画ボタン風の装飾。DS token のみ・実データなし）。
2. **課題（3 つの壁）**: 台本作成（何をどう撮るか決められない）/ 撮影判断（現場で迷う・撮り直しが多い）/ 編集（切り貼り・字幕付けに時間がかかる）— North Star の 3 ハードルそのまま。Lucide: `FileText` / `Video` / `Scissors` 等。
3. **3 ステップ（`id="how"`）**: ① SOP アップロード（PDF / Excel）→ ② AI がカット設計（動画シナリオ = 撮影指示）→ ③ スマホ（PWA）でナビ撮影 → ④ 自動合成（字幕付き完成動画）。aigenba の「一行フロー + カード」レイアウト踏襲。
4. **素材**: 「手元の手順書から、そのまま作れる。」（作業手順書.pdf / 作業標準.xlsx チップ → 矢印 → シナリオカードのモック）。
5. **成果（3 者）**: 撮る人 = ナビに従うだけ（専門知識ゼロ）/ 教える人 = 品質が撮影者スキルに依存しない / 管理者 = 標準化された動画マニュアル資産。
6. **組織で安全に**: 組織分離 / RBAC / PII 暗号化（既存基盤の事実のみ。Lucide: `Building2` / `KeyRound` / `Lock`）。
7. **料金 CTA**（`bg-neutral`）: 「無料で始められます。」+ 注記「Free プランは月 {ticket} 枚のチケット付き。新規登録でさらに {page.signupGrantTickets} 枚（AI 解析 1 枚・動画レンダ 3 枚を消費）」→ ボタン「無料で始める」(`/register`) + リンク「料金プランを見る」(`/pricing`)。※月次付与枚数は props で持たない（プラン詳細は pricing の責務）ため、この文言は「Free プランで今すぐ試せます + 新規登録でチケット {n} 枚」に留める。
8. **問い合わせ**: `page.contactUrl`（`contactIsExternal` なら `target="_blank" rel="noopener noreferrer"`）。

`<svelte:head>` は付けない（title/description はサーバ SEO が正本 = 既存 SeoComposer/SeoRenderer 方式。aigenba の svelte:head 方式は移植しない）。

### PHPStan適合チェック

- [x] `__invoke(Request $request): InertiaResponse` 型明示
- [x] DTO 経由（`page` prop は `LandingPageShape`）
- [x] `config()` 戻りは TicketPricingService 内で Assert 済み int のみ受ける

### テスト計画

- [ ] Feature 新規 `tests/Feature/Marketing/LandingPageTest.php`: guest GET `/` 200 + `AssertableInertia` で component=Welcome / `page.signupGrantTickets`=10 / `page.isAuthenticated`=false、認証済で true、`contactUrl` 既定 `/contact?source=landing`
- [ ] 既存 `SeoHeadCompositionTest` green 維持（offers 追加で期待変更が要る場合は更新）
- [ ] Vitest `Welcome.test.ts` 書き換え: hero 見出し・「無料で始める」リンク・3 ステップ見出しの描画、`disabled` 属性を持つ button が存在しないこと

### リスク

- LP は静的コンテンツ主体で後退リスク小。SEO title の正本がサーバ側であることを崩さない（svelte:head を持ち込まない）。

---

## 施策3: 料金表実装

### 変更箇所

- 新規: `app/Services/Marketing/PricingService.php`
- 新規: `app/Services/Billing/TicketPricingService.php`
- 新規: `app/Http/Controllers/Marketing/PricingController.php`
- 変更: `routes/web.php`（closure → controller。route 名 `pricing` 維持）
- 変更: `database/seeders/DatabaseSeeder.php`（`TicketVolumePriceSeeder::class` を `PlanSeeder::class` の直後に追加）
- 変更: `resources/js/pages/Pricing.svelte`（全面書き換え）
- 新規: `resources/js/components/molecules/PricingPlanCard.svelte` + `PricingPlanCard.types.ts`（aigenba 移植: primitive props + footerCta snippet。`disabled` なし）

### 波及変更

- TypeScript 型定義: `types/marketing.ts`（施策1）
- テストファイル: `tests/js/pages/Pricing.test.ts`（新規）、`tests/Feature/Marketing/PricingPageTest.php`（新規）。**seeder 登録の波及**: テストは `$seed=true` で全 seeder が走るため、`TicketVolumePriceSeeder` 追加により既存テストの前提（volume price 0 行）が変わる — `TicketVolumeTierTest` は自前 seed のため要確認・調整（現行 seed 行と衝突しないか）。
- SEO: `PricingController` が SeoManager へ full メタを供給（既存 minimal 分類からの移行。`config/seo.php` の route 分類設定があれば追随）

### 変更後コード（要点）

```php
// app/Services/Marketing/PricingService.php
final class PricingService
{
    /** @var list<PricingPlanDto>|null リクエスト内メモ化 (aigenba 同様) */
    private ?array $memoizedPlans = null;

    /**
     * 公開プラン一覧 (sort_order 昇順)。価格は plan_prices current (kind=base)、
     * 能力値は config/quota.php の limits ("コードにプラン名分岐を書かない" 規約どおり
     * key の値だけを読む。limits に無い key は無制限 = null)。
     *
     * @return list<PricingPlanDto>
     */
    public function listPublicPlans(): array
    {
        if ($this->memoizedPlans !== null) {
            return $this->memoizedPlans;
        }

        $quotaPlans = config('quota.plans');
        Assert::isArray($quotaPlans);

        return $this->memoizedPlans = Plan::query()->orderBy('sort_order')->get()
            ->map(function (Plan $plan) use ($quotaPlans): PricingPlanDto {
                $limits = $quotaPlans[$plan->code] ?? [];
                Assert::isArray($limits);
                $price = $plan->currentPrice(PlanPriceKind::Base);

                return new PricingPlanDto(
                    code: $plan->code,
                    name: $plan->name,
                    baseAmountJpy: $price?->amount,
                    monthlyTicketGrant: $plan->monthly_ticket_grant,
                    maxProjects: self::intOrNull($limits, 'max_projects'),
                    maxMembers: self::intOrNull($limits, 'max_members'),
                    maxStorageGb: self::storageGb($limits),
                );
            })->values()->all();
    }
    // intOrNull / storageGb (bytes → GiB 整数換算) は private static、mixed を is_int で絞り込む
}
```

```php
// app/Services/Billing/TicketPricingService.php — 表示専用の読み取り口 (消費・購入経路とは独立)
final class TicketPricingService
{
    /**
     * current 全段を min_count 昇順で返す (min_count=1 の行が spot を兼ねる)。
     * 各段は floor (config billing.ticket_unit_price_floor) を Assert (設定異常 fail-fast)。
     *
     * @return list<PurchaseTierDto>
     */
    public function volumeTiersForDisplay(): array { /* TicketVolumePrice current 昇順 → floor Assert → PurchaseTierDto */ }

    /** spot 単価 (min_count=1 の current 行)。無ければ TicketVolumeTierUnavailableException (fail-closed) */
    public function spotUnitAmount(): int { /* currentTierFor(1)->unitAmount を利用 (二重実装しない) */ }

    /** config billing.signup_grant_tickets (Assert integer/positive)。TicketLedgerService::grantSignupGrant と同じ config key を読む表示用口 */
    public function signupGrantTickets(): int { /* ... */ }

    /** config billing.signup_grant_expiry_days (Assert) */
    public function signupGrantExpiryDays(): int { /* ... */ }
}
```

```php
// app/Http/Controllers/Marketing/PricingController.php
class PricingController extends Controller
{
    public function __construct(
        private readonly PricingService $pricing,
        private readonly TicketPricingService $ticketPricing,
        private readonly ContactUrl $contact,
        private readonly SeoManager $seo,
        private readonly SeoUrl $url,
    ) {}

    public function __invoke(Request $request): InertiaResponse
    {
        $contactUrl = $this->contact->resolve();
        if ($this->contact->kind() === ContactDestinationKind::Internal) {
            $contactUrl .= '?source='.InquirySource::Pricing->value;
        }

        $analysisCost = config('manual.analysis_ticket_cost');
        $renderCost = config('manual.render_ticket_cost');
        Assert::integer($analysisCost);
        Assert::integer($renderCost);

        $dto = new PricingPageDto(
            plans: $this->pricing->listPublicPlans(),
            ticketTiers: $this->ticketPricing->volumeTiersForDisplay(),
            spotUnitAmountJpy: $this->ticketPricing->spotUnitAmount(),
            signupGrantTickets: $this->ticketPricing->signupGrantTickets(),
            signupGrantExpiryDays: $this->ticketPricing->signupGrantExpiryDays(),
            analysisTicketCost: $analysisCost,
            renderTicketCost: $renderCost,
            isAuthenticated: $request->user() !== null,
            contactUrl: $contactUrl,
            contactIsExternal: $this->contact->kind() === ContactDestinationKind::External,
        );

        // SEO full 供給 (HomeController と同じ SeoMeta 方式。lowPriceJpy=0 = Free)
        $this->seo->set(
            SeoMeta::default($this->url, '/pricing')
                ->withTitle('料金プラン') // SeoTitle 合成は既存規約に従う
                ->withJsonLd([JsonLd::softwareApplication(Config::string('seo.site_name'), $this->url->to('/pricing'), '...', 0)]),
        );

        return Inertia::render('Pricing', ['page' => $dto->toArray()]);
    }
}
```

```php
// routes/web.php (置換)
Route::get('/pricing', PricingController::class)->name('pricing');
```

### Pricing.svelte（構成 — aigenba Guest/Pricing.svelte の骨格を AI-CUE 文言・2 プランで移植）

Props: `{ page: PricingPageProps }`（+ 既存 shared props から appName を GuestLayout へ）。

1. 見出し + 料金構造の注記ボックス（Info アイコン）: 「表示は各プランの基本料金（月額）。AI 解析・動画レンダには共通のチケットを使います（AI 解析 {analysisTicketCost} 枚・動画レンダ {renderTicketCost} 枚）」。
2. プランカードグリッド（`sm:grid-cols-2`）: `PricingPlanCard`（name / priceAmount（0 は「無料」表示・aigenba 準拠）/ priceCaption「基本料金」/ features）。features は `月 {monthlyTicketGrant} 枚のチケット付与` / `プロジェクト {maxProjects ?? '無制限'}` / `メンバー {maxMembers ?? '無制限'} 名` / `ストレージ {maxStorageGb} GB`。CTA: 未認証 = 「このプランで始める」(`/register`)、認証済 = 「プランを変更」(`/billing`)。
3. 大規模利用バナー: 「より大きな組織・拠点展開のご相談」→ 問い合わせ CTA（`contactUrl` / external 分岐は aigenba と同じ）。
4. **チケット料金表**（`id="ticket-pricing"`）: aigenba の帯変換ロジック（`{minCount}〜{next.minCount - 1} 枚` / 最終段 `{minCount} 枚以上`）そのまま + signup grant 注記「新規契約でチケット {signupGrantTickets} 枚が無料（{signupGrantExpiryDays} 日間有効）」。
5. FAQ アコーディオン（`aria-expanded` 付き button。aigenba 実装踏襲）: 「無料で試せますか？」「チケットは何に使いますか？（AI 解析 1 枚 / 動画レンダ 3 枚、プレビューは無料）」「追加チケットの購入方法（1 枚 ¥{spotUnitAmountJpy}〜・まとめ買いで単価逓減・組織のオーナー/管理者が購入）」「解約・プラン変更」。

### PHPStan適合チェック

- [x] Service は DTO の list を返す（配列 shape は docblock 固定）
- [x] `config()` の mixed は Assert で絞る（quota.plans / manual.*_ticket_cost）
- [x] `$price?->amount` の null 伝播を shape の `int|null` で受ける

### テスト計画

- [ ] Feature 新規 `tests/Feature/Marketing/PricingPageTest.php`:
  - guest GET `/pricing` 200 + component=Pricing + `page.plans` に free/standard（code/name/baseAmountJpy: free=null, standard=1980※施策7 後 4980 に更新）
  - `page.ticketTiers` が 7 段・昇順（1/100 … 500/50）、`spotUnitAmountJpy`=100
  - `signupGrantTickets`=10 / `signupGrantExpiryDays`=30 / `analysisTicketCost`=1 / `renderTicketCost`=3
  - quota limits 反映（free: maxProjects=1 / maxMembers=3 / maxStorageGb=1）
- [ ] 既存テスト green: `TicketVolumeTierTest`（DatabaseSeeder 登録後の seed 行との整合を確認・必要なら自前 seed を seeder 呼び出しへ寄せる）
- [ ] Vitest `Pricing.test.ts`（新規）: プランカード 2 枚・チケット帯表示（「20〜49 枚 ¥80」）・FAQ 開閉・`disabled` 不在

### リスク

- `DatabaseSeeder` への seeder 追加は全テストの seed 前提を変える。`ticket_volume_prices` を暗黙 0 行前提にしたテストが落ちる可能性 → 実装時に `composer test` 全走で検出・調整（テスト削除はしない）。
- `/pricing` の応答形状変更（props なし → page）: 旧 Pricing.svelte 参照の Vitest があれば書き換え（現状テストなし。新規作成）。

---

## 施策4: チケット購入バックエンド（冪等 Checkout）

### 変更箇所

- 新規 migration: `create_ticket_checkout_sessions_table`
- 新規: `app/Models/Billing/TicketCheckoutSession.php` / `app/Enums/Billing/TicketCheckoutSessionStatus.php`
- 新規: `app/Services/Billing/TicketCheckoutGateway.php`（interface）/ `app/Services/Billing/CashierTicketCheckoutGateway.php`
- 新規: `app/Services/Billing/TicketCheckoutService.php`
- 新規: `app/Http/Controllers/Billing/TicketPurchaseController.php` / `app/Http/Requests/Billing/TicketCheckoutRequest.php`
- 新規: `app/DataTransferObjects/Billing/PurchaseTicketsPageDto.php` / `app/DataTransferObjects/Billing/TicketCheckoutRedirect.php` / `app/DataTransferObjects/Billing/CreatedCheckoutSession.php`
- 変更: `routes/web.php` / `app/Support/Security/MassAssignmentProtectedKeys.php`（`initiated_by_user_id` 追加）
- 新規: `database/factories/Billing/TicketCheckoutSessionFactory.php`
- 変更: `app/Providers/AppServiceProvider.php`（interface → Cashier 実装の bind）
- ドキュメント: `docs/architecture.md` / `docs/factories.md` に TicketCheckoutSession を追記

### 波及変更

- TypeScript 型定義: `types/billing.ts`（施策6）
- API Resource/DTO: なし（web POST は redirect 応答のみ）
- テストファイル: `tests/Feature/Billing/TicketCheckoutTest.php`（新規）+ `MassAssignmentSafetyTest`（保護キー追加の自動検証対象）

### migration（新規テーブル）

```php
Schema::create('ticket_checkout_sessions', function (Blueprint $table): void {
    $table->id();
    $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
    // 監査行のため user 削除でも行は残す (null 化)
    $table->foreignId('initiated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
    $table->unsignedInteger('ticket_count');
    $table->unsignedInteger('unit_amount');   // 作成時単価 pin (webhook 金額照合の出典)
    $table->string('currency', 8);            // pin (jpy)
    $table->string('stripe_session_id')->unique();
    $table->string('attempt_token', 26);
    $table->string('checkout_url', 2048);
    $table->string('status');                  // pending / completed / expired (アプリ層 enum cast)
    $table->timestamp('expires_at');           // Stripe session expires_at の pin (live 判定の決定基準)
    $table->timestamp('completed_at')->nullable();
    $table->timestamps();
    $table->unique(['organization_id', 'attempt_token']);
    $table->index(['organization_id', 'initiated_by_user_id', 'status', 'expires_at']);
});
```

### Model / Enum

```php
enum TicketCheckoutSessionStatus: string
{
    case Pending = 'pending';
    case Completed = 'completed';
    case Expired = 'expired';
}
```

`TicketCheckoutSession`: `$fillable = []`（全列を Service が明示代入。状態キー・FK は relation / 直接代入のみ）。casts: `status` enum / `expires_at`・`completed_at` immutable_datetime / int 列 integer。`organization()` BelongsTo / `initiatedBy()` BelongsTo(User)。helper: `isLivePending(CarbonImmutable $now): bool`（`status===Pending && expires_at > $now`）。

### Gateway

```php
interface TicketCheckoutGateway
{
    /**
     * one-time Checkout Session を作る (mode=payment / card のみ / promo・tax なし)。
     * $idempotencyKey により同一 attempt の再送は Stripe 側で同一 session に収束する。
     */
    public function createTicketCheckout(
        Organization $organization,
        string $stripePriceId,
        int $quantity,
        string $successUrl,
        string $cancelUrl,
        string $idempotencyKey,
        array $metadata, // array<string, string>
    ): CreatedCheckoutSession; // {sessionId: string, url: string, expiresAt: CarbonImmutable}

    /** Stripe 側 session を expire する。返り値は expire 後の session status ('expired'|'complete'|...) */
    public function expireCheckoutSession(string $sessionId): string;
}
```

`CashierTicketCheckoutGateway`: `$organization->createOrGetStripeCustomer()` → `$organization->stripe()->checkout->sessions->create([...], ['idempotency_key' => $idempotencyKey])`。payload: `mode=payment`, `customer`, `line_items=[[price, quantity]]`, `payment_method_types=['card']`, `success_url`, `cancel_url`, `metadata`。`allow_promotion_codes` / `automatic_tax` は指定しない（無効のまま）。url / expires_at の null は Assert で弾く（hosted mode では常に返る）。`AppServiceProvider` で bind。テストは fake を bind（決定的な `cs_test_{token}` / url / expires_at=+24h を返し、呼び出しを記録）。

### TicketCheckoutService（冪等マシンの中核）

```php
class TicketCheckoutService
{
    private const LOCK_SECONDS = 10;
    private const LOCK_WAIT_SECONDS = 5;

    public function __construct(private readonly TicketCheckoutGateway $gateway) {}

    /**
     * 冪等 checkout 開始。戻り値 TicketCheckoutRedirect {url: string|null, alreadyCompleted: bool}
     *  - url あり → Inertia::location で遷移 (新規 or replay)
     *  - url null + alreadyCompleted → 「受付済み」着地
     * 業務エラーは CheckoutInProgressException / StaleCheckoutAttemptException (新規 Exception 2 種)
     * → controller が back()->with('error') に変換。
     */
    public function startCheckout(Organization $org, User $user, int $count, string $attemptToken): TicketCheckoutRedirect
    {
        $tier = TicketVolumePrice::currentTierFor($count); // 既存: fail-closed / floor / production 未 sync 拒否

        try {
            return Cache::lock("billing:ticket-checkout:{$org->id}", self::LOCK_SECONDS)
                ->block(self::LOCK_WAIT_SECONDS, fn () => $this->startCheckoutLocked($org, $user, $count, $tier, $attemptToken));
        } catch (LockTimeoutException $e) {
            // fail-closed: ロックなし実行へフォールバックしない
            throw new CheckoutInProgressException('直前の購入手続きが進行中です。数秒おいて再度お試しください。', previous: $e);
        }
    }

    private function startCheckoutLocked(...): TicketCheckoutRedirect
    {
        $now = CarbonImmutable::now();

        // (0) 期限切れ pending の回収: dedup の前に expired へ遷移 (R2 Critical: 死 URL を replay しない)
        TicketCheckoutSession::query()
            ->where('organization_id', $org->id)
            ->where('status', TicketCheckoutSessionStatus::Pending)
            ->where('expires_at', '<=', $now)
            ->update(['status' => TicketCheckoutSessionStatus::Expired->value]);

        // (1) 同一 attempt_token: 同 count live pending → replay / completed → 受付済み / それ以外 → stale
        $sameAttempt = TicketCheckoutSession::query()
            ->where('organization_id', $org->id)
            ->where('attempt_token', $attemptToken)
            ->first();
        if ($sameAttempt !== null) {
            if ($sameAttempt->status === TicketCheckoutSessionStatus::Completed) {
                return new TicketCheckoutRedirect(url: null, alreadyCompleted: true);
            }
            if ($sameAttempt->isLivePending($now) && $sameAttempt->ticket_count === $count) {
                return new TicketCheckoutRedirect(url: $sameAttempt->checkout_url, alreadyCompleted: false);
            }
            throw new StaleCheckoutAttemptException('購入手続きの有効期限が切れました。画面を再読み込みして再度お試しください。');
        }

        // (2) live pending dedup (org, user): 同 count → replay / 別 count → Stripe expire 成功時のみ expired 化して続行
        $livePending = TicketCheckoutSession::query()
            ->where('organization_id', $org->id)
            ->where('initiated_by_user_id', $user->id)
            ->where('status', TicketCheckoutSessionStatus::Pending)
            ->where('expires_at', '>', $now)
            ->latest('id')->first();
        if ($livePending !== null) {
            if ($livePending->ticket_count === $count) {
                return new TicketCheckoutRedirect(url: $livePending->checkout_url, alreadyCompleted: false);
            }
            $status = $this->gateway->expireCheckoutSession($livePending->stripe_session_id); // 失敗は throw → CheckoutInProgressException に包む
            if ($status === 'complete') {
                throw new CheckoutInProgressException('直前の購入が処理中です。数秒おいて再度お試しください。');
            }
            $livePending->status = TicketCheckoutSessionStatus::Expired;
            $livePending->save();
        }

        // (3) Stripe 作成 (idempotency key = purchase:{attemptToken}) → DB 記録。
        $created = $this->gateway->createTicketCheckout(
            $org, $tier->stripePriceId, $count,
            route('billing.tickets.show', ['purchased' => 1]),
            route('billing.tickets.show'),
            'purchase:'.$attemptToken,
            ['purpose' => 'ticket_purchase', 'organization_id' => (string) $org->id, 'count' => (string) $count],
        );

        try {
            $session = new TicketCheckoutSession;
            $session->organization()->associate($org);
            $session->initiatedBy()->associate($user);
            $session->ticket_count = $count;
            $session->unit_amount = $tier->unitAmount;
            $session->currency = 'jpy'; // tier snapshot の currency 列と同値 (実装では TicketVolumePrice 行から取る)
            $session->stripe_session_id = $created->sessionId;
            $session->attempt_token = $attemptToken;
            $session->checkout_url = $created->url;
            $session->status = TicketCheckoutSessionStatus::Pending;
            $session->expires_at = $created->expiresAt;
            $session->save();
        } catch (UniqueConstraintViolationException) {
            // 並行 race / Stripe idempotency replay: 既存行 re-read で replay / stale に収束 (500 にしない)
            $existing = TicketCheckoutSession::query()
                ->where('organization_id', $org->id)
                ->where('attempt_token', $attemptToken)
                ->orWhere('stripe_session_id', $created->sessionId) // 実装では where 群を正しく括弧化
                ->latest('id')->first();
            if ($existing?->isLivePending(CarbonImmutable::now()) === true && $existing->ticket_count === $count) {
                return new TicketCheckoutRedirect(url: $existing->checkout_url, alreadyCompleted: false);
            }
            throw new StaleCheckoutAttemptException('購入手続きをやり直してください。画面を再読み込みして再度お試しください。');
        }

        return new TicketCheckoutRedirect(url: $created->url, alreadyCompleted: false);
    }
}
```

補足:
- `currency` は `TicketVolumePrice` 行の `currency` を pin する（`currentTierFor` の返す `TicketVolumeTier` DTO には currency が無いため、**`TicketVolumeTier` に `currency` を追加**するか tier 行を再取得する。→ 採用: `TicketVolumeTier` readonly DTO に `public string $currency` を追加し `currentTierFor` で詰める。**波及**: `TicketVolumeTier` を new している既存箇所（`TicketVolumePrice::currentTierFor` のみ）と `TicketVolumeTierTest` の期待）。
- Laravel 12 は unique 違反を `UniqueConstraintViolationException`（`QueryException` サブクラス）で投げるため driver 文字列判定は不要（aigenba の `isUniqueViolation` は移植しない）。
- 新規 Exception: `app/Exceptions/Billing/CheckoutInProgressException.php` / `StaleCheckoutAttemptException.php`（RuntimeException 継承。web 経路のみ・controller で catch するため bootstrap ハンドラ追加は不要）。

### Controller / FormRequest / Routes

```php
// app/Http/Requests/Billing/TicketCheckoutRequest.php
class TicketCheckoutRequest extends FormRequest
{
    use ProhibitsProtectedKeys; // organization_id 等の混入は 422

    public function rules(): array
    {
        return [
            'count' => ['required', 'integer', 'min:'.TicketVolumePrice::PURCHASE_MIN_COUNT, 'max:'.TicketVolumePrice::PURCHASE_MAX_COUNT],
            'attempt_token' => ['required', 'ulid'],
        ];
    }
}
```

```php
// app/Http/Controllers/Billing/TicketPurchaseController.php
class TicketPurchaseController extends Controller
{
    use ResolvesCurrentOrganization;

    /** 購入画面 (閲覧は組織メンバー全員 / attempt_token は render ごとに ULID 発行) */
    public function show(Request $request, TicketPricingService $pricing, TicketLedgerService $tickets): Response
    {
        $organization = $this->resolveCurrentOrganization($request);
        Gate::authorize('view', $organization);

        $user = $request->user();
        Assert::isInstanceOf($user, User::class);

        $dto = new PurchaseTicketsPageDto(
            tiers: $pricing->volumeTiersForDisplay(),
            minCount: TicketVolumePrice::PURCHASE_MIN_COUNT,
            maxCount: TicketVolumePrice::PURCHASE_MAX_COUNT,
            defaultCount: 10,
            balance: $tickets->balance($organization),
            canManage: $user->can('manageBilling', $organization),
            attemptToken: (string) Str::ulid(),
            purchased: $request->boolean('purchased'), // Stripe success_url からの帰還 (表示専用)
        );

        return Inertia::render('Billing/PurchaseTickets', ['page' => $dto->toArray()]);
    }

    /** Checkout 開始 (manageBilling のみ) */
    public function checkout(TicketCheckoutRequest $request, TicketCheckoutService $service): SymfonyResponse|RedirectResponse
    {
        $organization = $this->resolveCurrentOrganization($request);
        Gate::authorize('manageBilling', $organization);

        $user = $request->user();
        Assert::isInstanceOf($user, User::class);

        $count = $request->validated('count');
        $attemptToken = $request->validated('attempt_token');
        Assert::integerish($count);
        Assert::string($attemptToken);

        try {
            $redirect = $service->startCheckout($organization, $user, (int) $count, $attemptToken);
        } catch (CheckoutInProgressException|StaleCheckoutAttemptException|TicketVolumeTierUnavailableException $e) {
            return back()->with('error', $e->getMessage());
        }

        if ($redirect->url === null) {
            return redirect()->route('billing.tickets.show')->with('info', 'この購入は既に受付済みです。残高への反映をお待ちください。');
        }

        return Inertia::location($redirect->url); // 外部 Stripe への full page redirect
    }
}
```

```php
// routes/web.php — 既存 billing.* の直後 (auth+verified 内・課金ゲート group 外 = 未契約でも購入可)
Route::get('/purchase-tickets', [TicketPurchaseController::class, 'show'])
    ->name('billing.tickets.show');
Route::post('/purchase-tickets/checkout', [TicketPurchaseController::class, 'checkout'])
    ->name('billing.tickets.checkout');
```

`PurchaseTicketsPageDto` shape:

```php
/**
 * @phpstan-import-type PurchaseTierShape from PurchaseTierDto
 * @phpstan-type PurchaseTicketsPageShape array{
 *   tiers: list<PurchaseTierShape>, minCount: int, maxCount: int, defaultCount: int,
 *   balance: int, canManage: bool, attemptToken: string, purchased: bool
 * }
 */
```

### PHPStan適合チェック

- [x] 全 public メソッドに戻り値型 + docblock（list<> / Shape）
- [x] `validated()` の mixed を Assert で絞ってから使用
- [x] enum cast + immutable_datetime cast で日時型を CarbonImmutable に固定
- [x] Gateway 戻り値は readonly DTO（連想配列を返さない）

### テスト計画（`tests/Feature/Billing/TicketCheckoutTest.php` 新規 + fake gateway）

- [ ] guest → login redirect / 非メンバー current org なし → 404
- [ ] member（manageBilling なし）: GET 200 + `canManage=false` / POST 403
- [ ] admin POST: fake gateway 呼び出し 1 回・`Inertia::location`（409 応答の `X-Inertia-Location`）で URL 遷移・DB 行 pending（count/unit_amount pin: 30 枚 → ¥80）
- [ ] **同一 attempt_token 再送**: gateway 追加呼び出しなし・同一 URL replay
- [ ] **別 token・同 count（別タブ想定）**: 新規作成せず既存 URL replay（gateway create 1 回のまま）
- [ ] **別 token・別 count**: 既存 pending expire（gateway expire 呼び出し）→ 新 session 作成。expire が 'complete' を返すケース → error flash + 新規作成なし
- [ ] **期限切れ pending（expires_at 過去）**: replay されず expired 化 + 新 session 作成（R2 Critical の回帰テスト）
- [ ] completed 済み attempt_token の再送 → 「受付済み」着地（gateway 呼び出しなし）
- [ ] count 境界: 0 / 1001 / 非整数 → validation error（back + errors）。attempt_token 欠落 / 非 ULID → 同様
- [ ] 保護キー: payload に `organization_id` 混入 → 422（ProhibitsProtectedKeys）
- [ ] tier 空（ticket_volume_prices を空にする）→ fail-closed の error flash（spot へ落ちない）
- [ ] **未契約 org（subscription なし）でも GET/POST 到達可能**（課金ゲート対象外の回帰）
- [ ] `MassAssignmentSafetyTest`（自動）: `initiated_by_user_id` が fillable に含まれない

### リスク

- Cashier のバージョンにより `stripe()` クライアント API 差異 → gateway に閉じているため影響局所。実装時に `laravel/cashier` の現行メジャーで確認。
- Cache lock は既定 cache driver に依存（テスト環境 array driver でも `block` は動作）。

---

## 施策5: webhook 冪等付与（checkout.session.completed）

### 変更箇所

- `app/Services/Billing/StripeWebhookProcessor.php`: `CheckoutSessionCompleted => null` の arm を `$this->grantPurchasedTickets($payload)` に置換 + private メソッド追加 + terminal-ack の運用アラート強化

### 波及変更

- TypeScript 型定義: なし
- API Resource/DTO: なし
- テストファイル: `tests/Feature/Billing/TicketPurchaseWebhookTest.php`（新規）。既存 `WebhookIdempotencyTest` / `WebhookEventSubscriptionInvariantTest` は green 維持（CheckoutSessionCompleted は既に purchese 対象の購読集合に含まれる）

### 変更後コード

```php
private function grantPurchasedTickets(array $payload): void
{
    // (1) purpose ガード: ticket_purchase 以外 (サブスク checkout / 他 purpose / mode≠payment) は受理のみ。
    //     無関係 event を failed にしない。
    if ($this->stringAt($payload, 'data.object.metadata.purpose') !== 'ticket_purchase') {
        return;
    }
    if ($this->stringAt($payload, 'data.object.mode') !== 'payment') {
        return;
    }

    $sessionId = $this->stringAt($payload, 'data.object.id');
    if ($sessionId === null) {
        throw new RuntimeException('checkout.session.completed: session id 欠落 (ticket_purchase)');
    }

    // (2) 真実源は自 DB 行。行不在は retryable failure (crash 先着 webhook は同一 attempt の
    //     再試行で DB 行が記録された後、Stripe の event 再送で本経路に収束する)。
    $session = TicketCheckoutSession::query()->where('stripe_session_id', $sessionId)->first();
    if ($session === null) {
        throw new RuntimeException("ticket purchase webhook: 未追跡 session {$sessionId} (DB 行なし、再送待ち)");
    }

    // (3) tenant キー不信: payload の customer / metadata.organization_id は照合のみ。不一致は throw (fail-closed)。
    $organization = $session->organization;
    Assert::isInstanceOf($organization, Organization::class);
    $customerId = $this->stringAt($payload, 'data.object.customer');
    if ($customerId === null || $organization->stripe_id !== $customerId) {
        throw new RuntimeException("ticket purchase webhook: customer 照合不一致 (session {$sessionId})");
    }
    $metaOrgId = $this->stringAt($payload, 'data.object.metadata.organization_id');
    if ($metaOrgId !== (string) $organization->id) {
        throw new RuntimeException("ticket purchase webhook: metadata organization_id 照合不一致 (session {$sessionId})");
    }

    // (4) payment_status=paid 必須 (card 固定下の防御線。未決済 completed を付与しない)
    if ($this->stringAt($payload, 'data.object.payment_status') !== 'paid') {
        throw new RuntimeException("ticket purchase webhook: payment_status が paid でない (session {$sessionId})");
    }

    // (5) 金額照合: amount_subtotal === count × pin 単価、currency === pin (欠落・不一致は throw)
    $amountSubtotal = data_get($payload, 'data.object.amount_subtotal');
    $currency = $this->stringAt($payload, 'data.object.currency');
    if (! is_int($amountSubtotal)
        || $amountSubtotal !== $session->ticket_count * $session->unit_amount
        || $currency !== $session->currency) {
        throw new RuntimeException("ticket purchase webhook: 金額/通貨照合不一致 (session {$sessionId})");
    }

    // (6) 冪等付与 (idempotency_key purchase:{sessionId} UNIQUE) + 行 completed 化 (同一 TX)
    $paymentIntentId = $this->stringAt($payload, 'data.object.payment_intent');
    DB::transaction(function () use ($organization, $session, $amountSubtotal, $paymentIntentId): void {
        $this->tickets->grantPurchased(
            $organization,
            $session->ticket_count,
            $session->stripe_session_id,
            $paymentIntentId,
            $amountSubtotal, // 返金按分の分母 (clawback が使う)
        );
        if ($session->status !== TicketCheckoutSessionStatus::Completed) {
            $session->status = TicketCheckoutSessionStatus::Completed;
            $session->completed_at = CarbonImmutable::now();
            $session->save();
        }
    });
}
```

terminal-ack の運用アラート（概念レビュー R4 Suggestion 対応）— `claim()` の terminal 分岐に追加:

```php
if ($existing->attempts >= self::MAX_PROCESSING_ATTEMPTS) {
    Log::warning(/* 既存の構造化ログ */);
    // 付与系イベントの取りこぼしは「決済済み・未付与」を残すため運用アラート経路 (report) に載せる
    if (in_array($type, [HandledStripeWebhookEvent::CheckoutSessionCompleted->value, HandledStripeWebhookEvent::InvoicePaid->value], true)) {
        report(new RuntimeException("stripe webhook terminal failure (grant イベント): {$eventId} ({$type})"));
    }
    return null;
}
```

補足: 例外 throw は既存 `handle()` の catch で `status=failed + failure_reason + report + 再 throw`（= Stripe が再送）に接続される。**新規コード追加なしで retryable failure が成立**する（概念レビュー R3 Critical の対応点）。

### PHPStan適合チェック

- [x] payload は `array<mixed>` + `stringAt()` / `is_int()` で絞り込み（mixed を漏らさない）
- [x] `$session->organization` は `Assert::isInstanceOf`
- [x] enum 比較は `===`（string 比較しない）

### テスト計画（`tests/Feature/Billing/TicketPurchaseWebhookTest.php` 新規。`WebhookReceived` 直発火の既存流儀）

- [ ] 正常系: pending 行 + paid payload → 残高 +count / 行 completed / `ticket_ledger_entries` に purchase 行（payment_intent_id / purchase_amount 記録）
- [ ] **同一 event_id 再送** → 二重付与なし（claim skip）
- [ ] **event_id 違いの同一 session 再送** → 二重付与なし（台帳 idempotency_key UNIQUE）
- [ ] **DB 行なし（crash 先着）** → 例外 = event failed + 付与なし → 行 insert 後に同 event 再送 → **一度だけ付与**（R3 Critical の回帰シナリオそのまま）
- [ ] payment_status≠paid → failed + 付与なし
- [ ] amount_subtotal 不一致 / currency 不一致 / customer 不一致 / metadata org 不一致 → failed + 付与なし
- [ ] purpose なし・別 purpose・mode=subscription → processed（failed にならない）+ 付与なし
- [ ] terminal-ack: attempts 上限到達の completed イベント → report される（`Exceptions::fake()` 等で検証）
- [ ] 付与後の `charge.refunded`（payment_intent 一致）→ 既存 clawback で残高逆仕訳（既存 `TicketRefundClawbackTest` の流儀で 1 ケース、購入経路との接続を固定）

### リスク

- `invoice.paid` にも terminal report を足すため、既存の恒久失敗イベント（あれば）で report が増える — 意図した可観測化。
- webhook 実 payload の形状差（新旧 API）は `stringAt` の fallback 不要（checkout.session は安定形状）。テスト payload は Stripe fixture 形状に合わせる。

---

## 施策6: 購入フロント + 残高不足導線

### 変更箇所

- 新規: `resources/js/pages/Billing/PurchaseTickets.svelte`
- 新規: `resources/js/types/billing.ts`（`PurchaseTicketsPageProps` = PHP shape と exact 対）
- 変更: `resources/js/components/features/manual/AnalysisPanel.svelte` / `RenderPanel.svelte`（402 導線）
- 変更: `resources/js/pages/Billing/Index.svelte`（残高カードに「チケットを購入」リンク）
- 新規 Vitest: `tests/js/pages/PurchaseTickets.test.ts` / 変更: 既存 manual パネル系テスト

### 波及変更

- TypeScript 型定義: `types/billing.ts`（新規）・`types/marketing.ts`（施策1）
- テストファイル: 上記 Vitest + `tests/js/pages/Pricing.test.ts`（施策3）

### PurchaseTickets.svelte（設計）

```ts
interface Props { page: PurchaseTicketsPageProps }
// page: { tiers, minCount, maxCount, defaultCount, balance, canManage, attemptToken, purchased }
```

- `AppLayout` 配下（認証済みページ）。見出し「チケットを購入」+ 現在残高。
- `purchased=true` なら成功バナー「ご購入ありがとうございます。決済の確認後、残高に反映されます（通常数秒〜数分）」（webhook 反映待ちを明示。残高はリロードで更新）。
- 枚数入力: `FormField` + `Input type="number"`（`min`/`max` 属性は視覚ヒントとして付与するが、**送信ボタンは disabled にしない**。不正値はクライアントで押下時にエラー表示 + サーバ validation の二重防御）。
- 適用単価・総額の即時表示: `tiers` から `minCount <= count` の最大段を選び `単価 ¥N × count = 合計 ¥M`（`$derived`。aigenba T905 F-5-02 と同じロジック）。
- 傾斜表: Pricing と同じ帯表示（`X〜Y 枚 / ¥N`。表示コンポーネントは page 内 `{#each}` で完結。Pricing 側と molecule 共有はしない — 表示都合が異なるため過剰抽象化を避ける）。
- 購入ボタン: `router.post('/purchase-tickets/checkout', { count, attempt_token: page.attemptToken })`。`onStart/onFinish` の `loading` 表示（`Button loading` prop）。flash error（`page.props.flash.error` 既存流儀）と validation errors を `Alert` / `FormError` で表示。
- **role-aware**: `canManage=false` のとき、フォームの代わりに `Alert` で「チケットの購入は組織のオーナーまたは管理者が行えます。管理者に購入を依頼してください」を表示（残高・料金表は表示 = 透明性維持）。
- Checkout は `Inertia::location` によるサーバ主導遷移のため、フロントは特別な処理不要（Inertia が 409 + X-Inertia-Location を follow する）。

### 402 導線（AnalysisPanel / RenderPanel）

現行: `extractMessage(body)` の文字列を `errorMessage` に入れて表示。変更:

```ts
// 402 かつ code === 'insufficient_tickets' のとき購入リンクを併記するフラグを立てる
let showPurchaseLink = $state(false);
// requestAnalyze/requestRender の失敗分岐で:
showPurchaseLink = res.status === 402 && isInsufficientTickets(body); // body.code 厳格一致 (InsufficientTicketsBody)
```

表示部: `{#if showPurchaseLink}<TextLink href="/purchase-tickets">チケットを購入する</TextLink>{/if}` をエラーメッセージ直後に追加（`types/manual.ts` の `InsufficientTicketsBody` を再利用した type guard を `lib/` か各パネル内に実装。両パネルで共通なので `components/features/manual/insufficient-tickets.ts` の小ヘルパに置く）。

### Billing/Index.svelte

チケット残高ブロックに `TextLink href="/purchase-tickets"`「チケットを購入」を追加（canManageBilling に依らず表示 — 遷移先が role-aware）。

### PHPStan適合チェック

- 対象外（フロントのみ）。TS は `pnpm typecheck` green（exact interface / no any）。

### テスト計画

- [ ] Vitest `PurchaseTickets.test.ts`: 単価・総額の tier 計算（19→¥100 / 20→¥80 / 500→¥50）、canManage=false の案内表示、purchased=true バナー、`disabled` 属性のボタン不在
- [ ] Vitest 既存 manual パネルテストに 402 リンク表示ケース追加（fetch mock で 402 + code）
- [ ] `pnpm lint` / `pnpm typecheck` / `pnpm build` green（atomic-import-graph / ds-purity / lucide-scoped の architecture テスト含む）

### リスク

- `AnalysisPanel` / `RenderPanel` は fetch ベースの XHR。402 応答 body の code 判定は既存 `InsufficientTicketsResource` 契約（`{code, message}`）に厳格一致させ、他エラーで誤表示しない。

---

## 施策7: 価格改定（Standard ¥1,980 → ¥4,980。独立コミット）

### 変更箇所

- `database/seeders/PlanSeeder.php`: `PRICE_AMOUNTS = ['standard' => ['base' => 4980]]`
- `stripe/fixtures/plan_standard.json`: `unit_amount: 4980`（説明文の金額表記があれば同時更新）

### 波及変更

- テストファイル:
  - `tests/Feature/Billing/BillingPageTest.php` L29（`unitAmount` 期待 1980 → 4980）
  - `tests/Feature/Billing/VerifyStripePricesCommandTest.php`（fixture 期待値 1980 → 4980、コメント含む）
  - `tests/Feature/Billing/SyncStripePricesCommandTest.php`（helper 既定 1980 はテストローカル値のため機能上は不要だが、fixture との整合コメントがあれば追随）
  - 施策3 `PricingPageTest` の standard 期待値（4980 で作成 or 本施策で更新）
- `StripePriceCatalogFixtureInvariantTest`（lookup_key 集合のみ検証 = 金額非依存で green のはず。実行確認）

### 根拠・リスク

- 「価格値は aigenba のものをそのまんま移植（plan_prices 含む）」のユーザー指示。導線実装と独立のプロダクト判断のため**独立コミット**とし、revert 可能性を保つ。
- 本番 Stripe には `billing:sync-stripe-prices` 適用まで反映されない（fixture = desired state 宣言）。既契約への影響は Stripe 価格改定運用（新 Price 切替）の範囲で、本設計ではカタログ宣言の更新のみ。

### テスト計画

- [ ] 上記 3 テストの期待値更新後、`composer test` green
- [ ] seeder 再実行（updateOrCreate）で bootstrap 行のみ更新されることは既存 `ensureCurrentPrice` の仕様（sync 済 row は不変）— 既存テストでカバー済み

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | incremental（単一 worktree・施策 1→2→3→4→5→6→7 の順で段階コミット） |
| 判断根拠 | 施策間に明確な依存（DTO→画面、バックエンド→webhook→フロント）があり、単一ブランチの直列実装が最も安全。施策7 は独立コミットで revert 可能性を確保 |
| 競合リスク | `routes/web.php` / `StripeWebhookProcessor.php` / `MassAssignmentProtectedKeys.php` は他 TODO と競合しやすい。実装前に main 最新を取り込むこと |

## 検証チェックリスト（実装完了条件）

- `composer test`（既存課金テスト含め全 green）/ `composer phpstan`（lv10）/ `vendor/bin/pint --test`
- `pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm build`
- 不変条件のテスト登録: 冪等（同 token / 別 token 同 count / 期限切れ / webhook 再送 / crash 先着）・fail-closed（金額・通貨・org 照合 / tier 空 / floor）・認可（member 403 / cross-org は current org 解決で構造的に不可）・保護キー 422・未契約 org 到達可


## 関連する現行コード（抜粋）

### app/Services/Billing/StripeWebhookProcessor.php（claim / process / grantMonthlyTickets 抜粋）
```php
    public function handle(WebhookReceived $event): void
    {
        /** @var array<mixed> $payload */
        $payload = $event->payload;
        $eventId = $this->stringAt($payload, 'id');
        $type = $this->stringAt($payload, 'type');
        if ($eventId === null || $type === null) {
            return; // 形式不正の payload は処理対象外 (署名検証は Cashier middleware 側)
        }

        $record = $this->claim($eventId, $type, $payload);
        if ($record === null) {
            return; // 同一 event_id を処理済み (冪等 skip)
        }

        try {
            $this->process($type, $payload);
        } catch (Throwable $exception) {
            $record->status = WebhookEventStatus::Failed;
            $record->failure_reason = $exception->getMessage();
            $record->save();
            report($exception);

            throw $exception; // 200 を返さず Stripe の再送を促す (failed は再送で再処理)
        }

        $record->status = WebhookEventStatus::Processed;
        $record->failure_reason = null;
        $record->processed_at = CarbonImmutable::now();
        $record->save();
    }

    /**
     * 冪等記録の獲得。処理すべきときだけ record を返す。
     * - 未受信: 新規 received で記録して返す
     * - processed / received (処理中): null (二重処理 skip)
     * - failed: attempts をインクリメントして received に戻して返す (Stripe 再送による再処理)。
     *   ただし attempts が MAX_PROCESSING_ATTEMPTS に到達済みなら null (terminal-ack:
     *   処理せず 200 を返し Stripe の自動再送を打ち切る)
     *
     * @param  array<mixed>  $payload
     */
    private function claim(string $eventId, string $type, array $payload): ?StripeWebhookEvent
    {
        return DB::transaction(function () use ($eventId, $type, $payload): ?StripeWebhookEvent {
            $existing = StripeWebhookEvent::query()
                ->where('event_id', $eventId)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                if ($existing->status !== WebhookEventStatus::Failed) {
                    return null;
                }
                if ($existing->attempts >= self::MAX_PROCESSING_ATTEMPTS) {
                    // terminal: 構造化ログで可観測化し、運用側が failure_reason を見て手動対応する
                    Log::warning('stripe webhook: terminal failure, acking to stop Stripe retries', [
                        'event_id' => $eventId,
                        'type' => $type,
                        'attempts' => $existing->attempts,
                    ]);

                    return null;
                }
                $existing->status = WebhookEventStatus::Received;
                $existing->attempts += 1;
                $existing->save();

                return $existing;
            }

            $record = new StripeWebhookEvent;
            // 全カラム明示代入 (クライアント入力は入らない)
            $record->event_id = $eventId;
            $record->type = $type;
            $record->status = WebhookEventStatus::Received;
            $record->payload = $payload;
            $record->save();

            return $record;
        });
    }

    /**
     * @param  array<mixed>  $payload
     */
    private function process(string $type, array $payload): void
    {
        // 処理イベント集合の単一出典は HandledStripeWebhookEvent (購読集合の導出元)。
        // case を足したらここに arm を足す (handled ⊆ subscribed は invariant test が担保)
        match (HandledStripeWebhookEvent::tryFrom($type)) {
            HandledStripeWebhookEvent::SubscriptionCreated,
            HandledStripeWebhookEvent::SubscriptionUpdated => $this->syncSubscriptionState($payload),
            HandledStripeWebhookEvent::SubscriptionDeleted => $this->clearPlanCode($payload),
            HandledStripeWebhookEvent::InvoicePaid => $this->grantMonthlyTickets($payload),
            HandledStripeWebhookEvent::ChargeRefunded => $this->clawbackRefundedTickets($payload),
            HandledStripeWebhookEvent::InvoicePaymentFailed => $this->handleInvoicePaymentFailed($payload),
            // 拡張点: テンプレートでは受理のみ (派生アプリで
            // TicketLedgerService::grantPurchased によるチケット購入付与等を実装する)
            HandledStripeWebhookEvent::CheckoutSessionCompleted => null,
            null => null, // 未対応 type は受理のみ (processed として記録)
        };
    }

    /**
     * customer.subscription.created/updated: plan_code 同期 + 次回更新日時の同期。
```

### app/Services/Billing/TicketLedgerService.php（grantPurchased / balance 抜粋）
```php
    /**
     * 買い切りチケット付与 (checkout.session.completed 由来。無期限)。
     *
     * 冪等キーは `purchase:{checkout session id}`。返金逆仕訳の正本キー (payment_intent_id) と
     * 按分分母 (purchaseAmount = 元決済額) を同一エントリに記録する。
     */
    public function grantPurchased(
        Organization $organization,
        int $amount,
        string $stripeSessionId,
        ?string $paymentIntentId = null,
        ?int $purchaseAmount = null,
    ): void {
        Assert::positiveInteger($amount, 'grantPurchased の amount は正の整数のみ');
        Assert::stringNotEmpty($stripeSessionId);

        $this->insertIdempotent($organization, "purchase:{$stripeSessionId}", [
            'delta' => $amount,
            'kind' => TicketLedgerKind::Grant->value,
            'source' => TicketSource::Purchased->value,
            'description' => "チケット購入 (checkout session: {$stripeSessionId})",
            'granted_at' => CarbonImmutable::now(),
            'expires_at' => null,
            'stripe_checkout_session_id' => $stripeSessionId,
            'payment_intent_id' => $paymentIntentId,
            'purchase_amount' => $purchaseAmount,
        ]);
    }

    /**
     * charge.refunded 受信時に買い切りチケットを逆仕訳 (clawback) する。
     *
     * charge.payment_intent → purchased 付与エントリ (正本) を引き、累積返金額 $amountRefunded に
     * 対応する「逆仕訳すべき累積枚数 (target)」から既逆仕訳枚数 (already) を差し引いた delta のみ
    /**
     * 利用可能残高 (= 未失効の台帳合計 − reserved 予約合計)。
     *
     * 期限付き付与は expires_at 到達で合算から外れる。消費 (reserve_commit / clawback) 行は
     * 期限を持たず残るため、失効は「未消費分も含めた全額失効」として保守的に働く
     * (失効前に消費した分だけ残高が下振れし得るが、over-grant にはならない)。
     * バケット (出所×期限) 単位の厳密な失効会計が必要な派生アプリは
     * source / expires_at 列を使って balance を差し替えること。
     */
    public function balance(Organization $organization): int
    {
        $ledgerTotal = (int) TicketLedgerEntry::query()
            ->where('organization_id', $organization->getKey())
            ->where(function ($query): void {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', CarbonImmutable::now());
            })
            ->sum('delta');

        $reserved = (int) TicketReservation::query()
            ->where('organization_id', $organization->getKey())
            ->where('status', TicketReservationStatus::Reserved)
            ->sum('amount');

        return $ledgerTotal - $reserved;
    }
```

### app/Models/Billing/TicketVolumePrice.php（currentTierFor）
```php
class TicketVolumePrice extends Model
{
    /** 購入枚数の下限 (tier 解決の入力検証) */
    public const int PURCHASE_MIN_COUNT = 1;

    /** 購入枚数の上限 (tier 解決の入力検証。異常量の Checkout 構築を弾く) */
    public const int PURCHASE_MAX_COUNT = 1000;

    /** @var list<string> */
    protected $fillable = [
        'lookup_key',
        'stripe_price_id',
        'min_count',
        'unit_amount',
        'currency',
        'nickname',
        'livemode',
        'synced_at',
        'active_from',
        'active_to',
        'is_current',
    ];

    /**
     * 購入枚数に適用される逐減単価 tier を解決する。
     *
     * - current 行のうち `min_count <= count` を満たす最大 min_count 行を採用する
     * - 該当 0 件は TicketVolumeTierUnavailableException (別段へ黙って落とさない = fail-closed)
     * - production では未 sync (livemode=false or synced_at null) の Price を Assert で弾く
     * - 解決単価が floor (config billing.ticket_unit_price_floor) 未満は設定異常として停止する
     */
    public static function currentTierFor(int $count): TicketVolumeTier
    {
        Assert::range(
            $count,
            self::PURCHASE_MIN_COUNT,
            self::PURCHASE_MAX_COUNT,
            sprintf('チケット購入数は %d〜%d の範囲で指定してください', self::PURCHASE_MIN_COUNT, self::PURCHASE_MAX_COUNT),
        );

        $row = self::query()
            ->where('is_current', true)
            ->where('min_count', '<=', $count)
            ->orderByDesc('min_count')
            ->first();

        if (! $row instanceof self) {
            throw new TicketVolumeTierUnavailableException($count);
        }

        if (app()->environment('production')) {
            Assert::true(
                $row->livemode && $row->synced_at !== null,
                "Stripe 未同期の ticket volume price は本番で使用不可: {$row->lookup_key}",
            );
        }

        // 単価 floor 検証 (seed / sync の設定異常で傾斜表が floor を割った場合に停止する)
        $floor = config('billing.ticket_unit_price_floor');
        Assert::integer($floor, 'config billing.ticket_unit_price_floor は整数で設定してください');
        Assert::greaterThanEq(
            $row->unit_amount,
            $floor,
            "チケット単価 {$row->unit_amount} が floor {$floor} を下回っています (lookup_key={$row->lookup_key})",
        );

        return new TicketVolumeTier(
            minCount: $row->min_count,
            unitAmount: $row->unit_amount,
            stripePriceId: $row->stripe_price_id,
            lookupKey: $row->lookup_key,
        );
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'min_count' => 'integer',
            'unit_amount' => 'integer',
            'livemode' => 'boolean',
            'is_current' => 'boolean',
            'synced_at' => 'immutable_datetime',
            'active_from' => 'immutable_datetime',
            'active_to' => 'immutable_datetime',
        ];
    }
}
```

### app/Http/Controllers/Billing/BillingController.php（既存規約の見本）
```php
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

### routes/web.php（billing 節 + pricing）
```php

/*
|--------------------------------------------------------------------------
| 公開マーケ / 法的ページ (auth 不要)
|--------------------------------------------------------------------------
| /pricing は公開 Inertia 雛形 (SEO minimal 分類。SeoComposer が title を供給)。
| /terms /privacy /commerce-disclosure は Route::view の薄い Blade スタブ。文面が
| 未確定のプレースホルダのため noindex (blade の <meta robots> + NoIndex middleware の
| X-Robots-Tag で二重防御)。正式文面へ差し替えて公開する際に noindex を外すこと。
*/
Route::get('/pricing', fn () => Inertia::render('Pricing'))->name('pricing');
Route::middleware(NoIndex::class)->group(function (): void {
    Route::view('/terms', 'legal.terms')->name('legal.terms');
    Route::view('/privacy', 'legal.privacy')->name('legal.privacy');
    Route::view('/commerce-disclosure', 'legal.commerce-disclosure')->name('legal.commerce-disclosure');
});


    /*
    | 課金 (current org スコープ)。プラン変更は Stripe Checkout / Customer Portal 経由のみ。
    | Stripe webhook ルート (POST /stripe/webhook) は Cashier が自動登録する
    | (CSRF 除外は bootstrap/app.php の validateCsrfTokens except 'stripe/*')。
    | billing / webhook / 組織管理系は課金ゲート (require-active-subscription) の
    | allowlist (gate group に含めない)。未契約でも checkout に到達できることを保証する。
    */
    Route::get('/billing', [BillingController::class, 'index'])
        ->name('billing.index');
    Route::post('/billing/checkout', [BillingController::class, 'checkout'])
        ->name('billing.checkout');
    Route::post('/billing/portal', [BillingController::class, 'portal'])
        ->name('billing.portal');

    /*
    | 組織配下の業務 route (課金ゲート対象)。有効な subscription (BillingAccess 判定)
    | を持たない組織は billing へ redirect される (JSON は 402)。
    | 新しい業務ドメインの route はこの group 内に追加すること。
    */
    Route::middleware(['require-active-subscription', 'project.in-current-org'])->group(function (): void {
```

### app/Http/Controllers/HomeController.php（現行全文）
```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Seo\JsonLd;
use App\Support\Seo\SeoManager;
use App\Support\Seo\SeoMeta;
use App\Support\Seo\SeoUrl;
use Illuminate\Support\Facades\Config;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * トップページ (route: home)。SEO full 分類ページの参考実装:
 * controller が SeoManager にメタを供給すると SeoComposer が完全な SEO ヘッド
 * (canonical / og / JSON-LD) をサーバ描画する。
 */
class HomeController extends Controller
{
    public function __construct(
        private readonly SeoManager $seo,
        private readonly SeoUrl $url,
    ) {}

    public function __invoke(): InertiaResponse
    {
        $siteName = Config::string('seo.site_name');

        $this->seo->set(
            SeoMeta::default($this->url, '/')
                ->withTitle($siteName)
                ->withJsonLd([
                    // logo はアプリ側で public/images/logo.svg を配置して差し替える (placeholder)
                    JsonLd::organization($siteName, $this->url->base(), $this->url->to('/images/logo.svg')),
                    JsonLd::website($siteName, $this->url->base()),
                    // 公開価格が確定したら lowPriceJpy を供給する (null = offers を出さない)
                    JsonLd::softwareApplication(
                        $siteName,
                        $this->url->base(),
                        Config::string('seo.default_description'),
                        null,
                    ),
                ]),
        );

        return Inertia::render('Welcome', [
            'appName' => config('app.name'),
        ]);
    }
}

```

### app/Services/Marketing/ContactUrl.php（既存・再利用対象）
```php
<?php

declare(strict_types=1);

namespace App\Services\Marketing;

use Illuminate\Support\Facades\Log;

/**
 * 問い合わせ CTA の宛先を解決する。
 *
 * `/contact` をフロントに直書きする代わりに、`config('services.marketing.contact_url')` で
 * 内部 route / 外部 URL / mailto を切替可能にする (問い合わせを外部フォームサービスに
 * 委ねるアプリ向け)。未設定なら内部フォーム (/contact) を既定にする。
 * source attribution (どこからの導線か) は呼び出し側が query で付与する。
 */
final class ContactUrl
{
    public const INTERNAL_DEFAULT = '/contact';

    /** 解決後の宛先 URL。 */
    public function resolve(): string
    {
        $configured = config('services.marketing.contact_url');

        if (! is_string($configured) || $configured === '') {
            return self::INTERNAL_DEFAULT;
        }

        // scheme allowlist で fail-close。`javascript:` / `data:` 等の危険 scheme が
        // front の href に届くのを防ぐ (この値は Inertia prop として front に渡る)。
        if (! $this->isSafeDestination($configured)) {
            Log::warning('marketing.contact_url に許可外の値が設定されています。内部フォームへ fallback します。', [
                'configured' => $configured,
            ]);

            return self::INTERNAL_DEFAULT;
        }

        return $configured;
    }

    /**
     * 宛先として許可する形式か (内部 path / http(s) / mailto のみ)。
     */
    private function isSafeDestination(string $url): bool
    {
        // `//evil.com` / `/\evil.com` は内部 path に見えて外部遷移する (ブラウザは backslash を
        // slash に正規化する)。`/` 始まりでも 2 文字目が `/` または `\` のものは
        // protocol-relative 相当として内部 path から除外する。
        if (str_starts_with($url, '/')) {
            $second = $url[1] ?? '';

            return $second !== '/' && $second !== '\\';
        }

        return str_starts_with($url, 'http://')
            || str_starts_with($url, 'https://')
            || str_starts_with($url, 'mailto:');
    }

    /** 宛先種別 (フロントの遷移方法分岐用)。 */
    public function kind(): ContactDestinationKind
    {
        $url = $this->resolve();

        if (str_starts_with($url, 'mailto:')) {
            return ContactDestinationKind::Mailto;
        }
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return ContactDestinationKind::External;
        }

        return ContactDestinationKind::Internal;
    }
}

```

### database/seeders/TicketVolumePriceSeeder.php（TIERS）
```php
class TicketVolumePriceSeeder extends Seeder
{
    /**
     * 段別単価表 (min_count => unit_amount)。min_count=1 の行が spot 単価を兼ねる。
     *
     * @var array<int, int>
     */
    private const array TIERS = [
        1 => 100,
        20 => 80,
        50 => 70,
        100 => 65,
        200 => 60,
        300 => 55,
        500 => 50,
    ];

    public function run(): void
    {
```

### app/DataTransferObjects/Billing/TicketVolumeTier.php（currency 追加の対象）
```php
<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Billing;

/**
 * スポット購入の数量逐減 (volume tier) で解決された 1 段分の単価 snapshot。
 *
 * `ticket_volume_prices` current の `min_count <= count` 最大行を表す
 * (min_count=1 の行が spot 単価を兼ねる)。派生アプリの Stripe Checkout は
 * `stripePriceId` × quantity=count で構築し、webhook は `unitAmount` で金額整合を検証する。
 */
final readonly class TicketVolumeTier
{
    public function __construct(
        public int $minCount,
        public int $unitAmount,
        public string $stripePriceId,
        public string $lookupKey,
    ) {}
}

```

### bootstrap/app.php（InsufficientTickets 402 変換）
```php
        $exceptions->render(function (InsufficientTicketsException $exception, Request $request) {
            if ($request->is('api/*')) {
                return null; // ApiExceptionRenderer に委譲 (既存)
            }
            if ($request->expectsJson()) {
                // XHR (analyze 等) は 402 + JsonResource (response()->json() 直書きはしない)
                return InsufficientTicketsResource::make($exception)
                    ->response($request)
                    ->setStatusCode(402);
            }

            return back()->with('error', $exception->getMessage()); // 既存の web 挙動を維持
        });
```
