<?php

declare(strict_types=1);

use App\Models\SourceDocument;
use App\Services\Manual\SopTextExtractor;
use Illuminate\Support\Facades\Storage;

/*
 * pipeline smoke のダミー SOP fixture (施策 5)。
 *
 * 意義: 「smoke が fixture の不備で落ちる」という**紛らわしい失敗**を構造的に潰す。
 * 判定は比率計算を再実装せず SopTextExtractor と同じ基準で behavioral に行う。
 */

it('fixture が存在し UTF-8 として妥当である', function (): void {
    $path = base_path('resources/fixtures/pipeline-smoke-sop.txt');

    expect(is_file($path))->toBeTrue();

    $contents = file_get_contents($path);
    expect($contents)->toBeString()
        ->and(mb_check_encoding((string) $contents, 'UTF-8'))->toBeTrue()
        ->and(strlen((string) $contents))
        ->toBeGreaterThan(config()->integer('manual.analysis_min_text_bytes'))
        ->toBeLessThan(config()->integer('manual.analysis_max_text_bytes'));
});

it('fixture が SopTextExtractor のゲートを通る', function (): void {
    Storage::fake();
    $contents = file_get_contents(base_path('resources/fixtures/pipeline-smoke-sop.txt'));
    expect($contents)->toBeString();

    $path = 'source-documents/pipeline-smoke-sop.txt';
    Storage::put($path, (string) $contents);

    $document = SourceDocument::factory()->create([
        'file_path' => $path,
        'original_name' => 'pipeline-smoke-sop.txt',
        'mime' => 'text/plain',
        'size_bytes' => strlen((string) $contents),
    ]);

    // 短すぎ / 日本語比率不足なら AnalysisFailedException が飛ぶ = ゲートを通らない
    $extracted = app(SopTextExtractor::class)->extract($document);

    expect($extracted->text)->not->toBe('')
        ->and($extracted->byteLength)
        ->toBeGreaterThanOrEqual(config()->integer('manual.analysis_min_text_bytes'));
});
