# 対応マトリクス: design-review Round 2

判定は **CHANGES_REQUESTED**（Critical 1 / Warning 4 / Suggestion 2）。
**全件対応した（反論なし）**。特に Critical は実際に成立するデータ整合性バグで、
設計の穴を突かれたものである。

## [Critical] 施策 4: 急所 D&D 中の 2 本目 pointerdown が対象手順をすり替える

- 判断: **対応する**（指摘が正しい。設計の欠陥）
- 根拠: 指摘の再現手順どおりに成立する。旧設計の `onPointHandleDown()` は
  **`start()` を呼ぶ前に** `pointListEl` / `pointDragStep` を書き換えていた。
  `start()` は進行中なら 2 本目を無視するが、**スコープはもう上書きされている**ため、
  1 本目の指を離した瞬間に「手順 A を掴んでいたのに手順 B の急所が並べ替わる」。
  iOS の多点入力では現実に起こる操作であり、しかも**サーバに保存されるまで気付かない**
  （シナリオ編集はローカル作業コピーなので、間違った並びのまま「シナリオを更新」される）。
- 対応内容:
  1. `PointerDragController.start()` の戻り値を `void` → **`boolean`**（受理したら true）に変更。
     型 doc に「受理されたときだけドラッグに紐づく状態を確定すること」を明記した。
  2. `onPointHandleDown()` を「候補を一時変数に受ける → `start()` を呼ぶ →
     **受理されたときだけ** `pointListEl` / `pointDragStep` へ反映」の順に書き換えた。
     拒否された場合は現在のスコープを一切触らない。
  3. テスト計画に R2-1（多点入力の競合）を追加。
     「手順 A で開始 → 手順 B のハンドルに 2 本目 → 1 本目で drop → **手順 A だけが動く**」

## [Warning] 施策 1: `moveItem` が `undefined` 要素を動かせない（generic 契約と実装の不一致）

- 判断: **対応する**
- 根拠: 指摘が正しい。`const moved = next[from]; if (moved === undefined) return next;` は
  **配列要素の値**を存在判定に使っており、`T` に `undefined` を含む型では
  正当な要素が no-op になる。export された generic 関数としては契約違反である。
  「本アプリでは使わない」というコメントで塞ぐのは、型で表現できることを散文に逃がしている。
- 対応内容: `const moved = next.splice(from, 1); next.splice(clamped, 0, ...moved);` に変更。
  `from` は直前に範囲検査済みなので戻り値は実行時に必ず 1 要素であり、
  `undefined` 要素も正しく動き、`noUncheckedIndexedAccess` にも依存しない。
  テストに「`Array<number | undefined>` でも正しく動く」ケースを追加した。
  （Round 1 の反論は Codex 側が「前提が過剰だった」と認めたが、
  結果としてより良い実装に落ち着いたので反論の有無に関わらずこの形を採る）

## [Warning] 施策 2/7: rAF を同期即時実行にすると自動スクロールのテストが無限再帰する

- 判断: **対応する**
- 根拠: 完全に正しい。`tickAutoScroll()` は末尾で次フレームを登録するので、
  stub が同期実行すると停止条件に到達できない。**テストが止まらなくなる**設計だった。
- 対応内容: テスト計画を「callback をキューに保存するだけの fake rAF に差し替え、
  テストから 1 フレームだけ明示実行する」形へ書き換え、コード例も設計に入れた。
  `cancelAnimationFrame` も併せて stub し、`afterEach` の `vi.unstubAllGlobals()` で戻す。

## [Warning] 施策 4: `runSettled` の遅延・実行時 no-op でも成功告知が出る

- 判断: **対応する**
- 根拠: 正しい。旧設計は `runSettled(...)` の**外**で `announce()` していたため、
  (a) IME 変換中は「まだ動いていない」のに読み上げ、
  (b) 実行時の再検査で no-op になっても読み上げていた。
  施策 5（テイク側）で「成功後にだけ告知する」と直したのと**同じ誤り**が
  シナリオ側に残っていた（対称性の欠落）。
- 対応内容: `moveStepTo` / `movePointTo` を
  「`runSettled(() => { 実行時再検査 → commitStructural(変異) → announce() })`」の形に変更。
  告知は実際に変異した経路の中だけで起きる。テスト計画に R2-2（IME 変換中の告知）を追加した。

## [Warning] 施策 6: rAF stub と pointer capture stub の後始末を分離せよ

- 判断: **対応する**
- 対応内容: 施策 2 のテスト計画に「global の差し替えは `afterEach` の
  `vi.unstubAllGlobals()` で戻す。`withoutPointerCapture` とは別の後始末なので混ぜない」と明記した。

## [Suggestion] 施策 2: 挿入位置が変わらないフレームでも `onState` を呼ぶのは無駄

- 判断: **対応する**
- 根拠: 毎フレームの通知は Svelte 側の更新を誘発し、`getBoundingClientRect` による
  強制レイアウトと合わさると端スクロール中に効く。
- 対応内容: `setInsertion(next)` を導入し、**値が変わったときだけ** `onState` を呼ぶ。
  ただし掴んだ直後の初回だけは `activeIndex` を UI へ伝える必要があるため必ず通知する
  （初回だけ特別扱いする理由をコードコメントに残した）。

## [Suggestion] 施策 4: 受け入れ条件 A2 の割り付け表が `$effect` のまま

- 判断: **対応する**
- 対応内容: 表の記述を `onMount` の cleanup に修正した。
