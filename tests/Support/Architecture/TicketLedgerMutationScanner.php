<?php

declare(strict_types=1);

namespace Tests\Support\Architecture;

use RuntimeException;
use Tests\Support\PhpReferenceScanner;
use Tests\Support\ReferenceKind;

/**
 * 追記専用チケット台帳の**変更サイト**を検出する走査器 (純関数)。
 *
 * ## 走査対象
 *
 * - 母集団は利用側 gate が渡す (`Tests\Support\TrackedPhpSourceFiles::all()` のうち
 *   `app/` 配下)。**同じ列挙を 2 本持たない**ため、ここでは列挙しない
 * - トークン化は {@see ArchTokenStream::significantTokens()}
 *   (`TOKEN_PARSE` + `ParseError` → 例外)。**解析できない入力は無言で空にせず落とす**
 * - **モデル参照は 2 つの判定の和** (拾いすぎ側 = fail-closed):
 *     (i) {@see PhpReferenceScanner::references()} が返す site のうち `name` が
 *         `App\Models\Billing\TicketLedgerEntry` に一致する (NameReference / Construction)、
 *         または StaticCall の receiver が同 FQCN に解決されるもの
 *     (ii) 正規化トークン列に短名 `TicketLedgerEntry` が `T_STRING` として現れるもの
 *   走査器は「型宣言 / `::class` / `instanceof` の位置を emit しない」と明言しているので、
 *   そこは短名一致 (ii) で埋める。和なので判定は**拾いすぎ側**へ倒れる
 * - **表名リテラル**は `T_CONSTANT_ENCAPSED_STRING` の**引用符を外した素の綴り**が
 *   `ticket_ledger_entries` に**完全一致**する出現の数。
 *   **エスケープ列 (`\'` / `\n`) や二重引用符の変数展開は評価しない** —
 *   表名は英小文字と下線だけなので、エスケープを含む書き方は対象外である
 *   (対象外にしたので、その書き方について検出力を主張しない)
 * - **変更語彙 / 削除語彙**は「識別子 + 直後が `(`」かつ「直前が `function` でない」位置の数。
 *   **区切りの宣言**: 判定は**トークン単位の完全一致**であり、部分文字列一致に頼らない
 *   (`presave(` / `unsave(` / `saveAll(` はいずれも別トークンなので数えない)
 * - **論理削除 scope** (`withTrashed(` / `onlyTrashed(`) は同じ規則で数え、加えて
 *   **受理する構文を 2 形に固定**する。それ以外は**未解決として利用側に返す** (fail-closed):
 *     (A) `Organization::withTrashed()` — 受け手が `App\Models\Organization` に解決される
 *     (B) `Organization::query()->withTrashed(` — トークン列そのものの一致
 *         (`T_STRING(Organization)` `::` `query` `(` `)` `->` `T_STRING(withTrashed)` `(`)
 *   変数受け手 (`$query->withTrashed()`) や長い連鎖は**受理しない**
 *   (同じファイルに `Organization::query()` が在ることを根拠に認定する形は fail-open)
 *
 * ## 保証しないもの (誇張しない)
 *
 * 1. **呼び出し側に表名・共通 helper 側に削除語彙という「分離」は検出できない**
 * 1b. **モデル参照の判定は「完全修飾名で解決できた」と「短名だけが一致した」の
 *    2 つを分けて返す** (`ledgerModelReference()`)。利用側は和で拾いすぎ側へ倒しているが、
 *    どちらで当たったかは結果に残るので、失敗メッセージで区別できる。
 *    **短名一致だけで当たったファイルを「台帳モデルを参照している」と断定してはならない**
 *    (同名の別クラスでも当たる。拾いすぎ = fail-closed であって、証明ではない)
 * 2. 定数・列挙型・変数を経由した表名 (`DB::table(self::TABLE)`) は追えない
 * 3. 可変メソッド名 (`$row->{$verb}()`) / repository / service 境界を越える削除は追えない
 * 4. 到達解析は行わない (到達不能なコードの語彙も数える)
 * 5. **真の並行実行での排他の実効性は見ない** (見るのはトークン順の構造まで)
 * 5b. **`lockOrderViolations()` が見るのはトークン順の構造だけ**である。具体的には
 *    (i) `transaction(` の**引数範囲**を closure の範囲として扱う
 *        (第 1 引数が `function` / `fn` で始まることは要求するが、
 *         後続の引数があればその範囲も内側として数える)、
 *    (ii) `lockForUpdate(` の**受け手が組織モデルか**は見ない、
 *    (iii) `delete(` の**対象が台帳か**は見ない。
 *    したがって「同一 closure 内で**組織行を**先にロックし**台帳を**変更する」ことは
 *    **証明しない** — 証明するのは「同一 transaction 引数の内側に変更操作が閉じており、
 *    ロック語彙が最初の変更操作より前に現れる」というトークン順の構造までである
 * 6. 受け手が完全に動的で、ファイル内にモデルの短名も表名リテラルも現れない形は検出しない
 *
 * したがって本走査器と利用側 gate が主張するのは
 * 「**対象構文の範囲で**、モデル参照または表名リテラルと変更語彙が同一ファイルに現れる
 * 変更サイトを deny-by-default で固定する」ことまでであり、**変更経路の全数性は主張しない**。
 */
