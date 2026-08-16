仮説は「Round 1 の修正自体は妿当だが、イベントの多重入力・遅延実行・rAF の再帰という時間軸に新しい不整合がないか」です。確認の結果、主要な修正方針は正しいものの、急所 D&D で別手順を並べ替え得る競合が残っています。

## 施策 1: 並べ替え計算の純関数

**判定: REQUEST_CHANGES**

[Warning] `moveItem<T>` が汎用関数である一方、`undefined` 要素だけ移動できない契約になっています。

```ts
const moved = next[from];
if (moved === undefined) return next;
```

これは型エラー回避ではなく、配列要素の値を存在判定に使っています。`T = undefined | DraftStep` のような型では、有効な要素でも no-op になります。「本アプリでは使わない」というコメントだけでは、export された generic 関数の型契約と実装が一致しません。

修正案は、値を取り出さず配列のまま移す方法です。

```ts
const moved = next.splice(from, 1);
next.splice(clamped, 0, ...moved);
```

`from` は直前に範囲検査済みなので `moved` は実行時に必ず1要素です。これなら `undefined` も正しく移動でき、`noUncheckedIndexedAccess` にも依存しません。

なお、Round 1 への反論は妥当です。現在の設定で分割代入が型エラーになる、という断定は成立しません。こちらの前提が過剰でした。

## 施策 2: Pointer Events 制御

**判定: REQUEST_CHANGES**

[Warning] 自動スクロールのテストで rAF を「即時実行」にすると無限再帰になります。

`tickAutoScroll()` は末尾で次の `requestAnimationFrame(tickAutoScroll)` を登録します。stub が callback を同期実行すると、停止条件へ到達する機会なく再帰し続けます。

修正案: callback をキューへ保存する制御可能な fake rAF にし、テストから1フレームだけ明示実行してください。

```ts
let frame: FrameRequestCallback | null = null;
vi.stubGlobal("requestAnimationFrame", (callback: FrameRequestCallback) => {
    frame = callback;
    return 1;
});

// pointermove 後
frame?.(performance.now());
```

[Suggestion] `tickAutoScroll()` で毎フレーム新しい state object を通知すると、挿入位置が変わっていなくても Svelte 側の更新が走ります。正しさには影響しませんが、強制レイアウトを伴う処理なので、前回値と異なる場合だけ `onState` を呼ぶ設計が適切です。

`finish(commit, notify)` の契約自体は妥当です。`destroy()` でも pointer capture、rAF、表示状態は解放され、利用者由来でない `onCancel` だけが抑止されています。

## 施策 3: DragHandle atom

**判定: APPROVE**

`onclick` を props から除外する判断、Lucide 利用、token ベースの class、disabled を使わない設計は整合しています。

## 施策 4: ScenarioEditor への配線

**判定: REQUEST_CHANGES**

[Critical] 急所ドラッグ中に2本目の pointerdown が来ると、最初のドラッグが別手順へ commit され得ます。

現在の順序は次のとおりです。

1. 1本目で手順Aの急所を開始し、controller が pointer A を保持する
2. 2本目で手順Bのハンドルを押す
3. `onPointHandleDown()` が `pointListEl` と `pointDragStep` を手順Bへ上書きする
4. controller の `start()` は「既に pointer がある」ため2本目を無視する
5. pointer A の drop が、上書きされた手順Bに対して `movePointTo()` を実行する

これは iOS の多点入力で別手順を誤変更するデータ整合性バグです。

修正案: `start()` を `boolean` 戻り値にし、開始を受理した場合だけ scope を確定してください。既存ドラッグの scope を上書きしないことが重要です。

```ts
readonly start: (index: number, event: PointerEvent) => boolean;
```

または controller 側へ、ドラッグごとの不変な context を渡して commit callback で返す設計でも構いません。テストには「別手順の2本目 pointerdown 後、1本目を drop しても最初の手順だけが移動する」を追加してください。

[Warning] `moveStepTo()` / `movePointTo()` は、IME により実処理が遅延または実行時 no-op になっても、呼び出し直後に成功告知します。

```ts
runSettled(() => /* 後で実行 */);
announce("移動しました"); // 今すぐ実行
```

実行時の再検査で no-op になった場合も嘘の告知になります。

修正案: 実際に代入した分岐内で告知するか、構造操作が実行されたかを戻り値として伝えてください。`runSettled` のキュー実行時に告知される必要があります。

`onMount` への変更は妥当です。window listener の所有期間と Svelte component の mount 期間が一致し、SSR 時にも実行されません。cleanup で両 controller と急所 scope を解放している点も正しいです。

[Suggestion] 受け入れ条件 A2 の割り付け表に、まだ「施策4・5（`$effect` の cleanup）」と残っています。`onMount` cleanup に修正してください。

## 施策 5: TakeStrip への配線

**判定: APPROVE**

`run(): Promise<boolean>` への変更は、提示された呼び出し関係では後退を起こしません。戻り値を無視する既存呼び出しは従来どおり動き、`finally` も戻り値を上書きしていません。

`reorderTo()` は成功後だけ告知し、失敗は既存の `role="alert"` に任せています。`onMount` cleanup と `finish(false, false)` の組み合わせにも不整合はありません。

既存4呼び出し側については、実装時に各テストが「成功時の `onChanged`」「失敗時の error」「busy 解放」を従来どおり通ることを確認すれば十分です。

## 施策 6: Pointer capture スタブ

**判定: APPROVE**

`Object.getOwnPropertyDescriptor()` と `finally` による復元は妥当です。生の `undefined` 代入による型エラーも避けられています。

ただし、施策2で指摘した rAF stub とは分離して復元し、テスト間で global state を漏らさないようにしてください。

## 施策 7: テストと受け入れ手順

**判定: REQUEST_CHANGES**

[Warning] 自動スクロールテストの「rAF を即時実行」はテストを停止不能にするため、制御可能な1フレーム実行へ修正が必要です。

[Warning] Critical の多点入力競合と、IME 遅延後に no-op となった場合の誤告知をテスト計画へ追加してください。

iOS 実機記録を機械的な存在検査にしない判断は妥当です。実機確認の事実とファイル存在を同一視しない説明も明確です。

## 全体判定

**CHANGES_REQUESTED**

Round 1 の中心的な修正である `onMount`、破棄と取消の分離、自動スクロール中の再計算、成功後の aria-live 告知は適切です。

実装前に必要な修正は次の3点です。

1. 急所 D&D の2本目 pointerdown で scope を上書きしない
2. `runSettled` の実行成功後にだけ ScenarioEditor の告知を出す
3. 自動スクロールテストの rAF を同期再帰ではなく手動フレーム制御にする