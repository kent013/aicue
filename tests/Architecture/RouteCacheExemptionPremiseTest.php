<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

/*
 * 逸脱 D19 (経路キャッシュ起動での後付けは「走らせない」側を維持する) を
 * 許している**前提そのもの**を機械で固定する。
 *
 * ★これは「デプロイの正しさ」を事前検査する仕組みではない (AGENTS.md が禁じているのはそちら)。
 *   固定するのは、D19 を許している前提に対して**いま定義できるトリップワイヤ**である。
 *   同じ形は tests/Feature/Security/ThrottleExemptionPremiseTest.php /
 *   tests/Feature/Security/IdempotencyExemptionPremiseTest.php に前例がある。
 *
 * ★赤くなったときに求めるのは「デプロイを正しくすること」ではなく、
 *   「D19 を読み直して、専用の実行点クラスへの移行か、毎デプロイ再生成の機械強制かを
 *   同じ PR で決めること」である。
 *
 * ★2 つの検査は対等ではない。前提を本当に決めるのは検査 B (`route:cache` が実行されないこと) で、
 *   検査 A (デプロイ定義が無いこと) は**早期の気づき**のための粗い網である。
 *   デプロイ定義があっても `route:cache` を打たなければ前提は崩れず、逆に定義が無くても
 *   `route:cache` を打てば崩れる。したがって A は網羅を主張しない。
 *
 * ★保証範囲を誇張しない:
 *   - PHP は**コメントと docblock を落とした後のコードと文字列リテラル**を走査する。
 *   - 見ないもの: Markdown の説明文 / 動的に連結した文字列 / リポジトリの外から与えられる実行手順。
 *   - 検出するのは「`artisan` と `optimize` の間が空白だけ」の書き方までである。
 *     間にオプションが挟まる形 (`artisan --env=production optimize`) は**拾わない**。
 *     シェルの文法を正規表現で解析し始めると際限がないため、ここで線を引いている。
 *   - 起動時の cache の鮮度も、デプロイ手順の正しさも検査しない。
 *   - 検査 A は新しい CI 基盤やファイル名を網羅できない (`.github/workflows/*.yml` の中身も見ない)。
 *   - **丸ごと走査から外しているのは本テスト自身 1 件だけ**である (検出したい語を負のコントロールの
 *     入力として持つため)。したがって**本ファイルの中の実行記述には沈黙する**。
 *     説明として needle を持つ他のファイルは、丸ごと外さずに**件数を完全一致で pin** して扱う。
 *
 * DB 不使用の静的検査 (既存 Architecture テストと同じ作法)。
 */

/**
 * 本逸脱の登録番号。`docs/template-divergence.md` / `AGENTS.md` /
 * `docs/app-integration-guide.md` と本テストの結線はこの 1 か所を通す
 * (番号を 2 か所に書かない)。
 */
const ROUTE_CACHE_DIVERGENCE_ID = 'D19';

/**
 * 検査 B の走査から**丸ごと**外す唯一のファイル (repo 相対)。
 *
 * 本テスト自身だけである。検出したい語を負のコントロールの入力として持つため、
 * 自分を走査すると必ず自分で赤くなる。
 *
 * ★**保証の穴として明記する**: 本ファイルの中に `route:cache` の実行記述を書いても
 *   検査 B は沈黙する。丸ごとの除外はこの 1 件に限り、他のファイルは
 *   {@see ROUTE_CACHE_PREMISE_KNOWN_MENTIONS} の**件数 pin** で扱う。
 */
const ROUTE_CACHE_PREMISE_SELF_PATH = 'tests/Architecture/RouteCacheExemptionPremiseTest.php';

