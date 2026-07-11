【アプリの使命（North Star）— AGENTS.md より】
**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

【禁止事項 — AGENTS.md より】
1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(`back()->with(...)` / 明示 redirect で完結)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)

【セキュリティ不変条件 — AGENTS.md より（アプリ都合で緩めない）】
1. tenant キー不信: ownership/actor/tenant キーを payload から受け取らない(`ProhibitsProtectedKeys` + `MassAssignmentSafetyTest`)
2. 子は親に属する: nested route の不整合は認可より前に 404(`NestedRouteIdorDefenseTest` inventory 登録必須)
3. cross-org 不可: 組織を跨ぐ read/write をしない(relation / org-scoped 解決経由のみ)
4. untrusted 文字列は UserInput 型経由でのみ prompt に入れる
5. 権限判定は常に `laratrust_team_id` を明示(strict_check=true)
6. PII(email/name)は CipherSweet。検索は `whereBlind()`
7. 課金の冪等性: webhook は冪等マシン経由、チケットは reserve→commit/release の 2 フェーズ
8. 外部 URL 取得は SSRF 検査経由

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。

データに真摯に向き合え。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。方向性が間違っているなら、設計そのものを見直せ。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可（リポジトリは /workspace）。

あなたは経験豊富なWebアプリケーションアーキテクトです。Laravel + Svelte アプリケーション改善の詳細設計をレビューしてください。

【前提環境】
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- PHPStan level 10 / Pest / DTO + JsonResource パターン
- Laratrust RBAC（Organization → Team → Project階層）
- テスト DB は PostgreSQL（RefreshDatabase グローバル + --parallel）

【レビュー観点】
1. コードの正確性（ロジックエラー、エッジケース、null安全性）
2. 既存コードとの整合性（命名規約、パターン、API）
3. PHPStan level 10 適合性（型安全性、generics、Assert使用）
4. テスト計画の網羅性（各施策にPestテスト、RefreshDatabaseグローバル適用に従う）
5. DTO/JsonResource パターンの遵守
6. Inertia Props vs API Responseの使い分け
7. 副作用・後退リスク
8. 波及変更の網羅性（TypeScript型定義、API Resource、テストが変更対象に含まれているか）
9. セキュリティ（認可チェック、入力バリデーション、OWASP Top 10、AGENTS.md のセキュリティ不変条件）
10. DESIGN.md準拠（UI変更）: design token 経由の参照か、hex 直書きを増やさないか
11. Atomic Design準拠: atoms/molecules/organisms/features/templates/pages の責務分離・単方向 import・アイコンは Lucide のみ

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

【重要な現行コード（抜粋。全文は /workspace の該当ファイルを読んでよい）】
- app/Services/Manual/AnalysisJobService.php: trigger() は tx 内で VideoManual 行ロック → AnalysisJob 作成（status/sourceDocument 明示代入）→ analyzing 遷移 → commit 後 dispatch。failJob(AnalysisJob, string): bool は tx 内 行ロック + terminal guard（terminal 済みは false）。recoverStale() は failJob を回す
- app/Services/Manual/RenderJobService.php: trigger()/triggerPreview() 同型（kind 明示代入）。failJob(RenderJob, RenderErrorCode, string): bool 同型。completeRenderIntoLockedManual はロック済み前提メソッド（ScenarioWritePathInventoryTest が経路固定）
- app/Services/Manual/AnalysisPipeline.php: run() が startJob → LLM 3 段 → finalize(job, generated): void（terminal tx: materialize + tickets->commit + succeeded。Running guard で早期 return）。catch → failJob。ロック順 analysis_jobs → video_manuals → ticket_reservations → organizations
- app/Services/Manual/RenderPipeline.php: finalize(...): bool 済み（succeeded 到達で true）
- app/Services/Billing/TicketLedgerService.php: balance() = 有効台帳 sum − Reserved 拘束 sum。commit(reservation) は tx 内 lockReservationRow → lockOrganizationRow → appendEntry(-amount) → Committed。pipeline の terminal tx 内から savepoint で呼ばれる
- app/Services/Organization/OrganizationMembershipService.php: inviteMember() は招待 save 後 Notification::route('mail', $email)->notify(new OrganizationInvitationNotification(...))。token は sha256 のみ保存
- app/Http/Middleware/HandleInertiaRequests.php: share() は auth/organizations/currentOrganization/flash/contact/title を共有。$user は User へ narrowing 済み
- routes/web.php: auth+verified 群に /billing・/purchase-tickets(name: billing.tickets.show)。業務 route は require-active-subscription + project.in-current-org 群。projects.manuals.show = /projects/{project}/manuals/{manual}
- resources/js/components/templates/AppLayout.svelte: header 内 appName リンク + headerActions snippet。page.props を narrow して使用
- tests/Architecture/ManualEnumTsSyncInvariantTest.php: PHP enum ⇔ TS literal union の値集合一致を正規表現抽出で固定するパターンあり
- User は Notifiable + CipherSweet(email 検索は whereBlind('email','email_index',$v))。organizationRole(Organization): ?OrganizationRole は laratrust_team_id 明示判定。OrganizationRole::canManage() は owner/admin で true
- Laravel の DatabaseChannel は container 経由で解決される（binding 差し替え可）。DatabaseNotification は uuid PK

---
## 詳細設計書
# 詳細設計: notification-center（アプリ内通知センター）

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

### 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(`back()->with(...)` / 明示 route redirect で完結)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)

### コーディングルール

- **PHPStan level 10** 必須（`composer phpstan`）
- **Pest**テストフレームワーク（`composer test`）
- **RefreshDatabase** + `--parallel` 並列実行（`tests/Pest.php` でグローバル適用、個別 `DatabaseTransactions` 使用禁止）
- **テストデータは必ずFactoryで生成**（`Model::create()` 手組み禁止）
- 新モデルを追加する設計では **対応するFactoryの作成も施策に含める** こと
- **DTO + JsonResource** パターン / **アーリーリターン** 推奨
- **コードフォーマット**: `composer fix`（Pint）/ `pnpm lint:fix`
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript

