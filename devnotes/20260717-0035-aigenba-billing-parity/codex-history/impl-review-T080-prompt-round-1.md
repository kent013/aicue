# 実装レビュー依頼: T080 (決済 parity P8b — 課金 UI parity + 監査「判断不要 15 件」の消化)

## アプリの使命 (North Star)

（AGENTS.md「使命 (North Star)」より）

## 使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。


## 禁止事項 / セキュリティ不変条件

（AGENTS.md「禁止事項」「セキュリティ不変条件」より）

## 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。
   招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)

## セキュリティ不変条件(アプリ都合で緩めない)

詳細と実装手順は `docs/app-integration-guide.md` §7。すべて Architecture テストで強制されている:

1. **tenant キー不信**: ownership/actor/tenant キーを payload から受け取らない
   (`ProhibitsProtectedKeys` + `MassAssignmentSafetyTest`)
2. **子は親に属する**: nested route の不整合は**認可より前に 404**
   (`NestedRouteIdorDefenseTest` の inventory に登録必須)
3. **cross-org 不可**: 組織を跨ぐ read/write をしない(relation / org-scoped 解決経由のみ)
4. **untrusted 文字列は UserInput 型経由でのみ prompt に入れる**
5. **権限判定は常に `laratrust_team_id` を明示**(strict_check=true)
6. **PII(email/name)は CipherSweet**。検索は `whereBlind()`(平文 where は hit しない)
7. **課金の冪等性**: webhook は冪等マシン経由、チケットは reserve→commit/release の 2 フェーズ
8. **外部 URL 取得は SSRF 検査経由**: 外部 URL(特にユーザ入力由来)を取得する機能は
   必ず `Kent013\SsrfPin\UrlSafetyInspector` / `PinnedHttpClient` を通す。
   安全境界は `config/ssrf-pin.php` に pin する(`SsrfPinBoundaryTest` が pin 値を固定)


## 思考原則 — 全議論に適用

まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

## ツール使用制限

コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

## あなたの役割

あなたは AI-CUE (Laravel 12 + Inertia + Svelte 5 runes + Tailwind v4 DS token) の実装レビュアーである。
以下の **差分 (git diff main...HEAD)** を、**設計書の P8b 節**（Codex 合議 16 ラウンドで APPROVED 済み・逸脱不可）に照らしてレビューせよ。

### 出力形式

指摘は重大度別に分類し、各指摘に「根拠 (どのファイル・どの行・設計書のどの記述)」「具体的な失敗シナリオ」「修正案」を必ず添えること。

- `[Critical]` — 設計違反 / 禁止事項・セキュリティ不変条件抵触 / 実害のあるバグ / 後退 (regression) / テストが不変条件を固定できていない (空振り)
- `[Warning]` — 実害はまだ無いが将来事故る設計上の弱点・保守性・カバレッジ欠落
- `[Suggestion]` — 好み・軽微な改善

**推測で Critical を積まないこと**。差分に無い既存コードの挙動が根拠として必要な場合は、リポジトリ内のファイルを読んで確認してから断定せよ（読み込みは許可されている）。確認できない場合は「未確認の仮説」と明示せよ。

### レビュー観点 (優先順)

1. **設計どおりか**: P8b 節の (a)〜(e)・波及変更・主要な契約・テスト計画に対する充足度。**逸脱があれば「設計のどの記述に対して何が違うか」を明示**する。
2. **所管境界**: P9 所管 (checkout 着地 feedback / `resolveBillingFeedback` / 請求先 billingContact / subscription 用 attempt token) に手を出していないか。P8a の auto-recharge カード実体 (`AutoRechargeCard` / `AutoRechargeSettingsDto` / setup token) を壊していないか。会計 (`TicketLedgerService`) と Stripe 境界 (`*Gateway`) に触れていないか。
3. **禁止事項**: 特に **#8 (必須条件未充足を理由に button を disabled にしない)**、#4 (`response()->json()` 直書き禁止 = Inertia/DTO 経由)。
4. **セキュリティ不変条件**: cross-org / cross-user のデータ露出 (特に `resumeUrl` = Stripe Checkout 直リンク)、認可の抜け (`Gate::authorize` / `canManage` の出し分け)、tenant キー不信。
5. **PHPStan level 10 適合**: `@phpstan-type` / `@phpstan-import-type` の整合、mixed 残り、union 戻り値。widen / baseline / `@phpstan-ignore` は禁止 (差分に無いことの確認)。
6. **テストが不変条件を実際に固定しているか (空振りしていないか)**: assertion が緩すぎないか、fixture が意図した状態を作れているか、**削除・弱体化された既存 assertion が無いか**。設計のテスト計画に列挙された項目のうち**実装されていないもの**があれば指摘せよ。
7. **副作用・後退リスク**: props 一括変更 (`Billing/Index` の 4 props → `page` 1 本)、`Pricing.svelte` → `Guest/Pricing.svelte` 移動、`attemptToken` → `ticketAttemptToken` 改名、`Billing/Index` からのプラン一覧撤去の参照喪失。
8. **フロント規約**: DS token 経由か (hex 直書きを増やしていないか)、component 階層の単方向 import (`atoms → molecules → organisms → features/{domain} → templates → pages`。`pages/Billing/_helpers/` は pages 層) を破っていないか、アイコンは `@lucide/svelte` のみか。

### 実装者が自認している論点 (賛否と見落としを述べよ)

以下は実装者 (Claude) が差分作成後に自分で見つけた懸念である。**各々について「Critical/Warning/Suggestion/問題なし」の判定と根拠**を述べ、さらに**見落としている論点**を挙げよ。

1. **決済成功着地と `formState='resume'` の衝突**: Stripe から `/purchase-tickets?purchased=1&session_id=...` へ戻った直後、webhook 未達なら当該 session はまだ `Pending`(live) のため `formState='resume'` になり、「決済手続きが進行中です／前回の決済を続けるか、新しく購入し直してください」という警告バナー + 「決済を続ける」CTA (支払い済み Stripe session への直リンク) が、成功バナー (`purchased`) と**同時に**出る。移植元 aigenba も同型 (aigenba は完了 acknowledgment を `?session_id` 着地 feedback = AI-CUE では P9 所管 に委ねている)。設計書は `purchased` との相互作用に言及していない。これは UX 破綻/誤操作誘発として Critical か、P9 まで許容される Warning か。
2. **`resolveResumablePurchase` の 2 段目 (completed 窓)**: 設計は aigenba `TicketService.php:1393-1417` の「2 段取得」を verbatim と規定するが、**移植元の現行実装は completed 段を撤去済み** (`/tmp/aigenba/app/Services/Billing/TicketService.php:1384-1406` のコメント「T905 で存在した『完了 session を窓内で completed 状態に写像する』第 2 段は撤去した」)。実装は設計どおり 2 段で書いている。設計 (承認済み) に従うのが正か、移植元現行に合わせるべきか。
3. **`resolveResumablePurchase` が model の `isLivePending()` を使わず SQL 条件を再実装**している (設計は「live pending 判定は既存 `TicketCheckoutSession::isLivePending()` を使う」と記述)。判定式は等価か (`status=Pending && expires_at > now` + `checkout_url <> ''`)、二重定義として将来の乖離リスクか。
4. **`billing.plans` が課金ゲート allowlist (route group 外配置) に入ったことを固定するテストが無い**: 既存の `tests/Feature/Billing/GateInversionF07RegressionTest.php` の「(d) 遮断先および課金系 route は gate group 外で再遮断されない」dataset は `onboarding.checkout` / `billing.index` / `billing.tickets.show` / `notifications.index` のみで、`billing.plans` が未登録。`BillingPlansPageTest` にも「未契約 (NoSubscription) org が GET /billing/plans で 200」を固定するケースが無い。構造的 allowlist が将来壊れても検知できないのでは。
5. **`BillingController::resolveCurrentPlan()` が `PricingService::listPublicPlans()` からの線形探索**である: `is_active=false` に落とされた (= 非公開化された) プランを契約中の org では `plan` が `null` になり、`Billing/Index` が「まだプランに契約していません。」と表示する。契約中の組織に未契約と表示するのは誤情報ではないか。
6. **`Billing/Index.svelte` の月額表示**: `formatYen(null)` が `"—"` を返すため、`billingState !== 'active_free_plan'` かつ `baseAmountJpy === null` のプランでは「月額 ¥—」という壊れた表示になりうる。
7. **`Billing/Plans.svelte` のサーバ validation エラー表示**: `planCodeError` は Inertia の共有 `errors.plan_code` から derive しているため、プラン A で失敗した後に dialog を閉じてプラン B を開くと**古いエラーが出たまま**になる。また ConfirmDialog の `message` は `planNameOf(confirmingPlanCode ?? "")` で組むため、閉じる瞬間に `confirmingPlanCode=null` になり文言が「プランを「」に変更します」に一瞬なる。
8. **`ConfirmDialog` に `banner?: Snippet` を additive 追加**した (organisms の共通部品。未指定時の出力は不変)。DESIGN.md §Components > ConfirmDialog 本文には追記していない (仕様の真実は `ConfirmDialog.types.ts` と DESIGN.md が規定しているため types 側に記述)。この扱いで妥当か。
9. **bug-hunt インベントリ**: `.claude/skills/app-bug-hunt/screens.md` に `billing/plans` を追加したが、`stories/S5-billing.md` の記述「billing.index → 現在のプラン・チケット残高・**月次付与枚数**が表示」は D28 (月次付与廃止) と P8b の Index 再構成で陳腐化している。

### 検証コマンドの実行結果 (実装者申告。全 green)

```
composer test        : pass (2388 tests / 2386 passed / 2 skipped / 9622 assertions)
composer phpstan     : pass (730/730 files, [OK] No errors, baseline 追加なし)
vendor/bin/pint --test: pass
pnpm lint            : pass
pnpm typecheck       : pass (tsc --noEmit)
pnpm test            : pass (97 files / 879 tests)
pnpm build           : pass
```

### 前提 (P1〜P8a はマージ済み)

- `BillingAccess::state()` が 5 状態 (`Subscribed`/`ActiveFreePlan`/`PendingCheckout`/`ExpiredCheckout`/`NoSubscription`) を返し、`hasActiveAccess()` は `state()->grantsAccess()` 一本。
- 無料枠は `organizations.free_plan_code='personal'` の明示申告。`plans` から `free` 行は撤去済み。D28 により全 tier `monthly_ticket_grant = 0`。
- `TicketLedgerService::balance(): TicketBalanceDto` が per-source 残高 (`monthlyRemaining` / `purchasedRemaining` / `totalAvailable` / `activeReservations` / `nextExpireAt`。**debt は存在しない**) を返す。
- テスト helper `createOrganizationWithOwner(name, grandfatherFreePlan: true)` の既定は「無料枠付与済み (= ActiveFreePlan)」。未契約を検証したいテストは `grandfatherFreePlan: false` を明示する。
- 課金ゲート (`RequireActiveSubscription`) の allowlist は **route group 外配置による構造的 allowlist** (middleware 内の名前リストではない)。

---

## 差分 (git diff main...HEAD)