/**
 * 説明文として needle を持つことが確認済みのファイルと、その**件数**。
 *
 * 件数は**完全一致**で、増えても減っても赤になる (`ForbiddenStatementExemption` と同じ作法)。
 * ファイル単位の除外にしないのは、除外したファイルの中に将来の実行記述が紛れ込んでも
 * 沈黙してしまうためである (deny-by-default の粒度を落とさない)。
 *
 * - `RouteThrottleBinderTest`: テスト名の文字列に「route:cache 下の再適用が冪等」という
 *   **説明**が入っている。実行ではないが、コメントを落としても文字列リテラルとして残る。
 *
 * @var array<string, int>
 */
const ROUTE_CACHE_PREMISE_KNOWN_MENTIONS = [
    'tests/Feature/Security/RouteThrottleBinderTest.php' => 1,
];

/**
 * 走査の母集団が空振りでないことを確かめる代表パス。
 *
 * @var list<string>
 */
const ROUTE_CACHE_PREMISE_SENTINEL_PATHS = [
    'composer.json',
    '.github/workflows/ci.yml',
    'scripts/bug-hunt-shard.sh',
];

/** 走査の母集団の下限 (これを下回ったら列挙そのものを疑う)。 */
const ROUTE_CACHE_PREMISE_MINIMUM_TRACKED_FILES = 500;

/**
 * git 追跡下の全ファイル (repo 相対パス、昇順)。
 *
 * ★`Tests\Support\TrackedPhpSourceFiles` は `*.php` 専用なので使えない。対象が
 *   拡張子を問わないため、共用クラスを新設せず本テスト内に閉じる (今必要なものだけ作る)。
 * ★git が使えない環境では**空を返さず例外**にする (fail-open の防止)。
 *
 * @return list<string>
 */
function routeCachePremiseTrackedFiles(): array
{
    $process = new Process(['git', 'ls-files', '-z'], base_path());
    $process->run();

    if (! $process->isSuccessful()) {
        throw new RuntimeException(
            'git ls-files の実行に失敗しました (git worktree 前提の architecture invariant): '
            .$process->getErrorOutput()
        );
    }

    $files = [];
    foreach (explode("\0", $process->getOutput()) as $relative) {
        if ($relative === '') {
            continue;
        }

        $files[] = $relative;
    }

    sort($files);

    return $files;
}

/**
 * PHP ソースからコメントと docblock を落とす (行番号を保つ)。
 *
 * 落とした部分は**同じ改行数**へ置き換える。単にトークンを連ねると、違反の報告で
 * 出すファイル名と行番号が実際の位置とずれる。
 */
function routeCachePremiseStripPhpComments(string $source): string
{
    $stripped = '';

    foreach (token_get_all($source) as $token) {
        if (! is_array($token)) {
            $stripped .= $token;

            continue;
        }

        if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
            $stripped .= str_repeat("\n", substr_count($token[1], "\n"));

            continue;
        }

        $stripped .= $token[1];
    }

    return $stripped;
}

/**
 * 検査 B の判定 (純関数)。`route:cache` の実行と、空白だけを挟む `artisan optimize` を探す。
 *
 * 素の `optimize` は `route:cache` を含む複合コマンドなので対象に入れる。`optimize:clear`
 * (消す側。bug-hunt が使う) は直後が `:` なので一致しない。
 *
 * @return list<array{line: int, needle: string}> 1 起点の行番号と一致した語
 */
function routeCachePremiseViolations(string $contents): array
{
    $patterns = [
        'route:cache' => '/route:cache/',
        'artisan optimize' => '/artisan\s+optimize(?!:)/',
    ];

    $violations = [];

    foreach ($patterns as $needle => $pattern) {
        if (preg_match_all($pattern, $contents, $matches, PREG_OFFSET_CAPTURE) === false) {
            throw new RuntimeException("正規表現の実行に失敗しました: {$pattern}");
        }

        foreach ($matches[0] as $match) {
            $violations[] = [
                'line' => substr_count(substr($contents, 0, (int) $match[1]), "\n") + 1,
                'needle' => $needle,
            ];
        }
    }

    usort($violations, fn (array $a, array $b): int => $a['line'] <=> $b['line']);

    return $violations;
}

