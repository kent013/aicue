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

### 変更系 route の 3 層 (認証 → テナント境界 → 認可)

状態を変える route (POST/PUT/PATCH/DELETE) は次の 3 層を**この順序で**通る。
順序が本質で、層 2 と層 3 を入れ替えると cross-org が 404 ではなく 403 を返し、
リソースの存在が漏れる (セキュリティ不変条件 2/3)。

```
リクエスト
  ├─ [層1] 認証         auth / auth:api-key,api-oauth
  │                     … ManageRouteAuthGuardTest / ApiGuardAllowlistInvariantTest
  ├─ [層2a] テナント境界 (middleware / routing 層) ★FormRequest より前
  │                     project.in-current-org (web) / api.project-in-org (API) /
  │                     MembershipScopedOrganizationBinder / Route::scopeBindings()
  │                     … ProjectRouteCurrentOrgGuardTest / NestedRouteIdorDefenseTest
  │                                                       ← 不整合は 404
  ├─ [FormRequest] バリデーション (422)  ※層2a より後・層2b より前
  ├─ [層2b] テナント境界 (controller inline = 二重防御)
  │                     resolveOrganizationProject / resolveProjectItem  ← 不整合は 404
  └─ [層3] 認可         Gate::authorize / Gate::forUser(...)->authorize
                        … ControllerAuthorizationGateTest               ← 不足は 403
```

route parameter を経由しない id (POST payload / MCP tool 引数 / token claim / queue payload) は
上記のどの層にも現れないため、**クラス起点の主キー同一性クエリ**を別 gate で deny-by-default に
分類する (`ModelDirectFetchInvariantTest` + `tests/Support/Security/DirectFetchInventory`)。
`NestedRouteIdorDefenseTest` (route parameter 由来) とは母集団が素で交わらない。

- **層 2a が無いと FormRequest の 422 が存在オラクルになる**。inline guard (層 2b) は
  FormRequest より**後**に走るため、「cross-org の実在リソース + 不正 payload = 422 /
  不在リソース = 404」の差分でリソースの実在が漏れる。層 2b は二重防御として残す
- **`ControllerAuthorizationGateTest`** (Architecture) が層 3 を deny-by-default で強制する。
  合格条件は `Gate` ファサード 1 系統のみで、membership binder / `resolve*` 系 /
  `auth`・`verified`・`recent-auth`・`require-active-subscription`・`api-key.ability`
  middleware / `FormRequest::authorize()` は**認可として数えない** (数えると gate が形骸化する)。
  `can:` middleware も受理しない (controller より前に走るため層 2 → 層 3 の順序を壊す)。
  認可を持たない route は `App\Enums\Security\ControllerAuthorizationExemption` +
  30 文字以上の具体的根拠付きで exemption inventory に登録する。
  字句解析は `tests/Support/AuthorizationMarkerScanner` に切り出し、
  解析器自体の positive/negative は `AuthorizationMarkerScannerTest` (Unit) が固定する
- **REST API v1 の認可主体**は `ApiActorContext::$user`。dual guard は通過した guard を
  default に昇格させるため `Auth::user()` は API キー経路で `ApiKey` を返す。
  `Gate::authorize` を使うと Policy の `User $user` 型に対して TypeError = 500 になるので、
  必ず `Gate::forUser($this->apiActor($request)->user)->authorize(...)` を使う

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
  `CUSTOM_BINDER` (explicit binder が入力正規化を担うため pattern を適用しない) /
  `NON_MODEL` / `EXTERNAL`
  (vendor route が持ち込む param を route identity ごとに登録)。
  `CUSTOM_BINDER` の現在の登録は以下 (`RouteBindingCustomBinderDocSyncTest` が
  `RouteBindingTypes::CUSTOM_BINDER` と**双方向**で同期を強制する。マーカーごと消さないこと):
  <!-- CUSTOM_BINDER:BEGIN -->
  - `{organization}` — `MembershipScopedOrganizationBinder`。`{organization:slug}` を併用するため
    数値 pattern を掛けると slug route が全滅する。binder が入力正規化を担う
  - `{passkey}` — `SelfScopedPasskeyBinder`。Fortify (vendor) が登録する route の param で、
    app 側から `Route::pattern` を掛けると vendor の route 定義変更に追随できないため、
    binder が「認証ユーザー所有 + 数値正規化」を担う (**他人の passkey は 404** =
    セキュリティ不変条件 2 の実装点。403 だと存在が漏れる)
  <!-- CUSTOM_BINDER:END -->
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
| `Passkey` | パスキー (WebAuthn credential)。vendor モデル (`Laravel\Passkeys\Passkey`) の app サブクラス。アカウント削除で cascade 削除。契約は [docs/auth-security-mechanisms.md](auth-security-mechanisms.md) §5 | User 従属 |
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
| `Billing/StripeWebhookEvent` | Stripe webhook の冪等マシン (滞留を回収待ちへ置いた理由は `recovery_reason`) | tenant 外 |
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
| `Manual/AnalysisJobService` | AI-CUE: AI 解析の状態機械 (trigger = draft/ready→analyzing + in-flight 冪等 + 残高事前チェック / failJob = 行ロック + terminal guard の冪等失敗確定 / failStaleJob = 滞留回収の 1 件処理の口 (行ロック下で滞留の述語ごと再評価する)) |
| `Manual/AnalysisPipeline` | AI-CUE: 解析パイプライン本体 (extract→decompose→generate→terminal tx)。チケット 2 フェーズ (予約冪等キー = analysis_jobs.ticket_reservation_id、materialize + commit + succeeded を単一 tx で原子化)。LLM 出力の有界リトライ (JSON 検証失敗のみ最大 2 回) |
| `Manual/SopTextExtractor` | AI-CUE: SOP テキスト抽出 (pdf = smalot/pdfparser / xlsx·xls = phpoffice/phpspreadsheet / txt)。UTF-8 strict 検証 + **SJIS 誤解釈 (pdfparser が定義済み CJK CMap 非対応のため CP932 を Windows-1252 として decode する) の区間単位復元** (**復元は日本語本文ゲートで拒否される文書にのみ適用する**。既に日本語として読める文書は 1 バイトも変更しない = 正当なテキストの不変性を構造で保証する。区間の採否は CP1252 可逆性 / SJIS-win 妥当性 / 全角日本語が 2 文字以上増える / 区間の過半数が日本語、の 4 条件をすべて満たすこと) + **日本語本文ゲート** (`manual.analysis_min_japanese_ratio` 未満は LLM に渡さず insufficientJapaneseText。評価対象は**正規化後・空白を除いた文字数**に占める日本語文字の比率。**閾値の変更は TODO 起票 + 実測の再提出を必須とする**) + UTF-8 バイト上限 (token budget 導出。AnalysisTokenBudgetInvariantTest が算術を固定)。0 バイトは媒体で弁別する (pdf = unextractable / plain・spreadsheet = tooShort) |
| `Manual/RenderJobService` | AI-CUE: レンダの状態機械 (trigger = ready→rendering + render 冪等 + 採用テイク/尺/残高 guard / triggerPreview = Organization 行ロックで org 同時 preview 上限を直列化 / failJob = 冪等失敗確定 / completeRenderIntoLockedManual = ロック済み前提メソッド / failStaleJob = 滞留回収の 1 件処理の口 / reconcileOutputs = 出力世代の収束) |
| `Manual/RenderPipeline` | AI-CUE: レンダパイプライン本体 (startJob→buildManifest→compose→upload→finalize)。チケット 2 フェーズ (予約冪等キー = render_jobs.ticket_reservation_id、complete + commit + succeeded を terminal tx で原子化)。version スナップショット固定 (§10.8-6) |
| `Manual/CutSequencer` | AI-CUE: カット表示順 (step→配下 point) と表示ラベル (手順N/急所N-M) の導出 (読み取り専用) |
| `Manual/CurrentRenderArtifact` | AI-CUE: 「いま受け取れるレンダ成果物はどれか」の唯一の選択式 (読み取り専用)。playback / download / 詳細画面 props の 3 消費者が同じ行を指す。保持ポリシーと同じ世代定義 (最新 succeeded の output_path が NULL なら旧世代へフォールバックしない)。§完成レンダ成果物の選択と受け取り口 |
| `Manual/ScenarioBookendBuilder` | AI-CUE: AI 生成シナリオの前後へ導入/総括カットを決定的に付与する純関数 (DB/ロックに触れない。呼び出しは `AnalysisPipeline::finalize` の terminal tx 内。総括の要点再掲は**今回生成の steps からのみ**抽出 = 再生成時に旧シナリオを総括しない) |
| `Render/VideoComposer` (interface) + `Render/FfmpegVideoComposer` | AI-CUE: 動画合成の抽象 + ffmpeg v1 実装 (Process facade 経由・配列引数。filtergraph にはサーバ生成一時ファイル名と数値のみ = 字幕本文を直接埋めない) |
| `Render/AssSubtitleWriter` | AI-CUE: ASS 字幕生成の安全境界 (唯一の字幕テキスト出力点。リテラル \N/override tag/制御文字/zero-width の正規化 + mb 安全な長さ上限) |
| `Render/RenderObjectStorage` | AI-CUE: レンダ出力 S3 操作の集約点 (download/upload/署名 URL/削除/prefix。DL 用 Content-Disposition は RFC 5987 + ASCII fallback + ヘッダ注入不能) |
| `Auth/SocialAccountService` | ソーシャルログイン連携。SSO 登録時の `email_verified_at` は `Auth/EmailTrust/EmailTrustPolicyResolver` (provider ごとの `email_trust` 宣言) 経由でのみ立てる (nOAuth 対策。契約は [docs/auth-security-mechanisms.md](auth-security-mechanisms.md) §4)。**SSO 登録は password を持たない** (`hasPassword()` が fail-closed で判定できるようにする。前方修正のみ = 既存ユーザーの phantom password は遡及是正しない。[docs/template-divergence.md](template-divergence.md) D13) |
| `Auth/LoginMethodInventory` | 「ログイン画面から本人がアカウントに入れる手段」の投影後集合。`EnsureLoginMethodRemains` が唯一の呼び出し点 (行ロック下で評価する契約) |
| `Auth/PasskeyLoginPolicy` | passkey **ログイン**可否の単一判定点 (feature flag + TOTP)。vendor の login ゲート / inventory / UI prop が共有する |
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
> 対象 VideoManual 行を `lockForUpdate()` で取得した同一トランザクション内で反映する
> (= 更新経路)。対象行がまだ存在しない生成経路 (新規 INSERT) は、所有元 Project 行を
> `lockForUpdate()` した同一トランザクション内で INSERT し、初期状態
> (`status` / `scenario_version`) を明示代入する (DB カラム default に依存しない)。**

- **更新経路**の直列化点は VideoManual 行 (Project 行はロックしない。カテゴリ等 project 集合との
  整合はシナリオ書き込みに無関係のため、直列化粒度を manual に意図的に絞る)。
  親 relation 経由の再解決 (`$project->manuals()->whereKey(...)->lockForUpdate()`) で
  「子は親に属する」も同時に担保する
- **生成経路**は対象 VideoManual 行が未存在のため、所有元 Project 行を `lockForUpdate()` した
  同一 tx 内で INSERT する。**免除されるのはその tx が生成した新規行の初期値
  (`status` / `scenario_version`) の INSERT のみ**であり、生成後の行に対する後続の書き込みは
  更新経路として扱う — `duplicate()` の cuts materialize は、保存した新 manual を
  `lockForUpdate()` で**再取得してから**行う (`copyCuts` の呼び出し前提)
- 準拠実装 (下表は**メソッド粒度で記録する経路 inventory**。ただし
  `ScenarioWritePathInventoryTest` (Architecture テストへ昇格済み) の**機械検証は
  deny-by-default の token 走査 = ファイル粒度**に留まり、表の粒度と一致しない。
  同一ファイル内のメソッド追加は検出しない):

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
  | `VideoManualService::create()` | **生成経路**。status=Draft / scenario_version=0 を新規 manual の INSERT 時に明示代入 (DB default 非依存 = 戻り値インスタンスが hydrate 済みになる)。Project 行 lockForUpdate 済み tx 内の新規 INSERT で、既存行への並行書き込みではない |
  | `VideoManualService::duplicate()` | **生成経路**。cuts (別名保存。元 manual を lockForUpdate して一貫読み取り、cuts は lockForUpdate 済みの**新** manual 経由で作成) + status=Draft / scenario_version=0 の明示代入。adopted_take_id は複製しない (検出 4 は非対象) |

  生成経路 (`create()` / `duplicate()`) の allowlist は**ファイル粒度**であり、
  `ScenarioWritePathInventoryTest` は「VideoManualService.php が status/scenario_version を書く」
  ことまでしか固定しない。**個々のメソッドが初期状態を明示代入していることの fail-first は
  `tests/Feature/Projects/ManualServiceBoundaryTest.php` /
  `tests/Feature/Projects/ManualDuplicateTest.php` の behavioral テストが担う。**

  テイク採用 API は inventory 準拠へ昇格済み (検出 4 = `adopted_take_id` の token 走査 +
  書き込み形検出)。RenderJob の状態遷移も inventory 準拠済み (検出 5 =
  `completeRenderIntoLockedManual` の宣言/呼び出し限定)
- 状態 guard (rendering/analyzing 中の保存は 409) は第一防衛、共有行ロックは
  「job 側の書き込みと保存が絶対に交差しない」ための構造的防衛 (二重防御)

### キュー投入の原子性

**業務状態の保存とキュー投入は同一トランザクション内で行う** (裁定 AG-114 確定 1 / 到達基準 AG-126 /
除外基準 AG-127。設計は `devnotes/20260809-0027-queue-dispatch-atomicity/`)。
旧実装は commit 後に dispatch していたため、その間にプロセスが落ちると
`RunManualAnalysis` / `RunManualRender` / `DeleteRenderOutputsJob` / `DeleteTakeObjectsJob` /
Stripe webhook 由来の 2 ジョブが「保存済み・未投入」のまま残った。
滞留回収は**再投入ではなく失敗確定へ倒す**ため、ユーザーの再実行なしには前進しない。

1. **0 件 pin (commit 後ずらしの機構を使わない)** — 次の 6 種は
   `QueueDispatchAtomicityInventoryTest` が deny-by-default で **0 件**に固定する。
   **allow-list は持たない** (case を 1 つも持たない免除 enum は死んだ機構になるため、
   除外が必要になった時点で免除機構ごと設計し直す)。

   | # | 迂回路 | 検出方法 | 母集団 |
   |---|---|---|---|
   | D1 | `->afterCommit()` | token 走査 | `app` / `routes` / `bootstrap` / `database` / `config` |
   | D2 | 静的 `::afterCommit()` 全般 (`DB::afterCommit()` を含む) | token 走査 | 同上 |
   | D3 | `ShouldQueueAfterCommit` / `ShouldHandleEventsAfterCommit` | リフレクション | `ShouldQueue` 実装 ∪ Mailable subclass |
   | D4 | config の `after_commit => true` (sync 以外) | config 走査 | `queue.connections` 全件 |
   | D5 | `$afterCommit` の truthy な既定値 / promoted parameter / truthy な単一リテラル代入 | リフレクション + token 走査 | D3 と同じ / D1 と同じ |
   | D6 | `ShouldDispatchAfterCommit` (event 側) | リフレクション | `app/` の**全クラス** |

   - **D3 / D5 の母集団に Mailable を足す**のは、Mailable が `ShouldQueue` なしでも
     `Mail::to(...)->queue()` でキューに載り、vendor の `SendQueuedMailable::__construct()` が
     `$afterCommit` を wrapper job へコピーするため (本リポジトリは `CreateInquiryAction` が
     現に `Mail::to(...)->queue(...)` を使っている)。
   - **D6 が要る**のは、`Events\Dispatcher::dispatch()` が `ShouldDispatchAfterCommit` を見て
     **イベント発火そのもの**を commit 後へ回すためである (queued listener がぶら下がっていれば
     enqueue も commit 後になる)。event には marker interface が無く母集団を静的に絞れないので、
     `app/` の全クラスという**超集合**を deny-by-default で見る。
   - **`ShouldHandleEventsAfterCommit` も D3 で見る**のは、
     `Events\Dispatcher::handlerShouldBeDispatchedAfterDatabaseTransactions()` が
     `ShouldQueueAfterCommit` ではなくこちらを見るためである (ShouldQueue な listener では
     これが**キュー投入そのもの**を commit 後へずらす)。
   - **token 走査である理由**: 素の文字列 grep にすると、契約の反転 docblock が旧主張として
     `->afterCommit()` を引用した瞬間に gate が自壊する。
   - **D5 の判定は vendor と同じ真偽値文脈**である (`Queue::shouldDispatchAfterCommit()` は
     `isset($job->afterCommit)` で拾った値をそのまま真偽値評価する)。`1` のような truthy 値も違反、
     `null` / `false` / `0` は違反にしない。**promoted parameter は既定値に依らず違反**とする
     (呼び出し側が `new Job(afterCommit: true)` で任意に渡せるため)。

2. **起動時 fail-closed 検査** — `QueueDispatchAtomicityGuard` が
   `AppServiceProvider::boot()` から**全環境で**走る (production 限定ではない —
   R4 はテスト・dev でこそ意味を持つため `ProductionEnvGuard` には相乗りしない)。

   | 規則 | 内容 |
   |---|---|
   | R1 | 参照接続 (既定接続 ∪ pin 済み 3 接続) の driver は `database` |
   | R2 | 同接続の DB 接続は業務 DB と同一 (`connection` が null = 既定 DB は許可) |
   | R3 | 同接続の `after_commit` は `false` を明示 (キー欠落も違反) |
   | R4 | `sync` 接続は driver=sync かつ `after_commit=true` |
   | R5 | production の既定接続の driver は `database` (sync の本番投入を拒否) |

   - **sync の除外は driver ではなく接続「名」で判定する**。driver で除外すると
     `database-analysis.driver = sync` にした構成が R1〜R3 を丸ごと skip して通ってしまう。
   - **pin 済み接続集合の drift** は `QueuedJobLeaseInventoryTest` の対称差テストが閉じる
     (guard 単体では閉じない)。
   - `Bus::batch` / `Bus::chain` は `app/` に 0 件のため束台帳の検査は持たない
     (導入時は `config('queue.batching')` の接続一致検査を guard に足すこと)。

3. **`config/queue.php` の `sync` は `after_commit => true` が必須** — これが無いと
   tx 内 dispatch がテストレーンで即時インライン実行され、pipeline の `startJob`
   (lockForUpdate + `status===queued`) が**自分自身のロック下で成立**してしまう。
   `after_commit=true` の sync では「業務 tx の commit 直後・テスト tx の内側でインライン実行」
   となり、本番の「commit 後に worker が拾う」と同じ順序意味論になる。

4. **検証方法** — **`Queue::fake()` では原子性を検証できない**
   (`QueueFake::push()` は `enqueueUsing` を通らず、after_commit の解決も観測点も素通りする)。
   原子性の検証は `queue.default='database'` + 実 `jobs` 表 +
   `JobQueueing` の `DB::transactionLevel()` 観測 (`RecordsJobQueueingTransactionLevel`) で行う。
   判定は **action 直前の level (baseline) + 1 以上**であり、固定値では判定しない。
   **rollback テストは移設を検出しない** — 旧実装でもテストが外側 tx で包めば jobs 行は
   rollback で消えるため、主契約は tx level 観測だけである。

5. **入口排他との関係** — `AutoRechargeTriggerJob` から `ShouldBeUnique` を撤去した。
   `UniqueLock` は dispatch 呼び出し時に取得され、rollback 時の解放は afterCommit 経路でしか
   行われないため、業務 tx の内側で dispatch すると rollback しても `uniqueFor` 秒の抑止が残る
   (ネスト深さに依らず解消できない)。一回性は永続状態遷移が担う (§ジョブの重複実行と結果の一回性)。

