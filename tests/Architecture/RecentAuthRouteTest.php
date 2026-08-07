<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\ConfirmRecentAuthController;
use App\Http\Controllers\Auth\SocialAuthController;
use App\Listeners\Auth\StampRecentAuthOnLogin;
use App\Listeners\Auth\StampRecentAuthOnPasskeyVerified;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Routing\Router;
use Tests\Support\Security\RecentAuthMiddleware;

/*
 * 機微操作 route に recent-auth middleware が付与されていることを CI で担保する (付与漏れ検出)。
 * 新たな機微操作 route を追加した PR は本 allowlist の更新を PR review で判断すること。
 */

/**
 * @return list<string>
 */
function recentAuthRequiredRouteNames(): array
{
    return [
        // API キー (発行 / 失効)
        'organizations.api-keys.store',
        'organizations.api-keys.revoke',
        // OAuth セッション失効 (組織管理経路。API キー失効と同じ機微度)
        'organizations.api-keys.sessions.revoke',
        // アカウント削除
        'settings.account.destroy',
        // パスワード初回設定 (認証手段を増やす操作。セッション奪取からの永続化を防ぐため step-up 必須)
        'settings.password.store',
        // オーナー移譲
        'organizations.transfer-ownership',
        // 組織の 2FA 必須方針トグル (Owner 専権のセキュリティ方針変更)
        'organizations.two-factor-requirement.update',
        // メンバー 2FA リセット (アカウント全体の第二要素を外す機微操作)
        'organizations.members.two-factor.reset',
        // リカバリコード表示 / 再生成 (第二要素の bypass 経路。Fortify 登録ルートへ
        // FortifyServiceProvider::attachRecentAuthToSensitiveRoutes() が後付け配線)
        'two-factor.recovery-codes',
        'two-factor.regenerate-recovery-codes',
        // 2FA 無効化 (第二要素そのものの除去。bug-hunt F-H3。同じく後付け配線)
        'two-factor.disable',
        // 2FA seed の露出 (T124)。qr-code は otpauth:// URL、secret-key は平文 seed を返す
        'two-factor.qr-code',
        'two-factor.secret-key',
        // 2FA enrollment 開始 (T124)。force=true が seed とリカバリコードを差し替える
        'two-factor.enable',
        // profile 更新 (email 変更時のみ条件付き step-up。配線は
        // FortifyServiceProvider::attachRecentAuthToSensitiveRoutes()。
        // routeHasRecentAuth は 'recent-auth.on-email-change' も str_starts_with で検出)
        'user-profile-information.update',
        // passkey 管理 (credential 集合を増減させる経路。配線は
        // App\Providers\PasskeyServiceProvider::attachMiddlewareToPasskeyRoutes())。
        // passkey.confirm / passkey.confirm-options は **satisfier 側**のため対象外
        // (自分自身に step-up を要求すると詰む)。
        'passkey.registration-options',
        'passkey.store',
        'passkey.destroy',
    ];
}

/**
 * 判定の実体は Tests\Support\Security\RecentAuthMiddleware に単一化してある (T124)。
 * TwoFactorStepUpInventoryTest (deny-by-default 目録) と同じ述語を使い、
 * 「一方は付いていると言い、他方は付いていないと言う」ドリフトを防ぐ。
 */
function routeHasRecentAuth(RoutingRoute $route): bool
{
    return RecentAuthMiddleware::isAttached($route);
}

test('機微操作 route 全件に recent-auth middleware が付与されている', function (): void {
    /** @var Router $router */
    $router = app('router');
    $routes = $router->getRoutes();
    $routes->refreshNameLookups();

    foreach (recentAuthRequiredRouteNames() as $name) {
        $route = $routes->getByName($name);
        expect($route)->not->toBeNull("route '{$name}' が存在しない (allowlist の更新漏れ?)");
        expect(routeHasRecentAuth($route))->toBeTrue("route '{$name}' に recent-auth middleware が付与されていない (付け忘れ)");
    }
});

/*
|--------------------------------------------------------------------------
| satisfier 集合の inventory (deny-by-default)
|--------------------------------------------------------------------------
|
| RecentAuthState::confirm() は「鮮度が成立した」と宣言する唯一の writer であり、
| 呼び出し元が増えることは step-up の成立条件が増えることそのものである。
| 未登録の呼び出し元が生えたら fail させ、PR review で必ず判断させる。
|
| ⚠ **この走査の限界**: token_get_all() ベースの静的走査であり、
|   「RecentAuthState を参照しているファイルの中の `->confirm(` 呼び出し」という
|   保守的な近似で検出する。完全に動的な呼び出し
|   (`$cls = 'App\Security\RecentAuthState'; app($cls)->confirm()`) は取り逃がす。
|   本テストの役割は「新しい satisfier を足すときに必ず PR で判断させる」ことに限定し、
|   完全性の証明ではない。より強い保証が必要になったら AGENTS.md のコードベース探索方針
|   どおり code-review-graph の AST グラフへ寄せること。
*/

