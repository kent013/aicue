# テンプレート設計の決定事項(2026-06-11 オーナー確定)

`04-template-open-questions.md` の Q1〜Q12 に対するオーナー回答。以後のテンプレート設計は本書を正とする。

| # | 論点 | 決定 |
|---|---|---|
| Q1 | 組織階層 | **aigenba 型の 3 階層**: `Organization → CustomTeam(部門) → Project`。Personal Organization 自動生成も aigenba 型に従う。**部門概念が不要なアプリではスキーマは変えず、組織ごとに自動生成する Default Team で表示上スキップする**(詳細仕様: `06-default-team-pattern.md`) |
| Q2 | プロジェクトロール命名 | **`project_admin` / `project_member`**(汎用名)。権限の中身・ドメイン的リネーム(tutor/trainee 等)はアプリ毎。ロール/permission enum+シーダーは 1 箇所で差し替え可能な構造にする |
| Q3 | API scope の渡し方・入口防御 | **nested route + 広い `ProhibitsProtectedKeys`(`missing`)**(spirux 型)。多態リソースが必要になった場合の aigenba 型への逸脱手順を docs 同梱。deny-by-default Architecture テスト同梱 |
| Q4 | LLM 防御スタック | **コアのみ同梱**: UserInput 型+prompt YAML+LlmCallLog+canary/defensive gate+PromptOperationGuardrail テスト。tool allowlist(config/llm-defense)・会話履歴ビルダー・EvaluationVariableShield は「LLM 利用形態別レシピ」として docs に置く |
| Q5 | nested route IDOR 防御 | **URL 整合 404 guard + org-scoped 解決**(aigenba 型)。Q1 で aigenba 階層を採るのと整合。inventory テスト(NestedRouteIdorDefenseTest 相当)同梱 |
| Q6 | laratrust strict_check | **`true`**。あわせて「権限判定は常に team を明示」規約を docs に明記 |
| Q7 | 課金プリミティブ | **チケット台帳(reserve/commit/release)+多次元 Quota(OrganizationQuota/Usage)を両方、独立モジュールとして同梱**。プラン定義(シーダー)で有効化を選択。席数・買い切りチケット・プラン自動移行は実装例として docs 送り |
| Q8 | API キー体系 | **flat ability 既定**。包含関係は ability 定義側で表現。prefix は config 化。PlatformApiKey はオプションモジュール |
| Q9 | HTTPS・デプロイ前提 | **RedirectToHttps を同梱し env フラグで無効化可**(ALB 終端構成では off)。forceScheme+secure cookie+HSTS は無条件同梱。デプロイ docs は ALB 構成/単機構成の 2 レシピ併記 |
| Q10 | 命名正規化 | **`AdminUser` / `SocialAccount` / `organization_admin` / `Models\Billing\` 名前空間で確定**。監査ログ(AuditLog vs SecurityAuditEvent)のみ保留 → 実装比較タスクとして残す |
| Q11 | メール変更フロー | **aigenba 型で確定**: Fortify レンジ+旧アドレスへのセキュリティ通知(新アドレス非開示) |
| Q12 | 自走スキル群 | **アプリ名非依存名(`app-implement` 等)で同梱**。パス・ID 依存は config/frontmatter に抽出。リネーム不要でそのまま使える形 |
| Q13 | UI 既定テーマ | **aigenba 型をニュートラル化**。「影なし・最小色・rounded 3 段・weight 2 段」の制約体系を維持し、色値だけ汎用的な中立色に差し替えた既定テーマ。アプリ毎の差し替えは tokens.css の値変更で完結させる(機構 = DESIGN.md canonical + tokens.css + parity/purity テストは確定) |
| Q14 | Atomic Design 階層 | **organisms あり 5 層**(atoms/molecules/organisms/features/templates)。portal 系(Modal/ConfirmDialog/ToastContainer)を organisms に置く spirux 型 |
| Q15 | DS 機械統制 | **普遍ルール=フル + テーマ由来ルール分離**。raw hex/token 迂回/arbitrary/inline style/z-index/Lucide 外 SVG は常時フル適用。影・gradient・rounded/weight 段数はテーマ定義から導出。層別テスト+構造化 allowlist+parity テスト同梱、allowlist 0 件で出荷 |
| Q16 | DESIGN.md 雛形 | **中間粒度(400〜600 行)**。aigenba 型(トークン+意味割り当てルール+Do/Don't)をベースに、spirux の汎用 component 節(Table/Forms/Toast/Modal/Loading 等の使い分けルール)を追加。class 全列挙はしない(型と実装に委ねる) |

## 決定から導かれる注意点

- **Q1×Q3 の組み合わせ**: 3 階層 + nested route なので、API パスは
  `/api/v1/projects/{project}/...` を基本に、必要に応じて
  `/organizations/{organization}/teams/{team}/projects/{project}` 級の深い Web route が生じる。
  Q5 で URL 整合 guard を採用したため、深い route でも relation の有無に縛られない(aigenba で実証済みの構成)。
- **Q3 の prohibited キー集合**: テンプレ既定は actor/tenant 最小集合
  (`user_id` / `organization_id` / `project_id` / `team_id` / `custom_team_id` /
  `created_by_user_id` / `current_organization_id`)+アプリ追記方式。
- **確定済みの細目**(04 の「決め」論点より): cache `serializable_classes=false` 既定 /
  PHP ^8.4 / PHPStan level 10 / VerifyMcpOrigin は spirux 版(本番 bare `*` 拒否)/
  統一エラー envelope・rate limit バケット・production:preflight・debug route 非登録検証は spirux 版 /
  オーナー移譲は行ロック付き(spirux 版)。

## 追加決定(2026-06-11)

- **Figma 連携はテンプレート対象外で確定**(オーナー判断)。spirux の figma 系スキル
  (figma-design/implement/sync/diff)・Code Connect・pixel-diff harness は移植しない。
  必要になったアプリが spirux から個別に持ち込む。

## 残タスク(保留事項)

1. 監査ログの正規形決定: 実装比較の結果、両者は重複でなく**責務の異なる 3 層**
   (AuditLog=操作ログ(append-only) / SecurityAuditEvent=認証イベント / ModelAudit=管理属性 diff)
   と判明。**3 層とも同梱する案を推奨**(`11-backend-data-conventions.md` §4)。Phase 1 設計時に最終確定
2. ヘルプシステム(aigenba の manifest+audience 別 Markdown)を機構としてテンプレに入れるか未決
