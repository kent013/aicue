<?php

declare(strict_types=1);

// 一時スクリプト (devnotes 配下)。D27 追記が登録簿の形式を満たすかだけを、
// DB もテストロックも使わずに確かめる。判定の実体は本番と同じ純関数を呼ぶ。

require __DIR__.'/../../vendor/autoload.php';

use Carbon\CarbonImmutable;
use Tests\Support\TemplateDivergence\DivergenceLedgerParser;
use Tests\Support\TemplateDivergence\DivergenceLedgerRules;
use Tests\Support\TemplateDivergence\LedgerContext;
use Tests\Support\TemplateDivergence\TodoLedgerReference;

$root = dirname(__DIR__, 2);
$markdown = file_get_contents($root.'/docs/template-divergence.md');
$todo = file_get_contents($root.'/docs/TODO.md')."\n".file_get_contents($root.'/docs/TODO-closed.md');

$violations = DivergenceLedgerRules::violations(
    DivergenceLedgerParser::parse($markdown),
    new LedgerContext(
        baseDate: CarbonImmutable::today(),
        pinnedEntryCount: 26,
        pathExists: fn (string $path): bool => is_file($root.'/'.$path),
        directoryExists: fn (string $path): bool => is_dir($root.'/'.$path),
        rationaleExists: fn (string $reference): bool => TodoLedgerReference::existsIn($reference, $todo),
    ),
);

fwrite(STDOUT, $violations === [] ? "ledger OK\n" : "violations:\n".implode("\n", $violations)."\n");
