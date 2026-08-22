<?php

declare(strict_types=1);

namespace Tests\Support\Help;

use RuntimeException;

/**
 * ヘルプ機構の検査が使う一時ツリーの組み立て・撤去。
 *
 * ★**実リポジトリの `docs/help/` を書き換えるテストを書かないための道具**である。
 *   書き込みを伴う検査は必ず本クラスが作る一時ディレクトリを root にする
 *   (`composer test` は `--parallel` なので、実ツリーを触ると別レーンと競合する)。
 * ★作ったディレクトリはプロセス内に覚えておき、`cleanup()` で一括撤去する。
 */
final class HelpTestTree
{
    /** 本プロセスで作った一時ディレクトリ (cleanup の対象)。 */
    /** @var list<string> */
    private static array $created = [];

    /** インスタンス化しない (道具の置き場)。 */
    private function __construct() {}

    /**
     * 一意な一時ディレクトリを作って絶対パスを返す。
     */
    public static function makeDir(string $prefix): string
    {
        $base = sys_get_temp_dir().'/'.$prefix.'-'.bin2hex(random_bytes(8));

        if (! mkdir($base, 0o755, true) && ! is_dir($base)) {
            throw new RuntimeException("一時ディレクトリを作成できません: {$base}");
        }

        $real = realpath($base);
        if ($real === false) {
            throw new RuntimeException("一時ディレクトリを解決できません: {$base}");
        }

        self::$created[] = $real;

        return $real;
    }

    /**
     * manifest を書く。`$sections` は連想配列の list をそのまま JSON にする。
     *
     * @param  list<array<string, mixed>>  $sections
     */
    public static function writeManifest(string $root, array $sections, mixed $schemaVersion = 1): void
    {
        $payload = ['schema_version' => $schemaVersion, 'sections' => $sections];

        self::put($root.'/manifest.json', (string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** 生の manifest 文字列を書く (JSON 破損などの負例用)。 */
    public static function writeRawManifest(string $root, string $contents): void
    {
        self::put($root.'/manifest.json', $contents);
    }

    /** ファイルを書く (中間ディレクトリは作る)。 */
    public static function put(string $path, string $contents): void
    {
        $dir = dirname($path);
        if (! is_dir($dir) && ! mkdir($dir, 0o755, true) && ! is_dir($dir)) {
            throw new RuntimeException("ディレクトリを作成できません: {$dir}");
        }

        if (file_put_contents($path, $contents) === false) {
            throw new RuntimeException("ファイルを書けません: {$path}");
        }
    }

    /**
     * ツリー全体の (相対パス → 内容の sha256) 写像。ディレクトリと symlink も種別として記録する。
     *
     * ★`--check` が 1 バイトも書かないことを見るために使う。
     *
     * @return array<string, string>
     */
    public static function snapshot(string $dir): array
    {
        $result = [];
        self::walk($dir, $dir, $result);
        ksort($result);

        return $result;
    }

    /**
     * MCP ツールの見本を一時走査根へ書き、その場で読み込む。
     *
     * ★`App\Mcp\Tools\` の名前空間で宣言し**明示的に読み込む**ので、
     *   `ReflectionClass::getFileName()` は一時走査根を指す
     *   (走査器の「実体の一致」検査が空振りしない)。
     *
     * @param  non-empty-string  $class  クラス名 (名前空間なし)。プロセス内で一意にすること
     * @param  non-empty-string  $case  `App\Enums\Mcp\ToolName` の case 名
     * @param  string  $schemaBody  `schema()` の本体 (return 文を含む PHP)
     * @return string 書いたファイルの絶対パス
     */
    public static function writeToolFixture(
        string $root,
        string $class,
        string $case,
        string $description = 'fixture tool',
        string $schemaBody = 'return [];',
    ): string {
        $path = $root.'/'.$class.'.php';

        self::put($path, self::toolFixtureSource($class, $case, $description, $schemaBody));

        require_once $path;

        return $path;
    }

    /** 見本ツールの PHP ソース (読み込まずに書くだけの負例でも使う)。 */
    public static function toolFixtureSource(
        string $class,
        string $case,
        string $description = 'fixture tool',
        string $schemaBody = 'return [];',
    ): string {
        $escapedDescription = var_export($description, true);

        return <<<PHP
<?php

declare(strict_types=1);

namespace App\\Mcp\\Tools;

use App\\Enums\\Mcp\\ToolName;
use App\\Services\\Mcp\\Auth\\McpAuthorizationContext;
use Illuminate\\Contracts\\JsonSchema\\JsonSchema;
use Laravel\\Mcp\\Request as McpRequest;

final class {$class} extends AppMcpTool
{
    protected string \$description = {$escapedDescription};

    /** @return array<string, mixed> */
    public function schema(JsonSchema \$schema): array
    {
        {$schemaBody}
    }

    protected function toolName(): ToolName
    {
        return ToolName::{$case};
    }

    /** @return array<string, mixed> */
    protected function runTool(McpRequest \$request, McpAuthorizationContext \$ctx): array
    {
        return [];
    }
}

PHP;
    }

    /** 本プロセスで作った一時ディレクトリをすべて撤去する。 */
    public static function cleanup(): void
    {
        foreach (self::$created as $dir) {
            self::remove($dir);
        }

        self::$created = [];
    }

    /** 再帰削除 (symlink は辿らずに外す)。 */
    public static function remove(string $path): void
    {
        if (is_link($path)) {
            unlink($path);

            return;
        }

        if (! file_exists($path)) {
            return;
        }

        if (! is_dir($path)) {
            unlink($path);

            return;
        }

        $entries = scandir($path);
        if ($entries !== false) {
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }

                self::remove($path.'/'.$entry);
            }
        }

        rmdir($path);
    }

    /**
     * @param  array<string, string>  $result
     */
    private static function walk(string $root, string $dir, array &$result): void
    {
        $entries = scandir($dir);
        if ($entries === false) {
            throw new RuntimeException("ディレクトリを走査できません: {$dir}");
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $absolute = $dir.'/'.$entry;
            $relative = ltrim(substr($absolute, strlen($root)), '/');

            if (is_link($absolute)) {
                $result[$relative] = 'link:'.(readlink($absolute) === false ? '?' : readlink($absolute));

                continue;
            }

            if (is_dir($absolute)) {
                $result[$relative] = 'dir';
                self::walk($root, $absolute, $result);

                continue;
            }

            $contents = file_get_contents($absolute);
            $result[$relative] = $contents === false ? 'unreadable' : hash('sha256', $contents);
        }
    }
}
