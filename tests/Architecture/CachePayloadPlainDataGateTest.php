<?php

declare(strict_types=1);

/*
 * Architecture invariant: **キャッシュに入れてよいのは素のデータだけ** (配列 / 文字列 / 数値 / 真偽値)。
 *
 * SoT = lctl 台帳 feature `cache-payload-plain-data` の標準形 v1 (裁定 2026-08-06) と
 * AGENTS.md セキュリティ不変条件 11 / docs/app-integration-guide.md §7 不変条件 6。
 *
 * ★なぜ静的検査か (実行時検出では捕まらない):
 *   テストレーンは phpunit.xml で CACHE_STORE=array、config/cache.php の array store は
 *   'serialize' => false。**オブジェクトを put してもそのまま返る = テストは緑になる**。
 *   本番は database store で serialize され、serializable_classes => false のため
 *   読み戻しは __PHP_Incomplete_Class になる。つまり「テストで再現しない本番専用の壊れ方」であり、
 *   実行時 detector (KeyWritten 購読等) は原理的にこの穴を塞げない。
 *
 * ★serializable_classes は **false 固定**であって「キーを消してよい」ではない:
 *   CacheManager は `config['cache.serializable_classes'] ?? null` を読み、各 store は
 *   `if ($this->serializableClasses !== null)` のときだけ allowed_classes を渡す。
 *   キーを消すと制限なしの unserialize() に戻る = **fail-open**。宣言の pin は
 *   tests/Feature/Config/ConfigHardeningTest.php (config ファイル直接評価) が担い、
 *   実行時の値はここで pin する。
 *
 * ★この gate が保証するもの:
 *   - 検査 1 (L1 語彙): キャッシュ受け手に対して呼ばれた API が全件 4 分類のどれかに属する。
 *     未分類は fail (Laravel が新しい書き込み API を足したときに黙って通さない)
 *   - 検査 2-3 (L2 書き込み経路): WRITE に分類された呼び出し箇所が目録と exact-fit。
 *     未登録も、実在しない登録も、件数のズレも fail。各 entry は payload の説明・
 *     往復を固定する単体テストのパス (実在検証つき)・30 文字以上の根拠を持つ
 *   - 検査 4-5 (L3 面): **キャッシュ記号に触れているファイル集合**が目録と exact-fit で、
 *     宣言した role (write / no-payload-write / lock-only / driver-handoff) が実測と整合する
 *     (規則自体も検査 5b で固定)
 *   - 検査 6: 実行時 config('cache.serializable_classes') === false、store 単位の上書きなし
 *   - 検査 5b: role 判定規則そのものの正負コントロール (実在ファイルの構成に依存させない)
 *   - 検査 6b: 語彙表の健全性 (4 分類が互いに素 / 除外型が受け手型に混ざっていない)
 *   - 検査 7: 空振り検知 (走査ファイル数 / メソッド呼び出し数 / 解決できたキャッシュ式が 0 でない)
 *   - 検査 8: 自己参照コントロール (本ファイル自身を走査して書き込み 0 件・面 hit なし)
 *   - 検査 9 以降: 正負コントロール fixture (facade / チェーン / ヘルパ / DI / コンテナ /
 *     getStore / literal 動的呼び出し / 完全修飾ヘルパ / 静的に判定できない形 /
 *     session・disk / lock / コメント)
 *
 * ★この gate が保証しないもの (誇張しない):
 *   - **payload の式が本当に素データか**は静的に判定しない。目録の `payload` 欄は人間の申告で、
 *     機械が保証するのは「申告なしに書き込み経路を増やせない」ことと「往復の単体テストが実在する」ことだけ
 *   - **facade mock 経由の書き込み** (`Cache::shouldReceive('put')`)。TERMINAL で辿りを止めるため
 *     WRITE には数えない。ただしそのファイルは L3 (面) に必ず現れるので無申告では追加できない
 *   - **受け手そのものが動的に得られる形** (`$container->make($name)->put(...)` など、
 *     bind 名が変数)。受け手を解決できないので WRITE に数えない。L3 でも捕まらない
 *     (`app` / `resolve` / `make` の第 1 引数が literal のときだけ面として数えるため)。
 *     この形は実測 0 件で、通常のレビューで自明に不自然な書き方である
 *   ※ 受け手が cache と分かっている上での**動的メソッド名** (`->{$m}(...)` / `->$m(...)`) は
 *     素通りさせず `unclassified` として fail させる。literal 形 (`->{'put'}(...)`) は通常形と同じに分類する
 *   - **docblock だけで型付けされた受け手** (`@var Repository $c` の docblock を書いた直後に
 *     `$c->put(...)` する形)。型宣言 (引数 / プロパティ / promoted ctor param) のみを見る。
 *     ※同じファイルに対応する型の `use` があれば **L3 (面) には現れる**が、
 *       完全修飾 docblock だけで import も型宣言も無い形は **L3 でも捕まらない**。
 *       docblock 解析は行わない (実測 0 件)
 *   - `use A\{B, C};` のグループ use 構文は扱わない (実測 0 件。`NoNonCompoundGlobalUseTest` が
 *     別途 use の書き方を縛っている)
 *
 * 解析は PhpToken::tokenize (コメント・文字列リテラルは code token ではないので拾わない)。
 * regex にすると**この説明コメント自身**で偽赤になる。DB 不使用 (Architecture lane は TestCase のみ)。
 */

/**
 * 走査対象ディレクトリ (リポジトリルートからの相対)。
 *
 * tests/ を含めるのは、テストが array store の性質に守られて object を cache に入れても
 * 緑になるため (本番だけで壊れる書き方をテストが先に持ち込むのを防ぐ)。
 * 本 gate の fixture は nowdoc の中にあり code token ではないので自己汚染しない (検査 8 で固定)。
 *
 * @var list<string>
 */
const CACHE_PAYLOAD_SCAN_DIRS = ['app', 'routes', 'database', 'tests'];

/**
 * 受け手として解決するキャッシュ型 (FQCN)。
 *
 * @var list<string>
 */
const CACHE_PAYLOAD_RECEIVER_TYPES = [
    'Illuminate\Support\Facades\Cache',
    'Illuminate\Contracts\Cache\Repository',
    'Illuminate\Contracts\Cache\Factory',
    'Illuminate\Contracts\Cache\Store',
    'Illuminate\Cache\Repository',
    'Illuminate\Cache\CacheManager',
    'Illuminate\Cache\TaggedCache',
    'Psr\SimpleCache\CacheInterface',
];

/**
 * キャッシュ名前空間だが payload を持たないため受け手にしない型 (明示除外・理由付き)。
 *
 * @var array<string, string>
 */
const CACHE_PAYLOAD_EXCLUDED_TYPES = [
    'Illuminate\Contracts\Cache\Lock' => '排他オブジェクト。payload を持たない',
    'Illuminate\Contracts\Cache\LockProvider' => '排他の発行元。payload を持たない',
    'Illuminate\Contracts\Cache\LockTimeoutException' => '排他取得失敗の例外型。payload を持たない',
    'Illuminate\Cache\RateLimiter' => 'レート制限。ThrottleCoverageInventoryTest の担当',
    'Illuminate\Cache\RateLimiting\Limit' => 'レート制限の値オブジェクト',
];

/**
 * payload を書き込む API (全小文字)。
 *
 * @var list<string>
 */
const CACHE_PAYLOAD_WRITE_METHODS = [
    'put', 'add', 'forever', 'remember', 'rememberforever', 'sear',
    'flexible', 'putmany', 'set', 'setmultiple',
];

/**
 * payload を書き込まない API (increment/decrement は整数のみ書けるため素データが構造的に保証される)。
 *
 * @var list<string>
 */
const CACHE_PAYLOAD_NON_WRITE_METHODS = [
    'get', 'many', 'getmultiple', 'has', 'missing', 'pull', 'forget', 'delete',
    'deletemultiple', 'flush', 'clear', 'increment', 'decrement',
    'supportstags', 'getprefix', 'getdefaultdriver', 'setdefaultdriver',
    'forgetdriver', 'purge', 'extend', 'itemkey', 'refresheventdispatcher',
];

