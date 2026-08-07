<?php

declare(strict_types=1);

namespace App\Support;

/**
 * 外部 SDK (Stripe / AWS) のクライアント待ち上限の**単一出典**。
 *
 * ★env で上書きできる口を作らない。`config/queue.php` の retry_after が
 *   「静的 gate は config をテスト環境の値で読むため、env 上書きを残すと
 *    gate は通るが本番の実値は別、を作れてしまう」という理由でリテラル固定なのと同じ理屈で、
 *   **gate が読む値と本番の実値を一致させる**ために定数で持つ。
 * ★config ファイルから参照するために「クラス定数」にしている
 *   (config の中で config() を呼ぶのは読み込み順に依存して壊れる)。
 *
 * ★用語: 「HTTP 試行 timeout 予算」= cURL / Guzzle に与える 1 試行あたりの上限 × attempts。
 *   **SDK 操作全体の wall-clock deadline ではない** (DNS 解決・credential provider・
 *   endpoint discovery・retry backoff はこの外側)。誇張して書かないこと。
 *
 * 運用契約: docs/architecture.md §外部 SDK の待ち上限の規約
 */
final class ExternalClientTimeouts
{
    // --- Stripe (プロセス大域。ApiRequestor の HTTP client にしか置けない) ---

    /** TCP 接続確立の上限 (SDK 既定 30s)。 */
    public const int STRIPE_CONNECT_TIMEOUT_SECONDS = 5;

    /** 1 リクエストの総時間上限 (SDK 既定 80s)。単一オブジェクトの create/retrieve/pay しか呼ばない。 */
    public const int STRIPE_TIMEOUT_SECONDS = 20;

    /**
     * SDK 内リトライ回数。**0 に pin する**。
     *
     * 課金の一回性は Stripe idempotency key とリコンサイルが担う設計 (AGENTS.md ドメイン規約 6) で、
     * SDK 自動 retry に寄せない。0 でないとジョブの外部予算が retry 数だけ倍化する。
     */
    public const int STRIPE_MAX_NETWORK_RETRIES = 0;

    // --- AWS 制御系 (SES 送信 / SNS。転送量が有界) ---

    public const int AWS_CONTROL_CONNECT_TIMEOUT_SECONDS = 5;

    public const int AWS_CONTROL_TIMEOUT_SECONDS = 15;

    // --- AWS データ系 (s3 disk のクライアント既定。本文転送があるため長い) ---

    public const int AWS_S3_CONNECT_TIMEOUT_SECONDS = 10;

    /**
     * s3 disk クライアントの総時間上限。
     *
     * ★短くできない: Flysystem の write 経路 (`AwsS3V3Adapter::upload()` →
     *   `createOptionsFromConfig()`) は `AVAILABLE_OPTIONS` / `MUP_AVAILABLE_OPTIONS` しか
     *   転送しないため **`@http` を per-command で注入できない**。client 既定が
     *   データ系を賄う必要がある (vendor 実査済み)。
     * ★web 同期経路で使う metadata 操作は per-command で AWS_CONTROL_* へ絞る。
     */
    public const int AWS_S3_TIMEOUT_SECONDS = 900;

    /**
     * AWS SDK クライアントの **試行回数** (SDK 既定 3)。worst case が timeout × attempts に
     * なるため明示 pin する。
     *
     * ★**語彙に注意 (vendor 実査)**: `retries` を array 形式で渡すと
     *   `Aws\Retry\ConfigurationProvider::unwrap()` が `max_attempts` を
     *   **初回を含む試行回数**として解釈し、`ClientResolver::_apply_retries()` が
     *   legacy モードで `maxAttempts - 1` を retry 数に使う。
     *   つまり `max_attempts = 2` は「初回 + 再試行 1 回」である。
     *   一方 per-command の `@retries` (AWS_CONTROL_PLANE_RETRIES) は
     *   **retry 回数**であり `0` = 再試行しない。**同じ数字でも意味が違う**。
     */
    public const int AWS_MAX_ATTEMPTS = 2;

