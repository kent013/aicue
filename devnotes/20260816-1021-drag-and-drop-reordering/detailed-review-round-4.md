Round 4 の所有権遷移を、開始拒否、確定、取消、例外、unmount の各経路で確認しました。排他は閉じています。

## 施策別判定

### 施策 1: 並べ替え純関数

**判定: APPROVE**

変更なし。`splice` + spread の契約、型安全性、off-by-one テスト計画に問題ありません。

### 施策 2: Pointer Events 制御

**判定: APPROVE**

`isDragging()` の削除で失われる必要情報はありません。

- controller 内の排他は `pointerId !== null`
- controller 間の排他は `start()` の受理結果と `dragOwner`
- UI 表示は `PointerDragState`
- 終了判定は `finish()`

それぞれ別の責務で満たされています。閾値超過後しか真にならない API を削除したことで、誤用余地も減っています。

### 施策 3: DragHandle atom

**判定: APPROVE**

変更による影響はありません。

### 施策 4: ScenarioEditor への配線

**判定: APPROVE**

`dragOwner` の状態遷移は全経路で閉じています。

| 経路 | 結果 |
|---|---|
| owner が存在する状態で開始 | controller を呼ばず拒否。既存状態は不変 |
| controller が `false` を返す | owner・急所 scope とも不変 |
| step 開始受理 | `dragOwner = "step"` |
| point 開始受理 | owner と急所 scope を同じ同期処理内で確定 |
| commit | `try/finally` により処理中の例外でも解放 |
| cancel / no-op drop / Escape / pointercancel | `onCancel: releaseDrag` で解放 |
| unmount | 両 controller の `destroy()` 後に明示的に解放 |

`destroy()` が `onCancel` を呼ばない契約とも整合しています。cleanup が `releaseDrag()` を直接呼ぶため、unmount 時に所有権や急所 scope は残りません。

`onCommit` 内で `moveStepTo()` / `movePointTo()` が例外を送出しても、`finally` が所有権を解放します。controller 自身も callback 前に pointer capture、rAF、内部状態を解放しているため、再入可能な状態へ戻っています。

開始前に共有状態を書き換える経路も残っていません。急所側の `list` はローカル変数であり、受理前には `pointListEl`、`pointDragStep`、`dragOwner` のいずれも変更されません。

### 施策 5: TakeStrip への配線

**判定: APPROVE**

controller は1つで外部 scope もないため、`isDragging()` 削除や `dragOwner` 導入の影響を受けません。`start()` の戻り値を捨てても、拒否時に controller 内部状態が変更されない契約により安全です。

### 施策 6: Pointer capture スタブ

**判定: APPROVE**

変更による影響はありません。

### 施策 7: テスト・受け入れ手順

**判定: APPROVE**

R3-1 / R3-2 が双方向の controller 間排他を検証し、R2-1 / R3-3 が同一 controller 内の排他を検証しています。1本目の結果まで確認するため、「2本目が見かけ上動かないだけで対象をすり替えている」回帰も検出できます。

[Suggestion] ケース一覧の見出しが「design-review R2 で追加が必須になった3ケース」のまま、表には R3-1〜R3-3 を含む6ケースがあります。「R2・R3 で追加されたケース」などへ直すと文書上の整合が取れます。実装可否を左右する指摘ではありません。

## 全体判定

**APPROVED**

Critical / Warning はありません。Round 3 で残っていた controller 間の多点入力競合は、受理時点の単一所有権、全終了経路での解放、双方向テストによって閉じています。