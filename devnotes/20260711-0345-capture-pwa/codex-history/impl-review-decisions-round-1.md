# 対応マトリクス: impl-review (最終確認) Round 1

Codex 返答: `../impl-review-final-round-1.md`（Critical なし・マージ可の判定）。

## [Critical] なし
- 判断: — (指摘なし)
- 根拠: pending→used の読み取り順変更で Warning の過少計上窓は安全側 (二重計上=拒否側) に倒せているとの確認を得た。

## [Warning] 追加テストが呼び出し順の固定に留まり、READ COMMITTED 下の実競合挙動までは検証していない
- 判断: 見送る
- 根拠: 実競合の再現には 2 コネクション + トランザクション境界制御の統合テスト基盤が必要で、
  本修正 (読み取り順 1 行) に対して過大。順序固定テスト + docblock の不変条件明文化で
  「順序を崩すリファクタ」は検知できる。bytesPending/bytesUsed の意味論変更は
  既存の集計テスト (org 分離・status 別計上) が固定している。
- 対応内容: なし (テストは順序固定 + 既存集計テストで担保)。

## [Warning] 逆方向競合 (pending 読み後の新規 issue) は過大計上=誤拒否寄りに倒れ、高負荷時に一時的な誤拒否率が上がりうる
- 判断: 見送る (仕様として受容)
- 根拠: 新規 issue 同士は Organization 行ロックで直列化されるため実際にはこの窓は issue 間では発生しない。
  finalize との競合による一時的二重計上は「拒否側に倒す」という本修正の意図そのもの
  (セキュリティ穴ではない)。誤拒否時はクライアントの再試行で解消する。
- 対応内容: なし (監視は運用課題として認識)。

## [Suggestion] 並行 finalize を模した統合寄りテストの追加
- 判断: 見送る
- 根拠: 上記 Warning 1 と同じ (費用対効果)。
- 対応内容: なし。

## [Suggestion] TakeUploadService / TakeRegistrationService 側にも読み取り順不変条件の参照コメントを置く
- 判断: 対応する
- 根拠: 低コストで将来のリファクタ事故 (順序破壊・不要な org ロック追加) を防げる。
- 対応内容: `TakeUploadService::issue()` の checkAddition 呼び出し箇所と
  `TakeRegistrationService::finalize()` の docblock に、pending→used 順不変条件と
  StorageUsageService docblock への参照コメントを追加。

## [Suggestion] マージ可の判定
- 判断: 対応する (マージへ進む)
- 根拠: Critical なし・全検証コマンド green。
