<?php

declare(strict_types=1);

namespace App\Services\Render;

use App\DataTransferObjects\Manual\Render\RenderClipSpec;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Webmozart\Assert\Assert;

/**
 * カット 1 枚分の ASS 字幕ファイルを生成する (概念設計 §6 の安全境界)。
 *
 * - 字幕本文は filtergraph に直接埋めない (このクラスが唯一の字幕テキスト出力点)
 * - 正規化 (normalizeDialogueText)。**順序が本質** (入力由来のバックスラッシュ無効化 →
 *   改行変換の順に固定。逆にすると入力リテラル `\N` の無効化が生成済み ASS 改行まで潰す):
 *   1. 入力中のリテラル `\N` `\n` `\h` を無効化 (バックスラッシュを全角 `＼` に置換)
 *   2. 改行の正規化: CRLF / 単独 CR → LF
 *   3. 正規化済み LF → `\N` (ASS 改行。ここで生成した \N は以降の手順で触らない)
 *   4. `{` `}` → 全角 `｛` `｝` に置換 (ASS override tag {\...} 注入の無効化)
 *   5. 不可視制御文字の包括除去: C0/C1 制御文字 (LF は手順 3 で消費済み) + zero-width 系
 *      (U+200B-200F, U+202A-202E, U+2060-2064, U+FEFF)
 *   6. 長さ上限: 1 行 (手順 3 の \N 分割単位) 最大 100 文字・総文字数最大 500 文字。
 *      切り詰めは mb_substr のマルチバイト安全 API で行い (UTF-8 途中切断の禁止)、
 *      超過は切り詰め + 構造化ログ (subtitle_secondary は text 型のため防御必須)
 *   7. 出力ファイルは BOM なし UTF-8 固定 (BOM/エンコーディング検出に依存しない)
 * - スタイル: subtitle_secondary = 画面下部 (メイン)、subtitle_primary = 上部帯 (名称・数値)。
 *   フォントは config manual.render_subtitle_font
 */
class AssSubtitleWriter
{
    /** 1 行の最大文字数 (手順 6) */
    private const int MAX_LINE_CHARS = 100;

    /** 総文字数の最大 (手順 6) */
    private const int MAX_TOTAL_CHARS = 500;

    public function write(RenderClipSpec $clip, int $durationMs, string $filePath): void
    {
        $endTime = $this->formatTime($durationMs);
        [$width, $height] = $this->resolution();
        $font = config()->string('manual.render_subtitle_font');
        // Style 行はカンマ区切りのため、フォント名 (サーバ config 値) のカンマ・改行は落とす
        $font = str_replace([',', "\r", "\n"], '', $font);

        $lines = [
            '[Script Info]',
            'ScriptType: v4.00+',
            "PlayResX: {$width}",
            "PlayResY: {$height}",
            'WrapStyle: 0',
            'ScaledBorderAndShadow: yes',
            '',
            '[V4+ Styles]',
            'Format: Name, Fontname, Fontsize, PrimaryColour, SecondaryColour, OutlineColour, BackColour, Bold, Italic, Underline, StrikeOut, ScaleX, ScaleY, Spacing, Angle, BorderStyle, Outline, Shadow, Alignment, MarginL, MarginR, MarginV, Encoding',
            // secondary = 画面下部 (メイン。Alignment 2 = 下中央)
            "Style: Secondary,{$font},56,&H00FFFFFF,&H000000FF,&H00101010,&H80000000,0,0,0,0,100,100,0,0,1,3,1,2,60,60,48,1",
            // primary = 上部帯 (名称・数値の強調。Alignment 8 = 上中央)
            "Style: Primary,{$font},64,&H0000FFFF,&H000000FF,&H00101010,&H80000000,1,0,0,0,100,100,0,0,1,3,1,8,60,60,36,1",
            '',
            '[Events]',
            'Format: Layer, Start, End, Style, Name, MarginL, MarginR, MarginV, Effect, Text',
        ];

        $secondary = $this->normalizeDialogueText($clip->subtitleSecondary, $clip->cutId, 'subtitle_secondary');
        if ($secondary !== '') {
            $lines[] = "Dialogue: 0,0:00:00.00,{$endTime},Secondary,,0,0,0,,{$secondary}";
        }
        if ($clip->subtitlePrimary !== null) {
            $primary = $this->normalizeDialogueText($clip->subtitlePrimary, $clip->cutId, 'subtitle_primary');
            if ($primary !== '') {
                $lines[] = "Dialogue: 0,0:00:00.00,{$endTime},Primary,,0,0,0,,{$primary}";
            }
        }

        // BOM なし UTF-8 固定 (手順 7)
        $written = file_put_contents($filePath, implode("\n", $lines)."\n");
        if ($written === false) {
            throw new RuntimeException("ASS 字幕ファイルを書き込めません: {$filePath}");
        }
    }

