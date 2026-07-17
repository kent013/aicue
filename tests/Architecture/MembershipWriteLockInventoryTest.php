<?php

declare(strict_types=1);

use App\Services\Organization\OrganizationMembershipService;

/*
 * メンバーシップ書き込みの共通ロック規約 (canonical 順序 users→organizations) の drift-guard。
 *
 * OrganizationMembershipService の mutating な public メソッドを reflection で列挙し、
 * 3 分類 (directLock / delegatedToLocked / exempt) への登録を強制する。加えてメソッドソースを
 * 検査し、実際にロックを呼んでいることを保証する:
 * - directLock 群: メソッドソースに `lockForMembershipWrite(` が現れること。
 * - delegatedToLocked 群: ロック済み内部メソッド (`joinOrganization(`) 呼び出しが現れること。
 * - 未分類メソッドがあれば fail (drift 検出)。
 */

test('OrganizationMembershipService の書き込みメソッドは共通ロック規約に準拠する', function (): void {
    // 自身の tx 冒頭で直接ロックする mutating メソッド
    $directLock = ['applyConsoleRole', 'changeRole', 'removeMember', 'transferOwnership', 'deleteAccount'];
    // ロック済み内部メソッド (joinOrganization) 経由で間接的にロックされる受諾経路
    $delegatedToLocked = ['acceptInvitation', 'acceptInvitationIfValid'];
    // ロック不要 (membership/role を変えない) と判断した書き込みメソッド (根拠付き exempt)
    $exempt = [
        'inviteMember',     // 招待レコード生成のみ (membership/role 不変)
        'revokeInvitation', // 招待の論理失効のみ (membership/role 不変)
        // 読み取り専用判定 (ロック不要・表示スナップショット)。deleteAccount がロック下で権威判定する
        'organizationsBlockingDeletion',
        // register prefill 用の read + session forget のみ (membership/role/DB 書き込みなし)。
        // token_hash 照合で active 招待の email を返すだけで、共通ロック規約の対象外。
        'resolveRegisterPrefillEmail',
    ];

    $reflection = new ReflectionClass(OrganizationMembershipService::class);
    $ownPublicMethods = collect($reflection->getMethods(ReflectionMethod::IS_PUBLIC))
        ->reject(fn (ReflectionMethod $m): bool => $m->isConstructor()
            || $m->getDeclaringClass()->getName() !== OrganizationMembershipService::class)
        ->map(fn (ReflectionMethod $m): string => $m->getName())
        ->all();

    // 1. 分類漏れ検出
    $classified = array_merge($directLock, $delegatedToLocked, $exempt);
    expect(array_values(array_diff($ownPublicMethods, $classified)))
        ->toBe([], '新しい書き込みメソッドは directLock / delegatedToLocked / exempt に分類すること');

    // 2. 実ロック呼び出しの静的検査 (メソッド本文を切り出して文字列一致)
    $source = file($reflection->getFileName() ?: '') ?: [];
    $bodyOf = function (string $method) use ($reflection, $source): string {
        $m = $reflection->getMethod($method);
        $start = $m->getStartLine();
        $end = $m->getEndLine();
        if ($start === false || $end === false) {
            return '';
        }

        return implode('', array_slice($source, $start - 1, $end - $start + 1));
    };
    foreach ($directLock as $method) {
        // {$method} は lockForMembershipWrite を直接呼ぶこと (toContain は message 引数を取らない)
        expect(str_contains($bodyOf($method), 'lockForMembershipWrite('))->toBeTrue();
    }
    foreach ($delegatedToLocked as $method) {
        // {$method} はロック済み joinOrganization を経由すること
        expect(str_contains($bodyOf($method), 'joinOrganization('))->toBeTrue();
    }

    // 3. [ロック順序 guard] deleteAccount 本文で最初の lockForMembershipWrite( が
    //    organizations( 列挙より前に現れること (canonical 順序 users→organizations の退行検出)
    $deleteBody = $bodyOf('deleteAccount');
    $firstLock = strpos($deleteBody, 'lockForMembershipWrite(');
    $orgEnumeration = strpos($deleteBody, "orderBy('organizations.id')");
    expect($firstLock)->not->toBeFalse('deleteAccount は lockForMembershipWrite を呼ぶこと');
    expect($orgEnumeration)->not->toBeFalse('deleteAccount は organizations を列挙すること');
    expect($firstLock)->toBeLessThan($orgEnumeration, 'deleteAccount は組織列挙の前に user 行をロックすること');
});

