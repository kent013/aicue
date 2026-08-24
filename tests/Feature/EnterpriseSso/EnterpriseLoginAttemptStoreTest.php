<?php

declare(strict_types=1);

use App\DataTransferObjects\EnterpriseSso\AttemptConsumeResult;
use App\Enums\EnterpriseSso\FingerprintPurpose;
use App\Exceptions\EnterpriseSso\EnterpriseSsoAttemptStoreFailure;
use App\Models\EnterpriseSsoLoginAttempt;
use App\Models\Organization;
use App\Models\OrganizationOidcConnection;
use App\Services\EnterpriseSso\EnterpriseLoginAttemptStore;
use App\Support\EnterpriseSso\AttemptFingerprint;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\EnterpriseSso\CommittedConnectionHarness;

/*
 * ログイン試行の保管 (B4)。
 *
 * 不変条件:
 *   **同じ試行の使用権を、ちょうど 1 つの要求だけが得る。**
 *   **かつ、その試行を開始したブラウザだけが使える。**
 */

function attemptStore(): EnterpriseLoginAttemptStore
{
    return app(EnterpriseLoginAttemptStore::class);
}

test('開始で行が作られ、指紋だけが保存される (原文を保存しない)', function (): void {
    $connection = OrganizationOidcConnection::factory()->create();

    $attempt = attemptStore()->start($connection, 'state-1', 'nonce-1', 'verifier-1', 'binding-1');

    /** @var object{state_fingerprint: string, nonce_fingerprint: string, browser_binding_fingerprint: string} $raw */
    $raw = DB::table('enterprise_sso_login_attempts')->where('id', $attempt->id)->first();

    expect($raw->state_fingerprint)->toBe(AttemptFingerprint::of(FingerprintPurpose::State, 'state-1'));
    expect($raw->nonce_fingerprint)->toBe(AttemptFingerprint::of(FingerprintPurpose::Nonce, 'nonce-1'));
    expect($raw->browser_binding_fingerprint)
        ->toBe(AttemptFingerprint::of(FingerprintPurpose::BrowserBinding, 'binding-1'));

    // 原文は 1 つも保存されていない
    $encoded = json_encode((array) $raw, JSON_THROW_ON_ERROR);
    foreach (['state-1', 'nonce-1', 'binding-1'] as $plain) {
        expect($encoded)->not->toContain($plain);
    }
});

test('正しい state と結合の秘密なら使用権を得られ、行は消える', function (): void {
    $connection = OrganizationOidcConnection::factory()->create();
    attemptStore()->start($connection, 'state-1', 'nonce-1', 'verifier-1', 'binding-1');

    $result = attemptStore()->consume('state-1', 'binding-1');

    expect($result->succeeded)->toBeTrue();
    expect($result->rowIsGone)->toBeTrue();
    expect($result->attempt?->codeVerifier)->toBe('verifier-1');
    expect($result->attempt?->connection->id)->toBe($connection->id);
    expect(EnterpriseSsoLoginAttempt::query()->count())->toBe(0);
});

test('同じ state を 2 回は使えない (使用権はちょうど 1 つ)', function (): void {
    $connection = OrganizationOidcConnection::factory()->create();
    attemptStore()->start($connection, 'state-1', 'nonce-1', 'verifier-1', 'binding-1');

    expect(attemptStore()->consume('state-1', 'binding-1')->succeeded)->toBeTrue();

    $second = attemptStore()->consume('state-1', 'binding-1');
    expect($second->succeeded)->toBeFalse();
    expect($second->rowIsGone)->toBeTrue();
});

test('別のブラウザで開くと失敗する (login CSRF)', function (): void {
    $connection = OrganizationOidcConnection::factory()->create();
    attemptStore()->start($connection, 'state-1', 'nonce-1', 'verifier-1', 'binding-1');

    $result = attemptStore()->consume('state-1', 'attacker-binding');

    expect($result->succeeded)->toBeFalse();
    expect($result->rowIsGone)->toBeFalse();
});

test('結合の不一致では行が消えない (他人の試行を消せない)', function (): void {
    $connection = OrganizationOidcConnection::factory()->create();
    attemptStore()->start($connection, 'state-1', 'nonce-1', 'verifier-1', 'binding-1');

    attemptStore()->consume('state-1', 'attacker-binding');

    expect(EnterpriseSsoLoginAttempt::query()->count())->toBe(1);

    // 本人はそのまま使える
    expect(attemptStore()->consume('state-1', 'binding-1')->succeeded)->toBeTrue();
});

test('期限切れの行は拒否と同時に消える (トランザクションが巻き戻らない)', function (): void {
    $connection = OrganizationOidcConnection::factory()->create();
    $attempt = attemptStore()->start($connection, 'state-1', 'nonce-1', 'verifier-1', 'binding-1');
    $attempt->forceFill(['expires_at' => now()->subMinute()])->save();

    $result = attemptStore()->consume('state-1', 'binding-1');

    expect($result->succeeded)->toBeFalse();
    expect($result->rowIsGone)->toBeTrue();
    // ★オンアクセス掃除が commit されている
    expect(EnterpriseSsoLoginAttempt::query()->count())->toBe(0);
});

test('存在しない state は「行が無い」として返る (例外にしない)', function (): void {
    $result = attemptStore()->consume('never-issued', 'binding-1');

    expect($result->succeeded)->toBeFalse();
    expect($result->rowIsGone)->toBeTrue();
    expect($result->attempt)->toBeNull();
});

