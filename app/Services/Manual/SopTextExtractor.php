<?php

declare(strict_types=1);

namespace App\Services\Manual;

use App\DataTransferObjects\Manual\Analysis\ExtractedText;
use App\Exceptions\Manual\AnalysisFailedException;
use App\Models\SourceDocument;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Smalot\PdfParser\Parser as PdfParser;
use Throwable;
use Webmozart\Assert\Assert;

/**
 * SOP (SourceDocument) からのテキスト抽出。doc/10 §10.7 (v1: テキスト抽出可能な手順書のみ)。
 *
 * - 分岐はアップロード時に内容 sniff 済みの mime を使う (クライアント拡張子は信頼しない)
 * - 抽出不能/実質空/バイト上限超過は AnalysisFailedException (ユーザー向け文言)
 * - byteLength (strlen = UTF-8 bytes) が token budget 判定値 (config manual.analysis_max_text_bytes)
 */
class SopTextExtractor
{
    public function extract(SourceDocument $document): ExtractedText
    {
        $contents = Storage::get($document->file_path);
        Assert::string($contents, "SOP ファイルが見つかりません: {$document->file_path}");

        $kind = $this->kindFor($document->mime);
        try {
            $text = match ($kind) {
                'pdf' => $this->fromPdf($contents),
                'spreadsheet' => $this->fromSpreadsheet($contents),
                'plain' => $contents,
            };
        } catch (Throwable $exception) {
            // parser の内部例外はユーザー向け文言へ正規化 (詳細は report で内部ログのみ)
            report($exception);

            throw AnalysisFailedException::unextractable();
        }

        $text = $this->ensureUtf8($text); // JSON 化・UserInput 生成を不正バイトで壊さない
        $text = $this->normalize($text);

        $bytes = strlen($text);
        if ($bytes < config()->integer('manual.analysis_min_text_bytes')) {
            throw AnalysisFailedException::unextractable(); // 画像/スキャン → v1 未対応の明示文言
        }
        if ($bytes > config()->integer('manual.analysis_max_text_bytes')) {
            throw AnalysisFailedException::tooLarge();
        }

        return new ExtractedText($text, $bytes, $kind);
    }

    /**
     * mime → 抽出方式。未知 mime はアップロード時 sniff で弾かれている前提だが、
     * 防御的に unextractable で落とす (LLM に渡さない)。
     *
     * @return 'pdf'|'spreadsheet'|'plain'
     */
    private function kindFor(string $mime): string
    {
        return match ($mime) {
            'application/pdf' => 'pdf',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.ms-excel' => 'spreadsheet',
            'text/plain' => 'plain',
            default => throw AnalysisFailedException::unextractable(),
        };
    }

    private function fromPdf(string $contents): string
    {
        return (new PdfParser)->parseContent($contents)->getText();
    }

    /**
     * PhpSpreadsheet はファイルパス入力のため一時ファイル経由で読み込む (finally で削除)。
     * 全シートのセルをタブ/改行結合する。
     */
    private function fromSpreadsheet(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'sop-xls-');
        Assert::string($path, '一時ファイルを作成できません');
        try {
            file_put_contents($path, $contents);
            $spreadsheet = IOFactory::load($path);

            $lines = [];
            foreach ($spreadsheet->getAllSheets() as $sheet) {
                $title = $sheet->getTitle();
                if ($title !== '') {
                    $lines[] = $title;
                }
                foreach ($sheet->getRowIterator() as $row) {
                    $cells = [];
                    $cellIterator = $row->getCellIterator();
                    $cellIterator->setIterateOnlyExistingCells(true);
                    /** @var Cell $cell */
                    foreach ($cellIterator as $cell) {
                        $value = $cell->getFormattedValue();
                        if (trim($value) !== '') {
                            $cells[] = $value;
                        }
                    }
                    if ($cells !== []) {
                        $lines[] = implode("\t", $cells);
                    }
                }
            }

            return implode("\n", $lines);
        } finally {
            @unlink($path);
        }
    }

    /**
     * UTF-8 妥当性の担保 (旧 XLS の SJIS 系・PDF の壊れた埋め込み対策)。
     * 推測変換で未知バイナリを「日本語らしき無意味文字列」へ化けさせない strict 手順:
     *   1. mb_check_encoding OK → そのまま
     *   2. NG → mb_detect_encoding (UTF-8/SJIS-win/EUC-JP、strict)。判定不能 → unextractable
     *   3. 判定 encoding から mb_convert_encoding → 再検証。不合格 → unextractable
     */
    private function ensureUtf8(string $text): string
    {
        if (mb_check_encoding($text, 'UTF-8')) {
            return $text;
        }

        $detected = mb_detect_encoding($text, ['UTF-8', 'SJIS-win', 'EUC-JP'], true);
        if ($detected === false) {
            throw AnalysisFailedException::unextractable(); // バイナリ扱い (救済変換しない)
        }

        $converted = mb_convert_encoding($text, 'UTF-8', $detected);
        if (! is_string($converted) || ! mb_check_encoding($converted, 'UTF-8')) {
            throw AnalysisFailedException::unextractable();
        }

        return $converted;
    }

    /** 連続空白の圧縮 + trim (LLM 入力バイト数を無駄にしない) */
    private function normalize(string $text): string
    {
        // 行内の連続空白 (タブ含む) を 1 個へ、3 行以上の連続改行を 2 行へ圧縮する
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\n{3,}/u', "\n\n", str_replace("\r\n", "\n", $text)) ?? $text;

        return trim($text);
    }
}
