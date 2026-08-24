<?php

declare(strict_types=1);

namespace Tests\Support\Architecture;

use Tests\Support\PhpTokenScan;

/**
 * 初期組織生成 (`provisionInitialOrganization`) の呼び出しサイトと、行ロック構造の走査器。
 *
 * ★見るのは**字句として現れた呼び出し**だけである。可変メソッド名・コンテナ経由の
 *   動的呼び出しは**保証範囲の外**であり、その構文について検出力を主張しない。
 * ★宣言 (`function provisionInitialOrganization(`) は呼び出しサイトに数えない。
 * ★「ロックの後に数える」判定は**トークン順**で行う (実行順ではない)。
 */
final class ProvisioningCallSiteScanner
{
    private const string METHOD = 'provisionInitialOrganization';

    /** ロック取得の呼び名。 */
    private const string LOCK = 'lockForUpdate';

    /** 所属を数える relation の呼び名。 */
    private const string MEMBERSHIP = 'organizations';

    /**
     * @param  list<array{absolute: string, relative: string}>  $files
     * @return list<string> relative パスの昇順 (重複なし)
     */
    public static function callSites(array $files): array
    {
        $hits = [];
        foreach ($files as $file) {
            $source = (string) file_get_contents($file['absolute']);
            if (self::sourceCallsProvisioning($source)) {
                $hits[] = $file['relative'];
            }
        }
        sort($hits);

        return array_values(array_unique($hits));
    }

    /** `->provisionInitialOrganization(` / `::provisionInitialOrganization(` の呼び出しがあるか。 */
    public static function sourceCallsProvisioning(string $source): bool
    {
        $tokens = PhpTokenScan::normalize($source);
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            if ($tokens[$i]['id'] !== T_STRING || $tokens[$i]['text'] !== self::METHOD) {
                continue;
            }
            $previous = $tokens[$i - 1] ?? null;
            $next = $tokens[$i + 1] ?? null;
            if ($next === null || $next['text'] !== '(') {
                continue;
            }
            if ($previous === null) {
                continue;
            }
            if (in_array($previous['id'], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 「行ロックを取り、**その後に**所属を数えている」か。
     *
     * `provisionInitialOrganization` の宣言以降のトークン列で、`lockForUpdate` の出現位置が
     * `organizations` relation の出現位置より前にあることを見る。
     */
    public static function locksBeforeCounting(string $source): bool
    {
        $tokens = PhpTokenScan::normalize($source);
        $count = count($tokens);

        $start = null;
        for ($i = 0; $i < $count; $i++) {
            if ($tokens[$i]['id'] === T_STRING
                && $tokens[$i]['text'] === self::METHOD
                && ($tokens[$i - 1]['id'] ?? null) === T_FUNCTION) {
                $start = $i;
                break;
            }
        }
        if ($start === null) {
            return false;
        }

        $lockAt = null;
        $countAt = null;
        for ($i = $start; $i < $count; $i++) {
            $text = $tokens[$i]['text'];
            if ($tokens[$i]['id'] !== T_STRING) {
                continue;
            }
            if ($lockAt === null && $text === self::LOCK) {
                $lockAt = $i;
            }
            if ($countAt === null && $text === self::MEMBERSHIP
                && ($tokens[$i + 1]['text'] ?? null) === '('
                && in_array($tokens[$i - 1]['id'] ?? null, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)) {
                $countAt = $i;
            }
        }

        return $lockAt !== null && $countAt !== null && $lockAt < $countAt;
    }
}