## 概念設計リファレンス

[conceptual-design.md](./conceptual-design.md)（Codex 概念レビュー Round 3 で APPROVED）

## 全体像

```
発火点（すべて既存 exactly-once 遷移の commit 後）
  AnalysisPipeline::run ──(finalize=true)──┐
  AnalysisJobService::failJob ─(true)──────┤
  RenderPipeline::run ──(finalize=true)────┼─▶ NotificationCenterService ─▶ User::notify(...)
  RenderJobService::failJob ─(true,render)─┤      （宛先/内容/org を DB relation から導出）
  OrganizationMembershipService::invite ───┤              │ database channel
  TicketLedgerService::commit ─(閾値クロス)─┘              ▼
                                              notifications テーブル（organization_id 列付き）
読み出し
  HandleInertiaRequests ─▶ shared props notifications.unreadCount（全画面ベル）
  NotificationController@index ─▶ NotificationListItemData[]（Inertia typed array）
  @open（POST 既読化+303）/@read/@readAll（back()）
```

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | notifications テーブル + triggered_by 列の migration | `database/migrations/*_create_notifications_table.php`（新規）, `*_add_triggered_by_to_job_tables.php`（新規）, `app/Support/Security/MassAssignmentProtectedKeys.php` | 高 |
| 2 | NotificationType enum + payload DTO + Notification クラス + org 列チャネル | `app/Enums/Notification/NotificationType.php`, `app/DataTransferObjects/Notification/*`, `app/Notifications/InApp/*`, `app/Notifications/Channels/OrganizationScopedDatabaseChannel.php`, `app/Providers/AppServiceProvider.php` | 高 |
| 3 | NotificationCenterService（発火・読み出し・既読化） | `app/Services/Notification/NotificationCenterService.php`（新規） | 高 |
| 4 | ジョブ terminal 遷移への発火配線 + triggered_by 記録 | `app/Services/Manual/{AnalysisPipeline,AnalysisJobService,RenderPipeline,RenderJobService}.php`, `app/Http/Controllers/Projects/{ManualAnalysisController,ManualRenderController}.php` | 高 |
| 5 | 招待・残高低下の発火配線 | `app/Services/Organization/OrganizationMembershipService.php`, `app/Services/Billing/TicketLedgerService.php`, `config/billing.php` | 中 |
| 6 | 通知ルート + Controller + 読み出し DTO + shared props | `routes/web.php`, `app/Http/Controllers/NotificationController.php`（新規）, `app/DataTransferObjects/Notification/NotificationListItemData.php`, `app/Http/Middleware/HandleInertiaRequests.php` | 高 |
| 7 | フロント（ベル・一覧・型） | `resources/js/components/molecules/NotificationBell.svelte`, `resources/js/components/features/notifications/NotificationListItem.svelte`, `resources/js/pages/Notifications/Index.svelte`, `resources/js/types/notification.ts`, `resources/js/components/templates/AppLayout.svelte` | 高 |
| 8 | テスト（Feature / Architecture / Vitest） | `tests/Feature/Notifications/*`, `tests/Architecture/NotificationTypeTsSyncInvariantTest.php`, `tests/js/components/molecules/NotificationBell.test.ts`, `tests/js/components/features/NotificationListItem.test.ts` | 高 |

---

## 施策1: migrations + 保護キー

### 変更箇所

- 新規: `database/migrations/2026_07_12_000000_create_notifications_table.php`
- 新規: `database/migrations/2026_07_12_000100_add_triggered_by_to_job_tables.php`
- `app/Support/Security/MassAssignmentProtectedKeys.php`（L47 付近に追記）

### 変更後コード

```php
// create_notifications_table.php
// Laravel 標準 notifications スキーマ + organization_id first-class 列（概念レビュー R1 Critical 対応）。
// data は jsonb（pgsql）だが org 判定・クエリには使わない（表示用 payload 限定）。
Schema::create('notifications', function (Blueprint $table): void {
    $table->uuid('id')->primary();
    $table->string('type'); // NotificationType の value（クラス名を DB に置かない）
    $table->morphs('notifiable');
    // org 文脈のサーバ導出列。org 削除で通知ごと消える（概念レビュー R2 対応）
    $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
    $table->jsonb('data');
    $table->timestamp('read_at')->nullable();
    $table->timestamps();
    // 未読数 1 クエリの担保（標準 morphs index は read_at を含まないため複合で明示。R2 対応）
    $table->index(['notifiable_type', 'notifiable_id', 'read_at']);
});
```

```php
// add_triggered_by_to_job_tables.php（analysis_jobs / render_jobs 共通）
Schema::table('analysis_jobs', function (Blueprint $table): void {
    // ジョブ実行者（通知宛先の導出用）。Auth からの明示代入のみ・payload 不信任
    $table->foreignId('triggered_by')->nullable()->constrained('users')->nullOnDelete();
});
Schema::table('render_jobs', function (Blueprint $table): void {
    $table->foreignId('triggered_by')->nullable()->constrained('users')->nullOnDelete();
});
```

```php
// MassAssignmentProtectedKeys::all() に追記（actor 群の 'created_by' の直後）
'triggered_by', // AI-CUE: analysis_jobs / render_jobs のジョブ実行者 (通知宛先導出。Auth 導出のみ)
```

### 波及変更

- `ProhibitsProtectedKeys` を使う全 FormRequest に `triggered_by` の missing rule が自動で乗る（既存機構。個別変更なし）
- `MassAssignmentSafetyTest` / `FormRequestProhibitedKeyTest` が新キーを自動走査（$fillable に入れないので追加対応なし）
- `AnalysisJob` / `RenderJob` モデル: `@property int|null $triggered_by` PHPDoc + `triggeredBy(): BelongsTo` relation 追加
- Factory: `AnalysisJobFactory` / `RenderJobFactory` は既存のまま（triggered_by は nullable。テストで必要時は `for(User, 'triggeredBy')` を使う）
- `docs/architecture.md` / `docs/factories.md` への追記（notifications テーブルは Eloquent 標準 `DatabaseNotification` を使うため新規モデル/Factory は作らない。テストでは `$user->notify(...)` 実発火で行を作る）

