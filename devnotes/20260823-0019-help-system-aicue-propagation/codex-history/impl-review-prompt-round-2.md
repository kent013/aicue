# Round 2: Round 1 の指摘への対応

Round 1 の全指摘 (Critical 2 / Warning 5) に対する判断と対応を以下に示す。
**対応マトリクス**を読んだうえで、修正後のファイル全文を再レビューせよ。

## 対応マトリクス

# 対応マトリクス: impl-review Round 1

## [Critical] `HelpRepository`: root symlink と 検査・I/O 間の TOCTOU

- 判断: **対応する (2 つに分割)**
- 根拠:
  - **root symlink は素直な穴である**。`docs/help` 自体が置き場の外への symlink だと
    `realpath()` がその外側を canonical root として返すので、`readResolved()` の
    「置き場の内側か」検査も `writeGenerated()` の実体検査も**全部通ってしまう**。
    静止状態で成立する穴であり、既存の負例が 1 つも押さえていない。塞ぐ。
  - **TOCTOU は塞げない**。PHP には `openat(2)` / `O_NOFOLLOW` に相当する API が無く、
    「検査してから開く」以外の書き方が存在しない。ここで「descriptor-relative な
    no-follow 操作へまとめる」ことはできないので、**保証を実装に合わせて狭める**
    (Codex 自身が示した 2 択のうち後者)。誇張した保証を docblock に残すほうが有害である。
- 対応内容:
  1. `rootReal()` を新設し、**置き場の最終要素が symlink なら例外**にした。
     読み取り (`read` / `readManifest` / `generatedArtifactPaths`) と書き込み
     (`writeGenerated`) の**すべて**がこの 1 か所を通る。
  2. 書き込み後の再検査に**封じ込めの検査**を足した — 書いた実体の `realpath()` が
     生成物ディレクトリ直下と一致しなければ例外 (取り消せないが「起きたことに気付く」)。
  3. `HelpRepository` の docblock と `docs/help-system.md` の「保証しないもの」に
     **TOCTOU を防がないこと**を明記した (何を守り何を守らないかを 1 行で言う)。
  4. 負例を追加: 置き場そのものが外部への symlink のとき `sections()` /
     `generatedArtifactPaths()` / `writeGenerated()` が止まり、外部ファイルが変化しないこと。

## [Critical] `McpToolMetadata`: 最上位の schema drift がパラメータ 0 件へ fail-open

- 判断: **対応する**
- 根拠: 正典 I14 は「vendor のメタデータの形が変われば**生成は止まる**(静かに欠けない)」である。
  `properties` が別のキー名へ変わった場合に「パラメータ 0 件」として緑で通るのは、
  I14 が名指しで防ごうとしている失敗の形そのものである。指摘は正しい。
- 対応内容: 最上位の形を pin した。`type` が `'object'` であることを要求し、
  最上位のキーは `type` / `properties` / `required` の 3 つに限る (未知のキーは例外)。
  vendor 更新で無害なキーが増えても止まるが、**止まるのが正しい側**である
  (例外に直し方を書いてある)。負例 3 種を追加した。

## [Warning] 想定外形状の dataset が分岐固有の文言を裏取りしていない

- 判断: **対応する**
- 根拠: 詳細設計のテスト計画が「メッセージへの要求は負例の種類ごとに分ける
  (一律の曖昧な assert を置かない)」と明記している。現状は設計違反であり、
  分岐が入れ替わっても緑になる (検出力の主張が崩れる)。
- 対応内容: dataset に**分岐固有の文言**の列を足し、各ケースでその文言を要求するようにした。

## [Warning] `McpToolScanner`: 走査根の symlink を受理する

- 判断: **対応する**
- 根拠: `HelpRepository` の root と同じ形の穴である。走査根は first-party の固定パスなので
  symlink を許す理由が無い。片方だけ塞ぐと規約が場当たりになる。
- 対応内容: 走査根の最終要素が symlink なら例外にし、負例を追加した。

## [Warning] `McpToolReferenceGenerator`: パラメータ名が無害化されていない

- 判断: **対応する (ただし無害化ではなく拒否)**
- 根拠: 指摘の事実 (名前に `|` / backtick / 改行が入ると表が壊れる) は正しい。
  ただし**説明文と名前では扱いを変えるべきである**。説明文は人が書いた散文なので
  表示用に無害化してよいが、名前は first-party の schema のキー = 識別子であり、
  静かに別名へ書き換えると**生成物の名前と実装の名前がずれる** (この機構の目的そのものを壊す)。
  backtick は code span の中では逆斜線で逃がせないので、そもそも無害化できない。
- 対応内容: 表を壊す文字を含む名前は**例外で止める** (直し方を例外に書く)。負例 2 種を追加した。

## [Warning] `docs/help-system.md` の保証が実装より強い

- 判断: **対応する**
- 根拠: 上の 2 つの Critical への対応で実装側の保証範囲が確定したので、文書を実装に合わせる。
- 対応内容: 「保証しないもの」に TOCTOU を明記し、置き場・走査根の symlink 拒否と
  最上位 schema の pin を規約として書いた。

## [Warning] 負例の不足 (root symlink / 走査根 symlink / 最上位 drift)

- 判断: **対応する**
- 対応内容: 上の各項目で追加済み (合計 8 本の負例を追加)。


## 検証結果 (修正後)

```
composer phpstan                       -> [OK] No errors
vendor/bin/pint --test                 -> passed
php artisan help:build --check         -> exit 0 (_generated/mcp-tools.md .. up_to_date)
composer test -- --filter='Help|McpTool'
  -> {"tool":"pest","result":"passed","tests":125,"passed":125,"assertions":343}
```

(Round 1 時点は 118 tests / 299 assertions。負例を 8 本足して 125 / 343 になった。)

## 修正後のファイル

### `app/Services/Help/HelpRepository.php` (修正後の全文)

```php
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
 *     (置き場・生成物ディレクトリ・生成物のいずれも symlink でないこと、
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

    /** @param non-empty-string $root `docs/help/` の絶対パス */
    public function __construct(private readonly string $root) {}

    /**
     * 置き場の実体 (canonical path)。**読み書きのすべてがここを通る**。
     *
     * ★置き場そのものが symlink なら例外にする。辿ってしまうと `realpath()` が
     *   外側を canonical root として返し、「置き場の内側か」の検査も
     *   生成物ディレクトリの一致検査も**全部通ってしまう** (穴が塞がった顔をする)。
     *
     * @return non-empty-string
     *
     * @throws HelpManifestException
     */
    private function rootReal(): string
    {
        if (is_link($this->root)) {
            throw new HelpManifestException(
                "ヘルプの置き場に symlink は使えません: {$this->root} — 実ディレクトリに置き換えること。",
            );
        }

        return $this->resolveRealDirectory($this->root, 'ヘルプの置き場');
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
     * ★書き込みの**後**にもう一度実体を検査する (作成の途中で入れ替えられた形を残さない)。
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

```
### `app/Services/Help/McpToolMetadata.php` (修正後の全文)

