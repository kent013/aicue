<?php

declare(strict_types=1);

namespace Tests\Support\EnterpriseSso;

use RuntimeException;
use Tests\Support\PhpReferenceScanner;
use Tests\Support\PhpTokenScan;
use Tests\Support\ReferenceKind;
use Tests\Support\ReferenceSite;

/**
 * 企業 SSO の 5 本の gate (G1〜G5) が共有する走査器。
 *
 * ## 走査対象
 *
 * 呼び出し側が渡した**走査根の配下の `*.php` 全数**である
 * (根そのものが存在しなければ fail-fast する = 改名・移動で黙って空振りしない)。
 *
 * ## 名前の解決 (AGENTS.md 走査器共通規約 (a))
 *
 * クラス参照は `Tests\Support\PhpReferenceScanner` が解いた**完全修飾名**で突き合わせる
 * (短名一致は別名つき取り込み 1 つで黙る)。本走査器は解決の実装を自分で持たない。
 *
 * ## 解決できない形の扱い ((b) fail-closed)
 *
 * 走査根が**自分たちが書く小さな領域**であることを使い、次の 2 つを**違反として返す**
 * (未解決を無言で候補から外さない):
 *
 *  1. **動的な呼び出しの形** — `$obj->$name()` / `new $cls` / `$cls::method()` /
 *     `call_user_func` 系。走査根の中でこれらを使う正当な理由が無いので、禁じても実装が困らない
 *  2. **受け手の型が解決できない保護対象語彙の呼び出し** — 呼び出し側が
 *     {@see self::unresolvedProtectedCalls()} へ語彙を渡す。動的構文でなくても
 *     解決範囲の外に落ちうるため、そこも失敗させる
 *
 * ## 語彙一致 ((e))
 *
 * 語彙の一致は**トークンの完全一致**で判定する (素の部分文字列一致に頼らない)。
 * 区切りは PHP の字句そのものであり、`hasSecretIn` のような**接頭辞・接尾辞つきの識別子**は
 * 別のトークンなので一致しない。
 *
 * ## 保証しないもの (誇張しない)
 *
 * - 文字列リテラルの中身に書かれたクラス名 (`app('App\\Services\\…')`) は見ない
 * - **受け手の型は「構築子の promoted プロパティの型」からしか解決しない**。
 *   局所変数・factory の戻り値・プロパティ以外の代入は解決しない
 *   (だからこそ、それらは 2 の**違反**として返る = 見逃さない)
 * - `app/` の外 (vendor が呼ぶ経路) は母集団に入らない
 */
final class EnterpriseSsoSourceScanner
{
    /** 動的呼び出しとみなす vendor / 標準の関数名 (可変 callable)。 */
    private const array DYNAMIC_CALLABLE_FUNCTIONS = [
        'call_user_func', 'call_user_func_array', 'forward_static_call', 'forward_static_call_array',
    ];

    /** インスタンス化しない (純関数の置き場)。 */
    private function __construct() {}

    /**
     * 走査根の配下の PHP ファイル (相対パス => ソース)。
     *
     * @param  list<string>  $roots  リポジトリ相対の走査根
     * @return array<string, string>
     */
    public static function sources(array $roots): array
    {
        $base = dirname(__DIR__, 3);

        /** @var array<string, string> $sources */
        $sources = [];
        foreach ($roots as $root) {
            $absolute = $base.'/'.$root;

            // ★存在しない根は fail-fast (改名・移動で黙って空振りしない = (b) の 3 つ目)。
            if (! is_dir($absolute) && ! is_file($absolute)) {
                throw new RuntimeException("走査根が存在しません: {$root}");
            }

            if (is_file($absolute)) {
                $sources[$root] = (string) file_get_contents($absolute);

                continue;
            }

            foreach (PhpReferenceScanner::phpFiles($absolute, $root) as $relative => $source) {
                $sources[$relative] = $source;
            }
        }

        return $sources;
    }