### PHPStan適合チェック

- [x] migration は無名クラス + `declare(strict_types=1)`（既存規約）
- [x] 新規モデルなし（`DatabaseNotification` は framework 提供）

### テスト計画

- [ ] `tests/Feature/Notifications/NotificationSchemaTest.php`: 通知発火で organization_id 列が埋まる / 複合 index 前提の未読 count が動く（機能面で担保）
- [ ] 既存 `MassAssignmentSafetyTest` が green のまま（triggered_by を $fillable に入れない）

### リスク

- なし（新規テーブル + nullable 列追加のみ。既存データ無変更）

---

## 施策2: 型付き通知契約（enum / DTO / Notification / チャネル）

### 変更箇所

- 新規: `app/Enums/Notification/NotificationType.php`
- 新規: `app/DataTransferObjects/Notification/ManualJobPayload.php` / `InvitationReceivedPayload.php` / `TicketBalanceLowPayload.php`
- 新規: `app/Notifications/InApp/ManualAnalyzedNotification.php` / `ManualRenderedNotification.php` / `InvitationReceivedNotification.php` / `TicketBalanceLowNotification.php`（共通基底 `AppNotification`）
- 新規: `app/Notifications/Channels/OrganizationScopedDatabaseChannel.php`
- `app/Providers/AppServiceProvider.php`（container binding 1 行）

### 変更後コード（骨子）

```php
// app/Enums/Notification/NotificationType.php — type の単一の正（TS 側 types/notification.ts と対）
enum NotificationType: string
{
    case ManualAnalyzed = 'manual_analyzed';
    case ManualRendered = 'manual_rendered';
    case InvitationReceived = 'invitation_received';
    case TicketBalanceLow = 'ticket_balance_low';
}
```

```php
// app/DataTransferObjects/Notification/ManualJobPayload.php（解析/レンダ共用の表示 payload）
final readonly class ManualJobPayload
{
    public function __construct(
        public int $projectId,
        public int $manualId,
        public string $manualTitle,       // スナップショット（manual 削除後も本文表示可）
        public string $organizationName,  // スナップショット（join 不要・改名/退会後も当時名）
        public bool $succeeded,
        public ?string $error,            // 失敗時のユーザー向け文言（既存 error 列の値）
    ) {}

    /** @return array{project_id: int, manual_id: int, manual_title: string,
     *   organization_name: string, succeeded: bool, error: string|null} */
    public function toArray(): array { /* 素直に詰める */ }

    /** @param array<string, mixed> $data 読み出し側の検証復元（型不整合は null 返し = fallback 表示） */
    public static function tryFromArray(array $data): ?self { /* is_int/is_string 検査して組む */ }
}
// InvitationReceivedPayload: { organization_name: string }
// TicketBalanceLowPayload: { organization_name: string, balance: int, threshold: int }
```

```php
// app/Notifications/InApp/AppNotification.php（抽象基底）
abstract class AppNotification extends Notification
{
    /** @return list<string> */
    public function via(object $notifiable): array { return ['database']; }

    public function databaseType(object $notifiable): string { return $this->type()->value; }

    abstract public function type(): NotificationType;

    /** organization_id 列の値（OrganizationScopedDatabaseChannel が読む） */
    abstract public function organizationId(): ?int;

    /** @return array<string, int|string|bool|null> */
    abstract public function toDatabase(object $notifiable): array; // 実装は payload DTO の toArray() を返すのみ
}

// 具象例: ManualAnalyzedNotification
final class ManualAnalyzedNotification extends AppNotification
{
    public function __construct(
        private readonly int $organizationId,
        private readonly ManualJobPayload $payload,
    ) {}
    public function type(): NotificationType { return NotificationType::ManualAnalyzed; }
    public function organizationId(): ?int { return $this->organizationId; }
    public function toDatabase(object $notifiable): array { return $this->payload->toArray(); }
}
// ManualRenderedNotification 同型 / InvitationReceivedNotification・TicketBalanceLowNotification は各 payload
```

```php
// app/Notifications/Channels/OrganizationScopedDatabaseChannel.php
// 標準 DatabaseChannel の公式拡張点 buildPayload に organization_id をマージするだけの薄い層
class OrganizationScopedDatabaseChannel extends DatabaseChannel
{
    /** @return array<string, mixed> */
    protected function buildPayload($notifiable, Notification $notification): array
    {
        $payload = parent::buildPayload($notifiable, $notification);
        if ($notification instanceof AppNotification) {
            $payload['organization_id'] = $notification->organizationId();
        }
        return $payload;
    }
}

// AppServiceProvider::register()
$this->app->bind(DatabaseChannel::class, OrganizationScopedDatabaseChannel::class);
// （ChannelManager::createDatabaseDriver は container 経由で解決するため binding 差し替えが効く）
```

### 波及変更

- TypeScript型定義: `resources/js/types/notification.ts`（施策7）と `NotificationType` の値集合同期（施策8 の Architecture テスト）
- API Resource/DTO: なし（database channel のみ。mail 系既存 Notification は無変更）
- 既存 `app/Notifications/{Billing,User}/*`・`OrganizationInvitationNotification`・`EmailChangedSecurityNotification`: **無変更**（mail channel は DatabaseChannel binding の影響を受けない）

### PHPStan適合チェック

- [x] payload は DTO のみ（`array<string, mixed>` を跨層で流さない。tryFromArray で検証復元）
- [x] `buildPayload` の親シグネチャ互換（`@return array<string, mixed>`）
- [x] enum backed string / readonly DTO / 戻り値型明示

### テスト計画

- [ ] `NotificationSchemaTest`: `notify()` 実発火 → notifications 行の type = enum 値・organization_id 列・data 形状を assert
- [ ] payload DTO の `tryFromArray` 単体（不正形状 → null）

### リスク

