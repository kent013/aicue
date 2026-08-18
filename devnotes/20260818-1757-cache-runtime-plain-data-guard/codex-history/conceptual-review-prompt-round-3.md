# Round 3

Round 2 の指摘への対応マトリクスと、修正後の概念設計を提示します。
判定を更新してください (APPROVED / CHANGES_REQUESTED)。

## 対応マトリクス

# 対応マトリクス: conceptual-review Round 2

## [Critical] 3-1 Macroable の境界が閉じていない (登録 → 直接書き込み → 解除)

- 判断: **対応する (一部は保証範囲外として明記する)**
- 根拠 (vendor 実読): `Illuminate\Cache\Repository` は `Macroable` を use しており、
  `Repository::macro()` で登録した closure は Repository インスタンスへ束縛されるため
  `$this->store->put(...)` へ直接到達できる。末端 4 メソッドを通らないので accumulator に残らない。
  一方 `Illuminate\Support\Facades\Facade` は **Macroable を use していない**ことを実読で確認したので、
  `Cache::macro(...)` は最終的に `Repository::macro(...)` へ落ちる (facade 独自の macro 経路は無い)。
- 対応内容: 3 段で塞ぐ。
  1. **静的層 L4** に `macro` / `mixin` / `flushMacros` をキャッシュ受け手型に対する迂回語彙として
     追加し、`app` / `routes` / `database` / `tests` で **0 件 pin** する
     (リポジトリ内からの登録は完全に塞がる)
  2. **実行時層**は `install()` と `flushAndFailIfStray()` の**両方**で
     `Repository::$macros` が空であることを検査し、空でなければ違反として記録して復元する
     (vendor が登録して残す形はここで落ちる)
  3. **負例を必須にする**: 「macro を登録 → macro 経由で `$this->store` へ直接書き込む →
     flush で違反になる」を振る舞い検査に置く
- 残る限界 (保証しないものへ明記): **同一テストの中で登録し、使い、`flushMacros()` で消す**形は
  どちらの層でも検出できない。ただしこれは vendor がテスト実行中にそれを行った場合に限られ、
  リポジトリ内のコードでは (1) が塞ぐ。
  なお **AG-151 が列挙した v2 の境界迂回は「保管先の直接取得・受け皿の直接生成・拡張登録」の
  3 形**であり、macro はそこに含まれていない。本設計はそれを**超えて**塞ぎにいっている。

## [Critical] 5-1 RateLimiter を保証範囲外にするのは v2 と衝突する

- 判断: **指摘を受け入れて設計を変える (保証範囲外にしない)**
- 根拠: 指摘のとおり、`ThrottleCoverageInventoryTest` は流量制限の**配線**を守る検査であって、
  キャッシュへ入れる値の形を守る検査ではない。責務が違うことは除外の根拠にならない。
- 対応内容: **guard 導入時に `RateLimiter` の内部の受け皿を guard 付きへ差し替える**
  (家系の先行実装 aigenba と同じ形)。`Illuminate\Cache\RateLimiter::$cache` は protected で
  公開の setter が無い (vendor 実読で確認) ため反射で差し替える。
  **`forgetInstance(RateLimiter::class)` はしない** — 名前付き制限の定義
  (`RateLimiter::for(...)`。本リポジトリでは `AppServiceProvider::boot()` に多数ある) は
  起動時に 1 度だけ登録されるので、インスタンスを捨てると二度と復活しない
  (aigenba は実測で 365 件のテストが落ちたと記録している)。
  プロパティが消えていたら**その場で例外**にする (Laravel 更新で pin が空振りしない)。
  これに伴い「保証しないもの」から RateLimiter を外し、スコープ外の項目からも削除した。

## [Warning] 2 「計測」が一時免除にならないようにせよ

- 判断: **対応する**
- 対応内容: **guard に計測用の許可モードを足さない**。計測は「違反で赤くなったテスト実行の
  出力から一覧を採る」だけで行う (PHPUnit / Pest は失敗しても残りのテストを走らせるので、
  1 回の実行で全件が採れる)。この方針を実装順の工程 1 に明記した。

## [Warning] 3-2 `repository()` は公開 API とは限らない / シグネチャ互換

- 判断: **対応する**
- 対応内容: 表の見出しを「主な公開メソッド」から「**override 対象メソッド**」に改め、
  **可視性を含む vendor シグネチャ完全互換**を詳細設計の必須条件として明記した。

