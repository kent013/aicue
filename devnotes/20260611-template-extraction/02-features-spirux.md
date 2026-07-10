# spirux 機能インベントリ

> 出典: `tmp/spirux/docs/` 配下(architecture / models / services / routes / frontend /
> concepts/function_summary / billing-quota / quota / mcp / audit-pipeline)+ app/Models・app/Enums・composer.json の実コード確認。
> タグ: [汎用] = テンプレート化候補 / [固有] = spirux ドメイン固有。
>
> ⚠ 注意: docs の一部(billing-quota 等)は実コードより古い。実コードには aigenba から整列済みの
> チケット課金モデル(Plan / PlanPrice / TicketLedgerEntry / TicketPrice / Subscription /
> StripeWebhookEvent / StarterMigrationConsent)が存在する。

## アプリ概要

AI UX 評価プラットフォーム。Playwright によるページレンダリング+LLM で、Web サイトをヒューリスティック
(UX ハニカム 6 軸)・SEO・体験シナリオ(ペルソナ+Playwright エージェント)の 3 レイヤーで自動評価し、
スクリーンショット上に指摘を描画するレポートを生成する B2B SaaS。

## 組織構成モデル

```
Organization(テナント、Laratrust Team と 1:1)
 ├── OrganizationQuota / OrganizationUsage(リソース上限・月次利用量)
 └── Project(組織直下。部門層なし)
      ├── ProjectMember(pivot: project_admin / project_member)
      ├── Site → Page → PageRender / Evaluation → EvaluationResult
      ├── Persona
      └── Scenario(project 配下固定、NOT NULL)

ロール: organization_owner / organization_admin / organization_member(Laratrust)
        project_admin / project_member(pivot)
        admin guard: super_admin(AdminUser)
```

## 機能一覧

### 認証・アカウント
- メール+パスワード登録・ログイン [汎用]
- ソーシャルログイン(GitHub / Google、SocialAccount) [汎用]
- 2FA(TOTP、Fortify) [汎用]
- メール検証 / メール変更(pending_email + トークン。Fortify レンジへの簡素化提案あり=HM1/R6) [汎用]
- パスワードリセット・変更(旧メールへの変更通知あり) [汎用]
- step-up 再認証(recent-auth) [汎用]
- アカウント削除(カスケード) [汎用]
- CipherSweet PII 暗号化 + blind index [汎用]

### 組織・メンバー管理
- 組織作成・切替(current_organization_id)・設定・削除 [汎用]
- オーナー移譲(行ロック付きトランザクション) [汎用]
- メンバー招待(7 日有効トークン)・既存ユーザー追加 [汎用]
- ロール変更・削除、細分化権限(api-keys-manage / billing-manage) [汎用]
- Project メンバーシップ(明示登録、Owner/Admin は暗黙全アクセス) [汎用]

### 権限(RBAC)
- Laratrust team-scoped RBAC(strict_check=true) [汎用]
- Policy ベース認可(Organization / Project / Site / Evaluation 等) [汎用]
- Tenant-Boundary Precondition(TBP、cross-org 操作防止) [汎用]
- `Route::scopeBindings()` による nested route IDOR 防御 [汎用(方式は判断対象)]
- API Key ability ベース制御(read / write / evaluations:run 等) [汎用]

### サイト・ページ管理(ドメイン)
- Project / Site / Page CRUD(base_url、業種・サイト種別属性) [固有]
- ページ自動探索(sitemap / クロール / 手動、DiscoverSitePagesJob) [固有]
- サイトコンテキスト調査(企業・市場情報の Web 検索、ResearchSiteContextJob) [固有]
- Playwright レンダリング(PageRender、desktop/mobile、1h キャッシュ、analysis_data) [固有]
- スクリーンショット分割(sharp)・配信(権限チェック+private cache) [固有]

### ペルソナ・シナリオ(ドメイン)
- Persona CRUD(年齢・性別・IT リテラシー・利用目的) [固有]
- Scenario CRUD(start/goal URL、ビューポート指定) [固有]

### 評価(ドメイン、3 レイヤー)
- ヒューリスティック評価(UX ハニカム 6 軸、チェックリスト+指摘、両ビューポート) [固有]
- SEO 評価(静的チェック+LLM 改善提案、ヒューリスティックに併走) [固有]
- 体験シナリオ評価 scenario_walk(Playwright エージェント+Anthropic SDK 直呼び、
  inner_speech / frustration / goal_progress をターン別記録) [固有]
- 一括評価(bulk)・評価ジョブキュー・ステータスポーリング・エラー分類 retry [固有]
- チェックリストマスタ同期(Google Sheets → YAML renderer) [固有]

### レポート・ダッシュボード(ドメイン)
- ページ統合レポート / レーダーチャート / スクリーンショット上への指摘描画 [固有]
- 前回比較(スコア差分・新規/解消指摘) [固有]
- 共有リンク(ログイン不要)・PDF 書き出し(dompdf、日本語フォント) [固有(共有リンク機構は汎用)]
- ダッシュボード統計・インサイト・推奨アクション・スコアトレンド [固有]
- 評価完了の database 通知(通知センター) [汎用(機構)]

### 課金・Quota(Stripe / Cashier)
- Plan / PlanPrice / Subscription / StripeWebhookEvent / TicketLedgerEntry / TicketPrice /
  StarterMigrationConsent(aigenba と整列済みのチケット課金。Models/ 直下フラット配置) [汎用]