```php
<?php

declare(strict_types=1);

namespace App\Services\Help;

use App\Mcp\Tools\AppMcpTool;
use Illuminate\JsonSchema\JsonSchema as JsonSchemaFactory;
use RuntimeException;

/**
 * MCP ツール 1 本のメタデータ。**vendor の実行時出力を first-party の型へ閉じ込める境界**である。
 *
 * ★**正規化する。閉じた集合で弾かない** (正典の設計判断) — vendor の実行時出力は
 *   first-party の型保証の外にあり、閉集合で弾くと正当なツール定義が生成を止めるためである。
 * ★**静かに欠けるより止まる**。想定外の形は握り潰さず例外にし、
 *   例外には**対象クラス / 不正だった箇所 / 直し方**を必ず含める。
 * ★**組み立ての入口は 2 つ**である。`fromTool()` が実行時の経路、`fromSchema()` が
 *   直列化済みの schema を受ける境界そのものである (後者を public にしてあるのは、
 *   vendor が出しえない形も含めた負例を検査から与えられるようにするためで、
 *   **前者は後者へ委譲する** = 検査した経路と実行時の経路が同一になる)。
 * ★**最上位の形は pin する**。`type` が `'object'` であることと、最上位のキーが
 *   `type` / `properties` / `required` の 3 つに限られることを要求する。
 *   これを見ないと、vendor が `properties` を別のキー名へ変えたときに
 *   「パラメータ 0 件」として**静かに緑で通る** (I14 が名指しで防ごうとしている失敗の形)。
 *   vendor 更新で無害なキーが増えても止まるが、**止まるのが正しい側**である。
 * ★**保証しないもの**: 説明文・パラメータ説明の内容の妥当性は見ない (存在と型だけを見る)。
 */
final readonly class McpToolMetadata
{
    /**
     * vendor の直列化が出す最上位のキー。**これ以外が現れたら形が変わったとみなす**。
     *
     * @var list<string>
     */
    private const array KNOWN_TOP_LEVEL_KEYS = ['type', 'properties', 'required'];

    /**
     * @param  class-string<AppMcpTool>  $className
     * @param  non-empty-string  $name
     * @param  list<McpToolParameter>  $parameters  schema の宣言順
     */
    public function __construct(
        public string $className,
        public string $name,
        public string $description,
        public array $parameters,
    ) {}

    /**
     * @param  class-string<AppMcpTool>  $className
     *
     * @throws RuntimeException vendor のメタデータが想定外の形のとき
     */
    public static function fromTool(AppMcpTool $tool, string $className): self
    {
        $name = $tool->name();
        if ($name === '') {
            throw new RuntimeException("{$className}: name() が空文字です — ToolName enum の値を返すこと。");
        }

        /** @var array<string, mixed> $schema */
        $schema = JsonSchemaFactory::object($tool->schema(...))->toArray();

        return self::fromSchema($schema, $className, $name, $tool->description());
    }

    /**
     * 直列化済みの JSON Schema からメタデータを組み立てる (正規化の境界)。
     *
     * @param  array<string, mixed>  $schema
     * @param  class-string<AppMcpTool>  $className
     * @param  non-empty-string  $name
     *
     * @throws RuntimeException
     */
    public static function fromSchema(array $schema, string $className, string $name, string $description): self
    {
        return new self(
            className: $className,
            name: $name,
            description: $description,
            parameters: self::parametersFrom($schema, $className),
        );
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return list<McpToolParameter>
     *
     * @throws RuntimeException
     */
    private static function parametersFrom(array $schema, string $className): array
    {
        $hint = 'vendor (laravel/mcp / illuminate/json-schema) の出力形が変わった可能性がある。'.
            'McpToolMetadata の正規化を新しい形に合わせて直すこと。';

        self::assertTopLevelShape($schema, $className, $hint);

        // vendor 実読: properties は 0 件のとき、required は必須 0 件のとき、いずれもキーごと消える。
        $hasProperties = array_key_exists('properties', $schema);
        $hasRequired = array_key_exists('required', $schema);

        // ★「required はあるが properties が無い」は vendor では起きえない形である。
        //   これを 0 件として黙って受けると、必須パラメータが**静かに欠ける**。
        if ($hasRequired && ! $hasProperties) {
            throw new RuntimeException(
                "{$className}: schema に required があるのに properties がありません — {$hint}",
            );
        }

        /** @var mixed $properties */
        $properties = $hasProperties ? $schema['properties'] : [];
        if (! is_array($properties)) {
            throw new RuntimeException("{$className}: schema の properties が配列ではありません — {$hint}");
        }

        $required = [];
        /** @var mixed $rawRequired */
        $rawRequired = $hasRequired ? $schema['required'] : [];
        if (! is_array($rawRequired) || ! array_is_list($rawRequired)) {
            throw new RuntimeException("{$className}: schema の required が list ではありません — {$hint}");
        }
        /** @var mixed $key */
        foreach ($rawRequired as $key) {
            if (! is_string($key) || $key === '') {
                throw new RuntimeException(
                    "{$className}: schema の required に非空の文字列でない要素があります — {$hint}",
                );
            }
            if (isset($required[$key])) {
                throw new RuntimeException(
                    "{$className}: schema の required に重複があります: {$key} — {$hint}",
                );
            }
            if (! array_key_exists($key, $properties)) {
                throw new RuntimeException(
                    "{$className}: schema の required `{$key}` が properties にありません — {$hint}",
                );
            }
            $required[$key] = true;
        }

        $parameters = [];
        /** @var mixed $definition */
        foreach ($properties as $name => $definition) {
            if (! is_string($name) || $name === '') {
                throw new RuntimeException(
                    "{$className}: schema の properties にパラメータ名が非空の文字列でない要素があります — {$hint}",
                );
            }
            if (! is_array($definition)) {
                throw new RuntimeException(
                    "{$className}: schema のパラメータ `{$name}` の定義が配列ではありません — {$hint}",
                );
            }

            $parameters[] = new McpToolParameter(
                name: $name,
                type: self::normalizeType($definition['type'] ?? null, $name, $className),
                required: isset($required[$name]),
                description: self::normalizeDescription($definition['description'] ?? null, $name, $className),
            );
        }

        return $parameters;
    }

    /**
     * 最上位の形を検査する。**「パラメータ 0 件」と「形が変わった」を区別するための段**である。
     *
     * @param  array<string, mixed>  $schema
     *
     * @throws RuntimeException
     */
    private static function assertTopLevelShape(array $schema, string $className, string $hint): void
    {
        /** @var mixed $type */
        $type = $schema['type'] ?? null;
        if ($type !== 'object') {
            throw new RuntimeException(
                "{$className}: schema の最上位の type が 'object' ではありません — {$hint}",
            );
        }

        $unknown = array_values(array_diff(array_keys($schema), self::KNOWN_TOP_LEVEL_KEYS));
        if ($unknown !== []) {
            throw new RuntimeException(
                "{$className}: schema の最上位に未知のキーがあります: ".implode(', ', $unknown)." — {$hint}",
            );
        }
    }

    /**
     * 型を**表示用の文字列へ正規化する** (閉じた集合で弾かない)。
     *
     * @return non-empty-string
     *
     * @throws RuntimeException 文字列でも文字列の配列でもないとき
     */
    private static function normalizeType(mixed $type, string $name, string $className): string
    {
        if (is_string($type) && $type !== '') {
            return $type;
        }

        if (is_array($type)) {
            // ★union / nullable は **list** で来る (vendor 実読)。
            //   associative を受けてキーを捨てると、形の変化が静かに通る。
            if (! array_is_list($type) || $type === []) {
                throw new RuntimeException(
                    "{$className}: パラメータ `{$name}` の type が非空の list ではありません — ".
                    'vendor の出力形が変わった可能性がある。McpToolMetadata::normalizeType を直すこと。',
                );
            }

            $parts = [];
            /** @var mixed $part */
            foreach ($type as $part) {
                if (! is_string($part) || $part === '') {
                    throw new RuntimeException(
                        "{$className}: パラメータ `{$name}` の type に非空の文字列でない要素があります — ".
                        'vendor の出力形が変わった可能性がある。McpToolMetadata::normalizeType を直すこと。',
                    );
                }
                $parts[] = $part;
            }

            return implode('|', $parts);
        }

        if ($type === null) {
            return '(未宣言)';
        }

        throw new RuntimeException(
            "{$className}: パラメータ `{$name}` の type が文字列でも文字列の配列でもありません — ".
            'vendor の出力形が変わった可能性がある。McpToolMetadata::normalizeType を直すこと。',
        );
    }

    /** @throws RuntimeException */
    private static function normalizeDescription(mixed $description, string $name, string $className): string
    {
        if ($description === null) {
            return '';
        }
        if (is_string($description)) {
            return $description;
        }

        throw new RuntimeException(
            "{$className}: パラメータ `{$name}` の description が文字列ではありません — ".
            'vendor の出力形が変わった可能性がある。McpToolMetadata::normalizeDescription を直すこと。',
        );
    }
}

```
### `app/Services/Help/McpToolScanner.php` (修正後の全文)

