# 対応マトリクス: design-review Round 2

## [Critical] L4「0 件」と、実際に迂回 API を呼ぶ S5 が矛盾する

- 判断: **指摘のとおり。対応する**（このままでは負例を書いた瞬間に gate が落ちる）
- 対応内容: L4 の言い方を **「通常経路 0 件 + 名指し自己テストの exact-fit」** に改める。
  `CACHE_PAYLOAD_BOUNDARY_SELFTEST_INVENTORY`（新設）へ
  `パス::メソッド名 => 件数 + 根拠` で登録し、登録できるパスを
  **`tests/Feature/Cache/CachePayloadPlainDataGuardTest.php` と `tests/Support/Cache/` 配下**に
  固定する（他のパスは登録できない = 免除の口にならない）。件数は完全一致。
  **動的呼び出しで走査を避ける形は採らない**（検出力の裏取りが弱くなるため）。

## [Critical] 非 macro の `__call()` が未検査の Store 経路を残す

- 判断: **指摘のとおり。対応する（無条件 hard fail にする）**
- 根拠: 「`Repository` が名前を持つ API は `__call` に来ない」は、
  **store 固有 API / 将来追加される API が payload を運ばない証明にはならない**。
- 対応内容: `__call()` は macro かどうかに関わらず `reportBoundary()` で落とす。
  実測（S8 の反復計測）で正当な非 payload の用途が出てきたら、
  **guard の中に無言の許可を作らず**、vendor 実読で用途を分類したうえで
  設計へ差し戻して判断する（本 TODO の完了条件に「未分類の `__call` が残っていないこと」を入れる）。

## [Critical] `setStore()` のシグネチャと「Store 参照唯一サイト」が矛盾する

- 判断: **矛盾しないことを vendor 実読で確認した。設計に実宣言を明記する**
- 根拠: `Illuminate\Cache\Repository::setStore($store)` は **型宣言を持たない**
  （docblock に `@param \Illuminate\Contracts\Cache\Store $store` があるだけ）。
  よって忠実に写しても `PlainDataGuardedRepository` は `Store` 型を参照しない。
  **`Store` 型を参照してよい唯一のサイトは manager の `repository(Store $store, …)` のまま**である。
- 対応内容: 設計に vendor の実宣言（`public function setStore($store)`）を転記し、
  「唯一サイト」の主張の根拠として明記した。

## [Critical] S5 の共通 assertion helper が global function ではオートロードされない

- 判断: **指摘のとおり。対応する**
- 対応内容: `Tests\Support\Cache\CachePayloadViolationAssertions` クラスの
  static メソッド `expectViolation()` にする（既存の PSR-4 でオートロードされる）。

## [Critical] ArrayAccess の提示コードが PHPStan チェック欄と一致しない

- 判断: **指摘のとおり。対応する**
- 対応内容: helper の引数型を **具体クラス `Illuminate\Cache\Repository`** にし、
  呼び出し側で `Cache::store('array')` の結果を `instanceof` で絞る。
  `Illuminate\Cache\Repository` は静的層の受け手型でもあるので、
  型宣言から受け手名が作られる（L2 の検出は維持される）。

## [Critical] W5 が vendor の変更だけを pin し、ローカルの写しを比較していない

- 判断: **指摘のとおり。対応する**
- 対応内容: **W5b を新設**する。`Tests\TestCase::createApplication()` の正規化 token 列も pin し、
  **「vendor の期待列へ許可差分を挿入した列」と完全一致**することを検査する。
  許可差分は次の 3 つだけ:
  (1) 戻り値の fail-closed 確認（`if (! $app instanceof Application) { throw … }`）、
  (2) `PlainDataCacheGuard::registerBeforeBootstrap($app);`、
  (3) 戻り値型の宣言と `#[\Override]` 属性。
  これで `traitsUsedByTest` の代入・cached config / routes の分岐・`return $app` を
  ローカルから消すと赤くなる。

## [Critical] L4d の `prev === T_IMPLEMENTS` では複数 interface を検出できない

- 判断: **指摘のとおり。対応する**
- 対応内容: `T_EXTENDS` / `T_IMPLEMENTS` を見つけたら**宣言句全体**（`{` まで）を読み、
  カンマ区切りの各名前を `use` 表で完全修飾名へ解決する。
  **解決できない名前は候補から外さず fail させる**（未解決を落とす）。
  負例に「2 番目の interface としての `Store`」「別名つき `Store`」「完全修飾名」
  「複数行の implements」の 4 形を置く。

## [Warning] Store 型の保証範囲の説明が広すぎる

- 判断: **対応する**
- 対応内容: 保証しないものを
  「**走査根の外で宣言され、完全修飾名が組み込み Store の命名規則に一致しない第三者の Store 実装**の
  直接生成・解決は検出しない」と具体的に書く。あわせて、独自 driver の登録口である
  `Cache::extend()` を 0 件 pin することが**その面を増やさないための責務**であることを併記する。

## [Warning] 第 2 Application の Facade 復元の主張

- 判断: **指摘のとおり。対応する**
- 対応内容: 主張を「元の解決済みインスタンスを復元する」から
  「**第 2 Application の解決済みインスタンスを残さず、元の Application から再解決できる状態へ戻す**」
  へ改める。検査 22 も object identity の一致ではなく、
  「facade の application が元へ戻っていること」と「再解決した cache manager が guard 付きであること」
  を見る形にする。`PlainDataCacheGuard::reset()` を `finally` に含めることも明記する。

## [Warning] macro 残存テストは global afterEach を直接は検証できない

- 判断: **指摘のとおり。対応する**
- 対応内容: テスト内で `flushAndFailIfStray()` を明示的に呼び、
  `RuntimeException` と `MACRO_REGISTERED` を検査する形にする。
  テスト名も「flush が残存 macro を検出する」に改める。
  全レーンから flush が呼ばれることは S6 が保証する（分担を明記）。

## [Warning] `Cache::extend()` の前提が未確定のまま断定されている

- 判断: **実装前に確定した。断定してよい**
- 根拠（vendor 実読）: `CacheManager::build()` は
  `if (isset($this->customCreators[$config['driver']])) { return $this->callCustomCreator($config); }` で
  **`repository()` を通さずに返す**。`callCustomCreator()` は
  `return $this->customCreators[$config['driver']]($this->app, $config);` であり、
  **creator の戻り値をそのまま返す**（型は `mixed`）。`resolve()` は `build()` へ委譲する。
  よって「独自 creator は `repository()` を通らない」は**確定した事実**である。
- 対応内容: 設計に上の実読結果を根拠として転記した。S5 の実証テストは
  「creator が返した受け皿が guard 付きでないこと」を固定する形で残す
  （前提が変わったら赤くなる trip-wire として意味がある）。

## [Warning] `PRISM_PROMPT_CACHE` の「追跡下 0 件」は達成不能

- 判断: **指摘のとおり。対応する**
- 対応内容: 「追跡下 0 件」をやめ、**保証範囲を限定する** —
  「`.env.example` / 実行設定 (`phpunit.xml` 等) / `config/` 本体から除去する」とし、
  テストと設計文書に語が残ることは想定内とする。
  **文字列を動的連結して検査を避ける形は採らない**。

## [Warning] S10 は S2 / S7 の確定後に文言を再調整

- 判断: **対応する**（本ラウンドの確定内容を S10 の文面へ反映した）
