【アプリの使命 (North Star)】

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。
- v1 スコープ: 字幕のみ / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

【禁止事項】

1. テストなしの実装完了報告（不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」）
2. PHPStan エラーの widen（型を緩めて黙らせる）・baseline 化
3. dev DB への破壊操作をエージェント判断で実行すること
4. `response()->json()` の直書き（DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外）
5. LLM 呼び出しの Prism 直呼び
6. prompt 文字列のコード直書き
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。設計そのものを見直せ。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

## system: あなたの役割

あなたは Laravel + Svelte アプリの**実装レビュアー**である。以下の改善実装 (T037: 撮影画面 capture.manuals.show のモバイル/タブレット横 overflow 修正) をレビューせよ。

### レビュー観点

1. **設計との一致性**: 添付の詳細設計書どおりに実装されているか（施策1〜4）。
2. **正確性**: CSS の overflow 是正ロジックが正しいか。`grid-cols-1` (= `repeat(1, minmax(0,1fr))`) による列最小幅クランプ、両 pane の `min-w-0`、`lg:grid-cols-2` によるデスクトップ 2 カラム維持、shooting_point 行の `<p min-w-0>` + `<span min-w-0 flex-1 truncate>` + アイコン `shrink-0` による ellipsis 発火が意図どおりか。デスクトップ表示の後退がないか。
3. **テスト網羅性**: 構造回帰テスト（vitest）が正しく振る舞いを固定しているか。jsdom はレイアウト計算しないため overflow 自体は証明できず「構造回帰」で代替する制約が明記されているか。テストが脆すぎ/緩すぎないか。
4. **DESIGN.md 準拠**: color/radius/typography を token 経由で参照し hex 直書き（`#RRGGBB`）を増やしていないか。今回は Tailwind 標準レイアウトユーティリティ（`grid-cols-1` `min-w-0` `flex-1` `truncate` `shrink-0`）の追加のみで、token 値変更・hex 追加なし。
5. **Atomic Design 準拠**: `resources/js/components/` の atoms/molecules/organisms/features/templates/pages の責務分離と単方向 import に反していないか。アイコンは Lucide (`MapPin` 既存) を使い、SVG 直書きを増やしていないか。今回は features/capture 内の class 変更のみで層構成不変。
6. **セキュリティ・禁止事項**: 上記禁止事項いずれにも抵触しないこと（本施策はフロント class 変更 + vitest のみ）。

### 出力形式

- ファイルごとに判定を述べる。
- 指摘は **[Critical] / [Warning] / [Suggestion]** に分類する。
- 最後に全体判定を **APPROVED** または **CHANGES_REQUESTED** で明示する。

---

## user

### 詳細設計書

@devnotes/20260714-1049-capture-show-responsive/detailed-design.md

（上記ファイルを読むこと。要点: 施策1=Show.svelte のグリッドを `grid-cols-1` 化 + 左右 pane に `min-w-0` + 安定 testid、施策2=CutNavigator の shooting_point を `<span min-w-0 flex-1 truncate>` 構造へ、施策3=CaptureShow.test.ts に overflow ガード describe 追加、施策4=CutNavigator.test.ts 新規で truncate 構造を固定。scene 行は施策1 で親幅が確定すれば既存 `truncate` が復活するため構造変更不要。施策1→2 は同一 PR 必須。）

### 実装差分 (git diff)