```diff
diff --git a/.claude/skills/app-bug-hunt/screens.md b/.claude/skills/app-bug-hunt/screens.md
index 747942f..ab9aace 100644
--- a/.claude/skills/app-bug-hunt/screens.md
+++ b/.claude/skills/app-bug-hunt/screens.md
@@ -15,6 +15,7 @@ ## 画面一覧
 | app/projects/{project}/manuals/{manual} | capture.manuals.show | S3 |
 | app/projects/{project}/manuals/{manual}/cuts/{cut}/takes/{take}/playback | capture.takes.playback | S3 |
 | billing | billing.index | S5 |
+| billing/plans | billing.plans | S5 |
 | commerce-disclosure | legal.commerce-disclosure | S1 |
 | contact | contact | S1 |
 | contact/thanks | contact.thanks | S1 |
diff --git a/app/DataTransferObjects/Billing/BillingDashboardDto.php b/app/DataTransferObjects/Billing/BillingDashboardDto.php
new file mode 100644
index 0000000..fac4c9f
--- /dev/null
+++ b/app/DataTransferObjects/Billing/BillingDashboardDto.php
@@ -0,0 +1,75 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\DataTransferObjects\Billing;
+
+use App\DataTransferObjects\Marketing\PricingPlanDto;
+use App\Enums\Billing\OnboardingBillingState;
+
+/**
+ * 課金ダッシュボード (/billing) の Inertia page prop (P8b / bs-14)。
+ *
+ * プラン一覧は /billing/plans へ移設済み。ここは「現在のプラン / per-bucket 残高 /
+ * 現行 quota 上限 / 導線」に絞る。plan は表示用の解決結果 (ActiveFreePlan なら
+ * free_plan_code、それ以外は plan_code。gate 判定には使わない)。
+ *
+ * P9 は本 DTO へ additive に feedback / billingContact を足す (placeholder は先置きしない)。
+ *
+ * TS 側は resources/js/types/billing.ts の BillingDashboardProps と exact 対で保守する。
+ *
+ * @phpstan-import-type PricingPlanShape from PricingPlanDto
+ * @phpstan-import-type TicketBalanceShape from TicketBalanceDto
+ * @phpstan-import-type QuotaLimitsShape from QuotaLimitsDto
+ * @phpstan-import-type AutoRechargeShape from AutoRechargeSettingsDto
+ *
+ * @phpstan-type BillingDashboardShape array{
+ *   plan: PricingPlanShape|null,
+ *   billingState: string,
+ *   currentPeriodEnd: string|null,
+ *   balance: TicketBalanceShape,
+ *   quotas: QuotaLimitsShape,
+ *   canManageBilling: bool,
+ *   continueUrl: string|null,
+ *   autoRecharge: AutoRechargeShape,
+ *   autoRechargeSetupToken: string
+ * }
+ */
+final readonly class BillingDashboardDto
+{
+    public function __construct(
+        public ?PricingPlanDto $plan,
+        public OnboardingBillingState $billingState,
+        public ?string $currentPeriodEnd,
+        public TicketBalanceDto $balance,
+        public QuotaLimitsDto $quotas,
+        public bool $canManageBilling,
+        /**
+         * 課金ゲートで中断された「元の画面」への復帰先。契約成立着地でのみ 1 回だけ非 null
+         * (サーバが same-origin 内部 path に正規化済み)。
+         */
+        public ?string $continueUrl,
+        /** P8a: オートリチャージ設定 (常に非 null。既定は enabled=false の opt-in) */
+        public AutoRechargeSettingsDto $autoRecharge,
+        /** P8a: カード登録 (mode=setup) 開始 POST の attempt_token (render 単位) */
+        public string $autoRechargeSetupToken,
+    ) {}
+
+    /**
+     * @return BillingDashboardShape
+     */
+    public function toArray(): array
+    {
+        return [
+            'plan' => $this->plan?->toArray(),
+            'billingState' => $this->billingState->value,
+            'currentPeriodEnd' => $this->currentPeriodEnd,
+            'balance' => $this->balance->toArray(),
+            'quotas' => $this->quotas->toArray(),
+            'canManageBilling' => $this->canManageBilling,
+            'continueUrl' => $this->continueUrl,
+            'autoRecharge' => $this->autoRecharge->toArray(),
+            'autoRechargeSetupToken' => $this->autoRechargeSetupToken,
+        ];
+    }
+}
diff --git a/app/DataTransferObjects/Billing/BillingPlansPageDto.php b/app/DataTransferObjects/Billing/BillingPlansPageDto.php
new file mode 100644
index 0000000..c1d9754
--- /dev/null
+++ b/app/DataTransferObjects/Billing/BillingPlansPageDto.php
@@ -0,0 +1,55 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\DataTransferObjects\Billing;
+
+use App\DataTransferObjects\Marketing\PricingPlanDto;
+use App\Enums\Billing\OnboardingBillingState;
+
+/**
+ * プラン比較ページ (/billing/plans) の Inertia page prop。
+ *
+ * プラン台帳 → DTO の mapper は公開料金表と共有する (PricingService::listPublicPlans)。
+ * currentPlanCode は **表示専用** の解決結果であり gate 判定には使わない
+ * (判定は BillingAccess::state() 一本)。
+ *
+ * TS 側は resources/js/types/billing.ts の BillingPlansPageProps と exact 対で保守する。
+ *
+ * @phpstan-import-type PricingPlanShape from PricingPlanDto
+ *
+ * @phpstan-type BillingPlansPageShape array{
+ *   plans: list<PricingPlanShape>,
+ *   currentPlanCode: string|null,
+ *   billingState: string,
+ *   canManage: bool
+ * }
+ */
+final readonly class BillingPlansPageDto
+{
+    /**
+     * @param  list<PricingPlanDto>  $plans
+     */
+    public function __construct(
+        public array $plans,
+        public ?string $currentPlanCode,
+        public OnboardingBillingState $billingState,
+        public bool $canManage,
+    ) {}
+
+    /**
+     * @return BillingPlansPageShape
+     */
+    public function toArray(): array
+    {
+        return [
+            'plans' => array_map(
+                static fn (PricingPlanDto $plan): array => $plan->toArray(),
+                $this->plans,
+            ),
+            'currentPlanCode' => $this->currentPlanCode,
+            'billingState' => $this->billingState->value,
+            'canManage' => $this->canManage,
+        ];
+    }
+}
diff --git a/app/DataTransferObjects/Billing/PurchaseTicketsPageDto.php b/app/DataTransferObjects/Billing/PurchaseTicketsPageDto.php
index a71ef3b..3586781 100644
--- a/app/DataTransferObjects/Billing/PurchaseTicketsPageDto.php
+++ b/app/DataTransferObjects/Billing/PurchaseTicketsPageDto.php
@@ -4,23 +4,34 @@
 
 namespace App\DataTransferObjects\Billing;
 
+use App\Enums\Billing\PurchaseFormState;
+
 /**
  * チケット購入画面 (/purchase-tickets) の Inertia page prop。
  *
  * TS 側は resources/js/types/billing.ts の PurchaseTicketsPageProps と exact 対で保守する。
  *
+ * ticketAttemptToken は**チケット決済専用**の attempt token
+ * (ticket_checkout_sessions.attempt_token / Stripe key `purchase:{token}` の名前空間)。
+ * サブスク checkout 用 token (P9) とは別テーブル・別 key 空間のため型名で区別する。
+ *
  * @phpstan-import-type PurchaseTierShape from PurchaseTierDto
+ * @phpstan-import-type TicketBalanceShape from TicketBalanceDto
  *
  * @phpstan-type PurchaseTicketsPageShape array{
  *   tiers: list<PurchaseTierShape>,
  *   minCount: int,
  *   maxCount: int,
  *   defaultCount: int,
- *   balance: int,
+ *   balance: TicketBalanceShape,
  *   canManage: bool,
- *   attemptToken: string,
+ *   ticketAttemptToken: string,
  *   purchased: bool,
- *   autoRechargeEnabled: bool
+ *   autoRechargeEnabled: bool,
+ *   formState: string,
+ *   boundCount: int|null,
+ *   resumeUrl: string|null,
+ *   newPurchaseUrl: string
  * }
  */
 final readonly class PurchaseTicketsPageDto
@@ -33,10 +44,19 @@ public function __construct(
         public int $minCount,
         public int $maxCount,
         public int $defaultCount,
-        public int $balance,
+        /** P5 由来の per-source 残高 snapshot (画面で再計算しない) */
+        public TicketBalanceDto $balance,
         public bool $canManage,
-        public string $attemptToken,
+        public string $ticketAttemptToken,
         public bool $purchased,
+        /** P8b: 購入フォームの状態 (normal / resume / completed) */
+        public PurchaseFormState $formState,
+        /** resume / completed で表示する確定枚数 (normal は null) */
+        public ?int $boundCount,
+        /** resume の「決済を続ける」遷移先 (Stripe Checkout URL)。それ以外は null */
+        public ?string $resumeUrl,
+        /** 「新しく購入し直す」= ?fresh=1 の自画面 URL */
+        public string $newPurchaseUrl,
         /** P8a: オートリチャージが有効か (購入導線の案内文言の出し分けに使う。既定 false)。 */
         public bool $autoRechargeEnabled = false,
     ) {}
@@ -54,11 +74,15 @@ public function toArray(): array
             'minCount' => $this->minCount,
             'maxCount' => $this->maxCount,
             'defaultCount' => $this->defaultCount,
-            'balance' => $this->balance,
+            'balance' => $this->balance->toArray(),
             'canManage' => $this->canManage,
-            'attemptToken' => $this->attemptToken,
+            'ticketAttemptToken' => $this->ticketAttemptToken,
             'purchased' => $this->purchased,
             'autoRechargeEnabled' => $this->autoRechargeEnabled,
+            'formState' => $this->formState->value,
+            'boundCount' => $this->boundCount,
+            'resumeUrl' => $this->resumeUrl,
+            'newPurchaseUrl' => $this->newPurchaseUrl,
         ];
     }
 }
diff --git a/app/DataTransferObjects/Billing/QuotaLimitsDto.php b/app/DataTransferObjects/Billing/QuotaLimitsDto.php
new file mode 100644
index 0000000..11bf0e5
--- /dev/null
+++ b/app/DataTransferObjects/Billing/QuotaLimitsDto.php
@@ -0,0 +1,57 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\DataTransferObjects\Billing;
+
+/**
+ * 課金ダッシュボードに出す現行 quota 上限 (override 反映済み)。
+ *
+ * 値の出典は QuotaService::limits() (プラン既定 + organization override のマージ結果)。
+ * limits に key が無い = 無制限 = null。maxStorageGb は GiB 換算の表示値で、換算規則は
+ * PricingService::storageGb と同一 (intdiv(bytes, 1024**3) 切り捨て)。
+ *
+ * 使用量 (current) は AI-CUE に横断集計経路が無いため持たない (上限の提示のみ)。
+ *
+ * @phpstan-type QuotaLimitsShape array{
+ *   maxProjects: int|null,
+ *   maxMembers: int|null,
+ *   maxStorageGb: int|null
+ * }
+ */
+final readonly class QuotaLimitsDto
+{
+    public function __construct(
+        public ?int $maxProjects,
+        public ?int $maxMembers,
+        public ?int $maxStorageGb,
+    ) {}
+
+    /**
+     * QuotaService::limits() の結果から組み立てる。
+     *
+     * @param  array<string, int>  $limits
+     */
+    public static function fromLimits(array $limits): self
+    {
+        $bytes = $limits['max_storage_bytes'] ?? null;
+
+        return new self(
+            maxProjects: $limits['max_projects'] ?? null,
+            maxMembers: $limits['max_members'] ?? null,
+            maxStorageGb: $bytes === null ? null : intdiv($bytes, 1024 ** 3),
+        );
+    }
+
+    /**
+     * @return QuotaLimitsShape
+     */
+    public function toArray(): array
+    {
+        return [
+            'maxProjects' => $this->maxProjects,
+            'maxMembers' => $this->maxMembers,
+            'maxStorageGb' => $this->maxStorageGb,
+        ];
+    }
+}
diff --git a/app/Enums/Billing/PurchaseFormState.php b/app/Enums/Billing/PurchaseFormState.php
new file mode 100644
index 0000000..dd9681a
--- /dev/null
+++ b/app/Enums/Billing/PurchaseFormState.php
@@ -0,0 +1,23 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Enums\Billing;
+
+/**
+ * チケット購入フォームの状態 (P8b / tc-5。aigenba PurchaseFormState 相当)。
+ *
+ * - Normal: 新規購入フォーム (fresh attempt_token)。
+ * - Resume: 進行中 (live pending) Checkout への復帰。枚数は session に固定 (boundCount)。
+ * - Completed: 直近 (purchase_resume_window_minutes 窓内) の完了。反映待ち案内 +
+ *   「もう一度購入する」(?fresh=1) のみを出す。
+ *
+ * どの状態でも入力・ボタンを disabled にはしない (禁止事項 #8)。resume / completed では
+ * 購入フォーム自体を描画せず、読み取りテキストと明示的な CTA に置き換える。
+ */
+enum PurchaseFormState: string
+{
+    case Normal = 'normal';
+    case Resume = 'resume';
+    case Completed = 'completed';
+}
diff --git a/app/Http/Controllers/Billing/BillingController.php b/app/Http/Controllers/Billing/BillingController.php
index 0a1efad..76d2f2c 100644
--- a/app/Http/Controllers/Billing/BillingController.php
+++ b/app/Http/Controllers/Billing/BillingController.php
@@ -5,6 +5,11 @@
 namespace App\Http\Controllers\Billing;
 
 use App\DataTransferObjects\Billing\AutoRechargeConsentDto;
+use App\DataTransferObjects\Billing\BillingDashboardDto;
+use App\DataTransferObjects\Billing\BillingPlansPageDto;
+use App\DataTransferObjects\Billing\QuotaLimitsDto;
+use App\DataTransferObjects\Marketing\PricingPlanDto;
+use App\Enums\Billing\OnboardingBillingState;
 use App\Enums\Billing\PlanPriceKind;
 use App\Enums\CheckoutIntent;
 use App\Enums\CheckoutSessionStatus;
@@ -17,12 +22,15 @@
 use App\Http\Requests\Billing\UpdateAutoRechargeRequest;
 use App\Models\Billing\BillingCheckoutSession;
 use App\Models\Billing\Plan;
+use App\Models\Billing\Subscription;
 use App\Models\Organization;
 use App\Models\User;
 use App\Services\Billing\AutoRechargeService;
 use App\Services\Billing\BillingAccess;
+use App\Services\Billing\QuotaService;
 use App\Services\Billing\SubscriptionService;
 use App\Services\Billing\TicketLedgerService;
+use App\Services\Marketing\PricingService;
 use App\Services\Onboarding\IntendedPlanResolver;
 use App\Services\Onboarding\OnboardingReturnResolver;
 use Illuminate\Http\RedirectResponse;
@@ -54,9 +62,18 @@ public function __construct(
         private readonly AutoRechargeService $autoRecharge,
     ) {}
 
-    /** 課金ページ (現在プラン / チケット残高 / プラン一覧 / オートリチャージ設定) */
-    public function index(Request $request, TicketLedgerService $tickets): Response|RedirectResponse
-    {
+    /**
+     * 課金ダッシュボード (現在プラン / per-bucket チケット残高 / quota 上限 / 導線)。
+     *
+     * P8b (bs-14): プラン一覧は /billing/plans へ移設し、ここは請求ダッシュボードに寄せる。
+     * props は BillingDashboardDto の 1 本 (禁止事項 #4)。
+     */
+    public function index(
+        Request $request,
+        TicketLedgerService $tickets,
+        QuotaService $quota,
+        PricingService $pricing,
+    ): Response|RedirectResponse {
         $organization = $this->resolveCurrentOrganization($request);
         Gate::authorize('view', $organization);
 
@@ -71,37 +88,82 @@ public function index(Request $request, TicketLedgerService $tickets): Response|
             return $landing;
         }
 
-        $plans = Plan::query()->orderBy('sort_order')->get()
-            ->map(function (Plan $plan): array {
-                $price = $plan->currentPrice(PlanPriceKind::Base);
-
-                return [
-                    'code' => $plan->code,
-                    'name' => $plan->name,
-                    'price' => $price === null ? null : [
-                        'unitAmount' => $price->amount,
-                        'currency' => $price->currency,
-                    ],
-                ];
-            })
-            ->values()
-            ->all();
-
         $canManageBilling = $user->can('manageBilling', $organization);
-
-        return Inertia::render('Billing/Index', [
-            'plans' => $plans,
-            'currentPlanCode' => $organization->plan_code,
-            'ticketBalance' => $tickets->balance($organization)->totalAvailable(),
-            'canManageBilling' => $canManageBilling,
-            'continueUrl' => $this->resolveOnboardingContinue($organization),
+        $subscription = $organization->subscription('default');
+
+        $dto = new BillingDashboardDto(
+            plan: $this->resolveCurrentPlan($organization, $pricing),
+            billingState: $this->access->state($organization),
+            currentPeriodEnd: $subscription instanceof Subscription
+                ? $subscription->current_period_end?->toIso8601String()
+                : null,
+            balance: $tickets->balance($organization),
+            quotas: QuotaLimitsDto::fromLimits($quota->limits($organization)),
+            canManageBilling: $canManageBilling,
+            continueUrl: $this->resolveOnboardingContinue($organization),
             // P8a: オートリチャージ設定カード。subscription 有無に依存せず常に非 null
             // (無料パーソナル含む全プランが対象。**既定は enabled=false の opt-in**)。
-            'autoRecharge' => $this->autoRecharge->settingsFor($organization, $canManageBilling)->toArray(),
+            autoRecharge: $this->autoRecharge->settingsFor($organization, $canManageBilling),
             // カード登録開始 POST の attempt_token (render 単位。setup は課金を伴わないため
             // 購入導線のようなサーバ側安定化は不要 — 同一 token の再送は台帳 unique で冪等)。
-            'autoRechargeSetupToken' => strtolower((string) Str::ulid()),
-        ]);
+            autoRechargeSetupToken: strtolower((string) Str::ulid()),
+        );
+
+        return Inertia::render('Billing/Index', ['page' => $dto->toArray()]);
+    }
+
+    /**
+     * プラン比較ページ (P8b / bs-6)。閲覧は組織メンバー全員、変更は manageBilling のみ。
+     *
+     * プラン台帳 → DTO の mapper は公開料金表と共有する (新 DTO を発明しない)。
+     */
+    public function plans(Request $request, PricingService $pricing): Response
+    {
+        $organization = $this->resolveCurrentOrganization($request);
+        Gate::authorize('view', $organization);
+
+        $user = $request->user();
+        Assert::isInstanceOf($user, User::class);
+
+        $dto = new BillingPlansPageDto(
+            plans: $pricing->listPublicPlans(),
+            currentPlanCode: $this->resolveCurrentPlanCode($organization),
+            billingState: $this->access->state($organization),
+            canManage: $user->can('manageBilling', $organization),
+        );
+
+        return Inertia::render('Billing/Plans', ['page' => $dto->toArray()]);
+    }
+
+    /**
+     * 表示用の現在プラン code。
+     *
+     * ActiveFreePlan は free_plan_code が正 (canceled サブスク行が残る paid→free 経路で
+     * plan_code に旧 paid が残るため)。**表示専用**であり gate 判定には使わない
+     * (判定は BillingAccess::state() 一本)。
+     */
+    private function resolveCurrentPlanCode(Organization $organization): ?string
+    {
+        return $this->access->state($organization) === OnboardingBillingState::ActiveFreePlan
+            ? $organization->free_plan_code
+            : $organization->plan_code;
+    }
+
+    /** 表示用の現在プラン (台帳に無い code / 未契約は null)。 */
+    private function resolveCurrentPlan(Organization $organization, PricingService $pricing): ?PricingPlanDto
+    {
+        $code = $this->resolveCurrentPlanCode($organization);
+        if ($code === null) {
+            return null;
+        }
+
+        foreach ($pricing->listPublicPlans() as $plan) {
+            if ($plan->code === $code) {
+                return $plan;
+            }
+        }
+
+        return null;
     }
 
     /**
@@ -275,12 +337,23 @@ private function resolveOnboardingContinue(Organization $organization): ?string
         return $continue;
     }
 
-    /** Stripe Customer Portal へリダイレクトする (支払い方法・解約の自己管理) */
-    public function portal(Request $request, SubscriptionService $subscriptions): SymfonyResponse
+    /**
+     * Stripe Customer Portal へリダイレクトする (支払い方法・解約の自己管理)。
+     *
+     * P8b (bs-11): Portal は Stripe customer + サブスク前提。free personal
+     * (canceled サブスク行が残る paid→free を含む = billingState で判定) / 未契約 org は
+     * Cashier の assertCustomerExists() 例外 (= 500) に到達させず error flash で back する。
+     */
+    public function portal(Request $request, SubscriptionService $subscriptions): SymfonyResponse|RedirectResponse
     {
         $organization = $this->resolveCurrentOrganization($request);
         Gate::authorize('manageBilling', $organization);
 
+        if ($this->access->state($organization) === OnboardingBillingState::ActiveFreePlan
+            || ! $organization->subscription('default') instanceof Subscription) {
+            return back()->with('error', 'お支払い管理画面は有償プラン契約後にご利用いただけます。');
+        }
+
         return Inertia::location($subscriptions->createPortalSession($organization, route('billing.index'))->url);
     }
 }
diff --git a/app/Http/Controllers/Billing/TicketPurchaseController.php b/app/Http/Controllers/Billing/TicketPurchaseController.php
index e9c2025..c5c16df 100644
--- a/app/Http/Controllers/Billing/TicketPurchaseController.php
+++ b/app/Http/Controllers/Billing/TicketPurchaseController.php
@@ -5,12 +5,15 @@
 namespace App\Http\Controllers\Billing;
 
 use App\DataTransferObjects\Billing\PurchaseTicketsPageDto;
+use App\Enums\Billing\PurchaseFormState;
+use App\Enums\Billing\TicketCheckoutSessionStatus;
 use App\Exceptions\Billing\CheckoutInProgressException;
 use App\Exceptions\Billing\StaleCheckoutAttemptException;
 use App\Exceptions\Billing\TicketVolumeTierUnavailableException;
 use App\Http\Concerns\ResolvesCurrentOrganization;
 use App\Http\Controllers\Controller;
 use App\Http\Requests\Billing\TicketCheckoutRequest;
+use App\Models\Billing\TicketCheckoutSession;
 use App\Models\Billing\TicketVolumePrice;
 use App\Models\User;
 use App\Services\Billing\AutoRechargeService;
@@ -40,7 +43,13 @@ class TicketPurchaseController extends Controller
     /** 購入画面の枚数入力の初期値 */
     private const int DEFAULT_COUNT = 10;
 
-    /** 購入画面 (attempt_token は render ごとに ULID 発行) */
+    /**
+     * 購入画面。
+     *
+     * P8b (tc-5): attempt_token は毎 render ULID ではなく、**自分が開始した復帰可能な購入**が
+     * あればその session の token を再利用する (ブラウザバック / bfcache で既存 replay 冪等が
+     * 効き、二重課金にならない)。`?fresh=1` は明示的に新規購入 (別 token) へ倒す。
+     */
     public function show(
         Request $request,
         TicketPricingService $pricing,
@@ -60,15 +69,48 @@ public function show(
         $purchased = $request->boolean('purchased')
             && $checkout->confirmsPurchaseReturn($organization, is_string($sessionId) ? $sessionId : null);
 
+        $canManage = $user->can('manageBilling', $organization);
+
+        // manageBilling を持たない閲覧者には resume / completed を出さない
+        // (resumeUrl は外部 Stripe Checkout 直リンクで purchase gate を迂回しうる)。
+        $resumable = ($canManage && ! $request->boolean('fresh'))
+            ? $checkout->resolveResumablePurchase(
+                $organization,
+                $user->id,
+                config()->integer('billing.purchase_resume_window_minutes'),
+            )
+            : null;
+
+        [$formState, $attemptToken, $boundCount, $resumeUrl] = match (true) {
+            $resumable instanceof TicketCheckoutSession
+                && $resumable->status === TicketCheckoutSessionStatus::Pending => [
+                    PurchaseFormState::Resume,
+                    $resumable->attempt_token,
+                    $resumable->ticket_count,
+                    $resumable->checkout_url,
+                ],
+            $resumable instanceof TicketCheckoutSession => [
+                PurchaseFormState::Completed,
+                $resumable->attempt_token,
+                $resumable->ticket_count,
+                null,
+            ],
+            default => [PurchaseFormState::Normal, (string) Str::ulid(), null, null],
+        };
+
         $dto = new PurchaseTicketsPageDto(
             tiers: $pricing->volumeTiersForDisplay(),
             minCount: TicketVolumePrice::PURCHASE_MIN_COUNT,
             maxCount: TicketVolumePrice::PURCHASE_MAX_COUNT,
             defaultCount: self::DEFAULT_COUNT,
-            balance: $tickets->balance($organization)->totalAvailable(),
-            canManage: $user->can('manageBilling', $organization),
-            attemptToken: (string) Str::ulid(),
+            balance: $tickets->balance($organization),
+            canManage: $canManage,
+            ticketAttemptToken: $attemptToken,
             purchased: $purchased,
+            formState: $formState,
+            boundCount: $boundCount,
+            resumeUrl: $resumeUrl,
+            newPurchaseUrl: route('billing.tickets.show', ['fresh' => 1]),
             // P8a: 有効なら「自動購入が設定済み」であることを購入導線でも示せるようにする
             // (軽量な enabled 判定のみ。カタログ解決コストは払わない)。
             autoRechargeEnabled: $autoRecharge->isEnabledFor($organization),
diff --git a/app/Http/Controllers/Marketing/PricingController.php b/app/Http/Controllers/Marketing/PricingController.php
index 96d43aa..e23a9be 100644
--- a/app/Http/Controllers/Marketing/PricingController.php
+++ b/app/Http/Controllers/Marketing/PricingController.php
@@ -70,7 +70,7 @@ public function __invoke(Request $request): InertiaResponse
                 ]),
         );
 
-        return Inertia::render('Pricing', [
+        return Inertia::render('Guest/Pricing', [
             'page' => $dto->toArray(),
         ]);
     }
diff --git a/app/Services/Billing/TicketCheckoutService.php b/app/Services/Billing/TicketCheckoutService.php
index 4e679a8..e22a635 100644
--- a/app/Services/Billing/TicketCheckoutService.php
+++ b/app/Services/Billing/TicketCheckoutService.php
@@ -88,6 +88,43 @@ public function confirmsPurchaseReturn(Organization $organization, ?string $sess
             ->exists();
     }
 
+    /**
+     * P8b (tc-5): 購入画面の状態機械が読む「復帰可能な購入」を解決する。
+     *
+     * 2 段取得 (会計には一切触れない):
+     *   (1) live pending (Pending / expires_at 未来 / checkout_url 非空) の最新 → resume
+     *   (2) 窓内 completed (completed_at > now - window) の最新 → completed
+     *   (3) いずれも無ければ null → normal
+     *
+     * いずれも organization_id + initiated_by_user_id スコープ。resumeUrl は Stripe Checkout の
+     * 直リンクで購入 gate を迂回しうるため、他 user の session を絶対に露出させない。
+     */
+    public function resolveResumablePurchase(Organization $organization, int $userId, int $windowMinutes): ?TicketCheckoutSession
+    {
+        $now = CarbonImmutable::now();
+
+        $livePending = TicketCheckoutSession::query()
+            ->where('organization_id', $organization->id)
+            ->where('initiated_by_user_id', $userId)
+            ->where('status', TicketCheckoutSessionStatus::Pending)
+            ->where('expires_at', '>', $now)
+            ->where('checkout_url', '<>', '')
+            ->latest('id')
+            ->first();
+
+        if ($livePending !== null) {
+            return $livePending;
+        }
+
+        return TicketCheckoutSession::query()
+            ->where('organization_id', $organization->id)
+            ->where('initiated_by_user_id', $userId)
+            ->where('status', TicketCheckoutSessionStatus::Completed)
+            ->where('completed_at', '>', $now->subMinutes($windowMinutes))
+            ->latest('id')
+            ->first();
+    }
+
     private function startCheckoutLocked(
         Organization $organization,
         User $user,
diff --git a/config/billing.php b/config/billing.php
index 38d164b..9060b97 100644
--- a/config/billing.php
+++ b/config/billing.php
@@ -41,6 +41,14 @@
     */
     'ticket_low_balance_threshold' => (int) env('BILLING_TICKET_LOW_BALANCE_THRESHOLD', 5),
 
+    /*
+    | 購入画面の resume / completed 表示窓 (分。P8b / tc-5)。
+    | この窓内に「自分が開始した」live pending / 完了 session があれば、購入画面は
+    | 新しい attempt_token を発行せず既存 session へ復帰導線を出す (ブラウザバック /
+    | bfcache 復帰で既存 replay 冪等が効く = 二重課金しない)。既定 30 は移植元と同値。
+    */
+    'purchase_resume_window_minutes' => (int) env('BILLING_PURCHASE_RESUME_WINDOW_MINUTES', 30),
+
     /*
     |----------------------------------------------------------------------
     | オートリチャージ (裏チャージ。P8a)
diff --git a/resources/js/components/molecules/PricingPlanCard.svelte b/resources/js/components/molecules/PricingPlanCard.svelte
index c17ed96..ca88815 100644
--- a/resources/js/components/molecules/PricingPlanCard.svelte
+++ b/resources/js/components/molecules/PricingPlanCard.svelte
@@ -24,6 +24,8 @@
         isHighlighted?: boolean;
         features: PricingFeature[];
         testId?: string;
+        /** header 右上専用 (現在のプラン等の Badge)。未指定時の出力は不変 */
+        headerBadges?: Snippet;
         /** card footer 下部 CTA 専用 */
         footerCta: Snippet;
     }
@@ -36,6 +38,7 @@
         isHighlighted = false,
         features,
         testId,
+        headerBadges,
         footerCta,
     }: Props = $props();
 
@@ -45,7 +48,14 @@
 </script>
 
 <div class="flex flex-col rounded-lg border bg-surface p-5 {borderClass}" data-testid={testId}>
-    <h3 class="text-h3 text-text">{name}</h3>
+    <div class="flex flex-wrap items-center gap-2">
+        <h3 class="shrink-0 text-h3 text-text">{name}</h3>
+        {#if headerBadges}
+            <div class="ml-auto flex max-w-full min-w-0 flex-wrap justify-end gap-2">
+                {@render headerBadges()}
+            </div>
+        {/if}
+    </div>
     {#if priceCaption !== undefined && !isFree}
         <!-- 表示価格が総額と誤解されるのを防ぐ (例: 基本料金)。 -->
         <p class="mt-3 text-caption text-text-secondary" data-testid="price-caption">
diff --git a/resources/js/components/organisms/ConfirmDialog.svelte b/resources/js/components/organisms/ConfirmDialog.svelte
index 975755b..4a5da77 100644
--- a/resources/js/components/organisms/ConfirmDialog.svelte
+++ b/resources/js/components/organisms/ConfirmDialog.svelte
@@ -7,6 +7,7 @@
         open = $bindable(false),
         title,
         message,
+        banner,
         confirmLabel = "確認",
         cancelLabel = "キャンセル",
         confirmVariant = "primary",
@@ -34,6 +35,9 @@
 </script>
 
 <Modal bind:open={() => open, setOpen} {title} size="sm" {processing} {testId}>
+    {#if banner}
+        {@render banner()}
+    {/if}
     <p>{message}</p>
     {#snippet footer()}
         <Button variant="ghost" onclick={handleCancel} disabled={processing}>
diff --git a/resources/js/components/organisms/ConfirmDialog.types.ts b/resources/js/components/organisms/ConfirmDialog.types.ts
index 7368162..35901b1 100644
--- a/resources/js/components/organisms/ConfirmDialog.types.ts
+++ b/resources/js/components/organisms/ConfirmDialog.types.ts
@@ -1,3 +1,5 @@
+import type { Snippet } from "svelte";
+
 /**
  * ConfirmDialog organism の仕様の真実。意味論は DESIGN.md §Components > ConfirmDialog を参照。
  */
@@ -15,6 +17,11 @@ export interface ConfirmDialogProps {
     title: string;
     /** 確認メッセージ本文 */
     message: string;
+    /**
+     * message の直上に描画する任意スロット (サーバ validation エラーの Alert 等)。
+     * 未指定なら描画されない = 既存の出力は不変。
+     */
+    banner?: Snippet;
     confirmLabel?: string;
     cancelLabel?: string;
     /** 既定 primary。irreversible / destructive な操作は danger を指定する */
diff --git a/resources/js/pages/Billing/Index.svelte b/resources/js/pages/Billing/Index.svelte
index c264615..5bcd732 100644
--- a/resources/js/pages/Billing/Index.svelte
+++ b/resources/js/pages/Billing/Index.svelte
@@ -1,6 +1,7 @@
 <script lang="ts">
-    import { page, router } from "@inertiajs/svelte";
-    import Badge from "@/components/atoms/Badge.svelte";
+    import { onMount } from "svelte";
+    import { page as inertiaPage, router } from "@inertiajs/svelte";
+    import { CreditCard } from "@lucide/svelte";
     import Button from "@/components/atoms/Button.svelte";
     import Card from "@/components/atoms/Card.svelte";
     import TextLink from "@/components/atoms/TextLink.svelte";
@@ -9,71 +10,35 @@
     import PageContent from "@/components/templates/PageContent.svelte";
     import PageHeader from "@/components/molecules/PageHeader.svelte";
     import AutoRechargeCard from "@/components/features/billing/AutoRechargeCard.svelte";
-    import { CreditCard } from "@lucide/svelte";
+    import { formatDate } from "@/lib/date-format";
     import type { SharedProps } from "@/lib/shared-props";
-    import type {
-        AutoRechargeProps,
-        BillingIndexPlan,
-        BillingIndexPlanPrice,
-    } from "@/types/billing";
+    import type { BillingDashboardProps } from "@/types/billing";
 
     /**
-     * 課金ページ (現在プラン / チケット残高 / プラン一覧)。
-     * プラン変更は Stripe Checkout、支払い方法・解約は Customer Portal 経由
-     * (POST → Inertia::location で Stripe へ full page redirect)。
-     * manageBilling 権限 (owner / admin) が無いメンバーは閲覧のみ。
+     * 課金ダッシュボード (/billing)。現在のプラン / per-bucket チケット残高 / 現行 quota 上限 /
+     * オートリチャージ設定 と、プラン比較・チケット購入への導線を持つ。
+     *
+     * プラン一覧は /billing/plans (Billing/Plans.svelte) へ移設済み。
+     * 支払い方法・解約は Customer Portal (POST → Inertia::location で Stripe へ) 経由。
      */
-    type PlanPrice = BillingIndexPlanPrice;
-    type Plan = BillingIndexPlan;
-
     interface Props {
-        plans: Plan[];
-        currentPlanCode: string | null;
-        ticketBalance: number;
-        canManageBilling: boolean;
-        /**
-         * 課金ゲートで中断された「元の画面」への復帰先。契約成立着地でのみ 1 回だけ
-         * 非 null で届く (サーバが same-origin 内部 path に正規化済み)。
-         */
-        continueUrl?: string | null;
-        /** P8a: オートリチャージ設定 (常に非 null。既定は enabled=false の opt-in) */
-        autoRecharge: AutoRechargeProps;
-        /** P8a: カード登録 (mode=setup) 開始 POST の attempt_token (render 単位) */
-        autoRechargeSetupToken: string;
+        page: BillingDashboardProps;
     }
 
-    let {
-        plans,
-        currentPlanCode,
-        ticketBalance,
-        canManageBilling,
-        continueUrl = null,
-        autoRecharge,
-        autoRechargeSetupToken,
-    }: Props = $props();
+    let { page }: Props = $props();
 
-    const shared = $derived(page.props as unknown as SharedProps);
+    const shared = $derived(inertiaPage.props as unknown as SharedProps);
     const appName = $derived(shared.appName ?? "");
 
-    const currentPlan = $derived(plans.find((plan) => plan.code === currentPlanCode) ?? null);
+    // Personal (free) はサブスクなし。Stripe portal / 次回請求日などサブスク前提の UI を出さない。
+    const isFreePlan = $derived(page.billingState === "active_free_plan");
 
-    let processingPlanCode = $state<string | null>(null);
     let portalProcessing = $state(false);
 
-    function startCheckout(plan: Plan): void {
-        router.post(
-            "/billing/checkout",
-            { plan_code: plan.code },
-            {
-                onStart: () => {
-                    processingPlanCode = plan.code;
-                },
-                onFinish: () => {
-                    processingPlanCode = null;
-                },
-            },
-        );
-    }
+    const formatYen = (amount: number | null): string =>
+        amount === null ? "—" : new Intl.NumberFormat("ja-JP").format(amount);
+
+    const formatLimit = (value: number | null): string => (value === null ? "無制限" : String(value));
 
     function openPortal(): void {
         router.post(
@@ -90,15 +55,15 @@
         );
     }
 
-    function formatPrice(price: PlanPrice | null): string {
-        if (price === null) {
-            return "無料";
-        }
-        if (price.currency === "jpy") {
-            return `¥${price.unitAmount.toLocaleString("ja-JP")} / 月`;
+    // ?highlight=auto-recharge の着地 anchor (購入画面等からの誘導。scroll のみ・副作用なし)。
+    onMount(() => {
+        const params = new URLSearchParams(window.location.search);
+        if (params.get("highlight") === "auto-recharge") {
+            document
+                .querySelector('[data-testid="auto-recharge-card"]')
+                ?.scrollIntoView({ behavior: "smooth" });
         }
-        return `${price.unitAmount.toLocaleString()} ${price.currency.toUpperCase()} / 月`;
-    }
+    });
 </script>
 
 <AppLayout {appName}>
@@ -111,101 +76,149 @@
         />
         <PageContent>
             <div class="flex flex-col gap-10">
-            {#if continueUrl !== null}
-                <Card padding="lg" testId="billing-continue">
-                    <p class="text-body">お手続きが完了しました。中断していた画面に戻れます。</p>
-                    <div class="mt-4">
-                        <Button href={continueUrl} inertia testId="billing-continue-link">
-                            元の画面に戻る
+                {#if page.continueUrl !== null}
+                    <Card padding="lg" testId="billing-continue">
+                        <p class="text-body">お手続きが完了しました。中断していた画面に戻れます。</p>
+                        <div class="mt-4">
+                            <Button href={page.continueUrl} inertia testId="billing-continue-link">
+                                元の画面に戻る
+                            </Button>
+                        </div>
+                    </Card>
+                {/if}
+
+                <Card padding="lg" testId="current-plan-card">
+                    <h2 class="text-h3">現在のプラン</h2>
+                    {#if page.plan !== null}
+                        <div class="mt-4 grid gap-4 md:grid-cols-2">
+                            <div>
+                                <p class="text-caption text-text-secondary">プラン</p>
+                                <p class="text-h2 text-text" data-testid="current-plan-code">
+                                    {page.plan.name}
+                                </p>
+                                <p class="text-body text-text-secondary">
+                                    {#if isFreePlan}
+                                        月額 無料（チケット代のみ）
+                                    {:else}
+                                        月額 ¥{formatYen(page.plan.baseAmountJpy)}
+                                    {/if}
+                                </p>
+                            </div>
+                            {#if !isFreePlan}
+                                <div>
+                                    <p class="text-caption text-text-secondary">次回請求日</p>
+                                    <p class="text-h3 text-text" data-testid="current-period-end">
+                                        {formatDate(page.currentPeriodEnd, "—")}
+                                    </p>
+                                </div>
+                            {/if}
+                        </div>
+                    {:else}
+                        <p class="mt-4 text-body text-text-secondary" data-testid="no-plan-note">
+                            まだプランに契約していません。「プラン比較」から新規契約できます。
+                        </p>
+                    {/if}
+                    <div class="mt-6 flex flex-wrap items-center gap-4">
+                        <Button href="/billing/plans" inertia variant="ghost" testId="billing-plans-link">
+                            プラン比較
                         </Button>
+                        {#if page.canManageBilling && !isFreePlan}
+                            <Button
+                                variant="ghost"
+                                loading={portalProcessing}
+                                onclick={openPortal}
+                                testId="billing-portal-button"
+                            >
+                                お支払い方法を管理 (Stripe)
+                            </Button>
+                        {/if}
                     </div>
+                    {#if !page.canManageBilling}
+                        <p class="mt-4 text-caption text-text-secondary">
+                            プランの変更には組織の管理者権限が必要です。
+                        </p>
+                    {/if}
                 </Card>
-            {/if}
 
-            <Card padding="lg" testId="billing-summary">
-                <div class="flex flex-wrap items-start justify-between gap-4">
-                    <div>
-                        <h2 class="text-h3">現在のプラン</h2>
-                        <p class="mt-2 text-body" data-testid="current-plan-name">
-                            {currentPlan?.name ?? "未契約 (Free 相当)"}
+                <Card padding="lg" testId="billing-balance">
+                    <h2 class="text-h3">チケット残高</h2>
+                    <div class="mt-4">
+                        <p class="text-caption text-text-secondary">今すぐ使える残高</p>
+                        <p class="text-h2 text-text" data-testid="ticket-balance">
+                            {page.balance.totalAvailable.toLocaleString("ja-JP")}
+                            <span class="text-caption text-text-secondary">枚</span>
                         </p>
                     </div>
-                    <div>
-                        <h2 class="text-h3">チケット残高</h2>
-                        <p class="mt-2 text-body" data-testid="ticket-balance">
-                            {ticketBalance.toLocaleString("ja-JP")} 枚
-                        </p>
-                        <!-- 遷移先が role-aware (非管理者には購入依頼の案内) のため権限に依らず表示 -->
-                        <p class="mt-1">
-                            <TextLink href="/purchase-tickets" testId="purchase-tickets-link">
-                                チケットを購入
-                            </TextLink>
+                    <dl class="mt-4 grid gap-4 border-t border-border pt-4 md:grid-cols-2">
+                        <div>
+                            <dt class="text-caption text-text-secondary">プラン付与残</dt>
+                            <dd class="mt-1 text-h3 text-text" data-testid="balance-monthly">
+                                {page.balance.monthlyRemaining.toLocaleString("ja-JP")}
+                                <span class="text-caption text-text-secondary">枚</span>
+                            </dd>
+                            <p class="text-caption text-text-secondary">
+                                プラン付与・初回特典分の残り（有効期限あり）
+                            </p>
+                        </div>
+                        <div>
+                            <dt class="text-caption text-text-secondary">購入済み残</dt>
+                            <dd class="mt-1 text-h3 text-text" data-testid="balance-purchased">
+                                {page.balance.purchasedRemaining.toLocaleString("ja-JP")}
+                                <span class="text-caption text-text-secondary">枚</span>
+                            </dd>
+                            <p class="text-caption text-text-secondary">追加購入した分の残り</p>
+                        </div>
+                    </dl>
+                    {#if page.balance.nextExpireAt !== null}
+                        <p class="mt-3 text-caption text-text-secondary" data-testid="balance-next-expire">
+                            次の失効: {formatDate(page.balance.nextExpireAt, "—")}
                         </p>
-                    </div>
-                </div>
-                {#if canManageBilling}
-                    <div class="mt-6">
-                        <Button
-                            variant="ghost"
-                            loading={portalProcessing}
-                            onclick={openPortal}
-                            testId="billing-portal-button"
-                        >
-                            お支払い方法を管理 (Stripe)
-                        </Button>
-                    </div>
-                {:else}
-                    <p class="mt-6 text-caption text-text-secondary">
-                        プランの変更には組織の管理者権限が必要です。
+                    {/if}
+                    <!-- 遷移先が role-aware (非管理者には購入依頼の案内) のため権限に依らず表示 -->
+                    <p class="mt-4">
+                        <TextLink href="/purchase-tickets" testId="purchase-tickets-link">
+                            チケットを購入
+                        </TextLink>
                     </p>
-                {/if}
-            </Card>
+                </Card>
 
-            <!--
-                P8a: オートリチャージ (裏チャージ) 設定カード。
-                差し込み位置と ?highlight=auto-recharge anchor は P8b (T080) 所管のため、
-                ここでは実体の追加に留める (P8b が後からマージされる前提)。
-            -->
-            <AutoRechargeCard
-                {autoRecharge}
-                updateUrl="/billing/auto-recharge"
-                setupUrl="/billing/auto-recharge/setup"
-                setupAttemptToken={autoRechargeSetupToken}
-            />
+                <!--
+                    P8a: オートリチャージ (裏チャージ) 設定カード。
+                    差し込み位置と ?highlight=auto-recharge の着地 anchor は P8b 所管
+                    (カード実体は P8a 所管のため、ここでは配置のみを決める)。
+                -->
+                <AutoRechargeCard
+                    autoRecharge={page.autoRecharge}
+                    updateUrl="/billing/auto-recharge"
+                    setupUrl="/billing/auto-recharge/setup"
+                    setupAttemptToken={page.autoRechargeSetupToken}
+                />
 
-            <section>
-                <h2 class="text-h3">プラン一覧</h2>
-                <ul class="mt-4 flex flex-col gap-4" data-testid="plan-list">
-                    {#each plans as plan (plan.code)}
-                        <li>
-                            <Card padding="lg">
-                                <div class="flex flex-wrap items-center justify-between gap-4">
-                                    <div>
-                                        <div class="flex items-center gap-2">
-                                            <h3 class="text-h3">{plan.name}</h3>
-                                            {#if plan.code === currentPlanCode}
-                                                <Badge tone="primary">現在のプラン</Badge>
-                                            {/if}
-                                        </div>
-                                        <p class="mt-1 text-caption text-text-secondary">
-                                            {formatPrice(plan.price)}
-                                        </p>
-                                    </div>
-                                    {#if canManageBilling && plan.price !== null && plan.code !== currentPlanCode}
-                                        <Button
-                                            loading={processingPlanCode === plan.code}
-                                            onclick={() => startCheckout(plan)}
-                                            testId={`checkout-${plan.code}`}
-                                        >
-                                            このプランにする
-                                        </Button>
-                                    {/if}
-                                </div>
-                            </Card>
-                        </li>
-                    {/each}
-                </ul>
-            </section>
+                <Card padding="lg" testId="billing-quotas">
+                    <h2 class="text-h3">現在のプランの上限</h2>
+                    <dl class="mt-4 grid gap-4 sm:grid-cols-3">
+                        <div>
+                            <dt class="text-caption text-text-secondary">プロジェクト</dt>
+                            <dd class="mt-1 text-h3 text-text" data-testid="quota-max-projects">
+                                {formatLimit(page.quotas.maxProjects)}
+                            </dd>
+                        </div>
+                        <div>
+                            <dt class="text-caption text-text-secondary">メンバー</dt>
+                            <dd class="mt-1 text-h3 text-text" data-testid="quota-max-members">
+                                {formatLimit(page.quotas.maxMembers)}
+                            </dd>
+                        </div>
+                        <div>
+                            <dt class="text-caption text-text-secondary">ストレージ</dt>
+                            <dd class="mt-1 text-h3 text-text" data-testid="quota-max-storage">
+                                {page.quotas.maxStorageGb === null
+                                    ? "無制限"
+                                    : `${page.quotas.maxStorageGb} GB`}
+                            </dd>
+                        </div>
+                    </dl>
+                </Card>
             </div>
         </PageContent>
     </PageContainer>
diff --git a/resources/js/pages/Billing/Plans.svelte b/resources/js/pages/Billing/Plans.svelte
new file mode 100644
index 0000000..9811e05
--- /dev/null
+++ b/resources/js/pages/Billing/Plans.svelte
@@ -0,0 +1,140 @@
+<script lang="ts">
+    import { page as inertiaPage, router } from "@inertiajs/svelte";
+    import { CreditCard } from "@lucide/svelte";
+    import Alert from "@/components/atoms/Alert.svelte";
+    import PageHeader from "@/components/molecules/PageHeader.svelte";
+    import ConfirmDialog from "@/components/organisms/ConfirmDialog.svelte";
+    import AppLayout from "@/components/templates/AppLayout.svelte";
+    import PageContainer from "@/components/templates/PageContainer.svelte";
+    import PageContent from "@/components/templates/PageContent.svelte";
+    import type { SharedProps } from "@/lib/shared-props";
+    import type { BillingPlansPageProps } from "@/types/billing";
+    import type { PricingPlanShape } from "@/types/marketing";
+    import PlanCard from "./_helpers/PlanCard.svelte";
+
+    /**
+     * プラン比較 (/billing/plans)。閲覧は組織メンバー全員、変更は manageBilling のみ。
+     * 変更は既存の Stripe Checkout (POST /billing/checkout。body は plan_code のみ) へ委譲する。
+     *
+     * 変更できないプランでも CTA は enabled のまま描画し、理由は caption + 押下時 Alert で
+     * 伝える (DESIGN.md / 禁止事項 #8)。
+     */
+    interface Props {
+        page: BillingPlansPageProps;
+    }
+
+    let { page }: Props = $props();
+
+    const shared = $derived(inertiaPage.props as unknown as SharedProps);
+    const appName = $derived(shared.appName ?? "");
+
+    // サーバ validation エラー (旧タブからの送信・未同期プラン等) は dialog 内に出す。
+    const planCodeError = $derived.by<string | null>(() => {
+        const errors = inertiaPage.props.errors as Record<string, string> | undefined;
+        return errors?.plan_code ?? null;
+    });
+
+    const formatLimit = (value: number | null): string => (value === null ? "無制限" : String(value));
+
+    // Personal は個人専用の無料プラン。有効化は onboarding 経路のため本画面からは変更しない。
+    const isPersonal = (plan: PricingPlanShape): boolean => plan.code === "personal";
+
+    const canSwitchTo = (plan: PricingPlanShape): boolean => {
+        if (!page.canManage) return false;
+        if (page.currentPlanCode === plan.code) return false;
+        if (isPersonal(plan)) return false;
+        return true;
+    };
+
+    // canSwitchTo の各分岐に 1:1 対応する理由文言 (canSwitch=true では空文字)。
+    const switchBlockedReasonFor = (plan: PricingPlanShape): string => {
+        if (!page.canManage) return "プランを変更する権限がありません";
+        if (page.currentPlanCode === plan.code) return "現在ご利用中のプランです";
+        if (isPersonal(plan)) {
+            return "パーソナルプラン（無料）は個人専用のため、こちらからは変更できません";
+        }
+        return "";
+    };
+
+    let confirmingPlanCode = $state<string | null>(null);
+    let confirmOpen = $state(false);
+    let submitting = $state(false);
+
+    const planNameOf = (code: string): string =>
+        page.plans.find((plan) => plan.code === code)?.name ?? code;
+
+    function openConfirm(planCode: string): void {
+        confirmingPlanCode = planCode;
+        confirmOpen = true;
+    }
+
+    function closeConfirm(): void {
+        confirmingPlanCode = null;
+    }
+
+    function submitPlanChange(): void {
+        const planCode = confirmingPlanCode;
+        if (planCode === null || submitting) return;
+        router.post(
+            "/billing/checkout",
+            { plan_code: planCode },
+            {
+                onStart: () => {
+                    submitting = true;
+                },
+                onFinish: () => {
+                    submitting = false;
+                },
+                // 成功時のみ閉じる (validation error 時は開いたままサーバ文言を出す)
+                onSuccess: () => {
+                    confirmOpen = false;
+                    confirmingPlanCode = null;
+                },
+            },
+        );
+    }
+</script>
+
+<AppLayout {appName}>
+    <PageContainer>
+        <PageHeader
+            title="プラン比較"
+            description="現在のプランの変更・新規契約ができます"
+            icon={CreditCard}
+            testId="billing-plans-heading"
+        />
+        <PageContent>
+            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3" data-testid="plans-grid">
+                {#each page.plans as plan (plan.code)}
+                    <PlanCard
+                        {plan}
+                        isCurrent={page.currentPlanCode === plan.code}
+                        canSwitch={canSwitchTo(plan)}
+                        switchBlockedReason={switchBlockedReasonFor(plan)}
+                        {formatLimit}
+                        onSwitch={openConfirm}
+                    />
+                {/each}
+            </div>
+        </PageContent>
+    </PageContainer>
+</AppLayout>
+
+<ConfirmDialog
+    bind:open={confirmOpen}
+    title="プラン変更の確認"
+    message={`プランを「${planNameOf(confirmingPlanCode ?? "")}」に変更します。よろしいですか？お支払い手続きの画面 (Stripe) に移動します。`}
+    confirmLabel="変更する"
+    processing={submitting}
+    onConfirm={submitPlanChange}
+    onCancel={closeConfirm}
+    testId="plan-change-confirm"
+>
+    {#snippet banner()}
+        {#if planCodeError !== null}
+            <div class="mb-3">
+                <Alert type="danger" testId="plan-change-error">{planCodeError}</Alert>
+            </div>
+        {/if}
+    {/snippet}
+</ConfirmDialog>
diff --git a/resources/js/pages/Billing/PurchaseTickets.svelte b/resources/js/pages/Billing/PurchaseTickets.svelte
index 71ffd7b..8bcf536 100644
--- a/resources/js/pages/Billing/PurchaseTickets.svelte
+++ b/resources/js/pages/Billing/PurchaseTickets.svelte
@@ -1,6 +1,6 @@
 <script lang="ts">
     import { page as inertiaPage, router } from "@inertiajs/svelte";
-    import { CircleCheck, ShoppingCart, Ticket } from "@lucide/svelte";
+    import { CircleCheck, ExternalLink, ShoppingCart, Ticket } from "@lucide/svelte";
     import Alert from "@/components/atoms/Alert.svelte";
     import Button from "@/components/atoms/Button.svelte";
     import Card from "@/components/atoms/Card.svelte";
@@ -10,8 +10,10 @@
     import AppLayout from "@/components/templates/AppLayout.svelte";
     import PageContainer from "@/components/templates/PageContainer.svelte";
     import PageContent from "@/components/templates/PageContent.svelte";
+    import { formatDate } from "@/lib/date-format";
     import type { SharedProps } from "@/lib/shared-props";
     import type { PurchaseTicketsPageProps } from "@/types/billing";
+    import { parseTicketCount } from "./ticketCount";
 
     /**
      * チケット購入画面 (current org スコープ)。
@@ -20,6 +22,9 @@
      *   Inertia::location が Stripe Checkout へ full page redirect する
      * - 送信ボタンは disabled にしない (不正値は押下時にエラー表示 + サーバ validation の二重防御)
      * - canManage=false のメンバーには購入依頼の案内を表示 (残高・料金表は表示 = 透明性維持)
+     * - formState (P8b): normal のみ購入フォームを描画する。resume / completed では
+     *   フォームを描画せず確定枚数を読み取りテキストで示し、明示的な CTA に置き換える
+     *   (disabled にはしない = 禁止事項 #8)
      */
     interface Props {
         page: PurchaseTicketsPageProps;
@@ -39,13 +44,8 @@
     let clientError = $state<string | null>(null);
 
     // 生入力を整数として厳格に解釈する (clamp / floor の暗黙補正をしない)。
-    // input[type=number] の bind:value は number を返すことがあるため String 経由で正規化する
-    const parsedCount = $derived.by<number | null>(() => {
-        const trimmed = String(countText).trim();
-        if (!/^\d+$/.test(trimmed)) return null;
-        const n = Number(trimmed);
-        return Number.isSafeInteger(n) ? n : null;
-    });
+    // 解釈規則は pages/Billing/ticketCount.ts が単一出典。
+    const parsedCount = $derived.by<number | null>(() => parseTicketCount(countText));
 
     const isValidCount = $derived(
         parsedCount !== null && parsedCount >= page.minCount && parsedCount <= page.maxCount,
@@ -101,7 +101,7 @@
         }
         router.post(
             "/purchase-tickets/checkout",
-            { count: parsedCount, attempt_token: page.attemptToken },
+            { count: parsedCount, attempt_token: page.ticketAttemptToken },
             {
                 onStart: () => {
                     submitting = true;
@@ -112,6 +112,17 @@
             },
         );
     }
+
+    // resume: 進行中の Stripe Checkout (外部 URL) へ遷移する
+    function continueCheckout(): void {
+        if (page.resumeUrl === null) return;
+        window.location.href = page.resumeUrl;
+    }
+
+    // 「新しく購入し直す」= ?fresh=1 で新しい attempt_token を強制発行する
+    function startFreshPurchase(): void {
+        router.get(page.newPurchaseUrl);
+    }
 </script>
 
 <AppLayout {appName}>
@@ -129,19 +140,91 @@
                     決済の確認後、残高に反映されます (通常数秒〜数分)。反映はページの再読み込みでご確認いただけます。
                 </Alert>
             {/if}
+
+            {#if page.formState === "resume"}
+                <Alert type="warning" title="決済手続きが進行中です" testId="purchase-resume-banner">
+                    前回の決済を続けるか、新しく購入し直してください。
+                </Alert>
+            {:else if page.formState === "completed"}
+                <Alert type="success" title="直前のご購入を受け付けています" testId="purchase-completed-banner">
+                    決済の確認後、残高に反映されます。続けて購入する場合は「もう一度購入する」からお進みください。
+                </Alert>
+            {/if}
+
             <Card padding="lg" testId="purchase-balance">
                 <div class="flex items-center gap-3">
                     <Ticket class="size-5 text-primary" aria-hidden="true" />
                     <div>
-                        <h2 class="text-h3">現在の残高</h2>
+                        <h2 class="text-h3">今すぐ使える残高</h2>
                         <p class="mt-1 text-body" data-testid="purchase-balance-count">
-                            {page.balance.toLocaleString("ja-JP")} 枚
+                            {page.balance.totalAvailable.toLocaleString("ja-JP")} 枚
                         </p>
                     </div>
                 </div>
+                <dl class="mt-4 grid gap-4 border-t border-border pt-4 sm:grid-cols-2">
+                    <div>
+                        <dt class="text-caption text-text-secondary">プラン付与残</dt>
+                        <dd class="mt-1 text-h3 text-text" data-testid="balance-monthly">
+                            {page.balance.monthlyRemaining.toLocaleString("ja-JP")} 枚
+                        </dd>
+                        <p class="text-caption text-text-secondary">
+                            プラン付与・初回特典分の残り（有効期限あり）
+                        </p>
+                    </div>
+                    <div>
+                        <dt class="text-caption text-text-secondary">購入済み残</dt>
+                        <dd class="mt-1 text-h3 text-text" data-testid="balance-purchased">
+                            {page.balance.purchasedRemaining.toLocaleString("ja-JP")} 枚
+                        </dd>
+                        <p
+                            class="inline-flex items-center gap-1 text-caption text-text-secondary"
+                            data-testid="purchased-bucket-caption"
+                        >
+                            <CircleCheck class="size-3.5" aria-hidden="true" />
+                            追加購入した分の残り。購入したチケットに有効期限はありません。
+                        </p>
+                    </div>
+                </dl>
+                {#if page.balance.nextExpireAt !== null}
+                    <p class="mt-3 text-caption text-text-secondary" data-testid="balance-next-expire">
+                        次の失効: {formatDate(page.balance.nextExpireAt, "—")}
+                    </p>
+                {/if}
             </Card>
 
-            {#if page.canManage}
+            {#if !page.canManage}
+                <Alert type="info" testId="purchase-role-note">
+                    チケットの購入は組織のオーナーまたは管理者が行えます。管理者に購入を依頼してください。
+                </Alert>
+            {:else if page.formState === "resume"}
+                <Card padding="lg" testId="purchase-resume">
+                    <h2 class="text-h3">進行中のお手続き</h2>
+                    <p class="mt-2 text-body" data-testid="purchase-bound-count">
+                        購入枚数 {(page.boundCount ?? 0).toLocaleString("ja-JP")} 枚
+                    </p>
+                    <div class="mt-6 flex flex-wrap gap-3">
+                        <Button onclick={continueCheckout} testId="purchase-resume-continue">
+                            <ExternalLink class="size-4" aria-hidden="true" />
+                            決済を続ける
+                        </Button>
+                        <Button variant="ghost" onclick={startFreshPurchase} testId="purchase-fresh">
+                            新しく購入し直す
+                        </Button>
+                    </div>
+                </Card>
+            {:else if page.formState === "completed"}
+                <Card padding="lg" testId="purchase-completed">
+                    <h2 class="text-h3">直前のご購入</h2>
+                    <p class="mt-2 text-body" data-testid="purchase-bound-count">
+                        購入枚数 {(page.boundCount ?? 0).toLocaleString("ja-JP")} 枚
+                    </p>
+                    <div class="mt-6">
+                        <Button onclick={startFreshPurchase} testId="purchase-fresh">
+                            もう一度購入する
+                        </Button>
+                    </div>
+                </Card>
+            {:else}
                 <Card padding="lg" testId="purchase-form">
                     <h2 class="text-h3">購入枚数</h2>
                     <div class="mt-4 flex max-w-xs flex-col gap-2">
@@ -185,10 +268,6 @@
                         Stripe の決済画面に移動します。決済確認後にチケットが付与されます。
                     </p>
                 </Card>
-            {:else}
-                <Alert type="info" testId="purchase-role-note">
-                    チケットの購入は組織のオーナーまたは管理者が行えます。管理者に購入を依頼してください。
-                </Alert>
             {/if}
 
             <section>
@@ -206,10 +285,6 @@
                         {/each}
                     </ul>
                 {/if}
-                <p class="mt-2 inline-flex items-center gap-1 text-caption text-text-secondary">
-                    <CircleCheck class="size-3.5" aria-hidden="true" />
-                    購入したチケットに有効期限はありません。
-                </p>
             </section>
             </div>
         </PageContent>
diff --git a/resources/js/pages/Billing/_helpers/PlanCard.svelte b/resources/js/pages/Billing/_helpers/PlanCard.svelte
new file mode 100644
index 0000000..8c9cf39
--- /dev/null
+++ b/resources/js/pages/Billing/_helpers/PlanCard.svelte
@@ -0,0 +1,93 @@
+<!--
+  PlanCard — Billing/Plans の page-local helper (aigenba `Billing/_helpers/PlanCard.svelte` 移植)。
+
+  page-local 配置は維持する: Billing 固有 props (isCurrent / canSwitch / onSwitch 等) を束ね、
+  共通の plan カード構造 (枠・価格・feature バレット) は molecules/PricingPlanCard へ委譲する
+  アダプタ。本 file は Billing/Plans 以外から import しない (= page-local 規約)。
+
+  D4 適合 (AGENTS.md 禁止事項 #8): aigenba は canSwitch=false を disabled ボタン +「変更不可」で
+  表現するが、AI-CUE では **CTA を enabled のまま**描画し、押下時に理由を Alert で表示する。
+  理由文言はカード内 caption としても常時可視にし、情報を失わない (意図的な非 parity)。
+-->
+<script lang="ts">
+    import Alert from "@/components/atoms/Alert.svelte";
+    import Badge from "@/components/atoms/Badge.svelte";
+    import Button from "@/components/atoms/Button.svelte";
+    import PricingPlanCard from "@/components/molecules/PricingPlanCard.svelte";
+    import type { PricingFeature } from "@/components/molecules/PricingPlanCard.types";
+    import type { PricingPlanShape } from "@/types/marketing";
+
+    interface Props {
+        plan: PricingPlanShape;
+        isCurrent: boolean;
+        canSwitch: boolean;
+        /** canSwitch=false の理由 (常時 caption 表示 + 押下時 Alert)。canSwitch=true では空文字 */
+        switchBlockedReason: string;
+        formatLimit: (value: number | null) => string;
+        onSwitch: (planCode: string) => void;
+    }
+
+    let { plan, isCurrent, canSwitch, switchBlockedReason, formatLimit, onSwitch }: Props = $props();
+
+    // 押下時にだけ立てる transient state (押せる状態は維持し、理由は押下後に明示する)。
+    let blockedShown = $state(false);
+
+    // 月次のチケット付与は廃止済 (常に 0 枚) のため表記しない (料金ページと同一方針。D28)。
+    // 語彙は公開料金表 (Guest/Pricing の buildFeatures) と同一出典。
+    const features = $derived<PricingFeature[]>([
+        { text: `プロジェクト ${formatLimit(plan.maxProjects)}` },
+        { text: `メンバー ${formatLimit(plan.maxMembers)} 名` },
+        {
+            text:
+                plan.maxStorageGb === null
+                    ? "ストレージ 無制限"
+                    : `ストレージ ${plan.maxStorageGb} GB`,
+        },
+    ]);
+
+    // baseAmountJpy null = plan_prices (base) を持たない無料プラン → PricingPlanCard が「無料」表示。
+    const priceAmount = $derived(plan.baseAmountJpy);
+
+    function handleClick(): void {
+        if (!canSwitch) {
+            blockedShown = true;
+            return;
+        }
+        blockedShown = false;
+        onSwitch(plan.code);
+    }
+</script>
+
+<PricingPlanCard
+    name={plan.name}
+    {priceAmount}
+    priceCaption="基本料金"
+    isHighlighted={isCurrent}
+    {features}
+    testId={`plan-card-${plan.code}`}
+>
+    {#snippet headerBadges()}
+        {#if isCurrent}
+            <Badge tone="primary" testId={`plan-current-badge-${plan.code}`}>現在のプラン</Badge>
+        {/if}
+    {/snippet}
+    {#snippet footerCta()}
+        {#if blockedShown && switchBlockedReason !== ""}
+            <div class="mb-3">
+                <Alert type="warning" testId="plan-switch-blocked">{switchBlockedReason}</Alert>
+            </div>
+        {/if}
+        <Button fullWidth onclick={handleClick} testId={`plan-change-${plan.code}`}>
+            このプランへ変更
+        </Button>
+        {#if switchBlockedReason !== ""}
+            <!-- 押下前から理由を可視化する (disabled で情報を失わないための常時 caption) -->
+            <p
+                class="mt-2 text-caption text-text-secondary"
+                data-testid={`plan-switch-reason-${plan.code}`}
+            >
+                {switchBlockedReason}
+            </p>
+        {/if}
+    {/snippet}
+</PricingPlanCard>
diff --git a/resources/js/pages/Billing/ticketCount.ts b/resources/js/pages/Billing/ticketCount.ts
new file mode 100644
index 0000000..25c63e4
--- /dev/null
+++ b/resources/js/pages/Billing/ticketCount.ts
@@ -0,0 +1,24 @@
+// チケット購入枚数の「文字列 → 整数」変換を 1 箇所に集約した純関数 (aigenba verbatim 移植)。
+//
+// 型責務の分離:
+//   - UI draft 型 = string (PurchaseTickets.svelte の countText は常に string で保持)
+//   - domain value 型 = number | null (本関数の戻り値)
+//
+// `<Input type="number">` への two-way `bind:value` は Svelte 5 が値を number に強制するため、
+// draft を string で保つ構造にしても本関数は防御的に `String(raw)` を噛ませ、万一 number が
+// 渡っても throw しない。
+//
+// 許容形式は「符号付き整数のみ」に固定する。`1e3` (指数) / `0x10` (16進) / `1.5` (小数) /
+// `Infinity` / `"-"` / `"1."` / 空文字 は全て null に倒し、暗黙補正 (clamp/floor) はしない。
+// 範囲 (min/max) 検証は呼び出し側とサーバ validation の責務。
+
+const INTEGER_RE = /^-?\d+$/;
+
+export function parseTicketCount(raw: string | number): number | null {
+    const trimmed = String(raw).trim();
+    if (!INTEGER_RE.test(trimmed)) {
+        return null;
+    }
+    const n = Number(trimmed);
+    return Number.isInteger(n) ? n : null;
+}
diff --git a/resources/js/pages/Pricing.svelte b/resources/js/pages/Guest/Pricing.svelte
similarity index 74%
rename from resources/js/pages/Pricing.svelte
rename to resources/js/pages/Guest/Pricing.svelte
index a957e9b..c60cb73 100644
--- a/resources/js/pages/Pricing.svelte
+++ b/resources/js/pages/Guest/Pricing.svelte
@@ -25,6 +25,11 @@
     const formatYen = (v: number): string => new Intl.NumberFormat("ja-JP").format(v);
     const formatLimit = (v: number | null): string => (v === null ? "無制限" : String(v));
 
+    // 三層構成: 「個人でご利用の方」(personal 無料バナー) と「法人でご利用の方」(カードグリッド) +
+    // 大規模利用バナー。personal は個人用であることを強調するためグリッドから分離する。
+    const personalPlan = $derived(page.plans.find((plan) => plan.code === "personal") ?? null);
+    const corporatePlans = $derived(page.plans.filter((plan) => plan.code !== "personal"));
+
     const buildFeatures = (plan: PricingPlanShape): PricingFeature[] => [
         { text: `プロジェクト ${formatLimit(plan.maxProjects)}` },
         { text: `メンバー ${formatLimit(plan.maxMembers)} 名` },
@@ -50,7 +55,7 @@
     const faqs = $derived([
         {
             q: "無料で試せますか？",
-            a: `はい。Personal プランは基本料金なしでご利用いただけます。さらにプランを有効化すると初回 1 回だけチケット ${page.signupGrantTickets} 枚 (${page.signupGrantExpiryDays} 日間有効) が無料でついてくるので、AI 解析から動画の完成までを実際にお試しいただけます。`,
+            a: `パーソナルプランは基本料金無料でご利用いただけます。さらにプランを有効化すると初回 1 回だけチケット ${page.signupGrantTickets} 枚 (${page.signupGrantExpiryDays} 日間有効) が無料でついてくるので、AI 解析から動画の完成までを実際にお試しいただけます。`,
         },
         {
             q: "チケットは何に使いますか？",
@@ -86,7 +91,7 @@
         <div class="text-center">
             <h1 class="text-h1 text-text">料金プラン</h1>
             <p class="mt-3 text-body text-text-secondary">
-                無料で始めて、必要になったらチームで広げる。シンプルな 2 プランです。
+                個人から法人まで、規模や利用量に合わせて選べるプランをご用意しています。
             </p>
         </div>
 
@@ -106,9 +111,48 @@
             </p>
         </div>
 
-        <!-- プランカード -->
-        <div class="mx-auto mt-10 grid max-w-3xl gap-4 sm:grid-cols-2" data-testid="pricing-plan-grid">
-            {#each page.plans as plan (plan.code)}
+        {#if personalPlan !== null}
+            <!-- 個人でご利用の方: Personal (無料) は個人用であることを強調する専用バナー。 -->
+            <div class="mx-auto mt-10 max-w-3xl">
+                <h2 class="text-h3 text-text-secondary">個人でご利用の方</h2>
+                <div
+                    class="mt-3 flex flex-col gap-4 rounded-lg border border-primary/30 bg-primary-soft p-6 sm:flex-row sm:items-center sm:justify-between"
+                    data-testid="personal-banner"
+                >
+                    <div>
+                        <p class="flex flex-wrap items-baseline gap-x-3">
+                            <span class="text-h3 text-text">{personalPlan.name}</span>
+                            <span class="text-h2 text-text">無料</span>
+                        </p>
+                        <p class="mt-1 text-body text-text-secondary">
+                            基本料金はかからず、AI 解析・動画レンダに使うチケット代だけでご利用いただけます。
+                            プロジェクト {formatLimit(personalPlan.maxProjects)}・メンバー
+                            {formatLimit(personalPlan.maxMembers)} 名・ストレージ
+                            {personalPlan.maxStorageGb === null
+                                ? "無制限"
+                                : `${personalPlan.maxStorageGb} GB`}。
+                        </p>
+                    </div>
+                    <div class="flex shrink-0 flex-col items-center gap-1">
+                        {#if page.isAuthenticated}
+                            <Button href="/billing/plans" inertia>プランを変更</Button>
+                        {:else}
+                            <Button href={`/register?plan=${encodeURIComponent(personalPlan.code)}`}>
+                                基本料金無料で始める
+                            </Button>
+                        {/if}
+                        <p class="text-caption text-text-secondary" data-testid="personal-click-trigger">
+                            カード登録なしで開始・まずは無料チケット {page.signupGrantTickets} 枚から
+                        </p>
+                    </div>
+                </div>
+            </div>
+        {/if}
+
+        <!-- 法人でご利用の方: personal を除いたプランカード -->
+        <h2 class="mx-auto mt-8 max-w-3xl text-h3 text-text-secondary">法人でご利用の方</h2>
+        <div class="mx-auto mt-3 grid max-w-3xl gap-4 sm:grid-cols-2" data-testid="pricing-plan-grid">
+            {#each corporatePlans as plan (plan.code)}
                 <PricingPlanCard
                     name={plan.name}
                     priceAmount={plan.baseAmountJpy}
@@ -118,7 +162,7 @@
                 >
                     {#snippet footerCta()}
                         {#if page.isAuthenticated}
-                            <Button href="/billing" fullWidth inertia>プランを変更</Button>
+                            <Button href="/billing/plans" fullWidth inertia>プランを変更</Button>
                         {:else}
                             <Button href={`/register?plan=${encodeURIComponent(plan.code)}`} fullWidth>
                                 このプランで始める
diff --git a/resources/js/types/billing.ts b/resources/js/types/billing.ts
index 2b595e6..8c164c8 100644
--- a/resources/js/types/billing.ts
+++ b/resources/js/types/billing.ts
@@ -1,40 +1,82 @@
-import type { PurchaseTierShape } from "@/types/marketing";
+import type { PricingPlanShape, PurchaseTierShape } from "@/types/marketing";
 
 /**
  * 課金ページの Inertia props。
  * PHP 側 DTO (App\DataTransferObjects\Billing\*) の @phpstan-type shape と exact 対。
  */
 
+/**
+ * PHP: OnboardingBillingState (backed enum) の value 集合と exact 対。
+ * 分岐退行を型で検知するため union を明示する (string にしない)。
+ */
+export type BillingStateValue =
+    | "no_subscription"
+    | "pending_checkout"
+    | "expired_checkout"
+    | "subscribed"
+    | "active_free_plan";
+
+/**
+ * PHP: TicketBalanceDto (TicketBalanceShape) と対。
+ * per-source の**表示値** (clamp 済み)。UI はこの値をそのまま描画し、再計算・clamp しない。
+ * 債務 (負残高) の概念は持たない = 債務行を UI に足さないこと。
+ */
+export interface TicketBalanceShape {
+    readonly monthlyRemaining: number;
+    readonly purchasedRemaining: number;
+    readonly totalAvailable: number;
+    readonly activeReservations: number;
+    readonly nextExpireAt: string | null;
+}
+
+/** PHP: QuotaLimitsDto (QuotaLimitsShape) と対 (null = 無制限) */
+export interface QuotaLimitsShape {
+    readonly maxProjects: number | null;
+    readonly maxMembers: number | null;
+    readonly maxStorageGb: number | null;
+}
+
+/** 購入フォームの状態 (PHP: PurchaseFormState) */
+export type PurchaseFormStateValue = "normal" | "resume" | "completed";
+
 /** PHP: PurchaseTicketsPageDto (PurchaseTicketsPageShape) と対 */
 export interface PurchaseTicketsPageProps {
     readonly tiers: readonly PurchaseTierShape[];
     readonly minCount: number;
     readonly maxCount: number;
     readonly defaultCount: number;
-    readonly balance: number;
+    readonly balance: TicketBalanceShape;
     readonly canManage: boolean;
-    readonly attemptToken: string;
+    /** チケット決済専用の attempt token (サブスク checkout 用とは別 key 空間) */
+    readonly ticketAttemptToken: string;
     readonly purchased: boolean;
     /** P8a: オートリチャージが有効か (既定 false) */
     readonly autoRechargeEnabled: boolean;
+    readonly formState: PurchaseFormStateValue;
+    /** resume / completed で確定している枚数 (normal は null) */
+    readonly boundCount: number | null;
+    /** resume の「決済を続ける」遷移先 (Stripe Checkout URL) */
+    readonly resumeUrl: string | null;
+    /** 「新しく購入し直す」= ?fresh=1 の自画面 URL */
+    readonly newPurchaseUrl: string;
 }
 
-/** Billing/Index (課金ページ) の Inertia props */
-export interface BillingIndexPlanPrice {
-    readonly unitAmount: number;
-    readonly currency: string;
-}
-
-export interface BillingIndexPlan {
-    readonly code: string;
-    readonly name: string;
-    readonly price: BillingIndexPlanPrice | null;
+/** PHP: BillingPlansPageDto (BillingPlansPageShape) と対 */
+export interface BillingPlansPageProps {
+    readonly plans: readonly PricingPlanShape[];
+    /** 表示用の現在プラン code (gate 判定には使わない) */
+    readonly currentPlanCode: string | null;
+    readonly billingState: BillingStateValue;
+    readonly canManage: boolean;
 }
 
-export interface BillingIndexProps {
-    readonly plans: readonly BillingIndexPlan[];
-    readonly currentPlanCode: string | null;
-    readonly ticketBalance: number;
+/** PHP: BillingDashboardDto (BillingDashboardShape) と対 */
+export interface BillingDashboardProps {
+    readonly plan: PricingPlanShape | null;
+    readonly billingState: BillingStateValue;
+    readonly currentPeriodEnd: string | null;
+    readonly balance: TicketBalanceShape;
+    readonly quotas: QuotaLimitsShape;
     readonly canManageBilling: boolean;
     /**
      * 課金ゲートで中断された「元の画面」への復帰先 (same-origin 内部 path)。
diff --git a/routes/web.php b/routes/web.php
index e51e200..f555e44 100644
--- a/routes/web.php
+++ b/routes/web.php
@@ -321,6 +321,9 @@
     */
     Route::get('/billing', [BillingController::class, 'index'])
         ->name('billing.index');
+    // P8b (bs-6): プラン比較。閲覧は組織メンバー全員 (変更操作のみ manageBilling)。
+    Route::get('/billing/plans', [BillingController::class, 'plans'])
+        ->name('billing.plans');
     Route::post('/billing/checkout', [BillingController::class, 'checkout'])
         ->name('billing.checkout');
     Route::post('/billing/portal', [BillingController::class, 'portal'])
diff --git a/tests/Feature/Billing/AutoRechargeEndpointTest.php b/tests/Feature/Billing/AutoRechargeEndpointTest.php
index 6bd552b..32535e1 100644
--- a/tests/Feature/Billing/AutoRechargeEndpointTest.php
+++ b/tests/Feature/Billing/AutoRechargeEndpointTest.php
@@ -175,10 +175,10 @@
 
     $props = $response->viewData('page')['props'];
 
-    expect($props)->toHaveKey('autoRecharge')
-        ->and($props['autoRecharge']['enabled'])->toBeFalse()
-        ->and($props['autoRecharge']['canManage'])->toBeTrue()
-        ->and($props)->toHaveKey('autoRechargeSetupToken');
+    expect($props['page'])->toHaveKey('autoRecharge')
+        ->and($props['page']['autoRecharge']['enabled'])->toBeFalse()
+        ->and($props['page']['autoRecharge']['canManage'])->toBeTrue()
+        ->and($props['page'])->toHaveKey('autoRechargeSetupToken');
 });
 
 test('member でも autoRecharge props は届くが canManage=false (閲覧は全員)', function (): void {
@@ -190,7 +190,7 @@
         ->get('/billing')
         ->assertOk();
 
-    expect($response->viewData('page')['props']['autoRecharge']['canManage'])->toBeFalse();
+    expect($response->viewData('page')['props']['page']['autoRecharge']['canManage'])->toBeFalse();
 });
 
 test('setup 台帳行があっても BillingAccess::state() は PendingCheckout にならない (P2 契約の回帰)', function (): void {
diff --git a/tests/Feature/Billing/BillingPageTest.php b/tests/Feature/Billing/BillingPageTest.php
index 86e6a67..6d912d3 100644
--- a/tests/Feature/Billing/BillingPageTest.php
+++ b/tests/Feature/Billing/BillingPageTest.php
@@ -14,7 +14,8 @@
  * 認可・validation 失敗経路のみ検証する)。
  */
 
-test('owner は /billing でプラン一覧・残高・管理フラグを見られる', function (): void {
+test('owner は /billing で現在プラン・per-bucket 残高・quota・管理フラグを見られる', function (): void {
+    // P8b: プラン一覧は /billing/plans へ移設 (期待は BillingPlansPageTest が持つ)。
     [$organization, $owner] = createOrganizationWithOwner();
     app(TicketLedgerService::class)->grant($organization, 10, '初期付与');
 
@@ -22,21 +23,27 @@
         ->assertOk()
         ->assertInertia(fn (Assert $page) => $page
             ->component('Billing/Index')
-            // sort_order 昇順 (personal 1 / starter 2 / standard 3。free 行は D11 で撤去済み)
-            ->has('plans', 3)
-            ->where('plans.0.code', 'personal')
-            ->where('plans.0.price', null) // activate 経由の無料プラン = Price 無し
-            ->where('plans.1.code', 'starter')
-            ->has('plans.1.price', fn (Assert $price) => $price
-                ->where('unitAmount', 980)
-                ->where('currency', 'jpy'))
-            ->where('plans.2.code', 'standard')
-            ->has('plans.2.price', fn (Assert $price) => $price
-                ->where('unitAmount', 4980)
-                ->where('currency', 'jpy'))
-            ->where('currentPlanCode', null)
-            ->where('ticketBalance', 10)
-            ->where('canManageBilling', true));
+            ->missing('plans') // インラインのプラン一覧は撤去済み
+            ->where('page.plan.code', 'personal') // ActiveFreePlan = free_plan_code が正
+            ->where('page.billingState', 'active_free_plan')
+            ->where('page.currentPeriodEnd', null)
+            ->where('page.balance.totalAvailable', 10)
+            ->where('page.balance.monthlyRemaining', 0)
+            ->where('page.balance.purchasedRemaining', 10)
+            ->where('page.quotas.maxProjects', 1)
+            ->where('page.quotas.maxMembers', 3)
+            ->where('page.quotas.maxStorageGb', 1)
+            ->where('page.canManageBilling', true));
+});
+
+test('未契約 org の /billing では現在プランが null で届く', function (): void {
+    [, $owner] = createOrganizationWithOwner(grandfatherFreePlan: false);
+
+    $this->actingAs($owner)->get('/billing')
+        ->assertOk()
+        ->assertInertia(fn (Assert $page) => $page
+            ->where('page.plan', null)
+            ->where('page.billingState', 'no_subscription'));
 });
 
 test('member も閲覧できるが管理フラグは false', function (): void {
@@ -48,7 +55,7 @@
         ->assertOk()
         ->assertInertia(fn (Assert $page) => $page
             ->component('Billing/Index')
-            ->where('canManageBilling', false));
+            ->where('page.canManageBilling', false));
 });
 
 test('member は checkout を開始できない (403)', function (): void {
@@ -108,7 +115,10 @@
 });
 
 test('owner の portal は fake gateway 経由で中立帰還 URL へ遷移する (happy path)', function (): void {
-    [, $owner] = createOrganizationWithOwner();
+    // P8b (bs-11): portal は有償サブスク前提の事前ガードを通る必要がある
+    // (未契約 / ActiveFreePlan の遮断は BillingPortalGuardTest が固定)。
+    [$organization, $owner] = createOrganizationWithOwner(grandfatherFreePlan: false);
+    contractPaidPlan($organization);
     $this->app->bind(StripeGatewayInterface::class, FakeStripeGateway::class);
 
     $response = $this->actingAs($owner)->post('/billing/portal');
diff --git a/tests/Feature/Billing/BillingPlansPageTest.php b/tests/Feature/Billing/BillingPlansPageTest.php
new file mode 100644
index 0000000..0597a2b
--- /dev/null
+++ b/tests/Feature/Billing/BillingPlansPageTest.php
@@ -0,0 +1,85 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Models\User;
+use App\Services\Billing\Contracts\StripeGatewayInterface;
+use App\Services\Billing\Fakes\FakeStripeGateway;
+use Inertia\Testing\AssertableInertia as Assert;
+
+/*
+ * P8b (bs-6): プラン提示の専用ページ (/billing/plans)。
+ *
+ * Billing/Index からプラン一覧を移設した先。表示用の currentPlanCode 解決規則は
+ * ActiveFreePlan なら free_plan_code、それ以外は plan_code (gate 判定には使わない)。
+ */
+
+test('owner は /billing/plans で公開プラン一覧と表示状態を受け取る', function (): void {
+    [, $owner] = createOrganizationWithOwner();
+
+    $this->actingAs($owner)->get('/billing/plans')
+        ->assertOk()
+        ->assertInertia(fn (Assert $page) => $page
+            ->component('Billing/Plans')
+            // sort_order 昇順 (personal 1 / starter 2 / standard 3。free 行は D11 で撤去済み)
+            ->has('page.plans', 3)
+            ->where('page.plans.0.code', 'personal')
+            ->where('page.plans.0.baseAmountJpy', null)
+            ->where('page.plans.1.code', 'starter')
+            ->where('page.plans.2.code', 'standard')
+            ->where('page.plans.2.maxStorageGb', 50)
+            ->where('page.currentPlanCode', 'personal')
+            ->where('page.billingState', 'active_free_plan')
+            ->where('page.canManage', true));
+});
+
+test('ActiveFreePlan の org では plan_code に旧 paid が残っていても free 側が currentPlanCode', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $organization->forceFill(['plan_code' => 'standard'])->save();
+    createFakeSubscription($organization, status: 'canceled');
+
+    $this->actingAs($owner)->get('/billing/plans')
+        ->assertOk()
+        ->assertInertia(fn (Assert $page) => $page
+            ->where('page.billingState', 'active_free_plan')
+            ->where('page.currentPlanCode', 'personal'));
+});
+
+test('有償契約中の org では plan_code が currentPlanCode', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner(grandfatherFreePlan: false);
+    contractPaidPlan($organization);
+
+    $this->actingAs($owner)->get('/billing/plans')
+        ->assertOk()
+        ->assertInertia(fn (Assert $page) => $page
+            ->where('page.billingState', 'subscribed')
+            ->where('page.currentPlanCode', 'standard'));
+});
+
+test('member も閲覧できるが canManage=false', function (): void {
+    [$organization] = createOrganizationWithOwner();
+    $member = attachOrganizationMember($organization);
+    $member->forceFill(['current_organization_id' => $organization->id])->save();
+
+    $this->actingAs($member)->get('/billing/plans')
+        ->assertOk()
+        ->assertInertia(fn (Assert $page) => $page
+            ->component('Billing/Plans')
+            ->where('page.canManage', false));
+});
+
+test('current organization が無いユーザーは 404', function (): void {
+    $user = User::factory()->create();
+
+    $this->actingAs($user)->get('/billing/plans')->assertNotFound();
+});
+
+test('POST /billing/checkout は plan_code のみで成立する (attempt token を要求しない)', function (): void {
+    [, $owner] = createOrganizationWithOwner(grandfatherFreePlan: false);
+    $this->app->bind(StripeGatewayInterface::class, FakeStripeGateway::class);
+
+    $response = $this->actingAs($owner)->post('/billing/checkout', ['plan_code' => 'standard']);
+
+    $response->assertStatus(302);
+    expect($response->headers->get('Location'))->toContain('fake_external=stripe');
+});
diff --git a/tests/Feature/Billing/BillingPortalGuardTest.php b/tests/Feature/Billing/BillingPortalGuardTest.php
new file mode 100644
index 0000000..9db12d0
--- /dev/null
+++ b/tests/Feature/Billing/BillingPortalGuardTest.php
@@ -0,0 +1,49 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Services\Billing\Contracts\StripeGatewayInterface;
+use App\Services\Billing\Fakes\FakeStripeGateway;
+
+/*
+ * P8b (bs-11): Customer Portal の事前ガード。
+ *
+ * Portal は Stripe customer + サブスク前提。free personal (canceled サブスク行が残る
+ * paid→free を含む) / 未契約 org は Cashier の assertCustomerExists() 例外 (= 500) に
+ * 到達させず、error flash で back する (fail-closed)。
+ */
+
+test('未契約 org (サブスク行なし) の owner は portal に到達せず error flash で戻る', function (): void {
+    [, $owner] = createOrganizationWithOwner(grandfatherFreePlan: false);
+    // Fake gateway を bind しておき「呼ばれない」ことを到達判定に使う
+    $this->app->bind(StripeGatewayInterface::class, FakeStripeGateway::class);
+
+    $response = $this->from('/billing')->actingAs($owner)->post('/billing/portal');
+
+    $response->assertRedirect('/billing');
+    $response->assertSessionHas('error', 'お支払い管理画面は有償プラン契約後にご利用いただけます。');
+    // Stripe (fake) に到達していない = 外部 URL への遷移になっていない
+    expect($response->headers->get('Location'))->not->toContain('fake_external=stripe');
+});
+
+test('ActiveFreePlan (canceled サブスク行が残る) org の owner も portal に到達しない', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner(); // free_plan_code='personal'
+    createFakeSubscription($organization, status: 'canceled');
+    $this->app->bind(StripeGatewayInterface::class, FakeStripeGateway::class);
+
+    $response = $this->from('/billing')->actingAs($owner)->post('/billing/portal');
+
+    $response->assertRedirect('/billing');
+    $response->assertSessionHas('error', 'お支払い管理画面は有償プラン契約後にご利用いただけます。');
+});
+
+test('有償サブスクを持つ owner は従来どおり Portal URL へ遷移する', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner(grandfatherFreePlan: false);
+    contractPaidPlan($organization);
+    $this->app->bind(StripeGatewayInterface::class, FakeStripeGateway::class);
+
+    $response = $this->actingAs($owner)->post('/billing/portal');
+
+    $response->assertStatus(302);
+    expect($response->headers->get('Location'))->toContain('fake_external=stripe');
+});
diff --git a/tests/Feature/Billing/TicketCheckoutTest.php b/tests/Feature/Billing/TicketCheckoutTest.php
index 534cc87..c17eb02 100644
--- a/tests/Feature/Billing/TicketCheckoutTest.php
+++ b/tests/Feature/Billing/TicketCheckoutTest.php
@@ -36,7 +36,7 @@ function checkoutPayload(int $count = 30, ?string $token = null): array
     $this->post('/purchase-tickets/checkout', checkoutPayload())->assertRedirect('/login');
 });
 
-test('owner は購入画面で tiers / 残高 / canManage / attemptToken を受け取る', function (): void {
+test('owner は購入画面で tiers / per-bucket 残高 / canManage / ticketAttemptToken を受け取る', function (): void {
     [, $owner] = createOrganizationWithOwner();
 
     $this->actingAs($owner)->get('/purchase-tickets')
@@ -49,10 +49,14 @@ function checkoutPayload(int $count = 30, ?string $token = null): array
             ->where('page.minCount', 1)
             ->where('page.maxCount', 1000)
             ->where('page.defaultCount', 10)
-            ->where('page.balance', 0)
+            ->where('page.balance.totalAvailable', 0)
+            ->where('page.balance.monthlyRemaining', 0)
+            ->where('page.balance.purchasedRemaining', 0)
+            ->where('page.balance.nextExpireAt', null)
             ->where('page.canManage', true)
             ->where('page.purchased', false)
-            ->has('page.attemptToken'));
+            ->where('page.formState', 'normal')
+            ->has('page.ticketAttemptToken'));
 });
 
 test('fake_external marker query は purchased 表示に転用されない (アプリ非解釈)', function (): void {
diff --git a/tests/Feature/Billing/TicketPurchaseResumeStateTest.php b/tests/Feature/Billing/TicketPurchaseResumeStateTest.php
new file mode 100644
index 0000000..3671b53
--- /dev/null
+++ b/tests/Feature/Billing/TicketPurchaseResumeStateTest.php
@@ -0,0 +1,154 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\OrganizationRole;
+use App\Models\Billing\TicketCheckoutSession;
+use App\Services\Billing\TicketCheckoutGateway;
+use Carbon\CarbonImmutable;
+use Inertia\Testing\AssertableInertia as Assert;
+use Tests\Support\FakeTicketCheckoutGateway;
+
+/*
+ * P8b (tc-5): 購入画面の状態機械 + ticketAttemptToken のサーバ側安定化。
+ *
+ * - live pending (自分が開始した決済待ち) があれば resume へ写像し、token を再利用する
+ *   (ブラウザバック / bfcache 復帰で既存 replay 冪等が効く = 二重課金しない)
+ * - 完了直後 (窓内) は completed。窓外は normal
+ * - 非管理者には resume / completed を出さない (resumeUrl は Stripe 直リンクで gate を迂回する)
+ * - 他 user の pending は resume しない (initiated_by_user_id スコープ)
+ */
+
+test('live pending がある owner は resume 状態で既存 token / count / resumeUrl を受け取る', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $session = TicketCheckoutSession::factory()
+        ->forOrganization($organization)
+        ->initiatedBy($owner)
+        ->create(['ticket_count' => 42]);
+
+    $this->actingAs($owner)->get('/purchase-tickets')
+        ->assertOk()
+        ->assertInertia(fn (Assert $page) => $page
+            ->component('Billing/PurchaseTickets')
+            ->where('page.formState', 'resume')
+            ->where('page.ticketAttemptToken', $session->attempt_token)
+            ->where('page.boundCount', 42)
+            ->where('page.resumeUrl', $session->checkout_url)
+            ->where('page.newPurchaseUrl', fn (string $url): bool => str_contains($url, 'fresh=1')));
+});
+
+test('?fresh=1 は resume を捨てて normal + 別 token に倒す', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $session = TicketCheckoutSession::factory()
+        ->forOrganization($organization)
+        ->initiatedBy($owner)
+        ->create();
+
+    $this->actingAs($owner)->get('/purchase-tickets?fresh=1')
+        ->assertOk()
+        ->assertInertia(fn (Assert $page) => $page
+            ->where('page.formState', 'normal')
+            ->where('page.boundCount', null)
+            ->where('page.resumeUrl', null)
+            ->where('page.ticketAttemptToken', fn (string $t): bool => $t !== $session->attempt_token));
+});
+
+test('窓内の完了 session は completed 状態 (resumeUrl は null)', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    TicketCheckoutSession::factory()
+        ->forOrganization($organization)
+        ->initiatedBy($owner)
+        ->completed()
+        ->create(['ticket_count' => 7, 'completed_at' => CarbonImmutable::now()->subMinutes(5)]);
+
+    $this->actingAs($owner)->get('/purchase-tickets')
+        ->assertOk()
+        ->assertInertia(fn (Assert $page) => $page
+            ->where('page.formState', 'completed')
+            ->where('page.boundCount', 7)
+            ->where('page.resumeUrl', null));
+});
+
+test('窓外の完了 session は normal へ縮退する', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    TicketCheckoutSession::factory()
+        ->forOrganization($organization)
+        ->initiatedBy($owner)
+        ->completed()
+        ->create(['completed_at' => CarbonImmutable::now()->subMinutes(31)]);
+
+    $this->actingAs($owner)->get('/purchase-tickets')
+        ->assertOk()
+        ->assertInertia(fn (Assert $page) => $page
+            ->where('page.formState', 'normal')
+            ->where('page.boundCount', null));
+});
+
+test('期限切れ pending は resume しない (normal)', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    TicketCheckoutSession::factory()
+        ->forOrganization($organization)
+        ->initiatedBy($owner)
+        ->stale()
+        ->create();
+
+    $this->actingAs($owner)->get('/purchase-tickets')
+        ->assertOk()
+        ->assertInertia(fn (Assert $page) => $page
+            ->where('page.formState', 'normal')
+            ->where('page.resumeUrl', null));
+});
+
+test('非管理者 (member) には live pending があっても resume を出さない', function (): void {
+    [$organization] = createOrganizationWithOwner();
+    $member = attachOrganizationMember($organization);
+    $member->forceFill(['current_organization_id' => $organization->id])->save();
+    TicketCheckoutSession::factory()
+        ->forOrganization($organization)
+        ->initiatedBy($member)
+        ->create();
+
+    $this->actingAs($member)->get('/purchase-tickets')
+        ->assertOk()
+        ->assertInertia(fn (Assert $page) => $page
+            ->where('page.formState', 'normal')
+            ->where('page.resumeUrl', null));
+});
+
+test('他 user が開始した pending は resume しない (initiated_by_user_id スコープ)', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $admin = attachOrganizationMember($organization, OrganizationRole::Admin);
+    $admin->forceFill(['current_organization_id' => $organization->id])->save();
+    TicketCheckoutSession::factory()
+        ->forOrganization($organization)
+        ->initiatedBy($owner)
+        ->create();
+
+    $this->actingAs($admin)->get('/purchase-tickets')
+        ->assertOk()
+        ->assertInertia(fn (Assert $page) => $page
+            ->where('page.formState', 'normal')
+            ->where('page.resumeUrl', null));
+});
+
+test('resume 表示の token を再送しても Stripe session は増えず同一 URL へ収束する', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $fake = new FakeTicketCheckoutGateway;
+    app()->instance(TicketCheckoutGateway::class, $fake);
+
+    $session = TicketCheckoutSession::factory()
+        ->forOrganization($organization)
+        ->initiatedBy($owner)
+        ->create(['ticket_count' => 30]);
+
+    // 画面 render 由来の安定 token をそのまま再送する (ブラウザバック相当)
+    $response = $this->actingAs($owner)->post('/purchase-tickets/checkout', [
+        'count' => 30,
+        'attempt_token' => $session->attempt_token,
+    ]);
+
+    $response->assertStatus(302);
+    expect($response->headers->get('Location'))->toBe($session->checkout_url)
+        ->and($fake->created)->toBe([])
+        ->and(TicketCheckoutSession::query()->where('organization_id', $organization->id)->count())->toBe(1);
+});
diff --git a/tests/Feature/LegalPagesTest.php b/tests/Feature/LegalPagesTest.php
index 25da183..8e01ab8 100644
--- a/tests/Feature/LegalPagesTest.php
+++ b/tests/Feature/LegalPagesTest.php
@@ -27,5 +27,5 @@
     // 公開ページなので noindex を付けない。
     expect($response->headers->get('X-Robots-Tag'))->toBeNull();
 
-    $response->assertInertia(fn (Assert $page) => $page->component('Pricing'));
+    $response->assertInertia(fn (Assert $page) => $page->component('Guest/Pricing'));
 });
diff --git a/tests/Feature/Marketing/PricingPageTest.php b/tests/Feature/Marketing/PricingPageTest.php
index 8dc0465..a99ff44 100644
--- a/tests/Feature/Marketing/PricingPageTest.php
+++ b/tests/Feature/Marketing/PricingPageTest.php
@@ -31,7 +31,7 @@ function seededBaseAmount(string $code): int
     $this->get('/pricing')
         ->assertOk()
         ->assertInertia(fn (Assert $page) => $page
-            ->component('Pricing')
+            ->component('Guest/Pricing')
             ->has('page.plans', 3) // sort_order 昇順 (personal 1 / starter 2 / standard 3。free 行は D11 で撤去済み)
             ->where('page.plans.0.code', 'personal')
             ->where('page.plans.0.baseAmountJpy', null) // Price 無し = 無料表示契約
diff --git a/tests/Feature/Onboarding/OnboardingReturnFlowTest.php b/tests/Feature/Onboarding/OnboardingReturnFlowTest.php
index 8165a8e..c4c0990 100644
--- a/tests/Feature/Onboarding/OnboardingReturnFlowTest.php
+++ b/tests/Feature/Onboarding/OnboardingReturnFlowTest.php
@@ -86,12 +86,12 @@
         ->assertOk()
         ->assertInertia(fn (Assert $page) => $page
             ->component('Billing/Index')
-            ->where('continueUrl', '/projects'));
+            ->where('page.continueUrl', '/projects'));
 
     // 1 回限り: リロードでは CTA が残らない
     $this->actingAs($owner)->get('/billing')
         ->assertOk()
-        ->assertInertia(fn (Assert $page) => $page->where('continueUrl', null));
+        ->assertInertia(fn (Assert $page) => $page->where('page.continueUrl', null));
 });
 
 test('billing.index は未契約 (grantsAccess 不成立) では continueUrl を出さず return_to も消さない', function (): void {
@@ -100,7 +100,7 @@
 
     $this->actingAs($owner)->get('/billing')
         ->assertOk()
-        ->assertInertia(fn (Assert $page) => $page->where('continueUrl', null));
+        ->assertInertia(fn (Assert $page) => $page->where('page.continueUrl', null));
 
     expect(session(OnboardingReturnResolver::orgKey($organization)))->toBe('/projects');
 });
@@ -112,5 +112,5 @@
 
     $this->actingAs($owner)->get('/billing')
         ->assertOk()
-        ->assertInertia(fn (Assert $page) => $page->where('continueUrl', null));
+        ->assertInertia(fn (Assert $page) => $page->where('page.continueUrl', null));
 });
diff --git a/tests/js/components/molecules/PricingPlanCard.test.ts b/tests/js/components/molecules/PricingPlanCard.test.ts
index 00c8caf..e46430c 100644
--- a/tests/js/components/molecules/PricingPlanCard.test.ts
+++ b/tests/js/components/molecules/PricingPlanCard.test.ts
@@ -52,4 +52,29 @@ describe("PricingPlanCard", () => {
         expect(screen.getByTestId("price-caption")).toHaveTextContent("基本料金");
         expect(card).toHaveTextContent("特典 A");
     });
+
+    it("headerBadges を渡すと header 行に描画する", () => {
+        const headerBadges = createRawSnippet(() => ({
+            render: () => "<span data-testid='badge-slot'>現在のプラン</span>",
+        }));
+        render(PricingPlanCard, {
+            props: {
+                name: "テストプラン",
+                priceAmount: 4980,
+                features: [{ text: "特典 A" }],
+                testId: "plan-card",
+                headerBadges,
+                footerCta,
+            },
+        });
+
+        expect(screen.getByTestId("badge-slot")).toHaveTextContent("現在のプラン");
+    });
+
+    it("headerBadges 未指定でも既存出力は不変 (回帰)", () => {
+        renderCard(4980);
+
+        expect(screen.queryByTestId("badge-slot")).toBeNull();
+        expect(screen.getByTestId("plan-card")).toHaveTextContent("テストプラン");
+    });
 });
diff --git a/tests/js/pages/Billing/Index.test.ts b/tests/js/pages/Billing/Index.test.ts
new file mode 100644
index 0000000..d5c760f
--- /dev/null
+++ b/tests/js/pages/Billing/Index.test.ts
@@ -0,0 +1,146 @@
+import { afterEach, describe, expect, it, vi } from "vitest";
+import { cleanup, render, screen } from "@testing-library/svelte";
+import Index from "@/pages/Billing/Index.svelte";
+import type { BillingDashboardProps } from "@/types/billing";
+
+const { routerPostMock, pageState } = vi.hoisted(() => ({
+    routerPostMock: vi.fn(),
+    pageState: { props: {} as Record<string, unknown> },
+}));
+
+vi.mock("@inertiajs/svelte", async (importOriginal) => ({
+    ...(await importOriginal<typeof import("@inertiajs/svelte")>()),
+    router: { post: routerPostMock },
+    page: pageState,
+}));
+
+/*
+ * 課金ダッシュボード。プラン一覧は /billing/plans へ移設済み (plan-list は持たない)。
+ * per-bucket 残高 / quota 上限 / portal 出し分けを固定する。
+ */
+
+const basePage: BillingDashboardProps = {
+    plan: {
+        code: "personal",
+        name: "Personal",
+        baseAmountJpy: null,
+        maxProjects: 1,
+        maxMembers: 3,
+        maxStorageGb: 1,
+    },
+    billingState: "active_free_plan",
+    currentPeriodEnd: null,
+    balance: {
+        monthlyRemaining: 4,
+        purchasedRemaining: 6,
+        totalAvailable: 10,
+        activeReservations: 0,
+        nextExpireAt: null,
+    },
+    quotas: { maxProjects: 1, maxMembers: 3, maxStorageGb: 1 },
+    canManageBilling: true,
+    continueUrl: null,
+    autoRecharge: {
+        enabled: false,
+        thresholdCount: 5,
+        maxCount: 50,
+        minCount: 1,
+        maxCountLimit: 1000,
+        canManage: true,
+        hasPaymentMethod: false,
+        paymentMethodBrand: null,
+        paymentMethodLast4: null,
+        setupPending: false,
+        requiresReconsent: false,
+        pendingAutoEnable: false,
+        disabledReason: null,
+        failureCount: 0,
+        consentVersion: "v1",
+        baseUnitAmountJpy: 100,
+        tiers: [{ minCount: 1, unitAmount: 100 }],
+    },
+    autoRechargeSetupToken: "01j0000000000000000000test",
+};
+
+afterEach(() => {
+    cleanup();
+    routerPostMock.mockReset();
+    pageState.props = {};
+});
+
+describe("Billing/Index", () => {
+    it("プラン一覧を持たず「プラン比較」導線を出す", () => {
+        render(Index, { props: { page: basePage } });
+
+        expect(screen.queryByTestId("plan-list")).toBeNull();
+        expect(screen.getByTestId("billing-plans-link").getAttribute("href")).toContain(
+            "/billing/plans",
+        );
+    });
+
+    it("active_free_plan では portal ボタンを出さず「月額 無料（チケット代のみ）」を出す", () => {
+        render(Index, { props: { page: basePage } });
+
+        expect(screen.queryByTestId("billing-portal-button")).toBeNull();
+        expect(screen.getByTestId("current-plan-card")).toHaveTextContent(
+            "月額 無料（チケット代のみ）",
+        );
+        expect(screen.queryByTestId("current-period-end")).toBeNull();
+    });
+
+    it("subscribed では portal ボタンと次回請求日を出す", () => {
+        render(Index, {
+            props: {
+                page: {
+                    ...basePage,
+                    billingState: "subscribed",
+                    currentPeriodEnd: "2026-09-01T00:00:00+09:00",
+                    plan: { ...basePage.plan!, code: "standard", name: "Standard", baseAmountJpy: 4980 },
+                },
+            },
+        });
+
+        expect(screen.getByTestId("billing-portal-button")).toBeInTheDocument();
+        expect(screen.getByTestId("current-period-end")).toHaveTextContent("2026");
+        expect(screen.getByTestId("current-plan-card")).toHaveTextContent("月額 ¥4,980");
+    });
+
+    it("per-bucket 残高を描画し、債務行を持たない", () => {
+        render(Index, {
+            props: {
+                page: {
+                    ...basePage,
+                    balance: { ...basePage.balance, nextExpireAt: "2026-09-01T00:00:00+09:00" },
+                },
+            },
+        });
+
+        expect(screen.getByTestId("ticket-balance")).toHaveTextContent("10");
+        expect(screen.getByTestId("balance-monthly")).toHaveTextContent("4");
+        expect(screen.getByTestId("balance-purchased")).toHaveTextContent("6");
+        expect(screen.getByTestId("balance-next-expire")).toHaveTextContent("次の失効");
+        expect(screen.getByTestId("billing-balance").textContent ?? "").not.toContain("債務");
+    });
+
+    it("plan=null では未契約の案内を出す", () => {
+        render(Index, {
+            props: { page: { ...basePage, plan: null, billingState: "no_subscription" } },
+        });
+
+        expect(screen.getByTestId("no-plan-note")).toHaveTextContent("まだプランに契約していません");
+    });
+
+    it("quota 上限 (プロジェクト / メンバー / ストレージ) を描画する", () => {
+        render(Index, { props: { page: basePage } });
+
+        expect(screen.getByTestId("quota-max-projects")).toHaveTextContent("1");
+        expect(screen.getByTestId("quota-max-members")).toHaveTextContent("3");
+        expect(screen.getByTestId("quota-max-storage")).toHaveTextContent("1 GB");
+    });
+
+    it("auto-recharge カードの差し込み位置を持つ (実体は P8a 所管)", () => {
+        render(Index, { props: { page: basePage } });
+
+        expect(screen.getByTestId("auto-recharge-card")).toBeInTheDocument();
+    });
+});
diff --git a/tests/js/pages/Billing/PlanCard.test.ts b/tests/js/pages/Billing/PlanCard.test.ts
new file mode 100644
index 0000000..f058046
--- /dev/null
+++ b/tests/js/pages/Billing/PlanCard.test.ts
@@ -0,0 +1,108 @@
+import { afterEach, describe, expect, it, vi } from "vitest";
+import { cleanup, fireEvent, render, screen } from "@testing-library/svelte";
+import PlanCard from "@/pages/Billing/_helpers/PlanCard.svelte";
+import type { PricingPlanShape } from "@/types/marketing";
+
+/*
+ * Billing/Plans の page-local カード。
+ * - isCurrent で headerBadges に「現在のプラン」バッジが出る
+ * - canSwitch=false でも CTA は enabled のまま (禁止事項 #8 / DESIGN.md)。理由は常時 caption +
+ *   押下時 Alert で伝える
+ * - features に D28 で撤去した「月 N 枚」表記が含まれない
+ */
+
+const plan: PricingPlanShape = {
+    code: "standard",
+    name: "Standard",
+    baseAmountJpy: 4980,
+    maxProjects: 10,
+    maxMembers: 10,
+    maxStorageGb: 50,
+};
+
+const formatLimit = (v: number | null): string => (v === null ? "無制限" : String(v));
+
+afterEach(cleanup);
+
+describe("Billing/_helpers/PlanCard", () => {
+    it("isCurrent で「現在のプラン」バッジを出す", () => {
+        render(PlanCard, {
+            props: {
+                plan,
+                isCurrent: true,
+                canSwitch: false,
+                switchBlockedReason: "現在ご利用中のプランです",
+                formatLimit,
+                onSwitch: vi.fn(),
+            },
+        });
+
+        expect(screen.getByTestId("plan-current-badge-standard")).toHaveTextContent("現在のプラン");
+    });
+
+    it("canSwitch=false でも disabled にせず、理由を caption と押下時 Alert で伝える", async () => {
+        const onSwitch = vi.fn();
+        render(PlanCard, {
+            props: {
+                plan,
+                isCurrent: false,
+                canSwitch: false,
+                switchBlockedReason: "プランを変更する権限がありません",
+                formatLimit,
+                onSwitch,
+            },
+        });
+
+        // 常時可視の理由 caption (disabled で情報を失わない)
+        expect(screen.getByTestId("plan-switch-reason-standard")).toHaveTextContent(
+            "プランを変更する権限がありません",
+        );
+        // disabled 属性の button は存在しない
+        const cta = screen.getByTestId("plan-change-standard");
+        expect(cta.hasAttribute("disabled")).toBe(false);
+        expect(screen.queryByTestId("plan-switch-blocked")).toBeNull();
+
+        await fireEvent.click(cta);
+        expect(onSwitch).not.toHaveBeenCalled();
+        expect(screen.getByTestId("plan-switch-blocked")).toHaveTextContent(
+            "プランを変更する権限がありません",
+        );
+    });
+
+    it("canSwitch=true の押下は onSwitch に plan code を渡す", async () => {
+        const onSwitch = vi.fn();
+        render(PlanCard, {
+            props: {
+                plan,
+                isCurrent: false,
+                canSwitch: true,
+                switchBlockedReason: "",
+                formatLimit,
+                onSwitch,
+            },
+        });
+
+        await fireEvent.click(screen.getByTestId("plan-change-standard"));
+        expect(onSwitch).toHaveBeenCalledWith("standard");
+        expect(screen.queryByTestId("plan-switch-blocked")).toBeNull();
+    });
+
+    it("features は quota 上限のみで「月 N 枚」表記を含まない (D28)", () => {
+        render(PlanCard, {
+            props: {
+                plan,
+                isCurrent: false,
+                canSwitch: true,
+                switchBlockedReason: "",
+                formatLimit,
+                onSwitch: vi.fn(),
+            },
+        });
+
+        const card = screen.getByTestId("plan-card-standard");
+        expect(card).toHaveTextContent("プロジェクト 10");
+        expect(card).toHaveTextContent("メンバー 10 名");
+        expect(card).toHaveTextContent("ストレージ 50 GB");
+        expect(card.textContent ?? "").not.toMatch(/月\s*\d+\s*枚/);
+    });
+});
diff --git a/tests/js/pages/Billing/Plans.test.ts b/tests/js/pages/Billing/Plans.test.ts
new file mode 100644
index 0000000..656c7d2
--- /dev/null
+++ b/tests/js/pages/Billing/Plans.test.ts
@@ -0,0 +1,109 @@
+import { afterEach, describe, expect, it, vi } from "vitest";
+import { cleanup, fireEvent, render, screen } from "@testing-library/svelte";
+import Plans from "@/pages/Billing/Plans.svelte";
+import type { BillingPlansPageProps } from "@/types/billing";
+
+const { routerPostMock, pageState } = vi.hoisted(() => ({
+    routerPostMock: vi.fn(),
+    pageState: { props: {} as Record<string, unknown> },
+}));
+
+vi.mock("@inertiajs/svelte", async (importOriginal) => ({
+    ...(await importOriginal<typeof import("@inertiajs/svelte")>()),
+    router: { post: routerPostMock },
+    page: pageState,
+}));
+
+/*
+ * プラン比較ページ。確認ダイアログ経由で POST /billing/checkout に plan_code のみを送る。
+ * サーバ validation エラー時は dialog を開いたままサーバ文言を出す。
+ */
+
+const basePage: BillingPlansPageProps = {
+    plans: [
+        {
+            code: "personal",
+            name: "Personal",
+            baseAmountJpy: null,
+            maxProjects: 1,
+            maxMembers: 3,
+            maxStorageGb: 1,
+        },
+        {
+            code: "standard",
+            name: "Standard",
+            baseAmountJpy: 4980,
+            maxProjects: 10,
+            maxMembers: 10,
+            maxStorageGb: 50,
+        },
+    ],
+    currentPlanCode: "personal",
+    billingState: "active_free_plan",
+    canManage: true,
+};
+
+afterEach(() => {
+    cleanup();
+    routerPostMock.mockReset();
+    pageState.props = {};
+});
+
+describe("Billing/Plans", () => {
+    it("plans-grid に全プランを描画し、現在プランにバッジを出す", () => {
+        render(Plans, { props: { page: basePage } });
+
+        expect(screen.getByTestId("plans-grid")).toBeInTheDocument();
+        expect(screen.getByTestId("plan-card-personal")).toBeInTheDocument();
+        expect(screen.getByTestId("plan-card-standard")).toBeInTheDocument();
+        expect(screen.getByTestId("plan-current-badge-personal")).toHaveTextContent("現在のプラン");
+    });
+
+    it("「このプランへ変更」→ 確認 → plan_code のみを POST する", async () => {
+        render(Plans, { props: { page: basePage } });
+
+        await fireEvent.click(screen.getByTestId("plan-change-standard"));
+        const dialog = await screen.findByTestId("plan-change-confirm");
+        expect(dialog).toHaveTextContent("Standard");
+
+        await fireEvent.click(screen.getByText("変更する"));
+
+        expect(routerPostMock).toHaveBeenCalledTimes(1);
+        const [url, payload] = routerPostMock.mock.calls[0] as [string, Record<string, unknown>];
+        expect(url).toBe("/billing/checkout");
+        expect(payload).toEqual({ plan_code: "standard" });
+    });
+
+    it("errors.plan_code があるとき dialog にサーバ文言を描画する", async () => {
+        pageState.props = { errors: { plan_code: "選択したプランは現在お申し込みいただけません。" } };
+        render(Plans, { props: { page: basePage } });
+
+        await fireEvent.click(screen.getByTestId("plan-change-standard"));
+        await screen.findByTestId("plan-change-confirm");
+
+        expect(screen.getByTestId("plan-change-error")).toHaveTextContent(
+            "選択したプランは現在お申し込みいただけません。",
+        );
+    });
+
+    it("canManage=false でも CTA は enabled のまま (押下で理由を出す)", async () => {
+        render(Plans, { props: { page: { ...basePage, canManage: false } } });
+
+        const cta = screen.getByTestId("plan-change-standard");
+        expect(cta.hasAttribute("disabled")).toBe(false);
+
+        await fireEvent.click(cta);
+        expect(routerPostMock).not.toHaveBeenCalled();
+        expect(screen.getByTestId("plan-switch-blocked")).toHaveTextContent(
+            "プランを変更する権限がありません",
+        );
+    });
+
+    it("personal は本画面から変更できない旨を常時 caption で示す", () => {
+        render(Plans, { props: { page: basePage } });
+
+        expect(screen.getByTestId("plan-switch-reason-personal")).toHaveTextContent(
+            "現在ご利用中のプランです",
+        );
+    });
+});
diff --git a/tests/js/pages/Billing/ticketCount.test.ts b/tests/js/pages/Billing/ticketCount.test.ts
new file mode 100644
index 0000000..dc4bc58
--- /dev/null
+++ b/tests/js/pages/Billing/ticketCount.test.ts
@@ -0,0 +1,32 @@
+import { describe, expect, it } from "vitest";
+import { parseTicketCount } from "@/pages/Billing/ticketCount";
+
+/*
+ * 購入枚数の解釈は「符号付き整数のみ」。指数・16進・小数・Infinity・空文字は null に倒し、
+ * clamp / floor の暗黙補正をしない (範囲検証は呼び出し側 + サーバ validation の責務)。
+ */
+
+describe("parseTicketCount", () => {
+    it("符号付き整数を数値へ変換する", () => {
+        expect(parseTicketCount("10")).toBe(10);
+        expect(parseTicketCount("-5")).toBe(-5);
+        expect(parseTicketCount(" 42 ")).toBe(42);
+        expect(parseTicketCount("0")).toBe(0);
+    });
+
+    it("整数以外の表記は null に倒す (暗黙補正しない)", () => {
+        expect(parseTicketCount("1e3")).toBeNull();
+        expect(parseTicketCount("0x10")).toBeNull();
+        expect(parseTicketCount("1.5")).toBeNull();
+        expect(parseTicketCount("Infinity")).toBeNull();
+        expect(parseTicketCount("-")).toBeNull();
+        expect(parseTicketCount("1.")).toBeNull();
+        expect(parseTicketCount("")).toBeNull();
+        expect(parseTicketCount("abc")).toBeNull();
+    });
+
+    it("number が渡っても防御的に処理する (String 経由)", () => {
+        expect(parseTicketCount(10)).toBe(10);
+        expect(parseTicketCount(1.5)).toBeNull();
+    });
+});
diff --git a/tests/js/pages/Pricing.test.ts b/tests/js/pages/Guest/Pricing.test.ts
similarity index 76%
rename from tests/js/pages/Pricing.test.ts
rename to tests/js/pages/Guest/Pricing.test.ts
index 9f3c0dd..e136edb 100644
--- a/tests/js/pages/Pricing.test.ts
+++ b/tests/js/pages/Guest/Pricing.test.ts
@@ -1,6 +1,6 @@
 import { describe, expect, it } from "vitest";
 import { fireEvent, render, screen, within } from "@testing-library/svelte";
-import Pricing from "@/pages/Pricing.svelte";
+import Pricing from "@/pages/Guest/Pricing.svelte";
 import type { PricingPageProps } from "@/types/marketing";
 
 /*
@@ -52,17 +52,30 @@ const basePage: PricingPageProps = {
 };
 
 describe("Pricing", () => {
-    it("プランカード 2 枚を描画し personal は「無料」、standard は月額を表示する", () => {
+    it("三層構成 (個人バナー / 法人グリッド / 大規模利用バナー) を描画する", () => {
         render(Pricing, { props: { page: basePage } });
 
-        const freeCard = screen.getByTestId("pricing-plan-personal");
-        expect(freeCard).toHaveTextContent("無料");
-        expect(freeCard).not.toHaveTextContent("¥");
+        // personal はグリッドから分離した専用バナー (個人利用専用であることを強調)
+        const personalBanner = screen.getByTestId("personal-banner");
+        expect(personalBanner).toHaveTextContent("Personal");
+        expect(personalBanner).toHaveTextContent("無料");
+        expect(screen.queryByTestId("pricing-plan-personal")).toBeNull();
 
+        // 法人グリッドは personal を除いた残り
+        const grid = screen.getByTestId("pricing-plan-grid");
+        expect(grid).not.toHaveTextContent("Personal");
         const standardCard = screen.getByTestId("pricing-plan-standard");
         expect(standardCard).toHaveTextContent("¥4,980");
         expect(standardCard).toHaveTextContent("基本料金");
         expect(standardCard).toHaveTextContent("ストレージ 50 GB");
+
+        expect(screen.getByTestId("pricing-enterprise-banner")).toBeInTheDocument();
+    });
+
+    it("D28: プラン表記に「月 N 枚のチケット付与」を含まない", () => {
+        const { container } = render(Pricing, { props: { page: basePage } });
+
+        expect(container.textContent ?? "").not.toMatch(/月\s*\d+\s*枚のチケット付与/);
     });
 
     it("チケット帯 (X〜Y 枚 / 最終段 X 枚以上) と signup grant 注記を描画する", () => {
@@ -92,7 +105,7 @@ describe("Pricing", () => {
         await fireEvent.click(question);
         expect(question).toHaveAttribute("aria-expanded", "true");
         expect(
-            screen.getByText(/Personal プランは基本料金なしでご利用いただけます/),
+            screen.getByText(/パーソナルプランは基本料金無料でご利用いただけます/),
         ).toBeInTheDocument();
 
         await fireEvent.click(question);
@@ -101,15 +114,15 @@ describe("Pricing", () => {
 
     it("未認証は登録 CTA、認証済みはプラン変更 CTA を出す", () => {
         const { unmount } = render(Pricing, { props: { page: basePage } });
-        const ctas = screen.getAllByRole("link", { name: "このプランで始める" });
-        expect(ctas).toHaveLength(2);
-        // P7: 料金表 → /register?plan={code} で選択意図を handoff する。
+        // personal はバナー CTA、法人プランはカード CTA (P7: /register?plan={code} で handoff)
+        const personalCta = screen.getByRole("link", { name: "基本料金無料で始める" });
+        const gridCtas = screen.getAllByRole("link", { name: "このプランで始める" });
+        expect(gridCtas).toHaveLength(1);
         // 期待値は PlanCode allowlist に実在する code のみ (normalizeRaw が null 化しない値)。
-        expect(ctas.map((cta) => new URL((cta as HTMLAnchorElement).href).search)).toEqual([
-            "?plan=personal",
-            "?plan=standard",
-        ]);
-        for (const cta of ctas) {
+        expect(
+            [personalCta, ...gridCtas].map((cta) => new URL((cta as HTMLAnchorElement).href).search),
+        ).toEqual(["?plan=personal", "?plan=standard"]);
+        for (const cta of [personalCta, ...gridCtas]) {
             expect(new URL((cta as HTMLAnchorElement).href).pathname).toBe("/register");
         }
         unmount();
diff --git a/tests/js/pages/PurchaseTickets.test.ts b/tests/js/pages/PurchaseTickets.test.ts
index 6fb632c..dc0da50 100644
--- a/tests/js/pages/PurchaseTickets.test.ts
+++ b/tests/js/pages/PurchaseTickets.test.ts
@@ -5,8 +5,9 @@ import type { PurchaseTicketsPageProps } from "@/types/billing";
 
 // router.post をモックする。page (Inertia store) も hoisted fake でモックし、
 // props.errors を注入して serverErrors 経路を検証できるようにする (既定は空 = 従来挙動)。
-const { routerPostMock, pageState } = vi.hoisted(() => ({
+const { routerPostMock, routerGetMock, pageState } = vi.hoisted(() => ({
     routerPostMock: vi.fn(),
+    routerGetMock: vi.fn(),
     pageState: { props: {} as Record<string, unknown> },
 }));
 
@@ -14,6 +15,7 @@ vi.mock("@inertiajs/svelte", async (importOriginal) => ({
     ...(await importOriginal<typeof import("@inertiajs/svelte")>()),
     router: {
         post: routerPostMock,
+        get: routerGetMock,
     },
     page: pageState,
 }));
@@ -36,16 +38,27 @@ const basePage: PurchaseTicketsPageProps = {
     minCount: 1,
     maxCount: 1000,
     defaultCount: 10,
-    balance: 3,
+    balance: {
+        monthlyRemaining: 1,
+        purchasedRemaining: 2,
+        totalAvailable: 3,
+        activeReservations: 0,
+        nextExpireAt: null,
+    },
     canManage: true,
-    attemptToken: "01J0000000000000000000TEST",
+    ticketAttemptToken: "01J0000000000000000000TEST",
     purchased: false,
     autoRechargeEnabled: false,
+    formState: "normal",
+    boundCount: null,
+    resumeUrl: null,
+    newPurchaseUrl: "/purchase-tickets?fresh=1",
 };
 
 afterEach(() => {
     cleanup();
     routerPostMock.mockReset();
+    routerGetMock.mockReset();
     pageState.props = {}; // errors 注入をリセット (テスト間の汚染防止)
 });
 
@@ -59,6 +72,8 @@ describe("Billing/PurchaseTickets", () => {
         render(PurchaseTickets, { props: { page: basePage } });
 
         expect(screen.getByTestId("purchase-balance-count")).toHaveTextContent("3 枚");
+        expect(screen.getByTestId("balance-monthly")).toHaveTextContent("1 枚");
+        expect(screen.getByTestId("balance-purchased")).toHaveTextContent("2 枚");
         expect(screen.getByTestId("purchase-tier-table")).toHaveTextContent("1〜19 枚");
         expect(screen.getByTestId("purchase-total")).toHaveTextContent(
             "単価 ¥100 × 10 枚 = 合計 ¥1,000",
@@ -189,4 +204,58 @@ describe("Billing/PurchaseTickets", () => {
             "決済の確認後、残高に反映されます",
         );
     });
+
+    it("「有効期限はありません」は購入済みバケツの caption 位置に出る (誤読防止)", () => {
+        render(PurchaseTickets, { props: { page: basePage } });
+
+        expect(screen.getByTestId("purchased-bucket-caption")).toHaveTextContent(
+            "購入したチケットに有効期限はありません",
+        );
+    });
+
+    it("nextExpireAt があれば次の失効を表示する", () => {
+        render(PurchaseTickets, {
+            props: {
+                page: {
+                    ...basePage,
+                    balance: { ...basePage.balance, nextExpireAt: "2026-09-01T00:00:00+09:00" },
+                },
+            },
+        });
+
+        expect(screen.getByTestId("balance-next-expire")).toHaveTextContent("次の失効");
+    });
+
+    it("formState=resume では購入フォームを描画せず確定枚数と 2 種の CTA を出す", () => {
+        render(PurchaseTickets, {
+            props: {
+                page: {
+                    ...basePage,
+                    formState: "resume",
+                    boundCount: 42,
+                    resumeUrl: "https://checkout.stripe.test/c/pay/cs_test_1",
+                },
+            },
+        });
+
+        expect(screen.queryByTestId("purchase-form")).toBeNull();
+        expect(screen.getByTestId("purchase-resume-banner")).toBeInTheDocument();
+        expect(screen.getByTestId("purchase-bound-count")).toHaveTextContent("42 枚");
+        expect(screen.getByTestId("purchase-resume-continue")).toBeInTheDocument();
+        // disabled にはしない (禁止事項 #8)
+        expect(screen.getByTestId("purchase-fresh").hasAttribute("disabled")).toBe(false);
+    });
+
+    it("formState=completed では完了バナーと「もう一度購入する」を出す", async () => {
+        render(PurchaseTickets, {
+            props: { page: { ...basePage, formState: "completed", boundCount: 7 } },
+        });
+
+        expect(screen.queryByTestId("purchase-form")).toBeNull();
+        expect(screen.getByTestId("purchase-completed-banner")).toBeInTheDocument();
+        expect(screen.getByTestId("purchase-bound-count")).toHaveTextContent("7 枚");
+
+        await fireEvent.click(screen.getByTestId("purchase-fresh"));
+        expect(routerGetMock).toHaveBeenCalledWith("/purchase-tickets?fresh=1");
+    });
 });

```

