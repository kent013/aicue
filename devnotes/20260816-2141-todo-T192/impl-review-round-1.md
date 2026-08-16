**Critical**
なし。

**Warning**
`resources/js/components/features/manual/TakePreviewPanel.svelte`  
静止画 `<img>` の読み込み失敗状態が `take?.id` 変更でしかリセットされません。署名 URL の再取得などで同じ take の `playbackUrl` だけが変わった場合、前回の失敗表示が残り続けます。`TakePreviewDialog.svelte` と同じく `$effect` で `playbackUrl` も購読して `imageFailed = false` に戻してください。

`tests/Architecture/FfmpegProcessLaunchInventoryTest.php`  
`FfmpegSafetyArguments::all()` の検査が「ファイル内に import と呼び出し文字列があるか」だけなので、同じファイルに新しい ffmpeg/ffprobe 起動を追加して安全引数を付け忘れても通ります。特に `PipelineSmokeCommand.php` は複数起動点を持つため、ファイル単位 pin では弱いです。各 `Process::run([...])` の argv 先頭を検査するテスト、または `PipelineSmokeCommand` 用の argv テストを追加してください。

`resources/js/components/features/manual/TakeFileUpload.svelte`  
PC 側の静止画アップロード経路に component テストがありません。`CaptureFileFallback` には「accept=image/*」「正規化失敗時に原本を送らない」テストがありますが、PC 経路は同じ重要契約を持つのに未固定です。`material="still"` で正規化 blob を `image/jpeg` として送ること、`readDurationMs()` を通さないこと、正規化失敗時に enqueue しないことを追加してください。

**Suggestion**
`resources/js/types/capture.ts` / `resources/js/types/manual.ts` / `tests/Architecture/ManualEnumTsSyncInvariantTest.php`  
`MaterialType` と `CutMaterialType` が同じ値集合を別名で重複定義されています。現状は一致していますが、sync test は `CutMaterialType` だけです。片方を再利用するか、capture 側 `MaterialType` も enum sync の対象にすると drift を防げます。

`tests/Feature/Manual/StillMaterialConsistencyTest.php`  
C5 と誤申告失敗はよく固定されています。一方で S8 の C1 still/still は分割テストで概ね覆われていますが、「登録された still take が render manifest で TakeStill になる」通しの 1 本があると、S1/S2/S3 の接続点がより明確になります。

**問題なし**
S1/S2 の `takes.material_type`、分類器、FormRequest の `missing`、予約行 `content_type` 由来の確定は設計に沿っています。tenant/ownership key を payload から受ける変更も見当たりません。

S3/S4 の `EffectiveMaterialType`、`StillDisplayDuration`、`takeSourcePath` 改名、`-max_alloc` 一律付与の方向性は妥当です。`PipelineSmokeCommand` を母集団に含めた逸脱も、例外を作らず同じ安全境界に通しており妥当です。

DTO/JsonResource パターン上、`response()->json()` の追加は見当たりません。フロントも Lucide アイコンのみで、hex 直書きや disabled による必須条件ブロックは増えていません。

 docs/architecture.md は「保証しないもの」を比較的正確に書いており、`takes.material_type` が実体検証ではなく申告分類である点も誇張していません。

CHANGES_REQUESTED