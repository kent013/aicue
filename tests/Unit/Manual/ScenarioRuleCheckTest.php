<?php

declare(strict_types=1);

use App\Enums\Manual\ScenarioRuleCode;
use App\Models\Cut;
use App\Models\VideoManual;
use App\Support\Manual\ScenarioRuleCheck;
use Illuminate\Database\Eloquent\Collection;

/*
 * ScenarioRuleCheck (シナリオ規約検査): 5 code の陽性/陰性と境界、位置表記、
 * 数え方 (導入/総括カットも手順に含む / 親を解決できない子は数えない) を固定する。
 */

/**
 * 規約に適合する既定値 (どの code にも載らない cut)。
 *
 * @return array<string, mixed>
 */
function compliantCutAttributes(): array
{
    return [
        'narration' => 'バルブを閉じます。',
        'subtitle_primary' => 'バルブ閉',
        'subtitle_secondary' => '安全確認',
    ];
}

/**
 * 手順カットを 1 件作る (sort_order は呼び出し順に採番する)。
 *
 * @param  array<string, mixed>  $overrides
 */
function makeStepCut(VideoManual $manual, int $sortOrder, array $overrides = []): Cut
{
    return Cut::factory()
        ->forManual($manual)
        ->withSortOrder($sortOrder)
        ->create([...compliantCutAttributes(), ...$overrides]);
}

/**
 * 急所カットを 1 件作る。
 *
 * @param  array<string, mixed>  $overrides
 */
function makePointCut(Cut $step, int $sortOrder, array $overrides = []): Cut
{
    return Cut::factory()
        ->asPointOf($step)
        ->withSortOrder($sortOrder)
        ->create([...compliantCutAttributes(), ...$overrides]);
}

/**
 * 検査対象の並び (ScenarioReportBuilder と同じ sort_order → id 順) で取得する。
 *
 * @return Collection<int, Cut>
 */
function orderedCutsOf(VideoManual $manual): Collection
{
    return $manual->cuts()->orderBy('sort_order')->orderBy('id')->get();
}

test('規約に適合するシナリオでは指摘が 0 件になる', function (): void {
    $manual = VideoManual::factory()->create();
    $step = makeStepCut($manual, 0);
    makePointCut($step, 1);

    $report = ScenarioRuleCheck::run(orderedCutsOf($manual));

    expect($report->findings)->toBe([]);
    expect($report->stepCount)->toBe(1);
    expect($report->pointCount)->toBe(1);
    expect($report->verdict)->toBeNull(); // 所見は呼び出し側が合流させる
});

test('5 つの code がそれぞれ陽性になる', function (array $overrides, ScenarioRuleCode $expected): void {
    $manual = VideoManual::factory()->create();
    makeStepCut($manual, 0, $overrides);

    $report = ScenarioRuleCheck::run(orderedCutsOf($manual));
    $codes = array_map(static fn ($finding): ScenarioRuleCode => $finding->code, $report->findings);

    expect($codes)->toContain($expected);
})->with([
    'narration_missing' => [['narration' => '   '], ScenarioRuleCode::NarrationMissing],
    'narration_not_polite' => [['narration' => 'バルブを閉じる'], ScenarioRuleCode::NarrationNotPolite],
    'narration_directive' => [['narration' => 'バルブを閉じてください。'], ScenarioRuleCode::NarrationDirective],
    'subtitle_primary_sentence' => [['subtitle_primary' => 'バルブを閉じます'], ScenarioRuleCode::SubtitlePrimarySentence],
    'subtitle_secondary_missing' => [['subtitle_secondary' => ''], ScenarioRuleCode::SubtitleSecondaryMissing],
]);

test('ナレーションが空のときは文体を問わない (missing だけが載る)', function (): void {
    $manual = VideoManual::factory()->create();
    makeStepCut($manual, 0, ['narration' => '']);

    $report = ScenarioRuleCheck::run(orderedCutsOf($manual));
    $codes = array_map(static fn ($finding): ScenarioRuleCode => $finding->code, $report->findings);

    expect($codes)->toBe([ScenarioRuleCode::NarrationMissing]);
});

