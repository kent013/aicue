<?php

declare(strict_types=1);

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/*
 * setup-worktree.sh の実行時ファイル供給契約。
 *
 * worktree へ供給する秘密ファイル 4 本 (.env / storage/oauth-private.key /
 * storage/oauth-public.key / .env.bughunt.local) は provision_secret_file 1 本を通り、
 * **作成時点で mode を 0600 に確定**する。
 *
 * 単純な cp は新規作成時に供給元の mode を引き継ぐため、親の .env が 0644 だと worktree を
 * 作るたびに world-readable な秘密ファイルが 1 つ増える。さらに cp は供給先が既に存在すると
 * その mode を変えないので、一度広く置かれたら締まらない。`install -m 600` は作成時点で
 * mode を確定するので、cp → chmod の 2 段にある「一瞬だけ広く読める窓」も作らない。
 *
 * setup-worktree.sh は top-level 実行型 (main() を持たない) なので、素朴に source すると
 * composer install / pnpm install / DB 作成まで走る。SETUP_WORKTREE_SOURCE_ONLY で
 * 関数定義だけ取り込んで抜ける guard を使う。
 *
 * ★ 保証範囲を誇張しない: 静的ケース (S-1〜S-5) は**既知の回帰形の検出**であって、
 *   bash のあらゆる条件コンテキスト・あらゆる書き方を排除する証明ではない
 *   (複数行にまたがる条件式や、変数経由の間接呼び出しには沈黙する)。
 *   素の呼び出し行の完全一致 (S-2) と、実挙動を見る動的ケース (D-1〜D-13) を
 *   組み合わせて実用上の検出力を確保している。
 */

/**
 * setup-worktree.sh を source して provision_secret_file だけを叩く。
 * 引数は位置引数で渡す (文字列連結による shell injection を避ける)。
 * stdout には、その呼び出しの後に PROVISIONED_PATHS に入っている相対パスが 1 行ずつ出る。
 */
function runProvisionSecretFile(
    string $requirement,
    string $parent,
    string $worktree,
    string $relative,
    string $hint = '',
): ProcessResult {
    return Process::timeout(60)
        ->env(['SETUP_WORKTREE_SOURCE_ONLY' => '1'])
        ->run([
            'bash', '-c',
            'source "$1"; provision_secret_file "$2" "$3" "$4" "$5" "$6"; '
                .'printf "%s\n" "${PROVISIONED_PATHS[@]+"${PROVISIONED_PATHS[@]}"}"',
            '_',
            base_path('scripts/setup-worktree.sh'),
            $requirement, $parent, $worktree, $relative, $hint,
        ]);
}

/**
 * stdout に出た PROVISIONED_PATHS の中身を配列で返す (空行は落とす)。
 *
 * @return list<string>
 */
function provisionedPathsOf(ProcessResult $result): array
{
    return array_values(array_filter(
        array_map(trim(...), explode("\n", $result->output())),
        static fn (string $line): bool => $line !== '',
    ));
}

/**
 * 親 / worktree の一時ディレクトリを作る。
 *
 * worktree 側には storage/ も作る (供給関数は親ディレクトリを作らない契約なので、
 * 実 worktree と同じ前提を fixture 側で用意する)。
 *
 * @return array{0: string, 1: string} [親, worktree]
 */
function makeWorktreeFixture(): array
{
    $base = sys_get_temp_dir().'/setup-worktree-contract-'.bin2hex(random_bytes(6));
    File::makeDirectory($base.'/parent/storage', 0700, true);
    File::makeDirectory($base.'/worktree/storage', 0700, true);

    return [$base.'/parent', $base.'/worktree'];
}

/** setup-worktree.sh の本文。 */
function setupWorktreeSource(): string
{
    return File::get(base_path('scripts/setup-worktree.sh'));
}

/** setup-worktree.sh から provision_secret_file() の本体だけを切り出す。 */
function provisionSecretFileBody(string $source): string
{
    // 関数定義は列 0 で始まり、閉じ括弧も列 0 に来る (このファイルの既存の書き方)。
    expect($source)->toMatch('/^provision_secret_file\(\) \{$/m', '関数定義の形が変わっている');
    preg_match('/^provision_secret_file\(\) \{$(.*?)^\}$/ms', $source, $m);

    // 切り出しに失敗したら空文字が返り、呼び出し側の検査が落ちる (fail-closed)。
    return $m[1] ?? '';
}

// --- 動的ケース (source 専用入口から実走) ---

