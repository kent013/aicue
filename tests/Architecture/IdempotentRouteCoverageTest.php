<?php

declare(strict_types=1);

use App\Enums\Security\IdempotencyWiringExemption;
use App\Http\Middleware\IdempotentRequest;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/*
 * 冪等配線 (idempotent middleware) の付与漏れ invariant (deny-by-default)。
 *
 * 「`api/v1/*` の変更系 route は `idempotent` をちょうど 1 本持つ」を機械強制する。
 * 持たないものは型付き分類 + 30 文字以上の根拠で exemption inventory へ登録させる。
 *
 * ★母集団は URI prefix `api/v1/` × 変更系メソッド。**vendor 登録の route も外さない**
 *   (MCP transport の 2 本も母集団に入り、免除理由という形で根拠が残る)。
 *   `oauth/*` を入れないのは RFC 6749/8628 の token endpoint が
 *   Idempotency-Key を仕様に持たないため (スコープ外)。
 *
 * ★実効 middleware 列は Router::gatherRouteMiddleware() で取得する
 *   (`route:list --json` は group 名が展開されず誤判定するため使わない)。
 *
 * ★**保証しないこと**: 本 gate は `api/v1/` 配下しか見ない。web (session + CSRF) の
 *   書込 route、`oauth/*`、将来別 prefix で生える機械向け API には**沈黙する**。
 *   別 prefix の API を足すときは母集団設計から見直すこと。
 */

/**
 * 変更系 HTTP メソッド。
 *
 * @return list<string>
 */
function idempotentCoverageMutatingMethods(): array
{
    return ['POST', 'PUT', 'PATCH', 'DELETE'];
}

/**
 * 母集団件数の**下限**。現在値ちょうど (exact fit)。
 *
 * ★母集団が 6 本しかないため、下限に余裕を持たせるとセレクタが壊れて
 *   母集団が半減しても気づけない。exact fit なら prefix の typo や
 *   メソッド集合の縮小が必ず赤になる。増やすときはこの数値を書き換えること。
 */
function idempotentCoverageRouteFloor(): int
{
    return 6;
}

/** exemption 件数の上限。**現在値ちょうど** (exact fit) */
function idempotentCoverageExemptionCap(): int
{
    // ★余裕を 1 でも持たせると、その 1 本は「個別の根拠も再レビューも無しに
    //   免除できる枠」になる。上げる前に必ず再検討すること。
    return 3;
}

/**
 * case 別上限 (分類の偏り検出。array_sum で全体 cap を導出しない)。
 *
 * @return array<string, int>
 */
function idempotentCoverageExemptionCapByCase(): array
{
    return [
        IdempotencyWiringExemption::SelfRevocationUnreachableReplay->value => 1,
        IdempotencyWiringExemption::McpTransportPerToolEnforcement->value => 1,
        IdempotencyWiringExemption::VendorMethodNotAllowedStub->value => 1,
    ];
}

/** exemption 理由の最低文字数 (「同上」「N/A」を機械的に弾く) */
function idempotentCoverageReasonMinLength(): int
{
    return 30;
}

/**
 * `idempotent` を持たないことが正しいと裁定した route の inventory。
 *
 * @return array<string, array{IdempotencyWiringExemption, string}>
 */
function idempotentCoverageExemptions(): array
{
    $revoke = IdempotencyWiringExemption::SelfRevocationUnreachableReplay;
    $transport = IdempotencyWiringExemption::McpTransportPerToolEnforcement;
    $stub = IdempotencyWiringExemption::VendorMethodNotAllowedStub;

    return [
        'api.v1.me.session.revoke' => [$revoke,
            'RevokeSessionController::destroy() は actor 自身の OAuth session を失効させる。'
            .'成功後は同じ Bearer token が auth:api-oauth / resolve.api-actor の段で 401 になるため、'
            .'idempotent を配線しても保存応答がクライアントへ返る経路が構造的に存在しない。'
            .'加えて失効操作自体が冪等 (session が既に無くても同じ結果)。'
            .'この前提は IdempotencyExemptionPremiseTest が behavioral に固定する。'],

        'POST /api/v1/mcp' => [$transport,
            'Laravel\Mcp\Server\Registrar::web() が登録する MCP transport の単一 endpoint。'
            .'冪等の単位は transport ではなく tool 呼び出しであり、書き込み tool への'
            .'idempotency_key 必須化は AppMcpTool::handle() の中央分岐が担う'
            .'(McpWriteToolIdempotencyEnforcementTest が強制)。'],

        'DELETE /api/v1/mcp' => [$stub,
            'Registrar::web() が登録する定数 405 スタブ (Allow: POST)。MCP の session 終了 API'
            .'非対応の表明であり、ハンドラは本体処理へ一切到達しないため冪等性の概念が無い。'],
    ];
}

