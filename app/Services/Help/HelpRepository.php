<?php

declare(strict_types=1);

namespace App\Services\Help;

use JsonException;

/**
 * ヘルプの置き場 (`docs/help/`) の読み取り層。
 *
 * ★**閉じる側に倒れる**。パスを組み立てるたびに字句の検査 (`assertRelativePath`) と
 *   実体の検査 (`resolveRealDirectory` / `readResolved`) を**やり直す**。
 *   片方だけを通した結果を使い回さない。
 * ★**未検査の絶対パスを外へ出さない**。読み書きの両方を本クラスに閉じ込める
 *   (`read()` / `writeGenerated()`)。呼び出し側が絶対パスを組み立てる口は持たない
 *   — 持たせると「字句だけ通したパスへ書く」経路が必ず生まれる。
 * ★**走査対象**: `manifest.json` が宣言した節と、`_generated/` 直下の `*.md` だけ。
 * ★**保証しないもの**:
 *   - 本文の内容 (Markdown の妥当性・網羅性) は一切見ない。
 *   - `pages/` 配下の未宣言ファイルは孤児として扱わない
 *     (手書きの下書きを赤にしないため。孤児検査の対象は生成物ディレクトリだけである)。
 *   - 生成物ディレクトリの**階層は許さない**。下位ディレクトリを見つけたら例外で止まる
 *     (再帰走査を持たないので「見えない場所に孤児が居る」を作らない)。
 *   - 実体の検査は POSIX 前提である (`is_link()` / `realpath()`)。開発・CI は Linux で、
 *     Windows は対象外。
 *   - **検査と入出力の間の差し替え (TOCTOU) は防げない**。PHP には `openat(2)` /
 *     `O_NOFOLLOW` に相当する API が無く、「実体を検査してから開く」以外の書き方が
 *     存在しないためである。本クラスが保証するのは**静止状態での封じ込め**
 *     (起点から置き場までの経路・生成物ディレクトリ・生成物のいずれも symlink でないこと、
 *     解決後の実体が置き場の内側にあること) までで、
 *     書き込みの最中にファイルを差し替える攻撃者は想定していない
 *     (これは開発者の作業ツリーで走る生成器であり、脅威モデルに含めない)。
 *     書き込み後の検査は**取り消しではなく検出**である。
 */
final class HelpRepository
{
    /** 生成物のディレクトリ名 (直下のみ)。 */
    public const string GENERATED_DIR = '_generated';

    /** 手書きページのディレクトリ名 (直下のみ)。0 件でよい。 */
    public const string PAGES_DIR = 'pages';

    private const string MANIFEST_FILE = 'manifest.json';

    /** 読める manifest の schema 版 (厳密一致。未知の版は読まずに落とす)。 */
    private const int SCHEMA_VERSION = 1;

    /**
     * @param  non-empty-string  $root  `docs/help/` の**canonical な**絶対パス
     *
     * ★**canonical であることは呼び出し側の契約**であり、本クラスが毎回検査する
     *   (`rootReal()`)。配線側は信頼する起点 (`realpath(base_path())`) から組み立てる。
     */
    public function __construct(private readonly string $root) {}

    /**
     * 置き場の実体。**読み書きのすべてがここを通る**。
     *
     * ★**渡された置き場が canonical path であることを毎回検査する**
     *   (`realpath()` の結果が渡された文字列と完全一致すること)。
     *   これは「置き場そのものが symlink でない」だけでなく
     *   **起点から置き場までの経路のどの要素も symlink でない**ことを意味する。
     *   最終要素だけを `is_link()` で見ると、`docs` が外部への symlink で
     *   `docs/help` が (その先の) 実ディレクトリ、という静止状態の抜け道が残る
     *   — `realpath()` が外側を canonical root として返すので、
     *   「置き場の内側か」の検査も生成物ディレクトリの一致検査も**全部通ってしまう**。
     * ★**作業ツリー全体が symlink の先にある形は拒まない**。信頼する起点そのものを
     *   `realpath()` で正規化してから組み立てるためである (配線は `AppServiceProvider`)。
     *
     * @return non-empty-string
     *
     * @throws HelpManifestException
     */
    private function rootReal(): string
    {
        $real = realpath($this->root);

        if ($real === false || ! is_dir($real)) {
            throw new HelpManifestException(
                "ヘルプの置き場をディレクトリとして解決できません: {$this->root}",
            );
        }

        if ($real !== $this->root) {
            throw new HelpManifestException(
                "ヘルプの置き場が canonical path ではありません: {$this->root} (解決先: {$real}) — ".
                '起点から置き場までの経路に symlink がある。'.
                '信頼する起点を realpath で正規化してから組み立てること。',
            );
        }

        return $real;
    }

