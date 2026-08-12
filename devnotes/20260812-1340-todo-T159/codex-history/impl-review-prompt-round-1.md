【アプリの使命 (North Star) — AGENTS.md より】

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

【禁止事項 — AGENTS.md より】

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(成果物はリポジトリ内のファイルとして出力する)

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

あなたは経験豊富なコードレビュアーです。Svelte 5 + Inertia の実装をレビューしてください。

【レビュー観点】
1. 詳細設計との一致性 2. 正確性 3. 型安全性 4. テストが退行を検出できるか (mutation 実測を添付)
5. 副作用・後退リスク 6. DESIGN.md / Atomic Design 7. 文言の正確さ (誇張していないか)

【特に見てほしい点】
- 設計からの乖離 1 点 (テストヘルパーの型注釈) の判断は妥当か
- 1 つの <p> に条件で継ぎ足す形が、読み上げ・可読性で問題ないか
- 文言が「観測できた事実」だけを述べているか (「古い」と断定していないか)

【出力形式】ファイルごとに APPROVE / REQUEST_CHANGES、[Critical][Warning][Suggestion]、全体判定、日本語

---

## 詳細設計書 (APPROVE 済み)

# 詳細設計: stale-preview-note

## 使命・制約(絶対遵守)

### アプリの使命(North Star) — AGENTS.md より転記

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した
**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、
専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

### 禁止事項 — AGENTS.md より転記

1. テストなしの実装完了報告 2. PHPStan の widen 3. dev DB への破壊操作
4. `response()->json()` の直書き 5. Prism 直呼び 6. prompt 直書き
7. `redirect()->intended()` 8. **必須条件未充足での disabled** 9. Artifact の使用

### コーディングルール

Svelte 5 runes + DS token のみ / TypeScript / 単方向 import / Lucide のみ /
`pnpm lint` `typecheck` `test` `build` が green / 既存テストの削除禁止。

## 概念設計リファレンス

- `devnotes/20260812-1340-stale-preview-note/conceptual-design.md` (Round 2 APPROVED)

---

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | 注記を「生成時点で」に言い換え、完全解消時のみ現在状態と再生成案内を足す | `resources/js/components/features/manual/RenderPanel.svelte` | High |
| 2 | 契約をテストで固定 | `tests/js/components/features/manual/RenderPanel.test.ts` | High |
| 3 | T148 節に「現在 coverage を表示の文脈として使う」を明記 | `docs/architecture.md` | Medium |

**サーバ側 0 行 / props 不変**。判定に要る 2 値 (`playbackJob.placeholder_cut_count` /
`coverage.missing_count`) は既に `RenderPanel` の props にある。

---

## 施策 1

### 現行コード

```svelte
{#if playbackNote !== null}
    <p class="text-caption text-text-secondary" data-testid="preview-placeholder-note">
        このプレビューは {playbackNote}
        件のカットに使用できる採用テイクがないため、その区間が黒背景になっています。
    </p>
{/if}
```

### 変更後コード

```ts
/**
 * その動画が**生成された時点**の黒背景カット数 (T148 の値契約。再計算しない)。
 * null は 0 と同一視しない = 何も言わない。
 */
const playbackNote = $derived(/* 現行のまま */);

/**
 * **その動画の黒背景の理由が、現在は完全に解消しているか。**
 * 名前をこの意味のまま保つ — 「プレビューが古いか」という一般命題は名乗らない
 * (シナリオ編集・カット追加・テイク差し替えでも古くなるが、この 2 値では判定できない)。
 */
const previewPlaceholderStateFullyResolved = $derived(
    playbackNote !== null && coverage.missing_count === 0,
);
```

```svelte
{#if playbackNote !== null}
    <!-- **常に「生成時点で」と書く** (現在形にしない)。生成時 20 件 → 現在 5 件のような
         部分解消でも「いま 20 件足りない」という誤読が起きないため。
         完全解消しているときだけ、現在状態と再生成の案内を足す (bug-hunt F-1-02)。 -->
    <p class="text-caption text-text-secondary" data-testid="preview-placeholder-note">
        このプレビューは生成時点で {playbackNote}
        件のカットに使用できる採用テイクがなく、その区間が黒背景になっています。{#if previewPlaceholderStateFullyResolved}現在のシナリオでは未採用のカットはありません。最新の内容で確認するにはプレビューを再生成してください。{/if}
    </p>
{/if}
```

