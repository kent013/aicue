<?php

declare(strict_types=1);

/**
 * 使い捨て実験スクリプト (devnotes 配下。恒久化しない)。
 *
 * 目的: generate 段 (シナリオ生成) だけを隔離し、同一入力 (tejun.pdf の作業分解表) を
 * 複数 provider/model に投げて「コスト」「所要時間」「出力トークン」「構造妥当性」を比較する。
 *
 * 設計上の注意:
 * - factory (ScenarioGenerationPrompt::make) 経由を維持する。Prism 直呼びはしない
 *   (AGENTS.md 禁止事項 5 / PromptGuardrailTest)。
 * - provider/model は selectOptimalProvider() がクラスプロパティを最優先するため、
 *   reflection でそこへ差し込む (YAML は書き換えない = 他の実行に影響しない)。
 * - 価格表に無い provider は unknown_model_behavior='zero' により llm_call_logs に
 *   コスト 0 で記録される。**コストは本スクリプトが公式単価から算術で出す** (台帳は信用しない)。
 *
 * 実行: php devnotes/20260717-0037-analysis-generate-timeout/provider-quality-probe.php
 */

use App\DataTransferObjects\Manual\Analysis\GeneratedScenarioData;
use App\Prompts\ScenarioGenerationPrompt;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require_once __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// 公式ドキュメントから取得した単価 (USD / 1M tokens)。取得日 2026-07-17。
// 出典: platform.claude.com/docs/en/about-claude/pricing.md,
//       developers.openai.com/api/docs/pricing, ai.google.dev/gemini-api/docs/pricing
const PRICES = [
    // Anthropic
    'anthropic:claude-sonnet-4-5-20250929' => ['in' => 3.00, 'out' => 15.00], // 現行 baseline (legacy)
    'anthropic:claude-haiku-4-5-20251001' => ['in' => 1.00, 'out' => 5.00],
    'anthropic:claude-sonnet-5' => ['in' => 2.00, 'out' => 10.00], // 導入価格 (〜2026-08-31)
    // Gemini (3.x = 現行世代)
    'gemini:gemini-3.1-flash-lite' => ['in' => 0.25, 'out' => 1.50],
    'gemini:gemini-3-flash-preview' => ['in' => 0.50, 'out' => 3.00],
    'gemini:gemini-3.5-flash' => ['in' => 1.50, 'out' => 9.00],
    // OpenAI (5.6 = 現行世代)
    'openai:gpt-5.6-luna' => ['in' => 1.00, 'out' => 6.00],
    'openai:gpt-5.4-nano' => ['in' => 0.20, 'out' => 1.25], // 前回 OK だったので比較用に残す
];

$targets = [
    ['anthropic', 'claude-sonnet-4-5-20250929'], // 現行 = baseline
    ['anthropic', 'claude-sonnet-5'],
    ['anthropic', 'claude-haiku-4-5-20251001'],  // 前回 overloaded → 再試行
    ['gemini', 'gemini-3.1-flash-lite'],
    ['gemini', 'gemini-3-flash-preview'],
    ['gemini', 'gemini-3.5-flash'],
    ['openai', 'gpt-5.6-luna'],
    ['openai', 'gpt-5.4-nano'],
];

$decomposition = DB::table('analysis_jobs')->where('id', 1)->value('result_json');
if (! is_string($decomposition) || $decomposition === '') {
    fwrite(STDERR, "作業分解表が取れない (analysis_jobs id=1 の result_json が空)\n");
    exit(1);
}
printf("入力: 作業分解表 %d bytes\n\n", strlen($decomposition));

$outDir = __DIR__.'/probe-outputs';
@mkdir($outDir, 0o755, true);

$results = [];
foreach ($targets as [$provider, $model]) {
    $label = "$provider:$model";
    printf('--- %s ... ', $label);

    $logIdBefore = (int) (DB::table('llm_call_logs')->max('id') ?? 0);
    $prompt = ScenarioGenerationPrompt::make($decomposition);

    // selectOptimalProvider() はクラスプロパティを最優先する。YAML を汚さずに差し替える。
    $ref = new ReflectionClass($prompt);
    foreach (['provider' => $provider, 'model' => $model] as $prop => $val) {
        $p = $ref->getProperty($prop);
        $p->setAccessible(true);
        $p->setValue($prompt, $val);
    }
    // 実所要 (baseline で実測 181〜230 秒) が YAML の 120 秒に切られないよう十分に取る。
    $prompt->withClientOptions(['timeout' => 900]);

    $start = microtime(true);
    $text = null;
    $error = null;
    try {
        $text = $prompt->executeSync();
    } catch (Throwable $e) {
        $error = $e::class.': '.substr($e->getMessage(), 0, 200);
    }
    $durationSec = round(microtime(true) - $start, 1);

    // 構造妥当性: 本番と同じ検証器にかける
    $parseError = null;
    $cutCount = null;
    $pointCount = null;
    if (is_string($text)) {
        try {
            $parsed = GeneratedScenarioData::fromLlmText($text);
            $cutCount = count($parsed->steps);
            $pointCount = 0;
            foreach ($parsed->steps as $s) {
                $pointCount += is_array($s->points ?? null) ? count($s->points) : 0;
            }
        } catch (Throwable $e) {
            $parseError = $e::class.': '.substr($e->getMessage(), 0, 160);
        }
    }

    // usage は「この実行で新しく書かれた行」だけを見る。
    // (前版のバグ: 失敗時は行が書かれないため直前の実行の行を読んでいた)
    $log = DB::table('llm_call_logs')->where('id', '>', $logIdBefore)->orderByDesc('id')->first();
    $inTok = $log->input_tokens ?? 0;
    $outTok = $log->output_tokens ?? 0;
    $finish = $log === null ? '(no log)' : ($log->finish_reason ?? '?');
    $price = PRICES[$label] ?? null;
    $cost = $price ? ($inTok * $price['in'] + $outTok * $price['out']) / 1_000_000 : null;

    if (is_string($text)) {
        file_put_contents("$outDir/".str_replace([':', '.'], ['_', '_'], $label).'.txt', $text);
    }

    $results[] = compact('label', 'durationSec', 'finish', 'inTok', 'outTok', 'cost', 'cutCount', 'pointCount', 'parseError', 'error');
    printf("%s / %ss / out=%s / finish=%s%s\n",
        $error ? 'ERROR' : ($parseError ? 'PARSE_NG' : 'OK'),
        $durationSec, $outTok, $finish, $error ? " / $error" : ($parseError ? " / $parseError" : ''));
}

echo "\n=== 比較表 (入力 tejun.pdf = 29 手順) ===\n";
printf("%-42s %7s %10s %8s %10s %6s %7s %s\n", 'model', 'sec', 'finish', 'out_tok', 'cost_usd', 'steps', 'points', 'valid');
foreach ($results as $r) {
    printf("%-42s %7s %10s %8s %10s %6s %7s %s\n",
        $r['label'], $r['durationSec'], $r['finish'], $r['outTok'],
        $r['cost'] !== null ? '$'.number_format($r['cost'], 4) : '-',
        $r['cutCount'] ?? '-', $r['pointCount'] ?? '-',
        $r['error'] ? 'ERROR' : ($r['parseError'] ? 'NG' : 'OK'));
}
file_put_contents(__DIR__.'/probe-results.json', json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "\n出力: $outDir/ , probe-results.json\n";
