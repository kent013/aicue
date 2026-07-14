<?php

declare(strict_types=1);

use App\DataTransferObjects\Manual\Render\ComposedLocalVideo;
use App\DataTransferObjects\Manual\Render\RenderClipSource;
use App\DataTransferObjects\Manual\Render\RenderClipSpec;
use App\DataTransferObjects\Manual\Render\RenderManifest;
use App\Enums\Manual\RenderKind;
use App\Services\Render\FfmpegVideoComposer;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/*
 * 実 ffmpeg / ffprobe を用いた合成疎通 smoke (bug-hunt F-1-0b / ffmpeg-provisioning 層 2)。
 * 既存 FfmpegVideoComposerTest は Process::fake でコマンド構造を検証する。本ファイルは
 * 実バイナリを起動し「日本語字幕を焼き込んだ最小合成が正常終了し mp4 が出力される」ことを検証する。
 * skip はローカル任意環境の便宜であり、CI では ffmpeg 導入を層 1 (ci.yml) が fail-fast で強制する。
 */

/** config 済みの ffmpeg / ffprobe が実行可能か (skip guard の指標。config 値を尊重・例外も未導入扱い) */
function renderBinariesAvailable(): bool
{
    try {
        foreach (['manual.render_ffmpeg_binary', 'manual.render_ffprobe_binary'] as $key) {
            $binary = config()->string($key);
            // バイナリ不在時に Process 実装差異で例外化しても「未導入」として確実に skip させる
            if (! Process::run([$binary, '-version'])->successful()) {
                return false;
            }
        }
    } catch (Throwable) {
        return false;
    }

    return true;
}

/** 一意な作業ディレクトリ (並列テスト安全。呼び出し側で try/finally 削除する) */
function smokeWorkDir(): string
{
    $dir = sys_get_temp_dir().'/ffmpeg-smoke-'.bin2hex(random_bytes(8));
    if (! mkdir($dir, 0o755, true) && ! is_dir($dir)) {
        throw new RuntimeException("smoke work dir を作成できません: {$dir}");
    }

    return $dir;
}

test('実 ffmpeg で日本語字幕を焼き込んだ最小合成が成功し mp4 を出力する', function (): void {
    // 実エンコードを軽量化 (疎通が目的。画素比較はしない)
    config()->set('manual.render_resolution', '320x240');
    config()->set('manual.render_fps', 5);
    config()->set('manual.preview_placeholder_seconds', 1);
    config()->set('manual.render_subtitle_font', 'Noto Sans CJK JP'); // 再現性のため明示

    $workDir = smokeWorkDir();

    try {
        $manifest = new RenderManifest(
            renderJobId: 1,
            kind: RenderKind::Preview,
            scenarioVersion: 1,
            outputKey: 'projects/1/manuals/1/previews/v1-1.mp4',
            clips: [new RenderClipSpec(
                cutId: 1,
                label: '手順1',
                source: RenderClipSource::Placeholder, // 素材ダウンロード不要 (黒背景 + 字幕)
                takeVideoPath: null,
                stillDisplaySeconds: null,
                subtitlePrimary: null,
                subtitleSecondary: 'これは疎通確認用の日本語字幕です。', // libass + フォント解決を通す
            )],
        );

        $composed = app(FfmpegVideoComposer::class)->compose(
            $manifest,
            [], // Placeholder は localSources 不要
            $workDir,
            function (): void {},
        );

        expect($composed)->toBeInstanceOf(ComposedLocalVideo::class);
        expect(is_file($composed->localPath))->toBeTrue();            // output.mp4 が存在
        expect(filesize($composed->localPath))->toBeGreaterThan(0);   // 空でない
        expect($composed->totalDurationMs)->toBeGreaterThan(0);       // ffprobe が尺を読めた
    } finally {
        // 作成した work dir のみを確実に削除 (sys_get_temp_dir 全 glob はしない。design-review R1)
        File::deleteDirectory($workDir);
    }
})->skip(fn (): bool => ! renderBinariesAvailable(), 'ffmpeg/ffprobe 未導入のため skip (層 1 が CI で fail-fast)');
