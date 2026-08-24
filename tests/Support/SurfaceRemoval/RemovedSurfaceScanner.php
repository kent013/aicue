<?php

declare(strict_types=1);

namespace Tests\Support\SurfaceRemoval;

use ParseError;
use Tests\Support\PhpTokenScan;

/**
 * 撤去語の出現と**構文上の形**だけを返す純関数群 (許可ポリシーを持たない)。
 *
 * ★語彙一致は `TOKEN_CHARACTERS` で分割した run のトークン完全一致で判定する
 *   (正規表現の語境界にも素の部分文字列一致にも頼らない。AGENTS.md「静的検査の共通規約」(e))。
 *   区切りは**宣言した文字集合の外のすべてのバイト**であり、UTF-8 の多バイト文字は
 *   すべて区切りになる (ASCII 以外はトークン文字に入れていない)。
 * ★クラス参照は完全修飾名 (ASCII 大小無視) で突き合わせる (同 (a))。解決は `PhpNameResolver`。
 * ★PHP は「文字列リテラル」ではなく **lexeme** を見る。文字列リテラルだけに限ると
 *   `public bool $imageSourceDocumentsEnabled;` や `const OCR_ANALYSIS_ENABLED = true;` での
 *   復活を検出できない。
 * ★PHP は**構文検証を先に行い**、`ParseError` を投げるファイルは未解決にする (fail-closed)。
 *   捕まえるのは `ParseError` **だけ**である (親型 `\Error` まで捕まえると、予期しない実行時障害まで
 *   「解析未解決」へ変換してしまい、本来テストを落とすべき異常が別の意味に化ける)。
 *   正規化は既存の単一出典 `Tests\Support\PhpTokenScan::normalize()` を使う (挙動は変えない)。
 *
 * ★**保証しないもの (検出力を誇張しない)**:
 *   - 撤去語を分割して連結する書き方・定数経由の参照・実行時に組み立てた文字列には沈黙する。
 *   - PHP のコメント / docblock の中では沈黙する (`normalize()` が落とすため)。
 *   - **middleware 位置に現れる変数・式** (`->middleware($alias)` /
 *     `->middleware('throttle:'.$limiter)`) は**クラス参照でも文字列リテラルでもない**ため
 *     母集団に入らない。これは許可一覧ではなく**規則の段階での定義**である
 *     (`X::class` 構文だけをクラス参照として扱い、受け手が名前でないものは未解決にする)。
 *     実体化した route については実行時層 (`PasswordConfirmMiddlewareAbsenceTest`) が補完する。
 *   - `FqcnMethodReference` は `クラス部::メソッド名` が**空白を挟まず**並んでいる形だけを見る。
 *   - NUL を含むファイルは母集団に入らない (`RemovedSurfaceScanTargets`。利用側は 0 件を要求する)。
 * ★解決できない形は**未解決として分けて返す** (空配列へ混ぜない)。利用側 gate は必ず
 *   `ScanOutcome::mergeUnresolved()` で空を要求すること。
 */
final class RemovedSurfaceScanner
{
    /**
     * トークン文字の集合。**これ以外のバイトはすべて区切り**である。
     * 生テキストはこの集合の**最長の連なり (run)** へ分割される。
     */
    private const string TOKEN_CHARACTERS = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_.-';

    /**
     * 完全修飾参照専用のトークン文字集合 (`\` を含み `.` `-` を含まない)。
     *
     * `TOKEN_CHARACTERS` では `\` と `:` が区切りになるため、完全修飾参照は複数の run へ割れて
     * 原理的に一致しない。専用の集合でクラス部とメソッド部を構文的に切り出す。
     */
    private const string FQCN_TOKEN_CHARACTERS = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_\\';

    /**
     * M1: middleware 位置を作る呼び出し名 (ASCII 大小無視の完全一致)。
     *
     * @var list<string>
     */
    private const array MIDDLEWARE_CALL_NAMES = [
        'middleware', 'withoutmiddleware', 'middlewaregroup', 'appendtogroup', 'prependtogroup', 'alias',
    ];

