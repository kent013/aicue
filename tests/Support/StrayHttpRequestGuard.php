<?php

declare(strict_types=1);

namespace Tests\Support;

use Closure;
use GuzzleHttp\Psr7\Uri;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\StrayRequestException;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Psr\Http\Message\RequestInterface;
use RuntimeException;

/**
 * テストレーンの HTTP 出口を既定拒否にし、握り潰されても検出できるようにする guard。
 *
 * 裁定 AG-105 の必須要件 (テストレーンの既定として preventStrayRequests を常時有効化 +
 * 自機宛て loopback の明示許可) の実装。設計は devnotes/20260807-1235-stray-http-egress-deny/。
 *
 * 仕組み:
 *  1. install() で Http Factory に preventStrayRequests + allowStrayRequests(loopback) を張る。
 *  2. 同じ Factory の globalMiddleware に **自分自身** (invokable) を 1 本積む。
 *     globalMiddleware は Guzzle handler stack の**最外側**に来る (stub handler は最内側) ため、
 *     stub handler が同期 throw する StrayRequestException を確実に観測できる。
 *  3. 観測した stray を static accumulator に記録してから **再 throw** する
 *     (フレームワークの既定挙動を変えない)。
 *  4. tests/Pest.php の afterEach が flushAndFailIfStray() を呼び、記録があれば test を fail させる。
 *
 * ★4 が本 guard の存在意義。FxRateService::fetchFromFrankfurter は catch (Throwable) で
 *   例外を握り潰し、SnsCertificateFetcher は証明書取得の失敗を
 *   SnsVerificationUnavailableException へ写像するため、
 *   preventStrayRequests **だけ**では「fx_snapshot が null になる」「取りに行った事実が
 *   503 に化ける」等の挙動変化に紛れて、テストが静かに緑のまま通る。
 *   accumulator があれば必ず赤くなる
 *   (StrayLlmCallGuard で既に学習済みの失敗を繰り返さない)。
 *
 * **保証範囲**: Laravel HTTP client (`Illuminate\Http\Client`) 経由の出口**のみ**。
 * 同一プロセス内でしか効かない。Socialite (Guzzle 直) / Stripe SDK / AWS SDK /
 * Playwright ブラウザ自身の fetch / bug-hunt の別プロセス実行は**対象外**。
 * さらに許可判定は**名前解決前の URL 文字列**照合なので、hosts / DNS の健全性
 * (`localhost` が loopback に解決されること) は**保証しない** — それは前提である。
 *
 * ただし「URL 文字列の glob 照合だけ」では userinfo 詐称
 * (`http://127.0.0.1:80@api.frankfurter.dev/`) が許可パターンを潜り抜けるため、
 * **パース済みホストによる第 2 層** (`isSmuggledLoopbackUrl()`) を middleware で併用する。
 */
