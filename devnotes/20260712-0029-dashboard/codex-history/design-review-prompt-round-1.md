# 使命・禁止事項・セキュリティ不変条件（AGENTS.md より）

## アプリの使命 (North Star)

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

v1 スコープ: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

## 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)

## セキュリティ不変条件(アプリ都合で緩めない)

1. **tenant キー不信**: ownership/actor/tenant キーを payload から受け取らない
2. **子は親に属する**: nested route の不整合は認可より前に 404
3. **cross-org 不可**: 組織を跨ぐ read/write をしない(relation / org-scoped 解決経由のみ)
4. untrusted 文字列は UserInput 型経由でのみ prompt に入れる
5. **権限判定は常に `laratrust_team_id` を明示**(strict_check=true)
6. PII(email/name)は CipherSweet。検索は `whereBlind()`
7. 課金の冪等性: webhook は冪等マシン経由、チケットは reserve→commit/release の 2 フェーズ
8. 外部 URL 取得は SSRF 検査経由

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

あなたは経験豊富なWebアプリケーションアーキテクトです。Laravel + Svelte アプリケーション改善の詳細設計をレビューしてください。

【前提環境】
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- PHPStan level 10
- Pestテストフレームワーク
- DTO + JsonResource パターン
- Laratrust RBAC（Organization → Team → Project階層）

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
10. DESIGN.md準拠（UI/frontend 変更を含む場合）: `/DESIGN.md` が design token の canonical source。color / radius / typography を token 経由で参照する設計か、hex 直書きを増やさないか
11. Atomic Design準拠（UI/frontend 変更を含む場合）: atoms/molecules/organisms/templates の責務分離、アイコンは Lucide 前提で SVG 直書きを新設していないか

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

必要に応じてリポジトリ内の実ファイルを読んで整合性を確認してよい（読み取りのみ）。特に:
routes/web.php / app/Http/Concerns/ResolvesCurrentOrganization.php / app/Services/Project/DefaultProjectResolver.php /
app/Services/Organization/OrganizationMembershipService.php / app/Models/{User,VideoManual,Cut,Organization}.php /
app/Services/Billing/{QuotaService,TicketLedgerService,BillingAccess}.php / app/Services/Capture/StorageUsageService.php /
app/Policies/ProjectPolicy.php / app/Http/Controllers/Capture/CaptureManualController.php /
app/Http/Middleware/HandleInertiaRequests.php / resources/js/pages/{Dashboard.svelte,Projects/Show.svelte} /
resources/js/lib/shared-props.ts / resources/js/types/manual.ts / config/{quota.php,billing.php,seo.php} / tests/Pest.php

---

## 詳細設計書

# 詳細設計: dashboard（進行中ジョブ / 最近のマニュアル / 残高）

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

v1 スコープ: 字幕のみ / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

### 禁止事項（AGENTS.md）

1. テストなしの実装完了報告（不変条件は Architecture/Feature テストへの登録まで含めて「実装済み」）
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作をエージェント判断で実行
4. `response()->json()` の直書き（DTO / JsonResource / Inertia を使う）
5. LLM 呼び出しの Prism 直呼び
6. prompt 文字列のコード直書き
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI

### セキュリティ不変条件（該当分）

- tenant キー不信（本設計は GET のみ・payload なし）
- cross-org 不可: 集計は relation / org-scoped 解決経由のみ
- 権限判定は常に `laratrust_team_id` 明示（ProjectPolicy / OrganizationPolicy へ委譲）

### コーディングルール

- **PHPStan level 10** 必須（`composer phpstan`）
- **Pest**テストフレームワーク（`composer test`）
- **RefreshDatabase** + `--parallel` 並列実行（個別 `DatabaseTransactions` 使用禁止）
- **テストデータは必ず Factory で生成**
- 新モデルなし（Factory 追加なし。`docs/architecture.md` / `docs/factories.md` への追記不要）
- **DTO + typed array** パターン（Inertia props。`toArray(): array{...}` で shape 固定）
- アーリーリターン推奨 / `composer fix`（Pint）/ `pnpm lint:fix`
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript

## 概念設計リファレンス

`devnotes/20260712-0029-dashboard/conceptual-design.md`（Codex 概念レビュー Round 4 APPROVED）

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | CurrentOrganizationResolver（current org の所属再確認つき解決 + 自己修復） | `app/Services/Organization/CurrentOrganizationResolver.php`（新規）, `tests/Feature/Organization/CurrentOrganizationResolverTest.php`（新規） | 高 |
| 2 | ダッシュボード集計（DashboardService + ブロック単位 DTO 群） | `app/Services/Dashboard/DashboardService.php`（新規）, `app/DataTransferObjects/Dashboard/{DashboardPageData,InProgressManualData,RecentManualData,ShootingTargetData,BillingSummaryData}.php`（新規）, `app/Enums/Dashboard/DashboardRole.php`（新規） | 高 |
| 3 | DashboardController + route 差し替え + Feature テスト | `app/Http/Controllers/DashboardController.php`（新規）, `routes/web.php`（L153-155 差し替え）, `tests/Feature/DashboardTest.php`（新規） | 高 |
| 4 | Dashboard.svelte 全面書き換え + TS 型 + Vitest | `resources/js/pages/Dashboard.svelte`（書き換え）, `resources/js/types/dashboard.ts`（新規）, `resources/js/types/manual.ts`（STATUS_TONES 移設）, `resources/js/pages/Projects/Show.svelte`（STATUS_TONES import 化）, `tests/js/pages/Dashboard.test.ts`（新規） | 高 |

