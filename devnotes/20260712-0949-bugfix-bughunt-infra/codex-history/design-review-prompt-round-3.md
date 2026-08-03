# Codex 詳細設計レビュー Round 3: bugfix-bughunt-infra

Round 2 の残指摘 (A3 の [Warning] 1 件) に対応した。

## 対応マトリクス

### [Warning] A3: 固定長 sessionId 生成の不変条件にテストが無い
- **対応済み**: 新規 `tests/Unit/Billing/FakeTicketCheckoutGatewayTest.php` を A3 のテスト計画に追加。
  提案どおり 4 ケースを明記:
  1. 同一 idempotency key から同一 `sessionId` が返る (決定論収束)
  2. 異なる key から異なる `sessionId` が返る
  3. `sessionId` が `^cs_bughuntfake_[0-9a-f]{32}$` に一致する (固定長トークン化の退行検出)
  4. 戻り URL が cancel URL ベースで `fake_external=stripe` marker が付与される
     (既存 query がある URL では `&`、無ければ `?` で連結)
- 施策一覧 (A3 行) の変更ファイル欄にも `FakeExternalUrl.php` と本テストファイルを明記した。

全体判定 (APPROVED / CHANGES_REQUESTED) の更新を。
