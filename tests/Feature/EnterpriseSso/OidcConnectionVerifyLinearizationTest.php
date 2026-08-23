<?php

declare(strict_types=1);

use App\DataTransferObjects\EnterpriseSso\VerifyOutcome;
use App\Enums\EnterpriseSso\OidcConnectionStatus;
use App\Exceptions\EnterpriseSso\OidcConnectionTransitionException;
use App\Models\Organization;
use App\Models\OrganizationOidcConnection;
use App\Services\EnterpriseSso\OidcConnectionTransitionService;
use App\ValueObjects\EnterpriseSso\ConnectionSecret;
use App\ValueObjects\EnterpriseSso\OidcIssuerUrl;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Tests\Support\EnterpriseSso\FakeIdentityProvider;

/*
 * `verify` の三段構成の線形化 (D1)。
 *
 * ★**同期の割り込み注入**で作る (待ち合わせを使わない)。同一プロセスで callback に待たせると、
 *   `verify()` が戻らないためテスト本体が割り込みを起こせず**必ずデッドロックする**。
 *   偽 IdP の応答直前の callback が**そのまま自分で割り込みを行って戻る**形にする。
 */

function verifyService(): OidcConnectionTransitionService
{
    return app(OidcConnectionTransitionService::class);
}

function draftConnection(FakeIdentityProvider $idp): OrganizationOidcConnection
{
    return OrganizationOidcConnection::factory()->create([
        'issuer' => $idp->issuer,
        'client_secret_encrypted' => ConnectionSecret::fromPlaintext('secret-value'),
    ]);
}

test('通常の verify は Draft → Verified へ進む', function (): void {
    $idp = (new FakeIdentityProvider)->install();
    $connection = draftConnection($idp);
    /** @var Organization $organization */
    $organization = $connection->organization;

    expect(verifyService()->verify($organization, $connection))->toBe(VerifyOutcome::Verified);

    $fresh = $connection->fresh();
    expect($fresh?->status)->toBe(OidcConnectionStatus::Verified);
    expect($fresh?->verified_at)->not->toBeNull();
});

test('外部取得の間に認証材料を更新すると、古い結果が採用されない (本命)', function (): void {
    $idp = (new FakeIdentityProvider)->install();
    $connection = draftConnection($idp);
    /** @var Organization $organization */
    $organization = $connection->organization;

    $idp->beforeRespond(function () use ($organization, $connection): void {
        // ★「スナップショットを取った後・応答を返す前」に割り込む。待たない。
        verifyService()->update(
            $organization,
            $connection->id,
            null,
            OidcIssuerUrl::fromString('https://new.example.test'),
            null,
            null,
        );
    });

    expect(verifyService()->verify($organization, $connection))->toBe(VerifyOutcome::StaleCredentials);

    $fresh = $connection->fresh();
    expect($fresh?->status)->toBe(OidcConnectionStatus::Draft);
    expect($fresh?->verified_at)->toBeNull();
});

test('client secret だけを変えた場合も採用されない', function (): void {
    $idp = (new FakeIdentityProvider)->install();
    $connection = draftConnection($idp);
    /** @var Organization $organization */
    $organization = $connection->organization;

    $idp->beforeRespond(function () use ($organization, $connection): void {
        verifyService()->update($organization, $connection->id, null, null, null, ConnectionSecret::fromPlaintext('new'));
    });

    expect(verifyService()->verify($organization, $connection))->toBe(VerifyOutcome::StaleCredentials);
    expect($connection->fresh()?->status)->toBe(OidcConnectionStatus::Draft);
});

test('版を据え置いたまま client secret だけ差し替えても採用されない (第 3 の比較子の単独の証明)', function (): void {
    $idp = (new FakeIdentityProvider)->install();
    $connection = draftConnection($idp);
    /** @var Organization $organization */
    $organization = $connection->organization;

    $idp->beforeRespond(function () use ($connection): void {
        // ★D1 を通さず DB へ直接書く = credentials_revision は増えない。
        //   それでも**暗号文の digest** が変わるので採用されない。
        DB::table('organization_oidc_connections')
            ->where('id', $connection->id)
            ->update(['client_secret_encrypted' => encrypt('totally-different')]);
    });

    expect(verifyService()->verify($organization, $connection))->toBe(VerifyOutcome::StaleCredentials);
});

test('版を据え置いたまま issuer だけ変えても採用されない (第 2 の比較子の単独の証明)', function (): void {
    $idp = (new FakeIdentityProvider)->install();
    $connection = draftConnection($idp);
    /** @var Organization $organization */
    $organization = $connection->organization;

    $idp->beforeRespond(function () use ($connection): void {
        DB::table('organization_oidc_connections')
            ->where('id', $connection->id)
            ->update(['issuer' => 'https://sneaky.example.test']);
    });

    expect(verifyService()->verify($organization, $connection))->toBe(VerifyOutcome::StaleCredentials);
});

test('同じ平文の secret を保存し直しただけでも採用されない (digest は拒否の側へ倒れる = fail-closed)', function (): void {
    $idp = (new FakeIdentityProvider)->install();
    $connection = draftConnection($idp);
    /** @var Organization $organization */
    $organization = $connection->organization;

    $idp->beforeRespond(function () use ($connection): void {
        // ★同じ平文でも再暗号化で別の暗号文になるので digest が変わる。
        //   これは**偽陽性の側 = 拒否の側**であり安全側である (運営はもう一度押せばよい)。
        DB::table('organization_oidc_connections')
            ->where('id', $connection->id)
            ->update(['client_secret_encrypted' => encrypt('secret-value')]);
    });

    expect(verifyService()->verify($organization, $connection))->toBe(VerifyOutcome::StaleCredentials);
});

