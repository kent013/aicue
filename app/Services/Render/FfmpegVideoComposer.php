<?php

declare(strict_types=1);

namespace App\Services\Render;

use App\DataTransferObjects\Manual\Render\ComposedLocalVideo;
use App\DataTransferObjects\Manual\Render\RenderClipSource;
use App\DataTransferObjects\Manual\Render\RenderClipSpec;
use App\DataTransferObjects\Manual\Render\RenderManifest;
use App\Exceptions\Manual\RenderCompositionException;
use App\Support\Media\FfmpegSafetyArguments;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Webmozart\Assert\Assert;

/**
 * ffmpeg による v1 合成実装 (概念設計 §6)。実行は Process facade 経由 (テストは Process::fake())。
 *
 * 安全境界 (施策 6 と対):
 * - filtergraph に入るのは**サーバ生成の一時ファイル名と数値のみ** (字幕本文・ユーザー由来
 *   文字列は一切入らない)。字幕は AssSubtitleWriter が生成する work dir 内の clip{n}.ass
 *   (英数字のみ = filtergraph メタ文字を含まない) を subtitles= に渡す
 * - コマンドは配列引数 (シェル連結なし)。作業ファイル名は全て連番の固定形式
 */
class FfmpegVideoComposer implements VideoComposer
{
    /** クリップ 1 本のエンコード上限 (秒)。全体のハード上限は RunManualRender::$timeout */
    private const int ENCODE_TIMEOUT_SECONDS = 600;

    /** ffprobe の上限 (秒) */
    private const int PROBE_TIMEOUT_SECONDS = 60;

    public function __construct(
        private readonly AssSubtitleWriter $subtitles,
    ) {}

    public function compose(RenderManifest $manifest, array $localSources, string $workDir, callable $onClipComposed): ComposedLocalVideo
    {
        $totalClips = count($manifest->clips);
        $clipDurationsMs = [];
        $clipFiles = [];

        foreach ($manifest->clips as $index => $clip) {
            $clipFile = "clip{$index}.mp4";
            $this->composeClip($clip, $localSources, $workDir, $index, $clipFile);
            $clipFiles[] = $clipFile;
            $clipDurationsMs[$clip->cutId] = $this->probeDurationMs("{$workDir}/{$clipFile}");
            $onClipComposed($index + 1, $totalClips);
        }

        $outputFile = 'output.mp4';
        $this->concat($workDir, $clipFiles, $outputFile);

        return new ComposedLocalVideo(
            localPath: "{$workDir}/{$outputFile}",
            clipDurationsMs: $clipDurationsMs,
            totalDurationMs: (int) array_sum($clipDurationsMs),
        );
    }

    /**
     * カット 1 枚分のクリップ正規化 + 字幕焼き込み (H.264/AAC・解像度/fps は config 固定値)。
     *
     * @param  array<int, string>  $localSources
     */
    private function composeClip(RenderClipSpec $clip, array $localSources, string $workDir, int $index, string $clipFile): void
    {
        $assFile = "clip{$index}.ass";

        /** @var array{list<string>, list<string>, string, int} $plan [inputArgs, mapArgs, filter, durationMs] */
        $plan = match ($clip->source) {
            RenderClipSource::TakeVideo => $this->planTakeVideo($clip, $localSources, $assFile),
            RenderClipSource::TakeStill => $this->planTakeStill($clip, $localSources, $workDir, $index, $assFile),
            RenderClipSource::Placeholder => $this->planPlaceholder($assFile),
        };
        [$inputArgs, $mapArgs, $filter, $durationMs] = $plan;

        // 字幕 ASS 生成 (字幕本文の唯一の出力点。filtergraph にはファイル名のみ渡す)
        $this->subtitles->write($clip, $durationMs, "{$workDir}/{$assFile}");

        $this->runFfmpeg($workDir, [
            '-y',
            ...$inputArgs,
            '-vf', $filter,
            ...$mapArgs,
            '-c:v', 'libx264', '-preset', 'veryfast', '-crf', '23',
            '-c:a', 'aac', '-ar', '48000', '-ac', '2',
            '-shortest',
            $clipFile,
        ], "compose clip ({$clip->label})");
    }

