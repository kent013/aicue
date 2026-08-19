<?php

declare(strict_types=1);

namespace Tests\Support\Manual;

/**
 * テスト用の最小 PDF バイト列を組み立てる (画像・スキャン SOP の OCR 対応。
 * `AnalysisMediaValidator` / OCR パイプラインテストが使うページ数の異なる fixture)。
 * smalot/pdfparser で妥当に parse できる最小限の xref テーブル付き構造を手組みする。
 */
final class MinimalPdfFixture
{
    /** 指定ページ数のテキスト層を持たない最小 PDF を作る (pageCount >= 1)。 */
    public static function withPages(int $pageCount): string
    {
        $objects = [];
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $kids = [];
        for ($i = 0; $i < $pageCount; $i++) {
            $kids[] = (3 + $i).' 0 R';
        }
        $objects[2] = '<< /Type /Pages /Kids ['.implode(' ', $kids)."] /Count {$pageCount} >>";
        for ($i = 0; $i < $pageCount; $i++) {
            $objects[3 + $i] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << >> >>';
        }

        return self::assemble($objects);
    }

    /** parseContent() 自体は成功するがページが 0 件の壊れた PDF。 */
    public static function withZeroPages(): string
    {
        return self::assemble([
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [] /Count 0 >>',
        ]);
    }

    /** parseContent() 自体が例外になる破損 PDF (xref が無い)。 */
    public static function corrupt(): string
    {
        return "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";
    }

    /**
     * @param  array<int, string>  $objects
     */
    private static function assemble(array $objects): string
    {
        ksort($objects);

        $out = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $num => $body) {
            $offsets[$num] = strlen($out);
            $out .= "{$num} 0 obj\n{$body}\nendobj\n";
        }
        $xrefStart = strlen($out);
        $maxNum = max(array_keys($objects));
        $out .= 'xref'."\n".'0 '.($maxNum + 1)."\n";
        $out .= "0000000000 65535 f \n";
        for ($i = 1; $i <= $maxNum; $i++) {
            $offset = $offsets[$i] ?? 0;
            $out .= sprintf("%010d 00000 n \n", $offset);
        }
        $out .= 'trailer'."\n".'<< /Size '.($maxNum + 1)." /Root 1 0 R >>\nstartxref\n{$xrefStart}\n%%EOF";

        return $out;
    }
}
