<?php

declare(strict_types=1);

namespace Tests\Support\LegacyUrl;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * 旧 URL 残存検査の**母集団と 4 分類**の単一出典 (家系裁定 AG-037 / 施策 10)。
 *
 * ## 母集団
 *
 * git 追跡下のファイル**全数** (拡張子で絞らない)。`Tests\Support\TrackedPhpSourceFiles` は
 * `.php` に限った兄弟であり、こちらは同じ作法 (`git ls-files`) で全ファイルへ広げた別の母集団である
 * (列挙を 2 本持つのではなく、対象の定義が違う)。
 *
 * ## 4 分類 (排他)
 *
 * | 分類 | 何を入れるか |
 * |---|---|
 * | 走査する (`Scanned`) | 抽出方式 (`LegacyUrlExtractionMode`) を割り当てて旧 URL を検出する |
 * | 走査しない (`NotScanned`) | **理由必須**。理由の無い除外は書けない |
 * | 自己検査専用 (`SelfCheckOnly`) | 検出語をわざと持つ負例 fixture。`LegacyUrlSelfCheckPopulationTest` が件数を pin |
 * | 未分類 | **1 件でも現れたら赤**。enum の case を持たせず、別の配列へ理由付きで積む |
 *
 * ## 確定の順序 (固定)
 *
 *   git 追跡下の列挙
 *   → symlink が解決でき解決先がリポジトリ内か (壊れている / 外なら未解決)
 *   → 通常ファイルとして読めるか (失敗は未解決)
 *   → 分類 (走査しない / 自己検査専用 / 走査する / 未分類)
 *   → 走査する・自己検査専用のものだけ内容を読み、NUL / 不正 UTF-8 は**未解決** (無言で捨てない)
 *
 * ★**fail-open を作らない**: 追跡下にあるのに読めないパスを `continue` で捨てない。
 * ★**バイナリ資産は分類で外す**。内容の NUL 判定で黙って落とすと、NUL を 1 つ入れるだけで
 *   走査から逃げられる。逃げ道を塞ぐため、走査対象に分類したファイルが NUL を含んだら**赤**にする。
 *
 * ## 保証しないもの
 *
 * - git 未追跡のファイルは列挙しない (gate が守る境界は commit / CI であり、そこでは必ず追跡下にある)。
 * - 分類は**パスと拡張子だけ**で決まる。中身から種別を推定しない。
 */
final class LegacyUrlScanRoots
{
    /**
     * 走査しない置き場所 (接頭辞またはパス完全一致) と**理由**。
     *
     * ★理由は 30 文字以上を `LegacyOrganizationlessUrlAbsenceTest` が機械で要求する
     *   (「一言で外す」ことをさせない)。
     *
     * @var array<string, string>
     */
    private const array NOT_SCANNED_PATHS = [
        'devnotes/' => '設計・レビューの記録であり実行されない。当時の URL 表記は履歴であって参照ではないため、書き換えると記録が事実でなくなる',
        'doc/reference/' => '現場から預かった業務資料 (SOP・撮影シナリオ・モックアップ・プロンプト案) であり、本アプリの URL を 1 つも持たない。編集の権利も本リポジトリに無い',
        'docs/TODO-closed.md' => 'クローズ済み TODO の記録は当時の事実である。過去の作業説明に現れる旧 URL を書き換えると記録そのものが嘘になるため、履歴として走査から外す',
        'composer.lock' => '依存解決の生成物であり人が書く記述を含まない。パッケージ名や URL は上流の値であって本アプリの経路ではない',
        'pnpm-lock.yaml' => '依存解決の生成物であり人が書く記述を含まない。パッケージ名や URL は上流の値であって本アプリの経路ではない',
        'public/build/' => 'ビルド生成物であり、原本は resources/ 配下の走査で押さえている。生成物を直接直すことはない',
    ];

    /**
     * 走査しない拡張子 (バイナリ資産) と**理由**。
     *
     * @var array<string, string>
     */
    private const array NOT_SCANNED_EXTENSIONS = [
        'png' => '画像バイナリであり、テキストとしての URL 参照を持たない (モックアップ・スクリーンショット)',
        'jpg' => '画像バイナリであり、テキストとしての URL 参照を持たない (モックアップ・スクリーンショット)',
        'jpeg' => '画像バイナリであり、テキストとしての URL 参照を持たない (モックアップ・スクリーンショット)',
        'gif' => '画像バイナリであり、テキストとしての URL 参照を持たない (モックアップ・スクリーンショット)',
        'ico' => '画像バイナリであり、テキストとしての URL 参照を持たない (favicon)',
        'pdf' => '配布資料のバイナリであり、テキストとしての URL 参照を持たない (現場から預かった手順書)',
        'docx' => 'オフィス文書のバイナリであり、テキストとしての URL 参照を持たない (現場から預かった資料)',
        'xlsx' => 'オフィス文書のバイナリであり、テキストとしての URL 参照を持たない (撮影シナリオ表)',
        'mp4' => '動画バイナリであり、テキストとしての URL 参照を持たない (見本素材)',
    ];

