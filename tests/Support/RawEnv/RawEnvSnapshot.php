<?php

declare(strict_types=1);

namespace Tests\Support\RawEnv;

use Closure;
use Illuminate\Support\Env;
use InvalidArgumentException;
use RuntimeException;
use Throwable;
use Webmozart\Assert\Assert;

/**
 * 生の環境変数 3 面 (`getenv()` / `$_ENV` / `$_SERVER`) の退避・注入・復元。
 *
 * ★**本リポジトリで 3 面を触ってよいのはこのクラスだけ**である
 *   (例外は自身の契約テストと `tests/bootstrap.php` の 2 つ。
 *    `RawEnvDirectWriteGateTest` が deny-by-default で強制する。
 *    ただし gate が見るのは**列挙した字句の書き込み形だけ**である。保証範囲は
 *    `RawEnvDirectWriteScanner` の docblock が正本)。
 * ★`RefreshDatabase` は**プロセスの環境変数を守らない**ので、テストが env を触ったら
 *   自分で元へ戻さないとテストプロセス全体へ漏れる。
 * ★3 面すべてを見るのは、Laravel の `env()` が **`$_SERVER` → `$_ENV` → `putenv`** の順に
 *   live で読むためである (実測: `Illuminate\Support\Env::getRepository()` が
 *   `RepositoryBuilder::createWithDefaultAdapters()` = `ServerConstAdapter` → `EnvConstAdapter`
 *   を作り、`$putenv` が真のとき末尾に `PutenvAdapter` を足す)。
 *   **この順序は注釈ではなく契約テストが実行時に固定する**。
 *
 * ── 2 通りの結び方 (家系の正典 raw-env-snapshot-restore v1 の i5。択一ではない) ──
 *
 *  (a) 閉包を囲む形: `with()`。検証 → 退避 → `try { 適用 + 本体 } finally { 復元 }`
 *  (b) 退避を持ち回る形: `captureAndClear()` + `restore()`。
 *      検証 → 退避 → `try { 未設定化 } catch { 復元して再送出 }` → 呼び出し側が
 *      枠組みの後処理フック (`afterEach`) から `restore()` を呼ぶ
 *
 *  (b) は適用が終わった時点で呼び出し側へ戻るので `finally` を本体の終わりまで
 *  開いたままにできない。**適用の途中で失敗したときの巻き戻しはその場で行う**
 *  (失敗すると snapshot が呼び出し側へ返らない = 後処理フックも戻せないため)。
 *
 * ── 例外の契約 ─────────────────────────────────────────────────────
 *
 *  - キーの不正・拒否は第 1 段で `InvalidArgumentException`。**1 面も触っていない**。
 *  - `putenv()` の失敗は `RuntimeException`。
 *  - **復元は最初の失敗で止めない** — 全キーの 3 面を最後まで戻し、失敗したキーを集めて
 *    最後に 1 つの `RuntimeException` にする。
 *  - **本体の例外と復元の失敗が重なった場合**、表に出るのは復元の失敗で、
 *    本体の例外は `previous` に連結する (情報を落とさない)。
 *
 * ── 使い方 ─────────────────────────────────────────────────────────
 *
 *  **同時に触るキーは 1 回の操作で渡す** (単一キーの操作を入れ子にして分けない)。
 *  分けると、内側のキーが拒否された時点で外側のキーは既に適用済みになり、
 *  「検証の段では何も触らない」が呼び出し側の書き方で崩れる。
 *
 * ── 保証しないもの (誇張しない) ─────────────────────────────────────────
 *
 *  - **適用の途中で `putenv()` が失敗したときの巻き戻りと、復元が最初の失敗で止まらないことは
 *    動的には検査していない** (検証を通ったキーで `putenv()` を失敗させる状況をテストから作れず、
 *    失敗を注入する差し替え口は新設しない)。構造の固定
 *    (`RawEnvGuardStructure` を使う契約テストの h 群) で代えている。
 *  - `$changes` / `$keys` に**現れないキーには一切触れない**。
 *  - 閉包の口は PHP の連想配列の性質で**数値だけのキーが整数へ畳まれる**ため拒否される。
 *    持ち回りの口は `list` なので畳み込みが起きず数値だけのキーも扱えるが、本リポジトリに需要は無い。
 *  - **本部品はテスト専用である**。`putenv()` はスレッド安全でないため本番の経路では使わない。
 */
final class RawEnvSnapshot
{
    /**
     * 差し替えを拒否するキーの接頭辞 (単一点の守りから導いた宣言。**許可一覧は持たない**)。
     *
     * `DB_` — `tests/bootstrap.php` は「pgsql lane の最終 `DB_DATABASE` が test DB か」を
     * Laravel boot 前に 1 回だけ fail-closed 検証する単一点ガードである
     * (`Tests\Support\Ci\TestDatabaseEnv::assertPgsqlTestDatabaseSafe()`)。
     * テスト実行中に DB 系 env を差し替えると、その検査の後ろを通ることになり
     * dev DB へ向く経路を作りうる (AGENTS.md 禁止事項 3)。
     *
     * @var list<non-empty-string>
     */
    public const array DENIED_KEY_PREFIXES = ['DB_'];

