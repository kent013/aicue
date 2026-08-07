# 概念設計レビュー Round 4

Round 3 の指摘 (Critical 1 件 + Warning 6 件) をすべて反映しました。特に **スコープ外表に残っていた
撤回済みの CAS 主張を全面書き換え**し、本文との矛盾を解消しています。
対応マトリクスと修正後の概念設計全文を示します。

## 対応マトリクス

# 対応マトリクス: conceptual-review Round 3

## [Critical] スコープ外表の claim token / CAS の除外理由が、本文で撤回した主張のまま残っている

- 判断: **対応する**
- 根拠: 完全に妥当。本文 (§閉じない窓 1) で「CAS は競合を閉じられる」と書きながら、
  スコープ外表に「残余窓を縮めない」「同一行の奪い合いが存在しない」が残っていた = 旧裁定の残存。
  設計文書内の自己矛盾は、次に読む人がどちらを信じるかで判断が割れるため最悪の欠陥。
- 対応内容: スコープ外表の当該行を全面書き換え。Codex の提案どおり
  (1) CAS は recoverStale と送信開始の競合を閉じられる /
  (2) `sending` 獲得後のプロセス死には「送信結果が不明」という別の回収問題が生じる /
  (3) 現行 timeout/stale 序列下では元の競合リスクが低い /
  (4) 状態機械・回収処理・UI・TS 型への波及に対し便益が小さい → **リスク受容**
  の 4 点構成にした。「同一行の奪い合いが存在しない」は主理由から削除
  (今回 CAS が解く対象は worker と stale recovery 間の**送信権競合**であるため)。

## [Warning] 「`sending` の回収閾値で同じ形の競合が再び現れる」は不正確

- 判断: **対応する**
- 根拠: 妥当。`running` の競合は「送信前に cron が失敗させた後に旧 worker が送信する」問題、
  `sending` の回収は「送信されたか分からない状態をどう収束させるか」という結果不明問題で、別物。
  混同すると CAS を採らないコスト評価そのものが不正確になる。
- 対応内容: 「同じ競合が再発する」→「**送信開始後の結果不明を扱う新しい回収契約が必要になる**」
  へ書き換え、**LLM には冪等キーが無いため自動再送では閉じず、照会 / 補償 / 人手確認のいずれかを
  設計する必要がある**という具体的コストまで書いた。

## [Warning] 「`running` が実質 `sending` の役割を果たしている」は不正確

- 判断: **対応する**
- 根拠: 妥当。`running` は LLM 前処理や ffmpeg compose を含む長い区間で、cron が failed 化できる。
  送信権を保護する状態ではない。
- 対応内容: 「`running` と timeout/stale 序列により、**正常運用下では stale recovery と送信が
  競合しにくい**」という表現へ弱めた。

## [Warning] 「OOM kill / deploy / ホスト死は本設計以前から安全」は二重実行についてだけ成立する

- 判断: **対応する**
- 根拠: 妥当。旧 worker は復帰しないが、LLM 送信後・DB 確定前の死亡は課金済み・結果不明を残し、
  S3 PUT 後の死亡は孤児成果物を残す。「安全」は包括的すぎる。
- 対応内容: 「**stale recovery と旧ワーカーの競合は起きない**」へ限定し、
  課金済み結果不明 / 孤児成果物は残余窓 2 の管轄 = 本設計の対象外であることを明記した。

## [Warning] Canceled 検出後の invoice 終端は新たな外部副作用である

- 判断: **対応する**
- 根拠: 完全に妥当。抑止のために新しい外部呼び出しを足すのだから、それ自体が
  timeout / 通信断 / 再実行の対象になる。無条件の best-effort では設計として不十分。
- 対応内容: 概念設計に固定すべき 5 点を明記した:
  (i) 終端にも attempt に pin した idempotency key を使う /
  (ii) void 対象の invoice id が当該 attempt に保存された値と一致することを確認する /
  (iii) already void / paid 等の Stripe 状態を「成功」と「明示的な非成功」へ分類する /
  (iv) 終端失敗ログに固定 event 名と attempt 識別子を含める /
  (v) **終端の成否にかかわらず課金処理へは進まない**ことを Feature テストで固定する。

## [Warning] `DuplicateDeliveryAccepted` の「ユーザーに実害を与えない」は判定が曖昧

