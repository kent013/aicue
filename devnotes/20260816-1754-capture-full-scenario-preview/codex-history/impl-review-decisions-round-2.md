# 対応マトリクス: impl-review Round 2

## [Critical] 同一セッション内で `await tick()` をまたぐ古い `playActive()` が、前進後のクリップを再生する

- 判断: **対応する**
- 根拠: 指摘のとおりである。Round 1 の修正は `sessionId` を `tick()` 前に退避したが、
  **再生対象そのもの** (`activeSlot` / `slotGeneration[slot]` / `assignmentId[slot]`) は
  `tick()` の後に読み直していた。保留中に前進すると、古い呼び出しが新しいクリップに対して
  `play()` を重ねて呼び (= 同じ資源への二重再生要求)、その拒否は**現在世代と一致する**ため
  誤って `blocked` になる。詳細設計 S5 の「呼び出し時点の generation を closure へ退避してから
  `play()` する」という契約とも食い違っていた。
- 対応内容:
  - `playActive()` は **`await tick()` の前に 4 つ (session / slot / generation / assignment) を
    退避**し、再生の直前と `catch` の両方で `isCurrentTarget()` により照合する形にした。
    照合に落ちた呼び出しは `play()` を呼ばずに終わる (二重再生要求そのものを作らない)。
  - `assignmentId` も照合に入れたのは、同一 slot・同一世代でも要素を作り直した場合
    (別資源への割り当て直し) に旧要素へ `play()` しないためである。
  - テスト追加 (component):
    「tick 待ちの間に前進した古い再生要求は、新しいクリップを再生しない」。
    render 直後 (playActive が `await tick()` で保留中) に同期的に `ended` を発火させ、
    `play()` が**進んだ先のクリップに対して 1 回だけ**呼ばれることを、
    呼び出し先の要素の同一性まで含めて固定した。
  - **fail-first を実測で確認した**: `playActive()` を修正前の形 (tick 後に台帳を読み直す)
    へ戻すと、この 1 本だけが `played` が 2 件になって赤くなることを確認してから戻した。

## [Warning] close → reopen / replay の直接固定が無い (unmount → 再 render での検証になっている)

- 判断: **対応する**
- 根拠: 指摘のとおり、実運用は `bind:open` による**同一インスタンス**の開閉であり、
  unmount を挟む形はその経路を通っていない。`replay()` も同様に未固定だった。
- 対応内容: component テストを 2 本追加した。
  - 「同一インスタンスの close → reopen をまたぐ拒否も新セッションへ混入しない」
    (`rerender({ open: false })` → `rerender({ open: true })` で同一インスタンスを開閉する)
  - 「もう一度再生の後に届く旧セッションの拒否も混入しない」
    (終端 → `replay()` → 旧 `play()` を reject)

## [問題なし] `scenario-preview.ts` / lib テスト / page テスト

- 判断: **対応不要**
- 根拠: Round 2 で「前進経路を塞いでいない」「待機状態ガードを外すと赤くなる」ことを
  確認済みという判定を受けた。`retry` が `failed` / `placeholder` でも通る点は、
  現行 UI にその導線が無いため不整合にならないという判定も一致している
  (将来 `failed` に再試行導線を足すときは、`retry` が待機状態から `loading` へ戻す
  既存の遷移がそのまま使える)。