```php
<?php

declare(strict_types=1);

namespace App\Services\Help;

use App\Mcp\Tools\AppMcpTool;
use ReflectionClass;
use RuntimeException;

/**
 * `app/Mcp/Tools/` を走査して MCP ツールの具象クラスを列挙する。
 *
 * ★**走査根は 1 本** (`app/Mcp/Tools/` の直下)。git 追跡下 PHP 全数より狭いので
 *   `Tests\Support\TrackedPhpSourceFiles` は共用しない。**存在しない根は fail-fast** で落とす。
 * ★**基底クラスは `App\Mcp\Tools\AppMcpTool`** — 正典 (裁定 AG-100) が
 *   「移植時に各リポジトリの基底クラスへ差し替える 1 行」と名指しした箇所である。
 * ★**deny-by-default**: 走査根の直下に置いた具象クラスは、基底を継承していなければ**例外で止まる**。
 *   「走査対象から外す」口を持たない (外したければファイルを別の場所へ移すしかない)。
 * ★**母集団の非空は本走査器の契約である**。0 件は「違反 0 件」ではなく走査の破損なので例外にする。
 * ★**保証しないもの**:
 *   - 下位ディレクトリは見ない (`app/Mcp/Tools/` は現に平坦であり、階層を作る予定も無い)。
 *   - 1 ファイルに複数のクラスを書いた場合、ファイル名と同名のクラスしか見ない。
 *   - サーバへの登録有無は見ない。走査集合と登録集合の一致は
 *     `tests/Architecture/McpToolReferencePopulationTest.php` の担当である。
 *   - git 未追跡 (add 前) のファイルも走査する (gate の境界は commit / CI なので実効差は無い)。
 *   - 実体の検査は POSIX 前提である (`is_link()` / `realpath()`)。
 *   - **検査と読み込みの間の差し替え (TOCTOU) は防げない** (`HelpRepository` と同じ理由)。
 *     保証するのは静止状態で走査根・各ファイルが symlink でないことまでである。
 */
final class McpToolScanner
{
    private const string NAMESPACE_PREFIX = 'App\\Mcp\\Tools\\';

    /** @param non-empty-string $root `app/Mcp/Tools/` の絶対パス */
    public function __construct(private readonly string $root) {}

    /**
     * @return list<class-string<AppMcpTool>> クラス名の昇順
     *
     * @throws RuntimeException 走査根が無い / クラスを解決できない / 基底を継承しない / 母集団が空
     */
    public function concreteToolClasses(): array
    {
        // ★走査根そのものが symlink なら止める。`is_dir()` / `scandir()` / autoload /
        //   Reflection の `realpath()` はすべて symlink を辿るので、外部への symlink でも
        //   「実体の一致」検査を通過してしまう (走査根が固定の first-party パスである前提が崩れる)。
        if (is_link($this->root)) {
            throw new RuntimeException(
                "MCP ツールの走査根に symlink は使えません: {$this->root} — 実ディレクトリを指すこと。",
            );
        }

        if (! is_dir($this->root)) {
            throw new RuntimeException(
                "MCP ツールの走査根が存在しません: {$this->root} — ".
                'ディレクトリを移動・改名したなら McpToolScanner の配線を同じ変更で直すこと。',
            );
        }

        $entries = scandir($this->root);
        if ($entries === false) {
            throw new RuntimeException("MCP ツールの走査根を走査できません: {$this->root}");
        }

        $classes = [];

        foreach ($entries as $entry) {
            if (! str_ends_with($entry, '.php')) {
                continue;
            }

            $absolute = $this->root.'/'.$entry;
            if (! is_file($absolute) || is_link($absolute)) {
                throw new RuntimeException("MCP ツールの実体が通常ファイルではありません: {$absolute}");
            }

            $class = self::NAMESPACE_PREFIX.substr($entry, 0, -4);

            if (! class_exists($class)) {
                throw new RuntimeException(
                    "MCP ツールのクラスを解決できません: {$class} ({$absolute}) — ".
                    'ファイル名とクラス名 / namespace が一致しているか確認すること。',
                );
            }

            $reflection = new ReflectionClass($class);

            // ★走査したファイルと、autoload が解決したクラスの実体が同じであることを要求する。
            //   `class_exists()` は Composer autoload から**別のファイル**をロードしうるので、
            //   これを見ないと「一時 root の見本を走査しているつもりで本物を見ている」
            //   状態に気付けず、負例が空振りする (検出力の主張が崩れる)。
            $declaredIn = $reflection->getFileName();
            $declaredReal = $declaredIn === false ? false : realpath($declaredIn);
            $scannedReal = realpath($absolute);

            if ($declaredReal === false || $scannedReal === false || $declaredReal !== $scannedReal) {
                throw new RuntimeException(
                    "{$class} の実体が走査中のファイルと一致しません ".
                    '(走査: '.$absolute.' / 解決: '.var_export($declaredIn, true).') — '.
                    'ファイル名とクラス名 / namespace が一致しているか、'.
                    '同名クラスが別の場所から autoload されていないか確認すること。',
                );
            }

            if ($reflection->isAbstract() || $reflection->isInterface()) {
                continue;
            }

            if (! $reflection->isSubclassOf(AppMcpTool::class)) {
                throw new RuntimeException(
                    "{$class} は ".AppMcpTool::class.' を継承していません — '.
                    'app/Mcp/Tools/ 直下には MCP ツールだけを置くこと '.
                    '(補助クラスは別の namespace へ移すこと)。',
                );
            }

            /** @var class-string<AppMcpTool> $class */
            $classes[] = $class;
        }

        if ($classes === []) {
            throw new RuntimeException(
                "MCP ツールが 1 件も見つかりません: {$this->root} — ".
                '母集団が空なのは「違反 0 件」ではなく走査の破損である。',
            );
        }

        sort($classes, SORT_STRING);

        return $classes;
    }
}

```
### `app/Services/Help/Generators/McpToolReferenceGenerator.php` (修正後の全文)

```php
<?php

declare(strict_types=1);

namespace App\Services\Help\Generators;

use App\Mcp\Tools\AppMcpTool;
use App\Services\Help\McpToolMetadata;
use App\Services\Help\McpToolParameter;
use App\Services\Help\McpToolScanner;
use Illuminate\Contracts\Container\Container;
use RuntimeException;
use Webmozart\Assert\Assert;

/**
 * MCP ツール一覧の Markdown を実装から生成する (正典 AG-100 の還流対象 (2))。
 *
 * ★出力は**決定的**である (ツールは name 昇順、パラメータは schema の宣言順、
 *   日時・環境変数のような可変要素を一切含めない)。同じ実装からは同じバイト列が出る。
 * ★**説明と名前で扱いを分ける**。説明文は人が書いた散文なので表示用に無害化する
 *   (縦棒と改行を潰す)。**パラメータ名は無害化せず、表を壊す文字を含むなら例外で止める** —
 *   名前は first-party の schema のキー (識別子) であり、静かに別名へ書き換えると
 *   生成物の名前と実装の名前がずれる (この機構の目的そのものを壊す)。
 *   backtick は code span の中では逆斜線で逃がせないので、そもそも無害化できない。
 * ★**保証しないもの**: 説明文の質は見ない。サーバに登録されているかも見ない
 *   (走査集合と登録集合の一致は `McpToolReferencePopulationTest` の担当)。
 */
final class McpToolReferenceGenerator implements HelpGenerator
{
    public function __construct(
        private readonly McpToolScanner $scanner,
        private readonly Container $container,
    ) {}

    public function key(): string
    {
        return 'mcp-tools';
    }

    public function generate(): string
    {
        $metadata = [];

        foreach ($this->scanner->concreteToolClasses() as $class) {
            /** @var mixed $tool */
            $tool = $this->container->make($class);
            Assert::isInstanceOf($tool, AppMcpTool::class);

            $metadata[] = McpToolMetadata::fromTool($tool, $class);
        }

        usort($metadata, static fn (McpToolMetadata $a, McpToolMetadata $b): int => strcmp($a->name, $b->name));

        $lines = [
            '<!-- 自動生成: `php artisan help:build` が生成する。手で編集しない。 -->',
            '<!-- 生成器: mcp-tools ('.self::class.') -->',
            '',
            '# MCP ツールリファレンス',
            '',
            '本アプリが MCP サーバー (`App\Mcp\Servers\AppMcpServer`) 経由で公開しているツールの一覧である。',
            '実装 (`app/Mcp/Tools/`) から自動生成しているので、手書きの説明が実装からずれることはない。',
            '',
            '現在のツール数: '.count($metadata),
        ];

        foreach ($metadata as $tool) {
            $lines[] = '';
            $lines[] = '## `'.$tool->name.'`';
            $lines[] = '';
            $lines[] = self::escapeCell($tool->description);

            if ($tool->parameters === []) {
                $lines[] = '';
                $lines[] = 'パラメータなし。';

                continue;
            }

            $lines[] = '';
            $lines[] = '| パラメータ | 型 | 必須 | 説明 |';
            $lines[] = '|---|---|---|---|';
            foreach ($tool->parameters as $parameter) {
                $lines[] = self::parameterRow($parameter, $tool->className);
            }
        }

        return implode("\n", $lines)."\n";
    }

    /** @throws RuntimeException 名前が表を壊す文字を含むとき */
    private static function parameterRow(McpToolParameter $parameter, string $className): string
    {
        if (preg_match('/[|`\r\n]/', $parameter->name) === 1) {
            throw new RuntimeException(
                "{$className}: パラメータ名 `{$parameter->name}` が表を壊す文字 ".
                '(縦棒 / backtick / 改行) を含みます — MCP ツールの schema のキーから取り除くこと '.
                '(名前は無害化しない。静かに別名へ書き換えると生成物と実装がずれる)。',
            );
        }

        return sprintf(
            '| `%s` | %s | %s | %s |',
            $parameter->name,
            self::escapeCell($parameter->type),
            $parameter->required ? '必須' : '任意',
            self::escapeCell($parameter->description),
        );
    }

    /** 表のセルを壊す縦棒と改行を無害化する (`docs/template-divergence.md` と同じ方針)。 */
    private static function escapeCell(string $value): string
    {
        return str_replace(['|', "\r\n", "\n", "\r"], ['\\|', ' ', ' ', ' '], $value);
    }
}

