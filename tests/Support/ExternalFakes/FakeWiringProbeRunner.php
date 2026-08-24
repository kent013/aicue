<?php

declare(strict_types=1);

namespace Tests\Support\ExternalFakes;

use JsonException;
use RuntimeException;
use Tests\Support\Process\BootProbeResult;
use Tests\Support\Process\BootProbeRunner;

/**
 * 観測用スクリプト (fake-wiring-probe.php) を子プロセスで走らせる。
 *
 * ★**子の起こし方・回収・書き出し先の退避は共通の起動器**
 *   (`Tests\Support\Process\BootProbeRunner`) が持つ
 *   (lctl feature: subprocess-boot-probe-harness の正典 v1 (1)〜(5))。
 *   本クラスに残るのは「観測用の環境ファイルを安全に用意すること」と
 *   「子の出力を解釈すること」の 2 つだけである。
 *
 * ## 1. 子の環境は 4 段で決まる
 *
 * 継承 (`PATH` / `HOME` / `TMPDIR`) → 基底 (`APP_KEY` / `QUEUE_CONNECTION` / `CACHE_STORE`) →
 * ケース別 (本クラスの `CASE_ENV_KEYS` の 3 件) → 予約 (書き出し先 7 キー。起動器が決める)。
 * **統制点は `proc_open` へ渡す環境配列**である — 子はその配列だけを受け取るので、
 * 開発者ローカルの env (`TESTING_FAKE_*` / DB 資格情報など) はここで締め出される。
 * 後ろの段が前の段に勝つので、ケース別上書きは基底に勝つ。
 *
 * ## 2. 使い捨て鍵の置き場所は 2 つに分かれる
 *
 * `APP_KEY` は**ケース別上書き**、`CIPHERSWEET_KEY` は**環境ファイル**に置く。
 * Laravel の環境変数リポジトリは **immutable** で、**プロセス環境に既に在る値を Dotenv は
 * 上書きしない**ためである。起動器の基底が `APP_KEY` を載せる以上、環境ファイルへ書いた
 * 使い捨て鍵は無視される (設計時に子プロセスで実測して確定した)。
 * どちらの鍵も**親の実鍵を複写しない** — 起動のたびにその場で生成する
 * (観測は解決と経路の組み立てだけで、既存データの復号も DB 接続もしないため実鍵は要らない)。
 *
 * ## 3. 一時ディレクトリが 2 つある
 *
 *  - **外側**: 本クラスが作る**環境ファイルの置き場**。0700 で作り、環境ファイルは 0600。
 *    起動前に実効の権限を確かめ、違えば**子を起こさずに失敗させる**。
 *    後片付けは `withEnvironmentDirectory()` の `finally` が行い、本体がどう終わっても通る
 *  - **内側**: 起動器が作る**書き出し先の退避先**。子の storage / 設定キャッシュ等はここへ向く
 *
 * どちらも**リポジトリの外**であることを起動前に確かめる (正典 v1 (5) の fail-closed)。
 * 境界の判定は `BootProbeRunner::isInside()` を使う (規則を 2 か所で持たない)。
 *
 * ## 4. 設定キャッシュの退避先は起動器の予約鍵である
 *
 * `APP_CONFIG_CACHE` ほか 7 キーは起動器が一時ディレクトリから導く**予約鍵**なので、
 * 本クラスからは渡せない (渡すと `BootProbeRunner::run()` が例外にする)。
 *
 * ## 5. 取り込んだ `BootProbeRunner` の docblock の訂正 (向こうはバイト一致なので直せない)
 *
 * | 取り込んだ記述 | aicue での実際 |
 * |---|---|
 * | 「外部到達統制の subprocess 0 件 pin に触れる (AGENTS.md セキュリティ不変条件 **15**)」 | aicue の外部到達点の目録は **セキュリティ不変条件 9** である |
 * | 「同じ扱いの先例は `tests/Support/Architecture/GlobalUse/PhpLintOracle.php`」 | aicue では `tests/Support/GlobalUse/PhpLintOracle.php` (`Architecture/` が入らない) |
 * | 「統制点は `proc_open` へ渡す環境配列だけ」 | **プロセス環境の統制点はそれで唯一だが、環境ファイル (`.env`) は別経路である** |
 *
 * **趣旨 (`tests/` 専用であり `app/` へ持ち出さない) は aicue でもそのまま成り立つ。**
 *
 * ### 呼び出し側の必須契約 (T249 の実測から。起動器の docblock には書かれていない)
 *
 * **Laravel を起こす子は、環境ファイルの置き場所を自分で退避しなければならない。**
 * 起動器が締め出すのは*プロセス環境*だけで、`.env` の読み込みは止めない。子の作業ディレクトリは
 * リポジトリ root なので、`bootstrap/app.php` を素で読むと Laravel は**リポジトリの `.env` を
 * そのまま設定へ載せる** (実測: DB パスワードと実 `CIPHERSWEET_KEY` が子の設定に載った)。
 * 退避の手段は 2 通りで、どちらでもよい:
 *
 *  - **専用の環境ファイルを読ませる** — 本クラスの経路 (`useEnvironmentPath()` +
 *    `loadEnvironmentFrom()` を子入口が呼ぶ)
 *  - **実在しない場所を指させる** — 起動器の自己検査 (S9 / S10) の経路
 *    (一時ディレクトリを環境パスにすると `safeLoad()` は何も読まない)
 *
 * この契約の守り方は経路ごとに強さが違う。**一部の経路は字句の pin だけである**:
 *
 *  - 本クラスの経路 / 起動器の自己検査 (S9) — **実挙動で測る**
 *    (`ExternalFakeBootProbeTest` の P-17 が読んだ環境ファイルの絶対パスを完全一致で、
 *     S9 が同じことを起動器側で)
 *  - 実プロセス並行テストの子入口 — **字句の pin だけ** (退避の呼び出しが在ることまで)。
 *    別 feature の観測契約なので実測は足していない
 *
 * どの経路がどちらかの正本は
 * `tests/Architecture/PhpBootProbeReferenceInventoryTest.php` の軸 B の申告
 * (`env_isolation`) であり、G-8 が分類を、G-9 が字句の裏打ちを固定する。
 *
 * **保証しないもの**: 観測できるのは設定キャッシュ**無し**の起動だけである。
 * キャッシュ有りの起動は観測しない (キャッシュが古いときの挙動は本観測の範囲外で、
 * 本番混入防止は ProductionEnvGuard の二重判定が受け持つ)。
 */