    /**
     * manifest が宣言した節 (宣言順)。
     *
     * @return list<HelpSection>
     *
     * @throws HelpManifestException
     */
    public function sections(): array
    {
        $manifest = $this->readManifest();

        $sections = [];
        $seenSlugs = [];
        $seenPaths = [];
        $seenGenerators = [];

        foreach ($manifest as $index => $entry) {
            if (! is_array($entry)) {
                throw new HelpManifestException("manifest の sections[{$index}] が object ではありません。");
            }

            $slug = $this->requireNonEmptyString($entry, 'slug', $index);
            $title = $this->requireNonEmptyString($entry, 'title', $index);
            $path = $this->requireNonEmptyString($entry, 'path', $index);

            $generatorKey = null;
            if (array_key_exists('generator', $entry)) {
                $generatorKey = $this->requireNonEmptyString($entry, 'generator', $index);
            }

            $this->assertRelativePath($path, $generatorKey !== null, $index);

            if (isset($seenSlugs[$slug])) {
                throw new HelpManifestException("manifest の slug が重複しています: {$slug}");
            }
            if (isset($seenPaths[$path])) {
                throw new HelpManifestException("manifest の path が重複しています: {$path}");
            }
            // ★generator は 1 節につき 1 本 (完全一致を集合一致へ弱めない)。
            //   `HelpGenerator::generate()` は節を引数に取らないので、
            //   同じ生成器を 2 節が参照する形は「同じ内容を 2 か所へ書く」意味しか持たない。
            if ($generatorKey !== null && isset($seenGenerators[$generatorKey])) {
                throw new HelpManifestException(
                    "manifest の generator が重複しています: {$generatorKey} — ".
                    '1 つの生成器を参照できる節は 1 つだけである。',
                );
            }
            $seenSlugs[$slug] = true;
            $seenPaths[$path] = true;
            if ($generatorKey !== null) {
                $seenGenerators[$generatorKey] = true;
            }

            $sections[] = new HelpSection($slug, $title, $path, $generatorKey);
        }

        return $sections;
    }

    /**
     * 節の本文。存在しなければ null (**不在は例外にしない** — Missing として報告するため)。
     *
     * @throws HelpManifestException 実体が置き場の外・regular file でない・symlink のとき
     */
    public function read(HelpSection $section): ?string
    {
        $this->assertRelativePath($section->path, $section->isGenerated(), null);

        $rootReal = $this->rootReal();
        $absolute = $rootReal.'/'.$section->path;

        if (! file_exists($absolute) && ! is_link($absolute)) {
            return null;
        }

        return $this->readResolved($absolute, $section->path, $rootReal);
    }

    /**
     * 生成物ディレクトリ直下の `*.md` の相対パス (昇順)。孤児検査の母集団である。
     *
     * @return list<non-empty-string>
     *
     * @throws HelpManifestException
     */
    public function generatedArtifactPaths(): array
    {
        $rootReal = $this->rootReal();
        $dir = $rootReal.'/'.self::GENERATED_DIR;

        if (is_link($dir)) {
            throw new HelpManifestException(
                "生成物ディレクトリに symlink は使えません: {$dir} — 実ディレクトリに置き換えること。",
            );
        }
        if (! file_exists($dir)) {
            return [];
        }

        $dirReal = $this->resolveRealDirectory($dir, '生成物ディレクトリ');
        if ($dirReal !== $dir) {
            throw new HelpManifestException("生成物ディレクトリが置き場の外を指しています: {$dir}");
        }

        $entries = scandir($dirReal);
        if ($entries === false) {
            throw new HelpManifestException("生成物ディレクトリを走査できません: {$dirReal}");
        }

        $paths = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $absolute = $dirReal.'/'.$entry;

            // ★symlink / FIFO / socket / ディレクトリを「通常の生成物候補」に混ぜない。
            //   `.md` で終わる symlink を Orphan として静かに返すと、
            //   「通常ファイルでない実体は例外」という規約が字句だけの飾りになる。
            if (is_link($absolute)) {
                throw new HelpManifestException(
                    "生成物ディレクトリに symlink があります: {$absolute} — 削除すること。",
                );
            }
            if (is_dir($absolute)) {
                throw new HelpManifestException(
                    "生成物ディレクトリは階層を許しません: {$absolute} — ".
                    'ディレクトリを削除し、生成物は '.self::GENERATED_DIR.'/ 直下に置くこと。',
                );
            }
            if (! is_file($absolute)) {
                throw new HelpManifestException(
                    "生成物ディレクトリに通常ファイルでない実体があります: {$absolute} — 削除すること。",
                );
            }
            if (! str_ends_with($entry, '.md')) {
                throw new HelpManifestException(
                    "生成物ディレクトリに Markdown 以外の実体があります: {$absolute} — 削除すること。",
                );
            }

            $relative = self::GENERATED_DIR.'/'.$entry;
            $this->assertRelativePath($relative, true, null);
            $paths[] = $relative;
        }

