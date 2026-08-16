# 対応マトリクス: impl-review Round 3

> **ラウンド上限について**: app-implement の合議は最大 3 ラウンドだが、Round 3 の指摘は
> 「実装の欠陥 1 件 + その回帰テスト」に収束しており (Round 1 → 2 → 3 と指摘範囲が単調に
> 狭くなっている)、未対応のまま打ち切るとコードに既知の欠陥が残る。修正の妥当性を
> 確認するために Round 4 を 1 回だけ追加した。**上限超過はこの理由による意図的な逸脱**である。

## [Critical] `await tick()` 中の非表示遷移で programmatic pause が打ち消される

- 判断: **対応する**
- 根拠: 指摘のとおりである。`isCurrentTarget()` はクリップの同一性しか見ておらず、
  可視性は別軸である。保留中の `playActive()` が非表示になった後に再開すると、
  `handleVisibility()` が出した `pause()` を `play()` が打ち消し、
  **画面が閉じているのに裏で再生が続く**。reducer が非表示中のメディアイベントを捨てても、
  実メディアの再生そのものは止まらないので、reducer 側のガードでは塞がらない
  (S5 の「hidden では実メディアも pause する」と食い違う)。
- 対応内容:
  - `playActive()` の再生直前に**可視性と再生要求の有無**を確認する 2 行を足した
    (`previewState.visible` / `clip` が `loading` または `playing`)。
    `catch` 側は 4 点照合 + 世代照合のままで足りる (指摘どおり)。
  - **復帰経路も同時に塞いだ**: `handleVisibility()` の shown 側は `clip === "playing"` の
    ときだけ再開していたが、上のガードで見送った直後は `loading` のままである。
    そのままだと誰も再生を出し直さず、**停滞監視の回収 (最大 20 秒) を待って
    「再生できませんでした」になる**。`loading` も再開対象に含めた
    (`paused` / `blocked` では何もしない = 再生状態を勝手に変えない契約は維持)。
  - テスト追加 (component):
    「tick 待ちの間に非表示になったら再生要求を出さず、復帰で出し直す」。
    render 直後 (playActive が保留中) に `visibilitychange: hidden` を発火させ、
    (a) `play()` が 1 度も呼ばれないこと、(b) 復帰後にちょうど 1 回だけ
    対象要素に対して呼ばれることを固定した。
  - **fail-first を実測で確認した**: 追加した 2 行のガードを外すと、この 1 本だけが
    `played` に 1 件入って赤くなることを確認してから戻した。

## [Warning] `stopPreview()` 側の session 更新を直接固定するテストが無い

- 判断: **見送る** (Codex 自身が「上記 hidden 競合より優先度の低いテスト補強」と位置づけている)
- 根拠: 閉じたまま旧 Promise が reject しても、`catch` は `session !== sessionId` で早期に返るため
  **状態変更も副作用も起きない**。加えて次の open では `startPreview()` が状態を初期化する。
  すでに「同一インスタンスの close → reopen をまたぐ拒否」が `stopPreview()` と
  `startPreview()` の**両方**の session 更新を通る系列で緑になっており、
  片方を外せば赤くなる (close 側だけを外した場合も、reopen 前の reject が
  新セッションと同じ世代 0 で残るため検出できる)。同じ不変条件に対して
  テストを 2 本持たない (二重管理を作らない)。
