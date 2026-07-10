<?php

declare(strict_types=1);

namespace App\Enums\Capture;

/**
 * テイクアップロード予約の状態 (概念設計 D2/D3/D4)。
 *
 * pending → verifying → completed / released の一方向遷移。
 * - claim (pending→verifying) は原子的 UPDATE (POST takes)
 * - completed 化は verifying→completed の CAS (sweeper と競合しない)
 * - released 化は拒否・冪等重複・stale 掃除 (bytes_pending 解放)
 */
enum TakeUploadReservationStatus: string
{
    case Pending = 'pending';       // 予約中 (bytes_pending に計上)

    case Verifying = 'verifying';   // POST takes が claim 中 (外部 I/O 中。cron は fresh なら触れない)

    case Completed = 'completed';   // POST takes 成功 (以降 takes.size_bytes が真実源)

    case Released = 'released';     // 拒否・冪等重複・stale 掃除で解放
}