- 判断: **対応する**
- 根拠: 妥当。認証・課金・招待・失敗通知の重複は混乱や誤操作 (二重の支払い操作等) を招きうる。
- 対応内容: 適用条件 (2) を「**重複受信時にユーザーが誤った操作へ誘導されない**」へ具体化し、
  根拠に「何が重複配信されうるか・**重複時に受信者に何が起きるか**・なぜ受容できるか」の
  3 点を書かせる形にした。さらに
  「**課金関連・失敗通知・セキュリティ通知を『配信系だから』で一括免除しない**」を明記した。

## [Warning] `preflight: null = 外部呼び出しを持たないジョブ` は型だけでは証明できない

- 判断: **対応する**
- 根拠: 妥当。`null` に意味を持たせると、新しい外部呼び出しを足しても目録が green のままになりうる
  (deny-by-default が壊れる)。
- 対応内容: `preflight` を **nullable にしない**。`PreflightRequirement` を
  `PreflightCheckpoint` (class + method + `ExternalCallKind`) と
  `NoExternalCall` (**30 文字以上の個別根拠必須**) の 2 実装に限定する形へ変更した。


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

**結果の一回性を担うのは「永続状態遷移 (条件付き UPDATE / 悲観ロック + status guard) と
外部側の冪等性」であり、入口の排他 (`ShouldBeUnique` / `Cache::lock`) は best-effort である。
本設計はその序列を機械的に固定したうえで、両者の間に欠けている
**preflight suppression = 外部呼び出し直前の所有権再検証** を追加する。**

> **用語の分離 (Round 2 レビュー反映)**
> - **結果の一回性**: ドメイン状態と課金計上が高々 1 回しか確定しない性質。
>   担うのは条件付き UPDATE / 悲観ロック + status guard / 予約 CAS (**既に実在する**)。
> - **外部呼び出し回数の一回性**: 外部 API を高々 1 回しか叩かない性質。
>   これは DB 側の機構では保証できず、外部側の冪等キーが担う (Stripe は実在、LLM には無い)。
> - **preflight suppression (本設計の追加分)**: 上のどちらでもなく、
>   **既に所有権を失ったと判明しているケースの外部送信を、送る手前で止める**こと。
>   「保証」ではなく「検出できた分だけ止める抑止策」である。

要は 3 点:

1. **preflight 再検証を外部呼び出しの直前に置く** — 検証と外部呼び出しの間に自前の書き込みを挟まない。
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
該当するのは:

- **ワーカーが一時停止して復帰する経路** — SIGSTOP / VM suspend / シグナル配送遅延で
  `updated_at` が凍り、cron が failed 化し、**その後**にワーカーが再開して次の段へ進む場合。
- **`Cache::lock` 期限切れ後の停止操作** — TTL 180 秒を超えた Stripe 呼び出し中に
  ユーザーが auto-recharge を停止し attempt が canceled 化した後、旧ワーカーが次の Stripe 操作へ進む場合。

なお **OOM kill / deploy / ホスト死は「ワーカーが居なくなる」経路**であり、復帰しないため
**stale recovery と旧ワーカーの競合は起きない** (「安全」ではない — LLM 送信後・DB 確定前に
死ねば課金済み・結果不明が残り、S3 PUT 後に死ねば孤児成果物が残る。それらは残余窓 2 の管轄で、
本設計の対象外)。

### 閉じない窓 (残余。設定整合では閉じない)

1. **再検証 SELECT と外部送信の間** — DB の決定と外部 HTTP 送信は別リソースであり、
   分散トランザクションなしに原子化できない。
   **この窓は CAS で閉じられる** (Round 2 レビューで反論を撤回した点。以下参照)。
   本設計は CAS を採らないため、この窓を**リスク受容**する。

   **なぜ CAS なら閉じるのか**: `worker: running --CAS--> sending` と
   `cron: running --CAS--> failed` の WHERE 条件をどちらも `status = running` にすれば
   成功するのは一方だけで、DB 上で直列化される。worker が `sending` を獲得した後は
   cron が failed 化できないため、その後の送信は正当になる。
   CAS でも残るのは「`sending` 獲得後のプロセス死 = 送信済みか不明」だけであり、
   これは別の窓 (残余 2) である。

   **それでも CAS を採らない理由 (リスク受容の判断)**:
   1. `sending` は recoverStale が今のやり方では回収できない状態になる。回収しなければ
      ワーカー死亡時に manual が `analyzing` のまま**固着する (ユーザーの詰み)**。
      そこで `sending` にも回収を用意することになるが、それは元の競合の再発ではなく
      **「送信が行われたか分からない状態をどう収束させるか」という別種の問題**であり、
      **新しい回収契約**が要る — 外部側に冪等キーがあれば再送で閉じられるが、
      **LLM には冪等キーが無い**ため、照会 / 補償 / 人手確認のいずれかを設計する必要がある。
   2. 現行の `running` は LLM 前処理・ffmpeg compose を含む長い区間を表しており、
      `sending` のように送信権を保護する状態ではない。ただし
      `$timeout (1,560/1,500s) < stale 閾値 (1,800s)` の序列 (既に CI 固定済み) により、
      **正常運用下では stale recovery と送信が競合しにくい**。
   3. 状態を 1 つ増やす波及は `recoverStale` / 進捗 UI / TS 型定義 / 既存 Feature テスト群に及ぶ。
      1 と 3 のコストが、下記「窓の幅」の下での便益を上回る。
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

