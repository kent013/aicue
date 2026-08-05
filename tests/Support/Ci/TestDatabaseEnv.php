<?php

declare(strict_types=1);

namespace Tests\Support\Ci;

use InvalidArgumentException;
use Webmozart\Assert\Assert;

/**
 * テスト用 DB 名の決定ロジック (pgsql 一本化)。
 *
 * 安全軸: tests/bootstrap.php が pgsqlOverrideDatabase() の算出値
 *   (`<slug>_test_<worktree-hash>`) で DB_DATABASE を後勝ち上書きし、直後に
 *   assertPgsqlTestDatabaseSafe() で「最終 DB 名が test DB (allowlist 一致 + 非 dev)」を
 *   Laravel boot 前に fail-closed 検証する一点に集約する。shell / docker-compose から
 *   DB_DATABASE=<dev DB> が leak しても override + 単一点ガードで dev DB には到達しない。
 *
 * paratest 実行時は Laravel の ParallelTesting が base 名に更に `_test_<token>` を
 * 付与してプロセスごとに分離する (2 段分離)。
 *
 * NOTE: prefix / denylist の 'app' は config('template.slug') の既定値に対応する
 *   (本クラスは Laravel boot 前に走るため config() は使えない)。アプリ初期化時の
 *   init.sh 置換対象。テンプレート派生アプリ名をここへ直書きしないこと
 *   (AppNameHardcodeTest)。
 */
final class TestDatabaseEnv
{
    /** テスト DB 名 prefix。config('template.slug') 既定値 'app' + '_test_' (init.sh 置換対象)。 */
    public const TEST_DB_PREFIX = 'app_test_';

    /** 実テスト DB 名の許可パターン (base または paratest worker)。不可逆 DROP / bootstrap ガードの正の allow。 */
    public const TEST_DB_ALLOWLIST_PATTERN = '/^app_test_[0-9a-f]{8}(_test_[0-9]+)?$/';

    /**
     * dev DB 名の hard-deny 対象 (docker-compose の POSTGRES_DB / slug 既定値)。trim+lowercase 比較。
     *
     * `bug_hunt*` は allowlist regex でも構造的に除外されるが、
     * 「bug-hunt 環境の DB は絶対に触らない」(AGENTS.md §bug-hunt の dev DB 防御) という
     * 意図をコードに残す二重防御として明示列挙する。
     *
     * ★ bug-hunt の並列 cap は 4 (`scripts/bug-hunt-shard.sh` の `BUGHUNT_SHARD_CAP`) だが、
     *   本 denylist は**守る側**なので cap と同期させない。過去 cap=8 期に作られ得る
     *   残留 DB (`bug_hunt_5`..`bug_hunt_8`) を保護し続けるため、意図的に cap より広い。
     *   縮めると防御が後退する (`BughuntShardCapInvariantTest` が値を固定している)。
     */
    public const DEV_DB_DENYLIST = [
        'app',
        'bug_hunt',
        'bug_hunt_1',
        'bug_hunt_2',
        'bug_hunt_3',
        'bug_hunt_4',
        'bug_hunt_5',
        'bug_hunt_6',
        'bug_hunt_7',
        'bug_hunt_8',
    ];

    /**
     * 孤児 sweep の分類ロジックのバージョン。
     *
     * `--confirm` token の canonical JSON に含める。分類規則を変更したら**必ず上げる**こと
     * (古い token では apply できなくなる = 規則変更を人間の再承認なしに通過させない)。
     */
    public const CLASSIFIER_VERSION = 1;

    /** worktree root realpath の決定論的 8 桁 hash。別 worktree との DB 衝突を防ぐキー。 */
    public static function workrootHash(string $projectRoot): string
    {
        $real = realpath($projectRoot);
        Assert::string($real, 'projectRoot must resolve to a real path');

        return substr(sha1($real), 0, 8);
    }

    /**
     * pgsql base テスト DB 名 `<slug>_test_<hash>`。
     * 生成名が dev DB でない・allowlist 準拠であることを Assert する (理論破綻で fail-closed)。
     */
    public static function pgsqlBaseDatabase(string $projectRoot): string
    {
        $name = self::TEST_DB_PREFIX.self::workrootHash($projectRoot);

        if (self::isDevDatabase($name)) {
            throw new InvalidArgumentException("computed test DB name collided with a dev DB: {$name}");
        }
        Assert::true(self::isAllowedTestDatabase($name), "computed test DB name is not allowlisted: {$name}");

        return $name;
    }

