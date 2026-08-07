<?php

declare(strict_types=1);

use App\Enums\Security\InvitationResolutionScope;

/*
 * OrganizationInvitation のクエリ起点の deny-by-default 目録。
 *
 * 守る不変条件 (裁定 AG-113 の必須要素 (b)):
 *   「**受信者視点の解決・一覧・件数は、必ず scopeActivePendingForEmail の 1 本を再利用する**」
 * これがずれると「件数は出るのに受諾できない」が起き、さらに悪いことに、
 * 受信者向け経路が別の絞り込みで招待に到達すると**他人宛の招待に届く存在オラクル**になる。
 *
 * 本テストは app/ 配下で OrganizationInvitation のクエリを起点する箇所を機械抽出し、
 * 5 分類のいずれかへの登録を要求する (未登録は fail / 実在しない登録も fail)。
 * さらに RecipientScopedPendingSet に分類した箇所は、**本文に activePendingForEmail(
 * が現れること**を要求する (分類したのに別の絞り込みを書く退行の検出)。
 * LockedRowReload に分類した箇所は、**lockForUpdate() があり、かつ主キーが
 * 解決済みモデル由来 (`whereKey($model->id)`) であること**を要求する
 * (「新しい到達経路を作らない」という免除の根拠そのものを機械検証する)。
 *
 * ★空振り対策を 3 つ持つ:
 *   (1) 母集団 floor — 抽出が 0 件 / 縮小したら fail (セレクタの空振り検出)
 *   (2) RecipientScopedPendingSet の exact-fit cap — 現在値ちょうど。
 *       受信者視点の解決口を増やす差分は必ずこの数値を変える形で現れ、再レビューを強制する
 *   (3) 負のコントロール — 分類済み各 case が 1 件以上存在すること (分類の死に枝を作らない)
 *
 * ★**保証範囲を誇張しない**: 抽出は字句 (文字列一致) ベースであり、
 *   `$model = OrganizationInvitation::class; $model::query()` のように変数経由で
 *   モデルを扱う書き方は検出できない。これは既存の ModelDirectFetchInvariantTest /
 *   MembershipWriteLockInventoryTest と同じ限界である。
 */

