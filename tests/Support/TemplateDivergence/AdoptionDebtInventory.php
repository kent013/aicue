<?php

declare(strict_types=1);

namespace Tests\Support\TemplateDivergence;

use RuntimeException;

/**
 * 採用時債務一覧 — 「採用時点で内容が食い違っていたが、登録簿に説明が無いパス」と
 * **その時点のアプリ側 sha256**。
 *
 * ★**免除の許可一覧ではない**。採用時点の凍結された観測である。
 *   ハッシュを持つので「採用時の姿のまま」と「採用後に手を入れた」を区別でき、
 *   後者は違反になる (パスだけを持つ形は、そのパスに対する恒久的な許可一覧になってしまう)。
 * ★一覧が縮む契機は 2 つ = (1) 内容をテンプレートへ戻す /
 *   (2) 意図的逸脱として登録簿へ書く。期限による棚卸しは登録簿の D34
 *   (`監視中` + 見直し期限) が持つ。
 * ★**保証しないもの**: 一覧へ行を足す変更は機械では止まらない (生成器のガードと
 *   件数 pin の PR 差分に依存する)。各パスが意図的逸脱なのか追従遅れなのかは分類していない。
 *
 * 書式は 1 行 1 件・タブ区切りの 2 列で、先頭行が**世代識別子のヘッダ**である:
 *
 *     # template_ledger_commit=<40 桁小文字 hex>
 *     <repo-relative パス>\t<採用時のアプリ側 sha256>
 *
 * ヘッダの値は指紋台帳の `generated_at_commit` と突き合わせる (突合 gate の F14)。
 * 2 生成物は別ディレクトリなのでセット単位の原子性を主張できず、
 * **片方だけが更新された状態**はこのヘッダの不一致として落ちる。
 */
final class AdoptionDebtInventory
{
    /** 一覧の置き場 (リポジトリ相対)。登録簿の対象パスとしても登録されている (D34)。 */
    public const string INVENTORY_PATH = 'tests/Support/TemplateDivergence/adoption-debt.tsv';

    /** ヘッダ行の正準形。 */
    private const string HEADER_PATTERN = '/^# template_ledger_commit=([0-9a-f]{40})$/';

    /** インスタンス化しない (純関数のみ)。 */
    private function __construct() {}

    /**
     * リポジトリの一覧ファイルを読んで検証済みの内容を返す。
     *
     * **読めないことは空ではなく例外**にする (fail-open を作らない)。
     *
     * @return array{templateLedgerCommit: string, entries: array<string, string>}
     *
     * @throws RuntimeException
     */
    public static function read(string $root): array
    {
        $path = rtrim($root, '/').'/'.self::INVENTORY_PATH;
        $contents = is_file($path) && ! is_link($path) ? file_get_contents($path) : false;

        if ($contents === false) {
            throw new RuntimeException("採用時債務一覧を読めない (実行不能として落とす): {$path}");
        }

        return self::parse($contents);
    }

    /**
     * 一覧の本文を検証して返す。
     *
     * 落とす形 (内容側 10 形。読み取り失敗を合わせて詳細設計の 11 形):
     * 空 / 先頭行が世代識別子のヘッダでない / 末尾改行が無い / 空行 /
     * 列がタブ 2 列でない / 前後に空白がある / パスの重複 /
     * パスが `RepoRelativePath::isValid()` を通らない / ハッシュが 64 桁小文字 hex でない /
     * パスの昇順でない。
     *
     * @return array{templateLedgerCommit: string, entries: array<string, string>}
     *
     * @throws RuntimeException
     */
    public static function parse(string $contents): array
    {
        if ($contents === '') {
            throw new RuntimeException('採用時債務一覧が空である (ヘッダ行だけでも必要である)');
        }
        if (! str_ends_with($contents, "\n")) {
            throw new RuntimeException('採用時債務一覧の末尾改行が無い');
        }

        // 末尾の改行 1 つだけを落とす (余分な改行は空行として検出させる)
        $lines = explode("\n", substr($contents, 0, -1));

        $header = array_shift($lines);
        if ($header === null || preg_match(self::HEADER_PATTERN, $header, $matches) !== 1) {
            throw new RuntimeException('採用時債務一覧の先頭行が `# template_ledger_commit=<40 桁小文字 hex>` でない');
        }

        $entries = [];
        foreach ($lines as $index => $line) {
            $lineNumber = $index + 2; // ヘッダが 1 行目
            if ($line === '') {
                throw new RuntimeException("採用時債務一覧の {$lineNumber} 行目が空行である");
            }

            $columns = explode("\t", $line);
            if (count($columns) !== 2) {
                throw new RuntimeException("採用時債務一覧の {$lineNumber} 行目がタブ区切りの 2 列でない");
            }

            [$path, $hash] = $columns;
            if (trim($path) !== $path || trim($hash) !== $hash) {
                throw new RuntimeException("採用時債務一覧の {$lineNumber} 行目の値に前後の空白がある");
            }
            if (! RepoRelativePath::isValid($path)) {
                throw new RuntimeException("採用時債務一覧の {$lineNumber} 行目のパスが単一ファイルパスでない: {$path}");
            }
            if (preg_match('/^[0-9a-f]{64}$/', $hash) !== 1) {
                throw new RuntimeException("採用時債務一覧の {$lineNumber} 行目のハッシュが 64 桁小文字 hex でない");
            }
            if (array_key_exists($path, $entries)) {
                throw new RuntimeException("採用時債務一覧のパスが重複している: {$path}");
            }

            $entries[$path] = $hash;
        }

        $sortedKeys = array_keys($entries);
        sort($sortedKeys, SORT_STRING);
        if (array_keys($entries) !== $sortedKeys) {
            throw new RuntimeException('採用時債務一覧がパスの昇順でない (生成器で再生成すること)');
        }

        return ['templateLedgerCommit' => $matches[1], 'entries' => $entries];
    }

    /**
     * 検証済みの内容から一覧の本文を組み立てる (生成器が使う。読み書きの正準形を 1 か所にする)。
     *
     * @param  array<string, string>  $entries
     */
    public static function render(string $templateLedgerCommit, array $entries): string
    {
        ksort($entries, SORT_STRING);

        $text = '# template_ledger_commit='.$templateLedgerCommit."\n";
        foreach ($entries as $path => $hash) {
            $text .= $path."\t".$hash."\n";
        }

        return $text;
    }
}
