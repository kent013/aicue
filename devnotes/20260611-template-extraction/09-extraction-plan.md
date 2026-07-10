# 抽出実行計画

> **進捗 (2026-06-11)**: Phase 0 / 0.5a / 0.5b / 1 / 2a / 2b / 3 / 4 / 5 / 6 / 7 / 8 /
> 9a (AGENTS.md+docs) / 9b (スキル群) / 10 (README+init.sh+スモーク) **すべて完了**。
> 最終状態: Pest 184 / vitest 161 / PHPStan lv10 / Pint / build 全 green。
> 計画からの主な変更:
> - Phase 0.5 は a (DS 基盤) / b (コンポーネント) に分割
> - kent013/laravel-ssrf-pin は packagist 未公開のため見送り (vendoring 検討は将来)
> - MCP 認証は Passport OAuth でなく API キー Bearer の最小構成 (OAuth 移行手順をコメント化)
> - 課金の高度機能 (返金クローバック/席数/買い切り/自動移行/非同期決済) はレシピ化対象として未移植
> - Dusk/E2E ハーネス・デプロイ レシピ (Deployer/terraform)・通知センター UI は未導入 (残課題)

> `08-template-architecture.md` のモジュールを実際に組み上げる順序と各フェーズの完了条件(DoD)。
> 1 フェーズ = 1 worktree ブランチ単位を想定。各フェーズ末で「テンプレが起動し、全テスト green」を維持する。

## 進め方の原則

- **動くものを段階的に**: どのフェーズの完了時点でも `init.sh → migrate → serve` が通り、
  そこまでのモジュールの Pest/Vitest/PHPStan lv10/Pint/ESLint が green。
- **抽出 = 移植 + 汎用化**。ドナーからのコピーで始め、(1) ドメイン参照の除去
  (2) 名前の config 化(08 §5)(3) 決定事項との整合(05)の 3 点を必ず通す。
- **テストも同時に移植**。実装だけ持ってきてテストを後回しにしない
  (両アプリの「テストなし実装完了禁止」規約を踏襲)。
- 各フェーズの先頭で対象ドナーコードを読み、`devnotes/` にフェーズ設計メモ
  (持ってくるファイル一覧・捨てる依存・書き換え点)を残してから着手する。

## フェーズ一覧

### Phase 0: 基盤(M1)— ドナー: aigenba
Laravel 13 新規プロジェクト+ツールチェーン移植(mise/mprocs/init.sh/pnpm workspace/
Vite+Svelte5+Tailwind4/Pint/ESLint/PHPStan lv10/Pest/Vitest/Dusk env 分離)。
`config/template.php` の骨格。CI(lint+test+phpstan)。
**DoD**: 素の welcome 画面が起動。全 lint/test ハーネスが空テストで green。
EnvExampleInvariantTest 同梱。

### Phase 0.5: UI 基盤・デザインシステム(M13)— 機構: aigenba / 部品: spirux
DESIGN.md 雛形(ニュートラル既定テーマ、中間粒度)、tokens.css(@theme+@utility ramp)、
inventory/tokens/canonical-source-parity テスト、ds-purity 統制(普遍ルール+テーマ由来ルール分離、
層別テスト、構造化 allowlist 空)、Atomic Design 5 層の骨格、コア atoms/molecules/organisms
(Button/Input/FormField/Badge/Card/Select/Toggle/Table/Tabs/Pagination/Modal/ConfirmDialog/
Toast+flash-to-toast 等を既定テーマ token で実装)、AuthLayout/GuestLayout/AppLayout 骨格、
better-tailwindcss eslint。
**DoD**: 全部品が DS-pure(allowlist 0 件)で purity/parity テスト green。
テーマ差し替え(tokens.css の色値変更)だけで全部品の見た目が変わることをスモーク確認。

### Phase 1: 認証・アカウント(M2)— ドナー: aigenba(画面は spirux + FormField 統一)
Fortify 一式、Socialite(config 駆動プロバイダ)、SocialAccount、step-up、
メール変更(Q11)、CipherSweet+blind index、アカウント削除(spirux から)。
**DoD**: 登録→2FA→ログイン→メール変更→削除が画面で動く。RecentAuthRouteTest /
SsoBoundaryTest 移植。Personal Organization 生成は Phase 2 へのフックだけ置く。

### Phase 2: 組織・Team・Project(M3)— ドナー: aigenba ★Default Team 実装
3 階層スキーマ、Default Team パターン(06 の不変条件・Factory 規約・部分 unique index)、
Personal Organization 自動生成、招待、ロール enum+シーダー(Q2/Q10 命名)、
strict_check=true(Q6)、暗黙権限継承、オーナー移譲(spirux の行ロック版)、
teams_visible フラグと Team 管理ルートの条件登録。
**DoD**: teams_visible=false で組織→プロジェクトの 2 階層 UI が動き、
=true で Team 管理が現れる。06 記載の不変条件テスト 4 種が green。
OrganizationLifecycleInvariantTest 移植。

