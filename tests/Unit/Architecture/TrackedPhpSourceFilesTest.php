<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;
use Tests\Support\TrackedPhpSourceFiles;

/*
 * `TrackedPhpSourceFiles` の列挙結果を、一時 git リポジトリを作って固定する。
 *
 * ★実リポジトリでは負の対照 (未追跡ファイル・index に残った削除済みファイル) を
 *   作れないため、検体は一時ディレクトリに用意する。
 * ★git が無い / git worktree でない場所を渡したときは**空配列で黙らず例外**にする。
 *   ここで黙ると、この列挙器に乗る gate が丸ごと空振りして緑になる (fail-open)。
 */

/**
 * 検体用の一時 git リポジトリを作る。
 *
 * @return string 作成したディレクトリの絶対パス
 */
function trackedPhpSourceFilesFixtureRepository(): string
{
    // --parallel での衝突を避けるため、名前の確保は tempnam() で行う
    // (実ファイルが作られるので、消してから同名のディレクトリにする)。
    $path = tempnam(sys_get_temp_dir(), 'tracked-php-');
    if ($path === false) {
        throw new RuntimeException('検体用の一時ディレクトリ名を確保できませんでした');
    }
    unlink($path);
    if (! mkdir($path, 0o700) && ! is_dir($path)) {
        throw new RuntimeException("検体用の一時ディレクトリを作れませんでした: {$path}");
    }

    $run = function (array $command) use ($path): void {
        $process = new Process($command, $path);
        $process->run();
        if (! $process->isSuccessful()) {
            throw new RuntimeException(
                '検体リポジトリの準備に失敗しました: '.implode(' ', $command).' / '.$process->getErrorOutput()
            );
        }
    };

    $run(['git', 'init', '-q']);

    mkdir($path.'/sub', 0o700);
    file_put_contents($path.'/a.php', "<?php\n");
    file_put_contents($path.'/sub/b.php', "<?php\n");
    file_put_contents($path.'/c.blade.php', "<div></div>\n");
    file_put_contents($path.'/d.txt', "text\n");
    file_put_contents($path.'/tracked-then-deleted.php', "<?php\n");

    $run(['git', 'add', 'a.php', 'sub/b.php', 'c.blade.php', 'd.txt', 'tracked-then-deleted.php']);

    // 追跡した後にファイルだけ消す (index には残る)
    unlink($path.'/tracked-then-deleted.php');

    // 未追跡ファイル
    file_put_contents($path.'/untracked.php', "<?php\n");

    return $path;
}

/** 確保した一時ディレクトリだけを再帰削除する (誤ったパスを消さないための guard 付き)。 */
function trackedPhpSourceFilesCleanup(string $path): void
{
    $expectedPrefix = rtrim(sys_get_temp_dir(), '/').'/tracked-php-';
    if (! str_starts_with($path, $expectedPrefix) || ! is_dir($path)) {
        throw new RuntimeException("後片付けの対象が想定外のパスです: {$path}");
    }

    $process = new Process(['rm', '-rf', $path]);
    $process->run();
}

test('追跡下 PHP 列挙器: 追跡下の *.php だけを昇順で返す', function (): void {
    $repository = trackedPhpSourceFilesFixtureRepository();

    try {
        $files = TrackedPhpSourceFiles::all($repository);
        $relatives = array_column($files, 'relative');

        // 正の対照: 追跡下の *.php だけが昇順で並ぶ
        //   負の対照 1: blade (c.blade.php) を含まない
        //   負の対照 2: 未追跡 (untracked.php) を含まない
        //   負の対照 3: 拡張子違い (d.txt) を含まない
        //   負の対照 4: index に残った削除済み (tracked-then-deleted.php) を含まない
        expect($relatives)->toBe(['a.php', 'sub/b.php']);

        // absolute は root からの実在パスであること
        foreach ($files as $file) {
            expect($file['absolute'])->toBe($repository.'/'.$file['relative']);
            expect(is_file($file['absolute']))->toBeTrue();
        }
    } finally {
        trackedPhpSourceFilesCleanup($repository);
    }
});

test('追跡下 PHP 列挙器: git worktree でない場所は空配列でなく例外にする', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'tracked-php-');
    expect($path)->not->toBeFalse();
    unlink((string) $path);
    mkdir((string) $path, 0o700);

    try {
        expect(fn () => TrackedPhpSourceFiles::all((string) $path))->toThrow(RuntimeException::class);
    } finally {
        trackedPhpSourceFilesCleanup((string) $path);
    }
});

test('追跡下 PHP 列挙器: 実リポジトリに対して疎通する', function (): void {
    $files = TrackedPhpSourceFiles::all(base_path());
    $relatives = array_column($files, 'relative');

    expect(count($files))->toBeGreaterThanOrEqual(1400);
    expect($relatives)->toContain('tests/Support/TrackedPhpSourceFiles.php');
    // blade が母集団に入らないことを実リポジトリでも確認する
    expect($relatives)->not->toContain('resources/views/app.blade.php');
});
