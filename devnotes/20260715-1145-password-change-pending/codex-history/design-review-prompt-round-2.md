# 詳細設計レビュー Round 2（Round 1 指摘への対応報告）

Round 1 の指摘（Critical 1 / Warning 4 / Suggestion 1）すべてに対応しました。以下の対応で全体判定を再評価してください。

## [Critical] 施策4: clearErrors/put 順序テストの偽陽性
対応: 順序比較の前に呼び出し回数を確定させる。
```ts
await waitFor(() => expect(putMock).toHaveBeenCalledTimes(1));
expect(clearMock).toHaveBeenCalledTimes(1);
expect(clearMock.mock.invocationCallOrder[0]).toBeLessThan(
  putMock.mock.invocationCallOrder[0],
);
```

## [Warning] 施策1&2: コメント圧縮
対応: 変更後コードのコメントを 2 行に短縮。
```svelte
function submitPassword(event: SubmitEvent): void {
    event.preventDefault();
    // 送信中の誤認防止のため、前回エラーを送信開始時に明示クリア
    // (Inertia useForm は送信ではクリアせず応答後にのみ errors を更新するため)。
    passwordForm.clearErrors();
    passwordForm.put("/user/password", {
        errorBag: "updatePassword",
        preserveScroll: true,
        onSuccess: () => { passwordForm.reset(); },
    });
}
```

## [Warning] 施策3: transform 戻り値に put/patch を含める
対応: `transform()` を `{ post, put, patch }` に拡張（戻り型注釈も更新）。既存 consumer は `.post` のみ参照で後方互換。
```ts
    transform() {
      return { post, put, patch };
    },
```

## [Warning] 施策4: closest("form") の null ガード
対応: 共用ヘルパで null ガードしてから submit。
```ts
function submitPasswordForm(): void {
  const submit = screen.getByRole("button", { name: /パスワードを変更|変更中…/ });
  const formEl = submit.closest("form");
  expect(formEl).not.toBeNull();
  void fireEvent.submit(formEl as HTMLFormElement);
}
```

## [Warning] 施策4: pending 文言テストの tick 依存
対応: `tick()` をやめ `waitFor` に変更。
```ts
    const form = formHolder.password as { processing: boolean };
    form.processing = true;
    await waitFor(() =>
      expect(screen.getByRole("button", { name: "変更中…" })).toBeInTheDocument(),
    );
    const busyButton = screen.getByRole("button", { name: "変更中…" });
    expect(busyButton).toBeDisabled();
    expect(busyButton).toHaveAttribute("aria-busy", "true");
```

## [Suggestion] 既存 4 ケース名不変を明記
対応: 施策4 設計方針に「既存 4 ケースの describe/it 名は変更しない（追加のみ）」を追記。

---

これらの修正で残 Critical/Warning は解消したと考えます。全体判定（APPROVED / CHANGES_REQUESTED）を返してください。まだ残る Critical/Warning があれば具体的な修正案とともに指摘してください。
