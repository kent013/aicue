# Round 2: Round 1 の指摘への対応

Round 1 の全指摘 (Critical 1 / Warning 6 / Suggestion 6) を捌きました。対応マトリクスと、修正後の差分・追加した検査を示します。

## 対応マトリクス

# 対応マトリクス: impl-review Round 1

## [Critical] 動的な直接生成 (`new $class`) を黙って見逃す

- 判断: **対応する (ただし母集団を面の中へ限定し、残る限界を明記する)**
- 根拠: 指摘のとおり `T_NEW` の後が解決できない形を落とす分岐が無かった。ただし
  **走査根全体で落とすと 12 件の無関係な誤検出**が出た (`(new $model)->getTable()` /
  `PasskeyFactory::new()` など。前者はキャッシュと無関係な Eloquent のモデル生成、
  後者はそもそも生成ではなくメソッド名である)。誤検出は目録を意味の無い儀式に変えるので、
  落とす範囲を**キャッシュ記号に触れるファイル (L3 の面) の中**へ限定した。
- 対応内容:
  - `T_NEW` の直後が名前として解決できない形を `dynamicNewSites` に集め、
    そのファイルが面なら未分類として落とす (`cachePayloadCollectFromSource`)
  - `::new()` / `->new()` は**メソッド名**なので母集団から外す
  - **具体 store の名前に触れることを面の条件へ追加**した。これにより
    `$class = ArrayStore::class; $store = new $class;` はその時点でファイルが面になり、
    動的生成が落ちる
  - 負例 (面の中の動的生成 2 形) と正例 (面でないファイルの動的生成 /
    `Factory::new()` / 名前で書かれた `new`) を追加
  - **残る限界を冒頭 docblock の「保証しないもの」へ明記**した —
    クラス名を**素の文字列リテラル**で書いて動的生成する形は走査していない。
    L4b の「直接生成を 0 件で pin する」という主張はその構文を除いた範囲である
    (AGENTS.md 走査規約 (b) の「保証範囲を明示的に狭める」側を採った)

## [Warning] L4c が「第 1 引数」という構造を見ていない

- 判断: **対応する**
- 根拠: 直前 token が `(` であることしか見ておらず、`leak($store)` のような
  任意の呼び出しの第 1 引数でも通ってしまう。指摘のとおり穴である。
- 対応内容: 判定を純関数 `cachePayloadStoreLeakViolations()` へ切り出し、
  `new` + `PlainDataGuardedRepository` + `(` の直後であることまで確認する形にした。
  負例 3 形 (第 1 引数のすり替え / 第 2 引数への流出 / 受け皿以外への手渡し) と
  正例 1 形を追加した。

## [Warning] 継承解析の「解決不能なら null」分岐に負例が無い

- 判断: **対応する**
- 根拠: fail-closed 分岐の裏取りが無いのは AGENTS.md の 4 点セット違反である。
- 対応内容: `class Fixture implements $dynamicInterface {}` を合成入力とする負例を追加した。

## [Warning] W2/W3 が「finally に reset」を保証していない

- 判断: **対応する**
- 根拠: 指摘のとおり。afterEach より後に `reset()` があれば通る形だったので、
  flush が throw したときに accumulator が漏れる書き方を素通ししていた。
- 対応内容: `cacheGuardLaneWiringViolations()` に try / finally の位置判定を足し、
  **flush は try の中・reset は finally の中**であることを要求する形にした。
  負例を 3 形 (flush が無い / reset が finally の外 / try-finally の形でない) にした。

## [Warning] W6 がメソッドの中を見ていない

- 判断: **対応する**
- 根拠: ファイル全体の token 順を見ていたので、別メソッドで結線し別メソッドで
  bootstrap する形を正常扱いしていた。
- 対応内容: `cacheGuardBootstrapOrderViolations()` の引数を**メソッド本体の token 列**へ変え、
  W1 / W6 とも反射で切り出した本体を渡す形にした。負例に「別メソッドへ分けた形は
  ファイル全体を見ると 0 件になる」ことを明示する合成入力を追加した。

## [Warning] W4 の trait 検出が短名だけ / パス解決の失敗を黙って除外

- 判断: **対応する**
- 根拠: 指摘のとおり。別名 1 つで検査が黙る形だった。
- 対応内容: 取り込み表 (`use ... as ...` を含む) を作り、**型宣言より後の `use`** だけを
  trait の取り込みとして完全修飾名で突き合わせる形にした
  (namespace 直下の取り込みは対象外 — `tests/TestCase.php` は override のために必要である)。
  `getRealPath()` / `file_get_contents()` の失敗は expect で落とす (fail-closed)。
  負例 4 形 (短名 / 別名 / 完全修飾名 / カンマ区切り) と正例 1 形 (取り込みだけ) を追加した。

## [Warning] W8 の負例が実際の判定関数を通っていない

- 判断: **対応する**
- 根拠: 加工した配列を素の比較で確かめるだけでは、判定側が壊れても負例が緑のままになる。
- 対応内容: 判定を `cacheGuardTokenListViolations()` /
  `cacheGuardLocalCopyViolations()` の 2 つの純関数へ切り出し、W5 / W5b / W8 / W7 の
  すべてがこの関数を通る形にした。W8 の削除負例には「結線 1 行」も足した。

## [Warning] runtime-exposure.md が差分に無い

- 判断: **対応する (ファイルは実在していた。レビューへ渡す差分の指定漏れである)**
- 根拠: `devnotes/20260818-1757-cache-runtime-plain-data-guard/runtime-exposure.md` は
  作成済みだったが、Round 1 のプロンプトを組むときに `git diff` の対象から
  `devnotes/` を落としていた。
- 対応内容: Round 2 のプロンプトへ本文を添付する。内容は wave 0 (`__call` の素通しの分類。
  実測で 18 件中 16 件が落ちることを確認) / wave 1 (全レーン 5862 件中、実行時層の違反 0 件) /
  wave 2 (静的層を入れた後の再計測) と、一意ファイル数・違反サイト数・違反件数の 3 つを持つ。

## [Suggestion] AGENTS.md の「自己テストだけを exact-fit」が実態と食い違う

- 判断: **対応する**
- 対応内容: 継承・実装の宣言は別の名指し目録で扱い実行時層の実装 2 本だけを許す、と書き分けた。

## [Suggestion] guide にも「2 層とも見えない」を明記

- 判断: **対応する**
- 対応内容: `docs/app-integration-guide.md` の不変条件 6 に同じ一文を足した。

## [Suggestion] 冒頭の不変条件説明に `null` が抜けている

- 判断: **対応する**
- 対応内容: 静的 gate 冒頭の 1 行を「配列 / 文字列 / 数値 / 真偽値 / null」に直した。

## [Suggestion] L4g は一致ではなく部分集合

- 判断: **対応する**
- 根拠: TERMINAL には mock 系も含むので「一致」は不正確である。
- 対応内容: テスト名・コメント・`PlainDataGuardedRepository` の docblock を
  「部分集合」へ直した。あわせて docblock の参照先を実際に検査を持つ
  `CachePayloadPlainDataGateTest.php` へ訂正した。

## [Suggestion] `put()` の配列キー分岐が直接テストされていない

- 判断: **対応する**
- 対応内容: 検査 4b を追加した (負例 = `put(['k' => new stdClass], 60)` /
  正例 = 素データの往復)。L2 目録の `put` の件数を 2 へ更新した。


## Round 1 で差分に含め忘れていた計測記録 (runtime-exposure.md 全文)

# 実行時層を結線したときの露出の計測記録 (S8)

実行時層 (`Tests\Support\Cache\PlainDataCacheGuard`) を全レーンへ結線すると、
array store の性質に守られて緑だった書き込みが露出しうる。本書はその**計測**の記録である。
免除目録は作らない (家系の裁定 AG-107)。

計測環境: worktree `.claude/worktrees/tasks/T228` / `composer test` (`--parallel --processes=4`) /
`composer test:browser`。`phpunit.xml` / `phpunit.browser.xml` に `stopOnFailure` /
`stopOnError` の指定が無いこと (= 1 件失敗しても継続実行する) を実行前に確認した。

## wave 0: 計測の前に vendor 実読で分類した 1 件 (`__call` の素通し)

詳細設計 S2 は `Repository::__call()` を**無条件に**落とす形を出発点にしていた。
実装に入る前に vendor を実読したところ、次が確認できた。

- `Illuminate\Cache\Repository` は **`lock()` / `restoreLock()` を宣言していない**
  (`vendor/laravel/framework/src/Illuminate/Cache/Repository.php` の public メソッド一覧に無い)
- `Illuminate\Cache\CacheManager::__call()` は `$this->store()->$method(...)` へ委譲するので、
  `Cache::lock(...)` は `Repository::__call()` → `$this->store->lock(...)` の**素通し**で届く
- 本リポジトリはこの形を 6 ファイルで使っている (静的層の role=lock-only)

**実測で裏を取った**。`STORE_PASSTHROUGH_METHODS` を空にして
`composer test -- --filter=ReconcileSubscriptionStatus` を走らせると、
18 件中 16 件が `BOUNDARY_BYPASS(storePassthrough): lock` で落ちた。

処理: **guard に無言の許可は作らず**、`PlainDataGuardedRepository::STORE_PASSTHROUGH_METHODS`
として排他 2 語彙 (`lock` / `restoreLock`) を**名指しで分類**した。排他オブジェクトは
payload を運ばないためである。この分類が静的層の TERMINAL 語彙と一致していることは
`CachePayloadPlainDataGateTest` の検査 L4g が固定し、
分類していない素通しが落ちることは `CachePayloadPlainDataGuardTest` の検査 15b が、
分類した 2 語彙が通ることは同 15c が固定する。

## wave 1: 全レーンの計測

実行: `composer test` (2026-08-18)

```
tests: 5862 / passed: 5857 / failed: 3 / skipped: 2 / risky: 5
```

失敗 3 件はすべて**静的層 (S7 未着手) の目録ずれ**であり、実行時層の違反ではない。

| # | 失敗したテスト | 出所 | 内容 |
|---|---|---|---|
| 1 | `CachePayloadPlainDataGateTest` 検査 1 | 静的層 | 新設した実行時層と自己テストの API が L1 語彙に無い (`rememberWithWarmth` / `hasMacro` / `setStore` / `macro` / `flushMacros` / 未知メソッド 2 件) |
| 2 | 同 検査 2 | 静的層 | 新設した書き込み経路 11 件が L2 目録に未登録 |
| 3 | 同 検査 4 | 静的層 | 新設した 6 ファイルが L3 面の目録に未登録 |

**実行時層の違反 (`CachePayloadViolation` / accumulator の記録) は 0 件**であった。

- 一意ファイル数: **0**
- 違反サイト数: **0**
- 違反件数 (延べ): **0**

### 0 件だったことの解釈 (誇張しない)

事前調査 (概念設計) の見込みどおりである。

- `app/` のキャッシュ書き込みは `FxRateService::put` の 1 件だけで、渡すのは
  `FxSnapshotDto::toArray()` の連想配列である
- テストが実際に踏む vendor 側の書き込みは、いずれも素データであった —
  Laratrust の役割・権限キャッシュ (配列)、`Illuminate\Cache\RateLimiter` (整数)、
  スケジューラの排他 (真偽値)、キューワーカーの未処理例外カウンタ (整数)
- `Repository::$macros` の残骸も 0 件であった (全レーンの flush が
  `MACRO_REGISTERED` を 1 度も記録していない)

ただしこれは「**テストが実行した経路について** 0 件」という意味であり、
呼び出し元が 0 件の休眠経路 (`PromptTemplate::fromYaml()` 等) は実行時層では閉じない。
そちらは施策 S9 (設定による閉鎖) の効果である。

## wave 2: 静的層 (S7) を入れた後の再計測

実行: `composer test` / `composer test:browser` (最終検証。結果は実装メモの検証結果欄)。
実行時層の違反は引き続き 0 件で、静的層の 3 件も解消した。

## 是正した対象

- `app/` 由来: **なし** (露出 0 件)
- `tests/` 由来: **なし** (露出 0 件)
- vendor 由来: **なし** (露出 0 件)
- 設計との差: `__call` の素通しを無条件 hard fail から
  **排他 2 語彙の名指し分類 + それ以外は hard fail** へ変更した (wave 0)

## 完了条件との対応

- 未分類の `__call` (保管先への素通し) は残っていない。分類は `lock` / `restoreLock` の
  2 語彙ちょうどで、静的層の TERMINAL 語彙との一致を検査 L4g が pin する
- 累積の一意ファイル数は 0 で、差し戻し閾値 (10 ファイル) には遠く届かない


## 修正後の差分 (Round 1 のレビュー時点からの差分)