final class FakeWiringProbeRunner
{
    /**
     * 子が実働証明の印を書く先 (`storage_path()` からの相対パス)。
     *
     * ★正典 v1 (5) の実働証明の観測点。退避が効いていなければ印はリポジトリ側へ落ち、
     *   起動器の `writtenRelativePaths` に現れない = P-13 が赤になる。
     */
    public const string MARKER_RELATIVE_PATH = 'app/private/fake-wiring-probe-marker.txt';

    /**
     * 一時環境ファイルに書いてよいキー (deny-by-default)。
     * 実資格情報のキーは 1 つも無く、鍵は使い捨ての生成値である。
     *
     * ★`APP_KEY` は**ここに置けない**。Laravel の環境変数リポジトリは immutable で、
     *   プロセス環境に既に在る値を Dotenv は上書きしない。BootProbeRunner の基底が
     *   `APP_KEY` を載せる以上、ここへ書いても無視される (設計時に子プロセスで実測)。
     *   使い捨て `APP_KEY` は CASE_ENV_KEYS 側 (ケース別上書き) が運ぶ。
     *
     * @var list<string>
     */
    public const array ALLOWED_ENV_FILE_KEYS = [
        'APP_ENV', 'APP_URL', 'APP_DEBUG', 'CIPHERSWEET_KEY',
        'TESTING_FAKE_EXTERNALS', 'TESTING_FAKE_STORAGE', 'TESTING_FAKE_LLM',
    ];

    /**
     * BootProbeRunner へ渡す**ケース別上書き**のキー (正典 v1 (2) の第 3 段)。
     *
     * ★`TESTING_FAKE_*` はここに**無い**。偽物の宣言はプロセス環境へ 1 件も載せず、
     *   0600 の環境ファイルの中だけに置く (P-7 の危険接頭辞の禁止をそのまま維持する)。
     * ★`APP_CONFIG_CACHE` ほかの書き出し先は runner の**予約鍵**なので渡さない (渡すと例外)。
     * ★この一覧は P-7 がリテラルで完全一致 pin する (増やすと赤になる)。
     *
     * @var list<string>
     */
    public const array CASE_ENV_KEYS = [
        'FAKE_WIRING_PROBE_ENV_DIR',
        'FAKE_WIRING_PROBE_ENV_FILE',
        'APP_KEY',
    ];

