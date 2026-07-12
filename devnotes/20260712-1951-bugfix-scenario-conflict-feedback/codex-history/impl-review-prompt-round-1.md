# アプリの使命 (North Star)

**AI-CUE** は、現場に既にある作業手順書(SOP)を起点に、AI が撮るべきカットを設計した動画シナリオを生成し、そのシナリオをスマホ(PWA)でナビゲーション撮影することで、専門知識ゼロの現場作業者でも標準化されたマニュアル動画を作れるようにする。「思考ゼロ・編集ゼロ」。

# 禁止事項

1. テストなしの実装完了報告
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作
4. response()->json() の直書き(DTO / JsonResource / Inertia を使う)
5. LLM 呼び出しの Prism 直呼び
6. prompt 文字列のコード直書き
7. 操作系 POST の応答での redirect()->intended()
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)

【思考原則 — 全議論に適用】
まず仮説を立てろ。データに真摯に向き合え。先人の知恵を探せ。機能の名前に立ち返れ。仕組みが機能していない段階で値を弄るな。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

# system: 実装レビュアー

あなたは経験豊富な Web アプリケーションのコードレビュアーです。Laravel 12 + Svelte 5 + Inertia.js + TypeScript の改善実装をレビューしてください。

【前提環境】PHP 8.4 / PHPStan level 10 / Pest (RefreshDatabase グローバル + --parallel) / DTO + JsonResource / DESIGN.md が design token の canonical source / Atomic Design (atoms/molecules/organisms/templates)。

【レビュー観点】
1. 設計との一致性(下記 detailed-design.md 準拠)
2. コードの正確性(ロジック・エッジケース・null 安全)
3. PHPStan level 10 適合性
4. テスト網羅性(各施策に Pest/Vitest、再現性)
5. DTO/JsonResource パターン遵守
6. セキュリティ(認可・入力・情報漏洩)
7. DESIGN.md 準拠(color/radius/typography を token 経由、hex 直書きを増やさない)
8. Atomic Design 準拠(階層の逆流なし、Lucide アイコン、SVG 直書きを増やさない)

【出力形式】
- ファイルごとに判定 (APPROVE / REQUEST_CHANGES)
- 指摘は [Critical] [Warning] [Suggestion] で分類、Critical/Warning には修正案を必ず添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

---

# user

## 詳細設計書
リポジトリ内の `devnotes/20260712-1951-bugfix-scenario-conflict-feedback/detailed-design.md` を読んでください (APPROVED 済み)。2 施策:
- 施策1 (F-02): ScenarioEditor 保存失敗フィードバックの再構成 (SaveFailure union 化・操作点直近表示・focus/scrollIntoView・403 分岐追加)。保存ロジック・409 応答契約は無変更。
- 施策2 (F-05): 動画マニュアル関連 4 画面の固有 <title> 供給 (setPrivateTitle / config/seo.php)。

## design system 参照
- 使用 token: 既存 `text-caption` / `text-text-secondary` のみ。hex 直書き追加なし。
- 新規 atomic コンポーネントなし。既存 `atoms/Alert.svelte` の `action` prop (Snippet) を条件付きで渡すのみ。アイコン変更なし。

