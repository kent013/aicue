# aigenba 機能インベントリ

> 出典: `tmp/aigenba/docs/` 配下(README / architecture / schema-reference / permission-design /
> multi-tenant-rbac-design / billing-logic / help/ / operations/)+ app/Models・app/Enums・composer.json の実コード確認。
> タグ: [汎用] = テンプレート化候補 / [固有] = aigenba ドメイン固有。

## アプリ概要

AI OJT シミュレーションプラットフォーム。シナリオで定義した対話の「場」を LLM が立ち上げ、
人間と AI(NPC)が対話するセッション(Encounter)を記録・採点・分析する B2B SaaS。

## 組織構成モデル

```
Organization(テナント、Laratrust Team と 1:1)
 ├── CustomTeam(部門)            ← spirux に無い階層
 │    └── Project
 │         ├── Scenario(project-scoped)
 │         └── Course/Scenario 割当
 └── Scenario(organization-scoped 共有)

ロール: system_administrator / organization_owner / organization_administrator /
        organization_member / project_tutor / project_trainee
        (OrganizationRole / ProjectRole / Role の enum 3 枚構成)
```

## 機能一覧

### 認証・アカウント
- メール+パスワード登録・ログイン [汎用]
- Google SSO(Socialite + JWKS 検証、アカウントリンク=UserSocialAccount) [汎用]
- 2FA(TOTP + リカバリコード、Fortify) [汎用]
- メール検証・メール変更(Fortify レンジ+旧アドレスへのセキュリティ通知 EmailChangedSecurityNotification) [汎用]
- パスワードリセット・変更 [汎用]
- step-up 再認証(機微操作前) [汎用]
- Personal Organization 自動生成 [汎用]
- 監査ログ(AuditLog: 認証・権限操作) [汎用]

### 組織・メンバー管理
- 複数組織の作成・切替・編集 [汎用]
- メンバー招待(メールトークン、OrganizationInvitation) [汎用]
- ロール管理(Owner / Administrator / Member) [汎用]
- CustomTeam(部門)の CRUD・メンバー管理 [固有寄り(階層自体が判断対象)]
- メンバー削除・オーナー移譲 [汎用]

### 権限(RBAC)
- Laratrust team-scoped RBAC(組織=team、常に team_id 明示で判定。strict_check=false) [汎用]
- Policy(Gate)による画面・API 認可 [汎用]
- 暗黙的権限継承(OrgAdmin 以上は配下 Project 自動許可) [汎用]
- ドメイン permission enum 群(CoursePermission / EncounterPermission / TeamPermission /
  DerivativePermission / PlatformPermission / ProjectApiKeyPermission) [固有(構造は汎用)]
- Scenario 3 状態モデル別権限(Project / Organization / Imported) [固有]

### プロジェクト・コース・シナリオ(ドメイン)
- Project CRUD(CustomTeam 配下) [汎用(配置階層は判断対象)]
- Course 作成・import・割当・コピー(JSON + CLI/MCP) [固有]
- Scenario CRUD・昇格(Project→Organization)・Project 配布・Usage 表示 [固有]
- SharedResource(platform / organization / project の多態スコープ配布) [固有]
- ScenarioSnapshot / ScenarioAssignment / Template(pipeline factory) [固有]
- Scenario JSON schema 検証(opis/json-schema) [固有]

### Encounter(学習セッション)実行(ドメイン)
- Encounter 起動・対話ログ(encounter_events append-only)・SSE 配信 [固有]
- NPC 応答生成・発話評価・進捗判定・ヒント生成・採点(LLM プロンプト 8 種) [固有]
- Trust スコア / Progress Guide / 完了条件判定 / リプレイ [固有]
- panelKind 複数種(meeting / conversation / oral_exam / self_assessment 等) [固有]

### AI・LLM 基盤
- Prism 統合(マルチプロバイダ: Anthropic / OpenAI) [汎用]
- kent013/laravel-prism-prompt(YAML プロンプトテンプレート) [汎用]
- Prompt Injection 防御: UserInput 型 + RenderedConversationContext 型強制
  (PHPStan level10 で生 string を弾く)+ PromptOperationGuardrailTest [汎用(機構)/[固有](会話文脈)]
- ConversationContextBuilder(マルチターン会話履歴の集約) [固有]
- EvaluationVariableShield 相当(spirux から HS5 で逆輸入済) [汎用]
- LLM コスト記録(LlmCallLog)+コスト監視 [汎用]
- Prism::fake() テストモック規約 [汎用]

