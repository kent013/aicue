<?php

declare(strict_types=1);

namespace Tests\Support\Concurrency;

use RuntimeException;
use Tests\Support\Ci\TestDatabaseEnv;
use Tests\Support\ExternalFakes\FakeWiringProbeRunner;
use Webmozart\Assert\Assert;

/**
 * 子プロセスの設定の出所を作る (開発 DB への到達遮断の中心)。
 *
 * 作法は {@see FakeWiringProbeRunner} の 6 点規約を踏襲する:
 * `env -i` で環境を作り直す / 専用の一時 env ファイル 1 つだけを設定の出所にする /
 * ディレクトリ 0700・env ファイル 0600 を起動前に検査して違えば子を起こさない /
 * 締切つき実行 / 解釈できない子の出力は fail-closed / finally で必ず片付ける。
 *
 * ★相手 (`FakeWiringProbeRunner`) は **DB へ接続しないこと**が要件なので DB 座標を渡さない。
 *   こちらは**接続することが要件**なので、遮断の設計を独自に持つ。
 *   「似ているから」で共通基底へ寄せない (寄せると DB 遮断が片方の都合で緩む)。
 * ★**相手と違う判断をした点を黙って作らない**: 相手は APP_KEY / CIPHERSWEET_KEY を
 *   使い捨てで生成し「一時ファイルは秘密を 1 つも持たない」を達成している。
 *   こちらは**既存行 (CipherSweet で暗号化された PII) を読む必要がある**ため親の実鍵を渡す。
 *   そのぶん置き場所を守る (0700 / 0600 / 起動前の権限検査 /
 *   **回収の成否にかかわらず finally で必ず unlink**)。
 *
 * **保証しないもの**: ここが塞ぐのは「子が親のチェックアウトの `.env` / プロセス環境を
 * 読んで別の DB へ繋ぐ」経路だけである。子が自分でハードコードした座標へ繋ぐ形
 * (実装ミス) は塞げないので、実効座標の一致は子の段 9 と親の
 * {@see ConcurrentProbeObservation::assertDatabaseCoordinates()} が別に見る。
 */
final class ProbeEnvironment
{
    /**
     * 子の env ファイルへ書いてよいキー (deny-by-default)。
     *
     * @var list<string>
     */
    public const array ALLOWED_ENV_FILE_KEYS = [
        'APP_ENV', 'APP_KEY', 'APP_URL', 'APP_DEBUG', 'CIPHERSWEET_KEY', 'BCRYPT_ROUNDS',
        'DB_CONNECTION', 'DB_URL', 'DB_HOST', 'DB_PORT', 'DB_DATABASE',
        'DB_USERNAME', 'DB_PASSWORD', 'DB_CHARSET', 'DB_SSLMODE',
        'CACHE_STORE', 'QUEUE_CONNECTION', 'SESSION_DRIVER', 'MAIL_MAILER',
    ];

    /**
     * 子へ渡してよい**プロセス環境変数** (`env -i` で空にしたうえでこれだけ載せる)。
     *
     * ★この定数は「起動側が載せる分」の宣言であり、**子が実際に受け取った分**は
     *   子自身が段 6 で観測して突き合わせる (組み立て側の配列を見ても `env -i` の退行は映らない)。
     *
     * @var list<string>
     */
    public const array ALLOWED_PROCESS_ENV_KEYS = [
        'CONCURRENCY_PROBE_ENV_DIR',
        'CONCURRENCY_PROBE_ENV_FILE',
        // 設定キャッシュを無効化する (存在しない絶対パスを一時ディレクトリ配下に指す)
        'APP_CONFIG_CACHE',
    ];

    /** env ファイルの名前 (workspace 内で固定) */
    public const string ENV_FILE_NAME = '.env.probe';

    /**
     * env ファイルの 1 行を受理する唯一の書式。
     *
     * 値の中身は「引用符・バックスラッシュ・ドル記号以外の 1 文字」か
     * 「**encoder が実際に作る 3 種の escape** (`\\` / `\"` / `\$`)」の並びだけである。
     * 素の `$` を許さないのは、encoder が必ず escape する以上 canonical な出力には現れず、
     * かつ phpdotenv が二重引用符の中で `${VAR}` を展開する = 実効値が食い違う経路だからである。
     */
    private const string ENV_LINE_PATTERN = '/^([A-Z][A-Z0-9_]*)="((?:[^"\\\\$]|\\\\[\\\\"$])*)"$/';

