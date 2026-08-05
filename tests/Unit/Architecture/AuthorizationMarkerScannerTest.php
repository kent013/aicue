<?php

declare(strict_types=1);

use Tests\Support\AuthorizationMarkerScanner;

/*
 * 認可マーカー解析器そのものの positive/negative 固定。
 *
 * ControllerAuthorizationGateTest (変更系 route の deny-by-default 認可 gate) の
 * 検出ロジックは **gate 自体がセキュリティ機構**であり、
 * 「一時的にコメントアウトして落ちるか確認する」手動検証では後の改修に対する回帰が効かない。
 * route inventory に依存しない純粋 helper として切り出し、直接テストする。
 *
 * ★ケース「チェーンが切れている 2 文」と「T_USE の 3 用途」が本テストの存在理由。
 *   前者は正規表現実装が誤合格していた形、後者は名前空間 import と
 *   lexical use / trait use の混同を、それぞれ恒久的に固定する。
 *
 * DB 非依存の Unit テスト (route 登録も RefreshDatabase も不要)。
 */

test('Gate::authorize は認可マーカーとして検出される', function (): void {
    expect(AuthorizationMarkerScanner::hasAuthorizationMarker(
        "Gate::authorize('update', \$item);"
    ))->toBeTrue();
});

test('Gate::forUser(...)->authorize は認可マーカーとして検出される', function (): void {
    expect(AuthorizationMarkerScanner::hasAuthorizationMarker(
        "Gate::forUser(\$user)->authorize('update', \$item);"
    ))->toBeTrue();
});

test('複数行のメソッドチェーンでも検出される', function (): void {
    expect(AuthorizationMarkerScanner::hasAuthorizationMarker(
        "Gate::forUser(\$user)\n    ->authorize('update', \$item);"
    ))->toBeTrue();
});

test('引数に配列・クロージャ・ネスト括弧があっても対応括弧を正しく追える', function (): void {
    expect(AuthorizationMarkerScanner::hasAuthorizationMarker(
        "Gate::forUser(\$a->b(c(\$d)))->authorize('create', [Item::class, \$project]);"
    ))->toBeTrue();

    expect(AuthorizationMarkerScanner::hasAuthorizationMarker(
        "Gate::forUser(array_map(static fn (\$x) => \$x, [1, 2])[0])->authorize('x', \$y);"
    ))->toBeTrue();
});

test('チェーンが切れた 2 文は認可マーカーにならない (正規表現の誤合格を封じる)', function (): void {
    expect(AuthorizationMarkerScanner::hasAuthorizationMarker(
        "Gate::forUser(\$user); \$other->authorize('x');"
    ))->toBeFalse();
});

test('行コメント内の Gate::authorize は検出されない', function (): void {
    expect(AuthorizationMarkerScanner::hasAuthorizationMarker(
        "// 認可は controller の Gate::authorize が行う\n\$item->save();"
    ))->toBeFalse();
});

test('docblock 内の Gate::authorize は検出されない', function (): void {
    expect(AuthorizationMarkerScanner::hasAuthorizationMarker(
        "/** Gate::authorize を通す */\n\$item->save();"
    ))->toBeFalse();
});

test('文字列リテラル内の Gate::authorize は検出されない', function (): void {
    expect(AuthorizationMarkerScanner::hasAuthorizationMarker(
        "\$msg = 'Gate::authorize';"
    ))->toBeFalse();
});

test('可変長文字列内の Gate::authorize は検出されない', function (): void {
    expect(AuthorizationMarkerScanner::hasAuthorizationMarker(
        '$msg = "prefix {$x} Gate::authorize";'
    ))->toBeFalse();
});

test('Gate::allows は認可マーカーにならない (例外を投げないため)', function (): void {
    expect(AuthorizationMarkerScanner::hasAuthorizationMarker(
        "Gate::allows('update', \$item);"
    ))->toBeFalse();
});

