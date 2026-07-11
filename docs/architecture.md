# アーキテクチャ概要

テンプレート同梱リソースの一覧と層構造のリファレンス。
tenancy 設計・組み込み手順の詳細は [docs/app-integration-guide.md](app-integration-guide.md)、
Default Team パターンは [docs/default-team-pattern.md](default-team-pattern.md) を参照。

> **規約**: 新規モデルを追加したら、対応する Factory の追加
> ([docs/factories.md](factories.md)) と本書「ドメインモデル」表への追記が必須。
> どちらかを欠いた実装完了報告は不可 (AGENTS.md 実装規約)。

## 層構造

```
routes/ (web / api / ai / console)
  → Http/Controllers (薄く。認可 + Service 委譲 + DTO/JsonResource/Inertia 応答)
    → Services (ビジネスロジック。transaction はここ)
      → Models (Eloquent。保護キーは forceFill / relation で明示代入)
  → Mcp/Servers + Mcp/Tools (MCP 公開面。AppMcpTool 基底 + ToolName enum 経由)
  → Filament (管理画面。AdminUser 専用 guard)
Policies (認可。laratrust_team_id 明示の strict check)
Prompts (LLM 呼び出し factory。prompt 本文は resources/prompts/*.yaml)
DataTransferObjects / Http/Resources (応答形の単一定義)
```

## ドメインモデル (テンプレート同梱)

| Model | 役割 | tenancy |
|---|---|---|
| `User` | エンドユーザー。PII (email/name) は CipherSweet 暗号化 | 複数 Organization に所属 |
| `AdminUser` | 運営管理者 (Filament 専用 guard)。エンドユーザーと別テーブル | tenant 外 |
| `Organization` | テナント境界。課金・quota・API キーの単位 | ルート |
| `Team` (laratrust) | Laratrust のロールスコープ。Organization と 1:1 | Organization 従属 |
| `CustomTeam` | 組織内のチーム。各組織に Default Team がちょうど 1 つ | Organization 従属 |
| `Project` | 作業単位。CustomTeam (通常は Default Team) 配下 | Organization → CustomTeam 従属 |
| `Item` | **ドメインリソースの見本**。新規リソース追加はこれを複製して始める | Project 従属 |
| `Category` | AI-CUE: 動画マニュアルの分類 (project 内で name ユニーク・sort_order は Service 専有) | Project 従属 |
| `VideoManual` | AI-CUE: 動画マニュアル本体 (status enum・カテゴリ削除で未分類化) | Project 従属 |
| `SourceDocument` | AI-CUE: SOP ファイル (追記型 immutable。差し替え = 新規行、解析は latest 勝ち。extracted_json は解析の write-only 監査スナップショット) | VideoManual 従属 |
| `AnalysisJob` | AI-CUE: AI 解析ジョブ (status/step/progress。ticket_reservation_id = 予約の冪等キー。$fillable なし = Service の明示代入のみ) | VideoManual 従属 |
| `Cut` | AI-CUE: シナリオカット (Tier B schema 先取り。自己参照 parent_cut_id / 循環 FK adopted_take_id は後付け migration) | VideoManual 従属 |
| `Take` | AI-CUE: 撮影素材 ((cut_id, client_take_id) UNIQUE = 同期冪等キー。downloaded_at = DL 済み ACK → 削除不可) | Cut 従属 |
| `TakeUploadReservation` | AI-CUE: テイクアップロード予約 (容量 Quota の bytes_pending 真実源。pending→verifying→completed/released。organization_id は org 集計用の非正規化キー) | Cut 従属 (organization_id は集計用) |
| `Role` / `Permission` | Laratrust のロール・権限 (seed 固定) | Team スコープ |
| `OrganizationInvitation` | 組織招待 (token は hash 保存) | Organization 従属 |
| `SocialAccount` | ソーシャルログイン連携 | User 従属 |
| `ApiKey` | REST API / MCP 認証キー (組織スコープ、secret は hash 保存) | Organization 従属 |
| `OauthSession` | OAuth セッション (CLI ログインの認可承認 1 回 = 1 行。token chain を集約、失効単位) | Organization / User 従属 |
| `IdempotencyKey` | API 冪等キー (API キー actor / OAuth user actor 単位) | ApiKey または User 従属 |
| `McpIdempotencyKey` | MCP 書き込み tool の冪等レコード (caller 境界は org + user + tool + key) | Organization / User 従属 |
| `Inquiry` | 問い合わせ (公開フォーム由来。PII は CipherSweet 暗号化、AdminUser が対応) | tenant 外 |
| `EmailSuppression` | メール送信抑止リスト (SES バウンス/苦情由来。email 正規化平文が UNIQUE 正本) | tenant 外 |
| `LlmCallLog` | LLM 呼び出しの監査ログ (コスト・FX スナップショット含む) | Organization 従属 |
| `SecurityAuditEvent` | セキュリティ監査イベント | User / Organization 従属 |
| `ModelAudit` | Critical Action 中のモデル属性 diff 監査 (owen-it/laravel-auditing。CriticalActionContext active 時のみ記録) | tenant 外 |
| `Billing/Plan` / `Billing/PlanPrice` | プラン定義と Stripe Price 対応 | tenant 外 (マスタ) |
| `Billing/OrganizationQuota` | 組織ごとの利用上限 | Organization 従属 |
| `Billing/StripeWebhookEvent` | Stripe webhook の冪等マシン | tenant 外 |
| `Billing/TicketLedgerEntry` / `Billing/TicketReservation` | チケット台帳 (reserve→commit/release の 2 フェーズ。期限付き付与・idempotency_key 冪等付与・返金 clawback) | Organization 従属 |
| `Billing/TicketVolumePrice` | スポット購入の数量逐減 (volume tier) 単価の Stripe Price snapshot | tenant 外 (マスタ) |
| `Billing/TicketCheckoutSession` | チケットスポット購入の Stripe Checkout Session 追跡 (attempt_token 冪等 + 単価 pin = webhook 金額照合の出典。status: pending/completed/expired) | Organization 従属 |
| `Billing/Subscription` | Cashier Subscription のテンプレート拡張 (current_period_end / Subscription Schedule の部分完了追跡列) | Organization 従属 |
| `Billing/BillingNotification` | 請求通知の delivery record (通知台帳。(type, invoice_id) / (type, dedup_key) 複合 UNIQUE で send-once を構造保証) | Organization 従属 |

