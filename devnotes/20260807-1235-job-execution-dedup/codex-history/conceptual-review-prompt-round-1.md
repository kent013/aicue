# アプリの使命 (North Star) — AGENTS.md より転記

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

# 思考原則 — AGENTS.md より転記

1. **フレームワークのレンジ内でやる**。自前機構の前に Laravel / 同梱モジュールの公式作法を確認する
2. **今必要なものだけ作る**(オーバーエンジニアリング禁止。「あったら便利」は作らない)
3. **後方互換の並走を残さない**。書き換えると決めたら同じ PR で旧実装を消す
4. **別物の概念を「似ているから」で統合しない**
5. **テストファースト**。fail を確認してから実装に入る
6. **タコツボ実装を避ける**。各ステップで他要素との結合観点を確認する

# 禁止事項 — AGENTS.md より転記

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(Artifact ツールでの成果物公開を行わない。成果物はリポジトリ内のファイルとして出力する)

# 思考原則 — 全議論に適用

まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

# ツール使用制限

コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

あなたはWebアプリケーション（Laravel + Svelte）の改善に関する概念設計レビュアーです。

【レビュー観点】
1. 使命との整合性: この改善はアプリの使命（North Star）に本質的に貢献するか
2. 禁止事項違反: 上記禁止事項に抵触していないか
3. 実現可能性: 技術的に実現可能か（Laravel 12 + Svelte 5 + Inertia.js）
4. 期待効果の妥当性: 主張している効果は合理的に期待できるか
5. リスク: 重大な副作用・後退の可能性はないか
6. スコープの適切さ: 過大または過小になっていないか
7. **型安全性**: DTO/JsonResourceパターンに沿っているか。PHPStan level 10を通せるか

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

---

## 補足: リポジトリの実コード事実 (実査済み。レビューの前提として使ってよい)

- ジョブは `app/Jobs/{Billing,Capture,Manual}` に 10 本。`ShouldQueue` 実装は Mailable 2 / Notification 6 を含めて計 18 件で、`tests/Architecture/QueuedJobLeaseInventoryTest.php` の `QUEUED_JOB_LEASE_INVENTORY` が deny-by-default で全数を目録化している。
- `ShouldBeUnique` を実装するのは `app/Jobs/Billing/AutoRechargeTriggerJob.php` のみ (`uniqueFor = 30`, `uniqueId = organizationId`)。`WithoutOverlapping` は app/ に 0 件。
- `AnalysisPipeline::run()` の流れ: `findOrFail` → `startJob()` (tx + `lockForUpdate` + `status === Queued` guard → running) → `extract` → LLM 3 段 (`withBoundedRetry` で各段を有界リトライ) → `finalize()` (tx + `lockForUpdate` + `status === Running` guard で materialize + `tickets->commit()` + Succeeded を原子化) → 通知。例外は `catch (Throwable) → report() → AnalysisJobService::failJob()`。
- `RenderPipeline::run()` の流れ: `startJob()` → `buildManifest()` → `downloadSources()` → ffmpeg `compose()` → `storage->upload()` → `finalize()` (同型)。`finally` で「succeeded 未達なら upload 済みオブジェクトを best-effort delete」しており、コメントに「孤児オブジェクトは reconcile 対象外」と書かれている。
- `AnalysisJobService::recoverStale()` / `RenderJobService::recoverStale()` は cron で `queued` は created_at、`running` は updated_at が閾値 (30 分) を超えたものを `failJob()` で failed へ落とす。旧ワーカーへの通知手段は無い。
- `AnalysisJobService::trigger()` は `$locked->analysisJobs()->make()` で**毎回新しい行**を起票する (同一 manual の in-flight は 1 つに制限)。`JobStatus` は `queued / running / succeeded / failed` で `isTerminal()` を持ち、terminal から戻る遷移は存在しない。
- `AutoRechargeService::executeAttempt()` は org 単位 `Cache::lock(..., LOCK_TTL_SECONDS = 180)` を `block(10, ...)` で取り、`executeAttemptLocked()` が `$attempt->refresh()` → `Pending` 検査 → `isEnabledFor()` → `createAutoRechargeInvoice()` → `forceFill(['stripe_invoice_id'])->save()` → `payOffSessionInvoice()` → `recordSuccessfulCharge()` の順で進む。
- `AutoRechargeService::recordSuccessfulCharge()` / `transitionToTerminal()` は `->where('status','pending')->update([...])` の条件付き UPDATE で、更新 1 行のときだけ後続処理を行う。
- `ExecuteAutoRechargeAttemptJob` は既定接続 (`queue.connections.database.retry_after = 600`) で `$timeout` 未宣言。`QueuedJobLeaseInventoryTest` は「既定接続のジョブは `$timeout` を宣言しない」を強制している。
- `tests/Architecture/ThrottleCoverageInventoryTest.php` + `App\Enums\Security\ThrottleCoverageExemption` が「型付き enum + 30 文字以上の根拠 + 全体 cap (exact fit) + case 別 cap」という免除目録の作法の見本。
- `tests/` 全体で `ShouldBeUnique` / `WithoutOverlapping` / `uniqueFor` / lock TTL を参照するテストは 0 件。

## 概念設計

<!-- devnotes/20260807-1235-job-execution-dedup/conceptual-design.md 全文 -->

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

## 期待効果

