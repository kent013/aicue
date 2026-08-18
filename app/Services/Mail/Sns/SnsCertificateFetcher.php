<?php

declare(strict_types=1);

namespace App\Services\Mail\Sns;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Kent013\SsrfPin\Enums\SsrfDenyReason;
use Kent013\SsrfPin\UrlSafetyInspector;
use Throwable;

/**
 * SNS 署名検証用の証明書 (PEM) の取得口。
 * **SNS 署名検証まわりで外部 HTTP を行うのはこのクラスだけ**である
 * (`tests/Architecture/SnsCertificateFetchContractTest.php` が走査根の範囲で固定する)。
 *
 * 責務を 1 クラスへ閉じる理由: 「無認証のリクエストが外部取得を誘発する」経路であり、
 * 防御が散ると必ずどれかが抜ける。ここが持つ防御は 7 点:
 *
 *  1. **取得先の限定** — 引数の型 `SnsCertificateUrl` が SNS 証明書 URL の厳格な書式を
 *     保証する (呼び出し側の作法ではなく**型**で担保する)
 *  2. **SSRF 検査** — `UrlSafetyInspector::inspect()` (セキュリティ不変条件 8)。
 *     `PinnedHttpClient` は本文を返さないので証明書取得には使えず、inspect → fetch の形にする。
 *     **ロックを取る前に行う** — DNS 解決には明示的な時間上限が無くロック寿命の予算に
 *     入れられないため、そして SSRF で拒否される要求にロックを触らせないためである
 *  3. **redirect 禁止** (`withoutRedirecting`) + **2xx 以外を受理しない**
 *  4. **時間予算** — 接続とリクエスト全体を短く取る
 *  5. **応答サイズ上限** — PEM は数 KB。超えた応答は**検証もキャッシュもしない**
 *  6. **PEM 確認** — `openssl_x509_read()` が通ったものだけを扱う
 *  7. **キャッシュと同時取得の抑止** — 下記
 *
 * ## 同時取得の抑止 (ロックの契約)
 *
 * ロックキーは `CERT_FETCH_LOCK_KEY` **1 本だけ**で、`CERT_FETCH_PERMITS = 1` と
 * 1 対 1 に対応する。2 以上へ増やすならロックキーを permit 数へ分割する実装が同時に要る
 * (定数だけ書き換えても実効挙動は変わらない = 検査が偽の安心を与える) ため、
 * 契約テストが `=== 1` と「`Cache::lock()` の site がちょうど 1 つ」を要求する。
 *
 * **URL ごとにロックキーを分けない**。厳格な書式検証を通る URL の末尾は攻撃者が
 * 自由に変えられるため、分けると「存在しない証明書名を並べるだけで同時取得数を増やせる」。
 *
 * **取れなければ待たずに一時障害 (503) へ倒す**。待つと待ち時間ぶん worker を占有し、
 * このクラスが作ろうとしている上界の議論が成立しなくなる。503 は SNS の再送対象なので、
 * 即時の恒久ドロップにはならない (ただし再送期間を超えて競合が続けば配送断念はありうる)。
 *
 * 時間の大小関係 (`config/services.php` の `services.sns_certificate`):
 *
 *   待ち上限 (0) < 接続 (2) <= リクエスト全体 (5)、リクエスト全体 (5) + 後処理余裕 (2) <= 寿命 (8)
 *
 * 右の不等号は「取得中にロックが失効して 2 人目が取り始めない」ためである。
 * ★**後処理余裕は保証値ではなく見積である** — キャッシュ再確認の I/O と PEM 解析に
 *   強制上限は無い。したがって permit 1 は「1 要求のロック保持が寿命を超えない限り」の
 *   条件付きの性質である。
 *
 * ## キャッシュの規律
 *
 * - キーは `CACHE_PREFIX` + URL の sha256 (**キーに URL の平文を残さない**)
 * - 載せるのは**署名検証が通った PEM だけ**である。昇格は `rememberVerified()` で、
 *   呼ぶのは `AwsSnsSignatureVerifier` が `validate()` を通したあとの 1 箇所だけである
 *   (この唯一性は契約テストが `app/` 全体で exact-fit に固定する)。
 *   未検証の応答を載せると、壊れた証明書を寿命のあいだ配り続けて正当な通知を
 *   403 にし続ける = 自作の fail-closed になる
 * - **キャッシュの読み書きで署名検証を失敗させない**。読みの失敗は miss 扱い、
 *   書きの失敗はログのみで続行する。
 *   ★ただしこれは「読みだけが失敗し、ロック基盤は生きている」場合の話である。
 *     同じ store が読み書きとロックの両方を担うので、**store ごと落ちればロック取得も
 *     失敗して 503 になる**
 * - 読み戻しは「文字列 + 空でない + PEM として読める」を検査し、失敗したら `forget` して
 *   miss 扱いにする (セキュリティ不変条件 11)
 *
 * ## 例外の写像 (出所で境界を分ける)
 *
 * | 出所 | 意味 | 扱い |
 * |---|---|---|
 * | `Cache::lock()` の例外 | ロック非対応 store 等の**設定・実装の誤り** | **fail-fast** (握り潰さない) |
 * | `Lock::get()` の `Throwable` | ロック基盤の一時障害 | **503** (排他できない状態で取りに行かない) |
 * | 取得できなかった (競合) | 正常な競合 | **503** |
 * | `ConnectionException` | 接続 / DNS / TLS / timeout | **503** |
 * | 2xx 以外の応答 (3xx / 4xx / 5xx) | 取得先が期待と違う | **503** |
 * | それ以外の `Throwable` (TypeError 等) | **プログラム不具合** | **写像せず伝播** (503 で隠さない) |
 * | SSRF 判定の DNS 解決失敗 | 一時障害 | **503** |
 * | SSRF 判定のその他の拒否 / サイズ超過 / PEM 不正 | 恒久 | **403** |
 * | cache の `get` / `put` / `forget` / `release` の `Throwable` | 最適化の障害 | **best-effort** |
 *
 * ## 保証しないもの (誇張しない)
 *
 * - **DNS rebinding は解消しない**。検査時と接続時で名前解決が変わる TOCTOU は残り、
 *   private IP への TCP 接続と TLS ClientHello そのものは発生する。HTTP 層での取得を
 *   制限するのは「通常の CA 検証が有効であること」を前提とした TLS であり、
 *   取得先の host が型で `sns.<region>.amazonaws.com` に固定されていることに依存する
 * - **DNS 解決そのものに時間の上限は無く、permit 1 の対象外である**。ロックの外で行うので
 *   permit 1 は壊さないが、**無認証の入力から作れる別々の host** (`sns.a1.amazonaws.com`,
 *   `sns.a2.amazonaws.com`, …) の解決は**並列に走りうる**。これは受容した判断であり、
 *   理由は 3 つ:
 *   (1) **t0 からの後退ではなく前進**である — t0 は同じ入力に対して書式検証だけで
 *       **外向き HTTP 取得を無制限に並列で行っていた**。t1 では同じ入力が届いても
 *       行うのは名前解決までで、HTTP 取得は permit 1 に直列化される
 *   (2) 受け口の `throttle:webhook-ses` (300/分・IP 単位) が単一 IP の物量を頭打ちにする
 *   (3) **補助的な事情として**、存在しない host の解決は NXDOMAIN で終わり否定応答も cache されうる
 *       (別名どうしで共有されるとは限らないので、これは主たる根拠にはしない)
 *   **再検討条件** (いずれも既存の観測値で判断する):
 *   受け口 `webhooks.ses` の応答時間の p95 / p99 が悪化した (アクセスログ) /
 *   `mail.sns.verification_unavailable` の件数が増えた (アプリログ) /
 *   受け口の 429 応答が増えた (アクセスログ)。そのときに採る緩和策は
 *   「証明書 host の region を TopicArn の allowlist へ束縛する」
 *   「名前解決用の独立した同時実行制限を設ける」「解決器へ実効 timeout を入れる」である
 * - **応答サイズ上限も時間予算もメモリ使用量の上界ではない**。Laravel の HTTP client は
 *   既定で非 stream なので本文は先に全部メモリへ載り、長さを測る位置を変えても上界にならない。
 *   時間の上限も、帯域が大きければ受信バイト数を制限しない。上限の役割は
 *   「**期待と違う応答を検証・キャッシュに固定しない**」ことだけである
 * - **permit 1 は条件付き**である (上記のとおり後処理に強制上限が無い)。
 *   worker 停止やキャッシュ基盤の長時間停止で保持が伸びれば取得は重なりうる。
 *   所有者つきの解放で誤解放は防ぐが、重なり自体は防がない
 * - **キャッシュ store が共有されない構成 (file 等) ではホストごとに 1 回取りに行く**
 *   (既定 `database` は共有される)
 */
