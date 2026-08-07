# 概念設計レビュー Round 2

Round 1 の指摘への対応を報告します。対応マトリクス (判断と根拠) と、修正後の概念設計全文を示します。
再レビューをお願いします。**Critical への反論部分については、反論が成立しているか (窓が本当に縮まないか) を
特に厳しく検証してください。**

## 対応マトリクス

# 対応マトリクス: conceptual-review Round 1

## [Critical] 実現可能性: `status === Running` の再読込は排他でも CAS でもなく構造的保証にならない (再読込 → cron が failed 化 → 外部呼び出し、の順序が残る)

- 判断: **一部対応 + 一部反論**
- 根拠:
  - **反論 (中核)**: Codex が挙げた代替 (`running → sending_*` の条件付き UPDATE / `execution_token` の CAS)
    は**この窓を閉じない**。DB の決定 (commit) と外部 HTTP 送信は別リソースであり、
    両者を原子化する手段は分散トランザクションしかない。CAS でも
    「CAS 成功 → cron が failed 化 → 送信」の順序はそのまま残る (窓の幅も同じ)。
    CAS が優位なのは「**同一行を複数担当が同時に奪い合う**」場合だが、aicue では
    `startJob()` の `lockForUpdate() + status === Queued` guard により
    1 行が `Running` へ遷移するのは高々 1 回で、再実行は新しい行を起票する。
    つまり CAS が解く問題そのものが存在しない。
  - **反論 (窓の幅の実測)**: `recoverStale()` は `running` かつ `updated_at <= now - 30 分` を対象にする。
    一方 worker が張る SIGALRM は `RunManualAnalysis::$timeout = 1,560s (26 分)` /
    `RunManualRender::$timeout = 1,500s (25 分)` で、`startJob()` が `updated_at` を
    run() 入口で更新する。したがって **`$timeout < stale 閾値` が成り立つ限り、
    `queue:work` で生きているワーカーのジョブが recoverStale の母集団に入ることはない**。
    この序列は `AnalysisTimeBudgetInvariantTest` / `RenderTimeBudgetInvariantTest` が
    既に CI 固定している (1,560 < 1,680 < 1,800 ≤ 1,800)。
    残る窓は「ワーカーが自分の SIGALRM 予算を 240 秒以上超えて停止 (SIGSTOP / VM freeze) し、
    かつ再開する」という経路に限られる。
  - **対応**: この分析が概念設計に書かれていなかったのは事実で、Codex の指摘は
    「設計文書として保証範囲を明示していない」点で正しい。
    **概念設計に §「閉じる窓と閉じない窓」を新設**し、上記を明記する。
    あわせて `queue:listen` (dev / bug-hunt) ではジョブ側 `$timeout` が効かないため
    この序列が成立しない例外も記録する (`QueueWorkerLeaseInvariantTest` の docblock が根拠)。
- 対応内容: 概念設計に §「所有権再検証が閉じる窓・閉じない窓」を追加。
  claim token / sending 状態 CAS を採らない根拠を「窓を閉じないから」に更新
  (従来は「不要だから」だけだった)。

## [Critical] 期待効果が設計の保証能力を超えている (「最大 3 回 → 0 回」等)

- 判断: **対応する**
- 根拠: 妥当。「0 回」は「再検証時点で既に terminal であることを検出できたケースで 0 回」であり、
  無条件の 0 ではない。効果表現が保証範囲を超えると、次に読む人が残余窓を見落とす。
- 対応内容: 期待効果を Codex の提案どおり 3 層に分けて書き直す
  (再読込で防げるもの / 防げない race / 完全に防ぐために必要なもの)。
  数値は「**再検証時点で terminal を検出できた場合の後続外部呼び出し = 0**」へ改める。

## [Critical] `JobOwnershipLostException` を `Log::info + return` で握ると本物の実装ミスと区別できない

- 判断: **対応する**
- 根拠: 妥当。所有権喪失は「正常だが異常兆候」であり、無音で return すると
  頻度が観測できない (窓が実際に開いているかを運用で確かめられない)。
