<?php

declare(strict_types=1);

use Tests\Support\Ci\TestDatabaseCandidate;
use Tests\Support\Ci\TestDatabaseClassification;
use Tests\Support\Ci\TestDatabaseDecision;
use Tests\Support\Ci\TestDatabaseEnv;

/*
 * 孤児テスト DB sweep (`drop-test-db.php --orphans`) の分類ロジックと
 * confirm token の Unit テスト。
 *
 * 固定する不変条件:
 *   1. 分類優先順位 Protected → Live → Foreign → Orphan → Unlabeled が一意に決まる
 *      (Live が Foreign/Orphan より先 = provenance コメントを細工しても生存 DB は落とせない)
 *   2. **削除可否を分類だけで自動決定しない**。Orphan も Unlabeled も
 *      `--include-hash` で 1 つずつ名指ししない限り shouldDrop = false
 *   3. dev DB (`app` / `bug_hunt*`) と allowlist 外は候補生成の時点で例外 (境界で弾く)
 *   4. token は canonical JSON の SHA-256 全長で、入力順に依存せず、
 *      include_hashes / classifier_version の違いを必ず反映する
 *
 * 本テストは DB を触らない (純関数のみ。path 実在判定も注入する)。
 */

/** provenance path の実在判定を注入するためのヘルパ (FS に触らず Foreign/Orphan を作り分ける)。 */
function ciPathExists(string ...$existing): callable
{
    return static fn (string $path): bool => in_array($path, $existing, true);
}

/**
 * hash 群の base + worker DB 候補を作る。
 *
 * @return list<TestDatabaseCandidate>
 */
function ciGroup(string $hash, ?string $provenance, int $workers = 4): array
{
    $candidates = [new TestDatabaseCandidate("app_test_{$hash}", $hash, false, $provenance)];
    for ($i = 1; $i <= $workers; $i++) {
        $candidates[] = new TestDatabaseCandidate("app_test_{$hash}_test_{$i}", $hash, true, null);
    }

    return $candidates;
}

/**
 * @param  list<TestDatabaseDecision>  $decisions
 * @return array<string, TestDatabaseDecision>
 */
function ciByName(array $decisions): array
{
    $byName = [];
    foreach ($decisions as $decision) {
        $byName[$decision->candidate->name] = $decision;
    }

    return $byName;
}

// ── T-C2-1 / T-C2-11: live (base + worker 5 件が同じ分類) ──

it('classifies a live worktree hash group as Live and never drops it', function (): void {
    $decisions = TestDatabaseEnv::classifyTestDatabases(
        ciGroup('8af22c44', '/workspace'),
        ['8af22c44'],
        [],
        [],
        ciPathExists('/workspace'),
    );

    expect($decisions)->toHaveCount(5);
    foreach ($decisions as $decision) {
        expect($decision->classification)->toBe(TestDatabaseClassification::Live)
            ->and($decision->shouldDrop)->toBeFalse();
    }
});

// ── T-C2-2 / T-C2-19: orphan は --include-hash なしでは落ちない ──

it('classifies a labelled group with a missing path as Orphan but does not drop it by default', function (): void {
    $decisions = TestDatabaseEnv::classifyTestDatabases(
        ciGroup('3a7d6b4e', '/gone/worktree'),
        ['8af22c44'],
        [],
        [],
        ciPathExists('/workspace'),
    );

    foreach ($decisions as $decision) {
        expect($decision->classification)->toBe(TestDatabaseClassification::Orphan)
            ->and($decision->shouldDrop)->toBeFalse();
    }
});

// ── T-C2-3: foreign ──

it('classifies a labelled group whose path still exists as Foreign', function (): void {
    $decisions = TestDatabaseEnv::classifyTestDatabases(
        ciGroup('823cbbd2', '/other/clone'),
        ['8af22c44'],
        [],
        ['823cbbd2'], // 名指ししても Foreign は落ちない
        ciPathExists('/workspace', '/other/clone'),
    );

    foreach ($decisions as $decision) {
        expect($decision->classification)->toBe(TestDatabaseClassification::Foreign)
            ->and($decision->shouldDrop)->toBeFalse();
    }
});

// ── T-C2-4: 優先順位 1 (Protected) が 2 (Live) / 5 (Unlabeled) に勝つ ──

