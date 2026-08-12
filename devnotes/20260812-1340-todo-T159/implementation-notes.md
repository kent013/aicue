# 実装メモ: T159 完成動画の直下に古いプレビュー注記が残る (bug-hunt F-1-02)

設計: `devnotes/20260812-1340-stale-preview-note/` (概念 Round 2 APPROVED / 詳細 Round 3 APPROVE)。
実装レビュー: `impl-review-round-1.md` (Round 1 APPROVE / Critical・Warning 0)。

## 何が間違っていたのか

**注記の値は正しかった。** aicue:T148 の値契約どおり `placeholder_cut_count` は
生成物の説明であり、そのプレビューは確かに生成時点で 20 件が黒背景だった。
**間違っていたのは提示のしかた** — 現在形の文 (「〜ないため、〜黒背景になっています」) を、
既に解消済みの事実について書き、しかも完成動画の直下に並べていた。

## 実装したもの

1. 注記を**常に**「このプレビューは**生成時点で** N 件…」に言い換えた (現在形をやめた)。
   これだけで「いま N 件足りない」という誤読が**部分解消のケースでも**消える。
2. `placeholder_cut_count > 0` かつ `coverage.missing_count === 0` の**完全解消時だけ**、
   「現在のシナリオでは未採用のカットはありません」+ 再生成の案内を足す。
3. `docs/architecture.md` の T148 節に「現在 coverage は**上書きではなく表示の文脈**」を明記。

**サーバ 0 行 / props 不変 / testid 不変**。判定に要る 2 値は既に RenderPanel の props にあった。

## 名前と主張を狭く保つ

判定名は `previewPlaceholderStateFullyResolved`。**「プレビューが古い」という一般命題は
名乗らない** — シナリオ編集・カット追加・テイク差し替えでも古くなるが、この 2 値では判定できない。

## mutation の実測 (6 種すべて予測どおり)

| # | mutation | 予測 | 実測 |
|---|---|---|---|
| M1 | 「生成時点で」を現在形へ戻す | 契約 1・2・5 | 一致 (4 件) |
| M2 | 判定を `missing_count > 0` へ反転 | 契約 1・2 | 一致 (4 件) |
| M3 | 完全解消の分岐を常に出す | 契約 1 | 一致 (2 件) |
| M4 | 判定に `finishedJob === null` を足す | 契約 2 | 一致 (**finishedJob=true の 1 件だけ**) |
| M5a | `> 0` 判定を外す | 契約 3 (既存 D-5b) | 一致 |
| M5b | `null` を表示値へ通す | 契約 4 (既存 D-5) | 一致 (2 件) |

M4 が「finishedJob=true の契約 2 だけ」を赤くしたことで、**「完成動画の有無で判定しない」**という
設計判断が機械的に守られていることを実測できた。

なお設計 Round 2 で **M5b の当初定義 (「`!== null` を外す」) は mutation として成立しない**と
判明している (`null > 0` は false なので注記は出ない)。「`null` を表示値へ通す」に再定義した。

## 設計からの乖離 1 点

テストヘルパーの戻り値型注釈 `: typeof baseProps` を外した。`baseProps` の
`playbackJob` / `finishedJob` は `null`、`missing_labels` は `never[]` と推論されるため、
注釈を付けると値を差し込めず `tsc` が落ちた (実測)。理由はコメントに残した。

## 検証コマンド (worktree 内)

`pnpm test` 1362 passed / `composer test` 4540 passed・2 skipped /
`composer phpstan` No errors / pint / lint / typecheck / build: 全緑。

## 保証しないもの (誇張しない)

- **「プレビューが古い」ことは判定しない**。判定するのは黒背景理由の**完全解消**だけ。
  部分解消・逆方向 (テイク削除)・シナリオ編集による陳腐化は検出しない
  (ただし「生成時点で」の言い換えは全ケースで効く)。
- **自動で再生成はしない**。案内を出すだけ。
- **`placeholder_cut_count` の値契約は不変**。再計算していない。