- DatabaseChannel binding 差し替えは全 database 通知に及ぶが、現状 database channel 利用は本フィーチャのみ（既存は mail のみ）。`AppNotification` 以外は素通し実装で後方互換

---

## 施策3: NotificationCenterService

### 変更箇所

- 新規: `app/Services/Notification/NotificationCenterService.php`

### 変更後コード（骨子）

```php
class NotificationCenterService
{
    // ── 発火（すべて terminal 遷移 commit 後に呼ばれる。例外はジョブ本流を壊さない） ──

    /** 解析 terminal 通知。宛先 = creator ∪ triggeredBy（org 所属を再確認して dedup） */
    public function notifyAnalysisFinished(AnalysisJob $job): void
    {
        $this->safely(function () use ($job): void {
            $manual = $job->videoManual; // relation 再解決（payload 不信任）
            if ($manual === null) { return; } // manual 削除競合は黙って skip
            $project = $manual->project;
            $organization = $project?->organization;
            Assert::isInstanceOf($organization, Organization::class);
            $payload = new ManualJobPayload(
                projectId: $project->id, manualId: $manual->id, manualTitle: $manual->title,
                organizationName: $organization->name,
                succeeded: $job->status === JobStatus::Succeeded, error: $job->error,
            );
            foreach ($this->jobRecipients($manual, $job->triggered_by, $organization) as $user) {
                $user->notify(new ManualAnalyzedNotification($organization->id, $payload));
            }
        });
    }

    public function notifyRenderFinished(RenderJob $job): void { /* kind=Render のみ・同型 */ }

    /** 招待通知（既存ユーザーのみ。whereBlind = CipherSweet 不変条件 6。所属確認はしない） */
    public function notifyInvitationReceived(OrganizationInvitation $invitation): void
    {
        $this->safely(function () use ($invitation): void {
            $organization = $invitation->organization;
            Assert::isInstanceOf($organization, Organization::class);
            $user = User::whereBlind('email', 'email_index', $invitation->email)->first();
            if ($user === null) { return; }
            $user->notify(new InvitationReceivedNotification(
                $organization->id, new InvitationReceivedPayload($organization->name),
            ));
        });
    }

    /** 残高低下（宛先 = org の owner/admin。organizationRole は laratrust_team_id 明示判定） */
    public function notifyTicketBalanceLow(Organization $organization, int $balance, int $threshold): void
    {
        $this->safely(function () use ($organization, $balance, $threshold): void {
            $payload = new TicketBalanceLowPayload($organization->name, $balance, $threshold);
            foreach ($organization->users()->get() as $user) {
                if ($user->organizationRole($organization)?->canManage() !== true) { continue; }
                $user->notify(new TicketBalanceLowNotification($organization->id, $payload));
            }
        });
    }

    // ── 読み出し・既読化（Controller から委譲） ──

    /** @return LengthAwarePaginator<int, DatabaseNotification> 自分宛のみ（構造的 self-scope） */
    public function paginateFor(User $user, int $perPage = 20): LengthAwarePaginator { /* latest() */ }

    public function unreadCountFor(User $user): int { return $user->unreadNotifications()->count(); }

    /** 自分宛以外は 404（存在オラクル封じ）。firstOrFail を relation 経由で */
    public function findOwnOrFail(User $user, string $id): DatabaseNotification
    { return $user->notifications()->whereKey($id)->firstOrFail(); }

    public function markRead(DatabaseNotification $notification): void { $notification->markAsRead(); }
    public function markAllRead(User $user): void { $user->unreadNotifications()->update(['read_at' => now()]); }

    /** 宛先集合: creator ∪ triggeredBy を id で dedup し、org 所属を再確認 @return list<User> */
    private function jobRecipients(VideoManual $manual, ?int $triggeredById, Organization $organization): array { /* … */ }

    /** 通知失敗はジョブ本流を絶対に壊さない（catch + report。tx 外でのみ呼ばれる前提） */
    private function safely(callable $callback): void
    { try { $callback(); } catch (Throwable $e) { report($e); } }
}
```

### 波及変更

- なし（新規クラス。発火側は施策4/5、読み出し側は施策6が結線）

### PHPStan適合チェック

- [x] 戻り値型明示 / `Assert::isInstanceOf` で relation の null 排除
- [x] `LengthAwarePaginator` の generics 明示
- [x] DTO 経由（配列返却なし）

### テスト計画

- [ ] `jobRecipients`: creator=triggeredBy のとき 1 通のみ / 退会済み creator に送らない
- [ ] `safely`: 通知内で例外 → report されるがメソッドは throw しない（`Exceptions::fake` 等で検証）

### リスク

- `organization->users()->get()` は org 規模に線形だが、v1 の org 規模（数十人）で問題なし。閾値クロス時のみ実行

---

## 施策4: ジョブ terminal 遷移への発火配線 + triggered_by 記録

### 変更箇所

- `app/Services/Manual/AnalysisJobService.php`: `trigger()` L50-95（actor 引数 + triggered_by 代入）、`failJob()` L106-140（tx 後通知）
- `app/Services/Manual/RenderJobService.php`: `trigger()` L65-106 / `triggerPreview()` L114-152（同上）、`failJob()` L164-199（tx 後通知・kind=render のみ）
- `app/Services/Manual/AnalysisPipeline.php`: `finalize()` L186-216 を bool 返却化、`run()` L50-69 で成功通知
- `app/Services/Manual/RenderPipeline.php`: `run()`（finalize true 後）で成功通知
- `app/Http/Controllers/Projects/ManualAnalysisController.php` L40 / `ManualRenderController.php` L49, L66: `$request->user()` を trigger に渡す

### 現行コード（要点）

```php
// AnalysisJobService::trigger() L81-88
$job = $locked->analysisJobs()->make();
$job->status = JobStatus::Queued;
$job->sourceDocument()->associate($document);
$job->save();

// AnalysisJobService::failJob() L106-140
public function failJob(AnalysisJob $job, string $error): bool
{
    return DB::transaction(function () use ($job, $error): bool { /* terminal guard → failed 遷移 */ });
}

// AnalysisPipeline::finalize() L186 — 現状 void。run() L64 は $this->finalize($job, $generated);
```