test('丁寧体の境界: 否定形・体言止めでない終端は偽陽性にしない', function (string $narration): void {
    $manual = VideoManual::factory()->create();
    makeStepCut($manual, 0, ['narration' => $narration]);

    $report = ScenarioRuleCheck::run(orderedCutsOf($manual));

    expect($report->findings)->toBe([]);
})->with([
    'ます' => ['バルブを閉じます。'],
    'ません' => ['この状態で手を触れてはいけません。'],
    'です' => ['ハンドルが止まる位置が基準です。'],
    'でした' => ['前回の点検は正常でした。'],
    'ました' => ['圧力が下がりました。'],
    'ましょう' => ['圧力計を確認しましょう。'],
    '末尾に記号がある' => ['圧力を確認します!'],
    '末尾に空白がある' => ['圧力を確認します。  '],
]);

test('「〜してください」は directive と not_polite の両方に載る', function (): void {
    $manual = VideoManual::factory()->create();
    makeStepCut($manual, 0, ['narration' => 'バルブを閉じてください']);

    $report = ScenarioRuleCheck::run(orderedCutsOf($manual));
    $codes = array_map(static fn ($finding): ScenarioRuleCode => $finding->code, $report->findings);

    expect($codes)->toBe([ScenarioRuleCode::NarrationNotPolite, ScenarioRuleCode::NarrationDirective]);
});

test('位置は 1 始まりの「手順 N」「急所 N-M」で記録される', function (): void {
    $manual = VideoManual::factory()->create();
    makeStepCut($manual, 0);
    $step2 = makeStepCut($manual, 1, ['narration' => 'バルブを閉じる']);
    makePointCut($step2, 2);
    makePointCut($step2, 3);
    makePointCut($step2, 4, ['subtitle_secondary' => '']);

    $report = ScenarioRuleCheck::run(orderedCutsOf($manual));

    $byCode = [];
    foreach ($report->findings as $finding) {
        $byCode[$finding->code->value] = $finding;
    }

    expect($byCode['narration_not_polite']->positions)->toBe([['step' => 2, 'point' => null]]);
    expect($byCode['subtitle_secondary_missing']->positions)->toBe([['step' => 2, 'point' => 3]]);
});

test('位置は上限件数で打ち切るが count は全件になる', function (): void {
    $manual = VideoManual::factory()->create();
    $total = ScenarioRuleCheck::MAX_POSITIONS_PER_CODE + 3;
    for ($i = 0; $i < $total; $i++) {
        makeStepCut($manual, $i, ['subtitle_secondary' => '']);
    }

    $report = ScenarioRuleCheck::run(orderedCutsOf($manual));

    expect($report->findings)->toHaveCount(1);
    expect($report->findings[0]->count)->toBe($total);
    expect($report->findings[0]->positions)->toHaveCount(ScenarioRuleCheck::MAX_POSITIONS_PER_CODE);
    // 打ち切りは先頭から (位置は走査順)
    expect($report->findings[0]->positions[0])->toBe(['step' => 1, 'point' => null]);
});

test('導入/総括カットも手順として数える (識別子を持たない以上そうなる)', function (): void {
    $manual = VideoManual::factory()->create();
    // 導入 / 本体 / 総括 の 3 件がすべてトップレベル cut として並ぶ
    makeStepCut($manual, 0, ['narration' => 'この動画では作業の全体像を示します。']);
    makeStepCut($manual, 1);
    makeStepCut($manual, 2, ['narration' => '以上の手順を振り返ります。']);

    $report = ScenarioRuleCheck::run(orderedCutsOf($manual));

    expect($report->stepCount)->toBe(3);
    expect($report->pointCount)->toBe(0);
    expect($report->findings)->toBe([]);
});

test('親を解決できない子 cut は pointCount にも指摘にも入らない', function (): void {
    $manual = VideoManual::factory()->create();
    $step = makeStepCut($manual, 0);
    $orphanParent = makeStepCut($manual, 5);
    // 孤児 cut: 親が同じ集合に居ない (親を別 manual の cut にはできないため、
    // 取得集合から外れた cut を親に持つ状況を「集合を絞る」ことで再現する)
    $orphan = makePointCut($orphanParent, 6, ['subtitle_secondary' => '']);
    // 三層目の cut: 親は居るがその親自身も子である
    $point = makePointCut($step, 1);
    $thirdLevel = makePointCut($point, 2, ['subtitle_secondary' => '']);

    /** @var Collection<int, Cut> $subset */
    $subset = $manual->cuts()
        ->whereIn('id', [$step->id, $point->id, $thirdLevel->id, $orphan->id])
        ->orderBy('sort_order')->orderBy('id')->get();

    $report = ScenarioRuleCheck::run($subset);

    expect($report->stepCount)->toBe(1);
    expect($report->pointCount)->toBe(1); // $point だけ
    expect($report->findings)->toBe([]); // 孤児・三層目の指摘は出さない
});