test('D-1: 供給元があれば内容が供給先へ入る (.env)', function (): void {
    [$parent, $worktree] = makeWorktreeFixture();

    try {
        File::put($parent.'/.env', "APP_ENV=local\n");

        $result = runProvisionSecretFile('required', $parent, $worktree, '.env');

        expect($result->exitCode() ?? 1)->toBe(0);
        expect(File::get($worktree.'/.env'))->toBe("APP_ENV=local\n");
    } finally {
        File::deleteDirectory(dirname($parent));
    }
});

test('D-2: 親が 0644 でも供給先は 0600 (.env)', function (): void {
    [$parent, $worktree] = makeWorktreeFixture();

    try {
        File::put($parent.'/.env', "APP_ENV=local\n");
        chmod($parent.'/.env', 0644);

        expect(runProvisionSecretFile('required', $parent, $worktree, '.env')->exitCode() ?? 1)->toBe(0);
        expect(decoct(fileperms($worktree.'/.env') & 0777))
            ->toBe('600', '供給先が world-readable になっている (cp / cp+chmod への退行)');
    } finally {
        File::deleteDirectory(dirname($parent));
    }
});

test('D-3: 親が 0644 でも供給先は 0600 (storage/oauth-private.key)', function (): void {
    [$parent, $worktree] = makeWorktreeFixture();

    try {
        File::put($parent.'/storage/oauth-private.key', "PRIVATE\n");
        chmod($parent.'/storage/oauth-private.key', 0644);

        $result = runProvisionSecretFile('optional', $parent, $worktree, 'storage/oauth-private.key');

        expect($result->exitCode() ?? 1)->toBe(0);
        expect(decoct(fileperms($worktree.'/storage/oauth-private.key') & 0777))
            ->toBe('600', 'Passport 署名鍵が world-readable で置かれている');
    } finally {
        File::deleteDirectory(dirname($parent));
    }
});

test('D-4: 親が 0644 でも供給先は 0600 (storage/oauth-public.key)', function (): void {
    // 公開鍵も同じ契約に入れるのは**意図的な選択**である。worktree へ供給する実行時ファイルは
    // 配布物ではなく作業者本人の PHP プロセスだけが読むので、1 本だけ例外にすると
    // 「どれを狭く置くか」の判断がスクリプトに 2 種類生まれる。
    [$parent, $worktree] = makeWorktreeFixture();

    try {
        File::put($parent.'/storage/oauth-public.key', "PUBLIC\n");
        chmod($parent.'/storage/oauth-public.key', 0644);

        $result = runProvisionSecretFile('optional', $parent, $worktree, 'storage/oauth-public.key');

        expect($result->exitCode() ?? 1)->toBe(0);
        expect(decoct(fileperms($worktree.'/storage/oauth-public.key') & 0777))->toBe('600');
    } finally {
        File::deleteDirectory(dirname($parent));
    }
});

test('D-5: 親が 0644 でも供給先は 0600 (.env.bughunt.local)', function (): void {
    [$parent, $worktree] = makeWorktreeFixture();

    try {
        File::put($parent.'/.env.bughunt.local', "APP_ENV=bughunt.local\n");
        chmod($parent.'/.env.bughunt.local', 0644);

        $result = runProvisionSecretFile('optional', $parent, $worktree, '.env.bughunt.local');

        expect($result->exitCode() ?? 1)->toBe(0);
        expect(File::get($worktree.'/.env.bughunt.local'))->toBe("APP_ENV=bughunt.local\n");
        expect(decoct(fileperms($worktree.'/.env.bughunt.local') & 0777))->toBe('600');
    } finally {
        File::deleteDirectory(dirname($parent));
    }
});

test('D-6: 供給先が既に 0666 で存在しても上書き後は内容が新しく mode は 0600', function (): void {
    [$parent, $worktree] = makeWorktreeFixture();

    try {
        File::put($parent.'/.env', "APP_ENV=new\n");
        chmod($parent.'/.env', 0644);
        File::put($worktree.'/.env', "APP_ENV=old\n");
        chmod($worktree.'/.env', 0666);

        expect(runProvisionSecretFile('required', $parent, $worktree, '.env')->exitCode() ?? 1)->toBe(0);

        expect(File::get($worktree.'/.env'))->toBe("APP_ENV=new\n");
        expect(decoct(fileperms($worktree.'/.env') & 0777))
            ->toBe('600', 'cp は供給先が既に存在するとその mode を変えない (退行の入り口)');
    } finally {
        File::deleteDirectory(dirname($parent));
    }
});

test('D-7: optional で供給元が無ければ終了コード 0 で供給先を作らない', function (): void {
    [$parent, $worktree] = makeWorktreeFixture();

    try {
        $result = runProvisionSecretFile('optional', $parent, $worktree, '.env.bughunt.local');

        expect($result->exitCode() ?? 1)->toBe(0);
        expect(File::exists($worktree.'/.env.bughunt.local'))->toBeFalse('空ファイルを作っている');
    } finally {
        File::deleteDirectory(dirname($parent));
    }
});

