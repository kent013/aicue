## 施策2判定: REQUEST_CHANGES

[Warning] `(b)` の `onError` 駆動には、まだ実装上の矛盾があります。

現行の `confirmTwoFactor()` は `post` options に `onError` を渡していません。そのため、捕捉した `options.onError?.(...)` を呼んでも何も起きず、`errors.code` は更新されません。また実物の Inertia では、フォーム内部が errors を更新した後に利用者の `onError` callbackを呼びます。

修正案:

- `reactiveUseForm` の `post` に Inertia のエラー処理を模倣させるのではなく、`setErrors()` または `respondWithErrors()` を公開する。
- `(a)` で submit と `errorBag` を検証する。
- `(b)` では submit 後に `respondWithErrors({ code: "..." })` を呼び、リアクティブな表示を検証する。
- 「`options.onError` を直接発火」は削除する。

例:

```ts
const respondWithErrors = (nextErrors: Record<string, string>) => {
    Object.assign(errors, nextErrors);
};
```

これは責務も明確です。

- `(a)`：コンポーネントから Inertia への visit option
- `(b)`：Inertia が反映した form errors から UI への表示
- `(c)`：成功 callback 後の状態遷移

`reset: vi.fn()`、`processing` の `$state` + getter 化、成功パスの駆動方針は問題ありません。

## 全体判定: CHANGES_REQUESTED

上記一点を修正すれば、ほかに Critical / Warning はなく **APPROVED** と判断できます。