final readonly class SnsCertificateFetcher
{
    /** キャッシュキーの接頭辞 (URL は sha256 にする = キーに平文を残さない) */
    public const string CACHE_PREFIX = 'sns:cert:';

    /**
     * 同時取得数。**単一ロックキーと 1 対 1 に対応する** (上の docblock 参照)。
     * 2 以上へ増やすならロックキーの分割が同時に要る。
     */
    public const int CERT_FETCH_PERMITS = 1;

    /** 取得ロックのキー (1 本だけ持つ) */
    private const string CERT_FETCH_LOCK_KEY = 'sns:cert:fetch';

    public function __construct(
        private HttpFactory $http,
        private UrlSafetyInspector $inspector,
    ) {}

    /**
     * キャッシュ済みの PEM。無いとき / キャッシュ障害のとき / 読み戻せない値だったときは null。
     */
    public function cached(SnsCertificateUrl $url): ?string
    {
        $key = self::cacheKey($url);

        try {
            /** @var mixed $value */
            $value = Cache::get($key);
        } catch (Throwable) {
            // キャッシュは最適化である。読みの障害で署名検証を止めない (miss 扱い)。
            Log::warning('mail.sns.cert_cache_read_failed');

            return null;
        }

        if ($value === null) {
            return null;
        }

        if (is_string($value) && $value !== '' && self::isReadablePem($value)) {
            return $value;
        }

        // 読み戻せない値が残っていたら消して miss 扱いにする (不変条件 11)。
        $this->forgetQuietly($key);

        return null;
    }

    /**
     * SSRF 検査 → 同時 1 本に直列化した取得。
     *
     * 手順: SSRF 検査 (ロックの外) → 非ブロッキングでロック →
     * **ロック保持中にキャッシュ再確認** → 取得 → finally で所有者つき解放。
     *
     * @throws SnsSignatureInvalidException SSRF 判定 / サイズ / PEM 不正 (恒久 = 403)
     * @throws SnsVerificationUnavailableException 競合 / ロック基盤障害 / 取得失敗 / DNS 解決失敗 (503)
     */
    public function fetchSerialized(SnsCertificateUrl $url): SnsCertificate
    {
        // ★SSRF 検査はロックの**外**で行う。(a) DNS 解決に時間の上限が無くロック寿命の
        //   予算へ入れられない、(b) 拒否される要求にロックを触らせない、の 2 つが理由である。
        $this->inspect($url);

        // ★ここで投げるのは「ロック非対応 store」等の設定・実装の誤りだけなので**捕まえない**
        //   (可用性の退避に飲み込ませない = fail-fast)。
        $lock = Cache::lock(
            self::CERT_FETCH_LOCK_KEY,
            Config::integer('services.sns_certificate.lock_ttl_seconds'),
        );

        try {
            $acquired = $lock->get();
        } catch (Throwable $e) {
            // ロック基盤の一時障害。排他できない状態では取りに行かない
            // (同時取得数の上界を黙って壊すより、再送に任せるほうが安全である)。
            throw new SnsVerificationUnavailableException('certificate lock unavailable', 0, $e);
        }

        if ($acquired !== true) {
            // 待たない (上の docblock 参照)。
            throw new SnsVerificationUnavailableException('certificate fetch is busy');
        }

        try {
            // ロックを取るまでの間に別の要求が埋めているかもしれない。
            $cached = $this->cached($url);
            if ($cached !== null) {
                return SnsCertificate::fromCache($cached);
            }

            return SnsCertificate::fetched($this->fetchRemote($url));
        } finally {
            // 取得しても hit で返しても**必ず**解放する (所有者つきの比較削除なので
            // 他所有者の鍵は消さない)。解放の失敗は飲む (finally で投げると元の例外を壊す)。
            $this->releaseQuietly($lock);
        }
    }

    /**
     * **署名検証が通った** PEM をキャッシュへ昇格させる (best-effort)。
     *
     * ★呼んでよいのは `AwsSnsSignatureVerifier` が `MessageValidator::validate()` を
     *   通したあとだけである。この唯一性は
     *   `tests/Architecture/SnsCertificateFetchContractTest.php` が
     *   `app/` 全体で exact-fit に固定する (名前も前提条件を言う形にしてある)。
     *
     * 保存に失敗しても署名検証は済んでいる。次回また取りに行くだけなので落とさない。
     */
    public function rememberVerified(SnsCertificateUrl $url, string $pem): void
    {
        try {
            Cache::put(
                self::cacheKey($url),
                $pem,
                Config::integer('services.sns_certificate.cache_ttl_seconds'),
            );
        } catch (Throwable) {
            Log::warning('mail.sns.cert_cache_write_failed');
        }
    }

    /**
     * SSRF 検査 (取得より前・ロックより前)。
     *
     * @throws SnsSignatureInvalidException 恒久の拒否 (403)
     * @throws SnsVerificationUnavailableException DNS 解決失敗 (503)
     */
    private function inspect(SnsCertificateUrl $url): void
    {
        $decision = $this->inspector->inspect($url->value);
        if ($decision->allowed) {
            return;
        }

        // DNS 解決失敗だけが一時障害である。書式検証を通った host が private IP へ
        // 解決される状態は DNS rebinding か split-horizon DNS であり、再送では直らない。
        if ($decision->reason === SsrfDenyReason::DnsResolutionFailed) {
            throw new SnsVerificationUnavailableException('certificate host is not resolvable');
        }

        throw new SnsSignatureInvalidException('certificate URL rejected by SSRF inspection');
    }

    /**
     * キャッシュに一切触らない実取得 (HTTP → 応答コード → サイズ → PEM 確認)。
     *
     * @throws SnsSignatureInvalidException
     * @throws SnsVerificationUnavailableException
     */
    private function fetchRemote(SnsCertificateUrl $url): string
    {
        try {
            $response = $this->http
                ->connectTimeout(Config::integer('services.sns_certificate.connect_timeout_seconds'))
                ->timeout(Config::integer('services.sns_certificate.request_timeout_seconds'))
                ->withoutRedirecting()
                ->get($url->value);
        } catch (ConnectionException $e) {
            // 接続 / DNS / TLS / timeout **だけ**を一時障害へ写像する。
            // TypeError や LogicException は写像しない (プログラム不具合を 503 で隠さない)。
            throw new SnsVerificationUnavailableException('certificate fetch failed', 0, $e);
        }

        // ★`->throw()` は使わない。4xx / 5xx しか例外化しないので、`withoutRedirecting()` と
        //   併用すると **3xx の本文が証明書として扱われうる**。2xx 以外は一様に拒否する。
        if (! $response->successful()) {
            throw new SnsVerificationUnavailableException('certificate response is not successful');
        }

        $body = $response->body();

        if (strlen($body) > Config::integer('services.sns_certificate.max_bytes')) {
            // 証明書としてあり得ない大きさ = 取得先が期待と違う。恒久扱いにする。
            throw new SnsSignatureInvalidException('certificate response is too large');
        }

        if (! self::isReadablePem($body)) {
            throw new SnsSignatureInvalidException('certificate response is not a valid PEM');
        }

        return $body;
    }

    /**
     * PEM として読めるか。
     *
     * `openssl_x509_read()` は失敗時に false を返しつつ warning も出す。warning は
     * Laravel のエラーハンドラが `ErrorException` へ昇格させるため、**戻り値と例外の両方**を
     * 「読めなかった」に畳む (エラー抑制演算子は使わない)。
     * 戻り値の `OpenSSLCertificate` は**ここから外へ出さない** (キャッシュ境界は常に string)。
     */
    private static function isReadablePem(string $pem): bool
    {
        try {
            return openssl_x509_read($pem) !== false;
        } catch (Throwable) {
            return false;
        }
    }

    private static function cacheKey(SnsCertificateUrl $url): string
    {
        return self::CACHE_PREFIX.hash('sha256', $url->value);
    }

    private function forgetQuietly(string $key): void
    {
        try {
            Cache::forget($key);
        } catch (Throwable) {
            Log::warning('mail.sns.cert_cache_forget_failed');
        }
    }

    private function releaseQuietly(Lock $lock): void
    {
        try {
            $lock->release();
        } catch (Throwable) {
            // 解放できなくても寿命の失効で回復する。**その間は後続が 503 になる**ことを
            // 観測できるようにするためのログである (平文は出さない)。
            Log::warning('mail.sns.cert_lock_release_failed');
        }
    }
}
