<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\SecurityAuditEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/*
 * T203: /manage/users の最終ログイン表示が**行ごとのクエリを撃たない**ことを固定する。
 *
 * 守るのは 1 点だけ — App\Services\Security\LastLoginLookup が id 集合に対して
 * security_audit_events を **1 回だけ**引くこと。誰かが行ごとに呼ぶ形へ戻したら赤くなる。
 *
 * **総クエリ数の同値では測らない**。この画面は Laratrust のロール判定を利用者ごとに行うため
 * 総数はもともと行数に比例しており (実測)、総数で測ると「最終ログインが N+1 になった」以外の
 * 理由で赤/緑が動いて検査の意味が薄れる。よって導出元の表を名指しで数える
 * (tests/Feature/Capture/CaptureManualListQueryCountTest.php は総数で測れる画面なので
 *  流儀が違う。ここで同じ形にすると嘘の保証になる)。
 *
 * 計測は「GET 1 回ぶん」に限り、fixture 生成は flushQueryLog で計測外にする。
 * 初回リクエスト固有の初期化を混ぜないよう、計測前に暖機の GET を 1 回撃つ。
 * 比較は**同一利用者 (owner) の行数違い**でのみ行い、メンバー数以外の条件
 * (招待 0 件 / Default Project 不在 / 追加メンバーのロールは一律 Member /
 *  全員が login 行を 1 本持つ) は両ケースで揃える。
 */

/** 組織へ Member を n 人足し、それぞれに login 行を 1 本ずつ持たせる */
function addMembersWithLoginRow(Organization $organization, int $count): void
{
    foreach (range(1, $count) as $ignored) {
        $member = attachOrganizationMember($organization, OrganizationRole::Member);
        SecurityAuditEvent::factory()->forUser($member)->occurredAt(CarbonImmutable::now()->subDay())->create();
    }
}

/**
 * /manage/users を 1 回開き、その間に実行された SQL を返す。
 *
 * @return list<string>
 */
function measureUserManagementQueries(Organization $organization, User $owner): array
{
    DB::enableQueryLog();
    DB::flushQueryLog();
    test()->actingAs($owner)->get("/organizations/{$organization->slug}/manage/users")->assertOk();
    $log = DB::getQueryLog();
    DB::disableQueryLog();

    return array_map(static fn (array $entry): string => (string) $entry['query'], $log);
}

/**
 * security_audit_events に触れたクエリだけを取り出す。
 *
 * @param  list<string>  $queries
 * @return list<string>
 */
function securityAuditEventQueries(array $queries): array
{
    return array_values(array_filter(
        $queries,
        static fn (string $query): bool => str_contains($query, 'security_audit_events'),
    ));
}

test('最終ログインの導出クエリはメンバー数に依らず 1 本 (行ごとに引かない)', function (): void {
    [$smallOrganization, $smallOwner] = createOrganizationWithOwner('メンバー 1 人の組織');
    SecurityAuditEvent::factory()->forUser($smallOwner)->occurredAt(CarbonImmutable::now()->subDay())->create();

    [$largeOrganization, $largeOwner] = createOrganizationWithOwner('メンバー 10 人の組織');
    SecurityAuditEvent::factory()->forUser($largeOwner)->occurredAt(CarbonImmutable::now()->subDay())->create();
    addMembersWithLoginRow($largeOrganization, 9);

    // 招待 0 件 / Default Project 不在 は両ケースの既定 (createOrganizationWithOwner の初期状態)
    expect($smallOrganization->invitations()->count())->toBe(0);
    expect($largeOrganization->invitations()->count())->toBe(0);

    measureUserManagementQueries($smallOrganization, $smallOwner); // 暖機
    measureUserManagementQueries($largeOrganization, $largeOwner); // 暖機

    $small = securityAuditEventQueries(measureUserManagementQueries($smallOrganization, $smallOwner));
    $large = securityAuditEventQueries(measureUserManagementQueries($largeOrganization, $largeOwner));

    expect($small)->toHaveCount(1);
    expect($large)->toHaveCount(
        1,
        '最終ログインの導出が行ごとのクエリになりました (10 人の組織で '
        .count($large)." 本)。\n".implode("\n", $large)
    );
});
