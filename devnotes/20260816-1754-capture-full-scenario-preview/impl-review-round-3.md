## ファイル別判定

### `resources/js/components/features/capture/ScenarioPreviewDialog.svelte`

[Critical] `isCurrentTarget()` はクリップの同一性を閉じていますが、可視性を確認しないため、`await tick()` 中の非表示遷移で programmatic pause が破られます。

具体系列:

1. `startPreview()` または `syncDestination()` が `playActive()` を開始し、`await tick()` で保留。
2. 解決前に `visibilitychange` で hidden になる。
3. `handleVisibility()` は現在の video を `pauseProgrammatically()` し、state を `visible: false` にする。
4. 保留中の `playActive()` が再開する。
5. session / slot / generation / assignment は変わっていないため、`isCurrentTarget()` が `true`。
6. 非表示中にもかかわらず `video.play()` が呼ばれ、直前の pause を打ち消す。

これはS5の「hiddenでは実メディアもpauseする」と食い違います。Reducerが非表示中のイベントを捨てても、実メディアのバックグラウンド再生自体は止まりません。

再生直前の条件に、少なくとも次を追加する必要があります。

```ts
previewState.visible &&
(previewState.clip === "loading" || previewState.clip === "playing")
```

可視性は assignment の同一性とは別軸なので、`isCurrentTarget()`へ含めるか、`playActive()`の直前で明示的に確認するのが妥当です。catch側は現在の4点照合とgeneration照合で問題ありません。

追加テストとして、初回`playActive()`が`tick()`待ちの間に`visibilitychange: hidden`を発生させ、`play()`が一度も呼ばれないことを固定してください。その後shownに戻した際の期待動作も確認すると、再生不能を作っていないことまで守れます。

一方、Round 2で指摘した同一セッション内の前進競合は解消されています。session / slot / generation / assignment の4点は、close/reopen、replay、index前進、同一slot再割り当てを適切に区別しています。

### `tests/js/components/features/capture/ScenarioPreviewDialog.test.ts`

[Warning] 新規3テストは、それぞれ以下の回帰を検出できています。

- 同一インスタンスclose/reopen: `startPreview()`によるsession更新を外すと赤くなる
- replay: replay時のsession更新を外すと赤くなる
- tick中の前進: tick前のtarget退避、または直前の4点照合を外すと`play()`が2回になり赤くなる

特に前進テストは呼び出し先videoの同一性まで見ており、有効なbehavioral testです。

ただし、上記のhidden競合が未固定です。既存の非表示テストは`playActive()`のtick待ちが終わった後にhiddenへ遷移しているため、この競合を検出しません。

また、`stopPreview()`側のsession更新を直接固定するなら、「closeしたまま旧Promiseをrejectし、reopen前にも状態変更や副作用が起きない」系列が必要です。ただし次回open時には`startPreview()`が状態を初期化するため、これは上記hidden競合より優先度の低いテスト補強です。

### `resources/js/lib/capture/scenario-preview.ts`

問題ありません。Round 2の判定を維持します。

### `tests/js/lib/capture/scenario-preview.test.ts`

問題ありません。Round 2の判定を維持します。

### その他のT191変更ファイル

今回変更提示のないファイルについて、Round 2までの判定を維持します。DTO、JsonResource/Inertia、PHPStan、署名URL・ACK発行条件、権限、DS token、Atomic Designに新たな問題はありません。

提示された検証結果とfail-first結果は確認しました。指示に従い、こちらではコマンドを実行していません。

**全体判定: CHANGES_REQUESTED**