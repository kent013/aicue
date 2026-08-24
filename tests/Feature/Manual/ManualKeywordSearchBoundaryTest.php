<?php

declare(strict_types=1);

use App\Models\Cut;
use App\Models\Project;
use App\Models\User;
use App\Models\VideoManual;
use App\Services\Manual\ManualKeywordSearch;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * T202: カット本文検索がテナント境界・母集団条件を破らないことの固定。
 *
 * **本ファイルが本改善の安全性の中核**である。ManualKeywordSearch::apply() の入れ子 group を
 * 外す (= orWhereHas を素で積む) と OR が外へ漏れ、呼び出し側が積んだ
 * project_id / status / created_by の制約を**すべて無効化する**。
 * その失敗様式で全件が赤くなるように書いてある。
 *
 * 検索語は本文にしか置かず、title には置かない (title 一致で通ってしまうと
 * 「カット本文の EXISTS が母集団条件を壊していないか」を見たことにならない)。
 */

/** 本文 (narration) にだけ検索語を持つカットを 1 本ぶら下げる */
function manualWithBodyKeyword(Project $project, string $keyword, string $title, ?User $creator = null, string $status = 'ready'): VideoManual
{
    $factory = VideoManual::factory()->forProject($project);
    if ($creator !== null) {
        $factory = $factory->createdBy($creator);
    }

    $manual = $factory->create(['title' => $title, 'status' => $status]);
    Cut::factory()->forManual($manual)->create(['narration' => "作業前に{$keyword}を確認する"]);

    return $manual;
}

test('別 project の manual は本文一致でも PC 一覧に混ざらない', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $own = Project::factory()->forOrganization($organization)->create();
    $other = Project::factory()->forOrganization($organization)->create();

    $target = manualWithBodyKeyword($own, 'ボルト締結', '自 project の手順');
    manualWithBodyKeyword($other, 'ボルト締結', '別 project の手順');

    $this->actingAs($owner)->get("/organizations/{$organization->slug}/projects/{$own->id}?q=".urlencode('ボルト締結'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('manuals.data', 1)
            ->where('manuals.data.0.id', $target->id)
            ->where('manuals.meta.total', 1));
});

test('別 project の manual は本文一致でも撮影 PWA 一覧に混ざらない', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $own = Project::factory()->forOrganization($organization)->create();
    $other = Project::factory()->forOrganization($organization)->create();

    $target = manualWithBodyKeyword($own, 'ボルト締結', '自 project の手順');
    manualWithBodyKeyword($other, 'ボルト締結', '別 project の手順');

    $this->actingAs($owner)->get("/organizations/{$organization->slug}/app/projects/{$own->id}/manuals?q=".urlencode('ボルト締結'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('manuals', 1)
            ->where('manuals.0.id', $target->id));
});

test('別 organization の manual は本文一致でもどちらの面にも混ざらない (cross-org 不可)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    [$foreignOrganization] = createOrganizationWithOwner('別組織');

    $own = Project::factory()->forOrganization($organization)->create();
    $foreign = Project::factory()->forOrganization($foreignOrganization)->create();

    $target = manualWithBodyKeyword($own, '絶縁手袋', '自組織の手順');
    manualWithBodyKeyword($foreign, '絶縁手袋', '別組織の手順');

    $this->actingAs($owner)->get("/organizations/{$organization->slug}/projects/{$own->id}?q=".urlencode('絶縁手袋'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('manuals.data', 1)
            ->where('manuals.data.0.id', $target->id));

    $this->actingAs($owner)->get("/organizations/{$organization->slug}/app/projects/{$own->id}/manuals?q=".urlencode('絶縁手袋'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('manuals', 1)
            ->where('manuals.0.id', $target->id));
});

test('撮影 PWA の ready/published 制限は本文一致でも外れない', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();

    $ready = manualWithBodyKeyword($project, '養生テープ', '撮影可', null, 'ready');
    manualWithBodyKeyword($project, '養生テープ', '下書き', null, 'draft');
    manualWithBodyKeyword($project, '養生テープ', '解析中', null, 'analyzing');

    $this->actingAs($owner)->get("/organizations/{$organization->slug}/app/projects/{$project->id}/manuals?q=".urlencode('養生テープ'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('manuals', 1)
            ->where('manuals.0.id', $ready->id));
});

test('mine=1 の created_by 制限は本文一致でも外れない (PC / 撮影 PWA の両面)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $other = attachOrganizationMember($organization);
    $project = Project::factory()->forOrganization($organization)->create();

    $mine = manualWithBodyKeyword($project, '安全帯', '自作', $owner);
    manualWithBodyKeyword($project, '安全帯', '他人作', $other);

    $this->actingAs($owner)->get("/organizations/{$organization->slug}/projects/{$project->id}?mine=1&q=".urlencode('安全帯'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('manuals.data', 1)
            ->where('manuals.data.0.id', $mine->id));

    $this->actingAs($owner)->get("/organizations/{$organization->slug}/app/projects/{$project->id}/manuals?mine=1&q=".urlencode('安全帯'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('manuals', 1)
            ->where('manuals.0.id', $mine->id));
});

test('apply() は呼び出し側が積んだ条件を無効化しない (負のコントロール)', function (): void {
    [$organization] = createOrganizationWithOwner();
    $project = Project::factory()->forOrganization($organization)->create();

    // A: 一致語を持たない / B: narration に一致語を持つ
    $a = VideoManual::factory()->forProject($project)->create(['title' => '一致しない手順']);
    Cut::factory()->forManual($a)->create(['narration' => '一致しない本文']);
    $b = manualWithBodyKeyword($project, '検査治具', '一致する手順');

    $query = VideoManual::query()->whereKey($a->id);
    ManualKeywordSearch::apply($query, '検査治具');

    // 入れ子 group を外すと whereKey が OR に押し出されて B が返り、必ず赤くなる。
    // toSql() の文字列一致は採らない (Laravel の版差で壊れ、守りたい性質を直接は見ていない)
    expect($query->pluck('id')->all())->toBe([]);
    expect($b->id)->not->toBe($a->id); // fixture の前提 (B が別行として実在すること)
});
