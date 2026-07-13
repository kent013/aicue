<?php

declare(strict_types=1);

use App\Enums\ProjectRole;
use App\Models\Project;
use Illuminate\Support\Collection;
use Inertia\Testing\AssertableInertia;

/*
 * Projects/Show の assignableUsers prop 契約 (メンバー追加フォームの候補)。
 *
 * 権限根拠の単一化: project メンバー管理の認可根拠は一貫して can('update', $project) = canManage
 * であり canManageMembers (org レベルのユーザー管理) ではない。assignableUsers のゲート・
 * メンバー管理 Card の表示・PII (name) 開示はすべて canManage を単一根拠にする。
 *
 * - assignableUsers は id/name のみ (PII 最小)。
 * - 候補 = current org のメンバーのうち members (明示・暗黙) に含まれない者。
 * - canManage=false の閲覧者には [] (name も PII のため payload 生成時点で絞る)。
 *
 * store/destroy の挙動は ProjectMemberTest が網羅済みのため本ファイルでは重複追加しない。
 */

/**
 * assignableUsers prop を配列に正規化する。
 *
 * @return list<array{id: int, name: string}>
 */
function assignableRows(mixed $rows): array
{
    /** @var list<array{id: int, name: string}> */
    return $rows instanceof Collection ? $rows->toArray() : (array) $rows;
}

it('assignableUsers は id/name のみで既存メンバー(明示・暗黙)と他組織を除外する', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();          // owner = 暗黙メンバー
    $assigned = attachOrganizationMember($organization);             // 明示メンバーにする
    $free = attachOrganizationMember($organization);                 // 候補に出るべき
    [, $outsider] = createOrganizationWithOwner('組織B');            // 他組織 = 出ない
    $project = Project::factory()->forOrganization($organization)->create();
    attachProjectMember($project, $assigned, ProjectRole::Member);

    $response = $this->actingAs($owner)->get("/projects/{$project->id}");

    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
        ->component('Projects/Show')
        ->has('assignableUsers')
        ->where('assignableUsers', function (mixed $rows) use ($free, $assigned, $owner, $outsider): bool {
            $list = assignableRows($rows);

            // shape 固定: 各行は id/name のみ (email 等の余剰キーを含まない = PII 最小)
            foreach ($list as $row) {
                expect(array_keys($row))->toEqualCanonicalizing(['id', 'name']);
            }

            $ids = array_column($list, 'id');

            return in_array($free->id, $ids, true)          // 未所属の org member は候補
                && ! in_array($assigned->id, $ids, true)    // 明示メンバー除外
                && ! in_array($owner->id, $ids, true)       // 暗黙メンバー (org owner) 除外
                && ! in_array($outsider->id, $ids, true);   // 他組織除外
        })
    );
});

it('canManage=false の閲覧者には assignableUsers=[] かつ member email も null', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $viewer = attachOrganizationMember($organization);
    $viewer->forceFill(['current_organization_id' => $organization->id])->save();
    // 未所属の org member。canManage=true なら候補に出るため、[] が空虚な真でないことを担保する。
    attachOrganizationMember($organization);
    $project = Project::factory()->forOrganization($organization)->create();
    // viewer は project_member (閲覧のみ = can('update') 不可)。owner は暗黙メンバー。
    attachProjectMember($project, $viewer, ProjectRole::Member);

    $response = $this->actingAs($viewer)->get("/projects/{$project->id}");

    $response->assertOk();
    $response->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
        ->component('Projects/Show')
        ->where('canManage', false)
        ->where('canViewMemberEmails', false)
        // PII 二重ゲート: 候補 name も閲覧者に漏らさない
        ->where('assignableUsers', fn (mixed $rows): bool => assignableRows($rows) === [])
        // members の全行で email が null (実装が一部行だけ秘匿する退行を検知)
        ->where('members', function (mixed $members): bool {
            $list = $members instanceof Collection ? $members->toArray() : (array) $members;

            foreach ($list as $row) {
                expect($row)->toHaveKey('email');
                if ($row['email'] !== null) {
                    return false;
                }
            }

            return $list !== [];
        })
    );
});