---

## 施策 1: CurrentOrganizationResolver

### 変更箇所

- ファイル: `app/Services/Organization/CurrentOrganizationResolver.php`（新規）

### 波及変更

- TypeScript型定義: なし
- API Resource/DTO: なし
- テストファイル: `tests/Feature/Organization/CurrentOrganizationResolverTest.php`（新規）

### 現行コード

存在しない。`current_organization_id` の null 化は `OrganizationMembershipService::removeMember`（L349-351）が行い「次回アクセス時に選び直す」とコメントするが、選び直す実装はどこにもない。`ResolvesCurrentOrganization` trait は null を 404 に倒すのみ。

### 変更後コード

```php
<?php

declare(strict_types=1);

namespace App\Services\Organization;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Webmozart\Assert\Assert;

/**
 * current organization の「所属再確認つき」解決 + 自己修復 (概念設計 表示組織の解決規則)。
 *
 * removeMember は current org からの除名時に current_organization_id を null 化するが
 * 「選び直す」実装は本 Service が初出。v1 の呼び出し元は DashboardController のみ
 * (他画面への展開は後続。ResolvesCurrentOrganization trait は従来どおり null=404)。
 *
 * 競合契約 (概念レビュー Round 2-4 で確定):
 * - 表示の安全性は「読み出し時の所属再確認」で担保する。current が指す org は常に
 *   pivot relation で所属を再確認してから返す = 非所属 org (dangling) を描画に出さない
 * - 書き込みは best-effort の冪等修復。単一の条件付き UPDATE
 *   (current IS NULL または観測した dangling 値のまま、かつ所属 pivot が存続) のみ
 * - UPDATE 成否によらず fresh 再取得 → 所属再確認 1 回のみ。解決不能なら null (無限再試行しない)
 */
class CurrentOrganizationResolver
{
    /** 表示組織を解決する。null = 所属組織 0 件 (または競合で解決不能) */
    public function resolve(User $user): ?Organization
    {
        // 1. current の所属再確認つき読み出し (dangling は null 扱いに倒す)
        $current = $this->membershipVerified($user, $user->current_organization_id);
        if ($current !== null) {
            return $current;
        }

        // 2. 自己修復: 決定的候補 (organizations.id 昇順の先頭)
        $observed = $user->current_organization_id; // null または dangling 値
        $candidateId = $user->organizations()->orderBy('organizations.id')->value('organizations.id');
        if ($candidateId === null) {
            return null; // 所属 0 件 → setup 表示
        }
        Assert::integerish($candidateId);

        // 原子的条件付き UPDATE: 観測値のまま + 所属存続のときのみ設定
        // (除名 tx が先に commit していれば whereHas が偽 → 0 件更新 = 修復しない。
        //  観測後に別 org へ変更済みなら WHERE 不一致 → 上書きしない)
        User::query()
            ->whereKey($user->getKey())
            ->where(function (Builder $query) use ($observed): void {
                $query->whereNull('current_organization_id');
                if ($observed !== null) {
                    $query->orWhere('current_organization_id', $observed);
                }
            })
            ->whereHas('organizations', fn (Builder $query) => $query->whereKey((int) $candidateId))
            ->update(['current_organization_id' => (int) $candidateId]);

        // 3. 成否によらず relation キャッシュ破棄 + fresh 再取得 → 所属再確認 (1 回のみ)
        $user->refresh();

        return $this->membershipVerified($user, $user->current_organization_id);
    }

    /** 所属再確認つき読み出し (pivot relation 経由 = cross-org を構造的に排除) */
    private function membershipVerified(User $user, ?int $organizationId): ?Organization
    {
        if ($organizationId === null) {
            return null;
        }

        /** @var Organization|null */
        return $user->organizations()->whereKey($organizationId)->first();
    }
}
```

実装ノート:
- `current_organization_id` は保護キー（`MassAssignmentProtectedKeys`）だが、Query Builder の `update()` は fillable を経由しない**サーバ導出のみ**の書き込み（`OrganizationSwitchController` の forceFill と同じ位置づけ。payload 値は一切使わない）
- `value()` の戻りは mixed のため `Assert::integerish` で絞る（PHPStan lv10）
- `refresh()` は attributes と loaded relations の両方を破棄する（Laravel 標準）— Round 3 指摘の stale relation 対策

### PHPStan適合チェック

