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
 *   baseAmountJpy: int|null,        // null = 基本料金なし = 無料表示 (下記契約)
 *   monthlyTicketGrant: int,
 *   maxProjects: int|null,          // null = 無制限（quota limits に key なし）
 *   maxMembers: int|null,
 *   maxStorageGb: int|null          // GiB 換算の表示値 (intdiv(bytes, 1024**3) 切り捨て)
 * }
 *
 * baseAmountJpy の契約: AI-CUE のプラン台帳では「plan_prices (base) を持たない = Checkout 対象外の
 * 無料プラン」(PlanSeeder の既存意味論。Billing/Index.svelte の formatPrice(null)=>「無料」と同じ表示契約)。
 * 「お問い合わせ」種別のプランを将来 Plan 行として導入する場合は、null の多義化を避けるため
 * 表示状態の明示フィールド追加を先に行うこと (この docblock を実装コメントにも転記する)。
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
// resources/js/types/marketing.ts（PHP shape と exact 対。全プロパティ readonly で accidental widening を防ぐ）
export interface LandingPageProps {
    readonly signupGrantTickets: number;
    readonly contactUrl: string;
    readonly contactIsExternal: boolean;
    readonly isAuthenticated: boolean;
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
- `app/Services/Marketing/ContactUrl.php`（`resolveForSource(InquirySource $source): string` 追加。内部 path のときのみ `?`/`&` を使い分けて `source={value}` を付与し、`#fragment` があれば fragment 直前に挿入する。外部 URL / mailto は `resolve()` と同値を返す）
- `resources/js/pages/Welcome.svelte`（10 行雛形 → 実 LP。render 先名は `Welcome` のまま）

### 波及変更

- TypeScript 型定義: `types/marketing.ts` の `LandingPageProps`（施策1）
- テストファイル: `tests/js/pages/Welcome.test.ts`（全面書き換え）、`tests/Feature/Seo/SeoHeadCompositionTest.php`（home の JSON-LD 期待に offers が加わる場合は期待値更新）、`ContactUrl` の既存ユニット/Feature テストへ `resolveForSource` のケース追加（既定 `/contact` → `/contact?source=landing`、query 既存の内部 path → `&source=` 連結、`/contact#form` → `/contact?source=landing#form`、`/contact?foo=1#form` → `/contact?foo=1&source=landing#form`、外部 URL / mailto → 付与なし）
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