    /**
     * web 同期経路の metadata 操作の **retry 回数** (`@retries`。0 = 再試行しない)。
     *
     * SDK 内で粘らせず、アプリ側で失敗を返して再操作を促す。
     * ★上の AWS_MAX_ATTEMPTS とは語彙が違う (試行回数 vs retry 回数)。
     */
    public const int AWS_CONTROL_PLANE_RETRIES = 0;

    // --- 既定キュー接続 (database) の時間予算 ---

    /**
     * `ExecuteAutoRechargeAttemptJob` の最長経路で許す Stripe HTTP 呼び出し回数。
     *
     * ★静的計数では Cashier 内部 (`createOrGetStripeCustomer`) を数えられないため、
     *   **実行時の HTTP 呼び出し回数**で固定する
     *   (`tests/Feature/Billing/AutoRechargeStripeCallBudgetTest.php`)。
     */
    public const int DEFAULT_CONNECTION_STRIPE_CALL_BUDGET = 10;

    /** 既定接続のジョブが外部 SDK 待ちに使ってよい上限 (= 20s × 10 回)。 */
    public const int DEFAULT_CONNECTION_EXTERNAL_BUDGET_SECONDS = 200;

    /** 外部呼び出し以外 (DB / ロック待ち / ログ / 後始末) の予算。 */
    public const int DEFAULT_CONNECTION_LOCAL_BUDGET_SECONDS = 90;

    /** 既定接続のワーカー `--timeout`。`外部予算 + 局所予算 < これ < retry_after` を守る。 */
    public const int DEFAULT_CONNECTION_WORKER_TIMEOUT_SECONDS = 300;

    /**
     * AWS クライアント構築引数 (制御系)。
     *
     * @return array{http: array{connect_timeout: int, timeout: int}, retries: array{mode: 'legacy', max_attempts: int}}
     */
    public static function awsControlClientOptions(): array
    {
        return [
            'http' => [
                'connect_timeout' => self::AWS_CONTROL_CONNECT_TIMEOUT_SECONDS,
                'timeout' => self::AWS_CONTROL_TIMEOUT_SECONDS,
            ],
            'retries' => ['mode' => 'legacy', 'max_attempts' => self::AWS_MAX_ATTEMPTS],
        ];
    }

    /**
     * AWS クライアント構築引数 (s3 disk = データ系)。
     *
     * @return array{http: array{connect_timeout: int, timeout: int}, retries: array{mode: 'legacy', max_attempts: int}}
     */
    public static function awsS3ClientOptions(): array
    {
        return [
            'http' => [
                'connect_timeout' => self::AWS_S3_CONNECT_TIMEOUT_SECONDS,
                'timeout' => self::AWS_S3_TIMEOUT_SECONDS,
            ],
            'retries' => ['mode' => 'legacy', 'max_attempts' => self::AWS_MAX_ATTEMPTS],
        ];
    }

    /**
     * S3 の **per-command** 上書き (web 同期経路の metadata 操作用)。
     *
     * `Aws\AwsClient::getCommand()` は `@http` を `+=` で合成する = **渡した側が勝つ**。
     * `@retries` は `Aws\RetryMiddleware` / `RetryMiddlewareV2` の両方が読む (vendor 実査済み)。
     *
     * @return array{'@http': array{connect_timeout: int, timeout: int}, '@retries': int}
     */
    public static function awsControlPlaneCommandOptions(): array
    {
        return [
            '@http' => [
                'connect_timeout' => self::AWS_CONTROL_CONNECT_TIMEOUT_SECONDS,
                'timeout' => self::AWS_CONTROL_TIMEOUT_SECONDS,
            ],
            '@retries' => self::AWS_CONTROL_PLANE_RETRIES,
        ];
    }
}
