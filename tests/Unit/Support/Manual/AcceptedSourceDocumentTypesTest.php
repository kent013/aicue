<?php

declare(strict_types=1);

use App\Support\Manual\AcceptedSourceDocumentTypes;

/*
 * AcceptedSourceDocumentTypes (画像・スキャン SOP の OCR 対応): 受理する SourceDocument
 * 形式の唯一の情報源。旧 `manual.ocr_analysis_enabled` フラグは撤去済みで、
 * 画像受理は常時有効。extensions()/mimes()/acceptAttribute() を固定する。
 */

test('画像 (jpg/jpeg/png) を含む (常時有効)', function (): void {
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
});

test('formatsLabel は画像を含む文面を返す (常時有効)', function (): void {
    expect(AcceptedSourceDocumentTypes::formatsLabel())
        ->toBe('PDF・Excel・テキスト形式、または JPEG・PNG の画像');
});

/*
 * ラベルの前提の pin。formatsLabel() は拡張子リストから機械導出せず、法務確認を経た
 * 文をそのまま持つ。したがって「拡張子集合が変わったのにラベルが据え置き」という
 * 乖離は本テストだけが検出できる。
 *
 * 集合の差分ではなく **順序込みの完全一致** で書く: acceptAttribute() は extensions() の
 * 順序に依存して文字列を組むため、集合比較では表示順の変更を見逃す。
 */
test('前提の pin: 拡張子集合が現在値ちょうど (ずれたらラベルの見直しが必要)', function (): void {
    $failure = 'ラベル (AcceptedSourceDocumentTypes::formatsLabel) の見直しが必要です。'
        .'受理拡張子の集合または順序が変わったのに、人間向けの文面は機械導出していないため追随しません。';

    expect(config()->array('manual.source_document_mimes'))
        ->toBe(['pdf', 'xlsx', 'xls', 'txt'], $failure);
    expect(AcceptedSourceDocumentTypes::extensions())
        ->toBe(['pdf', 'xlsx', 'xls', 'txt', 'jpg', 'jpeg', 'png'], $failure);
});

test('webp/gif は含まれない (スコープ外)', function (): void {
    expect(AcceptedSourceDocumentTypes::extensions())->not->toContain('webp');
    expect(AcceptedSourceDocumentTypes::extensions())->not->toContain('gif');
    expect(AcceptedSourceDocumentTypes::mimes())->not->toContain('image/webp');
    expect(AcceptedSourceDocumentTypes::mimes())->not->toContain('image/gif');
});
