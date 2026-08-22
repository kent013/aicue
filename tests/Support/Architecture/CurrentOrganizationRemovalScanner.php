<?php

declare(strict_types=1);

namespace Tests\Support\Architecture;

use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\Support\PhpReferenceScanner;
use Tests\Support\PhpTokenScan;
use Tests\Support\ReferenceKind;
use Tests\Support\TrackedPhpSourceFiles;

/**
 * 「いまどの組織か」の保持方式の残骸を検出する走査器 (家系裁定 AG-037)。
 *
 * ★**検出語を文字列リテラルとして持たない**。断片を連結して組み立てるので、本走査器と
 *   利用側 gate は自分自身の走査に引っかからない (自己例外の目録を持たずに済む)。
 * ★名前の解決は `PhpReferenceScanner` (完全修飾名まで解決済み) に委ねる。
 *   別名つき取り込み (`use ... as ...`) で黙らない。
 * ★**解決できない形は落とす**方針だが、本走査器が見るのは
 *   「字句として現れた列名・route 名」と「解決済み FQCN・型」だけである。
 *   実行時に組み立てた名前 (`'current_'.$suffix`) には**無言で効かない**。
 *   この構文について検出力を主張しない。
 *
 * 走査根:
 *  - `TrackedPhpSourceFiles` (git 追跡下の PHP 全数)
 *  - `resources/js` の `.ts` / `.svelte`
 *  - `database/` の PHP (migration / seeder / factory)
 */
final class CurrentOrganizationRemovalScanner
{
    /** 撤去した保持列の列名 (断片から組み立てる)。 */
    public static function columnName(): string
    {
        return 'current_'.'organization'.'_id';
    }

    /** 撤去した Service の完全修飾名 (断片から組み立てる)。 */
    public static function removedServiceFqcn(): string
    {
        return 'App\\Services\\Organization\\'.'Current'.'OrganizationResolver';
    }

    /** 撤去した route 名 (断片から組み立てる)。 */
    public static function removedRouteName(): string
    {
        return 'organizations.'.'switch';
    }

    /** relation / プロパティ名 (断片から組み立てる)。 */
    private static function relationName(): string
    {
        return 'current'.'Organization';
    }

    /**
     * 走査根ごとのファイル一覧 (label => list<array{absolute: string, relative: string}>)。
     *
     * @return array<string, list<array{absolute: string, relative: string}>>
     */
    public static function roots(string $base): array
    {
        return [
            'tracked-php' => TrackedPhpSourceFiles::all($base),
            'resources/js' => self::trackedFiles($base, 'resources/js', ['ts', 'svelte']),
            'database' => self::trackedFiles($base, 'database', ['php']),
        ];
    }

    /**
     * 列名リテラルの出現 (撤去 migration だけは除く)。
     *
     * @return list<string> "relative:line" の一覧
     */
    public static function columnLiteralHits(string $base): array
    {
        $needle = self::columnName();
        $hits = [];
        foreach (self::roots($base) as $files) {
            foreach ($files as $file) {
                if (str_contains($file['relative'], 'drop_'.$needle.'_from_users_table')) {
                    continue; // 撤去そのものを書いた migration
                }
                $contents = (string) file_get_contents($file['absolute']);
                foreach (explode("\n", $contents) as $index => $line) {
                    if (str_contains($line, $needle)) {
                        $hits[] = $file['relative'].':'.($index + 1);
                    }
                }
            }
        }

        return array_values(array_unique($hits));
    }

    public static function containsColumnName(string $source): bool
    {
        return str_contains($source, self::columnName());
    }

    /**
     * `User` に対する relation 宣言 / プロパティアクセス。
     *
     * @param  list<array{absolute: string, relative: string}>  $files
     * @return list<string>
     */
    public static function userRelationHits(array $files): array
    {
        $hits = [];
        foreach ($files as $file) {
            $source = (string) file_get_contents($file['absolute']);
            if (self::sourceHasUserRelation($source)) {
                $hits[] = $file['relative'];
            }
        }

        return $hits;
    }