it('gives --protect-hash precedence over live and unlabeled classification', function (): void {
    $decisions = TestDatabaseEnv::classifyTestDatabases(
        ciGroup('8af22c44', null),
        ['8af22c44'],
        ['8af22c44'],
        ['8af22c44'],
        ciPathExists(),
    );

    foreach ($decisions as $decision) {
        expect($decision->classification)->toBe(TestDatabaseClassification::Protected)
            ->and($decision->shouldDrop)->toBeFalse();
    }
});

// ── T-C2-5: 優先順位 2 (Live) が 4 (Orphan) に勝つ = comment 細工で生存 DB を落とせない ──

it('keeps a live hash as Live even when its provenance comment points at a missing path', function (): void {
    $decisions = TestDatabaseEnv::classifyTestDatabases(
        ciGroup('8af22c44', '/tampered/does-not-exist'),
        ['8af22c44'],
        [],
        ['8af22c44'],
        ciPathExists('/workspace'),
    );

    foreach ($decisions as $decision) {
        expect($decision->classification)->toBe(TestDatabaseClassification::Live)
            ->and($decision->shouldDrop)->toBeFalse();
    }
});

// ── T-C2-6 / T-C2-7 / T-C2-7b: unlabeled ──

it('classifies a group without provenance as Unlabeled and keeps it by default', function (): void {
    $decisions = TestDatabaseEnv::classifyTestDatabases(
        ciGroup('018d63c6', null, 0),
        ['8af22c44'],
        [],
        [],
        ciPathExists('/workspace'),
    );

    expect($decisions[0]->classification)->toBe(TestDatabaseClassification::Unlabeled)
        ->and($decisions[0]->shouldDrop)->toBeFalse();
});

it('drops an Unlabeled group only when its own hash is named by --include-hash', function (): void {
    $decisions = TestDatabaseEnv::classifyTestDatabases(
        ciGroup('018d63c6', null, 0),
        ['8af22c44'],
        [],
        ['018d63c6'],
        ciPathExists('/workspace'),
    );

    expect($decisions[0]->classification)->toBe(TestDatabaseClassification::Unlabeled)
        ->and($decisions[0]->shouldDrop)->toBeTrue();
});

it('does not drag a different Unlabeled hash along with --include-hash', function (): void {
    $decisions = TestDatabaseEnv::classifyTestDatabases(
        [...ciGroup('018d63c6', null, 0), ...ciGroup('91c7197b', null, 0)],
        ['8af22c44'],
        [],
        ['018d63c6'],
        ciPathExists('/workspace'),
    );

    $byName = ciByName($decisions);
    expect($byName['app_test_018d63c6']->shouldDrop)->toBeTrue()
        ->and($byName['app_test_91c7197b']->shouldDrop)->toBeFalse();
});

// ── T-C2-8 / T-C2-9 / T-C2-10: 境界で弾く ──

it('refuses to build a candidate for the dev database', function (): void {
    TestDatabaseCandidate::fromDatabaseName('app', null);
})->throws(InvalidArgumentException::class);

it('refuses to build a candidate for bug-hunt databases', function (string $name): void {
    TestDatabaseCandidate::fromDatabaseName($name, null);
})->with(['bug_hunt', 'bug_hunt_3', 'bug_hunt_8'])->throws(InvalidArgumentException::class);

it('refuses to build a candidate for names outside the allowlist', function (string $name): void {
    TestDatabaseCandidate::fromDatabaseName($name, null);
})->with([
    'app_test_XYZ',
    'app_test_8af22c44_backup',
    'app_test_8AF22C44',
    'app_test_8af22c4',
    'postgres',
])->throws(InvalidArgumentException::class);

it('rejects a candidate whose hash does not match its database name', function (): void {
    new TestDatabaseCandidate('app_test_8af22c44', 'deadbeef', false, null);
})->throws(InvalidArgumentException::class);

// ── T-C2-11 / T-C2-12: worker は base の分類を継承 / base 不在は Unlabeled ──

it('inherits the base classification for paratest worker databases', function (): void {
    $decisions = TestDatabaseEnv::classifyTestDatabases(
        ciGroup('3a7d6b4e', '/gone'),
        [],
        [],
        ['3a7d6b4e'],
        ciPathExists('/workspace'),
    );

    $byName = ciByName($decisions);
    expect($byName['app_test_3a7d6b4e_test_2']->classification)->toBe(TestDatabaseClassification::Orphan)
        ->and($byName['app_test_3a7d6b4e_test_2']->shouldDrop)->toBeTrue()
        ->and($byName['app_test_3a7d6b4e_test_2']->reason)->toContain('base の分類を継承');
});

