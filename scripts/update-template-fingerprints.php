<?php

declare(strict_types=1);

/*
 * scripts/update-template-fingerprints.php — アプリ側の指紋台帳
 * docs/template-fingerprints.json と採用時債務一覧
 * tests/Support/TemplateDivergence/adoption-debt.tsv の生成器
 * (家系の裁定 AG-110 の標準形 t1 を role: app 側へ持ち込んだもの)。
 *
 * 使い方:
 *   php scripts/update-template-fingerprints.php --template-ledger=<path> [--adopt-new-template-ledger]
 *
 * `--template-ledger` には**正典 (laravel-claude-template) の指紋台帳をそのまま保存した
 * ファイル**を渡す。取得は機能台帳 lctl の get_source
 * (project: laravel-claude-template / path: docs/template-fingerprints.json) で行い、
 * 一時ファイルへ置く (コミットしない)。既定ではそのファイルの sha256 が
 * `LedgerPins::TEMPLATE_LEDGER_SOURCE_SHA256` と一致することを要求する。
 * 台帳を新しい世代へ載せ替えるときだけ `--adopt-new-template-ledger` を明示し、
 * 標準出力に出る新しい pin 値を `LedgerPins` へ書き写す。
 *
 * **CI では走らせない**。共有ファイルを変えたときに逸脱を登録するのは人の作業であり、
 * 本スクリプトは台帳の載せ替え時にだけ使う。
 *
 * 判定ロジックは持たず tests/Support/TemplateDivergence の純粋クラスへ委譲する
 * (規則の正本を 1 箇所にするため。突合 gate と同じ実装を使う)。
 * root は dirname(__DIR__) 固定で、**差し替える隠しオプションは作らない**
 * (テストは service を一時ディレクトリの root で直接呼ぶ)。
 *
 * 終了コード規約 (scripts/bug-hunt-inventory-check.sh の 0 / 3 規約と同型):
 *   0 = 生成成功 / 3 = ガードによる拒否 / 1 = 実行不能
 * 拒否と実行不能は**例外の型**で区別する (GenerationRefused / RuntimeException)。
 * 3 と、書き込み開始前の 1 では**生成物を 1 バイトも変えない**。
 */

use Tests\Support\TemplateDivergence\AdoptionDebtInventory;
use Tests\Support\TemplateDivergence\DivergenceLedgerParser;
use Tests\Support\TemplateDivergence\FingerprintGenerationContext;
use Tests\Support\TemplateDivergence\FingerprintGenerationService;
use Tests\Support\TemplateDivergence\FingerprintLedger;
use Tests\Support\TemplateDivergence\GenerationRefused;
use Tests\Support\TemplateDivergence\LedgerPins;
use Tests\Support\TemplateDivergence\RegularFileReader;
use Tests\Support\TemplateDivergence\TrackedRepositoryFiles;

$root = dirname(__DIR__);

$fail = static function (string $message): never {
    fwrite(STDERR, 'error: '.$message."\n");
    exit(1);
};

$autoload = $root.'/vendor/autoload.php';
if (! is_file($autoload)) {
    fwrite(STDERR, "error: vendor/autoload.php が無い。`composer install` を dev 依存込みで実行すること\n");
    exit(1);
}

require $autoload;

if (! class_exists(FingerprintGenerationService::class)) {
    fwrite(STDERR, 'error: Tests\\Support\\TemplateDivergence が autoload されていない。'
        ."`composer install` を dev 依存込みで実行すること (autoload-dev が要る)\n");
    exit(1);
}

// --- 引数解析 (未知・重複・欠落はすべて実行不能) ---
$templateLedgerPath = null;
$adoptNewTemplateLedger = false;
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--template-ledger=')) {
        if ($templateLedgerPath !== null) {
            $fail('--template-ledger が 2 回指定されている');
        }
        $templateLedgerPath = substr($argument, strlen('--template-ledger='));
        if ($templateLedgerPath === '') {
            $fail('--template-ledger の値が空である');
        }

        continue;
    }
    if ($argument === '--adopt-new-template-ledger') {
        if ($adoptNewTemplateLedger) {
            $fail('--adopt-new-template-ledger が 2 回指定されている');
        }
        $adoptNewTemplateLedger = true;

        continue;
    }

    $fail("未知の引数である: {$argument}");
}

if ($templateLedgerPath === null) {
    $fail('--template-ledger=<path> が要る (正典の指紋台帳を保存したファイル)');
}

$templateLedgerRaw = is_file($templateLedgerPath) && ! is_link($templateLedgerPath)
    ? file_get_contents($templateLedgerPath)
    : false;
if ($templateLedgerRaw === false) {
    $fail("正典の指紋台帳を読めない: {$templateLedgerPath}");
}

// --- role ガード (最も現実的な無効化経路を正規経路の側で塞ぐ) ---
// 既存のアプリ側台帳が role: template なら、子アプリで正典側の生成を走らせている。
// これは逸脱検出そのものを消すので**拒否 (3)** で止める。
// 拒否は GenerationRefused で表し、終了コードへの写像は下の catch 1 か所に集約する
// (拒否 = 3 / 実行不能 = 1。型を使わずに直接 exit すると型の説明が実装と食い違う)。
$previousLedger = null;
$fingerprintPath = $root.'/'.LedgerPins::FINGERPRINT_LEDGER_PATH;

// --- 登録簿と既存の債務一覧 ---
$ledgerMarkdown = file_get_contents($root.'/docs/template-divergence.md');
if ($ledgerMarkdown === false) {
    $fail('逸脱の登録簿 (docs/template-divergence.md) を読めない');
}

