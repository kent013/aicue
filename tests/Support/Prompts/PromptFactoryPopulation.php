<?php

declare(strict_types=1);

namespace Tests\Support\Prompts;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/**
 * 依頼文 factory (`app/Prompts/`) の母集団を**再帰**で全数列挙する。
 *
 * ★`tests/Architecture/PromptUntrustedInputContractTest.php` も同種の列挙を持つが、
 *   同ファイルは**採用時債務一覧に採用時 sha 付きで凍結**されているため触らない
 *   (触ると「戻す / テンプレートへ同期 / 逸脱登録」のいずれかが必須になり、
 *    20 行の重複を消す利得に見合わない = 思考原則 2)。よって本クラスは
 *   `LlmResponseDecodePointGateTest` 専用の列挙として独立して持つ。
 *
 * ★**走査根の不在は fail-fast** で落とす (根の移動 / typo で黙って PASS しない)。
 *   母集団の非空は**利用側 gate** が検査する (共通規約 (b) の「母集団 0 件と違反 0 件を区別する」)。
 *
 * ## 保証しないもの
 *
 * - 1 ファイル 1 クラス・PSR-4 (`App\Prompts\` => `app/Prompts/`) を前提にパスから
 *   クラス名を作る。1 ファイルに複数クラスを書いた場合・名前空間がパスと一致しない場合は
 *   その差を見ない (本リポジトリの `app/Prompts/` は全件この前提を満たす)。
 * - 抽象クラス / trait / interface を区別しない (実在するクラスかどうかだけを見る)。
 */
final class PromptFactoryPopulation
{
    /** 走査根 (リポジトリルートからの相対パス)。 */
    private const string ROOT = 'app/Prompts';

    /** 走査根に対応する名前空間。 */
    private const string NAMESPACE_PREFIX = 'App\\Prompts\\';

    /** 走査根の絶対パス。**存在しなければ例外**。 */
    public static function root(): string
    {
        return self::resolve(self::ROOT);
    }

    /**
     * リポジトリルートからの相対パスを絶対パスへ解決する。**存在しなければ例外** (fail-fast)。
     *
     * ★自己検査が「根の不在で実際に落ちること」を確かめられるよう public にしてある
     *   (根を消して確かめる手段が他に無い)。
     */
    public static function resolve(string $relativeRoot): string
    {
        $absolute = realpath(dirname(__DIR__, 3).'/'.$relativeRoot);
        if (! is_string($absolute)) {
            throw new RuntimeException('走査根を解決できません: '.$relativeRoot);
        }

        return $absolute;
    }

    /**
     * 依頼文 factory の完全修飾名 (昇順)。
     *
     * @return list<class-string>
     */
    public static function classes(): array
    {
        $root = self::root();
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        );

        $classes = [];
        foreach ($iterator as $file) {
            if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $relative = ltrim(str_replace($root, '', $file->getPathname()), '/');
            $class = self::NAMESPACE_PREFIX.str_replace('/', '\\', substr($relative, 0, -4));
            if (! class_exists($class)) {
                throw new RuntimeException("依頼文 factory のクラスを解決できません: {$class}");
            }
            $classes[] = $class;
        }
        sort($classes);

        return $classes;
    }
}
