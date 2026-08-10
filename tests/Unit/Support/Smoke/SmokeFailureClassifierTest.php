<?php

declare(strict_types=1);

use App\Enums\Manual\JobStatus;
use App\Enums\Smoke\SmokeFailureClass;
use App\Enums\Smoke\SmokeStage;
use App\Support\Smoke\SmokeFailureClassifier;

/*
 * 失敗分類 (施策 6)。**観測のための分類であり制御フローを変えない**。
 *
 * ここが smoke の固有ロジックのうち DB も実 LLM も要らない部分であり、
 * 判定表を Unit で直接固定する (コマンド本体は bughunt.local + bug-hunt DB を要求するため
 * llm-evidence 段まで Feature テストから駆動できない)。
 */

/**
 * 判定表を読みやすくするための名前付き引数ラッパ (既定はすべて「何も起きていない失敗」)。
 */
function classifySmoke(
    SmokeStage $stage,
    bool $stageSucceeded = false,
    ?JobStatus $jobStatus = null,
    bool $timedOut = false,
    bool $hasLlmFailureRow = false,
    bool $hasLlmSuccessRow = false,
    bool $llmRecordingIncomplete = false,
    bool $hasRenderErrorCode = false,
    bool $outputReadable = true,
    bool $ffprobeFailed = false,
): ?SmokeFailureClass {
    return SmokeFailureClassifier::classify(
        $stage,
        $stageSucceeded,
        $jobStatus,
        $timedOut,
        $hasLlmFailureRow,
        $hasLlmSuccessRow,
        $llmRecordingIncomplete,
        $hasRenderErrorCode,
        $outputReadable,
        $ffprobeFailed,
    );
}

it('preflight の失敗は Preflight', function (): void {
    expect(classifySmoke(SmokeStage::Preflight))->toBe(SmokeFailureClass::Preflight);
});

it('queued のまま上限到達は Wiring (worker が拾っていない)', function (): void {
    expect(classifySmoke(SmokeStage::Analysis, jobStatus: JobStatus::Queued, timedOut: true))
        ->toBe(SmokeFailureClass::Wiring);
});

it('running のまま上限到達は StageTimeout', function (): void {
    expect(classifySmoke(SmokeStage::Render, jobStatus: JobStatus::Running, timedOut: true))
        ->toBe(SmokeFailureClass::StageTimeout);
});

it('render 段の error_code は Render', function (): void {
    expect(classifySmoke(SmokeStage::Render, hasRenderErrorCode: true))
        ->toBe(SmokeFailureClass::Render);
});

it('artifact 段で出力を読み出せないのは Storage', function (): void {
    expect(classifySmoke(SmokeStage::Artifact, outputReadable: false))
        ->toBe(SmokeFailureClass::Storage);
});

it('artifact 段で読めたが ffprobe が落ちたのは Render', function (): void {
    expect(classifySmoke(SmokeStage::Artifact, outputReadable: true, ffprobeFailed: true))
        ->toBe(SmokeFailureClass::Render);
});

it('analysis 段の失敗で failure_reason 行があるのは Llm', function (): void {
    expect(classifySmoke(SmokeStage::Analysis, hasLlmFailureRow: true, hasLlmSuccessRow: true))
        ->toBe(SmokeFailureClass::Llm);
});

it('llm-evidence 段で成功行が 1 行も無いのは Llm', function (): void {
    expect(classifySmoke(SmokeStage::LlmEvidence, hasLlmSuccessRow: false))
        ->toBe(SmokeFailureClass::Llm);
});

it('fixture 段の失敗は Llm に漏らさず Unknown', function (): void {
    expect(classifySmoke(SmokeStage::Fixture, hasLlmSuccessRow: false))
        ->toBe(SmokeFailureClass::Unknown);
});

it('capture 段の失敗はリトライ痕 (failure 行) があっても Unknown', function (): void {
    expect(classifySmoke(SmokeStage::Capture, hasLlmFailureRow: true, hasLlmSuccessRow: true))
        ->toBe(SmokeFailureClass::Unknown);
});

