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

## route binding の型制約 (ドメイン制約: route key は最大 18 桁)

`app/Http/Routing/RouteBindingTypes` が **全 binding param の型 inventory (単一 SoT)**。
`AppServiceProvider::boot` が inventory 駆動で `Route::pattern` を適用する
(route 個別の `->whereNumber()` / `->whereUuid()` は書かない)。

- **なぜ必要か**: pgsql は型不一致の比較で `22P02` (invalid_text_representation)、
  bigint 範囲外で `22003` (numeric_value_out_of_range) を投げる。非適合セグメント
  (`/projects/abc`) が implicit binding に届くと QueryException → **404 ではなく生 500**。
  型制約に合致しない URL は route にマッチしない = 404 になり、`SubstituteBindings` へ
  到達しないためクエリ自体が発行されない
- **ドメイン制約 (重要)**: `BIGINT_PATTERN` は **`[0-9]{1,18}`**。
  DB の bigint が許容する 19 桁 ID を**意図的に排除**し、
  **「AI-CUE の route key は最大 18 桁」**と定める。`[0-9]+` だと桁あふれが regex を
  通過して 22003 → 500 が残るため、**桁数だけで範囲内を保証する**
  (PHP_INT_MAX = 9223372036854775807 は 19 桁 / 18 桁の最大値は必ず範囲内)。
  実 ID が 10^18 に達することは無いため運用上の制約にならないが、
  「適合値の挙動は不変」ではない点に注意。値自体は Architecture テストが pin する
- **5 分類 (deny-by-default)**: `BIGINT` / `UUID` (param => モデルの map。pattern 適用) /
  `CUSTOM_BINDER` (`{organization}`。`{organization:slug}` 併用のため pattern を適用せず
  `MembershipScopedOrganizationBinder` が入力正規化を担う) / `NON_MODEL` / `EXTERNAL`
  (vendor route が持ち込む param を route identity ごとに登録)。
  未登録 param の出現は `RouteBindingTypeConstraintInventoryTest` が fail させる
  (未知 param を数値と推測しない)。実挙動 (非適合 → 404) は
  `tests/Feature/Routing/RouteBindingTypeConstraintTest` が pgsql 実接続で固定する
- **`MANUALLY_RESOLVED` (IV-9(a) の免除)**: controller が implicit binding を使わず
  手動解決する param は action 引数が string になるため、**「param 名 + route identity」の
  両方**で免除登録する (param 名だけの免除は同名 param を使う将来 route を丸ごと素通りさせる)。
  免除しても pattern の型制約と PK 型検査は効き続ける。現在の登録は
  `{notification}` × `notifications.open` / `notifications.read` のみ
  (cross-user 404 = 存在オラクル封じのため `$user->notifications()` 経由で解決する)
- **route identity の規約**: route name を第一とし、name 無し route は
  `method:uri` signature (method は昇順ソート・暗黙の `HEAD` は除外)。
  **Livewire の endpoint prefix (`livewire-<APP_KEY 由来 8 桁ハッシュ>`) は `livewire/` へ
  正規化**してから identity にする (正規化しないと APP_KEY ごとに inventory が壊れる)
- **`NON_MODEL` は実 route 走査の結果だけを登録する**: 現在は `intent` / `provider` /
  `userId` の 3 件。routes に現れない param を残すと IV-2 (逆方向検査) が
  陳腐化した登録として fail させる

## ドメインモデル (テンプレート同梱)

| Model | 役割 | tenancy |
|---|---|---|
| `User` | エンドユーザー。PII (email/name) は CipherSweet 暗号化 | 複数 Organization に所属 |
| `AdminUser` | 運営管理者 (Filament 専用 guard)。エンドユーザーと別テーブル | tenant 外 |
| `Organization` | テナント境界。課金・quota・API キーの単位。請求先連絡先 (`billing_contact_email` / `billing_contact_name`) は PII のため CipherSweet 暗号化 (email のみ blind index。検索は `whereBlind`) | ルート |
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
| `Billing/BillingCheckoutSession` | サブスク契約 / カード登録 Stripe Checkout Session の追跡 (attempt_token 冪等。`BillingAccess::state()` の PendingCheckout / ExpiredCheckout の出典。status: pending/completed/failed/expired)。**live/stale の判定は本モデルの `staleThresholdAt()` / `isLivePending()` が単一出典**で、`BillingAccess::state()` / `SubscriptionService::startCheckout()` / 日次 sweeper が共有する | Organization 従属 |
| `Billing/Subscription` | Cashier Subscription のテンプレート拡張 (current_period_end / has_payment_method / Subscription Schedule の部分完了追跡列) | Organization 従属 |
| `Billing/TicketAutoRecharge` | オートリチャージ設定 (1 org 1 行。**既定 off の opt-in**。同意 snapshot 4 列 + 連続失敗状態。`max_count > threshold_count` は DB CHECK) | Organization 従属 |
| `Billing/TicketAutoRechargeAttempt` | オートリチャージ試行の状態機械 (pending → paid / failed / canceled。quantity・unit_amount は起票時 pin = webhook 金額照合の出典。partial unique `tar_attempts_org_pending_unique` で org あたり pending は 1 件) | Organization 従属 |
| `Billing/BillingNotification` | 請求通知の delivery record (通知台帳。(type, invoice_id) / (type, dedup_key) 複合 UNIQUE で send-once を構造保証) | Organization 従属 |

