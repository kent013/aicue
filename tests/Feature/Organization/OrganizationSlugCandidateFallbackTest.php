<?php

declare(strict_types=1);

use App\Exceptions\Organization\OrganizationSlugTakenException;
use App\Models\CustomTeam;
use App\Models\Organization;
use App\Models\Team;
use App\Models\User;
use App\Services\Organization\OrganizationProvisioningService;

/*
 * 一意衝突したときの候補の遷移 (家系裁定 AG-039)。
 *
 * | 由来 | 衝突したときの遷移 |
 * |---|---|
 * | Requested (利用者が明示) | 即 422 (代替を作らない) |
 * | Derived (組織名から導出) | Fallback へ 1 回だけ遷移 (同じ導出値を繰り返さない) |
 * | Fallback (`org-{乱数}`) | 新しい乱数で最大 3 回 |
 *
 * ★PostgreSQL では一意制約違反でトランザクションが中断するため、
 *   **1 試行 = 1 transaction 境界**にしてある。失敗試行の書き込み
 *   (Team / Default Team / role 付与) が残らないことを固定する。
 */

test('導出値が使用済みなら fallback で成功する (利用者にはエラーを見せない)', function (): void {
    // 導出値と同じ識別名を先に押さえる。指定値も**保存可能型を通す** Factory の state を使う
    // (テスト側で forceFill すると保存経路 1 本の不変条件がテストから崩れる)
    Organization::factory()->withSlug('acme-corp')->create();

    $user = User::factory()->create();
    $organization = app(OrganizationProvisioningService::class)->provision($user, 'Acme Corp');

    expect($organization->slug)->not->toBe('acme-corp');
    expect($organization->slug)->toStartWith('org-');
});

test('失敗した試行の Team / Default Team / role 付与が残らない', function (): void {
    // 導出値と同じ識別名を先に押さえる。指定値も**保存可能型を通す** Factory の state を使う
    // (テスト側で forceFill すると保存経路 1 本の不変条件がテストから崩れる)
    Organization::factory()->withSlug('acme-corp')->create();

    $teamsBefore = Team::query()->count();
    $customTeamsBefore = CustomTeam::query()->count();

    $user = User::factory()->create();
    $organization = app(OrganizationProvisioningService::class)->provision($user, 'Acme Corp');

    // 成功した 1 試行ぶんだけが増える (失敗試行は transaction 境界で巻き戻る)
    expect(Team::query()->count())->toBe($teamsBefore + 1);
    expect(CustomTeam::query()->count())->toBe($customTeamsBefore + 1);
    expect($organization->customTeams()->where('is_default', true)->count())->toBe(1);
});

test('権限 cache にも残留が無い (save 成功後に addRole する順序契約)', function (): void {
    // 導出値と同じ識別名を先に押さえる。指定値も**保存可能型を通す** Factory の state を使う
    // (テスト側で forceFill すると保存経路 1 本の不変条件がテストから崩れる)
    Organization::factory()->withSlug('acme-corp')->create();

    $user = User::factory()->create();
    $organization = app(OrganizationProvisioningService::class)->provision($user, 'Acme Corp');

    // 成功した組織にだけ Owner が付く。失敗試行の team には role_user 行が無い
    expect($user->fresh()?->organizationRole($organization)?->value)->toBe('organization_owner');
    $orphanTeamIds = Team::query()
        ->whereNotIn('id', Organization::query()->pluck('laratrust_team_id'))
        ->pluck('id');
    foreach ($orphanTeamIds as $teamId) {
        $this->assertDatabaseMissing('role_user', ['user_id' => $user->id, 'team_id' => $teamId]);
    }
});

test('利用者が明示した識別名の衝突は代替を作らず例外になる', function (): void {
    [$existing] = createOrganizationWithOwner('先着');
    $user = User::factory()->create();

    expect(fn (): Organization => app(OrganizationProvisioningService::class)
        ->provision($user, 'テスト組織', $existing->slug))
        ->toThrow(OrganizationSlugTakenException::class);

    expect($user->organizations()->count())->toBe(0);
});

test('別の一意違反は再送出される (識別名の衝突に化けない)', function (): void {
    [$existing] = createOrganizationWithOwner('先着');
    $user = User::factory()->create();

    // laratrust_team_id を先着と同じにする細工はできないため、
    // 「識別名以外の一意違反は isSlugTaken=false で再送出される」ことは
    // OrganizationSlugConstraintTest が制約名の識別で固定している。
    // ここでは正常系が壊れていないことだけを確認する。
    // 日本語名は Str::slug が空を返すので fallback (org-{乱数}) へ倒れる
    expect(app(OrganizationProvisioningService::class)->provision($user, '別の組織')->slug)
        ->toStartWith('org-');
    expect($existing->fresh())->not->toBeNull();
});
