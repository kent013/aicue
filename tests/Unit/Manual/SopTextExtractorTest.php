<?php

declare(strict_types=1);

use App\Exceptions\Manual\AnalysisFailedException;
use App\Models\SourceDocument;
use App\Services\Manual\SopTextExtractor;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Webmozart\Assert\Assert;

/*
 * SOP テキスト抽出 (施策 7):
 * - plain / xlsx の抽出、UTF-8 strict 検証 (SJIS 変換 / バイナリ拒否)
 * - 実質空 (min_text_bytes 未満) / バイト上限超過の明示エラー
 * - SJIS 誤解釈 (pdfparser の CJK CMap 非対応) の区間単位復元 / 日本語本文ゲート (T096)
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

/**
 * 同梱サンプル SOP の中身 (回帰コーパス)。
 * fixture を複製せず参照するため、欠落は黙ってスキップせず明示的に失敗させる。
 */
function sampleSopContents(string $name): string
{
    $path = base_path("doc/reference/sample-sop/{$name}");
    $contents = file_exists($path) ? file_get_contents($path) : false;
    if (! is_string($contents)) {
        throw new RuntimeException("回帰コーパスのサンプル SOP を読めません: {$path}");
    }

    return $contents;
}

/** CP932 バイト列を Windows-1252 として読んだときの化け (pdfparser が返すもの) を合成する */
function sjisMojibake(string $japanese): string
{
    $sjis = mb_convert_encoding($japanese, 'CP932', 'UTF-8');
    Assert::string($sjis);
    $mojibake = mb_convert_encoding($sjis, 'UTF-8', 'CP1252');
    Assert::string($mojibake);

    return $mojibake;
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

/*
 * T096: SJIS 誤解釈の区間単位復元 + 日本語本文ゲート
 */

test('CP1252 として読まれた SJIS テキストは日本語へ復元される', function (): void {
    Storage::fake();
    $document = storedDocument(
        sjisMojibake(str_repeat('作業手順書 ネジを締める 安全確認 保護メガネ着用。', 5)),
        'text/plain',
        'txt',
    );

    $extracted = app(SopTextExtractor::class)->extract($document);

    expect($extracted->text)->toContain('ネジを締める');
    expect($extracted->text)->toContain('保護メガネ');
    expect($extracted->byteLength)->toBe(strlen($extracted->text));
});

test('正当な日本語テキストは 1 文字も変化しない', function (): void {
    Storage::fake();
    // normalize() で変化しない形 (連続空白・連続改行・前後空白なし) にして「復元による誤変換ゼロ」を固定する
    $text = "作業手順書\n1. ネジを締める (トルク 5Nm)\n2. カバーを取り付ける\n"
        ."3. 動作確認を行う\n安全: 保護メガネと手袋を着用する";
    $document = storedDocument($text, 'text/plain', 'txt');

    $extracted = app(SopTextExtractor::class)->extract($document);

    expect($extracted->text)->toBe($text);
});

/*
 * 復元段は媒体非依存で、正当なテキストの不変性は「適用範囲」(日本語本文ゲート未満の文書だけを救う)
 * と「区間ごとの検証」(CP1252 可逆 / SJIS-win 妥当 / 全角日本語が 2 文字以上増える / 過半数が日本語)
 * の 2 段で守る。正当な日本語手順書に CP1252 文字が混ざっても 1 文字も変えないことを固定する。
 * 特に © (0xA9) / ± (0xB1) / ° (0xB0) は CP932 では半角カナの単バイト帯に写るため、
 * 半角カナを採否の根拠にすると正当テキストが壊れる (Codex impl-review round 1〜4 の回帰。
 * 実測は probe/probe-mixed-latin.php / probe/probe-run-criteria.php)。
 */
test('正当な日本語に CP1252 文字が混在しても復元は発火しない (非 PDF)', function (string $text): void {
    Storage::fake();
    $document = storedDocument($text, 'text/plain', 'txt');

    $extracted = app(SopTextExtractor::class)->extract($document);

    expect($extracted->text)->toBe($text);
})->with([
    'アクセント記号 (é ö ß à € ™)' => ["作業手順書 (Café ラインの Größe 点検)\n"
        ."1. ネジを締める。トルクは 5Nm とする。\n"
        ."2. カバーを取り付ける。€120 の部材と ™ 表示を確認する。\n"
        .'備考: à la carte の設備は対象外とする。'],
    '著作権表記 (© = 半角カナ帯)' => ['作業手順書 © 2026 株式会社サンプル 無断転載を禁ず。'
        .'ネジを締める。安全確認を徹底すること。保護メガネを着用する。'],
    '単位記号 (° ± = 半角カナ帯)' => ['作業手順書 温度は 25° 前後、公差は ±0.5mm とする。'
        .'ネジを締める。安全確認を徹底すること。保護メガネを着用する。'],
    '仏語 créé (偶然 CP932 の 2 バイト列として成立する)' => ['作業手順書 この設備は 2020 年に créé された。'
        .'ネジを締める。安全確認を徹底すること。保護メガネを着用する。'],
    // ASCII を挟まない高位バイト列は区間が短いため比率が 1.0 になりうる。
    // 全角日本語の増加 2 文字以上を要求することで弾く (Codex impl-review round 3 の回帰)
    'ASCII を挟まない高位バイト列 àé' => ['研削àé作業の手順書。ネジを締める。'
        .'安全確認を徹底すること。保護メガネを着用する。'],
    'ASCII を挟まない高位バイト列 Àéé' => ['研削Àéé作業の手順書。ネジを締める。'
        .'安全確認を徹底すること。保護メガネを着用する。'],
    'ASCII を挟まない高位バイト列 ©éé' => ['研削©éé作業の手順書。ネジを締める。'
        .'安全確認を徹底すること。保護メガネを着用する。'],
    // CP932 の日本語とバイト列が同一になる列 (àé×N = 琺×N) は区間検証では原理的に弁別できない。
    // 復元をゲート未満の文書に限ることで、日本語として読める文書では発火しない
    // (Codex impl-review round 4 の回帰)
    'CP932 と同一バイト列 àéàé' => ['研削àéàé作業の手順書。ネジを締める。'
        .'安全確認を徹底すること。保護メガネを着用する。'],
    'CP932 と同一バイト列 àéàéàé' => ['研削àéàéàé作業の手順書。ネジを締める。'
        .'安全確認を徹底すること。保護メガネを着用する。'],
]);

test('日本語として読める文書には復元を適用しない (ゲート未満の文書だけを救う)', function (): void {
    Storage::fake();
    // 日本語比率がゲート閾値以上の文書は、CP932 として妥当な区間を含んでいても 1 バイトも変えない。
    // 区間検証の強度ではなく適用範囲で正当テキストの不変性を保証していることを固定する
    $text = '作業手順書の点検項目。'.sjisMojibake('ネジを締める安全確認保護メガネ着用')
        .'。安全確認を徹底すること。保護メガネを着用する。手順を遵守すること。';
    $document = storedDocument($text, 'text/plain', 'txt');

    $extracted = app(SopTextExtractor::class)->extract($document);

    expect($extracted->text)->toBe($text);
});

/*
 * 区間の採用比率 (RUN_MIN_JAPANESE_RATIO = 過半数) を区間単位で直接固定する。
 * 化けた `作業` (全角 2 文字) に ASCII を足して区間の日本語比率を境界の上下へ振る。
 */
test('区間の日本語比率が過半数未満なら復元しない (境界: 下側)', function (): void {
    Storage::fake();
    // 文書全体はゲート未満 (= 復元の対象) にしたうえで、区間の復元後比率を
    // 日本語 30 文字 / (30 + ASCII 45) = 0.40 にする。'。' は区間の境界を作るためだけに置く
    $text = '。'.sjisMojibake(str_repeat('作業', 15)).str_repeat('a', 45).'。';
    $document = storedDocument($text, 'text/plain', 'txt');

    // 区間が採用されないため日本語は現れず、日本語本文ゲートで拒否される
    expect(fn () => app(SopTextExtractor::class)->extract($document))
        ->toThrow(AnalysisFailedException::class, '十分な日本語の本文');
});

test('区間の日本語比率が過半数以上なら復元する (境界: 上側)', function (): void {
    Storage::fake();
    // ASCII を 30 文字に減らすと区間の復元後比率は 30 / (30 + 30) = 0.50 となり採用される
    $text = '。'.sjisMojibake(str_repeat('作業', 15)).str_repeat('a', 30).'。';
    $document = storedDocument($text, 'text/plain', 'txt');

    $extracted = app(SopTextExtractor::class)->extract($document);

    expect($extracted->text)->toContain('作業作業');
});

test('正当な欧文テキストは復元されず日本語不足で拒否される', function (string $text): void {
    Storage::fake();
    // いずれも analysis_min_text_bytes (100) 以上にして tooShort と競合させない
    expect(strlen($text))->toBeGreaterThanOrEqual(100);
    $document = storedDocument($text, 'text/plain', 'txt');

    expect(fn () => app(SopTextExtractor::class)->extract($document))
        ->toThrow(AnalysisFailedException::class, '十分な日本語の本文');
})->with([
    'en' => ['Work Instruction: 1. Tighten the screw to 5Nm. 2. Attach the cover plate. 3. Check the operation before use.'],
    'de' => ['Arbeitsanweisung: Schraube mit 5Nm anziehen. Größe prüfen. Für die Straße. Öl nachfüllen. Weiß markieren.'],
    'fr' => ['Mode opératoire: Serrer la vis à 5 Nm. Vérifier la référence arrière. Côté gauche. Contrôler après usage.'],
]);

test('復元は混在文書の正規日本語を壊さない', function (): void {
    Storage::fake();
    // 正しく decode された日本語 (AS の隠し OCR 層に相当。実 PDF では 3292 文字中 63 文字) と
    // mojibake の混在。復元前の日本語比率がゲート閾値未満である = 復元が必要な文書である
    $document = storedDocument(
        '非鉄金属'.sjisMojibake(str_repeat('作業手順書 ネジを締める 安全確認 保護メガネ着用', 4)),
        'text/plain',
        'txt',
    );

    $extracted = app(SopTextExtractor::class)->extract($document);

    expect($extracted->text)->toContain('非鉄金属');
    expect($extracted->text)->toContain('ネジを締める');
});

test('日本語比率が閾値未満のテキストは拒否される (境界: 下側)', function (): void {
    Storage::fake();
    config()->set('manual.analysis_min_japanese_ratio', 0.10);
    // 空白を除く 100 文字中 日本語 9 文字 = 0.09
    $document = storedDocument(str_repeat('A', 91).'安全確認手順書作業', 'text/plain', 'txt');

    expect(fn () => app(SopTextExtractor::class)->extract($document))
        ->toThrow(AnalysisFailedException::class, '十分な日本語の本文');
});

test('日本語比率が閾値以上のテキストは通る (境界: 上側)', function (): void {
    Storage::fake();
    config()->set('manual.analysis_min_japanese_ratio', 0.10);
    // 空白を除く 100 文字中 日本語 11 文字 = 0.11
    $document = storedDocument(str_repeat('A', 89).'安全確認手順書作業前点', 'text/plain', 'txt');

    $extracted = app(SopTextExtractor::class)->extract($document);

    expect($extracted->sourceKind)->toBe('plain');
});

test('抽出結果が空の PDF は unextractable (tooShort と弁別)', function (): void {
    Storage::fake();
    $document = storedDocument(sampleSopContents('AP_オペレーション手順書.pdf'), 'application/pdf', 'pdf');

    expect(fn () => app(SopTextExtractor::class)->extract($document))
        ->toThrow(AnalysisFailedException::class, 'テキストを抽出できません');
});

test('空の text/plain は tooShort (画像未対応と弁別)', function (): void {
    Storage::fake();
    $document = storedDocument('', 'text/plain', 'txt');

    expect(fn () => app(SopTextExtractor::class)->extract($document))
        ->toThrow(AnalysisFailedException::class, '本文が短すぎます');
});

test('空の Spreadsheet は tooShort', function (): void {
    Storage::fake();
    $spreadsheet = new Spreadsheet;
    $tmp = tempnam(sys_get_temp_dir(), 'sop-xlsx-');
    Assert::string($tmp);
    (new Xlsx($spreadsheet))->save($tmp);
    $contents = file_get_contents($tmp);
    @unlink($tmp);
    Assert::string($contents, "一時 xlsx を読めません: {$tmp}"); // 失敗を空文字へ潰さない
    $document = storedDocument(
        $contents,
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'xlsx',
    );

    expect(fn () => app(SopTextExtractor::class)->extract($document))
        ->toThrow(AnalysisFailedException::class, '本文が短すぎます');
});

test('同梱サンプル SOP 5 本の抽出結果は期待値表どおりである', function (string $file, ?string $expectedError): void {
    Storage::fake();
    $document = storedDocument(sampleSopContents($file), 'application/pdf', 'pdf');

    if ($expectedError !== null) {
        expect(fn () => app(SopTextExtractor::class)->extract($document))
            ->toThrow(AnalysisFailedException::class, $expectedError);

        return;
    }

    $extracted = app(SopTextExtractor::class)->extract($document);
    expect($extracted->sourceKind)->toBe('pdf');
    expect($extracted->text)->toContain('グラインダー研削作業');
    expect($extracted->text)->toContain('保護メガネ');
})->with([
    ['AP_オペレーション手順書.pdf', 'テキストを抽出できません'],
    ['AT_作業手順書.pdf', 'テキストを抽出できません'],
    ['作業要領書.pdf', 'テキストを抽出できません'],
    ['AW_作業手順書 (1).pdf', '十分な日本語の本文'],
    ['AS_作業手順書.pdf', null],
]);
