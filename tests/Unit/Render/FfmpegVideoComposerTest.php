<?php

declare(strict_types=1);

use App\DataTransferObjects\Manual\Render\RenderClipSource;
use App\DataTransferObjects\Manual\Render\RenderClipSpec;
use App\DataTransferObjects\Manual\Render\RenderManifest;
use App\Enums\Manual\RenderKind;
use App\Exceptions\Manual\RenderCompositionException;
use App\Services\Render\FfmpegVideoComposer;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;

/*
 * ffmpeg 合成のコマンド構造 (Process::fake。実 ffmpeg に触れない):
 * - filtergraph に字幕本文が現れない (subtitles= は workdir 配下の .ass ファイル名のみ)
 * - 解像度・fps が config 値 / ffprobe 出力から実測尺を導出 / 非 0 終了で例外
 */

const ATTACK_SUBTITLE = "字幕本文{\\an8}':,攻撃";

function composerManifest(RenderClipSpec ...$clips): RenderManifest
{
    return new RenderManifest(
        renderJobId: 10,
        kind: RenderKind::Preview,
        scenarioVersion: 2,
        outputKey: 'projects/1/manuals/1/previews/v2-10.mp4',
        clips: array_values($clips),
    );
}

function takeVideoClip(int $cutId = 1): RenderClipSpec
{
    return new RenderClipSpec(
        cutId: $cutId,
        label: '手順1',
        source: RenderClipSource::TakeVideo,
        takeVideoPath: 'takes/src.mp4',
        stillDisplaySeconds: null,
        subtitlePrimary: null,
        subtitleSecondary: ATTACK_SUBTITLE,
    );
}

function takeStillClip(int $cutId = 1, int $seconds = 4): RenderClipSpec
{
    return new RenderClipSpec(
        cutId: $cutId,
        label: '手順1',
        source: RenderClipSource::TakeStill,
        takeVideoPath: 'takes/src-still.mp4',
        stillDisplaySeconds: $seconds,
        subtitlePrimary: null,
        subtitleSecondary: ATTACK_SUBTITLE,
    );
}

function placeholderClip(int $cutId = 2): RenderClipSpec
{
    return new RenderClipSpec(
        cutId: $cutId,
        label: '手順2',
        source: RenderClipSource::Placeholder,
        takeVideoPath: null,
        stillDisplaySeconds: null,
        subtitlePrimary: null,
        subtitleSecondary: ATTACK_SUBTITLE,
    );
}

function composerWorkDir(): string
{
    $dir = sys_get_temp_dir().'/composer-test-'.uniqid();
    mkdir($dir);

    return $dir;
}

/**
 * Process::fake + 実行コマンドの収集。ffprobe の duration は 2.5 秒を返す。
 *
 * @param  list<string>  $recorded  (参照で埋まる。1 要素 = 1 コマンドの space 連結)
 */
function fakeFfmpegProcesses(array &$recorded, int $ffmpegExitCode = 0): void
{
    Process::fake(function (PendingProcess $process) use (&$recorded, $ffmpegExitCode) {
        $command = $process->command;
        $line = is_array($command) ? implode(' ', array_map(strval(...), $command)) : (string) $command;
        $recorded[] = $line;

        if (str_contains($line, '-select_streams')) {
            return Process::result(output: ''); // 音声トラックなし
        }
        if (str_contains($line, '-show_entries')) {
            return Process::result(output: "2.500000\n"); // 実測尺 2.5s
        }

        return Process::result(output: '', errorOutput: $ffmpegExitCode === 0 ? '' : 'boom', exitCode: $ffmpegExitCode);
    });
}

test('filtergraph に字幕本文が現れない (subtitles= は workdir 配下の .ass ファイル名のみ)', function (): void {
    $recorded = [];
    fakeFfmpegProcesses($recorded);
    $workDir = composerWorkDir();
    file_put_contents("{$workDir}/../src.mp4", 'x');

    app(FfmpegVideoComposer::class)->compose(
        composerManifest(takeVideoClip(), placeholderClip()),
        [1 => "{$workDir}/src1.mp4"],
        $workDir,
        function (): void {},
    );

    expect($recorded)->not->toBeEmpty();
    foreach ($recorded as $line) {
        expect($line)->not->toContain('字幕本文');
        expect($line)->not->toContain('{\\an8}');
    }
    // subtitles= の値はサーバ生成の連番 .ass のみ
    $vfLines = array_values(array_filter($recorded, fn (string $line): bool => str_contains($line, 'subtitles=')));
    expect($vfLines)->not->toBeEmpty();
    foreach ($vfLines as $line) {
        expect((bool) preg_match('/subtitles=clip\d+\.ass[,\s]/', $line))->toBeTrue();
    }
    // 字幕本文は ASS ファイル側に (正規化済みで) 出力されている
    $ass = (string) file_get_contents("{$workDir}/clip0.ass");
    expect($ass)->toContain('字幕本文');
});

test('解像度・fps が config 値でコマンドに現れる', function (): void {
    config()->set('manual.render_resolution', '1280x720');
    config()->set('manual.render_fps', 24);
    $recorded = [];
    fakeFfmpegProcesses($recorded);
    $workDir = composerWorkDir();

    app(FfmpegVideoComposer::class)->compose(
        composerManifest(takeVideoClip()),
        [1 => "{$workDir}/src1.mp4"],
        $workDir,
        function (): void {},
    );

    $encode = implode("\n", $recorded);
    expect($encode)->toContain('scale=1280:720:force_original_aspect_ratio=decrease');
    expect($encode)->toContain('fps=24');
});