    /**
     * 字幕本文の ASS 向け正規化 (docblock の手順 1〜6。順序固定)。
     */
    public function normalizeDialogueText(string $text, int $cutId, string $field): string
    {
        // 1. 入力由来のバックスラッシュを全角へ (リテラル \N \n \h の無効化)
        $text = str_replace('\\', '＼', $text);

        // 2. 改行の正規化 (CRLF / 単独 CR → LF)
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        // 3. LF → ASS 改行 \N (ここで生成した \N は以降の手順で触らない)
        $text = str_replace("\n", '\\N', $text);

        // 4. ASS override tag ({\...}) 注入の無効化
        $text = str_replace(['{', '}'], ['｛', '｝'], $text);

        // 5. 不可視制御文字の包括除去 (C0/C1 + DEL + zero-width 系)
        $cleaned = preg_replace(
            '/[\x{0000}-\x{001F}\x{007F}\x{0080}-\x{009F}\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2064}\x{FEFF}]/u',
            '',
            $text,
        );
        Assert::string($cleaned, '制御文字除去の正規表現が失敗しました');
        $text = $cleaned;

        // 6. 長さ上限 (行 100 文字 / 総 500 文字。mb_substr のマルチバイト安全な切り詰め)
        return $this->truncate($text, $cutId, $field);
    }

    /**
     * \N 分割単位での行切り詰め + 総文字数上限 (超過は構造化ログ)。
     * 生成済み \N シーケンスを途中切断しないため、切り詰めは行内容に対してのみ行う。
     */
    private function truncate(string $text, int $cutId, string $field): string
    {
        $lines = explode('\\N', $text);
        $truncated = false;

        $kept = [];
        $total = 0;
        foreach ($lines as $line) {
            if (mb_strlen($line) > self::MAX_LINE_CHARS) {
                $line = mb_substr($line, 0, self::MAX_LINE_CHARS);
                $truncated = true;
            }
            $remaining = self::MAX_TOTAL_CHARS - $total;
            if ($remaining <= 0) {
                $truncated = true;
                break;
            }
            if (mb_strlen($line) > $remaining) {
                $line = mb_substr($line, 0, $remaining);
                $truncated = true;
            }
            $kept[] = $line;
            $total += mb_strlen($line);
        }

        if ($truncated) {
            Log::warning('render subtitle truncated', [
                'cut_id' => $cutId,
                'field' => $field,
                'original_chars' => mb_strlen($text),
            ]);
        }

        return implode('\\N', $kept);
    }

    /** ASS 時刻表記 (H:MM:SS.CC) */
    private function formatTime(int $durationMs): string
    {
        $centiseconds = intdiv($durationMs, 10);
        $hours = intdiv($centiseconds, 360_000);
        $minutes = intdiv($centiseconds % 360_000, 6_000);
        $seconds = intdiv($centiseconds % 6_000, 100);
        $centi = $centiseconds % 100;

        return sprintf('%d:%02d:%02d.%02d', $hours, $minutes, $seconds, $centi);
    }

    /**
     * @return array{int, int} [width, height]
     */
    private function resolution(): array
    {
        $resolution = config()->string('manual.render_resolution');
        $parts = explode('x', $resolution);
        Assert::count($parts, 2, 'manual.render_resolution は WxH 形式で設定してください');

        return [(int) $parts[0], (int) $parts[1]];
    }
}
