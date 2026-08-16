# 対応マトリクス: impl-review Round 1

Codex (gpt-5.5 / reasoning=high) の全体判定は **APPROVED**。
Critical / Warning は 0 件、Suggestion が 1 件。

## [Suggestion] T154 gate を関数 body 単位の検査へ強めてはどうか

対象: `tests/Architecture/CurrentRenderArtifactInventoryTest.php` ケース 8

指摘の要旨: `ofMany(` / `hasOne(` のファイル単位の件数 pin を 1 → 2 にした変更は
「ただちに widen とは見ない」(succeeded 条件の 1 固定 + `latestSucceededRender` /
`coverCut` の名前 pin により T154 の主要な検出力は維持されている) が、将来さらに
強めるなら `latestSucceededRender()` の**関数 body 単位**で `RenderJob` / `Succeeded` /
`ofMany` を検査するとより堅い。

- 判断: **見送る**
- 根拠:
  1. Codex 自身が「今回の完了条件では必須ではない」と明記しており、指摘は将来の強化案である。
  2. 実行すると `RenderArtifactSelectionScanner` に関数 body の範囲抽出 (波括弧の対応付け) を
     新設することになる。これは T198 (代表サムネイルの表示) のスコープ外であり、
     **スコープを勝手に広げない**という本ラウンドの制約と、思考原則 2
     (今必要なものだけ作る) に反する。
  3. 本タスクが崩した前提は「VideoManual.php の one-of-many relation は 1 本だけ」という
     **代理検査の現在値**であって、T154 の不変条件 (成果物の選択式は Canonical ただ 1 ファイル /
     候補行は 1 本) そのものではない。不変条件に効く検査
     (`countSucceededStatusMarkers` = 1、`RenderKind::Render` の参照数 = 1、
     `hasOutputPathReference` = false) は 1 つも緩めていない。
- 対応内容: コード変更なし。強化案は T154 の gate を触る別タスクの議題として残す
  (本ラウンドでは登録しない — 現状で検出力の低下が無いため起票条件を満たさない)。

## 既知差異 2 件に対する評価

いずれも Codex から「妥当」と評価され、追加対応は不要と判断した。

1. `CaptureManualSummaryData` に `status` を復活させない (詳細設計の記述が T197 より古い)
   → 「T197 後の現行仕様優先として妥当」
2. `CurrentRenderArtifactInventoryTest` ケース 8 の件数 pin 更新 + 名前 pin 追加
   → 「ただちに widen とは見ない。T154 の主要な検出力は維持されている」
