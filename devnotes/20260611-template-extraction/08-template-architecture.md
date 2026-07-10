# テンプレート構成設計

> `05-decisions.md` の決定に基づく、laravel-claude-template 本体の構成。
> 「何を・どちらのアプリから・どういう形で」テンプレートに置くかを定義する。

## 1. テンプレートの形態

**「起動するアプリケーション」として提供する**(スケルトン生成器やパッケージ集ではなく)。

- `git clone` → `init.sh` → migrate/seed で、認証・組織・課金・API・管理画面が
  **ドメイン機能ゼロの状態で動く** Laravel + Svelte アプリ。
- ドメイン層は空に近い状態とし、参考実装として最小のサンプルリソース 1 つ
  (Project 配下の汎用エンティティ、例: `Item`)を同梱。§2 の全規約
  (nested route / prohibited keys / Policy / Factory / Architecture テスト登録)を
  満たした「正しい追加の見本」として機能させ、実アプリでは削除またはリネームして使う。
- アプリ固有値(アプリ名・API prefix・ドメイン)は config + `.env` + init スクリプトの
  置換で注入する。コード中にプロダクト名をハードコードしない。

採用バージョン: PHP ^8.4 / Laravel ^13 / Filament ^5 / Svelte 5 + Inertia + Tailwind 4。
パッケージは両アプリの共通集合(Fortify / Socialite / Passport / laravel-mcp / Cashier /
Laratrust / CipherSweet / laravel-auditing / Prism / laravel-prism-prompt / laravel-ssrf-pin)。

## 2. モジュール構成とドナー対応表

「ドナー」= 抽出元として実装を正とするアプリ。○ = 相手側から差分を取り込む。

