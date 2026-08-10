<?php

declare(strict_types=1);

use App\Providers\FakeExternalsServiceProvider;
use App\Support\FakeStorageGate;
use Tests\Support\ExternalFakes\FakeClassCatalog;
use Tests\Support\ExternalFakes\FakeWiringSourceScanner;

/*
 * 本番コードが fake のクラス名を 1 度も参照しないことの全走査
 * (c2c: external-fakes-wiring-gate 柱 3(c))。
 *
 * fake クラス名は**ディレクトリと命名から動的導出**する (ハードコード一覧を持たない)。
 * 現時点の違反は 0 件 = 「増えないこと」を今固定するのが最安。
 *
 * ★走査候補: 「fake 実装クラス」だけでは足りない。配置例外
 *   (FakeExternalsServiceProvider / FakeStorageGate) を業務コードが参照しても検出できず
 *   偽グリーンになるため、候補は implementationClasses() ∪ placementExceptions() のキーとする。
 *
 * ★走査根: app/ だけだと routes/ に Testing controller を直書きする、config/ にクラス名を書く、
 *   といった抜け道が残る。「本番コード全走査」を名乗る以上、
 *   app/ • routes/ • config/ • bootstrap/ の 4 根を走査する。
 *
 * ★誤検出が出たら allowlist を足す方向へ倒さない。まず「本当に本番コードから fake を
 *   参照しているのか」を疑う (それが本 gate の目的)。
 */

/** 参照 allowlist: fake 系クラスを参照してよい本番ファイル (**repo ルート相対**) */
const FAKE_REFERENCE_ALLOWED = [
    // 唯一の配線点 (何を fake にするかの決定はここに集約する)
    'app/Providers/FakeExternalsServiceProvider.php',
    // fake storage signed route の受け口 (FakeStorageGate 成立時のみ route 登録される)
    'app/Http/Controllers/Testing/PutFakeStorageObjectController.php',
    'app/Http/Controllers/Testing/GetFakeStorageObjectController.php',
    // bug-hunt 専用の通し確認コマンド。fake storage へ実バイトを置く必要があり、
    // FakeStorageGate 成立時のみ動く (上 2 件の controller と同 species)。
    // 本番経路からは到達しない (artisan 手動実行のみ・スケジュール登録なし)。
    // ★実装条件: constructor 引数を持たず、fake は handle() の fail-secure 4 条件を
    //   通過した**後**にのみ app() で遅延解決する。
    'app/Console/Commands/Development/PipelineSmokeCommand.php',
    // provider 登録点。FakeExternalsServiceProvider (配置例外クラス) を必ず参照する
    'bootstrap/providers.php',
];

test('4-1 配置規約: Fake 命名クラスは Fakes/ か Testing/ 配下にのみ存在する', function (): void {
    $allowed = array_merge(
        FakeClassCatalog::implementationClasses(),
        array_keys(FakeClassCatalog::placementExceptions()),
    );

    $misplaced = array_values(array_diff(FakeClassCatalog::namedClasses(), $allowed));

    expect($misplaced)->toBe([]);
});

test('4-2 配置例外は 2 件から増えていない', function (): void {
    // 増やすときは placementExceptions() に理由を書いたうえで**ここも触る** (意図的な摩擦)。
    expect(array_keys(FakeClassCatalog::placementExceptions()))->toBe([
        FakeExternalsServiceProvider::class,
        FakeStorageGate::class,
    ]);
});

test('4-3 本番コードは fake クラスを参照しない', function (): void {
    $implementations = FakeClassCatalog::implementationClasses();
    $candidates = array_values(array_unique(array_merge(
        $implementations,
        array_keys(FakeClassCatalog::placementExceptions()),
    )));
    $files = FakeClassCatalog::scanFiles();

    // 走査器 / 母集団導出が壊れて「空走査で緑」になるのを防ぐ (fail-closed)
    expect($candidates)->not->toBeEmpty()
        ->and($files)->not->toBeEmpty();

    $violations = [];
    foreach ($files as $file) {
        if (in_array($file, FAKE_REFERENCE_ALLOWED, true)) {
            continue;
        }

        // fake 実装クラス自身が別の fake を参照するのは正当 (FakeTakeObjectStorage → FakeObjectStore 等)
        if (str_starts_with($file, 'app/')
            && in_array(FakeClassCatalog::classFromPath($file), $implementations, true)) {
            continue;
        }

        $referenced = FakeWiringSourceScanner::referencedClasses(
            FakeClassCatalog::sourceOf($file),
            $candidates
        );

        if ($referenced !== []) {
            $violations[] = $file.': '.implode(', ', $referenced);
        }
    }

    expect($violations)->toBe([]);
});

test('4-4 参照 allowlist は 5 件から増えていない', function (): void {
    // 増やすときは理由コメントを添えて**ここも触る** (意図的な摩擦)。
    expect(FAKE_REFERENCE_ALLOWED)->toHaveCount(5)
        ->and(FAKE_REFERENCE_ALLOWED)->toBe([
            'app/Providers/FakeExternalsServiceProvider.php',
            'app/Http/Controllers/Testing/PutFakeStorageObjectController.php',
            'app/Http/Controllers/Testing/GetFakeStorageObjectController.php',
            'app/Console/Commands/Development/PipelineSmokeCommand.php',
            'bootstrap/providers.php',
        ]);
});
