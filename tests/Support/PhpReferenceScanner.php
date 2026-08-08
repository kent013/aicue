<?php

declare(strict_types=1);

namespace Tests\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * PHP ソースの「名前参照 / 構築 / 呼び出し」を列挙する中立走査器 (純関数)。
 *
 * ★走査は `PhpTokenScan::normalize()` (空白 / コメント / DocComment 除去) の結果に対して行う。
 *   `T_CONSTANT_ENCAPSED_STRING` の中身は名前解決の対象にしない。
 * ★**何を「外部到達」とみなすかは一切知らない**。判定は利用側 (`ExternalClientBoundaryScanner` /
 *   `Tests\Support\ExternalSeam\ExternalSeamScanner`) が行う。ここに TARGET を持ち込むと
 *   2 目録の責務が混ざる。
 * ★**`use` import は site ではない**。alias マップの構築にのみ使い、母集団へは登録しない
 *   (PHP の `use` はクラス本体の外に書かれるため、site 扱いすると正規の import を持つ
 *    全ファイルが違反になる)。ただし「ファイルがその名前空間を知っているか」の文脈判定に
 *   使えるよう `ReferenceScanResult::$imports` として返す。
 * ★`{` の数え漏れに注意: `T_CURLY_OPEN` / `T_DOLLAR_OPEN_CURLY_BRACES` (文字列補間) の
 *   閉じ `}` は単一文字トークンで現れるため、開き側を depth に数えないと brace が片側だけ減り
 *   以降の site が誤って FileScope 帰属になる (T126 の実測で発覚した罠)。
 */
final class PhpReferenceScanner
{
    /**
     * 正規化済みトークン列 (呼び出し引数の追加解析用に利用側へ渡す)。
     *
     * @return list<array{id: int|null, text: string, line: int}>
     */
    public static function tokens(string $phpSource): array
    {
        return PhpTokenScan::normalize($phpSource);
    }

