# 詳細設計レビュー Round 3（Round 2 指摘への対応報告）

Round 2 で残った Warning 1 件（`submitPasswordForm` が `fireEvent.submit` の Promise を破棄）に対応しました。

## [Warning] 施策4: submitPasswordForm を async 化
対応: ヘルパを `async` にし submit の完了を await。全呼び出し側を `await submitPasswordForm()` に統一。
```ts
async function submitPasswordForm(): Promise<void> {
  const submit = screen.getByRole("button", { name: /パスワードを変更|変更中…/ });
  const formEl = submit.closest("form");
  expect(formEl).not.toBeNull();
  await fireEvent.submit(formEl as HTMLFormElement);
}
```
呼び出し側（3 ケース）はいずれも `await submitPasswordForm();` に更新済み。

---

これで残 Critical/Warning は解消したと考えます。全体判定（APPROVED / CHANGES_REQUESTED）を返してください。