/**
 * RecentAuthState::confirm() を呼んでよいクラスの FQCN。
 *
 * @return list<string>
 */
function recentAuthSatisfierClasses(): array
{
    return [
        // password 再入力
        ConfirmRecentAuthController::class,
        // 再SSO (step-up intent。本人性バインド済み)
        SocialAuthController::class,
        // fresh credential login (web guard・非 recaller)
        StampRecentAuthOnLogin::class,
        // passkey 検証成立 (confirm 経路。login 経路では Login が後勝ち。本人性バインド済み)
        StampRecentAuthOnPasskeyVerified::class,
    ];
}

/**
 * @return list<string> app/ 配下の php ファイル絶対パス
 */
function recentAuthPhpFilesUnderApp(): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS));

    foreach ($iterator as $file) {
        if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }

    sort($files);

    return $files;
}

/**
 * ソースが RecentAuthState::confirm() を呼んでいるか、および宣言クラスの FQCN。
 *
 * @return array{callsConfirm: bool, fqcn: string|null}
 */
function analyzeRecentAuthConfirmCalls(string $source): array
{
    $tokens = PhpToken::tokenize($source);
    $count = count($tokens);

    $namespace = null;
    $class = null;
    $referencesState = false;
    $callsConfirm = false;

    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];

        if ($token->is(T_NAMESPACE) && $namespace === null) {
            $parts = [];
            for ($j = $i + 1; $j < $count; $j++) {
                if ($tokens[$j]->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
                    $parts[] = $tokens[$j]->text;
                } elseif ($tokens[$j]->text === ';' || $tokens[$j]->text === '{') {
                    break;
                }
            }
            $namespace = implode('\\', $parts);

            continue;
        }

        if ($token->is(T_CLASS) && $class === null) {
            for ($j = $i + 1; $j < $count; $j++) {
                if ($tokens[$j]->is(T_STRING)) {
                    $class = $tokens[$j]->text;
                    break;
                }
                if ($tokens[$j]->text === '(') {
                    break;   // 匿名クラス
                }
            }

            continue;
        }

        // RecentAuthState への参照 (import / FQCN / 短縮名のいずれか)
        if ($token->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])
            && str_contains($token->text, 'RecentAuthState')) {
            $referencesState = true;

            continue;
        }

        // `->confirm(` の検出
        if ($token->is(T_OBJECT_OPERATOR)
            && isset($tokens[$i + 1])
            && $tokens[$i + 1]->is(T_STRING)
            && $tokens[$i + 1]->text === 'confirm') {
            $callsConfirm = true;
        }
    }

    $fqcn = ($namespace !== null && $namespace !== '' && $class !== null)
        ? $namespace.'\\'.$class
        : null;

    return [
        'callsConfirm' => $referencesState && $callsConfirm,
        'fqcn' => $fqcn,
    ];
}

test('RecentAuthState::confirm の呼び出し元は inventory に登録されたクラスのみ', function (): void {
    $allowed = recentAuthSatisfierClasses();
    $violations = [];
    $checked = 0;

    foreach (recentAuthPhpFilesUnderApp() as $path) {
        $source = file_get_contents($path);
        if (! is_string($source)) {
            continue;
        }

        $analysis = analyzeRecentAuthConfirmCalls($source);
        if (! $analysis['callsConfirm']) {
            continue;
        }

        $checked++;

        if ($analysis['fqcn'] === null || ! in_array($analysis['fqcn'], $allowed, true)) {
            $violations[] = "{$path} が RecentAuthState::confirm() を呼んでいるが satisfier inventory に未登録";
        }
    }

    expect($violations)->toBe([]);
    // 呼び出し元が 1 件も見つからない = 走査が壊れている (空振り drift)
    expect($checked)->toBeGreaterThan(0);
});

test('satisfier inventory のクラスは全て実在する', function (): void {
    foreach (recentAuthSatisfierClasses() as $fqcn) {
        expect(class_exists($fqcn))->toBeTrue("satisfier inventory のクラス {$fqcn} が存在しない");
    }
});
