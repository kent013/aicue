<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;
use Tests\Support\PhpTokenScan;

/*
 * 撤去した滞留回収の実装が再流入していないことの gate (T171)。
 *
 * **素の部分文字列照合にしない**。新設コードには撤去対象と字面が重なる名前
 * (StuckWorkRecoverySweeper::sweep など) が実在するため、単純な走査は新設コード自身を落とす。
 * 検出単位は次の 3 種類に限る:
 *   1. 撤去したコマンド名 (完全一致の文字列)
 *   2. 撤去したクラス名 (完全修飾名と短縮名の両方)
 *   3. 撤去したメソッドの**宣言** (`function recoverStale(` の形。呼び出しでは判定しない)
 *
 * **保証しないもの (誇張しない)**:
 * - インスタンス変数からの呼び出し (`$service->recoverStale()`) は、字句だけでは受信側の
 *   クラスを確定できないため**保証範囲に入れない**。「呼び出しが残っていれば必ず落ちる」とは
 *   書かない。撤去の担保は (a) 旧メソッドの宣言が存在しないこと、(b) 旧クラス名・旧コマンド名が
 *   存在しないこと、(c) composer test が緑であること (宣言が消えているので呼び出しが残れば
 *   実行時に落ちる) の 3 つの組み合わせで得る
 * - 動的に組み立てた文字列 (`'analysis:'.$suffix`) には沈黙する
 * - 走査対象から外した場所 (devnotes / docs/TODO-closed.md) は過去の記録であり書き換えさせない
 */

/** 撤去したコマンド名 (完全一致の文字列で探す) */
function retiredRecoveryCommandNames(): array
{
    return [
        'analysis:recover-stale-jobs',
        'render:recover-stale-jobs',
        'billing:release-stale-reservations',
        'billing:recover-stale-webhook-events',
        'capture:release-stale-upload-reservations',
    ];
}

/** 撤去したクラス (完全修飾名と短縮名の両方を探す) */
function retiredRecoveryClassNames(): array
{
    return [
        'App\Services\Capture\StaleUploadReservationSweeper',
        'App\DataTransferObjects\Billing\WebhookRecoveryResultDto',
    ];
}

/** 撤去したメソッド名 (宣言だけを探す。呼び出しでは判定しない) */
function retiredRecoveryMethodNames(): array
{
    return ['recoverStale', 'releaseStale'];
}

/**
 * 走査対象 (リポジトリ相対パス => 全文)。
 *
 * app / routes / config / tests / database と、運用の正本になる docs を見る。
 * **devnotes と docs/TODO-closed.md は除く** (過去の記録なので書き換えさせない)。
 *
 * @return array<string, string>
 */
function retiredRecoveryScannedSources(): array
{
    $sources = [];

    foreach (['app', 'routes', 'config', 'tests', 'database'] as $directory) {
        foreach (Finder::create()->files()->in(base_path($directory))->name('*.php')->sortByName() as $file) {
            $sources[$directory.'/'.str_replace(DIRECTORY_SEPARATOR, '/', $file->getRelativePathname())] = $file->getContents();
        }
    }

    foreach (Finder::create()->files()->in(base_path('docs'))->name('*.md')->sortByName() as $file) {
        $relative = 'docs/'.str_replace(DIRECTORY_SEPARATOR, '/', $file->getRelativePathname());
        if ($relative === 'docs/TODO-closed.md') {
            continue;
        }
        $sources[$relative] = $file->getContents();
    }

    foreach (['AGENTS.md', 'DESIGN.md'] as $rootDoc) {
        if (file_exists(base_path($rootDoc))) {
            $sources[$rootDoc] = (string) file_get_contents(base_path($rootDoc));
        }
    }

    return $sources;
}

test('撤去したコマンド名がコードにも運用の正本にも 1 箇所も残っていない', function (): void {
    $violations = [];

    foreach (retiredRecoveryScannedSources() as $path => $source) {
        // 本 gate 自身は検出語彙そのものを持つので除く (自己参照で必ず落ちる)
        if ($path === 'tests/Architecture/RetiredRecoveryReferenceGateTest.php') {
            continue;
        }
        foreach (retiredRecoveryCommandNames() as $command) {
            if (str_contains($source, $command)) {
                $violations[] = $path.' — '.$command;
            }
        }
    }

    expect($violations)->toBe([],
        '撤去済みのコマンド名が残っています。滞留回収の入口は work:recover-stuck ただ 1 本で、'
        .'系列は --stream=<key> で指定します (docs/architecture.md の「滞留回収の共通基盤」に'
        .'旧語彙からの対応表があります)。'.PHP_EOL.implode(PHP_EOL, $violations));
});

test('撤去したクラス名がコードにも運用の正本にも 1 箇所も残っていない', function (): void {
    $violations = [];

    foreach (retiredRecoveryScannedSources() as $path => $source) {
        if ($path === 'tests/Architecture/RetiredRecoveryReferenceGateTest.php') {
            continue;
        }
        foreach (retiredRecoveryClassNames() as $class) {
            $short = substr((string) strrchr($class, '\\'), 1);
            if (str_contains($source, $class) || str_contains($source, $short)) {
                $violations[] = $path.' — '.$class;
            }
        }
    }

    expect($violations)->toBe([],
        '撤去済みのクラス名が残っています。撮影アップロードの回収は '
        .'App\Services\Recovery\Streams\StaleUploadReservationStream が、'
        .'掃引の結果は App\DataTransferObjects\Recovery\StreamSweepResultDto が引き継いでいます。'
        .PHP_EOL.implode(PHP_EOL, $violations));
});

test('撤去したメソッドの宣言が 1 つも存在しない (呼び出しでは判定しない)', function (): void {
    $retired = retiredRecoveryMethodNames();
    $violations = [];

    foreach (retiredRecoveryScannedSources() as $path => $source) {
        if (! str_ends_with($path, '.php')) {
            continue;
        }
        if ($path === 'tests/Architecture/RetiredRecoveryReferenceGateTest.php') {
            continue;
        }

        $tokens = PhpTokenScan::normalize($source);
        $count = count($tokens);
        for ($i = 0; $i < $count - 1; $i++) {
            if ($tokens[$i]['id'] !== T_FUNCTION) {
                continue;
            }
            $name = $tokens[$i + 1];
            if ($name['id'] === T_STRING && in_array($name['text'], $retired, true)) {
                $violations[] = $path.':'.$name['line'].' — function '.$name['text'].'()';
            }
        }
    }

    expect($violations)->toBe([],
        '撤去済みメソッドの宣言が復活しています。滞留回収は共通基盤 (StuckWorkStream の実装) 経由で'
        .'行い、ドメイン側は行ロック下で述語を再評価する 1 件処理の口だけを公開してください。'
        .PHP_EOL.implode(PHP_EOL, $violations));
});

test('検出語彙そのものが空でない (gate が無力化されていない)', function (): void {
    // 語彙を空にすると 3 本すべてが自動的に緑になるため、母集団の下限を固定する
    expect(retiredRecoveryCommandNames())->toHaveCount(5);
    expect(retiredRecoveryClassNames())->toHaveCount(2);
    expect(retiredRecoveryMethodNames())->toHaveCount(2);
    expect(count(retiredRecoveryScannedSources()))->toBeGreaterThan(100);
});