## 主要 Service (テンプレート同梱)

| Service | 役割 |
|---|---|
| `Organization/OrganizationProvisioningService` | 組織作成 (Team + Default Team + Owner ロールまで一括) |
| `Organization/OrganizationMembershipService` | メンバー追加・削除・ロール変更 |
| `Project/ProjectService` | プロジェクト CRUD |
| `Manual/CategoryService` | AI-CUE: カテゴリ create/update/reorder/delete (Project 行ロックで直列化・sort_order 専有) |
| `Manual/VideoManualService` | AI-CUE: 動画マニュアル create/updateMeta/delete (created_by サーバ導出・category 保存時再解決) |
| `Manual/ScenarioService` | AI-CUE: シナリオ (Cut 群) の document 単位保存 (VideoManual 行ロック → rendering/analyzing・楽観ロック guard → 2 段階 reconcile → version+1) + AI 解析結果の materialize (`materializeIntoLockedManual` = ロック済み前提メソッド)。§シナリオ整合の共有不変条件の準拠実装 |
| `Manual/SourceDocumentService` | AI-CUE: SOP (SourceDocument) の保存。追記型 immutable (差し替え = 新規行)。専用 route 経路は VideoManual 行ロック + draft/ready guard、MIME は内容 sniff で再判定 (polyglot 対策) |
| `Manual/AnalysisJobService` | AI-CUE: AI 解析の状態機械 (trigger = draft/ready→analyzing + in-flight 冪等 + 残高事前チェック / failJob = 行ロック + terminal guard の冪等失敗確定 / recoverStale = stale 回復 cron 本体) |
| `Manual/AnalysisPipeline` | AI-CUE: 解析パイプライン本体 (extract→decompose→generate→terminal tx)。チケット 2 フェーズ (予約冪等キー = analysis_jobs.ticket_reservation_id、materialize + commit + succeeded を単一 tx で原子化)。LLM 出力の有界リトライ (JSON 検証失敗のみ最大 2 回) |
| `Manual/SopTextExtractor` | AI-CUE: SOP テキスト抽出 (pdf = smalot/pdfparser / xlsx·xls = phpoffice/phpspreadsheet / txt)。UTF-8 strict 検証 + UTF-8 バイト上限 (token budget 導出。AnalysisTokenBudgetInvariantTest が算術を固定) |
| `Manual/RenderJobService` | AI-CUE: レンダの状態機械 (trigger = ready→rendering + render 冪等 + 採用テイク/尺/残高 guard / triggerPreview = Organization 行ロックで org 同時 preview 上限を直列化 / failJob = 冪等失敗確定 / completeRenderIntoLockedManual = ロック済み前提メソッド / recoverStale・reconcileOutputs = cron 本体) |
| `Manual/RenderPipeline` | AI-CUE: レンダパイプライン本体 (startJob→buildManifest→compose→upload→finalize)。チケット 2 フェーズ (予約冪等キー = render_jobs.ticket_reservation_id、complete + commit + succeeded を terminal tx で原子化)。version スナップショット固定 (§10.8-6) |
| `Manual/CutSequencer` | AI-CUE: カット表示順 (step→配下 point) と表示ラベル (手順N/急所N-M) の導出 (読み取り専用) |
| `Render/VideoComposer` (interface) + `Render/FfmpegVideoComposer` | AI-CUE: 動画合成の抽象 + ffmpeg v1 実装 (Process facade 経由・配列引数。filtergraph にはサーバ生成一時ファイル名と数値のみ = 字幕本文を直接埋めない) |
| `Render/AssSubtitleWriter` | AI-CUE: ASS 字幕生成の安全境界 (唯一の字幕テキスト出力点。リテラル \N/override tag/制御文字/zero-width の正規化 + mb 安全な長さ上限) |
| `Render/RenderObjectStorage` | AI-CUE: レンダ出力 S3 操作の集約点 (download/upload/署名 URL/削除/prefix。DL 用 Content-Disposition は RFC 5987 + ASCII fallback + ヘッダ注入不能) |
| `Auth/SocialAccountService` | ソーシャルログイン連携 |
| `Billing/BillingAccess` | 課金ゲート判定 (`subscription('default')` が active/trialing なら許可)。**課金による利用可否の判定は本クラス経由のみ** (アプリは本クラスの差し替えで gate 方針を変更する)。適用は `require-active-subscription` middleware (業務 route group。billing / webhook は構造的 allowlist) |
| `Billing/QuotaService` | quota の消費・検証 |
| `Billing/StripeWebhookProcessor` | webhook の冪等処理 |
| `Billing/BillingNotificationDispatcher` | 請求通知の冪等 dispatch 窓口 (通知台帳へ insertOrIgnore → 新規行のみ queue。**請求系通知の送信は本クラス経由のみ**) |
| `Billing/StripeScheduleGateway` | Subscription Schedule API の集約 gateway (create/update/release/retrieve。テストは mock 差替) |
| `Billing/StripePriceCatalogClient` | Stripe Price Catalog への read-only adapter (`prices.list` の lookup_keys で現行 active Price を解決。価格カタログ as-code の sync/verify コマンドが利用) |
| `Billing/PortalConfigurationSpec` | Customer Portal の許可機能ポリシー固定真実源 (subscription_update 無効化。`billing:ensure-portal-configuration` が生成/検証) |
| `Billing/TicketLedgerService` | チケットの reserve/commit/release と冪等付与 (grantMonthly/grantSignupGrant/grantPurchased)・返金逆仕訳 (clawback) |
| `Billing/TicketCheckoutService` | チケットスポット購入の冪等 Checkout 開始 (org 単位 Cache::lock 直列化 + attempt_token 冪等 + live pending dedup + INSERT unique 違反の re-read 収束。二重課金防止の冪等マシン) |
| `Billing/TicketCheckoutGateway` (interface) + `Billing/CashierTicketCheckoutGateway` | Stripe one-time Checkout の抽象 (mode=payment / card のみ / promo・tax なし = amount_subtotal 照合の前提。idempotency key 対応。テストは fake を bind) |
| `Billing/TicketPricingService` | チケット価格の表示専用読み取り口 (傾斜表 / spot 単価 / signup grant 表示値。消費・購入経路と独立) |
| `Marketing/PricingService` | 料金表 (/pricing) のプラン一覧構築 (plan_prices current + config/quota.php limits の値のみ参照) |
| `OAuth/OauthSessionListService` | OAuth セッション一覧 (CLI セッション + legacy MCP token の併記) |
| `VersionInfoService` | `/api/v1/version` の capability negotiation payload (semver fail-fast + CLI client id 解決) |
| `Mcp/McpIdempotencyService` | MCP 書き込み tool の冪等 replay/store (`mcp_idempotency_keys`) |
| `Mcp/Auth/McpAuthorizationContext` | MCP tool の認可コンテキスト (token → user/org 解決 + permission runtime 再評価) |
| `Security/SecurityEventRecorder` | セキュリティ監査イベント記録 |
| `LlmCallLogWriter` / `FxRateService` | LLM コスト記録と為替換算 |

