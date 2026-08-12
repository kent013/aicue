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

あなたは Svelte 5 + Inertia の詳細設計レビュアーです。

【レビュー観点】
1. 正確性 2. 既存整合 3. 型安全性 4. テスト網羅性と mutation 5. 副作用 6. DESIGN.md / Atomic Design

【特に見てほしい点】
- 契約 1..6 と mutation M1..M5 の対応に抜けは無いか
- 文言を 1 つの <p> に条件で継ぎ足す形で、読み上げ (role/aria) 上の問題は無いか
- testid を変えない判断は妥当か

【出力形式】施策ごとに APPROVE / REQUEST_CHANGES、[Critical][Warning][Suggestion]、全体判定、日本語

---

## 詳細設計書

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
| 1 | 黒背景ありで未採用も残る (部分解消) → 「生成時点で N 件」と書き、**現在状態の文は出さない** | `placeholder_cut_count=20` / `missing_count=5` |
| 2 | 黒背景ありで未採用ゼロ (完全解消) → 「生成時点で N 件」+「現在のシナリオでは未採用のカットはありません」+ 再生成案内 | `placeholder_cut_count=20` / `missing_count=0` |
| 3 | `placeholder_cut_count=0` なら注記自体を出さない (現行維持) | 注記 testid が不在 |
| 4 | `placeholder_cut_count=null` なら注記自体を出さない (null を 0 と同一視しない。現行維持) | 注記 testid が不在 |
| 5 | **現在形の断定文が消えている** | 本文に「ないため、その区間が黒背景になっています」を含まない |
| 6 | 完成動画があっても注記の判定は変わらない (`finishedJob` に依存しない) | `finishedJob` あり/なしで契約 1 の結果が同じ |

### fail 先行

契約 1 / 2 / 5 / 6 が赤くなることを確認する (3 / 4 は現行でも緑の想定)。

### mutation 計画

| # | mutation | 最低これが赤くなるはず |
|---|---|---|
| M1 | 「生成時点で」を現在形に戻す | 契約 5 |
| M2 | `previewPlaceholderStateFullyResolved` を `missing_count > 0` に反転 | 契約 1・2 |
| M3 | 完全解消の分岐を常に出す | 契約 1 |
| M4 | 判定に `finishedJob !== null` を足す | 契約 6 |
| M5 | `playbackNote` の null 判定を外し 0 でも出す | 契約 3・4 |

## 実装モード

incremental (1 component + テスト + docs 1 節)。競合リスクなし。

## 保証しないもの (誇張しない)

- **「プレビューが古い」ことは判定しない**。判定するのは黒背景理由の**完全解消**だけ。
  部分解消・逆方向 (テイク削除)・シナリオ編集による陳腐化は**検出しない**
  (ただし「生成時点で」の言い換えは全ケースで効く)。
- **自動で再生成はしない**。
- **サーバの props・認可・値契約は変えない**。


---

## 関連する現行コード

### RenderPanel.svelte (注記まわり)

```svelte
    const stepLabel = $derived(
        renderJob?.step ? RENDER_STEP_LABELS[renderJob.step] : "書き出しを待機中",
    );
    // 完成動画はあるがシナリオ編集で ready に戻った (要再生成) 案内
    const needsRegenerate = $derived(
        status === "ready" && renderJob?.status === "succeeded",
    );
    /**
     * 事前告知の要約ラベル。missing_labels は props 側で先頭 10 件に打ち切られているため、
     * 打ち切られていることを UI 側でも明示する (件数は missing_count が正)。
     */
    const missingLabelSummary = $derived(
        coverage.missing_labels.length < coverage.missing_count
            ? `${coverage.missing_labels.join("、")} ほか ${
                  coverage.missing_count - coverage.missing_labels.length
              } 件`
            : coverage.missing_labels.join("、"),
    );
    /** 再生している動画**そのもの**の実績値だけを出す (null は 0 と同一視しない = 何も言わない) */
    const playbackNote = $derived(
        playbackJob !== null &&
            playbackJob.placeholder_cut_count !== null &&
            playbackJob.placeholder_cut_count > 0
            ? playbackJob.placeholder_cut_count
            : null,
    );
    // ポーリング対象の job id 集合 (id のみに依存を狭め、応答更新で再購読しない)
    const pollKey = $derived(
        [
            isInFlight(renderJob) && renderJob !== null ? `r${renderJob.id}` : null,

...
                </div>
            {/if}
            {#if playbackJob !== null && !previewInFlight}
                {#if playbackNote !== null}
                    <!-- 事後説明: 注記と動画 URL は同一の playbackJob から出る (別世代の値で説明しない) -->
                    <p
                        class="text-caption text-text-secondary"
                        data-testid="preview-placeholder-note"
                    >
                        このプレビューは {playbackNote}
                        件のカットに使用できる採用テイクがないため、その区間が黒背景になっています。
                    </p>
                {/if}
                <!-- svelte-ignore a11y_media_has_caption (プレビュー動画の字幕は焼き込み済み) -->
                <!-- aria-label は固定文言でよい: playbackJob の供給源は初期値 (Controller が
                     kind=Preview ∧ status=Succeeded で抽出) と poll の preview 分岐だけで、
                     render job が入る経路が無い (完成動画と取り違わない)。
                     完成動画は finishedJob という別枠で持つため、この根拠は T154 後も成立する。 -->
                <video
                    controls
                    preload="metadata"
                    class="w-full rounded-md bg-neutral"
                    src={`/projects/${projectId}/manuals/${manualId}/render-jobs/${playbackJob.id}/playback`}
                    aria-label="プレビュー動画"
                    data-testid="preview-video"
                ></video>
            {/if}
        </div>
```