```
### `docs/help-system.md` (修正後の全文)

```markdown
# ヘルプ機構 (置き場と規約)

`docs/help/` の置き場・宣言・生成物の運用契約の**正本**である。
機構の実装は `app/Services/Help/` と `app/Console/Commands/Help/HelpBuildCommand.php`。

## これは何か

「ヘルプ本文を置く場所」と「実装から自動生成する節」を 1 つの宣言 (manifest) で扱い、
**生成物が実装からずれたまま気付かれない形を作らない**ための機構である。

- **取り込み基盤**: `HelpRepository` が `docs/help/` を読み書きする唯一の層。
- **生成器の台帳**: `HelpGeneratorRegistry::GENERATORS` が生成器の全数申告。
- **唯一の入口**: `php artisan help:build` (生成) / `php artisan help:build --check` (鮮度検査)。
- **鮮度ゲート**: `tests/Feature/Help/HelpBuildFreshnessTest.php` が `composer test` (= CI) で
  `--check` を走らせる。生成物が古いと**赤くなる**。

## 置き場の規約

- `docs/help/manifest.json` が**宣言の正本**である。ここに無い節は存在しない。
- `schema_version` は `1` で**厳密一致**する (文字列 `"1"` も未知の `2` も読まずに落ちる)。
- `path` の値域は `_generated/<name>.md` または `pages/<name>.md` の **2 通りだけ**。
  `<name>` は `[A-Za-z0-9][A-Za-z0-9._-]*`。**どちらも直下のみで階層を許さない**
  (階層を許すと孤児の検査に再帰走査が要る)。
- `generator` キーを**持つ節が生成物**、持たない節が**手書きページ**である。
  `generator` の値は `HelpGeneratorRegistry::GENERATORS` のキーと**完全一致**する
  (両方向。片側にしか無ければ `help:build` も `--check` も止まる = deny-by-default)。
  **1 つの生成器を参照できる節は 1 つだけ**である。
- 生成物は `php artisan help:build` が書き、**手で編集しない**
  (生成物の先頭にその旨のコメントが入る)。
- **手書きページは 0 件でよい**。0 件でも `help:build --check` は成功する
  (ヘルプ本文の未整備を赤字扱いしない)。
- `docs/help/_generated/` 直下に manifest 未宣言の `.md` があれば **Orphan** として報告する。
  **`help:build` は孤児を削除しない** — 人が消すか manifest へ宣言する。
- **置き場 (`docs/help/`)・生成物ディレクトリ・生成物のいずれも symlink であってはならない**。
  1 つでも symlink なら例外で止まる (置き場そのものを辿ると `realpath()` が外側を
  canonical root として返し、「置き場の内側か」の検査が意味を失う)。
- 生成物ディレクトリにディレクトリ・`.md` 以外・通常ファイルでない実体があれば
  **例外で止まる** (字句の規約を実体でも守る)。

## 報告の種別と終了コード

| 種別 | 意味 | 対処 |
|---|---|---|
| `up_to_date` | 生成物が実装と一致している | — |
| `stale` | 生成物が古い | `php artisan help:build` を実行して差分をコミットする |
| `missing` | 宣言された生成物が無い | `php artisan help:build` を実行する |
| `orphan` | manifest に無い生成物が残っている | 削除するか manifest へ宣言する |

**終了コードは 0 と 1 の 2 値だけ**である (例外も 1 へ畳む)。
`up_to_date` 以外が 1 件でもあれば 1 になる。

## 生成器を足すとき

1. `App\Services\Help\Generators\HelpGenerator` を実装する (`key()` と `generate()`)。
2. `HelpGeneratorRegistry::GENERATORS` へ 1 行足す。
3. `docs/help/manifest.json` へ節を 1 つ足す (`generator` に同じキー)。
4. `php artisan help:build` を実行し、生成物を**同じコミットに含める**。

2 と 3 のどちらかを忘れると `help:build` 自体が止まる (意図した fail-closed である)。

## 現在の生成器

| キー | 実装 | 生成物 | 入力 |
|---|---|---|---|
| `mcp-tools` | `App\Services\Help\Generators\McpToolReferenceGenerator` | `docs/help/_generated/mcp-tools.md` | `app/Mcp/Tools/` の具象ツール (`McpToolScanner` が走査) |

`McpToolScanner` の走査根は `app/Mcp/Tools/` **直下だけ**で、基底
`App\Mcp\Tools\AppMcpTool` を継承しない具象クラスを見つけたら**例外で止まる**
(補助クラスは別の namespace へ置くこと)。母集団が 0 件になることも
「違反 0 件」ではなく走査の破損として例外にする。

vendor (`laravel/mcp` / `illuminate/json-schema`) が返すメタデータの形は、
**最上位を pin し、値は閉じた集合で弾かずに表示用へ正規化する**。

- 最上位は `type === 'object'` であることと、キーが `type` / `properties` / `required` の
  3 つに限られることを要求する。**vendor が `properties` を別のキー名へ変えたら止まる**
  (これを見ないと「パラメータ 0 件」として静かに緑で通り、生成物から全パラメータが消える)。
  vendor 更新で無害なキーが増えても止まるが、**止まるのが正しい側**である。
- パラメータの `type` は文字列でも文字列の配列 (union / nullable) でも受け、
  `|` 連結の表示用文字列へ正規化する。未宣言は `(未宣言)`。
- **説明文は無害化し、パラメータ名は無害化しない**。説明の縦棒と改行は表を壊さないよう
  潰すが、名前が表を壊す文字 (`|` / backtick / 改行) を含むときは**例外で止める** —
  名前は実装の識別子であり、静かに別名へ書き換えると生成物と実装がずれる。
- 想定外の形はすべて**静かに欠けずに止まる**
  (例外に対象クラス・不正だった箇所・直し方が入る)。

## 保証しないもの (誇張しない)

- **表示面を持たない**。HTTP でヘルプを配る route も画面も無く、Markdown を HTML へ
  変換もしない (変換先が無い)。
- **ヘルプ本文の中身の品質・網羅性は検査しない**。機構が見るのは置き場の規約と
  生成物の鮮度だけである。
- **`pages/` 配下の未宣言ファイルは孤児として扱わない** (手書きの下書きを赤にしないため)。
  孤児検査の母集団は生成物ディレクトリの直下だけである。
- 実体の検査は **POSIX 前提** (`is_link()` / `realpath()`) である。Windows は対象外。
- **検査と入出力の間の差し替え (TOCTOU) は防げない**。PHP に `openat(2)` / `O_NOFOLLOW`
  相当の API が無いため、「実体を検査してから開く」以外の書き方が存在しない。
  保証するのは**静止状態での封じ込め**までで、書き込みの最中にファイルや親ディレクトリを
  symlink へ差し替える攻撃者は脅威モデルに含めない (これは開発者の作業ツリーで走る生成器である)。
  書き込み後の検査は**取り消しではなく検出**である。
- **保証しないものの網羅的な正本は各クラスの docblock** であり、本書はその要約である
  (2 か所に同じ一覧を書くと必ず食い違う)。

```
### `tests/Unit/Services/Help/McpToolReferenceGeneratorTest.php` (修正後の全文)

```php
<?php

declare(strict_types=1);

use App\Enums\Mcp\ToolName;
use App\Mcp\Tools\AppMcpTool;
use App\Mcp\Tools\WhoamiTool;
use App\Services\Help\Generators\McpToolReferenceGenerator;
use App\Services\Help\McpToolMetadata;
use App\Services\Help\McpToolScanner;
use App\Services\Mcp\Auth\McpAuthorizationContext;
use App\Services\Mcp\McpIdempotencyService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Tests\Support\Help\HelpTestTree;

/*
 * MCP ツール一覧の生成 (I2) と、vendor のメタデータの形が変わったら
 * **静かに欠けずに止まる** こと (I14) を固定する。
 */