test('Gate::forUser(...)->allows は認可マーカーにならない', function (): void {
    expect(AuthorizationMarkerScanner::hasAuthorizationMarker(
        "Gate::forUser(\$user)->allows('update', \$item);"
    ))->toBeFalse();
});

test('authorize を呼んでいない (末尾の括弧が無い) 記述は認可マーカーにならない', function (): void {
    // 呼び出しでない `->authorize;` / `::authorize;` を合格させると gate が誤合格する
    expect(AuthorizationMarkerScanner::hasAuthorizationMarker(
        'Gate::forUser($user)->authorize;'
    ))->toBeFalse();

    expect(AuthorizationMarkerScanner::hasAuthorizationMarker(
        'Gate::authorize;'
    ))->toBeFalse();

    expect(AuthorizationMarkerScanner::hasAuthorizationMarker(
        '$x = Gate::forUser($user)->authorize::class;'
    ))->toBeFalse();
});

test('arrow function の中の Gate::authorize は認可マーカーにならない (実行されない認可式)', function (): void {
    $source = <<<'PHP'
        public function destroy(Request $request, Project $project, Item $item): JsonResponse
        {
            $authorize = fn () => Gate::authorize('delete', $item);
            $item->delete();

            return JsonResource::make(['deleted' => true])->response();
        }
        PHP;

    expect(AuthorizationMarkerScanner::hasAuthorizationMarker($source))->toBeFalse();
});

test('クロージャの中の Gate::authorize は認可マーカーにならない (実行されない認可式)', function (): void {
    $source = <<<'PHP'
        public function destroy(Request $request, Project $project, Item $item): JsonResponse
        {
            $callback = function () use ($item): void {
                Gate::forUser($item->owner)->authorize('delete', $item);
            };
            $item->delete();

            return JsonResource::make(['deleted' => true])->response();
        }
        PHP;

    expect(AuthorizationMarkerScanner::hasAuthorizationMarker($source))->toBeFalse();
});

test('ハンドラ直下の認可は、同じメソッドにクロージャがあっても検出される', function (): void {
    $source = <<<'PHP'
        public function destroy(Request $request, Project $project, Item $item): JsonResponse
        {
            Gate::forUser($this->apiActor($request)->user)->authorize('delete', $item);
            $names = array_map(static fn (Item $i): string => $i->name, $project->items->all());
            $item->delete();

            return JsonResource::make(['deleted' => true, 'siblings' => $names])->response();
        }
        PHP;

    expect(AuthorizationMarkerScanner::hasAuthorizationMarker($source))->toBeTrue();
});

test('クロージャ内の inline guard は順序検証の対象にならない', function (): void {
    $guards = ['resolveOrganizationProject', 'resolveProjectItem'];

    $source = <<<'PHP'
        public function update(Request $request, Project $project, Item $item): JsonResponse
        {
            $later = function () use ($project, $item): void {
                $this->resolveProjectItem($project, $item);
            };
            $this->resolveOrganizationProject($organization, $project);
            Gate::forUser($this->apiActor($request)->user)->authorize('update', $item);

            return ItemResource::make($item)->response();
        }
        PHP;

    // クロージャ内の guard は実行位置が確定しないため数えない (残るのは直下の 1 件)
    expect(AuthorizationMarkerScanner::guardMarkerOffsets($source, $guards))->toHaveCount(1);
});

test('use Illuminate\Support\Facades\Gate; は名前空間 import として検出される', function (): void {
    expect(AuthorizationMarkerScanner::importsGateFacade(<<<'PHP'
        <?php

        namespace App\Http\Controllers;

        use Illuminate\Support\Facades\Gate;

        class Foo {}
        PHP))->toBeTrue();
});

test('同名の別クラス (App\Support\Gate) の import は受理しない', function (): void {
    expect(AuthorizationMarkerScanner::importsGateFacade(<<<'PHP'
        <?php

        namespace App\Http\Controllers;

        use App\Support\Gate;

        class Foo {}
        PHP))->toBeFalse();
});

