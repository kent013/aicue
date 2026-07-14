# 使命 (North Star)

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

**v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

# 禁止事項

1. テストなしの実装完了報告
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(factory 経由のみ)
6. prompt 文字列のコード直書き
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。設計そのものを見直せ。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

# system: コードレビュアーとしてのタスク

あなたは Laravel + Svelte 5 アプリの改善実装をレビューする。本件 T047「撮影中カメラプレビューへの字幕オーバーレイ表示」は **frontend のみ**の変更（PHP・DTO・API・Inertia props スキーマ不変）。

## レビュー観点

1. **設計との一致性**: 下記詳細設計書どおりに実装されているか（施策 S1〜S5）。
2. **正確性**: Svelte 5 runes（`$props`/`$state`/`$derived`）の使い方、条件付き描画、後方互換（既定値付き props で既存呼び出しが壊れないか）。
3. **テスト網羅性**: 空判定（trim 後）、描画は元文字列を書き換えない、トグルの `aria-pressed`/`aria-label` 遷移、disabled ガード不在の証跡、後方互換。既存テスト削除・改変がないか。
4. **セキュリティ**: overlay が MediaRecorder の MediaStream に含まれない（焼込にならない）= DOM 別レイヤーであること。
5. **DESIGN.md 準拠**: color/radius/typography は DS token/ramp 経由か。hex 直書き（`#RRGGBB`）を増やしていないか。`bg-text/70`・`text-surface`・`text-body`・`rounded-sm` は DS token/ramp。opacity modifier 付き overlay 背景は Modal の `bg-text/50` に前例あり。
6. **Atomic Design 準拠**: `resources/js/components/features/capture/` 配置の妥当性、単方向 import（atoms→molecules→organisms→features→templates→pages）、アイコンは `@lucide/svelte` のみ（`Captions`/`CaptionsOff`/`Circle`/`Square`）。
7. **禁止事項 8**: 字幕が空でもトグルを disabled にしていないか。

## 出力形式

- ファイルごとに判定。
- 指摘は Critical / Warning / Suggestion に分類。
- 最後に全体判定 **APPROVED** または **CHANGES_REQUESTED** を明記。

---

# user

## 詳細設計書（抜粋）

施策一覧:
- S1: 字幕オーバーレイ表示コンポーネント新設 `SubtitleOverlay.svelte`（presentational・無状態）
  - Props: `primary: string | null`（上部帯=名称・数値）/ `secondary: string`（下部メイン）/ `visible: boolean`
  - 空判定は `trim()` 後。`visible===false` または両方空なら何も描画しない。
  - overlay: `pointer-events-none absolute inset-0 flex flex-col justify-between p-3`。上下 2 スロット構造で `justify-between`。
  - 帯: `max-w-[90%] bg-text/70 px-3 py-1 text-center text-body text-surface whitespace-pre-line rounded-sm`。primary=`line-clamp-2`、secondary=`line-clamp-3`。
  - **描画は元文字列**（trim した値では描画しない）。overlay ルートに id を付与しない（条件付き描画で IDREF 不在になり得る・複数インスタンス重複回避。トグルは `aria-pressed` + 状態連動 `aria-label` で表現）。
  - testid: `subtitle-overlay` / `subtitle-primary` / `subtitle-secondary`。
- S2: CameraRecorder に字幕レイヤー + トグルを組込。Props に `subtitlePrimary?`（既定 null）/ `subtitleSecondary?`（既定 ""）を追加（既定値付きで後方互換）。`showSubtitles = $state(true)`（v1 中核価値が字幕のため既定 ON）。video を `relative` コンテナで包み overlay を重ねる。コントロール行右に字幕トグル（raw button + `aria-pressed` + 状態連動 `aria-label`、先例 PasswordInput）。既存録画ロジックは無改変。
- S3: Capture/Show から `selectedCut.subtitle_primary`/`subtitle_secondary` を配線（`{:else}`=selectedCut non-null 配下）。
- S4: SubtitleOverlay 単体テスト（新規）。
- S5: CameraRecorder トグル配線テスト（追記のみ、既存 8 ケース無改変）。

**設計不変条件**: overlay は DOM の別レイヤーであり MediaRecorder が録る MediaStream には含まれない（焼込にならない）。バックエンド・DTO・API・Inertia props スキーマは一切変更しない。

## 実装差分（git diff）

