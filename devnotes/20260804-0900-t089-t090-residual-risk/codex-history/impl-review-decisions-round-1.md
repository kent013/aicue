# 対応マトリクス: impl-review Round 1

Codex 判定: **APPROVED** (Critical 0 / Warning 1 / Suggestion 2)。
Round 1 で APPROVED のため合議ループは 1 ラウンドで終了する。

## [Warning] `QuotaStatusDto::build()` の `array_values($exceeded)` が実装で外されている (設計との差分)

- 判断: **反論する (実装を正とし、設計側の差分として記録する)**
- 根拠: 詳細設計は「append のみだが list 契約を構造的に保証する」として `array_values()` を
  書いていたが、**PHPStan level 10 が `arrayValues.list` (「既に list なので効果が無い」) で
  error を出す**。禁止事項 2 により widen / baseline / `@phpstan-ignore` で黙らせることはできず、
  inline `@var` でも推論を上書きしない方針のため、**呼び出しを外すのが唯一の適法な解**である。
  設計文書がツールの実挙動と食い違っていたケースなので、実コードを正とする。
- 対応内容: `array_values()` を削除し、代わりに
  「append のみで組み立てるため `list<string>` のまま (PHPStan が推論する)。
   将来 filter 等でキーが飛ぶ操作を挟むなら、その時点で `array_values` を足すこと。」
  というコメントを `$exceeded = []` の直上に置いた。**構造保証の意図は文章として残る**ため
  設計の狙い (将来の変更で list 契約が崩れないようにする) は失われていない。
  この差分は StructuredOutput の `deviations_from_design` にも記録する。

## [Suggestion] `'/'.AdminPanelPath::resolve()` を他箇所と同じ呼び方に揃える

- 判断: **見送る**
- 根拠: `AdminPanelPath::resolve()` は docblock で「trim 済み・空なら 'admin'」= **先頭スラッシュを
  含まない**ことを契約として宣言しており (二重 fail-safe)、`'/'.` の連結はその契約に沿った
  正しい使い方である。`bootstrap/app.php` の既存利用も同じ契約前提で `resolve()` の戻り値を
  path 比較に使っており、「揃える」対象となる別の呼び方は存在しない。
  契約に沿った 1 箇所の連結を避けるためにヘルパを増やすのはオーバーエンジニアリング (思考原則 2)。

## [Suggestion] Browser テストの `click('通知を確認')` に `data-testid` を付けて堅牢化する

- 判断: **見送る**
- 根拠: 本タスクの設計スコープは T089/T090 の残存 6 論点の確定であり、Dashboard の
  リンクに testId を足すのは設計に無い変更 (思考原則 2 / 「設計に無いものを足さない」)。
  文言結合の脆さは**テスト内コメントで既に明示済み**
  (「文言は Dashboard.svelte 由来 (testId 未付与)。変わったら本テストを追随させること」)
  であり、壊れたときに追随先が分かる形にはなっている。
  testId 付与が要るなら Dashboard 側の別タスクとして扱う。
