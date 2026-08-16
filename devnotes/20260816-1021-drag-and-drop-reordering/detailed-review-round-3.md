仮説は「controller 単体の排他が、コンポーネント全体の排他として成立しているか」です。確認結果、`pointDragCtl` 内のスコープすり替えは閉じましたが、`ScenarioEditor` の2つの controller 間に同種の穴が残っています。

## 施策 1: 並べ替え純関数

**判定: APPROVE**

`splice` の戻り値を spread する実装に問題はありません。

- `undefined` を含む要素も移動できる
- 入力配列を変更しない
- `from` の範囲検査後なので削除数は必ず1
- 前方・後方移動とも `to` は最終 index として正しく機能する

追加された `Array<number | undefined>` のテストも適切です。

## 施策 2: Pointer Events 制御

**判定: APPROVE**

`start(): boolean` は同一 controller 内の多点入力を正しく拒否し、拒否時には `fromIndex`、`pointerId`、`handle` などを書き換えません。

`setInsertion()` の間引きも妥当です。最初の有効な `pointermove` では無条件に active state を通知し、それ以降は insertion index が変化した場合だけ通知します。終了時の `{ null, null }` は別経路で必ず通知されるため、表示状態が残る問題もありません。

rAF の手動1フレーム実行への変更も正しいです。

## 施策 3: DragHandle atom

**判定: APPROVE**

Round 2 から新しい問題はありません。Atomic Design、Lucide、DS token、disabled 禁止の各条件に適合しています。

## 施策 4: ScenarioEditor への配線

**判定: REQUEST_CHANGES**

[Critical] 手順用と急所用が別 controller なので、両者は同時にドラッグを開始できます。

現在閉じられているのは、次の経路です。

```text
pointDragCtl の1本目 → pointDragCtl の2本目を拒否
```

しかし、次の経路は拒否されません。

```text
stepDragCtl で手順Aを開始
  → pointDragCtl で手順A内の急所を開始
  → 手順を先にdropして steps の順番を変更
  → 急所をdrop
  → 古い pointDragStep の数値 index が別の手順を指す
```

逆方向の「急所ドラッグ中に手順ドラッグを開始」も成立します。各 controller は自身の `pointerId` しか知らないため、互いの進行状態を排他できません。

修正案: `ScenarioEditor` 全体で共有する owner を設け、どちらか一方だけ開始できるようにしてください。

```ts
type DragOwner = "step" | "point";
let dragOwner: DragOwner | null = null;
```

開始処理は以下の順序にします。

1. `dragOwner !== null` なら拒否
2. 対象 controller の `start()` を呼ぶ
3. `true` の場合だけ owner と急所 scope を確定
4. `onCommit` / `onCancel` / unmount の全経路で owner を解放

step controller にも `onCancel` が必要です。commit callback も `try/finally` で owner を解放してください。

テストには少なくとも次を追加してください。

- 手順ドラッグ中に急所の pointerdown を行っても、急所ドラッグは始まらない
- 急所ドラッグ中に手順の pointerdown を行っても、手順ドラッグは始まらない
- 拒否された2本目の操作が、1本目の対象や結果を変更しない

`onPointHandleDown()` 単体の順序は正しくなっています。候補の `list` はローカル変数に留まり、`start()` が拒否した場合に共有状態を変更していません。

[Suggestion] owner の排他は `isDragging()` ではなく、pointerdown を受理した時点から有効にしてください。`isDragging()` は閾値超過後だけ true なので、閾値未満の待機中に2本目を受理してしまいます。

`announce()` を `runSettled` 内へ移した変更は妥当です。境界告知を外に置く非対称も正しいです。境界告知は「変更成功」の主張ではなく、その時点の要求を拒否した理由だからです。

## 施策 5: TakeStrip への配線

**判定: APPROVE**

TakeStrip は controller が1つだけで、ドラッグに付随する外部 scope もありません。2本目は `start()` 内で拒否され、`fromIndex` も書き換わらないため、ScenarioEditor と同種の controller 間競合はありません。

`void dragCtl?.start(...)` は boolean 戻り値を意図的に捨てる表現として問題ありません。

## 施策 6: Pointer capture スタブ

**判定: APPROVE**

変更内容と後始末の分離に問題はありません。

## 施策 7: テスト・受け入れ手順

**判定: REQUEST_CHANGES**

[Warning] R2-1 は point controller 内の競合だけを検証しており、step controller と point controller の同時開始を検出できません。

修正案: 施策4で示した双方向の controller 間排他テストを追加してください。少なくとも一方向だけでなく、両方の開始順を固定する必要があります。

それ以外のテスト計画、IME 遅延告知、rAF 手動実行、実機確認の扱いは妥当です。

## 全体判定

**CHANGES_REQUESTED**

Round 2 の各修正は、その対象範囲では正しく実装されています。残る変更は、`ScenarioEditor` の `stepDragCtl` と `pointDragCtl` をまたぐコンポーネント全体の排他制御と、その双方向テストです。ここを閉じれば、今回提示された設計について追加の Critical/Warning はありません。