- [x] 戻り値の型が明示されている（`?Organization`）
- [x] null安全（`Assert::integerish`、早期 return）
- [x] DTOを返している（Model 返却は Eloquent の標準パターン。配列返却なし）
- [x] Genericsの型パラメータ（`Builder` closure の型付け、`@var Organization|null`）

### テスト計画（`tests/Feature/Organization/CurrentOrganizationResolverTest.php`）

概念レビューで確定した競合契約 5 ケース + 基本 2 ケース:

- [ ] current 非 null + 所属あり → その org を返す（書き込みなし）
- [ ] 所属 0 件 → null（current は変更されない）
- [ ] **org はあるが current null → 候補（organizations.id 昇順先頭）へ自己修復し、その org を返す**
- [ ] **current が非所属 org を指す（dangling を forceFill で手動作成）→ 当該 org を返さず、所属 org へ自己修復**
- [ ] **候補 membership が UPDATE 前に消失 → current を設定しない（null のまま・null を返す）**: `organizations()` の観測後に detach してから resolve の続きを実行するのは単体では再現困難のため、「所属 0 件だが observed=dangling」の形（detach 済み + current=旧 org id）で whereHas 偽 → 0 件更新 → null を検証する
- [ ] **観測後に current が別 org へ変更済み → 上書きしない**: 条件付き UPDATE の WHERE を直接検証（current = org B に設定済みのユーザーに対し、observed=null 相当の呼び出しでは membershipVerified が org B を返し UPDATE 自体に到達しない + WHERE 句が `IS NULL OR = observed` のみであることを「dangling 観測値と異なる current では更新 0 件」で固定）
- [ ] **条件付き UPDATE が 0 件 → fresh 再取得した最新状態で解決（1 回のみ・解決不能なら null）**
- [ ] 個別の `DatabaseTransactions` を使っていない

### リスク

- GET リクエスト内の書き込み（冪等な整合修復）。副作用は current_organization_id の設定のみで、既存の `OrganizationSwitchController` と同一の意味論。読み出し安全性（所属再確認）が防御線のため、修復の失敗はすべて「setup 表示 or 別 org 表示」に degrade し cross-org 描画には決して倒れない

---

## 施策 2: ダッシュボード集計（DashboardService + DTO 群）

### 変更箇所

- ファイル: `app/Services/Dashboard/DashboardService.php`（新規）
- ファイル: `app/DataTransferObjects/Dashboard/DashboardPageData.php` ほか 4 DTO（新規）
- ファイル: `app/Enums/Dashboard/DashboardRole.php`（新規）

### 波及変更

- TypeScript型定義: `resources/js/types/dashboard.ts`（施策 4。PHP typed array と対）
- API Resource/DTO: なし（新規 DTO のみ。既存 DTO 変更なし）
- テストファイル: `tests/Feature/DashboardTest.php`（施策 3 に集約。Service は Controller 経由の Feature テストで検証 = 既存の CategoryService 等と同じ方針）

### 現行コード

存在しない（`/dashboard` は inline closure で props なしの render のみ）。

### 変更後コード

#### `app/Enums/Dashboard/DashboardRole.php`

```php
<?php

declare(strict_types=1);

namespace App\Enums\Dashboard;

/**
 * ダッシュボード表示ロール (概念設計「ロール差」)。判定はサーバ側で
 * ProjectPolicy へ委譲した結果の写像 (フロントは表示分岐のみ、権限判定を持たない)。
 */
enum DashboardRole: string
{
    case Editor = 'editor';   // ProjectPolicy::update 可 (org owner/admin または project_admin)
    case Shooter = 'shooter'; // update 不可 + ProjectPolicy::capture 可 (project_member)
    case Viewer = 'viewer';   // どちらも不可の組織メンバー
}
```

#### `app/DataTransferObjects/Dashboard/InProgressManualData.php`

```php
<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Dashboard;

use App\Enums\Manual\JobStatus;
use App\Enums\Manual\VideoManualStatus;

/** 進行中ジョブ 1 行 (analyzing/rendering の manual + 進行中 job のスナップショット) */
final readonly class InProgressManualData
{
    public function __construct(
        public int $manualId,
        public string $title,
        public VideoManualStatus $manualStatus,
        public ?JobStatus $jobStatus,     // null = job 行が見つからない過渡状態 (表示は「準備中」)
        public ?int $progress,
        public ?string $jobUpdatedAt,     // 「最終更新」表示 (Y-m-d H:i)
    ) {}

    /**
     * @return array{manual_id: int, title: string, manual_status: string,
     *   job_status: string|null, progress: int|null, job_updated_at: string|null}
     */
    public function toArray(): array
    {
        return [
            'manual_id' => $this->manualId,
            'title' => $this->title,
            'manual_status' => $this->manualStatus->value,
            'job_status' => $this->jobStatus?->value,
            'progress' => $this->progress,
            'job_updated_at' => $this->jobUpdatedAt,
        ];
    }
}
```

#### `app/DataTransferObjects/Dashboard/RecentManualData.php`