    /**
     * 指定した完全修飾名への参照 (取り込みも site も両方見る)。
     *
     * @param  array<string, string>  $sources
     * @param  list<string>  $forbidden  完全修飾名
     * @return list<string> 人が読める記述子
     */
    public static function forbiddenClassReferences(array $sources, array $forbidden): array
    {
        $lowered = array_map(strtolower(...), $forbidden);

        $violations = [];
        foreach ($sources as $path => $source) {
            $result = PhpReferenceScanner::references($path, $source);

            foreach ($result->imports as $fqcn) {
                if (in_array(strtolower($fqcn), $lowered, true)) {
                    $violations[] = "{$path}: {$fqcn} を取り込んでいる";
                }
            }

            foreach ($result->sites as $site) {
                if (self::siteReferences($site, $lowered)) {
                    $violations[] = "{$path}:{$site->line}: {$site->name} を参照している";
                }
            }
        }

        return array_values(array_unique($violations));
    }

    /**
     * 動的な呼び出しの形 ((b) fail-closed の 1 つ目)。
     *
     * @param  array<string, string>  $sources
     * @return list<string>
     */
    public static function dynamicCallForms(array $sources): array
    {
        $violations = [];

        foreach ($sources as $path => $source) {
            $tokens = PhpTokenScan::normalize($source);
            $count = count($tokens);

            for ($i = 0; $i < $count; $i++) {
                $text = $tokens[$i]['text'];
                $next = $tokens[$i + 1]['text'] ?? '';

                // `$obj->$name()` / `$obj::$name()` — 矢印 / 二重コロンの直後が変数で、**呼び出している**もの。
                // ★`Foo::$property` (静的プロパティへの参照) は動的な**呼び出し**ではないので拾わない
                //   (拾うと `JWT::$leeway = …` のような正当な代入まで違反になる)。
                if (($text === '->' || $text === '?->' || $text === '::')
                    && str_starts_with($next, '$')
                    && ($tokens[$i + 2]['text'] ?? '') === '('
                ) {
                    $violations[] = "{$path}:{$tokens[$i]['line']}: 動的なメンバー名";

                    continue;
                }

                // `new $cls`
                if ($tokens[$i]['id'] === T_NEW && str_starts_with($next, '$')) {
                    $violations[] = "{$path}:{$tokens[$i]['line']}: 可変クラス名の生成";

                    continue;
                }

                // `call_user_func(...)` 系
                if ($tokens[$i]['id'] === T_STRING
                    && in_array(strtolower($text), self::DYNAMIC_CALLABLE_FUNCTIONS, true)
                    && $next === '('
                    && ! in_array($tokens[$i - 1]['text'] ?? '', ['->', '?->', '::'], true)
                ) {
                    $violations[] = "{$path}:{$tokens[$i]['line']}: 可変 callable ({$text})";
                }
            }
        }

        return array_values(array_unique($violations));
    }