/**
 * 受け手を保ったまま連鎖する API。
 *
 * `getStore()` は `Illuminate\Contracts\Cache\Store` を返し **put / forever を持つ**ので
 * NON_WRITE ではなく CHAIN (`Cache::getStore()->put(...)` の抜けを塞ぐ)。
 *
 * @var list<string>
 */
const CACHE_PAYLOAD_CHAIN_METHODS = ['store', 'driver', 'tags', 'resolve', 'getstore', 'getfacaderoot'];

/**
 * 受け手がキャッシュでなくなる terminal (以降の連鎖を辿らない)。
 *
 * `getFacadeRoot` はここに置かない — facade の**実体 (CacheManager)** を返すので
 * `Cache::getFacadeRoot()->put(...)` は本物の書き込みになる。CHAIN 側に置く
 * (impl-review Round 1 [Warning] 反映)。
 *
 * @var list<string>
 */
const CACHE_PAYLOAD_TERMINAL_METHODS = [
    'lock', 'restorelock', 'shouldreceive', 'spy', 'partialmock', 'swap',
    'expects', 'shouldhavereceived', 'shouldnothavereceived',
];

/**
 * L2: キャッシュ **書き込み経路**の目録 (deny-by-default / exact-fit)。
 *
 * key   = `{リポジトリルートからの相対パス}::{メソッド名 (全小文字)}` (行番号は使わない。
 *         行がずれるたびに目録が壊れると gate が「邪魔だから消す」対象になるため)
 * count = そのファイル・そのメソッドの出現回数 (exact-fit。2 件目を足したら必ず落ちる)
 * payload = 実際に渡している式と、それが素データである理由
 * proof   = 往復を固定している単体テストのパス (**実在を検査する**)
 * rationale = 30 文字以上の具体的根拠
 *
 * 経路が 1 本しかない現状では専用 enum (app/Enums/Security/) + inventory クラス
 * (tests/Support/Security/) へ昇格させない (AGENTS.md 思考原則 2「今必要なものだけ作る」)。
 *
 * @var array<string, array{count: int, payload: string, proof: string, rationale: string}>
 */
const CACHE_PAYLOAD_WRITE_INVENTORY = [
    'app/Services/FxRateService.php::put' => [
        'count' => 1,
        'payload' => 'FxSnapshotDto::toArray() の連想配列 (float 1 / string 3)。オブジェクトは渡さない',
        'proof' => 'tests/Unit/DataTransferObjects/FxSnapshotDtoTest.php',
        'rationale' => '当日の USD/JPY レートを 1 日 cache する。読み戻しは is_array 検査 + FxSnapshotDto::fromArray() + 失敗時 Cache::forget() で標準形どおり',
    ],
];

/**
 * L3: **キャッシュ記号に触れているファイル**の目録 (exact-fit)。
 *
 * L1/L2 の静的解析には原理的な穴 (変数動的ディスパッチ / docblock 型 / facade mock) がある。
 * 「新しいファイルがキャッシュに触れ始めたこと」自体を粗い網で捕まえ、穴を無申告で通さない。
 *
 * role: write = 任意 payload を書く (L2 にも登録が要る) /
 *       no-payload-write = キャッシュに触れるが任意 payload を書く API を呼ばない (読み出し / 削除 / flush 等) /
 *       lock-only = 排他だけ /
 *       driver-handoff = 受け手 (driver/store) を解決するだけで、読み出し・書き込み・削除の
 *       いずれも行わず他のコンポーネントへそのまま渡す (T215: キューワーカーへの cache 注入が該当)
 * ※「read-only」ではなく no-payload-write と呼ぶ。forget / flush を含む実態と名前を一致させるため
 *
 * @var array<string, array{role: string, rationale: string}>
 */
const CACHE_PAYLOAD_SURFACE_INVENTORY = [
    'app/Console/Commands/Billing/ReconcileAutoRechargeAttempts.php' => [
        'role' => 'lock-only',
        'rationale' => '突合コマンドの多重起動を Cache::lock で抑止するのみ。payload は書かない',
    ],
    'app/Console/Commands/Billing/ReconcileSubscriptionStatus.php' => [
        'role' => 'lock-only',
        'rationale' => 'Stripe 契約状態の突き合わせの多重起動を Cache::lock で抑止するのみ。payload は書かない',
    ],
    'app/Services/Billing/AutoRechargeService.php' => [
        'role' => 'lock-only',
        'rationale' => 'org 単位のオートリチャージ排他に Cache::lock を使うのみ。payload は一切書かない',
    ],
    'app/Services/Billing/SubscriptionService.php' => [
        'role' => 'lock-only',
        'rationale' => 'checkout 開始 / プラン変更の二重実行を Cache::lock で抑止するのみ。payload は書かない',
    ],
    'app/Services/Billing/TicketCheckoutService.php' => [
        'role' => 'lock-only',
        'rationale' => 'チケット checkout の二重発行を Cache::lock で抑止するのみ。payload は書かない',
    ],
    'app/Services/FxRateService.php' => [
        'role' => 'write',
        'rationale' => 'FX レートの当日 cache。素の配列を put し、読み戻しで DTO へ組み立て直す唯一の経路',
    ],
    'tests/Feature/Billing/ReconcileSubscriptionStatusTest.php' => [
        'role' => 'lock-only',
        'rationale' => '突き合わせコマンドの多重起動を再現するため Cache::lock を先取するのみ。payload は書かない',
    ],
    'tests/Feature/Queue/DeferredRetryHorizonTest.php' => [
        'role' => 'driver-handoff',
        'rationale' => 'Worker::setCache() へ渡すため app(\'cache\')->driver() で driver を解決するだけで、読み出し・書き込み・削除のいずれも行わない。未処理例外の計数は framework 側が整数で行う',
    ],
];

/**
 * 走査対象の PHP ファイル一覧。
 *
 * @return list<array{absolute: string, relative: string}>
 */
function cachePayloadScanTargets(): array
{
    $root = base_path();
    $files = [];
    foreach (CACHE_PAYLOAD_SCAN_DIRS as $dir) {
        $base = $root.'/'.$dir;
        if (! is_dir($base)) {
            continue;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $absolute = $file->getRealPath();
            if (! is_string($absolute)) {
                continue;
            }
            $files[] = [
                'absolute' => $absolute,
                'relative' => ltrim(str_replace($root, '', $absolute), '/'),
            ];
        }
    }
    sort($files);

    return $files;
}

/**
 * index 以降で最初の significant token の index。
 *
 * @param  list<PhpToken>  $tokens
 */
function cachePayloadNext(array $tokens, int $index): ?int
{
    $count = count($tokens);
    for ($i = $index; $i < $count; $i++) {
        if (! $tokens[$i]->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
            return $i;
        }
    }

    return null;
}

/**
 * index 以前で最後の significant token の index。
 *
 * @param  list<PhpToken>  $tokens
 */
function cachePayloadPrev(array $tokens, int $index): ?int
{
    for ($i = $index; $i >= 0; $i--) {
        if (! $tokens[$i]->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
            return $i;
        }
    }

    return null;
}

/**
 * `(` の対応する `)` の index。
 *
 * @param  list<PhpToken>  $tokens
 */
function cachePayloadMatchingParen(array $tokens, int $open): ?int
{
    $depth = 0;
    $count = count($tokens);
    for ($i = $open; $i < $count; $i++) {
        if ($tokens[$i]->text === '(') {
            $depth++;
        } elseif ($tokens[$i]->text === ')') {
            $depth--;
            if ($depth === 0) {
                return $i;
            }
        }
    }

    return null;
}

/**
 * `use A\B\C;` / `use A\B\C as D;` から alias => FQCN の表を作る。
 * グループ use (`use A\{B, C};`) は本リポジトリに存在しないため扱わない (限界として冒頭に明記)。
 *
 * @param  list<PhpToken>  $tokens
 * @return array<string, string>
 */
