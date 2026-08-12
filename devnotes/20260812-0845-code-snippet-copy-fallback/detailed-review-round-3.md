## 施策1: REQUEST_CHANGES

### [Warning] `range.cloneRange()` が例外非送出境界の外にある

`selectCode()` では、選択を張った後の `range.cloneRange()` が `try/catch` の外です。

```ts
selection.removeAllRanges();
selection.addRange(range);
// ここが投げると、選択だけ残して copy() が reject する
ownedRange = range.cloneRange();
```

この場合、案内も成功表示も出ず、所有権を記録していない選択だけが残ります。

修正案: cloneを既存選択へ触る前に作成してください。

```ts
let range: Range;
let owned: Range;

try {
    range = document.createRange();
    range.selectNodeContents(codeEl);
    owned = range.cloneRange();
} catch {
    return false;
}

try {
    selection.removeAllRanges();
    selection.addRange(range);
} catch {
    return false;
}

ownedRange = owned;

return true;
```

これにより `createRange`、`selectNodeContents`、`cloneRange` の失敗時には既存選択を維持できます。

### [Warning] `onDestroy` の具体的な実装が変更後コードに含まれていない

設計判断と契約15では次の動作を要求していますが、提示された変更後コードには登録処理がありません。

- `attemptId++`
- `clearOwnSelection()`
- timeout解除
- `timeoutId = undefined`

修正案: 詳細設計の実装コードとして明示してください。

```ts
onDestroy(() => {
    attemptId++;
    clearOwnSelection();

    if (timeoutId !== undefined) {
        clearTimeout(timeoutId);
        timeoutId = undefined;
    }
});
```

設計文だけでなく、実装対象コードに含まれていることを確認できれば十分です。

### [Suggestion] Rangeの4点一致は実用上妥当

`Range` と `cloneRange()` はDOM変更に追従するlive rangeなので、テキストノードの分割・結合・正規化が起きた場合、両方の境界が同様に補正されることが期待されます。ただし、DOM標準に「どの操作でどの境界点になるか」は定められていても、Selectionを誰が変更したかという所有者情報はありません。

したがって、利用者が偶然まったく同じ4境界を選び直した場合は、自動選択との区別が不可能です。これは残留制約として受容可能です。同じ範囲が維持されている以上、実害も限定的です。

再試行冒頭・legacy成功時・破棄時の3箇所で解除する配置にも問題ありません。`clearOwnSelection()` が冪等かつ例外非送出であるため、呼び出しの重複による副作用はありません。

---

## 施策2: REQUEST_CHANGES

### [Warning] M1は契約14を赤くしない

契約14は最初から `window.getSelection()` を `null` にしているため、正常実装でも `selectCode()` は `false` です。`selectCode()` を常に `false` にしても結果は変わらず、契約14は緑のままです。

修正案:

```text
M1 → 最低でも契約3が赤くなる
```

文面分岐はM7が十分に固定しています。

### [Warning] M3はmutationとして検出不能

`typeof` ガードだけを削除しても、未定義関数の呼び出しによる例外を `try/catch` が拾うため、契約6の観測結果は変わりません。設計書自身も「赤くならない可能性が高い」としていますが、これはmutation計画の目的を満たしません。

修正案は次のいずれかです。

- M3を計画から削除し、`typeof` ガードは防御的な早期returnであって独立した振る舞い契約ではないと整理する。
- `typeof` ガードと `try/catch` を同時に除去するmutationにする。ただしM2との責務重複を明記する。

前者が簡潔です。契約6は「未定義でも案内へ落ちる」という外部契約を十分固定しています。

### [Warning] `clearOwnSelection()` の例外非送出契約がテストされていない

Round 2で追加した重要な判断ですが、契約15件の中にはSelection解除が失敗してもlegacy成功を維持するテストがありません。実装を将来整理した際、`try/catch` が外れても検出できません。

修正案: 次の契約を追加してください。

> legacy成功後のSelection解除が例外を投げても「コピー完了」を表示する

`removeAllRanges()` を呼び出し回数で分岐させ、`selectCode()` 時は成功、`clearOwnSelection()` 時だけthrowさせれば検査できます。対応mutationは「`clearOwnSelection()` の `try/catch` を削除」です。

### [Suggestion] 契約15のタイマー検査は差分で見る

Svelteやテスト環境が内部でタイマーを使う可能性があるため、`setTimeout` の総呼び出し回数をゼロと断定するより、unmount直前の呼び出し回数を基準にしてください。

```ts
const countBeforeResolve = setTimeoutSpy.mock.calls.length;
resolvePending();
await act(async () => {
    await pending;
});
expect(setTimeoutSpy).toHaveBeenCalledTimes(countBeforeResolve);
```

## 全体判定: CHANGES_REQUESTED

所有Rangeの4点一致、3箇所での選択解除、非同期試行の世代管理は実装可能な設計になっています。残件は局所的ですが、`cloneRange()` の例外経路は実際に状態不整合を作るため、実装着手前に修正が必要です。

あわせてM1・M3の予測を直し、`clearOwnSelection()` の例外非送出を契約化すれば、実装着手可能な水準です。