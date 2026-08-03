# 実装レビュー依頼: T081 (決済 parity P9 — checkout 冪等 + 着地 feedback + 請求先情報 + PM 流用/T1004)


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
以下の **差分 (git diff main...HEAD)** を、**設計書の P9 節**（Codex 合議 16 ラウンドで APPROVED 済み・逸脱不可）に照らしてレビューせよ。

P9 は **決済 parity の最終フェーズ**であり、扱うのは「サブスク checkout の冪等状態機械」「webhook 着地の状態遷移」
「着地 feedback」「請求先 PII (CipherSweet)」「T1004 = サブスク決済カードのオートリチャージ流用」。
**金銭が動く経路**であるため、二重課金・同意なし課金・PII 平文保存の 3 つは実害が直接的である。

### 出力形式

指摘は重大度別に分類し、各指摘に「根拠 (どのファイル・どの行・設計書のどの記述)」「具体的な失敗シナリオ」「修正案」を必ず添えること。

- `[Critical]` — 設計違反 / 禁止事項・セキュリティ不変条件抵触 / 実害のあるバグ / 後退 (regression) / テストが不変条件を固定できていない (空振り)
- `[Warning]` — 実害はまだ無いが将来事故る設計上の弱点・保守性・カバレッジ欠落
- `[Suggestion]` — 好み・軽微な改善

**推測で Critical を積まないこと**。差分に無い既存コードの挙動が根拠として必要な場合は、リポジトリ内のファイルを読んで確認してから断定せよ（読み込みは許可されている）。確認できない場合は「未確認の仮説」と明示せよ。
移植元 aigenba は `/tmp/aigenba` に読み取り専用で存在する（verbatim 移植の照合に使ってよい）。

### レビュー観点 (優先順)

1. **課金の冪等性 (P9 の中核。セキュリティ不変条件 #7)**:
   - `SubscriptionService::startCheckout()` の段 0〜6 が、**リトライ・並行・順序逆転**のいずれでも二重 subscription を作らないか。
     `Cache::lock` + `UNIQUE(organization_id, intent, attempt_token)` + Stripe idempotency key + INSERT race の re-read 収束で穴が塞がっているか。
   - lock の外で行われている判定・Stripe 呼び出しが、lock 内の前提を壊さないか (段 5 の Stripe 作成は lock 内か、失敗時に行だけ / Stripe session だけが残る非対称は許容範囲か)。
   - `isUniqueViolation()` の判定 (SQLSTATE + index 名 / 列名) が **実際に使う driver (pgsql / sqlite)** で成立するか。
     成立しないと race が 500 に落ちる。テストがその成立を実 driver で確かめているか、それとも文字列を自分で作った例外で
     模擬しているだけ (= 空振り) か。
   - webhook `settleSubscriptionCheckout()` の遷移が C-2 の 1 定義に一致し、`Completed` 終局・再送 no-op になっているか。
2. **冪等クエリの intent スコープ (P8a の行との混線)**: 段 2/3/4・`isAttemptCompleted`・`attemptTokenIsForeign`・
   feedback・着地 flash・`hasRecentAutoRechargeFundedSignup` のすべてが `intent=subscription_start` でスコープされているか。
   逆に日次 sweeper だけは **intent 非スコープ**（verbatim）か。P8a の `setup_payment_method` 行を壊していないか。
3. **請求先 PII (不変条件 #6)**: `billing_contact_email` / `billing_contact_name` が **両方とも** CipherSweet 暗号化され、
   平文カラム・平文 where 経路・平文ログが生まれていないか。blind index の契約 (email のみ) が守られているか。
   `Organization` への CipherSweet 導入が既存の org 読み書き（Filament / seeder / factory / 通知宛先）を壊していないか。
   Stripe へ送る内容の境界が広がっていないか (`stripeEmail()` の追加は設計に無い — 妥当か)。
4. **T1004 の fail-closed**: `consent_version='v2'` の検証が **checkout 開始前** (Request 層) にあり、
   `applyReusedPaymentMethod` が適格性先行で不適格なら Stripe にも DB にも触らない完全 no-op か。
   dispatch 条件が `payment_status ∈ {paid, no_payment_required}` の allowlist か。
   **v2 に上げた「開示」が UI の同意文言に実在するか**（版だけ上げて文言が v1 のままなら開示は成立していない）。
5. **feedback の one-shot 性と fail-closed**: `?session_id` が **org スコープ relation** 経由でのみ引かれ、
   `intent !== subscription_start` は null になるか。`?portal` + `session('error')` の抑止が効くか。
   UI が raw query を見ていないか。リロードで feedback が残り続けないか。
6. **禁止事項**: #8 (必須条件未充足を理由に button を disabled にしない)、#4 (`response()->json()` 直書き)、#7 (`redirect()->intended()`)、#2 (PHPStan の widen / baseline / `@phpstan-ignore`)。
7. **セキュリティ不変条件**: #1 tenant キー不信 (payload の `customer` / `metadata.org_ref` は照合のみ)、
   #2 不整合は認可より前に 404 (`attemptTokenIsForeign` → `Gate::authorize` の順)、#3 cross-org read/write の不在。
8. **PHPStan level 10 適合**: `@phpstan-type` / `@phpstan-import-type` の整合、mixed 残り、`/** @var */` による再表明が
   型の widen になっていないか。DTO のデフォルト引数追加が「必須 prop」の契約を壊していないか。
9. **テストが不変条件を実際に固定しているか (空振りしていないか)**: 設計「テスト計画」の 1〜63 のうち **実装されていない項目**を挙げよ。
   assertion が緩すぎないか、fixture が意図した状態を作れているか、既存 assertion の削除・弱体化が無いか。
   特に **並行 race (テスト 9)・境界時刻 (テスト 13/14)・PII 平文非保存 (テスト 30-33)・同意失効 (テスト 52/61)** が
   本当に検証になっているかを厳しく見よ。
10. **副作用・後退リスク**: `CashierStripeGateway` が `newSubscription()->checkout()` を捨てたことで
   Cashier の webhook が `subscriptions` 行を作れなくなる死角 (設計のリスク表の筆頭)、
   `BillingAccess::staleThresholdAt()` の撤去による既存呼び出し元の喪失、`config` の `consent_version` 改定で
   稼働中設定が停止する影響、`Organization` の `$casts` / `SoftDeletes` と CipherSweet の相互作用。
11. **フロント規約**: DS token のみ (hex 直書き禁止)、component 階層の単方向 import
   (`atoms → molecules → organisms → features/{domain} → templates → pages`)、アイコンは `@lucide/svelte` のみ。

### 実装者が自認している論点 (賛否と見落としを述べよ)

以下は実装者 (Claude) が差分作成後に自分で見つけた懸念である。**各々について「Critical/Warning/Suggestion/問題なし」の判定と根拠**を述べ、さらに**見落としている論点**を挙げよ。

1. **`consent_version` を v2 に上げたのに、UI の同意文言が P8a (v1) のまま**である。
   `resources/js/pages/Onboarding/Checkout.svelte` の `funding-consent-terms` ブロックは
   「次の画面でカードを登録します。登録しただけでは課金されません。」という **カード登録経路 (v1) の文言**のままで、
   **「ご契約のお支払いカードをオートリチャージにも使う」という v2 の開示が文面に存在しない**。
   さらにこの文言は今回 snippet 化して **有償プラン枝でも描画**されるようになったため、
   有償契約 (= 次の画面は契約決済であってカード登録ではない) で事実と異なる説明になる。
   設計 (C-3 リスク表)「**v2 同意文言 (契約のお支払いカードをオートリチャージにも使う) で開示済み**であり、
   開示の版管理が `consent_version` = aigenba の消費者保護契約そのもの」に照らして、これは Critical か。
2. **`resolveAutoRechargeLanding()` に `$request->session()->reflash()` を足した** (設計に無い 1 行)。
   303 の 1 hop を跨いで前段 flash を生存させる意図だが、`error` flash も一緒に延命するため
   「成功着地で直前の error が再表示される」経路を作らないか。
3. **DTO のデフォルト引数**: `BillingDashboardDto::$feedback = null` / `$billingContact = null`、
   `BillingPlansPageDto::$subscriptionAttemptToken = ''`、`OnboardingCheckoutDto::$subscriptionAttemptToken = ''`。
   設計は `billingContact: BillingContactShape` (非 null) / `subscriptionAttemptToken: string` を **必須**と書いている。
   デフォルト値により「渡し忘れると空 token が front へ出て POST が 422 になる」silent failure を作らないか。
4. **`Organization::stripeEmail()` の追加は設計に無い**。請求先メールを Stripe customer へ同期する意図だが、
   設計の非スコープ / 波及変更に列挙されていない。PII の外部送出境界を広げていないか
   (設計リスク表は「現行 `syncStripeCustomerDetails()` が既に owner email を送っており送信先・内容は不変」と述べている)。
5. **`SubscriptionService::assertCheckoutReady()` が請求先メール必須の guard になっている**。
   `billing_contact_email` 未設定 org は owner email へ fallback するため実害は無いはずだが、
   owner を解決できない org (owner 退会等) で **契約 checkout が InvalidArgumentException → `back()->with('error')` で詰む**。
   出口 (請求先情報フォーム) は同じ `/billing` にあるが、未契約 org は `/billing` に到達できるか。
6. **段 4 の `expireCheckoutSession` を lock 内で複数行に対して逐次実行**している。1 行目で `'complete'` を検出して
   例外を投げると、それ以前に expire 済みの行は `Expired` に落ちた状態で残る (部分適用)。設計は「それ以外 → 行を Expired にして続行」
   としか書いていない。この部分適用は許容か。
7. **`FakeStripeGateway` が `tests/Support` と `app/Services/Billing/Fakes` の 2 本立て**になった。
   runtime fake (bughunt) 側は `expireCheckoutSession` が常に `'expired'` を返すため、
   bughunt 環境で「別 plan の live pending がある状態」の分岐が実質再現できない。テスト観点の欠落か。
8. **`SubscriptionCheckoutIdempotencyTest` の並行 race テスト (設計テスト 9)** が
   `UniqueConstraintViolationException` を**自作して注入**しているなら、`isUniqueViolation()` の
   実 driver 文字列 (pgsql の制約名 / sqlite の列名) と乖離していても green になる。
   実際に 2 回 INSERT して制約に当てる形になっているか確認してほしい。
9. **`BillingAccess::state()` の pending 行取得が `->get(['id','status','created_at'])`** になった
   (`status` を select に追加)。select 列の取りこぼしがあると `isLivePending()` が
   常に false になり `state()` が壊れる。この結合を固定するテストがあるか。
10. **`ReconcileSubscriptionSchedules::expireStaleCheckouts()` が intent 非スコープの一括 UPDATE** である。
    P8a の setup 行も `Expired` になるが、P8a 側の `hasRecentCompletedSetup` / `isAutoEnablePending` の
    判定に副作用が及ばないか (setup 行の `Expired` 化が「処理中」窓や再同意導線を壊さないか)。

### 検証コマンドの実行結果 (実装者申告)

```
composer test         : pass (2513 tests / 2511 passed / 2 skipped / 10120 assertions / 469.5s)
composer phpstan      : pass (741/741 files, [OK] No errors, baseline 追加なし)
vendor/bin/pint --test: pass
pnpm lint             : pass
pnpm typecheck        : pass (tsc --noEmit)
pnpm test             : pass (98 files / 892 tests)
pnpm build            : pass
composer test:browser : **未実行** (Chromium/WebKit レーンは未検証)
```

### 前提 (P1〜P8b + T088 はマージ済み)

- `BillingAccess::state()` が 5 状態 (`Subscribed`/`ActiveFreePlan`/`PendingCheckout`/`ExpiredCheckout`/`NoSubscription`) を返し、`hasActiveAccess()` は `state()->grantsAccess()` 一本。
- 無料枠は `organizations.free_plan_code='personal'` の明示申告。`plans` から `free` 行は撤去済み。D28 により全 tier `monthly_ticket_grant = 0`。
- `billing_checkout_sessions` は P2 で作成済み。**最初の writer は P8a** (`intent=setup_payment_method`)。P9 が `intent=subscription_start` の writer を足す。
- `TicketLedgerService` は per-source clamp / 消費優先 / commit-wins 済み。`availableTrueBalance()` / `balance(): TicketBalanceDto` が使える。
- P8a の auto-recharge 一式 (`AutoRechargeService` / `AutoRechargeSettingsDto` / `Contracts\AutoRechargeGatewayInterface` (8 メソッド) / `app/Jobs/Billing/*` / `config/billing.php` の `auto_recharge`) は実装済み。
- テスト helper `createOrganizationWithOwner(name, grandfatherFreePlan: true)` の既定は「無料枠付与済み (= ActiveFreePlan)」。未契約を検証したいテストは `grandfatherFreePlan: false` を明示する。
- テストは `tests/Pest.php` のグローバル `RefreshDatabase` + `--parallel` (個別 `DatabaseTransactions` 禁止)。テストデータは Factory のみ。

---

## 差分 (git diff main...HEAD)

```diff
diff --git a/app/Actions/Billing/UpdateBillingContactAction.php b/app/Actions/Billing/UpdateBillingContactAction.php
new file mode 100644
index 0000000..bc7ef01
--- /dev/null
+++ b/app/Actions/Billing/UpdateBillingContactAction.php
@@ -0,0 +1,43 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Actions\Billing;
+
+use App\DataTransferObjects\Billing\UpdateBillingContactData;
+use App\Models\Organization;
+use App\Services\Billing\BillingCustomerSynchronizer;
+use Illuminate\Support\Facades\DB;
+
+/**
+ * P9: 請求先メール / 宛名を更新し、**email 変更時のみ** Stripe 同期を発火する Action。
+ *
+ * 不変条件: 同期は BillingCustomerSynchronizer 経由 (BillingSyncDispatchInvariantTest) /
+ * transaction 内 afterCommit / email dirty 時のみ同期 /
+ * billing_contact_name は DB 保存のみで Stripe へは送らない。
+ */
+final class UpdateBillingContactAction
+{
+    public function __construct(
+        private readonly BillingCustomerSynchronizer $synchronizer,
+    ) {}
+
+    public function execute(Organization $organization, UpdateBillingContactData $data): void
+    {
+        DB::transaction(function () use ($organization, $data): void {
+            // 両列とも $fillable 外 (PII / 状態キー) のため明示代入する。
+            $organization->billing_contact_email = $data->email;
+            $organization->billing_contact_name = $data->name;
+
+            // dirty 判定は save() 前に評価する (save 後は false になる)。
+            // billing_contact_name は Stripe へ送らないため同期トリガに含めない。
+            $emailChanged = $organization->isDirty('billing_contact_email');
+
+            $organization->save();
+
+            if ($emailChanged) {
+                $this->synchronizer->dispatchFor($organization);
+            }
+        });
+    }
+}
diff --git a/app/Console/Commands/Billing/ReconcileSubscriptionSchedules.php b/app/Console/Commands/Billing/ReconcileSubscriptionSchedules.php
index c9479f0..7c89a03 100644
--- a/app/Console/Commands/Billing/ReconcileSubscriptionSchedules.php
+++ b/app/Console/Commands/Billing/ReconcileSubscriptionSchedules.php
@@ -5,6 +5,8 @@
 namespace App\Console\Commands\Billing;
 
 use App\Enums\Billing\ScheduleSetupStatus;
+use App\Enums\CheckoutSessionStatus;
+use App\Models\Billing\BillingCheckoutSession;
 use App\Models\Billing\Subscription;
 use App\Services\Billing\StripeScheduleGateway;
 use Carbon\CarbonImmutable;
@@ -43,22 +45,46 @@ class ReconcileSubscriptionSchedules extends Command
     /** remote 消失で None へ reset した件数。 */
     private int $reset = 0;
 
+    /** stale pending から Expired へ収束させた checkout session の件数 (P9)。 */
+    private int $expired = 0;
+
     public function handle(StripeScheduleGateway $gateway): int
     {
         // 同一プロセス内での再呼び出し (scheduler / テスト) で前回値が累積しないようリセットする。
         $this->restored = 0;
         $this->promoted = 0;
         $this->reset = 0;
+        $this->expired = 0;
 
         $this->retryMissing($gateway);
         $this->retryPartial($gateway);
+        $this->expireStaleCheckouts();
 
         // 無言成功ではなく処理件数を出力する (scheduler ログでの観測用)。
-        $this->info("reconcile-schedules: restored={$this->restored} promoted={$this->promoted} reset={$this->reset}");
+        $this->info("reconcile-schedules: restored={$this->restored} promoted={$this->promoted} reset={$this->reset} expired={$this->expired}");
 
         return self::SUCCESS;
     }
 
+    /**
+     * 工程 3 (P9): stale な pending checkout session を実 DB でも Expired へ収束させる。
+     *
+     * 境界は live 判定 (`created_at >= staleThresholdAt`) の **補集合** (`<`) であり、
+     * 閾値は BillingCheckoutSession::staleThresholdAt() の単一出典を読む (C-1)。
+     *
+     * **intent で絞らない** — P8a の `setup_payment_method` 行も対象にする
+     * (1 日以上前の pending は Stripe 側で既に expire 済みであり Expired 化は事実の追認。
+     * 遅延成功は webhook の C-2 遷移で Expired からでも Completed へ受理される)。
+     * Stripe 照会は行わない (created_at 閾値で決定的に扱える)。
+     */
+    private function expireStaleCheckouts(): void
+    {
+        $this->expired = BillingCheckoutSession::query()
+            ->where('status', CheckoutSessionStatus::Pending->value)
+            ->where('created_at', '<', BillingCheckoutSession::staleThresholdAt(CarbonImmutable::now()))
+            ->update(['status' => CheckoutSessionStatus::Expired->value]);
+    }
+
     /**
      * 工程 1: local に schedule_id が無い契約の remote 復元。
      *
diff --git a/app/DataTransferObjects/Billing/BillingContactDto.php b/app/DataTransferObjects/Billing/BillingContactDto.php
new file mode 100644
index 0000000..3196545
--- /dev/null
+++ b/app/DataTransferObjects/Billing/BillingContactDto.php
@@ -0,0 +1,49 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\DataTransferObjects\Billing;
+
+use App\Models\Organization;
+
+/**
+ * P9: 請求先連絡先の表示値。
+ *
+ * `email` / `name` は組織に保存された正本 (CipherSweet 復号済み)。未設定の間は
+ * `fallbackEmail` (owner email) が実際の通知宛先になることを UI が示せるようにする。
+ *
+ * @phpstan-type BillingContactShape array{email: string|null, name: string|null, fallbackEmail: string|null}
+ */
+final readonly class BillingContactDto
+{
+    public function __construct(
+        public ?string $email,
+        public ?string $name,
+        /** 未設定時に実際の宛先となる owner email (表示は「未設定時の送信先」用途のみ) */
+        public ?string $fallbackEmail,
+    ) {}
+
+    public static function fromOrganization(Organization $organization): self
+    {
+        $email = $organization->billing_contact_email;
+        $name = $organization->billing_contact_name;
+
+        return new self(
+            email: is_string($email) && $email !== '' ? $email : null,
+            name: is_string($name) && $name !== '' ? $name : null,
+            fallbackEmail: $organization->billingContactEmail(),
+        );
+    }
+
+    /**
+     * @return BillingContactShape
+     */
+    public function toArray(): array
+    {
+        return [
+            'email' => $this->email,
+            'name' => $this->name,
+            'fallbackEmail' => $this->fallbackEmail,
+        ];
+    }
+}
diff --git a/app/DataTransferObjects/Billing/BillingDashboardDto.php b/app/DataTransferObjects/Billing/BillingDashboardDto.php
index fac4c9f..9deae60 100644
--- a/app/DataTransferObjects/Billing/BillingDashboardDto.php
+++ b/app/DataTransferObjects/Billing/BillingDashboardDto.php
@@ -14,7 +14,7 @@
  * 現行 quota 上限 / 導線」に絞る。plan は表示用の解決結果 (ActiveFreePlan なら
  * free_plan_code、それ以外は plan_code。gate 判定には使わない)。
  *
- * P9 は本 DTO へ additive に feedback / billingContact を足す (placeholder は先置きしない)。
+ * P9: 着地 feedback (one-shot) と請求先連絡先を additive に足した。
  *
  * TS 側は resources/js/types/billing.ts の BillingDashboardProps と exact 対で保守する。
  *
@@ -22,6 +22,8 @@
  * @phpstan-import-type TicketBalanceShape from TicketBalanceDto
  * @phpstan-import-type QuotaLimitsShape from QuotaLimitsDto
  * @phpstan-import-type AutoRechargeShape from AutoRechargeSettingsDto
+ * @phpstan-import-type BillingFeedbackShape from BillingFeedbackDto
+ * @phpstan-import-type BillingContactShape from BillingContactDto
  *
  * @phpstan-type BillingDashboardShape array{
  *   plan: PricingPlanShape|null,
@@ -32,7 +34,9 @@
  *   canManageBilling: bool,
  *   continueUrl: string|null,
  *   autoRecharge: AutoRechargeShape,
- *   autoRechargeSetupToken: string
+ *   autoRechargeSetupToken: string,
+ *   feedback: BillingFeedbackShape|null,
+ *   billingContact: BillingContactShape
  * }
  */
 final readonly class BillingDashboardDto
@@ -53,6 +57,14 @@ public function __construct(
         public AutoRechargeSettingsDto $autoRecharge,
         /** P8a: カード登録 (mode=setup) 開始 POST の attempt_token (render 単位) */
         public string $autoRechargeSetupToken,
+        /**
+         * P9: 決済戻り着地の one-shot フィードバック (query を解釈済み。UI は raw query を見ない)。
+         * T088 で PurchaseFormState::Completed を撤去したため、**購入完了をユーザーに知らせる
+         * 唯一の経路**がこれ。該当しない着地では null。
+         */
+        public ?BillingFeedbackDto $feedback = null,
+        /** P9: 請求先連絡先 (未設定時は fallbackEmail = owner email が実際の宛先) */
+        public ?BillingContactDto $billingContact = null,
     ) {}
 
     /**
@@ -70,6 +82,8 @@ public function toArray(): array
             'continueUrl' => $this->continueUrl,
             'autoRecharge' => $this->autoRecharge->toArray(),
             'autoRechargeSetupToken' => $this->autoRechargeSetupToken,
+            'feedback' => $this->feedback?->toArray(),
+            'billingContact' => ($this->billingContact ?? new BillingContactDto(null, null, null))->toArray(),
         ];
     }
 }
diff --git a/app/DataTransferObjects/Billing/BillingFeedbackDto.php b/app/DataTransferObjects/Billing/BillingFeedbackDto.php
new file mode 100644
index 0000000..2ea901e
--- /dev/null
+++ b/app/DataTransferObjects/Billing/BillingFeedbackDto.php
@@ -0,0 +1,45 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\DataTransferObjects\Billing;
+
+use App\Enums\Billing\BillingFeedbackKind;
+
+/**
+ * P9: /billing 着地時のフィードバック。
+ * Controller が query (session_id / portal / replayed / retry) を解釈して構築し、
+ * UI は raw query を見ずにこの DTO のみを描画する。
+ *
+ * @phpstan-type SimpleBillingFeedbackKind 'purchase_received'|'purchase_processing'|'purchase_already_received'|'checkout_retry_required'|'portal_returned'
+ * @phpstan-type BillingFeedbackShape array{kind: SimpleBillingFeedbackKind, message: string}
+ */
+final readonly class BillingFeedbackDto
+{
+    private function __construct(
+        public BillingFeedbackKind $kind,
+        public string $message,
+    ) {}
+
+    /**
+     * CTA を持たない通常フィードバック (purchase_received / processing / already / retry / portal)。
+     */
+    public static function simple(BillingFeedbackKind $kind, string $message): self
+    {
+        return new self($kind, $message);
+    }
+
+    /**
+     * @return BillingFeedbackShape
+     */
+    public function toArray(): array
+    {
+        /** @var SimpleBillingFeedbackKind $kindValue */
+        $kindValue = $this->kind->value;
+
+        return [
+            'kind' => $kindValue,
+            'message' => $this->message,
+        ];
+    }
+}
diff --git a/app/DataTransferObjects/Billing/BillingPlansPageDto.php b/app/DataTransferObjects/Billing/BillingPlansPageDto.php
index c1d9754..f0333ba 100644
--- a/app/DataTransferObjects/Billing/BillingPlansPageDto.php
+++ b/app/DataTransferObjects/Billing/BillingPlansPageDto.php
@@ -22,7 +22,8 @@
  *   plans: list<PricingPlanShape>,
  *   currentPlanCode: string|null,
  *   billingState: string,
- *   canManage: bool
+ *   canManage: bool,
+ *   subscriptionAttemptToken: string
  * }
  */
 final readonly class BillingPlansPageDto
@@ -35,6 +36,12 @@ public function __construct(
         public ?string $currentPlanCode,
         public OnboardingBillingState $billingState,
         public bool $canManage,
+        /**
+         * P9: 契約 checkout 開始 POST の冪等 token (画面 render ごとに固定される ULID)。
+         * チケット購入の `ticketAttemptToken` / カード登録の `autoRechargeSetupToken` とは
+         * **別 key 空間** (混ぜない)。
+         */
+        public string $subscriptionAttemptToken = '',
     ) {}
 
     /**
@@ -50,6 +57,7 @@ public function toArray(): array
             'currentPlanCode' => $this->currentPlanCode,
             'billingState' => $this->billingState->value,
             'canManage' => $this->canManage,
+            'subscriptionAttemptToken' => $this->subscriptionAttemptToken,
         ];
     }
 }
diff --git a/app/DataTransferObjects/Billing/CheckoutSessionDto.php b/app/DataTransferObjects/Billing/CheckoutSessionDto.php
new file mode 100644
index 0000000..c8074de
--- /dev/null
+++ b/app/DataTransferObjects/Billing/CheckoutSessionDto.php
@@ -0,0 +1,43 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\DataTransferObjects\Billing;
+
+/**
+ * P9: 冪等 checkout マシン (`SubscriptionService::startCheckout`) の戻り値。
+ *
+ * `url === null` は「新規 Checkout を作らなかった」を意味する:
+ *  - Completed 行の replay (= 既に受付済み)
+ *  - 同 plan の live pending dedup (= 進行中の Checkout がある)
+ * どちらかは Controller が `stripe_session_id` の行 status で判別する。
+ *
+ * @phpstan-type CheckoutSessionShape array{
+ *   stripeSessionId: string,
+ *   url: string|null,
+ *   intent: string,
+ *   planCode: string|null
+ * }
+ */
+final readonly class CheckoutSessionDto
+{
+    public function __construct(
+        public string $stripeSessionId,
+        public ?string $url,
+        public string $intent,
+        public ?string $planCode,
+    ) {}
+
+    /**
+     * @return CheckoutSessionShape
+     */
+    public function toArray(): array
+    {
+        return [
+            'stripeSessionId' => $this->stripeSessionId,
+            'url' => $this->url,
+            'intent' => $this->intent,
+            'planCode' => $this->planCode,
+        ];
+    }
+}
diff --git a/app/DataTransferObjects/Billing/UpdateBillingContactData.php b/app/DataTransferObjects/Billing/UpdateBillingContactData.php
new file mode 100644
index 0000000..194481c
--- /dev/null
+++ b/app/DataTransferObjects/Billing/UpdateBillingContactData.php
@@ -0,0 +1,34 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\DataTransferObjects\Billing;
+
+use App\Http\Requests\Billing\UpdateBillingContactRequest;
+use App\Support\EmailNormalizer;
+use Webmozart\Assert\Assert;
+
+/**
+ * P9: 請求先更新の入力 DTO。
+ *
+ * email は正規化済みの非空文字 (blind index の検索入力と同一正規化)、
+ * name は空文字を null に畳んだ任意宛名。
+ */
+final class UpdateBillingContactData
+{
+    public function __construct(
+        public readonly string $email,
+        public readonly ?string $name,
+    ) {}
+
+    public static function fromRequest(UpdateBillingContactRequest $request): self
+    {
+        $email = EmailNormalizer::normalize($request->string('billing_contact_email')->toString());
+        Assert::stringNotEmpty($email);
+
+        $rawName = $request->input('billing_contact_name');
+        $name = is_string($rawName) && trim($rawName) !== '' ? trim($rawName) : null;
+
+        return new self($email, $name);
+    }
+}
diff --git a/app/DataTransferObjects/Onboarding/OnboardingCheckoutDto.php b/app/DataTransferObjects/Onboarding/OnboardingCheckoutDto.php
index d712bd5..612e81e 100644
--- a/app/DataTransferObjects/Onboarding/OnboardingCheckoutDto.php
+++ b/app/DataTransferObjects/Onboarding/OnboardingCheckoutDto.php
@@ -28,7 +28,8 @@
  *   signupGrantTickets: int,
  *   intendedPlanCode: string|null,
  *   consentTerms: AutoRechargeConsentTermsShape,
- *   fundingChoices: list<string>
+ *   fundingChoices: list<string>,
+ *   subscriptionAttemptToken: string
  * }
  */
 final readonly class OnboardingCheckoutDto
@@ -61,6 +62,10 @@ public function __construct(
          * @var list<string>
          */
         public array $fundingChoices = [],
+        /**
+         * P9: 有償プランの契約 checkout 開始 POST が使う冪等 token (render 単位の ULID)。
+         */
+        public string $subscriptionAttemptToken = '',
     ) {}
 
     /**
@@ -81,6 +86,7 @@ public function toArray(): array
             'intendedPlanCode' => $this->intendedPlanCode,
             'consentTerms' => ($this->consentTerms ?? new AutoRechargeConsentTermsDto(0, 0, 0, 0, ''))->toArray(),
             'fundingChoices' => $this->fundingChoices,
+            'subscriptionAttemptToken' => $this->subscriptionAttemptToken,
         ];
     }
 }
diff --git a/app/Enums/Billing/BillingFeedbackKind.php b/app/Enums/Billing/BillingFeedbackKind.php
new file mode 100644
index 0000000..2dfa41f
--- /dev/null
+++ b/app/Enums/Billing/BillingFeedbackKind.php
@@ -0,0 +1,22 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Enums\Billing;
+
+/**
+ * P9: 課金 Checkout / portal の着地フィードバック種別。
+ * Inertia::location() の full page redirect を跨いだ後、/billing 着地で
+ * ユーザーに「購入を受け付けたか / 処理中か / 既に受付済みか」を伝える。
+ *
+ * T088 で PurchaseFormState::Completed を撤去したため、**購入完了をユーザーに知らせる
+ * 唯一の経路が本 feedback (one-shot)** になっている。
+ */
+enum BillingFeedbackKind: string
+{
+    case PurchaseReceived = 'purchase_received';
+    case PurchaseProcessing = 'purchase_processing';
+    case PurchaseAlreadyReceived = 'purchase_already_received';
+    case CheckoutRetryRequired = 'checkout_retry_required';
+    case PortalReturned = 'portal_returned';
+}
diff --git a/app/Exceptions/Billing/SubscriptionAttemptPlanMismatchException.php b/app/Exceptions/Billing/SubscriptionAttemptPlanMismatchException.php
new file mode 100644
index 0000000..8895bc1
--- /dev/null
+++ b/app/Exceptions/Billing/SubscriptionAttemptPlanMismatchException.php
@@ -0,0 +1,18 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Exceptions\Billing;
+
+use RuntimeException;
+
+/**
+ * P9 (N-1): 同一 `subscription_attempt_token` で **別プラン** の checkout が再送された。
+ *
+ * `Billing/Plans` は 1 render = 1 token のため「Starter を押して戻り Standard を押す」が
+ * 同 token・別 plan として実在する。移植元 (aigenba) は保存済み session の plan の
+ * Checkout URL へ replay するが、それでは **押した plan と違う plan の Checkout に着地**する。
+ * AI-CUE は fail-closed に 422 (`plan_code`) へ倒し、ユーザーに再読み込みを促す
+ * (先例: TicketCheckoutService の StaleCheckoutAttemptException 分岐)。
+ */
+final class SubscriptionAttemptPlanMismatchException extends RuntimeException {}
diff --git a/app/Http/Controllers/Billing/BillingController.php b/app/Http/Controllers/Billing/BillingController.php
index 76d2f2c..787e1ad 100644
--- a/app/Http/Controllers/Billing/BillingController.php
+++ b/app/Http/Controllers/Billing/BillingController.php
@@ -4,22 +4,30 @@
 
 namespace App\Http\Controllers\Billing;
 
+use App\Actions\Billing\UpdateBillingContactAction;
 use App\DataTransferObjects\Billing\AutoRechargeConsentDto;
+use App\DataTransferObjects\Billing\BillingContactDto;
 use App\DataTransferObjects\Billing\BillingDashboardDto;
+use App\DataTransferObjects\Billing\BillingFeedbackDto;
 use App\DataTransferObjects\Billing\BillingPlansPageDto;
 use App\DataTransferObjects\Billing\QuotaLimitsDto;
+use App\DataTransferObjects\Billing\UpdateBillingContactData;
 use App\DataTransferObjects\Marketing\PricingPlanDto;
+use App\Enums\Billing\BillingFeedbackKind;
 use App\Enums\Billing\OnboardingBillingState;
-use App\Enums\Billing\PlanPriceKind;
+use App\Enums\Billing\SignupFundingChoice;
 use App\Enums\CheckoutIntent;
 use App\Enums\CheckoutSessionStatus;
 use App\Exceptions\Billing\CheckoutInProgressException;
+use App\Exceptions\Billing\StaleCheckoutAttemptException;
 use App\Exceptions\Billing\StripePriceNotSyncedException;
+use App\Exceptions\Billing\SubscriptionAttemptPlanMismatchException;
 use App\Http\Concerns\ResolvesCurrentOrganization;
 use App\Http\Controllers\Controller;
 use App\Http\Requests\Billing\BillingCheckoutRequest;
 use App\Http\Requests\Billing\StartAutoRechargeSetupRequest;
 use App\Http\Requests\Billing\UpdateAutoRechargeRequest;
+use App\Http\Requests\Billing\UpdateBillingContactRequest;
 use App\Models\Billing\BillingCheckoutSession;
 use App\Models\Billing\Plan;
 use App\Models\Billing\Subscription;
@@ -37,6 +45,7 @@
 use Illuminate\Http\Request;
 use Illuminate\Support\Facades\Gate;
 use Illuminate\Support\Str;
+use Illuminate\Validation\ValidationException;
 use Inertia\Inertia;
 use Inertia\Response;
 use InvalidArgumentException;
@@ -88,6 +97,13 @@ public function index(
             return $landing;
         }
 
+        // T1004: funding=auto_recharge の契約完了着地は ?highlight=auto-recharge へ 303 + flash
+        // (オートリチャージ設定への導線を成功着地の主役にする)。非該当なら通常 feedback へ委ねる。
+        $autoRechargeLanding = $this->resolveAutoRechargeLanding($request, $organization);
+        if ($autoRechargeLanding !== null) {
+            return $autoRechargeLanding;
+        }
+
         $canManageBilling = $user->can('manageBilling', $organization);
         $subscription = $organization->subscription('default');
 
@@ -107,6 +123,10 @@ public function index(
             // カード登録開始 POST の attempt_token (render 単位。setup は課金を伴わないため
             // 購入導線のようなサーバ側安定化は不要 — 同一 token の再送は台帳 unique で冪等)。
             autoRechargeSetupToken: strtolower((string) Str::ulid()),
+            // P9: 決済戻り着地の one-shot フィードバック (query 解釈済み)。
+            feedback: $this->resolveBillingFeedback($request, $organization),
+            // P9: 請求先連絡先 (未設定なら owner email が実際の宛先)。
+            billingContact: BillingContactDto::fromOrganization($organization),
         );
 
         return Inertia::render('Billing/Index', ['page' => $dto->toArray()]);
@@ -130,6 +150,8 @@ public function plans(Request $request, PricingService $pricing): Response
             currentPlanCode: $this->resolveCurrentPlanCode($organization),
             billingState: $this->access->state($organization),
             canManage: $user->can('manageBilling', $organization),
+            // P9: 契約 checkout の冪等 token (画面 render ごとに固定 = 1 render 1 token)。
+            subscriptionAttemptToken: (string) Str::ulid(),
         );
 
         return Inertia::render('Billing/Plans', ['page' => $dto->toArray()]);
@@ -167,44 +189,108 @@ private function resolveCurrentPlan(Organization $organization, PricingService $
     }
 
     /**
-     * Stripe Checkout を開始し、Checkout URL へリダイレクトする
-     * (戻り型に RedirectResponse を含むのは price 不在 / 開始不可時の back() 分岐のため)
+     * P9: Stripe Checkout (サブスク契約) を **冪等** に開始し、Checkout URL へリダイレクトする。
+     *
+     * 実行順は不変条件 #2 (「不整合は認可より前に 404」) に従う:
+     * (1) 他 org / 他 user の token は Gate より前に 404 (403 にしない = 存在オラクル封じ)
+     * (2) 認可 (3) T1004 の事前同意記録 (4) plan 解決 → 冪等開始。
+     *
+     * ボタンを disabled にはしない (禁止事項 #8) ため、ここで返すエラー・422 が
+     * 押下時のフィードバックになる。
      */
-    public function checkout(BillingCheckoutRequest $request, SubscriptionService $subscriptions): SymfonyResponse|RedirectResponse
-    {
+    public function checkout(
+        BillingCheckoutRequest $request,
+        SubscriptionService $subscriptions,
+        AutoRechargeService $autoRecharge,
+    ): SymfonyResponse|RedirectResponse {
         $organization = $this->resolveCurrentOrganization($request);
+
+        $user = $request->user();
+        Assert::isInstanceOf($user, User::class);
+
+        $attemptToken = $request->validated('subscription_attempt_token');
+        Assert::string($attemptToken);
+
+        // (1) 他 org / 他 user の token は 404 (Gate より前 = 存在オラクル封じ)
+        abort_if($subscriptions->attemptTokenIsForeign($attemptToken, $organization, $user), 404);
+
+        // (2) 認可
         Gate::authorize('manageBilling', $organization);
 
+        // (3) T1004: funding=auto_recharge は事前同意 (enabled=false) を Checkout 開始前に記録する。
+        //     Checkout が後段で失敗・放棄されても同意 row は無害 (enabled=false = 課金は発生しない)。
+        $fundingRaw = $request->validated('funding_choice');
+        $funding = is_string($fundingRaw) ? SignupFundingChoice::from($fundingRaw) : null;
+        if ($funding === SignupFundingChoice::AutoRecharge) {
+            $consentVersion = $request->validated('consent_version');
+            Assert::stringNotEmpty($consentVersion);
+            try {
+                $autoRecharge->recordPreConsent($organization, $user, new AutoRechargeConsentDto($consentVersion));
+            } catch (CheckoutInProgressException $e) {
+                return back()->with('error', $e->getMessage());
+            }
+        }
+
+        // (4) plan 解決 → 冪等開始
         $planCode = $request->validated('plan_code');
         Assert::string($planCode);
-        $plan = Plan::query()->where('code', $planCode)->firstOrFail();
-
-        $price = $plan->currentPrice(PlanPriceKind::Base);
-        if ($price === null) {
-            return back()->with('error', '選択したプランは現在お申し込みいただけません。');
-        }
+        $plan = Plan::query()->where('code', $planCode)->where('is_active', true)->firstOrFail();
 
         try {
-            $redirect = $subscriptions->startCheckout(
+            $result = $subscriptions->startCheckout(
                 $organization,
-                $price,
-                route('billing.index'),
-                route('billing.index'),
+                $user,
+                $plan,
+                route('billing.index').'?session_id={CHECKOUT_SESSION_ID}',
+                route('billing.plans'),
+                $attemptToken,
+                $funding,
             );
+        } catch (SubscriptionAttemptPlanMismatchException $e) {
+            // 同 token・別 plan (1 render 1 token のため「戻って別プランを押す」で実在する)
+            throw ValidationException::withMessages(['plan_code' => $e->getMessage()]);
+        } catch (StaleCheckoutAttemptException) {
+            return redirect()->route('billing.index', ['retry' => 1]);
+        } catch (CheckoutInProgressException $e) {
+            return back()->with('error', $e->getMessage());
         } catch (StripePriceNotSyncedException) {
             // production の sync 漏れ。500 にせず現行と同一文言で差し戻す
             return back()->with('error', '選択したプランは現在お申し込みいただけません。');
         } catch (InvalidArgumentException $e) {
-            // 既に有効なサブスクリプションがある (service 層の fail-closed ガード)
+            // 既に有効なサブスクリプションがある / Price 未設定 (service 層の fail-closed ガード)
             return back()->with('error', $e->getMessage());
         }
 
+        if ($result->url === null) {
+            // url=null は「新規 Checkout を作らなかった」= 受付済み replay か live pending dedup。
+            return $subscriptions->isAttemptCompleted($organization, $result->stripeSessionId)
+                ? redirect()->route('billing.index', ['replayed' => 1])
+                : back()->with('warning', '既に進行中の Checkout があります。数分お待ちください。');
+        }
+
         // 契約開始が成立したのでプラン意図を消費する (checkout URL 取得後・遷移前)。
-        // price 不在 / 開始不可の back() 経路では forget しない = 意図を維持して再試行できる。
+        // 開始不可の back() 経路では forget しない = 意図を維持して再試行できる。
         $this->intendedPlanResolver->forgetForOrganization($organization);
 
         // 外部 URL への遷移は Inertia::location (full page redirect)
-        return Inertia::location($redirect->url);
+        return Inertia::location($result->url);
+    }
+
+    /**
+     * P9: 請求先連絡先 (メール / 宛名) の更新。current-org スコープ
+     * (route parameter を持たないため cross-org 指定が構造的に不能)。
+     */
+    public function updateBillingContact(
+        UpdateBillingContactRequest $request,
+        UpdateBillingContactAction $action,
+    ): RedirectResponse {
+        $organization = $this->resolveCurrentOrganization($request);
+        Gate::authorize('manageBilling', $organization);
+
+        $action->execute($organization, UpdateBillingContactData::fromRequest($request));
+
+        // 操作系 POST/PATCH は back() で完結させる (禁止事項 #7)
+        return back()->with('info', '請求先情報を更新しました。');
     }
 
     /**
@@ -314,6 +400,117 @@ private function resolveAutoRechargeSetupLanding(Request $request, Organization
         return redirect()->route('billing.index', [], 303)->with('success', $message);
     }
 
+    /**
+     * P9 (T1004): funding=auto_recharge の契約完了着地を `?highlight=auto-recharge` へ 303 する。
+     *
+     * 自 org の `subscription_start` + `completed` + `funding_choice=auto_recharge` を検証できた
+     * ときだけ変換する (他 org / `setup_payment_method` の session_id は素通し = IDOR 防御)。
+     * 文言は「実際に PM 流用 Job が dispatch 済み (= 決済確定) かつ有効な事前同意が待機中」の
+     * ときだけ確定表現にし、それ以外は fail-closed な誘導文言に落とす。
+     */
+    private function resolveAutoRechargeLanding(Request $request, Organization $organization): ?RedirectResponse
+    {
+        $sessionId = $request->query('session_id');
+        if (! is_string($sessionId) || $sessionId === '') {
+            return null;
+        }
+
+        $session = $organization->checkoutSessions()
+            ->where('stripe_session_id', $sessionId)
+            ->first();
+
+        if (! $session instanceof BillingCheckoutSession
+            || $session->intent !== CheckoutIntent::SubscriptionStart->value
+            || $session->status !== CheckoutSessionStatus::Completed->value
+            || $session->funding_choice !== SignupFundingChoice::AutoRecharge->value) {
+            return null; // それ以外は従来どおり resolveBillingFeedback に委ねる
+        }
+
+        $message = $session->pm_reuse_dispatched_at !== null
+            && $this->autoRecharge->isAutoEnablePending($organization)
+            ? 'お支払いを受け付けました。オートリチャージは、ご契約のお支払いカードで自動的に有効になります。反映されない場合は、この画面から設定できます。'
+            : 'お支払いを受け付けました。オートリチャージの設定はこの画面から確認できます。';
+
+        // 前段が積んだ flash を 303 の 1 hop を跨いで着地 render まで生存させる。
+        $request->session()->reflash();
+
+        return redirect()
+            ->route('billing.index', ['highlight' => 'auto-recharge'], 303)
+            ->with('info', $message);
+    }
+
+    /**
+     * P9: /billing 着地時の query を解釈してフィードバックを構築する (one-shot)。
+     *
+     * UI は raw query を見ず、この DTO のみを描画する。`session_id` は **org スコープ relation**
+     * 経由でのみ引くため、他 org の session_id を付けても feedback は出ない (偽 success 排除)。
+     * さらに **intent !== subscription_start は null** に倒す (fail-closed。P8a の
+     * `setup_payment_method` 行が同一テーブルに実在するため必須)。
+     */
+    private function resolveBillingFeedback(Request $request, Organization $organization): ?BillingFeedbackDto
+    {
+        if ($request->query('portal') !== null) {
+            // error flash がある着地では成功偽装を抑止するため portal_returned を出さない。
+            if (is_string($request->session()->get('error'))) {
+                return null;
+            }
+
+            return BillingFeedbackDto::simple(
+                BillingFeedbackKind::PortalReturned,
+                'お支払い管理画面から戻りました。',
+            );
+        }
+
+        $sessionId = $request->query('session_id');
+        if (is_string($sessionId) && $sessionId !== '') {
+            $session = $organization->checkoutSessions()
+                ->where('stripe_session_id', $sessionId)
+                ->first();
+
+            // 未知 / 別 org の session_id (手動付与) は feedback を出さない。
+            if (! $session instanceof BillingCheckoutSession) {
+                return null;
+            }
+            // intent 検証で fail-closed (カード登録の着地に購入文言を出さない)。
+            if ($session->intent !== CheckoutIntent::SubscriptionStart->value) {
+                return null;
+            }
+
+            if ($session->status === CheckoutSessionStatus::Completed->value) {
+                return BillingFeedbackDto::simple(
+                    BillingFeedbackKind::PurchaseReceived,
+                    'お支払いを受け付けました。プランへの反映には数分かかる場合があります。',
+                );
+            }
+
+            if ($session->status === CheckoutSessionStatus::Pending->value) {
+                return BillingFeedbackDto::simple(
+                    BillingFeedbackKind::PurchaseProcessing,
+                    'お支払いを確認しています。プラン反映までしばらくお待ちください。',
+                );
+            }
+
+            // Failed / Expired は無言 (状態を主張しない。出口は Plans からの新規 token 発行)。
+            return null;
+        }
+
+        if ($request->query('replayed') !== null) {
+            return BillingFeedbackDto::simple(
+                BillingFeedbackKind::PurchaseAlreadyReceived,
+                'この内容のお支払いは既に受け付け済みです。',
+            );
+        }
+
+        if ($request->query('retry') !== null) {
+            return BillingFeedbackDto::simple(
+                BillingFeedbackKind::CheckoutRetryRequired,
+                'お手続きの有効期限が切れました。画面を再読み込みして再試行してください。',
+            );
+        }
+
+        return null;
+    }
+
     /**
      * 契約成立着地でのみ「元の画面に戻る」導線を出す (1 回限り = リロードで CTA が残らない)。
      *
@@ -354,6 +551,9 @@ public function portal(Request $request, SubscriptionService $subscriptions): Sy
             return back()->with('error', 'お支払い管理画面は有償プラン契約後にご利用いただけます。');
         }
 
-        return Inertia::location($subscriptions->createPortalSession($organization, route('billing.index'))->url);
+        // 戻り着地で `portal_returned` feedback を出すため ?portal=1 を付ける (UI は raw query を見ない)。
+        return Inertia::location(
+            $subscriptions->createPortalSession($organization, route('billing.index', ['portal' => 1]))->url,
+        );
     }
 }
diff --git a/app/Http/Controllers/Onboarding/OnboardingController.php b/app/Http/Controllers/Onboarding/OnboardingController.php
index c9e4194..6ce2401 100644
--- a/app/Http/Controllers/Onboarding/OnboardingController.php
+++ b/app/Http/Controllers/Onboarding/OnboardingController.php
@@ -23,6 +23,7 @@
 use Illuminate\Http\RedirectResponse;
 use Illuminate\Http\Request;
 use Illuminate\Support\Facades\Gate;
+use Illuminate\Support\Str;
 use Inertia\Inertia;
 use Inertia\Response;
 use Webmozart\Assert\Assert;
@@ -93,6 +94,8 @@ public function show(Request $request): Response|RedirectResponse
                 SignupFundingChoice::AutoRecharge->value,
                 SignupFundingChoice::Later->value,
             ],
+            // P9: 有償プラン契約 POST の冪等 token (render 単位の ULID)。
+            subscriptionAttemptToken: (string) Str::ulid(),
         );
 
         return Inertia::render('Onboarding/Checkout', [
diff --git a/app/Http/Requests/Billing/BillingCheckoutRequest.php b/app/Http/Requests/Billing/BillingCheckoutRequest.php
index 1fa48cc..def7e24 100644
--- a/app/Http/Requests/Billing/BillingCheckoutRequest.php
+++ b/app/Http/Requests/Billing/BillingCheckoutRequest.php
@@ -4,14 +4,23 @@
 
 namespace App\Http\Requests\Billing;
 
+use App\Enums\Billing\SignupFundingChoice;
 use App\Http\Requests\Concerns\ProhibitsProtectedKeys;
 use Illuminate\Foundation\Http\FormRequest;
+use Illuminate\Validation\Rule;
+use Webmozart\Assert\Assert;
 
 /**
  * Stripe Checkout 開始。Policy 検証 (manageBilling) は Controller 側 (Gate::authorize)。
  *
  * plan_code は「ユーザーがどのプランを購入するか」の選択値であり、tenant/状態キーではない
  * (organizations.plan_code への反映は webhook 同期のみ。この値で直接書き換えることはない)。
+ *
+ * P9: `subscription_attempt_token` (冪等 token) を必須にする。単一契約 route が
+ * Plans 経路 (funding 非提示) と Onboarding 経路 (funding 2 択) の両方を宿すため
+ * `funding_choice` は **nullable** (null = 従来の契約 checkout = PM 流用しない)。
+ * `funding_choice=auto_recharge` のときだけ現行 `consent_version` との完全一致を要求する
+ * (不一致・欠落は 422 = recordPreConsent にも Stripe にも到達しない fail-closed)。
  */
 class BillingCheckoutRequest extends FormRequest
 {
@@ -29,6 +38,42 @@ public function rules(): array
     {
         return array_merge([
             'plan_code' => ['required', 'string', 'exists:plans,code'],
+            // Str::ulid() は大文字 Crockford base32 を含むため lowercase regex 不可
+            // → Laravel の 'ulid' ルールを使う。
+            'subscription_attempt_token' => ['required', 'ulid'],
+            'funding_choice' => [
+                'nullable',
+                'string',
+                Rule::in(array_map(
+                    static fn (SignupFundingChoice $choice): string => $choice->value,
+                    SignupFundingChoice::cases(),
+                )),
+            ],
+            'consent_version' => [
+                'required_if:funding_choice,'.SignupFundingChoice::AutoRecharge->value,
+                'string',
+                'max:16',
+                Rule::in([$this->currentAutoRechargeConsentVersion()]),
+            ],
         ], $this->protectedKeyMissingRules());
     }
+
+    /**
+     * @return array<string, string>
+     */
+    public function messages(): array
+    {
+        return [
+            'consent_version.required_if' => '自動購入への同意が必要です。',
+            'consent_version.in' => '自動購入の同意内容が更新されています。ページを再読み込みして内容を確認してください。',
+        ];
+    }
+
+    private function currentAutoRechargeConsentVersion(): string
+    {
+        $version = config()->string('billing.auto_recharge.consent_version');
+        Assert::stringNotEmpty($version, 'config billing.auto_recharge.consent_version は非空で設定してください');
+
+        return $version;
+    }
 }
diff --git a/app/Http/Requests/Billing/UpdateBillingContactRequest.php b/app/Http/Requests/Billing/UpdateBillingContactRequest.php
new file mode 100644
index 0000000..175ad73
--- /dev/null
+++ b/app/Http/Requests/Billing/UpdateBillingContactRequest.php
@@ -0,0 +1,34 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Http\Requests\Billing;
+
+use App\Http\Requests\Concerns\ProhibitsProtectedKeys;
+use Illuminate\Foundation\Http\FormRequest;
+
+/**
+ * P9: 請求先連絡先 (メール / 宛名) の更新。
+ * 認可 (manageBilling) は Controller 側 (Gate::authorize)。組織は current-org スコープ
+ * (route parameter を持たないため cross-org 指定が構造的に不能)。
+ */
+class UpdateBillingContactRequest extends FormRequest
+{
+    use ProhibitsProtectedKeys;
+
+    public function authorize(): bool
+    {
+        return true;
+    }
+
+    /**
+     * @return array<string, list<mixed>>
+     */
+    public function rules(): array
+    {
+        return array_merge([
+            'billing_contact_email' => ['required', 'email:rfc', 'max:255'],
+            'billing_contact_name' => ['nullable', 'string', 'max:255'],
+        ], $this->protectedKeyMissingRules());
+    }
+}
diff --git a/app/Jobs/Billing/ReuseSubscriptionPaymentMethodJob.php b/app/Jobs/Billing/ReuseSubscriptionPaymentMethodJob.php
new file mode 100644
index 0000000..159d765
--- /dev/null
+++ b/app/Jobs/Billing/ReuseSubscriptionPaymentMethodJob.php
@@ -0,0 +1,71 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Jobs\Billing;
+
+use App\Models\Organization;
+use App\Services\Billing\AutoRechargeService;
+use App\Services\Billing\Contracts\AutoRechargeGatewayInterface;
+use Illuminate\Bus\Queueable;
+use Illuminate\Contracts\Queue\ShouldQueue;
+use Illuminate\Foundation\Bus\Dispatchable;
+use Illuminate\Queue\InteractsWithQueue;
+use Illuminate\Queue\SerializesModels;
+use Illuminate\Support\Facades\Log;
+
+/**
+ * P9 (T1004): mode=subscription Checkout 完了 (funding=auto_recharge) からの
+ * サブスク決済カード流用。
+ *
+ * webhook 同期処理から**外向き Stripe API を撃たない** invariant のため Job へ退避する:
+ * PM 解決 (gateway) → `AutoRechargeService::applyReusedPaymentMethod`
+ * (適格性先行 fail-closed — 同意なし・失効・停止状態では customer default PM にも
+ * ローカル snapshot にも一切触れない)。
+ *
+ * Model 参照は保持しない (id のみ) = 遅延実行中の stale snapshot を作らない。
+ */
+final class ReuseSubscriptionPaymentMethodJob implements ShouldQueue
+{
+    use Dispatchable;
+    use InteractsWithQueue;
+    use Queueable;
+    use SerializesModels;
+
+    public int $tries = 3;
+
+    public int $backoff = 30;
+
+    public function __construct(
+        public readonly int $organizationId,
+        public readonly string $stripeSubscriptionId,
+    ) {}
+
+    public function handle(AutoRechargeGatewayInterface $gateway, AutoRechargeService $autoRecharge): void
+    {
+        $org = Organization::query()->find($this->organizationId);
+        if (! $org instanceof Organization) {
+            return;
+        }
+
+        // 軽量 guard: webhook 再送等で明らかに no-op のとき (enabled 済み・同意なし・失効・停止)、
+        // Stripe retrieve より前に return する (不要な外部 API 呼び出しの排除)。
+        if (! $autoRecharge->isAutoEnablePending($org)) {
+            return;
+        }
+
+        $paymentMethodId = $gateway->resolveSubscriptionPaymentMethod($this->stripeSubscriptionId);
+        if ($paymentMethodId === null) {
+            // PM 解決不能でも詰まない (請求ページのカード登録 CTA で回復できる)。
+            // ログには org id / subscription id のみ出す (PM・customer 情報は出さない)。
+            Log::warning('auto-recharge: subscription PM unresolved, skipping reuse', [
+                'organization_id' => $this->organizationId,
+                'stripe_subscription_id' => $this->stripeSubscriptionId,
+            ]);
+
+            return;
+        }
+
+        $autoRecharge->applyReusedPaymentMethod($org, $paymentMethodId);
+    }
+}
diff --git a/app/Models/Billing/BillingCheckoutSession.php b/app/Models/Billing/BillingCheckoutSession.php
index 1afc55d..3b79409 100644
--- a/app/Models/Billing/BillingCheckoutSession.php
+++ b/app/Models/Billing/BillingCheckoutSession.php
@@ -7,6 +7,7 @@
 use App\Enums\CheckoutIntent;
 use App\Enums\CheckoutSessionStatus;
 use App\Models\Organization;
+use Carbon\CarbonImmutable;
 use Database\Factories\Billing\BillingCheckoutSessionFactory;
 use Illuminate\Database\Eloquent\Factories\HasFactory;
 use Illuminate\Database\Eloquent\Model;
@@ -17,17 +18,25 @@
  * サブスク契約 Checkout Session の追跡行 (`BillingAccess::state()` の
  * PendingCheckout / ExpiredCheckout の真実源)。
  *
+ * P9 (C-1): **「pending 行が live か」の判定は本クラスの述語だけが定義する**。
+ * 閾値 (now - 1day) は staleThresholdAt() の 1 箇所にしか literal として現れず、
+ * `BillingAccess::state()` / `SubscriptionService::startCheckout()` の段 2/3/4 /
+ * 日次 sweeper (`ReconcileSubscriptionSchedules::expireStaleCheckouts()`) の 4 経路が
+ * これを共有する (判定の正しさを sweeper の実行タイミングに依存させない)。
+ *
  * @property int $id
  * @property int $organization_id
  * @property int|null $initiated_by_user_id
  * @property string $intent
  * @property string|null $plan_code
+ * @property string|null $funding_choice
  * @property string $stripe_session_id
  * @property string $idempotency_key
  * @property string|null $attempt_token
  * @property string|null $checkout_url
  * @property string $status
  * @property Carbon|null $completed_at
+ * @property Carbon|null $pm_reuse_dispatched_at
  * @property Carbon|null $created_at
  * @property Carbon|null $updated_at
  */
@@ -40,11 +49,15 @@ class BillingCheckoutSession extends Model
      * tenant / actor キー (organization_id / initiated_by_user_id) は移植元と異なり
      * $fillable に載せない (MassAssignmentProtectedKeys の不変条件。relation / 明示代入のみ)。
      *
+     * `pm_reuse_dispatched_at` も **意図的に $fillable 外** — webhook (StripeWebhookProcessor)
+     * の forceFill 専用 marker であり、クライアント入力・通常の fill 経路では立てない。
+     *
      * @var list<string>
      */
     protected $fillable = [
         'intent',
         'plan_code',
+        'funding_choice',
         'stripe_session_id',
         'idempotency_key',
         'attempt_token',
@@ -56,6 +69,7 @@ class BillingCheckoutSession extends Model
     /** @var array<string, string> */
     protected $casts = [
         'completed_at' => 'datetime',
+        'pm_reuse_dispatched_at' => 'datetime',
         'initiated_by_user_id' => 'integer',
     ];
 
@@ -75,12 +89,39 @@ public function statusEnum(): CheckoutSessionStatus
     }
 
     /**
-     * Pending かつ checkout_url 生存 = 復帰可能な進行中 Checkout。
-     * 購入導線が resume 状態 (decision URL 再提示) を出すか判定する述語。
+     * live/stale の境界 (**閾値 literal の単一出典**)。
+     * Stripe Checkout Session の 24h 自動 expire と一致させる (移植元 aigenba: subDay)。
+     *
+     * 境界は排他的に統一する:
+     *   live  : created_at >= staleThresholdAt($now)   (isLivePending / state() / dedup の SQL filter)
+     *   stale : created_at <  staleThresholdAt($now)   (sweeper の expireStaleCheckouts)
+     * 両者は補集合であり、境界時刻ちょうどの行が「live かつ Expired 化対象」になることはない。
+     *
+     * `now()` を内部で呼ばない純関数 (テストが時刻を注入できる)。
+     */
+    public static function staleThresholdAt(CarbonImmutable $now): CarbonImmutable
+    {
+        return $now->subDay();
+    }
+
+    /**
+     * live pending (= 決済待ちとして生きている) か。
+     * created_at が null の行は live 扱い (P2 state() の else 分岐と同一)。
      */
-    public function isReplayablePending(): bool
+    public function isLivePending(CarbonImmutable $now): bool
     {
         return $this->status === CheckoutSessionStatus::Pending->value
+            && ($this->created_at === null
+                || $this->created_at->greaterThanOrEqualTo(self::staleThresholdAt($now)));
+    }
+
+    /**
+     * live pending かつ checkout_url 生存 = 復帰可能な進行中 Checkout。
+     * 購入導線が resume 状態 (decision URL 再提示) を出すか判定する述語。
+     */
+    public function isReplayablePending(CarbonImmutable $now): bool
+    {
+        return $this->isLivePending($now)
             && $this->checkout_url !== null
             && $this->checkout_url !== '';
     }
diff --git a/app/Models/Organization.php b/app/Models/Organization.php
index e9f2b95..8829945 100644
--- a/app/Models/Organization.php
+++ b/app/Models/Organization.php
@@ -5,6 +5,7 @@
 namespace App\Models;
 
 use App\Enums\OrganizationRole;
+use App\Models\Billing\BillingCheckoutSession;
 use App\Models\Billing\OrganizationQuota;
 use App\Models\Billing\Plan;
 use App\Models\Billing\TicketLedgerEntry;
@@ -21,6 +22,11 @@
 use Illuminate\Notifications\Notification;
 use Illuminate\Notifications\RoutesNotifications;
 use Laravel\Cashier\Billable;
+use ParagonIE\CipherSweet\BlindIndex;
+use ParagonIE\CipherSweet\EncryptedRow;
+use ParagonIE\CipherSweet\Transformation\Lowercase;
+use Spatie\LaravelCipherSweet\Concerns\UsesCipherSweet;
+use Spatie\LaravelCipherSweet\Contracts\CipherSweetEncrypted;
 use Webmozart\Assert\Assert;
 
 /**
@@ -40,18 +46,52 @@
  * (`subscriptions` テーブルは Stripe 実体のみを保持する invariant を守るため)。
  * free_plan_code / free_plan_activated_at / personal_declared_* / signup_tickets_granted_at は
  * いずれも状態キーのため $fillable 外 (PersonalPlanService の forceFill 経由でのみ書き込む)。
+ *
+ * P9: 請求先連絡先 (billing_contact_email / billing_contact_name) は PII のため
+ * **両列とも CipherSweet で暗号化**する (セキュリティ不変条件 #6)。平文 where は hit しないため
+ * email の検索は `whereBlind('billing_contact_email', 'organization_billing_contact_email_index', …)`
+ * のみ (保存値は EmailNormalizer 正規化済みのため検索入力も同一正規化を通すこと)。
+ * 両列とも $fillable 外 (UpdateBillingContactAction が明示代入する)。
+ *
+ * @property string|null $billing_contact_email
+ * @property string|null $billing_contact_name
  */
-class Organization extends Model
+class Organization extends Model implements CipherSweetEncrypted
 {
     /** @use HasFactory<OrganizationFactory> */
-    use Billable, HasFactory, RoutesNotifications, SoftDeletes;
+    use Billable, HasFactory, RoutesNotifications, SoftDeletes, UsesCipherSweet;
 
-    /** @var list<string> */
+    /**
+     * billing_contact_* は含めない (UpdateBillingContactAction が明示代入する)。
+     *
+     * @var list<string>
+     */
     protected $fillable = [
         'name',
         'slug',
     ];
 
+    /**
+     * 請求先連絡先の暗号化設定 (不変条件 #6)。
+     *
+     * 両列とも nullable のため `addOptionalTextField` を使う
+     * (`addField` は null で fieldNotOptional 例外になる = Inquiry の先例)。
+     */
+    public static function configureCipherSweet(EncryptedRow $encryptedRow): void
+    {
+        $encryptedRow
+            ->addOptionalTextField('billing_contact_email')
+            ->addOptionalTextField('billing_contact_name')
+            // 検索契約: 請求調査 (Stripe Dashboard の請求先メール → AI-CUE 組織の逆引き =
+            // 返金・二重課金の一次対応で唯一の特定経路) のため email のみ blind index 化する。
+            ->addBlindIndex(
+                'billing_contact_email',
+                new BlindIndex('organization_billing_contact_email_index', [new Lowercase]),
+            );
+        // billing_contact_name は blind index を張らない
+        // (等値検索の要求が無い = 検索が必要な項目だけ whereBlind)。
+    }
+
     /**
      * @return BelongsTo<Team, $this>
      */
@@ -162,15 +202,52 @@ public function defaultTeam(): CustomTeam
         return $team;
     }
 
+    /**
+     * サブスク契約 / カード登録 Checkout の追跡行 (P2 で導入。P9 の着地 feedback /
+     * T1004 の着地 flash が **org スコープ**で引くために必要)。
+     *
+     * @return HasMany<BillingCheckoutSession, $this>
+     */
+    public function checkoutSessions(): HasMany
+    {
+        return $this->hasMany(BillingCheckoutSession::class);
+    }
+
     /**
      * 請求通知の宛先 (BillingNotificationDispatcher が組織宛に notify する)。
-     * テンプレートは請求先メール列を持たないため Owner メンバーの email に送る。
-     * Owner を解決できない場合は null (dispatcher が failed(missing_billing_recipient)
+     *
+     * P9: `billing_contact_email` が正本で、未設定なら Owner メンバーの email へ fallback する。
+     * Owner も解決できない場合は null (dispatcher が failed(missing_billing_recipient)
      * として確定し queued 滞留を防ぐ)。
-     * 派生アプリは billing_contact_email 等の正本列を追加して本メソッドを上書きする。
      */
     public function routeNotificationForMail(Notification $notification): ?string
     {
+        return $this->billingContactEmail();
+    }
+
+    /**
+     * Stripe customer に同期する請求先メール (Cashier の syncStripeCustomerDetails が読む)。
+     *
+     * Organization は `email` 列を持たないため Cashier 既定では null になる。請求先の正本を
+     * 明示して「請求書が届く先」を Stripe 側と一致させる。**宛名 (billing_contact_name) は
+     * Stripe へ送らない** (`stripeName()` は組織名のまま = 送信内容の境界を広げない)。
+     */
+    public function stripeEmail(): ?string
+    {
+        return $this->billingContactEmail();
+    }
+
+    /**
+     * 請求関連の宛先メール (billing_contact_email 正本 → owner email fallback)。
+     * 通知宛先と checkout 事前検証 (SubscriptionService::assertCheckoutReady) の共通出典。
+     */
+    public function billingContactEmail(): ?string
+    {
+        $contact = $this->billing_contact_email;
+        if (is_string($contact) && trim($contact) !== '') {
+            return $contact;
+        }
+
         /** @var User|null $owner */
         $owner = $this->users()
             ->get()
diff --git a/app/Services/Billing/AutoRechargeService.php b/app/Services/Billing/AutoRechargeService.php
index e0c0ed6..0c09624 100644
--- a/app/Services/Billing/AutoRechargeService.php
+++ b/app/Services/Billing/AutoRechargeService.php
@@ -10,6 +10,7 @@
 use App\Enums\Billing\AutoRechargeAttemptStatus;
 use App\Enums\Billing\AutoRechargeDisabledReason;
 use App\Enums\Billing\BillingNotificationType;
+use App\Enums\Billing\SignupFundingChoice;
 use App\Enums\CheckoutIntent;
 use App\Enums\CheckoutSessionStatus;
 use App\Exceptions\Billing\CheckoutInProgressException;
@@ -115,9 +116,15 @@ public function settingsFor(Organization $organization, bool $canManage): AutoRe
             && $config->stripe_payment_method_id === null
             && $this->autoEnableEligible($config);
 
-        // 「処理中」判定 = setup Checkout 完了済みだが PM snapshot 未反映。
-        // (P9 の signup-funding 契約経由の PM 流用は本フェーズでは配線されない。)
-        $setupPending = ! $hasPm && $this->hasRecentCompletedSetup($organization);
+        // 「処理中」判定:
+        //  (a) P8a: カード登録 (mode=setup) Checkout 完了済みだが PM snapshot 未反映
+        //  (b) P9/T1004: funding=auto_recharge の有償契約が決済確定し、PM 流用 Job の収束待ち
+        // (b) は **pendingAutoEnable=true のときだけ** 効かせる (v1 失効・再同意が必要な org で
+        // 30 分間カード登録 CTA / 再同意導線を隠さないため)。
+        $setupPending = ! $hasPm && (
+            $this->hasRecentCompletedSetup($organization)
+            || ($pendingAutoEnable && $this->hasRecentAutoRechargeFundedSignup($organization))
+        );
 
         return new AutoRechargeSettingsDto(
             enabled: $config !== null && $config->enabled,
@@ -946,6 +953,84 @@ function () use ($organization, $paymentMethodId): bool {
         return $enabledNow;
     }
 
+    /**
+     * P9 (T1004): サブスク決済カードをオートリチャージへ流用する。
+     *
+     * setup 経路 (`applySetupCompletion`) との違い: **ユーザーは「オートリチャージ用のカード登録」を
+     * 明示していない**ため、適格性 (`autoEnableEligible`) を**先に**確認し、不適格なら
+     * customer default PM もローカル snapshot も一切変更しない完全 no-op にする (fail-closed)。
+     *
+     * 適格時の副作用 (customer の `invoice_settings.default_payment_method` 更新) は
+     * v2 同意文言 (契約のお支払いカードをオートリチャージにも使う) で開示済み。
+     * updateSettings / applySetupCompletion / recordPreConsent / executeAttempt と
+     * **同一 org lock** で直列化するため、lock 保持中に適格性が変化する経路は構造的に存在しない。
+     *
+     * @return bool 今回の呼び出しで enabled に遷移したか
+     */
+    public function applyReusedPaymentMethod(Organization $organization, string $paymentMethodId): bool
+    {
+        Assert::stringNotEmpty($paymentMethodId); // fake/将来呼び出しの空文字混入防御
+
+        $lock = Cache::lock($this->lockName($organization), self::LOCK_TTL_SECONDS);
+
+        try {
+            /** @var bool $enabledNow */
+            $enabledNow = $lock->block(10, function () use ($organization, $paymentMethodId): bool {
+                // 適格性の先行確認 (lock 内・TX 外): 不適格なら Stripe にも DB にも触らない。
+                $config = $this->configFor($organization);
+                if ($config === null || ! $this->autoEnableEligible($config)) {
+                    Log::info('auto-recharge: subscription PM reuse skipped (not eligible)', [
+                        'organization_id' => $this->orgId($organization),
+                        'reason' => $config === null ? 'no_config' : 'not_eligible',
+                    ]);
+
+                    return false;
+                }
+
+                // 適格 → default PM を設定 (Cashier 冪等実装) してから有効化を確定する。
+                $this->gateway->setDefaultPaymentMethod($organization, $paymentMethodId);
+
+                return DB::transaction(function () use ($organization, $paymentMethodId): bool {
+                    $config = $this->lockedConfigFor($organization);
+                    // ここで不適格になる経路は上記 lock 直列化により到達不能のはず。到達した場合は
+                    // 「Stripe だけ変更済みの部分適用」なので silent no-op にせず例外で顕在化させる
+                    // (Job retry → 適格なら収束 / 不適格が続くなら failed_jobs で検知)。
+                    if ($config === null || ! $this->autoEnableEligible($config)) {
+                        throw new RuntimeException(
+                            'auto-recharge PM reuse: eligibility lost after default PM update (org '
+                            .$this->orgId($organization).') — partial application detected',
+                        );
+                    }
+
+                    $wasEnabled = $config->enabled;
+                    $config->stripe_payment_method_id = $paymentMethodId;
+                    $config->enabled = true;
+                    $config->failure_count = 0;
+                    $config->save();
+
+                    return ! $wasEnabled;
+                });
+            });
+        } catch (LockTimeoutException $e) {
+            // webhook Job (tries=3, backoff=30) の再試行に乗せる (握り潰さない)。
+            throw new RuntimeException(
+                'auto-recharge PM reuse lock busy for org '.$this->orgId($organization),
+                previous: $e,
+            );
+        }
+
+        if ($enabledNow) {
+            // 通知失敗で Job を失敗させない (applySetupCompletion と同型)。
+            try {
+                $this->notifyAutoEnabled($organization, $paymentMethodId);
+            } catch (Throwable $e) {
+                report($e);
+            }
+        }
+
+        return $enabledNow;
+    }
+
     /**
      * 有効な事前同意が待機中か (= PM が届けば自動有効化される状態。settingsFor の
      * pendingAutoEnable と同一定義の共通判定)。
@@ -1192,6 +1277,25 @@ private function hasRecentCompletedSetup(Organization $organization): bool
             ->exists();
     }
 
+    /**
+     * P9 (T1004): 「PM 流用 Job の収束待ち」の窓に入っているか。
+     *
+     * 基準は **`pm_reuse_dispatched_at`** (dispatch した事実の永続マーカー)。
+     * `updated_at` / `completed_at` は完了後の別更新・未決済 completed で窓が誤って開くため使わない。
+     */
+    private function hasRecentAutoRechargeFundedSignup(Organization $organization): bool
+    {
+        $windowMinutes = config()->integer('billing.auto_recharge.setup_pending_window_minutes');
+
+        return BillingCheckoutSession::query()
+            ->where('organization_id', $organization->getKey())
+            ->where('intent', CheckoutIntent::SubscriptionStart->value)
+            ->where('funding_choice', SignupFundingChoice::AutoRecharge->value)
+            ->where('status', CheckoutSessionStatus::Completed->value)
+            ->where('pm_reuse_dispatched_at', '>=', CarbonImmutable::now()->subMinutes($windowMinutes))
+            ->exists();
+    }
+
     private function defaultThreshold(): int
     {
         return config()->integer('billing.auto_recharge.default_threshold');
diff --git a/app/Services/Billing/BillingAccess.php b/app/Services/Billing/BillingAccess.php
index 7896276..72a8237 100644
--- a/app/Services/Billing/BillingAccess.php
+++ b/app/Services/Billing/BillingAccess.php
@@ -81,20 +81,22 @@ public function state(Organization $organization): OnboardingBillingState
             return OnboardingBillingState::ExpiredCheckout;
         }
 
-        $threshold = self::staleThresholdAt(CarbonImmutable::now());
+        // live/stale の判定は BillingCheckoutSession の述語だけが定義する (P9 C-1)。
+        // 閾値 literal をここに再発明しない (CheckoutLiveThresholdSingleSourceTest が機械検出)。
+        $now = CarbonImmutable::now();
         /** @var Collection<int, BillingCheckoutSession> $pendingRows */
         $pendingRows = BillingCheckoutSession::query()
             ->where('organization_id', $organization->id)
             ->where('status', CheckoutSessionStatus::Pending->value)
-            ->get(['id', 'created_at']);
+            ->get(['id', 'status', 'created_at']);
 
         $hasLivePending = false;
         $hasStalePending = false;
         foreach ($pendingRows as $row) {
-            if ($row->created_at !== null && $row->created_at->lessThan($threshold)) {
-                $hasStalePending = true;
-            } else {
+            if ($row->isLivePending($now)) {
                 $hasLivePending = true;
+            } else {
+                $hasStalePending = true;
             }
         }
 
@@ -112,15 +114,4 @@ public function state(Organization $organization): OnboardingBillingState
 
         return $hasExpired ? OnboardingBillingState::ExpiredCheckout : OnboardingBillingState::NoSubscription;
     }
-
-    /**
-     * pending checkout の stale 境界 (単一出典)。
-     *
-     * **live = `created_at >= staleThresholdAt($now)` / stale = `created_at < staleThresholdAt($now)`**
-     * の排他で統一する。sweeper (実 DB の expire) も本 helper を `<` で読むこと。
-     */
-    public static function staleThresholdAt(CarbonImmutable $now): CarbonImmutable
-    {
-        return $now->subDay();
-    }
 }
diff --git a/app/Services/Billing/BillingCustomerSynchronizer.php b/app/Services/Billing/BillingCustomerSynchronizer.php
index 37d3b52..254d839 100644
--- a/app/Services/Billing/BillingCustomerSynchronizer.php
+++ b/app/Services/Billing/BillingCustomerSynchronizer.php
@@ -10,7 +10,8 @@
 /**
  * Stripe customer 同期 job の dispatch を集約する単一窓口 (IV-2)。
  *
- * 同期を発火するのは `RenameOrganizationAction` のみ (請求先連絡先の更新経路は P9)。
+ * 同期を発火するのは `RenameOrganizationAction` (組織名) と `UpdateBillingContactAction`
+ * (請求先メール。宛名は Stripe へ送らない) のみ。
  * webhook ハンドラはこの経路を通らないため、Stripe→アプリ→Stripe の同期ループは構造的に発生しない。
  */
 final class BillingCustomerSynchronizer
diff --git a/app/Services/Billing/CashierAutoRechargeGateway.php b/app/Services/Billing/CashierAutoRechargeGateway.php
index cf48c01..838fbe3 100644
--- a/app/Services/Billing/CashierAutoRechargeGateway.php
+++ b/app/Services/Billing/CashierAutoRechargeGateway.php
@@ -15,6 +15,8 @@
 use Stripe\Exception\InvalidRequestException;
 use Stripe\Invoice;
 use Stripe\PaymentIntent;
+use Stripe\PaymentMethod as StripePaymentMethod;
+use Stripe\Subscription as StripeSubscription;
 use Webmozart\Assert\Assert;
 
 /**
@@ -228,7 +230,7 @@ public function resolveSetupIntentPaymentMethod(string $setupIntentId): string
         $setupIntent = Cashier::stripe()->setupIntents->retrieve($setupIntentId);
         $paymentMethod = $setupIntent->payment_method;
 
-        if ($paymentMethod instanceof \Stripe\PaymentMethod) {
+        if ($paymentMethod instanceof StripePaymentMethod) {
             return $paymentMethod->id;
         }
 
@@ -245,6 +247,61 @@ public function setDefaultPaymentMethod(Organization $organization, string $paym
         $organization->updateDefaultPaymentMethod($paymentMethodId);
     }
 
+    public function resolveSubscriptionPaymentMethod(string $stripeSubscriptionId): ?string
+    {
+        // basil API では Invoice に payment_intent が直載りしない (retrieveInvoiceState と同じ理由)。
+        // fallback は payments.data[].payment.payment_intent を expand して解決する。
+        $subscription = Cashier::stripe()->subscriptions->retrieve(
+            $stripeSubscriptionId,
+            ['expand' => ['latest_invoice.payments.data.payment.payment_intent']],
+        );
+
+        return self::resolvePaymentMethodFromSubscription($subscription);
+    }
+
+    /**
+     * P9 (T1004): Stripe Subscription オブジェクトから決済 PM を多段解決する純関数
+     * (テスト可能に分離 — `\Stripe\Subscription::constructFrom()` fixture で分岐を直接固定する)。
+     *
+     * @return non-empty-string|null
+     */
+    public static function resolvePaymentMethodFromSubscription(StripeSubscription $subscription): ?string
+    {
+        // 第一候補: subscription.default_payment_method
+        // (`payment_settings.save_default_payment_method='on_subscription'` で埋まる前提)。
+        $candidate = $subscription->default_payment_method;
+        if ($candidate instanceof StripePaymentMethod) {
+            $candidate = $candidate->id;
+        }
+        if (is_string($candidate) && $candidate !== '') {
+            return $candidate;
+        }
+
+        // 第二候補: latest_invoice の InvoicePayment 経由 (basil API — Invoice に payment_intent は
+        // 直載りしないため payments.data[].payment.payment_intent から辿る)。
+        $invoice = $subscription->latest_invoice;
+        $payments = $invoice instanceof Invoice ? $invoice->payments : null;
+        if ($payments !== null) {
+            foreach ($payments->data as $invoicePayment) {
+                $paymentIntent = $invoicePayment->payment->payment_intent ?? null;
+                if (! $paymentIntent instanceof PaymentIntent) {
+                    continue;
+                }
+
+                $fallback = $paymentIntent->payment_method;
+                if ($fallback instanceof StripePaymentMethod) {
+                    $fallback = $fallback->id;
+                }
+                // 空文字は null 扱い (PHPDoc の non-empty-string|null を runtime でも保証)。
+                if (is_string($fallback) && $fallback !== '') {
+                    return $fallback;
+                }
+            }
+        }
+
+        return null;
+    }
+
     /**
      * expanded InvoicePayment の PaymentIntent から SCA (requires_action) 待ちかを判定する。
      * webhook 到着順・local failure_code に依存しない判定源。
diff --git a/app/Services/Billing/CashierStripeGateway.php b/app/Services/Billing/CashierStripeGateway.php
index 14c9a2e..871e2ec 100644
--- a/app/Services/Billing/CashierStripeGateway.php
+++ b/app/Services/Billing/CashierStripeGateway.php
@@ -4,14 +4,16 @@
 
 namespace App\Services\Billing;
 
+use App\DataTransferObjects\Billing\CreatedCheckoutSession;
 use App\DataTransferObjects\Billing\ExternalBillingRedirect;
 use App\Models\Organization;
 use App\Services\Billing\Contracts\StripeGatewayInterface;
+use Carbon\CarbonImmutable;
+use Laravel\Cashier\Cashier;
 use Webmozart\Assert\Assert;
 
 /**
  * StripeGatewayInterface の Cashier (Stripe SDK) 実装。
- * ロジックは BillingController から移動 (挙動不変)。
  * PortalConfigurationSpec は同一名前空間 (App\Services\Billing) のため use 不要。
  */
 final class CashierStripeGateway implements StripeGatewayInterface
@@ -21,18 +23,97 @@ public function createSubscriptionCheckout(
         string $stripePriceId,
         string $successUrl,
         string $cancelUrl,
-    ): ExternalBillingRedirect {
-        $checkout = $organization
-            ->newSubscription('default', $stripePriceId)
-            ->checkout([
-                'success_url' => $successUrl,
-                'cancel_url' => $cancelUrl,
-            ]);
-
-        $url = $checkout->asStripeCheckoutSession()->url;
-        Assert::string($url, 'Checkout Session に URL がありません (ui_mode: hosted のみ対応)');
-
-        return new ExternalBillingRedirect($url);
+        array $metadata,
+        string $idempotencyKey,
+    ): CreatedCheckoutSession {
+        // Cashier の `newSubscription()->checkout()` は最終的に request options 無しで
+        // `checkout->sessions->create()` を呼ぶため per-request idempotency key を伝播できない。
+        // 冪等キーを Stripe Checkout 作成 API へ確実に渡すため SDK を直叩きする
+        // (CashierTicketCheckoutGateway と同型)。
+        $organization->createOrGetStripeCustomer();
+
+        $session = $organization->stripe()->checkout->sessions->create(
+            $this->buildSubscriptionSessionPayload($organization, $stripePriceId, $successUrl, $cancelUrl, $metadata),
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
+    public function expireCheckoutSession(string $stripeSessionId): string
+    {
+        // 決済主体は organization だが expire は session id 単独で完結する
+        // (呼び出し側が自 org 行の session id のみ渡す契約)
+        $session = Cashier::stripe()->checkout->sessions->expire($stripeSessionId);
+
+        return is_string($session->status) ? $session->status : 'expired';
+    }
+
+    /**
+     * subscription Checkout Session payload (pure)。
+     *
+     * invariant (gateway ユニットテストで固定):
+     * - `subscription_data.metadata.{name,type} = 'default'` — Cashier の WebhookController が
+     *   `subscriptions` 行を作る際に読むラベル。**落とすと課金成立なのに subscription 行が
+     *   作られず** `BillingAccess::state()` が NoSubscription に落ちて締め出しが起きる。
+     * - `subscription_data.payment_settings.save_default_payment_method = 'on_subscription'` —
+     *   T1004 の PM 流用の第一候補 (`subscription.default_payment_method`) が埋まる前提。
+     *
+     * @param  array<string, string>  $metadata
+     * @return array{
+     *   mode: 'subscription',
+     *   customer: string,
+     *   line_items: array{array{price: string, quantity: int}},
+     *   success_url: string,
+     *   cancel_url: string,
+     *   metadata: array<string, string>,
+     *   subscription_data: array{
+     *     metadata: array<string, string>,
+     *     payment_settings: array{save_default_payment_method: 'on_subscription'}
+     *   }
+     * }
+     */
+    public function buildSubscriptionSessionPayload(
+        Organization $organization,
+        string $stripePriceId,
+        string $successUrl,
+        string $cancelUrl,
+        array $metadata,
+    ): array {
+        // createOrGetStripeCustomer() 後は必ず存在する (欠落は設定異常として fail-fast)
+        $customerId = $organization->stripe_id;
+        Assert::stringNotEmpty($customerId, 'Stripe customer 未作成の組織では Checkout を作れません');
+
+        return [
+            'mode' => 'subscription',
+            'customer' => $customerId,
+            'line_items' => [
+                [
+                    'price' => $stripePriceId,
+                    'quantity' => 1,
+                ],
+            ],
+            'success_url' => $successUrl,
+            'cancel_url' => $cancelUrl,
+            'metadata' => $metadata,
+            'subscription_data' => [
+                'metadata' => [
+                    'name' => 'default',
+                    'type' => 'default',
+                ],
+                'payment_settings' => [
+                    'save_default_payment_method' => 'on_subscription',
+                ],
+            ],
+        ];
     }
 
     public function createPortalSession(Organization $organization, string $returnUrl): ExternalBillingRedirect
diff --git a/app/Services/Billing/Contracts/AutoRechargeGatewayInterface.php b/app/Services/Billing/Contracts/AutoRechargeGatewayInterface.php
index 55397f9..b322091 100644
--- a/app/Services/Billing/Contracts/AutoRechargeGatewayInterface.php
+++ b/app/Services/Billing/Contracts/AutoRechargeGatewayInterface.php
@@ -88,4 +88,14 @@ public function resolveSetupIntentPaymentMethod(string $setupIntentId): string;
      * 既 attach の PM は attach を skip する冪等実装。
      */
     public function setDefaultPaymentMethod(Organization $organization, string $paymentMethodId): void;
+
+    /**
+     * P9 (T1004): サブスクリプションの決済に使われた payment_method id を解決する。
+     *
+     * 解決順序: `subscription.default_payment_method` →
+     * `latest_invoice.payment_intent.payment_method`。双方 null なら null。空文字は返さない。
+     *
+     * @return non-empty-string|null
+     */
+    public function resolveSubscriptionPaymentMethod(string $stripeSubscriptionId): ?string;
 }
diff --git a/app/Services/Billing/Contracts/StripeGatewayInterface.php b/app/Services/Billing/Contracts/StripeGatewayInterface.php
index 23965e7..71f9bdf 100644
--- a/app/Services/Billing/Contracts/StripeGatewayInterface.php
+++ b/app/Services/Billing/Contracts/StripeGatewayInterface.php
@@ -4,6 +4,7 @@
 
 namespace App\Services\Billing\Contracts;
 
+use App\DataTransferObjects\Billing\CreatedCheckoutSession;
 use App\DataTransferObjects\Billing\ExternalBillingRedirect;
 use App\Models\Organization;
 
@@ -17,14 +18,29 @@
 interface StripeGatewayInterface
 {
     /**
-     * subscription (type=default) の hosted Checkout Session を作り遷移先を返す。
+     * subscription (type=default) の hosted Checkout Session を作り snapshot を返す。
+     *
+     * 戻り値に session id を含むのは **webhook 照合の pin** に必須のため
+     * (billing_checkout_sessions.stripe_session_id が真実源になる)。
+     * $idempotencyKey は Stripe へそのまま渡す (`sub_start:{attemptToken}`)。
+     *
+     * @param  array<string, string>  $metadata  照合専用 (認可・org 解決には使わない)
      */
     public function createSubscriptionCheckout(
         Organization $organization,
         string $stripePriceId,
         string $successUrl,
         string $cancelUrl,
-    ): ExternalBillingRedirect;
+        array $metadata,
+        string $idempotencyKey,
+    ): CreatedCheckoutSession;
+
+    /**
+     * Stripe 側 Checkout Session を expire する (別 plan の live pending 整理)。
+     *
+     * @return string expire 後の session status ('expired'|'complete'|...)
+     */
+    public function expireCheckoutSession(string $stripeSessionId): string;
 
     /**
      * Customer Portal セッションを作り遷移先を返す
diff --git a/app/Services/Billing/Fakes/FakeAutoRechargeGateway.php b/app/Services/Billing/Fakes/FakeAutoRechargeGateway.php
index 350ca5a..d04360a 100644
--- a/app/Services/Billing/Fakes/FakeAutoRechargeGateway.php
+++ b/app/Services/Billing/Fakes/FakeAutoRechargeGateway.php
@@ -77,4 +77,15 @@ public function setDefaultPaymentMethod(Organization $organization, string $paym
     {
         // no-op: fake 環境は Stripe customer を更新しない。
     }
+
+    public function resolveSubscriptionPaymentMethod(string $stripeSubscriptionId): ?string
+    {
+        // 既知 prefix (fake が発行する subscription id) にのみ対の PM id を返し、
+        // 未知の id は **null** (= 解決不能) にする。空文字は返さない。
+        if (! str_starts_with($stripeSubscriptionId, 'sub_bughuntfake_')) {
+            return null;
+        }
+
+        return 'pm_bughuntfake_'.substr(hash('sha256', $stripeSubscriptionId), 0, 20);
+    }
 }
diff --git a/app/Services/Billing/Fakes/FakeStripeGateway.php b/app/Services/Billing/Fakes/FakeStripeGateway.php
index db21192..7a12102 100644
--- a/app/Services/Billing/Fakes/FakeStripeGateway.php
+++ b/app/Services/Billing/Fakes/FakeStripeGateway.php
@@ -4,14 +4,19 @@
 
 namespace App\Services\Billing\Fakes;
 
+use App\DataTransferObjects\Billing\CreatedCheckoutSession;
 use App\DataTransferObjects\Billing\ExternalBillingRedirect;
 use App\Models\Organization;
 use App\Services\Billing\Contracts\StripeGatewayInterface;
+use Carbon\CarbonImmutable;
 
 /**
  * StripeGatewayInterface の runtime fake (fake_externals 環境専用)。
  * 契約は FakeTicketCheckoutGateway と同じ「中立帰還」。subscription 状態は変更しない
  * (active subscription の正本は BughuntBillingSeeder)。
+ *
+ * session id は **idempotency key から決定的に導出**する (Stripe の idempotency replay と
+ * 同じ収束特性 = 同一 key の再呼び出しで同一 sessionId)。
  */
 final class FakeStripeGateway implements StripeGatewayInterface
 {
@@ -20,8 +25,21 @@ public function createSubscriptionCheckout(
         string $stripePriceId,
         string $successUrl,
         string $cancelUrl,
-    ): ExternalBillingRedirect {
-        return new ExternalBillingRedirect(FakeExternalUrl::neutralReturn($cancelUrl));
+        array $metadata,
+        string $idempotencyKey,
+    ): CreatedCheckoutSession {
+        $token = substr(hash('sha256', $idempotencyKey), 0, 32);
+
+        return new CreatedCheckoutSession(
+            sessionId: "cs_bughuntfake_{$token}",
+            url: FakeExternalUrl::neutralReturn($cancelUrl),
+            expiresAt: CarbonImmutable::now()->addDay(), // Stripe hosted checkout の既定 24h
+        );
+    }
+
+    public function expireCheckoutSession(string $stripeSessionId): string
+    {
+        return 'expired';
     }
 
     public function createPortalSession(Organization $organization, string $returnUrl): ExternalBillingRedirect
diff --git a/app/Services/Billing/StripeWebhookProcessor.php b/app/Services/Billing/StripeWebhookProcessor.php
index 974ec17..63c4787 100644
--- a/app/Services/Billing/StripeWebhookProcessor.php
+++ b/app/Services/Billing/StripeWebhookProcessor.php
@@ -6,11 +6,13 @@
 
 use App\Enums\Billing\BillingNotificationType;
 use App\Enums\Billing\HandledStripeWebhookEvent;
+use App\Enums\Billing\SignupFundingChoice;
 use App\Enums\Billing\TicketCheckoutSessionStatus;
 use App\Enums\Billing\WebhookEventStatus;
 use App\Enums\CheckoutIntent;
 use App\Enums\CheckoutSessionStatus;
 use App\Jobs\Billing\HandleAutoRechargeChargeFailureJob;
+use App\Jobs\Billing\ReuseSubscriptionPaymentMethodJob;
 use App\Jobs\Billing\SetDefaultPaymentMethodJob;
 use App\Models\Billing\BillingCheckoutSession;
 use App\Models\Billing\Plan;
@@ -528,9 +530,120 @@ private function handleCheckoutSessionCompleted(array $payload): void
             return;
         }
 
+        // P9: サブスク契約 Checkout (mode=subscription / purpose=subscription_start) の着地。
+        // 真実源は自 DB 行 (billing_checkout_sessions の intent=subscription_start)。
+        // 金銭の付与経路には一切触らない (付与は invoice.paid / plan_code 同期は
+        // customer.subscription.* が真実源)。
+        if ($this->stringAt($payload, 'data.object.metadata.purpose') === 'subscription_start') {
+            $this->settleSubscriptionCheckout($payload);
+
+            return;
+        }
+
         $this->grantPurchasedTickets($payload);
     }
 
+    /**
+     * P9 (C-2): サブスク契約 Checkout の状態確定。**遷移条件はこの 1 定義のみ**。
+     *
+     * `status !== Completed` の行だけを payload の `payment_status` が確定した結果へ遷移させる。
+     * `Completed` は終局 (再送・後続 payload は no-op = 冪等)。
+     *   - paid / no_payment_required → Completed (+ completed_at)
+     *   - unpaid                     → Failed
+     *   - 上記以外 (null 等)         → 遷移しない (受理のみ)
+     *
+     * `Failed` / `Expired` からの遅延成功も受理する: これらは AI-CUE 側の都合で付く
+     * **ローカルな見立て** (日次 sweeper が全 stale pending を Expired にする) であり、
+     * 決済の終局は Stripe が持つ。金銭の付与は invoice.paid が真実源のため台帳は動かない。
+     *
+     * @param  array<mixed>  $payload
+     */
+    private function settleSubscriptionCheckout(array $payload): void
+    {
+        // (1) purpose ガード (呼び出し元で済) + mode ガード: mode≠subscription は受理のみ
+        //     (既存 grantPurchasedTickets の mode=payment / P8a の mode=setup と相互排他)。
+        if ($this->stringAt($payload, 'data.object.mode') !== 'subscription') {
+            return;
+        }
+
+        $sessionId = $this->stringAt($payload, 'data.object.id');
+        if ($sessionId === null) {
+            throw new RuntimeException('checkout.session.completed: session id 欠落 (subscription_start)');
+        }
+
+        // (2) 真実源は自 DB 行。行不在は retryable failure (crash 先着 webhook は Stripe の
+        //     再送で本経路に収束する)。
+        $local = BillingCheckoutSession::query()
+            ->where('stripe_session_id', $sessionId)
+            ->where('intent', CheckoutIntent::SubscriptionStart->value)
+            ->first();
+        if ($local === null) {
+            throw new RuntimeException("subscription checkout webhook: 未追跡 session {$sessionId} (DB 行なし、再送待ち)");
+        }
+
+        $organization = $local->organization;
+        Assert::isInstanceOf($organization, Organization::class);
+
+        // (3) tenant キー不信: payload の customer / metadata.org_ref は照合のみ (org 解決には使わない)
+        $customerId = $this->stringAt($payload, 'data.object.customer');
+        if ($customerId === null || $organization->stripe_id !== $customerId) {
+            throw new RuntimeException("subscription checkout webhook: customer 照合不一致 (session {$sessionId})");
+        }
+        $metaOrgRef = $this->stringAt($payload, 'data.object.metadata.org_ref');
+        if ($metaOrgRef !== (string) $organization->id) {
+            throw new RuntimeException("subscription checkout webhook: metadata org_ref 照合不一致 (session {$sessionId})");
+        }
+
+        // (4) 遷移 (C-2 の 1 定義)
+        if ($local->status === CheckoutSessionStatus::Completed->value) {
+            return; // 終局 no-op (冪等)
+        }
+
+        $paymentStatus = $this->stringAt($payload, 'data.object.payment_status');
+        if (in_array($paymentStatus, ['paid', 'no_payment_required'], true)) {
+            $local->forceFill([
+                'status' => CheckoutSessionStatus::Completed->value,
+                'completed_at' => CarbonImmutable::now(),
+            ])->save();
+        } elseif ($paymentStatus === 'unpaid') {
+            $local->forceFill(['status' => CheckoutSessionStatus::Failed->value])->save();
+
+            return;
+        } else {
+            return; // 未知値 / 欠落は遷移しない (受理のみ = fail-closed)
+        }
+
+        // (5) T1004: 決済確定 + funding=auto_recharge のときだけ PM 流用 Job を dispatch する。
+        //     dispatch の事実を session に永続化する — setupPending / 着地 flash の
+        //     「自動的に有効になります」表示を決済確定済みの契約に限定する出典
+        //     (未決済 completed への伝播防止)。再送は (4) の終局 no-op で到達しない。
+        $subscriptionId = $this->subscriptionIdFrom($payload);
+        if ($local->funding_choice === SignupFundingChoice::AutoRecharge->value && $subscriptionId !== null) {
+            $local->forceFill(['pm_reuse_dispatched_at' => CarbonImmutable::now()])->save();
+            ReuseSubscriptionPaymentMethodJob::dispatch($local->organization_id, $subscriptionId);
+        }
+    }
+
+    /**
+     * `checkout.session.completed` の `data.object.subscription` から subscription id を取る。
+     *
+     * **string と array{id} の両方を受理する**: 当該フィールドは expandable で、
+     * expand 指定の無い通常の payload では **string ID** (`"sub_xxx"`) で来る。
+     * array を前提にすると本番で Job が一度も dispatch されない。
+     * それ以外の型 / 空文字は null (fail-closed = dispatch しない)。
+     *
+     * @param  array<mixed>  $payload
+     */
+    private function subscriptionIdFrom(array $payload): ?string
+    {
+        $value = data_get($payload, 'data.object.subscription');
+        if (is_array($value)) {
+            $value = $value['id'] ?? null;
+        }
+
+        return is_string($value) && $value !== '' ? $value : null;
+    }
+
     /**
      * P8a: mode=setup Checkout の完了。台帳行を completed 化し、PM の default 設定 +
      * 事前同意の自動有効化を Job へ退避する (外向き Stripe API は webhook 同期処理で叩かない)。
diff --git a/app/Services/Billing/SubscriptionService.php b/app/Services/Billing/SubscriptionService.php
index 99a340b..a9d41f7 100644
--- a/app/Services/Billing/SubscriptionService.php
+++ b/app/Services/Billing/SubscriptionService.php
@@ -4,22 +4,38 @@
 
 namespace App\Services\Billing;
 
+use App\DataTransferObjects\Billing\CheckoutSessionDto;
 use App\DataTransferObjects\Billing\ExternalBillingRedirect;
 use App\DataTransferObjects\Billing\SubscriptionEntitlementDto;
 use App\Enums\Billing\EntitlementDeniedReason;
 use App\Enums\Billing\PlanPriceKind;
 use App\Enums\Billing\ScheduleSetupStatus;
+use App\Enums\Billing\SignupFundingChoice;
 use App\Enums\Billing\SubscriptionState;
+use App\Enums\CheckoutIntent;
+use App\Enums\CheckoutSessionStatus;
 use App\Enums\PlanCode;
+use App\Exceptions\Billing\CheckoutInProgressException;
+use App\Exceptions\Billing\StaleCheckoutAttemptException;
 use App\Exceptions\Billing\StripePriceNotSyncedException;
+use App\Exceptions\Billing\SubscriptionAttemptPlanMismatchException;
+use App\Models\Billing\BillingCheckoutSession;
 use App\Models\Billing\Plan;
 use App\Models\Billing\PlanPrice;
 use App\Models\Billing\Subscription;
 use App\Models\Organization;
+use App\Models\User;
 use App\Services\Billing\Contracts\StripeGatewayInterface;
 use Carbon\CarbonImmutable;
+use Illuminate\Contracts\Cache\LockTimeoutException;
+use Illuminate\Database\Eloquent\Builder;
+use Illuminate\Database\QueryException;
+use Illuminate\Database\UniqueConstraintViolationException;
+use Illuminate\Support\Facades\Cache;
 use Illuminate\Support\Facades\DB;
+use Illuminate\Support\Facades\Log;
 use Illuminate\Validation\ValidationException;
+use Throwable;
 use Webmozart\Assert\Assert;
 
 /**
@@ -224,42 +240,333 @@ public function recordPaymentMethodSnapshot(Subscription $sub, bool $hasPaymentM
     }
 
     /**
-     * Stripe Checkout (サブスク契約) を開始し、遷移先 (hosted Checkout URL) を返す。
+     * P9: Stripe Checkout (サブスク契約) を **冪等状態機械** として開始する。
      *
-     * checkout session の冪等状態機械 (attempt token / billing_checkout_sessions) は
-     * 本フェーズのスコープ外 (後続フェーズで本メソッドに配線する)。
+     * クエリは常に `intent=subscription_start` でスコープする (`UNIQUE(organization_id,
+     * intent, attempt_token)` の intent 軸が P8a のカード登録 token 空間と分ける)。
+     * live 判定は `BillingCheckoutSession` の述語 (C-1) だけを使い、独自の日付比較を書かない。
      *
+     * 段 0: 事前 assert + 基準時刻 / 段 1: 既存 subscription guard /
+     * 段 2: 同 token 行 (別 plan → 422 / replayable → 再生 / それ以外 → stale) /
+     * 段 3: 同 plan の live pending dedup (org-wide) / 段 4: 別 plan の live pending を expire /
+     * 段 5: Stripe 作成 → DB 記録 / 段 6: UNIQUE 違反の re-read 収束 (500 にしない)。
+     *
+     * @param  SignupFundingChoice|null  $funding  T1004: 行の funding_choice に記録する
+     *                                             (null = 従来の契約 checkout = PM 流用しない)
+     *
+     * @throws SubscriptionAttemptPlanMismatchException 同 token・別 plan の再送 (Controller が 422)
+     * @throws StaleCheckoutAttemptException 期限切れ / 終端済み token の再送
+     * @throws CheckoutInProgressException lock 競合 / 別 plan session の整理失敗 / 決済処理中
      * @throws StripePriceNotSyncedException production runtime で未 sync の Price のとき
      * @throws ValidationException Stripe 決済対象外のプランのとき (422)
      * @throws \InvalidArgumentException 既に有効なサブスクリプションがあるとき
      */
     public function startCheckout(
         Organization $org,
-        PlanPrice $basePrice,
+        User $user,
+        Plan $plan,
         string $successUrl,
         string $cancelUrl,
-    ): ExternalBillingRedirect {
-        // production runtime guard
+        string $attemptToken,
+        ?SignupFundingChoice $funding,
+    ): CheckoutSessionDto {
+        // 段 0: 事前 assert (lock を取る前に確定できる guard は先に倒す)
+        Assert::stringNotEmpty($attemptToken, '契約手続きトークンが不正です');
+        $this->assertCheckoutReady($org);
+
+        $basePrice = $plan->currentPrice(PlanPriceKind::Base);
+        Assert::isInstanceOf($basePrice, PlanPrice::class, '基本 Price 未設定のプランです');
         $this->assertPriceSynced($basePrice);
-
-        $plan = $basePrice->plan;
-        Assert::isInstanceOf($plan, Plan::class);
         $this->assertStripeBillablePlan($plan);
 
+        try {
+            $result = Cache::lock("billing:checkout:start:{$org->id}", 10)->block(
+                5,
+                fn (): CheckoutSessionDto => $this->startCheckoutLocked(
+                    $org, $user, $plan, $basePrice, $successUrl, $cancelUrl, $attemptToken, $funding,
+                ),
+            );
+            // Cache::lock()->block() は mixed を返すため型を絞る (TicketCheckoutService と同型)。
+            Assert::isInstanceOf($result, CheckoutSessionDto::class);
+
+            return $result;
+        } catch (LockTimeoutException $e) {
+            // fail-closed: ロックなし実行へフォールバックしない (二重 subscription を作らない)
+            throw new CheckoutInProgressException('直前の操作が進行中です。数秒お待ちください。', previous: $e);
+        }
+    }
+
+    /**
+     * 要件 7: (org, user) スコープ外に同 token 行が在るか。
+     * true なら Controller が **Gate より前に 404** を返す (存在オラクル封じ)。
+     */
+    public function attemptTokenIsForeign(string $attemptToken, Organization $org, User $user): bool
+    {
+        if ($attemptToken === '') {
+            return false;
+        }
+
+        return BillingCheckoutSession::query()
+            ->where('intent', CheckoutIntent::SubscriptionStart->value)
+            ->where('attempt_token', $attemptToken)
+            ->where(function (Builder $q) use ($org, $user): void {
+                /** @var Builder<BillingCheckoutSession> $q */
+                $q->where('organization_id', '!=', $org->getKey())
+                    ->orWhereNull('initiated_by_user_id')
+                    ->orWhere('initiated_by_user_id', '!=', $user->getKey());
+            })
+            ->exists();
+    }
+
+    /**
+     * 指定 session id の自 org 行が Completed か (Controller の `?replayed=1` 分岐の判定源)。
+     */
+    public function isAttemptCompleted(Organization $org, string $stripeSessionId): bool
+    {
+        return BillingCheckoutSession::query()
+            ->where('organization_id', $org->getKey())
+            ->where('intent', CheckoutIntent::SubscriptionStart->value)
+            ->where('stripe_session_id', $stripeSessionId)
+            ->where('status', CheckoutSessionStatus::Completed->value)
+            ->exists();
+    }
+
+    private function startCheckoutLocked(
+        Organization $org,
+        User $user,
+        Plan $plan,
+        PlanPrice $basePrice,
+        string $successUrl,
+        string $cancelUrl,
+        string $attemptToken,
+        ?SignupFundingChoice $funding,
+    ): CheckoutSessionDto {
+        // lock closure 先頭で基準時刻を 1 回だけ取り、段 2/3/4 の live 判定を共有述語へ通す (C-1)。
+        $now = CarbonImmutable::now();
+        $threshold = BillingCheckoutSession::staleThresholdAt($now);
+
+        // 段 1: 既存 subscription guard
         $existing = $org->subscription('default');
         Assert::true(
             ! $existing instanceof Subscription || ! $existing->valid(),
             '既に有効なサブスクリプションがあります。プラン変更をご利用ください。'
         );
 
-        return $this->gateway->createSubscriptionCheckout(
+        // 段 2: 同 token 行 (intent=subscription_start スコープ)
+        $sameAttempt = $this->subscriptionAttemptQuery($org)
+            ->where('attempt_token', $attemptToken)
+            ->latest('id')
+            ->first();
+
+        if ($sameAttempt instanceof BillingCheckoutSession) {
+            // 要件 6 (N-1): plan 不一致は replay より **前** に判定する。
+            if ($sameAttempt->plan_code !== $plan->code) {
+                throw new SubscriptionAttemptPlanMismatchException(
+                    'お手続きの内容が変わりました。画面を再読み込みして選び直してください。',
+                );
+            }
+            if ($this->isReplayableCheckout($sameAttempt, $now)) {
+                return $this->replayCheckout($sameAttempt);
+            }
+
+            throw new StaleCheckoutAttemptException(
+                '契約手続きの有効期限が切れました。画面を再読み込みして再試行してください。',
+            );
+        }
+
+        // 段 3: 同 plan の live pending dedup (**org-wide**。subscription は org 単位の singleton
+        // であり、actor scope にすると同 org の 2 人が同時に live Checkout を持てて二重契約を許す)。
+        $pending = $this->subscriptionAttemptQuery($org)
+            ->where('plan_code', $plan->code)
+            ->where('status', CheckoutSessionStatus::Pending->value)
+            ->where('created_at', '>=', $threshold)
+            ->latest('id')
+            ->first();
+
+        if ($pending instanceof BillingCheckoutSession) {
+            return new CheckoutSessionDto(
+                stripeSessionId: $pending->stripe_session_id,
+                url: null,
+                intent: CheckoutIntent::SubscriptionStart->value,
+                planCode: $plan->code,
+            );
+        }
+
+        // 段 4: 別 plan の live pending を expire する (stale な別 plan 行は Stripe 側で既に
+        // expire 済みのため照会せず放置する = 無駄な外部 API を撃たない)。
+        $otherPlanPending = $this->subscriptionAttemptQuery($org)
+            ->where('status', CheckoutSessionStatus::Pending->value)
+            ->where('created_at', '>=', $threshold)
+            ->where(function (Builder $q) use ($plan): void {
+                /** @var Builder<BillingCheckoutSession> $q */
+                $q->whereNull('plan_code')->orWhere('plan_code', '!=', $plan->code);
+            })
+            ->get();
+
+        foreach ($otherPlanPending as $row) {
+            // Stripe 側 expire 失敗時は local を上書きせず停止する (remote session が open のまま
+            // 新規 Checkout を作ると別 plan で二重完了しうる)。
+            try {
+                $expireResult = $this->gateway->expireCheckoutSession($row->stripe_session_id);
+            } catch (Throwable $e) {
+                Log::warning('startCheckout: failed to expire old pending, stopping', [
+                    'organization_id' => $org->getKey(),
+                    'stripe_session_id' => $row->stripe_session_id,
+                ]);
+
+                throw new CheckoutInProgressException(
+                    '前回の決済セッションの整理に失敗しました。 数分後に再試行してください。',
+                    previous: $e,
+                );
+            }
+
+            if ($expireResult === 'complete') {
+                // 決済完了済 (= webhook 未着)。新規 Checkout を作らず caller に通知する。
+                throw new CheckoutInProgressException('直前の決済が処理中です。数分お待ちください。');
+            }
+
+            $row->status = CheckoutSessionStatus::Expired->value;
+            $row->save();
+        }
+
+        // 段 5: Stripe 作成 → DB 記録。metadata は照合専用 (認可・org 解決には使わない)。
+        $created = $this->gateway->createSubscriptionCheckout(
             $org,
             $basePrice->stripe_price_id,
             $successUrl,
             $cancelUrl,
+            [
+                'purpose' => 'subscription_start',
+                'org_ref' => (string) $org->id,
+                'plan_code' => $plan->code,
+            ],
+            'sub_start:'.$attemptToken,
+        );
+
+        try {
+            // 失敗 INSERT が PostgreSQL で外側 transaction を abort させないよう savepoint で囲む。
+            DB::transaction(function () use ($org, $user, $plan, $created, $attemptToken, $funding): void {
+                $session = new BillingCheckoutSession;
+                // tenant / actor キーは relation / 明示代入 (mass assignment しない)
+                $session->organization()->associate($org);
+                $session->initiated_by_user_id = $user->id;
+                $session->fill([
+                    'intent' => CheckoutIntent::SubscriptionStart->value,
+                    'plan_code' => $plan->code,
+                    'funding_choice' => $funding?->value,
+                    'stripe_session_id' => $created->sessionId,
+                    'idempotency_key' => 'sub_start:'.$attemptToken,
+                    'attempt_token' => $attemptToken,
+                    'checkout_url' => $created->url,
+                    'status' => CheckoutSessionStatus::Pending->value,
+                ]);
+                $session->save();
+            });
+        } catch (UniqueConstraintViolationException $e) {
+            // 段 6: 並行 race。unique(org, intent, attempt_token) 違反 → 既存を再読込して収束する
+            // (attempt_token 以外の unique 違反は rethrow = 500 に落として調査対象にする)。
+            if (! $this->isUniqueViolation($e)) {
+                throw $e;
+            }
+
+            $row = $this->subscriptionAttemptQuery($org)
+                ->where('attempt_token', $attemptToken)
+                ->latest('id')
+                ->first();
+
+            if ($row instanceof BillingCheckoutSession && $this->isReplayableCheckout($row, CarbonImmutable::now())) {
+                return $this->replayCheckout($row);
+            }
+
+            throw new StaleCheckoutAttemptException(
+                '契約手続きの有効期限が切れました。画面を再読み込みして再試行してください。',
+            );
+        }
+
+        return new CheckoutSessionDto(
+            stripeSessionId: $created->sessionId,
+            url: $created->url,
+            intent: CheckoutIntent::SubscriptionStart->value,
+            planCode: $plan->code,
         );
     }
 
+    /**
+     * `intent=subscription_start` に pin した org スコープのクエリ
+     * (P8a の `setup_payment_method` 行を段 2/3/4 に混入させない唯一の出典)。
+     *
+     * @return Builder<BillingCheckoutSession>
+     */
+    private function subscriptionAttemptQuery(Organization $org): Builder
+    {
+        return BillingCheckoutSession::query()
+            ->where('organization_id', $org->getKey())
+            ->where('intent', CheckoutIntent::SubscriptionStart->value);
+    }
+
+    /**
+     * 同 attempt_token の既存 session が冪等再生可能か。
+     * **stale pending は replay しない** (死んだ checkout_url へ収束させない = C-1)。
+     */
+    private function isReplayableCheckout(BillingCheckoutSession $session, CarbonImmutable $now): bool
+    {
+        if ($session->status === CheckoutSessionStatus::Completed->value) {
+            return true;
+        }
+
+        return $session->isReplayablePending($now);
+    }
+
+    /**
+     * replayable な既存 session を冪等再生する。
+     *  - Pending → 同じ checkout_url に戻す
+     *  - Completed → url=null (Controller が「受付済み」フィードバックを出す)
+     */
+    private function replayCheckout(BillingCheckoutSession $session): CheckoutSessionDto
+    {
+        $url = $session->status === CheckoutSessionStatus::Pending->value
+            ? $session->checkout_url
+            : null;
+
+        return new CheckoutSessionDto(
+            stripeSessionId: $session->stripe_session_id,
+            url: $url,
+            intent: CheckoutIntent::SubscriptionStart->value,
+            planCode: $session->plan_code,
+        );
+    }
+
+    /**
+     * QueryException が attempt_token unique 制約違反か判定する (driver 差を吸収)。
+     *
+     * SQLSTATE は driver で異なる (MySQL/SQLite=23000, PostgreSQL=23505) ため両方許容し、
+     * 識別子で attempt_token unique 違反だけを拾う (他制約を replay 分岐へ誤って流さない)。
+     * MySQL/PostgreSQL は index 名、SQLite は構成列名で一致を見る。
+     */
+    private function isUniqueViolation(QueryException $e): bool
+    {
+        if (! in_array($e->getCode(), ['23000', '23505'], true)) {
+            return false;
+        }
+
+        $message = $e->getMessage();
+
+        return str_contains($message, 'billing_checkout_sessions_org_intent_attempt_unique')
+            || (str_contains($message, 'billing_checkout_sessions.organization_id')
+                && str_contains($message, 'attempt_token'));
+    }
+
+    /**
+     * 契約開始前の事前検証: 請求先メールが解決できること
+     * (billing_contact_email 正本 → owner email fallback)。
+     */
+    public function assertCheckoutReady(Organization $org): void
+    {
+        $email = $org->billingContactEmail();
+        Assert::stringNotEmpty($email, '請求先メールが未設定です');
+        Assert::regex($email, '/^[^@\s]+@[^@\s]+\.[^@\s]+$/', '請求先メールの形式が不正です');
+    }
+
     /** Stripe Customer Portal セッション (支払い方法・解約の自己管理) の遷移先を返す。 */
     public function createPortalSession(Organization $org, string $returnUrl): ExternalBillingRedirect
     {
diff --git a/config/billing.php b/config/billing.php
index 9060b97..153a9cc 100644
--- a/config/billing.php
+++ b/config/billing.php
@@ -59,12 +59,12 @@
     |
     | 同意文言バージョン (consent_version) の改定履歴:
     |   v1 = 初版 (カード登録経路のみ = mode=setup Checkout で登録したカードを使う)
+    |   v2 = P9 / T1004: 有償契約でサブスク決済カードをオートリチャージへ流用することを明示
     |
     | 提示条件の実質 (開始残高・補充枚数・上限額の提示形式・停止方法・即時課金可能性・
     | **カードの取得手段**) を変える改定では**必ず version を上げること**。
     | 版を上げると reconsentRequiredFor 経由で既存同意が自動失効し、再同意まで
-    | 自動購入が停止する (fail-closed)。
-    | サブスク決済カードの流用 (P9 / T1004) を配線する際は v2 へ上げる。
+    | 自動購入が停止する (fail-closed)。**救済 backfill は書かない** (版の意味が無効化されるため)。
     */
     'auto_recharge' => [
         /* 残高がこの枚数を下回ると補充する (既定値。org ごとに設定で上書き) */
@@ -89,7 +89,7 @@
         'setup_pending_window_minutes' => (int) env('BILLING_AUTO_RECHARGE_SETUP_PENDING_WINDOW_MINUTES', 30),
 
         /* 現行の同意文言バージョン (上記の改定規約に従う) */
-        'consent_version' => env('BILLING_AUTO_RECHARGE_CONSENT_VERSION', 'v1'),
+        'consent_version' => env('BILLING_AUTO_RECHARGE_CONSENT_VERSION', 'v2'),
     ],
 
 ];
diff --git a/database/factories/Billing/BillingCheckoutSessionFactory.php b/database/factories/Billing/BillingCheckoutSessionFactory.php
index db8a93e..3ff16c1 100644
--- a/database/factories/Billing/BillingCheckoutSessionFactory.php
+++ b/database/factories/Billing/BillingCheckoutSessionFactory.php
@@ -4,6 +4,7 @@
 
 namespace Database\Factories\Billing;
 
+use App\Enums\Billing\SignupFundingChoice;
 use App\Enums\CheckoutIntent;
 use App\Enums\CheckoutSessionStatus;
 use App\Models\Billing\BillingCheckoutSession;
@@ -103,4 +104,31 @@ public function stale(): static
             'created_at' => CarbonImmutable::now()->subDays(2),
         ]);
     }
+
+    /** P9: 契約 attempt 単位の冪等 token + plan を同時に固定する。 */
+    public function withAttempt(string $token, string $planCode = 'starter'): static
+    {
+        return $this->state(fn (): array => [
+            'attempt_token' => $token,
+            'plan_code' => $planCode,
+            'checkout_url' => 'https://checkout.stripe.com/dummy',
+            'idempotency_key' => 'sub_start:'.$token,
+        ]);
+    }
+
+    /** T1004: オンボーディングの資金選択で「オートリチャージ」を選んだ契約行。 */
+    public function fundingAutoRecharge(): static
+    {
+        return $this->state(fn (): array => [
+            'funding_choice' => SignupFundingChoice::AutoRecharge->value,
+        ]);
+    }
+
+    /** T1004: PM 流用 Job を dispatch 済みのマーカーを立てる (「処理中」窓の基準)。 */
+    public function pmReuseDispatched(?CarbonImmutable $at = null): static
+    {
+        return $this->state(fn (): array => [
+            'pm_reuse_dispatched_at' => $at ?? CarbonImmutable::now(),
+        ]);
+    }
 }
diff --git a/database/factories/OrganizationFactory.php b/database/factories/OrganizationFactory.php
index 2a201ee..bb5cce8 100644
--- a/database/factories/OrganizationFactory.php
+++ b/database/factories/OrganizationFactory.php
@@ -86,6 +86,20 @@ public function grandfathered(): static
         ]);
     }
 
+    /**
+     * P9: 請求先連絡先を設定済みの組織。
+     *
+     * 両列とも $fillable 外 (PII) だが Factory の state は forceFill 相当で通る。
+     * email は保存時と同じ正規化 (小文字化) を通す — blind index の検索契約と揃える。
+     */
+    public function withBillingContact(?string $email = null, ?string $name = null): static
+    {
+        return $this->state(fn (): array => [
+            'billing_contact_email' => Str::lower(trim($email ?? fake()->unique()->safeEmail())),
+            'billing_contact_name' => $name,
+        ]);
+    }
+
     /** 初回無償チケット付与済み (org 単位 1 回マーカーが立っている) 組織 */
     public function signupGranted(): static
     {
diff --git a/database/migrations/2026_07_17_000300_add_signup_funding_to_billing_checkout_sessions.php b/database/migrations/2026_07_17_000300_add_signup_funding_to_billing_checkout_sessions.php
new file mode 100644
index 0000000..24fd8f8
--- /dev/null
+++ b/database/migrations/2026_07_17_000300_add_signup_funding_to_billing_checkout_sessions.php
@@ -0,0 +1,39 @@
+<?php
+
+declare(strict_types=1);
+
+use Illuminate\Database\Migrations\Migration;
+use Illuminate\Database\Schema\Blueprint;
+use Illuminate\Support\Facades\Schema;
+
+/**
+ * P9 (T081 / T1004): サブスク契約 Checkout に「資金選択」と「PM 流用 Job dispatch marker」を足す。
+ *
+ * - funding_choice: Onboarding/Checkout の資金 2 択 (SignupFundingChoice の値)。
+ *   `auto_recharge` の有償契約だけが T1004 (サブスク決済カードのオートリチャージ流用) の対象。
+ *   Plans 経路 (契約変更) は funding 提示が無いため null。
+ * - pm_reuse_dispatched_at: ReuseSubscriptionPaymentMethodJob を dispatch した事実の永続マーカー。
+ *   決済確定 (payment_status ∈ {paid, no_payment_required}) の completed でのみ立つため、
+ *   「オートリチャージが自動的に有効になります」表示の唯一の出典になる
+ *   (updated_at / completed_at は未決済 completed で窓が誤って開くため使わない)。
+ *   webhook の forceFill 専用 marker のため $fillable には入れない。
+ *
+ * P2 所管テーブルへの **additive 列追加のみ** (既存列・index・UNIQUE は触らない)。
+ */
+return new class extends Migration
+{
+    public function up(): void
+    {
+        Schema::table('billing_checkout_sessions', function (Blueprint $table): void {
+            $table->string('funding_choice', 16)->nullable()->after('plan_code');
+            $table->timestamp('pm_reuse_dispatched_at')->nullable()->after('completed_at');
+        });
+    }
+
+    public function down(): void
+    {
+        Schema::table('billing_checkout_sessions', function (Blueprint $table): void {
+            $table->dropColumn(['funding_choice', 'pm_reuse_dispatched_at']);
+        });
+    }
+};
diff --git a/database/migrations/2026_07_17_000400_add_billing_contact_columns_to_organizations_table.php b/database/migrations/2026_07_17_000400_add_billing_contact_columns_to_organizations_table.php
new file mode 100644
index 0000000..6851d26
--- /dev/null
+++ b/database/migrations/2026_07_17_000400_add_billing_contact_columns_to_organizations_table.php
@@ -0,0 +1,36 @@
+<?php
+
+declare(strict_types=1);
+
+use Illuminate\Database\Migrations\Migration;
+use Illuminate\Database\Schema\Blueprint;
+use Illuminate\Support\Facades\Schema;
+
+/**
+ * P9 (T081): 請求先連絡先 (メール / 宛名)。
+ *
+ * 両列とも **CipherSweet の ciphertext** を格納するため `text()` を使う
+ * (暗号文は元値より長くなるため string(255) では溢れる)。
+ * blind index 用の列は作らない — spatie/laravel-ciphersweet の共有 `blind_indexes`
+ * morph テーブルに入る (`Organization::configureCipherSweet()` 参照)。
+ *
+ * 一意制約は張らない (複数組織が同一請求先メールを持つのは正当)。
+ * NOT NULL 化・backfill も行わない (未設定時は owner email へ fallback する)。
+ */
+return new class extends Migration
+{
+    public function up(): void
+    {
+        Schema::table('organizations', function (Blueprint $table): void {
+            $table->text('billing_contact_email')->nullable()->after('slug');
+            $table->text('billing_contact_name')->nullable()->after('billing_contact_email');
+        });
+    }
+
+    public function down(): void
+    {
+        Schema::table('organizations', function (Blueprint $table): void {
+            $table->dropColumn(['billing_contact_email', 'billing_contact_name']);
+        });
+    }
+};
diff --git a/docs/architecture.md b/docs/architecture.md
index 3a392ac..7f81107 100644
--- a/docs/architecture.md
+++ b/docs/architecture.md
@@ -67,7 +67,7 @@ ## ドメインモデル (テンプレート同梱)
 |---|---|---|
 | `User` | エンドユーザー。PII (email/name) は CipherSweet 暗号化 | 複数 Organization に所属 |
 | `AdminUser` | 運営管理者 (Filament 専用 guard)。エンドユーザーと別テーブル | tenant 外 |
-| `Organization` | テナント境界。課金・quota・API キーの単位 | ルート |
+| `Organization` | テナント境界。課金・quota・API キーの単位。請求先連絡先 (`billing_contact_email` / `billing_contact_name`) は PII のため CipherSweet 暗号化 (email のみ blind index。検索は `whereBlind`) | ルート |
 | `Team` (laratrust) | Laratrust のロールスコープ。Organization と 1:1 | Organization 従属 |
 | `CustomTeam` | 組織内のチーム。各組織に Default Team がちょうど 1 つ | Organization 従属 |
 | `Project` | 作業単位。CustomTeam (通常は Default Team) 配下 | Organization → CustomTeam 従属 |
@@ -97,7 +97,7 @@ ## ドメインモデル (テンプレート同梱)
 | `Billing/TicketLedgerEntry` / `Billing/TicketReservation` | チケット台帳 (reserve→commit/release の 2 フェーズ。期限付き付与・idempotency_key 冪等付与・返金 clawback) | Organization 従属 |
 | `Billing/TicketVolumePrice` | スポット購入の数量逐減 (volume tier) 単価の Stripe Price snapshot | tenant 外 (マスタ) |
 | `Billing/TicketCheckoutSession` | チケットスポット購入の Stripe Checkout Session 追跡 (attempt_token 冪等 + 単価 pin = webhook 金額照合の出典。status: pending/completed/expired) | Organization 従属 |
-| `Billing/BillingCheckoutSession` | サブスク契約 Stripe Checkout Session の追跡 (attempt_token 冪等。`BillingAccess::state()` の PendingCheckout / ExpiredCheckout の出典。status: pending/completed/failed/expired) | Organization 従属 |
+| `Billing/BillingCheckoutSession` | サブスク契約 / カード登録 Stripe Checkout Session の追跡 (attempt_token 冪等。`BillingAccess::state()` の PendingCheckout / ExpiredCheckout の出典。status: pending/completed/failed/expired)。**live/stale の判定は本モデルの `staleThresholdAt()` / `isLivePending()` が単一出典**で、`BillingAccess::state()` / `SubscriptionService::startCheckout()` / 日次 sweeper が共有する | Organization 従属 |
 | `Billing/Subscription` | Cashier Subscription のテンプレート拡張 (current_period_end / has_payment_method / Subscription Schedule の部分完了追跡列) | Organization 従属 |
 | `Billing/TicketAutoRecharge` | オートリチャージ設定 (1 org 1 行。**既定 off の opt-in**。同意 snapshot 4 列 + 連続失敗状態。`max_count > threshold_count` は DB CHECK) | Organization 従属 |
 | `Billing/TicketAutoRechargeAttempt` | オートリチャージ試行の状態機械 (pending → paid / failed / canceled。quantity・unit_amount は起票時 pin = webhook 金額照合の出典。partial unique `tar_attempts_org_pending_unique` で org あたり pending は 1 件) | Organization 従属 |
diff --git a/docs/factories.md b/docs/factories.md
index 8dc8279..e87c7e3 100644
--- a/docs/factories.md
+++ b/docs/factories.md
@@ -16,7 +16,7 @@ ## Factory 一覧 (テンプレート同梱)
 |---------|-------|-----------|
 | `UserFactory` | User | `unverified()`, `ssoOnly()` (password null + 認証済み), `withTwoFactor()` (本物の TOTP secret + recovery codes + confirmed) |
 | `AdminUserFactory` | AdminUser | `withMfa()` |
-| `OrganizationFactory` | Organization | `personal()` |
+| `OrganizationFactory` | Organization | `personal()`, `freePersonal($declarer)`, `grandfathered()`, `signupGranted()`, `withBillingContact(?$email, ?$name)` (請求先連絡先。CipherSweet 暗号化列) |
 | `CustomTeamFactory` | CustomTeam | — |
 | `ProjectFactory` | Project | `forOrganization($org)` |
 | `ItemFactory` | Item | `forProject($project)` |
@@ -40,7 +40,7 @@ ## Factory 一覧 (テンプレート同梱)
 | `Billing\BillingNotificationFactory` | Billing/BillingNotification | `forOrganization($org)`, `reminder(?string $dedupKey = null)` (dedup_key 経路), `sent()`, `failed()` |
 | `Billing\TicketCheckoutSessionFactory` | Billing/TicketCheckoutSession | `forOrganization($org)`, `initiatedBy($user)`, `completed()`, `expired()`, `stale()` (pending のまま expires_at 過去) |
 | `Billing\TicketReservationFactory` | Billing/TicketReservation | `forOrganization($org)`, `legacy()` (P5 前の in-flight 予約 = `consume_*` null), `monthlyHold(?CarbonImmutable $consumeExpiresAt = null)`, `purchasedHold()`, `stale()` (reserved のまま TTL 超過) |
-| `Billing\BillingCheckoutSessionFactory` | Billing/BillingCheckoutSession | `withAttemptToken($token, ?$checkoutUrl)`, `initiatedBy(int $userId)`, `completed()`, `setupPaymentMethod()`, `expired()`, `failed()`, `stale()` (pending のまま created_at が stale 境界より過去) |
+| `Billing\BillingCheckoutSessionFactory` | Billing/BillingCheckoutSession | `withAttemptToken($token, ?$checkoutUrl)`, `initiatedBy(int $userId)`, `completed()`, `setupPaymentMethod()`, `expired()`, `failed()`, `stale()` (pending のまま created_at が stale 境界より過去), `withAttempt($token, $planCode)` (契約 attempt の token + plan を同時固定), `fundingAutoRecharge()` (T1004 の PM 流用対象), `pmReuseDispatched(?$at)` (PM 流用 Job dispatch marker) |
 | `Billing\TicketAutoRechargeFactory` | Billing/TicketAutoRecharge | `enabled()` (PM + 同意記録済み), `preConsented()` (事前同意のみ = pendingAutoEnable), `consentedMaxAmount(int $amount)` (価格改定 → 再同意シナリオ), `disabledByFailures()` |
 | `Billing\TicketAutoRechargeAttemptFactory` | Billing/TicketAutoRechargeAttempt | `withInvoice(?string $invoiceId = null)`, `paid()`, `failed()`, `canceled()` (既定は invoice 未作成の pending。**org あたり pending は DB partial unique で 1 件まで**) |
 
diff --git a/lang/ja/validation.php b/lang/ja/validation.php
index 7e90b67..9c9528b 100644
--- a/lang/ja/validation.php
+++ b/lang/ja/validation.php
@@ -212,6 +212,9 @@
         'declaration' => '個人利用の確認',
         'count' => '購入枚数',
         'attempt_token' => '操作トークン',
+        'subscription_attempt_token' => '契約手続きトークン',
+        'billing_contact_email' => '請求先メールアドレス',
+        'billing_contact_name' => '請求先の宛名',
         // オートリチャージ (P8a)。'enabled' は 2 段階認証と同名キーのため
         // UpdateAutoRechargeRequest::attributes() で個別に上書きする
         'threshold_count' => 'リチャージ開始残高',
diff --git a/resources/js/components/features/billing/BillingContactForm.svelte b/resources/js/components/features/billing/BillingContactForm.svelte
new file mode 100644
index 0000000..3c12fd3
--- /dev/null
+++ b/resources/js/components/features/billing/BillingContactForm.svelte
@@ -0,0 +1,133 @@
+<script lang="ts">
+    import { page as inertiaPage, router } from "@inertiajs/svelte";
+    import { Receipt } from "@lucide/svelte";
+    import Button from "@/components/atoms/Button.svelte";
+    import Card from "@/components/atoms/Card.svelte";
+    import Input from "@/components/atoms/Input.svelte";
+    import FormField from "@/components/molecules/FormField.svelte";
+    import type { BillingContactShape } from "@/types/billing";
+
+    /**
+     * BillingContactForm — 請求先情報 (メール / 宛名) の更新セクション (P9)。
+     *
+     * 請求書・支払い失敗などの請求通知の宛先になる。未設定の間は組織オーナーのメールへ
+     * 送られるため、その旨を help に出す (fallbackEmail はサーバ確定値)。
+     *
+     * **ボタンは未入力でも disabled にしない** (AGENTS.md 禁止事項 #8) — 押下してサーバの
+     * validation 文言を表示する。in-flight の多重送信抑止は Button の loading で表現する。
+     */
+    interface Props {
+        billingContact: BillingContactShape;
+        /** 更新 PATCH 先 (billing.contact.update) */
+        updateUrl: string;
+        canManage: boolean;
+    }
+
+    let { billingContact, updateUrl, canManage }: Props = $props();
+
+    let emailText = $state(billingContact.email ?? "");
+    let nameText = $state(billingContact.name ?? "");
+    let submitting = $state(false);
+
+    const serverErrors = $derived(
+        (inertiaPage.props.errors ?? {}) as Record<string, string>,
+    );
+    const emailError = $derived(!submitting ? (serverErrors.billing_contact_email ?? null) : null);
+    const nameError = $derived(!submitting ? (serverErrors.billing_contact_name ?? null) : null);
+
+    const helpText = $derived(
+        billingContact.email === null && billingContact.fallbackEmail !== null
+            ? `未設定のため、現在は組織オーナー (${billingContact.fallbackEmail}) 宛に送信しています。`
+            : "請求書・お支払いに関するご連絡をこのメールアドレスへお送りします。",
+    );
+
+    function submit(): void {
+        if (submitting) return; // 多重送信ガード (disabled にはしない)
+        router.patch(
+            updateUrl,
+            { billing_contact_email: emailText, billing_contact_name: nameText },
+            {
+                preserveScroll: true,
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
+<Card padding="lg" testId="billing-contact-card">
+    <div class="flex items-center gap-2">
+        <Receipt class="size-5 text-text-secondary" aria-hidden="true" />
+        <h2 class="text-h3">請求先情報</h2>
+    </div>
+
+    {#if canManage}
+        <form
+            class="mt-4 flex flex-col gap-4"
+            data-testid="billing-contact-form"
+            onsubmit={(event) => {
+                event.preventDefault();
+                submit();
+            }}
+        >
+            <FormField
+                label="請求先メールアドレス"
+                id="billing-contact-email"
+                required
+                error={emailError}
+                help={helpText}
+            >
+                {#snippet children({ id, describedBy, invalid })}
+                    <Input
+                        {id}
+                        type="email"
+                        bind:value={emailText}
+                        error={invalid}
+                        aria-describedby={describedBy}
+                        testId="billing-contact-email-input"
+                    />
+                {/snippet}
+            </FormField>
+
+            <FormField label="宛名 (任意)" id="billing-contact-name" error={nameError}>
+                {#snippet children({ id, describedBy, invalid })}
+                    <Input
+                        {id}
+                        bind:value={nameText}
+                        error={invalid}
+                        aria-describedby={describedBy}
+                        testId="billing-contact-name-input"
+                    />
+                {/snippet}
+            </FormField>
+
+            <div>
+                <Button type="submit" loading={submitting} testId="billing-contact-submit">
+                    請求先情報を保存
+                </Button>
+            </div>
+        </form>
+    {:else}
+        <dl class="mt-4 grid gap-4 md:grid-cols-2">
+            <div>
+                <dt class="text-caption text-text-secondary">請求先メールアドレス</dt>
+                <dd class="mt-1 text-body text-text" data-testid="billing-contact-email-readonly">
+                    {billingContact.email ?? "未設定"}
+                </dd>
+            </div>
+            <div>
+                <dt class="text-caption text-text-secondary">宛名</dt>
+                <dd class="mt-1 text-body text-text" data-testid="billing-contact-name-readonly">
+                    {billingContact.name ?? "未設定"}
+                </dd>
+            </div>
+        </dl>
+        <p class="mt-4 text-caption text-text-secondary">
+            請求先情報の変更には組織の管理者権限が必要です。
+        </p>
+    {/if}
+</Card>
diff --git a/resources/js/pages/Billing/Index.svelte b/resources/js/pages/Billing/Index.svelte
index 5bcd732..b9c7cf7 100644
--- a/resources/js/pages/Billing/Index.svelte
+++ b/resources/js/pages/Billing/Index.svelte
@@ -2,6 +2,8 @@
     import { onMount } from "svelte";
     import { page as inertiaPage, router } from "@inertiajs/svelte";
     import { CreditCard } from "@lucide/svelte";
+    import Alert from "@/components/atoms/Alert.svelte";
+    import type { AlertType } from "@/components/atoms/Alert.svelte";
     import Button from "@/components/atoms/Button.svelte";
     import Card from "@/components/atoms/Card.svelte";
     import TextLink from "@/components/atoms/TextLink.svelte";
@@ -10,9 +12,10 @@
     import PageContent from "@/components/templates/PageContent.svelte";
     import PageHeader from "@/components/molecules/PageHeader.svelte";
     import AutoRechargeCard from "@/components/features/billing/AutoRechargeCard.svelte";
+    import BillingContactForm from "@/components/features/billing/BillingContactForm.svelte";
     import { formatDate } from "@/lib/date-format";
     import type { SharedProps } from "@/lib/shared-props";
-    import type { BillingDashboardProps } from "@/types/billing";
+    import type { BillingDashboardProps, BillingFeedbackKind } from "@/types/billing";
 
     /**
      * 課金ダッシュボード (/billing)。現在のプラン / per-bucket チケット残高 / 現行 quota 上限 /
@@ -35,6 +38,23 @@
 
     let portalProcessing = $state(false);
 
+    /**
+     * P9: 決済戻り着地の one-shot フィードバック。**raw query は一切見ない** —
+     * kind → variant の写像だけを持ち、文言はサーバ確定値をそのまま描画する。
+     * 一度表示したら消える (リロードで query が落ちれば feedback は null で届く)。
+     */
+    const FEEDBACK_VARIANTS = {
+        purchase_received: "success",
+        purchase_processing: "info",
+        purchase_already_received: "info",
+        checkout_retry_required: "warning",
+        portal_returned: "info",
+    } as const satisfies Record<BillingFeedbackKind, AlertType>;
+
+    const feedbackVariant = $derived(
+        page.feedback === null ? null : FEEDBACK_VARIANTS[page.feedback.kind],
+    );
+
     const formatYen = (amount: number | null): string =>
         amount === null ? "—" : new Intl.NumberFormat("ja-JP").format(amount);
 
@@ -59,9 +79,9 @@
     onMount(() => {
         const params = new URLSearchParams(window.location.search);
         if (params.get("highlight") === "auto-recharge") {
-            document
-                .querySelector('[data-testid="auto-recharge-card"]')
-                ?.scrollIntoView({ behavior: "smooth" });
+            const card = document.querySelector('[data-testid="auto-recharge-card"]');
+            card?.scrollIntoView({ behavior: "smooth" });
+            card?.setAttribute("data-highlighted", "true");
         }
     });
 </script>
@@ -76,6 +96,14 @@
         />
         <PageContent>
             <div class="flex flex-col gap-10">
+                {#if page.feedback !== null && feedbackVariant !== null}
+                    <Alert type={feedbackVariant} testId="billing-feedback">
+                        <span data-testid={`billing-feedback-${page.feedback.kind}`}>
+                            {page.feedback.message}
+                        </span>
+                    </Alert>
+                {/if}
+
                 {#if page.continueUrl !== null}
                     <Card padding="lg" testId="billing-continue">
                         <p class="text-body">お手続きが完了しました。中断していた画面に戻れます。</p>
@@ -194,6 +222,13 @@
                     setupAttemptToken={page.autoRechargeSetupToken}
                 />
 
+                <!-- P9: 請求先情報 (請求通知の宛先。未設定時は owner email へ fallback)。 -->
+                <BillingContactForm
+                    billingContact={page.billingContact}
+                    updateUrl="/billing/contact"
+                    canManage={page.canManageBilling}
+                />
+
                 <Card padding="lg" testId="billing-quotas">
                     <h2 class="text-h3">現在のプランの上限</h2>
                     <dl class="mt-4 grid gap-4 sm:grid-cols-3">
diff --git a/resources/js/pages/Billing/Plans.svelte b/resources/js/pages/Billing/Plans.svelte
index 9811e05..c96921b 100644
--- a/resources/js/pages/Billing/Plans.svelte
+++ b/resources/js/pages/Billing/Plans.svelte
@@ -14,7 +14,9 @@
 
     /**
      * プラン比較 (/billing/plans)。閲覧は組織メンバー全員、変更は manageBilling のみ。
-     * 変更は既存の Stripe Checkout (POST /billing/checkout。body は plan_code のみ) へ委譲する。
+     * 変更は既存の Stripe Checkout (POST /billing/checkout) へ委譲する。body は plan_code +
+     * subscription_attempt_token (冪等 token。funding_choice は載せない = 契約変更経路に
+     * 資金選択の提示は無い)。
      *
      * 変更できないプランでも CTA は enabled のまま描画し、理由は caption + 押下時 Alert で
      * 伝える (DESIGN.md / 禁止事項 #8)。
@@ -77,7 +79,7 @@
         if (planCode === null || submitting) return;
         router.post(
             "/billing/checkout",
-            { plan_code: planCode },
+            { plan_code: planCode, subscription_attempt_token: page.subscriptionAttemptToken },
             {
                 onStart: () => {
                     submitting = true;
diff --git a/resources/js/pages/Onboarding/Checkout.svelte b/resources/js/pages/Onboarding/Checkout.svelte
index 61995f0..ce951df 100644
--- a/resources/js/pages/Onboarding/Checkout.svelte
+++ b/resources/js/pages/Onboarding/Checkout.svelte
@@ -153,7 +153,16 @@
         lastSubmittedPlanCode = chosenPlanCode;
         router.post(
             "/billing/checkout",
-            { plan_code: chosenPlanCode },
+            {
+                plan_code: chosenPlanCode,
+                subscription_attempt_token: pageData.subscriptionAttemptToken,
+                funding_choice: fundingChoice,
+                // auto_recharge のときだけ同意 version を送る (金額は送らない = サーバ再計算)。
+                // 同意アクションは実行ボタンのクリック。
+                ...(fundingChoice === AUTO_RECHARGE
+                    ? { consent_version: consentTerms.consentVersion }
+                    : {}),
+            },
             {
                 onStart: () => {
                     submitting = true;
@@ -246,58 +255,7 @@
                             testId="personal-declaration"
                         />
 
-                        <!-- P8a (D29(i)): チケットの補充方法の 2 択。既定は自動購入 (おすすめ) だが、
-                             「あとで決める」を選べば課金設定なしで始められる (opt-in を強制しない)。 -->
-                        <fieldset class="flex flex-col gap-2" data-testid="funding-choice">
-                            <legend class="text-caption font-medium text-text">
-                                チケットの補充方法
-                            </legend>
-                            {#each pageData.fundingChoices as choice (choice)}
-                                <label class="flex items-start gap-2">
-                                    <input
-                                        type="radio"
-                                        name="funding_choice"
-                                        value={choice}
-                                        checked={fundingChoice === choice}
-                                        onchange={() => {
-                                            fundingChoice = choice;
-                                        }}
-                                        class="mt-1 h-4 w-4 accent-primary"
-                                        data-testid={`funding-choice-${choice}`}
-                                    />
-                                    <span class="text-body text-text">
-                                        {#if choice === AUTO_RECHARGE}
-                                            残高が少なくなったら自動で購入する（おすすめ）
-                                        {:else}
-                                            あとで決める（無償チケットだけで始める）
-                                        {/if}
-                                    </span>
-                                </label>
-                            {/each}
-
-                            {#if fundingChoice === AUTO_RECHARGE}
-                                <div
-                                    class="rounded-sm border border-border p-3"
-                                    data-testid="funding-consent-terms"
-                                >
-                                    <p class="text-caption text-text-secondary">
-                                        残高が {consentTerms.thresholdCount} 枚を下回ると、登録済みのカードで不足分をまとめて購入し、{consentTerms.maxCount}
-                                        枚まで補充します。1 回の自動購入の上限額は ¥{formatYen(
-                                            consentTerms.maxAmountJpy,
-                                        )}（税込・1 枚あたり ¥{formatYen(consentTerms.unitAmountJpy)}）です。
-                                    </p>
-                                    <p class="mt-1 text-caption text-text-secondary">
-                                        次の画面でカードを登録します。登録しただけでは課金されません。設定はいつでも変更・停止できます。
-                                    </p>
-                                </div>
-                            {/if}
-
-                            {#if fundingChoiceError !== null}
-                                <p class="text-caption text-danger" data-testid="funding-choice-error">
-                                    {fundingChoiceError}
-                                </p>
-                            {/if}
-                        </fieldset>
+                        {@render fundingChoiceSection()}
 
                         <div>
                             <Button
@@ -320,6 +278,8 @@
                             </Alert>
                         {/if}
 
+                        {@render fundingChoiceSection()}
+
                         <div>
                             <Button
                                 onclick={submitPaidPlan}
@@ -348,3 +308,58 @@
         </PageContent>
     </PageContainer>
 </AppLayout>
+
+{#snippet fundingChoiceSection()}
+                    <!-- P8a (D29(i)): チケットの補充方法の 2 択。既定は自動購入 (おすすめ) だが、
+                         「あとで決める」を選べば課金設定なしで始められる (opt-in を強制しない)。 -->
+                    <fieldset class="flex flex-col gap-2" data-testid="funding-choice">
+                        <legend class="text-caption font-medium text-text">
+                            チケットの補充方法
+                        </legend>
+                        {#each pageData.fundingChoices as choice (choice)}
+                            <label class="flex items-start gap-2">
+                                <input
+                                    type="radio"
+                                    name="funding_choice"
+                                    value={choice}
+                                    checked={fundingChoice === choice}
+                                    onchange={() => {
+                                        fundingChoice = choice;
+                                    }}
+                                    class="mt-1 h-4 w-4 accent-primary"
+                                    data-testid={`funding-choice-${choice}`}
+                                />
+                                <span class="text-body text-text">
+                                    {#if choice === AUTO_RECHARGE}
+                                        残高が少なくなったら自動で購入する（おすすめ）
+                                    {:else}
+                                        あとで決める（無償チケットだけで始める）
+                                    {/if}
+                                </span>
+                            </label>
+                        {/each}
+
+                        {#if fundingChoice === AUTO_RECHARGE}
+                            <div
+                                class="rounded-sm border border-border p-3"
+                                data-testid="funding-consent-terms"
+                            >
+                                <p class="text-caption text-text-secondary">
+                                    残高が {consentTerms.thresholdCount} 枚を下回ると、登録済みのカードで不足分をまとめて購入し、{consentTerms.maxCount}
+                                    枚まで補充します。1 回の自動購入の上限額は ¥{formatYen(
+                                        consentTerms.maxAmountJpy,
+                                    )}（税込・1 枚あたり ¥{formatYen(consentTerms.unitAmountJpy)}）です。
+                                </p>
+                                <p class="mt-1 text-caption text-text-secondary">
+                                    次の画面でカードを登録します。登録しただけでは課金されません。設定はいつでも変更・停止できます。
+                                </p>
+                            </div>
+                        {/if}
+
+                        {#if fundingChoiceError !== null}
+                            <p class="text-caption text-danger" data-testid="funding-choice-error">
+                                {fundingChoiceError}
+                            </p>
+                        {/if}
+                    </fieldset>
+{/snippet}
diff --git a/resources/js/types/billing.ts b/resources/js/types/billing.ts
index 9d9db18..ec03b87 100644
--- a/resources/js/types/billing.ts
+++ b/resources/js/types/billing.ts
@@ -61,6 +61,31 @@ export interface PurchaseTicketsPageProps {
     readonly newPurchaseUrl: string;
 }
 
+/**
+ * PHP: BillingFeedbackDto の SimpleBillingFeedbackKind と exact 対 (5 値)。
+ * UI は raw query を見ず、この kind でバナー variant を決める。
+ */
+export type BillingFeedbackKind =
+    | "purchase_received"
+    | "purchase_processing"
+    | "purchase_already_received"
+    | "checkout_retry_required"
+    | "portal_returned";
+
+/** PHP: BillingFeedbackDto (BillingFeedbackShape) と対 */
+export interface BillingFeedbackShape {
+    readonly kind: BillingFeedbackKind;
+    readonly message: string;
+}
+
+/** PHP: BillingContactDto (BillingContactShape) と対 */
+export interface BillingContactShape {
+    readonly email: string | null;
+    readonly name: string | null;
+    /** 未設定時に実際の通知宛先になる owner email */
+    readonly fallbackEmail: string | null;
+}
+
 /** PHP: BillingPlansPageDto (BillingPlansPageShape) と対 */
 export interface BillingPlansPageProps {
     readonly plans: readonly PricingPlanShape[];
@@ -68,6 +93,8 @@ export interface BillingPlansPageProps {
     readonly currentPlanCode: string | null;
     readonly billingState: BillingStateValue;
     readonly canManage: boolean;
+    /** 契約 checkout の冪等 token (チケット購入 / カード登録とは別 key 空間) */
+    readonly subscriptionAttemptToken: string;
 }
 
 /** PHP: BillingDashboardDto (BillingDashboardShape) と対 */
@@ -87,6 +114,13 @@ export interface BillingDashboardProps {
     readonly autoRecharge: AutoRechargeProps;
     /** P8a: カード登録 (mode=setup) 開始 POST の attempt_token (render 単位) */
     readonly autoRechargeSetupToken: string;
+    /**
+     * P9: 決済戻り着地の one-shot フィードバック。該当しない着地では null。
+     * **購入完了をユーザーに知らせる唯一の経路**なので、null と分岐を落とさないこと。
+     */
+    readonly feedback: BillingFeedbackShape | null;
+    /** P9: 請求先連絡先 (未設定なら fallbackEmail が実際の宛先) */
+    readonly billingContact: BillingContactShape;
 }
 
 /**
diff --git a/resources/js/types/onboarding.ts b/resources/js/types/onboarding.ts
index 3383c4a..de40f1d 100644
--- a/resources/js/types/onboarding.ts
+++ b/resources/js/types/onboarding.ts
@@ -46,6 +46,8 @@ export interface OnboardingCheckoutShape {
     readonly consentTerms: AutoRechargeConsentTerms;
     /** 画面に出す資金選択の並び (enum 値。`tickets` は UI に出さない) */
     readonly fundingChoices: readonly string[];
+    /** P9: 有償プラン契約 POST の冪等 token (render 単位の ULID) */
+    readonly subscriptionAttemptToken: string;
 }
 
 /** PHP: BillingRequiredDto (BillingRequiredShape) と対 */
diff --git a/routes/web.php b/routes/web.php
index f555e44..fdfb259 100644
--- a/routes/web.php
+++ b/routes/web.php
@@ -328,6 +328,10 @@
         ->name('billing.checkout');
     Route::post('/billing/portal', [BillingController::class, 'portal'])
         ->name('billing.portal');
+    // P9: 請求先連絡先 (メール / 宛名)。current org スコープ (route parameter なし)。
+    // 認可は Controller 冒頭の Gate::authorize('manageBilling')。
+    Route::patch('/billing/contact', [BillingController::class, 'updateBillingContact'])
+        ->name('billing.contact.update');
 
     /*
     | オートリチャージ (裏チャージ。P8a)。**opt-in・既定 off**。
diff --git a/tests/Architecture/BillingContactEncryptionInvariantTest.php b/tests/Architecture/BillingContactEncryptionInvariantTest.php
new file mode 100644
index 0000000..f68a874
--- /dev/null
+++ b/tests/Architecture/BillingContactEncryptionInvariantTest.php
@@ -0,0 +1,46 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Models\Billing\BillingCheckoutSession;
+use App\Models\Organization;
+use ParagonIE\CipherSweet\CipherSweet;
+use ParagonIE\CipherSweet\EncryptedRow;
+use Spatie\LaravelCipherSweet\Contracts\CipherSweetEncrypted;
+
+/*
+ * P9 (セキュリティ不変条件 #6 / #1): 請求先 PII の保管と mass-assignment の構造的封じ。
+ *
+ * 「暗号化を後から外す」「$fillable に足す」変更をテストの更新なしには通さない。
+ */
+
+test('Organization は CipherSweetEncrypted を実装し両列を登録している', function (): void {
+    expect(is_subclass_of(Organization::class, CipherSweetEncrypted::class))->toBeTrue();
+
+    $row = new EncryptedRow(app(CipherSweet::class), 'organizations');
+    Organization::configureCipherSweet($row);
+
+    $fields = $row->listEncryptedFields();
+    expect($fields)->toContain('billing_contact_email');
+    expect($fields)->toContain('billing_contact_name');
+});
+
+test('organizations.billing_contact_* の列型は text (ciphertext を格納するため)', function (string $column): void {
+    // Architecture lane は DB を持たないため migration 定義を直接読む
+    // (string(255) では ciphertext が溢れる = 型の後退を機械検出する)。
+    $migration = file_get_contents(
+        base_path('database/migrations/2026_07_17_000400_add_billing_contact_columns_to_organizations_table.php'),
+    );
+    expect($migration)->toBeString();
+    expect($migration)->toContain("\$table->text('{$column}')->nullable()");
+})->with(['billing_contact_email', 'billing_contact_name']);
+
+test('billing_contact_* は $fillable に無い (明示代入のみ)', function (string $column): void {
+    expect((new Organization)->getFillable())->not->toContain($column);
+})->with(['billing_contact_email', 'billing_contact_name']);
+
+test('billing_checkout_sessions.pm_reuse_dispatched_at は $fillable に無い (webhook の forceFill 専用 marker)', function (): void {
+    expect((new BillingCheckoutSession)->getFillable())->not->toContain('pm_reuse_dispatched_at');
+    // funding_choice は checkout 開始時の入力なので fillable
+    expect((new BillingCheckoutSession)->getFillable())->toContain('funding_choice');
+});
diff --git a/tests/Architecture/CheckoutLiveThresholdSingleSourceTest.php b/tests/Architecture/CheckoutLiveThresholdSingleSourceTest.php
new file mode 100644
index 0000000..236a6ed
--- /dev/null
+++ b/tests/Architecture/CheckoutLiveThresholdSingleSourceTest.php
@@ -0,0 +1,37 @@
+<?php
+
+declare(strict_types=1);
+
+/*
+ * P9 (C-1) の構造的封じ: live/stale の閾値 literal は
+ * BillingCheckoutSession::staleThresholdAt() **1 箇所にしか存在しない**。
+ *
+ * state() / startCheckout() / 日次 sweeper が「同じ 1 日」を独自に再発明すると、
+ * 片方だけを直したときに「支払えないのに新規 Checkout も作れない」詰みが生まれる。
+ * 閾値の再発明を機械検出する (テストが変更の唯一の入口)。
+ */
+
+$consumers = [
+    'app/Services/Billing/BillingAccess.php',
+    'app/Services/Billing/SubscriptionService.php',
+    'app/Console/Commands/Billing/ReconcileSubscriptionSchedules.php',
+];
+
+test('live 判定の閾値 literal は共有述語の外に現れない', function (string $relativePath): void {
+    $source = file_get_contents(base_path($relativePath));
+    expect($source)->toBeString();
+
+    expect($source)->not->toContain('subDay(');
+    expect($source)->not->toContain('subDays(');
+})->with($consumers);
+
+test('閾値の単一出典は BillingCheckoutSession::staleThresholdAt にある', function (): void {
+    $source = file_get_contents(base_path('app/Models/Billing/BillingCheckoutSession.php'));
+    expect($source)->toBeString();
+
+    expect($source)->toContain('public static function staleThresholdAt(CarbonImmutable $now): CarbonImmutable');
+    expect($source)->toContain('$now->subDay()');
+    // live / stale が同一述語から導かれること (補集合の関係を壊さない)
+    expect($source)->toContain('public function isLivePending(CarbonImmutable $now): bool');
+    expect($source)->toContain('public function isReplayablePending(CarbonImmutable $now): bool');
+});
diff --git a/tests/Feature/Billing/BillingAccessStateTest.php b/tests/Feature/Billing/BillingAccessStateTest.php
index facb195..b941007 100644
--- a/tests/Feature/Billing/BillingAccessStateTest.php
+++ b/tests/Feature/Billing/BillingAccessStateTest.php
@@ -228,7 +228,7 @@ function cohortBillingAccess(): BillingAccess
     $organization = cohortPaidOrganization();
     BillingCheckoutSession::factory()->create([
         'organization_id' => $organization->getKey(),
-        'created_at' => BillingAccess::staleThresholdAt(CarbonImmutable::now()),
+        'created_at' => BillingCheckoutSession::staleThresholdAt(CarbonImmutable::now()),
     ]);
 
     expect(cohortBillingAccess()->state($organization))->toBe(OnboardingBillingState::PendingCheckout)
@@ -240,7 +240,7 @@ function cohortBillingAccess(): BillingAccess
     $organization = cohortPaidOrganization();
     BillingCheckoutSession::factory()->create([
         'organization_id' => $organization->getKey(),
-        'created_at' => BillingAccess::staleThresholdAt(CarbonImmutable::now())->subSecond(),
+        'created_at' => BillingCheckoutSession::staleThresholdAt(CarbonImmutable::now())->subSecond(),
     ]);
 
     expect(cohortBillingAccess()->state($organization))->toBe(OnboardingBillingState::ExpiredCheckout);
diff --git a/tests/Feature/Billing/BillingCheckoutSessionModelTest.php b/tests/Feature/Billing/BillingCheckoutSessionModelTest.php
index cc3444f..979ce93 100644
--- a/tests/Feature/Billing/BillingCheckoutSessionModelTest.php
+++ b/tests/Feature/Billing/BillingCheckoutSessionModelTest.php
@@ -7,7 +7,9 @@
 use App\Models\Billing\BillingCheckoutSession;
 use App\Models\Organization;
 use App\Models\User;
+use Carbon\CarbonImmutable;
 use Illuminate\Database\QueryException;
+use Illuminate\Support\Carbon;
 
 /*
  * BillingCheckoutSession (state() の PendingCheckout / ExpiredCheckout の真実源) の
@@ -43,13 +45,13 @@
 test('isReplayablePending は pending かつ checkout_url が生存しているときだけ true', function (): void {
     $replayable = BillingCheckoutSession::factory()->withAttemptToken('token-live')->create();
 
-    expect($replayable->isReplayablePending())->toBeTrue();
+    expect($replayable->isReplayablePending(CarbonImmutable::now()))->toBeTrue();
 });
 
 test('isReplayablePending は checkout_url が null / 空なら false', function (?string $url): void {
     $session = BillingCheckoutSession::factory()->create(['checkout_url' => $url]);
 
-    expect($session->isReplayablePending())->toBeFalse();
+    expect($session->isReplayablePending(CarbonImmutable::now()))->toBeFalse();
 })->with([null, '']);
 
 test('isReplayablePending は pending 以外なら checkout_url があっても false', function (string $state): void {
@@ -58,7 +60,7 @@
         ->{$state}()
         ->create();
 
-    expect($session->isReplayablePending())->toBeFalse();
+    expect($session->isReplayablePending(CarbonImmutable::now()))->toBeFalse();
 })->with(['completed', 'expired', 'failed']);
 
 test('initiatedBy / organization の関連が引ける', function (): void {
@@ -129,3 +131,38 @@
         ->not->toContain('organization_id')
         ->not->toContain('initiated_by_user_id');
 });
+
+test('isLivePending は created_at が stale 境界より新しいときだけ true (境界の両側を固定)', function (): void {
+    // created_at の永続化は秒精度 (Eloquent の date format) のため基準時刻も秒に丸める。
+    $now = CarbonImmutable::now()->startOfSecond();
+
+    $live = BillingCheckoutSession::factory()->create(['created_at' => $now->subHours(23)]);
+    $boundary = BillingCheckoutSession::factory()->create([
+        'created_at' => BillingCheckoutSession::staleThresholdAt($now),
+    ]);
+    $stale = BillingCheckoutSession::factory()->create(['created_at' => $now->subHours(25)]);
+
+    expect($live->isLivePending($now))->toBeTrue();
+    // 境界時刻ちょうどは live 側 (live/stale は補集合であり両方に属する行は存在しない)
+    expect($boundary->isLivePending($now))->toBeTrue();
+    expect($stale->isLivePending($now))->toBeFalse();
+});
+
+test('created_at が null の行は live 扱い (state() の else 分岐と同一)', function (): void {
+    $session = BillingCheckoutSession::factory()->create();
+    $session->created_at = null;
+
+    expect($session->isLivePending(CarbonImmutable::now()))->toBeTrue();
+});
+
+test('P9 の additive 2 列: funding_choice は fillable、pm_reuse_dispatched_at は datetime cast かつ fillable 外', function (): void {
+    $session = BillingCheckoutSession::factory()
+        ->fundingAutoRecharge()
+        ->pmReuseDispatched()
+        ->create();
+
+    expect($session->funding_choice)->toBe('auto_recharge');
+    expect($session->refresh()->pm_reuse_dispatched_at)->toBeInstanceOf(Carbon::class);
+    expect($session->getFillable())->toContain('funding_choice');
+    expect($session->getFillable())->not->toContain('pm_reuse_dispatched_at');
+});
diff --git a/tests/Feature/Billing/BillingContactPiiTest.php b/tests/Feature/Billing/BillingContactPiiTest.php
new file mode 100644
index 0000000..a87eae9
--- /dev/null
+++ b/tests/Feature/Billing/BillingContactPiiTest.php
@@ -0,0 +1,78 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Models\Organization;
+use Illuminate\Support\Facades\DB;
+
+/*
+ * P9 (セキュリティ不変条件 #6): 請求先連絡先は email / name とも CipherSweet で暗号化する。
+ * 平文 where は hit しない = 検索は whereBlind のみ (email だけが blind index を持つ)。
+ */
+
+test('PATCH 後、organizations の生値は両列とも平文と一致しない (model 経由では復号される)', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+
+    $this->actingAs($owner)->patch('/billing/contact', [
+        'billing_contact_email' => 'billing@example.test',
+        'billing_contact_name' => '経理部 御中',
+    ])->assertRedirect();
+
+    /** @var object{billing_contact_email: string, billing_contact_name: string} $raw */
+    $raw = DB::table('organizations')->where('id', $organization->id)->firstOrFail();
+    expect($raw->billing_contact_email)->not->toBe('billing@example.test');
+    expect($raw->billing_contact_name)->not->toBe('経理部 御中');
+    // CipherSweet の ciphertext は backend prefix 付き ('nacl:' / 'brng:')
+    expect($raw->billing_contact_email)->toMatch('/^(nacl|brng|fips):/');
+
+    $fresh = $organization->fresh();
+    expect($fresh?->billing_contact_email)->toBe('billing@example.test');
+    expect($fresh?->billing_contact_name)->toBe('経理部 御中');
+});
+
+test('平文 where は hit せず whereBlind が該当 org を引く', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+
+    $this->actingAs($owner)->patch('/billing/contact', [
+        'billing_contact_email' => 'billing@example.test',
+    ])->assertRedirect();
+
+    expect(Organization::query()->where('billing_contact_email', 'billing@example.test')->exists())->toBeFalse();
+
+    $found = Organization::whereBlind(
+        'billing_contact_email',
+        'organization_billing_contact_email_index',
+        'billing@example.test',
+    )->first();
+    expect($found?->id)->toBe($organization->id);
+});
+
+test('billing_contact_name の blind index 行は作られない (検索契約が存在しない)', function (): void {
+    [, $owner] = createOrganizationWithOwner();
+
+    $this->actingAs($owner)->patch('/billing/contact', [
+        'billing_contact_email' => 'billing@example.test',
+        'billing_contact_name' => '経理部 御中',
+    ])->assertRedirect();
+
+    $indexNames = DB::table('blind_indexes')->distinct()->pluck('name')->all();
+    expect($indexNames)->toContain('organization_billing_contact_email_index');
+    expect($indexNames)->not->toContain('organization_billing_contact_name_index');
+});
+
+test('大文字混じり入力は正規化後の小文字で whereBlind が hit する', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+
+    $this->actingAs($owner)->patch('/billing/contact', [
+        'billing_contact_email' => '  Billing@Example.TEST  ',
+    ])->assertRedirect();
+
+    expect($organization->fresh()?->billing_contact_email)->toBe('billing@example.test');
+
+    $found = Organization::whereBlind(
+        'billing_contact_email',
+        'organization_billing_contact_email_index',
+        'billing@example.test',
+    )->first();
+    expect($found?->id)->toBe($organization->id);
+});
diff --git a/tests/Feature/Billing/BillingFeedbackTest.php b/tests/Feature/Billing/BillingFeedbackTest.php
new file mode 100644
index 0000000..67689c0
--- /dev/null
+++ b/tests/Feature/Billing/BillingFeedbackTest.php
@@ -0,0 +1,143 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\CheckoutSessionStatus;
+use App\Models\Billing\BillingCheckoutSession;
+use App\Models\Organization;
+use Inertia\Testing\AssertableInertia as Assert;
+use Laravel\Cashier\Events\WebhookReceived;
+
+/*
+ * P9: /billing 着地の one-shot フィードバック。
+ *
+ * T088 で PurchaseFormState::Completed を撤去したため、**購入完了をユーザーに知らせる
+ * 唯一の経路**がこれ。UI は raw query を見ず DTO のみを描画する。
+ * session_id は org スコープ relation 経由でのみ引き、intent 検証で fail-closed にする。
+ */
+
+test('自 org の completed / pending は対応する kind を返し、failed / expired は null', function (string $state, ?string $kind): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+
+    $factory = BillingCheckoutSession::factory()->for($organization);
+    $stated = match ($state) {
+        'completed' => $factory->completed(),
+        'pending' => $factory,
+        'failed' => $factory->failed(),
+        default => $factory->expired(),
+    };
+    $session = $stated->create();
+
+    $this->actingAs($owner)
+        ->get('/billing?session_id='.$session->stripe_session_id)
+        ->assertOk()
+        ->assertInertia(fn (Assert $page) => $kind === null
+            ? $page->where('page.feedback', null)
+            : $page->where('page.feedback.kind', $kind));
+})->with([
+    'completed' => ['completed', 'purchase_received'],
+    'pending' => ['pending', 'purchase_processing'],
+    'failed' => ['failed', null],
+    'expired' => ['expired', null],
+]);
+
+test('他 org / 未知 / intent=setup_payment_method の session_id は feedback を出さない', function (string $case): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    [$foreign] = createOrganizationWithOwner('他組織');
+
+    $sessionId = match ($case) {
+        'foreign' => BillingCheckoutSession::factory()->for($foreign)->completed()->create()->stripe_session_id,
+        'setup' => BillingCheckoutSession::factory()->for($organization)->setupPaymentMethod()->completed()
+            ->create()->stripe_session_id,
+        default => 'cs_unknown_session',
+    };
+
+    $this->actingAs($owner)
+        ->get('/billing?session_id='.$sessionId)
+        ->assertOk()
+        ->assertInertia(fn (Assert $page) => $page->where('page.feedback', null));
+})->with(['他 org' => ['foreign'], '未知' => ['unknown'], 'P8a の setup 行' => ['setup']]);
+
+test('?portal は portal_returned を返すが、error flash がある着地では null (成功偽装の抑止)', function (): void {
+    [, $owner] = createOrganizationWithOwner();
+
+    $this->actingAs($owner)
+        ->get('/billing?portal=1')
+        ->assertOk()
+        ->assertInertia(fn (Assert $page) => $page->where('page.feedback.kind', 'portal_returned'));
+
+    $this->actingAs($owner)
+        ->withSession(['error' => 'お支払い管理画面は有償プラン契約後にご利用いただけます。'])
+        ->get('/billing?portal=1')
+        ->assertOk()
+        ->assertInertia(fn (Assert $page) => $page->where('page.feedback', null));
+});
+
+test('?replayed / ?retry は中立文言の kind を返す', function (string $query, string $kind): void {
+    [, $owner] = createOrganizationWithOwner();
+
+    $this->actingAs($owner)
+        ->get('/billing?'.$query.'=1')
+        ->assertOk()
+        ->assertInertia(fn (Assert $page) => $page->where('page.feedback.kind', $kind));
+})->with([
+    'replayed' => ['replayed', 'purchase_already_received'],
+    'retry' => ['retry', 'checkout_retry_required'],
+]);
+
+test('query の無い着地では feedback=null (one-shot: リロードで消える)', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $session = BillingCheckoutSession::factory()->for($organization)->completed()->create();
+
+    $this->actingAs($owner)
+        ->get('/billing?session_id='.$session->stripe_session_id)
+        ->assertOk()
+        ->assertInertia(fn (Assert $page) => $page->where('page.feedback.kind', 'purchase_received'));
+
+    // canonical URL への再訪 (= リロード相当) では feedback が消える
+    $this->actingAs($owner)
+        ->get('/billing')
+        ->assertOk()
+        ->assertInertia(fn (Assert $page) => $page->where('page.feedback', null));
+});
+
+test('C-2 との結合: Expired 行が遅延 completed で Completed になった後の着地は purchase_received', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $organization->stripe_id = 'cus_feedback_1';
+    $organization->save();
+
+    $session = BillingCheckoutSession::factory()->for($organization)->expired()->create([
+        'stripe_session_id' => 'cs_feedback_1',
+        'plan_code' => 'standard',
+    ]);
+
+    event(new WebhookReceived(feedbackCompletedPayload($organization)));
+    expect($session->refresh()->status)->toBe(CheckoutSessionStatus::Completed->value);
+
+    $this->actingAs($owner)
+        ->get('/billing?session_id=cs_feedback_1')
+        ->assertOk()
+        ->assertInertia(fn (Assert $page) => $page->where('page.feedback.kind', 'purchase_received'));
+});
+
+/**
+ * @return array<string, mixed>
+ */
+function feedbackCompletedPayload(Organization $organization): array
+{
+    return [
+        'id' => 'evt_feedback_1',
+        'type' => 'checkout.session.completed',
+        'data' => ['object' => [
+            'id' => 'cs_feedback_1',
+            'mode' => 'subscription',
+            'customer' => 'cus_feedback_1',
+            'payment_status' => 'paid',
+            'metadata' => [
+                'purpose' => 'subscription_start',
+                'org_ref' => (string) $organization->id,
+                'plan_code' => 'standard',
+            ],
+        ]],
+    ];
+}
diff --git a/tests/Feature/Billing/BillingPageTest.php b/tests/Feature/Billing/BillingPageTest.php
index 6d912d3..91d2ec3 100644
--- a/tests/Feature/Billing/BillingPageTest.php
+++ b/tests/Feature/Billing/BillingPageTest.php
@@ -6,6 +6,7 @@
 use App\Services\Billing\Contracts\StripeGatewayInterface;
 use App\Services\Billing\Fakes\FakeStripeGateway;
 use App\Services\Billing\TicketLedgerService;
+use Illuminate\Support\Str;
 use Inertia\Testing\AssertableInertia as Assert;
 
 /*
@@ -64,7 +65,10 @@
     $member->forceFill(['current_organization_id' => $organization->id])->save();
 
     $this->actingAs($member)
-        ->post('/billing/checkout', ['plan_code' => 'standard'])
+        ->post('/billing/checkout', [
+            'plan_code' => 'standard',
+            'subscription_attempt_token' => (string) Str::ulid(),
+        ])
         ->assertForbidden();
 });
 
@@ -80,7 +84,10 @@
     [, $owner] = createOrganizationWithOwner();
 
     $this->actingAs($owner)
-        ->post('/billing/checkout', ['plan_code' => 'no-such-plan'])
+        ->post('/billing/checkout', [
+            'plan_code' => 'no-such-plan',
+            'subscription_attempt_token' => (string) Str::ulid(),
+        ])
         ->assertSessionHasErrors('plan_code');
 });
 
@@ -90,6 +97,7 @@
     $this->actingAs($owner)
         ->post('/billing/checkout', [
             'plan_code' => 'standard',
+            'subscription_attempt_token' => (string) Str::ulid(),
             'organization_id' => $organization->id,
         ])
         ->assertSessionHasErrors('organization_id');
@@ -105,12 +113,16 @@
     [, $owner] = createOrganizationWithOwner();
     $this->app->bind(StripeGatewayInterface::class, FakeStripeGateway::class);
 
-    $response = $this->actingAs($owner)->post('/billing/checkout', ['plan_code' => 'standard']);
+    $response = $this->actingAs($owner)->post('/billing/checkout', [
+        'plan_code' => 'standard',
+        'subscription_attempt_token' => (string) Str::ulid(),
+    ]);
 
-    // 非 Inertia リクエストでは Inertia::location は 302 redirect を返す
+    // 非 Inertia リクエストでは Inertia::location は 302 redirect を返す。
+    // P9: cancel URL は /billing/plans (fake gateway は cancel URL ベースの中立帰還)。
     $response->assertStatus(302);
     $location = $response->headers->get('Location');
-    expect($location)->toContain('/billing')
+    expect($location)->toContain('/billing/plans')
         ->and($location)->toContain('fake_external=stripe');
 });
 
diff --git a/tests/Feature/Billing/BillingPlansPageTest.php b/tests/Feature/Billing/BillingPlansPageTest.php
index 100b26c..394bb87 100644
--- a/tests/Feature/Billing/BillingPlansPageTest.php
+++ b/tests/Feature/Billing/BillingPlansPageTest.php
@@ -5,6 +5,8 @@
 use App\Models\User;
 use App\Services\Billing\Contracts\StripeGatewayInterface;
 use App\Services\Billing\Fakes\FakeStripeGateway;
+use Illuminate\Support\Str;
+use Inertia\Testing\AssertableInertia;
 use Inertia\Testing\AssertableInertia as Assert;
 
 /*
@@ -90,12 +92,35 @@
     $this->actingAs($user)->get('/billing/plans')->assertNotFound();
 });
 
-test('POST /billing/checkout は plan_code のみで成立する (attempt token を要求しない)', function (): void {
+test('POST /billing/checkout は plan_code + subscription_attempt_token で成立する (P9 の冪等 token 必須)', function (): void {
     [, $owner] = createOrganizationWithOwner(grandfatherFreePlan: false);
     $this->app->bind(StripeGatewayInterface::class, FakeStripeGateway::class);
 
-    $response = $this->actingAs($owner)->post('/billing/checkout', ['plan_code' => 'standard']);
+    $response = $this->actingAs($owner)->post('/billing/checkout', [
+        'plan_code' => 'standard',
+        'subscription_attempt_token' => (string) Str::ulid(),
+    ]);
 
     $response->assertStatus(302);
     expect($response->headers->get('Location'))->toContain('fake_external=stripe');
 });
+
+test('Billing/Plans の props に render 単位の subscriptionAttemptToken が載る', function (): void {
+    [, $owner] = createOrganizationWithOwner();
+
+    $first = null;
+    $this->actingAs($owner)->get('/billing/plans')->assertOk()->assertInertia(
+        function (AssertableInertia $page) use (&$first): void {
+            $token = $page->toArray()['props']['page']['subscriptionAttemptToken'];
+            expect($token)->toBeString()->not->toBe('');
+            $first = $token;
+        },
+    );
+
+    // render ごとに新しい token (1 render = 1 token)
+    $this->actingAs($owner)->get('/billing/plans')->assertOk()->assertInertia(
+        function (AssertableInertia $page) use ($first): void {
+            expect($page->toArray()['props']['page']['subscriptionAttemptToken'])->not->toBe($first);
+        },
+    );
+});
diff --git a/tests/Feature/Billing/CheckoutStaleThresholdTest.php b/tests/Feature/Billing/CheckoutStaleThresholdTest.php
new file mode 100644
index 0000000..7ef30c7
--- /dev/null
+++ b/tests/Feature/Billing/CheckoutStaleThresholdTest.php
@@ -0,0 +1,148 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\Billing\OnboardingBillingState;
+use App\Enums\CheckoutIntent;
+use App\Enums\CheckoutSessionStatus;
+use App\Models\Billing\BillingCheckoutSession;
+use App\Services\Billing\BillingAccess;
+use App\Services\Billing\Contracts\StripeGatewayInterface;
+use Carbon\CarbonImmutable;
+use Illuminate\Support\Str;
+use Tests\Support\FakeStripeGateway;
+
+/*
+ * P9 (C-1): live 判定の単一出典。
+ *
+ * 「pending 行が live か」の判定は BillingCheckoutSession の述語だけが定義し、
+ * state() / startCheckout() の段 2・3・4 / 日次 sweeper の 4 経路が共有する。
+ * 判定の正しさを sweeper の実行タイミングに依存させない (= sweeper 未実行でも成立する)。
+ */
+
+beforeEach(function (): void {
+    $this->app->singleton(StripeGatewayInterface::class, fn (): FakeStripeGateway => new FakeStripeGateway);
+});
+
+test('2 日前の stale pending があっても新 token の POST は新規 Checkout を作る (warning に落ちない)', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    BillingCheckoutSession::factory()
+        ->for($organization)
+        ->initiatedBy((int) $owner->id)
+        ->withAttempt((string) Str::ulid(), 'standard')
+        ->stale()
+        ->create();
+
+    $this->actingAs($owner)->post('/billing/checkout', [
+        'plan_code' => 'standard',
+        'subscription_attempt_token' => (string) Str::ulid(),
+    ])->assertRedirectContains('https://checkout.stripe.test/');
+
+    expect(BillingCheckoutSession::query()->where('organization_id', $organization->id)->count())->toBe(2);
+
+    /** @var FakeStripeGateway $fake */
+    $fake = app(StripeGatewayInterface::class);
+    expect($fake->created)->toHaveCount(1);
+    // stale な行は Stripe 側で既に expire 済みのため照会しない (無駄な外部 API を撃たない)。
+    expect($fake->expired)->toHaveCount(0);
+});
+
+test('同 token + stale pending の再送は ?retry=1、境界内 (23h59m) なら既存 URL へ replay する', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+
+    // (a) stale (25h 前) → retry
+    $staleToken = (string) Str::ulid();
+    BillingCheckoutSession::factory()
+        ->for($organization)
+        ->initiatedBy((int) $owner->id)
+        ->withAttempt($staleToken, 'standard')
+        ->create(['created_at' => CarbonImmutable::now()->subHours(25)]);
+
+    $this->actingAs($owner)->post('/billing/checkout', [
+        'plan_code' => 'standard',
+        'subscription_attempt_token' => $staleToken,
+    ])->assertRedirect('/billing?retry=1');
+
+    // (b) 境界内 (23h59m 前) → replay (既存 checkout_url)
+    [$org2, $owner2] = createOrganizationWithOwner('境界内組織');
+    $liveToken = (string) Str::ulid();
+    BillingCheckoutSession::factory()
+        ->for($org2)
+        ->initiatedBy((int) $owner2->id)
+        ->withAttempt($liveToken, 'standard')
+        ->create(['created_at' => CarbonImmutable::now()->subMinutes(23 * 60 + 59)]);
+
+    $this->actingAs($owner2)->post('/billing/checkout', [
+        'plan_code' => 'standard',
+        'subscription_attempt_token' => $liveToken,
+    ])->assertRedirect('https://checkout.stripe.com/dummy');
+});
+
+test('state() と startCheckout() は同一閾値を共有する (23h = PendingCheckout / 25h = ExpiredCheckout)', function (): void {
+    $access = app(BillingAccess::class);
+
+    // 23h 前 = live → PendingCheckout、新規作成しない
+    [$org23, $owner23] = createOrganizationWithOwner('23h 組織', grandfatherFreePlan: false);
+    BillingCheckoutSession::factory()
+        ->for($org23)
+        ->initiatedBy((int) $owner23->id)
+        ->withAttempt((string) Str::ulid(), 'standard')
+        ->create(['created_at' => CarbonImmutable::now()->subHours(23)]);
+
+    expect($access->state($org23))->toBe(OnboardingBillingState::PendingCheckout);
+
+    $this->actingAs($owner23)
+        ->from('/billing/plans')
+        ->post('/billing/checkout', [
+            'plan_code' => 'standard',
+            'subscription_attempt_token' => (string) Str::ulid(),
+        ])
+        ->assertSessionHas('warning');
+    expect(BillingCheckoutSession::query()->where('organization_id', $org23->id)->count())->toBe(1);
+
+    // 25h 前 = stale → ExpiredCheckout、新 token で新規作成できる
+    [$org25, $owner25] = createOrganizationWithOwner('25h 組織', grandfatherFreePlan: false);
+    BillingCheckoutSession::factory()
+        ->for($org25)
+        ->initiatedBy((int) $owner25->id)
+        ->withAttempt((string) Str::ulid(), 'standard')
+        ->create(['created_at' => CarbonImmutable::now()->subHours(25)]);
+
+    expect($access->state($org25))->toBe(OnboardingBillingState::ExpiredCheckout);
+
+    $this->actingAs($owner25)->post('/billing/checkout', [
+        'plan_code' => 'standard',
+        'subscription_attempt_token' => (string) Str::ulid(),
+    ])->assertRedirectContains('https://checkout.stripe.test/');
+    expect(BillingCheckoutSession::query()->where('organization_id', $org25->id)->count())->toBe(2);
+});
+
+test('state() は read 経路で DB 行を書き換えない (stale pending は in-memory 判定のみ)', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner(grandfatherFreePlan: false);
+    $row = BillingCheckoutSession::factory()
+        ->for($organization)
+        ->initiatedBy((int) $owner->id)
+        ->stale()
+        ->create();
+
+    expect(app(BillingAccess::class)->state($organization))->toBe(OnboardingBillingState::ExpiredCheckout);
+    expect($row->refresh()->status)->toBe(CheckoutSessionStatus::Pending->value);
+});
+
+test('billing:reconcile-schedules は stale pending だけを Expired にする (intent で絞らない)', function (): void {
+    [$organization] = createOrganizationWithOwner();
+
+    $staleSub = BillingCheckoutSession::factory()->for($organization)->stale()->create();
+    $staleSetup = BillingCheckoutSession::factory()->for($organization)->setupPaymentMethod()->stale()->create();
+    $live = BillingCheckoutSession::factory()->for($organization)->create();
+
+    $this->artisan('billing:reconcile-schedules')
+        ->expectsOutputToContain('expired=2')
+        ->assertSuccessful();
+
+    expect($staleSub->refresh()->status)->toBe(CheckoutSessionStatus::Expired->value);
+    // P8a の setup 行も収束する (sweeper だけは intent 非スコープ)
+    expect($staleSetup->refresh()->status)->toBe(CheckoutSessionStatus::Expired->value);
+    expect($staleSetup->intent)->toBe(CheckoutIntent::SetupPaymentMethod->value);
+    expect($live->refresh()->status)->toBe(CheckoutSessionStatus::Pending->value);
+});
diff --git a/tests/Feature/Billing/SubscriptionCheckoutGuardTest.php b/tests/Feature/Billing/SubscriptionCheckoutGuardTest.php
index dbbf010..f5709e4 100644
--- a/tests/Feature/Billing/SubscriptionCheckoutGuardTest.php
+++ b/tests/Feature/Billing/SubscriptionCheckoutGuardTest.php
@@ -6,10 +6,13 @@
 use App\Exceptions\Billing\StripePriceNotSyncedException;
 use App\Models\Billing\Plan;
 use App\Models\Billing\PlanPrice;
+use App\Models\Organization;
+use App\Models\User;
 use App\Services\Billing\Contracts\StripeGatewayInterface;
 use App\Services\Billing\Fakes\FakeStripeGateway;
 use App\Services\Billing\SubscriptionService;
 use Carbon\CarbonImmutable;
+use Illuminate\Support\Str;
 use Illuminate\Validation\ValidationException;
 use Webmozart\Assert\Assert;
 
@@ -20,6 +23,9 @@
  *   (deploy 手順の sync 漏れで test Price の本番課金が発生する事故を DB レベルで塞ぐ)。
  * - assertStripeBillablePlan: Personal (free) / Enterprise / 未知 code は fail-closed で 422。
  * - 有効なサブスク保持組織の再 checkout は fail-closed (プラン変更は Portal 経由)。
+ *
+ * P9: シグネチャは冪等マシン (org, user, plan, urls, attemptToken, funding) に変わった。
+ * base Price は plan から service が解決する。
  */
 
 function checkoutGuardService(): SubscriptionService
@@ -27,49 +33,56 @@ function checkoutGuardService(): SubscriptionService
     return app(SubscriptionService::class);
 }
 
+function checkoutGuardPlan(string $planCode = 'standard'): Plan
+{
+    return Plan::query()->where('code', $planCode)->firstOrFail();
+}
+
 function checkoutGuardPrice(string $planCode = 'standard'): PlanPrice
 {
-    $price = Plan::query()->where('code', $planCode)->firstOrFail()
-        ->currentPrice(PlanPriceKind::Base);
+    $price = checkoutGuardPlan($planCode)->currentPrice(PlanPriceKind::Base);
     Assert::isInstanceOf($price, PlanPrice::class, "{$planCode} の current base price が未 seed");
 
     return $price;
 }
 
+function startGuardCheckout(Organization $organization, User $user, ?Plan $plan = null): string
+{
+    $result = checkoutGuardService()->startCheckout(
+        $organization,
+        $user,
+        $plan ?? checkoutGuardPlan(),
+        'https://example.test/return',
+        'https://example.test/return',
+        (string) Str::ulid(),
+        null,
+    );
+
+    return (string) $result->url;
+}
+
 beforeEach(function (): void {
     $this->app->bind(StripeGatewayInterface::class, FakeStripeGateway::class);
 });
 
 test('非 production では未 sync の test mode Price でも checkout できる', function (): void {
-    [$organization] = createOrganizationWithOwner();
+    [$organization, $owner] = createOrganizationWithOwner();
     $price = checkoutGuardPrice();
     $price->forceFill(['livemode' => false, 'synced_at' => null])->save();
 
-    $redirect = checkoutGuardService()->startCheckout(
-        $organization,
-        $price,
-        'https://example.test/return',
-        'https://example.test/return',
-    );
-
-    expect($redirect->url)->toContain('fake_external=stripe');
+    expect(startGuardCheckout($organization, $owner))->toContain('fake_external=stripe');
 });
 
 test('production では未 sync / test mode の Price を StripePriceNotSyncedException で拒否する', function (bool $livemode, ?string $syncedAt): void {
     $this->app->detectEnvironment(fn (): string => 'production');
-    [$organization] = createOrganizationWithOwner();
+    [$organization, $owner] = createOrganizationWithOwner();
     $price = checkoutGuardPrice();
     $price->forceFill([
         'livemode' => $livemode,
         'synced_at' => $syncedAt === null ? null : CarbonImmutable::now(),
     ])->save();
 
-    checkoutGuardService()->startCheckout(
-        $organization,
-        $price,
-        'https://example.test/return',
-        'https://example.test/return',
-    );
+    startGuardCheckout($organization, $owner);
 })->with([
     'test mode Price (livemode=false)' => [false, 'now'],
     'sync 未実施 (synced_at=null)' => [true, null],
@@ -78,62 +91,37 @@ function checkoutGuardPrice(string $planCode = 'standard'): PlanPrice
 
 test('production でも livemode + synced_at 済みの Price なら checkout できる', function (): void {
     $this->app->detectEnvironment(fn (): string => 'production');
-    [$organization] = createOrganizationWithOwner();
+    [$organization, $owner] = createOrganizationWithOwner();
     $price = checkoutGuardPrice();
     $price->forceFill(['livemode' => true, 'synced_at' => CarbonImmutable::now()])->save();
 
-    $redirect = checkoutGuardService()->startCheckout(
-        $organization,
-        $price,
-        'https://example.test/return',
-        'https://example.test/return',
-    );
-
-    expect($redirect->url)->toContain('fake_external=stripe');
+    expect(startGuardCheckout($organization, $owner))->toContain('fake_external=stripe');
 });
 
 test('Stripe 決済対象外プラン (personal) の checkout は 422 (validation)', function (): void {
-    [$organization] = createOrganizationWithOwner();
-    $price = checkoutGuardPrice();
+    [$organization, $owner] = createOrganizationWithOwner();
     // personal は Price を持たない (activate 経路) ため、Price 側の plan を差し替えて
     // 「validation を迂回して非対象プランの Price が渡る」経路を service 層で塞ぐことを固定する
     $personal = Plan::query()->where('code', 'personal')->firstOrFail();
-    $price->forceFill(['plan_id' => $personal->id])->save();
+    checkoutGuardPrice()->forceFill(['plan_id' => $personal->id])->save();
 
-    checkoutGuardService()->startCheckout(
-        $organization->fresh() ?? $organization,
-        $price->fresh() ?? $price,
-        'https://example.test/return',
-        'https://example.test/return',
-    );
+    startGuardCheckout($organization, $owner, $personal->fresh() ?? $personal);
 })->throws(ValidationException::class);
 
 test('既に有効なサブスクリプションがある組織の checkout は fail-closed', function (): void {
-    [$organization] = createOrganizationWithOwner();
+    [$organization, $owner] = createOrganizationWithOwner();
     createFakeSubscription($organization, status: 'active');
 
-    checkoutGuardService()->startCheckout(
-        $organization,
-        checkoutGuardPrice(),
-        'https://example.test/return',
-        'https://example.test/return',
-    );
+    startGuardCheckout($organization, $owner);
 })->throws(InvalidArgumentException::class, '既に有効なサブスクリプションがあります。プラン変更をご利用ください。');
 
 test('解約済み (猶予期間も終了) のサブスクだけを持つ組織は再 checkout できる', function (): void {
-    [$organization] = createOrganizationWithOwner();
+    [$organization, $owner] = createOrganizationWithOwner();
     // Cashier の valid() は ends_at で猶予期間を見るため、終了済みを明示する
     createFakeSubscription($organization, status: 'canceled')
         ->forceFill(['ends_at' => CarbonImmutable::now()->subDay()])->save();
 
-    $redirect = checkoutGuardService()->startCheckout(
-        $organization,
-        checkoutGuardPrice(),
-        'https://example.test/return',
-        'https://example.test/return',
-    );
-
-    expect($redirect->url)->toContain('fake_external=stripe');
+    expect(startGuardCheckout($organization, $owner))->toContain('fake_external=stripe');
 });
 
 test('有効サブスク保持組織の /billing/checkout は 500 にせず error flash で差し戻す', function (): void {
@@ -142,7 +130,10 @@ function checkoutGuardPrice(string $planCode = 'standard'): PlanPrice
 
     $this->actingAs($owner)
         ->from('/billing')
-        ->post('/billing/checkout', ['plan_code' => 'standard'])
+        ->post('/billing/checkout', [
+            'plan_code' => 'standard',
+            'subscription_attempt_token' => (string) Str::ulid(),
+        ])
         ->assertRedirect('/billing')
         ->assertSessionHas('error', '既に有効なサブスクリプションがあります。プラン変更をご利用ください。');
 });
diff --git a/tests/Feature/Billing/SubscriptionCheckoutIdempotencyTest.php b/tests/Feature/Billing/SubscriptionCheckoutIdempotencyTest.php
new file mode 100644
index 0000000..33853f0
--- /dev/null
+++ b/tests/Feature/Billing/SubscriptionCheckoutIdempotencyTest.php
@@ -0,0 +1,340 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\CheckoutIntent;
+use App\Enums\CheckoutSessionStatus;
+use App\Models\Billing\BillingCheckoutSession;
+use App\Services\Billing\Contracts\StripeGatewayInterface;
+use Illuminate\Support\Str;
+use Tests\Support\FakeStripeGateway;
+
+/*
+ * P9 (要件 1-7): サブスク checkout の冪等状態機械。
+ *
+ * - attempt_token 単位の冪等 (UNIQUE(org, intent, attempt_token) + Stripe idempotency key)
+ * - org-wide の live pending dedup (subscription は org 単位の singleton)
+ * - 他 org / 他 user の token は **認可より前に 404** (存在オラクル封じ)
+ * - P8a の intent=setup_payment_method 行と混線しない (intent 軸の token 空間分離)
+ */
+
+function subCheckoutFake(): FakeStripeGateway
+{
+    /** @var FakeStripeGateway $fake */
+    $fake = app(StripeGatewayInterface::class);
+
+    return $fake;
+}
+
+function subAttemptToken(): string
+{
+    return (string) Str::ulid();
+}
+
+beforeEach(function (): void {
+    $this->app->singleton(StripeGatewayInterface::class, fn (): FakeStripeGateway => new FakeStripeGateway);
+});
+
+test('同一 token + 同一 plan の 2 連投は 1 行に収束し既存 checkout_url へ replay する', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $token = subAttemptToken();
+
+    $first = $this->actingAs($owner)->post('/billing/checkout', [
+        'plan_code' => 'standard',
+        'subscription_attempt_token' => $token,
+    ]);
+    // Inertia::location は非 Inertia リクエストでは 302 (Inertia リクエストでは 409 + X-Inertia-Location)
+    $first->assertRedirectContains('https://checkout.stripe.test/');
+
+    $second = $this->actingAs($owner)->post('/billing/checkout', [
+        'plan_code' => 'standard',
+        'subscription_attempt_token' => $token,
+    ]);
+    $second->assertRedirectContains('https://checkout.stripe.test/');
+
+    expect(BillingCheckoutSession::query()->where('organization_id', $organization->id)->count())->toBe(1);
+    // Stripe 作成は 1 回だけ (2 回目は DB 行の checkout_url を再生)
+    expect(subCheckoutFake()->created)->toHaveCount(1);
+    expect($first->headers->get('Location'))->toBe($second->headers->get('Location'));
+});
+
+test('同一 token + 別 plan_code は 422 で、行も Stripe 呼び出しも増えない', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $token = subAttemptToken();
+
+    $this->actingAs($owner)->post('/billing/checkout', [
+        'plan_code' => 'standard',
+        'subscription_attempt_token' => $token,
+    ])->assertRedirectContains('https://checkout.stripe.test/');
+
+    $this->actingAs($owner)
+        ->from('/billing/plans')
+        ->post('/billing/checkout', [
+            'plan_code' => 'starter',
+            'subscription_attempt_token' => $token,
+        ])
+        ->assertInvalid(['plan_code']);
+
+    expect(BillingCheckoutSession::query()->where('organization_id', $organization->id)->count())->toBe(1);
+    expect(subCheckoutFake()->created)->toHaveCount(1);
+});
+
+test('idempotency_key は sub_start:{token} で、同 key の再呼び出しは同一 sessionId を返す', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $token = subAttemptToken();
+
+    $this->actingAs($owner)->post('/billing/checkout', [
+        'plan_code' => 'standard',
+        'subscription_attempt_token' => $token,
+    ])->assertRedirectContains('https://checkout.stripe.test/');
+
+    $row = BillingCheckoutSession::query()->where('organization_id', $organization->id)->firstOrFail();
+    expect($row->idempotency_key)->toBe('sub_start:'.$token);
+    expect(subCheckoutFake()->created[0]['idempotencyKey'])->toBe('sub_start:'.$token);
+
+    // key 空間の分離: チケット (purchase:) / カード登録 (auto-recharge-setup:) と衝突しない
+    expect($row->idempotency_key)->not->toStartWith('purchase:');
+    expect($row->idempotency_key)->not->toStartWith('auto-recharge-setup:');
+
+    // 同一 key の再呼び出しで同一 sessionId (Stripe idempotency replay と同じ収束特性)
+    $again = subCheckoutFake()->createSubscriptionCheckout(
+        $organization, 'price_test', 'https://a.test', 'https://b.test', [], 'sub_start:'.$token,
+    );
+    expect($again->sessionId)->toBe($row->stripe_session_id);
+});
+
+test('他 org の token は manageBilling を持つ owner でも 404 で、行が作られない', function (): void {
+    [$otherOrg, $otherOwner] = createOrganizationWithOwner('別組織');
+    [$organization, $owner] = createOrganizationWithOwner();
+
+    $token = subAttemptToken();
+    BillingCheckoutSession::factory()
+        ->for($otherOrg)
+        ->initiatedBy((int) $otherOwner->id)
+        ->withAttempt($token, 'standard')
+        ->create();
+
+    $this->actingAs($owner)->post('/billing/checkout', [
+        'plan_code' => 'standard',
+        'subscription_attempt_token' => $token,
+    ])->assertNotFound();
+
+    expect(BillingCheckoutSession::query()->where('organization_id', $organization->id)->exists())->toBeFalse();
+    expect(subCheckoutFake()->created)->toHaveCount(0);
+});
+
+test('同 org の他 user の token も 404 (token 所有者判定は actor スコープ)', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $other = attachOrganizationMember($organization);
+
+    $token = subAttemptToken();
+    BillingCheckoutSession::factory()
+        ->for($organization)
+        ->initiatedBy((int) $other->id)
+        ->withAttempt($token, 'standard')
+        ->create();
+
+    $this->actingAs($owner)->post('/billing/checkout', [
+        'plan_code' => 'standard',
+        'subscription_attempt_token' => $token,
+    ])->assertNotFound();
+
+    expect(BillingCheckoutSession::query()->where('organization_id', $organization->id)->count())->toBe(1);
+    expect(subCheckoutFake()->created)->toHaveCount(0);
+});
+
+test('completed 行の token 再送は ?replayed=1 へ倒し Stripe を呼ばない', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $token = subAttemptToken();
+
+    BillingCheckoutSession::factory()
+        ->for($organization)
+        ->initiatedBy((int) $owner->id)
+        ->withAttempt($token, 'standard')
+        ->completed()
+        ->create();
+
+    $this->actingAs($owner)->post('/billing/checkout', [
+        'plan_code' => 'standard',
+        'subscription_attempt_token' => $token,
+    ])->assertRedirect('/billing?replayed=1');
+
+    expect(subCheckoutFake()->created)->toHaveCount(0);
+});
+
+test('expired / failed 行の token 再送は ?retry=1 へ倒す', function (string $state): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $token = subAttemptToken();
+
+    $factory = BillingCheckoutSession::factory()
+        ->for($organization)
+        ->initiatedBy((int) $owner->id)
+        ->withAttempt($token, 'standard');
+
+    ($state === 'expired' ? $factory->expired() : $factory->failed())->create();
+
+    $this->actingAs($owner)->post('/billing/checkout', [
+        'plan_code' => 'standard',
+        'subscription_attempt_token' => $token,
+    ])->assertRedirect('/billing?retry=1');
+
+    expect(subCheckoutFake()->created)->toHaveCount(0);
+})->with(['expired', 'failed']);
+
+test('別 token・同 plan の live pending は org-wide dedup で warning に倒れる (別 user でも 1 本)', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $other = attachOrganizationMember($organization);
+
+    BillingCheckoutSession::factory()
+        ->for($organization)
+        ->initiatedBy((int) $other->id)
+        ->withAttempt(subAttemptToken(), 'standard')
+        ->create();
+
+    $this->actingAs($owner)
+        ->from('/billing/plans')
+        ->post('/billing/checkout', [
+            'plan_code' => 'standard',
+            'subscription_attempt_token' => subAttemptToken(),
+        ])
+        ->assertRedirect('/billing/plans')
+        ->assertSessionHas('warning', '既に進行中の Checkout があります。数分お待ちください。');
+
+    expect(BillingCheckoutSession::query()->where('organization_id', $organization->id)->count())->toBe(1);
+    expect(subCheckoutFake()->created)->toHaveCount(0);
+});
+
+test('別 token・別 plan の live pending: expire=complete は CheckoutInProgress で停止する', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    BillingCheckoutSession::factory()
+        ->for($organization)
+        ->initiatedBy((int) $owner->id)
+        ->withAttempt(subAttemptToken(), 'starter')
+        ->create();
+
+    subCheckoutFake()->expireResult = 'complete';
+
+    $this->actingAs($owner)
+        ->from('/billing/plans')
+        ->post('/billing/checkout', [
+            'plan_code' => 'standard',
+            'subscription_attempt_token' => subAttemptToken(),
+        ])
+        ->assertRedirect('/billing/plans')
+        ->assertSessionHas('error', '直前の決済が処理中です。数分お待ちください。');
+
+    expect(BillingCheckoutSession::query()->where('organization_id', $organization->id)->count())->toBe(1);
+    expect(subCheckoutFake()->created)->toHaveCount(0);
+});
+
+test('別 token・別 plan の live pending: expire が throw したら local を上書きせず停止する', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $old = BillingCheckoutSession::factory()
+        ->for($organization)
+        ->initiatedBy((int) $owner->id)
+        ->withAttempt(subAttemptToken(), 'starter')
+        ->create();
+
+    subCheckoutFake()->failOnExpire = true;
+
+    $this->actingAs($owner)
+        ->from('/billing/plans')
+        ->post('/billing/checkout', [
+            'plan_code' => 'standard',
+            'subscription_attempt_token' => subAttemptToken(),
+        ])
+        ->assertRedirect('/billing/plans')
+        ->assertSessionHas('error', '前回の決済セッションの整理に失敗しました。 数分後に再試行してください。');
+
+    expect($old->refresh()->status)->toBe(CheckoutSessionStatus::Pending->value);
+    expect(subCheckoutFake()->created)->toHaveCount(0);
+});
+
+test('別 token・別 plan の live pending: expire=expired なら旧行が Expired になり新規発行が続行する', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $old = BillingCheckoutSession::factory()
+        ->for($organization)
+        ->initiatedBy((int) $owner->id)
+        ->withAttempt(subAttemptToken(), 'starter')
+        ->create();
+
+    $this->actingAs($owner)->post('/billing/checkout', [
+        'plan_code' => 'standard',
+        'subscription_attempt_token' => subAttemptToken(),
+    ])->assertRedirectContains('https://checkout.stripe.test/');
+
+    expect($old->refresh()->status)->toBe(CheckoutSessionStatus::Expired->value);
+    expect(BillingCheckoutSession::query()->where('organization_id', $organization->id)->count())->toBe(2);
+    expect(subCheckoutFake()->created)->toHaveCount(1);
+});
+
+test('initiated_by_user_id が必ず非 null で記録される', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+
+    $this->actingAs($owner)->post('/billing/checkout', [
+        'plan_code' => 'standard',
+        'subscription_attempt_token' => subAttemptToken(),
+    ])->assertRedirectContains('https://checkout.stripe.test/');
+
+    $row = BillingCheckoutSession::query()->where('organization_id', $organization->id)->firstOrFail();
+    expect($row->initiated_by_user_id)->toBe((int) $owner->id);
+    expect($row->intent)->toBe(CheckoutIntent::SubscriptionStart->value);
+});
+
+test('P8a の setup 行が同 org に live pending でも段 2/3/4 に一切干渉しない', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $token = subAttemptToken();
+
+    // 同一 attempt_token を持つ setup 行 (intent 軸で token 空間が分かれている)
+    BillingCheckoutSession::factory()
+        ->for($organization)
+        ->initiatedBy((int) $owner->id)
+        ->setupPaymentMethod()
+        ->withAttemptToken($token)
+        ->create();
+
+    $this->actingAs($owner)->post('/billing/checkout', [
+        'plan_code' => 'standard',
+        'subscription_attempt_token' => $token,
+    ])->assertRedirectContains('https://checkout.stripe.test/');
+
+    expect(subCheckoutFake()->created)->toHaveCount(1);
+    expect(BillingCheckoutSession::query()
+        ->where('organization_id', $organization->id)
+        ->where('intent', CheckoutIntent::SubscriptionStart->value)
+        ->count())->toBe(1);
+});
+
+test('既に valid な subscription を持つ org は行を作らず error flash で停止する', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    createFakeSubscription($organization, status: 'active');
+
+    $this->actingAs($owner)
+        ->from('/billing/plans')
+        ->post('/billing/checkout', [
+            'plan_code' => 'standard',
+            'subscription_attempt_token' => subAttemptToken(),
+        ])
+        ->assertRedirect('/billing/plans')
+        ->assertSessionHas('error', '既に有効なサブスクリプションがあります。プラン変更をご利用ください。');
+
+    expect(BillingCheckoutSession::query()->where('organization_id', $organization->id)->exists())->toBeFalse();
+});
+
+test('subscription_attempt_token の欠落 / 非 ULID は 422', function (mixed $token): void {
+    [, $owner] = createOrganizationWithOwner();
+
+    $payload = ['plan_code' => 'standard'];
+    if ($token !== null) {
+        $payload['subscription_attempt_token'] = $token;
+    }
+
+    $this->actingAs($owner)
+        ->from('/billing/plans')
+        ->post('/billing/checkout', $payload)
+        ->assertInvalid(['subscription_attempt_token']);
+})->with([
+    'missing' => [null],
+    'not-ulid' => ['not-a-ulid'],
+    'empty' => [''],
+]);
diff --git a/tests/Feature/Billing/SubscriptionCheckoutWebhookRaceTest.php b/tests/Feature/Billing/SubscriptionCheckoutWebhookRaceTest.php
new file mode 100644
index 0000000..95987a8
--- /dev/null
+++ b/tests/Feature/Billing/SubscriptionCheckoutWebhookRaceTest.php
@@ -0,0 +1,211 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\Billing\OnboardingBillingState;
+use App\Enums\Billing\WebhookEventStatus;
+use App\Enums\CheckoutSessionStatus;
+use App\Models\Billing\BillingCheckoutSession;
+use App\Models\Billing\StripeWebhookEvent;
+use App\Models\Organization;
+use App\Services\Billing\BillingAccess;
+use App\Services\Billing\Contracts\StripeGatewayInterface;
+use Carbon\CarbonImmutable;
+use Illuminate\Contracts\Debug\ExceptionHandler;
+use Illuminate\Support\Str;
+use Laravel\Cashier\Events\WebhookReceived;
+use Tests\Support\FakeStripeGateway;
+
+/*
+ * P9 (要件 8 / C-2): サブスク契約 Checkout の webhook 状態遷移。
+ *
+ * 遷移条件は 1 定義のみ: status !== Completed の行だけを payment_status の判定結果へ遷移させ、
+ * Completed は終局 (再送は no-op = 冪等)。Failed / Expired からの遅延成功は受理する
+ * (それらは AI-CUE 側のローカルな見立てであり、決済の終局は Stripe が持つ)。
+ *
+ * **金銭の付与経路には一切触れない** (付与は invoice.paid、plan_code 同期は
+ * customer.subscription.* が真実源 = D7 境界)。
+ */
+
+/** @return array{Organization, BillingCheckoutSession} */
+function subWebhookFixture(string $status = CheckoutSessionStatus::Pending->value): array
+{
+    [$organization, $owner] = createOrganizationWithOwner();
+    $organization->stripe_id = 'cus_sub_test_1';
+    $organization->save();
+
+    $session = BillingCheckoutSession::factory()
+        ->for($organization)
+        ->initiatedBy((int) $owner->id)
+        ->withAttempt((string) Str::ulid(), 'standard')
+        ->create([
+            'stripe_session_id' => 'cs_test_sub_1',
+            'status' => $status,
+        ]);
+
+    return [$organization, $session];
+}
+
+/**
+ * @param  array<string, mixed>  $overrides
+ * @return array<string, mixed>
+ */
+function subCompletedPayload(Organization $organization, string $eventId = 'evt_sub_1', array $overrides = []): array
+{
+    $object = array_merge([
+        'id' => 'cs_test_sub_1',
+        'mode' => 'subscription',
+        'customer' => 'cus_sub_test_1',
+        'payment_status' => 'paid',
+        'subscription' => 'sub_test_1',
+        'metadata' => [
+            'purpose' => 'subscription_start',
+            'org_ref' => (string) $organization->id,
+            'plan_code' => 'standard',
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
+test('paid の completed で行が Completed になり、チケット付与も plan_code 同期も起きない', function (): void {
+    [$organization, $session] = subWebhookFixture();
+    $planCodeBefore = $organization->plan_code;
+
+    event(new WebhookReceived(subCompletedPayload($organization)));
+
+    expect($session->refresh()->status)->toBe(CheckoutSessionStatus::Completed->value);
+    expect($session->completed_at)->not->toBeNull();
+    // D7 境界: 台帳もプランも動かさない
+    expect($organization->ticketLedgerEntries()->count())->toBe(0);
+    expect($organization->refresh()->plan_code)->toBe($planCodeBefore);
+});
+
+test('同一 event の再送は終局 no-op (completed_at が更新されない)', function (): void {
+    [$organization, $session] = subWebhookFixture();
+
+    event(new WebhookReceived(subCompletedPayload($organization)));
+    $completedAt = $session->refresh()->completed_at;
+
+    // event_id 違いの再送 (claim skip を迂回) でも終局 no-op
+    event(new WebhookReceived(subCompletedPayload($organization, 'evt_sub_2')));
+
+    expect($session->refresh()->status)->toBe(CheckoutSessionStatus::Completed->value);
+    expect($session->completed_at?->toIso8601String())->toBe($completedAt?->toIso8601String());
+});
+
+test('Expired / Failed 行への遅延 completed (paid) は Completed として受理する', function (string $status): void {
+    [$organization, $session] = subWebhookFixture($status);
+
+    event(new WebhookReceived(subCompletedPayload($organization)));
+
+    expect($session->refresh()->status)->toBe(CheckoutSessionStatus::Completed->value);
+})->with([
+    'expired' => [CheckoutSessionStatus::Expired->value],
+    'failed' => [CheckoutSessionStatus::Failed->value],
+]);
+
+test('Completed 行への unpaid は遷移しない (終局)', function (): void {
+    [$organization, $session] = subWebhookFixture(CheckoutSessionStatus::Completed->value);
+
+    event(new WebhookReceived(subCompletedPayload($organization, overrides: ['payment_status' => 'unpaid'])));
+
+    expect($session->refresh()->status)->toBe(CheckoutSessionStatus::Completed->value);
+});
+
+test('unpaid は Failed へ、payment_status 欠落 / 未知値は遷移しない', function (?string $paymentStatus, string $expected): void {
+    [$organization, $session] = subWebhookFixture();
+
+    $overrides = $paymentStatus === null ? ['payment_status' => null] : ['payment_status' => $paymentStatus];
+    event(new WebhookReceived(subCompletedPayload($organization, overrides: $overrides)));
+
+    expect($session->refresh()->status)->toBe($expected);
+})->with([
+    'unpaid' => ['unpaid', CheckoutSessionStatus::Failed->value],
+    'null' => [null, CheckoutSessionStatus::Pending->value],
+    'unknown' => ['no_payment_yet', CheckoutSessionStatus::Pending->value],
+]);
+
+test('Expired 行への unpaid は Failed になる', function (): void {
+    [$organization, $session] = subWebhookFixture(CheckoutSessionStatus::Expired->value);
+
+    event(new WebhookReceived(subCompletedPayload($organization, overrides: ['payment_status' => 'unpaid'])));
+
+    expect($session->refresh()->status)->toBe(CheckoutSessionStatus::Failed->value);
+});
+
+test('cancel 相当 (webhook が来ない) では行が Pending のまま、2 日後は ExpiredCheckout で再開できる', function (): void {
+    $this->app->singleton(StripeGatewayInterface::class, fn (): FakeStripeGateway => new FakeStripeGateway);
+
+    [$organization, $owner] = createOrganizationWithOwner(grandfatherFreePlan: false);
+    $session = BillingCheckoutSession::factory()
+        ->for($organization)
+        ->initiatedBy((int) $owner->id)
+        ->withAttempt((string) Str::ulid(), 'standard')
+        ->create(['created_at' => CarbonImmutable::now()->subDays(2)]);
+
+    expect($session->refresh()->status)->toBe(CheckoutSessionStatus::Pending->value);
+    expect(app(BillingAccess::class)->state($organization))->toBe(OnboardingBillingState::ExpiredCheckout);
+    // state() 実行で行は書き換わらない
+    expect($session->refresh()->status)->toBe(CheckoutSessionStatus::Pending->value);
+
+    $this->actingAs($owner)->post('/billing/checkout', [
+        'plan_code' => 'standard',
+        'subscription_attempt_token' => (string) Str::ulid(),
+    ])->assertRedirectContains('https://checkout.stripe.test/');
+});
+
+test('行不在の completed は retryable failure (silent 付与しない)', function (): void {
+    [$organization] = createOrganizationWithOwner();
+    $organization->stripe_id = 'cus_sub_test_1';
+    $organization->save();
+
+    app()->instance(ExceptionHandler::class, Mockery::spy(ExceptionHandler::class));
+
+    expect(fn () => event(new WebhookReceived(subCompletedPayload($organization))))
+        ->toThrow(RuntimeException::class);
+
+    expect(StripeWebhookEvent::query()->where('event_id', 'evt_sub_1')->firstOrFail()->status)
+        ->toBe(WebhookEventStatus::Failed);
+});
+
+test('customer / metadata.org_ref の照合不一致は throw する (tenant キー不信)', function (array $overrides): void {
+    [$organization, $session] = subWebhookFixture();
+
+    app()->instance(ExceptionHandler::class, Mockery::spy(ExceptionHandler::class));
+
+    expect(fn () => event(new WebhookReceived(subCompletedPayload($organization, overrides: $overrides))))
+        ->toThrow(RuntimeException::class);
+
+    expect($session->refresh()->status)->toBe(CheckoutSessionStatus::Pending->value);
+})->with([
+    'customer 不一致' => [['customer' => 'cus_other']],
+    'org_ref 不一致' => [['metadata' => ['purpose' => 'subscription_start', 'org_ref' => '99999', 'plan_code' => 'standard']]],
+]);
+
+test('purpose ディスパッチは排他: ticket_purchase / mode=setup は settleSubscriptionCheckout に入らない', function (): void {
+    [$organization, $session] = subWebhookFixture();
+
+    // purpose=ticket_purchase の payload を投げても subscription 行は動かない
+    // (ticket 側は追跡行不在で throw するため purpose 不一致だけを見る payload にする)
+    $payload = subCompletedPayload($organization, 'evt_other_1', [
+        'mode' => 'payment',
+        'metadata' => ['purpose' => 'other_purpose'],
+    ]);
+    event(new WebhookReceived($payload));
+
+    expect($session->refresh()->status)->toBe(CheckoutSessionStatus::Pending->value);
+
+    // mode=setup + purpose=auto_recharge_setup は P8a 経路 (subscription 行に触れない)
+    $setupPayload = subCompletedPayload($organization, 'evt_other_2', [
+        'mode' => 'setup',
+        'metadata' => ['purpose' => 'other_setup'],
+    ]);
+    event(new WebhookReceived($setupPayload));
+
+    expect($session->refresh()->status)->toBe(CheckoutSessionStatus::Pending->value);
+});
diff --git a/tests/Feature/Billing/SubscriptionPmReuseTest.php b/tests/Feature/Billing/SubscriptionPmReuseTest.php
new file mode 100644
index 0000000..4bd7bf3
--- /dev/null
+++ b/tests/Feature/Billing/SubscriptionPmReuseTest.php
@@ -0,0 +1,444 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\Billing\AutoRechargeDisabledReason;
+use App\Enums\Billing\BillingNotificationType;
+use App\Enums\Billing\SignupFundingChoice;
+use App\Enums\CheckoutSessionStatus;
+use App\Jobs\Billing\ReuseSubscriptionPaymentMethodJob;
+use App\Models\Billing\BillingCheckoutSession;
+use App\Models\Billing\BillingNotification;
+use App\Models\Billing\TicketAutoRecharge;
+use App\Models\Billing\TicketAutoRechargeAttempt;
+use App\Models\Organization;
+use App\Services\Billing\AutoRechargeService;
+use App\Services\Billing\Contracts\AutoRechargeGatewayInterface;
+use App\Services\Billing\Contracts\StripeGatewayInterface;
+use Carbon\CarbonImmutable;
+use Illuminate\Support\Facades\Queue;
+use Illuminate\Support\Str;
+use Laravel\Cashier\Events\WebhookReceived;
+use Tests\Support\FakeAutoRechargeGateway;
+use Tests\Support\FakeStripeGateway;
+
+/*
+ * P9 / T1004: 有償契約の決済カードをオートリチャージへ流用する。
+ *
+ * 3 段の fail-closed:
+ *  (1) Request 層で consent_version の現行版一致を **checkout 開始前**に検証 (422)
+ *  (2) recordPreConsent は enabled=false の同意 row のみ (課金経路に触れない)
+ *  (3) applyReusedPaymentMethod が **適格性先行** — 不適格なら Stripe にも DB にも触らない
+ */
+
+beforeEach(function (): void {
+    $this->gateway = new FakeAutoRechargeGateway;
+    app()->instance(AutoRechargeGatewayInterface::class, $this->gateway);
+    $this->service = app(AutoRechargeService::class);
+});
+
+function pmReuseNotificationCount(Organization $organization): int
+{
+    return BillingNotification::query()
+        ->where('organization_id', $organization->getKey())
+        ->where('type', BillingNotificationType::AutoRechargeEnabled->value)
+        ->count();
+}
+
+/** @return array{Organization, BillingCheckoutSession} */
+function pmReuseFixture(?string $fundingChoice = SignupFundingChoice::AutoRecharge->value): array
+{
+    [$organization, $owner] = createOrganizationWithOwner();
+    $organization->stripe_id = 'cus_pmreuse_1';
+    $organization->save();
+
+    $session = BillingCheckoutSession::factory()
+        ->for($organization)
+        ->initiatedBy((int) $owner->id)
+        ->withAttempt((string) Str::ulid(), 'standard')
+        ->create([
+            'stripe_session_id' => 'cs_test_pmreuse_1',
+            'funding_choice' => $fundingChoice,
+        ]);
+
+    return [$organization, $session];
+}
+
+/**
+ * @param  array<string, mixed>  $overrides
+ * @return array<string, mixed>
+ */
+function pmReusePayload(Organization $organization, string $eventId = 'evt_pmreuse_1', array $overrides = []): array
+{
+    $object = array_merge([
+        'id' => 'cs_test_pmreuse_1',
+        'mode' => 'subscription',
+        'customer' => 'cus_pmreuse_1',
+        'payment_status' => 'paid',
+        'subscription' => 'sub_pmreuse_1',
+        'metadata' => [
+            'purpose' => 'subscription_start',
+            'org_ref' => (string) $organization->id,
+            'plan_code' => 'standard',
+        ],
+    ], $overrides);
+
+    return ['id' => $eventId, 'type' => 'checkout.session.completed', 'data' => ['object' => $object]];
+}
+
+// ------------------------------------------------------------------
+// dispatch 条件 (webhook 同期処理)
+// ------------------------------------------------------------------
+
+test('funding=auto_recharge + paid の completed で marker が立ち Job が dispatch される', function (): void {
+    Queue::fake();
+    [$organization, $session] = pmReuseFixture();
+
+    event(new WebhookReceived(pmReusePayload($organization)));
+
+    expect($session->refresh()->status)->toBe(CheckoutSessionStatus::Completed->value);
+    expect($session->pm_reuse_dispatched_at)->not->toBeNull();
+    Queue::assertPushed(
+        ReuseSubscriptionPaymentMethodJob::class,
+        fn (ReuseSubscriptionPaymentMethodJob $job): bool => $job->organizationId === (int) $organization->id
+            && $job->stripeSubscriptionId === 'sub_pmreuse_1',
+    );
+});
+
+test('決済未確定 (unpaid / payment_status 欠落) では dispatch されず marker も立たない', function (mixed $paymentStatus): void {
+    Queue::fake();
+    [$organization, $session] = pmReuseFixture();
+
+    event(new WebhookReceived(pmReusePayload($organization, overrides: ['payment_status' => $paymentStatus])));
+
+    expect($session->refresh()->pm_reuse_dispatched_at)->toBeNull();
+    Queue::assertNotPushed(ReuseSubscriptionPaymentMethodJob::class);
+})->with([
+    'unpaid' => ['unpaid'],
+    'null' => [null],
+]);
+
+test('funding=later / null (Plans 経路) では dispatch されない', function (?string $funding): void {
+    Queue::fake();
+    [$organization, $session] = pmReuseFixture($funding);
+
+    event(new WebhookReceived(pmReusePayload($organization)));
+
+    expect($session->refresh()->status)->toBe(CheckoutSessionStatus::Completed->value);
+    expect($session->pm_reuse_dispatched_at)->toBeNull();
+    Queue::assertNotPushed(ReuseSubscriptionPaymentMethodJob::class);
+})->with([
+    'later' => [SignupFundingChoice::Later->value],
+    'null' => [null],
+]);
+
+test('subscription フィールドは string / expanded object の両形を受理し、それ以外は dispatch しない', function (mixed $subscription, bool $dispatched): void {
+    Queue::fake();
+    [$organization] = pmReuseFixture();
+
+    event(new WebhookReceived(pmReusePayload($organization, overrides: ['subscription' => $subscription])));
+
+    $dispatched
+        ? Queue::assertPushed(ReuseSubscriptionPaymentMethodJob::class)
+        : Queue::assertNotPushed(ReuseSubscriptionPaymentMethodJob::class);
+})->with([
+    // expand 指定の無い通常 payload は string ID = 本番の主経路
+    'string id' => ['sub_pmreuse_1', true],
+    'expanded object' => [['id' => 'sub_pmreuse_1', 'status' => 'active'], true],
+    'null' => [null, false],
+    'empty string' => ['', false],
+    'other type' => [123, false],
+]);
+
+test('C-2 結合: Expired 行への遅延 completed でも marker が立ち dispatch される / 再送では marker が更新されない', function (): void {
+    Queue::fake();
+    [$organization, $session] = pmReuseFixture();
+    $session->forceFill(['status' => CheckoutSessionStatus::Expired->value])->save();
+
+    event(new WebhookReceived(pmReusePayload($organization)));
+
+    $marker = $session->refresh()->pm_reuse_dispatched_at;
+    expect($marker)->not->toBeNull();
+
+    // 終局 no-op = 再送では marker が延びない
+    event(new WebhookReceived(pmReusePayload($organization, 'evt_pmreuse_2')));
+    expect($session->refresh()->pm_reuse_dispatched_at?->toIso8601String())->toBe($marker?->toIso8601String());
+    Queue::assertPushed(ReuseSubscriptionPaymentMethodJob::class, 1);
+});
+
+test('webhook 同期処理は外向き Stripe API を撃たない (PM 解決は Job 側のみ)', function (): void {
+    Queue::fake();
+    [$organization] = pmReuseFixture();
+
+    event(new WebhookReceived(pmReusePayload($organization)));
+
+    // settleSubscriptionCheckout は marker を立てて dispatch するだけ (retrieve しない)
+    expect($this->gateway->resolvedSubscriptions)->toHaveCount(0);
+    expect($this->gateway->defaultPaymentMethodsSet)->toHaveCount(0);
+});
+
+// ------------------------------------------------------------------
+// applyReusedPaymentMethod (適格性先行 fail-closed)
+// ------------------------------------------------------------------
+
+test('事前同意 (v2) があれば default PM 設定 + snapshot + enabled=true + 通知 1 通', function (): void {
+    [$organization] = createOrganizationWithOwner();
+    TicketAutoRecharge::factory()->for($organization)->preConsented()->create();
+
+    $enabledNow = $this->service->applyReusedPaymentMethod($organization, 'pm_reused_1');
+
+    expect($enabledNow)->toBeTrue();
+    expect($this->gateway->defaultPaymentMethodsSet)->toHaveCount(1);
+
+    $config = TicketAutoRecharge::query()->where('organization_id', $organization->id)->firstOrFail();
+    expect($config->enabled)->toBeTrue();
+    expect($config->stripe_payment_method_id)->toBe('pm_reused_1');
+    expect($config->failure_count)->toBe(0);
+    expect(pmReuseNotificationCount($organization))->toBe(1);
+});
+
+test('中核 fail-closed: 同意失効 (v1 残存) では Stripe にもローカルにも一切触れない', function (): void {
+    [$organization] = createOrganizationWithOwner();
+    $config = TicketAutoRecharge::factory()->for($organization)->preConsented()->create();
+    $config->forceFill(['consent_version' => 'v1'])->save();
+
+    $enabledNow = $this->service->applyReusedPaymentMethod($organization, 'pm_reused_1');
+
+    expect($enabledNow)->toBeFalse();
+    expect($this->gateway->defaultPaymentMethodsSet)->toHaveCount(0);
+    expect($config->refresh()->enabled)->toBeFalse();
+    expect($config->stripe_payment_method_id)->toBeNull();
+});
+
+test('config なし / disabled_reason あり は完全 no-op (gateway 呼び出し 0)', function (bool $withConfig): void {
+    [$organization] = createOrganizationWithOwner();
+    if ($withConfig) {
+        $config = TicketAutoRecharge::factory()->for($organization)->preConsented()->create();
+        $config->forceFill(['disabled_reason' => AutoRechargeDisabledReason::User])->save();
+    }
+
+    expect($this->service->applyReusedPaymentMethod($organization, 'pm_reused_1'))->toBeFalse();
+    expect($this->gateway->defaultPaymentMethodsSet)->toHaveCount(0);
+})->with(['config なし' => [false], 'disabled_reason あり' => [true]]);
+
+test('再実行 (enabled 遷移済み) は no-op で通知も再送されない', function (): void {
+    [$organization] = createOrganizationWithOwner();
+    TicketAutoRecharge::factory()->for($organization)->preConsented()->create();
+
+    expect($this->service->applyReusedPaymentMethod($organization, 'pm_reused_1'))->toBeTrue();
+    expect($this->service->applyReusedPaymentMethod($organization, 'pm_reused_1'))->toBeFalse();
+
+    expect(pmReuseNotificationCount($organization))->toBe(1);
+});
+
+test('空文字 PM は fail-fast (InvalidArgumentException)', function (): void {
+    [$organization] = createOrganizationWithOwner();
+    TicketAutoRecharge::factory()->for($organization)->preConsented()->create();
+
+    $this->service->applyReusedPaymentMethod($organization, '');
+})->throws(InvalidArgumentException::class);
+
+// ------------------------------------------------------------------
+// Job
+// ------------------------------------------------------------------
+
+test('Job 一気通貫: 事前同意 → PM 解決 → enabled=true', function (): void {
+    [$organization] = createOrganizationWithOwner();
+    TicketAutoRecharge::factory()->for($organization)->preConsented()->create();
+    $this->gateway->subscriptionPaymentMethodId = 'pm_from_subscription';
+
+    (new ReuseSubscriptionPaymentMethodJob((int) $organization->id, 'sub_x'))
+        ->handle($this->gateway, $this->service);
+
+    $config = TicketAutoRecharge::query()->where('organization_id', $organization->id)->firstOrFail();
+    expect($config->enabled)->toBeTrue();
+    expect($config->stripe_payment_method_id)->toBe('pm_from_subscription');
+});
+
+test('Job の軽量 guard: isAutoEnablePending=false なら Stripe retrieve を呼ばない', function (): void {
+    [$organization] = createOrganizationWithOwner(); // config なし = pending でない
+
+    (new ReuseSubscriptionPaymentMethodJob((int) $organization->id, 'sub_x'))
+        ->handle($this->gateway, $this->service);
+
+    expect($this->gateway->resolvedSubscriptions)->toHaveCount(0);
+    expect($this->gateway->defaultPaymentMethodsSet)->toHaveCount(0);
+});
+
+test('PM 解決不能 (null) は no-op で詰まない (例外を投げない)', function (): void {
+    [$organization] = createOrganizationWithOwner();
+    TicketAutoRecharge::factory()->for($organization)->preConsented()->create();
+    $this->gateway->subscriptionPaymentMethodId = null;
+
+    (new ReuseSubscriptionPaymentMethodJob((int) $organization->id, 'sub_x'))
+        ->handle($this->gateway, $this->service);
+
+    expect($this->gateway->resolvedSubscriptions)->toHaveCount(1);
+    expect($this->gateway->defaultPaymentMethodsSet)->toHaveCount(0);
+    expect(TicketAutoRecharge::query()->where('organization_id', $organization->id)->firstOrFail()->enabled)->toBeFalse();
+});
+
+test('org 不在は例外なしで return する', function (): void {
+    (new ReuseSubscriptionPaymentMethodJob(999999, 'sub_x'))->handle($this->gateway, $this->service);
+
+    expect($this->gateway->resolvedSubscriptions)->toHaveCount(0);
+});
+
+// ------------------------------------------------------------------
+// setupPending (窓) / 着地 flash / 同意 fail-closed
+// ------------------------------------------------------------------
+
+test('setupPending: 決済確定した auto_recharge 契約 + 有効な事前同意の待機中は true', function (): void {
+    [$organization] = createOrganizationWithOwner();
+    TicketAutoRecharge::factory()->for($organization)->preConsented()->create();
+    BillingCheckoutSession::factory()
+        ->for($organization)
+        ->completed()
+        ->fundingAutoRecharge()
+        ->pmReuseDispatched()
+        ->create();
+
+    expect($this->service->settingsFor($organization, true)->setupPending)->toBeTrue();
+});
+
+test('setupPending: 同意失効 / funding=later / marker なし / 30 分超は false', function (callable $arrange): void {
+    [$organization] = createOrganizationWithOwner();
+    $arrange($organization);
+
+    expect($this->service->settingsFor($organization, true)->setupPending)->toBeFalse();
+})->with([
+    '同意失効 (v1) では再同意導線を隠さない' => [function (Organization $org): void {
+        $config = TicketAutoRecharge::factory()->for($org)->preConsented()->create();
+        $config->forceFill(['consent_version' => 'v1'])->save();
+        BillingCheckoutSession::factory()->for($org)->completed()->fundingAutoRecharge()->pmReuseDispatched()->create();
+    }],
+    'funding=later の契約完了' => [function (Organization $org): void {
+        TicketAutoRecharge::factory()->for($org)->preConsented()->create();
+        BillingCheckoutSession::factory()->for($org)->completed()->pmReuseDispatched()->create();
+    }],
+    'marker なし (未決済 completed)' => [function (Organization $org): void {
+        TicketAutoRecharge::factory()->for($org)->preConsented()->create();
+        BillingCheckoutSession::factory()->for($org)->completed()->fundingAutoRecharge()->create();
+    }],
+    'dispatch から 30 分超' => [function (Organization $org): void {
+        TicketAutoRecharge::factory()->for($org)->preConsented()->create();
+        BillingCheckoutSession::factory()->for($org)->completed()->fundingAutoRecharge()
+            ->pmReuseDispatched(CarbonImmutable::now()->subMinutes(31))->create();
+    }],
+]);
+
+test('着地 flash: 自 org の auto_recharge 完了 session は ?highlight=auto-recharge へ 303', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    TicketAutoRecharge::factory()->for($organization)->preConsented()->create();
+    $session = BillingCheckoutSession::factory()
+        ->for($organization)
+        ->completed()
+        ->fundingAutoRecharge()
+        ->pmReuseDispatched()
+        ->create();
+
+    $this->actingAs($owner)
+        ->get('/billing?session_id='.$session->stripe_session_id)
+        ->assertStatus(303)
+        ->assertRedirect('/billing?highlight=auto-recharge')
+        ->assertSessionHas('info', fn (string $m): bool => str_contains($m, '自動的に有効になります'));
+});
+
+test('着地 flash: marker なし / 同意失効では確定表現を避けた誘導文言になる', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $session = BillingCheckoutSession::factory()
+        ->for($organization)
+        ->completed()
+        ->fundingAutoRecharge()
+        ->create();
+
+    $this->actingAs($owner)
+        ->get('/billing?session_id='.$session->stripe_session_id)
+        ->assertStatus(303)
+        ->assertSessionHas('info', 'お支払いを受け付けました。オートリチャージの設定はこの画面から確認できます。');
+});
+
+test('着地 flash: 他 org / setup_payment_method の session_id は 303 しない (IDOR 防御)', function (bool $otherOrg): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    [$foreign] = createOrganizationWithOwner('他組織');
+
+    $factory = BillingCheckoutSession::factory()
+        ->for($otherOrg ? $foreign : $organization)
+        ->completed()
+        ->fundingAutoRecharge();
+    $session = ($otherOrg ? $factory : $factory->setupPaymentMethod())->create();
+
+    $this->actingAs($owner)
+        ->get('/billing?session_id='.$session->stripe_session_id)
+        ->assertOk();
+})->with(['他 org' => [true], 'setup_payment_method' => [false]]);
+
+test('同意 fail-closed (Request 層): consent_version 欠落 / 旧版は 422 で行も Stripe 呼び出しも増えない', function (?string $consentVersion, string $expectedMessage): void {
+    $this->app->singleton(StripeGatewayInterface::class, fn (): FakeStripeGateway => new FakeStripeGateway);
+    [$organization, $owner] = createOrganizationWithOwner();
+
+    $payload = [
+        'plan_code' => 'standard',
+        'subscription_attempt_token' => (string) Str::ulid(),
+        'funding_choice' => SignupFundingChoice::AutoRecharge->value,
+    ];
+    if ($consentVersion !== null) {
+        $payload['consent_version'] = $consentVersion;
+    }
+
+    $this->actingAs($owner)
+        ->from('/onboarding/checkout')
+        ->post('/billing/checkout', $payload)
+        ->assertInvalid(['consent_version' => $expectedMessage]);
+
+    expect(TicketAutoRecharge::query()->where('organization_id', $organization->id)->exists())->toBeFalse();
+    expect(BillingCheckoutSession::query()->where('organization_id', $organization->id)->exists())->toBeFalse();
+
+    /** @var FakeStripeGateway $fake */
+    $fake = app(StripeGatewayInterface::class);
+    expect($fake->created)->toHaveCount(0);
+})->with([
+    '欠落' => [null, '自動購入への同意が必要です。'],
+    '旧版 v1' => ['v1', '自動購入の同意内容が更新されています。ページを再読み込みして内容を確認してください。'],
+]);
+
+test('同意記録の順序: recordPreConsent → startCheckout の順で走り、課金は発生しない', function (): void {
+    $this->app->singleton(StripeGatewayInterface::class, function (): FakeStripeGateway {
+        $fake = new FakeStripeGateway;
+        $fake->failOnCreate = true; // Checkout 作成が失敗しても同意 row は残る
+
+        return $fake;
+    });
+    [$organization, $owner] = createOrganizationWithOwner();
+
+    try {
+        $this->withoutExceptionHandling()->actingAs($owner)->post('/billing/checkout', [
+            'plan_code' => 'standard',
+            'subscription_attempt_token' => (string) Str::ulid(),
+            'funding_choice' => SignupFundingChoice::AutoRecharge->value,
+            'consent_version' => config()->string('billing.auto_recharge.consent_version'),
+        ]);
+    } catch (Throwable) {
+        // Checkout 作成の失敗そのものは本テストの検証対象ではない
+    }
+
+    $config = TicketAutoRecharge::query()->where('organization_id', $organization->id)->firstOrFail();
+    expect($config->enabled)->toBeFalse();
+    expect($config->consent_version)->toBe(config()->string('billing.auto_recharge.consent_version'));
+    expect(TicketAutoRechargeAttempt::query()->where('organization_id', $organization->id)->count())->toBe(0);
+});
+
+test("consent_version='v2' 改定の効果: v1 同意行は自動失効し PM 流用でも有効化されない", function (): void {
+    [$organization] = createOrganizationWithOwner();
+    $config = TicketAutoRecharge::factory()->for($organization)->preConsented()->create();
+    $config->forceFill(['consent_version' => 'v1'])->save();
+
+    expect($this->service->isAutoEnablePending($organization))->toBeFalse();
+    expect($this->service->settingsFor($organization, true)->pendingAutoEnable)->toBeFalse();
+
+    (new ReuseSubscriptionPaymentMethodJob((int) $organization->id, 'sub_x'))
+        ->handle($this->gateway, $this->service);
+
+    expect($config->refresh()->enabled)->toBeFalse();
+    expect($this->gateway->defaultPaymentMethodsSet)->toHaveCount(0);
+});
diff --git a/tests/Feature/Billing/UpdateBillingContactTest.php b/tests/Feature/Billing/UpdateBillingContactTest.php
new file mode 100644
index 0000000..850b6cc
--- /dev/null
+++ b/tests/Feature/Billing/UpdateBillingContactTest.php
@@ -0,0 +1,149 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\OrganizationRole;
+use App\Jobs\Billing\SyncBillingCustomerDetails;
+use App\Models\Organization;
+use Illuminate\Support\Facades\Queue;
+use Inertia\Testing\AssertableInertia;
+
+/*
+ * P9: 請求先連絡先の更新経路 (PATCH /billing/contact)。
+ * current-org スコープ (route parameter なし) + manageBilling。
+ * Stripe 同期は **email 変更時のみ** BillingCustomerSynchronizer 経由で発火する。
+ */
+
+test('email 変更時のみ Stripe 同期 job が dispatch される (name のみの変更では発火しない)', function (): void {
+    Queue::fake();
+    [$organization, $owner] = createOrganizationWithOwner();
+    $organization->forceFill(['stripe_id' => 'cus_contact_1'])->save();
+
+    $this->actingAs($owner)->patch('/billing/contact', [
+        'billing_contact_email' => 'billing@example.test',
+        'billing_contact_name' => '経理部',
+    ])->assertRedirect();
+    Queue::assertPushed(SyncBillingCustomerDetails::class, 1);
+
+    // name だけ変更 (email は同値) → 同期は増えない
+    $this->actingAs($owner)->patch('/billing/contact', [
+        'billing_contact_email' => 'billing@example.test',
+        'billing_contact_name' => '総務部',
+    ])->assertRedirect();
+    Queue::assertPushed(SyncBillingCustomerDetails::class, 1);
+
+    expect($organization->fresh()?->billing_contact_name)->toBe('総務部');
+});
+
+test('stripe_id 未設定の org では同期 job が dispatch されない', function (): void {
+    Queue::fake();
+    [$organization, $owner] = createOrganizationWithOwner();
+    expect($organization->stripe_id)->toBeNull();
+
+    $this->actingAs($owner)->patch('/billing/contact', [
+        'billing_contact_email' => 'billing@example.test',
+    ])->assertRedirect();
+
+    Queue::assertNotPushed(SyncBillingCustomerDetails::class);
+});
+
+test('宛名は空文字を null に畳む', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+
+    $this->actingAs($owner)->patch('/billing/contact', [
+        'billing_contact_email' => 'billing@example.test',
+        'billing_contact_name' => '   ',
+    ])->assertRedirect()->assertSessionHas('info', '請求先情報を更新しました。');
+
+    expect($organization->fresh()?->billing_contact_name)->toBeNull();
+});
+
+test('認可: member は 403', function (): void {
+    [$organization] = createOrganizationWithOwner();
+    $member = attachOrganizationMember($organization);
+    $member->forceFill(['current_organization_id' => $organization->id])->save();
+
+    $this->actingAs($member)->patch('/billing/contact', [
+        'billing_contact_email' => 'billing@example.test',
+    ])->assertForbidden();
+});
+
+test('認可: 未ログインは login へ redirect', function (): void {
+    createOrganizationWithOwner();
+
+    $this->patch('/billing/contact', [
+        'billing_contact_email' => 'billing@example.test',
+    ])->assertRedirect('/login');
+});
+
+test('current-org スコープ: org 切替後の PATCH は切替後 org だけを更新する', function (): void {
+    [$orgA, $owner] = createOrganizationWithOwner('組織 A');
+    [$orgB] = createOrganizationWithOwner('組織 B');
+    $orgB->users()->attach($owner);
+    $owner->addRole(OrganizationRole::Owner->value, $orgB->laratrust_team_id);
+    $owner->forceFill(['current_organization_id' => $orgB->id])->save();
+
+    $this->actingAs($owner)->patch('/billing/contact', [
+        'billing_contact_email' => 'b@example.test',
+    ])->assertRedirect();
+
+    expect($orgB->fresh()?->billing_contact_email)->toBe('b@example.test');
+    expect($orgA->fresh()?->billing_contact_email)->toBeNull();
+});
+
+test('payload 契約: 保護キー混入は 422 / email 欠落も 422', function (array $payload, string $field): void {
+    [, $owner] = createOrganizationWithOwner();
+
+    $this->actingAs($owner)
+        ->from('/billing')
+        ->patch('/billing/contact', $payload)
+        ->assertInvalid([$field]);
+})->with([
+    'organization_id 混入' => [['billing_contact_email' => 'a@example.test', 'organization_id' => 1], 'organization_id'],
+    'plan_id 混入' => [['billing_contact_email' => 'a@example.test', 'plan_id' => 1], 'plan_id'],
+    'email 欠落' => [[], 'billing_contact_email'],
+    'email 形式不正' => [['billing_contact_email' => 'not-an-email'], 'billing_contact_email'],
+]);
+
+test('routeNotificationForMail は billing_contact_email 正本 → owner email fallback', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+
+    // 未設定なら owner email
+    expect($organization->billingContactEmail())->toBe($owner->email);
+
+    $this->actingAs($owner)->patch('/billing/contact', [
+        'billing_contact_email' => 'billing@example.test',
+    ])->assertRedirect();
+
+    /** @var Organization $fresh */
+    $fresh = $organization->fresh();
+    expect($fresh->billingContactEmail())->toBe('billing@example.test');
+});
+
+test('Billing/Index の props に billingContact が載る (未設定なら fallbackEmail が owner)', function (): void {
+    [, $owner] = createOrganizationWithOwner();
+
+    $this->actingAs($owner)->get('/billing')->assertOk()->assertInertia(
+        fn (AssertableInertia $page) => $page
+            ->where('page.billingContact.email', null)
+            ->where('page.billingContact.name', null)
+            ->where('page.billingContact.fallbackEmail', $owner->email),
+    );
+});
+
+test('stripeEmail は請求先メール正本 → owner email fallback (宛名は Stripe へ送らない)', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+
+    expect($organization->stripeEmail())->toBe($owner->email);
+
+    $this->actingAs($owner)->patch('/billing/contact', [
+        'billing_contact_email' => 'billing@example.test',
+        'billing_contact_name' => '経理部',
+    ])->assertRedirect();
+
+    /** @var Organization $fresh */
+    $fresh = $organization->fresh();
+    expect($fresh->stripeEmail())->toBe('billing@example.test');
+    // 宛名は送信内容に含めない (stripeName は組織名のまま)
+    expect($fresh->stripeName())->toBe($organization->name);
+});
diff --git a/tests/Support/FakeAutoRechargeGateway.php b/tests/Support/FakeAutoRechargeGateway.php
index 5d84d1c..5e175d4 100644
--- a/tests/Support/FakeAutoRechargeGateway.php
+++ b/tests/Support/FakeAutoRechargeGateway.php
@@ -55,6 +55,15 @@ final class FakeAutoRechargeGateway implements AutoRechargeGatewayInterface
     /** true にすると terminateInvoice が throw する (終端失敗 → pending 維持の再現)。 */
     public bool $failOnTerminate = false;
 
+    /** @var list<string> resolveSubscriptionPaymentMethod を要求された subscription id (T1004) */
+    public array $resolvedSubscriptions = [];
+
+    /** resolveSubscriptionPaymentMethod の返り値 (null = 解決不能)。 */
+    public ?string $subscriptionPaymentMethodId = 'pm_test_subscription';
+
+    /** true にすると resolveSubscriptionPaymentMethod が throw する。 */
+    public bool $failOnResolveSubscriptionPaymentMethod = false;
+
     /** createSetupCheckout が返す url (null = 進行中 replay の再現)。 */
     public ?string $setupUrl = 'https://checkout.stripe.test/c/setup/cs_setup_test';
 
@@ -181,6 +190,17 @@ public function setDefaultPaymentMethod(Organization $organization, string $paym
         $this->defaultPaymentMethod = new DefaultPaymentMethodDto($paymentMethodId, 'visa', '4242');
     }
 
+    public function resolveSubscriptionPaymentMethod(string $stripeSubscriptionId): ?string
+    {
+        $this->resolvedSubscriptions[] = $stripeSubscriptionId;
+
+        if ($this->failOnResolveSubscriptionPaymentMethod) {
+            throw new RuntimeException('fake gateway: resolveSubscriptionPaymentMethod failed');
+        }
+
+        return $this->subscriptionPaymentMethodId;
+    }
+
     /** 有効化 fail-closed を通過させる (default PM ありの状態を注入する)。 */
     public function withDefaultPaymentMethod(string $paymentMethodId = 'pm_test_default'): self
     {
diff --git a/tests/Support/FakeStripeGateway.php b/tests/Support/FakeStripeGateway.php
new file mode 100644
index 0000000..50cb243
--- /dev/null
+++ b/tests/Support/FakeStripeGateway.php
@@ -0,0 +1,91 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support;
+
+use App\DataTransferObjects\Billing\CreatedCheckoutSession;
+use App\DataTransferObjects\Billing\ExternalBillingRedirect;
+use App\Models\Organization;
+use App\Services\Billing\Contracts\StripeGatewayInterface;
+use Carbon\CarbonImmutable;
+use RuntimeException;
+
+/**
+ * StripeGatewayInterface のテスト用 spy (Stripe に到達しない)。
+ *
+ * - createSubscriptionCheckout: 呼び出しを記録し、idempotency key から決定的な
+ *   session id / URL を返す (Stripe の idempotency replay と同じ収束特性を再現)
+ * - expireCheckoutSession: 呼び出しを記録し、$expireResult を返す ($failOnExpire で throw)
+ */
+final class FakeStripeGateway implements StripeGatewayInterface
+{
+    /** @var list<array{organizationId: int, stripePriceId: string, successUrl: string, cancelUrl: string, metadata: array<string, string>, idempotencyKey: string}> */
+    public array $created = [];
+
+    /** @var list<string> expire を要求された session id */
+    public array $expired = [];
+
+    /** expireCheckoutSession の返り値 ('expired' / 'complete' 等) */
+    public string $expireResult = 'expired';
+
+    /** true にすると expireCheckoutSession が throw する (Stripe 障害の再現) */
+    public bool $failOnExpire = false;
+
+    /** true にすると createSubscriptionCheckout が throw する */
+    public bool $failOnCreate = false;
+
+    /** @var list<int> syncCustomerDetails を呼ばれた org id */
+    public array $synced = [];
+
+    public function createSubscriptionCheckout(
+        Organization $organization,
+        string $stripePriceId,
+        string $successUrl,
+        string $cancelUrl,
+        array $metadata,
+        string $idempotencyKey,
+    ): CreatedCheckoutSession {
+        if ($this->failOnCreate) {
+            throw new RuntimeException('fake stripe: createSubscriptionCheckout failed');
+        }
+
+        $this->created[] = [
+            'organizationId' => (int) $organization->getKey(),
+            'stripePriceId' => $stripePriceId,
+            'successUrl' => $successUrl,
+            'cancelUrl' => $cancelUrl,
+            'metadata' => $metadata,
+            'idempotencyKey' => $idempotencyKey,
+        ];
+
+        $token = substr(hash('sha256', $idempotencyKey), 0, 32);
+
+        return new CreatedCheckoutSession(
+            sessionId: "cs_test_{$token}",
+            url: "https://checkout.stripe.test/c/pay/cs_test_{$token}",
+            expiresAt: CarbonImmutable::now()->addDay(),
+        );
+    }
+
+    public function expireCheckoutSession(string $stripeSessionId): string
+    {
+        if ($this->failOnExpire) {
+            throw new RuntimeException('fake stripe: expireCheckoutSession failed');
+        }
+
+        $this->expired[] = $stripeSessionId;
+
+        return $this->expireResult;
+    }
+
+    public function createPortalSession(Organization $organization, string $returnUrl): ExternalBillingRedirect
+    {
+        return new ExternalBillingRedirect('https://billing.stripe.test/p/session/test?return='.urlencode($returnUrl));
+    }
+
+    public function syncCustomerDetails(Organization $organization): void
+    {
+        $this->synced[] = (int) $organization->getKey();
+    }
+}
diff --git a/tests/Unit/Billing/FakeStripeGatewayTest.php b/tests/Unit/Billing/FakeStripeGatewayTest.php
index df2f44b..94ddd7c 100644
--- a/tests/Unit/Billing/FakeStripeGatewayTest.php
+++ b/tests/Unit/Billing/FakeStripeGatewayTest.php
@@ -11,16 +11,28 @@
  * - syncCustomerDetails は no-op (fake 環境が実 Stripe API を叩かない規約)
  */
 
-test('checkout は cancel URL ベースの中立帰還 URL を返す', function (): void {
-    $redirect = (new FakeStripeGateway)->createSubscriptionCheckout(
+test('checkout は cancel URL ベースの中立帰還 URL を返し、同一冪等キーで同一 sessionId に収束する', function (): void {
+    $gateway = new FakeStripeGateway;
+    $args = [
         Organization::factory()->make(),
         'price_test',
         'https://app.test/billing?success=1',
         'https://app.test/billing',
-    );
+        ['purpose' => 'subscription_start'],
+        'sub_start:01JQ0000000000000000000000',
+    ];
 
-    expect($redirect->url)->toContain('https://app.test/billing')
-        ->and($redirect->url)->toContain('fake_external=stripe');
+    $created = $gateway->createSubscriptionCheckout(...$args);
+
+    expect($created->url)->toContain('https://app.test/billing')
+        ->and($created->url)->toContain('fake_external=stripe');
+
+    // Stripe の idempotency replay と同じ収束特性 (同一 key = 同一 session)
+    expect($gateway->createSubscriptionCheckout(...$args)->sessionId)->toBe($created->sessionId);
+});
+
+test('expireCheckoutSession は expired を返す (fake は Stripe を叩かない)', function (): void {
+    expect((new FakeStripeGateway)->expireCheckoutSession('cs_test'))->toBe('expired');
 });
 
 test('portal は return URL ベースの中立帰還 URL を返す', function (): void {
diff --git a/tests/Unit/Billing/SubscriptionCheckoutPayloadInvariantTest.php b/tests/Unit/Billing/SubscriptionCheckoutPayloadInvariantTest.php
new file mode 100644
index 0000000..4522d86
--- /dev/null
+++ b/tests/Unit/Billing/SubscriptionCheckoutPayloadInvariantTest.php
@@ -0,0 +1,70 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Models\Organization;
+use App\Services\Billing\CashierStripeGateway;
+
+/*
+ * P9: subscription Checkout Session payload の invariant。**payload 変更の唯一の入口**。
+ *
+ * - subscription_data.metadata.{name,type} = 'default'
+ *   Cashier の WebhookController が `subscriptions` 行を作る際に読むラベル。落とすと
+ *   **課金成立なのに subscription 行が作られず** BillingAccess::state() が NoSubscription に
+ *   落ちて締め出しが起きる (P4 のゲート反転後は致命的)。
+ * - subscription_data.payment_settings.save_default_payment_method = 'on_subscription'
+ *   T1004 の PM 流用の第一候補 (subscription.default_payment_method) が埋まる前提。
+ * - promo / automatic tax を含まない (金額照合の前提を壊さない = チケット側と同一方針)。
+ */
+
+test('payload は mode=subscription で customer / line_items / metadata を含む', function (): void {
+    $organization = Organization::factory()->make();
+    $organization->stripe_id = 'cus_payload_1';
+
+    $payload = (new CashierStripeGateway)->buildSubscriptionSessionPayload(
+        $organization,
+        'price_standard',
+        'https://app.test/billing?session_id={CHECKOUT_SESSION_ID}',
+        'https://app.test/billing/plans',
+        ['purpose' => 'subscription_start', 'org_ref' => '1', 'plan_code' => 'standard'],
+    );
+
+    expect($payload['mode'])->toBe('subscription');
+    expect($payload['customer'])->toBe('cus_payload_1');
+    expect($payload['line_items'])->toBe([['price' => 'price_standard', 'quantity' => 1]]);
+    expect($payload['success_url'])->toContain('{CHECKOUT_SESSION_ID}');
+    expect($payload['cancel_url'])->toBe('https://app.test/billing/plans');
+    expect($payload['metadata']['purpose'])->toBe('subscription_start');
+});
+
+test('subscription_data は Cashier の name/type ラベルと save_default_payment_method を含む', function (): void {
+    $organization = Organization::factory()->make();
+    $organization->stripe_id = 'cus_payload_1';
+
+    $payload = (new CashierStripeGateway)->buildSubscriptionSessionPayload(
+        $organization, 'price_standard', 'https://a.test', 'https://b.test', [],
+    );
+
+    expect($payload['subscription_data']['metadata']['name'])->toBe('default');
+    expect($payload['subscription_data']['metadata']['type'])->toBe('default');
+    expect($payload['subscription_data']['payment_settings']['save_default_payment_method'])
+        ->toBe('on_subscription');
+});
+
+test('payload に allow_promotion_codes / automatic_tax を含めない', function (): void {
+    $organization = Organization::factory()->make();
+    $organization->stripe_id = 'cus_payload_1';
+
+    $payload = (new CashierStripeGateway)->buildSubscriptionSessionPayload(
+        $organization, 'price_standard', 'https://a.test', 'https://b.test', [],
+    );
+
+    expect($payload)->not->toHaveKey('allow_promotion_codes');
+    expect($payload)->not->toHaveKey('automatic_tax');
+});
+
+test('Stripe customer 未作成の組織では fail-fast する', function (): void {
+    (new CashierStripeGateway)->buildSubscriptionSessionPayload(
+        Organization::factory()->make(), 'price_standard', 'https://a.test', 'https://b.test', [],
+    );
+})->throws(InvalidArgumentException::class);
diff --git a/tests/js/pages/Billing/BillingContactForm.test.ts b/tests/js/pages/Billing/BillingContactForm.test.ts
new file mode 100644
index 0000000..2e005d2
--- /dev/null
+++ b/tests/js/pages/Billing/BillingContactForm.test.ts
@@ -0,0 +1,88 @@
+import { afterEach, describe, expect, it, vi } from "vitest";
+import { cleanup, fireEvent, render, screen } from "@testing-library/svelte";
+import BillingContactForm from "@/components/features/billing/BillingContactForm.svelte";
+import type { BillingContactShape } from "@/types/billing";
+
+const { routerPatchMock, pageState } = vi.hoisted(() => ({
+    routerPatchMock: vi.fn(),
+    pageState: { props: {} as Record<string, unknown> },
+}));
+
+vi.mock("@inertiajs/svelte", async (importOriginal) => ({
+    ...(await importOriginal<typeof import("@inertiajs/svelte")>()),
+    router: { patch: routerPatchMock },
+    page: pageState,
+}));
+
+/*
+ * P9: 請求先情報フォーム。
+ * - 未入力でも submit は disabled にしない (AGENTS.md 禁止事項 #8)。押下でサーバ文言を出す。
+ * - 未設定時は「実際の宛先は owner email」であることをサーバ確定値で示す。
+ */
+
+const baseContact: BillingContactShape = {
+    email: null,
+    name: null,
+    fallbackEmail: "owner@example.test",
+};
+
+afterEach(() => {
+    cleanup();
+    routerPatchMock.mockReset();
+    pageState.props = {};
+});
+
+describe("BillingContactForm", () => {
+    it("未入力でも submit は enabled のまま押下でき、PATCH が飛ぶ", async () => {
+        render(BillingContactForm, {
+            props: { billingContact: baseContact, updateUrl: "/billing/contact", canManage: true },
+        });
+
+        const submit = screen.getByTestId("billing-contact-submit");
+        expect(submit).not.toBeDisabled();
+
+        await fireEvent.click(submit);
+
+        expect(routerPatchMock).toHaveBeenCalledTimes(1);
+        const [url, payload] = routerPatchMock.mock.calls[0] as [string, Record<string, unknown>];
+        expect(url).toBe("/billing/contact");
+        expect(payload).toEqual({ billing_contact_email: "", billing_contact_name: "" });
+    });
+
+    it("サーバ 422 の errors.billing_contact_email を表示する", () => {
+        pageState.props = {
+            errors: { billing_contact_email: "請求先メールアドレスは、有効なメールアドレス形式で指定してください。" },
+        };
+        render(BillingContactForm, {
+            props: { billingContact: baseContact, updateUrl: "/billing/contact", canManage: true },
+        });
+
+        expect(
+            screen.getByText("請求先メールアドレスは、有効なメールアドレス形式で指定してください。"),
+        ).toBeInTheDocument();
+    });
+
+    it("未設定のときは owner email が実際の宛先であることを示す", () => {
+        render(BillingContactForm, {
+            props: { billingContact: baseContact, updateUrl: "/billing/contact", canManage: true },
+        });
+
+        expect(screen.getByText(/owner@example\.test/)).toBeInTheDocument();
+    });
+
+    it("canManage=false では読み取り専用表示になる (フォームを出さない)", () => {
+        render(BillingContactForm, {
+            props: {
+                billingContact: { email: "billing@example.test", name: "経理部", fallbackEmail: null },
+                updateUrl: "/billing/contact",
+                canManage: false,
+            },
+        });
+
+        expect(screen.queryByTestId("billing-contact-form")).toBeNull();
+        expect(screen.getByTestId("billing-contact-email-readonly")).toHaveTextContent(
+            "billing@example.test",
+        );
+        expect(screen.getByTestId("billing-contact-name-readonly")).toHaveTextContent("経理部");
+    });
+});
diff --git a/tests/js/pages/Billing/Index.test.ts b/tests/js/pages/Billing/Index.test.ts
index d5c760f..b73812d 100644
--- a/tests/js/pages/Billing/Index.test.ts
+++ b/tests/js/pages/Billing/Index.test.ts
@@ -10,7 +10,7 @@ const { routerPostMock, pageState } = vi.hoisted(() => ({
 
 vi.mock("@inertiajs/svelte", async (importOriginal) => ({
     ...(await importOriginal<typeof import("@inertiajs/svelte")>()),
-    router: { post: routerPostMock },
+    router: { post: routerPostMock, patch: vi.fn() },
     page: pageState,
 }));
 
@@ -55,11 +55,13 @@ const basePage: BillingDashboardProps = {
         pendingAutoEnable: false,
         disabledReason: null,
         failureCount: 0,
-        consentVersion: "v1",
+        consentVersion: "v2",
         baseUnitAmountJpy: 100,
         tiers: [{ minCount: 1, unitAmount: 100 }],
     },
     autoRechargeSetupToken: "01j0000000000000000000test",
+    feedback: null,
+    billingContact: { email: null, name: null, fallbackEmail: "owner@example.test" },
 };
 
 afterEach(() => {
@@ -144,3 +146,47 @@ describe("Billing/Index", () => {
         expect(screen.getByTestId("auto-recharge-card")).toBeInTheDocument();
     });
 });
+
+describe("Billing/Index — 着地 feedback (P9)", () => {
+    // T088 で PurchaseFormState::Completed を撤去したため、この one-shot が
+    // 「購入完了をユーザーに知らせる唯一の経路」になっている。
+    it.each([
+        ["purchase_received", "お支払いを受け付けました。"],
+        ["purchase_processing", "お支払いを確認しています。"],
+        ["purchase_already_received", "既に受け付け済みです。"],
+        ["checkout_retry_required", "有効期限が切れました。"],
+        ["portal_returned", "お支払い管理画面から戻りました。"],
+    ] as const)("kind=%s のバナーをサーバ文言で描画する", (kind, message) => {
+        render(Index, { props: { page: { ...basePage, feedback: { kind, message } } } });
+
+        expect(screen.getByTestId("billing-feedback")).toHaveTextContent(message);
+        expect(screen.getByTestId(`billing-feedback-${kind}`)).toBeInTheDocument();
+    });
+
+    it("feedback=null では何も描画しない (リロードで消える one-shot)", () => {
+        render(Index, { props: { page: basePage } });
+
+        expect(screen.queryByTestId("billing-feedback")).toBeNull();
+    });
+
+    it("raw query を参照しない (?session_id があっても feedback=null なら描画しない)", () => {
+        window.history.replaceState({}, "", "/billing?session_id=cs_test&replayed=1");
+        render(Index, { props: { page: basePage } });
+
+        expect(screen.queryByTestId("billing-feedback")).toBeNull();
+        window.history.replaceState({}, "", "/billing");
+    });
+
+    it("?highlight=auto-recharge でオートリチャージカードを強調する", async () => {
+        window.history.replaceState({}, "", "/billing?highlight=auto-recharge");
+        render(Index, { props: { page: basePage } });
+
+        await vi.waitFor(() => {
+            expect(screen.getByTestId("auto-recharge-card")).toHaveAttribute(
+                "data-highlighted",
+                "true",
+            );
+        });
+        window.history.replaceState({}, "", "/billing");
+    });
+});
diff --git a/tests/js/pages/Billing/Plans.test.ts b/tests/js/pages/Billing/Plans.test.ts
index 656c7d2..997d330 100644
--- a/tests/js/pages/Billing/Plans.test.ts
+++ b/tests/js/pages/Billing/Plans.test.ts
@@ -15,7 +15,8 @@ vi.mock("@inertiajs/svelte", async (importOriginal) => ({
 }));
 
 /*
- * プラン比較ページ。確認ダイアログ経由で POST /billing/checkout に plan_code のみを送る。
+ * プラン比較ページ。確認ダイアログ経由で POST /billing/checkout に plan_code +
+ * subscription_attempt_token を送る (funding_choice は載せない)。
  * サーバ validation エラー時は dialog を開いたままサーバ文言を出す。
  */
 
@@ -41,6 +42,7 @@ const basePage: BillingPlansPageProps = {
     currentPlanCode: "personal",
     billingState: "active_free_plan",
     canManage: true,
+    subscriptionAttemptToken: "01JQ0000000000000000000000",
 };
 
 afterEach(() => {
@@ -59,7 +61,7 @@ describe("Billing/Plans", () => {
         expect(screen.getByTestId("plan-current-badge-personal")).toHaveTextContent("現在のプラン");
     });
 
-    it("「このプランへ変更」→ 確認 → plan_code のみを POST する", async () => {
+    it("「このプランへ変更」→ 確認 → plan_code + 冪等 token を POST する (funding_choice は載せない)", async () => {
         render(Plans, { props: { page: basePage } });
 
         await fireEvent.click(screen.getByTestId("plan-change-standard"));
@@ -71,7 +73,11 @@ describe("Billing/Plans", () => {
         expect(routerPostMock).toHaveBeenCalledTimes(1);
         const [url, payload] = routerPostMock.mock.calls[0] as [string, Record<string, unknown>];
         expect(url).toBe("/billing/checkout");
-        expect(payload).toEqual({ plan_code: "standard" });
+        expect(payload).toEqual({
+            plan_code: "standard",
+            subscription_attempt_token: "01JQ0000000000000000000000",
+        });
+        expect(payload).not.toHaveProperty("funding_choice");
     });
 
     it("errors.plan_code があるとき dialog にサーバ文言を描画する", async () => {
diff --git a/tests/js/pages/OnboardingCheckout.test.ts b/tests/js/pages/OnboardingCheckout.test.ts
index 3bf3e1c..0493412 100644
--- a/tests/js/pages/OnboardingCheckout.test.ts
+++ b/tests/js/pages/OnboardingCheckout.test.ts
@@ -45,9 +45,10 @@ const basePageData: OnboardingCheckoutShape = {
         maxCount: 50,
         maxAmountJpy: 3500,
         unitAmountJpy: 70,
-        consentVersion: "v1",
+        consentVersion: "v2",
     },
     fundingChoices: ["auto_recharge", "later"],
+    subscriptionAttemptToken: "01JQ0000000000000000000000",
 };
 
 afterEach(() => {
@@ -129,7 +130,7 @@ describe("Onboarding/Checkout", () => {
         expect(routerPostMock).toHaveBeenCalledWith(
             "/onboarding/activate-personal",
             // P8a: funding_choice の既定は auto_recharge (同意 version 同送。金額は送らない)
-            { declaration: "0", funding_choice: "auto_recharge", consent_version: "v1" },
+            { declaration: "0", funding_choice: "auto_recharge", consent_version: "v2" },
             expect.anything(),
         );
     });
@@ -152,7 +153,7 @@ describe("Onboarding/Checkout", () => {
 
         expect(routerPostMock).toHaveBeenCalledWith(
             "/onboarding/activate-personal",
-            { declaration: "1", funding_choice: "auto_recharge", consent_version: "v1" },
+            { declaration: "1", funding_choice: "auto_recharge", consent_version: "v2" },
             expect.anything(),
         );
     });
@@ -209,20 +210,45 @@ describe("Onboarding/Checkout", () => {
         expect(routerPostMock).toHaveBeenCalledTimes(1);
     });
 
-    it("有償プランは plan_code のみを課金 checkout に送る", async () => {
+    it("有償プランは plan_code + 冪等 token + funding_choice を課金 checkout に送る (既定は auto_recharge = consent_version 同梱)", async () => {
         renderPage();
 
         await fireEvent.click(screen.getByTestId("select-plan-starter"));
         expect(screen.queryByTestId("personal-free-step")).not.toBeInTheDocument();
 
-        await fireEvent.click(screen.getByTestId("paid-plan-submit"));
+        // 同意ダイアログ未操作でも申込ボタンは押せる (禁止事項 #8)。
+        const submit = screen.getByTestId("paid-plan-submit");
+        expect(submit).not.toBeDisabled();
+
+        await fireEvent.click(submit);
         expect(routerPostMock).toHaveBeenCalledWith(
             "/billing/checkout",
-            { plan_code: "starter" },
+            {
+                plan_code: "starter",
+                subscription_attempt_token: "01JQ0000000000000000000000",
+                funding_choice: "auto_recharge",
+                consent_version: "v2",
+            },
             expect.anything(),
         );
     });
 
+    it("funding_choice=later では consent_version を送らない", async () => {
+        renderPage();
+
+        await fireEvent.click(screen.getByTestId("select-plan-starter"));
+        await fireEvent.click(screen.getByTestId("funding-choice-later"));
+        await fireEvent.click(screen.getByTestId("paid-plan-submit"));
+
+        const [, payload] = routerPostMock.mock.calls.at(-1) as [string, Record<string, unknown>];
+        expect(payload).toEqual({
+            plan_code: "starter",
+            subscription_attempt_token: "01JQ0000000000000000000000",
+            funding_choice: "later",
+        });
+        expect(payload).not.toHaveProperty("consent_version");
+    });
+
     it("無償プラン (personal) は有償 checkout へ送らない (Stripe checkout へ混入させない)", async () => {
         // props の plans を単一真実源に「基本料金を持つものだけ有償」と判定する。
         // personal は currentBaseAmount=null なので、仮に有償 submit 経路へ到達しても送信しない。
```

---

## 設計書 P9 節 (正本。逸脱不可)

### P9: サブスク checkout の冪等・着地 feedback + 請求先情報 + PM 流用（T1004）

前提（v2）: P1〜P8b がマージ済み。**`BillingCheckoutSession`（model + migration + Factory）・`CheckoutIntent`（`App\Enums\CheckoutIntent`: `SubscriptionStart` / `SetupPaymentMethod`）・`CheckoutSessionStatus`（`App\Enums\CheckoutSessionStatus`: `Pending` / `Completed` / `Failed` / `Expired`）は P2 で導入済み**（`BillingAccess::state()` の `PendingCheckout` / `ExpiredCheckout` が読むため前倒し = D25 v2）。**`billing_checkout_sessions` の最初の writer は P8a**（`startSetupCheckout` が `intent=SetupPaymentMethod` / `status=pending` の行を書く。P8a 本文「最初の writer は P8a になる（P2 との契約）」）。**P9 が新規に書くのは `intent=SubscriptionStart` の行**であり、P9 の冪等状態機械・dedup・feedback・sweeper はすべて **P8a の setup 行と同居する前提**で設計する。P2 の `state()` は **live pending を `created_at >= now()-1day` の in-memory 判定で見る**（`expires_at` 列は存在しない）。P8b までで `BillingDashboardDto` / `BillingPlansPageDto` / `Billing/Plans.svelte` / `BillingCustomerSynchronizer` / `SyncBillingCustomerDetails` / `StripeGatewayInterface` + `CashierStripeGateway` + `FakeStripeGateway` が揃っている。P8a までで **`AutoRechargeService`（`recordPreConsent` / `applySetupCompletion` / `autoEnableEligible` / `isAutoEnablePending` / `hasRecentCompletedSetup` / `reconsentRequiredFor`）・`SignupFundingChoice`（3 case）・`AutoRechargeSettingsDto`（`pendingAutoEnable` / `setupPending` を持つ aigenba verbatim shape）・`Contracts\AutoRechargeGatewayInterface`（8 メソッド）+ `CashierAutoRechargeGateway` + `Fakes\FakeAutoRechargeGateway`・`app/Jobs/Billing/*`・`config/billing.php` の `auto_recharge` ブロック（`consent_version='v1'`）**が揃っている。

**P9 の担当は 4 つ**: (a) `attempt_token` による冪等状態機械を **`SubscriptionStart` 行の writer として配線**する、(b) 着地 feedback（`resolveBillingFeedback` + `Billing/Index` バナー）、(c) 請求先情報（`billing_contact_email` / `billing_contact_name`）、**(d) T1004 = サブスク決済カードのオートリチャージ流用**（D29(ii) で P8a から明示移譲。`ReuseSubscriptionPaymentMethodJob` / `applyReusedPaymentMethod` / `resolveSubscriptionPaymentMethod` / `hasRecentAutoRechargeFundedSignup` / `billing_checkout_sessions.{funding_choice, pm_reuse_dispatched_at}` / `settingsFor.setupPending` の (b) 条件 / 着地 flash 分岐 / `consent_version` の `'v2'` 改定）。

**DoD**: サブスク checkout が二重 subscription 作成を構造的に起こせない（`UNIQUE(organization_id, intent, attempt_token)` + org-wide live pending dedup + Stripe idempotency key + INSERT race の re-read 収束）。**`state()` / `startCheckout()` / 日次 sweeper が同一の live 判定（`created_at >= now()-1day`）を単一出典から共有し、stale pending が永久に再利用される経路が構造的に存在しない**（下記 C-1）。**webhook の遷移条件が一意**（`Completed` 以外は payload の判定結果へ遷移。`Completed` 終局。下記 C-2）。**T1004 一式が実装され**、`funding_choice=auto_recharge` の契約 checkout が**決済確定（`payment_status ∈ {paid, no_payment_required}`）のときだけ** `ReuseSubscriptionPaymentMethodJob` を dispatch し、`applyReusedPaymentMethod` が**適格性先行 fail-closed**（同意なし・失効・停止状態では customer default PM にもローカル snapshot にも一切触れない）で有効化する。**`consent_version` は `'v2'`**（= v1 同意は `reconsentRequiredFor` 経由で自動失効 → 再同意。fail-closed）。`billing_contact_*` は **CipherSweet 暗号化で保存**され、平文 DB 非保存・平文 where 不 hit が Feature/Architecture テストで固定される。**金銭の付与経路には一切触らない**（D7 維持: 付与は `invoice.paid`、`plan_code` 同期は `customer.subscription.*`）。**`EffectivePlan` は使わない**（判定源は `BillingAccess::state()` の `OnboardingBillingState`）。

**token 型名の分離（交渉不可）**: チケット決済の `ticketAttemptToken` / `ticket_checkout_sessions.attempt_token` / Stripe key `purchase:{token}` は **P8b までで確定済みの別テーブル・別 key 空間**。P8a のカード登録は `billing_checkout_sessions` の **`intent=setup_payment_method`** + Stripe key `auto-recharge-setup:{token}`。P9 が導入するのは `subscriptionAttemptToken`（props / TS 型名）/ `billing_checkout_sessions.attempt_token`（**`intent=subscription_start` でスコープ**）/ Stripe key `sub_start:{token}`（aigenba verbatim の名前空間）。3 者を同一 DTO・同一 key 空間に混ぜない。

#### 変更箇所

| ファイル (AI-CUE) | 何をするか | 移植元 (aigenba) |
|---|---|---|
| `app/Models/Billing/BillingCheckoutSession.php`（改修。P2 導入分） | **live 判定の単一出典を置く**（C-1）。`// 境界は排他的に統一する:`<br>`//   live  : created_at >= staleThresholdAt($now)  （isLivePending / state() / dedup の SQL filter）`<br>`//   stale : created_at <  staleThresholdAt($now)  （sweeper の expireStaleCheckouts）`<br>`// 両者は補集合であり、境界時刻ちょうどの行が「live かつ Expired 化対象」になることはない。`<br>`public static function staleThresholdAt(CarbonImmutable $now): CarbonImmutable` = `$now->subDay()`（**aigenba の閾値をそのまま**）/ `isLivePending(CarbonImmutable $now): bool` = `status === CheckoutSessionStatus::Pending->value && ($created_at === null \|\| $created_at->greaterThanOrEqualTo(self::staleThresholdAt($now)))` / `isReplayablePending(CarbonImmutable $now): bool` = `isLivePending($now) && checkout_url !== null && checkout_url !== ''`。**additive 2 列の宿主化**: `@property string\|null $funding_choice` / `@property Carbon\|null $pm_reuse_dispatched_at` + `$fillable` へ `funding_choice`、`$casts` へ `'pm_reuse_dispatched_at' => 'datetime'`（**`pm_reuse_dispatched_at` は `$fillable` に入れない** = webhook の `forceFill` 専用 marker） | `/tmp/aigenba/app/Models/Billing/BillingCheckoutSession.php:23,35,49,67,96-104` + `BillingAccess.php:58` / `ReconcileSubscriptionSchedules.php:113` の `subDay()` を 1 箇所へ集約。AI-CUE 先例 `app/Models/Billing/TicketCheckoutSession.php:64-68` |
| `database/migrations/2026_07_xx_xxxxxx_add_signup_funding_to_billing_checkout_sessions.php`（新規。**additive のみ**） | `funding_choice` = `string(16)->nullable()->after('plan_code')` / `pm_reuse_dispatched_at` = `timestamp()->nullable()->after('completed_at')`。**P2 所管テーブルへの additive 列追加のみ**（既存列・index・UNIQUE は触らない）。`down()` は `dropColumn(['funding_choice','pm_reuse_dispatched_at'])` | `/tmp/aigenba/database/migrations/2026_06_25_090200_add_signup_funding_to_billing_checkout_sessions.php:21`（`funding_choice` の列型 verbatim。`pack_count` / `topup_count` / `applied_trial_days` は原則 4 で非移植）+ `/tmp/aigenba/database/migrations/2026_07_09_140000_add_pm_reuse_dispatched_at_to_billing_checkout_sessions.php`（docblock ごと verbatim） |
| `app/Services/Billing/BillingAccess.php`（改修） | `state()` の stale 判定を `$row->isLivePending($now)` 経由へ差し替える（`$now = CarbonImmutable::now()` を 1 回だけ取り、`$threshold` のローカル literal を撤去）。**挙動不変**（同じ `subDay()` 値）。P2 の分岐表・`BillingAccessStateTest` は**無変更で green** | `/tmp/aigenba/app/Services/Billing/BillingAccess.php:57-75` |
| `app/Console/Commands/Billing/ReconcileSubscriptionSchedules.php`（改修） | **`expireStaleCheckouts()` を追加**（**境界は排他: `created_at < staleThresholdAt()`** = live 判定 `>=` の補集合）。`BillingCheckoutSession::query()->where('status', Pending)->where('created_at', '<', BillingCheckoutSession::staleThresholdAt(CarbonImmutable::now()))->update(['status' => Expired])`。**intent で絞らない**（verbatim。P8a の `SetupPaymentMethod` 行も対象）。Stripe 照会なし。`handle()` の集計行へ `expired={n}` を追加。既存 daily 登録（`routes/console.php:38`）に相乗り = **新 command も新 `Schedule::command()` 行も作らない** | `/tmp/aigenba/app/Console/Commands/Billing/ReconcileSubscriptionSchedules.php:112-121` |
| `app/Services/Billing/SubscriptionService.php`（改修） | **`startCheckout()` を冪等マシンへ差し替える**（`SubscriptionCheckoutService` を新設しない = aigenba は本 Service に置いている）。シグネチャ: `startCheckout(Organization $org, User $user, Plan $plan, string $successUrl, string $cancelUrl, string $attemptToken, ?SignupFundingChoice $funding): CheckoutSessionDto`。`Cache::lock("billing:checkout:start:{$org->id}", 10)->block(5, …)`（**lock 名も verbatim**）。`assertCheckoutReady()` / `isReplayableCheckout()` / `replayCheckout()` / `isUniqueViolation()` / `attemptTokenIsForeign()` を実装。**行 INSERT に `'funding_choice' => $funding?->value` を含める**（T1004 の唯一の入力）。lock closure 先頭で `$now = CarbonImmutable::now()` を 1 回取り、段 2/3/4 の live 判定をすべて共有述語へ通す（C-1） | `/tmp/aigenba/app/Services/Billing/SubscriptionService.php:508-717,738,854,930-985` |
| `app/DataTransferObjects/Billing/CheckoutSessionDto.php`（新規） | **verbatim**（`stripeSessionId` / `url` / `intent` / `planCode` + `toArray()` + `@phpstan-type CheckoutSessionShape`） | `/tmp/aigenba/app/DataTransferObjects/Billing/CheckoutSessionDto.php` |
| `app/Services/Billing/Contracts/StripeGatewayInterface.php`（改修） | `createSubscriptionCheckout(Organization $org, string $stripePriceId, string $successUrl, string $cancelUrl, array $metadata, string $idempotencyKey): CreatedCheckoutSession`（戻り値は既存 `CreatedCheckoutSession` = session id の pin が webhook 照合に必須）。`expireCheckoutSession(string $stripeSessionId): string` を追加。**席引数は移植しない**（原則 4） | `/tmp/aigenba/app/Services/Billing/Contracts/StripeGatewayInterface.php:50,200` / AI-CUE `app/Services/Billing/TicketCheckoutGateway.php` |
| `app/Services/Billing/CashierStripeGateway.php`（改修） | `newSubscription('default',…)->checkout()` をやめ `$org->stripe()->checkout->sessions->create($payload, ['idempotency_key' => $key])` 直呼びへ（**Cashier の `checkout()` ヘルパは per-request idempotency key を公開しない**）。`buildSubscriptionSessionPayload()` を public pure メソッドで切り出し、`subscription_data.metadata.{name,type}='default'` + **`payment_settings.save_default_payment_method='on_subscription'`**（**T1004 の第一候補 `subscription.default_payment_method` が埋まる前提**）を含める。`expireCheckoutSession()` を実装 | `/tmp/aigenba/app/Services/Billing/CashierStripeGateway.php:69-82` / AI-CUE `CashierTicketCheckoutGateway::buildSessionPayload()` |
| `app/Services/Billing/Fakes/FakeStripeGateway.php`（改修） | 新シグネチャに追随。`CreatedCheckoutSession` を決定的に返し、**同一 `idempotencyKey` の再呼び出しで同一 sessionId** を返す。`expireCheckoutSession()` は既定 `'expired'`（テストが `'complete'` / throw を注入可） | `/tmp/aigenba/app/Services/Billing/Testing/StripeGatewayDuskFake.php` / AI-CUE `Fakes/FakeTicketCheckoutGateway.php` |
| `app/Services/Billing/Contracts/AutoRechargeGatewayInterface.php`（改修。P8a 導入分） | **9 本目**として `resolveSubscriptionPaymentMethod(string $stripeSubscriptionId): ?string` を追加（`@return non-empty-string\|null`。docblock「解決順序: `subscription.default_payment_method` → `latest_invoice.payment_intent.payment_method`。双方 null なら null。空文字は返さない」を verbatim）。D31 の狭い gateway 規約は維持（`StripeGatewayInterface` には足さない） | `/tmp/aigenba/app/Services/Billing/Contracts/StripeGatewayInterface.php:286-294` |
| `app/Services/Billing/CashierAutoRechargeGateway.php`（改修。P8a 導入分） | `resolveSubscriptionPaymentMethod()` = `Cashier::stripe()->subscriptions->retrieve($id, ['expand' => ['latest_invoice.payments.data.payment.payment_intent']])` → **`public static function resolvePaymentMethodFromSubscription(\Stripe\Subscription $subscription): ?string`**（多段解決の純関数として分離 = fixture で分岐を直接固定できる。verbatim） | `/tmp/aigenba/app/Services/Billing/CashierStripeGateway.php:930-975` |
| `app/Services/Billing/Fakes/FakeAutoRechargeGateway.php`（改修。P8a 導入分） | `resolveSubscriptionPaymentMethod()` を決定的に実装（既知 prefix の subscription id に対して対の PM id を返し、未知は **null**（= 解決不能。空文字は返さない））。テストが「解決不能」「例外」を注入できる | `/tmp/aigenba/app/Services/Billing/Testing/StripeGatewayDuskFake.php:412-421` |
| `app/Jobs/Billing/ReuseSubscriptionPaymentMethodJob.php`（新規） | **verbatim**（`public int $tries = 3` / `public int $backoff = 30` / `__construct(public readonly int $organizationId, public readonly string $stripeSubscriptionId)` / `handle(AutoRechargeGatewayInterface $gateway, AutoRechargeService $autoRecharge)`）。org 不在 → return / **軽量 guard `! $autoRecharge->isAutoEnablePending($org)` → Stripe retrieve 前に return** / PM 解決 null → `Log::warning('auto-recharge: subscription PM unresolved, skipping reuse', ['organization_id','stripe_subscription_id'])` + return（**PM・customer 情報はログに出さない**）/ それ以外 → `applyReusedPaymentMethod()`。docblock（T710 = 外向き Stripe API を webhook 同期処理から Job へ退避）ごと移植 | `/tmp/aigenba/app/Jobs/Billing/ReuseSubscriptionPaymentMethodJob.php`（gateway 型のみ D31 に合わせ `AutoRechargeGatewayInterface`） |
| `app/Services/Billing/AutoRechargeService.php`（改修。P8a 導入分） | **`applyReusedPaymentMethod(Organization $org, string $paymentMethodId): bool` を追加（verbatim）**: `Assert::stringNotEmpty($paymentMethodId)` → `Cache::lock("billing:auto-recharge:{$org->id}")->block(10, …)` → **lock 内・TX 外で適格性先行確認**（`$config === null \|\| ! autoEnableEligible($config)` → `Log::info('auto-recharge: subscription PM reuse skipped (not eligible)', ['organization_id','reason'])` + `return false` = **Stripe にも DB にも触らない完全 no-op**）→ `gateway->setDefaultPaymentMethod()` → `DB::transaction`（`lockForUpdate` 再取得 → 不適格なら **`RuntimeException`（部分適用の顕在化。silent no-op にしない）** → snapshot + `enabled=true` + `failure_count=0`）→ `LockTimeoutException` は `RuntimeException` で Job retry へ → `$enabledNow` なら `notifyAutoEnabled()`（`report()` で握る）。**`hasRecentAutoRechargeFundedSignup(Organization $org): bool` を追加**: `intent=subscription_start` + `funding_choice=auto_recharge` + `status=completed` + **`pm_reuse_dispatched_at >= now()-{setup_pending_window_minutes}`**（`updated_at`/`completed_at` は使わない = 未決済 completed で窓が誤って開く）。**`settingsFor()` の `setupPending` を (b) 込みへ**: `$setupPending = ! $hasPm && (hasRecentCompletedSetup($org) \|\| ($pendingAutoEnable && hasRecentAutoRechargeFundedSignup($org)))` | `/tmp/aigenba/app/Services/Billing/AutoRechargeService.php:113-120,955-1025,1216-1228`（`intent=SignupFunding` → **`SubscriptionStart`** の 1 点のみ読み替え。`SignupFunding` intent は P2 が原則 4 で非移植のため） |
| `config/billing.php`（改修。P8a 導入分） | `auto_recharge.consent_version` を **`'v1'` → `'v2'`**（D29-b）。**改定履歴コメントを verbatim で持ち込む**（「v1 = T1003 初版（カード登録経路のみ）/ v2 = T1004 有償契約でサブスク決済カードをオートリチャージへ流用することを明示」「提示条件の実質（…カードの取得手段）を変える変更では必ず version を上げること」）。他の値（`default_threshold=5` / `default_max=50` / `max_count=1000` / `max_failures=3` / `pending_expiry_hours=24` / `setup_pending_window_minutes=30`）は**不変** | `/tmp/aigenba/config/billing.php:31-47` |
| `app/Exceptions/Billing/SubscriptionAttemptPlanMismatchException.php`（新規） | 同 token・別 plan の再送。Controller が `ValidationException::withMessages(['plan_code' => …])` = **422**（非 verbatim。根拠は N-1） | — |
| `app/Exceptions/Billing/StaleCheckoutAttemptException.php` / `CheckoutInProgressException.php`（再利用） | **既存クラス**をサブスク側でも使う。新設しない | `/tmp/aigenba/app/Exceptions/Billing/StaleCheckoutAttemptException.php` |
| `app/Http/Requests/Billing/BillingCheckoutRequest.php`（改修） | `subscription_attempt_token => ['required','ulid']`（`Str::ulid()` は大文字 Crockford base32 のため lowercase regex 不可 = aigenba のコメントごと移植）。**T1004**: `funding_choice => ['nullable','string', Rule::in(array_map(fn (SignupFundingChoice $c): string => $c->value, SignupFundingChoice::cases()))]` / `consent_version => ['required_if:funding_choice,'.SignupFundingChoice::AutoRecharge->value, 'string','max:16', Rule::in([$this->currentAutoRechargeConsentVersion()])]` + `messages()` の 2 文言 verbatim（`'consent_version.required_if' => '自動購入への同意が必要です。'` / `'consent_version.in' => '自動購入の同意内容が更新されています。ページを再読み込みして内容を確認してください。'`）。**`pack_count` / `topup_count` / `campaign_code` / `seats` は移植しない**（原則 4）。`ProhibitsProtectedKeys` は据置 | `/tmp/aigenba/app/Http/Controllers/Billing/BillingController.php:624-652`（`funding_choice` は AI-CUE の単一契約 route が Plans 経路（funding 非提示）と Onboarding 経路（funding 2 択 = P8a）の両方を宿すため **`required` → `nullable`**。null = 従来の契約 checkout = 流用しない） |
| `app/Enums/Billing/BillingFeedbackKind.php`（新規） | **verbatim 5 case**（`PurchaseReceived` / `PurchaseProcessing` / `PurchaseAlreadyReceived` / `CheckoutRetryRequired` / `PortalReturned`） | `/tmp/aigenba/app/Enums/Billing/BillingFeedbackKind.php` |
| `app/DataTransferObjects/Billing/BillingFeedbackDto.php`（新規） | **verbatim**（`private __construct` + `simple(kind, message)` + `toArray(): BillingFeedbackShape` + `@phpstan-type SimpleBillingFeedbackKind`） | `/tmp/aigenba/app/DataTransferObjects/Billing/BillingFeedbackDto.php` |
| `app/DataTransferObjects/Billing/BillingContactDto.php`（新規） | `email` / `name` / `fallbackEmail`（owner email）+ `toArray(): BillingContactShape` | `/tmp/aigenba/app/Models/Organization.php:119-138` の fallback 意味論 |
| `app/Http/Controllers/Billing/BillingController.php`（改修） | `index` に private `resolveBillingFeedback(Request, Organization): ?BillingFeedbackDto`（**verbatim**）+ **`resolveAutoRechargeLanding(Request, Organization): ?RedirectResponse`（T1004 の `?session_id` 分岐のみ）** を追加。`checkout` を新 `startCheckout()` へ配線（404 → 認可 → **`recordPreConsent`** → 開始の順）。`portal` の return URL を `route('billing.index', ['portal' => 1])` へ。`plans` に `subscriptionAttemptToken` を載せる。`updateBillingContact` を追加 | `/tmp/aigenba/app/Http/Controllers/Billing/BillingController.php:195,235-265,318-393,540-610,657-684` |
| `app/Services/Billing/StripeWebhookProcessor.php`（改修） | `CheckoutSessionCompleted` arm に `settleSubscriptionCheckout()` を**追加**（遷移条件は C-2 の 1 定義のみ）+ **T1004 dispatch 分岐**（`funding_choice=auto_recharge` + `payment_status ∈ {paid,no_payment_required}` + `subscriptionIdFrom($object) !== null` → `forceFill(['pm_reuse_dispatched_at' => CarbonImmutable::now()])->save()` → `ReuseSubscriptionPaymentMethodJob::dispatch($local->organization_id, $subscriptionId)`）+ private `subscriptionIdFrom(array $object): ?string`（`$object['subscription']` が array なら `['id']` を取る verbatim）。既存 `grantPurchasedTickets()` は**無改変** | `/tmp/aigenba/app/Http/Controllers/Billing/StripeWebhookController.php:447-470,508-526,1422-1433,1528-1541` |
| `app/DataTransferObjects/Billing/BillingDashboardDto.php`（改修） | additive: `feedback: BillingFeedbackShape\|null` / `billingContact: BillingContactShape`。**`autoRecharge: AutoRechargeShape` は P8a 導入済み・無変更**（`setupPending` / `pendingAutoEnable` の shape は P8a で aigenba verbatim） | `/tmp/aigenba/app/DataTransferObjects/Billing/BillingDashboardDto.php` |
| `app/DataTransferObjects/Billing/BillingPlansPageDto.php`（改修） | additive: `subscriptionAttemptToken: string`（render ごとの ULID） | `/tmp/aigenba/app/DataTransferObjects/Billing/BillingPlansDto.php` |
| `app/DataTransferObjects/Onboarding/OnboardingCheckoutDto.php`（改修。P3 導入分） | additive: `subscriptionAttemptToken: string`（P3 本文が「`attemptToken` 同梱（P9）」と明示委譲済み。T1004 の POST が冪等 token を必要とする） | `/tmp/aigenba/resources/js/pages/Onboarding/Checkout.svelte:34`（props `attemptToken`） |
| `resources/js/pages/Onboarding/Checkout.svelte`（改修。P3 導出 + P8a の funding 2 択） | 有償プランの submit body を `{plan_code, subscription_attempt_token, funding_choice, ...(funding_choice==='auto_recharge' ? {consent_version: consentTerms.consentVersion} : {})}` にして `billing.checkout` へ POST（aigenba の `signup-checkout` POST 相当）。**同意アクションは実行ボタンのクリック**（コメント verbatim）。**disabled でブロックしない**（禁止事項 #8 / D4） | `/tmp/aigenba/resources/js/pages/Onboarding/Checkout.svelte:190-251` |
| `app/Models/Organization.php`（改修） | `checkoutSessions(): HasMany<BillingCheckoutSession, $this>` を追加（feedback / T1004 着地の org スコープ引きに必須）。`implements CipherSweetEncrypted` + `use UsesCipherSweet` + `configureCipherSweet()`。`routeNotificationForMail()` を `billing_contact_email` 正本 → owner email fallback へ。**両列とも `$fillable` 外** | `/tmp/aigenba/app/Models/Organization.php:119-138`（fallback 意味論のみ） |
| `database/migrations/2026_07_xx_xxxxxx_add_billing_contact_columns_to_organizations_table.php`（新規） | `billing_contact_email` / `billing_contact_name` を **`text()->nullable()`**（CipherSweet ciphertext のため `string(255)` を使わない）。**blind index 列は作らない**（共有 `blind_indexes` morph テーブル） | `/tmp/aigenba/database/migrations/2026_04_14_011301_add_cashier_columns_to_organizations_table.php:16-17`（**列型は非 verbatim**） |
| `app/DataTransferObjects/Billing/UpdateBillingContactData.php`（新規） | **verbatim**（`fromRequest()` で `EmailNormalizer::normalize()` + `Assert::stringNotEmpty()`、name は空文字を null へ畳む） | `/tmp/aigenba/app/DataTransferObjects/Billing/UpdateBillingContactData.php` |
| `app/Http/Requests/Billing/UpdateBillingContactRequest.php`（新規） | `billing_contact_email => ['required','email:rfc','max:255']` / `billing_contact_name => ['nullable','string','max:255']` + `protectedKeyMissingRules()`（**`array_merge`** = AI-CUE trait docblock の保護キー後勝ち merge） | `/tmp/aigenba/app/Http/Requests/Organizations/Billing/UpdateBillingContactRequest.php` |
| `app/Actions/Billing/UpdateBillingContactAction.php`（新規） | **verbatim**（`DB::transaction` 内で両列代入 → **`save()` 前に `isDirty('billing_contact_email')` 判定** → `save()` → email dirty 時のみ `BillingCustomerSynchronizer::dispatchFor()`） | `/tmp/aigenba/app/Actions/Billing/UpdateBillingContactAction.php` |
| `resources/js/components/features/billing/BillingContactForm.svelte`（新規） | 請求先メール / 宛名の更新フォーム。`@lucide/svelte` の `Receipt`、DS token のみ | `/tmp/aigenba/resources/js/pages/Billing/_helpers/BillingContactForm.svelte` |
| `resources/js/pages/Billing/Index.svelte`（改修） | `page.feedback` バナー（`kind` で variant 決定・**raw query を UI が見ない**）と `BillingContactForm` を T071 primitive 配下に追加。**`?highlight=auto-recharge` で P8a の `AutoRechargeCard` へスクロール/強調**（T1004 着地の主役化） | `/tmp/aigenba/resources/js/pages/Billing/Index.svelte` |
| `resources/js/pages/Billing/Plans.svelte`（改修） | POST body を `{plan_code}` → `{plan_code, subscription_attempt_token}` へ（**funding_choice は載せない** = 契約変更経路に funding 提示は無い） | `/tmp/aigenba/resources/js/pages/Billing/Plans.svelte:117-119` |
| `routes/web.php`（改修） | `PATCH /billing/contact` → `billing.contact.update`（課金ゲート allowlist 内・**route parameter なし** = current-org スコープ）。**T1004 は既存 `billing.checkout` に相乗り**（新 route なし） | — |

**列を足す / 足さない点**: `billing_checkout_sessions` は **P2 で作成済み・P8a が `SetupPaymentMethod` 行の writer として先行**。P9 は **additive 2 列（`funding_choice` / `pm_reuse_dispatched_at`）のみ**を足す（P2 所管テーブルへの additive は許容）。**`expires_at` 列は追加しない**（live 判定は `status=Pending` + `created_at >= now()-1day` = `isLivePending()` が単一出典）。`organizations.billing_contact_*` を含め **P9 の migration は 2 本**。

**非スコープ（P9 で持ち込まない）**: `SignupFunding` / `CreditPurchase` intent（対象機能が無い = 原則 4。T1004 は既存 `SubscriptionStart` + `funding_choice` 列で成立する）/ `seats`・`pack_count`・`topup_count`・`applied_campaign_id`・`applied_trial_days`・`credit_count`・`unit_amount`（P2 が原則 4 で非移植と決定済み）/ `?setup_session_id` 着地・`autoRechargeAutoConsent`（aigenba T1002 G4 = **カード登録 Checkout の着地** = D29(i) の「P8a = free（personal）経路の全部」所管。P9 へ移譲された T1004 の列挙に含まれない）/ `resolveOnboardingContinue`（`OnboardingReturnResolver` は P7 所管で `?session_id` 非依存に配線済み）/ `checkout.session.expired` の購読（`created_at` 閾値 + 日次 sweeper で決定的に扱えるため Stripe 照会を増やさない）/ `billing_contact_email` の NOT NULL 化・backfill（fallback が生きている限り不要）。

#### 波及変更

- **`BillingCheckoutSession` の writer 構成**: P8a（`intent=setup_payment_method`）+ P9（`intent=subscription_start`）の 2 writer になる。P9 の冪等マシンは **クエリを常に `intent=subscription_start` でスコープ**するため、段 2（同 token）/ 段 3（同 plan live pending dedup）/ 段 4（別 plan expire）に P8a の setup 行が混入しない（`UNIQUE(organization_id, intent, attempt_token)` の `intent` 軸が token 空間を分ける）。逆に **日次 sweeper は intent で絞らない**（verbatim）ため、P8a の stale な setup pending も `Expired` へ収束する。
- **live 判定の単一出典化（C-1）が触る P2 資産**: `BillingCheckoutSession`（述語 3 本を追加）/ `BillingAccess::state()`（**挙動不変のリファクタ**）。P2 の migration・Factory・`BillingAccessStateTest` の期待は**変更しない**。P8a が固定した不変条件（「setup 行は `state()` の `PendingCheckout` に落ちない」）も**無変更で green**（P9 は `state()` の分岐を変えない）。**P9 が書く `subscription_start` の live pending 行は `PendingCheckout` の正当な対象**であり、これは P2 の分岐表どおりの意味である。
- **P8a 資産への追記（P8a の既存挙動は不変）**: `AutoRechargeService`（**2 メソッド追加** + `settingsFor` の `setupPending` に (b) 条件を OR で追加。既存 (a) 条件と `pendingAutoEnable` の定義は不変）/ `Contracts\AutoRechargeGatewayInterface`（9 本目）+ `CashierAutoRechargeGateway` + `FakeAutoRechargeGateway`（`FakeExternalsServiceProvider` の bind は P8a のまま = **新 bind なし**）/ `config/billing.php`（`consent_version` v1 → v2）。**`AutoRechargeSettingsDto` は無変更**（P8a が aigenba verbatim の shape で導入済み = `pendingAutoEnable` / `setupPending` を保持）。
- **`consent_version='v2'` の移行効果（data migration なし）**: P8a 期に記録された v1 同意行は `reconsentRequiredFor()` が **自動失効**と判定し、`autoEnableEligible()` = false → `pendingAutoEnable` / `setupPending` が false → 自動有効化は起きず**再同意 UI（P8a の `AutoRechargeCard`）へ落ちる**。**既に `enabled=true` の org は `requiresReconsent=true` になり自動購入が停止する**（fail-closed = aigenba の版管理契約そのもの。原則 3 により値も文言も verbatim）。backfill・救済スクリプトは作らない。
- **既存 daily バッチへの相乗り**: `ReconcileSubscriptionSchedules`（`routes/console.php:38` で daily 登録済み）に `expireStaleCheckouts()` を追加。**新 command / 新 Schedule 行なし**。
- **TypeScript 型定義** `resources/js/types/billing.ts`:
  - 追加 `BillingFeedbackKind = 'purchase_received'|'purchase_processing'|'purchase_already_received'|'checkout_retry_required'|'portal_returned'`（**5 値**。PHP の `SimpleBillingFeedbackKind` と exact 対）/ `BillingFeedbackShape { readonly kind: BillingFeedbackKind; readonly message: string }` / `BillingContactShape { readonly email: string | null; readonly name: string | null; readonly fallbackEmail: string | null }`。
  - `BillingDashboardProps` に `feedback` / `billingContact` を追加。`BillingPlansPageProps` に `subscriptionAttemptToken: string` を追加。`AutoRechargeShape` は **P8a のまま無変更**。
  - `resources/js/types/onboarding.ts`（P3 産出）の `OnboardingCheckoutShape` に `subscriptionAttemptToken: string` を追加（`consentTerms` は P8a 追加済み）。`SignupFundingChoice` の TS literal union は **P8a 産出を再利用**（P9 で再定義しない）。
- **Inertia props**: `Billing/Index` / `Billing/Plans` / `Onboarding/Checkout` の `page` shape 拡張（DTO `toArray()` 経由。`response()->json()` 直書きなし）。新規ページなし。
- **DTO**: 新規 `CheckoutSessionDto` / `BillingFeedbackDto` / `BillingContactDto` / `UpdateBillingContactData`。改修 `BillingDashboardDto` / `BillingPlansPageDto` / `OnboardingCheckoutDto`。既存 `CreatedCheckoutSession` をサブスク側でも再利用。
- **Factory**: P2 の `BillingCheckoutSessionFactory` に **`initiatedBy(User $user)` / `withAttempt(string $token, string $planCode)` / `stale()` / `fundingAutoRecharge()` / `pmReuseDispatched(?CarbonImmutable $at = null)`** を追加。`OrganizationFactory` に `withBillingContact(?string $email = null, ?string $name = null)` を追加（テストデータ手組み禁止）。P8a の `TicketAutoRechargeFactory`（同意 4 列の state）を**そのまま再利用**する。
- **config**: `config/cashier.php` の購読集合は既存導出のまま（**case を増やさない** = `CheckoutSessionCompleted` は既存。`WebhookEventSubscriptionInvariantTest` は無変更で green）。**T1004 は新 webhook event を購読しない**。
- **テストファイル（更新・削除しない）**: `tests/Feature/Billing/BillingPageTest.php`（Index props に `feedback` / `billingContact`）/ `BillingPlansPageTest.php`（`subscriptionAttemptToken`）/ `PortalConfigurationTest.php`（`?portal=1`）/ `ReconcileSubscriptionSchedulesTest.php`（stale expire ケース追加）/ `BillingCheckoutSessionModelTest.php`（live 述語 + 新 2 列の cast/fillable ケース追加）/ `WebhookIdempotencyTest.php`・`WebhookEventSubscriptionInvariantTest.php`（期待不変）/ `TicketPurchaseWebhookTest.php`・`TicketCheckoutTest.php`（**無改変で green**）/ `BillingAccessStateTest.php`（P2 の期待不変 + writer 経由ケース追加）/ **P8a 産出の `AutoRechargeServiceTest`・`AutoRechargePreConsentTest`・`AutoRechargeEndpointTest`（`consent_version` の期待を `'v1'` → `'v2'` へ更新。`setupPending` の既存 (a) ケースは不変）**/ `tests/js/support/autoRechargeProps.ts`（**無変更**）/ `tests/js/pages/Billing/Index.test.ts`・`Plans.test.ts`・`OnboardingCheckout.test.ts`。
- **Architecture テストへの影響**: `MassAssignmentSafetyTest`（`billing_contact_*` / `pm_reuse_dispatched_at` は `$fillable` 外）/ `FormRequestProhibitedKeyTest`（新 FormRequest）/ `ManageRouteAuthGuardTest`（`billing.contact.update`）/ `BillingSyncDispatchInvariantTest`（`dispatchFor` の呼び出し元に `UpdateBillingContactAction` を追加）/ **P8a の `WebhookAsyncDispatchTest` 相当（webhook 同期処理から外向き Stripe API を撃たない）に `settleSubscriptionCheckout` を追加**（T1004 の Stripe 呼び出しは Job 側のみ）。新規 3 本は「テスト計画」§。

#### 主要な契約

**ルート**（課金ゲート allowlist 内・route parameter を持たない current-org スコープ。current org 不在 / 非所属は認可より前に 404）

```
GET   /billing            billing.index           BillingController@index      … 既存 (?session_id / ?portal / ?replayed / ?retry / ?highlight を解釈)
GET   /billing/plans      billing.plans           BillingController@plans      … 既存 (subscriptionAttemptToken を発行)
POST  /billing/checkout   billing.checkout        BillingController@checkout   … 既存 (body: {plan_code, subscription_attempt_token, funding_choice?, consent_version?})
POST  /billing/portal     billing.portal          BillingController@portal     … 既存 (return URL に ?portal=1)
PATCH /billing/contact    billing.contact.update  BillingController@updateBillingContact  ← 新規 (manageBilling)
```

**DB**

```sql
-- billing_checkout_sessions (P2 で作成。P8a が intent='setup_payment_method' 行の writer として先行済み。
--  P9 は intent='subscription_start' 行の writer + 下記 2 列の additive 追加のみ)
id, organization_id FK cascade, initiated_by_user_id FK users nullOnDelete,
intent varchar(32), plan_code varchar(32) null,
stripe_session_id varchar UNIQUE, idempotency_key varchar(128) UNIQUE,
attempt_token varchar null, checkout_url varchar(2048) null,
status varchar(16) default 'pending', completed_at timestamp null, timestamps
UNIQUE (organization_id, intent, attempt_token)  -- 名: billing_checkout_sessions_org_intent_attempt_unique
INDEX  (organization_id, intent, status) / INDEX (organization_id, intent, initiated_by_user_id, id)
+ funding_choice varchar(16) null            -- P9 additive (T1004 の唯一の入力。SignupFundingChoice の値)
+ pm_reuse_dispatched_at timestamp null      -- P9 additive (PM 流用 Job dispatch の永続マーカー)

-- organizations (additive)
billing_contact_email text null,  billing_contact_name text null   -- CipherSweet ciphertext
```

##### C-1: live 判定の単一出典（`state()` と `startCheckout()` が同一閾値を共有する契約）

**契約**: 「pending 行が live か」の判定は **`BillingCheckoutSession` の述語だけが定義する**。閾値 `now()-1day`（aigenba の `subDay()` 値。Stripe Checkout Session の 24h 自動 expire と一致）は `staleThresholdAt()` の**1 箇所にしか literal として現れない**。

```php
// App\Models\Billing\BillingCheckoutSession — 閾値の単一出典
/** live/stale の境界。Stripe Checkout Session の 24h 自動 expire と一致させる (aigenba: subDay)。 */
public static function staleThresholdAt(CarbonImmutable $now): CarbonImmutable
{
    return $now->subDay();
}

/** live pending (= 決済待ちとして生きている) か。created_at が null の行は live 扱い (P2 state() の else 分岐と同一)。 */
public function isLivePending(CarbonImmutable $now): bool
{
    return $this->status === CheckoutSessionStatus::Pending->value
        && ($this->created_at === null
            || $this->created_at->greaterThanOrEqualTo(self::staleThresholdAt($now)));
}

/** live pending かつ checkout_url 生存 = 復帰可能な進行中 Checkout。 */
public function isReplayablePending(CarbonImmutable $now): bool
{
    return $this->isLivePending($now)
        && $this->checkout_url !== null && $this->checkout_url !== '';
}
```

**共有方法（この 4 経路が上の述語 / 閾値だけを使う。独自の日付比較を書かない）**

| 経路 | 使い方 | 効果 |
|---|---|---|
| `BillingAccess::state()`（P2） | `$now = CarbonImmutable::now()` を 1 回取り、pending 行を `$row->isLivePending($now)` で分類（live → `PendingCheckout` / stale → `hasExpired` 材料）。**read 経路で DB 書込をしない**（P2 verbatim） | 挙動不変（同じ `subDay()`） |
| `SubscriptionService::startCheckout()` 段 2（同 token） | `isReplayableCheckout($row, $now)` = `status === Completed` **または** `$row->isReplayablePending($now)` | **stale pending の同 token 再送が死んだ `checkout_url` へ収束せず `StaleCheckoutAttemptException` → `?retry=1`** |
| 同 段 3 / 段 4（live pending dedup / 別 plan expire。**`intent=subscription_start` スコープ**） | クエリに `->where('created_at', '>=', BillingCheckoutSession::staleThresholdAt($now))` を付す（SQL 側 live filter） | **stale pending が dedup に hit しない = 新 token で新規 Checkout が成立する** |
| `ReconcileSubscriptionSchedules::expireStaleCheckouts()`（daily） | `->where('created_at', '<', BillingCheckoutSession::staleThresholdAt(CarbonImmutable::now()))->update(['status' => Expired])`（**intent で絞らない** = P8a の setup 行も収束させる） | stale 行を実 DB でも `Expired` へ収束 |

**成立する同値（テスト 14 / 21 で機械固定）**: 任意の org・任意時刻で
`state($org) === PendingCheckout` ⇔ `startCheckout()` が新規 Checkout を作らない（同 plan は段 3 の dedup、別 plan は段 4 の expire 経由）。
`state($org) === ExpiredCheckout`（stale pending のみが理由） ⇒ **新 token の `startCheckout()` は新規 Checkout を作れる**。
**「2 日後に新 token で新規 Checkout」が成立する**（日次 sweeper の実行有無に依存しない）。

##### 冪等状態機械の契約（要件 1-9）

| # | 契約 | 実現 |
|---|---|---|
| 1 | **`organization_id` + `subscription_attempt_token` の UNIQUE** | `UNIQUE(organization_id, intent, attempt_token)`（P2）に **`intent='subscription_start'` を pin** して成立させる。`intent` はサブスク token 空間と **P8a のカード登録 token 空間（`setup_payment_method` / `auto-recharge-setup:{token}`）** を分ける軸であり、チケット token は**別テーブル**のため混線しない |
| 2 | **`initiated_by_user_id` による actor scope** | 行作成時に `initiatedBy()->associate($user)` で**必ず非 null 記録**。**live pending dedup は org-wide のまま**（要件 4）— subscription は org 単位の singleton であり、actor scope にすると同 org の 2 人が同時に live Checkout を持てて**二重 subscription を許す**。actor scope が効くのは **token の所有者判定（要件 7）のみ** |
| 3 | **`pending` / `completed` / `failed` / `expired`** | P2 の `CheckoutSessionStatus`（verbatim）。遷移は C-2 の 1 定義のみ |
| 4 | **同 token 再送は既存 Checkout URL へ収束** | 同 token 行が `isReplayableCheckout($row, $now)` なら `replayCheckout()`: live pending → **保存済み `checkout_url`** / `Completed` → `url=null`。非 replayable（stale pending / `Failed` / `Expired`）→ `StaleCheckoutAttemptException`。**新規 Checkout を作らない** |
| 5 | **Stripe idempotency key 対応** | Stripe へ渡す key は **`'sub_start:'.$attemptToken`**（aigenba verbatim の名前空間）。DB `idempotency_key` 列には**同値を保存**し UNIQUE を張る |
| 6 | **plan code 不一致の token 再利用は 422** | 同 token 行の `plan_code !== $plan->code` → `SubscriptionAttemptPlanMismatchException` → **422**（`assertInvalid(['plan_code'])`）。**`isReplayableCheckout()` より前に判定する** |
| 7 | **他 org・他 user の token は 404** | `attemptTokenIsForeign(string $token, Organization $org, User $user): bool` = `intent=subscription_start` かつ同 `attempt_token` の行が**存在し、かつ (org, initiated_by_user_id) が一致しない**とき true。Controller が **`Gate` より前に 404**（存在オラクル封じ） |
| 8 | **success / cancel webhook との競合と再送** | `settleSubscriptionCheckout()` の遷移は C-2 の 1 定義（`Completed` 終局 = 再送 no-op / **`Failed`・`Expired` からの遅延成功は受理**）。cancel は Stripe から `completed` が来ない → 行は `Pending` のまま → 1 日経過で `state()` が `ExpiredCheckout` |
| 9 | **tenant キーを payload から受け取らない Request 契約** | `BillingCheckoutRequest` / `UpdateBillingContactRequest` が `ProhibitsProtectedKeys`。`organization_id` / `initiated_by_user_id` / `plan_id` は `missing` = 存在するだけで **422** |

```php
// App\Services\Billing\SubscriptionService（新 Service を作らない）
/**
 * @throws SubscriptionAttemptPlanMismatchException|StaleCheckoutAttemptException|CheckoutInProgressException|StripePriceNotSyncedException
 */
public function startCheckout(
    Organization $org, User $user, Plan $plan,
    string $successUrl, string $cancelUrl, string $attemptToken,
    ?SignupFundingChoice $funding,   // T1004: 行の funding_choice に記録する (null = 従来の契約 checkout)
): CheckoutSessionDto;

/** 要件 7: (org, user) スコープ外に同 token 行が在るか。true なら Controller が認可より前に 404 */
public function attemptTokenIsForeign(string $attemptToken, Organization $org, User $user): bool;

final readonly class CheckoutSessionDto {   // verbatim
    public function __construct(
        public string $stripeSessionId, public ?string $url,
        public string $intent, public ?string $planCode,
    ) {}
}
```

`startCheckout()` の手順（`Cache::lock("billing:checkout:start:{$org->id}", 10)->block(5, …)` 内。`LockTimeoutException` は fail-closed で `CheckoutInProgressException('直前の操作が進行中です。数秒お待ちください。')`）:

| # | 段 | 挙動 |
|---|---|---|
| 0 | 事前 assert + 基準時刻 | `Assert::stringNotEmpty($attemptToken, '契約手続きトークンが不正です')` / `assertCheckoutReady($org)` / `assertPriceSynced($basePrice)` / `assertStripeBillablePlan($plan)`。**lock closure 先頭で `$now = CarbonImmutable::now()` を 1 回だけ取る** |
| 1 | 既存 subscription guard | `$org->subscription('default')` が `valid()` なら `'既に有効なサブスクリプションがあります。プラン変更をご利用ください。'`（`Assert::true`） |
| 2 | **同 token 行**（`org` + `intent=subscription_start` + `attempt_token`。`latest('id')->first()`） | `plan_code !== $plan->code` → `SubscriptionAttemptPlanMismatchException`（**要件 6**）<br>`isReplayableCheckout($row, $now)` → `replayCheckout()`（**要件 4**）<br>それ以外（**stale pending 含む** / `Failed` / `Expired`）→ `StaleCheckoutAttemptException('契約手続きの有効期限が切れました。画面を再読み込みして再試行してください。')` |
| 3 | **同 plan の live pending dedup**（`org` + `intent=subscription_start` + `plan_code` + `status=Pending` + **`created_at >= staleThresholdAt($now)`**。**org-wide**） | `CheckoutSessionDto(url: null, …)` → Controller が `back()->with('warning', '既に進行中の Checkout があります。数分お待ちください。')` |
| 4 | **別 plan の live pending を expire**（同じ live filter・同じ intent スコープ） | `gateway->expireCheckoutSession()` が throw → `CheckoutInProgressException('前回の決済セッションの整理に失敗しました。 数分後に再試行してください。')` / `'complete'` → `CheckoutInProgressException('直前の決済が処理中です。数分お待ちください。')` / それ以外 → 行を `Expired` にして続行。**stale な別 plan 行は Stripe 側で既に expire 済みのため照会せず放置** |
| 5 | Stripe 作成 → DB 記録 | `gateway->createSubscriptionCheckout(…, metadata: ['purpose' => 'subscription_start', 'org_ref' => (string) $org->id, 'plan_code' => $plan->code], idempotencyKey: 'sub_start:'.$attemptToken)` → `DB::transaction` で行 INSERT（`intent` / `plan_code` / **`funding_choice` = `$funding?->value`** / `stripe_session_id` / `idempotency_key` / `attempt_token` / `checkout_url` / `status=Pending` / `initiated_by_user_id`） |
| 6 | `UniqueConstraintViolationException` の re-read 収束 | `isUniqueViolation()`（SQLSTATE `23000`/`23505` + index 名 `billing_checkout_sessions_org_intent_attempt_unique` / SQLite は構成列名で一致）以外は rethrow。該当時は `(org, intent, attempt_token)` を再読込 → `isReplayableCheckout($row, $now)` なら `replayCheckout()` / でなければ `StaleCheckoutAttemptException`（**500 にしない**） |

##### C-2: webhook 状態遷移（要件 3 / 8。**遷移条件はこの 1 定義のみ**）

**遷移条件（唯一）**: `settleSubscriptionCheckout()` は **`status !== Completed` の行だけ**を、`checkout.session.completed` payload の `payment_status` が確定した結果へ遷移させる。`Completed` は**終局**（再送・後続 payload は no-op = 冪等）。

- `payment_status ∈ {paid, no_payment_required}` → `Completed` + `completed_at = now()`
- `payment_status === 'unpaid'` → `Failed`
- 上記以外（null 等）→ **遷移しない**（受理のみ）

```
Pending   ──paid|no_payment_required──▶ Completed (+completed_at)
Failed    ──paid|no_payment_required──▶ Completed   … 遅延成功の受理 (非同期決済の後着)
Expired   ──paid|no_payment_required──▶ Completed   … 遅延成功の受理 (段 4 / sweeper で expire 済みの行)
Pending   ──unpaid──────────────────▶ Failed
Expired   ──unpaid──────────────────▶ Failed
Completed ──(任意の payload)─────────▶ Completed    … 終局 = no-op (冪等)
Pending   ──(段 4 の明示 expire / 日次 sweeper)──▶ Expired
```

cancel / 離脱は**遷移を持たない**。`BillingAccess::state()` が C-1 の述語で `PendingCheckout` / `ExpiredCheckout` と読む（**read 経路で DB 書込をしない**）。**遅延成功を受理する根拠**: `Expired` / `Failed` は AI-CUE 側の都合で付く**ローカルな見立て**であり、決済の終局は Stripe が持つ。金銭の付与は `invoice.paid` が真実源（D7）なので本遷移は feedback と冪等の忠実性のみを回復する。

```php
// StripeWebhookProcessor::settleSubscriptionCheckout(array $payload): void
// (1) purpose ガード: metadata.purpose !== 'subscription_start' → 受理のみ / mode !== 'subscription' → 受理のみ
//     (既存 grantPurchasedTickets の 'ticket_purchase' + mode=payment ガード / P8a の mode=setup 分岐と相互排他)
// (2) 真実源は自 DB 行。行不在 → throw = retryable failure
// (3) tenant キー不信: payload の customer / metadata.org_ref は照合のみ (不一致は throw)。org 解決には使わない
// (4) 遷移は C-2 の 1 定義:
//     if ($local->status === CheckoutSessionStatus::Completed->value) { return; }   // 終局 no-op
//     $status = $this->stringAt($payload, 'data.object.payment_status');
//     if (in_array($status, ['paid', 'no_payment_required'], true)) {
//         $local->forceFill(['status' => Completed->value, 'completed_at' => CarbonImmutable::now()])->save();
//     } elseif ($status === 'unpaid') {
//         $local->forceFill(['status' => Failed->value])->save();
//     }                                                                              // それ以外は受理のみ
// (5) T1004 dispatch (下記 C-3)。チケット・プランの付与も plan_code 同期もここでは一切行わない (D7)。
```

##### C-3: T1004 サブスク決済カード流用（D29(ii) で P8a から移譲。aigenba verbatim）

**入力**: P9 が書く `intent=subscription_start` + `funding_choice='auto_recharge'` の `BillingCheckoutSession` 行（= Onboarding/Checkout の funding 2 択で `auto_recharge` を選んだ有償契約）。**事前同意（`recordPreConsent`）は checkout POST 時に記録済み**（`enabled=false` + 同意 4 列）。**適格性の最終判定は Job → `applyReusedPaymentMethod` の fail-closed が担う**。

**(1) dispatch 条件（webhook 同期処理。外向き Stripe API は撃たない = T710 invariant）**

```php
// StripeWebhookProcessor::settleSubscriptionCheckout の末尾 (C-2 の遷移で Completed になった呼び出しのみ)
$paymentStatus  = $this->stringAt($payload, 'data.object.payment_status');
$subscriptionId = $this->subscriptionIdFrom($object);
// subscriptionIdFrom は **string と array{id} の両方を受理する**（Codex Round 15 Critical）。
//   `checkout.session.completed` の `data.object.subscription` は **expandable field** で、
//   **expand 指定が無い通常の payload では string ID**（`"sub_xxx"`）で来る。array を前提にすると
//   **本番で Job が一度も dispatch されない**。array は expand 済み payload（`{id: "sub_xxx", …}`）のみ。
if ($local->funding_choice === SignupFundingChoice::AutoRecharge->value
    && ($paymentStatus === 'paid' || $paymentStatus === 'no_payment_required')
    && $subscriptionId !== null) {
    // dispatch の事実を session に永続化する — setupPending / 着地 flash の「自動的に有効になります」
    // 表示を決済確定済みの契約に限定する出典 (未決済 completed への伝播防止)。
    $local->forceFill(['pm_reuse_dispatched_at' => CarbonImmutable::now()])->save();
    ReuseSubscriptionPaymentMethodJob::dispatch($local->organization_id, $subscriptionId);
}
```

**決済未確定（`payment_status` が `paid` / `no_payment_required` 以外）では dispatch しない**（決済未確定の契約カードでオートリチャージを有効化しない = aigenba の top-up 付与ガードと同一基準）。**再送は C-2 の終局 no-op に従い dispatch されない**（marker の 30 分窓が再送で延びない。aigenba は再送でも dispatch するが Job の `isAutoEnablePending` guard により**結果は同一**であり、差分は窓の延長有無のみ = C-2 の一意化（N-5）から機械的に導かれる帰結）。

**(2) `ReuseSubscriptionPaymentMethodJob`（`tries=3` / `backoff=30`。verbatim）**: org 不在 → return / **`! isAutoEnablePending($org)` → Stripe retrieve 前に return**（不要な外部 API の排除）/ `resolveSubscriptionPaymentMethod()` が null → `Log::warning`（org id + subscription id のみ）+ return（**詰まない**: 請求ページのカード登録 CTA で回復できる）/ それ以外 → `applyReusedPaymentMethod($org, $pm)`。

**(3) `AutoRechargeService::applyReusedPaymentMethod(Organization $org, string $paymentMethodId): bool`（verbatim）**

- setup 経路（`applySetupCompletion`）との違い: **ユーザーは「オートリチャージ用のカード登録」を明示していない**ため、**適格性（`autoEnableEligible`）を先に確認し、不適格なら customer default PM もローカル snapshot も一切変更しない完全 no-op**（fail-closed。`Log::info(reason: no_config|not_eligible)`）。
- 適格 → `gateway->setDefaultPaymentMethod()`（Cashier 冪等実装。副作用は customer の `invoice_settings.default_payment_method` = **v2 同意文言で開示済み**）→ `DB::transaction` で `lockForUpdate` 再取得 → snapshot + `enabled=true` + `failure_count=0` → `return ! $wasEnabled`。
- **TX 内で適格性が失われていたら `RuntimeException`**（「Stripe だけ変更済みの部分適用」を silent no-op にせず顕在化。Job retry で収束 / 継続不適格なら `failed_jobs` で検知）。
- `updateSettings` / `applySetupCompletion` / `recordPreConsent` / `executeAttempt` と**同一 org lock**（`billing:auto-recharge:{org}`）で直列化 = lock 保持中に適格性が変化する経路は構造的に存在しない。`LockTimeoutException` は `RuntimeException` で Job retry へ（握り潰さない）。
- `enabledNow` のときのみ `notifyAutoEnabled()`（通知失敗は `report()` で握り、Job を失敗させない = `applySetupCompletion` と同型）。

**(4) 「処理中」表示の窓（`settingsFor().setupPending`。P8a の (a) に (b) を OR で追加）**

```php
$setupPending = ! $hasPm && (
    $this->hasRecentCompletedSetup($org)                                   // (a) P8a: カード登録 Checkout 完了
    || ($pendingAutoEnable && $this->hasRecentAutoRechargeFundedSignup($org))  // (b) T1004: PM 流用 Job の収束待ち
);
```
`hasRecentAutoRechargeFundedSignup()` = `intent=subscription_start` + `funding_choice=auto_recharge` + `status=completed` + **`pm_reuse_dispatched_at >= now()->subMinutes(config('billing.auto_recharge.setup_pending_window_minutes'))`**（既定 30）。**基準は `pm_reuse_dispatched_at`**（`updated_at` / `completed_at` は完了後の別更新・未決済 completed で窓が誤って開くため使わない）。**(b) は `pendingAutoEnable=true` のときだけ**（v1 失効・再同意が必要な org で 30 分間カード登録 CTA / 再同意導線を隠さない）。

**(5) 着地 flash（`resolveAutoRechargeLanding`。`?session_id` 分岐のみ）**

`?session_id` を `$organization->checkoutSessions()` の **org スコープ**で引き、`intent=subscription_start` + `status=completed` + `funding_choice=auto_recharge` を検証できたときだけ **`billing.index?highlight=auto-recharge` への 303 + `with('info', …)`** へ変換する（それ以外の `session_id` は従来どおり `resolveBillingFeedback` に委ねる）。文言は 2 分岐（verbatim）:

- `pm_reuse_dispatched_at !== null && isAutoEnablePending($org)` → `'お支払いを受け付けました。オートリチャージは、ご契約のお支払いカードで自動的に有効になります。反映されない場合は、この画面から設定できます。'`
- それ以外（同意失効・未決済 completed 等） → `'お支払いを受け付けました。オートリチャージの設定はこの画面から確認できます。'`（**確定表現を避けた fail-closed な誘導文言**）

**(6) 同意版（D29-b）**: `config('billing.auto_recharge.consent_version') = 'v2'`。`BillingCheckoutRequest` が **checkout 開始前に現行版との完全一致を検証**（不一致・欠落は 422 = `recordPreConsent` にも Stripe にも到達しない）。Controller は `Gate::authorize('manageBilling')` の後・`startCheckout()` の前に `recordPreConsent($org, $user, new AutoRechargeConsentDto($consentVersion))` を呼ぶ（`CheckoutInProgressException` → `back()->with('error', …)`）。**Checkout が後段で失敗・放棄されても同意 row は無害**（`enabled=false` = 課金は一切発生しない）。

##### Controller の実行順（要件 7 = セキュリティ不変条件 #2「不整合は認可より前に 404」）

```php
public function checkout(BillingCheckoutRequest $request, SubscriptionService $subscriptions, AutoRechargeService $autoRecharge): SymfonyResponse|RedirectResponse
{
    $organization = $this->resolveCurrentOrganization($request);
    $user = $request->user();  Assert::isInstanceOf($user, User::class);
    $attemptToken = $request->validated('subscription_attempt_token');  Assert::string($attemptToken);

    // (1) 他 org / 他 user の token は 404 (403 にしない = 存在オラクル封じ)。Gate より前。
    abort_if($subscriptions->attemptTokenIsForeign($attemptToken, $organization, $user), 404);
    // (2) 認可
    Gate::authorize('manageBilling', $organization);
    // (3) T1004: funding=auto_recharge は事前同意 (enabled=false) を Checkout 開始前に記録する。
    $fundingRaw = $request->validated('funding_choice');
    $funding = is_string($fundingRaw) ? SignupFundingChoice::from($fundingRaw) : null;
    if ($funding === SignupFundingChoice::AutoRecharge) {
        $consentVersion = $request->validated('consent_version');  Assert::stringNotEmpty($consentVersion);
        try {
            $autoRecharge->recordPreConsent($organization, $user, new AutoRechargeConsentDto($consentVersion));
        } catch (CheckoutInProgressException $e) {
            return back()->with('error', $e->getMessage());
        }
    }
    // (4) plan 解決 → 冪等開始
    $planCode = $request->validated('plan_code');  Assert::string($planCode);
    $plan = Plan::query()->where('code', $planCode)->where('is_active', true)->firstOrFail();

    try {
        $result = $subscriptions->startCheckout(
            $organization, $user, $plan,
            route('billing.index').'?session_id={CHECKOUT_SESSION_ID}',
            route('billing.plans'),
            $attemptToken,
            $funding,
        );
    } catch (SubscriptionAttemptPlanMismatchException $e) {
        throw ValidationException::withMessages(['plan_code' => $e->getMessage()]);   // 422
    } catch (StaleCheckoutAttemptException) {
        return redirect()->route('billing.index', ['retry' => 1]);                     // → checkout_retry_required
    } catch (CheckoutInProgressException|StripePriceNotSyncedException $e) {
        return back()->with('error', $e->getMessage());
    }

    if ($result->url === null) {
        return $this->isAttemptCompleted($organization, $result->stripeSessionId)
            ? redirect()->route('billing.index', ['replayed' => 1])                    // → purchase_already_received
            : back()->with('warning', '既に進行中の Checkout があります。数分お待ちください。');
    }

    return Inertia::location($result->url);
}
```

**禁止事項 #7**（`redirect()->intended()`）は使わない。**禁止事項 #8**: `Billing/Plans` / `Onboarding/Checkout` の申込ボタンは token / plan / 同意の状態で disabled にせず、押下時に上記のエラー・422 を表示する。

##### 着地 feedback（`resolveBillingFeedback`。verbatim。UI は raw query を見ない）

`index` は **`resolveAutoRechargeLanding()` を先に評価**し（該当時は 303 = C-3(5)）、非該当なら以下の feedback を返す。

| query | 条件 | kind / 文言 |
|---|---|---|
| `?portal` | **`session('error')` が文字列なら `null`**（成功偽装の抑止。aigenba F-2-03 verbatim） | `portal_returned` /「お支払い管理画面から戻りました。」 |
| `?session_id=` | `$organization->checkoutSessions()->where('stripe_session_id', …)` で **org スコープ**（未知 / 他 org は `null` = 偽 success 排除）。**`intent !== subscription_start` も `null`**（fail-closed。P8a の `setup_payment_method` 行が同テーブルに実在するため必須） | `Completed` → `purchase_received` /「お支払いを受け付けました。プランへの反映には数分かかる場合があります。」<br>`Pending` → `purchase_processing` /「お支払いを確認しています。プラン反映までしばらくお待ちください。」<br>`Failed` / `Expired` → **`null`**（verbatim） |
| `?replayed` | — | `purchase_already_received` /「この内容のお支払いは既に受け付け済みです。」 |
| `?retry` | — | `checkout_retry_required` /「お手続きの有効期限が切れました。画面を再読み込みして再試行してください。」 |

##### aigenba からの非 verbatim 点と根拠（5 点。他は verbatim。PII / DS / disabled 禁止は §横断決定 v2 の既決事項）

| # | 点 | aigenba | AI-CUE (P9) | 根拠 |
|---|---|---|---|---|
| N-1 | **同 token・別 plan** | `replayCheckout()` で**保存済み session の plan** の Checkout URL へ収束 | **422**（`SubscriptionAttemptPlanMismatchException`） | `Billing/Plans` は 1 render = 1 token のため「Starter を押して戻り Standard を押す」が同 token・別 plan として実在する。verbatim だと**押した plan と違う plan の Checkout に着地**する。AI-CUE 先例（`TicketCheckoutService:108-121`）とも整合。**ユーザー指示（P9 要件 6）による明示決定** |
| N-2 | **`initiated_by_user_id` の actor scope** | subscription 経路は org スコープ（actor scope は `TicketService` = T905 R1/R2 Critical で採用済み） | **token 所有者判定（要件 7 の 404）にのみ actor scope を適用**。dedup は org-wide のまま | aigenba 自身が同一の replay 機構に対し T905 で下した結論を、P2 が移植済みの `initiated_by_user_id` 列に適用する。**dedup を actor scope にはしない**（subscription の org singleton 性を壊すため） |
| N-3 | **`idempotency_key` 列の値** | `sprintf('sub_start:%d:%s:%d:%d', org, priceId, seatOverflow, floor5min(now))`（T680 で dedup 用途からは外れた**遺物**） | **Stripe へ渡した key と同値**（`'sub_start:'.$attemptToken`） | 5 分バケット key は同 org・同 price の別 token が同バケットに入ると UNIQUE 衝突し、`isUniqueViolation()` に拾われず **500** になる死角がある。**seat 引数は AI-CUE に存在しない**（原則 4）ため 5 分バケット式はそのままでは移植不能でもある |
| N-4 | **live 判定の共有（C-1）** | 閾値 `subDay()` は `BillingAccess::state()` と `ReconcileSubscriptionSchedules::expireStaleCheckouts()` に**別々の literal** で置かれ、`startCheckout()` の dedup / replay は **`status=Pending` + URL のみ**で live を判定する | **`BillingCheckoutSession::staleThresholdAt()` / `isLivePending()` を単一出典にし、`state()` / `startCheckout()` の段 2・3・4 / sweeper の 4 経路が共有** | **Codex Critical (1)**。aigenba は daily sweeper に依存して整合を保つが、判定の正しさを sweeper の実行タイミングに依存させないために述語を共有する（値は `subDay()` のまま = 原則 3 を侵さない）。AI-CUE 先例 `TicketCheckoutSession::isLivePending(CarbonImmutable $now)` |
| N-5 | **遅延成功の受理（C-2）** | `markLocalCheckoutCompleted()` は **`Pending` 以外は触らない**。`Failed` / `Expired` は終局 | **`Completed` 以外は payload の判定結果へ遷移**（`Failed` / `Expired` → `Completed` を受理）。**帰結として T1004 dispatch も「遷移が起きた呼び出し」限定**になる（再送で marker 窓が延びない。Job の `isAutoEnablePending` guard により**結果は同一**） | **Codex Critical (2)**。AI-CUE は**日次 sweeper が全 stale pending を `Expired` にする**ため verbatim だと「支払ったのに feedback が恒久 null」「決済済みなのに PM 流用が走らない」が現実に起きる。金銭は D7 の `invoice.paid` が真実源のため台帳は動かない。**aigenba へ報告し、先方が Pending-only を維持するなら差分として保持する**（原則 5 の運用） |

**T1004 の読み替え 1 点（非 verbatim ではない）**: aigenba の `intent=SignupFunding` を **`intent=SubscriptionStart`** に読み替える（`hasRecentAutoRechargeFundedSignup` / dispatch 分岐 / 着地 flash）。`SignupFunding` intent は **P2 が原則 4 で非移植**（AI-CUE の契約 checkout は `SubscriptionStart` の 1 本）と決定済みであり、**新 intent を作らず `funding_choice` 列を additive に足すだけで T1004 が成立する**（D29 の根拠列に明記済み）。列名・値・窓・文言・fail-closed 条件はすべて verbatim。

##### PII（不変条件 #6。email だけでなく name も閉じる）

```php
// App\Models\Organization
class Organization extends Model implements CipherSweetEncrypted, /* 既存 */
{
    use Billable, HasFactory, RoutesNotifications, SoftDeletes, UsesCipherSweet;

    /** @var list<string> billing_contact_* は含めない (UpdateBillingContactAction が明示代入) */
    protected $fillable = ['name', 'slug'];

    public static function configureCipherSweet(EncryptedRow $encryptedRow): void
    {
        // 両列とも nullable のため addOptionalTextField (addField は null で fieldNotOptional 例外 = Inquiry の先例)
        $encryptedRow
            ->addOptionalTextField('billing_contact_email')
            ->addOptionalTextField('billing_contact_name')
            // 検索契約: 請求調査 (Stripe Dashboard の請求先メール → AI-CUE 組織の逆引き = 返金・
            // 二重課金の一次対応で唯一の特定経路) のため email のみ blind index 化する。
            ->addBlindIndex('billing_contact_email', new BlindIndex('organization_billing_contact_email_index', [new Lowercase]));
        // billing_contact_name は blind index を張らない (等値検索の要求が無い = 検索が必要な項目だけ whereBlind)。
    }

    /** 請求通知の宛先: billing_contact_email 正本 → owner email fallback (aigenba IV-1/IV-N1) */
    public function routeNotificationForMail(Notification $notification): ?string;

    /** @return HasMany<BillingCheckoutSession, $this> */
    public function checkoutSessions(): HasMany;
}
```

- **検索契約**: `billing_contact_email` の検索は **`Organization::whereBlind('billing_contact_email', 'organization_billing_contact_email_index', $value)` のみ**。保存値は `EmailNormalizer::normalize()` 済みのため検索入力も**同一正規化を通す**。
- **`billing_contact_name` の検索は契約として存在しない**（blind index 行を作らない）。
- **一意制約は張らない**（複数組織が同一請求先メールを持つのは正当）。
- **cast**: `casts()` に `billing_contact_*` を**追加しない**（CipherSweet が row-level で暗号化/復号する。`encrypted` cast を重ねると二重暗号化）。
- **soft delete**: `Organization` は `SoftDeletes` のため blind index 行は残る（hard delete しない）。
- **更新経路**: `PATCH /billing/contact` → `Gate::authorize('manageBilling', $organization)` + **current-org scope**（route parameter を持たないため cross-org 指定が構造的に不能）→ `UpdateBillingContactAction`。

```php
// App\Http\Controllers\Billing\BillingController
public function updateBillingContact(UpdateBillingContactRequest $request, UpdateBillingContactAction $action): RedirectResponse
{
    $organization = $this->resolveCurrentOrganization($request);
    Gate::authorize('manageBilling', $organization);
    $action->execute($organization, UpdateBillingContactData::fromRequest($request));

    return back()->with('info', '請求先情報を更新しました。');
}
```

##### DTO 形状（PHP `@phpstan-type` と `resources/js/types/billing.ts` を exact 対で保守）

```
BillingFeedbackShape  = { kind: 'purchase_received'|'purchase_processing'|'purchase_already_received'
                                |'checkout_retry_required'|'portal_returned',
                          message: string }
BillingContactShape   = { email: string|null, name: string|null, fallbackEmail: string|null }  // fallbackEmail = owner email
BillingDashboardShape = { …P8b の全項目 (billingState / plan / balance / quota), …P8a の autoRecharge (無変更),
                          feedback: BillingFeedbackShape|null, billingContact: BillingContactShape }
BillingPlansPageShape = { plans: list<PricingPlanShape>, billingState: BillingStateValue, currentPlanCode: string|null,
                          canManage: bool, subscriptionAttemptToken: string }
OnboardingCheckoutShape = { …P3 の全項目, …P8a の consentTerms, subscriptionAttemptToken: string }
```

**UI**: `Billing/Index.svelte` は `templates/PageContainer` / `molecules/PageHeaderSection` / `templates/PageContent`（T071 primitive）配下に feedback バナーと `BillingContactForm` を置く。DS token のみ（hex 直書き禁止）。アイコンは `@lucide/svelte`（`CircleCheck` / `Clock` / `Receipt`）。**判定源は `page.billingState`**。`EffectivePlan` は存在しない。

#### PHPStan 適合チェック

- `BillingCheckoutSession::$status` / `$intent` / **`$funding_choice`** は **P2 verbatim の plain string 列**（enum cast ではない）。比較は `$row->status === CheckoutSessionStatus::Pending->value` / `$row->funding_choice === SignupFundingChoice::AutoRecharge->value` の**文字列比較**で書く（cast 前提の enum 比較は `alwaysFalse`）。
- `BillingCheckoutSession::$created_at` / **`$pm_reuse_dispatched_at`** は `Carbon|null`（`'pm_reuse_dispatched_at' => 'datetime'` cast + `@property Carbon|null`）。`isLivePending()` は `=== null ||` で null を明示分岐（`?->` で握り潰さない）。`staleThresholdAt()` は `CarbonImmutable` を受けて返す純関数（`now()` を内部で呼ばない = テストが時刻を注入できる）。
- `Cache::lock()->block()` は `mixed` を返すため、`TicketCheckoutService` と同じく `Assert::isInstanceOf($result, CheckoutSessionDto::class)`（`applyReusedPaymentMethod` は `/** @var bool $enabledNow */` = aigenba verbatim の再表明）で絞る。
- `attemptTokenIsForeign()` は `->exists()` を返す `bool`。`where(fn (Builder $q) => …)` の closure 引数に `@param Builder<BillingCheckoutSession>` を付す。
- **`SignupFundingChoice` は enum で比較**（`$funding === SignupFundingChoice::AutoRecharge`）。`$request->validated('funding_choice')` は `mixed` → `is_string()` 判定後に `::from()`（P8a の `ActivatePersonalController` と同一様式 = 分岐網羅を PHPStan に見せる）。`?SignupFundingChoice` 引数は `$funding?->value` で `string|null` に落とす。
- `StripeWebhookProcessor::subscriptionIdFrom(array $object): ?string` — `$object['subscription']` は `mixed`。
  **string（通常 payload = expandable field 未 expand）と `array{id: string}`（expand 済み payload）の両方を受理する**
  （Codex Round 15 Critical: array 前提だと本番で T1004 が一度も発火しない）。実装は
  `$v = $object['subscription'] ?? null; if (is_array($v)) { $v = $v['id'] ?? null; } return is_string($v) && $v !== '' ? $v : null;`
  （既存 `stringAt()` と同じ narrow 様式。それ以外の型は null = fail-closed で dispatch しない）。`payment_status` は `in_array($status, ['paid','no_payment_required'], true)`（Stripe 値集合は enum 化しない = payload 由来の外部語彙）。
- `AutoRechargeGatewayInterface::resolveSubscriptionPaymentMethod(): ?string` は `@return non-empty-string|null`。実装の `CashierAutoRechargeGateway::resolvePaymentMethodFromSubscription(\Stripe\Subscription $s): ?string` は `$s->default_payment_method` の `string|PaymentMethod|null` を `instanceof` で分岐し、`is_string($c) && $c !== ''` で `non-empty-string` へ narrow（fallback の `latest_invoice.payments.data[].payment.payment_intent` は `instanceof Invoice` / `instanceof PaymentIntent` で明示分岐）。
- `ReuseSubscriptionPaymentMethodJob` は **`public readonly int $organizationId` / `public readonly string $stripeSubscriptionId`** のみを持つ（`SerializesModels` は付けるが Model 参照は保持しない = verbatim）。`handle(AutoRechargeGatewayInterface $gateway, AutoRechargeService $autoRecharge): void` の DI 解決は container 型で確定。`Organization::query()->find()` の戻り値は `! $org instanceof Organization` で narrow。
- `Log::warning` / `Log::info` の context は `array<string, scalar|null>`。
- `BillingFeedbackDto::toArray()` は `@phpstan-type SimpleBillingFeedbackKind` + `@return BillingFeedbackShape`。`$this->kind->value` は `string` に広がるため `/** @var SimpleBillingFeedbackKind $kindValue */` で literal union へ narrow（型の widen ではなく enum → literal の再表明）。
- `resolveBillingFeedback()` / `resolveAutoRechargeLanding()` の `$request->query('session_id')` は `mixed` → `is_string($x) && $x !== ''` で narrow。`$request->session()->get('error')` も `is_string()` で判定（verbatim）。
- `Organization::routeNotificationForMail(): ?string` — `EmailNormalizer::normalize(string): string` は非 null 引数を要求するため `is_string() && trim() !== ''` で narrow してから渡す（AI-CUE の既存 `EmailNormalizer` を改変しない）。
- `UpdateBillingContactData::fromRequest()` は `$request->string(…)->toString()` + `Assert::stringNotEmpty()`、name は `mixed` を `is_string() && trim() !== ''` で narrow（verbatim）。
- `StripeGatewayInterface::createSubscriptionCheckout()` の `array $metadata` は `@param array<string, string>`。`buildSubscriptionSessionPayload()` は `@return array{mode: 'subscription', customer: string, line_items: …, subscription_data: array{metadata: array<string, string>, payment_settings: array{save_default_payment_method: 'on_subscription'}}, success_url: string, cancel_url: string}` で固定。
- `isUniqueViolation(QueryException $e)`: `$e->getCode()` は `mixed` → `in_array($e->getCode(), ['23000','23505'], true)`（strict 比較で型不一致は false）。**INSERT は `UniqueConstraintViolationException` で catch** し driver 差の判定は `isUniqueViolation()` に委ねる。
- `ReconcileSubscriptionSchedules::expireStaleCheckouts()` の `->update()` 戻り値は `int`。
- 型を緩めた回避・baseline 化は行わない（禁止事項 2）。

#### テスト計画

**テストファースト**。`RefreshDatabase` グローバル + `--parallel`（個別 `DatabaseTransactions` 禁止）。テストデータは Factory のみ。時刻依存は `travelTo()` / Factory の `stale()` / `pmReuseDispatched()` state で固定。Stripe は `FakeStripeGateway` / `FakeAutoRechargeGateway` を bind（実 API を撃たない）。

新規 `tests/Feature/Billing/SubscriptionCheckoutIdempotencyTest.php`（**要件 1-7**）:
1. 同一 `subscription_attempt_token` + 同一 plan の 2 連投で **`billing_checkout_sessions` が 1 行**、2 回目は**既存 `checkout_url` へ収束**し fake の作成呼び出しが **1 回**（要件 1 / 4）。
2. 同一 token + **別 plan_code** → **422**（`assertInvalid(['plan_code'])`）。行は増えず Stripe 呼び出しも増えない（要件 6 / N-1）。
3. `idempotency_key === 'sub_start:'.$attempt_token`、かつ同 key の再呼び出しで fake が**同一 sessionId** を返す（要件 5）。ticket 側 `purchase:{token}` / **P8a の `auto-recharge-setup:{token}`** と**衝突しない**（key 空間分離）。
4. **他 org の token** → **404**（`Gate` 到達前。`manageBilling` を持つ owner でも 404）。**同 org の他 user の token** → **404**（要件 7 / 2）。いずれも**行が作られない**。
5. `completed()` 行の token 再送 → `billing.index?replayed=1`、Stripe 呼び出し 0（要件 4）。
6. `expired()` / `failed()` 行の token 再送 → `billing.index?retry=1`。
7. **別 token・同 plan の live pending** → `back()->with('warning')`、**新規行なし・Stripe 呼び出しなし**（org-wide dedup）。**同 org の別 user が別 token で申し込んでも 1 本に収束**（要件 2）。
8. **別 token・別 plan の live pending**: `expireCheckoutSession` が `'complete'` → `CheckoutInProgressException` → `back()->with('error')`、**新規行なし**。throw → 停止し local 行は `Pending` のまま。`'expired'` → 旧行が `Expired` になり新規発行が続行。
9. `UniqueConstraintViolationException` 注入（並行 race 模擬）→ **500 にならず** replay / stale へ収束。**attempt_token 以外の unique 違反は rethrow**。
10. 既に `valid()` な subscription を持つ org → `'既に有効なサブスクリプションがあります。…'` で停止（行なし）。
11. `initiated_by_user_id` が**必ず非 null** で記録される（要件 2）。
11b. **P8a の `intent=setup_payment_method` 行が同 org に live pending で在っても**、段 2/3/4 に一切干渉しない（同 `attempt_token` の setup 行があっても subscription checkout は新規発行する = `intent` による token 空間分離の回帰）。

新規 `tests/Feature/Billing/CheckoutStaleThresholdTest.php`（**C-1**）:
12. **`created_at` を 2 日前にした pending 行があるとき、新 token の POST が新規 Checkout を作る**（行 2 行・Stripe 作成 1 回・`Inertia::location`）。**warning に落ちない**。
13. 同 token + **stale pending** の再送 → **`?retry=1`**。`created_at` が**境界内**（23h59m 前）なら既存 URL へ replay（境界の両側を固定）。
14. **`state()` と `startCheckout()` の同値**: `PendingCheckout` のとき新規作成しない / `ExpiredCheckout`（stale pending のみが理由）のとき新 token は新規作成できる、を `travelTo()` で 23h / 25h の 2 点固定。
15. `billing:reconcile-schedules` 実行で **stale pending のみが `Expired`、live pending は `Pending` のまま**（`ReconcileSubscriptionSchedulesTest` に追加。既存 2 工程の期待は不変）。**stale な `setup_payment_method` 行も `Expired` になる**（intent 無しフィルタの verbatim）。**sweeper 未実行でも 12/13/14 が成立する**。

新規 `tests/Architecture/CheckoutLiveThresholdSingleSourceTest.php`（**C-1 の構造的封じ**）:
16. `BillingAccess.php` / `SubscriptionService.php` / `ReconcileSubscriptionSchedules.php` のソースに **`subDay(` / `subDays(` が出現しない**（閾値 literal は `staleThresholdAt()` にのみ存在する）。

新規 `tests/Feature/Billing/SubscriptionCheckoutWebhookRaceTest.php`（**要件 8 / C-2**）:
17. `checkout.session.completed`（purpose=subscription_start / mode=subscription / payment_status=paid）→ 行 `Completed` + `completed_at`。**チケット付与も `plan_code` 書き換えも起きない**（`ticket_ledger_entries` 0 件 / `organizations.plan_code` 不変 = D7 境界）。
18. 同一 event の**再送** → 冪等（`Completed` のまま `completed_at` 不変 = 終局 no-op）。
19. **`Expired` 行への遅延 completed（paid）→ `Completed`** / **`Failed` 行への paid 再送 → `Completed`** / **`Completed` 行への unpaid → 遷移しない**。
20. `payment_status=unpaid` → `Pending`→`Failed` / `Expired`→`Failed`。`payment_status=null` → **遷移しない**。
21. **cancel 相当** → 行は `Pending` のまま。`created_at` を 2 日前にすると `state()` が **`ExpiredCheckout`** を返し、**新 token で新規 Checkout が作れる**。`state()` 実行で**行が書き換わらない**。
22. 行不在の completed → throw = retryable failure（**silent 付与しない**）。
23. `customer` / `metadata.org_ref` 不一致 → throw（tenant キー不信）。
24. **purpose ディスパッチの排他**: `purpose=ticket_purchase` は `settleSubscriptionCheckout` に入らず既存 `grantPurchasedTickets` が動く（`TicketPurchaseWebhookTest` が**無改変で green**）。**`mode=setup`（P8a）も入らない**（`SetDefaultPaymentMethodJob` 分岐が従来どおり = `AutoRechargeWebhookTest` が無改変で green）。

新規 `tests/Feature/Billing/SubscriptionPmReuseTest.php`（**T1004 = Codex Round 14 Critical (1)**。移植元 `/tmp/aigenba/tests/Feature/Billing/SubscriptionPmReuseTest.php`）:
47. `funding_choice=auto_recharge` + `payment_status=paid` の completed → **`pm_reuse_dispatched_at` が立ち `ReuseSubscriptionPaymentMethodJob` が dispatch される**（`Queue::fake()`）。
48. `payment_status` が `unpaid` / null → **dispatch されず marker も立たない**（契約未確定ガード）。
49. `funding_choice=later` / `null`（Plans 経路） → dispatch されない。
50. **`subscription` の payload 型（Codex Round 15 Critical。両形を必須で検証する）**: **(a) string ID `['subscription' => 'sub_x']` → dispatch される**（**expand 指定の無い通常の `checkout.session.completed` は `subscription` が string で来る = 本番の主経路**。array 前提だと**本番で一度も発火しない**）/ **(b) expanded object `['subscription' => ['id' => 'sub_x']]` → id を取り出して dispatch** / (c) `subscription` が null / 空文字 / その他の型 → **dispatch されない**（fail-closed）。
51. **事前同意あり（v2）** → `setDefaultPaymentMethod` 呼び出し + snapshot + `enabled=true` + 通知 1 通（`applyReusedPaymentMethod`）。
52. **中核 fail-closed**: 同意失効（v1 残存）では **customer default PM もローカル snapshot も一切変更されない**（gateway 呼び出し 0 / `enabled=false` のまま）。
53. `config` なし / `disabled_reason` あり → **完全 no-op**（gateway 呼び出し 0）。
54. 再実行（`enabled` 遷移済み）→ no-op で**通知も再送されない**。
55. 空文字 PM → `InvalidArgumentException`（fail-fast）。
56. **Job 一気通貫**（事前同意 → PM 解決 → `enabled=true`）/ **軽量 guard**（`isAutoEnablePending=false` なら `resolveSubscriptionPaymentMethod` を**呼ばない**）/ **PM 解決不能（null）→ no-op**（warning ログ + カード登録 CTA で回復可能）/ **org 不在は例外なしで return**。
57. **部分適用の顕在化**: default PM 更新後に適格性が失われたら **`RuntimeException`**（silent no-op にしない）。
58. **`setupPending`**: 契約完了 + 有効な事前同意の待機中 → **true** / 同意失効（v1）→ **false**（再同意フォールバック UI を隠さない）/ `funding=later` の契約完了 → **false** / dispatch から **30 分超で false**（`pm_reuse_dispatched_at` 基準の窓）/ **marker なし（未決済 completed）→ false**。
59. **着地 flash**: `?session_id` が自 org の `subscription_start` + `completed` + `auto_recharge` 行 → **`?highlight=auto-recharge` へ 303**。marker あり + `isAutoEnablePending` → 「自動的に有効になります」/ それ以外 → 確定表現を避けた誘導文言。**他 org / `intent=setup_payment_method` の session_id は 303 しない**（IDOR 防御 = feedback と同じ org スコープ）。
60. **同意 fail-closed（Request 層）**: `billing.checkout` に `funding_choice=auto_recharge` + `consent_version` 欠落 → **422**（`'自動購入への同意が必要です。'`）/ 旧版 `v1` → **422**（`'自動購入の同意内容が更新されています。…'`）。いずれも **`ticket_auto_recharges` 行も `billing_checkout_sessions` 行も増えず Stripe 呼び出し 0**（`recordPreConsent` 到達前）。
61. **`consent_version='v2'` 改定の効果**: P8a 期の v1 同意行を持つ org は `pendingAutoEnable=false` / `requiresReconsent=true` になり、**PM 流用でも自動有効化されない**（`reconsentRequiredFor` による自動失効 = fail-closed）。
62. **C-2 との結合**: `Expired` 行への遅延 completed（paid）でも **marker が立ち Job が dispatch される**（遅延成功が PM 流用へ届く）。**同一 event の再送では marker が更新されない**（終局 no-op）。
63. **同意記録の順序**: `funding_choice=auto_recharge` の POST は **`recordPreConsent`（`enabled=false` + 同意 4 列） → `startCheckout`** の順で走り、Checkout 作成が失敗しても同意 row は残り**課金は発生しない**（`ticket_auto_recharge_attempts` 0 件）。

新規 `tests/Feature/Billing/BillingFeedbackTest.php`:
25. `?session_id=` が自 org の `Completed` 行 → `feedback.kind === 'purchase_received'`。`Pending` → `purchase_processing`。**`Failed` / `Expired` → `null`**（verbatim）。
26. **他 org / 未知の `session_id`** → `null`（偽 success 排除）。**`intent=setup_payment_method`（P8a の実在行）→ `null`**（fail-closed）。
27. `?portal` + `session('error')` あり → `null`。error 無し → `portal_returned`。
28. `?replayed` → `purchase_already_received` / `?retry` → `checkout_retry_required`。
29. **C-2 との結合**: `Expired` 行が遅延 completed で `Completed` になった後の `?session_id` 着地が `purchase_received` を出す。

新規 `tests/Feature/Billing/BillingContactPiiTest.php`（**不変条件 #6**）:
30. `PATCH /billing/contact` 後、**`DB::table('organizations')` の生値が両列の平文と一致しない**。model 経由の読み出しは平文に復号される。
31. **平文 where が hit しない**（`where('billing_contact_email', $plain)->exists()` が false）。`whereBlind(…)` が該当 org を引く。
32. **`billing_contact_name` の blind index 行が存在しない**（検索契約の固定）。
33. 大文字混じり入力 → 正規化後の小文字で `whereBlind` が hit。

新規 `tests/Feature/Billing/UpdateBillingContactTest.php`:
34. **email 変更時のみ** `SyncBillingCustomerDetails` が dispatch（`Queue::fake()`）。**name のみ変更では dispatch されない**。
35. `stripe_id === null` の org では dispatch されない。transaction rollback で発火しない（`afterCommit`）。
36. **認可**: member は 403 / 未ログインは redirect。**current-org scope**: org 切替後の PATCH が切替後 org のみを更新。
37. **payload 契約**（要件 9 / 不変条件 #1）: `organization_id` / `initiated_by_user_id` / `plan_id` を混ぜると **422**。`billing_contact_email` 欠落 → 422。
38. `routeNotificationForMail()` が `billing_contact_email` 正本 → 未設定時に owner email へ fallback。

新規 `tests/Architecture/BillingContactEncryptionInvariantTest.php`:
39. `Organization` が `CipherSweetEncrypted` を実装し、`configureCipherSweet()` に**両列**が登録されている。
40. `organizations.billing_contact_*` の列型が `text`。
41. `billing_contact_*` が `$fillable` に無い。**`billing_checkout_sessions.pm_reuse_dispatched_at` も `$fillable` に無い**（webhook の `forceFill` 専用 marker）。

更新テスト:
42. `BillingPageTest` 相当 — `subscription_attempt_token` 欠落 / 非 ULID → 422。Index props に `feedback` / `billingContact`。
42b. **P8a 産出テストの期待更新（削除しない）**: `AutoRechargePreConsentTest` / `AutoRechargeEndpointTest` / `AutoRechargeServiceTest` の `consent_version` 期待を **`'v1'` → `'v2'`**（`setupPending` の (a) ケース・`pendingAutoEnable` の既存期待は不変）。
42c. **webhook 同期処理の invariant**（P8a 産出）に `settleSubscriptionCheckout` を追加 — **外向き Stripe API を撃たない**（PM 解決は Job 側のみ）。

JS（Vitest）:
43. 新規 `tests/js/pages/Billing/BillingContactForm.test.ts` — 未入力でも **submit が disabled にならない**（禁止事項 #8）。押下時にサーバ 422 の `errors.billing_contact_email` が表示される。
44. 更新 `tests/js/pages/Billing/Index.test.ts` — `feedback` の **5 kind** が対応バナーを描画し、`null` で何も描画しない。**raw query を参照しない**。`?highlight=auto-recharge` で `AutoRechargeCard` が強調される。
45. 更新 `tests/js/pages/Billing/Plans.test.ts` — POST body に `subscription_attempt_token` が載る（**`funding_choice` は載らない**）。ボタンは常に enabled。422 が `plan_code` エラーとして表示される。
45b. 更新 `tests/js/pages/OnboardingCheckout.test.ts` — 有償プランの POST body に **`subscription_attempt_token` + `funding_choice`**、`auto_recharge` 選択時のみ **`consent_version`** が載る。**同意ダイアログ未操作でも申込ボタンは enabled**（禁止事項 #8）。
46. 影響（無変更で green）: `tests/js/architecture/{page-shell-structure,ds-purity,atomic-import-graph,lucide-scoped-import}.test.ts`。

#### リスク

| リスク | 緩和 |
|---|---|
| **`CashierStripeGateway` が `newSubscription()->checkout()` を捨てることで Cashier の webhook が `subscriptions` 行を作れなくなる**（`subscription_data.metadata.{name,type}` 依存。落とすと**課金成立なのに subscription 行が無い** = `state()` が `NoSubscription` に落ち P4 後に締め出し） | `buildSubscriptionSessionPayload()` を public pure メソッドにし、**`metadata.name='default'` / `type='default'` + `payment_settings.save_default_payment_method='on_subscription'` を含むことを gateway ユニットテストの invariant として固定**（後者は T1004 の第一候補 PM が埋まる前提でもある）。テスト 17 で「completed webhook 後に `customer.subscription.created` が来ると `subscriptions` 行が作られる」ことを確認する。**この invariant テストが payload 変更の唯一の入口** |
| **T1004 が「同意していない自動課金」を作る** | 3 段の fail-closed: (1) Request 層で `consent_version` の現行版一致を **checkout 開始前**に検証（テスト 60）/ (2) `recordPreConsent` は `enabled=false` の同意 row のみ（課金経路に触れない）/ (3) `applyReusedPaymentMethod` が **適格性先行**で不適格なら Stripe にも DB にも触らない完全 no-op（テスト 52 / 53）。さらに `consent_version='v2'` により **P8a 期の v1 同意は自動失効**（テスト 61）。同意文言・版番号・既定値は **aigenba verbatim**（原則 3） |
| **決済未確定の契約カードでオートリチャージが有効になる** | dispatch 条件が `payment_status ∈ {paid, no_payment_required}` の allowlist（テスト 48）。**`pm_reuse_dispatched_at` は dispatch した事実のみを表す永続マーカー**であり、`setupPending` / 着地 flash の「自動的に有効になります」表示は**この marker + `isAutoEnablePending` の AND**（テスト 58 / 59）。`updated_at` / `completed_at` は窓の基準に使わない（verbatim） |
| **PM 流用が「勝手にカードを既定にした」と受け取られる** | `setDefaultPaymentMethod` は T1000 の課金機構（customer default PM への off-session invoice 課金）の構造上の前提であり、**setup 経路と同一の副作用**。**v2 同意文言（契約のお支払いカードをオートリチャージにも使う）で開示済み**であり、開示の版管理が `consent_version` = aigenba の消費者保護契約そのもの。適格でない org には副作用が一切及ばない（テスト 52） |
| **`applyReusedPaymentMethod` の部分適用**（Stripe だけ変更済み） | 適格性判定・Stripe 更新・DB 確定を**同一 org lock**（`billing:auto-recharge:{org}`）内で直列化し、TX 内で適格性が失われていたら **`RuntimeException` で顕在化**（silent no-op にしない。Job retry で収束 / 継続不適格は `failed_jobs` で検知）。テスト 57 |
| **`consent_version` 改定で稼働中のオートリチャージが止まる** | **意図した fail-closed**（`reconsentRequiredFor` → `requiresReconsent=true` → `createAttemptLocked` が停止）。出口は P8a の `AutoRechargeCard` の再同意 1 クリック。**救済 backfill は書かない**（版の意味を無効化するため）。テスト 61 が停止と再同意導線の両方を固定 |
| **live 判定の単一出典化が P2 の `state()` を壊す** | 変更は「同じ `subDay()` 値を述語経由で呼ぶ」だけで**挙動不変**。P2 の `BillingAccessStateTest` / 分岐表 / migration / Factory を**無変更で green** に保つことを DoD にする。テスト 16 の arch test が閾値 literal の再発明を機械検出 |
| **日次 sweeper が P8a の `SetupPaymentMethod` 行を expire する** | aigenba verbatim（intent 無しフィルタ）。1 日以上前の pending は Stripe 側で既に expire 済みであり `Expired` 化は事実の追認。C-2 により**遅延成功は `Expired` からでも `Completed` へ受理される**ため決済を取りこぼさない。テスト 15 / 19 / 62 |
| **C-2 の遷移緩和が「未決済を成功に見せる」** | 遷移条件は `payment_status` の allowlist のみ。**null / 未知値は遷移しない**。`Completed` は終局のため巻き戻しも起きない。テスト 19 / 20。金銭の付与は `invoice.paid`（D7） |
| **P9 の writer が P8a の setup 行と混線する** | 冪等マシンのクエリは**常に `intent=subscription_start` スコープ**（`UNIQUE(organization_id, intent, attempt_token)` の `intent` 軸）。feedback / 着地 flash も `intent` 検証で fail-closed（テスト 11b / 26 / 59）。逆に **sweeper だけは intent 非スコープ**（verbatim）で setup 行も収束させる（テスト 15） |
| **同 token・別 plan の 422 が aigenba からの逸脱**（N-1） | 逸脱は N-1 の 1 点に限定し、根拠を Service の docblock に明記。**aigenba へ報告し、先方が replay 継続を選ぶなら verbatim へ戻す**（原則 5）。テスト 2 がこの分岐の唯一の契約 |
| **`idempotency_key` を attempt_token 由来に変えた差分**（N-3） | 当該列は aigenba でも T680 以降 dedup に使われていない遺物で**意味論の後退はない**（5 分バケット衝突による 500 の死角が消える）。seat 引数が無い以上 verbatim 式は移植不能。テスト 3 で「列値 == Stripe へ渡した key」を固定し差分を 1 箇所に閉じる |
| **`Organization` への CipherSweet 導入が既存の org 検索・Filament を壊す** | 暗号化するのは新規 additive 2 列のみ。`name` / `slug` は平文のまま。既存行は null のため `addOptionalTextField` で素通し = backfill 不要 |
| **`billing_contact_email` を Stripe へ同期することで PII が外部へ出る** | 現行 `syncStripeCustomerDetails()` が既に owner email を送っており送信先・内容は不変。**`billing_contact_name` は Stripe へ送らない**（aigenba IV-6 verbatim）。CipherSweet は保管時の保護であり境界は変わらない |
| **feedback バナーが「成功」を偽装する** | `session_id` は**自 org の DB 行と照合できたときのみ** feedback を出し、行の `status` を文言の唯一の根拠にする（`Pending` は「確認しています」）。任意 query（`?replayed` / `?retry`）は状態を主張しない中立文言のみ |
| **`Failed` 着地が無言**（aigenba verbatim の性質） | **既知の性質として意図的に継承する**（原則 5: 先回り修正しない = v1 で `PurchasePaymentFailed` を発明して parity を壊した失敗の再発防止）。出口は `Billing/Plans` からの新規 token 発行（1 クリック）で常に存在する（テスト 6 / 12 / 21）。**aigenba へ報告し、先方が文言を足したら取り込む** |
| **live pending dedup の `expireCheckoutSession` 失敗で checkout が詰む** | fail-closed は二重 live session を作らないための **aigenba verbatim の意図的挙動**。出口は (a) 同 token 再送 → 元の `checkout_url`、(b) **1 日経過で新 token で再開**（C-1 により sweeper を待たない）。テスト 8 / 12 / 21 |