---

## 設計書 P8b 節 (正本。逸脱不可)

### P8b: 課金 UI parity（Guest/Pricing 三層 + Billing/Plans + PlanCard + PurchaseTickets 状態機械 + Index 情報密度）+ 監査「判断不要 15 件」の消化

前提（v2）: P1〜P7 / P8a がマージ済み。すなわち **`PlanCode` 5 case**・**`plans.is_active` は P1 で `true` seed 済み（再公開フェーズは存在しない）**・**`App\Enums\Billing\OnboardingBillingState` + `BillingAccess::state()`（aigenba verbatim）**・**per-bucket `TicketBalanceDto`（`monthlyRemaining` / `purchasedRemaining` / `totalAvailable` / `activeReservations` / `nextExpireAt`。**`debt` は存在しない**）**・**D28 により全 tier `monthly_ticket_grant = 0`**・`?plan=` handoff（P7）・AutoRecharge（P8a）が既にある。本フェーズは **UI 層と、それを支える Controller / DTO のみ**を触る。会計（`TicketLedgerService`）と Stripe 境界（`*Gateway`）には手を入れない（監査 ticket-charge-11 の 4 分割境界を維持）。

**所管の境界**: **billing contact（列 / フォーム / props / 更新 Action）・checkout 着地 feedback（`resolveBillingFeedback` / feedback バナー）・subscription checkout 用 attempt token は P9 所管**であり本フェーズに登場しない。**チケット決済の `ticketAttemptToken` は既存冪等マシンに必要なため維持・安定化する**（P9 の subscription 用とは型名で区別）。