function cachePayloadUseMap(array $tokens): array
{
    $map = [];
    $count = count($tokens);
    for ($i = 0; $i < $count; $i++) {
        if (! $tokens[$i]->is(T_USE)) {
            continue;
        }
        $nameIndex = cachePayloadNext($tokens, $i + 1);
        if ($nameIndex === null || ! $tokens[$nameIndex]->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
            continue; // closure の use(...) など
        }
        $fqcn = ltrim($tokens[$nameIndex]->text, '\\');
        $alias = str_contains($fqcn, '\\') ? substr((string) strrchr($fqcn, '\\'), 1) : $fqcn;

        $asIndex = cachePayloadNext($tokens, $nameIndex + 1);
        if ($asIndex !== null && $tokens[$asIndex]->is(T_AS)) {
            $aliasIndex = cachePayloadNext($tokens, $asIndex + 1);
            if ($aliasIndex !== null && $tokens[$aliasIndex]->is(T_STRING)) {
                $alias = $tokens[$aliasIndex]->text;
            }
        }
        $map[$alias] = $fqcn;
    }

    return $map;
}

/**
 * ソース中の名前トークンを FQCN へ解決する。
 *
 * 未 import の裸 `Cache`、および `use Cache;` (root 名前空間の class alias を import した形) は
 * Laravel の class alias で facade に解決されるため、**安全側に facade とみなす**
 * (過剰検出は目録登録で解消できるが、見落としは本番でしか気付けない)。
 *
 * **名前空間 alias 経由の qualified name も展開する** (`use Illuminate\Support\Facades as F;`
 * → `F\Cache::put(...)`)。head だけを alias に差し替えて残りを捨てると `F\Cache` が
 * `Illuminate\Support\Facades` に潰れて受け手判定から落ちるため、**残りを連結する**
 * (impl-review Round 1 [Critical] 反映)。
 *
 * @param  array<string, string>  $useMap
 */
function cachePayloadResolveName(string $raw, array $useMap): string
{
    $name = ltrim($raw, '\\');
    if (isset($useMap[$name])) {
        $name = $useMap[$name];
    } elseif (str_contains($name, '\\')) {
        $head = strstr($name, '\\', true);
        if (is_string($head) && isset($useMap[$head])) {
            $name = $useMap[$head].substr($name, strlen($head));
        }
    }

    // 名前空間を持たない `Cache` は class alias 経由の facade (`use Cache;` を含む)
    if (! str_contains($name, '\\') && strtolower($name) === 'cache') {
        return 'Illuminate\Support\Facades\Cache';
    }

    return $name;
}

/**
 * 同一ファイル内で「キャッシュ型として宣言された名前」を集める。
 *
 * 型宣言 (promoted ctor param / プロパティ宣言 / 引数) の直後の変数名を拾い、
 * 変数形 (`$cache->`) とプロパティ形 (`$this->cache->`) の両方の受け手名として扱う。
 * 同名の別型ローカル変数を巻き込む可能性はあるが、**安全側に倒す** (誤検出は目録で解消できる)。
 *
 * @param  list<PhpToken>  $tokens
 * @param  array<string, string>  $useMap
 * @return list<string> `$` を除いた名前
 */
function cachePayloadReceiverNames(array $tokens, array $useMap): array
{
    $names = [];
    $count = count($tokens);
    for ($i = 0; $i < $count; $i++) {
        if (! $tokens[$i]->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
            continue;
        }
        if (! in_array(cachePayloadResolveName($tokens[$i]->text, $useMap), CACHE_PAYLOAD_RECEIVER_TYPES, true)) {
            continue;
        }
        // 型宣言の直後 (union / nullable / intersection / DNF の括弧を跨いで) 最初に現れる変数
        $j = cachePayloadNext($tokens, $i + 1);
        // ★直後が `(` なら型宣言ではなく**呼び出し / インスタンス化** (`cache($values, 60)` /
        //   `new Repository($store)`)。ここを跨ぐと引数の変数が受け手名として登録され、
        //   無関係な `$values->put()` を cache 書き込みと誤検出する (impl-review Round 2 反映)。
        if ($j !== null && $tokens[$j]->text === '(') {
            continue;
        }
        while ($j !== null && (
            $tokens[$j]->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])
            || in_array($tokens[$j]->text, ['|', '&', '?', '(', ')'], true)
        )) {
            $j = cachePayloadNext($tokens, $j + 1);
        }
        if ($j !== null && $tokens[$j]->is(T_VARIABLE)) {
            $names[] = ltrim($tokens[$j]->text, '$');
        }
    }

    return array_values(array_unique($names));
}

/**
 * literal 文字列トークン (`'put'` / `"put"`) の中身。literal でなければ null。
 */
function cachePayloadLiteralValue(string $raw): ?string
{
    if (preg_match('/\A[bB]?([\'"])([A-Za-z_][A-Za-z0-9_]*)\1\z/', $raw, $m) !== 1) {
        return null;
    }

    return $m[2];
}

/**
 * 受け手 (`::` / `->` の index) から連鎖を辿ってメソッド呼び出しを分類する。
 *
 * 動的メソッド呼び出しの扱い (`CarbonOverflowArithmeticGateTest` と揃える):
 *   - `->{'put'}(...)` (literal) は静的に決定できるので**通常形と同じに分類する**
 *   - `->{$m}(...)` / `->$m(...)` (変数形) は決定できない。**受け手が cache だと分かっている**以上
 *     素通りさせる理由が無いので `unclassified` として fail させる (実測 0 件)
 *
 * @param  list<PhpToken>  $tokens
 * @return list<array{method: string, line: int, kind: string}> kind: write|non_write|chain|terminal|unclassified
 */
function cachePayloadFollowChain(array $tokens, int $operatorIndex): array
{
    $calls = [];
    $index = $operatorIndex;

    while (true) {
        $nameIndex = cachePayloadNext($tokens, $index + 1);
        if ($nameIndex === null) {
            return $calls;
        }

        $rawName = null;
        $afterName = $nameIndex;

        if ($tokens[$nameIndex]->is(T_STRING)) {
            $rawName = $tokens[$nameIndex]->text;
        } elseif ($tokens[$nameIndex]->text === '{') {
            $inner = cachePayloadNext($tokens, $nameIndex + 1);
            $close = $inner === null ? null : cachePayloadNext($tokens, $inner + 1);
            if ($inner === null || $close === null || $tokens[$close]->text !== '}') {
                return $calls;
            }
            $afterName = $close;
            $rawName = $tokens[$inner]->is(T_CONSTANT_ENCAPSED_STRING)
                ? cachePayloadLiteralValue($tokens[$inner]->text)
                : null;
            if ($rawName === null) {
                // 変数形の動的ディスパッチ。受け手が cache と分かっているので素通りさせない
                $calls[] = ['method' => '{$dynamic}', 'line' => $tokens[$nameIndex]->line, 'kind' => 'unclassified'];

                return $calls;
            }
        } elseif ($tokens[$nameIndex]->is(T_VARIABLE)) {
            // `->$method(...)` 形も同様に判定不能
            $open = cachePayloadNext($tokens, $nameIndex + 1);
            if ($open !== null && $tokens[$open]->text === '(') {
                $calls[] = ['method' => '$dynamic', 'line' => $tokens[$nameIndex]->line, 'kind' => 'unclassified'];
            }

            return $calls;
        } else {
            return $calls;
        }

        $open = cachePayloadNext($tokens, $afterName + 1);
        if ($open === null || $tokens[$open]->text !== '(') {
            return $calls; // プロパティ / 定数アクセス
        }
        $nameIndex = $afterName;

        $method = strtolower($rawName);
        $kind = match (true) {
            in_array($method, CACHE_PAYLOAD_WRITE_METHODS, true) => 'write',
            in_array($method, CACHE_PAYLOAD_NON_WRITE_METHODS, true) => 'non_write',
            in_array($method, CACHE_PAYLOAD_CHAIN_METHODS, true) => 'chain',
            in_array($method, CACHE_PAYLOAD_TERMINAL_METHODS, true) => 'terminal',
            default => 'unclassified',
        };
        $calls[] = ['method' => $rawName, 'line' => $tokens[$nameIndex]->line, 'kind' => $kind];

        if ($kind !== 'chain') {
            return $calls;
        }

        $close = cachePayloadMatchingParen($tokens, $open);
        if ($close === null) {
            return $calls;
        }
        $next = cachePayloadNext($tokens, $close + 1);
        if ($next === null || ! $tokens[$next]->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR])) {
            return $calls;
        }
        $index = $next;
    }
}

/**
 * コンテナ解決の第 1 引数がキャッシュ束縛かどうか。
 *
 * `'cache'` / `'cache.store'` の literal、または受け手型の `::class` のときだけ true。
 * bind 名が変数の形は静的に決まらないので false (冒頭コメントの「保証しないもの」)。
 *
 * @param  list<PhpToken>  $tokens
 * @param  array<string, string>  $useMap
 */
