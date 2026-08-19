<?php

declare(strict_types=1);

use App\Enums\Manual\AnalysisFailureReason;
use App\Exceptions\Manual\AnalysisFailedException;

/*
 * AnalysisFailedException の reason enum 化 (画像・スキャン SOP の OCR 対応)。
 * 各 named constructor が正しい reason を持つことを、AnalysisFailureReason::cases() を
 * dataset にした完全一致検査で固定する (新しい case を追加したときにテスト対象から漏れない)。
 */

/** named constructor 名 => 期待する reason */
function analysisFailedExceptionConstructorMap(): array
{
    return [
        'unextractable' => AnalysisFailureReason::Unextractable,
        'tooShort' => AnalysisFailureReason::TooShort,
        'insufficientJapaneseText' => AnalysisFailureReason::InsufficientJapaneseText,
        'tooLarge' => AnalysisFailureReason::TooLarge,
        'timedOut' => AnalysisFailureReason::TimedOut,
        'providerBusy' => AnalysisFailureReason::ProviderBusy,
        'unsafeResponse' => AnalysisFailureReason::UnsafeResponse,
        'unreadableEncoding' => AnalysisFailureReason::UnreadableEncoding,
        'mediaUnreadable' => AnalysisFailureReason::MediaUnreadable,
        'mediaTooLarge' => AnalysisFailureReason::MediaTooLarge,
        'ocrEmptyOrInvalid' => AnalysisFailureReason::OcrEmptyOrInvalid,
    ];
}

test('AnalysisFailureReason の全 case が named constructor に対応している (漏れ検出)', function (): void {
    $map = analysisFailedExceptionConstructorMap();
    $mappedReasons = array_map(static fn (AnalysisFailureReason $reason): string => $reason->value, array_values($map));
    sort($mappedReasons);

    $allReasons = array_map(static fn (AnalysisFailureReason $reason): string => $reason->value, AnalysisFailureReason::cases());
    sort($allReasons);

    expect($mappedReasons)->toBe($allReasons);
});

test('各 named constructor が正しい reason を持つ', function (string $method, AnalysisFailureReason $expected): void {
    /** @var AnalysisFailedException $exception */
    $exception = AnalysisFailedException::{$method}();

    expect($exception->reason)->toBe($expected);
    expect($exception->getMessage())->not->toBe('');
})->with(function (): iterable {
    foreach (analysisFailedExceptionConstructorMap() as $method => $reason) {
        yield $method => [$method, $reason];
    }
});

test('isOcrEligibleForPdf は 3 理由だけ true を返す', function (): void {
    expect(AnalysisFailureReason::Unextractable->isOcrEligibleForPdf())->toBeTrue();
    expect(AnalysisFailureReason::TooShort->isOcrEligibleForPdf())->toBeTrue();
    expect(AnalysisFailureReason::InsufficientJapaneseText->isOcrEligibleForPdf())->toBeTrue();

    // 負例: 対象外の理由 (容量超過等) は OCR 経路へ回さない
    expect(AnalysisFailureReason::TooLarge->isOcrEligibleForPdf())->toBeFalse();
    expect(AnalysisFailureReason::TimedOut->isOcrEligibleForPdf())->toBeFalse();
    expect(AnalysisFailureReason::MediaUnreadable->isOcrEligibleForPdf())->toBeFalse();
    expect(AnalysisFailureReason::MediaTooLarge->isOcrEligibleForPdf())->toBeFalse();
    expect(AnalysisFailureReason::OcrEmptyOrInvalid->isOcrEligibleForPdf())->toBeFalse();
});
