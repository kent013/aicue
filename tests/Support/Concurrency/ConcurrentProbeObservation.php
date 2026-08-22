<?php

declare(strict_types=1);

namespace Tests\Support\Concurrency;

use App\Enums\ApiErrorCode;

/**
 * 子プロセス 1 本ぶんの一次観測。
 *
 * ★勝者の判定は**行の最終状態ではなくこの一次観測**で行う (正典・家系の作法)。
 *   行だけを見ると「2 本とも本処理を実行したが後着が上書きした」形と区別がつかない。
 * ★{@see self::fromDecodedJson()} は **fail-closed**。必須キーの欠落・型違い・**未知キー**の
 *   いずれでも例外にする (子と親のプロトコル退行を黙って受け入れない)。
 * ★**キャストで救わない**。整数 cast の飽和で別の値が通る穴を家系が実際に踏んでいる。
 */
final readonly class ConcurrentProbeObservation
{
    /**
     * 受理する JSON のキー (deny-by-default。過不足があれば例外)。
     *
     * @var list<string>
     */
    public const array REQUIRED_KEYS = [
        // 同一性 (起動時の割り当て・親が出した go token との突合)
        'child_id', 'nonce', 'go_token',
        // 何が起きたか (一次観測)
        'http_status', 'error_code', 'handler_executions', 'entered_handler',
        // 何を送ったか (2 子が同一要求だったことの証明)
        'route_name', 'uri', 'request_hash', 'api_key_id',
        // 守りたい層以外が無効化されていたか (要素 (3))
        'cache_default', 'cache_store_driver',
        // どこへ繋いだか (開発 DB 到達の検出)
        'db_driver', 'db_host', 'db_port', 'db_database', 'db_username', 'db_charset', 'db_sslmode', 'db_url',
    ];

    private function __construct(
        public string $childId,
        public string $nonce,
        public string $goToken,
        public int $httpStatus,
        /** ★勝者は null、敗者は 'idempotency_in_progress' (409 は 3 コードあるので必須) */
        public ?string $errorCode,
        public int $handlerExecutions,
        public bool $enteredHandler,
        public string $routeName,
        public string $uri,
        public string $requestHash,
        /** ★入力のコピーではなく、認証後の ApiActorContext から観測した値 */
        public int $apiKeyId,
        public string $cacheDefault,
        /** ★既定 store を**裏打ちする driver** (store 名だけでは名前と実体のずれを落とせない) */
        public string $cacheStoreDriver,
        public ProbeDatabaseCoordinates $database,
    ) {}

    /**
     * @throws ConcurrencyProtocolException 解釈できない観測は通さない
     */
    public static function fromDecodedJson(#[\SensitiveParameter] mixed $value): self
    {
        if (! is_array($value)) {
            throw ConcurrencyProtocolException::unexpectedObservation('観測が配列でない');
        }

        $actual = array_keys($value);
        sort($actual);
        $expected = self::REQUIRED_KEYS;
        sort($expected);
        if ($actual !== $expected) {
            throw ConcurrencyProtocolException::unexpectedObservation(sprintf(
                'キー集合が一致しない (欠落: %s / 余剰: %s)',
                implode(',', array_diff($expected, $actual)) ?: '(なし)',
                implode(',', array_diff($actual, $expected)) ?: '(なし)',
            ));
        }

        /** @var array<string, mixed> $value */
        $childId = self::stringValue($value, 'child_id');
        $httpStatus = self::intValue($value, 'http_status');
        if ($httpStatus < 100 || $httpStatus > 599) {
            throw ConcurrencyProtocolException::unexpectedObservation("http_status が範囲外: {$httpStatus}");
        }

        $errorCode = $value['error_code'];
        if ($errorCode !== null && (! is_string($errorCode) || $errorCode === '')) {
            throw ConcurrencyProtocolException::unexpectedObservation(
                'error_code は null か非空文字列でなければならない (空文字は通さない)'
            );
        }

        $handlerExecutions = self::intValue($value, 'handler_executions');
        if ($handlerExecutions < 0) {
            throw ConcurrencyProtocolException::unexpectedObservation('handler_executions が負');
        }

        $enteredHandler = $value['entered_handler'];
        if (! is_bool($enteredHandler)) {
            throw ConcurrencyProtocolException::unexpectedObservation('entered_handler が真偽値でない');
        }

        // ★矛盾する組合せを通さない (true なら >= 1、false なら 0)
        if ($enteredHandler && $handlerExecutions < 1) {
            throw ConcurrencyProtocolException::unexpectedObservation(
                'entered_handler=true なのに handler_executions が 0'
            );
        }
        if (! $enteredHandler && $handlerExecutions !== 0) {
            throw ConcurrencyProtocolException::unexpectedObservation(
                'entered_handler=false なのに handler_executions が 0 でない'
            );
        }

        return new self(
            childId: $childId,
            nonce: self::stringValue($value, 'nonce'),
            goToken: self::stringValue($value, 'go_token'),
            httpStatus: $httpStatus,
            errorCode: $errorCode,
            handlerExecutions: $handlerExecutions,
            enteredHandler: $enteredHandler,
            routeName: self::stringValue($value, 'route_name'),
            uri: self::stringValue($value, 'uri'),
            requestHash: self::stringValue($value, 'request_hash'),
            apiKeyId: self::intValue($value, 'api_key_id'),
            cacheDefault: self::stringValue($value, 'cache_default'),
            cacheStoreDriver: self::stringValue($value, 'cache_store_driver'),
            database: ProbeDatabaseCoordinates::fromDecodedJson($value),
        );
    }

    /** 起動時の割り当て・親が出した go token と食い違ったら通さない */
    public function assertIdentity(string $childId, string $nonce, string $goToken): void
    {
        if ($this->childId !== $childId) {
            throw ConcurrencyProtocolException::identityMismatch($childId, 'child_id', $childId, $this->childId);
        }

        if ($this->nonce !== $nonce) {
            throw ConcurrencyProtocolException::identityMismatch($childId, 'nonce', $nonce, $this->nonce);
        }

        if ($this->goToken !== $goToken) {
            throw ConcurrencyProtocolException::goTokenMismatch($childId, $goToken, $this->goToken);
        }
    }

    /**
     * 敗者としての条件 (release の前提)。満たさなければ例外。
     *
     * ★`idempotency_conflict` / `idempotency_indeterminate` は通さない。
     *   409 は 3 コードあり、body 違いの conflict でも「勝者 1 / 敗者 1」は成立して
     *   **緑になってしまう**ためである。
     */
    public function assertLost(string $expectedRequestHash): void
    {
        if ($this->httpStatus !== 409) {
            throw ConcurrencyProtocolException::unexpectedObservation(
                "敗者の応答が 409 でない: {$this->httpStatus}"
            );
        }

        if ($this->errorCode !== ApiErrorCode::IdempotencyInProgress->value) {
            throw ConcurrencyProtocolException::unexpectedObservation(sprintf(
                '敗者の error_code が %s でない: %s',
                ApiErrorCode::IdempotencyInProgress->value,
                $this->errorCode ?? '(null)',
            ));
        }

        if ($this->enteredHandler || $this->handlerExecutions !== 0) {
            throw ConcurrencyProtocolException::unexpectedObservation('敗者が本処理へ入っている');
        }

        if ($this->requestHash !== $expectedRequestHash) {
            throw ConcurrencyProtocolException::unexpectedObservation(sprintf(
                '敗者の request_hash が親の期待値と違う (期待 %s / 実際 %s)',
                $expectedRequestHash,
                $this->requestHash,
            ));
        }
    }

    /**
     * 守りたい層以外が無効化されていたか (要素 (3))。
     *
     * ★言えるのは「Laravel の既定 cache を経由するプロセス間共有ロックが使えない」までである
     *   (「アプリ側ロックが 1 つも無い」とは言えない)。
     * ★**store 名と driver の 2 つ**を見る。名前だけだと「array という名前の store が
     *   実は別の driver で裏打ちされている」形を落とせない。
     *   (詳細設計は 2 つ目に `Cache::getDefaultDriver()` を挙げていたが、その戻り値は
     *   `config('cache.default')` そのもので同じ事実の写しにすぎず、
     *   かつ cache API を呼ぶと `CachePayloadPlainDataGateTest` の L3 目録への登録が要る。
     *   採用時債務のファイルを触ることになるため、より強い設定側の観測へ置き換えた)
     */
    public function assertAppLocksDisabled(): void
    {
        if ($this->cacheDefault !== 'array' || $this->cacheStoreDriver !== 'array') {
            throw ConcurrencyProtocolException::unexpectedObservation(sprintf(
                '子の既定 cache が array に固定できていない (store=%s driver=%s)',
                $this->cacheDefault,
                $this->cacheStoreDriver,
            ));
        }
    }

    /** 親が渡した DB 座標と完全一致するか (開発 DB 到達の検出) */
    public function assertDatabaseCoordinates(ProbeDatabaseCoordinates $expected): void
    {
        if ($this->database->equals($expected)) {
            return;
        }

        throw ConcurrencyProtocolException::unexpectedObservation(sprintf(
            '子の実効 DB 座標が親と一致しない (親 %s / 子 %s)',
            $expected->describe(),
            $this->database->describe(),
        ));
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private static function stringValue(#[\SensitiveParameter] array $value, string $key): string
    {
        $raw = $value[$key];
        if (! is_string($raw)) {
            throw ConcurrencyProtocolException::unexpectedObservation("{$key} が文字列でない");
        }

        return $raw;
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private static function intValue(#[\SensitiveParameter] array $value, string $key): int
    {
        $raw = $value[$key];
        // ★`is_int` を要求する。"409" のような数値文字列はキャストで救わない。
        if (! is_int($raw)) {
            throw ConcurrencyProtocolException::unexpectedObservation("{$key} が整数でない");
        }

        return $raw;
    }
}
