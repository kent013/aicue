# Round 2: Round 1 指摘への対応

Warning 2 件・Suggestion 2 件を**すべて対応**しました。反論はありません。
APPROVED にできるか確認してください。

---

# 対応マトリクス: design-review Round 1

判定 REQUEST_CHANGES。Critical 0 / Warning 2 / Suggestion 2。**すべて対応**(反論なし)。

## [Warning] 契約 5 と M1 の対応が弱い

- 判断: **対応する**
- 根拠: 契約 5 は旧文言の**不在**しか見ておらず、「生成時点で」を現在形に戻す mutation を
  確実には殺せない (別の現在形にすれば旧文言と一致しないため)。
- 対応内容: 契約 1 / 2 で**本文に `生成時点で 20 件` を含むことを直接 assert** する形に変更し、
  M1 の予測も「契約 1・2 と 5」に修正した。

## [Warning] M5 の説明が不正確 (`> 0` も見ている)

- 判断: **対応する**
- 対応内容: mutation を **M5a (`> 0` を外す → 契約 3)** と **M5b (`!== null` を外す → 契約 4)** に
  分割し、それぞれ対応する契約を明示した。

## [Suggestion] M4 は契約 2 でも `finishedJob` あり/なしを比べた方が堅い

- 判断: **対応する**
- 対応内容: 契約 1 と**契約 2 の両方**で `finishedJob` あり/なしを比較する形にした
  (完全解消分岐側に `finishedJob` を足す mutation も殺せる)。

## [Suggestion] docs 追記の判断は妥当 (肯定)

- 判断: 対応不要。


---

## 改訂後の詳細設計 (全文)

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
| 1 | 黒背景ありで未採用も残る (部分解消) → 本文に **`生成時点で 20 件` を直接含む**ことを assert し、現在状態の文は出さない | `placeholder_cut_count=20` / `missing_count=5`。**`finishedJob` あり/なしの両方**で同じ結果 |
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
| M5b | `playbackNote` の `!== null` 判定を外す | 契約 4 |

## 実装モード

incremental (1 component + テスト + docs 1 節)。競合リスクなし。

## 保証しないもの (誇張しない)

- **「プレビューが古い」ことは判定しない**。判定するのは黒背景理由の**完全解消**だけ。
  部分解消・逆方向 (テイク削除)・シナリオ編集による陳腐化は**検出しない**
  (ただし「生成時点で」の言い換えは全ケースで効く)。
- **自動で再生成はしない**。
- **サーバの props・認可・値契約は変えない**。
