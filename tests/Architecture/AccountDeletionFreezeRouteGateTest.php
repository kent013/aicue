<?php

declare(strict_types=1);

use App\Enums\Account\AccountDeletionFreezeAllowance;
use App\Http\Middleware\EnsureAccountNotPendingDeletion;
use App\Models\User;
use App\Services\Billing\PortalConfigurationSpec;
use App\Support\Account\AccountDeletionGrace;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Routing\Router;
use Symfony\Component\HttpFoundation\Response;

/*
 * Architecture invariant: 退会予約中の**凍結 (deny-by-default)** の母集団と allowlist を固定する。
 *
 * SoT = devnotes/20260809-0908-account-deletion-grace/detailed-design.md の PR-B (B4)。
 *
 * 記号: `U` = 凍結 middleware (EnsureAccountNotPendingDeletion) が付いた全 route、
 *       `A` = AccountDeletionFreezeAllowance の route 名集合。**`A ⊆ U`**。
 *
 * ★この gate が保証するもの:
 *   - 検査 1: `A ⊆ U` (allowlist に `U` 外の route 名を書けない = 死に登録を作らない)
 *   - 検査 2: enum の route 名が**実在し、凍結 middleware を実際に持つ**
 *   - 検査 3: **middleware が実際に bypass する集合と `A` が exact-fit** (実装と宣言の一致)
 *   - 検査 4: **`U` に無名 route があれば fail** (名前で allowlist を書けないため)
 *   - 検査 5: enum は wildcard (`*`) を持たない / 各 case の `rationale()` が 30 文字以上
 *   - 検査 6: 母集団の内外を両方向で固定する
 *       (a) `logout` / `session.status` が `U` に**含まれない** (認証回復・離脱を凍結させない)
 *       (b) `recent-auth.confirm` / `.status` / `.password` が `U` に**含まれる**
 *           (group の外へ移されたら allowlist が死に登録になる)
 *   - 検査 7: **`BillingPortal` を allowlist に置く前提の pin** —
 *       `PortalConfigurationSpec` の `subscription_update.enabled === false` /
 *       `subscription_cancel.enabled === true` / `subscription_cancel.mode === 'at_period_end'`。
 *       `billing:ensure-portal-configuration --verify` が保証するのは「Stripe 側設定と spec の
 *       **一致**」だけなので、**spec 自体を書き換えると正しい設定として受け入れられうる**。
 *       よって allowlist 登録の前提を behavioral に固定する
 *       (`ThrottleExemptionPremiseTest` / `IdempotencyExemptionPremiseTest` と同じ作法)
 *   - 検査 8: **即時削除 / 通知 open / 自動チャージ更新が `A` に無い**ことの名指し pin
 *   - 空振り検知: `U` の件数 floor / `A` の件数 exact / 母集団 0 件で fail
 *
 * ★この gate が保証しないもの (誇張しない):
 *   - **実 HTTP での遮断挙動**は見ない (route を実際に叩く全件 sweep と、
 *     取消・ブロッカー解消への到達性は `tests/Feature/Auth/AccountDeletionFreezeTest.php` の担当。
 *     Architecture lane は DB を使わないため、ここでは middleware を直接駆動して
 *     bypass 集合だけを測る)
 *   - **middleware の実行順序**は見ない (テナント境界 404 より後であることの固定は
 *     `TenantBoundaryOrderingTest` の担当)
 *   - route cache 済み起動での配線 (group 直付けなので cache に焼き込まれるが、
 *     「毎デプロイ route:cache を再生成する」運用要件そのものは機械化できない)
 *
 * DB 不使用 (Architecture lane は TestCase のみ)。
 */

/** `U` の件数下限 (空振り防止)。業務 route が丸ごと group から外れたら赤くなる。 */
const FREEZE_POPULATION_FLOOR = 60;

/** `A` の件数 (exact-fit。増減させたらこの数値も同じ diff で書き換わる)。 */
const FREEZE_ALLOWANCE_COUNT = 16;

/**
 * 凍結 middleware が付いた route (`U`)。名前をキーにする。
 *
 * @return array<string, RoutingRoute>
 */
function freezePopulation(): array
{
    /** @var Router $router */
    $router = app('router');
    $routes = $router->getRoutes();
    $routes->refreshNameLookups();

    $population = [];
    foreach ($routes as $route) {
        if (! in_array(EnsureAccountNotPendingDeletion::class, $route->gatherMiddleware(), true)
            && ! in_array('not-pending-deletion', $route->gatherMiddleware(), true)) {
            continue;
        }
        $population[(string) $route->getName()] = $route;
    }

    return $population;
}

/** 凍結 middleware が付いた route のうち**名前を持たない**もの (uri で返す)。 */
function freezeUnnamedRouteUris(): array
{
    /** @var Router $router */
    $router = app('router');
    $unnamed = [];
    foreach ($router->getRoutes() as $route) {
        if (! in_array(EnsureAccountNotPendingDeletion::class, $route->gatherMiddleware(), true)
            && ! in_array('not-pending-deletion', $route->gatherMiddleware(), true)) {
            continue;
        }
        if ($route->getName() === null || $route->getName() === '') {
            $unnamed[] = $route->uri();
        }
    }

    return $unnamed;
}

