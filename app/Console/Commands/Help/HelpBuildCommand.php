<?php

declare(strict_types=1);

namespace App\Console\Commands\Help;

use App\Services\Help\HelpArtifactState;
use App\Services\Help\HelpBuildReport;
use App\Services\Help\HelpBuildService;
use Illuminate\Console\Command;
use Throwable;

/**
 * ヘルプ生成物の**生成と鮮度検査の唯一の入口**。
 *
 * ★**終了コードは 0 と 1 の 2 値だけ**。例外も 1 へ畳む。
 *   捕捉は `Throwable` である — `RuntimeException` だけでは
 *   `Webmozart\Assert` の `InvalidArgumentException`・container の
 *   `BindingResolutionException`・`TypeError` 等の `Error` 系が素通りし、
 *   0/1 以外の終わり方が生まれる。
 * ★`--check` は**作業ツリーを 1 バイトも変えない**。
 * ★手書きページが 0 件でも成功する (ヘルプ本文の未整備を赤字扱いしない)。
 */
final class HelpBuildCommand extends Command
{
    /** @var string */
    protected $signature = 'help:build {--check : 生成せず鮮度だけを検査する (作業ツリーを変更しない)}';

    /** @var string */
    protected $description = 'docs/help/ の生成物を組み立てる (--check は生成せず鮮度だけを検査する)';

    public function handle(HelpBuildService $service): int
    {
        $checkOnly = (bool) $this->option('check');

        try {
            $report = $checkOnly ? $service->check() : $service->build();
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->render($report, $checkOnly);

        return $report->isClean() ? self::SUCCESS : self::FAILURE;
    }

    private function render(HelpBuildReport $report, bool $checkOnly): void
    {
        foreach ($report->observations as $observation) {
            $this->components->twoColumnDetail(
                $observation->relativePath,
                $observation->state->value,
            );
        }

        if ($report->isClean()) {
            $this->components->info($checkOnly ? 'ヘルプ生成物は鮮度が保たれている。' : 'ヘルプ生成物を組み立てた。');

            return;
        }

        foreach ($report->problems() as $problem) {
            $this->components->error(match ($problem->state) {
                HelpArtifactState::Stale => "生成物が古い: {$problem->relativePath} — `php artisan help:build` を実行すること。",
                HelpArtifactState::Missing => "生成物が無い: {$problem->relativePath} — `php artisan help:build` を実行すること。",
                HelpArtifactState::Orphan => "manifest に無い生成物が残っている: {$problem->relativePath} — 削除するか manifest へ宣言すること。",
                HelpArtifactState::UpToDate => '',
            });
        }
    }
}
