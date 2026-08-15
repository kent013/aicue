<?php

declare(strict_types=1);

use Webmozart\Assert\Assert;

/*
 * 台帳規約 (AGENTS.md): 「scripts/ へスクリプトを追加したら scripts/README.md の表に 1 行追記する」
 * を deny-by-default で機械強制する。
 *
 * 本テストを足す理由: この規約は実際にドリフトした (make-shard-phpunit.php が
 * 「CI から自動呼び出し」と書かれたまま、どこからも呼ばれていなかった)。
 * 禁止事項 1 に従い、不変条件を Architecture テストへ登録するところまでを「実装済み」とする。
 *
 * 本テストは DB を触らない (ファイル読み取りのみ)。
 */

/**
 * 台帳登録の対象外 (`scripts/` からの相対パス)。理由を書けないものをここに足さないこと。
 *
 * @var array<string, string>
 */
const SCRIPTS_README_EXEMPT = [
    // 台帳そのもの。自分を自分の表へ登録するのは同語反復なので対象外にする。
    'README.md' => '台帳ファイル自身 (表の正本であって、表に載る対象ではない)',
];

/**
 * `scripts/` 配下を **再帰的に** 走査してファイルの相対パスを返す (純関数)。
 *
 * `scripts/*` + `scripts/ci/*` の 2 階層だけを見る実装は、将来 `scripts/foo/bar.sh` が
 * 増えたときに黙って漏れる = deny-by-default を名乗れない。
 *
 * @return list<string> `scripts/` からの相対パス (昇順)
 */
function scriptsDirectoryFiles(string $scriptsDir): array
{
    $found = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($scriptsDir, FilesystemIterator::SKIP_DOTS),
    );

    foreach ($iterator as $file) {
        if (! $file instanceof SplFileInfo || ! $file->isFile()) {
            continue;
        }
        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($scriptsDir) + 1));
        // Python の bytecode キャッシュは git 管理外の生成物 (.gitignore 済み)。
        // 台帳は「人が書いたスクリプト」の一覧なので、自己テストを手で走らせた副産物で
        // 無関係な gate が赤くならないように母集団から外す。
        if (str_contains($relative, '__pycache__/')) {
            continue;
        }
        $found[] = $relative;
    }

    sort($found);

    return $found;
}

/**
 * README の表を「相対パス => [用途, 実行タイミング]」へ正規化する (純関数)。
 *
 * パースは「行頭 `|` + 1 列目がバッククォート囲み」に限定する
 * (見出し行 / 区切り行 / 説明文を巻き込まないため)。
 *
 * @return array<string, array{purpose: string, timing: string}>
 */
function scriptsReadmeRows(string $markdown): array
{
    $rows = [];

    // 改行分割に `\R` を使わないこと: `/u` 無しの PCRE では `\R` が NEL (0x85) にも一致し、
    // 日本語 UTF-8 テキスト (0x85 を含む多バイト文字が頻出) を**文字の途中で分断**する。
    // 実測でこの表の行が壊れて S1 の偽陽性が出た。改行だけを明示列挙する。
    foreach (preg_split('/\r\n|\r|\n/', $markdown) ?: [] as $line) {
        $line = trim($line);
        if (! str_starts_with($line, '|')) {
            continue;
        }

        $cells = array_map(trim(...), explode('|', trim($line, '|')));
        if (count($cells) < 3) {
            continue;
        }
        if (preg_match('/^`([^`]+)`$/', $cells[0], $matches) !== 1) {
            continue;
        }

        $rows[$matches[1]] = ['purpose' => $cells[1], 'timing' => $cells[2]];
    }

    return $rows;
}

/**
 * 台帳と実ファイルの乖離を列挙する (純関数)。
 *
 * 実ファイルを読まない純関数に切り出すのは、負のコントロール (検出器が空振りしていないこと)
 * を fixture で確認できるようにするため。
 *
 * @param  list<string>  $files  `scripts/` からの相対パス
 * @param  array<string, array{purpose: string, timing: string}>  $rows
 * @param  array<string, string>  $exempt  相対パス => 除外理由
 * @return list<string> 違反一覧 (空 = 合格)
 */
