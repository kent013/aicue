<?php

declare(strict_types=1);

use App\Enums\Manual\MaterialType;
use App\Exceptions\Capture\TakeThumbnailExtractionException;
use App\Services\Capture\FfmpegTakeThumbnailExtractor;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/*
 * ffmpeg 1 フレーム抽出のコマンド構造と再試行 (Process::fake。実 ffmpeg に触れない):
 * - 安全境界の引数 (-nostdin / -protocol_whitelist file / 配列渡し)
 * - 出力寸法・品質が config 固定
 * - 尺不足で 0 バイトなら seek=0 で 1 回だけ再試行する
 * - 実行前に出力先を消す (1 回目の残骸を成功と誤認しない)
 * - 安全境界の -max_alloc が**バイナリ直後**に付く (画素数爆弾で worker を落とさない)
 * - 静止画は seek=0 の 1 回だけ (「1 秒地点」が存在しない)
 *
 * ★ 実バイナリの挙動差 (`-frames:v 1` + `-f image2` の出力有無) は本テストでは検出しない。
 *   実バイナリでの通し確認は bug-hunt の pipeline-smoke (別基盤) の領域である。
 */

/** 一時作業ディレクトリ (実行ごとに一意) */
function thumbnailWorkDir(): string
{
    $dir = sys_get_temp_dir().'/thumb-extract-'.uniqid();
    mkdir($dir);

    return $dir;
}

/**
 * Process::fake + 実行コマンドの収集。$onRun は実行のたびに (回数, 出力先) で呼ばれ、
 * 出力ファイルの生成/非生成を決定的に作る。
 *
 * @param  list<string>  $recorded  (参照で埋まる。1 要素 = 1 コマンドの space 連結)
 * @param  callable(int, string): int  $onRun  戻り値 = 終了コード
 */
function fakeThumbnailFfmpeg(array &$recorded, callable $onRun): void
{
    $attempt = 0;
    Process::fake(function (PendingProcess $process) use (&$recorded, &$attempt, $onRun) {
        $command = $process->command;
        $parts = is_array($command) ? array_map(strval(...), $command) : [(string) $command];
        $recorded[] = implode(' ', $parts);
        $attempt++;
        $destination = $parts[count($parts) - 1];
        $exitCode = $onRun($attempt, $destination);

        return Process::result(output: '', errorOutput: $exitCode === 0 ? '' : 'ffmpeg boom', exitCode: $exitCode);
    });
}

test('コマンド構造: 安全境界の引数と config 由来の寸法・品質を持つ', function (): void {
    $recorded = [];
    fakeThumbnailFfmpeg($recorded, function (int $attempt, string $destination): int {
        file_put_contents($destination, 'jpeg');

        return 0;
    });
    $workDir = thumbnailWorkDir();

    app(FfmpegTakeThumbnailExtractor::class)->extract("{$workDir}/source", "{$workDir}/thumbnail.jpg", MaterialType::Video);

    expect($recorded)->toHaveCount(1);
    $line = $recorded[0];
    expect($line)->toContain('-nostdin');
    expect($line)->toContain('-protocol_whitelist file');
    expect($line)->toContain('-frames:v 1');
    expect($line)->toContain('-vf scale=640:640:force_original_aspect_ratio=decrease');
    expect($line)->toContain('-q:v 5');
    expect($line)->toContain('-f image2');
    // -ss は config の thumbnail_seek_ms (1000) を秒へ変換した値
    expect($line)->toContain('-ss 1.000');
    // 引数はサーバ生成のパス 2 つだけ (利用者由来の文字列は 1 つも入らない)
    expect($line)->toContain("-i {$workDir}/source");
    expect($line)->toContain("{$workDir}/thumbnail.jpg");
});

test('安全境界の -max_alloc がバイナリ直後 (argv[1], argv[2]) に付く', function (): void {
    // 配置を「最初の -i より前」ではなくバイナリ直後で固定する。ffprobe は入力を位置引数で
    // 受けるため、-i を基準にすると同じ検査が別コマンドで空振りする。
    $commands = [];
    Process::fake(function (PendingProcess $process) use (&$commands) {
        $command = $process->command;
        $commands[] = is_array($command) ? array_map(strval(...), $command) : [(string) $command];
        file_put_contents(is_array($command) ? (string) $command[count($command) - 1] : '', 'jpeg');

        return Process::result(output: '', errorOutput: '', exitCode: 0);
    });
    $workDir = thumbnailWorkDir();

    app(FfmpegTakeThumbnailExtractor::class)
        ->extract("{$workDir}/source", "{$workDir}/thumbnail.jpg", MaterialType::Video);

    expect($commands)->toHaveCount(1);
    expect($commands[0][1])->toBe('-max_alloc');
    expect($commands[0][2])->toBe((string) config()->integer('manual.ffmpeg_max_alloc_bytes'));
});

