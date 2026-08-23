<?php

declare(strict_types=1);

namespace App\Services\EnterpriseSso;

use App\DataTransferObjects\EnterpriseSso\OidcJsonWebKeySet;
use App\DataTransferObjects\EnterpriseSso\OidcProviderMetadata;
use App\Enums\EnterpriseSso\RejectionReason;
use App\Exceptions\EnterpriseSso\EnterpriseSsoAttemptRejectedException;
use App\ValueObjects\EnterpriseSso\OidcIssuerUrl;
use Closure;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Kent013\SsrfPin\Dtos\Deadline;
use Kent013\SsrfPin\Dtos\PinnedFailure;
use Kent013\SsrfPin\Dtos\PinnedRequest;
use Kent013\SsrfPin\PinnedHttpClient;
use Throwable;

/**
 * 接続先情報 (OIDC Discovery) と公開鍵 (JWKS) の取得。
 *
 * ★**外向きは `PinnedHttpClient` だけである**。`Http` ファサード・`HttpFactory` を
 *   本サービス (および `App\Services\EnterpriseSso` 配下) へ注入しない。
 *   検査 → 名前解決 → 接続が同じ経路を通るので、検査と接続の間の TOCTOU
 *   (DNS rebinding) を自分から作り直さない。
 *   境界の正本は `config/ssrf-pin.php` であり、本機能はそれを変更しない
 *   (AGENTS.md セキュリティ不変条件 8)。
 *
 * ## 防御
 *
 *  1. **pin 済み経路** — 検査・名前解決・接続が同じ経路
 *  2. **リダイレクトを追従しない** (`followRedirects: false`) — 転送先が未検査のまま
 *     取得されるのを防ぐ。**2xx 以外は一様に拒否する** (3xx を成功として扱わない)
 *  3. **issuer の完全一致** — 文書の issuer が登録済み issuer と一致すること
 *  4. **endpoint は https の絶対 URL・userinfo なし・fragment なし** —
 *     ★同一 origin は**要求しない** (OIDC 標準の要件ではなく、実在の IdP を拒否する)。
 *     ★**query は禁じない** (禁じる標準上の根拠が無い)
 *  5. **応答サイズ上限** — 期待と違う応答を DTO に固定しない。
 *     ★`PinnedRequest` は要求ごとの上限を受け取らない (^0.4) ので、
 *     transport の上限 (`config/ssrf-pin.php`) の**内側**でアプリが測って拒否する
 *
 * ## キャッシュ (セキュリティ不変条件 11)
 *
 * 入れるのは**素の配列とスカラーだけ**である。読み戻しは DTO へ明示的に組み立て直して
 * 検査し、**破損 / 空配列 / 未知の値**のいずれでも `forget` して miss 扱いにする。
 */