### 変更後コード

```php
// AnalysisJobService（RenderJobService も同型）
public function __construct(
    private readonly TicketLedgerService $tickets,
    private readonly NotificationCenterService $notifications, // 追加
) {}

/** actor はジョブ実行者（通知宛先の導出用）。web 経路では必ず存在するが、将来の CLI 経路に備え nullable */
public function trigger(Project $project, VideoManual $manual, ?User $actor = null): AnalysisJob
{
    // …既存 tx 内…
    $job = $locked->analysisJobs()->make();
    $job->status = JobStatus::Queued;
    $job->sourceDocument()->associate($document);
    if ($actor !== null) {
        $job->triggeredBy()->associate($actor); // Auth 導出のみ（保護キー。payload からは 422）
    }
    $job->save();
    // …
}

public function failJob(AnalysisJob $job, string $error): bool
{
    $failed = DB::transaction(function () use ($job, $error): bool { /* 既存のまま */ });

    // terminal 遷移が実際に起きたときだけ・commit 後に通知（exactly-once 遷移に乗る = 二重通知なし。
    // 通知例外は Service 内 catch + report でジョブ本流を壊さない）
    if ($failed) {
        $this->notifications->notifyAnalysisFinished($job);
    }

    return $failed;
}
```

```php
// AnalysisPipeline::finalize() — RenderPipeline::finalize と同型の bool 返却へ
/** @return bool succeeded に到達したか（stale 回復先勝ちなら false = 通知しない） */
private function finalize(AnalysisJob $job, GeneratedScenarioData $generated): bool
{
    return DB::transaction(function () use ($job, $generated): bool {
        // …既存処理は不変。冒頭の Running guard で return false、末尾で return true…
    });
}

// AnalysisPipeline::run() L64 付近
if ($this->finalize($job, $generated)) {
    $this->notifications->notifyAnalysisFinished($job->refresh()); // commit 後・成功時のみ
}
```