```php
/** 最近のマニュアル 1 行 */
final readonly class RecentManualData
{
    public function __construct(
        public int $id,
        public string $title,
        public VideoManualStatus $status,
        public ?string $categoryName,
        public string $updatedAt,
    ) {}

    /**
     * @return array{id: int, title: string, status: string,
     *   category_name: string|null, updated_at: string}
     */
    public function toArray(): array { /* 対応する連想配列 */ }
}
```

#### `app/DataTransferObjects/Dashboard/ShootingTargetData.php`

```php
/** 撮影対象 1 行 (採用待ち cut がある ready/published manual) */
final readonly class ShootingTargetData
{
    public function __construct(
        public int $manualId,
        public string $title,
        public int $cutsCount,
        public int $pendingCutsCount, // 採用テイクなしの cut 数
    ) {}

    /**
     * @return array{manual_id: int, title: string, cuts_count: int, pending_cuts_count: int}
     */
    public function toArray(): array { /* 対応する連想配列 */ }
}
```

#### `app/DataTransferObjects/Dashboard/BillingSummaryData.php`

```php
/** チケット残高 + 容量 Quota (低残高警告と高使用率警告は別個のフラグ) */
final readonly class BillingSummaryData
{
    public function __construct(
        public int $ticketBalance,
        public bool $isLowBalance,          // balance < billing.ticket_low_balance_threshold
        public int $storageUsedBytes,       // StorageUsageService::occupiedBytes
        public ?int $storageLimitBytes,     // QuotaService::limits[max_storage_bytes] (無制限は null)
        public ?int $storageUsagePercent,   // 0-100 に clamp (limit null なら null)
        public bool $hasActiveSubscription, // BillingAccess::hasActiveAccess
    ) {}

    /**
     * @return array{ticket_balance: int, is_low_balance: bool, storage_used_bytes: int,
     *   storage_limit_bytes: int|null, storage_usage_percent: int|null,
     *   has_active_subscription: bool}
     */
    public function toArray(): array { /* 対応する連想配列 */ }
}
```

#### `app/DataTransferObjects/Dashboard/DashboardPageData.php`

```php
/**
 * ダッシュボード props の頂点 DTO。state で 3 状態を明示:
 * - no_organization: 所属組織 0 件 (organization/project/billing すべて null)
 * - no_project: org はあるが project なし (billing のみ非 null)
 * - ready: 通常表示
 */
final readonly class DashboardPageData
{
    /**
     * @param  list<InProgressManualData>  $inProgress
     * @param  list<RecentManualData>  $recentManuals
     * @param  list<ShootingTargetData>  $shootingTargets
     */
    public function __construct(
        public string $state, // 'no_organization'|'no_project'|'ready' (array shape で固定)
        public ?DashboardRole $role,
        public bool $canCreateProject,
        public ?int $projectId,
        public ?string $projectName,
        public array $inProgress,
        public array $recentManuals,
        public array $shootingTargets,
        public ?BillingSummaryData $billing,
    ) {}

    /**
     * @return array{state: 'no_organization'|'no_project'|'ready', role: string|null,
     *   can_create_project: bool, project: array{id: int, name: string}|null,
     *   in_progress: list<array{manual_id: int, title: string, manual_status: string,
     *     job_status: string|null, progress: int|null, job_updated_at: string|null}>,
     *   recent_manuals: list<array{id: int, title: string, status: string,
     *     category_name: string|null, updated_at: string}>,
     *   shooting_targets: list<array{manual_id: int, title: string, cuts_count: int,
     *     pending_cuts_count: int}>,
     *   billing: array{ticket_balance: int, is_low_balance: bool, storage_used_bytes: int,
     *     storage_limit_bytes: int|null, storage_usage_percent: int|null,
     *     has_active_subscription: bool}|null}
     */
    public function toArray(): array { /* 各 DTO の toArray を合成 */ }
}
```

#### `app/Services/Dashboard/DashboardService.php`

