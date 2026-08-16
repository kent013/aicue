**レビュー仮説**  
設計の主リスクは D&D 自体より、Svelte 5 のリアクティビティ、TypeScript strict、既存の履歴・保存経路との接続部にあると見ます。成功条件は「off-by-one が固定される」「pointer 状態が必ず解放される」「既存の ▲▼ / undo / 保存 / PATCH 経路を壊さない」ことです。

## 施策別判定

### 施策 1: 並べ替え計算の純関数モジュール

**判定: REQUEST_CHANGES**

- [Warning] `moveItem()` の `const [moved] = next.splice(from, 1);` は TypeScript strict、特に `noUncheckedIndexedAccess` 有効時に `T | undefined` になり得ます。直前に範囲検査していても TS は splice 結果を 1 要素と推論しません。  
  修正案: `const moved = next[from]; if (moved === undefined) return next; next.splice(from, 1); ...` のように明示的に絞る、または `const removed = next.splice(...); const moved = removed[0]; if (moved === undefined) return next;` としてください。

- [Suggestion] `toFinalIndex()` は非整数・負数・`rows.length` 超過をそのまま返します。呼び出し側が範囲検査しているため致命的ではありませんが、純関数側の fail-safe 方針と揃えるなら clamp/整数チェックを明記するか、「正規化済み入力だけを受ける」と契約に書くべきです。

### 施策 2: Pointer Events のドラッグ制御

**判定: REQUEST_CHANGES**

- [Warning] `destroy()` が `finish(false)` を呼ぶため、通常終了後のコンポーネント破棄では問題ありませんが、ドラッグ中破棄時に `onCancel` が呼ばれます。ScenarioEditor / TakeStrip 側で `onCancel` に UI 状態の解除以外の副作用を足すと、unmount 時に意図しない副作用が出ます。  
  修正案: `finish(commit, notifyCancel = true)` のように分け、`destroy()` では UI 解放だけ行うか、callback 契約に「destroy 中も onCancel が呼ばれる」と明記してコンポーネント側テストに含めてください。

- [Warning] 自動スクロール中に `window.scrollBy()` しても、`insertion` は次の `pointermove` まで更新されません。iOS Safari で端スクロールして指を止めたまま drop すると、古い挿入位置で commit される可能性があります。  
  修正案: `tickAutoScroll()` 内で現在の `clientY` を保持して `insertionIndexFromRects(bounds(), lastClientY)` を再計算し、`onState` も更新してください。

- [Suggestion] `start()` 時点で `event.preventDefault()` しない設計は妥当ですが、ボタン要素なので drag 後に click が発火する可能性があります。現状 onclick が無いため実害は小さいものの、将来の回帰防止として DragHandle か controller 側で drag activated 後の click 抑止方針を明記するとよいです。

### 施策 3: ドラッグハンドル atom

**判定: APPROVE**

- [Suggestion] `ariaLabel` は「ドラッグ、または上下キー」と説明しており良いです。ただし button role のまま pointer drag を担うので、最低限 `ArrowUp` / `ArrowDown` のコンポーネントテストは必須です。設計には含まれているため問題ありません。

### 施策 4: シナリオ編集への配線

**判定: REQUEST_CHANGES**

- [Critical] `$effect` 内の callback が `stepListEl` / `pointDragStep` / `moveStepTo` などの `$state`・関数スコープ値を参照しています。Svelte 5 の dependency tracking は effect 実行中の同期 read が対象です。設計文では「同期的に読まないので再実行されない」とありますが、`rows: () => directRows(stepListEl)` の生成時に read されないとしても、関数・状態の扱いは実装次第で誤解を生みやすく、controller 多重生成のリスクがあります。  
  修正案: `onMount` / cleanup で controller を生成する設計に変更してください。browser-only lifecycle としても意図が明確で、D&D controller の生成は `$effect` より `onMount` が適しています。

- [Warning] 急所 D&D の `onCommit` 後始末が本文コードに入っておらず、注釈で「実装では末尾でも同じ 2 行」とされています。設計書としては不完全です。  
  修正案: `try/finally` で `pointListEl = null; pointDragStep = null;` を必ず実行する完成コードを設計に書いてください。

- [Warning] `movePointTo()` で `const points = steps[stepIndex]?.points;` を取り、その後 `steps[stepIndex].points = moveItem(points, from, to);` と再アクセスしています。TS strict では `steps[stepIndex]` が undefined でないことが保持されない可能性があります。  
  修正案: `const step = steps[stepIndex]; if (step === undefined) return; const points = step.points; ... step.points = moveItem(points, from, to);` にしてください。

### 施策 5: 撮影 PWA テイク列への配線

**判定: REQUEST_CHANGES**

- [Warning] `reorderTo()` が `announce()` を `void move(take, to)` より前に呼ぶため、PATCH が失敗しても「移動しました」と読み上げられます。これは UX とアクセシビリティ上の誤告知です。  
  修正案: 成功後に告知するには `move()` が成功/失敗を返すよう変更するか、`run()` を `Promise<boolean>` にして成功時のみ `announce()` してください。失敗時は既存 `role="alert"` のエラー表示に委ねる設計が妥当です。

- [Warning] 端での no-op PATCH 廃止は合理的ですが、既存挙動変更です。テスト計画にはありますが、設計上「busyTakeId が立たなくなる」「onChanged が呼ばれなくなる」ことも明示した方がよいです。  
  修正案: 端操作の期待を「通信なし、busy なし、再取得なし、aria-live 告知あり」と固定してください。

### 施策 6: pointer capture スタブ

**判定: REQUEST_CHANGES**

- [Warning] `Element.prototype.setPointerCapture = ...` は lib.dom の型上 readonly ではありませんが、テストで一時的に `undefined` にする計画は TypeScript 的に通りにくいです。`delete` もしないと書いていますが、代入で undefined 化するなら型エラーの可能性があります。  
  修正案: スタブ非対応ケースは controller 側を `setPointerCapture` の無いダミー currentTarget で開始する、または `Object.defineProperty` を使う helper を型付きで用意する、と設計してください。

### 施策 7: テストと受け入れ手順

**判定: APPROVE**

- [Suggestion] iOS 実機確認を devnotes に残す方針は妥当です。可能なら「実機確認ファイルの存在」を自動テストで強制するかどうかも判断してください。強制しないなら、完了条件としてレビュー時に人間が見る運用であることを明記するとよいです。

## 全体判定

**CHANGES_REQUESTED**

方向性は妥当です。特に「挿入 index」と「最終 index」を分ける設計、Pointer Events 採用、既存保存経路への合流は良い判断です。

ただし現状の詳細設計には、TypeScript strict で落ち得る箇所、Svelte の lifecycle に関する曖昧さ、TakeStrip の成功前告知という実害のある UX 問題があります。上記の Critical/Warning を反映すれば、実装に進める水準になります。