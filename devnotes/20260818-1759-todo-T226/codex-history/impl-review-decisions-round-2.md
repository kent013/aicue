# 対応マトリクス: impl-review Round 2

## [Warning] 「保証しないもの」の理由が PHP の構文規則と一致していない
- 判断: 対応する
- 根拠: 型宣言 / `::class` / `instanceof` / `implements` は周辺トークンを見れば
  クラス名の位置だと判定できる。「構文から確定できない」は走査器の実装限界を
  PHP の限界として書いており、誇張である。
- 対応内容: `PhpReferenceScanner` の docblock と `docs/architecture.md` の理由を
  「本走査器がこれらの文脈の判定を実装していない (文脈判定を足せば解決はできる)」へ訂正した。
  `new` の直後を解決する分岐のコメントも「直前のトークンだけで分かる」へ言い換えた。

## [Warning] `$isClassImport` を要素ごとに戻す分岐がテストで裏取りされていない
- 判断: 対応する
- 根拠: 既存の見本は「class → function → const」の順なので、
  typed 要素の後にフラグが戻らない欠陥があっても緑になる。
- 対応内容: `use Aws\{function Support\s3 as Helper, S3, const Support\SNS as Marker, Sns};`
  の見本を追加し、typed 要素の**後ろ**にあるクラス import 2 件が別名表に載ることを固定した。
  フラグを戻す 1 行を消すとこの test だけが赤くなることも実測した。

## [Warning] `docs/architecture.md` の根拠が不正確
- 判断: 対応する (上記に含む)

## [Warning] 棚卸しの「fail-closed 化を実施」が実際の保証より広い
- 判断: 対応する
- 対応内容: 「部分修飾名を完全修飾名まで解決し、受け手の解決状態を判別可能にした。
  そのうえで**外部到達点の目録 2 系統と prompt 窓口**では未解決を拾う側へ倒した」へ狭めた。

## [Suggestion] `ReferenceScanResult::$imports` の契約を書く
- 判断: 対応する
- 対応内容: 「ファイルスコープのクラス / 名前空間 import だけが載る」ことと、
  「ファイル全体を 1 つの表へ畳んだ結果なので、namespace ブロックが複数あって同じ短縮名を使う場合は
  後のブロックが勝つ。名前解決そのものはブロックごとの表で行っており、この表は使っていない」
  を `@param` の説明へ書いた。

## 併せて行った整理 (Round 2 の diff 送信後)
- `PromptWindowScanner::reference()` の `$receiver` 引数が全呼び出しで `null` になったので削除し、
  種別も `NameReference` 固定にした (使われない引数を残さない)。
  静的呼び出しの判定分岐も、同じ site を作る 2 つの経路を 1 つへまとめた。