- 対応内容: 例外に構造化コンテキスト (`jobType` / `jobId` / `expectedStatus` / `actualStatus` /
  `stage` / `externalCall`) を持たせ、専用の `Log::warning` (info ではなく warning) で
  全項目を出す。Feature テストで「所有権喪失時に外部呼び出しが起きない」だけでなく
  「failJob が呼ばれない / 通知が飛ばない」まで固定する。

## [Warning] AutoRecharge の Pending 再確認にも同じ race。Stripe idempotency key を保証層に含めよ

- 判断: **対応する (既存機構の明記)**
- 根拠: aicue は既に `idempotencyKeyBase($attempt) = 'auto-recharge:'.$attempt->attempt_ulid` を
  `createAutoRechargeInvoice()` / `payOffSessionInvoice()` の両方へ渡している。
  Stripe 側の冪等は attempt 行に pin 済みで、**同一 attempt に対する二重課金は成立しない**。
  設計文書がこれを保証層として明示していなかったのが欠落。
- 対応内容: 概念設計の保証層を「(1) org lock (best-effort) / (2) 送信直前の Pending 再検証 /
  (3) 条件付き UPDATE / (4) Stripe idempotency key (attempt_ulid pin)」の 4 層として明記し、
  S4 の目録にも 4 層の対応を書く。

## [Warning] Render の upload 直前だけでは multipart / 途中死の孤児は残る。「発生源を 1 つ潰す」は言い過ぎ

- 判断: **対応する**
- 根拠: 妥当。加えて出力キーは `v{scenario_version}-{jobId}.mp4` と決定的なので
  重複 PUT は上書きであり「二重オブジェクト」は元々発生しない。問題は
  「terminal 済みジョブの出力が残る」ことである。
- 対応内容: 表現を「**terminal 済みと検出できたジョブの PUT を抑止する**」へ改め、
  残余 (PUT 中のプロセス死) を §閉じない窓へ記載する。

## [Warning] S4 の母集団 18 件は重い。Mailable / Notification まで含めると免除目録がノイズ化する

- 判断: **一部反論 + 一部対応**
- 根拠:
  - **反論**: 母集団を「外部副作用または課金に触れる queued class」へ絞ると、
    その**セレクタ自体が新しい判断点**になり、新規ジョブがセレクタから漏れて
    静かに未分類になる (deny-by-default が壊れる)。
    `QueuedJobLeaseInventoryTest` と母集団を完全一致させておくことには
    「片方だけ更新される drift が構造的に起きない」という別の価値もある。
  - **対応**: ノイズ懸念自体は正しい。配信系 (Mailable / Notification 8 件) を
    **1 つの免除 case に集約**し、case 別 cap を 8 (exact fit) で置く。
    これで 8 件は「1 個の裁定 + 8 行の登録」に圧縮され、増えたときだけ再検討が強制される。
- 対応内容: `JobDedupExemption::OutboundDeliveryWithoutDomainStateWrite` (配信系) を新設し、
  case 別 cap で膨張を検出する方式を概念設計へ明記。

## [Warning] `[class-string, method]` の配列 shape は PHPStan で緩みやすい

- 判断: **対応する**
- 根拠: 妥当。tuple 配列は shape を書けるが、目録が育つと読み手にも静的解析にも辛い。
- 対応内容: `tests/Support/JobDedup/` に `final readonly class GuaranteeEntry` /
  `ExemptionEntry` を置き、constructor で `class-string` / enum / `non-empty-string` を受ける形にする
  (アプリコードではなくテスト支援クラスとして置く = app/ を汚さない)。

## [Warning] 禁止事項: AGENTS.md 規約追加を含むなら Architecture テストまで含めないと「実装済み」にならない

- 判断: **対応する**
- 根拠: AGENTS.md 禁止事項 1 そのもの。
- 対応内容: 概念設計の実装方針に「S1/S2 は Feature テスト、S3/S4 は Architecture テストで
  登録まで行って初めて完了」と明記する。