## 主要 Service (テンプレート同梱)

| Service | 役割 |
|---|---|
| `Organization/OrganizationProvisioningService` | 組織作成 (Team + Default Team + Owner ロールまで一括) |
| `Organization/OrganizationMembershipService` | メンバー追加・削除・ロール変更 |
| `Project/ProjectService` | プロジェクト CRUD |
| `Manual/CategoryService` | AI-CUE: カテゴリ create/update/reorder/delete (Project 行ロックで直列化・sort_order 専有) |
| `Manual/VideoManualService` | AI-CUE: 動画マニュアル create/updateMeta/delete/duplicate (created_by サーバ導出・category 保存時再解決。duplicate = 別名保存: 保存済み cuts を新 manual へ複製し takes/成果物/SOP は引き継がない) |
| `Manual/ScenarioService` | AI-CUE: シナリオ (Cut 群) の document 単位保存 (VideoManual 行ロック → rendering/analyzing・楽観ロック guard → 2 段階 reconcile → version+1) + AI 解析結果の materialize (`materializeIntoLockedManual` = ロック済み前提メソッド)。§シナリオ整合の共有不変条件の準拠実装 |
| `Manual/SourceDocumentService` | AI-CUE: SOP (SourceDocument) の保存。追記型 immutable (差し替え = 新規行)。専用 route 経路は VideoManual 行ロック + draft/ready guard、MIME は内容 sniff で再判定 (polyglot 対策) |
| `Manual/AnalysisJobService` | AI-CUE: AI 解析の状態機械 (trigger = draft/ready→analyzing + in-flight 冪等 + 残高事前チェック / failJob = 行ロック + terminal guard の冪等失敗確定 / recoverStale = stale 回復 cron 本体) |
| `Manual/AnalysisPipeline` | AI-CUE: 解析パイプライン本体 (extract→decompose→generate→terminal tx)。チケット 2 フェーズ (予約冪等キー = analysis_jobs.ticket_reservation_id、materialize + commit + succeeded を単一 tx で原子化)。LLM 出力の有界リトライ (JSON 検証失敗のみ最大 2 回) |
| `Manual/SopTextExtractor` | AI-CUE: SOP テキスト抽出 (pdf = smalot/pdfparser / xlsx·xls = phpoffice/phpspreadsheet / txt)。UTF-8 strict 検証 + **SJIS 誤解釈 (pdfparser が定義済み CJK CMap 非対応のため CP932 を Windows-1252 として decode する) の区間単位復元** (**復元は日本語本文ゲートで拒否される文書にのみ適用する**。既に日本語として読める文書は 1 バイトも変更しない = 正当なテキストの不変性を構造で保証する。区間の採否は CP1252 可逆性 / SJIS-win 妥当性 / 全角日本語が 2 文字以上増える / 区間の過半数が日本語、の 4 条件をすべて満たすこと) + **日本語本文ゲート** (`manual.analysis_min_japanese_ratio` 未満は LLM に渡さず insufficientJapaneseText。評価対象は**正規化後・空白を除いた文字数**に占める日本語文字の比率。**閾値の変更は TODO 起票 + 実測の再提出を必須とする**) + UTF-8 バイト上限 (token budget 導出。AnalysisTokenBudgetInvariantTest が算術を固定)。0 バイトは媒体で弁別する (pdf = unextractable / plain・spreadsheet = tooShort) |
| `Manual/RenderJobService` | AI-CUE: レンダの状態機械 (trigger = ready→rendering + render 冪等 + 採用テイク/尺/残高 guard / triggerPreview = Organization 行ロックで org 同時 preview 上限を直列化 / failJob = 冪等失敗確定 / completeRenderIntoLockedManual = ロック済み前提メソッド / recoverStale・reconcileOutputs = cron 本体) |
| `Manual/RenderPipeline` | AI-CUE: レンダパイプライン本体 (startJob→buildManifest→compose→upload→finalize)。チケット 2 フェーズ (予約冪等キー = render_jobs.ticket_reservation_id、complete + commit + succeeded を terminal tx で原子化)。version スナップショット固定 (§10.8-6) |
| `Manual/CutSequencer` | AI-CUE: カット表示順 (step→配下 point) と表示ラベル (手順N/急所N-M) の導出 (読み取り専用) |
| `Manual/ScenarioBookendBuilder` | AI-CUE: AI 生成シナリオの前後へ導入/総括カットを決定的に付与する純関数 (DB/ロックに触れない。呼び出しは `AnalysisPipeline::finalize` の terminal tx 内。総括の要点再掲は**今回生成の steps からのみ**抽出 = 再生成時に旧シナリオを総括しない) |
| `Render/VideoComposer` (interface) + `Render/FfmpegVideoComposer` | AI-CUE: 動画合成の抽象 + ffmpeg v1 実装 (Process facade 経由・配列引数。filtergraph にはサーバ生成一時ファイル名と数値のみ = 字幕本文を直接埋めない) |
| `Render/AssSubtitleWriter` | AI-CUE: ASS 字幕生成の安全境界 (唯一の字幕テキスト出力点。リテラル \N/override tag/制御文字/zero-width の正規化 + mb 安全な長さ上限) |
| `Render/RenderObjectStorage` | AI-CUE: レンダ出力 S3 操作の集約点 (download/upload/署名 URL/削除/prefix。DL 用 Content-Disposition は RFC 5987 + ASCII fallback + ヘッダ注入不能) |
| `Auth/SocialAccountService` | ソーシャルログイン連携 |
| `Billing/BillingAccess` | billing entitlement 判定。**`plan_code` は判定に一切使わない** (quota の解決キーでしかない)。`state()` が `Subscribed` (subscription が entitled) / `ActiveFreePlan` (`free_plan_code='personal'`) のいずれかなら許可、それ以外 (`NoSubscription` / `PendingCheckout` / `ExpiredCheckout`) は遮断する。かつては「plan_code null = 支払い不要 free tier は許可」だったが P4 のゲート反転で撤廃した (無料枠は明示申告へ)。**課金による利用可否の判定は本クラス経由のみ** (アプリは本クラスの差し替えで gate 方針を変更する)。適用は `require-active-subscription` middleware (業務 route group。billing / webhook は構造的 allowlist)。plan_code は Stripe Price を持つ有償プラン契約時のみ webhook が set する状態キー — 支払い不要プランを plan_code に載せる場合は本判定とセットで見直す (`RequireActiveSubscriptionMiddlewareTest` が固定) |
| `Billing/SubscriptionService` | 契約 (Subscription) の状態管理。Stripe への I/O は Gateway 経由のみで、entitlement 導出 / webhook 受信時の状態同期 / **`attempt_token` 冪等の Checkout 開始** (`startCheckout`) に責務を絞る。§サブスク契約 Checkout の準拠実装 |
| `Billing/PersonalPlanService` | Personal (無料) の適格性判定・有効化・退役。**free entitlement は `organizations.free_plan_code` で表現**し `subscriptions` は Stripe 実体のみという invariant を守る。farming 防止は DB partial unique (`organizations_personal_free_declarer_unique`) が hard invariant、owner 条件は eligibility の best-effort |
| `Billing/AutoRechargeService` | オートリチャージの設定・同意・attempt 状態機械 (§チケット オートリチャージ の準拠実装。全ミューテータが同一ロックを取る) |
| `Billing/BillingPermissionService` | 組織スコープ `manage-billing` permission の個別付与/剥奪 (既定境界 Owner/Admin は `OrganizationPolicy::manageBilling` がロール判定し、本 service の直接付与を OR で参照する) |
| `Billing/BillingCustomerSynchronizer` | Stripe customer 同期 Job の dispatch 単一窓口。発火元は `RenameOrganizationAction` (組織名) と `UpdateBillingContactAction` (請求先メール) のみで、webhook ハンドラは通らない = Stripe→アプリ→Stripe の同期ループが構造的に起きない |
| `Billing/PlanPriceService` | プラン価格のバージョニング (旧 current 無効化 + 新 current 差し込みを単一 tx。二重 current は生成列の部分 unique が最終ガード) |
| `Billing/QuotaService` | quota の消費・検証 |
| `Billing/StripeWebhookProcessor` | webhook の冪等処理 |
| `Billing/BillingNotificationDispatcher` | 請求通知の冪等 dispatch 窓口 (通知台帳へ insertOrIgnore → 新規行のみ queue。**請求系通知の送信は本クラス経由のみ**) |
| `Billing/StripeScheduleGateway` | Subscription Schedule API の集約 gateway (create/update/release/retrieve。テストは mock 差替) |
| `Billing/StripePriceCatalogClient` | Stripe Price Catalog への read-only adapter (`prices.list` の lookup_keys で現行 active Price を解決。価格カタログ as-code の sync/verify コマンドが利用) |
| `Billing/PortalConfigurationSpec` | Customer Portal の許可機能ポリシー固定真実源 (subscription_update 無効化。`billing:ensure-portal-configuration` が生成/検証)。プラン変更はアプリが所有する (`SubscriptionService::changePlan`) |
| `Billing/TicketLedgerService` | チケットの reserve/commit/release と冪等付与 (grantMonthly/grantSignupGrant/grantPurchased)・返金逆仕訳 (clawback) |
| `Billing/TicketCheckoutService` | チケットスポット購入の冪等 Checkout 開始 (org 単位 Cache::lock 直列化 + attempt_token 冪等 + live pending dedup + INSERT unique 違反の re-read 収束。二重課金防止の冪等マシン) |
| `Billing/TicketCheckoutGateway` (interface) + `Billing/CashierTicketCheckoutGateway` | Stripe one-time Checkout の抽象 (mode=payment / card のみ / promo・tax なし = amount_subtotal 照合の前提。idempotency key 対応。テストは fake を bind) |
| `Billing/TicketPricingService` | チケット価格の表示専用読み取り口 (傾斜表 / spot 単価 / signup grant 表示値。消費・購入経路と独立) |
| `Marketing/PricingService` | 料金表 (/pricing) のプラン一覧構築 (plan_prices current + config/quota.php limits の値のみ参照) |
| `Marketing/ContactUrl` | 問い合わせ CTA の宛先解決 (`config('services.marketing.contact_url')` で内部 route / 外部 URL / mailto を切替。未設定なら `/contact`。source attribution は呼び出し側が query で付与) |
| `Onboarding/IntendedPlanResolver` | 「料金表で選んだプラン意図」を 料金表 → 登録 → Onboarding Checkout で一貫保持する (pending / org-scoped の 2 キー。put は有効値のみ・無効値は forget、peek は再正規化して残す) |
| `Onboarding/OnboardingReturnResolver` | 課金ゲートで失われた「意図先 destination」を org-scoped session に保持し完了着地で復帰させる。**same-origin の内部相対 path のみ許可** (絶対 URL / protocol-relative は破棄 = open-redirect 防御) |
| `Organization/CurrentOrganizationResolver` | current organization の「所属再確認つき」解決 + 自己修復 (読み出し時に pivot relation で所属を再確認 = dangling org を描画に出さない。書き込みは条件付き UPDATE の冪等 best-effort) |
| `Dashboard/DashboardService` | ダッシュボードのサーバ集計 (読み取り専用・固定本数クエリ。集計は organization / project の relation 経由のみ = cross-org は構造的に不可) |
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
  | `VideoManualService::duplicate()` | cuts (別名保存。元 manual を lockForUpdate して一貫読み取り、cuts は lockForUpdate 済みの**新** manual 経由で作成)。scenario_version/status/adopted_take_id のリテラル書き込みはしない (新規行は DB default 依存) ため検出 1/2/4 は非対象 |

  テイク採用 API は inventory 準拠へ昇格済み (検出 4 = `adopted_take_id` の token 走査 +
  書き込み形検出)。RenderJob の状態遷移も inventory 準拠済み (検出 5 =
  `completeRenderIntoLockedManual` の宣言/呼び出し限定)