```diff
diff --git a/resources/js/components/features/capture/CameraRecorder.svelte b/resources/js/components/features/capture/CameraRecorder.svelte
index f2cfdc4..d5cb57b 100644
--- a/resources/js/components/features/capture/CameraRecorder.svelte
+++ b/resources/js/components/features/capture/CameraRecorder.svelte
@@ -1,9 +1,11 @@
 <script lang="ts">
     import { onDestroy } from "svelte";
-    import { Circle, Square } from "@lucide/svelte";
+    import { Captions, CaptionsOff, Circle, Square } from "@lucide/svelte";
     import Button from "@/components/atoms/Button.svelte";
+    import SubtitleOverlay from "@/components/features/capture/SubtitleOverlay.svelte";
     import { classifyGetUserMediaError, preferredRecordingMimeType } from "@/lib/capture/camera";
     import type { CameraUnavailableReason } from "@/lib/capture/camera";
+    import type { CaptureCut } from "@/types/capture";
 
     /**
      * MediaRecorder による録画 (概念設計 D9)。停止時に blob を親へ渡す。
@@ -15,9 +17,21 @@
         onCaptured: (blob: Blob, mimeType: string, durationMs: number) => void;
         /** カメラが恒久的に使えないと判明したときの通知 (親がフォールバックへ切替) */
         onCameraUnavailable: (reason: CameraUnavailableReason) => void;
+        /** 選択中カットの字幕 (撮影ガイド overlay 用。焼込ではない)。既定は空 (字幕なし) */
+        subtitlePrimary?: CaptureCut["subtitle_primary"];
+        subtitleSecondary?: CaptureCut["subtitle_secondary"];
     }
 
-    let { onCaptured, onCameraUnavailable }: Props = $props();
+    let {
+        onCaptured,
+        onCameraUnavailable,
+        subtitlePrimary = null,
+        subtitleSecondary = "",
+    }: Props = $props();
+
+    // 字幕オーバーレイの表示トグル (doc/05 §5.2)。v1 中核価値が字幕のため既定 ON。
+    let showSubtitles = $state(true);
+    const subtitleToggleLabel = $derived(showSubtitles ? "字幕を非表示" : "字幕を表示");
 
     let video: HTMLVideoElement | null = $state(null);
     let stream: MediaStream | null = null;
@@ -110,15 +124,22 @@
 </script>
 
 <div class="flex flex-col gap-3">
-    <!-- svelte-ignore a11y_media_has_caption -->
-    <video
-        bind:this={video}
-        autoplay
-        playsinline
-        muted
-        class="aspect-video w-full rounded-md bg-surface object-cover"
-        data-testid="camera-preview"
-    ></video>
+    <div class="relative">
+        <!-- svelte-ignore a11y_media_has_caption -->
+        <video
+            bind:this={video}
+            autoplay
+            playsinline
+            muted
+            class="aspect-video w-full rounded-md bg-surface object-cover"
+            data-testid="camera-preview"
+        ></video>
+        <SubtitleOverlay
+            primary={subtitlePrimary}
+            secondary={subtitleSecondary}
+            visible={showSubtitles}
+        />
+    </div>
     <div class="flex items-center justify-center gap-3">
         {#if recording}
             <Button variant="danger" onclick={stopRecording} testId="stop-recording">
@@ -131,6 +152,22 @@
                 録画開始
             </Button>
         {/if}
+        <!-- 字幕トグル (録画ボタン右)。二値の pressed 状態は raw button + aria-pressed で表現
+             (先例: molecules/PasswordInput.svelte)。字幕が空でも disabled にしない (禁止事項 8) -->
+        <button
+            type="button"
+            class="flex items-center rounded-sm p-2 text-text-secondary transition-colors duration-150 hover:text-text focus-visible:ring-3 focus-visible:ring-primary/35 focus-visible:outline-none"
+            aria-label={subtitleToggleLabel}
+            aria-pressed={showSubtitles}
+            onclick={() => (showSubtitles = !showSubtitles)}
+            data-testid="toggle-subtitles"
+        >
+            {#if showSubtitles}
+                <Captions class="size-5" aria-hidden="true" />
+            {:else}
+                <CaptionsOff class="size-5" aria-hidden="true" />
+            {/if}
+        </button>
     </div>
     {#if error}
         <p class="text-center text-caption text-danger" role="alert">{error}</p>
diff --git a/resources/js/components/features/capture/SubtitleOverlay.svelte b/resources/js/components/features/capture/SubtitleOverlay.svelte
new file mode 100644
index 0000000..62f1804
--- /dev/null
+++ b/resources/js/components/features/capture/SubtitleOverlay.svelte
@@ -0,0 +1,51 @@
+<script lang="ts">
+    import type { CaptureCut } from "@/types/capture";
+
+    /**
+     * 撮影中カメラプレビューへ重畳する字幕ガイド (doc/05 §5.2 の字幕重畳要件)。
+     * 焼込ではなく撮影ガイド overlay: MediaRecorder が録る MediaStream には含まれない。
+     * primary=上部帯 (名称・数値) / secondary=下部メイン。位置は AssSubtitleWriter (ASS) と一致。
+     * 位置・占有領域の確認用であり全文確認用ではない (長文は line-clamp で省略)。
+     */
+    interface Props {
+        primary: CaptureCut["subtitle_primary"];
+        secondary: CaptureCut["subtitle_secondary"];
+        visible: boolean;
+    }
+
+    let { primary, secondary, visible }: Props = $props();
+
+    // trim は「空判定」のみに使う。描画には元文字列をそのまま使う (内容を書き換えない)。
+    // secondary は型上 string だが将来の props 契約変更に備え防御的に nullish 合体する。
+    const hasPrimary = $derived((primary ?? "").trim() !== "");
+    const hasSecondary = $derived((secondary ?? "").trim() !== "");
+    const shown = $derived(visible && (hasPrimary || hasSecondary));
+</script>
+
+{#if shown}
+    <div
+        class="pointer-events-none absolute inset-0 flex flex-col justify-between p-3"
+        data-testid="subtitle-overlay"
+    >
+        <div class="flex justify-center">
+            {#if hasPrimary}
+                <p
+                    class="line-clamp-2 max-w-[90%] rounded-sm bg-text/70 px-3 py-1 text-center text-body whitespace-pre-line text-surface"
+                    data-testid="subtitle-primary"
+                >
+                    {primary}
+                </p>
+            {/if}
+        </div>
+        <div class="flex justify-center">
+            {#if hasSecondary}
+                <p
+                    class="line-clamp-3 max-w-[90%] rounded-sm bg-text/70 px-3 py-1 text-center text-body whitespace-pre-line text-surface"
+                    data-testid="subtitle-secondary"
+                >
+                    {secondary}
+                </p>
+            {/if}
+        </div>
+    </div>
+{/if}
diff --git a/resources/js/pages/Capture/Show.svelte b/resources/js/pages/Capture/Show.svelte
index 18212fd..99d3e79 100644
--- a/resources/js/pages/Capture/Show.svelte
+++ b/resources/js/pages/Capture/Show.svelte
@@ -183,6 +183,8 @@
                         onCaptured={(blob, mimeType, durationMs) =>
                             handleCaptured(blob, mimeType, durationMs)}
                         onCameraUnavailable={(reason) => (cameraUnavailableReason = reason)}
+                        subtitlePrimary={selectedCut.subtitle_primary}
+                        subtitleSecondary={selectedCut.subtitle_secondary}
                     />
                 {:else}
                     {#if fallbackNotice !== null}
diff --git a/tests/js/components/features/capture/CameraRecorder.test.ts b/tests/js/components/features/capture/CameraRecorder.test.ts
index f9d1384..b6fbf59 100644
--- a/tests/js/components/features/capture/CameraRecorder.test.ts
+++ b/tests/js/components/features/capture/CameraRecorder.test.ts
@@ -210,4 +210,79 @@ describe("CameraRecorder", () => {
             expect(onCameraUnavailable).toHaveBeenCalledWith("permission_denied");
         });
     });
+
+    // --- T047: 字幕オーバーレイのトグル配線 (追記。既存ケースは無改変) ---
+
+    it("字幕 props 既定 (省略) でも既存フローは無変更で render できる (後方互換)", () => {
+        render(CameraRecorder, {
+            props: { onCaptured: vi.fn(), onCameraUnavailable: vi.fn() },
+        });
+        // 既定 ON でも字幕なしなら overlay は描画されない
+        expect(screen.queryByTestId("subtitle-overlay")).not.toBeInTheDocument();
+        // トグルは常に存在する
+        expect(screen.getByTestId("toggle-subtitles")).toBeInTheDocument();
+    });
+
+    it("字幕 props を渡すと既定 showSubtitles=true で overlay が表示される", () => {
+        render(CameraRecorder, {
+            props: {
+                onCaptured: vi.fn(),
+                onCameraUnavailable: vi.fn(),
+                subtitlePrimary: "名称A",
+                subtitleSecondary: "メイン字幕",
+            },
+        });
+        expect(screen.getByTestId("subtitle-overlay")).toBeInTheDocument();
+        const toggle = screen.getByTestId("toggle-subtitles");
+        expect(toggle).toHaveAttribute("aria-pressed", "true");
+        expect(toggle).toHaveAttribute("aria-label", "字幕を非表示");
+    });
+
+    it("トグルクリックで overlay 非表示 + aria-pressed=false / aria-label='字幕を表示'", async () => {
+        render(CameraRecorder, {
+            props: {
+                onCaptured: vi.fn(),
+                onCameraUnavailable: vi.fn(),
+                subtitlePrimary: "名称A",
+                subtitleSecondary: "メイン字幕",
+            },
+        });
+        await fireEvent.click(screen.getByTestId("toggle-subtitles"));
+
+        expect(screen.queryByTestId("subtitle-overlay")).not.toBeInTheDocument();
+        const toggle = screen.getByTestId("toggle-subtitles");
+        expect(toggle).toHaveAttribute("aria-pressed", "false");
+        expect(toggle).toHaveAttribute("aria-label", "字幕を表示");
+    });
+
+    it("再クリックで overlay 再表示 + aria-pressed=true / aria-label='字幕を非表示'", async () => {
+        render(CameraRecorder, {
+            props: {
+                onCaptured: vi.fn(),
+                onCameraUnavailable: vi.fn(),
+                subtitlePrimary: "名称A",
+                subtitleSecondary: "メイン字幕",
+            },
+        });
+        const toggle = screen.getByTestId("toggle-subtitles");
+        await fireEvent.click(toggle);
+        await fireEvent.click(toggle);
+
+        expect(screen.getByTestId("subtitle-overlay")).toBeInTheDocument();
+        expect(toggle).toHaveAttribute("aria-pressed", "true");
+        expect(toggle).toHaveAttribute("aria-label", "字幕を非表示");
+    });
+
+    it("字幕が空でもトグルは disabled にならず、クリックで状態遷移する (禁止事項 8)", async () => {
+        render(CameraRecorder, {
+            props: { onCaptured: vi.fn(), onCameraUnavailable: vi.fn() },
+        });
+        const toggle = screen.getByTestId("toggle-subtitles");
+        // disabled 属性を持たない
+        expect(toggle).not.toBeDisabled();
+        expect(toggle).toHaveAttribute("aria-pressed", "true");
+        // 実クリックで状態遷移する (押下不能=詰みにしない)
+        await fireEvent.click(toggle);
+        expect(toggle).toHaveAttribute("aria-pressed", "false");
+    });
 });
diff --git a/tests/js/components/features/capture/SubtitleOverlay.test.ts b/tests/js/components/features/capture/SubtitleOverlay.test.ts
new file mode 100644
index 0000000..58c005b
--- /dev/null
+++ b/tests/js/components/features/capture/SubtitleOverlay.test.ts
@@ -0,0 +1,98 @@
+import { afterEach, describe, expect, it } from "vitest";
+import { cleanup, render, screen } from "@testing-library/svelte";
+import SubtitleOverlay from "@/components/features/capture/SubtitleOverlay.svelte";
+
+/*
+ * SubtitleOverlay: 撮影中プレビューへ重畳する字幕ガイド (焼込ではない DOM overlay)。
+ * primary=上部帯 / secondary=下部メイン。空判定は trim 後、描画は元文字列のまま。
+ * visible=false または primary/secondary 両方空なら何も描画しない。
+ */
+
+afterEach(() => {
+    cleanup();
+});
+
+describe("SubtitleOverlay", () => {
+    it("visible=true + primary/secondary あり → overlay と両帯が表示される", () => {
+        render(SubtitleOverlay, {
+            props: { primary: "名称A", secondary: "メイン字幕", visible: true },
+        });
+        expect(screen.getByTestId("subtitle-overlay")).toBeInTheDocument();
+        expect(screen.getByTestId("subtitle-primary")).toHaveTextContent("名称A");
+        expect(screen.getByTestId("subtitle-secondary")).toHaveTextContent("メイン字幕");
+    });
+
+    it("visible=false → overlay 非表示 (primary/secondary あっても描画しない)", () => {
+        render(SubtitleOverlay, {
+            props: { primary: "名称A", secondary: "メイン字幕", visible: false },
+        });
+        expect(screen.queryByTestId("subtitle-overlay")).not.toBeInTheDocument();
+        expect(screen.queryByTestId("subtitle-primary")).not.toBeInTheDocument();
+        expect(screen.queryByTestId("subtitle-secondary")).not.toBeInTheDocument();
+    });
+
+    it("primary=null かつ secondary='' → 非表示", () => {
+        render(SubtitleOverlay, {
+            props: { primary: null, secondary: "", visible: true },
+        });
+        expect(screen.queryByTestId("subtitle-overlay")).not.toBeInTheDocument();
+    });
+
+    it("primary/secondary が空白のみ (空白・改行) → trim 後空扱いで非表示", () => {
+        render(SubtitleOverlay, {
+            props: { primary: "   ", secondary: "\n", visible: true },
+        });
+        expect(screen.queryByTestId("subtitle-overlay")).not.toBeInTheDocument();
+    });
+
+    it("primary のみ (secondary='') → primary 帯のみ表示、secondary 帯は非存在", () => {
+        render(SubtitleOverlay, {
+            props: { primary: "名称A", secondary: "", visible: true },
+        });
+        expect(screen.getByTestId("subtitle-overlay")).toBeInTheDocument();
+        expect(screen.getByTestId("subtitle-primary")).toBeInTheDocument();
+        expect(screen.queryByTestId("subtitle-secondary")).not.toBeInTheDocument();
+    });
+
+    it("secondary のみ (primary=null) → secondary 帯のみ表示、primary 帯は非存在", () => {
+        render(SubtitleOverlay, {
+            props: { primary: null, secondary: "メイン字幕", visible: true },
+        });
+        expect(screen.getByTestId("subtitle-overlay")).toBeInTheDocument();
+        expect(screen.getByTestId("subtitle-secondary")).toBeInTheDocument();
+        expect(screen.queryByTestId("subtitle-primary")).not.toBeInTheDocument();
+    });
+
+    it("長文 JP + 多数改行を同時に与えても両帯が別々に存在し line-clamp が付く (中央侵食しない構造)", () => {
+        const longPrimary = Array.from({ length: 8 }, (_, i) => `名称行${i}`).join("\n");
+        const longSecondary = Array.from({ length: 12 }, (_, i) => `本文行${i}`).join("\n");
+        render(SubtitleOverlay, {
+            props: { primary: longPrimary, secondary: longSecondary, visible: true },
+        });
+        const primary = screen.getByTestId("subtitle-primary");
+        const secondary = screen.getByTestId("subtitle-secondary");
+        expect(primary).toHaveClass("line-clamp-2");
+        expect(secondary).toHaveClass("line-clamp-3");
+    });
+
+    it("描画文字列を trim で書き換えない: 前後空白を含む値でも描画され textContent に本体を含む", () => {
+        render(SubtitleOverlay, {
+            props: { primary: "  a  ", secondary: "  b  ", visible: true },
+        });
+        expect(screen.getByTestId("subtitle-primary").textContent).toContain("a");
+        expect(screen.getByTestId("subtitle-secondary").textContent).toContain("b");
+    });
+
+    it("位置構造: overlay ルートが flex-col justify-between、primary が先頭・secondary が末尾スロット", () => {
+        render(SubtitleOverlay, {
+            props: { primary: "名称A", secondary: "メイン字幕", visible: true },
+        });
+        const overlay = screen.getByTestId("subtitle-overlay");
+        expect(overlay).toHaveClass("flex-col", "justify-between");
+        const primary = screen.getByTestId("subtitle-primary");
+        const secondary = screen.getByTestId("subtitle-secondary");
+        // primary スロットが secondary スロットより前に位置する (DOM 順)
+        const position = primary.compareDocumentPosition(secondary);
+        expect(position & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
+    });
+});

```