afterEach(function (): void {
    HelpTestTree::cleanup();
});

/** 一時走査根を使う生成器を組み立てる。 */
function helpGeneratorOver(string $root): McpToolReferenceGenerator
{
    return new McpToolReferenceGenerator(new McpToolScanner($root), app());
}

test('生成器のキーは manifest と突き合わせる `mcp-tools` である', function (): void {
    expect(app(McpToolReferenceGenerator::class)->key())->toBe('mcp-tools');
});

test('出力は決定的である (同じ実装からは同じバイト列が出る)', function (): void {
    $generator = app(McpToolReferenceGenerator::class);

    expect($generator->generate())->toBe($generator->generate());
});

test('出力は先頭に自動生成の断り書きを持ち、末尾は改行 1 個で終わる', function (): void {
    $markdown = app(McpToolReferenceGenerator::class)->generate();

    expect($markdown)->toStartWith('<!-- 自動生成:')
        ->and($markdown)->toEndWith("\n")
        ->and(str_ends_with($markdown, "\n\n"))->toBeFalse();
});

test('パラメータを持つツールは表で、持たないツールは「パラメータなし。」で書かれる', function (): void {
    $root = HelpTestTree::makeDir('mcp-generator-shape');
    HelpTestTree::writeToolFixture($root, 'GeneratorFixtureNoParamTool', 'Whoami');
    HelpTestTree::writeToolFixture(
        $root,
        'GeneratorFixtureParamTool',
        'ListProjects',
        'パラメータ付きの見本',
        "return ['project_id' => \$schema->integer()->description('Project ID')->required(), 'page' => \$schema->integer()];",
    );

    $markdown = helpGeneratorOver($root)->generate();

    expect($markdown)->toContain('現在のツール数: 2')
        ->and($markdown)->toContain('パラメータなし。')
        ->and($markdown)->toContain('| パラメータ | 型 | 必須 | 説明 |')
        ->and($markdown)->toContain('| `project_id` | integer | 必須 | Project ID |')
        ->and($markdown)->toContain('| `page` | integer | 任意 |  |');
});

test('説明の縦棒と改行は表を壊さないように無害化される', function (): void {
    $root = HelpTestTree::makeDir('mcp-generator-escape');
    HelpTestTree::writeToolFixture(
        $root,
        'GeneratorFixtureEscapeTool',
        'Whoami',
        "縦棒 | と\n改行を含む説明",
    );

    $markdown = helpGeneratorOver($root)->generate();

    expect($markdown)->toContain('縦棒 \\| と 改行を含む説明')
        ->and($markdown)->not->toContain("縦棒 | と\n改行");
});

test('ツールは name の昇順で並ぶ', function (): void {
    $root = HelpTestTree::makeDir('mcp-generator-order');
    HelpTestTree::writeToolFixture($root, 'GeneratorFixtureOrderWhoamiTool', 'Whoami');
    HelpTestTree::writeToolFixture($root, 'GeneratorFixtureOrderListItemsTool', 'ListItems');

    $markdown = helpGeneratorOver($root)->generate();

    expect(strpos($markdown, '## `list-items`'))->toBeLessThan((int) strpos($markdown, '## `whoami`'));
});

test('type が文字列の配列 (union / nullable) なら縦棒連結の表示文字列へ正規化される', function (): void {
    $metadata = McpToolMetadata::fromSchema(
        ['type' => 'object', 'properties' => ['nick' => ['type' => ['string', 'null']]]],
        WhoamiTool::class,
        'fixture',
        '',
    );

    expect($metadata->parameters[0]->type)->toBe('string|null');
});

test('type が未宣言なら (未宣言) へ正規化される (閉じた集合で弾かない)', function (): void {
    $metadata = McpToolMetadata::fromSchema(
        ['type' => 'object', 'properties' => ['loose' => ['description' => 'なんでも']]],
        WhoamiTool::class,
        'fixture',
        '',
    );

    expect($metadata->parameters[0]->type)->toBe('(未宣言)')
        ->and($metadata->parameters[0]->description)->toBe('なんでも')
        ->and($metadata->parameters[0]->required)->toBeFalse();
});

test('properties も required も無い schema はパラメータ 0 件として受け入れる', function (): void {
    $metadata = McpToolMetadata::fromSchema(['type' => 'object'], WhoamiTool::class, 'fixture', '');

    expect($metadata->parameters)->toBe([]);
});

dataset('vendor メタデータの想定外の形', [
    // [schema, 分岐固有の文言, 追加で必ず現れる語 (パラメータ名 / キー名)]
    '最上位の type が無い' => [
        ['properties' => ['a' => ['type' => 'string']]],
        '最上位の type',
        [],
    ],
    '最上位の type が object でない' => [
        ['type' => 'array', 'properties' => ['a' => ['type' => 'string']]],
        '最上位の type',
        [],
    ],
    '最上位に未知のキーがある (properties の改名)' => [
        ['type' => 'object', 'fields' => ['a' => ['type' => 'string']]],
        '最上位に未知のキーがあります',
        ['fields'],
    ],
    'type が数値' => [
        ['type' => 'object', 'properties' => ['a' => ['type' => 1]]],
        'の type が文字列でも文字列の配列でもありません',
        ['a'],
    ],
    'type が object (連想配列)' => [
        ['type' => 'object', 'properties' => ['a' => ['type' => ['first' => 'string']]]],
        'の type が非空の list ではありません',
        ['a'],
    ],
    'type が空配列' => [
        ['type' => 'object', 'properties' => ['a' => ['type' => []]]],
        'の type が非空の list ではありません',
        ['a'],
    ],
    'type の要素が文字列でない' => [
        ['type' => 'object', 'properties' => ['a' => ['type' => ['string', 3]]]],
        'の type に非空の文字列でない要素があります',
        ['a'],
    ],
    'description が文字列でない' => [
        ['type' => 'object', 'properties' => ['a' => ['type' => 'string', 'description' => ['x']]]],
        'の description が文字列ではありません',
        ['a'],
    ],
    'パラメータ定義が配列でない' => [
        ['type' => 'object', 'properties' => ['a' => 'string']],
        'の定義が配列ではありません',
        ['a'],
    ],
    'properties が配列でない' => [
        ['type' => 'object', 'properties' => 'nope'],
        'schema の properties が配列ではありません',
        [],
    ],
    'required が list でない' => [
        ['type' => 'object', 'properties' => ['a' => ['type' => 'string']], 'required' => ['a' => true]],
        'schema の required が list ではありません',
        [],
    ],
    'required の要素が空文字' => [
        ['type' => 'object', 'properties' => ['a' => ['type' => 'string']], 'required' => ['']],
        'schema の required に非空の文字列でない要素があります',
        [],
    ],
    'required に重複がある' => [
        ['type' => 'object', 'properties' => ['a' => ['type' => 'string']], 'required' => ['a', 'a']],
        'schema の required に重複があります',
        ['a'],
    ],
    'required が properties に無い名前を指す' => [
        ['type' => 'object', 'properties' => ['a' => ['type' => 'string']], 'required' => ['b']],
        'が properties にありません',
        ['b'],
    ],
    'required があるのに properties が無い' => [
        ['type' => 'object', 'required' => ['a']],
        'schema に required があるのに properties がありません',
        [],
    ],
]);

test('想定外の形は静かに欠けず、分岐ごとに固有の文言で止まる', function (array $schema, string $branchPhrase, array $expectedMentions): void {
    $call = fn (): McpToolMetadata => McpToolMetadata::fromSchema($schema, WhoamiTool::class, 'fixture', '');

    expect($call)->toThrow(RuntimeException::class);

    try {
        $call();
    } catch (RuntimeException $e) {
        $message = $e->getMessage();

        // 全負例で共通: 対象クラス名 / 何が起きたか (vendor の形が変わった) / 直し方 (直す先の型)
        expect($message)->toContain(WhoamiTool::class)
            ->and($message)->toContain('vendor')
            ->and($message)->toContain('McpToolMetadata');

        // ★分岐固有の文言 — これが無いと別の共通例外へ流れても緑になる (検出力の主張が崩れる)
        expect($message)->toContain($branchPhrase);

        // 特定できる負例のみ: パラメータ名 / キー名
        foreach ($expectedMentions as $mention) {
            expect($message)->toContain($mention);
        }
    }
})->with('vendor メタデータの想定外の形');

