<?php

declare(strict_types=1);

use App\Enums\Manual\VideoManualStatus;
use App\Enums\ProjectRole;
use App\Models\Category;
use App\Models\Project;
use App\Models\User;
use App\Models\VideoManual;
use App\Services\Manual\ManualRowAbilities;

/*
 * T182: ManualRowAbilities の**前提**を固定する。
 *
 * 前提: download / delete の可否は「その manual が属する project」で決まり、
 * manual 個別の属性 (status / 作成者 / カテゴリ) には依存しない。
 * よってページで 1 回だけ評価して全行へ配ってよい。
 *
 * **この前提が崩れる policy 変更をしたらこのテストが赤くなる**。そのときは
 * 可否の評価を行ループへ移し (同時に N+1 の解消も設計し直す)、
 * ManualRowAbilities の docblock と本テストを書き換えること。
 */

/**
 * 同一 project 配下に属性の異なる 3 行を作る (status / 作成者 / カテゴリが全部違う)。
 *
 * @return list<VideoManual>
 */
function manualRowsWithDifferingAttributes(Project $project, User $creator): array
{
    $category = Category::factory()->forProject($project)->create();
    $other = User::factory()->create();

    return [
        VideoManual::factory()->forProject($project)->createdBy($creator)->published(60_000)
            ->forCategory($category)->create(),
        VideoManual::factory()->forProject($project)->createdBy($other)->create([
            'status' => VideoManualStatus::Draft->value,
        ]),
        VideoManual::factory()->forProject($project)->createdBy($creator)->create([
            'status' => VideoManualStatus::Ready->value,
        ]),
    ];
}

test('代表行の可否は同一 project の全行を個別評価した結果と一致する (組織 owner)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $manuals = manualRowsWithDifferingAttributes($project, $owner);

    $abilities = ManualRowAbilities::forPage($owner, $project, $manuals);

    expect($abilities->canDownload)->toBeTrue();
    expect($abilities->canDelete)->toBeTrue();
    foreach ($manuals as $manual) {
        expect($owner->can('download', $manual))->toBe($abilities->canDownload);
        expect($owner->can('delete', $manual))->toBe($abilities->canDelete);
    }
});

test('撮影者は全行で両方 false、編集者は全行で両方 true (行ごとの実評価と一致)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();

    $shooter = attachOrganizationMember($organization);
    $shooter->forceFill(['current_organization_id' => $organization->id])->save();
    attachProjectMember($project, $shooter, ProjectRole::Member);

    $editor = attachOrganizationMember($organization);
    $editor->forceFill(['current_organization_id' => $organization->id])->save();
    attachProjectMember($project, $editor, ProjectRole::Admin);

    $manuals = manualRowsWithDifferingAttributes($project, $owner);

    $shooterAbilities = ManualRowAbilities::forPage($shooter, $project, $manuals);
    expect($shooterAbilities->canDownload)->toBeFalse();
    expect($shooterAbilities->canDelete)->toBeFalse();

    $editorAbilities = ManualRowAbilities::forPage($editor, $project, $manuals);
    expect($editorAbilities->canDownload)->toBeTrue();
    expect($editorAbilities->canDelete)->toBeTrue();

    foreach ($manuals as $manual) {
        expect($shooter->can('download', $manual))->toBeFalse();
        expect($shooter->can('delete', $manual))->toBeFalse();
        expect($editor->can('download', $manual))->toBeTrue();
        expect($editor->can('delete', $manual))->toBeTrue();
    }
});

test('行が 1 件も無いページでは両方 false (評価しない)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();

    $abilities = ManualRowAbilities::forPage($owner, $project, []);

    expect($abilities->canDownload)->toBeFalse();
    expect($abilities->canDelete)->toBeFalse();
});
