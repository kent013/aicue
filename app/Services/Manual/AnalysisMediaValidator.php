<?php

declare(strict_types=1);

namespace App\Services\Manual;

use App\DataTransferObjects\Manual\Analysis\ImageAnalysisMediaData;
use App\DataTransferObjects\Manual\Analysis\PdfAnalysisMediaData;
use App\Exceptions\Manual\AnalysisFailedException;
use App\Models\SourceDocument;
use finfo;
use Illuminate\Support\Facades\Storage;
use Smalot\PdfParser\Parser as PdfParser;
use Throwable;
use Webmozart\Assert\Assert;

/**
 * OCR 経路 (画像・スキャン SOP の OCR 対応) の媒体検証。
 *
 * OCR 経路へ回してよい入力かどうかの判定 (容量・画素数・ページ数の上限) と、
 * 検証済み媒体 DTO の生成を 1 箇所に閉じる。**このクラスだけが**
 * `ImageAnalysisMediaData::fromValidated()` / `PdfAnalysisMediaData::fromValidated()` を
 * 呼んでよい (`PromptDefenseWindowGateTest` の `MediaDataNamedConstructorCall` ルールが pin する)。
 */
final class AnalysisMediaValidator
{
    /** @var list<string> */
    private const array SUPPORTED_IMAGE_MIMES = ['image/jpeg', 'image/png'];

    /**
     * @throws AnalysisFailedException 容量/画素数上限超過・非対応 mime・破損画像の場合
     */
    public function validateImage(SourceDocument $document): ImageAnalysisMediaData
    {
        Assert::inArray(
            $document->mime,
            self::SUPPORTED_IMAGE_MIMES,
            'AnalysisMediaValidator::validateImage は画像 mime の SourceDocument にのみ呼ぶ',
        );

        // 検証と vendor 変換は同じバイト列に対して行う (このメソッド内で 1 回だけ読む)。
        $bytes = Storage::get($document->file_path);
        Assert::string($bytes, "SOP ファイルが見つかりません: {$document->file_path}");

        $sizeBytes = strlen($bytes);
        if ($sizeBytes > config()->integer('manual.source_document_image_max_bytes')) {
            throw AnalysisFailedException::mediaTooLarge();
        }

        $size = @getimagesizefromstring($bytes);
        if ($size === false) {
            throw AnalysisFailedException::mediaUnreadable();
        }
        $width = $size[0];
        $height = $size[1];

        // persisted mime (アップロード時に sniff 済みのはずの値) と実バイトの形式が
        // 一致することをここでも確認する (例えば mime=image/jpeg のレコードが
        // 実は PNG バイトである、といった不整合を検出する)
        if ($size['mime'] !== $document->mime) {
            throw AnalysisFailedException::mediaUnreadable();
        }
        if ($width < 1 || $height < 1) {
            throw AnalysisFailedException::mediaUnreadable();
        }

        // 乗算オーバーフロー/極端な dimension を避けるため、先に辺長を検査してから
        // 除算で画素数上限を判定する ($width * $height を先に計算しない)
        $maxDimension = config()->integer('manual.analysis_ocr_max_dimension');
        if ($width > $maxDimension || $height > $maxDimension) {
            throw AnalysisFailedException::mediaTooLarge();
        }
        $maxPixels = config()->integer('manual.analysis_ocr_max_pixels');
        if ($height > intdiv($maxPixels, $width)) {
            throw AnalysisFailedException::mediaTooLarge();
        }

        return ImageAnalysisMediaData::fromValidated($document->mime, $bytes, $sizeBytes, $width, $height);
    }

    /**
     * @throws AnalysisFailedException 容量上限超過・ページ数上限超過・破損 PDF の場合
     */
    public function validatePdfForOcr(SourceDocument $document): PdfAnalysisMediaData
    {
        Assert::same(
            $document->mime,
            'application/pdf',
            'AnalysisMediaValidator::validatePdfForOcr は PDF mime の SourceDocument にのみ呼ぶ',
        );

        $bytes = Storage::get($document->file_path);
        Assert::string($bytes, "SOP ファイルが見つかりません: {$document->file_path}");

        // 画像側 (persisted mime と getimagesizefromstring() の mime 一致確認) と対称の
        // sniff 検証。実バイトが PDF であることを finfo で確認し、persisted mime を鵜呑みにしない
        // (ファイルの差し替わり・DB 不整合を検出する)。
        $sniffed = (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        if ($sniffed !== 'application/pdf') {
            throw AnalysisFailedException::mediaUnreadable();
        }

        $sizeBytes = strlen($bytes);
        if ($sizeBytes > config()->integer('manual.source_document_max_bytes')) {
            throw AnalysisFailedException::mediaTooLarge(); // 既存の 20MB 上限を OCR 経路でも適用
        }

        try {
            $pageCount = count((new PdfParser)->parseContent($bytes)->getPages());
        } catch (Throwable $exception) {
            report($exception);
            // ページ数を数えられない = 破損/未対応形式であり「大きすぎる」とは別の理由。
            // 数えられなかった場合も OCR 経路へ回さない (fail-closed。0 ページと読み替えない)
            throw AnalysisFailedException::mediaUnreadable();
        }
        if ($pageCount < 1) {
            // parseContent() 自体は成功するが有効なページが無い壊れた PDF
            throw AnalysisFailedException::mediaUnreadable();
        }
        if ($pageCount > config()->integer('manual.analysis_ocr_max_pages')) {
            throw AnalysisFailedException::mediaTooLarge();
        }

        return PdfAnalysisMediaData::fromValidated($document->mime, $bytes, $sizeBytes, $pageCount);
    }
}
