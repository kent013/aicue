<?php

declare(strict_types=1);

namespace Tests\Support\LegacyUrl;

use Tests\Support\SourceLiterals;

/**
 * 組織を持たない**旧 URL** と**撤去した route 名**の検出器 (家系裁定 AG-037 / 施策 10)。
 *
 * ## 何を検出するか (2 つの台帳。同じ台帳にしない)
 *
 * | 台帳 | 対象 | 中身 |
 * |---|---|---|
 * | 旧パス台帳 | URL 文字列 | 単位 B で組織 URL 配下へ移した業務パスの**根** |
 * | 撤去 route 名台帳 | route 名 | `organizations.switch` の 1 本だけ (他の route 名は維持されている) |
 *
 * ## 判定 (正規化済み path の root 一致)
 *
 * query (`?`) と hash (`#`) を落とした path が、**根と完全一致するか `根/` で始まる**ときに旧パスと判定する。
 * 前方一致だけでは末尾スラッシュなしの根 (`/projects`) を拾えず、素の部分文字列一致では
 * `/projectsomething` まで拾ってしまう。
 *
 * ## 誤検出を作らない 3 つの規則 (実測で必要になった順)
 *
 * 1. **根の直前が語の一部なら旧パスではない**。`/organizations/acme/projects` の `/projects` は
 *    直前が英数字なので根の位置に無い。同じ理由で `/billing/purchase-tickets` も拾わない。
 * 2. **組織セグメントの直後は新 URL である**。`organizations/{organization}/projects` /
 *    `` `/organizations/${slug}/billing` `` のように直前が置換子で終わる形を明示的に許す
 *    (規則 1 の英数字判定では `}` を拾ってしまうため)。先頭スラッシュの有無は問わない
 *    (route 表を写した目録は `organizations/{organization}/…` と書く)。
 * 3. **組織 URL 組み立ての入口に渡した相対パスは新 URL である**。フロントの
 *    `orgUrl(slug, '/projects')` / `currentOrgUrl('/projects')` は
 *    `resources/js/lib/org-url.ts` が組織 prefix を必ず付ける唯一の入口である。
 *
 * ## 保証しないもの (誇張しない)
 *
 * - **scheme と host を伴う絶対 URL は対象外**である (`https://example.com/dashboard`)。
 *   外部サービスの URL と自アプリの URL を字面で区別できないため、
 *   host の後ろの path は根の位置と見なさない。
 * - 実行時に組み立てる形 (`'/dash'.$suffix` / `'/' + name`) には**無言で効かない**。
 * - `LegacyUrlExtractionMode::SourceLiteral` のファイルでは**コメントを見ない**。
 *   撤去を説明する散文は参照ではないためである。
 * - 利用者のブックマーク・外部サービスに登録済みの URL・送信済みメール本文・
 *   ブラウザの履歴は**リポジトリの外**にあり、本検査の対象にならない。
 */
final class LegacyUrlScanner
{
    /** 規則 ID: PHP の文字列リテラル。 */
    public const string RULE_PHP_LITERAL = 'php-string-literal';

    /** 規則 ID: TypeScript / JavaScript / Svelte / Python の文字列リテラル。 */
    public const string RULE_SCRIPT_LITERAL = 'script-string-literal';

    /** 規則 ID: Blade テンプレートの全文。 */
    public const string RULE_BLADE_TEXT = 'blade-text';

    /** 規則 ID: Markdown の全文 (リンクの宛先とプレーン URL の両方を含む)。 */
    public const string RULE_MARKDOWN_TEXT = 'markdown-text';

    /** 規則 ID: JSON / TOML / YAML / TSV / テキスト等のデータの全文。 */
    public const string RULE_DATA_TEXT = 'data-text';

    /** 規則 ID: 撤去した route 名 (旧パスとは別台帳)。 */
    public const string RULE_REMOVED_ROUTE_NAME = 'removed-route-name';

    /**
     * 全文走査での終端集合 (走査器共通規約 (e) の宣言)。
     *
     * ★空白文字全般 (半角空白・タブ・改行・復帰・全角空白) と、閉じ記号・句読点の**列挙**である。
     *   **この列挙が保証範囲**であり、ここに無い終端 (`.` など) は保証しない
     *   (`.` を入れると `app.example.com` のホスト名を旧パスとして拾ってしまう)。
     *
     * @var list<string>
     */
    public const array PLAIN_TEXT_TERMINATORS = [
        ' ', "\t", "\n", "\r", "\u{3000}",
        ')', ']', '}', '>', '"', "'", '`', ',', ';', ':', '。', '、', '）', '｜', '|',
    ];