- 状態 guard (rendering/analyzing 中の保存は 409) は第一防衛、共有行ロックは
  「job 側の書き込みと保存が絶対に交差しない」ための構造的防衛 (二重防御)

### AI 解析ジョブの運用契約

- 解析ジョブ (`RunManualAnalysis`) は専用 queue connection **`database-analysis`**
  (queue=analysis、retry_after=1680) で流れる。**本番/ステージングの worker プロセス定義・
  デプロイ手順・監視対象に `php artisan queue:work database-analysis` を必須項目として登録する**
  (専用 worker が居ないとジョブは滞留する。queued 滞留は `analysis:recover-stale-jobs` cron が
  30 分で failJob するため、滞留 = 監視で気づける)
- 時間 budget の連鎖 `job timeout (1,560s) < retry_after (1,680s) < 予約 TTL (1,800s) ≤ stale 閾値 (1,800s)`
  は `AnalysisTimeBudgetInvariantTest` が CI 固定する。内訳は
  `deadline D (1,080s = 3 × client timeout) + client timeout C (360s) + finalize 予算 (30s) + 安全余白 (90s)`。
  **D (`manual.analysis_deadline_seconds`) は `AnalysisPipeline` が各 LLM 試行の開始前に検査する
  ソフト予算**であり、走行中の呼び出しは中断しない (中断は C が担う)。ハード上限は worker の
  `$timeout` (SIGALRM)
