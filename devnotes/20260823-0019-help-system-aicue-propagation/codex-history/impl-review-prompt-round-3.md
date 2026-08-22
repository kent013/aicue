# Round 3: Round 2 の指摘への対応

Round 2 の指摘 (Critical 2 / Warning 3) に対する判断と対応を示す。

## 対応マトリクス

# 対応マトリクス: impl-review Round 2

## [Critical] `HelpRepository`: 親要素 (祖先) が symlink の経路が残る

- 判断: **対応する**
- 根拠: 指摘は正しい。`is_link($this->root)` は**最終要素しか見ない**ので、
  `holder/docs -> /outside` という**親要素**の symlink があると
  `holder/docs/help` は通常ディレクトリのまま `realpath()` が `/outside/help` を返す。
  以降の「置き場の内側か」の検査も生成物ディレクトリの一致検査も**全部その外側を
  正当な root として通す**。これは Round 1 で脅威モデルから外した「操作中の差し替え」
  ではなく、**操作開始前から成立する静止状態の抜け道**であり、外すべき理由が無い。
- 対応内容: Codex の提案どおり**信頼する anchor を明示する**形にした。
  - **契約**: 置き場は **canonical path** として渡す。`rootReal()` は
    `realpath($root) === $root` を毎回検査する。これは最終要素だけでなく
    **起点から置き場までの経路のどの要素も symlink でない**ことを意味する
    (1 つの検査で祖先も閉じる)。
  - **anchor**: 配線 (`AppServiceProvider::canonicalPathUnder()`) が
    `realpath(base_path())` を起点にし、**配下の相対部分は正規化しない**
    (正規化すると経路の symlink が畳まれて検査が意味を失う)。
    これにより「作業ツリー全体が symlink の先にある」形は**拒まない**
    (Codex が懸念した偽陽性を作らない)。
  - 負例を追加: **親要素が symlink で最終要素は通常ディレクトリ**の形を作り、
    `sections()` / `generatedArtifactPaths()` / `writeGenerated()` / `read()` の
    4 経路すべてが止まり、外部ファイルが 1 バイトも変化しないことを固定した。
    テスト内で `is_link($root) === false` を先に assert して、
    **旧実装の分岐では素通りする形であること**を明示している (負例が空振りしない)。

## [Critical] `writeGenerated()` の docblock が縮小した保証と矛盾する

- 判断: **対応する**
- 根拠: 「作成の途中で入れ替えられた形を残さない」は取り消せることを含意しており、
  事後検出しかできない実装に対して過大である。Round 1 で保証を狭めた以上、
  この 1 行が残っていると文書全体の信頼が落ちる。
- 対応内容: 「**入れ替えを検出する**ためであり、書かれてしまった内容を取り消せる
  という意味ではない」に書き換えた。

## [Warning] `McpToolScanner`: 同じ祖先 symlink 経路が残る

- 判断: **対応する**
- 根拠: `HelpRepository` と同じ形。片方だけ閉じると規約が場当たりになる。
- 対応内容: 走査根も **canonical path 契約**にし (`realpath($root) === $root`)、
  配線を `canonicalPathUnder('app/Mcp/Tools')` に揃えた。
  親要素 symlink の負例を追加した (同じく `is_link()` が false であることを先に assert)。

## [Warning] `tests/Feature/Help/HelpRepositoryTest.php` / `tests/Unit/Architecture/McpToolScannerTest.php`

- 判断: **対応する**
- 対応内容: 上記 2 件で親要素 symlink の負例を追加。既存の最終要素 symlink の負例は
  新しい検査で止まるようになったので、期待する文言を
  `canonical path ではありません` へ更新した (分岐は 1 本に統合されている)。

## [Warning] `docs/help-system.md` の symlink の保証範囲が曖昧

- 判断: **対応する**
- 対応内容: 「置き場が symlink であってはならない」を
  「**信頼する起点から置き場までの経路に symlink があってはならない**」へ書き換え、
  最終要素だけでなく途中の要素も含むこと、走査根にも同じ契約が効くこと、
  作業ツリー全体が symlink の先にある形は拒まないことを明記した。
  「保証しないもの」の TOCTOU の項も、静止状態で守る範囲を経路まで含めて言い直した。

## 解消済みと判定された項目 (Round 2 で OK)

- `McpToolMetadata` の最上位 schema pin / `McpToolReferenceGeneratorTest` の分岐固有検査 /
  `McpToolReferenceGenerator` のパラメータ名拒否 — いずれも Round 2 で OK 判定。追加変更なし。


## 要点 (設計の変更点)

**最終要素の `is_link()` 検査をやめ、「根は canonical path として渡す」契約に変えた。**

