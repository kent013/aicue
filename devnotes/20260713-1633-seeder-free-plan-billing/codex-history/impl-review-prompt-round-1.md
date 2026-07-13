# アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。
- v1 スコープ: 字幕のみ / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

# 禁止事項（自分・実装双方に適用）

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI
- ドメイン規約: コードにプラン名 (code) で分岐を書かない。能力は「値」で表現する。`organizations.plan_code` は Stripe Price を持つ有償プランの契約時のみ set される (null = 未契約 = free tier)。

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

# system: コードレビュアー役割

あなたは Laravel 12 + Svelte 5 + Inertia + Cashier アプリの改善実装をレビューするシニアレビュアーである。以下の観点でレビューせよ:

- **設計との一致性**: 下記の詳細設計書どおりに実装されているか
- **正確性**: バグ・エッジケース・冪等性・回帰再現性
- **PHPStan level 10 適合性**: 型安全、null 安全 (`firstOrFail` narrow 等)
- **DTO/JsonResource パターン**: 該当あれば (本 diff は seeder + テストのみ)
- **テスト網羅性**: バグ再現 → 修正の TDD、Free/有償の両側不変条件
- **セキュリティ**: 認可・IDOR (本変更は billing gate の entitlement)
- **ドメイン規約**: プラン名 (code) 文字列での本番能力分岐をしていないか (fixture 検証は例外として容認可)

出力形式: ファイルごとに判定し、指摘を Critical / Warning / Suggestion に分類。最後に全体判定を **APPROVED** または **CHANGES_REQUESTED** で明示すること。

なお本変更は `resources/js` / `resources/css` を含まない (seeder + PHP テストのみ) ため、DESIGN.md / Atomic Design 観点は対象外。

---

# user

## 背景（TODO T020 / F-C3）

ManualTestSeeder が Free プラン (Stripe Price を持たない未契約既定) 組織にも `plan_code='free'` を載せていたため、BillingAccess が active subscription を要求して Free 組織の全ロールを課金ゲートで締め出していた (bug-hunt F-C3)。BillingAccess / Middleware は現行仕様が正しく、根本原因は seeder の plan_code 不変条件違反。よって seeder を是正する。

## 詳細設計書（要点）

- 施策1: `ManualTestSeeder::createOrganization` を、`$plan->currentPrice(PlanPriceKind::Base) !== null` (= current base Price を持つ有償プラン) のときのみ plan_code を forceFill し、`attachFakeActiveSubscription` で active な Cashier subscription 行を投入するよう変更。Free (Price 無し) は plan_code=null のまま。判定はプラン名比較でなく Plan の「値」から導出。`attachFakeActiveSubscription` は既存 default subscription があれば早期 return する冪等メソッド。
- 施策2: `ManualTestSeederTest` の総当たりループを、有償なら plan_code=code + active subscription、Free なら plan_code=null + subscription 無し、両 tier とも `BillingAccess::hasActiveAccess` が true、と是正。`first()` を `firstOrFail()` に変更し null narrow。
- 施策3 (新規): `SeededFreePlanBillingAccessTest` — seed 済み Free 組織の owner/admin/member が `/projects` に到達 (assertOk + Inertia component 'Projects/Index')。修正前は 302→billing で fail する回帰テスト。有償側も plan_code + active subscription を確認。
- 施策4 (新規): `PlanSeederPriceInvariantTest` — 「standard は current base Price を持つ」「free は Price を持たない」を判定式に依存しない独立テストで固定 (施策2 の silently pass ドリフトを塞ぐ)。プラン名直接参照は fixture 仕様検証で容認。

## 実装差分（git diff HEAD）

