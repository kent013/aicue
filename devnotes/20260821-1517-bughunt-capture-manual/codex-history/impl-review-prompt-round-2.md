## Round 1 指摘への対応 (対応マトリクスは codex-history に保存済み)

Round 1 の Warning 3 件・Suggestion 2 件すべてに対応した。Critical は無かった。
以下の差分と、強化した Phase A 調査記録をレビューし、全体判定を更新してほしい。

### 対応の要点
1. [Warning] SourceDocumentSummaryPropsTest: 別組織 SOP sentinel が「現在閲覧中 manual の props に
   混ざらない」positive test を追加 (組織 B owner が組織 B 自身の manual を閲覧し、組織 A の sentinel が
   出ないことを assert)。既存の cross-org 404 は独立 test として残した。
2. [Warning] CaptureShow.test.ts の Phase A 回帰: router の全 programmatic 入口 (reload/visit/get/post) を
   1 本の collector に集約。通常フローで collector が最低 1 件 (現 URL reload) を捕まえること (母集団非空) を
   assert。負のコントロールは**実 mock 入口へ禁止 destination を注入し mock→collect→判定の配線ごと**検出する
   形に変更 (純関数直呼びを廃止)。正例 (/app/... は外部に載らない) も併置。実 <Link>/form helper は
   観測点外である旨と、その唯一の /app 外 destination が意図的 PC 詳細リンク (T155) である旨を docblock に明記。
3. [Warning] Phase A ネットワーク観測記録: phase-a-investigation.md を強化。調査手段=静的走査であり
   live Playwright 観測は本セッション未実施であることを明示し、観測分類基準 (409 / Inertia::location /
   window.location・ハーネス / resourceType / X-Inertia-Location 実値) を枠組みとして記載。結論を分岐 (c)
   非再現・原因未確定と明示し**ハーネス起因とは断定しない**。施策5 スキップ根拠を静的走査事実
   (window.location 系 0 件・router.visit/get 不在) に紐づけた。
4. [Suggestion] Pest: uploadedAt を既知 created_at の toIso8601String() と完全一致で assert。
5. [Suggestion] ManualsShow.test.ts: formatDateTime の既知出力を含むことを assert に追加。

### テスト結果 (対応後)
- SourceDocumentSummaryPropsTest: 8 passed。CaptureShow.test.ts + ManualsShow.test.ts: 72 passed。
- 直前の全 composer test: 6390 tests / 6388 passed / 0 failed / 2 skipped (Codex 対応前の状態で緑)。
  対応は test の追加・強化のみで本体コードは不変のため退行なし。pint/lint/typecheck/build 全 green。

## 強化後の Phase A 調査記録 (phase-a-investigation.md 全文)
# F-1-02 Phase A 調査結果 (施策4)

## 目的
撮影 PWA (Capture/Show) の**アプリ自コード**が `/app/` 外への programmatic Inertia visit を
起こすかを確定し、施策5 (Phase B 恒久ガード) の実施可否を判断する。

## 調査手段と、その範囲・限界 (誇張しない)
本実装セッションで行った調査は **アプリソースの静的走査** と **サーバ描画経路の確認** である。

- **実施した**: capture 関連ディレクトリ全体の programmatic navigation 走査 (下表)、
  `CaptureManualController::show` が render のみで redirect を持たないことの確認
  (既存 `CaptureManualBrowsingTest` が 200 render を固定済み)。
- **実施していない**: 隔離 bughunt 環境 (`:8010` 等) を provision した上での Playwright
  ハーネス実走行によるネットワーク実観測。本セッションでは bughunt 環境を立てていない。
  よって下記「分類基準」は**観測を判定するための枠組み**として記すが、live 観測値は取っていない。

このため本調査の結論は**静的走査を根拠とする範囲**に限定され、動的に組み立てた遷移
(実行時に文字列生成する `window.location` 代入等) までを排除したとは主張しない。ただし
下記走査のとおり、そもそも `window.location` 系 API・`router.visit/get` を capture コードは
一切呼んでおらず、動的生成の起点自体が存在しない。