final readonly class OidcDiscoveryService
{
    private const string METADATA_CACHE_PREFIX = 'enterprise-sso:metadata:';

    private const string JWKS_CACHE_PREFIX = 'enterprise-sso:jwks:';

    private const string JWKS_REFETCHED_AT_CACHE_PREFIX = 'enterprise-sso:jwks-refetched-at:';

    /** 再取得の同時実行を抑える接続単位のロック。 */
    private const string JWKS_REFETCH_LOCK_PREFIX = 'enterprise-sso:jwks-refetch:';

    /**
     * ロックの寿命 (秒)。
     *
     * ★外向き取得の時間予算 (接続 3 + 要求 5) より長くする — 取得中にロックが失効すると
     *   2 人目が取り始めてしまい、抑止そのものが成立しない。
     */
    public const int JWKS_REFETCH_LOCK_SECONDS = 15;

    public function __construct(
        private PinnedHttpClient $pinned,
        private CacheRepository $cache,
    ) {}

    /**
     * 接続先情報の取得と検証。
     *
     * @throws EnterpriseSsoAttemptRejectedException
     */
    public function fetchMetadata(OidcIssuerUrl $issuer): OidcProviderMetadata
    {
        $cached = $this->cachedMetadata($issuer);
        if ($cached !== null) {
            return $cached;   // アーリーリターン
        }

        $body = $this->fetchPinned(
            $issuer->wellKnownUrl(),
            Config::integer('enterprise-sso.discovery.max_body_bytes'),
            RejectionReason::DiscoveryFetchFailed,
            RejectionReason::DiscoveryBodyTooLarge,
        );

        $metadata = OidcProviderMetadata::fromResponseBody($body, expectedIssuer: $issuer);

        $this->cache->put(
            self::METADATA_CACHE_PREFIX.$issuer->cacheDigest(),
            $metadata->toCachePayload(),
            Config::integer('enterprise-sso.discovery.cache_ttl_seconds'),
        );

        return $metadata;
    }

    /**
     * 公開鍵集合の取得。
     *
     * @throws EnterpriseSsoAttemptRejectedException
     */
    public function fetchJwks(OidcProviderMetadata $metadata): OidcJsonWebKeySet
    {
        $cached = $this->cachedJwks($metadata);
        if ($cached !== null) {
            return $cached;   // アーリーリターン
        }

        return $this->fetchAndCacheJwks($metadata);
    }

    /**
     * 未知の `kid` での鍵の再取得。
     *
     *  - **接続 id 単位のロック**を取り、同時要求でも再取得が 1 回になる
     *  - 最終再取得時刻を**スカラー**でキャッシュに持ち、最小間隔の内側では再取得しない
     *    (未知 kid を連打されたときの増幅を防ぐ)
     *  - **ロック基盤の障害時はその試行を拒否する** (再取得を無制限に許さない)
     *  - 再取得は **1 回だけ**である (呼び出し側が再帰しない)
     *
     * @throws EnterpriseSsoAttemptRejectedException 最小間隔の内側 (= 再取得しない)
     */
    public function refetchJwks(OidcProviderMetadata $metadata, int $connectionId): OidcJsonWebKeySet
    {
        $minimumInterval = Config::integer('enterprise-sso.discovery.jwks_refetch_min_interval_seconds');

        // ★**接続 id 単位のロックを取る**。取らずに「読んで → 判定して → 書く」だけだと、
        //   同じ接続へ未知 kid の callback が同時に来たとき**両方が古い時刻を読んで両方が再取得する**
        //   (署名不正のトークンを並行投入するだけで IdP への外向き取得を増幅できる)。
        return $this->underRefetchLock(
            Cache::lock(self::JWKS_REFETCH_LOCK_PREFIX.$connectionId, self::JWKS_REFETCH_LOCK_SECONDS),
            function () use ($metadata, $connectionId, $minimumInterval): OidcJsonWebKeySet {
                $stampKey = self::JWKS_REFETCHED_AT_CACHE_PREFIX.$connectionId;

                // ★最小間隔の判定は**ロックの中で**行う (外で読むと判定そのものが競合する)。
                /** @var mixed $lastRefetchedAt */
                $lastRefetchedAt = $this->cache->get($stampKey);
                if (is_int($lastRefetchedAt) && (time() - $lastRefetchedAt) < $minimumInterval) {
                    throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::JwksRefetchUnavailable);
                }

                $this->cache->put($stampKey, time(), $minimumInterval);
                $this->cache->forget(self::JWKS_CACHE_PREFIX.$metadata->issuer->cacheDigest());

                return $this->fetchAndCacheJwks($metadata);
            },
        );
    }

    /**
     * ロックを取れたときだけ `$callback` を走らせる。
     *
     * ★**待たない**。待つと未知 kid の連打が worker を占有する。
     * ★**ロック基盤の障害はその試行を拒否する** (再取得を無制限に許さない = fail-closed)。
     * ★受け手を**型宣言された引数**にしてあるのは、G2 の走査器が
     *   「受け手の型が解決できない保護対象語彙の呼び出し」を落とすためである
     *   (局所変数のままだと未解決として赤くなる = 見逃さない側の設計である)。
     *
     * @param  Closure(): OidcJsonWebKeySet  $callback
     *
     * @throws EnterpriseSsoAttemptRejectedException
     */
    private function underRefetchLock(Lock $lock, Closure $callback): OidcJsonWebKeySet
    {
        try {
            $acquired = $lock->get();
        } catch (Throwable) {
            throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::JwksRefetchUnavailable);
        }

        if ($acquired !== true) {
            throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::JwksRefetchUnavailable);
        }

        try {
            return $callback();
        } finally {
            // ★解放の失敗で**成功した取得結果を捨てない** (解放は後片付けである)。
            //   取りこぼしてもロックは寿命 (JWKS_REFETCH_LOCK_SECONDS) で自然に切れるので、
            //   「二度と再取得できない」形にはならない。
            try {
                $lock->release();
            } catch (Throwable) {
                // ★後片付けの失敗は本体の結果に影響させない。ただし**無言にはしない** —
                //   完全に握り潰すとキャッシュ基盤の障害の兆候が見えなくなる。
                //   載せるのは**固定の文言だけ**である (鍵も URL も接続 id も載せない)。
                Log::warning('enterprise-sso jwks refetch lock release failed');
            }
        }
    }

    private function fetchAndCacheJwks(OidcProviderMetadata $metadata): OidcJsonWebKeySet
    {
        $body = $this->fetchPinned(
            $metadata->jwksUri,
            Config::integer('enterprise-sso.discovery.max_body_bytes'),
            RejectionReason::JwksFetchFailed,
            RejectionReason::JwksMalformed,
        );

        $jwks = OidcJsonWebKeySet::fromResponseBody($body);

        $this->cache->put(
            self::JWKS_CACHE_PREFIX.$metadata->issuer->cacheDigest(),
            $jwks->toCachePayload(),
            Config::integer('enterprise-sso.discovery.cache_ttl_seconds'),
        );

        return $jwks;
    }

    private function cachedMetadata(OidcIssuerUrl $issuer): ?OidcProviderMetadata
    {
        $key = self::METADATA_CACHE_PREFIX.$issuer->cacheDigest();

        /** @var mixed $payload */
        $payload = $this->cache->get($key);
        if ($payload === null) {
            return null;
        }

        if (! is_array($payload)) {
            $this->cache->forget($key);

            return null;
        }

        $metadata = OidcProviderMetadata::fromCachePayload($payload);
        if ($metadata === null || ! hash_equals($issuer->value, $metadata->issuer->value)) {
            $this->cache->forget($key);

            return null;
        }

        return $metadata;
    }

    private function cachedJwks(OidcProviderMetadata $metadata): ?OidcJsonWebKeySet
    {
        $key = self::JWKS_CACHE_PREFIX.$metadata->issuer->cacheDigest();

        /** @var mixed $payload */
        $payload = $this->cache->get($key);
        if ($payload === null) {
            return null;
        }

        if (! is_array($payload)) {
            $this->cache->forget($key);

            return null;
        }

        $jwks = OidcJsonWebKeySet::fromCachePayload($payload);
        if ($jwks === null) {
            $this->cache->forget($key);

            return null;
        }

        return $jwks;
    }

    /**
     * pin 済み経路での GET。**2xx かつ上限内の本文だけ**を返す。
     *
     * @throws EnterpriseSsoAttemptRejectedException
     */
    private function fetchPinned(
        string $url,
        int $maxBodyBytes,
        RejectionReason $failureReason,
        RejectionReason $tooLargeReason,
    ): string {
        $request = new PinnedRequest(
            method: 'GET',
            url: $url,
            headers: ['Accept' => 'application/json'],
            connectTimeout: (float) Config::integer('enterprise-sso.discovery.connect_timeout_seconds'),
        );

        // ★fetch() は PinnedResponse|PinnedFailure を**値で**返す (catch では捕まらない)。
        $result = $this->pinned->fetch(
            $request,
            Deadline::afterSeconds((float) Config::integer('enterprise-sso.discovery.request_timeout_seconds')),
            followRedirects: false,
        );

        if ($result instanceof PinnedFailure) {
            throw EnterpriseSsoAttemptRejectedException::of($failureReason);
        }

        // ★3xx を成功として扱わない (追従していないので本文は転送元のもの)。
        if ($result->status < 200 || $result->status >= 300) {
            throw EnterpriseSsoAttemptRejectedException::of($failureReason);
        }

        if (strlen($result->body) > $maxBodyBytes) {
            throw EnterpriseSsoAttemptRejectedException::of($tooLargeReason);
        }

        return $result->body;
    }
}
