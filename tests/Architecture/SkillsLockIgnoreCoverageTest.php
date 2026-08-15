<?php

declare(strict_types=1);

use Webmozart\Assert\Assert;

/*
 * 版固定ファイル `skills-lock.json` に登録された外部 skill が、**全キー** git の除外規則で
 * 閉じられていることを deny-by-default で固定する。
 *
 * なぜ必要か: 本リポジトリは外部 skill の実体を追跡せず都度取得する側である
 * (AGENTS.md §設計・TODO・devnotes の運用)。`.gitignore` は
 * `/.claude/skills/stripe-*` の glob を持つが、登録キー `upgrade-stripe` は名前が
 * `stripe-` で始まらないためこの glob に入らない。都度取得でスキルを復元した瞬間に
 * 追跡候補が 1 本開く (誤ってコミットする入口になる)。
 * 個別行を足すだけでは同じ抜けが再発するので、「登録キーの全数が閉じている」ことを
 * 機械で固定する。
 *
 * 走らせるもの: `git check-ignore --no-index -z --stdin` を 1 回だけ起動し、
 * **リポジトリルート相対の `.claude/skills/{キー}` だけ**を NUL 区切りで流し込む
 * (絶対パスや `.gitignore` の行そのものは渡さない)。
 * `--no-index` は必須である — 付けないと「追跡されているから報告されない」という
 * **追跡状態**の影響を受けるが、本テストが見たいのは**除外規則そのもの**である。
 * `.gitignore` の glob 評価を PHP 側で再実装しない (git の挙動とズレたら検査の意味が消える)。
 *
 * 保証範囲を誇張しない: 見るのは**版固定ファイルに登録されたキーだけ**である。
 * `/.agents` や外部コマンドが生成する状態ファイルの除外は本テストの対象外で、
 * 「外部由来のものが 1 つも追跡されない」とは読めない。
 *
 * 本テストは DB を触らない (ファイルと git の読み取りのみ)。
 */

/**
 * 登録キーのうち git の除外規則に一致しなかったものを違反として列挙する (純関数)。
 *
 * 実ファイルも git も読まないので、正・負のコントロールを fixture で書ける。
 *
 * @param  list<string>  $keys  版固定ファイルの登録キー
 * @param  list<string>  $ignoredPaths  git が「除外される」と答えたリポジトリ相対パス
 * @return list<string> 違反一覧 (空 = 合格)
 */
function skillsLockIgnoreViolations(array $keys, array $ignoredPaths): array
{
    // 空振り防止: 登録キーが 1 件も無いと検査が素通りする (常に緑になる) ため違反にする。
    if ($keys === []) {
        return ['L0: skills-lock.json の登録キーが 0 件 (検査が空振りしている)'];
    }

    $violations = [];

    foreach ($keys as $key) {
        $path = '.claude/skills/'.$key;
        if (! in_array($path, $ignoredPaths, true)) {
            $violations[] = "L1: {$path} が git の除外規則に一致しない"
                .' (.gitignore に理由コメントつきの行を足すこと)';
        }
    }

    return $violations;
}

/**
 * `skills-lock.json` の `skills` 直下のキーを昇順で返す。
 *
 * @return list<string>
 */
function skillsLockKeys(string $lockFilePath): array
{
    $json = file_get_contents($lockFilePath);
    Assert::string($json, "skills-lock.json を読めない: {$lockFilePath}");

    $decoded = json_decode($json, true);
    Assert::isArray($decoded, 'skills-lock.json が JSON オブジェクトでない');
    Assert::keyExists($decoded, 'skills', 'skills-lock.json に skills が無い');
    Assert::isArray($decoded['skills'], 'skills-lock.json の skills がオブジェクトでない');

    $keys = array_keys($decoded['skills']);
    Assert::allString($keys, 'skills-lock.json の登録キーが文字列でない');
    sort($keys);

    return array_values($keys);
}