6. **保証しないもの (誇張しない)**
   - 消えるのは「業務状態を commit したのにキューへ投入されない」窓**だけ**である。
     commit 前の障害は両方 rollback し (不整合ではない)、commit 後に jobs 行が残っても
     **worker がそれを処理することは保証しない**。
   - guard は **config の値だけ**を見る。`connection` 名の一致は「同一トランザクションに乗る」
     ことの**代理検査**にすぎず、別 PDO / connection resolver 差し替え / 同名で別サーバを指す
     構成は検査しない。
   - **「dispatch が業務 tx の内側にあること」の静的完全性は保証しない**。gate が固定するのは
     「commit 後ずらしの機構を使っていないこと」までで、tx 外に置かれた新しい dispatch は
     gate に映らない (既知経路は behavioral テストが経路ごとに固定する)。
   - D1/D2/D5(代入) は token 走査なので、動的な迂回 (`$m = 'afterCommit'; $job->$m();` /
     `$this->afterCommit = $flag;` / `= 1 + 1` のような式 / helper 経由) には沈黙する
     (D5 の代入は**単一リテラルの代入だけ**を真偽値評価する。基数付き数値と数値区切りは扱うが、
     **エスケープを含む文字列リテラルは評価不能に倒して検出しない**)。
   - **低残高通知は原子的でない** (at-most-once = 既定仕様。§アプリ内通知の配信保証)。

### キューのリース期間とワーカー制限時間の規約

DB driver のキューには**実行中にリース (`retry_after`) を延長する API が無い**ため、
「まだ走っている処理を落ちたと誤認させない」手段は設定の大小関係を保つことだけである。
リースが切れると、まだ走っているジョブが**別のワーカーへ再配布される** (二重実行)。
2 本の規則を**互いに独立に**満たす (両者のあいだに大小関係は課さない)。

- **規則 1 (無条件)**: その接続で有効なワーカー / supervisor の `--timeout` が、
  その接続の `retry_after` を**下回る**。1 つのワーカーは同じ接続の複数種類のジョブを
  処理するため、`$timeout` を持つジョブが 1 本あっても免除されない。
- **規則 2**: その接続で動くジョブの明示的な `$timeout` が、その接続の `retry_after` を下回る。

| 接続 | `retry_after` | ワーカー `--timeout` | 備考 |
|---|---|---|---|
| `database` | 360 | **300** | 外部予算 200s (Stripe 20s × 呼び出し予算 10 回) + 局所予算 90s = 290 < 300 (T126)。§外部 SDK の待ち上限の規約 |
| `database-analysis` | 1680 | **1620** | ジョブ側 `$timeout` 1,560 を上回る帯 |
| `database-render` | 1680 | **1620** | ジョブ側 `$timeout` 1,500 を上回る帯 |
| `database-media` | 300 | **240** | 削除は冪等 + `$tries=3` なので kill されても再配布で完了する |

**本番/ステージングの supervisor 定義にもこの `--timeout` を必ず設定する**
(リポジトリ外にあるため CI は検知しない。上表が正本)。

- `driver=database` の接続は **dev ワーカーペイン (`mprocs.yaml`) を必ず持つ**。
  接続だけ増やしてワーカーを足し忘れるとジョブが黙って滞留する。
- 静的検査: `tests/Architecture/QueueWorkerLeaseInvariantTest.php` (規則 1。
  `mprocs.yaml` と `scripts/bug-hunt-shard.sh` の両方) /
  `tests/Architecture/QueuedJobLeaseInventoryTest.php` (規則 2 + キューに載る全クラスの
  接続目録を deny-by-default で固定)。ワーカー timeout 到達時の遷移は
  `tests/Feature/Queue/WorkerTimeoutTransitionTest.php` が behavioral に固定する。
- **`queue:listen` ではジョブ側 `$timeout` が効かない**
  (`Listener` が子 `queue:work --once` へ `--timeout` を渡さず、`Worker::runNextJob()` は
  SIGALRM を張らない)。dev / bug-hunt では `--timeout` が唯一の上限であり、
  到達すると listener 本体も終了する。**Laravel のメジャー更新時はこの前提を再確認する**
  (前提が変わると規則 1 の重要度そのものが変わる)。
- `database` の `retry_after` は **env で上書きできないリテラル**で持つ
  (静的 gate は config をテスト環境の値で読むため、env 上書きを残すと
  「gate は通るが本番の実値は別」を作れてしまう)。

### ジョブの重複実行と結果の一回性

キューは at-least-once であり、上のリース規約を守っても**二重実行そのものは無くならない**
(worker 停止・再開、リース切れ、cron による stale 回復)。したがって守るのは「実行が 1 回」ではなく
**「結果が 1 回」**である (裁定 AG-082 の追従。設計は
`devnotes/20260807-1235-job-execution-dedup/`)。

1. **2 層の役割** — 入口の排他 (`ShouldBeUnique` / `Cache::lock`) は **best-effort** であり、
   保証を担わない (鍵は失敗・timeout で解放されないことがあり、TTL でも切れる)。
   結果の一回性は**永続状態遷移** (条件付き UPDATE / 悲観ロック + status guard / 予約 CAS) と
   **外部側の冪等性** (Stripe idempotency key / invoice の状態検査) が担う。
   **preflight** (外部呼び出し直前の所有権再検証) は「既に失われた所有権を検出して送信を止める」
   **抑止策**であって保証ではない。
2. **所有権の定義** — **(行の主キー, 進行中 status)**。`AnalysisJob` / `RenderJob` /
   `TicketAutoRechargeAttempt` はいずれも単調な状態機械で、再実行は**新しい行を起票する**ため、
   `status` の再読込がそのまま所有権の再検証になる (claim token 列を持たない根拠)。
   行が消えている場合も所有権喪失として扱う (deny-by-default)。
3. **preflight の配置規則** — **外部呼び出しの直前**に置く。再検証と外部呼び出しの間に
   **自前の書き込みを挟まない**。挟んだ場合は書き込みの**後**に再度置く
   (auto-recharge は `invoice_id` の永続化を挟むため create 前と pay 前の 2 箇所)。
4. **終端後のジョブ状態・進捗書き込みの禁止** — preflight を置いた経路では、terminal 化された後に
   旧ワーカーが自前の書き込みを行う経路も同時に塞ぐ。**ジョブ行**への進捗書き込み
   (`step` / `progress` / `result_json` / `stripe_invoice_id`) は `where status=…` の
   **条件付き UPDATE** にする (「failed なのに progress=65」を作らない。副次的に `updated_at` の
   更新も止まるため stale 判定の基準が terminal 行で動かない)。
   **対象はジョブ行に限る** — `SourceDocument::extracted_json` のような write-only の
   監査スナップショットは状態機械の一部ではないため対象外である。
5. **auto-recharge の保証層** (課金は最も高価なので 4 層で持つ):

   | 層 | 機構 | 何を保証するか |
   |---|---|---|
   | 入口 | org `Cache::lock` (TTL 180s) | best-effort の直列化のみ (T137 で `AutoRechargeTriggerJob` の `ShouldBeUnique` は撤去。§キュー投入の原子性) |
   | 起票 | `tar_attempts_org_pending_unique` (partial unique) | org に pending は 1 つまで |
   | 遷移 | `where status='pending'` の条件付き UPDATE | 1 attempt = 1 遷移 |
   | 効果 | 台帳 `recharge:{invoiceId}` の UNIQUE + Stripe idempotency key | 付与と課金の一回性 |

   **冪等キーは 2 本ある**: 付与の一回性は台帳の `recharge:{invoiceId}` (**invoice 単位**)、
   attempt 遷移の一回性は条件付き UPDATE (**attempt 単位**)。`recordSuccessfulCharge()` が
   「grant → attempt 遷移」の順なのはこのためで、**逆順にしない**
   (逆順は「Stripe で課金済みなのにチケット未付与」というより悪い不整合を生む)。
6. **閉じない窓 (受容済み)** —
   (a) **送信権の競合**: preflight 通過から送信までの間に terminal 化されうる。
   (b) **送信結果の不明**: 送信直後にプロセスが死ぬと結果が分からない (S3 PUT / Stripe pay 同型)。
   (c) **LLM に冪等キーが無い**: provider 側で重複排除できない (だから preflight を置く)。
   (d) **`queue:listen` ではジョブ側 `$timeout` が効かない** (dev / bug-hunt)。
7. **序列** — `LOCK_TTL_SECONDS` < 既定接続の `retry_after`
   (鍵の残留が正当な再実行を封鎖する時間を、キューの再配送間隔の内側に収める)。
   `uniqueFor` の系統は T137 で撤去済み (`ShouldBeUnique` の unique lock は業務 tx 内 dispatch と
   両立しない — dispatch 時に取得され rollback で解放されないため)。
   ジョブ側 `$timeout` < `retry_after` < 予約 TTL ≤ stale 閾値 (上節)。
   成立前提は「pcntl 有効 / 遅延なし / 時計ずれが小さい / シグナル順序 / supervisor 設定」。
8. **運用契約 (所有者 = 課金運用担当)** —
   - `event = job_ownership_lost` の**連続発生**は「ワーカーの停止・再開が多い」または
     「序列の前提が崩れた」の兆候。頻度を監視する。
   - **恒久回収を持たない open invoice が 2 種ある**。どちらも `reconcile()` は
     DB の pending attempt を走査するため**母集団外**であり、手動収束が必要。
     **検知元がそれぞれ違う**ので分けて書く:

     | # | 発生条件 | 検知元 | 収束手順 |
     |---|---|---|---|
     | (a) | 所有権喪失後の void / delete に失敗した | **アプリログ**: `event = job_ownership_lost_cleanup` かつ `terminated=false` (原因の分類は同ログの **`failure_class`** = `GatewayFailureClass`、**`error_class`** = 例外クラス名。**成功時も両キーは `null` で存在する** (集計 schema を成否で割らない)。`report()` 側にも invoice id とこの 2 値だけを持つサニタイズ済み例外しか流れないため、**この cleanup 経路で本サービスが出す構造化ログと report message には Stripe が生成した原メッセージが残らない** (`report()` の stack trace / vendor 側の別ログ / 伝播した queue failure は本保証の範囲外)。`tryTerminateInvoice()` / `reconcile()` も同じ 2 キーへ統一済み。詳細が要るときは `invoice_id` で Stripe 側を直接確認する) | 同ログの `invoice_id` を Stripe で確認し、`paid` でなければ手動 void |
     | (b) | invoice 作成成功 → `stripe_invoice_id` の永続化前にワーカーが死亡した | **アプリログには何も残らない**。Stripe 側を起点に探す — metadata `purpose=auto_recharge` を持つ `draft` / `open` invoice を列挙し、その `recharge_attempt_ulid` に対応する `ticket_auto_recharge_attempts` 行の `stripe_invoice_id` が **NULL または別 id** のものが孤児 | **原則すべて手動終端の対象**とする。`paid` でないことを確認して void / delete する |

     > **(b) を「次の実行が拾うから放置してよい」と書かない** — Stripe の idempotency key は
     > **保持期間 (数十時間程度) を過ぎると再実行で別の invoice が作られる**。
     > 期限の無い状態検査で冪等化されている `terminateInvoice()` とは性質が違う。
     > 例外的に一時保留してよいのは「保持期間内であることが確認でき、かつ再実行が確実に
     > 予定されている」場合だけで、その場合も**再実行後に DB の `stripe_invoice_id` と
     > 一致しない旧 invoice は終端する**。長期間残った pending attempt に対して
     > 「収束するはず」という偽の安全性を持たせないこと。

     どちらも Stripe metadata の `recharge_attempt_ulid` から attempt を逆引きできる
     (`metadataFor()` が全 invoice に付与している)。
     照合は**課金運用担当が定期的に行う** (自動化は母集団が Stripe 側にあるため
     本節のスコープ外。必要になったら独立の TODO として起票する)。

**規約 ↔ テスト対応表** (AGENTS.md 禁止事項 1 = 不変条件はテスト登録まで含めて「実装済み」):

| 規約の文 | 保証するテスト |
|---|---|
| キューに載る全クラスが保証側 or 免除に分類される | `JobExecutionDedupInventoryTest` |
| 登録された**すべての** preflight checkpoint が実在し、制御方式 (`PreflightControlFlow`) に一致する戻り型を持つ (**存在まで**) | `JobExecutionDedupInventoryTest` |
| 期待する外部呼び出し種別 (`jobDedupRequiredExternalCalls()` が正本) と checkpoint 登録の集合一致 / `NoExternalCall` と混在しない | `JobExecutionDedupInventoryTest` |
| preflight が**外部呼び出しの直前に置かれている** (配置) | `AnalysisPipelineTest` / `RenderPipelineTest` / `AutoRechargeServiceTest`。★**分担**: Architecture gate = 集合一致 + 実在 + 戻り型 / Feature テスト = 配置。Manual は既存 fake のフック (`onAttempt` / `duringCompose`)、**Billing は注入可能な `FakeAttemptOwnershipPreflight`** (競合注入シーム) で配置を赤化する |
| 終端後にジョブ行の進捗を書き戻さない (条件付き UPDATE) | `AnalysisPipelineTest` / `RenderPipelineTest` |
| 終端後に `stripe_invoice_id` を書き込まない (条件付き UPDATE) | `AutoRechargeServiceTest` |
| 同一 invoice への付与は台帳に 1 件しか入らない | `AutoRechargeServiceTest` |
| 免除は型付き enum + 30 文字以上の根拠 / 件数は宣言と一致 | `JobExecutionDedupInventoryTest` + value object の `Assert` |
| 入口の排他 TTL / `uniqueFor` < `retry_after` | `JobExclusionOrderingInvariantTest` |
| `$timeout < retry_after < 予約 TTL ≤ stale 閾値` | `AnalysisTimeBudgetInvariantTest` / `RenderTimeBudgetInvariantTest` |
| worker `--timeout` < `retry_after` | `QueueWorkerLeaseInvariantTest` |
| 所有権喪失時に LLM を呼ばない | `AnalysisPipelineTest` |
| 所有権喪失時に S3 PUT しない | `RenderPipelineTest` |
| 所有権喪失時に invoice 作成・支払いを抑止し、必要な既作成 invoice を終端する | `AutoRechargeServiceTest` |
| ログコンテキストに PII を含めない | `JobOwnershipLostContextTest` |
| 固定 event 名の literal が 1 箇所に閉じる | `JobExecutionDedupInventoryTest` |
| gateway を注入されるクラスが観測目録 or 免除に分類される / vendor 例外が全件分類される / `unknown` が写像表の値に現れない / fake の失敗注入が本物と同じ分類になる | `BillingGatewayFailureTaxonomyInventoryTest` |
| 分類器の写像・境界 (`UnknownApiErrorException` の HTTP status) ・`context()` の array shape | `GatewayFailureClassifierTest` |
| 失敗分類が実際にログへ載る / 成功時も null で存在する / 制御フローが変わらない | `AutoRechargeServiceTest` / `AutoRechargeReconcileTest` |

### オートリチャージの失敗分類

決済 gateway (`AutoRechargeGatewayInterface`) の消費経路で捕まえた例外は、
`App\Support\Billing\GatewayFailureClassifier` が**有界な語彙**へ写してからログに載せる。
**分類は観測のためであり、制御フローを変えない** (課金の振る舞いは分類の導入前後で同一)。

| `failure_class` | 意味 | 運用担当が取る行動 |
|---|---|---|
| `provider_unavailable` | 決済事業者側の一時的な不能 (接続断・レート制限・5xx) | 同じ要求の再送で収束しうる。頻度を監視する |
| `provider_rejected` | 決済事業者が要求を受理しなかった (400/401/402/403 等) | 再送しても収束しない。要求内容・認証情報・利用者操作を確認する |
| `invariant_violation` | アプリ自身が検出した不変条件違反 (Assert / SDK・Cashier の誤用) | **アプリの欠陥**。コードを直す |
| `local_failure` | 自インフラ層 (DB / cache) の失敗 | インフラを確認する |
| `unknown` | **写像表に一致が無かった** | 下記「`unknown` の運用契約」 |

ログに載るのは `failure_class` と `error_class` (例外クラス名) の **2 キーだけ**である。
**例外 message は載せない** (外部サービスが生成する可変文字列であり、
構造化ログの集計語彙にしない)。

**`unknown` の運用契約 (所有者 = 課金運用担当)**

- **検知条件**: `failure_class = unknown` を含む warning が 1 件でも出たら検知とみなす
  (`unknown` は「分類器に欠落がある」という通知そのものであり、正常状態では出ない)。
- **初動**: 同ログの `error_class` を見て、そのクラスを
  `GatewayFailureClassifier::directMap()` (または条件付き規則) へ追加し、
  `GatewayFailureClassifierTest` の期待値表にも**独立に**書く。
  **`unknown` を写像表の値として書いてはならない** (gate が機械的に禁止する)。
- vendor 由来のクラスなら `BillingGatewayFailureTaxonomyInventoryTest` の検査 9 が
  同時に赤くなっているはずなので、CI の赤と突き合わせる。

**vendor 更新 (`composer update`) で gate が赤くなったときの手順**

`BillingGatewayFailureTaxonomyInventoryTest` は stripe-php / cashier の例外クラス集合を走査し
「写像表 == 実在クラス集合」を要求する。**依存更新で赤くなるのは意図した費用**であり
(外部の語彙が増えたことを人間に必ず知らせるための仕掛け)、soft-fail 化しない。

1. 検査 9 の失敗メッセージが挙げるクラス名を確認する。
2. 増えたクラスは vendor の throw site を読んで**行動で切る**分類を決め、
   `GatewayFailureClassifier::directMap()` と `GatewayFailureClassifierTest` の期待値表の
   **両方**へ追加する (二重宣言なのは、片方だけでは写像を間違えても green になるため)。
   HTTP status 等の条件で分岐が要るものだけ `conditionalClasses()` 側へ置く。
3. 消えたクラスは両方から削除する。
4. 検査 13 (a) が赤い場合は SDK が**サブ名前空間を増減させた**ことを意味する。
   `VendorExceptionPopulation::EXCLUDED_STRIPE_SUBNAMESPACES` に
   30 文字以上の根拠付きで宣言するか、母集団定義そのものを再検討する。

**Stripe 例外型を知ってよいクラス**は gateway 実装 3 本
(`CashierStripeGateway` / `CashierAutoRechargeGateway` / `StripeScheduleGateway`) と
`GatewayFailureClassifier` の**計 4 つに閉じる** (検査 19 が allowlist で固定。
走査は PHP 同梱の tokenizer で行い、`use` 文だけでなく完全修飾名・文字列リテラルも拾う。
コメント / docblock での言及は対象外)。
集約点が増えると観測語彙が割れるため、新しい観測点を作らず分類器を使うこと。

**免除 (`GatewayFailureObservationExemption::PropagatesToQueueFailure`) の前提**は
検査 21 が behavioral に固定する: 免除宣言したクラスは `catch` 節を **1 つも持たない**
(tokenizer の `T_CATCH` 計数。コメント中の記述には反応しない)。
件数と根拠長だけを見る gate では、後から `catch (Throwable)` を足して `getMessage()` を
ログへ載せても green のままになるため (`ThrottleExemptionPremiseTest` と同じ作法)。
catch を足す必要が出たら、観測目録へ移すか免除の分類を見直すこと。

### AI 解析ジョブの運用契約

- 解析ジョブ (`RunManualAnalysis`) は専用 queue connection **`database-analysis`**
  (queue=analysis、retry_after=1680) で流れる。**本番/ステージングの worker プロセス定義・
  デプロイ手順・監視対象に `php artisan queue:work database-analysis --timeout=1620` を
  必須項目として登録する** (`--timeout` は規則 1。§キューのリース期間とワーカー制限時間の規約)
  (専用 worker が居ないとジョブは滞留する。queued 滞留は滞留回収
  (`work:recover-stuck --stream=analysis_job --apply`) が 30 分で失敗確定するため、滞留 = 監視で気づける)
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
  デプロイ手順・監視対象に `php artisan queue:work database-render --timeout=1620` を
  必須項目として登録する** (`--timeout` は規則 1。§キューのリース期間とワーカー制限時間の規約)
  (専用 worker が居ないとジョブは滞留する。queued 滞留は滞留回収
  (`work:recover-stuck --stream=render_job --apply`) が **10 分** (queued 短 SLA。enqueue 時点で
  編集を止めるため) / running 滞留は **30 分** で失敗確定するため、滞留 = 監視で気づける)
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

#### 採用テイク充足判定の単一化と告知契約 (T148)

「採用済みかつ ready のテイクを持つか」の判定は **`Services/Manual/AdoptedReadyTakeCoverage`
の 1 ファイルだけ**が持つ。以前は同じ式が `RenderJobService` と `RenderPipeline` に複製され、
**preview のトリガーには存在しなかった**ため、完成動画生成が 422 でブロックする状態を
プレビューは黙って通し、全編黒画面の動画を警告なしで出していた (bug-hunt F-1-01)。