## [Warning] 4-1 「静的層だけが見える実例は計測工程で分かる」は成立しない

- 判断: **指摘のとおり誤り。対応する**
- 対応内容: 実行されない書き込みは実行時の計測でも見つからない。実例を確認できていないので
  **「原理上の反例」と明記**し、実在を主張しない書き方に直した。

## [Warning] 4-2 G を「no-op」と断定できない

- 判断: **対応する**
- 対応内容: 「現行コードを確認した範囲では利用箇所が無く、観測できる挙動の変化は見込まれない」
  という限定した書き方に直した。

## [Warning] 5-2 工程 1 の完了条件と「各段で全レーン緑」が両立しない

- 判断: **指摘のとおり。対応する**
- 対応内容: 工程 1 の完了条件を
  「guard の正負コントロールが成立し、全レーンを完走して露出の一覧を採れたこと」に変えた。
  「全レーン緑」は工程 3 以降の完了条件にした。

## [Warning] 6 保証外を残したまま v2 相当を名乗らない

- 判断: **対応する**
- 対応内容: RateLimiter を保証範囲へ戻し、macro も 3 段で塞いだので、
  **v2 の 4 要素はすべて満たす**。残る限界 (同一テスト内での macro の登録・使用・解除など) は
  v2 の要求に含まれない事項なので、台帳へは v2 として報告する。
  ただし報告文には**保証しないものを併記する**ことを完了条件に加えた。

## [Warning] 7 `Repository::$macros` の型安全と復元契約

- 判断: **対応する (詳細は Phase 2)**
- 対応内容: 概念設計に「プロパティ不在なら例外 / 取得値の型検査 / 例外時も `finally` で復元」の
  3 点を契約として書き、具体的な型注釈は詳細設計で確定するとした。

---

## 修正後の概念設計 (全文)

# 概念設計: キャッシュ素データ規約の実行時層 (正典 v2 追従)

## 背景・課題

家系の機能台帳 lctl の feature `cache-payload-plain-data` は、裁定 AG-151 (2026-08-10) で
**正典 v2** へ上がった。v2 の必須要素は 4 つである。

1. **静的層** = 書き込みサイトの全数申告目録を持ち、未申告の経路を deny-by-default で落とす
2. **実行時層** = テスト実行中のキャッシュ書き込みを**受け皿の側で捕まえ、保管先へ渡す前の値を
   再帰的に検査する**
3. **設定の二段 pin** = `serializable_classes` を false で固定し、宣言と実効値の両方を pin する
4. **境界迂回の hard fail** = 保管先の直接取得・受け皿の直接生成・拡張登録を落とす

aicue の現在地 (台帳の判定は `update_pending` / version「v1 (静的層のみ)」/ target v2)。

| 要素 | aicue の現状 |
|---|---|
| (1) 静的層 | **実装済み**。`tests/Architecture/CachePayloadPlainDataGateTest.php` (1455 行、検査 1〜9 + 正負コントロール) |
| (2) 実行時層 | **完全に不在** |
| (3) 設定の二段 pin | **実装済み**。宣言 pin = `ConfigHardeningTest` (config ファイル直接評価)、実効値 = 上記 gate の検査 6 |
| (4) 境界迂回の hard fail | **部分的**。`getStore()` は CHAIN として辿るが落とさない。`Cache::extend()` は NON_WRITE に分類されて素通し。`new Repository(...)` / `new CacheManager(...)` は誤検出回避のため**意図的に走査から外している**。`tags()` は CHAIN で通す |

さらに、gate 冒頭 10-16 行には次の主張がコメントとして残っている。

> ★なぜ静的検査か (実行時検出では捕まらない):
> テストレーンは phpunit.xml で CACHE_STORE=array、config/cache.php の array store は
> 'serialize' => false。**オブジェクトを put してもそのまま返る = テストは緑になる**。
> (中略) 実行時 detector (KeyWritten 購読等) は原理的にこの穴を塞げない。

この主張は AG-151 が名指しで**棄却した**。棄却の理由は「実行時層は直列化の検査ではなく**値の検査**
だから」である — 受け皿 (`Illuminate\Cache\Repository`) を包んで `put()` に渡された値そのものを
再帰的に見る形なら、保管方式が直列化しない array store でも同じように発火する。
したがって本リポジトリのコメントは**事実として誤り**であり、書き直しが要る。
この誤りを放置すると「実行時層は要らない」という判断が将来のセッションへ再生産される。

