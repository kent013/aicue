# アプリの使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

# 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)。
   **実行経路を持つ prompt factory は `LlmCallContextData` を必須引数で受け、
   `->withMetadata($context->toMetadata())` で帰属 (organization / subject) を付ける** — 付け忘れは
   PHPStan level 10 が落とす。帰属の対象を持たない見本 (`ExampleSummaryPrompt`) は
   `PromptUntrustedInputContractTest` の inventory へ**帰属キーを空配列で exempt 登録**する
   (deny-by-default なので exempt にする操作がレビューで必ず見える)。
   欠けると `llm_call_logs.metadata_missing` になり組織別・対象別の費用が出せない
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。
   招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(Artifact ツールでの成果物公開を行わない。成果物はリポジトリ内のファイルとして出力する)

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

あなたはWebアプリケーション（Laravel + Svelte）の改善に関する概念設計レビュアーです。

【レビュー観点】
1. 使命との整合性: この改善はアプリの使命（North Star）に本質的に貢献するか
2. 禁止事項違反: 上記禁止事項に抵触していないか
3. 実現可能性: 技術的に実現可能か（Laravel 12 + Svelte 5 + Inertia.js）
4. 期待効果の妥当性: 主張している効果は合理的に期待できるか
5. リスク: 重大な副作用・後退の可能性はないか
6. スコープの適切さ: 過大または過小になっていないか
7. 型安全性: DTO/JsonResourceパターンに沿っているか。PHPStan level 10を通せるか

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

---

## 概念設計

# 概念設計: stuck-job-recovery (滞留回収の共通基盤への寄せ替え)

## 背景・課題

### 1. 台帳 (lctl) 側の事実

feature `stuck-job-recovery` の裁定 AG-083 (2026-08-06) は「雛形を置くだけではなく、実装そのものを
標準形 v1 へ統一する」と決めた。標準形が求めるのは 3 点 — **回収の共通基盤 / 既存回収の寄せ替え /
定期に外から叩く入口**。aicue のセルはこれまで「追従元 (laravel-claude-template) に共通基盤が
無いので寄せる先が存在しない」という理由で pending だった。

2026-08-10 の差分巡回でこの理由は失効した。laravel-claude-template:T076 が共通基盤を入れ、
既存の期限切れ予約解放を同一基盤へ移設して旧実装を撤去している (= 寄せ替えの参照実装が家系に
存在する)。したがって aicue の pending 理由は「基盤が無いから成立しない」ではなく
**「基盤はできたので着手できる (未着手)」** に変わった。

正典 (laravel-claude-template) の構成:

- `StuckWorkStream` 契約 — `candidateIds()` が主キーだけを返し `recover()` が id しか
  受け取らない。**行ロック下での述語再評価が構造的に強制される**のが要点
- `StuckWorkRecoverySweeper` / `StuckWorkStreamRegistry` — 走査枠と作用枠を分ける
- `work:recover-stuck {--stream} {--limit} {--apply}` — 既定 dry-run、Schedule 3 本
- `config/recovery.php` + `RecoveryThresholds` — stall は進捗時刻起点、give-up と look-back は
  発生時刻起点と起点を分ける
- deny-by-default の stream 台帳 gate / 撤去済み参照の再流入を止める gate

### 2. aicue 側の事実 (実読で確認した)

回収の入口は**現在 5 本**あり、それぞれ独立に実装されている
(監督セッションの観測は 3 本だったが、実読すると解析ジョブと撮影アップロード予約の
2 本が加わる。**寄せ替えの対象範囲はこの 5 本で確定させる**)。

| # | 入口 (routes/console.php) | 実体 | 周期 | 滞留の判定 | 述語の再評価方式 |
|---|---|---|---|---|---|
| 1 | `analysis:recover-stale-jobs` | `AnalysisJobService::recoverStale()` | 5 分 | queued: `created_at` / running: `updated_at` が 30 分超過 | `failJob()` 内の `lockForUpdate` + terminal guard |
| 2 | `render:recover-stale-jobs` | `RenderJobService::recoverStale()` | 5 分 | queued 10 分 / running 30 分 | 同上 |
| 3 | `billing:release-stale-reservations` | `TicketLedgerService::releaseStale()` | 5 分 | `expires_at` 超過 / 失効 monthly hold | `release()` 内の行ロック + status 検査 (不成立は `LogicException`) |
| 4 | `billing:recover-stale-webhook-events` | `StripeWebhookProcessor::recoverStale()` | 5 分 | `received` かつ `updated_at` が 15 分超過 | `claimStale()` の WHERE 付き `lockForUpdate` |
| 5 | `capture:release-stale-upload-reservations` | `StaleUploadReservationSweeper::sweep()` | 10 分 | pending の `expires_at` / verifying の `updated_at` | 条件付き UPDATE (CAS) |

5 本が共有しているもの: 候補を列挙 → 1 件ずつ取り直して条件を再確認 → 件数を返す、という同じ作法。