- **述語はカット単位で切り出す** (`AdoptedReadyTakeCoverage::isMissing(Cut)`)。集計 API だけを
  共有すると manifest 側 (Placeholder 分岐) で式が再実装されるため、**3 消費者
  (render の 422 / 詳細画面 props / manifest の Placeholder 分岐) がすべて同じ述語を通る**。
- **制裁だけが非対称で、基準は同じ**である。render は 422 でブロックする (標準化された成果物の
  完全性)。preview は**ブロックしない** — 未撮影は制作途中の正常な状態であり、preview は
  チケット非消費で manual status も触らない「途中経過を見る」機能だからである。
  代わりに詳細画面 props (`render.coverage`) が**押す前に**同じ件数を告知する。
  **必須条件未充足を理由にボタンを disabled にしない / 確認ダイアログも足さない**
  (AGENTS.md 禁止事項 8)。
- **告知文は述語の意味をそのまま言う**。`TakeStatus` は uploading / processing / ready / failed の
  4 値を持つため、述語が真になるのは「まだ撮っていない」だけではない。よって
  「未撮影」と断定せず「撮影・処理が完了した採用テイクがありません」と書く。
- **`render_jobs.placeholder_cut_count` の値契約** (生成物の説明であり、現在状態からの
  再計算はしない。値の出所は buildManifest が確定した clips ただ 1 つ):

  | 行の状態 | 値 |
  |---|---|
  | 本列の追加以前から在る行 | `null` (**backfill しない**) |
  | queued / running / finalize 未到達の failed | `null` |
  | succeeded な preview | 実際にプレースホルダへ落ちたクリップ数 |
  | succeeded な render | `0` (render は欠落し得ない) |

  **`null` を `0` と同一視しない**。`0` は「黒背景ゼロで生成された」という積極的な事実であり、
  `null` は「その動画について言えることが無い」である。UI は `null` では何も表示しない。

  **現在 coverage は「上書き」ではなく「表示の文脈」としてだけ使う (T159)**。
  注記は**常に「生成時点で N 件…」**と書き (現在形にしない)、
  `placeholder_cut_count > 0` かつ `coverage.missing_count === 0` のとき **だけ**
  「現在のシナリオでは未採用のカットはありません」と再生成の案内を足す。
  値は**再計算しない**ので値契約は不変である。
  **これは「プレビューが古い」ことの判定ではない** — 判定できるのは
  「黒背景の理由が**完全に解消**した」という片方向だけで、部分解消 (生成時 20 → 現在 5)・
  逆方向 (テイク削除で増えた)・シナリオ編集による陳腐化は**検出しない**
  (ただし「生成時点で」の言い換えは全ケースで誤読を防ぐ)。
  背景は bug-hunt run 20260812-100645 の F-1-02 (完成動画の直下に矛盾する注記が残っていた)。
- **書き込み位置は `finalize`** である。値が確定するのは `buildManifest` だが、そこは
  `video_manuals` を先にロックしているため、同 tx で `render_jobs` を UPDATE すると
  グローバル順の**逆順取得**になる。`finalize` は既に `render_jobs → video_manuals` の正順で
  ロック済みなので、そこに 1 列足すのが唯一の順序安全な置き場である。
- **再生対象は id ではなく job そのものを props に載せる** (`render.playbackJob`)。動画 URL と
  「黒背景が何カット分あったか」の注記が同一オブジェクトから出るため、最新 preview job と
  再生中の動画が別世代になる穴が条件分岐ではなく構造で消える。
- **機械強制**: `adoptedTake` を参照する `app/` 配下のファイルは
  `Support/Security/AdoptedTakeReferenceInventory` へ区分 (`AdoptedTakeReferenceKind`) と
  30 文字以上の根拠付きで登録する (deny-by-default・exact-fit)。判定式の同居
  (`adoptedTake` 参照と `TakeStatus::Ready` の同一ファイル出現) を許されるのは Canonical 1 件と
  名指し免除だけで、免除には「relation の実体を参照しない」機械検査される前提が付く
  (`tests/Architecture/AdoptedReadyTakeCriterionInventoryTest.php`)。
- **保証しないもの (誇張しない)**:
  - 事前告知は**描画時点のスナップショット**である。別タブ・別ユーザー・別デバイスの撮影で
    古くなる (押下を止めないため詰みは作らないが「常に最新」ではない)。
  - gate は静的走査であり、文字列変数経由の relation 名・動的プロパティアクセス・
    `Take` を別経路で引いて status を見るコードには**沈黙する**。判定式の同居検出も
    「同一ファイル内に `TakeStatus::Ready` が出るか」という近似で、別ファイルへ切り出して
    同じ判定を書く経路は検出できない。
  - `placeholder_cut_count` が語るのは**プレースホルダに落ちたクリップ数だけ**で、
    その動画が実用に足るか (品質) は何も語らない。
  - **プレースホルダ映像自体は変えない** (黒背景 + 字幕は意図的な仕様)。
  - ダッシュボード / 撮影ナビの撮影待ちカウント (`whereDoesntHave('adoptedTake')`) との差は
    残る (あちらは「採用済みだが ready でないテイク」を撮影済みとして数える別基準)。
    統合せず `DifferentCriterion` として記録するだけである。
  - Browser lane が見るのは**告知の可視性と押下可能性**のみで、実 ffmpeg 合成・黒画面の
    目視確認は staging worker での運用確認に委ねる。

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
- **テナント境界 404 は課金ゲートより前**: `{project}` を持つ route では
  `project.in-current-org` が `require-active-subscription` **より先**に走る
  (`bootstrap/app.php` の priority list が正本)。逆順だと「他組織に実在する project =
  課金ゲートの 302 / 不在の project = 404」と分岐し、未契約組織のユーザーでも
  project id の実在を列挙できる**存在オラクル**になる (監査サイクル 2 High-1)。
  自組織 project に対する着地は従来どおり 302 のままで、詰みは作らない。
  順序は `TenantBoundaryOrderingTest`、挙動は `TenantBoundaryPrecedenceTest` が固定する。
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
    `always_invoice` は使わない = 即時請求の与信失敗遷移を持ち込まない)。
    **この方針は確定済み**であり、切り替えに必要な作業一式は下記「proration 方針」を参照
    (機械的な守り手は `tests/Unit/Billing/SubscriptionSwapPayloadInvariantTest`)
  - 冪等は 2 層: 同一 render の二重送信は idempotency key `change-plan:{token}:{planCode}`、
    別 render からの再操作は **gateway の remote Price 照合** (`AlreadyOnTargetPrice` =
    update を送らない)
  - **`organizations.plan_code` は書かない**。反映 (projection_synced) は
    `customer.subscription.updated` → `applySubscriptionSnapshot` が唯一の writer
  - Customer Portal の `subscription_update` は **無効のまま** (プラン変更はアプリが所有する)。
    再開放に必要な条件は `App\Services\Billing\PortalConfigurationSpec` の docblock が正本
  - **proration 方針** (確定): `create_prorations` を既定とし、日割り差額は次回請求に反映する。
    `always_invoice` (即時徴収) へ切り替えるには以下が**セットで**必要であり、
    「payload の 1 行」では終わらない:
    1. `CashierStripeGateway::buildSwapPayload()` の変更 + payload invariant テストの更新
    2. **状態機械の拡張**: `SubscriptionState` に `pending_update` 相当の表現が無い。
       `incomplete` は現在 `Inactive` に畳まれ、`BillingAccess` → `require-active-subscription` で
       **アプリ全体が遮断される**。「アップグレードしようとして与信に失敗しただけの利用者」を
       ロックアウトしない state 設計が先に要る
    3. **webhook の受け口**: `customer.subscription.pending_update_applied` / `..._expired` と、
       プラン変更文脈での `invoice.payment_failed` の扱いが `StripeWebhookProcessor` に無い
    4. **UI**: 3DS/SCA の確認導線がアプリに無い (決済 UI は Stripe hosted の Checkout / Portal のみ)。
       要アクション状態を受ける画面が要る
    5. **ロールバック意味論**: `pending_update` 期限切れで Stripe が巻き戻す挙動と
       `organizations.plan_code` の projection を整合させる規約が要る

    **再検討条件**: 日割り差額の回収遅延がキャッシュフロー上の問題であることを事業側が
    数値で示したとき。上記 1〜5 を同一 TODO で扱う前提で再設計する
    (**証拠なく金銭の挙動を反転させない**)。判断の経緯は
    `devnotes/20260804-0900-t089-t090-residual-risk/` を参照
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
- **ダッシュボード callout は state 別 (T150)**: `/dashboard` の課金 callout は
  `BillingSummaryData::$billingState` (`OnboardingBillingState` の 5 値) をそのまま props に
  載せて分岐する。**真偽値に潰さない** — 一度も契約していない組織 (`no_subscription`) に
  支払い失敗の文言を出していた回帰 (bug-hunt 20260811-003230 F-2-01) の原因が、
  `hasBillingAccess` が「未契約」と「支払い不健全」を 1 bit に畳んでいたことだった。
  - **CTA の行き先を画面が権限で分岐させない**: 未契約系 (`no_subscription` /
    `pending_checkout`) の CTA は常に `/onboarding/checkout` を指し、契約済みなら
    `billing.index`、`manageBilling` なしなら `onboarding.billing-required` へ
    **サーバ (`OnboardingController::show` の離脱ガード) が捌く**。認可をフロントで
    二重実装しないし、押せないボタンも作らない (禁止事項 8)。
  - 値集合の同期は `OnboardingBillingStateTsSyncInvariantTest` (PHP enum ⇔
    `resources/js/types/billing.ts` の `BillingStateValue`)、分岐の網羅は
    **`resources/js/types/dashboard.ts` の `BILLING_CALLOUTS`** が持つ
    `satisfies Record<BillingStateValue, …>` (= `pnpm typecheck`)、描画は vitest と
    Browser lane が担う。**3 層は別物でどれか 1 つでは足りない**。
  - **copy map を `.svelte` に置かない**: `pnpm typecheck` は `tsc --noEmit` であり
    `.svelte` を型検査しない (svelte-check は未導入)。page 内に `satisfies` を書くと
    **一度も評価されず**、キー漏れが無言で通る (T150 の mutation で実測)。
    state 網羅をコンパイル時に守らせる map は `resources/js/types/*.ts` に置く
    (`types/manual.ts` の `VIDEO_MANUAL_STATUS_LABELS` /
    `CAPTURE_NAVIGABLE_BY_STATUS` と同じ所在)。
  - **判定ロジックは変えていない** (`BillingAccess::state()` / `grantsAccess()` は不変)。
    `expired_checkout` が「有償契約後の支払い不健全」と「checkout 期限切れの未契約」を
    ともに指す多義性は残る。

## 支払い失敗の猶予と Stripe 契約状態の突き合わせ (T163)

### 猶予 (payment grace) の定義

- **猶予** = 支払い失敗 (`subscriptions.stripe_status = 'past_due'`) を**観測してから**、
  利用を止めるまでの日数。**日数の正本は `config/billing.php` の `payment_grace_days`**
  (既定 14 日。`BILLING_PAYMENT_GRACE_DAYS` で環境ごとに変えられる)。
  ここに具体の数字を書き写さない (二重管理を作らない)。
- **判定の唯一の口は `App\Support\Billing\PaymentGracePolicy`**。config を読む場所と期限を
  計算する場所をここ 1 つに閉じる。画面文言・通知・運用スクリプトが日数を再計算しない。
  境界 (期限ちょうどの瞬間) は**切れていない**扱い = 利用者に有利な側へ倒す。
- **起点は `subscriptions.past_due_since`** で、これは**観測時刻であって Stripe 側で実際に
  支払いが失敗した時刻ではない**。webhook を落としていれば日次突き合わせが観測した時刻に
  なる (= 利用者に有利な側へずれる)。
- 書込は `SubscriptionService::applySubscriptionSnapshot` の 1 行に閉じる
  (`PastDueSinceWriteInvariantTest` が `app/` 内の書込を機械固定する)。打刻規則は 3 つだけ:
  past_due 観測 + 既存値 NULL → 観測時刻を打つ / past_due 観測 + 既存値あり → 上書きしない
  (再送で猶予を先送りしない) / past_due 以外の観測 → NULL に戻す。
  **手動 SQL / tinker でこの列を書かない**。
- 遮断は `SubscriptionService::deriveEntitlement` の一本道に足した 1 条件
  (`state = PastDue` ∧ 起点が非 NULL ∧ 猶予切れ → `EntitlementDeniedReason::PaymentGraceExpired`)。
  **起点が NULL のときは遮断しない** — 打刻漏れという自分側の不具合を、支払い済み顧客の
  締め出しに変えないため。
- **チケット残高切れには猶予を設けない** (残高 0 は予約時点で即拒否)。前払いチケットで猶予を
  作ると「借金して使わせる」ことになる。**これは未実装ではなく決定である**。

### 支払い未解決 (`PastDue` / `Unpaid`) の間に禁じる 2 つ

`SubscriptionState::hasUnsettledPayment()` が true の間、次の 2 つを**同時に**禁じる
(同じ問いなので述語を 2 つに割らない):

1. **無料枠への読み替え** (`BillingAccess::state()`) — 支払いに失敗した利用者が、無料枠を
   申告済みの組織で `ActiveFreePlan` に落ちて何事もなく使い続けられるのを塞ぐ。
2. **新規契約の開始** (`SubscriptionService::startCheckout` 段 1b) — Cashier の `valid()` は
   past_due / unpaid を false と見るため段 1 を素通りするが、Stripe 側の契約は生きており、
   ここで作ると **2 本目の契約 = 二重請求**になる。

- **契約が終了したあとは (未払いが残っていても) 無料枠へ戻る**。督促の末に Stripe が解約した
  (`canceled`) 契約には未払いの請求書が残りうるが、その回収は課金事業者側の債権管理であり、
  アプリの利用可否とは切り離す (切り離さないと未払い請求書を追い続ける仕組みが要る)。
- 遮断された管理者の着地は `onboarding.checkout` → **`billing.index`** (支払い方法を更新できる
  画面) へ `OnboardingController::show` が送り直す。ドメイン規約 4 の着地契約は **middleware の
  着地**についてのもので、その先で画面が適切な場所へ送り直すのは既存の
  `hasActiveAccess → billing.index` と同型。`billing.index` は課金ゲートの外なので詰まない。

### 日次の突き合わせ (`billing:reconcile-subscription-status`)

webhook は落ちうる (Stripe 自身が遅延・欠落を明記している)。1 通落とすとローカルの
`stripe_status` は古いまま固まり、支払い失敗の遮断も復旧も起きない。本コマンドが
**Stripe を真実として**食い違いを収束させる唯一の経路。

- **責務の境界** (既存 2 本と書く列が重ならない。相乗りさせない):

  | コマンド | 周期 | 書くもの |
  |---|---|---|
  | `billing:reconcile-auto-recharge` | 15 分 | チケット自動購入の未決金 (台帳) |
  | `billing:reconcile-schedules` | 日次 | 予約 (Schedule) の作りかけ (schedule 列) |
  | `billing:reconcile-subscription-status` | 日次 | 契約状態そのもの (`applySubscriptionSnapshot` の担当列) |

- **金銭は動かさない** (チケットの付与・返金に触れない)。**列を直接書かない**
  (書込は `SubscriptionService` の 2 メソッド経由のみ)。
- 書くのは**食い違いがあるときだけ** (`needsSnapshotConvergence`)。比較対象は
  `applySubscriptionSnapshot` が書く列すべてで、status だけを見ると `current_period_end` や
  `ends_at` だけがずれた状態が永久に収束しない。
- Stripe の subscription オブジェクト → `SubscriptionSnapshot` の写像は
  **`SubscriptionSnapshotMapper` 1 つ**で、webhook (payload の `data.object`) と突き合わせ
  (SDK の `toArray()`) が同じ規則で読む (写像が 2 つあると突き合わせ経路だけ別挙動になる)。
- **実行時間上限とロックの関係**: 各契約の照会の**直前**に残り時間を見て打ち切る
  (soft limit。最後に開始した照会 1 回分だけ超過しうる)。
  `TIME_BUDGET_SECONDS + STRIPE_CONNECT_TIMEOUT_SECONDS + STRIPE_TIMEOUT_SECONDS < LOCK_SECONDS`
  を定数比較テストが固定し、**Stripe SDK の再試行が 0 回であること**を前提として同時に固定する。
- **監視対象 (必須項目として登録する)**: 本コマンドの**終了コード**と **`report()`**。
  照会失敗 1 件以上 / ロック取得失敗 / 実行時間上限超過で `FAILURE`。未確認 (Stripe に無い) は
  状態を変えないので `SUCCESS` だが、件数が 0 でなければ必ず `report()` する
  (1 実行 1 回・内容は件数と organization id のみ = PII を載せない)。

### 保証しないもの (誇張しない)

- 起点は**観測時刻**であり、実際に支払いが失敗した時刻ではない。移行の backfill は
  **デプロイ時刻**を起点に置く (実際の失敗時刻は復元できないため。遡って遮断しない)。
- 未確認 (404) や照会失敗が続く契約では、猶予も遮断も**動かない** (状態を変える材料が無い)。
- webhook と突き合わせの**観測順序は保証しない**。古い観測が後勝ちすると起点が作り直される
  ことがある。収束は最終的なもので、Stripe の再送と翌日の突き合わせで揃う。
- ローカルが終了扱い (`canceled` / `incomplete_expired`) の行は照会対象外なので、
  **誤って終了と書かれた行は自動回復しない**。
- 決済手段 (`has_payment_method`) は **true 方向のみ**修復する (観測できないことを false と
  断定しない = 単調更新を壊さない)。
- `organizations.plan_code` は比較対象にしない (同一トランザクションで同期されるため
  subscriptions 行と食い違わない。未知 Price のときだけ据え置かれるが、その回復は本経路の
  責務ではない)。

## 滞留回収の共通基盤 (T171 / 家系の裁定 AG-083 標準形 v1)

止まったまま進まなくなった処理・予約を、原因を問わず「一定時間が過ぎても状態が変わっていない」
ことだけを手がかりに前へ進める仕組み。**入口は `work:recover-stuck` ただ 1 本**で、
対象の系列は `--stream=<key>` で指定する (`RecoveryStream` が系列とその実行間隔の正本)。

### 系列の契約 (`App\Contracts\Recovery\StuckWorkStream`)

- `candidateIds()` は**主キーだけ**を昇順で返し、`recover()` は**主キーと掃引開始時刻しか
  受け取らない**。行の内容を持ち回れないので、回収側は必ず行を取り直して述語を再評価することに
  なる (候補を集めた後に正常へ進んだものを誤って失敗にする事故が構造的に起きない)
- 候補列挙と行ロック下の再評価は**同じ 1 つの述語**を使う (各ドメインの Service の private に
  集約してある)。片方だけ書き換えられると誤回収が再発するため、複製を作らない
- 競合・条件不成立は例外ではなく `RecoveryOutcome::Skipped` を返す。例外を投げてよいのは本当の
  不変条件違反だけで、掃引側が報告して次の候補へ進む

### 実効上限とページ送りの違い

- 掃引はページ送り (1 度に 200 件ずつ取り出す) で行う。**これはメモリの上界であって掃引全体の
  上限ではない**。先頭に居座って毎回例外になる行があっても、カーソルが跨いで前進するので
  後続に手が届く
- 実効上限 = `min(--limit, 系列の申告)`。適用箇所は 1 つだけで、系列の実装は上限を知らない。
  現在 上限を申告しているのは撮影アップロード (500) だけである
- `limit-reached=yes` は「上限に達し、かつ**未処理の候補が実在する**」ときだけ出す
  (ちょうど上限件数で候補が尽きた場合は打ち切りではない)

### 実行しない指定 (既定) が数えるもの

`--apply` を付けない実行は `recover()` を 1 度も呼ばず、候補の件数だけを数える。
**「回収されるはずの件数」は出せない** (webhook の回収は受理そのものが書き込みのため)。
出力の `candidates` は実際に回収される件数の**上界**にすぎない。

### 結果の種類 (`RecoveryOutcome`。この 5 値がすべて)

| 種類 | 意味 |
|---|---|
| `recovered` | 業務状態を前へ進めた |
| `recovered_with_cleanup_failure` | 前へ進めたが付随する後始末に失敗した (S3 の孤児削除) |
| `skipped` | 競合・条件不成立で何もしなかった (正常事象。失敗ではない) |
| `deferred` | 前へ進まなかったが次回の掃引へ残した (webhook の再実行失敗) |
| `escalated` | 自動回収の対象外へ移し人手へ渡した (webhook の `recovery_pending`) |