test('表示名だけを変えた場合は verify が成功する (負のコントロール)', function (): void {
    $idp = (new FakeIdentityProvider)->install();
    $connection = draftConnection($idp);
    /** @var Organization $organization */
    $organization = $connection->organization;

    $idp->beforeRespond(function () use ($organization, $connection): void {
        // ★認証に関与しない更新は巻き込まない (updated_at で代用していたら落ちる)。
        verifyService()->update($organization, $connection->id, '新しい表示名', null, null, null);
    });

    expect(verifyService()->verify($organization, $connection))->toBe(VerifyOutcome::Verified);
    expect($connection->fresh()?->status)->toBe(OidcConnectionStatus::Verified);
});

test('取得中に接続が削除されたら Verified にしない', function (): void {
    $idp = (new FakeIdentityProvider)->install();
    $connection = draftConnection($idp);
    /** @var Organization $organization */
    $organization = $connection->organization;

    $idp->beforeRespond(function () use ($organization, $connection): void {
        verifyService()->destroy($organization, $connection->id);
    });

    expect(verifyService()->verify($organization, $connection))->toBe(VerifyOutcome::ConnectionGone);
    expect(OrganizationOidcConnection::query()->whereKey($connection->id)->exists())->toBeFalse();
});

test('他組織の接続 id では再取得できない (relation 起点であることの証明)', function (): void {
    $idp = (new FakeIdentityProvider)->install();
    $connection = draftConnection($idp);
    $otherOrganization = Organization::factory()->create();

    expect(verifyService()->verify($otherOrganization, $connection))->toBe(VerifyOutcome::ConnectionGone);
    expect($connection->fresh()?->status)->toBe(OidcConnectionStatus::Draft);
});

test('同じ材料の verify が二重に走っても例外にならず、2 回目は遷移しない成功になる', function (): void {
    $idp = (new FakeIdentityProvider)->install();
    $connection = draftConnection($idp);
    /** @var Organization $organization */
    $organization = $connection->organization;

    expect(verifyService()->verify($organization, $connection))->toBe(VerifyOutcome::Verified);

    $verifiedAt = $connection->fresh()?->verified_at;
    expect(verifyService()->verify($organization, $connection))->toBe(VerifyOutcome::AlreadyVerified);
    expect($connection->fresh()?->verified_at?->equalTo($verifiedAt))->toBeTrue();
});

test('Active / Disabled から verify を呼ぶと定義外の遷移として例外になる', function (OidcConnectionStatus $status): void {
    $idp = (new FakeIdentityProvider)->install();
    $connection = draftConnection($idp);
    $connection->forceFill(['status' => $status, 'verified_at' => now()])->save();
    /** @var Organization $organization */
    $organization = $connection->organization;

    expect(fn () => verifyService()->verify($organization, $connection))
        ->toThrow(OidcConnectionTransitionException::class);
})->with([OidcConnectionStatus::Active, OidcConnectionStatus::Disabled]);

/*
 * ★**外向き取得の間に接続の行がロックされていない**ことの証明。
 *
 * ★`beforeRespond` の中の別操作が完了することを根拠に**しない** (Round 8 の注意)。
 *   同一プロセス・**同一の DB 接続**では自分が取った行ロックは**再入できる**ので、
 *   ロックを持っていても止まらず、証明にならない。
 * ★代わりに **query listener で直接測る**。再入の影響を受けず、
 *   「第 2 段までロックを取っていない」を**そのまま**測る。
 */
test('外向き取得の時点で for update が 1 つも発行されていない', function (): void {
    $idp = (new FakeIdentityProvider)->install();
    $connection = draftConnection($idp);
    /** @var Organization $organization */
    $organization = $connection->organization;

    /** @var list<string> $statements */
    $statements = [];
    DB::listen(function (QueryExecuted $event) use (&$statements): void {
        $statements[] = strtolower($event->sql);
    });

    /** @var list<string> $atRespond */
    $atRespond = [];
    $idp->beforeRespond(function () use (&$statements, &$atRespond): void {
        $atRespond = $statements;
    });

    verifyService()->verify($organization, $connection);

    $lockingBeforeFetch = array_values(array_filter(
        $atRespond,
        static fn (string $sql): bool => str_contains($sql, 'for update'),
    ));

    expect($lockingBeforeFetch)->toBe([], '第 2 段より前に行ロックを取ってはいけない');

    // ★走査が空振りしていないこと (第 3 段では実際にロックを取っている)
    expect(array_values(array_filter(
        $statements,
        static fn (string $sql): bool => str_contains($sql, 'for update'),
    )))->not->toBe([]);
});

test('第 2 段がトランザクションの段数を増やしていない', function (): void {
    $idp = (new FakeIdentityProvider)->install();
    $connection = draftConnection($idp);
    /** @var Organization $organization */
    $organization = $connection->organization;

    // ★**基準の段数を先に取る**。グローバル RefreshDatabase がテスト全体を 1 つの
    //   トランザクションで包むので、Feature レーンでは通常 1 であって 0 ではない。
    //   固定したい不変条件は「第 2 段が段を増やさない」であって絶対値ではない。
    $baseline = DB::transactionLevel();

    /** @var list<int> $observed */
    $observed = [];
    $idp->beforeRespond(function () use (&$observed): void {
        $observed[] = DB::transactionLevel();
    });

    verifyService()->verify($organization, $connection);

    expect($observed)->not->toBe([]);
    foreach ($observed as $level) {
        expect($level)->toBe($baseline);
    }
});
