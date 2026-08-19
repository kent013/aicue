<?php

declare(strict_types=1);

use App\DataTransferObjects\Manual\Analysis\ExtractedSopData;
use App\Enums\Manual\AnalysisFailureReason;
use App\Exceptions\Manual\AnalysisFailedException;
use App\Exceptions\Manual\LlmOutputInvalidException;
use App\Support\Manual\AnalysisAcceptanceGate;

/*
 * AnalysisAcceptanceGate (画像・スキャン SOP の OCR 対応): OCR 経路の成功条件。
 * 手順 1 件以上・work_process 非空は ExtractedSopData::fromLlmText() が既に検証済みなので、
 * ここでは実質空判定 (tooShort 相当) と日本語比率を追加でかける。
 *
 * 比率判定用の定型フィクスチャは短い日本語句が多いため、実質空判定
 * (manual.analysis_min_text_bytes) を小さい値に固定し、比率判定の検証と
 * 実質空判定の検証を分離する (実質空判定そのものは専用のテストで固定する)。
 */
beforeEach(function (): void {
    config()->set('manual.analysis_min_text_bytes', 10);
});

/** @param  list<string>  $workProcesses */
function ocrResult(array $workProcesses): ExtractedSopData
{
    return ExtractedSopData::fromLlmText(json_encode([
        'header' => ['title' => null, 'department' => null, 'revision' => null],
        'sections' => [[
            'title' => null,
            'steps' => array_map(
                static fn (string $workProcess, int $index): array => [
                    'no' => $index + 1,
                    'work_process' => $workProcess,
                    'work_points' => [],
                    'safety_points' => [],
                    'quality_points' => [],
                    'pm_points' => [],
                ],
                $workProcesses,
                array_keys($workProcesses),
            ),
        ]],
    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
}

test('先に赤くする: [UNREADABLE] のみの結果は現状のスキーマ検証だけでは拒否されない', function (): void {
    // 非空文字列なので fromLlmText() 自体は通過する (schemaViolation にはならない)。
    // AnalysisAcceptanceGate がまだ無かった頃はここで通ってしまっていたことの確認。
    $data = ocrResult(['[UNREADABLE]', '[UNREADABLE]']);
    expect($data->sections[0]['steps'])->toHaveCount(2);
});

test('[UNREADABLE] だけで構成された結果は OcrEmptyOrInvalid で拒否される', function (): void {
    $data = ocrResult(['[UNREADABLE]', '[UNREADABLE][UNREADABLE]']);

    expect(fn () => AnalysisAcceptanceGate::validateOcrResult($data))
        ->toThrow(AnalysisFailedException::class);

    try {
        AnalysisAcceptanceGate::validateOcrResult($data);
    } catch (AnalysisFailedException $exception) {
        expect($exception->reason)->toBe(AnalysisFailureReason::OcrEmptyOrInvalid);
    }
});

test('日本語比率が下限未満 (英数字のみ) の結果は拒否される', function (): void {
    $data = ocrResult(['ABC123', 'DEF456']);

    expect(fn () => AnalysisAcceptanceGate::validateOcrResult($data))
        ->toThrow(AnalysisFailedException::class);
});

test('手順 1 件以上・日本語比率も十分な結果は正常に通る', function (): void {
    $data = ocrResult(['バルブを閉じる', 'ハンドルを時計回りに回す']);

    $result = AnalysisAcceptanceGate::validateOcrResult($data);
    expect($result)->toBe($data);
});

test('判読不能箇所が一部だけの結果は全体比率次第で通過/拒否の両方になりうる', function (): void {
    // 正常な日本語本文が十分にある場合は通過する
    $mostlyReadable = ocrResult([
        'バルブを閉じる作業を丁寧に実施する。ハンドルを時計回りにゆっくりと回して確実に閉じる。',
        '[UNREADABLE]',
    ]);
    expect(AnalysisAcceptanceGate::validateOcrResult($mostlyReadable))->toBe($mostlyReadable);

    // 判読不能が支配的な場合は拒否される
    $mostlyUnreadable = ocrResult(['あ', '[UNREADABLE][UNREADABLE][UNREADABLE][UNREADABLE]']);
    expect(fn () => AnalysisAcceptanceGate::validateOcrResult($mostlyUnreadable))
        ->toThrow(AnalysisFailedException::class);
});

test('日本語比率が 1.0 でも実質空 (min_text_bytes 未満) なら OcrEmptyOrInvalid (impl-review Round 2 対応)', function (): void {
    // 「あ」1 文字は日本語比率 1.0 になるが、実質空 (tooShort 相当) であるべき。
    // テキスト経路の tooShort を PDF の OCR フォールバックで実質迂回できてしまう欠陥の回帰確認。
    config()->set('manual.analysis_min_text_bytes', 100); // 本番既定値相当で検証する
    $data = ocrResult(['あ']);

    expect(fn () => AnalysisAcceptanceGate::validateOcrResult($data))
        ->toThrow(AnalysisFailedException::class);
    try {
        AnalysisAcceptanceGate::validateOcrResult($data);
    } catch (AnalysisFailedException $exception) {
        expect($exception->reason)->toBe(AnalysisFailureReason::OcrEmptyOrInvalid);
    }
});

test('実質空判定は境界値 (ちょうど min_text_bytes / 1 byte 不足) を正しく判定する', function (): void {
    // 日本語 1 文字 = UTF-8 で 3 byte。「あいう」3 文字 = ちょうど 9 byte。
    $text = 'あいう';
    expect(strlen($text))->toBe(9);

    config()->set('manual.analysis_min_text_bytes', 9); // ちょうど 9 byte → 通る
    $exact = ocrResult([$text]);
    expect(AnalysisAcceptanceGate::validateOcrResult($exact))->toBe($exact);

    config()->set('manual.analysis_min_text_bytes', 10); // 1 byte 不足 → 拒否される
    $short = ocrResult([$text]);
    expect(fn () => AnalysisAcceptanceGate::validateOcrResult($short))
        ->toThrow(AnalysisFailedException::class);
});

test('日本語として自然な捏造はこのゲートでは検出できない (既知の限界の回帰確認)', function (): void {
    // 資料に無い内容でも自然な日本語であればゲートは通過させる。
    // 誤読・捏造の是正は既存の「編集する」機能に委ねる (概念設計の既知の限界)。
    $fabricated = ocrResult(['この手順書には存在しない架空の作業手順を記述する。']);

    expect(AnalysisAcceptanceGate::validateOcrResult($fabricated))->toBe($fabricated);
});

test('検証順序: スキーマ違反 (空文字列 work_process) は日本語比率チェックまで到達せず schemaViolation になる', function (): void {
    expect(fn () => ExtractedSopData::fromLlmText(json_encode([
        'header' => [],
        'sections' => [[
            'title' => null,
            'steps' => [[
                'no' => 1,
                'work_process' => '',
                'work_points' => [],
                'safety_points' => [],
                'quality_points' => [],
                'pm_points' => [],
            ]],
        ]],
    ])))->toThrow(LlmOutputInvalidException::class);
});
