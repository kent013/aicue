<?php

declare(strict_types=1);

namespace Tests\Support\EnterpriseSso;

use App\Enums\EnterpriseSso\FingerprintPurpose;
use App\Support\EnterpriseSso\AttemptFingerprint;
use Closure;
use Firebase\JWT\JWT;
use Kent013\SsrfPin\Contracts\DnsResolverInterface;
use Kent013\SsrfPin\Contracts\PinnedCurlTransportInterface;
use Kent013\SsrfPin\Dtos\CurlResolveEntry;
use Kent013\SsrfPin\Dtos\PinnedFailure;
use Kent013\SsrfPin\Dtos\PinnedRequest;
use Kent013\SsrfPin\Dtos\PinnedResponse;
use Kent013\SsrfPin\Testing\FakeDnsResolver;
use Kent013\SsrfPin\Testing\FakePinnedTransport;
use Kent013\SsrfPin\UrlSafetyInspector;
use RuntimeException;
use Webmozart\Assert\Assert;

/**
 * 試験用の偽 IdP。
 *
 * ★**アプリ側 (`app/`) に偽の実装を置かない**。差し替えるのは
 *   ssrf-pin が出荷している transport の seam ({@see FakePinnedTransport}) だけである。
 *   したがって:
 *     - 本番の container 束縛を 1 つも変えない (`ExternalFakeDeclaration` の母集団に入らない)
 *     - 未認証で任意の subject を名乗れる route を**作らない**
 *     - それでも**実装が `PinnedHttpClient` を通ることの検査**になる —
 *       通らなければ本 fake には 1 件も要求が届かないからである
 *
 * ★discovery / JWKS / token の 3 経路を URL で振り分ける。
 * ★`beforeRespond` は「**待つ**ものではなく**やって戻る**もの」である。
 *   同一プロセスで callback に待たせると呼び出し元が戻らずデッドロックするので、
 *   sleep も ready / go も締切も持たせない (順序は呼び出しの構造が保証する)。
 */
final class FakeIdentityProvider
{
    /** 署名鍵の id。 */
    public const string KEY_ID = 'fake-key-1';

    /**
     * 偽 IdP の名前解決結果 (**公開到達可能と分類される IP**)。
     *
     * ★private レンジを返すと `UrlSafetyInspector` (本物) が拒否して transport まで届かない。
     *   ここで公開 IP を返すことで、SSRF 判定を通ったうえで偽の transport が応答する形になる。
     */
    public const string PUBLIC_IP = '93.184.216.34';

    /** @var list<PinnedRequest> 受領した要求 (到達の検証に使う) */
    public array $requests = [];

    /** @var array<string, mixed> discovery 文書の上書き */
    private array $metadataOverrides = [];

    /** @var list<array<string, string>> JWKS が返す鍵 (既定は自分の公開鍵 1 本) */
    private ?array $keys = null;

    /** @var Closure(string): void|null 応答を返す直前に呼ぶ割り込み点 */
    private ?Closure $beforeRespond = null;

    /** @var array<string, mixed> ID トークンの claim の上書き */
    private array $claimOverrides = [];

    /** @var list<string> 上書きで削る claim */
    private array $removedClaims = [];

    private string $idTokenAlgorithm = 'RS256';

    private ?string $idTokenOverride = null;

    private ?PinnedFailure $failureOverride = null;

    private int $statusOverride = 200;

    private ?string $bodyOverride = null;

    private static ?string $privateKey = null;

    /** @var array<string, string>|null */
    private static ?array $publicJwk = null;

    public function __construct(public readonly string $issuer = 'https://idp.example.test') {}