    /**
     * **受け手の型が解決できない**保護対象語彙の呼び出し ((b) fail-closed の 2 つ目)。
     *
     * 受け手の型は「構築子の promoted プロパティの型」からだけ解決する。
     * それ以外 (局所変数・factory の戻り値) は解決できないので**違反として返す**。
     *
     * @param  array<string, string>  $sources
     * @param  list<string>  $vocabulary  保護対象のメソッド名 (小文字)
     * @return list<string>
     */
    public static function unresolvedProtectedCalls(array $sources, array $vocabulary): array
    {
        $violations = [];

        foreach ($sources as $path => $source) {
            $properties = self::declaredPropertyTypes($source);
            $variables = self::declaredParameterTypes($source);
            $tokens = PhpTokenScan::normalize($source);
            $count = count($tokens);

            for ($i = 0; $i < $count; $i++) {
                if ($tokens[$i]['id'] !== T_STRING || ($tokens[$i + 1]['text'] ?? '') !== '(') {
                    continue;
                }
                if (! in_array(strtolower($tokens[$i]['text']), $vocabulary, true)) {
                    continue;
                }

                $arrow = $tokens[$i - 1]['text'] ?? '';
                if ($arrow !== '->' && $arrow !== '?->') {
                    // 静的呼び出し / 素の関数呼び出しは受け手の型の話ではない
                    continue;
                }

                // 解決済みとみなすのは 2 形だけである:
                //   (1) `$this-><宣言された型のプロパティ>->method()`
                //   (2) `$<宣言された型の引数>->method()`
                // どちらも**型が静的に書かれている**受け手であり、字句だけで型が確定する。
                $property = $tokens[$i - 2]['text'] ?? '';
                $receiverArrow = $tokens[$i - 3]['text'] ?? '';
                $receiver = $tokens[$i - 4]['text'] ?? '';

                $viaProperty = $receiver === '$this'
                    && ($receiverArrow === '->' || $receiverArrow === '?->')
                    && array_key_exists($property, $properties);

                $viaParameter = str_starts_with($property, '$')
                    && array_key_exists(substr($property, 1), $variables);

                if (! $viaProperty && ! $viaParameter) {
                    $violations[] = "{$path}:{$tokens[$i]['line']}: 受け手の型が解決できない {$tokens[$i]['text']}()";
                }
            }
        }

        return array_values(array_unique($violations));
    }

    /**
     * 語彙のトークン完全一致 ((e))。
     *
     * @param  array<string, string>  $sources
     * @param  list<string>  $vocabulary
     * @return list<string>
     */
    public static function forbiddenTokens(array $sources, array $vocabulary): array
    {
        $lowered = array_map(strtolower(...), $vocabulary);

        $violations = [];
        foreach ($sources as $path => $source) {
            foreach (PhpTokenScan::normalize($source) as $token) {
                if ($token['id'] !== T_STRING) {
                    continue;
                }
                if (in_array(strtolower($token['text']), $lowered, true)) {
                    $violations[] = "{$path}:{$token['line']}: {$token['text']}";
                }
            }
        }

        return array_values(array_unique($violations));
    }

    /**
     * 指定のメソッドを**呼んでいる**ファイル (呼び出し元の exact-fit の pin 用)。
     *
     * ★**宣言 (`function foo()`) は呼び出しではない**ので数えない
     *   (数えると定義しているファイル自身が必ず呼び出し元として現れ、pin が意味を失う)。
     *
     * @param  array<string, string>  $sources
     * @return list<string>
     */
    public static function filesCalling(array $sources, string $method): array
    {
        $lowered = strtolower($method);

        $files = [];
        foreach ($sources as $path => $source) {
            $tokens = PhpTokenScan::normalize($source);

            foreach ($tokens as $index => $token) {
                if ($token['id'] !== T_STRING || strtolower($token['text']) !== $lowered) {
                    continue;
                }
                if (($tokens[$index + 1]['text'] ?? '') !== '(') {
                    continue;
                }
                // 宣言はスキップする
                if (($tokens[$index - 1]['id'] ?? null) === T_FUNCTION) {
                    continue;
                }

                $files[] = $path;

                break;
            }
        }

        return array_values(array_unique($files));
    }