test('name が空文字のツールは止まる', function (): void {
    $tool = new class(app(McpIdempotencyService::class)) extends AppMcpTool
    {
        public function name(): string
        {
            return '';
        }

        public function schema(JsonSchema $schema): array
        {
            return [];
        }

        protected function toolName(): ToolName
        {
            return ToolName::Whoami;
        }

        protected function runTool(
            Request $request,
            McpAuthorizationContext $ctx,
        ): array {
            return [];
        }
    };

    expect(fn (): McpToolMetadata => McpToolMetadata::fromTool($tool, WhoamiTool::class))
        ->toThrow(RuntimeException::class, 'name() が空文字です');
});

test('パラメータ名に縦棒が入っていたら生成は止まる (静かに別名へ書き換えない)', function (): void {
    $root = HelpTestTree::makeDir('mcp-generator-pipe-name');
    HelpTestTree::writeToolFixture(
        $root,
        'GeneratorFixturePipeNameTool',
        'Whoami',
        'fixture tool',
        "return ['a|b' => \$schema->integer()];",
    );

    expect(fn (): string => helpGeneratorOver($root)->generate())
        ->toThrow(RuntimeException::class, '表を壊す文字');
});

test('パラメータ名に backtick が入っていたら生成は止まる', function (): void {
    $root = HelpTestTree::makeDir('mcp-generator-backtick-name');
    HelpTestTree::writeToolFixture(
        $root,
        'GeneratorFixtureBacktickNameTool',
        'Whoami',
        'fixture tool',
        "return ['a'.chr(96).'b' => \$schema->integer()];",
    );

    expect(fn (): string => helpGeneratorOver($root)->generate())
        ->toThrow(RuntimeException::class, '表を壊す文字');
});

```
### `tests/Unit/Architecture/McpToolScannerTest.php` (修正後の全文)

```php
<?php

declare(strict_types=1);

use App\Mcp\Tools\AppMcpTool;
use App\Services\Help\McpToolScanner;
use Tests\Support\Help\HelpTestTree;

/*
 * McpToolScanner (ヘルプの MCP ツール走査器) の自己検査。
 *
 * 走査器・gate の共通規約 (AGENTS.md §静的検査 (gate) と走査器の共通規約) の
 * (b) fail-closed / (c) 負例で裏取り を、合成した一時走査根で両方向に固定する。
 * 実装の docblock が「保証しないもの」の正本である。
 */

afterEach(function (): void {
    HelpTestTree::cleanup();
});

test('正例: 基底を継承した具象クラスをクラス名昇順で列挙する', function (): void {
    $root = HelpTestTree::makeDir('mcp-scanner-ok');
    HelpTestTree::writeToolFixture($root, 'ScannerFixtureZebraTool', 'Whoami');
    HelpTestTree::writeToolFixture($root, 'ScannerFixtureAlphaTool', 'ListProjects');

    $classes = (new McpToolScanner($root))->concreteToolClasses();

    expect($classes)->toBe([
        'App\Mcp\Tools\ScannerFixtureAlphaTool',
        'App\Mcp\Tools\ScannerFixtureZebraTool',
    ]);

    foreach ($classes as $class) {
        expect(is_subclass_of($class, AppMcpTool::class))->toBeTrue();
    }
});

test('正例: 抽象クラスは母集団から外れるが具象は残る', function (): void {
    $root = HelpTestTree::makeDir('mcp-scanner-abstract');
    HelpTestTree::writeToolFixture($root, 'ScannerFixtureConcreteTool', 'Whoami');

    $abstract = $root.'/ScannerFixtureAbstractTool.php';
    HelpTestTree::put($abstract, <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

abstract class ScannerFixtureAbstractTool extends AppMcpTool {}

PHP);
    require_once $abstract;

    expect((new McpToolScanner($root))->concreteToolClasses())
        ->toBe(['App\Mcp\Tools\ScannerFixtureConcreteTool']);
});

test('負例: 走査根が存在しないと例外で止まる (空を返さない)', function (): void {
    $root = HelpTestTree::makeDir('mcp-scanner-missing');
    $missing = $root.'/not-there';

    expect(fn (): array => (new McpToolScanner($missing))->concreteToolClasses())
        ->toThrow(RuntimeException::class, '走査根が存在しません');
});

test('負例: 母集団が 0 件なら「違反 0 件」ではなく走査の破損として止まる', function (): void {
    $root = HelpTestTree::makeDir('mcp-scanner-empty');

    expect(fn (): array => (new McpToolScanner($root))->concreteToolClasses())
        ->toThrow(RuntimeException::class, '1 件も見つかりません');
});

test('負例: クラス名とファイル名が一致しないと例外で止まる', function (): void {
    $root = HelpTestTree::makeDir('mcp-scanner-unresolved');
    HelpTestTree::put($root.'/ScannerFixtureNoSuchClassTool.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

final class ScannerFixtureDifferentNameTool {}

PHP);

    expect(fn (): array => (new McpToolScanner($root))->concreteToolClasses())
        ->toThrow(RuntimeException::class, 'クラスを解決できません');
});

test('負例: 基底を継承しない具象クラスがあると例外で止まる', function (): void {
    $root = HelpTestTree::makeDir('mcp-scanner-not-a-tool');
    $path = $root.'/ScannerFixtureHelperClass.php';
    HelpTestTree::put($path, <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

final class ScannerFixtureHelperClass {}

PHP);
    require_once $path;

    expect(fn (): array => (new McpToolScanner($root))->concreteToolClasses())
        ->toThrow(RuntimeException::class, 'を継承していません');
});

test('負例: 実体が symlink だと例外で止まる', function (): void {
    $root = HelpTestTree::makeDir('mcp-scanner-symlink');
    HelpTestTree::writeToolFixture($root, 'ScannerFixtureLinkTargetTool', 'Whoami');
    symlink($root.'/ScannerFixtureLinkTargetTool.php', $root.'/ScannerFixtureLinkedTool.php');

    expect(fn (): array => (new McpToolScanner($root))->concreteToolClasses())
        ->toThrow(RuntimeException::class, '通常ファイルではありません');
});

test('負例: 同名クラスが別の場所から読み込まれていると例外で止まる (走査が空振りしない)', function (): void {
    $root = HelpTestTree::makeDir('mcp-scanner-shadow');

    // 実在する `App\Mcp\Tools\WhoamiTool` と同名のファイルを一時根へ置く。
    // class_exists() は composer autoload 経由で **本物** を読むため、
    // Reflection の実体は app/Mcp/Tools/WhoamiTool.php を指し、走査中のファイルと食い違う。
    HelpTestTree::put($root.'/WhoamiTool.php', "<?php\n\ndeclare(strict_types=1);\n\n// 中身は読まれない (autoload が本物を解決する)\n");

    expect(fn (): array => (new McpToolScanner($root))->concreteToolClasses())
        ->toThrow(RuntimeException::class, '実体が走査中のファイルと一致しません');
});

test('負例: 走査根そのものが symlink だと例外で止まる', function (): void {
    $real = HelpTestTree::makeDir('mcp-scanner-real-root');
    HelpTestTree::writeToolFixture($real, 'ScannerFixtureBehindLinkTool', 'Whoami');

    $linkRoot = HelpTestTree::makeDir('mcp-scanner-link-holder').'/tools';
    symlink($real, $linkRoot);

    expect(fn (): array => (new McpToolScanner($linkRoot))->concreteToolClasses())
        ->toThrow(RuntimeException::class, '走査根に symlink は使えません');
});

test('走査根が実在し、実装の母集団が非空であること', function (): void {
    $root = base_path('app/Mcp/Tools');

    expect(is_dir($root))->toBeTrue();
    expect((new McpToolScanner($root))->concreteToolClasses())->not->toBeEmpty();
});

```
### `tests/Feature/Help/HelpRepositoryTest.php` (修正後の全文)

```php
<?php

declare(strict_types=1);

use App\Services\Help\HelpManifestException;
use App\Services\Help\HelpRepository;
use App\Services\Help\HelpSection;
use Tests\Support\Help\HelpTestTree;

/*
 * ヘルプの置き場 (`docs/help/`) の読み取り層。
 *
 * I1 (取り込み基盤) / I11 (直下のみ・階層不可) / I12 (閉じる側へ倒れる:
 * パスを組み立てるたびに字句の検査と実体の検査をやり直す) を負例で裏取りする。
 *
 * 書き込みを伴うので **必ず一時ディレクトリ** を root にする (実 `docs/help/` は触らない)。
 */

afterEach(function (): void {
    HelpTestTree::cleanup();
});

/** 生成物 1 件を宣言した既定の manifest を持つ一時置き場。 */
function helpRepoRoot(string $prefix = 'help-repo'): string
{
    $root = HelpTestTree::makeDir($prefix);
    HelpTestTree::writeManifest($root, [
        ['slug' => 'mcp-tools', 'title' => 'MCP ツールリファレンス', 'path' => '_generated/mcp-tools.md', 'generator' => 'mcp-tools'],
    ]);

    return $root;
}

