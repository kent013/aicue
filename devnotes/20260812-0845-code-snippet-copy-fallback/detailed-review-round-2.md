## 施策1: REQUEST_CHANGES

### [Critical] `clearOwnSelection()` の所有権判定が不十分

`selectionOwned` は「過去にこの component が選択を作った」ことしか示しません。利用者がその後、同じ `<code>` 内の一部分を選び直した場合も、

```ts
codeEl.contains(selection.getRangeAt(0).commonAncestorContainer)
```

が `true` になり、利用者自身の選択を破棄します。契約11の「別要素を選択」はこのケースを検出できません。

修正案: 作成した Range の境界を保持し、現在の Range と完全一致するときだけ解除してください。

```ts
let ownedRange: Range | null = null;

function selectCode(): boolean {
    // ...
    selection.removeAllRanges();
    selection.addRange(range);
    ownedRange = range.cloneRange();

    return true;
}

function clearOwnSelection(): void {
    const expected = ownedRange;
    ownedRange = null;

    if (expected === null) return;

    const selection = window.getSelection();
    if (selection === null || selection.rangeCount !== 1) return;

    const current = selection.getRangeAt(0);
    const isOwned =
        current.startContainer === expected.startContainer &&
        current.startOffset === expected.startOffset &&
        current.endContainer === expected.endContainer &&
        current.endOffset === expected.endOffset;

    if (isOwned) selection.removeAllRanges();
}
```

契約11に加えて「同じ `<code>` 内を利用者が部分選択し直した場合も奪わない」を追加してください。

### [Warning] 再試行開始時に前回の選択が残る

1回目が `manual-selected`、2回目の Clipboard API が保留中の場合、案内だけが消えて前回の選択は残ります。さらに2回目が Clipboard API で成功しても、その選択は解除されません。

これは設計上の不変条件、

> 選択が残っているのは手動コピーを促しているときだけ

に反します。

修正案: 新しい試行の冒頭で前回所有分を解除します。

```ts
const attempt = ++attemptId;
clearOwnSelection();
status = "idle";
```

契約8では案内だけでなく `selectedText()` も空であること、契約7では再試行成功後に選択が残らないことを固定してください。

### [Warning] unmount 後に保留中の試行が状態更新・タイマー登録できる

`onDestroy` で選択と既存タイマーを解除しても、保留中の `writeText()` が後から解決すると、現在の `attemptId` と一致したまま処理が続きます。その結果、破棄後に `markCopied()` が呼ばれ、新しい2秒タイマーが登録されます。

修正案: `onDestroy` で試行を無効化してください。

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

「保留中に unmount → Promise 解決後もタイマー登録・状態処理されない」契約も必要です。少なくとも fake timer の count、または `setTimeout` spy で確認できます。

### [Warning] `clearOwnSelection()` の失敗が legacy コピー成功を失敗扱いにしうる

`execCommand("copy")` が成功した後、`getRangeAt()` または `removeAllRanges()` が例外を投げると `copy()` 自体が reject し、`markCopied()` に到達しません。選択解除は後処理なので、コピー成功を覆してはいけません。

修正案: `clearOwnSelection()` 自体を例外非送出にし、先に所有状態を破棄してから Selection 操作を `try/catch` してください。

### [Suggestion] 「案内の解除は3つ」の記述を分離する

component 破棄時に追加した処理が解除するのは Selection です。案内DOMは通常の component 破棄で消えます。「表示状態の解除」と「所有Selectionの解除」を別々の不変条件として記述した方が正確です。

---

## 施策2: REQUEST_CHANGES

### [Warning] M2 は修正後も契約6を赤くしない

M2で `try/catch` だけを外しても、契約6では `document.execCommand` が未定義なので、次のガードで戻ります。

```ts
if (typeof document.execCommand !== "function") return false;
```

したがって例外は発生せず、契約6は緑のままです。

修正案: 次の契約を追加してください。

> `document.execCommand("copy")` が例外を投げても案内へフォールバックする

そのテストで throwing stub を使えば、`try/catch` 削除 mutation が確実に検出されます。契約6は `typeof` ガード、追加契約は例外隔離という別の責務です。

### [Warning] M8 は所有権の実装によっては契約11だけでは不十分

現在の M8 は「自分の選択か判定を外す」ため、別要素を選ぶ契約11で検出できます。ただし、Criticalで示した「同じ code 内の部分選択」は現在の判定そのものを通過します。

修正案: M8を次の2種類に分けるか、少なくとも両方の利用者選択をテストしてください。

- 所有権判定を完全に外す
- Range完全一致を `codeEl.contains(...)` に弱める

### [Warning] テスト本数と文書の記載が整合していない

以下が食い違っています。

- 施策一覧: 「新規5本」
- 契約表: 1〜11
- 表外: 契約12
- fail先行: 「新規11本と更新2本」

契約とテストケースは必ずしも1対1ではありませんが、現状は何本追加する設計なのか判断できません。

修正案: 「契約数」と「テストケース数」を分けて記載し、施策一覧も更新してください。「新規N本が落ちる」ではなく、fail-first時に未実装契約が期待どおり赤になることを記録する形が堅実です。

### [Suggestion] 契約8・9の保留Promiseテストはjsdomで実装可能

jsdom固有の障害はありません。手動deferredを使い、クリック処理を完了待ちしすぎないことが要点です。

```ts
let resolve!: () => void;
let reject!: (reason?: unknown) => void;

const pending = new Promise<void>((res, rej) => {
    resolve = res;
    reject = rej;
});
```

`fireEvent.click()` は開始後のDOM更新を `act()`でflushし、保留中の Promise 自体を await しない構成にします。契約9では呼び出し回数ごとに異なる Promise を返す mock が必要です。各 deferred はテスト終了前に必ず resolve/rejectし、未処理 rejection を残さないでください。

契約11の外部選択対象は、component の `unmount` に巻き込まれないよう `document.body` 配下に別途置く必要があります。

---

## 全体判定: CHANGES_REQUESTED

Round 1の主要指摘には正しい方向で対応しています。4値enum、legacy成功時の選択解除、`attemptId`、保留Promiseによる中間状態テストはいずれも妥当です。

残る中心課題は、Selectionの所有権を「code内にあるか」ではなく「componentが作ったRangeと同一か」で判定すること、再試行・破棄と保留Promiseを含めてSelectionと非同期処理のライフサイクルを閉じること、M2を実際に赤化できる契約へ直すことです。