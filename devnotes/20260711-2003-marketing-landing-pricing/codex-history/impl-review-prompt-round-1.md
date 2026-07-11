# 使命 (North Star)

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

# 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)

# セキュリティ不変条件(アプリ都合で緩めない)

1. **tenant キー不信**: ownership/actor/tenant キーを payload から受け取らない
2. **子は親に属する**: nested route の不整合は認可より前に 404
3. **cross-org 不可**: 組織を跨ぐ read/write をしない
4. **untrusted 文字列は UserInput 型経由でのみ prompt に入れる**
5. **権限判定は常に `laratrust_team_id` を明示**(strict_check=true)
6. **PII(email/name)は CipherSweet**。検索は `whereBlind()`
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

# 役割・タスク

あなたはシニア Laravel 12 / Svelte 5 (Inertia) エンジニアとして、TODO T007「LP(トップ) + 料金表 + チケットリチャージ (aigenba 移植)」の**最終実装レビュー**を行う。

対象は main と todo/T007 ブランチの差分全体（下記 user 部に diff 全文を添付）。設計ドキュメントは `/workspace/devnotes/20260711-2003-marketing-landing-pricing/detailed-design.md` を参照してよい。実装ファイル本体は `/workspace/.claude/worktrees/tasks/T007/` 配下にある（読み込み可）。

観点:
1. **正当性バグ**: ロジック誤り、境界条件、null/空配列、レースコンディション
2. **セキュリティ不変条件違反**: 上記 8 項目、特にチケット購入 Stripe Checkout の冪等性(webhook 二重配送・checkout セッション再利用)、tenant キー、認可
3. **課金の正確性**: 数量ティア価格計算、通貨・端数、Stripe fixture との整合
4. **規約違反**: 禁止事項 1〜8、DTO 経由、Svelte 5 runes、atomic import 階層
5. **テスト網羅**: 重要パスにテスト漏れがないか

出力形式:
- `## Critical` / `## Warning` / `## Suggestion` の 3 セクション
- 各指摘は「ファイルパス:行 — 指摘内容 — 根拠 — 修正案」
- 指摘が無いセクションは「なし」と明記
- 最後に `## 総評` で 3 行以内のまとめ

なお全検証コマンド (composer test 1424 passed / phpstan 0 errors / pint / eslint / tsc / vitest 399 passed / vite build) は green 済み。

---

# user 部: main...todo/T007 の diff 全文