    /**
     * 採用テイク動画クリップ (音声欠落テイクは anullsrc を map する)。
     *
     * @param  array<int, string>  $localSources
     * @return array{list<string>, list<string>, string, int}
     */
    private function planTakeVideo(RenderClipSpec $clip, array $localSources, string $assFile): array
    {
        $source = $this->sourceFor($clip, $localSources);
        $durationMs = $this->probeDurationMs($source);
        $hasAudio = $this->hasAudioStream($source);

        return [
            [
                '-i', $source,
                '-f', 'lavfi', '-t', $this->seconds($durationMs), '-i', 'anullsrc=r=48000:cl=stereo',
            ],
            ['-map', '0:v:0', '-map', $hasAudio ? '0:a:0' : '1:a:0'],
            $this->scaledFilter($assFile),
            $durationMs,
        ];
    }

    /**
     * 静止画クリップ (素材の**先頭フレーム**を stillDisplaySeconds 尺で保持 + 無音声)。
     *
     * 入力契約: 「1 枚目のフレームを取り出せる入力」であれば動画でも画像でもよい。
     * 1 段目の `-frames:v 1` は画像入力でも 1 枚の PNG を出すため、
     * 動画テイク由来の still と画像テイク由来の still を**同じ経路**で扱える。
     * 「画像なら中間 PNG 化を省く」最適化はしない (通る経路を 2 本にすると片方だけ壊れる形を作る)。
     *
     * 2 段目 (`-loop 1 -i frame{n}.png`) が読むのはサーバ生成 PNG だが、その画素数は
     * **入力素材と同じ**である。画素数の防波堤は FfmpegSafetyArguments の -max_alloc が
     * 全コマンドに一律で掛ける。
     *
     * @param  array<int, string>  $localSources
     * @return array{list<string>, list<string>, string, int}
     */
    private function planTakeStill(RenderClipSpec $clip, array $localSources, string $workDir, int $index, string $assFile): array
    {
        $source = $this->sourceFor($clip, $localSources);
        $seconds = $clip->stillDisplaySeconds;
        Assert::notNull($seconds, 'TakeStill は stillDisplaySeconds 必須 (マニフェスト構築で確定)');

        // 先頭フレームの静止画化
        $frameFile = "frame{$index}.png";
        $this->runFfmpeg($workDir, ['-y', '-i', $source, '-frames:v', '1', $frameFile], 'still frame extract');

        return [
            [
                '-loop', '1', '-t', (string) $seconds, '-i', $frameFile,
                '-f', 'lavfi', '-t', (string) $seconds, '-i', 'anullsrc=r=48000:cl=stereo',
            ],
            ['-map', '0:v:0', '-map', '1:a:0'],
            $this->scaledFilter($assFile),
            $seconds * 1000,
        ];
    }

    /**
     * プレビュー専用プレースホルダ (黒背景 + 字幕)。
     *
     * @return array{list<string>, list<string>, string, int}
     */
    private function planPlaceholder(string $assFile): array
    {
        [$width, $height] = $this->resolution();
        $fps = config()->integer('manual.render_fps');
        $seconds = config()->integer('manual.preview_placeholder_seconds');

        return [
            [
                '-f', 'lavfi', '-i', "color=black:s={$width}x{$height}:d={$seconds}",
                '-f', 'lavfi', '-t', (string) $seconds, '-i', 'anullsrc=r=48000:cl=stereo',
            ],
            ['-map', '0:v:0', '-map', '1:a:0'],
            "fps={$fps},subtitles={$assFile},format=yuv420p",
            $seconds * 1000,
        ];
    }

    /** 実素材用フィルタ列 (アスペクト維持で config 解像度へ正規化 + 字幕焼き込み) */
    private function scaledFilter(string $assFile): string
    {
        [$width, $height] = $this->resolution();
        $fps = config()->integer('manual.render_fps');

        return "scale={$width}:{$height}:force_original_aspect_ratio=decrease,"
            ."pad={$width}:{$height}:(ow-iw)/2:(oh-ih)/2,fps={$fps},subtitles={$assFile},format=yuv420p";
    }