### 監視対象 (必須。**5 つを見る**)

- `errors > 0` が続く = 特定の行で回収が失敗し続けている
- `deferred > 0` が続く = 再実行が失敗し続けている。**`errors` には出ない** —
  失敗を行に書き戻して次回へ回すため、`errors=0` のまま滞留しうる (独立した監視対象である)
- `escalated` の件数 = 自動回収の対象外として人手へ渡した件数
- `cleanup-failed > 0` = S3 の孤児削除に失敗した件数。**手動確認が要る** —
  行は解放済みなので自動では拾い直せない
- `limit-reached=yes` が続く = 上限で打ち切っており後続候補が残っている

加えて各系列の Schedule には `onFailure` → `report()` が付いており、回収が止まったことが
無音にならないようにしてある。

### 多重起動の抑止と、その限界

`onOneServer()` + `withoutOverlapping()` を全系列に揃えてある。**ロックの有効期限は明示する**
(`RecoveryStream::overlapExpiryMinutes()` = 実行間隔の 2 倍)。Laravel の既定は 24 時間で、
異常終了でロックが残ると回収が丸 1 日止まったまま無音になるためである。
**保証の限界を誇張しない**: 有効期限を過ぎるとロックは期限切れとして解けるので、
正常な実行がその時間を超えて走っている間は同一系列が並行実行されうる。多重起動しても状態が
壊れないことは各系列の行ロック下の再評価が担保するが、「重複が起きない」とは書かない。
**想定最大実行時間が有効期限を下回っていること**は運用の監視対象 (実行時間) である。

### 目録 (deny-by-default)

`StuckWorkRecoveryInventoryTest` が「registry の系列集合 == `RecoveryStream` の全 case ==
目録の申告集合」と「Schedule に載る全コマンドが回収の入口か非回収の申告のどちらかに属する」を
機械強制する。**`--apply` の付け忘れは無音で回収を全面停止させる**ため、その検査が本 gate の
主目的である。撤去した旧実装の再流入は `RetiredRecoveryReferenceGateTest` が止める。

### 旧語彙からの対応表 (運用者が旧語彙で探して見つからない状態を作らない)

| 旧 (撤去済みコマンドの出力) | 新 (`work:recover-stuck` の出力) |
|---|---|
| `replayed` | `recovered` |
| `retry-scheduled` | `deferred` |
| `moved-to-recovery-pending` | `escalated` |
| `skipped` | `skipped` |
| `recovered N stale analysis job(s)` / render 側の同種 | `recovered` |
| `released N stale reservation(s)` / upload reservation 側の同種 | `recovered` |

コマンド名の対応は次のとおり (旧名は 5 本ともコードにも運用の正本にも残っていない):
解析 → `--stream=analysis_job` / レンダ → `--stream=render_job` /
チケット予約 → `--stream=ticket_reservation` / Stripe の通知 → `--stream=webhook_event` /
撮影アップロード → `--stream=upload_reservation`。
アップロード予約の**保持期間の決着**だけは回収ではないため `capture:purge-upload-reservations`
(日次) に分けてある。

### 保証しないもの (誇張しない)

- 撮影アップロードの 500 件上限は**公平性を保証しない** (毎回同じ先頭側だけを見る可能性がある。
  ページ送りが効くのは 1 回の掃引の中だけである)
- S3 の削除に失敗した孤児オブジェクトは**自動では拾い直せない** (行は解放済みで候補から外れる)。
  `cleanup-failed` の件数を見て手で確認する
- 実行しない指定の候補件数は、実際に回収される件数の**上界**にすぎない
- 目録は申告の集合一致を見るだけで、`recover()` が実際に行ロック下で述語を再評価しているかは
  検査できない (それは各系列の Feature テストが担う)
- Schedule の検査は登録内容を見るだけで、**定期実行の仕組みが実際に動いていることは保証しない**
  (運用側の監視対象)
- 滞留の閾値は各ドメインの設定 (`config/manual.php` / `config/billing.php` / `config/capture.php`)
  に置いたままである。回収側の設定へ集約すると、ジョブの制限時間・再試行間隔・予約の有効期限との
  序列を固定している既存テストと情報源が 2 つに割れるため

## Stripe webhook の滞留回収

- **状態の意味**: `received` = 受理済み・未終局 (**処理中と次の回収待ちを兼ねる**。
  どちらかは `updated_at` が `config('billing.webhook_stale_after_minutes')` (15 分) を
  超えたかで区別する) / `processed` = 終局 / `failed` = HTTP 経路の失敗 (Stripe の再送が
  再試行の駆動者) / `recovery_pending` = 自動再実行の対象外として置いた静止状態
- **なぜ回収が要るか**: `claim()` が直列化するのは状態遷移だけで `process()` は
  トランザクションの外にある。そこで落ちた行は `received` のまま残り、Stripe の再送は
  `claim()` に弾かれて 200 で終わる → Stripe も再送を打ち切る = **付与が無音で失われる**
- **回収してよい種類**: `HandledStripeWebhookEvent::replaySafety()` の 2 値分類が**唯一の**
  判断材料。`SafeToReplay` の意味は「再実行しても追加の被害を生まない」であって
  「再実行すれば復旧する」ではない。**ハンドラに副作用を足したら分類を再審査すること**
  (順序に依存する書き込みを足したら `OrderSensitive` へ移す。機械では検出できない)
- **回収の失敗は終局させない**: 再実行が例外になっても `received` のままにして
  `failure_reason` だけ書く (`failed` にすると回収対象から外れ、Stripe も再送しないため
  二度と再試行されない)。`attempts` は消費されるので上限 8 で必ず止まる
- **処理対象外の種類**: `HandledStripeWebhookEvent` に無い type は通常経路と同じく
  再実行して `processed` にする (`process()` の `null` arm は構造的に no-op)。
  回収だけ別扱いにして運用ノイズを作らない
- **監視対象 (必須項目として登録する)**: **`php artisan work:recover-stuck --stream=webhook_event --apply`
  (scheduler で 5 分ごと・`onOneServer()` + `withoutOverlapping()`)**。
  失敗は `onFailure` → `report()` で運用アラート経路に載る。観測点は 3 つ:
  1. `status='received'` かつ `updated_at <= now - 閾値` の件数
     (増え続ける = scheduler か本コマンドが動いていない)
  2. 本コマンド出力の `deferred` 件数 (再実行が失敗し続けている)
  3. `status='recovery_pending'` の件数 (理由は `recovery_reason`:
     `order_sensitive` / `attempts_exhausted`)。出力の `escalated` はここへ移した件数である
- **運用手順**: `recovery_reason` ごとに次の行動が違う。
  `order_sensitive` は Stripe ダッシュボードで現在の契約状態を確認する /
  `attempts_exhausted` は `failure_reason` があれば確認し、ログと Stripe 上の状態と
  合わせて手当てする (連続クラッシュでは NULL のことがある)
- **保証しないもの**: (1) 順序に依存する種類は自動復旧しない (契約状態は後続の
  `customer.subscription.updated` が追随する。初回無償付与だけは失われ得るので件数で拾う)。
  (2) 条件付き UPDATE が守るのは `stripe_webhook_events` 行の世代だけで、旧ワーカーと
  回収側の `process()` の**同時実行そのものは防がない** (付与の一回性は台帳の
  `idempotency_key` UNIQUE が担う)。(3) `report()` の配送は通知基盤の設定次第で、
  常設の観測点は件数のほうである。(4) HTTP 経路で `failed` になった行は回収 cron が拾わない。
  (5) 外部 API 遅延等で本当に閾値を超えた生存ワーカーがいた場合、順序に依存する種類の行は
  `recovery_pending` へ置かれ、そのワーカーが成功しても行は回収待ちのまま残る
  (業務側の副作用は正しく起きているのに、行だけ「要確認」に見える)

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
  併せて `ticket_checkout_sessions` 行を completed 化する。
  **滞留 (`received` のまま残った) 分は `work:recover-stuck --stream=webhook_event` が回収する**ので、
  手動付与の前にその経路で決着していないかを確認する (§Stripe webhook の滞留回収)
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
  停止は滞留回収 → 失敗確定の経路で失敗通知が発火する
- **宛先導出**: ジョブ通知 = `manual.created_by` ∪ `triggered_by` (jobs 列。Auth からの明示代入のみ =
  `MassAssignmentProtectedKeys` 登録済み) を org 所属再確認 + dedup / 招待 = `whereBlind` 一致の
  既存ユーザーのみ (平文 token 非含有) / 残高低下 = org の owner/admin
  (`organizationRole` = laratrust_team_id 明示判定)
- **残高低下のクロス検知**: `TicketLedgerService::reserve` の org 行ロック内で
  「実効残高 (Reserved 拘束込み) が `billing.ticket_low_balance_threshold` を跨いだ」ことを判定し、
  **クロスの事実だけをクロージャの戻り値で持ち出して tx を抜けた最後に 1 回通知する**
  (commit は拘束と台帳が相殺し balance 不変 = クロスを発生させない。
  release/grant で回復して再度跨げば再通知)。`billing_notifications` (メール送達台帳) には行を作らない
  - **T137 で `DB::afterCommit` を撤去した** (§キュー投入の原子性)。afterCommit は
    「commit したのに未投入」の窓を作る機構であり、AG-127 の付随的副作用は
    「tx の外へ出す」であって「afterCommit で温存する」ではない
  - **保証範囲を誇張しない**: `reserve()` が呼び出し側の tx にネストされている場合、通知は
    依然として外側 tx の内側で走る (= 外側のロックを保持したまま INSERT され、SQL 層の失敗は
    PostgreSQL の transaction abort を経て業務操作ごと失敗させうる)。
    `NotificationCenterService::safely()` が握るのは**アプリケーション層の例外だけ**である
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
  `take_upload_reservations` (pending) を予約
  (**初期 status は INSERT 時に明示代入**する。DB カラム default は既存行と他の INSERT 経路の
  ために残すが、この経路の意味は default に依存しない) → `Capture/TakeObjectStorage` が
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
  プロセス定義・デプロイ手順・監視対象に `php artisan queue:work database-media --timeout=240`
  を必須項目として登録する** (専用 worker が居ないと削除ジョブは滞留する。`--timeout` は
  規則 1。§キューのリース期間とワーカー制限時間の規約)
- **孤児掃除の定期実行**: `work:recover-stuck --stream=upload_reservation --apply` (10 分毎・
  onOneServer) が期限切れ pending / stale verifying (updated_at 15 分超過) を released 化して
  bytes_pending を解放し、PUT 済み未登録の S3 オブジェクトを削除する
  (`Recovery/Streams/StaleUploadReservationStream`。fresh verifying には触れない = 登録処理の
  claim 契約と競合しない)。1 掃引の上限は 500 件 (S3 の入出力を有界にするため。公平性は保証しない)
- **保持期間の決着は別コマンド**: released/completed の retention (30 日) 超過行の物理削除は
  `capture:purge-upload-reservations` (日次・onOneServer) が行う。滞留の前進ではなく期限の決着
  なので回収とは入口を分けている
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
- **PC 面との導線は往復で 1 対 (T155)**: 往路は `Manuals/Show` / `Manuals/Edit` の
  「この手順書を撮影する」(`isCaptureNavigable` = ready / published のときだけ出す)、
  復路は `Capture/Show` ヘッダーの「マニュアル詳細へ」(**`Capture/Show` へ到達済みの利用者に対し、
  追加の status / ability 条件を設けず常に出す**)。
  **2 つの述語を共有しない**: 往路は「いま撮影を始めてよい相か」、復路は「元の画面へ戻れるか」で
  意味が違い、合成中 (`rendering`) こそ進み具合を見に戻る場面である。復路専用の述語も作らない。
  復路を無条件にできる根拠は、行き先 `projects.manuals.show` が `capture.manuals.show` と
  **同じ層を同じ順序で通る**ことである — 外側 group の `auth` / `verified` /
  `not-pending-deletion`、内側 group の `require-active-subscription` / `project.in-current-org`、
  `Route::scopeBindings()`、controller の `resolveOrganizationProject()` (認可より前に 404)、
  `Gate::authorize('view', $manual)`。詳細 GET はどちらも status で絞り込まない (一覧だけが絞る)。
  よって復路が 403 になる経路が見当たらない。**ただしテストが固定するのはこの構造的同一性ではなく**、
  現在サポートする最弱 principal である撮影者 (project_member) について全 status で
  両 route の 200 + 着地 component が成立することである (`CaptureReturnPathTest`)。
  **保証しないもの**: インストール済み PWA (standalone) で同一窓に留まることは保証しない
  (`public/manifest.webmanifest` に `scope` 宣言が無く、既定 scope が `/` になるという
  仕様の読みに基づくだけで実機観測がない)。狭幅ヘッダーの実レイアウト (折り返し・truncate) も
  保証しない (Vitest は jsdom でクラス名しか見ない。Browser lane は追加していない)。
  撮影完了の検知・自動遷移も行わない (ヘッダーの常設リンクのみ)。
  撮影者が完成動画を観られるようにもならない (認可は不変。§完成レンダ成果物の選択と受け取り口)
- **PWA フロント**: `pages/Capture/*` + `features/capture/*` + `lib/capture/*`
  (即時アップロード優先・IndexedDB は失敗/オフライン時の一時バッファ・419 は csrf-cookie
  再取得 1 回リトライ)。SW (`public/capture-sw.js`) は同一オリジン GET `/build/*` のみ
  stale-while-revalidate (アプリ応答・S3 は素通し)

## 退会 (アカウント削除) の課金ガード (T115)

- **不変条件**: 「**唯一 Owner** かつ (**他メンバーが残る** ∨ **生きた課金責務がある**) 組織」が
  1 つでもあれば退会をブロックし、**次の一手を提示する** (押下時にエラー = 削除ボタンは
  disabled にしない)。**通常のアプリ経路の**判定の権威は
  `OrganizationMembershipService::deleteAccount()` のロック下再評価 (canonical 順序
  users → organizations)。表示用の `/settings` props (`accountDeletionBlockers`) は
  スナップショットに過ぎない
- **「生きた課金責務」の定義**: `Services/Billing/AccountDeletionBillingGuard::hasLiveBillingObligation()`
  = `SubscriptionState::fromSubscription()->grantsAccess()` (Active / UpgradeRecovery / PastDue)
  **かつ `ends_at === null`**。`ends_at` 付き (期末解約予約済み) を**通す**のが要点で、ここを塞ぐと
  「解約したのに退会できない」最長 1 課金周期の詰みが出る。`paused` / `canceled` / `unpaid` /
  `incomplete*` も通す。**これは entitlement (利用可否) の判定ではない** (利用可否の窓口は
  `BillingAccess` / `SubscriptionService::deriveEntitlement`)
- **退会処理から決済事業者 API を呼ばない**原則 (自 DB と外部サービスの二重書き込みを避ける)。
  固定しているのは `tests/Feature/Auth/AccountDeletionTest.php` の
  「退会成功経路では決済事業者 API を呼ばない」「課金中でブロックされる経路でも決済事業者 API を
  呼ばない (解約を代行しない)」の 2 本 (並べ替えに耐えるよう番号ではなく**テスト名**で参照する)
- **予防 + 検知の 2 枚構成**: webhook トランザクションとの競合は排他しない
  (subscription 行を作るのは Cashier の `WebhookController` = vendor 側で、自前 listener の
  排他では覆えない)。検知は daily の `billing:detect-orphan-billing-organizations`
  (Owner 不在かつ生きた課金責務が残る組織を 1 実行につき**集約して 1 回だけ** `report()`。
  内容は件数と organization id のみ = PII 非出力。ガード導入前から存在する孤児も拾う)。
  **監視対象**: 本コマンドの `report()`
- **検知バッチの N+1 の判断記録**: `orphanBillingOrganizationIds()` は組織ごとに subscription を
  引くが、入力が「Owner 不在の組織」= 通常 0 件の異常系集合のため許容する。件数が増えたら
  exists subquery 化する
- **決済事業者側データの運用注記**: 顧客データの消去は削除ではなく**非表示化 (redaction)** で、
  非表示化は**作成から 90 日後のみ**・処理に**最大 30 日**を要する。**アプリからは自動化しない**
  (退会経路から事業者 API を呼ばない原則と整合)。必要時は運用手順で実施する。
  **外部仕様のため鵜呑みで固定しない**: 出典は c2c 台帳 feature `account-deletion-billing-guard`
  の handover / 裁定 AG-033 (**確認日 2026-08-05**。一次情報は決済事業者 (Stripe) の公式
  ドキュメントだが、**台帳側に一次情報の URL が pin されていない**)。数値を運用に効かせる前に
  一次情報を引き直し、URL と確認日をここへ追記すること。事業者仕様変更時に更新する対象である
- **⚠ 直上の bullet は経緯として残した過去記述である**。「台帳側に一次情報の URL が pin されていない」
  「数値を運用に効かせる前に一次情報を引き直せ」の 2 点は **T141 で解消済み**で、
  現在状態は直下の 3 bullet が正本である (直上の未 pin 記述を現在状態として読まないこと)
- **一次情報の pin (T141)**:
  <https://docs.stripe.com/privacy/redaction> と
  <https://docs.stripe.com/privacy/deletion-requests> を **2026-08-10 に確認**した。
  90 日は「**取引**は作成から 90 日後に非表示にできる」(失敗した取引は直ちに / サンドボックスは即時 /
  返金済みは返金完了時点)、最大 30 日は「関連データを非同期で識別して編集するのに最大 30 日」を指す。
  **customer 単体の待機期間ではない**点に注意 (上の運用注記の要約より条件が細かい)。
  なお RedactionJob は同日時点で**公開プレビュー**と明記されている。手順・保証しないもの・
  実施記録コマンドは **`docs/account-deletion-runbook.md` が正本**
- **redaction の実施記録 (T141)**: 実施は人手で行い、アプリは記録だけ持つ。
  `organizations.stripe_customer_redacted_at` (実施日時) と
  `organizations.stripe_customer_redacted_id` (記録時点の `stripe_id` の写し) の **2 列セット**で、
  記録経路は `billing:mark-stripe-customer-redacted` (既定 dry-run / `--apply` で実記録 /
  既記録なら no-op。**決済事業者 API を呼ばない**)。日時だけだと「**どの** customer を
  redact したか」が事後に検証できないため 2 列必要で、**両列同時**の不変条件は
  PostgreSQL の CHECK 制約 (`organizations_stripe_customer_redaction_pair_check`) が
  アプリ層を迂回した UPDATE に対しても担保する
- **「決済事業者 API を呼ばない」の静的 gate (T141)**:
  `tests/Architecture/AccountDeletionPathGateTest.php` が退会経路の**依存閉包**を走査し、
  閉包内のクラスが決済事業者記号へ到達しないことを固定する (免除は
  `App\Enums\Security\DeletionPathSeamExemption` + 30 文字以上の根拠。現在 0 件)。
  behavioral 2 本は「その経路で今日呼ばれなかった」しか言えず**新しい依存を注入した瞬間に沈黙する**
  ため、静的 gate と behavioral は**並存**させる (behavioral 側は変更しない)。
  **保証しないもの**は gate 冒頭 docblock が正本 (変数 container 解決 / vendor 内部の通信 /
  docblock のみの受け手 / 実行時 bind 差し替え。**そもそも検知であって遮断ではない**)
- **決済手段の前提**: subscription Checkout は `payment_method_types` を指定せず Stripe
  ダッシュボード設定に委ねている。**非同期決済 (コンビニ払い等) を有効化する場合、`incomplete` を
  退会ガードで通過させている判断を再確認すること** (滞留時間が伸びるため)

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

- `organizations.members.update` (PATCH) の role payload は 3 値コマンド
  (旧 org ロール値は enum 検証で拒否)。Owner は enum 外 = 構造的に指定不可
  (Owner 昇格は transferOwnership のみ)
- **`organizations.invitations.store` (POST) の role payload は org ロール 2 値**
  (`organization_admin` / `organization_member`)。`Rule::enum(OrganizationRole)->except([Owner])`
  で Owner を構造的に拒否する。**招待は「組織に入れる」ことだけを意味し**、
  編集者 / 撮影者は参加後にロール割当コマンドで付与する
  (役割付き招待 `organization_invitations.project_role` は裁定 AG-079 で列ごと撤去。
  受諾 `joinOrganization` は org 参加 + org ロール付与 + accepted_at のみを行う)

### 招待受諾の 2 経路 (token URL / アプリ内)

受諾経路は 2 本あり **受諾の根拠が違う**。片方だけを見て「不整合」と直さないこと。

