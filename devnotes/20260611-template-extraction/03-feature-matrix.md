# aigenba / spirux 機能マトリクス(テンプレート抽出用)

両アプリの機能をカテゴリごとに突き合わせた一覧。テンプレート列の凡例:

- **◎ コア**: 両アプリでほぼ同一。テンプレートにそのまま入れる
- **○ 共通(要パラメータ化)**: 両アプリにあるが命名・項目・設定が違う。骨格をテンプレ化し中身を差し替え可能に
- **△ 判断対象**: 方式が分岐している/片方にしかない。`04-template-open-questions.md` 参照
- **× ドメイン固有**: テンプレート対象外(アプリ毎に実装)

## 1. 認証・アカウント

| 機能 | aigenba | spirux | テンプレ |
|---|---|---|---|
| メール+パスワード登録・ログイン(Fortify) | ✓ | ✓ | ◎ |
| ソーシャルログイン(Socialite) | Google | GitHub + Google | ○ プロバイダ設定可能に |
| ソーシャルアカウントリンク | UserSocialAccount | SocialAccount | ○ モデル名統一 → △Q10 |
| 2FA(TOTP + リカバリコード) | ✓ | ✓ | ◎ |
| メール検証 | ✓ | ✓ | ◎ |
| メール変更フロー | Fortify レンジ+旧宛先通知 | pending_email+トークン(Fortify 化提案済) | △Q11 |
| パスワードリセット・変更通知 | ✓ | ✓ | ◎ |
| step-up 再認証 | ✓ | ✓ | ◎ |
| Personal Organization 自動生成 | ✓ | (組織作成フロー) | △Q1 付随 |
| アカウント削除 | — | ✓ カスケード | ◎ spirux 版を採用 |
| CipherSweet PII 暗号化 + blind index | ✓ | ✓ | ◎ |

## 2. 組織・メンバー管理

| 機能 | aigenba | spirux | テンプレ |
|---|---|---|---|
| Organization CRUD・切替(current_organization_id) | ✓ | ✓ | ◎ |
| 部門層(CustomTeam) | ✓ Org→CustomTeam→Project | なし(Org→Project 直下) | △Q1 ★最重要 |
| メンバー招待(メールトークン) | ✓ | ✓(7 日期限) | ◎ |
| ロール変更・メンバー削除 | ✓ | ✓ | ◎ |
| オーナー移譲 | ✓ | ✓(行ロック) | ◎ 強い方(spirux)に揃える |
| Project メンバーシップ | ロール(tutor/trainee) | pivot(project_admin/member) | △Q2 |

## 3. 権限(RBAC)

| 機能 | aigenba | spirux | テンプレ |
|---|---|---|---|
| Laratrust team-scoped RBAC(Org=Team 1:1) | ✓ | ✓ | ◎ |
| strict_check | false(常に team 明示) | true | △Q6 → true 推奨 |
| 組織ロール 3 種(owner/admin/member) | ✓ organization_administrator | ✓ organization_admin | ○ 命名統一 → △Q10 |
| プロジェクトロール | project_tutor / project_trainee | project_admin / project_member | △Q2 |
| Policy(Gate)認可 | ✓ | ✓ | ◎ |
| 暗黙権限継承(OrgAdmin→配下 Project) | ✓ | ✓ | ◎ |
| cross-org 防御(TBP / invariant) | Service+DB CHECK | TBP precondition | ○ 不変条件を揃えて骨格化 |
| ドメイン permission enum | 多数(Course/Encounter 等) | あり | ○ enum 構造のみテンプレ、中身はアプリ定義 |

## 4. 課金・クォータ(Stripe / Cashier)

