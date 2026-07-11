<?php

declare(strict_types=1);

use App\Enums\AdminConsoleRole;
use App\Enums\MemberRoleState;
use App\Enums\OrganizationRole;
use App\Enums\ProjectRole;

/*
 * ユーザー管理画面の表示状態 (5 値) の導出と遷移コマンドのマッピング (概念設計 D2)。
 * 全組合せを漏れなく分類する canonical mapping を固定する。
 */

test('derive() は org ロール × project pivot の全組合せを 5 値へ分類する', function (
    ?OrganizationRole $orgRole,
    ?ProjectRole $projectRole,
    MemberRoleState $expected,
): void {
    expect(MemberRoleState::derive($orgRole, $projectRole))->toBe($expected);
})->with([
    'Owner × pivot なし' => [OrganizationRole::Owner, null, MemberRoleState::Owner],
    'Member × project_admin = 編集者' => [OrganizationRole::Member, ProjectRole::Admin, MemberRoleState::Editor],
    'Member × project_member = 撮影者' => [OrganizationRole::Member, ProjectRole::Member, MemberRoleState::Shooter],
    'Member × pivot なし = 未割当' => [OrganizationRole::Member, null, MemberRoleState::Unassigned],
    'Admin × pivot なし' => [OrganizationRole::Admin, null, MemberRoleState::Admin],
]);

test('owner は project pivot が残っていても管理者(オーナー)として表示する (stale pivot 無視)', function (): void {
    expect(MemberRoleState::derive(OrganizationRole::Owner, ProjectRole::Admin))->toBe(MemberRoleState::Owner);
    expect(MemberRoleState::derive(OrganizationRole::Owner, ProjectRole::Member))->toBe(MemberRoleState::Owner);
});

test('admin は project pivot が残っていても管理者として表示する (stale pivot 無視)', function (): void {
    expect(MemberRoleState::derive(OrganizationRole::Admin, ProjectRole::Admin))->toBe(MemberRoleState::Admin);
    expect(MemberRoleState::derive(OrganizationRole::Admin, ProjectRole::Member))->toBe(MemberRoleState::Admin);
});

test('org ロール null (Laratrust ロール未付与の異常行) は pivot が残っていても未割当へ丸める', function (): void {
    // null 判定は pivot 判定より先 (stale pivot が編集者/撮影者と誤表示され修復契約と食い違うのを防ぐ)
    expect(MemberRoleState::derive(null, null))->toBe(MemberRoleState::Unassigned);
    expect(MemberRoleState::derive(null, ProjectRole::Admin))->toBe(MemberRoleState::Unassigned);
    expect(MemberRoleState::derive(null, ProjectRole::Member))->toBe(MemberRoleState::Unassigned);
});

test('AdminConsoleRole のコマンド → 既存プリミティブへのマッピング', function (): void {
    expect(AdminConsoleRole::Admin->organizationRole())->toBe(OrganizationRole::Admin);
    expect(AdminConsoleRole::Editor->organizationRole())->toBe(OrganizationRole::Member);
    expect(AdminConsoleRole::Shooter->organizationRole())->toBe(OrganizationRole::Member);

    expect(AdminConsoleRole::Admin->projectRole())->toBeNull();
    expect(AdminConsoleRole::Editor->projectRole())->toBe(ProjectRole::Admin);
    expect(AdminConsoleRole::Shooter->projectRole())->toBe(ProjectRole::Member);
});

test('AdminConsoleRole に owner は存在しない (Owner 昇格は transferOwnership のみの型表現)', function (): void {
    expect(array_column(AdminConsoleRole::cases(), 'value'))->toBe(['admin', 'editor', 'shooter']);
    expect(AdminConsoleRole::tryFrom('owner'))->toBeNull();
});

test('label はドメイン表示名 (doc/10 §10.5) を返す', function (): void {
    expect(AdminConsoleRole::Admin->label())->toBe('管理者');
    expect(AdminConsoleRole::Editor->label())->toBe('編集者');
    expect(AdminConsoleRole::Shooter->label())->toBe('撮影者');
    expect(MemberRoleState::Owner->label())->toBe('管理者（オーナー）');
    expect(MemberRoleState::Unassigned->label())->toBe('未割当');
});
