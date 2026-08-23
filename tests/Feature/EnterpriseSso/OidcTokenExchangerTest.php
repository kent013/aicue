<?php

declare(strict_types=1);

use App\Enums\EnterpriseSso\RejectionReason;
use App\Exceptions\EnterpriseSso\EnterpriseSsoAttemptRejectedException;
use App\Models\OrganizationOidcConnection;
use App\Services\EnterpriseSso\OidcDiscoveryService;
use App\Services\EnterpriseSso\OidcTokenExchanger;
use App\ValueObjects\EnterpriseSso\ConnectionSecret;
use App\ValueObjects\EnterpriseSso\OidcIssuerUrl;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;
use Kent013\SsrfPin\Dtos\PinnedFailure;
use Kent013\SsrfPin\Enums\TransportError;
use Tests\Support\EnterpriseSso\FakeIdentityProvider;

/*
 * 認可コードとトークンの交換 (B2)。
 */

function exchangeWith(FakeIdentityProvider $idp, OrganizationOidcConnection $connection): callable
{
    $metadata = app(OidcDiscoveryService::class)->fetchMetadata(OidcIssuerUrl::fromString($idp->issuer));

    return fn (): mixed => app(OidcTokenExchanger::class)->exchange(
        $connection,
        $metadata,
        'https://app.example.test/enterprise/callback',
        'auth-code-value',
        'code-verifier-value',
    );
}

function connectionFor(FakeIdentityProvider $idp): OrganizationOidcConnection
{
    return OrganizationOidcConnection::factory()->create([
        'issuer' => $idp->issuer,
        'client_id' => 'client-1234',
        'client_secret_encrypted' => ConnectionSecret::fromPlaintext('very-secret-value'),
    ]);
}

test('code_verifier が要求 body に載る (PKCE の往復の片端)', function (): void {
    $idp = (new FakeIdentityProvider)->withClaims(['nonce' => 'n'])->install();
    $connection = connectionFor($idp);

    exchangeWith($idp, $connection)();

    parse_str((string) $idp->lastTokenRequest()?->body, $form);
    expect($form['code_verifier'])->toBe('code-verifier-value');
    expect($form['grant_type'])->toBe('authorization_code');
    expect($form['redirect_uri'])->toBe('https://app.example.test/enterprise/callback');
});

test('IdP が basic に対応していれば body に client_secret を載せない', function (): void {
    $idp = (new FakeIdentityProvider)->withClaims(['nonce' => 'n'])->install();
    $connection = connectionFor($idp);

    exchangeWith($idp, $connection)();

    $request = $idp->lastTokenRequest();
    parse_str((string) $request?->body, $form);

    expect($form)->not->toHaveKey('client_secret');
    // 実送信要求には資格情報が**必ず在る** (これは漏洩ではなく到達の検証である)
    expect($request?->headers['Authorization'] ?? '')->toStartWith('Basic ');
    expect(base64_decode(substr((string) ($request?->headers['Authorization'] ?? ''), 6), true))
        ->toBe('client-1234:very-secret-value');
});

test('basic に対応しない IdP では body に載せる (post へ落ちる)', function (): void {
    $idp = (new FakeIdentityProvider)
        ->withMetadata(['token_endpoint_auth_methods_supported' => ['client_secret_post']])
        ->withClaims(['nonce' => 'n'])
        ->install();
    $connection = connectionFor($idp);

    exchangeWith($idp, $connection)();

    $request = $idp->lastTokenRequest();
    parse_str((string) $request?->body, $form);

    expect($form['client_secret'])->toBe('very-secret-value');
    expect($request?->headers ?? [])->not->toHaveKey('Authorization');
});

test('transport の失敗が値で返っても固定の理由コードの例外になる (catch では捕まらない経路)', function (): void {
    $idp = (new FakeIdentityProvider)->withClaims(['nonce' => 'n'])->install();
    $connection = connectionFor($idp);
    $exchange = exchangeWith($idp, $connection);

    $idp->withTransportFailure(new PinnedFailure(TransportError::Timeout, $idp->issuer.'/token', 0));

    expect($exchange)->toThrow(EnterpriseSsoAttemptRejectedException::class);
});