## [Suggestion] 再検証メソッドの戻り型・例外型も固定せよ

- 判断: **対応する (詳細設計で)**
- 根拠: 低コストで効く。
- 対応内容: 詳細設計で `void` 返却 + `JobOwnershipLostException` throw に固定し、
  gate の Reflection 検査で「戻り型が void」まで見る。


---

## 修正後の概念設計 (全文)

# 概念設計: job-execution-dedup

> lctl feature id: `job-execution-deduplication` / 裁定 AG-082 (2026-08-06) への aicue 追従。
> 一次入力: `devnotes/20260807-1235-job-execution-dedup/recon-brief.md`

## 背景・課題

裁定 AG-082 は「二重実行の抑止」を **入口の排他** と **結果の一回性 (保証側)** の 2 層に分け、
**保証を担うのは後者だけ**であると定義した。入口の排他 (`ShouldBeUnique` / `WithoutOverlapping` /
`Cache::lock`) は Laravel 公式が「失敗や timeout で鍵が解放されないことがある」と明記する
best-effort であり、単独では何も保証しない。

aicue の実コードを実査した結果、台帳の記述 (「保証側の層が無い」) は不正確で、保証側に相当する層は
**3 系統実在する**:

| # | 機構 | 実装箇所 |
|---|------|---------|
| (a) | 悲観ロック + status guard | `AnalysisPipeline::startJob()/finalize()` / `RenderPipeline::startJob()/finalize()` |
| (b) | 条件付き UPDATE (`where('status','pending')->update()`) | `AutoRechargeService::recordSuccessfulCharge()` / `transitionToTerminal()` |
| (c) | 予約 CAS (pending→verifying→completed) | `TakeRegistrationService` / `StaleUploadReservationSweeper` |

したがって aicue の実際の欠落は「保証側が無い」ではなく、以下の 4 点である。

### G1. 外部呼び出しの**直前**の所有権再検証が 0 箇所

`recoverStale` cron (`AnalysisJobService::recoverStale()` / `RenderJobService::recoverStale()`) は
`running` かつ `updated_at <= now-30分` のジョブを **failJob で terminal (failed) へ落とす**。
しかし旧ワーカーは生きたまま走り続けられ、誰もそれを検知しない:

- `AnalysisPipeline`: `startJob()` (status 検査) → **LLM 3 段 (課金される外部呼び出し)** → `finalize()` (status 検査)。
  間に status 再検査が 1 つも無い。失敗ジョブに対して LLM 課金が最大 3 回発生する。
- `RenderPipeline`: `startJob()` → download / ffmpeg compose → **`storage->upload()` (S3 PUT)** → `finalize()`。
  `finalize()` が false を返す経路では `finally` の best-effort delete に望みを託しており、
  プロセスが死ねば**孤児 S3 オブジェクト**が残る (コメント自身が「reconcile 対象外」と認めている)。
- `AutoRechargeService::executeAttemptLocked()`: lock 取得直後に `refresh()` + `Pending` 検査 +
  `isEnabledFor()` を行うが、その後 **`createAutoRechargeInvoice()` → `$attempt->save()`
  (自前の書き込み) → `payOffSessionInvoice()` (Stripe 課金)** と続く。
  裁定文が名指しする「検証の後に自前の書き込みを挟むと、接続断で旧担当が送信できる窓が開く」
  そのものの形になっている。

`finalize()` の guard は「課金済みの外部呼び出し」を取り消せない。閉じるべきは**呼び出しの手前**である。

### G2. 排他 TTL と実行上限の序列が固定されていない

`AutoRechargeService::LOCK_TTL_SECONDS = 180` に対し、その lock 内で走る
`ExecuteAutoRechargeAttemptJob` は既定接続 (`retry_after = 600`)・`$timeout` 未宣言。
`AutoRechargeTriggerJob::$uniqueFor = 30` も同様に、どの実行上限と比較されるべきかが
コード上どこにも書かれていない。序列が逆転しても CI は何も言わない。