## シナリオ整合の共有不変条件 (AI-CUE ドメイン規約)

> **cuts / video_manuals.scenario_version / video_manuals.status を書き込む全経路は、
> 対象 VideoManual 行を `lockForUpdate()` で取得した同一トランザクション内で反映する。**

- 直列化点は VideoManual 行 (Project 行はロックしない。カテゴリ等 project 集合との整合は
  シナリオ書き込みに無関係のため、直列化粒度を manual に意図的に絞る)。
  親 relation 経由の再解決 (`$project->manuals()->whereKey(...)->lockForUpdate()`) で
  「子は親に属する」も同時に担保する
- 準拠実装 (メソッド粒度の経路 inventory。`ScenarioWritePathInventoryTest` が
  deny-by-default の token 走査で機械検証する = **Architecture テストへ昇格済み**):

  | 経路 | 書いてよいもの |
  |---|---|
  | `ScenarioService::save()` | cuts / scenario_version / status (rendering·analyzing guard 付き) |
  | `ScenarioService::materializeIntoLockedManual()` | cuts / scenario_version / status (analyzing→ready のみ。呼び出しは AnalysisPipeline::finalize の terminal tx に限定) |
  | `AnalysisJobService::trigger()` | status (draft·ready→analyzing のみ) |
  | `AnalysisJobService::failJob()` | status (analyzing→ready·draft のみ。cuts 有無で決定) |
  | `Capture/CaptureTakeService::adopt()` / `delete()` | cuts.adopted_take_id (採用 / 採用テイク削除時の null 化。検出 4 の allowlist) |
  | `RenderJobService::trigger()` | status (ready→rendering のみ。scenario_version はスナップショット読み) |
  | `RenderJobService::failJob()` | status (rendering→ready のみ。kind=render に限る。preview は触らない) |
  | `RenderJobService::completeRenderIntoLockedManual()` | cuts.cut_length_ms / total_length_ms / status (rendering→published のみ。呼び出しは RenderPipeline::finalize の terminal tx に限定 = 検出 5) |

  テイク採用 API は inventory 準拠へ昇格済み (検出 4 = `adopted_take_id` の token 走査 +
  書き込み形検出)。RenderJob の状態遷移も inventory 準拠済み (検出 5 =
  `completeRenderIntoLockedManual` の宣言/呼び出し限定)