    /** 観測に使う自ホストの URL (実サーバは立てない。経路の組み立てにだけ使う) */
    private const string PROBE_APP_URL = 'http://127.0.0.1:65535';

    /** 環境ファイルの名前 (一時ディレクトリ内で固定) */
    private const string ENV_FILE_NAME = '.env.probe';

    /**
     * 環境ファイルの置き場所を 0700 で用意し、**本体がどう終わっても必ず消す**足場。
     *
     * ★`run()` の `finally` をここへ切り出したのは、**後始末そのものを検査から直接呼べるように**
     *   するためである (P-10c)。制限時間超過の経路は「`interpret()` が例外を投げる」(P-15) と
     *   「本体が例外を投げれば中身ごと消える」(P-10c) の合成で覆う。
     *   **プロセスの挙動を偽装する注入の継ぎ目ではない** — 起こし方も回収も BootProbeRunner のままである。
     *
     * ★**リポジトリの中には作らない** (正典 v1 (5) の fail-closed)。内側の退避先は
     *   BootProbeRunner が同じ検査を持つが、外側 (この環境ファイルの置き場) にも同じ境界が要る。
     *   判定は BootProbeRunner::isInside() を使う (境界規則を 2 か所で持たない)。
     * ★権限は callback を呼ぶ**前に**実効値で確かめる。どの失敗でも作った置き場所を消してから投げる。
     *
     * @template T
     *
     * @param  callable(string): T  $body  引数は作った置き場所の絶対パス
     * @return T
     */
    public static function withEnvironmentDirectory(?string $baseDirectory, callable $body): mixed
    {
        $base = $baseDirectory ?? sys_get_temp_dir();

        // ★`Webmozart\Assert` を使わない — あちらは InvalidArgumentException を投げるので、
        //   呼び出し側の例外契約が RuntimeException と 2 本立てになってしまう。
        //   この境界は明示検査で RuntimeException に統一する。
        if (! str_starts_with($base, DIRECTORY_SEPARATOR)) {
            throw new RuntimeException("観測用の置き場所は絶対パスであること: {$base}");
        }

        if (! is_dir($base) || ! is_writable($base)) {
            throw new RuntimeException("観測用の置き場所を使用できない: {$base}");
        }

        $created = rtrim($base, DIRECTORY_SEPARATOR).'/fake-wiring-probe-'.bin2hex(random_bytes(8));

        if (! mkdir($created, 0700) || ! is_dir($created)) {
            throw new RuntimeException("観測用の一時ディレクトリを作れない: {$created}");
        }

        try {
            $directory = realpath($created);
            if (! is_string($directory) || $directory === '') {
                throw new RuntimeException("観測用の一時ディレクトリを正規化できない: {$created}");
            }

            // 正典 (5) の fail-closed。ここを緩めると環境ファイルがリポジトリへ落ちる。
            // ★両辺とも realpath 済みで比べる (FakeClassCatalog::repoRoot() は dirname() の結果で
            //   正規化されていないため、symlink 越しだと素の比較が取り違える)。
            $repositoryRoot = realpath(FakeClassCatalog::repoRoot());
            if (! is_string($repositoryRoot) || $repositoryRoot === '') {
                throw new RuntimeException('リポジトリ root を正規化できない');
            }

            if (BootProbeRunner::isInside($repositoryRoot, $directory)) {
                throw new RuntimeException(
                    "観測用の一時ディレクトリがリポジトリ内にある: {$directory}"
                );
            }

            // 実効の権限で確かめる (chmod の戻り値だけでは umask 等の影響を捕まえられない)。
            if (! chmod($directory, 0700) || self::mode($directory) !== 0700) {
                throw new RuntimeException("観測用の一時ディレクトリを 0700 にできない: {$directory}");
            }

            return $body($directory);
        } finally {
            self::removeDirectory($created);
        }
    }