    /**
     * pgsql のとき DB_DATABASE に強制すべき base 名。pgsql 以外 / 未設定は null。
     *
     * @param  array<string, mixed>  $server  $_SERVER 相当 (DB_CONNECTION を見て分岐)
     */
    public static function pgsqlOverrideDatabase(array $server, string $projectRoot): ?string
    {
        if (($server['DB_CONNECTION'] ?? null) !== 'pgsql') {
            return null;
        }

        return self::pgsqlBaseDatabase($projectRoot);
    }

    /**
     * 単一点 fail-closed ガード本体。pgsql lane で最終 DB_DATABASE が test DB
     * (allowlist 一致 + 非 dev) でなければ例外。tests/bootstrap.php から Laravel boot 前に呼ぶ。
     *
     * @throws InvalidArgumentException dev DB / 非 allowlist の場合
     */
    public static function assertPgsqlTestDatabaseSafe(string $effectiveDb): void
    {
        if (self::isDevDatabase($effectiveDb)) {
            throw new InvalidArgumentException("refusing to run pgsql tests against a dev DB: {$effectiveDb}");
        }
        Assert::true(self::isAllowedTestDatabase($effectiveDb), "effective pgsql test DB is not allowlisted: {$effectiveDb}");
    }

    /** DB 名が dev DB (variant 含む) か。前後空白・大小バリアントも塞ぐ。 */
    public static function isDevDatabase(string $name): bool
    {
        return in_array(strtolower(trim($name)), self::DEV_DB_DENYLIST, true);
    }

    /** DB 名が test allowlist に一致するか (不可逆 DROP・bootstrap ガードの正の allow)。 */
    public static function isAllowedTestDatabase(string $name): bool
    {
        return preg_match(self::TEST_DB_ALLOWLIST_PATTERN, $name) === 1;
    }

    // ── 孤児テスト DB sweep (drop-test-db.php --orphans) の分類 ──

    /**
     * 孤児判定。**同一候補が複数条件を満たしても結果が一意**になるよう、
     * 以下の順に評価して最初に一致した分類で確定する:
     *
     *   1. Protected — hash が `--protect-hash` に含まれる          → shouldDrop = false (常に保護)
     *   2. Live      — hash が生存 worktree hash 集合に含まれる      → shouldDrop = false (常に保護)
     *   3. Foreign   — hash グループの provenance path が実在する    → shouldDrop = false (常に保護)
     *   4. Orphan    — hash グループの provenance path が実在しない  → shouldDrop = (hash ∈ includeHashes)
     *   5. Unlabeled — hash グループに provenance が無い            → shouldDrop = (hash ∈ includeHashes)
     *
     * - 1 が 2 より先: 明示保護は生存判定より強い (人間の意思を最優先)
     * - 2 が 3 より先: comment は書き換え可能な**分類材料**にすぎず、生存 worktree の突合が優先する
     *   (= comment を細工しても生存 DB は落とせない)
     * - 3 が 4 より先: path が実在する = 誰かが使っている可能性がある側へ倒す (fail-safe)
     * - 4 / 5 は「現在のクローンから生存を**否定できない**」群なので、**どちらも明示指定制**にする
     * - worker DB (`_test_N`) は base と同じ hash グループの分類を継承する (base の provenance が代表)
     *
     * **中核原則: 削除可否を分類だけで自動決定しない。**
     * `$includeHashes` (= `--include-hash`) で人間が 1 つずつ名指しした hash 以外は 1 件も落ちない。
     *
     * @param  list<TestDatabaseCandidate>  $candidates
     * @param  list<string>  $liveHashes  生存 worktree の hash
     * @param  list<string>  $protectedHashes  `--protect-hash`
     * @param  list<string>  $includeHashes  `--include-hash` (Orphan / Unlabeled をこの hash に限り候補化)
     * @param  (callable(string): bool)|null  $pathExists  provenance path の実在判定。
     *                                                     既定は `is_dir()`。**注入すると本メソッドは純関数になる**
     *                                                     (FS を触らずに Foreign/Orphan 分岐を固定できる)
     * @return list<TestDatabaseDecision>
     */
    public static function classifyTestDatabases(
        array $candidates,
        array $liveHashes,
        array $protectedHashes,
        array $includeHashes,
        ?callable $pathExists = null,
    ): array {
        $exists = $pathExists ?? static fn (string $path): bool => is_dir($path);

        $live = self::normalizeHashList($liveHashes, '--live hash');
        $protected = self::normalizeHashList($protectedHashes, '--protect-hash');
        $include = self::normalizeHashList($includeHashes, '--include-hash');

        // provenance は **base DB の comment のみ**を hash グループ全体の出自として扱う。
        // base 不在で worker だけ残っている群は provenance を持たない = Unlabeled になる。
        /** @var array<string, string> $groupProvenance */
        $groupProvenance = [];
        foreach ($candidates as $candidate) {
            if (! $candidate->isWorker && $candidate->provenancePath !== null && $candidate->provenancePath !== '') {
                $groupProvenance[$candidate->hash] = $candidate->provenancePath;
            }
        }

        $decisions = [];
        foreach ($candidates as $candidate) {
            $hash = $candidate->hash;
            $provenance = $groupProvenance[$hash] ?? null;
            $inherited = $candidate->isWorker ? ' (base の分類を継承)' : '';

            if (in_array($hash, $protected, true)) {
                $decisions[] = new TestDatabaseDecision(
                    $candidate,
                    TestDatabaseClassification::Protected,
                    "--protect-hash={$hash} で明示保護{$inherited}",
                    false,
                );

                continue;
            }
            if (in_array($hash, $live, true)) {
                $decisions[] = new TestDatabaseDecision(
                    $candidate,
                    TestDatabaseClassification::Live,
                    "生存 worktree の hash{$inherited}",
                    false,
                );

                continue;
            }
            if ($provenance !== null && $exists($provenance)) {
                $decisions[] = new TestDatabaseDecision(
                    $candidate,
                    TestDatabaseClassification::Foreign,
                    "provenance path が実在する (別クローンが生きている可能性): {$provenance}{$inherited}",
                    false,
                );

                continue;
            }

            $named = in_array($hash, $include, true);
            if ($provenance !== null) {
                $decisions[] = new TestDatabaseDecision(
                    $candidate,
                    TestDatabaseClassification::Orphan,
                    $named
                        ? "provenance path が不在で --include-hash={$hash} に名指しされている: {$provenance}{$inherited}"
                        : "provenance path が不在 (落とすには --include-hash={$hash} が必要): {$provenance}{$inherited}",
                    $named,
                );

                continue;
            }

            $decisions[] = new TestDatabaseDecision(
                $candidate,
                TestDatabaseClassification::Unlabeled,
                $named
                    ? "provenance ラベルなしで --include-hash={$hash} に名指しされている{$inherited}"
                    : "provenance ラベルなし (落とすには --include-hash={$hash} が必要){$inherited}",
                $named,
            );
        }

        return $decisions;
    }

