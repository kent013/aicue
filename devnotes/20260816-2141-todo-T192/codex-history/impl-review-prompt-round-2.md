# Round 2: Round 1 指摘への対応

Round 1 の指摘 (Critical 0 / Warning 3 / Suggestion 2) をすべて捌いた。判断と根拠は下記のとおり。

# 対応マトリクス: impl-review Round 1

Codex 判定: **CHANGES_REQUESTED** (Critical 0 / Warning 3 / Suggestion 2)

## [Warning] TakePreviewPanel の imageFailed リセットが take.id 変更でしかかからない

- 判断: **対応する**
- 根拠: 指摘のとおり。同じテイクのまま署名 URL だけが変わる (再取得) 場面で、前回の失敗表示が
  残り続ける。TakePreviewDialog 側は `playbackUrl` も購読しており、2 画面で挙動が食い違っていた。
- 対応内容: `resources/js/components/features/manual/TakePreviewPanel.svelte` の `$effect` で
  `playbackUrl` も購読するようにした。参照順の問題を避けるため effect の宣言位置を
  `playbackUrl` の `$derived` より後ろへ移した。

## [Warning] FfmpegProcessLaunchInventoryTest がファイル単位でしか検査していない

- 判断: **対応する**
- 根拠: 指摘のとおり。「import と呼び出し文字列がファイルにあるか」だけだと、同じファイルへ
  安全引数なしの起動を追加しても緑のままになる。PipelineSmokeCommand は起動点を 3 つ持つため、
  この弱さが実際に効く。
- 対応内容: 検査 2 を**起動点ごと**の pin へ変えた。母集団 3 ファイルについて
  プロセス起動式 (`run([`) の件数と `FfmpegSafetyArguments::all()` の出現件数が一致すること、
  かつ現行の件数 (3 / 1 / 3) を完全一致で pin する。件数が動けば必ずレビューに出る。
  docblock に**保証しないもの**を明記した (件数一致は「母集団 3 ファイルの `run([` がすべて
  ffmpeg / ffprobe 起動である」という現状に依存する / 引数の並びは Unit テストの担当)。
  behavioral な argv テストを PipelineSmokeCommand に足す案は採らなかった —
  同コマンドは bug-hunt 専用の fail-secure gate (専用 DB 接続・fake storage gate) を通らないと
  `handle()` に到達せず、テストのために gate を緩めるのは本末転倒だからである。

## [Warning] TakeFileUpload (PC 側の静止画アップロード) に component テストが無い

- 判断: **対応する**
- 根拠: 指摘のとおり。撮影 PWA 側 (CaptureFileFallback) と同じ契約 (accept の切替 /
  正規化して送る / 失敗時に原本を送らない) を持つのに未固定だった。
- 対応内容: `tests/js/components/features/manual/TakeFileUpload.test.ts` を新設 (5 件)。
  accept の切替 / 正規化 blob を `image/jpeg` かつ `durationMs: null` で enqueue すること /
  正規化失敗で enqueue しないこと / 種別違いのファイルで enqueue しないこと (両方向) を固定した。

## [Suggestion] MaterialType と CutMaterialType が 2 ファイルに重複定義されている

- 判断: **一部対応する** (統合はしない / sync テストを両方に張る)
- 根拠: 2 つの types ファイル (`types/capture.ts` / `types/manual.ts`) は
  「PC は署名 URL の口を持たない」という理由で意図的に分けてある。片方が他方を import すると
  その分離が崩れるため、写しを 1 本にする案は採らない。一方で drift のリスクは実在するので、
  **両方を enum と突き合わせる**ことで解決する。
- 対応内容: `ManualEnumTsSyncInvariantTest` に `types/capture.ts` の `MaterialType` を
  突き合わせるテストを追加し、なぜ写しを 2 つ残すのかを同ファイルのコメントに書いた。

## [Suggestion] C1 (still/still) の通しが 1 本あると接続点が明確になる

- 判断: **対応する**
- 根拠: S1 (列) / S2 (受け入れ・確定) / S3 (実効判定・尺) は個別テストで覆えているが、
  「presign が .jpg のキーを作る → 登録が still で確定する → マニフェストが TakeStill になる」の
  接続点そのものは誰も見ていなかった。
- 対応内容: `tests/Feature/Manual/StillMaterialConsistencyTest.php` へ
  「C1 通し: 静止画の presign → 登録 → 採用 → マニフェストが TakeStill になる」を追加。
  presigned PUT の実体は持てないので、HeadObject 照合だけを予約行と一致させて模している。


---

## 修正後の差分 (Round 1 で指摘された箇所と、その周辺のみ抜粋)

