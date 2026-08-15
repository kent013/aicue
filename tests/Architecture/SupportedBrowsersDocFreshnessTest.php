<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Tests\Support\Docs\PrimarySourceReviewDate;

/*
 * 対応ブラウザ方針の文書 (docs/supported-browsers.md) の期限検査。
 *
 * 同書はブラウザ挙動の一次情報 (自動化ハーネスの版と起動スイッチ / 復元が再現しない原因 /
 * 実機受入確認の実施状況) に依存しており、時間で陳腐化する。**未実施のまま忘れられるのを防ぐ**
 * ために、確認日の行を 1 行だけ持たせて機械で読む。
 *
 * **保証しないもの**: 確認日は自己申告であり、**日付を新しくしても内容が正しいことは
 * 担保しない**。この検査が担うのは「見直す機会を強制的に作る」ことだけである。
 * 本テストはある日、コードを 1 行も変えていないのに赤くなる。それは意図した設計である。
 */

const SUPPORTED_BROWSERS_DOC = 'docs/supported-browsers.md';

/** 再確認すべき項目 (失敗メッセージに並べて「日付だけ更新して黙らせる」運用を防ぐ)。 */
const SUPPORTED_BROWSERS_REVIEW_ITEMS = <<<'TEXT'
再確認する項目:
  - 自動化ハーネス (Playwright / pest-plugin-browser) の版と起動スイッチの状況
  - 復元が再現しない原因の調査 (Chromium は起動スイッチで特定済み / WebKit は未特定)
  - iOS 実機受入確認 (T085) の実施状況
確認したら docs/supported-browsers.md の確認日の行を更新すること
(日付だけ更新して内容を見直さないのは、この検査の目的に反する)。
TEXT;

test('確認日の行が 1 行だけ存在し、読めて、期限内である', function (): void {
    $contents = file_get_contents(base_path(SUPPORTED_BROWSERS_DOC));
    expect($contents)->toBeString();

    $found = PrimarySourceReviewDate::extractAll((string) $contents);

    expect($found)->toHaveCount(
        1,
        '確認日の行は '.SUPPORTED_BROWSERS_DOC.' に 1 行だけ置くこと (見つかった数: '.count($found).")\n"
        .SUPPORTED_BROWSERS_REVIEW_ITEMS,
    );

    // 基準日は UTC の今日に固定する (実行環境のタイムゾーンで境界が動かないように)。
    $problem = PrimarySourceReviewDate::problem($found[0], CarbonImmutable::today('UTC'));

    expect($problem)->toBeNull(
        SUPPORTED_BROWSERS_DOC.' の確認日が使えない: '.($problem ?? '')."\n"
        .SUPPORTED_BROWSERS_REVIEW_ITEMS,
    );
});

test('日付判定の境界 (純粋関数を直接呼ぶ。文書は書き換えない)', function (): void {
    $today = CarbonImmutable::parse('2026-08-15', 'UTC')->startOfDay();

    // 行が無い / 書式違い / 実在しない日付は「読めない」として赤にする。
    expect(PrimarySourceReviewDate::problem(null, $today))->not->toBeNull()
        ->and(PrimarySourceReviewDate::problem('2026/08/15', $today))->not->toBeNull()
        ->and(PrimarySourceReviewDate::problem('2026-8-15', $today))->not->toBeNull()
        ->and(PrimarySourceReviewDate::problem('未確認', $today))->not->toBeNull()
        ->and(PrimarySourceReviewDate::problem('2026-02-30', $today))->not->toBeNull();

    // 未来の日付は記入ミスとして赤にする (黙って通さない)。
    expect(PrimarySourceReviewDate::problem('2026-08-16', $today))->not->toBeNull();

    // 当日は緑。ちょうど上限日数前も緑、1 日超えると赤。
    expect(PrimarySourceReviewDate::problem('2026-08-15', $today))->toBeNull()
        ->and(PrimarySourceReviewDate::problem(
            $today->subDays(PrimarySourceReviewDate::MAX_AGE_DAYS)->format('Y-m-d'),
            $today,
        ))->toBeNull()
        ->and(PrimarySourceReviewDate::problem(
            $today->subDays(PrimarySourceReviewDate::MAX_AGE_DAYS + 1)->format('Y-m-d'),
            $today,
        ))->not->toBeNull();
});

test('確認日の行を抽出できないときは空を返す (degenerate PASS 防止の自己検証)', function (): void {
    expect(PrimarySourceReviewDate::extractAll("# 見出し\n本文だけの文書\n"))->toBe([]);
});