```php
// RenderJobService::failJob — kind=render のみ通知（preview はノイズ・status 遷移も無い）
if ($failed && $job->kind === RenderKind::Render) {
    $this->notifications->notifyRenderFinished($job);
}

// RenderPipeline::run — 既存の `if ($this->finalize($job, $result)) {` ブロック内に追加
if ($job->refresh()->kind === RenderKind::Render) {
    $this->notifications->notifyRenderFinished($job);
}

// ManualAnalysisController::store L40 / ManualRenderController::store L49・preview L66
$job = $analysis->trigger($project, $manual, $request->user());
```

### 波及変更

- `ScenarioWritePathInventoryTest`: **経路追加なし**（status/cuts/scenario_version の書き込みは既存メソッドのまま。通知は tx 外）
- 既存テスト `AnalysisPipelineTest` / `RenderPipelineTest` / `ManualAnalyzeTest` / `RenderTriggerTest` / stale 系: trigger のシグネチャは省略可能引数のため既存呼び出しは互換。通知の副作用が増えるため `Notification::fake()` 追加が必要なテストを洗い出して更新
- `triggeredBy()` relation を `AnalysisJob` / `RenderJob` モデルへ追加（施策1と同時）

### PHPStan適合チェック

- [x] `?User $actor = null` の nullable 明示 / finalize の bool 返却型
- [x] `$job->refresh()` 後の enum 比較は cast 済みプロパティ

### テスト計画

- [ ] `tests/Feature/Notifications/ManualAnalysisNotificationTest.php`:
  - 解析成功 → creator と triggeredBy に各 1 件（succeeded=true）。creator=triggeredBy なら 1 件のみ
  - 解析失敗（pipeline 例外 / recoverStale / Job::failed 経由）→ 失敗通知 1 件・**二重発火しない**（failJob 2 回目 no-op で通知 0）
  - 退会済み creator に通知しない / cross-org ユーザーに作られない
- [ ] `tests/Feature/Notifications/ManualRenderNotificationTest.php`: render 成功/失敗、**preview は成功/失敗とも通知 0**
- [ ] 既存 pipeline テストが green のまま（挙動不変の確認）

### リスク

- trigger シグネチャ拡張は省略可能引数のため既存呼び出し互換。actor 未指定時は triggered_by NULL = creator のみ宛先（安全側）
- `notifyAnalysisFinished` は relation 再解決のため N+1 はない（単発）

---

## 施策5: 招待・残高低下の発火配線

### 変更箇所

- `app/Services/Organization/OrganizationMembershipService.php`: `inviteMember()` L46-86（招待保存 + メール送信後に in-app 通知）
- `app/Services/Billing/TicketLedgerService.php`: `commit()` L263-285（閾値クロス検知 + afterCommit）
- `config/billing.php`: `ticket_low_balance_threshold` 追加

### 変更後コード

```php
// OrganizationMembershipService::inviteMember() — メール Notification::route(...) の直後に追加
// 既存ユーザーが宛先ならアプリ内でも気づけるようにする（メールの補完。平文 token は含めない）
$this->notifications->notifyInvitationReceived($invitation);
// （constructor injection: private readonly NotificationCenterService $notifications を追加）
```

```php
// config/billing.php
/*
| チケット残高低下のアプリ内通知閾値。消費 commit で「閾値以上 → 閾値未満」を
| 跨いだときのみ owner/admin に 1 回通知する（クロス検知 = 消費毎の再通知なし）。
*/
'ticket_low_balance_threshold' => (int) env('BILLING_TICKET_LOW_BALANCE_THRESHOLD', 5),
```

```php
// TicketLedgerService::commit() — 既存 tx 内・appendEntry 後に追加
public function commit(TicketReservation $reservation): void
{
    DB::transaction(function () use ($reservation): void {
        $locked = $this->lockReservationRow($reservation);
        $organization = $locked->organization;
        Assert::isInstanceOf($organization, Organization::class);
        $this->lockOrganizationRow($organization); // ← 既存。org 行ロック下 = クロス判定は直列化済み

        $this->appendEntry(/* 既存のまま */);

        $locked->status = TicketReservationStatus::Committed;
        $locked->save();

        // 閾値クロス検知（org 行ロック下で決定的。予約中拘束を含む実効残高 = balance()）
        $threshold = config()->integer('billing.ticket_low_balance_threshold');
        $after = $this->balance($organization);
        $before = $after + $locked->amount; // commit は Reserved→Committed のため拘束済み分は変わらず、
                                            // 台帳 delta -amount のみが残高を動かす
        if ($before >= $threshold && $after < $threshold) {
            // afterCommit: commit は pipeline の terminal tx 内から savepoint で呼ばれるため、
            // 最外層 commit 成立後にのみ通知（rollback 時は発火しない）
            DB::afterCommit(fn () => $this->notifications->notifyTicketBalanceLow($organization, $after, $threshold));
        }
    });

    $reservation->refresh();
}
```

> 注: `balance()` は「有効台帳合計 − Reserved 拘束」。Reserved→Committed の commit では拘束 `-amount` と
> 台帳 `-amount` が同時に動くため、**commit 前後で balance() は変化しない**。よって before/after は台帳側の
> 変化で判定する: `after = balance()` / `before = after + $locked->amount` は「この commit が無かった場合の
> 実効残高」。この算術は Feature テストで固定する。

### 波及変更

- `TicketLedgerService` constructor に `NotificationCenterService` 注入 → 循環依存なし（NotificationCenterService は TicketLedgerService に依存しない）
- 既存 `BillingNotificationDispatcher` / `billing_notifications`: **無変更**（メール送達台帳系はそのまま。ticket_balance_low は billing_notifications に行を作らない = 二重管理なし）
- `.env.example`: `BILLING_TICKET_LOW_BALANCE_THRESHOLD` 追記（`EnvExampleInvariantTest` 対応）

### PHPStan適合チェック

- [x] `config()->integer()`（型付き取得。既存規約）
- [x] closure の戻り値型 / `Assert::isInstanceOf`

### テスト計画

- [ ] `tests/Feature/Notifications/TicketBalanceLowNotificationTest.php`:
  - 残高が閾値を跨ぐ commit → owner/admin に各 1 件、member には作られない
  - 既に閾値未満の状態でさらに commit → 通知されない（クロスのみ）
  - grant で回復後に再度跨ぐ → 再通知される
  - rollback される tx 内の commit → 通知されない（afterCommit）
- [ ] `tests/Feature/Notifications/InvitationNotificationTest.php`:
  - 既存ユーザーの email へ招待 → その User に 1 件（whereBlind 一致。payload に token を含まない）
  - 未登録 email へ招待 → 通知 0（メールのみ）
  - 招待メール（既存 `OrganizationInvitationNotification`）は従来どおり送信される

### リスク

- commit 内のクロス判定は org 行ロック下のため並行 commit と直列化済み（二重通知なし）
- 通知は afterCommit + safely のため課金 2 フェーズ（不変条件 7）へ影響しない

---

## 施策6: ルート + Controller + 読み出し DTO + shared props

### 変更箇所

- `routes/web.php`: `auth + verified` 群（`require-active-subscription` の**外**。L151 の group 直下、/billing 群の近く）
- 新規: `app/Http/Controllers/NotificationController.php`
- 新規: `app/DataTransferObjects/Notification/NotificationListItemData.php`
- `app/Http/Middleware/HandleInertiaRequests.php`: `share()` L48-79 に `notifications.unreadCount` 追加

### 変更後コード

```php
// routes/web.php（auth+verified 群内・require-active-subscription の外）
// 通知センター。{notification} は implicit binding を使わず controller が
// $request->user()->notifications() 経由で解決する（cross-user は構造的に 404 =
// 存在オラクル封じ。1 param のため NestedRouteIdorDefenseTest の inventory 対象外）。
Route::get('/notifications', [NotificationController::class, 'index'])
    ->name('notifications.index');
Route::post('/notifications/read-all', [NotificationController::class, 'readAll'])
    ->name('notifications.read-all');
Route::post('/notifications/{notification}/open', [NotificationController::class, 'open'])
    ->name('notifications.open');
Route::post('/notifications/{notification}/read', [NotificationController::class, 'read'])
    ->name('notifications.read');
```

```php
// app/Http/Controllers/NotificationController.php（薄い Controller。Service 委譲）
class NotificationController extends Controller
{
    public function __construct(private readonly NotificationCenterService $notifications) {}

    /** 通知一覧（全 org 横断 = 自分宛のみで構造的に閉じる） */
    public function index(Request $request): Response
    {
        $user = $this->authedUser($request);
        $paginator = $this->notifications->paginateFor($user);

        return Inertia::render('Notifications/Index', [
            'notifications' => array_map(
                static fn (DatabaseNotification $n): array => NotificationListItemData::fromNotification($n)->toArray(),
                $paginator->items(),
            ),
            'pagination' => [/* current_page/last_page/total（既存 ManualListItem のページャ shape と同形） */],
        ]);
    }

    /** 既読化 + 遷移先のサーバ解決（POST + 303。GET にしない = prefetch 既読化防止） */
    public function open(Request $request, string $notification): RedirectResponse
    {
        $user = $this->authedUser($request);
        $found = $this->notifications->findOwnOrFail($user, $notification); // cross-user 404
        $this->notifications->markRead($found);

        $item = NotificationListItemData::fromNotification($found);

        // 種別ごとの遷移先解決。開けない場合は一覧へ明示 redirect（back() の Referer ループ回避）
        return match (true) {
            // manual 系: 通知 org ≠ current org → 案内して一覧へ（自動 org 切替はしない）
            $item->isManualJob() && ! $this->belongsToCurrentOrg($request, $found) =>
                redirect()->route('notifications.index')
                    ->with('info', 'この通知は別の組織のものです。組織を切り替えてから開いてください。'),
            // manual 系: current org 配下に manual が現存 → manual 画面へ（遷移先の認可・404 は
            // 既存の project.in-current-org + scopeBindings 防御に委ねる = 認可判断を複製しない）
            $item->isManualJob() && $this->manualExists($request, $item) =>
                redirect()->route('projects.manuals.show', [$item->projectId(), $item->manualId()]),
            $item->isManualJob() =>
                redirect()->route('notifications.index')->with('info', '対象の動画マニュアルは削除されています。'),
            $item->type === NotificationType::TicketBalanceLow =>
                redirect()->route('billing.tickets.show'),
            // invitation_received ほか遷移先なし: 既読化のみで一覧に留まる
            default => redirect()->route('notifications.index')
                ->with('info', '招待はメールの受諾リンクから参加してください。'),
        };
    }

    /** 1 件既読化（back() 完結） */
    public function read(Request $request, string $notification): RedirectResponse
    {
        $user = $this->authedUser($request);
        $this->notifications->markRead($this->notifications->findOwnOrFail($user, $notification));

        return back();
    }

    /** 一括既読化（back() 完結） */
    public function readAll(Request $request): RedirectResponse
    {
        $this->notifications->markAllRead($this->authedUser($request));

        return back()->with('success', 'すべての通知を既読にしました');
    }
}
```

```php
// NotificationListItemData — DatabaseNotification 生配列を渡さない読み出し境界
final readonly class NotificationListItemData
{
    public function __construct(
        public string $id,
        public NotificationType $type,          // tryFrom で復元。未知 type は fromNotification が rawType のみで組む
        public ?string $rawType,                // 未知 type の fallback 表示用
        public ?int $organizationId,
        public ?string $readAt,                 // ISO8601
        public string $createdAt,               // ISO8601
        public ManualJobPayload|InvitationReceivedPayload|TicketBalanceLowPayload|null $payload,
    ) {}

    public static function fromNotification(DatabaseNotification $notification): self
    { /* type=tryFrom → 種別ごとの Payload::tryFromArray。復元失敗は payload=null（fallback 表示） */ }

    /** @return array{id: string, type: string, organization_id: int|null, read_at: string|null,
     *   created_at: string, payload: array<string, int|string|bool|null>|null} */
    public function toArray(): array { /* TS NotificationItem と対 */ }
}
```

```php
// HandleInertiaRequests::share() へ追加（closure = Inertia partial reload で省略可能。
// 将来の only:['notifications'] ポーリング拡張にもそのまま使える）
'notifications' => [
    'unreadCount' => fn (): int => $user?->unreadNotifications()->count() ?? 0,
],
```

### 波及変更

- TypeScript型定義: `resources/js/types/notification.ts`（施策7で新設。shared props の `notifications.unreadCount` も型定義）
- テストファイル: `tests/Feature/Inertia/SharedAuthPropsTest.php` 相当に unreadCount の共有を追加（または新設 `NotificationSharedPropsTest`）
- `DatabaseNotification::$organization_id` への型付きアクセスは `NotificationListItemData::fromNotification` 内で `is_int` 検査（morph 生モデルの attribute 取得）

### PHPStan適合チェック

- [x] Controller は薄く Service 委譲 / `authedUser` で `User` へ narrowing（`Assert::isInstanceOf`）
- [x] union 型 payload の match 分岐に default あり / DTO 返却（生配列なし）
- [x] `response()->json()` 不使用（Inertia + RedirectResponse のみ）

### テスト計画

- [ ] `tests/Feature/Notifications/NotificationCenterTest.php`:
  - index: 自分宛のみ表示（他人の通知が混ざらない）・未読/既読・ページネーション・**全 org 横断で表示**
  - read: 自分の通知は既読化 / **他人の通知 uuid は 404**（403 でない = 存在秘匿）/ 存在しない uuid も 404
  - read-all: 自分の未読のみ全既読（他人の行に影響しない）
  - open: manual 現存 + 同一 org → manuals.show へ 303 / manual 削除済み → 一覧 + info / 通知 org ≠ current org → 一覧 + info / ticket_balance_low → billing.tickets.show / invitation → 一覧 + info / cross-user 404 / **GET /notifications/{id}/open は 405**（POST 限定の固定）
  - 未認証は login へ / unverified は verified ガード
- [ ] shared props: ログイン時 unreadCount が正数で共有される・未ログイン画面で 0/欠落しない

### リスク

- 未読 count が全 Inertia 応答に 1 クエリ追加 → 複合 index + closure（partial reload 省略）で軽微
- `open` の遷移先判定はサーバ側の存在確認 1 クエリ（単発・許容）

---

## 施策7: フロントエンド

### 変更箇所

- 新規: `resources/js/types/notification.ts`
- 新規: `resources/js/components/molecules/NotificationBell.svelte`
- 新規: `resources/js/components/features/notifications/NotificationListItem.svelte`
- 新規: `resources/js/pages/Notifications/Index.svelte`
- `resources/js/components/templates/AppLayout.svelte`: ヘッダー L35-44 にベル配置

### 変更後コード（骨子）

```typescript
// resources/js/types/notification.ts
/** PHP: App\Enums\Notification\NotificationType と対（値集合を一致させる。Architecture テストで固定） */
export type NotificationType =
    | "manual_analyzed"
    | "manual_rendered"
    | "invitation_received"
    | "ticket_balance_low";

export interface ManualJobPayload {
    project_id: number;
    manual_id: number;
    manual_title: string;
    organization_name: string;
    succeeded: boolean;
    error: string | null;
}
export interface InvitationReceivedPayload { organization_name: string; }
export interface TicketBalanceLowPayload { organization_name: string; balance: number; threshold: number; }

/** type を discriminant にした union。未知 type（string）は fallback 描画 */
export interface NotificationItem {
    id: string;
    type: NotificationType | (string & {});
    organization_id: number | null;
    read_at: string | null;
    created_at: string;
    payload: ManualJobPayload | InvitationReceivedPayload | TicketBalanceLowPayload | null;
}

export interface NotificationSharedProps { unreadCount: number; }
```

```svelte
<!-- molecules/NotificationBell.svelte: Bell (Lucide) + 未読バッジ。/notifications への Inertia link -->
<script lang="ts">
    import { Bell } from "@lucide/svelte";
    import { Link } from "@inertiajs/svelte";
    interface Props { unreadCount: number; }
    let { unreadCount }: Props = $props();
    const badge = $derived(unreadCount > 99 ? "99+" : String(unreadCount));
</script>
<Link href="/notifications" class="relative ... (DS token のみ)" aria-label="通知">
    <Bell class="size-5" />
    {#if unreadCount > 0}
        <span class="absolute ... bg-danger text-inverse ..." data-testid="unread-badge">{badge}</span>
    {/if}
</Link>
```

- `AppLayout.svelte`: `header` 内・`headerActions` の**前**に、ログイン時のみ
  `<NotificationBell unreadCount={notifications?.unreadCount ?? 0} />` を常設
  （`page.props.notifications` を `NotificationSharedProps` として narrow。template → molecule は単方向 import 適合）。
- `pages/Notifications/Index.svelte`: `AppLayout` + `NotificationListItem` のリスト + 既存 `Pagination` molecule +
  `EmptyState`（0 件時）+「すべて既読にする」ボタン（`router.post(route read-all 相当のパス)`。未読 0 でも
  **disabled にしない** — 押下時は成功 flash のみ）。
- `features/notifications/NotificationListItem.svelte`: type ごとにアイコン（`FileSearch`/`Film`/`Mail`/`TicketMinus` 等
  Lucide のみ）と文言を組み立て。行クリック = `router.post(/notifications/{id}/open)`（POST 遷移）。未読は
  `bg-*` token でハイライト。**未知 type は汎用アイコン + rawType 表示の fallback**（enum⇔TS ドリフト耐性）。

### 波及変更

- `tests/js/architecture/atomic-import-graph.test.ts`: features/notifications は自動走査（他 domain への横参照を作らないので追加対応なし）
- ds-purity / lucide-scoped-import / typography 各 invariant テスト: 新規コンポーネントも自動対象（token のみ・Lucide のみで書く）

### PHPStan適合チェック（TS 側は typecheck）

- [x] `NotificationItem` union + discriminant で `pnpm typecheck` green
- [x] `(string & {})` で未知 type を型として許容しつつ補完を保つ

### テスト計画（Vitest）

- [ ] `NotificationBell.test.ts`: unreadCount=0 でバッジ非表示 / 5 で「5」/ 100 で「99+」/ disabled 属性を一切持たない
- [ ] `NotificationListItem.test.ts`: manual_analyzed 成功/失敗の文言・org バッジ・未読ハイライト / 未知 type の fallback 描画
- [ ] `NotificationsIndex.test.ts`（pages はコンポーネントテストで）: 空状態 EmptyState / read-all ボタンが disabled でない

### リスク

- AppLayout は全認証画面の共有骨格 → ベル追加は表示のみの変更（既存 snippet 構造は不変）。既存 `AppLayoutFlashToast` 系テストへの影響なし

---

## 施策8: テスト一覧（新規分の総括）

| テスト | 種別 | 検証内容 |
|---|---|---|
| `tests/Feature/Notifications/NotificationSchemaTest.php` | Feature | 発火で type=enum 値・organization_id 列・data 形状。unread count クエリ |
| `tests/Feature/Notifications/ManualAnalysisNotificationTest.php` | Feature | 成功/失敗で 1 件・二重発火なし（failJob 冪等）・creator∪triggeredBy dedup・退会者除外 |
| `tests/Feature/Notifications/ManualRenderNotificationTest.php` | Feature | render 成功/失敗・preview は通知 0・recoverStale 経由の失敗通知 |
| `tests/Feature/Notifications/InvitationNotificationTest.php` | Feature | whereBlind 一致で 1 件・未登録は 0・token 非含有・既存メール通知は不変 |
| `tests/Feature/Notifications/TicketBalanceLowNotificationTest.php` | Feature | 閾値クロスのみ・owner/admin のみ・回復後の再クロスで再通知・rollback 非発火 |
| `tests/Feature/Notifications/NotificationCenterTest.php` | Feature | index/read/read-all/open の全分岐・cross-user 404・GET open 405・unverified ガード |
| `tests/Feature/Notifications/NotificationSharedPropsTest.php` | Feature | shared props unreadCount（ログイン/未ログイン） |
| `tests/Architecture/NotificationTypeTsSyncInvariantTest.php` | Architecture | `NotificationType` ⇔ `types/notification.ts` の値集合一致（`ManualEnumTsSyncInvariantTest` と同パターン） |
| `tests/js/.../NotificationBell.test.ts` ほか | Vitest | バッジ・fallback・disabled 不使用 |

既存テストの回帰確認: `AnalysisPipelineTest` / `RenderPipelineTest` / `ManualAnalyzeTest` / `RenderTriggerTest` /
`AnalysisRecoverStaleJobsTest` / `RenderStaleRecoveryTest` / Billing 系（`Notification::fake()` の追加が必要な箇所を更新）。

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | standalone |
| 判断根拠 | migration 2 本 + サービス横断の発火配線 + 共有レイアウト変更を含み、施策間の依存が直列（1→2→3→4/5→6→7→8）。単一 worktree でテストまで一気通貫が安全 |
| 競合リスク | `AppLayout.svelte`・`HandleInertiaRequests.php`・`routes/web.php` は他フィーチャと衝突しやすい共有点だが、いずれも追記のみ。`AnalysisJobService`/`RenderJobService` の constructor 変更は DI 経由のため呼び出し側影響なし |

## 実装順序（依存）

1. 施策1（migration + 保護キー）→ 2（型契約）→ 3（Service）
2. 施策4/5（発火配線。既存テストの green を維持しながら）
3. 施策6（ルート/Controller/shared props）→ 7（フロント）
4. 施策8（テスト。ただし各施策は TDD で fail-first）
