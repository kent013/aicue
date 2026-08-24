<?php

declare(strict_types=1);

use Tests\Support\Security\OrganizationReferenceProvenance as Provenance;
use Tests\Support\Security\OrganizationResolutionPoint as Point;

/*
 * 親鎖の 5 検証の**検出力を両方向で裏取り**する (家系裁定 AG-047)。
 *
 * 正例 (多段の relation) が緑になること、負例 5 形がそれぞれ検出されることを固定する。
 * 判定関数は gate 側 (`tests/Architecture/MachinePlaneOrganizationReferenceTest.php` の
 * `validateResolutionChain`) と同一である (2 本持たない)。
 */

require_once __DIR__.'/../../Architecture/MachinePlaneOrganizationReferenceTest.php';

test('正例: 多段の relation (ActorDerived ← RelationScoped ← RelationScoped) は緑', function (): void {
    $problems = validateResolutionChain([
        new Point('org', Provenance::ActorDerived),
        new Point('project', Provenance::RelationScoped, 'org'),
        new Point('item', Provenance::RelationScoped, 'project'),
    ]);

    expect($problems)->toBe([]);
});

test('負例 1: resolutionId の重複を検出する', function (): void {
    $problems = validateResolutionChain([
        new Point('org', Provenance::ActorDerived),
        new Point('org', Provenance::PrimaryKeyBinding),
    ]);

    expect($problems)->not->toBeEmpty();
});

test('負例 2: 実在しない親を検出する', function (): void {
    $problems = validateResolutionChain([
        new Point('org', Provenance::ActorDerived),
        new Point('project', Provenance::RelationScoped, 'no-such-parent'),
    ]);

    expect($problems)->not->toBeEmpty();
});

test('負例 3: 自己参照を検出する', function (): void {
    $problems = validateResolutionChain([
        new Point('org', Provenance::ActorDerived),
        new Point('project', Provenance::RelationScoped, 'project'),
    ]);

    expect($problems)->not->toBeEmpty();
});

test('負例 4: 循環 (A → B → A) を検出する', function (): void {
    $problems = validateResolutionChain([
        new Point('a', Provenance::RelationScoped, 'b'),
        new Point('b', Provenance::RelationScoped, 'a'),
    ]);

    expect($problems)->not->toBeEmpty();
});

test('負例 5: 根へ到達しない鎖 (RelationScoped だけ) を検出する', function (): void {
    $problems = validateResolutionChain([
        new Point('a', Provenance::RelationScoped, 'b'),
        new Point('b', Provenance::RelationScoped, 'c'),
        new Point('c', Provenance::RelationScoped, 'a'),
    ]);

    expect($problems)->not->toBeEmpty();
});

test('負例 6: RelationScoped 以外に親が付いていたら余剰登録として検出する', function (): void {
    $problems = validateResolutionChain([
        new Point('org', Provenance::ActorDerived),
        new Point('other', Provenance::PrimaryKeyBinding, 'org'),
    ]);

    expect($problems)->not->toBeEmpty();
});

test('負例 7: RelationScoped なのに親が無いのを検出する', function (): void {
    $problems = validateResolutionChain([
        new Point('project', Provenance::RelationScoped),
    ]);

    expect($problems)->not->toBeEmpty();
});