/** 目録キー = "{app/ からの相対パス}#{メソッド名}"。 */
function invitationResolutionInventory(): array
{
    $recipient = InvitationResolutionScope::RecipientScopedPendingSet;
    $token = InvitationResolutionScope::TokenHashLookup;
    $orgScoped = InvitationResolutionScope::OrganizationScoped;
    $model = InvitationResolutionScope::ModelInternal;
    $lockedReload = InvitationResolutionScope::LockedRowReload;

    return [
        'Models/OrganizationInvitation.php#findActiveByPlainToken' => [$model,
            '平文 token の sha256 照合による active 解決の単一口。email での絞り込みを持たない'
            .' (列挙面を広げない)。MatchesInvitationEmail / acceptInvitationIfValid /'
            .' register prefill が共有する。'],
        'Models/OrganizationInvitation.php#scopeActive' => [$model,
            'active (未受諾・未失効・期限内) の定義そのもの。受諾可能性の判定条件を'
            .' 1 箇所に集約し、個別判定 (isExpired / isAccepted / isRevoked) とのドリフトを防ぐ。'],
        'Models/OrganizationInvitation.php#scopeActivePendingForEmail' => [$model,
            '受信者視点の絞り込みの定義そのもの。active + blind index 完全一致 + 組織実在の'
            .' 3 条件がすべて 0 件へ collapse することが、一律 404 に畳める根拠。'],

        'Http/Controllers/Organizations/InvitationAcceptanceController.php#show' => [$token,
            '署名なし token URL の確認画面。token_hash 照合で 1 件引き、無効理由 (不在/取消/受諾済/'
            .'期限切れ) を出し分けずに同一の Invitations/Invalid ページへ畳む (token オラクル封じ)。'],
        'Http/Controllers/Admin/UserManagementController.php#index' => [$orgScoped,
            '管理者視点の招待一覧。$organization->invitations() 経由でのみ引き、現在組織の'
            .'招待だけを列挙する (cross-org 不可。organization は membership スコープの binder 解決済み)。'],
        'Rules/MatchesInvitationEmail.php#validate' => [$token,
            'register フォームの email 一致検証。session の平文 token を findActiveByPlainToken へ'
            .'渡すだけで、email から招待を引くことはしない (列挙面を広げない)。'],

        'Services/Organization/OrganizationMembershipService.php#pendingInvitationsQuery' => [$recipient,
            '受信者視点の唯一のクエリ起点。未ログイン・未 verified・email 空は null を返し'
            .' DB を引かない。一覧・件数・受諾解決がすべてここを通る。'],
        'Services/Organization/OrganizationMembershipService.php#acceptInvitation' => [$token,
            'POST token 受諾。token_hash 照合で解決し、失効/期限/受諾済みを個別メッセージに'
            .'出し分ける (token 保持者向けの既存契約)。'],
        'Services/Organization/OrganizationMembershipService.php#acceptInvitationIfValid' => [$token,
            'register 経路の受諾。findActiveByPlainToken で解決し、招待 email と登録 email の'
            .'一致を要求する (MatchesInvitationEmail と対で二重防御)。'],
        'Services/Organization/OrganizationMembershipService.php#resolveRegisterPrefillEmail' => [$token,
            'register 画面の email prefill。session の平文 token を token_hash 照合で解決するだけで、'
            .'email 側から招待を引かない (stale token は forget して null)。'],
        'Services/Organization/OrganizationMembershipService.php#joinOrganization' => [$lockedReload,
            '受諾確定の共通コア。id は呼び出し元が既に解決済みの招待の主キーで、ここでは'
            .'ロック下の再検証 (受諾済/取消/期限) のために同じ行を読み直すだけ (新しい到達経路を作らない)。'],
        'Services/Organization/OrganizationMembershipService.php#hasPendingInvitation' => [$orgScoped,
            '管理者視点。$organization->invitations() 経由で同一組織内の重複招待だけを見る'
            .' (中立メッセージのための存在判定であり受信者視点ではない)。'],
    ];
}

/** 理由の最低文字数 (「短い」で通さない)。 */
function invitationResolutionReasonMinLength(): int
{
    return 30;
}

/**
 * 抽出されるクエリ起点の下限 (空振り drift ガード)。**実測値ちょうど**。
 * セレクタが壊れて母集団が縮んだら fail する。
 */
function invitationResolutionSiteFloor(): int
{
    return 12;
}

/**
 * RecipientScopedPendingSet の exact-fit cap (**現在値ちょうど**)。
 *
 * 受信者視点の解決口を増やす差分は必ずこの数値を変える形で現れ、
 * 「その経路も scopeActivePendingForEmail を再利用しているか」の再レビューを強制する。
 */
function invitationResolutionRecipientCap(): int
{
    return 1;
}

/**
 * OrganizationInvitation のクエリ起点とみなす字句セレクタ。
 *
 * @return list<string>
 */
function invitationResolutionSelectors(): array
{
    return [
        'OrganizationInvitation::query(',
        'OrganizationInvitation::where',
        'OrganizationInvitation::find',
        '->invitations()',
        'activePendingForEmail(',
    ];
}

/**
 * モデル自身のファイル (app/Models/OrganizationInvitation.php) にだけ適用する追加セレクタ。
 * scope 定義 / 静的解決口はモデル内では `$query->` / `self::query(` の形で書かれるため。
 *
 * @return list<string>
 */
function invitationResolutionModelSelectors(): array
{
    return ['self::query(', '$query->'];
}

/** app/ 配下の PHP ファイル (相対パス => 絶対パス)。 */
function invitationResolutionAppFiles(): array
{
    $appDir = dirname(__DIR__, 2).'/app';
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($appDir, FilesystemIterator::SKIP_DOTS),
    );

    $files = [];
    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $files[str_replace($appDir.'/', '', $file->getPathname())] = $file->getPathname();
    }
    ksort($files);

    return $files;
}

