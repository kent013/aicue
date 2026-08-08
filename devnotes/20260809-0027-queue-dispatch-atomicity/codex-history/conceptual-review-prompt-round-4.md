# Round 4: 対応マトリクスと修正内容

Round 3 の指摘への判断です。R-10 の機械固定は反論し、§8 を弱める側を選びました。それ以外はすべて対応済みです。

# 対応マトリクス: conceptual-review Round 3

## [Warning] R-10 の前提 (attempts=1) が機械固定されていない

- 判断: **一部反論・一部対応 (Codex の提示した 2 択のうち「§8 を弱める」を選ぶ)**
- 根拠 (反論): 提案された Architecture gate は「sync レーンで実行されうる tx 内 dispatch を
  含むトランザクションは attempts=1」という条件付き不変条件で、母集団の定義自体が
  「どのトランザクションが sync レーンで実行されうるか」という到達可能性解析を要する。
  一方、影響範囲は **sync レーン (テスト / dev) に限られ、本番 (database driver) には存在しない**
  (本番では tx 内に載るのは jobs 行の INSERT だけで job 本体は走らない)。
  本番に存在しないリスクのために到達可能性解析を伴う gate を新設するのは
  思考原則 2 (今必要なものだけ作る) に反する。
- 対応内容 (受容部分): §8 を Codex の言うとおり弱めた。
  「現行実査 (2026-08-08 / main = c71061e) では 0 件のため起きない。
  **ただしこの前提は機械固定していない = 将来の退行を検出しない**。
  複数行の第 2 引数・変数渡し・`DB::connection(...)->transaction(...)`・自前 wrapper は
  grep では捕捉できない」と明記し、機械固定しない理由 (影響が sync レーン限定) と
  退行時の顕在化の仕方 (sync レーンのテストが不安定化する) も書いた。

## [Warning] D1/D2 の本番母集団が代表アンカーだけでは不十分

- 判断: **対応する (指摘は正しい)**
- 根拠: 列挙器が `app/Jobs` や `app/Actions` を丸ごと除外しても
  `TicketLedgerService.php` のアンカーは通ってしまう。
- 対応内容: §5-1 の 2 を「**母集団境界の exact-fit 固定**」へ書き換えた。
  Architecture テスト側で `Symfony\Component\Finder\Finder` (既に
  `BillingSyncDispatchInvariantTest` が使用) により `app/**/*.php` の正規化済み集合を作り、
  `QueuedJobPopulation::appPhpFiles()` との**対称差が空**であることを assert する。
  これは検出ロジックの二重実装ではなく母集団境界の固定である旨も明記した。

## [Warning] §5-2 mutation #10 が旧設計のまま

- 判断: **対応する**
- 対応内容: #10 を Codex の提案 3 つで置換した。
  #10 = `ShouldBeUnique` を戻す → M8 の反転テストが落ちる /
  #11 = trigger dispatch を tx 外へ戻す → M9 の実 jobs 表 + tx level テストが落ちる /
  #12 = partial unique を外す → 並行実行の一回性テストが落ちる。

## [Suggestion] `ShouldBeUnique` 撤去後の一回性を behavioral test で固定せよ

- 判断: **対応する**
- 対応内容: §5-3 に「同一 org へ `AutoRechargeTriggerJob` を並行実行しても
  pending attempt は高々 1 件」を追加した。

## [Suggestion] §8 の `ShouldBeUnique` 一般論の対象範囲を明記せよ

- 判断: **対応する**
- 対応内容: 「撤去するのは `AutoRechargeTriggerJob` **だけ**。今後 `ShouldBeUnique` を持つ job を
  業務 tx の内側から dispatch する設計を足すと同じ問題が再発する。本設計はこれを機械検査しない」
  を §8 に追記した。

## [Suggestion] 「AG-127 の除外は 0 件」に修飾を付けよ

- 判断: **対応する**
- 対応内容: 「**確定 1 の queue dispatch 母集団では** 0 件」に統一し、
  「通知まで含めた広義の付随的副作用に除外が無いという意味ではない」旨の注記を §3 に置いた。