/** 予約中 (pending) の未保存 User を組み立てる (DB を使わない)。 */
function freezePendingUser(): User
{
    $user = new User;
    $requestedAt = CarbonImmutable::now();
    $user->forceFill([
        'id' => 1,
        'deletion_requested_at' => $requestedAt,
        'deletion_purge_after' => AccountDeletionGrace::purgeAfter($requestedAt),
    ]);

    return $user;
}

/**
 * 凍結 middleware を route 名 1 つに対して駆動し、$next が呼ばれた (= 通過した) かを返す。
 *
 * 実 HTTP を経由しないのは Architecture lane が DB を持たないため。ここで測るのは
 * 「middleware 単体の分岐」だけで、配線込みの挙動は Feature テストが見る。
 */
function freezeMiddlewarePasses(string $routeName, User $user): bool
{
    $request = Request::create('/architecture-probe', 'GET');
    $request->setLaravelSession(app('session.store'));
    $request->setUserResolver(static fn (): User => $user);

    $route = new RoutingRoute(['GET'], '/architecture-probe', static fn (): string => 'ok');
    $route->name($routeName);
    $request->setRouteResolver(static fn (): RoutingRoute => $route);

    $passed = false;
    (new EnsureAccountNotPendingDeletion)->handle($request, static function () use (&$passed): Response {
        $passed = true;

        return new Response('ok');
    });

    return $passed;
}

test('検査 1: allowlist は凍結母集団 U の部分集合である (A ⊆ U)', function (): void {
    $population = array_keys(freezePopulation());
    $outside = array_values(array_diff(AccountDeletionFreezeAllowance::values(), $population));

    expect($outside)->toBe([],
        'AccountDeletionFreezeAllowance に、凍結 middleware が付いていない route 名が登録されています。'
        .'凍結されない route を allowlist に書いても意味がなく (死に登録)、'
        .'「通している」という誤った安心を生みます: '.implode(', ', $outside));
});

test('検査 2: allowlist の route 名は実在し、実際に凍結 middleware を持つ', function (): void {
    /** @var Router $router */
    $router = app('router');
    $routes = $router->getRoutes();
    $routes->refreshNameLookups();

    $missing = [];
    $unprotected = [];
    foreach (AccountDeletionFreezeAllowance::cases() as $case) {
        $route = $routes->getByName($case->value);
        if ($route === null) {
            $missing[] = $case->value;

            continue;
        }
        $middleware = $route->gatherMiddleware();
        if (! in_array(EnsureAccountNotPendingDeletion::class, $middleware, true)
            && ! in_array('not-pending-deletion', $middleware, true)) {
            $unprotected[] = $case->value;
        }
    }

    expect($missing)->toBe([], '実在しない route 名が allowlist に残っています: '.implode(', ', $missing));
    expect($unprotected)->toBe([], '凍結対象でない route が allowlist にあります: '.implode(', ', $unprotected));
});

test('検査 3: middleware が実際に bypass する集合と allowlist が exact-fit である', function (): void {
    // ★この検査が守るのは「**宣言 (enum) と実装 (middleware の分岐) の一致**」であって、
    //   allowlist の増減ではない (enum に case を足すと両辺が同時に動くため点灯しない。
    //   T142 の mutation M5 で実測)。増加を捕まえるのは件数の exact-fit pin と検査 8 の
    //   名指し pin であり、この検査は middleware 側に prefix 一致 / wildcard を持ち込む
    //   改変を落とす役割を持つ。
    $user = freezePendingUser();

    $passing = [];
    foreach (array_keys(freezePopulation()) as $name) {
        if (freezeMiddlewarePasses($name, $user)) {
            $passing[] = $name;
        }
    }
    sort($passing);

    $declared = AccountDeletionFreezeAllowance::values();
    sort($declared);

    expect($passing)->toBe($declared,
        '宣言 (enum) と実装 (middleware の分岐) がずれています。'
        .'wildcard や prefix 一致を実装に入れていないか確認してください。');
});

test('検査 4: 凍結母集団に無名 route が無い (名前で allowlist を書けないため)', function (): void {
    expect(freezeUnnamedRouteUris())->toBe([],
        '凍結対象の group に無名 route があります。allowlist は route 名でしか書けないため、'
        .'無名 route は「絶対に通せない route」になります (救済経路なら詰みを生みます)。');
});

test('検査 5: allowlist に wildcard が無く、各 case の根拠が 30 文字以上ある', function (): void {
    $wildcards = [];
    $short = [];
    foreach (AccountDeletionFreezeAllowance::cases() as $case) {
        if (str_contains($case->value, '*')) {
            $wildcards[] = $case->value;
        }
        $rationale = $case->rationale();
        if (mb_strlen($rationale) < 30) {
            $short[] = "{$case->value}: ".mb_strlen($rationale).' 文字';
        }
    }

    expect($wildcards)->toBe([],
        'wildcard を許すと namespace 単位で通ってしまい (billing.* が購入・新規契約まで含む)、'
        .'凍結の意味が消えます: '.implode(', ', $wildcards));
    expect($short)->toBe([], '通す根拠は 30 文字以上で書くこと: '.implode(' / ', $short));
});