function cachePayloadIsCacheBindingArg(array $tokens, ?int $firstArg, array $useMap): bool
{
    if ($firstArg === null) {
        return false;
    }
    if ($tokens[$firstArg]->is(T_CONSTANT_ENCAPSED_STRING)) {
        return in_array(trim($tokens[$firstArg]->text, "'\""), ['cache', 'cache.store'], true);
    }
    if (! $tokens[$firstArg]->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
        return false;
    }

    // app(Repository::class) 形。`::class` であることまで確認する
    $colon = cachePayloadNext($tokens, $firstArg + 1);
    $classToken = $colon === null ? null : cachePayloadNext($tokens, $colon + 1);
    $isClassConst = $colon !== null && $tokens[$colon]->is(T_DOUBLE_COLON)
        && $classToken !== null && strtolower($tokens[$classToken]->text) === 'class';

    return $isClassConst && in_array(
        cachePayloadResolveName($tokens[$firstArg]->text, $useMap),
        CACHE_PAYLOAD_RECEIVER_TYPES,
        true
    );
}

/**
 * `app()` (引数 0 個 = コンテナそのもの) から `->make('cache')` / `->get('cache')` を辿り、
 * キャッシュ受け手になった直後の `->` の index を返す。該当しなければ null。
 *
 * ★`app('cache')->put(...)` と違い `app()->make('cache')->put(...)` は
 *   受け手が member 名 (`make`) 側に現れるため、literal 束縛の判定を別経路で持たないと
 *   **L2 でも L3 でも丸ごと素通り**する (impl-review Round 1 [Critical] 反映)。
 *
 * @param  list<PhpToken>  $tokens
 * @param  array<string, string>  $useMap
 * @param  int  $closeIndex  `app()` の閉じ括弧 index
 */
function cachePayloadContainerMakeChain(array $tokens, int $closeIndex, array $useMap): ?int
{
    $arrow = cachePayloadNext($tokens, $closeIndex + 1);
    if ($arrow === null || ! $tokens[$arrow]->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR])) {
        return null;
    }
    $method = cachePayloadNext($tokens, $arrow + 1);
    if ($method === null || ! $tokens[$method]->is(T_STRING)
        || ! in_array(strtolower($tokens[$method]->text), ['make', 'makewith', 'get'], true)) {
        return null;
    }
    $open = cachePayloadNext($tokens, $method + 1);
    if ($open === null || $tokens[$open]->text !== '(') {
        return null;
    }
    if (! cachePayloadIsCacheBindingArg($tokens, cachePayloadNext($tokens, $open + 1), $useMap)) {
        return null;
    }
    $close = cachePayloadMatchingParen($tokens, $open);
    $next = $close === null ? null : cachePayloadNext($tokens, $close + 1);
    if ($next === null || ! $tokens[$next]->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR])) {
        return null;
    }

    return $next;
}

/**
 * 1 ファイル分の収集 (純関数。fixture 文字列にも同じ関数を当てられる)。
 *
 * `writes` は **構造体**で返す (文字列に畳んでから再パースすると `strrchr` 等で壊れるため)。
 * ヘルパの配列形 `cache([...], $ttl)` は method 名 `cache` として記録する。
 *
 * @return array{writes: list<array{relative: string, line: int, method: string}>, unclassified: list<string>, methods: list<string>, cacheCalls: int, methodCalls: int, surface: bool}
 */
function cachePayloadCollectFromSource(string $source, string $relative): array
{
    /** @var list<PhpToken> $tokens */
    $tokens = PhpToken::tokenize($source);
    $useMap = cachePayloadUseMap($tokens);
    $receiverNames = cachePayloadReceiverNames($tokens, $useMap);

    $writes = [];
    $unclassified = [];
    $methods = [];
    $cacheCalls = 0;
    $methodCalls = 0;
    $surface = false;
    $count = count($tokens);

    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];

        // 空振り検知用: `->name(` 形のメソッド呼び出し総数 (受け手を問わない)
        if ($token->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR])) {
            $nameIndex = cachePayloadNext($tokens, $i + 1);
            $open = $nameIndex === null ? null : cachePayloadNext($tokens, $nameIndex + 1);
            if ($nameIndex !== null && $tokens[$nameIndex]->is(T_STRING)
                && $open !== null && $tokens[$open]->text === '(') {
                $methodCalls++;
            }
        }

        $operatorIndex = null;

        if ($token->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
            $prev = cachePayloadPrev($tokens, $i - 1);
            $isMemberName = $prev !== null
                && $tokens[$prev]->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_CONST]);
            $resolved = cachePayloadResolveName($token->text, $useMap);
            // ★グローバル関数の呼び出し名は先頭 `\` を落として比較する。
            //   `\cache([...], 60)` は T_NAME_FULLY_QUALIFIED (text = '\cache') なので、
            //   素の text 比較だと**ヘルパ書き込みの完全修飾形が丸ごと素通り**する。
            //   名前空間を含む名前 (`App\cache`) は別物なので除外する。
            $callable = strtolower(ltrim($token->text, '\\'));
            $isRootCallable = ! str_contains($callable, '\\');
            $lower = $isRootCallable ? $callable : '';

            if (! $isMemberName && in_array($resolved, CACHE_PAYLOAD_RECEIVER_TYPES, true)) {
                $surface = true; // use 文・型宣言・::class 参照でも「面」としては hit する
                $next = cachePayloadNext($tokens, $i + 1);
                if ($next !== null && $tokens[$next]->is(T_DOUBLE_COLON)) {
                    // `Cache::put(...)` / `Cache::{'put'}(...)` を followChain に委ねる。
                    // `Repository::class` は followChain が T_CLASS を見て空を返すので無害
                    $operatorIndex = $next;
                }
            }

            if (! $isMemberName && $lower === 'cache') {
                $open = cachePayloadNext($tokens, $i + 1);
                if ($open !== null && $tokens[$open]->text === '(') {
                    $surface = true;
                    $cacheCalls++;
                    $methods[] = 'cache';
                    $firstArg = cachePayloadNext($tokens, $open + 1);
                    $close = cachePayloadMatchingParen($tokens, $open);

                    if ($firstArg === null || $close === null) {
                        // 壊れたソース。何もしない
                    } elseif ($firstArg === $close) {
                        // cache() は Repository を返す = 連鎖の起点
                        $next = cachePayloadNext($tokens, $close + 1);
                        if ($next !== null && $tokens[$next]->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR])) {
                            $operatorIndex = $next; // cache()->put(...)
                        }
                    } elseif ($tokens[$firstArg]->text === '[' || $tokens[$firstArg]->is(T_ARRAY)) {
                        // cache([...], $ttl) は書き込み形
                        $writes[] = ['relative' => $relative, 'line' => $token->line, 'method' => 'cache'];
                    } elseif (! $tokens[$firstArg]->is(T_CONSTANT_ENCAPSED_STRING)) {
                        // ★cache($values, 60) は $values が配列なら書き込みになる。静的に決まらない形は
                        //   deny-by-default で fail させ、Cache::put(...) 等の明示形へ書き換えさせる
                        $unclassified[] = "{$relative}:{$token->line} → cache(<静的に判定できない第 1 引数>)";
                    }
                    // 文字列リテラル引数 (cache('key') / cache('key', $default)) は読み出し
                }
            }

            if (! $isMemberName && in_array($lower, ['app', 'resolve', 'make'], true)) {
                $open = cachePayloadNext($tokens, $i + 1);
                $hasParen = $open !== null && $tokens[$open]->text === '(';
                $firstArg = $hasParen ? cachePayloadNext($tokens, $open + 1) : null;
                $close = $hasParen && $open !== null ? cachePayloadMatchingParen($tokens, $open) : null;

                if ($hasParen && cachePayloadIsCacheBindingArg($tokens, $firstArg, $useMap)) {
                    // app('cache')->put(...) / app(Repository::class)->put(...)
                    $surface = true;
                    $next = $close === null ? null : cachePayloadNext($tokens, $close + 1);
                    if ($next !== null && $tokens[$next]->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR])) {
                        $operatorIndex = $next;
                    }
                } elseif ($hasParen && $close !== null && $firstArg === $close && $lower === 'app') {
                    // app()->make('cache')->put(...) 形。コンテナ経由の 1 段追加
                    $chained = cachePayloadContainerMakeChain($tokens, $close, $useMap);
                    if ($chained !== null) {
                        $surface = true;
                        $operatorIndex = $chained;
                    }
                }
            }
        }

        if ($operatorIndex === null && $token->is(T_VARIABLE)) {
            $name = ltrim($token->text, '$');
            if ($name === 'this') {
                $arrow = cachePayloadNext($tokens, $i + 1);
                $propIndex = $arrow !== null && $tokens[$arrow]->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR])
                    ? cachePayloadNext($tokens, $arrow + 1)
                    : null;
                if ($propIndex !== null && $tokens[$propIndex]->is(T_STRING)
                    && in_array($tokens[$propIndex]->text, $receiverNames, true)) {
                    $after = cachePayloadNext($tokens, $propIndex + 1);
                    if ($after !== null && $tokens[$after]->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR])) {
                        $operatorIndex = $after; // $this->cache->put(...)
                        $surface = true;
                    }
                }
            } elseif (in_array($name, $receiverNames, true)) {
                $arrow = cachePayloadNext($tokens, $i + 1);
                if ($arrow !== null && $tokens[$arrow]->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR])) {
                    $operatorIndex = $arrow; // $cache->put(...)
                    $surface = true;
                }
            }
        }

        if ($operatorIndex === null) {
            continue;
        }

        foreach (cachePayloadFollowChain($tokens, $operatorIndex) as $call) {
            $cacheCalls++;
            $methods[] = $call['method'];
            if ($call['kind'] === 'write') {
                $writes[] = ['relative' => $relative, 'line' => $call['line'], 'method' => $call['method']];
            } elseif ($call['kind'] === 'unclassified') {
                $unclassified[] = "{$relative}:{$call['line']} → ->{$call['method']}()";
            }
        }
    }

    return [
        'writes' => $writes,
        'unclassified' => $unclassified,
        'methods' => $methods,
        'cacheCalls' => $cacheCalls,
        'methodCalls' => $methodCalls,
        'surface' => $surface,
    ];
}