### G3. 「どのジョブがどの層で守られているか」の目録が無い

保証側の実装が `lockForUpdate` + status 文字列比較の**各所書き下ろし**であり、
新しいジョブが同じ形を踏襲する構造的保証が無い。`tests/Architecture` は 70 本あるが、
`ShouldBeUnique` / `WithoutOverlapping` / `uniqueFor` / lock TTL を参照するテストは
`tests/` 全体で **0 件**である。

### G4. 「設定整合では閉じない窓」が記録されていない

lock TTL や uniqueFor をどう積んでも閉じない残余窓 (プロセス停止と外部送信の間、
外部側の重複受信など) が文書化されておらず、次に読む人が「TTL を伸ばせば直る」と誤解する。

---

## 改善アイデア

**「入口の排他は best-effort であり、保証は外部呼び出しの直前の所有権再検証が担う」という
序列を、コードと目録の両方で機械的に固定する。**

要は 3 点:

1. **再検証を外部呼び出しの直前に置く** — 検証と外部呼び出しの間に自前の書き込みを挟まない。
2. **序列を CI で固定する** — 排他 TTL / uniqueFor が「保証を代替しない短い側」に留まることを検査する。
3. **目録で deny-by-default にする** — キューに載る全クラスに「保証側の機構 + 再検証点」か
   「型付き免除 + 30 文字以上の根拠」かの分類を要求する。

### 所有権を何で表すか (本設計の中核判断)

aigenba は `App\Support\JobExecution\OwnershipClaim` (claim_token / claimed_at / claim_expires_at)
を新設して追従した。**aicue はこれを移植しない**。理由:

`AnalysisJob` / `RenderJob` / `TicketAutoRechargeAttempt` の状態機械はいずれも
**単調 (monotone)** かつ **再実行は新しい行を作る** 設計である:

- `AnalysisJobService::trigger()` は `$locked->analysisJobs()->make()` で**毎回新しい行**を起票する
  (in-flight は 1 つに制限。`queued → running → succeeded|failed` は一方向で、terminal から戻らない)。
- `RenderJobService::trigger()/triggerPreview()` も同型。
- `TicketAutoRechargeAttempt` は `pending → paid|failed|canceled` の一方向で、再試行は別 attempt 行。

`startJob()` の `lockForUpdate() + status === Queued` guard により、**1 つの行に対して
`Running` への遷移は高々 1 回**しか起きない。したがって:

> **所有権 = (行の主キー, 進行中 status)** であり、`status` の再読込がそのまま所有権の再検証になる。

claim token は「同じ行を複数の担当が奪い合い、奪取後も行が進行状態に留まる」モデルで必要になる語彙で、
aicue の状態機械にはその状況が存在しない。導入すればカラム 3 本と TTL という
**閉じるべき窓を新たに 1 つ増やす**だけである (AGENTS.md 思考原則 2「今必要なものだけ作る」/
4「別物の概念を似ているからで統合しない」)。**この判断そのものを設計文書と目録に記録する**ことが、
台帳へ返す最も価値のある情報である。

---

## 所有権再検証が閉じる窓・閉じない窓 (裁定 AG-082 標準形 (5) の記録)

本設計が何を保証し、何を保証しないかを先に確定させる。**保証範囲を書かない改善は、
次に読む人に「もう安全だ」と誤解させる分だけ有害**である。

### 閉じる窓

**「再検証の時点で既に terminal であることを検出できたケース」の後続外部呼び出し**を 0 にする。
これは実運用で圧倒的多数を占める形 — ワーカー異常終了 (OOM kill / deploy / ホスト死) で
`updated_at` が凍り、30 分後に cron が failed 化し、**その後**にワーカーが復帰する経路や、
`Cache::lock` 期限切れ後にユーザーが auto-recharge を停止 (attempt を canceled 化) した後に
旧ワーカーが Stripe を叩く経路がこれに当たる。

