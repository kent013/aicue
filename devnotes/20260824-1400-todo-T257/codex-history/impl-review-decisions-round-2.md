# 対応マトリクス: impl-review Round 2

## [Warning] AGENTS.md「例外へ載せるのは区分ごとの固定文だけ」が `SchemaViolation` に成立しない
- 判断: 対応する
- 根拠: `LlmJson::schemaViolation($detail, $path)` は呼び出し側の `$detail` をそのまま例外へ渡す。
  非漏洩テストが固定しているのも復号に失敗した 6 区分だけであり、記述が実装より広かった
  (保証範囲の誇張)。
- 対応内容: 規約 21 を「**復号に失敗した 6 区分**では固定文だけ」に狭め、
  `schema_violation` の `detail` は呼び出し側が組み立てるものであり
  **応答由来の文字列を混ぜないのは呼び出し側の責務 (機械では見ていない)** と明記した。

## [Warning] AGENTS.md「応答は登録済み受け取り関数の直接の引数」が全分類に読める
- 判断: 対応する
- 根拠: 検査 3 が強制するのは `Decoded` 分類だけで、`FreeText` / `ProviderShape` は対象外。
- 対応内容: 「**`Decoded` 分類の**応答は…」へ限定し、他 2 分類が対象外である旨を書いた。
  併せて「加工してから渡す形」も赤になることを追記した (Round 1 で足した検出)。

## [Warning] docs/architecture.md の `getMessage()` の記述が `schemaViolation()` と矛盾
- 判断: 対応する
- 対応内容: 同節を「復号に失敗した 6 区分では固定文だけ (非漏洩テストが固定するのもこの 6 区分)」
  に狭め、`schema_violation` が例外であること・現在の呼び出しは応答由来の文字列を含まないが
  **機械では見ていない**ことを明記した。

## [Warning] `llmResponseOtherReceivers()` の双方向照合・理由長の分岐が本番テストで一度も通らない
- 判断: 対応する
- 根拠: 目録が 0 件なので、余剰登録 / 未登録の観測値 / 30 文字未満の理由のいずれも
  本番の実データでは踏まない = 共通規約 (c) の裏取りが無い。
- 対応内容: 判定を純関数 `Tests\Support\Llm\LlmSeamInventoryRules::otherReceiverViolations()`
  へ切り出し、gate はそれを呼ぶだけにした。自己検査で合成入力の**両方向**を固定した
  (正例 = 現行どおり空 / 負例 = 未登録の観測値・stale 登録・短すぎる根拠・
  **末尾一致では通さないこと** の 4 形)。

## [Warning] 免除の前提検査に負例が無い
- 判断: 対応する
- 対応内容: 同じく純関数 `LlmSeamInventoryRules::exemptionViolations()` へ切り出し、
  負例 3 形 (実在しないパス / 30 文字未満の根拠 / **前提 (`executeSync()` を持つ) が失われた免除**)
  を合成入力で固定した。gate 側は走査で得た site 数をそのまま渡す。

## [Suggestion] 名前付き引数の分岐に正例が無い
- 判断: 対応する
- 根拠: `resolveSeam()` に専用分岐を足した以上、その分岐の正例が無いと誤検出側を固定できない。
- 対応内容: 見本 `seam-named-argument.php.txt` を追加し、
  `ExtractedSopData::fromLlmText(text: …->executeSync())` が
  `ResolvedPromptFactory` + 登録済み receiver として解決されることを固定した。

## [Warning] `composer test` 全数の再実行が未完了
- 判断: 対応する
- 対応内容: 本ラウンドの修正を入れたうえで全数を実行し、結果を Round 3 に載せる
  (Round 2 実行中は他エージェントがグローバルテストロックを保持していたため待機になっていた)。

## [Warning] `pipeline-smoke --check` / 互換性確認 A・B が未実施
- 判断: 見送る (設計どおり)
- 根拠: A / B は課金を伴い、設計が「エージェント判断では実行しない」と定めている。
  `--check` は provision 済み bughunt 環境を要求し、同一ホストで他エージェントの shard が
  走行中のため provision できない (相手の走行を壊す)。preflight の内容も本変更の経路に触れない。
- 対応内容: TODO クローズ時に**外部確認待ち**として 3 件を明示する。
