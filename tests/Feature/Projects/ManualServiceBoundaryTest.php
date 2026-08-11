<?php

declare(strict_types=1);

use App\Enums\Manual\VideoManualStatus;
use App\Models\Category;
use App\Models\Project;
use App\Models\VideoManual;
use App\Services\Manual\CategoryService;
use App\Services\Manual\VideoManualService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/*
 * Service 境界防御 (route binding とは別レイヤ)。
 * 全メソッドは Project 行ロック取得後に対象の子を親 relation から再解決するため、
 * 別 Service・将来のバッチ等から cross-project の子を渡されても
 * ModelNotFoundException (→404) で拒否し、DB を一切変更しない。
 */

test('CategoryService::update は cross-project の Category を拒否し DB を変更しない', function (): void {
    [$organization] = createOrganizationWithOwner();
    $projectA = Project::factory()->forOrganization($organization)->create();
    $projectB = Project::factory()->forOrganization($organization)->create();
    $categoryB = Category::factory()->forProject($projectB)->create(['name' => '元の名前']);
    $countBefore = Category::query()->count();

    expect(fn () => app(CategoryService::class)->update($projectA, $categoryB, '改竄名'))
        ->toThrow(ModelNotFoundException::class);

    expect($categoryB->fresh()?->name)->toBe('元の名前');
    expect(Category::query()->count())->toBe($countBefore);
});

test('CategoryService::delete は cross-project の Category を拒否し DB を変更しない', function (): void {
    [$organization] = createOrganizationWithOwner();
    $projectA = Project::factory()->forOrganization($organization)->create();
    $projectB = Project::factory()->forOrganization($organization)->create();
    $categoryB = Category::factory()->forProject($projectB)->create();
    $countBefore = Category::query()->count();

    expect(fn () => app(CategoryService::class)->delete($projectA, $categoryB))
        ->toThrow(ModelNotFoundException::class);

    expect(Category::query()->whereKey($categoryB->id)->exists())->toBeTrue();
    expect(Category::query()->count())->toBe($countBefore);
});

test('VideoManualService::updateMeta は cross-project の VideoManual を拒否し DB を変更しない', function (): void {
    [$organization] = createOrganizationWithOwner();
    $projectA = Project::factory()->forOrganization($organization)->create();
    $projectB = Project::factory()->forOrganization($organization)->create();
    $manualB = VideoManual::factory()->forProject($projectB)->create(['title' => '元のタイトル']);
    $countBefore = VideoManual::query()->count();

    expect(fn () => app(VideoManualService::class)->updateMeta($projectA, $manualB, '改竄タイトル', null))
        ->toThrow(ModelNotFoundException::class);

    expect($manualB->fresh()?->title)->toBe('元のタイトル');
    expect(VideoManual::query()->count())->toBe($countBefore);
});

test('VideoManualService::delete は cross-project の VideoManual を拒否し DB を変更しない', function (): void {
    [$organization] = createOrganizationWithOwner();
    $projectA = Project::factory()->forOrganization($organization)->create();
    $projectB = Project::factory()->forOrganization($organization)->create();
    $manualB = VideoManual::factory()->forProject($projectB)->create();
    $countBefore = VideoManual::query()->count();

    expect(fn () => app(VideoManualService::class)->delete($projectA, $manualB))
        ->toThrow(ModelNotFoundException::class);

    expect(VideoManual::query()->whereKey($manualB->id)->exists())->toBeTrue();
    expect(VideoManual::query()->count())->toBe($countBefore);
});

test('VideoManualService::create は他 project の categoryId を拒否し manual を残さない', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $projectA = Project::factory()->forOrganization($organization)->create();
    $projectB = Project::factory()->forOrganization($organization)->create();
    $categoryB = Category::factory()->forProject($projectB)->create();

    // FormRequest の exists をすり抜けても、保存時再解決 (二段目) が transaction ごと巻き戻す
    expect(fn () => app(VideoManualService::class)->create($projectA, 'タイトル', $categoryB->id, $owner->id))
        ->toThrow(ModelNotFoundException::class);

    expect(VideoManual::query()->count())->toBe(0);
});

