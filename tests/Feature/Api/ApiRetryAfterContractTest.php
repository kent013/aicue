<?php

declare(strict_types=1);

use App\Exceptions\ApiExceptionRenderer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

/*
 * API 封筒 (裁定 (c)) の Retry-After 契約。
 *
 * 本文 (details.retry_after) と HTTP ヘッダの**両方**が
 * App\Support\Http\RetryAfterSeconds を通ることを固定する
 * (同一応答の中で 2 つの解釈が並ばない = 解釈の SoT は 1 つ)。
 *
 * throttle 閾値まで連打すると遅いため、ApiExceptionRenderer::render() を
 * api/* の Request で直接呼ぶ契約テストにする。
 */

/** api/* を満たす Request。 */
function apiRetryAfterRequest(): Request
{
    return Request::create('/api/v1/items', 'GET');
}

/**
 * @param  array<string, string>  $headers
 * @return array{payload: array<string, mixed>, response: JsonResponse}
 */
function apiRetryAfterRender(int $status, array $headers): array
{
    $response = ApiExceptionRenderer::render(
        new HttpException($status, 'Too Many Attempts.', headers: $headers),
        apiRetryAfterRequest(),
    );

    expect($response)->not->toBeNull();
    /** @var JsonResponse $response */
    $decoded = json_decode((string) $response->getContent(), true);
    expect($decoded)->toBeArray();
    /** @var array<string, mixed> $decoded */

    return ['payload' => $decoded, 'response' => $response];
}

test('429 の Retry-After が整数のとき details.retry_after に int で載る', function (): void {
    ['payload' => $payload] = apiRetryAfterRender(429, ['Retry-After' => '60']);

    expect($payload)->toHaveKey('error');
    /** @var array<string, mixed> $error */
    $error = $payload['error'];
    expect($error)->toHaveKey('details');
    /** @var array<string, mixed> $details */
    $details = $error['details'];
    expect($details['retry_after'])->toBe(60);
});

test('Retry-After が HTTP-date のとき details を出さない (厳格化)', function (): void {
    ['payload' => $payload] = apiRetryAfterRender(429, ['Retry-After' => 'Wed, 21 Oct 2015 07:28:00 GMT']);

    /** @var array<string, mixed> $error */
    $error = $payload['error'];
    expect($error)->not->toHaveKey('details');
});

test('Retry-After が負数のとき details を出さない (厳格化)', function (): void {
    ['payload' => $payload] = apiRetryAfterRender(429, ['Retry-After' => '-5']);

    /** @var array<string, mixed> $error */
    $error = $payload['error'];
    expect($error)->not->toHaveKey('details');
});

test('Retry-After が未設定のとき details を出さない', function (): void {
    ['payload' => $payload] = apiRetryAfterRender(429, []);

    /** @var array<string, mixed> $error */
    $error = $payload['error'];
    expect($error)->not->toHaveKey('details');
});

test('Retry-After ヘッダも本文と同じ解釈になる', function (): void {
    ['response' => $valid] = apiRetryAfterRender(429, ['Retry-After' => '60']);
    expect($valid->headers->get('Retry-After'))->toBe('60');

    ['response' => $httpDate] = apiRetryAfterRender(429, ['Retry-After' => 'Wed, 21 Oct 2015 07:28:00 GMT']);
    expect($httpDate->headers->has('Retry-After'))->toBeFalse();

    ['response' => $negative] = apiRetryAfterRender(429, ['Retry-After' => '-5']);
    expect($negative->headers->has('Retry-After'))->toBeFalse();
});

test('Retry-After 以外の例外ヘッダは従来どおり移送される', function (): void {
    $response = ApiExceptionRenderer::render(
        new UnauthorizedHttpException('Bearer realm="api"', 'Unauthenticated.'),
        apiRetryAfterRequest(),
    );

    expect($response)->not->toBeNull();
    /** @var JsonResponse $response */
    expect($response->headers->get('WWW-Authenticate'))->toBe('Bearer realm="api"');
});