### 閉じない窓 (残余。設定整合では閉じない)

1. **再検証 SELECT と外部送信の間** — DB の決定と外部 HTTP 送信は別リソースであり、
   分散トランザクションなしに原子化できない。
   **`running → sending` の条件付き UPDATE や claim token の CAS へ寄せてもこの窓は同じ幅で残る**
   (CAS 成功 → cron が failed 化 → 送信、の順序が同様に成立する)。
   CAS が優位なのは「同一行を複数担当が同時に奪い合う」場合だが、
   aicue では `startJob()` の `lockForUpdate() + status === Queued` guard により
   1 行が `Running` へ遷移するのは高々 1 回で、再実行は新しい行を起票するため、
   **CAS が解く問題そのものが存在しない**。
2. **外部送信の途中でのプロセス死** — S3 PUT 中に死ねば書きかけ / 完了済みオブジェクトが残る。
   ただし出力キーは `.../v{scenario_version}-{jobId}.mp4` と決定的なので
   「二重オブジェクト」にはならず、残るのは terminal 済みジョブの孤児 1 個である
   (`render:reconcile-outputs` の管轄)。
3. **LLM 呼び出しには冪等キーが無い** — provider 側が重複課金を弾く手段がない。
   だからこそ「呼ぶ手前で止める」ことに価値がある (呼んだ後では取り消せない)。
4. **`queue:listen` 配下ではジョブの `$timeout` が効かない** (dev / bug-hunt)。
   下記「窓の幅」の議論が成立しない環境であることを記録する
   (`QueueWorkerLeaseInvariantTest` の docblock が根拠)。

### 窓の幅 — 既存の序列が本番でこの窓をほぼ閉じている

`recoverStale()` の母集団は `running` かつ `updated_at <= now - 30 分`。一方
`startJob()` が run() 入口で `updated_at` を更新し、ワーカーは
`RunManualAnalysis::$timeout = 1,560s (26 分)` / `RunManualRender::$timeout = 1,500s (25 分)` で
SIGALRM により殺される。したがって:

> **`$timeout < stale 閾値` が成り立つ限り、`queue:work` 配下で生きているワーカーのジョブが
> `recoverStale` の母集団に入ることはない。**

この序列は `AnalysisTimeBudgetInvariantTest` / `RenderTimeBudgetInvariantTest` が既に CI 固定している
(`1,560 < 1,680 < 1,800 ≤ 1,800`)。残余窓 1 が本番で実際に開くのは
「ワーカーが自分の SIGALRM 予算を 240 秒以上超えて停止 (SIGSTOP / VM freeze / 極端な GC 停止) し、
かつ再開する」経路に限られる。**この事実こそが「再検証で十分」の根拠**であり、
S5 の文書と S4 の目録に根拠として書き残す。

### 保証層の全体像 (auto-recharge を例に)

| 層 | 機構 | 保証の強さ |
|---|---|---|
| 1 | org 単位 `Cache::lock` (TTL 180s) | best-effort (入口の排他) |
| 2 | 外部呼び出し直前の `Pending` 再検証 | terminal 検出済みなら送信しない (本設計で追加) |
| 3 | `where('status','pending')->update()` の条件付き UPDATE | 1 attempt = 1 遷移 (既存) |
| 4 | Stripe idempotency key = `auto-recharge:{attempt_ulid}` | **同一 attempt の二重課金は Stripe 側で成立しない** (既存) |

層 4 が既に存在するため、残余窓 1 が開いても「同じ attempt に対する二重課金」は起きない
(起きるのは「停止後に 1 回課金される」= 停止意思の取りこぼしであり、二重課金ではない)。
解析 (LLM) には層 4 に相当するものが無い点が最も弱く、だからこそ層 2 の価値が最も高い。

---

## 期待効果

