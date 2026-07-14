<?php

declare(strict_types=1);

use App\DataTransferObjects\Manual\ScenarioPointInput;
use App\DataTransferObjects\Manual\ScenarioStepInput;
use App\Enums\Manual\ShotType;
use App\Models\VideoManual;
use App\Services\Manual\ScenarioBookendBuilder;
use App\Support\Manual\ScenarioLimits;

/*
 * ScenarioBookendBuilder の抽出/組み立て規則 (純関数)。
 * - 先頭=導入 / 末尾=総括 / 中間=渡した steps 保持
 * - 総括 subtitle_secondary の 3 段フォールバック (point → step → 定型) と長さ制御
 * - normalize (全角空白) / title truncate / lang キー欠落 fail-fast
 */

/**
 * @param  list<ScenarioPointInput>  $points
 */
function bookendStep(?string $subtitlePrimary = null, array $points = []): ScenarioStepInput
{
    return new ScenarioStepInput(
        id: null,
        scene: '手順シーン',
        shotType: ShotType::Yori,
        shootingPoint: null,
        narration: '手順の説明',
        subtitlePrimary: $subtitlePrimary,
        subtitleSecondary: '手順の補足',
        materialType: null,
        staticDisplaySeconds: null,
        points: $points,
    );
}

function bookendPoint(?string $subtitlePrimary): ScenarioPointInput
{
    return new ScenarioPointInput(
        id: null,
        scene: '急所シーン',
        shotType: ShotType::Yori,
        shootingPoint: null,
        narration: '急所の説明',
        subtitlePrimary: $subtitlePrimary,
        subtitleSecondary: '急所の補足',
        materialType: null,
        staticDisplaySeconds: null,
    );
}

function bookendManual(string $title = 'ネジ締め作業'): VideoManual
{
    return VideoManual::factory()->make(['title' => $title]);
}

test('wrap は先頭=導入・末尾=総括・中間=渡した steps を順序保持で返す', function (): void {
    $steps = [bookendStep('手順A'), bookendStep('手順B')];

    $result = app(ScenarioBookendBuilder::class)->wrap(bookendManual(), $steps);

    expect($result)->toHaveCount(4);
    // 中間は渡した step を同一オブジェクトで保持
    expect($result[1])->toBe($steps[0]);
    expect($result[2])->toBe($steps[1]);
});

test('導入カットは Hiki / points=[] / id=null / narration に作業名補間 / subtitle_primary <=100', function (): void {
    $result = app(ScenarioBookendBuilder::class)->wrap(bookendManual('ネジ締め作業'), [bookendStep('手順A')]);

    $intro = $result[0];
    expect($intro->id)->toBeNull();
    expect($intro->shotType)->toBe(ShotType::Hiki);
    expect($intro->points)->toBe([]);
    expect($intro->narration)->toContain('ネジ締め作業');
    expect(mb_strlen((string) $intro->subtitlePrimary))->toBeLessThanOrEqual(ScenarioLimits::MAX_SUBTITLE_PRIMARY_CHARS);
});

test('総括カットは Hiki / points=[] / id=null で末尾に置かれる', function (): void {
    $result = app(ScenarioBookendBuilder::class)->wrap(bookendManual(), [bookendStep('手順A')]);

    $summary = $result[array_key_last($result)];
    expect($summary->id)->toBeNull();
    expect($summary->shotType)->toBe(ShotType::Hiki);
    expect($summary->points)->toBe([]);
});

test('総括再掲は point.subtitle_primary を先頭 N 件「／」連結する (config 既定 3)', function (): void {
    $steps = [
        bookendStep('手順A', [bookendPoint('急所1'), bookendPoint('急所2')]),
        bookendStep('手順B', [bookendPoint('急所3'), bookendPoint('急所4')]),
    ];

    $result = app(ScenarioBookendBuilder::class)->wrap(bookendManual(), $steps);
    $summary = $result[array_key_last($result)];

    // 既定 3 件: 急所1／急所2／急所3
    expect($summary->subtitleSecondary)->toContain('急所1');
    expect($summary->subtitleSecondary)->toContain('急所2');
    expect($summary->subtitleSecondary)->toContain('急所3');
    expect($summary->subtitleSecondary)->not->toContain('急所4');
    // lang 接頭辞込みの完成文
    expect($summary->subtitleSecondary)->toBe(trans('manual.bookend.summary.subtitle_secondary_recap', [
        'points' => '急所1／急所2／急所3',
    ], 'ja'));
});

test('summary_recap_max_points で再掲件数が可変になる', function (): void {
    config(['manual.summary_recap_max_points' => 2]);
    $steps = [bookendStep('手順A', [bookendPoint('急所1'), bookendPoint('急所2'), bookendPoint('急所3')])];

    $result = app(ScenarioBookendBuilder::class)->wrap(bookendManual(), $steps);
    $summary = $result[array_key_last($result)];

    expect($summary->subtitleSecondary)->toContain('急所2');
    expect($summary->subtitleSecondary)->not->toContain('急所3');
});