final class TicketLedgerMutationScanner
{
    /** 台帳モデルの完全修飾名。 */
    public const string LEDGER_MODEL = 'App\Models\Billing\TicketLedgerEntry';

    /** 台帳モデルの短名 (拾いすぎ側の判定に使う)。 */
    public const string LEDGER_MODEL_SHORT = 'TicketLedgerEntry';

    /** 台帳の表名。 */
    public const string LEDGER_TABLE = 'ticket_ledger_entries';

    /** 組織モデルの完全修飾名 (論理削除 scope の受理形の受け手)。 */
    public const string ORGANIZATION_MODEL = 'App\Models\Organization';

    /** トランザクションの受け手として受理する facade。 */
    public const string DB_FACADE = 'Illuminate\Support\Facades\DB';

    /** 論理削除 scope の語彙。 @var list<string> */
    public const array TRASHED_SCOPE_VERBS = ['withTrashed', 'onlyTrashed'];

    /** インスタンス化しない (純関数の置き場)。 */
    private function __construct() {}

    /**
     * 正規化済みトークン列 (解析できない入力は例外)。
     *
     * @return list<array{id: int|null, text: string, line: int}>
     */
    public static function tokenize(string $source, string $context): array
    {
        return ArchTokenStream::significantTokens($source, $context);
    }