- 状態 guard (rendering/analyzing 中の保存は 409) は第一防衛、共有行ロックは
  「job 側の書き込みと保存が絶対に交差しない」ための構造的防衛 (二重防御)

### AI 解析ジョブの運用契約

- 解析ジョブ (`RunManualAnalysis`) は専用 queue connection **`database-analysis`**
  (queue=analysis、retry_after=1560) で流れる。**本番/ステージングの worker プロセス定義・
  デプロイ手順・監視対象に `php artisan queue:work database-analysis` を必須項目として登録する**
  (専用 worker が居ないとジョブは滞留する。queued 滞留は `analysis:recover-stale-jobs` cron が
  30 分で failJob するため、滞留 = 監視で気づける)
- 時間 budget の連鎖 `job timeout (1,380s) < retry_after (1,560s) < 予約 TTL (1,800s) ≤ stale 閾値 (1,800s)`
  は `AnalysisTimeBudgetInvariantTest` が CI 固定する
- ローカル/テストの検証: パイプラインの同期実行は `AnalysisPipeline::run()` の直接呼び出し、
  dispatch の検証は `Queue::fake()` (sync ドライバの自動実行には依存しない)

### レンダジョブの運用契約

- レンダジョブ (`RunManualRender`) は専用 queue connection **`database-render`**
  (queue=render、retry_after=1680) で流れる。**本番/ステージングの worker プロセス定義・
  デプロイ手順・監視対象に `php artisan queue:work database-render` を必須項目として登録する**
  (専用 worker が居ないとジョブは滞留する。queued 滞留は `render:recover-stale-jobs` cron が
  **10 分** (queued 短 SLA。enqueue 時点で編集を止めるため) / running 滞留は **30 分** で
  failJob するため、滞留 = 監視で気づける)