##### 監査「A. 判断不要 = 機械的に aigenba へ寄せられる (15 件)」の消化台帳

| # | finding | 本フェーズでの対応 |
|---|---|---|
| 1 | registration-funnel-7 `EmailVerificationContinuation` | **P7 で完了**。P8b では触らない。 |
| 2 | registration-funnel-11 `OnboardingReturnResolver` | **P7 で完了**。P8b では触らない。 |
| 3 | registration-funnel-14 オンボ外枠の T071 primitive 整合 | **P3 で完了**（`Onboarding/{Checkout,BillingRequired}.svelte`）。P8b は同じ規約を新設 `Billing/Plans.svelte` に適用する（aigenba の `PageHeaderSection` + breadcrumbs / `max-w-6xl` 直書きは移植しない = 監査 ticket-charge-7 が「breadcrumbs はサイト共通ナビ方針として別 slice」と結論済み）。 |
| 4 | pricing-plans-6 `Billing/_helpers/PlanCard.svelte` | **P8b で実施**（下記 (a)）。移植元 `/tmp/aigenba/resources/js/pages/Billing/_helpers/PlanCard.svelte`。 |
| 5 | pricing-plans-7 `PricingPlanCard` の headerBadges / contactLabel | **headerBadges snippet を追加**（← `/tmp/aigenba/resources/js/components/molecules/PricingPlanCard.svelte`）。**contactLabel は追加しない** — AI-CUE に enterprise の Plan 行が無く（`PlanSeeder` = personal / starter / standard）、監査 action 自体が「enterprise プラン採否に連動」と条件付き（原則 4）。既存コメント（`/workspace/resources/js/components/molecules/PricingPlanCard.svelte:7-8`）どおり大規模利用はカード外バナーの責務を維持する。 |
| 6 | billing-subscription-4 `SubscriptionService` / `SubscriptionSnapshot` | **P2 で完了**。P8b は `BillingAccess::state()` の返す `OnboardingBillingState` を読むだけで、gate 判定を UI / Controller で再実装しない。 |
| 7 | billing-subscription-6 料金プラン画面 `Billing/Plans` | **P8b で実施**（下記 (a)）。 |
| 8 | billing-subscription-7 サブスク checkout の冪等・着地 feedback | **P9 へ移譲**。`Billing/Plans` の POST body は **`{plan_code}` のみ**とする（aigenba の `attempt_token` 同梱は P9 の成果物が揃ってから）。 |
| 9 | billing-subscription-10 `BillingCustomerSynchronizer` / 請求先情報 | **P9 へ移譲**（列 + 更新 Action + 同期 job + フォーム + props の全体）。 |
| 10 | billing-subscription-11 Customer Portal の事前ガード | **P8b で実施**（下記 (c)）。移植元 `/tmp/aigenba/app/Http/Controllers/Billing/BillingController.php:978-1002`。 |
| 11 | billing-subscription-14 `Billing/Index` の構造と情報密度 | **P8b で実施**（下記 (d)）。外枠は既に T071 準拠のため是正不要。プラン一覧を `Billing/Plans` へ移設し、Index を請求ダッシュボード（現在プラン / per-bucket 残高 / quota / 導線）へ寄せる。auto-recharge カード実体は **P8a 所管**（P8b は差し込み位置のみ）。 |
| 12 | billing-subscription-15 `billing-required` 画面 | **P3 で完了**。P8b では触らない。 |
| 13 | ticket-charge-5 購入フォームの状態機械 + attempt_token 安定化 | **P8b で実施**（下記 (b)）。対象は `ticketAttemptToken`。 |
| 14 | ticket-charge-8 spot 単価の出典 | **合わせない（対応不要）**。監査 action の宿題「production の livemode / synced_at 必須チェック」は実装済み（`/workspace/app/Models/Billing/TicketVolumePrice.php:91-96`）。単一テーブル集約を維持。 |
| 15 | ticket-charge-11 サービス分割構造 | **合わせない（逆行しない）**。会計 = `TicketLedgerService` / 導線・状態 = Controller + DTO / Stripe = `*Gateway` の 4 分割境界を守り、`resolveResumablePurchase` は冪等 Checkout マシンの一部として `TicketCheckoutService` に置く。 |