### Phase 3: セキュリティ層(M4)— ドナー: spirux ★最重要フェーズ
ProhibitsProtectedKeys(custom_team_id 含む集合で)、URL 整合 guard+org-scoped 解決
(aigenba から)、NestedRouteIdorDefenseTest(両アプリ版を統合した inventory 形式)、
MassAssignment 系(AST スキャナ+strict+出口規約)、ModelDirectFetchInvariantTest、
SSRF pin、SecurityHeaders、RedirectToHttps+env フラグ、serializable_classes=false、
production:preflight、debug route 非登録検証、cross-org invariant の Service+DB CHECK 雛形。
**DoD**: サンプルリソース(下記 Phase 4 で導入予定の `Item` の前身でも可)1 件が
全 inventory テストに登録された状態で green。`docs/template-divergence.md` 空レジストリ設置。

### Phase 4: サンプルドメインリソース(08 §1)
Project 配下の `Item` を「正しい追加の見本」として実装(migration/Model/Policy/
FormRequest/nested route/Svelte 画面/Factory/Feature テスト/各 inventory 登録)。
**DoD**: 07 のガイドに従って LLM が新リソースを追加する手順が、Item を見本に再現可能。
ここで 07 ガイドの記述と実コードの食い違いを洗い出して 07 を改訂する。

### Phase 5: 課金・Quota(M5)— ドナー: aigenba(Quota のみ spirux)
Cashier、Plan/PlanPrice(`Models\Billing\`)、Checkout Saga、Webhook 冪等マシン、
チケット台帳、返金クローバック、BillingNotification、cron 群、
OrganizationQuota/Usage/QuotaService(spirux)、価格 catalog 同期+検証 command。
プラン定義シーダー(チケット付与数+Quota 値。サンプル 2 プラン)。
**DoD**: Stripe test mode で checkout→webhook→チケット付与→Item 作成時の
Quota check が end-to-end で動く。WebhookAsyncDispatchInvariantTest /
StripePriceCatalogFixtureInvariantTest / QuotaOverride 系テスト移植。
席数・買い切り・自動移行はレシピ docs(`recipes/seat-billing.md` 等)としてここで書く。

### Phase 6: API・API キー・MCP(M6+M7)— ドナー: spirux
API キー(flat ability、prefix config 化)、Idempotency-Key 配線、エラー envelope、
rate limit 4 バケット、`/api/v1/projects/{project}/items` (サンプル)、whoami/version、
MCP(Passport OAuth、VerifyMcpOrigin spirux 版、tool 雛形 4 種を Item 対象で)、
onboarding snippet 画面。PlatformApiKey はオプションモジュール+レシピ。
**DoD**: API キー発行→Item CRUD→MCP tool 呼び出しが通る。
FormRequestProhibitedKeyTest に API 系 Request が全登録。

### Phase 7: LLM コア(M8)— 折衷
Prism 設定、prompt YAML 規約+PromptYamlContractTest、UserInput 型+
PromptUntrustedInputContractTest(aigenba)、PromptOperationGuardrail(aigenba)、
canary+defensive gate(spirux)、LlmCallLog+コスト記録、Prism::fake()/Dusk canned 規約。
サンプル prompt 1 本(Item の説明文を要約する等の無害なもの)。
レシピ docs: conversation-llm / llm-tools-allowlist / evaluation-shield。
**DoD**: サンプル prompt が fake で test green、生 string を prompt に渡すコードが
PHPStan で落ちることをテストで実証。

### Phase 8: 管理画面・通知・問い合わせ(M9+M10)
Filament 5+AdminUser+guard+2FA、Organization/User/Role/Plan/Subscription/Quota/
LlmCallLog/ModelAudit リソース、認証系・課金系メール、EmailSuppression、Inquiry、
通知センター(spirux)、SES+Mailpit relay(aigenba)。
**DoD**: 管理者ログイン→各リソース操作、メールが Mailpit に届く。

### Phase 9: 開発プロセス資産(M12)+運用 docs(M11)
`app-*` スキル群(両アプリの共通部を統合、パス/ID を frontmatter・config 参照に)、
AGENTS.md/CLAUDE.md 雛形、TODO 運用ファイル、docs 雛形一式、デプロイ 2 レシピ、
runbook 群、supply-chain 監査ファイル。07/06 を `docs/` へ移設して devnotes 側は凍結。
**DoD**: テンプレ上で `app-design → app-todo-add → app-implement` の 1 サイクルが
サンプル課題(Item への小機能追加)で完走する。

### Phase 10: 仕上げ
init.sh の対話初期化(08 §5 の範囲)、README、TEMPLATE_VERSION 機構、
新規アプリ生成のスモークテスト(クリーン環境で clone→init→migrate→全 test green)。
**DoD**: 第三者(別ディレクトリ)で 30 分以内に「動く新規アプリ」が立つ。

## 依存関係

```
P0 → P0.5 → P1 → P2 → P3 → P4 → P5 → P6 → P7 → P8 → P9 → P10
              └ P1 以降の全画面は P0.5 のコンポーネント・レイアウトを前提
                 └ P4 以降は P3 の防御層を前提
P5/P6/P7 は相互独立(P4 完了後なら並行可)。P8 は P5(課金リソース)に依存。
```

## 保留事項の解消タイミング

| 保留(05 より) | 解消フェーズ |
|---|---|
| 監査ログ正規形(AuditLog vs SecurityAuditEvent) | Phase 1 設計メモで実装比較して決定(認証イベント記録が最初に必要になるため) |
| ヘルプシステム同梱可否 | Phase 8 でオーナーに確認(管理画面・静的ページ群と同時期が自然) |
| ~~Figma 系スキルの扱い~~ | **解消済(2026-06-11)**: テンプレート対象外で確定 |