test('クロージャの lexical use は名前空間 import と混同されない', function (): void {
    expect(AuthorizationMarkerScanner::importsGateFacade(<<<'PHP'
        <?php

        namespace App\Http\Controllers;

        $fn = function ($x) use ($gate) {
            return $gate;
        };
        PHP))->toBeFalse();
});

test('trait use は名前空間 import と混同されない', function (): void {
    expect(AuthorizationMarkerScanner::importsGateFacade(<<<'PHP'
        <?php

        namespace App\Http\Controllers;

        class Foo
        {
            use Illuminate\Support\Facades\Gate;
        }
        PHP))->toBeFalse();
});

test('import 無しで Gate::authorize を書いたファイルは import 検査に落ちる', function (): void {
    $source = <<<'PHP'
        <?php

        namespace App\Http\Controllers;

        class Foo
        {
            public function bar(): void
            {
                Gate::authorize('update', $item);
            }
        }
        PHP;

    expect(AuthorizationMarkerScanner::hasAuthorizationMarker($source))->toBeTrue();
    expect(AuthorizationMarkerScanner::importsGateFacade($source))->toBeFalse();
});

test('alias 付き import / group use は受理しない (Gate:: が Facade を指す保証が無い)', function (): void {
    expect(AuthorizationMarkerScanner::importsGateFacade(<<<'PHP'
        <?php

        use Illuminate\Support\Facades\Gate as LaravelGate;
        PHP))->toBeFalse();

    expect(AuthorizationMarkerScanner::importsGateFacade(<<<'PHP'
        <?php

        use Illuminate\Support\Facades\{Auth, Gate};
        PHP))->toBeFalse();
});

test('inline URL 整合 guard の位置は認可マーカーより前であることを比較できる', function (): void {
    $guards = ['resolveOrganizationProject', 'resolveProjectItem'];

    $correct = <<<'PHP'
        $this->resolveOrganizationProject($organization, $project);
        Gate::forUser($actor->user)->authorize('create', [Item::class, $project]);
        PHP;

    $inverted = <<<'PHP'
        Gate::forUser($actor->user)->authorize('create', [Item::class, $project]);
        $this->resolveOrganizationProject($organization, $project);
        PHP;

    $guardOffsets = AuthorizationMarkerScanner::guardMarkerOffsets($correct, $guards);
    $authOffset = AuthorizationMarkerScanner::authorizationMarkerOffset($correct);
    expect($guardOffsets)->toHaveCount(1)
        ->and($authOffset)->not->toBeNull()
        ->and(max($guardOffsets))->toBeLessThan($authOffset);

    $guardOffsets = AuthorizationMarkerScanner::guardMarkerOffsets($inverted, $guards);
    $authOffset = AuthorizationMarkerScanner::authorizationMarkerOffset($inverted);
    expect(max($guardOffsets))->toBeGreaterThan($authOffset);
});

test('guard が 2 段ある場合は全件を返す (片方だけ認可より後ろでも検出できる)', function (): void {
    $guards = ['resolveOrganizationProject', 'resolveProjectItem'];

    // 1 段目は Gate の前、2 段目は Gate の後 = 壊れた順序。
    // 最初の 1 件だけを返す実装だとこれが合格してしまう (誤合格)
    $partiallyInverted = <<<'PHP'
        $this->resolveOrganizationProject($organization, $project);
        Gate::forUser($actor->user)->authorize('update', $item);
        $this->resolveProjectItem($project, $item);
        PHP;

    $guardOffsets = AuthorizationMarkerScanner::guardMarkerOffsets($partiallyInverted, $guards);
    $authOffset = AuthorizationMarkerScanner::authorizationMarkerOffset($partiallyInverted);

    expect($guardOffsets)->toHaveCount(2)
        ->and(min($guardOffsets))->toBeLessThan($authOffset)
        // ★全件検査でなければ検出できない壊れ方
        ->and(max($guardOffsets))->toBeGreaterThan($authOffset);
});
