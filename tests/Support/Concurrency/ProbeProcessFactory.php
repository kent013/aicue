<?php

declare(strict_types=1);

namespace Tests\Support\Concurrency;

/**
 * 子プロセスの作り手。
 *
 * 本番経路の実装は {@see SymfonyProbeProcessFactory} ただ 1 本で、
 * {@see ConcurrencyProbeRunner::run()} は引数が `null` のときだけそれを作る
 * (偽物を差す注入点と本番経路の分岐を 1 か所に留める)。
 */
interface ProbeProcessFactory
{
    public function create(ProbeLaunchSpec $spec): ProbeProcess;
}