    /** インスタンス化しない (純関数の置き場)。 */
    private function __construct() {}

    /**
     * 組織 URL 配下へ移した業務パスの**根** (先頭スラッシュつき)。
     *
     * ★**検出語を文字列リテラルとして持たない** — 断片を連結して組み立てる。
     *   こうしないと本走査器と利用側 gate が自分自身の走査に引っかかり、
     *   「旧 URL 文字列を持つ例外目録」という再帰を作ることになる。
     * ★根の出典: 単位 B の前後で `Route::getRoutes()` の第 1 セグメントから消えたものである
     *   (`billing` / `billing-required` / `dashboard` / `manage` / `notifications` /
     *   `onboarding` / `projects` / `purchase-tickets`)。`app` は**正規の分岐入口として残る**ため
     *   `CaptureEntryUrlAllowance` の許可目録と対で扱う。
     *
     * @return list<string>
     */
    public static function legacyRoots(): array
    {
        return [
            '/'.'bill'.'ing'.'-required',
            '/'.'purchase'.'-tickets',
            '/'.'notifi'.'cations',
            '/'.'onboar'.'ding',
            '/'.'dash'.'board',
            '/'.'proj'.'ects',
            '/'.'bill'.'ing',
            '/'.'man'.'age',
            self::captureRoot(),
        ];
    }

    /**
     * 撮影 PWA の根 (断片から組み立てる)。**この根だけは配下つきでのみ旧 URL である**。
     *
     * ★裸のこれは**正規の分岐入口** (`capture.entry`) であり、今後も残る
     *   (PWA の `start_url` / robots の宣言 / 入口の Feature テストが正しく持つ)。
     *   旧 URL なのは配下 (`…/projects/…` 等) を持つ形だけで、そちらは
     *   組織 URL 配下 (`/organizations/{slug}/app/…`) へ移設済みである。
     * ★この「配下つきのみ」規則があるので、正規入口のための許可目録は要らない
     *   (許可目録は**目録の中身が旧 URL 文字列を持つ**という再帰を招きやすく、
     *   規則で表せるならそちらが良い)。
     */
    public static function captureRoot(): string
    {
        return '/'.'a'.'pp';
    }

    /** 撤去した route 名 (断片から組み立てる)。 */
    public static function removedRouteName(): string
    {
        return 'organizations.'.'switch';
    }

    /** 組織セグメントの接頭辞 (断片から組み立てる。規則 2 の判定に使う)。 */
    public static function organizationSegment(): string
    {
        return 'organi'.'zations';
    }

    /**
     * 1 ファイル分の検出。
     *
     * @return list<LegacyUrlOccurrence>
     */
    public static function scanFile(LegacyUrlScannedFile $file): array
    {
        $occurrences = [];

        foreach (self::extract($file) as $chunk) {
            foreach (self::matchesIn($chunk['value']) as $matched) {
                $occurrences[] = new LegacyUrlOccurrence(
                    relative: $file->relative,
                    line: $chunk['line'],
                    ruleId: $file->ruleId,
                    matched: $matched,
                );
            }
            if (str_contains($chunk['value'], self::removedRouteName())) {
                $occurrences[] = new LegacyUrlOccurrence(
                    relative: $file->relative,
                    line: $chunk['line'],
                    ruleId: self::RULE_REMOVED_ROUTE_NAME,
                    matched: self::removedRouteName(),
                );
            }
        }

        return $occurrences;
    }

    /**
     * 抽出方式に従って走査対象の断片を取り出す。
     *
     * @return list<array{line: int, value: string}>
     */
    public static function extract(LegacyUrlScannedFile $file): array
    {
        if ($file->mode === LegacyUrlExtractionMode::PlainText) {
            $chunks = [];
            foreach (explode("\n", $file->contents) as $index => $line) {
                $chunks[] = ['line' => $index + 1, 'value' => $line];
            }

            return $chunks;
        }

        if ($file->ruleId === self::RULE_PHP_LITERAL) {
            return SourceLiterals::php($file->contents);
        }

        return self::scriptLiteralsWithOrgUrlAllowance($file->contents, str_ends_with($file->relative, '.py'));
    }

    /**
     * script のリテラル抽出。**組織 URL 組み立ての入口へ渡した相対パスは除く** (規則 3)。
     *
     * ★判定は**そのリテラルの開始位置**で行う (同じ値が入口の外にも書かれていれば
     *   そちらは残る = 入口の中に 1 度書いたことで外の直書きまで許してしまうことはない)。
     *
     * @return list<array{line: int, offset: int, value: string}>
     */
    private static function scriptLiteralsWithOrgUrlAllowance(string $source, bool $hashComments): array
    {
        $kept = [];
        foreach (SourceLiterals::script($source, $hashComments) as $literal) {
            if (self::isOrganizationUrlBuilderArgument($source, $literal['offset'])) {
                continue;
            }
            $kept[] = $literal;
        }

        return $kept;
    }