### 2 層が相補である根拠 (aicue で確認した両方向)

正典が 2 層を要求する根拠は「どちらも他方を包含しない」ことである。aicue で実読して確認した
両方向は次のとおり。**aigenba が挙げた例をそのまま写さない** — あちらは対象パッケージが
リポジトリ内の同梱パッケージで静的層から見えるが、aicue では composer 依存 (`vendor/`) であり
**静的走査の母集団 (`app` / `routes` / `database` / `tests`) の外**だからである。

- **実行時層だけが見えるもの**: `vendor/` 配下からの書き込み。静的走査の母集団に入らないので、
  テストがその経路を踏んだときに値を見られるのは実行時層だけである。
  実例: `vendor/kent013/laravel-prism-prompt/src/PromptTemplate.php` の `fromYaml()` が
  `Cache::store(...)->put($cacheKey, $instance, $ttl)` で **PromptTemplate オブジェクトそのもの**を
  既定ストアへ入れる (`config/prism-prompt.php` の `cache.enabled` は
  `env('PRISM_PROMPT_CACHE', true)` = **既定で有効**)。
  ★ただし現時点で `fromYaml()` の呼び出し元は本リポジトリにも同パッケージ内にも 0 件であり
  (窓口 `PromptDefense` が使う `Prompt::load()` は `loadMetadata()` 経由でここを通らない)、
  **いまはどちらの層も発火しない休眠経路**である。呼び出し元が生まれ、かつテストがそれを踏んだ
  ときに初めて実行時層が見る。**この経路を今日閉じるのは実行時層ではなく施策 G (設定による閉鎖)
  の効果である** — 効果の帰属を混ぜない
- **静的層だけが見えるもの**: `app/` `tests/` にありながら**テストが 1 度も踏まない**書き込み。
  実行時層は実行されないものを永久に見ない。
  ★これは**原理上の反例**であり、本リポジトリでの実在は主張しない (現在の書き込みは
  `FxRateService` の 1 件だけで、そこは実際にテストが踏む)。**実行されない書き込みは
  実行時の計測でも見つからない**ので、露出の計測工程でも実在は確かめられない。
  それでもキャッシュ書き込みの追加は日常的に起こるため、
  **新しい経路が申告なしに増えないこと**は静的層でしか守れない
- **`Repository::__call()` の素通し**: `Repository` は未定義メソッドを
  `$this->store->$method(...)` へ素通しする。受け皿を包んでもこの経路の値は見えないが、
  未知のキャッシュ API 呼び出しは**静的層の検査 1 (4 分類のどれにも属さない API を落とす)** が
  受け持つ。これも 2 層が噛み合っている実例である

### なぜ今やるか (使命との関係)

AI-CUE は SOP と生成シナリオ、撮影テイクという**顧客の業務知識**を扱う。キャッシュ経由の
逆シリアライズは、そこへ到達する経路を 1 本増やす。規約 (AGENTS.md セキュリティ不変条件 11) は
既にあるが、v1 の静的層が保証しているのは「**申告なしに書き込み経路を増やせない**」ことだけで、
「**申告された値が実際に素データである**」ことは gate 自身が「保証しないもの」として明記している
(目録の `payload` 欄は人間の申告である)。後者を機械で保証するのが実行時層である。

## 改善アイデア

**実行時層を新設し、テストの全レーンへ結線する。あわせて静的層の誤ったコメントを訂正し、
境界迂回の hard fail を v2 の水準まで引き上げる。**

### 方針 1: 受け皿 (Repository) を包む。イベント購読にはしない

`Illuminate\Cache\Events\KeyWritten` の購読は差し替え可能な境界で、テスト本体の `Event::fake()` や
store 設定の `'events' => false` で無効化できる。`Illuminate\Cache\Repository` の書き込みメソッドは
イベント層より**下**にあるため、どちらの影響も受けない。家系の 2 実装 (laravel-claude-template /
aigenba) はどちらも Repository 境界を採っており、本リポジトリもそれに揃える。

包む口は `CacheManager::repository()` である。vendor の組み込み driver 生成
(`createArrayDriver()` / `createDatabaseStore()` / `createFileDriver()` / `memo()` 等) は
**いずれも `repository()` を通る**ことを実読で確認した。独自 driver
(`Cache::extend()` の creator) だけは `repository()` を通る保証が無いので、静的層で落とす。