    /**
     * M3: middleware 位置を作るプロパティ名 (ASCII 大小無視の完全一致)。
     *
     * @var list<string>
     */
    private const array MIDDLEWARE_PROPERTY_NAMES = [
        '$middleware', '$middlewaregroups', '$middlewarepriority',
    ];

    /** インスタンス化しない (純関数の置き場)。 */
    private function __construct() {}

    /**
     * Tier 2: 生テキストを run へ分割してトークン完全一致で走査する。
     *
     * @param  list<ScannedFile>  $files
     * @return ScanOutcome<Occurrence>
     */
    public static function scanText(array $files, RemovedTerm $term): ScanOutcome
    {
        $occurrences = [];

        foreach ($files as $file) {
            if ($term->mode === TermMatchMode::FqcnMethodReference) {
                foreach (self::fqcnMethodOccurrences($file, $term) as $occurrence) {
                    $occurrences[] = $occurrence;
                }

                continue;
            }

            if ($term->mode === TermMatchMode::FqcnReference) {
                foreach (self::fqcnOccurrences($file, $term) as $occurrence) {
                    $occurrences[] = $occurrence;
                }

                continue;
            }

            foreach (self::runs($file->contents, self::TOKEN_CHARACTERS) as $run) {
                if (! self::runMatches($run['text'], $term)) {
                    continue;
                }
                $occurrences[] = new Occurrence(
                    $file->relative,
                    self::lineAt($file->contents, $run['offset']),
                    $run['text'],
                );
            }
        }

        return new ScanOutcome($occurrences, []);
    }

    /**
     * Tier 1: PHP の lexeme (識別子・変数・定数・文字列・heredoc・名前) を走査する。
     *
     * @param  list<ScannedFile>  $files
     * @return ScanOutcome<Occurrence>
     */
    public static function scanPhpLexemes(array $files, RemovedTerm $term): ScanOutcome
    {
        $occurrences = [];
        /** @var array<string, string> $unresolved */
        $unresolved = [];

        foreach ($files as $file) {
            if (! $file->isPhp) {
                continue;
            }
            $tokens = self::tokenize($file, $unresolved);
            if ($tokens === null) {
                continue;
            }

            foreach ($tokens as $token) {
                $lexeme = self::lexemeOf($token);
                if ($lexeme === null) {
                    continue;
                }
                foreach (self::runs($lexeme, self::TOKEN_CHARACTERS) as $run) {
                    if (! self::runMatches($run['text'], $term)) {
                        continue;
                    }
                    $occurrences[] = new Occurrence($file->relative, $token['line'], $run['text']);
                }
            }
        }

        return new ScanOutcome($occurrences, $unresolved);
    }

