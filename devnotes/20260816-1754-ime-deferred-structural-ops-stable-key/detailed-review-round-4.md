## 全体判定: CHANGES_REQUESTED

施策1〜5は引き続き APPROVE です。修正後の走査は現行コードに対して8件を返し、全件 `fromNamedFunction: true` になります。ただし施策6の文レベルarrow検出には、同一行で呼び出す場合の穴が残っています。

### 施策6: REQUEST_CHANGES

[Warning] `ARROW_DEFINITION` の状態更新が呼び出し判定より後なので、負のコントロール `(d2)` の例を `fromNamedFunction` では検出できません。

設計にある次の1行形式を処理するとします。

```ts
const foo = (): void => { runSettled(...); };
```

現在の順序では、`CALL` 判定時点の `lastOpenerWasNamed` は直前の名前付き関数の状態のままです。

```ts
if (CALL.test(line)) {
    // fromNamedFunction: true で登録される
}

if (ARROW_DEFINITION.test(line)) {
    lastOpenerWasNamed = false;
}
```

この追加だけなら件数が9件になるためテスト自体は赤くなります。しかし、既存呼び出しの削除と同時に追加された場合など、件数が相殺されるとarrow検査としては機能しません。「文レベルarrowからの呼び出しを `ARROW_DEFINITION` で弾く」という保証とも一致しません。

修正案は、名前付き関数宣言を除外した後、arrow定義を呼び出し判定より先に処理することです。

```ts
const declared = DECLARATION.exec(line);
if (declared) {
  current = declared[1];
  lastOpenerWasNamed = true;
  continue;
}

if (ARROW_DEFINITION.test(line)) {
  lastOpenerWasNamed = false;
}

if (CALL.test(line) && current !== null && current !== "runSettled") {
  sites.push({
    line: index + 1,
    caller: current,
    fromNamedFunction: lastOpenerWasNamed,
  });
}
```

`runSettled(() => {` は行頭の変数宣言ではなく `ARROW_DEFINITION` に一致しないため、`addStep` / `addPoint` の検出には影響しません。これで次の両方を正しく扱えます。

```ts
const foo = (): void => { runSettled(...); };
```

```ts
const foo = (): void => {
  runSettled(...);
};
```

### 現行8件の確認

修正済みの宣言先行処理により、`function runSettled(...)` 自体は除外されます。対象は次の8件です。

- `addStep`
- `addPoint`
- `removeStep`
- `removePoint`
- `moveStepTo`
- `movePointTo`
- `undo`
- `redo`

現行の `onKeydown` / `onBeforeUnload` は最後の `runSettled` 呼び出しより後なので、現在の8件はいずれも `fromNamedFunction: true` です。

リスク表の訂正文にも問題ありません。上記の判定順だけ直せば、全体を承認できる状態です。