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
 * - delegatedToLocked 群: 宣言した委譲先 (メソッド名 => 必須の呼び出し文字列の map) が
 *   メソッドソースに現れること。
 *   ★かつては `joinOrganization(` のハードコードだった。別のロック済みメソッドへ委譲する経路
 *   (executeAccountDeletionRequest → deleteAccount) を足したときに実ロック検査が
 *   空振りしないよう map へ一般化してある (既存 3 本の判定は等価)。
 * - 未分類メソッドがあれば fail (drift 検出)。
 */

test('OrganizationMembershipService の書き込みメソッドは共通ロック規約に準拠する', function (): void {
    // 自身の tx 冒頭で直接ロックする mutating メソッド
    $directLock = [
        'applyConsoleRole', 'changeRole', 'removeMember', 'transferOwnership', 'deleteAccount',
        // 退会予約 / 取消 (猶予期間つき削除・凍結方式)。users 行だけを書くが、
        // deleteAccount と同じ canonical 順序 (users 昇順 → organizations 昇順) の起点に乗せ、
        // 新しいロック順序を作らない (順序の SoT を 2 クラスに分けない)
        'requestAccountDeletion', 'cancelAccountDeletion',
    ];
    // ロック済み内部メソッド経由で間接的にロックされる経路 (メソッド名 => 必須の委譲先呼び出し)。
    // ★ハードコードの 'joinOrganization(' を map へ一般化した (既存 3 本の判定は等価のまま)。
    //   委譲先が 1 種類しか無い前提を残すと、別のロック済みメソッドへ委譲する経路を
    //   足したときに「登録はできるが実ロックの検査は空振り」になる。
    $delegatedToLocked = [
        'acceptInvitation' => 'joinOrganization(',
        'acceptInvitationIfValid' => 'joinOrganization(',
        'acceptPendingInvitation' => 'joinOrganization(',
        // 予約の執行 (日次バッチ専用)。ロック・ガード・削除はすべて deleteAccount が持つ
        'executeAccountDeletionRequest' => 'deleteAccount(',
    ];
    // ロック不要 (membership/role を変えない) と判断した書き込みメソッド (根拠付き exempt)
    $exempt = [
        'inviteMember',     // 招待レコード生成のみ (membership/role 不変)
        // 招待の論理失効のみ (membership/role 不変)。**受諾との競合の最終権威は
        // joinOrganization が取る招待行の lockForUpdate 側にあり**、取り消しの UPDATE も
        // 同じ行を取るため直列化される (ここで membership ロックを取る必要はない)
        'revokeInvitation',
        // 受信者視点の read-only (表示・件数)。membership/role を変えない
        'pendingInvitationsFor',
        'pendingInvitationCountFor',
        // 読み取り専用判定 (ロック不要・表示スナップショット)。deleteAccount がロック下で権威判定する
        'organizationsBlockingDeletion',
        // 課金孤児の検知バッチ用の読み取り専用列挙 (Owner 不在の組織)。membership/role を変えない
        'organizationsWithoutOwner',
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
    $classified = array_merge($directLock, array_keys($delegatedToLocked), $exempt);
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
    foreach ($delegatedToLocked as $method => $requiredCall) {
        // {$method} は宣言した委譲先 ({$requiredCall}) を経由すること
        expect(str_contains($bodyOf($method), $requiredCall))->toBeTrue();
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
 * joinOrganization() の戻り値 (bool) 消費 drift-guard。
 *
 * joinOrganization は「ロック下再検証で受諾不能だった」を false で返す。false を捨てると
 * 呼び出し元は受諾できていないのに成功扱いで応答してしまう
 * (参加していない組織へ着地させる非正規状態を作る)。
 *
 * 本検査は token_get_all() で**呼び出し式の形**だけを見る (契約の正しさは
 * InvitationAcceptRaceTest が behavioral に見る。2 本は役割が違うので併存させる)。
 * 判定は「破棄形の拒否」で、許可形の列挙はしない (&& / || / ( / , など値が使われる文脈は
 * 無数にあり、許可側を列挙すると正しい実装を落とすため)。
 */
test('joinOrganization() の戻り値を破棄している呼び出しが無い (受諾不能を成功扱いにしない)', function (): void {
    $reflection = new ReflectionClass(OrganizationMembershipService::class);
    $path = $reflection->getFileName();
    expect($path)->not->toBeFalse();
    $source = file_get_contents((string) $path);
    expect($source)->not->toBeFalse();

    $tokens = token_get_all((string) $source);
    /** 空白 / コメントを飛ばして有意トークンの index 列を作る */
    $significant = [];
    foreach ($tokens as $index => $token) {
        if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        $significant[] = $index;
    }

    $callCount = 0;
    $violations = [];
    $unknownForms = [];

    foreach ($significant as $position => $index) {
        $token = $tokens[$index];
        if (! is_array($token) || $token[0] !== T_STRING || $token[1] !== 'joinOrganization') {
            continue;
        }
        // 呼び出しであること (次の有意トークンが `(`)
        $next = $tokens[$significant[$position + 1] ?? $index] ?? null;
        if ($next !== '(') {
            continue;
        }
        // メソッド宣言 (`private function joinOrganization(`) は呼び出しではない
        $prev = $tokens[$significant[$position - 1] ?? $index] ?? null;
        if (is_array($prev) && $prev[0] === T_FUNCTION) {
            continue;
        }

        $callCount++;
        $line = $token[2];

        // `$this->joinOrganization(` の形であること (未知の呼び出し形は deny-by-default)
        $prevPrev = $tokens[$significant[$position - 2] ?? $index] ?? null;
        $isThisCall = is_array($prev) && $prev[0] === T_OBJECT_OPERATOR
            && is_array($prevPrev) && $prevPrev[0] === T_VARIABLE && $prevPrev[1] === '$this';
        if (! $isThisCall) {
            $unknownForms[] = "line {$line}";

            continue;
        }

        // さらに 1 つ前が `;` / `{` / `}` なら式文 = 戻り値の破棄
        $beforeCall = $tokens[$significant[$position - 3] ?? $index] ?? null;
        if (in_array($beforeCall, [';', '{', '}'], true)) {
            $violations[] = "line {$line}";
        }
    }

    expect($unknownForms)->toBe([],
        'joinOrganization() の未知の呼び出し形を検出しました (人のレビューを通すため fail させています)。'
        .PHP_EOL.implode(PHP_EOL, $unknownForms));

    expect($violations)->toBe([],
        'joinOrganization() の戻り値 (false = ロック下再検証で受諾不能) を破棄している呼び出しがあります。'
        .PHP_EOL.implode(PHP_EOL, $violations));

    // exact-fit: 現在の呼び出し元は acceptInvitation / acceptInvitationIfValid /
    // acceptPendingInvitation の 3 つ。増減は必ずこの数値を変える差分として現れ、
    // 「その経路でも false を正しく消費しているか」の再レビューを強制する。
    expect($callCount)->toBe(3,
        'joinOrganization() の呼び出し元の数が変わりました。新しい経路が false を'
        .'正しく消費しているかを確認してからこの数値を更新してください。');
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