- **使命への貢献**: 現場作業者にとって「解析した / 書き出した」の一回性は、動画マニュアルという
  成果物への信頼そのものである。同時に、二重実行が起きる面は**すべて課金面** (LLM 従量課金 /
  Stripe 課金 / チケット) に接している。ここで実際に金が二重に動けば「思考ゼロ・編集ゼロ」以前に
  プロダクトを使い続けてもらえない。使命への貢献は間接だが、下支えとして必須。

### (a) 再検証で防げるもの (本設計が保証する範囲)

- **再検証時点で既に terminal と判定できたジョブの後続外部呼び出しが 0 になる**:
  - 解析: 残りの LLM 段 (最大 3 段 × リトライ) を 1 回も呼ばない。
  - レンダ: S3 PUT を行わない (terminal 済みジョブの孤児オブジェクトを作らない)。
  - auto-recharge: Stripe の invoice 作成 / 課金を行わない (停止後課金を送信手前で止める)。
- **新しいキュージョブの分類漏れを CI が即座に検出する** (deny-by-default の目録)。
- **観測できるようになる**: 所有権喪失は構造化ログ (`jobType` / `jobId` / `expectedStatus` /
  `actualStatus` / `stage` / `externalCall`) として出るため、窓が実際にどれだけ開いているかを
  運用で測れる。現在は「静かに二重実行される」ため測定手段すら無い。

### (b) 本設計でも防げないもの

- 再検証 SELECT の直後に所有権を失う race (上記 §残余窓 1)。
- 外部送信の途中でのプロセス死 (同 2)。

### (c) 完全に防ぐために必要なもの (今回作らない)

- LLM provider 側の冪等キー相当 (提供されていない)。
- lease / heartbeat を状態機械へ組み込み、`recoverStale` が生存ワーカーと構造的に競合しない形にする
  (現状は `$timeout < stale 閾値` の序列で実質同じ効果を、追加状態ゼロで得ている)。

- **家系への還流**: 「単調な状態機械では status 再読込が claim token の役割を果たし、
  CAS へ寄せても残余窓は縮まない」という判断は、aigenba の OwnershipClaim 一式と対になる
  別解として台帳に残す価値がある。

---

## 実装方針 (概要)

### S1. 外部呼び出し直前の所有権再検証 (解析 / レンダ)

- `App\Exceptions\Manual\JobOwnershipLostException` を新設 (両パイプライン共用)。
  **構造化コンテキストを型付きで保持する** (`jobType` / `jobId` / `expectedStatus` /
  `actualStatus` / `stage` / `externalCall`)。無音で握らず、必ず観測できる形にする。
- `AnalysisPipeline`: `withBoundedRetry()` の **`$attempt()` 実行の直前**に
  `status === Running` の軽量再読込検査を挿入する。1 箇所の挿入で
  extract / decompose / generate の 3 段 × 全リトライ試行を覆う (挿入点が 1 つ = 抜けようがない)。
- `RenderPipeline`: `storage->upload()` の**直前**に同じ検査を挿入する。
- `run()` は `JobOwnershipLostException` を **`catch (Throwable)` より前**で捕捉し、
  `Log::warning` (info ではない) で全コンテキストを出して `return` する
  (`report()` しない・`failJob()` を呼ばない)。
  既に terminal なので failJob は no-op になるが、通知経路とチケット release 経路を
  無用に叩かないため、専用 catch で明示的に閉じる。

### S2. Stripe 呼び出し直前の所有権再検証 + 序列の是正 (auto-recharge)

- `executeAttemptLocked()` の **`createAutoRechargeInvoice()` の直前**と、
  **`$attempt->forceFill(['stripe_invoice_id'])->save()` の後・`payOffSessionInvoice()` の直前**の
  2 箇所へ `Pending` 再確認を挿入する (自前の書き込みを挟んだ後に必ず再検証する = 裁定文の要求そのもの)。
- 失われていたら structured no-op (`Log::warning` + `return`)。既存の lock busy 時と同じ扱いで、
  リコンサイル (i)/(ii) が回収する。
