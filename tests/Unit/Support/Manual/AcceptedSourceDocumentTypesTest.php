<?php

declare(strict_types=1);

use App\Support\Manual\AcceptedSourceDocumentTypes;

/*
 * AcceptedSourceDocumentTypes (画像・スキャン SOP の OCR 対応): 受理する SourceDocument
 * 形式の唯一の情報源。フラグ true/false それぞれの extensions()/mimes()/
 * acceptAttribute()/imagesEnabled() を固定する。
 */

test('フラグ false のとき画像を含まない', function (): void {
    config()->set('manual.ocr_analysis_enabled', false);

    expect(AcceptedSourceDocumentTypes::extensions())->toBe(['pdf', 'xlsx', 'xls', 'txt']);
    expect(AcceptedSourceDocumentTypes::mimes())->toBe([
        'application/pdf',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-excel',
        'text/plain',
    ]);
    expect(AcceptedSourceDocumentTypes::acceptAttribute())->toBe('.pdf,.xlsx,.xls,.txt');
    expect(AcceptedSourceDocumentTypes::imagesEnabled())->toBeFalse();
});

test('フラグ true のとき画像 (jpg/jpeg/png) を含む', function (): void {
    config()->set('manual.ocr_analysis_enabled', true);

    expect(AcceptedSourceDocumentTypes::extensions())->toBe(['pdf', 'xlsx', 'xls', 'txt', 'jpg', 'jpeg', 'png']);
    expect(AcceptedSourceDocumentTypes::mimes())->toBe([
        'application/pdf',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-excel',
        'text/plain',
        'image/jpeg',
        'image/png',
    ]);
    expect(AcceptedSourceDocumentTypes::acceptAttribute())->toBe('.pdf,.xlsx,.xls,.txt,.jpg,.jpeg,.png');
    expect(AcceptedSourceDocumentTypes::imagesEnabled())->toBeTrue();
});

test('webp/gif はフラグに関わらず含まれない (スコープ外)', function (): void {
    config()->set('manual.ocr_analysis_enabled', true);

    expect(AcceptedSourceDocumentTypes::extensions())->not->toContain('webp');
    expect(AcceptedSourceDocumentTypes::extensions())->not->toContain('gif');
    expect(AcceptedSourceDocumentTypes::mimes())->not->toContain('image/webp');
    expect(AcceptedSourceDocumentTypes::mimes())->not->toContain('image/gif');
});
