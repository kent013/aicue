<?php

declare(strict_types=1);

namespace Tests\Support;

use BackedEnum;
use RuntimeException;

/**
 * PHP enum ⇔ TS literal union の値集合同期 invariant 用の抽出ヘルパ。
 * ManualEnumTsSyncInvariantTest / NotificationTypeTsSyncInvariantTest が共有する
 * (T008 で ManualEnumTsSyncInvariantTest 内のローカル関数から昇格)。
 */
final class TsUnionValues
{
    /**
     * TS ファイルから `export type {Name} = "a" | "b" | ...;` の値集合を抽出する。
     * 抽出不能 (degenerate PASS) は fail させる (RuntimeException)。
     *
     * @param  string  $relativePath  base_path からの相対パス (例: resources/js/types/manual.ts)
     * @return list<string>
     */
    public static function extract(string $relativePath, string $typeName): array
    {
        $path = base_path($relativePath);
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException("TS ファイルを読めません: {$path}");
        }

        // `export type X =` から次の `;` までを取り出す (複数行 union 対応)
        $matched = preg_match(
            '/export\s+type\s+'.preg_quote($typeName, '/').'\s*=\s*(.*?);/s',
            $contents,
            $matches,
        );
        if ($matched !== 1) {
            throw new RuntimeException("TS union が抽出できません (degenerate PASS 防止): {$typeName}");
        }

        $literalCount = preg_match_all('/"([^"]+)"/', $matches[1], $literals);
        if ($literalCount === false || $literalCount === 0) {
            throw new RuntimeException("TS union のリテラルが抽出できません: {$typeName}");
        }

        $values = $literals[1];
        sort($values);

        return $values;
    }

    /**
     * @param  list<BackedEnum>  $cases
     * @return list<string>
     */
    public static function enumStringValues(array $cases): array
    {
        $values = array_map(static fn (BackedEnum $case): string => (string) $case->value, $cases);
        sort($values);

        return $values;
    }
}
