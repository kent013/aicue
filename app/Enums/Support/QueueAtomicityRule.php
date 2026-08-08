<?php

declare(strict_types=1);

namespace App\Enums\Support;

/**
 * QueueDispatchAtomicityGuard が検査する規則の識別子 (AG-114 確定 2)。
 *
 * 違反 DTO (QueueDispatchAtomicityViolation) が「どの規則に落ちたか」を型で持つため、
 * テスト側は message 文字列ではなく規則で assert できる。
 */
enum QueueAtomicityRule: string
{
    /** 参照接続 (sync 以外) の driver は database である */
    case DatabaseDriver = 'database_driver';

    /** driver=database の参照接続は業務 DB と同一の DB 接続を使う */
    case SameDatabaseConnection = 'same_database_connection';

    /** driver=database の参照接続は after_commit=false である */
    case AfterCommitDisabled = 'after_commit_disabled';

    /** sync 接続は after_commit=true である (テスト・dev の実行順序の保存) */
    case SyncAfterCommitEnabled = 'sync_after_commit_enabled';

    /** production の既定接続は database である (sync の本番投入を拒否する) */
    case ProductionAsyncDriver = 'production_async_driver';

    /** 検査の前提となる config 値 (queue.default / database.default / queue.connections) が読めない */
    case ConfigUnreadable = 'config_unreadable';
}
