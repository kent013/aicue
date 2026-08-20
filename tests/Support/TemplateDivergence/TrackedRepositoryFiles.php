<?php

declare(strict_types=1);

namespace Tests\Support\TemplateDivergence;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * git 追跡下の全ファイルを列挙する (拡張子で絞らない)。
 *
 * ★`Tests\Support\TrackedPhpSourceFiles` は `-- *.php` 限定なので本用途には使えない
 *   (母集合は拡張子を問わない全追跡ファイルである)。本クラスは
 *   **TemplateDivergence の検査専用**であり、他 gate の走査根を置き換える主張はしない
 *   (寄せる作業に見合う不変条件の増加が無い。AGENTS.md の単一出典要求は
 *   「PHP 全数の走査」に向けられている)。
 *
 * ★**保証しないもの**:
 *   - 未追跡ファイルは列挙しない (本機構が守る境界は commit / CI である)
 *   - git が無い / 失敗した場合は**空を返さず例外にする** (fail-open 防止)
 *   - index に残っているが working tree に無いパスも列挙する
 *     (削除の検出は利用側が行う。突合 gate では `MissingCurrent` になる)
 *   - **母集団の非空を契約にしない**。0 件が異常かどうかは利用側の gate が判定する
 *     (突合 gate の F4 / 生成器の実行不能判定)
 */
final class TrackedRepositoryFiles
{
    /** インスタンス化しない (純関数のみ)。 */
    private function __construct() {}

    /**
     * @return list<string> repo-relative パスの昇順 (重複なし)
     *
     * @throws RuntimeException git を実行できない / 失敗した / 不正なパスを返した
     */
    public static function all(string $root): array
    {
        $process = new Process(['git', 'ls-files', '-z'], $root);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException(
                'git ls-files の実行に失敗した (実行不能は fail): '.trim($process->getErrorOutput()),
            );
        }

        $paths = [];
        foreach (explode("\0", $process->getOutput()) as $path) {
            if ($path === '') {
                continue;
            }
            if (! RepoRelativePath::isValid($path)) {
                // 解決できない形は黙って外さず落とす (共通規約 (b))
                throw new RuntimeException('git ls-files が単一ファイルパスでない値を返した: '.var_export($path, true));
            }
            if (array_key_exists($path, $paths)) {
                throw new RuntimeException("git ls-files が同じパスを 2 回返した: {$path}");
            }
            $paths[$path] = true;
        }

        $result = array_keys($paths);
        sort($result, SORT_STRING);

        return $result;
    }
}