it('classifies worker-only groups (base already gone) as Unlabeled', function (): void {
    $decisions = TestDatabaseEnv::classifyTestDatabases(
        [new TestDatabaseCandidate('app_test_91c7197b_test_1', '91c7197b', true, null)],
        [],
        [],
        [],
        ciPathExists('/workspace'),
    );

    expect($decisions[0]->classification)->toBe(TestDatabaseClassification::Unlabeled)
        ->and($decisions[0]->shouldDrop)->toBeFalse();
});

it('ignores a provenance comment attached to a worker database (base is the only source)', function (): void {
    // worker に細工でラベルが付いても、hash グループの出自は base の comment だけが代表する。
    $decisions = TestDatabaseEnv::classifyTestDatabases(
        [new TestDatabaseCandidate('app_test_91c7197b_test_1', '91c7197b', true, '/workspace')],
        [],
        [],
        [],
        ciPathExists('/workspace'),
    );

    expect($decisions[0]->classification)->toBe(TestDatabaseClassification::Unlabeled);
});

// ── T-C2-13 / T-C2-14 / T-C2-15 / T-C2-15b: token ──

it('produces a stable full-length sha256 token for the same input', function (): void {
    $a = TestDatabaseEnv::orphanConfirmToken(['app_test_3a7d6b4e'], ['8af22c44'], [], ['3a7d6b4e']);
    $b = TestDatabaseEnv::orphanConfirmToken(['app_test_3a7d6b4e'], ['8af22c44'], [], ['3a7d6b4e']);

    expect($a)->toBe($b)->and($a)->toMatch('/^[0-9a-f]{64}$/');
});

it('changes the token when a single drop target changes', function (): void {
    $a = TestDatabaseEnv::orphanConfirmToken(['app_test_3a7d6b4e'], ['8af22c44'], [], ['3a7d6b4e']);
    $b = TestDatabaseEnv::orphanConfirmToken(
        ['app_test_3a7d6b4e', 'app_test_3a7d6b4e_test_1'],
        ['8af22c44'],
        [],
        ['3a7d6b4e'],
    );

    expect($a)->not->toBe($b);
});

it('distinguishes element boundaries because the token is canonical JSON', function (): void {
    // 区切りなしの連結だと ["a_b","c"] と ["a","b_c"] が同じ文字列になり衝突する。
    $a = TestDatabaseEnv::orphanConfirmToken(['a_b', 'c'], [], [], []);
    $b = TestDatabaseEnv::orphanConfirmToken(['a', 'b_c'], [], [], []);

    expect($a)->not->toBe($b);
});

it('is independent of the input ordering (ascending sort)', function (): void {
    $a = TestDatabaseEnv::orphanConfirmToken(
        ['app_test_823cbbd2', 'app_test_3a7d6b4e'],
        ['b4f0102e', '8af22c44'],
        ['91c7197b', '018d63c6'],
        ['823cbbd2', '3a7d6b4e'],
    );
    $b = TestDatabaseEnv::orphanConfirmToken(
        ['app_test_3a7d6b4e', 'app_test_823cbbd2'],
        ['8af22c44', 'b4f0102e'],
        ['018d63c6', '91c7197b'],
        ['3a7d6b4e', '823cbbd2'],
    );

    expect($a)->toBe($b);
});

it('changes the token when include_hashes changes', function (): void {
    $a = TestDatabaseEnv::orphanConfirmToken([], ['8af22c44'], [], ['3a7d6b4e']);
    $b = TestDatabaseEnv::orphanConfirmToken([], ['8af22c44'], [], ['823cbbd2']);

    expect($a)->not->toBe($b);
});