- **使命への貢献**: 現場作業者にとって「解析した / 書き出した」の一回性は、動画マニュアルという
  成果物への信頼そのものである。同時に、二重実行が起きる面は**すべて課金面** (LLM 従量課金 /
  Stripe 課金 / チケット) に接している。ここで実際に金が二重に動けば「思考ゼロ・編集ゼロ」以前に
  プロダクトを使い続けてもらえない。使命への貢献は間接だが、下支えとして必須。
- **具体的な改善見込み**:
  - stale 回復後の LLM 課金呼び出し **最大 3 回 → 0 回** (次の段の手前で中断)。
  - stale 回復後の S3 PUT **1 回 → 0 回** (孤児オブジェクトの発生源を 1 つ潰す)。
  - lock 期限切れ後の Stripe invoice 作成 / 課金 **可能 → 構造的に不可**。
  - 新しいキュージョブを足したときの分類漏れを CI が即座に検出する (deny-by-default)。
- **家系への還流**: 「単調な状態機械では status 再読込が claim token の役割を果たす」という
  判断は、aigenba の OwnershipClaim 一式と対になる別解として台帳に残す価値がある。

---

## 実装方針 (概要)

### S1. 外部呼び出し直前の所有権再検証 (解析 / レンダ)

- `App\Exceptions\Manual\JobOwnershipLostException` を新設 (両パイプライン共用)。
- `AnalysisPipeline`: `withBoundedRetry()` の **`$attempt()` 実行の直前**に
  `status === Running` の軽量再読込検査を挿入する。1 箇所の挿入で
  extract / decompose / generate の 3 段 × 全リトライ試行を覆う (挿入点が 1 つ = 抜けようがない)。
- `RenderPipeline`: `storage->upload()` の**直前**に同じ検査を挿入する。
- `run()` は `JobOwnershipLostException` を **`catch (Throwable)` より前**で捕捉し、
  `Log::info` + `return` する (`report()` しない・`failJob()` を呼ばない)。
  既に terminal なので failJob は no-op になるが、通知経路とチケット release 経路を
  無用に叩かないため、専用 catch で明示的に閉じる。

### S2. Stripe 呼び出し直前の所有権再検証 + 序列の是正 (auto-recharge)

- `executeAttemptLocked()` の **`createAutoRechargeInvoice()` の直前**と、
  **`$attempt->forceFill(['stripe_invoice_id'])->save()` の後・`payOffSessionInvoice()` の直前**の
  2 箇所へ `Pending` 再確認を挿入する (自前の書き込みを挟んだ後に必ず再検証する = 裁定文の要求そのもの)。
- 失われていたら structured no-op (`Log::info` + `return`)。既存の lock busy 時と同じ扱いで、
  リコンサイル (i)/(ii) が回収する。

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
- 各クラスに次のいずれかの登録を要求する (未登録は fail):
  - **保証側**: `App\Enums\Security\JobDedupGuarantee` (機構の分類) + 30 文字以上の根拠
    + **再検証点** (`[class-string, method]`。Reflection で実在を検査)
  - **免除**: `App\Enums\Security\JobDedupExemption` + 30 文字以上の根拠
- `ThrottleCoverageInventoryTest` に倣い、免除件数の全体 cap (exact fit) と case 別 cap を持つ。

### S5. 運用契約の文書化 (「設定整合では閉じない窓」の記録)

- `docs/architecture.md` の §キューのリース期間とワーカー制限時間の規約 の隣に
  **§ジョブの重複実行と結果の一回性** を新設し、2 層の役割・所有権の定義・残余窓を書く。
- `AGENTS.md` ドメイン固有規約へ項目 6 として要約を追加する。

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
| **claim token / claimed_at / claim_expires_at カラムの導入 (aigenba OwnershipClaim の移植)** | aicue の状態機械は単調かつ再実行は新規行を起票するため、`status` 再読込が所有権の再検証として完全である (上記「所有権を何で表すか」)。カラムと TTL を足すのは閉じるべき窓を増やすだけ。 |
| **`ExecuteAutoRechargeAttemptJob` の専用キュー接続への pin と `$timeout` 宣言** | `QueuedJobLeaseInventoryTest` の「既定接続では `$timeout` 宣言禁止」に抵触するため接続分割が前提になる。それは T127 の管轄で、本件と独立に判断されるべき。本設計は「lock TTL は保証を担わない」側の序列固定で閉じる。 |
| **HTTP / MCP の冪等キー、Stripe webhook の冪等マシン、滞留回収 (reconcile)** | 裁定 AG-082 が明示的に範囲外とした項目。aicue には既に `WebhookIdempotencyTest` / reconcile コマンド群が実在する。 |
| **ffmpeg compose / S3 download の直前の再検証** | ローカル CPU と冪等な読み取りであり、取り消せない外部副作用を持たない。無駄な計算の削減は本件の目的 (結果の一回性) ではない。残余窓として S5 の文書へ記録する。 |
| **`TakeRegistrationService` の予約 CAS の作り替え** | 既に条件付き UPDATE (CAS) で保証側の要件を満たしている。S4 の目録に「保証側」として登録するだけでよい。 |
| **閾値そのもののチューニング (`LOCK_TTL_SECONDS` / `uniqueFor` / stale 閾値の値変更)** | AGENTS.md 思考原則「仕組みが機能していない段階で値を弄るな」。本件は序列を固定する変更であり、現行値は序列を満たしている。 |