    /**
     * Tier 1: **middleware 位置**に現れる alias 文字列 / クラス参照を返す。
     *
     * middleware 位置の定義 (有限。これ以外は母集団に入らない):
     *   M1 呼び出し名が `middleware` / `withoutMiddleware` / `middlewareGroup` /
     *      `appendToGroup` / `prependToGroup` / `alias` の引数領域
     *   M2 キー名が `middleware` を部分文字列として含む (ASCII 大小無視) 配列要素の値の領域
     *   M3 プロパティ `$middleware` / `$middlewareGroups` / `$middlewarePriority` の初期化式の領域
     *
     * 領域からは **`X::class` 構文のクラス参照**と**文字列リテラル**だけを取り出す。
     * 受け手が名前でない `X::class` (`$cls::class`) は未解決にする。
     *
     * @param  list<ScannedFile>  $files
     * @return ScanOutcome<MiddlewareReference>
     */
    public static function scanMiddlewarePositions(array $files): ScanOutcome
    {
        $references = [];
        /** @var array<string, string> $unresolved */
        $unresolved = [];

        foreach ($files as $file) {
            if (! $file->isPhp) {
                continue;
            }
            $tokens = self::tokenize($file, $unresolved);
            if ($tokens === null) {
                continue;
            }
            $resolver = PhpNameResolver::analyze($tokens);
            $count = count($tokens);

            /** @var array<int, bool> $marks */
            $marks = [];
            for ($i = 0; $i < $count; $i++) {
                $id = $tokens[$i]['id'];
                $text = $tokens[$i]['text'];

                if ($id === T_STRING
                    && in_array(strtolower($text), self::MIDDLEWARE_CALL_NAMES, true)
                    && self::isChar($tokens, $i + 1, '(')) {
                    $close = self::matchingBracket($tokens, $i + 1);
                    if ($close === null) {
                        $unresolved[$file->relative] = 'middleware 呼び出しの括弧の対応を解決できない';

                        continue;
                    }
                    self::markRange($marks, $i + 2, $close - 1);

                    continue;
                }

                if ($id === T_CONSTANT_ENCAPSED_STRING
                    && isset($tokens[$i + 1])
                    && $tokens[$i + 1]['id'] === T_DOUBLE_ARROW
                    && str_contains(strtolower(self::unquote($text)), 'middleware')) {
                    $end = self::valueEnd($tokens, $i + 2);
                    if ($end === null) {
                        $unresolved[$file->relative] = 'middleware キーの値の範囲を解決できない';

                        continue;
                    }
                    self::markRange($marks, $i + 2, $end);

                    continue;
                }

                if ($id === T_VARIABLE
                    && in_array(strtolower($text), self::MIDDLEWARE_PROPERTY_NAMES, true)
                    && self::isChar($tokens, $i + 1, '=')) {
                    $end = self::valueEnd($tokens, $i + 2);
                    if ($end === null) {
                        $unresolved[$file->relative] = 'middleware プロパティの初期化式の範囲を解決できない';

                        continue;
                    }
                    self::markRange($marks, $i + 2, $end);
                }
            }

            for ($i = 0; $i < $count; $i++) {
                if (! isset($marks[$i])) {
                    continue;
                }
                $token = $tokens[$i];

                if ($token['id'] === T_CONSTANT_ENCAPSED_STRING) {
                    $references[] = new MiddlewareReference(
                        $file->relative,
                        $token['line'],
                        MiddlewareReferenceKind::AliasString,
                        self::unquote($token['text']),
                        null,
                    );

                    continue;
                }

                if ($token['id'] === T_DOUBLE_COLON && isset($tokens[$i + 1]) && $tokens[$i + 1]['id'] === T_CLASS) {
                    $resolved = $resolver->resolveClassReference($tokens, $i - 1);
                    if ($resolved === null) {
                        $unresolved[$file->relative] = sprintf(
                            'middleware 位置のクラス参照を完全修飾名へ解決できない (行 %d)',
                            $token['line'],
                        );

                        continue;
                    }
                    $references[] = new MiddlewareReference(
                        $file->relative,
                        $token['line'],
                        MiddlewareReferenceKind::ClassReference,
                        $tokens[$i - 1]['text'],
                        ltrim($resolved, '\\'),
                    );
                }
            }
        }

        return new ScanOutcome($references, $unresolved);
    }