    /**
     * 指定のメソッドの**呼び出しごと**に、名前付き引数が**特定のリテラルで**渡されているかを見る。
     *
     * ★ファイル単位の部分文字列一致にしない。「同じファイルに安全な呼び出しが 1 つあれば
     *   緑になる」形だと、**同じファイルへ既定値の呼び出しを 1 行足すだけで見逃す**。
     * ★**値まで見る**。名前付き引数の存在だけを見ると `followRedirects: true` が素通りする
     *   (gate の名前が主張していることと、実際に保証していることが食い違う)。
     * ★**静的に確定できない値は違反として返す** ((b) fail-closed)。
     *   `followRedirects: $configured` / `! false` / `false || true` はどれも通さない —
     *   通してよいのは**リテラルちょうど 1 トークン**の場合だけである。
     * ★**外側の引数リストの深さ 1 にある名前付き引数だけ**を見る。
     *   深さを見ないと、入れ子の別の呼び出し・配列・クロージャの中にある同名の引数を
     *   外側のものと取り違える (`fetch($this->build(followRedirects: false), …)` が通ってしまう)。
     * ★深さは**整数のカウンタではなく区切りの stack** で持つ。PHP の開き区切りは
     *   素の `(` `[` `{` だけではないためである (実測した token の形):
     *
     *   | 構文 | 開き token | 閉じ |
     *   |---|---|---|
     *   | attribute `#[Probe]` | `T_ATTRIBUTE` (text は `#[`) | `]` |
     *   | 文字列内挿 `"{$x}"` | `T_CURLY_OPEN` (text は `{`) | `}` |
     *   | 文字列内挿 `"${x}"` | `T_DOLLAR_OPEN_CURLY_BRACES` (text は `${`) | `}` |
     *
     *   text だけで判定すると `#[` と `${` が**開きとして数えられないのに閉じだけ数えられ**、
     *   その場から深さが 1 つずれる (以降の入れ子が深さ 1 に見えて取り違える)。
     *   `T_CURLY_OPEN` は text が `{` なので偶然合っていたが、**偶然に依存しない**。
     * ★**対応の取れない区切り**が出たら「読み切れない」として落とす ((b) fail-closed)。
     *   単なる整数カウンタでは `([)]` のような壊れた対応を検出できない。
     *
     * ## 保証しないもの (誇張しない)
     *
     * - **first-class callable** (`fetch(...)`) は引数が無い形として**違反側**へ落ちる。
     *   呼び出しの引数を静的に確定できないためであり、G2 の狭い走査根では fail-closed が正しい。
     * - 可変メソッド名 (`$obj->{$name}(...)`) の呼び出しは走査対象に入らない
     *   (メソッド名が T_STRING で現れない)。
     *
     * @param  array<string, string>  $sources
     * @param  string  $literal  許すリテラル (例: `false`)
     * @return list<string> 引数が無い / 値が違う / 値を確定できない呼び出しの記述子
     */
    public static function callsWithoutNamedLiteral(
        array $sources,
        string $method,
        string $argument,
        string $literal,
    ): array {
        $loweredMethod = strtolower($method);

        $violations = [];
        foreach ($sources as $path => $source) {
            $tokens = PhpTokenScan::normalize($source);
            $count = count($tokens);

            for ($i = 0; $i < $count; $i++) {
                if ($tokens[$i]['id'] !== T_STRING || strtolower($tokens[$i]['text']) !== $loweredMethod) {
                    continue;
                }
                if (($tokens[$i + 1]['text'] ?? '') !== '(') {
                    continue;
                }
                // 宣言は呼び出しではない
                if (($tokens[$i - 1]['id'] ?? null) === T_FUNCTION) {
                    continue;
                }

                $end = self::matchingParenthesis($tokens, $i + 1);
                if ($end === null) {
                    // 括弧の対応が取れない = 解決できない形なので**落とす** ((b) fail-closed)
                    $violations[] = "{$path}:{$tokens[$i]['line']}: {$method}() の引数を読み切れない";

                    continue;
                }

                // ★**外側の引数リストの深さ 1 にあるものだけ**を対象にする。
                //   深さを見ないと、入れ子の別の呼び出しの同名引数を外側のものと取り違える
                //   (`fetch($this->build(followRedirects: false), $deadline)` が緑になってしまう)。
                // ★開きは text だけで決まらない (`#[` / `${`)。stack で持ち、
                //   対応が取れなければ「読み切れない」として落とす。
                $valuePosition = null;
                $unresolved = false;
                /** @var list<string> $expectedClosers 外側の `(` に対応する `)` を底に積む */
                $expectedClosers = [')'];

                for ($k = $i + 2; $k < $end; $k++) {
                    $text = $tokens[$k]['text'];
                    $closer = self::closerForOpener($tokens[$k]);

                    if ($closer !== null) {
                        $expectedClosers[] = $closer;

                        continue;
                    }

                    if ($text === ')' || $text === ']' || $text === '}') {
                        if (array_pop($expectedClosers) !== $text) {
                            $unresolved = true;

                            break;
                        }

                        continue;
                    }

                    // 深さ 1 = 外側の引数リストそのもの (底の 1 件だけが残っている状態)
                    if (count($expectedClosers) !== 1) {
                        continue;
                    }

                    if ($tokens[$k]['id'] === T_STRING
                        && $text === $argument
                        && ($tokens[$k + 1]['text'] ?? '') === ':'
                        // ★`?:` (三項) や `::` と取り違えない
                        && ($tokens[$k + 2]['text'] ?? '') !== ':'
                    ) {
                        $valuePosition = $k + 2;

                        break;
                    }
                }

                // ★引数を最後まで読んだのに開きが閉じ切っていない形も「読み切れない」である
                //   (`fetch($a[0), $d)` のように閉じの種類が食い違う形をここで捕まえる)。
                if ($valuePosition === null && $expectedClosers !== [')']) {
                    $unresolved = true;
                }

                if ($unresolved) {
                    $violations[] = "{$path}:{$tokens[$i]['line']}: {$method}() の引数を読み切れない";

                    continue;
                }

                if ($valuePosition === null) {
                    $violations[] = "{$path}:{$tokens[$i]['line']}: {$method}() に {$argument}: が無い";

                    continue;
                }

                // ★値は**リテラルちょうど 1 トークン**であること。
                //   次のトークンが `,` か `)` でなければ式であり、静的に確定できない。
                $value = $tokens[$valuePosition]['text'] ?? '';
                $after = $tokens[$valuePosition + 1]['text'] ?? '';

                if (strtolower($value) !== strtolower($literal) || ($after !== ',' && $after !== ')')) {
                    $violations[] = "{$path}:{$tokens[$i]['line']}: {$method}() の {$argument}: が {$literal} でない";
                }
            }
        }

        return array_values(array_unique($violations));
    }

