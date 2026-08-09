<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/*
 * setup-worktree.sh の実行時ファイル provisioning 契約。
 *
 * bug-hunt は worktree 走行が既定 (AGENTS.md) だが、.env.bughunt.local は .gitignore 対象で
 * worktree には決して現れない。親からのコピーが唯一の供給路であり、無いと provision が必ず止まる
 * (bug-hunt run 20260809-152048 で実際に踏み、手動 cp で回避した)。
 *
 * 秘密ファイルの複製なので **mode は 0600 に固定**する。親が 0644 のとき `cp -p` は
 * world-readable な秘密ファイルを新たに作るため契約として弱く、`cp` → `chmod` の 2 段にも
 * 「一瞬だけ広く読める窓」がある。`install -m 600` は作成時点で mode を確定する。
 *
 * setup-worktree.sh は top-level 実行型 (main() を持たない) なので、素朴に source すると
 * composer install / pnpm install / DB 作成まで走る。SETUP_WORKTREE_SOURCE_ONLY で
 * 関数定義だけ取り込んで抜ける guard を使う。
 */

/**
 * setup-worktree.sh を source して provision_bughunt_env_file だけを叩く。
 * 引数は位置引数で渡す (文字列連結による shell injection を避ける)。
 */
function runProvisionBughuntEnvFile(string $parent, string $worktree): int
{
    $result = Process::timeout(60)
        ->env(['SETUP_WORKTREE_SOURCE_ONLY' => '1'])
        ->run([
            'bash', '-c',
            'source "$1"; provision_bughunt_env_file "$2" "$3"',
            '_',
            base_path('scripts/setup-worktree.sh'),
            $parent,
            $worktree,
        ]);

    return $result->exitCode() ?? 1;
}

/** @return array{0: string, 1: string} [親, worktree] の一時ディレクトリ */
function makeWorktreeFixture(): array
{
    $base = sys_get_temp_dir().'/setup-worktree-contract-'.bin2hex(random_bytes(6));
    File::makeDirectory($base.'/parent', 0700, true);
    File::makeDirectory($base.'/worktree', 0700, true);

    return [$base.'/parent', $base.'/worktree'];
}

test('親に .env.bughunt.local があれば worktree へコピーされる', function (): void {
    [$parent, $worktree] = makeWorktreeFixture();

    try {
        File::put($parent.'/.env.bughunt.local', "APP_ENV=bughunt.local\n");

        expect(runProvisionBughuntEnvFile($parent, $worktree))->toBe(0);
        expect(File::exists($worktree.'/.env.bughunt.local'))->toBeTrue();
        expect(File::get($worktree.'/.env.bughunt.local'))->toBe("APP_ENV=bughunt.local\n");
    } finally {
        File::deleteDirectory(dirname($parent));
    }
});

test('親に .env.bughunt.local が無ければ何もしない (bug-hunt 非利用リポジトリで no-op)', function (): void {
    [$parent, $worktree] = makeWorktreeFixture();

    try {
        expect(runProvisionBughuntEnvFile($parent, $worktree))->toBe(0);
        expect(File::exists($worktree.'/.env.bughunt.local'))->toBeFalse();
    } finally {
        File::deleteDirectory(dirname($parent));
    }
});

test('親が 0644 でもコピー先は 0600 になる', function (): void {
    [$parent, $worktree] = makeWorktreeFixture();

    try {
        File::put($parent.'/.env.bughunt.local', "APP_ENV=bughunt.local\n");
        chmod($parent.'/.env.bughunt.local', 0644);

        expect(runProvisionBughuntEnvFile($parent, $worktree))->toBe(0);

        $mode = fileperms($worktree.'/.env.bughunt.local') & 0777;
        expect(decoct($mode))->toBe('600', 'コピー先が world-readable になっている (cp -p / cp+chmod への退行)');
    } finally {
        File::deleteDirectory(dirname($parent));
    }
});

test('コピー先が既に存在しても上書き後に 0600 になる', function (): void {
    [$parent, $worktree] = makeWorktreeFixture();

    try {
        File::put($parent.'/.env.bughunt.local', "APP_ENV=new\n");
        chmod($parent.'/.env.bughunt.local', 0644);
        File::put($worktree.'/.env.bughunt.local', "APP_ENV=old\n");
        chmod($worktree.'/.env.bughunt.local', 0666);

        expect(runProvisionBughuntEnvFile($parent, $worktree))->toBe(0);

        expect(File::get($worktree.'/.env.bughunt.local'))->toBe("APP_ENV=new\n");
        expect(decoct(fileperms($worktree.'/.env.bughunt.local') & 0777))->toBe('600');
    } finally {
        File::deleteDirectory(dirname($parent));
    }
});

test('コピーに失敗したら非ゼロで落ちる (失敗を握り潰さない)', function (): void {
    // 秘密ファイルのコピー失敗を「親に無いためスキップ」に化けさせないこと。
    // コピー先ディレクトリを書き込み不可にして install を失敗させる。
    [$parent, $worktree] = makeWorktreeFixture();

    try {
        File::put($parent.'/.env.bughunt.local', "APP_ENV=bughunt.local\n");
        chmod($worktree, 0500);   // 書き込み不可

        expect(runProvisionBughuntEnvFile($parent, $worktree))
            ->not->toBe(0, 'コピー失敗が成功扱いになっている');
        expect(File::exists($worktree.'/.env.bughunt.local'))->toBeFalse();
    } finally {
        chmod($worktree, 0700);
        File::deleteDirectory(dirname($parent));
    }
})->skip(
    posix_geteuid() === 0,
    'root では書き込み不可ディレクトリでも install が成功するため検証できない',
);

test('本体の呼び出しが if の条件式に置かれていないこと (set -e で失敗が伝播する)', function (): void {
    // 関数単体テストでは top-level の set -e 配線までは固定できない。
    // `if provision_bughunt_env_file ...` の形へ戻ると、install の失敗が
    // 条件評価に吸われて「無いためスキップ」に化ける (Round 1 の指摘)。
    //
    // ★ 保証範囲: これは**既知の回帰形の検出**であって、
    //   「あらゆる bash 条件コンテキストに置かれていないことの証明」ではない
    //   (複数行にまたがる複雑な条件式までは正規表現で追えない)。
    //   素の呼び出し行の完全一致と組み合わせて、実用上の検出力を確保している。
    $source = File::get(base_path('scripts/setup-worktree.sh'));

    expect($source)->not->toMatch(
        '/\b(if|while|until|&&|\|\|)\s+provision_bughunt_env_file/',
        'provision_bughunt_env_file が条件式の位置で呼ばれている (set -e が効かず失敗を握り潰す)',
    );
    // 本体からは素の文として呼ばれていること
    expect($source)->toMatch('/^\s{4}provision_bughunt_env_file "\$\{REPO_ROOT\}" "\$\{WORKTREE_DIR\}"$/m');
});

test('install -m 600 を使っていること (cp + chmod の 2 段へ退行していない)', function (): void {
    // 2 段だと cp 直後から chmod までの間だけ world-readable な秘密ファイルが存在する。
    $source = File::get(base_path('scripts/setup-worktree.sh'));

    expect($source)->toContain('install -m 600 "${repo_root}/.env.bughunt.local"');
});
