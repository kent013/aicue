<?php

declare(strict_types=1);

use Webmozart\Assert\Assert;

/*
 * git index に **NFC 正規化で衝突する path** が無いことを deny-by-default で固定する。
 *
 * なぜ必要か: `doc/reference/` に NFD 形と NFC 形の entry が両方載っており
 * (index 197 に対し実体 139)、正規化非依存 lookup の FS では 1 ファイルに潰れる。
 * worktree では「削除済み扱いの NFD entry + untracked 扱いの NFC ファイル」が現れて
 * `scripts/teardown-worktree.sh` の dirty チェックを**常に fail** させ、
 * `git worktree remove --force` による迂回 → `drop-test-db.php` を通らない →
 * **孤児テスト DB が単調増加**する、という運用事故の起点になっていた
 * (docs/tech-debt.md §5-3 / §5-4)。
 *
 * `core.precomposeunicode` は **`.git/config` のローカル設定**であってリポジトリの
 * 恒久対策にならない (clone した各人が設定しない限り効かない)。恒久対策は本ゲートである。
 *
 * 本テストは DB を触らない (git index の読み取りのみ)。
 */

/**
 * NFC 正規化して衝突する path のグループを返す (純関数)。
 *
 * @param  list<string>  $paths  index 上の path 一覧
 * @return array<string, list<string>> NFC path => 衝突している元 path 群 (2 件以上のみ)
 */
function gitIndexNormalizationCollisions(array $paths): array
{
    /** @var array<string, list<string>> $byNfc */
    $byNfc = [];
    foreach ($paths as $path) {
        $nfc = Normalizer::normalize($path, Normalizer::FORM_C);
        Assert::string($nfc, "path を NFC 正規化できない: {$path}");
        $byNfc[$nfc][] = $path;
    }

    return array_filter($byNfc, static fn (array $group): bool => count($group) > 1);
}

/**
 * `git ls-files -z` で index の全 path を読む。
 *
 * 失敗したら **skip せず fail** させる (偽グリーン禁止)。NUL 区切りを壊さないため
 * shell を介さず proc_open で引数配列のまま起動する。
 *
 * @return list<string>
 */
function gitIndexTrackedPaths(string $repositoryRoot): array
{
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open(['git', 'ls-files', '-z'], $descriptors, $pipes, $repositoryRoot);
    Assert::true(is_resource($process), 'git ls-files を起動できなかった (テスト環境に git が無い?)');

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    Assert::same(0, $exitCode, 'git ls-files -z が失敗した: '.(is_string($stderr) ? $stderr : ''));
    Assert::string($stdout, 'git ls-files -z の出力を取得できなかった');

    return array_values(array_filter(explode("\0", $stdout), static fn (string $p): bool => $p !== ''));
}

// ── N3: intl / git が使えないなら skip ではなく fail する ──

it('has the intl Normalizer available (fail instead of skipping)', function (): void {
    expect(extension_loaded('intl'))->toBeTrue()
        ->and(class_exists(Normalizer::class))->toBeTrue();
});

// ── N1 + N2: 実 index に衝突が無い / 空振りしていない ──

it('has no NFC-normalization collisions in the git index', function (): void {
    $paths = gitIndexTrackedPaths(base_path());

    // N2 空振り防止: 規模連動の高い閾値は将来の偽赤になるため、代表 path の存在検査を主にする。
    expect(count($paths))->toBeGreaterThanOrEqual(50)
        ->and($paths)->toContain('AGENTS.md')
        ->and($paths)->toContain('composer.json');

    $collisions = gitIndexNormalizationCollisions($paths);

    $report = implode("\n", array_map(
        static fn (string $nfc, array $group): string => '  '.$nfc.' <= '.implode(' | ', $group),
        array_keys($collisions),
        array_values($collisions),
    ));

    expect($collisions)->toBe([], <<<TXT
        git index に NFC 正規化で衝突する path があります (正規化非依存 FS で teardown が壊れます):
        {$report}
        重複している側の entry を `git rm --cached` で 1 つに揃えてください
        (詳細: docs/worktree-isolation-strategy.md §NFC/NFD 正規化)
        TXT);
});

// ── N4 / N5: 正コントロール (検出器が本当に検出できること) ──

it('detects an NFD/NFC pair as a collision', function (): void {
    $nfc = Normalizer::normalize('doc/reference/カテゴリ.png', Normalizer::FORM_C);
    $nfd = Normalizer::normalize('doc/reference/カテゴリ.png', Normalizer::FORM_D);
    Assert::string($nfc);
    Assert::string($nfd);
    expect($nfc)->not->toBe($nfd); // 前提: 濁点が結合文字に分解される

    $collisions = gitIndexNormalizationCollisions([$nfc, $nfd, 'README.md']);

    expect($collisions)->toHaveCount(1)
        ->and($collisions[$nfc])->toBe([$nfc, $nfd]);
});

it('detects a three-way collision as a single group', function (): void {
    $nfc = Normalizer::normalize('doc/カテゴリ/プレビュー.png', Normalizer::FORM_C);
    $nfd = Normalizer::normalize('doc/カテゴリ/プレビュー.png', Normalizer::FORM_D);
    Assert::string($nfc);
    Assert::string($nfd);
    // 片側だけ分解した第 3 の表現 (ディレクトリ名は NFD / ファイル名は NFC)
    $mixedHead = Normalizer::normalize('doc/カテゴリ/', Normalizer::FORM_D);
    Assert::string($mixedHead);
    $mixed = $mixedHead.Normalizer::normalize('プレビュー.png', Normalizer::FORM_C);

    $collisions = gitIndexNormalizationCollisions([$nfc, $nfd, $mixed]);

    expect($collisions)->toHaveCount(1)
        ->and($collisions[$nfc])->toHaveCount(3);
});

// ── N6 / N7: 負コントロール ──

it('reports no collision for pure NFC paths', function (): void {
    $paths = ['AGENTS.md', 'composer.json', 'doc/reference/sample-sop/手順書.md'];
    $normalized = array_map(static function (string $path): string {
        $nfc = Normalizer::normalize($path, Normalizer::FORM_C);
        Assert::string($nfc);

        return $nfc;
    }, $paths);

    expect(gitIndexNormalizationCollisions($normalized))->toBe([]);
});

it('reports no collision for distinct Japanese paths', function (): void {
    expect(gitIndexNormalizationCollisions([
        'doc/reference/カテゴリ.png',
        'doc/reference/カテゴリー.png',
        'doc/reference/かてごり.png',
        'doc/reference/mockups/動画アプリ/01_ログイン.png',
    ]))->toBe([]);
});
