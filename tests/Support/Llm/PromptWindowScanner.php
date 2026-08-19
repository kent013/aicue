<?php

declare(strict_types=1);

namespace Tests\Support\Llm;

use App\DataTransferObjects\Manual\Analysis\ImageAnalysisMediaData;
use App\DataTransferObjects\Manual\Analysis\PdfAnalysisMediaData;
use App\Support\Llm\GuardedPrompt;
use App\Support\Llm\PromptCanary;
use App\Support\Llm\PromptDefense;
use App\Support\Llm\UntrustedTextSanitizer;
use Kent013\PrismPrompt\EmbeddingPrompt;
use Kent013\PrismPrompt\Prompt;
use Kent013\PrismPrompt\TextPrompt;
use Kent013\PrismPrompt\Values\UserInput;
use Prism\Prism\ValueObjects\Media\Document;
use Prism\Prism\ValueObjects\Media\Image;
use Prism\Prism\ValueObjects\Media\Media;
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
     * vendor 媒体型 (画像・スキャン SOP の OCR 対応)。`Media` に構築以外の static メソッドは
     * 存在しないため、`Image::`/`Document::` への任意の static 呼び出しを構築とみなせる。
     */
    private const array VENDOR_MEDIA_TYPES = [Image::class, Document::class];

    /** vendor 媒体型の基底 (subclass 検出の対象。Image/Document 自身も含む)。 */
    private const array VENDOR_MEDIA_BASE_TYPES = [Image::class, Document::class, Media::class];

    /** vendor prompt 継承検出の対象。 */
    private const array VENDOR_PROMPT_EXTENDS_TARGETS = [Prompt::class, TextPrompt::class];

    /**
     * 媒体 DTO の named constructor (`ImageAnalysisMediaData`/`PdfAnalysisMediaData` の
     * `fromValidated`)。受け手が不明でも、この特徴的なメソッド名なら fail-closed で拾う。
     */
    private const array MEDIA_DATA_CLASSES = [ImageAnalysisMediaData::class, PdfAnalysisMediaData::class];

    private const string MEDIA_DATA_METHOD = 'fromValidated';

    private const string WINDOW_LOAD_WITH_MEDIA_METHOD = 'loadWithMedia';

    /**
     * `Media` の named constructor 群 (受け手が不明な静的呼び出しを fail-closed で
     * 拾うための、既知のメソッド名の集合)。**受け手が解決できている場合はこの列挙を使わない**
     * (`Image::`/`Document::` への任意の static 呼び出しをそのまま構築とみなす)。
     * 列挙が要るのは「受け手が変数などで隠されている」場合だけである。
     *
     * @var list<string>
     */
    private const array MEDIA_CONSTRUCTOR_METHOD_NAMES = [
        'fromFileId', 'fromPath', 'fromLocalPath', 'fromStoragePath',
        'fromUrl', 'fromRawContent', 'fromBase64', 'fromText', 'fromChunks',
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
        array_push($sites, ...self::extendsDeclarations($relativePath, $phpSource));
        array_push($sites, ...self::dynamicMethodNameCalls($relativePath, $phpSource));
        array_push($sites, ...self::arrayCallableConstructions($relativePath, $phpSource));

        return $sites;
    }

    /**
     * `Image::{$method}(...)` のような中括弧による動的メソッド名の静的呼び出しを検出する
     * (画像・スキャン SOP の OCR 対応。impl-review Round 2 Critical 対応)。
     *
     * `PhpReferenceScanner` の静的呼び出し検出は「`::` の直後が `T_STRING` (メソッド名)」
     * という形だけを対象にしており、`::` の直後が `{` (中括弧開始) になるこの構文は
     * 元々 site として emit されない (=`VendorMediaTypeConstruction`/`MediaDataNamedConstructorCall`
     * のどちらの分類にも到達しない迂回路になっていた)。受け手が対象クラスへ解決できる場合、
     * メソッド名が静的に決まらなくても**受け手が対象クラスである事実だけ**で違反候補として拾う
     * (fail-closed。メソッド名を問わないので `VendorMediaTypeConstruction` と同じ理由で
     * 列挙が要らない)。
     *
     * ★ **保証しないもの**: 受け手も動的な形 (`$class::{$method}(...)`) は対象外
     * (受け手の名前解決すらできないため、対象クラスかどうか判定できない)。
     *
     * @return list<PromptWindowSite>
     */
    public static function dynamicMethodNameCalls(string $relativePath, string $phpSource): array
    {
        $tokens = PhpReferenceScanner::tokens($phpSource);
        $imports = PhpReferenceScanner::references($relativePath, $phpSource)->imports;
        $namespace = self::firstNamespace($tokens);
        $count = count($tokens);

        /** @var array<string, PromptWindowRule> $watched */
        $watched = array_merge(
            array_fill_keys(self::VENDOR_MEDIA_TYPES, PromptWindowRule::VendorMediaTypeConstruction),
            array_fill_keys(self::MEDIA_DATA_CLASSES, PromptWindowRule::MediaDataNamedConstructorCall),
        );

        $sites = [];
        for ($i = 0; $i < $count; $i++) {
            if ($tokens[$i]['id'] !== T_DOUBLE_COLON) {
                continue;
            }
            $next = $tokens[$i + 1] ?? null;
            if ($next === null || $next['id'] !== null || $next['text'] !== '{') {
                continue; // 通常の `::method(` / `::CONST` / `::class` はここでは扱わない
            }
            $receiverToken = $tokens[$i - 1] ?? null;
            if ($receiverToken === null) {
                continue;
            }
            $resolved = self::resolveNameToken($receiverToken, $namespace, $imports);
            if ($resolved === null) {
                continue;
            }
            $rule = $watched[$resolved] ?? null;
            if ($rule === null) {
                continue;
            }

            $sites[] = new PromptWindowSite(
                $relativePath,
                $receiverToken['line'],
                $rule,
                $resolved.'::{$dynamic}(...)',
            );
        }

        return $sites;
    }

    /**
     * 1 トークンを名前として解決する (完全修飾 / 部分修飾 / import 済み短縮名の
     * いずれか)。解決できなければ null (呼び出し側は fail-closed へ倒すこと)。
     *
     * @param  array{id: int|null, text: string, line: int}  $token
     * @param  array<string, string>  $imports
     */
    private static function resolveNameToken(array $token, string $namespace, array $imports): ?string
    {
        if ($token['id'] === T_NAME_FULLY_QUALIFIED) {
            return ltrim($token['text'], '\\');
        }
        if ($token['id'] === T_NAME_QUALIFIED || $token['id'] === T_NAME_RELATIVE) {
            return self::resolveExtendsQualifiedName($token['id'], $token['text'], $namespace, $imports);
        }
        if ($token['id'] === T_STRING) {
            return $imports[mb_strtolower($token['text'])]
                ?? ($namespace === '' ? $token['text'] : $namespace.'\\'.$token['text']);
        }

        return null;
    }

    /**
     * `[X::class, 'method']` という配列 callable の**構築** (画像・スキャン SOP の OCR 対応。
     * impl-review Round 3 Critical 対応)。呼び出し側がこの配列を実際に呼び出すかどうかは
     * データフロー解析が要るため追わない — **構築されている事実だけ**を deny-by-default で
     * 拒否する (窓口 1 ファイルの外で対象クラスの callable 配列を組む理由が無い)。
     * `VendorMediaTypeConstruction`/`MediaDataNamedConstructorCall` が「構築」を pin する
     * 既存の考え方 (呼び出しの証明ではなく構築点を塞ぐ) をそのまま踏襲する。
     *
     * 検出する構文: `[` `X::class` `,` 文字列リテラル `]` (要素順序固定。逆順・3 要素以上・
     * 変数を含む形は対象外 = 未解決として拾わない。これは「配列 callable なら何でも検出する」
     * という主張はしておらず、`X::class` を先頭に持つ最小限の形だけを塞ぐものである)。
     *
     * @return list<PromptWindowSite>
     */
    public static function arrayCallableConstructions(string $relativePath, string $phpSource): array
    {
        $tokens = PhpReferenceScanner::tokens($phpSource);
        $imports = PhpReferenceScanner::references($relativePath, $phpSource)->imports;
        $namespace = self::firstNamespace($tokens);
        $count = count($tokens);

        /** @var array<string, PromptWindowRule> $watched */
        $watched = array_merge(
            array_fill_keys(self::VENDOR_MEDIA_TYPES, PromptWindowRule::VendorMediaTypeConstruction),
            array_fill_keys(self::MEDIA_DATA_CLASSES, PromptWindowRule::MediaDataNamedConstructorCall),
        );

        $sites = [];
        for ($i = 0; $i < $count; $i++) {
            $open = $tokens[$i];
            if ($open['id'] !== null || $open['text'] !== '[') {
                continue;
            }

            $classToken = $tokens[$i + 1] ?? null;
            $doubleColon = $tokens[$i + 2] ?? null;
            $classKeyword = $tokens[$i + 3] ?? null;
            $comma = $tokens[$i + 4] ?? null;
            $methodToken = $tokens[$i + 5] ?? null;
            $close = $tokens[$i + 6] ?? null;

            if ($classToken === null || $doubleColon === null || $classKeyword === null
                || $comma === null || $methodToken === null || $close === null) {
                continue;
            }
            if ($doubleColon['id'] !== T_DOUBLE_COLON || $classKeyword['id'] !== T_CLASS) {
                continue;
            }
            if ($comma['id'] !== null || $comma['text'] !== ',') {
                continue;
            }
            if ($methodToken['id'] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }
            if ($close['id'] !== null || $close['text'] !== ']') {
                continue; // 3 要素以上・末尾カンマ等は対象外 (fail-closed で「未解決」とはせず、
                // 「この最小形ではない」として単に検出対象から外れる。保証範囲は上記 docblock 参照)
            }

            $resolved = self::resolveNameToken($classToken, $namespace, $imports);
            if ($resolved === null) {
                continue;
            }
            $rule = $watched[$resolved] ?? null;
            if ($rule === null) {
                continue;
            }

            $sites[] = new PromptWindowSite(
                $relativePath,
                $classToken['line'],
                $rule,
                '['.$resolved.'::class, '.trim($methodToken['text'], "'\"").']',
            );
        }

        return $sites;
    }

    /** @return array<string, string> 現在の namespace 宣言 (最初の 1 つ。ファイル先頭が前提) */
    private static function firstNamespace(array $tokens): string
    {
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            if ($tokens[$i]['id'] === T_NAMESPACE) {
                $next = $tokens[$i + 1] ?? null;
                if ($next !== null && ($next['id'] === T_NAME_QUALIFIED || $next['id'] === T_STRING)) {
                    return $next['text'];
                }
                break;
            }
        }

        return '';
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
            $method = match ($site->rule) {
                PromptWindowRule::WindowLoad => 'load',
                PromptWindowRule::WindowLoadUnattributed => 'loadUnattributed',
                PromptWindowRule::WindowLoadWithMedia => self::WINDOW_LOAD_WITH_MEDIA_METHOD,
                default => null,
            };
            if ($method === null) {
                continue;
            }

            // site の行から `PromptDefense::` に続くメソッド名トークンを探し直す
            // (ReferenceSite は tokenIndex を持つが、行と種別で十分に一意である)。
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

    /**
     * ★★ **保証しないもの (誇張しない。画像・スキャン SOP の OCR 対応で追加した 5 ルール共通)**:
     *   本メソッド (と `PhpReferenceScanner` 自体) は**字句 (トークン) レベルの静的解析**であり、
     *   受信者・メソッド名の両方が式 (関数呼び出しの戻り値・複雑な式等) になる形は
     *   token 列だけでは確定できない (データフロー解析が要る) ため検出できない
     *   (docs/architecture.md の「LLM プロンプト防御の窓口方式」節が既存の
     *   `VendorPromptLoad`/`WindowLoad` ルールについて既に宣言している限界と同種)。
     *
     *   ただし次の 2 形は、この走査器のアーキテクチャの中で追加検出している
     *   (impl-review Round 2/3 Critical 対応)。
     *   - **中括弧による動的メソッド名** (`Image::{$method}(...)`): 受け手が対象クラスへ
     *     解決できる場合に限り `dynamicMethodNameCalls()` が検出する (メソッド名は問わない)
     *   - **配列 callable の構築** (`[Image::class, 'method']`): `arrayCallableConstructions()` が
     *     構築時点の構文 (`X::class` を先頭要素に持つ 2 要素配列リテラル) を検出する
     *     (実際に呼び出されるかどうかは追わない。構築そのものを塞ぐ)
     */
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
                self::WINDOW_LOAD_WITH_MEDIA_METHOD => PromptWindowRule::WindowLoadWithMedia,
                self::MEDIA_DATA_METHOD => PromptWindowRule::MediaDataNamedConstructorCall,
                default => in_array($reference->name, self::MEDIA_CONSTRUCTOR_METHOD_NAMES, true)
                    ? PromptWindowRule::VendorMediaTypeConstruction
                    : null,
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

        // `Image::<任意の static メソッド>(` / `Document::<任意の static メソッド>(`
        // (Media に構築以外の static メソッドは存在しないため、メソッド名を列挙しない)
        if ($reference->kind === ReferenceKind::StaticCall
            && $reference->receiver->isResolved()
            && in_array($reference->receiver->fqcn(), self::VENDOR_MEDIA_TYPES, true)) {
            return new PromptWindowSite(
                $reference->path,
                $reference->line,
                PromptWindowRule::VendorMediaTypeConstruction,
                $reference->receiver->fqcn().'::'.$reference->name,
            );
        }

        // `ImageAnalysisMediaData::fromValidated(` / `PdfAnalysisMediaData::fromValidated(`
        if ($reference->kind === ReferenceKind::StaticCall
            && $reference->name === self::MEDIA_DATA_METHOD
            && $reference->receiver->isResolved()
            && in_array($reference->receiver->fqcn(), self::MEDIA_DATA_CLASSES, true)) {
            return new PromptWindowSite(
                $reference->path,
                $reference->line,
                PromptWindowRule::MediaDataNamedConstructorCall,
                $reference->receiver->fqcn().'::fromValidated',
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

        // `new Image(` / `new Document(`
        if ($reference->kind === ReferenceKind::Construction
            && in_array($reference->name, self::VENDOR_MEDIA_TYPES, true)) {
            return new PromptWindowSite(
                $reference->path,
                $reference->line,
                PromptWindowRule::VendorMediaTypeConstruction,
                'new '.$reference->name,
            );
        }

        // `PromptDefense::load(` / `PromptDefense::loadUnattributed(` / `PromptDefense::loadWithMedia(`
        if ($reference->kind === ReferenceKind::StaticCall && $reference->receiver->is(PromptDefense::class)) {
            $rule = match ($reference->name) {
                'load' => PromptWindowRule::WindowLoad,
                'loadUnattributed' => PromptWindowRule::WindowLoadUnattributed,
                self::WINDOW_LOAD_WITH_MEDIA_METHOD => PromptWindowRule::WindowLoadWithMedia,
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

    /**
     * `extends` 宣言の対象クラス名を検出する (画像・スキャン SOP の OCR 対応。
     * `MediaPromptExtendsDeclaration` / `VendorMediaTypeSubclassDeclaration` が使う)。
     *
     * `PhpReferenceScanner::references()` は import 済み短縮名・完全修飾名・部分修飾名を
     * `extends` の位置でも NameReference として解決するが、「その参照が `extends` の直後に
     * 書かれたものか」までは持ち帰らない (`ReferenceSite` は前後の文脈を持たない)。
     * そのため本メソッドは `T_EXTENDS` トークンの直後だけを対象にした専用の走査を行う。
     *
     * 名前解決は `PhpReferenceScanner::references()` が返す import 表 (`imports`) を再利用し、
     * 解決できない場合のみ現在の namespace の下に解決する (import 表側の解決規則と同じ)。
     * 対象 (`Prompt`/`TextPrompt`/`Image`/`Document`/`Media`) に一致しない `extends` は
     * 単に無視する (母集団は app/ 全体の class 宣言だが、興味があるのはこの 5 クラスだけ)。
     *
     * @return list<PromptWindowSite>
     */
    public static function extendsDeclarations(string $relativePath, string $phpSource): array
    {
        $tokens = PhpReferenceScanner::tokens($phpSource);
        $imports = PhpReferenceScanner::references($relativePath, $phpSource)->imports;
        $count = count($tokens);
        $namespace = self::firstNamespace($tokens);

        $sites = [];
        for ($i = 0; $i < $count; $i++) {
            if ($tokens[$i]['id'] !== T_EXTENDS) {
                continue;
            }
            $target = $tokens[$i + 1] ?? null;
            if ($target === null) {
                continue;
            }

            $name = match ($target['id']) {
                T_NAME_FULLY_QUALIFIED => ltrim($target['text'], '\\'),
                T_NAME_QUALIFIED, T_NAME_RELATIVE => self::resolveExtendsQualifiedName(
                    $target['id'],
                    $target['text'],
                    $namespace,
                    $imports,
                ),
                T_STRING => $imports[mb_strtolower($target['text'])]
                    ?? ($namespace === '' ? $target['text'] : $namespace.'\\'.$target['text']),
                default => null,
            };
            if ($name === null) {
                continue;
            }

            if (in_array($name, self::VENDOR_PROMPT_EXTENDS_TARGETS, true)) {
                $sites[] = new PromptWindowSite(
                    $relativePath, $target['line'], PromptWindowRule::MediaPromptExtendsDeclaration, 'extends '.$name,
                );
            } elseif (in_array($name, self::VENDOR_MEDIA_BASE_TYPES, true)) {
                $sites[] = new PromptWindowSite(
                    $relativePath, $target['line'], PromptWindowRule::VendorMediaTypeSubclassDeclaration, 'extends '.$name,
                );
            }
        }

        return $sites;
    }

    /**
     * @param  array<string, string>  $imports
     */
    private static function resolveExtendsQualifiedName(?int $id, string $text, string $namespace, array $imports): string
    {
        $separator = strpos($text, '\\');
        if ($id === T_NAME_RELATIVE) {
            $rest = $separator === false ? '' : substr($text, $separator + 1);

            return $namespace === '' ? $rest : $namespace.'\\'.$rest;
        }

        $head = $separator === false ? $text : substr($text, 0, $separator);
        $resolvedHead = $imports[mb_strtolower($head)] ?? null;
        if ($resolvedHead !== null) {
            return $separator === false ? $resolvedHead : $resolvedHead.substr($text, $separator);
        }

        return $namespace === '' ? $text : $namespace.'\\'.$text;
    }
}