```diff
diff --git a/database/seeders/ManualTestSeeder.php b/database/seeders/ManualTestSeeder.php
index 73166a2..2a0a49d 100644
--- a/database/seeders/ManualTestSeeder.php
+++ b/database/seeders/ManualTestSeeder.php
@@ -4,6 +4,7 @@
 
 namespace Database\Seeders;
 
+use App\Enums\Billing\PlanPriceKind;
 use App\Enums\OrganizationRole;
 use App\Models\Billing\Plan;
 use App\Models\Organization;
@@ -119,17 +120,53 @@ private function createUser(string $name, string $email, bool $verified = true):
 
     /**
      * 組織生成は provisioning 経由 (Default Team パターンの不変条件を担保する唯一の窓口)。
-     * plan_code は状態キー ($fillable 外) のため forceFill で明示代入する。
+     *
+     * plan_code の不変条件を尊重する: 「plan_code は Stripe Price を持つ有償プランの契約状態でのみ
+     * set される」(Model/StripeWebhookProcessor/BillingAccess の docblock が定める)。
+     * よって有償プラン (current base Price あり) のときのみ plan_code を forceFill し、あわせて
+     * active な Cashier subscription 行を投入する (plan_code 非 null ⇔ 契約行あり を seed でも満たす)。
+     * Free (Price 無し) は plan_code を null のまま = 未契約 = 支払い不要 tier として BillingAccess が許可する。
+     *
+     * 有償/Free の判定は Plan の「値」(current base Price の有無) からのみ導出し、プラン名 (code) の
+     * 文字列比較はしない (AGENTS.md ドメイン規約)。
      */
     private function createOrganization(User $owner, Plan $plan): Organization
     {
         $organization = app(OrganizationProvisioningService::class)
             ->provision($owner, "{$plan->name}プラン組織");
-        $organization->forceFill(['plan_code' => $plan->code])->save();
+
+        // current な base Price を持つ = Checkout 可能な有償プラン。plan_code は状態キー ($fillable 外)
+        if ($plan->currentPrice(PlanPriceKind::Base) !== null) {
+            $organization->forceFill(['plan_code' => $plan->code])->save();
+            $this->attachFakeActiveSubscription($organization);
+        }
 
         return $organization;
     }
 
+    /**
+     * 手動テスト用に active な Cashier subscription 行を直接投入する (Stripe API 非到達)。
+     * BillingAccess は plan_code 非 null の組織に active/trialing subscription を要求するため、
+     * plan_code を載せた有償組織は本行が無いと課金ゲートで締め出される。
+     * subscription('default') が active を返すための最小カラムのみを設定する。
+     *
+     * メソッド単体で冪等: 既に default subscription があれば作らない (run() の冪等 guard に依存せず、
+     * 部分実行・手動呼び出し・将来の guard 変更でも重複行を生まない)。
+     */
+    private function attachFakeActiveSubscription(Organization $organization): void
+    {
+        if ($organization->subscription('default') !== null) {
+            return; // 冪等: 既存の default subscription を尊重する
+        }
+
+        $organization->subscriptions()->create([
+            'type' => 'default',
+            'stripe_id' => 'sub_seed_'.Str::random(24),
+            'stripe_status' => 'active',
+            'quantity' => 1,
+        ]);
+    }
+
     private function addToOrganization(
         User $user,
         Organization $organization,
diff --git a/tests/Feature/Billing/PlanSeederPriceInvariantTest.php b/tests/Feature/Billing/PlanSeederPriceInvariantTest.php
new file mode 100644
index 0000000..2bd888e
--- /dev/null
+++ b/tests/Feature/Billing/PlanSeederPriceInvariantTest.php
@@ -0,0 +1,27 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\Billing\PlanPriceKind;
+use App\Models\Billing\Plan;
+
+/*
+ * seed fixture 不変条件: 有償プラン (Checkout 対象) は current な base Price を必ず持つ。
+ * ManualTestSeeder / BillingAccess の「plan_code 非 null ⇔ 有償契約」判定は「有償プランは
+ * currentPrice(Base) を持つ」という前提に立つ。この前提が崩れると seeded 有償組織が free 扱いに
+ * silently 退行するため、判定式 (currentPrice) に依存しない独立検証でここを固定する。
+ * (本番コードのプラン名分岐ではなく fixture 仕様の検証。docs 07 §4 の規約には抵触しない)
+ */
+
+test('有償プラン standard は current base Price を持つ (seed 不変条件)', function (): void {
+    $standard = Plan::query()->where('code', 'standard')->firstOrFail();
+
+    expect($standard->currentPrice(PlanPriceKind::Base))->not->toBeNull();
+});
+
+test('free プランは Stripe Price を持たない (Checkout 対象外の未契約既定)', function (): void {
+    $free = Plan::query()->where('code', 'free')->firstOrFail();
+
+    expect($free->currentPrice(PlanPriceKind::Base))->toBeNull();
+    expect($free->prices()->count())->toBe(0);
+});
diff --git a/tests/Feature/Billing/SeededFreePlanBillingAccessTest.php b/tests/Feature/Billing/SeededFreePlanBillingAccessTest.php
new file mode 100644
index 0000000..68cff4d
--- /dev/null
+++ b/tests/Feature/Billing/SeededFreePlanBillingAccessTest.php
@@ -0,0 +1,62 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\Billing\PlanPriceKind;
+use App\Enums\OrganizationRole;
+use App\Models\Billing\Plan;
+use App\Models\Organization;
+use App\Models\User;
+use Database\Seeders\ManualTestSeeder;
+use Illuminate\Support\Str;
+
+/*
+ * F-C3 回帰: ManualTestSeeder が生成する Free (Stripe Price 無し) プラン組織の全ロールが、
+ * 課金ゲート (require-active-subscription) を素通りして中核業務 route に到達できることを固定する。
+ * 根本原因は seeder が Free にも plan_code='free' を載せ、BillingAccess が active subscription を
+ * 要求して締め出していたこと (devnotes/20260713-1633-seeder-free-plan-billing)。
+ */
+
+/** current base Price を持たない Free プランを 1 つ取得する */
+function seededFreePlan(): Plan
+{
+    return Plan::query()->get()
+        ->first(fn (Plan $p): bool => $p->currentPrice(PlanPriceKind::Base) === null)
+        ?? throw new RuntimeException('Free プラン (Price 無し) が seed されていない');
+}
+
+test('seeded Free 組織の全ロールが /projects に到達できる (F-C3 回帰)', function (OrganizationRole $role): void {
+    $this->seed(ManualTestSeeder::class);
+
+    $plan = seededFreePlan();
+    $organization = Organization::query()->where('name', "{$plan->name}プラン組織")->firstOrFail();
+    expect($organization->plan_code)->toBeNull(); // seeder が不変条件を守っている
+
+    $email = Str::afterLast($role->value, '_')."-{$plan->code}@example.com";
+    $user = User::whereBlind('email', 'email_index', $email)->firstOrFail();
+
+    // assertOk() で 302→billing の redirect を検出。加えて Inertia コンポーネント名で
+    // 「200 だが別画面」ケースも塞ぐ (ProjectController@index の Inertia::render 先)。
+    $this->actingAs($user)->get('/projects')
+        ->assertOk()
+        ->assertInertia(fn ($page) => $page->component('Projects/Index'));
+})->with([
+    'owner' => OrganizationRole::Owner,
+    'admin' => OrganizationRole::Admin,
+    'member' => OrganizationRole::Member,
+]);
+
+test('seeded 有償組織は plan_code と active subscription を持ち課金ゲートを通過する', function (): void {
+    $this->seed(ManualTestSeeder::class);
+
+    $paid = Plan::query()->get()
+        ->first(fn (Plan $p): bool => $p->currentPrice(PlanPriceKind::Base) !== null);
+    expect($paid)->not->toBeNull();
+
+    $organization = Organization::query()->where('name', "{$paid?->name}プラン組織")->firstOrFail();
+    expect($organization->plan_code)->toBe($paid?->code);
+    expect($organization->subscription('default')?->stripe_status)->toBe('active');
+
+    $owner = User::whereBlind('email', 'email_index', "owner-{$paid?->code}@example.com")->firstOrFail();
+    $this->actingAs($owner)->get('/projects')->assertOk();
+});
diff --git a/tests/Feature/Database/ManualTestSeederTest.php b/tests/Feature/Database/ManualTestSeederTest.php
index 176d608..8531815 100644
--- a/tests/Feature/Database/ManualTestSeederTest.php
+++ b/tests/Feature/Database/ManualTestSeederTest.php
@@ -2,10 +2,12 @@
 
 declare(strict_types=1);
 
+use App\Enums\Billing\PlanPriceKind;
 use App\Enums\OrganizationRole;
 use App\Models\Billing\Plan;
 use App\Models\Organization;
 use App\Models\User;
+use App\Services\Billing\BillingAccess;
 use Database\Seeders\ManualTestSeeder;
 use Illuminate\Support\Facades\Hash;
 use Illuminate\Support\Str;
@@ -19,10 +21,22 @@
     $this->seed(ManualTestSeeder::class);
 
     foreach (Plan::query()->orderBy('sort_order')->get() as $plan) {
-        $organization = Organization::query()->where('name', "{$plan->name}プラン組織")->first();
+        $organization = Organization::query()->where('name', "{$plan->name}プラン組織")->firstOrFail();
 
-        expect($organization)->not->toBeNull();
-        expect($organization?->plan_code)->toBe($plan->code);
+        // 有償判定はループ先頭で 1 回だけ算出 (currentPrice の多重クエリを避け分岐を一元化)
+        $isPaid = $plan->currentPrice(PlanPriceKind::Base) !== null;
+
+        // plan_code の不変条件: Stripe Price を持つ有償プランのみ plan_code + active subscription を持つ。
+        // Free (Price 無し) は未契約 = plan_code null (BillingAccess の free-tier 許可の前提)。
+        if ($isPaid) {
+            expect($organization->plan_code)->toBe($plan->code);
+            expect($organization->subscription('default')?->stripe_status)->toBe('active');
+        } else {
+            expect($organization->plan_code)->toBeNull();
+            expect($organization->subscription('default'))->toBeNull(); // free には契約行が無い (根本原因への回帰耐性)
+        }
+        // どちらの tier も業務 route を利用してよい (free tier / 有償 active はともに許可)
+        expect(app(BillingAccess::class)->hasActiveAccess($organization))->toBeTrue();
 
         foreach (OrganizationRole::cases() as $role) {
             $email = Str::afterLast($role->value, '_')."-{$plan->code}@example.com";

```

## テスト結果

- 対象テスト (ManualTestSeederTest + SeededFreePlanBillingAccessTest + PlanSeederPriceInvariantTest): 9 passed, 70 assertions
- 全 composer test (--parallel): 1565 passed, 2 skipped, 0 failed
- composer phpstan: No errors (level 10, 631 files)
- vendor/bin/pint --test: passed
- pnpm lint / typecheck / test (476 passed) / build: all green

上記を踏まえ、設計一致性・正確性・PHPStan 適合・テスト網羅性・ドメイン規約遵守の観点でレビューし、全体判定を示すこと。