test('VideoManualService::updateMeta は他 project の categoryId を拒否し変更を巻き戻す', function (): void {
    [$organization] = createOrganizationWithOwner();
    $projectA = Project::factory()->forOrganization($organization)->create();
    $projectB = Project::factory()->forOrganization($organization)->create();
    $categoryB = Category::factory()->forProject($projectB)->create();
    $manualA = VideoManual::factory()->forProject($projectA)->create(['title' => '元のタイトル']);

    expect(fn () => app(VideoManualService::class)->updateMeta($projectA, $manualA, '改竄タイトル', $categoryB->id))
        ->toThrow(ModelNotFoundException::class);

    $fresh = $manualA->fresh();
    expect($fresh?->title)->toBe('元のタイトル');
    expect($fresh?->category_id)->toBeNull();
});

/*
 * 生成経路の初期状態契約 (T151)。
 *
 * create() が status / scenario_version を DB カラム default に委ねていた頃、戻り値の
 * インスタンスは当該属性を持たず (INSERT に含めていないため hydrate されない)、
 * 呼び出し側が `$manual->status->value` を読むと
 * `ErrorException: Attempt to read property "value" on null` で落ちた (pipeline-smoke の
 * fixture 段で実走観測)。以下は **refresh()/fresh() を挟まない戻り値インスタンスそのもの**に
 * 対する契約であり、この形の再発を behavioral に検出する。
 *
 * **属性ごとにテストを分けてある**: 1 本にまとめると status の明示代入だけを消したときと
 * scenario_version の明示代入だけを消したときの非対称 (片方だけ赤くなる) が観測できない
 * (同一テスト内では最初の失敗で停止するため)。
 *
 * ScenarioWritePathInventoryTest の allowlist は**ファイル粒度**でありメソッド単位の
 * fail-first を担えない。create() の明示代入を守るのは本テストである。
 */

test('VideoManualService::create の戻り値は refresh なしで status=Draft を保持する (category+SOP あり)', function (): void {
    // 既定ディスクを fake する (SourceDocumentService::appendDocument は Storage::putFileAs を
    // ディスク指定なし = 既定ディスクで呼ぶ。SourceDocumentUploadTest と同じ流儀)
    Storage::fake();
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();
    $category = Category::factory()->forProject($project)->create();
    $document = UploadedFile::fake()->createWithContent('sop.txt', '手順 1: 装置の電源を入れる');

    // pipeline-smoke が実際に踏んだ形 (category + SOP 同時アップロード) に寄せる。
    // category ありにすることで associate 後の 2 度目の save を通っても属性が残ることを固定する。
    $manual = app(VideoManualService::class)->create(
        $project, 'テスト手順書', $category->id, $owner->id, $document,
    );

    expect($manual->status)->toBe(VideoManualStatus::Draft);
    // 実走と同じ読み方 (修正前は "Attempt to read property \"value\" on null" で落ちる)
    expect($manual->status->value)->toBe('draft');
    expect($manual->category_id)->toBe($category->id);
    expect($manual->sourceDocuments()->count())->toBe(1);
});

test('VideoManualService::create の戻り値は refresh なしで scenario_version=0 を保持する', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();

    // 最短経路 (category なし / SOP なし)。status 契約と分けているのは mutation の観測のため。
    $manual = app(VideoManualService::class)->create($project, 'テスト手順書', null, $owner->id);

    expect($manual->scenario_version)->toBe(0);
});

test('VideoManualService::create が INSERT した行は DB 上も status=draft・scenario_version=0 である', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();

    $manual = app(VideoManualService::class)->create($project, 'テスト手順書', null, $owner->id);

    // 戻り値だけ整えて DB が別値、の取り違え防止 (明示代入値が DB default と一致することの固定)
    $fresh = $manual->fresh();
    expect($fresh?->status)->toBe(VideoManualStatus::Draft);
    expect($fresh?->scenario_version)->toBe(0);
    // cast を経由しない生値
    expect(DB::table('video_manuals')->where('id', $manual->id)->value('status'))->toBe('draft');
});