    /**
     * 参照 site と import を列挙する。
     *
     * ★**emission 契約**: `Socialite::driver('g')` の正規化トークン列は
     *   `T_STRING(Socialite)` / `T_DOUBLE_COLON` / `T_STRING(driver)` / `(` である。
     *   receiver の `Socialite` は「直前が `::` ではない」ため **`NameReference` として emit される**。
     *   加えて `driver` が `StaticCall(receiver: 'Laravel\Socialite\Facades\Socialite')` として
     *   emit される。すなわち **1 つの静的呼び出しは NameReference と StaticCall の 2 site を生む**。
     *   利用側はどちらか一方だけを canonical にすること (両方を見ると二重検出になる)。
     *
     * ★**名前解決の限界** (現行 `ExternalClientBoundaryScanner` の挙動をそのまま保存する):
     *   `T_NAME_QUALIFIED` (`Foo\Bar` のような部分修飾名) は `ltrim($text, '\\')` するだけで、
     *   「現在の namespace への相対解決」も「先頭 segment の alias 解決」も**行わない**。
     *   したがって `use Illuminate\Support\Facades; … Facades\Http::get()` は解決できない。
     *   これは既存 gate と同じ非対称であり、抽出は**振る舞い保存**が目的なのでここを直さない。
     */
    public static function references(string $relativePath, string $phpSource): ReferenceScanResult
    {
        $tokens = self::tokens($phpSource);
        $count = count($tokens);

        $namespace = '';
        /** @var array<string, string> $aliases short name (小文字) => FQCN */
        $aliases = [];

        $braceDepth = 0;
        /** @var list<array{kind: ScanScopeKind, class: string|null, bodyDepth: int}> $scopes */
        $scopes = [];
        /** @var array{kind: ScanScopeKind, class: string|null}|null $pendingScope */
        $pendingScope = null;
        /** @var list<array{name: string, bodyDepth: int}> $callables */
        $callables = [];
        $pendingCallable = null;

        /** @var list<ReferenceSite> $sites */
        $sites = [];

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            $id = $token['id'];
            $text = $token['text'];

            // --- namespace 宣言 ---
            if ($id === T_NAMESPACE) {
                $next = $tokens[$i + 1] ?? null;
                if ($next !== null && ($next['id'] === T_NAME_QUALIFIED || $next['id'] === T_STRING)) {
                    $namespace = $next['text'];
                    $i++;
                }

                continue;
            }

            // --- use import (alias マップ構築専用。母集団へ登録しない) ---
            if ($id === T_USE) {
                $next = $tokens[$i + 1] ?? null;
                // closure の `use ($x)` は import ではない
                if ($next !== null && $next['text'] === '(') {
                    continue;
                }
                $i = self::collectUseStatement($tokens, $i, $aliases);

                continue;
            }

            // --- クラス様宣言 (次の `{` で scope を push する) ---
            if ($id === T_CLASS || $id === T_TRAIT || $id === T_INTERFACE || $id === T_ENUM) {
                $previous = $tokens[$i - 1] ?? null;
                if ($previous !== null && $previous['id'] === T_DOUBLE_COLON) {
                    continue; // `Foo::class`
                }

                $next = $tokens[$i + 1] ?? null;
                $isNamed = $next !== null && $next['id'] === T_STRING;
                $pendingScope = [
                    'kind' => $isNamed ? ScanScopeKind::NamedClass : ScanScopeKind::AnonymousClass,
                    'class' => $isNamed && $next !== null
                        ? ($namespace === '' ? $next['text'] : $namespace.'\\'.$next['text'])
                        : null,
                ];

                continue;
            }

            // --- 関数 / メソッド宣言 (診断用の callable 名) ---
            if ($id === T_FUNCTION) {
                $next = $tokens[$i + 1] ?? null;
                $name = $next !== null && $next['id'] === T_STRING ? $next['text'] : '{closure}';
                $pendingCallable = $name;

                continue;
            }

            // --- 文字列補間の `{$x}` / `${x}` ---
            // ★閉じ `}` は**単一文字トークン**として現れるため、開き側を depth に数えないと
            //   brace が片側だけ減り、以降の site が誤って FileScope 帰属になる (実測で発覚)。
            if ($id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) {
                $braceDepth++;

                continue;
            }

            // --- brace の出入りで scope を push / pop ---
            if ($id === null && $text === '{') {
                $braceDepth++;
                if ($pendingScope !== null) {
                    $scopes[] = ['kind' => $pendingScope['kind'], 'class' => $pendingScope['class'], 'bodyDepth' => $braceDepth];
                    $pendingScope = null;
                } elseif ($pendingCallable !== null) {
                    $callables[] = ['name' => $pendingCallable, 'bodyDepth' => $braceDepth];
                    $pendingCallable = null;
                }

                continue;
            }

            if ($id === null && $text === '}') {
                $top = $scopes === [] ? null : $scopes[count($scopes) - 1];
                if ($top !== null && $top['bodyDepth'] === $braceDepth) {
                    array_pop($scopes);
                }
                $topCallable = $callables === [] ? null : $callables[count($callables) - 1];
                if ($topCallable !== null && $topCallable['bodyDepth'] === $braceDepth) {
                    array_pop($callables);
                }
                $braceDepth--;

                continue;
            }

            // 宣言だけで本体が無い (interface / abstract メソッド) の取りこぼしを残さない
            if ($id === null && $text === ';') {
                $pendingCallable = null;
                $pendingScope = null;

                continue;
            }

            $scopeKind = $scopes === [] ? ScanScopeKind::FileScope : $scopes[count($scopes) - 1]['kind'];
            $scopeClass = $scopes === [] ? null : $scopes[count($scopes) - 1]['class'];
            $callableName = $callables === [] ? null : $callables[count($callables) - 1]['name'];

            // --- 完全修飾 / 修飾名による参照 ---
            if ($id === T_NAME_FULLY_QUALIFIED || $id === T_NAME_QUALIFIED) {
                $kind = ($tokens[$i - 1]['id'] ?? null) === T_NEW
                    ? ReferenceKind::Construction
                    : ReferenceKind::NameReference;
                $sites[] = new ReferenceSite(
                    path: $relativePath,
                    line: $token['line'],
                    tokenIndex: $i,
                    kind: $kind,
                    name: ltrim($text, '\\'),
                    receiver: null,
                    qualified: true,
                    scopeKind: $scopeKind,
                    class: $scopeClass,
                    callable: $callableName,
                );

                continue;
            }

            if ($id !== T_STRING) {
                continue;
            }

            $previous = $tokens[$i - 1] ?? null;
            $previousId = $previous['id'] ?? null;
            $isMemberAccess = $previousId === T_OBJECT_OPERATOR || $previousId === T_NULLSAFE_OBJECT_OPERATOR;
            $isStaticAccess = $previousId === T_DOUBLE_COLON;
            $next = $tokens[$i + 1] ?? null;
            $isCall = $next !== null && $next['id'] === null && $next['text'] === '(';

            // --- 静的呼び出し `X::method(` ---
            if ($isStaticAccess && $isCall) {
                $receiverToken = $tokens[$i - 2] ?? null;
                $sites[] = new ReferenceSite(
                    path: $relativePath,
                    line: $token['line'],
                    tokenIndex: $i,
                    kind: ReferenceKind::StaticCall,
                    name: $text,
                    receiver: $receiverToken === null ? null : self::resolveName($receiverToken, $aliases),
                    qualified: false,
                    scopeKind: $scopeKind,
                    class: $scopeClass,
                    callable: $callableName,
                );

                continue;
            }

            // --- メソッド呼び出し `$x->method(` / `$x?->method(` ---
            if ($isMemberAccess && $isCall) {
                $sites[] = new ReferenceSite(
                    path: $relativePath,
                    line: $token['line'],
                    tokenIndex: $i,
                    kind: ReferenceKind::MethodCall,
                    name: $text,
                    receiver: null,
                    qualified: false,
                    scopeKind: $scopeKind,
                    class: $scopeClass,
                    callable: $callableName,
                );

                continue;
            }

            // --- import 済み short name による参照 (型宣言 / new / ::class / instanceof を含む) ---
            if ($isMemberAccess || $isStaticAccess) {
                continue; // メソッド名 / 定数名であってクラス参照ではない
            }
            if ($previousId === T_FUNCTION || $previousId === T_CONST || $previousId === T_CLASS
                || $previousId === T_INTERFACE || $previousId === T_TRAIT || $previousId === T_ENUM
                || $previousId === T_AS || $previousId === T_GOTO) {
                continue; // 宣言名であって参照ではない
            }
            $resolved = $aliases[mb_strtolower($text)] ?? null;
            if ($resolved === null) {
                continue;
            }

            $sites[] = new ReferenceSite(
                path: $relativePath,
                line: $token['line'],
                tokenIndex: $i,
                kind: $previousId === T_NEW ? ReferenceKind::Construction : ReferenceKind::NameReference,
                name: $resolved,
                receiver: null,
                qualified: false,
                scopeKind: $scopeKind,
                class: $scopeClass,
                callable: $callableName,
            );
        }