/**
 * クエリ起点を持つメソッドを抽出する。
 *
 * ReflectionClass の getStartLine()/getEndLine() でメソッド本文へ切り分け、
 * 本文にセレクタが現れるものだけを返す (MembershipWriteLockInventoryTest の bodyOf() と同じ手法)。
 *
 * @return array<string, string> 目録キー => メソッド本文
 */
function invitationResolutionSites(): array
{
    $modelRelativePath = 'Models/OrganizationInvitation.php';
    $sites = [];

    foreach (invitationResolutionAppFiles() as $relative => $absolute) {
        $class = 'App\\'.str_replace('/', '\\', substr($relative, 0, -4));
        if (! class_exists($class) && ! trait_exists($class) && ! interface_exists($class) && ! enum_exists($class)) {
            continue;
        }

        $selectors = invitationResolutionSelectors();
        if ($relative === $modelRelativePath) {
            $selectors = [...$selectors, ...invitationResolutionModelSelectors()];
        }

        $lines = file($absolute);
        if ($lines === false) {
            continue;
        }

        $reflection = new ReflectionClass($class);
        foreach ($reflection->getMethods() as $method) {
            if ($method->getDeclaringClass()->getName() !== $class) {
                continue;
            }
            // trait 由来のメソッドは declaringClass が使用側クラスになるが、行番号は
            // trait のファイルを指す。別ファイルの行を切り出さないよう定義ファイルで弾く。
            if ($method->getFileName() !== $absolute) {
                continue;
            }
            $start = $method->getStartLine();
            $end = $method->getEndLine();
            if ($start === false || $end === false) {
                continue;
            }
            $body = implode('', array_slice($lines, $start - 1, $end - $start + 1));

            foreach ($selectors as $selector) {
                if (str_contains($body, $selector)) {
                    $sites[$relative.'#'.$method->getName()] = $body;

                    break;
                }
            }
        }
    }

    ksort($sites);

    return $sites;
}

test('OrganizationInvitation のクエリ起点はすべて目録へ分類登録されている (未登録は fail)', function (): void {
    $sites = invitationResolutionSites();
    $inventory = invitationResolutionInventory();

    $unclassified = array_values(array_diff(array_keys($sites), array_keys($inventory)));

    expect($unclassified)->toBe([],
        'OrganizationInvitation の新しいクエリ起点は InvitationResolutionScope の'
        .'いずれかへ理由付きで登録してください (deny-by-default)。'
        .PHP_EOL.implode(PHP_EOL, $unclassified));
});

test('目録に実在しないクエリ起点が残っていない (stale 検出)', function (): void {
    $sites = invitationResolutionSites();
    $inventory = invitationResolutionInventory();

    $stale = array_values(array_diff(array_keys($inventory), array_keys($sites)));

    expect($stale)->toBe([],
        '目録に実在しないクエリ起点が残っています (メソッド名変更 / 削除に追随してください)。'
        .PHP_EOL.implode(PHP_EOL, $stale));
});

test('目録の理由は 30 文字以上で分類は enum である', function (): void {
    $violations = [];

    foreach (invitationResolutionInventory() as $key => $entry) {
        [$scope, $reason] = $entry;
        expect($scope)->toBeInstanceOf(InvitationResolutionScope::class);
        if (mb_strlen($reason) < invitationResolutionReasonMinLength()) {
            $violations[] = $key.' (理由 '.mb_strlen($reason).' 文字)';
        }
    }

    expect($violations)->toBe([],
        '目録の理由は '.invitationResolutionReasonMinLength().' 文字以上で書いてください。'
        .PHP_EOL.implode(PHP_EOL, $violations));
});