- **LLM 呼び出しの実効タイムアウトは `resources/prompts/*.yaml` の `client_options.timeout`** である。
  この値は `config/prism.php` の `request_timeout` (30s) を **上書きする**
  (prism-prompt の `Prompt::resolveClientOptions()` → Prism の `Anthropic::client()` の
  `withOptions()` が Guzzle option を後勝ちで書き換えるため)。解析の timeout を調整するときは
  `config/prism.php` ではなく prompt YAML を見ること
- LLM 呼び出しの有界リトライ対象は **JSON 検証失敗 + transient な provider/connection 例外**
  (`ConnectionException` / 529 / 408・500・502・503・504)。429・413・その他は fail-fast で
  理由別のユーザー文言を `analysis_jobs.error` に残す。リトライは `startJob` (reserve) の後・
  `finalize` (commit) の前に閉じており予約行に触れないため、チケット 2 フェーズ
  (冪等キー = `analysis_jobs.ticket_reservation_id`) は再試行回数に依らず高々 1 回ずつ成立する
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

## サブスク契約 Checkout とオンボーディング着地 (P7/P9) の運用契約

課金ゲート反転 (P4) 後、未契約組織は業務 route group に入れない。**遮断された先の着地**と
**契約の開始**を担うのが本節の経路。デプロイ順序の非交渉事項は
`docs/billing-gate-inversion-runbook.md` が正本。