```php
<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

use App\DataTransferObjects\Dashboard\BillingSummaryData;
use App\DataTransferObjects\Dashboard\DashboardPageData;
use App\DataTransferObjects\Dashboard\InProgressManualData;
use App\DataTransferObjects\Dashboard\RecentManualData;
use App\DataTransferObjects\Dashboard\ShootingTargetData;
use App\Enums\Dashboard\DashboardRole;
use App\Enums\Manual\JobStatus;
use App\Enums\Manual\RenderKind;
use App\Enums\Manual\VideoManualStatus;
use App\Enums\QuotaKey;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Models\VideoManual;
use App\Services\Billing\BillingAccess;
use App\Services\Billing\QuotaService;
use App\Services\Billing\TicketLedgerService;
use App\Services\Capture\StorageUsageService;
use App\Services\Project\DefaultProjectResolver;
use Illuminate\Contracts\Database\Eloquent\Builder;

/**
 * ダッシュボードのサーバ集計 (読み取り専用。固定本数のクエリで N+1 なし)。
 * 集計対象はすべて $organization / $project の relation 経由 = cross-org 構造的不可。
 * $organization は CurrentOrganizationResolver が所属再確認済みのものだけが渡される契約。
 */
class DashboardService
{
    private const int LIST_LIMIT = 5;

    public function __construct(
        private readonly DefaultProjectResolver $defaultProjects,
        private readonly TicketLedgerService $tickets,
        private readonly QuotaService $quota,
        private readonly StorageUsageService $storage,
        private readonly BillingAccess $billingAccess,
    ) {}

    public function build(User $user, ?Organization $organization): DashboardPageData
    {
        if ($organization === null) {
            return new DashboardPageData(
                state: 'no_organization', role: null, canCreateProject: false,
                projectId: null, projectName: null,
                inProgress: [], recentManuals: [], shootingTargets: [], billing: null,
            );
        }

        $billing = $this->billingSummary($organization);
        $project = $this->defaultProjects->resolve($organization);
        if ($project === null) {
            return new DashboardPageData(
                state: 'no_project', role: null,
                canCreateProject: $user->can('create', [Project::class, $organization]),
                projectId: null, projectName: null,
                inProgress: [], recentManuals: [], shootingTargets: [], billing: $billing,
            );
        }

        $role = $this->resolveRole($user, $project);

        return new DashboardPageData(
            state: 'ready', role: $role, canCreateProject: false,
            projectId: $project->id, projectName: $project->name,
            inProgress: $this->inProgress($project),
            recentManuals: $this->recentManuals($project),
            shootingTargets: $this->shootingTargets($project),
            billing: $billing,
        );
    }

    /** ProjectPolicy へ委譲した結果の写像 (laratrust_team_id 明示判定は Policy 内) */
    private function resolveRole(User $user, Project $project): DashboardRole
    {
        if ($user->can('update', $project)) {
            return DashboardRole::Editor;
        }
        if ($user->can('capture', $project)) {
            return DashboardRole::Shooter;
        }

        return DashboardRole::Viewer;
    }

    /**
     * 進行中ジョブ: analyzing/rendering の manual + 進行中 job (queued/running)。
     * job は relation の制約付き eager load (2 クエリ固定)。in-flight は manual×操作種別
     * あたり 1 本の既存不変条件 (doc/10 §10.8-8) により first() で一意。
     *
     * @return list<InProgressManualData>
     */
    private function inProgress(Project $project): array
    {
        $manuals = $project->manuals()
            ->whereIn('status', [VideoManualStatus::Analyzing, VideoManualStatus::Rendering])
            ->orderByDesc('updated_at')->orderByDesc('id')
            ->limit(self::LIST_LIMIT)
            ->get();

        $manuals->load([
            'analysisJobs' => fn (Builder $query) => $query
                ->whereIn('status', [JobStatus::Queued, JobStatus::Running])->latest('id'),
            'renderJobs' => fn (Builder $query) => $query
                ->where('kind', RenderKind::Render)
                ->whereIn('status', [JobStatus::Queued, JobStatus::Running])->latest('id'),
        ]);

        return $manuals->map(function (VideoManual $manual): InProgressManualData {
            $job = $manual->status === VideoManualStatus::Analyzing
                ? $manual->analysisJobs->first()
                : $manual->renderJobs->first();

            return new InProgressManualData(
                manualId: $manual->id,
                title: $manual->title,
                manualStatus: $manual->status,
                jobStatus: $job?->status,
                progress: $job?->progress,
                jobUpdatedAt: $job?->updated_at?->format('Y-m-d H:i'),
            );
        })->values()->all();
    }

    /** @return list<RecentManualData> */
    private function recentManuals(Project $project): array
    {
        return $project->manuals()->with('category')
            ->orderByDesc('updated_at')->orderByDesc('id')
            ->limit(self::LIST_LIMIT)
            ->get()
            ->map(fn (VideoManual $manual): RecentManualData => new RecentManualData(
                id: $manual->id,
                title: $manual->title,
                status: $manual->status,
                categoryName: $manual->category?->name,
                updatedAt: $manual->updated_at?->format('Y-m-d H:i') ?? '',
            ))->values()->all();
    }

    /**
     * 撮影対象: ready/published かつ採用テイクなしの cut を持つ manual。
     * 採用判定は relation 経由 (adoptedTake) = CaptureManualController::index の既存規約踏襲。
     *
     * @return list<ShootingTargetData>
     */
    private function shootingTargets(Project $project): array
    {
        return $project->manuals()
            ->whereIn('status', [VideoManualStatus::Ready, VideoManualStatus::Published])
            ->whereHas('cuts', fn (Builder $query) => $query->whereDoesntHave('adoptedTake'))
            ->withCount([
                'cuts',
                'cuts as pending_cuts_count' => fn (Builder $query) => $query->whereDoesntHave('adoptedTake'),
            ])
            ->orderByDesc('updated_at')->orderByDesc('id')
            ->limit(self::LIST_LIMIT)
            ->get()
            ->map(fn (VideoManual $manual): ShootingTargetData => new ShootingTargetData(
                manualId: $manual->id,
                title: $manual->title,
                cutsCount: (int) $manual->getAttribute('cuts_count'),
                pendingCutsCount: (int) $manual->getAttribute('pending_cuts_count'),
            ))->values()->all();
    }

    private function billingSummary(Organization $organization): BillingSummaryData
    {
        $balance = $this->tickets->balance($organization);
        $used = $this->storage->occupiedBytes($organization);
        $limit = $this->quota->limits($organization)[QuotaKey::MaxStorageBytes->value] ?? null;
        $percent = ($limit === null || $limit <= 0)
            ? null
            : (int) min(100, floor($used / $limit * 100));

        return new BillingSummaryData(
            ticketBalance: $balance,
            isLowBalance: $balance < config()->integer('billing.ticket_low_balance_threshold'),
            storageUsedBytes: $used,
            storageLimitBytes: $limit,
            storageUsagePercent: $percent,
            hasActiveSubscription: $this->billingAccess->hasActiveAccess($organization),
        );
    }
}
```

