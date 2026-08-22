<?php

declare(strict_types=1);

use Tests\Support\Architecture\CurrentOrganizationRemovalScanner;
use Tests\Support\TrackedPhpSourceFiles;

/*
 * 「いまどの組織か」の保持方式が 1 つも残っていない (家系裁定 AG-037 / 不変条件 I2)。
 *
 * 正典は「組織文脈は **URL だけ**で決まる。保持列と切替 endpoint は存在してはならない
 * (2 方式の併存不可)」と定める。撤去が中途半端だと、その状態自体が裁定違反である。
 *
 * ## 4 つの形を**別々に**検出する
 *
 * 一般語 `currentOrganization` の全面禁止はしない — 施策 6 で残した共有 prop
 * `currentOrganization` と DTO `CurrentOrganizationData` を自ら違反にしてしまうためである。
 *
 * | # | 検出する形 |
 * |---|---|
 * | 1 | 列名リテラル (撤去 migration 以外に 0 件) |
 * | 2 | `User` に対する `currentOrganization` の relation 宣言 / プロパティアクセス |
 * | 3 | 撤去した Service の完全修飾名 |
 * | 4 | 撤去した route 名 |
 *
 * ## 自己検出の閉じ方
 *
 * 本 gate と走査器は**検出語を文字列リテラルとして持たない** (断片を連結して組み立てる)。
 * したがって自分自身を例外へ登録する必要がない (目録を持てば、その目録が検出対象になる)。
 *
 * ## 保証しないもの (誇張しない)
 *
 * - 実行時に組み立てた名前 (`$column = 'current_'.$suffix`) には**無言で効かない**。
 * - `User` 以外の型に対する `->currentOrganization` は検出 2 の対象外である
 *   (共有 prop の連想配列キーと区別できないため)。
 * - 走査根は git 追跡下の PHP 全数 + `resources/js` + `database/` である。
 *   それ以外の置き場所 (`.claude/` の目録・生成物) は見ない。
 */

test('走査根はいずれも空でない (走査が壊れても気付ける)', function (): void {
    $roots = CurrentOrganizationRemovalScanner::roots(base_path());

    expect($roots)->not->toBeEmpty();
    foreach ($roots as $label => $files) {
        expect($files)->not->toBeEmpty("走査根 {$label} が空です");
    }
});

test('検出 1: 撤去した保持列の列名は撤去 migration 以外に 0 件', function (): void {
    $hits = CurrentOrganizationRemovalScanner::columnLiteralHits(base_path());

    expect($hits)->toBe([]);
});

test('検出 2: User の currentOrganization relation / プロパティアクセスが 0 件', function (): void {
    $files = TrackedPhpSourceFiles::all(base_path());

    expect(CurrentOrganizationRemovalScanner::userRelationHits($files))->toBe([]);
});

test('検出 3: 撤去した Service の完全修飾名が 0 件', function (): void {
    $files = TrackedPhpSourceFiles::all(base_path());

    expect(CurrentOrganizationRemovalScanner::removedServiceHits($files))->toBe([]);
});

test('検出 4: 撤去した route 名が 0 件', function (): void {
    $hits = CurrentOrganizationRemovalScanner::removedRouteNameHits(base_path());

    expect($hits)->toBe([]);
});

test('負例: 4 形それぞれを合成入力で検出できる (検出力の裏取り)', function (): void {
    $column = CurrentOrganizationRemovalScanner::columnName();
    $service = CurrentOrganizationRemovalScanner::removedServiceFqcn();
    $routeName = CurrentOrganizationRemovalScanner::removedRouteName();

    // 1: 列名リテラル
    expect(CurrentOrganizationRemovalScanner::containsColumnName("['{$column}' => 1]"))->toBeTrue();
    expect(CurrentOrganizationRemovalScanner::containsColumnName("['organization_id' => 1]"))->toBeFalse();

    // 2: User に対する relation 宣言 / プロパティアクセス (別名つき取り込みでも黙らない)
    $relationSource = <<<'PHP'
        <?php
        namespace App\Models;
        class User extends Model {
            public function currentOrganization(): BelongsTo { return $this->belongsTo(Organization::class); }
        }
        PHP;
    expect(CurrentOrganizationRemovalScanner::sourceHasUserRelation($relationSource))->toBeTrue();

    $aliasedAccess = <<<'PHP'
        <?php
        namespace App\Http;
        use App\Models\User as Account;
        function f(Account $u) { return $u->currentOrganization; }
        PHP;
    expect(CurrentOrganizationRemovalScanner::sourceHasUserRelation($aliasedAccess))->toBeTrue();

    $sharedProp = <<<'PHP'
        <?php
        namespace App\Http;
        function f(array $props) { return $props['currentOrganization']; }
        PHP;
    expect(CurrentOrganizationRemovalScanner::sourceHasUserRelation($sharedProp))->toBeFalse();

    // 3: FQCN (別名つき取り込みで黙らない)
    $aliasedService = "<?php\nnamespace App;\nuse {$service} as Resolver;\nclass X { public function f(Resolver \$r) {} }\n";
    expect(CurrentOrganizationRemovalScanner::sourceHasRemovedService($aliasedService))->toBeTrue();
    expect(CurrentOrganizationRemovalScanner::sourceHasRemovedService("<?php\nnamespace App;\nclass Y {}\n"))->toBeFalse();

    // 4: route 名
    expect(CurrentOrganizationRemovalScanner::containsRemovedRouteName("route('{$routeName}')"))->toBeTrue();
    expect(CurrentOrganizationRemovalScanner::containsRemovedRouteName("route('organizations.settings')"))->toBeFalse();
});