    /**
     * 差し替えを拒否するキー (完全一致)。
     *
     * `TEST_TOKEN` — paratest の作業単位の同定。Laravel の並列 DB 名
     * (`<base>_test_<TEST_TOKEN>`) がこれに乗っている。
     * `APP_CONFIG_CACHE` — `scripts/ci/ensure-test-db.php` が子プロセスへ渡す
     * 専用の設定キャッシュパス。親で立てると「通常経路では誰も生成しないはずの専用パス」の
     * 検査が意味を失う。
     *
     * @var list<non-empty-string>
     */
    public const array DENIED_KEYS = ['TEST_TOKEN', 'APP_CONFIG_CACHE'];

    /**
     * @param  list<array{key: string, serverExists: bool, server: mixed, envExists: bool, env: mixed, process: string|false}>  $state
     */
    private function __construct(private readonly array $state) {}

    /**
     * 3 面を差し替えて閉包を実行し、**成否によらず**元の存在状態と値へ戻す。
     *
     * ★キーは `string` で受け、非空・書式・拒否は**実行時の契約**として第 1 段で検査する
     *   (`non-empty-string` を宣言すると、不正入力を拒否する検査そのものが書けなくなる)。
     *
     * @template TReturn
     *
     * @param  array<string, RawEnvChannels>  $changes
     * @param  Closure(): TReturn  $body
     * @return TReturn
     *
     * @throws InvalidArgumentException キーが不正 / 拒否対象 / process 値に NUL (第 1 段。1 面も触っていない)
     * @throws RuntimeException `putenv()` が失敗した場合 (復元は行われる)
     */
    public static function with(array $changes, Closure $body): mixed
    {
        // --- 第 1 段: 検証 (この時点では何も触らない) ---
        self::assertChangesAllowed($changes);

        /** @var list<string> $keys */
        $keys = array_keys($changes);

        // --- 第 2 段: 退避 (この時点でも何も変えない) ---
        $snapshot = self::capture($keys);

        // --- 第 3 段: 適用 + 本体 (適用途中の失敗も finally で巻き戻る) ---
        $bodyError = null;

        try {
            foreach ($changes as $key => $channels) {
                self::apply((string) $key, $channels);
            }

            return $body();
        } catch (Throwable $e) {
            $bodyError = $e;

            throw $e;
        } finally {
            $snapshot->restore($bodyError);
        }
    }

    /**
     * 指定キーの 3 面を退避し、そのうえで 3 面とも未設定にする。
     * 復元は呼び出し側が枠組みの後処理フックから `restore()` を呼んで行う。
     *
     * @param  list<string>  $keys
     *
     * @throws InvalidArgumentException キーが不正 / 拒否対象の場合 (1 面も触っていない)
     * @throws RuntimeException `putenv()` が失敗した場合 (**その場で巻き戻してから**送出する)
     */
    public static function captureAndClear(array $keys): self
    {
        self::assertKeysAllowed($keys);
        $snapshot = self::capture($keys);

        try {
            foreach ($keys as $key) {
                self::apply($key, RawEnvChannels::none());
            }
        } catch (Throwable $e) {
            $snapshot->restore($e);

            throw $e;
        }

        return $snapshot;
    }

    /**
     * 退避した 3 面を、元の存在状態と値へ戻す (面ごとに独立して戻す)。
     *
     * ★**最初の失敗で止めない**。全キーを最後まで戻してから、失敗したキーをまとめて例外にする。
     *
     * @param  Throwable|null  $previous  本体側で起きていた例外 (復元も失敗したときに連結する)
     *
     * @throws RuntimeException 1 つ以上のキーで `putenv()` が失敗した場合
     */
    public function restore(?Throwable $previous = null): void
    {
        /** @var list<string> $failed */
        $failed = [];

        foreach ($this->state as $saved) {
            $key = $saved['key'];

            if ($saved['serverExists']) {
                $_SERVER[$key] = $saved['server'];
            } else {
                unset($_SERVER[$key]);
            }

            if ($saved['envExists']) {
                $_ENV[$key] = $saved['env'];
            } else {
                unset($_ENV[$key]);
            }

            // `putenv('K=a=b')` は値 `a=b` を設定する (等号を含む値を壊さない)。
            $applied = is_string($saved['process'])
                ? putenv($key.'='.$saved['process'])
                : putenv($key);

            if ($applied === false) {
                $failed[] = $key;
            }
        }

        if ($failed !== []) {
            throw new RuntimeException(
                'putenv() failed while restoring env keys: '.implode(', ', $failed),
                0,
                $previous,
            );
        }
    }