    /**
     * transport と名前解決を差し替える。
     *
     * ★`UrlSafetyInspector` そのものは偽物にしない (差し替え禁止)。
     *   差し替えるのは**その依存**である `DnsResolverInterface` だけなので、
     *   **SSRF の判定層は本物が動く** (pin 済み経路を通ることの検査になる)。
     */
    public function install(): self
    {
        app()->instance(PinnedCurlTransportInterface::class, new FakePinnedTransport(
            fn (PinnedRequest $request, CurlResolveEntry $entry): PinnedResponse|PinnedFailure => $this->respond($request),
        ));

        $host = parse_url($this->issuer, PHP_URL_HOST);
        Assert::stringNotEmpty($host);

        app()->bind(
            DnsResolverInterface::class,
            fn (): DnsResolverInterface => new FakeDnsResolver([$host => [self::PUBLIC_IP]]),
        );
        app()->forgetInstance(UrlSafetyInspector::class);

        return $this;
    }

    /** @param  array<string, mixed>  $overrides */
    public function withMetadata(array $overrides): self
    {
        $this->metadataOverrides = [...$this->metadataOverrides, ...$overrides];

        return $this;
    }

    /** @param  list<array<string, string>>  $keys */
    public function withKeys(array $keys): self
    {
        $this->keys = $keys;

        return $this;
    }

    /** @param  array<string, mixed>  $claims */
    public function withClaims(array $claims): self
    {
        $this->claimOverrides = [...$this->claimOverrides, ...$claims];

        return $this;
    }

    /** @param  list<string>  $claims */
    public function withoutClaims(array $claims): self
    {
        $this->removedClaims = [...$this->removedClaims, ...$claims];

        return $this;
    }

    public function withIdTokenAlgorithm(string $algorithm): self
    {
        $this->idTokenAlgorithm = $algorithm;

        return $this;
    }

    public function withRawIdToken(string $idToken): self
    {
        $this->idTokenOverride = $idToken;

        return $this;
    }

    public function withStatus(int $status): self
    {
        $this->statusOverride = $status;

        return $this;
    }

    public function withBody(string $body): self
    {
        $this->bodyOverride = $body;

        return $this;
    }

    public function withTransportFailure(PinnedFailure $failure): self
    {
        $this->failureOverride = $failure;

        return $this;
    }

    /**
     * 応答を返す**直前**に呼ぶ割り込み点 (D1 の `verify` の三段構成の検査に使う)。
     *
     * ★**1 回だけ**発火する (1 回の `verify` は discovery と JWKS の 2 経路を取りに行くため)。
     *
     * @param  Closure(string): void  $callback  引数は要求 URL
     */
    public function beforeRespond(Closure $callback): self
    {
        $this->beforeRespond = $callback;

        return $this;
    }

    /** discovery 文書 (上書きを反映したもの)。 */
    public function metadata(): array
    {
        return [
            'issuer' => $this->issuer,
            'authorization_endpoint' => $this->issuer.'/authorize',
            'token_endpoint' => $this->issuer.'/token',
            'jwks_uri' => $this->issuer.'/jwks',
            'token_endpoint_auth_methods_supported' => ['client_secret_basic', 'client_secret_post'],
            'id_token_signing_alg_values_supported' => ['RS256'],
            ...$this->metadataOverrides,
        ];
    }

    /** 署名済みの ID トークン。 */
    public function idToken(string $clientId, string $nonce, string $subject = 'sub-abc'): string
    {
        $claims = [
            'iss' => $this->issuer,
            'sub' => $subject,
            'aud' => $clientId,
            'exp' => time() + 300,
            'iat' => time(),
            'nonce' => $nonce,
            'email' => 'worker@corp.example',
            'name' => '現場 太郎',
            ...$this->claimOverrides,
        ];

        foreach ($this->removedClaims as $claim) {
            unset($claims[$claim]);
        }

        return JWT::encode($claims, self::privateKey(), $this->idTokenAlgorithm, self::KEY_ID);
    }

    /** 直近の token 交換の要求 (資格情報の載り方の検証に使う)。 */
    public function lastTokenRequest(): ?PinnedRequest
    {
        foreach (array_reverse($this->requests) as $request) {
            if (str_contains($request->url, '/token')) {
                return $request;
            }
        }

        return null;
    }