#### 変更箇所（ファイルパス + 何をするか。移植元 aigenba のパスを併記）

**(a) プラン提示の専用ページ化（bs-6 / pp-6 / pp-7）**

- 新規 `/workspace/resources/js/pages/Billing/Plans.svelte` ← `/tmp/aigenba/resources/js/pages/Billing/Plans.svelte`
  - `AppLayout > PageContainer > PageHeader > PageContent`（T071 primitive。aigenba の `PageHeaderSection` + breadcrumbs は採らない）。title「プラン比較」/ description「現在のプランの変更・新規契約ができます」/ icon `CreditCard` は verbatim。
  - `data-testid="plans-grid"` に `PlanCard` を並べる（aigenba :171-189 verbatim）。
  - `canSwitchTo` / `disabledReasonFor`（aigenba :60-90）を **AI-CUE に事実が存在する分岐だけ verbatim 移植**する: `!canManage` →「プランを変更する権限がありません」/ `currentPlanCode === plan.code` →「現在ご利用中のプランです」/ `isPersonal(plan)` →「パーソナルプラン（無料）は個人専用のため、こちらからは変更できません」。**移植しない分岐**: enterprise（Plan 行なし）/ starter 自動移行（AI-CUE に自動移行が無い = 監査 pricing-plans-4 は要プロダクト判断）/ `pendingPlanCode`（変更予約が無い = 監査 pricing-plans-9）。
  - 送信は既存 `POST /billing/checkout`、**body は `{plan_code}` のみ**（aigenba :117-119 の `attempt_token` 同梱は P9 所管）。`plan-change` / `upgrade-now` / `earlyUpgradePlanCodes` は移植しない（要プロダクト判断・原則 4）。aigenba の「free plan は plan-change でなく checkout」（:100-109）は AI-CUE では全経路が checkout のため自然に成立する。
  - 確認ダイアログは AI-CUE 既存 `organisms/ConfirmDialog.svelte` を使う。aigenba の inline `Modal` + `@confirm-modal` selector は **aigenba 自身が「browser test 都合の負債。ConfirmDialog atom への置換を検討」とコメントしている**（Plans.svelte:192-199）ため、その負債は移植しない。サーバ validation エラー（`page.props.errors.plan_code`）は dialog 内に `Alert` で描画し、**成功時のみ閉じる**（aigenba :121-127 verbatim）。
