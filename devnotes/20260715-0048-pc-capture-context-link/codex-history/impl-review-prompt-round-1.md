# 実装レビュー依頼: T054 pc-capture-context-link

## アプリの使命 (North Star)

**AI-CUE** は、現場に既にある作業手順書(SOP)を起点に、AI が撮るべきカットを設計した動画シナリオを生成し、そのシナリオをスマホ(PWA)でナビゲーション撮影することで、専門知識ゼロの現場作業者でも標準化されたマニュアル動画を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、標準作業を起点に AI が教材設計し撮影を指示する。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。
- v1 スコープ: 字幕のみ / 撮影は PWA(同一オリジン・セッション認証) / 単一 Default Project。

## 禁止事項（自分・実装双方に適用）

1. テストなしの実装完了報告
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作
4. `response()->json()` の直書き（DTO / JsonResource / Inertia を使う）
5. LLM 呼び出しの Prism 直呼び
6. prompt 文字列のコード直書き
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI（押下時にエラー表示する。DESIGN.md）

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。
データに真摯に向き合え。先人の知恵を探せ。機能の名前に立ち返れ。仕組みが機能していない段階で値を弄るな。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

## system: レビュアーの役割

あなたは Laravel + Svelte アプリのコードレビュアーです。以下の改善実装をレビューしてください。

レビュー観点:
- 設計との一致性（詳細設計書どおりに実装されているか）
- 正確性（ロジックの誤り、エッジケース）
- テスト網羅性
- セキュリティ（認可・IDOR・情報漏洩）
- **DESIGN.md準拠**: color / radius / typography は token 経由で参照し hex 直書きを増やさない
- **Atomic Design準拠**: `resources/js/components/` は `atoms/molecules/organisms/features/templates/pages` の単方向 import。pages から atom(`Button`) を使うのは順方向で OK。アイコンは Lucide を使い SVG 直書きを増やさない

出力形式: ファイルごとに判定し、Critical / Warning / Suggestion に分類。最後に全体判定を **APPROVED** または **CHANGES_REQUESTED** で明示すること。

本改善はサーバ変更なし（純フロント: Svelte 5 runes + TypeScript + vitest）。PHP / DTO / route 変更はありません。

---

## user: 詳細設計書（要約）

PC の編集面(`Manuals/Edit.svelte`)・詳細面(`Manuals/Show.svelte`)から、該当マニュアルのスマホ撮影ナビ面(`/app/projects/${project.id}/manuals/${manual.id}` = capture.manuals.show)へ文脈リンクを追加する。

施策:
1. `resources/js/types/manual.ts` に撮影可否 predicate `isCaptureNavigable(status)` + 型付き網羅マップ `CAPTURE_NAVIGABLE_BY_STATUS` を追加（`satisfies Record<VideoManualStatus, boolean>`）。ready/published のみ true（撮影ナビ一覧 CaptureManualController::index の列挙と一致。draft/analyzing/rendering はシナリオ未確定でナビ画面が空になるため導線を出さない）。
2. Show 画面に「この手順書を撮影する」リンク（`Button variant="primary"` + `href` + `inertia` + `Camera` アイコン、testId=`capture-manual-link`）。表示条件は `isCaptureNavigable(manual.status)`。撮影者(canManage=false)にも表示。action コンテナは撮影導線か管理系のいずれかが出るときだけ描画（空 div を残さない）。
3. Edit 画面に同リンクをヘッダ側に追加（保存アクションと視覚分離）。
4. predicate 単体テスト（新規 `tests/js/types/manual.test.ts`）。
5. Show/Edit コンポーネントテスト拡充（表示条件・権限非依存・href 検証）。

表示条件は `isCaptureNavigable` が true のときのみ描画（false は非表示。disabled ボタンにしない = 禁止事項 #8 準拠）。

## user: 実装差分（git diff）

