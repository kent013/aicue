<?php

declare(strict_types=1);

use App\Support\Media\FfmpegSafetyArguments;
use Symfony\Component\Finder\Finder;

/*
 * 守る不変条件: app/ から起動する ffmpeg / ffprobe プロセスは 1 本残らず
 * FfmpegSafetyArguments::all() (= -max_alloc) をバイナリ直後に持つ。
 *
 * 検査 1 (母集団の pin): app/ 配下で 'manual.render_ffmpeg_binary' /
 *   'manual.render_ffprobe_binary' を参照するファイルを走査し、現行 3 ファイルと
 *   完全一致することを assert する (増減のどちらでも赤になる)。
 * 検査 2 (**起動点ごと**の pin): その 3 ファイルについて、プロセス起動式 (`->run(` に配列が続く形) の
 *   件数と `FfmpegSafetyArguments::all()` の出現件数が**一致**することを assert する。
 *   ファイル単位の「import があるか」だけだと、同じファイルへ安全引数なしの起動を
 *   足しても緑のままになる (PipelineSmokeCommand は起動点を 3 つ持つ)。
 * 検査 3 (値の pin): 安全境界引数が 2 要素 (-max_alloc + config 値) であること。
 *
 * ★ 保証範囲を誇張しない: これは**字句走査**である。
 *   - 動的に組み立てたコマンド配列・vendor 内部からのプロセス起動には沈黙する
 *   - 件数一致は「母集団の 3 ファイルでは配列引数の `->run(` がすべて ffmpeg / ffprobe 起動である」
 *     という現状に依存する。ffmpeg 以外のプロセス起動をこれらのファイルへ足すと赤になるので、
 *     そのときは分類を見直すこと (黙って通ることはない)
 *   - 数えるのは `->run(` に配列が続く**呼び出し表現**だけである。配列を変数へ組み立ててから
 *     `->run($args)` で渡す形・`start()` / `Process::run()` の静的呼び出し・vendor 経由の起動は
 *     数に入らない (= その形で起動点を足すと本検査は沈黙する)。空白と改行の揺れは吸収する
 *   - **引数の並び**(バイナリ直後にあること) は固定しない。それは Unit テスト
 *     (Process::fake の引数列: FfmpegVideoComposerTest / FfmpegTakeThumbnailExtractorTest) の担当である
 */

/**
 * app/ 配下で ffmpeg / ffprobe バイナリ設定キーを参照するファイル (app/ 相対パス)。
 *
 * @return list<string>
 */
function ffmpegBinaryReferencingFiles(): array
{
    $files = [];
    foreach (Finder::create()->files()->in(base_path('app'))->name('*.php') as $file) {
        $contents = $file->getContents();
        if (str_contains($contents, 'manual.render_ffmpeg_binary')
            || str_contains($contents, 'manual.render_ffprobe_binary')) {
            $files[] = str_replace(base_path('app').'/', '', $file->getPathname());
        }
    }
    sort($files);

    return $files;
}

test('ffmpeg / ffprobe を起動するファイルの母集団が pin されている', function (): void {
    expect(ffmpegBinaryReferencingFiles())->toBe([
        // 開発用の通し確認コマンド (合成素材を自分で作って自分で probe する)。
        // 入力は利用者由来ではないが、経路ごとに例外を作らず同じ安全境界を通す
        'Console/Commands/Development/PipelineSmokeCommand.php',
        'Services/Capture/FfmpegTakeThumbnailExtractor.php',
        'Services/Render/FfmpegVideoComposer.php',
    ]);
});

test('母集団の全ファイルが FfmpegSafetyArguments を import している', function (): void {
    $missing = [];
    foreach (ffmpegBinaryReferencingFiles() as $relative) {
        $contents = (string) file_get_contents(base_path("app/{$relative}"));
        if (! str_contains($contents, 'use App\Support\Media\FfmpegSafetyArguments;')) {
            $missing[] = $relative;
        }
    }

    expect($missing)->toBe([]);
});

test('起動点の件数と安全境界引数の付与件数が一致する', function (): void {
    // ファイル単位ではなく**起動点ごと**に見る。同じファイルへ安全引数なしの起動を
    // 足したら赤になる (import があるだけでは通さない)。
    $counts = [];
    foreach (ffmpegBinaryReferencingFiles() as $relative) {
        $contents = (string) file_get_contents(base_path("app/{$relative}"));
        // 空白・改行の揺れ (`->run ([` / `->run(\n    [`) を吸収する。
        // substr_count('run([') だと、この揺れで起動点を足しても件数が動かず黙って通る
        $counts[$relative] = [
            'launches' => preg_match_all('/->\s*run\s*\(\s*\[/', $contents),
            'guarded' => substr_count($contents, 'FfmpegSafetyArguments::all()'),
        ];
    }

    // 現行の起動点数も完全一致で pin する (件数が動いたら必ずレビューに出る)
    expect($counts)->toBe([
        'Console/Commands/Development/PipelineSmokeCommand.php' => ['launches' => 3, 'guarded' => 3],
        'Services/Capture/FfmpegTakeThumbnailExtractor.php' => ['launches' => 1, 'guarded' => 1],
        'Services/Render/FfmpegVideoComposer.php' => ['launches' => 3, 'guarded' => 3],
    ]);
});

test('安全境界引数はバイナリ直後に置く 2 要素である (-max_alloc + config 値)', function (): void {
    expect(FfmpegSafetyArguments::all())->toBe([
        '-max_alloc',
        (string) config()->integer('manual.ffmpeg_max_alloc_bytes'),
    ]);
});

test('母集団が空でない (degenerate PASS 防止)', function (): void {
    // 上の「全ファイルが経由している」検査が、Finder が 1 件も返さないことで
    // 緑になっていないことを示す。
    expect(ffmpegBinaryReferencingFiles())->not->toBe([]);
});
