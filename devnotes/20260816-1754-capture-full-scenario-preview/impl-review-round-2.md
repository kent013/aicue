## ファイル別判定

### `resources/js/components/features/capture/ScenarioPreviewDialog.svelte`

[Critical] `sessionId` によりセッション境界は閉じましたが、同一セッション内で `await tick()` をまたぐ古い `playActive()` が、前進後のクリップを再生する経路が残っています。

`playActive()` は `sessionId` だけを `tick()` 前に退避し、`activeSlot` と `generation` は `tick()` 後に読み取っています。

具体的な系列:

1. clip A で `playActive()` を呼ぶ。`await tick()` で保留。
2. tick 解決前に利用者が `skip`。
3. `syncDestination()` が clip B を割り当て、B 用の `playActive()` を呼ぶ。
4. A 起点の古い `playActive()` が再開し、現在の slot・generation、つまり B を読み取って `video.play()`。
5. B 起点の `playActive()` も同じ B に対して `video.play()`。
6. 一方が成功しても、もう一方が `NotAllowedError` になれば、現在世代として `blocked` が適用される。

これは詳細設計の「`play()` 呼び出し時点の generation を closure へ退避」に一致していません。`sessionId` は close/reopen・replay を識別しますが、同じセッション内の index 前進は識別しません。

`tick()` 前に少なくとも以下を退避し、`tick()` 後にも一致を確認する必要があります。

- `sessionId`
- `activeSlot`
- `slotGeneration[slot]`
- 必要なら割り当てを識別する `assignmentId[slot]`

その後、退避した slot の要素に対してだけ `play()` し、catch でも session・generation・assignment を照合してください。

追加テストは「`playActive()` が tick 待ちの間に skip した場合、旧呼び出しが次クリップを二重に `play()` せず、その拒否が次クリップを blocked にしない」を固定する必要があります。

なお、質問1のうち close/reopen・replayをまたぐ経路については、`startPreview()` と `stopPreview()` の両方で単調増加させる実装により閉じています。

### `resources/js/lib/capture/scenario-preview.ts`

問題ありません。

`failed` / `placeholder` でメディア由来イベントだけを落とし、`tick`、`skip`、可視性イベントは通しているため、前進経路は塞がれていません。非表示期間を表示尺へ算入しない既存契約も維持されています。

`retry` も通りますが、現状のUIでは `failed` / `placeholder` に retry 導線がないため、不整合にはなっていません。

### `tests/js/components/features/capture/ScenarioPreviewDialog.test.ts`

[Warning] 追加された旧セッション rejection テストは、`sessionId` のガードを外せば赤くなるため、実装の追認だけにはなっていません。

ただし、テストは旧コンポーネントを unmount して新しいコンポーネントを render しています。実運用の `bind:open` による同一インスタンスの close → reopen、および `replay()` の検証にはなっていません。実装上はどちらも `sessionId` で防げていますが、中心契約として次も直接固定した方がよいです。

- 同一コンポーネントで `open: true → false → true`
- replay 後に旧 `play()` が reject
- `await tick()` 中に skip する同一セッション競合

最後のケースは上記 Critical を検出するため必須です。

`failed` 中に継続する `timeupdate` のテストは、待機状態ガードを外すと前進しなくなるため有効です。

### `tests/js/lib/capture/scenario-preview.test.ts`

問題ありません。

`failed` の `progress` / `playing` と、`placeholder` の `ended` / `error` を含めており、待機状態ガードの削除や対象イベントの不足で赤くなります。尺満了後の前進まで確認しているため、単なる状態固定のテストにもなっていません。

### `tests/js/pages/CaptureShow.test.ts`

問題ありません。

通し再生側の全文と共通節の双方を固定しており、Round 1 の Suggestion は解消されています。

### Round 1 で問題なしとしたその他のファイル

変更提示がないため判定は維持します。

- `AdoptedReadyTakeCoverage.php`: 問題なし
- `CaptureCutData.php`: 問題なし
- `CaptureManualDetailData.php`: 問題なし
- `CaptureManualController.php`: 問題なし
- `AdoptedTakeReferenceInventory.php`: 問題なし
- `resources/js/types/capture.ts`: 問題なし
- `resources/js/pages/Capture/Show.svelte`: 問題なし
- `doc/05_スマホアプリ機能仕様.md`: 問題なし
- `docs/architecture.md`: 問題なし

提示された検証結果は確認しました。指示に従い、こちらではコマンドを実行していません。

**全体判定: CHANGES_REQUESTED**