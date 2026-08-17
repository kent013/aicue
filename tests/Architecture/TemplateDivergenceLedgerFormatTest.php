<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Tests\Support\TemplateDivergence\DivergenceLedgerParser;
use Tests\Support\TemplateDivergence\DivergenceLedgerRules;
use Tests\Support\TemplateDivergence\LedgerContext;
use Tests\Support\TemplateDivergence\TodoLedgerReference;
use Webmozart\Assert\Assert;

/*
 * 逸脱の登録簿 (`docs/template-divergence.md`) が家系の統一形式を満たすことを検査する。
 *
 * 判定の実体は `DivergenceLedgerRules` (純関数) にあり、本テストは
 * **実ファイルを読んで文脈を組み立て、違反が空であることを見るだけ**の薄い層である。
 * 負例 (検出器が実際に検出できること) は
 * `tests/Unit/Architecture/DivergenceLedgerRulesTest.php` が固定する。
 *
 * **この検査が保証しないもの** (誇張しない):
 *  - 実ファイルがテンプレートから逸脱したのに登録が無いこと (登録漏れそのもの)。
 *    実体との突合は台帳リポジトリの巡回が行う (家系の裁定 AG-159)
 *  - 内容をテンプレート準拠へ戻したのに残っている登録 (対象パスは実在し続けるため)
 *  - 登録の中身が正しいこと (空でないこと・値域に収まっていることだけを見る)
 *  - 削除した番号の再利用 (使用済み番号の履歴を持たないため)
 *
 * 実行不能 (台帳が読めない / 囲みが閉じない / 登録エントリ領域が無い) は
 * skip でも緑でもなく**不合格**にする。
 */

/**
 * 登録件数の固定値。
 *
 * **明示件数との同期検査であって、例外を許す一覧ではない**。個別の D 番号を名指しして
 * 規則を免除する仕組みは持たない。登録を足した / 消したら同じ変更でこの値も直す。
 */
const TEMPLATE_DIVERGENCE_ENTRY_COUNT = 25;

/** 逸脱の登録簿の本文 (読めないことは不合格)。 */
function templateDivergenceMarkdown(): string
{
    $markdown = file_get_contents(base_path('docs/template-divergence.md'));
    Assert::string($markdown, 'docs/template-divergence.md を読めない');

    return $markdown;
}

/** 根拠の照合先 (TODO 台帳の Open と Closed の両方)。 */
function templateDivergenceTodoSources(): string
{
    $open = file_get_contents(base_path('docs/TODO.md'));
    $closed = file_get_contents(base_path('docs/TODO-closed.md'));
    Assert::string($open, 'docs/TODO.md を読めない');
    Assert::string($closed, 'docs/TODO-closed.md を読めない');

    return $open."\n".$closed;
}

test('TD0: 逸脱の登録簿を読めること (実行不能は不合格)', function (): void {
    expect(trim(templateDivergenceMarkdown()))->not->toBe('');
    expect(trim(templateDivergenceTodoSources()))->not->toBe('');
});

test('TD1〜TD12: 逸脱の登録簿が統一形式を満たすこと', function (): void {
    $todoSources = templateDivergenceTodoSources();

    $violations = DivergenceLedgerRules::violations(
        DivergenceLedgerParser::parse(templateDivergenceMarkdown()),
        new LedgerContext(
            baseDate: CarbonImmutable::today(),
            pinnedEntryCount: TEMPLATE_DIVERGENCE_ENTRY_COUNT,
            pathExists: fn (string $path): bool => is_file(base_path($path)),
            directoryExists: fn (string $path): bool => is_dir(base_path($path)),
            // T 番号は TODO 台帳の表のセルとして境界付きで照合する (T1 が T10 に一致しないように)
            rationaleExists: fn (string $reference): bool => TodoLedgerReference::existsIn($reference, $todoSources),
        ),
    );

    expect($violations)->toBe([], "逸脱の登録簿の形式違反:\n".implode("\n", $violations));
});