| 経路 | route | 受諾の根拠 | 解決方法 |
|---|---|---|---|
| メール token URL | `invitations.accept` (GET) / `invitations.accept.store` (POST) | **有効な招待 token の保持** | `token_hash` (sha256) 照合 |
| アプリ内 | `invitations.accept-in-app` (POST) | **auth 済み ∧ email 確認済み ∧ ログイン者 email = 招待宛先** | `OrganizationInvitation::scopeActivePendingForEmail($user->email)` |

- **`verified` の非対称は仕様**: token 経路は招待直後の未検証ユーザーも受諾できる
  (メールを受け取れたこと自体が根拠の一部)。アプリ内経路は根拠そのものが
  「email 確認済みのログイン者 = 招待宛先」なので `verified` が必須。
- **token 経路の bearer 意味論は `organization_admin` の招待にも同じく及ぶ**
  (bug-hunt run 20260811-003230 の F-2-03 で「別 email 宛の token をログイン中の別ユーザーが
  受諾できる」が再現し、仕様どおりと確認された。以後の探索が同じものを認可バイパスとして
  再提出しないよう明記する)。受諾時に `joinOrganization()` が付与するのは
  `$invitation->role` (org ロール 2 値) であり、**管理者ロールの招待リンクを入手した
  任意のログイン中ユーザーはそのまま組織管理者になれる**。これは経路の性質が
  メンバー招待と同じ (入口の唯一の弱点は招待メールの機密性) であることの帰結で、
  緩和は 7 日失効・受諾済み/取消済みの除外・`/manage/users` での可視化。
  **メンバー招待と管理者招待で受諾根拠を変えていない**のは意図であって漏れではない。
- **email 照合の非対称**: アプリ内経路は blind index の**大文字小文字を区別する完全一致**
  (email の blind index に Lowercase transformer を付けていない)。
  **email は正規化保存していない** (`App\Support\EmailNormalizer` は inquiry / billing contact 専用で、
  `CreateNewUser` は validated 値をそのまま保存する) ため、「招待は `Foo@example.com` 宛 /
  ログインは `foo@example.com`」は実運用で起こりうる。この場合アプリ内受諾は 0 件 = **404 に倒れる**
  (fail-secure) が、**メール token 経路は `token_hash` 照合なので影響を受けず従来どおり受諾できる**。
  正規化するなら既存全レコードの blind index 再計算と全 `whereBlind` 呼び出し元の同時変更を伴う別作業になる。
- **存在秘匿の畳み方**: 受信者視点の解決・一覧・件数は `scopeActivePendingForEmail` の 1 本だけを
  再利用する (`InvitationResolutionInventoryTest` が deny-by-default で強制)。
  宛先不一致 / 不在 id / 期限切れ / 取消済 / 受諾済 / 削除済み組織宛は**すべて同じ 0 件**へ collapse し、
  controller は**一律 404** を返す (403 を返さない = 招待の存在を教えない)。
  `{invitation}` は implicit binding させない (binding 段で解決すると不在 id だけが binding 404 になり
  1 bit の存在オラクルになる。`NestedRouteDefenseInventory` / `RouteBindingTypes::MANUALLY_RESOLVED` に登録)。
- **最終権威の表** (どの競合をどのロックが閉じるか):

| 競合 | 最終権威 |
|---|---|
| 組織の soft-delete | `lockForMembershipWrite` が取る organizations 行ロック (削除も同じ行の UPDATE) |
| 取消 / 期限到来 / 並行受諾 | `joinOrganization` の招待行 `lockForUpdate` (取消の UPDATE も同じ行を取る) |
| 別経路での並行 join | `organization_user` の `insertOrIgnore` (0 行 = 既 join として role を変更しない) |

- `joinOrganization()` は **bool を返す** (false = ロック下再検証で受諾不能)。
  全呼び出し元が false を消費する (token 経路は中立メッセージへ / register 経路は
  現在組織を確定せず null / アプリ内経路は 404)。戻り値を捨てる実装は
  `MembershipWriteLockInventoryTest` のトークナイザ検査が fail させる。
- 共有 prop `invitationInbox.pendingCount` は **closure** のため `only:` 指定の partial reload では
  評価されない (件数はフルページ遷移時に更新される。受諾直後は dashboard へフル遷移するため実害はない)。
  キー名を `invitations` にしないのは、ページ prop `invitations` (Admin/Users の招待一覧) と
  衝突して共有 prop が上書きされるため。
- **未ログイン / 未 verified / email 空は DB を一切引かない**
  (`OrganizationMembershipService::pendingInvitationsQuery` の early return。
  共有 prop は全リクエストで評価されるため、これが実効的な負荷契約になる)。
- **役割付き招待の撤去 (AG-079) のデプロイ順序**: (1) `project_role` を読み書きしないコードを先にデプロイ →
  (2) 旧プロセスの排除 (`queue:restart` / web worker 入替完了) → (3) 列 drop の migration。
  逆順にすると旧コードが存在しない列へ INSERT して 500 になる (回復導線が無い)。
  ローリング更新中は新旧 HTTP 契約の混在で招待送信が一時的に 422 になりうるが、
  `StoreOrganizationInvitationRequest::messages()` の「画面を再読み込みしてやり直してください。」が回復導線になる。

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

## 偽の外部サービスの宣言と配線の不変条件 (T119 / T177)

外部サービス (決済 / 保存先 / LLM) を偽物へ差し替える配線は、**登録漏れが例外にならず
本物が静かに動く**という性質を持つ (Laravel は abstract が具象クラスなら設定が無くても
自動組み立てする)。撮影データと課金は取り返しがつかない副作用を持つため、以下を不変条件として
固定する (gate は `tests/Architecture/ExternalFakeWiringInvariantTest` /
`FakeClassReferenceInvariantTest` / `LaneExternalFakeBindingTest` / `ExternalFakeBootProbeTest`、
走査器の固定は `tests/Unit/Architecture/FakeWiringSourceScannerTest`)。

- **「何をどの偽物へ差し替えるか」の唯一の正本は
  `App\Support\ExternalFakes\ExternalFakeDeclaration`** (本番の読み込み対象に置く)。
  差し替え 1 本は `ExternalFakeBinding` の値オブジェクトで表し、
  capability flag / 許可環境 / 差し替えない対象もここが持つ。
  provider・`FakeStorageGate`・bug-hunt の seeder・`ProductionEnvGuard`・
  bug-hunt の環境ひな型検査は**すべてこの宣言を読む** (同じ集合を 2 か所に書かない)。
- **差し替えの唯一の配線点は `App\Providers\FakeExternalsServiceProvider`**。container 差し替えは
  `$this->app->bind($swap->abstract, $swap->fake)` の形**だけ**で行う。
  **`::class` を直に書く bind は許可形から外れる** = 差し替え先の決定は宣言側にしか無い
  (`singleton()` / 第 3 引数 (= singleton 相当) / 変数 abstract / closure concrete /
  `app()`・`resolve()`・`App::`・`Container::getInstance()` 経由も deny-by-default で fail する)。
  登録は `bootstrap/providers.php` で **`AppServiceProvider` より後**に置く (後勝ち rebind)。
- **新しい差し替えを足したら `ExternalFakeDeclaration::swaps()` に entry を足す**。
  足すと「flag off で real / flag on + allowlist env で fake / allowlist 外 env で real」の
  **実証**検査が自動で増える。判定は必ず**厳密クラス一致** (`$resolved::class === $expected`) —
  保存先の偽物は本物のサブクラスなので `instanceof` では偽グリーンになる。
  Architecture lane は `RefreshDatabase` を使わないため、**解決対象の constructor が DB 非依存**で
  あることを確認すること。**entry を消す変異を映すのは `3-16` (abstract 一覧の件数付き pin) だけ**で、
  増減させるときは宣言と gate の 2 か所を同時に触る (意図的な摩擦)。
- **レーン側 (`tests/`) から偽の実装クラスを container へ直接結ばない**
  (`LaneExternalFakeBindingTest` が静的に禁じる。例外の登録簿は持たない)。
  レーンで偽物を有効にするときは `tests/Pest.php` の `enableFakeExternals()` /
  `enableFakeStorage()` を使い、宣言 + provider の 1 本を共有する。
  per-test の代役 (`tests/Support/Fake*`) は Laravel 公式作法のテストダブルであり本規約の対象外。
- **capability flag は 3 系統で許可環境が異なる**: `testing.fake_externals` (決済 + 人間性確認 +
  外部ログイン。local / testing / bughunt.local。ただし**外部ログインだけ local を除く**)、
  `testing.fake_storage` (`App\Support\FakeStorageGate` が有効化条件の単一正本。
  bughunt.local ∨ (testing ∧ 自動テスト実行中))、`testing.fake_llm`
  (bughunt.local のみ。`Prompt::$fake` は container ではなくプロセス大域の static)。
- **差し替えない対象**は `ExternalFakeDeclaration::neverSwapped()` に理由付きで宣言する
  (受信通知の署名検証 / 外部 URL の安全検査)。宣言集合と交わったら gate が落ちる。
- **本番混入防止の正本は `App\Support\ProductionEnvGuard`** (配備前 = `production:preflight` /
  起動時 = `AppServiceProvider::boot`)。**設定値とプロセスの実環境変数 (`$_SERVER` / `$_ENV` /
  `getenv()`) の両方**を見る — 設定キャッシュを作った環境と出荷先が食い違うと、キャッシュ上は
  false でも、キャッシュが失われた起動で環境変数が読み直されて本番で偽物が立ちうるため。
  解釈できない値 (`maybe` / 非文字列) は安全側で違反にする。fake 配線 gate はこれを二重実装しない。
- **fake 実装クラスは `app/**/Fakes/` か `app/**/Testing/` に置く**。配置例外は
  `FakeExternalsServiceProvider` (唯一の配線点) と `FakeStorageGate` (有効化条件) の 2 件のみ。
- **本番コード (`app/` • `routes/` • `config/` • `bootstrap/`) は fake クラスを参照しない**。
  参照してよいのは宣言・配線点・偽の保存先の署名付き経路の受け口を含む 6 ファイルだけで、
  allowlist の件数はテストが固定している (増やすには理由コメントと併せて 2 箇所を触る摩擦がかかる)。
  **誤検出が出ても allowlist を足す方向へ倒さない** — それが gate の目的である。
- **別プロセスでの実測** (`ExternalFakeBootProbeTest`): 実際の起動の下で宣言の全件が
  偽物 / 本物へ解決されること、外部ログインの転送先が自ホストへ閉じること、
  production + フラグ有効なら起動そのものが失敗することを子プロセスで観測する。
  子の環境は `env -i` で空にし、設定は使い捨ての鍵だけを書いた一時環境ファイル 1 つから読む
  (親のチェックアウトの `.env` を読ませない = 実資格情報を子へ渡さない)。
  **観測できるのは設定キャッシュ無しの起動だけ**である (キャッシュが古いときの事故は
  上の二重判定が受け持つ)。

### bug-hunt の投入データ (seeder) の配線 (T177)

偽の外部サービスの配線と**同じ理由** (登録漏れが無音) が投入データにも当てはまるため、
`tests/Architecture/BughuntSeedWiringInvariantTest` が deny-by-default で固定する。
区分の目録は `tests/Support/Bughunt/BughuntSeedWiringInventory`
(母集団は `database/seeders/` の全 seeder で、過不足なく一致することを要求する)。

- `scripts/bug-hunt-shard.sh` の `cmd_provision` と `cmd_reseed` の投入列が**順序込みで一致**する
  (順序に意味がある。並べ替えるときは 2 か所を同時に直す)
- その列の集合が目録の「bug-hunt で明示投入する」区分と過不足なく一致する
- bug-hunt 専用の seeder は `DatabaseSeeder` に現れない (全環境の `migrate:fresh --seed` で走らない)
- 環境ガードを要求する区分は `run()` の**最初の実効文が `if`** で、条件に区分ごとの判定語が
  すべて現れ、本体に早期 `return` がある
- **静的走査は条件の論理 (かつ / または) を読めない**ため、ガードを要求する区分には
  その論理を実際に動かして固定している振る舞いテストを目録から紐づける (前提テストが消えたら赤くなる)

**bug-hunt の手順書 (`.claude/skills/app-bug-hunt/`) 側に投入データの検査は置かない。**
手順書が守るのは走行の型 (禁止事項・走る順・異常の見分け方) であり、
「どの投入データがどの入口に配線されているか」は実行時の配線の関心事である。
配線の検査は上の Architecture テストが持つ。

## 外部 SDK の待ち上限の規約 (T126)

外部 SDK (Stripe / AWS) は**無指定だと待ちが有界にならない**
(Stripe cURL client の既定 80s × SDK 自動リトライ / AWS は timeout 無指定 = 無制限 × 3 attempts)。
値の正本は **`App\Support\ExternalClientTimeouts`** ただ 1 つで、env で上書きできる口を作らない
(gate が読む値と本番の実値を一致させるため。`config/queue.php` の `retry_after` と同じ理屈)。

> **用語 (誇張しない)**: 「HTTP 試行 timeout 予算」= cURL / Guzzle に与える 1 試行あたりの上限 × 試行回数。
> **SDK 操作全体の wall-clock deadline ではない** (DNS 解決・credential provider・
> endpoint discovery・retry backoff はこの外側)。

| 面 | 値 | 配線点 |
|---|---|---|
| Stripe (プロセス大域) | connect 5s / timeout 20s / `max_network_retries` 0 | `App\Providers\ExternalClientTimeoutServiceProvider` |
| AWS 制御系 (SES 送信 / SNS) | connect 5s / timeout 15s / `max_attempts` 2 | `config/services.php` の `ses` / `AppServiceProvider` の `SnsClient` singleton |
| AWS データ系 (s3 disk 既定) | connect 10s / timeout 900s / `max_attempts` 2 | `config/filesystems.php` の `disks.s3` |
| S3 per-command (web 同期の metadata) | connect 5s / timeout 15s / `@retries` 0 | `TakeObjectStorage::headObject()` |

- **Stripe は client ごとの timeout を支えない**。`StripeClient` の config に timeout 系のキーが無く、
  `Stripe\ApiRequestor` の static HTTP client だけが唯一の調整点である。したがってテナント別 timeout は持たない。
- **`max_network_retries = 0`** に pin する。課金の一回性は **Stripe idempotency key とリコンサイル**が
  担う設計 (AGENTS.md ドメイン規約 6) であり、SDK 自動 retry に寄せない
  (0 でないとジョブの外部予算が retry 数だけ倍化する)。
- **AWS の語彙に注意**: 構築引数の `retries.max_attempts` は **初回を含む試行回数** (2 = 初回 + 再試行 1 回)、
  per-command の `@retries` は **retry 回数** (0 = 再試行しない)。同じ数字でも意味が違う。
- **s3 disk の既定を短くできない**: Flysystem の write 経路 (`AwsS3V3Adapter::upload()`) は
  `@http` を per-command で転送しないため、client 既定がデータ系を賄う必要がある。
- **`services.ses` は vendor 契約に依存する**。`Illuminate\Mail\MailManager::createSesV2Transport()` が
  `config('services.ses')` を **そのまま `new SesV2Client(...)` へ渡す**ため、AWS client option は
  この配列の**直下**に置く (ネストすると AWS 側から未知キーになり黙って無視される)。
  この前提は `ExternalClientTimeoutInventoryTest` が behavioral に固定する。

### S3 到達境界と面分類

- 業務層は **`TakeObjectStorage` / `RenderObjectStorage`** しか参照しない。AWS SDK / Flysystem へ
  到達しうる `app/` のクラスは `ExternalClientTimeoutInventoryTest` の目録へ
  「adapter」か「免除 (`App\Enums\Storage\ExternalClientBoundaryExemption` + 30 文字以上の根拠)」で
  登録が必須 (deny-by-default)。
- adapter の public メソッドは **`App\Enums\Storage\S3OperationSurface`** で面分類する
  (正本は `tests/Support/Storage/S3SurfaceInventory`)。分類軸は「転送量が有界か」と
  「per-command option を注入できるか」の 2 つ。
- **`Bulk` 面を web 同期経路から呼ばない**。これは**規約であって機械証明ではない**
  (呼び出しグラフ解析が要る)。既存の web 経路については
  `tests/Feature/Capture/TakeRegistrationS3SurfaceTest.php` が behavioral に固定する。
- 免除理由 (`ExternalClientBoundaryExemption`) には**適用条件を機械検査する前提表**が付く
  (「`disk()` を呼ばない」「`new Aws\…` しない」等)。docblock の約束だけで免除を通さない。
- **`driver=s3` の disk は全件が pin を宣言する**ことを gate が要求する。
  `Storage` facade を既定 disk のまま使う層は `FILESYSTEM_DISK` 次第で S3 へ到達しうるため、
  「特定の disk 名」ではなく driver 単位で塞ぐ。到達しても待ちはデータ系の帯 (有界) になる。
- **走査の保証範囲を誇張しない**: 目録の母集団は「型/クラス名の参照」「`new Aws\…`」
  「`disk()` / `getClient()` の呼び出し」「Stripe 大域 setter の呼び出し」の静的検出である。
  **文字列キーの container 解決だけでこれらの token をまったく出さない迂回は検出できない**。
  だから**やらない**、が規約の側の担保である。

### 帯を変更するときのデプロイ順序

**worker の起動形態は環境で違う**: `mprocs.yaml` は **dev** で `queue:listen`、
**本番/ステージングの supervisor** は上の値表どおり `queue:work`。確認コマンドは**両方**を拾う正規表現にする。

```
0. 実施条件 (手順 1 の前に確認する)
   - **低トラフィック時間帯**に実施する (SIGALRM で落ちる旧ジョブを最小化するため)
   - `database` キューの未処理件数が 0 に近いこと
     (select count(*) from jobs where queue = 'default')
   - オートリチャージの pending attempt が滞留していないこと
     (select count(*) from ticket_auto_recharge_attempts where status = 'pending')

1. 全 worker の supervisor 定義を --timeout=540 → 300 へ変更して再起動する
   (このときコードは旧のまま = retry_after 600。300 < 600 で規則 1 は成立)
   ★確認方法: 各 worker ホストで
     pgrep -af 'artisan queue:(work|listen) database( |$)' を実行し、
     出力の全行に --timeout=300 が含まれること。実施主体は本番デプロイ担当。

2. 新コード (SDK pin + retry_after 360) をデプロイし、全 worker を入れ替える

3. 旧 worker が残っていないことを確認する
   ★確認方法: 同コマンドで --timeout=540 の行が 0 件であること。
     加えてデプロイ開始時刻より前に起動した worker プロセスが残っていないこと
     (ps -o lstart=,args= -p <pid>)

4. 実施後、手順 0 と同じクエリで pending attempt の残留を確認し、
   残っていればリコンサイルの完了を待つ (または手動起動する)
```

**受容事項**: 手順 1 の間、旧コード (Stripe 80s 前提) のジョブが 300s で SIGALRM されうる。
`ExecuteAutoRechargeAttemptJob` は `$tries = 1` でリコンサイルが再試行を担うため、恒久喪失にはならない
(Stripe idempotency key により二重課金にもならない)。手順 0 の実施条件はこの受容の**発生確率を下げる**
ためのものであり、「起きない」ことの保証ではない。
## 2FA 面の step-up (recent-auth) 契約 (T124)

第二要素そのものを扱う面は、**セッション認証だけでは到達させない**。
機械強制は 2 枚 (`RecentAuthRouteTest` の allowlist + `TwoFactorStepUpInventoryTest` の
deny-by-default 目録) で、判定述語は `Tests\Support\Security\RecentAuthMiddleware` に
単一化してある (2 つの gate が別々に堅牢化されてドリフトするのを防ぐ)。

### 何を守るか

| 系統 | route | 開けたままにすると |
|---|---|---|
| (a) 秘密の開示 | `two-factor.qr-code` / `two-factor.secret-key` / `two-factor.recovery-codes` | 奪取セッションから TOTP seed を読み出して**第二要素を複製**できる (以後ログインが素通し) |
| (b) 第二要素の除去・差し替え | `two-factor.enable` / `two-factor.disable` / `two-factor.regenerate-recovery-codes` | 正規ユーザーを**締め出せる** |

(b) に `two-factor.enable` が入るのは、Fortify の `TwoFactorAuthenticationController` が
`$request->boolean('force')` をそのまま `EnableTwoFactorAuthentication` へ渡し、
**force=true が `two_factor_secret` と `two_factor_recovery_codes` を再生成する一方で
`two_factor_confirmed_at` を触らない**ためである (fortify v1.37.2 実査)。
奪取セッションから 1 回叩くだけで「誰も知らない秘密で TOTP を要求し続ける」
**永久ロックアウト**が成立する。秘密の読み出しだけ塞いで差し替えを開けたままにしない。

