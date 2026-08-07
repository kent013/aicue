# 対応マトリクス: design-review Round 4

## S6 [Critical] checkpoint を複数化しても「目録から `StripeInvoiceCreate` を削除した」ことを検出できない (M8c が赤にならない)

- 判断: **対応する**
- 根拠: 完全に妥当。現行の検査 (非空 / 混在なし / 重複なし / 実在) はすべて
  **登録済みの中で閉じている**ため、登録漏れという「不在」を検出できない。
  mutation 受け入れ条件 (M8c) と gate の主張が一致していなかった。
- 対応内容: Codex 提案の**独立した期待値 map** を採る (過剰な構造化を避ける方)。
  `jobDedupRequiredExternalCalls(): array<class-string, list<ExternalCallKind>>` を新設し、
  gate で次を検査する:
  1. **キー集合が `jobDedupGuarantees()` と完全一致**する (期待値の書き忘れも fail)
  2. 各ジョブについて、**期待種別の集合と登録済み checkpoint の種別集合が一致**する
  3. 期待が `[]` のジョブは `preflights` が `[new NoExternalCall(...)]` ちょうど 1 件であること
  これで M8c (create の登録削除) は 2 で赤になる。
- **この検査が保証しないこと (設計に明記する)**: 期待値 map と目録の**両方**を同時に消せば
  green のままになる。これは宣言的 gate の性質であり、
  「1 箇所の削除では通らない」= レビューで必ず 2 箇所の差分が見える形にすることが目的である。
  より強い形 (サービスのソースを走査して外部呼び出しの実在から期待集合を導出する) は、
  **preflight を意図的に持たない外部呼び出し** (後始末の `terminateInvoice`) の分類が
  別途必要になり複雑さが跳ねるため今回は採らない (AGENTS.md 思考原則 2)。この判断を gate に書き残す。

## S6 [Warning] `NoExternalCall` の複数登録を拒否していない

- 判断: **対応する**
- 根拠: 妥当。`[new NoExternalCall(…), new NoExternalCall(…)]` が通ってしまう。
- 対応内容: `$none !== []` のとき **`count($none) === 1` かつ `$checkpoints === []`** を要求する。

## S6 / S7 [Warning] 「外部呼び出し種別ごとに checkpoint がちょうど 1 件」は現行 gate の保証を誇張している

- 判断: **対応する**
- 根拠: 妥当。集合一致検査を入れるまでは「登録済み種別に重複がない」までしか言えない。
- 対応内容: 上記の集合一致検査を追加したうえで、S7 の対応表を
  「**期待する外部呼び出し種別 (`jobDedupRequiredExternalCalls()` が正本) と
  checkpoint 登録の集合一致**」という記述に改める。
  分担を明記する — **Architecture gate = 集合一致と実在と戻り型 / Feature テスト = 配置**。

## S1 [Suggestion] リスク節の「文字列 1 本のためにクラスを作る」は event 定数が 2 本になったので不正確

- 判断: **対応する**
- 根拠: 事実誤り。
- 対応内容: 「**ログ event 定数だけのために専用クラスを作る**」へ修正。