/**
 * L3 の role と実測メソッドの整合違反を返す (純関数。検査 5b の正負コントロールで規則自体を固定する)。
 *
 * role の意味:
 *   write            = 任意 payload を書く (L2 目録にも登録が要る)
 *   no-payload-write = キャッシュに触れるが**任意 payload を書く API を呼ばない**
 *                      (読み出し / 削除 / flush / increment などが該当。
 *                        「read-only」という名前は flush や forget を含む実態と合わないため使わない)
 *   lock-only        = 排他 (`lock` / `restoreLock`) しか使わない
 *   driver-handoff   = 受け手 (driver/store) を**解決するだけ**で、読み出し・書き込み・削除を
 *                      一切行わず他のコンポーネントへそのまま渡す (CHAIN 分類のメソッドのみを許す。
 *                      T215: `Worker::setCache()` へ渡すためだけに `app('cache')->driver()` を呼ぶ形が該当)
 *
 * @param  list<string>  $methods  実測メソッド (全小文字)
 * @return list<string> 違反理由。空なら整合
 */
function cachePayloadRoleViolations(string $role, array $methods, bool $hasWriteEntry): array
{
    if (! in_array($role, ['write', 'no-payload-write', 'lock-only', 'driver-handoff'], true)) {
        return ["role は write / no-payload-write / lock-only / driver-handoff のいずれか (宣言値: {$role})"];
    }

    if ($role === 'write') {
        return $hasWriteEntry ? [] : ['role=write なのに書き込み目録に entry がありません'];
    }

    $violations = [];
    if ($hasWriteEntry) {
        $violations[] = "role={$role} なのに書き込み目録に entry があります";
    }
    if ($methods === []) {
        $violations[] = "role={$role} なのにキャッシュ API 呼び出しが 1 件もありません"
            .'(使わなくなったなら import ごと消す)';
    }

    if ($role === 'lock-only') {
        $extra = array_values(array_diff($methods, ['lock', 'restorelock']));
        if ($extra !== []) {
            $violations[] = 'role=lock-only なのに排他以外のキャッシュ API を呼んでいます: '.implode(', ', $extra);
        }

        return $violations;
    }

    if ($role === 'driver-handoff') {
        // 連鎖 (CHAIN) 分類のメソッドだけを許す。読み出し・書き込み・削除・排他・mock は
        // 1 件でも現れたら違反 (「解決して渡すだけ」という申告を裏切るため)。
        $extra = array_values(array_diff($methods, CACHE_PAYLOAD_CHAIN_METHODS));
        if ($extra !== []) {
            $violations[] = 'role=driver-handoff なのに解決 (連鎖) 以外のキャッシュ API を呼んでいます: '
                .implode(', ', $extra);
        }

        return $violations;
    }

    // no-payload-write: 任意 payload を書かない API と連鎖 API だけを許す
    // (TERMINAL の lock / mock は別 role・別責務なのでここには入れない)
    $allowed = array_merge(CACHE_PAYLOAD_NON_WRITE_METHODS, CACHE_PAYLOAD_CHAIN_METHODS, ['cache']);
    $extra = array_values(array_diff($methods, $allowed));
    if ($extra !== []) {
        $violations[] = 'role=no-payload-write なのに payload を書く / 排他・mock の API を呼んでいます: '
            .implode(', ', $extra);
    }
    // CHAIN だけで終わる形 (受け手を取り回しているのに何もしない) は role の意味を壊すので終端を要求する
    if (array_intersect($methods, array_merge(CACHE_PAYLOAD_NON_WRITE_METHODS, ['cache'])) === []) {
        $violations[] = 'role=no-payload-write なのに終端の操作 (読み出し・削除等) がありません';
    }

    return $violations;
}

/**
 * 走査対象全体の収集結果 (同一プロセス内で 1 度だけ計算する)。
 *
 * @return array{writeCounts: array<string, int>, writeSites: list<string>, unclassified: list<string>, surfaces: array<string, list<string>>, cacheCalls: int, methodCalls: int, files: int}
 */
function cachePayloadCollectAll(): array
{
    /** @var array{writeCounts: array<string, int>, writeSites: list<string>, unclassified: list<string>, surfaces: array<string, list<string>>, cacheCalls: int, methodCalls: int, files: int}|null $cached */
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    $writeCounts = [];
    $writeSites = [];
    $unclassified = [];
    $surfaces = [];
    $cacheCalls = 0;
    $methodCalls = 0;
    $files = 0;

    foreach (cachePayloadScanTargets() as $target) {
        $source = file_get_contents($target['absolute']);
        if (! is_string($source)) {
            continue;
        }
        $files++;
        $collected = cachePayloadCollectFromSource($source, $target['relative']);
        $cacheCalls += $collected['cacheCalls'];
        $methodCalls += $collected['methodCalls'];

        foreach ($collected['writes'] as $write) {
            $writeSites[] = "{$write['relative']}:{$write['line']} → {$write['method']}()";
            $key = $write['relative'].'::'.strtolower($write['method']);
            $writeCounts[$key] = ($writeCounts[$key] ?? 0) + 1;
        }
        $unclassified = array_merge($unclassified, $collected['unclassified']);

        if ($collected['surface']) {
            $surfaces[$target['relative']] = $collected['methods'];
        }
    }

    ksort($writeCounts);
    ksort($surfaces);
    sort($writeSites);

    $cached = [
        'writeCounts' => $writeCounts,
        'writeSites' => $writeSites,
        'unclassified' => $unclassified,
        'surfaces' => $surfaces,
        'cacheCalls' => $cacheCalls,
        'methodCalls' => $methodCalls,
        'files' => $files,
    ];

    return $cached;
}

// ---------------------------------------------------------------------------
// 検査 1: L1 語彙
// ---------------------------------------------------------------------------

test('検査 1: キャッシュ受け手に対する未分類の API 呼び出しが無い', function (): void {
    $result = cachePayloadCollectAll();

    expect($result['unclassified'])->toBe([],
        'キャッシュ受け手に対して 4 分類 (WRITE / NON_WRITE / CHAIN / TERMINAL) のどれにも属さない API が'
        .'呼ばれています。payload を書くなら CACHE_PAYLOAD_WRITE_METHODS へ、書かないなら'
        .'CACHE_PAYLOAD_NON_WRITE_METHODS へ**理由を添えて**分類してください。'
        .PHP_EOL.implode(PHP_EOL, $result['unclassified']));
});