test('manifest が宣言した節を宣言順に読める (生成物と手書きの区別を含む)', function (): void {
    $root = HelpTestTree::makeDir('help-repo-sections');
    HelpTestTree::writeManifest($root, [
        ['slug' => 'mcp-tools', 'title' => 'MCP ツール', 'path' => '_generated/mcp-tools.md', 'generator' => 'mcp-tools'],
        ['slug' => 'getting-started', 'title' => 'はじめに', 'path' => 'pages/getting-started.md'],
    ]);

    $sections = (new HelpRepository($root))->sections();

    expect($sections)->toHaveCount(2)
        ->and($sections[0]->slug)->toBe('mcp-tools')
        ->and($sections[0]->generatorKey)->toBe('mcp-tools')
        ->and($sections[0]->isGenerated())->toBeTrue()
        ->and($sections[1]->slug)->toBe('getting-started')
        ->and($sections[1]->generatorKey)->toBeNull()
        ->and($sections[1]->isGenerated())->toBeFalse();
});

test('手書きページが 0 件の manifest も正常に読める (未整備を赤字にしない)', function (): void {
    expect((new HelpRepository(helpRepoRoot()))->sections())->toHaveCount(1);
});

test('本文が無い節は例外ではなく null を返す (不在と検査不能を混同しない)', function (): void {
    $root = helpRepoRoot();
    $repository = new HelpRepository($root);

    expect($repository->read($repository->sections()[0]))->toBeNull();
});

test('本文が在れば読める', function (): void {
    $root = helpRepoRoot();
    HelpTestTree::put($root.'/_generated/mcp-tools.md', "# 見本\n");

    $repository = new HelpRepository($root);

    expect($repository->read($repository->sections()[0]))->toBe("# 見本\n");
});

test('生成物ディレクトリが無ければ孤児の母集団は空である', function (): void {
    expect((new HelpRepository(helpRepoRoot()))->generatedArtifactPaths())->toBe([]);
});

test('生成物ディレクトリ直下の Markdown だけを昇順で列挙する', function (): void {
    $root = helpRepoRoot();
    HelpTestTree::put($root.'/_generated/zebra.md', "z\n");
    HelpTestTree::put($root.'/_generated/alpha.md', "a\n");
    HelpTestTree::put($root.'/pages/draft.md', "下書き\n");

    expect((new HelpRepository($root))->generatedArtifactPaths())
        ->toBe(['_generated/alpha.md', '_generated/zebra.md']);
});

test('書き込みは生成物として宣言された節にしか行えない', function (): void {
    $root = HelpTestTree::makeDir('help-repo-write-page');
    HelpTestTree::writeManifest($root, [
        ['slug' => 'intro', 'title' => 'はじめに', 'path' => 'pages/intro.md'],
    ]);

    $repository = new HelpRepository($root);
    $section = $repository->sections()[0];

    expect(fn () => $repository->writeGenerated($section, 'x'))
        ->toThrow(HelpManifestException::class, '手書きページを生成物として書き込めません');
});

test('書き込みは生成物ディレクトリを非再帰に作り、読み戻せる', function (): void {
    $root = helpRepoRoot();
    $repository = new HelpRepository($root);
    $section = $repository->sections()[0];

    $repository->writeGenerated($section, "生成物\n");

    expect($repository->read($section))->toBe("生成物\n")
        ->and(is_dir($root.'/_generated'))->toBeTrue();
});

/*
 * -------- 字句の負例 (I12 / I11) --------
 */

dataset('規約に反する path', [
    '相対指定を含む' => ['_generated/../../etc/passwd.md'],
    '絶対パス' => ['/etc/passwd.md'],
    '許されないディレクトリ' => ['secrets/leak.md'],
    '階層化した生成物' => ['_generated/sub/x.md'],
    'Markdown でない' => ['_generated/x.txt'],
    '名前が英数字以外で始まる' => ['_generated/-x.md'],
]);

test('path が規約に反する manifest は読めない', function (string $path): void {
    $root = HelpTestTree::makeDir('help-repo-path');
    HelpTestTree::writeManifest($root, [
        ['slug' => 'x', 'title' => 'x', 'path' => $path, 'generator' => 'mcp-tools'],
    ]);

    expect(fn (): array => (new HelpRepository($root))->sections())
        ->toThrow(HelpManifestException::class);
})->with('規約に反する path');

test('生成物の節が pages/ を指していたら読めない (generator の有無で期待するディレクトリが決まる)', function (): void {
    $root = HelpTestTree::makeDir('help-repo-dir-mismatch');
    HelpTestTree::writeManifest($root, [
        ['slug' => 'x', 'title' => 'x', 'path' => 'pages/x.md', 'generator' => 'mcp-tools'],
    ]);

    expect(fn (): array => (new HelpRepository($root))->sections())
        ->toThrow(HelpManifestException::class, '_generated/<name>.md');
});

/*
 * -------- manifest の負例 --------
 */

test('manifest が無ければ例外で止まる', function (): void {
    $root = HelpTestTree::makeDir('help-repo-no-manifest');

    expect(fn (): array => (new HelpRepository($root))->sections())
        ->toThrow(HelpManifestException::class, '通常ファイルとして存在しません');
});

test('manifest の JSON が壊れていたら例外で止まる', function (): void {
    $root = HelpTestTree::makeDir('help-repo-broken-json');
    HelpTestTree::writeRawManifest($root, '{ broken');

    expect(fn (): array => (new HelpRepository($root))->sections())
        ->toThrow(HelpManifestException::class, 'JSON が壊れています');
});

test('manifest の最上位が object でなければ例外で止まる', function (): void {
    $root = HelpTestTree::makeDir('help-repo-top-list');
    HelpTestTree::writeRawManifest($root, '[]');

    expect(fn (): array => (new HelpRepository($root))->sections())
        ->toThrow(HelpManifestException::class, '最上位が object ではありません');
});

test('sections が list でなければ例外で止まる', function (): void {
    $root = HelpTestTree::makeDir('help-repo-sections-map');
    HelpTestTree::writeRawManifest($root, '{"schema_version":1,"sections":{"a":1}}');

    expect(fn (): array => (new HelpRepository($root))->sections())
        ->toThrow(HelpManifestException::class, 'sections が配列 (list) ではありません');
});

test('sections が無ければ例外で止まる', function (): void {
    $root = HelpTestTree::makeDir('help-repo-no-sections');
    HelpTestTree::writeRawManifest($root, '{"schema_version":1}');

    expect(fn (): array => (new HelpRepository($root))->sections())
        ->toThrow(HelpManifestException::class, 'sections がありません');
});

test('節が object でなければ例外で止まる', function (): void {
    $root = HelpTestTree::makeDir('help-repo-entry-scalar');
    HelpTestTree::writeRawManifest($root, '{"schema_version":1,"sections":["x"]}');

    expect(fn (): array => (new HelpRepository($root))->sections())
        ->toThrow(HelpManifestException::class, 'object ではありません');
});

test('slug が重複したら例外で止まる', function (): void {
    $root = HelpTestTree::makeDir('help-repo-dup-slug');
    HelpTestTree::writeManifest($root, [
        ['slug' => 'a', 'title' => 'a', 'path' => '_generated/a.md', 'generator' => 'mcp-tools'],
        ['slug' => 'a', 'title' => 'b', 'path' => 'pages/b.md'],
    ]);

    expect(fn (): array => (new HelpRepository($root))->sections())
        ->toThrow(HelpManifestException::class, 'slug が重複しています');
});

test('path が重複したら例外で止まる', function (): void {
    $root = HelpTestTree::makeDir('help-repo-dup-path');
    HelpTestTree::writeManifest($root, [
        ['slug' => 'a', 'title' => 'a', 'path' => 'pages/a.md'],
        ['slug' => 'b', 'title' => 'b', 'path' => 'pages/a.md'],
    ]);

    expect(fn (): array => (new HelpRepository($root))->sections())
        ->toThrow(HelpManifestException::class, 'path が重複しています');
});

test('同じ generator を 2 つの節が参照したら例外で止まる (完全一致を集合一致へ弱めない)', function (): void {
    $root = HelpTestTree::makeDir('help-repo-dup-generator');
    HelpTestTree::writeManifest($root, [
        ['slug' => 'a', 'title' => 'a', 'path' => '_generated/a.md', 'generator' => 'mcp-tools'],
        ['slug' => 'b', 'title' => 'b', 'path' => '_generated/b.md', 'generator' => 'mcp-tools'],
    ]);

    expect(fn (): array => (new HelpRepository($root))->sections())
        ->toThrow(HelpManifestException::class, 'generator が重複しています');
});

