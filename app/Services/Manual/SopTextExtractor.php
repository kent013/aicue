<?php

declare(strict_types=1);

namespace App\Services\Manual;

use App\DataTransferObjects\Manual\Analysis\ExtractedText;
use App\Exceptions\Manual\AnalysisFailedException;
use App\Models\SourceDocument;
use App\Support\Manual\JapaneseTextRatio;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Smalot\PdfParser\Parser as PdfParser;
use Throwable;
use Webmozart\Assert\Assert;

/**
 * SOP (SourceDocument) からのテキスト抽出。doc/10 §10.7。
 *
 * テキスト抽出できない・日本語比率が不足する PDF は `AnalysisPipeline` が
 * `AnalysisMediaValidator` 経由の OCR 経路 (画像・スキャン SOP の OCR 対応) へ回す
 * (`manual.ocr_analysis_enabled` フラグが有効な場合のみ)。本クラスの責務は
 * あくまで「テキストを抽出できるか」の判定であり、OCR 経路の判断はここでは行わない。
 *
 * - 分岐はアップロード時に内容 sniff 済みの mime を使う (クライアント拡張子は信頼しない)
 * - 抽出不能/実質空/バイト上限超過は AnalysisFailedException (ユーザー向け文言)
 * - byteLength (strlen = UTF-8 bytes) が token budget 判定値 (config manual.analysis_max_text_bytes)
 * - SJIS 誤解釈 (pdfparser の定義済み CJK CMap 非対応) を区間単位で復元し、
 *   日本語本文が閾値未満のテキストは LLM に渡さない (manual.analysis_min_japanese_ratio)
 * - 復元は「そのままでは日本語本文ゲートで拒否される文書」にのみ適用する
 *   (既に日本語として読める文書は 1 バイトも変更しない)
 */
class SopTextExtractor
{
    /**
     * CP1252 の 256 バイトと 1:1 対応する文字だけからなる極大連続区間。
     *
     * pdfparser は定義済み CJK CMap (90ms-RKSJ-H 等) を知らないため、CP932 バイト列を
     * Windows-1252 として decode してしまう (Font::decodeContentByAutodetectIfNecessary)。
     * その化けを元バイト列へ戻せる文字集合が、この 256 文字の全単射である。
     * U+0081/008D/008F/0090/009D は CP1252 未定義バイトだが mbstring が素通しし、かつ
     * Shift_JIS の主要 lead byte (0x81 = JIS 記号行 / 0x8D / 0x8F / 0x90) なので必須。
     * BMP 全走査で「mbstring の CP1252 往復が同一になる集合」と完全一致を検証済み
     * (devnotes/20260804-0900-sop-pdf-mojibake/probe/probe-cp1252-table.php)。
     */
    private const CP1252_RUN_PATTERN = '/[\x{0000}-\x{007F}\x{0081}\x{008D}\x{008F}\x{0090}\x{009D}'
        .'\x{00A0}-\x{00FF}\x{20AC}\x{201A}\x{0192}\x{201E}\x{2026}\x{2020}\x{2021}\x{02C6}'
        .'\x{2030}\x{0160}\x{2039}\x{0152}\x{017D}\x{2018}\x{2019}\x{201C}\x{201D}\x{2022}'
        .'\x{2013}\x{2014}\x{02DC}\x{2122}\x{0161}\x{203A}\x{0153}\x{017E}\x{0178}]+/u';

    /**
     * CP932 の **2 バイト列からしか出ない**日本語文字 (JAPANESE_PATTERN から半角カナを除いたもの)。
     *
     * 半角カナ (U+FF66-FF9D) は CP932 では単バイト 0xA1-0xDF であり、これは CP1252 の
     * Latin-1 補助 (`©`=0xA9 / `±`=0xB1 / `°`=0xB0 / `À`=0xC0 …) と同じバイト帯である。
     * つまり「半角カナが増えた」ことは 2 バイト列の誤解釈の証拠にならない
     * (正当な `作業手順書 © 2026` が `作業手順書 ｩ 2026` へ壊れる。probe/probe-run-criteria.php)。
     * 区間の採否は必ずこちらで判定する。
     */
    private const MULTIBYTE_JAPANESE_PATTERN = '/[\x{3001}-\x{303F}\x{3040}-\x{30FF}\x{3400}-\x{4DBF}'
        .'\x{4E00}-\x{9FFF}\x{F900}-\x{FAFF}\x{FF01}-\x{FF60}]/u';