test('検査 6: 母集団の内外を両方向で固定する (認証回復は凍結しない / step-up は凍結対象)', function (): void {
    $population = array_keys(freezePopulation());

    // ★`toContain` は message 引数を取らない (第 2 引数が「もう 1 つの needle」として扱われ、
    //   期待と違う判定になる)。in_array の真偽で判定して message を付ける。
    // (a) 認証回復と離脱の手段は構造的に凍結されない。group の中へ移されたら fail。
    $frozenRecovery = array_values(array_intersect(
        ['logout', 'login', 'session.status', 'password.request', 'verification.notice'],
        $population,
    ));
    expect($frozenRecovery)->toBe([],
        '認証回復・離脱の手段が凍結対象になっています。凍結すると「ログアウトすらできない」'
        .'詰みになります: '.implode(', ', $frozenRecovery));

    // (b) step-up の 3 本は group の中にある (外へ移されたら allowlist が死に登録になる)。
    $stepUp = ['recent-auth.confirm', 'recent-auth.status', 'recent-auth.password'];
    $escaped = array_values(array_diff($stepUp, $population));
    expect($escaped)->toBe([],
        'step-up の route が凍結母集団から外れました。allowlist の該当 case は死に登録になるため、'
        .'enum 側も同じ差分で見直してください: '.implode(', ', $escaped));
});

test('検査 7: billing.portal を allowlist に置く前提 (PortalConfigurationSpec) を pin する', function (): void {
    // ★`billing:ensure-portal-configuration --verify` が見るのは「Stripe 側設定と spec の一致」
    //   だけなので、**spec 自体**を書き換えると verify は緑のまま前提だけが壊れる。
    //   「Portal は責務を減らす方向のみ」という allowlist の根拠をここで固定する。
    $features = PortalConfigurationSpec::features();

    expect($features['subscription_update']['enabled'])->toBeFalse(
        'Portal からプラン変更ができるようになると、凍結中に**新しい課金責務を作れる**ため '
        .'billing.portal を allowlist に置いた前提が崩れます。');
    expect($features['subscription_cancel']['enabled'])->toBeTrue(
        'Portal で解約できないと、退会ブロッカー (生きた課金責務) を解消する導線が消えます。');
    expect($features['subscription_cancel']['mode'] ?? null)->toBe('at_period_end');
});

test('検査 8: 猶予を迂回しうる route が allowlist に無い (名指しの pin)', function (): void {
    $declared = AccountDeletionFreezeAllowance::values();

    // 即時削除: 予約中に通すと「30 日猶予」を 1 手で迂回できる (誤操作救済が無効化される)
    expect($declared)->not->toContain('settings.account.destroy');
    // 通知 open: 遷移先へ 303 で飛ばすため、通すと業務 route / dashboard への抜け道になる
    expect($declared)->not->toContain('notifications.open');
    // 自動チャージ設定: 有効化・閾値変更を同じ endpoint が受けるため新しい課金責務の入口になる
    expect($declared)->not->toContain('billing.auto-recharge.update');
    expect($declared)->not->toContain('billing.auto-recharge.setup');
    // 新規契約・スポット購入・組織/招待の新規作成も責務を増やす方向なので通さない
    expect($declared)->not->toContain('billing.checkout');
    expect($declared)->not->toContain('billing.tickets.checkout');
    expect($declared)->not->toContain('organizations.store');
    expect($declared)->not->toContain('organizations.invitations.store');
});

test('空振り検知: 母集団と allowlist の件数を pin する', function (): void {
    $population = freezePopulation();

    expect(count($population))->toBeGreaterThan(FREEZE_POPULATION_FLOOR,
        '凍結母集団が想定より小さいです。group から middleware が外れていないか確認してください '
        .'(母集団が 0 件でも検査 1 は緑になるため、下限で pin します)。');
    expect(AccountDeletionFreezeAllowance::cases())->toHaveCount(FREEZE_ALLOWANCE_COUNT,
        '通す route を増減させたら FREEZE_ALLOWANCE_COUNT も同じ差分で書き換えてください '
        .'(「以下」ではなく「一致」で固定するのは、根拠なしに通せる余裕枠を作らないため)。');

    // 検査 3 の駆動器が死んでいたら vacuous green になる。正負を 1 本ずつ実測する。
    $user = freezePendingUser();
    expect(freezeMiddlewarePasses('settings', $user))->toBeTrue();
    expect(freezeMiddlewarePasses('dashboard', $user))->toBeFalse();

    // 未予約ユーザーは何も凍結されない (middleware が常時 deny になっていないことの対照)
    expect(freezeMiddlewarePasses('dashboard', new User))->toBeTrue();
});