    /**
     * `--apply` の confirm token。
     *
     * canonical JSON (キー順固定 / 要素は昇順 unique の JSON 配列) の SHA-256 **全長 64 桁**。
     * 区切りなしの連結は `["a_b","c"]` と `["a","b_c"]` を区別できないため、必ず JSON 配列にする。
     * `include_hashes` は「どの群を落とすことを人間が承認したか」= 承認文脈の一部なので含める。
     * `classifier_version` は「分類規則を変えたら古い token を無効化する」ために含める。
     *
     * @param  list<string>  $dropTargets  DROP 対象の DB 名
     * @param  list<string>  $liveHashes
     * @param  list<string>  $protectedHashes
     * @param  list<string>  $includeHashes
     */
    public static function orphanConfirmToken(
        array $dropTargets,
        array $liveHashes,
        array $protectedHashes,
        array $includeHashes,
    ): string {
        $sorted = static function (array $values): array {
            /** @var list<string> $values */
            $unique = array_values(array_unique($values));
            sort($unique, SORT_STRING);

            return $unique;
        };

        $canonical = json_encode([
            'classifier_version' => self::CLASSIFIER_VERSION,
            // キー名を 'orphans' にしないのは、実際の対象が Orphan だけでなく
            // Unlabeled も含む「--include-hash で名指しされた DROP 対象」だから。
            'drop_targets' => $sorted($dropTargets),
            'live_hashes' => $sorted($liveHashes),
            'protected' => $sorted($protectedHashes),
            'include_hashes' => $sorted($includeHashes),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return hash('sha256', $canonical);
    }

    /**
     * hash 引数を検証して昇順 unique に正規化する。形式違反は即例外 (fail-closed)。
     *
     * @param  list<string>  $hashes
     * @return list<string>
     */
    private static function normalizeHashList(array $hashes, string $label): array
    {
        foreach ($hashes as $hash) {
            Assert::regex(
                $hash,
                TestDatabaseCandidate::HASH_PATTERN,
                "{$label} must be 8 lowercase hex chars: {$hash}",
            );
        }
        $unique = array_values(array_unique($hashes));
        sort($unique, SORT_STRING);

        return $unique;
    }
}
