<?php

declare(strict_types=1);

namespace Tests\Support\Manual;

/**
 * テスト用の最小画像バイト列を組み立てる (画像・スキャン SOP の OCR 対応)。
 *
 * この開発環境の GD ビルドは JPEG エンコード関数 (`imagejpeg()`) を持たない
 * (`gd_info()['JPEG Support']` が false)。`getimagesizefromstring()` は SOF0 マーカーの
 * 幅・高さを読めれば足りるため、エントロピー符号化データを持たない最小 JPEG を
 * 手組みして幅・高さを自由に制御する。PNG は GD (`imagepng()`) でそのまま生成できる。
 */
final class MinimalImageFixture
{
    /** 指定の幅・高さを持つ最小 baseline JPEG (グレースケール 1 成分)。 */
    public static function jpeg(int $width, int $height): string
    {
        $out = "\xFF\xD8"; // SOI
        $app0 = "JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00";
        $out .= "\xFF\xE0".pack('n', strlen($app0) + 2).$app0;

        $sof = pack('C', 8).pack('n', $height).pack('n', $width).pack('C', 1);
        $sof .= pack('C', 1).pack('C', 0x11).pack('C', 0); // component id / sampling / qtable
        $out .= "\xFF\xC0".pack('n', strlen($sof) + 2).$sof;

        $out .= "\xFF\xD9"; // EOI

        return $out;
    }

    /** 指定の幅・高さを持つ PNG (GD `imagepng()` 生成)。 */
    public static function png(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        if ($image === false) {
            throw new \RuntimeException('imagecreatetruecolor に失敗しました');
        }

        ob_start();
        imagepng($image);
        $bytes = ob_get_clean();
        imagedestroy($image);

        if (! is_string($bytes)) {
            throw new \RuntimeException('imagepng の出力を取得できません');
        }

        return $bytes;
    }
}