    /**
     * その位置のリテラルが `orgUrl(...)` / `currentOrgUrl(...)` の引数として現れているか。
     *
     * ★「開き括弧を閉じないまま入口名まで遡れるか」で判定する。`[^()]*` が括弧を跨がせないので、
     *   入口を呼んだ後の別の呼び出し (`foo(bar(), '/x')`) は一致しない。
     */
    private static function isOrganizationUrlBuilderArgument(string $source, int $literalOffset): bool
    {
        $before = substr($source, 0, $literalOffset);

        return preg_match('/(?:currentOrgUrl|orgUrl)\(\s*[^()]*$/', $before) === 1;
    }

    /**
     * 1 つの断片に含まれる旧パスの根 (重複を保つ = 件数がそのまま出現数になる)。
     *
     * @return list<string>
     */
    public static function matchesIn(string $chunk): array
    {
        $matches = [];
        $organizationSegment = self::organizationSegment().'/';

        foreach (self::legacyRoots() as $root) {
            $offset = 0;
            while (($position = strpos($chunk, $root, $offset)) !== false) {
                $offset = $position + 1;

                if (! self::isRootPosition($chunk, $position, $organizationSegment)) {
                    continue;
                }
                $end = $position + strlen($root);
                if ($root === self::captureRoot()) {
                    // 配下つきのときだけ旧 URL (裸は正規の分岐入口)
                    if (! self::hasSubPathAfter($chunk, $end)) {
                        continue;
                    }
                } elseif (! self::isPathBoundaryAfter($chunk, $end)) {
                    continue;
                }
                $matches[] = $root;
            }
        }

        return $matches;
    }

    /**
     * 根の位置に現れているか (規則 1 と規則 2)。
     *
     * ★直前が英数字・`.`・`_`・`-`・`/` のいずれかなら根ではない (長いパスの途中 /
     *   ホスト名 / scheme 直後の `//`)。
     * ★ただし直前が組織セグメントの置換子で終わっているときは**新 URL** なので、
     *   やはり根ではない (規則 2)。
     */
    private static function isRootPosition(string $chunk, int $position, string $organizationSegment): bool
    {
        if ($position === 0) {
            return true;
        }

        $previous = $chunk[$position - 1];
        if (ctype_alnum($previous) || in_array($previous, ['.', '_', '-', '/'], true)) {
            return false;
        }

        // 規則 2: `organizations/{organization}` / `organizations/${slug}` / `organizations/<slug>` の直後
        $before = substr($chunk, 0, $position);
        $pattern = '/'.preg_quote($organizationSegment, '/').'(?:\{[^}]*\}|\$\{[^}]*\}|<[^>]*>)$/';

        return preg_match($pattern, $before) !== 1;
    }

    /**
     * 根の直後が path の区切りか (根と完全一致 or `根/` で始まる)。
     *
     * ★`?` と `#` は query / hash の始まりなので path の終端として扱う。
     * ★それ以外は `PLAIN_TEXT_TERMINATORS` の列挙だけを終端と認める
     *   (`/appx` `/app-old` `/myapp` を拾わない = 走査器共通規約 (e) の 3 形)。
     */
    /**
     * 根の直後に**配下のセグメントがあるか** (`/app` 専用)。
     *
     * ★`/` に続いて終端でない文字が 1 つ以上あることを求める。
     *   裸の `/app` と末尾スラッシュだけの `/app/` は正規入口とみなして拾わない。
     */
    private static function hasSubPathAfter(string $chunk, int $end): bool
    {
        if ($end + 1 >= strlen($chunk) || $chunk[$end] !== '/') {
            return false;
        }

        $next = substr($chunk, $end + 1);
        foreach (self::PLAIN_TEXT_TERMINATORS as $terminator) {
            if (str_starts_with($next, $terminator)) {
                return false;
            }
        }

        return true;
    }

    private static function isPathBoundaryAfter(string $chunk, int $end): bool
    {
        if ($end >= strlen($chunk)) {
            return true;
        }

        $next = substr($chunk, $end, 1);
        if ($next === '/' || $next === '?' || $next === '#' || $next === '\\') {
            return true;
        }

        foreach (self::PLAIN_TEXT_TERMINATORS as $terminator) {
            if (str_starts_with(substr($chunk, $end), $terminator)) {
                return true;
            }
        }

        return false;
    }
}