    /**
     * 自己検査専用 (検出語をわざと持つファイル) と**理由**。
     *
     * ★ここに入れたファイルは旧 URL の検出対象から外れるかわりに、
     *   `LegacyUrlSelfCheckPopulationTest` がファイル名と検出語の一致件数を**完全一致で pin** する。
     *
     * @var array<string, string>
     */
    private const array SELF_CHECK_ONLY_PATHS = [
        'tests/Architecture/fixtures/legacy-url/legacy-paths.md' => '旧 URL を検出できることを確かめる正例の見本。検出したい語をわざと持つのが役目であり、rule ID では表せない',
        'tests/Architecture/fixtures/legacy-url/allowed-paths.md' => '誤検出してはいけない新 URL・無関係な語の見本。旧 URL の根と紛らわしい語をわざと持つのが役目である',
        'tests/Architecture/fixtures/legacy-url/legacy-php-source.txt' => 'PHP の文字列リテラルとコメントの扱いを分ける検出力の見本。旧 URL をコメントとリテラルの両方に持つ',
        'tests/Architecture/fixtures/legacy-url/legacy-script-source.txt' => 'script の文字列リテラル・コメント・正規表現リテラル・組織 URL 組み立ての入口の扱いを分ける検出力の見本である',
        'tests/Architecture/fixtures/legacy-url/legacy-shadowed-builder.txt' => '入口の module を取り込まずに同名関数を自前定義した形の見本。規則 3 の免除が効かないことを確かめる',
        'tests/Architecture/fixtures/legacy-url/legacy-data-source.txt' => 'JSON / webmanifest の値と、1 行に 2 個の撤去 route 名を持つ見本。件数の数え方を確かめる',
        'tests/Architecture/fixtures/legacy-url/legacy-blade-source.txt' => 'Blade テンプレートの属性値と route helper 経由の記述を分ける検出力の見本である',
    ];

    /**
     * 走査する拡張子 → 抽出方式と rule ID。
     *
     * ★`.blade.php` は PHP としてではなく全文として見る (テンプレートであり、
     *   属性値と Blade 式が混在するため文字列リテラルの構文が閉じない)。
     * ★拡張子を持たないファイル (`artisan` / `scripts/codex` / `.gitignore` 等) は
     *   `''` のキーで全文走査に割り当てる。
     *
     * @var array<string, array{mode: LegacyUrlExtractionMode, rule: string}>
     */
    private const array SCANNED_EXTENSIONS = [
        'php' => ['mode' => LegacyUrlExtractionMode::SourceLiteral, 'rule' => LegacyUrlScanner::RULE_PHP_LITERAL],
        'ts' => ['mode' => LegacyUrlExtractionMode::SourceLiteral, 'rule' => LegacyUrlScanner::RULE_SCRIPT_LITERAL],
        'js' => ['mode' => LegacyUrlExtractionMode::SourceLiteral, 'rule' => LegacyUrlScanner::RULE_SCRIPT_LITERAL],
        'mjs' => ['mode' => LegacyUrlExtractionMode::SourceLiteral, 'rule' => LegacyUrlScanner::RULE_SCRIPT_LITERAL],
        'cjs' => ['mode' => LegacyUrlExtractionMode::SourceLiteral, 'rule' => LegacyUrlScanner::RULE_SCRIPT_LITERAL],
        'svelte' => ['mode' => LegacyUrlExtractionMode::SourceLiteral, 'rule' => LegacyUrlScanner::RULE_SCRIPT_LITERAL],
        'py' => ['mode' => LegacyUrlExtractionMode::SourceLiteral, 'rule' => LegacyUrlScanner::RULE_SCRIPT_LITERAL],
        'md' => ['mode' => LegacyUrlExtractionMode::PlainText, 'rule' => LegacyUrlScanner::RULE_MARKDOWN_TEXT],
        'json' => ['mode' => LegacyUrlExtractionMode::PlainText, 'rule' => LegacyUrlScanner::RULE_DATA_TEXT],
        'jsonl' => ['mode' => LegacyUrlExtractionMode::PlainText, 'rule' => LegacyUrlScanner::RULE_DATA_TEXT],
        'webmanifest' => ['mode' => LegacyUrlExtractionMode::PlainText, 'rule' => LegacyUrlScanner::RULE_DATA_TEXT],
        'toml' => ['mode' => LegacyUrlExtractionMode::PlainText, 'rule' => LegacyUrlScanner::RULE_DATA_TEXT],
        'yaml' => ['mode' => LegacyUrlExtractionMode::PlainText, 'rule' => LegacyUrlScanner::RULE_DATA_TEXT],
        'yml' => ['mode' => LegacyUrlExtractionMode::PlainText, 'rule' => LegacyUrlScanner::RULE_DATA_TEXT],
        'tsv' => ['mode' => LegacyUrlExtractionMode::PlainText, 'rule' => LegacyUrlScanner::RULE_DATA_TEXT],
        'txt' => ['mode' => LegacyUrlExtractionMode::PlainText, 'rule' => LegacyUrlScanner::RULE_DATA_TEXT],
        'css' => ['mode' => LegacyUrlExtractionMode::PlainText, 'rule' => LegacyUrlScanner::RULE_DATA_TEXT],
        'sh' => ['mode' => LegacyUrlExtractionMode::PlainText, 'rule' => LegacyUrlScanner::RULE_DATA_TEXT],
        'xml' => ['mode' => LegacyUrlExtractionMode::PlainText, 'rule' => LegacyUrlScanner::RULE_DATA_TEXT],
        'html' => ['mode' => LegacyUrlExtractionMode::PlainText, 'rule' => LegacyUrlScanner::RULE_DATA_TEXT],
        'neon' => ['mode' => LegacyUrlExtractionMode::PlainText, 'rule' => LegacyUrlScanner::RULE_DATA_TEXT],
        'ini' => ['mode' => LegacyUrlExtractionMode::PlainText, 'rule' => LegacyUrlScanner::RULE_DATA_TEXT],
        'example' => ['mode' => LegacyUrlExtractionMode::PlainText, 'rule' => LegacyUrlScanner::RULE_DATA_TEXT],
        'testing' => ['mode' => LegacyUrlExtractionMode::PlainText, 'rule' => LegacyUrlScanner::RULE_DATA_TEXT],
        '' => ['mode' => LegacyUrlExtractionMode::PlainText, 'rule' => LegacyUrlScanner::RULE_DATA_TEXT],
    ];