    /**
     * 親の**実行時の実接続設定**から子の env 値を作る。
     *
     * ★値の出所は `config('database.connections.pgsql')` であり env の再読解ではない
     *   (親と子が同じ DB を見ることが構造的に保証される)。
     * ★`DB_URL` は**空文字で固定**する。キーを消すと子の `.env` 読み込みで復活する。
     *
     * @return array<string, string>
     *
     * @throws RuntimeException 前提が崩れているとき (子を起こさせない)
     */
    public static function envFileValues(): array
    {
        Assert::same(config('database.default'), 'pgsql', 'このハーネスは pgsql レーンを前提にする');

        $config = config('database.connections.pgsql');
        Assert::isArray($config);

        // ★前提検査 1: 親が DB_URL 主体で接続していると、設定配列の host/port/database は
        //   実効座標とは限らない (URL 解析結果が優先される)。その場合は子を起こさない。
        $url = $config['url'] ?? null;
        if ($url !== null && $url !== '') {
            throw new RuntimeException(
                'このハーネスは個別キー接続のレーンを前提にする (DB_URL 主体の設定では'
                .'設定配列の host/port/database が実効座標とは限らないため子を起こさない)'
            );
        }

        $coordinates = ProbeDatabaseCoordinates::fromParentConfig();

        // ★前提検査 2: 既存の単一点ガードを**親側でも**通す (allowlist 一致 + dev denylist)。
        TestDatabaseEnv::assertPgsqlTestDatabaseSafe($coordinates->database);

        $values = [
            'APP_ENV' => 'testing',
            'APP_KEY' => self::requiredString(config('app.key'), 'app.key'),
            'APP_URL' => self::requiredString(config('app.url'), 'app.url'),
            'APP_DEBUG' => config('app.debug') === true ? 'true' : 'false',
            'CIPHERSWEET_KEY' => self::requiredString(
                config('ciphersweet.providers.string.key'),
                'ciphersweet.providers.string.key',
            ),
            // ★このアプリは config/hashing.php を持たない (framework 既定にまかせている) ため、
            //   親が実際に使っている値の出所はプロセス環境だけである。
            'BCRYPT_ROUNDS' => (string) (env('BCRYPT_ROUNDS') ?? 12),
            'DB_CONNECTION' => 'pgsql',
            'DB_URL' => '',
            'DB_HOST' => $coordinates->host,
            'DB_PORT' => (string) $coordinates->port,
            'DB_DATABASE' => $coordinates->database,
            'DB_USERNAME' => $coordinates->username,
            'DB_PASSWORD' => (string) (config('database.connections.pgsql.password') ?? ''),
            'DB_CHARSET' => $coordinates->charset,
            'DB_SSLMODE' => $coordinates->sslmode,
            // 守りたい層以外を無効化する (要素 (3))
            'CACHE_STORE' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'SESSION_DRIVER' => 'array',
            'MAIL_MAILER' => 'array',
        ];

        self::assertEnvFileKeys($values);
        self::assertNoLineInjection($values);

        return $values;
    }

    /**
     * キー集合が許可一覧と**完全一致**することを検査する。
     *
     * 「許可外が無い」だけでは足りない — 必須の DB キーが**欠落**した場合、
     * その穴は子の `.env` 読み込みで埋まりうる (まさに塞ぎたい形)。
     *
     * @param  array<string, string>  $values
     */
    public static function assertEnvFileKeys(array $values): void
    {
        $actual = array_keys($values);
        $allowed = self::ALLOWED_ENV_FILE_KEYS;
        sort($actual);
        sort($allowed);

        Assert::same($actual, $allowed, 'env ファイルのキー集合が許可一覧と一致しない');
    }

    /**
     * 値に改行 / CR が入っていたら**書かずに例外**にする。
     *
     * env ファイルは 1 行 1 キーなので、値の改行は**別キーの注入**になる。
     *
     * @param  array<string, string>  $values
     */
    public static function assertNoLineInjection(array $values): void
    {
        foreach ($values as $key => $value) {
            if (preg_match('/[\r\n]/', $value) === 1) {
                throw new RuntimeException("env 値に改行を含むキーは書けない: {$key}");
            }
        }
    }

    /**
     * 子が実際に受け取ったプロセス環境のキー集合を検査する (段 6 の純関数)。
     *
     * `env -i` の退行で親の `DB_URL` 等が継承されると、phpdotenv は immutable なので
     * **環境変数が env ファイルより優先**され、遮断を迂回する。
     *
     * @param  list<string>  $received
     *
     * @throws RuntimeException 許可 3 キーとの完全一致でない
     */
    public static function assertProcessEnvironmentKeys(array $received): void
    {
        $actual = $received;
        $allowed = self::ALLOWED_PROCESS_ENV_KEYS;
        sort($actual);
        sort($allowed);

        if ($actual === $allowed) {
            return;
        }

        throw new RuntimeException(
            '継承された環境変数がある (env -i の退行): 余剰='
            .(implode(',', array_diff($actual, $allowed)) ?: '(なし)')
            .' / 欠落='
            .(implode(',', array_diff($allowed, $actual)) ?: '(なし)')
        );
    }

