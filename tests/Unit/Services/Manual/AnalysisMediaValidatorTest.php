<?php

declare(strict_types=1);

use App\Enums\Manual\AnalysisFailureReason;
use App\Exceptions\Manual\AnalysisFailedException;
use App\Models\SourceDocument;
use App\Services\Manual\AnalysisMediaValidator;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Manual\MinimalImageFixture;
use Tests\Support\Manual\MinimalPdfFixture;

/*
 * AnalysisMediaValidator (画像・スキャン SOP の OCR 対応):
 * OCR 経路へ回してよい入力かどうかの判定 (容量・画素数・ページ数の上限) と
 * 検証済み媒体 DTO の生成を 1 箇所に閉じる。
 */

function storedMediaDocument(string $contents, string $mime, string $ext): SourceDocument
{
    $path = "source-documents/media-test.{$ext}";
    Storage::put($path, $contents);

    return SourceDocument::factory()->create([
        'file_path' => $path,
        'mime' => $mime,
    ]);
}

beforeEach(function (): void {
    Storage::fake();
});

// ---- 画像 ----

test('先に赤くする: 妥当な JPEG/PNG を渡すと検証済み DTO が返る', function (): void {
    $document = storedMediaDocument(MinimalImageFixture::jpeg(50, 30), 'image/jpeg', 'jpg');

    $data = app(AnalysisMediaValidator::class)->validateImage($document);

    expect($data->mime)->toBe('image/jpeg');
    expect($data->width)->toBe(50);
    expect($data->height)->toBe(30);
    expect($data->pixelCount)->toBe(1500);
    expect($data->sizeBytes)->toBe(strlen(MinimalImageFixture::jpeg(50, 30)));
});

test('PNG も検証済み DTO が返る', function (): void {
    $document = storedMediaDocument(MinimalImageFixture::png(20, 10), 'image/png', 'png');

    $data = app(AnalysisMediaValidator::class)->validateImage($document);

    expect($data->mime)->toBe('image/png');
    expect($data->width)->toBe(20);
    expect($data->height)->toBe(10);
});

test('画像容量上限ちょうどは通り、1 byte 超過で MediaTooLarge', function (): void {
    config()->set('manual.source_document_image_max_bytes', 100);
    $exact = MinimalImageFixture::jpeg(4, 4);
    // パディングでちょうど 100 byte に揃える (画像自体は妥当なまま)
    $padded = $exact.str_repeat("\x00", max(0, 100 - strlen($exact)));
    $overLimit = $padded."\x00";

    $documentExact = storedMediaDocument($padded, 'image/jpeg', 'jpg');
    expect(strlen($padded))->toBe(100);
    $dataExact = app(AnalysisMediaValidator::class)->validateImage($documentExact);
    expect($dataExact->sizeBytes)->toBe(100);

    $documentOver = storedMediaDocument($overLimit, 'image/jpeg', 'jpg2');
    expect(fn () => app(AnalysisMediaValidator::class)->validateImage($documentOver))
        ->toThrow(AnalysisFailedException::class);
    try {
        app(AnalysisMediaValidator::class)->validateImage($documentOver);
    } catch (AnalysisFailedException $exception) {
        expect($exception->reason)->toBe(AnalysisFailureReason::MediaTooLarge);
    }
});

test('画素数上限ちょうどは通り、1px 超過 (辺長は上限内) で MediaTooLarge', function (): void {
    config()->set('manual.analysis_ocr_max_dimension', 8000);
    config()->set('manual.analysis_ocr_max_pixels', 100);

    $exact = storedMediaDocument(MinimalImageFixture::jpeg(10, 10), 'image/jpeg', 'jpg');
    $data = app(AnalysisMediaValidator::class)->validateImage($exact);
    expect($data->pixelCount)->toBe(100);

    $over = storedMediaDocument(MinimalImageFixture::jpeg(11, 10), 'image/jpeg', 'jpg2');
    expect(fn () => app(AnalysisMediaValidator::class)->validateImage($over))
        ->toThrow(AnalysisFailedException::class);
});

test('辺長上限ちょうどは通り、1px 超過で MediaTooLarge (画素数判定には到達しない)', function (): void {
    config()->set('manual.analysis_ocr_max_dimension', 100);
    config()->set('manual.analysis_ocr_max_pixels', 1_000_000);

    $exact = storedMediaDocument(MinimalImageFixture::jpeg(100, 10), 'image/jpeg', 'jpg');
    $data = app(AnalysisMediaValidator::class)->validateImage($exact);
    expect($data->width)->toBe(100);

    $over = storedMediaDocument(MinimalImageFixture::jpeg(101, 10), 'image/jpeg', 'jpg2');
    expect(fn () => app(AnalysisMediaValidator::class)->validateImage($over))
        ->toThrow(AnalysisFailedException::class);
});

test('破損画像 (getimagesizefromstring が false) は MediaUnreadable', function (): void {
    $document = storedMediaDocument('not an image', 'image/jpeg', 'jpg');

    expect(fn () => app(AnalysisMediaValidator::class)->validateImage($document))
        ->toThrow(AnalysisFailedException::class);
    try {
        app(AnalysisMediaValidator::class)->validateImage($document);
    } catch (AnalysisFailedException $exception) {
        expect($exception->reason)->toBe(AnalysisFailureReason::MediaUnreadable);
    }
});

test('persisted mime と実バイトの形式が不一致なら MediaUnreadable', function (): void {
    // レコードは image/jpeg だが実体は PNG バイト
    $document = storedMediaDocument(MinimalImageFixture::png(10, 10), 'image/jpeg', 'jpg');

    expect(fn () => app(AnalysisMediaValidator::class)->validateImage($document))
        ->toThrow(AnalysisFailedException::class);
});