throttle (`two-factor-secret-read`) は**連続取得の回数上限**であって step-up の代替ではない。

### 目録の契約 (`TwoFactorStepUpInventoryTest`)

- 母集団は **route 名に `two-factor` を含む全 route** で、件数は **exact fit** (現在 11 本)。
  vendor が 1 本足しても必ず差分として現れ、分類を強制できる。
- 各 route は **recent-auth 系 middleware をちょうど 1 種類**持つか、
  `App\Enums\Security\TwoFactorStepUpExemption` + 30 文字以上の根拠で免除登録する。
  「1 種類」は `recent-auth` (無条件) と `recent-auth.on-email-change` (条件付き) の
  **同居**を禁じる意味である。同一 alias の重複登録は `Router::uniqueMiddleware()` が畳むため
  **実行時に観測できず**、検査対象にしていない (誇張しない)。
- 上の表の **6 本は exemption にできない** (名指しで固定)。免除側へ移されたら fail する。
- 免除は現在 3 件 (`two-factor.login` / `two-factor.login.store` = 未認証チャレンジ面、
  `two-factor.confirm` = TOTP の所持証明が前提で秘密を開示しない) で、全体 cap と
  case 別 cap の両方が exact fit。
- 組織管理側の 2 本 (`organizations.members.two-factor.reset` /
  `organizations.two-factor-requirement.update`) は母集団には入るが non-exemptible 名指しには
  入れない (脅威系統が違い、`RecentAuthRouteTest` の allowlist が既に固定している)。
- **保証範囲を誇張しない**: セレクタは名前ベースであり、`mfa.*` 等の別名で第二要素へ触る
  route には**沈黙する**。別名の route を足すときは母集団設計も同時に見直すこと。

### satisfier の到達性 (詰みを作らない側の契約)

step-up を新しい面に課したら、**その面へ到達する前に step-up を満たせる手段**が
必ず 1 つ以上あることを確認する。2FA 必須組織のゲート
(`RequireTwoFactorForEnforcedOrganizations::ALLOWED_ROUTE_NAMES`) は
password (`recent-auth.password`) / 再SSO (`social.redirect` • `social.callback`) /
**passkey** (`passkey.confirm-options` • `passkey.confirm`) の 3 satisfier をすべて通す。
どれか 1 つでも欠けると、その手段しか持たないユーザーが enrollment の入口で手段ゼロになり詰む。
`passkey.registration-options` / `passkey.store` / `passkey.destroy` は credential 集合を
増減させる**管理**経路であり satisfier ではないので、allowlist に入れない
(`TwoFactorEnforcementTest` の負のコントロールが固定)。

### クライアント側 (enrollment 動線)

`resources/js/pages/Settings/Security.svelte` は
**step-up を enrollment の最初の操作に固定する** (有効化ボタン → precheck → POST)。
precheck 無しで POST すると Inertia mutation が 409 (`recent_auth_required`) を受け、
単一ハンドラ (`registerRecentAuthRedirectHandler`) が confirm 画面へ**全画面遷移**する。
precheck ならモーダルで完結するので**設定画面から離脱せず**、再認証成立後に
enrollment の開始操作をその場で再開できる。
守っているのは離脱の回避であって QR / 入力中コードの保持ではない
(開始 POST の時点で素材はまだ存在しない。素材取得**後**の鮮度切れは下の 409 分岐が担当する)。

throttle の**巻き添え**は論点ではない (T125 でレーン分離済み。`two-factor-manage` 10/min と
`password-verify` 6/min は別 bucket なので、2FA 操作の連打で再認証が 429 になる
inline 時代の構造は残っていない)。ただし `ThrottleRequests` は middleware priority により
`RequireRecentAuth` より**先**に走るため、鮮度切れの試行もレーンの枠を消費する
(実測: 鮮度切れの GET でも `X-RateLimit-Remaining` が減る)。precheck はその無駄も避けるが、
固定したい本命は画面状態を失わないことである。

素材 (QR / セットアップキー) の 409 は「取得失敗」とは**別事象**として扱い、
自動再開は 1 enrollment につき 1 回に制限する。status が取れない (delegated) ときは
**再取得しない** — 再取得すると 409 → status 失敗 → 再取得 の無限ループになるため、
`enrollment-step-up-blocked` の Alert と再認証ページ導線を出して**人間の操作**を待つ。

## 外部到達点の目録 (標準形 v1 / 検知 v1) (T138)

`app/` から外部サービスへ出るコード到達点を **deny-by-default の目録**で押さえる。
正本は `Tests\Support\ExternalSeam\ExternalSeamInventory`、強制は
`tests/Architecture/ExternalSeamInventoryTest.php`。**本節が「保証しないもの」の正本**である
(`AGENTS.md` ドメイン固有規約と gate 冒頭コメントは要約であり、増減はここで管理する)。

### 目的と、T126 到達境界目録との違い

| | §外部 SDK の待ち上限の規約 (T126) | 本目録 (T138) |
|---|---|---|
| 問い | **待ち上限が pin されているか** | **その到達点の身元が検査されているか** |
| 母集団 | AWS / Flysystem / Storage facade / Stripe 大域 setter | 決済 client の取得・構築 / Socialite / Http / Mail・Notification facade |
| 目的 | ハング防止 (timeout の宣言) | 差し替え・監視の設計に含める (fake 配線と新経路の検知) |

目的が違うので**両方に載るクラスがある**のは正当である (`AppServiceProvider` は
AWS SNS クライアント構築で T126 に、`Cashier::stripe()->prices` の container 配線で
本目録に載る = **別々の到達事実**)。禁じているのは「同じ到達事実の二重宣言」であり、
規則が分離しているので構造的に起きない。

走査基盤は `Tests\Support\PhpReferenceScanner` (中立走査器) に一本化されており、
T126 の `ExternalClientBoundaryScanner` も本目録の `ExternalSeamScanner` も
**同じ namespace 解決 / alias マップ / brace scope 追跡**の上に立つ (2 本持たない)。

### 検出規則 (5 種)

| 規則 | 何を見るか | 名乗ってよい種別 |
|---|---|---|
| `payment_client_call` | `Cashier::stripe()` / `$x->stripe()` | `payment` |
| `payment_client_construction` | `new Stripe\StripeClient` (**完全一致**) | `payment` |
| `socialite_facade_reference` | `Laravel\Socialite\Facades\Socialite` の参照 | `social_login` |
| `http_facade_reference` | `Illuminate\Support\Facades\Http` の参照 | `captcha` / `market_data` |
| `mail_facade_reference` | `Mail` / `Notification` facade の参照 | `mail` |

- **接頭辞走査をしない**。`Stripe\` を素の接頭辞で走ると `GatewayFailureClassifier` が
  import する Stripe 例外 14 クラスと `StripePriceCatalogEntry` の値オブジェクト参照を拾い、
  目録が肥大して信号が死ぬ。規則は **client の取得・構築**に限定する。
- `$x->stripe()` は receiver 非依存で拾い、同一ファイルが決済名前空間 (`Laravel\Cashier\` /
  `Stripe\`) を **site または import で**知らない場合だけ抑制する。抑制した site は
  捨てずに `ExternalSeamScanResult::$suppressed` へ積み、gate が **0 件**を固定する
  (抑制が静かに効いて偽陰性になることがない)。
- facade の canonical は **`NameReference` のみ**。`Socialite::driver()` は receiver が
  `NameReference`、メソッドが `StaticCall` として **2 site 出る**ため、両方を採ると
  1 呼び出しが 2 件に数えられる。

### 種別 × 次元と委譲

`ExternalSeamKind` (payment / social_login / captcha / mail / market_data / object_storage / llm) ×
`ExternalSeamDimension` (code_reach_point / destination_set) の必須表を
`ExternalSeamInventory::requiredDimensions()` が exact-fit で宣言し、各対は
**目録か委譲のちょうど一方**で覆われる (二重宣言も未被覆も赤)。

| 種別 × 次元 | 覆う側 | 委譲先 |
|---|---|---|
| `object_storage` × code_reach_point | 委譲 | `ExternalClientTimeoutInventoryTest` (T126) |
| `llm` × code_reach_point | 委譲 | `PromptGuardrailTest` (Prism 直呼び禁止) |
| `social_login` × destination_set | 委譲 | `SocialProviderTrustPolicyTest` (`config('template.social_providers')`) |
| 上記以外 | 目録 (`entries()`) | — |

委譲の結線は 2 層: (1) `livenessProbe` を**実行**して母集団が空でないこと (behavioral)、
(2) `gateFile` の実在 + `gateTestName` が `PestTestNameScanner` の抽出結果に**完全一致**すること。
単なる文字列包含にしないのは、改名後も旧名がコメントに残れば緑になってしまうためである。

`payment` に `destination_set` を要求しない: Stripe の宛先は API キーが指す account であり、
設定面の走査対象にしていない。

### SSO の集約と captcha の fake 配線

- SSO は `App\Services\Auth\SocialiteDriverResolver` **1 クラスに名指し固定**される
  (`ExternalSeamInventory::socialLoginFunnel()`)。他クラスからの `Socialite::driver()` は
  登録も免除もできず必ず赤くなる (集約と直呼び禁止の機械化)。
  **T153 で集約先を controller からこの薄い解決点へ切り出した** — container の差し替えキーに
  なれるのは controller ではなく解決点だからである。
- 非本番の captcha は `testing.fake_externals` で `RecaptchaVerifier` →
  `RecaptchaVerifierTestFake` へ container bind される (`ExternalFakeDeclaration`)。
  abstract が**具象クラス**のため bind を消しても Laravel が本物を自動組み立てし、
  `RECAPTCHA_SECRET_KEY` が設定された環境では**無言で** Google siteverify を叩く
  (`StrayHttpRequestGuard` は bug-hunt の別プロセス実行には効かない)。
- **SSO も同じ capability flag (`testing.fake_externals`) で fake する** (T153)。
  `SocialiteDriverResolver` → `App\Services\Auth\Fakes\FakeSocialiteDriverResolver` へ
  container bind され、`redirect()` は自アプリの `social.callback` へ 302 する
  (IdP 風の中間スタブ画面は作らない)。identity は provider 名から決定論的に導出した
  canned 値 (`fake-{provider}-user` / `fake-{provider}-sso@example.com`) で、
  外部入力では切り替えられない。
  - **env allowlist は `testing` / `bughunt.local` のみで `local` を除く**
    (`ExternalFakeDeclaration::SSO_ENVIRONMENTS`)。SSO fake は未認証 GET 2 本
    (`/auth/{p}/redirect/login` → `/auth/{p}/callback`) で canned アカウントへログインできる
    = **認証バイパス**であり、かつ `local` は開発者が実 IdP 連携を確認する唯一の環境である。
    この除外は**誤設定ではなく設計上の除外**なので warning ログを出さない (LLM fake と同じ扱い)。
  - **差し替えキーは自前の具象クラスであって Socialite の `Factory` ではない**。
    `SocialiteServiceProvider` は `DeferrableProvider` で、`Container::bind()` は
    `deferredServices` を消さない。`Factory::class` へ bind すると最初の解決時に deferred
    provider が読み込まれ、その `singleton(Factory::class, …)` が**後勝ちで fake を消して
    無言で実 IdP へ戻る**。
  - vendor の `Socialite::fake()` は使わない (戻り先が `https://socialite.fake/...` で
    round-trip が完成しない)。ただし identity は vendor の `Two\User` を `map()` で再利用する。

### 免除分類 (`ExternalSeamClassification::Exempt`) は現時点で使用できない

規則を「client の取得・構築」と「外向き facade の参照」に絞った結果、検出 = 実到達となり
「身元検査不要」側の母集団が **0 件**である。母集団 0 の免除語彙を先に作ると
「1 件も検査せずに緑」な gate が 3 本増えるため、`Exempt` の使用を gate が明示的に拒否する。
免除が本当に必要になった時点で、免除語彙 enum・免除前提表・30 文字根拠検査・空振り防止を
**セットで新設**させる意図的な摩擦である (失敗メッセージが案内する)。

### 保証しないもの (誇張しない。**本節が正本**)

1. **出口の遮断**。本目録は新経路の**検知**であり、実行時の外部通信は止めない。
   SSO の実 IdP 遷移は別機構 (fake 配線) が塞いでおり、本目録の効果ではない。
   また塞がるのは**アプリが返すリダイレクト先**までで、ブラウザ自身が出す通信は
   Playwright の origin allowlist が担う別の層である
2. **委譲先の assert の中身**。委譲先の gate が弱められた (必須宣言のうち 1 つを検査しなくなった等)
   場合は検出できない。結線は「母集団の生存」と「test 名の同定」までである
3. **`app/` の外**。`routes/` / `config/` に書かれた到達コードは走査しない
   (SSO の宛先集合だけは委譲で押さえるが、これは SSO 固有の措置である)
4. **次元そのものの数え落とし**。次元の定義は人手であり、未知の設定面や新 SDK 表面が
   第 3 の次元を作った場合は沈黙する
5. **文字列キーの container 解決だけの経路** (型名も呼び出しも出さない形)
6. **vendor 内部から出る通信** (Cashier / Socialite の内部実装)
7. **他種別の宛先集合** (Stripe の API キーが指す account / SES の region / 為替 API の URL)
8. **`.env.bughunt.local` (git 管理外) の内容**。pin できるのは `.env.bughunt.local.example` まで
9. **決済の別 API 表面**。検出は「client の取得・構築」に限り、新しい静的 helper が増えたときは
   規則の追加が要る
10. **部分修飾名の解決**。`T_NAME_QUALIFIED` (`Facades\Http::get()` のような書き方) は
    現在の namespace への相対解決も先頭 segment の alias 解決も行わない
    (`ExternalClientBoundaryScanner` と同じ限界)。この限界は
    `tests/Unit/Architecture/ExternalSeamScannerTest.php` が**テストとして明示的に固定**しており、
    将来直すときは必ず差分が出る
## 冪等キーの claim と保持期間 (REST API v1 / MCP)

REST API v1 の `Idempotency-Key` は **本処理の前に claim する**方式で、契約の正本は
[docs/api-idempotency.md](api-idempotency.md)。ここには運用側の要点だけを置く。

- **モデル**: `IdempotencyKey` は `state` 列 (`processing` / `completed` / `indeterminate`) を持つ。
  claim は `insertOrIgnore` で行い、調停者は既存 unique 2 本
  (`api_key_id, route_name, key` / `user_id, route_name, key`) **だけ** (cache ロックを併用しない)。
  決着は `completed` / `indeterminate` の 2 つで、**release (再実行を許す) 経路を持たない**。
  状態遷移は middleware の条件付き UPDATE のみが行うため `state` は `$fillable` に入れない。
- **契約変更 (破壊的)**: 4xx/5xx で終わった要求の後、同じキーは再利用できない
  (409 `idempotency_indeterminate`)。観測面は `api.v1.projects.items.{store,update,destroy}` の
  3 route のみ。MCP write tool は 0 本のため観測面なし。
- **cron**: `idempotency:prune` (daily・`onOneServer`) が保持期間
  (`config/idempotency.php` の `retention_hours`。**env 不使用**) を超えた行を
  REST / MCP 両テーブルから物理削除する。claim 時の lazy delete は
  「再送されたキー」しか回収しないため単調増加を止められない。
- **監視対象**: `idempotency:prune` の `report()`。`processing` のまま期限切れになった行は
  「claim したのに確定できなかった要求」= プロセス強制終了か finalize 失敗の痕跡である
  (載せるのは件数のみ。キー値・body は載せない)。
- **閉じない窓 (誇張しない)**: OOM / timeout / プロセス強制終了で `processing` が残る窓は
  閉じない。保持期間満了まで同一キーは 409 `idempotency_in_progress` を返し続ける。
- **`onOneServer()` の前提**: scheduler が動いていることと、ロックを提供する cache driver が
  使われていることが前提 (既存の `billing:send-billing-reminders` /
  `render:reconcile-outputs` と同じ。本節で新しく持ち込む前提ではない)。
  満たさないと多重実行しうるが DELETE は冪等で、害は `report()` の重複に留まる。

## 退会の猶予期間つき削除 (凍結方式・30 日)

lctl 台帳 feature `account-deletion-billing-guard` の標準形 v1 (裁定 AG-128) の必須 (2)。
設計は `devnotes/20260809-0908-account-deletion-grace/detailed-design.md` の PR-B。
**猶予つき予約と即時削除は併存する** (どちらか一方に寄せない)。

- **凍結方式の定義は「users 行の生死を変えない」**。`SoftDeletes` は使わない —
  FK cascade / nullOnDelete / CipherSweet の blind index (`email_index`) の一意照合 /
  passkey / OAuth セッション / 招待の email 照合が、すべて users 行の実在を前提にしている。
  予約は `users.deletion_requested_at` / `users.deletion_purge_after` の 2 列で表す。
- **`deletion_purge_after` は絶対時刻**で持つ (猶予日数のスナップショットを持たない)。
  不可逆な物理削除の期日に config 変更を遡及させないため。猶予日数は
  `purge_after - requested_at` から導出する。値の SSOT は
  `App\Support\Account\AccountDeletionGrace` (`config/account.php`。**env 不使用**)。
- **状態機械は DB で閉じている**。CHECK 制約 2 本
  (`users_deletion_request_pair_check` = 両列同時 / `users_deletion_purge_after_order_check`
  = 期限が予約時刻以降) が片列だけの非正規状態を拒否する。アプリ側の `isPending()` は
  同じ定義 (両列が揃うときだけ true) で、制約が無効化されても判定がぶれない。
- **凍結は deny-by-default**。`routes/web.php` の `auth` + `verified` group 全体に
  `not-pending-deletion` を直付けし (route cache に焼き込むため後付け binder は使わない)、
  `App\Enums\Account\AccountDeletionFreezeAllowance` の **exact case のみ**通す
  (wildcard 禁止・30 文字以上の根拠必須)。通すのは「取消」「取消への step-up」
  「退会ブロッカーの解消 (解約 / 移譲 / メンバー整理 / 招待取消)」「通知の閲覧」だけ。
  遮断は **302 → `/settings`** (403 で突き放さない)、JSON/XHR は **409**。
  母集団と allowlist の一致は `AccountDeletionFreezeRouteGateTest`、実挙動は
  `tests/Feature/Auth/AccountDeletionFreezeTest.php` が固定する。
- **`settings.account.destroy` (即時削除) は allowlist に入れない**。予約中に即時削除を通すと
  30 日猶予の迂回口になる。「今すぐ消したい」なら **取消 → 即時削除**の 2 手を踏む。
- **実行位置**は `bootstrap/app.php` の priority list が正本で、テナント境界 404
  (`EnsureProjectBelongsToCurrentOrganization`) より**後**・課金ゲートの直後に置く。
  302 で短絡するため前に置くと存在オラクルになる (セキュリティ不変条件 10)。
  ログイン・ログアウト・パスワード再設定・メール確認・2FA challenge・passkey ログインは
  group の外にあり、**認証回復と離脱の手段は構造的に凍結されない**。
- **取消に step-up を課さない**。誤操作救済の本体であり、関門を足すと「取り消せない」詰みの
  再生産になる。受け入れるリスクは「奪取者が予約を取り消せる」ことだが、失われるのは
  意思表示だけで本人は再予約できる (逆は本人が救済できない = 被害が重い)。
  予約 (`settings.account.deletion-request.store`) には recent-auth を課す。
- **cron**: `account:purge-deletion-requests --apply` (daily・`onOneServer`) が
  期限到来の予約を執行する。判定は既存の
  `OrganizationMembershipService::deleteAccount()` がそのまま行う (課金ガードの
  ロック下再評価を継承)。予約 / 取消 / 執行はいずれも `lockForMembershipWrite`
  (users 昇順 → organizations 昇順) の canonical 順序に乗る。
- **監視対象**: 本コマンドの**終了コード**と `report()`。退会ブロッカーは**業務上の保留**で
  SUCCESS のまま次へ進み (予約は維持し翌日再試行)、インフラ障害・不変条件違反・
  予約列の非正規行は `unexpected` として **FAILURE** になる。出力は**件数のみ**で
  user id / email を載せない。保留は **走査後に件数を集約した `RuntimeException` で report** する
  (`ValidationException` を素で `report()` しても Laravel の既定 dontReport が握り潰すため。
  T142 で実測)。保留が滞留すると 30 日を過ぎた予約が消えないままになるので、
  `blocked` の継続・増加を正常成功として扱わない。