```diff
diff --git a/AGENTS.md b/AGENTS.md
index 8c368e23..f27b44e9 100644
--- a/AGENTS.md
+++ b/AGENTS.md
@@ -86,15 +86,32 @@ ## セキュリティ不変条件(アプリ都合で緩めない)
     `bootstrap/app.php` の **priority list**(route の宣言順ではない)
     (`ProjectRouteCurrentOrgGuardTest` / `NestedRouteIdorDefenseTest` /
     `TenantBoundaryOrderingTest`)
-11. **キャッシュに入れるのは素のデータだけ**: cache へ渡す値は配列 / 文字列 / 数値 / 真偽値に限る
+11. **キャッシュに入れるのは素のデータだけ**: cache へ渡す値は
+    配列 / 文字列 / 数値 / 真偽値 / `null` に限る
     (オブジェクトを直接入れない)。読み戻しは `fromArray()` 等で**明示的に組み立て直して検査**し、
     失敗したら `forget` する(準拠実装 `FxRateService` + `FxSnapshotDto`)。
     `config/cache.php` の `serializable_classes` は **`false` 固定**でクラス許可一覧を作らず、
     **キーごと消さない**(宣言が無いと制限なしの `unserialize()` に戻る = fail-open)。
-    **テストは array store で緑になり本番 database store でだけ壊れる**ため、
-    書き込み経路とキャッシュに触れるファイルは deny-by-default の目録で強制する
-    (`CachePayloadPlainDataGateTest` / 宣言 pin は `ConfigHardeningTest`。
-    guide §7 不変条件 6 と対応)
+    強制は **2 層**である(家系の裁定 AG-151 = 正典 v2)。
+    **静的層** (`CachePayloadPlainDataGateTest`) は書き込み経路とキャッシュに触れるファイルを
+    deny-by-default の目録で強制し、受け皿の境界を迂回する書き方(`Cache::extend` /
+    `getStore` / `setStore` / `tags` / 受け手型・保管先型の直接生成 / macro 登録)を
+    **通常経路は 0 件、実行時層の自己テストだけを名指しの目録へ exact-fit** で pin する。
+    受け手型・保管先型の**継承・実装の宣言**は別の名指し目録で扱い、
+    実行時層の実装 2 本 (guard 付き受け皿と guard 付き manager) だけを許す。
+    **実行時層** (`Tests\Support\Cache\PlainDataCacheGuard`) はテスト中のキャッシュ書き込みを
+    受け皿の側で捕まえ、保管先へ渡す前の値を再帰検査する。結線はアプリ起動の前
+    (`Tests\TestCase::createApplication()`)で、後始末は `tests/Pest.php` の全レーンが行う
+    (`CacheGuardWiringGateTest` が deny-by-default で固定)。
+    **「テストは array store なので実行時には捕まらない」は誤り** — 実行時層は直列化ではなく
+    **値**を見るので、直列化しない保管方式でも同じように発火する。
+    ただし **`getStore()` は実行時には落とせない**(vendor 自身が流量制限・排他の正常系で呼ぶ)
+    ため、そこは静的層だけが塞ぐ。したがって
+    **vendor が `getStore()` 経由で書く値は 2 層とも見えない**。
+    設定の宣言 pin は `ConfigHardeningTest`、実効値は静的 gate の検査 6。
+    **主要な境界の例外として `getStore()` だけをここにも記す**。
+    網羅的な保証外一覧の正本は**実行時層の docblock**であり、本書と guide には写さない
+    (2 か所に書くと必ず食い違う)。guide §7 不変条件 6 と対応
 
 > **採番の注意**: 本節の番号と `docs/app-integration-guide.md` §7 の番号は **1:1 対応しない**
 > (本節 6 = PII CipherSweet / guide 6 = 逆シリアライズ、本節 8 = SSRF / guide 8 = 認可 gate)。
diff --git a/config/prism-prompt.php b/config/prism-prompt.php
index ac0e8498..0725ce87 100644
--- a/config/prism-prompt.php
+++ b/config/prism-prompt.php
@@ -83,12 +83,20 @@
     |--------------------------------------------------------------------------
     |
     | Configuration for caching parsed YAML templates.
-    | Enabled by default in production for performance.
-    | Set PRISM_PROMPT_CACHE=false in .env for development.
+    |
+    | ★enabled は false 固定 (env を介さない)。
+    |   同梱パッケージの Kent013\PrismPrompt\PromptTemplate::fromYaml() は
+    |   Cache::store(...)->put($cacheKey, $instance, $ttl) で **PromptTemplate オブジェクトそのもの**を
+    |   キャッシュへ入れる。これは AGENTS.md セキュリティ不変条件 11 (キャッシュに入れるのは
+    |   素のデータだけ) に反する。有効・無効を決める設定は本リポジトリが所有しているので、
+    |   既定で閉じる。env で開け直せる形は残さない (開いた瞬間に規約違反になるため)。
+    |   ※現行コードを確認した範囲では fromYaml() の呼び出し元が無く、観測できる挙動の変化は
+    |     見込まれない。効果はパッケージ更新等で呼び出し元が生まれたときの fail-safe である。
+    |   宣言と実効値の二段 pin は tests/Feature/Config/ConfigHardeningTest.php。
     |
     */
     'cache' => [
-        'enabled' => env('PRISM_PROMPT_CACHE', true),
+        'enabled' => false,
         'ttl' => 3600,
         'store' => null, // null = default cache driver
     ],
diff --git a/docs/app-integration-guide.md b/docs/app-integration-guide.md
index 3e088a48..6180bbb2 100644
--- a/docs/app-integration-guide.md
+++ b/docs/app-integration-guide.md
@@ -229,14 +229,27 @@ ## 7. 守るべき不変条件(チェックリスト)
 6. **任意 class の逆シリアライズを許さない / キャッシュに入れるのは素のデータだけ**:
    `config/cache.php` の `serializable_classes` は **`false` 固定**でクラス許可一覧は作らない
    (例外を作らない)。**キーごと消すのも不可** — Laravel は宣言が無いと制限なしの
-   `unserialize()` に戻る(fail-open)。cache へ渡してよいのは配列 / 文字列 / 数値 / 真偽値だけで、
+   `unserialize()` に戻る(fail-open)。cache へ渡してよいのは
+   配列 / 文字列 / 数値 / 真偽値 / `null` だけで、
    オブジェクトは `toArray()` で素の配列にしてから入れ、読み戻しは `fromArray()` 等で
    **明示的に組み立て直して検査し、失敗したら `forget`** する
    (準拠実装: `App\Services\FxRateService` + `App\DataTransferObjects\FxSnapshotDto`)。
-   **テストレーンは array store(`serialize => false`)なのでオブジェクトを入れても緑になる** —
-   本番の database store でだけ壊れるため、静的検査で塞ぐ:
-   キャッシュ書き込み経路とキャッシュに触れるファイルは
-   `tests/Architecture/CachePayloadPlainDataGateTest.php` の目録へ登録必須(deny-by-default)。
+   強制は **2 層**である(家系の裁定 AG-151 = 正典 v2):
+   - **静的層** (`tests/Architecture/CachePayloadPlainDataGateTest.php`) —
+     キャッシュ書き込み経路とキャッシュに触れるファイルは目録へ登録必須(deny-by-default)。
+     受け皿の境界を迂回する書き方(`Cache::extend` / `getStore` / `setStore` / `tags` /
+     受け手型・保管先型の直接生成 / 継承 / macro 登録)は
+     **通常経路 0 件 + 実行時層の自己テストだけを名指しの目録へ exact-fit** で pin する
+   - **実行時層** (`Tests\Support\Cache\PlainDataCacheGuard`) —
+     テスト中のキャッシュ書き込みを受け皿の側で捕まえ、保管先へ渡す**前の値**を再帰検査する。
+     結線はアプリ起動の前(`Tests\TestCase::createApplication()`)、後始末は
+     `tests/Pest.php` の全レーン(`tests/Architecture/CacheGuardWiringGateTest.php` が固定)
+   **「テストは array store なので実行時には捕まらない」は誤り** — 実行時層は直列化ではなく
+   **値**を見るので、直列化しない保管方式でも同じように発火する。
+   ただし **`getStore()` は実行時には落とせない**(vendor 自身が流量制限・排他の正常系で呼ぶ)
+   ため、そこは静的層だけが塞ぐ。したがって
+   **vendor が `getStore()` 経由で書く値は 2 層とも見えない**。
+   網羅的な保証外一覧の正本は**実行時層の docblock**であり、本書と AGENTS.md には写さない。
    配列往復は `tests/Unit/DataTransferObjects/FxSnapshotDtoTest.php` が固定する
 7. **課金系の冪等性**: webhook は冪等マシン経由、消費は 2 フェーズ、通知は dedup_key。
    課金による利用可否の判定は `BillingAccess` 経由のみ(subscription 直参照の gate 分岐禁止。
diff --git a/docs/architecture.md b/docs/architecture.md
index 99d803ba..67930924 100644
--- a/docs/architecture.md
+++ b/docs/architecture.md
@@ -2853,3 +2853,70 @@ ### 保証しないもの (誇張しない)
 - **レーンの非対称**: 値集合の同期は `pnpm test` (CI の frontend job) でだけ走る。
   PHP としての妥当性は backend job (`composer test` / PHPStan)。
   **`composer test` だけでは値集合の同期は検証されない**。
+
+## キャッシュ素データ規約の 2 層 (T228 / 家系の裁定 AG-151 = 正典 v2)
+
+「キャッシュに入れるのは素のデータだけ」(AGENTS.md セキュリティ不変条件 11) は
+**静的層と実行時層の 2 層**で強制する。どちらも他方を包含しない。
+
+| 層 | 実体 | 保証すること |
+|---|---|---|
+| 静的層 | `tests/Architecture/CachePayloadPlainDataGateTest.php` | **申告なしに書き込み経路を増やせない**。境界を迂回する書き方が通常経路で 0 件である |
+| 実行時層 | `tests/Support/Cache/PlainDataCacheGuard.php` ほか 4 本 | **テストが実行した書き込みの値が実際に素データである** |
+
+- **静的層だけが見えるもの**: `tests/` `app/` にありながらテストが 1 度も踏まない書き込み。
+  実行時層は実行されないものを永久に見ない
+- **実行時層だけが見えるもの**: `vendor/` 配下からの書き込み。静的走査の母集団
+  (`app` / `routes` / `database` / `tests`) に入らないので、テストがその経路を踏んだときに
+  値を見られるのは実行時層だけである
+
+### 実行時層の仕組み
+
+受け皿 (`Illuminate\Cache\Repository`) を継承した `PlainDataGuardedRepository` が
+値の末端 4 メソッド (`put` / `add` / `forever` / `putMany`) を override し、
+保管先へ渡す**前の値**を `PlainDataInspector` で再帰検査する。
+糖衣 API (`set` / `setMultiple` / `remember` / `rememberForever` / `sear` / `flexible` /
+`rememberWithWarmth` / `$cache[$k] = $v`) は vendor 実装がこの 4 つへ合流するので、
+合流が将来変わったら `tests/Feature/Cache/CachePayloadPlainDataGuardTest.php` が落ちる。
+
+**イベント購読 (`KeyWritten`) にはしない** — `Event::fake()` や store 設定の
+`'events' => false` で無効化できる差し替え可能な境界だからである。
+
+**結線はアプリ起動の前**である。`Tests\TestCase::createApplication()` が
+`bootstrap/app.php` を require した直後・`bootstrap()` の直前に
+`PlainDataCacheGuard::registerBeforeBootstrap()` を呼ぶ。Pest の beforeEach では遅く、
+起動中の書き込み (vendor 由来だと静的層の走査根にも入らない) が
+**2 層とも沈黙する穴**になる。
+
+**違反は「その場で例外」と「accumulator への記録」の両方**にする。アプリ側の
+`catch (Throwable)` で例外が消えても、afterEach の `flushAndFailIfStray()` で必ず赤くなる
+(既存の `StrayHttpRequestGuard` / `StrayLlmCallGuard` と同じ設計)。
+
+### 露出したときの直し方
+
+**免除目録は作らない**。出所ごとに次のとおり処理する。
+
+1. `app/` → 必ず直す。素の配列にして入れ、読み戻しで組み立て直す
+   (準拠実装 `FxRateService` + `FxSnapshotDto`)。あわせて静的層の L2 目録へ登録する
+2. `tests/` → 必ず直す (本番で壊れる書き方をテストが先取りしている状態である)
+3. vendor 由来 → (a) 本リポジトリが所有する設定でその機能を閉じる /
+   (b) その機能を使わない形へアプリを直す / (c) どちらもできなければ実装を完了にせず
+   家系の台帳の議題として起こす。**guard 側に許可一覧を足す選択肢は取らない**
+
+### 保管先への素通しの分類 (`__call`)
+
+`Illuminate\Cache\Repository` は `lock()` / `restoreLock()` を宣言しておらず、
+`Cache::lock(...)` は `Repository::__call()` の素通しで保管先へ届く。排他は payload を
+運ばないので、実行時層はこの 2 語彙**だけ**を名指しで通し、それ以外の素通しと
+macro 経由の呼び出しは境界迂回として落とす。許可を 2 か所で別々に育てないよう、
+静的層の TERMINAL 語彙との一致を同じ gate (検査 L4g) が固定する。
+
+### 設定で閉じたもの
+
+`config/prism-prompt.php` の `cache.enabled` は **`false` 固定** (env を介さない)。
+同梱パッケージの `PromptTemplate::fromYaml()` が `PromptTemplate` オブジェクトそのものを
+キャッシュへ入れるためで、有効・無効を決める設定を本リポジトリが所有している以上、
+既定で閉じるのが規約の帰結である。宣言と実効値の二段 pin は `ConfigHardeningTest`。
+
+> **保証しないものは本節に書かない**。正本は実行時層 (`PlainDataCacheGuard`) の docblock である
+> (2 か所に書くと必ず食い違う)。
diff --git a/docs/template-divergence.md b/docs/template-divergence.md
index 591e48d1..fd57cee3 100644
--- a/docs/template-divergence.md
+++ b/docs/template-divergence.md
@@ -8,7 +8,7 @@ # テンプレート差分レジストリ
 `template-divergence-ledger` が 2026-08-15 に確定した形) に従う。形式は
 `tests/Architecture/TemplateDivergenceLedgerFormatTest.php` が機械で強制する。
 
-登録エントリ: 28 件
+登録エントリ: 29 件
 
 ## 記録の原則
 
@@ -1698,3 +1698,61 @@ ### 関連
   `tests/js/architecture/enum-ts-sync-extractor.test.ts` /
   `tests/js/support/enum-ts-sync/`
 - 設計: `devnotes/20260817-1748-enum-ts-generic-sync-gate/`
+
+---
+
+## D30 キャッシュ素データ規約の実行時層を、アプリ起動の前に結線し境界迂回を正典より広く塞ぐ
+
+| 行 | 内容 |
+|---|---|
+| 対象パス | `tests/Support/Cache/PlainDataCacheGuard.php` / `tests/Support/Cache/GuardedBoundaryProbe.php` / `tests/Architecture/CacheGuardWiringGateTest.php` |
+| 業務要件起因の説明 | 本アプリは起動時に名前付き流量制限を多数登録し、その時点で受け皿を握るため、Pest の beforeEach で結線すると起動中の書き込みが 2 層とも見えない穴になる。また同梱パッケージがオブジェクトをキャッシュへ入れる実装を持つため、受け皿を跨ぐ書き方を正典の 3 形より広く塞ぐ必要がある |
+| 揃え続ける不変条件と保証機構 | 結線がアプリ起動の前にあり全レーンが後始末すること (`CacheGuardWiringGateTest`)。受け皿を跨ぐ書き方が通常経路 0 件であること (`CachePayloadPlainDataGateTest` の検査 L4a-L4g) |
+| 再判定の条件 | 家系の正典が結線点と境界迂回の語彙を改めたとき / Laravel が `createApplication()` の本体を変えて写しが維持できなくなったとき |
+| 決めた日 | 2026-08-18 |
+| 決めた人 | 開発者 |
+| 根拠 | devnotes/20260818-1757-cache-runtime-plain-data-guard/ |
+| 状態 | 監視中 |
+| 見直し期限 | 2027-02-14 |
+
+| 観点 | テンプレート | 本アプリ |
+|---|---|---|
+| 結線点 | Pest の beforeEach 相当 | アプリ起動の前 (`Tests\TestCase::createApplication()` の bootstrap 直前) |
+| 境界迂回の語彙 | 保管先の直接取得・受け皿の直接生成・拡張登録の 3 形 | 上記に加えて `setStore` / `tags` / macro 系 / 具体 store の生成 / 受け手型の継承・実装 |
+| 迂回の判定 | 0 件 | 通常経路 0 件 + 実行時層の自己テストだけを名指しの目録へ exact-fit |
+| 目録の構造 | 書き込みサイトの全数申告目録 | 既存の L1-L3 に L4 (迂回) を足す形 |
+| ArrayAccess 書き込み | 検出しない | `$cache[$k] = $v` を静的にも検出する |
+
+### なぜ正当な差分か (logic-driven)
+
+`AppServiceProvider::boot()` が名前付き流量制限を多数登録するため、`Illuminate\Cache\RateLimiter` は
+**起動中に** cache を解決して受け皿を握る。beforeEach で結線すると RateLimiter が握るのは
+guard の付いていない受け皿になり、起動中の書き込みは実行時層に見えない。
+vendor 由来の書き込みは静的層の走査根 (`app` / `routes` / `database` / `tests`) にも入らないので、
+**2 層とも沈黙する**。`Illuminate\Foundation\Testing\TestCase::createApplication()` は
+`bootstrap/app.php` を require したあと `bootstrap()` を呼ぶ間に**まだ起動していない `$app`** に
+触れる唯一の点なので、そこを override して結線する。
+
+境界迂回を広げたのは、`Repository::tags()` が `new TaggedCache($this->store, ...)` を素で生成して
+継承を素通りすること、`Repository` が `Macroable` を use しており macro の closure から
+`$this->store` へ直接到達できることを vendor 実読で確認したためである。
+どちらも実行時層の被覆から抜ける口であり、正典の 3 形には含まれていない。
+
+### 揃えている不変条件 (これは保証し続ける)
+
+> 「テストが実行したキャッシュ書き込みの値は、保管先へ渡る前に素データであることを検査されている」
+
+- 結線がアプリ起動の前にあることと、全レーンが後始末することは `CacheGuardWiringGateTest` が固定する
+- vendor の `createApplication()` の写しは token 列の完全一致で pin するので、静かに古くならない
+- 受け皿を跨ぐ書き方は自己テスト目録と exact-fit で、1 件増えたら必ず赤くなる
+
+### 保証しないもの
+
+- 保証しないものの正本は `tests/Support/Cache/PlainDataCacheGuard.php` の docblock である
+  (本書と `docs/architecture.md` には写さない)
+
+### 関連
+
+- 実装: `tests/Support/Cache/` / `tests/TestCase.php` / `tests/Pest.php` /
+  `tests/Architecture/CachePayloadPlainDataGateTest.php`
+- 設計: `devnotes/20260818-1757-cache-runtime-plain-data-guard/`
diff --git a/tests/Architecture/CacheGuardWiringGateTest.php b/tests/Architecture/CacheGuardWiringGateTest.php
new file mode 100644
index 00000000..28375e10
--- /dev/null
+++ b/tests/Architecture/CacheGuardWiringGateTest.php
@@ -0,0 +1,853 @@
+<?php
+
+declare(strict_types=1);
+
+use Illuminate\Foundation\Testing\TestCase as VendorTestCase;
+use Tests\Support\Cache\IsolatedApplicationProbe;
+use Tests\TestCase;
+
+/*
+ * Architecture invariant: **キャッシュ素データ規約の実行時層が、アプリ起動の前に結線され、
+ * 全レーンで後始末されている**こと (家系の裁定 AG-151 = 正典 v2 の要素 2)。
+ *
+ * 実行時層そのもの (値の検査・境界迂回の hard fail) の振る舞いは
+ * tests/Feature/Cache/CachePayloadPlainDataGuardTest.php が固定する。
+ * 本 gate が固定するのは**結線**である — 結線が beforeEach へ後退したり、
+ * どこかのレーンから flush が抜けたりすると、検査は緑のまま**検出だけが消える**。
+ *
+ * ★この gate が保証するもの:
+ *   - W1: Tests\TestCase::createApplication() が bootstrap() より**前**に
+ *     PlainDataCacheGuard::registerBeforeBootstrap() を呼ぶ
+ *     (反射で**メソッド本体**を切り出し、その中の token 位置で判定する。
+ *      ファイル全体を見る形だと「別メソッドで結線し別メソッドで bootstrap」を正常扱いする)
+ *   - W2/W3: tests/Pest.php の**期待するレーン集合ちょうど** ({Feature, Unit} / {Architecture} /
+ *     {Browser}) の beforeEach に assertInstalled があり、afterEach が try / finally の形で
+ *     **try に flush・finally に reset** がある (reset が finally の外にあると、flush が throw した
+ *     ときに accumulator が次テストへ漏れる)
+ *   - W4: WithCachedConfig / WithCachedRoutes を**クラス本体で use している**テストが 0 件である
+ *     (使い始めると override が vendor と食い違う前提が崩れる)。
+ *     短名・別名・完全修飾名・カンマ区切りを取り込み表で解決して突き合わせる
+ *   - W5: vendor の Illuminate\Foundation\Testing\TestCase::createApplication() の
+ *     正規化 token 列が期待値と**完全一致**する (Laravel 更新で写しが静かに古くならない)
+ *   - W5b: ローカルの写しが「vendor 期待列 + 許可差分 3 つ」と**完全一致**する。
+ *     許可差分は (1) 戻り値の fail-closed 確認 (2) 結線 1 行 (3) 戻り値型と #[\Override] だけ
+ *   - W6: 起動中の負例 (IsolatedApplicationProbe) が **同じ関数**を bootstrap より前に呼ぶ
+ *   - W7: 空振り検知 (走査ファイルが実在 / token 数が 0 でない / 許可差分がすべて位置ごと一致 /
+ *     検出器が合成入力の負例に反応する)
+ *   - W8: 負のコントロール (flush が無いレーン / reset が finally の外 / bootstrap の後で結線 /
+ *     結線が無い / レーン集合違い / vendor 本体の token 増減・順序入れ替え /
+ *     ローカルから既知の文を削除)。**いずれも本 gate が実際に使う判定関数へ通す**
+ *     (加工した配列を素の比較で確かめるだけだと、判定側が壊れても負例が緑のままになる)
+ *
+ * ★この gate が保証しないもの (誇張しない):
+ *   - vendor 側の `setUp()` / `refreshApplication()` の変更や bootstrapper の増減は見ない。
+ *     見るのは `createApplication()` の**本体だけ**である
+ *   - tests/Pest.php の**実行時の**挙動は見ない (字句として書かれていることだけを見る)。
+ *     実際に flush が発火することは CachePayloadPlainDataGuardTest の負例が示す
+ *   - レーンを新設したことは W2/W3 のレーン集合 exact-fit で赤くなるが、
+ *     phpunit.xml の testsuite 構成そのものは見ない
+ *
+ * 解析は PhpToken::tokenize (コメント・文字列リテラルは code token ではないので拾わない)。
+ * regex にすると**この説明コメント自身**で偽赤になる。
+ */
+
+/**
+ * vendor の `Illuminate\Foundation\Testing\TestCase::createApplication()` の正規化 token 列。
+ * Laravel 更新で 1 token でも変わったら W5 が赤くなる。**それが目的**である。
+ *
+ * @var list<string>
+ */
+const CACHE_GUARD_VENDOR_CREATE_APPLICATION_TOKENS = [
+    'public', 'function', 'createApplication', '(', ')', '{', '$app', '=', 'require', 'Application',
+    '::', 'inferBasePath', '(', ')', '.', '\'/bootstrap/app.php\'', ';', '$this', '->', 'traitsUsedByTest',
+    '=', 'class_uses_recursive', '(', 'static', '::', 'class', ')', ';', 'if', '(',
+    'isset', '(', 'CachedState', '::', '$cachedConfig', ',', '$this', '->', 'traitsUsedByTest', '[',
+    'WithCachedConfig', '::', 'class', ']', ')', ')', '{', '$this', '->', 'markConfigCached',
+    '(', '$app', ')', ';', '}', 'if', '(', 'isset', '(', 'CachedState',
+    '::', '$cachedRoutes', ',', '$this', '->', 'traitsUsedByTest', '[', 'WithCachedRoutes', '::', 'class',
+    ']', ')', ')', '{', '$app', '->', 'booting', '(', 'fn', '(',
+    ')', '=>', '$this', '->', 'markRoutesCached', '(', '$app', ')', ')', ';',
+    '}', '$app', '->', 'make', '(', 'Kernel', '::', 'class', ')', '->',
+    'bootstrap', '(', ')', ';', 'return', '$app', ';', '}',
+];
+
+/**
+ * ローカルの `Tests\TestCase::createApplication()` の正規化 token 列。
+ *
+ * ★W5 は vendor 側の変更しか見ず、W1 は「結線が bootstrap より前にある」ことしか見ない。
+ *   その 2 つだけだと、ローカルの写しから `$this->traitsUsedByTest` の代入・cached config 分岐・
+ *   cached routes 分岐・`return $app` を消しても**両方とも緑のまま**になる。
+ *
+ * @var list<string>
+ */
+const CACHE_GUARD_LOCAL_CREATE_APPLICATION_TOKENS = [
+    'public', 'function', 'createApplication', '(', ')', ':', 'Application', '{', '$app', '=',
+    'require', 'Application', '::', 'inferBasePath', '(', ')', '.', '\'/bootstrap/app.php\'', ';', 'if',
+    '(', '!', '$app', 'instanceof', 'Application', ')', '{', 'throw', 'new', 'RuntimeException',
+    '(', '\'bootstrap/app.php が Application を返しませんでした\'', ')', ';', '}', 'PlainDataCacheGuard', '::', 'registerBeforeBootstrap', '(', '$app',
+    ')', ';', '$this', '->', 'traitsUsedByTest', '=', 'class_uses_recursive', '(', 'static', '::',
+    'class', ')', ';', 'if', '(', 'isset', '(', 'CachedState', '::', '$cachedConfig',
+    ',', '$this', '->', 'traitsUsedByTest', '[', 'WithCachedConfig', '::', 'class', ']', ')',
+    ')', '{', '$this', '->', 'markConfigCached', '(', '$app', ')', ';', '}',
+    'if', '(', 'isset', '(', 'CachedState', '::', '$cachedRoutes', ',', '$this', '->',
+    'traitsUsedByTest', '[', 'WithCachedRoutes', '::', 'class', ']', ')', ')', '{', '$app',
+    '->', 'booting', '(', 'fn', '(', ')', '=>', '$this', '->', 'markRoutesCached',
+    '(', '$app', ')', ')', ';', '}', '$app', '->', 'make', '(',
+    'Kernel', '::', 'class', ')', '->', 'bootstrap', '(', ')', ';', 'return',
+    '$app', ';', '}',
+];
+
+/**
+ * ローカルの写しに足してよい差分 (offset は**ローカル列の index**、tokens は挿入された列)。
+ *
+ * ここから挿入を取り除くと vendor 期待列に**完全一致**しなければならない。
+ * 部分列の除去だけだと別の位置に同じ列を置いても通るため、**位置まで固定する**。
+ *
+ * @var list<array{reason: string, offset: int, tokens: list<string>}>
+ */
+const CACHE_GUARD_LOCAL_ALLOWED_INSERTIONS = [
+    [
+        'reason' => '戻り値型の宣言 (vendor は docblock だけなので狭めていない)',
+        'offset' => 5,
+        'tokens' => [':', 'Application'],
+    ],
+    [
+        'reason' => '戻り値の fail-closed 確認と、bootstrap 直前の結線 1 行',
+        'offset' => 19,
+        'tokens' => [
+            'if', '(', '!', '$app', 'instanceof', 'Application', ')', '{', 'throw', 'new',
+            'RuntimeException', '(', '\'bootstrap/app.php が Application を返しませんでした\'', ')', ';', '}',
+            'PlainDataCacheGuard', '::', 'registerBeforeBootstrap', '(', '$app', ')', ';',
+        ],
+    ],
+];
+
+/**
+ * tests/Pest.php で期待するレーン集合 (`->in(...)` の引数集合)。
+ *
+ * @var list<list<string>>
+ */
+const CACHE_GUARD_EXPECTED_LANES = [
+    ['Architecture'],
+    ['Browser'],
+    ['Feature', 'Unit'],
+];
+
+/**
+ * 空白・コメント・開始タグを落とした token の文字列列。
+ *
+ * @return list<string>
+ */
+function cacheGuardNormalizedTokens(string $source): array
+{
+    /** @var list<PhpToken> $tokens */
+    $tokens = PhpToken::tokenize($source);
+
+    return array_values(array_map(
+        static fn (PhpToken $token): string => $token->text,
+        array_filter(
+            $tokens,
+            static fn (PhpToken $token): bool => ! $token->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG]),
+        ),
+    ));
+}
+
+/**
+ * メソッド本体の正規化 token 列を反射で取り出す (fail-closed)。
+ *
+ * @return list<string>
+ */
+function cacheGuardMethodTokens(string $class, string $method): array
+{
+    $reflection = new ReflectionMethod($class, $method);
+
+    $file = $reflection->getFileName();
+    $start = $reflection->getStartLine();
+    $end = $reflection->getEndLine();
+    if ($file === false || $start === false || $end === false) {
+        throw new RuntimeException("{$class}::{$method}() の定義位置を解決できません (内部関数か eval)");
+    }
+
+    $lines = file($file);
+    if ($lines === false) {
+        throw new RuntimeException("{$file} を読めません");
+    }
+
+    return cacheGuardNormalizedTokens(
+        '<?php '.implode('', array_slice($lines, $start - 1, $end - $start + 1))
+    );
+}
+
+/**
+ * token 列 $needle が最初に現れる位置。無ければ null。
+ *
+ * @param  list<string>  $tokens
+ * @param  list<string>  $needle
+ */
+function cacheGuardSequencePosition(array $tokens, array $needle, int $from = 0): ?int
+{
+    $limit = count($tokens) - count($needle);
+    for ($i = $from; $i <= $limit; $i++) {
+        if (array_slice($tokens, $i, count($needle)) === $needle) {
+            return $i;
+        }
+    }
+
+    return null;
+}
+
+/**
+ * 「結線が bootstrap より**前**にある」ことの違反理由 (純関数。合成入力にも当てられる)。
+ *
+ * ★引数は**メソッド本体の token 列**である。ファイル全体を渡すと「別のメソッドで結線し、
+ *   別のメソッドで bootstrap する」形を正常扱いしてしまう。
+ *
+ * @param  list<string>  $tokens
+ * @return list<string>
+ */
+function cacheGuardBootstrapOrderViolations(array $tokens, string $label): array
+{
+    $wiring = cacheGuardSequencePosition($tokens, ['PlainDataCacheGuard', '::', 'registerBeforeBootstrap', '(']);
+    $bootstrap = cacheGuardSequencePosition($tokens, ['->', 'bootstrap', '(', ')']);
+
+    $violations = [];
+    if ($wiring === null) {
+        $violations[] = "{$label}: PlainDataCacheGuard::registerBeforeBootstrap() の呼び出しがありません";
+    }
+    if ($bootstrap === null) {
+        $violations[] = "{$label}: ->bootstrap() の呼び出しがありません (走査対象を取り違えている)";
+    }
+    if ($wiring !== null && $bootstrap !== null && $wiring > $bootstrap) {
+        $violations[] = "{$label}: 結線が bootstrap() より後にあります (起動中の書き込みを見逃す)";
+    }
+
+    return $violations;
+}
+
+/**
+ * tests/Pest.php を `pest()->extend(TestCase::class)` 単位のレーンブロックへ割る。
+ *
+ * @return list<array{lanes: list<string>, tokens: list<string>}>
+ */
+function cacheGuardLaneBlocks(string $source): array
+{
+    $tokens = cacheGuardNormalizedTokens($source);
+    $starts = [];
+    $from = 0;
+    while (($position = cacheGuardSequencePosition($tokens, ['pest', '(', ')', '->', 'extend'], $from)) !== null) {
+        $starts[] = $position;
+        $from = $position + 1;
+    }
+
+    $blocks = [];
+    foreach ($starts as $index => $start) {
+        $end = $starts[$index + 1] ?? count($tokens);
+        $block = array_slice($tokens, $start, $end - $start);
+
+        $lanes = [];
+        $inPosition = cacheGuardSequencePosition($block, ['->', 'in', '(']);
+        if ($inPosition !== null) {
+            for ($i = $inPosition + 3; $i < count($block); $i++) {
+                if ($block[$i] === ')') {
+                    break;
+                }
+                if ($block[$i] === ',') {
+                    continue;
+                }
+                $lanes[] = trim($block[$i], "'\"");
+            }
+        }
+        sort($lanes);
+
+        $blocks[] = ['lanes' => $lanes, 'tokens' => $block];
+    }
+
+    return $blocks;
+}
+
+/**
+ * 1 レーンブロックの後始末の違反理由 (純関数。合成入力にも当てられる)。
+ *
+ * @param  list<string>  $block
+ * @return list<string>
+ */
+function cacheGuardLaneWiringViolations(array $block, string $label): array
+{
+    $violations = [];
+
+    $beforeEach = cacheGuardSequencePosition($block, ['->', 'beforeEach', '(']);
+    $afterEach = cacheGuardSequencePosition($block, ['->', 'afterEach', '(']);
+    if ($beforeEach === null) {
+        $violations[] = "{$label}: beforeEach がありません";
+    }
+    if ($afterEach === null) {
+        $violations[] = "{$label}: afterEach がありません";
+
+        return $violations;
+    }
+
+    $assertInstalled = cacheGuardSequencePosition($block, ['PlainDataCacheGuard', '::', 'assertInstalled', '(']);
+    if ($assertInstalled === null || $assertInstalled > $afterEach) {
+        $violations[] = "{$label}: beforeEach で PlainDataCacheGuard::assertInstalled() を呼んでいません";
+    }
+
+    $try = cacheGuardSequencePosition($block, ['try', '{'], $afterEach);
+    $finally = cacheGuardSequencePosition($block, ['finally', '{'], $afterEach);
+    if ($try === null || $finally === null) {
+        $violations[] = "{$label}: afterEach が try / finally の形になっていません";
+    }
+
+    $flush = cacheGuardSequencePosition($block, ['PlainDataCacheGuard', '::', 'flushAndFailIfStray', '(']);
+    if ($flush === null || $flush < $afterEach || ($finally !== null && $flush > $finally)) {
+        $violations[] = "{$label}: afterEach の try で PlainDataCacheGuard::flushAndFailIfStray() を呼んでいません";
+    }
+
+    $reset = cacheGuardSequencePosition($block, ['PlainDataCacheGuard', '::', 'reset', '(']);
+    if ($reset === null || $finally === null || $reset < $finally) {
+        // ★flush が throw しても次テストへ accumulator を漏らさないために、reset は
+        //   **finally の中**でなければならない (try の中や afterEach の外では意味が変わる)。
+        $violations[] = "{$label}: afterEach の finally で PlainDataCacheGuard::reset() を呼んでいません";
+    }
+
+    return $violations;
+}
+
+/**
+ * 期待 token 列との完全一致を判定する (負例をこの関数に通すため純関数にしてある)。
+ *
+ * @param  list<string>  $actual
+ * @param  list<string>  $expected
+ * @return list<string>
+ */
+function cacheGuardTokenListViolations(array $actual, array $expected, string $label): array
+{
+    if ($actual === $expected) {
+        return [];
+    }
+
+    return ["{$label}: token 列が期待値と一致しません (実測 "
+        .count($actual).' token / 期待 '.count($expected).' token)'];
+}
+
+/**
+ * ローカルの写しが「vendor 期待列 + 許可差分」であることの違反理由 (純関数)。
+ *
+ * @param  list<string>  $local
+ * @return list<string>
+ */
+function cacheGuardLocalCopyViolations(array $local): array
+{
+    $violations = cacheGuardTokenListViolations(
+        $local,
+        CACHE_GUARD_LOCAL_CREATE_APPLICATION_TOKENS,
+        'ローカルの写し',
+    );
+
+    $stripped = $local;
+    foreach (array_reverse(CACHE_GUARD_LOCAL_ALLOWED_INSERTIONS) as $insertion) {
+        if (array_slice($local, $insertion['offset'], count($insertion['tokens'])) !== $insertion['tokens']) {
+            $violations[] = "許可差分「{$insertion['reason']}」が期待位置にありません";
+
+            continue;
+        }
+        array_splice($stripped, $insertion['offset'], count($insertion['tokens']));
+    }
+
+    return array_merge($violations, cacheGuardTokenListViolations(
+        $stripped,
+        CACHE_GUARD_VENDOR_CREATE_APPLICATION_TOKENS,
+        '許可差分を除いたローカルの写し',
+    ));
+}
+
+/**
+ * 1 ファイルが cached config / cached routes の trait を**クラス本体で use している**か。
+ *
+ * ★namespace 直下の取り込み (`use Illuminate\Foundation\Testing\WithCachedConfig;`) は
+ *   対象にしない — tests/TestCase.php は override のために取り込む必要があるためである。
+ *   見るのは**最初の型宣言より後**に現れる `use` (= trait の取り込み) だけで、
+ *   短名・別名・完全修飾名・カンマ区切りの複数指定をすべて解決して突き合わせる。
+ *
+ * @param  array<string, string>  $useMap  alias => FQCN
+ * @param  list<PhpToken>  $tokens
+ * @return list<string> 見つかった trait の完全修飾名
+ */
+function cacheGuardCachedStateTraitUses(array $tokens, array $useMap): array
+{
+    $watched = [
+        'Illuminate\Foundation\Testing\WithCachedConfig',
+        'Illuminate\Foundation\Testing\WithCachedRoutes',
+    ];
+
+    $count = count($tokens);
+    $typeStart = null;
+    for ($i = 0; $i < $count; $i++) {
+        if ($tokens[$i]->is([T_CLASS, T_TRAIT, T_INTERFACE, T_ENUM])) {
+            $typeStart = $i;
+            break;
+        }
+    }
+    if ($typeStart === null) {
+        return [];
+    }
+
+    $found = [];
+    for ($i = $typeStart; $i < $count; $i++) {
+        if (! $tokens[$i]->is(T_USE)) {
+            continue;
+        }
+        for ($j = $i + 1; $j < $count; $j++) {
+            $token = $tokens[$j];
+            if ($token->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
+                continue;
+            }
+            if ($token->text === ';' || $token->text === '{' || $token->text === '(') {
+                break; // `use (...)` の closure 形もここで抜ける
+            }
+            if ($token->text === ',') {
+                continue;
+            }
+            if (! $token->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
+                continue;
+            }
+            $name = ltrim($token->text, '\\');
+            $resolved = $useMap[$name] ?? $name;
+            if (in_array($resolved, $watched, true)) {
+                $found[] = $resolved;
+            }
+        }
+    }
+
+    return $found;
+}
+
+/**
+ * `use A\B\C;` / `use A\B\C as D;` から alias => FQCN の表を作る。
+ *
+ * @param  list<PhpToken>  $tokens
+ * @return array<string, string>
+ */
+function cacheGuardUseMap(array $tokens): array
+{
+    $map = [];
+    $count = count($tokens);
+    for ($i = 0; $i < $count; $i++) {
+        // ★型宣言より後の `use` は trait の取り込みなので取り込み表に混ぜない
+        //   (混ぜると `use WithCachedConfig;` が自分自身へ解決して短名の負例が黙る)。
+        if ($tokens[$i]->is([T_CLASS, T_TRAIT, T_INTERFACE, T_ENUM])) {
+            break;
+        }
+        if (! $tokens[$i]->is(T_USE)) {
+            continue;
+        }
+        $nameIndex = null;
+        for ($j = $i + 1; $j < $count; $j++) {
+            if ($tokens[$j]->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
+                continue;
+            }
+            $nameIndex = $j;
+            break;
+        }
+        if ($nameIndex === null || ! $tokens[$nameIndex]->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
+            continue;
+        }
+        $fqcn = ltrim($tokens[$nameIndex]->text, '\\');
+        $alias = str_contains($fqcn, '\\') ? substr((string) strrchr($fqcn, '\\'), 1) : $fqcn;
+
+        // `use A\B\C as D;` の別名を取り込む (別名 1 つで検査が黙るのを防ぐ)
+        for ($j = $nameIndex + 1; $j < $count; $j++) {
+            if ($tokens[$j]->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
+                continue;
+            }
+            if ($tokens[$j]->is(T_AS)) {
+                for ($k = $j + 1; $k < $count; $k++) {
+                    if ($tokens[$k]->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
+                        continue;
+                    }
+                    if ($tokens[$k]->is(T_STRING)) {
+                        $alias = $tokens[$k]->text;
+                    }
+                    break;
+                }
+            }
+            break;
+        }
+
+        $map[$alias] = $fqcn;
+    }
+
+    return $map;
+}
+
+/** 走査対象を fail-closed で読む。 */
+function cacheGuardReadSource(string $relative): string
+{
+    $absolute = base_path($relative);
+    expect(is_file($absolute))->toBeTrue("{$relative} が実在しません (走査根の改名を疑う)");
+
+    $source = file_get_contents($absolute);
+    expect($source)->toBeString("{$relative} を読めません");
+
+    return (string) $source;
+}
+
+// ---------------------------------------------------------------------------
+// W1 / W6: 結線が bootstrap より前にある
+// ---------------------------------------------------------------------------
+
+test('W1: Tests\TestCase::createApplication() は bootstrap() より前に結線する', function (): void {
+    expect(cacheGuardBootstrapOrderViolations(
+        cacheGuardMethodTokens(TestCase::class, 'createApplication'),
+        'Tests\TestCase::createApplication()',
+    ))->toBe([]);
+});
+
+test('W6: 起動中の負例も同じ関数を同じメソッド内で bootstrap より前に呼ぶ', function (): void {
+    // ★負例が別経路で結線していたら「同じ結線を通った」ことの証明にならない。
+    //   ファイル全体ではなく**メソッド本体**を反射で切り出して見る
+    //   (別メソッドで結線し別メソッドで bootstrap する形を正常扱いしないため)。
+    expect(method_exists(IsolatedApplicationProbe::class, 'run'))->toBeTrue();
+
+    expect(cacheGuardBootstrapOrderViolations(
+        cacheGuardMethodTokens(IsolatedApplicationProbe::class, 'run'),
+        'IsolatedApplicationProbe::run()',
+    ))->toBe([]);
+});
+
+// ---------------------------------------------------------------------------
+// W2 / W3: 全レーンの結線と後始末
+// ---------------------------------------------------------------------------
+
+test('W2/W3: tests/Pest.php の期待レーン集合ちょうどが結線と後始末を持つ', function (): void {
+    $blocks = cacheGuardLaneBlocks(cacheGuardReadSource('tests/Pest.php'));
+
+    $lanes = array_map(static fn (array $block): array => $block['lanes'], $blocks);
+    $expected = CACHE_GUARD_EXPECTED_LANES;
+    usort($lanes, static fn (array $a, array $b): int => implode(',', $a) <=> implode(',', $b));
+
+    expect($lanes)->toBe($expected,
+        'tests/Pest.php のレーン構成が期待と一致しません。レーンを増減したなら '
+        .'CACHE_GUARD_EXPECTED_LANES も同じ変更で直し、新レーンにも guard の結線と後始末を入れてください。');
+
+    foreach ($blocks as $block) {
+        expect(cacheGuardLaneWiringViolations($block['tokens'], implode('+', $block['lanes'])))->toBe([]);
+    }
+});
+
+// ---------------------------------------------------------------------------
+// W4: vendor 追随の前提 (cached config / cached routes を使っていない)
+// ---------------------------------------------------------------------------
+
+test('W4: WithCachedConfig / WithCachedRoutes を使うテストが 0 件である', function (): void {
+    // ★使い始めると createApplication() の写しが vendor と食い違い、
+    //   cached 分岐の意味が変わる。使うときは override を写し直すこと。
+    $root = base_path('tests');
+    $iterator = new RecursiveIteratorIterator(
+        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
+    );
+
+    $users = [];
+    $files = 0;
+    foreach ($iterator as $file) {
+        if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
+            continue;
+        }
+        $absolute = $file->getRealPath();
+        // ★解決できないパスを黙って除外しない (fail-closed)。
+        expect($absolute)->toBeString('走査対象のパスを解決できません: '.$file->getPathname());
+        if ($absolute === __FILE__) {
+            continue; // 本 gate 自身 (検出したい語を負例の入力として持つ)
+        }
+        $files++;
+
+        $source = file_get_contents((string) $absolute);
+        expect($source)->toBeString('走査対象を読めません: '.$absolute);
+        /** @var list<PhpToken> $tokens */
+        $tokens = PhpToken::tokenize((string) $source);
+
+        foreach (cacheGuardCachedStateTraitUses($tokens, cacheGuardUseMap($tokens)) as $trait) {
+            $users[] = ltrim(str_replace(base_path(), '', (string) $absolute), '/').' → '.$trait;
+        }
+    }
+
+    expect($files)->toBeGreaterThan(0, 'tests/ の走査が空振りしている');
+    expect($users)->toBe([]);
+
+    // 検出器が負例に反応する (短名 / 別名 / 完全修飾名 / カンマ区切りの 4 形)。
+    foreach ([
+        '短名' => "<?php\nuse Illuminate\\Foundation\\Testing\\WithCachedConfig;\nclass P { use WithCachedConfig; }",
+        '別名' => "<?php\nuse Illuminate\\Foundation\\Testing\\WithCachedRoutes as R;\nclass P { use R; }",
+        '完全修飾名' => "<?php\nclass P { use \\Illuminate\\Foundation\\Testing\\WithCachedConfig; }",
+        'カンマ区切り' => "<?php\nuse Illuminate\\Foundation\\Testing\\WithCachedConfig;\nclass P { use Countable, WithCachedConfig; }",
+    ] as $label => $fixture) {
+        /** @var list<PhpToken> $probe */
+        $probe = PhpToken::tokenize($fixture);
+        expect(cacheGuardCachedStateTraitUses($probe, cacheGuardUseMap($probe)))
+            ->toHaveCount(1, "{$label}: 負例を検出できていません");
+    }
+
+    // 正のコントロール: namespace 直下の取り込みだけなら検出しない (tests/TestCase.php が該当)。
+    $importOnlyFixture = <<<'PHP'
+    <?php
+    use Illuminate\Foundation\Testing\WithCachedConfig;
+    class P {
+        public function run(): void {
+            $used = WithCachedConfig::class;
+        }
+    }
+    PHP;
+    /** @var list<PhpToken> $importOnly */
+    $importOnly = PhpToken::tokenize($importOnlyFixture);
+    expect(cacheGuardCachedStateTraitUses($importOnly, cacheGuardUseMap($importOnly)))->toBe([]);
+});
+
+// ---------------------------------------------------------------------------
+// W5 / W5b: vendor 本体とローカルの写しの token 完全一致
+// ---------------------------------------------------------------------------
+
+test('W5: vendor の createApplication() の token 列が期待値と完全一致する', function (): void {
+    expect(cacheGuardTokenListViolations(
+        cacheGuardMethodTokens(VendorTestCase::class, 'createApplication'),
+        CACHE_GUARD_VENDOR_CREATE_APPLICATION_TOKENS,
+        'vendor の createApplication()',
+    ))->toBe([],
+        'Laravel の createApplication() が変わりました。tests/TestCase.php の写しを'
+        .'読み直して更新し、本 gate の期待 token 列も同じ変更で直してください。');
+});
+
+test('W5b: ローカルの写しが vendor 期待列 + 許可差分と完全一致する', function (): void {
+    expect(cacheGuardLocalCopyViolations(cacheGuardMethodTokens(TestCase::class, 'createApplication')))
+        ->toBe([],
+            'tests/TestCase.php の createApplication() が期待と一致しません。'
+            .'許可差分 (戻り値型 / fail-closed 確認 / 結線 1 行) 以外の変更を入れていないか、'
+            .'vendor の写しから文を消していないか確認してください。');
+
+    // #[\Override] は反射で別途見る (getStartLine から切り出したソースに属性行が入る保証が無い)。
+    expect((new ReflectionMethod(TestCase::class, 'createApplication'))->getAttributes(Override::class))
+        ->toHaveCount(1);
+});
+
+// ---------------------------------------------------------------------------
+// W7: 空振り検知
+// ---------------------------------------------------------------------------
+
+test('W7: 走査と検出器が空振りしていない', function (): void {
+    expect(CACHE_GUARD_VENDOR_CREATE_APPLICATION_TOKENS)->not->toBe([]);
+    expect(CACHE_GUARD_LOCAL_CREATE_APPLICATION_TOKENS)->not->toBe([]);
+    expect(cacheGuardMethodTokens(VendorTestCase::class, 'createApplication'))->not->toBe([]);
+    expect(cacheGuardMethodTokens(TestCase::class, 'createApplication'))->not->toBe([]);
+    expect(cacheGuardLaneBlocks(cacheGuardReadSource('tests/Pest.php')))->toHaveCount(3);
+
+    // 許可差分の合計が token 数の差と一致する (取りこぼした差分が無い)
+    $inserted = array_sum(array_map(
+        static fn (array $insertion): int => count($insertion['tokens']),
+        CACHE_GUARD_LOCAL_ALLOWED_INSERTIONS,
+    ));
+    expect(count(CACHE_GUARD_LOCAL_CREATE_APPLICATION_TOKENS) - count(CACHE_GUARD_VENDOR_CREATE_APPLICATION_TOKENS))
+        ->toBe($inserted);
+
+    // 検出器が負例に反応する (実在ファイルの構成に依存させない)
+    expect(cacheGuardBootstrapOrderViolations(
+        cacheGuardNormalizedTokens('<?php $app->make(Kernel::class)->bootstrap();'), 'probe'
+    ))->not->toBe([]);
+    expect(cacheGuardLaneWiringViolations(cacheGuardNormalizedTokens('<?php pest()->extend(TestCase::class);'), 'probe'))
+        ->not->toBe([]);
+    expect(cacheGuardTokenListViolations(['a'], ['b'], 'probe'))->not->toBe([]);
+    expect(cacheGuardLocalCopyViolations([]))->not->toBe([]);
+});
+
+// ---------------------------------------------------------------------------
+// W8: 負のコントロール
+// ---------------------------------------------------------------------------
+
+test('W8: 結線が bootstrap の後にある形 / 結線が無い形を検出する', function (): void {
+    $afterBootstrap = <<<'PHP'
+    <?php
+    class Probe {
+        public function createApplication() {
+            $app = require 'bootstrap/app.php';
+            $app->make(Kernel::class)->bootstrap();
+            PlainDataCacheGuard::registerBeforeBootstrap($app);
+            return $app;
+        }
+    }
+    PHP;
+    expect(cacheGuardBootstrapOrderViolations(cacheGuardNormalizedTokens($afterBootstrap), 'fixture'))
+        ->toHaveCount(1);
+
+    $missing = <<<'PHP'
+    <?php
+    class Probe {
+        public function createApplication() {
+            $app = require 'bootstrap/app.php';
+            $app->make(Kernel::class)->bootstrap();
+            return $app;
+        }
+    }
+    PHP;
+    expect(cacheGuardBootstrapOrderViolations(cacheGuardNormalizedTokens($missing), 'fixture'))
+        ->toHaveCount(1);
+
+    // ★別メソッドで結線し別メソッドで bootstrap する形は、メソッド本体を渡す限り
+    //   それぞれ 1 件ずつ落ちる (ファイル全体を渡すと 0 件になってしまう形である)。
+    $splitWiring = <<<'PHP'
+    <?php
+    class Probe {
+        public function wire($app) {
+            PlainDataCacheGuard::registerBeforeBootstrap($app);
+        }
+        public function createApplication() {
+            $app = require 'bootstrap/app.php';
+            $app->make(Kernel::class)->bootstrap();
+            return $app;
+        }
+    }
+    PHP;
+    expect(cacheGuardBootstrapOrderViolations(cacheGuardNormalizedTokens($splitWiring), 'file-scope'))->toBe([]);
+});
+
+test('W8: レーンから flush / reset / assertInstalled が抜けた形を検出する', function (): void {
+    $complete = <<<'PHP'
+    <?php
+    pest()->extend(TestCase::class)
+        ->beforeEach(function (): void {
+            PlainDataCacheGuard::assertInstalled($this->app);
+        })
+        ->afterEach(function (): void {
+            try {
+                PlainDataCacheGuard::flushAndFailIfStray();
+            } finally {
+                PlainDataCacheGuard::reset();
+            }
+        })
+        ->in('Feature', 'Unit');
+    PHP;
+
+    $blocks = cacheGuardLaneBlocks($complete);
+    expect($blocks)->toHaveCount(1);
+    expect($blocks[0]['lanes'])->toBe(['Feature', 'Unit']);
+    expect(cacheGuardLaneWiringViolations($blocks[0]['tokens'], 'fixture'))->toBe([]);
+
+    $missingFlush = <<<'PHP'
+    <?php
+    pest()->extend(TestCase::class)
+        ->beforeEach(function (): void {
+            PlainDataCacheGuard::assertInstalled($this->app);
+        })
+        ->afterEach(function (): void {
+            try {
+                StrayHttpRequestGuard::flushAndFailIfStray();
+            } finally {
+                PlainDataCacheGuard::reset();
+            }
+        })
+        ->in('Feature', 'Unit');
+    PHP;
+
+    $resetOutsideFinally = <<<'PHP'
+    <?php
+    pest()->extend(TestCase::class)
+        ->beforeEach(function (): void {
+            PlainDataCacheGuard::assertInstalled($this->app);
+        })
+        ->afterEach(function (): void {
+            try {
+                PlainDataCacheGuard::flushAndFailIfStray();
+                PlainDataCacheGuard::reset();
+            } finally {
+                StrayHttpRequestGuard::reset();
+            }
+        })
+        ->in('Feature', 'Unit');
+    PHP;
+
+    $noTryFinally = <<<'PHP'
+    <?php
+    pest()->extend(TestCase::class)
+        ->beforeEach(function (): void {
+            PlainDataCacheGuard::assertInstalled($this->app);
+        })
+        ->afterEach(function (): void {
+            PlainDataCacheGuard::flushAndFailIfStray();
+            PlainDataCacheGuard::reset();
+        })
+        ->in('Feature', 'Unit');
+    PHP;
+
+    foreach ([
+        'flush が無い' => $missingFlush,
+        'reset が finally の外' => $resetOutsideFinally,
+        'try / finally の形でない' => $noTryFinally,
+    ] as $label => $damaged) {
+        expect($damaged)->not->toBe($complete, "{$label}: 合成入力が完全形と同じになっている");
+
+        $blocks = cacheGuardLaneBlocks($damaged);
+        expect(cacheGuardLaneWiringViolations($blocks[0]['tokens'], 'fixture'))
+            ->not->toBe([], "{$label}: 検出できていません");
+    }
+
+    $missingAssert = <<<'PHP'
+    <?php
+    pest()->extend(TestCase::class)
+        ->beforeEach(function (): void {
+        })
+        ->afterEach(function (): void {
+            try {
+                PlainDataCacheGuard::flushAndFailIfStray();
+            } finally {
+                PlainDataCacheGuard::reset();
+            }
+        })
+        ->in('Architecture');
+    PHP;
+
+    $blocks = cacheGuardLaneBlocks($missingAssert);
+    expect(cacheGuardLaneWiringViolations($blocks[0]['tokens'], 'fixture'))->toHaveCount(1);
+});
+
+test('W8: レーン集合が違う形を検出する', function (): void {
+    $fixture = <<<'PHP'
+    <?php
+    pest()->extend(TestCase::class)->in('Feature');
+    pest()->extend(TestCase::class)->in('Unit');
+    PHP;
+
+    $lanes = array_map(static fn (array $block): array => $block['lanes'], cacheGuardLaneBlocks($fixture));
+    expect($lanes)->not->toBe(CACHE_GUARD_EXPECTED_LANES);
+});
+
+test('W8: vendor 本体の token 増減・順序入れ替えを判定関数が検出する', function (): void {
+    $expected = CACHE_GUARD_VENDOR_CREATE_APPLICATION_TOKENS;
+
+    $added = $expected;
+    $added[] = ';';
+    expect(cacheGuardTokenListViolations($added, $expected, 'fixture'))->not->toBe([]);
+
+    $swapped = $expected;
+    [$swapped[6], $swapped[7]] = [$swapped[7], $swapped[6]];
+    expect(count($swapped))->toBe(count($expected)); // 数だけでは検出できないことの明示
+    expect(cacheGuardTokenListViolations($swapped, $expected, 'fixture'))->not->toBe([]);
+
+    expect(cacheGuardTokenListViolations($expected, $expected, 'fixture'))->toBe([]);
+});
+
+test('W8: ローカルの写しから既知の文を消した形を判定関数が検出する', function (): void {
+    // ★W5 (vendor 側) と W1 (順序) だけでは緑のまま通ってしまう改変を、W5b が捕まえる。
+    expect(cacheGuardLocalCopyViolations(CACHE_GUARD_LOCAL_CREATE_APPLICATION_TOKENS))->toBe([]);
+
+    foreach ([
+        'traitsUsedByTest の代入' => ['$this', '->', 'traitsUsedByTest', '=', 'class_uses_recursive'],
+        'cached config 分岐' => ['WithCachedConfig', '::', 'class'],
+        'cached routes 分岐' => ['WithCachedRoutes', '::', 'class'],
+        'return $app' => ['return', '$app', ';'],
+        '結線 1 行' => ['PlainDataCacheGuard', '::', 'registerBeforeBootstrap'],
+    ] as $label => $needle) {
+        $position = cacheGuardSequencePosition(CACHE_GUARD_LOCAL_CREATE_APPLICATION_TOKENS, $needle);
+        expect($position)->not->toBeNull("{$label} が期待列にありません");
+
+        $damaged = CACHE_GUARD_LOCAL_CREATE_APPLICATION_TOKENS;
+        array_splice($damaged, (int) $position, count($needle));
+
+        expect(cacheGuardLocalCopyViolations($damaged))->not->toBe([], "{$label}: 検出できていません");
+    }
+});
diff --git a/tests/Architecture/CachePayloadPlainDataGateTest.php b/tests/Architecture/CachePayloadPlainDataGateTest.php
index bc74a21e..e892472e 100644
--- a/tests/Architecture/CachePayloadPlainDataGateTest.php
+++ b/tests/Architecture/CachePayloadPlainDataGateTest.php
@@ -1,19 +1,34 @@
 <?php
 
 declare(strict_types=1);
+use Tests\Support\Cache\PlainDataGuardedRepository;
 
 /*
- * Architecture invariant: **キャッシュに入れてよいのは素のデータだけ** (配列 / 文字列 / 数値 / 真偽値)。
+ * Architecture invariant: **キャッシュに入れてよいのは素のデータだけ**
+ * (配列 / 文字列 / 数値 / 真偽値 / null)。
  *
  * SoT = lctl 台帳 feature `cache-payload-plain-data` の標準形 v1 (裁定 2026-08-06) と
  * AGENTS.md セキュリティ不変条件 11 / docs/app-integration-guide.md §7 不変条件 6。
  *
- * ★なぜ静的検査か (実行時検出では捕まらない):
- *   テストレーンは phpunit.xml で CACHE_STORE=array、config/cache.php の array store は
- *   'serialize' => false。**オブジェクトを put してもそのまま返る = テストは緑になる**。
- *   本番は database store で serialize され、serializable_classes => false のため
- *   読み戻しは __PHP_Incomplete_Class になる。つまり「テストで再現しない本番専用の壊れ方」であり、
- *   実行時 detector (KeyWritten 購読等) は原理的にこの穴を塞げない。
+ * ★2 層構成のうち**静的層**がこのファイルである (家系の裁定 AG-151 = 正典 v2)。
+ *   - 静的層 (ここ) が保証するのは「**申告なしに書き込み経路を増やせない**」ことである。
+ *     目録の payload 欄は**人間の申告**なので、書いた値が実際に素データかは保証しない
+ *   - 実行時層 (tests/Support/Cache/PlainDataCacheGuard.php) が保証するのは
+ *     「**テストが実行した書き込みの値が実際に素データである**」ことである。
+ *     受け皿 (Illuminate\Cache\Repository) を包んで保管先へ渡す前の値を再帰検査するので、
+ *     **直列化を一度も経由しない = テストレーンの array store でも同じように発火する**
+ *   - どちらも他方を包含しない。vendor 由来の書き込みは静的層の走査根に入らず (実行時層だけが見る)、
+ *     テストが 1 度も踏まない経路は実行時層に見えない (静的層だけが見る)
+ *
+ *   ※ 旧版のこの位置には「実行時 detector は原理的にこの穴を塞げない」という記述があったが、
+ *     これは**書き込みイベントを購読する型の検出器にだけ当てはまる主張**で、
+ *     受け皿を包んで値を見る型には当てはまらない。裁定 AG-151 が誤りとして棄却したので削除した。
+ *
+ * ★L4 (境界迂回) を**静的層だけで塞ぐ**ものがある。とくに `getStore()` は
+ *   vendor 自身が正常系で呼ぶため実行時には落とせない (RateLimiter の hit/increment 経路、
+ *   Repository::flushLocks() の自己呼び出し、スケジューラの排他など)。
+ *   よって「保管先を直接取得して書く」形を塞ぐのは**このファイルだけ**であり、
+ *   vendor が getStore() 経由で書く値は 2 層とも見えない (保証しないもの)。
  *
  * ★serializable_classes は **false 固定**であって「キーを消してよい」ではない:
  *   CacheManager は `config['cache.serializable_classes'] ?? null` を読み、各 store は
@@ -33,7 +48,13 @@
  *     (規則自体も検査 5b で固定)
  *   - 検査 6: 実行時 config('cache.serializable_classes') === false、store 単位の上書きなし
  *   - 検査 5b: role 判定規則そのものの正負コントロール (実在ファイルの構成に依存させない)
- *   - 検査 6b: 語彙表の健全性 (4 分類が互いに素 / 除外型が受け手型に混ざっていない)
+ *   - 検査 6b: 語彙表の健全性 (5 分類が互いに素 / 除外型が受け手型に混ざっていない)
+ *   - 検査 L4a-L4f (境界迂回): 受け皿を跨いで保管先へ届く / 受け皿の生成に割り込む書き方
+ *     (`extend` / `getStore` / `setStore` / `tags` / `macro` / `mixin` / `flushMacros` /
+ *     受け手型・保管先型の直接生成 / 継承・実装の宣言) が、**通常経路 0 件 +
+ *     実行時層の自己テストの exact-fit** に収まっている。
+ *     **キャッシュ記号に触れるファイル (L3 の面) の中では**、`new $class` のように
+ *     生成対象が静的に決まらない形も**未分類として落とす** (fail-closed)
  *   - 検査 7: 空振り検知 (走査ファイル数 / メソッド呼び出し数 / 解決できたキャッシュ式が 0 でない)
  *   - 検査 8: 自己参照コントロール (本ファイル自身を走査して書き込み 0 件・面 hit なし)
  *   - 検査 9 以降: 正負コントロール fixture (facade / チェーン / ヘルパ / DI / コンテナ /
@@ -51,6 +72,17 @@
  *     この形は実測 0 件で、通常のレビューで自明に不自然な書き方である
  *   ※ 受け手が cache と分かっている上での**動的メソッド名** (`->{$m}(...)` / `->$m(...)`) は
  *     素通りさせず `unclassified` として fail させる。literal 形 (`->{'put'}(...)`) は通常形と同じに分類する
+ *   - **走査根の外で宣言され、完全修飾名が組み込み Store の命名規則に一致しない第三者の
+ *     `Store` 実装**の直接生成・コンテナ束縛経由の取得 (`cachePayloadIsStoreType()` の限界)
+ *   - **クラス名を素の文字列で書いて動的に生成する形** (`$c = 'Illuminate\Cache\ArrayStore';
+ *     $s = new $c;`)。名前として書かれた `::class` 参照はその時点でファイルが面になるので
+ *     動的生成が落ちるが、**文字列リテラルは走査していない**。L4b の「直接生成を 0 件で pin する」
+ *     という主張は**この構文を除いた範囲**である
+ *   - **キャッシュ記号に触れないファイルの動的生成** (`(new $model)->getTable()` 等)。
+ *     そこで受け皿を組み立てても、その受け皿を使う書き込みは受け手として解決できないため、
+ *     下の「受け手そのものが動的に得られる形」と同じ限界に帰着する
+ *   - **受け手名として解決できない変数**への添字代入 (`$c['k'] = $v` の `$c` が型宣言を持たない形)。
+ *     既存の受け手解決の限界と同じ
  *   - **docblock だけで型付けされた受け手** (`@var Repository $c` の docblock を書いた直後に
  *     `$c->put(...)` する形)。型宣言 (引数 / プロパティ / promoted ctor param) のみを見る。
  *     ※同じファイルに対応する型の `use` があれば **L3 (面) には現れる**が、
@@ -110,30 +142,55 @@
  */
 const CACHE_PAYLOAD_WRITE_METHODS = [
     'put', 'add', 'forever', 'remember', 'rememberforever', 'sear',
-    'flexible', 'putmany', 'set', 'setmultiple',
+    'flexible', 'putmany', 'set', 'setmultiple', 'rememberwithwarmth', 'offsetset',
 ];
 
 /**
  * payload を書き込まない API (increment/decrement は整数のみ書けるため素データが構造的に保証される)。
  *
+ * `hasmacro` は macro 登録簿の**読み出し**であり、登録も呼び出しもしない
+ * (登録側の `macro` / `mixin` / `flushmacros` は BYPASS)。
+ *
  * @var list<string>
  */
 const CACHE_PAYLOAD_NON_WRITE_METHODS = [
     'get', 'many', 'getmultiple', 'has', 'missing', 'pull', 'forget', 'delete',
     'deletemultiple', 'flush', 'clear', 'increment', 'decrement',
     'supportstags', 'getprefix', 'getdefaultdriver', 'setdefaultdriver',
-    'forgetdriver', 'purge', 'extend', 'itemkey', 'refresheventdispatcher',
+    'forgetdriver', 'purge', 'itemkey', 'refresheventdispatcher', 'hasmacro',
 ];
 
 /**
  * 受け手を保ったまま連鎖する API。
  *
- * `getStore()` は `Illuminate\Contracts\Cache\Store` を返し **put / forever を持つ**ので
- * NON_WRITE ではなく CHAIN (`Cache::getStore()->put(...)` の抜けを塞ぐ)。
+ * `getStore()` / `tags()` はここに**置かない** — どちらも受け皿 (Repository) を跨いで
+ * 保管先へ届くので BYPASS である (L4)。辿って書き込みを数えるのではなく、
+ * 書き方そのものを 0 件で pin する。
+ *
+ * @var list<string>
+ */
+const CACHE_PAYLOAD_CHAIN_METHODS = ['store', 'driver', 'resolve', 'getfacaderoot'];
+
+/**
+ * 受け皿 (Repository) を跨いで保管先 (Store) へ届く / 受け皿の生成そのものに割り込む API。
+ * **通常経路は 0 件**で、実行時層の自己テストだけを名指しの目録へ exact-fit で登録する
+ * (家系の裁定 AG-151 の v2 要素 4「境界迂回の hard fail」)。
+ *
+ * - extend    独自 creator は CacheManager::repository() を通らないので実行時層の被覆から抜ける
+ *             (通らないことは tests/Feature/Cache/CachePayloadPlainDataGuardTest.php が実証する)。
+ *             判定は**通常経路 0 件 + GuardedBoundaryProbe の自己テストの exact-fit**である
+ * - getStore / setStore  保管先を直接触る = 受け皿を跨ぐ。`getStore()` は vendor 自身が
+ *             正常系で呼ぶため**実行時には落とせない** = ここが唯一の防壁である
+ * - tags      vendor の tags() は new TaggedCache(...) を素で生成するので guard が効かない。
+ *             加えて本番の database store は supportsTags() が false でタグ非対応
+ * - macro / mixin / flushMacros  Repository は Macroable を use しており、
+ *             macro 内から $this->store へ直接到達できる (末端 4 メソッドを通らない)
  *
  * @var list<string>
  */
-const CACHE_PAYLOAD_CHAIN_METHODS = ['store', 'driver', 'tags', 'resolve', 'getstore', 'getfacaderoot'];
+const CACHE_PAYLOAD_BYPASS_METHODS = [
+    'extend', 'getstore', 'setstore', 'tags', 'macro', 'mixin', 'flushmacros',
+];
 
 /**
  * 受け手がキャッシュでなくなる terminal (以降の連鎖を辿らない)。
@@ -149,6 +206,107 @@
     'expects', 'shouldhavereceived', 'shouldnothavereceived',
 ];
 
+/**
+ * L4: 境界迂回の**自己テスト**の目録 (exact-fit)。
+ *
+ * key   = `{相対パス}::{メソッド名 (全小文字)}` / `{相対パス}::new {完全修飾名}`
+ *         ★**完全修飾名で突き合わせる** (AGENTS.md 走査規約 (a))。短名では別名つき取り込みや
+ *           同名の別クラスを区別できない
+ * count = 出現回数 (完全一致。1 件増えたら必ず落ちる)
+ * rationale = 30 文字以上の具体的根拠
+ *
+ * ★登録できるのは **tests/Support/Cache/GuardedBoundaryProbe.php の 1 ファイルだけ**である
+ *   (検査 L4f が名指しで固定する)。「tests/Support/Cache/ 配下すべて」にはしない —
+ *   将来足した任意の補助ファイルが自己テストを名乗れてしまうため。
+ * ★**動的呼び出しで走査を避ける形は採らない** (検出力の裏取りが弱くなるため)。
+ * ★本目録に載せた呼び出しは**検査 1 (未分類 API の deny-by-default) の母集団からも除く**。
+ *   実行時層は保管先への素通し (`__call`) を落とすので、その自己テストは
+ *   「4 分類のどれでもない API 名」を意図的に呼ぶことになるためである。
+ *   目録に載っていない未知 API は従来どおり落ちる。
+ *
+ * @var array<string, array{count: int, rationale: string}>
+ */
+const CACHE_PAYLOAD_BOUNDARY_SELFTEST_INVENTORY = [
+    'tests/Support/Cache/GuardedBoundaryProbe.php::extend' => [
+        'count' => 1,
+        'rationale' => '独自 driver の creator が CacheManager::repository() を通らないことを実証する trip-wire。通らなくなったら L4 の根拠が変わる',
+    ],
+    'tests/Support/Cache/GuardedBoundaryProbe.php::flushmacros' => [
+        'count' => 1,
+        'rationale' => 'callMacro の finally で必ず登録を消すための 1 件。消さないと global afterEach の macro pin が二重に落ちる',
+    ],
+    'tests/Support/Cache/GuardedBoundaryProbe.php::guardprobemacro' => [
+        'count' => 1,
+        'rationale' => '登録した macro を実際に呼ぶ 1 件。実行時層の __call() が macro を使用時点で落とすことの負例になる',
+    ],
+    'tests/Support/Cache/GuardedBoundaryProbe.php::guardprobeunknownmethod' => [
+        'count' => 1,
+        'rationale' => 'macro でない未知メソッド (保管先への素通し) を呼ぶ 1 件。名指しで分類していない素通しが落ちることの負例になる',
+    ],
+    'tests/Support/Cache/GuardedBoundaryProbe.php::macro' => [
+        'count' => 2,
+        'rationale' => 'macro 経由の到達が使用時点で落ちること (callMacro) と、残存 macro を flush が検出すること (registerMacroWithoutUsing) の 2 件',
+    ],
+    'tests/Support/Cache/GuardedBoundaryProbe.php::new Illuminate\Cache\ArrayStore' => [
+        'count' => 2,
+        'rationale' => 'setStore の引数と独自 creator の保管先として使う。保管先の直接生成が検出されることの自己確認も兼ねる',
+    ],
+    'tests/Support/Cache/GuardedBoundaryProbe.php::new Illuminate\Cache\Repository' => [
+        'count' => 1,
+        'rationale' => '独自 creator が返す素の受け皿。guard を通らない受け皿が実際に作れてしまうことを実証するために必要な 1 件',
+    ],
+    'tests/Support/Cache/GuardedBoundaryProbe.php::setstore' => [
+        'count' => 1,
+        'rationale' => '受け皿の保管先を差し替える口が境界迂回として落ちることを固定する。落ちなくなると guard 付き受け皿の中身を入れ替えられる',
+    ],
+    'tests/Support/Cache/GuardedBoundaryProbe.php::tags' => [
+        'count' => 1,
+        'rationale' => 'guard 付き受け皿の tags() が境界迂回として落ちることを固定する。落ちなくなると TaggedCache 経由の書き込みが素通りする',
+    ],
+];
+
+/** L4 の自己テストを置いてよい唯一のファイル (相対パス)。 */
+const CACHE_PAYLOAD_BOUNDARY_SELFTEST_FILE = 'tests/Support/Cache/GuardedBoundaryProbe.php';
+
+/**
+ * L4d: 受け手型 / 保管先型の**継承・実装の宣言**を許す名指しの目録 (exact-fit)。
+ *
+ * key = `{相対パス}::{extends|implements} {完全修飾名}`。
+ * 任意の Repository サブクラスを作れば `new` の検出を逃れられるので、**宣言側で塞ぐ**。
+ *
+ * @var array<string, string>
+ */
+const CACHE_PAYLOAD_SUBCLASS_INVENTORY = [
+    'tests/Support/Cache/PlainDataGuardedRepository.php::extends Illuminate\Cache\Repository' => '実行時層の受け皿そのもの。値の末端 4 メソッドを override するには継承以外の手段が無い',
+    'tests/Support/Cache/PlainDataGuardedCacheManager.php::extends Illuminate\Cache\CacheManager' => '実行時層の manager そのもの。repository() を override して guard 付き受け皿を返すために継承する',
+];
+
+/**
+ * 保管先 (Store) の型かどうかの判定規則。
+ *
+ * 解決した完全修飾名が
+ *   (a) `Illuminate\Contracts\Cache\Store` である、または
+ *   (b) `Illuminate\Cache\` で始まり `Store` で終わる (ArrayStore / DatabaseStore / FileStore /
+ *       RedisStore / NullStore / MemoizedStore / StorageStore / FailoverStore …)
+ * のとき保管先の型とみなす。
+ *
+ * ★**保証しないもの**: **走査根の外で宣言され、完全修飾名が組み込み Store の命名規則に
+ *   一致しない第三者の Store 実装**の直接生成・解決は検出しない
+ *   (例: `new Vendor\Package\CacheBackend()` が vendor 内で Store を実装している形)。
+ *   `Cache::extend()` の pin は **CacheManager 経由で第三者 Store の面を増やす経路**を閉じるが、
+ *   **走査根の外の第三者 Store を直接生成する / 独自のコンテナ束縛で取得する経路までは
+ *   保証しない** (「唯一の登録口」とは書かない)。
+ *   規則そのものの正負は検査 L4e が固定する。
+ */
+function cachePayloadIsStoreType(string $fqcn): bool
+{
+    if ($fqcn === 'Illuminate\Contracts\Cache\Store') {
+        return true;
+    }
+
+    return str_starts_with($fqcn, 'Illuminate\Cache\\') && str_ends_with($fqcn, 'Store');
+}
+
 /**
  * L2: キャッシュ **書き込み経路**の目録 (deny-by-default / exact-fit)。
  *
@@ -159,18 +317,114 @@
  * proof   = 往復を固定している単体テストのパス (**実在を検査する**)
  * rationale = 30 文字以上の具体的根拠
  *
+ * kind  = 'plain'          …素データを入れる本来の経路。proof は**配列往復を固定する単体テスト**
+ *         'guard-selftest' …実行時層が違反を検出することを固定するための意図的な違反。
+ *                            proof は**その検出を固定する振る舞い検査**
+ *
  * 経路が 1 本しかない現状では専用 enum (app/Enums/Security/) + inventory クラス
  * (tests/Support/Security/) へ昇格させない (AGENTS.md 思考原則 2「今必要なものだけ作る」)。
  *
- * @var array<string, array{count: int, payload: string, proof: string, rationale: string}>
+ * @var array<string, array{kind: string, count: int, payload: string, proof: string, rationale: string}>
  */
 const CACHE_PAYLOAD_WRITE_INVENTORY = [
     'app/Services/FxRateService.php::put' => [
+        'kind' => 'plain',
         'count' => 1,
         'payload' => 'FxSnapshotDto::toArray() の連想配列 (float 1 / string 3)。オブジェクトは渡さない',
         'proof' => 'tests/Unit/DataTransferObjects/FxSnapshotDtoTest.php',
         'rationale' => '当日の USD/JPY レートを 1 日 cache する。読み戻しは is_array 検査 + FxSnapshotDto::fromArray() + 失敗時 Cache::forget() で標準形どおり',
     ],
+    'tests/Feature/Cache/CachePayloadPlainDataGuardTest.php::add' => [
+        'kind' => 'guard-selftest',
+        'count' => 1,
+        'payload' => '意図的な違反値 (stdClass) と素データの両方。add() が末端として検査されることを固定する',
+        'proof' => 'tests/Feature/Cache/CachePayloadPlainDataGuardTest.php',
+        'rationale' => '値の末端 4 メソッドのうち add が保管前に検査されることを実 API 経由で固定する。ここが無いと申告の裏取りが機械化されない',
+    ],
+    'tests/Feature/Cache/CachePayloadPlainDataGuardTest.php::flexible' => [
+        'kind' => 'guard-selftest',
+        'count' => 1,
+        'payload' => '意図的な違反値 (stdClass)。flexible が putMany へ合流することの実証に使う',
+        'proof' => 'tests/Feature/Cache/CachePayloadPlainDataGuardTest.php',
+        'rationale' => '糖衣 API の合流が将来変わったら guard の被覆が静かに減るため、実 API 経由で合流を固定する 1 件',
+    ],
+    'tests/Feature/Cache/CachePayloadPlainDataGuardTest.php::forever' => [
+        'kind' => 'guard-selftest',
+        'count' => 1,
+        'payload' => '意図的な違反値 (stdClass) と素データの両方。forever が末端として検査されることを固定する',
+        'proof' => 'tests/Feature/Cache/CachePayloadPlainDataGuardTest.php',
+        'rationale' => '値の末端 4 メソッドのうち forever が保管前に検査されることを実 API 経由で固定する 1 件',
+    ],
+    'tests/Feature/Cache/CachePayloadPlainDataGuardTest.php::offsetset' => [
+        'kind' => 'guard-selftest',
+        'count' => 2,
+        'payload' => '意図的な違反値 (stdClass) と素データの両方。$cache[$k] = $v と $cache[$k] ??= $v の 2 形',
+        'proof' => 'tests/Feature/Cache/CachePayloadPlainDataGuardTest.php',
+        'rationale' => 'ArrayAccess 書き込みが put へ合流することを実 API 経由で固定する 2 件。静的層の添字代入検出とも対応する',
+    ],
+    'tests/Feature/Cache/CachePayloadPlainDataGuardTest.php::put' => [
+        'kind' => 'guard-selftest',
+        'count' => 2,
+        'payload' => '意図的な違反値 (stdClass / Closure 等) と素データの両方。通常形と配列キー形 (putMany 相当) の 2 件',
+        'proof' => 'tests/Feature/Cache/CachePayloadPlainDataGuardTest.php',
+        'rationale' => '実行時層が「保管前の値を再帰検査して落とす」ことを実 API 経由で固定する唯一の場所。ここが無いと申告の裏取りが機械化されない',
+    ],
+    'tests/Feature/Cache/CachePayloadPlainDataGuardTest.php::putmany' => [
+        'kind' => 'guard-selftest',
+        'count' => 1,
+        'payload' => '意図的な違反値 (stdClass) を含む連想配列。putMany が末端として検査されることを固定する',
+        'proof' => 'tests/Feature/Cache/CachePayloadPlainDataGuardTest.php',
+        'rationale' => '値の末端 4 メソッドのうち putMany が保管前に検査されることを実 API 経由で固定する 1 件',
+    ],
+    'tests/Feature/Cache/CachePayloadPlainDataGuardTest.php::remember' => [
+        'kind' => 'guard-selftest',
+        'count' => 1,
+        'payload' => '意図的な違反値 (stdClass)。remember が rememberWithWarmth 経由で put へ合流することの実証に使う',
+        'proof' => 'tests/Feature/Cache/CachePayloadPlainDataGuardTest.php',
+        'rationale' => '糖衣 API の合流が将来変わったら guard の被覆が静かに減るため、実 API 経由で合流を固定する 1 件',
+    ],
+    'tests/Feature/Cache/CachePayloadPlainDataGuardTest.php::rememberforever' => [
+        'kind' => 'guard-selftest',
+        'count' => 1,
+        'payload' => '意図的な違反値 (stdClass)。rememberForever が forever へ合流することの実証に使う',
+        'proof' => 'tests/Feature/Cache/CachePayloadPlainDataGuardTest.php',
+        'rationale' => '糖衣 API の合流が将来変わったら guard の被覆が静かに減るため、実 API 経由で合流を固定する 1 件',
+    ],
+    'tests/Feature/Cache/CachePayloadPlainDataGuardTest.php::rememberwithwarmth' => [
+        'kind' => 'guard-selftest',
+        'count' => 1,
+        'payload' => '意図的な違反値 (stdClass)。rememberWithWarmth が put へ合流することの実証に使う',
+        'proof' => 'tests/Feature/Cache/CachePayloadPlainDataGuardTest.php',
+        'rationale' => '糖衣 API の合流が将来変わったら guard の被覆が静かに減るため、実 API 経由で合流を固定する 1 件',
+    ],
+    'tests/Feature/Cache/CachePayloadPlainDataGuardTest.php::sear' => [
+        'kind' => 'guard-selftest',
+        'count' => 1,
+        'payload' => '意図的な違反値 (stdClass)。sear が rememberForever 経由で forever へ合流することの実証に使う',
+        'proof' => 'tests/Feature/Cache/CachePayloadPlainDataGuardTest.php',
+        'rationale' => '糖衣 API の合流が将来変わったら guard の被覆が静かに減るため、実 API 経由で合流を固定する 1 件',
+    ],
+    'tests/Feature/Cache/CachePayloadPlainDataGuardTest.php::set' => [
+        'kind' => 'guard-selftest',
+        'count' => 1,
+        'payload' => '意図的な違反値 (stdClass)。PSR-16 の set が put へ合流することの実証に使う',
+        'proof' => 'tests/Feature/Cache/CachePayloadPlainDataGuardTest.php',
+        'rationale' => '糖衣 API の合流が将来変わったら guard の被覆が静かに減るため、実 API 経由で合流を固定する 1 件',
+    ],
+    'tests/Feature/Cache/CachePayloadPlainDataGuardTest.php::setmultiple' => [
+        'kind' => 'guard-selftest',
+        'count' => 1,
+        'payload' => '意図的な違反値 (stdClass)。PSR-16 の setMultiple が putMany へ合流することの実証に使う',
+        'proof' => 'tests/Feature/Cache/CachePayloadPlainDataGuardTest.php',
+        'rationale' => '糖衣 API の合流が将来変わったら guard の被覆が静かに減るため、実 API 経由で合流を固定する 1 件',
+    ],
+    'tests/Support/Cache/BootTimeCacheWriteProbeProvider.php::put' => [
+        'kind' => 'guard-selftest',
+        'count' => 1,
+        'payload' => '起動中に意図的に入れるオブジェクト (stdClass)。provider 自身が例外を握り潰す',
+        'proof' => 'tests/Feature/Cache/CachePayloadPlainDataGuardTest.php',
+        'rationale' => '起動 (bootstrap) 中の書き込みも guard が捕まえることを固定するための見本。結線点が beforeEach へ後退したら赤くなる',
+    ],
 ];
 
 /**
@@ -183,7 +437,11 @@
  *       no-payload-write = キャッシュに触れるが任意 payload を書く API を呼ばない (読み出し / 削除 / flush 等) /
  *       lock-only = 排他だけ /
  *       driver-handoff = 受け手 (driver/store) を解決するだけで、読み出し・書き込み・削除の
- *       いずれも行わず他のコンポーネントへそのまま渡す (T215: キューワーカーへの cache 注入が該当)
+ *       いずれも行わず他のコンポーネントへそのまま渡す (T215: キューワーカーへの cache 注入が該当) /
+ *       guard-implementation = 実行時層の実装そのもの。受け手型を**参照するだけ**で
+ *       キャッシュ API は 1 件も呼ばない (tests/Support/Cache/ 配下でだけ名乗れる) /
+ *       boundary-selftest = 境界迂回が hard fail することを固定する唯一の呼び出し元
+ *       (CACHE_PAYLOAD_BOUNDARY_SELFTEST_FILE ちょうどでだけ名乗れる)
  * ※「read-only」ではなく no-payload-write と呼ぶ。forget / flush を含む実態と名前を一致させるため
  *
  * @var array<string, array{role: string, rationale: string}>
@@ -217,12 +475,87 @@
         'role' => 'lock-only',
         'rationale' => '突き合わせコマンドの多重起動を再現するため Cache::lock を先取するのみ。payload は書かない',
     ],
+    'tests/Feature/Cache/CachePayloadPlainDataGuardTest.php' => [
+        'role' => 'write',
+        'rationale' => '実行時層の振る舞い検査。意図的に違反する値を書いて guard が落とすことを固定する唯一のファイル',
+    ],
     'tests/Feature/Queue/DeferredRetryHorizonTest.php' => [
         'role' => 'driver-handoff',
         'rationale' => 'Worker::setCache() へ渡すため app(\'cache\')->driver() で driver を解決するだけで、読み出し・書き込み・削除のいずれも行わない。未処理例外の計数は framework 側が整数で行う',
     ],
+    'tests/Support/Cache/BootTimeCacheWriteProbeProvider.php' => [
+        'role' => 'write',
+        'rationale' => '起動中の書き込みを guard が捕まえることを固定する見本 provider。boot() で意図的にオブジェクトを入れる',
+    ],
+    'tests/Support/Cache/GuardedBoundaryProbe.php' => [
+        'role' => 'boundary-selftest',
+        'rationale' => '境界迂回が hard fail することを固定する唯一の呼び出し元。L4 の自己テスト目録に登録できるのはこのファイルだけ',
+    ],
+    'tests/Support/Cache/PlainDataCacheGuard.php' => [
+        'role' => 'guard-implementation',
+        'rationale' => '実行時層の結線と accumulator。Repository::$macros の pin のために Repository を参照するだけで API は呼ばない',
+    ],
+    'tests/Support/Cache/PlainDataGuardedCacheManager.php' => [
+        'role' => 'guard-implementation',
+        'rationale' => '実行時層の manager。Store 型を参照してよい唯一のサイトで、repository() を override して受け皿を差し替える',
+    ],
+    'tests/Support/Cache/PlainDataGuardedRepository.php' => [
+        'role' => 'guard-implementation',
+        'rationale' => '実行時層の受け皿。Illuminate\Cache\Repository を継承して末端 4 メソッドを検査する。キャッシュ API 呼び出しは持たない',
+    ],
 ];
 
+/**
+ * L4c: guard 付き manager が保管先 (`$store`) を受け皿の第 1 引数以外へ流していないか。
+ *
+ * `$store` の出現は次の 2 か所ちょうどでなければならない (純関数。合成入力にも当てられる)。
+ *   (1) `Store $store` の型宣言の直後
+ *   (2) `new PlainDataGuardedRepository($store, …)` の**第 1 引数**
+ *
+ * ★(2) は「直前が `(`」だけでは足りない — 任意の関数呼び出しの第 1 引数でも通ってしまう。
+ *   `new` + 受け皿クラス名 + `(` の直後であることまで確認する。
+ *
+ * @return list<string> 違反理由。空なら整合
+ */
+function cachePayloadStoreLeakViolations(string $source): array
+{
+    /** @var list<PhpToken> $tokens */
+    $tokens = PhpToken::tokenize($source);
+
+    $occurrences = [];
+    $count = count($tokens);
+    for ($i = 0; $i < $count; $i++) {
+        if (! $tokens[$i]->is(T_VARIABLE) || $tokens[$i]->text !== '$store') {
+            continue;
+        }
+
+        $prev = cachePayloadPrev($tokens, $i - 1);
+        if ($prev !== null && $tokens[$prev]->text === 'Store') {
+            $occurrences[] = 'declaration';
+
+            continue;
+        }
+
+        // `new PlainDataGuardedRepository(` の直後 = 第 1 引数
+        $open = $prev;
+        $class = $open === null ? null : cachePayloadPrev($tokens, $open - 1);
+        $new = $class === null ? null : cachePayloadPrev($tokens, $class - 1);
+        $isFirstConstructorArgument = $open !== null && $tokens[$open]->text === '('
+            && $class !== null && $tokens[$class]->text === 'PlainDataGuardedRepository'
+            && $new !== null && $tokens[$new]->is(T_NEW);
+
+        $occurrences[] = $isFirstConstructorArgument
+            ? 'repository-first-argument'
+            : "leak@line{$tokens[$i]->line}";
+    }
+
+    if ($occurrences !== ['declaration', 'repository-first-argument']) {
+        return ['$store の出現が期待と一致しません: '.implode(' / ', $occurrences)];
+    }
+
+    return [];
+}
+
 /**
  * 走査対象の PHP ファイル一覧。
  *
@@ -315,6 +648,80 @@ function cachePayloadMatchingParen(array $tokens, int $open): ?int
     return null;
 }
 
+/**
+ * `[` の対応する `]` の index。
+ *
+ * @param  list<PhpToken>  $tokens
+ */
+function cachePayloadMatchingBracket(array $tokens, int $open): ?int
+{
+    $depth = 0;
+    $count = count($tokens);
+    for ($i = $open; $i < $count; $i++) {
+        if ($tokens[$i]->text === '[') {
+            $depth++;
+        } elseif ($tokens[$i]->text === ']') {
+            $depth--;
+            if ($depth === 0) {
+                return $i;
+            }
+        }
+    }
+
+    return null;
+}
+
+/**
+ * `extends A` / `implements A, B` の宣言句を読み、カンマ区切りの各名前を解決して返す。
+ *
+ * ★直前 token だけを見る形では不十分 — `class X implements SomeInterface, Store {}` の
+ *   `Store` の直前は `,` である。そこで T_EXTENDS / T_IMPLEMENTS を見つけたら
+ *   **宣言句全体 (`{` まで)** を読む。**解決できない名前は候補から外さず `null` で返す**
+ *   (未解決を落とす = AGENTS.md 走査規約 (b))。
+ *
+ * @param  list<PhpToken>  $tokens
+ * @param  array<string, string>  $useMap
+ * @return list<array{keyword: string, resolved: string|null, line: int}>
+ */
+function cachePayloadInheritanceClause(array $tokens, int $keywordIndex, array $useMap): array
+{
+    $keyword = strtolower($tokens[$keywordIndex]->text);
+    $declared = [];
+    $count = count($tokens);
+
+    for ($i = $keywordIndex + 1; $i < $count; $i++) {
+        $token = $tokens[$i];
+        if ($token->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
+            continue;
+        }
+        if ($token->text === '{' || $token->text === ';') {
+            break;
+        }
+        if ($token->text === ',') {
+            continue;
+        }
+        if ($token->is(T_IMPLEMENTS)) {
+            // `class X extends A implements B` の切り替え。implements 側は
+            // T_IMPLEMENTS を起点とする別の呼び出しが読むので、ここでは打ち切る (二重記録の防止)。
+            break;
+        }
+        if ($token->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
+            $declared[] = [
+                'keyword' => $keyword,
+                'resolved' => cachePayloadResolveName($token->text, $useMap),
+                'line' => $token->line,
+            ];
+
+            continue;
+        }
+
+        // 予期しない token (可変長の型構文など)。解決できない形として落とす。
+        $declared[] = ['keyword' => $keyword, 'resolved' => null, 'line' => $token->line];
+    }
+
+    return $declared;
+}
+
 /**
  * `use A\B\C;` / `use A\B\C as D;` から alias => FQCN の表を作る。
  * グループ use (`use A\{B, C};`) は本リポジトリに存在しないため扱わない (限界として冒頭に明記)。
@@ -449,7 +856,7 @@ function cachePayloadLiteralValue(string $raw): ?string
  *     素通りさせる理由が無いので `unclassified` として fail させる (実測 0 件)
  *
  * @param  list<PhpToken>  $tokens
- * @return list<array{method: string, line: int, kind: string}> kind: write|non_write|chain|terminal|unclassified
+ * @return list<array{method: string, line: int, kind: string}> kind: write|non_write|chain|terminal|bypass|unclassified
  */
 function cachePayloadFollowChain(array $tokens, int $operatorIndex): array
 {
@@ -506,6 +913,7 @@ function cachePayloadFollowChain(array $tokens, int $operatorIndex): array
             in_array($method, CACHE_PAYLOAD_WRITE_METHODS, true) => 'write',
             in_array($method, CACHE_PAYLOAD_NON_WRITE_METHODS, true) => 'non_write',
             in_array($method, CACHE_PAYLOAD_CHAIN_METHODS, true) => 'chain',
+            in_array($method, CACHE_PAYLOAD_BYPASS_METHODS, true) => 'bypass',
             in_array($method, CACHE_PAYLOAD_TERMINAL_METHODS, true) => 'terminal',
             default => 'unclassified',
         };
@@ -606,7 +1014,7 @@ function cachePayloadContainerMakeChain(array $tokens, int $closeIndex, array $u
  * `writes` は **構造体**で返す (文字列に畳んでから再パースすると `strrchr` 等で壊れるため)。
  * ヘルパの配列形 `cache([...], $ttl)` は method 名 `cache` として記録する。
  *
- * @return array{writes: list<array{relative: string, line: int, method: string}>, unclassified: list<string>, methods: list<string>, cacheCalls: int, methodCalls: int, surface: bool}
+ * @return array{writes: list<array{relative: string, line: int, method: string}>, unclassified: list<string>, methods: list<string>, bypasses: list<string>, bypassCounts: array<string, int>, subclassDeclarations: list<string>, cacheCalls: int, methodCalls: int, surface: bool}
  */
 function cachePayloadCollectFromSource(string $source, string $relative): array
 {
@@ -618,11 +1026,25 @@ function cachePayloadCollectFromSource(string $source, string $relative): array
     $writes = [];
     $unclassified = [];
     $methods = [];
+    /** @var list<string> $bypasses */
+    $bypasses = [];
+    /** @var array<string, int> $bypassCounts */
+    $bypassCounts = [];
+    /** @var list<string> $subclassDeclarations */
+    $subclassDeclarations = [];
+    /** @var list<string> $dynamicNewSites */
+    $dynamicNewSites = [];
     $cacheCalls = 0;
     $methodCalls = 0;
     $surface = false;
     $count = count($tokens);
 
+    /** 迂回 1 件を記録する (目録の key は解決済みの完全修飾名で作る)。 */
+    $recordBypass = function (string $key, string $site) use (&$bypasses, &$bypassCounts): void {
+        $bypasses[] = $site;
+        $bypassCounts[$key] = ($bypassCounts[$key] ?? 0) + 1;
+    };
+
     for ($i = 0; $i < $count; $i++) {
         $token = $tokens[$i];
 
@@ -636,6 +1058,42 @@ function cachePayloadCollectFromSource(string $source, string $relative): array
             }
         }
 
+        // L4b の fail-closed: `new` の対象が**名前として解決できない**形を落とす。
+        // `new $class` / `new ($expr)` は生成されるクラスが静的に決まらないため、
+        // 保管先の直接生成を隠せてしまう (`$store = new $class; $store->put(...)` は
+        // 受け手型の宣言も持たないので L2 にも現れない)。
+        // ★落とすのは**キャッシュ記号に触れるファイル (L3 の面)** の中だけである。
+        //   面に現れないファイルの動的生成まで落とすと、キャッシュと無関係な
+        //   `(new $model)->getTable()` 等が大量に巻き込まれ、目録が意味の無い儀式になる。
+        // 無名クラス (`new class extends Repository {}`) は T_EXTENDS の分岐が受け持つ。
+        if ($token->is(T_NEW)) {
+            $beforeNew = cachePayloadPrev($tokens, $i - 1);
+            $isMethodNamedNew = $beforeNew !== null
+                && $tokens[$beforeNew]->is([T_DOUBLE_COLON, T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR]);
+            $target = cachePayloadNext($tokens, $i + 1);
+            $isResolvableTarget = $target !== null && $tokens[$target]->is([
+                T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_CLASS, T_STATIC,
+            ]);
+            if (! $isMethodNamedNew && ! $isResolvableTarget) {
+                $dynamicNewSites[] = "{$relative}:{$token->line} → new <静的に解決できないクラス名>";
+            }
+        }
+
+        // L4d: 受け手型 / 保管先型の継承・実装の宣言 (宣言側で塞ぐ)。
+        if ($token->is([T_EXTENDS, T_IMPLEMENTS])) {
+            foreach (cachePayloadInheritanceClause($tokens, $i, $useMap) as $declared) {
+                if ($declared['resolved'] === null) {
+                    $unclassified[] = "{$relative}:{$declared['line']} → extends/implements <解決できない名前>";
+
+                    continue;
+                }
+                if (in_array($declared['resolved'], CACHE_PAYLOAD_RECEIVER_TYPES, true)
+                    || cachePayloadIsStoreType($declared['resolved'])) {
+                    $subclassDeclarations[] = "{$relative}::{$declared['keyword']} {$declared['resolved']}";
+                }
+            }
+        }
+
         $operatorIndex = null;
 
         if ($token->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
@@ -651,7 +1109,9 @@ function cachePayloadCollectFromSource(string $source, string $relative): array
             $isRootCallable = ! str_contains($callable, '\\');
             $lower = $isRootCallable ? $callable : '';
 
-            if (! $isMemberName && in_array($resolved, CACHE_PAYLOAD_RECEIVER_TYPES, true)) {
+            $isReceiverType = ! $isMemberName && in_array($resolved, CACHE_PAYLOAD_RECEIVER_TYPES, true);
+
+            if ($isReceiverType) {
                 $surface = true; // use 文・型宣言・::class 参照でも「面」としては hit する
                 $next = cachePayloadNext($tokens, $i + 1);
                 if ($next !== null && $tokens[$next]->is(T_DOUBLE_COLON)) {
@@ -661,6 +1121,23 @@ function cachePayloadCollectFromSource(string $source, string $relative): array
                 }
             }
 
+            $isStoreType = ! $isMemberName && cachePayloadIsStoreType($resolved);
+            if ($isStoreType) {
+                // 具体 store の名前に触れているファイルも「面」に数える
+                // (受け皿を自前で組み立てる材料に触れている、という事実は目録へ出す)。
+                $surface = true;
+            }
+
+            // L4b: 受け手型 / 保管先型の**直接生成**。受け皿を自前で作られると
+            //      guard 付き manager を通らない受け皿が生まれる。
+            if (($isReceiverType || $isStoreType)
+                && $prev !== null && $tokens[$prev]->is(T_NEW)) {
+                $recordBypass(
+                    "{$relative}::new {$resolved}",
+                    "{$relative}:{$token->line} → new {$resolved}",
+                );
+            }
+
             if (! $isMemberName && $lower === 'cache') {
                 $open = cachePayloadNext($tokens, $i + 1);
                 if ($open !== null && $tokens[$open]->text === '(') {
@@ -734,6 +1211,20 @@ function cachePayloadCollectFromSource(string $source, string $relative): array
                 if ($arrow !== null && $tokens[$arrow]->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR])) {
                     $operatorIndex = $arrow; // $cache->put(...)
                     $surface = true;
+                } elseif ($arrow !== null && $tokens[$arrow]->text === '[') {
+                    // ArrayAccess 書き込み (`$cache['k'] = $v` / `$cache['k'] ??= $v`)。
+                    // メソッド呼び出し走査では検出できないので専用の分岐を持つ。
+                    $closeBracket = cachePayloadMatchingBracket($tokens, $arrow);
+                    $assign = $closeBracket === null ? null : cachePayloadNext($tokens, $closeBracket + 1);
+                    if ($assign !== null && in_array($tokens[$assign]->text, ['=', '??='], true)) {
+                        $surface = true;
+                        $cacheCalls++;
+                        $writes[] = ['relative' => $relative, 'line' => $token->line, 'method' => 'offsetSet'];
+                        $methods[] = 'offsetset';
+                    } elseif ($closeBracket === null) {
+                        // ★対応する `]` を見つけられない = 解決できない形。見逃さずに落とす。
+                        $unclassified[] = "{$relative}:{$token->line} → \${$name}[…] (対応する ] を解決できない)";
+                    }
                 }
             }
         }
@@ -745,18 +1236,41 @@ function cachePayloadCollectFromSource(string $source, string $relative): array
         foreach (cachePayloadFollowChain($tokens, $operatorIndex) as $call) {
             $cacheCalls++;
             $methods[] = $call['method'];
+            $key = $relative.'::'.strtolower($call['method']);
+
             if ($call['kind'] === 'write') {
                 $writes[] = ['relative' => $relative, 'line' => $call['line'], 'method' => $call['method']];
+            } elseif ($call['kind'] === 'bypass') {
+                $recordBypass($key, "{$relative}:{$call['line']} → ->{$call['method']}()");
             } elseif ($call['kind'] === 'unclassified') {
-                $unclassified[] = "{$relative}:{$call['line']} → ->{$call['method']}()";
+                // ★実行時層は保管先への素通し (__call) を落とすため、その自己テストは
+                //   「4 分類のどれでもない API 名」を意図的に呼ぶ。自己テスト目録に
+                //   登録済みの呼び出しだけを迂回として数え、それ以外は従来どおり落とす。
+                if (array_key_exists($key, CACHE_PAYLOAD_BOUNDARY_SELFTEST_INVENTORY)) {
+                    $recordBypass($key, "{$relative}:{$call['line']} → ->{$call['method']}()");
+                } else {
+                    $unclassified[] = "{$relative}:{$call['line']} → ->{$call['method']}()";
+                }
             }
         }
     }
 
+    // ★動的生成はキャッシュ記号に触れるファイルの中だけ落とす (上の T_NEW 分岐の説明を参照)。
+    if ($surface) {
+        $unclassified = array_merge($unclassified, $dynamicNewSites);
+    }
+
+    sort($bypasses);
+    ksort($bypassCounts);
+    sort($subclassDeclarations);
+
     return [
         'writes' => $writes,
         'unclassified' => $unclassified,
         'methods' => $methods,
+        'bypasses' => $bypasses,
+        'bypassCounts' => $bypassCounts,
+        'subclassDeclarations' => $subclassDeclarations,
         'cacheCalls' => $cacheCalls,
         'methodCalls' => $methodCalls,
         'surface' => $surface,
@@ -777,18 +1291,59 @@ function cachePayloadCollectFromSource(string $source, string $relative): array
  *                      T215: `Worker::setCache()` へ渡すためだけに `app('cache')->driver()` を呼ぶ形が該当)
  *
  * @param  list<string>  $methods  実測メソッド (全小文字)
+ * @param  string  $path  宣言されたファイル (役割を任意のファイルが名乗れないようにするため)
  * @return list<string> 違反理由。空なら整合
  */
-function cachePayloadRoleViolations(string $role, array $methods, bool $hasWriteEntry): array
+function cachePayloadRoleViolations(string $role, array $methods, bool $hasWriteEntry, string $path = ''): array
 {
-    if (! in_array($role, ['write', 'no-payload-write', 'lock-only', 'driver-handoff'], true)) {
-        return ["role は write / no-payload-write / lock-only / driver-handoff のいずれか (宣言値: {$role})"];
+    $known = ['write', 'no-payload-write', 'lock-only', 'driver-handoff', 'guard-implementation', 'boundary-selftest'];
+    if (! in_array($role, $known, true)) {
+        return ['role は '.implode(' / ', $known)." のいずれか (宣言値: {$role})"];
     }
 
     if ($role === 'write') {
         return $hasWriteEntry ? [] : ['role=write なのに書き込み目録に entry がありません'];
     }
 
+    if ($role === 'guard-implementation') {
+        // 実行時層の実装そのもの。受け手型を**参照するだけ**で API は呼ばない、という申告である。
+        $violations = [];
+        if ($hasWriteEntry) {
+            $violations[] = 'role=guard-implementation なのに書き込み目録に entry があります';
+        }
+        if ($methods !== []) {
+            $violations[] = 'role=guard-implementation なのにキャッシュ API を呼んでいます: '.implode(', ', $methods);
+        }
+        if (! str_starts_with($path, 'tests/Support/Cache/')) {
+            $violations[] = 'role=guard-implementation は tests/Support/Cache/ 配下でだけ名乗れます: '.$path;
+        }
+
+        return $violations;
+    }
+
+    if ($role === 'boundary-selftest') {
+        // 境界迂回が hard fail することを固定する唯一の呼び出し元。
+        $violations = [];
+        if ($hasWriteEntry) {
+            $violations[] = 'role=boundary-selftest なのに書き込み目録に entry があります (payload は書かない)';
+        }
+        if ($path !== CACHE_PAYLOAD_BOUNDARY_SELFTEST_FILE) {
+            $violations[] = 'role=boundary-selftest を名乗れるのは '.CACHE_PAYLOAD_BOUNDARY_SELFTEST_FILE." だけです: {$path}";
+        }
+        $registered = false;
+        foreach (array_keys(CACHE_PAYLOAD_BOUNDARY_SELFTEST_INVENTORY) as $key) {
+            if (str_starts_with($key, $path.'::')) {
+                $registered = true;
+                break;
+            }
+        }
+        if (! $registered) {
+            $violations[] = 'role=boundary-selftest なのに L4 の自己テスト目録に entry がありません';
+        }
+
+        return $violations;
+    }
+
     $violations = [];
     if ($hasWriteEntry) {
         $violations[] = "role={$role} なのに書き込み目録に entry があります";
@@ -838,11 +1393,11 @@ function cachePayloadRoleViolations(string $role, array $methods, bool $hasWrite
 /**
  * 走査対象全体の収集結果 (同一プロセス内で 1 度だけ計算する)。
  *
- * @return array{writeCounts: array<string, int>, writeSites: list<string>, unclassified: list<string>, surfaces: array<string, list<string>>, cacheCalls: int, methodCalls: int, files: int}
+ * @return array{writeCounts: array<string, int>, writeSites: list<string>, unclassified: list<string>, surfaces: array<string, list<string>>, bypassSites: list<string>, bypassCounts: array<string, int>, subclassDeclarations: list<string>, cacheCalls: int, methodCalls: int, files: int}
  */
 function cachePayloadCollectAll(): array
 {
-    /** @var array{writeCounts: array<string, int>, writeSites: list<string>, unclassified: list<string>, surfaces: array<string, list<string>>, cacheCalls: int, methodCalls: int, files: int}|null $cached */
+    /** @var array{writeCounts: array<string, int>, writeSites: list<string>, unclassified: list<string>, surfaces: array<string, list<string>>, bypassSites: list<string>, bypassCounts: array<string, int>, subclassDeclarations: list<string>, cacheCalls: int, methodCalls: int, files: int}|null $cached */
     static $cached = null;
     if ($cached !== null) {
         return $cached;
@@ -852,6 +1407,12 @@ function cachePayloadCollectAll(): array
     $writeSites = [];
     $unclassified = [];
     $surfaces = [];
+    /** @var list<string> $bypassSites */
+    $bypassSites = [];
+    /** @var array<string, int> $bypassCounts */
+    $bypassCounts = [];
+    /** @var list<string> $subclassDeclarations */
+    $subclassDeclarations = [];
     $cacheCalls = 0;
     $methodCalls = 0;
     $files = 0;
@@ -872,6 +1433,11 @@ function cachePayloadCollectAll(): array
             $writeCounts[$key] = ($writeCounts[$key] ?? 0) + 1;
         }
         $unclassified = array_merge($unclassified, $collected['unclassified']);
+        $bypassSites = array_merge($bypassSites, $collected['bypasses']);
+        $subclassDeclarations = array_merge($subclassDeclarations, $collected['subclassDeclarations']);
+        foreach ($collected['bypassCounts'] as $key => $bypassCount) {
+            $bypassCounts[$key] = ($bypassCounts[$key] ?? 0) + $bypassCount;
+        }
 
         if ($collected['surface']) {
             $surfaces[$target['relative']] = $collected['methods'];
@@ -880,13 +1446,19 @@ function cachePayloadCollectAll(): array
 
     ksort($writeCounts);
     ksort($surfaces);
+    ksort($bypassCounts);
     sort($writeSites);
+    sort($bypassSites);
+    sort($subclassDeclarations);
 
     $cached = [
         'writeCounts' => $writeCounts,
         'writeSites' => $writeSites,
         'unclassified' => $unclassified,
         'surfaces' => $surfaces,
+        'bypassSites' => $bypassSites,
+        'bypassCounts' => $bypassCounts,
+        'subclassDeclarations' => $subclassDeclarations,
         'cacheCalls' => $cacheCalls,
         'methodCalls' => $methodCalls,
         'files' => $files,
@@ -940,10 +1512,13 @@ function cachePayloadCollectAll(): array
         // key のメソッド名は全小文字。'cache' はヘルパの配列形 cache([...], $ttl) 専用の名前。
         expect(in_array($method, CACHE_PAYLOAD_WRITE_METHODS, true) || $method === 'cache')
             ->toBeTrue("{$key}: key のメソッドが WRITE 語彙にありません");
+        expect(in_array($entry['kind'], ['plain', 'guard-selftest'], true))
+            ->toBeTrue("{$key}: kind は plain / guard-selftest のいずれか (宣言値: {$entry['kind']})");
         expect(is_file(base_path($path)))->toBeTrue("{$key}: 対象ファイルが実在しません");
         expect(is_file(base_path($entry['proof'])))->toBeTrue(
-            "{$key}: proof に指定した単体テスト {$entry['proof']} が実在しません。"
-            .'キャッシュへ入れる配列は「往復が壊れないこと」を単体テストで固定してください');
+            "{$key}: proof に指定した検査 {$entry['proof']} が実在しません。"
+            .'kind=plain はキャッシュへ入れる配列の「往復が壊れないこと」を単体テストで、'
+            .'kind=guard-selftest は「実行時層が落とすこと」を振る舞い検査で固定してください');
         expect(mb_strlen($entry['rationale']))->toBeGreaterThanOrEqual(30, "{$key}: rationale が短すぎます");
         expect(mb_strlen($entry['payload']))->toBeGreaterThanOrEqual(10, "{$key}: payload の説明が短すぎます");
     }
@@ -984,7 +1559,7 @@ function cachePayloadCollectAll(): array
             }
         }
 
-        expect(cachePayloadRoleViolations($entry['role'], $methods, $hasWrite))
+        expect(cachePayloadRoleViolations($entry['role'], $methods, $hasWrite, $path))
             ->toBe([], "{$path}: 宣言した role が実測と整合しません");
     }
 });
@@ -1021,6 +1596,25 @@ function cachePayloadCollectAll(): array
     expect(cachePayloadRoleViolations('driver-handoff', ['driver'], true))->not->toBe([]);
 
     expect(cachePayloadRoleViolations('unknown-role', ['get'], false))->not->toBe([]);
+
+    // guard-implementation (T228): 受け手型を参照するだけ。API を 1 件でも呼んだら違反、
+    // 許可パス外で名乗っても違反 (任意のファイルが迂回実装の免除に使えないようにする)。
+    $guardPath = 'tests/Support/Cache/PlainDataGuardedRepository.php';
+    expect(cachePayloadRoleViolations('guard-implementation', [], false, $guardPath))->toBe([]);
+    expect(cachePayloadRoleViolations('guard-implementation', ['put'], false, $guardPath))->not->toBe([]);
+    expect(cachePayloadRoleViolations('guard-implementation', ['get'], false, $guardPath))->not->toBe([]);
+    expect(cachePayloadRoleViolations('guard-implementation', [], true, $guardPath))->not->toBe([]);
+    expect(cachePayloadRoleViolations('guard-implementation', [], false, 'app/Services/FxRateService.php'))
+        ->not->toBe([]);
+
+    // boundary-selftest (T228): 名指しの 1 ファイルだけが名乗れ、L4 の自己テスト目録に
+    // entry を持ち、L2 の書き込み目録には entry を持たない。
+    expect(cachePayloadRoleViolations('boundary-selftest', ['tags'], false, CACHE_PAYLOAD_BOUNDARY_SELFTEST_FILE))
+        ->toBe([]);
+    expect(cachePayloadRoleViolations('boundary-selftest', ['tags'], true, CACHE_PAYLOAD_BOUNDARY_SELFTEST_FILE))
+        ->not->toBe([]);
+    expect(cachePayloadRoleViolations('boundary-selftest', ['tags'], false, 'tests/Support/Cache/OtherProbe.php'))
+        ->not->toBe([]);
 });
 
 // ---------------------------------------------------------------------------
@@ -1040,13 +1634,14 @@ function cachePayloadCollectAll(): array
     }
 });
 
-test('検査 6b: 語彙表が健全 (4 分類は互いに素 / 除外型は受け手型と重ならない)', function (): void {
+test('検査 6b: 語彙表が健全 (5 分類は互いに素 / 除外型は受け手型と重ならない)', function (): void {
     // ★同じメソッドが 2 つの分類に入ると match の順序で暗黙に勝敗が決まり、
     //   「WRITE のつもりが NON_WRITE として素通り」が静かに起きる。互いに素であることを固定する。
     $groups = [
         'WRITE' => CACHE_PAYLOAD_WRITE_METHODS,
         'NON_WRITE' => CACHE_PAYLOAD_NON_WRITE_METHODS,
         'CHAIN' => CACHE_PAYLOAD_CHAIN_METHODS,
+        'BYPASS' => CACHE_PAYLOAD_BYPASS_METHODS,
         'TERMINAL' => CACHE_PAYLOAD_TERMINAL_METHODS,
     ];
     $all = array_merge(...array_values($groups));
@@ -1064,6 +1659,155 @@ function cachePayloadCollectAll(): array
     }
 });
 
+// ---------------------------------------------------------------------------
+// 検査 L4: 境界迂回の hard fail (正典 v2 の要素 4)
+// ---------------------------------------------------------------------------
+
+test('検査 L4a: 受け皿の境界を迂回する API 呼び出しと直接生成が自己テスト目録と exact-fit で一致する', function (): void {
+    $result = cachePayloadCollectAll();
+
+    $declared = [];
+    foreach (CACHE_PAYLOAD_BOUNDARY_SELFTEST_INVENTORY as $key => $entry) {
+        $declared[$key] = $entry['count'];
+    }
+    ksort($declared);
+
+    expect($result['bypassCounts'])->toBe($declared,
+        '受け皿 (Illuminate\Cache\Repository) を跨いで保管先へ届く / 受け皿の生成に割り込む書き方は'
+        .'**通常経路 0 件**です (家系の裁定 AG-151 の境界迂回の hard fail)。'
+        .'Cache::extend / getStore / setStore / tags / macro / mixin / flushMacros / '
+        .'受け手型・保管先型の直接生成は、実行時層が値を見られない経路を作ります。'
+        .'実行時層の自己テストだけが CACHE_PAYLOAD_BOUNDARY_SELFTEST_INVENTORY へ登録できます。'
+        .PHP_EOL.'検出: '.implode(PHP_EOL, $result['bypassSites']));
+});
+
+test('検査 L4b: 自己テスト目録の各 entry が形式要件を満たし実測で非空である', function (): void {
+    expect(CACHE_PAYLOAD_BOUNDARY_SELFTEST_INVENTORY)->not->toBe([]);
+    $result = cachePayloadCollectAll();
+
+    foreach (CACHE_PAYLOAD_BOUNDARY_SELFTEST_INVENTORY as $key => $entry) {
+        expect($entry['count'])->toBeGreaterThanOrEqual(1, "{$key}: count は 1 以上");
+        expect(mb_strlen($entry['rationale']))->toBeGreaterThanOrEqual(30, "{$key}: rationale が短すぎます");
+        expect($result['bypassCounts'][$key] ?? 0)->toBe($entry['count'],
+            "{$key}: 目録の件数と実測が一致しません (実在しない登録も、件数のズレも落とす)");
+    }
+});
+
+test('検査 L4c: guard 付き manager は $store を受け皿の第 1 引数以外へ流さない', function (): void {
+    // ★保管先を外へ流出させると、受け皿を迂回して書ける経路が生まれる。
+    $relative = 'tests/Support/Cache/PlainDataGuardedCacheManager.php';
+    $source = file_get_contents(base_path($relative));
+    expect($source)->toBeString();
+
+    expect(cachePayloadStoreLeakViolations((string) $source))->toBe([],
+        "{$relative}: \$store は (1) `Store \$store` の型宣言 (2) "
+        .'`new PlainDataGuardedRepository($store, …)` の第 1 引数 の 2 か所ちょうどでなければなりません');
+});
+
+test('検査 L4c の正負コントロール: $store の流出を検出する', function (): void {
+    $ok = <<<'PHP'
+    <?php
+    final class Fixture {
+        public function repository(Store $store, array $config = [])
+        {
+            return new PlainDataGuardedRepository($store, Arr::only($config, ['store']));
+        }
+    }
+    PHP;
+    expect(cachePayloadStoreLeakViolations($ok))->toBe([]);
+
+    // 第 1 引数が別の変数へすり替わっている (受け皿が包む保管先が変わる)
+    $swapped = <<<'PHP'
+    <?php
+    final class Fixture {
+        public function repository(Store $store, array $config = [])
+        {
+            $copy = leak($store);
+            return new PlainDataGuardedRepository($copy, []);
+        }
+    }
+    PHP;
+    expect(cachePayloadStoreLeakViolations($swapped))->not->toBe([]);
+
+    // 第 2 引数へ回すと、受け皿の外へ保管先が漏れる
+    $leaked = <<<'PHP'
+    <?php
+    final class Fixture {
+        public function repository(Store $store, array $config = [])
+        {
+            return new PlainDataGuardedRepository(new ArrayStore, $store);
+        }
+    }
+    PHP;
+    expect(cachePayloadStoreLeakViolations($leaked))->not->toBe([]);
+
+    // 受け皿へ渡さずどこかへ渡す形
+    $handedOff = <<<'PHP'
+    <?php
+    final class Fixture {
+        public function repository(Store $store, array $config = [])
+        {
+            Registry::remember($store);
+            return new PlainDataGuardedRepository($store, []);
+        }
+    }
+    PHP;
+    expect(cachePayloadStoreLeakViolations($handedOff))->not->toBe([]);
+});
+
+test('検査 L4d: 受け手型 / 保管先型の継承・実装が名指しの目録と exact-fit で一致する', function (): void {
+    $result = cachePayloadCollectAll();
+
+    $declared = array_keys(CACHE_PAYLOAD_SUBCLASS_INVENTORY);
+    sort($declared);
+
+    expect($result['subclassDeclarations'])->toBe($declared,
+        '受け手型 / 保管先型を継承・実装すると `new` の検出を逃れて受け皿を自作できます。'
+        .'宣言側で塞ぐため CACHE_PAYLOAD_SUBCLASS_INVENTORY と exact-fit で一致させてください。');
+
+    foreach (CACHE_PAYLOAD_SUBCLASS_INVENTORY as $key => $rationale) {
+        expect(mb_strlen($rationale))->toBeGreaterThanOrEqual(30, "{$key}: rationale が短すぎます");
+        expect(is_file(base_path(explode('::', $key, 2)[0])))->toBeTrue("{$key}: 対象ファイルが実在しません");
+    }
+});
+
+test('検査 L4e: 保管先型の判定規則の正負コントロール', function (): void {
+    expect(cachePayloadIsStoreType('Illuminate\Contracts\Cache\Store'))->toBeTrue();
+    expect(cachePayloadIsStoreType('Illuminate\Cache\ArrayStore'))->toBeTrue();
+    expect(cachePayloadIsStoreType('Illuminate\Cache\DatabaseStore'))->toBeTrue();
+    expect(cachePayloadIsStoreType('Illuminate\Cache\MemoizedStore'))->toBeTrue();
+
+    expect(cachePayloadIsStoreType('Illuminate\Cache\Repository'))->toBeFalse();
+    expect(cachePayloadIsStoreType('App\Support\Storage\ObjectStore'))->toBeFalse();
+    expect(cachePayloadIsStoreType('Illuminate\Session\Store'))->toBeFalse();
+    expect(cachePayloadIsStoreType('Illuminate\Cache\StoreFactory'))->toBeFalse();
+});
+
+test('検査 L4f: 自己テスト目録の key は GuardedBoundaryProbe.php ちょうどにしか無い', function (): void {
+    // ★「tests/Support/Cache/ 配下すべて」にはしない — 将来足した任意の補助ファイルが
+    //   自己テストを名乗れてしまうため。
+    expect(is_file(base_path(CACHE_PAYLOAD_BOUNDARY_SELFTEST_FILE)))->toBeTrue();
+
+    foreach (array_keys(CACHE_PAYLOAD_BOUNDARY_SELFTEST_INVENTORY) as $key) {
+        expect(explode('::', $key, 2)[0])->toBe(CACHE_PAYLOAD_BOUNDARY_SELFTEST_FILE,
+            "{$key}: 自己テスト目録に登録できるのは ".CACHE_PAYLOAD_BOUNDARY_SELFTEST_FILE.' だけです');
+    }
+});
+
+test('検査 L4g: 実行時層の素通し許可が静的層の排他語彙の部分集合である', function (): void {
+    // ★実行時層は `Repository::__call()` の素通しのうち排他 2 語彙だけを通す。
+    //   その許可が静的層の TERMINAL 語彙 (payload を運ばないと分類した語彙) の
+    //   **部分集合**であることを固定し、2 か所で別々に育てられないようにする
+    //   (TERMINAL には mock 系も含むので一致ではなく部分集合である)。
+    expect(PlainDataGuardedRepository::STORE_PASSTHROUGH_METHODS)->toBe(['lock', 'restorelock']);
+    expect(array_values(array_intersect(
+        CACHE_PAYLOAD_TERMINAL_METHODS,
+        PlainDataGuardedRepository::STORE_PASSTHROUGH_METHODS
+    )))->toBe(PlainDataGuardedRepository::STORE_PASSTHROUGH_METHODS,
+        '実行時層が素通しを許した語彙は、静的層が TERMINAL (payload を運ばない) と分類した語彙の'
+        .'部分集合でなければなりません');
+});
+
 // ---------------------------------------------------------------------------
 // 検査 7-8: 空振り検知と自己参照コントロール
 // ---------------------------------------------------------------------------
@@ -1075,6 +1819,24 @@ function cachePayloadCollectAll(): array
     expect($result['methodCalls'])->toBeGreaterThan(0, 'メソッド呼び出しを 1 件も見ていない (token 走査が死んでいる)');
     expect($result['cacheCalls'])->toBeGreaterThan(0, 'キャッシュ受け手を 1 件も解決できていない (受け手解決が死んでいる)');
     expect($result['surfaces'])->not->toBe([], 'キャッシュに触れるファイルを 1 件も検出できていない');
+    expect($result['bypassSites'])->not->toBe([], '境界迂回の検出器が 1 件も反応していない (L4 の走査が死んでいる)');
+    expect($result['subclassDeclarations'])->not->toBe([], '継承・実装の検出器が 1 件も反応していない (L4d の走査が死んでいる)');
+
+    // 検出器そのものが負例で反応することを合成入力で確かめる (実在ファイルの構成に依存させない)。
+    $probe = cachePayloadCollectFromSource(<<<'PHP'
+    <?php
+    namespace App\Demo;
+    use Illuminate\Support\Facades\Cache;
+    use Illuminate\Contracts\Cache\Repository;
+    class Fixture {
+        public function run(Repository $cache, $obj): void {
+            Cache::getStore()->put('a', [1], 60);
+            $cache['k'] = $obj;
+        }
+    }
+    PHP, 'probe.php');
+    expect($probe['bypassCounts'])->toBe(['probe.php::getstore' => 1]);
+    expect($probe['writes'])->toHaveCount(1);
 });
 
 test('検査 8: 自己参照コントロール (本 gate 自身は書き込み経路にも面にも現れない)', function (): void {
@@ -1085,6 +1847,8 @@ function cachePayloadCollectAll(): array
     // 将来ここに code としてキャッシュ呼び出しを書いたら落ちる = 正しい挙動。
     expect(array_key_exists($self, $result['surfaces']))->toBeFalse();
     expect(array_filter($result['writeSites'], fn (string $s): bool => str_starts_with($s, $self)))->toBe([]);
+    expect(array_filter($result['bypassSites'], fn (string $s): bool => str_starts_with($s, $self)))->toBe([]);
+    expect(array_filter($result['subclassDeclarations'], fn (string $s): bool => str_starts_with($s, $self)))->toBe([]);
 });
 
 // ---------------------------------------------------------------------------
@@ -1115,7 +1879,10 @@ public function run(Repository $other, $dto): void {
     PHP;
 
     $result = cachePayloadCollectFromSource($fixture, 'fixture.php');
-    expect($result['writes'])->toHaveCount(10);
+    // ★`Cache::tags(['t'])->forever(...)` は L4 で**迂回**になったので書き込みには数えない
+    //   (辿って数えるのではなく、書き方そのものを 0 件で pin する側へ移した)。
+    expect($result['writes'])->toHaveCount(9);
+    expect($result['bypassCounts'])->toBe(['fixture.php::tags' => 1]);
     expect($result['unclassified'])->toBe([]);
     expect($result['surface'])->toBeTrue();
 });
@@ -1138,7 +1905,10 @@ public function run(): void {
     PHP;
 
     $result = cachePayloadCollectFromSource($fixture, 'fixture.php');
-    expect($result['writes'])->toHaveCount(5);
+    // ★`Cache::getStore()->put(...)` は L4 で**迂回**になった。書き込み検出は消えるが
+    //   保護は弱くならない (迂回として 0 件 pin されるため)。
+    expect($result['writes'])->toHaveCount(4);
+    expect($result['bypassCounts'])->toBe(['fixture.php::getstore' => 1]);
     expect($result['unclassified'])->toBe([]);
     expect($result['surface'])->toBeTrue();
 });
@@ -1211,6 +1981,8 @@ public function run(array $values, $store): void {
     $result = cachePayloadCollectFromSource($fixture, 'fixture.php');
     expect($result['writes'])->toBe([]);
     expect($result['unclassified'])->toHaveCount(1); // cache($values, 60) だけ
+    // 受け手型の直接生成そのものは L4b の迂回として検出される
+    expect($result['bypassCounts'])->toBe(['fixture.php::new Illuminate\Cache\Repository' => 1]);
 });
 
 test('負のコントロール: app()->make(...) 経由のコンテナ解決も検出する', function (): void {
@@ -1433,6 +2205,232 @@ public function run(): void {
     expect($result['writes'])->toBe([]);
 });
 
+test('負のコントロール: 境界迂回の 7 語彙をすべて検出する', function (): void {
+    $fixture = <<<'PHP'
+    <?php
+    namespace App\Demo;
+    use Illuminate\Support\Facades\Cache;
+    use Illuminate\Cache\Repository;
+    use Illuminate\Cache\CacheManager;
+    class Fixture {
+        public function run(Repository $cache, CacheManager $manager): void {
+            Cache::extend('x', fn () => null);
+            $cache->getStore();
+            $cache->setStore(null);
+            $cache->tags(['t']);
+            $manager->macro('m', fn () => null);
+            $manager->mixin(null);
+            $manager->flushMacros();
+        }
+    }
+    PHP;
+
+    $result = cachePayloadCollectFromSource($fixture, 'fixture.php');
+    expect($result['bypassCounts'])->toBe([
+        'fixture.php::extend' => 1,
+        'fixture.php::flushmacros' => 1,
+        'fixture.php::getstore' => 1,
+        'fixture.php::macro' => 1,
+        'fixture.php::mixin' => 1,
+        'fixture.php::setstore' => 1,
+        'fixture.php::tags' => 1,
+    ]);
+    expect($result['writes'])->toBe([]);
+    expect($result['unclassified'])->toBe([]);
+});
+
+test('負のコントロール: 受け手型 / 保管先型の直接生成を検出する', function (): void {
+    $fixture = <<<'PHP'
+    <?php
+    namespace App\Demo;
+    use Illuminate\Cache\ArrayStore;
+    use Illuminate\Cache\Repository;
+    use Illuminate\Contracts\Cache\Store as CacheStore;
+    class Fixture {
+        public function run(): void {
+            $a = new Repository(new ArrayStore);
+            $b = new \Illuminate\Cache\DatabaseStore(null, 'cache', '');
+            $c = new \Illuminate\Cache\CacheManager(null);
+        }
+    }
+    PHP;
+
+    $result = cachePayloadCollectFromSource($fixture, 'fixture.php');
+    expect($result['bypassCounts'])->toBe([
+        'fixture.php::new Illuminate\Cache\ArrayStore' => 1,
+        'fixture.php::new Illuminate\Cache\CacheManager' => 1,
+        'fixture.php::new Illuminate\Cache\DatabaseStore' => 1,
+        'fixture.php::new Illuminate\Cache\Repository' => 1,
+    ]);
+});
+
+test('負のコントロール: 受け手型 / 保管先型の継承・実装を 4 形すべて検出する', function (): void {
+    // ★直前 token だけを見る形では 2 番目の interface を落とす。宣言句全体を読む。
+    $fixture = <<<'PHP'
+    <?php
+    namespace App\Demo;
+    use Countable;
+    use Illuminate\Cache\Repository;
+    use Illuminate\Contracts\Cache\Store as CacheStore;
+    class Second implements Countable, \Illuminate\Contracts\Cache\Store {}
+    class Aliased implements CacheStore {}
+    class Fully implements \Illuminate\Contracts\Cache\Store {}
+    class Multiline implements
+        Countable,
+        CacheStore {}
+    class Inherited extends Repository {}
+    PHP;
+
+    $result = cachePayloadCollectFromSource($fixture, 'fixture.php');
+    expect($result['subclassDeclarations'])->toBe([
+        'fixture.php::extends Illuminate\Cache\Repository',
+        'fixture.php::implements Illuminate\Contracts\Cache\Store',
+        'fixture.php::implements Illuminate\Contracts\Cache\Store',
+        'fixture.php::implements Illuminate\Contracts\Cache\Store',
+        'fixture.php::implements Illuminate\Contracts\Cache\Store',
+    ]);
+});
+
+test('負のコントロール: 継承句に解決できない名前があれば未分類として落とす', function (): void {
+    // ★fail-closed 分岐の裏取り (AGENTS.md 走査規約 (b))。名前として読めない形を
+    //   黙って候補から外すと、宣言側で塞ぐ L4d をすり抜けられる。
+    $fixture = <<<'PHP'
+    <?php
+    namespace App\Demo;
+    class Fixture implements $dynamicInterface {}
+    PHP;
+
+    $result = cachePayloadCollectFromSource($fixture, 'fixture.php');
+    expect($result['unclassified'])->toHaveCount(1);
+    expect($result['unclassified'][0])->toContain('extends/implements');
+    expect($result['subclassDeclarations'])->toBe([]);
+});
+
+test('負のコントロール: 面の中の静的に解決できない new を未分類として落とす', function (): void {
+    // ★`$store = new $class;` は生成されるクラスが静的に決まらず、受け手型の宣言も持たないので
+    //   L4b にも L2 にも現れない。キャッシュ記号に触れているファイルでは**見逃さずに落とす**。
+    $fixture = <<<'PHP'
+    <?php
+    namespace App\Demo;
+    use Illuminate\Cache\ArrayStore;
+    class Fixture {
+        public function run(): void {
+            $class = ArrayStore::class;
+            $store = new $class;
+            $store->put('key', new \stdClass(), 60);
+            $other = new ($this->resolver())();
+        }
+    }
+    PHP;
+
+    $result = cachePayloadCollectFromSource($fixture, 'fixture.php');
+    expect($result['surface'])->toBeTrue();
+    expect($result['unclassified'])->toHaveCount(2);
+    foreach ($result['unclassified'] as $entry) {
+        expect($entry)->toContain('new <静的に解決できないクラス名>');
+    }
+});
+
+test('正のコントロール: 面でないファイルの動的 new と new という名前のメソッドは巻き込まない', function (): void {
+    // ★キャッシュ記号に触れないファイルの `(new $model)->getTable()` まで落とすと、
+    //   目録が意味の無い儀式になる (保証しないものとして冒頭に明記している)。
+    //   `Factory::new()` は**メソッド名**であって生成ではない。
+    $fixture = <<<'PHP'
+    <?php
+    namespace App\Demo;
+    class Fixture {
+        public function run(string $model): void {
+            $table = (new $model)->getTable();
+            $factory = PasskeyFactory::new();
+            $a = new \DateTimeImmutable();
+            $b = new static();
+            $c = new class {};
+        }
+    }
+    PHP;
+
+    $result = cachePayloadCollectFromSource($fixture, 'fixture.php');
+    expect($result['surface'])->toBeFalse();
+    expect($result['unclassified'])->toBe([]);
+});
+
+test('正のコントロール: 無関係な interface の implements は迂回にしない', function (): void {
+    $fixture = <<<'PHP'
+    <?php
+    namespace App\Demo;
+    use Countable;
+    use JsonSerializable;
+    class Fixture implements Countable, JsonSerializable {}
+    PHP;
+
+    $result = cachePayloadCollectFromSource($fixture, 'fixture.php');
+    expect($result['subclassDeclarations'])->toBe([]);
+    expect($result['unclassified'])->toBe([]);
+});
+
+test('負のコントロール: ArrayAccess 書き込みを 2 形とも検出する', function (): void {
+    $fixture = <<<'PHP'
+    <?php
+    namespace App\Demo;
+    use Illuminate\Cache\Repository;
+    class Fixture {
+        public function run(Repository $cache, $dto): void {
+            $cache['a'] = $dto;
+            $cache['b'] ??= $dto;
+            $read = $cache['c'];
+        }
+    }
+    PHP;
+
+    $result = cachePayloadCollectFromSource($fixture, 'fixture.php');
+    expect($result['writes'])->toHaveCount(2);
+    expect(array_map(fn (array $w): string => $w['method'], $result['writes']))
+        ->toBe(['offsetSet', 'offsetSet']);
+    expect($result['unclassified'])->toBe([]);
+});
+
+test('正のコントロール: 自己テスト目録に登録された未知 API だけを未分類から外す', function (): void {
+    // ★実行時層の自己テストは「4 分類のどれでもない API 名」を意図的に呼ぶ。
+    //   目録に載っている呼び出しだけを迂回として数え、載っていないものは従来どおり落とす。
+    $fixture = <<<'PHP'
+    <?php
+    namespace App\Demo;
+    use Illuminate\Cache\Repository;
+    class Fixture {
+        public function run(Repository $cache): void {
+            $cache->guardProbeUnknownMethod();
+        }
+    }
+    PHP;
+
+    $registered = cachePayloadCollectFromSource($fixture, CACHE_PAYLOAD_BOUNDARY_SELFTEST_FILE);
+    expect($registered['unclassified'])->toBe([]);
+    expect($registered['bypassCounts'])
+        ->toBe([CACHE_PAYLOAD_BOUNDARY_SELFTEST_FILE.'::guardprobeunknownmethod' => 1]);
+
+    $unregistered = cachePayloadCollectFromSource($fixture, 'app/Demo/Fixture.php');
+    expect($unregistered['unclassified'])->toHaveCount(1);
+    expect($unregistered['bypassCounts'])->toBe([]);
+});
+
+test('正のコントロール: guard 付き受け皿の生成そのものは迂回にしない', function (): void {
+    // ★L4d が宣言側 (extends) で塞いでいるので、自前クラスの生成は通ってよい。
+    $fixture = <<<'PHP'
+    <?php
+    namespace App\Demo;
+    use Tests\Support\Cache\PlainDataGuardedRepository;
+    class Fixture {
+        public function run($store): void {
+            $repository = new PlainDataGuardedRepository($store, []);
+        }
+    }
+    PHP;
+
+    $result = cachePayloadCollectFromSource($fixture, 'fixture.php');
+    expect($result['bypassCounts'])->toBe([]);
+    expect($result['surface'])->toBeFalse();
+});
+
 test('正のコントロール: 排他・レート制限の型は受け手にしない', function (): void {
     $fixture = <<<'PHP'
     <?php
diff --git a/tests/Architecture/TemplateDivergenceLedgerFormatTest.php b/tests/Architecture/TemplateDivergenceLedgerFormatTest.php
index 272fc3df..9483a142 100644
--- a/tests/Architecture/TemplateDivergenceLedgerFormatTest.php
+++ b/tests/Architecture/TemplateDivergenceLedgerFormatTest.php
@@ -34,7 +34,7 @@
  * **明示件数との同期検査であって、例外を許す一覧ではない**。個別の D 番号を名指しして
  * 規則を免除する仕組みは持たない。登録を足した / 消したら同じ変更でこの値も直す。
  */
-const TEMPLATE_DIVERGENCE_ENTRY_COUNT = 28;
+const TEMPLATE_DIVERGENCE_ENTRY_COUNT = 29;
 
 /** 逸脱の登録簿の本文 (読めないことは不合格)。 */
 function templateDivergenceMarkdown(): string
diff --git a/tests/Feature/Cache/CachePayloadPlainDataGuardTest.php b/tests/Feature/Cache/CachePayloadPlainDataGuardTest.php
new file mode 100644
index 00000000..8603f4ec
--- /dev/null
+++ b/tests/Feature/Cache/CachePayloadPlainDataGuardTest.php
@@ -0,0 +1,379 @@
+<?php
+
+declare(strict_types=1);
+
+/*
+ * 実行時層 (キャッシュ素データ規約) の振る舞い検査。
+ *
+ * 静的層 (tests/Architecture/CachePayloadPlainDataGateTest.php) が保証するのは
+ * 「申告なしに書き込み経路を増やせない」ことだけで、目録の payload 欄は人間の申告である。
+ * ここで固定するのは「**テストが実行した書き込みの値が実際に素データである**」ことを
+ * 受け皿 (Illuminate\Cache\Repository) の側で機械的に検査できている、という実体である。
+ *
+ * ★意図的に違反を起こす検査は必ず CachePayloadViolationAssertions::expectViolation() を通す。
+ *   accumulator を drain しないと global afterEach の flushAndFailIfStray() が二重に落ちる。
+ */
+
+use Illuminate\Cache\Repository;
+use Illuminate\Contracts\Cache\Lock;
+use Illuminate\Contracts\Foundation\Application as ApplicationContract;
+use Illuminate\Support\Carbon;
+use Illuminate\Support\Collection;
+use Illuminate\Support\Facades\Cache;
+use Illuminate\Support\Facades\Event;
+use Illuminate\Support\Facades\Facade;
+use Tests\Support\Cache\CachePayloadViolation;
+use Tests\Support\Cache\CachePayloadViolationAssertions;
+use Tests\Support\Cache\GuardedBoundaryProbe;
+use Tests\Support\Cache\IsolatedApplicationProbe;
+use Tests\Support\Cache\PlainDataCacheGuard;
+use Tests\Support\Cache\PlainDataGuardedCacheManager;
+use Tests\Support\Cache\PlainDataGuardedRepository;
+use Tests\Support\Cache\PlainDataInspector;
+
+/**
+ * guard 付き受け皿へ**実 API 経由**で書き込む (合流の実証用)。
+ *
+ * remember / rememberForever / sear / set / setMultiple / flexible /
+ * rememberWithWarmth / ArrayAccess は vendor 実装が put / add / forever / putMany へ
+ * 合流する。その合流が将来変わったら本テストが落ちる (guard の被覆が静かに減らない)。
+ *
+ * ★受け皿は**型宣言の引数**で受ける。ローカル変数へ代入する書き方だと静的層が
+ *   受け手名を解決できず、書き込みが L2 目録に現れなくなる。
+ */
+function cachePayloadGuardWrite(Repository $cache, string $method, string $key, mixed $value): void
+{
+    match ($method) {
+        'put' => $cache->put($key, $value, 60),
+        'add' => $cache->add($key, $value, 60),
+        'forever' => $cache->forever($key, $value),
+        'putMany' => $cache->putMany([$key => $value], 60),
+        'set' => $cache->set($key, $value, 60),
+        'setMultiple' => $cache->setMultiple([$key => $value], 60),
+        'remember' => $cache->remember($key, 60, fn (): mixed => $value),
+        'rememberForever' => $cache->rememberForever($key, fn (): mixed => $value),
+        'sear' => $cache->sear($key, fn (): mixed => $value),
+        'flexible' => $cache->flexible($key, [60, 120], fn (): mixed => $value),
+        'rememberWithWarmth' => $cache->rememberWithWarmth($key, 60, fn (): mixed => $value),
+        'offsetSet' => $cache[$key] = $value,
+        'offsetCoalesce' => $cache[$key] ??= $value,
+        default => throw new InvalidArgumentException("未知の書き込みメソッド: {$method}"),
+    };
+}
+
+/**
+ * `put()` の**配列キー形** (vendor が putMany へ回す分岐) を実 API 経由で叩く。
+ *
+ * @param  array<string, mixed>  $values
+ */
+function cachePayloadGuardPutMap(Repository $cache, array $values): void
+{
+    $cache->put($values, 60);
+}
+
+/**
+ * 名指しで分類した排他の素通し (`PlainDataGuardedRepository::STORE_PASSTHROUGH_METHODS`) を
+ * 実 API 経由で叩く。受け皿は**型宣言の引数**で受ける (静的層の受け手解決のため)。
+ */
+function cachePayloadGuardLock(Repository $cache, string $method): Lock
+{
+    return match ($method) {
+        'lock' => $cache->lock('guard-passthrough-lock', 1),
+        'restoreLock' => $cache->restoreLock('guard-passthrough-lock', 'guard-owner'),
+        default => throw new InvalidArgumentException("未知の排他メソッド: {$method}"),
+    };
+}
+
+/** guard 付き受け皿を具体クラスへ絞って取り出す (ArrayAccess を使うため契約型では足りない)。 */
+function cachePayloadGuardedRepository(): Repository
+{
+    $repository = Cache::store('array');
+    expect($repository)->toBeInstanceOf(PlainDataGuardedRepository::class);
+    assert($repository instanceof Repository);
+
+    return $repository;
+}
+
+// ---------------------------------------------------------------------------
+// 検査 1-7: 実 API 経由の値検査 (合流の実証を含む)
+// ---------------------------------------------------------------------------
+
+test('検査 1: Event::fake() の後でも guard が効く', function (): void {
+    Event::fake();
+
+    CachePayloadViolationAssertions::expectViolation(
+        fn () => cachePayloadGuardWrite(cachePayloadGuardedRepository(), 'put', 'guard-event-fake', new stdClass),
+        ['put', 'guard-event-fake', 'OBJECT_FOUND(stdClass)'],
+    );
+});
+
+test('検査 2: 値の末端 4 メソッドがオブジェクトを落とす', function (string $method): void {
+    CachePayloadViolationAssertions::expectViolation(
+        fn () => cachePayloadGuardWrite(cachePayloadGuardedRepository(), $method, "guard-terminal-{$method}", new stdClass),
+        ['OBJECT_FOUND(stdClass)'],
+    );
+})->with(['put', 'add', 'forever', 'putMany']);
+
+test('検査 3: 糖衣 API も末端へ合流して落ちる', function (string $method): void {
+    CachePayloadViolationAssertions::expectViolation(
+        fn () => cachePayloadGuardWrite(cachePayloadGuardedRepository(), $method, "guard-sugar-{$method}", new stdClass),
+        ['OBJECT_FOUND(stdClass)'],
+    );
+})->with(['set', 'setMultiple', 'remember', 'rememberForever', 'sear', 'flexible', 'rememberWithWarmth']);
+
+test('検査 4: ArrayAccess 書き込みも末端へ合流して落ちる', function (string $method): void {
+    CachePayloadViolationAssertions::expectViolation(
+        fn () => cachePayloadGuardWrite(cachePayloadGuardedRepository(), $method, "guard-offset-{$method}", new stdClass),
+        ['OBJECT_FOUND(stdClass)'],
+    );
+})->with(['offsetSet', 'offsetCoalesce']);
+
+test('検査 4b: put() の配列キー形 (putMany 相当) も末端として検査される', function (): void {
+    // ★vendor の put() は `$key` が配列なら putMany へ回す。値の実体は $key 側にあるので、
+    //   override はこの分岐を専用に検査する。負例と正例の両方で固定する。
+    CachePayloadViolationAssertions::expectViolation(
+        fn () => cachePayloadGuardPutMap(cachePayloadGuardedRepository(), ['guard-put-map' => new stdClass]),
+        ['put', '(many)', "value['guard-put-map'] = OBJECT_FOUND(stdClass)"],
+    );
+
+    $cache = cachePayloadGuardedRepository();
+    cachePayloadGuardPutMap($cache, ['guard-put-map-ok' => ['a' => 1]]);
+
+    expect(PlainDataCacheGuard::drainForAssertion())->toBe([]);
+    expect($cache->get('guard-put-map-ok'))->toBe(['a' => 1]);
+});
+
+test('検査 5: クロージャも違反になる', function (): void {
+    CachePayloadViolationAssertions::expectViolation(
+        fn () => cachePayloadGuardWrite(cachePayloadGuardedRepository(), 'put', 'guard-closure', fn (): int => 1),
+        ['OBJECT_FOUND(Closure)'],
+    );
+});
+
+test('検査 6: 素のデータは通る', function (mixed $value): void {
+    $cache = cachePayloadGuardedRepository();
+    $key = 'guard-plain-'.md5(serialize($value));
+
+    cachePayloadGuardWrite($cache, 'put', $key, $value);
+
+    expect(PlainDataCacheGuard::drainForAssertion())->toBe([]);
+    expect($cache->get($key))->toBe($value);
+})->with([
+    [['a' => 1, 'b' => [true, false]]],
+    ['文字列'],
+    [42],
+    [1.5],
+    [true],
+    [null],
+    [[[[['深い']]]]],
+]);
+
+test('検査 7: 違反メッセージが method / key / 違反パスと種別 / 規約参照を持つ', function (): void {
+    $cache = cachePayloadGuardedRepository();
+
+    try {
+        cachePayloadGuardWrite($cache, 'add', 'guard-message', ['dto' => new stdClass]);
+        $this->fail('違反が検出されませんでした');
+    } catch (CachePayloadViolation $exception) {
+        expect($exception->getMessage())
+            ->toContain('add')
+            ->toContain('guard-message')
+            ->toContain("value['dto'] = OBJECT_FOUND(stdClass)")
+            ->toContain('AGENTS.md');
+    } finally {
+        PlainDataCacheGuard::drainForAssertion();
+    }
+});
+
+// ---------------------------------------------------------------------------
+// 検査 8-12: 値検査器そのもの (正負コントロールと境界)
+// ---------------------------------------------------------------------------
+
+test('検査 8: 値検査器が素データでない値を違反にする', function (): void {
+    expect(PlainDataInspector::violations(new stdClass))->toBe(['value = OBJECT_FOUND(stdClass)']);
+    expect(PlainDataInspector::violations(fn (): int => 1))->toBe(['value = OBJECT_FOUND(Closure)']);
+    expect(PlainDataInspector::violations(Carbon::parse('2026-08-18')))
+        ->toBe(['value = OBJECT_FOUND(Illuminate\Support\Carbon)']);
+    expect(PlainDataInspector::violations(new Collection([1, 2])))
+        ->toBe(['value = OBJECT_FOUND(Illuminate\Support\Collection)']);
+
+    $open = fopen('php://memory', 'r');
+    expect(PlainDataInspector::violations($open))->toBe(['value = RESOURCE_FOUND(stream)']);
+    if (is_resource($open)) {
+        fclose($open);
+    }
+
+    // 閉じた resource は is_resource() が false・is_scalar() も false =
+    // どの許可分岐にも当たらない。fail-closed で UNKNOWN_TYPE になる。
+    expect(PlainDataInspector::violations($open))->toBe(['value = UNKNOWN_TYPE(resource (closed))']);
+
+    // 入れ子の中の違反もパス付きで出る
+    expect(PlainDataInspector::violations(['a' => [0 => new stdClass]]))
+        ->toBe(["value['a'][0] = OBJECT_FOUND(stdClass)"]);
+});
+
+test('検査 9: 値検査器は素データを違反にしない', function (): void {
+    expect(PlainDataInspector::violations(['a' => 1, 'b' => 'x', 'c' => [true, null, 1.5]]))->toBe([]);
+    expect(PlainDataInspector::violations(null))->toBe([]);
+    expect(PlainDataInspector::violations([]))->toBe([]);
+});
+
+test('検査 10: 深さの境界 (32 は通り 33 は LIMIT_EXCEEDED)', function (): void {
+    $build = function (int $depth): array {
+        $value = ['leaf'];
+        for ($i = 1; $i < $depth; $i++) {
+            $value = [$value];
+        }
+
+        return $value;
+    };
+
+    expect(PlainDataInspector::violations($build(PlainDataInspector::MAX_DEPTH)))->toBe([]);
+    expect(PlainDataInspector::violations($build(PlainDataInspector::MAX_DEPTH + 1)))
+        ->toHaveCount(1)
+        ->and(PlainDataInspector::violations($build(PlainDataInspector::MAX_DEPTH + 1))[0])
+        ->toContain('LIMIT_EXCEEDED(depth)');
+});
+
+test('検査 11: ノード数の境界 (根を含む 10000 は通り 10001 は LIMIT_EXCEEDED)', function (): void {
+    // 根 (配列そのもの) を 1 と数えるので、要素数は MAX_NODES - 1 まで通る。
+    $ok = range(1, PlainDataInspector::MAX_NODES - 1);
+    $ng = range(1, PlainDataInspector::MAX_NODES);
+
+    expect(PlainDataInspector::violations($ok))->toBe([]);
+    expect(PlainDataInspector::violations($ng))->toBe(['value[9999] = LIMIT_EXCEEDED(nodes)']);
+});
+
+test('検査 12: 自己参照配列は停止して LIMIT_EXCEEDED になる', function (): void {
+    $value = ['a' => 1];
+    $value['self'] = &$value;
+
+    $violations = PlainDataInspector::violations($value);
+
+    expect($violations)->not->toBe([]);
+    expect(implode(' / ', $violations))->toContain('LIMIT_EXCEEDED');
+});
+
+// ---------------------------------------------------------------------------
+// 検査 13-16: 境界迂回の hard fail
+// ---------------------------------------------------------------------------
+
+test('検査 13: tags() は境界迂回として落ちる', function (): void {
+    CachePayloadViolationAssertions::expectViolation(
+        fn () => GuardedBoundaryProbe::callTags(cachePayloadGuardedRepository()),
+        ['BOUNDARY_BYPASS(tags)'],
+    );
+});
+
+test('検査 14: setStore() は境界迂回として落ちる', function (): void {
+    CachePayloadViolationAssertions::expectViolation(
+        fn () => GuardedBoundaryProbe::callSetStore(cachePayloadGuardedRepository()),
+        ['BOUNDARY_BYPASS(setStore)'],
+    );
+});
+
+test('検査 15: macro は使用時点で境界迂回として落ちる', function (): void {
+    CachePayloadViolationAssertions::expectViolation(
+        fn () => GuardedBoundaryProbe::callMacro(cachePayloadGuardedRepository()),
+        ['BOUNDARY_BYPASS(macro)', 'guardProbeMacro'],
+    );
+});
+
+test('検査 15b: macro でない未知メソッド (store 素通し) も境界迂回として落ちる', function (): void {
+    CachePayloadViolationAssertions::expectViolation(
+        fn () => GuardedBoundaryProbe::callUnknownMethod(cachePayloadGuardedRepository()),
+        ['BOUNDARY_BYPASS(storePassthrough)', 'guardProbeUnknownMethod'],
+    );
+});
+
+test('検査 15c: 名指しで分類した排他 2 語彙の素通しは通る', function (string $method): void {
+    // ★正のコントロール。`Illuminate\Cache\Repository` は lock() / restoreLock() を宣言せず、
+    //   `Cache::lock(...)` は __call() の素通しで保管先へ届く (vendor 実読)。
+    //   ここを塞ぐと role=lock-only の 6 ファイルが全滅する (S8 の計測で実測済み)。
+    //   排他は payload を運ばないので名指しで分類し、それ以外の素通しは検査 15b が落とす。
+    $lock = cachePayloadGuardLock(cachePayloadGuardedRepository(), $method);
+
+    expect($lock)->toBeInstanceOf(Lock::class);
+    expect(PlainDataCacheGuard::drainForAssertion())->toBe([]);
+})->with(['lock', 'restoreLock']);
+
+test('検査 16: flush が残存 macro を検出して既定へ戻す', function (): void {
+    GuardedBoundaryProbe::registerMacroWithoutUsing();
+
+    expect(fn () => PlainDataCacheGuard::flushAndFailIfStray())
+        ->toThrow(RuntimeException::class, 'MACRO_REGISTERED');
+
+    // flush の finally が reset() を通るので accumulator も macro も既定へ戻っている。
+    expect(PlainDataCacheGuard::drainForAssertion())->toBe([]);
+    expect(Repository::hasMacro('guardProbeResidualMacro'))->toBeFalse();
+});
+
+// ---------------------------------------------------------------------------
+// 検査 17-19: 握り潰しと結線の実体
+// ---------------------------------------------------------------------------
+
+test('検査 17: 起動 (bootstrap) 中の書き込みは provider が握り潰しても accumulator に残る', function (): void {
+    // ★afterEach で flush が呼ばれること自体は CacheGuardWiringGateTest の担当。
+    //   ここが固定するのは「結線がアプリ起動の前に入っているので起動中の書き込みも見える」ことである。
+    $original = Facade::getFacadeApplication();
+
+    $drained = IsolatedApplicationProbe::run(
+        fn (ApplicationContract $app): array => PlainDataCacheGuard::drainForAssertion()
+    );
+
+    expect(implode(' / ', $drained))->toContain('OBJECT_FOUND(stdClass)');
+
+    // 検査 22 (第 2 アプリの後始末) を同じ場所で固定する。
+    expect(Facade::getFacadeApplication())->toBe($original);
+    expect(Cache::store('array'))->toBeInstanceOf(PlainDataGuardedRepository::class);
+    expect(app('cache'))->toBeInstanceOf(PlainDataGuardedCacheManager::class);
+});
+
+test('検査 18: アプリ側が握り潰しても accumulator に残る', function (): void {
+    $cache = cachePayloadGuardedRepository();
+
+    try {
+        cachePayloadGuardWrite($cache, 'forever', 'guard-swallowed', new stdClass);
+    } catch (Throwable) {
+        // FxRateService と同じく握り潰す形を再現する
+    }
+
+    $drained = PlainDataCacheGuard::drainForAssertion();
+    expect($drained)->toHaveCount(1);
+    expect($drained[0])->toContain('OBJECT_FOUND(stdClass)');
+});
+
+test('検査 19: 独自 creator は CacheManager::repository() を通らない', function (): void {
+    // ★これは trip-wire である。通るようになったら L4 で extend を 0 件 pin する根拠が変わる。
+    $manager = app('cache');
+    expect($manager)->toBeInstanceOf(PlainDataGuardedCacheManager::class);
+    assert($manager instanceof PlainDataGuardedCacheManager);
+
+    $resolved = GuardedBoundaryProbe::resolveCustomDriver($manager);
+
+    expect($resolved)->toBeInstanceOf(Repository::class);
+    expect($resolved)->not->toBeInstanceOf(PlainDataGuardedRepository::class);
+});
+
+// ---------------------------------------------------------------------------
+// 検査 20-21: 後始末と空振り検知
+// ---------------------------------------------------------------------------
+
+test('検査 20: reset() は冪等で、drain 後は次テストへ漏れない', function (): void {
+    $cache = cachePayloadGuardedRepository();
+    cachePayloadGuardWrite($cache, 'put', 'guard-reset', ['ok']);
+
+    PlainDataCacheGuard::reset();
+    PlainDataCacheGuard::reset();
+
+    expect(PlainDataCacheGuard::drainForAssertion())->toBe([]);
+    expect(PlainDataCacheGuard::inspectedCount())->toBe(0);
+});
+
+test('検査 21: guard が実際に値を見ている (空振り検知)', function (): void {
+    $before = PlainDataCacheGuard::inspectedCount();
+
+    cachePayloadGuardWrite(cachePayloadGuardedRepository(), 'put', 'guard-inspected', ['ok']);
+
+    expect(PlainDataCacheGuard::inspectedCount())->toBeGreaterThan($before);
+});
diff --git a/tests/Feature/Config/ConfigHardeningTest.php b/tests/Feature/Config/ConfigHardeningTest.php
index 9126b5c3..549a631a 100644
--- a/tests/Feature/Config/ConfigHardeningTest.php
+++ b/tests/Feature/Config/ConfigHardeningTest.php
@@ -144,6 +144,25 @@ function evaluateConfigFileWithEnv(string $configFile, array $env): array
         'クラス許可一覧は作らない (lctl 標準形 v1 / AGENTS.md セキュリティ不変条件 11)');
 });
 
+// ========== prism-prompt: テンプレートのオブジェクトキャッシュを持たない (T228) ==========
+
+test('config/prism-prompt.php は cache.enabled を false で宣言している (env で開かない)', function (): void {
+    // ★同梱パッケージの PromptTemplate::fromYaml() は PromptTemplate オブジェクトそのものを
+    //   キャッシュへ入れる (AGENTS.md セキュリティ不変条件 11 に反する)。有効・無効を決める
+    //   設定は本リポジトリが所有しているので、env で開け直せる形を残さない。
+    $config = evaluateConfigFileWithEnv('prism-prompt.php', ['PRISM_PROMPT_CACHE' => 'true']);
+
+    expect($config['cache'])->toBeArray();
+    /** @var array<string, mixed> $cache */
+    $cache = $config['cache'];
+    expect($cache['enabled'])->toBeFalse(
+        'PromptTemplate::fromYaml() がオブジェクトをキャッシュへ入れるため、env で開けられてはならない');
+});
+
+test('prism-prompt.cache.enabled は実行時にも false', function (): void {
+    expect(config('prism-prompt.cache.enabled'))->toBeFalse();
+});
+
 // ========== fortify: passkeys ブロックの env 派生 (T166) ==========
 
 /*
diff --git a/tests/Pest.php b/tests/Pest.php
index 144da3a3..4e3c1cbd 100644
--- a/tests/Pest.php
+++ b/tests/Pest.php
@@ -26,6 +26,7 @@
 use Illuminate\Support\Str;
 use Kent013\PrismPrompt\Prompt;
 use Laravel\Cashier\Subscription;
+use Tests\Support\Cache\PlainDataCacheGuard;
 use Tests\Support\StrayHttpRequestGuard;
 use Tests\Support\StrayLlmCallGuard;
 use Tests\TestCase;
@@ -60,18 +61,24 @@
         // テスト本体で Http::fake([...]) を呼ぶと該当 URL は透過する
         // (Factory::fake() は prevent フラグを reset しないため共存する)。
         StrayHttpRequestGuard::install($this->app);
+
+        // キャッシュ guard は Tests\TestCase::createApplication() の bootstrap 前に結線済み。
+        // ここでは**結線が効いていること**だけを確認する (accumulator には触らない。
+        // 触ると起動中に記録された違反が消える)。
+        PlainDataCacheGuard::assertInstalled($this->app);
     })
     ->afterEach(function (): void {
         try {
             // stray call が記録されていれば test を fail させる (Service 層の
             // try/catch fallback で guard 例外が握り潰されてもここで必ず赤くなる)
             //
-            // ★2 つの guard は順に flush する。**同時発生時は先に throw した guard の
-            //   詳細だけが表示される** (もう一方の accumulator は finally の reset で
+            // ★3 つの guard は順に flush する。**同時発生時は先に throw した guard の
+            //   詳細だけが表示される** (他方の accumulator は finally の reset で
             //   捨てられる)。test は既に赤いので「静かに緑」にはならず、検出目的は達成される。
-            //   両方を集約する仕組みは入れない (今必要なものだけ作る)。
+            //   すべてを集約する仕組みは入れない (今必要なものだけ作る)。
             StrayLlmCallGuard::flushAndFailIfStray();
             StrayHttpRequestGuard::flushAndFailIfStray();
+            PlainDataCacheGuard::flushAndFailIfStray();
         } finally {
             // flush が throw しても次テストへ accumulator / Prompt::$fake を漏らさない
             if (Prompt::isFaking()) {
@@ -79,6 +86,7 @@
             }
             StrayLlmCallGuard::reset();
             StrayHttpRequestGuard::reset();
+            PlainDataCacheGuard::reset();
         }
     })
     ->in('Feature', 'Unit');
@@ -93,12 +101,15 @@
     ->beforeEach(function (): void {
         $this->withoutVite();
         StrayHttpRequestGuard::install($this->app);
+        PlainDataCacheGuard::assertInstalled($this->app);
     })
     ->afterEach(function (): void {
         try {
             StrayHttpRequestGuard::flushAndFailIfStray();
+            PlainDataCacheGuard::flushAndFailIfStray();
         } finally {
             StrayHttpRequestGuard::reset();
+            PlainDataCacheGuard::reset();
         }
     })
     ->in('Architecture');
@@ -137,17 +148,21 @@
         // Browser lane と bughunt 実行時の両方で共有 (registrar 参照)。install() 内の
         // stopFaking の後に上書きインストールするのが load-bearing。
         app(CannedPromptFakeRegistrar::class)->install();
+
+        PlainDataCacheGuard::assertInstalled($this->app);
     })
     ->afterEach(function (): void {
         try {
             StrayLlmCallGuard::flushAndFailIfStray();
             StrayHttpRequestGuard::flushAndFailIfStray();
+            PlainDataCacheGuard::flushAndFailIfStray();
         } finally {
             if (Prompt::isFaking()) {
                 Prompt::stopFaking();
             }
             StrayLlmCallGuard::reset();
             StrayHttpRequestGuard::reset();
+            PlainDataCacheGuard::reset();
         }
     })
     ->in('Browser');
diff --git a/tests/Support/Cache/BootTimeCacheWriteProbeProvider.php b/tests/Support/Cache/BootTimeCacheWriteProbeProvider.php
new file mode 100644
index 00000000..10b76848
--- /dev/null
+++ b/tests/Support/Cache/BootTimeCacheWriteProbeProvider.php
@@ -0,0 +1,36 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\Cache;
+
+use Illuminate\Support\Facades\Cache;
+use Illuminate\Support\ServiceProvider;
+use stdClass;
+use Throwable;
+
+/**
+ * 起動 (bootstrap) 中の書き込みを実行時層が捕まえることを固定するための見本 provider。
+ *
+ * `boot()` で意図的にオブジェクトをキャッシュへ入れ、**自分で例外を握り潰す**。
+ * 握り潰しても accumulator に記録が残ることを
+ * tests/Feature/Cache/CachePayloadPlainDataGuardTest.php が確認する。
+ * `catch` を消すと bootstrap 自体が例外になって別の理由で赤くなる (どちらでも赤い)。
+ *
+ * ★この provider は `IsolatedApplicationProbe` が組み立てる**第 2 のアプリ**にだけ登録する。
+ *   通常のテスト用アプリへ足すと bootstrap 中に落ちてテスト本体へ到達しない。
+ */
+final class BootTimeCacheWriteProbeProvider extends ServiceProvider
+{
+    /** 起動中に意図的な違反を書き込むキー。 */
+    public const string PROBE_KEY = 'cache-guard-boot-probe';
+
+    public function boot(): void
+    {
+        try {
+            Cache::put(self::PROBE_KEY, new stdClass, 60);
+        } catch (Throwable) {
+            // 意図的に握り潰す (アプリ側の try/catch fallback の再現)
+        }
+    }
+}
diff --git a/tests/Support/Cache/CachePayloadViolation.php b/tests/Support/Cache/CachePayloadViolation.php
new file mode 100644
index 00000000..a8a6195a
--- /dev/null
+++ b/tests/Support/Cache/CachePayloadViolation.php
@@ -0,0 +1,45 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\Cache;
+
+use RuntimeException;
+
+/**
+ * キャッシュへ素のデータでない値を書き込もうとした / 受け皿の境界を迂回したときに投げる。
+ *
+ * 書き込み呼び出しの**中で** throw されるため、失敗は書き込み元のテストへ帰属する
+ * (「読み出しで壊れる」形の弱い検出にしない)。呼び出し元が握り潰しても
+ * PlainDataCacheGuard の accumulator に残り、afterEach で必ず赤くなる。
+ */
+final class CachePayloadViolation extends RuntimeException
+{
+    /**
+     * @param  list<string>  $violations
+     */
+    public static function forWrite(string $method, string $key, array $violations): self
+    {
+        return new self(
+            "Cache::{$method}('{$key}') に素のデータでない値が渡されました:".PHP_EOL
+            .'  '.implode(PHP_EOL.'  ', $violations).PHP_EOL
+            .'キャッシュに入れてよいのは配列 / 文字列 / 数値 / 真偽値 / null だけです。'
+            .'読み出し側がアプリのコードで組み立て直せる形 (例: DTO なら toArray()) にしてください。'
+            .'規約: AGENTS.md セキュリティ不変条件 11 / '
+            .'静的層: tests/Architecture/CachePayloadPlainDataGateTest.php / '
+            .'実行時層: tests/Support/Cache/PlainDataGuardedRepository.php'
+            .' (LIMIT_EXCEEDED / UNKNOWN_TYPE は「guard が素のデータであることを証明できなかった」'
+            .'ことを表す。値を小さくするか、キャッシュに入れる形を見直すこと)',
+        );
+    }
+
+    public static function forBoundary(string $operation, string $detail): self
+    {
+        return new self(
+            "キャッシュ受け皿の境界を迂回しました: {$operation} ({$detail})。".PHP_EOL
+            .'受け皿 (Illuminate\Cache\Repository) を跨いで保管先へ届く経路は、'
+            .'実行時層が値を見られないため使えません。'
+            .'規約: AGENTS.md セキュリティ不変条件 11 / 家系の裁定 AG-151 の境界迂回の hard fail',
+        );
+    }
+}
diff --git a/tests/Support/Cache/CachePayloadViolationAssertions.php b/tests/Support/Cache/CachePayloadViolationAssertions.php
new file mode 100644
index 00000000..2c1363ab
--- /dev/null
+++ b/tests/Support/Cache/CachePayloadViolationAssertions.php
@@ -0,0 +1,37 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\Cache;
+
+use Closure;
+
+/**
+ * 意図的な違反を起こすテストのための共通 assertion。
+ *
+ * ★drain を忘れるとグローバル afterEach の `flushAndFailIfStray()` が二重に落ちて
+ *   **すべての負例が失敗する**。単に消すのではなく**記録内容まで assert する**
+ *   (「例外だけ別経路から出た」空振りを防ぐため)。
+ * ★PSR-4 は関数をオートロードしないので、global function ではなくクラスの static メソッドにする。
+ */
+final class CachePayloadViolationAssertions
+{
+    /**
+     * (1) 例外が投げられること (2) accumulator にちょうど 1 件記録され期待する断片を含むこと
+     * (3) drain 後に accumulator が空であること をまとめて検査する。
+     *
+     * @param  Closure(): mixed  $callback
+     * @param  list<string>  $expectedFragments
+     */
+    public static function expectViolation(Closure $callback, array $expectedFragments): void
+    {
+        expect($callback)->toThrow(CachePayloadViolation::class);
+
+        $drained = PlainDataCacheGuard::drainForAssertion();
+        expect($drained)->toHaveCount(1);
+        foreach ($expectedFragments as $fragment) {
+            expect($drained[0])->toContain($fragment);
+        }
+        expect(PlainDataCacheGuard::drainForAssertion())->toBe([]);
+    }
+}
diff --git a/tests/Support/Cache/GuardedBoundaryProbe.php b/tests/Support/Cache/GuardedBoundaryProbe.php
new file mode 100644
index 00000000..0aea112b
--- /dev/null
+++ b/tests/Support/Cache/GuardedBoundaryProbe.php
@@ -0,0 +1,85 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\Cache;
+
+use Illuminate\Cache\ArrayStore;
+use Illuminate\Cache\CacheManager;
+use Illuminate\Cache\Repository;
+
+/**
+ * 境界迂回が hard fail することを固定するための**唯一の**呼び出し元。
+ *
+ * ★受け皿を `Illuminate\Cache\Repository` 型の**引数**で受けるのが load-bearing —
+ *   静的層 (tests/Architecture/CachePayloadPlainDataGateTest.php) は型宣言から受け手名を作るため、
+ *   ローカル変数へ代入する書き方だと L4 の自己テスト目録が実測 0 件になって exact-fit が落ちる。
+ * ★境界 API を呼ぶ自己テストは**このファイルにだけ**置く (L4f が置き場所を名指しで固定する)。
+ */
+final class GuardedBoundaryProbe
+{
+    // ★`@return never` は付けない。引数の native 型は**通常の** Illuminate\Cache\Repository で、
+    //   通常の Repository の tags() は値を返し得る。「guard 付きを渡したときに例外になる」ことは
+    //   tests/Feature/Cache/CachePayloadPlainDataGuardTest.php が保証するのであって、
+    //   静的なメソッド契約ではない。
+
+    public static function callTags(Repository $cache): void
+    {
+        $cache->tags(['t']);
+    }
+
+    public static function callSetStore(Repository $cache): void
+    {
+        $cache->setStore(new ArrayStore);
+    }
+
+    public static function callUnknownMethod(Repository $cache): void
+    {
+        $cache->guardProbeUnknownMethod();
+    }
+
+    /**
+     * macro を登録して**使う**。guard の `__call()` が例外を投げるので、
+     * **`finally` で必ず登録を消す** — 消さないと global afterEach の macro 検査が
+     * MACRO_REGISTERED を記録し、意図的負例が二重に失敗する。
+     * 境界 API の呼び出しはこのファイルにしか置けないので、
+     * テスト本体の finally から `flushMacros()` を呼ぶ形にはできない。
+     */
+    public static function callMacro(Repository $cache): void
+    {
+        Repository::macro('guardProbeMacro', fn (): bool => true);
+
+        try {
+            $cache->guardProbeMacro();
+        } finally {
+            Repository::flushMacros();
+        }
+    }
+
+    /**
+     * macro を**登録するだけ**で使わない (flush の残存 macro 検出用)。
+     * 呼び出し側のテストが `flushAndFailIfStray()` を明示的に呼び、
+     * MACRO_REGISTERED の記録と既定への復元を確認する。
+     */
+    public static function registerMacroWithoutUsing(): void
+    {
+        Repository::macro('guardProbeResidualMacro', fn (): bool => true);
+    }
+
+    /**
+     * 独自 creator が `CacheManager::repository()` を通らないことの実証用。
+     *
+     * ★登録も解決も**引数の manager** に対して行う。facade へ登録して引数から解決すると、
+     *   facade root と引数が別インスタンスだったときに「extend の前提」ではなく
+     *   別インスタンスの問題で落ちる。CacheManager は静的層の受け手型なので
+     *   L4 の検出力は保たれる。
+     */
+    public static function resolveCustomDriver(CacheManager $manager): mixed
+    {
+        config()->set('cache.stores.guard-probe', ['driver' => 'guard-probe']);
+
+        $manager->extend('guard-probe', fn (): Repository => new Repository(new ArrayStore));
+
+        return $manager->store('guard-probe');
+    }
+}
diff --git a/tests/Support/Cache/IsolatedApplicationProbe.php b/tests/Support/Cache/IsolatedApplicationProbe.php
new file mode 100644
index 00000000..122fb128
--- /dev/null
+++ b/tests/Support/Cache/IsolatedApplicationProbe.php
@@ -0,0 +1,68 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\Cache;
+
+use Closure;
+use Illuminate\Container\Container;
+use Illuminate\Contracts\Console\Kernel;
+use Illuminate\Foundation\Application;
+use Illuminate\Support\Facades\Facade;
+use RuntimeException;
+
+/**
+ * 第 2 のアプリを **Tests\TestCase::createApplication() と同じ結線経路**で組み立て、
+ * コンテナと facade の状態を必ず元へ戻す。
+ *
+ * 起動 (bootstrap) 中の書き込みを実行時層が捕まえることを固定するには、
+ * 起動が失敗しても走り続けられる**別のアプリ**が要る (通常のテスト用アプリへ
+ * 違反する provider を足すと bootstrap 中に落ちてテスト本体へ到達しない)。
+ *
+ * 退避と復元の順序 (固定):
+ *   退避: Container::getInstance() → Facade::getFacadeApplication()
+ *   復元 (finally): Facade::clearResolvedInstances() → Facade::setFacadeApplication(退避値)
+ *         → Container::setInstance(退避値) → PlainDataCacheGuard::reset()
+ *
+ * ★戻すのは「**第 2 アプリの解決済みインスタンスを残さず、元の Application から
+ *   再解決できる状態**」であって、元の解決済みインスタンスそのものではない
+ *   (facade の解決済みインスタンスは消去して遅延再解決に任せる)。
+ */
+final class IsolatedApplicationProbe
+{
+    /**
+     * @template TReturn
+     *
+     * @param  Closure(Application): TReturn  $callback
+     * @return TReturn
+     */
+    public static function run(Closure $callback): mixed
+    {
+        $container = Container::getInstance();
+        $facadeApplication = Facade::getFacadeApplication();
+
+        try {
+            $app = require Application::inferBasePath().'/bootstrap/app.php';
+            if (! $app instanceof Application) {
+                throw new RuntimeException(
+                    'bootstrap/app.php が Application を返しませんでした: '.get_debug_type($app)
+                );
+            }
+
+            // ★ここが結線点。Tests\TestCase::createApplication() と**同じ関数**を
+            //   bootstrap() より前に呼ぶ (CacheGuardWiringGateTest が同一性を pin する)。
+            PlainDataCacheGuard::registerBeforeBootstrap($app);
+
+            $app->register(BootTimeCacheWriteProbeProvider::class);
+
+            $app->make(Kernel::class)->bootstrap();
+
+            return $callback($app);
+        } finally {
+            Facade::clearResolvedInstances();
+            Facade::setFacadeApplication($facadeApplication);
+            Container::setInstance($container);
+            PlainDataCacheGuard::reset();
+        }
+    }
+}
diff --git a/tests/Support/Cache/PlainDataCacheGuard.php b/tests/Support/Cache/PlainDataCacheGuard.php
new file mode 100644
index 00000000..9fa1ee5f
--- /dev/null
+++ b/tests/Support/Cache/PlainDataCacheGuard.php
@@ -0,0 +1,264 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\Cache;
+
+use Illuminate\Cache\CacheManager;
+use Illuminate\Cache\RateLimiter;
+use Illuminate\Cache\Repository;
+use Illuminate\Contracts\Foundation\Application;
+use ReflectionClass;
+use ReflectionProperty;
+use RuntimeException;
+
+/**
+ * キャッシュ素データ規約の**実行時層**。テスト実行中のキャッシュ書き込みを受け皿の側で
+ * 捕まえ、保管先へ渡す**前の値**を再帰検査する (家系の裁定 AG-151 = 正典 v2 の要素 2)。
+ *
+ * ## 2 層のうちの実行時層である
+ *
+ * 静的層 (tests/Architecture/CachePayloadPlainDataGateTest.php) が保証するのは
+ * 「申告なしに書き込み経路を増やせない」ことだけで、目録の payload 欄は**人間の申告**である。
+ * 本 guard が保証するのは「**テストが実行した書き込みの値が実際に素データである**」ことである。
+ * 受け皿を包んで値を見るので、**直列化を一度も経由しない array store でも同じように発火する**。
+ *
+ * ## 結線はアプリ起動の**前**
+ *
+ * 結線点は `Tests\TestCase::createApplication()` の `bootstrap()` 直前である
+ * (`registerBeforeBootstrap()`)。Pest の beforeEach では遅い — 起動 (bootstrap) 中の
+ * 書き込みは、vendor 由来だと静的層の走査根 (app / routes / database / tests) にも
+ * 入らないため、結線が遅れると**2 層とも沈黙する穴**になる。
+ * `Illuminate\Container\Container::extend()` は binding がまだ無くても登録でき、
+ * `CacheServiceProvider::register()` の `singleton('cache', …)` は extenders を消さない
+ * (`bind()` の `dropStaleInstances()` が消すのは instances と aliases だけ) ので、
+ * `cache` の初回解決時に必ず guard 付き manager になる。
+ *
+ * ## 違反は「その場で例外」と「accumulator への記録」の両方
+ *
+ * アプリ側の `catch (Throwable)` (準拠実装 `FxRateService` が読み書きを握り潰す形を持つ) で
+ * 例外が消えても、afterEach の `flushAndFailIfStray()` で必ず赤くなる
+ * (既存の `StrayHttpRequestGuard` / `StrayLlmCallGuard` と同じ設計)。
+ *
+ * ## 保証しないもの (**正本はここ**。AGENTS.md / docs には写さない)
+ *
+ * - `bootstrap/app.php` を require し終える前に走るコードからの書き込み
+ *   (結線はその直後なので、起動中 = bootstrap の書き込みは**対象に入る**)
+ * - **`getStore()` 経由**で保管先へ直接書く形。vendor 自身が正常系で `getStore()` を呼ぶため
+ *   実行時には落とせない (`Illuminate\Cache\RateLimiter::withoutSerializationOrCompression()` /
+ *   `Repository::flushLocks()` / スケジューラの排他)。ここを塞ぐのは**静的層 (L4) だけ**であり、
+ *   **vendor が `getStore()` 経由で書く値は 2 層とも見えない**
+ * - **保管先へ素通しさせた排他 2 語彙 (`lock` / `restoreLock`) の先**
+ *   (`PlainDataGuardedRepository::STORE_PASSTHROUGH_METHODS`。排他は payload を運ばない、が根拠)
+ * - **走査根の外で宣言された第三者 `Store` 実装**を直接生成する / 独自のコンテナ束縛で得る経路
+ * - テストが 1 度も踏まない経路 (実行時層は実行されないものを見ない)
+ * - `--parallel` の worker をまたいだ違反の集約 (accumulator はプロセス内 static)
+ * - macro を**同一テスト内で登録し、使わずに、`flushMacros()` で消す**形
+ *   (使えば `__call()` が落とし、残せば flush の macro 検査が落とすが、
+ *    使わずに消された登録はどちらにも現れない)
+ */
+final class PlainDataCacheGuard
+{
+    /** @var list<string> */
+    private static array $violations = [];
+
+    /** guard が実際に値を検査した回数 (空振り検知用)。 */
+    private static int $inspected = 0;
+
+    /**
+     * アプリ生成の直後・`bootstrap()` の**前**に呼ぶ。
+     *
+     * 順序は load-bearing である。
+     *  1. accumulator と計測値を初期化する (前テストが異常終了して afterEach が走らなかった
+     *     場合の残骸をここで消す)
+     *  2. `Repository::$macros` を検査して既定へ戻す (残骸があれば違反として記録してから)
+     *  3. `cache` の extender を登録する
+     *
+     * ★1 と 2 を Pest の beforeEach へ置いてはならない。結線が bootstrap 前に入る以上、
+     *   **起動中に記録された違反が beforeEach の初期化で消える**。provider が例外を握り潰した
+     *   場合、accumulator の記録が唯一の証拠である。
+     */
+    public static function registerBeforeBootstrap(Application $app): void
+    {
+        self::$violations = [];
+        self::$inspected = 0;
+        self::pinMacros();
+
+        $app->extend('cache', function (mixed $manager, Application $app): PlainDataGuardedCacheManager {
+            // ★受け取った実体が**素の** CacheManager ちょうどでなければ落とす。
+            //   独自 creator の登録口 (Cache::extend()) は静的層 L4 が 0 件で pin しているので、
+            //   引き継ぐべき状態は無い。想定外の実体を黙って捨てない。
+            if (! $manager instanceof CacheManager || $manager::class !== CacheManager::class) {
+                throw new RuntimeException(
+                    'cache binding が想定外の実体でした: '.get_debug_type($manager).'。'
+                    .'PlainDataCacheGuard の結線前提 (素の Illuminate\Cache\CacheManager) が崩れている。'
+                );
+            }
+
+            return new PlainDataGuardedCacheManager($app);
+        });
+    }
+
+    /**
+     * 結線が効いていることの確認 (Pest の beforeEach)。**accumulator には触らない**。
+     */
+    public static function assertInstalled(Application $app): void
+    {
+        $manager = $app->make('cache');
+        if (! $manager instanceof PlainDataGuardedCacheManager) {
+            throw new RuntimeException('キャッシュ guard が結線されていません: '.get_debug_type($manager));
+        }
+
+        // ★RateLimiter は起動中に cache を解決する (AppServiceProvider::boot() が
+        //   RateLimiter::for(...) を多数登録するため必ず解決される)。したがって
+        //   「起動前に結線できていた」ことの証拠になる。**解決されていなければ前提が崩れたので落とす**。
+        if (! $app->resolved(RateLimiter::class)) {
+            throw new RuntimeException(
+                'RateLimiter が起動中に解決されていません。起動前結線の前提 '
+                .'(AppServiceProvider::boot() の名前付き制限登録) が崩れている。'
+            );
+        }
+
+        // **読むだけで書き換えない**。プロパティが無ければ ReflectionException = その場で失敗。
+        $repository = (new ReflectionProperty(RateLimiter::class, 'cache'))
+            ->getValue($app->make(RateLimiter::class));
+
+        if (! $repository instanceof PlainDataGuardedRepository) {
+            throw new RuntimeException(
+                'RateLimiter が guard 付きでない受け皿を握っています: '.get_debug_type($repository)
+            );
+        }
+    }
+
+    /**
+     * 書き込まれる値を検査する。違反は accumulator に記録し、**その場でも例外**を投げる。
+     */
+    public static function inspect(string $method, string $key, mixed $value): void
+    {
+        self::$inspected++;
+
+        $violations = PlainDataInspector::violations($value);
+        if ($violations === []) {
+            return;
+        }
+
+        self::$violations[] = "{$method}('{$key}'): ".implode(' / ', $violations);
+
+        throw CachePayloadViolation::forWrite($method, $key, $violations);
+    }
+
+    /**
+     * 受け皿の境界を迂回した呼び出しを記録して例外にする。
+     */
+    public static function reportBoundary(string $operation, string $detail): never
+    {
+        self::$violations[] = "BOUNDARY_BYPASS({$operation}): {$detail}";
+
+        throw CachePayloadViolation::forBoundary($operation, $detail);
+    }
+
+    /**
+     * Pest の afterEach。残存 macro を検査して記録し、accumulator に記録があれば fail させる。
+     */
+    public static function flushAndFailIfStray(): void
+    {
+        try {
+            self::pinMacros();
+
+            if (self::$violations === []) {
+                return;
+            }
+
+            throw new RuntimeException(
+                'Plain-data cache violation detected during test execution. '
+                .'キャッシュに入れてよいのは素のデータだけ (AGENTS.md セキュリティ不変条件 11 / '
+                .'家系の裁定 AG-107・AG-151)。'.PHP_EOL.self::summarize(self::$violations)
+            );
+        } finally {
+            self::reset();
+        }
+    }
+
+    /** accumulator と計測値を消し、macro を**記録せずに**既定へ戻す。 */
+    public static function reset(): void
+    {
+        self::$violations = [];
+        self::$inspected = 0;
+        self::restoreMacros();
+    }
+
+    /**
+     * 意図的に違反を起こすテスト用の drain (`StrayLlmCallGuard` と同じ)。
+     *
+     * @return list<string>
+     */
+    public static function drainForAssertion(): array
+    {
+        $drained = self::$violations;
+        self::$violations = [];
+
+        return $drained;
+    }
+
+    /** guard が実際に値を見た回数 (空振り検知)。 */
+    public static function inspectedCount(): int
+    {
+        return self::$inspected;
+    }
+
+    /**
+     * `Repository::$macros` を検査して記録し、既定へ戻す。
+     */
+    private static function pinMacros(): void
+    {
+        $macros = self::readMacros();
+        if ($macros !== []) {
+            self::$violations[] = 'MACRO_REGISTERED('
+                .implode(', ', array_map(strval(...), array_keys($macros))).')';
+        }
+
+        self::restoreMacros();
+    }
+
+    /** 記録せず既定へ戻すだけ (reset() から呼ぶ。flush の直後に二重記録しない)。 */
+    private static function restoreMacros(): void
+    {
+        self::macrosProperty()->setValue(null, []);
+    }
+
+    /** @return array<array-key, mixed> */
+    private static function readMacros(): array
+    {
+        $macros = self::macrosProperty()->getValue();
+        if (! is_array($macros)) {
+            throw new RuntimeException('Repository::$macros が配列ではありません: '.get_debug_type($macros));
+        }
+
+        return $macros;
+    }
+
+    private static function macrosProperty(): ReflectionProperty
+    {
+        $reflection = new ReflectionClass(Repository::class);
+        if (! $reflection->hasProperty('macros')) {
+            throw new RuntimeException(
+                'Illuminate\Cache\Repository::$macros が存在しません。macro 経由の迂回 pin が'
+                .'空振りしている。vendor を読み直して pin を作り直すこと。'
+            );
+        }
+
+        return $reflection->getProperty('macros');
+    }
+
+    /**
+     * @param  list<string>  $violations
+     */
+    private static function summarize(array $violations): string
+    {
+        return implode(PHP_EOL, array_map(
+            static fn (string $violation, int $index): string => '  ['.($index + 1).'] '.$violation,
+            $violations,
+            array_keys($violations),
+        ));
+    }
+}
diff --git a/tests/Support/Cache/PlainDataGuardedCacheManager.php b/tests/Support/Cache/PlainDataGuardedCacheManager.php
new file mode 100644
index 00000000..91f372d8
--- /dev/null
+++ b/tests/Support/Cache/PlainDataGuardedCacheManager.php
@@ -0,0 +1,46 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\Cache;
+
+use Illuminate\Cache\CacheManager;
+use Illuminate\Contracts\Cache\Store;
+use Illuminate\Support\Arr;
+
+/**
+ * すべての cache driver を PlainDataGuardedRepository で包むテスト用 CacheManager。
+ *
+ * vendor の組み込み driver 生成 (`createArrayDriver()` 等) はいずれも `repository()` を
+ * 通るため、ここ 1 箇所の override で array / database / file いずれにも guard が効く。
+ * `Cache::extend()` の独自 creator は `repository()` を通らない
+ * (tests/Feature/Cache/CachePayloadPlainDataGuardTest.php が実証する)。
+ * よって静的層 (tests/Architecture/CachePayloadPlainDataGateTest.php の L4) が
+ * `Cache::extend()` を **通常経路 0 件 + GuardedBoundaryProbe の自己テストの exact-fit** で
+ * pin して口を塞いでいる。
+ *
+ * **本クラスは Illuminate\Contracts\Cache\Store を参照してよい唯一のサイトである**
+ * (vendor 互換シグネチャの要求)。`$store` は
+ * `new PlainDataGuardedRepository($store, ...)` の第 1 引数以外に現れてはならず、
+ * その構造条件は同 gate の L4c が機械検査する (store を外へ流出させると受け皿を迂回できる)。
+ */
+final class PlainDataGuardedCacheManager extends CacheManager
+{
+    /**
+     * {@inheritDoc}
+     *
+     * @param  array<string, mixed>  $config
+     * @return PlainDataGuardedRepository
+     */
+    public function repository(Store $store, array $config = [])
+    {
+        $repository = new PlainDataGuardedRepository($store, Arr::only($config, ['store']));
+
+        // vendor CacheManager::repository() と同じ event dispatcher 設定を再現する。
+        if ($config['events'] ?? true) {
+            $this->setEventDispatcher($repository);
+        }
+
+        return $repository;
+    }
+}
diff --git a/tests/Support/Cache/PlainDataGuardedRepository.php b/tests/Support/Cache/PlainDataGuardedRepository.php
new file mode 100644
index 00000000..fbfee5ef
--- /dev/null
+++ b/tests/Support/Cache/PlainDataGuardedRepository.php
@@ -0,0 +1,188 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\Cache;
+
+use Illuminate\Cache\Repository;
+use UnitEnum;
+
+/**
+ * キャッシュ書き込みの**値の実体**を検査する受け皿 (テスト実行時層)。
+ *
+ * ## なぜ受け皿 (Repository) 境界なのか (イベント購読ではない)
+ *
+ * `Illuminate\Cache\Events\KeyWritten` の購読は**差し替え可能な境界**であり、
+ * テスト本体の `Event::fake()` や store 設定の `'events' => false` で無効化できる。
+ * `Illuminate\Cache\Repository` の書き込みメソッドはイベント層より下にあるため、
+ * どちらの影響も受けない。
+ *
+ * ## なぜ 4 メソッドで足りるのか (vendor 実読で確認済み)
+ *
+ * set → put / setMultiple → putMany / remember → rememberWithWarmth → put /
+ * sear → rememberForever → forever / flexible → putMany / offsetSet → put /
+ * putMany($v, null) → putManyForever → forever。
+ * 合流が将来変わったら CachePayloadPlainDataGuardTest の実 API 経由テストが落ちる。
+ * ★これは**標準 API の値の合流**についての主張であって、`Store` へ直接届く経路の
+ *   完全性の主張ではない (そちらは静的層 L4 の担当)。
+ *
+ * ## 境界迂回として落とすもの
+ *
+ * - `tags()` — vendor の実装が `new TaggedCache($this->store, ...)` を素で生成するため、
+ *   継承しても以降の書き込みが検査を通らない。加えて本番の保管方式 (database store) は
+ *   タグ非対応 (`supportsTags()` が false) なので、タグを使う書き方は本番で例外になる
+ * - `setStore()` — 受け皿の保管先を差し替える口 (vendor に呼び出し元 0 件)
+ * - `__call()` — macro は**無条件に**落とす。macro の closure は `$this->store` へ
+ *   直接到達でき、末端 4 メソッドを通らない (「同一テスト内で登録し、使い、消す」形も
+ *   使用時点で捕まる)。macro でない素通しは、**保管先の非 payload API として名指しで
+ *   分類した語彙だけ**を通し、それ以外は落とす (`STORE_PASSTHROUGH_METHODS`)
+ *
+ * ## 保管先への素通しを名指しで分類する理由 (deny-by-default)
+ *
+ * `Illuminate\Cache\Repository` は **`lock()` / `restoreLock()` を宣言していない**。
+ * `Cache::lock(...)` は `CacheManager::__call()` → `Repository::__call()` →
+ * `$this->store->lock(...)` の素通しで届く (vendor 実読)。本リポジトリはこの形を
+ * 6 ファイルで使っており (静的層の role=lock-only)、排他オブジェクトは payload を運ばない。
+ * よって「payload を運ばない排他 2 語彙**だけ**」を名指しで通し、それ以外の素通しは落とす。
+ * この 2 語彙が静的層の TERMINAL 語彙 (payload を運ばないと分類した語彙) の**部分集合**である
+ * ことは tests/Architecture/CachePayloadPlainDataGateTest.php の検査 L4g が機械で固定する
+ * (許可を 2 か所で別々に育てられないようにするため)。
+ *
+ * ## 保証しないもの
+ *
+ * - **`getStore()` は落とさない**。vendor 自身が正常系で呼ぶためである — 実読の根拠:
+ *   `Illuminate\Cache\RateLimiter::withoutSerializationOrCompression()` (hit/increment の経路) /
+ *   `Illuminate\Cache\Repository::flushLocks()` (自己呼び出し) /
+ *   `Illuminate\Console\Scheduling\CacheEventMutex` / `Illuminate\Console\CacheCommandMutex` /
+ *   `Illuminate\Cache\Limiters\ConcurrencyLimiterBuilder` / `Illuminate\Cache\MemoizedStore`。
+ *   よって「保管先を直接取得して書く」形を塞ぐのは**静的層 (L4) だけ**であり、
+ *   vendor が `getStore()` 経由で書く値は実行時層に見えない
+ * - **素通しを許した 2 語彙の先**は見ない (`$this->store->lock(...)` が保管先で何をするかは
+ *   検査しない。排他は payload を持たない、が根拠である)
+ * - `increment` / `decrement` は store 直行だが整数しか書けないので検査しない
+ *
+ * ## 許可一覧を持たない (payload について)
+ *
+ * vendor の書き込みも対象に含める。`config/cache.php` の `serializable_classes => false` の下では
+ * **誰が入れたかに関わらず**オブジェクトを入れれば本番の読み出しが失敗するため、
+ * vendor の検出は誤検出ではなく本番の潜在バグの発見である (家系の裁定 AG-107「例外を作らない」)。
+ * 上の `STORE_PASSTHROUGH_METHODS` は**値を運ばない API の分類**であって、
+ * 「この呼び出し元なら値を見逃す」という許可ではない。
+ */
+final class PlainDataGuardedRepository extends Repository
+{
+    /**
+     * 保管先へ素通しさせる非 payload API (全小文字)。
+     *
+     * `Illuminate\Cache\Repository` が宣言しておらず、`__call()` 経由で
+     * `Illuminate\Contracts\Cache\LockProvider` へ届く排他 2 語彙だけである。
+     *
+     * @var list<string>
+     */
+    public const array STORE_PASSTHROUGH_METHODS = ['lock', 'restorelock'];
+
+    /**
+     * {@inheritDoc}
+     */
+    public function put($key, $value, $ttl = null)
+    {
+        if (is_array($key)) {
+            // vendor と同じく `$key` が配列なら putMany 形 (値の実体は $key 側)。
+            PlainDataCacheGuard::inspect('put', '(many)', $key);
+
+            return parent::put($key, $value, $ttl);
+        }
+
+        PlainDataCacheGuard::inspect('put', self::describeKey($key), $value);
+
+        return parent::put($key, $value, $ttl);
+    }
+
+    /**
+     * {@inheritDoc}
+     */
+    public function add($key, $value, $ttl = null)
+    {
+        PlainDataCacheGuard::inspect('add', self::describeKey($key), $value);
+
+        return parent::add($key, $value, $ttl);
+    }
+
+    /**
+     * {@inheritDoc}
+     */
+    public function forever($key, $value)
+    {
+        PlainDataCacheGuard::inspect('forever', self::describeKey($key), $value);
+
+        return parent::forever($key, $value);
+    }
+
+    /**
+     * {@inheritDoc}
+     */
+    public function putMany(array $values, $ttl = null)
+    {
+        PlainDataCacheGuard::inspect('putMany', '(many)', $values);
+
+        return parent::putMany($values, $ttl);
+    }
+
+    /**
+     * {@inheritDoc}
+     *
+     * @return never
+     */
+    public function tags($names)
+    {
+        PlainDataCacheGuard::reportBoundary('tags', self::describeKey($names));
+    }
+
+    /**
+     * {@inheritDoc}
+     *
+     * ★vendor の宣言は `public function setStore($store)` で **型宣言を持たない**
+     *   (docblock に `@param \Illuminate\Contracts\Cache\Store $store` があるだけ)。
+     *   忠実に写すので本クラスは `Store` 型を参照しない
+     *   = 「Store 型を参照してよい唯一のサイトは manager の repository()」という主張と矛盾しない。
+     *
+     * @return never
+     */
+    public function setStore($store)
+    {
+        PlainDataCacheGuard::reportBoundary('setStore', get_debug_type($store));
+    }
+
+    /**
+     * {@inheritDoc}
+     *
+     * macro は無条件に落とす。macro でない素通しは名指しで分類した非 payload API だけ通す
+     * (クラス docblock「境界迂回として落とすもの」/「保管先への素通しを名指しで分類する理由」)。
+     */
+    public function __call($method, $parameters)
+    {
+        if (self::hasMacro($method)) {
+            PlainDataCacheGuard::reportBoundary('macro', $method);
+        }
+
+        if (! in_array(strtolower($method), self::STORE_PASSTHROUGH_METHODS, true)) {
+            PlainDataCacheGuard::reportBoundary('storePassthrough', $method);
+        }
+
+        return parent::__call($method, $parameters);
+    }
+
+    /** 失敗メッセージ用のキー表現 (キーは string / UnitEnum / 配列を取り得る)。 */
+    private static function describeKey(mixed $key): string
+    {
+        if (is_string($key)) {
+            return $key;
+        }
+
+        if ($key instanceof UnitEnum) {
+            return $key::class.'::'.$key->name;
+        }
+
+        return get_debug_type($key);
+    }
+}
diff --git a/tests/Support/Cache/PlainDataInspector.php b/tests/Support/Cache/PlainDataInspector.php
new file mode 100644
index 00000000..9b897046
--- /dev/null
+++ b/tests/Support/Cache/PlainDataInspector.php
@@ -0,0 +1,134 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\Cache;
+
+/**
+ * キャッシュへ書き込まれる値が**素のデータ**かを再帰検査する純関数。
+ *
+ * 素のデータ = 配列 / 文字列 / 数値 / 真偽値 / null だけで構成された値
+ * (家系の裁定 AG-151 が定めた許可集合。AGENTS.md セキュリティ不変条件 11 と同義)。
+ * DTO・Eloquent モデル・Collection・列挙型・日時オブジェクト・クロージャ・resource は違反である。
+ *
+ * ## 違反の種別
+ *
+ * - `OBJECT_FOUND` / `RESOURCE_FOUND` — 規約そのものの違反
+ * - `UNKNOWN_TYPE` — **上のどれにも当てはまらない型**。閉じた resource が代表例で、
+ *   `is_resource()` は false を返すが `is_scalar()` にも当たらない。
+ *   「分類できなかったものを素データとして通さない」ための fail-closed 分岐である
+ * - `LIMIT_EXCEEDED` — **規約違反ではなく「検査器が素のデータであることを証明できなかった」**
+ *   ことを表す。自己参照配列 (`$v['self'] = &$v;`) は素朴な再帰走査を停止させないため、
+ *   深さ・ノード数の上限を置き、超過は fail-closed で違反として返す
+ *
+ * ## 上限値の根拠
+ *
+ * - 深さ 32: `json_decode` の既定深さ 512 より十分浅く、キャッシュ payload としては 32 段でも異常に深い
+ * - ノード 10000: **根の値を 1 と数えた総ノード数**。1 件のキャッシュ entry としては十分大きい
+ *
+ * 境界の直前・直後は tests/Feature/Cache/CachePayloadPlainDataGuardTest.php が pin する。
+ *
+ * ## 保証しないもの
+ *
+ * - **値の意味**は見ない (素のデータであれば内容は問わない)
+ * - 配列のキーは見ない (PHP は配列キーを int|string に限るので、キーがオブジェクトになる形は無い)
+ * - **保管先へ渡ったあとの変換**は見ない (store 側の直列化・圧縮は対象外)
+ */
+final class PlainDataInspector
+{
+    /** 走査の最大深さ (配列の入れ子段数)。超過は LIMIT_EXCEEDED。 */
+    public const int MAX_DEPTH = 32;
+
+    /** 走査の最大ノード数 (**根の値を 1 と数える**)。超過は LIMIT_EXCEEDED。 */
+    public const int MAX_NODES = 10000;
+
+    /**
+     * 値が素のデータかを再帰検査し、違反を返す (空配列 = 素のデータ)。
+     *
+     * @return list<string> "<パス> = <種別>(<詳細>)" の形
+     */
+    public static function violations(mixed $value, string $path = 'value'): array
+    {
+        /** @var list<string> $violations */
+        $violations = [];
+        $nodes = 0;
+
+        self::walk($value, $path, 0, $violations, $nodes);
+
+        return $violations;
+    }
+
+    /**
+     * @param  list<string>  $violations
+     */
+    private static function walk(mixed $value, string $path, int $depth, array &$violations, int &$nodes): void
+    {
+        $nodes++;
+        if ($nodes > self::MAX_NODES) {
+            if (! self::alreadyReportedLimit($violations, 'nodes')) {
+                $violations[] = $path.' = LIMIT_EXCEEDED(nodes)';
+            }
+
+            return;
+        }
+
+        // ★許可集合を**先に**判定して早期 return する (許可の定義を 1 か所に閉じる)。
+        if ($value === null || is_scalar($value)) {
+            return;
+        }
+
+        if (is_object($value)) {
+            $violations[] = $path.' = OBJECT_FOUND('.$value::class.')';
+
+            return;
+        }
+
+        if (is_resource($value)) {
+            $violations[] = $path.' = RESOURCE_FOUND('.get_resource_type($value).')';
+
+            return;
+        }
+
+        if (! is_array($value)) {
+            // ★閉じた resource が代表例。is_resource() は false、is_scalar() も false。
+            //   分類できないものを素データとして通さない (fail-closed)。
+            $violations[] = $path.' = UNKNOWN_TYPE('.get_debug_type($value).')';
+
+            return;
+        }
+
+        if ($depth + 1 > self::MAX_DEPTH) {
+            $violations[] = $path.' = LIMIT_EXCEEDED(depth)';
+
+            return;
+        }
+
+        foreach ($value as $key => $element) {
+            self::walk(
+                $element,
+                $path.'['.(is_int($key) ? (string) $key : "'".$key."'").']',
+                $depth + 1,
+                $violations,
+                $nodes,
+            );
+
+            if ($nodes > self::MAX_NODES) {
+                return;
+            }
+        }
+    }
+
+    /**
+     * @param  list<string>  $violations
+     */
+    private static function alreadyReportedLimit(array $violations, string $kind): bool
+    {
+        foreach ($violations as $violation) {
+            if (str_ends_with($violation, 'LIMIT_EXCEEDED('.$kind.')')) {
+                return true;
+            }
+        }
+
+        return false;
+    }
+}
diff --git a/tests/TestCase.php b/tests/TestCase.php
index b527b73a..9aaf6e55 100644
--- a/tests/TestCase.php
+++ b/tests/TestCase.php
@@ -4,7 +4,15 @@
 
 namespace Tests;
 
+use Illuminate\Contracts\Console\Kernel;
+use Illuminate\Foundation\Application;
+use Illuminate\Foundation\Testing\CachedState;
 use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
+use Illuminate\Foundation\Testing\WithCachedConfig;
+use Illuminate\Foundation\Testing\WithCachedRoutes;
+use Override;
+use RuntimeException;
+use Tests\Support\Cache\PlainDataCacheGuard;
 
 abstract class TestCase extends BaseTestCase
 {
@@ -12,4 +20,42 @@ abstract class TestCase extends BaseTestCase
      * RefreshDatabase 後に DatabaseSeeder (Role 等の参照データ) を流す。
      */
     protected bool $seed = true;
+
+    /**
+     * アプリを生成する。**bootstrap の直前**にキャッシュ guard を結線するために override する。
+     *
+     * ★Pest の beforeEach では遅い。起動 (bootstrap) 中の書き込みは、vendor 由来だと
+     *   静的層の走査根 (app / routes / database / tests) にも入らないため、
+     *   結線が遅れると 2 層とも沈黙する穴になる。
+     *
+     * ★本体は vendor (Illuminate\Foundation\Testing\TestCase::createApplication()) の
+     *   写しであり、**guard の結線 1 行と戻り値の fail-closed 確認だけを足している**。
+     *   vendor 側が変わったら tests/Architecture/CacheGuardWiringGateTest.php の
+     *   W5 / W5b (期待 token 列の完全一致) が赤くなるので、そのとき写し直す。
+     */
+    #[Override]
+    public function createApplication(): Application
+    {
+        $app = require Application::inferBasePath().'/bootstrap/app.php';
+
+        if (! $app instanceof Application) {
+            throw new RuntimeException('bootstrap/app.php が Application を返しませんでした');
+        }
+
+        PlainDataCacheGuard::registerBeforeBootstrap($app);
+
+        $this->traitsUsedByTest = class_uses_recursive(static::class);
+
+        if (isset(CachedState::$cachedConfig, $this->traitsUsedByTest[WithCachedConfig::class])) {
+            $this->markConfigCached($app);
+        }
+
+        if (isset(CachedState::$cachedRoutes, $this->traitsUsedByTest[WithCachedRoutes::class])) {
+            $app->booting(fn () => $this->markRoutesCached($app));
+        }
+
+        $app->make(Kernel::class)->bootstrap();
+
+        return $app;
+    }
 }

```

## テスト結果 (Round 2 時点)

- `composer test -- --filter='CachePayloadPlainData|CacheGuardWiring'`: 98 tests / 98 passed / 0 failed
- `vendor/bin/pint --test`: passed
- 全コマンド (composer test / phpstan / pint / pnpm lint / typecheck / test / build /
  typecheck:packages / build:packages / test:packages / composer test:browser) の再実行は
  本ラウンドの合意後に行い、結果を報告します。

## 補足: Critical への対応で範囲を限定した理由

`T_NEW` の後が解決できない形を**走査根全体で**落とすと、次の 12 件が誤検出になりました。

```
app/Enums/Billing/BillingRetentionTarget.php:60   (new $model)->getTable()
app/Models/Billing/BillingCheckoutSession.php:78  同上
app/Models/Billing/BillingNotification.php:63     同上
app/Models/Billing/TicketAutoRecharge.php:79      同上
app/Models/Billing/TicketAutoRechargeAttempt.php:72 同上
app/Models/ModelAudit.php:122                     同上
app/Models/Passkey.php:48                         PasskeyFactory::new()  ← 生成ではなくメソッド名
database/factories/ApiKeyFactory.php:48           同上
tests/Architecture/MassAssignmentSafetyTest.php:58/73  (new $class)->getFillable()
tests/Architecture/RouteBindingTypeConstraintInventoryTest.php:337  同上
tests/Feature/InitialState/NullInitialStateColumnClassificationTest.php:560  同上
```

いずれもキャッシュと無関係で、キャッシュ規約の目録に登録しようがありません。
そこで (a) `::new()` / `->new()` を母集団から外し、(b) 落とすのは
**キャッシュ記号に触れるファイル (L3 の面)** の中だけにしました。
あわせて **具体 store の名前に触れることを面の条件へ追加**したので、
指摘された `$class = \Illuminate\Cache\ArrayStore::class; $store = new $class;` は
その `::class` 参照でファイルが面になり、動的生成が落ちます (負例で固定しました)。

残る限界 (**クラス名を素の文字列リテラルで書く形**) は冒頭 docblock の
「保証しないもの」へ明記し、L4b の主張をその構文を除く範囲へ狭めました。
これは AGENTS.md 走査規約 (b) が認める 2 択のうち「検出力の主張を明示的に狭める」側です。
この判断が妥当か、それとも文字列リテラルまで走査すべきかについて意見をください。
