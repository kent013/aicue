# 対応マトリクス: design-review Round 3

Critical 1 / Warning 2 / Suggestion 1。**全件対応**した。反論は 0 件。

## [Critical] S5 テスト 10 が「目録か委譲のどちらか」で排他になっていない

- 判断: **対応する**
- 根拠: 正しい。「1 件以上あれば通る」形だと、(1) 目録と委譲の両方で同じ対を宣言、(2) 同じ `(kind, dimension)` の委譲を重複登録、(3) 必須表に無い余剰委譲、(4) `DestinationSet` の曖昧な多重委譲、がすべて緑になる。これは「同じ到達事実を二重宣言しない」という本設計の主目的そのものと矛盾する。
- 対応内容: テスト 10 を**排他的被覆**へ書き換え、実装コードも設計に載せた。
  - (a) 目録は `CodeReachPoint` の coverage source を 1 つ提供する（その kind の entry が 1 件以上あるとき）
  - (b) 委譲は `(kind, dimension)` ごとに高々 1 件
  - (c) 目録と委譲の両方が同じ対を覆っていたら赤
  - (d) 逆方向: `delegations()` の全件が `requiredDimensions()` の必須対に含まれる（余剰委譲の拒否）
  - (e) coverage source が 0 の必須対も赤
  - mutation を 2 本追加: **M16**（目録が覆う `Payment × CodeReachPoint` へ委譲を足す = 二重被覆）/ **M17**（委譲の重複 / 必須表に無い余剰委譲）
  - 「この形が要求する目録の性質」を注記し、テスト 13 と合わせて二重宣言が双方向に固定されることを明示した

## [Warning] S5 M7 の期待する赤が誤っている

- 判断: **対応する**
- 根拠: 正しい。`EXTERNAL_SEAM_RULE_KINDS` から `MarketData` を削っても、テスト 4 はキー集合の exact-fit と各値の非空しか見ないので緑。
- 対応内容: M7 の期待する赤を **テスト 1(a) + テスト 1(b)** へ訂正し、「テスト 4 は赤にならない」と明記した。

## [Warning] S5 `PestTestNameScanner` がメソッド呼び出しも拾う

- 判断: **対応する**
- 根拠: 正しい。`$object->test('名前')` / `$object?->test('名前')` / `SomeClass::test('名前')` は局所的に同じトークン並びになる。
- 対応内容: docblock に「対象は Pest のグローバル関数 `test()` / `it()` だけ」という契約を明記し、直前トークンが `T_OBJECT_OPERATOR` / `T_NULLSAFE_OBJECT_OPERATOR` / `T_DOUBLE_COLON` / `T_FUNCTION` のものを除外する規則を加えた。unit テストに #4（メソッド呼び出し 3 形）と #5（`function test()` の宣言）を追加した。

## [Suggestion] S8 「保証しないもの 9 項目」が実際は 10 項目

- 判断: **対応する**
- 対応内容: 件数表記をやめ「**完全一覧はここ（`docs/architecture.md`）が正本**（項目数は今後増減するため本設計に件数を書かない）」へ改めた。