| 機能 | aigenba | spirux | テンプレ |
|---|---|---|---|
| Plan / PlanPrice カタログ | ✓ Models/Billing/ | ✓ Models/ 直下 | ○ 配置統一 → △Q10 |
| Subscription / Checkout Saga | ✓ | ✓ | ◎ |
| Webhook 冪等マシン(StripeWebhookEvent) | ✓ | ✓ | ◎ |
| チケット 2 フェーズ消費(reserve/commit/release + 台帳) | ✓ | ✓(整列済) | ◎ |
| 買い切りチケット(TicketPurchase) | ✓ | — | △Q7 |
| 席数管理(additional_seats / SubscriptionItem) | ✓ | — | △Q7 |
| 多次元リソース Quota(OrganizationQuota/Usage) | QuotaOverride のみ | ✓ 項目多数 | △Q7 |
| 返金クローバック / dispute 通知 | ✓ | (要確認) | ◎ 強い方に揃える |
| 非同期決済(コンビニ/銀振)/ JCT | ✓ | (要確認) | ○ |
| BillingNotification(dedup_key) | ✓ | ✓ | ◎ |
| price catalog 同期・検証 command | runbook | ✓ command | ◎ spirux 版を採用 |
| Stale reservation 解放 cron | ✓ 5 分 | ✓ 10 分 | ◎ |

## 5. API キー・REST API

| 機能 | aigenba | spirux | テンプレ |
|---|---|---|---|
| Organization API Key 発行・失効・1 度きり表示 | ✓ `aigb_*` | ✓ | ◎(prefix はアプリ設定) |
| scope/ability 体系 | scope+暗黙包含階層 | flat ability | △Q8 |
| Platform API Key(運用キー) | ✓ `platform_*` | — | △Q8 |
| Idempotency-Key 配線 | ✓ | ✓ 全 CRUD | ◎ 強い方(spirux)に揃える |
| REST API v1 の scope 渡し | body discriminator(多態) | nested route | △Q3 ★(D1) |
| 統一エラー envelope | (要確認) | ✓ api-errors.md | ◎ spirux 版を採用 |
| rate limit バケット | (要確認) | ✓ 4 バケット | ◎ spirux 版を採用 |
| whoami / version | ✓ | ✓ | ◎ |

## 6. MCP・CLI

| 機能 | aigenba | spirux | テンプレ |
|---|---|---|---|
| Laravel MCP + Passport OAuth 2.1 | ✓ | ✓ | ◎ |
| VerifyMcpOrigin | fail-closed(`*` ガードなし) | fail-closed+本番 `*` 拒否 | ◎ spirux 版を採用(D6) |
| McpIdempotencyKey/Service | ✓ | ✓ | ◎ |
| MCP tool 群 | ドメイン tool 多数 | 9 tool | × tool 自体は固有。whoami/list 系の雛形のみ ○ |
| CLI/MCP onboarding snippet 画面 | ✓ | ✓ | ◎ |

## 7. AI・LLM 基盤

| 機能 | aigenba | spirux | テンプレ |
|---|---|---|---|
| Prism + laravel-prism-prompt(YAML) | ✓ | ✓ | ◎ |
| UserInput 型(untrusted 入力の型強制) | ✓ +RenderedConversationContext | ✓ EvaluationVariableShield | △Q4(D3/D8) |
| prompt canary + defensive gate | (要確認) | ✓ | ◎ spirux 版を採用候補 |
| config/llm-defense.php(tool allowlist/alert) | なし(意図的) | ✓ | △Q4 |
| PromptOperationGuardrail(Facade 直呼び禁止) | ✓ | — | ◎ aigenba 版を採用候補 |
| LlmCallLog(コスト記録) | ✓ | ✓(JPY 換算) | ◎ |
| Prism::fake() / Dusk canned response | ✓ | ✓ | ◎ |
| マルチターン会話基盤(ConversationContextBuilder) | ✓ | N/A | × ドメイン依存(D8) |

## 8. 管理画面(Filament 5)

| 機能 | aigenba | spirux | テンプレ |
|---|---|---|---|
| 管理者モデル+専用 guard | Admin | AdminUser(super_admin) | ○ 命名統一 → △Q10 |
| 管理者 2FA | ✓ | ✓ | ◎ |
| Organization / User / Role 管理 | ✓ | ✓ | ◎ |
| 課金リソース(Plan/Subscription/Quota) | ✓ | ✓ | ◎ |
| 監査ログ閲覧 | AuditLog | ModelAudit/SecurityAuditEvent | △Q10 |
| ドメインリソース管理 | Encounter 等 | Site/Evaluation 等 | × |