/*
 * role-grant sole-gateway drift-guard。
 *
 * deleteAccount の孤児化ガードは「組織の owner 集合を変える書き込みは必ず lockForMembershipWrite で
 * その組織行をロックする」ことを直列化の前提にしている (organizations 行が owner 変更の共通 mutex)。
 * この前提は org のロール割当 (role_user) への書き込みが **ロック済みサービスメソッド経由のみ**
 * で行われて初めて成立する。本テストは owner を付与し得るロール書き込み —
 * Laratrust API (addRole/removeRole/syncRoles) と role_user pivot への直接アクセス —
 * が OrganizationMembershipService (全経路ロック済み) と OrganizationProvisioningService
 * (新規組織生成時の creator への Owner 付与のみ = 既存組織の owner 集合は変えない bootstrap 例外)
 * 以外に現れないことを静的に強制し、未ロック経路の混入 (直列化の破れ) を検出する。
 *
 * role_user を **読み取るだけ** のコード (判定クエリ) は owner 集合を変えないため直列化前提を
 * 破らない。読み取り専用の参照は $readOnly に登録して許可し、そのファイルが Laratrust の
 * 書き込み API (addRole/removeRole/syncRoles) を含まないことを別途強制する
 * (= 読み取り許可が書き込みの抜け穴にならないようにする)。
 */
test('org ロール割当 (role_user) の書き込みは既知のロック済みサービス経由のみ (owner 変更の直列化前提を守る)', function (): void {
    $appDir = dirname(__DIR__, 2).'/app';
    $allowed = [
        'Services/Organization/OrganizationMembershipService.php', // 全経路 lockForMembershipWrite 済み
        'Services/Organization/OrganizationProvisioningService.php', // 新規組織の creator への Owner 付与のみ
    ];
    // role_user を読み取るだけ (owner 集合を変えない) のため許可するファイル。
    // 下で「Laratrust 書き込み API を含まないこと」を強制する。
    $readOnly = [
        // eligibility(): 同一 user が owner として在籍する別 free personal org の存在判定
        'Services/Billing/PersonalPlanService.php',
    ];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($appDir, FilesystemIterator::SKIP_DOTS),
    );
    $offenders = [];
    $readOnlyViolations = [];
    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $relative = str_replace($appDir.'/', '', $file->getPathname());
        if (in_array($relative, $allowed, true)) {
            continue;
        }
        $contents = file_get_contents($file->getPathname()) ?: '';

        // 読み取り専用許可: Laratrust の書き込み API を含まないことだけを強制する
        // (含んでいたら「読み取りのみ」の前提が崩れているので違反として報告する)。
        if (in_array($relative, $readOnly, true)) {
            if (preg_match('/->(addRole|removeRole|syncRoles)\(/', $contents) === 1) {
                $readOnlyViolations[] = $relative;
            }

            continue;
        }

        // Laratrust API 経路 + role_user pivot への直接アクセスの双方を検出する。
        if (preg_match('/->(addRole|removeRole|syncRoles)\(|role_user/', $contents) === 1) {
            $offenders[] = $relative;
        }
    }

    expect($readOnlyViolations)->toBe(
        [],
        '読み取り専用として許可した role_user 参照が Laratrust 書き込み API を含んでいます '
        .'($readOnly から外し、ロック済みサービス経由へ移すこと)。',
    );

    expect($offenders)->toBe(
        [],
        'Laratrust ロール書き込みは lockForMembershipWrite 済みのサービス経由のみに限定すること '
        .'(未ロック経路は deleteAccount の孤児化ガードの直列化前提を破る)。',
    );
});