- BillingNotification(dedup_key) [汎用]
- Checkout Session / Customer Portal / Webhook 処理 / price catalog(lookup_key)同期・検証 [汎用]
- free プラン廃止 runbook(billing-free-removal) [固有(履歴)]
- OrganizationQuota / OrganizationUsage(多次元リソース上限:
  max_projects / max_sites_per_project / max_evaluations_per_month / max_members /
  max_personas / max_scenarios / max_api_keys / report_export_enabled 等) [汎用(項目は固有)]
- QuotaService(check / executeWithQuotaCheck / reserve-release accounting) [汎用]
- Quota 超過 402 レスポンス・利用状況バー UI [汎用]
- Stuck reservation repair cron(10 分間隔) [汎用]

### API キー
- API Key 発行・失効・平文露出制限(T-SEC-12) [汎用]
- ability ベース(read / write / evaluations:run、フラット) [汎用(命名は固有)]
- Idempotency-Key 全配線(T-SEC-09、CRUD + pages:bulk) [汎用]

### REST API v1 / MCP / CLI
- nested route 方式 `/api/v1/projects/{project}/scenarios` 等(route param 由来 scope) [汎用(方式は判断対象)]
- 統一エラー envelope(code/message/status/details、docs/api-errors.md) [汎用]
- rate limit バケット(api-read / api-write / api-status / api-mcp) [汎用]
- CLI capture submission(audits:submit、GHA テンプレート同梱) [固有]
- Laravel MCP + Passport OAuth 2.1、tool 9 種(whoami / list_* / show_* / run_evaluation) [汎用(tool は固有)]
- McpIdempotencyService [汎用]
- VerifyMcpOrigin(本番 bare `*` 拒否ガード付き) [汎用]
- CLI / MCP セットアップ snippet 画面 [汎用]

### 管理画面(Filament 5、AdminUser モデル + admin guard)
- AdminUser 管理(TOTP 2FA) [汎用]
- Organization / OrganizationQuota(override)/ User / Project / Site / Evaluation 管理 [汎用(ドメイン分は固有)]
- ModelAudit 閲覧 [汎用]

### 通知・メール
- 招待・検証・リセット・メール変更(新旧両宛)・パスワード変更通知 [汎用]
- 評価完了通知(database) [固有]
- LLM defense alert(mail、T-SEC-07) [汎用]
- EmailSuppression [汎用]

### お問い合わせ
- Inquiry 作成・削除 runbook [汎用]

### セキュリティ機構
- mass-assignment 入口防御: ProhibitsProtectedKeys trait
  (organization_id/project_id/team_id/site_id/page_id/evaluation_id/current_organization_id/
  user_id/created_by_user_id を `missing`)+ Architecture テスト [汎用(方式は判断対象)]
- SSRF 防御(PinnedHttpClient / UrlSafetyGuard / SubresourceHostPin) [汎用]
- LLM 防御: EvaluationVariableShield(untrusted 窓口集約)+ config/llm-defense.php
  (tool allowlist / alert 先)+ prompt canary 埋込・leak 検出・defensive gate [汎用]
- LlmCallLog(org 別コスト USD→JPY) [汎用]
- SecurityAuditEvent / critical action 記録 [汎用]
- RedirectToHttps middleware(app 層 308) [汎用(インフラ前提は判断対象)]
- cache serializable_classes = false(全 deny) [汎用(既定値として推奨)]
- debug login route 非登録検証(T-SEC-16)・production:preflight(T-SEC-04) [汎用]
- supply-chain 監査(review-checklist / accepted-advisories / socket-dev) [汎用]
- audit-pipeline(多角監査の運用ドキュメント) [汎用]

### 運用・デプロイ
- release workflow(pre/post-deploy 検証) [汎用]
- Lightsail demo セットアップ [固有(環境)]
- Stripe price catalog 同期・検証 command [汎用]

### テスト・CI・開発ツール
- Pest / Dusk(DuskFakesServiceProvider + canned LLM レスポンス)/ Vitest / larastan [汎用]
- Architecture テスト(FormRequestProhibitedKeyTest / MassAssignment 等) [汎用]
- Factory 規約(docs/factories.md) [汎用]
- 自走スキル群(spirux-autopilot / design / implement / todo-* / codex-review / update-docs /
  figma-design / figma-sync / figma-diff / design-review / sync-checklist) [汎用(figma 系は判断対象)]
- 差分レジストリ(docs/spirux-aigenba-divergence.md、aigenba 側が正本) [汎用(運用として)]
- Figma design system 連携(Code Connect / pixel-diff harness) [固有寄り]

## 技術スタック(実コード確認済)

- PHP ^8.3 / Laravel ^13.0 / Filament ^5.4 / Laratrust ^8.5 / Cashier ^16.5
- Fortify ^1.36 / Passport ^13.0 / laravel/mcp ~0.7.0 / Prism ^0.100.1
- kent013/laravel-prism-prompt、kent013/laravel-ssrf-pin、spatie/laravel-ciphersweet、
  owen-it/laravel-auditing、barryvdh/laravel-dompdf
- Svelte 5(Runes)+ Inertia + Tailwind 4 + Vite + Vitest、bits-ui、@lucide/svelte
- Node 側: Playwright + @anthropic-ai/sdk + tsx + sharp(render-page / scenario-walk)