### 方針 1b: 値を運ぶ公開 API の funnel matrix (vendor 実読で確定)

`Illuminate\Cache\Repository` の公開 API を実読し、3 分類に棚卸しした。

| 分類 | API | 扱い |
|---|---|---|
| **値の末端** (ここを包めば足りる) | `put` / `add` / `forever` / `putMany` | guard 付き受け皿が override して検査する |
| 末端へ合流する糖衣 | `set`→`put` / `setMultiple`→`putMany` / `remember`→`rememberWithWarmth`→`put` / `sear`→`rememberForever`→`forever` / `flexible`→`putMany` / `offsetSet`→`put` / `putMany($v, null)`→`putManyForever`→`forever` | override 不要。**合流が将来変わったら落ちる**ように、振る舞い検査が実 API 経由で全部叩く |
| 値を運ばない | `get` / `many` / `pull` / `has` / `missing` / `forget` / `flush` / `touch` (期限だけ) / `increment` / `decrement` (整数のみ) / `string` `integer` `float` `boolean` `array` (読み出しの型付き糖衣) | 検査しない |
| **禁止する (迂回)** | `tags()` / `getStore()` / `setStore()` / `Cache::extend()` / `macro()` / `mixin()` / `flushMacros()` / 受け手型の直接生成 | 実行時 hard fail (`tags`) + `$macros` の毎回 pin + 静的層 L4 で 0 件 pin |

`tags()` を禁止にする理由は 2 つある。

1. `Repository::tags()` は `new TaggedCache($this->store, ...)` を**素で生成**する
   (`TaggedCache extends Repository`)。guard 付き受け皿を継承しても、そこから先の書き込みは
   検査を通らない = **実行時層の穴**である
2. **本番の保管方式 (database store) はタグに対応しない** (`supportsTags()` が false)。
   タグを使う書き方は本番で例外になるので、そもそも落とすのが正しい。
   現在の使用箇所は 0 件 (静的 gate の負例 fixture が nowdoc 内に 1 件持つだけ)

### 方針 1c: macro 経由の迂回を 3 段で塞ぐ

`Repository` は `Macroable` を use しているため、`Repository::macro()` で登録した closure は
Repository インスタンスへ束縛され、**`$this->store->put(...)` へ直接到達できる**。
末端 4 メソッドを通らないので accumulator にも残らない。次の 3 段で塞ぐ。

1. **静的層 L4**: `macro` / `mixin` / `flushMacros` をキャッシュ受け手型に対する迂回語彙へ追加し、
   走査対象 4 ディレクトリで **0 件 pin** する。リポジトリ内からの登録はこれで完全に塞がる
   (`Illuminate\Support\Facades\Facade` は `Macroable` を use していないことを実読で確認したので、
   `Cache::macro(...)` も最終的に `Repository::macro(...)` へ落ちる = 同じ語彙で捕まる)
2. **実行時層**: `install()` と `flushAndFailIfStray()` の**両方**で `Repository::$macros` が
   空であることを検査し、空でなければ違反として記録したうえで既定へ復元する
   (vendor が登録して残す形はここで落ちる)
3. **負例を必須にする**: 「macro を登録 → macro 経由で保管先へ直接書き込む → flush で違反になる」を
   振る舞い検査に置く

**保証しないもの**: 同一テストの中で登録し、使い、`flushMacros()` で消す形はどちらの層でも
検出できない (vendor がテスト実行中にそれを行った場合に限る)。
なお AG-151 が列挙した v2 の境界迂回は「保管先の直接取得・受け皿の直接生成・拡張登録」の 3 形で
あり、macro はそこに含まれていない。本設計はそれを**超えて**塞ぎにいっている。

`Repository::__call()` の素通しそのもの (macro を経由しない未知メソッドの転送) は、
静的層の検査 1 (4 分類のどれにも属さない API を落とす) が受け持つ。
`Repository::$unserializableClassHandler` は**不完全クラスを検出したときの通知口であり迂回口では
ない**ので pin しない。使う必要が出たら L4 の議題として起こす (保証しないものへ明記する)。

### 方針 2: 結線の形は既存の guard 慣行 (StrayHttpRequestGuard / StrayLlmCallGuard) に揃える

