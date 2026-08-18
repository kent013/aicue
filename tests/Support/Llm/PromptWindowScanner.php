<?php

declare(strict_types=1);

namespace Tests\Support\Llm;

use App\Support\Llm\GuardedPrompt;
use App\Support\Llm\PromptCanary;
use App\Support\Llm\PromptDefense;
use App\Support\Llm\UntrustedTextSanitizer;
use Kent013\PrismPrompt\EmbeddingPrompt;
use Kent013\PrismPrompt\Prompt;
use Kent013\PrismPrompt\TextPrompt;
use Kent013\PrismPrompt\Values\UserInput;
use Tests\Support\PhpReferenceScanner;
use Tests\Support\ReceiverName;
use Tests\Support\ReferenceKind;
use Tests\Support\ReferenceSite;
use Tests\Support\ScanScopeKind;

/**
 * 「factory → 窓口 → 実行単位」の 1 本道を静的に確かめるための**判定だけ**を持つ薄い層。
 *
 * ★ 走査そのものは `Tests\Support\PhpReferenceScanner` (namespace / alias / scope を解決する
 *   中立走査器) が行う。token 走査器をもう 1 本作らない
 *   (`ExternalSeamScanner` / `ExternalClientBoundaryScanner` と同じ関係)。
 * ★ 走査は正規化済みトークン列に対して行われるため、**コメント / docblock / 文字列リテラル中の
 *   出現には反応しない**。gate 自身の説明文を数えてしまう事故 (家系の先行実装で実際に起きた)
 *   を構造的に避けている。
 * ★ 保証範囲を誇張しない: 見えるのは**静的な出現**だけである。文字列キーの container 解決や
 *   vendor 内部から出る呼び出しには沈黙する。
 */
final class PromptWindowScanner
{
    /** vendor prompt の読み込み receiver (完全一致)。 */
    public const array VENDOR_PROMPT_CLASSES = [
        Prompt::class,
        TextPrompt::class,
        EmbeddingPrompt::class,
    ];

    /** vendor prompt の読み込みメソッド名。 */
    private const string VENDOR_LOAD_METHOD = 'load';

    /** 窓口の内部部品 (窓口の外から参照されてはならない = 規律を分散させない)。 */
    public const array INTERNAL_PARTS = [
        UserInput::class,
        UntrustedTextSanitizer::class,
        PromptCanary::class,
    ];

    /**
     * 1 ファイルを走査して site を列挙する。
     *
     * @return list<PromptWindowSite>
     */
    public static function scan(string $relativePath, string $phpSource): array
    {
        $result = PhpReferenceScanner::references($relativePath, $phpSource);

        $references = $result->sites;
        array_push($references, ...self::sameNamespaceReferences($relativePath, $phpSource, $result->imports));

        $sites = [];
        foreach ($references as $reference) {
            $site = self::classify($reference);
            if ($site !== null) {
                $sites[] = $site;
            }
        }

        return $sites;
    }