    // ContactUrl::resolveForSource (施策2 で追加): 内部 path のときのみ source を安全に付与
    // (既存 query の有無を判定して ?/& を使い分け。外部 URL / mailto には付与しない)
    $contactUrl = $this->contactUrl->resolveForSource(InquirySource::Landing);

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
- 新規: `resources/js/components/molecules/PricingPlanCard.svelte` + `PricingPlanCard.types.ts`（aigenba 移植: primitive props + footerCta snippet。`disabled` なし）。**props 契約は AI-CUE 用に変更**: `priceAmount: number | null` は **null → 「無料」表示**（0 も防御的に同一表示。AI-CUE の台帳意味論 = 価格行なし = 無料。aigenba の `contactLabel`（null=お問い合わせ）分岐は移植しない — 該当プランが存在しない機能を作らない。大規模利用の問い合わせはカード外の静的バナーが担う）

### 波及変更

- TypeScript 型定義: `types/marketing.ts`（施策1）
- テストファイル: `tests/js/pages/Pricing.test.ts`（新規）、`tests/Feature/Marketing/PricingPageTest.php`（新規）。**seeder 登録の波及**: テストは `$seed=true` で全 seeder が走るため、`TicketVolumePriceSeeder` 追加により既存テストの前提（volume price 0 行）が変わる — `TicketVolumeTierTest` は自前 seed のため要確認・調整（現行 seed 行と衝突しないか）。
- SEO: `PricingController` が SeoManager へ full メタを供給（既存 minimal 分類からの移行。`config/seo.php` の route 分類設定があれば追随）

### 変更後コード（要点）

```php
// app/Services/Marketing/PricingService.php
// 注意: デフォルト解決 (非 singleton) 前提。メモ化はリクエスト内キャッシュとして設計しているため
// singleton 登録するとリクエスト間で価格変更が反映されなくなる (docblock に明記)。
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
    // intOrNull / storageGb は private static、mixed を is_int で絞り込む。
    // storageGb の変換規則: intdiv($bytes, 1024 ** 3) = GiB 切り捨て (free: 1GiB→1 / standard: 50GiB→50)。
    // Feature テストと Vitest の期待値もこの規則に固定する。
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
        $contactUrl = $this->contact->resolveForSource(InquirySource::Pricing);

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
2. プランカードグリッド（`sm:grid-cols-2`）: `PricingPlanCard`（name / priceAmount（**null・0 は「無料」表示** = DTO 契約と対）/ priceCaption「基本料金」/ features）。features は `月 {monthlyTicketGrant} 枚のチケット付与` / `プロジェクト {maxProjects ?? '無制限'}` / `メンバー {maxMembers ?? '無制限'} 名` / `ストレージ {maxStorageGb} GB`。CTA: 未認証 = 「このプランで始める」(`/register`)、認証済 = 「プランを変更」(`/billing`)。
3. 大規模利用バナー: 「より大きな組織・拠点展開のご相談」→ 問い合わせ CTA（`contactUrl` / external 分岐は aigenba と同じ）。
4. **チケット料金表**（`id="ticket-pricing"`）: aigenba の帯変換ロジック（`{minCount}〜{next.minCount - 1} 枚` / 最終段 `{minCount} 枚以上`）そのまま + signup grant 注記「新規契約でチケット {signupGrantTickets} 枚が無料（{signupGrantExpiryDays} 日間有効）」。
5. FAQ アコーディオン（`aria-expanded` 付き button。aigenba 実装踏襲）: 「無料で試せますか？」「チケットは何に使いますか？（AI 解析 1 枚 / 動画レンダ 3 枚、プレビューは無料）」「追加チケットの購入方法（1 枚 ¥{spotUnitAmountJpy}〜・まとめ買いで単価逓減・組織のオーナー/管理者が購入）」「解約・プラン変更」。

### PHPStan適合チェック

- [x] Service は DTO の list を返す（配列 shape は docblock 固定）
- [x] `config()` の mixed は Assert で絞る（quota.plans / manual.*_ticket_cost）
- [x] `$price?->amount` の null 伝播を shape の `int|null` で受ける

### テスト計画

- [ ] Feature 新規 `tests/Feature/Marketing/PricingPageTest.php`:
  - guest GET `/pricing` 200 + component=Pricing + `page.plans` に free/standard（free の baseAmountJpy=null。**standard の baseAmountJpy はリテラルではなく seed 済み `Plan::currentPrice(Base)->amount` から導出して一致検証** = 施策7 の価格改定コミットでこのテストの修正が不要になる）
  - `page.ticketTiers` が 7 段・昇順（1/100 … 500/50）、`spotUnitAmountJpy`=100
  - `signupGrantTickets`=10 / `signupGrantExpiryDays`=30 / `analysisTicketCost`=1 / `renderTicketCost`=3
  - quota limits 反映（free: maxProjects=1 / maxMembers=3 / maxStorageGb=1。GiB 切り捨て規則の固定）
- [ ] **Seeder 登録の副作用棚卸し（同 PR で完了を確約 = 完了条件）**: `TicketVolumePriceSeeder::class` を DatabaseSeeder に追加するコミットを単独で作り、その時点で `composer test` を全走。`ticket_volume_prices` 0 行を暗黙前提にする既存テストを棚卸しして期待値を更新する（**テスト削除・上書きはしない**）。特に `TicketVolumeTierTest` は自前 seed と seeder 行の衝突（min_count×is_current の partial UNIQUE）を確認し、必要なら自前 seed を seeder 呼び出しへ寄せる
- [ ] Vitest `Pricing.test.ts`（新規）: プランカード 2 枚・**free（baseAmountJpy=null）が「無料」、standard が「¥N／月」表示**（null と 0 の挙動を PricingPlanCard 単体テストでも固定）・チケット帯表示（「20〜49 枚 ¥80」）・FAQ 開閉・`disabled` 不在

### リスク

- `DatabaseSeeder` への seeder 追加は全テストの seed 前提を変える → 上記の単独コミット + 全走棚卸しで管理（Seeder 登録はアプリ機能要件: 購入 tier 解決と dev/prod bootstrap に必要で、seeder docblock が定める「傾斜単価を使う派生アプリが DatabaseSeeder へ追加する」正規オプトイン経路）。
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
- ドキュメント: `docs/architecture.md` / `docs/factories.md` に TicketCheckoutSession を追記。`docs/architecture.md` 課金節に terminal failure の運用手順（failure_reason 参照 → 手動 grantPurchased 判断）を 1 段追記

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

`CashierTicketCheckoutGateway`: `$organization->createOrGetStripeCustomer()` → `$organization->stripe()->checkout->sessions->create([...], ['idempotency_key' => $idempotencyKey])`。payload 構築は `buildSessionPayload()`（`@return array<string, mixed>` の pure メソッド）に分離し、`mode=payment`, `customer`, `line_items=[[price, quantity]]`, `payment_method_types=['card']`, `success_url`, `cancel_url`, `metadata` のみを含める。**`allow_promotion_codes` / `automatic_tax` を含まないことをユニットテストで invariant 固定**（金額照合 `amount_subtotal === count × unit` の前提。promo/tax を将来有効化する場合は照合式を amount_total 系へ移行し、この invariant テストの更新が変更の入口）。url / expires_at の null は Assert で弾く（hosted mode では常に返る）。`AppServiceProvider` で bind。テストは fake を bind（決定的な `cs_test_{token}` / url / expires_at=+24h を返し、呼び出しを記録）。

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
        // metadata は照合専用 (認可・org 解決の判断には一切使わない。真実源は ticket_checkout_sessions 行)。
        // tenant キー不信の誤読を防ぐため organization_id ではなく非権限キー名 org_ref を使う。
        $created = $this->gateway->createTicketCheckout(
            $org, $tier->stripePriceId, $count,
            route('billing.tickets.show', ['purchased' => 1]),
            route('billing.tickets.show'),
            'purchase:'.$attemptToken,
            ['purpose' => 'ticket_purchase', 'org_ref' => (string) $org->id, 'count' => (string) $count],
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
            // 並行 race / Stripe idempotency replay: 既存行 re-read で replay / stale に収束 (500 にしない)。
            // orWhere は使わず 2 段の確定クエリで引く (括弧化漏れによる cross-org 混線を構造的に防ぐ):
            //  (1) UNIQUE(org, attempt_token) スコープ → 高々 1 行
            //  (2) global UNIQUE(stripe_session_id) → 引けても自 org 行でなければ replay しない
            $existing = TicketCheckoutSession::query()
                ->where('organization_id', $org->id)
                ->where('attempt_token', $attemptToken)
                ->first();
            if ($existing === null) {
                $existing = TicketCheckoutSession::query()
                    ->where('stripe_session_id', $created->sessionId)
                    ->first();
                if ($existing !== null && $existing->organization_id !== $org->id) {
                    $existing = null; // 自 org 行以外は絶対に replay しない (fail-closed)
                }
            }
            if ($existing !== null
                && $existing->isLivePending(CarbonImmutable::now())
                && $existing->ticket_count === $count) {
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
    // org_ref は照合専用 (認可・org 解決には使わない。真実源は DB 行 → organization relation)
    $metaOrgRef = $this->stringAt($payload, 'data.object.metadata.org_ref');
    if ($metaOrgRef !== (string) $organization->id) {
        throw new RuntimeException("ticket purchase webhook: metadata org_ref 照合不一致 (session {$sessionId})");
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
- [ ] gateway payload invariant: `buildSessionPayload()` が `allow_promotion_codes` / `automatic_tax` を含まない（施策4 のユニットテストだが金額照合の前提として本施策と対で保守）
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

表示部: `{#if showPurchaseLink}<TextLink href="/purchase-tickets">チケットを購入する</TextLink>{/if}` をエラーメッセージ直後に追加（`types/manual.ts` の `InsufficientTicketsBody` を再利用した type guard。両パネル共通のため `components/features/manual/insufficient-tickets.ts` の小ヘルパに置く — features/{domain} 配下は atomic-import-graph の単方向規約で他ドメインからの import が機械的に禁止されるため境界は保たれる）。

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
  - 施策3 `PricingPageTest` は seed 値導出の検証（リテラル非依存）にしてあるため**本施策での修正不要**（二重修正回避）
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
