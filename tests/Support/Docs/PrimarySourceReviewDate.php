<?php

declare(strict_types=1);

namespace Tests\Support\Docs;

use Carbon\CarbonImmutable;

/**
 * 対応ブラウザ方針の文書が持つ「一次情報の最終確認日」を読み、期限を判定する純粋関数群。
 *
 * 目的は「**実機再確認と一次情報の再確認が、未実施のまま忘れられるのを防ぐ**」ことである。
 * 判定を純粋関数として切り出しているのは、境界 (ちょうど 400 日前 / 401 日前 / 未来日) を
 * 文書を書き換えずにテストできるようにするためである。
 *
 * **保証しないもの**: 確認日は自己申告であり、**日付を新しくしても内容が正しいことは
 * 担保しない**。ここが担うのは「見直す機会を強制的に作る」ことだけである。
 */
final class PrimarySourceReviewDate
{
    /** 文書に 1 行だけ置く見出し語。 */
    public const LABEL = '一次情報の最終確認日';

    /** 確認日から基準日までの経過日数の上限 (これを超えたら赤)。 */
    public const MAX_AGE_DAYS = 400;

    /**
     * 本文から確認日の記述をすべて取り出す (行が 1 行だけであることは呼び出し側が確かめる)。
     *
     * 走査するのは「行頭が見出し語で始まる行」だけである。引用や説明文の中で見出し語に
     * 触れている行を拾わないため、行頭一致にしている。
     *
     * @return list<string> 見出し語の後ろに書かれていた値 (前後の空白は落とす)
     */
    public static function extractAll(string $contents): array
    {
        $found = [];
        foreach (preg_split('/\R/u', $contents) ?: [] as $line) {
            if (! str_starts_with($line, self::LABEL)) {
                continue;
            }
            $found[] = trim(mb_substr($line, mb_strlen(self::LABEL)), " \t:：");
        }

        return $found;
    }

    /**
     * 確認日の値を判定する。問題があれば理由を、無ければ null を返す。
     *
     * 基準日は呼び出し側が渡す (実行環境のタイムゾーンで境界が動かないよう、
     * 呼び出し側は UTC の今日を渡すこと)。
     */
    public static function problem(?string $value, CarbonImmutable $today): ?string
    {
        if ($value === null) {
            return '確認日の行が見つからない';
        }

        // 書式は YYYY-MM-DD のみ。他の書式は「読めない」として扱う。
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return "確認日 '{$value}' が YYYY-MM-DD の書式ではない";
        }

        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, 'UTC');
        if ($date === false || $date->format('Y-m-d') !== $value) {
            return "確認日 '{$value}' が実在する日付ではない";
        }

        if ($date->greaterThan($today)) {
            return "確認日 '{$value}' が未来になっている (記入ミス)";
        }

        // 双方 UTC の 0 時なので日数は整数になる (符号は上の未来判定で正に確定している)。
        $elapsed = (int) $date->diffInDays($today);
        if ($elapsed > self::MAX_AGE_DAYS) {
            return "確認日 '{$value}' から {$elapsed} 日が経過している (上限 ".self::MAX_AGE_DAYS.' 日)';
        }

        return null;
    }
}