### 設計判断

- **`placeholder_cut_count` は再計算しない** (T148 の値契約)。足すのは現在状態の**文脈**だけ。
- **文言で「古い」と断定しない**。書くのは観測できた事実 2 つだけ。
- **プレビュー動画は消さない**。生成物の履歴として価値がある。
- **`finishedJob` の有無で判定しない**。古くなる契機は完成動画生成ではなく採用テイクの変化。
- **`testid` は変えない** (`preview-placeholder-note`)。既存テストと bug-hunt の参照を壊さない。

---

## 施策 2: 契約

| # | 契約 | 検査 |
|---|---|---|
| 1 | 黒背景ありで未採用も残る (部分解消) → 本文に **`生成時点で 20 件` を直接含む**ことを assert (DOM の改行・空白を正規化する `toHaveTextContent` を使う) し、現在状態の文は出さない | `placeholder_cut_count=20` / `missing_count=5`。**`finishedJob` あり/なしの両方**で同じ結果 |
| 2 | 黒背景ありで未採用ゼロ (完全解消) → 本文に **`生成時点で 20 件`** と「現在のシナリオでは未採用のカットはありません」+ 再生成案内を含む | `placeholder_cut_count=20` / `missing_count=0`。**`finishedJob` あり/なしの両方**で同じ結果 |
| 3 | `placeholder_cut_count=0` なら注記自体を出さない (現行維持) | 注記 testid が不在 |
| 4 | `placeholder_cut_count=null` なら注記自体を出さない (null を 0 と同一視しない。現行維持) | 注記 testid が不在 |
| 5 | **現在形の断定文が消えている** | 本文に「ないため、その区間が黒背景になっています」を含まない |
| 6 | 完成動画があっても注記の判定は変わらない (`finishedJob` に依存しない) | 契約 1 **と契約 2 の両方**を `finishedJob` あり/なしで比較する (完全解消分岐側に `finishedJob` を足す mutation を殺すため) |

### fail 先行

契約 1 / 2 / 5 / 6 が赤くなることを確認する (3 / 4 は現行でも緑の想定)。

### mutation 計画

| # | mutation | 最低これが赤くなるはず |
|---|---|---|
| M1 | 「生成時点で」を現在形に戻す | 契約 1・2 (本文の `生成時点で 20 件` を直接 assert しているため) と契約 5 |
| M2 | `previewPlaceholderStateFullyResolved` を `missing_count > 0` に反転 | 契約 1・2 |
| M3 | 完全解消の分岐を常に出す | 契約 1 |
| M4 | 判定に `finishedJob !== null` を足す | 契約 6 |
| M5a | `playbackNote` の `> 0` 判定を外す (0 でも注記を出す) | 契約 3 |
| M5b | **`null` を表示値へ通すよう分岐を変える** (テンプレートの表示条件を `playbackNote !== null` から常時表示にする / null に sentinel を返す)。**`!== null` を外すだけでは `null > 0` が false なので契約 4 を破れない** | 契約 4 |

## 実装モード

incremental (1 component + テスト + docs 1 節)。競合リスクなし。

## 保証しないもの (誇張しない)

- **「プレビューが古い」ことは判定しない**。判定するのは黒背景理由の**完全解消**だけ。
  部分解消・逆方向 (テイク削除)・シナリオ編集による陳腐化は**検出しない**
  (ただし「生成時点で」の言い換えは全ケースで効く)。
- **自動で再生成はしない**。
- **サーバの props・認可・値契約は変えない**。


---

## テスト結果 (worktree 内)

`pnpm test` 1362 passed / `composer test` 4540 passed・2 skipped / `composer phpstan` No errors /
`vendor/bin/pint --test` / `pnpm lint` / `pnpm typecheck` / `pnpm build`: 全緑。

### fail 先行

契約 1 (2 dataset) / 契約 2 (2 dataset) / 契約 5 = **5 ケースが赤**、
既存の D-5 / D-5b (契約 3 / 4 相当) は緑。**予測どおり**。

### 設計からの乖離 1 点

テストヘルパー `stalePreviewProps` の戻り値型注釈 `: typeof baseProps` を**外した**。
`baseProps` の `playbackJob` / `finishedJob` は `null`、`missing_labels` は `never[]` と
推論されるため、注釈を付けると値を差し込めず `tsc` が落ちた (実測)。
注釈を外して object literal からの推論に任せ、理由をコメントに残した。