test('validateImage に PDF mime の SourceDocument を渡すと契約違反として例外になる', function (): void {
    $document = storedMediaDocument(MinimalPdfFixture::withPages(1), 'application/pdf', 'pdf');

    expect(fn () => app(AnalysisMediaValidator::class)->validateImage($document))
        ->toThrow(InvalidArgumentException::class);
});

test('識別可能なバイト列で検証すると、DTO の bytes が保存した fixture そのものと同一になる', function (): void {
    // 検証と vendor 変換は同じバイト列に対して行う (validateImage() 内で 1 回だけ読む)。
    // ここでは「読み込んだ結果が保存した fixture と同一であること」を固定する
    // (Storage の呼び出し回数そのものは実装 (Storage::get() を 1 度だけ呼ぶ) をコードで担保する)。
    $identifiable = MinimalImageFixture::jpeg(6, 6);
    $document = storedMediaDocument($identifiable, 'image/jpeg', 'identifiable.jpg');

    $data = app(AnalysisMediaValidator::class)->validateImage($document);

    expect($data->bytes)->toBe($identifiable);
    expect($data->sizeBytes)->toBe(strlen($identifiable));
});

// ---- PDF ----

test('妥当な PDF (1 ページ) は検証済み DTO が返る', function (): void {
    $bytes = MinimalPdfFixture::withPages(1);
    $document = storedMediaDocument($bytes, 'application/pdf', 'pdf');

    $data = app(AnalysisMediaValidator::class)->validatePdfForOcr($document);

    expect($data->mime)->toBe('application/pdf');
    expect($data->pageCount)->toBe(1);
    expect($data->bytes)->toBe($bytes);
});

test('PDF 容量上限ちょうどは通り、1 byte 超過で MediaTooLarge (既存の 20MB 上限を適用)', function (): void {
    $base = MinimalPdfFixture::withPages(1);

    // 上限 = 実サイズちょうど → 通る
    config()->set('manual.source_document_max_bytes', strlen($base));
    $documentAtBase = storedMediaDocument($base, 'application/pdf', 'pdf');
    $data = app(AnalysisMediaValidator::class)->validatePdfForOcr($documentAtBase);
    expect($data->sizeBytes)->toBe(strlen($base));

    // 上限 = 実サイズ - 1 (1 byte 超過相当) → MediaTooLarge
    config()->set('manual.source_document_max_bytes', strlen($base) - 1);
    $documentOver = storedMediaDocument($base, 'application/pdf', 'pdf2');
    expect(fn () => app(AnalysisMediaValidator::class)->validatePdfForOcr($documentOver))
        ->toThrow(AnalysisFailedException::class);
});

test('PDF ページ数上限ちょうどは通り、1 ページ超過で MediaTooLarge', function (): void {
    config()->set('manual.analysis_ocr_max_pages', 3);

    $exact = storedMediaDocument(MinimalPdfFixture::withPages(3), 'application/pdf', 'pdf');
    $data = app(AnalysisMediaValidator::class)->validatePdfForOcr($exact);
    expect($data->pageCount)->toBe(3);

    $over = storedMediaDocument(MinimalPdfFixture::withPages(4), 'application/pdf', 'pdf2');
    expect(fn () => app(AnalysisMediaValidator::class)->validatePdfForOcr($over))
        ->toThrow(AnalysisFailedException::class);
});

test('破損 PDF (パース不能) は MediaUnreadable (MediaTooLarge と弁別される)', function (): void {
    $document = storedMediaDocument(MinimalPdfFixture::corrupt(), 'application/pdf', 'pdf');

    expect(fn () => app(AnalysisMediaValidator::class)->validatePdfForOcr($document))
        ->toThrow(AnalysisFailedException::class);
    try {
        app(AnalysisMediaValidator::class)->validatePdfForOcr($document);
    } catch (AnalysisFailedException $exception) {
        expect($exception->reason)->toBe(AnalysisFailureReason::MediaUnreadable);
    }
});

test('PDF ページ数 0 (parseContent は成功するがページが無い) は MediaUnreadable', function (): void {
    $document = storedMediaDocument(MinimalPdfFixture::withZeroPages(), 'application/pdf', 'pdf');

    expect(fn () => app(AnalysisMediaValidator::class)->validatePdfForOcr($document))
        ->toThrow(AnalysisFailedException::class);
    try {
        app(AnalysisMediaValidator::class)->validatePdfForOcr($document);
    } catch (AnalysisFailedException $exception) {
        expect($exception->reason)->toBe(AnalysisFailureReason::MediaUnreadable);
    }
});

test('persisted mime は application/pdf だが実バイトが PDF でない場合は MediaUnreadable', function (): void {
    // 画像バイトを PDF として persisted した不整合 (ファイル差し替わり等) を検出する
    $document = storedMediaDocument(MinimalImageFixture::png(10, 10), 'application/pdf', 'pdf');

    expect(fn () => app(AnalysisMediaValidator::class)->validatePdfForOcr($document))
        ->toThrow(AnalysisFailedException::class);
    try {
        app(AnalysisMediaValidator::class)->validatePdfForOcr($document);
    } catch (AnalysisFailedException $exception) {
        expect($exception->reason)->toBe(AnalysisFailureReason::MediaUnreadable);
    }
});

test('validatePdfForOcr に画像 mime の SourceDocument を渡すと契約違反として例外になる', function (): void {
    $document = storedMediaDocument(MinimalImageFixture::jpeg(10, 10), 'image/jpeg', 'jpg');

    expect(fn () => app(AnalysisMediaValidator::class)->validatePdfForOcr($document))
        ->toThrow(InvalidArgumentException::class);
});