## 静的一次調査 (grep によるアプリコード走査)
`resources/js/pages/Capture/`, `resources/js/lib/capture/`,
`resources/js/components/features/capture/` を対象に programmatic navigation を走査した:

| 呼出 | 箇所 | destination | 分類 |
|------|------|-------------|------|
| `router.reload({only:['manual']})` | `Capture/Show.svelte:132` (`reloadManual`) | 現 URL (url 引数なし) | 現 URL 部分リロード (許可) |
| `router.get('/app/projects/{id}/manuals', ...)` | `Capture/Index.svelte:52` | `/app/...` | in-app (許可)。Show ではない |
| `router.post(...)` | `Capture/Account.svelte:49` | ログアウト | 明示的な認証離脱 (意図)。Show ではない |
| `<Link href="/app/projects/{id}/manuals">` | `Capture/Show.svelte:482` | `/app/...` | in-app (許可) |
| `<Link href="/projects/{id}/manuals/{id}">` | `Capture/Show.svelte:489` | `/projects/...` (=/app 外) | **利用者クリックの明示リンク** (PC 詳細への復路 = T155。`docs/architecture.md §撮影 PWA の運用契約`) |

- `window.location` / `location.assign` / `location.replace` / `Inertia::location()` は
  capture コードに**存在しない** (grep で 0 件)。

## 遷移種別の分類基準 (観測時に区別すべき枠組み。設計 施策4 の調査手順)
live 観測を行う場合に、以下を**必ず区別して記録する**ための枠組みを定義しておく
(本セッションでは live 観測値は未取得):

1. **アセット version 不一致による `409`**: 現在 URL のハードリロード。
2. **アプリが明示する `Inertia::location()`**: `X-Inertia-Location` ヘッダ**実値**の URL への
   ハードビジット。
3. **`window.location` / ハーネス操作**: Inertia 外の document navigation。
4. 記録手段は Playwright ハーネスで取れる範囲 (request の `resourceType` = document/xhr/fetch、
   URL、response の `X-Inertia` / `X-Inertia-Location` ヘッダ) に限定し、ステータスコードだけでなく
   `X-Inertia-Location` の実値を残す。`beforeunload` は補助観測に格下げ。

## 結論 (設計の 3 分岐のうち (c))
**静的走査の範囲では「Capture/Show が自ら起こす `/app/` 外への programmatic Inertia visit」を
確認できなかった。** 唯一の `/app/` 外遷移は利用者がクリックする明示 Inertia `<Link>`
(PC 詳細への復路。運用契約で意図済み = T155) である。

- bug-hunt が観測した `/app/` 離脱の候補は、(i) この意図的な明示リンク、または
  (ii) ハードビジット (409 アセット version 不一致 / 認証失効 302→`X-Inertia-Location` /
  ブラウザ back/forward) が残るが、**live 観測をしていないため発生源は未確定**である。
- 設計の 3 分岐では **(c) どちらも観測できない = アプリ起因を再現できず原因未確定** に該当する。
  **ハーネス起因 (二重 fan-out) とは断定しない** (分岐 (b) を主張する時系列対応データを取っていない)。

## 帰結
- **施策5 (Phase B 恒久ガード = navigation-guard.ts) は実装しない。** 静的走査でアプリ自コードの
  /app 外 programmatic visit が存在せず (再現できず)、単一事象へ包括ガードを足すのは過大
  (AGENTS.md 思考原則 2 / 設計 Codex Round2 総括)。設計の risk 節も「非再現時は結論として
  記録し回帰テストを恒久的に残す」を許容している。
- 施策4 の回帰テストは恒久的に残す:
  `tests/js/pages/CaptureShow.test.ts` の describe「Capture/Show の /app 離脱防止 (F-1-02)」。
  - router の programmatic 入口 (reload/visit/get/post) を 1 本の collector に集約して観測する。
  - 通常フロー (キュー再開→reload) で collector が最低 1 件 (現 URL reload) を捕まえる (母集団非空)。
  - reload は url を持たない (現 URL 固定)。`/app/` 外への programmatic visit は 0 件。
  - **負のコントロールは実 mock 入口 (`router.visit/get/post`) へ禁止 destination を注入し、
    mock→collect→判定の配線ごと検出する** (判定用純関数の直呼びにしない)。
  - 観測点の保証範囲は同 describe の docblock が明示する (実 `<Link>` / form helper は観測点外で、
    その唯一の /app 外 destination は意図的な PC 詳細リンク = T155)。