- 新規 `/workspace/resources/js/pages/Billing/_helpers/PlanCard.svelte` ← `/tmp/aigenba/resources/js/pages/Billing/_helpers/PlanCard.svelte`
  - page-local adapter 規約（`Billing/Plans` 以外から import しない）をコメントごと踏襲し、`PricingPlanCard` 分子へ委譲する。
  - **移植する**: `isCurrent`（headerBadges「現在のプラン」Badge）/ `canSwitch` / features 組み立て / `formatYen` / `formatLimit` / `priceAmount`（personal は `0` を渡して「無料」表示。aigenba :98-100）。
  - **移植しない（データ源が AI-CUE に無い）**: `includedSeats` / `currentSeatAmount`（席課金）/ `isPending` / `isStarter` + `starterMigrationText` / `isEnterprise` + contact CTA。
  - **features は D28 準拠**: aigenba のコメント「月次のチケット付与は廃止済 (常に 0 枚) のため表記しない (料金ページと同一方針)」（PlanCard.svelte:78）を verbatim で採る。AI-CUE の Plan 台帳の語彙に写して `プロジェクト {formatLimit(maxProjects)}` / `メンバー {formatLimit(maxMembers)} 名` / `ストレージ {maxStorageGb} GB`（出典は `/workspace/resources/js/pages/Pricing.svelte:28-38` の `buildFeatures` と同一）。
  - **D4 適合（AGENTS.md 禁止事項 #8 = 原則 2 の逸脱理由）**: aigenba は `canSwitch=false` を `disabled` ボタン +「変更不可」+ `title` / `aria-label` で表現する（:146-157）が、**AI-CUE では CTA を enabled のまま描画**し、押下時に理由を `Alert`（`data-testid="plan-switch-blocked"`）で表示する。理由文言（`switchBlockedReason`。aigenba の `disabledReason` 相当）はカード内 caption としても常時可視にし、情報を失わない。`disabled` 属性は使わない。
