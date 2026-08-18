<?php

declare(strict_types=1);
use Tests\Support\Cache\PlainDataGuardedRepository;

/*
 * Architecture invariant: **キャッシュに入れてよいのは素のデータだけ**
 * (配列 / 文字列 / 数値 / 真偽値 / null)。
 *
 * SoT = lctl 台帳 feature `cache-payload-plain-data` の標準形 v1 (裁定 2026-08-06) と
 * AGENTS.md セキュリティ不変条件 11 / docs/app-integration-guide.md §7 不変条件 6。
 *
 * ★2 層構成のうち**静的層**がこのファイルである (家系の裁定 AG-151 = 正典 v2)。
 *   - 静的層 (ここ) が保証するのは「**申告なしに書き込み経路を増やせない**」ことである。
 *     目録の payload 欄は**人間の申告**なので、書いた値が実際に素データかは保証しない
 *   - 実行時層 (tests/Support/Cache/PlainDataCacheGuard.php) が保証するのは
 *     「**テストが実行した書き込みの値が実際に素データである**」ことである。
 *     受け皿 (Illuminate\Cache\Repository) を包んで保管先へ渡す前の値を再帰検査するので、
 *     **直列化を一度も経由しない = テストレーンの array store でも同じように発火する**
 *   - どちらも他方を包含しない。vendor 由来の書き込みは静的層の走査根に入らず (実行時層だけが見る)、
 *     テストが 1 度も踏まない経路は実行時層に見えない (静的層だけが見る)
 *
 *   ※ 旧版のこの位置には「実行時 detector は原理的にこの穴を塞げない」という記述があったが、
 *     これは**書き込みイベントを購読する型の検出器にだけ当てはまる主張**で、
 *     受け皿を包んで値を見る型には当てはまらない。裁定 AG-151 が誤りとして棄却したので削除した。
 *
 * ★L4 (境界迂回) を**静的層だけで塞ぐ**ものがある。とくに `getStore()` は
 *   vendor 自身が正常系で呼ぶため実行時には落とせない (RateLimiter の hit/increment 経路、
 *   Repository::flushLocks() の自己呼び出し、スケジューラの排他など)。
 *   よって「保管先を直接取得して書く」形を塞ぐのは**このファイルだけ**であり、
 *   vendor が getStore() 経由で書く値は 2 層とも見えない (保証しないもの)。
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
 *   - 検査 6b: 語彙表の健全性 (5 分類が互いに素 / 除外型が受け手型に混ざっていない)
 *   - 検査 L4a-L4f (境界迂回): 受け皿を跨いで保管先へ届く / 受け皿の生成に割り込む書き方
 *     (`extend` / `getStore` / `setStore` / `tags` / `macro` / `mixin` / `flushMacros` /
 *     受け手型・保管先型・実行時層の実装クラスの直接生成 / 継承・実装の宣言) が、
 *     **通常経路 0 件 + 実行時層の自己テストの exact-fit** に収まっている
 *   - 検査 L4h: `new $class` のように**生成対象が静的に決まらない形**を走査根の全体で
 *     deny-by-default にし、キャッシュの保管先ではない既知の用途を理由付きの目録へ
 *     exact-fit で登録している (fail-closed)
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
 *   - **走査根の外で宣言され、完全修飾名が組み込み Store の命名規則に一致しない第三者の
 *     `Store` 実装**の直接生成・コンテナ束縛経由の取得 (`cachePayloadIsStoreType()` の限界)
 *   - **`new` を経由しない取得** (コンテナ束縛・factory・vendor 内部からの受け取り)。
 *     L4h が塞ぐのは `new` で生成する形だけである
 *   - **継承・実装の宣言のうち、名前として書かれていない形**。PHP は extends / implements に
 *     名前しか書けないため合法な未解決形は無いが、走査は字句判定なので
 *     名前以外の token が現れたら**未分類として落とす** (負例は合成入力で固定する)。
 *     名前の解決は取り込み表 → 現在の名前空間の順で行い、完全修飾名で突き合わせる
 *     (`namespace\Foo` の相対参照も解決する)
 *   - **動的生成の目録 (L4h) の `rationale` が正しいこと**。「何を生成しているか」は
 *     人間の申告であり、機械は件数の exact-fit しか見ない (L2 の `payload` 欄と同じ扱い)。
 *     同じファイルの中で許可済みの生成をキャッシュの保管先の生成へ置き換えると、
 *     件数が変わらない限り検出できない
 *   - **受け手名として解決できない変数**への添字代入 (`$c['k'] = $v` の `$c` が型宣言を持たない形)。
 *     既存の受け手解決の限界と同じ
 *   - **docblock だけで型付けされた受け手** (`@var Repository $c` の docblock を書いた直後に
 *     `$c->put(...)` する形)。型宣言 (引数 / プロパティ / promoted ctor param) のみを見る。
 *     ※同じファイルに対応する型の `use` があれば **L3 (面) には現れる**が、
 *       完全修飾 docblock だけで import も型宣言も無い形は **L3 でも捕まらない**。
 *       docblock 解析は行わない (実測 0 件)
 *   - **`use function` / `use const` の取り込み**は名前解決の表に入れない (クラス参照ではない)
 *   - **1 ファイルに複数の名前空間がある形**は解決せず**未分類として落とす**
 *     (取り込み表を名前空間ごとに持ち分けない限り、別の名前空間の同名の別名で上書きできるため)
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
    'flexible', 'putmany', 'set', 'setmultiple', 'rememberwithwarmth', 'offsetset',
];

/**
 * payload を書き込まない API (increment/decrement は整数のみ書けるため素データが構造的に保証される)。
 *
 * `hasmacro` は macro 登録簿の**読み出し**であり、登録も呼び出しもしない
 * (登録側の `macro` / `mixin` / `flushmacros` は BYPASS)。
 *
 * @var list<string>
 */
const CACHE_PAYLOAD_NON_WRITE_METHODS = [
    'get', 'many', 'getmultiple', 'has', 'missing', 'pull', 'forget', 'delete',
    'deletemultiple', 'flush', 'clear', 'increment', 'decrement',
    'supportstags', 'getprefix', 'getdefaultdriver', 'setdefaultdriver',
    'forgetdriver', 'purge', 'itemkey', 'refresheventdispatcher', 'hasmacro',
];

/**
 * 受け手を保ったまま連鎖する API。
 *
 * `getStore()` / `tags()` はここに**置かない** — どちらも受け皿 (Repository) を跨いで
 * 保管先へ届くので BYPASS である (L4)。辿って書き込みを数えるのではなく、
 * 書き方そのものを 0 件で pin する。
 *
 * @var list<string>
 */
const CACHE_PAYLOAD_CHAIN_METHODS = ['store', 'driver', 'resolve', 'getfacaderoot'];

/**
 * 受け皿 (Repository) を跨いで保管先 (Store) へ届く / 受け皿の生成そのものに割り込む API。
 * **通常経路は 0 件**で、実行時層の自己テストだけを名指しの目録へ exact-fit で登録する
 * (家系の裁定 AG-151 の v2 要素 4「境界迂回の hard fail」)。
 *
 * - extend    独自 creator は CacheManager::repository() を通らないので実行時層の被覆から抜ける
 *             (通らないことは tests/Feature/Cache/CachePayloadPlainDataGuardTest.php が実証する)。
 *             判定は**通常経路 0 件 + GuardedBoundaryProbe の自己テストの exact-fit**である
 * - getStore / setStore  保管先を直接触る = 受け皿を跨ぐ。`getStore()` は vendor 自身が
 *             正常系で呼ぶため**実行時には落とせない** = ここが唯一の防壁である
 * - tags      vendor の tags() は new TaggedCache(...) を素で生成するので guard が効かない。
 *             加えて本番の database store は supportsTags() が false でタグ非対応
 * - macro / mixin / flushMacros  Repository は Macroable を use しており、
 *             macro 内から $this->store へ直接到達できる (末端 4 メソッドを通らない)
 *
 * @var list<string>
 */
const CACHE_PAYLOAD_BYPASS_METHODS = [
    'extend', 'getstore', 'setstore', 'tags', 'macro', 'mixin', 'flushmacros',
];

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
 * L4: 境界迂回の**自己テスト**の目録 (exact-fit)。
 *
 * key   = `{相対パス}::{メソッド名 (全小文字)}` / `{相対パス}::new {完全修飾名}`
 *         ★**完全修飾名で突き合わせる** (AGENTS.md 走査規約 (a))。短名では別名つき取り込みや
 *           同名の別クラスを区別できない
 * count = 出現回数 (完全一致。1 件増えたら必ず落ちる)
 * rationale = 30 文字以上の具体的根拠
 *
 * ★登録できるのは **tests/Support/Cache/GuardedBoundaryProbe.php の 1 ファイルだけ**である
 *   (検査 L4f が名指しで固定する)。「tests/Support/Cache/ 配下すべて」にはしない —
 *   将来足した任意の補助ファイルが自己テストを名乗れてしまうため。
 * ★**動的呼び出しで走査を避ける形は採らない** (検出力の裏取りが弱くなるため)。
 * ★本目録に載せた呼び出しは**検査 1 (未分類 API の deny-by-default) の母集団からも除く**。
 *   実行時層は保管先への素通し (`__call`) を落とすので、その自己テストは
 *   「4 分類のどれでもない API 名」を意図的に呼ぶことになるためである。
 *   目録に載っていない未知 API は従来どおり落ちる。
 *
 * @var array<string, array{count: int, rationale: string}>
 */
const CACHE_PAYLOAD_BOUNDARY_SELFTEST_INVENTORY = [
    'tests/Support/Cache/GuardedBoundaryProbe.php::extend' => [
        'count' => 1,
        'rationale' => '独自 driver の creator が CacheManager::repository() を通らないことを実証する trip-wire。通らなくなったら L4 の根拠が変わる',
    ],
    'tests/Support/Cache/GuardedBoundaryProbe.php::flushmacros' => [
        'count' => 1,
        'rationale' => 'callMacro の finally で必ず登録を消すための 1 件。消さないと global afterEach の macro pin が二重に落ちる',
    ],
    'tests/Support/Cache/GuardedBoundaryProbe.php::guardprobemacro' => [
        'count' => 1,
        'rationale' => '登録した macro を実際に呼ぶ 1 件。実行時層の __call() が macro を使用時点で落とすことの負例になる',
    ],
    'tests/Support/Cache/GuardedBoundaryProbe.php::guardprobeunknownmethod' => [
        'count' => 1,
        'rationale' => 'macro でない未知メソッド (保管先への素通し) を呼ぶ 1 件。名指しで分類していない素通しが落ちることの負例になる',
    ],
    'tests/Support/Cache/GuardedBoundaryProbe.php::macro' => [
        'count' => 2,
        'rationale' => 'macro 経由の到達が使用時点で落ちること (callMacro) と、残存 macro を flush が検出すること (registerMacroWithoutUsing) の 2 件',
    ],
    'tests/Support/Cache/GuardedBoundaryProbe.php::new Illuminate\Cache\ArrayStore' => [
        'count' => 2,
        'rationale' => 'setStore の引数と独自 creator の保管先として使う。保管先の直接生成が検出されることの自己確認も兼ねる',
    ],
    'tests/Support/Cache/GuardedBoundaryProbe.php::new Illuminate\Cache\Repository' => [
        'count' => 1,
        'rationale' => '独自 creator が返す素の受け皿。guard を通らない受け皿が実際に作れてしまうことを実証するために必要な 1 件',
    ],
    'tests/Support/Cache/GuardedBoundaryProbe.php::setstore' => [
        'count' => 1,
        'rationale' => '受け皿の保管先を差し替える口が境界迂回として落ちることを固定する。落ちなくなると guard 付き受け皿の中身を入れ替えられる',
    ],
    'tests/Support/Cache/GuardedBoundaryProbe.php::tags' => [
        'count' => 1,
        'rationale' => 'guard 付き受け皿の tags() が境界迂回として落ちることを固定する。落ちなくなると TaggedCache 経由の書き込みが素通りする',
    ],
];