### 課金(Stripe / Cashier)— app/Models/Billing/ 名前空間
- Plan / PlanPrice 管理(Starter / Standard / Business / Enterprise) [汎用(プラン内容は固有)]
- Checkout Saga(BillingCheckoutSession) [汎用]
- Webhook 冪等マシン(StripeWebhookEvent、重複・順序逆転対策) [汎用]
- Subscription / SubscriptionItem、プラン変更・解約・Starter→Standard 自動移行 [汎用(移行ルールは固有)]
- チケット 2 フェーズ消費(TicketReservation: reserve/commit/release、TicketLedgerEntry 台帳) [汎用(消費単位は固有)]
- TicketPurchase(買い切りチケット)・TicketPrice [固有寄り]
- 席数管理(additional_seats) [固有寄り]
- 返金クローバック・dispute/chargeback 通知・BillingNotification(dedup_key) [汎用]
- 非同期決済(コンビニ/銀行振込)・JCT(日本消費税 inclusive) [汎用]
- QuotaOverride(SystemAdmin による上限上書き) [汎用]
- Stale Reservation 解放 cron / Schedule 部分完了復旧 cron [汎用]

### API キー
- Organization API Key(`aigb_*`、scope: write_scenario/write_course/view_*/assign_scenario、暗黙包含階層) [汎用(scope 名は固有)]
- PlatformApiKey(`platform_*`、プラットフォーム運用キー) [固有寄り]
- 発行・失効・1 度きり平文表示(Session flash) [汎用]
- Capability ヘッダ(X-Aigenba-Capabilities)による negotiation [固有寄り]
- Idempotency-Key(IdempotencyKey / McpIdempotencyKey) [汎用]

### REST API v1 / MCP / CLI
- `/api/v1/scenarios|courses|shared/*` CRUD + diff/apply/import-dry-run(body discriminator 方式) [固有]
- `/api/v1/me` / `version` (whoami) [汎用]
- Laravel MCP サーバー + Passport OAuth [汎用]
- MCP Tool 群(scenario/course/shared push・diff・apply、audit log、template) [固有]
- VerifyMcpOrigin(Origin allowlist、提示時 fail-closed) [汎用]
- Onboarding スニペット(MCP/CLI 導入ガイド画面) [汎用]

### 管理画面(Filament 5、Admin モデル)
- Organization / User / Role / Permission 管理 [汎用]
- AuditLog / LlmCallLog 閲覧 [汎用]
- Plan / Subscription / QuotaOverride / API Key 失効管理 [汎用]
- Encounter / Derivative / Template 管理 [固有]

### 通知・メール
- 招待・検証・リセット系メール [汎用]
- 課金系通知(支払い失敗 / dunning / dispute / リマインダ) [汎用]
- EmailSuppression(配信停止) [汎用]
- SES 運用(dev は Mailpit relay) [汎用]

### お問い合わせ
- Inquiry 作成・メール送信・削除 runbook [汎用]

### セキュリティ機構
- CipherSweet PII 暗号化 + blind index(users.email/name、invitation.email) [汎用]
- mass-assignment 入口防御: 狭い universal trait(actor/team/secret キー `missing`)
  + per-request scope 制約 + 出口モデル層($fillable 不含 / AST 禁止) [汎用(方式は判断対象)]
- nested route IDOR 防御: URL 整合 404 guard(Web)+ org-scoped 解決(API) [汎用(方式は判断対象)]
- cross-org invariant(Service + DB CHECK の多層) [汎用]
- SSRF 防御(kent013/laravel-ssrf-pin) [汎用]
- SecurityHeaders(HSTS 等)・HTTPS は ALB 終端前提 [汎用(インフラ前提は判断対象)]
- cache serializable_classes 最小 allowlist(書誌検証 DTO のみ) [汎用(既定値は判断対象)]
- Webhook 署名検証 [汎用]
- supply-chain 監査(review-checklist / accepted-advisories.yaml) [汎用]

### ヘルプ・マニュアル
- docs/help(manifest.json + audience 別 Markdown ページ、CommonMark 描画) [汎用(中身は固有)]

### 運用・デプロイ
- 本番デプロイ手順(Deployer、ALB + SES + S3) [汎用]
- Stripe 環境セットアップ / 価格変更 runbook [汎用]
- セキュリティ runbook / 顧客プロビジョニング(YAML + script) [汎用]
- worktree 分離戦略 / pnpm global virtual store runbook [汎用]

### テスト・CI・開発ツール
- Pest / Dusk(env 分離)/ Vitest / PHPStan level 10(larastan) [汎用]
- Architecture テスト(mass-assignment / IDOR inventory / prompt contract / SSO boundary) [汎用]
- Factory 規約(docs/factories.md) [汎用]
- mprocs 開発環境 / init.sh / kill-ports.sh / mise [汎用]
- 自走スキル群(aigenba-autopilot / design / implement / todo-* / codex-review / update-docs) [汎用(名前空間は固有)]
- 差分レジストリ運用(docs/aigenba-spirux-divergence.md) [汎用(運用として)]

## 技術スタック(実コード確認済)

- PHP ^8.4 / Laravel ^13.0 / Filament ^5.0 / Laratrust ^8.5 / Cashier ^16.0
- Fortify ^1.36 / Passport ^13.0 / laravel/mcp ~0.7.0 / Prism ^0.100.1
- kent013/laravel-prism-prompt、kent013/laravel-ssrf-pin、spatie/laravel-ciphersweet、owen-it/laravel-auditing
- Svelte 5(Runes)+ Inertia + Tailwind 4 + Vite + Vitest、pnpm workspace