$parsedLedger = DivergenceLedgerParser::parse($ledgerMarkdown);
if ($parsedLedger->unparsable || $parsedLedger->parseViolations !== []) {
    $fail("逸脱の登録簿を解析できない (先に形式検査を通すこと):\n  ".implode("\n  ", $parsedLedger->parseViolations));
}

$registeredTargetPaths = [];
foreach ($parsedLedger->entries as $entry) {
    if ($entry->metadata === null) {
        $fail('逸脱の登録簿に登録メタ表を解析できない登録がある (先に形式検査を通すこと)');
    }
    foreach ($entry->metadata->targetPaths as $targetPath) {
        $registeredTargetPaths[] = $targetPath;
    }
}

$existingDebt = [];
if (is_file($root.'/'.AdoptionDebtInventory::INVENTORY_PATH)) {
    try {
        $existingDebt = AdoptionDebtInventory::read($root)['entries'];
    } catch (RuntimeException $e) {
        $fail('既存の採用時債務一覧を解釈できない: '.$e->getMessage());
    }
}

// --- 母集合の入力 ---
try {
    $trackedPaths = TrackedRepositoryFiles::all($root);
} catch (RuntimeException $e) {
    $fail($e->getMessage());
}

// --- 生成 ---
try {
    if (file_exists($fingerprintPath) || is_link($fingerprintPath)) {
        // 指紋台帳の読み取り口は 1 つに寄せる (gate / 一覧 / 生成器が同じ判定を通る)。
        // symlink を追跡すると母集合ごと差し替えられるため受理しない。
        $previousLedger = FingerprintLedger::fromJson(
            RegularFileReader::read($fingerprintPath, '既存の指紋台帳'),
        );

        // 判定本体は service 側に置く (CLI とテストが同じ処理を呼ぶ = 両方向を裏取りできる)
        FingerprintGenerationService::assertAppLedgerRole($previousLedger);
    }

    $context = FingerprintGenerationContext::forRoot(
        root: $root,
        expectedTemplateLedgerSha256: LedgerPins::TEMPLATE_LEDGER_SOURCE_SHA256,
        expectedSourceCommit: LedgerPins::TEMPLATE_LEDGER_SOURCE_COMMIT,
        adoptNewTemplateLedger: $adoptNewTemplateLedger,
        previousLedger: $previousLedger,
    );

    $report = FingerprintGenerationService::generate(
        context: $context,
        templateLedgerRaw: $templateLedgerRaw,
        trackedPaths: $trackedPaths,
        hasher: static function (string $relativePath) use ($root): string {
            $absolute = $root.'/'.$relativePath;
            $hash = is_link($absolute) || ! is_file($absolute) ? false : hash_file('sha256', $absolute);

            if ($hash === false) {
                throw new RuntimeException("母集合のファイルのハッシュを計算できない: {$relativePath}");
            }

            return $hash;
        },
        registeredTargetPaths: $registeredTargetPaths,
        divergenceEntryCount: count($parsedLedger->entries),
        existingDebt: $existingDebt,
        tempPathFactory: static function (string $targetPath): string|false {
            try {
                return dirname($targetPath).'/.'.basename($targetPath).'.'.bin2hex(random_bytes(8)).'.tmp';
            } catch (Exception) {
                return false;
            }
        },
        writer: static fn (string $path, string $data): int|false => file_put_contents($path, $data),
        reader: static fn (string $path): string|false => is_file($path) ? file_get_contents($path) : false,
        renamer: static fn (string $from, string $to): bool => rename($from, $to),
        remover: static fn (string $path): bool => ! is_file($path) || unlink($path),
    );
} catch (GenerationRefused $e) {
    fwrite(STDERR, 'refused: '.$e->getMessage()."\n");
    exit(3);
} catch (RuntimeException $e) {
    fwrite(STDERR, 'error: '.$e->getMessage()."\n");
    exit(1);
}

fwrite(STDOUT, sprintf(
    "生成物を更新した (%s / %s)\n"
        ."  LedgerPins::FINGERPRINT_POPULATION_COUNT = %d\n"
        ."  LedgerPins::ADOPTION_DEBT_COUNT          = %d\n"
        ."  LedgerPins::DIVERGENCE_ENTRY_COUNT       = %d\n"
        ."  内訳: 一致 %d / 相違 %d / 消滅 %d / 債務へ追加 %d 件%s\n"
        ."  世代識別子 (template_ledger_commit) = %s\n",
    LedgerPins::FINGERPRINT_LEDGER_PATH,
    AdoptionDebtInventory::INVENTORY_PATH,
    $report['populationCount'],
    $report['adoptionDebtCount'],
    $report['divergenceEntryCount'],
    $report['matched'],
    $report['mismatched'],
    $report['missing'],
    count($report['addedDebt']),
    $report['seeded'] ? ' (初回生成 = 採用)' : '',
    $report['templateLedgerCommit'],
));

// 案内は**遷移したときだけ**出す (安定した引退状態で再実行するたびに出すと嘘になる)
if ($report['newlyRetired']) {
    fwrite(STDOUT,
        "採用時債務が 0 件になった。同じ変更で次の 2 つを行うこと:\n"
        ."  1. LedgerPins::ADOPTION_DEBT_COUNT を 0 にする\n"
        .'  2. docs/template-divergence.md の対象パスから '
            .AdoptionDebtInventory::INVENTORY_PATH." の 1 行を外す\n"
        ."     (登録そのものは一覧クラスの説明として残す)\n",
    );
}

// 「取り除いた」は実際に削除したときだけ言う
if ($report['debtInventoryRemoved']) {
    fwrite(STDOUT, '一覧ファイルを取り除いた: '.AdoptionDebtInventory::INVENTORY_PATH."\n");
}

exit(0);