## [Suggestion] 契約数の表記ゆれ (4 契約 / 5 契約)

- 判断: **対応する**
- 対応内容: R-3 と §11 を「既存 5 契約」に統一した。

## [Suggestion] DTO に生 config 値を `mixed` のまま持たせない

- 判断: **対応する (詳細設計で具体化)**
- 対応内容: 詳細設計 §M6 で、DTO は正規化済みの型限定値
  (`string` / `bool` / `null` へ狭めた値) と規則 ID enum を持ち、`mixed` を公開しない形にする。

## [Suggestion] `ShouldBeUnique` 撤去 / AG-127 整理 / スコープ分割しない判断

- 判断: **見送る (Codex が成立と認めた項目。現状維持)**

---

## 修正後の該当箇所 (差分の要点)

### §3 冒頭 (修飾の追加)
### 論点: AG-127 の除外対象の線引き

**結論: 確定 1 の queue dispatch 母集団では AG-127 の除外は 0 件。除外機構 (exemption enum) は作らない。**

> **修飾を落とさないこと**: 「除外 0 件」は **確定 1 の母集団 (= 業務 tx の内側から投入される
> queue dispatch)** に限った話である。通知まで含めた広義の付随的副作用に除外が無い、という意味ではない
> (低残高通知は queue 母集団の外で、失敗分離の意味論が狭まる問題は残っている。下記)。

確定 1 の母集団は「**業務トランザクションの内側から投入される queue dispatch**」である。
実コードを当たった結果、その母集団に属するものはすべて tx 内へ移せる。

| 対象 | 確定 1 の母集団か | 扱い |
|---|---|---|

### §5-1 の 3 層 (母集団境界の exact-fit 固定へ変更)
**「検出関数は生きているが、実ファイルが渡されていない」偽グリーンを閉じる 3 層**

母集団が 1 件以上あれば通る形だと、「特定ディレクトリやクラスだけ脱落する」故障を見逃す
(Codex Round 2 の指摘)。したがって次の 3 層を設計に含める。

1. **経路統合の負のコントロール**: 検出器を「ファイルパス配列 / クラス名配列 / config 配列を
   受ける純関数」として `tests/Support/Queue/QueueDispatchDeferralInventory.php` に置き、
   テストは **fixture ディレクトリツリー** (テスト内で作る一時ディレクトリに D1/D2 対象の
   PHP ファイルを書く) を列挙器に食わせ、**「列挙 → 読み込み → 検出」の全経路**を通す。
   検出関数だけを直接叩く形にしない。D3 も fixture 側にダミークラスを置き、
   「クラス列挙 → リフレクション判定」まで通す
2. **母集団境界の exact-fit 固定**: 代表要素のアンカーだけでは
   「列挙器が `app/Jobs` や `app/Actions` を丸ごと除外する」故障を通してしまう
   (Codex Round 2/3 の指摘)。したがって **独立実装との対称差が空**であることを検証する:
   Architecture テスト側で `Symfony\Component\Finder\Finder` を使って
   `app/**/*.php` の正規化済み集合を作り、`QueuedJobPopulation::appPhpFiles()` との
   **対称差が空**であることを assert する。これは検出ロジックの二重実装ではなく
   **母集団境界の固定**である (Finder は既に `BillingSyncDispatchInvariantTest` で使われている)。
   `shouldQueueClasses()` / `config('queue.connections')` については 3 のとおり
   既存 inventory へ接続する
3. **既存 inventory との接続 (二重実装しない)**: `shouldQueueClasses()` の完全性は
   `QueuedJobLeaseInventoryTest` / `JobExecutionDedupInventoryTest` が
   **対称差が空**の形で既に deny-by-default 固定している。本 gate はその契約を docblock で
   参照し、母集団の完全性検査を自前で二重実装しない (ブリーフ申し送り (d) と同じ理由)

- **exact-fit**: 免除目録は **作らない** (§3 のとおり除外 0 件)。したがって

