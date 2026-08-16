# 対応マトリクス: impl-review Round 1

Codex (gpt-5.5 / reasoning=high) の全体判定は **APPROVED**。
ファイル別判定も 9 ファイルすべて APPROVED で、Critical / Warning は 0 件。
挙がったのは Suggestion 1 件のみ。

## [Suggestion] `onVideoError` の `event.currentTarget` に `instanceof HTMLVideoElement` を足す

- 判断: **見送る**
- 根拠:
  - 失敗経路は 2 本 (`play()` の rejection / `error` イベント) あり、どちらも
    「**現在 mount されている video 要素と同一のときだけ停止する**」という 1 つの規則で書いている。
    片方だけ `instanceof` の絞り込みを足すと、同じ規則の 2 つの実装が別の形になり、
    後から読む人が「なぜ片方だけ違うのか」を推測することになる。
  - 実行上の差も無い。`event.currentTarget` は handler 実行中は必ずその `<video>` 要素であり、
    仮に両辺が null になっても `stopPreview()` は冪等 (既に停止状態なら何も起きない)。
  - Codex 自身が「実行上は問題ない / 必須変更ではない」と明記している。
- 対応内容: コード変更なし。上記の理由を Round 2 で伝える必要も無い (Round 1 で APPROVED のため合議終了)。

## 設計からの意図的逸脱 (Round 1 で妥当と判定されたもの)

| # | 逸脱 | Codex の判定 |
|---|---|---|
| 1 | `dwellTimer` / `hovering` / `videoEl` を `$state` ではなく素の `let` にした | 妥当 (描画依存ではないため) |
| 2 | attachment の引数を `Element` で受け `instanceof HTMLVideoElement` で絞る | 妥当 (型安全) |
| 3 | `previewable` 中間定数を置かず `{#if}` に 3 条件を直接書いた | 妥当 (Svelte の narrowing を優先) |
| 4 | ScenarioEditor テストで `takeUrl` を `buildTakeUrl` の別名で import | 指摘なし (既存 2 ファイルと同じ別名規約) |

## 合議の結論

Round 1 で APPROVED のため、合議はここで終了する (最大 3 ラウンドのうち 1 ラウンド)。
