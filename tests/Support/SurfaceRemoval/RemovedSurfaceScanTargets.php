<?php

declare(strict_types=1);

namespace Tests\Support\SurfaceRemoval;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * 撤去物の不在 gate が共有する**走査根と実走査母集団**の単一出典。
 *
 * ★走査根 (8 本): `.github` / `app` / `bootstrap` / `config` / `lang` / `resources` /
 *   `routes` / `scripts`。`.github` と `scripts` は家系の正典 v1 が**必須**にしている
 *   (撤去直後に CI 設定へ参照が残り CI ジョブが全滅した実測事故の教訓)。
 * ★`database/migrations` は**含めない**。撤去した表の名前は移行履歴に必ず残るため、
 *   含めると原理的に赤くなる (正典 v1 の明文)。
 * ★母集団は**拡張子で絞らない**。`scripts/` には拡張子なしの実行ファイルが実在し、
 *   拡張子の許可集合方式ではそれらが落ちて上記の事故をそのまま再現する。
 * ★確定は**この 1 経路だけ**で行う (順序を固定する):
 *     git 追跡下の列挙 → symlink が解決でき解決先がリポジトリ内か (壊れている / 外なら unresolved)
 *     → 通常ファイルとして読めるか (失敗は unresolved)
 *     → NUL 判定 (含むなら binaryExcluded) → UTF-8 検証 (不正は unresolved)
 *     → 実走査母集団へ登録
 *   **数える集合は本体の検査が実際に走査した集合と同一**である (別に数え直さない)。
 * ★**fail-open を作らない**: git 追跡下にあるのに通常ファイルとして読めないパスを
 *   `continue` で捨てない (削除途中 / 壊れた symlink に撤去語があると検査から消えるため)。
 *   必ず `unresolved` へ理由つきで登録する。
 * ★**バイナリ除外は無言で許容しない**: 利用側 gate は `binaryExcluded === []` を
 *   不変条件にする (NUL を 1 つ入れて静的層を迂回する経路を塞ぐ)。
 * ★**保証しないもの**: git 未追跡のファイルは列挙しない
 *   (gate が守る境界は commit / CI であり、そこでは必ず追跡下にある)。
 *   走査根の外 (`tests/` / `docs/` / `database/` 等) は見ない。
 * ★`Tests\Support\TrackedPhpSourceFiles` との関係: あちらは拡張子 `.php` に限った
 *   リポジトリ全体の全数列挙で、本クラスは**同じ作法 (`git ls-files`) で母集団を
 *   全ファイルへ広げ、走査根を 8 本へ絞った兄弟**である。列挙を 2 本持つのではなく
 *   対象の定義が違う。
 */
final class RemovedSurfaceScanTargets
{
    /** @var list<string> 走査根 (リポジトリルート相対)。 */
    private const array ROOT_DIRECTORIES = [
        '.github', 'app', 'bootstrap', 'config', 'lang', 'resources', 'routes', 'scripts',
    ];

    /**
     * 各根に必ず含まれる代表パス (root 割当 / パス計算の誤りを検出する pin)。
     *
     * @var array<string, string>
     */
    public const array REPRESENTATIVE_PATHS = [
        '.github' => '.github/workflows/ci.yml',
        'app' => 'app/Providers/FortifyServiceProvider.php',
        'bootstrap' => 'bootstrap/app.php',
        'config' => 'config/seo.php',
        'lang' => 'lang/ja/validation.php',
        'resources' => 'resources/js/pages/Settings/Security.svelte',
        'routes' => 'routes/web.php',
        'scripts' => 'scripts/ci/drop-test-db.php',
    ];

    /**
     * 確定済みの実走査母集団 (プロセス内で 1 度だけ確定する)。
     *
     * ★2 つの gate が同じ母集団を共有するためのメモ化であり、判定を持たない。
     */
    private static ?ScanPopulation $memoizedPopulation = null;

    /** インスタンス化しない (純関数の置き場)。 */
    private function __construct() {}

    /** リポジトリルート (テスト実行時の base path)。 */
    public static function repositoryRoot(): string
    {
        $root = realpath(__DIR__.'/../../..');
        if (! is_string($root)) {
            throw new RuntimeException('リポジトリルートを解決できません');
        }

        return $root;
    }

    /**
     * 走査根 (相対 => 絶対)。**存在しない根は fail-fast**。
     *
     * @return array<string, string>
     */
    public static function roots(): array
    {
        $repositoryRoot = self::repositoryRoot();
        $roots = [];
        foreach (self::ROOT_DIRECTORIES as $relative) {
            $absolute = realpath($repositoryRoot.'/'.$relative);
            if (! is_string($absolute)) {
                throw new RuntimeException("走査根を解決できません: {$relative}");
            }
            $roots[$relative] = $absolute;
        }

        return $roots;
    }

    /**
     * 解決済みの絶対パスがリポジトリルート配下かどうか (純関数。自己検証の seam)。
     *
     * ★`population()` も自己検証も必ずこの関数を通す。symlink 判定を `population()` 内へ
     *   閉じ込めると、`git ls-files` の母集団外から確かめる手立てが無くなる。
     */
    public static function isPathInsideRepository(string $repositoryRoot, string $resolvedTarget): bool
    {
        return str_starts_with($resolvedTarget, rtrim($repositoryRoot, '/').'/');
    }