- **即時削除 (`settings.account.destroy`) の遮断は HTML と XHR の両方で固定済み (T160)**。
  凍結中の即時削除は **recent-auth の有無にかかわらず 409** を返す (凍結が step-up より先)。
  理由は (a) 凍結状態を知るのは**本人**で `/settings` に既に表示しており秘匿すべき相手がいない、
  (b) 再認証させてから断るのは体験として悪い。**実行順が変わっても 409 が正**であり、
  `AccountDeletionFreezeTest` の契約がそれを固定する。
  **未認証要求は 409 ではなく 401** — 未認証時は user 不在で凍結判定が作用しないため、
  この要求について middleware 順序への依存は無い。
- **削除の監査 metadata (T160)**: `AccountDeleted` イベントへ
  `deletion_requested` (削除実行時点で凍結中だったか。**行ロック下で読み直した行**から取る) /
  `route` / `method` を残す。route・method は呼び出し元が
  `AccountDeletionAuditContext::http()` / `::nonHttp()` で**明示的に**渡す
  (既定引数を持たせない = HTTP 呼び出し元の渡し忘れと「HTTP 外なので null」を区別する)。
  **これは観測であって防御ではない** — この値で分岐する処理は 1 つも無い。
  背景は bug-hunt run 20260812-100645 の F-4-Q1 (凍結中の即時削除で 1 件だけ実データが消えた
  観測。**2 回のクリーン再現では遮断され、原因は未特定**)。
- **2FA 必須組織との相互作用**: 2FA 強制ゲートは priority list で凍結より**前**に走る。
  取消は**業務の利用ではなく誤操作の救済**なので、**両ゲートの allowlist に入っている**
  (凍結側 = `AccountDeletionFreezeAllowance::DeletionRequestDestroy`、2FA 側 =
  `RequireTwoFactorForEnforcedOrganizations::ALLOWED_ROUTE_NAMES`)。かつては 2FA 側にだけ
  無く、未準拠ユーザーの取消 DELETE が `settings.security` へ倒れて
  「取り消したつもりで取り消せていない」状態になっていた (T149 / bug-hunt F-4-01 で実測)。
  通しても業務面には到達できないまま・認証手段は増減しない・準拠判定
  (`two_factor_confirmed_at`) も動かないため、2FA 必須の効力は変わらない。
  なお **`settings.security` を凍結の allowlist に入れる理由は据え置き**である
  (未準拠ユーザーが 2FA 設定に到達できないと準拠達成そのものが詰む。T142 で実測して発見)。
  この**ゲート間の判断の一致**は `RescueRouteGateInventoryTest` (救済 route の経路上の
  ゲートに分類の宣言を強制する deny-by-default 目録) が機械固定する。
  ⚠ 同目録が守るのは**分類の網羅**であって「経路上の全 middleware を通過できる」ことではない。
- **遮断メッセージ**: 2FA ゲートが**非安全メソッド**を短絡したときだけ、文頭に固定文
  `RequireTwoFactorForEnforcedOrganizations::BLOCKED_WRITE_PREFIX` (「直前の操作は
  実行されていません。」) を付ける。**遮断メッセージが元操作を名指しすることは保証しない**
  (route 名 → 操作名の写像表は持たない = 二重管理を作らない)。また
  「副作用が一切ない」ことも主張しない (session 書き込み・throttle 記録・CSRF 検証は
  短絡時にも起こりうる)。主張の範囲は「controller に到達していない」までである。
- **保証しないもの (誇張しない)**: 凍結は**アプリの web route だけ**に効く。
  `api/v1` / MCP / OAuth token 経由の経路には**沈黙する** (母集団に入っていない)。
  通知の重複配送も止めない (保証しているのは「予約操作からの job 生成は最大 1 件」まで)。
  予約中のユーザーが他者から招待を受けること自体は止めない
  (受諾 route は凍結対象なので受諾はできない)。
## 課金記録の保持期間 (7 年) の決着 (T143 / T144 / T145)

保持年数の正本は `config/legal.php` の `billing_retention_years`、唯一の解決点は
`App\Support\Legal\BillingRetention` (`BillingRetentionConfigSingleSourceTest` が機械固定)。
運用手順・障害対応は **`docs/billing-retention-runbook.md` が正本**。

- **規約側の宣言 (T145)**: `/privacy` の「保有期間」節が保持年数を公開する。年数は
  literal を書かず `BillingRetention::years()` から描画し、**config / SSOT / 文面の三者一致**を
  `BillingRetentionConfigSingleSourceTest` (検査 6 = 呼び出し元 exact-fit / 検査 7 =
  blade に literal が無いこと) と `PrivacyRetentionDeclarationTest` (描画結果の側から
  マーカー `data-legal-retention="billing-records"` / 節見出し / 固定文言「取引関係書類等」/
  年数の 4 点) が機械固定する。**照合は見出し番号ではなく属性と固定文言**で行う
  (節の並べ替え・番号の繰り下げで偽赤にしないため)。
  ⚠ **この文面は法務レビュー前の草案**である (家系の先例に揃えたもので独自の法的主張はしない)。
  「実装が宣言する年数」と「法務が確定する年数」の一致確認は**人間の仕事**であり、
  `config/legal.php` の `consent_version` は本追記では `draft-1` から動かしていない
  (版の確定はリリース時のオーナー判断)。よって「文面が変わったのに版が上がっていない」ことは
  検査対象外である。
- **コマンド**: `billing:purge-retention-expired` (既定 dry-run / `--apply` で実処理)。
  日次登録は `routes/console.php` の `Schedule::command('… --apply')->daily()->onOneServer()`。
- **決着の方式は target で 2 種類ある**。削除で決着する 6 target
  (`stripe_webhook_event` / `billing_checkout_session` / `ticket_checkout_session` /
  `ticket_auto_recharge_attempt` / `subscription_item` / `subscription`) と、
  **畳み込み**で決着する `ticket_ledger_entry` である。実行順は registry
  (`BillingRetentionPurgerRegistry`) が持ち、**子 → 親** (`subscription_item` →
  `subscription`) は入れ替えない (親を先に消すと FK cascade で子が件数報告を経由せず消える)。
- **台帳 (`ticket_ledger_entries`) だけ方式が違う理由**: そこが**残高の真実源**だからである。
  期限超過の行をそのまま消すと利用者のチケット残高が変わる。畳み込み
  (`App\Services\Billing\TicketLedgerCarryForwardService`) は
  `(organization_id, source, expires_at)` ごとに合算し、`kind = carry_forward` の
  **残高スナップショット 1 行**へ置換する。**group key に `organization_id` を必ず含める**
  (欠くと組織を跨いで残高を合算する)。`source IS NULL` (legacy 行) は独立した group。
  繰越行は**取引記録ではなく残高のスナップショット**であり、原取引の識別子を 1 つも
  引き継がない (`carried_forward_through` に集約期間の終端だけを持つ)。
  残高が 1 枚も変わらないことは `tests/Feature/Billing/TicketLedgerCarryForwardTest.php` が
  組織 / source / 失効時刻の粒度で機械固定する。
- **台帳を読む場所は目録制** (`TicketLedgerReaderInventoryTest`)。畳み込みの帰結として
  「7 年より古い個別取引は復元できない」ため、個別行に依存する読み手が宣言なしに増えると
  ある日その経路だけが静かに壊れる。目録は読み方 (`aggregate` / `row_detail` / `other_table`)
  の宣言を強制する。
- **監視対象**: 本コマンドの終了コード (`unexpected_failures > 0` で `FAILURE`) と、
  出力の `horizon:` 行 (**OK / NG / 判定不能** の 3 値)。**`fail_closed` は「安全に残した」であって
  「規約を満たした」ではない**ので、`horizon: NG` の継続と `fail_closed` の増加を正常成功として
  扱わない。想定外失敗があった target の件数は**数えられなかったので 0 で報告される**ため、
  失敗が 1 件でもあれば horizon は `判定不能` になる (その 0 を根拠に OK と言わない)。
- **保証しないもの (誇張しない)**: 目録 (`BillingRetentionTarget` /
  `BillingRetentionExclusion`) は**人間の申告**であり、課金取引の記録が
  `app/Models/Billing/` の外や Eloquent を経由しない表に置かれれば gate は沈黙する。
  本番で日次処理が止まっていないことも保証しない (責務は終了コードと scheduler 運用)。
  畳み込みで失われるもの (返金逆仕訳の逆引き / 消費の冪等キー / signup grant の部分 UNIQUE
  index の保護範囲) は `docs/billing-retention-runbook.md` §7 が一覧を持つ。

## 表ごとの保持期限の分類 (T175)

**実スキーマの全表**を「保持期限を誰が持つか」の区分へ分類し、その分類が実際の表の構成と
食い違っていないことを機械で確かめる層である。台帳は
`tests/Support/Retention/RetentionTableRegistry.php`、検査は
`tests/Feature/Retention/RetentionTableClassificationTest.php` (Feature lane =
実スキーマを引くため DB が要る)。

- **区分は 6 種** (`Tests\Support\Retention\RetentionClass`):
  課金取引の記録 / 定期実行が消す / 親と一緒に消える / 基準データ / 基盤が寿命を持つ / 未確定。
  **除外一覧を持たない** — 基盤の表 (`migrations` / `cache` / `sessions` / `jobs`) も
  区分の 1 つとして必ず載る (除外の口を作ると、そこへ名前を足すだけで検査から逃げられる)。
- **年数・起算点・purger の配線は台帳に書かない**。課金 7 年の正本は
  `App\Enums\Billing\BillingRetentionTarget`、各バッチの期限は各 config の解決点クラスであり、
  台帳が持つのは区分・根拠・保持者の名前だけである (同じ事実を 2 か所に書かない)。
- **未確定は隠さない**。期限が決まっていない表は「未確定」として載せ、件数と表名を
  検査が現在値ちょうどで pin する (増えるときも減るときもテストの差分としてレビューに出る)。
- **検査が保証するもの**: 台帳と実スキーマの表一覧が両方向で集合等価であること (RC-1 / RC-2)、
  二重宣言が無く根拠が 30 文字以上あること (RC-3)、課金 7 年の表集合が
  `BillingRetentionTarget` と両方向で一致すること (RC-4)、宣言した保持者が
  識別先として実在すること (RC-5)、区分と外部キーの構造が矛盾しないこと (RC-6 / RC-7)、
  総件数と未確定の表名が現在値ちょうどであること (RC-8)。

**外部キーの読み方 (RC-7)**: 「期限が要る表への外部キーを一律禁止」ではない。
**親が消えたときに子がどうなるか**で判断する。

| `on delete` | 親の削除時に起きること | 扱い |
|---|---|---|
| `cascade` | 子も消える | 矛盾 (「期限を持たない」と両立しない) |
| `restrict` / `no action` | 親の削除を拒否する | 矛盾 (親の期限の執行を止めうる) |
| `set null` (**列がすべて nullable**) | 子は残り外部キー列が空になる | 矛盾ではない |
| `set null` (`NOT NULL` が混ざる) | 制約違反で親の削除が失敗する | 矛盾 (結果は `restrict` と同じ) |
| `set default` | 既定値が制約を満たさなければ親の削除が失敗する | 矛盾 (本リポジトリに 1 本も無い) |
| 取得できない | 不明 | 矛盾 (保守的に倒す) |

実例として `llm_call_logs` / `security_audit_events` は組織・利用者への外部キーを
`nullOnDelete()` で持ち、**退会・組織削除の後も行が残る**。ここを一律違反にすると、
残る表を「親と一緒に消える」と偽って分類させることになり、検査が事実と逆に働く。
**足りない情報 (参照先が台帳に無い / 外部キーの列が空 / 列の nullable が取れない) は
すべて違反へ倒す** (fail-closed)。

**保証しないもの (誇張しない)**:

- **列は見ない**。単位は表であり、どの列が個人情報かは扱わない。
- **行ごとの寿命の違いは表現しない**。`users` は退会予約が入った行だけが猶予後に消えるが、
  表としては 1 つの区分に丸められる。
- **Schedule への配線は見ない**。コマンドが実在しさえすれば RC-5 は通る
  (`oauth_*` を未確定に置いているのはこのためである)。
- **保持者として宣言したクラス / コマンドが実際にその表を消すことは検査していない**。
  見ているのは識別先の実在だけである。
- **`on delete cascade` の存在は「親が実際に消される」ことを意味しない**。
  親を消す経路が存在するかは見ていない。
- **実データが実際に消えることは保証しない**。それは各掃除バッチの behavioral テストの担当。
- **区分の意味が正しいかは人間のレビュー対象**である。
- S3 上の実体 (レンダ出力・撮影テイク) / ビュー / 他スキーマの表は対象外である。

**`BillingRetentionTargetInventoryTest` との責務境界**: あちらは
`app/Models/Billing/` という人間の申告を母集団に、年数・起算点列・purger の配線・実行順を持つ。
本層は**それらを 1 つも写さず**、表集合の一致 (RC-4) だけで結線する。

**家系の正典との差**: lctl 台帳の正典 (aigenba v1) は `config/retention.php` を保存年数の
正本として持つが、**本リポジトリでは作らない**。保存年数は既に 4 か所の config に分かれて
置かれ、それぞれに唯一の解決点クラスと直読禁止の検査が付いているため、5 つ目の置き場を
作ると値が二重管理になる。**本層が持つのは「分類」であって「値」ではない**。

## パイプライン通し確認 (pipeline smoke) と LLM コストレポート (T147)

`dev:pipeline-smoke` は **SOP 投入 → AI 解析 → 撮影テイク → ffmpeg 合成 → mp4** の全段が
**実際に最後まで回ること**だけを機械で確認するコマンドである。bug-hunt レーン専用で、
起動導線は `scripts/bug-hunt-shard.sh pipeline-smoke --shard I --run-id TS`
(`BUGHUNT_ORCHESTRATOR=1` 必須 = 費用の防壁)。

### 実行を許す条件 (fail-secure。`--force` でも迂回できない)

1. `app()->environment('bughunt.local')` — 実 LLM / 実 ffmpeg / チケット消費を dev / production で走らせない
2. `BughuntDatabaseGuard::isBughuntDatabase()` — dev DB へ fixture をばら撒かない
3. `FakeStorageGate::enabled()` — 実 S3 へ書かない
4. `config('testing.fake_llm') === false` — fake のまま「通った」と報告しない

4 は**自プロセスの config** であり worker の設定は見ていない。worker が fake なら
`llm_call_logs` の記録行が 0 になり `llm-evidence` 段で落ちる (2 層で守る)。
確認プロンプトは `confirmToProceed($warning, true)` で**常に**出す (既定 callback は
production でしか確認しないため、bughunt.local では確認なしで課金が走ってしまう)。

### 段と成功条件 (**これだけを見る**)

| 段 | 成功条件 |
|---|---|
| `preflight` | ffmpeg / ffprobe 実行可 ∧ queue connection 2 本 ∧ SOP fixture ∧ 対象組織 (所属 user ∧ 残高 4 枚) |
| `fixture` | manual が `draft` ∧ `source_documents` 1 件 |
| `analysis` | `analysis_jobs.status = succeeded` ∧ `video_manuals.status = ready` ∧ `cuts` ≥ 1 ∧ `scenario_version` ≥ 1 |
| `llm-evidence` | 3 template それぞれに成功行 (`failure_reason IS NULL` ∧ `input_tokens > 0`) があり、**そのすべてが `metadata_missing = false` ∧ 期待した organization / subject を持つ** |
| `capture` | 全 cut に採用テイク (`ready`) がある |
| `render` | `render_jobs.status = succeeded` ∧ `video_manuals.status = published` ∧ `output_path` 非 NULL |
| `artifact` | 出力を読み出せ、ffprobe が 0 終了し、映像ストリーム ≥ 1 ∧ 尺 > 0 |

「この実行分」は `llm_call_logs.id > baselineId` で切り出す (`baselineId` は preflight 通過直後・
`fixture` 段の前に 1 回だけ取る)。`llm-evidence` の母集団は `whereIn('prompt_template', 3 template)`
で絞る (同 shard で他の prompt が走っても混ざらない)。

### 失敗分類 (`SmokeFailureClass`。観測のためであり制御フローを変えない)

`preflight` / `wiring` / `stage_timeout` / `llm` / `render` / `storage` / `unknown`。
判定は `App\Support\Smoke\SmokeFailureClassifier::classify()` の純関数 1 本で、判定順は
「成功段は分類しない → preflight → timeout×queued=wiring / timeout×running=stage_timeout →
render の error_code → artifact の読めない=storage / ffprobe 失敗=render →
**llm-evidence で成功行はあるが記録が不完全=wiring** → LLM 起因になり得る段だけ llm → unknown」。

- **`llm` は `analysis` / `llm-evidence` に閉じる**。他の段の失敗を provider のせいにしない
- **記録の不備 (帰属欠落 / 必要 template の成功行欠落) は `wiring`**。
  `llm` に混ぜると「レート制限で落ちた」と「`withMetadata()` を書き忘れた」が同じ札になる
- リトライは最終的に成功しても `failure_reason` 行を残すため、**成功した段は分類しない**

### LLM 呼び出しの帰属 (記録側の配線)

**実行経路を持つ** `app/Prompts/` の factory は `LlmCallContextData` を**必須引数**で受け、
窓口 (`PromptDefense::load()`) へ渡す。窓口が `withMetadata()` で `organization_id` / `user_id` /
`subject_type` / `subject_id` を載せる。AI 解析では subject = **`VideoManual`**
(費用を知りたい単位は成果物であって job ではない)。禁止事項 5 (LLM 呼び出しは factory → 窓口 →
実行単位の 1 本道のみ) を gate が強制しているため、**静的に書かれた通常の呼び出し経路では
帰属の迂回ができない**。記録層の列は 1 本も増やしていない。
なお gate が見るのは静的な出現だけで、反射・動的に組み立てたクラス名・文字列キーだけの
container 解決には沈黙する (§LLM プロンプト防御の窓口方式 の「保証しないもの」)。

**適用範囲を誇張しない**: 帰属の対象を持たない見本 (`ExampleSummaryPrompt`。呼び出し元が無い) は
`PromptUntrustedInputContractTest` の inventory へ**帰属キーを空配列で exempt 登録**してある。
つまり「全 factory が必須引数を持つ」のではなく「**inventory に帰属キーを登録した factory が
必須引数を持つ**」であり、exempt にする操作は deny-by-default の inventory 変更として
レビューに必ず現れる。

3 層で固定する: **型** (必須引数 = PHPStan level 10) / **構造**
(`PromptUntrustedInputContractTest` が組み立て済み Prompt の `metadata_context` を reflection で検査) /
**実地** (本 smoke の `llm-evidence` 段)。

### LLM コストレポート

集計は `LlmCostReportService` 1 本で、入口は 2 つ (**1 実装・複数入口**):
smoke 末尾の「この実行分」と `operations:llm-cost-report` の期間集計。

- 軸は `LlmCostGroupBy` の 4 つ (`prompt_template` / `model` / `organization` / `subject`)。
  すべて**素の列 GROUP BY** で、GROUP BY キーへ SQL 関数を適用しない
- **USD が主** (`total_cost_usd` は `pricing_snapshot` から決定的)。
  **JPY は副**で、期間合計は「各行の記録時レート (`fx_snapshot`) での合計」であり
  単一レートで USD を換算した値ではない
- **未解決 (null) は 0 に潰さない**。件数 (`usd_unresolved_calls` / `jpy_unresolved_calls`) で別に返す
  (整数集計列だけ `COALESCE(SUM(...), 0)` を掛ける = 0 件時の TOTAL が TypeError にならない)
- 期間は**半開区間 `since <= created_at < until`** で **UTC 解釈** (JST とは 9 時間ずれる)。
  日付のみの `--until` はその日を含む (排他境界を翌日 0 時にする)
- `metadata_missing_calls` は**帰属配線の健全性シグナル**である (0 でないなら呼び出し側の配線が欠けている)

### 保証しないもの (誇張しない)

1. **生成物の品質は一切保証しない**。判定しているのは「期待した状態遷移が起きたか」だけ
2. **実 S3 は検証していない**。通るのは `FakeObjectStore` の checksum 三者一致だけ
3. **ブラウザ (撮影 PWA) の実機経路は検証していない**。CLI から Service を呼んでいる
4. **worker プロセスの LLM モードを直接は見ていない**。`llm_call_logs` の記録行の存在で
   間接的に実呼び出しを実証している
