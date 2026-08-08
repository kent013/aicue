<?php

declare(strict_types=1);

namespace App\Enums\Idempotency;

/**
 * claim 試行の判定結果 (middleware の分岐は本 enum の match 1 段だけで完結させる)。
 *
 * Claimed 以外はすべて「本処理を実行しない」= 二重実行が起きないことが型で読める。
 */
enum IdempotencyClaimStatus
{
    /** 自分が claim を取得した。本処理を実行して finalize する */
    case Claimed;
    /** 完了済みの保存応答がある。再生する */
    case Replay;
    /** 同一キーで別 body。409 idempotency_conflict */
    case Conflict;
    /** 別リクエストが処理中。409 idempotency_in_progress */
    case InProgress;
    /** 決着不明で終わっている。409 idempotency_indeterminate */
    case Indeterminate;
}
