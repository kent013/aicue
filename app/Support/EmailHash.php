<?php

declare(strict_types=1);

namespace App\Support;

use Webmozart\Assert\Assert;

/**
 * email の keyed hash (HMAC-SHA256) 算出 helper。
 *
 * 単純 sha256 は辞書攻撃に弱いため、ログ・補助検索用には HMAC(app.key) で keyed hash を作る。
 * 平文 email をログに出さないための識別子として使う。
 *
 * 正規化の正本は EmailNormalizer である。本クラス内の mb_strtolower(trim(...)) は
 * 呼び出し漏れに対する防御的な再適用であり、canonical 化の定義を持つものではない。
 *
 * 制約: APP_KEY ローテーション時、前後の hash は突合不可になる。
 */
final class EmailHash
{
    public static function compute(string $email): string
    {
        $key = config('app.key');
        Assert::string($key);

        return hash_hmac('sha256', mb_strtolower(trim($email)), $key);
    }
}
