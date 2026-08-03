# 対応マトリクス: design-review Round 2

Codex Round 2 判定: CHANGES_REQUESTED（A1: APPROVE / A2: APPROVE / B: REQUEST_CHANGES / C: REQUEST_CHANGES）

## [Warning] B: headerActions 重複テストの期待値と実装設計の矛盾
- 判断: 対応する
- 根拠: 指摘どおり AppLayout に重複排除機構はなく、snippet に設定/ログアウトを渡せば二重描画される。「1 つずつ」の期待値は snippet の内容次第で偽になる矛盾したテストだった。
- 対応内容: テストを「**独自 testId を持つページ固有アクション**（例 `page-action`）を渡しても、常設ナビ nav-settings / nav-logout は各 1 個 + snippet 側も共存」に限定。設定/ログアウトの再注入防止は Dashboard 側ページテストで固定する旨を設計に明記。

## [Warning] B: Dashboard.test.ts の queryByTestId("nav-logout") では旧実装残存を検出できない
- 判断: 対応する
- 根拠: 旧 page-local ボタンには nav-logout testId が付いていないため、旧実装が残っても null になり素通りする。auth なしでも headerActions は描画されるという現行構造を突いたロールベース検証が正しい。
- 対応内容: 検証方法を `queryByRole("link", { name: "設定" })` と `queryByRole("button", { name: "ログアウト" })` の**両方が null** に変更（auth なし環境では常設ナビが出ないため、検出されるのは page-local 残存のみ = 旧実装残存を確実に検出）。

## [Warning] C: 再現 fixture (id=2 に 2FA) と探索起点 (member-role-3) の不一致
- 判断: 対応する
- 根拠: 指摘どおり「2FA有効 + 未割当」の最悪幅を同一行で固定できていなかった。
- 対応内容: fixture を id=3 に統一: `roleState: "unassigned"` かつ `twoFactorStatus: "enabled"`（閲覧者は id=1 owner/isSelf なので canResetTwoFactor は真）。同一行に 2FA バッジ + 未割当バッジ + 2FA 解除 + 未割当 select + 削除が揃う構成を `member-role-3` 起点で検証し、`reset-two-factor-3` / `remove-member-3` の存在確認も追加。既存の id=3 依存アサーションへの影響（2FA バッジ追加のみ）は実装時確認と注記。