    /**
     * 表名リテラルの出現数 (引用符を外した値の完全一致)。
     *
     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
     */
    public static function tableLiteralCount(array $tokens): int
    {
        $count = 0;
        foreach ($tokens as $token) {
            if ($token['id'] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }
            if (self::literalValue($token['text']) === self::LEDGER_TABLE) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * 台帳モデルへの参照 (**完全修飾名で解決できたか / 短名だけが一致したかを分けて返す**)。
     *
     * ★2 つを 1 つの `bool` へ潰さない。潰すと「同名の別クラスに当たっただけ」と
     *   「本当に台帳モデルを参照している」が区別できなくなり、失敗メッセージが嘘になる。
     *   利用側は**和**で拾いすぎ側 (fail-closed) へ倒すが、どちらで当たったかは残る。
     *
     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
     * @return array{fqcn: bool, shortName: bool}
     */
    public static function ledgerModelReference(string $relativePath, string $source, array $tokens): array
    {
        $fqcn = false;
        foreach (PhpReferenceScanner::references($relativePath, $source)->sites as $site) {
            if ($site->kind === ReferenceKind::StaticCall) {
                if ($site->receiver->is(self::LEDGER_MODEL)) {
                    $fqcn = true;

                    break;
                }

                continue;
            }
            if ($site->name === self::LEDGER_MODEL) {
                $fqcn = true;

                break;
            }
        }

        $shortName = false;
        foreach ($tokens as $token) {
            if ($token['id'] === T_STRING && $token['text'] === self::LEDGER_MODEL_SHORT) {
                $shortName = true;

                break;
            }
        }

        return ['fqcn' => $fqcn, 'shortName' => $shortName];
    }

    /**
     * 語彙の呼び出し位置の数 (識別子 + 直後が `(` かつ直前が `function` でない)。
     *
     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
     * @param  list<string>  $verbs
     */
    public static function verbCount(array $tokens, array $verbs): int
    {
        return count(self::verbPositions($tokens, $verbs));
    }

    /**
     * 語彙の呼び出し位置 (添字のリスト)。
     *
     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
     * @param  list<string>  $verbs
     * @return list<int>
     */
    public static function verbPositions(array $tokens, array $verbs): array
    {
        $positions = [];
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            if ($tokens[$i]['id'] !== T_STRING || ! in_array($tokens[$i]['text'], $verbs, true)) {
                continue;
            }
            if (! ArchTokenStream::isPunctuation($tokens, $i + 1, '(')) {
                continue;
            }
            if (($tokens[$i - 1]['id'] ?? null) === T_FUNCTION) {
                continue; // メソッド定義であって呼び出しではない
            }
            $positions[] = $i;
        }

        return $positions;
    }

    /**
     * 論理削除 scope の出現数と、**受理形に当てはまらなかった出現** (fail-closed の材料)。
     *
     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
     * @return array{count: int, unresolved: list<string>}
     */
    public static function trashedScopes(string $relativePath, string $source, array $tokens): array
    {
        $imports = PhpReferenceScanner::references($relativePath, $source)->imports;
        $positions = self::verbPositions($tokens, self::TRASHED_SCOPE_VERBS);

        $unresolved = [];
        foreach ($positions as $i) {
            if (self::isStaticOrganizationScope($tokens, $i, $imports)
                || self::isOrganizationQueryChain($tokens, $i, $imports)) {
                continue;
            }
            $unresolved[] = $relativePath.':'.$tokens[$i]['line'].' ('.$tokens[$i]['text'].')';
        }

        return ['count' => count($positions), 'unresolved' => $unresolved];
    }

    /**
     * 畳み込みの「ロック → 変更」構造の違反 (TLM-5 の 5 条)。空配列なら適合。
     *
     * ★見る範囲は **`DB::transaction(` の引数範囲**であって closure の本体そのものではない
     *   (第 1 引数が closure であることは要求するが、後続の引数があればその範囲も内側に数える)。
     *   受け手・削除対象は見ない (保証しないもの 5b)。
     *
     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
     * @param  list<string>  $mutationVerbs
     * @param  list<string>  $deleteVerbs
     * @return list<string>
     */
    public static function lockOrderViolations(
        array $tokens,
        string $relativePath,
        string $source,
        string $method,
        string $appendCall,
        array $mutationVerbs,
        array $deleteVerbs,
    ): array {
        $imports = PhpReferenceScanner::references($relativePath, $source)->imports;

        $body = self::methodBodyRange($tokens, $method);
        if ($body === null) {
            return ["メソッド {$method}() の本体が見つからない (走査が壊れている可能性がある)"];
        }
        [$bodyStart, $bodyEnd] = $body;

        // 条件 1: 本体の内側に DB ファサードの transaction( がちょうど 1 つ
        $transactions = [];
        foreach (self::verbPositions($tokens, ['transaction']) as $i) {
            if ($i <= $bodyStart || $i >= $bodyEnd) {
                continue;
            }
            if (! self::receiverIs($tokens, $i, self::DB_FACADE, $imports)) {
                continue;
            }
            $transactions[] = $i;
        }
        if (count($transactions) !== 1) {
            return [sprintf(
                'メソッド %s() の中に DB ファサードの transaction( が %d 個ある (ちょうど 1 つであること)',
                $method,
                count($transactions),
            )];
        }

        $closure = self::parenRange($tokens, $transactions[0] + 1);
        if ($closure === null) {
            return ["transaction( の引数範囲を閉じられない ({$method}())"];
        }
        [$closureStart, $closureEnd] = $closure;

        // 引数範囲を closure の範囲として扱うので、**第 1 引数が closure であること**は要求する
        // (要求しないと `DB::transaction($this->callback())` のような形も同じ扱いになる)。
        // `static` は単独では closure を意味しないので、直後が `function` / `fn` であることまで見る。
        if (! self::startsClosure($tokens, $closureStart + 1)) {
            return ["DB::transaction( の第 1 引数が closure ではない ({$method}())"];
        }

        $violations = [];

        // 条件 2: transaction 引数範囲の内側にロックがある
        $locks = array_values(array_filter(
            self::verbPositions($tokens, ['lockForUpdate']),
            static fn (int $i): bool => $i > $closureStart && $i < $closureEnd,
        ));
        if ($locks === []) {
            $violations[] = 'DB::transaction( の引数範囲の内側に lockForUpdate( が無い';
        }

        // 条件 3: transaction 引数範囲の内側に変更操作が 2 種類以上ある (空振り検出を兼ねる)
        $deletes = array_values(array_filter(
            self::verbPositions($tokens, $deleteVerbs),
            static fn (int $i): bool => $i > $closureStart && $i < $closureEnd,
        ));
        $appends = array_values(array_filter(
            self::verbPositions($tokens, [$appendCall]),
            static fn (int $i): bool => $i > $closureStart && $i < $closureEnd,
        ));
        if (count($deletes) < 2) {
            $violations[] = 'DB::transaction( の引数範囲の内側の削除語彙が 2 つ未満である (空振りの疑い)';
        }
        if (count($appends) !== 1) {
            $violations[] = sprintf(
                'DB::transaction( の引数範囲の内側の %s( が %d 個ある (ちょうど 1 つであること)',
                $appendCall,
                count($appends),
            );
        }

        // 条件 4: ロックが transaction 引数範囲内の最初の変更操作より前にある
        $operationVerbs = array_values(array_unique([...$mutationVerbs, $appendCall]));
        $operations = array_values(array_filter(
            self::verbPositions($tokens, $operationVerbs),
            static fn (int $i): bool => $i > $closureStart && $i < $closureEnd,
        ));
        if ($operations !== [] && $locks !== [] && $locks[0] > $operations[0]) {
            $violations[] = 'lockForUpdate( が DB::transaction( の引数範囲内の最初の変更操作より後ろにある (順序が契約である)';
        }

        // 条件 5: 本体のうち transaction 引数範囲の外側に変更操作が 1 つも無い
        $outside = array_values(array_filter(
            self::verbPositions($tokens, $operationVerbs),
            static fn (int $i): bool => $i > $bodyStart && $i < $bodyEnd
                && ($i < $closureStart || $i > $closureEnd),
        ));
        if ($outside !== []) {
            $violations[] = sprintf(
                'メソッド %s() の DB::transaction( の引数範囲の外側に変更操作が %d 個ある',
                $method,
                count($outside),
            );
        }

        // 条件 5 (後段): ファイル全体で追記の呼び出しは 1 件だけ
        $appendCallsInFile = self::verbCount($tokens, [$appendCall]);
        if ($appendCallsInFile !== 1) {
            $violations[] = sprintf(
                'ファイル全体の %s( の呼び出しが %d 件ある (1 件であること)',
                $appendCall,
                $appendCallsInFile,
            );
        }

        return $violations;
    }

    /**
     * メソッド本体の `{` と `}` の添字 (見つからなければ null)。
     *
     * ★文字列補間の `{$x}` / `${x}` の開き側も深さに数える (閉じ `}` は単一文字トークンで
     *   現れるため、数え漏らすと本体の範囲が途中で閉じる)。
     *
     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
     * @return array{int, int}|null
     */
    public static function methodBodyRange(array $tokens, string $method): ?array
    {
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            if ($tokens[$i]['id'] !== T_FUNCTION) {
                continue;
            }
            if (($tokens[$i + 1]['id'] ?? null) !== T_STRING || $tokens[$i + 1]['text'] !== $method) {
                continue;
            }
            // 引数リストを飛ばし、戻り値型を読み飛ばして最初の `{` を探す
            $paren = self::parenRange($tokens, $i + 2);
            if ($paren === null) {
                return null;
            }
            for ($j = $paren[1] + 1; $j < $count; $j++) {
                if (ArchTokenStream::isPunctuation($tokens, $j, ';')) {
                    return null; // 本体を持たない宣言 (abstract / interface)
                }
                if (ArchTokenStream::isPunctuation($tokens, $j, '{')) {
                    $end = self::braceRange($tokens, $j);

                    return $end === null ? null : [$j, $end];
                }
            }

            return null;
        }

        return null;
    }

