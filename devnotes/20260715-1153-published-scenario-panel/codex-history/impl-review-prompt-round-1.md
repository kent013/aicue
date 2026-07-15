# 使命・禁止事項・思考原則・ツール使用制限

## アプリの使命 (North Star)

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。
- v1 スコープ: 字幕のみ / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

## 禁止事項

1. テストなしの実装完了報告
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作をエージェント判断で実行
4. `response()->json()` の直書き（DTO / JsonResource / Inertia を使う）
5. LLM 呼び出しの Prism 直呼び
6. prompt 文字列のコード直書き
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI（押下時にエラー表示する）

## 思考原則 — 全議論に適用

まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

## ツール使用制限

コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

# system: 役割・タスク

あなたはシニアフロントエンドエンジニアとして、TODO **T061** の実装差分を**実装レビュー**する。

- 対象: bug-hunt F-1-03「published マニュアルでシナリオパネルが未作成表示に戻る不具合」の修正。
- 設計: `devnotes/20260715-1153-published-scenario-panel/detailed-design.md`（詳細設計 Round 1 APPROVED 済み）。
- 変更範囲: 純フロント（TypeScript + Svelte 5 runes）。PHP / DTO / PHPStan 影響なし。
- 品質ゲートは全 green 済み: `pnpm typecheck` / `pnpm lint` / `pnpm build` / `pnpm test`（全 750 テスト pass、追加分 40 pass）。

## レビュー観点

1. **設計整合**: detailed-design.md の施策1〜5 と差分が一致しているか。逸脱があれば指摘。
2. **バグ修正の正しさ**: F-1-03 の根本原因（確定相 published/rendering で「未生成」案内が出る）を確実に潰しているか。分岐順序（`isScenarioEstablished` を `!hasDocument` より先に判定）が核心として機能しているか。
3. **禁止事項#8 との抵触**: CTA を rendering/published で非表示にする施策4 が「必須条件未充足を理由に disabled」に当たらないか（設計判断の妥当性）。
4. **型安全**: `satisfies Record<VideoManualStatus, boolean>` によるキー網羅、helper の命名（cuts 実在と表示相の混同回避）。
5. **テスト網羅**: status × document マトリクスが漏れなく固定されているか、assert が主張（未生成案内を出さない）を正確に検証しているか。
6. **回帰リスク**: 既存挙動（ready+document, analyzing 中の CTA 非表示, ポーリング）を壊していないか。

## 出力フォーマット

指摘は **Critical / Warning / Suggestion** に分類。各指摘にファイル・行・理由・推奨対応を記載。
最後に **APPROVED** または **CHANGES_REQUESTED** の verdict を明示。

---

# user: レビュー対象

## 詳細設計（要約）

施策1・2: `resources/js/types/manual.ts` に `SCENARIO_ESTABLISHED_BY_STATUS`/`isScenarioEstablished`（ready/rendering/published=true）と `SCENARIO_ANALYZABLE_BY_STATUS`/`isAnalyzable`（draft/ready=true）を追加（`isCaptureNavigable` の直後、既存パターン踏襲、非破壊）。

施策3: `AnalysisPanel.svelte` 説明文分岐を「確定相優先」に組み替え。`isScenarioEstablished(status)` を最上位に置き、ready は現行文言 byte-identical 維持、rendering/published は「生成済みのシナリオは編集画面で確認できます。」（再解析注記なし）。`!hasDocument` はその後。

施策4: CTA を `canManage && isAnalyzable(status) && !analyzing` に限定（rendering/published で非表示）。draft/ready は SOP 未アップロードでもボタンを出し押下時にサーバエラー表示する既存挙動を維持（禁止#8 遵守）。

施策5: helper 単体テスト（`manual.test.ts`）+ status × document 分岐/CTA テスト（`AnalysisPanel.test.ts`）。

意図的挙動変更: ready+no-document / published+no-document で表示文言を「シナリオ有り」へ統一（確定相優先の副次修正、正しい方向）。

## 実装差分（git diff）