```diff
diff --git a/app/DataTransferObjects/Billing/CreatedCheckoutSession.php b/app/DataTransferObjects/Billing/CreatedCheckoutSession.php
new file mode 100644
index 0000000..74f61d1
--- /dev/null
+++ b/app/DataTransferObjects/Billing/CreatedCheckoutSession.php
@@ -0,0 +1,19 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\DataTransferObjects\Billing;
+
+use Carbon\CarbonImmutable;
+
+/**
+ * Gateway が作成した Stripe Checkout Session の snapshot (hosted mode 前提)。
+ */
+final readonly class CreatedCheckoutSession
+{
+    public function __construct(
+        public string $sessionId,
+        public string $url,
+        public CarbonImmutable $expiresAt,
+    ) {}
+}
diff --git a/app/DataTransferObjects/Billing/PurchaseTicketsPageDto.php b/app/DataTransferObjects/Billing/PurchaseTicketsPageDto.php
new file mode 100644
index 0000000..d64679f
--- /dev/null
+++ b/app/DataTransferObjects/Billing/PurchaseTicketsPageDto.php
@@ -0,0 +1,60 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\DataTransferObjects\Billing;
+
+/**
+ * チケット購入画面 (/purchase-tickets) の Inertia page prop。
+ *
+ * TS 側は resources/js/types/billing.ts の PurchaseTicketsPageProps と exact 対で保守する。
+ *
+ * @phpstan-import-type PurchaseTierShape from PurchaseTierDto
+ *
+ * @phpstan-type PurchaseTicketsPageShape array{
+ *   tiers: list<PurchaseTierShape>,
+ *   minCount: int,
+ *   maxCount: int,
+ *   defaultCount: int,
+ *   balance: int,
+ *   canManage: bool,
+ *   attemptToken: string,
+ *   purchased: bool
+ * }
+ */
+final readonly class PurchaseTicketsPageDto
+{
+    /**
+     * @param  list<PurchaseTierDto>  $tiers
+     */
+    public function __construct(
+        public array $tiers,
+        public int $minCount,
+        public int $maxCount,
+        public int $defaultCount,
+        public int $balance,
+        public bool $canManage,
+        public string $attemptToken,
+        public bool $purchased,
+    ) {}
+
+    /**
+     * @return PurchaseTicketsPageShape
+     */
+    public function toArray(): array
+    {
+        return [
+            'tiers' => array_map(
+                static fn (PurchaseTierDto $tier): array => $tier->toArray(),
+                $this->tiers,
+            ),
+            'minCount' => $this->minCount,
+            'maxCount' => $this->maxCount,
+            'defaultCount' => $this->defaultCount,
+            'balance' => $this->balance,
+            'canManage' => $this->canManage,
+            'attemptToken' => $this->attemptToken,
+            'purchased' => $this->purchased,
+        ];
+    }
+}
diff --git a/app/DataTransferObjects/Billing/PurchaseTierDto.php b/app/DataTransferObjects/Billing/PurchaseTierDto.php
new file mode 100644
index 0000000..8ecd9bf
--- /dev/null
+++ b/app/DataTransferObjects/Billing/PurchaseTierDto.php
@@ -0,0 +1,34 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\DataTransferObjects\Billing;
+
+/**
+ * チケット傾斜単価の表示用 slim DTO (Stripe Price ID / lookup_key は front へ出さない)。
+ *
+ * @phpstan-type PurchaseTierShape array{minCount: int, unitAmount: int}
+ */
+final readonly class PurchaseTierDto
+{
+    public function __construct(
+        public int $minCount,
+        public int $unitAmount,
+    ) {}
+
+    public static function fromTier(TicketVolumeTier $tier): self
+    {
+        return new self($tier->minCount, $tier->unitAmount);
+    }
+
+    /**
+     * @return PurchaseTierShape
+     */
+    public function toArray(): array
+    {
+        return [
+            'minCount' => $this->minCount,
+            'unitAmount' => $this->unitAmount,
+        ];
+    }
+}
diff --git a/app/DataTransferObjects/Billing/TicketCheckoutRedirect.php b/app/DataTransferObjects/Billing/TicketCheckoutRedirect.php
new file mode 100644
index 0000000..aa73a6d
--- /dev/null
+++ b/app/DataTransferObjects/Billing/TicketCheckoutRedirect.php
@@ -0,0 +1,19 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\DataTransferObjects\Billing;
+
+/**
+ * 冪等 checkout 開始の結果。
+ *
+ * - url あり → Inertia::location で Stripe Checkout へ遷移 (新規 or replay)
+ * - url null + alreadyCompleted → 「受付済み」着地 (付与反映待ちの案内)
+ */
+final readonly class TicketCheckoutRedirect
+{
+    public function __construct(
+        public ?string $url,
+        public bool $alreadyCompleted,
+    ) {}
+}
diff --git a/app/DataTransferObjects/Billing/TicketVolumeTier.php b/app/DataTransferObjects/Billing/TicketVolumeTier.php
index 324f1c9..22aa813 100644
--- a/app/DataTransferObjects/Billing/TicketVolumeTier.php
+++ b/app/DataTransferObjects/Billing/TicketVolumeTier.php
@@ -18,5 +18,6 @@ public function __construct(
         public int $unitAmount,
         public string $stripePriceId,
         public string $lookupKey,
+        public string $currency,
     ) {}
 }
diff --git a/app/DataTransferObjects/Marketing/LandingPageDto.php b/app/DataTransferObjects/Marketing/LandingPageDto.php
new file mode 100644
index 0000000..51f83ae
--- /dev/null
+++ b/app/DataTransferObjects/Marketing/LandingPageDto.php
@@ -0,0 +1,40 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\DataTransferObjects\Marketing;
+
+/**
+ * LP (トップ `/`) の Inertia page prop (単一真実源の shape 定義)。
+ *
+ * TS 側は resources/js/types/marketing.ts の LandingPageProps と exact 対で保守する。
+ *
+ * @phpstan-type LandingPageShape array{
+ *   signupGrantTickets: int,
+ *   contactUrl: string,
+ *   contactIsExternal: bool,
+ *   isAuthenticated: bool
+ * }
+ */
+final readonly class LandingPageDto
+{
+    public function __construct(
+        public int $signupGrantTickets,
+        public string $contactUrl,
+        public bool $contactIsExternal,
+        public bool $isAuthenticated,
+    ) {}
+
+    /**
+     * @return LandingPageShape
+     */
+    public function toArray(): array
+    {
+        return [
+            'signupGrantTickets' => $this->signupGrantTickets,
+            'contactUrl' => $this->contactUrl,
+            'contactIsExternal' => $this->contactIsExternal,
+            'isAuthenticated' => $this->isAuthenticated,
+        ];
+    }
+}
diff --git a/app/DataTransferObjects/Marketing/PricingPageDto.php b/app/DataTransferObjects/Marketing/PricingPageDto.php
new file mode 100644
index 0000000..ef69b30
--- /dev/null
+++ b/app/DataTransferObjects/Marketing/PricingPageDto.php
@@ -0,0 +1,73 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\DataTransferObjects\Marketing;
+
+use App\DataTransferObjects\Billing\PurchaseTierDto;
+
+/**
+ * 料金表 (/pricing) の Inertia page prop (単一真実源の shape 定義)。
+ *
+ * TS 側は resources/js/types/marketing.ts の PricingPageProps と exact 対で保守する。
+ *
+ * @phpstan-import-type PricingPlanShape from PricingPlanDto
+ * @phpstan-import-type PurchaseTierShape from PurchaseTierDto
+ *
+ * @phpstan-type PricingPageShape array{
+ *   plans: list<PricingPlanShape>,
+ *   ticketTiers: list<PurchaseTierShape>,
+ *   spotUnitAmountJpy: int,
+ *   signupGrantTickets: int,
+ *   signupGrantExpiryDays: int,
+ *   analysisTicketCost: int,
+ *   renderTicketCost: int,
+ *   isAuthenticated: bool,
+ *   contactUrl: string,
+ *   contactIsExternal: bool
+ * }
+ */
+final readonly class PricingPageDto
+{
+    /**
+     * @param  list<PricingPlanDto>  $plans
+     * @param  list<PurchaseTierDto>  $ticketTiers
+     */
+    public function __construct(
+        public array $plans,
+        public array $ticketTiers,
+        public int $spotUnitAmountJpy,
+        public int $signupGrantTickets,
+        public int $signupGrantExpiryDays,
+        public int $analysisTicketCost,
+        public int $renderTicketCost,
+        public bool $isAuthenticated,
+        public string $contactUrl,
+        public bool $contactIsExternal,
+    ) {}
+
+    /**
+     * @return PricingPageShape
+     */
+    public function toArray(): array
+    {
+        return [
+            'plans' => array_map(
+                static fn (PricingPlanDto $plan): array => $plan->toArray(),
+                $this->plans,
+            ),
+            'ticketTiers' => array_map(
+                static fn (PurchaseTierDto $tier): array => $tier->toArray(),
+                $this->ticketTiers,
+            ),
+            'spotUnitAmountJpy' => $this->spotUnitAmountJpy,
+            'signupGrantTickets' => $this->signupGrantTickets,
+            'signupGrantExpiryDays' => $this->signupGrantExpiryDays,
+            'analysisTicketCost' => $this->analysisTicketCost,
+            'renderTicketCost' => $this->renderTicketCost,
+            'isAuthenticated' => $this->isAuthenticated,
+            'contactUrl' => $this->contactUrl,
+            'contactIsExternal' => $this->contactIsExternal,
+        ];
+    }
+}
diff --git a/app/DataTransferObjects/Marketing/PricingPlanDto.php b/app/DataTransferObjects/Marketing/PricingPlanDto.php
new file mode 100644
index 0000000..93be2c9
--- /dev/null
+++ b/app/DataTransferObjects/Marketing/PricingPlanDto.php
@@ -0,0 +1,55 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\DataTransferObjects\Marketing;
+
+/**
+ * 料金表 (pricing) 表示専用のプラン 1 件分 (Billing 内部 DTO と責務分離)。
+ *
+ * baseAmountJpy の契約: AI-CUE のプラン台帳では「plan_prices (base) を持たない =
+ * Checkout 対象外の無料プラン」(PlanSeeder の既存意味論。Billing/Index.svelte の
+ * formatPrice(null)=>「無料」と同じ表示契約)。「お問い合わせ」種別のプランを将来
+ * Plan 行として導入する場合は、null の多義化を避けるため表示状態の明示フィールド
+ * 追加を先に行うこと。
+ *
+ * maxStorageGb は GiB 換算の表示値 (intdiv(bytes, 1024**3) 切り捨て)。
+ *
+ * @phpstan-type PricingPlanShape array{
+ *   code: string,
+ *   name: string,
+ *   baseAmountJpy: int|null,
+ *   monthlyTicketGrant: int,
+ *   maxProjects: int|null,
+ *   maxMembers: int|null,
+ *   maxStorageGb: int|null
+ * }
+ */
+final readonly class PricingPlanDto
+{
+    public function __construct(
+        public string $code,
+        public string $name,
+        public ?int $baseAmountJpy,
+        public int $monthlyTicketGrant,
+        public ?int $maxProjects,
+        public ?int $maxMembers,
+        public ?int $maxStorageGb,
+    ) {}
+
+    /**
+     * @return PricingPlanShape
+     */
+    public function toArray(): array
+    {
+        return [
+            'code' => $this->code,
+            'name' => $this->name,
+            'baseAmountJpy' => $this->baseAmountJpy,
+            'monthlyTicketGrant' => $this->monthlyTicketGrant,
+            'maxProjects' => $this->maxProjects,
+            'maxMembers' => $this->maxMembers,
+            'maxStorageGb' => $this->maxStorageGb,
+        ];
+    }
+}
diff --git a/app/Enums/Billing/TicketCheckoutSessionStatus.php b/app/Enums/Billing/TicketCheckoutSessionStatus.php
new file mode 100644
index 0000000..7bb0c8a
--- /dev/null
+++ b/app/Enums/Billing/TicketCheckoutSessionStatus.php
@@ -0,0 +1,19 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Enums\Billing;
+
+/**
+ * ticket_checkout_sessions の状態。
+ *
+ * - pending: Checkout URL 発行済み・決済待ち (expires_at > now のときのみ live)
+ * - completed: webhook (checkout.session.completed) で付与済み
+ * - expired: 期限切れ回収 / 別 count 再購入時の明示 expire
+ */
+enum TicketCheckoutSessionStatus: string
+{
+    case Pending = 'pending';
+    case Completed = 'completed';
+    case Expired = 'expired';
+}
diff --git a/app/Enums/Inquiry/InquirySource.php b/app/Enums/Inquiry/InquirySource.php
index 7cf40b6..00ff2b4 100644
--- a/app/Enums/Inquiry/InquirySource.php
+++ b/app/Enums/Inquiry/InquirySource.php
@@ -15,12 +15,14 @@ enum InquirySource: string
 {
     case Landing = 'landing';
     case Billing = 'billing';
+    case Pricing = 'pricing';
 
     public function label(): string
     {
         return match ($this) {
             self::Landing => 'トップページ',
             self::Billing => '請求画面',
+            self::Pricing => '料金プラン',
         };
     }
 
diff --git a/app/Exceptions/Billing/CheckoutInProgressException.php b/app/Exceptions/Billing/CheckoutInProgressException.php
new file mode 100644
index 0000000..6df7499
--- /dev/null
+++ b/app/Exceptions/Billing/CheckoutInProgressException.php
@@ -0,0 +1,15 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Exceptions\Billing;
+
+use RuntimeException;
+
+/**
+ * 直前のチケット購入手続きが進行中 (org 単位 lock の競合 / 決済処理中の expire 拒否)。
+ *
+ * fail-closed: ロックなし実行・二重 live session の作成へフォールバックせず、
+ * ユーザーへ「数秒おいて再試行」を案内する (controller が back()->with('error') に変換)。
+ */
+class CheckoutInProgressException extends RuntimeException {}
diff --git a/app/Exceptions/Billing/StaleCheckoutAttemptException.php b/app/Exceptions/Billing/StaleCheckoutAttemptException.php
new file mode 100644
index 0000000..e53c542
--- /dev/null
+++ b/app/Exceptions/Billing/StaleCheckoutAttemptException.php
@@ -0,0 +1,15 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Exceptions\Billing;
+
+use RuntimeException;
+
+/**
+ * attempt_token が再利用できない状態 (count 不一致・期限切れ・並行 race の非 replayable 行)。
+ *
+ * 画面の再読み込みで新しい attempt_token を発行してやり直してもらう
+ * (controller が back()->with('error') に変換)。
+ */
+class StaleCheckoutAttemptException extends RuntimeException {}
diff --git a/app/Http/Controllers/Billing/TicketPurchaseController.php b/app/Http/Controllers/Billing/TicketPurchaseController.php
new file mode 100644
index 0000000..9794ff0
--- /dev/null
+++ b/app/Http/Controllers/Billing/TicketPurchaseController.php
@@ -0,0 +1,93 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Http\Controllers\Billing;
+
+use App\DataTransferObjects\Billing\PurchaseTicketsPageDto;
+use App\Exceptions\Billing\CheckoutInProgressException;
+use App\Exceptions\Billing\StaleCheckoutAttemptException;
+use App\Exceptions\Billing\TicketVolumeTierUnavailableException;
+use App\Http\Concerns\ResolvesCurrentOrganization;
+use App\Http\Controllers\Controller;
+use App\Http\Requests\Billing\TicketCheckoutRequest;
+use App\Models\Billing\TicketVolumePrice;
+use App\Models\User;
+use App\Services\Billing\TicketCheckoutService;
+use App\Services\Billing\TicketLedgerService;
+use App\Services\Billing\TicketPricingService;
+use Illuminate\Http\RedirectResponse;
+use Illuminate\Http\Request;
+use Illuminate\Support\Facades\Gate;
+use Illuminate\Support\Str;
+use Inertia\Inertia;
+use Inertia\Response;
+use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
+use Webmozart\Assert\Assert;
+
+/**
+ * チケットスポット購入 (current org スコープ)。
+ *
+ * - 閲覧は組織メンバー全員 (残高・料金の透明性)。購入 (Checkout 開始) は
+ *   manageBilling (owner / admin) のみ
+ * - 課金ゲート (require-active-subscription) の対象外 = 未契約 / free プラン組織でも購入可能
+ */
+class TicketPurchaseController extends Controller
+{
+    use ResolvesCurrentOrganization;
+
+    /** 購入画面の枚数入力の初期値 */
+    private const int DEFAULT_COUNT = 10;
+
+    /** 購入画面 (attempt_token は render ごとに ULID 発行) */
+    public function show(Request $request, TicketPricingService $pricing, TicketLedgerService $tickets): Response
+    {
+        $organization = $this->resolveCurrentOrganization($request);
+        Gate::authorize('view', $organization);
+
+        $user = $request->user();
+        Assert::isInstanceOf($user, User::class);
+
+        $dto = new PurchaseTicketsPageDto(
+            tiers: $pricing->volumeTiersForDisplay(),
+            minCount: TicketVolumePrice::PURCHASE_MIN_COUNT,
+            maxCount: TicketVolumePrice::PURCHASE_MAX_COUNT,
+            defaultCount: self::DEFAULT_COUNT,
+            balance: $tickets->balance($organization),
+            canManage: $user->can('manageBilling', $organization),
+            attemptToken: (string) Str::ulid(),
+            purchased: $request->boolean('purchased'), // Stripe success_url からの帰還 (表示専用)
+        );
+
+        return Inertia::render('Billing/PurchaseTickets', ['page' => $dto->toArray()]);
+    }
+
+    /** Checkout 開始 (manageBilling のみ) */
+    public function checkout(TicketCheckoutRequest $request, TicketCheckoutService $service): SymfonyResponse|RedirectResponse
+    {
+        $organization = $this->resolveCurrentOrganization($request);
+        Gate::authorize('manageBilling', $organization);
+
+        $user = $request->user();
+        Assert::isInstanceOf($user, User::class);
+
+        $count = $request->validated('count');
+        $attemptToken = $request->validated('attempt_token');
+        Assert::integerish($count);
+        Assert::string($attemptToken);
+
+        try {
+            $redirect = $service->startCheckout($organization, $user, (int) $count, $attemptToken);
+        } catch (CheckoutInProgressException|StaleCheckoutAttemptException|TicketVolumeTierUnavailableException $e) {
+            return back()->with('error', $e->getMessage());
+        }
+
+        if ($redirect->url === null) {
+            return redirect()->route('billing.tickets.show')
+                ->with('info', 'この購入は既に受付済みです。残高への反映をお待ちください。');
+        }
+
+        // 外部 Stripe への full page redirect
+        return Inertia::location($redirect->url);
+    }
+}
diff --git a/app/Http/Controllers/HomeController.php b/app/Http/Controllers/HomeController.php
index f2bc99d..d3a78c0 100644
--- a/app/Http/Controllers/HomeController.php
+++ b/app/Http/Controllers/HomeController.php
@@ -4,16 +4,22 @@
 
 namespace App\Http\Controllers;
 
+use App\DataTransferObjects\Marketing\LandingPageDto;
+use App\Enums\Inquiry\InquirySource;
+use App\Services\Billing\TicketPricingService;
+use App\Services\Marketing\ContactDestinationKind;
+use App\Services\Marketing\ContactUrl;
 use App\Support\Seo\JsonLd;
 use App\Support\Seo\SeoManager;
 use App\Support\Seo\SeoMeta;
 use App\Support\Seo\SeoUrl;
+use Illuminate\Http\Request;
 use Illuminate\Support\Facades\Config;
 use Inertia\Inertia;
 use Inertia\Response as InertiaResponse;
 
 /**
- * トップページ (route: home)。SEO full 分類ページの参考実装:
+ * トップページ (route: home) = LP。SEO full 分類ページの参考実装:
  * controller が SeoManager にメタを供給すると SeoComposer が完全な SEO ヘッド
  * (canonical / og / JSON-LD) をサーバ描画する。
  */
@@ -22,9 +28,11 @@ class HomeController extends Controller
     public function __construct(
         private readonly SeoManager $seo,
         private readonly SeoUrl $url,
+        private readonly ContactUrl $contact,
+        private readonly TicketPricingService $ticketPricing,
     ) {}
 
-    public function __invoke(): InertiaResponse
+    public function __invoke(Request $request): InertiaResponse
     {
         $siteName = Config::string('seo.site_name');
 
@@ -35,18 +43,27 @@ public function __invoke(): InertiaResponse
                     // logo はアプリ側で public/images/logo.svg を配置して差し替える (placeholder)
                     JsonLd::organization($siteName, $this->url->base(), $this->url->to('/images/logo.svg')),
                     JsonLd::website($siteName, $this->url->base()),
-                    // 公開価格が確定したら lowPriceJpy を供給する (null = offers を出さない)
+                    // Free プランで開始可能 = lowPriceJpy 0 (「無料開始 + チケット制」訴求と一致させる)
                     JsonLd::softwareApplication(
                         $siteName,
                         $this->url->base(),
                         Config::string('seo.default_description'),
-                        null,
+                        0,
                     ),
                 ]),
         );
 
+        $dto = new LandingPageDto(
+            signupGrantTickets: $this->ticketPricing->signupGrantTickets(),
+            // 内部 path のときのみ source を安全に付与 (外部 URL / mailto には付与しない)
+            contactUrl: $this->contact->resolveForSource(InquirySource::Landing),
+            contactIsExternal: $this->contact->kind() === ContactDestinationKind::External,
+            isAuthenticated: $request->user() !== null,
+        );
+
         return Inertia::render('Welcome', [
             'appName' => config('app.name'),
+            'page' => $dto->toArray(),
         ]);
     }
 }
diff --git a/app/Http/Controllers/Marketing/PricingController.php b/app/Http/Controllers/Marketing/PricingController.php
new file mode 100644
index 0000000..96d43aa
--- /dev/null
+++ b/app/Http/Controllers/Marketing/PricingController.php
@@ -0,0 +1,77 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Http\Controllers\Marketing;
+
+use App\DataTransferObjects\Marketing\PricingPageDto;
+use App\Enums\Inquiry\InquirySource;
+use App\Http\Controllers\Controller;
+use App\Services\Billing\TicketPricingService;
+use App\Services\Marketing\ContactDestinationKind;
+use App\Services\Marketing\ContactUrl;
+use App\Services\Marketing\PricingService;
+use App\Support\Seo\JsonLd;
+use App\Support\Seo\SeoManager;
+use App\Support\Seo\SeoMeta;
+use App\Support\Seo\SeoUrl;
+use Illuminate\Http\Request;
+use Illuminate\Support\Facades\Config;
+use Inertia\Inertia;
+use Inertia\Response as InertiaResponse;
+use Webmozart\Assert\Assert;
+
+/**
+ * 公開料金表 (/pricing)。プラン基本料 + チケット傾斜単価 + FAQ を供給する。
+ * SEO は full 供給 (HomeController と同じ SeoMeta 方式。lowPriceJpy=0 = Free)。
+ */
+class PricingController extends Controller
+{
+    public function __construct(
+        private readonly PricingService $pricing,
+        private readonly TicketPricingService $ticketPricing,
+        private readonly ContactUrl $contact,
+        private readonly SeoManager $seo,
+        private readonly SeoUrl $url,
+    ) {}
+
+    public function __invoke(Request $request): InertiaResponse
+    {
+        $analysisCost = config('manual.analysis_ticket_cost');
+        $renderCost = config('manual.render_ticket_cost');
+        Assert::integer($analysisCost);
+        Assert::integer($renderCost);
+
+        $dto = new PricingPageDto(
+            plans: $this->pricing->listPublicPlans(),
+            ticketTiers: $this->ticketPricing->volumeTiersForDisplay(),
+            spotUnitAmountJpy: $this->ticketPricing->spotUnitAmount(),
+            signupGrantTickets: $this->ticketPricing->signupGrantTickets(),
+            signupGrantExpiryDays: $this->ticketPricing->signupGrantExpiryDays(),
+            analysisTicketCost: $analysisCost,
+            renderTicketCost: $renderCost,
+            isAuthenticated: $request->user() !== null,
+            contactUrl: $this->contact->resolveForSource(InquirySource::Pricing),
+            contactIsExternal: $this->contact->kind() === ContactDestinationKind::External,
+        );
+
+        $siteName = Config::string('seo.site_name');
+        $this->seo->set(
+            SeoMeta::default($this->url, '/pricing')
+                ->withTitle('料金プラン')
+                ->withDescription('AI-CUE の料金プラン。無料で始められる Free プランと、チームで使う Standard プラン。AI 解析・動画レンダは共通チケット制で、必要な分だけ購入できます。')
+                ->withJsonLd([
+                    JsonLd::softwareApplication(
+                        $siteName,
+                        $this->url->to('/pricing'),
+                        Config::string('seo.default_description'),
+                        0,
+                    ),
+                ]),
+        );
+
+        return Inertia::render('Pricing', [
+            'page' => $dto->toArray(),
+        ]);
+    }
+}
diff --git a/app/Http/Requests/Billing/TicketCheckoutRequest.php b/app/Http/Requests/Billing/TicketCheckoutRequest.php
new file mode 100644
index 0000000..b4f15af
--- /dev/null
+++ b/app/Http/Requests/Billing/TicketCheckoutRequest.php
@@ -0,0 +1,39 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Http\Requests\Billing;
+
+use App\Http\Requests\Concerns\ProhibitsProtectedKeys;
+use App\Models\Billing\TicketVolumePrice;
+use Illuminate\Foundation\Http\FormRequest;
+
+/**
+ * チケット購入 Checkout 開始。payload は count / attempt_token のみ
+ * (金額・Price ID・organization_id は受け取らない = tenant キー不信)。
+ */
+class TicketCheckoutRequest extends FormRequest
+{
+    use ProhibitsProtectedKeys;
+
+    public function authorize(): bool
+    {
+        return true; // 認可は controller の Gate::authorize('manageBilling') が行う
+    }
+
+    /**
+     * @return array<string, list<string>>
+     */
+    public function rules(): array
+    {
+        return array_merge([
+            'count' => [
+                'required',
+                'integer',
+                'min:'.TicketVolumePrice::PURCHASE_MIN_COUNT,
+                'max:'.TicketVolumePrice::PURCHASE_MAX_COUNT,
+            ],
+            'attempt_token' => ['required', 'ulid'],
+        ], $this->protectedKeyMissingRules());
+    }
+}
diff --git a/app/Models/Billing/TicketCheckoutSession.php b/app/Models/Billing/TicketCheckoutSession.php
new file mode 100644
index 0000000..90dd4c5
--- /dev/null
+++ b/app/Models/Billing/TicketCheckoutSession.php
@@ -0,0 +1,84 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Models\Billing;
+
+use App\Enums\Billing\TicketCheckoutSessionStatus;
+use App\Models\Organization;
+use App\Models\User;
+use Carbon\CarbonImmutable;
+use Database\Factories\Billing\TicketCheckoutSessionFactory;
+use Illuminate\Database\Eloquent\Factories\HasFactory;
+use Illuminate\Database\Eloquent\Model;
+use Illuminate\Database\Eloquent\Relations\BelongsTo;
+
+/**
+ * チケットスポット購入の Stripe Checkout Session 追跡行 (冪等マシンの真実源)。
+ *
+ * - 二重課金防止: UNIQUE(organization_id, attempt_token) + UNIQUE(stripe_session_id) +
+ *   live pending dedup (TicketCheckoutService)
+ * - webhook (checkout.session.completed) は本行の count / unit_amount / currency /
+ *   organization を真実源として金額照合・付与する (payload の metadata は照合専用)
+ * - 全列 Service の明示代入のみ ($fillable = [])
+ *
+ * @property int $id
+ * @property int $organization_id
+ * @property int|null $initiated_by_user_id
+ * @property int $ticket_count
+ * @property int $unit_amount
+ * @property string $currency
+ * @property string $stripe_session_id
+ * @property string $attempt_token
+ * @property string $checkout_url
+ * @property TicketCheckoutSessionStatus $status
+ * @property CarbonImmutable $expires_at
+ * @property CarbonImmutable|null $completed_at
+ * @property CarbonImmutable|null $created_at
+ * @property CarbonImmutable|null $updated_at
+ */
+class TicketCheckoutSession extends Model
+{
+    /** @use HasFactory<TicketCheckoutSessionFactory> */
+    use HasFactory;
+
+    /** @var list<string> 全列を Service が明示代入する (状態キー・FK は relation / 直接代入のみ) */
+    protected $fillable = [];
+
+    /**
+     * @return BelongsTo<Organization, $this>
+     */
+    public function organization(): BelongsTo
+    {
+        return $this->belongsTo(Organization::class);
+    }
+
+    /**
+     * @return BelongsTo<User, $this>
+     */
+    public function initiatedBy(): BelongsTo
+    {
+        return $this->belongsTo(User::class, 'initiated_by_user_id');
+    }
+
+    /** live pending (= replay 可能な決済待ち) か。期限切れ pending は live ではない。 */
+    public function isLivePending(CarbonImmutable $now): bool
+    {
+        return $this->status === TicketCheckoutSessionStatus::Pending
+            && $this->expires_at->greaterThan($now);
+    }
+
+    /**
+     * @return array<string, string>
+     */
+    protected function casts(): array
+    {
+        return [
+            'ticket_count' => 'integer',
+            'unit_amount' => 'integer',
+            'status' => TicketCheckoutSessionStatus::class,
+            'expires_at' => 'immutable_datetime',
+            'completed_at' => 'immutable_datetime',
+        ];
+    }
+}
diff --git a/app/Models/Billing/TicketVolumePrice.php b/app/Models/Billing/TicketVolumePrice.php
index 8740b5f..49507d3 100644
--- a/app/Models/Billing/TicketVolumePrice.php
+++ b/app/Models/Billing/TicketVolumePrice.php
@@ -109,6 +109,7 @@ public static function currentTierFor(int $count): TicketVolumeTier
             unitAmount: $row->unit_amount,
             stripePriceId: $row->stripe_price_id,
             lookupKey: $row->lookup_key,
+            currency: $row->currency,
         );
     }
 
diff --git a/app/Providers/AppServiceProvider.php b/app/Providers/AppServiceProvider.php
index 0713fe9..28244e5 100644
--- a/app/Providers/AppServiceProvider.php
+++ b/app/Providers/AppServiceProvider.php
@@ -18,7 +18,9 @@
 use App\Models\Billing\Subscription;
 use App\Models\Organization;
 use App\Models\User;
+use App\Services\Billing\CashierTicketCheckoutGateway;
 use App\Services\Billing\StripeWebhookProcessor;
+use App\Services\Billing\TicketCheckoutGateway;
 use App\Services\Mail\Sns\AwsSnsSignatureVerifier;
 use App\Services\Mail\Sns\SnsSignatureVerifier;
 use App\Services\Render\FfmpegVideoComposer;
@@ -95,6 +97,9 @@ public function register(): void
 
         // 動画合成の抽象 (doc/09 §9.7)。v1 は ffmpeg 実装。テストは fake 実装へ swap する
         $this->app->bind(VideoComposer::class, FfmpegVideoComposer::class);
+
+        // チケットスポット購入の Stripe Checkout 抽象 (T007)。テストは fake を bind する
+        $this->app->bind(TicketCheckoutGateway::class, CashierTicketCheckoutGateway::class);
     }
 
     public function boot(): void
diff --git a/app/Services/Billing/CashierTicketCheckoutGateway.php b/app/Services/Billing/CashierTicketCheckoutGateway.php
new file mode 100644
index 0000000..b02b5c8
--- /dev/null
+++ b/app/Services/Billing/CashierTicketCheckoutGateway.php
@@ -0,0 +1,104 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Services\Billing;
+
+use App\DataTransferObjects\Billing\CreatedCheckoutSession;
+use App\Models\Organization;
+use Carbon\CarbonImmutable;
+use Laravel\Cashier\Cashier;
+use Webmozart\Assert\Assert;
+
+/**
+ * TicketCheckoutGateway の Cashier (Stripe SDK) 実装。
+ *
+ * Cashier の checkout() ヘルパは per-request idempotency key を公開しないため、
+ * Cashier の Stripe クライアント ($organization->stripe()) を直接使う。
+ */
+final class CashierTicketCheckoutGateway implements TicketCheckoutGateway
+{
+    public function createTicketCheckout(
+        Organization $organization,
+        string $stripePriceId,
+        int $quantity,
+        string $successUrl,
+        string $cancelUrl,
+        string $idempotencyKey,
+        array $metadata,
+    ): CreatedCheckoutSession {
+        $organization->createOrGetStripeCustomer();
+
+        $session = $organization->stripe()->checkout->sessions->create(
+            $this->buildSessionPayload($organization, $stripePriceId, $quantity, $successUrl, $cancelUrl, $metadata),
+            ['idempotency_key' => $idempotencyKey],
+        );
+
+        // hosted mode では url / expires_at が常に返る (欠落は SDK/設定異常として fail-fast)
+        Assert::string($session->url, 'Checkout Session に URL がありません (ui_mode: hosted のみ対応)');
+        Assert::integer($session->expires_at, 'Checkout Session に expires_at がありません');
+
+        return new CreatedCheckoutSession(
+            sessionId: $session->id,
+            url: $session->url,
+            expiresAt: CarbonImmutable::createFromTimestamp($session->expires_at),
+        );
+    }
+
+    public function expireCheckoutSession(string $sessionId): string
+    {
+        // 決済主体は organization だが expire は session id 単独で完結する
+        // (呼び出し側が自 org 行の session id のみ渡す契約)
+        $session = Cashier::stripe()->checkout->sessions->expire($sessionId);
+
+        return is_string($session->status) ? $session->status : 'expired';
+    }
+
+    /**
+     * Checkout Session payload (pure)。
+     *
+     * invariant (TicketPurchaseWebhookTest / gateway ユニットテストで固定):
+     * `allow_promotion_codes` / `automatic_tax` を含まない。webhook の金額照合
+     * `amount_subtotal === count × unit` はこの前提に依存する。promo/tax を将来
+     * 有効化する場合は照合式を amount_total 系へ移行し、invariant テストの更新が変更の入口。
+     *
+     * @param  array<string, string>  $metadata
+     * @return array{
+     *   mode: 'payment',
+     *   customer: string,
+     *   line_items: array{array{price: string, quantity: int}},
+     *   payment_method_types: array{'card'},
+     *   success_url: string,
+     *   cancel_url: string,
+     *   metadata: array<string, string>
+     * }
+     */
+    public function buildSessionPayload(
+        Organization $organization,
+        string $stripePriceId,
+        int $quantity,
+        string $successUrl,
+        string $cancelUrl,
+        array $metadata,
+    ): array {
+        // createOrGetStripeCustomer() 後は必ず存在する (欠落は設定異常として fail-fast)
+        $customerId = $organization->stripe_id;
+        Assert::stringNotEmpty($customerId, 'Stripe customer 未作成の組織では Checkout を作れません');
+
+        return [
+            'mode' => 'payment',
+            'customer' => $customerId,
+            'line_items' => [
+                [
+                    'price' => $stripePriceId,
+                    'quantity' => $quantity,
+                ],
+            ],
+            // 即時決済のみ (非同期決済を許可すると「completed = 決済済み」の前提が壊れる)
+            'payment_method_types' => ['card'],
+            'success_url' => $successUrl,
+            'cancel_url' => $cancelUrl,
+            'metadata' => $metadata,
+        ];
+    }
+}
diff --git a/app/Services/Billing/StripeWebhookProcessor.php b/app/Services/Billing/StripeWebhookProcessor.php
index 5f22077..acdb374 100644
--- a/app/Services/Billing/StripeWebhookProcessor.php
+++ b/app/Services/Billing/StripeWebhookProcessor.php
@@ -6,11 +6,13 @@
 
 use App\Enums\Billing\BillingNotificationType;
 use App\Enums\Billing\HandledStripeWebhookEvent;
+use App\Enums\Billing\TicketCheckoutSessionStatus;
 use App\Enums\Billing\WebhookEventStatus;
 use App\Models\Billing\Plan;
 use App\Models\Billing\PlanPrice;
 use App\Models\Billing\StripeWebhookEvent;
 use App\Models\Billing\Subscription;
+use App\Models\Billing\TicketCheckoutSession;
 use App\Models\Organization;
 use App\Notifications\Billing\PaymentFailedNotification;
 use Carbon\CarbonImmutable;
@@ -19,6 +21,7 @@
 use Laravel\Cashier\Events\WebhookReceived;
 use RuntimeException;
 use Throwable;
+use Webmozart\Assert\Assert;
 
 /**
  * Stripe webhook の冪等マシン (Cashier の WebhookReceived listener)。
@@ -123,6 +126,14 @@ private function claim(string $eventId, string $type, array $payload): ?StripeWe
                         'type' => $type,
                         'attempts' => $existing->attempts,
                     ]);
+                    // 付与系イベントの取りこぼしは「決済済み・未付与」を残すため運用アラート経路
+                    // (report) にも載せる (failure_reason 参照 → 手動 grantPurchased 判断)
+                    if (in_array($type, [
+                        HandledStripeWebhookEvent::CheckoutSessionCompleted->value,
+                        HandledStripeWebhookEvent::InvoicePaid->value,
+                    ], true)) {
+                        report(new RuntimeException("stripe webhook terminal failure (grant イベント): {$eventId} ({$type})"));
+                    }
 
                     return null;
                 }
@@ -159,9 +170,8 @@ private function process(string $type, array $payload): void
             HandledStripeWebhookEvent::InvoicePaid => $this->grantMonthlyTickets($payload),
             HandledStripeWebhookEvent::ChargeRefunded => $this->clawbackRefundedTickets($payload),
             HandledStripeWebhookEvent::InvoicePaymentFailed => $this->handleInvoicePaymentFailed($payload),
-            // 拡張点: テンプレートでは受理のみ (派生アプリで
-            // TicketLedgerService::grantPurchased によるチケット購入付与等を実装する)
-            HandledStripeWebhookEvent::CheckoutSessionCompleted => null,
+            // チケットスポット購入の冪等付与 (T007。真実源は ticket_checkout_sessions 行)
+            HandledStripeWebhookEvent::CheckoutSessionCompleted => $this->grantPurchasedTickets($payload),
             null => null, // 未対応 type は受理のみ (processed として記録)
         };
     }
@@ -351,6 +361,89 @@ private function safelyNotify(callable $notify): void
         }
     }
 
+    /**
+     * checkout.session.completed: チケットスポット購入の冪等付与。
+     *
+     * - purpose ガード: metadata.purpose=ticket_purchase かつ mode=payment 以外は受理のみ
+     *   (サブスク checkout / 他 purpose を failed にしない)
+     * - 真実源は自 DB 行 (ticket_checkout_sessions)。payload の customer / metadata は照合のみ
+     *   (tenant キー不信)。行不在・照合不一致・未決済・金額不一致は例外 throw =
+     *   retryable failure (既存 handle() の catch で failed + Stripe 再送。恒久不整合は
+     *   attempts 上限の terminal-ack + failure_reason で運用調査へ)
+     * - 付与は TicketLedgerService::grantPurchased (idempotency_key purchase:{sessionId}
+     *   UNIQUE) で冪等。event_id 違い再送でも二重付与しない
+     *
+     * @param  array<mixed>  $payload
+     */
+    private function grantPurchasedTickets(array $payload): void
+    {
+        // (1) purpose ガード: ticket_purchase 以外 (サブスク checkout / 他 purpose / mode≠payment) は受理のみ
+        if ($this->stringAt($payload, 'data.object.metadata.purpose') !== 'ticket_purchase') {
+            return;
+        }
+        if ($this->stringAt($payload, 'data.object.mode') !== 'payment') {
+            return;
+        }
+
+        $sessionId = $this->stringAt($payload, 'data.object.id');
+        if ($sessionId === null) {
+            throw new RuntimeException('checkout.session.completed: session id 欠落 (ticket_purchase)');
+        }
+
+        // (2) 真実源は自 DB 行。行不在は retryable failure (crash 先着 webhook は同一 attempt の
+        //     再試行で DB 行が記録された後、Stripe の event 再送で本経路に収束する)
+        $session = TicketCheckoutSession::query()->where('stripe_session_id', $sessionId)->first();
+        if ($session === null) {
+            throw new RuntimeException("ticket purchase webhook: 未追跡 session {$sessionId} (DB 行なし、再送待ち)");
+        }
+
+        // (3) tenant キー不信: payload の customer / metadata.org_ref は照合のみ。不一致は throw (fail-closed)
+        $organization = $session->organization;
+        Assert::isInstanceOf($organization, Organization::class);
+        $customerId = $this->stringAt($payload, 'data.object.customer');
+        if ($customerId === null || $organization->stripe_id !== $customerId) {
+            throw new RuntimeException("ticket purchase webhook: customer 照合不一致 (session {$sessionId})");
+        }
+        // org_ref は照合専用 (認可・org 解決には使わない。真実源は DB 行 → organization relation)
+        $metaOrgRef = $this->stringAt($payload, 'data.object.metadata.org_ref');
+        if ($metaOrgRef !== (string) $organization->id) {
+            throw new RuntimeException("ticket purchase webhook: metadata org_ref 照合不一致 (session {$sessionId})");
+        }
+
+        // (4) payment_status=paid 必須 (card 固定下の防御線。未決済 completed を付与しない)
+        if ($this->stringAt($payload, 'data.object.payment_status') !== 'paid') {
+            throw new RuntimeException("ticket purchase webhook: payment_status が paid でない (session {$sessionId})");
+        }
+
+        // (5) 金額照合: amount_subtotal === count × pin 単価、currency === pin (欠落・不一致は throw)。
+        //     amount_total は税・割引の運用設定ドリフトで壊れるため使わない
+        //     (作成側でも promo / automatic tax を使わない構成に固定 = 二重防御)
+        $amountSubtotal = data_get($payload, 'data.object.amount_subtotal');
+        $currency = $this->stringAt($payload, 'data.object.currency');
+        if (! is_int($amountSubtotal)
+            || $amountSubtotal !== $session->ticket_count * $session->unit_amount
+            || $currency !== $session->currency) {
+            throw new RuntimeException("ticket purchase webhook: 金額/通貨照合不一致 (session {$sessionId})");
+        }
+
+        // (6) 冪等付与 (idempotency_key purchase:{sessionId} UNIQUE) + 行 completed 化 (同一 TX)
+        $paymentIntentId = $this->stringAt($payload, 'data.object.payment_intent');
+        DB::transaction(function () use ($organization, $session, $amountSubtotal, $paymentIntentId): void {
+            $this->tickets->grantPurchased(
+                $organization,
+                $session->ticket_count,
+                $session->stripe_session_id,
+                $paymentIntentId,
+                $amountSubtotal, // 返金按分の分母 (clawback が使う)
+            );
+            if ($session->status !== TicketCheckoutSessionStatus::Completed) {
+                $session->status = TicketCheckoutSessionStatus::Completed;
+                $session->completed_at = CarbonImmutable::now();
+                $session->save();
+            }
+        });
+    }
+
     /**
      * charge.refunded: 買い切りチケットを累積返金額に応じて逆仕訳 (clawback) する。
      * payment_intent が無い charge (手動 charge 等) は対象外。
diff --git a/app/Services/Billing/TicketCheckoutGateway.php b/app/Services/Billing/TicketCheckoutGateway.php
new file mode 100644
index 0000000..b2b1e1a
--- /dev/null
+++ b/app/Services/Billing/TicketCheckoutGateway.php
@@ -0,0 +1,38 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Services\Billing;
+
+use App\DataTransferObjects\Billing\CreatedCheckoutSession;
+use App\Models\Organization;
+
+/**
+ * チケットスポット購入の Stripe Checkout 抽象 (実装: CashierTicketCheckoutGateway。
+ * テストは fake を bind する)。Stripe 呼び出しを本 interface に閉じる。
+ */
+interface TicketCheckoutGateway
+{
+    /**
+     * one-time Checkout Session を作る (mode=payment / card のみ / promo・tax なし)。
+     * $idempotencyKey により同一 attempt の再送は Stripe 側で同一 session に収束する。
+     *
+     * @param  array<string, string>  $metadata  照合専用 (認可・org 解決には使わない)
+     */
+    public function createTicketCheckout(
+        Organization $organization,
+        string $stripePriceId,
+        int $quantity,
+        string $successUrl,
+        string $cancelUrl,
+        string $idempotencyKey,
+        array $metadata,
+    ): CreatedCheckoutSession;
+
+    /**
+     * Stripe 側 session を expire する。
+     *
+     * @return string expire 後の session status ('expired'|'complete'|...)
+     */
+    public function expireCheckoutSession(string $sessionId): string;
+}
diff --git a/app/Services/Billing/TicketCheckoutService.php b/app/Services/Billing/TicketCheckoutService.php
new file mode 100644
index 0000000..558421c
--- /dev/null
+++ b/app/Services/Billing/TicketCheckoutService.php
@@ -0,0 +1,185 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Services\Billing;
+
+use App\DataTransferObjects\Billing\TicketCheckoutRedirect;
+use App\DataTransferObjects\Billing\TicketVolumeTier;
+use App\Enums\Billing\TicketCheckoutSessionStatus;
+use App\Exceptions\Billing\CheckoutInProgressException;
+use App\Exceptions\Billing\StaleCheckoutAttemptException;
+use App\Models\Billing\TicketCheckoutSession;
+use App\Models\Billing\TicketVolumePrice;
+use App\Models\Organization;
+use App\Models\User;
+use Carbon\CarbonImmutable;
+use Illuminate\Contracts\Cache\LockTimeoutException;
+use Illuminate\Database\UniqueConstraintViolationException;
+use Illuminate\Support\Facades\Cache;
+use Webmozart\Assert\Assert;
+
+/**
+ * チケットスポット購入の冪等 Checkout 開始 (二重課金防止の冪等マシン)。
+ *
+ * 防御 4 層 (概念設計 §C):
+ * 1. attempt_token 冪等 (UNIQUE(org, attempt_token) + Stripe idempotency key purchase:{token})
+ * 2. live pending dedup (同 (org, user) の決済待ち session を 1 本に収束)
+ * 3. INSERT unique 違反の re-read 収束 (並行 race を 500 にしない)
+ * 4. webhook 冪等 + 台帳 idempotency_key UNIQUE (StripeWebhookProcessor / TicketLedgerService)
+ *
+ * crash 復旧特性: Stripe 作成成功後・DB 保存前に落ちても、同一 attempt の再試行は
+ * Stripe idempotency key で同一 session に収束し、その時点で DB 行が記録される。
+ * DB 行が引けない completed webhook は付与しない (fail-closed) ため、
+ * 未追跡 session が黙って付与されることはない。
+ */
+class TicketCheckoutService
+{
+    /** org 単位直列化ロックの保持秒数 (Stripe 呼び出し込みの上限) */
+    private const int LOCK_SECONDS = 10;
+
+    /** ロック取得の待機秒数 (超過は fail-closed でエラー着地) */
+    private const int LOCK_WAIT_SECONDS = 5;
+
+    public function __construct(private readonly TicketCheckoutGateway $gateway) {}
+
+    /**
+     * 冪等 checkout 開始。
+     *
+     * 業務エラーは CheckoutInProgressException / StaleCheckoutAttemptException /
+     * TicketVolumeTierUnavailableException → controller が back()->with('error') に変換する。
+     */
+    public function startCheckout(Organization $organization, User $user, int $count, string $attemptToken): TicketCheckoutRedirect
+    {
+        // tier 解決はサーバが権威 (fail-closed / floor 強制 / production 未 sync 拒否)
+        $tier = TicketVolumePrice::currentTierFor($count);
+
+        try {
+            $redirect = Cache::lock("billing:ticket-checkout:{$organization->id}", self::LOCK_SECONDS)
+                ->block(
+                    self::LOCK_WAIT_SECONDS,
+                    fn (): TicketCheckoutRedirect => $this->startCheckoutLocked($organization, $user, $count, $tier, $attemptToken),
+                );
+            Assert::isInstanceOf($redirect, TicketCheckoutRedirect::class); // block() の返り値は callback の返り値 (mixed 宣言のため絞り込む)
+
+            return $redirect;
+        } catch (LockTimeoutException $e) {
+            // fail-closed: ロックなし実行へフォールバックしない
+            throw new CheckoutInProgressException('直前の購入手続きが進行中です。数秒おいて再度お試しください。', previous: $e);
+        }
+    }
+
+    private function startCheckoutLocked(
+        Organization $organization,
+        User $user,
+        int $count,
+        TicketVolumeTier $tier,
+        string $attemptToken,
+    ): TicketCheckoutRedirect {
+        $now = CarbonImmutable::now();
+
+        // (0) 期限切れ pending の回収: dedup の前に expired へ遷移
+        //     (Stripe 側 24h expire 済みの死 URL を永続 replay しない。pin 値で決定的 = Stripe 照会不要)
+        TicketCheckoutSession::query()
+            ->where('organization_id', $organization->id)
+            ->where('status', TicketCheckoutSessionStatus::Pending)
+            ->where('expires_at', '<=', $now)
+            ->update(['status' => TicketCheckoutSessionStatus::Expired->value]);
+
+        // (1) 同一 attempt_token: 同 count live pending → replay / completed → 受付済み / それ以外 → stale
+        $sameAttempt = TicketCheckoutSession::query()
+            ->where('organization_id', $organization->id)
+            ->where('attempt_token', $attemptToken)
+            ->first();
+        if ($sameAttempt !== null) {
+            if ($sameAttempt->status === TicketCheckoutSessionStatus::Completed) {
+                return new TicketCheckoutRedirect(url: null, alreadyCompleted: true);
+            }
+            if ($sameAttempt->isLivePending($now) && $sameAttempt->ticket_count === $count) {
+                return new TicketCheckoutRedirect(url: $sameAttempt->checkout_url, alreadyCompleted: false);
+            }
+
+            throw new StaleCheckoutAttemptException('購入手続きの有効期限が切れました。画面を再読み込みして再度お試しください。');
+        }
+
+        // (2) live pending dedup (org, user): 同 count → replay / 別 count → Stripe expire 成功時のみ
+        //     expired 化して続行 (別タブ・新 token でも live session は 1 本)
+        $livePending = TicketCheckoutSession::query()
+            ->where('organization_id', $organization->id)
+            ->where('initiated_by_user_id', $user->id)
+            ->where('status', TicketCheckoutSessionStatus::Pending)
+            ->where('expires_at', '>', $now)
+            ->latest('id')
+            ->first();
+        if ($livePending !== null) {
+            if ($livePending->ticket_count === $count) {
+                return new TicketCheckoutRedirect(url: $livePending->checkout_url, alreadyCompleted: false);
+            }
+            // expire 失敗 (gateway throw) は新規作成せずエラー着地 (二重 live session を作らない)
+            $status = $this->gateway->expireCheckoutSession($livePending->stripe_session_id);
+            if ($status === 'complete') {
+                throw new CheckoutInProgressException('直前の購入が処理中です。数秒おいて再度お試しください。');
+            }
+            $livePending->status = TicketCheckoutSessionStatus::Expired;
+            $livePending->save();
+        }
+
+        // (3) Stripe 作成 (idempotency key = purchase:{attemptToken}) → DB 記録。
+        //     metadata は照合専用 (認可・org 解決の判断には一切使わない。真実源は ticket_checkout_sessions 行)。
+        //     tenant キー不信の誤読を防ぐため organization_id ではなく非権限キー名 org_ref を使う。
+        $created = $this->gateway->createTicketCheckout(
+            $organization,
+            $tier->stripePriceId,
+            $count,
+            route('billing.tickets.show', ['purchased' => 1]),
+            route('billing.tickets.show'),
+            'purchase:'.$attemptToken,
+            [
+                'purpose' => 'ticket_purchase',
+                'org_ref' => (string) $organization->id,
+                'count' => (string) $count,
+            ],
+        );
+
+        try {
+            $session = new TicketCheckoutSession;
+            $session->organization()->associate($organization);
+            $session->initiatedBy()->associate($user);
+            $session->ticket_count = $count;
+            $session->unit_amount = $tier->unitAmount;
+            $session->currency = $tier->currency; // tier snapshot の currency pin (webhook 照合の出典)
+            $session->stripe_session_id = $created->sessionId;
+            $session->attempt_token = $attemptToken;
+            $session->checkout_url = $created->url;
+            $session->status = TicketCheckoutSessionStatus::Pending;
+            $session->expires_at = $created->expiresAt;
+            $session->save();
+        } catch (UniqueConstraintViolationException) {
+            // 並行 race / Stripe idempotency replay: 既存行 re-read で replay / stale に収束 (500 にしない)。
+            // orWhere は使わず 2 段の確定クエリで引く (括弧化漏れによる cross-org 混線を構造的に防ぐ):
+            //  (1) UNIQUE(org, attempt_token) スコープ → 高々 1 行
+            //  (2) global UNIQUE(stripe_session_id) → 引けても自 org 行でなければ replay しない
+            $existing = TicketCheckoutSession::query()
+                ->where('organization_id', $organization->id)
+                ->where('attempt_token', $attemptToken)
+                ->first();
+            if ($existing === null) {
+                $existing = TicketCheckoutSession::query()
+                    ->where('stripe_session_id', $created->sessionId)
+                    ->first();
+                if ($existing !== null && $existing->organization_id !== $organization->id) {
+                    $existing = null; // 自 org 行以外は絶対に replay しない (fail-closed)
+                }
+            }
+            if ($existing !== null
+                && $existing->isLivePending(CarbonImmutable::now())
+                && $existing->ticket_count === $count) {
+                return new TicketCheckoutRedirect(url: $existing->checkout_url, alreadyCompleted: false);
+            }
+
+            throw new StaleCheckoutAttemptException('購入手続きをやり直してください。画面を再読み込みして再度お試しください。');
+        }
+
+        return new TicketCheckoutRedirect(url: $created->url, alreadyCompleted: false);
+    }
+}
diff --git a/app/Services/Billing/TicketPricingService.php b/app/Services/Billing/TicketPricingService.php
new file mode 100644
index 0000000..7381667
--- /dev/null
+++ b/app/Services/Billing/TicketPricingService.php
@@ -0,0 +1,79 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Services\Billing;
+
+use App\DataTransferObjects\Billing\PurchaseTierDto;
+use App\Models\Billing\TicketVolumePrice;
+use Webmozart\Assert\Assert;
+
+/**
+ * チケット価格の表示専用の読み取り口 (消費 = TicketLedgerService / 購入 =
+ * TicketCheckoutService の経路とは独立)。
+ *
+ * tier の権威解決は TicketVolumePrice::currentTierFor に一元化されており、
+ * 本 Service は料金表・購入画面が表示する値だけを供給する。
+ */
+final class TicketPricingService
+{
+    /**
+     * current 全段を min_count 昇順で返す (min_count=1 の行が spot を兼ねる)。
+     * 各段の単価が floor (config billing.ticket_unit_price_floor) を下回る
+     * 設定異常は Assert で fail-fast する (原価割れ表示を出さない)。
+     *
+     * @return list<PurchaseTierDto>
+     */
+    public function volumeTiersForDisplay(): array
+    {
+        $floor = config('billing.ticket_unit_price_floor');
+        Assert::integer($floor, 'config billing.ticket_unit_price_floor は整数で設定してください');
+
+        return array_values(TicketVolumePrice::query()
+            ->where('is_current', true)
+            ->orderBy('min_count')
+            ->get()
+            ->map(function (TicketVolumePrice $row) use ($floor): PurchaseTierDto {
+                Assert::greaterThanEq(
+                    $row->unit_amount,
+                    $floor,
+                    "チケット単価 {$row->unit_amount} が floor {$floor} を下回っています (lookup_key={$row->lookup_key})",
+                );
+
+                return new PurchaseTierDto($row->min_count, $row->unit_amount);
+            })
+            ->all());
+    }
+
+    /**
+     * spot 単価 (min_count=1 の current 行)。無ければ
+     * TicketVolumeTierUnavailableException (fail-closed。currentTierFor に委譲し二重実装しない)。
+     */
+    public function spotUnitAmount(): int
+    {
+        return TicketVolumePrice::currentTierFor(1)->unitAmount;
+    }
+
+    /**
+     * 初回 signup grant の枚数 (config billing.signup_grant_tickets)。
+     * TicketLedgerService::grantSignupGrant と同じ config key を読む表示用の口。
+     */
+    public function signupGrantTickets(): int
+    {
+        $count = config('billing.signup_grant_tickets');
+        Assert::integer($count, 'config billing.signup_grant_tickets は整数で設定してください');
+        Assert::greaterThan($count, 0, 'signup_grant_tickets は 1 以上で設定してください');
+
+        return $count;
+    }
+
+    /** 初回 signup grant の有効期限 (日) (config billing.signup_grant_expiry_days)。 */
+    public function signupGrantExpiryDays(): int
+    {
+        $days = config('billing.signup_grant_expiry_days');
+        Assert::integer($days, 'config billing.signup_grant_expiry_days は整数で設定してください');
+        Assert::greaterThan($days, 0, 'signup_grant_expiry_days は 1 以上で設定してください');
+
+        return $days;
+    }
+}
diff --git a/app/Services/Marketing/ContactUrl.php b/app/Services/Marketing/ContactUrl.php
index 4e6e414..689dd07 100644
--- a/app/Services/Marketing/ContactUrl.php
+++ b/app/Services/Marketing/ContactUrl.php
@@ -4,6 +4,7 @@
 
 namespace App\Services\Marketing;
 
+use App\Enums\Inquiry\InquirySource;
 use Illuminate\Support\Facades\Log;
 
 /**
@@ -40,6 +41,34 @@ public function resolve(): string
         return $configured;
     }
 
+    /**
+     * source attribution 付きの宛先を解決する。
+     *
+     * 内部 path のときのみ `source={value}` を安全に付与する (既存 query の有無で
+     * `?`/`&` を使い分け、`#fragment` があれば fragment 直前に挿入する)。
+     * 外部 URL / mailto は先方フォームの query 契約が不明なため付与しない
+     * (resolve() と同値を返す)。
+     */
+    public function resolveForSource(InquirySource $source): string
+    {
+        $url = $this->resolve();
+
+        if ($this->kind() !== ContactDestinationKind::Internal) {
+            return $url;
+        }
+
+        $fragment = '';
+        $hashPos = strpos($url, '#');
+        if ($hashPos !== false) {
+            $fragment = substr($url, $hashPos);
+            $url = substr($url, 0, $hashPos);
+        }
+
+        $separator = str_contains($url, '?') ? '&' : '?';
+
+        return $url.$separator.'source='.$source->value.$fragment;
+    }
+
     /**
      * 宛先として許可する形式か (内部 path / http(s) / mailto のみ)。
      */