- **worker ホスト要件**: ffmpeg / ffprobe バイナリ (`RENDER_FFMPEG_BINARY` /
  `RENDER_FFPROBE_BINARY`) と日本語対応フォント (`RENDER_SUBTITLE_FONT`。既定
  Noto Sans CJK JP) のインストールが前提 (Docker image 要件)。テストは `Process::fake()` で
  コマンド構造のみ検証するため、**実 ffmpeg でのフィルタ列・音声 map・字幕描画の実機検証は
  staging worker で行う** (運用項目)
- 時間 budget の連鎖 `job timeout (1,500s) < retry_after (1,680s) < 予約 TTL (1,800s) ≤ stale running 閾値 (1,800s)`
  + `queued 閾値 (600s) < running 閾値 (1,800s)` は `RenderTimeBudgetInvariantTest` が CI 固定する。
  `manual.render_max_total_source_ms` (尺上限ソフトゲート) を引き上げる際は timeout 1,500s に
  実レンダが収まるかを実測で再確認すること (連動する運用値)
- **出力保持は「非同期で最新 succeeded 1 世代へ収束」**: finalize が旧世代の
  `Jobs/Manual/DeleteRenderOutputsJob` (media queue。payload は render job id のみ =
  任意キー削除の権限を持たない。prefix 検証 + CAS NULL 化) を積み、取り残しは
  `render:reconcile-outputs` cron (5 分毎・onOneServer) が再投入する (dispatched/skipped を
  info 出力 = 削除が進まない異常を件数推移で検知)
- **グローバルロック順 (単一真実源 = 本節。RenderPipeline docblock は参考転記であり乖離時は本節優先)**:

  ```
  render_jobs → video_manuals → ticket_reservations → organizations
  ```

  (analysis 系の既存順 `analysis_jobs → video_manuals → …` と同構造。analysis_jobs と
  render_jobs を同一 tx でロックする経路は存在しないため両者の相対順は定義不要)。
  全経路はグローバル順の部分列のみで構成する (逆順取得ゼロ = 循環待ちを構成できない)。
  新経路追加時は本表 + RenderPipeline docblock を同時更新すること:

  | 経路 | 取得列 (すべてグローバル順の部分列) |
  |---|---|
  | `RenderJobService::trigger` | video_manuals のみ (balance() はロックなし集計) |
  | `RenderJobService::triggerPreview` | video_manuals → organizations |
  | `RenderPipeline::startJob` | render_jobs → (render のみ reserve 内部: organizations) |
  | `RenderPipeline::buildManifest` | video_manuals (読み取り一貫性の確定点) |
  | `RenderPipeline::finalize` | render_jobs → video_manuals → (render のみ commit 内部: ticket_reservations → organizations) |
  | `RenderJobService::failJob` | render_jobs → video_manuals → (release 内部: ticket_reservations → organizations) |
  | `DeleteRenderOutputsJob::handle` | 行ロックなし (読み取り検証 → tx 外 S3 削除 → CAS update の 3 段) |
- ローカル/テストの検証: パイプラインの同期実行は `RenderPipeline::run()` の直接呼び出し +
  fake `VideoComposer` (container swap)、dispatch の検証は `Queue::fake()`

