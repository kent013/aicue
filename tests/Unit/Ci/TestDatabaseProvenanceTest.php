<?php

declare(strict_types=1);

require_once __DIR__.'/../../../scripts/ci/pgsql_test_conn.php';

/*
 * ensure-test-db.php が付ける provenance ラベル (COMMENT ON DATABASE) の Unit テスト。
 *
 * 固定する不変条件:
 *   1. ensure の plan は **作成時・既存時の両方**で StampProvenance と UpdateSchema を含む
 *      (= 冪等。ここを片方だけにすると「ラベルの無い現役 DB」または「スキーマが古いまま放置
 *      される DB」が生まれる)。実行順は StampProvenance → UpdateSchema で固定する
 *      (スキーマ更新の失敗時に「ラベルの無い現役 DB」を残さないため)。
 *   2. COMMENT 文のリテラルは **PDO::quote() 経由**で組み立てる (独自連結しない。
 *      provenance の realpath に `'` が含まれうる)
 *   3. ラベル付与は **best-effort**。失敗しても例外を伝播させずテスト実行を止めない
 *      (権限差で偽赤を増やさない。危険の本体「ラベル無し DB の一括 DROP」は
 *       --include-hash の明示指定制で潰してある)
 *
 * 本テストは実 DB を作らない (SQL 文字列の生成と callable の注入のみ)。
 */

// ── T-C2-17: plan は両分岐とも StampProvenance + UpdateSchema を含む ──

it('plans create + stamp + schema update when the base database does not exist yet', function (): void {
    expect(testDatabaseEnsurePlan(false))->toBe([
        TestDatabaseEnsureAction::Create,
        TestDatabaseEnsureAction::StampProvenance,
        TestDatabaseEnsureAction::UpdateSchema,
    ]);
});

it('still plans a stamp + schema update when the base database already exists (idempotent)', function (): void {
    expect(testDatabaseEnsurePlan(true))->toBe([
        TestDatabaseEnsureAction::StampProvenance,
        TestDatabaseEnsureAction::UpdateSchema,
    ]);
});

it('never plans a create for an existing database', function (): void {
    expect(testDatabaseEnsurePlan(true))->not->toContain(TestDatabaseEnsureAction::Create);
});

it('always plans the schema update last, after the provenance stamp', function (): void {
    foreach ([false, true] as $exists) {
        $plan = testDatabaseEnsurePlan($exists);
        expect(array_search(TestDatabaseEnsureAction::UpdateSchema, $plan, true))
            ->toBe(count($plan) - 1, 'UpdateSchema is not last in the plan');
        if (in_array(TestDatabaseEnsureAction::StampProvenance, $plan, true)) {
            expect(array_search(TestDatabaseEnsureAction::StampProvenance, $plan, true))
                ->toBeLessThan(array_search(TestDatabaseEnsureAction::UpdateSchema, $plan, true));
        }
    }
});

// ── T-C2-17b: 識別子 / リテラルのクォート ──

it('quotes the identifier and delegates the literal to PDO::quote', function (): void {
    $pdo = new PDO('sqlite::memory:');

    expect(pgsqlCommentDatabaseSql($pdo, 'app_test_8af22c44', '/workspace'))
        ->toBe('COMMENT ON DATABASE "app_test_8af22c44" IS \'/workspace\'');
});

it('escapes single quotes in the provenance path via PDO::quote (no manual concatenation)', function (): void {
    $pdo = new PDO('sqlite::memory:');

    $sql = pgsqlCommentDatabaseSql($pdo, 'app_test_8af22c44', "/home/o'brien/repo");

    expect($sql)->toBe('COMMENT ON DATABASE "app_test_8af22c44" IS \'/home/o\'\'brien/repo\'')
        // 生の `'` が閉じられないまま残っていないこと (クォート漏れの回帰検出)
        ->and(substr_count($sql, "'") % 2)->toBe(0);
});

it('quotes a double quote inside the identifier', function (): void {
    $pdo = new PDO('sqlite::memory:');

    expect(pgsqlCommentDatabaseSql($pdo, 'weird"name', '/workspace'))
        ->toStartWith('COMMENT ON DATABASE "weird""name" IS ');
});

// ── T-C2-18 / T-C2-18b: best-effort な stamp ──

it('returns true and passes the COMMENT statement through when the exec succeeds', function (): void {
    $seen = null;
    $result = pgsqlStampProvenance(function (string $sql) use (&$seen): int {
        $seen = $sql;

        return 1;
    }, 'COMMENT ON DATABASE "app_test_8af22c44" IS \'/workspace\'');

    expect($result)->toBeTrue()
        ->and($seen)->toBe('COMMENT ON DATABASE "app_test_8af22c44" IS \'/workspace\'');
});

it('treats a false return value as a failure (PDO::exec can return false instead of throwing)', function (): void {
    $result = pgsqlStampProvenance(
        static fn (string $sql): bool => false,
        'COMMENT ON DATABASE "app_test_8af22c44" IS \'/workspace\'',
    );

    expect($result)->toBeFalse();
});

it('swallows exec failures so that a permission difference cannot break the test lane', function (): void {
    $result = pgsqlStampProvenance(static function (string $sql): never {
        throw new RuntimeException('permission denied for database');
    }, 'COMMENT ON DATABASE "app_test_8af22c44" IS \'/workspace\'');

    expect($result)->toBeFalse();
});