test('3xx / 4xx を成功として扱わない', function (int $status): void {
    $idp = (new FakeIdentityProvider)->withClaims(['nonce' => 'n'])->install();
    $connection = connectionFor($idp);
    $exchange = exchangeWith($idp, $connection);

    $idp->withStatus($status);

    expect($exchange)->toThrow(EnterpriseSsoAttemptRejectedException::class);
})->with([302, 400, 401, 500]);

test('応答の形が期待と違えば拒否する', function (string $body): void {
    $idp = (new FakeIdentityProvider)->withClaims(['nonce' => 'n'])->install();
    $connection = connectionFor($idp);
    $exchange = exchangeWith($idp, $connection);

    $idp->withBody($body);

    expect($exchange)->toThrow(EnterpriseSsoAttemptRejectedException::class);
})->with([
    'JSON でない' => ['not json'],
    'オブジェクトでない' => ['"a string"'],
    'id_token が無い' => ['{"access_token":"a"}'],
    'id_token が文字列でない' => ['{"id_token":123}'],
]);

test('大きすぎる応答を拒否する', function (): void {
    $idp = (new FakeIdentityProvider)->withClaims(['nonce' => 'n'])->install();
    $connection = connectionFor($idp);
    $exchange = exchangeWith($idp, $connection);

    $idp->withBody(str_repeat('x', 70000));

    expect($exchange)->toThrow(EnterpriseSsoAttemptRejectedException::class);
});

test('失敗しても例外の中身に秘密・認可コード・検証子が出ない', function (): void {
    $idp = (new FakeIdentityProvider)->withClaims(['nonce' => 'n'])->install();
    $connection = connectionFor($idp);
    $exchange = exchangeWith($idp, $connection);

    $idp->withStatus(400)->withBody('{"error":"invalid_client"}');

    try {
        $exchange();
        expect(false)->toBeTrue('例外になるはず');
    } catch (EnterpriseSsoAttemptRejectedException $e) {
        $rendered = $e->getMessage().$e->getTraceAsString();

        foreach (['very-secret-value', 'auth-code-value', 'code-verifier-value'] as $secret) {
            expect($rendered)->not->toContain($secret);
            expect($rendered)->not->toContain(base64_encode($secret));
            expect($rendered)->not->toContain(urlencode($secret));
        }

        // 例外は理由の enum しか持たない
        expect($e->getPrevious())->toBeNull();
    }
});

test('ログに秘密・認可コード・検証子が残らない', function (): void {
    $records = [];
    Log::listen(function (MessageLogged $event) use (&$records): void {
        $records[] = $event->message.json_encode($event->context);
    });

    $idp = (new FakeIdentityProvider)->withClaims(['nonce' => 'n'])->install();
    $connection = connectionFor($idp);
    $exchange = exchangeWith($idp, $connection);
    $idp->withStatus(400);

    try {
        $exchange();
    } catch (EnterpriseSsoAttemptRejectedException) {
        // 一様な拒否になるのは別テストが固定する
    }

    $combined = implode("\n", $records);
    foreach (['very-secret-value', 'auth-code-value', 'code-verifier-value'] as $secret) {
        expect($combined)->not->toContain($secret);
        expect($combined)->not->toContain(base64_encode($secret));
    }
});

test('例外の構築子は理由の enum しか受け取らない (連鎖が型で起きない)', function (): void {
    $constructor = (new ReflectionClass(EnterpriseSsoAttemptRejectedException::class))->getConstructor();

    expect($constructor?->isPrivate())->toBeTrue();
    expect($constructor?->getNumberOfParameters())->toBe(1);
    expect((string) $constructor?->getParameters()[0]->getType())
        ->toBe(RejectionReason::class);
});