## 実装差分 (git diff)
```diff
diff --git a/app/Http/Controllers/Capture/CaptureManualController.php b/app/Http/Controllers/Capture/CaptureManualController.php
index a0dc038..d932bb3 100644
--- a/app/Http/Controllers/Capture/CaptureManualController.php
+++ b/app/Http/Controllers/Capture/CaptureManualController.php
@@ -16,6 +16,7 @@
 use App\Services\Capture\TakeObjectStorage;
 use App\Services\Capture\UploadTicketCodec;
 use App\Services\Project\DefaultProjectResolver;
+use App\Support\Seo\SeoManager;
 use Illuminate\Contracts\Database\Eloquent\Builder;
 use Illuminate\Http\RedirectResponse;
 use Illuminate\Http\Request;
@@ -90,11 +91,15 @@ public function show(
         VideoManual $manual,
         TakeObjectStorage $storage,
         UploadTicketCodec $codec,
+        SeoManager $seo,
     ): Response {
         $organization = $this->resolveCurrentOrganization($request);
         $this->resolveOrganizationProject($organization, $project); // 認可より前に 404
         Gate::authorize('view', $manual); // 読み取りは撮影者含む org member
 
+        // 撮影 PWA であることをタブ上で判別可能にする動的固有名
+        $seo->setPrivateTitle($manual->title.' の撮影');
+
         $user = $request->user();
         Assert::isInstanceOf($user, User::class);
 
diff --git a/app/Http/Controllers/Projects/VideoManualController.php b/app/Http/Controllers/Projects/VideoManualController.php
index e312fa6..6293ba0 100644
--- a/app/Http/Controllers/Projects/VideoManualController.php
+++ b/app/Http/Controllers/Projects/VideoManualController.php
@@ -18,6 +18,7 @@
 use App\Models\User;
 use App\Models\VideoManual;
 use App\Services\Manual\VideoManualService;
+use App\Support\Seo\SeoManager;
 use Illuminate\Http\RedirectResponse;
 use Illuminate\Http\Request;
 use Illuminate\Http\UploadedFile;
@@ -88,13 +89,16 @@ public function store(StoreVideoManualRequest $request, Project $project, VideoM
     }
 
     /** 詳細 (撮影者も閲覧可) */
-    public function show(Request $request, Project $project, VideoManual $manual): Response
+    public function show(Request $request, Project $project, VideoManual $manual, SeoManager $seo): Response
     {
         $organization = $this->resolveCurrentOrganization($request);
         // URL 整合 guard: 認可より前に 404 ({manual} ∈ {project} は scopeBindings が担保済み)
         $this->resolveOrganizationProject($organization, $project);
         Gate::authorize('view', $manual);
 
+        // 動的固有名の per-page タイトル (noindex 維持。projects.show の参考実装踏襲)
+        $seo->setPrivateTitle($manual->title);
+
         $user = $request->user();
         Assert::isInstanceOf($user, User::class);
 
@@ -141,13 +145,16 @@ public function show(Request $request, Project $project, VideoManual $manual): R
     }
 
     /** 編集フォーム (メタデータ = title / category + シナリオ document) */
-    public function edit(Request $request, Project $project, VideoManual $manual): Response
+    public function edit(Request $request, Project $project, VideoManual $manual, SeoManager $seo): Response
     {
         $organization = $this->resolveCurrentOrganization($request);
         // URL 整合 guard: 認可より前に 404 ({manual} ∈ {project} は scopeBindings が担保済み)
         $this->resolveOrganizationProject($organization, $project);
         Gate::authorize('update', $manual);
 
+        // 複数 manual の並行編集タブを判別できるよう動的固有名 (概念レビュー合意)
+        $seo->setPrivateTitle($manual->title.' の編集');
+
         return Inertia::render('Manuals/Edit', [
             'project' => [
                 'id' => $project->id,
diff --git a/config/seo.php b/config/seo.php
index 984c82b..a32a64b 100644
--- a/config/seo.php
+++ b/config/seo.php
@@ -105,6 +105,9 @@
         'projects.index' => 'プロジェクト',
         'projects.create' => 'プロジェクトの作成',
         'projects.edit' => 'プロジェクトの編集',
+        // 動画マニュアル (show/edit/撮影 show は controller が setPrivateTitle で
+        // マニュアル名を供給。create のみ静的 = 対象実体が未存在のため)
+        'projects.manuals.create' => '動画マニュアルの作成',
     ],
 
 ];
diff --git a/resources/js/components/features/manual/ScenarioEditor.svelte b/resources/js/components/features/manual/ScenarioEditor.svelte
index 8ed87dc..66c37fc 100644
--- a/resources/js/components/features/manual/ScenarioEditor.svelte
+++ b/resources/js/components/features/manual/ScenarioEditor.svelte
@@ -1,4 +1,5 @@
 <script lang="ts">
+    import { tick } from "svelte";
     import { router } from "@inertiajs/svelte";
     import { ChevronDown, ChevronUp, ListPlus, Plus, Trash2 } from "@lucide/svelte";
     import Alert from "@/components/atoms/Alert.svelte";
@@ -92,8 +93,30 @@
     let snapshot = $state(serializeSteps(toDraftSteps(scenario.steps)));
     let saving = $state(false);
     let errors = $state<Record<string, string[]>>({});
-    let conflict = $state<ScenarioConflictBody | null>(null);
-    let genericError = $state<string | null>(null);
+
+    /**
+     * 保存失敗フィードバックの判別可能 union。
+     * - conflict: 409 (scenario_conflict 契約。理由はサーバ供給 message)
+     * - forbidden: 403 (セッション途中の権限剥奪等。将来の再ログイン導線はこの分岐に足す)
+     * - generic: 通信断・5xx・shape 不一致などその他の失敗
+     */
+    type SaveFailure =
+        | { kind: "conflict"; body: ScenarioConflictBody }
+        | { kind: "forbidden" }
+        | { kind: "generic"; message: string };
+
+    /** アラート描画用の表示モデル (kind → 見た目の導出を switch 1 箇所に集約) */
+    interface FailureView {
+        type: "warning" | "danger";
+        title?: string;
+        message: string;
+        showReloadCta: boolean;
+        testId: string;
+    }
+
+    let saveFailure = $state<SaveFailure | null>(null);
+    /** 失敗アラートの focus 対象 wrapper (tabindex=-1) */
+    let failureEl = $state<HTMLDivElement | null>(null);
     let confirmingStepIndex = $state<number | null>(null);
     let confirmingReload = $state(false);
     /** 明示同意済みの最新取得中フラグ (dirty 離脱確認を二重に出さない) */
@@ -146,19 +169,81 @@
         [points[index], points[next]] = [points[next], points[index]];
     }
 
+    /**
+     * union 網羅の型固定 (kind 追加時は引数の never 不一致でコンパイルエラーになり
+     * 表示漏れを検出する)。runtime に到達した場合は throw せず汎用 fallback を返す
+     * ($derived 内の throw で画面全体を巻き込まない。詳細レビュー合意)。
+     */
+    function unreachableFailureView(_value: never): FailureView {
+        return {
+            type: "danger",
+            message: "保存に失敗しました。時間をおいて再度お試しください。",
+            showReloadCta: false,
+            testId: "scenario-generic-error",
+        };
+    }
+
+    const failureView = $derived.by((): FailureView | null => {
+        if (saveFailure === null) return null; // null 先処理 → switch (概念レビュー合意)
+        switch (saveFailure.kind) {
+            case "conflict":
+                return {
+                    type: "warning",
+                    title: CONFLICT_TITLES[saveFailure.body.conflict_type],
+                    message: saveFailure.body.message,
+                    showReloadCta: saveFailure.body.conflict_type === "version_mismatch",
+                    testId: "scenario-conflict-banner",
+                };
+            case "forbidden":
+                return {
+                    type: "danger",
+                    message:
+                        "この操作を行う権限がありません。ページを再読み込みして状態を確認してください。",
+                    showReloadCta: false,
+                    testId: "scenario-forbidden-error",
+                };
+            case "generic":
+                return {
+                    type: "danger",
+                    message: saveFailure.message,
+                    showReloadCta: false,
+                    testId: "scenario-generic-error",
+                };
+            default:
+                return unreachableFailureView(saveFailure);
+        }
+    });
+
+    /**
+     * 失敗フィードバックの単一表示経路 (全 kind 共通)。state 確定 → tick() で DOM 反映を
+     * 待ち → focus({preventScroll}) → scrollIntoView({block:"nearest"}) の順で知覚させる。
+     * 明示呼び出し限定 ($effect の state 監視にしない = 無関係な再レンダで再発火しない)。
+     * focus 既定スクロールは抑止し、スクロール制御を scrollIntoView に一本化する
+     * (完全可視ならスクロールは原則発生せず、連続失敗時のジャンプを起こしにくい)。
+     */
+    async function showFailure(failure: SaveFailure): Promise<void> {
+        saveFailure = failure;
+        await tick();
+        failureEl?.focus({ preventScroll: true });
+        // UA 差異を残さないよう block/inline/behavior を全指定で固定 (Vitest は引数完全一致で担保)
+        failureEl?.scrollIntoView({ block: "nearest", inline: "nearest", behavior: "auto" });
+    }
+
     async function save(): Promise<void> {
         if (saving) return; // 多重送信ガード (disabled にはしない。押下は受けて即 return)
         saving = true;
         errors = {};
-        conflict = null;
-        genericError = null; // 前回の失敗表示をクリア (再保存成功後に旧エラーを残さない)
+        saveFailure = null; // 前回の失敗表示をクリア (再保存成功後に旧エラーを残さない)
         try {
             const res = await putScenario();
             await handleResponse(res);
         } catch {
             // ネットワーク断・fetch reject (419 回復 GET / 再試行 PUT の reject も含む)。
             // 作業コピーは保持したまま汎用エラーを表示 (未処理 Promise を漏らさない)
-            genericError = "通信に失敗しました。接続を確認して再度お試しください。";
+            await showFailure({
+                kind: "generic",
+                message: "通信に失敗しました。接続を確認して再度お試しください。",
+            });
         } finally {
             saving = false;
         }
@@ -186,7 +271,10 @@
                 applySaved(body);
                 return;
             }
-            genericError = "保存結果の取得に失敗しました。画面を再読み込みしてください。";
+            await showFailure({
+                kind: "generic",
+                message: "保存結果の取得に失敗しました。画面を再読み込みしてください。",
+            });
             return;
         }
         if (res.status === 419 && !retried) {
@@ -200,14 +288,22 @@
         }
         if (res.status === 401 || res.status === 419) {
             // セッション失効: 作業コピーは破棄せず、別タブでの再ログインを案内 (リダイレクトしない)
-            genericError =
-                "セッションが切れました。別のタブでログインし直してから、もう一度保存してください。";
+            await showFailure({
+                kind: "generic",
+                message:
+                    "セッションが切れました。別のタブでログインし直してから、もう一度保存してください。",
+            });
+            return;
+        }
+        if (res.status === 403) {
+            // 権限剥奪など。理由を明示する (汎用「時間をおいて再試行」への誤誘導をやめる)
+            await showFailure({ kind: "forbidden" });
             return;
         }
         if (res.status === 409) {
             const body = (await res.json().catch(() => null)) as ScenarioConflictBody | null;
             if (body?.code === "scenario_conflict") {
-                conflict = body; // 作業コピーは保持 (黙って編集内容を失わない)
+                await showFailure({ kind: "conflict", body }); // 作業コピーは保持
                 return;
             }
         }
@@ -219,7 +315,10 @@
                 return;
             }
         }
-        genericError = "保存に失敗しました。時間をおいて再度お試しください。";
+        await showFailure({
+            kind: "generic",
+            message: "保存に失敗しました。時間をおいて再度お試しください。",
+        });
     }
 
     /**
@@ -281,7 +380,7 @@
      */
     function reloadScenario(): void {
         confirmingReload = false;
-        conflict = null;
+        saveFailure = null;
         reloading = true;
         router.reload({
             only: ["scenario", "manual"],
@@ -291,13 +390,17 @@
                     reseed(latest);
                     return;
                 }
-                genericError =
-                    "最新シナリオの取得に失敗しました。画面を再読み込みしてください。";
+                void showFailure({
+                    kind: "generic",
+                    message: "最新シナリオの取得に失敗しました。画面を再読み込みしてください。",
+                });
             },
             onError: () => {
                 // 部分リロード自体の失敗 (ネットワーク断等)。無反応に見せない
-                genericError =
-                    "最新シナリオの取得に失敗しました。画面を再読み込みしてください。";
+                void showFailure({
+                    kind: "generic",
+                    message: "最新シナリオの取得に失敗しました。画面を再読み込みしてください。",
+                });
             },
             onFinish: () => {
                 reloading = false;
@@ -482,31 +585,6 @@
 {/snippet}
 
 <section aria-label="シナリオ編集">
-    {#if conflict}
-        <Alert type="warning" title={CONFLICT_TITLES[conflict.conflict_type]} testId="scenario-conflict-banner">
-            <p>{conflict.message}</p>
-            {#snippet action()}
-                {#if conflict?.conflict_type === "version_mismatch"}
-                    <Button
-                        variant="neutral"
-                        size="sm"
-                        onclick={() => (confirmingReload = true)}
-                        testId="scenario-conflict-reload"
-                    >
-                        サーバの最新を取得
-                    </Button>
-                {/if}
-            {/snippet}
-        </Alert>
-    {/if}
-    {#if genericError}
-        <div class="mt-3">
-            <Alert type="danger" testId="scenario-generic-error">
-                <p>{genericError}</p>
-            </Alert>
-        </div>
-    {/if}
-
     {#if steps.length === 0}
         <div class="mt-4">
             <EmptyState
@@ -639,6 +717,37 @@
         </div>
     {/if}
 
+    <!-- 再取得 CTA (トップレベル snippet として宣言し、必要な場合のみ Alert の action prop へ渡す) -->
+    {#snippet reloadAction()}
+        <Button
+            variant="neutral"
+            size="sm"
+            onclick={() => (confirmingReload = true)}
+            testId="scenario-conflict-reload"
+        >
+            サーバの最新を取得
+        </Button>
+    {/snippet}
+
+    {#if failureView}
+        <!-- 操作点 (シナリオを更新) 直上の失敗フィードバック。tabindex=-1 で programmatic focus を受ける -->
+        <div
+            class="mt-6 focus:outline-none"
+            bind:this={failureEl}
+            tabindex="-1"
+            data-testid="scenario-failure-region"
+        >
+            <Alert
+                type={failureView.type}
+                title={failureView.title}
+                action={failureView.showReloadCta ? reloadAction : undefined}
+                testId={failureView.testId}
+            >
+                <p>{failureView.message}</p>
+            </Alert>
+        </div>
+    {/if}
+
     <div class="mt-6 flex items-center gap-2">
         <Button onclick={save} loading={saving} testId="scenario-submit">シナリオを更新</Button>
         {#if dirty}
diff --git a/tests/Feature/Projects/ManualPageTitleTest.php b/tests/Feature/Projects/ManualPageTitleTest.php
new file mode 100644
index 0000000..42e7610
--- /dev/null
+++ b/tests/Feature/Projects/ManualPageTitleTest.php
@@ -0,0 +1,101 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Models\Project;
+use App\Models\VideoManual;
+use Inertia\Testing\AssertableInertia as Assert;
+
+/*
+ * F-05: 動画マニュアル関連 4 画面が固有の <title> を供給する回帰テスト。
+ * - create は静的 app_titles 経路 / show・edit・撮影 show は controller の setPrivateTitle
+ *   (動的固有名 = マニュアル名) 経路。いずれも noindex は維持する (認証配下)。
+ * - Inertia 共有 prop `title` はサーバ描画 <title> と同一 (SPA 遷移の title 追従 SoT)。
+ * URL は route 名解決で構築し、route 名変更時に app_titles キーの取り残しを検知する。
+ */
+
+beforeEach(function (): void {
+    config([
+        'seo.base_url' => 'https://app.example',
+        'seo.site_name' => 'Acme',
+        'seo.default_title' => 'Acme',
+        'seo.title_separator' => ' | ',
+        'seo.default_description' => '既定の説明文',
+    ]);
+});
+
+it('projects.manuals.create は静的 app_titles で固有 title を出す (noindex 維持)', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+
+    $html = (string) $this->actingAs($owner)
+        ->get(route('projects.manuals.create', $project))
+        ->getContent();
+
+    expect($html)->toContain('<title>動画マニュアルの作成 | Acme</title>')
+        ->toContain('<meta name="robots" content="noindex">');
+});
+
+it('projects.manuals.show はマニュアル名の動的固有 title を出す', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+    $manual = VideoManual::factory()->forProject($project)->create(['title' => 'ネジ締め作業']);
+
+    $html = (string) $this->actingAs($owner)
+        ->get(route('projects.manuals.show', [$project, $manual]))
+        ->getContent();
+
+    expect($html)->toContain('<title>ネジ締め作業 | Acme</title>')
+        ->toContain('<meta name="robots" content="noindex">');
+});
+
+it('projects.manuals.edit は「<マニュアル名> の編集」を title に出す', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+    $manual = VideoManual::factory()->forProject($project)->create(['title' => 'ネジ締め作業']);
+
+    $html = (string) $this->actingAs($owner)
+        ->get(route('projects.manuals.edit', [$project, $manual]))
+        ->getContent();
+
+    expect($html)->toContain('<title>ネジ締め作業 の編集 | Acme</title>');
+});
+
+it('capture.manuals.show は「<マニュアル名> の撮影」を title に出す', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+    $manual = VideoManual::factory()->forProject($project)->create([
+        'status' => 'ready',
+        'title' => 'ネジ締め作業',
+    ]);
+
+    $html = (string) $this->actingAs($owner)
+        ->get(route('capture.manuals.show', [$project, $manual]))
+        ->getContent();
+
+    expect($html)->toContain('<title>ネジ締め作業 の撮影 | Acme</title>');
+});
+
+it('Inertia 共有 prop title はサーバ描画 <title> と同一文字列 (show / edit / 撮影 show)', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+    $manual = VideoManual::factory()->forProject($project)->create([
+        'status' => 'ready',
+        'title' => 'ネジ締め作業',
+    ]);
+
+    $this->actingAs($owner)->get(route('projects.manuals.show', [$project, $manual]))
+        ->assertInertia(fn (Assert $page) => $page
+            ->component('Manuals/Show')
+            ->where('title', 'ネジ締め作業 | Acme'));
+
+    $this->actingAs($owner)->get(route('projects.manuals.edit', [$project, $manual]))
+        ->assertInertia(fn (Assert $page) => $page
+            ->component('Manuals/Edit')
+            ->where('title', 'ネジ締め作業 の編集 | Acme'));
+
+    $this->actingAs($owner)->get(route('capture.manuals.show', [$project, $manual]))
+        ->assertInertia(fn (Assert $page) => $page
+            ->component('Capture/Show')
+            ->where('title', 'ネジ締め作業 の撮影 | Acme'));
+});
diff --git a/tests/js/components/features/manual/ScenarioEditor.test.ts b/tests/js/components/features/manual/ScenarioEditor.test.ts
index fb22e00..88ef1ed 100644
--- a/tests/js/components/features/manual/ScenarioEditor.test.ts
+++ b/tests/js/components/features/manual/ScenarioEditor.test.ts
@@ -119,6 +119,9 @@ beforeEach(() => {
     routerOnMock.mockClear();
     vi.stubGlobal("fetch", fetchMock);
     clearToasts();
+    // jsdom は scrollIntoView 未実装。失敗フィードバックの知覚処理 (showFailure) が
+    // 全失敗経路で呼ぶため、毎テスト新しい spy を注入する (呼び出し順/引数検証にも使う)
+    Element.prototype.scrollIntoView = vi.fn();
 });
 
 afterEach(() => {
@@ -628,4 +631,141 @@ describe("ScenarioEditor", () => {
         render(ScenarioEditor, { props: { ...baseProps, scenario: makeDocument() } });
         expect(screen.getByTestId("scenario-submit")).not.toBeDisabled();
     });
+
+    // --- F-02 知覚可能性 (perceivability) の回帰テスト群 ---
+
+    it("失敗フィードバックは操作点 (シナリオを更新ボタン) の直前に描画される", async () => {
+        fetchMock.mockResolvedValueOnce(
+            jsonResponse(409, {
+                code: "scenario_conflict",
+                conflict_type: "version_mismatch",
+                message: "他の編集と競合しました。",
+                current_version: 9,
+            }),
+        );
+
+        render(ScenarioEditor, { props: { ...baseProps, scenario: makeDocument() } });
+        await fireEvent.click(screen.getByTestId("scenario-submit"));
+
+        await waitFor(() => {
+            expect(screen.getByTestId("scenario-failure-region")).toBeInTheDocument();
+        });
+        const region = screen.getByTestId("scenario-failure-region");
+        const submit = screen.getByTestId("scenario-submit");
+        // region は submit より前方 (DOCUMENT_POSITION_FOLLOWING) かつ同一 section 配下
+        const position = region.compareDocumentPosition(submit);
+        expect(position & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
+        expect(region.closest("section")).toBe(submit.closest("section"));
+    });
+
+    it("失敗表示は focus(preventScroll) → scrollIntoView の順で知覚させる (全 kind 共通)", async () => {
+        const focusSpy = vi.spyOn(HTMLElement.prototype, "focus");
+        const scrollMock = Element.prototype.scrollIntoView as ReturnType<typeof vi.fn>;
+
+        // 3 分岐 (conflict=409 / forbidden=403 / generic=500) それぞれで順序と引数を検証する
+        const cases: Array<{ status: number; body: unknown }> = [
+            {
+                status: 409,
+                body: {
+                    code: "scenario_conflict",
+                    conflict_type: "version_mismatch",
+                    message: "他の編集と競合しました。",
+                    current_version: 9,
+                },
+            },
+            { status: 403, body: {} },
+            { status: 500, body: {} },
+        ];
+
+        for (const { status, body } of cases) {
+            focusSpy.mockClear();
+            scrollMock.mockClear();
+            fetchMock.mockResolvedValueOnce(jsonResponse(status, body));
+
+            const { unmount } = render(ScenarioEditor, {
+                props: { ...baseProps, scenario: makeDocument() },
+            });
+            await fireEvent.click(screen.getByTestId("scenario-submit"));
+
+            await waitFor(() => {
+                expect(scrollMock).toHaveBeenCalledTimes(1);
+            });
+            expect(focusSpy).toHaveBeenCalledWith({ preventScroll: true });
+            expect(scrollMock).toHaveBeenCalledWith({
+                block: "nearest",
+                inline: "nearest",
+                behavior: "auto",
+            });
+            // focus が scrollIntoView より先に呼ばれる
+            const focusOrder = Math.min(...focusSpy.mock.invocationCallOrder);
+            expect(focusOrder).toBeLessThan(scrollMock.mock.invocationCallOrder[0]);
+            unmount();
+        }
+
+        focusSpy.mockRestore();
+    });
+
+    it("409 (analyzing) はサーバ供給 message を表示し再取得 CTA を出さない", async () => {
+        fetchMock.mockResolvedValueOnce(
+            jsonResponse(409, {
+                code: "scenario_conflict",
+                conflict_type: "analyzing",
+                message: "AI 解析中のため保存できません。完了後に再度お試しください。",
+                current_version: 3,
+            }),
+        );
+
+        render(ScenarioEditor, { props: { ...baseProps, scenario: makeDocument() } });
+        await fireEvent.click(screen.getByTestId("scenario-submit"));
+
+        await waitFor(() => {
+            expect(screen.getByTestId("scenario-conflict-banner")).toHaveTextContent(
+                "AI 解析中のため保存できません。完了後に再度お試しください。",
+            );
+        });
+        // version_mismatch 以外はリロード導線を出さない (空 action 余白も出さない)
+        expect(screen.queryByTestId("scenario-conflict-reload")).not.toBeInTheDocument();
+    });
+
+    it("403 は権限エラーの固定文言を表示し作業コピーを破棄しない", async () => {
+        fetchMock.mockResolvedValueOnce(jsonResponse(403, { message: "This action is unauthorized." }));
+
+        render(ScenarioEditor, { props: { ...baseProps, scenario: makeDocument() } });
+        await typeInto("step-0-scene", "手順シーンAX");
+        await fireEvent.click(screen.getByTestId("scenario-submit"));
+
+        await waitFor(() => {
+            expect(screen.getByTestId("scenario-forbidden-error")).toHaveTextContent(
+                "この操作を行う権限がありません。ページを再読み込みして状態を確認してください。",
+            );
+        });
+        // サーバ 403 body の英語文言は表示しない (内部状態を漏らさない)
+        expect(
+            screen.queryByText("This action is unauthorized."),
+        ).not.toBeInTheDocument();
+        // dirty (作業コピー) は保持
+        expect(screen.getByTestId("step-0-scene")).toHaveValue("手順シーンAX");
+        expect(screen.getByTestId("scenario-dirty-indicator")).toBeInTheDocument();
+    });
+
+    it("保存成功で失敗リージョンが消える", async () => {
+        fetchMock
+            .mockResolvedValueOnce(jsonResponse(403, {}))
+            .mockResolvedValueOnce(jsonResponse(200, makeDocument()));
+
+        render(ScenarioEditor, { props: { ...baseProps, scenario: makeDocument() } });
+        await fireEvent.click(screen.getByTestId("scenario-submit"));
+        await waitFor(() => {
+            expect(screen.getByTestId("scenario-failure-region")).toBeInTheDocument();
+        });
+        // 保存完了で submit が再度有効になる (loading 中は disabled=多重送信ガード) のを待つ
+        await waitFor(() => {
+            expect(screen.getByTestId("scenario-submit")).not.toBeDisabled();
+        });
+
+        await fireEvent.click(screen.getByTestId("scenario-submit"));
+        await waitFor(() => {
+            expect(screen.queryByTestId("scenario-failure-region")).not.toBeInTheDocument();
+        });
+    });
 });
```

## テスト結果
- Vitest: `tests/js/components/features/manual/ScenarioEditor.test.ts` 含む全 66 files / 459 tests passed (新規 5 ケース: 失敗リージョンの操作点直近描画・focus→scrollIntoView 順序と引数(3 kind)・409 analyzing の CTA 抑止・403 固定文言と作業コピー保持・保存成功でリージョン消滅)。
- Pest: `ManualPageTitleTest` 5 tests / 33 assertions passed。回帰確認として `Seo|Manual|Capture|Scenario` フィルタ 395 passed / 1 skipped。
- PHPStan level 10: No errors。Pint / eslint / tsc / build: すべて green。

全体判定 (APPROVED / CHANGES_REQUESTED) を出してください。