        return new ReferenceScanResult($sites, $aliases);
    }

    /**
     * `use` 文を読み進めて alias マップへ登録し、`;` の添字を返す。
     *
     * `use function` / `use const` は名前解決の対象外 (クラス参照ではない)。
     * グループ use (`use Aws\{S3\S3Client, Sns\SnsClient};`) にも対応する。
     *
     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
     * @param  array<string, string>  $aliases
     */
    private static function collectUseStatement(array $tokens, int $useIndex, array &$aliases): int
    {
        $count = count($tokens);
        $i = $useIndex + 1;

        if (($tokens[$i]['id'] ?? null) === T_FUNCTION || ($tokens[$i]['id'] ?? null) === T_CONST) {
            // 関数 / 定数の import。`;` まで読み飛ばす
            while ($i < $count && ! ($tokens[$i]['id'] === null && $tokens[$i]['text'] === ';')) {
                $i++;
            }

            return $i;
        }

        $prefix = '';
        $current = '';
        $alias = null;
        $expectAlias = false;

        for (; $i < $count; $i++) {
            $token = $tokens[$i];
            $id = $token['id'];
            $text = $token['text'];

            if ($id === null && ($text === ';' || $text === '{' || $text === '}' || $text === ',')) {
                // ★`{` の直前に溜まっている名前は**グループ use の接頭辞**であって import ではない。
                //   ここで alias 登録すると `use Illuminate\Support\Facades\{Http, Mail};` が
                //   `Facades` という実在しない import を作る。
                if ($current !== '' && $text !== '{') {
                    $fqcn = ltrim($prefix.$current, '\\');
                    $short = $alias ?? self::shortName($fqcn);
                    $aliases[mb_strtolower($short)] = $fqcn;
                }
                $current = '';
                $alias = null;
                $expectAlias = false;

                if ($text === '{') {
                    // グループ use: 直前までの名前が接頭辞になる
                    $prefix = self::groupPrefix($tokens, $useIndex, $i);

                    continue;
                }

                if ($text === ';') {
                    return $i;
                }

                continue;
            }

            if ($id === T_AS) {
                $expectAlias = true;

                continue;
            }

            if ($id === T_STRING || $id === T_NAME_QUALIFIED || $id === T_NAME_FULLY_QUALIFIED) {
                if ($expectAlias) {
                    $alias = $text;

                    continue;
                }
                $current .= $text;

                continue;
            }
        }

        return $count - 1;
    }

    /**
     * グループ use の接頭辞 (`use Aws\{...}` の `Aws\`) を組み立てる。
     *
     * ★区切りの `T_NS_SEPARATOR` も連結する。`use Illuminate\Support\Facades\{Http, Mail};` は
     *   `T_NAME_QUALIFIED('Illuminate\Support\Facades')` + `T_NS_SEPARATOR('\')` + `{` と
     *   トークン化されるため、separator を落とすと接頭辞が `Illuminate\Support\Facades` になり
     *   `Illuminate\Support\FacadesHttp` という壊れた FQCN を作る。
     *
     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
     */
    private static function groupPrefix(array $tokens, int $useIndex, int $braceIndex): string
    {
        $prefix = '';
        for ($i = $useIndex + 1; $i < $braceIndex; $i++) {
            $id = $tokens[$i]['id'];
            if ($id === T_STRING || $id === T_NAME_QUALIFIED || $id === T_NAME_FULLY_QUALIFIED
                || $id === T_NS_SEPARATOR) {
                $prefix .= $tokens[$i]['text'];
            }
        }

        return ltrim($prefix, '\\');
    }

    /**
     * トークンをクラス名 (FQCN) として解決する。解決できなければ null。
     *
     * @param  array{id: int|null, text: string, line: int}  $token
     * @param  array<string, string>  $aliases
     */
    private static function resolveName(array $token, array $aliases): ?string
    {
        $id = $token['id'];
        if ($id === T_NAME_FULLY_QUALIFIED || $id === T_NAME_QUALIFIED) {
            return ltrim($token['text'], '\\');
        }
        if ($id === T_STRING) {
            return $aliases[mb_strtolower($token['text'])] ?? null;
        }

        return null;
    }

    private static function shortName(string $fqcn): string
    {
        $position = strrpos($fqcn, '\\');

        return $position === false ? $fqcn : substr($fqcn, $position + 1);
    }

    /**
     * ディレクトリ配下の PHP ファイルを相対パス => ソースで返す。
     *
     * @return array<string, string>
     */
    public static function phpFiles(string $absoluteRoot, string $relativeRoot): array
    {
        if (! is_dir($absoluteRoot)) {
            return [];
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($absoluteRoot, FilesystemIterator::SKIP_DOTS),
        );

        $files = [];
        foreach ($iterator as $file) {
            if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $absolute = $file->getPathname();
            $source = file_get_contents($absolute);
            if ($source === false) {
                continue;
            }
            $relative = $relativeRoot.'/'.ltrim(str_replace($absoluteRoot, '', $absolute), '/');
            $files[$relative] = $source;
        }

        ksort($files);

        return $files;
    }
}