- **既存の Stripe idempotency key (`auto-recharge:{attempt_ulid}`) を保証層 4 として文書に明記する**
  (新規実装は不要。設計文書がこれを保証層として数えていなかったのが欠落だった)。

### S3. 排他 TTL / uniqueFor の序列を CI 固定

- 新規 `tests/Architecture/JobExclusionOrderingInvariantTest.php` (または S4 の gate へ同居) で:
  - `AutoRechargeService::LOCK_TTL_SECONDS (180) < queue.connections.database.retry_after (600)`
  - `AutoRechargeTriggerJob::$uniqueFor (30) < queue.connections.database.retry_after (600)`
- 意味: **排他の鍵は保証を担わない**ため「長い側」に倒さない。短い側に倒しておけば、
  鍵の残留が正当な再実行を封鎖する時間がキューの再配送間隔を超えない
  (§10.8-1「再実行は analyze/render 再トリガーのみ」との干渉を構造的に有界化する)。

### S4. 横断 gate (deny-by-default 目録)

- 新規 `tests/Architecture/JobExecutionDedupInventoryTest.php`。
  母集団は `QueuedJobLeaseInventoryTest` と同一 (`ShouldQueue` 実装 18 件)。
  **走査ロジックを `tests/Support/` の共有クラスへ 1 本化**し、二重管理の drift を根で断つ。
  母集団を「課金・外部副作用に触れるジョブ」へ絞ることは**しない** —
  そのセレクタ自体が新しい判断点になり、新規ジョブがセレクタから漏れて静かに未分類になるため
  (deny-by-default が壊れる)。
- 各クラスに次のいずれかの登録を要求する (未登録は fail):
  - **保証側**: `App\Enums\Security\JobDedupGuarantee` (機構の分類) + 30 文字以上の根拠
    + **再検証点** (`class-string` + method。Reflection で実在と `void` 返却を検査)
  - **免除**: `App\Enums\Security\JobDedupExemption` + 30 文字以上の根拠
- 目録の値は tuple 配列ではなく `tests/Support/JobDedup/` の **`final readonly` value object**
  (`GuaranteeEntry` / `ExemptionEntry`) で持つ (PHPStan level 10 で shape が緩まない)。
- `ThrottleCoverageInventoryTest` に倣い、免除件数の全体 cap (exact fit) と case 別 cap を持つ。
  配信系 (Mailable 2 + Notification 6 = 8 件) は **1 つの免除 case へ集約**し
  case 別 cap を exact fit で置く (8 件が「1 個の裁定 + 8 行の登録」に圧縮され、
  9 件目を足すときだけ再検討が強制される = 目録のノイズ化を防ぐ)。

### S5. 運用契約の文書化 (「設定整合では閉じない窓」の記録)

- `docs/architecture.md` の §キューのリース期間とワーカー制限時間の規約 の隣に
  **§ジョブの重複実行と結果の一回性** を新設し、2 層の役割・所有権の定義・
  保証層の全体像・**閉じない窓 4 つ**を書く。
- `AGENTS.md` ドメイン固有規約へ項目 6 として要約を追加する。

### 完了の定義 (禁止事項 1)

不変条件は「対応するテストへの登録まで含めて実装済み」である。したがって:

- S1 / S2 は **Feature テスト**で behavioral に固定して初めて完了
  (所有権喪失時に外部呼び出しが起きない / failJob も通知も呼ばれない、まで検証する)。
- S3 / S4 は **Architecture テスト**の登録まで含めて完了。
- S4 は「素の main では赤にならない」型の gate であるため、
  **mutation で赤化を確認する手順**を詳細設計に書き、実装時に実行して結果を記録する。

---

## 制約・前提

- **PHPStan level 10** / **Pest + `RefreshDatabase` グローバル適用 + `--parallel`** /
  テストデータは Factory 経由 / `declare(strict_types=1)` + 日本語コメント。