// ---------------------------------------------------------------------------
// 検査 2-3: L2 書き込み経路
// ---------------------------------------------------------------------------

test('検査 2: キャッシュ書き込み経路が目録と exact-fit で一致する', function (): void {
    $result = cachePayloadCollectAll();

    $declared = [];
    foreach (CACHE_PAYLOAD_WRITE_INVENTORY as $key => $entry) {
        $declared[$key] = $entry['count'];
    }
    ksort($declared);

    expect($result['writeCounts'])->toBe($declared,
        'キャッシュ書き込み経路が目録と一致しません (deny-by-default)。'
        .'新しい経路を足したなら CACHE_PAYLOAD_WRITE_INVENTORY へ '
        .'count / payload / proof (往復を固定する単体テストのパス) / rationale (30 文字以上) を'
        .'添えて登録してください。経路を消したなら目録からも消してください。'
        .PHP_EOL.'検出: '.implode(PHP_EOL, $result['writeSites']));
});

test('検査 3: 目録の各 entry が形式要件を満たす', function (): void {
    expect(CACHE_PAYLOAD_WRITE_INVENTORY)->not->toBe([]);

    foreach (CACHE_PAYLOAD_WRITE_INVENTORY as $key => $entry) {
        [$path, $method] = explode('::', $key, 2);

        expect($entry['count'])->toBeGreaterThanOrEqual(1, "{$key}: count は 1 以上");
        // key のメソッド名は全小文字。'cache' はヘルパの配列形 cache([...], $ttl) 専用の名前。
        expect(in_array($method, CACHE_PAYLOAD_WRITE_METHODS, true) || $method === 'cache')
            ->toBeTrue("{$key}: key のメソッドが WRITE 語彙にありません");
        expect(is_file(base_path($path)))->toBeTrue("{$key}: 対象ファイルが実在しません");
        expect(is_file(base_path($entry['proof'])))->toBeTrue(
            "{$key}: proof に指定した単体テスト {$entry['proof']} が実在しません。"
            .'キャッシュへ入れる配列は「往復が壊れないこと」を単体テストで固定してください');
        expect(mb_strlen($entry['rationale']))->toBeGreaterThanOrEqual(30, "{$key}: rationale が短すぎます");
        expect(mb_strlen($entry['payload']))->toBeGreaterThanOrEqual(10, "{$key}: payload の説明が短すぎます");
    }
});

// ---------------------------------------------------------------------------
// 検査 4-5: L3 面
// ---------------------------------------------------------------------------

test('検査 4: キャッシュに触れるファイル集合が目録と exact-fit で一致する', function (): void {
    $result = cachePayloadCollectAll();

    $found = array_keys($result['surfaces']);
    $declared = array_keys(CACHE_PAYLOAD_SURFACE_INVENTORY);
    sort($found);
    sort($declared);

    expect($found)->toBe($declared,
        'キャッシュに触れるファイルの集合が目録と一致しません (deny-by-default)。'
        .'復旧手順: payload を書くファイルなら role=write で登録し **CACHE_PAYLOAD_WRITE_INVENTORY にも**'
        .'登録する / 読み出し・削除しかしないなら role=no-payload-write / Cache::lock しか使わないなら role=lock-only。'
        .'いずれも 30 文字以上の rationale が要ります。');
});

test('検査 5: 目録が宣言した role が実測と整合する', function (): void {
    $result = cachePayloadCollectAll();

    foreach (CACHE_PAYLOAD_SURFACE_INVENTORY as $path => $entry) {
        expect(mb_strlen($entry['rationale']))->toBeGreaterThanOrEqual(30, "{$path}: rationale が短すぎます");

        $methods = array_map('strtolower', $result['surfaces'][$path] ?? []);

        $hasWrite = false;
        foreach (array_keys(CACHE_PAYLOAD_WRITE_INVENTORY) as $writeKey) {
            if (str_starts_with($writeKey, $path.'::')) {
                $hasWrite = true;
                break;
            }
        }

        expect(cachePayloadRoleViolations($entry['role'], $methods, $hasWrite))
            ->toBe([], "{$path}: 宣言した role が実測と整合しません");
    }
});

test('検査 5b: role 判定規則そのものの正負コントロール', function (): void {
    // ★実在ファイルの構成に依存せず判定規則を固定する。現状 no-payload-write の entry は 0 件なので、
    //   ここが無いと「実装を反転させても全テストが緑のまま」という穴が空く (design-review Round 4 反映)。
    expect(cachePayloadRoleViolations('write', ['get', 'forget', 'put'], true))->toBe([]);
    expect(cachePayloadRoleViolations('write', ['get'], false))->not->toBe([]);

    expect(cachePayloadRoleViolations('lock-only', ['lock'], false))->toBe([]);
    expect(cachePayloadRoleViolations('lock-only', ['lock', 'restorelock'], false))->toBe([]);
    expect(cachePayloadRoleViolations('lock-only', [], false))->not->toBe([]);
    expect(cachePayloadRoleViolations('lock-only', ['lock', 'get'], false))->not->toBe([]);
    expect(cachePayloadRoleViolations('lock-only', ['lock'], true))->not->toBe([]);

    expect(cachePayloadRoleViolations('no-payload-write', ['get'], false))->toBe([]);
    expect(cachePayloadRoleViolations('no-payload-write', ['store', 'get'], false))->toBe([]);
    expect(cachePayloadRoleViolations('no-payload-write', ['forget'], false))->toBe([]);
    expect(cachePayloadRoleViolations('no-payload-write', [], false))->not->toBe([]);
    expect(cachePayloadRoleViolations('no-payload-write', ['store'], false))->not->toBe([]);
    expect(cachePayloadRoleViolations('no-payload-write', ['lock'], false))->not->toBe([]);
    expect(cachePayloadRoleViolations('no-payload-write', ['put'], false))->not->toBe([]);
    expect(cachePayloadRoleViolations('no-payload-write', ['get'], true))->not->toBe([]);

    // driver-handoff (T215): CHAIN 分類のメソッドだけを許す。読み出し・書き込み・削除・排他が
    // 1 件でも混ざったら「解決して渡すだけ」という申告を裏切るので違反にする。
    expect(cachePayloadRoleViolations('driver-handoff', ['driver'], false))->toBe([]);
    expect(cachePayloadRoleViolations('driver-handoff', ['store', 'driver'], false))->toBe([]);
    expect(cachePayloadRoleViolations('driver-handoff', [], false))->not->toBe([]);
    expect(cachePayloadRoleViolations('driver-handoff', ['get'], false))->not->toBe([]);
    expect(cachePayloadRoleViolations('driver-handoff', ['driver', 'put'], false))->not->toBe([]);
    expect(cachePayloadRoleViolations('driver-handoff', ['driver', 'lock'], false))->not->toBe([]);
    expect(cachePayloadRoleViolations('driver-handoff', ['driver'], true))->not->toBe([]);

    expect(cachePayloadRoleViolations('unknown-role', ['get'], false))->not->toBe([]);
});

// ---------------------------------------------------------------------------
// 検査 6: serializable_classes の実行時 pin
// ---------------------------------------------------------------------------

test('検査 6: serializable_classes は実行時にも false (クラス許可一覧を持たない)', function (): void {
    // ★ここで false と null を区別することが本質。null / キー欠落だと Laravel は
    //   制限なしの unserialize() に戻る (CacheManager::serializableClasses() + 各 store)。
    expect(config('cache.serializable_classes'))->toBeFalse();

    /** @var array<string, mixed> $stores */
    $stores = config('cache.stores');
    foreach (array_keys($stores) as $store) {
        expect(config("cache.stores.{$store}.serializable_classes"))
            ->toBe(null, "store {$store} が serializable_classes を上書きしています");
    }
});

