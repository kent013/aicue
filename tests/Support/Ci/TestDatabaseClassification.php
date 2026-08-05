<?php

declare(strict_types=1);

namespace Tests\Support\Ci;

/**
 * 孤児テスト DB sweep の分類。
 *
 * **分類は「説明」のために行い、削除可否を分類だけで自動決定しない**。
 * `Orphan` / `Unlabeled` であっても `--include-hash=<hash>` で人間が 1 つずつ名指ししない限り
 * 1 件も DROP されない (一括フラグは意図的に用意しない)。
 *
 * 評価順序 (先に一致したもので確定する) は
 * `TestDatabaseEnv::classifyTestDatabases()` が固定する:
 *   Protected → Live → Foreign → Orphan → Unlabeled
 * `Live` が `Foreign` / `Orphan` より先なのは、`COMMENT ON DATABASE` (provenance) が
 * **書き換え可能な分類材料であって信頼境界ではない**ため。comment を細工しても
 * 生存 worktree の DB は落とせない。
 */
enum TestDatabaseClassification: string
{
    /** `--protect-hash` で明示保護された hash 群 (人間の意思が最優先)。 */
    case Protected = 'protected';

    /** 生存 worktree の hash 群 (git worktree list 突合)。 */
    case Live = 'live';

    /** provenance ラベルあり / その path が実在する = 別クローンが生きている可能性が高い。 */
    case Foreign = 'foreign';

    /** provenance ラベルあり / その path が実在しない = 消えた worktree の残骸の可能性が高い。 */
    case Orphan = 'orphan';

    /** provenance ラベルなし (本機能より前に作られた legacy / base 不在で worker のみ)。 */
    case Unlabeled = 'unlabeled';
}
