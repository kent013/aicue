# 対応マトリクス: impl-review Round 2 (全体判定 APPROVED)

Round 2 で Round 1 の Critical 2 件は「差分抽出範囲の問題であり実装は存在する」と確認され、
Warning 2 件は修正済みと確認された。残った指摘は Suggestion 1 件のみ。

## [Suggestion] キュー再開の配線テストが「uploaded がすべて watch へ入る」ことを保証していない
- 判断: **一部対応する** (テストの説明を実態へ合わせる。機構の追加はしない)
- 根拠: 指摘は正しい。`tests/js/pages/CaptureShow.test.ts` の配線テストが実際に固定しているのは
  **reload の回数** (uploaded が何件でも single-flight で 1 回 / 0 件なら 0 回) までであり、
  「uploaded の outcome が**すべて** `watch()` へ渡ること」は固定していない。
  ループが先頭 1 件だけに変わっても緑のままになる。
  一方、これを機械で固定するには `ThumbnailRefreshScheduler` を差し替え可能な collaborator
  (interface + 注入) にする必要がある。今それを作るのは
  AGENTS.md 思考原則 2 (今必要なものだけ作る) に反するため採らない。
  また AGENTS.md の作法として「**保証していないものを保証しているように書かない**」ことが
  重視されるため、**コメントを実態へ合わせる**方が正しい対応である。
- 対応内容: describe 冒頭のコメントを書き換え、
  (a) 固定しているのは reload の回数だけであること、
  (b) 「uploaded がすべて watch へ渡ること」は**保証しない**こと、
  (c) 固定するには scheduler を差し替え可能にする必要があり今は作らないこと
  を明記した。テストの assert 自体は変更していない (実装は全件 watch している)。