        sort($paths, SORT_STRING);

        return $paths;
    }

    /**
     * 生成物を書き込む。**書き込み経路の実体検査も本クラスに閉じ込める**。
     *
     * ★字句検査だけを通した絶対パスを呼び出し側へ渡さない (`absolutePathFor()` は持たない)。
     *   渡すと「`_generated` が外部ディレクトリへの symlink」で置き場の外へ書けてしまう。
     * ★ディレクトリ作成は**非再帰**である (階層を作れない)。
     * ★書き込みの**後**にもう一度実体を検査する。これは**入れ替えを検出する**ためであり、
     *   書かれてしまった内容を取り消せるという意味ではない (TOCTOU の項)。
     *
     * @throws HelpManifestException
     */
    public function writeGenerated(HelpSection $section, string $contents): void
    {
        if (! $section->isGenerated()) {
            throw new HelpManifestException("手書きページを生成物として書き込めません: {$section->path}");
        }

        $this->assertRelativePath($section->path, true, null);

        $rootReal = $this->rootReal();
        $dir = $rootReal.'/'.self::GENERATED_DIR;

        if (is_link($dir)) {
            throw new HelpManifestException(
                "生成物ディレクトリに symlink は使えません: {$dir} — 実ディレクトリに置き換えること。",
            );
        }
        if (! is_dir($dir) && ! mkdir($dir, 0o755) && ! is_dir($dir)) {
            throw new HelpManifestException("生成物ディレクトリを作成できません: {$dir}");
        }

        $dirReal = $this->resolveRealDirectory($dir, '生成物ディレクトリ');
        if ($dirReal !== $dir) {
            throw new HelpManifestException("生成物ディレクトリが置き場の外を指しています: {$dir}");
        }

        $absolute = $dirReal.'/'.basename($section->path);

        if (is_link($absolute)) {
            throw new HelpManifestException("生成物に symlink は使えません: {$section->path}");
        }
        if (file_exists($absolute) && ! is_file($absolute)) {
            throw new HelpManifestException("生成物の実体が通常ファイルではありません: {$section->path}");
        }

        if (file_put_contents($absolute, $contents) === false) {
            throw new HelpManifestException("生成物を書き込めません: {$section->path}");
        }

        // 書き込み後の再検査 (字句 → 実体 → 書き込み → 実体、の 4 段で閉じる)
        $this->assertWrittenFileIsContained($absolute, $dirReal, $section->path);
    }

    /**
     * 書き込んだ実体を**もう一度**検査する (通常ファイルであり、生成物ディレクトリ直下にあること)。
     *
     * ★`clearstatcache()` を先に呼ぶ。PHP は stat の結果をプロセス内で覚えるので、
     *   書き込み前の観測を使い回すと「書いた後の姿」を見たことにならない。
     * ★これは**取り消しではなく検出**である。書き込みの最中に実体を差し替えられた場合、
     *   本検査は例外を投げるが、既に書かれてしまった内容は戻せない (docblock の TOCTOU の項)。
     *
     * @throws HelpManifestException
     */
    private function assertWrittenFileIsContained(string $absolute, string $dirReal, string $relative): void
    {
        clearstatcache(true, $absolute);

        if (is_link($absolute) || ! is_file($absolute)) {
            throw new HelpManifestException("書き込んだ生成物が通常ファイルではありません: {$relative}");
        }

        $writtenReal = realpath($absolute);
        if ($writtenReal === false || $writtenReal !== $dirReal.'/'.basename($absolute)) {
            throw new HelpManifestException("書き込んだ生成物が置き場の外を指しています: {$relative}");
        }
    }

    /**
     * ディレクトリの実体を解決する (symlink を辿った後の絶対パス)。
     *
     * @return non-empty-string
     *
     * @throws HelpManifestException
     */
    private function resolveRealDirectory(string $path, string $label): string
    {
        $real = realpath($path);

        if ($real === false || ! is_dir($real)) {
            throw new HelpManifestException("{$label}をディレクトリとして解決できません: {$path}");
        }

        return $real;
    }

    /**
     * 字句の検査。ディレクトリは 2 つだけ・直下のみ・`.md` のみ・`.`/`..` を含まない。
     *
     * @throws HelpManifestException
     */
    private function assertRelativePath(string $path, bool $generated, ?int $index): void
    {
        $where = $index === null ? '' : " (sections[{$index}])";

        $expectedDir = $generated ? self::GENERATED_DIR : self::PAGES_DIR;
        $pattern = '#^'.preg_quote($expectedDir, '#').'/[A-Za-z0-9][A-Za-z0-9._-]*\.md$#';

        if (preg_match($pattern, $path) !== 1) {
            throw new HelpManifestException(
                "path が規約に合いません{$where}: {$path} — ".
                "期待する形は `{$expectedDir}/<name>.md` (直下のみ・階層不可) である。",
            );
        }

        foreach (explode('/', $path) as $segment) {
            if ($segment === '.' || $segment === '..') {
                throw new HelpManifestException("path に相対指定を含められません{$where}: {$path}");
            }
        }
    }

    /**
     * 実体の検査。symlink 不可・regular file のみ・realpath が置き場の内側にあること。
     *
     * @throws HelpManifestException
     */
    private function readResolved(string $absolute, string $relative, string $rootReal): string
    {
        if (is_link($absolute)) {
            throw new HelpManifestException("ヘルプの実体に symlink は使えません: {$relative}");
        }

        $real = realpath($absolute);

        if ($real === false) {
            throw new HelpManifestException("ヘルプの実体を解決できません: {$relative}");
        }
        if (! is_file($real)) {
            throw new HelpManifestException("ヘルプの実体が通常ファイルではありません: {$relative}");
        }
        if (! str_starts_with($real, $rootReal.DIRECTORY_SEPARATOR)) {
            throw new HelpManifestException("ヘルプの実体が置き場の外を指しています: {$relative}");
        }

        $contents = file_get_contents($real);
        if ($contents === false) {
            throw new HelpManifestException("ヘルプの実体を読めません: {$relative}");
        }

        return $contents;
    }

    /**
     * @return list<mixed>
     *
     * @throws HelpManifestException
     */
    private function readManifest(): array
    {
        $absolute = $this->rootReal().'/'.self::MANIFEST_FILE;

        if (is_link($absolute) || ! is_file($absolute)) {
            throw new HelpManifestException("manifest が通常ファイルとして存在しません: {$absolute}");
        }

        $raw = file_get_contents($absolute);
        if ($raw === false) {
            throw new HelpManifestException("manifest を読めません: {$absolute}");
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new HelpManifestException("manifest の JSON が壊れています: {$e->getMessage()}", 0, $e);
        }

        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new HelpManifestException('manifest の最上位が object ではありません。');
        }

        // ★宣言しておいて読まない値を作らない (fail-open の温床)。
        //   厳密比較なので文字列 "1" も未知の 2 も弾く。schema を変えるときは
        //   このコードを同じ変更で直すことになる = 旧コードが新 schema を誤読しない。
        /** @var mixed $schemaVersion */
        $schemaVersion = $decoded['schema_version'] ?? null;
        if ($schemaVersion !== self::SCHEMA_VERSION) {
            throw new HelpManifestException(
                'manifest の schema_version が '.self::SCHEMA_VERSION.' ではありません — '.
                'このコードが読めるのは schema_version '.self::SCHEMA_VERSION.' だけである。',
            );
        }

        if (! array_key_exists('sections', $decoded)) {
            throw new HelpManifestException('manifest に sections がありません。');
        }

        /** @var mixed $sections */
        $sections = $decoded['sections'];
        if (! is_array($sections) || ! array_is_list($sections)) {
            throw new HelpManifestException('manifest の sections が配列 (list) ではありません。');
        }

        return $sections;
    }

    /**
     * @param  array<array-key, mixed>  $entry
     * @return non-empty-string
     *
     * @throws HelpManifestException
     */
    private function requireNonEmptyString(array $entry, string $key, int $index): string
    {
        /** @var mixed $value */
        $value = $entry[$key] ?? null;

        if (! is_string($value) || $value === '') {
            throw new HelpManifestException("manifest の sections[{$index}].{$key} が非空の文字列ではありません。");
        }

        return $value;
    }
}