test('静止画は seek=0 の 1 回だけ実行する (再試行しない)', function (): void {
    $recorded = [];
    fakeThumbnailFfmpeg($recorded, function (int $attempt, string $destination): int {
        file_put_contents($destination, 'jpeg');

        return 0;
    });
    $workDir = thumbnailWorkDir();

    app(FfmpegTakeThumbnailExtractor::class)
        ->extract("{$workDir}/source", "{$workDir}/thumbnail.jpg", MaterialType::Still);

    expect($recorded)->toHaveCount(1);
    expect($recorded[0])->toContain('-ss 0.000'); // 動画既定の 1000ms を当てない
});

test('静止画で失敗したら再試行せずそのまま例外にする', function (): void {
    $recorded = [];
    fakeThumbnailFfmpeg($recorded, fn (int $attempt, string $destination): int => 1);
    $workDir = thumbnailWorkDir();

    expect(fn () => app(FfmpegTakeThumbnailExtractor::class)
        ->extract("{$workDir}/source", "{$workDir}/thumbnail.jpg", MaterialType::Still))
        ->toThrow(TakeThumbnailExtractionException::class, 'ffmpeg failed (thumbnail): ffmpeg boom');

    expect($recorded)->toHaveCount(1);
});

test('尺不足で 1 回目が 0 バイトなら seek=0 で 1 回だけ再試行し、成功すれば例外を投げない', function (): void {
    $recorded = [];
    fakeThumbnailFfmpeg($recorded, function (int $attempt, string $destination): int {
        if ($attempt === 1) {
            file_put_contents($destination, ''); // 終了コード 0 のまま 0 バイト
        } else {
            file_put_contents($destination, 'jpeg');
        }

        return 0;
    });
    $workDir = thumbnailWorkDir();

    app(FfmpegTakeThumbnailExtractor::class)->extract("{$workDir}/source", "{$workDir}/thumbnail.jpg", MaterialType::Video);

    expect($recorded)->toHaveCount(2);
    expect($recorded[0])->toContain('-ss 1.000');
    expect($recorded[1])->toContain('-ss 0.000'); // 先頭へ倒した再試行 (これ以上の探索はしない)
});

test('2 回とも失敗すると TakeThumbnailExtractionException で stderr の先頭が入る', function (): void {
    $recorded = [];
    fakeThumbnailFfmpeg($recorded, fn (int $attempt, string $destination): int => 1);
    $workDir = thumbnailWorkDir();

    expect(fn () => app(FfmpegTakeThumbnailExtractor::class)
        ->extract("{$workDir}/source", "{$workDir}/thumbnail.jpg", MaterialType::Video))
        ->toThrow(TakeThumbnailExtractionException::class, 'ffmpeg failed (thumbnail): ffmpeg boom');

    expect($recorded)->toHaveCount(2);
});

test('1 回目の残骸を成功と誤認しない (実行前に出力先を削除する)', function (): void {
    // 1 回目: 非 0 終了しつつ**非空ファイルを残す** / 2 回目: 終了コード 0 のまま何も出さない。
    // 実行前削除が無いと、2 回目の実体検査が 1 回目の残骸を見て「成功」と誤認する。
    $recorded = [];
    fakeThumbnailFfmpeg($recorded, function (int $attempt, string $destination): int {
        if ($attempt === 1) {
            file_put_contents($destination, 'broken-leftover');

            return 1;
        }

        return 0; // 出力を作らない
    });
    $workDir = thumbnailWorkDir();

    expect(fn () => app(FfmpegTakeThumbnailExtractor::class)
        ->extract("{$workDir}/source", "{$workDir}/thumbnail.jpg", MaterialType::Video))
        ->toThrow(TakeThumbnailExtractionException::class, 'ffmpeg produced no frame (seek=0ms)');
});

test('出力先を削除できない場合も失敗として扱う (OS 権限に依存させず File facade で作る)', function (): void {
    // ★ 素の unlink() を使わない理由: 失敗時の E_WARNING を Laravel のエラーハンドラが
    //   ErrorException へ変換する環境では「失敗理由を返す」契約から外れる。
    //   File::delete() + 存在確認なら判定が戻り値だけで閉じるので、ここでは
    //   File facade を差し替えて「削除が効かなかった」状況を決定的に作る。
    $recorded = [];
    fakeThumbnailFfmpeg($recorded, fn (int $attempt, string $destination): int => 0);

    File::shouldReceive('isFile')->andReturnTrue();
    File::shouldReceive('delete')->andReturnFalse();

    expect(fn () => app(FfmpegTakeThumbnailExtractor::class)
        ->extract('/tmp/thumb-source', '/tmp/thumb-out.jpg', MaterialType::Video))
        ->toThrow(TakeThumbnailExtractionException::class, 'failed to remove stale thumbnail output');

    // 削除できなかった時点で ffmpeg を 1 回も起動しない
    expect($recorded)->toBe([]);
});
