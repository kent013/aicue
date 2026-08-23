<?php

declare(strict_types=1);

namespace App\Support\Organization;

use Illuminate\Database\QueryException;

/**
 * 一意制約違反を **制約名まで**識別する。SQLSTATE 23505 だけで判定すると、
 * laratrust_team_id 等の別の一意違反まで「識別名が使われている」に化ける。
 *
 * ★識別できない違反は隠さず再送出する (呼び出し側が false を受けたら throw する)。
 * ★**保証範囲**: PostgreSQL の制約名が例外メッセージに現れることに依存する。
 *   別 RDBMS ではこの判定は成立しない (本アプリは PostgreSQL 固定)。
 */
final class OrganizationSlugConstraintViolation
{
    public const string SLUG_UNIQUE = 'organizations_slug_unique';

    /** PostgreSQL の unique_violation。 */
    private const string SQLSTATE_UNIQUE_VIOLATION = '23505';

    public static function isSlugTaken(QueryException $e): bool
    {
        if (($e->errorInfo[0] ?? null) !== self::SQLSTATE_UNIQUE_VIOLATION) {
            return false;
        }

        return str_contains($e->getMessage(), self::SLUG_UNIQUE);
    }
}
