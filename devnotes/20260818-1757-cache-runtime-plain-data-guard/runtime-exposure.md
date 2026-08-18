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
payload を運ばないためである。この分類が静的層の TERMINAL 語彙 (payload を運ばないと
分類した語彙) の**部分集合**であることは `CachePayloadPlainDataGateTest` の検査 L4g が固定し、
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
  2 語彙ちょうどで、静的層の TERMINAL 語彙の部分集合であることを検査 L4g が pin する
- 累積の一意ファイル数は 0 で、差し戻し閾値 (10 ファイル) には遠く届かない