    /**
     * 観測を 1 回走らせる。
     *
     * @param  string|null  $baseDirectory  環境ファイルの置き場を作る親 (省略時は sys_get_temp_dir())
     * @param  positive-int  $timeoutSeconds
     * @return array{
     *     exitCode: int,
     *     output: array<string, mixed>,
     *     envFileValues: array<string, string>,
     *     caseEnvValues: array<string, string>,
     *     directory: string,
     *     directoryMode: int,
     *     envFileMode: int,
     *     temporaryRoot: string,
     *     writtenRelativePaths: list<string>,
     * }
     */
    public static function run(
        string $environment,
        bool $fakeExternals,
        bool $fakeStorage,
        bool $fakeLlm,
        ?string $baseDirectory = null,
        int $timeoutSeconds = 120,
    ): array {
        // 置き場所の作成・リポジトリ外の fail-closed・0700 の確認・後片付けは helper が持つ。
        return self::withEnvironmentDirectory(
            $baseDirectory,
            static function (string $directory) use ($environment, $fakeExternals, $fakeStorage, $fakeLlm, $timeoutSeconds): array {
                $values = self::envFileValues($environment, $fakeExternals, $fakeStorage, $fakeLlm);
                $envFilePath = $directory.'/'.self::ENV_FILE_NAME;
                self::writeEnvFile($envFilePath, $values);

                $directoryMode = self::mode($directory);
                $envFileMode = self::mode($envFilePath);

                // 起動前に権限を確かめ、違えば子を起こさない (秘密を持たない設計だが置き場所は守る)。
                self::assertSafePermissions($directoryMode, $envFileMode);

                $caseEnv = self::caseEnvValues($directory);

                // 子の起こし方・回収・書き出し先の退避は共通 runner が持つ
                // (lctl feature: subprocess-boot-probe-harness の正典 v1 (1)〜(5))。
                $result = BootProbeRunner::run([self::probeScriptPath()], $caseEnv, $timeoutSeconds);

                return self::interpret($result, $values, $caseEnv, $directory, $directoryMode, $envFileMode);
            },
        );
    }

    /**
     * ケース別上書きの中身 (使い捨て鍵はここで作る)。
     *
     * @return array<string, string>
     */
    public static function caseEnvValues(string $directory): array
    {
        $values = [
            'FAKE_WIRING_PROBE_ENV_DIR' => $directory,
            'FAKE_WIRING_PROBE_ENV_FILE' => self::ENV_FILE_NAME,
            // 実鍵は複写せず、起動のたびに使い捨ての値を生成する。
            'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)),
        ];

        foreach (array_keys($values) as $key) {
            if (! in_array($key, self::CASE_ENV_KEYS, true)) {
                throw new RuntimeException("ケース別上書きに置けないキー: {$key}");
            }
        }

        return $values;
    }

    /**
     * runner の結果を観測結果へ翻訳する (**純関数**。子を起こさずに負例を測れる)。
     *
     * ★fail-closed を 4 つ持つ:
     *   1. 制限時間超過 (`timedOut`) は**通常の非ゼロ終了と区別して例外**にする。
     *      false や非ゼロ終了へ落とすと「観測できなかった」ことが沈黙する (fail-open)
     *   2. 出力が空 → 例外 (観測が成立していない)
     *   3. JSON として読めない → 例外
     *   4. トップレベルが配列でない → 例外
     * ★判定には `timedOut` を使い、`exitCode === 124` を直接読まない
     *   (終了要求を受けてから自分で `exit(0)` する子は `timedOut` かつ `exitCode === 0` になりうる)。
     *
     * @param  array<string, string>  $envFileValues
     * @param  array<string, string>  $caseEnv
     * @return array{
     *     exitCode: int,
     *     output: array<string, mixed>,
     *     envFileValues: array<string, string>,
     *     caseEnvValues: array<string, string>,
     *     directory: string,
     *     directoryMode: int,
     *     envFileMode: int,
     *     temporaryRoot: string,
     *     writtenRelativePaths: list<string>,
     * }
     */
    public static function interpret(
        BootProbeResult $result,
        array $envFileValues,
        array $caseEnv,
        string $directory,
        int $directoryMode,
        int $envFileMode,
    ): array {
        if ($result->timedOut) {
            throw new RuntimeException(
                '観測用の子プロセスが制限時間を超えて強制終了された (観測が成立していない)。'
                ."終了コード: {$result->exitCode} / 標準エラー: ".$result->stderr
            );
        }

        return [
            'exitCode' => $result->exitCode,
            'output' => self::decode($result->stdout),
            'envFileValues' => $envFileValues,
            'caseEnvValues' => $caseEnv,
            'directory' => $directory,
            'directoryMode' => $directoryMode,
            'envFileMode' => $envFileMode,
            'temporaryRoot' => $result->temporaryRoot,
            'writtenRelativePaths' => $result->writtenRelativePaths,
        ];
    }

