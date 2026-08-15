<?php

declare(strict_types=1);

namespace Tests\Support\ExternalFakes;

use JsonException;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * 観測用スクリプト (fake-wiring-probe.php) を子プロセスで走らせる。
 *
 * 子の環境は**完全に作り直す** (親から引き継がない)。決め方は 3 段:
 * 1. プロセスの環境変数は `env -i` で空にしてから、必要な分だけを渡す
 *    (親のシェルに残った TESTING_FAKE_* に結果を左右されない。
 *     bug-hunt のスクリプトが DB 資格情報を遮断するときと同じ手である)
 * 2. 設定の出所は**専用の一時環境ファイル 1 つだけ**にする
 *    (`FAKE_WIRING_PROBE_ENV_DIR` / `…_FILE` で子へ渡し、子が
 *     `useEnvironmentPath()` / `loadEnvironmentFrom()` で固定する)。
 *     親のチェックアウトの `.env` / `.env.bughunt.local` は**読ませない**
 *     = 実 Stripe / 外部ログイン / S3 の資格情報は子の設定に 1 つも入らない
 * 3. 設定キャッシュを無効化する。`APP_CONFIG_CACHE` を**存在しない一時パス**へ向け、
 *    キャッシュ無しの起動として観測する (共有の bootstrap/cache を作ったり消したりしない =
 *    並列実行と衝突しない)
 *
 * ★**親の実鍵を複写しない**。`APP_KEY` / `CIPHERSWEET_KEY` は起動のたびに
 *   **使い捨ての値をその場で生成する** (観測は解決と経路の組み立てだけで、既存データの
 *   復号も DB 接続もしないため実鍵は要らない)。これで一時ファイルは秘密を 1 つも持たない。
 * ★それでも置き場所は保護する: 専用の一時ディレクトリを 0700 で作り、環境ファイルは
 *   作成時点から 0600 にする。起動前に権限を確かめ、0600 でなければ**子を起こさずに失敗させる**。
 *   後片付けは finally で行い、timeout・JSON の解釈失敗・Process の例外でも必ず通る。
 *
 * **保証しないもの**: 観測できるのは設定キャッシュ**無し**の起動だけである。
 * キャッシュ有りの起動は観測しない (キャッシュが古いときの挙動は本観測の範囲外で、
 * 本番混入防止は ProductionEnvGuard の二重判定が受け持つ)。
 */
final class FakeWiringProbeRunner
{
    /**
     * 一時環境ファイルに書いてよいキー (deny-by-default)。
     * 実資格情報のキーは 1 つも無く、鍵の 2 つは使い捨ての生成値である。
     *
     * @var list<string>
     */
    public const array ALLOWED_ENV_FILE_KEYS = [
        'APP_ENV', 'APP_KEY', 'APP_URL', 'APP_DEBUG', 'CIPHERSWEET_KEY',
        'TESTING_FAKE_EXTERNALS', 'TESTING_FAKE_STORAGE', 'TESTING_FAKE_LLM',
    ];

    /**
     * 子プロセスへ渡してよい**プロセス環境変数**のキー (上とは別物なので定数を分ける)。
     * `env -i` で空にしたうえでこの 3 つだけを載せる。
     *
     * ★この定数は「起動側が載せる分」の宣言であり、**子が実際に受け取った分**は
     *   probe が自分で観測して返す。両方を突き合わせて初めて `env -i` の退行が映る。
     *
     * @var list<string>
     */
    public const array ALLOWED_PROCESS_ENV_KEYS = [
        'FAKE_WIRING_PROBE_ENV_DIR',
        'FAKE_WIRING_PROBE_ENV_FILE',
        // 設定キャッシュを無効化する (存在しない絶対パスを一時ディレクトリ配下に指す)
        'APP_CONFIG_CACHE',
    ];

    /** 観測に使う自ホストの URL (実サーバは立てない。経路の組み立てにだけ使う) */
    private const string PROBE_APP_URL = 'http://127.0.0.1:65535';

    /** 環境ファイルの名前 (一時ディレクトリ内で固定) */
    private const string ENV_FILE_NAME = '.env.probe';

    /**
     * 観測を 1 回走らせる。
     *
     * @param  string|null  $baseDirectory  一時ディレクトリを作る親 (省略時は sys_get_temp_dir())
     * @return array{
     *     exitCode: int,
     *     output: array<string, mixed>,
     *     envFileValues: array<string, string>,
     *     directory: string,
     *     directoryMode: int,
     *     envFileMode: int,
     *     configCachePath: string,
     *     configCacheExists: bool,
     * }
     */
    public static function run(
        string $environment,
        bool $fakeExternals,
        bool $fakeStorage,
        bool $fakeLlm,
        ?string $baseDirectory = null,
        float $timeout = 120.0,
    ): array {
        $base = $baseDirectory ?? sys_get_temp_dir();
        $directory = $base.'/fake-wiring-probe-'.bin2hex(random_bytes(8));

        if (! mkdir($directory, 0700) || ! is_dir($directory)) {
            throw new RuntimeException("観測用の一時ディレクトリを作れない: {$directory}");
        }

        try {
            chmod($directory, 0700);

            $values = self::envFileValues($environment, $fakeExternals, $fakeStorage, $fakeLlm);
            $envFilePath = $directory.'/'.self::ENV_FILE_NAME;
            self::writeEnvFile($envFilePath, $values);

            $directoryMode = self::mode($directory);
            $envFileMode = self::mode($envFilePath);

            // 起動前に権限を確かめ、違えば子を起こさない (秘密を持たない設計だが置き場所は守る)。
            self::assertSafePermissions($directoryMode, $envFileMode);

            $configCachePath = $directory.'/config-cache-absent.php';

            $process = new Process(
                [
                    'env', '-i',
                    'FAKE_WIRING_PROBE_ENV_DIR='.$directory,
                    'FAKE_WIRING_PROBE_ENV_FILE='.self::ENV_FILE_NAME,
                    'APP_CONFIG_CACHE='.$configCachePath,
                    PHP_BINARY,
                    self::probeScriptPath(),
                ],
                FakeClassCatalog::repoRoot(),
                null,
                null,
                $timeout,
            );
            $process->run();

            return [
                'exitCode' => $process->getExitCode() ?? -1,
                'output' => self::decode($process->getOutput()),
                'envFileValues' => $values,
                'directory' => $directory,
                'directoryMode' => $directoryMode,
                'envFileMode' => $envFileMode,
                'configCachePath' => $configCachePath,
                'configCacheExists' => file_exists($configCachePath),
            ];
        } finally {
            self::removeDirectory($directory);
        }
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
            'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)),
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
