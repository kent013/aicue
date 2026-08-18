# Round 2

Round 1 の指摘への対応マトリクスと、修正後の概念設計を提示します。
判定を更新してください (APPROVED / CHANGES_REQUESTED)。

## 対応マトリクス

# 対応マトリクス: conceptual-review Round 1

## [Critical] 3-1 `CacheManager::repository()` を包むだけでは網羅にならない (TaggedCache / 各書き込み API)

- 判断: **対応する** (指摘は正しい。ただし網羅の根拠は vendor 実読で確定できた)
- 根拠: `vendor/laravel/framework/src/Illuminate/Cache/Repository.php` を実読して funnel を確定した。
  値を運ぶ公開 API は末端 4 つ (`put` / `add` / `forever` / `putMany`) に集約される —
  `set`→`put` / `setMultiple`→`putMany` / `remember`→`rememberWithWarmth`→`put` /
  `sear`→`rememberForever`→`forever` / `flexible`→`putMany` / `offsetSet`→`put` /
  `putMany($values, null)`→`putManyForever`→`forever`。
  `touch` は値を運ばず (`store->touch(key, seconds)`)、`increment` / `decrement` は整数のみ。
  よって**末端 4 つを override すれば値を運ぶ経路は全部通る**。
  一方 `tags()` の指摘は**そのとおり穴である** — `Repository::tags()` は
  `new TaggedCache($this->store, ...)` を**素で生成**するため (`TaggedCache extends Repository`)、
  guard 付き受け皿を継承しても以降の書き込みは検査を通らない。
  さらに `Repository::__call()` は `$this->store->$method(...)` へ**素通し**する経路を持ち、
  `Repository` は `Macroable` を use しているため macro からも到達できる。
- 対応内容: 概念設計に **funnel matrix (値を運ぶ / 値を運ばない / 禁止する の 3 分類)** を明記した。
  `tags()` は**実行時 hard fail + 静的層 L4 で 0 件 pin** にする
  (根拠: 本番の保管方式 database store は `supportsTags()` が false でタグ非対応。
  タグを使う書き方は本番で例外になるので、落とすのが正しい。現使用も 0 件)。
  `__call` 素通しは `Repository::$macros` を空で pin することで macro 経由の到達を塞ぎ、
  素通しそのものは**静的層の検査 1 (未分類 API の deny-by-default)** が受け持つ
  (2 層の相補性の実例として設計に書いた)。

## [Warning] 3-2 L4 の対象を先に棚卸しせよ

- 判断: **対応する**
- 対応内容: `CacheManager` / `Repository` の公開 API を実読して、受け皿を迂回して `Store` へ
  到達する経路を棚卸しし、概念設計へ表として載せた (`extend` / `getStore` / `setStore` /
  `tags` / 受け手型の直接生成 / `memo` の扱い)。`memo()` は `repository()` を通るので
  実行時層は効くが、現在 4 語彙のどれにも属さないため**静的層の検査 1 が既に落とす**。
  この状態を「意図的に未分類のまま置く」と設計へ明記した。

## [Critical] 5-1 露出時の判断責任と記録方法が不明確

- 判断: **対応する**
- 対応内容: 実装の**最初の工程を「計測」にした** — guard を仮結線して全レーン
  (`composer test` + `composer test:browser`) を 1 度走らせ、露出を全件
  `devnotes/.../runtime-exposure.md` へ記録してから修正に入る (テストファースト)。
  判断責任と分岐 (アプリ / テスト / vendor) を明文化し、
  **vendor 由来で即時に解消できないものが 1 件でも残るなら実装を完了にせず、
  設計へ差し戻して台帳の議題として起こす**ことを完了条件に書いた。

## [Warning] 2 施策 G が「テストレーンだけ」という前提と矛盾する

- 判断: **対応する**
- 対応内容: 施策を **A〜F・H (テストレーン限定) と G (本番設定に触る)** に明示的に分けた。
  G は `env()` を介さず `false` を直書きし、`ConfigHardeningTest` で
  **宣言 (config ファイル直接評価) と実効値 (`config()`)** の両方を pin する
  (既存の `serializable_classes` の二段 pin と同じ形にそろえる)。
  なお現時点で `PromptTemplate::fromYaml()` の呼び出し元は 0 件なので、
  G は**挙動としては no-op** である旨も併記した。

## [Warning] 4 PromptTemplate を「静的層だけが見える例」と書いたのは不正確

- 判断: **対応する** (指摘のとおり誤り)
- 根拠: aigenba では同パッケージが**リポジトリ内の同梱パッケージ**なので静的層が見える。
  aicue では composer 依存 (`vendor/`) なので**静的層の母集団外**であり、
  呼び出し元 0 件のため実行時層も踏まない = **現時点ではどちらの層も発火しない休眠経路**である。
- 対応内容: 両方向の実例の記述を書き直した。実行時層の効果は
  「vendor 経路が**テストで実行されたときに**値を検査する」に限定し、
  休眠経路の閉鎖は施策 G (設定による閉鎖) の効果として**分けて**書いた。

## [Warning] 5-2 accumulator の残留リスク

- 判断: **対応する**
- 対応内容: 「テスト本体が例外を投げた場合」「アプリが例外を握り潰した場合」「複数違反の場合」
  「意図的違反テストの後で次のテストへ漏れないこと」を振る舞い検査 (施策 C) の必須項目として明記した。

## [Warning] 6 スコープが広い / 実装順を決めよ

- 判断: **対応する**
- 対応内容: 実装順を 5 段 (計測 → 実行時層と結線 → 露出の是正 → L4 と静的層の訂正 →
  設定の閉鎖と文書) にし、**各段の完了条件を「その時点で全レーン緑」**とした。