test('D-8: required で供給元が無ければ非ゼロで止まり供給先を作らない', function (): void {
    // 見本ファイルによる代替が復活していないこと (壊れた worktree を無言で作らない) も兼ねる。
    [$parent, $worktree] = makeWorktreeFixture();

    try {
        File::put($parent.'/.env.example', "APP_ENV=example\n");

        $result = runProvisionSecretFile('required', $parent, $worktree, '.env');

        expect($result->exitCode() ?? 0)->not->toBe(0, '必須の供給元が無いのに成功している');
        expect(File::exists($worktree.'/.env'))->toBeFalse();
    } finally {
        File::deleteDirectory(dirname($parent));
    }
});

test('D-9: 要否指定が required / optional 以外なら非ゼロ (fail-closed)', function (): void {
    [$parent, $worktree] = makeWorktreeFixture();

    try {
        File::put($parent.'/.env', "APP_ENV=local\n");

        $result = runProvisionSecretFile('maybe', $parent, $worktree, '.env');

        expect($result->exitCode() ?? 0)->not->toBe(0, '未知の要否指定が黙って通っている');
        expect(File::exists($worktree.'/.env'))->toBeFalse();
    } finally {
        File::deleteDirectory(dirname($parent));
    }
});

test('D-10: 供給先の親ディレクトリが無ければ非ゼロで、ディレクトリを作らない', function (): void {
    // install -D への退行検出。供給先パスを間違えたときに worktree の外へ
    // 静かにディレクトリを作る経路を持たせない。
    [$parent, $worktree] = makeWorktreeFixture();

    try {
        File::put($parent.'/storage/oauth-private.key', "PRIVATE\n");
        File::deleteDirectory($worktree.'/storage');

        $result = runProvisionSecretFile('optional', $parent, $worktree, 'storage/oauth-private.key');

        expect($result->exitCode() ?? 0)->not->toBe(0, '供給先の親ディレクトリが無いのに成功している');
        expect(File::isDirectory($worktree.'/storage'))->toBeFalse('親ディレクトリを作っている (install -D への退行)');
    } finally {
        File::deleteDirectory(dirname($parent));
    }
});

test('D-11: 供給先ディレクトリが書き込み不可なら非ゼロ (失敗を握り潰さない)', function (): void {
    [$parent, $worktree] = makeWorktreeFixture();

    try {
        File::put($parent.'/.env', "APP_ENV=local\n");
        chmod($worktree, 0500);   // 書き込み不可

        $result = runProvisionSecretFile('required', $parent, $worktree, '.env');

        expect($result->exitCode() ?? 0)->not->toBe(0, '供給失敗が成功扱いになっている');
        expect(File::exists($worktree.'/.env'))->toBeFalse();
    } finally {
        chmod($worktree, 0700);
        File::deleteDirectory(dirname($parent));
    }
})->skip(
    posix_geteuid() === 0,
    'root では書き込み不可ディレクトリでも install が成功するため検証できない',
);

test('D-12: PROVISIONED_PATHS には供給したものだけが記録される', function (): void {
    // health check が「存在しないパス」を検査して偽赤になるのを防ぐ。
    [$parent, $worktree] = makeWorktreeFixture();

    try {
        File::put($parent.'/.env', "APP_ENV=local\n");

        $supplied = runProvisionSecretFile('required', $parent, $worktree, '.env');
        expect($supplied->exitCode() ?? 1)->toBe(0);
        expect(provisionedPathsOf($supplied))->toBe(['.env']);

        $skipped = runProvisionSecretFile('optional', $parent, $worktree, '.env.bughunt.local');
        expect($skipped->exitCode() ?? 1)->toBe(0);
        expect(provisionedPathsOf($skipped))->toBe([], '供給していないパスが記録されている');

        // optional でも供給できたときは記録される (記録が required 専用になっていない)。
        File::put($parent.'/.env.bughunt.local', "APP_ENV=bughunt.local\n");
        $optional = runProvisionSecretFile('optional', $parent, $worktree, '.env.bughunt.local');
        expect($optional->exitCode() ?? 1)->toBe(0);
        expect(provisionedPathsOf($optional))->toBe(['.env.bughunt.local'], '供給したパスが記録されていない');
    } finally {
        File::deleteDirectory(dirname($parent));
    }
});