diff --git a/app/Services/Marketing/PricingService.php b/app/Services/Marketing/PricingService.php
new file mode 100644
index 0000000..dd13bee
--- /dev/null
+++ b/app/Services/Marketing/PricingService.php
@@ -0,0 +1,84 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Services\Marketing;
+
+use App\DataTransferObjects\Marketing\PricingPlanDto;
+use App\Enums\Billing\PlanPriceKind;
+use App\Models\Billing\Plan;
+use Webmozart\Assert\Assert;
+
+/**
+ * 料金表 (/pricing) 表示用のプラン一覧を構築する。
+ *
+ * - プラン台帳は Plan + PlanPrice (DB snapshot) が真実源
+ * - 能力値は config/quota.php の limits の「値」だけを読む
+ *   (コードにプラン名分岐を書かない規約どおり。limits に無い key は無制限 = null)
+ *
+ * 注意: デフォルト解決 (非 singleton) 前提。メモ化はリクエスト内キャッシュとして
+ * 設計しているため singleton 登録するとリクエスト間で価格変更が反映されなくなる。
+ */
+final class PricingService
+{
+    /** @var list<PricingPlanDto>|null リクエスト内メモ化 */
+    private ?array $memoizedPlans = null;
+
+    /**
+     * 公開プラン一覧 (sort_order 昇順)。価格は plan_prices current (kind=base)。
+     *
+     * @return list<PricingPlanDto>
+     */
+    public function listPublicPlans(): array
+    {
+        if ($this->memoizedPlans !== null) {
+            return $this->memoizedPlans;
+        }
+
+        $quotaPlans = config('quota.plans');
+        Assert::isArray($quotaPlans);
+
+        return $this->memoizedPlans = array_values(Plan::query()->orderBy('sort_order')->get()
+            ->map(function (Plan $plan) use ($quotaPlans): PricingPlanDto {
+                $limits = $quotaPlans[$plan->code] ?? [];
+                Assert::isArray($limits);
+                $price = $plan->currentPrice(PlanPriceKind::Base);
+
+                return new PricingPlanDto(
+                    code: $plan->code,
+                    name: $plan->name,
+                    baseAmountJpy: $price?->amount,
+                    monthlyTicketGrant: $plan->monthly_ticket_grant,
+                    maxProjects: self::intOrNull($limits, 'max_projects'),
+                    maxMembers: self::intOrNull($limits, 'max_members'),
+                    maxStorageGb: self::storageGb($limits),
+                );
+            })
+            ->all());
+    }
+
+    /**
+     * limits から int 値を安全に取り出す (無い key = 無制限 = null)。
+     *
+     * @param  array<mixed>  $limits
+     */
+    private static function intOrNull(array $limits, string $key): ?int
+    {
+        $value = $limits[$key] ?? null;
+
+        return is_int($value) ? $value : null;
+    }
+
+    /**
+     * max_storage_bytes を GiB 換算の表示値へ変換する (intdiv 切り捨て。
+     * free: 1GiB→1 / standard: 50GiB→50。Feature テストと Vitest の期待値もこの規則に固定)。
+     *
+     * @param  array<mixed>  $limits
+     */
+    private static function storageGb(array $limits): ?int
+    {
+        $bytes = self::intOrNull($limits, 'max_storage_bytes');
+
+        return $bytes === null ? null : intdiv($bytes, 1024 ** 3);
+    }
+}
diff --git a/app/Support/Security/MassAssignmentProtectedKeys.php b/app/Support/Security/MassAssignmentProtectedKeys.php
index 8cac260..6d6b838 100644
--- a/app/Support/Security/MassAssignmentProtectedKeys.php
+++ b/app/Support/Security/MassAssignmentProtectedKeys.php
@@ -44,6 +44,7 @@ public static function all(): array
             'adopted_take_id',
             // billing (Service / Seeder がサーバ側で導出する)
             'plan_id',
+            'initiated_by_user_id', // ticket_checkout_sessions の actor キー (T007)
             'reservation_id',
             'ticket_reservation_id', // AI-CUE: analysis_jobs の予約冪等キー (doc/10 §10.1)
             // secret (サーバ生成値)
diff --git a/config/seo.php b/config/seo.php
index ab184f6..984c82b 100644
--- a/config/seo.php
+++ b/config/seo.php
@@ -52,15 +52,13 @@
     | 上記いずれにも属さない route (認証配下のアプリ画面等) は noindex + title のみ描画される。
     */
     'route_classification' => [
-        'full' => ['home'],
-        'minimal' => ['pricing'],
+        'full' => ['home', 'pricing'],
+        'minimal' => [],
         'excluded' => ['seo.robots', 'seo.sitemap', 'seo.llms', 'seo.ai'],
     ],
 
     // minimal 分類のページ固有 title (route name => 固有名)。