## [Warning] 7 クラス間の型契約が概念設計に無い

- 判断: **対応する (ただし詳細は Phase 2 の担当)**
- 対応内容: 概念設計には**クラスの責務と公開メソッドの一覧**までを載せ、
  シグネチャの vendor 互換 (`put($key, $value, $ttl = null)` 等) と PHPStan level 10 の
  具体的な型注釈は詳細設計で確定する、と明記した。

## [Suggestion] 1 / 2 / 5 / 6 / 7 の各 Suggestion

- 判断: **見送る (すでに設計の意図と一致しているため変更不要)**

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
  実行時層は実行されないものを永久に見ない。本リポジトリで現に該当するものは
  棚卸ししていない (露出の計測工程で分かる) が、キャッシュ書き込みの追加は
  日常的に起こるため、**新しい経路が申告なしに増えないこと**は静的層でしか守れない
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
| **禁止する (迂回)** | `tags()` / `getStore()` / `setStore()` / `Cache::extend()` / 受け手型の直接生成 | 実行時 hard fail (`tags`) + 静的層 L4 で 0 件 pin |

`tags()` を禁止にする理由は 2 つある。

1. `Repository::tags()` は `new TaggedCache($this->store, ...)` を**素で生成**する
   (`TaggedCache extends Repository`)。guard 付き受け皿を継承しても、そこから先の書き込みは
   検査を通らない = **実行時層の穴**である
2. **本番の保管方式 (database store) はタグに対応しない** (`supportsTags()` が false)。
   タグを使う書き方は本番で例外になるので、そもそも落とすのが正しい。
   現在の使用箇所は 0 件 (静的 gate の負例 fixture が nowdoc 内に 1 件持つだけ)

`Repository::__call()` の素通しと `Macroable` については、**`Repository::$macros` が空であることを
guard が毎回 pin する** (macro を登録すれば `__call` 経由で `$this->store` へ到達できるため)。
素通しそのものは静的層の検査 1 が受け持つ (上述)。
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
- **現時点では挙動として no-op** である (`fromYaml()` の呼び出し元が 0 件)。
  効果はパッケージ更新や利用開始で呼び出し元が生まれたときの fail-safe である。
  性能への影響もない (呼ばれていないため)

### 実装順 (各段の完了条件は「その時点で全レーン緑」)

1. **計測**: guard を仮結線して `composer test` と `composer test:browser` を 1 度走らせ、
   露出した違反を**全件** `devnotes/{dir}/runtime-exposure.md` へ記録する
   (件数 / ファイル / 呼び出し元 / 値の型)。**修正はここではしない**
2. **実行時層と結線** (A / B / C / D)
3. **露出の是正** (計測で出たものを下の判断基準で処理する)
4. **静的層の訂正と L4** (E)
5. **設定の閉鎖と文書** (G / F / H)

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

| クラス (`tests/Support/Cache/`) | 責務 | 主な公開メソッド |
|---|---|---|
| `PlainDataInspector` | 値が素のデータかを再帰検査する純関数。深さ・ノード数の上限を持ち、**超過は「証明できなかった」として違反にする** (fail-closed) | `violations(mixed $value): list<string>` |
| `CachePayloadViolation` | 違反を表す例外 (`RuntimeException` 継承)。書き込み呼び出しの中で throw され、失敗が書き込み元のテストへ帰属する | `forWrite()` / `forBoundary()` |
| `PlainDataGuardedRepository` | `Illuminate\Cache\Repository` を継承し、値の末端 4 メソッドを override。`tags()` は境界迂回として throw | `put` / `add` / `forever` / `putMany` / `tags` |
| `PlainDataGuardedCacheManager` | `Illuminate\Cache\CacheManager` を継承し `repository()` を override。**`Store` 型を参照してよい唯一のサイト** | `repository(Store $store, array $config = [])` |
| `PlainDataCacheGuard` | 結線と accumulator。`cache` binding の差し替え、既解決インスタンスの破棄、`Repository::$macros` の pin と復元 | `install` / `flushAndFailIfStray` / `reset` / `drainForAssertion` / `inspectedCount` |

シグネチャは vendor と完全互換にする (`put($key, $value, $ttl = null)` 等)。
PHPStan level 10 を通す型注釈と、`mixed` を受けたあとの絞り込みは詳細設計で確定する。

## 制約・前提

- **A〜F・H はテストレーン限定**で本番の挙動を変えない (`tests/` だけを触る)。
  **G だけが `config/` を触り、本番の挙動 (テンプレートキャッシュの無効化) を変える**
- **既存 gate (1455 行) の責務分担を壊さない**。L1/L2/L3 の構造・語彙表・正負コントロールは
  そのまま残し、L4 を足す形にする
- `Illuminate\Cache\RateLimiter` は**既存 gate が明示的に母集団から外している**
  (「レート制限。ThrottleCoverageInventoryTest の担当」)。実行時層もこの区分に従い、
  既に解決済みの RateLimiter インスタンスへ**手を入れない**。
  帰結として流量制限の書き込みは guard を通らない場合がある。これは
  **保証しないもの**として docblock に書く (aigenba は反射でここへ手を入れたが、
  本リポジトリは既存の区分と整合させる方を採る)
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
- **`Illuminate\Cache\RateLimiter` の包み込み** (上の制約を参照)
- **`Repository::$unserializableClassHandler` の pin** (迂回口ではないため。
  保証しないものへ明記する)
- **台帳への書き戻し (append_event)**。実装完了後に別途行う
