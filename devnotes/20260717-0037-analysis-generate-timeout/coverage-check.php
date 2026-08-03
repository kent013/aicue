<?php

declare(strict_types=1);

/**
 * 使い捨て検証スクリプト (devnotes 配下)。
 * probe-outputs/*.txt を正解データ (作業分解表 35 手順) と突き合わせ、網羅性を機械的に測る。
 * API を叩かないので追加コストなし。
 *
 * 出力の実構造 (実物を読んで確認済み):
 *   GeneratedScenarioData->steps: list<ScenarioStepInput>
 *   ScenarioStepInput{ id, scene, shotType, shootingPoint, narration, subtitlePrimary, subtitleSecondary, points[] }
 *   → SOP 手順との対応は subtitlePrimary / subtitleSecondary で見る (action フィールドは存在しない)
 */

use App\DataTransferObjects\Manual\Analysis\GeneratedScenarioData;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require_once __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$truth = json_decode((string) DB::table('analysis_jobs')->where('id', 1)->value('result_json'), true);
$truthSteps = $truth['steps'] ?? [];
$truthPoints = array_sum(array_map(fn ($s) => count($s['points'] ?? []), $truthSteps));
printf("正解: %d 手順 / points 合計 %d\n\n", count($truthSteps), $truthPoints);

/** 手順名から特徴語を抜く (助詞・語尾を落として名詞句で照合する) */
function keyOf(string $action): string
{
    $a = preg_replace('/(する|を|に|へ|の|が|、|。|\s)+$/u', '', $action) ?? $action;

    return mb_substr($a, 0, 5);
}

foreach (glob(__DIR__.'/probe-outputs/*.txt') as $f) {
    $name = basename($f, '.txt');
    try {
        $d = GeneratedScenarioData::fromLlmText(file_get_contents($f));
    } catch (Throwable $e) {
        printf("=== %-38s パース不能\n", $name);

        continue;
    }

    // 出力側の全テキスト (step + point の字幕/シーン) を照合対象に集める
    $haystack = '';
    $points = 0;
    $emptyPoints = 0;
    foreach ($d->steps as $s) {
        $haystack .= ($s->subtitlePrimary ?? '').' '.$s->subtitleSecondary.' '.$s->scene.' '.$s->narration.' ';
        $p = is_array($s->points ?? null) ? count($s->points) : 0;
        $points += $p;
        if ($p === 0) {
            $emptyPoints++;
        }
        foreach (($s->points ?? []) as $pt) {
            $haystack .= ($pt->subtitlePrimary ?? '').' '.$pt->subtitleSecondary.' '.$pt->scene.' ';
        }
    }

    $missing = [];
    foreach ($truthSteps as $i => $ts) {
        $k = keyOf((string) $ts['action']);
        if ($k === '' || mb_strpos($haystack, $k) === false) {
            $missing[] = ($i + 1).'. '.$ts['action'];
        }
    }

    printf("=== %s\n", $name);
    printf("  steps=%2d/%d  points=%2d/%d  points空の手順=%d  網羅=%d/%d手順\n",
        count($d->steps), count($truthSteps), $points, $truthPoints,
        $emptyPoints, count($truthSteps) - count($missing), count($truthSteps));
    if ($missing !== []) {
        echo "  取りこぼした手順:\n";
        foreach ($missing as $m) {
            echo "     - $m\n";
        }
    }
    echo "\n";
}
