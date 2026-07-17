# Model Factory リファレンス

テスト用データ生成に使用する Eloquent Factory の一覧と使い方。

## 基本ルール

- テストデータは **必ず Factory で生成**する (`Model::create()` で手組みしない)
- 各 Factory は未指定の外部キーに対して親モデルの Factory を自動連鎖させるため、
  最小限の指定でツリー全体が作れる。リレーション先を共有したい場合は明示的に渡す
- **新規モデルを追加したら Factory の追加と本書一覧への追記が必須**
  ([docs/architecture.md](architecture.md) と同じ規約。AGENTS.md 実装規約)

## Factory 一覧 (テンプレート同梱)

| Factory | Model | 主な State |
|---------|-------|-----------|
| `UserFactory` | User | `unverified()`, `ssoOnly()` (password null + 認証済み), `withTwoFactor()` (本物の TOTP secret + recovery codes + confirmed) |
| `AdminUserFactory` | AdminUser | `withMfa()` |
| `OrganizationFactory` | Organization | `personal()` |
| `CustomTeamFactory` | CustomTeam | — |
| `ProjectFactory` | Project | `forOrganization($org)` |
| `ItemFactory` | Item | `forProject($project)` |
| `CategoryFactory` | Category | `forProject($project)` |
| `VideoManualFactory` | VideoManual | `forProject($project)`, `forCategory($category)`, `createdBy($user)` |
| `SourceDocumentFactory` | SourceDocument | `forManual($manual)` |
| `CutFactory` | Cut | `forManual($manual)` / `asPointOf($step)` / `withSortOrder($n)` |
| `AnalysisJobFactory` | AnalysisJob | `forManual($manual)` / `forDocument($document)` / `running()` / `failed($error)` / `succeeded()` |
| `RenderJobFactory` | RenderJob | `forManual($manual)` / `preview()` / `running()` / `succeeded($outputPath)` / `failed($code, $error)` |
| `TakeFactory` | Take | `forCut($cut)` / `downloaded()` (DL 済み ACK 打刻済み = 削除不可) |
| `TakeUploadReservationFactory` | TakeUploadReservation | `forCut($cut)` / `verifying()` / `completed()` / `released()` / `expired()`。`organization_id` は cut→manual→project→org を辿ってサーバ導出 (afterMaking) |
| `ApiKeyFactory` | ApiKey | `forOrganization($org)`, `revoked()`, `expired(?Carbon $expiresAt = null)` |
| `OrganizationInvitationFactory` | OrganizationInvitation | `forOrganization($org)`, `expired()`, `accepted()`, `revoked()`, `asAdmin()`。加えて `createWithPlainToken(array): array` (invitation と平文 token を tuple で返す。URL 生成用。DB には sha256 hash のみ保存) |
| `IdempotencyKeyFactory` | IdempotencyKey | `forApiKey($apiKey)`, `expired(?Carbon $expiresAt = null)` |
| `OauthSessionFactory` | OauthSession | `cli()`, `mcp()`, `revoked()` |
| `McpIdempotencyKeyFactory` | McpIdempotencyKey | `forOrganizationAndUser($org, $user)`, `expired()` |
| `InquiryFactory` | Inquiry | `spam()`, `closed(int $closedDaysAgo = 0)`, `staleOpen(int $createdDaysAgo = 40)` |
| `EmailSuppressionFactory` | EmailSuppression | `bounce()`, `complaint()`, `forEmail(string $email)` (normalize + hash 込み) |
| `LlmCallLogFactory` | LlmCallLog | `withFxSnapshot(float $rate = 154.32)`, `failed(string $reason = ...)`, `metadataMissing()` |
| `ModelAuditFactory` | ModelAudit | — (auditable は Item 既定。派生アプリは state で上書き) |
| `Billing\BillingNotificationFactory` | Billing/BillingNotification | `forOrganization($org)`, `reminder(?string $dedupKey = null)` (dedup_key 経路), `sent()`, `failed()` |
| `Billing\TicketCheckoutSessionFactory` | Billing/TicketCheckoutSession | `forOrganization($org)`, `initiatedBy($user)`, `completed()`, `expired()`, `stale()` (pending のまま expires_at 過去) |
| `Billing\BillingCheckoutSessionFactory` | Billing/BillingCheckoutSession | `withAttemptToken($token, ?$checkoutUrl)`, `initiatedBy(int $userId)`, `completed()`, `setupPaymentMethod()`, `expired()`, `failed()`, `stale()` (pending のまま created_at が stale 境界より過去) |

