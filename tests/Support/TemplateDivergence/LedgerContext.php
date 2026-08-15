<?php

declare(strict_types=1);

namespace Tests\Support\TemplateDivergence;

use Carbon\CarbonImmutable;
use Closure;

/**
 * 形式検査の文脈 (基準日と実在判定の注入点)。
 *
 * 基準日を引数で受け取るのは、見直し期限と決めた日の判定を純関数に保ち、
 * 単体テストが実行日で揺れないようにするためである。
 */
final readonly class LedgerContext
{
    /**
     * @param  CarbonImmutable  $baseDate  期限判定の基準日 (検査層は今日、単体テストは固定日を渡す)
     * @param  int  $pinnedEntryCount  検査側に固定した登録件数 (明示件数との同期検査であって免除一覧ではない)
     * @param  Closure(string): bool  $pathExists  リポジトリ相対の**ファイル**の実在判定 (is_file)
     * @param  Closure(string): bool  $directoryExists  リポジトリ相対の**ディレクトリ**の実在判定 (is_dir)
     * @param  Closure(string): bool  $rationaleExists  根拠 (T 番号) が TODO 台帳の表に実在するか
     */
    public function __construct(
        public CarbonImmutable $baseDate,
        public int $pinnedEntryCount,
        public Closure $pathExists,
        public Closure $directoryExists,
        public Closure $rationaleExists,
    ) {}
}