## テスト結果

- `pnpm typecheck`: OK（tsc --noEmit エラーなし）
- `pnpm lint`: OK
- `pnpm test`: **73 files / 579 tests passed, 0 failed**（内 SubtitleOverlay 10 ケース新規・CameraRecorder に 5 ケース追記、既存 CameraRecorder 8 ケースは無改変で通過）
- `pnpm build`: OK
- PHP 変更なし（composer test / phpstan / pint は非該当）

## design system 参照（DESIGN.md 関連抜粋）

- typography ramp: `text-body`（16px/400/lh1.7、本文・主要数値の役割）。
- radius ramp: `rounded-sm`(4px)/`rounded-md`(6px)/`rounded-lg`(8px) の 3 段のみ。小コントロールは `rounded-sm`。
- color token: `text-text`(`--color-text`)、`text-surface`（surface 前景）。
- Modal overlay の前例: overlay は `bg-text/50`（墨色 50%。黒 hex を使わない）。→ 本件の `bg-text/70` は同系の opacity modifier 運用。

## 触れた atomic ディレクトリ構造

`resources/js/components/features/capture/`:
- CameraRecorder.svelte（変更）/ CaptureFileFallback.svelte / CutNavigator.svelte / **SubtitleOverlay.svelte（新規）** / TakeCommentDialog.svelte / TakeStrip.svelte / UploadQueueBar.svelte