/**
 * 解決後 middleware 列 (Closure を除いた文字列 entry のみ)。
 *
 * @return list<string>
 */
function idempotentCoverageResolvedMiddleware(RoutingRoute $route): array
{
    /** @var Router $router */
    $router = Route::getFacadeRoot();

    return array_values(array_filter(
        $router->gatherRouteMiddleware($route),
        static fn (mixed $entry): bool => is_string($entry),
    ));
}

/** 実効 middleware 列に含まれる IdempotentRequest の本数 */
function idempotentCoverageEntryCount(RoutingRoute $route): int
{
    $count = 0;
    foreach (idempotentCoverageResolvedMiddleware($route) as $entry) {
        if (is_a(Str::before($entry, ':'), IdempotentRequest::class, true)) {
            $count++;
        }
    }

    return $count;
}

/** route の inventory キー (名前があれば名前、無ければ `{METHOD} /{uri}`) */
function idempotentCoverageRouteLabel(RoutingRoute $route): string
{
    $name = $route->getName();
    if ($name !== null && $name !== '') {
        return $name;
    }

    $methods = array_values(array_diff($route->methods(), ['HEAD']));

    return implode('|', $methods).' /'.$route->uri();
}

/** @return list<RoutingRoute> 母集団 (api/v1/ 配下の変更系) */
function idempotentCoverageRoutes(): array
{
    $mutating = idempotentCoverageMutatingMethods();
    $selected = [];

    foreach (Route::getRoutes() as $route) {
        if (! str_starts_with($route->uri(), 'api/v1/')) {
            continue;
        }
        if (array_intersect($mutating, $route->methods()) === []) {
            continue;
        }
        $selected[] = $route;
    }

    return $selected;
}

/**
 * 違反検出の本体 (負のコントロールから再利用するため関数に切り出す)。
 *
 * @return list<string>
 */
function idempotentCoverageViolations(): array
{
    $inventory = idempotentCoverageExemptions();
    $violations = [];

    foreach (idempotentCoverageRoutes() as $route) {
        $label = idempotentCoverageRouteLabel($route);
        $count = idempotentCoverageEntryCount($route);

        if ($count === 1) {
            continue;
        }
        if ($count === 0 && array_key_exists($label, $inventory)) {
            continue;
        }

        $violations[] = $count === 0
            ? "{$label}: idempotent が無く exemption inventory にも未登録"
            : "{$label}: idempotent が {$count} 本ある";
    }

    return $violations;
}

test('母集団が下限を下回らない (セレクタの空振り検出)', function (): void {
    $count = count(idempotentCoverageRoutes());

    expect($count)->toBeGreaterThanOrEqual(
        idempotentCoverageRouteFloor(),
        "api/v1 の変更系 route が {$count} 件しか検出されませんでした。"
        .'prefix / メソッド集合のセレクタが空振りしている可能性があります。',
    );
});

test('母集団の変更系 route は idempotent をちょうど 1 本持つか exemption に明示分類されている (未知は fail)', function (): void {
    expect(idempotentCoverageViolations())->toBe([],
        'api/v1 の変更系 route の idempotent 付与が不正です。idempotent を配線するか、'
        .'配線しないことが正しい理由を idempotentCoverageExemptions() に'
        .'IdempotencyWiringExemption + 具体的根拠付きで登録してください。'
        .PHP_EOL.implode(PHP_EOL, idempotentCoverageViolations()));
});

test('exemption inventory の key は現存する母集団 route (stale 検出)', function (): void {
    $labels = [];
    foreach (idempotentCoverageRoutes() as $route) {
        $labels[idempotentCoverageRouteLabel($route)] = true;
    }

    $stale = [];
    foreach (array_keys(idempotentCoverageExemptions()) as $key) {
        if (! isset($labels[$key])) {
            $stale[] = $key;
        }
    }

    expect($stale)->toBe([],
        'exemption inventory に現存しない route ラベル (削除/rename 済、または idempotent 付与済で'
        .'exemption が不要になったもの) があります: '.implode(', ', $stale));
});