    /**
     * **同じ名前空間の短縮名**を補って参照 site にする。
     *
     * `PhpReferenceScanner` は import (`use`) が無い短縮名を名前参照 site にしない
     * (同クラスの「保証しないもの」。`true` や定数まで同じ `T_STRING` で現れるため、
     * 短縮名を一律に site 化すると母集団が意味を失う)。
     * しかし窓口一式は `App\Support\Llm` に同居しているため、そのままでは
     * `PromptDefense.php` 内の `UntrustedTextSanitizer::sanitize(...)` の**受け手**や
     * `PromptCanary` の型宣言が 1 件も見えず、**所有権の検査が空振りしたまま緑になる**。
     * ここを補って穴を塞ぐ。
     *
     * ★ tokenizer は増やさない (`PhpReferenceScanner::tokens()` の正規化列を使う)。
     * ★ 補うのは**窓口一式の短縮名だけ**で、無関係な名前は 1 つも site にしない。
     * ★ 補うのは `NameReference` だけである。`new Foo(` と静的呼び出しそのものは
     *   中立走査器が解決するようになったので、ここで出すと**二重計上**になる。
     *
     * @param  array<string, string>  $imports  小文字 short name => FQCN
     * @return list<ReferenceSite>
     */
    private static function sameNamespaceReferences(string $relativePath, string $phpSource, array $imports): array
    {
        $tokens = PhpReferenceScanner::tokens($phpSource);
        $count = count($tokens);

        $namespace = '';
        for ($i = 0; $i < $count; $i++) {
            if ($tokens[$i]['id'] === T_NAMESPACE) {
                $next = $tokens[$i + 1] ?? null;
                if ($next !== null && ($next['id'] === T_NAME_QUALIFIED || $next['id'] === T_STRING)) {
                    $namespace = $next['text'];
                }
                break;
            }
        }
        if ($namespace === '') {
            return [];
        }

        /** @var array<string, string> $candidates 短縮名 => FQCN (この名前空間に属するものだけ) */
        $candidates = [];
        foreach ([...self::INTERNAL_PARTS, GuardedPrompt::class, PromptDefense::class] as $fqcn) {
            if (str_starts_with($fqcn, $namespace.'\\')
                && ! str_contains(substr($fqcn, strlen($namespace) + 1), '\\')) {
                $candidates[substr($fqcn, strlen($namespace) + 1)] = $fqcn;
            }
        }
        if ($candidates === []) {
            return [];
        }

        $references = [];
        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if ($token['id'] !== T_STRING || ! isset($candidates[$token['text']])) {
                continue;
            }
            if (isset($imports[mb_strtolower($token['text'])])) {
                continue; // import 済み = 中立走査器が既に site を出している
            }

            $previous = $tokens[$i - 1] ?? null;
            $previousId = $previous['id'] ?? null;
            if ($previousId === T_DOUBLE_COLON || $previousId === T_OBJECT_OPERATOR
                || $previousId === T_NULLSAFE_OBJECT_OPERATOR || $previousId === T_CLASS
                || $previousId === T_FUNCTION || $previousId === T_CONST) {
                continue; // メソッド名 / 宣言名であってクラス参照ではない
            }

            if ($previousId === T_NEW) {
                continue; // `new Foo(` は中立走査器が Construction として解決済み
            }

            $next = $tokens[$i + 1] ?? null;
            if ($next !== null && $next['id'] === T_DOUBLE_COLON) {
                $method = $tokens[$i + 2] ?? null;
                $paren = $tokens[$i + 3] ?? null;
                if ($method === null || $method['id'] !== T_STRING
                    || $paren === null || $paren['id'] !== null || $paren['text'] !== '(') {
                    continue; // `Foo::CONST` や `Foo::class`
                }
                // ★ 静的呼び出しで補うのは**受け手の NameReference だけ**である。呼び出しそのものは
                //   中立走査器が受け手を解決した StaticCall として出す (二重計上しない)。
                //   所有権の検査は NameReference 側を canonical にしているためここが要る。
            }

            $references[] = self::reference(
                $relativePath,
                $token['line'],
                $i,
                $candidates[$token['text']],
            );
        }