```diff
diff --git a/resources/js/components/features/capture/CutNavigator.svelte b/resources/js/components/features/capture/CutNavigator.svelte
index 0d1677c..0821120 100644
--- a/resources/js/components/features/capture/CutNavigator.svelte
+++ b/resources/js/components/features/capture/CutNavigator.svelte
@@ -53,9 +53,9 @@
                     </p>
                     <p class="truncate text-body">{cut.scene}</p>
                     {#if cut.shooting_point}
-                        <p class="flex items-center gap-1 truncate text-caption text-text-secondary">
+                        <p class="flex min-w-0 items-center gap-1 text-caption text-text-secondary">
                             <MapPin class="size-3 shrink-0" aria-hidden="true" />
-                            {cut.shooting_point}
+                            <span class="min-w-0 flex-1 truncate">{cut.shooting_point}</span>
                         </p>
                     {/if}
                 </div>
diff --git a/resources/js/pages/Capture/Show.svelte b/resources/js/pages/Capture/Show.svelte
index 81ee4f6..18212fd 100644
--- a/resources/js/pages/Capture/Show.svelte
+++ b/resources/js/pages/Capture/Show.svelte
@@ -150,8 +150,8 @@
         <UploadQueueBar {pendingCount} {pendingBytes} {uploading} {quotaMessage} onResume={resumeUploads} />
     </div>
 
-    <div class="mt-4 grid gap-4 lg:grid-cols-2">
-        <section class="rounded-md border border-border bg-surface">
+    <div class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-2" data-testid="capture-grid">
+        <section class="min-w-0 rounded-md border border-border bg-surface" data-testid="capture-left-pane">
             <h2 class="border-b border-border px-3 py-2 text-caption text-text-secondary">
                 シナリオ (タップして撮影)
             </h2>
@@ -162,7 +162,7 @@
             />
         </section>
 
-        <section class="flex flex-col gap-4">
+        <section class="flex min-w-0 flex-col gap-4" data-testid="capture-right-pane">
             {#if selectedCut === null}
                 <p class="text-caption text-text-secondary">
                     左のシナリオからカットを選ぶと撮影パネルが開きます。
diff --git a/tests/js/components/features/capture/CutNavigator.test.ts b/tests/js/components/features/capture/CutNavigator.test.ts
new file mode 100644
index 0000000..28bc618
--- /dev/null
+++ b/tests/js/components/features/capture/CutNavigator.test.ts
@@ -0,0 +1,50 @@
+import { afterEach, describe, expect, it, vi } from "vitest";
+import { cleanup, render, screen } from "@testing-library/svelte";
+import CutNavigator from "@/components/features/capture/CutNavigator.svelte";
+import type { CaptureCut } from "@/types/capture";
+
+function makeCut(overrides: Partial<CaptureCut> = {}): CaptureCut {
+    return {
+        id: 1,
+        type: "step",
+        parent_cut_id: null,
+        scene: "コーヒーメーカー全体を映し、作業者が電源ボタンに手を伸ばして押す一連",
+        shot_type: "hiki",
+        shooting_point: "電源ボタンとランプが画面中央に大きく映るように寄って撮影",
+        narration: "",
+        subtitle_primary: null,
+        subtitle_secondary: "",
+        adopted_take_id: null,
+        takes: [],
+        ...overrides,
+    };
+}
+
+afterEach(() => cleanup());
+
+describe("CutNavigator 狭幅 truncate 構造 (H13/F-1-3)", () => {
+    // Codex R1 Critical 反映: cut を一度だけ生成し render/getByText で同一参照を使う
+    it("scene 行は truncate を保つ (grid 是正で親幅が確定すれば効く。構造変更は不要)", () => {
+        const cut = makeCut();
+        render(CutNavigator, { props: { cuts: [cut], selectedCutId: null, onSelect: vi.fn() } });
+        expect(screen.getByText(cut.scene).className).toContain("truncate");
+    });
+
+    it("shooting_point 行は <p>min-w-0 + <span>truncate、MapPin は shrink-0 で ellipsis 可能", () => {
+        const cut = makeCut();
+        render(CutNavigator, { props: { cuts: [cut], selectedCutId: null, onSelect: vi.fn() } });
+
+        const sp = screen.getByText(cut.shooting_point!);
+        // 2 段検証: span 自身と親 <p> 行で役割分担を固定（付与先ずれ検出）。
+        // span は min-w-0/flex-1/truncate を全て要求（どれが欠けても red。Codex R2 Warning 反映）
+        expect(sp.tagName).toBe("SPAN");
+        expect(sp).toHaveClass("min-w-0", "flex-1", "truncate");
+        const row = sp.closest("p");
+        expect(row).not.toBeNull();
+        expect(row).toHaveClass("flex", "min-w-0");
+        // アイコン非圧縮（shrink-0）を仕様として固定
+        const icon = row!.querySelector("svg");
+        expect(icon).not.toBeNull();
+        expect(icon).toHaveClass("shrink-0");
+    });
+});
diff --git a/tests/js/pages/CaptureShow.test.ts b/tests/js/pages/CaptureShow.test.ts
index 47f5e17..595298b 100644
--- a/tests/js/pages/CaptureShow.test.ts
+++ b/tests/js/pages/CaptureShow.test.ts
@@ -214,3 +214,16 @@ describe("Capture/Show カメラフォールバック", () => {
         expect(enqueueMock.mock.calls[0][0].contentType).toBe("video/webm");
     });
 });
+
+describe("Capture/Show レイアウト overflow ガード (H13/F-1-3)", () => {
+    it("グリッドは mobile 単一列 (grid-cols-1)、左右 pane が min-w-0 を持つ", () => {
+        stubCameraSupported(false);
+        render(CaptureShow, { props: baseProps });
+
+        const grid = screen.getByTestId("capture-grid");
+        expect(grid.className).toContain("grid-cols-1");
+
+        expect(screen.getByTestId("capture-left-pane").className).toContain("min-w-0");
+        expect(screen.getByTestId("capture-right-pane").className).toContain("min-w-0");
+    });
+});
```

### テスト結果

- 対象テスト red→green（テストファースト）:
  - RED（施策1/2 未適用時）: CaptureShow「グリッドは mobile 単一列」= FAIL（`capture-grid` testid 不在）、CutNavigator「shooting_point 行」= FAIL（`P` を受け取り `SPAN` を期待）。合計 2 failed / 6 passed。
  - GREEN（施策適用後）: `vitest run CaptureShow CutNavigator` = 2 files passed / **8 tests passed**。
- 品質ゲート（worktree 内）:
  - `composer test` = pest 1716 passed / 2 skipped（PHP 変更なし・回帰なし）
  - `composer phpstan` = No errors
  - `vendor/bin/pint --test` = passed
  - `pnpm lint` = pass、`pnpm typecheck` = pass、`pnpm build` = 成功
  - `pnpm test`（全 vitest）= 72 files / 538 tests 全 pass（デフォルト 5000ms timeout ではマシン CPU 負荷由来の timeout flake が数件出るが、失敗集合は run 毎に変わり本変更ファイルは一切含まれない。--testTimeout=30000 で 538/538 green を確定。変更した CaptureShow/CutNavigator テストは毎回安定 pass）

### design system 参照

- 追加した class はすべて Tailwind 標準ユーティリティ（`grid-cols-1` / `min-w-0` / `flex-1` / `truncate` / `shrink-0` / `data-testid`）。DESIGN.md token（color/radius/typography）の参照・変更・hex 直書きの追加は一切なし。
- 触れた atomic ディレクトリ: `pages/Capture/Show.svelte`（pages 層）、`components/features/capture/CutNavigator.svelte`（features 層）。層をまたぐ新規 import なし。アイコンは既存の `@lucide/svelte` の `MapPin` を継続使用（`shrink-0` 維持）。
