<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Support\Organization\OrganizationSlug;
use App\Support\Organization\OrganizationSlugConstraintViolation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * DB 側の CHECK / UNIQUE が実際に効く (家系裁定 AG-039b / AG-039c)。
 *
 * ★**値オブジェクトを迂回することが検査の目的そのもの**である。Factory で正常な組織を作り、
 *   クエリビルダの直接 UPDATE で不正値を撃つ (`DB::table()->insert()` の直組みは使わない —
 *   他の必須列制約が先に発火して CHECK を検証できないため)。
 * ★この迂回は `OrganizationSlugWriteExemptions` に rule ID `query-builder-update` /
 *   件数 4 で登録済みである。
 */

/** @param non-empty-string $slug */
function updateSlugBypassingValueObject(Organization $organization, string $slug): void
{
    DB::table('organizations')->whereKey($organization->getKey())->update(['slug' => $slug]);
}

test('CHECK: 大文字は保存できない (正規化を迂回した値を DB が拒否する)', function (): void {
    [$organization] = createOrganizationWithOwner();

    expect(fn () => updateSlugBypassingValueObject($organization, 'Acme'))
        ->toThrow(QueryException::class);
});

test('CHECK: 連続ハイフンは保存できない', function (): void {
    [$organization] = createOrganizationWithOwner();

    expect(fn () => updateSlugBypassingValueObject($organization, 'ac--me'))
        ->toThrow(QueryException::class);
});

test('CHECK: 先頭ハイフンは保存できない', function (): void {
    [$organization] = createOrganizationWithOwner();

    expect(fn () => updateSlugBypassingValueObject($organization, '-acme'))
        ->toThrow(QueryException::class);
});

test('CHECK: 上限超過 (256 文字) は保存できない', function (): void {
    [$organization] = createOrganizationWithOwner();
    $tooLong = str_repeat('a', OrganizationSlug::MAX_LENGTH + 1);

    expect(fn () => updateSlugBypassingValueObject($organization, $tooLong))
        ->toThrow(QueryException::class);
});

test('一意制約違反は organizations_slug_unique として識別される', function (): void {
    [$first] = createOrganizationWithOwner('先着');
    [$second] = createOrganizationWithOwner('後着');

    try {
        $second->forceFill(['slug' => $first->slug])->save();
        $this->fail('一意制約に当たらなかった');
    } catch (QueryException $e) {
        expect(OrganizationSlugConstraintViolation::isSlugTaken($e))->toBeTrue();
    }
});

test('別の一意違反は「識別名が使われている」に化けない (SQLSTATE だけで判定しない)', function (): void {
    [$first] = createOrganizationWithOwner('先着');
    [$second] = createOrganizationWithOwner('後着');

    try {
        // laratrust_team_id の一意違反 (識別名とは無関係の 23505)
        $second->forceFill(['laratrust_team_id' => $first->laratrust_team_id])->save();
        $this->fail('一意制約に当たらなかった');
    } catch (QueryException $e) {
        expect(OrganizationSlugConstraintViolation::isSlugTaken($e))->toBeFalse();
    }
});