it('llm-evidence 段で成功行はあるが記録が不完全なのは Wiring', function (): void {
    expect(classifySmoke(
        SmokeStage::LlmEvidence,
        hasLlmSuccessRow: true,
        llmRecordingIncomplete: true,
    ))->toBe(SmokeFailureClass::Wiring);
});

it('記録不完全でも llm-evidence 以外の段へ Wiring を漏らさない (負のコントロール)', function (): void {
    expect(classifySmoke(
        SmokeStage::Analysis,
        hasLlmSuccessRow: false,
        llmRecordingIncomplete: true,
    ))->toBe(SmokeFailureClass::Llm);
});

it('写像表に一致しない失敗は Unknown', function (): void {
    expect(classifySmoke(SmokeStage::Capture))->toBe(SmokeFailureClass::Unknown);
});

it('成功した段は分類しない (リトライの failure 行があっても null)', function (): void {
    expect(classifySmoke(
        SmokeStage::Analysis,
        stageSucceeded: true,
        hasLlmFailureRow: true,
        hasLlmSuccessRow: true,
    ))->toBeNull();
});

// ── llmRecordingIncomplete() の導出表 (DB 不要) ────────────────────────

/** @var list<string> */
$required = ['sop-extract', 'work-decomposition', 'scenario-generation'];

it('記録が完全なら false', function () use ($required): void {
    expect(SmokeFailureClassifier::llmRecordingIncomplete($required, $required, $required))->toBeFalse();
});

it('成功行が 1 行も無いのは「記録の不備」ではない (Llm 側の疑いへ渡す)', function () use ($required): void {
    expect(SmokeFailureClassifier::llmRecordingIncomplete($required, [], []))->toBeFalse();
});

it('必要 template の成功行が足りないのは true (帰属が正しくても記録が足りない)', function () use ($required): void {
    $partial = ['sop-extract', 'work-decomposition'];

    expect(SmokeFailureClassifier::llmRecordingIncomplete($required, $partial, $partial))->toBeTrue();
});

it('成功行はあるが帰属が一部欠けているのは true', function () use ($required): void {
    expect(SmokeFailureClassifier::llmRecordingIncomplete($required, $required, ['sop-extract']))->toBeTrue();
});

it('全行の帰属が落ちている (withMetadata 未配線そのもの) のは true', function () use ($required): void {
    expect(SmokeFailureClassifier::llmRecordingIncomplete($required, $required, []))->toBeTrue();
});

// ── fullyAttributedTemplates() の畳み込み表 (DB 不要) ─────────────────

it('全行が帰属していた template だけを返す', function (): void {
    $observations = [
        ['sop-extract', true],
        ['work-decomposition', true],
    ];

    expect(SmokeFailureClassifier::fullyAttributedTemplates($observations))
        ->toBe(['sop-extract', 'work-decomposition']);
});

it('同じ template に正しい行と壊れた行が混在したら帰属していないと畳む (AND)', function (): void {
    // OR で畳む実装 (「正しい行が 1 本あれば通る」) はこのケースを見逃す
    $observations = [
        ['sop-extract', true],
        ['sop-extract', false],   // 帰属が落ちたリトライ後の行など
        ['work-decomposition', true],
    ];

    expect(SmokeFailureClassifier::fullyAttributedTemplates($observations))
        ->toBe(['work-decomposition']);
});

it('壊れた行が先に来ても順序に依らず帰属していないと畳む', function (): void {
    $observations = [
        ['sop-extract', false],
        ['sop-extract', true],
    ];

    expect(SmokeFailureClassifier::fullyAttributedTemplates($observations))->toBe([]);
});

it('観測が 1 件も無ければ空を返す', function (): void {
    expect(SmokeFailureClassifier::fullyAttributedTemplates([]))->toBe([]);
});
