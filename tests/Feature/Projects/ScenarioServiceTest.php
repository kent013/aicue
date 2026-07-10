<?php

declare(strict_types=1);

use App\DataTransferObjects\Manual\ScenarioPointInput;
use App\DataTransferObjects\Manual\ScenarioSaveInput;
use App\DataTransferObjects\Manual\ScenarioStepInput;
use App\Enums\Manual\ShotType;
use App\Models\Cut;
use App\Models\Project;
use App\Models\VideoManual;
use App\Services\Manual\ScenarioService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

/*
 * ScenarioService の境界防御 (route binding とは別レイヤ)。
 * assertPayloadIds の中核 (重複 422 / 異物 404 / 階層・型不一致 422) と
 * cross-project 拒否を Service 直叩きで固定する (すべて DB 不変を併せて検証)。
 * ManualServiceBoundaryTest と同じ流儀 (reconcile 検証中心のため専用ファイル)。
 */

/**
 * step 1 行の入力 DTO を組む (points は引数で付ける)。
 *
 * @param  list<ScenarioPointInput>  $points
 */
function scenarioStepInput(?int $id = null, array $points = [], string $scene = '手順シーン'): ScenarioStepInput
{
    return new ScenarioStepInput(
        id: $id,
        scene: $scene,
        shotType: ShotType::Hiki,
        shootingPoint: null,
        narration: 'ナレーション',
        subtitlePrimary: null,
        subtitleSecondary: '字幕',
        materialType: null,
        staticDisplaySeconds: null,
        points: $points,
    );
}

/** point 1 行の入力 DTO を組む。 */
function scenarioPointInput(?int $id = null, string $scene = '急所シーン'): ScenarioPointInput
{
    return new ScenarioPointInput(
        id: $id,
        scene: $scene,
        shotType: ShotType::Yori,
        shootingPoint: null,
        narration: 'ナレーション',
        subtitlePrimary: null,
        subtitleSecondary: '字幕',
        materialType: null,
        staticDisplaySeconds: null,
    );
}

test('ScenarioService::save は cross-project の VideoManual を拒否し DB を変更しない', function (): void {
    [$organization] = createOrganizationWithOwner();
    $projectA = Project::factory()->forOrganization($organization)->create();
    $projectB = Project::factory()->forOrganization($organization)->create();
    $manualB = VideoManual::factory()->forProject($projectB)->create();
    $countBefore = Cut::query()->count();

    expect(fn () => app(ScenarioService::class)->save(
        $projectA,
        $manualB,
        new ScenarioSaveInput(0, [scenarioStepInput()]),
    ))->toThrow(ModelNotFoundException::class);

    expect($manualB->refresh()->scenario_version)->toBe(0);
    expect(Cut::query()->count())->toBe($countBefore);
});

test('ScenarioService::save は payload 内 id 重複を 422 相当で拒否し DB を変更しない', function (): void {
    [$organization] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $manual = VideoManual::factory()->forProject($project)->create();
    $step = Cut::factory()->forManual($manual)->create(['scene' => '元のシーン']);

    expect(fn () => app(ScenarioService::class)->save(
        $project,
        $manual,
        new ScenarioSaveInput(0, [
            scenarioStepInput($step->id, scene: '改竄1'),
            scenarioStepInput($step->id, scene: '改竄2'),
        ]),
    ))->toThrow(ValidationException::class);

    expect($step->refresh()->scene)->toBe('元のシーン');
    expect($manual->refresh()->scenario_version)->toBe(0);
});

test('ScenarioService::save は他 manual の cut id 混入を 404 で拒否し DB を変更しない', function (): void {
    [$organization] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $manual = VideoManual::factory()->forProject($project)->create();
    $otherManual = VideoManual::factory()->forProject($project)->create();
    $foreignCut = Cut::factory()->forManual($otherManual)->create(['scene' => '元のシーン']);

    expect(fn () => app(ScenarioService::class)->save(
        $project,
        $manual,
        new ScenarioSaveInput(0, [scenarioStepInput($foreignCut->id, scene: '改竄')]),
    ))->toThrow(ModelNotFoundException::class);

    expect($foreignCut->refresh()->scene)->toBe('元のシーン');
    expect($manual->refresh()->scenario_version)->toBe(0);
    expect($manual->cuts()->count())->toBe(0);
});

test('ScenarioService::save は既存 step の id を point 位置に置く降格を 422 相当で拒否する', function (): void {
    [$organization] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $manual = VideoManual::factory()->forProject($project)->create();
    $step = Cut::factory()->forManual($manual)->create();
    $countBefore = Cut::query()->count();

    expect(fn () => app(ScenarioService::class)->save(
        $project,
        $manual,
        new ScenarioSaveInput(0, [
            scenarioStepInput(points: [scenarioPointInput($step->id)]),
        ]),
    ))->toThrow(ValidationException::class);

    expect($step->refresh()->parent_cut_id)->toBeNull();
    expect(Cut::query()->count())->toBe($countBefore);
    expect($manual->refresh()->scenario_version)->toBe(0);
});

test('ScenarioService::save は既存 point の id を step 位置に置く昇格を 422 相当で拒否する', function (): void {
    [$organization] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $manual = VideoManual::factory()->forProject($project)->create();
    $step = Cut::factory()->forManual($manual)->create();
    $point = Cut::factory()->asPointOf($step)->create();
    $countBefore = Cut::query()->count();

    expect(fn () => app(ScenarioService::class)->save(
        $project,
        $manual,
        new ScenarioSaveInput(0, [
            scenarioStepInput($step->id, points: []),
            scenarioStepInput($point->id),
        ]),
    ))->toThrow(ValidationException::class);

    expect($point->refresh()->parent_cut_id)->toBe($step->id);
    expect(Cut::query()->count())->toBe($countBefore);
    expect($manual->refresh()->scenario_version)->toBe(0);
});
