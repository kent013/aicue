<?php

declare(strict_types=1);

use App\Exceptions\Manual\AnalysisFailedException;
use App\Models\SourceDocument;
use App\Services\Manual\SopTextExtractor;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/*
 * SOP テキスト抽出 (施策 7):
 * - plain / xlsx の抽出、UTF-8 strict 検証 (SJIS 変換 / バイナリ拒否)
 * - 実質空 (min_text_bytes 未満) / バイト上限超過の明示エラー
 */

/** 保存済み SourceDocument (Storage::fake 上) を作る */
function storedDocument(string $contents, string $mime, string $ext): SourceDocument
{
    $path = "source-documents/test.{$ext}";
    Storage::put($path, $contents);

    return SourceDocument::factory()->create([
        'file_path' => $path,
        'mime' => $mime,
    ]);
}

test('plain テキストをそのまま抽出する (byteLength = strlen)', function (): void {
    Storage::fake();
    $text = str_repeat("手順1 部品を取り付ける\n", 10);
    $document = storedDocument($text, 'text/plain', 'txt');

    $extracted = app(SopTextExtractor::class)->extract($document);

    expect($extracted->sourceKind)->toBe('plain');
    expect($extracted->text)->toContain('部品を取り付ける');
    expect($extracted->byteLength)->toBe(strlen($extracted->text));
});

test('xlsx から全シートのセルを抽出する', function (): void {
    Storage::fake();
    $spreadsheet = new Spreadsheet;
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setCellValue('A1', '手順');
    $sheet->setCellValue('B1', '急所');
    $sheet->setCellValue('A2', 'ネジを締める作業を行い、工具を正しく持って対象物に当てて回す');
    $sheet->setCellValue('B2', 'トルクは 5Nm を厳守すること (締めすぎるとネジ山が潰れる)');
    $tmp = tempnam(sys_get_temp_dir(), 'sop-xlsx-');
    (new Xlsx($spreadsheet))->save($tmp);
    $document = storedDocument((string) file_get_contents($tmp), 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'xlsx');
    @unlink($tmp);

    $extracted = app(SopTextExtractor::class)->extract($document);

    expect($extracted->sourceKind)->toBe('spreadsheet');
    expect($extracted->text)->toContain('ネジを締める作業');
    expect($extracted->text)->toContain('5Nm');
});

test('SJIS-win テキストは strict 検出で UTF-8 へ変換される', function (): void {
    Storage::fake();
    $utf8 = str_repeat("手順: ネジを締める。急所: トルクは五ニュートンメートル。\n", 5);
    $sjis = mb_convert_encoding($utf8, 'SJIS-win', 'UTF-8');
    expect(is_string($sjis))->toBeTrue();
    $document = storedDocument((string) $sjis, 'text/plain', 'txt');

    $extracted = app(SopTextExtractor::class)->extract($document);

    expect(mb_check_encoding($extracted->text, 'UTF-8'))->toBeTrue();
    expect($extracted->text)->toContain('ネジを締める');
});

test('判定不能バイナリは unextractable (推測変換で LLM に渡さない)', function (): void {
    Storage::fake();
    // UTF-8 としても SJIS/EUC としても不正な連続バイト列
    $binary = str_repeat("\xFF\xFE\x80\x81\xFD", 50);
    $document = storedDocument($binary, 'text/plain', 'txt');

    expect(fn () => app(SopTextExtractor::class)->extract($document))
        ->toThrow(AnalysisFailedException::class, 'テキストを抽出できません');
});

test('実質空 (min_text_bytes 未満) は tooShort (画像未対応と別文言)', function (): void {
    Storage::fake();
    $document = storedDocument('短い', 'text/plain', 'txt');

    // 抽出はできたが本文が短すぎるケース。画像/スキャン (unextractable) とは別文言で弁別する
    expect(fn () => app(SopTextExtractor::class)->extract($document))
        ->toThrow(AnalysisFailedException::class, '本文が短すぎます');
});

test('未知 mime は従来どおり unextractable (テキストを抽出できません)', function (): void {
    Storage::fake();
    $document = storedDocument(str_repeat('内容', 100), 'image/png', 'png');

    expect(fn () => app(SopTextExtractor::class)->extract($document))
        ->toThrow(AnalysisFailedException::class, 'テキストを抽出できません');
});

test('max_text_bytes 超過は tooLarge (分割を促す)', function (): void {
    Storage::fake();
    config()->set('manual.analysis_max_text_bytes', 500);
    $document = storedDocument(str_repeat('長い手順書テキスト。', 100), 'text/plain', 'txt');

    expect(fn () => app(SopTextExtractor::class)->extract($document))
        ->toThrow(AnalysisFailedException::class, '手順書が大きすぎます');
});

test('破損 PDF (パース不能) は unextractable に正規化される', function (): void {
    Storage::fake();
    $document = storedDocument(str_repeat('%PDF-1.4 broken content without objects', 10), 'application/pdf', 'pdf');

    expect(fn () => app(SopTextExtractor::class)->extract($document))
        ->toThrow(AnalysisFailedException::class);
});
