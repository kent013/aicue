# 対応マトリクス: conceptual-review Round 2

## [Warning] prune の「削除行を state 別に集計する」実装方式を固定せよ
- 判断: **対応する**
- 根拠: 指摘のとおり。`delete()` は件数しか返さず、先に COUNT してから一括削除すると
  競合で「実際に削除した行」の集計にならない。
- 対応内容: 結論 8-3 に集計方法を明記した。state ごとに
  `DELETE … WHERE state = ? AND expires_at <= :cutoff` を発行し affected rows を実績とする。
  `cutoff` はコマンド開始時に 1 回だけ確定させ全 state で共有する。
  MCP 側は state 列を持たないので `expires_at <= :cutoff` の 1 本。

## [Warning] `IdempotencyClaimOutcome` の status × row 組合せ不変条件
- 判断: **対応する (ただし `response_body` の Assert は反論)**
- 根拠 (対応部分): `status + ?IdempotencyKey` を素で公開すると「claimed なのに row が null」
  「conflict なのに row が null」が構築できてしまう。named constructor で閉じるのは妥当。
- 根拠 (反論部分): `response_body` に `Assert::notNull` を課す提案は**後退になる**。
  現行実装は `is_array($body) ? $body : null` で保存しており、`null` は
  「2xx だが JSON 本体が配列でなかった」という**正当な保存値**である
  (モデルの PHPDoc も既に `array<array-key, mixed>|null` で nullable を表現済み)。
  非 null を要求すると、現行では再生できていた応答が `indeterminate` へ落ちる。
- 対応内容: 結論 9 に以下を追加した。
  (1) `__construct` を private にし named constructor
  (`claimed` / `replay` / `conflict` / `inProgress` / `indeterminate`) だけを公開、
  (2) `?IdempotencyKey` を露出せず `rowOrFail()` 経由で取り出す、
  (3) Unit テストで「無効な Outcome を構築できない」ことを固定、
  (4) `completed` の不変条件は `response_status !== null` **だけ**とし body は `?array` のまま渡す、
  (5) `Assert` 後は narrow 済みローカル変数のみを使いモデル property を読み直さない。

## [Suggestion] エラー envelope は 2 フィールドでなく構造全体を検証せよ
- 判断: **対応する**
- 対応内容: スコープ外節の記述を「`error.code` / `error.message` / `error.status` の 3 キーが揃い、
  それ以外の top-level キーが無いこと」に更新した。API エラー envelope 用の共通ヘルパーは
  `tests/` に存在しないことを確認済みなので、各テストで `assertJsonPath` + `assertJsonStructure` を明示する。

## [Suggestion] 後着 409 の前提 (外側 transaction が無いこと) を再確認せよ
- 判断: **対応する**
- 根拠: `DB::transaction` を張る middleware は `EnsureLoginMethodRemains` (web 専用 alias
  `ensure-login-method`) のみで、`routes/api.php` の書込 group には載っていない (実コードで確認)。
- 対応内容: 結論 8 の末尾に「前提の確認」として明記した。詳細設計でも再確認する。

## [Suggestion] `report()` に載せる情報を限定せよ (キー・body を出さない)
- 判断: **対応する**
- 対応内容: 結論 8-5 を追加。載せるのは `route_name` / actor 種別 / 期待 state / affected rows /
  例外クラス名の 5 つだけとし、`Idempotency-Key` の値・request body・保存応答 body は載せない。

## [Suggestion] 使命 / 禁止事項 / スコープ / リスクの各項
- 判断: **対応不要** (現状維持)
