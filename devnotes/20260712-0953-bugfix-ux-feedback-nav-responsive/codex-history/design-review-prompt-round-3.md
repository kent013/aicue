# 詳細設計レビュー Round 3 (対応反映後の再レビュー依頼)

Round 2 の指摘 (B: Warning 2 / C: Warning 1) をすべて設計へ反映しました。A1/A2 は変更ありません。

## 対応マトリクス

| # | 指摘 | 判断 | 対応 |
|---|------|------|------|
| 1 | [Warning] B: headerActions 重複テストの期待値と実装の矛盾（重複排除機構は無い） | 対応 | テストを「**独自 testId を持つページ固有アクション**（例 `page-action`）を渡しても常設ナビ `nav-settings` / `nav-logout` は各 1 個描画され、snippet 側アクションも共存する」に限定。snippet に設定/ログアウトそのものを渡すケースはテストしない（重複排除機構が無いことを設計に明記）。設定/ログアウトの再注入防止は Dashboard 側ページテストで固定 |
| 2 | [Warning] B: Dashboard.test.ts の `queryByTestId("nav-logout")` では旧実装残存を検出できない | 対応 | 検証を `queryByRole("link", { name: "設定" })` と `queryByRole("button", { name: "ログアウト" })` の**両方が null** に変更。テスト環境は page store 未設定 = auth なしで AppLayout 常設ナビは描画されないが、旧 page-local snippet が残っていれば `headerActions` は auth なしでも描画されるため、この検証が旧実装残存を確実に検出する |
| 3 | [Warning] C: fixture (id=2 に 2FA) と探索起点 (member-role-3) の不一致 | 対応 | fixture を id=3 に統一: `roleState: "unassigned"` **かつ** `twoFactorStatus: "enabled"`（閲覧者は id=1 owner/isSelf のため `canResetTwoFactor(未割当)` は真）。同一行に「2FA バッジ + 未割当バッジ + 2FA 解除ボタン + 未割当 select（『未割当（選択してください）』option）+ 削除ボタン」が揃う bug-hunt 実測の最悪幅構成を `member-role-3` 起点で検証。同行の `reset-two-factor-3` / `remove-member-3` の存在確認も追加。既存 id=3 依存アサーション（未割当バッジ等）への影響は 2FA バッジ追加のみで壊れない想定だが実装時に確認、と注記 |

## 修正後の該当セクション全文

### 施策 B テスト計画（修正後・該当部分）

> - 「**ページ固有アクションの snippet**（独自 testId を持つ別の操作、例 `page-action`）を渡しても、常設ナビ `nav-settings` / `nav-logout` は**各 1 個**描画され、snippet 側アクションも共存する」（`getAllByTestId("nav-settings").length === 1` 等。※AppLayout に重複排除機構は無いため、snippet に設定/ログアウトそのものを渡すケースはテストしない — 再注入の防止は Dashboard 側のページテスト（下記）で固定する）
>
> - [ ] 既存更新 (Vitest, `tests/js/pages/Dashboard.test.ts`): Dashboard が page-local の設定/ログアウトを持たないこと（AppLayout 常設化後の重複排除の回帰）。**検証方法**: テスト環境は page store 未設定 = auth なしのため AppLayout の常設ナビは描画されない。この状態で `queryByRole("link", { name: "設定" })` と `queryByRole("button", { name: "ログアウト" })` が**どちらも null** であることを検証する（旧実装の page-local snippet が残っていれば auth なしでも `headerActions` は描画されるため、この検証が旧実装残存を確実に検出する）。テスト意図として「logout POST は AppLayout の単一ハンドラの責務であり、Dashboard 内のイベントから `router.post('/logout')` を直接呼ばない」ことをコメントに明記する

### 施策 C テスト計画（修正後・該当部分）

> - **対象要素の特定は既存 `data-testid` 起点で行い、DOM 順序に依存しない**: メンバー行は `screen.getByTestId("member-role-3").closest("li")`（ロール select 起点）、操作ブロックは同 select の親 div (`element.parentElement`) を辿る。招待行は `screen.getByTestId("revoke-invitation-10").closest("li")` 起点
> - **fixture を bug-hunt 再現条件（最悪幅）に統一**: `membersFixture` の id=3 を `roleState: "unassigned"` **かつ** `twoFactorStatus: "enabled"` に変更する（閲覧者は id=1 の owner (isSelf) なので `canResetTwoFactor` は unassigned でも真）。これにより**同一行**に「2FA バッジ + 未割当バッジ + 2FA 解除ボタン + 未割当 select（『未割当（選択してください）』option）+ 削除ボタン」が揃い、F-14 実測の最悪幅構成を `member-role-3` 起点の同じ行で固定できる。同行に `reset-two-factor-3` / `remove-member-3` が存在することもあわせて検証する（既存テストの id=3 に依存するアサーション（未割当バッジ等）への影響は、2FA バッジ追加のみで壊れない想定だが実装時に確認）
> - 「招待行も同様の縦積みクラスを持つ」（`invitation-list` 側）

---

以上で Round 2 の Warning 3 件すべてに対応しました。全体判定をお願いします。
