# 使命 (North Star)

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

# 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

# system: 役割とタスク

あなたは T005「レンダ (採用テイク合成→完成 mp4。ffmpeg + チケット 2 フェーズ)」実装の**最終 impl-review** を行うシニアレビュアー。実装は既に前段レビューを 1 回通過しており、そこで挙がった唯一の Warning(下記)への修正コミットが追加された。今回はマージ直前の最終確認ラウンドである。

実装のあるブランチ: `todo/T005`(worktree: `/workspace/.claude/worktrees/tasks/T005`)。
このディレクトリ配下のファイルは自由に読んでよい(読み込みのみ)。主要ファイル:

- `app/Services/Render/FfmpegVideoComposer.php`(ffmpeg 合成。planTakeVideo/planTakeStill/planPlaceholder)
- `app/Services/Render/AssSubtitleWriter.php`(字幕 ASS 生成の安全境界)
- `app/Services/Manual/RenderPipeline.php`(startJob→buildManifest→compose→upload→finalize)
- `app/Services/Manual/RenderJobService.php`(trigger/triggerPreview/failJob/尺上限ゲート)
- `tests/Unit/Render/FfmpegVideoComposerTest.php`
- `tests/Feature/Manual/RenderTriggerTest.php` / `RenderPipelineTest.php`

## 前段レビューの Warning(今回の修正対象)

> 静止画カット (MaterialType::Still / RenderClipSource::TakeStill) のレンダ経路が全テストで一切実行されておらず、丸ごと無検証で出荷される。
> FfmpegVideoComposer::planTakeStill(先頭フレーム抽出 -frames:v 1 → -loop 1 -t {sec} でのループ、anullsrc map、duration=seconds*1000)と RenderPipeline::clipSpecFor の TakeStill 分岐、RenderJobService::assertTotalSourceDurationWithinLimit の Still 分岐(static_display_seconds*1000 加算)はどのテストも通らない。

## レビュー観点(この順に判定せよ)

1. **Warning 修正の妥当性**: 追加された 4 テスト(下記 diff)が Warning の指摘経路(planTakeStill / clipSpecFor TakeStill 分岐 / 尺上限 Still 分岐)を本当に固定しているか。アサーションが実装の写経(タウトロジー)になっていないか、回帰時に fail する形か。
2. **新規 Critical の有無**: 追加テスト自体の欠陥(false-green になる書き方、他テストへの汚染、config 汚染)や、テスト追加で露見する実装バグがないか。
3. 既存レビュー通過部分の再走査は不要。上記 1-2 に絞ること。

## 出力形式

```
## 総評
(2-4 文)

## Critical
(なければ「なし」)

## Warning
(なければ「なし」)

## Suggestion
(任意)
```

各指摘は「ファイル:該当箇所 / 問題 / 修正案」の形で書くこと。

---

# user: 今回追加された修正コミットの diff

