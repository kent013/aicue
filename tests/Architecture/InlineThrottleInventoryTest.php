<?php

declare(strict_types=1);

use App\Enums\Security\InlineThrottleBucketRationale;
use App\Support\Http\RouteThrottleBinder;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Routing\Router;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/*
 * inline throttle の残置 invariant (deny-by-default)。
 *
 * 「inline throttle を持つ route は目録に登録されている」を機械強制する。
 * 未登録は fail = **自前 route へ inline を足せない** (自前向けの enum case が無いため
 * 登録もできない)。これは AGENTS.md ドメイン規約 5 の機械化である。
 *
 * ★責務境界 (重複検査を作らない):
 *   - throttle が 1 本あるか            → ThrottleCoverageInventoryTest
 *   - inline の残置理由と共有上限        → **本テスト**
 *   - named limiter のキー形式と衝突     → RateLimiterKeyConventionTest
 *   - 実 HTTP での巻き添え 429 の消滅    → AuthThrottleCoverageTest
 */

/** inline 指定と判定する params (`{max},{decay}` またはパラメータなし = 既定 60,1)。 */
function inlineThrottleParamsAreInline(string $params): bool
{
    return $params === '' || preg_match('/^\d+,\d+$/', $params) === 1;
}

/** throttle entry (`{class}` or `{class}:{params}`) が inline 指定か。 */
function inlineThrottleEntryIsInline(string $entry): bool
{
    if (! RouteThrottleBinder::isThrottleEntry($entry)) {
        return false;
    }

    return inlineThrottleParamsAreInline(Str::contains($entry, ':') ? Str::after($entry, ':') : '');
}

/** throttle を 1 本以上持つ route の総数の下限 (走査の空振り検出。実測 48)。 */
function inlineThrottleThrottledRouteFloor(): int
{
    return 40;
}

/**
 * case 別の **exact fit** 件数 (`<=` ではなく `===` で照合する)。
 *
 * ★上限ではなく「ちょうどこの数」である。`<=` にすると件数が減ったときに
 *   余った枠が「個別の再検討なしに inline を足せる枠」として残ってしまう。
 *   増える方向にも減る方向にも、必ずこの数値を変える差分として現れさせる。
 *
 * @return array<string, int>
 */
function inlineThrottleRationaleExactCountByCase(): array
{
    return [
        InlineThrottleBucketRationale::VendorStatelessIpBucket->value => 2,
        // ★1 から動かさない。2 本目 = 認証済み actor の bucket 共有の再来。
        InlineThrottleBucketRationale::VendorMixedUserOrIpBucket->value => 1,
    ];
}

/**
 * case ごとに許す **action の由来** (vendor provenance)。
 *
 * ★「vendor だから inline を許す」という主張を機械化する。
 *   middleware 構成だけを見ていると、`StartSession` あり `Authenticate` なしの
 *   **自前 web route** が `VendorMixedUserOrIpBucket` として登録できてしまう。
 *   action class の名前空間を case ごとに固定することで、
 *   `App\...` の自前 controller はどの case にも当てはまらなくなる。
 *
 * ★この配列自体を書き換えれば当然すり抜けられる (`App\` を足す等)。
 *   それは目録型 gate の一般的な性質であり、**その差分がレビューに現れること**が
 *   本 gate の目的である (無言で通ることが無いこと)。
 *
 * @return array<string, list<string>> case => 許す action の名前空間接頭辞
 */
function inlineThrottleCaseVendorNamespaces(): array
{
    return [
        InlineThrottleBucketRationale::VendorStatelessIpBucket->value => ['Laravel\\Passport\\'],
        InlineThrottleBucketRationale::VendorMixedUserOrIpBucket->value => ['Livewire\\'],
    ];
}

/**
 * case ごとの適用条件を実効 middleware 列 + action の由来で機械化するための述語。
 *
 * ★分類を「作文」で終わらせないための premise 検査。vendor の更新で
 *   session の有無や controller の名前空間が変われば、根拠の文章より先にここが落ちる。
 *
 * ★**保証範囲を誇張しない**: 「`StartSession` が無い」は
 *   「`$request->user()` が絶対に null」を意味しない (独自の認証 middleware が
 *   user resolver を差し替える余地は残る)。ここで閉じているのは
 *   **session guard と framework の認証 middleware という 2 つの構造的な経路**だけである。
 *
 * @return array<string, callable(RoutingRoute): bool>
 */
