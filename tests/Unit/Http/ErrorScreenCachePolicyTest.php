<?php

declare(strict_types=1);

use App\Support\Http\ErrorScreenCachePolicy;
use Symfony\Component\HttpFoundation\Response;

/*
 * Error 画面差し替え応答のキャッシュ表現契約 (DB 不使用・reflection 不使用)。
 *
 * ★**加算方式**であること (set() で Cache-Control を丸ごと書き換えない) を固定する。
 *   呼び出し側が既に積んだ directive を落とさないことが本クラスの契約であり、
 *   Feature テストからは検証できない (差し替え応答は新規生成されるため原応答の
 *   directive を持たない = 別契約で落ちてしまう)。
 */

/** @return list<string> Cache-Control の directive 一覧 (小文字・トリム済み)。 */
function errorScreenCacheDirectives(Response $response): array
{
    $header = (string) $response->headers->get('Cache-Control');

    return array_values(array_filter(array_map(
        static fn (string $part): string => strtolower(trim($part)),
        explode(',', $header),
    ), static fn (string $part): bool => $part !== ''));
}

test('no-store と private を付ける', function (): void {
    $response = new Response;

    ErrorScreenCachePolicy::apply($response);

    expect($response->headers->hasCacheControlDirective('no-store'))->toBeTrue();
    expect($response->headers->hasCacheControlDirective('private'))->toBeTrue();
});

test('public を残さない', function (): void {
    $response = new Response;
    $response->headers->set('Cache-Control', 'public, max-age=600');

    ErrorScreenCachePolicy::apply($response);

    expect(errorScreenCacheDirectives($response))->not->toContain('public');
    expect($response->headers->hasCacheControlDirective('private'))->toBeTrue();
});

test('既存の directive を落とさない', function (): void {
    $response = new Response;
    $response->headers->set('Cache-Control', 'must-revalidate');

    ErrorScreenCachePolicy::apply($response);

    $directives = errorScreenCacheDirectives($response);
    expect($directives)->toContain('must-revalidate');
    expect($directives)->toContain('no-store');
    expect($directives)->toContain('private');
});

test('二重適用しても矛盾しない', function (): void {
    $response = new Response;

    ErrorScreenCachePolicy::apply($response);
    ErrorScreenCachePolicy::apply($response);

    $directives = errorScreenCacheDirectives($response);
    expect(array_values(array_unique($directives)))->toBe($directives);
    expect($directives)->toContain('no-store');
    expect($directives)->toContain('private');
    expect($directives)->not->toContain('public');

    $vary = $response->getVary();
    expect(array_values(array_unique($vary)))->toBe($vary);
});

test('既存の Vary を落とさず 3 ヘッダを追加する', function (): void {
    $response = new Response;
    $response->setVary(['Accept-Encoding']);

    ErrorScreenCachePolicy::apply($response);

    $vary = array_map(strtolower(...), $response->getVary());
    expect($vary)->toContain('accept-encoding');
    expect($vary)->toContain('x-inertia');
    expect($vary)->toContain('x-inertia-version');
    expect($vary)->toContain('accept');
});