```diff
diff --git a/tests/Feature/Manual/RenderPipelineTest.php b/tests/Feature/Manual/RenderPipelineTest.php
@@ -180,6 +181,39 @@
+test('Still カット (material_type=still) は TakeStill としてマニフェストへ載る (秒指定 + 未指定 fallback)', function (): void {
+    config()->set('manual.preview_placeholder_seconds', 3);
+    [, , $project, $manual, $cut, , $fake] = renderPipelineContext(tickets: 0, trigger: false);
+    // 1 本目: 秒指定あり
+    $cut->forceFill([
+        'material_type' => MaterialType::Still->value,
+        'static_display_seconds' => 4,
+    ])->save();
+    // 2 本目: 秒未指定 (static_display_seconds null → config fallback)
+    $fallbackCut = Cut::factory()->forManual($manual)->withSortOrder(1)->create([
+        'material_type' => MaterialType::Still->value,
+        'static_display_seconds' => null,
+    ]);
+    $fallbackTake = Take::factory()->forCut($fallbackCut)->create();
+    $fallbackCut->forceFill(['adopted_take_id' => $fallbackTake->id])->save();
+    Storage::disk('s3')->put($fallbackTake->video_path, 'fake-take-video-2');
+    $previewJob = app(RenderJobService::class)->triggerPreview($project, $manual);
+
+    app(RenderPipeline::class)->run($previewJob->id);
+
+    expect($previewJob->refresh()->status)->toBe(JobStatus::Succeeded);
+    $clips = collect($fake->lastManifest?->clips ?? []);
+    $still = $clips->firstWhere('cutId', $cut->id);
+    expect($still?->source)->toBe(RenderClipSource::TakeStill);
+    expect($still?->stillDisplaySeconds)->toBe(4);
+    $fallback = $clips->firstWhere('cutId', $fallbackCut->id);
+    expect($fallback?->source)->toBe(RenderClipSource::TakeStill);
+    expect($fallback?->stillDisplaySeconds)->toBe(3); // config fallback
+    // Still でも採用テイク素材 (先頭フレーム抽出元) はローカル供給される
+    expect($fake->lastSources)->toHaveKey($cut->id);
+    expect($fake->lastSources)->toHaveKey($fallbackCut->id);
+});
diff --git a/tests/Feature/Manual/RenderTriggerTest.php b/tests/Feature/Manual/RenderTriggerTest.php
@@ -184,6 +185,36 @@
+test('尺上限: Still カットは static_display_seconds×1000 で数える (テイク実尺 5,000ms は無視 → 201)', function (): void {
+    Queue::fake();
+    config()->set('manual.render_max_total_source_ms', 4_000);
+    [, $owner, $project, $manual, $cut] = renderTriggerContext(); // 採用テイクは 5,000ms
+    $cut->forceFill([
+        'material_type' => MaterialType::Still->value,
+        'static_display_seconds' => 3, // → 3,000ms として加算 (上限 4,000ms 内)
+    ])->save();
+
+    $this->actingAs($owner)->postJson(
+        "/projects/{$project->id}/manuals/{$manual->id}/render",
+    )->assertCreated();
+});
+
+test('尺上限: Still カットの static_display_seconds 合計が上限超過なら 422 (テイク実尺が小さくても)', function (): void {
+    Queue::fake();
+    config()->set('manual.render_max_total_source_ms', 4_000);
+    [, $owner, $project, $manual, $cut] = renderTriggerContext();
+    $cut->adoptedTake?->forceFill(['duration_ms' => 1_000])->save(); // 実尺は上限内
+    $cut->forceFill([
+        'material_type' => MaterialType::Still->value,
+        'static_display_seconds' => 5, // → 5,000ms として加算 (上限 4,000ms 超過)
+    ])->save();
+
+    $this->actingAs($owner)->postJson(
+        "/projects/{$project->id}/manuals/{$manual->id}/render",
+    )->assertUnprocessable()->assertJsonValidationErrors(['takes']);
+    expect(RenderJob::query()->count())->toBe(0);
+});
diff --git a/tests/Unit/Render/FfmpegVideoComposerTest.php b/tests/Unit/Render/FfmpegVideoComposerTest.php
@@ -43,6 +43,19 @@
+function takeStillClip(int $cutId = 1, int $seconds = 4): RenderClipSpec
+{
+    return new RenderClipSpec(
+        cutId: $cutId,
+        label: '手順1',
+        source: RenderClipSource::TakeStill,
+        takeVideoPath: 'takes/src-still.mp4',
+        stillDisplaySeconds: $seconds,
+        subtitlePrimary: null,
+        subtitleSecondary: ATTACK_SUBTITLE,
+    );
+}
@@ -178,6 +191,46 @@
+test('静止画カット (TakeStill): 先頭フレーム抽出 → -loop 1 -t 秒ループ + anullsrc / 尺は stillDisplaySeconds×1000', function (): void {
+    $recorded = [];
+    fakeFfmpegProcesses($recorded);
+    $workDir = composerWorkDir();
+
+    app(FfmpegVideoComposer::class)->compose(
+        composerManifest(takeStillClip(cutId: 1, seconds: 4)),
+        [1 => "{$workDir}/src0.mp4"],
+        $workDir,
+        function (): void {},
+    );
+
+    // 1) 先頭フレーム抽出 (-frames:v 1 → 連番 frame ファイル)
+    $frameLine = collect($recorded)->first(
+        fn (string $line): bool => str_contains($line, '-frames:v'),
+    );
+    expect($frameLine)->not->toBeNull();
+    expect($frameLine)->toContain("-i {$workDir}/src0.mp4");
+    expect($frameLine)->toContain('-frames:v 1 frame0.png');
+
+    // 2) エンコードは静止画ループ (-loop 1 -t {sec}) + 無音声 anullsrc (第 2 入力) の map
+    $encodeLine = collect($recorded)->first(
+        fn (string $line): bool => str_contains($line, 'libx264'),
+    );
+    expect($encodeLine)->toContain('-loop 1 -t 4 -i frame0.png');
+    expect($encodeLine)->toContain('-f lavfi -t 4 -i anullsrc=r=48000:cl=stereo');
+    expect($encodeLine)->toContain('-map 0:v:0 -map 1:a:0');
+
+    // 3) 尺導出: ASS 字幕の End が stillDisplaySeconds×1000 (= 4 秒) で固定される
+    $ass = (string) file_get_contents("{$workDir}/clip0.ass");
+    expect($ass)->toContain(',0:00:04.00,');
+
+    // 4) 字幕本文はコマンド (filtergraph 含む) に一切現れない (.ass ファイル名のみ)
+    foreach ($recorded as $line) {
+        expect($line)->not->toContain('字幕本文');
+        expect($line)->not->toContain('{\\an8}');
+    }
+    expect((bool) preg_match('/subtitles=clip\d+\.ass[,\s]/', (string) $encodeLine))->toBeTrue();
+});
```

検証結果(参考): composer test 1315 件 pass (skip 2) / composer phpstan 0 error / pint pass / pnpm lint・typecheck・test (359)・build すべて green。

上記観点 1-2 で最終判定せよ。必要なら worktree のファイルを読んで裏取りすること。