5. **費用は「この実行で記録された行の合計」**であり provider 側の請求額とは一致しない
6. **帰属メタデータが「イベント経由で `llm_call_logs` に記録されること」はテストレーンでは
   検証できない** (`Prompt::$fake` は `executePrism()` の先頭で短絡して
   `PromptExecutionCompleted` を発火せず、`PromptFake::record()` は metadata を記録しない)。
   テストレーンで検証できるのは「factory が組み立てた Prompt が `metadata_context` に
   帰属キーを持つこと」(reflection) までで、**listener を経て DB へ入ったことを確かめられるのは
   本 smoke の `llm-evidence` 段だけ**である
7. **並行実行に対する保証は無い**。「この実行分」は `llm_call_logs.id` の差分で切り出しており、
   同一 shard で別の LLM 呼び出しが並行すると混入する
8. **1 回通ったことは、次も通ることを意味しない**。実 LLM の出力は非決定的である

## 完成レンダ成果物の選択と受け取り口 (T154)

制作フロー最終段の「完成物の受け取り」は **DL 1 本**しかなく、アプリ内で観る手段が無かった
(`playback` は `kind=preview` 以外を 404 にしていた)。「思考ゼロ・編集ゼロ」を掲げながら
最後だけ外部プレイヤーを要求している状態を解消したのが本節の変更である。

### 選択式は 1 つ (`Services/Manual/CurrentRenderArtifact`)

「いま受け取れる成果物はどれか」は **`CurrentRenderArtifact::currentSucceeded(manual, kind)`
ただ 1 つ**が答える。定義は保持ポリシー
(`RenderJobService::newerSucceededExists` / `DeleteRenderOutputsJob`) と**同じ世代定義**である:

- 実体が残るのは「同 manual・同 kind の**最新 succeeded**」だけである
- したがって最新 succeeded の `output_path` が NULL (生成に失敗した / 掃除された) なら
  **旧世代へフォールバックしない**。旧世代の実体は削除済みなので、フォールバックは
  **壊れた署名 URL を返すことと同義**である
- **持たない責務**: published 判定と ability 判定は呼び出し側にある (名前が示す役割を超えない)

> これは「不具合を直した」ではなく「**定義を保持ポリシーへ揃えた**」である。
> `whereNotNull('output_path')` を先に効かせる旧式が本番データで実際に旧世代を
> 選んでいたかは**実測していない**。

### playback の 3 層 404 と kind→ability 写像

**route は 1 本も増やさない**。既存の `projects.manuals.render-jobs.playback` を
`kind=render` へ拡張する。manual 単位 URL にすると**再レンダ後も URL 文字列が変わらず**
ブラウザが古い媒体を再生しうるため、**job id を含む既存の形**を使う。

評価順は次のとおりで、**すべて認可より前に 404** (セキュリティ不変条件 2/10):

1. `{project}` ∈ current org (`project.in-current-org` middleware + inline guard)
2. `{manual}` ∈ `{project}` (`Route::scopeBindings()`)
3. `{renderJob}` ∈ `{manual}` (scopeBindings + inline 再検査 = 二重防御)

その後に**成果物の性質に合う ability** を評価する (2 値 enum の網羅 `match`。
到達不能な `else` を作らないので、`RenderKind` に case が増えたら PHPStan level 10 が落ちる):

| kind | ability | 追加条件 |
|---|---|---|
| `preview` | `render` | なし (**T154 で変えていない**) |
| `render` | `download` | `manual.status === published` (download と**同条件・同順序**) |

最後に「いま受け取れる成果物」と**同一行か**を照合する (旧世代 job id の直叩き・未完了・
実体削除済みはここで 404)。

**写像は `RenderPlaybackAbilityMappingTest` が behavioral に固定する**。本番 policy は
`VideoManualPolicy::render` と `::download` がどちらも `ProjectPolicy::update` に落ちるため
**可否が同値で観測差が出ない**ので、テスト専用 policy (`Tests\Support\Policies\DivergentVideoManualPolicy`)
を `Gate::policy()` で差し込み、ability ごとに可否を分岐させて写像を直接観測する。
「本番で意味のある権限差が既に存在する」とは言えない (固定できるのは写像が kind で
分岐していることまで)。

### props と endpoint は 1 対 1

詳細画面 props の `render.finishedJob` は、**endpoint が 302 を返す条件と 1 対 1** である
(`published` + `download` ability + 現行世代)。UI は `finishedJob !== null` **だけ**で
表示を決め、`canManage` を積まない:

- 秘匿境界は props 側に置く (判断を 2 箇所に持たない)
- `canManage` は `update` ability であり、`finishedJob` が既に運んでいる `download` ability とは
  別物である。積むと policy が分岐した日に**サーバが渡した成果物を UI が隠す**
- 「押すと 404」の導線を UI に出さない (`finishedJob=null` なら再生も DL も出さない)

`finishedJob` は**local state にしない**。render 成功時の `router.reload()` で props ごと
入れ替わる。ポーリング応答から組み立てる経路は作らない (応答は published / ability / 世代を
判定していない)。`<video preload="none">` なのは、詳細画面を開くたびに署名 URL 発行と
本体取得が走るのを避けるためである (完成動画は尺が長い)。

**完成動画に黒背景の注記は出さない**。`placeholder_cut_count` の値契約では succeeded render は
`0` であり、既存の `> 0` 条件では何も表示されない (完成動画用の分岐を新設していない)。

### 機械強制 (`CurrentRenderArtifactInventoryTest`)

`app/` 配下で **`render_jobs` に対する succeeded 条件つきの直接クエリ**を書いてよいファイルは
`Support/Security/RenderArtifactSelectionInventory` に登録されたものだけである
(deny-by-default・exact-fit)。区分 (`RenderArtifactSelectionKind`) と 30 文字以上の根拠が要る。

| 区分 | 意味 | 登録 |
|---|---|---|
| `Canonical` | 受け取り対象を 1 件選ぶ選択式の実体 | `Services/Manual/CurrentRenderArtifact.php` **のみ** |
| `SupersessionCriterion` | 世代交代の判定 (より新しい succeeded が在るか / 旧世代の収集) | `RenderJobService` / `RenderPipeline` |

`SupersessionCriterion` には**機械検査される前提**が付く: `latest(` / `orderByDesc(` を
1 度も持たず、かつ `where('id', '>' | '<', …)` の**連続 token 列**を持つこと
(「`id` と `>` が同一ファイルに在る」ではない)。前提が崩れた瞬間に区分ごと再審査になる。

### 保証しないもの (誇張しない)

- **撮影者 (project_member) は完成動画を観られない**。`download` ability は編集者のみのままで、
  本変更はそれを緩めない。「撮った人が結果に到達する」は**編集者について**成立する
- **kind→ability 写像は固定するが、本番 policy の差は今は存在しない** (上記)
- **シナリオ編集で `ready` に戻った manual の旧完成動画は、再生も DL もできない**。
  これは既存 download の挙動であり、本変更は**揃えるだけで改善しない**
- **既存 `playbackJob` (preview) の props 露出条件は変えていない**。`render` ability を持たない
  撮影者にも (UI では隠れているが) job の存在が渡る。`RenderJobData` は `output_path` も
  署名 URL も含まないため露出は「preview job が在ること」に留まる
- **Architecture gate が閉じるのはファイル粒度の直接クエリだけ**である。登録済みファイル内で
  メソッドを増やして選択式を書く経路・文字列変数経由・動的呼び出し・別ファイルへ切り出した
  同義式・repository を挟む間接経路には**沈黙する**。走査根は `app/` のみ
- succeeded 条件を伴わない別基準の選択 (表示用の最新 job = `VideoManualService::displayRenderJob`)
  はそもそも母集団に入らない (意図した設計)
- **署名 URL の TTL とその先の再生可否は保証しない** (`manual.render_playback_url_ttl_minutes`。
  長尺動画で TTL 切れの途中失敗が起きうるかは測っていない)
- **Browser lane は DOM 契約だけを検査する**。実際に mp4 が再生されること・S3 の CORS 設定・
  iOS Safari のインライン再生挙動 (`playsinline` 未付与) は確認していない
- **撮影 PWA からの戻り導線は `Capture/Show` ヘッダーの常設リンクとして実装済み** (T155。
  §撮影 PWA の運用契約)。ただし**完成動画へ直接着地するわけではない** — 行き先はマニュアル
  詳細画面で、そこに完成動画が出るかは本節の認可条件がそのまま決める (撮影者には出ない)

## LLM プロンプト防御の窓口方式 (T169 / 家系の裁定 AG-028)

外部由来の文字列 (SOP 本文と、そこから生まれた前段 LLM 出力の JSON) が prompt へ入る経路を
**1 本道**に畳み、その道の上で無害化・境界化・応答検査を行う。

### 経路 (静的に書ける道はこれだけ)

```
app/Prompts/{Sop,WorkDecomposition,ScenarioGeneration,ExampleSummary}Prompt
        │  make(生 string, LlmCallContextData)
        ▼
App\Support\Llm\PromptDefense                 ← 窓口 (唯一の入口)
        │  無害化 (UntrustedTextSanitizer)
        │  タグ境界化 (Kent013\PrismPrompt\Values\UserInput)
        │  合言葉の合流 (PromptCanary → system_prompt の {{ $llm_canary }})
        │  帰属の付与 (withMetadata。loadUnattributed だけが例外)
        ▼
App\Support\Llm\GuardedPrompt                 ← 実行単位 (唯一の出口)
        │  executeSync(): vendor 実行 → 応答の合言葉検査
        ▼  漏洩していれば PromptResponseRejectedException (応答は呼び出し元へ渡さない)
```

窓口の引数は**生の string の連想配列**である。呼び出し側が自分でタグ境界化の値オブジェクトを
作って渡す経路が型で消えており、実行単位は vendor prompt を返す公開メソッドを 1 つも持たない
ので、応答検査の迂回経路も型で消えている。

**限定**: 「これだけ」と言えるのは**静的に書ける経路**についてである。反射・動的に組み立てた
クラス名・文字列キーだけの container 解決で作った経路には gate が沈黙する
(本節末尾の「保証しないもの」)。

### 入力の無害化の分類 (**構造だけ**を扱う)

| 分類 | 対象 | 理由 |
|------|------|------|
| 保持 | 改行 `U+000A` / タブ `U+0009` / 通常の空白 | SOP の本文構造そのもの。消すと手順の区切りが失われる |
| 改行へ正規化 | `U+000D` (単独 / CRLF) / `U+2028` / `U+2029` | 行の区切りという意味は保つ (行数を変えない) |
| 除去 | その他の C0 / C1 / 双方向制御 (`U+200E` `U+200F` `U+202A`–`U+202E` `U+2066`–`U+2069`) / ゼロ幅 (`U+200B`–`U+200D`) / BOM | 人間には見えないのにモデルには渡る = 見えない指示の運び手になる |
| 拒否 | 無害化後の長さが `llm-defense.max_untrusted_bytes` 超過 / 不正な UTF-8 | 切り詰めると**黙って内容が変わる**。長さと壊れた符号化は拒否で扱う |

**「ignore previous instructions」等の文言は除去しない**。偽陰性と回避のいたちごっこになり、
正当な SOP 本文 (「前の指示は破棄する」という作業手順) を壊すためである。

### 長さ上限は 2 段で、順序を固定する

1. `manual.analysis_max_text_bytes` (150,000) — SOP 経路の運用上限。
   利用者向け文言「手順書が大きすぎます。分割してアップロードしてください。」が**先に**出る
2. `llm-defense.max_untrusted_bytes` (200,000) — 窓口の最後の砦。
   ここに当たること自体が異常事態の合図である

`LlmDefenseConfigGateTest` が **1 ≦ 2** を機械的に固定する (逆転すると分割案内が出なくなる)。

### 拒否の写り方 (`AnalysisPipeline::userMessageFor`)

| 例外 | 再試行 | 利用者向け文言 |
|------|--------|---------------|
| `UntrustedInputRejectedException` (`TooLarge`) | しない | `AnalysisFailedException::tooLarge()` |
| `UntrustedInputRejectedException` (`InvalidEncoding`) | しない | `AnalysisFailedException::unreadableEncoding()` |
| `PromptResponseRejectedException` | しない | `AnalysisFailedException::unsafeResponse()` |

`isTransient()` は deny-by-default なので 3 つとも自動的に非 retryable である。
合言葉の漏洩を再試行しないのは「同じ結果になるから」ではない (合言葉は毎回変わる)。
**安全性の違反が疑われる状態で、課金してまでもう一度モデルへ投げない**という判断である。
`unsafeResponse()` の文言が**原因を断定しない**のも同じ理由で、検知した事実は
「system 側の内容が応答に出た」ことだけであり、手順書が原因とは限らない
(原因を手順書だと書くと、正当な SOP の記述を利用者に削らせる誘導になる)。

### 集約設定 (`config/llm-defense.php`)

キーは `max_untrusted_bytes` / `canary_bytes` の 2 つだけで、**防御指示の文言も on/off スイッチも
env も置かない** (切れる防御は防御ではない / 環境ごとに緩められる経路を作らない)。
`LlmDefenseConfigGateTest` がキー集合・値の型・読み手クラスまでの双方向 pin・`env(` の字句不在を
固定する。env 検査を**字句**で行うのは、素の正規表現だと gate 自身やファイル冒頭の説明文の
"env" に反応するためである (家系の先行実装で実際に起きた事故)。

### gate の走査母集団 (検査ごとに違う。一括で「app/ だけ」とは言わない)

| 検査 | 母集団 | 理由 |
|------|--------|------|
| 呼び出し site (窓口 / vendor prompt 読み込み / 実行単位構築) | `app/` `routes/` `database/` `config/` `bootstrap/` の 5 根 | `routes/` のクロージャや seeder からの直接呼び出しは Prism 直呼びではないため、Prism 直呼び禁止の検査では捕まらない |
| 所有権 (内部部品を誰が参照してよいか) と reflection 系 | `app/` | アプリのクラス配置の問題である |
| — | `tests/` は常に母集団外 | テストが内部へ触るのは正当で、触る場所は `tests/Support/Llm/GuardedPromptInspector.php` 1 箇所に閉じている |

`PromptDefenseWindowGateTest` の変数集合突き合わせは YAML の `{{ $name }}` を正規表現で拾う。
これが成立するのは `PromptYamlContractTest` が prompt YAML に書ける Blade 式を
**単純変数展開と防御指示の静的呼び出しの 2 形**へ絞っているからである。
**構文契約が先、抽出は後**であり、契約側を緩めるなら抽出も同じ PR で見直す。

### 保証しないもの (誇張しない。**本節が正本**)

- **合言葉は「漏洩の検知」であって、プロンプトインジェクション一般の検出器ではない**。
  system 側の内容を吐かせずに悪性のシナリオを JSON として返させる攻撃は検知できない
- **非空白文字を挟んで分割された合言葉は検出しない** (`ab-cd…`)。検知は
  大小無視 + 空白除去の 2 パスまでである (この限界は単体テストで明示的に pin してある)
- **無害化は構造だけを扱う**。指示に見える文言は 1 文字も消さない
- **`max_untrusted_bytes` は上界の証明ではない**。2・3 段目の入力は前段 LLM 出力由来の JSON で、
  token 数からバイト数の上界は tokenizer 依存のため厳密には示せない。
  正常系の実測より十分大きい**防御上限**として置いている
- **gate が見るのは静的な出現だけ**である。文字列キーの container 解決だけの経路、
  動的に組み立てたクラス名、vendor 内部から出る呼び出しには沈黙する
- **窓口が守るのは prompt へ入る文字列まで**である。4 段目 (シナリオの反映) と
  ffmpeg 側の字幕描画は本節の対象外
- **trusted 変数の入口は存在しない**。作る必要が出たときの義務は
  `docs/template-divergence.md` D16 が正本

## 組織アクセスの失効 (T174 / 家系の正典 v2)

組織の中で誰かの役割が変わったとき、その人がその組織で持っている「人に委ねられた資格情報」を
**その場で・同じひとまとまり (トランザクション) の中で**失効させる。

### 境界

失効の境界は **「役割を変える操作が成功したこと」** である。**役割の集合の差分は取らない**。
差分を取ると権限ライブラリの役割キャッシュ (本番で 1 時間有効) に依存した判定になり、
取りこぼしたときに通してしまう側へ倒れるためである。

帰結として **昇格でも接続はやり直しになる**。これは代償を承知で選んだ既知の仕様であり、
監査の理由 (`OrgAccessRevocationReason`) に「オーナー移譲の受け手」が独立した case として
あるのは、この驚きをサポート時に 1 行で説明できるようにするためである。

### 窓口と配線

- 窓口は `app/Services/OAuth/OrganizationAccessRevoker.php` **ただ 1 本**。
  呼び出し元のトランザクションの内側であることを実行時に検査する
  (深さが 0 なら例外。説明文とテストだけに頼らない)。
- 呼ぶのは `OrganizationMembershipService` の 4 経路
  (`changeRole` / `removeMember` / `transferOwnership` / `normalizeOrganizationRole`)。
  移譲は**譲り手と受け手の 2 回**呼ぶ。
- 役割を書き込むのに呼ばない経路は `OrgAccessRevocationExemption` へ 30 文字以上の根拠付きで
  登録する (既定拒否)。現在の登録は招待受諾 (`joinOrganization`) の 1 件だけで、
  理由は「入れる操作の時点でその人がその組織で持つ資格情報は構造的に 0 件」である。

### 失効する 3 家族 (途中で打ち切らない)

| 家族 | 対象 | 落とすと起きること |
|---|---|---|
| 1 | `oauth_sessions` の未失効行 | 一覧・actor 解決の失効印が残らない |
| 2 | `oauth_access_tokens` と紐づく `oauth_refresh_tokens` | 更新トークンで再発行できてしまう |
| 3 | 未交換の `oauth_auth_codes` | 失効の直前に出た認可コードを後から交換できてしまう |

家族 2 は **セッション id で絞らない** (セッション行を持たない古いトークンが生き残るため) し、
**母集団を「未失効の利用トークン」に絞らない** (親が失効済みで子だけ未失効の不整合行を
取り逃すため)。絞るのは件数を数える更新文の側だけである。

### 監査

`SecurityEventType::OrganizationAccessRevoked` を `recordOrFail` で記録する。
書けなければ役割の変更ごと巻き戻る (「資格情報は失効したが監査に残っていない」状態を作らない)。
**失効 0 件でも 1 行残す** — 記録が無いと「窓口が呼ばれなかったのか / 対象が無かったのか」を
区別できないためである。metadata は組織 / 操作した人 / 理由 / 家族ごとの件数。

### 保証しないもの (誇張しない。**本節が正本**)

- **失効の選択と確定の間に新しい資格情報が発行される隙間は閉じていない**。
  発行の経路は組織行・利用者行のロックを取らないためである。最後の拒否線は要求ごとの再評価
  (`ResolveApiActor::contextFromUserToken` / `McpAuthorizationContext::for`) が受け持つ。
- **組織の API キーは失効させない**。**発行した人が組織から外れても、その鍵の読み取り権限は残る**
  (書き込みは `ProjectPolicy` の実行時評価で 403 になる)。この非対称を「防御がある」と丸めない。
  鍵を止める手段は組織管理者による失効操作である。所属の再評価を足すと発行者の退職で
  組織の自動連携が無言で止まるため、**別の判断として独立に起こす**。
- **プロジェクト単位の役割は失効の境界に入れない**。トークンの結び付き先は組織であり、
  その人はまだ組織のメンバーだからである。
- **静的検査は「呼び出しの字句が在ること」と「その位置」までしか見ない**。
  途中に早期 return や条件分岐を足せば、gate は緑のまま失効しない経路が生まれる。
  実挙動は `tests/Feature/Organizations/OrganizationAccessRevocationTest.php` が担う。
- 失効列の検査は「資格情報 4 表の名前を文字列で持つファイル」×
  「`->update(` / `::update(` / `->forceFill(` の**引数**に失効列がある」の積で判定する。
  **「窓口が 1 本であることの証明」ではなく「検出できる書き方に限った見張り」である**。
  表の名前を字句として持たない経路・属性への直接代入・生 SQL・列名を変数で組み立てる形には
  沈黙する。
- **認可コードの交換時に所属を確認してはいない**。閉じているのは「失効の時点で未交換だった
  コードを撃つ」ところまでである (後続の候補)。