test('受信者視点に分類した箇所は scopeActivePendingForEmail を再利用している', function (): void {
    $sites = invitationResolutionSites();
    $violations = [];

    foreach (invitationResolutionInventory() as $key => [$scope, $reason]) {
        if ($scope !== InvitationResolutionScope::RecipientScopedPendingSet) {
            continue;
        }
        $body = $sites[$key] ?? '';
        if (! str_contains($body, 'activePendingForEmail(')) {
            $violations[] = $key;
        }
    }

    expect($violations)->toBe([],
        '受信者視点の解決は scopeActivePendingForEmail の再利用のみで書いてください '
        .'(絞り込みを手書きすると件数と受諾がずれ、他人宛の招待へ届く経路になりうる)。'
        .PHP_EOL.implode(PHP_EOL, $violations));
});

test('ロック下再読取に分類した箇所は「解決済みモデルの主キー + lockForUpdate」の形をしている', function (): void {
    // LockedRowReload は「新しい到達経路を作らない」ことを根拠に存在秘匿の視点を免除される
    // 分類であり、放置すると**外部入力 id の直引きの逃げ道**になる (分類したもの勝ち)。
    // そこで根拠そのもの (a) ロック下であること (b) 主キーが**既に解決済みのモデル**由来で
    // あること を本文で機械検証する。文字列 id / route parameter を直接渡す形は落ちる。
    $sites = invitationResolutionSites();
    $violations = [];

    foreach (invitationResolutionInventory() as $key => [$scope, $reason]) {
        if ($scope !== InvitationResolutionScope::LockedRowReload) {
            continue;
        }
        $body = $sites[$key] ?? '';
        if (! str_contains($body, 'lockForUpdate(')) {
            $violations[] = $key.' (lockForUpdate() が無い = ロック下の再検証になっていない)';
        }
        // 例: whereKey($invitation->id) — 既に解決済みのモデルインスタンス由来の主キー。
        // whereKey($invitationId) のような未解決の外部入力は一致しない。
        if (preg_match('/whereKey\(\$[A-Za-z_][A-Za-z0-9_]*->(id|getKey\(\))\)/', $body) !== 1) {
            $violations[] = $key.' (主キーが解決済みモデル由来 ($model->id) の whereKey になっていない)';
        }
    }

    expect($violations)->toBe([],
        'LockedRowReload は「別分類で解決済みの招待を、同一トランザクション内で'
        .'行ロック付きに読み直すだけ」の経路にのみ使えます。未解決の id から引く場合は'
        .'受信者視点 / 管理者視点のどちらかへ寄せてください。'
        .PHP_EOL.implode(PHP_EOL, $violations));
});

test('抽出件数が floor を下回らない (セレクタ空振りの検出)', function (): void {
    $sites = invitationResolutionSites();

    expect(count($sites))->toBeGreaterThanOrEqual(invitationResolutionSiteFloor(),
        'OrganizationInvitation のクエリ起点の抽出件数が floor を下回りました。'
        .'セレクタが空振りしている可能性があります (削除が意図的なら floor を下げてください)。');
});

test('受信者視点の分類件数が exact-fit cap ちょうどである', function (): void {
    $recipients = array_keys(array_filter(
        invitationResolutionInventory(),
        static fn (array $entry): bool => $entry[0] === InvitationResolutionScope::RecipientScopedPendingSet,
    ));

    expect(count($recipients))->toBe(invitationResolutionRecipientCap(),
        '受信者視点の解決口の数が変わりました。増やす場合は「その経路も'
        .' scopeActivePendingForEmail を再利用しているか」を再レビューし、'
        .'invitationResolutionRecipientCap() を書き換えてください。');
});

test('各分類 case に 1 件以上の実体がある (死に枝を作らない)', function (): void {
    $used = array_map(static fn (array $entry): string => $entry[0]->value, invitationResolutionInventory());
    $missing = [];

    foreach (InvitationResolutionScope::cases() as $case) {
        if (! in_array($case->value, $used, true)) {
            $missing[] = $case->value;
        }
    }

    expect($missing)->toBe([],
        '実体の無い分類 case があります (使われない分類は形骸化するため、'
        .'case を消すか実体を分類してください)。'.PHP_EOL.implode(PHP_EOL, $missing));
});