## チケットスポット購入 (T007) の運用契約

- **経路**: `GET /purchase-tickets` (閲覧 = 組織メンバー) / `POST /purchase-tickets/checkout`
  (`manageBilling` のみ)。課金ゲート (`require-active-subscription`) の対象外 = 未契約 /
  free プラン組織でも購入できる。payload は `count` / `attempt_token` のみ
  (金額・Price ID は `TicketVolumePrice::currentTierFor` がサーバ権威で解決)
- **二重課金防止 4 層**: attempt_token 冪等 (UNIQUE(org, attempt_token) + Stripe idempotency key
  `purchase:{token}`) → live pending dedup (同 org×user の決済待ち session を 1 本に収束) →
  INSERT unique 違反の re-read 収束 → webhook 冪等 (claim + 台帳 idempotency_key
  `purchase:{sessionId}` UNIQUE)
- **webhook 付与 (checkout.session.completed)**: 真実源は `ticket_checkout_sessions` 行。
  payload の customer / metadata.org_ref は照合のみ (不一致・行不在・payment_status≠paid・
  amount_subtotal≠count×pin 単価・currency 不一致は例外 throw = retryable failure →
  Stripe 再送で再処理)。作成側 payload は promo / automatic tax を含まない
  (amount_subtotal 照合の前提。gateway invariant テストで固定)
- **terminal failure の運用手順**: 付与系イベント (checkout.session.completed / invoice.paid) が
  attempts 上限 (8) に到達すると terminal-ack + `report()` (運用アラート) される。
  対応: `stripe_webhook_events.failure_reason` を参照し、Stripe ダッシュボードで決済状態を確認 →
  決済済み・未付与が確定した場合のみ tinker 等で `TicketLedgerService::grantPurchased()` を
  手動実行する (idempotency_key `purchase:{sessionId}` により再実行しても二重付与しない)。
  併せて `ticket_checkout_sessions` 行を completed 化する
- **放棄 session の回収**: Stripe Checkout 自体の有効期限 (既定 24h) で Stripe 側が expire し、
  DB 行は checkout 開始時の期限切れ回収 (`status=pending AND expires_at <= now` → expired) で
  局所回収する (専用 cron は作らない)

## アプリ内通知センター (T008) の運用契約

- **格納**: Laravel 標準 `notifications` テーブル (Eloquent 標準 `DatabaseNotification` を使う。
  新規モデル / Factory は作らない。テストは `$user->notify(...)` 実発火で行を作る)。
  `organization_id` は first-class 列 (nullable FK, cascadeOnDelete)。
  `OrganizationScopedDatabaseChannel` (標準 DatabaseChannel の `buildPayload` 拡張 +
  container binding 差し替え) がサーバ導出で埋める。`data` (jsonb) は表示用 payload 限定
  (org 判定・クエリには使わない)
- **type 規約**: `notifications.type` には `NotificationType` enum の value を格納する
  (クラス名を DB に置かない。`InAppNotificationTypeInvariantTest` が
  `app/Notifications/InApp/*` の全派生に deny-by-default で強制。
  TS 側 `types/notification.ts` との値集合同期は `NotificationTypeTsSyncInvariantTest`)
- **発火**: すべて `NotificationCenterService` 経由・既存 exactly-once 遷移の **commit 後**
  (解析/レンダ terminal 遷移の bool ゲート / 招待作成後 / reserve の残高閾値クロス検知)。
  terminal tx 内に通知 insert を入れない。通知例外は catch + report でジョブ本流を壊さない
- **配信保証は at-most-once** (重複なし・欠落あり得る)。正はジョブ status + 既存ポーリング UI で、
  通知は補助チャネル。terminal commit 直後〜通知 insert 間のプロセス停止の欠落窓 (数 ms) は許容し、
  outbox 台帳は作らない (送達保証が要件化したときに outbox へ移行する)。worker のジョブ実行中
  停止は `recoverStale` → `failJob` 経由で失敗通知が発火する
- **宛先導出**: ジョブ通知 = `manual.created_by` ∪ `triggered_by` (jobs 列。Auth からの明示代入のみ =
  `MassAssignmentProtectedKeys` 登録済み) を org 所属再確認 + dedup / 招待 = `whereBlind` 一致の
  既存ユーザーのみ (平文 token 非含有) / 残高低下 = org の owner/admin
  (`organizationRole` = laratrust_team_id 明示判定)
