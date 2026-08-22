<?php

declare(strict_types=1);

namespace Tests\Support\Architecture;

use Tests\Support\PhpReferenceScanner;
use Tests\Support\PhpTokenScan;

/**
 * `organizations.slug` へ値を書く形を全数抽出する走査器 (家系裁定 AG-039)。
 *
 * ## 抽出する形と rule ID
 *
 * | rule ID | 形 |
 * |---|---|
 * | `raw-sql-update` | 生 SQL の文字列リテラルで `organizations` の `slug` を UPDATE する |
 * | `query-builder-update` | `->update([... 'slug' => …])` |
 * | `query-builder-insert` | `->insert([... 'slug' => …])` / `insertGetId` / `upsert` |
 * | `mass-assignment` | `new Organization([… 'slug' => …])` / `fill` / `create` / `updateOrCreate` / `firstOrCreate` |
 * | `force-fill` | `->forceFill([… 'slug' => …])` |
 * | `factory-definition` | Factory の `definition()` / `state()` が返す配列 |
 *
 * ## 判定の単位
 *
 * `'slug'` という**配列キー**の出現ごとに、それが属する配列リテラルを開いた
 * 直前の呼び出し名を token 列から遡って決める。呼び出し名が上表のどれでもない場合は
 * **書き込みではない** (画面 props の `'slug' => $organization->slug` などがこれに当たる)。
 *
 * ## 保証しないもの (誇張しない。利用側 gate の主張もこの範囲に狭める)
 *
 * - **変数に組み立てた配列を書き込み呼びへ渡す形**
 *   (`$attributes = ['slug' => …]; $model->forceFill($attributes);`) は
 *   呼び出し名を決められないため**検出しない**。この構文について検出力を主張しない。
 * - **動的なキー** (`[$column => …]`) と**動的なメソッド名** (`$model->$method(...)`) も同様である。
 * - 生 SQL は**文字列リテラルとして現れたもの**だけを見る。実行時に連結した SQL は見えない。
 * - `slug` 列を持つテーブルは `organizations` だけである (実測) ため、キー名だけで
 *   テーブルを特定している。**他テーブルに `slug` 列が増えたらこの前提は崩れる**
 *   (そのときは利用側 gate が「無関係な書き込み」を違反として拾い、赤で気付ける)。
 */
final class OrganizationSlugWriteScanner
{
    /** 書き込み呼びの語彙 => rule ID。 */
    private const array WRITE_CALLS = [
        'update' => 'query-builder-update',
        'updateQuietly' => 'query-builder-update',
        'insert' => 'query-builder-insert',
        'insertGetId' => 'query-builder-insert',
        'insertOrIgnore' => 'query-builder-insert',
        'upsert' => 'query-builder-insert',
        'forceFill' => 'force-fill',
        'fill' => 'mass-assignment',
        'create' => 'mass-assignment',
        'createQuietly' => 'mass-assignment',
        'forceCreate' => 'mass-assignment',
        'updateOrCreate' => 'mass-assignment',
        'firstOrCreate' => 'mass-assignment',
        'make' => 'mass-assignment',
        'Organization' => 'mass-assignment',   // `new Organization([...])`
        'state' => 'factory-definition',
    ];

    /**
     * 全ファイルの書き込み site を抽出する。
     *
     * @param  list<array{absolute: string, relative: string}>  $files
     * @return list<array{path: string, rule: string, line: int}>
     */
    public static function sites(array $files): array
    {
        $sites = [];
        foreach ($files as $file) {
            $source = (string) file_get_contents($file['absolute']);
            foreach (self::sitesInSource($source, str_contains($file['relative'], 'database/factories/')) as $site) {
                $sites[] = ['path' => $file['relative'], 'rule' => $site['rule'], 'line' => $site['line']];
            }
        }

        return $sites;
    }

    /**
     * 1 ファイル分の書き込み site。
     *
     * @return list<array{rule: string, line: int}>
     */
    public static function sitesInSource(string $source, bool $isFactory = false): array
    {
        $tokens = PhpTokenScan::normalize($source);
        $count = count($tokens);
        $sites = [];

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            // 生 SQL の UPDATE
            if ($token['id'] === T_CONSTANT_ENCAPSED_STRING && self::isRawSlugUpdate($token['text'])) {
                $sites[] = ['rule' => 'raw-sql-update', 'line' => $token['line']];

                continue;
            }

            if ($token['id'] !== T_CONSTANT_ENCAPSED_STRING || trim($token['text'], "'\"") !== 'slug') {
                continue;
            }
            if (($tokens[$i + 1]['id'] ?? null) !== T_DOUBLE_ARROW) {
                continue;
            }

            $rule = self::enclosingWriteRule($tokens, $i, $isFactory);
            if ($rule !== null) {
                $sites[] = ['rule' => $rule, 'line' => $token['line']];
            }
        }

        return $sites;
    }

    /** 生 SQL の文字列リテラルが organizations.slug を UPDATE しているか。 */
    private static function isRawSlugUpdate(string $literal): bool
    {
        $lower = mb_strtolower($literal);

        return str_contains($lower, 'update') && str_contains($lower, 'organizations') && str_contains($lower, 'slug');
    }

    /**
     * `'slug' =>` が属する配列リテラルを開いた呼び出しの rule ID (書き込みでなければ null)。
     *
     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
     */
    private static function enclosingWriteRule(array $tokens, int $keyIndex, bool $isFactory): ?string
    {
        // 直前へ遡り、この配列リテラルを開いた `[` / `array(` の位置を求める
        $depth = 0;
        $open = null;
        for ($i = $keyIndex - 1; $i >= 0; $i--) {
            $text = $tokens[$i]['text'];
            if (in_array($text, [']', ')'], true)) {
                $depth++;

                continue;
            }
            if (in_array($text, ['[', '('], true)) {
                if ($depth === 0) {
                    $open = $i;
                    break;
                }
                $depth--;

                continue;
            }
        }
        if ($open === null) {
            return null;
        }

        // 開き括弧の直前 (`[` なら さらにその前が `(`) から呼び出し名を取る
        $nameIndex = null;
        if ($tokens[$open]['text'] === '(') {
            $nameIndex = $open - 1;
        } elseif (($tokens[$open - 1]['text'] ?? null) === '(') {
            $nameIndex = $open - 2;
        }

        if ($nameIndex === null || ! isset($tokens[$nameIndex])) {
            // 呼び出しに包まれていない配列リテラル。Factory の definition()/state() の
            // `return [...]` はここに来る (Factory のときだけ書き込みとして扱う)。
            return $isFactory ? 'factory-definition' : null;
        }

        $name = $tokens[$nameIndex]['text'];

        return self::WRITE_CALLS[$name] ?? null;
    }

    /** ファイルが保存可能型 (`AssignableOrganizationSlug`) を参照しているか (FQCN 解決)。 */
    public static function sourceUsesAssignableType(string $source): bool
    {
        $fqcn = 'App\\Support\\Organization\\AssignableOrganizationSlug';
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
}