test('検査 6b: 語彙表が健全 (4 分類は互いに素 / 除外型は受け手型と重ならない)', function (): void {
    // ★同じメソッドが 2 つの分類に入ると match の順序で暗黙に勝敗が決まり、
    //   「WRITE のつもりが NON_WRITE として素通り」が静かに起きる。互いに素であることを固定する。
    $groups = [
        'WRITE' => CACHE_PAYLOAD_WRITE_METHODS,
        'NON_WRITE' => CACHE_PAYLOAD_NON_WRITE_METHODS,
        'CHAIN' => CACHE_PAYLOAD_CHAIN_METHODS,
        'TERMINAL' => CACHE_PAYLOAD_TERMINAL_METHODS,
    ];
    $all = array_merge(...array_values($groups));
    expect(count($all))->toBe(count(array_unique($all)), '同じメソッドが複数の分類に属しています');
    foreach ($groups as $name => $methods) {
        expect($methods)->toBe(array_map('strtolower', $methods), "{$name} は全小文字で書くこと");
    }

    // 明示除外した型 (Lock / RateLimiter 等) が受け手型に混ざっていないこと。
    // 混ざると Cache::lock の 9 か所が全部 fail する。
    expect(array_intersect(array_keys(CACHE_PAYLOAD_EXCLUDED_TYPES), CACHE_PAYLOAD_RECEIVER_TYPES))
        ->toBe([], '除外型が受け手型に混ざっています');
    foreach (CACHE_PAYLOAD_EXCLUDED_TYPES as $type => $reason) {
        expect(mb_strlen($reason))->toBeGreaterThanOrEqual(5, "{$type}: 除外理由を書くこと");
    }
});

// ---------------------------------------------------------------------------
// 検査 7-8: 空振り検知と自己参照コントロール
// ---------------------------------------------------------------------------

test('検査 7: 走査が空振りしていない', function (): void {
    $result = cachePayloadCollectAll();

    expect($result['files'])->toBeGreaterThan(0, '走査対象ファイルが 0 件 (ディレクトリ構成の変更を疑う)');
    expect($result['methodCalls'])->toBeGreaterThan(0, 'メソッド呼び出しを 1 件も見ていない (token 走査が死んでいる)');
    expect($result['cacheCalls'])->toBeGreaterThan(0, 'キャッシュ受け手を 1 件も解決できていない (受け手解決が死んでいる)');
    expect($result['surfaces'])->not->toBe([], 'キャッシュに触れるファイルを 1 件も検出できていない');
});

test('検査 8: 自己参照コントロール (本 gate 自身は書き込み経路にも面にも現れない)', function (): void {
    $result = cachePayloadCollectAll();
    $self = 'tests/Architecture/CachePayloadPlainDataGateTest.php';

    // fixture は nowdoc (文字列トークン) なので code として走査されない。
    // 将来ここに code としてキャッシュ呼び出しを書いたら落ちる = 正しい挙動。
    expect(array_key_exists($self, $result['surfaces']))->toBeFalse();
    expect(array_filter($result['writeSites'], fn (string $s): bool => str_starts_with($s, $self)))->toBe([]);
});

// ---------------------------------------------------------------------------
// 正負コントロール (走査ロジックの固定)
// ---------------------------------------------------------------------------

test('負のコントロール: facade / チェーン / ヘルパ / DI の書き込みを検出する', function (): void {
    $fixture = <<<'PHP'
    <?php
    namespace App\Demo;
    use Illuminate\Support\Facades\Cache;
    use Illuminate\Contracts\Cache\Repository;
    class Fixture {
        public function __construct(private readonly Repository $cache) {}
        public function run(Repository $other, $dto): void {
            Cache::put('a', [1], 60);
            Cache::add('b', 'x', 60);
            Cache::forever('c', 1);
            Cache::remember('d', 60, fn () => [1]);
            Cache::store('redis')->put('e', [1], 60);
            Cache::tags(['t'])->forever('f', [1]);
            cache()->put('g', [1], 60);
            cache(['h' => [1]], 60);
            $this->cache->put('i', [1], 60);
            $other->rememberForever('j', fn () => [1]);
        }
    }
    PHP;

    $result = cachePayloadCollectFromSource($fixture, 'fixture.php');
    expect($result['writes'])->toHaveCount(10);
    expect($result['unclassified'])->toBe([]);
    expect($result['surface'])->toBeTrue();
});

test('負のコントロール: コンテナ解決・getStore・literal 動的呼び出しの書き込みを検出する', function (): void {
    $fixture = <<<'PHP'
    <?php
    namespace App\Demo;
    use Illuminate\Contracts\Cache\Repository;
    use Illuminate\Support\Facades\Cache;
    class Fixture {
        public function run(): void {
            app(Repository::class)->put('a', [1], 60);
            resolve('cache')->forever('b', [1]);
            app('cache.store')->add('c', [1], 60);
            Cache::getStore()->put('d', [1], 60);
            Cache::{'put'}('e', [1], 60);
        }
    }
    PHP;

    $result = cachePayloadCollectFromSource($fixture, 'fixture.php');
    expect($result['writes'])->toHaveCount(5);
    expect($result['unclassified'])->toBe([]);
    expect($result['surface'])->toBeTrue();
});

test('負のコントロール: 名前空間 alias 経由の qualified name も受け手として解決する', function (): void {
    // ★`use A\B as X;` + `X\Cache::put(...)` は head を alias 展開しないと受け手から落ちる。
    //   L2 だけでなく L3 (面) からも消えるため、無申告でキャッシュ書き込みを増やせてしまう
    //   (impl-review Round 1 [Critical] 反映)。
    $fixture = <<<'PHP'
    <?php
    namespace App\Demo;
    use Illuminate\Support\Facades as Facades;
    use Illuminate\Contracts\Cache as CacheContract;
    class Fixture {
        public function __construct(private readonly CacheContract\Repository $cache) {}
        public function run(): void {
            Facades\Cache::put('a', [1], 60);
            $this->cache->forever('b', [1]);
        }
    }
    PHP;

    $result = cachePayloadCollectFromSource($fixture, 'fixture.php');
    expect($result['writes'])->toHaveCount(2);
    expect($result['unclassified'])->toBe([]);
    expect($result['surface'])->toBeTrue();
});

test('負のコントロール: DNF 型 ((A&B)|C) で宣言された受け手も解決する', function (): void {
    // ★DNF 型の `(` / `)` を跨げないと、既に role=write のファイルへこの形で書き込みを
    //   足しても L2 の件数も L3 の集合も変わらず素通りする (impl-review Round 2 [Warning] 反映)。
    $fixture = <<<'PHP'
    <?php
    namespace App\Demo;
    use Illuminate\Contracts\Cache\Repository;
    interface Marker {}
    class Fixture {
        public function write((Repository&Marker)|FallbackCache $cache): void {
            $cache->put('a', [1], 60);
        }
        public function writeReversed(FallbackCache|(Marker&Repository) $other): void {
            $other->forever('b', [1]);
        }
    }
    PHP;

    $result = cachePayloadCollectFromSource($fixture, 'fixture.php');
    expect($result['writes'])->toHaveCount(2);
    expect($result['unclassified'])->toBe([]);
    expect($result['surface'])->toBeTrue();
});

test('正のコントロール: 呼び出し / インスタンス化の引数を受け手名に登録しない', function (): void {
    // ★DNF 対応で `(` を跨ぐようにした副作用を封じる。`cache($values, 60)` や
    //   `new Repository($store)` の引数まで受け手扱いすると、無関係な `$values->put()` を
    //   キャッシュ書き込みと誤検出する (誤検出は目録を意味の無い儀式に変える)。
    $fixture = <<<'PHP'
    <?php
    namespace App\Demo;
    class Fixture {
        public function run(array $values, $store): void {
            cache($values, 60);
            $values->put('k', 'v');
            $repo = new \Illuminate\Cache\Repository($store);
            $store->put('k', 'v');
        }
    }
    PHP;

    $result = cachePayloadCollectFromSource($fixture, 'fixture.php');
    expect($result['writes'])->toBe([]);
    expect($result['unclassified'])->toHaveCount(1); // cache($values, 60) だけ
});

