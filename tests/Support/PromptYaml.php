<?php

declare(strict_types=1);

namespace Tests\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Yaml\Yaml;
use Throwable;

/**
 * prompt YAML 契約テスト群 (PromptYamlContractTest / PromptClientTimeoutInvariantTest /
 * DefensiveInstructionsPresenceTest) の共有走査ヘルパ。
 *
 * 各テストを targeted 実行しても動くよう、Pest テストファイル内の関数ではなく
 * autoload されるクラスに置く。
 */
final class PromptYaml
{
    /**
     * resources/prompts 配下の *.yaml/*.yml 絶対パス (deny-by-default 再帰走査)。
     *
     * @return list<string>
     */
    public static function paths(): array
    {
        $base = base_path('resources/prompts');
        if (! is_dir($base)) {
            return [];
        }

        $paths = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
        /** @var SplFileInfo $file */
        foreach ($it as $file) {
            // 大文字拡張子 (.YAML/.YML) も拾う (deny-by-default の穴を塞ぐ)。
            if (in_array(strtolower($file->getExtension()), ['yaml', 'yml'], true)) {
                $paths[] = $file->getPathname();
            }
        }
        sort($paths);

        return $paths;
    }

    /**
     * parse して連想配列 (map) を返す。失敗 / 非 map は $violations に積み null を返す。
     *
     * @param  list<string>  $violations
     * @return array<string, mixed>|null
     */
    public static function parseOrFail(string $path, array &$violations): ?array
    {
        try {
            $parsed = Yaml::parseFile($path);
        } catch (Throwable $e) {
            $violations[] = "{$path}: parse 失敗 ({$e->getMessage()})";

            return null;
        }

        if (! is_array($parsed) || array_is_list($parsed)) {
            $violations[] = "{$path}: top-level が連想配列(map)でない";

            return null;
        }

        /** @var array<string, mixed> $parsed */
        return $parsed;
    }
}
