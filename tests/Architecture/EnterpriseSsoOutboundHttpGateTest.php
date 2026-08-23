<?php

declare(strict_types=1);

use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use Tests\Support\EnterpriseSso\EnterpriseSsoSourceScanner;

/*
 * G2: 企業 SSO の外向き取得は pin 済み経路だけを通る。
 *
 * ## 本 gate が主張する範囲 (これ以上を主張しない)
 *
 * 次の 3 つの積だけである:
 *  1. 走査根の中に**既知の禁止型・ファサードの参照**が無い
 *  2. 走査根の中に**動的な呼び出しの形**が無い
 *  3. 走査根の中に**受け手の型が解決できない保護対象語彙の呼び出し**が無い
 *
 * ★**「外向きは PinnedHttpClient だけである」という主張の主証明は静的側に置かない。**
 *   主証明は次の 2 本である:
 *     - **DI の結線テスト** (`tests/Feature/EnterpriseSso/EnterpriseSsoHttpWiringTest.php`) —
 *       企業 SSO の 3 サービスへ注入される HTTP の担い手が `PinnedHttpClient` だけであること
 *     - **実挙動テスト** (`tests/Feature/EnterpriseSso/OidcDiscoveryServiceTest.php` ほか) —
 *       実装が pin 済み経路を実際に通ること (通らなければ偽 IdP に 1 件も要求が届かない)
 *
 * ## 保証しないもの (誇張しない)
 *
 * - 文字列で解決する container 経由 (`app('…')`) は見ない
 * - vendor の内部から出る通信は見ない
 * - 走査根の外 (controller / Job など) は母集団に入らない
 *
 * 走査器そのものの検出力は `tests/Unit/Architecture/EnterpriseSsoSourceScannerTest.php` が
 * 負例と正例の**両方向**で固定する。
 */

/** 走査根 (存在しなければ fail-fast する)。 */
function enterpriseSsoOutboundRoots(): array
{
    return ['app/Services/EnterpriseSso'];
}

/** 保護対象の語彙 (受け手の型を解決できないまま書けてはいけない呼び出し)。 */
function enterpriseSsoProtectedVocabulary(): array
{
    return ['fetch', 'get', 'post', 'send', 'request', 'put', 'patch', 'delete', 'head'];
}

test('G2-1: 走査根に禁止型・ファサードの参照が無い (許可一覧を持たない)', function (): void {
    $sources = EnterpriseSsoSourceScanner::sources(enterpriseSsoOutboundRoots());

    expect(EnterpriseSsoSourceScanner::forbiddenClassReferences($sources, [
        Http::class,
        HttpFactory::class,
        'GuzzleHttp\Client',
        'Symfony\Component\HttpClient\HttpClient',
    ]))->toBe([], '企業 SSO の外向き取得は PinnedHttpClient だけを通ること');
});

test('G2-2: 走査根に動的な呼び出しの形が無い (未解決を無言で候補から外さない)', function (): void {
    $sources = EnterpriseSsoSourceScanner::sources(enterpriseSsoOutboundRoots());

    expect(EnterpriseSsoSourceScanner::dynamicCallForms($sources))->toBe([]);
});

test('G2-3: 受け手の型が解決できない保護対象語彙の呼び出しが無い', function (): void {
    $sources = EnterpriseSsoSourceScanner::sources(enterpriseSsoOutboundRoots());

    expect(EnterpriseSsoSourceScanner::unresolvedProtectedCalls($sources, enterpriseSsoProtectedVocabulary()))
        ->toBe([]);
});

test('G2-4: すべての fetch() が追従を明示的に切っている (呼び出し単位で見る)', function (): void {
    $sources = EnterpriseSsoSourceScanner::sources(enterpriseSsoOutboundRoots());

    // ★`fetch()` の第 3 引数は**既定が true** なので、**リテラルの false** を渡していることを
    //   **呼び出しごとに**要求する。
    //   - ファイル単位の部分文字列一致だと、同じファイルへ既定値の呼び出しを 1 行足すだけで見逃す
    //   - 名前付き引数の**存在だけ**を見ると `followRedirects: true` が素通りする
    //   - 静的に確定できない値 (`$configured` / `! false` / `false || true`) も通さない (fail-closed)
    expect(EnterpriseSsoSourceScanner::callsWithoutNamedLiteral($sources, 'fetch', 'followRedirects', 'false'))
        ->toBe([], 'pin 済み経路の fetch() は followRedirects: false を明示すること');
});

test('G2-5: 走査が空振りしていない (母集団が空でない)', function (): void {
    expect(EnterpriseSsoSourceScanner::sources(enterpriseSsoOutboundRoots()))->not->toBe([]);
});