実装ノート:
- クエリ本数は固定（進行中 3・最近 2・撮影対象 2 + withCount subquery・残高/容量 3 前後）。全部 relation 起点
- `withCount` のエイリアス属性は `getAttribute` + `(int)` cast（PHPStan lv10 で魔法プロパティを避ける）
- kind=preview の RenderJob は対象外（preview は manual status を rendering にしない既存仕様と整合）
- job が見つからない過渡状態（terminal 直後〜status 戻し前の一瞬）は `jobStatus: null` で表現し UI は「準備中」表示（例外にしない）

### PHPStan適合チェック

- [x] 戻り値の型が明示（DTO / list<DTO>）
- [x] null安全（`?->`、`??`、config()->integer）
- [x] DTOを返している（typed array は toArray の array shape で固定）
- [x] Genericsの型パラメータ（Builder closure、Collection::map の戻り型明示）

### テスト計画

施策 3 の `tests/Feature/DashboardTest.php` に集約（Controller 経由。既存 Service 群と同方針）。

### リスク

- `updated_at` 降順は「編集・状態遷移で並びが変わる」ため直感的だが、テイク登録は manual の updated_at を touch しない（cuts/takes は別テーブル）。v1 は許容（「最近触ったマニュアル」の意味論）

---

## 施策 3: DashboardController + route 差し替え

### 変更箇所

- ファイル: `app/Http/Controllers/DashboardController.php`（新規）
- ファイル: `routes/web.php`（L153-155）

### 波及変更

- TypeScript型定義: `resources/js/types/dashboard.ts`（施策 4）
- API Resource/DTO: なし
- テストファイル: `tests/Feature/DashboardTest.php`（新規）。既存の `/dashboard` 参照テスト（SmokeTest / TwoFactorEnforcementTest / InvitationTest / LocalOnlyMiddlewareTest 等）は「200 / redirect 先」の互換を維持するため**変更不要**（回帰確認のみ）
- SEO タイトル: `config/seo.php` の `app_titles` に `'dashboard' => 'ダッシュボード'` が**登録済み**（変更不要）

### 現行コード

```php
// routes/web.php L153-155
Route::get('/dashboard', function () {
    return Inertia::render('Dashboard');
})->name('dashboard');
```

### 変更後コード

```php
// routes/web.php (auth+verified group 内・課金ゲート外のまま。位置も既存のまま)
Route::get('/dashboard', DashboardController::class)->name('dashboard');
```

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Dashboard\DashboardService;
use App\Services\Organization\CurrentOrganizationResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Webmozart\Assert\Assert;

/**
 * ダッシュボード (ログイン直後の着地点。概念設計 20260712-0029)。
 *
 * - ResolvesCurrentOrganization は使わない (current org なしを 404 にせず setup 表示に倒す)
 * - 表示組織は CurrentOrganizationResolver (所属再確認つき + 自己修復) で解決
 * - 課金ゲート外 (未契約でも状況把握と復帰導線を提供。CTA は billing.index /
 *   billing.tickets.show = どちらも課金ゲート外 route に固定)
 * - route param なし・payload なし = NestedRouteIdorDefenseTest inventory 対象外
 */