- **残高低下のクロス検知**: `TicketLedgerService::reserve` の org 行ロック内で
  「実効残高 (Reserved 拘束込み) が `billing.ticket_low_balance_threshold` を跨いだ」ときのみ
  `DB::afterCommit` で 1 回通知 (commit は拘束と台帳が相殺し balance 不変 = クロスを発生させない。
  release/grant で回復して再度跨げば再通知)。`billing_notifications` (メール送達台帳) には行を作らない
- **読み出し**: 自分宛 (notifiable = 自分) で構造的に閉じる (org フィルタなし = 全 org 横断)。
  `{notification}` は implicit binding を使わず relation 経由解決 (cross-user は 404 = 存在秘匿)。
  `open` は POST + 303 のサーバ解決遷移 (認可判断は複製せず遷移先の Gate が唯一の判断点)。
  未読数は `HandleInertiaRequests` の shared props `notifications.unreadCount` (closure 共有のため
  `router.reload({ only: ['notifications'] })` の partial reload キーとしてそのまま使える。
  将来の SPA 内ポーリングはこのキーで実現する。v1 はページ遷移時更新のみ)

## 撮影 PWA (presigned アップロード + 容量 Quota) の運用契約

doc/10 §10.3 / §10.8-4/-7 の実装 (T004)。routes は `/app/projects/{project}/...`
(web ガード・セッション + CSRF。GET は Inertia、書き込みは XHR JSON)。

- **presigned 直アップロード**: `Capture/TakeUploadService` が Organization 行ロック tx 内で
  容量 Quota (`max_storage_bytes`。bytes_used + bytes_pending + 加算) を判定し
  `take_upload_reservations` (pending) を予約 → `Capture/TakeObjectStorage` が
  **ChecksumSHA256 を署名条件に含む** presigned PUT URL + Crypt 封緘の検証専用チケットを発行。
  `Capture/TakeRegistrationService` がチケット検証 + 予約 claim (pending→verifying の原子的
  UPDATE) + HeadObject 三点照合 (size/content_type/checksum) + `(cut_id, client_take_id)` 冪等
  登録を行う (確定は verifying→completed の CAS = sweeper と競合しない)
- **使用量の真実源は集計クエリ** (`Capture/StorageUsageService`。bytes_used = takes.size_bytes の
  org 合計 / bytes_pending = pending 未失効 + verifying 全件。カウンタキャッシュは持たない)
- **media queue**: S3 オブジェクト削除 (`Jobs/Capture/DeleteTakeObjectsJob`) は専用 connection
  **`database-media`** (queue=media、retry_after=300) で流れる。**本番/ステージングの worker
  プロセス定義・デプロイ手順・監視対象に `php artisan queue:work database-media` を必須項目
  として登録する** (専用 worker が居ないと削除ジョブは滞留する)
- **孤児掃除 cron**: `capture:release-stale-upload-reservations` (10 分毎・onOneServer) が
  期限切れ pending / stale verifying (updated_at 15 分超過) を released 化して bytes_pending を
  解放し、PUT 済み未登録の S3 オブジェクトを削除する (`Capture/StaleUploadReservationSweeper`。
  fresh verifying には触れない = 登録処理の claim 契約と競合しない)。released/completed の
  retention (30 日) 超過行は物理削除する
- **DL 済み削除不可 (D6)**: 詳細 GET が採用テイクの署名 DL URL と同時に発行する ACK トークン
  (Crypt 封緘・同 TTL) を `POST .../takes/{take}/downloaded` が検証して `takes.downloaded_at` を
  打刻する。非 null のテイクは DELETE 422
- **PWA フロント**: `pages/Capture/*` + `features/capture/*` + `lib/capture/*`
  (即時アップロード優先・IndexedDB は失敗/オフライン時の一時バッファ・419 は csrf-cookie
  再取得 1 回リトライ)。SW (`public/capture-sw.js`) は同一オリジン GET `/build/*` のみ
  stale-while-revalidate (アプリ応答・S3 は素通し)