本リポジトリには既に同型の実行時 guard が 2 本あり、`tests/Pest.php` の全レーン
(Feature/Unit・Architecture・Browser) で `install()` (beforeEach) /
`flushAndFailIfStray()` (afterEach) / `reset()` (finally) の 3 点セットで結線されている。
キャッシュ guard も**同じ形**にする。

- **違反は「その場で例外」と「accumulator への記録」の両方**にする。片方だけでは足りない —
  アプリ側の `catch (Throwable)` (準拠実装 `FxRateService` 自身が読み戻し失敗時に握り潰す形を持つ)
  で例外が消えても、afterEach の flush で必ず赤くなる必要がある
- **意図的に違反を起こす自己検査**のために `drainForAssertion()` を持たせる
  (`StrayLlmCallGuard` と同じ)
- レーンごとに既定を変えない (既定が違うと「どのレーンなら通るか」を覚える必要が生まれる。
  外部 HTTP 出口の既定拒否で既に採った判断と同じ)

### 方針 3: 露出した既存違反は「直す」を既定にし、免除の口を作らない

実行時層を全レーンへ結線すると、array store の性質に守られて緑だった書き込みが露出しうる。
本設計は**免除目録を持たない**。理由は 2 つ。

1. 家系の正典が「例外を作らない」を明示している (AG-107 由来。aigenba は許可一覧の撤去まで行った)
2. 事前調査 (実読) の結果、**露出する見込みが小さい**

事前調査で確認したこと:

- `app/` のキャッシュ書き込みは `FxRateService::put` の 1 件だけで、渡すのは
  `FxSnapshotDto::toArray()` の連想配列である
- `tests/` の書き込みは静的層の目録 (L3 面) と exact-fit で、現在 `write` 役割は 0 件
  (`lock-only` 6 件 + `driver-handoff` 1 件 + `write` は `FxRateService` のみ)
- vendor 側でテストが実際に踏む書き込みは、いずれも素データであることを実読で確認した —
  Laratrust の役割 / 権限キャッシュ (`->get()->toArray()` の配列)、
  `Illuminate\Cache\RateLimiter` (整数と時刻の整数)、
  `Illuminate\Console\Scheduling\CacheEventMutex` / `CacheSchedulingMutex` (真偽値)、
  `Illuminate\Queue\Worker` の未処理例外カウンタ (整数)、
  同梱パッケージ `laravel-prism-prompt` の未知モデル警告の抑止 (整数)
- Livewire の `#[Computed(persist: true)]` / `#[Computed(cache: true)]` は
  任意の戻り値をキャッシュへ入れる形だが、**本リポジトリにも Filament にも使用箇所が 0 件**である

**ただしこの見込みを実装の前提にはしない**。実装の第 1 工程は「計測」であり、
実測してから修正に入る (下の「実装順」)。

### 方針 4: 境界迂回の hard fail は静的層の責務として既存 gate に足す

v2 要素 (4) は静的な性質 (「そういう書き方をしていない」) なので、既存の静的 gate に足す。
既存 gate の責務分担 (L1 語彙 / L2 書き込み経路 / L3 面) は壊さず、
**L4 = 境界迂回**を新しい検査として追加する。

- `Cache::extend()` を NON_WRITE から外して迂回語彙へ移す
  (独自 creator は `repository()` を通る保証が無く、実行時層の被覆から抜ける口になる)
- `getStore()` / `setStore()` / `tags()` を迂回語彙へ移す (`getStore` は現在 CHAIN)
- `macro()` / `mixin()` / `flushMacros()` を迂回語彙へ加える (方針 1c)
- 受け手型の**直接生成** (`new Repository` / `new CacheManager` / `new TaggedCache`) を検出する
- 迂回は **0 件で pin** する。ただし**実行時層の実装ファイル自身**は構造上これらを避けられない
  (`extends Repository` / `Store` 型の引数) ため、**名指しの 1 群**として扱い、
  「`$store` は guard 付き受け皿の第 1 引数以外に現れない」という構造条件を機械検査する
  (laravel-claude-template と同じ形)
- `memo()` は `repository()` を通るので実行時層は効くが、**現在 4 語彙のどれにも属さない**ため
  静的層の検査 1 が既に落とす。**意図的に未分類のまま置く** (使うことになったら
  scoped binding の扱いを含めて設計し直す必要があるため、黙って通さない)

