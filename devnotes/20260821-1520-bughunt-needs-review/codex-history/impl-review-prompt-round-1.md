# アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

v1 スコープ: 字幕のみ / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

# 禁止事項（AGENTS.md）

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(窓口 PromptDefense 経由の 1 本道のみ)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。設計そのものを見直せ。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

# system: コードレビュアーとしての役割

あなたは Laravel 12 + Svelte 5 + Inertia.js の改善実装をレビューするシニアレビュアーである。以下の観点で TODO T240 の実装差分をレビューせよ:

- **設計との一致性**: 詳細設計書のテスト計画・変更後コードと差分が一致しているか。
- **正確性**: redirect 分岐のロジックが正しいか。判定順序 (hasActiveAccess → manageBilling) が保たれているか。既存の未契約・支払い未解決経路を壊していないか。
- **PHPStan level 10 適合性**: 型の緩め・null 安全性の欠落がないか。
- **DTO/JsonResource パターン**: `response()->json()` 直書きがないか (redirect 分岐なので非該当のはずだが確認)。
- **テスト網羅性**: 着地先境界 (Subscribed/ActiveFreePlan/未契約/支払い未解決 × manageBilling 有無) が網羅されているか。テストファースト (赤→緑) の妥当性。characterization test (#6) の位置づけが正しいか。continuation の 2 段確認が中間ホップを保証しているか。
- **セキュリティ**: `Gate::allows('manageBilling', $organization)` が organization-scoped で正しく呼ばれ、権限境界が変わっていないか。IDOR 面。
- **DESIGN.md / Atomic Design 準拠**: 本差分は resources/js・resources/css を含まないため該当なし。

出力形式:
- ファイルごとに判定を述べ、指摘は Critical / Warning / Suggestion に分類する。
- 最後に全体判定を **APPROVED** または **CHANGES_REQUESTED** で明示する。

---

# user

## 詳細設計書（T240: bughunt-needs-review 施策 D — Q-2-01）

施策 D の要点:
- `OnboardingController::show()` の「契約済み (hasActiveAccess=true) → billing.index」分岐を、manageBilling 能力で分岐させる。保持者は billing.index (現状維持)、非保持メンバーは dashboard へ寄せる (現場作業者を操作できない請求画面ではなく業務入口へ着地させる。soft dead-end にしない)。
- 判定順序 (hasActiveAccess → manageBilling) は不変。未契約経路 (billing-required / checkout render / 支払い未解決 → billing.index) は変えない。
- `dashboard` は `RequireActiveSubscription` を通らない課金ゲート外 route。非管理メンバーでも 200 で描画される。
- 施策 A/B/C はコード変更なし (既存カバレッジ確認のみ)。
- `.claude/skills/app-bug-hunt/screens.md` の課金ゲート着地節を同一 PR で更新する。

テスト計画 (着地先境界の完全表):
| # | 状態 | manageBilling | 期待 | 種別 |
|---|---|:--:|---|---|
| 1 | Subscribed(paid) | あり | billing.index | 維持 |
| 2 | Subscribed(paid) | なし | dashboard | 期待更新 |
| 3 | ActiveFreePlan | あり | billing.index | 維持 |
| 4 | ActiveFreePlan | なし | dashboard | 新規 |
| 5 | 未契約 | あり | checkout 200 | 維持 |
| 6 | 未契約 | なし | billing-required | 新規 characterization (変更前から緑) |
| 7 | 支払い未解決 | あり | billing.index | 維持 |
| 8 | 支払い未解決 | なし | billing-required | 維持 |
- continuation (RegisterVerifyFlowTest): ActiveFreePlan 非管理メンバーが verify 完了後 onboarding.checkout 経由で dashboard に着地する。第一段 (verify→onboarding.checkout) と第二段 (onboarding.checkout→dashboard) を分けて中間ホップを保証する。

## 実装差分（git diff）

```diff
diff --git a/.claude/skills/app-bug-hunt/screens.md b/.claude/skills/app-bug-hunt/screens.md
index acbf42aa..63609c1b 100644
--- a/.claude/skills/app-bug-hunt/screens.md
+++ b/.claude/skills/app-bug-hunt/screens.md
@@ -141,7 +141,9 @@ ## 課金ゲート着地 (P4 ゲート反転) の画面遷移
 > §サブスク契約 Checkout とオンボーディング着地)。
 
 - `onboarding.checkout` は**離脱ガード付き**: 契約済み (有効 sub / free personal) は
-  `billing.index` へ、`manageBilling` 非保持者は `onboarding.billing-required` へ逃がす。
+  `manageBilling` 保持者 → `billing.index` / 非保持メンバー → `dashboard` へ寄せる
+  (非保持メンバーに操作できない請求画面を見せず業務入口へ着地させる。Q-2-01)。
+  未契約で `manageBilling` 非保持者は `onboarding.billing-required` へ逃がす。
 - `onboarding.billing-required` も同様に、利用可なら `dashboard`、`manageBilling` 保持者なら
   `onboarding.checkout` へ逃がす。**どちらの画面も「行き先のない詰み」を作らないこと**が契約で、
   ここでループ・403・空画面が出たら finding (H4/H10)。
diff --git a/app/Http/Controllers/Onboarding/OnboardingController.php b/app/Http/Controllers/Onboarding/OnboardingController.php
index cc136152..a45316bd 100644
--- a/app/Http/Controllers/Onboarding/OnboardingController.php
+++ b/app/Http/Controllers/Onboarding/OnboardingController.php
@@ -60,8 +60,16 @@ public function show(Request $request): Response|RedirectResponse
 
         // 判定順序は hasActiveAccess → manageBilling。契約済み non-manager が誤って
         // billing-required に飛ばないよう、先に契約状態を判定する。
+        // 契約済み (Subscribed / ActiveFreePlan) の入口着地は manageBilling 能力で分岐する:
+        // - 保持者: 請求管理が正当な着地なので billing.index (現状維持)。
+        // - 非保持メンバー (現場作業者): 自分で操作できない請求画面ではなく業務入口 dashboard へ
+        //   (Q-2-01。North Star: 現場作業者を最小摩擦で仕事へ着地させる。soft dead-end にしない)。
         if ($this->access->hasActiveAccess($organization)) {
-            return new RedirectResponse(route('billing.index'));
+            return new RedirectResponse(
+                Gate::allows('manageBilling', $organization)
+                    ? route('billing.index')
+                    : route('dashboard'),
+            );
         }
 
         // 未契約 + manageBilling 権限なし → billing-required へ
diff --git a/tests/Feature/Auth/RegisterVerifyFlowTest.php b/tests/Feature/Auth/RegisterVerifyFlowTest.php
index 5773c27a..8b85bc2c 100644
--- a/tests/Feature/Auth/RegisterVerifyFlowTest.php
+++ b/tests/Feature/Auth/RegisterVerifyFlowTest.php
@@ -2,6 +2,7 @@
 
 declare(strict_types=1);
 
+use App\Enums\OrganizationRole;
 use App\Models\Organization;
 use App\Models\User;
 use Illuminate\Support\Facades\Http;
@@ -115,6 +116,39 @@
     expect($user->fresh()?->hasVerifiedEmail())->toBeTrue();
 });
 
+test('continuation: ActiveFreePlan の非管理メンバーは verify 完了後 onboarding.checkout 経由で dashboard に着地する (Q-2-01)', function (): void {
+    // ActiveFreePlan (free_plan_code=personal) の既契約 org を用意する
+    // (hasActiveAccess=true かつ課金は非管理メンバーの管掌外)。
+    [$organization, $owner] = createOrganizationWithOwner(grandfatherFreePlan: false);
+    $organization->forceFill(['plan_code' => 'standard'])->save();
+    contractPaidPlan($organization, status: 'canceled');
+    $organization->forceFill([
+        'free_plan_code' => 'personal',
+        'free_plan_activated_at' => now(),
+        'personal_declared_at' => now(),
+        'personal_declared_by_user_id' => $owner->getKey(),
+    ])->save();
+
+    // 非管理メンバー (unverified) を当該 org に所属させ、current org / continuation を張る。
+    $member = User::factory()->unverified()->create();
+    $organization->users()->attach($member);
+    $member->addRole(OrganizationRole::Member->value, $organization->laratrust_team_id);
+    $member->forceFill(['current_organization_id' => $organization->id])->save();
+
+    $response = $this->actingAs($member)
+        ->withSession(['verify_continue_organization_id' => $organization->id])
+        ->get(($this->verificationUrlFor)($member));
+
+    // 第一段: verify 完了は onboarding.checkout へ redirect し continuation が消える。
+    $response->assertRedirect(route('onboarding.checkout'));
+    $response->assertSessionMissing('verify_continue_organization_id');
+    expect($member->fresh()?->hasVerifiedEmail())->toBeTrue();
+
+    // 第二段: onboarding.checkout は非管理メンバーを dashboard へ寄せる (中間ホップを保証)。
+    $this->actingAs($member->fresh())->get(route('onboarding.checkout'))
+        ->assertRedirect(route('dashboard'));
+});
+
 test('continuation なしの verify 完了は Fortify 既定と同値 (/dashboard?verified=1)', function (): void {
     $user = User::factory()->unverified()->create();
 
diff --git a/tests/Feature/Onboarding/OnboardingCheckoutTest.php b/tests/Feature/Onboarding/OnboardingCheckoutTest.php
index 21ec0cb2..e0d1667b 100644
--- a/tests/Feature/Onboarding/OnboardingCheckoutTest.php
+++ b/tests/Feature/Onboarding/OnboardingCheckoutTest.php
@@ -87,31 +87,92 @@ function expiredCheckoutOrganizationWithOwner(): array
         ->assertRedirect(route('billing.index'));
 });
 
-test('Subscribed の non-manager member は billing-required ではなく billing.index へ (判定順序の固定)', function (): void {
+// #2 [期待更新 Q-2-01]: 契約済み (paid) の非管理メンバーは、自分で操作できない
+// billing.index ではなく業務入口 dashboard へ寄せる。判定順序 (hasActiveAccess →
+// manageBilling) は不変で、分岐先だけを manageBilling 能力で切り替える。
+test('Subscribed の non-manager member は billing.index ではなく dashboard へ (Q-2-01)', function (): void {
     [$organization] = createOrganizationWithOwner(grandfatherFreePlan: false);
     contractPaidPlan($organization, status: 'active');
     $member = attachOrganizationMember($organization);
     $member->forceFill(['current_organization_id' => $organization->id])->save();
 
     $this->actingAs($member)->get('/onboarding/checkout')
-        ->assertRedirect(route('billing.index'));
+        ->assertRedirect(route('dashboard'));
 });
 
-test('ActiveFreePlan (free_plan_code=personal) は billing.index へ redirect', function (): void {
+test('Subscribed の manageBilling 保持 owner は billing.index へ (Q-2-01 で不変)', function (): void {
     [$organization, $owner] = createOrganizationWithOwner(grandfatherFreePlan: false);
+    contractPaidPlan($organization, status: 'active');
+
+    $this->actingAs($owner)->get('/onboarding/checkout')
+        ->assertRedirect(route('billing.index'));
+});
+
+/** ActiveFreePlan (free_plan_code=personal) の組織にする。 */
+function activateFreePersonalPlan(Organization $organization, User $declaredBy): void
+{
     $organization->forceFill(['plan_code' => 'standard'])->save(); // 移行 OR を経由しないことの明示
     contractPaidPlan($organization, status: 'canceled');
     $organization->forceFill([
         'free_plan_code' => 'personal',
         'free_plan_activated_at' => now(),
         'personal_declared_at' => now(),
-        'personal_declared_by_user_id' => $owner->getKey(),
+        'personal_declared_by_user_id' => $declaredBy->getKey(),
     ])->save();
+}
+
+test('ActiveFreePlan (free_plan_code=personal) の manageBilling 保持 owner は billing.index へ (Q-2-01 で不変)', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner(grandfatherFreePlan: false);
+    activateFreePersonalPlan($organization, $owner);
 
     $this->actingAs($owner)->get('/onboarding/checkout')
         ->assertRedirect(route('billing.index'));
 });
 
+// #4 [新規 Q-2-01]: bug-hunt が観測した実シナリオ。ActiveFreePlan (Personal free) の
+// 組織に属する非管理メンバーは、請求画面ではなく dashboard へ着地する。
+test('ActiveFreePlan + manageBilling 非保持 member は dashboard へ (Q-2-01 の既契約=Personal free ケース)', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner(grandfatherFreePlan: false);
+    activateFreePersonalPlan($organization, $owner);
+    $member = attachOrganizationMember($organization);
+    $member->forceFill(['current_organization_id' => $organization->id])->save();
+
+    $this->actingAs($member)->get('/onboarding/checkout')
+        ->assertRedirect(route('dashboard'));
+});
+
+// #6 [characterization / 境界回帰]: 未契約 (hasActiveAccess=false) の非管理メンバーは
+// dashboard には行かず billing-required へ。#4 と最も取り違えやすい境界 (active access の
+// 有無で dashboard か billing-required かが分かれる) を固定する。現行コードでも緑であり
+// (仕様変更テストではない)、変更後も緑を維持することで変更範囲が「active access を持つ
+// 非管理者だけ」であることを保証する。
+test('未契約 + manageBilling 非保持 member は billing-required へ (dashboard には行かない)', function (): void {
+    [$organization] = createOrganizationWithOwner(grandfatherFreePlan: false);
+    $member = attachOrganizationMember($organization);
+    $member->forceFill(['current_organization_id' => $organization->id])->save();
+
+    $this->actingAs($member)->get('/onboarding/checkout')
+        ->assertRedirect(route('onboarding.billing-required'));
+});
+
+// [着地の実効性]: dashboard への 302 の先で、非管理メンバーでも Dashboard 画面が
+// 課金ゲートに阻まれず 200 で開くこと (soft dead-end でないこと) を段階で固定する。
+test('dashboard 着地は 302 の先で実際に Dashboard 画面が 200 描画される', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner(grandfatherFreePlan: false);
+    activateFreePersonalPlan($organization, $owner);
+    $member = attachOrganizationMember($organization);
+    $member->forceFill(['current_organization_id' => $organization->id])->save();
+
+    // (1) onboarding.checkout が dashboard へ 302。
+    $this->actingAs($member)->get('/onboarding/checkout')
+        ->assertRedirect(route('dashboard'));
+
+    // (2)(3) 同一認証ユーザーで dashboard を GET すると 200 で Dashboard 画面が描画される。
+    $this->actingAs($member)->get(route('dashboard'))
+        ->assertOk()
+        ->assertInertia(fn (Assert $page): Assert => $page->component('Dashboard'));
+});
+
 test('未契約 org (plan_code IS NULL) は checkout を 200 で render する (P4 ゲート反転後)', function (): void {
     [$organization, $owner] = createOrganizationWithOwner(grandfatherFreePlan: false);
 

```

## テスト結果

- 対象 2 ファイル: 27 tests, 27 passed (実装前は #2/#4/dashboard実効性/continuation の 4 本が RED、#6 は実装前から GREEN を確認済み)。
- 施策 A/B/C の既存カバレッジテスト (SubscriptionCheckoutIdempotencyTest / EmailChangeTest / ProfileEmailChangeRecentAuthTest / PasswordSetupTest): 43 tests, 43 passed。
- 全 `composer test`: 6402 tests, 6400 passed, 2 skipped, 0 failed。
- `composer phpstan`: No errors (level 10)。
- `vendor/bin/pint --test`: passed。
- `pnpm lint` / `pnpm typecheck`: clean。`pnpm test`: 2369 passed。`pnpm build`: OK。
- `pnpm typecheck:packages` / `build:packages`: OK。`pnpm test:packages`: 106 passed。