/**
 * 検査 A の判定 (純関数)。デプロイ定義の実体とみなすパスを返す。
 *
 * @param  list<string>  $paths
 * @return list<string>
 */
function routeCachePremiseDeployDefinitionPaths(array $paths): array
{
    $directories = ['deploy/', 'ansible/', '.ebextensions/', 'k8s/', 'kubernetes/', 'helm/', 'charts/'];
    $exactNames = [
        'Procfile', 'fly.toml', 'render.yaml', 'app.yaml', 'vercel.json',
        'railway.json', 'captain-definition', '.gitlab-ci.yml', '.travis.yml',
        'azure-pipelines.yml', 'Jenkinsfile',
    ];
    $ciDirectories = ['.circleci/', '.buildkite/'];

    $matched = [];

    foreach ($paths as $path) {
        $basename = basename($path);

        foreach ([...$directories, ...$ciDirectories] as $prefix) {
            if (str_starts_with($path, $prefix)) {
                $matched[] = $path;

                continue 2;
            }
        }

        if (in_array($basename, $exactNames, true)) {
            $matched[] = $path;

            continue;
        }

        if (str_ends_with($path, '.tf') || str_ends_with($path, '.tfvars')) {
            $matched[] = $path;

            continue;
        }

        if (str_starts_with($basename, 'docker-compose') && str_ends_with($basename, '.yml')
            && str_contains($basename, 'prod')) {
            $matched[] = $path;

            continue;
        }

        if (str_starts_with($path, '.github/workflows/')) {
            $lowered = strtolower($basename);
            foreach (['deploy', 'release', 'cd'] as $hint) {
                if (str_contains($lowered, $hint)) {
                    $matched[] = $path;

                    continue 2;
                }
            }
        }
    }

    return $matched;
}

/**
 * 検査 B の走査結果を、ファイル単位で "path:line (needle)" の一覧にして返す。
 *
 * @return array<string, list<string>> repo 相対パス => 一致の一覧
 */
function routeCachePremiseScanByFile(): array
{
    $byFile = [];

    foreach (routeCachePremiseTrackedFiles() as $relative) {
        if (str_ends_with($relative, '.md')) {
            continue;
        }

        if ($relative === ROUTE_CACHE_PREMISE_SELF_PATH) {
            continue;
        }

        $absolute = base_path().'/'.$relative;
        if (! is_file($absolute)) {
            continue; // 削除済みだが index に残っている等
        }

        $contents = file_get_contents($absolute);
        if (! is_string($contents)) {
            throw new RuntimeException("読み取れないファイル: {$relative}");
        }

        if (str_ends_with($relative, '.php')) {
            $contents = routeCachePremiseStripPhpComments($contents);
        }

        foreach (routeCachePremiseViolations($contents) as $violation) {
            $byFile[$relative][] = "{$relative}:{$violation['line']} ({$violation['needle']})";
        }
    }

    return $byFile;
}

/**
 * 件数 pin 済みのファイルを除いた、想定外の一致の一覧。
 *
 * @return list<string>
 */
function routeCachePremiseScanFindings(): array
{
    $findings = [];

    foreach (routeCachePremiseScanByFile() as $relative => $matches) {
        if (array_key_exists($relative, ROUTE_CACHE_PREMISE_KNOWN_MENTIONS)) {
            continue; // 件数の一致は別のテストが完全一致で検査する
        }

        foreach ($matches as $match) {
            $findings[] = $match;
        }
    }

    sort($findings);

    return $findings;
}

/**
 * `docs/template-divergence.md` の当該逸脱の節 (見出しから次の見出しまで)。
 */
