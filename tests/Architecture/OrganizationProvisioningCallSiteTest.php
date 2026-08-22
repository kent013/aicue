<?php

declare(strict_types=1);

use Tests\Support\Architecture\ProvisioningCallSiteScanner;
use Tests\Support\TrackedPhpSourceFiles;

/*
 * 初期組織生成の**呼び出しサイトを固定する** (家系裁定 AG-038 / 不変条件 I5)。
 *
 * 「所属組織が 0 件なら初期組織を作る」は登録経路の一部であり、増えると
 * 「どこからでも組織が生える」状態になる。正典は呼び出しサイトを機械検査で固定せよと定める。
 *
 * ## 走査根と判定
 *
 * git 追跡下の PHP 全数 (`TrackedPhpSourceFiles`)。`provisionInitialOrganization` の
 * **メソッド呼び出し**を全数抽出し、許可した 2 経路と**完全一致**することを固定する
 * (増えても減っても赤)。母集団が空なら fail する。
 *
 * ## 行ロック構造の固定
 *
 * 冪等判定は「トランザクション内で利用者行を**行ロック**してから所属を数える」でなければ
 * ならない (AG-038 / I4)。`lockForUpdate()` が現れ、**その後に**所属を数える呼びが来ることを
 * 構文で固定する。
 *
 * ## 保証しないもの (誇張しない)
 *
 * - 見るのは**字句として現れた呼び出し**だけである。可変メソッド名 (`$obj->$method()`) や
 *   コンテナ経由の動的呼び出しには**無言で効かない**。
 * - 「ロックの後に数えている」ことは**トークン順**で見る。実行順が分岐で入れ替わる書き方
 *   (条件分岐の中でだけロックする等) は検出しない。
 * - **並行実行で 1 件になること自体は実測していない** (`RefreshDatabase` は未 commit の
 *   トランザクション内で走るため別接続から観測できない)。本 gate が固定するのは
 *   「そう書かれていること」だけである。挙動は Unit (seam) と Feature (逐次) が受け持つ。
 */

test('走査根が空でない', function (): void {
    expect(TrackedPhpSourceFiles::all(base_path()))->not->toBeEmpty();
});

test('初期組織生成の呼び出しサイトは許可した 2 経路と完全一致する', function (): void {
    $sites = ProvisioningCallSiteScanner::callSites(TrackedPhpSourceFiles::all(base_path()));

    expect($sites)->toBe([
        'app/Actions/Fortify/CreateNewUser.php',
        'app/Services/Auth/SocialAccountService.php',
    ]);
});

test('初期組織生成は行ロックの後に所属を数える', function (): void {
    $source = (string) file_get_contents(base_path('app/Services/Organization/OrganizationProvisioningService.php'));

    expect(ProvisioningCallSiteScanner::locksBeforeCounting($source))->toBeTrue();
});

test('負例: ロックを外した合成入力・呼び出しサイトの追加を検出できる', function (): void {
    $withoutLock = <<<'PHP'
        <?php
        class S {
            public function provisionInitialOrganization(User $user): Organization
            {
                return DB::transaction(function () use ($user): Organization {
                    $existing = $user->organizations()->orderBy('organizations.id')->first();
                    return $existing ?? $this->provision($user, 'x');
                });
            }
        }
        PHP;
    expect(ProvisioningCallSiteScanner::locksBeforeCounting($withoutLock))->toBeFalse();

    // ロックが**所属を数えた後**に来る形も落ちる (順序を見ている裏取り)
    $lockAfterCount = <<<'PHP'
        <?php
        class S {
            public function provisionInitialOrganization(User $user): Organization
            {
                return DB::transaction(function () use ($user): Organization {
                    $existing = $user->organizations()->orderBy('organizations.id')->first();
                    $locked = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
                    return $existing ?? $this->provision($locked, 'x');
                });
            }
        }
        PHP;
    expect(ProvisioningCallSiteScanner::locksBeforeCounting($lockAfterCount))->toBeFalse();

    // 呼び出しサイトの合成追加を検出できる
    $extraCallSite = "<?php\nclass Extra { public function f(\$s) { \$s->provisionInitialOrganization(\$u); } }\n";
    expect(ProvisioningCallSiteScanner::sourceCallsProvisioning($extraCallSite))->toBeTrue();
    // 宣言そのもの (Service 本体) は呼び出しサイトに数えない
    $declaration = "<?php\nclass S { public function provisionInitialOrganization(User \$u) {} }\n";
    expect(ProvisioningCallSiteScanner::sourceCallsProvisioning($declaration))->toBeFalse();
});