it('binds the token to the classifier version', function (): void {
    $canonical = static fn (int $version): string => hash('sha256', json_encode([
        'classifier_version' => $version,
        'drop_targets' => [],
        'live_hashes' => ['8af22c44'],
        'protected' => [],
        'include_hashes' => [],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

    expect(TestDatabaseEnv::orphanConfirmToken([], ['8af22c44'], [], []))
        ->toBe($canonical(TestDatabaseEnv::CLASSIFIER_VERSION))
        ->and($canonical(TestDatabaseEnv::CLASSIFIER_VERSION))
        ->not->toBe($canonical(TestDatabaseEnv::CLASSIFIER_VERSION + 1));
});

// ── T-C2-16: hash 引数の形式検証 ──

it('rejects malformed --include-hash / --protect-hash values', function (string $hash): void {
    TestDatabaseEnv::classifyTestDatabases([], [], [], [$hash], ciPathExists());
})->with(['ZZZZZZZZ', '8af22c4', '8af22c444', '8AF22C44', '', '8af22c4g'])
    ->throws(InvalidArgumentException::class);

it('rejects malformed hashes in the token input as well', function (): void {
    TestDatabaseEnv::classifyTestDatabases([], ['nothex!!'], [], [], ciPathExists());
})->throws(InvalidArgumentException::class);

// ── T-C2-20 / T-C2-21 ──

it('drops an Orphan group only when its hash is named by --include-hash', function (): void {
    $without = TestDatabaseEnv::classifyTestDatabases(ciGroup('b4f0102e', '/gone', 0), [], [], [], ciPathExists());
    $with = TestDatabaseEnv::classifyTestDatabases(
        ciGroup('b4f0102e', '/gone', 0),
        [],
        [],
        ['b4f0102e'],
        ciPathExists(),
    );

    expect($without[0]->shouldDrop)->toBeFalse()
        ->and($with[0]->classification)->toBe(TestDatabaseClassification::Orphan)
        ->and($with[0]->shouldDrop)->toBeTrue();
});

it('never drops Protected or Live groups even when they are named by --include-hash', function (): void {
    $decisions = TestDatabaseEnv::classifyTestDatabases(
        [...ciGroup('8af22c44', null, 0), ...ciGroup('3a7d6b4e', null, 0)],
        ['3a7d6b4e'],
        ['8af22c44'],
        ['8af22c44', '3a7d6b4e'],
        ciPathExists(),
    );

    $byName = ciByName($decisions);
    expect($byName['app_test_8af22c44']->classification)->toBe(TestDatabaseClassification::Protected)
        ->and($byName['app_test_8af22c44']->shouldDrop)->toBeFalse()
        ->and($byName['app_test_3a7d6b4e']->classification)->toBe(TestDatabaseClassification::Live)
        ->and($byName['app_test_3a7d6b4e']->shouldDrop)->toBeFalse();
});

// ── T-C2-22: namespace 差で path が見えないケースも保護される ──

it('protects a group whose provenance path is invisible from this namespace', function (): void {
    // bind mount の差などで is_dir() が false になるだけで他人の生存 DB が消えてはならない。
    $decisions = TestDatabaseEnv::classifyTestDatabases(
        ciGroup('823cbbd2', '/mnt/other-namespace/clone', 0),
        [],
        [],
        [],
        ciPathExists(), // 何も見えない
    );

    expect($decisions[0]->classification)->toBe(TestDatabaseClassification::Orphan)
        ->and($decisions[0]->shouldDrop)->toBeFalse();
});

// ── 既定の path 実在判定 (注入しない実運用経路) が働くこと ──

it('uses is_dir() as the default path existence probe', function (): void {
    $existing = realpath(sys_get_temp_dir());
    expect($existing)->toBeString();

    $foreign = TestDatabaseEnv::classifyTestDatabases(ciGroup('823cbbd2', (string) $existing, 0), [], [], [], null);
    $orphan = TestDatabaseEnv::classifyTestDatabases(
        ciGroup('823cbbd2', $existing.'/definitely-not-here-'.bin2hex(random_bytes(6)), 0),
        [],
        [],
        [],
        null,
    );

    expect($foreign[0]->classification)->toBe(TestDatabaseClassification::Foreign)
        ->and($orphan[0]->classification)->toBe(TestDatabaseClassification::Orphan);
});

// ── 分類結果は必ず具体的な理由を持つ (dry-run の説明責任) ──

it('always carries a concrete reason string', function (): void {
    $decisions = TestDatabaseEnv::classifyTestDatabases(
        [...ciGroup('8af22c44', '/workspace', 1), ...ciGroup('3a7d6b4e', '/gone', 1), ...ciGroup('018d63c6', null, 1)],
        ['8af22c44'],
        [],
        [],
        ciPathExists('/workspace'),
    );

    expect($decisions)->toHaveCount(6);
    foreach ($decisions as $decision) {
        expect($decision->reason)->not->toBe('');
    }
});
