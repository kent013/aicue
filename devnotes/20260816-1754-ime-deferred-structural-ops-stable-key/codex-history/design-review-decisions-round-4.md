# 対応マトリクス: design-review Round 4

全体判定 **CHANGES_REQUESTED**。施策 1-5 は APPROVE 継続。施策 6 のみ Warning 1 件。
**対応する** (反論なし)。

## [Warning] 施策 6: 1 行で書かれた arrow 定義からの呼び出しを `fromNamedFunction` で弾けない

> `const foo = (): void => { runSettled(...); };` は、`CALL` 判定の時点で
> `lastOpenerWasNamed` がまだ直前の名前付き関数の状態のままなので true で登録される。

- 判断: **対応する (こちらの順序ミス)**
- 根拠: 正しい。Round 3 で「宣言行を先に処理する」ところまでは直したが、
  `ARROW_DEFINITION` の状態更新は依然として `CALL` 判定の**後**に置いていた。
  そのため 1 行に収まった arrow 定義では、その行の呼び出しが
  「直前の名前付き関数からの呼び出し」として `fromNamedFunction: true` で登録される。
  件数が 9 になるので**この追加だけなら赤くはなる**が、
  Codex の言うとおり「既存呼び出しの削除と同時に足された」場合は件数が相殺され、
  arrow 検査としては機能しない。**設計が主張している保証と実装が一致していない**状態だった。
  施策 5 の負のコントロール (d2) は、まさにこの形を実測する手順なので、
  直さないと (d2) が「検出できない」という結果になり、
  せっかく塞いだつもりの穴が塞がっていないことになる。
- 対応内容: Codex 提示の順序へ変更した。1 反復の中の処理順を
  **(1) 名前付き function 宣言 → `continue` / (2) `ARROW_DEFINITION` で帰属の信用を落とす /
  (3) 呼び出し判定** とした。
  `runSettled(() => {` は行頭の変数宣言ではないため `ARROW_DEFINITION` に一致せず、
  `addStep` / `addPoint` の検出には影響しない (Codex も同じ結論)。
  これで 1 行形式・複数行形式のどちらの arrow 定義も同じく弾ける。

## Codex が確認した事実 (追認)

- 修正後の走査は現行 `ScenarioEditor.svelte` に対して **ちょうど 8 件**
  (`addStep` / `addPoint` / `removeStep` / `removePoint` / `moveStepTo` / `movePointTo` /
  `undo` / `redo`) を返し、**全件 `fromNamedFunction: true`** になる。
- `onKeydown` (L585) / `onBeforeUnload` (L845) は最後の `runSettled` 呼び出し (L519) より
  後ろにあるため、現時点で誤検出を起こさない。
- リスク表の訂正文にも問題なし。