function inlineThrottleCasePremises(): array
{
    $hasClass = static function (RoutingRoute $route, string $class): bool {
        /** @var Router $router */
        $router = Route::getFacadeRoot();
        foreach ($router->gatherRouteMiddleware($route) as $entry) {
            if (is_string($entry) && is_a(Str::before($entry, ':'), $class, true)) {
                return true;
            }
        }

        return false;
    };

    $fromVendor = static function (RoutingRoute $route, string $case): bool {
        $action = Str::before($route->getActionName(), '@');
        foreach (inlineThrottleCaseVendorNamespaces()[$case] ?? [] as $prefix) {
            if (str_starts_with($action, $prefix)) {
                return true;
            }
        }

        return false; // Closure action もここで false (由来を証明できない)
    };

    $stateless = InlineThrottleBucketRationale::VendorStatelessIpBucket->value;
    $mixed = InlineThrottleBucketRationale::VendorMixedUserOrIpBucket->value;

    return [
        // stateless = session guard も framework の認証 middleware も通らない
        //           → この 2 経路では user へ倒れない (= キーは IP になる)。
        //             「絶対に user にならない」ではない (上の保証範囲の注記を参照)
        $stateless => static fn (RoutingRoute $route): bool => $fromVendor($route, $stateless)
            && ! $hasClass($route, StartSession::class)
            && ! $hasClass($route, AuthenticatesRequests::class),
        // mixed = session はあるが auth 必須ではない → user id にも IP にもなる
        $mixed => static fn (RoutingRoute $route): bool => $fromVendor($route, $mixed)
            && $hasClass($route, StartSession::class)
            && ! $hasClass($route, AuthenticatesRequests::class),
    ];
}

/** 根拠文字列の最低文字数。 */
function inlineThrottleReasonMinLength(): int
{
    return 30;
}

/**
 * inline throttle を持つことが正しいと裁定した route の目録。
 *
 * @return array<string, array{InlineThrottleBucketRationale, string}>
 */
function inlineThrottleInventory(): array
{
    $statelessIp = InlineThrottleBucketRationale::VendorStatelessIpBucket;
    $mixed = InlineThrottleBucketRationale::VendorMixedUserOrIpBucket;

    return [
        'passport.token' => [$statelessIp,
            'Laravel\Passport\RouteRegistrar::forAccessTokens() が middleware([\'throttle\']) を'
            .'ハードコードしており、設定でも RouteThrottleBinder でも置換できない'
            .'(後付けすると二重付与になり ThrottleCoverageInventoryTest が fail する)。'
            .'StartSession も framework の認証 middleware も通らないため、'
            .'session guard または framework の認証 middleware 経由で user へ倒れる経路がない'
            .'(この構造を premise が機械検査する)。'],

        'passport.device.code' => [$statelessIp,
            '上記 passport.token と同じく Passport がハードコードした throttle (既定 60/min)。'
            .'device authorization grant の code 発行 endpoint で StartSession も framework の'
            .'認証 middleware も通らず、この 2 経路によって認証済み actor の bucket と交わる構造ではない'
            .'(この構造を premise が機械検査する)。'],

        'livewire.upload-file' => [$mixed,
            'Livewire\Features\SupportFileUploads\FileUploadController::middleware() が'
            .'config(\'livewire.temporary_file_upload.middleware\') ?: \'throttle:60,1\' を返す。'
            .'上書きには config/livewire.php の公開が要るが mergeConfigFrom は浅い merge のため'
            .'部分定義では temporary_file_upload 配下の disk/rules/cleanup を巻き添えで失う。'
            .'T125 の移行後、**認証済み actor の inline bucket を使う route はこれ 1 本だけ**に'
            .'なった (未認証時に IP へ倒れる分は passport 2 本と同じ性質であり、この主張は'
            .'認証済み側の bucket についてのみ成立する)。'],
    ];
}

/** route の目録キー (名前があれば名前、無ければ `{METHOD} /{uri}`)。 */
function inlineThrottleRouteLabel(RoutingRoute $route): string
{
    $name = $route->getName();
    if ($name !== null && $name !== '') {
        return $name;
    }

    return implode('|', array_values(array_diff($route->methods(), ['HEAD']))).' /'.$route->uri();
}

/** @return array{inline: list<string>, throttled: int} 母集団の走査結果。 */
function inlineThrottleScan(): array
{
    /** @var Router $router */
    $router = Route::getFacadeRoot();
    $inline = [];
    $throttled = 0;

    foreach (Route::getRoutes() as $route) {
        $entries = RouteThrottleBinder::throttleEntries($router, $route);
        if ($entries === []) {
            continue;
        }
        $throttled++;

        foreach ($entries as $entry) {
            if (inlineThrottleEntryIsInline($entry)) {
                $inline[] = inlineThrottleRouteLabel($route);

                break;
            }
        }
    }

    sort($inline);

    return ['inline' => $inline, 'throttled' => $throttled];
}

test('分類器は inline 指定と named 指定を取り違えない (負のコントロール)', function (): void {
    $throttle = 'Illuminate\Routing\Middleware\ThrottleRequests';

    // inline 側
    expect(inlineThrottleEntryIsInline($throttle.':6,1'))->toBeTrue();
    expect(inlineThrottleEntryIsInline($throttle.':60,1'))->toBeTrue();
    expect(inlineThrottleEntryIsInline($throttle))->toBeTrue('パラメータなし throttle は既定 60,1 の inline');
    expect(inlineThrottleEntryIsInline('Illuminate\Routing\Middleware\ThrottleRequestsWithRedis:10,1'))
        ->toBeTrue('redis 実装も ThrottleRequests の派生であり inline 判定の対象');

    // named 側
    expect(inlineThrottleEntryIsInline($throttle.':password-verify'))->toBeFalse();
    expect(inlineThrottleEntryIsInline($throttle.':api-read'))->toBeFalse();

    // throttle ですらない middleware
    expect(inlineThrottleEntryIsInline('Illuminate\Auth\Middleware\Authenticate:web'))->toBeFalse();
});