Factory を持たないモデル (Role / Permission / Team 等) は seed 固定値
または Service (`OrganizationProvisioningService` 等) 経由で作る。
アプリ内通知 (`notifications` テーブル) は Eloquent 標準 `DatabaseNotification` を使うため
新規モデル / Factory は作らない (テストでは `$user->notify(new ManualAnalyzedNotification(...))`
の実発火で行を作る。`AnalysisJob` / `RenderJob` の `triggered_by` は nullable のため
Factory は既存のまま。テストで必要なときは create 属性 `['triggered_by' => $user->id]` を渡す)。

## 使い方

### 基本

```php
// 単体生成
$user = User::factory()->create();

// 属性を指定して生成
$org = Organization::factory()->create(['name' => 'テスト組織']);

// State を使用
$key = ApiKey::factory()->revoked()->create();
```

### 自動リレーション解決

未指定の外部キーは親 Factory に連鎖する:

```
Item → Project → Organization (Default Team) → Team (laratrust)
Category → Project → Organization (Default Team) → Team (laratrust)
Take → Cut → VideoManual → Project (+ User) → Organization → Team (laratrust)
IdempotencyKey → ApiKey → Organization
OauthSession → User / Organization / Passport Client
LlmCallLog → Organization
CustomTeam → Organization
```

- `OrganizationFactory` は afterCreating で Default Team (CustomTeam) を必ず 1 つ作る
  ([docs/default-team-pattern.md](default-team-pattern.md) の不変条件)
- `ProjectFactory` は team 未指定時に新規組織の Default Team へ割り当てる。
  既存組織配下に作るには `forOrganization($org)` を使う

### テストでよく使うパターン: Pest ヘルパ (tests/Pest.php)

ロール付与・API キー発行は Factory 直叩きではなく共通ヘルパを使う:

```php
// Owner 付き組織 (provisioning 経由。Default Team + Owner ロールまで揃う)
[$organization, $owner] = createOrganizationWithOwner();

// 組織メンバー追加 (attach + laratrust_team_id 明示のロール付与)
$member = attachOrganizationMember($organization, OrganizationRole::Member);

// プロジェクトメンバー追加 (pivot にロール付き attach)
attachProjectMember($project, $member, ProjectRole::Member);

// 組織スコープ API キー発行 (平文付きで返す。REST API / MCP テスト用)
[$apiKey, $plainKey] = issueApiKey($organization, $owner, ['read', 'write']);
```

### テストでよく使うパターン: ドメインリソース (Item が見本)

```php
[$organization, $owner] = createOrganizationWithOwner();
$project = Project::factory()->forOrganization($organization)->create();
$item = Item::factory()->forProject($project)->create();
```

新規ドメインリソースの Factory も `forProject()` / `forOrganization()` の
明示 State + 親 Factory 連鎖のパターンに揃えること
([docs/app-integration-guide.md](app-integration-guide.md) §2)。

## Seeder

参照データの Seeder は依存順に分割されている (DatabaseSeeder の call 順):

1. `RoleSeeder` — 組織ロール (`OrganizationRole::cases()` から updateOrCreate)
2. `PermissionSeeder` — permission 定義 (テンプレート初期状態は空。アプリで追加)
3. `RolePermissionSeeder` — ロール → permission の紐付け専用 (additive・冪等)
4. `PlanSeeder` — プラン + 価格 bootstrap
5. `AdminUserSeeder` — local 開発用固定 AdminUser (local 以外では skip)

すべて再実行安全。テストでは `TestCase::$seed = true` で毎回流れる。

### ManualTestSeeder (手動テスト用・DatabaseSeeder からは呼ばれない)

ロール × プランの総当たりでユーザー/組織を固定パスワードで冪等作成する
(加えて複数組織所属ユーザーとメール未認証ユーザーを 1 人ずつ):

```
php artisan db:seed --class=ManualTestSeeder
```

- 全ユーザーのパスワード: `password123`
- email 規則: `{role}-{plan_code}@example.com` (例: `owner-free@example.com`、
  他に `multi-org@example.com` / `unverified@example.com`)
- 組織は `OrganizationProvisioningService` 経由で作成 (Default Team 込み)。
  組織名は `{プラン名}プラン組織`、`plan_code` はそのプランに設定される
