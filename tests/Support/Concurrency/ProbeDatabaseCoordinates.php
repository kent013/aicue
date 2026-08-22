<?php

declare(strict_types=1);

namespace Tests\Support\Concurrency;

use Webmozart\Assert\Assert;

/**
 * DB 接続座標 (親の期待値も子の観測も同じ型で持ち、同型どうしで厳密比較する)。
 *
 * ★`db_port` は `int`、他は `string` である。`array<string, string>` で持つと
 *   厳密比較のために暗黙のキャストが要り、「外部観測をキャストで救わない」という
 *   本設計の方針と矛盾する。
 */
final readonly class ProbeDatabaseCoordinates
{
    /** 観測 JSON でのキー名 (親子で同じ綴りを使うための唯一の正本) */
    public const array OBSERVATION_KEYS = [
        'db_driver', 'db_host', 'db_port', 'db_database',
        'db_username', 'db_charset', 'db_sslmode', 'db_url',
    ];

    public function __construct(
        public string $driver,
        public string $host,
        public int $port,
        public string $database,
        public string $username,
        public string $charset,
        public string $sslmode,
        /** ★空文字のみ許可 (非空は fail-closed) */
        public string $url,
    ) {
        Assert::same($url, '', 'DB_URL 主体の設定は本ハーネスの前提外である');
    }

    /**
     * **実行中のアプリの実接続設定**から作る (信頼済み設定の正規化)。
     *
     * 親も子も同じ経路で観測する — 値が違えば「別の DB を向いている」ことがそのまま差になる
     * (同じ抽出規則で読むからこそ、比較が座標の差だけを映す)。
     *
     * ★`config` の port は数値文字列でありうる。**黙ってキャストせず**、
     *   数値文字列であることと **1〜65535 の範囲**を明示的に検証してから int 化する。
     *   これは「信頼済みの設定を正規化する」経路であり、外部 JSON とは扱いが違う。
     */
    public static function fromParentConfig(): self
    {
        Assert::same(config('database.default'), 'pgsql', 'このハーネスは pgsql レーンを前提にする');

        $config = config('database.connections.pgsql');
        Assert::isArray($config);

        return new self(
            driver: self::stringValue($config, 'driver'),
            host: self::stringValue($config, 'host'),
            port: self::portValue($config['port'] ?? null),
            database: self::stringValue($config, 'database'),
            username: self::stringValue($config, 'username'),
            charset: self::stringValue($config, 'charset'),
            sslmode: self::stringValue($config, 'sslmode'),
            url: (string) ($config['url'] ?? ''),
        );
    }

    /**
     * 子側の観測 JSON から作る (**外部入力なので fail-closed**)。
     *
     * ★こちらは `is_int()` を要求し、**キャストで救わない**
     *   (数値文字列 "5432" は通さない。整数 cast の飽和で別の値が通る穴を家系が踏んでいる)。
     *
     * @param  array<string, mixed>  $value
     *
     * @throws ConcurrencyProtocolException
     */
    public static function fromDecodedJson(#[\SensitiveParameter] array $value): self
    {
        foreach (self::OBSERVATION_KEYS as $key) {
            if (! array_key_exists($key, $value)) {
                throw ConcurrencyProtocolException::unexpectedObservation("DB 座標のキーが欠けている: {$key}");
            }
        }

        $port = $value['db_port'];
        if (! is_int($port)) {
            throw ConcurrencyProtocolException::unexpectedObservation(
                'db_port が整数でない (数値文字列をキャストで救わない)'
            );
        }
        if ($port < 1 || $port > 65535) {
            throw ConcurrencyProtocolException::unexpectedObservation("db_port が範囲外: {$port}");
        }

        $strings = [];
        foreach (['db_driver', 'db_host', 'db_database', 'db_username', 'db_charset', 'db_sslmode', 'db_url'] as $key) {
            $raw = $value[$key];
            if (! is_string($raw)) {
                throw ConcurrencyProtocolException::unexpectedObservation("{$key} が文字列でない");
            }
            $strings[$key] = $raw;
        }

        if ($strings['db_url'] !== '') {
            throw ConcurrencyProtocolException::unexpectedObservation(
                'db_url が非空 (DB_URL 主体の設定で起動した子は受理しない)'
            );
        }

        return new self(
            driver: $strings['db_driver'],
            host: $strings['db_host'],
            port: $port,
            database: $strings['db_database'],
            username: $strings['db_username'],
            charset: $strings['db_charset'],
            sslmode: $strings['db_sslmode'],
            url: $strings['db_url'],
        );
    }

    /** 全項目の厳密比較 */
    public function equals(self $other): bool
    {
        return $this->driver === $other->driver
            && $this->host === $other->host
            && $this->port === $other->port
            && $this->database === $other->database
            && $this->username === $other->username
            && $this->charset === $other->charset
            && $this->sslmode === $other->sslmode
            && $this->url === $other->url;
    }

    /**
     * 観測 JSON へ載せる形 (キーの綴りを 1 か所に閉じる)。
     *
     * @return array<string, string|int>
     */
    public function toObservationValues(): array
    {
        return [
            'db_driver' => $this->driver,
            'db_host' => $this->host,
            'db_port' => $this->port,
            'db_database' => $this->database,
            'db_username' => $this->username,
            'db_charset' => $this->charset,
            'db_sslmode' => $this->sslmode,
            'db_url' => $this->url,
        ];
    }

    /** 人が読める形 (不一致の診断に使う) */
    public function describe(): string
    {
        return sprintf(
            '%s://%s@%s:%d/%s (charset=%s sslmode=%s url=%s)',
            $this->driver,
            $this->username,
            $this->host,
            $this->port,
            $this->database,
            $this->charset,
            $this->sslmode,
            $this->url === '' ? '(空)' : $this->url,
        );
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private static function stringValue(array $config, string $key): string
    {
        $value = $config[$key] ?? null;
        Assert::string($value, "database.connections.pgsql.{$key} が文字列でない");
        Assert::notEmpty($value, "database.connections.pgsql.{$key} が空である");

        return $value;
    }

    private static function portValue(mixed $port): int
    {
        if (is_int($port)) {
            Assert::range($port, 1, 65535, 'DB port が範囲外である');

            return $port;
        }

        Assert::string($port, 'DB port が整数でも文字列でもない');
        Assert::regex($port, '/^[0-9]+$/', 'DB port が数値文字列でない (黙ってキャストしない)');

        $normalized = (int) $port;
        Assert::range($normalized, 1, 65535, 'DB port が範囲外である');

        return $normalized;
    }
}