function routeCachePremiseDivergenceSection(): string
{
    $document = file_get_contents(base_path().'/docs/template-divergence.md');
    expect($document)->toBeString();

    $heading = '## '.ROUTE_CACHE_DIVERGENCE_ID.' ';
    $start = strpos((string) $document, $heading);
    expect($start)->toBeInt(
        'docs/template-divergence.md に ['.ROUTE_CACHE_DIVERGENCE_ID.'] の見出しがありません',
    );

    $rest = substr((string) $document, (int) $start);
    $next = strpos($rest, "\n## ", 1);

    return $next === false ? $rest : substr($rest, 0, $next);
}

/*
 * 2-1: 検査 A (早期の気づき)。
 */
test('デプロイ定義の実体が追跡下に 1 件も無い (D19 の前提の早期の気づき)', function (): void {
    $matched = routeCachePremiseDeployDefinitionPaths(routeCachePremiseTrackedFiles());

    expect($matched)->toBe([], implode("\n", [
        'デプロイ定義とみなされるファイルが追跡下にあります:',
        '  '.implode("\n  ", $matched),
        '',
        'これは意図した摩擦です。'.ROUTE_CACHE_DIVERGENCE_ID.' (docs/template-divergence.md) を読み直し、',
        '  (1) 経路の一覧が組み上がった後に走らせる専用の実行点クラスへ移行する',
        '  (2) 毎デプロイの `php artisan route:cache` 再生成を機械強制する',
        'のどちらを採るかを同じ PR で決めてください。',
        'デプロイと無関係な名前 (例: 文書公開の workflow) で一致した場合は、',
        '本テストの検査条件と '.ROUTE_CACHE_DIVERGENCE_ID.' の文章を同じ PR で直してください。',
    ]));
});

/*
 * 2-2: 検査 B (本体)。前提を本当に決めるのはこちら。
 */
test('route:cache を実行する記述が追跡下に 1 件も無い (D19 の主前提)', function (): void {
    $findings = routeCachePremiseScanFindings();

    expect($findings)->toBe([], implode("\n", [
        '`route:cache` を実行する記述が追跡下にあります:',
        '  '.implode("\n  ", $findings),
        '',
        ROUTE_CACHE_DIVERGENCE_ID.' は「経路キャッシュ起動では後付けを走らせない」側の契約を、',
        '`route:cache` が実行されないことを前提に許しています。前提が崩れた以上、',
        '専用の実行点クラスへの移行か、毎デプロイ再生成の機械強制かを同じ PR で決めてください。',
    ]));
});

/*
 * 2-2b: 説明文として needle を持つファイルは**件数を完全一致で pin** する。
 *       増えても減っても赤にすることで、ファイル単位の除外が作る死角を無くす。
 */
