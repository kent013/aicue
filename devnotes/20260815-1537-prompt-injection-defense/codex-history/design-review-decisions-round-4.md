# 対応マトリクス: design-review Round 4 (判定 APPROVED)

全施策 A〜K が APPROVE。設計変更要求は無し。

## [実装時の確認事項] vendor / 現行クラスの実 API との一致
- 判断: 対応する (設計へ反映)
- 根拠: Codex の指摘は設計変更要求ではなく「注入経路が実 API と一致することの実装時確認」。
  前提が違っていたら注入経路の設計をやり直す必要があるため、**実装の最初に確かめる**のが安い。
- 対応内容: 詳細設計に「実装時に最初に確かめること (fail-first)」節を新設し、4 点を書いた。
  1. `SopTextExtractor` が継承可能で `extract()` を override できる
  2. `Prompt::fake()` の応答生成から実行対象 prompt の合言葉を取得できる
     (できなければ `GuardedPromptInspector` 経由へ切り替える)
  3. `Prompt::load()` / `withMetadata()` の実戻り値型が `GuardedPrompt` の property 型と矛盾しない
  4. Blade が `{{ $llm_canary }}` を system 側でのみ展開する

## 最終確認 (使命・禁止事項)
- 使命: SOP を起点に AI が教材を設計する製品であり、起点が常に外部由来である以上、
  経路の迂回を塞ぎ漏洩を fail-closed に扱うことは使命の前提条件。全施策が寄与する。
- 禁止事項 1 (テストなしの実装完了): 全施策にテストを割り当て済み
  (A/H は gate、B/C は Unit、D/E/F/J は Feature、G/I は Architecture)。
- 禁止事項 5 (Prism 直呼び / 帰属必須): 窓口の `load()` は `LlmCallContextData` を必須引数に取り、
  免除経路は名指し 1 件に pin。射程は縮まず強化のみ。
- 禁止事項 6 (prompt 文字列の直書き): YAML 外出しを維持。合言葉の指示文も YAML 側に置く。
- 禁止事項 2 (PHPStan の widen): 各施策に PHPStan 適合チェックを記載。baseline は使わない。
- 思考原則 2・4 (二重防御を作らない): 役割分担表で「宣言 / 構造 / 結果確認」を分離。
- 思考原則 3 (後方互換の並走を残さない): 旧経路 (`Prompt::load()` の factory 直呼び) は
  同じ PR で全廃する。
