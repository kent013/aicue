## 全体判定: CHANGES_REQUESTED

施策1〜5は承認できます。ケース6・7・8のJavaScript挙動と負の実装分類も正しくなりました。ただし施策6の `collectCallSites` は、現状のままでは `runSettled` 自身の宣言を呼び出しとして数え、8件ではなく9件になります。

### 施策1: APPROVE

`addPoint` の安定キー捕捉、実行時再解決、対象消失時の no-op は整合しています。採番・履歴・dirty判定への副作用もありません。

### 施策2: APPROVE

親と子を順に再解決する実装で、両方の失敗境界が適切に分離されています。

ケース7は子ガード欠落による `splice(-1, 1)` を確実に検出します。ケース8は親ガード欠落による `TypeError` と後続操作の消失を検出します。

### 施策3: APPROVE

`removeStep` と確認ダイアログの key 化は正しいです。ケース6も、2回目の削除による末尾手順の巻き添えと余分な履歴を検出できます。

### 施策4: APPROVE

訂正後のケース6・7・8はJavaScriptの実際の挙動と一致しています。

- ケース6: `steps.splice(-1, 1)` によりBが消える
- ケース7: `parent.points.splice(-1, 1)` によりA-2が消える
- ケース8: `steps[-1]` が `undefined` となり、`parent.points` で `TypeError`。drainが中断してBへの追加が失われる

8ケースで、数値index捕捉、手順ガード欠落、子ガード欠落、親ガード欠落をそれぞれ検出できます。

### 施策5: APPROVE

変種 `(a)/(b1)/(b2)/(b3)/(d)` は施策4・6と対応しています。各変種を独立して適用・復元する手順も、負のコントロールとして妥当です。

### 施策6: REQUEST_CHANGES

[Warning] `runSettled` の関数宣言自体が呼び出しとして数えられます。

現在は呼び出し判定を宣言判定より先に行っています。

```ts
if (CALL.test(line) && current !== null && current !== "runSettled") {
    sites.push(...);
}

const declared = DECLARATION.exec(line);
```

次の宣言行にも `CALL` が一致します。

```ts
function runSettled(action: () => void): void {
```

`runSettled` の直前に宣言された関数名が `current` に残っているため、この行はその関数からの9件目の呼び出しとして登録されます。`current !== "runSettled"` は宣言判定前なので防げません。

修正案:

```ts
for (const [index, line] of lines.entries()) {
    const declared = DECLARATION.exec(line);

    if (declared) {
        current = declared[1];
        lastOpenerWasNamed = true;
        continue;
    }

    if (CALL.test(line) && current !== null && current !== "runSettled") {
        sites.push({
            line: index + 1,
            caller: current,
            fromNamedFunction: lastOpenerWasNamed,
        });
    }

    if (ARROW_DEFINITION.test(line)) {
        lastOpenerWasNamed = false;
    }
}
```

`addStep` / `addPoint` の `runSettled(() =>` は関数宣言行ではないため、この順序でも正しく呼び出しとして数えられます。引数内のarrowは `ARROW_DEFINITION` に一致しないので、同じ行の呼び出し判定にも影響しません。

[Warning] assertionのコメントだけが保証範囲を超えています。

```ts
// arrow function / メソッドから直接呼ぶ形は帰属を偽装できるので弾く
```

現在検出するのは `ARROW_DEFINITION` に一致する文レベルarrowだけです。メソッド宣言は検出しておらず、テスト冒頭でもメソッドを保証外と正しく記載しています。

修正案:

```ts
// 検出対象の文レベル arrow function から直接呼ぶ形を弾く
```

またはメソッド検出を追加できますが、本テストをトリップワイヤに限定する方針ならコメント修正で十分です。

### 補足

[Suggestion] 全体リスク表の履歴検証について、ケース8はUndo履歴を直接検証していません。

```text
施策4の6・7・8件目がこれを直接検証する
```

これは「6・7が誤変異に伴う余分な履歴を検証し、8がno-op後のqueue継続を検証する」と分けると正確です。

PHPStan、DTO/JsonResource、Inertia Props、サーバ認可は引き続き該当なしで妥当です。DESIGN.md、Atomic Design、Lucide、`disabled` 規約にも問題はありません。