/** L4 の自己テストを置いてよい唯一のファイル (相対パス)。 */
const CACHE_PAYLOAD_BOUNDARY_SELFTEST_FILE = 'tests/Support/Cache/GuardedBoundaryProbe.php';

/**
 * 実行時層の実装クラス。**生成は許すが継承は許さない**。
 *
 * ★これらを継承すると、末端 4 メソッドを override し直して `getStore()` 経由で
 *   保管先へ直接書ける (`class X extends PlainDataGuardedRepository { public function put(…) {
 *   return $this->getStore()->put(…); } }`)。受け手型にも保管先型の命名規則にも一致しないので、
 *   継承検査に足さないと L4d をすり抜ける。
 *
 * @var list<string>
 */
const CACHE_PAYLOAD_GUARD_IMPLEMENTATION_TYPES = [
    'Tests\Support\Cache\PlainDataGuardedRepository',
    'Tests\Support\Cache\PlainDataGuardedCacheManager',
];

/**
 * L4b の fail-closed: **キャッシュの保管先ではない**と申告する動的生成の目録 (exact-fit)。
 *
 * `new $class` / `new ($expr)` は生成されるクラスが静的に決まらないため、
 * 受け皿・保管先の直接生成を隠せてしまう
 * (`$c = ArrayStore::class; $s = new $c; $s->put(…)` は受け手型の宣言も持たないので L2 にも現れない)。
 * よって走査根の全体で deny-by-default にし、既知の非キャッシュ用途をここへ登録する。
 *
 * count = 出現回数 (完全一致。1 件増えたら必ず落ちる)
 * rationale = 30 文字以上の具体的根拠 (**何を生成しているか**を書く)
 *
 * ★`Factory::new()` / `->new()` は**メソッド名**であって生成ではないので母集団に入れない。
 *
 * @var array<string, array{count: int, rationale: string}>
 */
const CACHE_PAYLOAD_DYNAMIC_NEW_INVENTORY = [
    'app/Enums/Billing/BillingRetentionTarget.php' => [
        'count' => 1,
        'rationale' => '保持期限の対象 Eloquent モデルを生成して getTable() で表名を得るだけ。生成するのはモデルであって保管先ではない',
    ],
    'tests/Architecture/MassAssignmentSafetyTest.php' => [
        'count' => 2,
        'rationale' => '全 Eloquent モデルを順に生成して getFillable() / getGuarded() を読む走査。生成するのはモデルであって保管先ではない',
    ],
    'tests/Architecture/RouteBindingTypeConstraintInventoryTest.php' => [
        'count' => 1,
        'rationale' => 'route binding が宣言した型を生成して Eloquent Model かどうかと主キーの型区分を確かめる。生成するのはモデルであって保管先ではない',
    ],
    'tests/Feature/InitialState/NullInitialStateColumnClassificationTest.php' => [
        'count' => 1,
        'rationale' => '実スキーマと突き合わせるため Eloquent モデルを生成して cast 宣言を読む。生成するのはモデルであって保管先ではない',
    ],
];

/**
 * L4d: 受け手型 / 保管先型の**継承・実装の宣言**を許す名指しの目録 (exact-fit)。
 *
 * key = `{相対パス}::{extends|implements} {完全修飾名}`。
 * 任意の Repository サブクラスを作れば `new` の検出を逃れられるので、**宣言側で塞ぐ**。
 *
 * @var array<string, string>
 */
const CACHE_PAYLOAD_SUBCLASS_INVENTORY = [
    'tests/Support/Cache/PlainDataGuardedRepository.php::extends Illuminate\Cache\Repository' => '実行時層の受け皿そのもの。値の末端 4 メソッドを override するには継承以外の手段が無い',
    'tests/Support/Cache/PlainDataGuardedCacheManager.php::extends Illuminate\Cache\CacheManager' => '実行時層の manager そのもの。repository() を override して guard 付き受け皿を返すために継承する',
];

/**
 * 保管先 (Store) の型かどうかの判定規則。
 *
 * 解決した完全修飾名が
 *   (a) `Illuminate\Contracts\Cache\Store` である、または
 *   (b) `Illuminate\Cache\` で始まり `Store` で終わる (ArrayStore / DatabaseStore / FileStore /
 *       RedisStore / NullStore / MemoizedStore / StorageStore / FailoverStore …)
 * のとき保管先の型とみなす。
 *
 * ★**保証しないもの**: **走査根の外で宣言され、完全修飾名が組み込み Store の命名規則に
 *   一致しない第三者の Store 実装**の直接生成・解決は検出しない
 *   (例: `new Vendor\Package\CacheBackend()` が vendor 内で Store を実装している形)。
 *   `Cache::extend()` の pin は **CacheManager 経由で第三者 Store の面を増やす経路**を閉じるが、
 *   **走査根の外の第三者 Store を直接生成する / 独自のコンテナ束縛で取得する経路までは
 *   保証しない** (「唯一の登録口」とは書かない)。
 *   規則そのものの正負は検査 L4e が固定する。
 */
function cachePayloadIsStoreType(string $fqcn): bool
{
    if ($fqcn === 'Illuminate\Contracts\Cache\Store') {
        return true;
    }

    return str_starts_with($fqcn, 'Illuminate\Cache\\') && str_ends_with($fqcn, 'Store');
}

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
 * kind  = 'plain'          …素データを入れる本来の経路。proof は**配列往復を固定する単体テスト**
 *         'guard-selftest' …実行時層が違反を検出することを固定するための意図的な違反。
 *                            proof は**その検出を固定する振る舞い検査**
 *
 * 経路が 1 本しかない現状では専用 enum (app/Enums/Security/) + inventory クラス
 * (tests/Support/Security/) へ昇格させない (AGENTS.md 思考原則 2「今必要なものだけ作る」)。
 *
 * @var array<string, array{kind: string, count: int, payload: string, proof: string, rationale: string}>
 */
