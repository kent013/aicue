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
     * 撮影 PWA の根 (断片から組み立てる)。
     *
     * ★裸のこれは**正規の分岐入口** (`capture.entry`) として残るが、**規則では免除しない**。
     *   免除すると「どこにでも直書きしてよい」ことになり、
     *   「入口への導線は route helper 経由だけ」という不変条件が消えるためである。
     *   正規入口として実在する出現は `LegacyUrlAllowance` へ**パス + 規則 + 語 + 件数**で
     *   exact-fit 登録する (目録は旧 URL 文字列を持たず、語は走査器が組み立てた値を使う)。
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

    /**
     * 組織 URL 組み立ての唯一の入口 module (規則 3 の前提)。
     *
     * ★断片から組み立てる必要は無い (旧 URL の語ではない)。
     */
    public const string ORGANIZATION_URL_MODULE = '@/lib/org-url';

    /**
     * 入口として認める**取り込み元の名前** (規則 3)。
     *
     * ★同じ module の別の export (識別名だけを返す関数など) は入口ではない。
     *   取り込み元を見ないと、別名つき取り込みで何でも入口にできてしまう。
     *
     * @var list<string>
     */
    public const array ORGANIZATION_URL_BUILDERS = ['orgUrl', 'currentOrgUrl'];

    /** 組織セグメントの接頭辞 (断片から組み立てる。規則 2 の判定に使う)。 */
    public static function organizationSegment(): string
    {
        return 'organi'.'zations';
    }

    /** 構文文脈: 判定できなかった (式の途中・連結など)。 */
    public const string CONTEXT_EXPRESSION = 'expr';

    /** 構文文脈: 素の本文 (鍵にもリンクにも付いていない)。 */
    public const string CONTEXT_TEXT = 'text';

    /** 構文文脈: Markdown のリンクの宛先。 */
    public const string CONTEXT_MARKDOWN_LINK = 'markdown-link';

    /**
     * 1 ファイル分の検出。
     *
     * @return list<LegacyUrlOccurrence>
     */
    public static function scanFile(LegacyUrlScannedFile $file): array
    {
        $occurrences = [];
        $maskedSource = $file->mode === LegacyUrlExtractionMode::SourceLiteral
            ? SourceLiterals::maskComments($file->contents, str_ends_with($file->relative, '.py'))
            : null;

        foreach (self::extract($file) as $chunk) {
            foreach (self::matchesIn($chunk['value']) as $match) {
                $occurrences[] = new LegacyUrlOccurrence(
                    relative: $file->relative,
                    line: $chunk['line'],
                    ruleId: $file->ruleId,
                    matched: $match['root'],
                    path: $match['path'],
                    context: $maskedSource === null
                        ? self::plainTextContext($chunk['value'], $match['offset'])
                        : self::sourceLiteralContext($maskedSource, $chunk['offset']),
                );
            }
            // ★出現ごとに 1 件数える (1 行 1 件にすると、同じ行へ 2 個目を足しても
            //   件数が変わらず exact-fit の許可目録を迂回できる)
            $removedRouteName = self::removedRouteName();
            for ($i = substr_count($chunk['value'], $removedRouteName); $i > 0; $i--) {
                $occurrences[] = new LegacyUrlOccurrence(
                    relative: $file->relative,
                    line: $chunk['line'],
                    ruleId: self::RULE_REMOVED_ROUTE_NAME,
                    matched: $removedRouteName,
                    path: $removedRouteName,
                    context: $maskedSource === null
                        ? self::plainTextContext($chunk['value'], (int) strpos($chunk['value'], $removedRouteName))
                        : self::sourceLiteralContext($maskedSource, $chunk['offset']),
                );
            }
        }

        return $occurrences;
    }

    /**
     * 抽出方式に従って走査対象の断片を取り出す。
     *
     * @return list<array{line: int, offset: int, value: string}>
     */
    public static function extract(LegacyUrlScannedFile $file): array
    {
        if ($file->mode === LegacyUrlExtractionMode::PlainText) {
            $chunks = [];
            $offset = 0;
            foreach (explode("\n", $file->contents) as $index => $line) {
                $chunks[] = ['line' => $index + 1, 'offset' => $offset, 'value' => $line];
                $offset += strlen($line) + 1;
            }

            return $chunks;
        }

        if ($file->ruleId === self::RULE_PHP_LITERAL) {
            $literals = SourceLiterals::php($file->contents);

            return str_starts_with($file->relative, 'routes/')
                ? self::withoutRouteDefinitionUris($file->contents, $literals)
                : $literals;
        }

        return self::scriptLiteralsWithOrgUrlAllowance($file->contents, str_ends_with($file->relative, '.py'));
    }

    /**
     * route 定義の **URI 引数**だけを外したリテラル列 (`routes/` 専用の規則 4)。
     *
     * ★`routes/web.php` の URI は group の prefix からの**相対セグメント**であり、
     *   組織 prefix の中では根だけの記述が正しい姿になる。解決済みの route 表が
     *   1 本残らず組織 URL 配下にあることは `OrganizationScopedRouteCoverageTest` が固定する。
     * ★外すのは**その 1 引数だけ**である。`redirect('/projects')` のような route 定義以外の
     *   リテラルと、撤去 route 名は `routes/` の中でも引き続き検出する
     *   (ファイルごと走査から外すと、そこが抜け道になる)。
     * ★判定は「リテラルの直前が route 定義の呼び出しで、括弧も改行も跨がない」ことである。
     *   動的に組み立てた URI (`Route::get($uri, …)`) はそもそもリテラルではないので対象外である。
     *
     * @param  list<array{line: int, offset: int, value: string}>  $literals
     * @return list<array{line: int, offset: int, value: string}>
     */
    private static function withoutRouteDefinitionUris(string $source, array $literals): array
    {
        $kept = [];
        foreach ($literals as $literal) {
            $before = substr($source, 0, $literal['offset']);
            // ★外すのは **URI を受ける引数**だけである。`->name()` / `->as()` を外すと
            //   撤去 route 名の台帳が routes/ の中で丸ごと効かなくなる (実測で指摘された穴)。
            $isRouteUri = preg_match(
                '/(?:Route::(?:get|post|put|patch|delete|options|any|view|redirect|permanentRedirect|match|prefix)'
                .'|->prefix)\(\s*(?:\[[^\]]*\]\s*,\s*)?$/',
                $before,
            ) === 1;
            if ($isRouteUri) {
                continue;
            }
            $kept[] = $literal;
        }

        return $kept;
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
        // ★前後関係の判定は**コメントを潰した写し**で行う (位置は元と同じ)。
        //   生ソースを見ると `// currentOrgUrl(` の 1 行が次のリテラルまで届いて免除になる
        $masked = SourceLiterals::maskComments($source, $hashComments);
        // ★入口の名前は **import 宣言から解決する**。部分文字列一致にすると
        //   コメントや文字列の中に module 名を書くだけで免除の前提を満たせてしまう。
        //   宣言を読むときは**コメントと文字列の両方を潰した写し**を使う。
        $builderNames = self::importedOrganizationUrlBuilders(
            $masked,
            SourceLiterals::stringSpans($source, $hashComments),
        );

        $kept = [];
        foreach (SourceLiterals::script($source, $hashComments) as $literal) {
            if ($builderNames !== [] && self::isOrganizationUrlBuilderArgument($masked, $literal['offset'], $builderNames)) {
                continue;
            }
            $kept[] = $literal;
        }

        return $kept;
    }

    /**
     * 組織 URL 組み立ての module から**実際に取り込まれたローカル名**。
     *
     * ★`import { orgUrl, currentOrgUrl as u } from "@/lib/org-url";` の形を構文で読む。
     *   別名つき取り込みは別名側を返す (呼び出しに現れる名前で照合するため)。
     * ★入力は**コメントを潰した写し**である (コメントの中の import 宣言では前提を満たせない)。
     *
     * @param  list<array{int, int}>  $stringSpans  文字列リテラルが占める範囲
     * @return list<string>
     */
    private static function importedOrganizationUrlBuilders(string $maskedSource, array $stringSpans): array
    {
        $pattern = '/import\s*\{([^}]*)\}\s*from\s*[\'"]'
            .preg_quote(self::ORGANIZATION_URL_MODULE, '/')
            .'[\'"]/';
        if (preg_match_all($pattern, $maskedSource, $matches, PREG_OFFSET_CAPTURE) === false) {
            return [];
        }

        $names = [];
        foreach ($matches[1] as $index => $capture) {
            // ★宣言そのものが**文字列の中**にあるなら偽の宣言である
            //   (`const example = 'import { orgUrl } from "…"';`)
            $declarationOffset = (int) $matches[0][$index][1];
            if (self::isInsideAnySpan($declarationOffset, $stringSpans)) {
                continue;
            }

            $clause = (string) $capture[0];
            foreach (explode(',', $clause) as $specifier) {
                $parts = preg_split('/\s+as\s+/', trim($specifier)) ?: [];
                $imported = trim((string) ($parts[0] ?? ''));
                $local = trim((string) end($parts));

                // ★**取り込み元の名前**が入口の 2 本のどちらかであることを確かめる。
                //   ここを見ないと `currentOrganizationSlug as passthrough` のような
                //   別の関数まで入口として扱ってしまう。
                if (! in_array($imported, self::ORGANIZATION_URL_BUILDERS, true)) {
                    continue;
                }
                if (preg_match('/\A[A-Za-z_$][A-Za-z0-9_$]*\z/', $local) === 1
                    && in_array($local, $names, true) === false) {
                    $names[] = $local;
                }
            }
        }

        return $names;
    }

    /**
     * 位置がいずれかの範囲の内側か。
     *
     * @param  list<array{int, int}>  $spans
     */
    private static function isInsideAnySpan(int $offset, array $spans): bool
    {
        foreach ($spans as [$start, $end]) {
            if ($offset >= $start && $offset < $end) {
                return true;
            }
        }

        return false;
    }

    /**
     * その位置のリテラルが `orgUrl(...)` / `currentOrgUrl(...)` の引数として現れているか (規則 3)。
     *
     * ★条件は 3 つとも満たすこと:
     *   1. 呼び出し名の直前が**識別子の文字でない** (`notOrgUrl(` / `x.orgUrl(` を弾く)
     *   2. 開き括弧から遡る間に**括弧を跨がない** (別の呼び出しの引数を免除しない)
     *   3. 名前が **import 宣言から解決したローカル名**であること (呼び出し側で解決済み)
     *
     * ★入力は**コメントを潰した写し**である (呼び出し側が渡す)。生ソースを渡すと
     *   コメントの中の `orgUrl(` が次のリテラルまで届いて免除を作れてしまう。
     *
     * @param  list<string>  $builderNames
     */
    private static function isOrganizationUrlBuilderArgument(string $maskedSource, int $literalOffset, array $builderNames): bool
    {
        $before = substr($maskedSource, 0, $literalOffset);
        $alternatives = implode('|', array_map(
            static fn (string $name): string => preg_quote($name, '/'),
            $builderNames,
        ));

        return preg_match('/(?<![A-Za-z0-9_$.])(?:'.$alternatives.')\(\s*[^()]*$/', $before) === 1;
    }

    /**
     * 1 つの断片に含まれる旧パス (重複を保つ = 件数がそのまま出現数になる)。
     *
     * ★返すのは**根と、根から終端までの path 全体**の 2 つである。
     *   許可目録のキーには根を使い (目録が旧 URL 文字列を持たないため)、
     *   **区分ごとの前提の判定には path 全体を使う** — 根だけだと
     *   「同じ根で別の path へ置き換える」迂回 (`/app` → `/app/projects/1`) を止められない。
     *
     * @return list<array{root: string, path: string, offset: int}>
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
                if (! self::isPathBoundaryAfter($chunk, $position + strlen($root))) {
                    continue;
                }
                $matches[] = [
                    'root' => $root,
                    'path' => self::pathAt($chunk, $position),
                    'offset' => $position,
                ];
            }
        }

        return $matches;
    }

    /**
     * 全文走査での構文文脈 (鍵 / Markdown リンク / 素の本文)。
     *
     * ★語彙は**限定列挙**である (`key:<名前>` / `markdown-link` / `text`)。
     *   許可目録のキーに入るので、「同じ path を別の構文位置へ移す」と文脈が変わって赤くなる。
     * ★判定は一致位置の**直前**だけを見る発見的規則である (構文解析ではない)。
     *   判定できない形は `text` へ倒す (`expr` は source 側の語彙)。
     */
    public static function plainTextContext(string $chunk, int $position): string
    {
        $before = substr($chunk, 0, $position);

        if (preg_match('/[\'"]?([A-Za-z0-9_.\-]+)[\'"]?\s*[:=]\s*[\'"`]?\s*$/', $before, $matches) === 1) {
            return 'key:'.$matches[1];
        }
        if (preg_match('/\]\(\s*$/', $before) === 1) {
            return self::CONTEXT_MARKDOWN_LINK;
        }

        return self::CONTEXT_TEXT;
    }

    /**
     * ソースの構文文脈 (呼び出しの引数 / それ以外)。
     *
     * ★語彙は**限定列挙**である (`call:<名前>` / `expr`)。
     *   `->` の有無は区別しない (メソッドでも関数でも「その名前の呼び出しの引数」であることが要点)。
     * ★入力は**コメントを潰した写し**である。判定は発見的規則で、
     *   連結や変数への代入は `expr` へ倒れる (構文解析ではない)。
     */
    public static function sourceLiteralContext(string $maskedSource, int $literalOffset): string
    {
        $before = substr($maskedSource, 0, $literalOffset);

        if (preg_match('/([A-Za-z_$][A-Za-z0-9_$]*)\s*\(\s*[^()]*$/', $before, $matches) === 1) {
            return 'call:'.$matches[1];
        }

        return self::CONTEXT_EXPRESSION;
    }

    /**
     * 根の位置から**終端まで**の path 全体を切り出す。
     *
     * ★終端は `PLAIN_TEXT_TERMINATORS` と query (`?`) / hash (`#`) / バックスラッシュである。
     *   query 以降は path ではないので含めない。
     */
    private static function pathAt(string $chunk, int $position): string
    {
        $length = strlen($chunk);
        for ($end = $position; $end < $length; $end++) {
            $rest = substr($chunk, $end);
            if ($chunk[$end] === '?' || $chunk[$end] === '#' || $chunk[$end] === '\\') {
                break;
            }
            foreach (self::PLAIN_TEXT_TERMINATORS as $terminator) {
                if (str_starts_with($rest, $terminator)) {
                    break 2;
                }
            }
        }

        return substr($chunk, $position, $end - $position);
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