        return $references;
    }

    private static function reference(
        string $path,
        int $line,
        int $tokenIndex,
        string $name,
    ): ReferenceSite {
        return new ReferenceSite(
            path: $path,
            line: $line,
            tokenIndex: $tokenIndex,
            kind: ReferenceKind::NameReference,
            name: $name,
            receiver: ReceiverName::absent(),
            qualified: false,
            scopeKind: ScanScopeKind::NamedClass,
            class: null,
            callable: null,
        );
    }

    /**
     * 走査根 (相対パス => 絶対パス) をまとめて走査する。
     *
     * @param  array<string, string>  $roots
     * @return list<PromptWindowSite>
     */
    public static function scanRoots(array $roots): array
    {
        $sites = [];
        foreach ($roots as $relativeRoot => $absoluteRoot) {
            foreach (PhpReferenceScanner::phpFiles($absoluteRoot, $relativeRoot) as $relative => $source) {
                array_push($sites, ...self::scan($relative, $source));
            }
        }

        return $sites;
    }

    /**
     * 指定の種別の site だけを、重複を除いた**ファイルパスの一覧**として返す。
     *
     * @param  list<PromptWindowSite>  $sites
     * @return list<string>
     */
    public static function pathsOf(array $sites, PromptWindowRule $rule): array
    {
        $paths = [];
        foreach ($sites as $site) {
            if ($site->rule === $rule) {
                $paths[$site->path] = true;
            }
        }
        $unique = array_keys($paths);
        sort($unique);

        return $unique;
    }

    /**
     * 窓口呼び出しの引数 (`template:` / `untrusted:`) を静的に読み取る。
     *
     * ★ 読めるのは**名前付き引数 + リテラル**の形だけである。これは制約ではなく仕様で、
     *   動的に組み立てられた template 名や配列キーは `null` として返し、gate が違反にする
     *   (静的検査を無効化する書き方を許さない)。
     *
     * @return list<PromptWindowCall>
     */
    public static function windowCalls(string $relativePath, string $phpSource): array
    {
        $tokens = PhpReferenceScanner::tokens($phpSource);
        $count = count($tokens);

        /** @var list<PromptWindowCall> $calls */
        $calls = [];
        foreach (self::scan($relativePath, $phpSource) as $site) {
            if ($site->rule !== PromptWindowRule::WindowLoad && $site->rule !== PromptWindowRule::WindowLoadUnattributed) {
                continue;
            }

            // site の行から `PromptDefense::` に続くメソッド名トークンを探し直す
            // (ReferenceSite は tokenIndex を持つが、行と種別で十分に一意である)。
            $method = $site->rule === PromptWindowRule::WindowLoad ? 'load' : 'loadUnattributed';
            for ($i = 0; $i < $count; $i++) {
                $token = $tokens[$i];
                if ($token['id'] !== T_STRING || $token['text'] !== $method || $token['line'] !== $site->line) {
                    continue;
                }
                $previous = $tokens[$i - 1] ?? null;
                $next = $tokens[$i + 1] ?? null;
                if ($previous === null || $previous['id'] !== T_DOUBLE_COLON) {
                    continue;
                }
                if ($next === null || $next['id'] !== null || $next['text'] !== '(') {
                    continue;
                }

                $calls[] = new PromptWindowCall(
                    path: $site->path,
                    line: $site->line,
                    method: $method,
                    template: self::readTemplateArgument($tokens, $i + 1),
                    untrustedKeys: self::readUntrustedArgument($tokens, $i + 1),
                );
                break;
            }
        }

        return $calls;
    }

    /**
     * `template: 'sop-extract'` を読む (名前付き引数 + 文字列リテラルのみ)。
     *
     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
     * @param  int  $openIndex  `(` の添字
     */
    private static function readTemplateArgument(array $tokens, int $openIndex): ?string
    {
        $index = self::findNamedArgument($tokens, $openIndex, 'template');
        if ($index === null) {
            return null;
        }
        $value = $tokens[$index] ?? null;
        if ($value === null || $value['id'] !== T_CONSTANT_ENCAPSED_STRING) {
            return null;
        }

        return self::literalValue($value['text']);
    }

    /**
     * `untrusted: ['text' => $x]` のキー一覧を読む (配列リテラル + 文字列リテラルキーのみ)。
     *
     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
     * @param  int  $openIndex  `(` の添字
     * @return list<string>|null
     */
    private static function readUntrustedArgument(array $tokens, int $openIndex): ?array
    {
        $index = self::findNamedArgument($tokens, $openIndex, 'untrusted');
        if ($index === null) {
            return null;
        }
        $open = $tokens[$index] ?? null;
        if ($open === null || $open['id'] !== null || $open['text'] !== '[') {
            return null; // 配列リテラル以外 (変数 / 関数呼び出し) は読まない = 違反にする
        }

        $count = count($tokens);
        $depth = 0;
        $keys = [];
        $expectKey = true;
        for ($i = $index; $i < $count; $i++) {
            $token = $tokens[$i];
            if ($token['id'] === null && ($token['text'] === '[' || $token['text'] === '(')) {
                $depth++;

                continue;
            }
            if ($token['id'] === null && ($token['text'] === ']' || $token['text'] === ')')) {
                $depth--;
                if ($depth === 0) {
                    return $keys;
                }

                continue;
            }
            if ($depth !== 1) {
                continue; // 入れ子の中はキーとして数えない
            }
            if ($token['id'] === null && $token['text'] === ',') {
                $expectKey = true;

                continue;
            }
            if (! $expectKey) {
                continue;
            }
            // 要素の先頭。`'key' =>` の形だけを許す
            if ($token['id'] !== T_CONSTANT_ENCAPSED_STRING || ($tokens[$i + 1]['id'] ?? null) !== T_DOUBLE_ARROW) {
                return null;
            }
            $keys[] = self::literalValue($token['text']);
            $expectKey = false;
        }

        return null; // 閉じ括弧に到達しなかった (走査不能)
    }

    /**
     * `(` の直後から名前付き引数 `name:` を探し、**値の先頭トークン**の添字を返す。
     *
     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
     */
    private static function findNamedArgument(array $tokens, int $openIndex, string $name): ?int
    {
        $count = count($tokens);
        $depth = 0;
        for ($i = $openIndex; $i < $count; $i++) {
            $token = $tokens[$i];
            if ($token['id'] === null && ($token['text'] === '(' || $token['text'] === '[')) {
                $depth++;

                continue;
            }
            if ($token['id'] === null && ($token['text'] === ')' || $token['text'] === ']')) {
                $depth--;
                if ($depth === 0) {
                    return null;
                }

                continue;
            }
            if ($depth !== 1) {
                continue;
            }
            // ★ `$tokens[$i]['id'] ?? …` は使えない: 単一文字トークンの id は null であり、
            //   `??` は isset() 判定なので既定値へ落ちてしまう (実測で踏んだ罠)。
            $next = $tokens[$i + 1] ?? null;
            if ($token['id'] === T_STRING && $token['text'] === $name
                && $next !== null && $next['id'] === null && $next['text'] === ':') {
                return $i + 2;
            }
        }

        return null;
    }

    /** `'text'` / `"text"` の中身を取り出す (エスケープを含まない単純なリテラルのみ扱う)。 */
    private static function literalValue(string $literal): string
    {
        return trim($literal, "'\"");
    }

    private static function classify(ReferenceSite $reference): ?PromptWindowSite
    {
        // 受け手を静的に決められない静的呼び出し。**読み込み系のメソッド名なら拾う** = fail-closed。
        // ★`load` は「vendor 直読み」と「窓口呼び出し」のどちらか判別できないので、
        //   **窓口 1 ファイルにしか許されない側** (VendorPromptLoad) として扱う。
        //   窓口を迂回する経路を変数経由の書き方で隠せてはならない (共通規約 (b))。
        if ($reference->kind === ReferenceKind::StaticCall && $reference->receiver->isUnresolved()) {
            $rule = match ($reference->name) {
                self::VENDOR_LOAD_METHOD => PromptWindowRule::VendorPromptLoad,
                'loadUnattributed' => PromptWindowRule::WindowLoadUnattributed,
                default => null,
            };
            if ($rule !== null) {
                return new PromptWindowSite(
                    $reference->path,
                    $reference->line,
                    $rule,
                    '(受け手が未解決)::'.$reference->name,
                );
            }
        }

        // `Prompt::load(` / `TextPrompt::load(` / `EmbeddingPrompt::load(`
        if ($reference->kind === ReferenceKind::StaticCall
            && $reference->name === self::VENDOR_LOAD_METHOD
            && $reference->receiver->isResolved()
            && in_array($reference->receiver->fqcn(), self::VENDOR_PROMPT_CLASSES, true)) {
            return new PromptWindowSite(
                $reference->path,
                $reference->line,
                PromptWindowRule::VendorPromptLoad,
                $reference->receiver->fqcn().'::load',
            );
        }

        // `new GuardedPrompt(`
        if ($reference->kind === ReferenceKind::Construction && $reference->name === GuardedPrompt::class) {
            return new PromptWindowSite(
                $reference->path,
                $reference->line,
                PromptWindowRule::GuardedPromptConstruction,
                'new '.GuardedPrompt::class,
            );
        }

        // `PromptDefense::load(` / `PromptDefense::loadUnattributed(`
        if ($reference->kind === ReferenceKind::StaticCall && $reference->receiver->is(PromptDefense::class)) {
            $rule = match ($reference->name) {
                'load' => PromptWindowRule::WindowLoad,
                'loadUnattributed' => PromptWindowRule::WindowLoadUnattributed,
                default => null,
            };
            if ($rule !== null) {
                return new PromptWindowSite(
                    $reference->path,
                    $reference->line,
                    $rule,
                    PromptDefense::class.'::'.$reference->name,
                );
            }
        }

        // 窓口の内部部品への参照 (型宣言 / `::class` / 静的呼び出しの receiver を含む)。
        // ★ 静的呼び出しは receiver 側が NameReference としても emit されるため、
        //   canonical は NameReference / Construction 側だけにする (二重計上しない)。
        if (($reference->kind === ReferenceKind::NameReference || $reference->kind === ReferenceKind::Construction)
            && in_array($reference->name, self::INTERNAL_PARTS, true)) {
            return new PromptWindowSite(
                $reference->path,
                $reference->line,
                PromptWindowRule::InternalPartReference,
                $reference->name,
            );
        }

        return null;
    }
}
