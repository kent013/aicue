<?php

declare(strict_types=1);

use App\Models\IdempotencyKey;
use Illuminate\Support\Facades\Auth;
use Tests\Support\OAuthTestHelpers;

/*
 * IdempotentRouteCoverageTest の exemption が依拠する**前提**の behavioral proof。
 *
 * exemption は「idempotent を配線しないことが**正しい**」という主張であり、
 * その根拠 (成功後は同じ token が冪等層より前で 401 になる) が vendor 更新や
 * リファクタで崩れたら検出できなければならない。
 *
 * ★主張範囲を誇張しない: 本テストが固定するのは
 *   「revoke 成功後、同じ token での再送は 401 になり、冪等行が 1 件も作られない」
 *   という**観測**であって、「冪等層より前で止まった」ことの直接証明ではない
 *   (実行位置の証明は TenantBoundaryOrderingTest / ApiGuardAllowlistInvariantTest の
 *    順序 gate が担当する)。両者の組合せで免除の前提が成立する。
 */

test('session revoke 後の同一 token 再送は 401 になり冪等行を 1 件も作らない', function (): void {
    [$organization, $owner] = createOrganizationWithOwner('免除前提組織');

    $issued = OAuthTestHelpers::issueCliSessionTokens(
        test: $this,
        user: $owner,
        organization: $organization,
        client: OAuthTestHelpers::createMcpClient(name: 'Premise CLI'),
    );

    $this->flushHeaders();

    $this->withHeader('Authorization', 'Bearer '.$issued['access_token'])
        ->withHeader('Idempotency-Key', 'revoke-premise-1')
        ->deleteJson('/api/v1/me/session')
        ->assertOk();

    Auth::forgetGuards();

    $this->withHeader('Authorization', 'Bearer '.$issued['access_token'])
        ->withHeader('Idempotency-Key', 'revoke-premise-1')
        ->deleteJson('/api/v1/me/session')
        ->assertUnauthorized();

    // 観測上、revoke と再送のどちらでも冪等行は作られない
    // (= 配線しても再生応答が返る経路が無いという免除理由の裏取り)
    expect(IdempotencyKey::query()->count())->toBe(0);
});

test('DELETE /api/v1/mcp は定数 405 スタブのままで冪等行を 1 件も作らない', function (): void {
    // VendorMethodNotAllowedStub の免除根拠 = 「本体処理へ一切到達しない」。
    // vendor が将来この route を意味のある処理に変えたら、免除は無効になる。
    // (405 スタブであること自体は ThrottleExemptionPremiseTest も固定している。
    //  ここで足しているのは「冪等行が作られない」という冪等側の観測である)
    $response = $this->withHeader('Idempotency-Key', 'mcp-stub-premise-1')
        ->delete('/api/v1/mcp');

    expect($response->getStatusCode())->toBe(405);
    expect($response->headers->get('Allow'))->toBe('POST');
    expect($response->getContent())->toBe('');
    expect(IdempotencyKey::query()->count())->toBe(0);
});