-    'minimal_titles' => [
-        'pricing' => '料金プラン',
-    ],
+    'minimal_titles' => [],
 
     /*
     | sitemap.xml に載せる公開 HTML ページ (route name => changefreq/priority)。
@@ -102,6 +100,7 @@
         'invitations.accept' => '組織への招待',
         // 課金
         'billing.index' => 'プランとお支払い',
+        'billing.tickets.show' => 'チケットを購入',
         // プロジェクト (show は controller が setPrivateTitle でプロジェクト名を供給)
         'projects.index' => 'プロジェクト',
         'projects.create' => 'プロジェクトの作成',
diff --git a/database/factories/Billing/TicketCheckoutSessionFactory.php b/database/factories/Billing/TicketCheckoutSessionFactory.php
new file mode 100644
index 0000000..6446b51
--- /dev/null
+++ b/database/factories/Billing/TicketCheckoutSessionFactory.php
@@ -0,0 +1,82 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Database\Factories\Billing;
+
+use App\Enums\Billing\TicketCheckoutSessionStatus;
+use App\Models\Billing\TicketCheckoutSession;
+use App\Models\Organization;
+use App\Models\User;
+use Carbon\CarbonImmutable;
+use Illuminate\Database\Eloquent\Factories\Factory;
+use Illuminate\Support\Str;
+
+/**
+ * 既定は live pending (決済待ち・expires_at 未来) の追跡行。
+ * completed() / expired() / stale() state で状態遷移後の行を作る。
+ *
+ * @extends Factory<TicketCheckoutSession>
+ */
+class TicketCheckoutSessionFactory extends Factory
+{
+    protected $model = TicketCheckoutSession::class;
+
+    /**
+     * @return array<string, mixed>
+     */
+    public function definition(): array
+    {
+        $sessionId = 'cs_test_'.Str::random(24);
+
+        return [
+            'organization_id' => Organization::factory(),
+            'initiated_by_user_id' => User::factory(),
+            'ticket_count' => 10,
+            'unit_amount' => 100,
+            'currency' => 'jpy',
+            'stripe_session_id' => $sessionId,
+            'attempt_token' => (string) Str::ulid(),
+            'checkout_url' => "https://checkout.stripe.test/c/pay/{$sessionId}",
+            'status' => TicketCheckoutSessionStatus::Pending,
+            'expires_at' => CarbonImmutable::now()->addDay(),
+            'completed_at' => null,
+        ];
+    }
+
+    public function forOrganization(Organization $organization): static
+    {
+        return $this->state(fn (): array => ['organization_id' => $organization->getKey()]);
+    }
+
+    public function initiatedBy(User $user): static
+    {
+        return $this->state(fn (): array => ['initiated_by_user_id' => $user->getKey()]);
+    }
+
+    /** 付与済み (completed) の行。 */
+    public function completed(): static
+    {
+        return $this->state(fn (): array => [
+            'status' => TicketCheckoutSessionStatus::Completed,
+            'completed_at' => CarbonImmutable::now(),
+        ]);
+    }
+
+    /** 明示 expire 済みの行。 */
+    public function expired(): static
+    {
+        return $this->state(fn (): array => [
+            'status' => TicketCheckoutSessionStatus::Expired,
+        ]);
+    }
+
+    /** 期限切れ pending (status は pending のまま expires_at が過去) の行。 */
+    public function stale(): static
+    {
+        return $this->state(fn (): array => [
+            'status' => TicketCheckoutSessionStatus::Pending,
+            'expires_at' => CarbonImmutable::now()->subHour(),
+        ]);
+    }
+}
diff --git a/database/migrations/2026_07_11_200000_create_ticket_checkout_sessions_table.php b/database/migrations/2026_07_11_200000_create_ticket_checkout_sessions_table.php
new file mode 100644
index 0000000..e719490
--- /dev/null
+++ b/database/migrations/2026_07_11_200000_create_ticket_checkout_sessions_table.php
@@ -0,0 +1,45 @@
+<?php
+
+declare(strict_types=1);
+
+use Illuminate\Database\Migrations\Migration;
+use Illuminate\Database\Schema\Blueprint;
+use Illuminate\Support\Facades\Schema;
+
+/**
+ * チケットスポット購入の Stripe Checkout Session 追跡 (冪等マシンの真実源)。
+ *
+ * - attempt_token: 画面 render ごとに発行される ULID。UNIQUE(org, attempt_token) で
+ *   同一 attempt の再送を同一 session に収束させる
+ * - unit_amount / currency: 作成時単価の pin (webhook 金額照合の出典)
+ * - expires_at: Stripe session expires_at の pin (「live pending」判定の決定基準)
+ */
+return new class extends Migration
+{
+    public function up(): void
+    {
+        Schema::create('ticket_checkout_sessions', function (Blueprint $table): void {
+            $table->id();
+            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
+            // 監査行のため user 削除でも行は残す (null 化)
+            $table->foreignId('initiated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
+            $table->unsignedInteger('ticket_count');
+            $table->unsignedInteger('unit_amount');
+            $table->string('currency', 8);
+            $table->string('stripe_session_id')->unique();
+            $table->string('attempt_token', 26);
+            $table->string('checkout_url', 2048);
+            $table->string('status'); // pending / completed / expired (アプリ層 enum cast)
+            $table->timestamp('expires_at');
+            $table->timestamp('completed_at')->nullable();
+            $table->timestamps();
+            $table->unique(['organization_id', 'attempt_token']);
+            $table->index(['organization_id', 'initiated_by_user_id', 'status', 'expires_at']);
+        });
+    }
+
+    public function down(): void
+    {
+        Schema::dropIfExists('ticket_checkout_sessions');
+    }
+};
diff --git a/database/seeders/DatabaseSeeder.php b/database/seeders/DatabaseSeeder.php
index 9c696f4..848f868 100644
--- a/database/seeders/DatabaseSeeder.php
+++ b/database/seeders/DatabaseSeeder.php
@@ -11,12 +11,15 @@ class DatabaseSeeder extends Seeder
     public function run(): void
     {
         // call 順は依存関係のとおり固定: ロール定義 → permission 定義 → 紐付け →
-        // プラン → local 開発用 AdminUser (AdminUserSeeder 自身が local 以外で skip)
+        // プラン → チケット傾斜単価 → local 開発用 AdminUser (AdminUserSeeder 自身が local 以外で skip)。
+        // TicketVolumePriceSeeder はスポット購入 (tier 解決) と料金表表示の bootstrap に必要
+        // (seeder docblock が定める「傾斜単価を使う派生アプリが DatabaseSeeder へ追加する」正規オプトイン)
         $this->call([
             RoleSeeder::class,
             PermissionSeeder::class,
             RolePermissionSeeder::class,
             PlanSeeder::class,
+            TicketVolumePriceSeeder::class,
             AdminUserSeeder::class,
         ]);
     }
diff --git a/database/seeders/PlanSeeder.php b/database/seeders/PlanSeeder.php
index 66e2398..06ef321 100644
--- a/database/seeders/PlanSeeder.php
+++ b/database/seeders/PlanSeeder.php
@@ -30,7 +30,7 @@ class PlanSeeder extends Seeder
      * @var array<string, array<string, int>>
      */
     private const PRICE_AMOUNTS = [
-        'standard' => ['base' => 1980],
+        'standard' => ['base' => 4980],
     ];
 
     public function run(): void
diff --git a/docs/architecture.md b/docs/architecture.md
index 479a716..b9a5c5b 100644
--- a/docs/architecture.md
+++ b/docs/architecture.md
@@ -57,6 +57,7 @@ ## ドメインモデル (テンプレート同梱)
 | `Billing/StripeWebhookEvent` | Stripe webhook の冪等マシン | tenant 外 |
 | `Billing/TicketLedgerEntry` / `Billing/TicketReservation` | チケット台帳 (reserve→commit/release の 2 フェーズ。期限付き付与・idempotency_key 冪等付与・返金 clawback) | Organization 従属 |
 | `Billing/TicketVolumePrice` | スポット購入の数量逐減 (volume tier) 単価の Stripe Price snapshot | tenant 外 (マスタ) |
+| `Billing/TicketCheckoutSession` | チケットスポット購入の Stripe Checkout Session 追跡 (attempt_token 冪等 + 単価 pin = webhook 金額照合の出典。status: pending/completed/expired) | Organization 従属 |
 | `Billing/Subscription` | Cashier Subscription のテンプレート拡張 (current_period_end / Subscription Schedule の部分完了追跡列) | Organization 従属 |
 | `Billing/BillingNotification` | 請求通知の delivery record (通知台帳。(type, invoice_id) / (type, dedup_key) 複合 UNIQUE で send-once を構造保証) | Organization 従属 |
 
@@ -89,6 +90,10 @@ ## 主要 Service (テンプレート同梱)
 | `Billing/StripePriceCatalogClient` | Stripe Price Catalog への read-only adapter (`prices.list` の lookup_keys で現行 active Price を解決。価格カタログ as-code の sync/verify コマンドが利用) |
 | `Billing/PortalConfigurationSpec` | Customer Portal の許可機能ポリシー固定真実源 (subscription_update 無効化。`billing:ensure-portal-configuration` が生成/検証) |
 | `Billing/TicketLedgerService` | チケットの reserve/commit/release と冪等付与 (grantMonthly/grantSignupGrant/grantPurchased)・返金逆仕訳 (clawback) |
+| `Billing/TicketCheckoutService` | チケットスポット購入の冪等 Checkout 開始 (org 単位 Cache::lock 直列化 + attempt_token 冪等 + live pending dedup + INSERT unique 違反の re-read 収束。二重課金防止の冪等マシン) |
+| `Billing/TicketCheckoutGateway` (interface) + `Billing/CashierTicketCheckoutGateway` | Stripe one-time Checkout の抽象 (mode=payment / card のみ / promo・tax なし = amount_subtotal 照合の前提。idempotency key 対応。テストは fake を bind) |
+| `Billing/TicketPricingService` | チケット価格の表示専用読み取り口 (傾斜表 / spot 単価 / signup grant 表示値。消費・購入経路と独立) |
+| `Marketing/PricingService` | 料金表 (/pricing) のプラン一覧構築 (plan_prices current + config/quota.php limits の値のみ参照) |
 | `OAuth/OauthSessionListService` | OAuth セッション一覧 (CLI セッション + legacy MCP token の併記) |
 | `VersionInfoService` | `/api/v1/version` の capability negotiation payload (semver fail-fast + CLI client id 解決) |
 | `Mcp/McpIdempotencyService` | MCP 書き込み tool の冪等 replay/store (`mcp_idempotency_keys`) |
@@ -182,6 +187,31 @@ ### レンダジョブの運用契約
 - ローカル/テストの検証: パイプラインの同期実行は `RenderPipeline::run()` の直接呼び出し +
   fake `VideoComposer` (container swap)、dispatch の検証は `Queue::fake()`
 
+## チケットスポット購入 (T007) の運用契約
+
+- **経路**: `GET /purchase-tickets` (閲覧 = 組織メンバー) / `POST /purchase-tickets/checkout`
+  (`manageBilling` のみ)。課金ゲート (`require-active-subscription`) の対象外 = 未契約 /
+  free プラン組織でも購入できる。payload は `count` / `attempt_token` のみ
+  (金額・Price ID は `TicketVolumePrice::currentTierFor` がサーバ権威で解決)
+- **二重課金防止 4 層**: attempt_token 冪等 (UNIQUE(org, attempt_token) + Stripe idempotency key
+  `purchase:{token}`) → live pending dedup (同 org×user の決済待ち session を 1 本に収束) →
+  INSERT unique 違反の re-read 収束 → webhook 冪等 (claim + 台帳 idempotency_key
+  `purchase:{sessionId}` UNIQUE)
+- **webhook 付与 (checkout.session.completed)**: 真実源は `ticket_checkout_sessions` 行。
+  payload の customer / metadata.org_ref は照合のみ (不一致・行不在・payment_status≠paid・
+  amount_subtotal≠count×pin 単価・currency 不一致は例外 throw = retryable failure →
+  Stripe 再送で再処理)。作成側 payload は promo / automatic tax を含まない
+  (amount_subtotal 照合の前提。gateway invariant テストで固定)
+- **terminal failure の運用手順**: 付与系イベント (checkout.session.completed / invoice.paid) が
+  attempts 上限 (8) に到達すると terminal-ack + `report()` (運用アラート) される。
+  対応: `stripe_webhook_events.failure_reason` を参照し、Stripe ダッシュボードで決済状態を確認 →
+  決済済み・未付与が確定した場合のみ tinker 等で `TicketLedgerService::grantPurchased()` を
+  手動実行する (idempotency_key `purchase:{sessionId}` により再実行しても二重付与しない)。
+  併せて `ticket_checkout_sessions` 行を completed 化する
+- **放棄 session の回収**: Stripe Checkout 自体の有効期限 (既定 24h) で Stripe 側が expire し、
+  DB 行は checkout 開始時の期限切れ回収 (`status=pending AND expires_at <= now` → expired) で
+  局所回収する (専用 cron は作らない)
+
 ## 撮影 PWA (presigned アップロード + 容量 Quota) の運用契約
 
 doc/10 §10.3 / §10.8-4/-7 の実装 (T004)。routes は `/app/projects/{project}/...`
diff --git a/docs/factories.md b/docs/factories.md
index 5f342eb..3d22ed6 100644
--- a/docs/factories.md
+++ b/docs/factories.md
@@ -38,6 +38,7 @@ ## Factory 一覧 (テンプレート同梱)
 | `LlmCallLogFactory` | LlmCallLog | `withFxSnapshot(float $rate = 154.32)`, `failed(string $reason = ...)`, `metadataMissing()` |
 | `ModelAuditFactory` | ModelAudit | — (auditable は Item 既定。派生アプリは state で上書き) |
 | `Billing\BillingNotificationFactory` | Billing/BillingNotification | `forOrganization($org)`, `reminder(?string $dedupKey = null)` (dedup_key 経路), `sent()`, `failed()` |
+| `Billing\TicketCheckoutSessionFactory` | Billing/TicketCheckoutSession | `forOrganization($org)`, `initiatedBy($user)`, `completed()`, `expired()`, `stale()` (pending のまま expires_at 過去) |
 
 Factory を持たないモデル (Role / Permission / Team 等) は seed 固定値
 または Service (`OrganizationProvisioningService` 等) 経由で作る。
diff --git a/resources/js/components/features/manual/AnalysisPanel.svelte b/resources/js/components/features/manual/AnalysisPanel.svelte
index 57c11af..17cbd06 100644
--- a/resources/js/components/features/manual/AnalysisPanel.svelte
+++ b/resources/js/components/features/manual/AnalysisPanel.svelte
@@ -5,6 +5,8 @@
     import Button from "@/components/atoms/Button.svelte";
     import Card from "@/components/atoms/Card.svelte";
     import ConfirmDialog from "@/components/organisms/ConfirmDialog.svelte";
+    import TextLink from "@/components/atoms/TextLink.svelte";
+    import { isInsufficientTickets } from "@/components/features/manual/insufficient-tickets";
     import { csrfToken } from "@/lib/csrf";
     import type { AnalysisJobProps, VideoManualStatus } from "@/types/manual";
     import { ANALYSIS_STEP_LABELS } from "@/types/manual";
@@ -35,6 +37,8 @@
     let status = $state<VideoManualStatus>(manualStatus);
     let starting = $state(false);
     let errorMessage = $state<string | null>(null);
+    // 402 (残高不足) のとき購入導線を併記する (code 厳格一致。他エラーで誤表示しない)
+    let showPurchaseLink = $state(false);
     // セッション失効 (401/419) の案内。解析中表示の中で出す (ポーリングは停止する)
     let sessionExpiredMessage = $state<string | null>(null);
     let confirmingReanalyze = $state(false);
@@ -131,6 +135,7 @@
         if (starting) return; // 多重送信ガード (disabled にはしない)
         starting = true;
         errorMessage = null;
+        showPurchaseLink = false;
         sessionExpiredMessage = null;
         try {
             const res = await fetch(`/projects/${projectId}/manuals/${manualId}/analyze`, {
@@ -153,6 +158,7 @@
 
     async function handleStartResponse(res: Response): Promise<void> {
         const body = (await res.json().catch(() => null)) as unknown;
+        showPurchaseLink = res.status === 402 && isInsufficientTickets(body);
         if (res.status === 201 && body !== null && typeof body === "object") {
             const jobBody = body as AnalysisJobProps;
             currentJob = jobBody;
@@ -226,7 +232,16 @@
         {/if}
         {#if errorMessage}
             <div class="mt-4" data-testid="analysis-start-error">
-                <Alert type="danger">{errorMessage}</Alert>
+                <Alert type="danger">
+                    {errorMessage}
+                    {#if showPurchaseLink}
+                        <span class="ml-1">
+                            <TextLink href="/purchase-tickets" testId="analysis-purchase-link">
+                                チケットを購入する
+                            </TextLink>
+                        </span>
+                    {/if}
+                </Alert>
             </div>
         {/if}
         <p class="mt-2 text-body text-text-secondary">
diff --git a/resources/js/components/features/manual/RenderPanel.svelte b/resources/js/components/features/manual/RenderPanel.svelte
index 98b120f..021eea7 100644
--- a/resources/js/components/features/manual/RenderPanel.svelte
+++ b/resources/js/components/features/manual/RenderPanel.svelte
@@ -2,6 +2,8 @@
     import { router } from "@inertiajs/svelte";
     import { Clapperboard, Download, LoaderCircle, Play } from "@lucide/svelte";
     import Alert from "@/components/atoms/Alert.svelte";
+    import TextLink from "@/components/atoms/TextLink.svelte";
+    import { isInsufficientTickets } from "@/components/features/manual/insufficient-tickets";
     import Button from "@/components/atoms/Button.svelte";
     import Card from "@/components/atoms/Card.svelte";
     import ConfirmDialog from "@/components/organisms/ConfirmDialog.svelte";
@@ -42,6 +44,8 @@
     let status = $state<VideoManualStatus>(manualStatus);
     let starting = $state(false);
     let errorMessage = $state<string | null>(null);
+    // 402 (残高不足) のとき購入導線を併記する (code 厳格一致。他エラーで誤表示しない)
+    let showPurchaseLink = $state(false);
     let sessionExpiredMessage = $state<string | null>(null);
     let confirmingRender = $state(false);
 
@@ -158,6 +162,7 @@
         if (starting) return; // 多重送信ガード (disabled にはしない)
         starting = true;
         errorMessage = null;
+        showPurchaseLink = false;
         sessionExpiredMessage = null;
         try {
             const res = await fetch(`/projects/${projectId}/manuals/${manualId}/${kind}`, {
@@ -180,6 +185,7 @@
 
     async function handleStartResponse(kind: "render" | "preview", res: Response): Promise<void> {
         const body = (await res.json().catch(() => null)) as unknown;
+        showPurchaseLink = res.status === 402 && isInsufficientTickets(body);
         if (res.status === 201 && body !== null && typeof body === "object") {
             const jobBody = body as RenderJobProps;
             if (kind === "render") {
@@ -282,7 +288,16 @@
 
     {#if errorMessage}
         <div class="mt-4" data-testid="render-start-error">
-            <Alert type="danger">{errorMessage}</Alert>
+            <Alert type="danger">
+                {errorMessage}
+                {#if showPurchaseLink}
+                    <span class="ml-1">
+                        <TextLink href="/purchase-tickets" testId="render-purchase-link">
+                            チケットを購入する
+                        </TextLink>
+                    </span>
+                {/if}
+            </Alert>
         </div>
     {/if}
     {#if sessionExpiredMessage}
diff --git a/resources/js/components/features/manual/insufficient-tickets.ts b/resources/js/components/features/manual/insufficient-tickets.ts
new file mode 100644
index 0000000..754eb89
--- /dev/null
+++ b/resources/js/components/features/manual/insufficient-tickets.ts
@@ -0,0 +1,14 @@
+import type { InsufficientTicketsBody } from "@/types/manual";
+
+/**
+ * 402 応答 body がチケット残高不足 (InsufficientTicketsResource 契約) かの type guard。
+ * code 厳格一致で自分宛て応答のみ購入導線を出す (他エラーで誤表示しない)。
+ * AnalysisPanel / RenderPanel 共通 (features/manual 内に閉じる)。
+ */
+export function isInsufficientTickets(body: unknown): body is InsufficientTicketsBody {
+    return (
+        body !== null &&
+        typeof body === "object" &&
+        (body as { code?: unknown }).code === "insufficient_tickets"
+    );
+}
diff --git a/resources/js/components/molecules/PricingPlanCard.svelte b/resources/js/components/molecules/PricingPlanCard.svelte
new file mode 100644
index 0000000..c17ed96
--- /dev/null
+++ b/resources/js/components/molecules/PricingPlanCard.svelte
@@ -0,0 +1,86 @@
+<!--
+  PricingPlanCard — 料金プランカードの共通分子 (aigenba 移植)。
+
+  DTO 非依存 (primitive props)。feature 文言・CTA は呼び出し側が props / snippet で供給する。
+  AI-CUE の props 契約: priceAmount null は「plan_prices (base) を持たない = 無料プラン」
+  (PlanSeeder の台帳意味論。Billing/Index.svelte の formatPrice(null)=>「無料」と同じ表示契約)。
+  0 も防御的に同一表示。aigenba の contactLabel (null=お問い合わせ) 分岐は移植しない
+  (該当プランが存在しない機能を作らない。大規模利用の問い合わせはカード外バナーの責務)。
+-->
+<script lang="ts">
+    import type { Snippet } from "svelte";
+    import { Check } from "@lucide/svelte";
+    import type { PricingFeature } from "./PricingPlanCard.types";
+
+    interface Props {
+        name: string;
+        /** null = 基本料金なし = 無料表示 (0 も防御的に同一表示) */
+        priceAmount: number | null;
+        /** 価格サフィックス (既定 '／月') */
+        priceSuffix?: string;
+        /** 価格の直上に小さく載せる説明 (例: '基本料金')。表示価格が総額と誤解されるのを防ぐ。 */
+        priceCaption?: string;
+        /** 現在のプランなど強調枠 (border-primary) */
+        isHighlighted?: boolean;
+        features: PricingFeature[];
+        testId?: string;
+        /** card footer 下部 CTA 専用 */
+        footerCta: Snippet;
+    }
+
+    let {
+        name,
+        priceAmount,
+        priceSuffix = "／月",
+        priceCaption,
+        isHighlighted = false,
+        features,
+        testId,
+        footerCta,
+    }: Props = $props();
+
+    const formatYen = (v: number): string => new Intl.NumberFormat("ja-JP").format(v);
+    const borderClass = $derived(isHighlighted ? "border-primary" : "border-border");
+    const isFree = $derived(priceAmount === null || priceAmount === 0);
+</script>
+
+<div class="flex flex-col rounded-lg border bg-surface p-5 {borderClass}" data-testid={testId}>
+    <h3 class="text-h3 text-text">{name}</h3>
+    {#if priceCaption !== undefined && !isFree}
+        <!-- 表示価格が総額と誤解されるのを防ぐ (例: 基本料金)。 -->
+        <p class="mt-3 text-caption text-text-secondary" data-testid="price-caption">
+            {priceCaption}
+        </p>
+    {/if}
+    <p class="{priceCaption !== undefined && !isFree ? 'mt-0.5' : 'mt-3'} text-h2 text-text">
+        {#if isFree}
+            <!-- 無料プラン: ¥0 表記でなく「無料」を価格として掲示する -->
+            無料
+        {:else if priceAmount !== null}
+            ¥{formatYen(priceAmount)}
+            <span class="text-caption text-text-secondary">{priceSuffix}</span>
+        {/if}
+    </p>
+
+    <ul class="mt-4 flex-1 space-y-2 text-body text-text">
+        {#each features as feature, i (`${feature.variant ?? "default"}:${i}:${feature.text}`)}
+            {#if feature.variant === "warning"}
+                <li
+                    class="flex items-start gap-2 rounded-sm border border-warning bg-warning/10 p-2 text-text"
+                >
+                    <Check class="mt-0.5 size-4 shrink-0 text-warning" />
+                    {feature.text}
+                </li>
+            {:else}
+                <li class="flex items-start gap-2">
+                    <Check class="mt-0.5 size-4 shrink-0 text-success" />
+                    {feature.text}
+                </li>
+            {/if}
+        {/each}
+    </ul>
+
+    <div class="mt-5">
+        {@render footerCta()}
+    </div>
+</div>
diff --git a/resources/js/components/molecules/PricingPlanCard.types.ts b/resources/js/components/molecules/PricingPlanCard.types.ts
new file mode 100644
index 0000000..f740a5a
--- /dev/null
+++ b/resources/js/components/molecules/PricingPlanCard.types.ts
@@ -0,0 +1,8 @@
+/**
+ * PricingPlanCard の feature バレット 1 件。
+ * variant 'warning' は注意事項 (警告色バレット) として描画する。
+ */
+export interface PricingFeature {
+    text: string;
+    variant?: "warning";
+}
diff --git a/resources/js/pages/Billing/Index.svelte b/resources/js/pages/Billing/Index.svelte
index 97503fe..cd094ee 100644
--- a/resources/js/pages/Billing/Index.svelte
+++ b/resources/js/pages/Billing/Index.svelte
@@ -3,6 +3,7 @@
     import Badge from "@/components/atoms/Badge.svelte";
     import Button from "@/components/atoms/Button.svelte";
     import Card from "@/components/atoms/Card.svelte";
+    import TextLink from "@/components/atoms/TextLink.svelte";
     import AppLayout from "@/components/templates/AppLayout.svelte";
     import type { SharedProps } from "@/lib/shared-props";
 
@@ -102,6 +103,12 @@
                     <p class="mt-2 text-body" data-testid="ticket-balance">
                         {ticketBalance.toLocaleString("ja-JP")} 枚
                     </p>
+                    <!-- 遷移先が role-aware (非管理者には購入依頼の案内) のため権限に依らず表示 -->
+                    <p class="mt-1">
+                        <TextLink href="/purchase-tickets" testId="purchase-tickets-link">
+                            チケットを購入
+                        </TextLink>
+                    </p>
                 </div>
             </div>
             {#if canManageBilling}
diff --git a/resources/js/pages/Billing/PurchaseTickets.svelte b/resources/js/pages/Billing/PurchaseTickets.svelte
new file mode 100644
index 0000000..0e66ad8
--- /dev/null
+++ b/resources/js/pages/Billing/PurchaseTickets.svelte
@@ -0,0 +1,198 @@
+<script lang="ts">
+    import { page as inertiaPage, router } from "@inertiajs/svelte";
+    import { CircleCheck, ShoppingCart, Ticket } from "@lucide/svelte";
+    import Alert from "@/components/atoms/Alert.svelte";
+    import Button from "@/components/atoms/Button.svelte";
+    import Card from "@/components/atoms/Card.svelte";
+    import Input from "@/components/atoms/Input.svelte";
+    import FormField from "@/components/molecules/FormField.svelte";
+    import AppLayout from "@/components/templates/AppLayout.svelte";
+    import type { SharedProps } from "@/lib/shared-props";
+    import type { PurchaseTicketsPageProps } from "@/types/billing";
+
+    /**
+     * チケット購入画面 (current org スコープ)。
+     * - 枚数入力 → 傾斜表 (props 単一真実源) から適用単価・総額を即時表示
+     * - 購入 POST は count + attempt_token のみ (金額はサーバが権威)。成功時はサーバの
+     *   Inertia::location が Stripe Checkout へ full page redirect する
+     * - 送信ボタンは disabled にしない (不正値は押下時にエラー表示 + サーバ validation の二重防御)
+     * - canManage=false のメンバーには購入依頼の案内を表示 (残高・料金表は表示 = 透明性維持)
+     */
+    interface Props {
+        page: PurchaseTicketsPageProps;
+    }
+
+    let { page }: Props = $props();
+
+    const shared = $derived(inertiaPage.props as unknown as SharedProps);
+    const appName = $derived(shared.appName ?? "");
+    // flash (error/info) は AppLayout の flash-to-toast が表示する (インライン二重表示しない)
+    const serverErrors = $derived((inertiaPage.props.errors ?? {}) as Record<string, string>);
+
+    // props から一度だけ seed する (以後はユーザー入力が真実)
+    // svelte-ignore state_referenced_locally
+    let countText = $state<string | number>(String(page.defaultCount));
+    let submitting = $state(false);
+    let clientError = $state<string | null>(null);
+
+    // 生入力を整数として厳格に解釈する (clamp / floor の暗黙補正をしない)。
+    // input[type=number] の bind:value は number を返すことがあるため String 経由で正規化する
+    const parsedCount = $derived.by<number | null>(() => {
+        const trimmed = String(countText).trim();
+        if (!/^\d+$/.test(trimmed)) return null;
+        const n = Number(trimmed);
+        return Number.isSafeInteger(n) ? n : null;
+    });
+
+    const isValidCount = $derived(
+        parsedCount !== null && parsedCount >= page.minCount && parsedCount <= page.maxCount,
+    );
+
+    // 適用単価: tiers (minCount 昇順) から minCount <= count の最大段を選ぶ
+    const appliedUnit = $derived.by<number | null>(() => {
+        if (parsedCount === null) return null;
+        let unit: number | null = null;
+        for (const tier of page.tiers) {
+            if (parsedCount >= tier.minCount) unit = tier.unitAmount;
+        }
+        return unit;
+    });
+
+    // 合計は妥当時のみ金額表示 (範囲外は — 表示で誤認を防ぐ)
+    const totalAmount = $derived(
+        isValidCount && parsedCount !== null && appliedUnit !== null
+            ? parsedCount * appliedUnit
+            : null,
+    );
+
+    const formatYen = (v: number): string => new Intl.NumberFormat("ja-JP").format(v);
+
+    // 傾斜表の帯表示 (Pricing と同じ変換規則。表示都合が異なるため molecule 共有はしない)
+    const tierRows = $derived(
+        page.tiers.map((tier, i) => {
+            const next = page.tiers[i + 1];
+            return {
+                label: next ? `${tier.minCount}〜${next.minCount - 1} 枚` : `${tier.minCount} 枚以上`,
+                unitAmount: tier.unitAmount,
+            };
+        }),
+    );
+
+    function submit(): void {
+        if (submitting) return; // 多重送信ガード (disabled にはしない)
+        clientError = null;
+        if (!isValidCount || parsedCount === null) {
+            clientError = `購入枚数は ${page.minCount}〜${page.maxCount} の整数で入力してください`;
+            return;
+        }
+        router.post(
+            "/purchase-tickets/checkout",
+            { count: parsedCount, attempt_token: page.attemptToken },
+            {
+                onStart: () => {
+                    submitting = true;
+                },
+                onFinish: () => {
+                    submitting = false;
+                },
+            },
+        );
+    }
+</script>
+
+<AppLayout {appName}>
+    <h1 class="text-h2">チケットを購入</h1>
+    <p class="mt-1 text-caption text-text-secondary">
+        チケットは AI 解析・動画レンダに使います。まとめ買いで 1 枚あたりの料金が下がります。
+    </p>
+
+    <div class="mt-6 flex max-w-3xl flex-col gap-6">
+        {#if page.purchased}
+            <Alert type="success" title="ご購入ありがとうございます" testId="purchase-success-banner">
+                決済の確認後、残高に反映されます (通常数秒〜数分)。反映はページの再読み込みでご確認いただけます。
+            </Alert>
+        {/if}
+        <Card padding="lg" testId="purchase-balance">
+            <div class="flex items-center gap-3">
+                <Ticket class="size-5 text-primary" aria-hidden="true" />
+                <div>
+                    <h2 class="text-h3">現在の残高</h2>
+                    <p class="mt-1 text-body" data-testid="purchase-balance-count">
+                        {page.balance.toLocaleString("ja-JP")} 枚
+                    </p>
+                </div>
+            </div>
+        </Card>
+
+        {#if page.canManage}
+            <Card padding="lg" testId="purchase-form">
+                <h2 class="text-h3">購入枚数</h2>
+                <div class="mt-4 flex max-w-xs flex-col gap-2">
+                    <FormField
+                        label={`枚数 (${page.minCount}〜${page.maxCount})`}
+                        id="ticket-count"
+                        error={clientError ?? serverErrors.count ?? serverErrors.attempt_token ?? null}
+                    >
+                        {#snippet children({ id, describedBy, invalid })}
+                            <Input
+                                {id}
+                                type="number"
+                                bind:value={countText}
+                                error={invalid}
+                                aria-describedby={describedBy}
+                                min={page.minCount}
+                                max={page.maxCount}
+                                testId="ticket-count-input"
+                            />
+                        {/snippet}
+                    </FormField>
+                </div>
+
+                <p class="mt-4 text-body" data-testid="purchase-total">
+                    {#if totalAmount !== null && appliedUnit !== null}
+                        単価 ¥{formatYen(appliedUnit)} × {parsedCount} 枚 = 合計 ¥{formatYen(
+                            totalAmount,
+                        )}
+                    {:else}
+                        合計 —
+                    {/if}
+                </p>
+
+                <div class="mt-6">
+                    <Button onclick={submit} loading={submitting} testId="purchase-submit">
+                        <ShoppingCart class="size-4" aria-hidden="true" />
+                        購入手続きへ (Stripe)
+                    </Button>
+                </div>
+                <p class="mt-2 text-caption text-text-secondary">
+                    Stripe の決済画面に移動します。決済確認後にチケットが付与されます。
+                </p>
+            </Card>
+        {:else}
+            <Alert type="info" testId="purchase-role-note">
+                チケットの購入は組織のオーナーまたは管理者が行えます。管理者に購入を依頼してください。
+            </Alert>
+        {/if}
+
+        <section>
+            <h2 class="text-h3">チケット料金</h2>
+            {#if tierRows.length > 0}
+                <ul
+                    class="mt-3 rounded-lg border border-border bg-surface px-6 py-2 text-body"
+                    data-testid="purchase-tier-table"
+                >
+                    {#each tierRows as row (row.label)}
+                        <li class="flex justify-between border-b border-border py-2 last:border-b-0">
+                            <span class="text-text-secondary">{row.label}</span>
+                            <span class="text-text">¥{formatYen(row.unitAmount)} ／ 枚</span>
+                        </li>
+                    {/each}
+                </ul>
+            {/if}
+            <p class="mt-2 inline-flex items-center gap-1 text-caption text-text-secondary">
+                <CircleCheck class="size-3.5" aria-hidden="true" />
+                購入したチケットに有効期限はありません。
+            </p>
+        </section>
+    </div>
+</AppLayout>
diff --git a/resources/js/pages/Pricing.svelte b/resources/js/pages/Pricing.svelte
index f326a9b..81cd496 100644
--- a/resources/js/pages/Pricing.svelte
+++ b/resources/js/pages/Pricing.svelte
@@ -1,30 +1,230 @@
 <script lang="ts">
-    // 公開料金ページの雛形。アプリ初期化時に実プラン (Stripe price と結線) へ差し替える。
-    // 認証不要・SEO minimal 分類 (SeoComposer が「料金プラン | <site>」を供給)。
-    type Plan = { name: string; price: string; description: string };
-
-    const plans: Plan[] = [
-        { name: 'Free', price: '¥0', description: '個人利用ではじめる無料プラン（雛形）。' },
-        { name: 'Pro', price: '¥—', description: 'チームで使う有料プラン（雛形）。' },
-        { name: 'Enterprise', price: 'お問い合わせ', description: '大規模組織向けプラン（雛形）。' },
+    import { page as inertiaPage } from "@inertiajs/svelte";
+    import { ChevronDown, ExternalLink, Info, LayoutDashboard, Mail } from "@lucide/svelte";
+    import Button from "@/components/atoms/Button.svelte";
+    import PricingPlanCard from "@/components/molecules/PricingPlanCard.svelte";
+    import type { PricingFeature } from "@/components/molecules/PricingPlanCard.types";
+    import GuestLayout from "@/components/templates/GuestLayout.svelte";
+    import type { SharedProps } from "@/lib/shared-props";
+    import type { PricingPageProps, PricingPlanShape } from "@/types/marketing";
+
+    /**
+     * 公開料金表 (/pricing)。プラン基本料 (free / standard) + 共通チケット制の説明 +
+     * チケット傾斜料金表 + FAQ。title / description はサーバ SEO が正本のため
+     * svelte:head は付けない。
+     */
+    interface Props {
+        page: PricingPageProps;
+    }
+
+    let { page }: Props = $props();
+
+    const shared = $derived(inertiaPage.props as unknown as SharedProps);
+    const appName = $derived(shared.appName ?? "");
+
+    const formatYen = (v: number): string => new Intl.NumberFormat("ja-JP").format(v);
+    const formatLimit = (v: number | null): string => (v === null ? "無制限" : String(v));
+
+    const buildFeatures = (plan: PricingPlanShape): PricingFeature[] => [
+        { text: `月 ${plan.monthlyTicketGrant} 枚のチケット付与` },
+        { text: `プロジェクト ${formatLimit(plan.maxProjects)}` },
+        { text: `メンバー ${formatLimit(plan.maxMembers)} 名` },
+        {
+            text:
+                plan.maxStorageGb === null
+                    ? "ストレージ 無制限"
+                    : `ストレージ ${plan.maxStorageGb} GB`,
+        },
     ];
+
+    // チケット料金表: 傾斜段を「X〜Y 枚」の帯へ変換する (最終段は「X 枚以上」)。
+    const tierRows = $derived(
+        page.ticketTiers.map((tier, i) => {
+            const next = page.ticketTiers[i + 1];
+            return {
+                label: next ? `${tier.minCount}〜${next.minCount - 1} 枚` : `${tier.minCount} 枚以上`,
+                unitAmount: tier.unitAmount,
+            };
+        }),
+    );
+
+    const faqs = $derived([
+        {
+            q: "無料で試せますか？",
+            a: `はい。Free プランは基本料金なしでご利用いただけます。さらに新規契約でチケット ${page.signupGrantTickets} 枚 (${page.signupGrantExpiryDays} 日間有効) が無料でついてくるので、AI 解析から動画の完成までを実際にお試しいただけます。`,
+        },
+        {
+            q: "チケットは何に使いますか？",
+            a: `AI によるシナリオ解析に ${page.analysisTicketCost} 枚、完成動画のレンダリングに ${page.renderTicketCost} 枚を使います。プレビュー生成は無料です。`,
+        },
+        {
+            q: "追加チケットはどのように購入できますか？",
+            a: `1 枚あたり ${page.spotUnitAmountJpy} 円から、組織のオーナー・管理者が必要な分だけ購入できます。まとめ買いの割引単価はチケット料金表をご覧ください。`,
+        },
+        {
+            q: "解約・プラン変更はできますか？",
+            a: "いつでも可能です。プラン変更・解約は請求ダッシュボード (Stripe) から行えます。変更は次回更新のタイミングで反映されます。",
+        },
+    ]);
+
+    let openFaqIndex = $state<number | null>(null);
+    const toggleFaq = (i: number): void => {
+        openFaqIndex = openFaqIndex === i ? null : i;
+    };
 </script>
 
-<main class="min-h-screen bg-neutral px-6 py-16 text-text">
-    <div class="mx-auto max-w-4xl text-center">
-        <h1 class="text-h2">料金プラン</h1>
-        <p class="mt-2 text-caption text-text-secondary">
-            プレースホルダの料金雛形です。アプリ初期化時に実プランへ差し替えてください。
-        </p>
-
-        <div class="mt-10 grid gap-6 sm:grid-cols-3">
-            {#each plans as plan (plan.name)}
-                <section class="rounded-lg border border-border p-6 text-left">
-                    <h2 class="text-h3">{plan.name}</h2>
-                    <p class="mt-1 text-h2">{plan.price}</p>
-                    <p class="mt-2 text-caption text-text-secondary">{plan.description}</p>
-                </section>
+<GuestLayout {appName}>
+    {#snippet nav()}
+        {#if page.isAuthenticated}
+            <a href="/dashboard" class="text-text-secondary hover:text-primary">ダッシュボード</a>
+        {:else}
+            <a href="/login" class="text-text-secondary hover:text-primary">ログイン</a>
+            <a href="/register" class="text-primary hover:text-primary-hover">無料で始める</a>
+        {/if}
+    {/snippet}
+
+    <section class="py-6">
+        <div class="text-center">
+            <h1 class="text-h1 text-text">料金プラン</h1>
+            <p class="mt-3 text-body text-text-secondary">
+                無料で始めて、必要になったらチームで広げる。シンプルな 2 プランです。
+            </p>
+        </div>
+
+        <!-- 料金構造の注記: 基本料金 + 共通チケット制 (全プラン共通のためページ冒頭で説明) -->
+        <div
+            class="mx-auto mt-6 flex max-w-3xl items-start gap-3 rounded-lg border border-border bg-surface px-4 py-3 text-left"
+            data-testid="pricing-structure-note"
+        >
+            <Info class="mt-0.5 size-4 shrink-0 text-text-secondary" aria-hidden="true" />
+            <p class="text-body text-text-secondary">
+                表示は各プランの基本料金 (月額) です。AI 解析・動画レンダにはどのプランでも共通のチケットを使います
+                (AI 解析 {page.analysisTicketCost} 枚・動画レンダ {page.renderTicketCost} 枚。<a
+                    href="#ticket-pricing"
+                    class="text-primary underline">チケット料金</a
+                >をご覧ください)。
+            </p>
+        </div>
+
+        <!-- プランカード -->
+        <div class="mx-auto mt-10 grid max-w-3xl gap-4 sm:grid-cols-2" data-testid="pricing-plan-grid">
+            {#each page.plans as plan (plan.code)}
+                <PricingPlanCard
+                    name={plan.name}
+                    priceAmount={plan.baseAmountJpy}
+                    priceCaption="基本料金"
+                    features={buildFeatures(plan)}
+                    testId={`pricing-plan-${plan.code}`}
+                >
+                    {#snippet footerCta()}
+                        {#if page.isAuthenticated}
+                            <Button href="/billing" fullWidth inertia>プランを変更</Button>
+                        {:else}
+                            <Button href="/register" fullWidth>このプランで始める</Button>
+                        {/if}
+                    {/snippet}
+                </PricingPlanCard>
             {/each}
         </div>
-    </div>
-</main>
+
+        <!-- 大規模利用バナー -->
+        <div
+            class="mx-auto mt-4 flex max-w-3xl flex-col gap-4 rounded-lg border border-border bg-surface p-6 sm:flex-row sm:items-center sm:justify-between"
+            data-testid="pricing-enterprise-banner"
+        >
+            <div>
+                <h2 class="text-h3 text-text">より大きな組織・拠点展開のご相談</h2>
+                <p class="mt-1 text-body text-text-secondary">
+                    メンバー数・ストレージ・運用設計のご要望に応じて個別にご案内します。
+                </p>
+            </div>
+            <Button
+                href={page.contactUrl}
+                variant="ghost"
+                class="shrink-0"
+                target={page.contactIsExternal ? "_blank" : undefined}
+            >
+                {#if page.contactIsExternal}
+                    <ExternalLink class="size-4" aria-hidden="true" />
+                {:else}
+                    <Mail class="size-4" aria-hidden="true" />
+                {/if}
+                お問い合わせ
+            </Button>
+        </div>
+
+        <!-- チケット料金表 -->
+        <div id="ticket-pricing" class="mx-auto mt-12 max-w-2xl">
+            <h2 class="text-center text-h2 text-text">チケット料金</h2>
+            <p class="mt-3 text-center text-body text-text-secondary">
+                チケットは AI 解析 ({page.analysisTicketCost} 枚)・動画レンダ ({page.renderTicketCost} 枚)
+                に使います。組織のオーナー・管理者が必要な分だけ購入でき、まとめ買いで 1 枚あたりの料金が下がります。
+            </p>
+            <p
+                class="mt-4 rounded-lg border border-primary/30 bg-primary-soft px-4 py-3 text-center text-body text-text"
+                data-testid="signup-grant-note"
+            >
+                新規契約でチケット {page.signupGrantTickets} 枚が無料でついてきます (付与から {page.signupGrantExpiryDays}
+                日間有効)
+            </p>
+            {#if tierRows.length > 0}
+                <ul
+                    class="mt-4 rounded-lg border border-border bg-surface px-6 py-2 text-body"
+                    data-testid="ticket-tier-table"
+                >
+                    {#each tierRows as row (row.label)}
+                        <li class="flex justify-between border-b border-border py-2 last:border-b-0">
+                            <span class="text-text-secondary">{row.label}</span>
+                            <span class="text-text">¥{formatYen(row.unitAmount)} ／ 枚</span>
+                        </li>
+                    {/each}
+                </ul>
+            {/if}
+        </div>
+
+        {#if page.isAuthenticated}
+            <div class="mt-8 text-center">
+                <Button href="/dashboard" variant="ghost" inertia>
+                    <LayoutDashboard class="size-4" aria-hidden="true" /> ダッシュボードへ戻る
+                </Button>
+            </div>
+        {/if}
+    </section>
+
+    <!-- FAQ -->
+    <section class="py-10">
+        <div class="mx-auto max-w-3xl">
+            <h2 class="text-center text-h2 text-text">よくあるご質問</h2>
+            <div class="mt-6 space-y-2" data-testid="pricing-faq">
+                {#each faqs as faq, i (faq.q)}
+                    <div class="rounded-lg border border-border bg-surface">
+                        <button
+                            type="button"
+                            onclick={() => toggleFaq(i)}
+                            class="flex w-full items-center justify-between px-4 py-3 text-left"
+                            aria-expanded={openFaqIndex === i}
+                        >
+                            <span class="text-h3 text-text">{faq.q}</span>
+                            <ChevronDown
+                                class="size-4 text-text-secondary transition-transform {openFaqIndex === i
+                                    ? 'rotate-180'
+                                    : ''}"
+                                aria-hidden="true"
+                            />
+                        </button>
+                        {#if openFaqIndex === i}
+                            <div class="px-4 pb-4 text-body text-text">{faq.a}</div>
+                        {/if}
+                    </div>
+                {/each}
+            </div>
+        </div>
+    </section>
+
+    {#snippet footerLinks()}
+        <a href="/" class="hover:text-primary">トップ</a>
+        <a href="/terms" class="hover:text-primary">利用規約</a>
+        <a href="/privacy" class="hover:text-primary">プライバシーポリシー</a>
+        <a href={page.contactUrl} class="hover:text-primary">お問い合わせ</a>
+    {/snippet}
+</GuestLayout>
diff --git a/resources/js/pages/Welcome.svelte b/resources/js/pages/Welcome.svelte
index 3cfd80b..6fc8659 100644
--- a/resources/js/pages/Welcome.svelte
+++ b/resources/js/pages/Welcome.svelte
@@ -1,10 +1,394 @@
 <script lang="ts">
-    let { appName }: { appName: string } = $props();
+    import {
+        ArrowRight,
+        Building2,
+        Camera,
+        Check,
+        Circle,
+        Clapperboard,
+        ExternalLink,
+        FileSpreadsheet,
+        FileText,
+        KeyRound,
+        LayoutDashboard,
+        Lock,
+        Mail,
+        Scissors,
+        Smartphone,
+        Sparkles,
+        Upload,
+        UserCog,
+        Users,
+        Video,
+    } from "@lucide/svelte";
+    import Button from "@/components/atoms/Button.svelte";
+    import GuestLayout from "@/components/templates/GuestLayout.svelte";
+    import type { LandingPageProps } from "@/types/marketing";
+
+    /**
+     * LP (トップ)。North Star: SOP を起点に AI がカット設計 → PWA ナビ撮影 →
+     * 編集ゼロで字幕付きマニュアル動画。title / description はサーバ SEO
+     * (SeoComposer/SeoRenderer) が正本のため svelte:head は付けない。
+     */
+    interface Props {
+        appName: string;
+        page: LandingPageProps;
+    }
+
+    let { appName, page }: Props = $props();
+
+    // 課題 (3 つの壁): 台本作成・撮影判断・編集 (North Star の 3 ハードルそのまま)
+    const problems = [
+        {
+            icon: FileText,
+            title: "台本作成の壁",
+            text: "何をどう撮ればマニュアルになるのか。構成と台本を考えるだけで手が止まります。",
+        },
+        {
+            icon: Video,
+            title: "撮影判断の壁",
+            text: "現場でどこから撮るか迷い、撮り直しが増える。品質が撮影者のスキルに左右されます。",
+        },
+        {
+            icon: Scissors,
+            title: "編集の壁",
+            text: "切り貼り・字幕付け・書き出し。撮ったあとの編集に一番時間がかかります。",
+        },
+    ] as const;
+
+    // 3 ステップ (How): SOP → AI カット設計 → PWA ナビ撮影 → 自動合成
+    const steps = [
+        {
+            icon: Upload,
+            title: "手順書をアップロード",
+            text: "現場にある作業手順書 (PDF / Excel) をそのまま渡します。作り直しは不要です。",
+        },
+        {
+            icon: Sparkles,
+            title: "AI がカットを設計",
+            text: "AI が手順を読み解き、撮るべきカットを並べた動画シナリオ (撮影指示) を生成します。",
+        },
+        {
+            icon: Smartphone,
+            title: "スマホでナビ撮影",
+            text: "スマホ (PWA) がカットごとに撮影をナビゲート。指示に従って撮るだけです。",
+        },
+        {
+            icon: Clapperboard,
+            title: "自動で動画に合成",
+            text: "撮ったテイクを字幕付きの完成動画へ自動合成。編集作業はありません。",
+        },
+    ] as const;
+
+    // 成果 (3 者): 撮る人 / 教える人 / 管理者
+    const outcomes = [
+        {
+            icon: Camera,
+            title: "撮る人は、ナビに従うだけ",
+            text: "動画の専門知識はいりません。画面の指示どおりに撮れば、必要なカットが揃います。",
+        },
+        {
+            icon: UserCog,
+            title: "教える人の品質に、依存しない",
+            text: "標準作業を起点に AI が教材を設計するため、撮影者・指導者のスキルで品質がぶれません。",
+        },
+        {
+            icon: Users,
+            title: "管理者には、標準化された資産を",
+            text: "熟練者の暗黙知が、組織で共有できる標準化された動画マニュアル資産として残ります。",
+        },
+    ] as const;
+
+    // 組織で安全に (既存基盤の事実のみ)
+    const security = [
+        {
+            icon: Building2,
+            title: "組織分離",
+            text: "組織ごとにデータを分離。他組織のマニュアル・メンバー情報は見えません。",
+        },
+        {
+            icon: KeyRound,
+            title: "アクセス権限 (RBAC)",
+            text: "役割ごとに操作できる範囲を制御します。",
+        },
+        {
+            icon: Lock,
+            title: "個人情報 (PII) の暗号化",
+            text: "氏名やメールアドレスなど個人を特定しうる情報は暗号化して保管します。",
+        },
+    ] as const;
+
+    // Hero 右カラム: 撮影ナビ画面の静的モック (実データなし)
+    const mockCuts = [
+        { label: "カット 1: 作業前の全体", done: true },
+        { label: "カット 2: 元栓の位置を確認", done: true },
+        { label: "カット 3: バルブを閉める", done: false },
+        { label: "カット 4: 閉止後の目視確認", done: false },
+    ] as const;
 </script>
 
-<main class="flex min-h-screen items-center justify-center bg-neutral text-text">
-    <div class="text-center">
-        <h1 class="text-h2">{appName}</h1>
-        <p class="mt-2 text-caption text-text-secondary">Laravel + Svelte template is running.</p>
-    </div>
-</main>
+<GuestLayout {appName}>
+    {#snippet nav()}
+        <a href="/pricing" class="text-text-secondary hover:text-primary">料金プラン</a>
+        {#if page.isAuthenticated}
+            <a href="/dashboard" class="text-text-secondary hover:text-primary">ダッシュボード</a>
+        {:else}
+            <a href="/login" class="text-text-secondary hover:text-primary">ログイン</a>
+            <a href="/register" class="text-primary hover:text-primary-hover">無料で始める</a>
+        {/if}
+    {/snippet}
+
+    <!-- Hero -->
+    <section class="grid items-center gap-12 py-8 lg:grid-cols-2" data-testid="landing-hero">
+        <div>
+            <span
+                class="inline-flex items-center gap-2 rounded-sm bg-primary-soft px-3 py-1 text-caption font-medium text-primary"
+            >
+                <Sparkles class="size-4" aria-hidden="true" /> AI がカットを設計するマニュアル動画
+            </span>
+            <h1 class="mt-5 text-h1 text-text">動画マニュアルを、<br />手順書から。</h1>
+            <p class="mt-6 max-w-xl text-body text-text-secondary">
+                現場にある作業手順書 (SOP) を渡せば、AI が撮るべきカットを設計。スマホのナビに従って撮るだけで、
+                編集ゼロで字幕付きのマニュアル動画が完成します。台本作成・撮影判断・編集は、もう要りません。
+            </p>
+            <div class="mt-8 flex flex-col gap-3 sm:flex-row">
+                {#if page.isAuthenticated}
+                    <Button href="/dashboard" size="lg" inertia>
+                        <LayoutDashboard class="size-5" aria-hidden="true" /> ダッシュボードへ
+                    </Button>
+                {:else}
+                    <Button href="/register" size="lg" testId="hero-register">無料で始める</Button>
+                {/if}
+                <Button href="#how" size="lg" variant="ghost">
+                    仕組みを見る <ArrowRight class="size-4" aria-hidden="true" />
+                </Button>
+            </div>
+        </div>
+
+        <!-- 撮影ナビ画面の静的モック -->
+        <div class="rounded-lg border border-border bg-surface" aria-hidden="true">
+            <div class="flex h-12 items-center justify-between border-b border-border px-4">
+                <span class="text-caption font-medium text-text">バルブ閉止作業マニュアル</span>
+                <span class="rounded-md bg-success/10 px-3 py-1 text-caption text-success">撮影中 3/8</span>
+            </div>
+            <div class="flex flex-col gap-2 px-4 py-4">
+                {#each mockCuts as cut (cut.label)}
+                    <div
+                        class="flex items-center gap-2 rounded-md border px-3 py-2 text-caption {cut.done
+                            ? 'border-border bg-neutral text-text-secondary'
+                            : 'border-primary bg-primary-soft text-text'}"
+                    >
+                        {#if cut.done}
+                            <Check class="size-4 shrink-0 text-success" />
+                        {:else}
+                            <Circle class="size-4 shrink-0 text-primary" />
+                        {/if}
+                        {cut.label}
+                    </div>
+                {/each}
+            </div>
+            <div class="flex items-center justify-between gap-3 border-t border-border px-4 py-3">
+                <p class="text-caption text-text-secondary">
+                    ガイド: バルブ全体が映る位置から、閉め終わるまで撮影してください
+                </p>
+                <span
+                    class="flex size-10 shrink-0 items-center justify-center rounded-lg border-2 border-danger"
+                >
+                    <span class="size-5 rounded-sm bg-danger"></span>
+                </span>
+            </div>
+        </div>
+    </section>
+
+    <!-- 課題 (3 つの壁) -->
+    <section class="py-14">
+        <h2 class="text-center text-h2 text-text">動画マニュアルには、3 つの壁がある。</h2>
+        <p class="mx-auto mt-3 max-w-2xl text-center text-body text-text-secondary">
+            「作ったほうがいい」と分かっていても進まないのは、台本・撮影・編集のすべてに専門知識が要るからです。
+        </p>
+        <div class="mt-10 grid gap-6 sm:grid-cols-3">
+            {#each problems as problem (problem.title)}
+                {@const Icon = problem.icon}
+                <div class="rounded-lg border border-border bg-surface p-6">
+                    <div
+                        class="mb-4 flex size-10 items-center justify-center rounded-sm bg-primary-soft text-primary"
+                    >
+                        <Icon class="size-5" aria-hidden="true" />
+                    </div>
+                    <h3 class="text-h3 text-text">{problem.title}</h3>
+                    <p class="mt-2 text-body text-text-secondary">{problem.text}</p>
+                </div>
+            {/each}
+        </div>
+    </section>
+
+    <!-- 3 ステップ (How) -->
+    <section id="how" class="rounded-lg bg-surface px-6 py-14">
+        <h2 class="text-center text-h2 text-text">思考ゼロ・編集ゼロの 4 ステップ。</h2>
+        <p class="mx-auto mt-3 max-w-2xl text-center text-body text-text-secondary">
+            考える工程は AI が、撮る工程はナビが、編集は自動合成が引き受けます。
+        </p>
+
+        <div
+            class="mt-8 flex flex-wrap items-center justify-center gap-x-3 gap-y-2 text-caption font-medium text-text-secondary"
+        >
+            <span class="rounded-sm bg-neutral px-3 py-1">手順書</span>
+            <ArrowRight class="size-4" aria-hidden="true" />
+            <span class="rounded-sm bg-neutral px-3 py-1">AI がカット設計</span>
+            <ArrowRight class="size-4" aria-hidden="true" />
+            <span class="rounded-sm bg-neutral px-3 py-1">ナビ撮影</span>
+            <ArrowRight class="size-4" aria-hidden="true" />
+            <span class="rounded-sm bg-neutral px-3 py-1">自動合成</span>
+        </div>
+
+        <div class="mt-10 grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
+            {#each steps as step, i (step.title)}
+                {@const Icon = step.icon}
+                <div class="rounded-lg border border-border bg-neutral p-6">
+                    <span class="text-caption font-medium text-primary">STEP {i + 1}</span>
+                    <div
+                        class="mt-3 mb-4 flex size-10 items-center justify-center rounded-sm bg-primary-soft text-primary"
+                    >
+                        <Icon class="size-5" aria-hidden="true" />
+                    </div>
+                    <h3 class="text-h3 text-text">{step.title}</h3>
+                    <p class="mt-2 text-body text-text-secondary">{step.text}</p>
+                </div>
+            {/each}
+        </div>
+    </section>
+
+    <!-- 素材 -->
+    <section class="grid items-center gap-12 py-14 lg:grid-cols-2">
+        <div>
+            <h2 class="text-h2 text-text">手元の手順書から、そのまま作れる。</h2>
+            <p class="mt-4 text-body text-text-secondary">
+                作業手順書・作業標準・点検要領——現場に既にある文書が、そのまま動画マニュアルの設計図になります。
+                動画のために資料を作り直したり、構成を考え直したりする必要はありません。
+            </p>
+            <p class="mt-4 text-body text-text-secondary">
+                AI は手順の意図を読み解き、「どの作業を・どこから・どう撮るか」まで設計した動画シナリオに変換します。
+            </p>
+        </div>
+        <div class="rounded-lg border border-border bg-surface p-6" aria-hidden="true">
+            <div class="flex flex-wrap justify-center gap-2">
+                <span
+                    class="inline-flex items-center gap-2 rounded-sm border border-border bg-neutral px-3 py-2 text-caption text-text"
+                >
+                    <FileText class="size-4 text-text-secondary" /> 作業手順書.pdf
+                </span>
+                <span
+                    class="inline-flex items-center gap-2 rounded-sm border border-border bg-neutral px-3 py-2 text-caption text-text"
+                >
+                    <FileSpreadsheet class="size-4 text-text-secondary" /> 作業標準.xlsx
+                </span>
+            </div>
+            <div class="my-4 flex justify-center text-text-secondary">
+                <ArrowRight class="size-5 rotate-90" />
+            </div>
+            <div class="rounded-md border border-border bg-neutral p-4">
+                <div class="flex items-center gap-2">
+                    <span
+                        class="inline-flex size-8 shrink-0 items-center justify-center rounded-md bg-primary-soft text-primary"
+                    >
+                        <Clapperboard class="size-4" />
+                    </span>
+                    <span class="text-body font-medium text-text">バルブ閉止作業 の動画シナリオ</span>
+                </div>
+                <p class="mt-3 text-caption text-text-secondary">
+                    カット割り・撮影ガイド・字幕まで設計済み。あとはナビに従って撮るだけです。
+                </p>
+            </div>
+        </div>
+    </section>
+
+    <!-- 成果 (3 者) -->
+    <section class="rounded-lg bg-surface px-6 py-14">
+        <h2 class="text-center text-h2 text-text">誰が撮っても、同じ品質に。</h2>
+        <div class="mt-10 grid gap-6 lg:grid-cols-3">
+            {#each outcomes as outcome (outcome.title)}
+                {@const Icon = outcome.icon}
+                <div class="rounded-lg border border-border bg-neutral p-6">
+                    <div
+                        class="mb-4 flex size-10 items-center justify-center rounded-sm bg-primary-soft text-primary"
+                    >
+                        <Icon class="size-5" aria-hidden="true" />
+                    </div>
+                    <h3 class="text-h3 text-text">{outcome.title}</h3>
+                    <p class="mt-2 text-body text-text-secondary">{outcome.text}</p>
+                </div>
+            {/each}
+        </div>
+    </section>
+
+    <!-- 組織で安全に -->
+    <section class="py-14">
+        <h2 class="text-center text-h2 text-text">組織で安全に運用できます。</h2>
+        <div class="mt-10 grid gap-4 sm:grid-cols-3">
+            {#each security as item (item.title)}
+                {@const Icon = item.icon}
+                <div class="flex items-start gap-3 rounded-lg border border-border bg-surface p-5">
+                    <span
+                        class="inline-flex size-9 shrink-0 items-center justify-center rounded-sm bg-primary-soft text-primary"
+                    >
+                        <Icon class="size-5" aria-hidden="true" />
+                    </span>
+                    <div>
+                        <h3 class="text-body font-medium text-text">{item.title}</h3>
+                        <p class="mt-1 text-caption text-text-secondary">{item.text}</p>
+                    </div>
+                </div>
+            {/each}
+        </div>
+    </section>
+
+    <!-- 料金 CTA -->
+    <section class="rounded-lg bg-surface px-6 py-14 text-center" data-testid="landing-pricing-cta">
+        <h2 class="text-h2 text-text">無料で始められます。</h2>
+        <p class="mx-auto mt-3 max-w-2xl text-body text-text-secondary">
+            Free プランで今すぐ試せます。新規登録でチケット {page.signupGrantTickets} 枚が無料
+            (AI 解析 1 枚・動画レンダ 3 枚を消費)。
+        </p>
+        <div class="mt-8 flex flex-col items-center justify-center gap-3 sm:flex-row">
+            {#if page.isAuthenticated}
+                <Button href="/dashboard" size="lg" inertia>
+                    <LayoutDashboard class="size-5" aria-hidden="true" /> ダッシュボードへ
+                </Button>
+            {:else}
+                <Button href="/register" size="lg">無料で始める</Button>
+            {/if}
+            <Button href="/pricing" size="lg" variant="ghost" inertia>
+                料金プランを見る <ArrowRight class="size-4" aria-hidden="true" />
+            </Button>
+        </div>
+    </section>
+
+    <!-- 問い合わせ -->
+    <section class="py-12 text-center">
+        <h2 class="text-h3 text-text">導入のご相談・お問い合わせ</h2>
+        <p class="mx-auto mt-3 max-w-2xl text-body text-text-secondary">
+            拠点展開・運用設計のご相談も承ります。お気軽にお問い合わせください。
+        </p>
+        <a
+            href={page.contactUrl}
+            target={page.contactIsExternal ? "_blank" : undefined}
+            rel={page.contactIsExternal ? "noopener noreferrer" : undefined}
+            class="mt-4 inline-flex items-center gap-2 text-body text-primary hover:text-primary-hover"
+            data-testid="landing-contact-link"
+        >
+            {#if page.contactIsExternal}
+                <ExternalLink class="size-4" aria-hidden="true" />
+            {:else}
+                <Mail class="size-4" aria-hidden="true" />
+            {/if}
+            お問い合わせ <ArrowRight class="size-4" aria-hidden="true" />
+        </a>
+    </section>
+
+    {#snippet footerLinks()}
+        <a href="/pricing" class="hover:text-primary">料金プラン</a>
+        <a href="/terms" class="hover:text-primary">利用規約</a>
+        <a href="/privacy" class="hover:text-primary">プライバシーポリシー</a>
+        <a href={page.contactUrl} class="hover:text-primary">お問い合わせ</a>
+    {/snippet}
+</GuestLayout>
diff --git a/resources/js/types/billing.ts b/resources/js/types/billing.ts
new file mode 100644
index 0000000..fd7c6b7
--- /dev/null
+++ b/resources/js/types/billing.ts
@@ -0,0 +1,18 @@
+import type { PurchaseTierShape } from "@/types/marketing";
+
+/**
+ * 課金ページの Inertia props。
+ * PHP 側 DTO (App\DataTransferObjects\Billing\*) の @phpstan-type shape と exact 対。
+ */
+
+/** PHP: PurchaseTicketsPageDto (PurchaseTicketsPageShape) と対 */
+export interface PurchaseTicketsPageProps {
+    readonly tiers: readonly PurchaseTierShape[];
+    readonly minCount: number;
+    readonly maxCount: number;
+    readonly defaultCount: number;
+    readonly balance: number;
+    readonly canManage: boolean;
+    readonly attemptToken: string;
+    readonly purchased: boolean;
+}
diff --git a/resources/js/types/marketing.ts b/resources/js/types/marketing.ts
new file mode 100644
index 0000000..e852d19
--- /dev/null
+++ b/resources/js/types/marketing.ts
@@ -0,0 +1,44 @@
+/**
+ * マーケティングページ (LP / 料金表) の Inertia props。
+ * PHP 側 DTO (App\DataTransferObjects\Marketing\*) の @phpstan-type shape と exact 対。
+ * 全プロパティ readonly で accidental widening を防ぐ。
+ */
+
+/** PHP: LandingPageDto (LandingPageShape) と対 */
+export interface LandingPageProps {
+    readonly signupGrantTickets: number;
+    readonly contactUrl: string;
+    readonly contactIsExternal: boolean;
+    readonly isAuthenticated: boolean;
+}
+
+/** PHP: PurchaseTierDto (PurchaseTierShape) と対 */
+export interface PurchaseTierShape {
+    readonly minCount: number;
+    readonly unitAmount: number;
+}
+
+/** PHP: PricingPlanDto (PricingPlanShape) と対 (baseAmountJpy null = 無料表示) */
+export interface PricingPlanShape {
+    readonly code: string;
+    readonly name: string;
+    readonly baseAmountJpy: number | null;
+    readonly monthlyTicketGrant: number;
+    readonly maxProjects: number | null;
+    readonly maxMembers: number | null;
+    readonly maxStorageGb: number | null;
+}
+
+/** PHP: PricingPageDto (PricingPageShape) と対 */
+export interface PricingPageProps {
+    readonly plans: readonly PricingPlanShape[];
+    readonly ticketTiers: readonly PurchaseTierShape[];
+    readonly spotUnitAmountJpy: number;
+    readonly signupGrantTickets: number;
+    readonly signupGrantExpiryDays: number;
+    readonly analysisTicketCost: number;
+    readonly renderTicketCost: number;
+    readonly isAuthenticated: boolean;
+    readonly contactUrl: string;
+    readonly contactIsExternal: boolean;
+}
diff --git a/routes/web.php b/routes/web.php
index a3eec6f..f47a275 100644
--- a/routes/web.php
+++ b/routes/web.php
@@ -6,6 +6,7 @@
 use App\Http\Controllers\Auth\ConfirmRecentAuthController;
 use App\Http\Controllers\Auth\SocialAuthController;
 use App\Http\Controllers\Billing\BillingController;
+use App\Http\Controllers\Billing\TicketPurchaseController;
 use App\Http\Controllers\Capture\CaptureManualController;
 use App\Http\Controllers\Capture\CaptureSyncController;
 use App\Http\Controllers\Capture\CaptureTakeController;
@@ -13,6 +14,7 @@
 use App\Http\Controllers\ContactController;
 use App\Http\Controllers\DebugLoginController;
 use App\Http\Controllers\HomeController;
+use App\Http\Controllers\Marketing\PricingController;
 use App\Http\Controllers\Organizations\InvitationAcceptanceController;
 use App\Http\Controllers\Organizations\OrganizationApiKeyController;
 use App\Http\Controllers\Organizations\OrganizationController;
@@ -117,12 +119,12 @@
 |--------------------------------------------------------------------------
 | 公開マーケ / 法的ページ (auth 不要)
 |--------------------------------------------------------------------------
-| /pricing は公開 Inertia 雛形 (SEO minimal 分類。SeoComposer が title を供給)。
+| /pricing は公開料金表 (SEO full 分類。PricingController が SeoManager にメタを供給)。
 | /terms /privacy /commerce-disclosure は Route::view の薄い Blade スタブ。文面が
 | 未確定のプレースホルダのため noindex (blade の <meta robots> + NoIndex middleware の
 | X-Robots-Tag で二重防御)。正式文面へ差し替えて公開する際に noindex を外すこと。
 */
-Route::get('/pricing', fn () => Inertia::render('Pricing'))->name('pricing');
+Route::get('/pricing', PricingController::class)->name('pricing');
 Route::middleware(NoIndex::class)->group(function (): void {
     Route::view('/terms', 'legal.terms')->name('legal.terms');
     Route::view('/privacy', 'legal.privacy')->name('legal.privacy');
@@ -308,6 +310,16 @@
     Route::post('/billing/portal', [BillingController::class, 'portal'])
         ->name('billing.portal');
 
+    /*
+    | チケットスポット購入 (current org スコープ)。billing.* と同じく課金ゲート
+    | (require-active-subscription) の対象外 = 未契約 / free プラン組織でも購入できる。
+    | 閲覧は組織メンバー全員、Checkout 開始は manageBilling (owner / admin) のみ。
+    */
+    Route::get('/purchase-tickets', [TicketPurchaseController::class, 'show'])
+        ->name('billing.tickets.show');
+    Route::post('/purchase-tickets/checkout', [TicketPurchaseController::class, 'checkout'])
+        ->name('billing.tickets.checkout');
+
     /*
     | 組織配下の業務 route (課金ゲート対象)。有効な subscription (BillingAccess 判定)
     | を持たない組織は billing へ redirect される (JSON は 402)。
diff --git a/stripe/fixtures/plan_standard.json b/stripe/fixtures/plan_standard.json
index 8ffe156..bc6a70a 100644
--- a/stripe/fixtures/plan_standard.json
+++ b/stripe/fixtures/plan_standard.json
@@ -22,7 +22,7 @@
       "method": "post",
       "params": {
         "currency": "jpy",
-        "unit_amount": 1980,
+        "unit_amount": 4980,
         "product": "${standard_product:id}",
         "lookup_key": "app_standard_base",
         "nickname": "Standard 基本料 (月額)",
diff --git a/tests/Feature/Billing/BillingPageTest.php b/tests/Feature/Billing/BillingPageTest.php
index 28da8f1..9848634 100644
--- a/tests/Feature/Billing/BillingPageTest.php
+++ b/tests/Feature/Billing/BillingPageTest.php
@@ -26,7 +26,7 @@
             ->where('plans.1.code', 'standard')
             ->where('plans.1.monthlyTicketGrant', 100)
             ->has('plans.1.price', fn (Assert $price) => $price
-                ->where('unitAmount', 1980)
+                ->where('unitAmount', 4980)
                 ->where('currency', 'jpy'))
             ->where('currentPlanCode', null)
             ->where('ticketBalance', 10)
diff --git a/tests/Feature/Billing/SyncStripePricesCommandTest.php b/tests/Feature/Billing/SyncStripePricesCommandTest.php
index 5d5c410..4404ab7 100644
--- a/tests/Feature/Billing/SyncStripePricesCommandTest.php
+++ b/tests/Feature/Billing/SyncStripePricesCommandTest.php
@@ -23,7 +23,7 @@ function syncStandardBaseLookupKey(): string
  */
 function syncEntry(string $lookupKey, string $stripePriceId, array $overrides = []): StripePriceCatalogEntry
 {
-    $unitAmount = $overrides['unitAmount'] ?? 1980;
+    $unitAmount = $overrides['unitAmount'] ?? 4980;
     assert(is_int($unitAmount));
     $currency = $overrides['currency'] ?? 'jpy';
     assert(is_string($currency));
diff --git a/tests/Feature/Billing/TicketCheckoutTest.php b/tests/Feature/Billing/TicketCheckoutTest.php
new file mode 100644
index 0000000..de578fd
--- /dev/null
+++ b/tests/Feature/Billing/TicketCheckoutTest.php
@@ -0,0 +1,260 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\Billing\TicketCheckoutSessionStatus;
+use App\Models\Billing\TicketCheckoutSession;
+use App\Models\Billing\TicketVolumePrice;
+use App\Models\User;
+use App\Services\Billing\TicketCheckoutGateway;
+use Illuminate\Support\Str;
+use Inertia\Testing\AssertableInertia as Assert;
+use Tests\Support\FakeTicketCheckoutGateway;
+
+/*
+ * チケットスポット購入 (/purchase-tickets)。
+ * - 閲覧は組織メンバー全員 / Checkout 開始は manageBilling (owner / admin) のみ
+ * - 冪等マシン: attempt_token 冪等 / live pending dedup / 期限切れ回収 / race 収束
+ * - Stripe には到達しない (FakeTicketCheckoutGateway を bind)
+ */
+
+function fakeTicketGateway(): FakeTicketCheckoutGateway
+{
+    $fake = new FakeTicketCheckoutGateway;
+    app()->instance(TicketCheckoutGateway::class, $fake);
+
+    return $fake;
+}
+
+function checkoutPayload(int $count = 30, ?string $token = null): array
+{
+    return ['count' => $count, 'attempt_token' => $token ?? (string) Str::ulid()];
+}
+
+test('guest は login へ redirect される', function (): void {
+    $this->get('/purchase-tickets')->assertRedirect('/login');
+    $this->post('/purchase-tickets/checkout', checkoutPayload())->assertRedirect('/login');
+});
+
+test('owner は購入画面で tiers / 残高 / canManage / attemptToken を受け取る', function (): void {
+    [, $owner] = createOrganizationWithOwner();
+
+    $this->actingAs($owner)->get('/purchase-tickets')
+        ->assertOk()
+        ->assertInertia(fn (Assert $page) => $page
+            ->component('Billing/PurchaseTickets')
+            ->has('page.tiers', 7)
+            ->where('page.tiers.0.minCount', 1)
+            ->where('page.tiers.0.unitAmount', 100)
+            ->where('page.minCount', 1)
+            ->where('page.maxCount', 1000)
+            ->where('page.defaultCount', 10)
+            ->where('page.balance', 0)
+            ->where('page.canManage', true)
+            ->where('page.purchased', false)
+            ->has('page.attemptToken'));
+});
+
+test('member は閲覧可能 (canManage=false) だが POST は 403', function (): void {
+    [$organization] = createOrganizationWithOwner();
+    $member = attachOrganizationMember($organization);
+    $member->forceFill(['current_organization_id' => $organization->id])->save();
+
+    $this->actingAs($member)->get('/purchase-tickets')
+        ->assertOk()
+        ->assertInertia(fn (Assert $page) => $page
+            ->where('page.canManage', false));
+
+    $this->actingAs($member)
+        ->post('/purchase-tickets/checkout', checkoutPayload())
+        ->assertForbidden();
+});
+
+test('current org を持たないユーザーは 404', function (): void {
+    $user = User::factory()->create();
+
+    $this->actingAs($user)->get('/purchase-tickets')->assertNotFound();
+});
+
+test('未契約 org (subscription なし) でも GET/POST に到達できる (課金ゲート対象外)', function (): void {
+    $fake = fakeTicketGateway();
+    [, $owner] = createOrganizationWithOwner(subscribed: false);
+
+    $this->actingAs($owner)->get('/purchase-tickets')->assertOk();
+
+    $response = $this->actingAs($owner)
+        ->post('/purchase-tickets/checkout', checkoutPayload());
+
+    $response->assertStatus(302); // Inertia::location (非 Inertia リクエストは 302 redirect)
+    expect($fake->created)->toHaveCount(1);
+});
+
+test('owner の checkout は gateway 1 回呼び出しで Stripe URL へ遷移し pending 行が pin される', function (): void {
+    $fake = fakeTicketGateway();
+    [$organization, $owner] = createOrganizationWithOwner();
+    $token = (string) Str::ulid();
+
+    // Inertia リクエストでは Inertia::location = 409 + X-Inertia-Location で full page redirect する
+    $response = $this->actingAs($owner)
+        ->post('/purchase-tickets/checkout', ['count' => 30, 'attempt_token' => $token], ['X-Inertia' => 'true']);
+
+    $response->assertStatus(409);
+    expect($response->headers->get('X-Inertia-Location'))->toBe("https://checkout.stripe.test/c/pay/cs_test_{$token}");
+
+    expect($fake->created)->toHaveCount(1);
+    expect($fake->created[0]['quantity'])->toBe(30);
+    expect($fake->created[0]['idempotencyKey'])->toBe("purchase:{$token}");
+    expect($fake->created[0]['metadata'])->toBe([
+        'purpose' => 'ticket_purchase',
+        'org_ref' => (string) $organization->id,
+        'count' => '30',
+    ]);
+
+    $session = TicketCheckoutSession::query()->firstOrFail();
+    expect($session->organization_id)->toBe($organization->id);
+    expect($session->initiated_by_user_id)->toBe($owner->id);
+    expect($session->ticket_count)->toBe(30);
+    expect($session->unit_amount)->toBe(80); // 30 枚 → 20 枚〜段 (¥80) の pin
+    expect($session->currency)->toBe('jpy');
+    expect($session->status)->toBe(TicketCheckoutSessionStatus::Pending);
+    expect($session->attempt_token)->toBe($token);
+});
+
+test('同一 attempt_token の再送は gateway を呼ばず同一 URL を replay する', function (): void {
+    $fake = fakeTicketGateway();
+    [, $owner] = createOrganizationWithOwner();
+    $token = (string) Str::ulid();
+
+    $first = $this->actingAs($owner)
+        ->post('/purchase-tickets/checkout', ['count' => 30, 'attempt_token' => $token]);
+    $second = $this->actingAs($owner)
+        ->post('/purchase-tickets/checkout', ['count' => 30, 'attempt_token' => $token]);
+
+    expect($fake->created)->toHaveCount(1);
+    expect($second->headers->get('Location'))->toBe($first->headers->get('Location'));
+    expect(TicketCheckoutSession::query()->count())->toBe(1);
+});
+
+test('別 token・同 count (別タブ想定) は新規作成せず既存 live pending へ replay する', function (): void {
+    $fake = fakeTicketGateway();
+    [, $owner] = createOrganizationWithOwner();
+
+    $first = $this->actingAs($owner)
+        ->post('/purchase-tickets/checkout', checkoutPayload(30));
+    $second = $this->actingAs($owner)
+        ->post('/purchase-tickets/checkout', checkoutPayload(30));
+
+    expect($fake->created)->toHaveCount(1);
+    expect($second->headers->get('Location'))->toBe($first->headers->get('Location'));
+    expect(TicketCheckoutSession::query()->count())->toBe(1);
+});
+
+test('別 token・別 count は既存 pending を Stripe expire してから新規作成する', function (): void {
+    $fake = fakeTicketGateway();
+    [, $owner] = createOrganizationWithOwner();
+
+    $this->actingAs($owner)->post('/purchase-tickets/checkout', checkoutPayload(30));
+    $response = $this->actingAs($owner)->post('/purchase-tickets/checkout', checkoutPayload(50));
+
+    $response->assertStatus(302);
+    expect($fake->created)->toHaveCount(2);
+    expect($fake->expired)->toHaveCount(1);
+
+    $old = TicketCheckoutSession::query()->where('ticket_count', 30)->firstOrFail();
+    expect($old->status)->toBe(TicketCheckoutSessionStatus::Expired);
+    $new = TicketCheckoutSession::query()->where('ticket_count', 50)->firstOrFail();
+    expect($new->status)->toBe(TicketCheckoutSessionStatus::Pending);
+    expect($new->unit_amount)->toBe(70); // 50 枚 → 50 枚〜段 (¥70)
+});
+
+test('expire が complete を返したら新規作成せずエラー着地する (直前の決済が処理中)', function (): void {
+    $fake = fakeTicketGateway();
+    $fake->expireResult = 'complete';
+    [, $owner] = createOrganizationWithOwner();
+
+    $this->actingAs($owner)->post('/purchase-tickets/checkout', checkoutPayload(30));
+    $response = $this->actingAs($owner)->post('/purchase-tickets/checkout', checkoutPayload(50));
+
+    $response->assertRedirect();
+    $response->assertSessionHas('error');
+    expect($fake->created)->toHaveCount(1); // 新規作成なし
+    // 既存 pending は expired 化されない (complete = 決済側で完結する)
+    $session = TicketCheckoutSession::query()->firstOrFail();
+    expect($session->status)->toBe(TicketCheckoutSessionStatus::Pending);
+});
+
+test('期限切れ pending は replay されず expired 化して新 session を作成する', function (): void {
+    $fake = fakeTicketGateway();
+    [$organization, $owner] = createOrganizationWithOwner();
+
+    $staleToken = (string) Str::ulid();
+    $stale = TicketCheckoutSession::factory()
+        ->forOrganization($organization)
+        ->initiatedBy($owner)
+        ->stale()
+        ->create(['ticket_count' => 30, 'attempt_token' => $staleToken]);
+
+    $response = $this->actingAs($owner)->post('/purchase-tickets/checkout', checkoutPayload(30));
+
+    $response->assertStatus(302);
+    expect($fake->created)->toHaveCount(1);
+    expect($fake->expired)->toHaveCount(0); // 期限切れは Stripe expire 不要 (pin 値で決定的)
+    expect($stale->refresh()->status)->toBe(TicketCheckoutSessionStatus::Expired);
+    expect(TicketCheckoutSession::query()->count())->toBe(2);
+});
+
+test('completed 済み attempt_token の再送は gateway を呼ばず受付済み着地する', function (): void {
+    $fake = fakeTicketGateway();
+    [$organization, $owner] = createOrganizationWithOwner();
+
+    $token = (string) Str::ulid();
+    TicketCheckoutSession::factory()
+        ->forOrganization($organization)
+        ->initiatedBy($owner)
+        ->completed()
+        ->create(['ticket_count' => 30, 'attempt_token' => $token]);
+
+    $response = $this->actingAs($owner)
+        ->post('/purchase-tickets/checkout', ['count' => 30, 'attempt_token' => $token]);
+
+    $response->assertRedirect(route('billing.tickets.show'));
+    $response->assertSessionHas('info');
+    expect($fake->created)->toHaveCount(0);
+});
+
+test('count 境界外・非整数・attempt_token 不正は validation error になる', function (array $payload, string $errorKey): void {
+    $fake = fakeTicketGateway();
+    [, $owner] = createOrganizationWithOwner();
+
+    $this->actingAs($owner)
+        ->post('/purchase-tickets/checkout', $payload)
+        ->assertSessionHasErrors($errorKey);
+    expect($fake->created)->toHaveCount(0);
+})->with([
+    'count=0' => [['count' => 0, 'attempt_token' => '01J00000000000000000000000'], 'count'],
+    'count=1001' => [['count' => 1001, 'attempt_token' => '01J00000000000000000000000'], 'count'],
+    'count 非整数' => [['count' => 'ten', 'attempt_token' => '01J00000000000000000000000'], 'count'],
+    'attempt_token 欠落' => [['count' => 10], 'attempt_token'],
+    'attempt_token 非 ULID' => [['count' => 10, 'attempt_token' => 'not-a-ulid'], 'attempt_token'],
+]);
+
+test('payload に organization_id (保護キー) が混入すると 422', function (): void {
+    fakeTicketGateway();
+    [$organization, $owner] = createOrganizationWithOwner();
+
+    $this->actingAs($owner)
+        ->post('/purchase-tickets/checkout', checkoutPayload() + ['organization_id' => $organization->id])
+        ->assertSessionHasErrors('organization_id');
+});
+
+test('tier が空のとき fail-closed で error flash に着地する (spot へ落ちない)', function (): void {
+    $fake = fakeTicketGateway();
+    [, $owner] = createOrganizationWithOwner();
+    TicketVolumePrice::query()->delete();
+
+    $response = $this->actingAs($owner)->post('/purchase-tickets/checkout', checkoutPayload(30));
+
+    $response->assertRedirect();
+    $response->assertSessionHas('error');
+    expect($fake->created)->toHaveCount(0);
+});
diff --git a/tests/Feature/Billing/TicketPurchaseWebhookTest.php b/tests/Feature/Billing/TicketPurchaseWebhookTest.php
new file mode 100644
index 0000000..f510bf5
--- /dev/null
+++ b/tests/Feature/Billing/TicketPurchaseWebhookTest.php
@@ -0,0 +1,258 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\Billing\TicketCheckoutSessionStatus;
+use App\Enums\Billing\TicketSource;
+use App\Enums\Billing\WebhookEventStatus;
+use App\Models\Billing\StripeWebhookEvent;
+use App\Models\Billing\TicketCheckoutSession;
+use App\Models\Organization;
+use App\Services\Billing\CashierTicketCheckoutGateway;
+use App\Services\Billing\StripeWebhookProcessor;
+use App\Services\Billing\TicketLedgerService;
+use Illuminate\Contracts\Debug\ExceptionHandler;
+use Laravel\Cashier\Events\WebhookReceived;
+
+/*
+ * checkout.session.completed によるチケット購入の冪等付与。
+ * 真実源は ticket_checkout_sessions 行。照合不一致・行不在は retryable failure (failed +
+ * Stripe 再送)、purpose 外は受理のみ (processed)。Stripe API は呼ばない
+ * (WebhookReceived 直発火の既存流儀)。
+ */
+
+/** stripe_id 付き org + owner + pending 追跡行を作る */
+function ticketPurchaseFixture(int $count = 30, int $unitAmount = 80): array
+{
+    [$organization, $owner] = createOrganizationWithOwner();
+    $organization->stripe_id = 'cus_ticket_test_1';
+    $organization->save();
+
+    $session = TicketCheckoutSession::factory()
+        ->forOrganization($organization)
+        ->initiatedBy($owner)
+        ->create([
+            'ticket_count' => $count,
+            'unit_amount' => $unitAmount,
+            'currency' => 'jpy',
+            'stripe_session_id' => 'cs_test_purchase_1',
+        ]);
+
+    return [$organization, $owner, $session];
+}
+
+/**
+ * @return array<string, mixed>
+ */
+function ticketPurchaseCompletedPayload(
+    string $eventId = 'evt_ticket_purchase_1',
+    array $overrides = [],
+): array {
+    $object = array_merge([
+        'id' => 'cs_test_purchase_1',
+        'mode' => 'payment',
+        'customer' => 'cus_ticket_test_1',
+        'payment_status' => 'paid',
+        'payment_intent' => 'pi_ticket_test_1',
+        'amount_subtotal' => 30 * 80,
+        'currency' => 'jpy',
+        'metadata' => [
+            'purpose' => 'ticket_purchase',
+            'org_ref' => '',
+            'count' => '30',
+        ],
+    ], $overrides);
+
+    return [
+        'id' => $eventId,
+        'type' => 'checkout.session.completed',
+        'data' => ['object' => $object],
+    ];
+}
+
+/** org_ref を実 org id で埋めた正常 payload */
+function paidTicketPayload(Organization $organization, string $eventId = 'evt_ticket_purchase_1'): array
+{
+    $payload = ticketPurchaseCompletedPayload($eventId);
+    $payload['data']['object']['metadata']['org_ref'] = (string) $organization->id;
+
+    return $payload;
+}
+
+test('正常系: pending 行 + paid payload で残高が増え行が completed 化される', function (): void {
+    [$organization, , $session] = ticketPurchaseFixture();
+
+    event(new WebhookReceived(paidTicketPayload($organization)));
+
+    expect(app(TicketLedgerService::class)->balance($organization))->toBe(30);
+    expect($session->refresh()->status)->toBe(TicketCheckoutSessionStatus::Completed);
+    expect($session->completed_at)->not->toBeNull();
+
+    $entry = $organization->ticketLedgerEntries()
+        ->where('idempotency_key', 'purchase:cs_test_purchase_1')
+        ->firstOrFail();
+    expect($entry->delta)->toBe(30);
+    expect($entry->source)->toBe(TicketSource::Purchased);
+    expect($entry->payment_intent_id)->toBe('pi_ticket_test_1');
+    expect($entry->purchase_amount)->toBe(2400);
+});
+
+test('同一 event_id の再送は claim skip で二重付与しない', function (): void {
+    [$organization] = ticketPurchaseFixture();
+
+    event(new WebhookReceived(paidTicketPayload($organization)));
+    event(new WebhookReceived(paidTicketPayload($organization)));
+
+    expect(app(TicketLedgerService::class)->balance($organization))->toBe(30);
+    expect($organization->ticketLedgerEntries()->count())->toBe(1);
+});
+
+test('event_id 違いの同一 session 再送は台帳 idempotency_key で二重付与しない', function (): void {
+    [$organization] = ticketPurchaseFixture();
+
+    event(new WebhookReceived(paidTicketPayload($organization, 'evt_a')));
+    event(new WebhookReceived(paidTicketPayload($organization, 'evt_b')));
+
+    expect(app(TicketLedgerService::class)->balance($organization))->toBe(30);
+    expect($organization->ticketLedgerEntries()->count())->toBe(1);
+});
+
+test('DB 行なし (crash 先着) は failed になり、行記録後の再送で一度だけ付与される', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $organization->stripe_id = 'cus_ticket_test_1';
+    $organization->save();
+
+    // (1) 追跡行が無い状態で webhook 先着 → 例外 = failed + 付与なし
+    expect(fn () => event(new WebhookReceived(paidTicketPayload($organization))))
+        ->toThrow(RuntimeException::class, '未追跡 session');
+
+    $record = StripeWebhookEvent::query()->firstOrFail();
+    expect($record->status)->toBe(WebhookEventStatus::Failed);
+    expect(app(TicketLedgerService::class)->balance($organization))->toBe(0);
+
+    // (2) 同一 attempt の再試行が DB 行を記録 (Stripe idempotency key で同一 session に収束)
+    TicketCheckoutSession::factory()
+        ->forOrganization($organization)
+        ->initiatedBy($owner)
+        ->create([
+            'ticket_count' => 30,
+            'unit_amount' => 80,
+            'currency' => 'jpy',
+            'stripe_session_id' => 'cs_test_purchase_1',
+        ]);
+
+    // (3) Stripe の event 再送 (failed→received 復帰) で一度だけ付与
+    event(new WebhookReceived(paidTicketPayload($organization)));
+
+    expect(app(TicketLedgerService::class)->balance($organization))->toBe(30);
+    expect($organization->ticketLedgerEntries()->count())->toBe(1);
+    expect($record->refresh()->status)->toBe(WebhookEventStatus::Processed);
+});
+
+test('照合不一致・未決済は failed + 付与なし (fail-closed)', function (array $overrides, string $messagePart): void {
+    [$organization, , $session] = ticketPurchaseFixture();
+
+    $payload = paidTicketPayload($organization);
+    foreach ($overrides as $key => $value) {
+        data_set($payload, "data.object.{$key}", $value);
+    }
+
+    expect(fn () => event(new WebhookReceived($payload)))
+        ->toThrow(RuntimeException::class, $messagePart);
+
+    expect(app(TicketLedgerService::class)->balance($organization))->toBe(0);
+    expect($session->refresh()->status)->toBe(TicketCheckoutSessionStatus::Pending);
+    expect(StripeWebhookEvent::query()->firstOrFail()->status)->toBe(WebhookEventStatus::Failed);
+})->with([
+    'payment_status 未決済' => [['payment_status' => 'unpaid'], 'payment_status'],
+    'amount_subtotal 不一致' => [['amount_subtotal' => 100], '金額/通貨照合不一致'],
+    'currency 不一致' => [['currency' => 'usd'], '金額/通貨照合不一致'],
+    'customer 不一致' => [['customer' => 'cus_other'], 'customer 照合不一致'],
+    'metadata org_ref 不一致' => [['metadata.org_ref' => '999999'], 'org_ref 照合不一致'],
+]);
+
+test('purpose 外・mode 外は受理のみ (processed) で付与しない', function (array $overrides): void {
+    [$organization, , $session] = ticketPurchaseFixture();
+
+    $payload = paidTicketPayload($organization);
+    foreach ($overrides as $key => $value) {
+        data_set($payload, "data.object.{$key}", $value);
+    }
+
+    event(new WebhookReceived($payload));
+
+    expect(app(TicketLedgerService::class)->balance($organization))->toBe(0);
+    expect($session->refresh()->status)->toBe(TicketCheckoutSessionStatus::Pending);
+    expect(StripeWebhookEvent::query()->firstOrFail()->status)->toBe(WebhookEventStatus::Processed);
+})->with([
+    'purpose なし' => [['metadata.purpose' => null]],
+    '別 purpose' => [['metadata.purpose' => 'other_purpose']],
+    'mode=subscription' => [['mode' => 'subscription']],
+]);
+
+test('attempts 上限到達の checkout.session.completed は terminal-ack + report される', function (): void {
+    [$organization] = ticketPurchaseFixture();
+
+    $record = new StripeWebhookEvent;
+    $record->event_id = 'evt_terminal_1';
+    $record->type = 'checkout.session.completed';
+    $record->status = WebhookEventStatus::Failed;
+    $record->payload = paidTicketPayload($organization, 'evt_terminal_1');
+    $record->attempts = StripeWebhookProcessor::MAX_PROCESSING_ATTEMPTS;
+    $record->failure_reason = '恒久失敗';
+    $record->save();
+
+    // report() 経路 (運用アラート) に載ることを ExceptionHandler の spy で観測する
+    $handler = Mockery::spy(ExceptionHandler::class);
+    app()->instance(ExceptionHandler::class, $handler);
+
+    // 例外は投げられない (terminal-ack = 200) + 付与されない
+    event(new WebhookReceived(paidTicketPayload($organization, 'evt_terminal_1')));
+
+    expect(app(TicketLedgerService::class)->balance($organization))->toBe(0);
+    expect($record->refresh()->status)->toBe(WebhookEventStatus::Failed);
+    expect($record->attempts)->toBe(StripeWebhookProcessor::MAX_PROCESSING_ATTEMPTS);
+    $handler->shouldHaveReceived('report')->once();
+});
+
+test('gateway payload は promo / automatic tax を含まない (金額照合の前提 invariant)', function (): void {
+    [$organization] = ticketPurchaseFixture();
+    $gateway = new CashierTicketCheckoutGateway;
+
+    $payload = $gateway->buildSessionPayload(
+        $organization,
+        'price_test_x',
+        30,
+        'https://app.test/success',
+        'https://app.test/cancel',
+        ['purpose' => 'ticket_purchase'],
+    );
+
+    expect($payload)->not->toHaveKey('allow_promotion_codes');
+    expect($payload)->not->toHaveKey('automatic_tax');
+    expect($payload['mode'])->toBe('payment');
+    expect($payload['payment_method_types'])->toBe(['card']);
+    expect($payload['line_items'])->toBe([['price' => 'price_test_x', 'quantity' => 30]]);
+});
+
+test('付与後の charge.refunded (payment_intent 一致) で既存 clawback が逆仕訳する', function (): void {
+    [$organization] = ticketPurchaseFixture();
+
+    event(new WebhookReceived(paidTicketPayload($organization)));
+    expect(app(TicketLedgerService::class)->balance($organization))->toBe(30);
+
+    // 全額返金 → 全枚数逆仕訳 (既存 charge.refunded → clawbackPurchasedByPaymentIntent 経路)
+    event(new WebhookReceived([
+        'id' => 'evt_refund_1',
+        'type' => 'charge.refunded',
+        'data' => [
+            'object' => [
+                'id' => 'ch_test_1',
+                'payment_intent' => 'pi_ticket_test_1',
+                'amount_refunded' => 2400,
+            ],
+        ],
+    ]));
+
+    expect(app(TicketLedgerService::class)->balance($organization))->toBe(0);
+});
diff --git a/tests/Feature/Billing/TicketVolumeTierTest.php b/tests/Feature/Billing/TicketVolumeTierTest.php
index 269d46a..d2d3d9a 100644
--- a/tests/Feature/Billing/TicketVolumeTierTest.php
+++ b/tests/Feature/Billing/TicketVolumeTierTest.php
@@ -13,9 +13,16 @@
  * - tier 解決: min_count <= count を満たす最大 min_count の current 行
  * - 該当なしは fail-closed (TicketVolumeTierUnavailableException)
  * - floor (config billing.ticket_unit_price_floor) 未満の単価は設定異常として停止
- * - TicketVolumePriceSeeder はオプトインのサンプル段 (DatabaseSeeder 非登録)
+ * - TicketVolumePriceSeeder は T007 で DatabaseSeeder に登録済み (スポット購入の bootstrap)
  */
 
+beforeEach(function (): void {
+    // T007 で TicketVolumePriceSeeder が DatabaseSeeder に登録され、全テストで 7 段が
+    // bootstrap 投入されるようになった。本テストは段構成そのものを自前で制御して
+    // 検証するため、既定行をクリアして「明示投入した段のみ」の前提を維持する。
+    TicketVolumePrice::query()->delete();
+});
+
 /** current の tier 行を明示投入する */
 function seedVolumeTier(int $minCount, int $unitAmount): TicketVolumePrice
 {
diff --git a/tests/Feature/Billing/VerifyStripePricesCommandTest.php b/tests/Feature/Billing/VerifyStripePricesCommandTest.php
index d8d1f2c..8e9117a 100644
--- a/tests/Feature/Billing/VerifyStripePricesCommandTest.php
+++ b/tests/Feature/Billing/VerifyStripePricesCommandTest.php
@@ -12,7 +12,7 @@
 /*
  * billing:verify-stripe-prices (fixture / Stripe Catalog / plan_prices の整合検証)。
  * Stripe API は呼ばない: StripePriceCatalogClient をモックして検証する。
- * fixture 側の期待値は stripe/fixtures/plan_standard.json (unit_amount=1980) に一致させる。
+ * fixture 側の期待値は stripe/fixtures/plan_standard.json (unit_amount=4980) に一致させる。
  */
 
 function verifyStandardBaseLookupKey(): string
@@ -38,7 +38,7 @@ function alignPlanPricesToStripe(): void
     PlanPrice::query()
         ->where('lookup_key', verifyStandardBaseLookupKey())
         ->where('is_current', true)
-        ->update(['stripe_price_id' => 'price_live_standard_base', 'amount' => 1980]);
+        ->update(['stripe_price_id' => 'price_live_standard_base', 'amount' => 4980]);
 }
 
 /**
@@ -48,7 +48,7 @@ function happyStripeEntries(): array
 {
     $lookupKey = verifyStandardBaseLookupKey();
 
-    return [$lookupKey => verifyEntry($lookupKey, 'price_live_standard_base', 1980)];
+    return [$lookupKey => verifyEntry($lookupKey, 'price_live_standard_base', 4980)];
 }
 
 beforeEach(function (): void {
@@ -112,7 +112,7 @@ function happyStripeEntries(): array
 test('fixture spec が Stripe Catalog と不一致なら失敗する', function (): void {
     alignPlanPricesToStripe();
     $lookupKey = verifyStandardBaseLookupKey();
-    // fixture は 1980 だが Stripe が 30000 = matchesSpec 不一致。
+    // fixture は 4980 だが Stripe が 30000 = matchesSpec 不一致。
     // plan_prices.amount も合わせて (d) でなく (a) を単独発火させる
     $entries = [$lookupKey => verifyEntry($lookupKey, 'price_live_standard_base', 30000)];
     PlanPrice::query()->where('lookup_key', $lookupKey)->where('is_current', true)->update(['amount' => 30000]);
diff --git a/tests/Feature/Inquiry/ContactUrlTest.php b/tests/Feature/Inquiry/ContactUrlTest.php
index 8ea54b4..7ddb9ba 100644
--- a/tests/Feature/Inquiry/ContactUrlTest.php
+++ b/tests/Feature/Inquiry/ContactUrlTest.php
@@ -2,6 +2,7 @@
 
 declare(strict_types=1);
 
+use App\Enums\Inquiry\InquirySource;
 use App\Services\Marketing\ContactDestinationKind;
 use App\Services\Marketing\ContactUrl;
 
@@ -49,3 +50,30 @@
             ->where('contact.url', 'https://forms.example.com/contact')
             ->where('contact.kind', ContactDestinationKind::External->value));
 });
+
+test('resolveForSource は内部 path のとき source を安全に付与する', function (?string $configured, string $expected): void {
+    config()->set('services.marketing.contact_url', $configured);
+
+    $contactUrl = new ContactUrl;
+    expect($contactUrl->resolveForSource(InquirySource::Landing))->toBe($expected);
+})->with([
+    '既定 (/contact)' => [null, '/contact?source=landing'],
+    'query 既存の内部 path' => ['/contact?foo=1', '/contact?foo=1&source=landing'],
+    'fragment 付き内部 path' => ['/contact#form', '/contact?source=landing#form'],
+    'query + fragment 付き内部 path' => ['/contact?foo=1#form', '/contact?foo=1&source=landing#form'],
+]);
+
+test('resolveForSource は外部 URL / mailto には source を付与しない (resolve と同値)', function (string $configured): void {
+    config()->set('services.marketing.contact_url', $configured);
+
+    $contactUrl = new ContactUrl;
+    expect($contactUrl->resolveForSource(InquirySource::Pricing))->toBe($configured);
+})->with([
+    'https' => ['https://forms.example.com/contact'],
+    'mailto' => ['mailto:support@example.com'],
+]);
+
+test('InquirySource::normalize は pricing を受理する (allowlist 追加)', function (): void {
+    expect(InquirySource::normalize('pricing'))->toBe(InquirySource::Pricing);
+    expect(InquirySource::Pricing->label())->toBe('料金プラン');
+});
diff --git a/tests/Feature/Marketing/LandingPageTest.php b/tests/Feature/Marketing/LandingPageTest.php
new file mode 100644
index 0000000..9191962
--- /dev/null
+++ b/tests/Feature/Marketing/LandingPageTest.php
@@ -0,0 +1,49 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Models\User;
+use Inertia\Testing\AssertableInertia as Assert;
+
+/*
+ * LP (トップ /)。HomeController が LandingPageDto (typed array) を供給する。
+ * SEO full 分類 (canonical / og / JSON-LD) の検証は SeoHeadCompositionTest。
+ */
+
+test('guest は LP の page props (signup grant / contact / 未認証) を受け取る', function (): void {
+    $this->get('/')
+        ->assertOk()
+        ->assertInertia(fn (Assert $page) => $page
+            ->component('Welcome')
+            ->where('page.signupGrantTickets', 10)
+            ->where('page.contactUrl', '/contact?source=landing')
+            ->where('page.contactIsExternal', false)
+            ->where('page.isAuthenticated', false));
+});
+
+test('認証済みユーザーには isAuthenticated=true が渡る', function (): void {
+    $user = User::factory()->create();
+
+    $this->actingAs($user)->get('/')
+        ->assertOk()
+        ->assertInertia(fn (Assert $page) => $page
+            ->component('Welcome')
+            ->where('page.isAuthenticated', true));
+});
+
+test('外部フォーム設定時は contactUrl に source を付与せず external フラグが立つ', function (): void {
+    config()->set('services.marketing.contact_url', 'https://forms.example.com/contact');
+
+    $this->get('/')
+        ->assertOk()
+        ->assertInertia(fn (Assert $page) => $page
+            ->where('page.contactUrl', 'https://forms.example.com/contact')
+            ->where('page.contactIsExternal', true));
+});
+
+test('LP の JSON-LD は Free プラン開始可能 (lowPrice 0) の offers を含む', function (): void {
+    $html = (string) $this->get('/')->getContent();
+
+    expect($html)->toContain('AggregateOffer')
+        ->toContain('"lowPrice":0');
+});
diff --git a/tests/Feature/Marketing/PricingPageTest.php b/tests/Feature/Marketing/PricingPageTest.php
new file mode 100644
index 0000000..174d679
--- /dev/null
+++ b/tests/Feature/Marketing/PricingPageTest.php
@@ -0,0 +1,102 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\Billing\PlanPriceKind;
+use App\Models\Billing\Plan;
+use App\Models\User;
+use Inertia\Testing\AssertableInertia as Assert;
+use Webmozart\Assert\Assert as WebmozartAssert;
+
+/*
+ * 公開料金表 (/pricing)。PricingController が PricingPageDto (typed array) を供給する。
+ * standard の基本料はリテラルではなく seed 済み plan_prices から導出して検証する
+ * (価格改定コミットでこのテストの修正を不要にする)。
+ */
+
+/** seed 済み standard プランの current base 額 */
+function seededStandardBaseAmount(): int
+{
+    $price = Plan::query()->where('code', 'standard')->firstOrFail()
+        ->currentPrice(PlanPriceKind::Base);
+    WebmozartAssert::notNull($price, 'standard プランの current base price が未 seed');
+
+    return $price->amount;
+}
+
+test('guest は plans (free/standard) と quota limits 反映の能力値を受け取る', function (): void {
+    $standardAmount = seededStandardBaseAmount();
+
+    $this->get('/pricing')
+        ->assertOk()
+        ->assertInertia(fn (Assert $page) => $page
+            ->component('Pricing')
+            ->has('page.plans', 2)
+            ->where('page.plans.0.code', 'free')
+            ->where('page.plans.0.baseAmountJpy', null)
+            ->where('page.plans.0.monthlyTicketGrant', 10)
+            ->where('page.plans.0.maxProjects', 1)
+            ->where('page.plans.0.maxMembers', 3)
+            ->where('page.plans.0.maxStorageGb', 1) // GiB 切り捨て規則 (intdiv(bytes, 1024**3))
+            ->where('page.plans.1.code', 'standard')
+            ->where('page.plans.1.baseAmountJpy', $standardAmount)
+            ->where('page.plans.1.monthlyTicketGrant', 100)
+            ->where('page.plans.1.maxProjects', 10)
+            ->where('page.plans.1.maxMembers', 10)
+            ->where('page.plans.1.maxStorageGb', 50)
+            ->where('page.isAuthenticated', false));
+});
+
+test('ticketTiers は seeder の 7 段が昇順で供給され spot 単価は 100 円', function (): void {
+    $this->get('/pricing')
+        ->assertOk()
+        ->assertInertia(fn (Assert $page) => $page
+            ->has('page.ticketTiers', 7)
+            ->where('page.ticketTiers.0.minCount', 1)
+            ->where('page.ticketTiers.0.unitAmount', 100)
+            ->where('page.ticketTiers.1.minCount', 20)
+            ->where('page.ticketTiers.1.unitAmount', 80)
+            ->where('page.ticketTiers.6.minCount', 500)
+            ->where('page.ticketTiers.6.unitAmount', 50)
+            ->where('page.spotUnitAmountJpy', 100));
+});
+
+test('signup grant とチケットコストの表示値は config から供給される', function (): void {
+    $this->get('/pricing')
+        ->assertOk()
+        ->assertInertia(fn (Assert $page) => $page
+            ->where('page.signupGrantTickets', 10)
+            ->where('page.signupGrantExpiryDays', 30)
+            ->where('page.analysisTicketCost', 1)
+            ->where('page.renderTicketCost', 3)
+            ->where('page.contactUrl', '/contact?source=pricing')
+            ->where('page.contactIsExternal', false));
+});
+
+test('認証済みユーザーには isAuthenticated=true が渡る', function (): void {
+    $user = User::factory()->create();
+
+    $this->actingAs($user)->get('/pricing')
+        ->assertOk()
+        ->assertInertia(fn (Assert $page) => $page
+            ->where('page.isAuthenticated', true));
+});
+
+test('/pricing は SEO full 分類でサーバ描画される (canonical + offers + index 可)', function (): void {
+    config([
+        'seo.base_url' => 'https://app.example',
+        'seo.site_name' => 'Acme',
+        'seo.default_title' => 'Acme',
+        'seo.title_separator' => ' | ',
+    ]);
+
+    $response = $this->get('/pricing');
+    $response->assertOk();
+    $html = (string) $response->getContent();
+
+    expect($html)->toContain('<title>料金プラン | Acme</title>')
+        ->toContain('<link rel="canonical" href="https://app.example/pricing">')
+        ->toContain('"@type":"SoftwareApplication"')
+        ->toContain('AggregateOffer')
+        ->and($html)->not->toContain('noindex');
+});
diff --git a/tests/Feature/Seo/SeoHeadCompositionTest.php b/tests/Feature/Seo/SeoHeadCompositionTest.php
index d6efef7..3db6f57 100644
--- a/tests/Feature/Seo/SeoHeadCompositionTest.php
+++ b/tests/Feature/Seo/SeoHeadCompositionTest.php
@@ -39,10 +39,12 @@
         ->and($html)->not->toContain('noindex');
 });
 
-it('home の SoftwareApplication は価格未確定 (null) のため offers を出さない', function (): void {
+it('home の SoftwareApplication は Free プラン開始可能 (lowPrice 0) の offers を出す', function (): void {
+    // T007: LP の「無料開始」訴求と JSON-LD を一致させる (lowPriceJpy=0 供給)
     $html = (string) $this->get('/')->getContent();
 
-    expect($html)->not->toContain('AggregateOffer');
+    expect($html)->toContain('AggregateOffer')
+        ->toContain('"lowPrice":0');
 });
 
 it('認証配下ページは noindex + per-page title のみで canonical / og を漏らさない', function (): void {
diff --git a/tests/Support/FakeTicketCheckoutGateway.php b/tests/Support/FakeTicketCheckoutGateway.php
new file mode 100644
index 0000000..70cb8ba
--- /dev/null
+++ b/tests/Support/FakeTicketCheckoutGateway.php
@@ -0,0 +1,71 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support;
+
+use App\DataTransferObjects\Billing\CreatedCheckoutSession;
+use App\Models\Organization;
+use App\Services\Billing\TicketCheckoutGateway;
+use Carbon\CarbonImmutable;
+use RuntimeException;
+
+/**
+ * TicketCheckoutGateway のテスト用 fake (Stripe に到達しない)。
+ *
+ * - createTicketCheckout: 呼び出しを記録し、idempotency key から決定的な
+ *   session id / URL を返す (Stripe の idempotency replay と同じ収束特性を再現)
+ * - expireCheckoutSession: 呼び出しを記録し、$expireResult を返す
+ */
+final class FakeTicketCheckoutGateway implements TicketCheckoutGateway
+{
+    /** @var list<array{organizationId: int, stripePriceId: string, quantity: int, idempotencyKey: string, metadata: array<string, string>}> */
+    public array $created = [];
+
+    /** @var list<string> expire を要求された session id */
+    public array $expired = [];
+
+    /** expireCheckoutSession の返り値 ('expired' / 'complete' 等) */
+    public string $expireResult = 'expired';
+
+    /** true にすると createTicketCheckout が throw する (Stripe 障害の再現) */
+    public bool $failOnCreate = false;
+
+    public function createTicketCheckout(
+        Organization $organization,
+        string $stripePriceId,
+        int $quantity,
+        string $successUrl,
+        string $cancelUrl,
+        string $idempotencyKey,
+        array $metadata,
+    ): CreatedCheckoutSession {
+        if ($this->failOnCreate) {
+            throw new RuntimeException('fake gateway: create 失敗');
+        }
+
+        $this->created[] = [
+            'organizationId' => $organization->id,
+            'stripePriceId' => $stripePriceId,
+            'quantity' => $quantity,
+            'idempotencyKey' => $idempotencyKey,
+            'metadata' => $metadata,
+        ];
+
+        // idempotency key から決定的に導出 (同一 attempt の再送は同一 session に収束)
+        $token = str_replace('purchase:', '', $idempotencyKey);
+
+        return new CreatedCheckoutSession(
+            sessionId: "cs_test_{$token}",
+            url: "https://checkout.stripe.test/c/pay/cs_test_{$token}",
+            expiresAt: CarbonImmutable::now()->addDay(),
+        );
+    }
+
+    public function expireCheckoutSession(string $sessionId): string
+    {
+        $this->expired[] = $sessionId;
+
+        return $this->expireResult;
+    }
+}
diff --git a/tests/js/components/features/manual/AnalysisPanel.test.ts b/tests/js/components/features/manual/AnalysisPanel.test.ts
index 42d39df..10e4886 100644
--- a/tests/js/components/features/manual/AnalysisPanel.test.ts
+++ b/tests/js/components/features/manual/AnalysisPanel.test.ts
@@ -8,7 +8,9 @@ const { routerReloadMock } = vi.hoisted(() => ({
     routerReloadMock: vi.fn(),
 }));
 
-vi.mock("@inertiajs/svelte", () => ({
+// Link (TextLink 経由) は実物を使い、router のみ差し替える
+vi.mock("@inertiajs/svelte", async (importOriginal) => ({
+    ...(await importOriginal<typeof import("@inertiajs/svelte")>()),
     router: {
         reload: routerReloadMock,
     },
@@ -98,6 +100,32 @@ describe("AnalysisPanel", () => {
             );
         });
         expect(screen.getByTestId("analyze-button")).not.toBeDisabled();
+        // T007: 残高不足 (code 厳格一致) では購入導線を併記する
+        expect(screen.getByTestId("analysis-purchase-link")).toBeInTheDocument();
+        expect(
+            new URL(
+                (screen.getByTestId("analysis-purchase-link") as HTMLAnchorElement).href,
+            ).pathname,
+        ).toBe("/purchase-tickets");
+    });
+
+    it("insufficient_tickets 以外の 402/409 では購入導線を出さない (誤表示防止)", async () => {
+        fetchMock.mockResolvedValue(
+            jsonResponse(409, {
+                code: "analysis_conflict",
+                message: "解析が進行中です。",
+            }),
+        );
+
+        render(AnalysisPanel, { props: baseProps });
+        await fireEvent.click(screen.getByTestId("analyze-button"));
+
+        await waitFor(() => {
+            expect(screen.getByTestId("analysis-start-error")).toHaveTextContent(
+                "解析が進行中です。",
+            );
+        });
+        expect(screen.queryByTestId("analysis-purchase-link")).toBeNull();
     });
 
     it("422 (手順書なし) もサーバの message を表示する (disabled にしない)", async () => {
diff --git a/tests/js/components/features/manual/RenderPanel.test.ts b/tests/js/components/features/manual/RenderPanel.test.ts
index 91e430d..09bd60a 100644
--- a/tests/js/components/features/manual/RenderPanel.test.ts
+++ b/tests/js/components/features/manual/RenderPanel.test.ts
@@ -8,7 +8,9 @@ const { routerReloadMock } = vi.hoisted(() => ({
     routerReloadMock: vi.fn(),
 }));
 
-vi.mock("@inertiajs/svelte", () => ({
+// Link (TextLink 経由) は実物を使い、router のみ差し替える
+vi.mock("@inertiajs/svelte", async (importOriginal) => ({
+    ...(await importOriginal<typeof import("@inertiajs/svelte")>()),
     router: {
         reload: routerReloadMock,
     },
@@ -137,6 +139,13 @@ describe("RenderPanel", () => {
         });
         // 押下可能なまま (disabled にしない)
         expect(screen.getByTestId("render-button")).toBeInTheDocument();
+        // T007: 残高不足 (code 厳格一致) では購入導線を併記する
+        expect(screen.getByTestId("render-purchase-link")).toBeInTheDocument();
+        expect(
+            new URL(
+                (screen.getByTestId("render-purchase-link") as HTMLAnchorElement).href,
+            ).pathname,
+        ).toBe("/purchase-tickets");
     });
 
     it("rendering 中は step ラベルと progress を表示する", () => {
diff --git a/tests/js/components/molecules/PricingPlanCard.test.ts b/tests/js/components/molecules/PricingPlanCard.test.ts
new file mode 100644
index 0000000..00c8caf
--- /dev/null
+++ b/tests/js/components/molecules/PricingPlanCard.test.ts
@@ -0,0 +1,55 @@
+import { describe, expect, it } from "vitest";
+import { render, screen } from "@testing-library/svelte";
+import { createRawSnippet } from "svelte";
+import PricingPlanCard from "@/components/molecules/PricingPlanCard.svelte";
+
+/*
+ * PricingPlanCard: priceAmount null (plan_prices base なし = 無料プラン) と 0 は
+ * どちらも「無料」表示 (DTO 契約と対)。有償は ¥N + suffix + priceCaption。
+ */
+
+const footerCta = createRawSnippet(() => ({
+    render: () => "<button type='button'>CTA</button>",
+}));
+
+function renderCard(priceAmount: number | null): void {
+    render(PricingPlanCard, {
+        props: {
+            name: "テストプラン",
+            priceAmount,
+            priceCaption: "基本料金",
+            features: [{ text: "特典 A" }],
+            testId: "plan-card",
+            footerCta,
+        },
+    });
+}
+
+describe("PricingPlanCard", () => {
+    it("priceAmount=null は「無料」を掲示し価格 caption を出さない", () => {
+        renderCard(null);
+
+        const card = screen.getByTestId("plan-card");
+        expect(card).toHaveTextContent("無料");
+        expect(card).not.toHaveTextContent("¥");
+        expect(screen.queryByTestId("price-caption")).toBeNull();
+    });
+
+    it("priceAmount=0 も防御的に「無料」を掲示する", () => {
+        renderCard(0);
+
+        const card = screen.getByTestId("plan-card");
+        expect(card).toHaveTextContent("無料");
+        expect(card).not.toHaveTextContent("¥");
+    });
+
+    it("有償プランは ¥N／月 と priceCaption を掲示する", () => {
+        renderCard(4980);
+
+        const card = screen.getByTestId("plan-card");
+        expect(card).toHaveTextContent("¥4,980");
+        expect(card).toHaveTextContent("／月");
+        expect(screen.getByTestId("price-caption")).toHaveTextContent("基本料金");
+        expect(card).toHaveTextContent("特典 A");
+    });
+});
diff --git a/tests/js/pages/Pricing.test.ts b/tests/js/pages/Pricing.test.ts
new file mode 100644
index 0000000..f2eb38a
--- /dev/null
+++ b/tests/js/pages/Pricing.test.ts
@@ -0,0 +1,113 @@
+import { describe, expect, it } from "vitest";
+import { fireEvent, render, screen } from "@testing-library/svelte";
+import Pricing from "@/pages/Pricing.svelte";
+import type { PricingPageProps } from "@/types/marketing";
+
+/*
+ * 公開料金表。free (baseAmountJpy=null) の「無料」表示・standard の月額表示・
+ * チケット帯変換・FAQ 開閉・disabled 不使用を固定する。
+ */
+
+const basePage: PricingPageProps = {
+    plans: [
+        {
+            code: "free",
+            name: "Free",
+            baseAmountJpy: null,
+            monthlyTicketGrant: 10,
+            maxProjects: 1,
+            maxMembers: 3,
+            maxStorageGb: 1,
+        },
+        {
+            code: "standard",
+            name: "Standard",
+            baseAmountJpy: 4980,
+            monthlyTicketGrant: 100,
+            maxProjects: 10,
+            maxMembers: 10,
+            maxStorageGb: 50,
+        },
+    ],
+    ticketTiers: [
+        { minCount: 1, unitAmount: 100 },
+        { minCount: 20, unitAmount: 80 },
+        { minCount: 50, unitAmount: 70 },
+        { minCount: 100, unitAmount: 65 },
+        { minCount: 200, unitAmount: 60 },
+        { minCount: 300, unitAmount: 55 },
+        { minCount: 500, unitAmount: 50 },
+    ],
+    spotUnitAmountJpy: 100,
+    signupGrantTickets: 10,
+    signupGrantExpiryDays: 30,
+    analysisTicketCost: 1,
+    renderTicketCost: 3,
+    isAuthenticated: false,
+    contactUrl: "/contact?source=pricing",
+    contactIsExternal: false,
+};
+
+describe("Pricing", () => {
+    it("プランカード 2 枚を描画し free は「無料」、standard は月額を表示する", () => {
+        render(Pricing, { props: { page: basePage } });
+
+        const freeCard = screen.getByTestId("pricing-plan-free");
+        expect(freeCard).toHaveTextContent("無料");
+        expect(freeCard).not.toHaveTextContent("¥");
+
+        const standardCard = screen.getByTestId("pricing-plan-standard");
+        expect(standardCard).toHaveTextContent("¥4,980");
+        expect(standardCard).toHaveTextContent("基本料金");
+        expect(standardCard).toHaveTextContent("月 100 枚のチケット付与");
+        expect(standardCard).toHaveTextContent("ストレージ 50 GB");
+    });
+
+    it("チケット帯 (X〜Y 枚 / 最終段 X 枚以上) と signup grant 注記を描画する", () => {
+        render(Pricing, { props: { page: basePage } });
+
+        const table = screen.getByTestId("ticket-tier-table");
+        expect(table).toHaveTextContent("1〜19 枚");
+        expect(table).toHaveTextContent("20〜49 枚");
+        expect(table).toHaveTextContent("¥80 ／ 枚");
+        expect(table).toHaveTextContent("500 枚以上");
+        expect(table).toHaveTextContent("¥50 ／ 枚");
+
+        expect(screen.getByTestId("signup-grant-note")).toHaveTextContent(
+            "新規契約でチケット 10 枚が無料でついてきます (付与から 30 日間有効)",
+        );
+    });
+
+    it("FAQ は aria-expanded 付きの button で開閉できる", async () => {
+        render(Pricing, { props: { page: basePage } });
+
+        const question = screen.getByRole("button", { name: /無料で試せますか/ });
+        expect(question).toHaveAttribute("aria-expanded", "false");
+
+        await fireEvent.click(question);
+        expect(question).toHaveAttribute("aria-expanded", "true");
+        expect(
+            screen.getByText(/Free プランは基本料金なしでご利用いただけます/),
+        ).toBeInTheDocument();
+
+        await fireEvent.click(question);
+        expect(question).toHaveAttribute("aria-expanded", "false");
+    });
+
+    it("未認証は登録 CTA、認証済みはプラン変更 CTA を出す", () => {
+        const { unmount } = render(Pricing, { props: { page: basePage } });
+        expect(screen.getAllByRole("link", { name: "このプランで始める" })).toHaveLength(2);
+        unmount();
+
+        render(Pricing, {
+            props: { page: { ...basePage, isAuthenticated: true } },
+        });
+        expect(screen.getAllByRole("link", { name: "プランを変更" })).toHaveLength(2);
+    });
+
+    it("disabled 属性を持つ button が存在しない (DESIGN.md)", () => {
+        const { container } = render(Pricing, { props: { page: basePage } });
+
+        expect(container.querySelectorAll("button[disabled]")).toHaveLength(0);
+    });
+});
diff --git a/tests/js/pages/PurchaseTickets.test.ts b/tests/js/pages/PurchaseTickets.test.ts
new file mode 100644
index 0000000..6df72f4
--- /dev/null
+++ b/tests/js/pages/PurchaseTickets.test.ts
@@ -0,0 +1,136 @@
+import { afterEach, describe, expect, it, vi } from "vitest";
+import { cleanup, fireEvent, render, screen } from "@testing-library/svelte";
+import PurchaseTickets from "@/pages/Billing/PurchaseTickets.svelte";
+import type { PurchaseTicketsPageProps } from "@/types/billing";
+
+// router.post をモックし page state は実物を使う (props 未設定の空オブジェクト)
+const { routerPostMock } = vi.hoisted(() => ({
+    routerPostMock: vi.fn(),
+}));
+
+vi.mock("@inertiajs/svelte", async (importOriginal) => ({
+    ...(await importOriginal<typeof import("@inertiajs/svelte")>()),
+    router: {
+        post: routerPostMock,
+    },
+}));
+
+/*
+ * チケット購入画面。傾斜単価の即時計算・role-aware 案内・purchased バナー・
+ * disabled 不使用 (DESIGN.md) を固定する。
+ */
+
+const basePage: PurchaseTicketsPageProps = {
+    tiers: [
+        { minCount: 1, unitAmount: 100 },
+        { minCount: 20, unitAmount: 80 },
+        { minCount: 50, unitAmount: 70 },
+        { minCount: 100, unitAmount: 65 },
+        { minCount: 200, unitAmount: 60 },
+        { minCount: 300, unitAmount: 55 },
+        { minCount: 500, unitAmount: 50 },
+    ],
+    minCount: 1,
+    maxCount: 1000,
+    defaultCount: 10,
+    balance: 3,
+    canManage: true,
+    attemptToken: "01J0000000000000000000TEST",
+    purchased: false,
+};
+
+afterEach(() => {
+    cleanup();
+    routerPostMock.mockReset();
+});
+
+async function setCount(value: string): Promise<void> {
+    const input = screen.getByTestId("ticket-count-input");
+    await fireEvent.input(input, { target: { value } });
+}
+
+describe("Billing/PurchaseTickets", () => {
+    it("残高と傾斜表・既定枚数の単価計算 (10 枚 → spot ¥100) を表示する", () => {
+        render(PurchaseTickets, { props: { page: basePage } });
+
+        expect(screen.getByTestId("purchase-balance-count")).toHaveTextContent("3 枚");
+        expect(screen.getByTestId("purchase-tier-table")).toHaveTextContent("1〜19 枚");
+        expect(screen.getByTestId("purchase-total")).toHaveTextContent(
+            "単価 ¥100 × 10 枚 = 合計 ¥1,000",
+        );
+    });
+
+    it("枚数に応じて適用単価が切り替わる (19→¥100 / 20→¥80 / 500→¥50)", async () => {
+        render(PurchaseTickets, { props: { page: basePage } });
+
+        await setCount("19");
+        expect(screen.getByTestId("purchase-total")).toHaveTextContent(
+            "単価 ¥100 × 19 枚 = 合計 ¥1,900",
+        );
+
+        await setCount("20");
+        expect(screen.getByTestId("purchase-total")).toHaveTextContent(
+            "単価 ¥80 × 20 枚 = 合計 ¥1,600",
+        );
+
+        await setCount("500");
+        expect(screen.getByTestId("purchase-total")).toHaveTextContent(
+            "単価 ¥50 × 500 枚 = 合計 ¥25,000",
+        );
+    });
+
+    it("範囲外は合計を出さず、押下時にエラー表示して送信しない (disabled にしない)", async () => {
+        render(PurchaseTickets, { props: { page: basePage } });
+
+        await setCount("1001");
+        expect(screen.getByTestId("purchase-total")).toHaveTextContent("合計 —");
+
+        const submit = screen.getByTestId("purchase-submit");
+        expect(submit.hasAttribute("disabled")).toBe(false);
+
+        await fireEvent.click(submit);
+        expect(routerPostMock).not.toHaveBeenCalled();
+        expect(
+            screen.getByText("購入枚数は 1〜1000 の整数で入力してください"),
+        ).toBeInTheDocument();
+    });
+
+    it("妥当な枚数の押下で count + attempt_token を POST する", async () => {
+        render(PurchaseTickets, { props: { page: basePage } });
+
+        await setCount("30");
+        await fireEvent.click(screen.getByTestId("purchase-submit"));
+
+        expect(routerPostMock).toHaveBeenCalledTimes(1);
+        const [url, payload] = routerPostMock.mock.calls[0] as [string, Record<string, unknown>];
+        expect(url).toBe("/purchase-tickets/checkout");
+        expect(payload).toEqual({
+            count: 30,
+            attempt_token: "01J0000000000000000000TEST",
+        });
+    });
+
+    it("canManage=false では購入フォームの代わりに管理者依頼の案内を出す", () => {
+        render(PurchaseTickets, {
+            props: { page: { ...basePage, canManage: false } },
+        });
+
+        expect(screen.queryByTestId("purchase-form")).toBeNull();
+        expect(screen.getByTestId("purchase-role-note")).toHaveTextContent(
+            "チケットの購入は組織のオーナーまたは管理者が行えます",
+        );
+        // 透明性維持: 残高・料金表は表示する
+        expect(screen.getByTestId("purchase-balance-count")).toBeInTheDocument();
+        expect(screen.getByTestId("purchase-tier-table")).toBeInTheDocument();
+    });
+
+    it("purchased=true で反映待ちバナーを表示する", () => {
+        render(PurchaseTickets, {
+            props: { page: { ...basePage, purchased: true } },
+        });
+
+        expect(screen.getByTestId("purchase-success-banner")).toHaveTextContent(
+            "決済の確認後、残高に反映されます",
+        );
+    });
+});
diff --git a/tests/js/pages/Welcome.test.ts b/tests/js/pages/Welcome.test.ts
index da8823a..0e162d2 100644
--- a/tests/js/pages/Welcome.test.ts
+++ b/tests/js/pages/Welcome.test.ts
@@ -1,11 +1,89 @@
 import { describe, expect, it } from "vitest";
 import { render, screen } from "@testing-library/svelte";
 import Welcome from "@/pages/Welcome.svelte";
+import type { LandingPageProps } from "@/types/marketing";
 
-describe("Welcome", () => {
-    it("アプリ名を表示する", () => {
-        render(Welcome, { props: { appName: "My App" } });
+/*
+ * LP (トップ)。North Star (SOP → AI カット設計 → PWA ナビ撮影 → 自動合成) の訴求と
+ * 認証状態別 CTA、disabled 不使用 (DESIGN.md) を固定する。
+ */
 
-        expect(screen.getByRole("heading", { name: "My App" })).toBeInTheDocument();
+const guestPage: LandingPageProps = {
+    signupGrantTickets: 10,
+    contactUrl: "/contact?source=landing",
+    contactIsExternal: false,
+    isAuthenticated: false,
+};
+
+const baseProps = { appName: "AI-CUE", page: guestPage };
+
+describe("Welcome (LP)", () => {
+    it("hero 見出しと登録 CTA・仕組みリンクを描画する", () => {
+        render(Welcome, { props: baseProps });
+
+        expect(screen.getByRole("heading", { level: 1 })).toHaveTextContent(
+            "動画マニュアルを、手順書から。",
+        );
+        expect(screen.getByTestId("hero-register")).toBeInTheDocument();
+        expect(screen.getByRole("link", { name: /仕組みを見る/ })).toBeInTheDocument();
+    });
+
+    it("3 つの壁と 4 ステップ・料金 CTA (signup grant 枚数) を描画する", () => {
+        render(Welcome, { props: baseProps });
+
+        expect(screen.getByRole("heading", { name: "台本作成の壁" })).toBeInTheDocument();
+        expect(screen.getByRole("heading", { name: "撮影判断の壁" })).toBeInTheDocument();
+        expect(screen.getByRole("heading", { name: "編集の壁" })).toBeInTheDocument();
+
+        expect(screen.getByRole("heading", { name: "手順書をアップロード" })).toBeInTheDocument();
+        expect(screen.getByRole("heading", { name: "AI がカットを設計" })).toBeInTheDocument();
+        expect(screen.getByRole("heading", { name: "スマホでナビ撮影" })).toBeInTheDocument();
+        expect(screen.getByRole("heading", { name: "自動で動画に合成" })).toBeInTheDocument();
+
+        expect(screen.getByTestId("landing-pricing-cta")).toHaveTextContent(
+            "新規登録でチケット 10 枚が無料",
+        );
+        expect(screen.getByRole("link", { name: /料金プランを見る/ })).toBeInTheDocument();
+    });
+
+    it("未認証では登録 CTA、認証済みではダッシュボード CTA を出す", () => {
+        const { unmount } = render(Welcome, { props: baseProps });
+        expect(screen.getAllByRole("link", { name: "無料で始める" }).length).toBeGreaterThan(0);
+        unmount();
+
+        render(Welcome, {
+            props: { ...baseProps, page: { ...guestPage, isAuthenticated: true } },
+        });
+        expect(screen.getAllByRole("link", { name: /ダッシュボードへ/ }).length).toBeGreaterThan(
+            0,
+        );
+    });
+
+    it("問い合わせリンクは内部宛先では同タブ、外部宛先では新規タブで開く", () => {
+        const { unmount } = render(Welcome, { props: baseProps });
+        const internal = screen.getByTestId("landing-contact-link");
+        expect(new URL((internal as HTMLAnchorElement).href).pathname).toBe("/contact");
+        expect(internal).not.toHaveAttribute("target");
+        unmount();
+
+        render(Welcome, {
+            props: {
+                ...baseProps,
+                page: {
+                    ...guestPage,
+                    contactUrl: "https://forms.example.com/contact",
+                    contactIsExternal: true,
+                },
+            },
+        });
+        const external = screen.getByTestId("landing-contact-link");
+        expect(external).toHaveAttribute("target", "_blank");
+        expect(external).toHaveAttribute("rel", "noopener noreferrer");
+    });
+
+    it("disabled 属性を持つ button が存在しない (DESIGN.md)", () => {
+        const { container } = render(Welcome, { props: baseProps });
+
+        expect(container.querySelectorAll("button[disabled]")).toHaveLength(0);
     });
 });
```