## 管理メニュー (/manage/users・/projects/{project}/categories)

doc/04 §4.2 の管理者専用画面 (T006)。書き込みは既存 endpoint を再利用し、GET 画面のみ新設。

### ロールの 2 層モデル (保存しない = 導出)

| 遷移コマンド (`AdminConsoleRole`) | 適用後の最終状態 (1 tx で保証) |
|---|---|
| admin | org ロール Admin + org 配下 project pivot detach (stale 掃除) |
| editor | org ロール Member + Default Project pivot `project_admin` |
| shooter | org ロール Member + Default Project pivot `project_member` |

表示状態 (`MemberRoleState`) は org ロール × Default Project pivot から毎リクエスト導出する
5 値 (owner/admin/editor/shooter/**unassigned**)。未割当 (旧招待・project 削除後・Laratrust
ロール未付与の異常行) は非表示にせず可視化し、ロール割当コマンドで修復できる
(`OrganizationMembershipService::applyConsoleRole` の修復経路)。

- `organizations.members.update` (PATCH) / `organizations.invitations.store` (POST) の role
  payload は 3 値コマンド (旧 org ロール値は enum 検証で拒否)。Owner は enum 外 = 構造的に
  指定不可 (Owner 昇格は transferOwnership のみ)
- 招待は `organization_invitations.project_role` (nullable・サーバ導出・forceFill 専有) を持ち、
  受諾 (`joinOrganization` = 招待行 lockForUpdate + organization_user の insertOrIgnore) で
  Default Project へ pivot attach。受諾時 project 不在は org 参加のみ = 未割当へ可視 degrade

### DefaultProjectResolver の read/write 契約

`Services/Project/DefaultProjectResolver` が「org の先頭 project (projects.id 昇順)」解決の
single source of truth (capture.home も同 resolver)。表示/redirect は `resolve()` (ロックなし)、
pivot 書き込みは `resolveForUpdate()` (呼び出し側 tx 内で Project 行ロックを保持 =
CategoryService の Project 行ロック直列化と同型) のみを使う。

### pivot 書き込み経路の inventory

`project_members` への書き込みは `OrganizationMembershipService` (applyConsoleRole /
joinOrganization / removeMember の掃除) と `ProjectMemberController` に閉じる。
**`ProjectMemberPivotWritePathTest`** (Architecture) が deny-by-default で強制する
(掃除対象は必ず `$organization->projects()` 経由 = cross-org 不変条件)。

### 画面と guard

| route | 画面 | guard |
|---|---|---|
| GET `/manage/users` | `Admin/Users` (メンバー + 招待中 + 追加) | current org 解決 (org param なし = 越境不能) + `manageMembers` (403)。`/manage/` 配下の auth+verified は `ManageRouteAuthGuardTest` が強制 |
| GET `/projects/{project}/categories` | `Admin/Categories` (一覧・追加・編集・削除・▲▼) | 業務 group (課金ゲート + project.in-current-org = cross-org は認可前 404) + `CategoryPolicy::viewAny` (= ProjectPolicy::update 委譲。撮影者 403) |

**A+B 不可分の理由**: `members.update` / `invitations.store` の 3 値コマンド契約書き換えと
唯一の caller UI (Admin/Users + Settings スリム化) は同一リリース単位でなければならない
(分離すると旧 Settings UI が旧契約値を送信する並走/破壊状態になる。将来の分割 PR 事故防止)。
Settings の members props は `{id, name}` のみ (PII 最小化)、メンバー管理 UI の不在は
Vitest (`OrganizationsSettings.test.ts`) と Feature 両面で回帰固定する。

## 公開面

| 面 | 入口 | 認証 |
|---|---|---|
| Web UI | `routes/web.php` → Inertia (Svelte 5) | session (Fortify) |
| REST API v1 | `routes/api.php` → `Http/Controllers/Api/V1` | dual guard (`auth:api-key,api-oauth`) + `resolve.api-actor` |
| MCP | `routes/ai.php` → `Mcp/Servers` | Passport OAuth 2.1 (`auth:mcp-oauth`) |
| 管理画面 | Filament (`app/Filament`) | AdminUser guard |
