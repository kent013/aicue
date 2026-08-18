# 対応マトリクス: impl-review Round 4

## [Warning] try / assertInstalled がクロージャ直下で無条件に実行されることを見ていない

- 判断: **対応する**
- 根拠: 指摘のとおり、`if (false) { try { … } finally { … } }` は範囲としては afterEach の
  内側だが 1 度も実行されない。「範囲の内側にある」だけでは結線の保証にならない。
- 対応内容: `cacheGuardIsDirectStatement()` を新設し、範囲の `{` から数えて
  入れ子の波括弧の中に無いこと (= その範囲の**直下の文**であること) を要求する形にした。
  対象は (a) beforeEach 直下の `assertInstalled` (b) afterEach 直下の try 文
  (c) try ブロック直下の flush (d) finally ブロック直下の reset の 4 つである。
  負例「try が条件分岐の中にある」「assertInstalled が条件分岐の中にある」を追加した。

## [Suggestion] `cacheGuardTryStatement()` の docblock が実装と読み違えられる

- 判断: **対応する**
- 対応内容: 「**最初に現れる try 文**を解析し、**それ自身が finally を持つ場合だけ**返す。
  最初の try が finally を持たなければその場で null を返す (後続の別の try-finally を
  借りてこないため = fail-closed)」へ書き直した。

## [Warning] 静的 gate 側の取り込み表がグループ use に対応していない

- 判断: **対応する (解決する側を選んだ)**
- 根拠: 「別 gate が禁じているから」に依存すると本 gate 単体では fail-closed にならない、
  という指摘は正しい。走査規約 (a) はこの走査器自身に掛かる。
- 対応内容: `cachePayloadUseMap()` をグループ use (`use A\B\{C, D as E};`) に対応させた。
  接頭辞と別名を解決し、`use function` / `use const` は名前解決の表に入れない。
  負例「グループ use + 別名で guard 実装クラスを継承する形」と、
  取り込み表そのものの期待値を突き合わせる正例を追加した。
  冒頭 docblock の「グループ use は扱わない」という限界の記述は**削除**した (もう限界ではない)。