### 方針 5: 誤った説明の訂正と、2 層の責務分担の明文化

- 静的 gate 冒頭の「実行時 detector は原理的にこの穴を塞げない」を削除し、
  **2 層構成 (静的層 = 申告の全数性 / 実行時層 = 値の実体) の責務分担**を書く
- AGENTS.md セキュリティ不変条件 11 と `docs/app-integration-guide.md` §7 不変条件 6 の
  「静的検査で塞ぐ」という記述を「静的層 + 実行時層の 2 層で塞ぐ」に直す
- **保証しないものの正本は実行時層の docblock に置き**、AGENTS.md / guide には写さない
  (2 か所に書くと必ず食い違う。ドメイン規約 17 と同じ扱い)

## 期待効果

- **使命への貢献**: 顧客の業務知識 (SOP / シナリオ / テイク) を扱うアプリのキャッシュ経路から、
  逆シリアライズによる任意コード実行の余地を機械で塞ぐ。とくに **vendor 由来の書き込みが
  テストで実行されたときの値検査**は、現在まったく効いていない
- **申告の裏取り**: L2 目録の `payload` 欄 (人間の申告) が虚偽なら実行時層が落ちる。
  「静的に緑なのに本番で壊れる」という v1 の残り穴が閉じる
- **家系との整合**: 台帳の判定を `update_pending` (v1) から v2 相当へ進める。
  家系 6 リポジトリのうち v2 実装は 2 本 (laravel-claude-template / aigenba) で、
  aicue が 3 本目になる
- **誤情報の除去**: AG-151 が棄却した主張がコードコメントとして残っている状態を解消する

**期待しないこと (誇張しない)**: 実行時層はテストが**実際に実行した**書き込みしか見ない。
呼び出し元が 0 件の休眠経路 (`PromptTemplate::fromYaml()` 等) は、実行時層では閉じない。

## 実装方針 (概要)

### テストレーン限定の施策 (本番の挙動を変えない)

| # | 施策 | 主な変更ファイル |
|---|---|---|
| A | 実行時層の新設 (値検査器 / guard 付き受け皿 / guard 付き manager / 例外 / guard 本体) | `tests/Support/Cache/` 配下 5 本 (新規) |
| B | 全レーンへの結線 | `tests/Pest.php` |
| C | 実行時層の振る舞い検査 (正負コントロール・合流の実証・上限・自己参照・後始末) | `tests/Feature/Cache/CachePayloadPlainDataGuardTest.php` (新規) |
| D | 結線の pin (レーンごとに install / flush が居ることを deny-by-default で固定) | `tests/Architecture/CacheGuardLaneWiringGateTest.php` (新規) |
| E | 静的層の冒頭コメント訂正 + L4 (境界迂回) の追加 + 目録の役割追加 | `tests/Architecture/CachePayloadPlainDataGateTest.php` |
| F | 規約の明文化の更新 | `AGENTS.md` / `docs/app-integration-guide.md` / `docs/architecture.md` |
| H | テンプレートとの差の登録 (差が出る場合) | `docs/template-divergence.md` |

### 本番の設定に触る施策 (独立して判断できるように分けてある)

| # | 施策 | 主な変更ファイル |
|---|---|---|
| G | 同梱パッケージのオブジェクトキャッシュ経路を設定で閉じる | `config/prism-prompt.php` + `tests/Feature/Config/ConfigHardeningTest.php` |

G の内容と根拠:

- `config/prism-prompt.php` の `cache.enabled` を **`env()` を介さず `false` を直書き**にする
  (環境変数で再び有効にできる状態を残さない)
- `ConfigHardeningTest` で**宣言 (config ファイル直接評価) と実効値 (`config()`) の両方**を pin する
  (既存の `serializable_classes` の二段 pin と同じ形)
- 根拠: 規約 (AGENTS.md 不変条件 11) に反する書き込みを実際に行うコードが同梱パッケージに実在し、
  その有効・無効を決める設定を**本リポジトリが所有している**。所有しているなら既定で無効にするのが
  規約の当然の帰結である
- **現行コードを確認した範囲では利用箇所が無く、観測できる挙動の変化は見込まれない**
  (`fromYaml()` の呼び出し元が静的には 0 件。動的呼び出しやパッケージ内部の将来の経路まで
  不在であることは保証しない)。効果はパッケージ更新や利用開始で呼び出し元が生まれたときの
  fail-safe である

