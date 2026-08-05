<?php

declare(strict_types=1);

namespace Tests\Support\Ci;

use InvalidArgumentException;
use Webmozart\Assert\Assert;

/**
 * 孤児判定の入力 1 件。**境界で検証済みの値だけ**を持つ値オブジェクト。
 *
 * `pg_database` の問い合わせ結果は `mixed` 由来なので、純関数
 * (`TestDatabaseEnv::classifyTestDatabases()`) へ渡す前にここで
 *   - dev DB denylist に一致しない (`app` / `bug_hunt*`)
 *   - allowlist regex (`^app_test_[0-9a-f]{8}(_test_[0-9]+)?$`) に一致する
 *   - hash が `[0-9a-f]{8}`
 * を検証する。1 つでも崩れたら `InvalidArgumentException` で fail-closed する
 * (純関数側は `mixed` も未検証名も受け取らない)。
 */
final readonly class TestDatabaseCandidate
{
    /** worktree hash の形式 (8 桁小文字 hex)。`--protect-hash` / `--include-hash` にも使う。 */
    public const HASH_PATTERN = '/^[0-9a-f]{8}$/';

    /**
     * @param  string  $name  実 DB 名 (allowlist 検証済み)
     * @param  string  $hash  8 桁 worktree hash
     * @param  bool  $isWorker  paratest worker (`_test_N`) か
     * @param  string|null  $provenancePath  `COMMENT ON DATABASE` の値 (base のみ / 無ければ null)
     */
    public function __construct(
        public string $name,
        public string $hash,
        public bool $isWorker,
        public ?string $provenancePath,
    ) {
        if (TestDatabaseEnv::isDevDatabase($name)) {
            throw new InvalidArgumentException("refusing to build a sweep candidate for a dev DB: {$name}");
        }
        if (! TestDatabaseEnv::isAllowedTestDatabase($name)) {
            throw new InvalidArgumentException("database name is not allowlisted for sweep: {$name}");
        }
        Assert::regex($hash, self::HASH_PATTERN, "worktree hash must be 8 lowercase hex chars: {$hash}");
        Assert::same(
            TestDatabaseEnv::TEST_DB_PREFIX.$hash,
            substr($name, 0, strlen(TestDatabaseEnv::TEST_DB_PREFIX) + 8),
            "hash does not match the database name: {$name} / {$hash}",
        );
    }

    /**
     * DB 名 (+ provenance) から候補を組み立てる。allowlist 違反 / dev DB は例外で弾く。
     *
     * @param  string|null  $provenancePath  base DB の `shobj_description` (worker には付かない)
     */
    public static function fromDatabaseName(string $name, ?string $provenancePath): self
    {
        if (TestDatabaseEnv::isDevDatabase($name)) {
            throw new InvalidArgumentException("refusing to build a sweep candidate for a dev DB: {$name}");
        }
        if (preg_match('/^'.preg_quote(TestDatabaseEnv::TEST_DB_PREFIX, '/').'([0-9a-f]{8})(_test_[0-9]+)?$/', $name, $m) !== 1) {
            throw new InvalidArgumentException("database name is not allowlisted for sweep: {$name}");
        }

        return new self($name, $m[1], ($m[2] ?? '') !== '', $provenancePath);
    }
}