    /**
     * 区間を復元済みへ差し替える下限比率 = **過半数が日本語文字であること**。
     *
     * 文書ゲート (manual.analysis_min_japanese_ratio) とは問いが違う。文書ゲートは
     * 「この手順書を受け入れるか」の運用ポリシーの下限であり、こちらは
     * 「この区間が CP932 の誤解釈であると断定してよいか」の証拠の強さである。
     * 短い区間 (`créé` = 0xE9 0xE9 が偶然 CP932 の 2 バイト列として成立する等) は
     * 低い比率でも偶然通ってしまうため、文書ゲートの閾値を流用してはならない。
     * 実測 (probe/probe-run-criteria.php): 実 PDF AS の採用区間は 0.819〜0.874、
     * 正当テキストの誤発火候補は 0.20〜0.33 で、過半数 (0.50) は両者の間にある。
     */
    private const RUN_MIN_JAPANESE_RATIO = 0.50;

    /**
     * 区間を復元済みへ差し替えるのに必要な全角日本語の増加数 = **偶然の 1 件を化けと断定しない**。
     *
     * ASCII を挟まない高位バイト列 (`àé` = 0xE0 0xE9 等) は偶然 CP932 の妥当な 2 バイト列として
     * 成立し、漢字 1 文字を生むことがある。区間が短いと比率は容易に 1.0 になるため、
     * 比率だけでは弾けない (小標本では比率が証拠にならない)。
     * 実測 (probe/probe-run-criteria.php): 実 PDF AS の採用区間の増加数は 83〜1108 文字であり、
     * 「2 文字以上」は本物の化けを 1 件も落とさない。
     */
    private const RUN_MIN_MULTIBYTE_JAPANESE = 2;

    public function extract(SourceDocument $document): ExtractedText
    {
        $contents = Storage::get($document->file_path);
        Assert::string($contents, "SOP ファイルが見つかりません: {$document->file_path}");

        $kind = $this->kindFor($document->mime);
        try {
            $extracted = match ($kind) {
                'pdf' => $this->fromPdf($contents),
                'spreadsheet' => $this->fromSpreadsheet($contents),
                'plain' => $contents,
            };
        } catch (Throwable $exception) {
            // parser の内部例外はユーザー向け文言へ正規化 (詳細は report で内部ログのみ)
            report($exception);

            throw AnalysisFailedException::unextractable();
        }

        $extracted = $this->ensureUtf8($extracted); // JSON 化・UserInput 生成を不正バイトで壊さない
        $minJapaneseRatio = config()->float('manual.analysis_min_japanese_ratio');
        $ratioBefore = JapaneseTextRatio::of($extracted);

        // 復元は「そのままでは日本語本文ゲートで拒否される文書」だけを救う機構である。
        // 既に日本語として読める文書には一切触れない = 正当なテキストの不変性を
        // 統計 (区間ごとの検証) ではなく構造で保証する。
        // 区間検証をいくら厳しくしても `àéàé` のように CP932 の日本語と**バイト列が同一**で
        // 原理的に区別できない入力は残るため、その入力が意味を持たない領域へ適用範囲を閉じる。
        $repaired = $ratioBefore < $minJapaneseRatio
            ? $this->repairSjisMojibake($extracted)
            : $extracted;

        $text = $this->normalize($repaired);
        if ($repaired !== $extracted) {
            // 現場でこの化けがどれだけ起きているかを後から測れるようにする (本文は出さない)。
            // japaneseRatio は分母から空白を除くため normalize の前後で不変 = 下段のゲートと同一基準
            Log::info('SOP テキストの SJIS 誤解釈を復元しました', [
                'reason' => 'sjis_mojibake_repaired',
                'source_document_id' => $document->id,
                'source_kind' => $kind,
                'japanese_ratio_before' => round($ratioBefore, 4),
                'japanese_ratio_after' => round(JapaneseTextRatio::of($text), 4),
            ]);
        }

        $bytes = strlen($text);
        if ($bytes === 0 && $kind === 'pdf') {
            // PDF から 1 バイトも取れない = 文字が画像 (スキャン手順書)。
            // plain / spreadsheet の空ファイルは原因が違うので tooShort のままにする
            throw AnalysisFailedException::unextractable();
        }
        if ($bytes < config()->integer('manual.analysis_min_text_bytes')) {
            throw AnalysisFailedException::tooShort(); // 短い有効テキスト → 画像未対応と別文言
        }
        if ($bytes > config()->integer('manual.analysis_max_text_bytes')) {
            throw AnalysisFailedException::tooLarge();
        }

        $ratio = JapaneseTextRatio::of($text);
        if ($ratio < $minJapaneseRatio) {
            Log::info('SOP テキストの日本語本文が不足しています', [
                'reason' => 'insufficient_japanese_text',
                'source_document_id' => $document->id,
                'source_kind' => $kind,
                'japanese_ratio' => round($ratio, 4),
                'byte_length' => $bytes,
            ]);

            throw AnalysisFailedException::insufficientJapaneseText();
        }

        return new ExtractedText($text, $bytes, $kind);
    }

