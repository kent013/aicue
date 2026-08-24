<?php

declare(strict_types=1);

namespace Tests\Support\Concurrency;

/**
 * 子 1 本の起動仕様 (偽物も同じものを受け取る)。
 *
 * ★起動仕様を**値**にしてあるのが、失敗経路の検査で子プロセスを 1 本も起こさずに
 *   runner の調停と回収を固定できる理由である (偽の {@see ProbeProcessFactory} が
 *   同じ仕様を受け取り、合図を書く側を演じられる)。
 */
final readonly class ProbeLaunchSpec
{
    public function __construct(
        /** 合図・出力・env ファイルの置き場 */
        public string $workspaceDirectory,
        public string $childId,
        public string $nonce,
        public string $scriptPath,
        public string $environmentDirectory,
        public string $environmentFileName,
        public string $inputFileName,
        public string $configCachePath,
    ) {}

    public function inputFilePath(): string
    {
        return $this->workspaceDirectory.'/'.$this->inputFileName;
    }

    public function environmentFilePath(): string
    {
        return $this->environmentDirectory.'/'.$this->environmentFileName;
    }
}
