# 対応マトリクス: impl-review Round 2 (T256)

Codex の全体判定は **CHANGES_REQUESTED**。Critical 0 件 / Warning 2 件 (小項目 3 点)。すべて対応した。

## [Warning] `$withEntry` の `$index` が任意の `int` なので、戻り値の `list` 宣言が破れうる
- 判断: **対応する**
- 根拠: 指摘のとおり。範囲外の添字を渡すと非連続配列になり、`@return list<…>` の宣言と実態が食い違う
  (将来 tests/ を PHPStan の解析対象へ入れたときに落ちる)
- 対応内容: 戻り値を `array_values($entries)` で list へ正規化し、その理由を docblock に明記した

## [Warning] `envExampleLedgerViolations()` の docblock が「V1〜V21」のまま
- 判断: **対応する**
- 根拠: 規則 1 と規則 4 の検出力は V22〜V24 が無いと成立しない。保証機構の正本は docblock なので
  古い範囲が残ると「何が裏取りされているか」を誤読させる
- 対応内容: 「各規則の判定分岐を負のコントロール **V1〜V24** が対応表で押さえる」へ更新した
  (規則⇔ケースの対応表自体は Round 1 の修正で既に V22〜V24 を含む形にしてある)

## [Warning] 反証データセットの「複製に 1 か所だけ手を入れる」が V22 では字義どおりでない
- 判断: **対応する**
- 根拠: V22 は entry を 1 件足すのに加えて `kinds` / `classifications` の申告も揃えている。
  「1 か所」ではなく「導入する欠陥が 1 種類」が実態である
- 対応内容: 「各負例が導入する**欠陥は 1 種類だけ**である。申告件数は実件数へ合わせておき
  (V22 のように entry を足す負例では `kinds` / `classifications` も同じ数へ揃える)、
  狙った規則以外が発火しないようにする」へ書き換えた。同時発火が避けられないケースの列挙にも
  V23 を足した

## 確認結果

```
$ composer test -- --filter='EnvExampleInvariantTest'  → 61 passed / 94 assertions
$ vendor/bin/pint --test → passed
$ composer phpstan → No errors
```
