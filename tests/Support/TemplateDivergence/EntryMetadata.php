<?php

declare(strict_types=1);

namespace Tests\Support\TemplateDivergence;

/**
 * 逸脱の登録 1 件が持つ登録メタ表 9 行の値。
 *
 * 値は**生文字列のまま**持ち、妥当性 (値域・日付・実在) は `DivergenceLedgerRules` が見る。
 * ここで正規化すると「解析器が直したので合格した」という抜け道ができるため、
 * 解析器は取り出すだけにする。
 */
final readonly class EntryMetadata
{
    /**
     * @param  list<string>  $targetPaths  対象パス欄から取り出したパス (バッククォート囲みの中身)
     * @param  string  $rawTargetPathCell  対象パス欄の生の値 (書式違反の報告に使う)
     */
    public function __construct(
        public array $targetPaths,
        public string $rawTargetPathCell,
        public string $domainReason,
        public string $invariantAndGuard,
        public string $reevaluationCondition,
        public string $decidedOn,
        public string $decidedBy,
        public string $rationale,
        public string $state,
        public string $reviewDeadline,
    ) {}
}
