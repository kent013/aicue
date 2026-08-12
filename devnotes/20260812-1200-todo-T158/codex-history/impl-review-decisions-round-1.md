# 対応マトリクス: impl-review Round 1

判定 REQUEST_CHANGES。Critical 0 / Warning 2 (+ APPROVE 4 ファイル)。**すべて対応**(反論なし)。

## [Warning] 契約 4 に 402 / 422 が残っているか不明

- 判断: **対応する** (422 を追加。402 は追加しない)
- 根拠: M2 検出には 403 / 409 で足りるが、契約 4 の趣旨は「**変更対象外の応答を固定する**」こと。
  422 (ValidationException) は変更対象外の代表なので入れる価値がある。
- 対応内容: 契約 4 に **422 の dataset を追加**し、`errors` 配列が返ることも見るようにした
  (validation 応答の形が保たれていることの固定)。
  **402 は追加しない** — 課金ゲートの 402 は組織状態の作り込みが要り、
  本 TODO の変更 (404 だけを触る) との距離が遠い。**入れなかったことを明記する**。

## [Warning] Architecture テストの自己検査が「件数 4」で弱い

- 判断: **対応する**
- 根拠: 妥当。件数だけでは「どの記法を取りこぼしたか」が分からない。
- 対応内容: 自己検査を**記法ごとの dataset (13 件)** に作り替えた。
  正例 7 (`abort` 位置引数 / `abort` named / 複数行 + ネスト引数 / `abort_if` / `abort_unless` /
  `new HttpException` の named 順不同 / `new NotFoundHttpException`) と
  負例 6 (文言なし / 別 status / 空文字 / コメント内 / 文字列リテラル内 / message 無しの `new HttpException`)。
  検出結果を「記法ラベル => 行番号」で返すヘルパーに切り出し、記法単位で判定できるようにした。

## [Suggestion] docblock がやや長い / M2 のコメントは正しい

- 判断: 対応不要 (肯定的評価)。
