<?php

declare(strict_types=1);

use Tests\Support\Security\MachinePlaneEntryPoints;
use Tests\Support\Security\MachinePlaneOrganizationReferenceInventory;
use Tests\Support\Security\NotOrganizationScoped;
use Tests\Support\Security\OrganizationReferenceProvenance;
use Tests\Support\Security\OrganizationResolutionPoint;
use Tests\Support\Security\OrganizationScoped;

/*
 * 機械が使う経路は**不変の内部識別子**で組織を指す (家系裁定 AG-047 / 不変条件 I14)。
 *
 * 識別名 (AG-046 で 30 日 5 回まで変えられる) や表示名で組織を引く形が機械経路に混じると、
 * 改名の瞬間に機械の参照が壊れる。**入口を全数分類**することで、
 * 新しい入口が「分類されないまま増える」ことを構造的に防ぐ。
 *
 * ## 2 層
 *
 * - 第 1 層 = 入口の全数抽出 (`MachinePlaneEntryPoints`)。api / console / filament / mcp の 4 面。
 * - 第 2 層 = 入口ごとの解決点の分類 (`MachinePlaneOrganizationReferenceInventory`)。
 *
 * ## 親鎖の 5 検証
 *
 * | # | 検証 | 破れたときに起きること |
 * |---|---|---|
 * | 1 | `resolutionId` が入口内で一意 | 重複 ID で親の指す先が曖昧になる |
 * | 2 | `parentResolutionId` が同じ入口内に実在する | 存在しない親を指して黙って通る |
 * | 3 | 自己参照禁止 | 自分を根拠に自分を正当化できる |
 * | 4 | 循環禁止 (A → B → A) | 「親が存在する」だけの検査を循環がすり抜ける |
 * | 5 | 親鎖が最終的に PrimaryKeyBinding / ActorDerived へ到達する | 信頼の起点が無い relation 鎖が通る |
 *
 * `RelationScoped` 以外に `parentResolutionId` が付いていたら**赤**にする (余剰登録)。
 *
 * ## 保証しないもの (誇張しない)
 *
 * - **`PrimaryKeyBinding` は「操作してよい組織か」を保証しない**。認可は `Gate::authorize` と
 *   `ControllerAuthorizationGateTest` の担当である。
 * - 抽出器の docblock に書いた保証範囲の外 (実行時に条件付きで登録される入口・vendor の
 *   内部解決・リポジトリ外の手順) には**無言で効かない**。
 * - 本 gate が見るのは**台帳の宣言**であり、実装がその宣言どおりに書かれていることまでは
 *   見ない (それは各面の Feature テストの担当である)。
 */

test('第 1 層: 4 面の母集団がいずれも空でない', function (): void {
    expect(MachinePlaneEntryPoints::apiEntries())->not->toBeEmpty();
    expect(MachinePlaneEntryPoints::consoleEntries())->not->toBeEmpty();
    expect(MachinePlaneEntryPoints::filamentEntries())->not->toBeEmpty();
    expect(MachinePlaneEntryPoints::mcpEntries())->not->toBeEmpty();
});

test('第 2 層: 入口の全数が台帳と完全一致する (未登録・余剰のどちらも赤)', function (): void {
    $entries = MachinePlaneEntryPoints::all();
    $registered = array_keys(MachinePlaneOrganizationReferenceInventory::all());
    sort($registered);

    expect(array_values(array_diff($entries, $registered)))->toBe([]);   // 未登録
    expect(array_values(array_diff($registered, $entries)))->toBe([]);   // 余剰 (陳腐化)
});

test('NotOrganizationScoped の理由は 30 文字以上', function (): void {
    $short = [];
    foreach (MachinePlaneOrganizationReferenceInventory::all() as $entry => $classification) {
        if ($classification instanceof NotOrganizationScoped && mb_strlen($classification->reason) < 30) {
            $short[] = $entry;
        }
    }

    expect($short)->toBe([]);
});

test('親鎖の 5 検証: 一意 / 実在 / 自己参照禁止 / 循環禁止 / 根への到達', function (): void {
    $violations = [];

    foreach (MachinePlaneOrganizationReferenceInventory::all() as $entry => $classification) {
        if (! $classification instanceof OrganizationScoped) {
            continue;
        }
        foreach (validateResolutionChain($classification->resolutions) as $problem) {
            $violations[] = "{$entry}: {$problem}";
        }
    }

    expect($violations)->toBe([]);
});

/**
 * 親鎖の 5 検証 (負例テストからも呼ぶ)。
 *
 * @param  list<OrganizationResolutionPoint>  $resolutions
 * @return list<string> 検出した問題 (空 = 適合)
 */
function validateResolutionChain(array $resolutions): array
{
    $problems = [];

    /** @var array<string, OrganizationResolutionPoint> $byId */
    $byId = [];
    foreach ($resolutions as $point) {
        if (array_key_exists($point->resolutionId, $byId)) {
            $problems[] = "resolutionId が重複している ({$point->resolutionId})";

            continue;
        }
        $byId[$point->resolutionId] = $point;
    }

    foreach ($resolutions as $point) {
        if ($point->provenance !== OrganizationReferenceProvenance::RelationScoped) {
            if ($point->parentResolutionId !== null) {
                $problems[] = "RelationScoped でないのに親が付いている ({$point->resolutionId})";
            }

            continue;
        }
        if ($point->parentResolutionId === null) {
            $problems[] = "RelationScoped なのに親が無い ({$point->resolutionId})";

            continue;
        }
        if ($point->parentResolutionId === $point->resolutionId) {
            $problems[] = "自己参照している ({$point->resolutionId})";

            continue;
        }
        if (! array_key_exists($point->parentResolutionId, $byId)) {
            $problems[] = "親が同じ入口内に実在しない ({$point->resolutionId} -> {$point->parentResolutionId})";

            continue;
        }

        // 循環と根への到達を訪問済み集合で同時に見る
        $seen = [$point->resolutionId => true];
        $cursor = $byId[$point->parentResolutionId];
        while (true) {
            if (array_key_exists($cursor->resolutionId, $seen)) {
                $problems[] = "親鎖が循環している ({$point->resolutionId})";
                break;
            }
            $seen[$cursor->resolutionId] = true;

            if ($cursor->provenance !== OrganizationReferenceProvenance::RelationScoped) {
                break;  // 根 (PrimaryKeyBinding / ActorDerived) へ到達
            }
            if ($cursor->parentResolutionId === null || ! array_key_exists($cursor->parentResolutionId, $byId)) {
                $problems[] = "親鎖が根へ到達しない ({$point->resolutionId})";
                break;
            }
            $cursor = $byId[$cursor->parentResolutionId];
        }
    }

    return $problems;
}