```diff
diff --git a/app/Services/Capture/FfmpegTakeThumbnailExtractor.php b/app/Services/Capture/FfmpegTakeThumbnailExtractor.php
index 1e7bcdd..2ba803f 100644
--- a/app/Services/Capture/FfmpegTakeThumbnailExtractor.php
+++ b/app/Services/Capture/FfmpegTakeThumbnailExtractor.php
@@ -4,14 +4,16 @@
 
 namespace App\Services\Capture;
 
+use App\Enums\Manual\MaterialType;
 use App\Exceptions\Capture\TakeThumbnailExtractionException;
+use App\Support\Media\FfmpegSafetyArguments;
 use Illuminate\Support\Facades\File;
 use Illuminate\Support\Facades\Process;
 
 /**
  * ffmpeg による 1 フレーム抽出 (実行は Process facade 経由。テストは Process::fake())。
  *
- * 安全境界 (入力は**利用者がアップロードした動画**である):
+ * 安全境界 (入力は**利用者がアップロードした素材** = 動画または静止画である):
  * - 引数は配列で渡す (シェル連結なし)。入力・出力ともサーバ生成のパスだけで、
  *   利用者由来の文字列は 1 つも引数に入らない
  * - `-nostdin` で標準入力待ちに落ちない / `Process::timeout()` で実行を有界にする
@@ -24,15 +26,26 @@
  */
 final class FfmpegTakeThumbnailExtractor implements TakeThumbnailExtractor
 {
-    public function extract(string $localVideoPath, string $localThumbnailPath): void
+    public function extract(string $localSourcePath, string $localThumbnailPath, MaterialType $material): void
     {
+        // 静止画に「1 秒地点」は無い。seek=0 の 1 回で決める
+        // (動画既定の 1000ms を当てると 1 回目が必ず空振りし、無駄な ffmpeg 実行が 1 回増える)
+        if ($material === MaterialType::Still) {
+            $failure = $this->attempt($localSourcePath, $localThumbnailPath, 0);
+            if ($failure !== null) {
+                throw new TakeThumbnailExtractionException($failure);
+            }
+
+            return;
+        }
+
         $seekMs = config()->integer('capture.thumbnail_seek_ms');
 
-        $failure = $this->attempt($localVideoPath, $localThumbnailPath, $seekMs);
+        $failure = $this->attempt($localSourcePath, $localThumbnailPath, $seekMs);
         if ($failure !== null && $seekMs > 0) {
             // 尺が seek より短いと 1 フレームも出力されない。先頭で 1 回だけ再試行する
             // (これ以上の探索はしない = 尺の推定に ffprobe を足さない)
-            $failure = $this->attempt($localVideoPath, $localThumbnailPath, 0);
+            $failure = $this->attempt($localSourcePath, $localThumbnailPath, 0);
         }
         if ($failure !== null) {
             throw new TakeThumbnailExtractionException($failure);
@@ -58,6 +71,7 @@ private function attempt(string $source, string $destination, int $seekMs): ?str
         $result = Process::timeout(config()->integer('capture.thumbnail_ffmpeg_timeout_seconds'))
             ->run([
                 config()->string('manual.render_ffmpeg_binary'),
+                ...FfmpegSafetyArguments::all(),
                 '-nostdin', '-y',
                 '-protocol_whitelist', 'file',
                 '-ss', sprintf('%.3f', $seekMs / 1000),
diff --git a/app/Services/Capture/TakeRegistrationService.php b/app/Services/Capture/TakeRegistrationService.php
index e1db63a..1626611 100644
--- a/app/Services/Capture/TakeRegistrationService.php
+++ b/app/Services/Capture/TakeRegistrationService.php
@@ -9,6 +9,7 @@
 use App\DataTransferObjects\Capture\UploadTicketClaims;
 use App\Enums\Capture\CaptureConflictType;
 use App\Enums\Capture\TakeUploadReservationStatus;
+use App\Enums\Manual\MaterialType;
 use App\Enums\Manual\TakeStatus;
 use App\Exceptions\Capture\CaptureConflictException;
 use App\Jobs\Capture\GenerateTakeThumbnailJob;
@@ -17,6 +18,7 @@
 use App\Models\Take;
 use App\Models\TakeUploadReservation;
 use App\Models\VideoManual;
+use App\Support\Capture\TakeMaterialClassifier;
 use Illuminate\Database\Eloquent\ModelNotFoundException;
 use Illuminate\Database\UniqueConstraintViolationException;
 use Illuminate\Support\Facades\DB;
@@ -160,15 +162,25 @@ private function finalize(Project $project, VideoManual $manual, Cut $cut, TakeR
                 ]);
             }
 
+            // 素材種別は**予約行の content_type**から導く (チケット偽装で差し替えられない)
+            $material = TakeMaterialClassifier::fromContentType($reservation->content_type);
+
             $lockedCut->takes()->increment('sort_order'); // 既存を後ろへ (先頭 = 0。行ロック下で競合なし)
             $take = $lockedCut->takes()->make([
                 'client_take_id' => $reservation->client_take_id,
                 'video_path' => $reservation->video_path,
                 'size_bytes' => $reservation->size_bytes,   // 予約 = HeadObject 照合済み確定値
-                'duration_ms' => $input->durationMs,        // クライアント申告 (表示用)
+                // 静止画に尺は無い。クライアント申告があっても捨てる (表示・尺ゲートの両方で嘘をつかせない)
+                'duration_ms' => $material === MaterialType::Still ? null : $input->durationMs,
                 'captured_at' => $input->capturedAt,
             ]);
-            $take->forceFill(['status' => TakeStatus::Ready, 'sort_order' => 0])->save();
+            // material_type は保護された確定値のため forceFill で**INSERT 時に明示代入**する
+            // (ドメイン規約 1 (ii)/2 と同じ理由。DB default を置いていないので、ここが唯一の設定点である)
+            $take->forceFill([
+                'status' => TakeStatus::Ready,
+                'sort_order' => 0,
+                'material_type' => $material,
+            ])->save();
 
             // サムネイル生成の投入を**同一 tx 内**で行う (AGENTS.md ドメイン固有規約 11。
             // afterCommit に依存しない)。保証するのは「take 行を作ったのに生成 job が投入されない窓」の
diff --git a/app/Services/Capture/TakeThumbnailExtractor.php b/app/Services/Capture/TakeThumbnailExtractor.php
index 23bfe6c..ca0df95 100644
--- a/app/Services/Capture/TakeThumbnailExtractor.php
+++ b/app/Services/Capture/TakeThumbnailExtractor.php
@@ -4,10 +4,11 @@
 
 namespace App\Services\Capture;
 
+use App\Enums\Manual\MaterialType;
 use App\Exceptions\Capture\TakeThumbnailExtractionException;
 
 /**
- * テイク動画から表示用サムネイル (JPEG) を 1 枚作る抽象 (v1 は ffmpeg 実装)。
+ * テイク素材から表示用サムネイル (JPEG) を 1 枚作る抽象 (v1 は ffmpeg 実装)。
  *
  * `Render\VideoComposer` と同じ作法で interface に切る = テストは実バイナリに依存せず
  * container swap で fake を注入できる (AppServiceProvider が本番実装を bind する)。
@@ -15,10 +16,14 @@
 interface TakeThumbnailExtractor
 {
     /**
-     * @param  string  $localVideoPath  ローカルへ落とした動画 (サーバ生成のパス)
+     * 素材種別を受け取り、seek 方針を実装側が決める。
+     * 静止画に「1 秒地点」は存在しないため、種別を知らずに seek を決められない。
+     *
+     * @param  string  $localSourcePath  ローカルへ落とした素材 (サーバ生成のパス)
      * @param  string  $localThumbnailPath  出力先 (サーバ生成のパス)
+     * @param  MaterialType  $material  登録された素材の実体種別 (takes.material_type)
      *
      * @throws TakeThumbnailExtractionException 抽出できなかった場合
      */
-    public function extract(string $localVideoPath, string $localThumbnailPath): void;
+    public function extract(string $localSourcePath, string $localThumbnailPath, MaterialType $material): void;
 }
diff --git a/app/Services/Capture/TakeThumbnailPipeline.php b/app/Services/Capture/TakeThumbnailPipeline.php
index ede7bdb..8b8f738 100644
--- a/app/Services/Capture/TakeThumbnailPipeline.php
+++ b/app/Services/Capture/TakeThumbnailPipeline.php
@@ -56,7 +56,7 @@ public function run(int $takeId): void
 
             // S3 GET は冪等な読み取り / ffmpeg はローカル CPU = どちらも preflight の対象ではない
             $this->storage->downloadToLocal($take->video_path, $source);
-            $this->extractor->extract($source, $thumbnail);
+            $this->extractor->extract($source, $thumbnail, $take->material_type);
 
             $size = File::isFile($thumbnail) ? File::size($thumbnail) : 0;
             if ($size === 0) {
diff --git a/app/Services/Capture/TakeUploadService.php b/app/Services/Capture/TakeUploadService.php
index ff1ff70..b4ac89d 100644
--- a/app/Services/Capture/TakeUploadService.php
+++ b/app/Services/Capture/TakeUploadService.php
@@ -8,6 +8,7 @@
 use App\DataTransferObjects\Capture\TakeUploadTicketData;
 use App\DataTransferObjects\Capture\UploadTicketClaims;
 use App\Enums\Capture\TakeUploadReservationStatus;
+use App\Enums\Manual\MaterialType;
 use App\Enums\Manual\VideoManualStatus;
 use App\Enums\QuotaKey;
 use App\Models\Cut;
@@ -16,11 +17,11 @@
 use App\Models\TakeUploadReservation;
 use App\Models\VideoManual;
 use App\Services\Billing\QuotaService;
+use App\Support\Capture\TakeMaterialClassifier;
 use Carbon\CarbonImmutable;
 use Illuminate\Support\Facades\DB;
 use Illuminate\Support\Str;
 use Illuminate\Validation\ValidationException;
-use Webmozart\Assert\Assert;
 
 /**
  * presigned PUT URL + 署名チケット発行 (doc/10 §10.3 / §10.8-4,-7 / 概念設計 D2,D3)。
@@ -53,6 +54,19 @@ public function issue(Organization $organization, Project $project, VideoManual
             /** @var Cut $lockedCut */
             $lockedCut = $lockedManual->cuts()->whereKey($cut->id)->firstOrFail();
 
+            // 素材種別の整合 (受け入れは非対称):
+            // - still カット: 画像も動画も受ける (動画は先頭フレーム抽出で従来どおり合成できる)
+            // - video / 未指定カット: 動画のみ。画像は 422 で押下時にエラー表示 (禁止事項 8 の通り
+            //   ボタンを disabled にはしない)。入口で止めるのは「指示と違う素材で容量を消費させない」ため。
+            // 一方でレンダ側は take の実体を優先する (EffectiveMaterialType)。採用後に
+            // cut.material_type を video へ戻す編集ができるので、入口検証だけでは不整合を防げない。
+            if (TakeMaterialClassifier::fromContentType($input->contentType) === MaterialType::Still
+                && $lockedCut->material_type !== MaterialType::Still) {
+                throw ValidationException::withMessages([
+                    'content_type' => ['このカットは動画で撮影する設定です。静止画を使う場合はシナリオ編集で素材を「静止画」に変更してください。'],
+                ]);
+            }
+
             // Quota: bytes_used + bytes_pending + size が上限を超えるなら 422 (QuotaExceededException)。
             // 加算合成は occupiedBytes() (overflow 安全) に委譲し、呼び出し側で生加算しない。
             // occupiedBytes() は pending→used の読み取り順が並行制御上の不変条件
@@ -71,7 +85,7 @@ public function issue(Organization $organization, Project $project, VideoManual
                 $lockedManual->id,
                 $lockedCut->id,
                 (string) Str::ulid(),
-                self::extensionFor($input->contentType),
+                TakeMaterialClassifier::extensionFor($input->contentType),
             );
 
             $reservation = $lockedCut->uploadReservations()->make([
@@ -107,18 +121,4 @@ public function issue(Organization $organization, Project $project, VideoManual
 
         return new TakeUploadTicketData($presigned, $ticket, $reservation->client_take_id);
     }
-
-    /** 許可 Content-Type → S3 キー拡張子 (config capture.allowed_video_content_types と対で保守) */
-    private static function extensionFor(string $contentType): string
-    {
-        $extension = match ($contentType) {
-            'video/mp4' => 'mp4',
-            'video/webm' => 'webm',
-            'video/quicktime' => 'mov',
-            default => null,
-        };
-        Assert::notNull($extension, "未許可の Content-Type です: {$contentType}");
-
-        return $extension;
-    }
 }
diff --git a/app/Services/Manual/EffectiveMaterialType.php b/app/Services/Manual/EffectiveMaterialType.php
new file mode 100644
index 0000000..da7d9ab
--- /dev/null
+++ b/app/Services/Manual/EffectiveMaterialType.php
@@ -0,0 +1,36 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Services\Manual;
+
+use App\Enums\Manual\MaterialType;
+use App\Models\Cut;
+use App\Models\Take;
+
+/**
+ * 「このカットを**実際に**どちらの素材として合成するか」を決める式の**唯一の所在**。
+ *
+ * 実体優先である: cut の計画が `still` でなくても、採用テイクの実体が画像なら `Still` を返す。
+ * 理由は、**採用した後に編集者がシナリオ編集で cut.material_type を `video` へ戻せる**ためで、
+ * 入口 (presign 422) でも採用 API でもこの状態は防げない。画像を動画クリップ経路
+ * (`FfmpegVideoComposer::planTakeVideo()` = ffprobe で尺を測る) に流すと必ず壊れるので、
+ * 「画像が動画クリップとして合成される道」を構造的に消す。
+ *
+ * **採用テイクは引数で受ける** (このクラスは `adoptedTake` relation を読まない)。
+ * したがって `AdoptedTakeReferenceInventory` の登録は増えない。
+ *
+ * **ready 判定は一切しない** — 「採用済みかつ ready か」の述語は
+ * `AdoptedReadyTakeCoverage` の専権である (AGENTS.md ドメイン固有規約 12)。本クラスは呼ばれる時点で
+ * 採用テイクが確定していることを前提にする。
+ */
+final class EffectiveMaterialType
+{
+    public static function of(Cut $cut, Take $adoptedTake): MaterialType
+    {
+        return $cut->material_type === MaterialType::Still
+            || $adoptedTake->material_type === MaterialType::Still
+                ? MaterialType::Still
+                : MaterialType::Video;
+    }
+}
diff --git a/app/Services/Manual/RenderJobService.php b/app/Services/Manual/RenderJobService.php
index fdf8993..40b10b2 100644
--- a/app/Services/Manual/RenderJobService.php
+++ b/app/Services/Manual/RenderJobService.php
@@ -448,12 +448,16 @@ private function assertTotalSourceDurationWithinLimit(array $ordered): void
         $totalMs = 0;
         foreach ($ordered as $entry) {
             $cut = $entry->cut;
-            if ($cut->material_type === MaterialType::Still && $cut->static_display_seconds !== null) {
-                $totalMs += $cut->static_display_seconds * 1000;
-
-                continue;
-            }
-            $totalMs += $cut->adoptedTake->duration_ms ?? $defaultMs;
+            $take = $cut->adoptedTake;
+            // ここへ来る時点で採用テイクは確定している (充足判定 = AdoptedReadyTakeCoverage が先に 422 を出す)
+            Assert::notNull($take, '充足判定を通った cut には採用テイクが必ず存在する');
+
+            // レンダ (RenderPipeline::clipSpecFor) と**同じ 2 クラス**を通す。
+            // 片方だけ実効判定を持つと、cut=video/take=still の組み合わせで
+            // ゲート 60 秒 / レンダ 5 秒という新しい二重管理が生まれる
+            $totalMs += EffectiveMaterialType::of($cut, $take) === MaterialType::Still
+                ? StillDisplayDuration::secondsFor($cut) * 1000
+                : ($take->duration_ms ?? $defaultMs);
         }
 
         if ($totalMs > config()->integer('manual.render_max_total_source_ms')) {
diff --git a/app/Services/Manual/RenderPipeline.php b/app/Services/Manual/RenderPipeline.php
index 134b497..048da87 100644
--- a/app/Services/Manual/RenderPipeline.php
+++ b/app/Services/Manual/RenderPipeline.php
@@ -254,7 +254,7 @@ private function clipSpecFor(RenderJob $job, Cut $cut, string $label): RenderCli
                 cutId: $cut->id,
                 label: $label,
                 source: RenderClipSource::Placeholder,
-                takeVideoPath: null,
+                takeSourcePath: null,
                 stillDisplaySeconds: null,
                 subtitlePrimary: $cut->subtitle_primary,
                 subtitleSecondary: $cut->subtitle_secondary,
@@ -266,16 +266,16 @@ private function clipSpecFor(RenderJob $job, Cut $cut, string $label): RenderCli
         $take = $cut->adoptedTake;
         Assert::notNull($take, 'isMissing() が false なら採用テイクは必ず存在する');
 
-        $isStill = $cut->material_type === MaterialType::Still;
+        // 実効素材種別の式は EffectiveMaterialType が唯一の所在 (ここに書き直さない)。
+        // 尺ゲート (RenderJobService) も同じ 2 クラスを呼ぶ = ゲートとレンダで尺が食い違わない
+        $isStill = EffectiveMaterialType::of($cut, $take) === MaterialType::Still;
 
         return new RenderClipSpec(
             cutId: $cut->id,
             label: $label,
             source: $isStill ? RenderClipSource::TakeStill : RenderClipSource::TakeVideo,
-            takeVideoPath: $take->video_path,
-            stillDisplaySeconds: $isStill
-                ? ($cut->static_display_seconds ?? config()->integer('manual.preview_placeholder_seconds'))
-                : null,
+            takeSourcePath: $take->video_path,
+            stillDisplaySeconds: $isStill ? StillDisplayDuration::secondsFor($cut) : null,
             subtitlePrimary: $cut->subtitle_primary,
             subtitleSecondary: $cut->subtitle_secondary,
         );
@@ -374,17 +374,23 @@ private function outputKeyFor(VideoManual $manual, RenderJob $job): string
     /**
      * S3 から採用テイク素材を work dir へ取得する (cutId => local path。Placeholder cut は不在)。
      *
+     * ローカル名から拡張子を落としている (旧: `src{$index}.mp4`)。
+     * 拡張子は**以前から既に嘘**だった — `video/webm` / `video/quicktime` のテイクも
+     * `.mp4` という名前で落ちており、合成は最初から **ffmpeg の内容プローブ**に依存している。
+     * 画像素材を足すにあたって嘘を増やす理由が無いので、名前から拡張子ごと外す。
+     * 前例は TakeThumbnailPipeline の `"{$workDir}/source"` (同じく拡張子なしで ffmpeg に渡す)。
+     *
      * @return array<int, string>
      */
     private function downloadSources(RenderManifest $manifest, string $workDir): array
     {
         $localSources = [];
         foreach ($manifest->clips as $index => $clip) {
-            if ($clip->takeVideoPath === null) {
+            if ($clip->takeSourcePath === null) {
                 continue;
             }
-            $localPath = "{$workDir}/src{$index}.mp4";
-            $this->storage->downloadToLocal($clip->takeVideoPath, $localPath);
+            $localPath = "{$workDir}/src{$index}";
+            $this->storage->downloadToLocal($clip->takeSourcePath, $localPath);
             $localSources[$clip->cutId] = $localPath;
         }
 
diff --git a/app/Services/Manual/StillDisplayDuration.php b/app/Services/Manual/StillDisplayDuration.php
new file mode 100644
index 0000000..84cbb76
--- /dev/null
+++ b/app/Services/Manual/StillDisplayDuration.php
@@ -0,0 +1,34 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Services\Manual;
+
+use App\Models\Cut;
+
+/**
+ * 静止画カットの表示秒を決める式の**唯一の所在**。
+ *
+ * 編集者が `cuts.static_display_seconds` を指定していればそれを使い、未指定なら
+ * `config('manual.default_still_display_seconds')` を使う。
+ *
+ * 以前は `RenderPipeline` が `manual.preview_placeholder_seconds`
+ * (= 採用テイク欠落 cut のプレースホルダ尺) を流用していた。これは別概念であり、
+ * プレースホルダ尺を変えると完成動画の静止画尺まで黙って変わる状態だった。撤去済み。
+ *
+ * **クランプしない**。異常値を黙って別の値へ変えると設定ミスが隠れる。
+ *
+ * **doc/02 §2.2 の「ナレーション尺より短ければナレーション尺が優先」は v1 では実装しない。**
+ * v1 は字幕のみで TTS を持たず、ナレーション文に再生時間という属性が存在しないためである
+ * (doc/09 の v1 尺算出も `cut_length = material_ms` / 静止画は `static_display_seconds*1000`)。
+ * 再検討の条件は「TTS を導入してナレーション音声の実尺が確定したとき」で、
+ * そのときの変更点は本クラス 1 か所に閉じる。
+ */
+final class StillDisplayDuration
+{
+    public static function secondsFor(Cut $cut): int
+    {
+        return $cut->static_display_seconds
+            ?? config()->integer('manual.default_still_display_seconds');
+    }
+}
diff --git a/app/Support/Media/FfmpegSafetyArguments.php b/app/Support/Media/FfmpegSafetyArguments.php
new file mode 100644
index 0000000..49d4ce9
--- /dev/null
+++ b/app/Support/Media/FfmpegSafetyArguments.php
@@ -0,0 +1,29 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Support\Media;
+
+/**
+ * ffmpeg / ffprobe の安全境界引数 (**バイナリの直後**に置く)。
+ *
+ * `-max_alloc` は 1 回の heap 確保の上限。**画素数爆弾** (小さいファイルで巨大な画素数を宣言する
+ * 画像) が media キューの worker を OOM で落とし、キューを共有する他組織のサムネイル生成まで
+ * 遅延させることを防ぐ。バイト数の上限 (`capture.max_still_bytes`) では止まらない別の軸である。
+ *
+ * 配置は「最初の -i より前」ではなく **バイナリ直後** に統一する。
+ * ffprobe は入力を -i ではなく**位置引数**で受けるため、-i を基準にすると検査が空振りする。
+ *
+ * **保証しないもの**: プロセス全体の RSS 上限でも、同時実行数の上限でもない。
+ * worker のメモリ cgroup 制限は本リポジトリに存在しない (デプロイ定義が無いため新設もしない)。
+ */
+final class FfmpegSafetyArguments
+{
+    /** @return list<string> */
+    public static function all(): array
+    {
+        // config()->integer() で int を確定させてから明示的に文字列化する
+        // (未型付けの config() 値をコマンド配列へ流さない = list<string> を保つ)
+        return ['-max_alloc', (string) config()->integer('manual.ffmpeg_max_alloc_bytes')];
+    }
+}
diff --git a/resources/js/components/features/manual/TakePreviewPanel.svelte b/resources/js/components/features/manual/TakePreviewPanel.svelte
index 546d001..1fa69ba 100644
--- a/resources/js/components/features/manual/TakePreviewPanel.svelte
+++ b/resources/js/components/features/manual/TakePreviewPanel.svelte
@@ -43,6 +43,13 @@
 
     let error = $state<string | null>(null);
     let busy = $state(false);
+    /**
+     * <img> の読み込み失敗フラグ。素材種別は**申告 Content-Type からの分類**であり
+     * 実体の形式を保証しないため、still と申告された実体がデコードできない場合に
+     * 「何も出ない」状態を作らない。<video> 側には足さない (非対称は意図的)。
+     */
+    let imageFailed = $state(false);
+    const isStill = $derived(take?.material_type === "still");
 
     // ready 以外はサーバが 404 を返すため src を張らず <video> 自体を描かない
     // (無駄な要素とネットワーク要求を出さない)
@@ -52,6 +59,14 @@
             : null,
     );
 
+    $effect(() => {
+        // take の切り替えだけでなく、同じ take で URL だけが変わった場合 (署名の再取得など) も
+        // 失敗状態を戻す。id だけを購読すると前回の失敗表示が残り続ける
+        void take?.id;
+        void playbackUrl;
+        imageFailed = false;
+    });
+
     const thumbnailUrl = $derived(
         take !== null && take.has_thumbnail
             ? buildTakeUrl({ projectId, manualId, cutId: cut.id }, take.id, "/thumbnail")
@@ -100,17 +115,37 @@
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
+                        onerror={() => (imageFailed = true)}
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
@@ -132,7 +167,7 @@
         <p class="mt-2 text-caption text-text-secondary" data-testid="take-not-playable">
             {take === null
                 ? "左の一覧からテイクを選ぶと再生できます。"
-                : `このテイクはまだ再生できません（${TAKE_STATUS_LABELS[take.status]}）。`}
+                : `このテイクはまだ${isStill ? "表示" : "再生"}できません（${TAKE_STATUS_LABELS[take.status]}）。`}
         </p>
     {/if}
 
diff --git a/tests/Architecture/FfmpegProcessLaunchInventoryTest.php b/tests/Architecture/FfmpegProcessLaunchInventoryTest.php
new file mode 100644
index 0000000..4c82c03
--- /dev/null
+++ b/tests/Architecture/FfmpegProcessLaunchInventoryTest.php
@@ -0,0 +1,103 @@
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
+ * 検査 2 (**起動点ごと**の pin): その 3 ファイルについて、プロセス起動式 (`run([`) の件数と
+ *   `FfmpegSafetyArguments::all()` の出現件数が**一致**することを assert する。
+ *   ファイル単位の「import があるか」だけだと、同じファイルへ安全引数なしの起動を
+ *   足しても緑のままになる (PipelineSmokeCommand は起動点を 3 つ持つ)。
+ * 検査 3 (値の pin): 安全境界引数が 2 要素 (-max_alloc + config 値) であること。
+ *
+ * ★ 保証範囲を誇張しない: これは**字句走査**である。
+ *   - 動的に組み立てたコマンド配列・vendor 内部からのプロセス起動には沈黙する
+ *   - 件数一致は「母集団の 3 ファイルでは `run([` がすべて ffmpeg / ffprobe 起動である」
+ *     という現状に依存する。ffmpeg 以外のプロセス起動をこれらのファイルへ足すと赤になるので、
+ *     そのときは分類を見直すこと (黙って通ることはない)
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
+        $counts[$relative] = [
+            'launches' => substr_count($contents, 'run(['),
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
diff --git a/tests/Architecture/ManualEnumTsSyncInvariantTest.php b/tests/Architecture/ManualEnumTsSyncInvariantTest.php
index 6d79bd6..e0e2c16 100644
--- a/tests/Architecture/ManualEnumTsSyncInvariantTest.php
+++ b/tests/Architecture/ManualEnumTsSyncInvariantTest.php
@@ -3,6 +3,7 @@
 declare(strict_types=1);
 
 use App\Enums\Manual\JobStatus;
+use App\Enums\Manual\MaterialType;
 use App\Enums\Manual\RenderConflictType;
 use App\Enums\Manual\RenderErrorCode;
 use App\Enums\Manual\RenderKind;
@@ -53,3 +54,19 @@ function extractTsUnionValues(string $typeName): array
     expect(fn (): array => extractTsUnionValues('NoSuchUnionName'))
         ->toThrow(RuntimeException::class, 'degenerate PASS');
 });
+
+/*
+ * MaterialType の TS 側の写しは **2 ファイルにある** (PC 側 types/manual.ts の CutMaterialType /
+ * 撮影 PWA 側 types/capture.ts の MaterialType)。2 つの types ファイルは
+ * 「PC は署名 URL の口を持たない」という理由で意図的に分けてあり、片方が他方を import すると
+ * その分離が崩れる。したがって**写しは 2 つ残し、両方を enum と突き合わせる**
+ * (片方だけ pin すると drift が起きる)。
+ */
+test('CutMaterialType (types/manual.ts) の PHP enum ⇔ TS union 値集合が一致する', function (): void {
+    expect(extractTsUnionValues('CutMaterialType'))->toBe(TsUnionValues::enumStringValues(MaterialType::cases()));
+});
+
+test('MaterialType (types/capture.ts) の PHP enum ⇔ TS union 値集合が一致する', function (): void {
+    expect(TsUnionValues::extract('resources/js/types/capture.ts', 'MaterialType'))
+        ->toBe(TsUnionValues::enumStringValues(MaterialType::cases()));
+});
diff --git a/tests/Feature/Manual/StillMaterialConsistencyTest.php b/tests/Feature/Manual/StillMaterialConsistencyTest.php
new file mode 100644
index 0000000..44dfbf8
--- /dev/null
+++ b/tests/Feature/Manual/StillMaterialConsistencyTest.php
@@ -0,0 +1,258 @@
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
+ * C1 (still/still) / C2 (still/video) / C3 (video/video) は各所の既存テストが持つため、
+ * ここでは**この施策が新しく作った経路**だけを固定する:
+ * - C5: 採用後に cut.material_type を video へ戻しても、実体が画像なら
+ *       (a) マニフェストは TakeStill (b) 尺ゲートも静止画の尺で数える
+ * - 誤申告 (video と申告して画像を置く) は ffprobe が尺を取れず**失敗ジョブ**になる。
+ *   壊れた成果物を出さず、後続ジョブは処理できる
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
diff --git a/tests/js/components/features/manual/TakeFileUpload.test.ts b/tests/js/components/features/manual/TakeFileUpload.test.ts
new file mode 100644
index 0000000..85bbbde
--- /dev/null
+++ b/tests/js/components/features/manual/TakeFileUpload.test.ts
@@ -0,0 +1,149 @@
+import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
+import { cleanup, fireEvent, render, screen } from "@testing-library/svelte";
+import TakeFileUpload from "@/components/features/manual/TakeFileUpload.svelte";
+
+/*
+ * PC ローカル素材の追加アップロード。
+ * カットの計画が静止画なら accept を image/* に切り替え、**必ず再エンコードしてから**送る
+ * (寸法上限が効き EXIF が落ちる)。静止画に尺は無いので尺の事前チェックは通さない。
+ * 正規化に失敗したら原本を送らない (upload-url を呼ばない = quota を消費しない)。
+ */
+
+const enqueueMock = vi.hoisted(() => vi.fn());
+const deleteMock = vi.hoisted(() => vi.fn());
+const normalizeStillFile = vi.hoisted(() => vi.fn());
+
+vi.mock("@/lib/capture/upload-queue", () => ({
+    createMemoryPendingStore: () => ({ delete: deleteMock }),
+    generateClientTakeId: () => "01ARZ3NDEKTSV4RRFFQ69G5FAV",
+    UploadQueue: class {
+        enqueue = enqueueMock;
+    },
+}));
+
+vi.mock("@/lib/capture/still-encode", async (importOriginal) => {
+    const actual = await importOriginal<typeof import("@/lib/capture/still-encode")>();
+    return { ...actual, normalizeStillFile };
+});
+
+const baseProps = { projectId: 1, manualId: 5, cutId: 11, onUploaded: () => undefined };
+
+/** 動画の尺読み取り (readDurationMs) を決定的にする。jsdom は <video> のロードを実装しない */
+function stubVideoMetadata(seconds: number): void {
+    const createElement = document.createElement.bind(document);
+    vi.spyOn(document, "createElement").mockImplementation(((tag: string) => {
+        const element = createElement(tag);
+        if (tag !== "video") return element;
+        const video = element as HTMLVideoElement;
+        Object.defineProperty(video, "duration", { configurable: true, get: () => seconds });
+        Object.defineProperty(video, "src", {
+            configurable: true,
+            get: () => "",
+            set: () => queueMicrotask(() => video.onloadedmetadata?.(new Event("loadedmetadata"))),
+        });
+        return video;
+    }) as typeof document.createElement);
+    vi.stubGlobal("URL", {
+        ...URL,
+        createObjectURL: () => "blob:stub",
+        revokeObjectURL: () => undefined,
+    });
+}
+
+async function selectFile(file: File): Promise<void> {
+    const input = screen.getByTestId("take-file-input") as HTMLInputElement;
+    Object.defineProperty(input, "files", { value: [file], configurable: true });
+    await fireEvent.change(input);
+}
+
+beforeEach(() => {
+    enqueueMock.mockReset();
+    enqueueMock.mockImplementation((item: { clientTakeId: string }) =>
+        Promise.resolve({ status: "uploaded", clientTakeId: item.clientTakeId }),
+    );
+    deleteMock.mockReset();
+    normalizeStillFile.mockReset();
+});
+
+afterEach(() => {
+    cleanup();
+    vi.unstubAllGlobals();
+    vi.restoreAllMocks();
+});
+
+describe("TakeFileUpload", () => {
+    it("既定 (計画なし) は動画扱い: accept=video/* で、選んだファイルをそのまま enqueue する", async () => {
+        stubVideoMetadata(20);
+        render(TakeFileUpload, { props: baseProps });
+
+        expect(screen.getByTestId("take-file-input")).toHaveAttribute("accept", "video/*");
+
+        const file = new File(["mp4"], "a.mp4", { type: "video/mp4" });
+        await selectFile(file);
+
+        await vi.waitFor(() => expect(enqueueMock).toHaveBeenCalledTimes(1));
+        expect(enqueueMock.mock.calls[0][0]).toMatchObject({
+            blob: file,
+            contentType: "video/mp4",
+        });
+        expect(normalizeStillFile).not.toHaveBeenCalled();
+    });
+
+    it("material=still は accept=image/* で、正規化した blob を image/jpeg / 尺 null で送る", async () => {
+        const normalized = new Blob(["jpeg"], { type: "image/jpeg" });
+        normalizeStillFile.mockResolvedValue(normalized);
+        render(TakeFileUpload, { props: { ...baseProps, material: "still" } });
+
+        expect(screen.getByTestId("take-file-input")).toHaveAttribute("accept", "image/*");
+
+        await selectFile(new File(["png"], "a.png", { type: "image/png" }));
+
+        await vi.waitFor(() => expect(enqueueMock).toHaveBeenCalledTimes(1));
+        expect(enqueueMock.mock.calls[0][0]).toMatchObject({
+            blob: normalized,
+            contentType: "image/jpeg",
+            durationMs: null, // 画像に尺は無い (readDurationMs を通さない)
+        });
+    });
+
+    it("正規化に失敗したら enqueue せずエラー表示する (原本を送らない)", async () => {
+        normalizeStillFile.mockResolvedValue(null);
+        render(TakeFileUpload, { props: { ...baseProps, material: "still" } });
+
+        await selectFile(new File(["png"], "a.png", { type: "image/png" }));
+
+        await vi.waitFor(() => {
+            expect(screen.getByTestId("take-upload-error")).toHaveTextContent(
+                "画像を読み込めませんでした。別のファイルをお試しください。",
+            );
+        });
+        expect(enqueueMock).not.toHaveBeenCalled();
+    });
+
+    it("still カットで動画を選ぶと enqueue しない (押下は受けてから理由を出す)", async () => {
+        render(TakeFileUpload, { props: { ...baseProps, material: "still" } });
+
+        await selectFile(new File(["mp4"], "a.mp4", { type: "video/mp4" }));
+
+        await vi.waitFor(() => {
+            expect(screen.getByTestId("take-upload-error")).toHaveTextContent(
+                "画像ファイルを選択してください。",
+            );
+        });
+        expect(enqueueMock).not.toHaveBeenCalled();
+        expect(normalizeStillFile).not.toHaveBeenCalled();
+    });
+
+    it("video カットで画像を選ぶと enqueue しない (回帰)", async () => {
+        render(TakeFileUpload, { props: { ...baseProps, material: "video" } });
+
+        await selectFile(new File(["png"], "a.png", { type: "image/png" }));
+
+        await vi.waitFor(() => {
+            expect(screen.getByTestId("take-upload-error")).toHaveTextContent(
+                "動画ファイルを選択してください。",
+            );
+        });
+        expect(enqueueMock).not.toHaveBeenCalled();
+    });
+});

```

---

## 修正後の検証結果 (全コマンド green)

- `composer test`: 5495 tests / 5493 passed / 0 failed / 2 skipped / 23734 assertions
- `composer phpstan`: OK (level 10, no errors)
- `vendor/bin/pint --test`: passed
- `pnpm lint` / `pnpm typecheck`: passed
- `pnpm test`: 156 files / 1927 tests passed
- `pnpm build` / `pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages`: passed

---

## 判定してほしいこと

1. Round 1 の 3 つの Warning が解消されたか
2. Suggestion への対応 (統合ではなく sync テスト 2 本 / C1 通しの追加) が妥当か
3. 新たな Critical / Warning が生まれていないか

全体判定を **APPROVED** または **CHANGES_REQUESTED** の 1 語で明示すること。