function scriptsReadmeInventoryViolations(array $files, array $rows, array $exempt): array
{
    $violations = [];

    // S1: scripts/ 配下の全ファイル (明示 exemption を除く) が README の表に行を持つ
    foreach ($files as $relative) {
        if (array_key_exists($relative, $exempt)) {
            continue;
        }
        if (! array_key_exists($relative, $rows)) {
            $violations[] = "S1: scripts/{$relative} が scripts/README.md の表に無い (追加時は 1 行追記すること)";
        }
    }

    // S2: README の表の全行に対応する実ファイルがある
    foreach ($rows as $relative => $_row) {
        if (! in_array($relative, $files, true)) {
            $violations[] = "S2: scripts/README.md の行 `{$relative}` に対応する実ファイルが無い (削除時は行も消すこと)";
        }
    }

    // S3: 各行の「用途」「実行タイミング」列が空でない
    foreach ($rows as $relative => $row) {
        if ($row['purpose'] === '') {
            $violations[] = "S3: scripts/README.md の行 `{$relative}` の用途が空";
        }
        if ($row['timing'] === '') {
            $violations[] = "S3: scripts/README.md の行 `{$relative}` の実行タイミングが空";
        }
    }

    // S4: exemption が実在ファイルを指し、理由が非空であること
    foreach ($exempt as $relative => $reason) {
        if (! in_array($relative, $files, true)) {
            $violations[] = "S4: exemption `{$relative}` が実在しない (死んだ除外の残置)";
        }
        if (trim($reason) === '') {
            $violations[] = "S4: exemption `{$relative}` の理由が空 (理由なし除外は認めない)";
        }
    }

    return $violations;
}

test('scripts/ 配下の全ファイルが scripts/README.md の台帳と一致すること', function (): void {
    $markdown = file_get_contents(base_path('scripts/README.md'));
    Assert::string($markdown, 'scripts/README.md を読めない');

    $violations = scriptsReadmeInventoryViolations(
        scriptsDirectoryFiles(base_path('scripts')),
        scriptsReadmeRows($markdown),
        SCRIPTS_README_EXEMPT,
    );

    expect($violations)->toBe([], "scripts/README.md 台帳の乖離:\n".implode("\n", $violations));
});

test('S1 負のコントロール: 台帳に無いファイルを検出すること', function (): void {
    $violations = scriptsReadmeInventoryViolations(
        ['README.md', 'a.sh', 'ci/new-thing.php'],
        ['a.sh' => ['purpose' => 'x', 'timing' => 'y']],
        SCRIPTS_README_EXEMPT,
    );

    expect($violations)->toContain(
        'S1: scripts/ci/new-thing.php が scripts/README.md の表に無い (追加時は 1 行追記すること)',
    );
});

test('S2 負のコントロール: 実体の無い行を検出すること (今回のドリフトそのもの)', function (): void {
    $violations = scriptsReadmeInventoryViolations(
        ['README.md', 'a.sh'],
        [
            'a.sh' => ['purpose' => 'x', 'timing' => 'y'],
            'ci/make-shard-phpunit.php' => ['purpose' => 'sharding', 'timing' => 'CI から自動呼び出し'],
        ],
        SCRIPTS_README_EXEMPT,
    );

    expect($violations)->toContain(
        'S2: scripts/README.md の行 `ci/make-shard-phpunit.php` に対応する実ファイルが無い (削除時は行も消すこと)',
    );
});

test('S3 負のコントロール: 実行タイミングが空の行を検出すること', function (): void {
    $violations = scriptsReadmeInventoryViolations(
        ['README.md', 'a.sh'],
        ['a.sh' => ['purpose' => 'x', 'timing' => '']],
        SCRIPTS_README_EXEMPT,
    );

    expect($violations)->toContain('S3: scripts/README.md の行 `a.sh` の実行タイミングが空');
});

test('S4 負のコントロール: 死んだ exemption と理由なし exemption を検出すること', function (): void {
    $violations = scriptsReadmeInventoryViolations(
        ['README.md', 'a.sh'],
        ['a.sh' => ['purpose' => 'x', 'timing' => 'y']],
        ['gone.sh' => '既に消したスクリプト', 'a.sh' => '   '],
    );

    expect($violations)->toContain('S4: exemption `gone.sh` が実在しない (死んだ除外の残置)');
    expect($violations)->toContain('S4: exemption `a.sh` の理由が空 (理由なし除外は認めない)');
});