final class StrayHttpRequestGuard
{
    /**
     * 自機宛て loopback の明示許可パターン (単一 source of truth)。
     *
     * ★`config('app.url')` の host は**含めない**。理由:
     *  (1) Browser lane の in-process サーバは常に 127.0.0.1 に bind する
     *      (pest-plugin-browser ServerManager::DEFAULT_HOST) ので loopback リテラルで足りる。
     *  (2) その in-process サーバは boot 時に config('app.url') を**実行中に書き換える**ため、
     *      beforeEach 時点の snapshot は Browser lane で古い値になる。
     *  (3) APP_URL は環境依存 (.env は http://aicue.test、.env.example は http://localhost)。
     *      許可集合を環境依存にすると Architecture gate が固定値を検査できず、
     *      「開発者の .env 次第で外部ドメインが許可される」穴になる。
     *
     * ★`localhost` / `[::1]` を残す理由 (127.0.0.1 だけで足りるのでは、への回答):
     *   Browser lane の in-process サーバは 127.0.0.1 で足りるが、テスト本体や将来の
     *   fake 基盤が `localhost` 表記の自機 URL を組み立てることは普通に起きる
     *   (config/mcp.php の allowed origins も `http://localhost` / `http://127.0.0.1` の
     *    両方を持つ)。表記揺れで偽赤を出すコストの方が大きいので 3 ホストを持つ。
     *
     *   ★ただし判定機構の保証を誇張しない: `PendingRequest::isAllowedRequestUrl()` は
     *   **名前解決前の URL 文字列**に対する `Str::is()` 照合である。したがって
     *   `localhost` が外部 IP へ解決される環境では、この許可を通ったうえで
     *   **実際に外部へ送信されうる**。つまり本 guard は
     *   「`localhost` はテスト実行環境で loopback に解決される」を**前提として置いている**
     *   だけであり、hosts / DNS の健全性は保証しない (保証範囲の注記にも明記する)。
     *   その前提を置けないホスト名 (`aicue.test` のような任意のカスタムドメイン) は
     *   **入れない** — 解決先の前提が置けず、許可集合も環境依存になるため。
     *
     * ★末尾ワイルドカード 1 本 (`http://127.0.0.1*`) にはしない。
     *   Str::is() の glob では `http://127.0.0.1.evil.example/` まで通ってしまう。
     *   「ポート無し」「:ポート」「/パス」の 3 形で 1 ホストを覆う。
     *
     * ★**この定数だけでは loopback を保証できない** (Codex impl-review Round 1 の Critical):
     *   `:*` の `*` は Str::is() では任意文字列に展開されるため、**userinfo 詐称**
     *   (`http://127.0.0.1:80@api.frankfurter.dev/` — userinfo が `127.0.0.1:80`、
     *    実ホストは `api.frankfurter.dev`) が一致してしまう (PHP 実測で確認済み)。
     *   glob には「ここから先に `@` を含まない」を表現する手段が無いので、
     *   **パース済みホストによる第 2 層** (`isSmuggledLoopbackUrl()`) を guard 側に持つ。
     *
     * @var list<non-empty-string>
     */
    public const ALLOWED_URL_PATTERNS = [
        'http://127.0.0.1',
        'http://127.0.0.1/*',
        'http://127.0.0.1:*',
        'https://127.0.0.1',
        'https://127.0.0.1/*',
        'https://127.0.0.1:*',
        'http://localhost',
        'http://localhost/*',
        'http://localhost:*',
        'https://localhost',
        'https://localhost/*',
        'https://localhost:*',
        'http://[::1]',
        'http://[::1]/*',
        'http://[::1]:*',
        'https://[::1]',
        'https://[::1]/*',
        'https://[::1]:*',
    ];

    /**
     * ALLOWED_URL_PATTERNS のホスト部と 1:1 対応する loopback ホストの正本。
     *
     * `isSmuggledLoopbackUrl()` の第 2 層判定に使う。PSR-7 の `Uri::getHost()` は
     * IPv6 リテラルを角括弧付き (`[::1]`) で返すため、ここも角括弧付きで持つ (実測で確認)。
     *
     * @var list<non-empty-string>
     */
    public const LOOPBACK_HOSTS = ['127.0.0.1', 'localhost', '[::1]'];

    /** @var list<array{method: string, url: string}> */
    private static array $strayRequests = [];

    /**
     * Pest beforeEach から呼ぶ。前テストの残留を clear したうえで guard を install する。
     *
     * 各テストの setUp() が refreshApplication() するため Factory は毎テスト新品だが、
     * 「同一テスト内で 2 回呼ばれる」「将来 refreshApplication を経ないレーンが増える」
     * ケースで middleware が二重登録されると同じ stray を 2 件記録してしまうので、
     * **冪等**にしておく。
     */
    public static function install(Application $app): void
    {
        self::$strayRequests = [];

        $factory = $app->make(HttpFactory::class);

        // 既定拒否 + loopback だけを明示許可。allowStrayRequests(array) は置換なので、
        // ここが許可集合の唯一の設定点になる。
        $factory->preventStrayRequests();
        $factory->allowStrayRequests(self::ALLOWED_URL_PATTERNS);

        /** @var mixed $middleware */
        foreach ($factory->getGlobalMiddleware() as $middleware) {
            if ($middleware instanceof self) {
                return; // 既に積まれている (冪等)
            }
        }

        $factory->globalMiddleware(new self);
    }

    /**
     * Pest afterEach から呼ぶ。stray が記録されていれば RuntimeException を throw して
     * test を fail させる。アプリ側の try/catch で例外が握り潰されても、このパスで必ず赤くなる。
     *
     * accumulator は finally で必ず clear する (プロセス内の後続テストへの二次被害を防ぐ)。
     */
    public static function flushAndFailIfStray(): void
    {
        try {
            if (self::$strayRequests === []) {
                return;
            }
            throw new RuntimeException(
                'Stray outbound HTTP request detected during test execution. '
                .'Did you forget to call Http::fake([...]) in the test body? '
                .'(test lanes deny outbound HTTP by default; only loopback is allowed)'
                .PHP_EOL.self::summarize(self::$strayRequests)
            );
        } finally {
            self::$strayRequests = [];
        }
    }

