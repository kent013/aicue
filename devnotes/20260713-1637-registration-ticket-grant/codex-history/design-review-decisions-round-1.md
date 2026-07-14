# 対応マトリクス: design-review Round 1

## [Critical] 6-3 不変条件テストが `grantMonthly` を直接呼ぶ（実装詳細依存）
- 判断: 対応する
- 根拠: 将来 `grantMonthly` の可視性が変わると壊れる。制約そのものを検証すべき。
- 対応内容: 6-3 を「1 回目 = 公開ユースケース `grantSignupGrant($org)`（org キー挿入）、2 回目 =
  `DB::table('ticket_ledger_entries')->insertOrIgnore([... 'idempotency_key' => 'signup_grant:sub_legacy' ...])`
  で**異なるキーの直接挿入**を試み、部分 UNIQUE index が弾くことを検証（count=1 / balance=10）」へ書き換え。
  `grantMonthly` 依存を排除し DB 制約を直接検証する。

## [Warning] 施策3 の擬似コードが呼び出し表記を二重化していて誤写リスク
- 判断: 対応する
- 根拠: 設計書上の一意性。
- 対応内容: 施策3 から擬似コード（`$this->grantSignupGrant`）を削除し、最終形
  `$this->tickets->grantSignupGrant($organization)` のみを残す。

## [Warning] 施策3 付与失敗時の可観測性
- 判断: 対応する（fail-loud 維持 + 方針明記）
- 根拠: 付与失敗（config 誤設定等）は登録 tx をロールバックさせる（意図通り）。ただし握りつぶさない。
- 対応内容: grant を try/catch で包まない（fail-loud）。例外は登録アクション外へ伝播し、フレームワーク標準の
  例外ハンドラ（`report`）が記録する。誤設定はデプロイエラーとして早期顕在化させる旨を明記。

## [Warning] Architecture テストの indexdef 部分一致が弱い
- 判断: 対応する
- 根拠: `signup_grant` 単独では将来の別 index と誤検知し得る。
- 対応内容: `indexname` 完全一致に加え、`indexdef` に `UNIQUE` / `organization_id` / `ticket_ledger_entries` /
  `WHERE`（部分 index 述語の存在）/ `signup_grant` を全て要求する形へ強化。

## [Warning] Registration テストの「残高10」固定値は config 変更に脆い
- 判断: 対応する
- 根拠: config 由来値をテスト期待に使うべき。
- 対応内容: 期待値を `config('billing.signup_grant_tickets')` とし、固定値直書きを禁止と明記（6-1）。

## [Suggestion] テスト名に「増幅防止」/ changelog 注記
- 判断: 対応する（テスト名に反映）
- 対応内容: 招待非付与テスト名に「増幅防止（招待 N 人 = N×10 を作らない）」の意図を含める。
