# Round 3: Round 2 指摘への対応

Round 2 の指摘 (Critical 0 / Warning 2 / Suggestion 1) をすべて捌いた。

# 対応マトリクス: impl-review Round 2

Codex 判定: **CHANGES_REQUESTED** (Critical 0 / Warning 2 / Suggestion 1)

## [Warning] TakePreviewPanel の境界条件 (take 同一 / URL のみ変化) に回帰テストが無い

- 判断: **対応する。ただし指摘された形 ($effect の購読 + それを検査するテスト) は採らない**
- 根拠: 指摘のとおりテストが無かった。しかし実際に書いてみて分かったのは、
  **この境界は component テストでは isolate できない**ということである。
  `@testing-library/svelte` の `rerender()` は props をまとめて更新するため、
  `take` に触れず `cut` だけを変えても `$effect` は再実行される。実測として
  `void playbackUrl;` を外しても新テストは緑のままだった = 提案された形のテストは
  **degenerate PASS になる** (禁止事項に照らして、緑になるだけのテストを足す意味は無い)。
- 対応内容: 購読で直すのをやめ、**失敗の持ち方を変えた**。
  `TakePreviewPanel` / `TakePreviewDialog` の両方で、失敗を真偽値ではなく
  **失敗した URL** (`failedUrl`) として持ち、`imageFailed` を
  `failedUrl === playbackUrl` の `$derived` にした。失敗は「その URL の性質」であって
  component の状態ではないので、テイク切り替えでも署名 URL 再取得でも
  **リセットのための購読を書かずに構造的に外れる** (購読の書き漏らしという失敗様式が消える)。
  `$effect` は 2 component とも撤去した。
  テストは `tests/js/components/features/manual/TakePreviewPanel.test.ts` を新設し (7 件)、
  「URL が変われば失敗表示が外れる」と、その**負のコントロール**として
  「URL が変わらない再描画では失敗表示が残る」の 2 本を対にした
  (後者があるので「無条件に失敗表示を消す実装」では緑にならない)。

## [Warning] `substr_count($contents, 'run([')` は表記揺れで無検出になる

- 判断: **対応する**
- 根拠: 指摘のとおり。`->run ([` や `->run(\n    [` は同じ呼び出し表現であり、
  動的構築とは別問題である。「起動点を足したら黙って通らない」という主張と実装が一致していなかった。
- 対応内容: 数え方を `preg_match_all('/->\s*run\s*\(\s*\[/', …)` に変え、空白・改行の揺れを吸収した。
  併せて docblock の「保証しないもの」へ、**配列を変数へ組み立ててから `->run($args)` で渡す形**・
  **`start()` / 静的 `Process::run()`**・vendor 経由の起動は数に入らないことを明記した
  (字句検査の限界を「動的構築」だけに丸めない)。
  AST/tokenizer 化は採らなかった — 母集団 3 ファイルに対して検査機構の複雑さが釣り合わず、
  引数の**並び**は既に Unit テスト (Process::fake の argv) が固定しているためである。

## [Suggestion] StillMaterialConsistencyTest の冒頭コメントが内容と食い違っている

- 判断: **対応する**
- 根拠: 指摘のとおり。Round 1 で C1 通しを同ファイルへ追加したのに、冒頭は
  「C1 は既存テストが持つ」のままだった。保証範囲を誤読させる。
- 対応内容: 冒頭コメントを実態へ更新した (C1 通しは本ファイルが固定する /
  C2・C3 は既存挙動なので RenderPipelineTest・RenderTriggerTest に委ねる、と明記)。


---

## 修正後の差分