> **`$timeout < stale 閾値` が成り立ち、かつ下記の前提が満たされる限り、`queue:work` 配下で
> 生きているワーカーのジョブは、`recoverStale` に拾われるより先に自分の SIGALRM で終了する。**

この序列は `AnalysisTimeBudgetInvariantTest` / `RenderTimeBudgetInvariantTest` が既に CI 固定している
(`1,560 < 1,680 < 1,800 ≤ 1,800`)。ただし成立には次の前提が要る (**「母集団に入ることはない」とは書かない**):

1. `pcntl` による timeout handler が実際に有効であること。
2. timeout の発火を遅らせる実行形態・拡張がないこと
   (**`queue:listen` ではジョブ側 `$timeout` が効かない** — `QueueWorkerLeaseInvariantTest` の docblock)。
3. `updated_at` の基準時刻と cron の時刻に許容不能なずれがないこと。
4. 停止 → 再開したプロセスで、pending な timeout シグナルが送信処理より先に処理されること。
5. supervisor が制限時間超過プロセスを確実に排除すること。

これらは本番 (`queue:work` + supervisor) では成り立つが、**dev / bug-hunt (`queue:listen`) では
前提 2 が成立しない**。したがって残余窓 1 が実際に開くのは
「ワーカーが自分の SIGALRM 予算を 240 秒以上超えて停止 (SIGSTOP / VM suspend / シグナル配送遅延) し、
かつ再開する」経路に限られる — **これが残余窓 1 をリスク受容できる根拠**であり、
S5 の文書と S4 の目録に前提ごと書き残す。

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

- **家系への還流**: aigenba の OwnershipClaim / CAS 一式に対し、aicue は
  **「限定的な terminal 検出策 (preflight suppression) + 既存 timeout 序列によるリスク受容」**
  を選んだ、という**別の位置づけ**の解として台帳に残す (「CAS と同等」ではない)。
  「単調な状態機械では `running` が既に sending の役割を果たしており、
  `sending` を分離しても回収閾値のところに同じ競合が現れる」という分析は、
  他リポジトリが CAS を採る/採らないを判断するときの材料になる。

---

## 実装方針 (概要)

### S1. 外部呼び出し直前の所有権再検証 (解析 / レンダ)

- `App\Exceptions\Manual\JobOwnershipLostException` を新設。利用者は
  `App\Services\Manual\AnalysisPipeline` と `App\Services\Manual\RenderPipeline` の 2 つだけで、
  **どちらも Manual ドメイン**であるため namespace はここでよい
  (Billing 側は例外を投げず structured return で閉じるので共用しない)。
  **構造化コンテキストを型付きで保持する**:
  `jobType` (`class-string`) / `jobId` (`int`) / `expectedStatus` `actualStatus`
  (既存 `App\Enums\Manual\JobStatus`) / `externalCall` (新設 `App\Enums\Security\ExternalCallKind`) /
  `stage` (既存ドメイン step enum の `->value` = `non-empty-string`。
  同じ語彙の enum を 2 本作らないため新設しない)。
  **PII (email / name) と外部 payload は一切含めない**。
- `AnalysisPipeline`: `withBoundedRetry()` の **`$attempt()` 実行の直前**に
  `status === Running` の軽量再読込検査を挿入する。1 箇所の挿入で
  extract / decompose / generate の 3 段 × 全リトライ試行を覆う (挿入点が 1 つ = 抜けようがない)。