const CACHE_PAYLOAD_WRITE_INVENTORY = [
    'app/Services/FxRateService.php::put' => [
        'kind' => 'plain',
        'count' => 1,
        'payload' => 'FxSnapshotDto::toArray() の連想配列 (float 1 / string 3)。オブジェクトは渡さない',
        'proof' => 'tests/Unit/DataTransferObjects/FxSnapshotDtoTest.php',
        'rationale' => '当日の USD/JPY レートを 1 日 cache する。読み戻しは is_array 検査 + FxSnapshotDto::fromArray() + 失敗時 Cache::forget() で標準形どおり',
    ],
    'app/Services/Mail/Sns/SnsCertificateFetcher.php::put' => [
        'kind' => 'plain',
        'count' => 1,
        'payload' => 'SNS 署名検証用の証明書 (PEM) の素の文字列。オブジェクトは渡さない',
        'proof' => 'tests/Feature/Mail/SnsCertificateFetcherTest.php',
        'rationale' => '署名検証が通った証明書だけを URL の sha256 をキーにして寿命つきで保存する。読み戻しは is_string + 非空 + PEM として読めることを検査し、失敗したら Cache::forget して miss 扱いにする',
    ],
    'tests/Feature/Cache/CachePayloadPlainDataGuardTest.php::add' => [
        'kind' => 'guard-selftest',
        'count' => 1,
        'payload' => '意図的な違反値 (stdClass) と素データの両方。add() が末端として検査されることを固定する',
        'proof' => 'tests/Feature/Cache/CachePayloadPlainDataGuardTest.php',
        'rationale' => '値の末端 4 メソッドのうち add が保管前に検査されることを実 API 経由で固定する。ここが無いと申告の裏取りが機械化されない',
    ],
    'tests/Feature/Cache/CachePayloadPlainDataGuardTest.php::flexible' => [
        'kind' => 'guard-selftest',
        'count' => 1,
        'payload' => '意図的な違反値 (stdClass)。flexible が putMany へ合流することの実証に使う',
        'proof' => 'tests/Feature/Cache/CachePayloadPlainDataGuardTest.php',
        'rationale' => '糖衣 API の合流が将来変わったら guard の被覆が静かに減るため、実 API 経由で合流を固定する 1 件',
    ],
    'tests/Feature/Cache/CachePayloadPlainDataGuardTest.php::forever' => [
        'kind' => 'guard-selftest',
        'count' => 1,
        'payload' => '意図的な違反値 (stdClass) と素データの両方。forever が末端として検査されることを固定する',
        'proof' => 'tests/Feature/Cache/CachePayloadPlainDataGuardTest.php',
        'rationale' => '値の末端 4 メソッドのうち forever が保管前に検査されることを実 API 経由で固定する 1 件',
    ],
    'tests/Feature/Cache/CachePayloadPlainDataGuardTest.php::offsetset' => [
        'kind' => 'guard-selftest',
        'count' => 2,
        'payload' => '意図的な違反値 (stdClass) と素データの両方。$cache[$k] = $v と $cache[$k] ??= $v の 2 形',
        'proof' => 'tests/Feature/Cache/CachePayloadPlainDataGuardTest.php',
        'rationale' => 'ArrayAccess 書き込みが put へ合流することを実 API 経由で固定する 2 件。静的層の添字代入検出とも対応する',
    ],
    'tests/Feature/Cache/CachePayloadPlainDataGuardTest.php::put' => [
        'kind' => 'guard-selftest',
        'count' => 2,
        'payload' => '意図的な違反値 (stdClass / Closure 等) と素データの両方。通常形と配列キー形 (putMany 相当) の 2 件',
        'proof' => 'tests/Feature/Cache/CachePayloadPlainDataGuardTest.php',
        'rationale' => '実行時層が「保管前の値を再帰検査して落とす」ことを実 API 経由で固定する唯一の場所。ここが無いと申告の裏取りが機械化されない',
    ],
    'tests/Feature/Cache/CachePayloadPlainDataGuardTest.php::putmany' => [
        'kind' => 'guard-selftest',
        'count' => 1,
        'payload' => '意図的な違反値 (stdClass) を含む連想配列。putMany が末端として検査されることを固定する',
        'proof' => 'tests/Feature/Cache/CachePayloadPlainDataGuardTest.php',
        'rationale' => '値の末端 4 メソッドのうち putMany が保管前に検査されることを実 API 経由で固定する 1 件',
    ],
    'tests/Feature/Cache/CachePayloadPlainDataGuardTest.php::remember' => [
        'kind' => 'guard-selftest',
        'count' => 1,
        'payload' => '意図的な違反値 (stdClass)。remember が rememberWithWarmth 経由で put へ合流することの実証に使う',
        'proof' => 'tests/Feature/Cache/CachePayloadPlainDataGuardTest.php',
        'rationale' => '糖衣 API の合流が将来変わったら guard の被覆が静かに減るため、実 API 経由で合流を固定する 1 件',
    ],
    'tests/Feature/Cache/CachePayloadPlainDataGuardTest.php::rememberforever' => [
        'kind' => 'guard-selftest',
        'count' => 1,
        'payload' => '意図的な違反値 (stdClass)。rememberForever が forever へ合流することの実証に使う',
        'proof' => 'tests/Feature/Cache/CachePayloadPlainDataGuardTest.php',
        'rationale' => '糖衣 API の合流が将来変わったら guard の被覆が静かに減るため、実 API 経由で合流を固定する 1 件',
    ],
    'tests/Feature/Cache/CachePayloadPlainDataGuardTest.php::rememberwithwarmth' => [
        'kind' => 'guard-selftest',
        'count' => 1,
        'payload' => '意図的な違反値 (stdClass)。rememberWithWarmth が put へ合流することの実証に使う',
        'proof' => 'tests/Feature/Cache/CachePayloadPlainDataGuardTest.php',
        'rationale' => '糖衣 API の合流が将来変わったら guard の被覆が静かに減るため、実 API 経由で合流を固定する 1 件',
    ],
    'tests/Feature/Cache/CachePayloadPlainDataGuardTest.php::sear' => [
        'kind' => 'guard-selftest',
        'count' => 1,
        'payload' => '意図的な違反値 (stdClass)。sear が rememberForever 経由で forever へ合流することの実証に使う',
        'proof' => 'tests/Feature/Cache/CachePayloadPlainDataGuardTest.php',
        'rationale' => '糖衣 API の合流が将来変わったら guard の被覆が静かに減るため、実 API 経由で合流を固定する 1 件',
    ],
    'tests/Feature/Cache/CachePayloadPlainDataGuardTest.php::set' => [
        'kind' => 'guard-selftest',
        'count' => 1,
        'payload' => '意図的な違反値 (stdClass)。PSR-16 の set が put へ合流することの実証に使う',
        'proof' => 'tests/Feature/Cache/CachePayloadPlainDataGuardTest.php',
        'rationale' => '糖衣 API の合流が将来変わったら guard の被覆が静かに減るため、実 API 経由で合流を固定する 1 件',
    ],
    'tests/Feature/Cache/CachePayloadPlainDataGuardTest.php::setmultiple' => [
        'kind' => 'guard-selftest',
        'count' => 1,
        'payload' => '意図的な違反値 (stdClass)。PSR-16 の setMultiple が putMany へ合流することの実証に使う',
        'proof' => 'tests/Feature/Cache/CachePayloadPlainDataGuardTest.php',
        'rationale' => '糖衣 API の合流が将来変わったら guard の被覆が静かに減るため、実 API 経由で合流を固定する 1 件',
    ],
    'tests/Feature/Mail/SnsCertificateFetcherTest.php::put' => [
        'kind' => 'plain',
        'count' => 7,
        'payload' => '証明書 PEM の素の文字列と、読み戻せない値 (PEM でない素の文字列) を仕込む 7 件。どちらも文字列でオブジェクトは渡さない',
        'proof' => 'tests/Feature/Mail/SnsCertificateFetcherTest.php',
        'rationale' => 'キャッシュ命中・読み戻し不能・寿命・キー分離の振る舞いを実 API 経由で固定するため、テスト自身が素の文字列を仕込む。取得口の読み戻し検査と forget の往復もここで固定する',
    ],
    'tests/Support/Cache/BootTimeCacheWriteProbeProvider.php::put' => [
        'kind' => 'guard-selftest',
        'count' => 1,
        'payload' => '起動中に意図的に入れるオブジェクト (stdClass)。provider 自身が例外を握り潰す',
        'proof' => 'tests/Feature/Cache/CachePayloadPlainDataGuardTest.php',
        'rationale' => '起動 (bootstrap) 中の書き込みも guard が捕まえることを固定するための見本。結線点が beforeEach へ後退したら赤くなる',
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
 *       いずれも行わず他のコンポーネントへそのまま渡す (T215: キューワーカーへの cache 注入が該当) /
 *       guard-implementation = 実行時層の実装そのもの。受け手型を**参照するだけ**で
 *       キャッシュ API は 1 件も呼ばない (tests/Support/Cache/ 配下でだけ名乗れる) /
 *       boundary-selftest = 境界迂回が hard fail することを固定する唯一の呼び出し元
 *       (CACHE_PAYLOAD_BOUNDARY_SELFTEST_FILE ちょうどでだけ名乗れる)
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
    'app/Services/Mail/Sns/SnsCertificateFetcher.php' => [
        'role' => 'write',
        'rationale' => 'SNS 証明書の取得口。get / put / forget と Cache::lock を持つ唯一のファイルで、payload は PEM の素の文字列だけである',
    ],
    'tests/Feature/Billing/ReconcileSubscriptionStatusTest.php' => [
        'role' => 'lock-only',
        'rationale' => '突き合わせコマンドの多重起動を再現するため Cache::lock を先取するのみ。payload は書かない',
    ],
    'tests/Feature/Cache/CachePayloadPlainDataGuardTest.php' => [
        'role' => 'write',
        'rationale' => '実行時層の振る舞い検査。意図的に違反する値を書いて guard が落とすことを固定する唯一のファイル',
    ],
    'tests/Feature/Mail/SnsCertificateFetcherTest.php' => [
        'role' => 'write',
        'rationale' => 'キャッシュ命中・読み戻し不能・ロック競合・保管方式の障害を再現するため、素の文字列の put と Cache::lock を直接使う取得口の振る舞い検査',
    ],
    'tests/Feature/Queue/DeferredRetryHorizonTest.php' => [
        'role' => 'driver-handoff',
        'rationale' => 'Worker::setCache() へ渡すため app(\'cache\')->driver() で driver を解決するだけで、読み出し・書き込み・削除のいずれも行わない。未処理例外の計数は framework 側が整数で行う',
    ],
    'tests/Pest.php' => [
        'role' => 'no-payload-write',
        'rationale' => 'SNS 証明書テスト用にキャッシュの既定をテスト専用 array store へ向け直す共用ヘルパで forgetDriver を呼ぶだけ。payload は書かない',
    ],
    'tests/Support/Cache/BootTimeCacheWriteProbeProvider.php' => [
        'role' => 'write',
        'rationale' => '起動中の書き込みを guard が捕まえることを固定する見本 provider。boot() で意図的にオブジェクトを入れる',
    ],
    'tests/Support/Cache/GuardedBoundaryProbe.php' => [
        'role' => 'boundary-selftest',
        'rationale' => '境界迂回が hard fail することを固定する唯一の呼び出し元。L4 の自己テスト目録に登録できるのはこのファイルだけ',
    ],
    'tests/Support/Cache/PlainDataCacheGuard.php' => [
        'role' => 'guard-implementation',
        'rationale' => '実行時層の結線と accumulator。Repository::$macros の pin のために Repository を参照するだけで API は呼ばない',
    ],
    'tests/Support/Cache/PlainDataGuardedCacheManager.php' => [
        'role' => 'guard-implementation',
        'rationale' => '実行時層の manager。Store 型を参照してよい唯一のサイトで、repository() を override して受け皿を差し替える',
    ],
    'tests/Support/Cache/PlainDataGuardedRepository.php' => [
        'role' => 'guard-implementation',
        'rationale' => '実行時層の受け皿。Illuminate\Cache\Repository を継承して末端 4 メソッドを検査する。キャッシュ API 呼び出しは持たない',
    ],
];

/**
 * L4c: guard 付き manager が保管先 (`$store`) を受け皿の第 1 引数以外へ流していないか。
 *
 * `$store` の出現は次の 2 か所ちょうどでなければならない (純関数。合成入力にも当てられる)。
 *   (1) `Store $store` の型宣言の直後
 *   (2) `new PlainDataGuardedRepository($store, …)` の**第 1 引数**
 *
 * ★(2) は「直前が `(`」だけでは足りない — 任意の関数呼び出しの第 1 引数でも通ってしまう。
 *   `new` + 受け皿クラス名 + `(` の直後であることまで確認する。
 *
 * @return list<string> 違反理由。空なら整合
 */
function cachePayloadStoreLeakViolations(string $source): array
{
    /** @var list<PhpToken> $tokens */
    $tokens = PhpToken::tokenize($source);

    $occurrences = [];
    $count = count($tokens);
    for ($i = 0; $i < $count; $i++) {
        if (! $tokens[$i]->is(T_VARIABLE) || $tokens[$i]->text !== '$store') {
            continue;
        }

        $prev = cachePayloadPrev($tokens, $i - 1);
        if ($prev !== null && $tokens[$prev]->text === 'Store') {
            $occurrences[] = 'declaration';

            continue;
        }

        // `new PlainDataGuardedRepository(` の直後 = 第 1 引数
        $open = $prev;
        $class = $open === null ? null : cachePayloadPrev($tokens, $open - 1);
        $new = $class === null ? null : cachePayloadPrev($tokens, $class - 1);
        $isFirstConstructorArgument = $open !== null && $tokens[$open]->text === '('
            && $class !== null && $tokens[$class]->text === 'PlainDataGuardedRepository'
            && $new !== null && $tokens[$new]->is(T_NEW);

        $occurrences[] = $isFirstConstructorArgument
            ? 'repository-first-argument'
            : "leak@line{$tokens[$i]->line}";
    }

    if ($occurrences !== ['declaration', 'repository-first-argument']) {
        return ['$store の出現が期待と一致しません: '.implode(' / ', $occurrences)];
    }

    return [];
}

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
 * `[` の対応する `]` の index。
 *
 * @param  list<PhpToken>  $tokens
 */
function cachePayloadMatchingBracket(array $tokens, int $open): ?int
{
    $depth = 0;
    $count = count($tokens);
    for ($i = $open; $i < $count; $i++) {
        if ($tokens[$i]->text === '[') {
            $depth++;
        } elseif ($tokens[$i]->text === ']') {
            $depth--;
            if ($depth === 0) {
                return $i;
            }
        }
    }

    return null;
}

/**
 * `extends A` / `implements A, B` の宣言句を読み、カンマ区切りの各名前を解決して返す。
 *
 * ★直前 token だけを見る形では不十分 — `class X implements SomeInterface, Store {}` の
 *   `Store` の直前は `,` である。そこで T_EXTENDS / T_IMPLEMENTS を見つけたら
 *   **宣言句全体 (`{` まで)** を読む。**解決できない名前は候補から外さず `null` で返す**
 *   (未解決を落とす = AGENTS.md 走査規約 (b))。
 *
 * @param  list<PhpToken>  $tokens
 * @param  array<string, string>  $useMap
 * @return list<array{keyword: string, resolved: string|null, line: int}>
 */
function cachePayloadInheritanceClause(array $tokens, int $keywordIndex, array $useMap, string $namespace = ''): array
{
    $keyword = strtolower($tokens[$keywordIndex]->text);
    $declared = [];
    $count = count($tokens);

    for ($i = $keywordIndex + 1; $i < $count; $i++) {
        $token = $tokens[$i];
        if ($token->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
            continue;
        }
        if ($token->text === '{' || $token->text === ';') {
            break;
        }
        if ($token->text === ',') {
            continue;
        }
        if ($token->is(T_IMPLEMENTS)) {
            // `class X extends A implements B` の切り替え。implements 側は
            // T_IMPLEMENTS を起点とする別の呼び出しが読むので、ここでは打ち切る (二重記録の防止)。
            break;
        }
        if ($token->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE])) {
            $declared[] = [
                'keyword' => $keyword,
                'resolved' => cachePayloadResolveName($token->text, $useMap, $namespace),
                'line' => $token->line,
            ];

            continue;
        }

        // 予期しない token (可変長の型構文など)。解決できない形として落とす。
        $declared[] = ['keyword' => $keyword, 'resolved' => null, 'line' => $token->line];
    }

    return $declared;
}