test('throttle を持つ route の総数が下限を下回らない (走査の空振り検出)', function (): void {
    $scan = inlineThrottleScan();

    expect($scan['throttled'])->toBeGreaterThanOrEqual(
        inlineThrottleThrottledRouteFloor(),
        "throttle を持つ route が {$scan['throttled']} 件しか検出されませんでした。"
        .'middleware 解決が壊れている可能性があります (この場合 inline 母集団も 0 件になり、'
        .'目録検査が空振りで green になってしまう)。',
    );
});

test('inline throttle を持つ route は目録に登録されている (未知は fail)', function (): void {
    $inventory = inlineThrottleInventory();
    $unknown = array_values(array_diff(inlineThrottleScan()['inline'], array_keys($inventory)));

    expect($unknown)->toBe([],
        'inline throttle (`throttle:{max},{decay}`) を持つ route が目録に未登録です。'
        .'inline のキーは actor id だけで route 名も limiter 名も入らないため、'
        .'**同一 actor の全 inline route が 1 bucket を共有します**。'
        .'named limiter を新設してレーンを分けてください'
        .'(自前 route 向けの InlineThrottleBucketRationale case は意図的に存在しません)。'
        .PHP_EOL.implode(PHP_EOL, $unknown));
});

test('目録の key は現存する inline throttle route (stale 検出 / 母集団 0 件の検出)', function (): void {
    $inline = inlineThrottleScan()['inline'];
    $stale = array_values(array_diff(array_keys(inlineThrottleInventory()), $inline));

    expect($stale)->toBe([],
        '目録にあるが inline throttle を持たない route があります (named 化済み・削除済み、'
        .'または母集団の走査が壊れている)。named 化したら目録から消してください。'
        .PHP_EOL.implode(PHP_EOL, $stale));
});

test('目録の値は enum + 実質的な根拠文字列', function (): void {
    $min = inlineThrottleReasonMinLength();
    $violations = [];

    foreach (inlineThrottleInventory() as $label => [$rationale, $reason]) {
        if (! $rationale instanceof InlineThrottleBucketRationale) {
            $violations[] = "{$label}: 第 1 要素が InlineThrottleBucketRationale ではありません";
        }
        if (mb_strlen($reason) < $min) {
            $violations[] = "{$label}: 根拠が {$min} 文字未満です";
        }
    }

    expect($violations)->toBe([], PHP_EOL.implode(PHP_EOL, $violations));
});

test('case 別件数が宣言値とちょうど一致する (enum 全 case を走査。未登録も fail)', function (): void {
    $expected = inlineThrottleRationaleExactCountByCase();

    $counts = [];
    foreach (InlineThrottleBucketRationale::cases() as $case) {
        $counts[$case->value] = 0;
    }
    foreach (inlineThrottleInventory() as [$rationale, $reason]) {
        $counts[$rationale->value]++;
    }

    $violations = [];
    foreach ($counts as $case => $count) {
        if (! array_key_exists($case, $expected)) {
            $violations[] = "{$case}: inlineThrottleRationaleExactCountByCase() に件数がありません";

            continue;
        }
        // ★`>` ではなく `!==`。減った方向も差分として現れさせる (余った枠を残さない)。
        if ($count !== $expected[$case]) {
            $violations[] = "{$case}: {$count} 件 (宣言 {$expected[$case]} 件)";
        }
    }
    foreach (array_keys($expected) as $case) {
        if (! array_key_exists($case, $counts)) {
            $violations[] = "{$case}: enum に存在しない case の件数宣言が残っています";
        }
    }

    expect($violations)->toBe([],
        '件数を増やす前に、その route を named limiter へ移せないかを必ず再検討すること。'
        .'減った場合は宣言値を下げること (枠を残さない)。'
        .PHP_EOL.implode(PHP_EOL, $violations));
});

test('分類 case の適用条件が実効 middleware 列と一致する (premise の固定)', function (): void {
    // ★根拠の文章ではなく**実効 middleware 列**で分類の前提を固定する。
    //   vendor の更新で passport が session を張るようになれば、ここが先に落ちる。
    $premises = inlineThrottleCasePremises();
    $inventory = inlineThrottleInventory();
    $violations = [];

    foreach (Route::getRoutes() as $route) {
        $label = inlineThrottleRouteLabel($route);
        if (! array_key_exists($label, $inventory)) {
            continue;
        }
        $case = $inventory[$label][0]->value;
        if (! array_key_exists($case, $premises)) {
            $violations[] = "{$case}: premise が定義されていません";

            continue;
        }
        if (! $premises[$case]($route)) {
            $violations[] = "{$label}: {$case} の適用条件 (session / auth の有無) を満たしていません";
        }
    }

    expect($violations)->toBe([], PHP_EOL.implode(PHP_EOL, $violations));
});