### 実装順

| 工程 | 内容 | 完了条件 |
|---|---|---|
| 1 | **計測**: guard を仮結線して `composer test` と `composer test:browser` を 1 度走らせ、露出した違反を**全件** `devnotes/{dir}/runtime-exposure.md` へ記録する (件数 / ファイル / 呼び出し元 / 値の型)。**修正はここではしない** | guard の正負コントロールが成立し、**全レーンを完走して**露出の一覧を採れたこと (この時点で赤いのは想定どおり) |
| 2 | 実行時層と結線 (A / B / C / D) | 施策 C / D のテストが緑 |
| 3 | 露出の是正 (下の判断基準で処理する) | **全レーン緑** |
| 4 | 静的層の訂正と L4 (E) | **全レーン緑** |
| 5 | 設定の閉鎖と文書 (G / F / H) | **全レーン緑** + `composer phpstan` / `vendor/bin/pint --test` |

★工程 1 の計測のために **guard へ「違反を許す計測モード」を足さない** (足せば一時免除になる)。
Pest / PHPUnit は 1 件失敗しても残りを走らせるので、**赤くなった実行の出力から一覧が採れる**。

### 露出時の扱い (先に決めておく)

**免除目録は作らない**。露出の出所ごとに次のとおり処理する。

1. **アプリ側 (`app/`)** → **必ず直す**。素の配列にして入れ、読み戻しで組み立て直す
   (準拠実装 `FxRateService` + `FxSnapshotDto`)。あわせて静的層の L2 目録へ登録する
2. **テスト側 (`tests/`)** → **必ず直す**。本番で壊れる書き方をテストが先取りしている状態なので、
   残す理由が無い
3. **vendor 由来** → 次の 3 択で判断する。**guard 側に許可一覧を足す選択肢は取らない**
   (許可一覧の禁止は正典の要素そのものである)。
   (a) 本リポジトリが所有する設定でその機能を閉じられるなら閉じる (施策 G と同じ形)。
   (b) その機能を使わない形へアプリを直せるなら直す。
   (c) どちらもできないなら**実装を完了にせず**、露出の一覧を添えて設計へ差し戻し、
   家系の台帳の議題として起こす (本 TODO は blocked とする)
4. **想定を超える件数 (目安: 10 ファイル以上)** → 実装を止めて設計へ差し戻し、TODO を分割する

### クラスの責務 (シグネチャは詳細設計で確定する)

| クラス (`tests/Support/Cache/`) | 責務 | override 対象 / 公開メソッド |
|---|---|---|
| `PlainDataInspector` | 値が素のデータかを再帰検査する純関数。深さ・ノード数の上限を持ち、**超過は「証明できなかった」として違反にする** (fail-closed) | 公開: `violations(mixed $value): list<string>` |
| `CachePayloadViolation` | 違反を表す例外 (`RuntimeException` 継承)。書き込み呼び出しの中で throw され、失敗が書き込み元のテストへ帰属する | 公開: `forWrite()` / `forBoundary()` |
| `PlainDataGuardedRepository` | `Illuminate\Cache\Repository` を継承し、値の末端 4 メソッドを override。`tags()` は境界迂回として throw | override: `put` / `add` / `forever` / `putMany` / `tags` |
| `PlainDataGuardedCacheManager` | `Illuminate\Cache\CacheManager` を継承し `repository()` を override。**`Store` 型を参照してよい唯一のサイト** | override: `repository(Store $store, array $config = [])` |
| `PlainDataCacheGuard` | 結線と accumulator。`cache` binding の差し替え、既解決インスタンスの破棄、`RateLimiter` の受け皿の差し替え、`Repository::$macros` の pin と復元 | 公開: `install` / `flushAndFailIfStray` / `reset` / `drainForAssertion` / `inspectedCount` |

- **override するメソッドは、可視性を含めて vendor のシグネチャと完全互換**にする
  (`public function put($key, $value, $ttl = null)` / `public function repository(Store $store, array $config = [])`)。
  これは詳細設計の必須条件であり、互換でない形は PHP のレベルで致命的エラーになる
- **`Repository::$macros` の扱いの契約**: (i) プロパティが存在しなければ**その場で例外**にする
  (Laravel 更新で pin が空振りしない)、(ii) 取得値が配列であることを検査してから使う、
  (iii) 例外が出ても `finally` で必ず既定へ復元する。
  PHPStan level 10 を通す型注釈は詳細設計で確定する