    /**
     * CP932 バイト列を Windows-1252 として解釈された文字列 (mojibake) の復元。
     *
     * CP1252 レパートリ内の**極大連続区間**だけを単位に読み直す。区間外の文字
     * (= 正しく decode された日本語。AS_作業手順書.pdf では隠し OCR 層由来の 63 文字)
     * には一切触れないため、混在文書でも既存の正しいテキストを壊さない。
     *
     * 呼び出しは「日本語本文ゲートで拒否される文書」に限る (extract() の前提条件)。
     */
    private function repairSjisMojibake(string $text): string
    {
        $repaired = preg_replace_callback(
            self::CP1252_RUN_PATTERN,
            fn (array $matches): string => $this->decodeRunAsSjis((string) $matches[0]),
            $text,
        );

        return is_string($repaired) ? $repaired : $text;
    }

    /**
     * 1 区間を SJIS-win として読み直す。4 つの検証をすべて満たしたときだけ置換し、
     * 1 つでも欠けたら原文をそのまま返す (推測変換をしない)。
     *   1. CP1252 へ可逆に戻せる (= この区間が CP1252 誤解釈由来である)
     *   2. 得たバイト列が SJIS-win として妥当である
     *   3. 復号で **2 バイト列由来の**日本語が RUN_MIN_MULTIBYTE_JAPANESE 文字以上増える
     *   4. 復号結果の過半数が日本語文字である (RUN_MIN_JAPANESE_RATIO)
     */
    private function decodeRunAsSjis(string $run): string
    {
        // encoding 名がリテラルのため mb_convert_encoding は string を返す (不正名は ValueError)
        $bytes = mb_convert_encoding($run, 'CP1252', 'UTF-8');
        if (mb_convert_encoding($bytes, 'UTF-8', 'CP1252') !== $run) {
            return $run;
        }
        if (! mb_check_encoding($bytes, 'SJIS-win')) {
            return $run;
        }

        $decoded = mb_convert_encoding($bytes, 'UTF-8', 'SJIS-win');
        if (! mb_check_encoding($decoded, 'UTF-8')) {
            return $run;
        }
        // 半角カナ (CP932 では単バイト 0xA1-0xDF = CP1252 の Latin-1 補助と同じ帯) の増加は
        // 2 バイト列誤解釈の証拠にならないため、採否の判定からは除く。
        // また 1 文字だけの増加は偶然成立した 2 バイト列でも起きるため証拠として採らない
        $gained = $this->countBy(self::MULTIBYTE_JAPANESE_PATTERN, $decoded)
            - $this->countBy(self::MULTIBYTE_JAPANESE_PATTERN, $run);
        if ($gained < self::RUN_MIN_MULTIBYTE_JAPANESE) {
            return $run;
        }

        // 偶然 CP932 として成立しただけの短い区間を弾く (過半数が日本語文字であることを要求)
        return JapaneseTextRatio::of($decoded) >= self::RUN_MIN_JAPANESE_RATIO ? $decoded : $run;
    }

    /** パターンに一致する文字数 (SJIS 復元判定専用。文書ゲート側は JapaneseTextRatio が持つ) */
    private function countBy(string $pattern, string $text): int
    {
        $count = preg_match_all($pattern, $text);

        return is_int($count) ? $count : 0;
    }

    /**
     * mime → 抽出方式。未知 mime はアップロード時 sniff で弾かれている前提だが、
     * 防御的に unextractable で落とす (LLM に渡さない)。
     *
     * ★ 画像 mime (image/jpeg・image/png) もここでは default 分岐 (unextractable) に落ちる。
     *   これは呼び出し元 (`AnalysisPipeline::resolveExtractInput()`) が OCR 有効時に
     *   画像をそもそもここへ渡さず `AnalysisMediaValidator::validateImage()` へ直接回すためで、
     *   この default 分岐は「フラグ無効時」「OCR 未対応の呼び出し元」に対する防御である。
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
            // 書き込み失敗 (ディスクフル等) を IOFactory の後段例外に依存せず明示検出する
            Assert::integer(file_put_contents($path, $contents), '一時ファイルへ書き込めません');
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
