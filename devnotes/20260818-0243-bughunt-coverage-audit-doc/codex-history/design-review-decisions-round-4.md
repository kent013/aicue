# 対応マトリクス: design-review Round 4 (全体判定 APPROVED)

Critical / Warning は 0 件。Suggestion 2 件はどちらも採用した。

## [Suggestion] テスト 9 と 15 の責務表現 (`app/../../etc` は層 1 で落ちる)
- 判断: 対応する
- 根拠: 指摘のとおり、字句の逸脱は層 1 が先に落とすので、層 2 の検体として書くと
  「層 2 が効いている証拠」にならない (空振りの検査になる)。
- 対応内容: 9 番を「不在 / repo の外を指す symlink / repo の内を指す symlink / 親ディレクトリが
  symlink」へ、15 番の字句検体へ `app/../../etc` を移した。

## [Suggestion] `DeclarationError` の文面に CR / LF が混ざると 1 行契約が壊れる
- 判断: 対応する
- 対応内容: 「メッセージに外部入力の値 (パス等) を含めるときは CR / LF を空白へ置き換える」を
  CLI 契約へ追記した。

## 結論

詳細設計は Round 4 で **APPROVED**。実装は TODO 登録後、テストファースト
(施策 3 → 1 → 2 → 4 → 5 → 6 → 7) で進める。
