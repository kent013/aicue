<?php

declare(strict_types=1);

/*
 * テンプレート規約: アプリ slug のハードコード禁止。
 *
 * アプリ固有の識別子は config/template.php (TEMPLATE_APP_SLUG) と .env にのみ現れてよい。
 * コード中に slug を直書きすると、テンプレート派生アプリ間の copy-paste で別アプリの
 * 名前が混入する事故が起きる (spirux の tests/bootstrap.php に aigenba- が残っていた実例)。
 *
 * 検査: app/ routes/ database/ resources/js/ scripts/ の中に
 * config('template.slug') 以外の経路で slug 既定値が現れないこと。
 * 既定 slug は 'app' で一般語のため、ここでは「.env.example の TEMPLATE_APP_SLUG 値」を
 * 動的に取得して走査する (アプリが slug を変更した後も機能する)。
 *
 * 空振り検査 (AGENTS.md §静的検査 (gate) と走査器の共通規約 (b) の
 * 「違反が 0 件」と「母集団が 0 件」の区別): 本 gate は**走査根の非空が不変条件**である。
 * 5 本の走査根はどれか 1 本が改名・移動しても違反ゼロのまま緑になるため、
 * 「空振り検査」ケースが 5 本すべての生存 (実在かつファイルを持つ) を固定し、
 * その直後の負のコントロールが「根を差し替えると母集団が空になる」ことを示す。
 *
 * ★slug が既定値 'app' のままの間、**判定は一般語の誤検出を避けるため意図的に走らない**。
 *   その間も判定そのものが壊れていないことを示すため、判定を
 *   `appSlugHardcodeViolations()` へ分離し、**当たる語**と**当たらない語**の両方向を
 *   実在の走査根に対して裏取りする (「自己検査」ケース)。
 *   派生アプリが固有 slug を設定した瞬間に、この判定がそのまま働く。
 */

/**
 * slug 走査の根 (リポジトリ相対パス)。
 *
 * @return list<string>
 */
function appSlugScanRoots(): array
{
    return ['app', 'routes', 'database', 'resources/js', 'scripts'];
}

/**
 * 走査根配下の全ファイル (絶対パス)。根が実在しなければ空を返す。
 *
 * @return list<string>
 */
function appSlugScanFiles(string $absoluteRoot): array
{
    if (! is_dir($absoluteRoot)) {
        return [];
    }

    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($absoluteRoot, FilesystemIterator::SKIP_DOTS)
    );
    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $files[] = $file->getPathname();
        }
    }
    sort($files);

    return $files;
}

/** .env.example が宣言する TEMPLATE_APP_SLUG の値 (未宣言なら空文字)。 */
function appSlugFromEnvExample(): string
{
    $envExample = file_get_contents(base_path('.env.example'));
    expect($envExample)->toBeString();
    /** @var string $envExample */
    preg_match('/^TEMPLATE_APP_SLUG=(.+)$/m', $envExample, $m);

    return trim($m[1] ?? '');
}

/**
 * 走査根の一覧から、与えた語を含むファイル (リポジトリ相対パス) を集める。
 *
 * 判定を関数へ分離してあるのは、slug が既定値のままでも**判定そのものを両方向で
 * 裏取りできるようにする**ためである (下の「自己検査」ケース)。
 *
 * @param  list<string>  $roots  走査根 (リポジトリ相対パス)
 * @return list<string>
 */
function appSlugHardcodeViolations(array $roots, string $needle): array
{
    $violations = [];
    foreach ($roots as $root) {
        foreach (appSlugScanFiles(base_path($root)) as $path) {
            $contents = file_get_contents($path);
            if ($contents !== false && str_contains($contents, $needle)) {
                $violations[] = str_replace(base_path().'/', '', $path);
            }
        }
    }
    sort($violations);

    return $violations;
}

test('アプリ slug がコードにハードコードされていない', function (): void {
    $slug = appSlugFromEnvExample();

    // 既定値 'app' は一般語のため走査対象外 (派生アプリが固有 slug を設定した時点で発動する)
    if ($slug === '' || $slug === 'app') {
        expect(true)->toBeTrue();

        return;
    }

    $violations = appSlugHardcodeViolations(appSlugScanRoots(), $slug);

    expect($violations)->toBe([], 'slug "'.$slug.'" のハードコードを検出: '.implode(', ', $violations));
});

test('自己検査: 判定が当たる語を拾い、当たらない語を拾わない', function (): void {
    // slug が既定値 'app' の間、上の判定は早期 return するので一度も実行されない。
    // 判定が壊れたまま緑になるのを防ぐため、実在の走査根に対して両方向を固定する。
    // 当たる語: app/ 配下の PHP は全数が strict_types を宣言している
    // (StrictTypesDeclarationGateTest が deny-by-default で強制している事実に乗る)。
    expect(appSlugHardcodeViolations(['app'], 'declare(strict_types=1);'))
        ->not->toBe([], '判定が「必ず在る語」を拾えていません');
    // 当たらない語: **走査対象のどのファイルにも**書かれていない語では 1 件も拾わない
    // (誤検出しない)。この語は本ファイルには在るが、tests/ は走査根ではない。
    expect(appSlugHardcodeViolations(appSlugScanRoots(), 'slug-that-must-not-exist-in-this-repository'))
        ->toBe([]);
});

test('空振り検査: 5 本の走査根がいずれも生きている (実在しファイルを持つ)', function (): void {
    foreach (appSlugScanRoots() as $root) {
        $absolute = base_path($root);
        expect(is_dir($absolute))->toBeTrue("走査根 {$root} が存在しません");
        expect(appSlugScanFiles($absolute))->not->toBe([], "走査根 {$root} にファイルがありません");
    }
});

test('負のコントロール: 走査根を差し替えると母集団が空になる', function (): void {
    // 上の生存検査が空振りしていないことの裏取り。走査根の改名・移動を模して
    // 実在しないパスを渡すと母集団が 0 件になる = 生存検査が赤くなる。
    expect(appSlugScanFiles(base_path('app-renamed')))->toBe([]);
    expect(appSlugScanFiles(base_path('resources/js-renamed')))->toBe([]);
});
