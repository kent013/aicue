<?php

declare(strict_types=1);

namespace Tests\Support;

use LogicException;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\ConsoleSectionOutput;
use Symfony\Component\Console\Output\Output;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * 標準出力と標準エラーを別々の buffer に貯める Console 出力。
 *
 * `Artisan::call()` の既定 buffer は `BufferedOutput` (= ConsoleOutputInterface ではない) なので、
 * Symfony の `getErrorStyle()` が標準エラーへ落ちず**同じ buffer**へ書き戻る。
 * それでは「標準出力には 1 バイトも出さない」という契約を検証できないため、
 * 2 本を分けて受け取れる出力をテスト側から注入する。
 */
final class SplitConsoleOutput extends Output implements ConsoleOutputInterface
{
    private string $stdout = '';

    private OutputInterface $stderr;

    public function __construct()
    {
        parent::__construct(OutputInterface::VERBOSITY_NORMAL, false);

        $this->stderr = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, false);
    }

    /** 標準出力へ書かれた内容。 */
    public function stdout(): string
    {
        return $this->stdout;
    }

    /** 標準エラーへ書かれた内容。 */
    public function stderr(): string
    {
        $stderr = $this->stderr;

        return $stderr instanceof BufferedOutput ? $stderr->fetch() : '';
    }

    public function getErrorOutput(): OutputInterface
    {
        return $this->stderr;
    }

    public function setErrorOutput(OutputInterface $error): void
    {
        $this->stderr = $error;
    }

    public function section(): ConsoleSectionOutput
    {
        // 区画付き出力は実ストリームを要する。抽出コマンドは使わないので到達しない。
        throw new LogicException('SplitConsoleOutput は section() を提供しない');
    }

    protected function doWrite(string $message, bool $newline): void
    {
        $this->stdout .= $message.($newline ? PHP_EOL : '');
    }
}