- **経路 (すべて current org スコープ = route parameter を持たない)**:

  | route | 画面 / 責務 | guard |
  |---|---|---|
  | GET `/onboarding/checkout` (`onboarding.checkout`) | `Onboarding/Checkout` — プラン選択 + Personal(無料)の自己申告 + 資金選択 | `view` 認可 + 離脱ガード (契約済み → `billing.index` / `manageBilling` なし → `onboarding.billing-required`) |
  | GET `/billing-required` (`onboarding.billing-required`) | `Onboarding/BillingRequired` — 未契約 かつ `manageBilling` なし member への説明 (Owner 連絡先 + 問い合わせ導線) | `view` 認可 + 離脱ガード (利用可 → `dashboard` / `manageBilling` 保持 → `onboarding.checkout`) |
  | POST `/onboarding/activate-personal` (`onboarding.activate-personal`) | Personal(無料)の即時有効化 (Stripe Checkout を通らない) | `manageBilling` + `throttle:10,1` |
  | POST `/billing/checkout` (`billing.checkout`) | 有償プランの Stripe Checkout 開始 | `manageBilling` |
  | POST `/billing/plan` (`billing.plan.change`) | 契約中プランの in-app swap (プラン変更) | `manageBilling` |
  | PATCH `/billing/contact` (`billing.contact.update`) | 請求先連絡先の更新 | `manageBilling` |

  いずれも `require-active-subscription` group の**外**にある構造的 allowlist
  (`routes/web.php` の gate group コメントが正本)。ゲート内に入れると
  「契約するための画面が契約していないと見られない」詰みになる。
- **403 ではなく専用画面で受ける**: 権限のない member を 403 で突き放すと行き先のない
  ループになるため、`onboarding.billing-required` を用意する。両画面が相互に離脱ガードを
  持つことで「どちらにも留まれない往復」が構造的に起きない。