test('ffprobe 出力から clipDurationsMs / total が導出される + 進捗 callback', function (): void {
    $recorded = [];
    fakeFfmpegProcesses($recorded);
    $workDir = composerWorkDir();
    $progress = [];

    $composed = app(FfmpegVideoComposer::class)->compose(
        composerManifest(takeVideoClip(1), placeholderClip(2)),
        [1 => "{$workDir}/src1.mp4"],
        $workDir,
        function (int $done, int $total) use (&$progress): void {
            $progress[] = [$done, $total];
        },
    );

    expect($composed->clipDurationsMs)->toBe([1 => 2_500, 2 => 2_500]);
    expect($composed->totalDurationMs)->toBe(5_000);
    expect($composed->localPath)->toBe("{$workDir}/output.mp4");
    expect($progress)->toBe([[1, 2], [2, 2]]);
    // concat リストは workdir 内の連番ファイル名のみ
    $list = (string) file_get_contents("{$workDir}/list.txt");
    expect($list)->toBe("file 'clip0.mp4'\nfile 'clip1.mp4'\n");
});

test('音声トラックなしのテイクは anullsrc (第 2 入力) を map する', function (): void {
    $recorded = [];
    fakeFfmpegProcesses($recorded); // -select_streams は空 = 音声なし
    $workDir = composerWorkDir();

    app(FfmpegVideoComposer::class)->compose(
        composerManifest(takeVideoClip()),
        [1 => "{$workDir}/src1.mp4"],
        $workDir,
        function (): void {},
    );

    $encodeLine = collect($recorded)->first(
        fn (string $line): bool => str_contains($line, 'libx264'),
    );
    expect($encodeLine)->toContain('anullsrc=r=48000:cl=stereo');
    expect($encodeLine)->toContain('-map 0:v:0 -map 1:a:0');
});

test('静止画カット (TakeStill): 先頭フレーム抽出 → -loop 1 -t 秒ループ + anullsrc / 尺は stillDisplaySeconds×1000', function (): void {
    $recorded = [];
    fakeFfmpegProcesses($recorded);
    $workDir = composerWorkDir();

    app(FfmpegVideoComposer::class)->compose(
        composerManifest(takeStillClip(cutId: 1, seconds: 4)),
        [1 => "{$workDir}/src0.mp4"],
        $workDir,
        function (): void {},
    );

    // 1) 先頭フレーム抽出 (-frames:v 1 → 連番 frame ファイル)
    $frameLine = collect($recorded)->first(
        fn (string $line): bool => str_contains($line, '-frames:v'),
    );
    expect($frameLine)->not->toBeNull();
    expect($frameLine)->toContain("-i {$workDir}/src0.mp4");
    expect($frameLine)->toContain('-frames:v 1 frame0.png');

    // 2) エンコードは静止画ループ (-loop 1 -t {sec}) + 無音声 anullsrc (第 2 入力) の map
    $encodeLine = collect($recorded)->first(
        fn (string $line): bool => str_contains($line, 'libx264'),
    );
    expect($encodeLine)->toContain('-loop 1 -t 4 -i frame0.png');
    expect($encodeLine)->toContain('-f lavfi -t 4 -i anullsrc=r=48000:cl=stereo');
    expect($encodeLine)->toContain('-map 0:v:0 -map 1:a:0');

    // 3) 尺導出: ASS 字幕の End が stillDisplaySeconds×1000 (= 4 秒) で固定される
    $ass = (string) file_get_contents("{$workDir}/clip0.ass");
    expect($ass)->toContain(',0:00:04.00,');

    // 4) 字幕本文はコマンド (filtergraph 含む) に一切現れない (.ass ファイル名のみ)
    foreach ($recorded as $line) {
        expect($line)->not->toContain('字幕本文');
        expect($line)->not->toContain('{\\an8}');
    }
    expect((bool) preg_match('/subtitles=clip\d+\.ass[,\s]/', (string) $encodeLine))->toBeTrue();
});

test('ffmpeg 非 0 終了で RenderCompositionException', function (): void {
    $recorded = [];
    fakeFfmpegProcesses($recorded, ffmpegExitCode: 1);
    $workDir = composerWorkDir();

    expect(fn () => app(FfmpegVideoComposer::class)->compose(
        composerManifest(placeholderClip()),
        [],
        $workDir,
        function (): void {},
    ))->toThrow(RenderCompositionException::class, 'ffmpeg failed');
});

test('ffprobe が非数値を返したら RenderCompositionException', function (): void {
    Process::fake(function (PendingProcess $process) {
        $command = $process->command;
        $line = is_array($command) ? implode(' ', array_map(strval(...), $command)) : (string) $command;
        if (str_contains($line, '-show_entries')) {
            return Process::result(output: 'not-a-number');
        }

        return Process::result(output: '');
    });
    $workDir = composerWorkDir();

    expect(fn () => app(FfmpegVideoComposer::class)->compose(
        composerManifest(placeholderClip()),
        [],
        $workDir,
        function (): void {},
    ))->toThrow(RenderCompositionException::class, 'non-numeric');
});