    /**
     * accumulator を空に戻す。afterEach の finally から呼び、flushAndFailIfStray() が
     * throw した場合でも次テストへ残留を漏らさないことを保証する。
     */
    public static function reset(): void
    {
        self::$strayRequests = [];
    }

    /**
     * self-test 用 drain。意図的に stray を発生させるテストで、global afterEach に
     * 到達する前に accumulator を取り出して clear する。
     *
     * @return list<array{method: string, url: string}>
     */
    public static function drainForAssertion(): array
    {
        $drained = self::$strayRequests;
        self::$strayRequests = [];

        return $drained;
    }

    /**
     * URL が ALLOWED_URL_PATTERNS のいずれかに glob 一致するか (純関数)。
     * これは `PendingRequest::isAllowedRequestUrl()` が行う判定と**同じ意味論**であり、
     * 「framework が loopback として通す URL 集合」を再現するために持つ。
     */
    public static function matchesAllowedPattern(string $url): bool
    {
        foreach (self::ALLOWED_URL_PATTERNS as $pattern) {
            if (Str::is($pattern, $url)) {
                return true;
            }
        }

        return false;
    }

    /** ホスト名 (PSR-7 の `Uri::getHost()` 形式) が loopback リテラルか (純関数)。 */
    public static function isLoopbackHost(string $host): bool
    {
        return in_array(strtolower($host), self::LOOPBACK_HOSTS, true);
    }

    /**
     * 「許可パターンには一致するが、**パースした実ホストは loopback ではない**」URL か (純関数)。
     *
     * userinfo 詐称 (`http://127.0.0.1:80@api.frankfurter.dev/`) がこの形になる。
     * glob だけの判定では framework が stray 扱いせず**実送信まで進んでしまう**ため、
     * guard 側の第 2 層でこれを stray に落とす (Codex impl-review Round 1 の Critical)。
     *
     * ★この形の URL を `Http::fake()` で intercept したいケースは想定しない
     *   (loopback を騙る外部ホストを意図的に叩くテストは存在しない)。仮に必要になったら
     *   ここで fail するので、設計判断として必ず表面化する = fail-closed で正しい。
     */
    public static function isSmuggledLoopbackUrl(string $url): bool
    {
        if (! self::matchesAllowedPattern($url)) {
            return false;
        }

        try {
            $host = (new Uri($url))->getHost();
        } catch (InvalidArgumentException) {
            // パースできない URL が許可パターンに一致した = 想定外。fail-closed で拒否する。
            return true;
        }

        return ! self::isLoopbackHost($host);
    }

    /**
     * Guzzle global middleware 本体。handler stack の最外側に置かれ、
     * 最内側の stub handler が同期 throw する StrayRequestException を観測する。
     *
     * ★`->otherwise()` (promise rejection) ではなく **try/catch** で捕える。
     *   stub handler は promise を reject するのではなく同期 throw するため
     *   (PendingRequest::buildStubHandler)。async / pool 経路でも、Guzzle Client が
     *   rejection 化するのは本 middleware より**外側**なので try/catch で捕まる。
     *
     * @param  callable(RequestInterface, array<string, mixed>): mixed  $handler
     */
    public function __invoke(callable $handler): Closure
    {
        /**
         * @param  array<string, mixed>  $options  Guzzle の転送オプション
         */
        return function (RequestInterface $request, array $options) use ($handler): mixed {
            // 第 2 層: 許可パターンを userinfo 詐称で潜り抜ける URL を stray に落とす。
            // framework の isAllowedRequestUrl() は URL 文字列の glob 照合しかしないため、
            // ここで止めないと**実際に外部へ送信される**。
            $url = (string) $request->getUri();
            if (self::isSmuggledLoopbackUrl($url)) {
                self::$strayRequests[] = [
                    'method' => $request->getMethod(),
                    'url' => $url,
                ];

                throw new StrayRequestException($url);
            }

            try {
                return $handler($request, $options);
            } catch (StrayRequestException $e) {
                self::$strayRequests[] = [
                    'method' => $request->getMethod(),
                    'url' => (string) $request->getUri(),
                ];

                // フレームワークの既定挙動を変えない (記録するだけで握り潰さない)
                throw $e;
            }
        };
    }

    /**
     * @param  list<array{method: string, url: string}>  $requests
     */
    private static function summarize(array $requests): string
    {
        $lines = [];
        foreach ($requests as $i => $request) {
            $lines[] = sprintf('  [%d] %s %s', $i + 1, $request['method'], $request['url']);
        }

        return implode(PHP_EOL, $lines);
    }
}