final class DashboardController extends Controller
{
    public function __invoke(
        Request $request,
        CurrentOrganizationResolver $organizations,
        DashboardService $dashboard,
    ): Response {
        $user = $request->user();
        Assert::isInstanceOf($user, User::class);

        $organization = $organizations->resolve($user);
        if ($organization !== null) {
            Gate::authorize('view', $organization); // 二重防御 (resolver が所属再確認済み)
        }

        return Inertia::render('Dashboard', [
            'dashboard' => $dashboard->build($user, $organization)->toArray(),
        ]);
    }
}
```

### PHPStan適合チェック

- [x] 戻り値の型が明示（`Response`）
- [x] null安全（`Assert::isInstanceOf`、org null の早期分岐）
- [x] DTOを返している（DashboardPageData::toArray）
- [x] Generics 該当なし

### テスト計画（`tests/Feature/DashboardTest.php`）

`createOrganizationWithOwner` / `attachOrganizationMember` / 各 Factory（VideoManualFactory / AnalysisJobFactory / RenderJobFactory / CutFactory / TakeFactory / CategoryFactory / ProjectFactory）を使用。

- [ ] **集計正当性**: analyzing manual + running AnalysisJob(progress=40) / rendering manual + running RenderJob(kind=render) が `in_progress` に progress・job_updated_at 付きで出る。preview の RenderJob は出ない
- [ ] **最近のマニュアル**: updated_at 降順 5 件・6 件目が出ない・category_name / status が正しい
- [ ] **撮影対象**: ready manual の未採用 cut 数が pending_cuts_count に出る。全 cut 採用済み manual は出ない。draft manual は出ない
- [ ] **残高/容量**: grant 済み残高が ticket_balance に出る。threshold 未満で is_low_balance=true。takes.size_bytes 合計と plan limit から storage_usage_percent が正しい
- [ ] **cross-org 分離**: 別 org の manual / job / take が一切混入しない（in_progress / recent / shooting / storage 集計すべて）
- [ ] **ロール**: org owner → role=editor / project_member(撮影のみ) → shooter / project 非所属の org member → viewer
- [ ] **空状態**: 所属 org なし → state=no_organization で 200。org あり project なし → state=no_project + can_create_project（owner は true / member は false）。project あり manual 0 件 → ready + 空 list
- [ ] **current org 自己修復**: org はあるが current null → 200 + 当該 org のデータ + current_organization_id が修復されている（施策 1 のテストと合わせ 2 層）
- [ ] **未契約 org**: `createOrganizationWithOwner(subscribed: false)` → dashboard 200 + has_active_subscription=false + CTA 遷移先 `/purchase-tickets` `/billing` も 200（redirect loop なし不変条件）
- [ ] **ゲスト**: 302 → /login（既存挙動維持）
- [ ] 個別の `DatabaseTransactions` を使っていない

### リスク

- ログイン直後の全ユーザーが通る画面のためクエリ増（〜10 本）。全て limit 付き・index の効く FK 検索で v1 規模では問題なし（計測は後続）
- `redirect()->intended(route('dashboard'))` の既存着地点仕様は不変（振る舞い互換）

---

## 施策 4: Dashboard.svelte + TS 型 + Vitest

### 変更箇所

- ファイル: `resources/js/pages/Dashboard.svelte`（全面書き換え）
- ファイル: `resources/js/types/dashboard.ts`（新規）
- ファイル: `resources/js/types/manual.ts`（`STATUS_TONES` を共有 const として移設・export）
- ファイル: `resources/js/pages/Projects/Show.svelte`（ローカル `STATUS_TONES` 定義を削除し import に置換）

### 波及変更

- TypeScript型定義: `dashboard.ts` 新規（PHP `DashboardPageData::toArray` の array shape と対）。ページ契約は `DashboardProps & SharedProps` の合成（未読数は SharedProps 側を参照 = 契約 1 本化）
- API Resource/DTO: なし
- テストファイル: `tests/js/pages/Dashboard.test.ts`（新規）。`Projects/Show.svelte` の既存 Vitest（あれば）は STATUS_TONES 移設の回帰確認

### 現行コード

`Dashboard.svelte`（52 行の雛形）: EmptyState のみ。headerActions に 設定 / ログアウト。

### 変更後コード（構成）

```ts
// resources/js/types/dashboard.ts (PHP: DashboardPageData::toArray と対で保守)
import type { VideoManualStatus } from "@/types/manual";

export type DashboardState = "no_organization" | "no_project" | "ready";
export type DashboardRole = "editor" | "shooter" | "viewer";
export type DashboardJobStatus = "queued" | "running"; // 進行中のみ (terminal は出ない)

export interface InProgressManual {
    manual_id: number;
    title: string;
    manual_status: Extract<VideoManualStatus, "analyzing" | "rendering">;
    job_status: DashboardJobStatus | null; // null = 過渡状態 (「準備中」表示)
    progress: number | null;
    job_updated_at: string | null;
}

export interface RecentManual {
    id: number;
    title: string;
    status: VideoManualStatus;
    category_name: string | null;
    updated_at: string;
}

export interface ShootingTarget {
    manual_id: number;
    title: string;
    cuts_count: number;
    pending_cuts_count: number;
}

export interface BillingSummary {
    ticket_balance: number;
    is_low_balance: boolean;
    storage_used_bytes: number;
    storage_limit_bytes: number | null;
    storage_usage_percent: number | null;
    has_active_subscription: boolean;
}

export interface DashboardData {
    state: DashboardState;
    role: DashboardRole | null;
    can_create_project: boolean;
    project: { id: number; name: string } | null;
    in_progress: InProgressManual[];
    recent_manuals: RecentManual[];
    shooting_targets: ShootingTarget[];
    billing: BillingSummary | null;
}

