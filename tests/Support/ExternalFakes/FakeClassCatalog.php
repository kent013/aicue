<?php

declare(strict_types=1);

namespace Tests\Support\ExternalFakes;

use App\Providers\BughuntFakesServiceProvider;
use App\Support\FakeStorageGate;
use FilesystemIterator;
use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/**
 * fake クラスの母集団導出 (ハードコード一覧を持たない = fake が増えたら自動で母集団に入る)。
 *
 * 定義 1「fake 実装クラス」 = app/ 配下で `Fakes/` か `Testing/` ディレクトリに置かれた全クラス
 * 定義 2「fake 命名クラス」 = app/ 配下でクラス名が `Fake` で始まる or `Fake` で終わるクラス
 *
 * 前提: **1 ファイル 1 クラス + PSR-4 (`App\` => `app/`)**。Pint / composer の PSR-4 autoload が
 * 強制しているため、path から FQCN を導出する (token 解析より安定)。
 *
 * パス表記はすべて **repo ルート相対** (`app/Providers/Foo.php` / `routes/web.php`) で統一する。
 * 唯一の例外が {@see self::classFromPath()} で、これは `app/` 配下の repo 相対パスのみを受ける。
 */
final class FakeClassCatalog
{
    /** 参照走査の走査根 (repo ルート相対)。app/ だけだと route / config 直書きの抜け道が残る */
    private const array SCAN_ROOTS = ['app', 'routes', 'config', 'bootstrap'];

    /** fake 実装クラスを置くディレクトリ名 (path segment 完全一致で判定する) */
    private const array FAKE_DIRECTORIES = ['Fakes', 'Testing'];

    /**
     * 走査から外す生成物ディレクトリ (repo ルート相対の接頭辞)。
     *
     * `bootstrap/cache/` は `php artisan config:cache` 等が吐く**生成物**で .gitignore 済み。
     * ソースではないうえ、存在するかどうかが実行環境に依存するため走査すると gate が
     * 非決定になる (キャッシュ生成の有無で赤/緑が変わる)。
     */
    private const array EXCLUDED_PREFIXES = ['bootstrap/cache/'];

    /** repo ルートの絶対パス (tests/Support/ExternalFakes から 3 段上) */
    public static function repoRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    /**
     * 「fake の実体ではない配線基盤」の目録。用途は 2 つある。
     *
     * 1. 定義 2 (Fake 命名) に当たるが定義 1 (Fakes/ • Testing/ 配下) に属さなくてよい**配置の例外**
     * 2. **参照走査 (FakeClassReferenceInvariantTest 4-3) の候補**に必ず含める集合
     *
     * ★BughuntFakesServiceProvider は家系の名前へ改名した結果、名前の規則 (定義 2) では
     *   拾えなくなった。それでも本目録に残すのは用途 2 のためである — ここから落とすと、
     *   業務コードが唯一の配線点を直接参照しても検出できず**偽グリーン**になる
     *   (4-3 の docblock が名指しで警告している事故)。この包含は 4-3 の明示 assertion が固定する。
     *
     * @return array<class-string, string> class => 理由
     */
    public static function placementExceptions(): array
    {
        return [
            BughuntFakesServiceProvider::class => 'fake の実装ではなく唯一の配線 provider。Providers/ 配下にある必然性がある。',
            FakeStorageGate::class => 'fake の実装ではなく gate predicate (有効化条件の SSOT)。provider と action guard の双方が参照する。',
        ];
    }

    /**
     * 定義 1: fake 実装クラス。母集団は app/ のみ (PSR-4 のクラス定義があるのは app/ だけ)。
     *
     * @return list<class-string>
     */
    public static function implementationClasses(): array
    {
        $classes = [];
        foreach (self::phpFilesUnder('app') as $path) {
            // ファイル名を除いたディレクトリ segment に Fakes / Testing が含まれるか (完全一致)。
            // 'FakesHelper' のような別名ディレクトリを巻き込まないため部分一致にしない。
            $segments = explode('/', $path);
            array_pop($segments);
            if (array_intersect($segments, self::FAKE_DIRECTORIES) === []) {
                continue;
            }
            $classes[] = self::classFromPath($path);
        }

        return $classes;
    }

    /**
     * 定義 2: fake 命名クラス。母集団は app/ のみ。
     *
     * @return list<class-string>
     */
    public static function namedClasses(): array
    {
        $classes = [];
        foreach (self::phpFilesUnder('app') as $path) {
            $name = basename($path, '.php');
            if (! str_starts_with($name, 'Fake') && ! str_ends_with($name, 'Fake')) {
                continue;
            }
            $classes[] = self::classFromPath($path);
        }

        return $classes;
    }

    /**
     * 参照走査の対象となる本番コード全ファイル (**repo ルート相対**パス)。
     *
     * 走査根は 4 つ: app/ • routes/ • config/ • bootstrap/。
     * 対象は `.php` 拡張子のファイルのみ。
     *
     * @return list<string> 例: 'app/Providers/BughuntFakesServiceProvider.php', 'routes/web.php'
     */
    public static function scanFiles(): array
    {
        $files = [];
        foreach (self::SCAN_ROOTS as $root) {
            foreach (self::phpFilesUnder($root) as $path) {
                $files[] = $path;
            }
        }
        sort($files);

        return $files;
    }

    /**
     * repo 相対パス → FQCN。**app/ 配下のパスのみ**を受け取る (PSR-4: app/Foo/Bar.php => App\Foo\Bar)。
     * app/ 以外を渡された場合は例外を投げる (誤用を静かに通さない)。
     *
     * @return class-string
     */
    public static function classFromPath(string $repoRelativePath): string
    {
        if (! str_starts_with($repoRelativePath, 'app/') || ! str_ends_with($repoRelativePath, '.php')) {
            throw new InvalidArgumentException(
                "classFromPath() は app/ 配下の .php パスのみを受け取る: {$repoRelativePath}"
            );
        }

        $relative = substr($repoRelativePath, strlen('app/'), -strlen('.php'));

        /** @var class-string $class */
        $class = 'App\\'.str_replace('/', '\\', $relative);

        return $class;
    }

    /**
     * repo 相対パスのソースを読む (読み取り失敗を空文字で握り潰さない)。
     *
     * gate は「走査結果が空」を「違反なし」と解釈するため、読み取り失敗を黙って
     * 空文字にすると gate が静かに無力化する。どのファイルが読めなかったかを必ず示す。
     */
    public static function sourceOf(string $repoRelativePath): string
    {
        $absolute = self::repoRoot().'/'.$repoRelativePath;
        $source = @file_get_contents($absolute);

        if (! is_string($source) || $source === '') {
            throw new RuntimeException("ソースを読み取れない (fake 配線 gate の走査対象): {$repoRelativePath}");
        }

        return $source;
    }

    /**
     * 走査根配下の .php ファイル (repo ルート相対・昇順)。
     *
     * @return list<string>
     */
    private static function phpFilesUnder(string $root): array
    {
        $absoluteRoot = self::repoRoot().'/'.$root;
        if (! is_dir($absoluteRoot)) {
            throw new RuntimeException("走査根が存在しない: {$root}");
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($absoluteRoot, FilesystemIterator::SKIP_DOTS)
        );

        $files = [];
        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $relative = $root.'/'.str_replace(
                '\\',
                '/',
                substr($file->getPathname(), strlen($absoluteRoot) + 1)
            );

            foreach (self::EXCLUDED_PREFIXES as $prefix) {
                if (str_starts_with($relative, $prefix)) {
                    continue 2;
                }
            }

            $files[] = $relative;
        }
        sort($files);

        return $files;
    }
}