## 対応差分 (git diff。テスト 3 ファイル)
```diff
diff --git a/tests/Feature/Manual/SourceDocumentSummaryPropsTest.php b/tests/Feature/Manual/SourceDocumentSummaryPropsTest.php
new file mode 100644
index 00000000..233271cf
--- /dev/null
+++ b/tests/Feature/Manual/SourceDocumentSummaryPropsTest.php
@@ -0,0 +1,153 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Models\Organization;
+use App\Models\Project;
+use App\Models\SourceDocument;
+use App\Models\User;
+use App\Models\VideoManual;
+use Illuminate\Testing\TestResponse;
+use Inertia\Testing\AssertableInertia as Assert;
+
+/*
+ * F-1-01b: Manuals/Show の analysis.document (現在登録されている手順書の現況)。
+ * 「最新」の決定規則 (created_at max → tie-break id max) と PII 境界を固定する。
+ */
+
+/**
+ * @return array{Organization, User, Project, VideoManual}
+ */
+function summaryPropsContext(): array
+{
+    [$organization, $owner] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+    $manual = VideoManual::factory()->forProject($project)->create(['status' => 'draft']);
+
+    return [$organization, $owner, $project, $manual];
+}
+
+function showManual(User $actor, Project $project, VideoManual $manual): TestResponse
+{
+    return test()->actingAs($actor)->get("/projects/{$project->id}/manuals/{$manual->id}");
+}
+
+test('show: created_at が異なるとき新しい日時の SOP が document に載る', function (): void {
+    [, $owner, $project, $manual] = summaryPropsContext();
+    SourceDocument::factory()->forManual($manual)->create([
+        'original_name' => 'old.pdf',
+        'created_at' => now()->subDay(),
+    ]);
+    $newer = SourceDocument::factory()->forManual($manual)->create([
+        'original_name' => 'new.pdf',
+        'created_at' => now(),
+    ]);
+
+    showManual($owner, $project, $manual)
+        ->assertOk()
+        ->assertInertia(fn (Assert $page) => $page
+            ->component('Manuals/Show')
+            ->where('analysis.document.name', 'new.pdf')
+            ->where('analysis.hasDocument', true)
+        );
+    expect($newer->original_name)->toBe('new.pdf');
+});
+
+test('show: created_at が同一のとき id が大きい SOP が document に載る', function (): void {
+    [, $owner, $project, $manual] = summaryPropsContext();
+    $sameTime = now();
+    SourceDocument::factory()->forManual($manual)->create([
+        'original_name' => 'first.pdf',
+        'created_at' => $sameTime,
+    ]);
+    SourceDocument::factory()->forManual($manual)->create([
+        'original_name' => 'second.pdf',
+        'created_at' => $sameTime,
+    ]);
+
+    showManual($owner, $project, $manual)
+        ->assertInertia(fn (Assert $page) => $page
+            ->where('analysis.document.name', 'second.pdf')
+        );
+});
+
+test('show: SOP 添付済みなら document に name/sizeBytes/uploadedAt が載る', function (): void {
+    [, $owner, $project, $manual] = summaryPropsContext();
+    $uploadedAt = now()->subHours(3);
+    SourceDocument::factory()->forManual($manual)->create([
+        'original_name' => '作業手順.pdf',
+        'size_bytes' => 12345,
+        'created_at' => $uploadedAt,
+    ]);
+
+    showManual($owner, $project, $manual)
+        ->assertInertia(fn (Assert $page) => $page
+            ->where('analysis.document.name', '作業手順.pdf')
+            ->where('analysis.document.sizeBytes', 12345)
+            // uploadedAt は ISO 8601 (TZ 付き) 固定 = created_at と 1:1 (存在確認だけにしない)
+            ->where('analysis.document.uploadedAt', $uploadedAt->toIso8601String())
+        );
+});
+
+test('show: SOP 未添付なら document=null かつ hasDocument=false', function (): void {
+    [, $owner, $project, $manual] = summaryPropsContext();
+
+    showManual($owner, $project, $manual)
+        ->assertInertia(fn (Assert $page) => $page
+            ->where('analysis.document', null)
+            ->where('analysis.hasDocument', false)
+        );
+});
+
+test('show: hasDocument === (document !== null) が常に成り立つ (添付あり)', function (): void {
+    [, $owner, $project, $manual] = summaryPropsContext();
+    SourceDocument::factory()->forManual($manual)->create();
+
+    $response = showManual($owner, $project, $manual);
+    $response->assertInertia(function (Assert $page): void {
+        $document = $page->toArray()['props']['analysis']['document'] ?? null;
+        $hasDocument = $page->toArray()['props']['analysis']['hasDocument'] ?? null;
+        expect($hasDocument)->toBe($document !== null);
+    });
+});
+
+test('show: 同一組織・別 manual の SOP は当該 manual の analysis.document に出ない', function (): void {
+    [$organization, $owner, $project, $manual] = summaryPropsContext();
+    $otherManual = VideoManual::factory()->forProject($project)->create(['status' => 'draft']);
+    SourceDocument::factory()->forManual($otherManual)->create(['original_name' => 'sentinel-other-manual.pdf']);
+
+    showManual($owner, $project, $manual)
+        ->assertInertia(fn (Assert $page) => $page->where('analysis.document', null));
+});
+
+test('show: 別組織の SOP sentinel が現在閲覧中の manual の props に混ざらない', function (): void {
+    // 組織 A の manual に sentinel SOP を置く
+    [, , , $manualA] = summaryPropsContext();
+    SourceDocument::factory()->forManual($manualA)->create(['original_name' => 'sentinel-cross-org.pdf']);
+
+    // 組織 B の owner が組織 B 自身の manual (SOP 未添付) を閲覧する
+    [$orgB, $ownerB] = createOrganizationWithOwner();
+    $projectB = Project::factory()->forOrganization($orgB)->create();
+    $manualB = VideoManual::factory()->forProject($projectB)->create(['status' => 'draft']);
+
+    // 組織 A の sentinel が組織 B の props へ混入しない (relation 境界の構造的分離)
+    showManual($ownerB, $projectB, $manualB)
+        ->assertOk()
+        ->assertInertia(fn (Assert $page) => $page
+            ->where('analysis.document', null)
+            ->where('analysis.hasDocument', false)
+        );
+});
+
+test('show: 別組織 manual を直接 show すると 404 (本 finding の DTO 追加で退行しない)', function (): void {
+    [, , , $manual] = summaryPropsContext();
+    SourceDocument::factory()->forManual($manual)->create(['original_name' => 'sentinel-cross-org.pdf']);
+
+    [$otherOrg, $otherOwner] = createOrganizationWithOwner();
+    $otherProject = Project::factory()->forOrganization($otherOrg)->create();
+
+    // 別組織 owner が別組織の project 経由で当該 manual を直接 show → cross-org 404
+    test()->actingAs($otherOwner)
+        ->get("/projects/{$otherProject->id}/manuals/{$manual->id}")
+        ->assertNotFound();
+});
diff --git a/tests/js/pages/CaptureShow.test.ts b/tests/js/pages/CaptureShow.test.ts
index 8a44a86d..2629a5d1 100644
--- a/tests/js/pages/CaptureShow.test.ts
+++ b/tests/js/pages/CaptureShow.test.ts
@@ -21,6 +21,9 @@ import {
 
 const {
     routerReloadMock,
+    routerVisitMock,
+    routerGetMock,
+    routerPostMock,
     enqueueMock,
     resumeMock,
     autoDownloadRunMock,
@@ -28,6 +31,11 @@ const {
     pendingSeed,
 } = vi.hoisted(() => ({
     routerReloadMock: vi.fn(),
+    // F-1-02: 撮影 PWA の /app 離脱防止。programmatic な明示遷移入口 (visit/get/post) を
+    // 記録し、通常フローでこれらが /app 外へ向けて呼ばれないことを固定する。
+    routerVisitMock: vi.fn(),
+    routerGetMock: vi.fn(),
+    routerPostMock: vi.fn(),
     enqueueMock: vi.fn(),
     resumeMock: vi.fn(),
     autoDownloadRunMock: vi.fn(),
@@ -48,7 +56,12 @@ vi.mock("@/lib/capture/panel-navigation", async (importOriginal) => ({
 
 vi.mock("@inertiajs/svelte", async (importOriginal) => ({
     ...(await importOriginal<typeof import("@inertiajs/svelte")>()),
-    router: { reload: routerReloadMock },
+    router: {
+        reload: routerReloadMock,
+        visit: routerVisitMock,
+        get: routerGetMock,
+        post: routerPostMock,
+    },
 }));
 
 // jsdom に indexedDB が無いため in-memory PendingStore に差し替える
@@ -180,6 +193,9 @@ beforeEach(() => {
     routerReloadMock.mockImplementation((options: { onFinish?: () => void }) => {
         options.onFinish?.();
     });
+    routerVisitMock.mockReset();
+    routerGetMock.mockReset();
+    routerPostMock.mockReset();
     enqueueMock.mockReset();
     resumeMock.mockReset();
     resumeMock.mockResolvedValue([]);
@@ -650,6 +666,129 @@ describe("Capture/Show サムネイル反映の配線 (T183)", () => {
     });
 });
 
+/*
+ * 撮影 PWA の /app 離脱防止 (bug-hunt F-1-02 Phase A の回帰固定)。
+ *
+ * Phase A 調査 (devnotes/20260821-1517-bughunt-capture-manual/phase-a-investigation.md に記録)
+ * の結論: Capture/Show の**アプリ自コード**が起こす programmatic navigation は
+ * `router.reload({only:['manual']})` (現 URL の部分リロード) のみで、/app 外への
+ * programmatic Inertia visit (router.visit/get/post) は存在しない。/app 外への遷移は
+ * 利用者がクリックする明示リンク (Inertia <Link href="/projects/...">。PC 詳細への復路 = T155。
+ * docs/architecture.md §撮影 PWA の運用契約) だけである。
+ *
+ * よって恒久ガード (施策5 Phase B) は実装せず (再現できないものへ包括ガードを足さない。
+ * AGENTS.md 思考原則 2)、本ブロックが「通常フローで /app 外への programmatic visit が
+ * 発生しない」ことを回帰として固定する。
+ *
+ * **観測点と保証範囲 (誇張しない。AGENTS.md 走査規約 (b))**:
+ * 本ブロックの観測点は router の programmatic 入口 (reload/visit/get/post) を 1 本の
+ * collector (`collectProgrammaticVisits`) に集約したものである。アプリ自コードの
+ * programmatic navigation はこの 4 入口に限られる (Phase A の静的走査で確認)。
+ * - **含む**: `router.reload/visit/get/post` (= アプリが自分で起こす遷移)。
+ * - **含まない**: 実 Inertia `<Link>` クリック / form helper。これらは本テストの mock を
+ *   通らない実 component であり、その唯一の /app 外 destination は意図的な PC 詳細リンク
+ *   (T155。href は capture-manual-title 近傍のリンクとして別途構造で固定) である。
+ *   ここではその利用者主導リンクの「非発生」を主張しない (妨げもしない)。
+ */
+describe("Capture/Show の /app 離脱防止 (F-1-02)", () => {
+    /**
+     * visit の destination が現在オリジンの /app 配下でなければ
+     * 「/app 外 programmatic visit」とみなす判定器 (許可リスト方式)。
+     */
+    function isExternalProgrammaticDestination(url: unknown): boolean {
+        if (typeof url !== "string") return true; // 解析不能は外部側に倒す
+        let parsed: URL;
+        try {
+            parsed = new URL(url, window.location.href);
+        } catch {
+            return true;
+        }
+        if (parsed.origin !== window.location.origin) return true;
+        return !(parsed.pathname === "/app" || parsed.pathname.startsWith("/app/"));
+    }
+
+    /**
+     * router の全 programmatic 入口を 1 本に集約する共通 collector。
+     * reload は url を持たない = 現 URL 固定。visit/get/post は第 1 引数が destination。
+     * この 1 本を「通常フローの検査」も「負のコントロール」も共通に通す
+     * (mock → collect → 判定の配線ごと検証する)。
+     */
+    function collectProgrammaticVisits(): { method: string; url: unknown }[] {
+        return [
+            ...routerReloadMock.mock.calls.map((call) => {
+                const options = (call[0] ?? {}) as { url?: unknown };
+                // reload に url が付いたら現 URL 固定が崩れたとみなし外部側の判定に載せる
+                return { method: "reload", url: options.url };
+            }),
+            ...routerVisitMock.mock.calls.map((call) => ({ method: "visit", url: call[0] })),
+            ...routerGetMock.mock.calls.map((call) => ({ method: "get", url: call[0] })),
+            ...routerPostMock.mock.calls.map((call) => ({ method: "post", url: call[0] })),
+        ];
+    }
+
+    /** collector が集めた記録から /app 外 destination だけを抽出する。 */
+    function externalOf(records: { method: string; url: unknown }[]): {
+        method: string;
+        url: unknown;
+    }[] {
+        // reload は url=undefined が正常 (現 URL)。undefined は外部扱いしない。
+        return records.filter(
+            (r) => r.url !== undefined && isExternalProgrammaticDestination(r.url),
+        );
+    }
+
+    it("通常フロー (キュー再開 → reload) で /app 外への programmatic visit が発生しない", async () => {
+        stubCameraSupported(false);
+        // 母集団非空を保証する: uploaded を返して現 URL への reload を確実に 1 回起こす
+        resumeMock.mockResolvedValue([{ status: "uploaded", clientTakeId: "q1" }]);
+
+        render(CaptureShow, { props: baseProps });
+        await fireEvent(window, new Event("online"));
+
+        // 母集団非空: collector が programmatic 入口を最低 1 件 (現 URL reload) 捕まえている
+        await vi.waitFor(() => {
+            expect(collectProgrammaticVisits().length).toBeGreaterThan(0);
+        });
+
+        const records = collectProgrammaticVisits();
+        // reload は現 URL 固定 (url なし)。visit/get/post 入口は 1 件も呼ばれていない。
+        expect(records.every((r) => r.method === "reload" && r.url === undefined)).toBe(true);
+        // 同じ collector → 判定パイプラインで /app 外は 0 件
+        expect(externalOf(records)).toEqual([]);
+    });
+
+    it("負のコントロール: 禁止 destination を実 mock 入口へ流すと配線が検出する", async () => {
+        stubCameraSupported(false);
+        resumeMock.mockResolvedValue([{ status: "uploaded", clientTakeId: "q1" }]);
+
+        render(CaptureShow, { props: baseProps });
+        await fireEvent(window, new Event("online"));
+        await vi.waitFor(() => {
+            expect(collectProgrammaticVisits().length).toBeGreaterThan(0);
+        });
+
+        // 通常フロー時点では /app 外は 0 件 (母集団は非空)
+        expect(externalOf(collectProgrammaticVisits())).toEqual([]);
+
+        // 実 mock 入口 (router.visit) に禁止 destination を注入し、mock→collect→判定を通す
+        routerVisitMock("/projects/1/manuals/5");
+        routerGetMock("https://evil.example/app/x");
+        routerPostMock("/app.evil/x");
+
+        const external = externalOf(collectProgrammaticVisits());
+        expect(external.map((r) => r.url)).toEqual([
+            "/projects/1/manuals/5",
+            "https://evil.example/app/x",
+            "/app.evil/x",
+        ]);
+        // 現 URL 配下 (/app/...) は許可され外部に載らない (空振り防止の正例)
+        routerGetMock("/app/projects/1/manuals/5");
+        expect(externalOf(collectProgrammaticVisits()).map((r) => r.url)).not.toContain(
+            "/app/projects/1/manuals/5",
+        );
+    });
+});
+
 /*
  * 横持ち全画面撮影の**ページ配線** (T186 施策 D)。
  *
diff --git a/tests/js/pages/ManualsShow.test.ts b/tests/js/pages/ManualsShow.test.ts
index af71f611..1b62a156 100644
--- a/tests/js/pages/ManualsShow.test.ts
+++ b/tests/js/pages/ManualsShow.test.ts
@@ -1,6 +1,7 @@
 import { describe, expect, it } from "vitest";
 import { fireEvent, render, screen, waitFor } from "@testing-library/svelte";
 import Show from "@/pages/Manuals/Show.svelte";
+import { formatDateTime } from "@/lib/date-format";
 import type { VideoManualStatus } from "@/types/manual";
 
 const baseProps = {
@@ -12,7 +13,7 @@ const baseProps = {
         category: { id: 2, name: "仕上げ" },
         created_at: "2026-07-10 12:00",
     },
-    analysis: { job: null, hasDocument: false, report: null },
+    analysis: { job: null, hasDocument: false, document: null, report: null },
     render: {
         job: null,
         previewJob: null,
@@ -146,6 +147,7 @@ describe("Manuals/Show", () => {
                         manual_status: "analyzing" as VideoManualStatus,
                     },
                     hasDocument: true,
+                    document: null,
                     report: null,
                 },
             },
@@ -182,6 +184,64 @@ describe("Manuals/Show", () => {
         expect(screen.getByTestId("scenario-counts")).toHaveTextContent("手順 2");
     });
 
+    // --- F-1-01b: 現在登録されている手順書 (SOP) の現況表示 ---
+
+    it("document 有り: 手順書パネルにファイル名・サイズ・日時が出る", () => {
+        render(Show, {
+            props: {
+                ...baseProps,
+                analysis: {
+                    ...baseProps.analysis,
+                    hasDocument: true,
+                    document: {
+                        name: "作業手順.pdf",
+                        sizeBytes: 1024 * 1024 * 2,
+                        uploadedAt: "2026-07-10T12:00:00+09:00",
+                    },
+                },
+            },
+        });
+
+        expect(screen.getByTestId("source-document-name")).toHaveTextContent("作業手順.pdf");
+        const current = screen.getByTestId("source-document-current");
+        expect(current).toHaveTextContent("2.0 MB");
+        // 日時も formatDateTime の既知出力で固定する (サイズだけの assert にしない)
+        expect(current).toHaveTextContent(formatDateTime("2026-07-10T12:00:00+09:00"));
+        expect(screen.queryByTestId("source-document-empty")).toBeNull();
+    });
+
+    it("document null: 「まだ手順書は登録されていません」を表示し差し替え UI と矛盾しない", () => {
+        render(Show, { props: baseProps });
+
+        expect(screen.getByTestId("source-document-empty")).toHaveTextContent(
+            "まだ手順書は登録されていません",
+        );
+        expect(screen.queryByTestId("source-document-name")).toBeNull();
+    });
+
+    it("filename に <script> を含む document でも HTML として解釈されずテキスト表示される", () => {
+        const evil = "<script>alert(1)</script>.pdf";
+        render(Show, {
+            props: {
+                ...baseProps,
+                analysis: {
+                    ...baseProps.analysis,
+                    hasDocument: true,
+                    document: {
+                        name: evil,
+                        sizeBytes: 100,
+                        uploadedAt: "2026-07-10T12:00:00+09:00",
+                    },
+                },
+            },
+        });
+
+        const nameEl = screen.getByTestId("source-document-name");
+        // Svelte の既定エスケープでテキストとして描画され、実 script 要素は生成されない
+        expect(nameEl.textContent).toBe(evil);
+        expect(nameEl.querySelector("script")).toBeNull();
+    });
+
     // --- T148 (bug-hunt F-1-01): render props の配線 ---
 
     it("D-9: render.coverage と render.playbackJob が RenderPanel へ渡る", () => {

```

以上を踏まえ、施策4 の完了 (空振りしない回帰 + 観測記録) と施策5 スキップ判断の妥当性を再評価し、
全体判定 (APPROVED / CHANGES_REQUESTED) を返してほしい。