- **既存の時間 budget 連鎖を変えない**。`AnalysisTimeBudgetInvariantTest` /
  `RenderTimeBudgetInvariantTest` が固定する
  `$timeout < retry_after < 予約 TTL ≤ stale 閾値` に触れない
  (再検証は 1 回あたり数 ms の主キー SELECT で、deadline D の内側に収まる)。
- **`QueuedJobLeaseInventoryTest` の目録定数 (`QUEUED_JOB_LEASE_INVENTORY`) を変えない**。
  規則「既定接続のジョブは `$timeout` を宣言しない」も守る = 本設計では新しい `$timeout` を宣言しない。
- **キュー接続のトポロジを変えない**。`ExecuteAutoRechargeAttemptJob` の専用接続への pin は
  `docs/TODO.md` の T127 (既定キュー接続の分割) の管轄であり、本設計では触らない。
- Pest はテストファイル群を同一プロセスへ読み込むため、**グローバル関数名の衝突を避ける**
  (既存 `jobLease*` と重複しない prefix を使うか、共有クラスへ寄せる)。
- 新 gate は「素の main では赤にならない」型のテストであるため、**mutation で赤化を確認する手順**を
  設計に含める (詳細設計で明記)。

---

## スコープ外

| 除外するもの | 理由 |
|---|---|
| **`RunManualAnalysis` / `RunManualRender` への `ShouldBeUnique` 追加 (入口の排他)** | `uniqueFor` はジョブ実行上限 (1,560 / 1,500 秒) を上回る必要があり、鍵が残留すると `recoverStale` 後の再トリガーを最大 `uniqueFor` 秒ブロックする。これは §10.8-1「再実行は analyze/render 再トリガーのみ」という現行設計と**正面から衝突**する。裁定 AG-082 自身が「保証を担うのは保証側だけ」と明記しているため、入口の排他は追加せず **S4 の目録へ「免除 + 理由」として登録**して記録に残す。 |
| **claim token / claimed_at / claim_expires_at カラムの導入 (aigenba OwnershipClaim の移植)、および `running → sending` 状態への CAS 遷移** | (1) **残余窓を縮めない** — CAS 成功と外部送信の間にも同じ幅の窓が残る (§閉じない窓 1)。(2) aicue では 1 行が `Running` へ遷移するのは高々 1 回で、再実行は新規行を起票するため **CAS が解く「同一行の奪い合い」が存在しない**。(3) 状態を増やせば `recoverStale` / UI / TS 型定義まで波及する。AGENTS.md 思考原則 2 / 4。 |
| **`ExecuteAutoRechargeAttemptJob` の専用キュー接続への pin と `$timeout` 宣言** | `QueuedJobLeaseInventoryTest` の「既定接続では `$timeout` 宣言禁止」に抵触するため接続分割が前提になる。それは T127 の管轄で、本件と独立に判断されるべき。本設計は「lock TTL は保証を担わない」側の序列固定で閉じる。 |
| **HTTP / MCP の冪等キー、Stripe webhook の冪等マシン、滞留回収 (reconcile)** | 裁定 AG-082 が明示的に範囲外とした項目。aicue には既に `WebhookIdempotencyTest` / reconcile コマンド群が実在する。 |
| **ffmpeg compose / S3 download の直前の再検証** | ローカル CPU と冪等な読み取りであり、取り消せない外部副作用を持たない。無駄な計算の削減は本件の目的 (結果の一回性) ではない。残余窓として S5 の文書へ記録する。 |
| **`TakeRegistrationService` の予約 CAS の作り替え** | 既に条件付き UPDATE (CAS) で保証側の要件を満たしている。S4 の目録に「保証側」として登録するだけでよい。 |
| **閾値そのもののチューニング (`LOCK_TTL_SECONDS` / `uniqueFor` / stale 閾値の値変更)** | AGENTS.md 思考原則「仕組みが機能していない段階で値を弄るな」。本件は序列を固定する変更であり、現行値は序列を満たしている。 |
