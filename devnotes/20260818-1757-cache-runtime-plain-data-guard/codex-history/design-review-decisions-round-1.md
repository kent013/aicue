# 対応マトリクス: design-review Round 1

## [Critical] Store への直接到達を L4 が閉じ切れていない

- 判断: **大半は対応する。`getStore()` の実行時 hard fail だけは反論する (vendor 実測に基づく)**
- 対応 (静的層 = L4):
  - **具体 store の生成・型注入を検出する**。判定規則を
    「解決した FQCN が `Illuminate\Contracts\Cache\Store` である、または
    `Illuminate\Cache\` で始まり `Store` で終わる」に広げ、
    **生成 (`new`) と型宣言の両方**を迂回として扱う (正負例つき)
  - **受け手型の継承・実装の宣言そのもの**を迂回として検出する (L4d 新設)。
    `任意の Repository サブクラスを作って逃げる`経路は `new` を追うより
    **宣言側で塞ぐ**方が確実である。許すのは `tests/Support/Cache/` の名指し 2 ファイルだけ
  - `Illuminate\Contracts\Cache\Store` は既に受け手型なので、
    `public function __construct(Store $store)` + `$store->put(...)` は
    **現行でも L2 の書き込みとして検出される** (型宣言から受け手名を作る既存分岐)。
    L4 で「Store 型の注入自体」も落とすので二重に塞がる
- 対応 (実行時層):
  - `setStore()` を hard fail する (vendor に呼び出し元が 0 件であることを確認済み)
  - `__call()` を override し、**macro が登録されている名前への呼び出しを hard fail** する。
    これにより「同一テスト内で登録し、使い、消す」形も**使用時点で**捕まる
    (概念設計で保証範囲外としていた穴が閉じる)。
    macro でない素通し (store 固有メソッド) は親へ委譲する —
    `Repository` が持つ名前は `__call` に来ないので、素通しで payload を書ける API は無い
- **反論: `getStore()` の実行時 hard fail は採れない**。vendor 自身が正常系で呼んでいる。
  実読の根拠:
  - `Illuminate\Cache\RateLimiter::withoutSerializationOrCompression()` (299 行) が
    `$this->cache->getStore()` を呼ぶ。これは `hit()` / `increment()` の経路なので
    **流量制限を使うテストがすべて落ちる**
  - `Illuminate\Cache\Repository::flushLocks()` (805 行) が**自分自身で** `$this->getStore()` を呼ぶ
  - `Illuminate\Console\Scheduling\CacheEventMutex` / `Illuminate\Console\CacheCommandMutex` /
    `Illuminate\Cache\Limiters\ConcurrencyLimiterBuilder` / `Illuminate\Cache\MemoizedStore` も呼ぶ
  よって `getStore()` は**静的層で 0 件 pin する**(正典 AG-151 が求めているのも静的な hard fail である)。
  実行時に落とせないことは**保証しないもの**として docblock に根拠つきで書く
- 対応: **`Cache::extend()` が `repository()` を迂回することを振る舞いテストで実証する**
  (独自 creator を登録して解決し、返る受け皿が guard 付きでないことを固定する)。
  実証できなければ L4 の説明を書き直す

## [Critical] 意図的違反テストが accumulator を残し afterEach で失敗する

- 判断: **対応する (指摘のとおり。このままでは全負例が落ちる)**
- 対応内容: 共通 helper `expectCachePayloadViolation(Closure, string ...$expectedFragments)` を
  `tests/Support/Cache/` に置き、
  (1) `CachePayloadViolation` が投げられること、
  (2) `drainForAssertion()` の結果が**ちょうど 1 件**で method / key / 種別を含むこと、
  (3) drain 後に accumulator が空であること
  の 3 つを毎回まとめて検査する。`tags()` / Closure / 各合流テストにも同じ helper を使う。
  `reset()` は `$inspected` も 0 へ戻す。

## [Critical] `null` の許可が AGENTS.md と矛盾している

- 判断: **null は許可する。ただし「詳細設計だけで広げる」のではなく、AGENTS.md を正典に合わせる
  変更を同じ PR に含める**
- 根拠: 家系の裁定 AG-151 の本文は実行時層の判定を
  「素データ (配列・文字列・数値・真偽値・**null**) 以外なら違反として落とす」と定めており、
  **正典の側が null を含んでいる**。本リポジトリの AGENTS.md の列挙が正典より狭い。
  実務上も `Cache::put($k, null)` / `remember` の callback が null を返す形は
  「保存された null」と「不在」を PHP が区別できないため**クラス情報を一切運ばない** =
  逆シリアライズの攻撃面にならない。
  なお家系では motivation が null を外しており、割れている論点であることは承知している。
  本リポジトリは**正典の文言に合わせる**。
- 対応内容: S10 に「素データの定義に null を明記する」を追加し、
  AGENTS.md 不変条件 11 と `docs/app-integration-guide.md` §7 不変条件 6 の列挙を
  「配列 / 文字列 / 数値 / 真偽値 / null」に直す。設計側の記述もこれに統一する。

## [Critical] S1 の提示コードが閉じた resource を通す

- 判断: **対応する (指摘のとおり、説明とコードが食い違っていた)**
- 対応内容: 「変更後コード」を直し、`is_scalar($value) || $value === null` を明示して
  **それ以外を `UNKNOWN_TYPE(<型>)` 違反にする**分岐を本文へ入れた。S5 の負例に閉じたリソースを追加。

## [Critical] extender の `$manager::class` が mixed に対して安全でない

- 判断: **対応する**
- 対応内容: `! $manager instanceof CacheManager || $manager::class !== CacheManager::class` に直した。

## [Critical] 静的層の語彙・目録と追加コードが一致していない

- 判断: **すべて対応する**
- 対応内容:
  - `rememberwithwarmth` を `CACHE_PAYLOAD_WRITE_METHODS` へ追加
  - **ArrayAccess 書き込み (`$cache[$k] = $v` / `??=`) の検出を新設**
    (受け手名の変数の直後が `[` … `]` で、その次が `=` / `??=` なら `offsetset` の書き込みとして記録)。
    正負例・未解決を落とす分岐・空振り検知・docblock の保証範囲を同じ PR で揃える
  - S5 のヘルパは**受け皿を型宣言の引数で受ける** (`Repository $cache`)。
    こうしないと `$cache = Cache::store('array')` 形は静的層の受け手名にならず、
    **書き込みが L2 に現れない** (静的層が申告を要求しない = 目録の意味が消える)
  - `BootTimeCacheWriteProbeProvider` を変更ファイル一覧・L3 (role=write)・L2 (kind=guard-selftest) へ登録
  - `guard-implementation` を名乗れるパスを **`tests/Support/Cache/` 配下に固定**する
    (role 判定にパスを渡す)。`parent::` 呼び出しは受け手型の解決対象ではないので
    「キャッシュ API 呼び出し 0 件」と衝突しないことを確認済み
    (`extends Repository` は型参照であって呼び出しではない)

## [Critical] W5 の字句解析案では vendor 本体を解析できない

- 判断: **対応する**
- 対応内容: 方式を変えた。`<?php ` を前置して token 化し、**コメント・空白を落とした token 列を
  期待値と完全一致で pin する** (文の分割をやめる)。負例は
  (a) token の追加、(b) 並べ替え、(c) 結線位置を bootstrap の前後で入れ替えた列 の 3 形。

## [Critical] S0 の「各 1 回」で全露出は測れない

- 判断: **対応する (指摘のとおり。guard はその場で throw するのでテストあたり 1 件しか見えない)**
- 対応内容: **計測 → 是正 → 再計測を違反 0 まで反復する**形へ改めた。
  `runtime-exposure.md` に各回 (wave) の累積を残す。
  「10 ファイル以上で差し戻し」の判定は**累積した一意ファイル数**で行う。
  実装順を「S5/S6 の負例を先に赤くする → S1〜S4 → 計測と是正の反復 → S7 → S9〜S11」に一本化した。

## [Warning] S4 vendor の `createApplication()` から処理を削るのは不要な分岐

- 判断: **対応する**
- 対応内容: vendor 本体を**忠実に写し**、`require` の後・`bootstrap()` の前に結線を 1 行挟むだけにした。
  `traitsUsedByTest` と cached config/routes の分岐も残す
  (`CachedState` / `WithCachedConfig` / `WithCachedRoutes` は import できる。
  `markConfigCached()` / `markRoutesCached()` は protected なので継承先から呼べる)。
  これに伴い **W4 (両 trait の不使用 pin) は不要になったので削除**し、W5 に一本化した。

## [Warning] S4 レーン集合の照合

- 判断: **対応する**
- 対応内容: ブロック数を数えるのではなく、`->in(...)` の引数集合が
  `{Feature, Unit}` / `{Architecture}` / `{Browser}` の 3 つちょうどであることを照合する。

## [Warning] S5 検査 15 の主張

- 判断: **対応する**
- 対応内容: 名前と主張を「provider が握り潰しても accumulator に残る」に訂正した。
  afterEach の結線自体は S6 の gate が保証する、と分担を明記した。

## [Warning] S5 第 2 アプリの隔離

- 判断: **対応する**
- 対応内容: 隔離を専用 helper (`tests/Support/Cache/IsolatedApplicationProbe.php`) に切り出し、
  `Container` の instance / `Facade` の解決済みインスタンスと facade application の
  **退避と復元の順序**を固定する。復元順そのものを検査するテストを追加。

## [Warning] S3 flush の macro pin / RateLimiter の必須化 / `$inspected`

- 判断: **すべて対応する**
- 対応内容:
  - `flushAndFailIfStray()` の先頭で macro を「検査して記録し復元」→ accumulator を判定 →
    `finally` では記録せず復元・消去、という流れに固定した
    (`pinMacros()` と `restoreMacros()` に分ける)
  - `RateLimiter` の検査は **`resolved()` でなければ失敗**にする
    (本リポジトリは `AppServiceProvider::boot()` で名前付き制限を多数登録するので必ず解決される。
    解決されなくなったら前提が崩れたということなので落とす)
  - `registerBeforeBootstrap()` / `reset()` の両方で `$inspected` を 0 に戻すことを明記

## [Warning] S1 ノード数の数え方

- 判断: **対応する**
- 対応内容: 「根を含む総ノード 10000」であることをテスト名と生成ヘルパに明記する。

## [Warning] S2 「末端 4 メソッドで足りる」の但し書き / extend の実証

- 判断: **対応する**
- 対応内容: 「標準 `Repository` API の**値の合流**についてのみ成立する。`Store` 境界の完全性は
  別問題で、そちらは静的層の L4 が担う」と分けて書いた。`Cache::extend()` の迂回性は
  振る舞いテストで実証する。

## [Warning] S7 `new PlainDataGuardedRepository` の扱い

- 判断: **対応する (宣言側で塞ぐ)**
- 対応内容: L4d (受け手型の継承・実装の宣言を名指し 2 ファイルだけに許す) で塞ぐ。

## [Warning] S8 件数の数え方

- 判断: **対応する**
- 対応内容: 「一意ファイル数 / 違反サイト数 / 違反件数」を分けて記録し、
  閾値は**一意ファイル数**であることを明記した。

## [Warning] S10 保証の過大表現

- 判断: **対応する**
- 対応内容: S1/S2/S7 の修正後の実際の保証範囲に合わせて書く。
  とくに `getStore()` は**静的層だけで塞ぐ**ことを明記し、
  「実行時層が vendor 由来をすべて見る」とは書かない。

## [Suggestion] S9 `PRISM_PROMPT_CACHE` の残存確認

- 判断: **対応する** (追跡下の全ファイルを文字列検索して 0 件を確認する手順を S9 に追加)

## [Suggestion] S11 D30 の根拠の同期

- 判断: **対応する** (実装後に差が消えていたら登録しない、という既存方針に加え、
  `Cache::extend()` の実証結果で根拠が変わったら D30 の説明も直す、と明記)
