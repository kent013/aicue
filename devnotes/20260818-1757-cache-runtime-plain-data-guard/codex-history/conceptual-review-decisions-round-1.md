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
