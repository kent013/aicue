【アプリの使命 (North Star)】
**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

【禁止事項】
1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(migrate:fresh 等)をエージェント判断で実行すること
4. response()->json() の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(app/Prompts/ の factory 経由のみ)
6. prompt 文字列のコード直書き(resources/prompts/*.yaml に置く)
7. 操作系 POST の応答での redirect()->intended()
8. 必須条件未充足を理由にボタンを disabled にする UI

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

【system: 役割】
あなたはシニア Laravel セキュリティレビュアー。T001 (AI-CUE ドメイン基盤: Category/VideoManual CRUD) の実装レビューで検出された以下の Warning に対する修正パッチの最終レビューを行う。修正パッチのみが対象 (T001 本体は前段レビューで green)。指摘は Critical / Warning / Suggestion に分類し、Critical が無ければ「Critical なし」と明言すること。

【前段レビューの Warning (修正対象)】
プロジェクトスコープの DB 検証ルール (categories.name の unique) が、{project} が current org に属するか検証される前 (= コントローラの resolveOrganizationProject 404 ガードより前) に実行されるため、cross-org プロジェクトに対するクロステナントの存在オラクルになる。認証済み・有効サブスクの攻撃者が POST /projects/{他組織 projectId}/categories に name=推測値 を送ると、存在時 422 / 非存在時 404 の差分で他組織のカテゴリ名を辞書探索できる。StoreVideoManualRequest / UpdateVideoManualRequest の category exists ルールも同根 (category↔project 所属可否が 422 vs 404 で漏れる)。レビュアーの提案は Route::bind('project', ...) の org スコープ binder 化。

【採った修正方針 (binder ではなく middleware にした理由)】
{project} param は API v1 (routes/api.php) でも使われ、API は org を API キーから確定する別レイヤー (ResolvesApiOrganization) のため、web セッション前提の global binder はコンテキスト分岐を持ち込む。代わりに EnsureProjectBelongsToCurrentOrganization middleware (alias: project.in-current-org) を web の業務 route group に一括付与した。middleware は FormRequest 解決 (= DB ルール実行) より前・SubstituteBindings より後に走るため、cross-org {project} は DB ルールより前に 404 になる。controller の inline guard は二重防御として維持。網羅性は新規 Architecture テスト (ProjectRouteCurrentOrgGuardTest: web の {project} route は必ず middleware を持つ / API は持たない、deny-by-default) で固定。回帰テストは CategoryCrudTest (unique 衝突 name で 404) / VideoManualCrudTest (exists の in/out 両方の category id で同一 404) に追加。全検証 (composer test 981 / phpstan / pint / pnpm lint / typecheck / test 278 / build) green。

【レビュー観点】
1. この修正は存在オラクルを完全に閉じているか (残余の 422/404 差分経路・順序ハザードは無いか)
2. middleware の実行順序の前提 (SubstituteBindings 後・FormRequest 前) は Laravel 12 で正しいか
3. middleware 選択 (binder 不採用) の判断は妥当か
4. Architecture テストの deny-by-default は将来の route 追加の guard 漏れを確実に落とすか
5. 新たな退行 (正当な same-org リクエストへの誤 404、API v1 への影響) は無いか

対象コードベース: カレントディレクトリ (worktree)。必要なら routes/web.php・routes/api.php・app/Http/Middleware/EnsureProjectBelongsToCurrentOrganization.php 等を読んでよい。

---

【user: 修正パッチ全文】
```diff
diff --git a/app/Http/Concerns/ResolvesCurrentOrganization.php b/app/Http/Concerns/ResolvesCurrentOrganization.php
index b9c2d44..b5e9b9e 100644
--- a/app/Http/Concerns/ResolvesCurrentOrganization.php
+++ b/app/Http/Concerns/ResolvesCurrentOrganization.php
@@ -36,6 +36,10 @@ private function resolveCurrentOrganization(Request $request): Organization
      * URL 整合 guard (D2 不変条件): URL 上の {project} が current org に属さなければ
      * **認可より前に 404** (403 で存在を漏らさない / cross-org は 404)。
      * 所属確認は relation (Organization::projects = CustomTeam 経由) のみで行う (直 fetch 禁止)。
+     *
+     * web の {project} route では EnsureProjectBelongsToCurrentOrganization middleware
+     * (project.in-current-org) が本 guard を FormRequest の DB ルールより**前**にも実行する
+     * (422/404 差分の存在オラクル防止)。controller 内の呼び出しは二重防御として維持する。
      */
     private function resolveOrganizationProject(Organization $organization, Project $project): Project
     {
diff --git a/bootstrap/app.php b/bootstrap/app.php
index 40ab660..546b82a 100644
--- a/bootstrap/app.php
+++ b/bootstrap/app.php
@@ -7,6 +7,7 @@
 use App\Http\Middleware\BughuntCoverageMiddleware;
 use App\Http\Middleware\EnforceMcpTransport;
 use App\Http\Middleware\EnsureEmailIsVerifiedOrBack;
+use App\Http\Middleware\EnsureProjectBelongsToCurrentOrganization;
 use App\Http\Middleware\HandleInertiaRequests;
 use App\Http\Middleware\IdempotentRequest;
 use App\Http\Middleware\McpConsentOrganizationBinder;
@@ -96,6 +97,10 @@
             // (context 別文言は EmailVerificationGateContext)。organizations.store /
             // organizations.invitations.store に withoutMiddleware('verified') とセットで付与。
             'verified.or-back' => EnsureEmailIsVerifiedOrBack::class,
+            // web の {project} route の URL 整合 guard。cross-org の {project} を
+            // FormRequest の DB ルール (unique/exists) より前に 404 へ落とす
+            // (存在オラクル防止。網羅性は ProjectRouteCurrentOrgGuardTest が固定)
+            'project.in-current-org' => EnsureProjectBelongsToCurrentOrganization::class,
             'resolve.api-actor' => ResolveApiActor::class,
             'api-key.ability' => RequireApiKeyAbility::class,
             'idempotent' => IdempotentRequest::class,
diff --git a/docs/app-integration-guide.md b/docs/app-integration-guide.md
index a9dafc0..03fac64 100644
--- a/docs/app-integration-guide.md
+++ b/docs/app-integration-guide.md
@@ -65,7 +65,7 @@ ### 見本: Item リソース(この手順の実演)
 | 保護キー集合への FK 追記 | `app/Support/Security/MassAssignmentProtectedKeys.php`(Item の FK `project_id` は**既存リストに含まれる**ため追記不要。新規 FK 名のときだけ追記する) |
 | FormRequest(`ProhibitsProtectedKeys` + missing rule) | `app/Http/Requests/Projects/StoreItemRequest.php` / `UpdateItemRequest.php` |
 | nested route(Team セグメントなし = Default Team パターン) | `routes/web.php` の `/projects/{project}/items` 系 |
-| URL 整合 guard(認可より**前**に 404) | {project} ∈ current org は `app/Http/Concerns/ResolvesCurrentOrganization.php` の `resolveOrganizationProject()`(inline guard)。{item} ∈ {project} は `routes/web.php` の `Route::scopeBindings()`(`$project->items()` 経由で解決) |
+| URL 整合 guard(認可より**前**に 404) | {project} ∈ current org は 2 層: `project.in-current-org` middleware(`app/Http/Middleware/EnsureProjectBelongsToCurrentOrganization.php`。FormRequest の DB ルールより**前**に cross-org を 404 に落とす = 存在オラクル防止。web の {project} route group に一括付与、網羅性は `tests/Architecture/ProjectRouteCurrentOrgGuardTest.php`)+ `app/Http/Concerns/ResolvesCurrentOrganization.php` の `resolveOrganizationProject()`(inline guard、二重防御)。{item} ∈ {project} は `routes/web.php` の `Route::scopeBindings()`(`$project->items()` 経由で解決) |
 | guard inventory への登録 | `tests/Architecture/NestedRouteIdorDefenseTest.php`(Web の `projects.items.update/destroy` = ScopeBindings、API の `api.v1.projects.items.update/destroy` = UrlIntegrityGuard) |
 | REST API v1 controller(Web と同じ FormRequest 再利用、org-scoped 解決) | `app/Http/Controllers/Api/V1/ItemController.php`(`ResolvesApiOrganization`) |
 | API リソース(レスポンス整形) | `app/Http/Resources/Api/V1/ItemResource.php` |
diff --git a/docs/template-divergence.md b/docs/template-divergence.md
index 958cf74..436e3d9 100644
--- a/docs/template-divergence.md
+++ b/docs/template-divergence.md
@@ -94,3 +94,32 @@ ### 揃えている不変条件(これは保証し続ける)
 ### 関連
 - 実装: `app/Services/Manual/CategoryService.php`, `app/Models/Category.php`
 - 設計: `devnotes/20260710-2137-aicue-domain-foundation/detailed-design.md` 施策7
+
+## D4 ✅ web `{project}` route の org スコープ guard を middleware 層に追加 (project.in-current-org)
+
+| 観点 | テンプレート | 本アプリ |
+|---|---|---|
+| {project} ∈ current org の guard | controller の inline guard (`resolveOrganizationProject`) のみ | `project.in-current-org` middleware (`EnsureProjectBelongsToCurrentOrganization`) を web の {project} route group に一括付与 + inline guard を二重防御として維持 |
+
+### なぜ正当な差分か(logic-driven)
+FormRequest のバリデーションは controller メソッド解決時 = inline guard より**前**に走る。
+テンプレの Item 見本は DB ルールを持たないため無害だが、T001 で追加した project スコープの
+DB ルール (categories.name の unique / category の exists) は、cross-org プロジェクトに対して
+422 (検証エラー) と 404 の応答差分を作り、他組織のカテゴリ名・所属関係を辞書探索できる
+存在オラクルになる (T001 セキュリティレビュー指摘)。middleware は FormRequest 解決より前
+(SubstituteBindings の後) に走るため、順序ハザードを route group 単位で構造的に閉じる。
+`Route::bind('project', ...)` の binder 化は不採用: `{project}` param は API v1
+(`routes/api.php`) でも使われ、API は org を API キーから確定する (`ResolvesApiOrganization`)
+ため、web セッション前提の binder はコンテキスト分岐を持ち込む。middleware なら web group に
+閉じて付与でき、API 側の解決モデルに触れない。
+
+### 揃えている不変条件(これは保証し続ける)
+> 「cross-org の {project} は、FormRequest の DB ルールを含むあらゆるアプリコードより前に 404
+> (403 や 422 で存在を漏らさない)」
+`tests/Architecture/ProjectRouteCurrentOrgGuardTest.php` が deny-by-default で
+「web の {project} route は必ず本 middleware を持つ / API は持たない」を機械検証する。
+実挙動は `CategoryCrudTest` (unique 探索 404) / `VideoManualCrudTest` (exists 探索 404) が固定する。
+
+### 関連
+- 実装: `app/Http/Middleware/EnsureProjectBelongsToCurrentOrganization.php`, `routes/web.php`, `bootstrap/app.php`
+- テンプレート側の根拠: `docs/app-integration-guide.md` §2 (URL 整合 guard 行を 2 層構成に更新済み)
diff --git a/routes/web.php b/routes/web.php
index 953d6f2..303f102 100644
--- a/routes/web.php
+++ b/routes/web.php
@@ -293,11 +293,15 @@
     | を持たない組織は billing へ redirect される (JSON は 402)。
     | 新しい業務ドメインの route はこの group 内に追加すること。
     */
-    Route::middleware('require-active-subscription')->group(function (): void {
+    Route::middleware(['require-active-subscription', 'project.in-current-org'])->group(function (): void {
         /*
         | プロジェクト (current org スコープ。URL に org / team セグメントを含めない =
         | Default Team パターンのルーティング仕様)。
-        | {project} は URL 整合 guard ({project} ∈ current org) で認可より前に 404。
+        | {project} の URL 整合 guard ({project} ∈ current org) は 2 層:
+        | (1) project.in-current-org middleware — FormRequest の DB ルール (unique/exists) より
+        |     前に cross-org を 404 に落とす (存在オラクル防止。{project} を持たない route では
+        |     no-op のため group 一括付与。網羅性は ProjectRouteCurrentOrgGuardTest が固定)
+        | (2) controller の inline guard (resolveOrganizationProject) — 二重防御
         */
         Route::get('/projects', [ProjectController::class, 'index'])
             ->name('projects.index');
diff --git a/tests/Architecture/NestedRouteIdorDefenseTest.php b/tests/Architecture/NestedRouteIdorDefenseTest.php
index 76b6a56..c049bb4 100644
--- a/tests/Architecture/NestedRouteIdorDefenseTest.php
+++ b/tests/Architecture/NestedRouteIdorDefenseTest.php
@@ -40,14 +40,18 @@ function nestedRouteIdorInventory(): array
         'organizations.api-keys.sessions.revoke' => $s,
         // {invitation} は $organization->invitations() 経由 (招待取り消し。cross-org は 404)
         'organizations.invitations.revoke' => $s,
-        // {item} は $project->items() 経由 ({project} ∈ current org は controller の inline guard)
+        // {item} は $project->items() 経由 ({project} ∈ current org は
+        // project.in-current-org middleware + controller inline guard の 2 層)
         'projects.items.update' => $s,
         'projects.items.destroy' => $s,
-        // {category} は $project->categories() 経由 ({project} ∈ current org は controller の inline guard)
+        // {category} は $project->categories() 経由 ({project} ∈ current org は
+        // project.in-current-org middleware + controller inline guard の 2 層。
+        // FormRequest の DB ルール (unique) より前の 404 は ProjectRouteCurrentOrgGuardTest 参照)
         'projects.categories.update' => $s,
         'projects.categories.destroy' => $s,
         // {manual} は $project->manuals() 経由 (relation 名は route パラメータ {manual} の
-        // scopeBindings 推論と一致させた manuals()。{project} ∈ current org は inline guard)
+        // scopeBindings 推論と一致させた manuals()。{project} ∈ current org は
+        // project.in-current-org middleware + inline guard の 2 層)
         'projects.manuals.show' => $s,
         'projects.manuals.edit' => $s,
         'projects.manuals.update' => $s,
diff --git a/tests/Feature/Projects/CategoryCrudTest.php b/tests/Feature/Projects/CategoryCrudTest.php
index b1ee251..d8b221b 100644
--- a/tests/Feature/Projects/CategoryCrudTest.php
+++ b/tests/Feature/Projects/CategoryCrudTest.php
@@ -180,6 +180,27 @@
     expect($categoryB->fresh()?->name)->toBe('元の名前');
 });
 
+test('cross-org project へのカテゴリ name 探索は unique 検証より前に 404 (存在オラクル防止)', function (): void {
+    [, $ownerA] = createOrganizationWithOwner('組織A');
+    [$orgB] = createOrganizationWithOwner('組織B');
+    $projectB = Project::factory()->forOrganization($orgB)->create();
+    $categoryB = Category::factory()->forProject($projectB)->create(['name' => '秘密カテゴリ']);
+
+    // 衝突する name を送っても 422 (unique) ではなく 404 — 422/404 の応答差分で
+    // 他組織プロジェクトのカテゴリ名を辞書探索できないこと (project.in-current-org middleware が
+    // FormRequest の DB ルールより前に cross-org を 404 に落とす)
+    $this->actingAs($ownerA)
+        ->from('/projects')
+        ->post("/projects/{$projectB->id}/categories", ['name' => '秘密カテゴリ'])
+        ->assertNotFound();
+    $this->actingAs($ownerA)
+        ->from('/projects')
+        ->patch("/projects/{$projectB->id}/categories/{$categoryB->id}", ['name' => '秘密カテゴリ'])
+        ->assertNotFound();
+
+    expect($projectB->categories()->count())->toBe(1);
+});
+
 test('cross-project の {category} は 404 (scopeBindings)', function (): void {
     [$organization, $owner] = createOrganizationWithOwner();
     $projectA = Project::factory()->forOrganization($organization)->create();
diff --git a/tests/Feature/Projects/VideoManualCrudTest.php b/tests/Feature/Projects/VideoManualCrudTest.php
index 11f0d87..80336ad 100644
--- a/tests/Feature/Projects/VideoManualCrudTest.php
+++ b/tests/Feature/Projects/VideoManualCrudTest.php
@@ -229,6 +229,35 @@
     expect($manualB->fresh()?->title)->toBe('元のタイトル');
 });
 
+test('cross-org project への category id 探索は exists 検証より前に 404 (所属オラクル防止)', function (): void {
+    [, $ownerA] = createOrganizationWithOwner('組織A');
+    [$orgB] = createOrganizationWithOwner('組織B');
+    $projectB = Project::factory()->forOrganization($orgB)->create();
+    $categoryInB = Category::factory()->forProject($projectB)->create();
+    $otherProjectB = Project::factory()->forOrganization($orgB)->create();
+    $categoryOutOfB = Category::factory()->forProject($otherProjectB)->create();
+    $manualB = VideoManual::factory()->forProject($projectB)->create(['title' => '元のタイトル']);
+
+    // {project} に属する category id / 属さない category id のどちらも同じ 404 —
+    // exists ルールの 422/404 差分で他組織の category↔project 所属関係を探索できないこと
+    // (project.in-current-org middleware が FormRequest の DB ルールより前に 404 に落とす)
+    $this->actingAs($ownerA)
+        ->from('/projects')
+        ->post("/projects/{$projectB->id}/manuals", ['title' => 'x', 'category' => $categoryInB->id])
+        ->assertNotFound();
+    $this->actingAs($ownerA)
+        ->from('/projects')
+        ->post("/projects/{$projectB->id}/manuals", ['title' => 'x', 'category' => $categoryOutOfB->id])
+        ->assertNotFound();
+    $this->actingAs($ownerA)
+        ->from('/projects')
+        ->patch("/projects/{$projectB->id}/manuals/{$manualB->id}", ['title' => 'x', 'category' => $categoryOutOfB->id])
+        ->assertNotFound();
+
+    expect($projectB->manuals()->count())->toBe(1);
+    expect($manualB->fresh()?->title)->toBe('元のタイトル');
+});
+
 test('cross-project の {manual} は 404 (scopeBindings)', function (): void {
     [$organization, $owner] = createOrganizationWithOwner();
     $projectA = Project::factory()->forOrganization($organization)->create();
diff --git a/app/Http/Middleware/EnsureProjectBelongsToCurrentOrganization.php b/app/Http/Middleware/EnsureProjectBelongsToCurrentOrganization.php
new file mode 100644
index 0000000..f4c573a
--- /dev/null
+++ b/app/Http/Middleware/EnsureProjectBelongsToCurrentOrganization.php
@@ -0,0 +1,53 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Http\Middleware;
+
+use App\Http\Concerns\ResolvesCurrentOrganization;
+use App\Models\Project;
+use Closure;
+use Illuminate\Http\Request;
+use Symfony\Component\HttpFoundation\Response;
+
+/**
+ * web の `{project}` route の URL 整合 guard (middleware 層)。alias: project.in-current-org。
+ *
+ * cross-org の {project} を「FormRequest の DB ルールを含むあらゆるアプリコードより前に 404」
+ * へ構造的に落とす。controller の inline guard (resolveOrganizationProject) は認可より前の
+ * 404 を担うが、FormRequest のバリデーションは controller メソッド解決時 = inline guard より
+ * **前**に走るため、project スコープの DB ルール (categories.name の unique / category の
+ * exists 等) が cross-org プロジェクトに対する 422/404 差分の存在オラクルになる。
+ * middleware は FormRequest 解決より前 (SubstituteBindings の後) に走るため、
+ * この順序ハザードを route group 単位で構造的に閉じる。
+ *
+ * 適用境界:
+ *  - routes/web.php の業務 route group (require-active-subscription とセット) に付与する。
+ *    {project} param を持たない route では no-op (group 一括付与を許容し、将来の
+ *    project 配下 route 追加時の guard 漏れを防ぐ)。
+ *  - 網羅性は tests/Architecture/ProjectRouteCurrentOrgGuardTest が deny-by-default で固定する
+ *    (web の {project} route は必ず本 middleware を持つ / API は持たない)。
+ *  - API v1 は org を API キーから確定する別レイヤー (ResolvesApiOrganization) の責務のため
+ *    対象外 (web セッションの current org 前提の本 middleware を付けてはならない)。
+ *  - controller の inline guard は二重防御として残す (oauthSessions の controller 内再検査と
+ *    同じ位置づけ。middleware の付け漏れ・withoutMiddleware への最終防衛線)。
+ */
+class EnsureProjectBelongsToCurrentOrganization
+{
+    use ResolvesCurrentOrganization;
+
+    /**
+     * @param  Closure(Request): Response  $next
+     */
+    public function handle(Request $request, Closure $next): Response
+    {
+        $project = $request->route('project');
+
+        if ($project instanceof Project) {
+            $organization = $this->resolveCurrentOrganization($request);
+            $this->resolveOrganizationProject($organization, $project);
+        }
+
+        return $next($request);
+    }
+}
diff --git a/tests/Architecture/ProjectRouteCurrentOrgGuardTest.php b/tests/Architecture/ProjectRouteCurrentOrgGuardTest.php
new file mode 100644
index 0000000..465e455
--- /dev/null
+++ b/tests/Architecture/ProjectRouteCurrentOrgGuardTest.php
@@ -0,0 +1,57 @@
+<?php
+
+declare(strict_types=1);
+
+use Illuminate\Support\Facades\Route;
+
+/*
+ * web の `{project}` route は project.in-current-org middleware
+ * (EnsureProjectBelongsToCurrentOrganization) を必ず持つ invariant。
+ *
+ * cross-org の {project} は「FormRequest の DB ルール (unique/exists) を含む
+ * あらゆるアプリコードより前に 404」でなければならない (存在オラクル防止)。
+ * controller の inline guard (resolveOrganizationProject) は認可より前の 404 を担うが、
+ * FormRequest のバリデーションは controller メソッド解決時 (= inline guard より前) に走るため、
+ * middleware 層の guard が無いと project スコープの DB ルールがクロステナントの
+ * 存在オラクルになる (T001 レビュー指摘)。本テストは deny-by-default で
+ * 「{project} を受ける web route に middleware が付いていること」を機械検証し、
+ * 将来の route 追加での guard 漏れを構造的に落とす。
+ *
+ * API v1 (`api/*`) は org を API キーから確定する別レイヤー (ResolvesApiOrganization) の
+ * 責務のため対象外 (web セッション前提の本 middleware を付けてはならない)。
+ */
+test('web の {project} route は project.in-current-org middleware を必ず持つ (API は持たない)', function (): void {
+    $checked = 0;
+    $violations = [];
+
+    foreach (Route::getRoutes() as $route) {
+        if (! in_array('project', $route->parameterNames(), true)) {
+            continue;
+        }
+
+        $name = $route->getName() ?? $route->uri();
+        $middleware = $route->gatherMiddleware();
+
+        if (str_starts_with($route->uri(), 'api/')) {
+            // API は web セッション (current org) を持たない。誤配線は全 API project route を
+            // 404 に落とすため、付いていたら fail させる
+            if (in_array('project.in-current-org', $middleware, true)) {
+                $violations[] = "API route {$name} に web セッション前提の project.in-current-org が付いている";
+            }
+            $checked++;
+
+            continue;
+        }
+
+        if (! in_array('project.in-current-org', $middleware, true)) {
+            $violations[] = "web route {$name} に project.in-current-org middleware が無い"
+                .' (cross-org {project} が FormRequest の DB ルールより前に 404 になりません)';
+        }
+        $checked++;
+    }
+
+    expect($violations)->toBe([]);
+    // route が 1 本も検査されない (= {project} route が消えた/リネームされた) 場合も fail させ、
+    // テスト自体の空振り drift を検知する
+    expect($checked)->toBeGreaterThan(0);
+});
```