- `mixed` を受けたあとの絞り込み (`is_object` / `is_resource` / `is_array` の順序と
  それぞれの返り値の型) も詳細設計で確定する

## 制約・前提

- **A〜F・H はテストレーン限定**で本番の挙動を変えない (`tests/` だけを触る)。
  **G だけが `config/` を触り、本番の挙動 (テンプレートキャッシュの無効化) を変える**
- **既存 gate (1455 行) の責務分担を壊さない**。L1/L2/L3 の構造・語彙表・正負コントロールは
  そのまま残し、L4 を足す形にする
- **`Illuminate\Cache\RateLimiter` も guard の対象に含める**。
  静的 gate は RateLimiter 型を受け手の母集団から外しているが、それは
  「流量制限の**配線**は `ThrottleCoverageInventoryTest` の担当」という**静的走査の分担**の話であり、
  「RateLimiter がキャッシュへ入れる**値**は見なくてよい」という意味ではない。
  実行時層は値を見る層なので、RateLimiter が握る受け皿も guard 付きに差し替える。
  - `RateLimiter::$cache` は protected で公開の setter が無い (vendor 実読) ため**反射で差し替える**
  - **`forgetInstance(RateLimiter::class)` はしない**。名前付き制限の定義 (`RateLimiter::for(...)`。
    本リポジトリでは `AppServiceProvider::boot()` に多数ある) は起動時に 1 度だけ登録されるので、
    インスタンスを捨てると二度と復活しない (aigenba は実測で 365 件のテストが落ちたと記録している)
  - プロパティが消えていたら**その場で例外**にする (Laravel 更新で pin が空振りしない)
- **並列実行**: accumulator はプロセス内 static である。`--parallel` の worker 間では共有しない
  (既存の 2 guard と同じ)
- **`install()` より前 (アプリ boot 中) の書き込みは観測できない**。そこは静的層が覆う
- PHPStan level 10 を通す。`tests/` も解析対象である
- 走査器・gate の新設・変更なので、AGENTS.md「走査器・gate を新設・変更するときに同じ PR で
  揃える 4 点」(負例と正例 / 解決できない形を落とす / 空振り検知 / docblock に保証範囲) が
  全面的に適用される

## スコープ外

- **本番のキャッシュ実行経路への guard 導入**。これはテストレーンの検査機構であり、
  本番へ入れると性能と挙動の両方を変える。正典も要求していない
- **キャッシュの保存先・キー設計・有効期限の設計** (feature の boundary が明示的に除いている)
- **キュー・セッション・経路表のキャッシュ** (Laravel 側で別の仕組みが扱う。
  必要になったら別 feature として起票する、と台帳が定めている)
- **静的層の L1/L2/L3 の作り直し**。L4 の追加と冒頭コメントの訂正に限る
- **`Repository::$unserializableClassHandler` の pin** (迂回口ではないため。
  保証しないものへ明記する)
- **台帳への書き戻し (append_event)**。実装完了後に別途行う

## 保証しないもの (正本は実行時層の docblock。ここは要約)

- `install()` より前 (アプリ起動中) の書き込み
- **同一テストの中で** macro を登録し、使い、`flushMacros()` で消す形
- `Repository::__call()` の素通しのうち macro を経由しないもの (静的層の検査 1 が担当)
- 呼び出し元が 0 件の休眠経路 (実行時層は実行されないものを見ない)
- `--parallel` の worker をまたいだ違反の集約 (accumulator はプロセス内 static)

## v2 の 4 要素との対応 (完了判定に使う)

| v2 要素 | 本設計での担い手 |
|---|---|
| (1) 静的層 = 全数申告目録 + deny-by-default | 既存 gate の L1/L2/L3 (実装済み。据え置き) |
| (2) 実行時層 = 受け皿で保管前の値を再帰検査 | 施策 A / B / C / D (新設) |
| (3) 設定の二段 pin | 既存 (`ConfigHardeningTest` の宣言 pin + gate 検査 6 の実効値) |
| (4) 境界迂回の hard fail | 施策 E の L4 (`extend` / `getStore` / `setStore` / `tags` / 直接生成 / macro 系) + 実行時の `tags` hard fail |