- 変更 `/workspace/resources/js/components/molecules/PricingPlanCard.svelte`: `headerBadges?: Snippet` を追加し `<h3>` 行を `flex items-center justify-between` へ（← `/tmp/aigenba/resources/js/components/molecules/PricingPlanCard.svelte:29,:56-59`）。`contactLabel` は追加しない。未指定時の出力は不変。
- 変更 `/workspace/app/Http/Controllers/Billing/BillingController.php`: `plans()` を新設（← aigenba `BillingController::plans()` :399-440）。`index()` のプラン一覧構築（:43-58）を移す。プラン台帳 → DTO の mapper は **aigenba が Billing / Marketing 双方で `PlanDto::fromModel` を共有している**のと同型に、AI-CUE 既存の `PricingService::listPublicPlans()`（`PricingPlanDto`）を共有する（新 DTO を発明しない）。`currentPlanCode` の解決規則は aigenba verbatim: `state() === ActiveFreePlan ? $organization->free_plan_code : $organization->plan_code`（**表示用途のみ。gate 判定には使わない**）。
- 変更 `/workspace/routes/web.php`: `Route::get('/billing/plans', [BillingController::class, 'plans'])->name('billing.plans');` を billing 群（課金ゲート allowlist 内・**route parameter を持たない current-org スコープ**）へ追加。

**(b) 購入画面: per-bucket 残高 + 状態機械 + ticketAttemptToken 安定化（tc-5）**

- 新規 `/workspace/resources/js/pages/Billing/ticketCount.ts` ← `/tmp/aigenba/resources/js/pages/Billing/ticketCount.ts`（**verbatim**。`^-?\d+$` の符号付き整数のみ許容し clamp / floor しない。docblock も移植）。`/workspace/resources/js/pages/Billing/PurchaseTickets.svelte:43-48` のインライン正規表現（`^\d+$`）を置換する。
- 新規 `/workspace/app/Enums/Billing/PurchaseFormState.php` ← `/tmp/aigenba/app/Enums/Billing/PurchaseFormState.php`（`Normal|Resume|Completed`。**verbatim**）。
- 変更 `/workspace/resources/js/pages/Billing/PurchaseTickets.svelte` ← `/tmp/aigenba/resources/js/pages/Billing/PurchaseTickets.svelte`
  - 残高カードを **per-bucket 表示**へ（aigenba :255-290 verbatim）: 「今すぐ使える残高」= `balance.totalAvailable` / 「プラン付与残」= `balance.monthlyRemaining` + caption「プラン付与・初回特典分の残り（有効期限あり）」/ 「購入済み残」= `balance.purchasedRemaining` + caption「追加購入した分の残り」/ `data-testid="balance-next-expire"` = `balance.nextExpireAt`。**`debt` 行は無い**（aigenba に概念が存在しない）。出典は P5 の `TicketBalanceDto` のみで、画面で再計算しない。
  - `formState` による出し分け（aigenba :205-215 / :292-359 verbatim）: `normal` = 購入フォーム / `resume` = 進行中バナー +「決済を続ける」（`resumeUrl` へ `window.location.href`）+「新しく購入し直す」（`newPurchaseUrl` = `?fresh=1`）/ `completed` = 完了バナー +「もう一度購入する」。**resume / completed では購入フォームを描画せず `boundCount` を読み取りテキストで表示**する（`disabled` にしない = 禁止事項 #8）。
  - **単位は「枚」を維持**（aigenba の「回」は移植しない = 監査 ticket-charge-10 が「要プロダクト判断」に分類済み。AI-CUE の可変コスト消費という製品語彙）。
  - 既存の「購入したチケットに有効期限はありません」（:209-212）は **purchased バケツの caption 位置へ移す**（aigenba の per-bucket caption と同じ位置。monthly / signup grant 分と誤読されない = tc-10 の誤読リスク解消）。
  - submit ボタンは `disabled` にしない（aigenba :353 の `disabled={submitting || countError !== null}` は D4 により非移植。既存 `/workspace/tests/js/pages/PurchaseTickets.test.ts:86` の契約を維持）。
  - POST body は既存契約どおり `{count, attempt_token}`（サーバ `TicketCheckoutRequest` の field 名は不変。props 名のみ `attemptToken` → `ticketAttemptToken`）。
- 変更 `/workspace/app/Http/Controllers/Billing/TicketPurchaseController.php:42-73` ← aigenba `BillingController::showPurchase()`（:461-520）
  - `attemptToken: (string) Str::ulid()` の毎 render 発行をやめ、**`canManage && ! $request->boolean('fresh')` のときのみ** `resolveResumablePurchase()` 由来の token を再利用する（aigenba :479-481 verbatim。ブラウザバック / bfcache で既存 replay 冪等が効く）。
  - `match(true)` による `[$formState, $attemptToken, $boundCount, $resumeUrl]` の写像を verbatim 移植（aigenba :484-502）。
  - **非管理者には resume / completed を出さない**（aigenba :476-478 のコメントごと移植。`resumeUrl` は Stripe 直リンクで purchase gate を迂回する）。
  - `balance:` を `int` から `TicketLedgerService::balance()` の `TicketBalanceDto` へ差し替える。
- 変更 `/workspace/app/Services/Billing/TicketCheckoutService.php`: `resolveResumablePurchase()` を追加（← aigenba `TicketService.php:1393-1417`）。AI-CUE の `TicketCheckoutSession` は購入専用テーブルのため aigenba の `intent` 絞り込みは不要。live pending 判定は既存 `TicketCheckoutSession::isLivePending()` を使う。会計には触れない。
- 変更 `/workspace/config/billing.php`: `purchase_resume_window_minutes`（**既定 30 = aigenba の既定値そのまま**）を追加。

**(c) Customer Portal の事前ガード（bs-11）**

- 変更 `/workspace/app/Http/Controllers/Billing/BillingController.php:98-104` ← aigenba `BillingController::redirectToPortal()`（:978-1002）
  - `Gate::authorize('manageBilling')` の後、**`state() === ActiveFreePlan` または `subscription('default')` が無い**なら `back()->with('error', 'お支払い管理画面は有償プラン契約後にご利用いただけます。')`（**文言含め verbatim**）。Cashier `ManagesCustomer::billingPortalUrl()` の `assertCustomerExists()`（例外 = 500）に到達させない。
- 変更 `/workspace/resources/js/pages/Billing/Index.svelte:121`: portal ボタンの表示条件を `canManageBilling && billingState !== 'active_free_plan'` へ（aigenba Index.svelte:85,:181 verbatim。UI は `billingState` から導出し、独自の `canOpenPortal` prop を発明しない）。

**(d) Billing/Index の情報密度（bs-14）**

- 変更 `/workspace/resources/js/pages/Billing/Index.svelte` ← `/tmp/aigenba/resources/js/pages/Billing/Index.svelte`
  - `data-testid="plan-list"` のインラインプラン一覧（:139-171）と page-local `formatPrice` / `startCheckout` を**撤去**し、「プラン比較」導線（`/billing/plans`・`data-testid="billing-plans-link"`）へ置換する。
  - 現在プランカード（`data-testid="current-plan-card"`。aigenba :225-260 verbatim）: `plan !== null` なら プラン名（`data-testid="current-plan-code"`）+ 月額（`billingState === 'active_free_plan'` なら「月額 無料（チケット代のみ）」/ それ以外は `¥{baseAmountJpy}`）+ **`active_free_plan` 以外でのみ**「次回請求日」。`plan === null` なら「まだプランに契約していません。「プラン比較」から新規契約できます。」（aigenba :255-258 の文言を導線名だけ合わせて移植）。
  - 残高カードを **per-bucket**（aigenba :263-310 verbatim。totalAvailable / monthlyRemaining / purchasedRemaining / `balance-next-expire`。**債務行は無い**）へ。「月 {monthlyTicketGrant} 枚のチケット付与」表記（:154）は **D28 により撤去**する。
  - quota 表示（`maxProjects` / `maxMembers` / `maxStorageGb` の現行 limits）を追加する。aigenba の `QuotaSnapshotDto`（使用量つき）は AI-CUE に集計経路が無いため移植せず（原則 4）、`QuotaService::limits()`（override 反映済み）の値のみを出す。
  - `data-testid="auto-recharge-card"` の差し込み位置と `?highlight=auto-recharge` の着地 anchor を用意する（カード実体は **P8a 所管**）。
  - **feedback バナー / 請求先フォームは追加しない**（P9 所管）。
- 変更 `/workspace/app/Http/Controllers/Billing/BillingController.php::index()`: 4 props の array 直書き（:60-65）を `BillingDashboardDto` へ（禁止事項 #4 の遵守）。`billingState` は `BillingAccess::state($organization)`、`plan` は (a) と同じ解決規則、`balance` は `TicketLedgerService::balance()` の DTO。

**(e) Guest/Pricing の配置と三層構成（pp-8）**

- 移動 `/workspace/resources/js/pages/Pricing.svelte` → `/workspace/resources/js/pages/Guest/Pricing.svelte`（← `/tmp/aigenba/resources/js/pages/Guest/Pricing.svelte` の配置）。
- 変更 `/workspace/app/Http/Controllers/Marketing/PricingController.php:73`: `Inertia::render('Pricing', …)` → `Inertia::render('Guest/Pricing', …)`。**route path `/pricing`・route 名 `pricing`・SEO メタは不変**。
- 三層構成を移植（監査 pricing-plans-8 の前提「Personal 無料実体の存在」が **P1 で確定済み**のため、条件成就により機械移植する）:
  - `personalPlan` バナー（`data-testid="personal-banner"`。aigenba :144-175）— 「基本料金はかからず、トレーニングに使うチケット代だけでご利用いただけます。」等の文言は verbatim、席語彙のみ AI-CUE の `maxMembers` / `maxProjects` / `maxStorageGb` へ写す。CTA href（`/register?plan={code}`）は **P7 で導入済み**のためそのまま使う。
  - 法人グリッド（`data-testid="pricing-plan-grid"`。aigenba :177-195）= `personal` を除いた残り（starter / standard）。
  - enterprise 層は **AI-CUE 既存の大規模利用バナー**（`data-testid="pricing-enterprise-banner"`）を正とする（enterprise の Plan 行が無く `enterprisePlan` が常に null になるため。原則 4）。