```diff
diff --git a/resources/js/pages/Manuals/Edit.svelte b/resources/js/pages/Manuals/Edit.svelte
index ac44010..2116450 100644
--- a/resources/js/pages/Manuals/Edit.svelte
+++ b/resources/js/pages/Manuals/Edit.svelte
@@ -1,5 +1,6 @@
 <script lang="ts">
     import { page, useForm } from "@inertiajs/svelte";
+    import { Camera } from "@lucide/svelte";
     import Button from "@/components/atoms/Button.svelte";
     import Card from "@/components/atoms/Card.svelte";
     import Input from "@/components/atoms/Input.svelte";
@@ -9,6 +10,7 @@
     import AppLayout from "@/components/templates/AppLayout.svelte";
     import type { SharedProps } from "@/lib/shared-props";
     import type { CategoryOption, ScenarioDocument, VideoManualStatus } from "@/types/manual";
+    import { isCaptureNavigable } from "@/types/manual";
 
     /**
      * 動画マニュアルの編集 (基本情報 + シナリオ)。
@@ -34,6 +36,9 @@
     const shared = $derived(page.props as unknown as SharedProps);
     const appName = $derived(shared.appName ?? "");
 
+    // 撮影ナビ (capture.manuals.show) への文脈リンクを出してよい状態か
+    const captureNavigable = $derived(isCaptureNavigable(manual.status));
+
     const form = useForm({
         title: manual.title,
         category: manual.category === null ? "" : String(manual.category),
@@ -49,10 +54,25 @@
 </script>
 
 <AppLayout {appName}>
-    <h1 class="text-h2">動画マニュアルの編集</h1>
-    <p class="mt-1 text-caption text-text-secondary">
-        基本情報とシナリオ (撮影台本) を編集できます。
-    </p>
+    <div class="flex items-start justify-between gap-4">
+        <div class="min-w-0">
+            <h1 class="text-h2">動画マニュアルの編集</h1>
+            <p class="mt-1 text-caption text-text-secondary">
+                基本情報とシナリオ (撮影台本) を編集できます。
+            </p>
+        </div>
+        {#if captureNavigable}
+            <Button
+                variant="primary"
+                href={`/app/projects/${project.id}/manuals/${manual.id}`}
+                inertia
+                testId="capture-manual-link"
+            >
+                <Camera class="size-4" aria-hidden="true" />
+                この手順書を撮影する
+            </Button>
+        {/if}
+    </div>
 
     <div class="mt-6 max-w-2xl">
         <Card padding="lg">
diff --git a/resources/js/pages/Manuals/Show.svelte b/resources/js/pages/Manuals/Show.svelte
index 5db52ee..2384496 100644
--- a/resources/js/pages/Manuals/Show.svelte
+++ b/resources/js/pages/Manuals/Show.svelte
@@ -1,5 +1,6 @@
 <script lang="ts">
     import { page, router } from "@inertiajs/svelte";
+    import { Camera } from "@lucide/svelte";
     import Badge from "@/components/atoms/Badge.svelte";
     import Button from "@/components/atoms/Button.svelte";
     import Card from "@/components/atoms/Card.svelte";
@@ -13,7 +14,7 @@
     import AppLayout from "@/components/templates/AppLayout.svelte";
     import type { SharedProps } from "@/lib/shared-props";
     import type { AnalysisProps, CategoryOption, RenderProps, VideoManualStatus } from "@/types/manual";
-    import { STATUS_TONES, VIDEO_MANUAL_STATUS_LABELS } from "@/types/manual";
+    import { STATUS_TONES, VIDEO_MANUAL_STATUS_LABELS, isCaptureNavigable } from "@/types/manual";
 
     /**
      * 動画マニュアル詳細 (メタデータ + AI 解析パネル)。撮影者も閲覧可
@@ -39,6 +40,9 @@
     const shared = $derived(page.props as unknown as SharedProps);
     const appName = $derived(shared.appName ?? "");
 
+    // 撮影ナビ (capture.manuals.show) への文脈リンクを出してよい状態か
+    const captureNavigable = $derived(isCaptureNavigable(manual.status));
+
     /* ---- 複製 (別名保存) ---- */
     let duplicateDialogOpen = $state(false);
 
@@ -76,23 +80,38 @@
                 <span class="text-caption text-text-secondary">{manual.created_at}</span>
             </div>
         </div>
-        {#if canManage}
+        <!-- action コンテナは撮影導線か管理系のいずれかが出るときだけ描画 (空 div を残さない) -->
+        {#if captureNavigable || canManage}
             <div class="flex items-center gap-2">
-                <Button
-                    variant="ghost"
-                    onclick={() => (duplicateDialogOpen = true)}
-                    testId="duplicate-manual-button"
-                >
-                    複製
-                </Button>
-                <Button
-                    variant="ghost"
-                    href={`/projects/${project.id}/manuals/${manual.id}/edit`}
-                    inertia
-                    testId="edit-manual-button"
-                >
-                    編集
-                </Button>
+                {#if captureNavigable}
+                    <!-- canManage 内外を問わず表示 (撮影者=project_member も撮影ナビ view 可) -->
+                    <Button
+                        variant="primary"
+                        href={`/app/projects/${project.id}/manuals/${manual.id}`}
+                        inertia
+                        testId="capture-manual-link"
+                    >
+                        <Camera class="size-4" aria-hidden="true" />
+                        この手順書を撮影する
+                    </Button>
+                {/if}
+                {#if canManage}
+                    <Button
+                        variant="ghost"
+                        onclick={() => (duplicateDialogOpen = true)}
+                        testId="duplicate-manual-button"
+                    >
+                        複製
+                    </Button>
+                    <Button
+                        variant="ghost"
+                        href={`/projects/${project.id}/manuals/${manual.id}/edit`}
+                        inertia
+                        testId="edit-manual-button"
+                    >
+                        編集
+                    </Button>
+                {/if}
             </div>
         {/if}
     </div>
diff --git a/resources/js/types/manual.ts b/resources/js/types/manual.ts
index 2199a63..0f86500 100644
--- a/resources/js/types/manual.ts
+++ b/resources/js/types/manual.ts
@@ -30,6 +30,25 @@ export const STATUS_TONES = {
     published: "primary",
 } as const satisfies Record<VideoManualStatus, BadgeTone>;
 
+/**
+ * 撮影ナビ (capture.manuals.show) へ導線を出してよい状態か。
+ * 撮影ナビ一覧 (CaptureManualController::index) が列挙する ready/published と一致させる
+ * (draft/analyzing/rendering はシナリオ未確定でナビ画面が空になるため導線を出さない)。
+ * satisfies で status 追加時のキー漏れをコンパイル時検出する (STATUS_TONES と同方針)。
+ */
+export const CAPTURE_NAVIGABLE_BY_STATUS = {
+    draft: false,
+    analyzing: false,
+    ready: true,
+    rendering: false,
+    published: true,
+} as const satisfies Record<VideoManualStatus, boolean>;
+
+/** PC 編集/詳細から撮影ナビへ導線を出してよいか (型付き判定の単一ソース) */
+export function isCaptureNavigable(status: VideoManualStatus): boolean {
+    return CAPTURE_NAVIGABLE_BY_STATUS[status];
+}
+
 export interface PaginationMeta {
     current_page: number;
     last_page: number;
diff --git a/tests/js/pages/ManualsEdit.test.ts b/tests/js/pages/ManualsEdit.test.ts
index 3d39165..c5f23fc 100644
--- a/tests/js/pages/ManualsEdit.test.ts
+++ b/tests/js/pages/ManualsEdit.test.ts
@@ -57,4 +57,22 @@ describe("Manuals/Edit", () => {
         expect(screen.getByTestId("manual-submit")).not.toBeDisabled();
         expect(screen.getByTestId("scenario-submit")).not.toBeDisabled();
     });
+
+    it("published 状態では撮影ナビへの導線を表示し href が撮影ナビを厳密に指す", () => {
+        render(Edit, {
+            props: { ...baseProps, manual: { ...baseProps.manual, status: "published" as const } },
+        });
+
+        // Inertia Link は jsdom で origin 付き絶対 URL に解決される。
+        // path 全体を start/end 固定で照合し prefix / suffix / クエリ変化を検知する。
+        expect(screen.getByTestId("capture-manual-link").getAttribute("href")).toMatch(
+            /^https?:\/\/[^/]+\/app\/projects\/1\/manuals\/5$/,
+        );
+    });
+
+    it("draft 状態では撮影導線を表示しない", () => {
+        render(Edit, { props: baseProps });
+
+        expect(screen.queryByTestId("capture-manual-link")).toBeNull();
+    });
 });
diff --git a/tests/js/pages/ManualsShow.test.ts b/tests/js/pages/ManualsShow.test.ts
index dc77d6b..887e99c 100644
--- a/tests/js/pages/ManualsShow.test.ts
+++ b/tests/js/pages/ManualsShow.test.ts
@@ -87,6 +87,44 @@ describe("Manuals/Show", () => {
         expect(screen.queryByTestId("source-document-upload")).toBeNull();
     });
 
+    it("ready 状態では撮影ナビへの導線を表示し href が撮影ナビを厳密に指す", () => {
+        render(Show, {
+            props: { ...baseProps, manual: { ...baseProps.manual, status: "ready" } },
+        });
+
+        // Inertia Link は jsdom で origin 付き絶対 URL に解決される。
+        // path 全体を start/end 固定で照合し prefix / suffix / クエリ変化を検知する。
+        expect(screen.getByTestId("capture-manual-link").getAttribute("href")).toMatch(
+            /^https?:\/\/[^/]+\/app\/projects\/1\/manuals\/5$/,
+        );
+    });
+
+    it("published 状態でも撮影導線を表示する", () => {
+        render(Show, {
+            props: { ...baseProps, manual: { ...baseProps.manual, status: "published" } },
+        });
+
+        expect(screen.getByTestId("capture-manual-link")).toBeInTheDocument();
+    });
+
+    it("ready 状態なら canManage=false (撮影者) でも撮影導線を表示する", () => {
+        render(Show, {
+            props: {
+                ...baseProps,
+                canManage: false,
+                manual: { ...baseProps.manual, status: "ready" },
+            },
+        });
+
+        expect(screen.getByTestId("capture-manual-link")).toBeInTheDocument();
+    });
+
+    it("draft 状態では撮影導線を表示しない", () => {
+        render(Show, { props: baseProps });
+
+        expect(screen.queryByTestId("capture-manual-link")).toBeNull();
+    });
+
     it("analyzing 中は進捗を表示し、アップロード導線は出さない (draft/ready のみ)", () => {
         render(Show, {
             props: {
diff --git a/tests/js/types/manual.test.ts b/tests/js/types/manual.test.ts
new file mode 100644
index 0000000..13dd418
--- /dev/null
+++ b/tests/js/types/manual.test.ts
@@ -0,0 +1,28 @@
+import { describe, expect, it } from "vitest";
+import {
+    CAPTURE_NAVIGABLE_BY_STATUS,
+    isCaptureNavigable,
+    type VideoManualStatus,
+} from "@/types/manual";
+
+describe("isCaptureNavigable", () => {
+    it.each<[VideoManualStatus, boolean]>([
+        ["draft", false],
+        ["analyzing", false],
+        ["ready", true],
+        ["rendering", false],
+        ["published", true],
+    ])("%s -> %s", (status, expected) => {
+        expect(isCaptureNavigable(status)).toBe(expected);
+    });
+
+    it("全 VideoManualStatus のキーを持つ", () => {
+        expect(Object.keys(CAPTURE_NAVIGABLE_BY_STATUS).sort()).toEqual([
+            "analyzing",
+            "draft",
+            "published",
+            "ready",
+            "rendering",
+        ]);
+    });
+});
```

## user: テスト結果

- `pnpm typecheck`: OK
- `pnpm lint`: OK
- `pnpm test --testTimeout=30000`: 662 passed (77 files)、うち新規/拡充分（isCaptureNavigable 6件 / Show 撮影導線 4件 / Edit 撮影導線 2件）全 pass
- `pnpm build`: OK

## user: design system 参照

- リンクは既存 atom `Button`（`resources/js/components/atoms/Button.svelte`、`href` + `inertia` で Inertia `Link` を描画）を pages から利用（順方向 import）。
- アイコンは `@lucide/svelte` の `Camera`（Dashboard の撮影エントリと同一 iconography、SVG 直書きなし）。
- 遷移先 `/app/projects/${id}/manuals/${id}` は既存 Dashboard.svelte:65 と同一パス方式。
- hex 直書き・token 変更・新規 SVG なし。

上記をレビューし、全体判定を APPROVED / CHANGES_REQUESTED で明示してください。