test('用途別の指紋は相互に使い回せない (state の指紋を結合として使えない)', function (): void {
    $connection = OrganizationOidcConnection::factory()->create();
    attemptStore()->start($connection, 'state-1', 'nonce-1', 'verifier-1', 'binding-1');

    // 攻撃者が state の値をそのまま結合の秘密として送っても通らない
    expect(attemptStore()->consume('state-1', 'state-1')->succeeded)->toBeFalse();
});

test('複数タブで同時に開始しても互いの結合を壊さない', function (): void {
    $connection = OrganizationOidcConnection::factory()->create();
    attemptStore()->start($connection, 'state-a', 'nonce-a', 'verifier-a', 'binding-a');
    attemptStore()->start($connection, 'state-b', 'nonce-b', 'verifier-b', 'binding-b');

    expect(attemptStore()->consume('state-a', 'binding-a')->succeeded)->toBeTrue();
    expect(attemptStore()->consume('state-b', 'binding-b')->succeeded)->toBeTrue();
});

test('DB の障害 (削除が行に当たらない) は一様な拒否に畳まれず例外になる (負のコントロール)', function (): void {
    $connection = OrganizationOidcConnection::factory()->create();
    attemptStore()->start($connection, 'state-1', 'nonce-1', 'verifier-1', 'binding-1');

    // 削除が「行に当たらない」状況を、モデルの削除を no-op にして作る。
    EnterpriseSsoLoginAttempt::deleting(static fn (): bool => false);

    try {
        expect(fn () => attemptStore()->consume('state-1', 'binding-1'))
            ->toThrow(EnterpriseSsoAttemptStoreFailure::class);
    } finally {
        EnterpriseSsoLoginAttempt::flushEventListeners();
    }

    // ★巻き戻っているので行は残る
    expect(EnterpriseSsoLoginAttempt::query()->count())->toBe(1);
});

/*
 * ★**行ロックの排他を実際に競合させる 1 本**。
 *
 * グローバル `RefreshDatabase` の下では既定の接続で作った検体が別接続から見えないため、
 * コミット済みの検体を作る土台 ({@see CommittedConnectionHarness}) に乗せる。
 *
 * **証明する**: 1 本目が行を掴んでいる間、2 本目は進めない。1 本目が消したあとは
 * 2 本目にとって行が無い = 使用権を得るのはちょうど 1 つである。
 * **証明しない**: 実 OS プロセスを 2 本立てた場合の PHP 側の競合 (排他の主体は
 * pgsql の行ロックであり、そこは同じ機構である)。
 */
test('行ロックが 2 本目を実際に排他し、使用権を得るのはちょうど 1 つである', function (): void {
    $connectionId = null;
    $organizationId = null;

    try {
        /** @var array{connectionId: int, organizationId: int} $fixture */
        $fixture = CommittedConnectionHarness::create(function (): array {
            $organization = Organization::factory()->create();
            $oidc = OrganizationOidcConnection::factory()->create(['organization_id' => $organization->id]);
            app(EnterpriseLoginAttemptStore::class)->start($oidc, 'state-1', 'nonce-1', 'verifier-1', 'binding-1');

            return ['connectionId' => $oidc->id, 'organizationId' => $organization->id];
        });
        $connectionId = $fixture['connectionId'];
        $organizationId = $fixture['organizationId'];

        // ── 1 本目: 行を掴んだまま保持する
        $holder = CommittedConnectionHarness::connection(CommittedConnectionHarness::SECONDARY);
        $holder->beginTransaction();
        $locked = $holder->table('enterprise_sso_login_attempts')
            ->where('state_fingerprint', AttemptFingerprint::of(FingerprintPurpose::State, 'state-1'))
            ->lockForUpdate()
            ->first();
        expect($locked)->not->toBeNull();

        // ── 2 本目: 同じ行のロックが取れない (待ち上限を置いて「待たされたこと」を観測する)
        CommittedConnectionHarness::limitLockWait(CommittedConnectionHarness::PRIMARY, 400);

        $blocked = CommittedConnectionHarness::onConnection(
            CommittedConnectionHarness::PRIMARY,
            function (): bool {
                try {
                    app(EnterpriseLoginAttemptStore::class)->consume('state-1', 'binding-1');

                    return false;
                } catch (QueryException) {
                    return true;
                }
            },
        );

        expect($blocked)->toBeTrue('2 本目は 1 本目のロックで進めないはず');

        // ── 1 本目が使用権を消費してコミットする
        $holder->table('enterprise_sso_login_attempts')
            ->where('state_fingerprint', AttemptFingerprint::of(FingerprintPurpose::State, 'state-1'))
            ->delete();
        $holder->commit();

        // ── 2 本目は「行が無い」ものとして扱う = 使用権はちょうど 1 つだった
        $second = CommittedConnectionHarness::onConnection(
            CommittedConnectionHarness::PRIMARY,
            fn (): AttemptConsumeResult => app(EnterpriseLoginAttemptStore::class)
                ->consume('state-1', 'binding-1'),
        );

        expect($second->succeeded)->toBeFalse();
        expect($second->attempt)->toBeNull();
    } finally {
        CommittedConnectionHarness::rollbackQuietly(CommittedConnectionHarness::SECONDARY);
        if ($connectionId !== null) {
            CommittedConnectionHarness::cleanup($connectionId, $organizationId);
        }
    }
});