    /**
     * 一時環境ファイルへ書く内容 (許可キー以外は 1 つも作らない)。
     *
     * @return array<string, string>
     */
    public static function envFileValues(
        string $environment,
        bool $fakeExternals,
        bool $fakeStorage,
        bool $fakeLlm,
    ): array {
        // 実鍵は複写せず、起動のたびに使い捨ての値を生成する。
        // 形式は現行の設定が受理する形に合わせる (妥当性は「子が起動できたこと」自体が示す)。
        $values = [
            'APP_ENV' => $environment,
            'APP_URL' => self::PROBE_APP_URL,
            'APP_DEBUG' => 'false',
            'CIPHERSWEET_KEY' => bin2hex(random_bytes(32)),
            'TESTING_FAKE_EXTERNALS' => $fakeExternals ? 'true' : 'false',
            'TESTING_FAKE_STORAGE' => $fakeStorage ? 'true' : 'false',
            'TESTING_FAKE_LLM' => $fakeLlm ? 'true' : 'false',
        ];

        foreach (array_keys($values) as $key) {
            if (! in_array($key, self::ALLOWED_ENV_FILE_KEYS, true)) {
                throw new RuntimeException("一時環境ファイルへ書けないキー: {$key}");
            }
        }

        return $values;
    }

    /**
     * 一時ディレクトリ 0700 / 環境ファイル 0600 でなければ例外にする (子を起こさない)。
     */
    public static function assertSafePermissions(int $directoryMode, int $envFileMode): void
    {
        if ($directoryMode !== 0700 || $envFileMode !== 0600) {
            throw new RuntimeException(
                '観測用の一時ファイルの権限が想定と違うため子プロセスを起こさない ('
                .sprintf('dir=%04o file=%04o', $directoryMode, $envFileMode).')'
            );
        }
    }

    /** 観測用スクリプトの絶対パス */
    public static function probeScriptPath(): string
    {
        return __DIR__.'/fake-wiring-probe.php';
    }

    /** 観測が組み立てる自ホストの host 部 (転送先の照合に使う) */
    public static function probeAppHost(): string
    {
        $host = parse_url(self::PROBE_APP_URL, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            throw new RuntimeException('観測用 APP_URL から host を取り出せない');
        }

        return $host;
    }

    /**
     * @param  array<string, string>  $values
     */
    private static function writeEnvFile(string $path, array $values): void
    {
        // 'x' は既存ファイルがあれば失敗する (乗っ取られた置き場所へ書き足さない)。
        $handle = fopen($path, 'x');
        if ($handle === false) {
            throw new RuntimeException("観測用の環境ファイルを作れない: {$path}");
        }

        // 中身を書く**前に**権限を絞る。
        chmod($path, 0600);

        $lines = '';
        foreach ($values as $key => $value) {
            $lines .= $key.'='.$value."\n";
        }

        // 書き切れなかった / 閉じられなかった環境ファイルで子を起こすと、
        // 「観測できたつもりで設定が欠けている」状態になる。fail-closed で止める。
        $written = fwrite($handle, $lines);
        $closed = fclose($handle);

        if ($written !== strlen($lines) || $closed === false) {
            throw new RuntimeException("観測用の環境ファイルを書き切れなかった: {$path}");
        }
    }

    private static function mode(string $path): int
    {
        clearstatcache(true, $path);
        $permissions = fileperms($path);

        return $permissions === false ? -1 : ($permissions & 0777);
    }

    /**
     * 子の出力を読む。**解釈できない出力は黙って通さず例外にする** (fail-closed)。
     *
     * 出力が空・JSON でない・配列でない、のいずれも「観測が成立していない」ことを意味する。
     * 中身を `raw_output` に詰めて返すと、後続の表明が別の理由で落ちて原因が隠れる。
     *
     * @return array<string, mixed>
     */
    private static function decode(string $output): array
    {
        if (trim($output) === '') {
            throw new RuntimeException('観測用スクリプトが何も出力しなかった (観測が成立していない)');
        }

        try {
            $decoded = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException(
                '観測用スクリプトの出力を JSON として読めない: '.$e->getMessage()."\n出力: ".$output,
                previous: $e
            );
        }

        if (! is_array($decoded)) {
            throw new RuntimeException('観測用スクリプトの出力が配列ではない。出力: '.$output);
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private static function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory.'/'.$entry;
            if (is_dir($path)) {
                self::removeDirectory($path);

                continue;
            }
            unlink($path);
        }

        rmdir($directory);
    }
}
