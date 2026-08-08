<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * 「AWS SDK / Flysystem (= 外部ストレージ client) へ到達しうる site」と
 * 「Stripe SDK のプロセス大域 setter を呼ぶ site」を PHP ソースから静的に走査する純関数群。
 *
 * ★走査そのものは `PhpReferenceScanner` (中立走査器) に委譲し、本クラスは
 *   **「何を到達境界とみなすか」の filter だけ**を持つ。同じ namespace 解決 /
 *   alias マップ / brace scope 追跡を 2 本持たないため (T138 で抽出。public API と
 *   出力 shape は抽出前と完全に不変)。
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
     * ★分岐順は抽出前の `continue` 順と同じ (disk / getClient / stripe を名前参照より**先**に
     *   評価する)。順序を変えると `dropOrphanGetClientSites()` の入力が変わる。
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
        $tokens = PhpReferenceScanner::tokens($phpSource);
        $sites = [];

        foreach (PhpReferenceScanner::references($relativePath, $phpSource)->sites as $reference) {
            $isCall = $reference->kind === ReferenceKind::MethodCall || $reference->kind === ReferenceKind::StaticCall;

            $site = match (true) {
                // R4: disk() 呼び出し (receiver を問わない)
                $isCall && $reference->name === 'disk' => self::fromReference(
                    $reference,
                    'disk_call',
                    'disk',
                    self::classifyCallArgument($tokens, $reference->tokenIndex + 1),
                ),

                // R5: getClient() 呼び出し (receiver を問わない)
                $isCall && $reference->name === 'getClient' => self::fromReference(
                    $reference,
                    'get_client_call',
                    'getClient',
                    null,
                ),

                // R6: Stripe のプロセス大域 setter
                $reference->kind === ReferenceKind::StaticCall
                    && in_array($reference->name, self::STRIPE_GLOBAL_SYMBOLS, true)
                    && $reference->receiver !== null
                    && str_starts_with($reference->receiver, 'Stripe\\') => self::fromReference($reference, 'stripe_global_setter', $reference->name, null),

                // R7: `new Aws\…` は「構築点」であり、DI で受け取るだけの消費点と区別する
                $reference->kind === ReferenceKind::Construction && self::isTargetName($reference->name) => self::fromReference($reference, 'new_external_object', $reference->name, null),

                // R2 / R3: 到達境界の名前参照
                $reference->kind === ReferenceKind::NameReference && self::isTargetName($reference->name) => self::fromReference(
                    $reference,
                    $reference->qualified ? 'fqn_reference' : 'imported_name_reference',
                    $reference->name,
                    null,
                ),

                default => null,
            };

            if ($site !== null) {
                $sites[] = $site;
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

    /**
     * 中立走査器の site を本目録の site shape へ変換する。
     *
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
    private static function fromReference(
        ReferenceSite $reference,
        string $rule,
        string $name,
        ?string $diskArgument,
    ): array {
        return [
            'path' => $reference->path,
            'line' => $reference->line,
            'rule' => $rule,
            'name' => $name,
            'scopeKind' => $reference->scopeKind,
            'class' => $reference->class,
            'callable' => $reference->callable,
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
        return PhpReferenceScanner::phpFiles($absoluteRoot, $relativeRoot);
    }
}