    /**
     * 指定位置から closure が始まるか (`function` / `fn` / `static function` / `static fn`)。
     *
     * ★`static` 単独では closure を意味しない (`DB::transaction(static::$callback)` 等) ので、
     *   `static` の**直後**が `function` / `fn` であることまで確かめる。
     *
     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
     */
    private static function startsClosure(array $tokens, int $index): bool
    {
        $id = $tokens[$index]['id'] ?? null;
        if ($id === T_FUNCTION || $id === T_FN) {
            return true;
        }
        if ($id !== T_STATIC) {
            return false;
        }
        $next = $tokens[$index + 1]['id'] ?? null;

        return $next === T_FUNCTION || $next === T_FN;
    }

    /**
     * `(` の添字から対応する `)` までの範囲。
     *
     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
     * @return array{int, int}|null
     */
    private static function parenRange(array $tokens, int $open): ?array
    {
        if (! ArchTokenStream::isPunctuation($tokens, $open, '(')) {
            return null;
        }
        $depth = 0;
        $count = count($tokens);
        for ($i = $open; $i < $count; $i++) {
            if (ArchTokenStream::isPunctuation($tokens, $i, '(')) {
                $depth++;

                continue;
            }
            if (ArchTokenStream::isPunctuation($tokens, $i, ')')) {
                $depth--;
                if ($depth === 0) {
                    return [$open, $i];
                }
            }
        }

        return null;
    }