    /**
     * Tier 1: 指定クラス (完全修飾名) のメソッド宣言と静的呼び出し。
     *
     * ★対象クラスの宣言が trait を取り込んでいたら**未解決**にする (v1 は trait-use graph を
     *   扱わないため、メソッドが混入しているかを静的に判定できない)。
     *
     * @param  list<ScannedFile>  $files
     * @return ScanOutcome<MethodReference>
     */
    public static function scanMethodReferences(array $files, string $fqcn, string $method): ScanOutcome
    {
        $targetFqcn = strtolower(ltrim($fqcn, '\\'));
        $targetMethod = strtolower($method);
        $references = [];
        /** @var array<string, string> $unresolved */
        $unresolved = [];

        foreach ($files as $file) {
            if (! $file->isPhp) {
                continue;
            }
            $tokens = self::tokenize($file, $unresolved);
            if ($tokens === null) {
                continue;
            }
            $resolver = PhpNameResolver::analyze($tokens);
            $count = count($tokens);

            foreach ($resolver->typeDeclarationsOf($fqcn) as $declaration) {
                if ($declaration['usesTraits']) {
                    $unresolved[$file->relative] =
                        '対象クラスが trait を取り込んでおり、メソッドの混入を静的に判定できない';
                }
            }

            for ($i = 0; $i < $count; $i++) {
                $token = $tokens[$i];

                if ($token['id'] === T_FUNCTION) {
                    $nameIndex = self::isReturnByReferenceMarker($tokens, $i + 1) ? $i + 2 : $i + 1;
                    if (isset($tokens[$nameIndex])
                        && $tokens[$nameIndex]['id'] === T_STRING
                        && strtolower($tokens[$nameIndex]['text']) === $targetMethod) {
                        $type = $resolver->typeAt($i);
                        // ★型の**本体の直下**にある宣言だけをメソッド宣言と見なす。
                        //   メソッドの中で宣言された名前付き関数や、型の中に置いた無名クラスの
                        //   メソッドは深さが違うので誤検出しない。
                        if ($type !== null
                            && strtolower($type['fqcn']) === $targetFqcn
                            && $resolver->depthAt($i) === $type['bodyDepth']) {
                            $references[] = new MethodReference(
                                $file->relative,
                                $token['line'],
                                MethodReferenceKind::Declaration,
                            );
                        }
                    }

                    continue;
                }

                if ($token['id'] === T_DOUBLE_COLON
                    && isset($tokens[$i + 1])
                    && $tokens[$i + 1]['id'] === T_STRING
                    && strtolower($tokens[$i + 1]['text']) === $targetMethod) {
                    $resolved = $resolver->resolveClassReference($tokens, $i - 1);
                    if ($resolved === null) {
                        $unresolved[$file->relative] = sprintf(
                            '`::%s` を伴うクラス参照を完全修飾名へ解決できない (行 %d)',
                            $method,
                            $token['line'],
                        );

                        continue;
                    }
                    if (strtolower(ltrim($resolved, '\\')) === $targetFqcn) {
                        $references[] = new MethodReference(
                            $file->relative,
                            $token['line'],
                            MethodReferenceKind::StaticCall,
                        );
                    }
                }
            }
        }

        return new ScanOutcome($references, $unresolved);
    }

