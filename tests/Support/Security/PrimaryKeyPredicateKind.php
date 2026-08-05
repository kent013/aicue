<?php

declare(strict_types=1);

namespace Tests\Support\Security;

/**
 * 主キー述語の種別。
 *
 * `findMany($ids)` と `findOrFail($id)` を同じ扱いにすると、
 * identity 引数を単数前提で判定する副条件 (provenance 除外など) が破綻するため分けている。
 */
enum PrimaryKeyPredicateKind
{
    /** `find` / `findOrFail` / `findOrNew` / `whereKey` / `where('id', …)` / `firstWhere('id', …)` */
    case SingleIdentity;

    /** `findMany` / `whereIn('id', …)` / `where('id', 'in', …)` */
    case MultiIdentity;

    /** `whereKeyNot` — 「同一性」ではなく除外条件 (列挙ベクタになりうる) */
    case IdentityExclusion;

    /** `destroy` — 取得ではなく削除 */
    case DestructiveIdentity;
}