/**
 * `use A\B\C;` / `use A\B\C as D;` / `use A\B\{C, D as E};` から alias => FQCN の表を作る。
 *
 * ★グループ use も解決する。**この走査器自身が完全修飾名へ解決できること**が要る
 *   (AGENTS.md 走査規約 (a))。「別の gate がグループ use を禁じているから」に依存すると、
 *   本 gate 単体では fail-closed にならない。
 *
 * ★読むのは**名前空間スコープの取り込みだけ**である。波括弧の深さを追い、
 *   名前空間の直下にある `use` だけを集める。型宣言の本体に入った後の `use` は
 *   trait の取り込みであり、同名の取り込みを上書きすると名前解決が壊れる
 *   (`use X as Guarded;` の後で `class T { use \Other\Guarded; }` があると、
 *   `class Bypass extends Guarded {}` が別クラスへ解決されて継承禁止をすり抜ける)。
 *   **「最初の型宣言で打ち切る」形は誤り**である — PHP は型宣言の**後ろ**にも
 *   名前空間スコープの取り込みを置けるので、後置の取り込みを丸ごと落としてしまう。
 *
 * @param  list<PhpToken>  $tokens
 * @return array<string, string>
 */
function cachePayloadUseMap(array $tokens): array
{
    $map = [];
    $count = count($tokens);
    $baseDepth = cachePayloadNamespaceBodyDepth($tokens);
    $depth = 0;

    for ($i = 0; $i < $count; $i++) {
        if ($tokens[$i]->text === '{') {
            $depth++;

            continue;
        }
        if ($tokens[$i]->text === '}') {
            $depth--;

            continue;
        }
        if (! $tokens[$i]->is(T_USE) || $depth !== $baseDepth) {
            continue;
        }

        // ★`use function Foo\bar;` / `use const Foo\BAZ;` は**クラスの取り込みではない**。
        //   文ごと読み飛ばす (末尾の名前を alias として登録すると、同名のクラス取り込みを
        //   上書きして名前解決を壊す)。
        $head = cachePayloadNext($tokens, $i + 1);
        if ($head !== null && $tokens[$head]->is([T_FUNCTION, T_CONST])) {
            continue;
        }

        $prefix = '';
        $pending = null;
        $skipMember = false;

        for ($j = $i + 1; $j < $count; $j++) {
            $token = $tokens[$j];
            if ($token->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
                continue;
            }
            if ($token->text === ';' || $token->text === '(') {
                break; // 文の終わり / closure の use(...)
            }
            if ($token->is([T_FUNCTION, T_CONST])) {
                // グループ use の中の `function foo` / `const BAR` (混在指定)
                $skipMember = true;
                $pending = null;

                continue;
            }
            if ($skipMember && $token->text !== ',' && $token->text !== '}') {
                continue;
            }
            if ($token->text === '{') {
                // グループ use の開始。直前の名前が接頭辞になる
                $prefix = $pending === null ? '' : rtrim($pending, '\\').'\\';
                $pending = null;

                continue;
            }
            if ($token->text === '}' || $token->text === ',') {
                if ($pending !== null && ! $skipMember) {
                    $fqcn = $prefix.$pending;
                    $map[str_contains($fqcn, '\\') ? substr((string) strrchr($fqcn, '\\'), 1) : $fqcn] = $fqcn;
                }
                $pending = null;
                $skipMember = false;
                if ($token->text === '}') {
                    break;
                }

                continue;
            }
            if ($token->is(T_AS)) {
                $aliasIndex = cachePayloadNext($tokens, $j + 1);
                if ($aliasIndex !== null && $tokens[$aliasIndex]->is(T_STRING) && $pending !== null) {
                    $map[$tokens[$aliasIndex]->text] = $prefix.$pending;
                    $pending = null;
                    $j = $aliasIndex;
                }

                continue;
            }
            if ($token->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
                $pending = ltrim($token->text, '\\');

                continue;
            }
            if ($token->is(T_NS_SEPARATOR)) {
                continue; // グループ use の `A\B\{` の区切り
            }

            // `use function foo;` / `use const BAR;` など。名前として扱わない
            $pending = null;
        }

        if ($pending !== null && ! $skipMember) {
            $fqcn = $prefix.$pending;
            $map[str_contains($fqcn, '\\') ? substr((string) strrchr($fqcn, '\\'), 1) : $fqcn] = $fqcn;
        }
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
function cachePayloadResolveName(string $raw, array $useMap, string $namespace = ''): string
{
    $isFullyQualified = str_starts_with($raw, '\\');
    $name = ltrim($raw, '\\');

    // `namespace\Foo` (T_NAME_RELATIVE) は現在の名前空間からの相対指定である
    if (! $isFullyQualified && str_starts_with(strtolower($name), 'namespace\\')) {
        $rest = substr($name, strlen('namespace\\'));

        return $namespace === '' ? $rest : $namespace.'\\'.$rest;
    }

    if (isset($useMap[$name])) {
        $resolved = $useMap[$name];

        // `use Cache;` (root 名前空間の class alias の取り込み) も facade とみなす
        return strtolower($resolved) === 'cache' ? 'Illuminate\Support\Facades\Cache' : $resolved;
    }

    if (str_contains($name, '\\')) {
        $head = strstr($name, '\\', true);
        if (is_string($head) && isset($useMap[$head])) {
            return $useMap[$head].substr($name, strlen($head));
        }
    }

    // 名前空間を持たない `Cache` は class alias 経由の facade (`use Cache;` を含む)。
    // ★これは**安全側への過剰検出**である (PHP はクラス名を global へ落とさないので、
    //   名前空間の中の裸の `Cache` は本来 `<現在の名前空間>\Cache` を指す)。
    if (! str_contains($name, '\\') && strtolower($name) === 'cache') {
        return 'Illuminate\Support\Facades\Cache';
    }

    // ★取り込みにも無い非完全修飾名は**現在の名前空間からの相対**である。
    //   ここを飛ばすと `namespace Tests\Support\Cache; class X extends PlainDataGuardedRepository {}`
    //   のような**同一名前空間の短名**が完全修飾名へ解決できず、継承禁止をすり抜ける
    //   (AGENTS.md 走査規約 (a): クラス参照は完全修飾名で突き合わせる)。
    if (! $isFullyQualified && $namespace !== '') {
        return $namespace.'\\'.$name;
    }

    return $name;
}

/**
 * ファイルが宣言している名前空間の数。
 *
 * ★1 ファイルに複数の名前空間を置くと、取り込み表を名前空間ごとに持ち分けない限り
 *   別の名前空間の同名の別名で上書きできてしまう (継承先を誤解して母集団から外れる)。
 *   本走査器は**単一の名前空間だけを解決対象**とし、複数宣言は未分類として落とす。
 *
 * @param  list<PhpToken>  $tokens
 */
function cachePayloadNamespaceDeclarationCount(array $tokens): int
{
    $count = count($tokens);
    $declarations = 0;
    for ($i = 0; $i < $count; $i++) {
        if ($tokens[$i]->is(T_NAMESPACE)) {
            $declarations++;
        }
    }

    return $declarations;
}

/**
 * 名前空間の**本体の波括弧の深さ**。
 *
 * `namespace A\B;` (セミコロン形) なら 0、`namespace A\B { … }` (波括弧形) なら 1 である。
 * 取り込み表はこの深さにある `use` だけを読む。
 *
 * @param  list<PhpToken>  $tokens
 */
function cachePayloadNamespaceBodyDepth(array $tokens): int
{
    $count = count($tokens);
    for ($i = 0; $i < $count; $i++) {
        if (! $tokens[$i]->is(T_NAMESPACE)) {
            continue;
        }
        for ($j = $i + 1; $j < $count; $j++) {
            if ($tokens[$j]->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_STRING, T_NAME_QUALIFIED, T_NS_SEPARATOR])) {
                continue;
            }

            return $tokens[$j]->text === '{' ? 1 : 0;
        }
    }

    return 0;
}

/**
 * ファイル先頭の `namespace A\B;` を取り出す (無ければ空文字)。
 *
 * @param  list<PhpToken>  $tokens
 */
