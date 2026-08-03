# 対応マトリクス: design-review Round 2

## [Warning] A3: 固定長 sessionId 生成の不変条件にテストが無い
- 判断: 対応する
- 対応内容: 新規 `tests/Unit/Billing/FakeTicketCheckoutGatewayTest.php` を A3 のテスト計画へ追加:
  1. 同一 idempotency key → 同一 sessionId (決定論収束)
  2. 異なる key → 異なる sessionId
  3. sessionId が `^cs_bughuntfake_[0-9a-f]{32}$` に一致 (固定長トークン化の退行検出)
  4. 戻り URL が cancel URL ベース + `fake_external=stripe` marker (既存 query あり = `&` / なし = `?`)
- 施策一覧の A3 変更ファイル欄にも `FakeExternalUrl.php` と本テストを明記した。

(その他の指摘なし。A1/A2/B1/C1/C2 は APPROVE 済み)
