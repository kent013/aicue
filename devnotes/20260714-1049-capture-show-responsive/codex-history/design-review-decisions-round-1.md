# 対応マトリクス: design-review Round 1

全体判定: CHANGES_REQUESTED。施策1/2 は APPROVE、施策3/4 が REQUEST_CHANGES（テスト安定性）。

## [Critical] 施策3: `closest("section")` / `:scope > section` が brittle
- 判断: 対応する
- 根拠: DOM ラップ 1 段で壊れる。安定 testid の方が失敗理由も明瞭。
- 対応内容: `Show.svelte` に `data-testid="capture-grid"` / `capture-left-pane` /
  `capture-right-pane` を付与し、テストはそれらを直接取得して class を検証する。

## [Critical] 施策4: `makeCut()` を都度生成しており意図が不鮮明
- 判断: 対応する
- 根拠: factory 変更時にテスト意図がぶれる。
- 対応内容: `const cut = makeCut();` を先に定義し、render/getByText で同一参照を使う。

## [Warning] 施策2: scene は施策1 依存で成立 → 単独適用不可を明示
- 判断: 対応する
- 対応内容: 実装計画に「施策1→施策2 の順、同一 PR でマージ必須」を明記。

## [Warning] 施策3/4: className 部分一致のみだと付与先ずれを見逃す
- 判断: 対応する
- 対応内容: shooting_point は 2 段検証（行 `<p>` が `min-w-0`、`<span>` が `truncate`）。
  MapPin が `shrink-0` を持つアサーションも追加。

## [Warning] red→green のコマンド結果を devnotes に残す
- 判断: 対応する（実装フェーズの運用として明記）
- 対応内容: 完了条件に「`pnpm test -- CaptureShow` / `CutNavigator` の red→green 要約を実装 PR の devnotes に残す」を追加。実装は app-implement の責務。

## [Suggestion] 右カラム保守メモ / MapPin shrink-0 仕様化
- 判断: 対応する（設計に注記）