```diff
diff --git a/resources/js/components/features/manual/AnalysisPanel.svelte b/resources/js/components/features/manual/AnalysisPanel.svelte
index fae21db..5c82a31 100644
--- a/resources/js/components/features/manual/AnalysisPanel.svelte
+++ b/resources/js/components/features/manual/AnalysisPanel.svelte
@@ -9,7 +9,7 @@
     import { isInsufficientTickets } from "@/components/features/manual/insufficient-tickets";
     import { csrfToken } from "@/lib/csrf";
     import type { AnalysisJobProps, VideoManualStatus } from "@/types/manual";
-    import { ANALYSIS_STEP_LABELS } from "@/types/manual";
+    import { ANALYSIS_STEP_LABELS, isAnalyzable, isScenarioEstablished } from "@/types/manual";
 
     /**
      * AI 解析パネル (起動・進捗ポーリング・エラー表示)。doc/10 §10.3 / 概念設計 §8。
@@ -251,7 +251,7 @@
 <Card padding="lg">
     <div class="flex items-center justify-between gap-3">
         <h2 class="text-h3">シナリオ</h2>
-        {#if canManage && !analyzing}
+        {#if canManage && isAnalyzable(status) && !analyzing}
             <Button
                 onclick={requestAnalyze}
                 loading={starting}
@@ -311,10 +311,14 @@
             </div>
         {/if}
         <p class="mt-2 text-body text-text-secondary">
-            {#if !hasDocument}
+            {#if isScenarioEstablished(status)}
+                {#if status === "ready"}
+                    手順書から生成したシナリオを編集画面で確認できます。再解析すると既存のシナリオは置き換えられます。
+                {:else}
+                    生成済みのシナリオは編集画面で確認できます。
+                {/if}
+            {:else if !hasDocument}
                 手順書 (SOP) をアップロードすると、AI が撮るべきカットを設計したシナリオを生成します。
-            {:else if status === "ready"}
-                手順書から生成したシナリオを編集画面で確認できます。再解析すると既存のシナリオは置き換えられます。
             {:else}
                 アップロード済みの手順書から AI がシナリオを生成できます。
             {/if}
diff --git a/resources/js/types/manual.ts b/resources/js/types/manual.ts
index 8a8b446..5be606c 100644
--- a/resources/js/types/manual.ts
+++ b/resources/js/types/manual.ts
@@ -49,6 +49,46 @@ export function isCaptureNavigable(status: VideoManualStatus): boolean {
     return CAPTURE_NAVIGABLE_BY_STATUS[status];
 }
 
+/**
+ * シナリオが確定した「表示相」か (ready 以降)。
+ * status がシナリオ確定相 (ready / rendering / published) かを表す **UI 表示判定** であり、
+ * cuts の実在判定ではない (複製直後の draft+cuts はここでは false = 別症状)。
+ * これにより確定相で「未生成」案内を出さない。
+ * 注: CAPTURE_NAVIGABLE_BY_STATUS (撮影ナビ導線, rendering=false) とは別概念なので統合しない。
+ * satisfies で status 追加時のキー漏れをコンパイル時検出する。
+ */
+export const SCENARIO_ESTABLISHED_BY_STATUS = {
+    draft: false,
+    analyzing: false,
+    ready: true,
+    rendering: true,
+    published: true,
+} as const satisfies Record<VideoManualStatus, boolean>;
+
+/** status がシナリオ確定相 (ready 以降) の表示相か (型付き判定の単一ソース) */
+export function isScenarioEstablished(status: VideoManualStatus): boolean {
+    return SCENARIO_ESTABLISHED_BY_STATUS[status];
+}
+
+/**
+ * AI 解析操作を適用できる状態か (サーバ AnalysisJobService の許可集合 = draft / ready と一致)。
+ * これは **解析操作の適用可能状態** の判定であり、rendering / published / analyzing は
+ * status_not_analyzable (409) となるため false。AI 解析ボタン (CTA) の表示可否に使う。
+ * satisfies で status 追加時のキー漏れをコンパイル時検出する。
+ */
+export const SCENARIO_ANALYZABLE_BY_STATUS = {
+    draft: true,
+    analyzing: false,
+    ready: true,
+    rendering: false,
+    published: false,
+} as const satisfies Record<VideoManualStatus, boolean>;
+
+/** AI 解析操作を適用できる状態か (draft / ready。型付き判定の単一ソース) */
+export function isAnalyzable(status: VideoManualStatus): boolean {
+    return SCENARIO_ANALYZABLE_BY_STATUS[status];
+}
+
 export interface PaginationMeta {
     current_page: number;
     last_page: number;
diff --git a/tests/js/components/features/manual/AnalysisPanel.test.ts b/tests/js/components/features/manual/AnalysisPanel.test.ts
index b07fe6f..cf358fc 100644
--- a/tests/js/components/features/manual/AnalysisPanel.test.ts
+++ b/tests/js/components/features/manual/AnalysisPanel.test.ts
@@ -409,4 +409,84 @@ describe("AnalysisPanel", () => {
             expect(screen.getByTestId("reanalyze-dialog")).toBeInTheDocument();
         });
     });
+
+    describe("シナリオ説明文の status × document 分岐 (F-1-03)", () => {
+        it("draft + document なし: 未アップロード案内を表示し、解析ボタンも表示", () => {
+            render(AnalysisPanel, {
+                props: { ...baseProps, manualStatus: "draft" as const, hasDocument: false },
+            });
+            expect(
+                screen.getByText(/手順書 \(SOP\) をアップロードすると/),
+            ).toBeInTheDocument();
+            expect(screen.getByTestId("analyze-button")).toBeInTheDocument();
+        });
+
+        it("draft + document あり: 生成可能案内を表示し、解析ボタンも表示", () => {
+            render(AnalysisPanel, {
+                props: { ...baseProps, manualStatus: "draft" as const, hasDocument: true },
+            });
+            expect(
+                screen.getByText(/アップロード済みの手順書から AI がシナリオを生成できます/),
+            ).toBeInTheDocument();
+            expect(screen.getByTestId("analyze-button")).toBeInTheDocument();
+        });
+
+        it("ready + document あり: シナリオ確認 + 再解析注記を表示し、解析ボタンも表示 (既存不変)", () => {
+            render(AnalysisPanel, {
+                props: { ...baseProps, manualStatus: "ready" as const, hasDocument: true },
+            });
+            expect(
+                screen.getByText(/手順書から生成したシナリオを編集画面で確認できます。再解析すると/),
+            ).toBeInTheDocument();
+            expect(screen.getByTestId("analyze-button")).toBeInTheDocument();
+        });
+
+        it("ready + document なし: 確定相優先でシナリオ有り文言を表示 (未生成案内は出さない)", () => {
+            render(AnalysisPanel, {
+                props: { ...baseProps, manualStatus: "ready" as const, hasDocument: false },
+            });
+            expect(screen.getByText(/編集画面で確認できます/)).toBeInTheDocument();
+            expect(screen.queryByText(/アップロードすると/)).toBeNull();
+            expect(screen.queryByText(/シナリオを生成できます/)).toBeNull();
+            // ready は解析可能状態なのでボタンは表示
+            expect(screen.getByTestId("analyze-button")).toBeInTheDocument();
+        });
+
+        it("rendering + document あり: 生成済み文言を表示し、解析ボタンは非表示", () => {
+            render(AnalysisPanel, {
+                props: { ...baseProps, manualStatus: "rendering" as const, hasDocument: true },
+            });
+            expect(
+                screen.getByText(/生成済みのシナリオは編集画面で確認できます/),
+            ).toBeInTheDocument();
+            expect(screen.queryByText(/アップロードすると/)).toBeNull();
+            expect(screen.queryByText(/シナリオを生成できます/)).toBeNull();
+            expect(screen.queryByTestId("analyze-button")).toBeNull();
+        });
+
+        it("published + document あり: 生成済み文言を表示し、解析ボタンは非表示 (F-1-03)", () => {
+            render(AnalysisPanel, {
+                props: { ...baseProps, manualStatus: "published" as const, hasDocument: true },
+            });
+            expect(
+                screen.getByText(/生成済みのシナリオは編集画面で確認できます/),
+            ).toBeInTheDocument();
+            expect(screen.queryByText(/手順書 \(SOP\) をアップロードすると/)).toBeNull();
+            expect(
+                screen.queryByText(/アップロード済みの手順書から AI がシナリオを生成できます/),
+            ).toBeNull();
+            expect(screen.queryByTestId("analyze-button")).toBeNull();
+        });
+
+        it("published + document なし: 確定相優先でシナリオ有り文言を表示し、解析ボタンは非表示 (再発耐性)", () => {
+            render(AnalysisPanel, {
+                props: { ...baseProps, manualStatus: "published" as const, hasDocument: false },
+            });
+            expect(
+                screen.getByText(/生成済みのシナリオは編集画面で確認できます/),
+            ).toBeInTheDocument();
+            expect(screen.queryByText(/手順書 \(SOP\) をアップロードすると/)).toBeNull();
+            expect(screen.queryByTestId("analyze-button")).toBeNull();
+        });
+    });
 });
diff --git a/tests/js/types/manual.test.ts b/tests/js/types/manual.test.ts
index 13dd418..d163672 100644
--- a/tests/js/types/manual.test.ts
+++ b/tests/js/types/manual.test.ts
@@ -1,7 +1,11 @@
 import { describe, expect, it } from "vitest";
 import {
     CAPTURE_NAVIGABLE_BY_STATUS,
+    isAnalyzable,
     isCaptureNavigable,
+    isScenarioEstablished,
+    SCENARIO_ANALYZABLE_BY_STATUS,
+    SCENARIO_ESTABLISHED_BY_STATUS,
     type VideoManualStatus,
 } from "@/types/manual";
 
@@ -26,3 +30,47 @@ describe("isCaptureNavigable", () => {
         ]);
     });
 });
+
+describe("isScenarioEstablished", () => {
+    it.each<[VideoManualStatus, boolean]>([
+        ["draft", false],
+        ["analyzing", false],
+        ["ready", true],
+        ["rendering", true],
+        ["published", true],
+    ])("%s -> %s", (status, expected) => {
+        expect(isScenarioEstablished(status)).toBe(expected);
+    });
+
+    it("全 VideoManualStatus のキーを持つ", () => {
+        expect(Object.keys(SCENARIO_ESTABLISHED_BY_STATUS).sort()).toEqual([
+            "analyzing",
+            "draft",
+            "published",
+            "ready",
+            "rendering",
+        ]);
+    });
+});
+
+describe("isAnalyzable", () => {
+    it.each<[VideoManualStatus, boolean]>([
+        ["draft", true],
+        ["analyzing", false],
+        ["ready", true],
+        ["rendering", false],
+        ["published", false],
+    ])("%s -> %s", (status, expected) => {
+        expect(isAnalyzable(status)).toBe(expected);
+    });
+
+    it("全 VideoManualStatus のキーを持つ", () => {
+        expect(Object.keys(SCENARIO_ANALYZABLE_BY_STATUS).sort()).toEqual([
+            "analyzing",
+            "draft",
+            "published",
+            "ready",
+            "rendering",
+        ]);
+    });
+});

```