    /**
     * **枠組みを作り直す直前に呼ぶ。** 3 面へ入れた値が `.env.testing` の値で
     * 上書きされるのを防ぐ (正典 v1 の i10)。
     *
     * phpdotenv の immutable writer は「既に定義済みの変数は上書きしない」を
     * **自分が書いたかどうか**で判定する (実装を実読:
     * `Dotenv\Repository\Adapter\ImmutableWriter::isExternallyDefined()` は
     * 「読めて、かつ `$loaded` に自分の記録が無い」ときだけ真を返す)。
     * その writer は `Illuminate\Support\Env::$repository` に**プロセス静的**で保持されるので、
     * 1 度目の boot で `.env.testing` が書いたキーは `$loaded` に載ったままになり、
     * **env を読み直すたびに `.env.testing` の値で上書きされる**。
     * repository を捨てると `$loaded` が空の writer が作り直され、
     * 3 面に在る値が「外部で定義済み」として尊重される。
     *
     * ★**依拠している副作用 (監視条件)**: `Env::enablePutenv()` は本来
     *   putenv アダプタを有効化する API だが、その実装が `static::$repository = null` を
     *   伴うことに依拠している (実測: laravel/framework の `Illuminate\Support\Env`)。
     *   本リポジトリは `disablePutenv()` を呼ばないので、副作用は repository の作り直しだけである。
     *   **上流の版を上げてこの副作用が消えたら、i10 の手段を再評価すること**
     *   (家系の正典 v1 の未決論点 q3)。副作用が生きていること自体は契約テスト (g-1〜g-3) が
     *   実行時に固定する — docblock の監視条件だけでは「緑のまま保証だけ失われる」を検出できない。
     */
    public static function forgetLaravelEnvRepository(): void
    {
        Env::enablePutenv();
    }

    /**
     * 閉包の口の第 1 段 (キーと process 値の検査。**1 面も触らない**)。
     *
     * @param  array<array-key, RawEnvChannels>  $changes
     */
    private static function assertChangesAllowed(array $changes): void
    {
        self::assertKeysAllowed(array_keys($changes));

        foreach ($changes as $key => $channels) {
            if (! $channels->processSpecified) {
                continue;
            }

            // putenv() は NUL を含む文字列で ValueError を投げる。適用の段まで持ち越さない。
            Assert::notContains(
                $channels->processValue,
                "\0",
                "env value for key [{$key}] must not contain a NUL byte (putenv() would throw).",
            );
        }
    }

    /**
     * 受け取ったキーをすべて検査する (第 1 段。**1 面も触らない**)。
     *
     * @param  list<array-key>  $keys
     */
    private static function assertKeysAllowed(array $keys): void
    {
        foreach ($keys as $key) {
            // PHP の連想配列は "0" のような数値だけのキーを整数へ畳む。
            // 畳まれて届いたら復元時に別のキーを触ることになるので拒否する (fail-closed)。
            if (! is_string($key)) {
                throw new InvalidArgumentException(
                    'env key must be a string (PHP folds numeric-string array keys into integers): '
                    .var_export($key, true)
                );
            }

            Assert::stringNotEmpty($key, 'env key must not be empty.');
            Assert::notContains($key, '=', "env key must not contain '=' (putenv syntax): {$key}");
            Assert::notContains($key, "\0", 'env key must not contain a NUL byte.');

            foreach (self::DENIED_KEY_PREFIXES as $prefix) {
                Assert::false(
                    str_starts_with($key, $prefix),
                    "env key [{$key}] is denied: テスト DB の単一点ガード (tests/bootstrap.php) の前提を崩す。"
                    .'DB 接続を発生させない隔離された評価手段を設計フローで新設すること。',
                );
            }

            Assert::notInArray(
                $key,
                self::DENIED_KEYS,
                "env key [{$key}] is denied: 並列実行の作業単位の同定 / 専用の設定キャッシュパスの前提を崩す。",
            );
        }
    }

    /**
     * 3 面の現在の存在状態と値を退避する (第 2 段。**何も変えない**)。
     *
     * ★退避は**連想配列ではなくリスト**で持つ。キーで索く必要が無いうえ、
     *   連想配列にすると数値だけのキーが整数へ畳まれて復元先がずれる。
     *
     * @param  list<string>  $keys
     */
    private static function capture(array $keys): self
    {
        $state = [];
        foreach ($keys as $key) {
            $state[] = [
                'key' => $key,
                // 存在は値と別に持つ (`?? null` は「存在するが null」を潰す)
                'serverExists' => array_key_exists($key, $_SERVER),
                'server' => $_SERVER[$key] ?? null,
                'envExists' => array_key_exists($key, $_ENV),
                'env' => $_ENV[$key] ?? null,
                // getenv() の false (未設定) と '' (空文字) を区別する
                'process' => getenv($key),
            ];
        }

        return new self($state);
    }

    /** 指定した面に値を置き、**指定しなかった面は明示的に未設定にする** (i7)。 */
    private static function apply(string $key, RawEnvChannels $channels): void
    {
        if ($channels->serverSpecified) {
            $_SERVER[$key] = $channels->serverValue;
        } else {
            unset($_SERVER[$key]);
        }

        if ($channels->envSpecified) {
            $_ENV[$key] = $channels->envValue;
        } else {
            unset($_ENV[$key]);
        }

        $applied = $channels->processSpecified
            ? putenv($key.'='.$channels->processValue)
            : putenv($key);

        if ($applied === false) {
            throw new RuntimeException("putenv() failed for env key [{$key}].");
        }
    }
}