### §5-2 mutation 表
| # | 変異 | 落ちるべきテスト |
|---|---|---|
| 1 | `config/queue.php` の `sync` から `after_commit => true` を削る | `QueueDispatchAtomicityGuardTest` (R4) / M9 の順序テスト |
| 2 | `config/queue.php` の `database` の `after_commit` を `true` にする | `QueueDispatchAtomicityGuardTest` (R3) |
| 3 | `config/queue.php` の `database-render` の `connection` を別 DB 名にする | `QueueDispatchAtomicityGuardTest` (R2) |
| 4 | `AnalysisJobService::trigger` の dispatch を tx の外へ戻す | M9 の「analysis: 業務 tx 内で enqueue される」 |
| 5 | `BillingCustomerSynchronizer` に `->afterCommit()` を戻す | `QueueDispatchAtomicityInventoryTest` (D1) |
| 6 | `PaymentFailedNotification` に `ShouldQueueAfterCommit` を戻す | 同 (D3) |
| 7 | `TicketLedgerService` に `DB::afterCommit` を戻す | 同 (D2) |
| 8 | D1〜D4 の各検出器を「常に 0 件を返す」に潰す (1 つずつ) | 対応する負のコントロール (§5-1 の表) が **それぞれ**落ちる |
| 9 | `QueuedJobPopulation::appPhpFiles()` / `::shouldQueueClasses()` を空配列返しにする | D1・D2 側 / D3 側の母集団 0 件 fail が落ちる |
| 10 | `AutoRechargeTriggerJob` に `ShouldBeUnique` を戻す | M8 の反転テスト (`AutoRechargeTriggerJob` が `ShouldBeUnique` を実装しないことの固定) |
| 11 | `AutoRechargeTriggerJob` の dispatch を `reserve()` の tx の外へ戻す | M9 の実 `jobs` 表 + tx level 原子性テスト |
| 12 | `tar_attempts_org_pending_unique` (partial unique) を外す | M9 の「同一 org へ並行 trigger しても attempt は高々 1 件」テスト |


### §5-3 の追加テスト
- **`ShouldBeUnique` 撤去後の一回性**: 同一 org へ `AutoRechargeTriggerJob` を並行実行しても
  `ticket_auto_recharge_attempts` の pending が **高々 1 件**であることを assert する
  (入口排他の代わりに永続状態が一回性を担っていることの behavioral 固定)

### §8 の R-10 記述 (弱めた)
- **sync レーンでは job 本体が `Connection::commit()` の中で走る** (R-10)。
  SQL COMMIT は済んでいるのに `DB::transaction()` が throw しうる。業務クロージャの
  再実行は `causedByConcurrencyError && $currentAttempt < $maxAttempts` のときだけ起き、
  **現行実査 (2026-08-08 / main = c71061e) では `app/` に `DB::transaction()` の
  attempts 指定が 0 件のため起きない**。ただし
  **この前提は機械固定していない = 将来の退行を検出しない**。
  複数行の第 2 引数・変数渡し・`DB::connection(...)->transaction(...)`・自前 wrapper は
  grep では捕捉できない。機械固定しない理由は、影響範囲が **sync レーン
  (テスト / dev) に限られ本番 (database driver) には存在しない**ためで、
  専用 gate を新設するのは思考原則 2 に反すると判断した。
  退行したときは sync レーンのテストが不安定化する形で顕在化する

### §8 の ShouldBeUnique 対象範囲
- **`ShouldBeUnique` の unique lock が rollback で解放されない性質そのものは残る**。
  本設計で撤去するのは `AutoRechargeTriggerJob` **だけ**であり、
  今後 `ShouldBeUnique` を持つ job を業務 tx の内側から dispatch する設計を足すときは、
  同じ問題 (rollback しても `uniqueFor` の間だけ抑止が残る) が再発する。
  本設計はこれを機械検査しない

---

以上で Round 3 の Warning はすべて処理しました。R-10 のみ「機械固定しない代わりに §8 を弱める」を選んでいます。

全体判定 (APPROVED / CHANGES_REQUESTED) を明示してください。残る懸念が Suggestion 相当であれば APPROVED としてください。