- `RenderPipeline`: `storage->upload()` の**直前**に同じ検査を挿入する。
- `run()` は `JobOwnershipLostException` を **`catch (Throwable)` より前**で捕捉し、
  **固定 event 名 `event = 'job_ownership_lost'`** を含む `Log::warning` (info ではない) で
  全コンテキストを出して `return` する (`report()` しない・`failJob()` を呼ばない)。
  既に terminal なので failJob は no-op になるが、通知経路とチケット release 経路を
  無用に叩かないため、専用 catch で明示的に閉じる。
  固定 event 名にするのは、ログ基盤で**頻度を集計して残余窓 1 が実際に開いているかを測る**ためである。

### S2. Stripe 呼び出し直前の所有権再検証 + 序列の是正 (auto-recharge)

- `executeAttemptLocked()` の **`createAutoRechargeInvoice()` の直前**と、
  **`$attempt->forceFill(['stripe_invoice_id'])->save()` の後・`payOffSessionInvoice()` の直前**の
  2 箇所へ `Pending` 再確認を挿入する (自前の書き込みを挟んだ後に必ず再検証する = 裁定文の要求そのもの)。
- 1 回目 (invoice 未作成) で失われていたら pure な structured no-op
  (`event = 'job_ownership_lost'` の `Log::warning` + `return`)。invoice が無いので収束は自明。
- 2 回目 (invoice 作成済み・`stripe_invoice_id` 保存済み) で **`Canceled` を検出したときは
  pure no-op にせず、invoice を best-effort で終端する**。
  理由: 停止側の `terminateAndCancel()` → `tryTerminateInvoice()` は
  `stripe_invoice_id === null` のとき「invoice 未作成 = 課金され得ない」として素通りするため、
  停止がこちらの `stripe_invoice_id` 保存より先に走ると **誰も void しない open invoice が残る**。
  `reconcile()` の 5 分岐は pending attempt しか走査しないので、terminal 化済みのこれを拾えない。
  `Paid` のときは終端しない (void できない) / `Failed` のときも行わない
  (`terminateAndFail` が既に終端済み)。
  **この終端は「ログ付き後処理」ではなく新しい外部副作用**なので、詳細設計で次を固定する:
  (i) 終端にも attempt に pin した idempotency key を使う、
  (ii) void 対象の invoice id が **当該 attempt に保存された値と一致する**ことを確認する、
  (iii) already void / paid 等の Stripe 状態を「成功」と「明示的な非成功」へ分類する、
  (iv) 終端失敗ログに固定 event 名と attempt 識別子を含める、
  (v) **終端の成否にかかわらず課金処理へは進まない**ことを Feature テストで固定する。
- **既存の Stripe idempotency key を保証層 4 として文書に明記する** (新規実装は不要)。
  `idempotencyKeyBase = 'auto-recharge:{attempt_ulid}'` から
  `CashierAutoRechargeGateway` が `{base}:invoice` / `{base}:item` / `{base}:finalize` / `{base}:pay`
  と**操作ごとに異なるキー**を派生させている。これを固定するテストが無いので追加する。

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
  - **保証側**: `GuaranteeEntry` — **2 つの概念を別フィールドで持つ**
    - `mechanism: App\Enums\Security\JobDedupGuarantee` — **永続状態遷移の機構**
      (条件付き UPDATE / 悲観ロック + status guard / 予約 CAS)。これが「結果の一回性」を担う。
    - `preflight: PreflightRequirement` — **外部呼び出し直前の再検証点**。
      **nullable にしない** (`null` に「外部呼び出しが無い」という意味を持たせると、
      新しい外部呼び出しを足しても目録が green のままになりうる)。
      実装は 2 つだけの型付き分類とする:
      `PreflightCheckpoint` (`class-string` + method + `ExternalCallKind`。
      Reflection で実在と `void` 返却を検査) / `NoExternalCall` (30 文字以上の個別根拠が必須)。
    - `rationale: non-empty-string` — 30 文字以上。
  - **免除**: `ExemptionEntry` — `App\Enums\Security\JobDedupExemption` + 30 文字以上の根拠。
- **`Guarantee` と `Preflight` を同じ enum に混ぜない** (別概念。AGENTS.md 思考原則 4)。
- 目録の値は tuple 配列ではなく `tests/Support/JobDedup/` の **`final readonly` value object**
  で持つ (PHPStan level 10 で shape が緩まない)。