test('point が全て空なら top-level step.subtitle_primary へフォールバックする', function (): void {
    $steps = [
        bookendStep('手順A', [bookendPoint(null)]),
        bookendStep('手順B'),
    ];

    $result = app(ScenarioBookendBuilder::class)->wrap(bookendManual(), $steps);
    $summary = $result[array_key_last($result)];

    expect($summary->subtitleSecondary)->toContain('手順A');
    expect($summary->subtitleSecondary)->toContain('手順B');
});

test('point / step ともに空なら定型フォールバック文面を使う', function (): void {
    $steps = [bookendStep(null, [bookendPoint(null)])];

    $result = app(ScenarioBookendBuilder::class)->wrap(bookendManual('配線作業'), $steps);
    $summary = $result[array_key_last($result)];

    expect($summary->subtitleSecondary)->toBe(trans('manual.bookend.summary.subtitle_secondary_fallback', [
        'title' => '配線作業',
    ], 'ja'));
});

test('全角空白のみの subtitle_primary は再掲元に採らない (normalize)', function (): void {
    $steps = [
        bookendStep('手順A', [bookendPoint('　　'), bookendPoint('急所X')]),
    ];

    $result = app(ScenarioBookendBuilder::class)->wrap(bookendManual(), $steps);
    $summary = $result[array_key_last($result)];

    expect($summary->subtitleSecondary)->toContain('急所X');
    // 全角空白は候補外なので、それが 1 件目として拾われることはない
    expect($summary->subtitleSecondary)->toBe(trans('manual.bookend.summary.subtitle_secondary_recap', [
        'points' => '急所X',
    ], 'ja'));
});

test('長い title は scenario_bookend_title_max_chars で truncate される', function (): void {
    $max = config()->integer('manual.scenario_bookend_title_max_chars');
    $longTitle = str_repeat('長', $max + 20);

    $result = app(ScenarioBookendBuilder::class)->wrap(bookendManual($longTitle), [bookendStep('手順A')]);
    $intro = $result[0];

    // narration に埋まる作業名が max 文字に収まっている
    expect(mb_strlen((string) $intro->subtitlePrimary))->toBeLessThanOrEqual($max);
    expect($intro->subtitlePrimary)->toBe(str_repeat('長', $max));
});

test('複数件で完成文が上限超過なら件数を減らす', function (): void {
    // 1 件で ~1000 文字 → 接頭辞込み 2 件で MAX_SUBTITLE_SECONDARY_CHARS(2000) 超過
    $long = str_repeat('あ', 1000);
    $steps = [bookendStep('手順A', [bookendPoint($long), bookendPoint($long), bookendPoint('短い')])];

    $result = app(ScenarioBookendBuilder::class)->wrap(bookendManual(), $steps);
    $summary = $result[array_key_last($result)];

    expect(mb_strlen($summary->subtitleSecondary))->toBeLessThanOrEqual(ScenarioLimits::MAX_SUBTITLE_SECONDARY_CHARS);
});

test('1 件でも完成文が上限超過なら完成文を文字単位 truncate する', function (): void {
    $long = str_repeat('あ', ScenarioLimits::MAX_SUBTITLE_SECONDARY_CHARS + 500);
    $steps = [bookendStep('手順A', [bookendPoint($long)])];

    $result = app(ScenarioBookendBuilder::class)->wrap(bookendManual(), $steps);
    $summary = $result[array_key_last($result)];

    expect(mb_strlen($summary->subtitleSecondary))->toBe(ScenarioLimits::MAX_SUBTITLE_SECONDARY_CHARS);
});

test('summary_recap_max_points が 0 / -1 でも 1 件扱いに補正される', function (): void {
    $steps = [bookendStep('手順A', [bookendPoint('急所1'), bookendPoint('急所2')])];

    foreach ([0, -1] as $value) {
        config(['manual.summary_recap_max_points' => $value]);
        $result = app(ScenarioBookendBuilder::class)->wrap(bookendManual(), $steps);
        $summary = $result[array_key_last($result)];

        expect($summary->subtitleSecondary)->toContain('急所1');
        expect($summary->subtitleSecondary)->not->toContain('急所2');
    }
});

test('利用する bookend lang キーがすべて定義済みである', function (): void {
    $keys = [
        'manual.bookend.intro.scene',
        'manual.bookend.intro.narration',
        'manual.bookend.intro.subtitle_primary',
        'manual.bookend.intro.subtitle_secondary',
        'manual.bookend.summary.scene',
        'manual.bookend.summary.narration',
        'manual.bookend.summary.subtitle_primary',
        'manual.bookend.summary.subtitle_secondary_recap',
        'manual.bookend.summary.subtitle_secondary_fallback',
    ];
    foreach ($keys as $key) {
        expect(Lang::has($key, 'ja'))->toBeTrue("lang キー欠落: {$key}");
    }
});
