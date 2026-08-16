**レビュー結果**

[resources/js/components/features/capture/ScenarioPreviewDialog.svelte](/workspace/.claude/worktrees/tasks/T191/resources/js/components/features/capture/ScenarioPreviewDialog.svelte:198)

[Critical] close/reopen または replay をまたいだ古い `play()` rejection が、新しい再生セッションへ混入します。  
`playActive()` の `catch` は捕捉した `generation` と現在の `previewState.generation` だけを比較していますが、`startPreview()` は毎回 `generation: 0` で再初期化し、`stopPreview()` / close 時に世代を進めていません。

具体系列:

1. open 直後の generation 0 で `video.play()` が pending
2. close して teardown
3. すぐ reopen して新セッションも generation 0
4. 旧セッションの `play()` が `NotAllowedError` reject
5. `generation !== previewState.generation` が false になり、新セッションが `blocked` になる

詳細設計 S5 の「閉じる時に世代を進めてから `onClose()`」という契約と食い違います。`sessionId` / monotonically increasing generation / close 時の invalidation のいずれかが必要です。

[resources/js/lib/capture/scenario-preview.ts](/workspace/.claude/worktrees/tasks/T191/resources/js/lib/capture/scenario-preview.ts:156)

[Critical] `failed` 状態が terminal な表示待ちになっておらず、同一世代の `progress` / `playing` で復帰または延命できます。  
`error` や stall で `failed` になった後も、`progress` は `progressAt` を更新し、`playing` は `clip: "playing"` に戻します。

具体系列:

1. `loading` が stall timeout で `failed`
2. failed 表示中に同一 video から `progress` が断続的に届く
3. `progressAt` が更新され続ける
4. `placeholderSeconds` 経過判定が満たされず、次の entry へ進まない

これは S4/S5 の「1 本の失敗で止まらない」「failed は placeholderSeconds だけ見せて次へ進む」に反します。`failed` / `placeholder` 中は media-origin event を無視する、または `failedAt` と `progressAt` を分離する必要があります。

[tests/js/components/features/capture/ScenarioPreviewDialog.test.ts](/workspace/.claude/worktrees/tasks/T191/tests/js/components/features/capture/ScenarioPreviewDialog.test.ts:1)

[Warning] 上記の close/reopen 後の遅延 `play()` rejection を固定するテストがありません。現在の「拒否後も閉じられる」は即時 rejection だけで、世代 0 の再利用問題を検出できません。

[tests/js/lib/capture/scenario-preview.test.ts](/workspace/.claude/worktrees/tasks/T191/tests/js/lib/capture/scenario-preview.test.ts:1)

[Warning] `failed` 後に同一世代の `progress` / `playing` が来ても、failed 表示待ちが延びないことを固定するテストがありません。現状のテストは `failed → tick → advance` の素直な系列だけを見ています。

[tests/js/pages/CaptureShow.test.ts](/workspace/.claude/worktrees/tasks/T191/tests/js/pages/CaptureShow.test.ts:995)

[Suggestion] 「録画中のエラー文言は個別 preview と同じ言い回し」とテスト名にありますが、 assertion は末尾の共通文言だけです。意図が「同一制約を同じ言葉で説明する」なら、共通定数化するか全文一致で固定した方がよいです。

**問題なし**

- `app/Services/Manual/AdoptedReadyTakeCoverage.php`: S1 の単一述語化は設計どおりです。
- `app/DataTransferObjects/Capture/CaptureCutData.php`: DTO shape 追加、`has_thumbnail` docblock 補正、`response()->json()` 不使用は妥当です。
- `app/DataTransferObjects/Capture/CaptureManualDetailData.php`: ready 判定委譲、非 ready への署名 URL / ACK 抑止、eager load は設計に沿っています。
- `app/Http/Controllers/Capture/CaptureManualController.php`: `previewPlaceholderSeconds` props 追加は S3 どおりです。
- `app/Support/Security/AdoptedTakeReferenceInventory.php`: `DelegatedToCoverage` への変更は実装実態と一致しています。
- `resources/js/types/capture.ts`: `adopted_ready_take_id` 追加は S2 どおりです。
- `resources/js/pages/Capture/Show.svelte`: Atomic Design の import 方向、lucide 使用、disabled 回避、DS token 利用に大きな問題は見当たりません。
- `doc/05_スマホアプリ機能仕様.md` / `docs/architecture.md`: S7 の契約説明として妥当です。

提示されたテスト結果は確認しましたが、ユーザー指示に従い再実行はしていません。

**全体判定: CHANGES_REQUESTED**