## 9. 通知・メール・問い合わせ

| 機能 | aigenba | spirux | テンプレ |
|---|---|---|---|
| 認証系メール一式 | ✓ | ✓ | ◎ |
| 課金系通知(失敗/dunning/リマインダ) | ✓ | ✓ | ◎ |
| EmailSuppression | ✓ | ✓ | ◎ |
| Inquiry(問い合わせ)+削除 runbook | ✓ | ✓ | ◎ |
| SES 運用 + dev Mailpit relay | ✓ | (SNS validator あり) | ○ |
| アプリ内通知センター(database channel) | — | ✓ | ◎ spirux 版を採用候補 |

## 10. セキュリティ機構

| 機能 | aigenba | spirux | テンプレ |
|---|---|---|---|
| mass-assignment 入口防御 | 狭い trait+per-request+出口層 | 広い ProhibitsProtectedKeys(`missing`) | △Q3(D1) |
| nested route IDOR 防御 | URL 整合 guard+org-scoped 解決 | Route::scopeBindings() | △Q5(D2) |
| SSRF 防御(ssrf-pin) | ✓ | ✓ +SubresourceHostPin | ◎ |
| HTTPS 強制 | ALB 終端前提 | RedirectToHttps(308) | △Q9(D7) |
| cache serializable_classes | 最小 allowlist | false(全 deny) | ◎ テンプレ既定 false(D9) |
| ModelAudit(laravel-auditing)+ critical action | ✓ | ✓ | ◎ |
| debug route 非登録検証 / production:preflight | (要確認) | ✓ | ◎ spirux 版を採用 |
| supply-chain 監査(checklist/advisories) | ✓ | ✓ +socket-dev | ◎ |
| SecurityHeaders / CSP | ✓ | ✓ | ◎ |

## 11. テスト・CI・開発環境

| 機能 | aigenba | spirux | テンプレ |
|---|---|---|---|
| Pest / Dusk(env 分離・fake provider)/ Vitest | ✓ | ✓ | ◎ |
| PHPStan(larastan) | level 10 | ✓ | ◎ level 10 に揃える |
| Architecture テスト群 | ✓ | ✓ | ○ 不変条件は共通、対象はアプリ毎 |
| Factory 規約(docs/factories.md) | ✓ | ✓ | ◎ |
| mprocs / init.sh / mise / worktree 戦略 | ✓ | ✓ | ◎ |
| 自走スキル群(autopilot/design/implement/todo/codex-review/update-docs) | ✓ | ✓ | ○ 名前空間のパラメータ化 → △Q12 |
| 差分レジストリ運用 | ✓ 正本 | ✓ ミラー | △Q12(テンプレでは「テンプレとの差分」管理に転用?) |
| Figma 連携スキル(design system/sync/diff) | — | ✓ | △Q12 |

## 12. ドメイン固有(テンプレート対象外の確認)

| | aigenba | spirux |
|---|---|---|
| 中核モデル | Encounter / Course / Scenario(3 状態)/ SharedResource / Derivative / Template | Site / Page / PageRender / Evaluation / Persona / Scenario |
| LLM 用途 | マルチターン対話(NPC)+採点 | one-shot 構造化評価+Playwright エージェント |
| 固有 UI | SSE 対話画面・リプレイ | レポート・レーダーチャート・指摘描画・PDF |
| 固有外部連携 | — | Playwright / sharp / Google Sheets チェックリスト |

ドメイン層は共通化しない。ただし「Scenario」という名前が両方に居る(意味は別物)ため、
テンプレートにドメインモデルの雛形を置く場合は名前衝突に注意。

## 13. ヘルプ・運用ドキュメント

| 機能 | aigenba | spirux | テンプレ |
|---|---|---|---|
| アプリ内ヘルプ(manifest+Markdown+audience) | ✓ | — | △ 機構のみテンプレ化候補 |
| デプロイ runbook | Deployer+ALB | release.md+Lightsail | △Q9 |
| Stripe 環境/価格変更 runbook | ✓ | ✓(billing-free-removal) | ◎ 雛形化 |
| 顧客プロビジョニング(YAML+script) | ✓ | — | ○ 候補 |