- **D28 の文言反映**: `buildFeatures`（:28-38）の `月 {monthlyTicketGrant} 枚のチケット付与` バレットを**撤去**する（aigenba :57-59 のコメント「月次のチケット付与は廃止済 (常に 0 枚) のため表記しない。チケットは購入制で、料金はプラン表下のチケット料金表に集約する」を採る）。
- 併せて文言を aigenba verbatim へ寄せる: 見出し下の説明（:89-91）「無料で始めて…シンプルな 2 プランです。」→「個人から法人まで、規模や利用量に合わせて選べるプランをご用意しています。」（**プラン数を決め打ちしない** = aigenba :127 verbatim）。FAQ（:53-55）の「Free プランは基本料金なしで…」→ aigenba :91-93 の「パーソナルプランは基本料金無料でご利用いただけます。…」（`free` 行は P4 で撤去済み）。
- `aigenba` の `bg-primary/10` は AI-CUE の DS token `bg-primary-soft` へ写す（`ds-purity` 準拠。既存 `Pricing.svelte:165` の先例）。

#### 波及変更

**TypeScript 型定義**

- `/workspace/resources/js/types/billing.ts`:
  - 追加 `BillingStateValue = 'no_subscription'|'pending_checkout'|'expired_checkout'|'subscribed'|'active_free_plan'`（← `/tmp/aigenba/resources/js/types/Billing.ts:103-109` verbatim。分岐退行を型で検知する）。
  - 再利用 `TicketBalanceShape`（P5 が同ファイルへ追加済み。**`debt` フィールドは無い**）。
  - `PurchaseTicketsPageProps`: `balance: number` → `balance: TicketBalanceShape`、`attemptToken` → **`ticketAttemptToken`**、追加 `formState: 'normal'|'resume'|'completed'` / `boundCount: number | null` / `resumeUrl: string | null` / `newPurchaseUrl: string`。
  - 追加 `BillingPlansPageProps` / `BillingDashboardProps` / `QuotaLimitsShape`。
- `/workspace/resources/js/components/molecules/PricingPlanCard.svelte` の `Props` に `headerBadges?: Snippet`。
- `/workspace/resources/js/types/marketing.ts`: **変更なし**（`PricingPlanShape.monthlyTicketGrant` の削除は **D28 により P1 が実施済み**。P8b では触らない = 二重定義しない）

**DTO / JsonResource**

- 新規 `/workspace/app/DataTransferObjects/Billing/BillingPlansPageDto.php`（`@phpstan-type BillingPlansPageShape`。`PricingPlanShape` を `@phpstan-import-type`）。
- 新規 `/workspace/app/DataTransferObjects/Billing/BillingDashboardDto.php`（Index の props DTO 化 = 禁止事項 #4 の遵守）。
- 新規 `/workspace/app/DataTransferObjects/Billing/QuotaLimitsDto.php`（`maxProjects` / `maxMembers` / `maxStorageGb`。GiB 換算は `PricingService::storageGb` と同一規則 `intdiv(bytes, 1024 ** 3)`）。
- 変更 `/workspace/app/DataTransferObjects/Billing/PurchaseTicketsPageDto.php`（`balance: int` → `TicketBalanceDto`、`attemptToken` → `ticketAttemptToken`、`formState` / `boundCount` / `resumeUrl` / `newPurchaseUrl` 追加。PHP shape / TS shape / constructor / fixture を同時更新）。
- 新規 `/workspace/app/Enums/Billing/PurchaseFormState.php`。
- JsonResource: 追加なし（本フェーズは Inertia のみ）。

**Inertia props**

- `Billing/Index`: 4 props → `['page' => BillingDashboardDto::toArray()]` 1 本（PurchaseTickets / Pricing と同じ `page` 規約へ）。
- `Billing/Plans`: 新規 `['page' => BillingPlansPageDto::toArray()]`。
- `Billing/PurchaseTickets`: `page` の shape 拡張。
- `Pricing` → `Guest/Pricing`: component 名のみ変更（props 不変）。

**テストファイル**

- 更新: `/workspace/tests/Feature/Billing/BillingPageTest.php`（:17 のプラン一覧期待を `BillingPlansPageTest` へ移設、:25-34 の props 名追随、:106 portal 期待、:87 の 404 は維持）
- 更新: `/workspace/tests/Feature/Billing/PortalConfigurationTest.php`（事前ガード導入後の到達条件）
- 更新: `/workspace/tests/Feature/Billing/TicketCheckoutTest.php`（画面 render 由来の安定 token を使う経路の期待を追加。既存 replay / stale ケースは維持）
- 更新: `/workspace/tests/Feature/Marketing/PricingPageTest.php`（`->component('Pricing')` → `'Guest/Pricing'`。プラン集合の期待は P1 / P4 で更新済み）
- 更新: `/workspace/tests/js/pages/Pricing.test.ts` → `/workspace/tests/js/pages/Guest/Pricing.test.ts`（import path + :62 の `月 100 枚のチケット付与` 期待を **非表示** の期待へ。**削除しない**）
- 更新: `/workspace/tests/js/pages/PurchaseTickets.test.ts`（:57 残高 fixture を per-bucket へ / :102 の POST 契約は `attempt_token` のまま維持 / :86 の「disabled にしない」契約を維持 / `formState` ケース追加）
- 更新: `/workspace/tests/js/components/molecules/PricingPlanCard.test.ts`（headerBadges）
- 新規: `/workspace/tests/Feature/Billing/BillingPlansPageTest.php` / `BillingPortalGuardTest.php` / `TicketPurchaseResumeStateTest.php`
- 新規: `/workspace/tests/js/pages/Billing/Plans.test.ts` / `Billing/PlanCard.test.ts` / `Billing/ticketCount.test.ts` / `Billing/Index.test.ts`
- 影響（変更なしで pass すること）: `/workspace/tests/js/architecture/{page-shell-structure,ds-purity,atomic-import-graph,lucide-scoped-import}.test.ts`

#### 主要な契約

ルート（課金ゲート allowlist 内・**route parameter を持たない current-org スコープ**。current org 不在 / 非所属は認可より前に 404）

```
GET  /billing                   billing.index          BillingController@index
GET  /billing/plans             billing.plans          BillingController@plans     ← 新規
POST /billing/checkout          billing.checkout       … 既存 (body: {plan_code} のみ)
POST /billing/portal            billing.portal         … 既存 (事前ガード追加)
GET  /purchase-tickets?fresh=1  billing.tickets.show   … 既存 route に fresh query を追加解釈
GET  /pricing                   pricing                PricingController (component 名のみ Guest/Pricing へ)
```

Controller

```php
public function plans(Request $request, PricingService $pricing, BillingAccess $access): Response;                  // ['page' => BillingPlansPageDto]
public function index(Request $request, TicketLedgerService $tickets, BillingAccess $access, QuotaService $quota): Response; // ['page' => BillingDashboardDto]
public function portal(Request $request, SubscriptionCheckoutGateway $gateway, BillingAccess $access): SymfonyResponse|RedirectResponse; // 事前ガードの back() のため union
```

現在プランの表示解決（aigenba `BillingController::plans()` :421-423 / `index()` :105-107 verbatim。**gate 判定には使わない**）

```php
$state = $access->state($organization);                       // OnboardingBillingState
$currentPlanCode = $state === OnboardingBillingState::ActiveFreePlan
    ? $organization->free_plan_code
    : $organization->plan_code;
```

Service（4 分割境界を維持）

```php
// App\Services\Billing\TicketCheckoutService
public function resolveResumablePurchase(Organization $org, int $userId, int $windowMinutes): ?TicketCheckoutSession;
// 2 段取得 (aigenba TicketService.php:1393-1417 verbatim):
//  (1) live pending (status=Pending / expires_at > now / checkout_url <> '') を latest('id') → resume
//  (2) completed (completed_at > now - window) を latest('id') → completed / (3) null → normal
// いずれも organization_id + initiated_by_user_id スコープ (cross-user の resumeUrl 漏洩を構造的に封じる)
```

DTO 形状（要点）

```
BillingStateValue        = 'no_subscription'|'pending_checkout'|'expired_checkout'|'subscribed'|'active_free_plan'
TicketBalanceShape       = { monthlyRemaining: int, purchasedRemaining: int, totalAvailable: int,
                             activeReservations: int, nextExpireAt: string|null }        // P5 由来 (aigenba verbatim / debt なし)
PricingPlanShape         = { code, name, baseAmountJpy: int|null, 
                             maxProjects: int|null, maxMembers: int|null, maxStorageGb: int|null }  // 既存を Billing と共有
QuotaLimitsShape         = { maxProjects: int|null, maxMembers: int|null, maxStorageGb: int|null }
BillingPlansPageShape    = { plans: list<PricingPlanShape>, currentPlanCode: string|null,
                             billingState: BillingStateValue, canManage: bool }
BillingDashboardShape    = { plan: PricingPlanShape|null, billingState: BillingStateValue,
                             currentPeriodEnd: string|null, balance: TicketBalanceShape,
                             quotas: QuotaLimitsShape, canManageBilling: bool }
PurchaseTicketsPageShape = { tiers: list<PurchaseTierShape>, minCount: int, maxCount: int, defaultCount: int,
                             balance: TicketBalanceShape, canManage: bool, ticketAttemptToken: string,
                             purchased: bool, formState: 'normal'|'resume'|'completed',
                             boundCount: int|null, resumeUrl: string|null, newPurchaseUrl: string }
```

`totalAvailable` は `TicketBalanceDto::totalAvailable()`（`max($monthly + $purchased - $reservations, 0)`。aigenba verbatim）の値をそのまま描画する。UI は再計算・clamp しない。

DB 列 / index: **追加なし**。data migration も無い（`plans.is_active` は P1 で `true` seed 済み = 再公開フェーズが存在しない）。`ticket_checkout_sessions` の既存 `UNIQUE(organization_id, attempt_token)` / `initiated_by_user_id` / `expires_at` / `completed_at` をそのまま使う。

config: `billing.purchase_resume_window_minutes` = 30（aigenba 既定値）。`config/quota.php` は触らない（`personal` / `starter` の limits は P1、`fallback_plan` は P4 で確定済み）。

#### PHPStan 適合チェック

- `plans()` / `index()` の戻り値は `Inertia\Response`、`portal()` は `SymfonyResponse|RedirectResponse`（`back()` 分岐のため union を明示。既存 `checkout()` :72 と同型）。
- 全ページ props は `readonly` DTO の `toArray()` 経由（`response()->json()` 直書きなし）。各 DTO に `@phpstan-type …Shape` を付け `@phpstan-import-type` で合成する（既存 `PurchaseTicketsPageDto` / `PricingPageDto` と同じ規約）。`TicketBalanceShape` は P5 の DTO から import（再宣言しない）。`PricingPlanShape` は `App\DataTransferObjects\Marketing\PricingPlanDto` から import する。
- `OnboardingBillingState` は backed enum。props へは `->value` を渡し、TS 側 `BillingStateValue` union と exact 対にする。Controller の分岐は `===` の enum 比較のみ（string 比較を書かない）。
- `resolveResumablePurchase(): ?TicketCheckoutSession` の null は `match(true)` の `default => [PurchaseFormState::Normal, (string) Str::ulid(), null, null]` へ縮退。各腕は同じ arity・型順を返し list shape 推論を保つ（aigenba :484-502 と同形）。
- `config('billing.purchase_resume_window_minutes')` は `mixed` のため `config()->integer(...)` typed accessor で取得する（aigenba の `Assert::integer($windowMin, …)` と等価）。
- `$request->user()` は `Assert::isInstanceOf($user, User::class)`（既存踏襲）。`$request->boolean('fresh')` は `bool` 確定。
- `QuotaService::limits(): array<string, int>` から `QuotaLimitsDto` を作る際、無い key は `null`（無制限）へ。`intOrNull` / `storageGb` と同じ規則を DTO の static factory に置き、`mixed` を残さない。
- `$organization->subscription('default')` は `?Subscription`（Cashier）。portal ガードは `instanceof Subscription` で narrowing する（aigenba :987 と同形）。`current_period_end` は `Carbon|null` のため `?->toIso8601String()`。
- `PricingService::listPublicPlans(): list<PricingPlanDto>` をそのまま使う（`is_active` フィルタは P1 実装。P8b は変更しない）。
- **禁止**: `@phpstan-ignore` / baseline 追加 / 戻り値 widen。

#### テスト計画

**先に red を作る（新規）**

1. `tests/Feature/Billing/BillingPortalGuardTest.php`（bs-11）
   - `stripe_id` null・サブスク無しの org の owner が `POST /billing/portal` → **302 back + `error` flash**（Fake gateway 未呼び出し = Stripe に到達しない）。現行は Cashier 例外 = red。
   - `free_plan_code='personal'`（= `ActiveFreePlan`）で **canceled サブスク行が残る** org の owner → 同じく back + error（aigenba :982-988 の趣旨の回帰）。
   - 有償サブスク保持 org の owner → 既存どおり `Inertia::location` で Portal URL。
2. `tests/Feature/Billing/TicketPurchaseResumeStateTest.php`（tc-5）
   - live pending session を持つ owner が `GET /purchase-tickets` → `formState='resume'` / **`ticketAttemptToken` が既存 session の `attempt_token` と一致** / `boundCount` = `ticket_count` / `resumeUrl` = `checkout_url`。現行は毎 render fresh ULID = red。
   - `?fresh=1` → `formState='normal'` かつ token が別値。
   - 完了済 session（窓内）→ `formState='completed'` / `resumeUrl` は null。窓外 → `normal`。
   - **非管理者（member）** → live pending があっても `formState='normal'` / `resumeUrl` null。
   - **他 user の pending は resume しない**（`initiated_by_user_id` スコープ）。
   - 二重課金回帰: resume 表示 → 同 token で `POST /purchase-tickets/checkout` → 既存 replay で同一 checkout URL へ収束し Stripe session が増えない。
3. `tests/Feature/Billing/BillingPlansPageTest.php`（bs-6）
   - owner: `GET /billing/plans` 200 / `page.plans` に `is_active=true` の全プラン（personal / starter / standard）/ `page.currentPlanCode` / `page.billingState` / `canManage=true`。
   - `ActiveFreePlan` の org（canceled サブスク行あり）で `page.currentPlanCode === 'personal'`（`plan_code` に旧 paid が残っていても free 側が正）。
   - **POST body 契約**: `POST /billing/checkout` が `{plan_code}` のみで成立する（attempt token を要求しない）。
   - member: 200 だが `canManage=false`。
   - current org 無しユーザー: 404 / 非所属: 404（既存 `BillingPageTest:87` と同型）。
4. `tests/js/pages/Billing/PlanCard.test.ts`
   - `isCurrent` で「現在のプラン」バッジ（headerBadges）が出る。
   - `canSwitch=false` で **`switchBlockedReason` が可視テキストとして描画**され、かつ **`disabled` 属性の button が存在しない**。CTA 押下で理由 Alert（`plan-switch-blocked`）が出る（禁止事項 #8 / DESIGN.md の機械保証）。
   - features に **「月 N 枚」表記が含まれない**（D28 の機械保証）。
5. `tests/js/pages/Billing/ticketCount.test.ts`
   - `parseTicketCount`: `'10'→10` / `'-5'→-5` / `'1e3'|'0x10'|'1.5'|'Infinity'|'-'|'1.'|''→null` / `10(number)→10`（防御的 `String(raw)`）。
6. `tests/js/pages/Billing/Plans.test.ts`
   - 「このプランへ変更」→ `ConfirmDialog` 表示 → 確認で `POST /billing/checkout` に **`{plan_code}` のみ**を送る。
   - `errors.plan_code` があるとき dialog は開いたままサーバ文言を描画する。
7. `tests/js/pages/Billing/Index.test.ts`
   - `billingState='active_free_plan'` で portal ボタンを描画せず「月額 無料（チケット代のみ）」を出す。`billingState='subscribed'` で portal ボタンと次回請求日を出す。
   - `plan-list` を持たず「プラン比較」リンク（`/billing/plans`）を出す。
   - per-bucket 残高（totalAvailable / monthlyRemaining / purchasedRemaining / `balance-next-expire`）を描画し、**残高由来の債務行が存在しない**。
   - `plan=null` で「まだプランに契約していません」を出す。

**既存テストの更新（削除しない）**

- `tests/Feature/Billing/BillingPageTest.php:17`「owner は /billing でプラン一覧・残高・管理フラグを見られる」→ プラン一覧の期待を `BillingPlansPageTest` へ**移設**し、本 test は `page.plan` / `page.billingState` / `page.balance`(per-bucket) / `page.quotas` / `canManageBilling` の期待へ更新。:38 / :50 / :60 / :93 / :106 は props 名・portal 到達条件の追随。
- `tests/Feature/Billing/PortalConfigurationTest.php`: 事前ガード導入後も Portal configuration の spec が変わらないこと（ガードは spec でなく到達条件の変更）。
- `tests/Feature/Billing/TicketCheckoutTest.php`: 画面 render 由来の安定 token を使う経路の期待を追加（既存 replay / stale ケースは維持）。
- `tests/Feature/Marketing/PricingPageTest.php`: `->component('Pricing')` → `->component('Guest/Pricing')`（配置移動が props・SEO を変えないことの回帰）。
- `tests/js/pages/Pricing.test.ts` → `tests/js/pages/Guest/Pricing.test.ts`: import path を移設し、`:62` の「月 100 枚のチケット付与」期待を **「月 N 枚」表記が描画されない**（D28）へ更新。三層構成（`personal-banner` / `pricing-plan-grid` / `pricing-enterprise-banner`）の描画ケースを追加。
- `tests/js/pages/PurchaseTickets.test.ts`: `:57` の `balance` fixture を `TicketBalanceShape` へ、per-bucket 3 値 + `balance-next-expire` の描画期待を追加。`:86`（範囲外でも disabled にしない）・`:102`（`count` + `attempt_token` を POST）は**契約として維持**。`formState='resume'|'completed'` の描画ケース（フォーム非描画・`boundCount` 表示・CTA 2 種）を追加。「購入したチケットに有効期限はありません」が purchased バケツの caption 位置に出ることを固定。
- `tests/js/components/molecules/PricingPlanCard.test.ts`: `headerBadges` を渡すと header 右へ描画され、渡さない場合は既存出力が不変（回帰）。

**arch テスト（変更せず pass）**: `page-shell-structure`（新設 `Billing/Plans.svelte` が PageContainer / PageHeader / PageContent を使う。`_helpers/PlanCard.svelte` は AppLayout を import しないため対象外）/ `ds-purity`（hex 直書き禁止。aigenba の `bg-primary/10` は `bg-primary-soft` へ写す）/ `atomic-import-graph`（`_helpers` は pages 層。逆参照なし）/ `lucide-scoped-import`（アイコンは `@lucide/svelte` のみ）。

#### リスク

| リスク | 緩和 |
|---|---|
| **Index からプラン一覧（`plan-list` / `checkout-{code}`）を撤去**すると既存 Feature / bug-hunt シナリオが参照喪失する | 撤去前に `grep -rn 'plan-list\|checkout-' tests/ devnotes/` で参照を洗い出し、期待を `BillingPlansPageTest` / `Billing/Plans.test.ts` へ**移設**（削除しない）。Index には `billing-plans-link` を残し導線を切らない。 |
| **`ticketAttemptToken` 安定化で正当な追加購入を握り潰す**（completed 直後に別枚数で買えない） | `?fresh=1`（`newPurchaseUrl`）を `resume` / `completed` の両状態から必ず露出する（aigenba verbatim）。窓は `purchase_resume_window_minutes`(30) で有限化。 |
| **resume の Stripe 直リンクが purchase gate を迂回** | `canManage=false` では常に `normal` + fresh token へ縮退し `resumeUrl` を props に載せない（aigenba :476-481 のコメントごと移植）。`initiated_by_user_id` スコープで cross-user 漏洩も封じる。Feature テストで固定。 |
| **per-bucket 残高が P5 未マージだと成立しない** | P8b は P5 の後段。`TicketBalanceDto` を DTO 境界でのみ参照し `TicketLedgerService` の計算には触れない（残高数式は P5 の単一 snapshot が唯一の出典）。 |
| **D28 の文言撤去で「チケットが減った」と誤解される** | 「月 N 枚」は D28 で**実態が 0 になる**ため、表記を残す方が誤情報になる。aigenba と同じく料金の説明をチケット料金表（購入制 + signup grant {N} 枚）へ集約し、`signup-grant-note` を残して初回無料枠の可視性を維持する。 |
| **`active_free_plan` 以外の未契約（`no_subscription` / `expired_checkout`）でも portal ボタンが出る**（aigenba の UI 条件が `!isFreePlan` のみ） | **aigenba の挙動をそのまま移植する**（原則 5: aigenba にある問題を AI-CUE 側で先回り修正しない）。サーバ側事前ガードが back + error flash で fail-closed に受け止め、500 には到達しない。`BillingPortalGuardTest` がこの安全網を固定する。aigenba 側で UI 条件が直れば取り込む。 |
| **Guest/Pricing 移動で SSR / e2e の component 名参照が壊れる** | route path・route 名・SEO メタは不変。`grep -rn "'Pricing'\|\"Pricing\"" app/ tests/ resources/` で参照を全置換し、既存 `Pricing.test.ts` / `PricingPageTest` を移設して回帰にする。 |
| **Index の props 一括変更（4 props → `page`）の破壊範囲** | 同一 PR で Feature / JS 両テストを更新する。DTO 化は禁止事項 #4 の遵守でもあり後戻りしない。 |
| **P8a（auto-recharge カード）と Index を同時に触る競合** | P8b は `auto-recharge-card` の差し込み位置と `?highlight=auto-recharge` anchor のみ用意し、カード実体は P8a 所管（マージ順は P8a → P8b）。 |
| **P9（feedback / 請求先）が Index を再度触る** | P8b は Index を `BillingDashboardDto` 1 props に整えるところで止め、P9 は同 DTO への additive な追加（`feedback` / `billingContact`）で完結させる。placeholder props を先置きしない。 |

---