    /**
     * 生テキストに撤去語と一致する run が含まれるか。
     *
     * ★利用側 gate が「middleware 位置の alias 文字列」のような**値**を絞り込むための入口で、
     *   判定は `scanText()` / `scanPhpLexemes()` と**同じ 1 本のトークン一致**を通る
     *   (同じ判定を 2 本持たない)。
     */
    public static function textMatches(string $text, RemovedTerm $term): bool
    {
        if ($term->mode === TermMatchMode::FqcnMethodReference) {
            return self::fqcnMethodOccurrences(
                new ScannedFile('memory', 'memory', $text, false, null),
                $term,
            ) !== [];
        }

        if ($term->mode === TermMatchMode::FqcnReference) {
            return self::fqcnOccurrences(
                new ScannedFile('memory', 'memory', $text, false, null),
                $term,
            ) !== [];
        }

        foreach (self::runs($text, self::TOKEN_CHARACTERS) as $run) {
            if (self::runMatches($run['text'], $term)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 生テキストを宣言した文字集合の最長連なり (run) へ分割する。
     *
     * @return list<array{text: string, offset: int}>
     */
    private static function runs(string $text, string $tokenCharacters): array
    {
        $runs = [];
        $length = strlen($text);
        $start = null;

        for ($i = 0; $i < $length; $i++) {
            if (str_contains($tokenCharacters, $text[$i])) {
                if ($start === null) {
                    $start = $i;
                }

                continue;
            }
            if ($start !== null) {
                $runs[] = ['text' => substr($text, $start, $i - $start), 'offset' => $start];
                $start = null;
            }
        }
        if ($start !== null) {
            $runs[] = ['text' => substr($text, $start), 'offset' => $start];
        }

        return $runs;
    }

    /** run が撤去語と一致するか (様式ごとの完全一致)。 */
    private static function runMatches(string $run, RemovedTerm $term): bool
    {
        return match ($term->mode) {
            TermMatchMode::ExactRun => $run === $term->term,
            TermMatchMode::RunSegment => in_array($term->term, explode('.', $run), true),
            // 完全修飾参照は専用のトークン文字集合で判定する
            // (fqcnMethodOccurrences / fqcnOccurrences が担当する)
            TermMatchMode::FqcnMethodReference, TermMatchMode::FqcnReference => false,
        };
    }

    /**
     * `クラス部::メソッド名` の完全一致 (ASCII 大小無視・先頭 `\` は落として正規化)。
     *
     * @return list<Occurrence>
     */
    private static function fqcnMethodOccurrences(ScannedFile $file, RemovedTerm $term): array
    {
        $parts = explode('::', $term->term, 2);
        if (count($parts) !== 2) {
            return [];
        }
        $targetClass = self::normalizeFqcn($parts[0]);
        $targetMethod = strtolower($parts[1]);

        /** @var array<int, string> $endingAt */
        $endingAt = [];
        /** @var array<int, string> $startingAt */
        $startingAt = [];
        foreach (self::runs($file->contents, self::FQCN_TOKEN_CHARACTERS) as $run) {
            $startingAt[$run['offset']] = $run['text'];
            $endingAt[$run['offset'] + strlen($run['text'])] = $run['text'];
        }

        $occurrences = [];
        $offset = 0;
        while (($position = strpos($file->contents, '::', $offset)) !== false) {
            $offset = $position + 2;
            if (! isset($endingAt[$position], $startingAt[$position + 2])) {
                continue;
            }
            $class = self::normalizeFqcn($endingAt[$position]);
            $method = strtolower($startingAt[$position + 2]);
            if ($class !== $targetClass || $method !== $targetMethod) {
                continue;
            }
            $occurrences[] = new Occurrence(
                $file->relative,
                self::lineAt($file->contents, $position),
                $endingAt[$position].'::'.$startingAt[$position + 2],
            );
        }

        return $occurrences;
    }

    /**
     * 完全修飾クラス名そのものの完全一致 (メソッド名を伴わない)。
     *
     * @return list<Occurrence>
     */
    private static function fqcnOccurrences(ScannedFile $file, RemovedTerm $term): array
    {
        $target = self::normalizeFqcn($term->term);

        $occurrences = [];
        foreach (self::runs($file->contents, self::FQCN_TOKEN_CHARACTERS) as $run) {
            if (self::normalizeFqcn($run['text']) !== $target) {
                continue;
            }
            $occurrences[] = new Occurrence(
                $file->relative,
                self::lineAt($file->contents, $run['offset']),
                $run['text'],
            );
        }

        return $occurrences;
    }

    /**
     * 完全修飾名の正規化 (先頭の逆斜線を落とし、連続する逆斜線を 1 つへ畳み、ASCII 小文字化)。
     *
     * ★連続の畳み込みは二重引用符の文字列リテラルのエスケープ表記を吸収するためで、
     *   **拾いすぎる方向**の正規化である (見逃す方向へは倒れない)。
     */
    private static function normalizeFqcn(string $name): string
    {
        $collapsed = preg_replace('/\\\\+/', '\\', $name);

        return strtolower(ltrim($collapsed ?? $name, '\\'));
    }

    /**
     * PHP を構文検証してから正規化トークン列を返す。`ParseError` は未解決。
     *
     * @param  array<string, string>  $unresolved
     * @return list<array{id: int|null, text: string, line: int}>|null
     */
    private static function tokenize(ScannedFile $file, array &$unresolved): ?array
    {
        try {
            token_get_all($file->contents, TOKEN_PARSE); // ★構文検証のみ (結果は捨てる)
        } catch (ParseError $error) {                    // ★ParseError だけを捕まえる
            $unresolved[$file->relative] = 'PHP のトークン化に失敗: '.$error->getMessage();

            return null;
        }

        return PhpTokenScan::normalize($file->contents);
    }

    /**
     * 撤去語と突き合わせる lexeme (対象外のトークンは null)。
     *
     * @param  array{id: int|null, text: string, line: int}  $token
     */
    private static function lexemeOf(array $token): ?string
    {
        return match ($token['id']) {
            T_VARIABLE => substr($token['text'], 1),
            T_CONSTANT_ENCAPSED_STRING => self::unquote($token['text']),
            T_STRING, T_ENCAPSED_AND_WHITESPACE,
            T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE => $token['text'],
            default => null,
        };
    }

    /** 文字列リテラルの引用符を落とす (エスケープの復元はしない)。 */
    private static function unquote(string $literal): string
    {
        $value = $literal;
        if ($value !== '' && (strtolower($value[0]) === 'b')) {
            $value = substr($value, 1);
        }
        if (strlen($value) >= 2) {
            $first = $value[0];
            $last = $value[strlen($value) - 1];
            if (($first === "'" && $last === "'") || ($first === '"' && $last === '"')) {
                $value = substr($value, 1, -1);
            }
        }

        return $value;
    }

    /** バイト位置の行番号 (1 起点)。 */
    private static function lineAt(string $contents, int $offset): int
    {
        return substr_count($contents, "\n", 0, $offset) + 1;
    }

    /**
     * 参照返しの印 (`function &foo()` の `&`) かどうか。
     *
     * ★PHP 8 は `&` を文脈で 3 通りにトークン化する
     *   (素の `&` / `T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG` /
     *   `T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG`)。素の文字トークンだけを見ると
     *   `public static function &foo()` の宣言を**見逃す** (fail-open)。3 通りとも認める。
     *
     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
     */
    private static function isReturnByReferenceMarker(array $tokens, int $index): bool
    {
        if (! isset($tokens[$index])) {
            return false;
        }
        if (self::isChar($tokens, $index, '&')) {
            return true;
        }

        return in_array(
            $tokens[$index]['id'],
            [T_AMPERSAND_NOT_FOLLOWED_BY_VAR_OR_VARARG, T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG],
            true,
        );
    }

    /**
     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
     */
    private static function isChar(array $tokens, int $index, string $char): bool
    {
        return isset($tokens[$index]) && $tokens[$index]['id'] === null && $tokens[$index]['text'] === $char;
    }

    /**
     * 開き括弧に対応する閉じ括弧の位置 (対応が取れなければ null)。
     *
     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
     */
    private static function matchingBracket(array $tokens, int $openIndex): ?int
    {
        $depth = 0;
        $count = count($tokens);
        for ($k = $openIndex; $k < $count; $k++) {
            $delta = self::bracketDelta($tokens[$k]);
            if ($delta > 0) {
                $depth++;

                continue;
            }
            if ($delta < 0) {
                $depth--;
                if ($depth === 0) {
                    return $k;
                }
            }
        }

        return null;
    }

    /**
     * 値の式が終わる位置 (配列リテラルなら閉じ括弧、単一式なら深さ 0 の区切りの手前)。
     *
     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
     */
    private static function valueEnd(array $tokens, int $from): ?int
    {
        if (! isset($tokens[$from])) {
            return null;
        }
        if (self::isChar($tokens, $from, '[')) {
            return self::matchingBracket($tokens, $from);
        }
        if ($tokens[$from]['id'] === T_ARRAY && self::isChar($tokens, $from + 1, '(')) {
            return self::matchingBracket($tokens, $from + 1);
        }

        $depth = 0;
        $count = count($tokens);
        for ($k = $from; $k < $count; $k++) {
            $delta = self::bracketDelta($tokens[$k]);
            if ($delta > 0) {
                $depth++;

                continue;
            }
            if ($delta < 0) {
                if ($depth === 0) {
                    return $k - 1;
                }
                $depth--;

                continue;
            }
            if ($depth === 0 && $tokens[$k]['id'] === null && in_array($tokens[$k]['text'], [',', ';'], true)) {
                return $k - 1;
            }
        }

        return $count - 1;
    }

    /**
     * 括弧の深さの増減 (文字列補間が開く `{` と属性の `#[` を開き括弧として数える)。
     *
     * @param  array{id: int|null, text: string, line: int}  $token
     */
    private static function bracketDelta(array $token): int
    {
        if ($token['id'] === null) {
            if (in_array($token['text'], ['(', '[', '{'], true)) {
                return 1;
            }
            if (in_array($token['text'], [')', ']', '}'], true)) {
                return -1;
            }

            return 0;
        }

        return in_array($token['id'], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES, T_ATTRIBUTE], true) ? 1 : 0;
    }

    /**
     * @param  array<int, bool>  $marks
     */
    private static function markRange(array &$marks, int $from, int $to): void
    {
        for ($i = $from; $i <= $to; $i++) {
            $marks[$i] = true;
        }
    }
}
