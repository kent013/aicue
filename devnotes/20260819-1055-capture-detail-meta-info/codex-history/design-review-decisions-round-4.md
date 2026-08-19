# 対応マトリクス: design-review Round 4

Codex 全体判定: **APPROVED** (全施策 APPROVE。Critical/Warning 0 件、Suggestion 4 件)。
Suggestion もすべて安価に対応できるため、そのまま反映した。

## [Suggestion] 施策1: source-shape pin はコメント内文字列でも通る (保証外を承知のうえ実装時に人手確認)

- 判断: 対応する (実装時の確認事項として明記済みの内容を維持。追加のドキュメント変更はしない)
- 根拠: 本 Suggestion は「実装レビューで人手確認せよ」という運用上の注意であり、
  設計自体は既に保証範囲を明記済み。実装フェーズの申し送りとして扱う。

## [Suggestion] 施策3: `$cut->takes` を 2 回読まずローカル変数で共有する

- 判断: 対応する
- 対応内容: `$takes = $cut->takes;` を 1 度だけ受け、親子整合性検査 (`foreach`) と
  並べ替え (`sortBy`) の両方でこの変数を使う形へ変更した。

## [Suggestion] 施策3: `relationLoaded()` の保証範囲をより正確に書く

- 判断: 対応する
- 対応内容: docblock に「`relationLoaded()` が保証するのは relation cache の存在だけであり、
  完全な eager load 結果であることまでは判定できない。現在の呼び出し元は
  `with()`/`load()` で必ず全件取得しているためこの前提で成立する」という限定を追記した。

## [Suggestion] 施策6: 施策一覧表の行6に `CaptureCutDataTest.php` が抜けている

- 判断: 対応する
- 対応内容: 施策一覧表の行 6 (施策6) の変更ファイル欄へ
  `tests/Unit/DataTransferObjects/Capture/CaptureCutDataTest.php` (新規) を追記した。

## 総括

Round 1〜4 を通じて解消した論点:
- 施策3: `CaptureCutData::fromCut()` のカット単位 N+1 (Critical, Round 1)
- 施策2: `array_sum()` の int/float 契約と桁溢れ (Warning, Round 1)
- 施策5: 全件未確定時の表示分岐の実装とテスト期待値の不一致 (Warning, Round 1)
- 施策6: クエリ数テストが N+1 修正前提で書かれ必ず失敗する (Critical, Round 1)
- 施策1: source-shape pin の走査器共通規約 (e) 適合 (Warning, Round 2/3。最終的に
  否定判定を削除し正の判定のみへ縮小)
- 施策3: `takes` の eager load 強制と親子整合性 (Warning, Round 2/3。最終的に
  `relationLoaded()` + `take.cut_id === cut.id` の 2 段検査へ到達)
- 施策3: nested route 防御の根拠が「はず」で未確定だった点 (Warning, Round 2/3。
  実際に確認し、カバーされていなかった同一 org 内 project 不整合の Feature テストを新設)

全体判定 APPROVED。次工程は `/app-todo-add` での TODO 登録。