test('exemption inventory の値は enum + 実質的な理由文字列', function (): void {
    $minLength = idempotentCoverageReasonMinLength();
    $violations = [];

    foreach (idempotentCoverageExemptions() as $label => [$exemption, $reason]) {
        if (! $exemption instanceof IdempotencyWiringExemption) {
            $violations[] = "{$label}: 第 1 要素が IdempotencyWiringExemption ではありません";
        }
        if (mb_strlen($reason) < $minLength) {
            $violations[] = "{$label}: 理由が {$minLength} 文字未満です (「同上」「N/A」で埋める運用を止めます)";
        }
    }

    expect($violations)->toBe([], PHP_EOL.implode(PHP_EOL, $violations));
});

test('exemption 件数が上限を超えない (形骸化ガード)', function (): void {
    $count = count(idempotentCoverageExemptions());

    expect($count)->toBeLessThanOrEqual(
        idempotentCoverageExemptionCap(),
        "exemption が {$count} 件あります。idempotent を貼るべき route を exemption で"
        .'逃がしている可能性があります (上限を上げる前に必ず再検討すること)。',
    );
});

test('exemption inventory の key は idempotent を 1 本も持たない (死んだ exemption の検出)', function (): void {
    // ★「ちょうど 1 本 or exemption」検査は count === 1 で先に continue するため、
    //   *配線済みなのに exemption にも登録されている* 状態を検出できない。
    //   放置すると「もう不要な免除理由」が台帳に溜まり、次に読む人を誤らせる。
    $inventory = idempotentCoverageExemptions();
    $violations = [];

    foreach (idempotentCoverageRoutes() as $route) {
        $label = idempotentCoverageRouteLabel($route);
        if (! array_key_exists($label, $inventory)) {
            continue;
        }

        $count = idempotentCoverageEntryCount($route);
        if ($count !== 0) {
            $violations[] = "{$label}: idempotent が {$count} 本付いているのに exemption にも登録されています";
        }
    }

    expect($violations)->toBe([],
        'idempotent を配線したら exemption inventory から削除してください。'
        .PHP_EOL.implode(PHP_EOL, $violations));
});

test('exemption の case 別件数が上限を超えない (分類の偏り検出)', function (): void {
    // ★走査対象は **enum の全 case**。使用中の case だけを見ると、
    //   「新しい case を足したが cap を決めていない」状態を検出できない。
    $caps = idempotentCoverageExemptionCapByCase();

    $counts = [];
    foreach (IdempotencyWiringExemption::cases() as $case) {
        $counts[$case->value] = 0;
    }
    foreach (idempotentCoverageExemptions() as [$exemption, $reason]) {
        $counts[$exemption->value]++;
    }

    $violations = [];
    foreach ($counts as $case => $count) {
        if (! array_key_exists($case, $caps)) {
            $violations[] = "{$case}: idempotentCoverageExemptionCapByCase() に上限が登録されていません";

            continue;
        }
        if ($count > $caps[$case]) {
            $violations[] = "{$case}: {$count} 件 (上限 {$caps[$case]})";
        }
    }

    foreach (array_keys($caps) as $case) {
        if (! array_key_exists($case, $counts)) {
            $violations[] = "{$case}: enum に存在しない case の上限が残っています";
        }
    }

    expect($violations)->toBe([],
        'exemption の case 別件数が上限を超えました。上限を上げる前に、'
        .'その case へ落とした route が本当に idempotent 不要かを 1 本ずつ再検討してください。'
        .PHP_EOL.implode(PHP_EOL, $violations));
});

test('負のコントロール: idempotent 無しの api/v1 変更系 route を検出する', function (): void {
    // 目録にも無く idempotent も無い route を実行時に足すと、検出器が違反として拾う
    Route::post('api/v1/__idempotency_negative_control__', fn (): string => 'ok');

    expect(idempotentCoverageViolations())
        ->toContain('POST /api/v1/__idempotency_negative_control__: idempotent が無く exemption inventory にも未登録');
});

test('正のコントロール: idempotent 付きの api/v1 変更系 route は違反にならない', function (): void {
    Route::post('api/v1/__idempotency_positive_control__', fn (): string => 'ok')
        ->middleware('idempotent');

    expect(idempotentCoverageViolations())->toBe([]);
});
