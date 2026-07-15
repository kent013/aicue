施策1&2: APPROVE

施策3: APPROVE

施策4: REQUEST_CHANGES

- [Warning] `submitPasswordForm()` が `fireEvent.submit()` の Promise を破棄しており、呼び出し側がイベント処理完了を待てません。`waitFor` のない成功時 `reset` 配線テストなどが競合する可能性があります。  
  修正案:

```ts
async function submitPasswordForm(): Promise<void> {
  const submit = screen.getByRole("button", {
    name: /パスワードを変更|変更中…/,
  });
  const formEl = submit.closest("form");
  expect(formEl).not.toBeNull();
  await fireEvent.submit(formEl as HTMLFormElement);
}
```

各テストでは `await submitPasswordForm()` としてください。

それ以外の Round 1 指摘は適切に解消されています。順序検証も呼び出し回数を先に確定しており、偽陽性の懸念は解消しています。

全体判定: CHANGES_REQUESTED