    /**
     * env ファイルの 1 行を組み立てる (書式は 1 つだけ)。
     *
     * 形式: `KEY="value"` — 値は必ず二重引用符で囲み、**`\` / `"` / `$` の 3 文字**を
     * バックスラッシュでエスケープする。
     *
     * ★`$` をエスケープするのは、**phpdotenv が二重引用符の中で `${VAR}` を変数展開するため**
     *   である。エスケープしないと、パスワードに `$` が入っていた場合に実効値が変わる
     *   (子が接続できない、あるいは別の値で接続する)。
     * ★`#` と空白と空文字は引用符の内側にあるので特別扱いは要らない。
     * ★子側の厳格パーサ ({@see self::parseEnvFile()}) は**この 1 形式だけ**を受理し、
     *   同じ規則で復号する。
     */
    public static function encodeLine(string $key, #[\SensitiveParameter] string $value): string
    {
        $escaped = str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $value);

        return $key.'="'.$escaped.'"'."\n";
    }

    /**
     * 上の書式だけを受理する厳格パーサ (bootstrap 前の検査に使う)。
     *
     * ★`loadEnvironmentFrom()` は**その場では解析しない** (起動時に読む場所を指定するだけ)。
     *   bootstrap 前に DB 名を検査するには自前解析が要る。
     *
     * @return array<string, string>
     *
     * @throws RuntimeException 受理しない行がある
     */
    public static function parseEnvFile(string $path): array
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException("子の env ファイルを読めない: {$path}");
        }

        $values = [];
        foreach (explode("\n", $contents) as $index => $line) {
            if ($line === '') {
                continue;
            }

            if (preg_match(self::ENV_LINE_PATTERN, $line, $matches) !== 1) {
                throw new RuntimeException(
                    '子の env ファイルに受理しない行がある (行 '.($index + 1).')'
                );
            }

            $key = $matches[1];
            if (array_key_exists($key, $values)) {
                throw new RuntimeException("子の env ファイルにキーが重複している: {$key}");
            }

            // ★受理した 3 種の escape だけを解く (左から 1 回走査するので `\\\\$` のような
            //   「escape されたバックスラッシュ + escape されたドル」も正しく戻る)。
            $values[$key] = preg_replace_callback(
                '/\\\\([\\\\"$])/',
                static fn (array $m): string => $m[1],
                $matches[2],
            ) ?? '';
        }

        return $values;
    }

    /**
     * 保護されたファイルを作る (作成時点から 0600)。
     *
     * `FakeWiringProbeRunner::writeEnvFile()` と同じ手順を踏む:
     * 1. 一時的に `umask(0o077)` を設定する (**作成時の mode 自体**を 0600 にする)。
     *    `finally` で必ず元の umask へ復元する
     * 2. `fopen($path, 'x')` で作る (既存ファイルがあれば失敗 = 乗っ取られた置き場所へ書き足さない)
     * 3. **秘密を書き込む前に** `chmod($path, 0600)` する
     *    (umask に依存せず 0600 を確定させる。書いてから絞ると露出が残る)
     * 4. 書き切れなかった / 閉じられなかったら fail-closed で例外
     */
    public static function writeProtectedFile(string $path, #[\SensitiveParameter] string $contents): void
    {
        $previousUmask = umask(0o077);

        try {
            // ★`@` を付けるのは、既存ファイルでの失敗を**自前の fail-closed 例外**で表すため。
            //   付けないと PHP の警告が先に ErrorException へ化け、診断が「file exists」の
            //   生メッセージに置き換わって、この経路の意図 (乗っ取られた置き場所へ書き足さない) が読めなくなる。
            $handle = @fopen($path, 'x');
            if ($handle === false) {
                throw new RuntimeException("子へ渡すファイルを作れない (既存 / 権限): {$path}");
            }

            chmod($path, 0600);

            $written = fwrite($handle, $contents);
            $closed = fclose($handle);

            if ($written !== strlen($contents) || $closed === false) {
                throw new RuntimeException("子へ渡すファイルを書き切れなかった: {$path}");
            }
        } finally {
            umask($previousUmask);
        }
    }

    /**
     * ディレクトリ 0700・env ファイル 0600・入力ファイル 0600 でなければ例外 (子を起こさない)。
     */
    public static function assertSafePermissions(int $directoryMode, int $envFileMode, int $inputFileMode): void
    {
        if ($directoryMode !== 0700 || $envFileMode !== 0600 || $inputFileMode !== 0600) {
            throw new RuntimeException(sprintf(
                '子へ渡すファイルの権限が想定と違うため子プロセスを起こさない (dir=%04o env=%04o input=%04o)',
                $directoryMode,
                $envFileMode,
                $inputFileMode,
            ));
        }
    }

    /** パスの permission bits (取得できなければ -1) */
    public static function mode(string $path): int
    {
        clearstatcache(true, $path);
        $permissions = fileperms($path);

        return $permissions === false ? -1 : ($permissions & 0777);
    }

    /** 子プロセスの実行スクリプトの絶対パス */
    public static function probeScriptPath(): string
    {
        return __DIR__.'/idempotency-claim-probe.php';
    }

    private static function requiredString(mixed $value, string $label): string
    {
        Assert::string($value, "{$label} が文字列でない");
        Assert::notEmpty($value, "{$label} が空である");

        return $value;
    }
}