function cachePayloadNamespace(array $tokens): string
{
    $count = count($tokens);
    for ($i = 0; $i < $count; $i++) {
        if (! $tokens[$i]->is(T_NAMESPACE)) {
            continue;
        }
        $nameIndex = cachePayloadNext($tokens, $i + 1);
        if ($nameIndex === null || ! $tokens[$nameIndex]->is([T_STRING, T_NAME_QUALIFIED])) {
            // `namespace\Foo` (相対参照) や無名前空間ブロック。名前空間の宣言ではない
            continue;
        }

        return ltrim($tokens[$nameIndex]->text, '\\');
    }

    return '';
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
function cachePayloadReceiverNames(array $tokens, array $useMap, string $namespace = ''): array
{
    $names = [];
    $count = count($tokens);
    for ($i = 0; $i < $count; $i++) {
        if (! $tokens[$i]->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE])) {
            continue;
        }
        if (! in_array(cachePayloadResolveName($tokens[$i]->text, $useMap, $namespace), CACHE_PAYLOAD_RECEIVER_TYPES, true)) {
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
 * @return list<array{method: string, line: int, kind: string}> kind: write|non_write|chain|terminal|bypass|unclassified
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
            in_array($method, CACHE_PAYLOAD_BYPASS_METHODS, true) => 'bypass',
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
function cachePayloadIsCacheBindingArg(array $tokens, ?int $firstArg, array $useMap, string $namespace = ''): bool
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
        cachePayloadResolveName($tokens[$firstArg]->text, $useMap, $namespace),
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
function cachePayloadContainerMakeChain(array $tokens, int $closeIndex, array $useMap, string $namespace = ''): ?int
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
    if (! cachePayloadIsCacheBindingArg($tokens, cachePayloadNext($tokens, $open + 1), $useMap, $namespace)) {
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
 * @return array{writes: list<array{relative: string, line: int, method: string}>, unclassified: list<string>, methods: list<string>, bypasses: list<string>, bypassCounts: array<string, int>, subclassDeclarations: list<string>, dynamicNewSites: list<string>, dynamicNewCounts: array<string, int>, cacheCalls: int, methodCalls: int, surface: bool}
 */
function cachePayloadCollectFromSource(string $source, string $relative): array
{
    /** @var list<PhpToken> $tokens */
    $tokens = PhpToken::tokenize($source);
    $useMap = cachePayloadUseMap($tokens);
    $namespace = cachePayloadNamespace($tokens);
    $receiverNames = cachePayloadReceiverNames($tokens, $useMap, $namespace);
    $namespaceDeclarations = cachePayloadNamespaceDeclarationCount($tokens);

    $writes = [];
    $unclassified = [];
    $methods = [];
    /** @var list<string> $bypasses */
    $bypasses = [];
    /** @var array<string, int> $bypassCounts */
    $bypassCounts = [];
    /** @var list<string> $subclassDeclarations */
    $subclassDeclarations = [];
    /** @var list<string> $dynamicNewSites */
    $dynamicNewSites = [];
    /** @var array<string, int> $dynamicNewCounts */
    $dynamicNewCounts = [];
    $cacheCalls = 0;
    $methodCalls = 0;
    $surface = false;
    $count = count($tokens);

    /** 迂回 1 件を記録する (目録の key は解決済みの完全修飾名で作る)。 */
    $recordBypass = function (string $key, string $site) use (&$bypasses, &$bypassCounts): void {
        $bypasses[] = $site;
        $bypassCounts[$key] = ($bypassCounts[$key] ?? 0) + 1;
    };

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

        // L4b の fail-closed: `new` の対象が**名前として解決できない**形を落とす。
        // `new $class` / `new ($expr)` は生成されるクラスが静的に決まらないため、
        // 保管先の直接生成を隠せてしまう (`$store = new $class; $store->put(...)` は
        // 受け手型の宣言も持たないので L2 にも現れない)。
        // ★走査根の全体で deny-by-default にし、キャッシュと無関係な既知の用途は
        //   CACHE_PAYLOAD_DYNAMIC_NEW_INVENTORY へ理由付きで exact-fit 登録する
        //   (「この動的生成はキャッシュの保管先ではない」という申告になる)。
        // 無名クラス (`new class extends Repository {}`) は T_EXTENDS の分岐が受け持つ。
        if ($token->is(T_NEW)) {
            $beforeNew = cachePayloadPrev($tokens, $i - 1);
            $isMethodNamedNew = $beforeNew !== null
                && $tokens[$beforeNew]->is([T_DOUBLE_COLON, T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR]);
            $target = cachePayloadNext($tokens, $i + 1);
            $isResolvableTarget = $target !== null && $tokens[$target]->is([
                T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE, T_CLASS, T_STATIC,
            ]);
            if (! $isMethodNamedNew && ! $isResolvableTarget) {
                $dynamicNewSites[] = "{$relative}:{$token->line} → new <静的に解決できないクラス名>";
                $dynamicNewCounts[$relative] = ($dynamicNewCounts[$relative] ?? 0) + 1;
            }
        }

        // L4d: 受け手型 / 保管先型の継承・実装の宣言 (宣言側で塞ぐ)。
        if ($token->is([T_EXTENDS, T_IMPLEMENTS])) {
            foreach (cachePayloadInheritanceClause($tokens, $i, $useMap, $namespace) as $declared) {
                if ($declared['resolved'] === null) {
                    $unclassified[] = "{$relative}:{$declared['line']} → extends/implements <解決できない名前>";

                    continue;
                }
                if (in_array($declared['resolved'], CACHE_PAYLOAD_RECEIVER_TYPES, true)
                    || in_array($declared['resolved'], CACHE_PAYLOAD_GUARD_IMPLEMENTATION_TYPES, true)
                    || cachePayloadIsStoreType($declared['resolved'])) {
                    $subclassDeclarations[] = "{$relative}::{$declared['keyword']} {$declared['resolved']}";
                }
            }
        }

        $operatorIndex = null;

        if ($token->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE])) {
            $prev = cachePayloadPrev($tokens, $i - 1);
            $isMemberName = $prev !== null
                && $tokens[$prev]->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_CONST]);
            $resolved = cachePayloadResolveName($token->text, $useMap, $namespace);
            // ★グローバル関数の呼び出し名は先頭 `\` を落として比較する。
            //   `\cache([...], 60)` は T_NAME_FULLY_QUALIFIED (text = '\cache') なので、
            //   素の text 比較だと**ヘルパ書き込みの完全修飾形が丸ごと素通り**する。
            //   名前空間を含む名前 (`App\cache`) は別物なので除外する。
            $callable = strtolower(ltrim($token->text, '\\'));
            $isRootCallable = ! str_contains($callable, '\\');
            $lower = $isRootCallable ? $callable : '';

            $isReceiverType = ! $isMemberName && in_array($resolved, CACHE_PAYLOAD_RECEIVER_TYPES, true);

            if ($isReceiverType) {
                $surface = true; // use 文・型宣言・::class 参照でも「面」としては hit する
                $next = cachePayloadNext($tokens, $i + 1);
                if ($next !== null && $tokens[$next]->is(T_DOUBLE_COLON)) {
                    // `Cache::put(...)` / `Cache::{'put'}(...)` を followChain に委ねる。
                    // `Repository::class` は followChain が T_CLASS を見て空を返すので無害
                    $operatorIndex = $next;
                }
            }

            $isStoreType = ! $isMemberName && cachePayloadIsStoreType($resolved);
            if ($isStoreType) {
                // 具体 store の名前に触れているファイルも「面」に数える
                // (受け皿を自前で組み立てる材料に触れている、という事実は目録へ出す)。
                $surface = true;
            }

            // L4b: 受け手型 / 保管先型の**直接生成**。受け皿を自前で作られると
            //      guard 付き manager を通らない受け皿が生まれる。
            if (($isReceiverType || $isStoreType)
                && $prev !== null && $tokens[$prev]->is(T_NEW)) {
                $recordBypass(
                    "{$relative}::new {$resolved}",
                    "{$relative}:{$token->line} → new {$resolved}",
                );
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

                if ($hasParen && cachePayloadIsCacheBindingArg($tokens, $firstArg, $useMap, $namespace)) {
                    // app('cache')->put(...) / app(Repository::class)->put(...)
                    $surface = true;
                    $next = $close === null ? null : cachePayloadNext($tokens, $close + 1);
                    if ($next !== null && $tokens[$next]->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR])) {
                        $operatorIndex = $next;
                    }
                } elseif ($hasParen && $close !== null && $firstArg === $close && $lower === 'app') {
                    // app()->make('cache')->put(...) 形。コンテナ経由の 1 段追加
                    $chained = cachePayloadContainerMakeChain($tokens, $close, $useMap, $namespace);
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
                } elseif ($arrow !== null && $tokens[$arrow]->text === '[') {
                    // ArrayAccess 書き込み (`$cache['k'] = $v` / `$cache['k'] ??= $v`)。
                    // メソッド呼び出し走査では検出できないので専用の分岐を持つ。
                    $closeBracket = cachePayloadMatchingBracket($tokens, $arrow);
                    $assign = $closeBracket === null ? null : cachePayloadNext($tokens, $closeBracket + 1);
                    if ($assign !== null && in_array($tokens[$assign]->text, ['=', '??='], true)) {
                        $surface = true;
                        $cacheCalls++;
                        $writes[] = ['relative' => $relative, 'line' => $token->line, 'method' => 'offsetSet'];
                        $methods[] = 'offsetset';
                    } elseif ($closeBracket === null) {
                        // ★対応する `]` を見つけられない = 解決できない形。見逃さずに落とす。
                        $unclassified[] = "{$relative}:{$token->line} → \${$name}[…] (対応する ] を解決できない)";
                    }
                }
            }
        }

        if ($operatorIndex === null) {
            continue;
        }

        foreach (cachePayloadFollowChain($tokens, $operatorIndex) as $call) {
            $cacheCalls++;
            $methods[] = $call['method'];
            $key = $relative.'::'.strtolower($call['method']);

            if ($call['kind'] === 'write') {
                $writes[] = ['relative' => $relative, 'line' => $call['line'], 'method' => $call['method']];
            } elseif ($call['kind'] === 'bypass') {
                $recordBypass($key, "{$relative}:{$call['line']} → ->{$call['method']}()");
            } elseif ($call['kind'] === 'unclassified') {
                // ★実行時層は保管先への素通し (__call) を落とすため、その自己テストは
                //   「4 分類のどれでもない API 名」を意図的に呼ぶ。自己テスト目録に
                //   登録済みの呼び出しだけを迂回として数え、それ以外は従来どおり落とす。
                if (array_key_exists($key, CACHE_PAYLOAD_BOUNDARY_SELFTEST_INVENTORY)) {
                    $recordBypass($key, "{$relative}:{$call['line']} → ->{$call['method']}()");
                } else {
                    $unclassified[] = "{$relative}:{$call['line']} → ->{$call['method']}()";
                }
            }
        }
    }

    // ★1 ファイルに複数の名前空間があると、取り込み表を持ち分けない限り
    //   別の名前空間の同名の別名で上書きできる。**解決できない形として落とす**。
    if ($namespaceDeclarations > 1) {
        $unclassified[] = "{$relative} → 1 ファイルに名前空間が {$namespaceDeclarations} 個あり、"
            .'取り込み表を名前空間ごとに解決できません (1 ファイル 1 名前空間にしてください)';
    }

    sort($bypasses);
    ksort($bypassCounts);
    sort($subclassDeclarations);
    sort($dynamicNewSites);
    ksort($dynamicNewCounts);

    return [
        'writes' => $writes,
        'unclassified' => $unclassified,
        'methods' => $methods,
        'bypasses' => $bypasses,
        'bypassCounts' => $bypassCounts,
        'subclassDeclarations' => $subclassDeclarations,
        'dynamicNewSites' => $dynamicNewSites,
        'dynamicNewCounts' => $dynamicNewCounts,
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
 * @param  string  $path  宣言されたファイル (役割を任意のファイルが名乗れないようにするため)
 * @return list<string> 違反理由。空なら整合
 */
function cachePayloadRoleViolations(string $role, array $methods, bool $hasWriteEntry, string $path = ''): array
{
    $known = ['write', 'no-payload-write', 'lock-only', 'driver-handoff', 'guard-implementation', 'boundary-selftest'];
    if (! in_array($role, $known, true)) {
        return ['role は '.implode(' / ', $known)." のいずれか (宣言値: {$role})"];
    }

    if ($role === 'write') {
        return $hasWriteEntry ? [] : ['role=write なのに書き込み目録に entry がありません'];
    }

    if ($role === 'guard-implementation') {
        // 実行時層の実装そのもの。受け手型を**参照するだけ**で API は呼ばない、という申告である。
        $violations = [];
        if ($hasWriteEntry) {
            $violations[] = 'role=guard-implementation なのに書き込み目録に entry があります';
        }
        if ($methods !== []) {
            $violations[] = 'role=guard-implementation なのにキャッシュ API を呼んでいます: '.implode(', ', $methods);
        }
        if (! str_starts_with($path, 'tests/Support/Cache/')) {
            $violations[] = 'role=guard-implementation は tests/Support/Cache/ 配下でだけ名乗れます: '.$path;
        }

        return $violations;
    }

    if ($role === 'boundary-selftest') {
        // 境界迂回が hard fail することを固定する唯一の呼び出し元。
        $violations = [];
        if ($hasWriteEntry) {
            $violations[] = 'role=boundary-selftest なのに書き込み目録に entry があります (payload は書かない)';
        }
        if ($path !== CACHE_PAYLOAD_BOUNDARY_SELFTEST_FILE) {
            $violations[] = 'role=boundary-selftest を名乗れるのは '.CACHE_PAYLOAD_BOUNDARY_SELFTEST_FILE." だけです: {$path}";
        }
        $registered = false;
        foreach (array_keys(CACHE_PAYLOAD_BOUNDARY_SELFTEST_INVENTORY) as $key) {
            if (str_starts_with($key, $path.'::')) {
                $registered = true;
                break;
            }
        }
        if (! $registered) {
            $violations[] = 'role=boundary-selftest なのに L4 の自己テスト目録に entry がありません';
        }

        return $violations;
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
 * @return array{writeCounts: array<string, int>, writeSites: list<string>, unclassified: list<string>, surfaces: array<string, list<string>>, bypassSites: list<string>, bypassCounts: array<string, int>, subclassDeclarations: list<string>, dynamicNewSites: list<string>, dynamicNewCounts: array<string, int>, cacheCalls: int, methodCalls: int, files: int}
 */
function cachePayloadCollectAll(): array
{
    /** @var array{writeCounts: array<string, int>, writeSites: list<string>, unclassified: list<string>, surfaces: array<string, list<string>>, bypassSites: list<string>, bypassCounts: array<string, int>, subclassDeclarations: list<string>, dynamicNewSites: list<string>, dynamicNewCounts: array<string, int>, cacheCalls: int, methodCalls: int, files: int}|null $cached */
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    $writeCounts = [];
    $writeSites = [];
    $unclassified = [];
    $surfaces = [];
    /** @var list<string> $bypassSites */
    $bypassSites = [];
    /** @var array<string, int> $bypassCounts */
    $bypassCounts = [];
    /** @var list<string> $subclassDeclarations */
    $subclassDeclarations = [];
    /** @var list<string> $dynamicNewSites */
    $dynamicNewSites = [];
    /** @var array<string, int> $dynamicNewCounts */
    $dynamicNewCounts = [];
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
        $bypassSites = array_merge($bypassSites, $collected['bypasses']);
        $subclassDeclarations = array_merge($subclassDeclarations, $collected['subclassDeclarations']);
        $dynamicNewSites = array_merge($dynamicNewSites, $collected['dynamicNewSites']);
        foreach ($collected['bypassCounts'] as $key => $bypassCount) {
            $bypassCounts[$key] = ($bypassCounts[$key] ?? 0) + $bypassCount;
        }
        foreach ($collected['dynamicNewCounts'] as $key => $dynamicCount) {
            $dynamicNewCounts[$key] = ($dynamicNewCounts[$key] ?? 0) + $dynamicCount;
        }

        if ($collected['surface']) {
            $surfaces[$target['relative']] = $collected['methods'];
        }
    }

    ksort($writeCounts);
    ksort($surfaces);
    ksort($bypassCounts);
    ksort($dynamicNewCounts);
    sort($writeSites);
    sort($bypassSites);
    sort($subclassDeclarations);
    sort($dynamicNewSites);

    $cached = [
        'writeCounts' => $writeCounts,
        'writeSites' => $writeSites,
        'unclassified' => $unclassified,
        'surfaces' => $surfaces,
        'bypassSites' => $bypassSites,
        'bypassCounts' => $bypassCounts,
        'subclassDeclarations' => $subclassDeclarations,
        'dynamicNewSites' => $dynamicNewSites,
        'dynamicNewCounts' => $dynamicNewCounts,
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
        expect(in_array($entry['kind'], ['plain', 'guard-selftest'], true))
            ->toBeTrue("{$key}: kind は plain / guard-selftest のいずれか (宣言値: {$entry['kind']})");
        expect(is_file(base_path($path)))->toBeTrue("{$key}: 対象ファイルが実在しません");
        expect(is_file(base_path($entry['proof'])))->toBeTrue(
            "{$key}: proof に指定した検査 {$entry['proof']} が実在しません。"
            .'kind=plain はキャッシュへ入れる配列の「往復が壊れないこと」を単体テストで、'
            .'kind=guard-selftest は「実行時層が落とすこと」を振る舞い検査で固定してください');
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

        expect(cachePayloadRoleViolations($entry['role'], $methods, $hasWrite, $path))
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

    // guard-implementation (T228): 受け手型を参照するだけ。API を 1 件でも呼んだら違反、
    // 許可パス外で名乗っても違反 (任意のファイルが迂回実装の免除に使えないようにする)。
    $guardPath = 'tests/Support/Cache/PlainDataGuardedRepository.php';
    expect(cachePayloadRoleViolations('guard-implementation', [], false, $guardPath))->toBe([]);
    expect(cachePayloadRoleViolations('guard-implementation', ['put'], false, $guardPath))->not->toBe([]);
    expect(cachePayloadRoleViolations('guard-implementation', ['get'], false, $guardPath))->not->toBe([]);
    expect(cachePayloadRoleViolations('guard-implementation', [], true, $guardPath))->not->toBe([]);
    expect(cachePayloadRoleViolations('guard-implementation', [], false, 'app/Services/FxRateService.php'))
        ->not->toBe([]);

    // boundary-selftest (T228): 名指しの 1 ファイルだけが名乗れ、L4 の自己テスト目録に
    // entry を持ち、L2 の書き込み目録には entry を持たない。
    expect(cachePayloadRoleViolations('boundary-selftest', ['tags'], false, CACHE_PAYLOAD_BOUNDARY_SELFTEST_FILE))
        ->toBe([]);
    expect(cachePayloadRoleViolations('boundary-selftest', ['tags'], true, CACHE_PAYLOAD_BOUNDARY_SELFTEST_FILE))
        ->not->toBe([]);
    expect(cachePayloadRoleViolations('boundary-selftest', ['tags'], false, 'tests/Support/Cache/OtherProbe.php'))
        ->not->toBe([]);
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

test('検査 6b: 語彙表が健全 (5 分類は互いに素 / 除外型は受け手型と重ならない)', function (): void {
    // ★同じメソッドが 2 つの分類に入ると match の順序で暗黙に勝敗が決まり、
    //   「WRITE のつもりが NON_WRITE として素通り」が静かに起きる。互いに素であることを固定する。
    $groups = [
        'WRITE' => CACHE_PAYLOAD_WRITE_METHODS,
        'NON_WRITE' => CACHE_PAYLOAD_NON_WRITE_METHODS,
        'CHAIN' => CACHE_PAYLOAD_CHAIN_METHODS,
        'BYPASS' => CACHE_PAYLOAD_BYPASS_METHODS,
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
// 検査 L4: 境界迂回の hard fail (正典 v2 の要素 4)
// ---------------------------------------------------------------------------

test('検査 L4a: 受け皿の境界を迂回する API 呼び出しと直接生成が自己テスト目録と exact-fit で一致する', function (): void {
    $result = cachePayloadCollectAll();

    $declared = [];
    foreach (CACHE_PAYLOAD_BOUNDARY_SELFTEST_INVENTORY as $key => $entry) {
        $declared[$key] = $entry['count'];
    }
    ksort($declared);

    expect($result['bypassCounts'])->toBe($declared,
        '受け皿 (Illuminate\Cache\Repository) を跨いで保管先へ届く / 受け皿の生成に割り込む書き方は'
        .'**通常経路 0 件**です (家系の裁定 AG-151 の境界迂回の hard fail)。'
        .'Cache::extend / getStore / setStore / tags / macro / mixin / flushMacros / '
        .'受け手型・保管先型の直接生成は、実行時層が値を見られない経路を作ります。'
        .'実行時層の自己テストだけが CACHE_PAYLOAD_BOUNDARY_SELFTEST_INVENTORY へ登録できます。'
        .PHP_EOL.'検出: '.implode(PHP_EOL, $result['bypassSites']));
});

test('検査 L4b: 自己テスト目録の各 entry が形式要件を満たし実測で非空である', function (): void {
    expect(CACHE_PAYLOAD_BOUNDARY_SELFTEST_INVENTORY)->not->toBe([]);
    $result = cachePayloadCollectAll();

    foreach (CACHE_PAYLOAD_BOUNDARY_SELFTEST_INVENTORY as $key => $entry) {
        expect($entry['count'])->toBeGreaterThanOrEqual(1, "{$key}: count は 1 以上");
        expect(mb_strlen($entry['rationale']))->toBeGreaterThanOrEqual(30, "{$key}: rationale が短すぎます");
        expect($result['bypassCounts'][$key] ?? 0)->toBe($entry['count'],
            "{$key}: 目録の件数と実測が一致しません (実在しない登録も、件数のズレも落とす)");
    }
});

test('検査 L4c: guard 付き manager は $store を受け皿の第 1 引数以外へ流さない', function (): void {
    // ★保管先を外へ流出させると、受け皿を迂回して書ける経路が生まれる。
    $relative = 'tests/Support/Cache/PlainDataGuardedCacheManager.php';
    $source = file_get_contents(base_path($relative));
    expect($source)->toBeString();

    expect(cachePayloadStoreLeakViolations((string) $source))->toBe([],
        "{$relative}: \$store は (1) `Store \$store` の型宣言 (2) "
        .'`new PlainDataGuardedRepository($store, …)` の第 1 引数 の 2 か所ちょうどでなければなりません');
});

test('検査 L4c の正負コントロール: $store の流出を検出する', function (): void {
    $ok = <<<'PHP'
    <?php
    final class Fixture {
        public function repository(Store $store, array $config = [])
        {
            return new PlainDataGuardedRepository($store, Arr::only($config, ['store']));
        }
    }
    PHP;
    expect(cachePayloadStoreLeakViolations($ok))->toBe([]);

    // 第 1 引数が別の変数へすり替わっている (受け皿が包む保管先が変わる)
    $swapped = <<<'PHP'
    <?php
    final class Fixture {
        public function repository(Store $store, array $config = [])
        {
            $copy = leak($store);
            return new PlainDataGuardedRepository($copy, []);
        }
    }
    PHP;
    expect(cachePayloadStoreLeakViolations($swapped))->not->toBe([]);

    // 第 2 引数へ回すと、受け皿の外へ保管先が漏れる
    $leaked = <<<'PHP'
    <?php
    final class Fixture {
        public function repository(Store $store, array $config = [])
        {
            return new PlainDataGuardedRepository(new ArrayStore, $store);
        }
    }
    PHP;
    expect(cachePayloadStoreLeakViolations($leaked))->not->toBe([]);

    // 受け皿へ渡さずどこかへ渡す形
    $handedOff = <<<'PHP'
    <?php
    final class Fixture {
        public function repository(Store $store, array $config = [])
        {
            Registry::remember($store);
            return new PlainDataGuardedRepository($store, []);
        }
    }
    PHP;
    expect(cachePayloadStoreLeakViolations($handedOff))->not->toBe([]);
});

test('検査 L4d: 受け手型 / 保管先型の継承・実装が名指しの目録と exact-fit で一致する', function (): void {
    $result = cachePayloadCollectAll();

    $declared = array_keys(CACHE_PAYLOAD_SUBCLASS_INVENTORY);
    sort($declared);

    expect($result['subclassDeclarations'])->toBe($declared,
        '受け手型 / 保管先型を継承・実装すると `new` の検出を逃れて受け皿を自作できます。'
        .'宣言側で塞ぐため CACHE_PAYLOAD_SUBCLASS_INVENTORY と exact-fit で一致させてください。');

    foreach (CACHE_PAYLOAD_SUBCLASS_INVENTORY as $key => $rationale) {
        expect(mb_strlen($rationale))->toBeGreaterThanOrEqual(30, "{$key}: rationale が短すぎます");
        expect(is_file(base_path(explode('::', $key, 2)[0])))->toBeTrue("{$key}: 対象ファイルが実在しません");
    }
});

test('検査 L4h: 静的に解決できない new が非キャッシュ用途の目録と exact-fit で一致する', function (): void {
    $result = cachePayloadCollectAll();

    $declared = [];
    foreach (CACHE_PAYLOAD_DYNAMIC_NEW_INVENTORY as $path => $entry) {
        $declared[$path] = $entry['count'];
    }
    ksort($declared);

    expect($result['dynamicNewCounts'])->toBe($declared,
        '`new $class` / `new ($expr)` は生成されるクラスが静的に決まらないため、'
        .'受け皿・保管先の直接生成を隠せてしまいます (受け手型の宣言も持たないので L2 にも現れません)。'
        .'キャッシュの保管先ではないなら CACHE_PAYLOAD_DYNAMIC_NEW_INVENTORY へ'
        .'count と 30 文字以上の rationale を添えて登録してください。'
        .PHP_EOL.'検出: '.implode(PHP_EOL, $result['dynamicNewSites']));

    foreach (CACHE_PAYLOAD_DYNAMIC_NEW_INVENTORY as $path => $entry) {
        expect($entry['count'])->toBeGreaterThanOrEqual(1, "{$path}: count は 1 以上");
        expect(mb_strlen($entry['rationale']))->toBeGreaterThanOrEqual(30, "{$path}: rationale が短すぎます");
        expect(is_file(base_path($path)))->toBeTrue("{$path}: 対象ファイルが実在しません");
    }

    // 空振り検知: 目録が空でなく、実測も空でない (走査が死んでいたら気付けるようにする)
    expect(CACHE_PAYLOAD_DYNAMIC_NEW_INVENTORY)->not->toBe([]);
    expect($result['dynamicNewSites'])->not->toBe([]);
});

test('検査 L4e: 保管先型の判定規則の正負コントロール', function (): void {
    expect(cachePayloadIsStoreType('Illuminate\Contracts\Cache\Store'))->toBeTrue();
    expect(cachePayloadIsStoreType('Illuminate\Cache\ArrayStore'))->toBeTrue();
    expect(cachePayloadIsStoreType('Illuminate\Cache\DatabaseStore'))->toBeTrue();
    expect(cachePayloadIsStoreType('Illuminate\Cache\MemoizedStore'))->toBeTrue();

    expect(cachePayloadIsStoreType('Illuminate\Cache\Repository'))->toBeFalse();
    expect(cachePayloadIsStoreType('App\Support\Storage\ObjectStore'))->toBeFalse();
    expect(cachePayloadIsStoreType('Illuminate\Session\Store'))->toBeFalse();
    expect(cachePayloadIsStoreType('Illuminate\Cache\StoreFactory'))->toBeFalse();
});

test('検査 L4f: 自己テスト目録の key は GuardedBoundaryProbe.php ちょうどにしか無い', function (): void {
    // ★「tests/Support/Cache/ 配下すべて」にはしない — 将来足した任意の補助ファイルが
    //   自己テストを名乗れてしまうため。
    expect(is_file(base_path(CACHE_PAYLOAD_BOUNDARY_SELFTEST_FILE)))->toBeTrue();

    foreach (array_keys(CACHE_PAYLOAD_BOUNDARY_SELFTEST_INVENTORY) as $key) {
        expect(explode('::', $key, 2)[0])->toBe(CACHE_PAYLOAD_BOUNDARY_SELFTEST_FILE,
            "{$key}: 自己テスト目録に登録できるのは ".CACHE_PAYLOAD_BOUNDARY_SELFTEST_FILE.' だけです');
    }
});

test('検査 L4g: 実行時層の素通し許可が静的層の排他語彙の部分集合である', function (): void {
    // ★実行時層は `Repository::__call()` の素通しのうち排他 2 語彙だけを通す。
    //   その許可が静的層の TERMINAL 語彙 (payload を運ばないと分類した語彙) の
    //   **部分集合**であることを固定し、2 か所で別々に育てられないようにする
    //   (TERMINAL には mock 系も含むので一致ではなく部分集合である)。
    expect(PlainDataGuardedRepository::STORE_PASSTHROUGH_METHODS)->toBe(['lock', 'restorelock']);
    expect(array_values(array_intersect(
        CACHE_PAYLOAD_TERMINAL_METHODS,
        PlainDataGuardedRepository::STORE_PASSTHROUGH_METHODS
    )))->toBe(PlainDataGuardedRepository::STORE_PASSTHROUGH_METHODS,
        '実行時層が素通しを許した語彙は、静的層が TERMINAL (payload を運ばない) と分類した語彙の'
        .'部分集合でなければなりません');
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
    expect($result['bypassSites'])->not->toBe([], '境界迂回の検出器が 1 件も反応していない (L4 の走査が死んでいる)');
    expect($result['subclassDeclarations'])->not->toBe([], '継承・実装の検出器が 1 件も反応していない (L4d の走査が死んでいる)');

    // 検出器そのものが負例で反応することを合成入力で確かめる (実在ファイルの構成に依存させない)。
    $probe = cachePayloadCollectFromSource(<<<'PHP'
    <?php
    namespace App\Demo;
    use Illuminate\Support\Facades\Cache;
    use Illuminate\Contracts\Cache\Repository;
    class Fixture {
        public function run(Repository $cache, $obj): void {
            Cache::getStore()->put('a', [1], 60);
            $cache['k'] = $obj;
        }
    }
    PHP, 'probe.php');
    expect($probe['bypassCounts'])->toBe(['probe.php::getstore' => 1]);
    expect($probe['writes'])->toHaveCount(1);
});

test('検査 8: 自己参照コントロール (本 gate 自身は書き込み経路にも面にも現れない)', function (): void {
    $result = cachePayloadCollectAll();
    $self = 'tests/Architecture/CachePayloadPlainDataGateTest.php';

    // fixture は nowdoc (文字列トークン) なので code として走査されない。
    // 将来ここに code としてキャッシュ呼び出しを書いたら落ちる = 正しい挙動。
    expect(array_key_exists($self, $result['surfaces']))->toBeFalse();
    expect(array_filter($result['writeSites'], fn (string $s): bool => str_starts_with($s, $self)))->toBe([]);
    expect(array_filter($result['bypassSites'], fn (string $s): bool => str_starts_with($s, $self)))->toBe([]);
    expect(array_filter($result['subclassDeclarations'], fn (string $s): bool => str_starts_with($s, $self)))->toBe([]);
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
    // ★`Cache::tags(['t'])->forever(...)` は L4 で**迂回**になったので書き込みには数えない
    //   (辿って数えるのではなく、書き方そのものを 0 件で pin する側へ移した)。
    expect($result['writes'])->toHaveCount(9);
    expect($result['bypassCounts'])->toBe(['fixture.php::tags' => 1]);
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
    // ★`Cache::getStore()->put(...)` は L4 で**迂回**になった。書き込み検出は消えるが
    //   保護は弱くならない (迂回として 0 件 pin されるため)。
    expect($result['writes'])->toHaveCount(4);
    expect($result['bypassCounts'])->toBe(['fixture.php::getstore' => 1]);
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
    // 受け手型の直接生成そのものは L4b の迂回として検出される
    expect($result['bypassCounts'])->toBe(['fixture.php::new Illuminate\Cache\Repository' => 1]);
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

test('負のコントロール: 境界迂回の 7 語彙をすべて検出する', function (): void {
    $fixture = <<<'PHP'
    <?php
    namespace App\Demo;
    use Illuminate\Support\Facades\Cache;
    use Illuminate\Cache\Repository;
    use Illuminate\Cache\CacheManager;
    class Fixture {
        public function run(Repository $cache, CacheManager $manager): void {
            Cache::extend('x', fn () => null);
            $cache->getStore();
            $cache->setStore(null);
            $cache->tags(['t']);
            $manager->macro('m', fn () => null);
            $manager->mixin(null);
            $manager->flushMacros();
        }
    }
    PHP;

    $result = cachePayloadCollectFromSource($fixture, 'fixture.php');
    expect($result['bypassCounts'])->toBe([
        'fixture.php::extend' => 1,
        'fixture.php::flushmacros' => 1,
        'fixture.php::getstore' => 1,
        'fixture.php::macro' => 1,
        'fixture.php::mixin' => 1,
        'fixture.php::setstore' => 1,
        'fixture.php::tags' => 1,
    ]);
    expect($result['writes'])->toBe([]);
    expect($result['unclassified'])->toBe([]);
});

test('負のコントロール: 受け手型 / 保管先型の直接生成を検出する', function (): void {
    $fixture = <<<'PHP'
    <?php
    namespace App\Demo;
    use Illuminate\Cache\ArrayStore;
    use Illuminate\Cache\Repository;
    use Illuminate\Contracts\Cache\Store as CacheStore;
    class Fixture {
        public function run(): void {
            $a = new Repository(new ArrayStore);
            $b = new \Illuminate\Cache\DatabaseStore(null, 'cache', '');
            $c = new \Illuminate\Cache\CacheManager(null);
        }
    }
    PHP;

    $result = cachePayloadCollectFromSource($fixture, 'fixture.php');
    expect($result['bypassCounts'])->toBe([
        'fixture.php::new Illuminate\Cache\ArrayStore' => 1,
        'fixture.php::new Illuminate\Cache\CacheManager' => 1,
        'fixture.php::new Illuminate\Cache\DatabaseStore' => 1,
        'fixture.php::new Illuminate\Cache\Repository' => 1,
    ]);
});

test('負のコントロール: 受け手型 / 保管先型の継承・実装を 4 形すべて検出する', function (): void {
    // ★直前 token だけを見る形では 2 番目の interface を落とす。宣言句全体を読む。
    $fixture = <<<'PHP'
    <?php
    namespace App\Demo;
    use Countable;
    use Illuminate\Cache\Repository;
    use Illuminate\Contracts\Cache\Store as CacheStore;
    class Second implements Countable, \Illuminate\Contracts\Cache\Store {}
    class Aliased implements CacheStore {}
    class Fully implements \Illuminate\Contracts\Cache\Store {}
    class Multiline implements
        Countable,
        CacheStore {}
    class Inherited extends Repository {}
    PHP;

    $result = cachePayloadCollectFromSource($fixture, 'fixture.php');
    expect($result['subclassDeclarations'])->toBe([
        'fixture.php::extends Illuminate\Cache\Repository',
        'fixture.php::implements Illuminate\Contracts\Cache\Store',
        'fixture.php::implements Illuminate\Contracts\Cache\Store',
        'fixture.php::implements Illuminate\Contracts\Cache\Store',
        'fixture.php::implements Illuminate\Contracts\Cache\Store',
    ]);
});

test('負のコントロール: 継承句に解決できない名前があれば未分類として落とす', function (): void {
    // ★fail-closed 分岐の裏取り (AGENTS.md 走査規約 (b))。名前として読めない形を
    //   黙って候補から外すと、宣言側で塞ぐ L4d をすり抜けられる。
    $fixture = <<<'PHP'
    <?php
    namespace App\Demo;
    class Fixture implements $dynamicInterface {}
    PHP;

    $result = cachePayloadCollectFromSource($fixture, 'fixture.php');
    expect($result['unclassified'])->toHaveCount(1);
    expect($result['unclassified'][0])->toContain('extends/implements');
    expect($result['subclassDeclarations'])->toBe([]);
});

test('負のコントロール: 静的に解決できない new を 2 形とも検出する', function (): void {
    // ★`$store = new $class;` は生成されるクラスが静的に決まらず、受け手型の宣言も持たないので
    //   L4b にも L2 にも現れない。**走査根の全体で見逃さずに落とす** (L4h の目録が受け皿)。
    $fixture = <<<'PHP'
    <?php
    namespace App\Demo;
    class Fixture {
        public function run(): void {
            $class = 'Illuminate\Cache\ArrayStore';
            $store = new $class;
            $store->put('key', new \stdClass(), 60);
            $other = new ($this->resolver())();
        }
    }
    PHP;

    $result = cachePayloadCollectFromSource($fixture, 'fixture.php');
    expect($result['dynamicNewCounts'])->toBe(['fixture.php' => 2]);
    foreach ($result['dynamicNewSites'] as $entry) {
        expect($entry)->toContain('new <静的に解決できないクラス名>');
    }
});

test('正のコントロール: 名前で書かれた new と new というメソッド名は動的生成に数えない', function (): void {
    // ★`Factory::new()` / `->new()` は**メソッド名**であって生成ではない。
    $fixture = <<<'PHP'
    <?php
    namespace App\Demo;
    class Fixture {
        public function run(): void {
            $factory = PasskeyFactory::new();
            $chained = $this->factory()->new();
            $a = new \DateTimeImmutable();
            $b = new static();
            $c = new class {};
        }
    }
    PHP;

    $result = cachePayloadCollectFromSource($fixture, 'fixture.php');
    expect($result['dynamicNewCounts'])->toBe([]);
    expect($result['dynamicNewSites'])->toBe([]);
    expect($result['unclassified'])->toBe([]);
});

test('負のコントロール: guard 実装クラスの継承を 4 形とも迂回として検出する', function (): void {
    // ★受け手型にも保管先型の命名規則にも一致しないが、継承すれば末端 4 メソッドを
    //   override し直して getStore() 経由で保管先へ直接書ける。**宣言側で塞ぐ**。
    // ★4 形のうち**同一名前空間の短名**が load-bearing である。現在の名前空間を
    //   考慮しないと完全修飾名へ解決できず、継承禁止をすり抜ける (走査規約 (a))。
    $imported = <<<'PHP'
    <?php
    namespace App\Demo;
    use Tests\Support\Cache\PlainDataGuardedRepository;
    final class BypassRepository extends PlainDataGuardedRepository {}
    final class BypassManager extends \Tests\Support\Cache\PlainDataGuardedCacheManager {}
    PHP;

    expect(cachePayloadCollectFromSource($imported, 'fixture.php')['subclassDeclarations'])->toBe([
        'fixture.php::extends Tests\Support\Cache\PlainDataGuardedCacheManager',
        'fixture.php::extends Tests\Support\Cache\PlainDataGuardedRepository',
    ]);

    $sameNamespace = <<<'PHP'
    <?php
    namespace Tests\Support\Cache;
    final class BypassRepository extends PlainDataGuardedRepository {}
    final class RelativeBypass extends namespace\PlainDataGuardedCacheManager {}
    PHP;

    expect(cachePayloadCollectFromSource($sameNamespace, 'fixture.php')['subclassDeclarations'])->toBe([
        'fixture.php::extends Tests\Support\Cache\PlainDataGuardedCacheManager',
        'fixture.php::extends Tests\Support\Cache\PlainDataGuardedRepository',
    ]);

    // グループ use + 別名。走査器自身がグループ use を解決できないと素通しになる
    $groupUse = <<<'PHP'
    <?php
    namespace App\Demo;
    use Tests\Support\Cache\{PlainDataGuardedRepository as GuardedRepository};
    final class Bypass extends GuardedRepository {}
    PHP;

    expect(cachePayloadCollectFromSource($groupUse, 'fixture.php')['subclassDeclarations'])->toBe([
        'fixture.php::extends Tests\Support\Cache\PlainDataGuardedRepository',
    ]);

    // ★クラス本体の trait 取り込みが名前空間スコープの取り込みを上書きしないこと。
    //   上書きされると `extends Guarded` が別クラスへ解決されて母集団から外れる。
    $traitShadowing = <<<'PHP'
    <?php
    namespace App\Demo;
    use Tests\Support\Cache\PlainDataGuardedRepository as Guarded;
    class TraitUser {
        use \Vendor\Package\Guarded;
    }
    class Bypass extends Guarded {}
    PHP;

    expect(cachePayloadCollectFromSource($traitShadowing, 'fixture.php')['subclassDeclarations'])->toBe([
        'fixture.php::extends Tests\Support\Cache\PlainDataGuardedRepository',
    ]);

    // ★型宣言の**後ろ**に置いた名前空間スコープの取り込みも読むこと。
    //   「最初の型宣言で打ち切る」形だと取り込み表が空のまま確定して母集団から外れる。
    $lateImport = <<<'PHP'
    <?php
    namespace App\Demo;
    class Marker {}
    use Tests\Support\Cache\PlainDataGuardedRepository as Guarded;
    class Bypass extends Guarded {}
    PHP;

    expect(cachePayloadCollectFromSource($lateImport, 'fixture.php')['subclassDeclarations'])->toBe([
        'fixture.php::extends Tests\Support\Cache\PlainDataGuardedRepository',
    ]);

    // ★波括弧形の名前空間でも同じこと (取り込みは名前空間本体の直下にある)
    $bracedNamespace = <<<'PHP'
    <?php
    namespace App\Demo {
        use Tests\Support\Cache\PlainDataGuardedRepository as Guarded;
        class Bypass extends Guarded {}
    }
    PHP;

    expect(cachePayloadCollectFromSource($bracedNamespace, 'fixture.php')['subclassDeclarations'])->toBe([
        'fixture.php::extends Tests\Support\Cache\PlainDataGuardedRepository',
    ]);
});

test('負のコントロール: 1 ファイルに複数の名前空間がある形は解決できないとして落とす', function (): void {
    // ★取り込み表を名前空間ごとに持ち分けないと、後続の名前空間の同名の別名で上書きされ、
    //   継承先を誤解して母集団から外れる。**解決できない形として落とす** (走査規約 (b))。
    $semicolonForm = <<<'PHP'
    <?php
    namespace First;
    use Tests\Support\Cache\PlainDataGuardedRepository as Guarded;
    class Bypass extends Guarded {}
    namespace Second;
    use Vendor\Package\Unrelated as Guarded;
    PHP;

    $result = cachePayloadCollectFromSource($semicolonForm, 'fixture.php');
    expect($result['unclassified'])->toHaveCount(1);
    expect($result['unclassified'][0])->toContain('名前空間が 2 個');

    $bracedForm = <<<'PHP'
    <?php
    namespace First {
        use Tests\Support\Cache\PlainDataGuardedRepository as Guarded;
        class Bypass extends Guarded {}
    }
    namespace Second {
        use Vendor\Package\Unrelated as Guarded;
    }
    PHP;

    $result = cachePayloadCollectFromSource($bracedForm, 'fixture.php');
    expect($result['unclassified'])->toHaveCount(1);
    expect($result['unclassified'][0])->toContain('名前空間が 2 個');

    // 正のコントロール: 名前空間が 1 つなら落とさない
    $single = <<<'PHP'
    <?php
    namespace First;
    use Tests\Support\Cache\PlainDataGuardedRepository as Guarded;
    class Bypass extends Guarded {}
    PHP;
    expect(cachePayloadCollectFromSource($single, 'fixture.php')['unclassified'])->toBe([]);
});

test('正のコントロール: 完全修飾名 / 別名 / 同一名前空間の短名が同じ完全修飾名へ解決する', function (): void {
    // ★AGENTS.md 走査規約 (a) の裏取り。3 経路が同じ完全修飾名になることを固定する。
    $useMap = ['Aliased' => 'Tests\Support\Cache\PlainDataGuardedRepository'];

    expect(cachePayloadResolveName('\Tests\Support\Cache\PlainDataGuardedRepository', [], 'App\Demo'))
        ->toBe('Tests\Support\Cache\PlainDataGuardedRepository');
    expect(cachePayloadResolveName('Aliased', $useMap, 'App\Demo'))
        ->toBe('Tests\Support\Cache\PlainDataGuardedRepository');
    expect(cachePayloadResolveName('PlainDataGuardedRepository', [], 'Tests\Support\Cache'))
        ->toBe('Tests\Support\Cache\PlainDataGuardedRepository');
    expect(cachePayloadResolveName('namespace\PlainDataGuardedRepository', [], 'Tests\Support\Cache'))
        ->toBe('Tests\Support\Cache\PlainDataGuardedRepository');

    // 別名つき取り込みは名前空間より優先する (取り込みが勝つ)
    expect(cachePayloadResolveName('Aliased', $useMap, 'Tests\Support\Cache'))
        ->toBe('Tests\Support\Cache\PlainDataGuardedRepository');

    // 名前空間の中の裸の名前は**その名前空間**へ解決する (global へ落とさない)
    expect(cachePayloadResolveName('Repository', [], 'App\Demo'))->toBe('App\Demo\Repository');

    // グループ use も完全修飾名へ解決する (本走査器自身が fail-closed であるため)
    /** @var list<PhpToken> $group */
    $group = PhpToken::tokenize(<<<'PHP'
    <?php
    use Tests\Support\Cache\{PlainDataGuardedRepository as GuardedRepository, PlainDataGuardedCacheManager};
    use Illuminate\Cache\Repository;
    PHP);
    expect(cachePayloadUseMap($group))->toBe([
        'GuardedRepository' => 'Tests\Support\Cache\PlainDataGuardedRepository',
        'PlainDataGuardedCacheManager' => 'Tests\Support\Cache\PlainDataGuardedCacheManager',
        'Repository' => 'Illuminate\Cache\Repository',
    ]);

    // 関数・定数の取り込みは**クラスの取り込みではない**ので表に入れない
    // (末尾の名前を alias として登録すると、同名のクラス取り込みを上書きして解決を壊す)
    /** @var list<PhpToken> $functionImports */
    $functionImports = PhpToken::tokenize(<<<'PHP'
    <?php
    use Illuminate\Cache\Repository;
    use function Vendor\Tools\Repository;
    use const Vendor\Tools\Store;
    use Vendor\Tools\{function helper, const LIMIT, ArrayStore};
    PHP);
    expect(cachePayloadUseMap($functionImports))->toBe([
        'Repository' => 'Illuminate\Cache\Repository',
        'ArrayStore' => 'Vendor\Tools\ArrayStore',
    ]);

    // 名前空間の宣言そのものの抽出
    /** @var list<PhpToken> $tokens */
    $tokens = PhpToken::tokenize("<?php\nnamespace Tests\\Support\\Cache;\nclass X {}");
    expect(cachePayloadNamespace($tokens))->toBe('Tests\Support\Cache');
    /** @var list<PhpToken> $global */
    $global = PhpToken::tokenize('<?php class X {}');
    expect(cachePayloadNamespace($global))->toBe('');
});

test('正のコントロール: 無関係な interface の implements は迂回にしない', function (): void {
    $fixture = <<<'PHP'
    <?php
    namespace App\Demo;
    use Countable;
    use JsonSerializable;
    class Fixture implements Countable, JsonSerializable {}
    PHP;

    $result = cachePayloadCollectFromSource($fixture, 'fixture.php');
    expect($result['subclassDeclarations'])->toBe([]);
    expect($result['unclassified'])->toBe([]);
});

test('負のコントロール: ArrayAccess 書き込みを 2 形とも検出する', function (): void {
    $fixture = <<<'PHP'
    <?php
    namespace App\Demo;
    use Illuminate\Cache\Repository;
    class Fixture {
        public function run(Repository $cache, $dto): void {
            $cache['a'] = $dto;
            $cache['b'] ??= $dto;
            $read = $cache['c'];
        }
    }
    PHP;

    $result = cachePayloadCollectFromSource($fixture, 'fixture.php');
    expect($result['writes'])->toHaveCount(2);
    expect(array_map(fn (array $w): string => $w['method'], $result['writes']))
        ->toBe(['offsetSet', 'offsetSet']);
    expect($result['unclassified'])->toBe([]);
});

test('正のコントロール: 自己テスト目録に登録された未知 API だけを未分類から外す', function (): void {
    // ★実行時層の自己テストは「4 分類のどれでもない API 名」を意図的に呼ぶ。
    //   目録に載っている呼び出しだけを迂回として数え、載っていないものは従来どおり落とす。
    $fixture = <<<'PHP'
    <?php
    namespace App\Demo;
    use Illuminate\Cache\Repository;
    class Fixture {
        public function run(Repository $cache): void {
            $cache->guardProbeUnknownMethod();
        }
    }
    PHP;

    $registered = cachePayloadCollectFromSource($fixture, CACHE_PAYLOAD_BOUNDARY_SELFTEST_FILE);
    expect($registered['unclassified'])->toBe([]);
    expect($registered['bypassCounts'])
        ->toBe([CACHE_PAYLOAD_BOUNDARY_SELFTEST_FILE.'::guardprobeunknownmethod' => 1]);

    $unregistered = cachePayloadCollectFromSource($fixture, 'app/Demo/Fixture.php');
    expect($unregistered['unclassified'])->toHaveCount(1);
    expect($unregistered['bypassCounts'])->toBe([]);
});

test('正のコントロール: guard 付き受け皿の生成そのものは迂回にしない', function (): void {
    // ★L4d が宣言側 (extends) で塞いでいるので、自前クラスの生成は通ってよい。
    $fixture = <<<'PHP'
    <?php
    namespace App\Demo;
    use Tests\Support\Cache\PlainDataGuardedRepository;
    class Fixture {
        public function run($store): void {
            $repository = new PlainDataGuardedRepository($store, []);
        }
    }
    PHP;

    $result = cachePayloadCollectFromSource($fixture, 'fixture.php');
    expect($result['bypassCounts'])->toBe([]);
    expect($result['surface'])->toBeFalse();
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