```diff
diff --git a/resources/js/components/features/capture/TakePreviewDialog.svelte b/resources/js/components/features/capture/TakePreviewDialog.svelte
index 5abf6ac..9ab8607 100644
--- a/resources/js/components/features/capture/TakePreviewDialog.svelte
+++ b/resources/js/components/features/capture/TakePreviewDialog.svelte
@@ -37,6 +37,19 @@
 
     let video: HTMLVideoElement | undefined = $state();
     let subtitlesOn = $state(true);
+    /**
+     * 読み込みに失敗した <img> の URL。素材種別は**申告 Content-Type からの分類**であり
+     * 実体の形式を保証しないため (docs/architecture.md の非保証)、
+     * still と申告された実体がデコードできない場合に「何も出ない」状態を作らない。
+     * <video> 側には足さない (既存挙動を変えないため。非対称は意図的)。
+     *
+     * 真偽値ではなく**失敗した URL** を持つ: 失敗は「その URL の性質」であって component の
+     * 状態ではない。{#key} に頼る形も採らない (DOM は作り直されても <script> の $state は
+     * 再生成されないので前のテイクの失敗が残る)。この形なら、テイクの切り替えでも
+     * 同じテイクの署名 URL 再取得でも、リセットのための購読を書かずに構造的に外れる。
+     */
+    let failedUrl = $state<string | null>(null);
+    const imageFailed = $derived(failedUrl !== null && failedUrl === playbackUrl);
 
     // 再オープン時に字幕を初期 ON へ戻す (撮影 PWA は初期 ON。doc/05)。
     $effect(() => {
@@ -55,7 +68,7 @@
     // close / 採用成功で閉じる / take 差し替え / component 破棄を同一 cleanup で扱う。
     // effect 実行時の要素を固定し、差し替え時に新要素を誤 teardown しない。
     $effect(() => {
-        if (!open || take === null || video === undefined) return;
+        if (!open || take === null || take.material_type === "still" || video === undefined) return;
         const target = video;
         return () => teardownVideo(target);
     });
@@ -76,18 +89,38 @@
     <div class="flex flex-col gap-3">
         <div class="relative w-full overflow-hidden rounded-md bg-text/5">
             {#if open && take !== null}
-                {#key take.id}
-                    <!-- svelte-ignore a11y_media_has_caption -->
-                    <video
-                        bind:this={video}
-                        controls
-                        playsinline
-                        src={playbackUrl ?? undefined}
-                        class="w-full"
-                        aria-label={`${cutLabel} のテイク再生`}
-                        data-testid="take-preview-video"
-                    ></video>
-                {/key}
+                {#if take.material_type === "still"}
+                    {#if imageFailed}
+                        <p
+                            class="p-6 text-center text-caption text-text-secondary"
+                            role="status"
+                            data-testid="take-preview-unavailable"
+                        >
+                            このテイクはプレビューできません。
+                        </p>
+                    {:else}
+                        <img
+                            src={playbackUrl ?? undefined}
+                            alt={`${cutLabel} のテイク`}
+                            class="w-full"
+                            onerror={() => (failedUrl = playbackUrl)}
+                            data-testid="take-preview-image"
+                        />
+                    {/if}
+                {:else}
+                    {#key take.id}
+                        <!-- svelte-ignore a11y_media_has_caption -->
+                        <video
+                            bind:this={video}
+                            controls
+                            playsinline
+                            src={playbackUrl ?? undefined}
+                            class="w-full"
+                            aria-label={`${cutLabel} のテイク再生`}
+                            data-testid="take-preview-video"
+                        ></video>
+                    {/key}
+                {/if}
             {/if}
 
             {#if subtitlesOn}
diff --git a/resources/js/components/features/manual/TakePreviewPanel.svelte b/resources/js/components/features/manual/TakePreviewPanel.svelte
index 546d001..714a3fd 100644
--- a/resources/js/components/features/manual/TakePreviewPanel.svelte
+++ b/resources/js/components/features/manual/TakePreviewPanel.svelte
@@ -43,6 +43,18 @@
 
     let error = $state<string | null>(null);
     let busy = $state(false);
+    /**
+     * 読み込みに失敗した <img> の URL。素材種別は**申告 Content-Type からの分類**であり
+     * 実体の形式を保証しないため、still と申告された実体がデコードできない場合に
+     * 「何も出ない」状態を作らない。<video> 側には足さない (非対称は意図的)。
+     *
+     * 真偽値ではなく**失敗した URL** を持つ: 失敗は「その URL の性質」であって component の
+     * 状態ではない。こうすると、テイクの切り替えでも同じテイクの署名 URL 再取得でも、
+     * リセットのための購読 ($effect) を書かずに**構造的に**失敗表示が外れる
+     * (購読の書き漏らしという失敗様式そのものを消す)。
+     */
+    let failedUrl = $state<string | null>(null);
+    const isStill = $derived(take?.material_type === "still");
 
     // ready 以外はサーバが 404 を返すため src を張らず <video> 自体を描かない
     // (無駄な要素とネットワーク要求を出さない)
@@ -52,6 +64,8 @@
             : null,
     );
 
+    const imageFailed = $derived(failedUrl !== null && failedUrl === playbackUrl);
+
     const thumbnailUrl = $derived(
         take !== null && take.has_thumbnail
             ? buildTakeUrl({ projectId, manualId, cutId: cut.id }, take.id, "/thumbnail")
@@ -100,17 +114,37 @@
 <Card padding="md" testId="take-preview-panel">
     <div class="relative w-full overflow-hidden rounded-md bg-text/5">
         {#if playbackUrl !== null && take !== null}
-            {#key take.id}
-                <!-- svelte-ignore a11y_media_has_caption -->
-                <video
-                    controls
-                    playsinline
-                    src={playbackUrl}
-                    class="w-full"
-                    aria-label={`${cut.label} のテイク ${(takeIndex ?? 0) + 1}`}
-                    data-testid="take-preview-video"
-                ></video>
-            {/key}
+            {#if isStill}
+                {#if imageFailed}
+                    <p
+                        class="p-6 text-center text-caption text-text-secondary"
+                        role="status"
+                        data-testid="take-preview-unavailable"
+                    >
+                        このテイクはプレビューできません。
+                    </p>
+                {:else}
+                    <img
+                        src={playbackUrl}
+                        alt={`${cut.label} のテイク ${(takeIndex ?? 0) + 1}`}
+                        class="w-full"
+                        onerror={() => (failedUrl = playbackUrl)}
+                        data-testid="take-preview-image"
+                    />
+                {/if}
+            {:else}
+                {#key take.id}
+                    <!-- svelte-ignore a11y_media_has_caption -->
+                    <video
+                        controls
+                        playsinline
+                        src={playbackUrl}
+                        class="w-full"
+                        aria-label={`${cut.label} のテイク ${(takeIndex ?? 0) + 1}`}
+                        data-testid="take-preview-video"
+                    ></video>
+                {/key}
+            {/if}
             <SubtitleOverlay
                 primary={cut.subtitle_primary}
                 secondary={cut.subtitle_secondary}
@@ -132,7 +166,7 @@
         <p class="mt-2 text-caption text-text-secondary" data-testid="take-not-playable">
             {take === null
                 ? "左の一覧からテイクを選ぶと再生できます。"
-                : `このテイクはまだ再生できません（${TAKE_STATUS_LABELS[take.status]}）。`}
+                : `このテイクはまだ${isStill ? "表示" : "再生"}できません（${TAKE_STATUS_LABELS[take.status]}）。`}
         </p>
     {/if}
 
diff --git a/tests/Architecture/FfmpegProcessLaunchInventoryTest.php b/tests/Architecture/FfmpegProcessLaunchInventoryTest.php
new file mode 100644
index 0000000..1bff657
--- /dev/null
+++ b/tests/Architecture/FfmpegProcessLaunchInventoryTest.php
@@ -0,0 +1,108 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Support\Media\FfmpegSafetyArguments;
+use Symfony\Component\Finder\Finder;
+
+/*
+ * 守る不変条件: app/ から起動する ffmpeg / ffprobe プロセスは 1 本残らず
+ * FfmpegSafetyArguments::all() (= -max_alloc) をバイナリ直後に持つ。
+ *
+ * 検査 1 (母集団の pin): app/ 配下で 'manual.render_ffmpeg_binary' /
+ *   'manual.render_ffprobe_binary' を参照するファイルを走査し、現行 3 ファイルと
+ *   完全一致することを assert する (増減のどちらでも赤になる)。
+ * 検査 2 (**起動点ごと**の pin): その 3 ファイルについて、プロセス起動式 (`->run(` に配列が続く形) の
+ *   件数と `FfmpegSafetyArguments::all()` の出現件数が**一致**することを assert する。
+ *   ファイル単位の「import があるか」だけだと、同じファイルへ安全引数なしの起動を
+ *   足しても緑のままになる (PipelineSmokeCommand は起動点を 3 つ持つ)。
+ * 検査 3 (値の pin): 安全境界引数が 2 要素 (-max_alloc + config 値) であること。
+ *
+ * ★ 保証範囲を誇張しない: これは**字句走査**である。
+ *   - 動的に組み立てたコマンド配列・vendor 内部からのプロセス起動には沈黙する
+ *   - 件数一致は「母集団の 3 ファイルでは配列引数の `->run(` がすべて ffmpeg / ffprobe 起動である」
+ *     という現状に依存する。ffmpeg 以外のプロセス起動をこれらのファイルへ足すと赤になるので、
+ *     そのときは分類を見直すこと (黙って通ることはない)
+ *   - 数えるのは `->run(` に配列が続く**呼び出し表現**だけである。配列を変数へ組み立ててから
+ *     `->run($args)` で渡す形・`start()` / `Process::run()` の静的呼び出し・vendor 経由の起動は
+ *     数に入らない (= その形で起動点を足すと本検査は沈黙する)。空白と改行の揺れは吸収する
+ *   - **引数の並び**(バイナリ直後にあること) は固定しない。それは Unit テスト
+ *     (Process::fake の引数列: FfmpegVideoComposerTest / FfmpegTakeThumbnailExtractorTest) の担当である
+ */
+
+/**
+ * app/ 配下で ffmpeg / ffprobe バイナリ設定キーを参照するファイル (app/ 相対パス)。
+ *
+ * @return list<string>
+ */
+function ffmpegBinaryReferencingFiles(): array
+{
+    $files = [];
+    foreach (Finder::create()->files()->in(base_path('app'))->name('*.php') as $file) {
+        $contents = $file->getContents();
+        if (str_contains($contents, 'manual.render_ffmpeg_binary')
+            || str_contains($contents, 'manual.render_ffprobe_binary')) {
+            $files[] = str_replace(base_path('app').'/', '', $file->getPathname());
+        }
+    }
+    sort($files);
+
+    return $files;
+}
+
+test('ffmpeg / ffprobe を起動するファイルの母集団が pin されている', function (): void {
+    expect(ffmpegBinaryReferencingFiles())->toBe([
+        // 開発用の通し確認コマンド (合成素材を自分で作って自分で probe する)。
+        // 入力は利用者由来ではないが、経路ごとに例外を作らず同じ安全境界を通す
+        'Console/Commands/Development/PipelineSmokeCommand.php',
+        'Services/Capture/FfmpegTakeThumbnailExtractor.php',
+        'Services/Render/FfmpegVideoComposer.php',
+    ]);
+});
+
+test('母集団の全ファイルが FfmpegSafetyArguments を import している', function (): void {
+    $missing = [];
+    foreach (ffmpegBinaryReferencingFiles() as $relative) {
+        $contents = (string) file_get_contents(base_path("app/{$relative}"));
+        if (! str_contains($contents, 'use App\Support\Media\FfmpegSafetyArguments;')) {
+            $missing[] = $relative;
+        }
+    }
+
+    expect($missing)->toBe([]);
+});
+
+test('起動点の件数と安全境界引数の付与件数が一致する', function (): void {
+    // ファイル単位ではなく**起動点ごと**に見る。同じファイルへ安全引数なしの起動を
+    // 足したら赤になる (import があるだけでは通さない)。
+    $counts = [];
+    foreach (ffmpegBinaryReferencingFiles() as $relative) {
+        $contents = (string) file_get_contents(base_path("app/{$relative}"));
+        // 空白・改行の揺れ (`->run ([` / `->run(\n    [`) を吸収する。
+        // substr_count('run([') だと、この揺れで起動点を足しても件数が動かず黙って通る
+        $counts[$relative] = [
+            'launches' => preg_match_all('/->\s*run\s*\(\s*\[/', $contents),
+            'guarded' => substr_count($contents, 'FfmpegSafetyArguments::all()'),
+        ];
+    }
+
+    // 現行の起動点数も完全一致で pin する (件数が動いたら必ずレビューに出る)
+    expect($counts)->toBe([
+        'Console/Commands/Development/PipelineSmokeCommand.php' => ['launches' => 3, 'guarded' => 3],
+        'Services/Capture/FfmpegTakeThumbnailExtractor.php' => ['launches' => 1, 'guarded' => 1],
+        'Services/Render/FfmpegVideoComposer.php' => ['launches' => 3, 'guarded' => 3],
+    ]);
+});
+
+test('安全境界引数はバイナリ直後に置く 2 要素である (-max_alloc + config 値)', function (): void {
+    expect(FfmpegSafetyArguments::all())->toBe([
+        '-max_alloc',
+        (string) config()->integer('manual.ffmpeg_max_alloc_bytes'),
+    ]);
+});
+
+test('母集団が空でない (degenerate PASS 防止)', function (): void {
+    // 上の「全ファイルが経由している」検査が、Finder が 1 件も返さないことで
+    // 緑になっていないことを示す。
+    expect(ffmpegBinaryReferencingFiles())->not->toBe([]);
+});
diff --git a/tests/Feature/Manual/StillMaterialConsistencyTest.php b/tests/Feature/Manual/StillMaterialConsistencyTest.php
new file mode 100644
index 0000000..f9e0a97
--- /dev/null
+++ b/tests/Feature/Manual/StillMaterialConsistencyTest.php
@@ -0,0 +1,262 @@
+<?php
+
+declare(strict_types=1);
+
+use App\DataTransferObjects\Capture\ObjectMetadataData;
+use App\DataTransferObjects\Capture\PresignedUploadData;
+use App\DataTransferObjects\Capture\UploadTicketClaims;
+use App\DataTransferObjects\Manual\Render\ComposedLocalVideo;
+use App\DataTransferObjects\Manual\Render\RenderClipSource;
+use App\DataTransferObjects\Manual\Render\RenderManifest;
+use App\Enums\Manual\JobStatus;
+use App\Enums\Manual\MaterialType;
+use App\Enums\Manual\VideoManualStatus;
+use App\Models\Cut;
+use App\Models\Organization;
+use App\Models\Project;
+use App\Models\Take;
+use App\Models\User;
+use App\Models\VideoManual;
+use App\Services\Billing\TicketLedgerService;
+use App\Services\Capture\CaptureTakeService;
+use App\Services\Capture\TakeObjectStorage;
+use App\Services\Capture\UploadTicketCodec;
+use App\Services\Manual\RenderJobService;
+use App\Services\Manual\RenderPipeline;
+use App\Services\Render\VideoComposer;
+use Carbon\CarbonImmutable;
+use Illuminate\Process\PendingProcess;
+use Illuminate\Support\Facades\Process;
+use Illuminate\Support\Facades\Queue;
+use Illuminate\Support\Facades\Storage;
+use Illuminate\Support\Str;
+use Illuminate\Validation\ValidationException;
+
+/*
+ * 静止画素材の通し (詳細設計 S8 の組み合わせ表)。
+ *
+ * 本ファイルが固定するもの:
+ * - **C1 (still/still) の通し**: presign が .jpg のキーを作る → 登録が still で確定する →
+ *   採用 → マニフェストが TakeStill になる (S1 / S2 / S3 の接続点。末尾のテスト)
+ * - **C5**: 採用後に cut.material_type を video へ戻しても、実体が画像なら
+ *   (a) マニフェストは TakeStill (b) 尺ゲートも静止画の尺で数える
+ * - **誤申告** (video と申告して画像を置く) は ffprobe が尺を取れず**失敗ジョブ**になる。
+ *   壊れた成果物を出さず、後続ジョブは処理できる
+ *
+ * C2 (still/video) と C3 (video/video) は既存挙動そのままなので、既存の
+ * RenderPipelineTest / RenderTriggerTest が持つ回帰テストに委ねる (ここでは重複させない)。
+ *
+ * 誤申告の帰結は**向きによって非対称**である。「still と申告して動画を置いた」場合は
+ * 先頭フレーム抽出で成功しうる (C2 と同じ経路で害が無い) ため、題材にしない。
+ */
+
+/** 実 ffmpeg に触れない composer (container swap で注入する。本ファイル専用) */
+final class StillConsistencyComposer implements VideoComposer
+{
+    public ?RenderManifest $lastManifest = null;
+
+    public function compose(RenderManifest $manifest, array $localSources, string $workDir, callable $onClipComposed): ComposedLocalVideo
+    {
+        $this->lastManifest = $manifest;
+        $durations = [];
+        foreach ($manifest->clips as $index => $clip) {
+            $durations[$clip->cutId] = 1_000 * ($index + 1);
+            $onClipComposed($index + 1, count($manifest->clips));
+        }
+        $localPath = "{$workDir}/output.mp4";
+        file_put_contents($localPath, 'fake-mp4');
+
+        return new ComposedLocalVideo($localPath, $durations, (int) array_sum($durations));
+    }
+}
+
+/**
+ * cut の計画と take の実体を任意に組める文脈 (ticket 付与済み)。
+ *
+ * @return array{Organization, User, Project, VideoManual, Cut, Take}
+ */
+function stillConsistencyContext(?MaterialType $planned, MaterialType $actual): array
+{
+    Queue::fake();
+    Storage::fake('s3');
+    [$organization, $owner] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+    $manual = VideoManual::factory()->forProject($project)->create([
+        'status' => VideoManualStatus::Ready->value,
+        'scenario_version' => 2,
+    ]);
+    $cut = Cut::factory()->forManual($manual)->create([
+        'material_type' => $planned?->value,
+        'static_display_seconds' => null,
+    ]);
+    $take = $actual === MaterialType::Still
+        ? Take::factory()->forCut($cut)->still()->create()
+        : Take::factory()->forCut($cut)->create(['duration_ms' => 5_000]);
+    $cut->forceFill(['adopted_take_id' => $take->id])->save();
+    Storage::disk('s3')->put($take->video_path, 'fake-take-bytes');
+    app(TicketLedgerService::class)->grant($organization, 5, 'テスト残高');
+
+    return [$organization, $owner, $project, $manual, $cut, $take];
+}
+
+test('C5: cut=video / take=still でもマニフェストは TakeStill になり、尺は既定の静止画尺になる', function (): void {
+    config()->set('manual.default_still_display_seconds', 5);
+    [, , $project, $manual, $cut] = stillConsistencyContext(MaterialType::Video, MaterialType::Still);
+    $fake = new StillConsistencyComposer;
+    app()->instance(VideoComposer::class, $fake);
+    $job = app(RenderJobService::class)->trigger($project, $manual);
+
+    app(RenderPipeline::class)->run($job->id);
+
+    expect($job->refresh()->status)->toBe(JobStatus::Succeeded);
+    $clip = collect($fake->lastManifest?->clips ?? [])->firstWhere('cutId', $cut->id);
+    expect($clip?->source)->toBe(RenderClipSource::TakeStill);
+    expect($clip?->stillDisplaySeconds)->toBe(5);
+});
+
+test('C5: 尺ゲートも静止画の尺で数える (duration_ms 欠落の既定 60 秒に落ちない)', function (): void {
+    // 上限を 10 秒に絞る。旧実装は cut.material_type が video なので
+    // `duration_ms ?? render_default_take_duration_ms` = 60 秒として数え、ここで 422 になっていた。
+    // 実効判定を通す今は 5 秒として数えるためトリガーできる = レンダの実尺と一致する。
+    config()->set('manual.default_still_display_seconds', 5);
+    config()->set('manual.render_max_total_source_ms', 10_000);
+    config()->set('manual.render_default_take_duration_ms', 60_000);
+    [, , $project, $manual, $cut, $take] = stillConsistencyContext(MaterialType::Video, MaterialType::Still);
+    expect($take->duration_ms)->toBeNull();
+    expect($cut->material_type)->toBe(MaterialType::Video);
+    app()->instance(VideoComposer::class, new StillConsistencyComposer);
+
+    $job = app(RenderJobService::class)->trigger($project, $manual);
+
+    expect($job->status)->toBe(JobStatus::Queued);
+});
+
+test('尺ゲートの回帰: 動画テイクは従来どおり duration_ms で数える', function (): void {
+    config()->set('manual.render_max_total_source_ms', 4_000);
+    [, , $project, $manual] = stillConsistencyContext(MaterialType::Video, MaterialType::Video);
+    app()->instance(VideoComposer::class, new StillConsistencyComposer);
+
+    expect(fn () => app(RenderJobService::class)->trigger($project, $manual))
+        ->toThrow(ValidationException::class, '合計尺が上限を超えています');
+});
+
+test('video と申告して画像を置いたテイクは失敗ジョブになり、壊れた成果物を残さない', function (): void {
+    // material_type=video のまま実体が画像 → planTakeVideo → probeDurationMs の ffprobe が
+    // format=duration を数値で返せない。実バイナリには依存せず Process::fake で再現する。
+    [, , $project, $manual] = stillConsistencyContext(MaterialType::Video, MaterialType::Video);
+    Process::fake(function (PendingProcess $process) {
+        $command = $process->command;
+        $line = is_array($command) ? implode(' ', array_map(strval(...), $command)) : (string) $command;
+        if (str_contains($line, '-show_entries')) {
+            return Process::result(output: "N/A\n"); // 画像には尺が無い
+        }
+
+        return Process::result(output: '');
+    });
+    $job = app(RenderJobService::class)->trigger($project, $manual);
+
+    app(RenderPipeline::class)->run($job->id);
+
+    $job->refresh();
+    expect($job->status)->toBe(JobStatus::Failed);
+    expect($job->output_path)->toBeNull();
+    // compose 失敗地点では upload() 自体が未実行 = 出力オブジェクトはそもそも生まれない
+    // (孤児削除は finalize 失敗の別契約であり、ここでは期待しない)
+    expect(Storage::disk('s3')->allFiles())->not->toContain(
+        "projects/{$manual->project_id}/manuals/{$manual->id}/renders/v2-{$job->id}.mp4",
+    );
+    // rendering に取り残さない (編集をブロックし続けない)
+    expect($manual->refresh()->status)->toBe(VideoManualStatus::Ready);
+});
+
+test('失敗ジョブの後でも別のレンダジョブは正常に完了できる', function (): void {
+    [, , $project, $manual] = stillConsistencyContext(MaterialType::Video, MaterialType::Video);
+    Process::fake(fn (PendingProcess $process) => Process::result(output: "N/A\n"));
+    $failing = app(RenderJobService::class)->trigger($project, $manual);
+    app(RenderPipeline::class)->run($failing->id);
+    expect($failing->refresh()->status)->toBe(JobStatus::Failed);
+
+    // 2 本目は正常な composer で走らせる (キューが詰まらないことの確認)
+    app()->instance(VideoComposer::class, new StillConsistencyComposer);
+    $second = app(RenderJobService::class)->trigger($project, $manual->refresh());
+
+    app(RenderPipeline::class)->run($second->id);
+
+    expect($second->refresh()->status)->toBe(JobStatus::Succeeded);
+    expect($second->output_path)->not->toBeNull();
+});
+
+test('C1 通し: 静止画の presign → 登録 → 採用 → マニフェストが TakeStill になる', function (): void {
+    // S1 (material_type 列) / S2 (受け入れと確定) / S3 (実効判定と尺) の接続点を 1 本で通す。
+    // presigned PUT の実体は持てないので、HeadObject 照合だけを予約行と一致させて模す。
+    config()->set('manual.default_still_display_seconds', 5);
+    Queue::fake();
+    Storage::fake('s3');
+    [$organization, $owner] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+    $manual = VideoManual::factory()->forProject($project)->create([
+        'status' => VideoManualStatus::Ready->value,
+        'scenario_version' => 2,
+    ]);
+    $cut = Cut::factory()->forManual($manual)->create([
+        'material_type' => MaterialType::Still->value,
+        'static_display_seconds' => null,
+    ]);
+    app(TicketLedgerService::class)->grant($organization, 5, 'テスト残高');
+
+    // 1) presign: still カットへ image/jpeg → 予約行の S3 キーが .jpg になる
+    $storage = Mockery::mock(TakeObjectStorage::class);
+    app()->instance(TakeObjectStorage::class, $storage);
+    $storage->shouldReceive('presignUpload')->andReturn(new PresignedUploadData(
+        url: 'https://s3.fake.test/bucket/key?X-Amz-Signature=sig',
+        headers: ['Content-Type' => 'image/jpeg'],
+        expiresAt: CarbonImmutable::now()->addMinutes(30),
+    ));
+    $clientTakeId = strtoupper((string) Str::ulid());
+    $checksum = base64_encode(hash('sha256', 'jpeg-bytes', true));
+    $this->actingAs($owner)->postJson(
+        "/app/projects/{$project->id}/manuals/{$manual->id}/cuts/{$cut->id}/takes/upload-url",
+        [
+            'client_take_id' => $clientTakeId,
+            'size_bytes' => 120_000,
+            'content_type' => 'image/jpeg',
+            'checksum_sha256' => $checksum,
+        ],
+    )->assertOk();
+    $reservation = $cut->uploadReservations()->sole();
+    expect($reservation->video_path)->toEndWith('.jpg');
+
+    // 2) 登録: HeadObject を予約行と一致させ、素材を置いてから確定させる
+    Storage::disk('s3')->put($reservation->video_path, 'jpeg-bytes');
+    $storage->shouldReceive('headObject')->with($reservation->video_path)->andReturn(
+        new ObjectMetadataData(
+            contentLength: $reservation->size_bytes,
+            contentType: $reservation->content_type,
+            checksumSha256: $reservation->checksum_sha256,
+        ),
+    );
+    $ticket = app(UploadTicketCodec::class)->seal(
+        UploadTicketClaims::fromReservation($reservation->refresh()),
+    );
+    $this->actingAs($owner)->postJson(
+        "/app/projects/{$project->id}/manuals/{$manual->id}/cuts/{$cut->id}/takes",
+        ['ticket' => $ticket, 'client_take_id' => $clientTakeId, 'duration_ms' => 3_000],
+    )->assertCreated();
+    $take = $cut->takes()->sole();
+    expect($take->material_type)->toBe(MaterialType::Still);
+    expect($take->duration_ms)->toBeNull();
+
+    // 3) 採用 → レンダ: マニフェストが TakeStill + 既定の静止画尺になる
+    app(CaptureTakeService::class)->adopt($project, $manual, $cut, $take);
+    $fake = new StillConsistencyComposer;
+    app()->instance(VideoComposer::class, $fake);
+    $job = app(RenderJobService::class)->trigger($project, $manual->refresh());
+
+    app(RenderPipeline::class)->run($job->id);
+
+    expect($job->refresh()->status)->toBe(JobStatus::Succeeded);
+    $clip = collect($fake->lastManifest?->clips ?? [])->firstWhere('cutId', $cut->id);
+    expect($clip?->source)->toBe(RenderClipSource::TakeStill);
+    expect($clip?->takeSourcePath)->toBe($take->video_path);
+    expect($clip?->stillDisplaySeconds)->toBe(5);
+});
diff --git a/tests/js/components/features/manual/TakePreviewPanel.test.ts b/tests/js/components/features/manual/TakePreviewPanel.test.ts
new file mode 100644
index 0000000..ac3ce08
--- /dev/null
+++ b/tests/js/components/features/manual/TakePreviewPanel.test.ts
@@ -0,0 +1,138 @@
+import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
+import { cleanup, fireEvent, render, screen, waitFor } from "@testing-library/svelte";
+import TakePreviewPanel from "@/components/features/manual/TakePreviewPanel.svelte";
+import type { SelectableTake, TakeSelectionCut } from "@/types/manual";
+
+/*
+ * PC テイク選択画面の中央ペイン。静止画テイクは <video> ではなく <img> で出す。
+ * 素材種別は**申告 Content-Type からの分類**であって実体の形式を保証しないため、
+ * 読み込み失敗の受け皿を置き、「何も出ない」状態を作らない。
+ * 失敗状態は take の切り替えだけでなく、**同じ take で URL だけが変わった場合**にも戻す。
+ */
+
+function makeTake(overrides: Partial<SelectableTake> = {}): SelectableTake {
+    return {
+        id: 101,
+        status: "ready",
+        material_type: "still",
+        size_bytes: 120_000,
+        duration_ms: null,
+        comment: null,
+        captured_at: null,
+        sort_order: 0,
+        downloaded: false,
+        has_thumbnail: false,
+        ...overrides,
+    };
+}
+
+const cut: TakeSelectionCut = {
+    id: 34,
+    type: "step",
+    label: "手順1",
+    scene: "工具を準備する",
+    narration: "はじめに工具を準備します。",
+    subtitle_primary: null,
+    subtitle_secondary: "工具を準備する",
+    material_type: "still",
+    adopted: null,
+};
+
+function baseProps(take: SelectableTake | null = makeTake()) {
+    return {
+        take,
+        takeIndex: 0,
+        cut,
+        manualStatus: "ready" as const,
+        projectId: 7,
+        manualId: 12,
+        onChanged: () => undefined,
+    };
+}
+
+beforeEach(() => {
+    vi.stubGlobal("fetch", vi.fn());
+});
+
+afterEach(() => {
+    cleanup();
+    vi.unstubAllGlobals();
+    vi.restoreAllMocks();
+});
+
+describe("TakePreviewPanel", () => {
+    it("静止画テイクは <img> を出し <video> を出さない", () => {
+        render(TakePreviewPanel, { props: baseProps() });
+
+        expect(screen.getByTestId("take-preview-image")).toBeInTheDocument();
+        expect(screen.queryByTestId("take-preview-video")).not.toBeInTheDocument();
+    });
+
+    it("動画テイクは従来どおり <video> (回帰)", () => {
+        render(TakePreviewPanel, { props: baseProps(makeTake({ material_type: "video" })) });
+
+        expect(screen.getByTestId("take-preview-video")).toBeInTheDocument();
+        expect(screen.queryByTestId("take-preview-image")).not.toBeInTheDocument();
+    });
+
+    it("読み込み失敗で受け皿に差し替わる", async () => {
+        render(TakePreviewPanel, { props: baseProps() });
+
+        await fireEvent.error(screen.getByTestId("take-preview-image"));
+
+        await waitFor(() =>
+            expect(screen.getByTestId("take-preview-unavailable")).toHaveTextContent(
+                "このテイクはプレビューできません。",
+            ),
+        );
+    });
+
+    it("テイクを切り替えると失敗状態がリセットされる", async () => {
+        const { rerender } = render(TakePreviewPanel, { props: baseProps() });
+        await fireEvent.error(screen.getByTestId("take-preview-image"));
+        await screen.findByTestId("take-preview-unavailable");
+
+        await rerender({ take: makeTake({ id: 202 }) });
+
+        await waitFor(() => expect(screen.getByTestId("take-preview-image")).toBeInTheDocument());
+    });
+
+    it("同じテイクのまま URL だけが変わっても失敗表示が残らない", async () => {
+        // 署名 URL の再取得のように「take は同一で playbackUrl だけが変わる」場面。
+        // component は失敗を**真偽値ではなく失敗した URL** で持つので、URL が変われば
+        // 購読の有無に関係なく失敗表示が外れる (リセット漏れという失敗様式が構造的に無い)。
+        const sameTake = makeTake();
+        const { rerender } = render(TakePreviewPanel, { props: baseProps(sameTake) });
+        const first = screen.getByTestId("take-preview-image").getAttribute("src");
+        await fireEvent.error(screen.getByTestId("take-preview-image"));
+        await screen.findByTestId("take-preview-unavailable");
+
+        // take prop には触れず cut.id だけを変える (takeUrl の path が変わる)
+        await rerender({ cut: { ...cut, id: 99 } });
+
+        await waitFor(() => expect(screen.getByTestId("take-preview-image")).toBeInTheDocument());
+        expect(screen.getByTestId("take-preview-image").getAttribute("src")).not.toBe(first);
+    });
+
+    it("失敗した URL のままなら失敗表示が残る (失敗が URL に紐づいていることの裏)", async () => {
+        // 上のテストが「無条件に失敗表示が消える実装」でも緑になってしまわないことを示す
+        // 負のコントロール。URL が変わらない再描画では失敗表示が維持される。
+        const sameTake = makeTake();
+        const { rerender } = render(TakePreviewPanel, { props: baseProps(sameTake) });
+        await fireEvent.error(screen.getByTestId("take-preview-image"));
+        await screen.findByTestId("take-preview-unavailable");
+
+        await rerender({ takeIndex: 1 }); // URL に影響しない prop だけ動かす
+
+        await waitFor(() =>
+            expect(screen.getByTestId("take-preview-unavailable")).toBeInTheDocument(),
+        );
+    });
+
+    it("非 ready のテイクには URL を張らず、静止画向けの文言で理由を出す", () => {
+        render(TakePreviewPanel, { props: baseProps(makeTake({ status: "processing" })) });
+
+        expect(screen.queryByTestId("take-preview-image")).not.toBeInTheDocument();
+        expect(screen.getByTestId("take-not-playable")).toHaveTextContent("まだ表示できません");
+    });
+});

```

---

## 修正後の検証結果 (全コマンド green)

- `composer test`: 5495 tests / 5493 passed / 0 failed / 2 skipped / 23734 assertions
- `composer phpstan`: OK (level 10, no errors)
- `vendor/bin/pint --test`: passed
- `pnpm lint` / `pnpm typecheck`: passed
- `pnpm test`: 157 files / 1934 tests passed
- `pnpm build` / `pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages`: passed

---

## 判定してほしいこと

1. Round 2 の 2 つの Warning が解消されたか。とくに 1 件目は
   **提案とは別の直し方 (失敗を URL に紐づけて $effect を撤去)** を採ったので、
   その判断が妥当かを見てほしい (提案どおりの購読 + テストは degenerate PASS になることを実測した)。
2. Suggestion (コメントの食い違い) が解消されたか
3. 新たな Critical / Warning が生まれていないか

全体判定を **APPROVED** または **CHANGES_REQUESTED** の 1 語で明示すること。