    /**
     * concat demuxer による連結 (list.txt は work dir 内の連番ファイル名のみ)。
     *
     * @param  list<string>  $clipFiles
     */
    private function concat(string $workDir, array $clipFiles, string $outputFile): void
    {
        $list = '';
        foreach ($clipFiles as $file) {
            $list .= "file '{$file}'\n";
        }
        if (file_put_contents("{$workDir}/list.txt", $list) === false) {
            throw new RuntimeException("concat リストを書き込めません: {$workDir}/list.txt");
        }

        $this->runFfmpeg($workDir, [
            '-y', '-f', 'concat', '-safe', '0', '-i', 'list.txt', '-c', 'copy', $outputFile,
        ], 'concat');
    }

    /**
     * ffmpeg の実行 (非 0 終了は RenderCompositionException)。
     *
     * @param  list<string>  $arguments
     */
    private function runFfmpeg(string $workDir, array $arguments, string $stage): void
    {
        $binary = config()->string('manual.render_ffmpeg_binary');
        $result = Process::path($workDir)
            ->timeout(self::ENCODE_TIMEOUT_SECONDS)
            ->run([$binary, ...FfmpegSafetyArguments::all(), ...$arguments]);

        if (! $result->successful()) {
            throw new RenderCompositionException(
                "ffmpeg failed ({$stage}): ".mb_substr($result->errorOutput(), 0, 2000),
            );
        }
    }

    /** ffprobe による実測尺 (ms) */
    private function probeDurationMs(string $path): int
    {
        $binary = config()->string('manual.render_ffprobe_binary');
        $result = Process::timeout(self::PROBE_TIMEOUT_SECONDS)->run([
            $binary, ...FfmpegSafetyArguments::all(),
            '-v', 'error',
            '-show_entries', 'format=duration',
            '-of', 'default=noprint_wrappers=1:nokey=1',
            $path,
        ]);

        if (! $result->successful()) {
            throw new RenderCompositionException(
                'ffprobe failed (duration): '.mb_substr($result->errorOutput(), 0, 2000),
            );
        }

        $duration = trim($result->output());
        if ($duration === '' || ! is_numeric($duration)) {
            throw new RenderCompositionException("ffprobe returned non-numeric duration: {$duration}");
        }

        return (int) round(((float) $duration) * 1000);
    }

    /** 音声トラック有無の判定 (音声欠落テイクは anullsrc を map する) */
    private function hasAudioStream(string $path): bool
    {
        $binary = config()->string('manual.render_ffprobe_binary');
        $result = Process::timeout(self::PROBE_TIMEOUT_SECONDS)->run([
            $binary, ...FfmpegSafetyArguments::all(),
            '-v', 'error',
            '-select_streams', 'a',
            '-show_entries', 'stream=index',
            '-of', 'csv=p=0',
            $path,
        ]);

        if (! $result->successful()) {
            throw new RenderCompositionException(
                'ffprobe failed (audio streams): '.mb_substr($result->errorOutput(), 0, 2000),
            );
        }

        return trim($result->output()) !== '';
    }

    /**
     * @param  array<int, string>  $localSources
     */
    private function sourceFor(RenderClipSpec $clip, array $localSources): string
    {
        $source = $localSources[$clip->cutId] ?? null;
        Assert::string($source, "クリップ素材がありません (cut {$clip->cutId})");

        return $source;
    }

    /** ms → ffmpeg -t 引数 (秒。小数 3 桁) */
    private function seconds(int $durationMs): string
    {
        return sprintf('%.3f', $durationMs / 1000);
    }

    /**
     * @return array{int, int} [width, height]
     */
    private function resolution(): array
    {
        $resolution = config()->string('manual.render_resolution');
        $parts = explode('x', $resolution);
        Assert::count($parts, 2, 'manual.render_resolution は WxH 形式で設定してください');

        return [(int) $parts[0], (int) $parts[1]];
    }
}
