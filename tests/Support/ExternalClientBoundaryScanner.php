<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * 「AWS SDK / Flysystem (= 外部ストレージ client) へ到達しうる site」と
 * 「Stripe SDK のプロセス大域 setter を呼ぶ site」を PHP ソースから静的に走査する純関数群。
 *
 * ★走査は `PhpTokenScan::normalize()` (空白 / コメント / DocComment 除去) の結果に対して行う。
 *   `T_CONSTANT_ENCAPSED_STRING` の中身は名前解決の対象にしない
 *   (コメント・文字列中の `Aws\` を拾わない = 偽陽性の排除)。
 *
 * ★**保証範囲を誇張しない**: 検出できるのは「型/クラス名の参照」「`disk()` / `getClient()` の
 *   呼び出し」「Stripe 大域 setter の呼び出し」だけである。文字列キーの container 解決だけで
 *   これらの token をまったく出さない経路 (`app('filesystem')` の戻りを別メソッドへ渡す等) は
 *   **検出できない**。この非対称は docs/architecture.md §外部 SDK の待ち上限の規約に明記する。
 *
 * ★検出理由コード: `fqn_reference` / `imported_name_reference` (クラス名の参照) /
 *   `new_external_object` (**構築点**。DI で受け取るだけの消費点と区別するために種別を分ける) /
 *   `disk_call` / `get_client_call` / `stripe_global_setter`。
 *
 * ★**R1 (`use` import) は site ではない**。PHP の `use` はクラス本体の外 (ファイルスコープ) に
 *   書かれるため、これを実行 site として扱うと正規の import を持つ全ファイルが違反になる。
 *   R1 は **alias マップの構築にのみ使い、母集団へは登録しない**。
 */
final class ExternalClientBoundaryScanner
{
    /** 到達境界とみなす名前空間の接頭辞。 */
    private const array TARGET_PREFIXES = [
        'Aws\\',
        'League\\Flysystem\\',
        'Illuminate\\Filesystem\\',
    ];

    /** 到達境界とみなすクラス名 (完全一致)。 */
    private const array TARGET_EXACT = [
        'Illuminate\\Support\\Facades\\Storage',
        'Illuminate\\Container\\Attributes\\Storage',
        'Illuminate\\Contracts\\Filesystem\\Filesystem',
    ];

    /** Stripe のプロセス大域状態を触るシンボル (静的呼び出しで検出する)。 */
    public const array STRIPE_GLOBAL_SYMBOLS = ['setHttpClient', 'setMaxNetworkRetries', 'instance'];

    /**
     * 到達境界の site を走査する (R2〜R6 の全規則)。
     *
     * ★Stripe の大域 setter (R6) も **到達境界**である (外部 SDK のプロセス大域状態へ触る)。
     *   目録の対称差検査から外すと pin provider が「残骸」として検出されてしまう。
     *
     * @return list<array{
     *     path: string,
     *     line: int,
     *     rule: string,
     *     name: string,
     *     scopeKind: ScanScopeKind,
     *     class: string|null,
     *     callable: string|null,
     *     diskArgument: 'none'|'static'|'dynamic'|null,
     * }>
     */
    public static function boundarySites(string $relativePath, string $phpSource): array
    {
        return self::scan($relativePath, $phpSource);
    }

    /**
     * Stripe 大域 setter の site を走査する。
     *
     * @return list<array{
     *     path: string,
     *     line: int,
     *     rule: string,
     *     name: string,
     *     scopeKind: ScanScopeKind,
     *     class: string|null,
     *     callable: string|null,
     *     diskArgument: 'none'|'static'|'dynamic'|null,
     * }>
     */
    public static function stripeGlobalSites(string $relativePath, string $phpSource): array
    {
        return array_values(array_filter(
            self::scan($relativePath, $phpSource),
            static fn (array $site): bool => $site['rule'] === 'stripe_global_setter',
        ));
    }

    /**
     * 全規則の site を 1 パスで走査する。
     *
     * @return list<array{
     *     path: string,
     *     line: int,
     *     rule: string,
     *     name: string,
     *     scopeKind: ScanScopeKind,
     *     class: string|null,
     *     callable: string|null,
     *     diskArgument: 'none'|'static'|'dynamic'|null,
     * }>
     */
    public static function scan(string $relativePath, string $phpSource): array
    {
        $tokens = PhpTokenScan::normalize($phpSource);
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

            // --- R1: use import (alias マップ構築専用。母集団へ登録しない) ---
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

            // --- R2: 完全修飾 / 修飾名による参照 ---
            if ($id === T_NAME_FULLY_QUALIFIED || $id === T_NAME_QUALIFIED) {
                if (self::isTargetName($text)) {
                    $rule = ($tokens[$i - 1]['id'] ?? null) === T_NEW ? 'new_external_object' : 'fqn_reference';
                    $sites[] = self::site($relativePath, $token['line'], $rule, ltrim($text, '\\'), $scopeKind, $scopeClass, $callableName, null);
                }

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

            // --- R4: disk() 呼び出し (receiver を問わない) ---
            if ($text === 'disk' && ($isMemberAccess || $isStaticAccess) && $isCall) {
                $sites[] = self::site(
                    $relativePath,
                    $token['line'],
                    'disk_call',
                    'disk',
                    $scopeKind,
                    $scopeClass,
                    $callableName,
                    self::classifyCallArgument($tokens, $i + 1),
                );

                continue;
            }

            // --- R5: getClient() 呼び出し (receiver を問わない) ---
            if ($text === 'getClient' && ($isMemberAccess || $isStaticAccess) && $isCall) {
                $sites[] = self::site($relativePath, $token['line'], 'get_client_call', 'getClient', $scopeKind, $scopeClass, $callableName, null);

                continue;
            }

            // --- R6: Stripe のプロセス大域 setter ---
            if (in_array($text, self::STRIPE_GLOBAL_SYMBOLS, true) && $isStaticAccess && $isCall) {
                $receiver = $tokens[$i - 2] ?? null;
                $receiverName = $receiver === null ? null : self::resolveName($receiver, $aliases);
                if ($receiverName !== null && str_starts_with($receiverName, 'Stripe\\')) {
                    $sites[] = self::site($relativePath, $token['line'], 'stripe_global_setter', $text, $scopeKind, $scopeClass, $callableName, null);

                    continue;
                }
            }

            // --- R3: import 済み short name による参照 (型宣言 / new / ::class / instanceof を含む) ---
            if ($isMemberAccess || $isStaticAccess) {
                continue; // メソッド名 / 定数名であってクラス参照ではない
            }
            if ($previousId === T_FUNCTION || $previousId === T_CONST || $previousId === T_CLASS
                || $previousId === T_INTERFACE || $previousId === T_TRAIT || $previousId === T_ENUM
                || $previousId === T_AS || $previousId === T_GOTO) {
                continue; // 宣言名であって参照ではない
            }
            $resolved = $aliases[mb_strtolower($text)] ?? null;
            if ($resolved !== null && self::isTargetName($resolved)) {
                // R7: `new Aws\…` は「構築点」であり、DI で受け取るだけの消費点と区別する
                // (免除理由の適用条件を機械検査するために種別を分ける)。
                $rule = $previousId === T_NEW ? 'new_external_object' : 'imported_name_reference';
                $sites[] = self::site($relativePath, $token['line'], $rule, $resolved, $scopeKind, $scopeClass, $callableName, null);
            }
        }

        return self::dropOrphanGetClientSites($sites);
    }

    /**
     * `getClient()` は receiver を問わず拾う (`app('filesystem')->disk('s3')->getClient()` を
     * 逃さないため) 一方で、**名前が同じだけの無関係な API** (OAuth の
     * `AuthCodeEntity::getClient()` 等) まで母集団へ入れると目録が意味を失う。
     *
     * そこで `get_client_call` は「**同じファイルに到達境界の名前参照または `disk()` 呼び出しがある**」
     * ことを条件に登録する。到達境界に触れているファイル内の client 取り出しだけを見る。
     *
     * @param  list<array{path: string, line: int, rule: string, name: string, scopeKind: ScanScopeKind, class: string|null, callable: string|null, diskArgument: 'none'|'static'|'dynamic'|null}>  $sites
     * @return list<array{path: string, line: int, rule: string, name: string, scopeKind: ScanScopeKind, class: string|null, callable: string|null, diskArgument: 'none'|'static'|'dynamic'|null}>
     */
    private static function dropOrphanGetClientSites(array $sites): array
    {
        $hasBoundaryReference = false;
        foreach ($sites as $site) {
            if (in_array($site['rule'], ['fqn_reference', 'imported_name_reference', 'new_external_object', 'disk_call'], true)) {
                $hasBoundaryReference = true;

                break;
            }
        }

        if ($hasBoundaryReference) {
            return $sites;
        }

        return array_values(array_filter(
            $sites,
            static fn (array $site): bool => $site['rule'] !== 'get_client_call',
        ));
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
                if ($current !== '') {
                    $fqcn = ltrim($prefix.$current, '\\');
                    $short = $alias ?? self::shortName($fqcn);
                    $aliases[mb_strtolower($short)] = $fqcn;
                }
                $current = '';
                $alias = null;
                $expectAlias = false;

                if ($text === '{') {
                    // グループ use: 直前までの名前が接頭辞になる
                    $prefix = '';
                    // `{` の直前に確定させた current を接頭辞へ戻す必要があるため再構築する
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
     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
     */
    private static function groupPrefix(array $tokens, int $useIndex, int $braceIndex): string
    {
        $prefix = '';
        for ($i = $useIndex + 1; $i < $braceIndex; $i++) {
            $id = $tokens[$i]['id'];
            if ($id === T_STRING || $id === T_NAME_QUALIFIED || $id === T_NAME_FULLY_QUALIFIED) {
                $prefix .= $tokens[$i]['text'];
            }
        }

        return ltrim($prefix, '\\');
    }

    /**
     * 呼び出しの引数が「静的に決まる disk 名」か判定する。
     *
     * - `none`: 引数なし (`$this->disk()` のような集約 accessor)
     * - `static`: 文字列リテラル 1 個、またはクラス定数参照 1 個 (`FakeObjectStore::DISK` / `self::DISK`)
     * - `dynamic`: 変数を含む等、静的に決められない
     *
     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
     * @param  int  $openParenIndex  `(` の添字
     * @return 'none'|'static'|'dynamic'
     */
    private static function classifyCallArgument(array $tokens, int $openParenIndex): string
    {
        $count = count($tokens);
        $depth = 0;
        /** @var list<array{id: int|null, text: string, line: int}> $inner */
        $inner = [];

        for ($i = $openParenIndex; $i < $count; $i++) {
            $token = $tokens[$i];
            if ($token['id'] === null && $token['text'] === '(') {
                $depth++;
                if ($depth === 1) {
                    continue;
                }
            }
            if ($token['id'] === null && $token['text'] === ')') {
                $depth--;
                if ($depth === 0) {
                    break;
                }
            }
            if ($depth >= 1) {
                $inner[] = $token;
            }
        }

        if ($inner === []) {
            return 'none';
        }

        if (count($inner) === 1 && $inner[0]['id'] === T_CONSTANT_ENCAPSED_STRING) {
            return 'static';
        }

        // クラス定数参照 (`Foo::BAR` / `self::BAR` / `static::BAR`) — 静的に確定する
        if (count($inner) === 3 && $inner[1]['id'] === T_DOUBLE_COLON && $inner[2]['id'] === T_STRING) {
            $head = $inner[0]['id'];
            if ($head === T_STRING || $head === T_NAME_QUALIFIED || $head === T_NAME_FULLY_QUALIFIED || $head === T_STATIC) {
                return 'static';
            }
        }

        return 'dynamic';
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

    /** 到達境界の対象名か。 */
    private static function isTargetName(string $name): bool
    {
        $normalized = ltrim($name, '\\');

        if (in_array($normalized, self::TARGET_EXACT, true)) {
            return true;
        }

        foreach (self::TARGET_PREFIXES as $prefix) {
            if (str_starts_with($normalized, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private static function shortName(string $fqcn): string
    {
        $position = strrpos($fqcn, '\\');

        return $position === false ? $fqcn : substr($fqcn, $position + 1);
    }

    /**
     * @param  'none'|'static'|'dynamic'|null  $diskArgument
     * @return array{
     *     path: string,
     *     line: int,
     *     rule: string,
     *     name: string,
     *     scopeKind: ScanScopeKind,
     *     class: string|null,
     *     callable: string|null,
     *     diskArgument: 'none'|'static'|'dynamic'|null,
     * }
     */
    private static function site(
        string $path,
        int $line,
        string $rule,
        string $name,
        ScanScopeKind $scopeKind,
        ?string $class,
        ?string $callable,
        ?string $diskArgument,
    ): array {
        return [
            'path' => $path,
            'line' => $line,
            'rule' => $rule,
            'name' => $name,
            'scopeKind' => $scopeKind,
            'class' => $class,
            'callable' => $callable,
            'diskArgument' => $diskArgument,
        ];
    }

    /**
     * 失敗メッセージ用の 1 行。「なぜ母集団に入ったのか」が読める形にする。
     *
     * @param  array{path: string, line: int, rule: string, name: string, scopeKind: ScanScopeKind, class: string|null, callable: string|null, diskArgument: 'none'|'static'|'dynamic'|null}  $site
     */
    public static function describe(array $site): string
    {
        $callable = $site['callable'] ?? '(file scope)';

        return "{$site['path']}:{$site['line']} [{$site['rule']}] {$site['name']} ({$callable})";
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

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($absoluteRoot, \FilesystemIterator::SKIP_DOTS),
        );

        $files = [];
        foreach ($iterator as $file) {
            if (! $file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
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
