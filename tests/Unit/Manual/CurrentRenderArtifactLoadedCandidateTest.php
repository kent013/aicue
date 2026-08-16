<?php

declare(strict_types=1);

use App\Models\RenderJob;
use App\Models\VideoManual;
use App\Services\Manual\CurrentRenderArtifact;
use Illuminate\Support\Facades\DB;

/*
 * T189: 一覧向けの入口 CurrentRenderArtifact::fromLoadedRenderCandidate() の契約。
 *
 * currentSucceeded() と**同じ規則** (最新 succeeded の output_path が NULL なら
 * 旧世代へフォールバックしない) を、**eager load 済みの候補行**に対して適用する。
 * 一覧は行数に比例したクエリを撃たないため、この入口は追加クエリを 1 本も出さず、
 * 未ロードで呼ばれたら黙って lazy load せずに落ちる。
 */

test('eager load 済みの候補行を返し、追加クエリを 1 本も撃たない', function (): void {
    $manual = VideoManual::factory()->create();
    $newest = RenderJob::factory()->forManual($manual)->succeeded('renders/new.mp4')->create();

    // 観測区間は fromLoadedRenderCandidate() の呼び出しだけにする
    // (fixture 生成と load はカウンタ開始前に終わらせる)
    $manual->load('latestSucceededRender');

    DB::enableQueryLog();
    DB::flushQueryLog();

    try {
        // 退行 (未ロード例外など) で抜けても query log を必ず閉じる
        // = テスト間へグローバル状態を漏らさない
        $selected = CurrentRenderArtifact::fromLoadedRenderCandidate($manual);
        $log = DB::getQueryLog();
    } finally {
        DB::disableQueryLog();
    }

    expect($selected?->id)->toBe($newest->id);
    expect($log)->toBe([], '一覧向けの入口が追加クエリを撃ちました (行数に比例した N+1 になります)');
});

test('候補行の output_path が NULL なら null を返す (旧世代へフォールバックしない)', function (): void {
    $manual = VideoManual::factory()->create();
    RenderJob::factory()->forManual($manual)->succeeded('renders/old.mp4')->create();
    $stale = RenderJob::factory()->forManual($manual)->succeeded('renders/new.mp4')
        ->state(fn (): array => ['output_path' => null])->create();

    $manual->load('latestSucceededRender');

    // 候補行 relation は output_path を見ないので stale 行を返す (判断は選択式が持つ)
    expect($manual->latestSucceededRender?->id)->toBe($stale->id);
    expect(CurrentRenderArtifact::fromLoadedRenderCandidate($manual))->toBeNull();
});

test('候補行が無い manual では null を返す', function (): void {
    $manual = VideoManual::factory()->create();
    $manual->load('latestSucceededRender');

    expect(CurrentRenderArtifact::fromLoadedRenderCandidate($manual))->toBeNull();
});

test('未ロードの manual を渡すと InvalidArgumentException になる (黙って lazy load しない)', function (): void {
    $manual = VideoManual::factory()->create();
    RenderJob::factory()->forManual($manual)->succeeded('renders/new.mp4')->create();

    // relation を load していない = 一覧の eager load が外れた状態
    expect($manual->relationLoaded('latestSucceededRender'))->toBeFalse();

    CurrentRenderArtifact::fromLoadedRenderCandidate($manual);
})->throws(InvalidArgumentException::class);