- 受け取り側 (`HelpRepository::rootReal()` / `McpToolScanner`) は
  `realpath($root) === $root` を毎回検査する。
  等式が成り立つのは**起点から根までの経路のどの要素も symlink でないとき**だけなので、
  最終要素と祖先を **1 つの検査**で同時に閉じる。
- 配線 (`AppServiceProvider::canonicalPathUnder()`) は `realpath(base_path())` を
  信頼 anchor にし、**配下の相対部分は正規化しない**。
  これにより「作業ツリー全体が symlink の先にある」形は拒まず、
  「起点から根までの経路に symlink が挟まった」形だけを弾く。

## 検証結果 (修正後)

```
composer phpstan                       -> [OK] No errors
vendor/bin/pint --test                 -> passed
php artisan help:build --check         -> exit 0 (_generated/mcp-tools.md .. up_to_date)
composer test -- --filter='Help|McpTool'
  -> {"tool":"pest","result":"passed","tests":127,"passed":127,"assertions":359}
pnpm test -> Test Files 173 passed / Tests 2366 passed
```

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
 *     保証するのは静止状態で**起点から走査根までの経路**と各ファイルが symlink でないこと
 *     までである (走査根が canonical path であることを毎回検査する)。
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
        if (! is_dir($this->root)) {
            throw new RuntimeException(
                "MCP ツールの走査根が存在しません: {$this->root} — ".
                'ディレクトリを移動・改名したなら McpToolScanner の配線を同じ変更で直すこと。',
            );
        }

        // ★走査根が canonical path であることを要求する。`is_dir()` / `scandir()` / autoload /
        //   Reflection の `realpath()` はすべて symlink を辿るので、経路のどこか 1 要素でも
        //   symlink だと外部のディレクトリを走査しながら「実体の一致」検査を通過してしまう
        //   (走査根が固定の first-party パスである前提が崩れる)。最終要素だけの `is_link()`
        //   では `app/Mcp` が symlink という形を見逃す。
        //   作業ツリー全体が symlink の先にある形は拒まない — 配線側が信頼する起点を
        //   `realpath()` で正規化してから組み立てるためである。
        $real = realpath($this->root);
        if ($real === false || $real !== $this->root) {
            throw new RuntimeException(
                "MCP ツールの走査根が canonical path ではありません: {$this->root} ".
                '(解決先: '.var_export($real, true).') — 起点から走査根までの経路に symlink がある。'.
                '信頼する起点を realpath で正規化してから組み立てること。',
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
- **信頼する起点 (リポジトリルート) から置き場までの経路に symlink があってはならない**。
  置き場は canonical path として渡す契約で、`realpath()` の結果が渡された文字列と
  一致しなければ例外で止まる。最終要素 (`help`) だけでなく**途中の要素 (`docs`) も**含む
  — どこか 1 つでも symlink だと `realpath()` が外側を canonical root として返し、
  「置き場の内側か」の検査が意味を失うためである。
  同じ契約が MCP ツールの走査根 (`app/Mcp/Tools/`) にも適用される。
  **作業ツリー全体が symlink の先にある形は拒まない** — 配線 (`AppServiceProvider`) が
  起点そのものを `realpath()` で正規化してから組み立てるためである。
- 生成物ディレクトリと生成物も symlink であってはならない (見つけたら例外で止まる)。
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
  保証するのは**静止状態での封じ込め** (起点から置き場・走査根までの経路、生成物ディレクトリ、
  生成物のいずれも symlink でないこと) までで、書き込みの最中にファイルや親ディレクトリを
  symlink へ差し替える攻撃者は脅威モデルに含めない (これは開発者の作業ツリーで走る生成器である)。
  書き込み後の検査は**取り消しではなく検出**である。
- **保証しないものの網羅的な正本は各クラスの docblock** であり、本書はその要約である
  (2 か所に同じ一覧を書くと必ず食い違う)。

```
### `app/Providers/AppServiceProvider.php` (配線の該当部分)

```php
// ヘルプ機構 (T246) の 2 つの根。運用者が触る値ではないので CLI の knob には出さない
        // (出すと「別の場所を検査させて緑にする」経路ができる)。テストは container の
        // rebind で差し替える。
        // ★**信頼する起点はリポジトリルートの canonical path** である。ここで realpath を
        //   通しておくことで「作業ツリー全体が symlink の先にある」形は許しつつ、
        //   起点から根までの経路に symlink が挟まった形は受け取り側の検査が弾ける
        //   (根を canonical path として渡すことが両クラスの契約である)。
        $this->app->singleton(HelpRepository::class, static function (): HelpRepository {
            return new HelpRepository(self::canonicalPathUnder('docs/help'));
        });

        $this->app->singleton(McpToolScanner::class, static function (): McpToolScanner {
            return new McpToolScanner(self::canonicalPathUnder('app/Mcp/Tools'));
        });
    }

    /**
     * 信頼する起点 (リポジトリルートの canonical path) の配下の絶対パスを組み立てる。
     *
     * ★起点だけを `realpath()` で正規化し、**配下の相対部分は正規化しない**。
     *   正規化してしまうと経路に挟まった symlink が畳まれ、受け取り側の
     *   「canonical path か」の検査が意味を失う。
     *
     * @param  non-empty-string  $relative
     * @return non-empty-string
     */
    private static function canonicalPathUnder(string $relative): string
    {
        $base = realpath(base_path());
        Assert::string($base, 'リポジトリルートを解決できません。');
        Assert::stringNotEmpty($base);

        return $base.'/'.$relative;
    }
```
### 追加した負例 (`tests/Feature/Help/HelpRepositoryTest.php`)

```php
test('置き場の**親要素**が symlink でも読み書きのすべてが止まり、外部ファイルは変わらない', function (): void {
    // outside/help が実ディレクトリ (置き場)。holder/docs -> outside という**親要素**の
    // symlink を張り、holder/docs/help を置き場にする。最終要素は通常ディレクトリなので
    // `is_link()` だけでは素通りするが、canonical path 検査は弾く。
    $outside = HelpTestTree::makeDir('help-repo-ancestor-outside');
    $store = $outside.'/help';
    mkdir($store, 0o755);
    HelpTestTree::writeManifest($store, [
        ['slug' => 'mcp-tools', 'title' => 'MCP ツールリファレンス', 'path' => '_generated/mcp-tools.md', 'generator' => 'mcp-tools'],
    ]);
    HelpTestTree::put($store.'/_generated/mcp-tools.md', "外部の中身\n");

    $holder = HelpTestTree::makeDir('help-repo-ancestor-holder');
    symlink($outside, $holder.'/docs');
    $root = $holder.'/docs/help';

    expect(is_link($root))->toBeFalse()
        ->and(is_dir($root))->toBeTrue();

    $before = HelpTestTree::snapshot($outside);
    $repository = new HelpRepository($root);
    $section = (new HelpRepository($store))->sections()[0];

    expect(fn (): array => $repository->sections())
        ->toThrow(HelpManifestException::class, 'canonical path ではありません');
    expect(fn (): array => $repository->generatedArtifactPaths())
        ->toThrow(HelpManifestException::class, 'canonical path ではありません');
    expect(fn () => $repository->writeGenerated($section, '侵入'))
        ->toThrow(HelpManifestException::class, 'canonical path ではありません');
    expect(fn (): ?string => $repository->read($section))
        ->toThrow(HelpManifestException::class, 'canonical path ではありません');

    expect(HelpTestTree::snapshot($outside))->toBe($before)
        ->and(file_get_contents($store.'/_generated/mcp-tools.md'))->toBe("外部の中身\n");
});
```
### 追加した負例 (`tests/Unit/Architecture/McpToolScannerTest.php`)

```php
test('負例: 走査根の**親要素**が symlink でも例外で止まる (最終要素だけを見ない)', function (): void {
    // outside/tools が実ディレクトリ。holder/mcp -> outside という**親要素**の symlink を張り、
    // holder/mcp/tools を走査根にする。最終要素は通常ディレクトリなので
    // `is_link()` だけでは素通りするが、canonical path 検査は弾く。
    $outside = HelpTestTree::makeDir('mcp-scanner-ancestor-outside');
    $tools = $outside.'/tools';
    mkdir($tools, 0o755);
    HelpTestTree::writeToolFixture($tools, 'ScannerFixtureAncestorTool', 'Whoami');

    $holder = HelpTestTree::makeDir('mcp-scanner-ancestor-holder');
    symlink($outside, $holder.'/mcp');
    $root = $holder.'/mcp/tools';

    expect(is_link($root))->toBeFalse()
        ->and(is_dir($root))->toBeTrue();

    expect(fn (): array => (new McpToolScanner($root))->concreteToolClasses())
        ->toThrow(RuntimeException::class, 'canonical path ではありません');
});
```


## 再レビューの依頼

1. Round 2 の Critical 2 件が閉じたかを判定せよ。とくに
   **canonical path 契約が祖先 symlink を本当に閉じているか**、
   **偽陽性 (symlink 経由でチェックアウトされた作業ツリー) を作っていないか**を見よ。
2. 追加した負例が**旧実装では素通りする形**になっているか (空振りしていないか) を見よ。
3. 残っている誇張・不整合があれば指摘せよ。
4. 全体判定を `APPROVED` または `CHANGES_REQUESTED` で書け。
