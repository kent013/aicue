# 対応マトリクス: impl-review Round 2

Codex の判定は **CHANGES_REQUESTED** (Critical 1 件)。指摘は妥当で、そのまま対応した。

## [Critical] `/\$\{?CI\b/` は `$CI` / `${CI:-}` しか検出せず、`[[ -v CI ]]` / `test -v CI` / `printenv CI` が偽グリーンになる

- 判断: **対応する**
- 根拠: 指摘のとおり。「CI 環境変数を参照していないことを機械保証する」と宣言した以上、
  bash で実際に CI を読める書き方を取りこぼす gate は**空振り gate** であり、
  「登録したつもりで守られていない不変条件」という最悪の状態を作る (AGENTS.md 禁止事項 1 の趣旨に反する)。
- 対応内容:
  - 検出パターンを 4 本に拡張:
    `${CI...}` / `$CI` 展開、`[[ -v CI ]]`・`test -v CI`・`[ -v CI ]`、
    `printenv ... CI`、`env ... | ... CI`
  - 負のコントロールを 1 本 → **6 形態のテーブル駆動** (`expansion` / `bracket-v` / `test-v` /
    `printenv` / `env-grep` / `indirect`) に拡張。`indirect` (`flag=$CI` の 2 段構え) も含める

## [Suggestion] 分岐ではない `printf "$CI"` も検出する偽陽性がある。関数名・エラー文を「CI 参照禁止」に揃えるか、分岐検出へ限定するかを明確にせよ

- 判断: **対応する (「参照禁止」に揃える方を選ぶ)**
- 根拠: 分岐検出へ限定すると `flag=$CI` → `if [ "$flag" ]` の 2 段構えを取りこぼす
  (Critical と同じ穴を別の形で残す)。一方、ロック機構が CI を読む**正当な用途は 1 つも無い**ため、
  参照自体を禁じる方が契約として単純で漏れがない。安全側の偽陽性は許容する。
- 対応内容:
  - 関数名 `globalTestLockCiBypassViolations` → **`globalTestLockCiReferenceViolations`**
  - 定数 `GLOBAL_TEST_LOCK_NO_CI_BYPASS_SCRIPTS` → **`GLOBAL_TEST_LOCK_NO_CI_REFERENCE_SCRIPTS`**
  - エラー文 → 「`{path}` が CI 環境変数を参照している (CI を特別扱いしない = バイパス分岐を作らない)」
  - テスト名 → 「ロック機構が CI 環境変数を参照しないこと (CI バイパス禁止)」
  - docblock に「契約は『分岐していないこと』ではなく『参照していないこと』= deny-by-default」と明記

## 結果

- 層 2: 14 tests / **59 assertions** (49 → 59) / 全 pass
- `composer phpstan` (level 10): No errors / `vendor/bin/pint --test`: passed