### mutation の実測 (予測との対比)

| # | mutation | 予測 | 実測 |
|---|---|---|---|
| M1 | 「生成時点で」を現在形へ戻す | 契約 1・2 と 5 | **一致** (契約 1×2 / 2×2 の 4 件) |
| M2 | 判定を `missing_count > 0` に反転 | 契約 1・2 | **一致** (4 件) |
| M3 | 完全解消の分岐を常に出す | 契約 1 | **一致** (2 件) |
| M4 | 判定に `finishedJob === null` を足す | 契約 2 | **一致** (契約 2 の finishedJob=true のみ 1 件) |
| M5a | `> 0` 判定を外す | 契約 3 (既存 D-5b) | **一致** (1 件) |
| M5b | `null` を表示値へ通す (表示条件を常時に) | 契約 4 (既存 D-5) | **一致** (D-5 / D-5b の 2 件) |

**6 種すべて予測どおり**。特に M4 が「finishedJob=true の契約 2 だけ」を赤くしたことで、
「完成動画の有無で判定しない」という設計判断が機械的に守られていることを実測できた。

---

## 実装差分 (git diff)

```diff
diff --git a/docs/architecture.md b/docs/architecture.md
index 10ec1cf..5ac630f 100644
--- a/docs/architecture.md
+++ b/docs/architecture.md
@@ -657,6 +657,17 @@ #### 採用テイク充足判定の単一化と告知契約 (T148)
 
   **`null` を `0` と同一視しない**。`0` は「黒背景ゼロで生成された」という積極的な事実であり、
   `null` は「その動画について言えることが無い」である。UI は `null` では何も表示しない。
+
+  **現在 coverage は「上書き」ではなく「表示の文脈」としてだけ使う (T159)**。
+  注記は**常に「生成時点で N 件…」**と書き (現在形にしない)、
+  `placeholder_cut_count > 0` かつ `coverage.missing_count === 0` のとき **だけ**
+  「現在のシナリオでは未採用のカットはありません」と再生成の案内を足す。
+  値は**再計算しない**ので値契約は不変である。
+  **これは「プレビューが古い」ことの判定ではない** — 判定できるのは
+  「黒背景の理由が**完全に解消**した」という片方向だけで、部分解消 (生成時 20 → 現在 5)・
+  逆方向 (テイク削除で増えた)・シナリオ編集による陳腐化は**検出しない**
+  (ただし「生成時点で」の言い換えは全ケースで誤読を防ぐ)。
+  背景は bug-hunt run 20260812-100645 の F-1-02 (完成動画の直下に矛盾する注記が残っていた)。
 - **書き込み位置は `finalize`** である。値が確定するのは `buildManifest` だが、そこは
   `video_manuals` を先にロックしているため、同 tx で `render_jobs` を UPDATE すると
   グローバル順の**逆順取得**になる。`finalize` は既に `render_jobs → video_manuals` の正順で
diff --git a/resources/js/components/features/manual/RenderPanel.svelte b/resources/js/components/features/manual/RenderPanel.svelte
index 9a85e7d..69056a0 100644
--- a/resources/js/components/features/manual/RenderPanel.svelte
+++ b/resources/js/components/features/manual/RenderPanel.svelte
@@ -104,6 +104,16 @@
             ? playbackJob.placeholder_cut_count
             : null,
     );
+    /**
+     * **その動画の黒背景の理由が、現在は完全に解消しているか** (T159 / bug-hunt F-1-02)。
+     * 名前をこの意味のまま保つ — 「プレビューが古いか」という一般命題は名乗らない
+     * (シナリオ編集・カット追加・テイク差し替えでも古くなるが、この 2 値では判定できない)。
+     * placeholder_cut_count は**再計算しない** (T148 の値契約)。現在 coverage は
+     * 上書きではなく**表示の文脈**としてだけ使う。
+     */
+    const previewPlaceholderStateFullyResolved = $derived(
+        playbackNote !== null && coverage.missing_count === 0,
+    );
     // ポーリング対象の job id 集合 (id のみに依存を狭め、応答更新で再購読しない)
     const pollKey = $derived(
         [
@@ -440,12 +450,15 @@
             {#if playbackJob !== null && !previewInFlight}
                 {#if playbackNote !== null}
                     <!-- 事後説明: 注記と動画 URL は同一の playbackJob から出る (別世代の値で説明しない) -->
+                    <!-- **常に「生成時点で」と書く** (現在形にしない)。生成時 20 件 → 現在 5 件の
+                         ような部分解消でも「いま 20 件足りない」という誤読が起きないため。
+                         完全解消しているときだけ現在状態と再生成の案内を足す (F-1-02)。 -->
                     <p
                         class="text-caption text-text-secondary"
                         data-testid="preview-placeholder-note"
                     >
-                        このプレビューは {playbackNote}
-                        件のカットに使用できる採用テイクがないため、その区間が黒背景になっています。
+                        このプレビューは生成時点で {playbackNote}
+                        件のカットに使用できる採用テイクがなく、その区間が黒背景になっています。{#if previewPlaceholderStateFullyResolved}現在のシナリオでは未採用のカットはありません。最新の内容で確認するにはプレビューを再生成してください。{/if}
                     </p>
                 {/if}
                 <!-- svelte-ignore a11y_media_has_caption (プレビュー動画の字幕は焼き込み済み) -->
diff --git a/tests/js/components/features/manual/RenderPanel.test.ts b/tests/js/components/features/manual/RenderPanel.test.ts
index 7e83c78..0b64f6c 100644
--- a/tests/js/components/features/manual/RenderPanel.test.ts
+++ b/tests/js/components/features/manual/RenderPanel.test.ts
@@ -586,6 +586,64 @@ describe("RenderPanel", () => {
         expect(screen.queryByTestId("preview-placeholder-note")).not.toBeInTheDocument();
     });
 
+    /*
+     * 完成動画の直下に矛盾する注記が残る問題 (T159 / bug-hunt F-1-02)。
+     *
+     * 注記は**常に「生成時点で」**と書く (現在形にしない = 部分解消でも誤読を作らない)。
+     * 黒背景の理由が**完全に解消**しているとき (placeholder_cut_count>0 かつ missing_count===0)
+     * だけ、現在状態と再生成の案内を足す。
+     * **「プレビューが古い」という一般命題は名乗らない** (シナリオ編集等では判定できないため)。
+     */
+    // 戻り値の型注釈は付けない (baseProps の null 型に狭まってしまうため。呼び出し側は render の props)
+    function stalePreviewProps(missingCount: number, withFinished: boolean) {
+        return {
+            ...baseProps,
+            coverage: {
+                total_cuts: 20,
+                missing_count: missingCount,
+                missing_labels: missingCount > 0 ? ["手順 1"] : [],
+            },
+            playbackJob: renderJobBody({
+                id: 33,
+                kind: "preview",
+                status: "succeeded",
+                placeholder_cut_count: 20,
+            }),
+            finishedJob: withFinished ? finishedJobBody() : null,
+        };
+    }
+
+    it.each([false, true])(
+        "T159 契約 1: 部分解消 (finishedJob=%s) では生成時点の件数だけを書き、現在状態の文は出さない",
+        (withFinished) => {
+            render(RenderPanel, { props: stalePreviewProps(5, withFinished) });
+
+            const note = screen.getByTestId("preview-placeholder-note");
+            expect(note).toHaveTextContent("生成時点で 20 件");
+            expect(note).not.toHaveTextContent("現在のシナリオでは未採用のカットはありません");
+        },
+    );
+
+    it.each([false, true])(
+        "T159 契約 2: 完全解消 (finishedJob=%s) では現在状態と再生成の案内を足す",
+        (withFinished) => {
+            render(RenderPanel, { props: stalePreviewProps(0, withFinished) });
+
+            const note = screen.getByTestId("preview-placeholder-note");
+            expect(note).toHaveTextContent("生成時点で 20 件");
+            expect(note).toHaveTextContent("現在のシナリオでは未採用のカットはありません");
+            expect(note).toHaveTextContent("プレビューを再生成");
+        },
+    );
+
+    it("T159 契約 5: 現在形の断定文 (「〜ないため、〜黒背景になっています」) は残っていない", () => {
+        render(RenderPanel, { props: stalePreviewProps(5, true) });
+
+        expect(screen.getByTestId("preview-placeholder-note")).not.toHaveTextContent(
+            "ないため、その区間が黒背景になっています",
+        );
+    });
+
     it("D-6: 事後説明と動画 URL は同一の playbackJob から出る (最新 preview が別世代でも)", () => {
         render(RenderPanel, {
             props: {
```