/** ページ props (Inertia)。共有 props は SharedProps を合成して参照する (契約 1 本化) */
export interface DashboardProps {
    dashboard: DashboardData;
}
```

`Dashboard.svelte` の構成（Svelte 5 runes・DS token のみ・Lucide のみ・atomic 単方向 import）:

- `let { dashboard }: DashboardProps = $props();` + `page.props as unknown as SharedProps`（未読数・ユーザー名）
- **state 分岐**:
  - `no_organization` → EmptyState + `組織を作成` Button（href `/organizations/create`）
  - `no_project` → EmptyState + `can_create_project` のとき `プロジェクトを作成` Button（href `/projects/create`）/ false のとき案内文のみ（**disabled にしない = 非描画**）+ billing タイル
  - `ready` → 下記グリッド
- **スタットタイル行**（`StatCard` molecule 再利用・grid 4 枚）: チケット残高（`Ticket` icon。`is_low_balance` で subtext「残高が少なくなっています」+ `チケットを購入` TextLink → `/purchase-tickets`）/ 容量使用率（`HardDrive`。percent 表示 + used/limit の可読表記。limit null は「無制限」）/ 未読通知（`Bell`。SharedProps.notifications.unreadCount + `/notifications` へ TextLink）/ 進行中ジョブ数（`Loader`。in_progress.length）
- **未契約 callout**（`billing.has_active_subscription === false` のとき）: Card + 案内文 + `プランを見る` Button（href `/billing`）
- **進行中ジョブ Card**（in_progress.length > 0 のとき）: 各行 = title + Badge（analyzing=解析中 tertiary / rendering=書き出し中 warning。`STATUS_TONES`/`VIDEO_MANUAL_STATUS_LABELS` を types/manual.ts から import）+ progress bar（DS token の `bg-primary` 幅 %。`role="progressbar"` + aria 値）+「最終更新 {job_updated_at}」+ `詳細で最新の進捗を確認` TextLink → `/projects/{project.id}/manuals/{manual_id}`
- **最近のマニュアル Card**（role=editor / viewer が主表示）: 各行 = title（→ show）+ status Badge + category_name + updated_at。editor には 編集 TextLink（→ edit）。0 件時は EmptyState「最初のマニュアルを作成」CTA（editor のみ Button 描画。shooter/viewer は案内文）
- **撮影対象 Card**（全ロール表示・shooter では最近のマニュアルより先頭に配置）: 各行 = title + 「残り {pending_cuts_count}/{cuts_count} カット」+ `撮影する` Button（href `/app/projects/{project.id}/manuals/{manual_id}`）。0 件時は「撮影対象はまだありません」
- **クイックアクション**（Card + Button 群）: editor → `新規マニュアル作成`（`/projects/{id}/manuals/create`）・`カテゴリ管理`（`/projects/{id}/categories`）・`撮影アプリを開く`（`/app`）。shooter → `撮影アプリを開く` のみ。viewer → なし（非描画）
- headerActions（設定 / ログアウト）は現行を維持

### PHPStan適合チェック

- [x] 該当なし（フロントのみ。TS は `pnpm typecheck` で担保）

### テスト計画（`tests/js/pages/Dashboard.test.ts`）

既存 `tests/js/pages/*.test.ts`（NotificationsIndex 等）のパターン踏襲（@testing-library/svelte）:

- [ ] ready 状態: スタットタイル（残高/容量/未読/進行中）が値どおり描画される
- [ ] 進行中ジョブ行の progress / 最終更新 / 詳細導線 href が正しい
- [ ] role=editor: 新規作成・カテゴリ管理・編集導線が描画される
- [ ] role=shooter: 編集者専用導線が**存在しない**（DOM 非描画）+ 撮影対象が先頭
- [ ] 空状態: no_organization / no_project(can_create_project true/false) / manual 0 件の CTA と案内文
- [ ] is_low_balance=true で購入導線、has_active_subscription=false で billing callout
- [ ] **`disabled` 属性を持つ要素が 1 つも存在しない**
- [ ] Projects/Show の STATUS_TONES import 化の回帰（既存テストがあれば green 維持）

### リスク

- ds-purity / atomic-import-graph / svg-inline-allowlist の各 Architecture テストは既存 atom/molecule + Lucide のみ使用のため抵触しない見込み（CI で確認）
- progress bar は新規の視覚要素だが atom 化はしない（v1 はダッシュボード内のインライン実装。再利用が 2 箇所目に現れた時点で atom 昇格）

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | standalone（単一 worktree・単一 TODO） |
| 判断根拠 | 施策 1→2→3→4 が直列依存（resolver → service → controller → UI）で、途中状態では dashboard が中途半端になる。コミットは「①resolver+テスト ②集計+controller+route+Feature テスト ③フロント+Vitest」の 3 分割でレビュー単位を小さくする（概念レビュー Round 1 対応） |
| 競合リスク | `routes/web.php` L153-155 と `Dashboard.svelte` を書き換えるが、両者を触る並行タスクは現在なし。`types/manual.ts` への STATUS_TONES 追加は追記のみで衝突低リスク |

## 検証コマンド（全 green でコミット）

`composer test` / `composer phpstan` / `vendor/bin/pint --test` / `pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm build`
