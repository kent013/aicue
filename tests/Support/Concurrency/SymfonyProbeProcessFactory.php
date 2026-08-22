<?php

declare(strict_types=1);

namespace Tests\Support\Concurrency;

use Symfony\Component\Process\Process;

/**
 * 実プロセスを起こす唯一の実装。
 *
 * 起動コマンドは `env -i` で環境を作り直し、許可 3 キー
 * ({@see ProbeEnvironment::ALLOWED_PROCESS_ENV_KEYS}) だけを載せる (遮断の段 5)。
 *
 * ★秘密 (plain API key / request body) は **argv に載せない** (プロセス一覧から読める)。
 *   0700 のディレクトリ配下の 0600 の入力ファイルへ置き、そのファイル名だけを argv に載せる。
 * ★Symfony 側のタイムアウトは無効化する (`null`)。締切は runner が単一の絶対 deadline で
 *   持っており、2 か所に締切を置くと「どちらで落ちたか」が読めなくなる。
 */
final class SymfonyProbeProcessFactory implements ProbeProcessFactory
{
    public function __construct(private readonly string $workingDirectory) {}

    public function create(ProbeLaunchSpec $spec): ProbeProcess
    {
        $process = new Process(
            [
                'env', '-i',
                'CONCURRENCY_PROBE_ENV_DIR='.$spec->environmentDirectory,
                'CONCURRENCY_PROBE_ENV_FILE='.$spec->environmentFileName,
                'APP_CONFIG_CACHE='.$spec->configCachePath,
                PHP_BINARY,
                $spec->scriptPath,
                $spec->workspaceDirectory,
                $spec->childId,
                $spec->inputFileName,
            ],
            $this->workingDirectory,
            null,
            null,
            null,
        );

        return new SymfonyProbeProcess($process);
    }
}