    /** 確定済みの母集団 (プロセス内で 1 度だけ確定する。判定は持たない)。 */
    private static ?LegacyUrlScanPopulation $memoized = null;

    /** インスタンス化しない (純関数と定数の置き場)。 */
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

    /** 走査しない置き場所の理由表 (gate が理由の長さを検査する)。 @return array<string, string> */
    public static function notScannedPathReasons(): array
    {
        return self::NOT_SCANNED_PATHS;
    }

    /** 走査しない拡張子の理由表。 @return array<string, string> */
    public static function notScannedExtensionReasons(): array
    {
        return self::NOT_SCANNED_EXTENSIONS;
    }

    /** 自己検査専用の理由表。 @return array<string, string> */
    public static function selfCheckOnlyReasons(): array
    {
        return self::SELF_CHECK_ONLY_PATHS;
    }

    /** 走査する拡張子の割り当て表。 @return array<string, array{mode: LegacyUrlExtractionMode, rule: string}> */
    public static function scannedExtensions(): array
    {
        return self::SCANNED_EXTENSIONS;
    }

    /**
     * 拡張子 (小文字。拡張子なしは空文字列)。
     *
     * ★`.gitignore` のようにドットで始まるだけのファイルは**拡張子なし**として扱う。
     *   `x.blade.php` は `php` を返す (blade の判別はパス側で行う)。
     */
    public static function extensionOf(string $relative): string
    {
        $basename = basename($relative);
        $position = strrpos($basename, '.');
        if ($position === false || $position === 0) {
            return '';
        }

        return strtolower(substr($basename, $position + 1));
    }

    /** 解決済みの絶対パスがリポジトリルート配下か (純関数。自己検証の seam)。 */
    public static function isPathInsideRepository(string $repositoryRoot, string $resolvedTarget): bool
    {
        return str_starts_with($resolvedTarget, rtrim($repositoryRoot, '/').'/');
    }

