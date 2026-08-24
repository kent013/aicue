# 対応マトリクス: impl-review Round 2

## [Warning] 参照返しメソッドの宣言を検出できない

- 判断: **対応する**
- 根拠: 指摘どおり。PHP 8 は `&` を文脈で 3 通りにトークン化する。実測で確認した:
  `public static function &foo(): array` の `&` は素の文字トークンではなく
  **`T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG`** になる (引数の `array &$x` の側は
  `T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG`)。`isChar()` は `id === null` だけを認めるので、
  参照返しの宣言を**見逃していた** = fail-open。
- 対応内容:
  1. `isReturnByReferenceMarker()` を新設し、素の `&` と上記 2 つのトークン ID を
     すべて認めるようにした (保守的に両方を含める)。
  2. 見本 `positive-method-declaration-byref.php.txt` を追加し、専用テスト
     「参照返しのメソッド宣言も数える」で固定した。
  3. **fail-first 実測**: 修正を旧実装 (`isChar(…, '&')`) へ戻すと当該テストが
     「参照返しのメソッド宣言を検出できない」で赤くなることを確認した。

## [Suggestion] broken symlink は `population()` で共通関数を通っていない

- 判断: **対応する** (説明の訂正ではなく実経路を直す側を採る)
- 根拠: 指摘どおり。`is_file()` は壊れた symlink に false を返すため、順序が逆だと
  共通の純関数へ到達しない。結果は `unresolved` なので fail-open ではないが、
  「`population()` も自己検証も必ずこの関数を通る」という docblock の宣言と実経路が
  食い違う。**説明を弱めるより経路を直すほうが、自己検証と実母集団の同一性という
  本設計の趣旨に合う**。
- 対応内容: `population()` の判定順序を「symlink 判定 → `is_file()` 判定」へ入れ替え、
  クラス docblock の確定順序の記述も同じ順序へ直した。

## [Warning] 全体検証の完了条件がまだ満たされていない

- 判断: **対応する**
- 根拠: AGENTS.md の完了条件は全検証レーンの green であり、局所証拠では代替できない。
- 対応内容: Round 2 の修正をすべて入れ終えたあとに
  `composer test` / `pnpm test` / `pnpm test:packages` を全体で取り直し、
  結果を Round 3 のプロンプトへ実数で載せる。