    /**
     * symlink の解決結果の判定 (**`population()` も自己検証も必ずここを通る**)。
     *
     * ★symlink でなければ null。解決できない (壊れた symlink) か、解決先がリポジトリ外なら理由を返す。
     *   リポジトリ外のファイルを黙って走査対象へ引き込まず、走査対象からも逃がさない。
     * ★判定は純関数 `isPathInsideRepository()` を通す (`git ls-files` の母集団の外からも
     *   同じ経路で確かめられるようにするため)。
     */
    public static function symlinkUnresolvedReason(string $repositoryRoot, string $absolute): ?string
    {
        if (! is_link($absolute)) {
            return null;
        }

        $target = realpath($absolute);
        if ($target === false) {
            return 'symlink の解決に失敗 (壊れた symlink)';
        }
        if (! self::isPathInsideRepository($repositoryRoot, $target)) {
            return 'symlink がリポジトリ外へ解決される';
        }

        return null;
    }

    /**
     * 内容の分類 (純関数。**`population()` も自己検証も必ずここを通る**)。
     *
     * ★同じ判定を 2 本持たない。NUL 判定と UTF-8 検証を 1 つの入口に閉じることで、
     *   見本 (走査根の外に置く) からも実母集団からも同じ経路で確かめられる。
     */
    public static function classifyContents(string $contents): ContentClassification
    {
        if (str_contains($contents, "\0")) {
            return ContentClassification::Binary;
        }
        if (! mb_check_encoding($contents, 'UTF-8')) {
            return ContentClassification::InvalidUtf8;
        }

        return ContentClassification::Text;
    }

    /** 実走査母集団を確定する (唯一の経路)。 */
    public static function population(): ScanPopulation
    {
        if (self::$memoizedPopulation instanceof ScanPopulation) {
            return self::$memoizedPopulation;
        }

        $repositoryRoot = self::repositoryRoot();
        $files = [];
        $unresolved = [];
        $binaryExcluded = [];

        foreach (array_keys(self::roots()) as $root) {
            foreach (self::trackedPaths($repositoryRoot, $root) as $relative) {
                $absolute = $repositoryRoot.'/'.$relative;

                // ★ symlink の判定を先に通す (壊れた symlink は is_file() が false になるため、
                //   順序を逆にすると共通の純関数を通らず、自己検証と実母集団の経路が切れる)
                $symlinkReason = self::symlinkUnresolvedReason($repositoryRoot, $absolute);
                if ($symlinkReason !== null) {
                    $unresolved[$relative] = $symlinkReason;

                    continue;
                }

                if (! is_file($absolute)) {
                    // ★ git 追跡下なのに通常ファイルとして無い = 無言で捨てない
                    $unresolved[$relative] = '追跡下だが通常ファイルとして読めない';

                    continue;
                }

                $contents = @file_get_contents($absolute);
                if ($contents === false) {
                    $unresolved[$relative] = 'ファイルの読み取りに失敗';

                    continue;
                }

                // ★分類は必ず classifyContents() を通す (自己検証と同じ経路)
                $classification = self::classifyContents($contents);
                if ($classification === ContentClassification::Binary) {
                    $binaryExcluded[] = $relative;

                    continue;
                }
                if ($classification === ContentClassification::InvalidUtf8) {
                    $unresolved[$relative] = 'UTF-8 として不正';

                    continue;
                }

                $files[] = new ScannedFile(
                    root: $root,
                    relative: $relative,
                    contents: $contents,
                    isPhp: str_ends_with($relative, '.php') && ! str_ends_with($relative, '.blade.php'),
                    extension: self::extensionOf($relative),
                );
            }
        }

        return self::$memoizedPopulation = new ScanPopulation($files, $unresolved, $binaryExcluded);
    }

    /**
     * 拡張子 (小文字)。拡張子なしは null。
     *
     * ★`.github/workflows/ci.yml` → `yml` / `scripts/codex` → null。
     *   ドットで始まるだけのファイル (`.gitignore`) は拡張子なしとして扱う。
     */
    public static function extensionOf(string $relative): ?string
    {
        $basename = basename($relative);
        $position = strrpos($basename, '.');
        if ($position === false || $position === 0) {
            return null;
        }

        return strtolower(substr($basename, $position + 1));
    }

    /**
     * git 追跡下の相対パス (root 配下)。
     *
     * ★`is_file()` 判定はここでは**行わない** (捨てずに `unresolved` へ入れるため
     *   `population()` 側の責務にする)。
     *
     * @return list<string>
     */
    private static function trackedPaths(string $repositoryRoot, string $root): array
    {
        $process = new Process(['git', 'ls-files', '-z', '--', $root], $repositoryRoot);
        $process->run();
        if (! $process->isSuccessful()) {
            throw new RuntimeException('git ls-files の実行に失敗しました: '.$process->getErrorOutput());
        }

        $paths = [];
        foreach (explode("\0", $process->getOutput()) as $relative) {
            if ($relative === '') {
                continue;
            }
            $paths[] = $relative;
        }

        return $paths;
    }
}