test('D-13: 供給先が symlink なら非ゼロで落ち、リンク先の内容が変わらない', function (): void {
    // install は symlink を辿ってリンク先へ書き込むため、辿った先が worktree の外でも
    // 0600 の秘密ファイルを置いてしまう。
    [$parent, $worktree] = makeWorktreeFixture();

    try {
        File::put($parent.'/.env', "APP_ENV=secret\n");
        File::put($parent.'/outside.txt', "OUTSIDE\n");
        symlink($parent.'/outside.txt', $worktree.'/.env');

        $result = runProvisionSecretFile('required', $parent, $worktree, '.env');

        expect($result->exitCode() ?? 0)->not->toBe(0, 'symlink を辿って書き込んでいる');
        expect(File::get($parent.'/outside.txt'))->toBe("OUTSIDE\n", 'リンク先へ秘密が書き込まれている');
    } finally {
        File::deleteDirectory(dirname($parent));
    }
});

// --- 静的ケース (scripts/setup-worktree.sh の本文を読む) ---

test('S-1: provision_secret_file が条件式の位置で呼ばれていない', function (): void {
    // 条件の中では set -e が効かず、install の失敗が「無いためスキップ」に化けて
    // 秘密ファイルの供給失敗を隠す。
    //
    // ★ 先頭に \b を置くと `&& provision_...` / `|| provision_...` を捕まえられない。
    //   \b は「単語文字と非単語文字の境界」なので、直前が空白・直後が & のときは境界が成立せず
    //   その選択肢へ到達しない。
    expect(setupWorktreeSource())->not->toMatch(
        '/(?:\b(?:if|while|until)\s+(?:!\s*)?|(?:&&|\|\|)\s*(?:!\s*)?)provision_secret_file\b/',
        'provision_secret_file が条件式の位置で呼ばれている (set -e が効かず失敗を握り潰す)',
    );
});

test('S-2: 秘密ファイル 4 本が素の呼び出し行の完全一致で主経路に存在する', function (): void {
    // 供給対象が黙って減る・要否が黙って変わるのを検出する。
    $source = setupWorktreeSource();

    $calls = [
        'provision_secret_file required "${REPO_ROOT}" "${WORKTREE_DIR}" ".env" "${ENV_SETUP_HINT}"',
        'provision_secret_file optional "${REPO_ROOT}" "${WORKTREE_DIR}" "storage/oauth-private.key" "必要なら worktree 内で \'php artisan passport:keys\'"',
        'provision_secret_file optional "${REPO_ROOT}" "${WORKTREE_DIR}" "storage/oauth-public.key" "必要なら worktree 内で \'php artisan passport:keys\'"',
        'provision_secret_file optional "${REPO_ROOT}" "${WORKTREE_DIR}" ".env.bughunt.local" "bug-hunt 未使用なら不要"',
    ];

    foreach ($calls as $call) {
        expect($source)->toMatch(
            '/^'.preg_quote($call, '/').'$/mu',
            "主経路の供給行が見つからない: {$call}",
        );
    }
});

test('S-3: provision_secret_file の本体が install -m 600 を素の文として実行している', function (): void {
    // 部分一致では `install ... || true` のような退行が通ってしまうので、
    // 行頭・行末をアンカーした完全一致で照合する。
    expect(provisionSecretFileBody(setupWorktreeSource()))->toMatch(
        '/^ {4}install -m 600 -- "\$\{src\}" "\$\{dst\}"$/m',
        'provision_secret_file が install -m 600 を素の文として実行していない (失敗が握り潰される形になっている)',
    );
});

test('S-4: 秘密ファイル 4 本を cp で供給する行が 0 件', function (): void {
    // ★ 保証範囲: cp の直接の退行形だけを見る (変数に入れたコマンド名、別コマンド、
    //   別表記での供給には沈黙する)。
    expect(setupWorktreeSource())->not->toMatch(
        '/^\s*cp\s+.*"\$\{REPO_ROOT\}\/(?:\.env|storage\/oauth-[a-z]+\.key|\.env\.bughunt\.local)"/m',
        '秘密ファイルが cp で供給されている (関数経由への統一が崩れている)',
    );
});

test('S-5: .env.example を .env として置く代替が復活していない', function (): void {
    // .env.example から作った .env は APP_KEY も DB 接続も入っておらず、health check は
    // 通ってしまうため、動くように見えて壊れている worktree が無言で出来上がる。
    $source = setupWorktreeSource();

    expect($source)->not->toMatch(
        '/^\s*(?:cp|install|mv|ln)\s+.*\.env\.example/m',
        '.env.example を worktree へ置く代替が復活している',
    );
    expect($source)->not->toMatch(
        '/provision_secret_file\s+\S+\s+.*\.env\.example/',
        '.env.example を供給関数経由で置いている',
    );
});
