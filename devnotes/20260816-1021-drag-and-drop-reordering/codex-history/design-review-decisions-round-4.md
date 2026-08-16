# 対応マトリクス: design-review Round 4

判定は **APPROVED**（Critical 0 / Warning 0 / Suggestion 1）。全 7 施策が APPROVE。

## [Suggestion] 施策 7: ケース一覧の見出しが「R2 で追加が必須になった 3 ケース」のまま

- 判断: **対応する**（文書整合の修正のみ）
- 根拠: 表には R3-1〜R3-3 を足して 6 行あるのに、見出しが 3 ケースのままだった。
  見出しと中身が食い違うと、後から読む実装者が「3 つだけ足せばよい」と誤読する。
- 対応内容: 見出しを「**design-review R2 / R3 で追加が必須になった 6 ケース**」に修正した。

## Codex による最終確認（記録）

`dragOwner` の状態遷移が全経路で閉じていることを、Codex 側が表で確認した:

| 経路 | 結果 |
|---|---|
| owner が存在する状態で開始 | controller を呼ばず拒否。既存状態は不変 |
| controller が `false` を返す | owner・急所スコープとも不変 |
| step 開始受理 | `dragOwner = "step"` |
| point 開始受理 | owner と急所スコープを同じ同期処理内で確定 |
| commit | `try/finally` により処理中の例外でも解放 |
| cancel / 位置不変の drop / Escape / pointercancel | `onCancel: releaseDrag` で解放 |
| unmount | 両 controller の `destroy()` 後に明示的に解放 |

`isDragging()` の削除で失われる情報が無いことも確認された
（controller 内の排他 = `pointerId !== null` / controller 間の排他 = `start()` の受理結果と
`dragOwner` / UI 表示 = `PointerDragState` / 終了判定 = `finish()` と、責務が分かれているため）。

## 合議の総括

| ラウンド | 判定 | Critical | Warning | Suggestion |
|---|---|---|---|---|
| R1 | CHANGES_REQUESTED | 1（`$effect` → `onMount`） | 8 | 4 |
| R2 | CHANGES_REQUESTED | 1（多点入力でスコープすり替え） | 4 | 2 |
| R3 | CHANGES_REQUESTED | 1（controller 間の排他欠落） | 1 | 1 |
| R4 | **APPROVED** | 0 | 0 | 1（文書整合） |

反論したのは 1 件のみ（R1 の `noUncheckedIndexedAccess` に関する前提。実読で反証し、
Codex も R2 で「前提が過剰だった」と認めた）。それ以外は全件対応した。

**この合議で防いだ実害のあるバグ 2 件**（どちらも保存するまで気付かない種類のもの）:

1. **R2 Critical**: 急所ドラッグ中の 2 本目の指が、1 本目のドラッグ対象の手順をすり替える
2. **R3 Critical**: 手順ドラッグと急所ドラッグが同時に走り、先に確定した並べ替えによって
   後から確定する側の数値 index が別の手順を指す

いずれも iOS の多点入力で現実に起こる操作であり、撮影 PWA と PC 編集の両方が
タッチ端末で使われうる本アプリでは無視できない。