- **Personal(無料)の付与は `PersonalPlanService::activate()` が単一の真実源**
  (Controller は呼ぶだけ = 二重付与源を作らない)。適格性不成立は 500 でなく 422。
  完了後は課金ゲートが保存した継続先 (`OnboardingReturnResolver`。same-origin 内部 path のみ)
  へ戻す。**`redirect()->intended()` は使わない** (AGENTS.md 禁止事項 #7)。
- **`?plan=` handoff (P7)**: `IntendedPlanResolver` が org スコープ session へ積み、canonical URL
  へ 303 する (再読込・共有時に query が残らない)。以降は peek = 消費しない (リロード耐性)。
  Enterprise / 未知値は正規化で null に倒れる (Checkout を通らないプランを選ばせない)。
- **`onboarding.checkout` はメール認証済みが前提** (`['auth','verified']` group 配下)。
  未検証メールのまま到達できると `PersonalPlanService::activate()` の無料チケット付与と
  Stripe Checkout の入口が開き、使い捨てアドレスで無料枠を刈れるため、この配置は意図的である
  (`OnboardingCheckoutEmailVerificationGuardTest` が固定)。
  したがって **verify notice 画面 (`Auth/VerifyEmail`) に checkout へ進む CTA は置かない** —
  表示条件 (membership) と踏破条件 (verified) が食い違う恒常的に無効な導線になる
  (bug-hunt F-2-01)。プラン意図の継続は認証**後**に `VerifyEmailResponse` が
  `EmailVerificationContinuation::resolveUrl()` で解決して着地させる。画面へ渡すのは
  URL ではなく `continuesToCheckout` (継続の有無) のみで、認証後の着地を予告する文言に使う。
  認証前にプランを見たい需要は公開面 (`/pricing`) が満たす。
- **契約 Checkout の冪等状態機械 (P9)**: `SubscriptionService::startCheckout()` は
  `attempt_token` 冪等マシン (段 0 事前 assert → 1 既存 subscription guard → 2 同 token 行 →
  3 同 plan の live pending dedup (org-wide) → 4 別 plan の live pending を expire →
  5 Stripe 作成 + DB 記録 → 6 UNIQUE 違反の re-read 収束)。
  - クエリは常に `intent=subscription_start` にスコープする
    (`UNIQUE(organization_id, intent, attempt_token)` の intent 軸が P8a のカード登録
    token 空間と分ける)
  - **同 token・別 plan は 422** (押した plan と違う Checkout に着地させない)。
    **他 org / 他 user の token は Gate より前に 404** (存在オラクル封じ)
  - Stripe idempotency key は `sub_start:{token}` (チケット `purchase:` / カード登録と別空間)
  - live/stale の閾値は `BillingCheckoutSession::staleThresholdAt()` が単一出典で、
    `BillingAccess::state()` / 段 2・3・4 / 日次 sweeper が共有する
    (Architecture テストが literal の再発明を検出)
- **契約中プランの変更 (in-app swap / F-3-01)**: `POST /billing/plan` (`billing.plan.change`) →
  `SubscriptionService::changePlan()`。**有効な subscription を持つ組織専用**の経路で、
  持たない組織の `billing.checkout` と `Subscription::valid()` を境に排他
  (どちらの CTA も `/billing/plans` から出るが、送信先はサーバが決めた
  `hasChangeableSubscription` で分かれる)。
  - guard 順: 契約再読込 → **変更可能 state (Active のみ)** → schedule 管理下の拒否 →
    stale UI 検知 (`current_plan_code`。UX 専用。**要求先 ≠ local 現在プランのときだけ**評価) →
    Stripe swap。
    **`organizations.plan_code` が既に目標プランでも「受付済み」で早期 return しない** —
    この列は webhook 遅延を持つ projection なので、同一プラン判定は
    **gateway の remote 照合に一本化**する (`Applied` / `AlreadyOnTargetPrice` は remote の事実)。
    **state / schedule 判定は最前段**に置く — grace period (解約予約中) の契約は
    `plan_code` が旧プランのまま残るため、後段で「変更できない契約なのに成功扱い」に
    ならないようにする
  - stale 検知の期待値は **`organizations.plan_code` そのもの**
    (`planChangeExpectedPlanCode` prop)。表示用の `currentPlanCode`
    (ActiveFreePlan では `free_plan_code` を返す projection) とは別物で、混ぜると
    grace period 契約で恒常 422 になる
  - Stripe への更新は `proration_behavior=create_prorations` (日割りは**次回請求に反映**。
    `always_invoice` は使わない = 即時請求の与信失敗遷移を持ち込まない)
  - 冪等は 2 層: 同一 render の二重送信は idempotency key `change-plan:{token}:{planCode}`、
    別 render からの再操作は **gateway の remote Price 照合** (`AlreadyOnTargetPrice` =
    update を送らない)
  - **`organizations.plan_code` は書かない**。反映 (projection_synced) は
    `customer.subscription.updated` → `applySubscriptionSnapshot` が唯一の writer
  - Customer Portal の `subscription_update` は **無効のまま** (プラン変更はアプリが所有する)
- **着地 feedback (P9)**: `Inertia::location()` の full page redirect を跨いだ後、
  `/billing` 着地で one-shot バナーを出す (`BillingFeedbackKind`: purchase_received /
  purchase_processing / purchase_already_received / checkout_retry_required / portal_returned)。
  org スコープ + intent 検証で **fail-closed**、UI は raw query を見ない。
  `PurchaseFormState::Completed` 撤去後、**購入完了を伝える唯一の経路**。
  - **one-shot の定義**: 「サーバが同じ状態を再主張しない」こと。着地 query
    (`?session_id` / `?portal`) を認識したら **feedback の有無に関わらず canonical `/billing` へ
    303** で畳み、kind は `BillingFeedbackKind::FLASH_KEY` の session flash
    (次の 1 リクエストのみ生存) で運ぶ。着地 URL が履歴に残らないため、リロード・戻る・
    ブックマークでバナーが復活しない (bfcache による DOM 復元まで禁じる契約ではない)。
    アプリ自身が出す `checkout_retry_required` / `purchase_already_received` は
    query を経由せず発行側で直接 flash する (着地 query を発明しない)。
  - **着地の優先順位** (先着が redirect を返したら後段は評価しない):
    `?setup_session_id` (P8a) → `?session_id` かつ funding=auto_recharge (T1004) →
    `?session_id` / `?portal` (feedback) → 通常 render。
    着地判定は **DTO 構築より前**に置く (`resolveOnboardingContinue()` が return_to を
    消費するため、hop する request で DTO を組むと復帰先を無音で失う)。
  - **副作用境界**: 着地は **GET で DB を書かない** (状態遷移は webhook の管轄)。
    canonical URL の構築は 3 着地共通のヘルパ 1 箇所で、`highlight` のみ引き継ぐ。
    error flash がある着地では feedback を出さず error を次 render へ keep する
    (成功と失敗を同時に出さない)。
- **請求先連絡先 (P9)**: `organizations.billing_contact_email` / `billing_contact_name` は
  両列とも CipherSweet 暗号化 (email のみ blind index。検索は `whereBlind`)。
  `UpdateBillingContactAction` は **email が dirty のときだけ** Stripe 顧客へ同期する。
- **サブスク決済カードの流用 (P9 / T1004)**: `mode=subscription` Checkout が
  `payment_status ∈ {paid, no_payment_required}` で確定し、かつ資金選択が `auto_recharge` の
  ときだけ `ReuseSubscriptionPaymentMethodJob` を dispatch する
  (`pm_reuse_dispatched_at` が dispatch marker)。**webhook 同期処理から外向き Stripe API を
  撃たない**不変条件のため Job へ退避する。`AutoRechargeService::applyReusedPaymentMethod` は
  適格性先行の fail-closed (同意なし・失効・停止状態では Stripe にも DB にも触らない)。

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

## チケット オートリチャージ (P8a) の運用契約

**opt-in・既定 off**。`ticket_auto_recharges` に行が無い組織の課金挙動は完全に不変
(`reserve` の低残高通知も含む)。残高が閾値を割ったら off-session の Stripe Invoice で
自動購入する。

- **経路**: `POST /billing/auto-recharge` (設定更新) / `POST /billing/auto-recharge/setup`
  (カード登録 = Checkout mode=setup)。いずれも current org スコープ + `manageBilling`。
  課金ゲート (`require-active-subscription`) の対象外 = 支払い不健全な組織でも停止・
  カード更新に到達できる
- **トリガ点は `reserve`** (移植元の `commit` ではない)。AI-CUE の実効残高が減る唯一の
  消費イベントは `reserve` で、`commit` は拘束 −amount と台帳 −amount が相殺して balance
  不変のため、`commit` に置くと閾値クロスを取り逃す。`TicketLedgerService::reserve` の
  `DB::afterCommit` で既存の低残高通知と**同居**させる (parity の名で既存通知を削らない)
- **閾値判定・数量確定は `availableTrueBalance()`** (表示用 `balance()` は clamp 済みで、
  判定に使うと返金債務を隠して過剰補充する)。`quantity = min(max_count − 真値残高,
  PURCHASE_MAX_COUNT)` を attempt 作成時に一度だけ確定し、以降 `attempt.quantity` が真実源
- **二重課金防止 3 層**: (1) Stripe idempotency key `auto-recharge:{attempt_ulid}` で
  invoice create / pay が同一 invoice に収束 → (2) partial unique
  `tar_attempts_org_pending_unique` で org あたり pending attempt は同時 1 つ →
  (3) 付与は台帳 `idempotency_key = recharge:{invoiceId}` UNIQUE。加えて **failed / canceled
  への遷移は invoice 終端 (void/delete) 成功後のみ** = open invoice を残して終端しないため
  遅延成功による二重課金が構造的に起きない
- **並行制御**: 全ミューテータ (`updateSettings` / `recordPreConsent` / `applySetupCompletion` /
  `executeAttempt`) が同一ロック `billing:auto-recharge:{orgId}` (TTL 180 秒) を取るため、
  停止後課金と部分適用が構造的に起こらない。`createAttemptLocked` は `reserve` と同順で
  `organizations` 行を `lockForUpdate` する (ロック順序の交差を作らない)
- **SCA (authentication_required) は終端させない**: pending 維持 + 日次リマインダ
  (dedup = JST date bucket)。`pending_expiry_hours` 超過でリコンサイルが failed 終端する
- **再同意 (`reconsentRequiredFor`) は単一述語**: version 改定 ∨ 同意記録欠落 ∨ 上限超過 ∨
  現行カタログ最大請求額 > 同意時金額。UI 表示 / 設定更新 / 自動有効化 / attempt 起票停止の
  **4 箇所で共有**する。同意金額は必ずサーバ再計算 (client hidden の金額は受け取らない)
- **同意文言バージョン (`config('billing.auto_recharge.consent_version')`)**: 提示条件の実質
  (開始残高・補充枚数・上限額の提示形式・停止方法・即時課金可能性・**カードの取得手段**) を
  変える改定では必ず version を上げる。上げると既存同意が自動失効し、再同意まで自動購入が
  止まる (fail-closed)
- **監視対象 (必須項目として登録する)**: **`php artisan billing:reconcile-auto-recharge`
  (scheduler で `*/15 * * * *`・`onOneServer()` + `withoutOverlapping()`)**。
  webhook が `MAX_PROCESSING_ATTEMPTS = 8` で恒久 drop した「課金済み・チケット未付与」を
  回収する**唯一の**経路であり、停止・失敗が続くと資金回収済み・未付与が滞留する。
  失敗は `onFailure` → `report()` で既存の運用アラート経路に載る (routes/console.php)。
  **滞留の観測点**: `ticket_auto_recharge_attempts` の `status='pending'` 件数
  (および `created_at` が `pending_expiry_hours` を超えた行の有無)
- **terminal failure の運用手順**: `stripe_webhook_events.failure_reason` を確認したうえで、
  復旧は手動付与ではなく `billing:reconcile-auto-recharge` の 1 回実行で行う
  (Stripe 上 paid の invoice を検出して `recharge:{invoiceId}` 冪等で付与する)
- **rollback**: 全変更が additive (新テーブル 2 + 列 1 + 新 route/Job/Command)。既定 off の
  ため設定行が存在せず、コード revert で即時復帰できる。pending attempt が残る場合のみ
  revert 前に `billing:reconcile-auto-recharge` を 1 回流して収束させる

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
  **ChecksumSHA256 を署名条件に含む** presigned PUT URL + Crypt 封緘の検証専用チケットを発行
  (封緘/開封は `Capture/UploadTicketCodec` に集約。AEAD で改竄検出し、復号失敗・shape 不正・
  期限切れは null → 呼び出し側が 422 に変換。payload 種別キーで upload チケットと
  download ACK の相互流用を防ぐ)。
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
- **入室時の採用テイク自動 DL (T051)**: `pages/Capture/Show.svelte` が mount 時 (と online 復帰時) に
  `lib/capture/auto-download.ts` の `AdoptedTakeAutoDownloader` を起動し、採用 && ready && 未 DL の
  テイクを順次 `fetch(playback_url, {credentials:"omit"})` で実バイト完読 → 上記 ACK 経路へ送る
  (サーバ変更なし・既存 ACK と同一冪等打刻)。手動 DL ボタンと同一意味。**`downloaded_at` は取得済み・
  同期済みを示す可用性指標であり、端末内保存・オフライン再生・ブラウザキャッシュ残存を保証しない**
  (ワークフロー単位のグローバル同期状態であり端末単位ではない)。将来オフライン再生等で永続保存が
  必要になれば `downloaded_at` を流用せず別状態を設計する。本番 S3 は署名 URL への CORS GET 許可
  (`AllowedMethods` に GET、size 検査を使うなら `Access-Control-Expose-Headers: Content-Length,
  Content-Encoding`) が受け入れ条件 (未公開でも size 検査を自動スキップして degrade 成立)
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