5 本でばらついているもの (= 今回の課題):

1. **述語の再評価が 3 通りの機構**で書かれている (行ロック + guard / 例外 / 条件付き UPDATE)。
   どれも正しいが、6 本目を書く人がどれを写すかは書き手次第で、**間違えても静かに壊れる**
   (正常に動いていたものを失敗にする事故はエラーにならない)
2. **1 回の実行で扱う件数の上限を持つのは 5 番だけ** (500 件)。他は全件を 1 回で処理する
3. **試し打ち (dry-run) の手段が 1 本も無い**。本番で「いま何が回収対象なのか」を
   副作用なしに見ることができない
4. **回収結果の語彙がばらばら** (件数 int が 4 本、4 つの内訳を持つ DTO が 1 本)
5. **回収の入口が存在することを機械的に強制する仕組みが無い**。定期実行を 1 本足すときに、
   それが回収なのか突き合わせなのか保持期間の削除なのかを申告させる場所が無く、
   **6 本目を素通しで足せる**
6. **cron の失敗が運用アラートに載るのは 4 番だけ**。1〜3・5 は失敗しても無音である
   (回収が止まっていることに誰も気づけない = 本 feature の存在理由そのものが穴になる)

### 3. なぜ今か

4 番 (Stripe webhook の滞留回収) は本日 T162 で入ったばかりで、その設計は
「既存 2 つと同じ作法を採り、共通の回収基盤は作らない」と明記して着地している
(`devnotes/20260815-1109-stripe-webhook-stuck-recovery/`)。本設計はその判断を正面から見直す。

T162 の判断は当時の前提 (aicue にも家系にも共通基盤が無い) の下では妥当だった。前提が変わった
今、同じ判断を続けると 6 本目・7 本目が同じ理由で増える。**3 本目が生えた直後の今が寄せ替えの
好機**であり、家系の他リポジトリ (motivation) では「基盤ができた直後に寄せ替えではなく 2 本目の
独自回収が増えた」という乖離の拡大が実際に観測されている (台帳 2026-08-12 の巡回記録)。

## 改善アイデア

**5 本の回収経路を 1 つの契約・1 つの入口・1 つの目録へ寄せ、旧実装を同じ PR で撤去する。**

### 取るもの (正典から aicue に持ち込む)

1. **stream 契約**: `candidateIds()` は主キーだけを返し、`recover()` は id (と掃引開始時刻) しか
   受け取らない。行の内容を持ち回れないので、**再取得と述語の再評価が構造的に強制される**。
   これが正典の核であり、aicue が本当に必要としている唯一の部分である
2. **走査枠と作用枠を分けた sweeper と registry**: 1 回の実行で扱う件数の上限と、
   結果の集計をここに 1 箇所だけ持つ
3. **単一の入口コマンド** `work:recover-stuck --stream= --limit= --apply` (既定 dry-run)
4. **deny-by-default の目録 gate**: 登録された stream の集合と、定期実行に載っている
   コマンドの集合を突き合わせ、どちらにも属さないものを落とす
5. **撤去済み参照の再流入を止める gate**: 撤去した 5 本のコマンド名とメソッド名が
   コードにも docs にも戻ってこないことを機械で固定する

### 取らないもの (aicue には要らない / 入れると悪くなる)

1. **`config/recovery.php` への閾値の集約**。aicue の滞留閾値は既にドメインの config にあり、
   **ジョブの `timeout` < キューの `retry_after` < 予約 TTL ≤ 滞留閾値**という序列を
   Architecture テスト 2 本 (`AnalysisTimeBudgetInvariantTest` / `RenderTimeBudgetInvariantTest`)
   が固定している。回収側の config へ移すと**序列の情報源が 2 つに割れる**。
   → stream が自分のドメインの config を読む形にする (正典からの意図的な逸脱として記録する)
2. **look-back (遡及の下限)**。入れると「古すぎる滞留は永久に回収されない」という
   新しい無音の穴が生まれる。件数が問題になった実測が無い段階で入れるのは
   「今必要なものだけ作る」に反する
3. **give-up の共通機構**。「自走をやめる条件」は webhook が既に持っている
   (`attempts >= 8` で `recovery_pending` へ置き、**失敗として確定はしない**)。
   共通側に持たせるのは「1 回の実行で扱う件数の上限」だけにし、上限に達しても
   対象を失敗にせず次回の実行へ残す (正典が言う区別と同じ)
4. **正典と同じ形の結果 DTO**。webhook は 4 つの内訳 (再実行済み / 次回へ回した /
   回収待ちへ置いた / 何もしなかった) を **docs/architecture.md が監視の必須項目として
   宣言済み**なので、共通側は「結果の種類ごとの件数」を stream ごとに保持する形にする

### 寄せ替えない経路 (目録に理由付きで登録し、混ぜない)

