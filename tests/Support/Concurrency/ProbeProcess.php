<?php

declare(strict_types=1);

namespace Tests\Support\Concurrency;

/**
 * 子プロセス 1 本の抽象。
 *
 * ★**操作を分けている**のは、失敗経路の検査が「runner が停止・強制終了・待機を
 *   それぞれ要求したこと」を**順序込みで固定できる**ようにするためである。
 *   1 メソッドに束ねると、検査は「何かを呼んだ」しか言えない。
 *
 * **保証の境界**: 失敗経路の検査が主張するのは「runner がこの抽象へ要求すること」までである。
 * 実 OS プロセスに対するシグナルの実効性は**保証範囲外**とする
 * (実プロセスを起こすテストを増やすと正典の要素 (6) に反するため踏み込まない)。
 */
interface ProbeProcess
{
    public function start(): void;

    public function isRunning(): bool;

    public function exitCode(): ?int;

    public function output(): string;

    public function errorOutput(): string;

    /** SIGTERM */
    public function signalTerminate(): void;

    /** SIGKILL */
    public function signalKill(): void;

    /**
     * 上限つきで終了を待ち、終了コードを返す (時間内に終わらなければ null)。
     *
     * @param  float  $seconds  0 以上。0 は「1 度だけ状態を確かめる」を意味する
     */
    public function waitFor(float $seconds): ?int;
}