    /** JWKS が返す鍵 (既定は自分の公開鍵 1 本)。 */
    public function jwks(): array
    {
        return ['keys' => $this->keys ?? [self::publicJwk()]];
    }

    /** 本 fake の公開鍵 (JWK 形式)。 */
    public static function publicJwk(): array
    {
        self::ensureKeyPair();
        Assert::isArray(self::$publicJwk);

        return self::$publicJwk;
    }

    private function respond(PinnedRequest $request): PinnedResponse|PinnedFailure
    {
        $this->requests[] = $request;

        // ★「やって戻る」割り込み点。待たない (待つとデッドロックする)。
        // ★**1 回だけ**発火する。1 回の verify は discovery と JWKS の 2 経路を取りに行くので、
        //   毎回発火すると「割り込みは 1 回」という筋書きにならない (2 回目で前提が崩れる)。
        if ($this->beforeRespond !== null) {
            $callback = $this->beforeRespond;
            $this->beforeRespond = null;
            $callback($request->url);
        }

        if ($this->failureOverride !== null) {
            return $this->failureOverride;
        }

        $body = $this->bodyOverride ?? $this->bodyFor($request);

        return new PinnedResponse($this->statusOverride, [], $request->url, [$request->url], $body);
    }

    private function bodyFor(PinnedRequest $request): string
    {
        if (str_contains($request->url, '.well-known/openid-configuration')) {
            return json_encode($this->metadata(), JSON_THROW_ON_ERROR);
        }

        if (str_contains($request->url, '/jwks')) {
            return json_encode($this->jwks(), JSON_THROW_ON_ERROR);
        }

        if (str_contains($request->url, '/token')) {
            return json_encode([
                'token_type' => 'Bearer',
                'id_token' => $this->idTokenOverride ?? $this->idTokenForRequest($request),
            ], JSON_THROW_ON_ERROR);
        }

        throw new RuntimeException('偽 IdP が知らない URL を受け取りました: '.$request->url);
    }

    /**
     * 要求 body の `client_id` と、テストが記録した nonce から ID トークンを組み立てる。
     *
     * ★nonce は**要求からは取れない** (認可要求にしか載らない) ので、
     *   テストが `withClaims(['nonce' => …])` で渡した値を使う。
     */
    private function idTokenForRequest(PinnedRequest $request): string
    {
        parse_str($request->body ?? '', $form);
        $clientId = is_string($form['client_id'] ?? null) ? $form['client_id'] : 'client-unknown';

        $nonce = $this->claimOverrides['nonce'] ?? null;
        Assert::string($nonce, 'テストは withClaims([\'nonce\' => …]) で nonce を渡すこと');

        return $this->idToken($clientId, $nonce);
    }

    private static function privateKey(): string
    {
        self::ensureKeyPair();
        Assert::string(self::$privateKey);

        return self::$privateKey;
    }

    private static function ensureKeyPair(): void
    {
        if (self::$privateKey !== null) {
            return;
        }

        $resource = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        Assert::notFalse($resource, 'RSA 鍵の生成に失敗した');

        openssl_pkey_export($resource, $privateKey);
        Assert::string($privateKey);
        self::$privateKey = $privateKey;

        $details = openssl_pkey_get_details($resource);
        Assert::isArray($details);
        Assert::isArray($details['rsa'] ?? null);

        self::$publicJwk = [
            'kty' => 'RSA',
            'kid' => self::KEY_ID,
            'alg' => 'RS256',
            'use' => 'sig',
            'n' => self::base64Url($details['rsa']['n']),
            'e' => self::base64Url($details['rsa']['e']),
        ];
    }

    private static function base64Url(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }

    /** 試行が保持する nonce の指紋 (テストが nonce を組み立てるための補助)。 */
    public static function nonceFingerprint(string $nonce): string
    {
        return AttemptFingerprint::of(FingerprintPurpose::Nonce, $nonce);
    }
}