/**
 * 渡したパスのうち git が「除外される」と答えたものを返す。
 *
 * 失敗したら **skip せず fail** させる (偽グリーン禁止)。`git check-ignore` の終了コードは
 * 0 = 一致あり / 1 = 一致なし / それ以外 = エラーで、0 と 1 の両方を正常応答として扱う。
 * NUL 区切りを壊さないため shell を介さず proc_open で引数配列のまま起動する。
 *
 * @param  list<string>  $paths  リポジトリルート相対パス
 * @return list<string>
 */
function gitIgnoredPaths(string $repositoryRoot, array $paths): array
{
    if ($paths === []) {
        return [];
    }

    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open(
        ['git', 'check-ignore', '--no-index', '-z', '--stdin'],
        $descriptors,
        $pipes,
        $repositoryRoot,
    );
    Assert::true(is_resource($process), 'git check-ignore を起動できなかった (テスト環境に git が無い?)');

    // 手順を固定する: stdin へ書く → stdin を閉じる → stdout / stderr を読む → proc_close。
    // 閉じる前に読むと相手が入力待ちのまま止まる。
    fwrite($pipes[0], implode("\0", $paths)."\0");
    fclose($pipes[0]);

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    Assert::inArray(
        $exitCode,
        [0, 1],
        'git check-ignore が失敗した (exit '.$exitCode.'): '.(is_string($stderr) ? $stderr : ''),
    );
    Assert::string($stdout, 'git check-ignore の出力を取得できなかった');

    return array_values(array_filter(
        explode("\0", $stdout),
        static fn (string $path): bool => $path !== '',
    ));
}

// ── L1: 実測 (版固定ファイルの全キーが git の除外規則で閉じている) ──

test('skills-lock.json の全登録キーが git の除外規則で閉じられていること', function (): void {
    $keys = skillsLockKeys(base_path('skills-lock.json'));

    $violations = skillsLockIgnoreViolations(
        $keys,
        gitIgnoredPaths(base_path(), array_map(
            static fn (string $key): string => '.claude/skills/'.$key,
            $keys,
        )),
    );

    expect($violations)->toBe([], "skills-lock.json の登録キーに除外漏れがあります:\n".implode("\n", $violations));
});

// ── L2: 外部コマンドが本当に効いていること (空振り検出) ──

test('git check-ignore の呼び出しが除外される path と されない path を区別すること', function (): void {
    // 負のコントロール込みの前提検査。すべて空を返す / すべて返す実装になっていたら落ちる。
    $ignored = gitIgnoredPaths(base_path(), ['vendor', 'AGENTS.md']);

    expect($ignored)->toContain('vendor')
        ->and($ignored)->not->toContain('AGENTS.md');
});

// ── L3: 正のコントロール (検出器が本当に検出できること) ──

test('L1 正のコントロール: 除外されていない登録キーを検出すること', function (): void {
    $violations = skillsLockIgnoreViolations(
        ['stripe-projects', 'upgrade-stripe'],
        ['.claude/skills/stripe-projects'],
    );

    expect($violations)->toBe([
        'L1: .claude/skills/upgrade-stripe が git の除外規則に一致しない'
            .' (.gitignore に理由コメントつきの行を足すこと)',
    ]);
});

test('L0 正のコントロール: 登録キーが 0 件なら空振りとして検出すること', function (): void {
    expect(skillsLockIgnoreViolations([], []))
        ->toBe(['L0: skills-lock.json の登録キーが 0 件 (検査が空振りしている)']);
});

// ── L4: 負のコントロール ──

test('L1 負のコントロール: 全キーが除外されていれば違反が無いこと', function (): void {
    expect(skillsLockIgnoreViolations(
        ['stripe-best-practices', 'stripe-projects', 'upgrade-stripe'],
        [
            '.claude/skills/stripe-best-practices',
            '.claude/skills/stripe-projects',
            '.claude/skills/upgrade-stripe',
            '.claude/skills/unrelated',
        ],
    ))->toBe([]);
});