test('説明として needle を持つファイルの件数が完全一致で pin されている', function (): void {
    $byFile = routeCachePremiseScanByFile();

    $actual = [];
    foreach (ROUTE_CACHE_PREMISE_KNOWN_MENTIONS as $relative => $expected) {
        $actual[$relative] = count($byFile[$relative] ?? []);
    }

    expect($actual)->toBe(ROUTE_CACHE_PREMISE_KNOWN_MENTIONS, implode("\n", [
        '説明文として needle を持つと登録したファイルの件数が変わりました。',
        '増えた場合: 増えた 1 件が本当に「説明」なのかを確認してください。',
        '  実行記述なら '.ROUTE_CACHE_DIVERGENCE_ID.' の前提が崩れています。',
        '減った場合: 登録が不要になったので目録から外してください (空振り green を残さない)。',
        '実際の一致: '.json_encode($byFile, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]));
});

/*
 * 2-3: 走査の母集団が空振りでないこと (床値と代表パスの pin)。
 */
test('走査の母集団が空振りでない (床値と代表パスの pin)', function (): void {
    $tracked = routeCachePremiseTrackedFiles();

    expect(count($tracked))->toBeGreaterThanOrEqual(
        ROUTE_CACHE_PREMISE_MINIMUM_TRACKED_FILES,
        '追跡下ファイルの列挙が少なすぎます (git ls-files が期待どおり動いていない可能性)',
    );

    foreach (ROUTE_CACHE_PREMISE_SENTINEL_PATHS as $sentinel) {
        expect(in_array($sentinel, $tracked, true))->toBeTrue(
            "代表パス [{$sentinel}] が列挙に含まれません。走査域が変わっていないか確認してください。",
        );
    }
});

/*
 * 2-4: 負のコントロール。判定関数の**検出範囲の境界**を固定する。
 *      最後の 1 件は「安全だから許す」ではなく「いまの検出器の境界を見えるようにする」ためである。
 */
test('検出器の境界が固定されている', function (string $sample, bool $shouldDetect): void {
    $detected = routeCachePremiseViolations($sample) !== [];

    expect($detected)->toBe($shouldDetect, "入力: {$sample}");
})->with([
    'php artisan route:cache は検出する' => ['php artisan route:cache', true],
    'Artisan::call の route:cache は検出する' => ["Artisan::call('route:cache');", true],
    'artisan optimize は検出する' => ['php artisan optimize', true],
    '空白が複数の artisan optimize も検出する' => ["php artisan   optimize\n", true],
    'artisan optimize:clear は検出しない' => ['php artisan optimize:clear --except=cache', false],
    'オプションを挟む artisan optimize は検出しない' => ['php artisan --env=production optimize', false],
    '無関係な文字列は検出しない' => ['php artisan migrate --force', false],
]);

/*
 * 2-5: 負のコントロール。コメントは落とすが文字列リテラルは残す、の両方向と、
 *      落とした後も行番号がずれないことを固定する。
 */
test('PHP のコメント中の記述は違反にせず、文字列リテラル中の記述は違反にする', function (): void {
    $commentOnly = <<<'PHP'
        <?php

        // ここでは route:cache の契約を説明しているだけである
        /* php artisan optimize についての説明 */
        /** route:cache の docblock */
        $value = 1;
        PHP;

    expect(routeCachePremiseViolations(routeCachePremiseStripPhpComments($commentOnly)))->toBe([]);

    $literal = <<<'PHP'
        <?php

        /*
         * 複数行にまたがる説明。
         * route:cache について書いてある。
         */
        Artisan::call('route:cache');
        PHP;

    $violations = routeCachePremiseViolations(routeCachePremiseStripPhpComments($literal));

    expect($violations)->toHaveCount(1);
    expect($violations[0]['needle'])->toBe('route:cache');
    // 元の文字列で `Artisan::call` は 7 行目にある (コメントを落としても行番号は動かない)
    expect($violations[0]['line'])->toBe(7);
});

/*
 * 2-6: D19 と本テストの結線が切れていないこと。
 *      **保証範囲を誇張しない**: これは「参照が切れていないこと」までで、
 *      文章の意味が検査と一致していることは機械では見られない。
 */
test('逸脱の登録と本テストの結線が切れていない', function (): void {
    $section = routeCachePremiseDivergenceSection();

    // ★`toContain()` は可変長 needle を取るためメッセージ引数を持てない。bool へ落として理由を書く。
    expect(str_contains($section, 'RouteCacheExemptionPremiseTest.php'))->toBeTrue(
        ROUTE_CACHE_DIVERGENCE_ID.' の節が本テストのファイル名を書いていません',
    );

    foreach (['route:cache', 'artisan optimize', 'デプロイ定義'] as $keyword) {
        expect(str_contains($section, $keyword))->toBeTrue(
            ROUTE_CACHE_DIVERGENCE_ID.' の節に検査条件の要点 ['.$keyword.'] がありません',
        );
    }

    foreach (['AGENTS.md', 'docs/app-integration-guide.md'] as $referrer) {
        $document = file_get_contents(base_path().'/'.$referrer);
        expect($document)->toBeString();
        expect(str_contains((string) $document, ROUTE_CACHE_DIVERGENCE_ID))->toBeTrue(
            "{$referrer} が ".ROUTE_CACHE_DIVERGENCE_ID.' を参照していません',
        );
    }
});
