## 施策 A1
**判定: APPROVE**

認証条件、1リクエストによる throttle 回避、通知送信、redirect・flash 契約が十分に固定されています。`wantsJson` の意図も明確です。

## 施策 A2
**判定: APPROVE**

user 在／不在について、同一redirect・同一キー・同一文言を対で検証しており、F-06 の enumeration 抑止不変条件を維持できています。

## 施策 B
**判定: REQUEST_CHANGES**

- [Warning] `headerActions` 重複テストの期待値と実装設計が矛盾しています。設定・ログアウトを含む snippet を渡せば、現設計では常設ナビと両方がそのまま描画されます。`getAllByTestId(...).length === 1` を成立させる重複排除機構はありません。
  - 修正案: このテストは「任意のページ固有アクションを渡しても常設ナビが各1個」と限定し、snippet には別の test ID を持つ操作を渡してください。設定・ログアウトの再注入防止は、`Dashboard` からの削除をページテストで固定します。

- [Warning] `Dashboard.test.ts` の `queryByTestId("nav-logout") === null` は、ページローカルの旧ログアウトボタンに `nav-logout` が付いていないため、旧実装が残っても成功します。
  - 修正案: authなしで `headerActions` は描画される現行構造を利用し、`queryByRole("button", { name: "ログアウト" })` と設定リンクが存在しないことを検証してください。必要ならDashboard描画時の `router.post` mockも併用します。

## 施策 C
**判定: REQUEST_CHANGES**

- [Warning] 再現fixtureと探索起点が一致していません。テスト対象は `member-role-3` ですが、2FA再現条件は `id=2` に付与すると記載されています。これでは「2FA有効＋未割当」の最悪幅を同じ行で固定できません。
  - 修正案: `id=3` を `roleState: "unassigned"` かつ `twoFactorStatus: "enabled"`、2FA解除可能な権限条件に統一し、`member-role-3` 起点で同じ行のバッジ・解除ボタン・select・削除ボタンを確認してください。

## 全体判定
**CHANGES_REQUESTED**

A1/A2 は承認できます。Bのテストが旧実装残存を検出できない点と、Cの再現fixture不一致を修正すれば承認可能です。