| モジュール | 内容 | ドナー | 相手側から取り込むもの |
|---|---|---|---|
| **M1 基盤・開発環境** | Laravel 13 骨格、mise/mprocs/init.sh/kill-ports、pnpm workspace、Vite/Tailwind/Svelte、Pint/ESLint/PHPStan lv10/Pest/Vitest/Dusk(env 分離) | aigenba | spirux の lint 設定差分があれば強い方 |
| **M2 認証・アカウント** | Fortify(登録/2FA/リセット)、Socialite(プロバイダ config 駆動)、SocialAccount、step-up 再認証、メール変更(Fortify+旧宛先通知=Q11)、CipherSweet+blind index、Personal Organization 自動生成 | aigenba | spirux のアカウント削除カスケード |
| **M3 組織・Team・Project** | Org→CustomTeam→Project スキーマ、**Default Team パターン(06)**、招待、ロール(`organization_admin` / `project_admin` 系=Q2,Q10)、Laratrust strict_check=true(Q6)、暗黙権限継承 | aigenba | spirux のオーナー移譲(行ロック)、ProjectMember pivot 構造 |
| **M4 セキュリティ層** | ProhibitsProtectedKeys(Q3)、URL 整合 guard+org-scoped 解決(Q5)、cross-org invariant、SSRF pin、SecurityHeaders、RedirectToHttps+env フラグ(Q9)、serializable_classes=false、production:preflight、debug route 非登録検証 | **spirux** | aigenba の出口モデル層(MassAssignment AST/fillable 規約)、NestedRouteIdorDefenseTest は両者を統合 |
| **M5 課金** | Cashier、Plan/PlanPrice(`Models\Billing\`=Q10)、Checkout Saga、Webhook 冪等マシン、チケット台帳(2 フェーズ)、返金クローバック、BillingNotification、非同期決済/JCT、stale reservation cron、price catalog 同期 command | aigenba | spirux の price catalog 検証 command。**多次元 Quota(OrganizationQuota/Usage/QuotaService)は spirux がドナー**(Q7 で両方同梱) |
| **M6 API・API キー** | nested route 骨格(Q3)、flat ability(Q8)、API キー発行/失効/1 度きり表示、Idempotency-Key 全配線、統一エラー envelope、rate limit 4 バケット、whoami/version | **spirux** | aigenba の PlatformApiKey を**オプションモジュール**化 |
| **M7 MCP** | laravel-mcp+Passport OAuth、VerifyMcpOrigin(本番 `*` 拒否=D6)、McpIdempotencyService、whoami/list/show/run の tool 雛形、onboarding snippet 画面 | **spirux** | aigenba の tool 実装パターン(push/diff/apply)はレシピ docs 化 |
| **M8 LLM コア** | Prism 設定、prompt YAML 規約、UserInput 型+PHPStan 強制、prompt canary+defensive gate、PromptOperationGuardrail、LlmCallLog+コスト記録、Prism::fake()/Dusk canned 規約 | 折衷 | 型強制+guardrail = aigenba / canary+gate+Shield = spirux。会話履歴・tool allowlist はレシピ docs(Q4) |
| **M9 管理画面** | Filament 5、AdminUser(Q10)+専用 guard+2FA、Organization/User/Role/課金/Quota/LlmCallLog リソース、ModelAudit 閲覧 | spirux | aigenba の課金系リソース(Plan/Subscription/QuotaOverride) |
| **M10 通知・問い合わせ** | 認証系/課金系メール、EmailSuppression、Inquiry+削除 runbook、アプリ内通知センター(database channel)、SES 運用+dev Mailpit relay | 折衷 | 通知センター = spirux / SES relay = aigenba |
| **M11 運用・デプロイ** | release workflow(pre/post 検証)、デプロイ 2 レシピ(ALB / 単機=Q9)、Stripe 環境 runbook、顧客プロビジョニング script、security runbook | 折衷 | workflow = spirux / runbook 群 = aigenba |
| **M12 開発プロセス資産** | `app-*` スキル群(Q12)、AGENTS.md/CLAUDE.md 雛形、TODO.md 運用、devnotes 規約、`docs/template-divergence.md` フォーマット、docs 雛形(architecture/factories/testing-dusk/api-errors/supply-chain) | 折衷 | スキルは両者の共通部を統合し、パス/ID を frontmatter 化。design-review はオプション、Figma 系は対象外(確定) |
| **M13 UI 基盤・デザインシステム** | DESIGN.md 雛形(中間粒度=Q16)+ニュートラル既定テーマ(Q13)、tokens.css(@theme+@utility ramp)、parity テスト(inventory/tokens/canonical-source-parity)、ds-purity 統制(普遍/テーマ由来分離=Q15、層別テスト+構造化 allowlist)、Atomic Design 5 層(Q14)、コンポーネントセット(Table/FormField/Tabs/Pagination/Toggle/Modal/Toast 等)、flash→toast(visitKey)、レイアウト(サイドバー+組織切替+通知)、better-tailwindcss eslint | 折衷 | **機構=aigenba**(tokens/parity/purity/型分離/anchor 安全策)、**コンポーネント API・在庫=spirux**(FormField/iconOnly+ariaLabel/Inertia dual-mode/bits-ui)、スタイルは既定テーマ token で書き直し。詳細は `10-ui-design-system.md` |

オプションモジュール(既定 off、有効化手順を docs に同梱):
- PlatformApiKey(M6)
- Team 可視化(M3 — teams_visible フラグ、06 参照)
- ヘルプシステム(aigenba の manifest+audience 別 Markdown。保留 → 入れる場合は機構のみ)

テンプレート対象外(確定): Figma 連携(spirux の figma 系スキル・Code Connect・pixel-diff harness)。
必要なアプリが spirux から個別に持ち込む。

## 3. Architecture テストの抽出方針

テンプレートの価値の中核。両アプリのテストを 3 群に分類して扱う:

1. **そのまま同梱(汎用不変条件)**: FormRequestProhibitedKeyTest / MassAssignment 系(AST+strict+出口)/
   NestedRouteIdorDefenseTest(deny-by-default inventory 形式)/ ModelDirectFetchInvariantTest /
   PromptOperationGuardrailTest / PromptUntrustedInputContractTest / PromptYamlContractTest /
   RecentAuthRouteTest / EnvExample 系 / StripePriceCatalogFixtureInvariantTest /
   PolicyResolutionInvariantTest / OrganizationLifecycleInvariantTest / SsoBoundaryTest /
   WebhookAsyncDispatchInvariantTest
   → **inventory(対象リスト)部分を「サンプルリソース 1 件だけ登録された状態」で出荷**し、
   アプリがリソースを追加するたびに登録させる(07 §7 の運用)
2. **雛形化(構造は汎用・中身がドメイン)**: SubscriptionGatedRoutesTest 等
   → 対象を config/定数で差し替えられる形に書き直して同梱
3. **対象外(ドメイン固有)**: aigenba の Scenario*/Template*/Encounter*/Shared* 系、
   spirux の Evaluation 系 → 持ち込まない(「ドメイン不変条件もこう書く」見本として
   レシピ docs に 1 例だけ引用)

## 4. リポジトリレイアウト(目標形)

```
laravel-claude-template/
├── AGENTS.md / CLAUDE.md          # 雛形(アプリ名はプレースホルダでなく config 参照の書き方)
├── init.sh                        # アプリ名・prefix・DB 名等の対話的初期化
├── app/                           # M2〜M10 の実装(両アプリと同じディレクトリ規約)
├── config/template.php            # テンプレ設定の集約: app slug / api key prefix /
│                                  #   teams_visible / socialite providers / 有効モジュール
├── database/                      # migrations(3 階層+課金+quota+api keys+監査)、seeders(ロール/プラン)
├── docs/
│   ├── app-integration-guide.md   # 07 を移設(LLM 必読)
│   ├── default-team-pattern.md    # 06 を移設
│   ├── template-divergence.md     # 空のレジストリ(フォーマットのみ)
│   ├── recipes/                   # 逸脱・拡張レシピ集
│   │   ├── polymorphic-scope.md   #   多態スコープ(aigenba D1 型)
│   │   ├── conversation-llm.md    #   マルチターン対話 LLM
│   │   ├── llm-tools-allowlist.md #   LLM tools / config/llm-defense
│   │   ├── platform-api-key.md / teams-visible.md / seat-billing.md ...
│   ├── factories.md / testing-dusk.md / api-errors.md / architecture.md(雛形)
│   ├── operations/                # デプロイ 2 レシピ・Stripe runbook・security runbook
│   └── supply-chain/
├── .claude/skills/                # app-autopilot / app-design / app-implement /
│                                  # app-todo-add / app-todo-close / app-codex-review / app-update-docs
├── tests/Architecture/            # §3 の第 1・2 群
├── resources/js/                  # 認証・組織・課金・設定画面の Svelte 実装+UI 部品
└── scripts/                       # provision / deploy / codex ラッパ
```

## 5. 名前の注入方法

- **クラス名・テーブル名は汎用名で固定**(リネームさせない): AdminUser, SocialAccount,
  Organization, CustomTeam, Project, ApiKey, Plan, ...
- **表示名・prefix・ドメインは config**: `config/template.php` + `.env`
  (`TEMPLATE_APP_SLUG=myapp` → API キー prefix `myapp_`、メール差出人名、等)
- **init.sh が触るのは**: composer.json/package.json の name、`.env`、README、
  `config/template.php` の既定値のみ。ソースコードの一括置換はしない(置換漏れ事故を防ぐ)。

## 6. テンプレート更新の還流(将来運用)

- テンプレは独立リポジトリとして版管理(タグ)。
- 各アプリは生成時のテンプレ版を `TEMPLATE_VERSION` として記録。
- テンプレ更新の取り込みは「差分 PR を LLM が読み、app-integration-guide の規則で適用」する
  運用(git の subtree/merge には頼らない。生成後は別系統のコードベースになるため)。
- アプリ側で生まれた汎用改善は、aigenba↔spirux で実績のある「強い方へ寄せる」逆輸入手順で
  テンプレへ還流し、divergence は各アプリの `docs/template-divergence.md` に記録する。