test('generator が空文字なら例外で止まる', function (): void {
    $root = HelpTestTree::makeDir('help-repo-empty-generator');
    HelpTestTree::writeManifest($root, [
        ['slug' => 'a', 'title' => 'a', 'path' => '_generated/a.md', 'generator' => ''],
    ]);

    expect(fn (): array => (new HelpRepository($root))->sections())
        ->toThrow(HelpManifestException::class, '非空の文字列ではありません');
});

dataset('読めない schema_version', [
    '欠落' => ['{"sections":[]}'],
    '型違いの文字列' => ['{"schema_version":"1","sections":[]}'],
    '未知の版' => ['{"schema_version":2,"sections":[]}'],
]);

test('読める schema_version 以外は読まずに落ちる', function (string $raw): void {
    $root = HelpTestTree::makeDir('help-repo-schema');
    HelpTestTree::writeRawManifest($root, $raw);

    expect(fn (): array => (new HelpRepository($root))->sections())
        ->toThrow(HelpManifestException::class, 'schema_version');
})->with('読めない schema_version');

/*
 * -------- 実体の負例 (字句だけの飾りにしない) --------
 */

test('manifest が symlink なら例外で止まる', function (): void {
    $root = HelpTestTree::makeDir('help-repo-manifest-link');
    $outside = HelpTestTree::makeDir('help-repo-manifest-outside');
    HelpTestTree::put($outside.'/manifest.json', '{"schema_version":1,"sections":[]}');
    symlink($outside.'/manifest.json', $root.'/manifest.json');

    expect(fn (): array => (new HelpRepository($root))->sections())
        ->toThrow(HelpManifestException::class, '通常ファイルとして存在しません');
});

test('本文が symlink なら例外で止まる', function (): void {
    $root = helpRepoRoot();
    $outside = HelpTestTree::makeDir('help-repo-body-outside');
    HelpTestTree::put($outside.'/leak.md', "外\n");
    mkdir($root.'/_generated', 0o755);
    symlink($outside.'/leak.md', $root.'/_generated/mcp-tools.md');

    $repository = new HelpRepository($root);

    expect(fn (): ?string => $repository->read($repository->sections()[0]))
        ->toThrow(HelpManifestException::class, 'symlink は使えません');
});

test('生成物ディレクトリ自体が symlink なら例外で止まる', function (): void {
    $root = helpRepoRoot();
    $outside = HelpTestTree::makeDir('help-repo-dir-outside');
    symlink($outside, $root.'/_generated');

    expect(fn (): array => (new HelpRepository($root))->generatedArtifactPaths())
        ->toThrow(HelpManifestException::class, 'symlink は使えません');
});

test('生成物ディレクトリ直下に階層があれば例外で止まる (再帰走査を持たない)', function (): void {
    $root = helpRepoRoot();
    HelpTestTree::put($root.'/_generated/sub/x.md', "x\n");

    expect(fn (): array => (new HelpRepository($root))->generatedArtifactPaths())
        ->toThrow(HelpManifestException::class, '階層を許しません');
});

test('生成物ディレクトリ直下の symlink は Orphan に畳まず例外で止まる', function (): void {
    $root = helpRepoRoot();
    HelpTestTree::put($root.'/_generated/real.md', "r\n");
    symlink($root.'/_generated/real.md', $root.'/_generated/linked.md');

    expect(fn (): array => (new HelpRepository($root))->generatedArtifactPaths())
        ->toThrow(HelpManifestException::class, 'symlink があります');
});

test('生成物ディレクトリ直下の Markdown 以外は例外で止まる', function (): void {
    $root = helpRepoRoot();
    HelpTestTree::put($root.'/_generated/notes.txt', "t\n");

    expect(fn (): array => (new HelpRepository($root))->generatedArtifactPaths())
        ->toThrow(HelpManifestException::class, 'Markdown 以外の実体があります');
});

test('生成物ディレクトリ直下の通常ファイルでない実体は例外で止まる', function (): void {
    $root = helpRepoRoot();
    mkdir($root.'/_generated', 0o755);
    posix_mkfifo($root.'/_generated/pipe.md', 0o644);

    expect(fn (): array => (new HelpRepository($root))->generatedArtifactPaths())
        ->toThrow(HelpManifestException::class, '通常ファイルでない実体があります');
});

/*
 * -------- 書き込み経路が置き場の外へ出ないこと --------
 */

test('生成物ディレクトリが外部への symlink なら書き込みは止まり、外部ファイルは 1 バイトも変わらない', function (): void {
    $root = helpRepoRoot();
    $outside = HelpTestTree::makeDir('help-repo-write-outside');
    HelpTestTree::put($outside.'/mcp-tools.md', "外部の中身\n");
    symlink($outside, $root.'/_generated');

    $before = HelpTestTree::snapshot($outside);

    $repository = new HelpRepository($root);
    $section = $repository->sections()[0];

    expect(fn () => $repository->writeGenerated($section, '侵入'))
        ->toThrow(HelpManifestException::class, 'symlink は使えません');

    expect(HelpTestTree::snapshot($outside))->toBe($before)
        ->and(file_get_contents($outside.'/mcp-tools.md'))->toBe("外部の中身\n");
});

test('生成物の実体が symlink なら書き込みは止まる', function (): void {
    $root = helpRepoRoot();
    $outside = HelpTestTree::makeDir('help-repo-file-outside');
    HelpTestTree::put($outside.'/target.md', "外部\n");
    mkdir($root.'/_generated', 0o755);
    symlink($outside.'/target.md', $root.'/_generated/mcp-tools.md');

    $repository = new HelpRepository($root);
    $section = $repository->sections()[0];

    expect(fn () => $repository->writeGenerated($section, '侵入'))
        ->toThrow(HelpManifestException::class, '生成物に symlink は使えません');

    expect(file_get_contents($outside.'/target.md'))->toBe("外部\n");
});

test('置き場そのものが外部への symlink なら読み書きのすべてが止まり、外部ファイルは変わらない', function (): void {
    $outside = helpRepoRoot('help-repo-root-outside');
    HelpTestTree::put($outside.'/_generated/mcp-tools.md', "外部の中身\n");

    $linkRoot = HelpTestTree::makeDir('help-repo-root-link-holder').'/help';
    symlink($outside, $linkRoot);

    $before = HelpTestTree::snapshot($outside);
    $repository = new HelpRepository($linkRoot);

    expect(fn (): array => $repository->sections())
        ->toThrow(HelpManifestException::class, '置き場に symlink は使えません');
    expect(fn (): array => $repository->generatedArtifactPaths())
        ->toThrow(HelpManifestException::class, '置き場に symlink は使えません');

    // 節そのものは実置き場から読める形で作り、書き込み経路も止まることを見る
    $section = (new HelpRepository($outside))->sections()[0];
    expect(fn () => $repository->writeGenerated($section, '侵入'))
        ->toThrow(HelpManifestException::class, '置き場に symlink は使えません');
    expect(fn (): ?string => $repository->read($section))
        ->toThrow(HelpManifestException::class, '置き場に symlink は使えません');

    expect(HelpTestTree::snapshot($outside))->toBe($before)
        ->and(file_get_contents($outside.'/_generated/mcp-tools.md'))->toBe("外部の中身\n");
});

test('HelpSection は generatorKey の有無だけで生成物かどうかを決める', function (): void {
    expect((new HelpSection('a', 'A', '_generated/a.md', 'k'))->isGenerated())->toBeTrue()
        ->and((new HelpSection('a', 'A', 'pages/a.md', null))->isGenerated())->toBeFalse();
});

```


## 再レビューの依頼

1. Round 1 の Critical 2 件が**実際に閉じたか**を判定せよ。
   とくに TOCTOU については「防いだ」とは書いていない — **保証を狭めた**ことが
   実装・docblock・運用文書で一貫しているか、誇張が残っていないかを見よ。
2. 新しく足した検査 (最上位 schema の pin / 置き場と走査根の symlink 拒否 /
   パラメータ名の拒否) が**新たな穴や過剰拘束を作っていないか**を見よ。
   とくに「vendor 更新で無害なキーが増えたら止まる」判断の妥当性を評価せよ。
3. Warning 5 件の対応が十分かを判定せよ。
4. 全体判定を `APPROVED` または `CHANGES_REQUESTED` で書け。