    /**
     * symlink の解決結果の判定 (母集団の確定も自己検証も必ずここを通る)。
     *
     * ★symlink でなければ null。壊れている / リポジトリ外へ解決されるなら理由を返す。
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
     * パスの分類 (純関数。**母集団の確定も自己検証も必ずここを通る**)。
     *
     * ★分類できなければ `null` を返す = 未分類。利用側が赤にする。
     *
     * @return array{class: LegacyUrlScanClass, mode: ?LegacyUrlExtractionMode, rule: ?string, reason: ?string}|null
     */
    public static function classify(string $relative): ?array
    {
        foreach (self::SELF_CHECK_ONLY_PATHS as $path => $reason) {
            if ($relative === $path) {
                return ['class' => LegacyUrlScanClass::SelfCheckOnly, 'mode' => null, 'rule' => null, 'reason' => $reason];
            }
        }

        foreach (self::NOT_SCANNED_PATHS as $path => $reason) {
            $matches = str_ends_with($path, '/') ? str_starts_with($relative, $path) : $relative === $path;
            if ($matches) {
                return ['class' => LegacyUrlScanClass::NotScanned, 'mode' => null, 'rule' => null, 'reason' => $reason];
            }
        }

        $extension = self::extensionOf($relative);

        if (isset(self::NOT_SCANNED_EXTENSIONS[$extension])) {
            return [
                'class' => LegacyUrlScanClass::NotScanned,
                'mode' => null,
                'rule' => null,
                'reason' => self::NOT_SCANNED_EXTENSIONS[$extension],
            ];
        }

        if (str_ends_with($relative, '.blade.php')) {
            return [
                'class' => LegacyUrlScanClass::Scanned,
                'mode' => LegacyUrlExtractionMode::PlainText,
                'rule' => LegacyUrlScanner::RULE_BLADE_TEXT,
                'reason' => null,
            ];
        }

        if (isset(self::SCANNED_EXTENSIONS[$extension])) {
            return [
                'class' => LegacyUrlScanClass::Scanned,
                'mode' => self::SCANNED_EXTENSIONS[$extension]['mode'],
                'rule' => self::SCANNED_EXTENSIONS[$extension]['rule'],
                'reason' => null,
            ];
        }

        return null;
    }

    /**
     * 内容の分類 (純関数。**母集団の確定も自己検証も必ずここを通る**)。
     *
     * ★同じ判定を 2 本持たない。NUL 判定と UTF-8 検証を 1 つの入口に閉じることで、
     *   合成した文字列からも実母集団からも同じ経路で確かめられる。
     *   返すのは「走査対象に分類したのに読めない」理由 (問題なければ null)。
     */
    public static function contentsUnresolvedReason(string $contents): ?string
    {
        if (str_contains($contents, "\0")) {
            // 走査対象に分類したのにバイナリ = 分類が誤っている。無言で外さない
            return '走査対象に分類されているが NUL を含む (分類の誤り)';
        }
        if (! mb_check_encoding($contents, 'UTF-8')) {
            return 'UTF-8 として不正';
        }

        return null;
    }

    /** 母集団を確定する (唯一の経路)。 */
    public static function population(): LegacyUrlScanPopulation
    {
        if (self::$memoized instanceof LegacyUrlScanPopulation) {
            return self::$memoized;
        }

        $repositoryRoot = self::repositoryRoot();
        $scanned = [];
        $selfCheckOnly = [];
        $notScanned = [];
        $unclassified = [];
        $unresolved = [];

        foreach (self::trackedPaths($repositoryRoot) as $relative) {
            $absolute = $repositoryRoot.'/'.$relative;

            $symlinkReason = self::symlinkUnresolvedReason($repositoryRoot, $absolute);
            if ($symlinkReason !== null) {
                $unresolved[$relative] = $symlinkReason;

                continue;
            }

            if (! is_file($absolute)) {
                $unresolved[$relative] = '追跡下だが通常ファイルとして読めない';

                continue;
            }

            $classification = self::classify($relative);
            if ($classification === null) {
                $unclassified[] = $relative;

                continue;
            }

            if ($classification['class'] === LegacyUrlScanClass::NotScanned) {
                $notScanned[$relative] = (string) $classification['reason'];

                continue;
            }

            $contents = @file_get_contents($absolute);
            if ($contents === false) {
                $unresolved[$relative] = 'ファイルの読み取りに失敗';

                continue;
            }
            $contentsReason = self::contentsUnresolvedReason($contents);
            if ($contentsReason !== null) {
                $unresolved[$relative] = $contentsReason;

                continue;
            }

            $file = new LegacyUrlScannedFile(
                relative: $relative,
                contents: $contents,
                mode: $classification['mode'] ?? LegacyUrlExtractionMode::PlainText,
                ruleId: (string) $classification['rule'],
            );

            if ($classification['class'] === LegacyUrlScanClass::SelfCheckOnly) {
                $selfCheckOnly[] = $file;

                continue;
            }

            $scanned[] = $file;
        }

        return self::$memoized = new LegacyUrlScanPopulation(
            scanned: $scanned,
            selfCheckOnly: $selfCheckOnly,
            notScanned: $notScanned,
            unclassified: $unclassified,
            unresolved: $unresolved,
        );
    }

    /**
     * git 追跡下の相対パス全数。
     *
     * @return list<string>
     */
    private static function trackedPaths(string $repositoryRoot): array
    {
        $process = new Process(['git', 'ls-files', '-z'], $repositoryRoot);
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