    /**
     * `{` の添字から対応する `}` の添字 (文字列補間の開きも数える)。
     *
     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
     */
    private static function braceRange(array $tokens, int $open): ?int
    {
        $depth = 0;
        $count = count($tokens);
        for ($i = $open; $i < $count; $i++) {
            $id = $tokens[$i]['id'];
            if ($id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) {
                $depth++;

                continue;
            }
            if (ArchTokenStream::isPunctuation($tokens, $i, '{')) {
                $depth++;

                continue;
            }
            if (ArchTokenStream::isPunctuation($tokens, $i, '}')) {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }

    /**
     * 静的呼び出しの受け手が指定の完全修飾名に解決されるか。
     *
     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
     * @param  array<string, string>  $imports
     */
    private static function receiverIs(array $tokens, int $index, string $fqcn, array $imports): bool
    {
        if (($tokens[$index - 1]['id'] ?? null) !== T_DOUBLE_COLON) {
            return false;
        }

        return self::resolvesTo($tokens[$index - 2] ?? null, $fqcn, $imports);
    }

    /**
     * 受理形 (A) `Organization::withTrashed()`。
     *
     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
     * @param  array<string, string>  $imports
     */
    private static function isStaticOrganizationScope(array $tokens, int $index, array $imports): bool
    {
        return self::receiverIs($tokens, $index, self::ORGANIZATION_MODEL, $imports);
    }

    /**
     * 受理形 (B) `Organization::query()->withTrashed(` のトークン列そのものの一致。
     *
     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
     * @param  array<string, string>  $imports
     */
    private static function isOrganizationQueryChain(array $tokens, int $index, array $imports): bool
    {
        if (($tokens[$index - 1]['id'] ?? null) !== T_OBJECT_OPERATOR) {
            return false;
        }
        if (! ArchTokenStream::isPunctuation($tokens, $index - 2, ')')
            || ! ArchTokenStream::isPunctuation($tokens, $index - 3, '(')) {
            return false;
        }
        if (($tokens[$index - 4]['id'] ?? null) !== T_STRING || $tokens[$index - 4]['text'] !== 'query') {
            return false;
        }
        if (($tokens[$index - 5]['id'] ?? null) !== T_DOUBLE_COLON) {
            return false;
        }

        return self::resolvesTo($tokens[$index - 6] ?? null, self::ORGANIZATION_MODEL, $imports);
    }

    /**
     * 名前トークンが完全修飾名へ解決されるか (import 表 / 完全修飾を解く)。
     *
     * @param  array{id: int|null, text: string, line: int}|null  $token
     * @param  array<string, string>  $imports
     */
    private static function resolvesTo(?array $token, string $fqcn, array $imports): bool
    {
        if ($token === null) {
            return false;
        }
        if ($token['id'] === T_NAME_FULLY_QUALIFIED) {
            return ltrim($token['text'], '\\') === $fqcn;
        }
        if ($token['id'] !== T_STRING && $token['id'] !== T_NAME_QUALIFIED) {
            return false;
        }

        return ($imports[mb_strtolower($token['text'])] ?? null) === $fqcn;
    }

    /**
     * 引用符を外した**素の綴り**。
     *
     * ★エスケープ列は評価しない (`'ticket\_ledger\_entries'` のような書き方は一致しない)。
     *   表名は英小文字と下線だけなので実害は無いが、**「リテラルの値」ではなく「綴り」**である。
     */
    private static function literalValue(string $text): string
    {
        $first = $text[0] ?? '';
        if ($first !== "'" && $first !== '"') {
            throw new RuntimeException('文字列リテラルの引用符が解釈できない: '.$text);
        }

        return substr($text, 1, -1);
    }
}
