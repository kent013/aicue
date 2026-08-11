# 対応マトリクス: conceptual-review Round 2

## [Warning] 固定 identity と永続 bug-hunt DB での 4 intent 探索可能性が未確定
- 判断: **対応する**
- 根拠: 指摘が正しい。実コードで初期状態と分岐を確認した:
  provision は毎回 `migrate:fresh --seed` を実行し、`ManualTestSeeder` /
  `BughuntBillingSeeder` / `AdminUserSeeder` / `BughuntOAuthSeeder` のいずれも
  `social_accounts` に行を作らない → run 開始時 `fake-{provider}-user` は未連携。
  この状態から `link` 成功と `register` 新規作成成功は**排他** (先着 1 回)。
  2 回目以降は競合分岐へ落ちるが、それはアプリの正当な分岐であり詰みではない。
- 対応内容: 概念設計に「固定 identity と bug-hunt の共有 DB」節を新設し、
  intent × 到達条件の表と排他関係、リセット手段 (provision の `migrate:fresh --seed` /
  子 wrapper の `reseed`) を明記。成功条件と期待効果からも「4 intent 同時成立」の主張を外した。
  提示された 2 案のうち **後者 (成功経路と競合経路を分けて保証する)** を採る。
  **seeder で事前に連携を張る案は採らない** — 事前連携は `link` / `register` の成功経路を
  逆に潰し、探索能力を下げるため (思考原則 2)。
  identity をリクエストパラメータで選ばせる案も採らない (認証バイパス面の拡大) — 指摘に同意。

## [Warning] 「自前クラスを abstract にすれば」が実装方針と矛盾
- 判断: **対応する** (文言修正)
- 根拠: 指摘のとおり。`abstract` を container の `bind($abstract, $concrete)` の第 1 引数の
  意味で使っていたが、PHP の `abstract class` と読める。
- 対応内容: 「自前の**具象クラス**を container の差し替えキーにすれば (= PHP の `abstract class`
  ではなく `bind()` の第 1 引数として自前クラスを使う)」へ修正し、
  `RecaptchaVerifier` → `RecaptchaVerifierTestFake` と同一形であることを併記した。

## [Warning] 「Feature テスト成功」と「bug-hunt で継続探索可能」を分けて書け
- 判断: **対応する**
- 対応内容: 成功条件へ「1 provision 内で `link`→`step-up`→`login` **または**
  `register`→`step-up`→`login` のどちらかが踏める」を追加し、
  「Feature テストが示すのは各 intent の round-trip 成立であって、
  1 provision 内での 4 intent 同時成立ではない」と明記した。

## [Suggestion] 使命の位置づけ / local 除外 / スコープ / 型安全性
- 判断: 対応不要 (既に反映済み)