「滞留した業務状態を前へ進める」ことと、「外部と突き合わせる」「保持期間を決着させる」ことは
別の概念である (似ているからで統合しない)。次は stream にしない:

- `billing:reconcile-auto-recharge` / `billing:reconcile-schedules` /
  `billing:reconcile-subscription-status` — Stripe を真実として収束させる突き合わせ。
  DB の状態だけでは行き先が決まらない
- `render:reconcile-outputs` — 世代交代済みの出力を消し込む後始末であり、滞留の前進ではない
- `inquiry:purge` / `idempotency:prune` / `account:purge-deletion-requests` /
  `billing:purge-retention-expired` — 保持期間の決着
- `billing:detect-orphan-billing-organizations` / `billing:send-billing-reminders` — 検知・通知

### 5 番 (撮影アップロード予約) の扱い

現行の `sweep()` は 3 つの責務が 1 メソッドに同居している:
(a) 期限切れ予約の解放、(b) 未登録 S3 オブジェクトの削除、(c) 古い行の物理削除。
(a)(b) は滞留回収なので stream にし、(c) は保持期間の決着なので
`capture:purge-upload-reservations` (日次) へ分ける。aicue には既に保持期間の決着コマンドが
4 本あり、分けたほうが既存の作法に揃う。

## 期待効果

- **使命への貢献**: 撮影 → 解析 → レンダの各段が止まったまま放置されると、現場の利用者は
  チケット枠を押さえられたまま撮り直しもできない。回収の入口が 5 本バラバラだと、
  1 本が静かに止まっても誰も気づけない。入口と目録を 1 つにすることで
  「止まった仕事が必ず前へ進む」ことの担保を 1 箇所で確認できるようにする
- **6 本目を素通しで足せなくする** (deny-by-default の目録)。これが本改善の主目的で、
  同型の実装が 4 リポジトリで独立に増えたという家系の実績がその必要性の証拠である
- **本番で試し打ちできる** (既定 dry-run)。回収は「正常に動いていたものを失敗にする」
  事故を起こしうる操作なので、副作用なしに対象を見られることに運用上の価値がある
- **cron の失敗が全 stream で運用アラートに載る** (現在は 1 本だけ)

## 実装方針（概要）

1. `App\Contracts\Recovery\StuckWorkStream` (契約) と
   `App\Enums\Recovery\RecoveryOutcome` (結果の語彙) を新設する
2. `App\Services\Recovery\StuckWorkStreamRegistry` (stream の解決) と
   `App\Services\Recovery\StuckWorkRecoverySweeper` (走査・上限・集計) を新設する
3. `App\Services\Recovery\Streams\` 配下に stream を 5 本置く。中身は既存の実装を移設し、
   業務判定 (何を滞留とみなすか・どう前へ進めるか) は各ドメインの Service に残す
4. `App\Console\Commands\Operations\RecoverStuckWorkCommand` (`work:recover-stuck`) を新設し、
   `routes/console.php` の 5 本の `Artisan::command` と `Schedule` を撤去して、
   stream ごとの `Schedule` (現行の周期を 1 本ずつ保存) に置き換える
5. `AnalysisJobService::recoverStale()` / `RenderJobService::recoverStale()` /
   `TicketLedgerService::releaseStale()` / `StripeWebhookProcessor::recoverStale()` /
   `StaleUploadReservationSweeper::sweep()` を**同じ PR で撤去**する (並走させない)
6. Architecture テストを 2 本追加する (目録 gate / 撤去済み参照 gate)
7. 既存の Feature テストは 1 本も消さず、呼び出し先を stream・新コマンドへ張り替えて維持する

## 制約・前提

- **既存テストの削除は禁止事項**。5 経路のテスト (閾値・冪等・競合・順序非依存・通知) は
  すべて維持する。呼び出し口の張り替えのみ行う
- **後方互換の並走を残さない** (思考原則 3)。旧メソッド・旧コマンド名は同じ PR で消す
- 閾値の値は 1 つも変えない (`AnalysisTimeBudgetInvariantTest` /
  `RenderTimeBudgetInvariantTest` の序列をそのまま緑に保つ)
- 定期実行の**周期も変えない** (5 分 ×4 / 10 分 ×1)。統合するのは実装契約と入口であって
  実行間隔ではない
- `tests/Support/Security/DirectFetchInventory.php` に登録済みの 3 エントリ
  (主キー同一性クエリの分類) は、移設先のファイルパスへ**キーを更新**する必要がある
- テンプレートからの意図的な逸脱は `docs/template-divergence.md` に記録する

## スコープ外

- 突き合わせ (reconcile) 系 4 本・保持期間の決着系 4 本・検知系 2 本の寄せ替え
- 閾値の値そのものの見直し
- キュー投入経路の原子性 (別 feature `queue-dispatch-outbox` の範囲。aicue は既に
  業務トランザクション内 dispatch で決着済み)
- 台帳 (lctl) への書き戻し (監督セッションの責務)