    /**
     * トークンが**開き区切り**なら、対応する閉じ文字を返す (開きでなければ null)。
     *
     * ★`#[` (`T_ATTRIBUTE`) と `${` (`T_DOLLAR_OPEN_CURLY_BRACES`) は
     *   **text が素の `[` / `{` ではない**ので、text だけを見ると開きとして数えられない。
     *   閉じ (`]` / `}`) だけが数えられて深さが 1 つずれる。
     * ★`T_CURLY_OPEN` (`"{$x}"` の `{`) は text が `{` なので下の text 判定でも拾えるが、
     *   **偶然に依存しない**ように id でも明示する。
     *
     * @param  array{id: int|null, text: string, line: int}  $token
     */
    private static function closerForOpener(array $token): ?string
    {
        if ($token['id'] === T_ATTRIBUTE) {
            return ']';
        }
        if ($token['id'] === T_DOLLAR_OPEN_CURLY_BRACES || $token['id'] === T_CURLY_OPEN) {
            return '}';
        }

        return match ($token['text']) {
            '(' => ')',
            '[' => ']',
            '{' => '}',
            default => null,
        };
    }

    /**
     * `(` の位置から対応する `)` の位置を返す (見つからなければ null)。
     *
     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
     */
    private static function matchingParenthesis(array $tokens, int $open): ?int
    {
        $depth = 0;
        $count = count($tokens);

        for ($i = $open; $i < $count; $i++) {
            if ($tokens[$i]['text'] === '(') {
                $depth++;

                continue;
            }
            if ($tokens[$i]['text'] === ')') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }

    /**
     * 型を宣言されたプロパティ (プロパティ名 => 型の短名)。
     *
     * 構築子の promoted プロパティと、通常のプロパティ宣言の両方を拾う。
     *
     * @return array<string, string>
     */
    private static function declaredPropertyTypes(string $source): array
    {
        $tokens = PhpTokenScan::normalize($source);
        $count = count($tokens);

        /** @var array<string, string> $properties */
        $properties = [];

        for ($i = 0; $i < $count; $i++) {
            // 変数の直前に型が並ぶ形 (`private readonly Foo $bar` / `private Foo $bar`)
            if (! str_starts_with($tokens[$i]['text'], '$')) {
                continue;
            }

            $type = null;
            $sawModifier = false;
            for ($k = $i - 1; $k >= 0 && $k >= $i - 5; $k--) {
                $text = $tokens[$k]['text'];
                $id = $tokens[$k]['id'];

                if ($id === T_STRING && $type === null) {
                    $type = $text;

                    continue;
                }
                if (in_array($id, [T_PRIVATE, T_PROTECTED, T_PUBLIC, T_READONLY], true)) {
                    $sawModifier = true;

                    break;
                }
                if ($text === '?' || $id === T_WHITESPACE) {
                    continue;
                }
                break;
            }

            if ($sawModifier && $type !== null) {
                $properties[substr($tokens[$i]['text'], 1)] = $type;
            }
        }

        // `$this->pinned` のように**プロパティ名で引ける**表にする
        return $properties;
    }

    /**
     * 型を宣言された関数・メソッドの引数 (変数名 => 型の短名)。
     *
     * ★**ファイル全体で 1 つの表に畳む**。同名の引数が別のメソッドで別の型を持つ場合、
     *   後の宣言が勝つ。これは「型が書かれているか」だけを見る用途なので問題にならない
     *   (**どの型か**の判定には使っていない)。
     * ★型を書いていない引数 (`function f($x)`) は表に載らないので、
     *   その受け手の保護対象語彙の呼び出しは**未解決として落ちる**。
     *
     * @return array<string, string>
     */
    private static function declaredParameterTypes(string $source): array
    {
        $tokens = PhpTokenScan::normalize($source);
        $count = count($tokens);

        /** @var array<string, string> $variables */
        $variables = [];

        for ($i = 0; $i < $count; $i++) {
            if ($tokens[$i]['id'] !== T_FUNCTION && $tokens[$i]['id'] !== T_FN) {
                continue;
            }

            // 引数リストの括弧を探す
            $open = null;
            for ($k = $i + 1; $k < $count && $k <= $i + 4; $k++) {
                if ($tokens[$k]['text'] === '(') {
                    $open = $k;

                    break;
                }
            }
            if ($open === null) {
                continue;
            }

            $depth = 0;
            for ($k = $open; $k < $count; $k++) {
                $text = $tokens[$k]['text'];
                if ($text === '(') {
                    $depth++;

                    continue;
                }
                if ($text === ')') {
                    $depth--;
                    if ($depth === 0) {
                        break;
                    }

                    continue;
                }

                if ($depth !== 1 || ! str_starts_with($text, '$')) {
                    continue;
                }

                // 直前に型 (T_STRING) が並んでいれば「型が書かれている」とみなす
                for ($t = $k - 1; $t >= $open && $t >= $k - 3; $t--) {
                    if ($tokens[$t]['text'] === '?' || $tokens[$t]['text'] === '|') {
                        continue;
                    }
                    if ($tokens[$t]['id'] === T_STRING || $tokens[$t]['id'] === T_ARRAY) {
                        $variables[substr($text, 1)] = $tokens[$t]['text'];
                    }
                    break;
                }
            }
        }

        return $variables;
    }

    /**
     * @param  list<string>  $lowered
     */
    private static function siteReferences(ReferenceSite $site, array $lowered): bool
    {
        if (in_array($site->kind, [ReferenceKind::NameReference, ReferenceKind::Construction], true)) {
            return in_array(strtolower($site->name), $lowered, true);
        }

        if ($site->kind === ReferenceKind::StaticCall && $site->receiver->isResolved()) {
            return in_array(strtolower($site->receiver->fqcn()), $lowered, true);
        }

        return false;
    }
}