- `ThrottleCoverageInventoryTest` に倣い、免除件数の全体 cap (exact fit) と case 別 cap を持つ。
  配信系 (Mailable 2 + Notification 6 = 8 件) は **1 つの免除 case
  `JobDedupExemption::DuplicateDeliveryAccepted` へ集約**し case 別 cap を exact fit で置く。
  この case の適用条件は「(1) ドメイン状態を書かない (2) **重複受信時にユーザーが
  誤った操作へ誘導されない** (3) `$tries` / retry 契約上 at-least-once を受容済み」の 3 点すべてとし、
  **各クラスの根拠には「何が重複配信されうるか・重複時に受信者に何が起きるか・なぜ受容できるか」を
  個別に書かせる** (「domain write が無い」だけを免除根拠にしない — メール / 通知の重複配信も
  外部副作用である)。**課金関連・失敗通知・セキュリティ通知を「配信系だから」で一括免除しない**
  — これらは重複が混乱や誤操作 (二重の支払い操作等) を招きうるため、
  クラスごとに重複時の具体的影響を書いたうえで裁定する。

### S5. 運用契約の文書化 (「設定整合では閉じない窓」の記録)

- `docs/architecture.md` の §キューのリース期間とワーカー制限時間の規約 の隣に
  **§ジョブの重複実行と結果の一回性** を新設し、2 層の役割・所有権の定義・
  保証層の全体像・**閉じない窓 4 つ**を書く。
- `AGENTS.md` ドメイン固有規約へ項目 6 として要約を追加する。
  **規約の各文がどのテストで保証されるかの対応表**を詳細設計に置く (規約とテストの対応が無い規約は形骸化する)。
- **運用契約**: `event = 'job_ownership_lost'` が短時間に連続した場合は
  「ワーカーの停止・再開が起きている / 序列の前提が崩れている」兆候として扱う、という
  初動の判断基準を書く。
- reconcile 5 分岐 (`recovered_paid` / `retried` / `sca_reminded` / `expired` / `triggered`) と
  「所有権喪失で中断した attempt がどこへ収束するか」の対応表を書く。

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
| **claim token / claimed_at / claim_expires_at カラムの導入 (aigenba OwnershipClaim の移植)、および `running → sending` 状態への CAS 遷移** | **リスク受容として除外する (「効果が無いから」ではない)**。(1) CAS は `recoverStale` と送信開始の**送信権競合を実際に閉じられる** (§閉じない窓 1 参照。Round 2 で当初の反論を撤回済み)。(2) ただし `sending` 獲得後のプロセス死に対しては「**外部送信が行われたか不明**」という別種の問題が生じ、それを収束させる**新しい回収契約** (外部照会 / 補償 / 人手確認。LLM には冪等キーが無いため自動再送で閉じない) が必要になる。(3) 現行の `$timeout (1,560/1,500s) < stale 閾値 (1,800s)` 序列の下では元の競合の発生可能性が低い (§窓の幅)。(4) 状態機械 1 個の追加は `recoverStale` / 進捗 UI / TS 型定義 / 既存 Feature テスト群へ波及する。(2)(4) のコストが (1) の便益を上回ると判断する。AGENTS.md 思考原則 2。 |
| **`ExecuteAutoRechargeAttemptJob` の専用キュー接続への pin と `$timeout` 宣言** | `QueuedJobLeaseInventoryTest` の「既定接続では `$timeout` 宣言禁止」に抵触するため接続分割が前提になる。それは T127 の管轄で、本件と独立に判断されるべき。本設計は「lock TTL は保証を担わない」側の序列固定で閉じる。 |
| **HTTP / MCP の冪等キー、Stripe webhook の冪等マシン、滞留回収 (reconcile)** | 裁定 AG-082 が明示的に範囲外とした項目。aicue には既に `WebhookIdempotencyTest` / reconcile コマンド群が実在する。 |
| **ffmpeg compose / S3 download の直前の再検証** | ローカル CPU と冪等な読み取りであり、取り消せない外部副作用を持たない。無駄な計算の削減は本件の目的 (結果の一回性) ではない。残余窓として S5 の文書へ記録する。 |
| **`TakeRegistrationService` の予約 CAS の作り替え** | 既に条件付き UPDATE (CAS) で保証側の要件を満たしている。S4 の目録に「保証側」として登録するだけでよい。 |
| **閾値そのもののチューニング (`LOCK_TTL_SECONDS` / `uniqueFor` / stale 閾値の値変更)** | AGENTS.md 思考原則「仕組みが機能していない段階で値を弄るな」。本件は序列を固定する変更であり、現行値は序列を満たしている。 |
