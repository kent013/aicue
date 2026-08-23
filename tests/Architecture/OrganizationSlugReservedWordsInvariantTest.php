<?php

declare(strict_types=1);

use App\Enums\Organization\SlugReservationReason;
use App\Support\Organization\OrganizationSlug;
use App\Support\Organization\OrganizationSlugReservedWords;
use Tests\Support\Architecture\OrganizationSlugRouteScanner;

/*
 * 識別名の位置に現れる固定セグメントは予約語に登録されている (家系裁定 AG-039 / 不変条件 I10)。
 *
 * 固定 route を足したのに予約語へ登録し忘れると、その語を識別名に持つ組織が
 * **URL 上で route に食われて到達不能**になる。宣言時点で落とす。
 *
 * ## 判定は「位置」で行う
 *
 * 識別名の位置 = `/organizations/` 直下の第 2 セグメント。
 * `settings` / `api-keys` / `invitations` は第 3 セグメント以降なので**対象外**である
 * (語の一致で拾うと正当な組織名が取れなくなる)。
 *
 * ## 保証しないもの (誇張しない)
 *
 * - `authority_impersonation` / `syntax_conflict` の語は route 表から導けない。
 *   **設定ファイルが唯一の正本**であり、機械が見るのは「分類が付いていること」だけである。
 * - 予約語を増やしたときに既存組織との衝突を検査する義務 (migration を同じ変更に含める) は
 *   **人がレビュー時に適用する運用契約**であり、機械では強制しない。
 *   正本は `config/organization-slug-reserved.php` の冒頭 docblock。
 */

test('識別名の位置 (第 2 セグメント) の静的語はすべて route_conflict として登録済み', function (): void {
    $reserved = OrganizationSlugReservedWords::load();

    $missing = [];
    $misclassified = [];
    foreach (OrganizationSlugRouteScanner::staticSecondSegments() as $segment) {
        $reason = $reserved->reservationFor(OrganizationSlug::fromString($segment));
        if ($reason === null) {
            $missing[] = $segment;

            continue;
        }
        if ($reason !== SlugReservationReason::RouteConflict) {
            $misclassified[] = "{$segment} => {$reason->value}";
        }
    }

    expect($missing)->toBe([]);
    expect($misclassified)->toBe([]);
});

test('走査根は空でない (第 2 セグメントの抽出が壊れたら赤にする)', function (): void {
    // `organizations/create` が実在するので静的語は最低 1 件ある。
    expect(OrganizationSlugRouteScanner::staticSecondSegments())->not->toBeEmpty();
});

test('負例: 登録の無い静的セグメントは検出される', function (): void {
    $reserved = OrganizationSlugReservedWords::load([
        'create' => SlugReservationReason::RouteConflict->value,
    ]);

    // 合成した「未登録の静的セグメント」は reservationFor が null を返す = 検出される
    expect($reserved->reservationFor(OrganizationSlug::fromString('unregistered-segment')))->toBeNull();
    // 正例: 登録済みは検出されない
    expect($reserved->reservationFor(OrganizationSlug::fromString('create')))
        ->toBe(SlugReservationReason::RouteConflict);
});