test('負のコントロール: app()->make(...) 経由のコンテナ解決も検出する', function (): void {
    // ★`app('cache')->put()` と違い受け手が member 名 (`make`) の側に現れる形。
    //   専用経路を持たないと L2 でも L3 でも丸ごと素通りする (impl-review Round 1 [Critical] 反映)。
    $fixture = <<<'PHP'
    <?php
    namespace App\Demo;
    use Illuminate\Contracts\Cache\Repository;
    class Fixture {
        public function run(): void {
            app()->make('cache')->put('a', [1], 60);
            app()->make(Repository::class)->forever('b', [1]);
            app()->get('cache.store')->add('c', [1], 60);
        }
    }
    PHP;

    $result = cachePayloadCollectFromSource($fixture, 'fixture.php');
    expect($result['writes'])->toHaveCount(3);
    expect($result['unclassified'])->toBe([]);
    expect($result['surface'])->toBeTrue();
});

test('負のコントロール: Cache::getFacadeRoot() の後続書き込みを検出する', function (): void {
    // ★getFacadeRoot() は facade の**実体 (CacheManager)** を返すので put が本物の書き込みになる。
    //   TERMINAL に置くと同一ファイル内で write count を増やさずに書き込みを足せてしまう
    //   (impl-review Round 1 [Warning] 反映)。
    $fixture = <<<'PHP'
    <?php
    namespace App\Demo;
    use Illuminate\Support\Facades\Cache;
    class Fixture {
        public function run(): void {
            Cache::getFacadeRoot()->put('a', [1], 60);
        }
    }
    PHP;

    $result = cachePayloadCollectFromSource($fixture, 'fixture.php');
    expect($result['writes'])->toHaveCount(1);
    expect($result['unclassified'])->toBe([]);
});

test('負のコントロール: 完全修飾のヘルパ / コンテナ呼び出しも検出する', function (): void {
    // ★`\cache(...)` は T_NAME_FULLY_QUALIFIED (text = '\cache')。先頭 `\` を落として
    //   比較しないと、ヘルパ書き込みの完全修飾形だけが素通りする (design-review Round 2 反映)。
    $fixture = <<<'PHP'
    <?php
    namespace App\Demo;
    use Illuminate\Contracts\Cache\Repository;
    class Fixture {
        public function run(array $values): void {
            \cache(['a' => [1]], 60);
            \cache($values, 60);
            \app(Repository::class)->put('b', [1], 60);
            \app('cache')->forever('c', [1]);
        }
    }
    PHP;

    $result = cachePayloadCollectFromSource($fixture, 'fixture.php');
    expect($result['writes'])->toHaveCount(3);       // \cache([...]) + \app(...)->put + \app('cache')->forever
    expect($result['unclassified'])->toHaveCount(1); // \cache($values, 60)
    expect($result['surface'])->toBeTrue();
});

test('正のコントロール: 名前空間付きの同名関数はヘルパと見なさない', function (): void {
    $fixture = <<<'PHP'
    <?php
    namespace App\Demo;
    class Fixture {
        public function run(array $values): void {
            \App\Support\cache($values, 60);
        }
    }
    PHP;

    $result = cachePayloadCollectFromSource($fixture, 'fixture.php');
    expect($result['writes'])->toBe([]);
    expect($result['unclassified'])->toBe([]);
    expect($result['surface'])->toBeFalse();
});

test('負のコントロール: 静的に判定できない形は fail させる', function (): void {
    $fixture = <<<'PHP'
    <?php
    namespace App\Demo;
    use Illuminate\Contracts\Cache\Repository;
    class Fixture {
        public function __construct(private readonly Repository $cache) {}
        public function run(array $values, string $method): void {
            cache($values, 60);
            $this->cache->{$method}('k', $values, 60);
            $this->cache->$method('k', $values, 60);
        }
    }
    PHP;

    $result = cachePayloadCollectFromSource($fixture, 'fixture.php');
    // cache($values, 60) (配列なら書き込み) / 変数動的メソッド 2 形の計 3 件
    expect($result['unclassified'])->toHaveCount(3);
    expect($result['writes'])->toBe([]);
});

test('正のコントロール: cache() の読み出し形は書き込みに数えない', function (): void {
    $fixture = <<<'PHP'
    <?php
    namespace App\Demo;
    class Fixture {
        public function run(): void {
            $a = cache('key');
            $b = cache('key', 'default');
            $c = cache()->get('key');
        }
    }
    PHP;

    $result = cachePayloadCollectFromSource($fixture, 'fixture.php');
    expect($result['writes'])->toBe([]);
    expect($result['unclassified'])->toBe([]);
    expect($result['surface'])->toBeTrue();
});

test('正のコントロール: use Cache; 形でも facade として解決する', function (): void {
    $fixture = <<<'PHP'
    <?php
    namespace App\Demo;
    use Cache;
    class Fixture {
        public function run(): void {
            Cache::put('a', [1], 60);
        }
    }
    PHP;

    $result = cachePayloadCollectFromSource($fixture, 'fixture.php');
    expect($result['writes'])->toHaveCount(1);
    expect($result['surface'])->toBeTrue();
});

test('正のコントロール: session / disk の put を巻き込まない', function (): void {
    $fixture = <<<'PHP'
    <?php
    namespace App\Demo;
    class Fixture {
        public function __construct(private readonly \Illuminate\Contracts\Session\Session $session) {}
        public function run($request): void {
            session()->put('recent_auth_at', 1);
            $this->session->put('k', 'v');
            $request->session()->put('invitation_token', 'x');
            $this->disk()->put('a/b', 'c');
        }
    }
    PHP;

    $result = cachePayloadCollectFromSource($fixture, 'fixture.php');
    expect($result['writes'])->toBe([]);
    expect($result['surface'])->toBeFalse();
    expect($result['methodCalls'])->toBeGreaterThan(0); // 走査自体は生きている
});

test('正のコントロール: Cache::lock とその後続を書き込みに数えない', function (): void {
    $fixture = <<<'PHP'
    <?php
    namespace App\Demo;
    use Illuminate\Support\Facades\Cache;
    class Fixture {
        public function run(): void {
            $lock = Cache::lock('billing:x', 10);
            $lock->get();
            $lock->release();
            Cache::lock('billing:y', 10)->block(1, fn () => 'done');
        }
    }
    PHP;

    $result = cachePayloadCollectFromSource($fixture, 'fixture.php');
    expect($result['writes'])->toBe([]);
    expect($result['unclassified'])->toBe([]);
    expect(array_map('strtolower', $result['methods']))->toBe(['lock', 'lock']);
    expect($result['surface'])->toBeTrue(); // 面としては hit する (role=lock-only で登録が要る)
});

test('正のコントロール: コメント・文字列・nowdoc 中の記述を誤検出しない', function (): void {
    $fixture = <<<'PHP'
    <?php
    namespace App\Demo;
    class Fixture {
        /** 例: Cache::put('k', $dto, 60) と書いてはいけない */
        public function run(): void {
            // Cache::forever('k', $object);
            $doc = "Cache::put('k', $v, 60)";
            $here = <<<'INNER'
            Cache::add('k', new stdClass, 60);
            INNER;
        }
    }
    PHP;

    $result = cachePayloadCollectFromSource($fixture, 'fixture.php');
    expect($result['writes'])->toBe([]);
    expect($result['surface'])->toBeFalse();
});

test('負のコントロール: 未知のキャッシュ API は未分類として検出する', function (): void {
    $fixture = <<<'PHP'
    <?php
    namespace App\Demo;
    use Illuminate\Support\Facades\Cache;
    class Fixture {
        public function run(): void {
            Cache::putEverything('k', [1]);
        }
    }
    PHP;

    $result = cachePayloadCollectFromSource($fixture, 'fixture.php');
    expect($result['unclassified'])->toHaveCount(1);
    expect($result['writes'])->toBe([]);
});

test('正のコントロール: 排他・レート制限の型は受け手にしない', function (): void {
    $fixture = <<<'PHP'
    <?php
    namespace App\Demo;
    use Illuminate\Contracts\Cache\Lock;
    use Illuminate\Cache\RateLimiter;
    class Fixture {
        public function __construct(private readonly Lock $lock, private readonly RateLimiter $limiter) {}
        public function run(): void {
            $this->lock->get();
            $this->limiter->hit('key', 60);
        }
    }
    PHP;

    $result = cachePayloadCollectFromSource($fixture, 'fixture.php');
    expect($result['writes'])->toBe([]);
    expect($result['unclassified'])->toBe([]);
    expect($result['surface'])->toBeFalse();
});