    /**
     * 1 ファイル分の判定。
     *
     * 検出するのは 2 形だけである:
     *  (a) `App\Models\User` を宣言したクラス本体での `function currentOrganization(`
     *  (b) `App\Models\User` を**知っている**ファイルでの `->currentOrganization`
     *      (`?->` を含む。連想配列キー `['currentOrganization']` は対象外)
     */
    public static function sourceHasUserRelation(string $source): bool
    {
        $relation = self::relationName();
        $tokens = PhpTokenScan::normalize($source);
        $result = PhpReferenceScanner::references('synthetic.php', $source);

        $knowsUser = in_array('App\\Models\\User', $result->imports, true);
        foreach ($result->sites as $site) {
            if ($site->kind === ReferenceKind::NameReference && $site->name === 'App\\Models\\User') {
                $knowsUser = true;
            }
            if ($site->class === 'App\\Models\\User') {
                $knowsUser = true;
            }
        }

        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if ($token['id'] !== T_STRING || $token['text'] !== $relation) {
                continue;
            }
            $previous = $tokens[$i - 1] ?? null;
            $next = $tokens[$i + 1] ?? null;

            // (a) relation 宣言
            if ($previous !== null && $previous['id'] === T_FUNCTION) {
                return true;
            }
            // (b) プロパティアクセス (`->` / `?->`)
            if ($previous !== null
                && in_array($previous['id'], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)
                && $knowsUser
                && ! ($next !== null && $next['text'] === '(')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array{absolute: string, relative: string}>  $files
     * @return list<string>
     */
    public static function removedServiceHits(array $files): array
    {
        $hits = [];
        foreach ($files as $file) {
            $source = (string) file_get_contents($file['absolute']);
            if (self::sourceHasRemovedService($source)) {
                $hits[] = $file['relative'];
            }
        }

        return $hits;
    }

    public static function sourceHasRemovedService(string $source): bool
    {
        $fqcn = self::removedServiceFqcn();
        $result = PhpReferenceScanner::references('synthetic.php', $source);

        if (in_array($fqcn, $result->imports, true)) {
            return true;
        }
        foreach ($result->sites as $site) {
            if ($site->name === $fqcn) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    public static function removedRouteNameHits(string $base): array
    {
        $hits = [];
        foreach (self::roots($base) as $files) {
            foreach ($files as $file) {
                $contents = (string) file_get_contents($file['absolute']);
                foreach (explode("\n", $contents) as $index => $line) {
                    if (self::containsRemovedRouteName($line)) {
                        $hits[] = $file['relative'].':'.($index + 1);
                    }
                }
            }
        }

        return array_values(array_unique($hits));
    }

    public static function containsRemovedRouteName(string $source): bool
    {
        return str_contains($source, self::removedRouteName());
    }

    /**
     * git 追跡下のファイルを拡張子で絞って列挙する (存在しない根は fail-fast)。
     *
     * @param  list<string>  $extensions
     * @return list<array{absolute: string, relative: string}>
     */
    private static function trackedFiles(string $base, string $directory, array $extensions): array
    {
        if (! is_dir($base.'/'.$directory)) {
            throw new RuntimeException("走査根が存在しません: {$directory}");
        }

        $process = new Process(['git', 'ls-files', '-z', '--', $directory], $base);
        $process->run();
        if (! $process->isSuccessful()) {
            throw new RuntimeException('git ls-files の実行に失敗しました: '.$process->getErrorOutput());
        }

        $files = [];
        foreach (explode("\0", $process->getOutput()) as $relative) {
            if ($relative === '') {
                continue;
            }
            $extension = pathinfo($relative, PATHINFO_EXTENSION);
            if (! in_array($extension, $extensions, true)) {
                continue;
            }
            $absolute = $base.'/'.$relative;
            if (! is_file($absolute)) {
                continue;
            }
            $files[] = ['absolute' => $absolute, 'relative' => $relative];
        }

        usort($files, fn (array $a, array $b): int => strcmp($a['relative'], $b['relative']));

        return $files;
    }
}
