# 実装レビュー依頼: T021 新規登録時のチケット10枚付与

## アプリの使命 (North Star)

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置(SECI)。

本改善は、LP が約束する「新規登録で無償チケット 10 枚」を実際に付与し、課金前に AI 解析〜動画完成を試せる「まず触れる」導線を回復する(使命の入口を機能させる)。

## 禁止事項 (AGENTS.md 正本)

1. テストなしの実装完了報告(不変条件は Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI

### セキュリティ不変条件(関連)
- **課金の冪等性**: webhook は冪等マシン経由、チケットは reserve→commit/release の 2 フェーズ。付与は idempotency_key UNIQUE の冪等 insert。
- tenant キー不信 / cross-org 不可 / 権限判定は laratrust_team_id 明示 / PII は CipherSweet。

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

## あなたの役割

Laravel 12 + Svelte 5 + Inertia のコードレビュアーとして、以下の改善実装をレビューせよ。

### レビュー観点
1. **設計との一致性**: 詳細設計書の施策 1〜6 が正しく実装されているか
2. **正確性**: signup grant の冪等性(1 組織 1 回)が DB 制約 + アプリ層で原子的に保証されるか。登録 tx 内付与の副作用・ロールバック挙動。webhook 側の挙動変化(subscription id 非依存化)の妥当性
3. **PHPStan 適合性** (level 10): mixed の絞り込み、型明示
4. **DTO/JsonResource パターン**: `response()->json()` 直書きの有無
5. **テスト網羅性**: 各施策にテストがあるか。既存テストの更新が挙動変化を正しく反映しているか。テストデータは Factory 由来か
6. **セキュリティ**: 課金冪等性 §7 の遵守。招待経由での付与増幅防止(N人=N×10 を作らない)
7. **append-only 厳守**: migration が台帳行を触っていないか(index 追加のみ)

### 出力形式
- ファイルごとに判定
- 指摘は Critical / Warning / Suggestion に分類
- 全体判定を **APPROVED** または **CHANGES_REQUESTED** で明示

---

## 詳細設計書

（`devnotes/20260713-1637-registration-ticket-grant/detailed-design.md` を参照。要点のみ以下に再掲）

- 施策1: `ticket_ledger_entries` に部分 UNIQUE index (`ticket_ledger_entries_signup_grant_unique`, `organization_id WHERE idempotency_key LIKE 'signup_grant:%'`) を追加。作成前に非破壊の重複監査を行い、重複あれば `RuntimeException` で fail-closed。
- 施策2: `grantSignupGrant(Organization $organization)` へシグネチャ変更。冪等キーを `signup_grant:org:{orgId}` に内部生成。1 組織 1 回を idempotency_key + 部分 index で保証。source は Monthly のまま。
- 施策3: `CreateNewUser` の個人組織生成分岐で `grantSignupGrant($organization)` を登録 tx 内で呼ぶ。招待経由(join)では付与しない。try/catch で包まず fail-loud。
- 施策4: `StripeWebhookProcessor` の signup grant 呼び出しを新シグネチャへ。subscription id 依存を除去し `resolveInvoiceSubscriptionId()` を dead code として削除。付与は subscription id 非依存で常に走る(部分 index が二重付与を弾く)。
- 施策5: `Pricing.svelte` の文言「新規契約」→「新規登録」。
- 施策6: Feature(登録後残高10・招待非付与)/ Architecture(index 存在)/ 不変条件(異なるキーでも高々1行)/ 既存テスト更新(TicketGrant・WebhookIdempotency・InvoiceLinePricingShape・Pricing.test.ts)。

## テスト結果

- `composer test` (--parallel): 1564 tests, 1562 passed, 2 skipped, 0 failed
- `composer phpstan` (level 10): No errors
- `vendor/bin/pint --test`: passed
- `pnpm lint`: passed / `pnpm typecheck`: passed / `pnpm test`: 476 passed / `pnpm build`: passed

補足(既存テスト `InvoiceLinePricingShapeTest` の更新理由): subscription_create の invoice.paid は施策4で常に signup grant(+10)が走るようになったため、月次付与額のみを検証する意図に合わせ balance ではなく `monthly:%` エントリの delta/count を直接検証するよう変更した。

## 実装差分 (git diff)

```diff
diff --git a/app/Actions/Fortify/CreateNewUser.php b/app/Actions/Fortify/CreateNewUser.php
index f97b222..e330833 100644
--- a/app/Actions/Fortify/CreateNewUser.php
+++ b/app/Actions/Fortify/CreateNewUser.php
@@ -7,6 +7,7 @@
 use App\Models\User;
 use App\Rules\MatchesInvitationEmail;
 use App\Rules\UniqueEncryptedEmail;
+use App\Services\Billing\TicketLedgerService;
 use App\Services\Organization\OrganizationMembershipService;
 use App\Services\Organization\OrganizationProvisioningService;
 use Illuminate\Database\UniqueConstraintViolationException;
@@ -37,6 +38,7 @@ class CreateNewUser implements CreatesNewUsers
     public function __construct(
         private readonly OrganizationProvisioningService $provisioning,
         private readonly OrganizationMembershipService $membership,
+        private readonly TicketLedgerService $tickets,
     ) {}
 
     /**
@@ -94,7 +96,14 @@ public function create(array $input): User
                 if ($joined === null) {
                     // 個人用組織を同一 transaction 内で原子的に生成する
                     // (user だけ存在し組織なしの中間状態を作らない)
-                    $this->provisioning->provisionPersonalOrganization($user);
+                    $organization = $this->provisioning->provisionPersonalOrganization($user);
+
+                    // 初回 signup grant (無償 10 枚 / 30 日)。LP が約束する「新規登録で 10 枚」を実現する。
+                    // grantSignupGrant は純粋な ledger insert (通知・イベント・外部 I/O なし) のため登録 tx 内で完結し、
+                    // 冪等性は idempotency_key + 部分 UNIQUE index が DB レベルで保証する。
+                    // 招待経由 (join) は個人組織を作らず所属組織の残高を共有するため、ここでは付与しない
+                    // (招待 N 人 = N×10 の増幅を避ける)。
+                    $this->tickets->grantSignupGrant($organization);
                 }
 
                 return $user;
diff --git a/app/Services/Billing/StripeWebhookProcessor.php b/app/Services/Billing/StripeWebhookProcessor.php
index 87546cc..65e4e13 100644
--- a/app/Services/Billing/StripeWebhookProcessor.php
+++ b/app/Services/Billing/StripeWebhookProcessor.php
@@ -263,15 +263,11 @@ private function grantMonthlyTickets(array $payload): void
             return; // サブスク以外の請求 (one-time 等) では付与しない
         }
 
-        // 初回 signup grant (「まず触れる」導線)。subscription id が取れない場合は
-        // 安定した冪等キーを作れないため fail-closed で付与しない (report で可観測化)
+        // 初回 signup grant (「まず触れる」導線)。冪等キーは org スコープ (grantSignupGrant 内部で生成) のため
+        // subscription id は不要。1 組織 1 回の不変条件は idempotency_key + 部分 UNIQUE index が保証する。
+        // (通常は登録時に付与済のため、ここは非個人組織のサブスク等に対する no-op ないし 1 回付与の安全網)
         if ($billingReason === 'subscription_create') {
-            $subscriptionId = $this->resolveInvoiceSubscriptionId($payload);
-            if ($subscriptionId !== null) {
-                $this->tickets->grantSignupGrant($organization, "signup_grant:{$subscriptionId}");
-            } else {
-                report(new RuntimeException('invoice.paid subscription_create: subscription id 不明で signup grant skip'));
-            }
+            $this->tickets->grantSignupGrant($organization);
         }
 
         $plan = $this->resolveInvoicePlan($payload, $organization);
@@ -479,19 +475,6 @@ private function clawbackRefundedTickets(array $payload): void
         );
     }
 
-    /**
-     * invoice payload から紐づく subscription id を解決する (signup grant の安定冪等キー用)。
-     * 旧 Stripe API は top-level `subscription`、新 API は lines 配下に持つため両系を fallback で拾う。
-     *
-     * @param  array<mixed>  $payload
-     */
-    private function resolveInvoiceSubscriptionId(array $payload): ?string
-    {
-        return $this->stringAt($payload, 'data.object.subscription')
-            ?? $this->stringAt($payload, 'data.object.lines.data.0.subscription')
-            ?? $this->stringAt($payload, 'data.object.lines.data.0.parent.subscription_item_details.subscription');
-    }
-
     /**
      * invoice の対象プランを解決する。invoice 明細の price → plan_prices 逆引きを優先し、
      * 取れなければ organizations.plan_code に fallback (順序逆転への防御)。
diff --git a/app/Services/Billing/TicketLedgerService.php b/app/Services/Billing/TicketLedgerService.php
index 86b6b6d..96b9122 100644
--- a/app/Services/Billing/TicketLedgerService.php
+++ b/app/Services/Billing/TicketLedgerService.php
@@ -79,14 +79,17 @@ public function grantMonthly(
     /**
      * 初回 signup grant (「まず触れる」導線の無償チケット)。
      *
-     * サブスク作成の支払い確定時 (invoice.paid, billing_reason=subscription_create) に冪等付与する。
+     * 通常登録の完了時 (個人組織生成直後) と、Stripe サブスク作成の支払い確定時
+     * (invoice.paid, billing_reason=subscription_create) の双方から呼ばれる。
      * 枚数は config('billing.signup_grant_tickets')、期限は now + config('billing.signup_grant_expiry_days') 日。
-     * 冪等キーは呼び出し側が `signup_grant:{subscriptionId}` を渡す (月次付与と別名前空間で両建てを防ぐ)。
+     *
+     * **1 組織につき高々 1 回**の不変条件は、冪等キー `signup_grant:org:{orgId}` の UNIQUE と、
+     * ticket_ledger_entries の部分 UNIQUE index (organization_id WHERE idempotency_key LIKE 'signup_grant:%')
+     * が DB レベルで原子的に保証する。旧キー (signup_grant:{subId}) 行が既にある組織でも、部分 index が
+     * 同一述語でカバーするため insertOrIgnore が二重付与を弾く (アプリ層の存在チェックは不要)。
      */
-    public function grantSignupGrant(Organization $organization, string $idempotencyKey): void
+    public function grantSignupGrant(Organization $organization): void
     {
-        Assert::stringNotEmpty($idempotencyKey);
-
         $count = config('billing.signup_grant_tickets');
         Assert::integer($count, 'config billing.signup_grant_tickets は整数で設定してください');
         Assert::greaterThan($count, 0, 'signup_grant_tickets は 1 以上で設定してください');
@@ -95,11 +98,14 @@ public function grantSignupGrant(Organization $organization, string $idempotency
         Assert::integer($expiryDays, 'config billing.signup_grant_expiry_days は整数で設定してください');
         Assert::greaterThan($expiryDays, 0, 'signup_grant_expiry_days は 1 以上で設定してください');
 
+        $organizationId = $organization->getKey();
+        Assert::integer($organizationId, 'Organization の主キーは整数を想定しています');
+
         $this->grantMonthly(
             $organization,
             $count,
             CarbonImmutable::now()->addDays($expiryDays),
-            $idempotencyKey,
+            "signup_grant:org:{$organizationId}",
             '初回 signup grant',
         );
     }
diff --git a/database/migrations/2026_07_13_180622_add_signup_grant_unique_index_to_ticket_ledger_entries.php b/database/migrations/2026_07_13_180622_add_signup_grant_unique_index_to_ticket_ledger_entries.php
new file mode 100644
index 0000000..1b23e76
--- /dev/null
+++ b/database/migrations/2026_07_13_180622_add_signup_grant_unique_index_to_ticket_ledger_entries.php
@@ -0,0 +1,54 @@
+<?php
+
+declare(strict_types=1);
+
+use Illuminate\Database\Migrations\Migration;
+use Illuminate\Support\Facades\DB;
+use RuntimeException;
+
+/**
+ * ticket_ledger_entries に「1 組織 1 signup grant」を強制する部分 UNIQUE index を追加する。
+ *
+ * - 述語 `idempotency_key LIKE 'signup_grant:%'` は旧キー (signup_grant:{subId}) と
+ *   新 org スコープキー (signup_grant:org:{id}) の双方をカバーし、ローリングデプロイ中の
+ *   別キー同時 insert でも二重付与を DB レベルで原子的に防ぐ。
+ * - 作成前に既存重複を非破壊監査し、重複があれば fail-closed で停止する
+ *   (台帳は append-only。重複補正は別途承認された手順へ分離し、本 migration では触れない)。
+ */
+return new class extends Migration
+{
+    private const string INDEX_NAME = 'ticket_ledger_entries_signup_grant_unique';
+
+    public function up(): void
+    {
+        // 非破壊監査: 同一 organization_id に signup_grant:% 行が 2 件以上あると UNIQUE index は作れない
+        $duplicates = DB::table('ticket_ledger_entries')
+            ->where('idempotency_key', 'like', 'signup_grant:%')
+            ->groupBy('organization_id')
+            ->havingRaw('COUNT(*) > 1')
+            ->pluck('organization_id');
+
+        if ($duplicates->isNotEmpty()) {
+            throw new RuntimeException(
+                'signup_grant 重複あり (organization_id: '.$duplicates->implode(', ').
+                ')。台帳は append-only のため本 migration は補正しない。別途承認された補正手順で解消後に再実行すること。',
+            );
+        }
+
+        $driver = DB::connection()->getDriverName();
+        if (! in_array($driver, ['pgsql', 'sqlite'], true)) {
+            throw new RuntimeException("部分 UNIQUE index 未対応の driver: {$driver} (pgsql/sqlite のみ対応)");
+        }
+
+        // pgsql / sqlite はいずれも partial index (WHERE 述語) を支持する
+        DB::statement(
+            'CREATE UNIQUE INDEX '.self::INDEX_NAME.
+            " ON ticket_ledger_entries (organization_id) WHERE idempotency_key LIKE 'signup_grant:%'",
+        );
+    }
+
+    public function down(): void
+    {
+        DB::statement('DROP INDEX IF EXISTS '.self::INDEX_NAME);
+    }
+};
diff --git a/resources/js/pages/Pricing.svelte b/resources/js/pages/Pricing.svelte
index d8444bd..9bf2f0b 100644
--- a/resources/js/pages/Pricing.svelte
+++ b/resources/js/pages/Pricing.svelte
@@ -51,7 +51,7 @@
     const faqs = $derived([
         {
             q: "無料で試せますか？",
-            a: `はい。Free プランは基本料金なしでご利用いただけます。さらに新規契約でチケット ${page.signupGrantTickets} 枚 (${page.signupGrantExpiryDays} 日間有効) が無料でついてくるので、AI 解析から動画の完成までを実際にお試しいただけます。`,
+            a: `はい。Free プランは基本料金なしでご利用いただけます。さらに新規登録でチケット ${page.signupGrantTickets} 枚 (${page.signupGrantExpiryDays} 日間有効) が無料でついてくるので、AI 解析から動画の完成までを実際にお試しいただけます。`,
         },
         {
             q: "チケットは何に使いますか？",
@@ -165,7 +165,7 @@
                 class="mt-4 rounded-lg border border-primary/30 bg-primary-soft px-4 py-3 text-center text-body text-text"
                 data-testid="signup-grant-note"
             >
-                新規契約でチケット {page.signupGrantTickets} 枚が無料でついてきます (付与から {page.signupGrantExpiryDays}
+                新規登録でチケット {page.signupGrantTickets} 枚が無料でついてきます (付与から {page.signupGrantExpiryDays}
                 日間有効)
             </p>
             {#if tierRows.length > 0}
diff --git a/tests/Feature/Architecture/SignupGrantUniqueIndexInvariantTest.php b/tests/Feature/Architecture/SignupGrantUniqueIndexInvariantTest.php
new file mode 100644
index 0000000..222df56
--- /dev/null
+++ b/tests/Feature/Architecture/SignupGrantUniqueIndexInvariantTest.php
@@ -0,0 +1,35 @@
+<?php
+
+declare(strict_types=1);
+
+use Illuminate\Support\Facades\DB;
+use Webmozart\Assert\Assert;
+
+/*
+ * 「1 組織 1 signup grant」の DB 不変条件 (課金冪等性 §7 の一部)。
+ *
+ * ticket_ledger_entries の部分 UNIQUE index が、organization_id ごとに
+ * idempotency_key LIKE 'signup_grant:%' 行を高々 1 行に強制することを検証する。
+ * この index が旧キー (signup_grant:{subId}) と新 org スコープキー (signup_grant:org:{id}) の
+ * 双方をカバーし、ローリングデプロイ中の別キー同時 insert でも二重付与を DB レベルで防ぐ。
+ *
+ * テストは pgsql driver 前提 (テスト DB は pgsql)。pgsql は LIKE を ~~ 演算子・リテラルを
+ * 'signup_grant:%'::text として indexdef に描画するため、完全一致文字列ではなく
+ * UNIQUE / organization_id / signup_grant (部分文字列) の含有で検証する (Codex Round 4 caveat)。
+ */
+
+test('ticket_ledger_entries は 1 組織 1 signup grant を部分 UNIQUE index で強制する', function (): void {
+    $definition = DB::scalar(
+        "SELECT indexdef FROM pg_indexes
+         WHERE tablename = 'ticket_ledger_entries'
+           AND indexname = 'ticket_ledger_entries_signup_grant_unique'",
+    );
+    Assert::string($definition); // index 不在なら null → fail (存在保証も兼ねる)
+
+    expect($definition)
+        ->toContain('UNIQUE')                 // 一意制約であること
+        ->toContain('ticket_ledger_entries')  // 対象テーブル
+        ->toContain('organization_id')        // 対象列
+        ->toContain('WHERE')                  // 部分 index (述語) であること
+        ->toContain('signup_grant');          // 述語がキー prefix を参照 (LIKE は ~~ に正規化され得る)
+});
diff --git a/tests/Feature/Auth/RegistrationTest.php b/tests/Feature/Auth/RegistrationTest.php
index e836fbf..acea183 100644
--- a/tests/Feature/Auth/RegistrationTest.php
+++ b/tests/Feature/Auth/RegistrationTest.php
@@ -3,6 +3,7 @@
 declare(strict_types=1);
 
 use App\Models\User;
+use App\Services\Billing\TicketLedgerService;
 
 test('登録できる (同意の証跡が記録される)', function (): void {
     $response = $this->post('/register', [
@@ -19,6 +20,12 @@
     $user = User::whereBlind('email', 'email_index', 'taro@example.com')->firstOrFail();
     expect($user->terms_accepted_at)->not->toBeNull();
     expect($user->consent_version)->toBe(config()->string('legal.consent_version'));
+
+    // LP が約束する「新規登録で無償チケット」を個人組織へ付与する。
+    // 固定値ではなく config 由来値を期待に使う (設定変更後も意味が一貫する)。
+    $personalOrg = $user->organizations()->where('is_personal', true)->firstOrFail();
+    expect(app(TicketLedgerService::class)->balance($personalOrg))
+        ->toBe(config()->integer('billing.signup_grant_tickets'));
 });
 
 test('利用規約に同意しないと登録できない', function (): void {
diff --git a/tests/Feature/Billing/InvoiceLinePricingShapeTest.php b/tests/Feature/Billing/InvoiceLinePricingShapeTest.php
index 0c04d8a..befba65 100644
--- a/tests/Feature/Billing/InvoiceLinePricingShapeTest.php
+++ b/tests/Feature/Billing/InvoiceLinePricingShapeTest.php
@@ -5,7 +5,6 @@
 use App\Enums\Billing\PlanPriceKind;
 use App\Models\Billing\Plan;
 use App\Models\Organization;
-use App\Services\Billing\TicketLedgerService;
 use Laravel\Cashier\Events\WebhookReceived;
 use Webmozart\Assert\Assert;
 
@@ -77,8 +76,11 @@ function invoicePaidPayloadWithLine(string $eventId, string $customerId, array $
         ],
     ])));
 
-    // standard プランの monthly_ticket_grant (100) が付与される
-    expect(app(TicketLedgerService::class)->balance($organization))->toBe(100);
+    // standard プランの monthly_ticket_grant (100) が「月次付与」として計上される。
+    // subscription_create ではこれに加え signup grant (10) も走るため、balance ではなく
+    // 月次エントリ (monthly:%) を直接検証してこのテストの関心 (pricing 形状解決) に絞る。
+    expect($organization->ticketLedgerEntries()
+        ->where('idempotency_key', 'like', 'monthly:%')->sum('delta'))->toBe(100);
 });
 
 test('旧形状 (price.id) の invoice.paid でも月次付与される (後方互換)', function (): void {
@@ -88,10 +90,11 @@ function invoicePaidPayloadWithLine(string $eventId, string $customerId, array $
         'price' => ['id' => pricingShapeStandardBasePriceId()],
     ])));
 
-    expect(app(TicketLedgerService::class)->balance($organization))->toBe(100);
+    expect($organization->ticketLedgerEntries()
+        ->where('idempotency_key', 'like', 'monthly:%')->sum('delta'))->toBe(100);
 });
 
-test('新形状の price が plan_prices に無ければ付与しない', function (): void {
+test('新形状の price が plan_prices に無ければ月次付与しない', function (): void {
     $organization = pricingShapeStripeCustomer('cus_clover_unknown');
 
     event(new WebhookReceived(invoicePaidPayloadWithLine('evt_clover_unknown', 'cus_clover_unknown', [
@@ -102,6 +105,8 @@ function invoicePaidPayloadWithLine(string $eventId, string $customerId, array $
         ],
     ])));
 
-    // plan_code fallback も無い (未契約) ため付与されない
-    expect(app(TicketLedgerService::class)->balance($organization))->toBe(0);
+    // plan_code fallback も無い (未契約) ため「月次付与」は走らない
+    // (subscription_create なので signup grant は別途走るが、本テストの関心外)。
+    expect($organization->ticketLedgerEntries()
+        ->where('idempotency_key', 'like', 'monthly:%')->count())->toBe(0);
 });
diff --git a/tests/Feature/Billing/TicketGrantTest.php b/tests/Feature/Billing/TicketGrantTest.php
index e88e433..146a5b5 100644
--- a/tests/Feature/Billing/TicketGrantTest.php
+++ b/tests/Feature/Billing/TicketGrantTest.php
@@ -6,6 +6,7 @@
 use App\Enums\Billing\TicketSource;
 use App\Services\Billing\TicketLedgerService;
 use Carbon\CarbonImmutable;
+use Illuminate\Support\Facades\DB;
 
 /*
  * 冪等付与 (grantMonthly / grantSignupGrant / grantPurchased) と期限付き残高。
@@ -78,17 +79,20 @@ function grantService(): TicketLedgerService
     expect(grantService()->balance($organization))->toBe(7);
 });
 
-test('grantSignupGrant は config の枚数・期限で冪等付与する', function (): void {
+test('grantSignupGrant は config の枚数・期限で org スコープキーで冪等付与する', function (): void {
     [$organization] = createOrganizationWithOwner();
     config()->set('billing.signup_grant_tickets', 10);
     config()->set('billing.signup_grant_expiry_days', 30);
 
-    grantService()->grantSignupGrant($organization, 'signup_grant:sub_1');
-    grantService()->grantSignupGrant($organization, 'signup_grant:sub_1');
+    // 冪等キーは org スコープ (signup_grant:org:{id}) を内部生成する。二重呼び出しでも 1 行のみ。
+    grantService()->grantSignupGrant($organization);
+    grantService()->grantSignupGrant($organization);
 
     expect(grantService()->balance($organization))->toBe(10);
+    expect($organization->ticketLedgerEntries()->count())->toBe(1);
     $entry = $organization->ticketLedgerEntries()->firstOrFail();
     expect($entry->source)->toBe(TicketSource::Monthly);
+    expect($entry->idempotency_key)->toBe("signup_grant:org:{$organization->id}");
     expect($entry->expires_at?->toDateString())
         ->toBe(CarbonImmutable::now()->addDays(30)->toDateString());
 
@@ -101,10 +105,37 @@ function grantService(): TicketLedgerService
     [$organization] = createOrganizationWithOwner();
     config()->set('billing.signup_grant_tickets', 0);
 
-    expect(fn () => grantService()->grantSignupGrant($organization, 'signup_grant:sub_bad'))
+    expect(fn () => grantService()->grantSignupGrant($organization))
         ->toThrow(InvalidArgumentException::class);
 });
 
+test('1 組織に signup_grant は異なるキーでも高々 1 回しか計上されない (部分 UNIQUE index)', function (): void {
+    [$organization] = createOrganizationWithOwner();
+    $svc = grantService();
+
+    // 1 回目: 公開ユースケース経由 (org スコープキー signup_grant:org:{id})
+    $svc->grantSignupGrant($organization);
+
+    // 2 回目: 旧キー形式を直接投入 → 部分 UNIQUE index (organization_id WHERE idempotency_key
+    // LIKE 'signup_grant:%') が別キーでも弾く (ON CONFLICT DO NOTHING)。
+    // delta / 期限は config 由来にして設定変更後も意味が一貫するようにする。
+    DB::table('ticket_ledger_entries')->insertOrIgnore([
+        'organization_id' => $organization->getKey(),
+        'delta' => config('billing.signup_grant_tickets'),
+        'kind' => TicketLedgerKind::Grant->value,
+        'source' => TicketSource::Monthly->value,
+        'description' => '初回 signup grant (legacy)',
+        'granted_at' => now(),
+        'expires_at' => now()->addDays(config()->integer('billing.signup_grant_expiry_days')),
+        'idempotency_key' => 'signup_grant:sub_legacy',
+        'created_at' => now(),
+    ]);
+
+    expect($organization->ticketLedgerEntries()
+        ->where('idempotency_key', 'like', 'signup_grant:%')->count())->toBe(1);
+    expect($svc->balance($organization))->toBe(config('billing.signup_grant_tickets'));
+});
+
 test('grantPurchased は checkout session id で冪等付与し、返金正本キーを記録する', function (): void {
     [$organization] = createOrganizationWithOwner();
 
diff --git a/tests/Feature/Billing/WebhookIdempotencyTest.php b/tests/Feature/Billing/WebhookIdempotencyTest.php
index 43a2749..1ad5015 100644
--- a/tests/Feature/Billing/WebhookIdempotencyTest.php
+++ b/tests/Feature/Billing/WebhookIdempotencyTest.php
@@ -130,14 +130,14 @@ function subscriptionPayload(string $type, string $status, string $eventId): arr
 
     $payload = invoicePaidPayload('evt_signup_1');
     $payload['data']['object']['billing_reason'] = 'subscription_create';
-    $payload['data']['object']['subscription'] = 'sub_signup_1';
 
     event(new WebhookReceived($payload));
 
     // 月次 100 + signup grant (config billing.signup_grant_tickets = 10)
     expect(app(TicketLedgerService::class)->balance($organization))->toBe(110);
+    // 冪等キーは org スコープ (grantSignupGrant 内部生成)。subscription id には依存しない。
     $signup = $organization->ticketLedgerEntries()
-        ->where('idempotency_key', 'signup_grant:sub_signup_1')
+        ->where('idempotency_key', "signup_grant:org:{$organization->id}")
         ->firstOrFail();
     expect($signup->delta)->toBe(config('billing.signup_grant_tickets'));
     expect($signup->expires_at)->not->toBeNull();
@@ -150,21 +150,23 @@ function subscriptionPayload(string $type, string $status, string $eventId): arr
     expect(app(TicketLedgerService::class)->balance($organization))->toBe(110);
 });
 
-test('subscription id が取れない subscription_create では signup grant を付与しない (fail-closed)', function (): void {
+test('subscription id が無くても org スコープキーで signup grant を付与する', function (): void {
     $organization = billingStripeCustomer();
 
+    // subscription id を含まない subscription_create の invoice.paid。
+    // org スコープキー (signup_grant:org:{id}) は subscription id に依存しないため付与される。
     $payload = invoicePaidPayload('evt_signup_nosub');
     $payload['data']['object']['billing_reason'] = 'subscription_create';
 
     event(new WebhookReceived($payload));
 
-    // 月次付与のみ (signup grant は安定冪等キーを作れないため skip)
-    expect(app(TicketLedgerService::class)->balance($organization))->toBe(100);
+    // 月次 100 + signup grant 10
+    expect(app(TicketLedgerService::class)->balance($organization))->toBe(110);
     expect(
         $organization->ticketLedgerEntries()
             ->where('idempotency_key', 'like', 'signup_grant:%')
             ->count(),
-    )->toBe(0);
+    )->toBe(1);
 });
 
 test('customer.subscription.updated で organizations.plan_code が同期される', function (): void {
diff --git a/tests/Feature/Organization/InvitationTest.php b/tests/Feature/Organization/InvitationTest.php
index 300b695..0fae8b7 100644
--- a/tests/Feature/Organization/InvitationTest.php
+++ b/tests/Feature/Organization/InvitationTest.php
@@ -10,6 +10,7 @@
 use App\Models\Project;
 use App\Models\User;
 use App\Notifications\OrganizationInvitationNotification;
+use App\Services\Billing\TicketLedgerService;
 use App\Services\Organization\OrganizationMembershipService;
 use Illuminate\Notifications\AnonymousNotifiable;
 use Illuminate\Support\Facades\Notification;
@@ -330,6 +331,31 @@ function (OrganizationInvitationNotification $notification) use (&$plainToken):
     $response->assertSessionMissing('invitation_token');
 });
 
+test('招待経由登録では個人組織を作らず signup grant を付与しない (増幅防止)', function (): void {
+    // 招待経由は個人組織を作らず所属組織の残高を共有する。ここで付与すると招待 N 人 = N×10 の
+    // 増幅になるため、signup grant は「個人組織を作る新規登録」時のみに限定する (LP CTA も同じ意図)。
+    [$organization, $owner] = createOrganizationWithOwner('招待組織');
+    $token = inviteAndCaptureToken($organization, $owner, 'nofree@example.com', AdminConsoleRole::Admin);
+
+    $this->withSession(['invitation_token' => $token])->post('/register', [
+        'name' => '無償なし 花子',
+        'email' => 'nofree@example.com',
+        'password' => 'SecurePass1234',
+        'terms_accepted' => '1',
+    ])->assertRedirect(route('verification.notice'));
+
+    $user = User::whereBlind('email', 'email_index', 'nofree@example.com')->firstOrFail();
+    // 個人組織は生成されない
+    expect($user->organizations()->where('is_personal', true)->exists())->toBeFalse();
+    // 招待組織の残高に signup grant は乗らない (owner の付与ぶんも招待組織には走っていない)
+    expect(app(TicketLedgerService::class)->balance($organization))->toBe(0);
+    expect(
+        $organization->ticketLedgerEntries()
+            ->where('idempotency_key', 'like', 'signup_grant:%')
+            ->count(),
+    )->toBe(0);
+});
+
 test('招待 email と異なる email で register すると email エラーになる', function (): void {
     [$organization, $owner] = createOrganizationWithOwner();
     $token = inviteAndCaptureToken($organization, $owner, 'invited@example.com', AdminConsoleRole::Admin);
diff --git a/tests/js/pages/Pricing.test.ts b/tests/js/pages/Pricing.test.ts
index f2eb38a..7d24cb6 100644
--- a/tests/js/pages/Pricing.test.ts
+++ b/tests/js/pages/Pricing.test.ts
@@ -73,8 +73,10 @@ describe("Pricing", () => {
         expect(table).toHaveTextContent("500 枚以上");
         expect(table).toHaveTextContent("¥50 ／ 枚");
 
+        // 招待経由 (所属組織の残高を共有) は LP CTA の対象外。付与は個人組織を作る
+        // 「新規登録」時に走るため、文言も「新規登録で」で挙動と整合させる。
         expect(screen.getByTestId("signup-grant-note")).toHaveTextContent(
-            "新規契約でチケット 10 枚が無料でついてきます (付与から 30 日間有効)",
+            "新規登録でチケット 10 枚が無料でついてきます (付与から 30 日間有効)",
         );
     });
 

```
