# 詳細設計: admin-console（管理メニュー: ユーザー管理 + カテゴリ管理画面）

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

### 禁止事項（AGENTS.md）

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)

### セキュリティ不変条件（AGENTS.md。本設計に直接関わるもの）

- tenant キー不信（`ProhibitsProtectedKeys` + `MassAssignmentSafetyTest`）
- 子は親に属する: nested route 不整合は認可より前に 404（`NestedRouteIdorDefenseTest`）
- cross-org 不可（relation / org-scoped 解決経由のみ）
- 権限判定は常に `laratrust_team_id` 明示（strict_check=true）
- PII(email/name) は CipherSweet。検索は `whereBlind()`

### コーディングルール

- **PHPStan level 10** 必須（`composer phpstan`）
- **Pest** テストフレームワーク（`composer test`）
- **RefreshDatabase** + `--parallel` 並列実行（`tests/Pest.php` でグローバル適用、個別 `DatabaseTransactions` 使用禁止）
- **テストデータは必ず Factory で生成**（`Model::create()` 手組み禁止）
- 新モデルを追加する設計では対応する Factory の作成も施策に含める（本設計は新モデルなし。既存 `OrganizationInvitationFactory` に state 追加）
- **DTO + JsonResource** パターン / Inertia render（`response()->json()` 直書き禁止）
- **アーリーリターン** 推奨、`declare(strict_types=1)` + 日本語コメント、Controller 薄く（Service 委譲）、transaction は Service 内、保護キーは forceFill / relation 明示代入
- **コードフォーマット**: `composer fix`（Pint）/ `pnpm lint:fix`
- PHP 8.4 + Laravel 12 + Svelte 5（runes）+ Inertia.js + TypeScript
- フロントは DS token のみ（DESIGN.md / ds-purity）、atoms→molecules→organisms→features→templates→pages の単方向 import、アイコンは `@lucide/svelte` のみ

## 概念設計リファレンス

[devnotes/20260711-1009-admin-console/conceptual-design.md](./conceptual-design.md)（Codex 概念レビュー Round 4 で APPROVED）

**概念設計からの詳細化に伴う変更 1 点（URL）**: 概念設計の `GET /admin/users` は
**`GET /manage/users` に変更**する。理由: Filament 管理パネル（横断運用者向け）が
`->path('admin')` で `/admin/*` を占有しており（`app/Providers/Filament/AdminPanelProvider.php`
L40）、Filament の `UserResource` が既に `/admin/users` を持つため**直接衝突**する。
アプリ側 Inertia ページ名は概念設計どおり `Admin/Users` / `Admin/Categories` を維持
（Inertia コンポーネント名は URL と独立で衝突しない）。

## 施策一覧

| # | 施策名 | スライス | 変更ファイル | 優先度 |
|---|--------|---------|------------|--------|
| 1 | AdminConsoleRole / MemberRoleState enum 新設 | A | `app/Enums/AdminConsoleRole.php`(新), `app/Enums/MemberRoleState.php`(新) | 高 |
| 2 | DefaultProjectResolver 新設 + capture.home 一本化 | A | `app/Services/Project/DefaultProjectResolver.php`(新), `app/Http/Controllers/Capture/CaptureManualController.php` | 高 |
| 3 | organization_invitations.project_role 追加 | A | migration(新), `app/Models/OrganizationInvitation.php`, `database/factories/OrganizationInvitationFactory.php` | 高 |
| 4 | MembershipService のロール遷移コマンド化 + pivot 掃除 | A | `app/Services/Organization/OrganizationMembershipService.php` | 高 |
| 5 | 招待/ロール変更エンドポイントの 3 値コマンド化 | A | `OrganizationInvitationController.php`, `OrganizationMemberController.php`, `app/Http/Requests/Organizations/{StoreOrganizationInvitationRequest,UpdateOrganizationMemberRoleRequest}.php`(新) | 高 |
| 6 | ユーザー管理画面バックエンド | B | `app/Http/Controllers/Admin/UserManagementController.php`(新), `app/DataTransferObjects/Admin/{MemberRowData,InvitationRowData}.php`(新), `routes/web.php` | 高 |
| 7 | ユーザー管理画面フロント + Settings スリム化 | B | `resources/js/pages/Admin/Users.svelte`(新), `resources/js/components/features/admin/AdminMenuNav.svelte`(新), `resources/js/types/admin.ts`(新), `resources/js/pages/Organizations/Settings.svelte`, `app/Http/Controllers/Organizations/OrganizationController.php` | 高 |
| 8 | カテゴリ管理画面バックエンド | C | `app/Http/Controllers/Projects/CategoryController.php`, `app/Policies/CategoryPolicy.php`, `routes/web.php` | 中 |
| 9 | カテゴリ管理画面フロント + Projects/Show 移設・導線 | C | `resources/js/pages/Admin/Categories.svelte`(新), `resources/js/pages/Projects/Show.svelte`, `app/Http/Controllers/Projects/ProjectController.php` | 中 |
| 10 | ドキュメント更新 | - | `docs/template-divergence.md`, `docs/architecture.md` | 中 |

**実装順序**: スライス A（施策 1〜5）+ スライス B（施策 6〜7）は**不可分（同一 PR）**。
`members.update` / `invitations.store` の契約書き換え（A）と唯一の caller UI（B）を分離すると
旧 Settings UI が旧契約値を送信する並走/破壊状態になるため。スライス C（施策 8〜9）は独立実装可。
施策 10 は A+B / C のそれぞれに付随して更新する。

---

## 施策 1: AdminConsoleRole / MemberRoleState enum 新設

### 変更箇所
- `app/Enums/AdminConsoleRole.php`（新規）
- `app/Enums/MemberRoleState.php`（新規）

### 波及変更
- TypeScript 型定義: `resources/js/types/admin.ts` の `ConsoleRole` / `MemberRoleState` union（施策 7）
- API Resource/DTO: なし（施策 6 の DTO が値を参照）
- テストファイル: `tests/Feature/Organization/ConsoleRoleTransitionTest.php`（新規、施策 4 で作成）

### 変更後コード

```php
<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * 管理メニュー (ユーザー管理) のロール遷移コマンド (doc/02 §2.5 + doc/10 §10.5 の合成)。
 * 保存概念ではない: org ロール + Default Project pivot という既存プリミティブへの
 * 「正規状態への遷移」を表す。表示状態は MemberRoleState (導出) が担う。
 */
enum AdminConsoleRole: string
{
    case Admin = 'admin';     // 管理者 = org Admin (pivot は掃除)
    case Editor = 'editor';   // 編集者 = org Member + project_admin
    case Shooter = 'shooter'; // 撮影者 = org Member + project_member

    public function label(): string
    {
        return match ($this) {
            self::Admin => '管理者',
            self::Editor => '編集者',
            self::Shooter => '撮影者',
        };
    }

    /** コマンド適用後の org ロール */
    public function organizationRole(): OrganizationRole
    {
        return $this === self::Admin ? OrganizationRole::Admin : OrganizationRole::Member;
    }

    /** コマンド適用後の Default Project pivot ロール (Admin コマンドは pivot なし = null) */
    public function projectRole(): ?ProjectRole
    {
        return match ($this) {
            self::Admin => null,
            self::Editor => ProjectRole::Admin,
            self::Shooter => ProjectRole::Member,
        };
    }
}
```

```php
<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * ユーザー管理画面の表示状態 (毎リクエスト導出。DB に保存しない = backfill 不要)。
 * org ロール × Default Project pivot の全組合せを漏れなく 5 値に分類する
 * (概念設計 D2 の canonical mapping)。
 */
enum MemberRoleState: string
{
    case Owner = 'owner';           // 管理者 (オーナー)。変更不可 (transferOwnership のみ)
    case Admin = 'admin';           // 管理者。stale pivot があっても org ロール優先で無視
    case Editor = 'editor';         // 編集者 (org Member + project_admin)
    case Shooter = 'shooter';       // 撮影者 (org Member + project_member)
    case Unassigned = 'unassigned'; // 未割当 (org Member + pivot なし)。割当を促す表示

    /**
     * org ロール null (organization_user attach 済みだが Laratrust ロール未付与の異常行) も
     * Unassigned へ丸める: 異常行を非表示にせず「未割当」として可視化し、管理画面から
     * ロール割当コマンドで修復できるようにする (applyConsoleRole の修復経路と対)。
     * null 判定は project pivot 判定より**必ず先**に評価する (org ロールなし + stale pivot が
     * Editor/Shooter と誤表示され修復契約と食い違うのを防ぐ)。
     */
    public static function derive(?OrganizationRole $orgRole, ?ProjectRole $projectRole): self
    {
        return match (true) {
            $orgRole === null => self::Unassigned,
            $orgRole === OrganizationRole::Owner => self::Owner,
            $orgRole === OrganizationRole::Admin => self::Admin,
            $projectRole === ProjectRole::Admin => self::Editor,
            $projectRole === ProjectRole::Member => self::Shooter,
            default => self::Unassigned,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Owner => '管理者（オーナー）',
            self::Admin => '管理者',
            self::Editor => '編集者',
            self::Shooter => '撮影者',
            self::Unassigned => '未割当',
        };
    }
}
```

### PHPStan適合チェック
- [x] 戻り値の型が明示されている（enum メソッドすべて）
- [x] null安全（`projectRole(): ?ProjectRole` を明示）
- [x] DTOを返している（該当なし。純粋 enum）
- [x] Genericsの型パラメータ（該当なし）

### テスト計画
- [ ] `tests/Unit/Enums/MemberRoleStateTest.php`（新規）: `derive()` の全組合せ表
      （Owner×pivot 有無 / Admin×pivot 有無 / Member×admin / Member×member / Member×null /
      **null×pivot 有無 → Unassigned**）。stale pivot 無視は
      `it('admin は project pivot が残っていても管理者として表示する (stale pivot 無視)')` の
      ように意図をテスト名へ明示
- [ ] `AdminConsoleRole::organizationRole()/projectRole()` のマッピング検証（同上ファイル）

### リスク
- なし（純関数の追加のみ）

---

## 施策 2: DefaultProjectResolver 新設 + capture.home 一本化

### 変更箇所
- `app/Services/Project/DefaultProjectResolver.php`（新規）
- `app/Http/Controllers/Capture/CaptureManualController.php` `home()`（L38-46）

### 波及変更
- TypeScript 型定義: なし
- API Resource/DTO: なし
- テストファイル: `tests/Feature/Capture/` の home テスト（挙動同一のため既存 green を確認するのみ）、`tests/Feature/Projects/DefaultProjectResolverTest.php`（新規）

### 現行コード（CaptureManualController::home）

```php
public function home(Request $request): RedirectResponse
{
    $organization = $this->resolveCurrentOrganization($request);
    /** @var Project|null $project */
    $project = $organization->projects()->orderBy('projects.id')->first();
    abort_if($project === null, 404);

    return redirect()->route('capture.manuals.index', ['project' => $project]);
}
```

### 変更後コード

```php
<?php

declare(strict_types=1);

namespace App\Services\Project;

use App\Models\Organization;
use App\Models\Project;

/**
 * Default Project の解決規約の single source of truth (v1 = 単一 Default Project 前提)。
 * 「org の先頭 project (projects.id 昇順の最初)」を Default Project と定義する。
 * 複数 project 化の際はここだけを差し替える (呼び出し側は不変)。
 *
 * read / write の分離 (概念設計 D2):
 * - resolve(): 表示・redirect 用 (ロックなし)。capture.home / 管理メニュー導線 / 一覧表示
 * - resolveForUpdate(): pivot 書き込み用 (lockForUpdate)。呼び出し側トランザクション内で
 *   取得から pivot 更新完了まで Project 行ロックを保持し、解決直後の project 削除競合を
 *   排除する (CategoryService の「Project 行ロック = 直列化点」既存規約と同型)。
 */
class DefaultProjectResolver
{
    public function resolve(Organization $organization): ?Project
    {
        /** @var Project|null */
        return $organization->projects()->orderBy('projects.id')->first();
    }

    /** 必ず DB::transaction 内から呼ぶこと (ロール変更・招待受諾の pivot 書き込み専用) */
    public function resolveForUpdate(Organization $organization): ?Project
    {
        $id = $organization->projects()->orderBy('projects.id')->value('projects.id');
        if ($id === null) {
            return null;
        }

        /** @var Project|null */
        return Project::query()->whereKey($id)->lockForUpdate()->first();
    }
}
```

CaptureManualController::home は resolver 経由に書き換え（挙動不変）:

```php
public function home(Request $request, DefaultProjectResolver $defaultProjects): RedirectResponse
{
    $organization = $this->resolveCurrentOrganization($request);
    $project = $defaultProjects->resolve($organization);
    abort_if($project === null, 404);

    return redirect()->route('capture.manuals.index', ['project' => $project]);
}
```

> 注: `resolveForUpdate()` は「id を先に確定 → 行ロック付き再取得」の 2 段にする。
> `HasManyThrough` に直接 `lockForUpdate()` を掛けると JOIN 先 (custom_teams) までロック対象に
> なり、pgsql では `FOR UPDATE` と JOIN の組合せが複雑化するため、単一テーブルの主キー lock に
> 落とす。id 確定後に行が消えた場合は null が返り、呼び出し側の不在時契約 (error bag / 未割当)
> に倒れる。

### PHPStan適合チェック
- [x] 戻り値の型が明示（`?Project`）
- [x] null安全（不在 = null を契約化）
- [x] DTO（該当なし）
- [x] Generics（`@var Project|null` の明示 narrow）

### テスト計画
- [ ] `DefaultProjectResolverTest`: 0 project → null / 複数 project → 最小 id / 別 org の project を返さない（cross-org）
- [ ] `resolveForUpdate` が tx 内で取得した project の削除を（別接続から）ブロックすることの検証は
      並列 DB テストでは flaky になるため行わない。代わりに「id 確定後に行が消えた場合 null」を
      ユニットで担保し、競合時の最終挙動は施策 4 の Feature テスト（不在時エラー/未割当）で固定
- [ ] 既存 capture.home の Feature テストが green のまま（挙動不変の回帰確認）

### リスク
- capture.home のシグネチャ変更（DI 追加）のみ。挙動は不変

---

## 施策 3: organization_invitations.project_role 追加

### 変更箇所
- `database/migrations/2026_07_11_000000_add_project_role_to_organization_invitations_table.php`（新規）
- `app/Models/OrganizationInvitation.php`（casts 追加）
- `database/factories/OrganizationInvitationFactory.php`（state 追加）

### 波及変更
- TypeScript 型定義: なし（画面へは roleState 導出値のみ配布）
- API Resource/DTO: `InvitationRowData`（施策 6）が参照
- テストファイル: `tests/Feature/Organization/InvitationTest.php`（受諾時 attach の追加ケース）

### 変更後コード（migration）

```php
public function up(): void
{
    Schema::table('organization_invitations', function (Blueprint $table) {
        // 受諾時に Default Project へ付与する pivot ロール (ProjectRole 値)。
        // null = org 参加のみ (管理者招待 / 旧招待)。値はサーバが AdminConsoleRole から導出し、
        // クライアント payload からは受けない (forceFill 専用)
        $table->string('project_role')->nullable()->after('role');
    });
    // 許容値を DB 層でも固定 (手動更新・バッチ経由の不正値混入を構造的に拒否)
    DB::statement(
        "alter table organization_invitations add constraint organization_invitations_project_role_check "
        ."check (project_role is null or project_role in ('project_admin', 'project_member'))",
    );
}

public function down(): void
{
    DB::statement('alter table organization_invitations drop constraint if exists organization_invitations_project_role_check');
    Schema::table('organization_invitations', function (Blueprint $table) {
        $table->dropColumn('project_role');
    });
}
```

### 変更後コード（Model）

`$fillable` には**追加しない**（サーバ導出値。forceFill 専用）。casts に追加:

```php
protected function casts(): array
{
    return [
        'project_role' => ProjectRole::class, // nullable enum cast
        'expires_at' => 'datetime',
        'accepted_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];
}
```

Factory: `editorInvitation()` / `shooterInvitation()` state（`role` = organization_member +
`project_role` = 各値）を追加。

### PHPStan適合チェック
- [x] enum cast により `$invitation->project_role` は `ProjectRole|null` に型付く
      （`@property-read` docblock を Model に追記）
- [x] fillable 外（tenant キー不信の流儀と同じ forceFill 明示代入）

### テスト計画
- [ ] Factory state で `project_role` が enum として読めること（施策 4 のテストに内包）
- [ ] 旧行（project_role = null）の受諾が従来どおり動くこと（互換受諾テスト、施策 4）

### リスク
- 追記型カラム（nullable）のため既存データ・既存クエリへの影響なし

---

## 施策 4: OrganizationMembershipService のロール遷移コマンド化 + pivot 掃除

### 変更箇所
- `app/Services/Organization/OrganizationMembershipService.php`
  - `inviteMember()` シグネチャ変更（L40: `OrganizationRole $role` → `AdminConsoleRole $role`）
  - `joinOrganization()`（L163-170）: Default Project pivot attach 追加
  - `applyConsoleRole()`（新規メソッド）: 遷移コマンド適用
  - `removeMember()`（L206-229）: project pivot 掃除追加
  - コンストラクタに `DefaultProjectResolver` を DI

### 波及変更
- TypeScript 型定義: なし
- API Resource/DTO: なし
- テストファイル:
  - `tests/Feature/Organization/InvitationTest.php`（**更新**: `inviteAndCaptureToken` ヘルパの
    引数型 / role 値。追加: editor 招待受諾で pivot attach・受諾時 project 不在で未割当・
    旧招待互換受諾・editor/shooter 招待は project 不在で error bag）
  - `tests/Feature/Organization/MembershipTest.php`（**更新**: role payload 値。
    追加は ConsoleRoleTransitionTest へ）
  - `tests/Feature/Organization/ConsoleRoleTransitionTest.php`（**新規**）
  - `CreateNewUser`（register 経路の受諾）は `joinOrganization` 共通コアのため変更不要

### 現行コード（要点）

```php
public function inviteMember(Organization $organization, User $invitedBy, string $email, OrganizationRole $role): OrganizationInvitation
{
    // 中立メッセージの重複検査 → invitation 生成 (forceFill role/token_hash/expires_at) → メール
}

private function joinOrganization(OrganizationInvitation $invitation, Organization $organization, User $user, OrganizationRole $role): void
{
    DB::transaction(function () use ($organization, $user, $role, $invitation): void {
        $organization->users()->attach($user);
        $user->addRole($role->value, $organization->laratrust_team_id);
        $invitation->forceFill(['accepted_at' => now()])->save();
    });
}

public function removeMember(Organization $organization, User $target): void
{
    // 非メンバー/Owner ガード → tx { detach + removeRole + current_organization_id 掃除 }
}
```

### 変更後コード（要点）

```php
public function __construct(
    private readonly SecurityEventRecorder $recorder,
    private readonly DefaultProjectResolver $defaultProjects,
) {}

/**
 * メンバー招待 (3 値ロールコマンド)。編集者/撮影者は Default Project 存在が必須
 * (不在は ValidationException = Inertia error bag)。
 */
public function inviteMember(Organization $organization, User $invitedBy, string $email, AdminConsoleRole $role): OrganizationInvitation
{
    if ($this->emailBelongsToMember($organization, $email) || $this->hasPendingInvitation($organization, $email)) {
        throw ValidationException::withMessages([
            'email' => ['このメールアドレスには招待を送信できません。'],
        ]);
    }

    // 編集者/撮影者は Default Project が前提 (送信時点の静的確認。受諾時の最終確認は
    // joinOrganization が resolveForUpdate で行い、不在なら未割当に落とす)
    if ($role->projectRole() !== null && $this->defaultProjects->resolve($organization) === null) {
        throw ValidationException::withMessages([
            'role' => ['編集者・撮影者を招待するには、先にプロジェクトを作成してください。'],
        ]);
    }

    $plainToken = OrganizationInvitation::generateToken();

    $invitation = new OrganizationInvitation(['email' => $email]);
    $invitation->organization()->associate($organization);
    $invitation->invitedBy()->associate($invitedBy);
    // role / project_role / token_hash / expires_at は明示代入 (mass-assignment させない)
    $invitation->forceFill([
        'role' => $role->organizationRole()->value,
        'project_role' => $role->projectRole()?->value,
        'token_hash' => OrganizationInvitation::hashToken($plainToken),
        'expires_at' => now()->addDays(self::EXPIRES_DAYS),
    ]);
    $invitation->save();
    // ... (メール送信は現行のまま)
}

/**
 * 招待受諾の確定処理。並行受諾への防御は 2 層:
 * 1. **招待行の lockForUpdate**: 同一招待 (同一トークン二重送信) の並行受諾を直列化し、
 *    accepted_at / revoked_at の判定をロック下で再実行する (TOCTOU 封じ。呼び出し元の
 *    事前検証は第 1 層として維持)
 * 2. **organization_user の原子的 INSERT (insertOrIgnore)**: 別招待経由の並行 join
 *    (同一 user × 同一 org) でも unique 違反にならず、勝った側だけが role/pivot を付与する
 *    (affected rows = 0 なら join 済みと判断してスキップ)。値はすべてサーバ側モデル由来
 *    (organization/user は relation 解決済み) で、payload 不信の保護キー規約に反しない。
 *    organization_user は (organization_id, user_id) UNIQUE + timestamps のみの pivot
 *    (2026_06_11_074000_create_organizations_tables.php)。
 * project_role 付き招待は Default Project (resolveForUpdate = 行ロック) へ pivot attach。
 * 受諾時に project が消えていた場合は org 参加のみ = 「未割当」表示状態に落ちる (可視 degrade)。
 */
private function joinOrganization(OrganizationInvitation $invitation, Organization $organization, User $user, OrganizationRole $role): void
{
    DB::transaction(function () use ($organization, $user, $role, $invitation): void {
        // 1. 招待行ロック + 受諾可能状態のロック下再検証 (並行受諾に敗れた側は冪等 no-op)
        /** @var OrganizationInvitation $locked */
        $locked = OrganizationInvitation::query()->whereKey($invitation->id)->lockForUpdate()->firstOrFail();
        if ($locked->isAccepted() || $locked->isRevoked() || $locked->isExpired()) {
            return; // 期限境界の TOCTOU も含めロック下で完全再検証 (敗者は冪等 no-op)
        }

        // 2. org 参加の原子的 INSERT。0 行 = 別経路で join 済み (role/pivot は変更しない。
        //    非正規状態が残る場合も「未割当」として可視化され管理画面から修復できる)
        $joined = DB::table('organization_user')->insertOrIgnore([
            'organization_id' => $organization->id,
            'user_id' => $user->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($joined === 1) {
            $user->addRole($role->value, $organization->laratrust_team_id);

            $projectRole = $locked->project_role;
            if ($projectRole instanceof ProjectRole) {
                $project = $this->defaultProjects->resolveForUpdate($organization);
                $project?->members()->syncWithoutDetaching([
                    $user->getKey() => ['role' => $projectRole->value],
                ]);
            }
        }

        $locked->forceFill(['accepted_at' => now()])->save();
    });
}

/**
 * ロール遷移コマンドの適用 (概念設計 D2(b))。1 トランザクションで最終状態を保証する:
 * - Admin:   org Admin + org 配下 project pivot detach (stale 掃除)
 * - Editor:  org Member + Default Project pivot role=project_admin (sync)
 * - Shooter: org Member + Default Project pivot role=project_member (sync)
 * changeRole 再利用により非メンバー拒否・最終 Owner 保護を継承する。
 */
public function applyConsoleRole(Organization $organization, User $target, AdminConsoleRole $role): void
{
    DB::transaction(function () use ($organization, $target, $role): void {
        $projectRole = $role->projectRole();
        $project = null;
        if ($projectRole !== null) {
            // 書き込み用解決 (行ロック保持。取得〜pivot 更新まで削除競合を排除)
            $project = $this->defaultProjects->resolveForUpdate($organization);
            if ($project === null) {
                throw ValidationException::withMessages([
                    'role' => ['編集者・撮影者を割り当てるには、先にプロジェクトを作成してください。'],
                ]);
            }
        }

        // org ロール正規化。attach 済みかつ Laratrust ロール未付与の異常行 (表示状態は
        // 「未割当」= MemberRoleState::derive(null, ...)) は changeRole が「非メンバー」として
        // 拒否するため、修復経路として addRole で直接付与する (管理画面から正規化できる契約)
        if ($target->organizationRole($organization) === null) {
            // 非 attach は changeRole と同じ契約で拒否 (第 1 層は Controller の URL 整合 guard = 404)
            if (! $organization->users()->whereKey($target->getKey())->exists()) {
                throw ValidationException::withMessages(['role' => ['このユーザーは組織のメンバーではありません。']]);
            }
            $target->addRole($role->organizationRole()->value, $organization->laratrust_team_id);
        } else {
            // 同値なら changeRole 内で早期 return = 冪等。最終 Owner 保護も継承
            $this->changeRole($organization, $target, $role->organizationRole());
        }

        if ($projectRole !== null && $project !== null) {
            $project->members()->syncWithoutDetaching([
                $target->getKey() => ['role' => $projectRole->value],
            ]);

            return;
        }

        // Admin コマンド: stale pivot 掃除 (org 配下 project に限定 = cross-org 不変条件)
        $this->detachProjectMemberships($organization, $target);
    });
}

public function removeMember(Organization $organization, User $target): void
{
    // ... (非メンバー/Owner ガードは現行のまま)
    DB::transaction(function () use ($organization, $target, $role): void {
        $organization->users()->detach($target->getKey());
        if ($role !== null) {
            $target->removeRole($role->value, $organization->laratrust_team_id);
        }
        // project pivot 掃除 (org 配下 project に限定。別 org の pivot は維持)
        $this->detachProjectMemberships($organization, $target);
        if ($target->current_organization_id === $organization->id) {
            $target->forceFill(['current_organization_id' => null])->save();
        }
    });
}

/**
 * org 配下 project の pivot を一括 detach する。対象 project id は必ず
 * $organization->projects() (org-scoped relation) から解決する (cross-org 不変条件)。
 * project_members は pivot テーブルで対応する Eloquent モデル・モデルイベントを持たないため、
 * 意図的に素の delete を使う (belongsToMany::detach も pivot イベントは発火しない = 等価)。
 * 挙動契約は ConsoleRoleTransitionTest が固定する。
 */
private function detachProjectMemberships(Organization $organization, User $target): void
{
    /** @var list<int> $projectIds */
    $projectIds = $organization->projects()->pluck('projects.id')->all();
    if ($projectIds === []) {
        return;
    }

    DB::table('project_members')
        ->whereIn('project_id', $projectIds)
        ->where('user_id', $target->getKey())
        ->delete();
}
```

> `changeRole` を tx 内から呼ぶ: Laravel の `DB::transaction` はネストを savepoint として
> 扱うため既存メソッドを変更せず再利用できる。`changeRole` の最終 Owner 保護・非メンバー
> 拒否 (`ValidationException`) はそのまま外へ伝播し、外側 tx ごと rollback される。

### PHPStan適合チェック
- [x] 戻り値の型明示（`applyConsoleRole(): void` 等）
- [x] null安全（`$invitation->project_role` は enum cast で `ProjectRole|null`、
      `instanceof` narrow / `$project` は null チェック後のみ使用）
- [x] DTO（該当なし。Service は既存流儀の void/Model 返し）
- [x] Generics（`pluck()->all()` は `@var list<int>` で narrow）

### テスト計画（`ConsoleRoleTransitionTest.php` 新規 + `InvitationTest.php` 更新）
- [ ] editor → shooter: pivot role が project_member に更新・org ロールは Member のまま
- [ ] shooter → admin: org Admin へ昇格 + pivot detach（stale 掃除）
- [ ] admin → editor: org Member へ降格 + pivot project_admin 付与
- [ ] 未割当 (org Member, pivot なし) → editor: pivot 付与
- [ ] editor/shooter コマンドで Default Project 不在 → `assertSessionHasErrors('role')`
      （施策 5 の endpoint 経由）+ Service 単体で `ValidationException`
- [ ] 最終 Owner への admin コマンド → changeRole の保護継承（ValidationException）
- [ ] 非メンバーへの適用 → ValidationException（changeRole 継承）
- [ ] removeMember: org 配下 project の pivot が消える + **別 org の pivot は維持される**
      （同一 user を 2 org × 各 project に所属させた fixture）
- [ ] 招待受諾（ログイン後経路 + register 経路）: project_role 付き招待で pivot attach
- [ ] 受諾時 project 不在（招待後に project 削除）: org 参加のみ = 未割当（例外にならない）
- [ ] 旧招待互換: project_role = null の既存行の受諾が従来どおり（org 参加のみ）
- [ ] editor/shooter 招待送信時に project 不在 → error bag
- [ ] 受諾の冪等性/直列化: (a) 受諾済み招待で joinOrganization に到達しても no-op
      （招待行ロック下の再検証契約）、(b) 既 attach 状態（organization_user 行あり）での受諾が
      unique 違反にならず role/pivot を変更しない（insertOrIgnore の affected-rows 分岐契約）。
      真の並行実行は並列 DB テストでは flaky のため、ロック下再検証 + 原子的 INSERT の
      逐次検証で契約を固定する（insertOrIgnore は DB が原子性を保証）
- [ ] 修復経路: org attach 済み + Laratrust ロール未付与の異常行へ shooter コマンド →
      org Member ロール付与 + pivot 付与（未割当の可視化 → 正規化の契約）
- [ ] **Architecture**: `tests/Architecture/ProjectMemberPivotWritePathTest.php`（新規）—
      `project_members` への書き込み（`DB::table('project_members')` /
      `members()->attach|detach|sync*`）が許可 inventory（`OrganizationMembershipService` /
      `ProjectMemberController`）の外の app コードに現れたら fail（deny-by-default 走査。
      ScenarioWritePathInventoryTest と同型。概念レビュー Round 4 Suggestion の昇格）

### リスク
- `inviteMember` のシグネチャ変更はコンパイル時に全 caller を洗い出せる（PHPStan）。
  caller は `OrganizationInvitationController`（施策 5 で同時更新）と
  `InvitationTest` のヘルパのみ
- pivot 書き込み経路は本 Service の 2 メソッド（applyConsoleRole / joinOrganization）に閉じる。
  経路が増えた場合は `ScenarioWritePathInventoryTest` と同型の inventory テスト昇格を検討
  （Codex 概念レビュー Round 4 Suggestion。現時点は Feature テストで挙動固定）

---

## 施策 5: 招待/ロール変更エンドポイントの 3 値コマンド化

### 変更箇所
- `app/Http/Requests/Organizations/StoreOrganizationInvitationRequest.php`（新規）
- `app/Http/Requests/Organizations/UpdateOrganizationMemberRoleRequest.php`（新規）
- `app/Http/Controllers/Organizations/OrganizationInvitationController.php` `store()`（L26-49）
- `app/Http/Controllers/Organizations/OrganizationMemberController.php` `update()`（L32-51）

### 波及変更
- TypeScript 型定義: `types/admin.ts` の `ConsoleRole`（施策 7。旧 Settings は同 PR で送信 UI ごと撤去）
- API Resource/DTO: なし
- テストファイル: `MembershipTest.php`（role payload 値を `admin|editor|shooter` へ更新。
  Owner 指定拒否テストは「enum 外の値 → errors('role')」として維持）、`InvitationTest.php`（同様）、
  `UrlIntegrityGuardTest.php` / `OrganizationBoundaryNotFoundTest.php`（payload role 値のみ追随）

### 現行コード（OrganizationMemberController::update 抜粋）

```php
$request->validate([
    'role' => ['required', 'string', Rule::in([
        OrganizationRole::Admin->value,
        OrganizationRole::Member->value,
    ])],
]);
$role = $request->input('role');
Assert::string($role);

$membership->changeRole($organization, $user, OrganizationRole::from($role));
```

### 変更後コード

FormRequest（2 ファイル共通の形。静的入力検証に限定 = TOCTOU になる DB 依存検証を置かない）:

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Organizations;

use App\Enums\AdminConsoleRole;
use App\Http\Requests\Concerns\ProhibitsProtectedKeys;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Webmozart\Assert\Assert;

/**
 * メンバーロール変更 (3 値遷移コマンド)。
 * 認可は Controller の Gate::authorize('manageMembers') が唯一の責務
 * (FormRequest では判定しない。authorize(): true は「入力検証のみ担当」の宣言)。
 * Default Project の存在確認は Service トランザクション内 (TOCTOU 封じ) のため、
 * ここでは enum 妥当性のみを検証する。
 */
class UpdateOrganizationMemberRoleRequest extends FormRequest
{
    use ProhibitsProtectedKeys;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return array_merge([
            'role' => ['required', 'string', Rule::enum(AdminConsoleRole::class)],
        ], $this->protectedKeyMissingRules());
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            // 旧契約値 (organization_admin 等) を送るデプロイ跨ぎタブの回復導線を明示する
            'role' => 'ロールの指定が不正です。画面を再読み込みしてやり直してください。',
        ];
    }

    /** 型付きアクセサ (validated 後の値を enum へ narrow して Service に渡す) */
    public function role(): AdminConsoleRole
    {
        $role = $this->enum('role', AdminConsoleRole::class);
        Assert::isInstanceOf($role, AdminConsoleRole::class);

        return $role;
    }
}
```

`StoreOrganizationInvitationRequest` は上記 + `'email' => ['required', 'string', 'email', 'max:255']`
と `email(): string` アクセサ。

Controller:

```php
// OrganizationMemberController
public function update(UpdateOrganizationMemberRoleRequest $request, Organization $organization, User $user, OrganizationMembershipService $membership): RedirectResponse
{
    // URL 整合 guard: 認可より前に 404 (cross-tenant の存在を漏らさない) — 現行のまま
    $this->resolveOrganizationMember($organization, $user);
    Gate::authorize('manageMembers', $organization);

    $membership->applyConsoleRole($organization, $user, $request->role());

    return back()->with('success', 'ロールを変更しました');
}

// OrganizationInvitationController
public function store(StoreOrganizationInvitationRequest $request, Organization $organization, OrganizationMembershipService $membership): RedirectResponse
{
    Gate::authorize('manageMembers', $organization);

    $user = $request->user();
    Assert::isInstanceOf($user, User::class);

    $membership->inviteMember($organization, $user, $request->email(), $request->role());

    return back()->with('success', '招待メールを送信しました');
}
```

- Owner 指定は enum 外（`AdminConsoleRole` に owner がない）ため構造的に不可能
  （現行の「Owner 昇格は transferOwnership のみ」の不変条件を型で表現）。
- 応答は現行どおり `back()->with(...)`（禁止事項 7 遵守）。
- `{user}` の URL 整合 guard（認可前 404）・IDOR inventory 登録（UrlIntegrityGuard）は現状維持。

### PHPStan適合チェック
- [x] `role(): AdminConsoleRole` / `email(): string` の型付きアクセサ（`validated()` の mixed を
      Controller に漏らさない）
- [x] `rules()` の返り値型注釈（既存 FormRequest と同形）
- [x] ProhibitsProtectedKeys（tenant キー不信の入口防御）

### テスト計画
- [ ] `MembershipTest` 更新: role=admin/editor/shooter の各正常系、enum 外値
      （`organization_owner` / 旧値 `organization_admin` を含む）→ `assertSessionHasErrors('role')`
- [ ] 非管理者（org Member）からの update/store → 403、非メンバー {user} → 404（既存パターン維持）
- [ ] 保護キー（`organization_id` 等）を payload に混ぜたら errors（ProhibitsProtectedKeys）
- [ ] `InvitationTest` 更新: email 形式・重複中立メッセージ・招待一覧表示（既存の維持 + role 値追随）

### リスク
- 旧 role 値（`organization_admin` / `organization_member`）を送る古いタブの UI は検証エラーに
  なる（破壊的変更）。A+B 同一 PR 提供のため UI は常に新値を送る。デプロイ跨ぎの開きっぱなし
  タブのみ影響し、再読み込みで回復（error bag 表示のため無言失敗にはならない）

---

## 施策 6: ユーザー管理画面バックエンド（GET /manage/users）

### 変更箇所
- `app/Http/Controllers/Admin/UserManagementController.php`（新規）
- `app/DataTransferObjects/Admin/MemberRowData.php` / `InvitationRowData.php`（新規）
- `routes/web.php`（auth+verified group 内・課金ゲート外に GET /manage/users 追加）

### 波及変更
- TypeScript 型定義: `types/admin.ts`（施策 7）
- API Resource/DTO: 本施策で新設する 2 DTO
- テストファイル: `tests/Feature/Admin/UserManagementPageTest.php`（新規）

### 変更後コード

DTO（TS 側 `types/admin.ts` の `MemberRow` と対で保守）:

```php
<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Admin;

use App\Enums\MemberRoleState;
use App\Enums\OrganizationRole;
use App\Enums\ProjectRole;
use App\Models\User;

/**
 * ユーザー管理画面 (Admin/Users) のメンバー 1 行分。
 * 表示状態 (roleState) は org ロール × Default Project pivot から毎回導出する (概念設計 D2(a))。
 * email は CipherSweet 復号値。本画面は manageMembers 権限者しか到達できない (403) ため
 * 行レベルの可視性分岐は持たない (PII 可視性は画面到達境界で担保)。
 */
final readonly class MemberRowData
{
    public function __construct(
        public int $id,
        public string $name,
        public string $email,
        public string $roleState,      // MemberRoleState value
        public string $roleLabel,
        public string $twoFactorStatus, // disabled|pending|enabled
        public bool $isSelf,
    ) {}

    public static function fromUser(User $user, ?OrganizationRole $orgRole, ?ProjectRole $projectRole, int $currentUserId): self
    {
        $state = MemberRoleState::derive($orgRole, $projectRole);

        return new self(
            id: $user->id,
            name: $user->name,
            email: $user->email,
            roleState: $state->value,
            roleLabel: $state->label(),
            twoFactorStatus: $user->twoFactorStatus()->value,
            isSelf: $user->id === $currentUserId,
        );
    }
}
```

`InvitationRowData`: `id / email / roleState / roleLabel / expiresAt`（`roleState` は
`MemberRoleState::derive(OrganizationRole::from($invitation->role), $invitation->project_role)` で導出）。

Controller:

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\DataTransferObjects\Admin\InvitationRowData;
use App\DataTransferObjects\Admin\MemberRowData;
use App\Enums\OrganizationRole;
use App\Enums\ProjectRole;
use App\Http\Concerns\ResolvesCurrentOrganization;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Project\DefaultProjectResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Webmozart\Assert\Assert;

/**
 * 管理メニュー > ユーザー管理 (doc/04 §4.2。GET のみ)。
 * 書き込みは既存 organizations.* endpoint (招待 / ロール変更 / 削除 / 2FA リセット) を使う。
 * URL は /manage/* (Filament panel が /admin/* を占有しているため。詳細設計 §リファレンス)。
 * current org スコープ解決のみで org URL param を持たない = cross-org 越境不能。
 */
class UserManagementController extends Controller
{
    use ResolvesCurrentOrganization;

    public function index(Request $request, DefaultProjectResolver $defaultProjects): Response
    {
        $organization = $this->resolveCurrentOrganization($request);
        Gate::authorize('manageMembers', $organization); // 撮影者・一般メンバーは 403

        $user = $request->user();
        Assert::isInstanceOf($user, User::class);

        $project = $defaultProjects->resolve($organization);

        // Default Project の pivot ロールを 1 クエリで引く (user_id => ProjectRole)
        /** @var array<int, ProjectRole> $pivotRoles */
        $pivotRoles = [];
        if ($project !== null) {
            foreach ($project->members()->get() as $member) {
                $role = $project->memberRole($member);
                if ($role !== null) {
                    $pivotRoles[$member->id] = $role;
                }
            }
        }

        $members = [];
        foreach ($organization->users()->get() as $member) {
            // organizationRole null (attach 済みだが Laratrust ロール未付与の異常行) も
            // 非表示にせず「未割当」として可視化する (derive が null を Unassigned へ丸める。
            // 管理者はロール割当コマンドでこの行を修復できる = applyConsoleRole の修復経路)
            $members[] = MemberRowData::fromUser(
                $member,
                $member->organizationRole($organization),
                $pivotRoles[$member->id] ?? null,
                $user->id,
            );
        }

        $invitations = $organization->invitations()->active()->get()
            ->map(fn ($invitation): InvitationRowData => InvitationRowData::fromInvitation($invitation))
            ->values()
            ->all();

        return Inertia::render('Admin/Users', [
            'organizationSlug' => $organization->slug,
            'members' => $members,        // list<MemberRowData>
            'invitations' => $invitations, // list<InvitationRowData>
            'hasDefaultProject' => $project !== null,
            // 管理メニュー nav: カテゴリ管理リンク (can 連動 + project 不在は非表示)。
            // URL は route helper で生成 (route 名変更耐性)
            'categoriesUrl' => $project !== null && $user->can('update', $project)
                ? route('projects.categories.index', $project)
                : null,
        ]);
    }
}
```

routes/web.php（auth+verified group 内、課金ゲート group の**外** = organizations.* と同格）:

```php
/*
| 管理メニュー (doc/04 §4.2 管理者専用)。ユーザー管理は org メンバー管理の専用画面
| (書き込みは既存 organizations.* endpoint)。/admin/* は Filament panel が占有するため /manage/*。
| org 管理系として課金ゲート外 (未契約でもメンバー整理可能 = organizations.members.* と整合)。
*/
Route::get('/manage/users', [UserManagementController::class, 'index'])
    ->name('manage.users.index');
```

### PHPStan適合チェック
- [x] props は readonly DTO + docblock 型（mixed を出さない）
- [x] `organizationRole()` null の異常行を早期 continue（null 安全）
- [x] `Assert::isInstanceOf` による user narrow（既存流儀）
- [x] `active()` scope 利用（現行 settings() の生 where 条件よりも既存 scope に寄せる）

### テスト計画（`UserManagementPageTest.php` 新規 + Architecture）
- [ ] org Owner / Admin → 200 + `Admin/Users` component + members/invitations shape
- [ ] org Member（編集者 = project_admin でも org は Member）→ **403**
- [ ] 未ログイン → login redirect
- [ ] roleState 導出: owner/admin/editor/shooter/unassigned の 5 状態が rows に正しく出る
      （Laratrust ロール未付与の異常行が unassigned で**表示される**ことを含む）
- [ ] `categoriesUrl`: project 不在 → null / org admin → URL あり
- [ ] 招待一覧は active のみ（失効・受諾済・取消済は出ない）
- [ ] PII: 非管理者は画面自体に到達不能（403）= email 露出経路なし（可視性契約）
- [ ] **Architecture**: `tests/Architecture/ManageRouteAuthGuardTest.php`（新規）—
      `/manage/` prefix の全 named route が `auth` + `verified` middleware を持つことを
      deny-by-default で固定（将来 manage 配下へ route を足したときの guard 漏れ防止）

### リスク
- 読み取り専用の新設画面（書き込みは既存 endpoint）。
- PII 閲覧の監査ログは導入しない（判断根拠: SecurityEventRecorder は状態変更イベント用で、
  画面閲覧監査の基盤はテンプレに存在しない。閲覧可能者は manageMembers 権限者のみで、
  既存 settings のメンバー email 表示より露出面はむしろ縮小。閲覧監査は org 全体要件として
  別途設計する。AGENTS.md 思考原則 2）

---

## 施策 7: ユーザー管理画面フロント + Settings スリム化

### 変更箇所
- `resources/js/pages/Admin/Users.svelte`（新規）
- `resources/js/components/features/admin/AdminMenuNav.svelte`（新規）
- `resources/js/types/admin.ts`（新規）
- `resources/js/pages/Organizations/Settings.svelte`（メンバー管理 UI 撤去）
- `app/Http/Controllers/Organizations/OrganizationController.php` `settings()`（props スリム化）

### 波及変更
- TypeScript 型定義: `types/admin.ts` 新設（`MemberRow` / `InvitationRow` / `ConsoleRole` /
  `MemberRoleState`）
- API Resource/DTO: 施策 6 の DTO と対
- テストファイル: `tests/js/pages/AdminUsers.test.ts`（新規）、
  `tests/js/pages/OrganizationsSettings.test.ts`（メンバー UI 不在の回帰へ更新）、
  `tests/Feature/Organization/`（settings props の変更に追随: invitations prop 撤去・members 縮小）

### 設計（Admin/Users.svelte）

- レイアウト: `AppLayout` + 2 カラム（左 `AdminMenuNav`、右コンテンツ）。モック
  `PC_管理メニュー/02〜09` の導線（追加 / エラー / 更新完了 / 削除アラート）に対応する。
- **メンバー一覧**（Card + list）: 表示名 / メール / ロール select / 2FA badge / 削除ボタン。
  - ロール select（`Select` atom）: options = 管理者/編集者/撮影者（`ConsoleRole` 3 値）。
    現在値は `roleState`（editor/shooter/admin はそのまま、unassigned は空 option
    「未割当（選択してください）」を先頭表示）。owner 行と自分の行は select ではなく
    `roleLabel` テキスト表示（現行 Settings と同じ流儀。disabled は使わない）。
  - 変更は `router.patch(/organizations/{slug}/members/{id}, { role })`。エラー
    （project 不在等）は error bag → 行近傍の `FormError` + flash で表示（押下時エラー）。
  - 削除は `ConfirmDialog`（モック 08）→ `router.delete(...)`。
  - 2FA リセット: Settings から**移設**（`RecentAuthModal` + 理由 Modal + guardWithRecentAuth
    一式をそのまま移す）。
- **ユーザー追加**（Card。モック 03/04/06）: email + ロール select（3 値）→
  `POST /organizations/{slug}/invitations`。成功で reset + flash toast、失敗は FormField error
  （空値・形式・重複中立・project 不在）。
- **招待中一覧**: email / roleLabel / 期限 / 取消（`DELETE /organizations/{slug}/invitations/{id}`）。
  ※取消導線は Settings に存在しなかったが route (`organizations.invitations.revoke`) は既存。
- ボタンは常に押下可能（必須未充足での disabled 禁止 = 禁止事項 8。processing 中の
  `loading` prop は既存 atom の流儀どおり許容）。二重送信抑止は各 submit ハンドラ冒頭の
  冪等ガード `if (form.processing) return;` で明示する（disabled に頼らない）。

### 設計（AdminMenuNav.svelte）

```
features/admin/AdminMenuNav.svelte
Props: { active: "users" | "categories"; usersUrl: string | null; categoriesUrl: string | null }
```

Props は strict（optional `?` を使わず `string | null` 必須指定 = undefined 混入を型で拒否。
呼び出し側はサーバ props の null をそのまま渡す）。
- `Card` 内に「管理メニュー」見出し + リンク（`ユーザー管理` = Users icon /
  `カテゴリ管理` = Tags icon。`@lucide/svelte`）。null の項目は**非表示**（can 連動を
  サーバが URL null で表現。撮影者にはそもそも項目が出ない）。
- features/admin ドメイン内のみで使用（atomic import graph 順方向: pages → features）。

### 設計（Settings スリム化 + settings() props）

- `Organizations/Settings.svelte` から撤去: メンバー一覧 Card・招待 Card・2FA リセット Modal・
  changeRole/removeMember/invite の各ロジック。
- 残す: 組織名・2FA 必須方針・API キー導線・オーナー移譲（移譲先 select は
  `members`（id/name のみ）を使用）・RecentAuthModal（移譲と 2FA 方針が使用）。
- 追加: `canManageMembers` のとき「ユーザー管理画面を開く」`TextLink`（→ /manage/users。
  API キー Card と同じリンク Card 流儀）。
- `OrganizationController::settings()` props 変更:
  - `members`: `{id, name, email, role, twoFactorStatus}` → **`{id, name}` に縮小**
    （オーナー移譲 select 用途のみ。email/2FA を出さない = PII 最小化の改善）
  - `invitations`: **撤去**（Admin/Users が担う）
  - `currentUserRole` / `canManageApiKeys`: 維持

### TS 型（types/admin.ts）

```ts
export type ConsoleRole = "admin" | "editor" | "shooter";
export type MemberRoleState = ConsoleRole | "owner" | "unassigned";

export interface MemberRow {
    id: number;
    name: string;
    email: string;
    roleState: MemberRoleState;
    roleLabel: string;
    twoFactorStatus: "disabled" | "pending" | "enabled";
    isSelf: boolean;
}

export interface InvitationRow {
    id: number;
    email: string;
    roleState: MemberRoleState;
    roleLabel: string;
    expiresAt: string;
}
```

### PHPStan適合チェック
- [x] `settings()` の props 縮小は docblock 型を更新（typed array shape）
- [x] フロントのみの施策部分は `pnpm typecheck` / `pnpm lint` で担保

### テスト計画
- [ ] `AdminUsers.test.ts`（新規）: 描画（members/invitations/追加フォーム）、owner・self 行に
      select が無いこと、未割当行の表示、**必須未充足でもボタンが disabled でないこと**、
      エラー props 表示（FormField error）、削除 ConfirmDialog の文言
- [ ] `OrganizationsSettings.test.ts` 更新: メンバー管理 UI（member-list / invite-submit /
      reset-two-factor）が**存在しない**こと（旧 UI 並走の回帰封じ）、ユーザー管理導線リンク、
      オーナー移譲 select が members(id/name) で動くこと
- [ ] Feature: 旧 UI 非並走の props 面固定 — settings() の Inertia props に **`invitations`
      キーが存在しない**・`members` 行に **`email` キーが存在しない**ことを assert
      （Vitest の UI 不在テストと両面で契約固定。PII 最小化）

### リスク
- Settings の E2E/Vitest 資産の書き換え量が多い（メンバー管理系テストは Users 側へ移植）。
  移植漏れは `pnpm test` の未使用 fixture 警告と coverage 差分で検出

---

## 施策 8: カテゴリ管理画面バックエンド（GET /projects/{project}/categories）

### 変更箇所
- `app/Http/Controllers/Projects/CategoryController.php`（`index()` 追加）
- `app/Policies/CategoryPolicy.php`（`viewAny()` 追加）
- `routes/web.php`（業務 group 内に GET 追加）

### 波及変更
- TypeScript 型定義: なし（既存 `CategoryOption` を再利用）
- API Resource/DTO: なし（既存 `projects.show` と同じ typed array shape）
- テストファイル: `tests/Feature/Projects/CategoryIndexPageTest.php`（新規）

### 変更後コード

CategoryPolicy（親委譲を維持）:

```php
/**
 * カテゴリ管理画面 (専用 index) の閲覧: プロジェクトを操作できる人 (= 編集者以上)。
 * 撮影者の read は一覧 props (projects.show / capture) 経由であり、管理画面には入れない。
 * 対象 Category が無いため Project を追加引数に取る (create/reorder と同じシグネチャ規約)。
 */
public function viewAny(User $user, Project $project): bool
{
    return $this->projectPolicy->update($user, $project);
}
```

CategoryController:

```php
/** カテゴリ管理画面 (doc/04 §4.2。一覧・追加・編集・削除・▲▼ は既存 write endpoint を使う) */
public function index(Request $request, Project $project): Response
{
    $organization = $this->resolveCurrentOrganization($request);
    // URL 整合 guard: 認可より前に 404 (cross-org の存在を漏らさない)
    $this->resolveOrganizationProject($organization, $project);
    Gate::authorize('viewAny', [Category::class, $project]);

    $user = $request->user();
    Assert::isInstanceOf($user, User::class);

    return Inertia::render('Admin/Categories', [
        'project' => ['id' => $project->id, 'name' => $project->name],
        // sort_order 順 (▲▼ の表示順 = 動画一覧の並び順と同一規約)
        'categories' => array_values($project->categories()->orderBy('sort_order')->get()
            ->map(fn (Category $category): array => [
                'id' => $category->id,
                'name' => $category->name,
            ])
            ->all()),
        // 管理メニュー nav: ユーザー管理リンク (org 管理者のみ。can 連動。route helper で生成)
        'usersUrl' => $user->can('manageMembers', $organization) ? route('manage.users.index') : null,
    ]);
}
```

routes/web.php（既存 categories 群の直前。業務 group 内 = `require-active-subscription` +
`project.in-current-org` を継承）:

```php
// カテゴリ管理画面 (管理メニュー。一覧表示のみ。write は下記既存 route)
Route::get('/projects/{project}/categories', [CategoryController::class, 'index'])
    ->name('projects.categories.index');
```

- 1 param のため `NestedRouteIdorDefenseTest` の inventory 対象外（2+ param なし）。
  `{project}` guard は group middleware + inline の既存 2 層。
  `ProjectRouteCurrentOrgGuardTest`（deny-by-default）が新 route の middleware 付与を自動強制。
- store/update/destroy/reorder・`CategoryService` は**一切変更しない**。

### PHPStan適合チェック
- [x] 戻り値 `Response` 明示、typed array shape（既存 categoryRows と同形）
- [x] Assert による user narrow

### テスト計画（`CategoryIndexPageTest.php` 新規）
- [ ] org Owner/Admin → 200 + `Admin/Categories` component
- [ ] project_admin（編集者）→ 200（doc/10 §10.5: 編集者 = 管理画面可）
- [ ] project_member（撮影者）/ 無関係 org Member → **403**
- [ ] cross-org の {project} → **404**（テスト名で「authorize より前に 404」を明示し
      IDOR 不変条件との接続を示す。存在オラクル封じ）
- [ ] categories が sort_order 順で返る
- [ ] `usersUrl`: org admin → あり / project_admin（org Member）→ null
- [ ] 画面経由の一連の操作（既存バックエンドとの結合）: 追加 → 重複名 errors('name') →
      reorder → 並びが index props に反映 → 削除 → 当該カテゴリの manual が未分類
      （既存 write endpoint の回帰は既存テストが担保。ここでは index への反映のみ検証）

### リスク
- なし（読み取り専用 action の追加 + Policy 1 メソッド）

---

## 施策 9: カテゴリ管理画面フロント + Projects/Show 移設・導線

### 変更箇所
- `resources/js/pages/Admin/Categories.svelte`（新規）
- `resources/js/pages/Projects/Show.svelte`（カテゴリ CRUD UI 撤去 + 管理メニュー導線追加）
- `app/Http/Controllers/Projects/ProjectController.php` `show()`（props 追加）

### 波及変更
- TypeScript 型定義: `Projects/Show.svelte` の `Props` interface（`canManageMembers` 追加）
- API Resource/DTO: なし
- テストファイル: `tests/js/pages/AdminCategories.test.ts`（新規）、
  `tests/js/pages/ProjectsShow.test.ts`（カテゴリ CRUD 不在 + 導線表示の更新）、
  `tests/Feature/ProjectShowEmailVisibilityTest.php`（props 追加の影響なし・確認のみ）

### 設計（Admin/Categories.svelte）

- `AppLayout` + 2 カラム（左 `AdminMenuNav` active="categories"、右コンテンツ）。
- コンテンツはモック `PC_管理メニュー/10〜17` の構成:
  「+ カテゴリ追加」フォーム（Card）+ 一覧テーブル（▲▼ / カテゴリ名 / 編集 / 削除）。
- 実装は **Projects/Show.svelte L95-164（script）+ L373-463（markup）+ L561-612（Modal/Dialog）を
  移設**し、URL は `project.id` props から組み立てる（既存 write endpoint のまま）:
  - 追加: `POST /projects/{id}/categories`（重複・空値・50 字超は FormField error = モック 12）
  - 編集: Modal + `PATCH /projects/{id}/categories/{categoryId}`（モック 15）
  - 削除: ConfirmDialog「このカテゴリの動画マニュアルは未分類になります」+ DELETE（モック 17）
  - ▲▼: `PATCH /projects/{id}/categories/reorder`（order 配列。端の行は該当方向の
    ボタンを**非描画**（現行 Show と同じ guard 関数流儀。disabled にしない））
- 成功 flash は既存 `back()->with('success', ...)` → toast（consumeFlash）で
  モック 13/15/17 の完了表示に対応。

### 設計（Projects/Show.svelte の変更）

- 撤去: カテゴリ管理 Card（L373-464）、カテゴリ CRUD の script（L95-164）、
  編集 Modal（L561-601）、削除 ConfirmDialog（L603-612）。
  カテゴリ**フィルタ** select（L285-301）は残す（一覧絞り込みは doc/04 ホーム仕様）。
- 追加: 管理メニュー導線（`canManage` で「カテゴリ管理」リンク →
  `/projects/{id}/categories`、`canManageMembers` で「ユーザー管理」リンク → `/manage/users`。
  どちらも権限がなければ**非表示** = doc/04「管理者ログイン時のみサイドバーに表示」）。
- `Props` に `canManageMembers: boolean` を追加。

### 設計（ProjectController::show の props 追加）

```php
return Inertia::render('Projects/Show', [
    // ... 既存 props は不変 ...
    // 管理メニュー導線 (doc/04: 管理者のみサイドバー表示)。単一根拠は Gate
    'canManageMembers' => $user->can('manageMembers', $organization),
]);
```

### PHPStan適合チェック
- [x] props 追加は bool 1 個（docblock 更新）

### テスト計画
- [ ] `AdminCategories.test.ts`（新規）: 描画・追加フォームのエラー表示・端の行の▲▼非描画・
      削除ダイアログ文言（未分類化の警告）・**disabled 不使用**
- [ ] `ProjectsShow.test.ts` 更新: `category-list` / `category-submit` 等の旧 testid が
      **存在しない**こと（並走 UI の回帰封じ）、**カテゴリフィルタの存続テスト**
      （`manual-filter-category` が描画され categories が選択肢に出る = 撤去し過ぎの回帰封じ）、
      `canManage`/`canManageMembers` に応じた導線リンクの表示/非表示
- [ ] Feature（`ProjectPageTest` 相当の既存 Show テスト）: `canManageMembers` が
      org admin=true / project_admin=false になること

### リスク
- Projects/Show の diff が大きい（撤去中心）。カテゴリフィルタの残置を Vitest で明示的に固定する

---

## 施策 10: ドキュメント更新

### 変更箇所
- `docs/template-divergence.md`: 新エントリ「D{n} 管理メニューのユーザー管理 =
  招待一本化 + 遷移コマンドロール + Settings からの UI 移設」
  - logic-driven 理由: doc/04 §4.2（管理メニュー）+ doc/02 §2.5（管理者/一般の分離）を、
    テンプレの org メンバー基盤の上に「org ロール + Default Project pivot の合成」で実現。
    doc/04 レガシーモックの直接発行/平文 PW 表示はセキュリティ不変条件（PasswordPolicy /
    CipherSweet）と衝突するため招待一本化に reconcile（ユーザー ID → email マッピング）
  - 保証し続ける不変条件: 招待 token の hash-only 保存 / 中立メッセージ / laratrust_team_id
    明示 / Owner 操作は transferOwnership のみ（enum 型で構造化）/ PII 可視性は
    manageMembers 到達境界。drift 防止テスト: `ConsoleRoleTransitionTest` /
    `UserManagementPageTest` / Settings の member UI 不在 Vitest
- `docs/architecture.md`: 「管理メニュー（/manage/users・/projects/{project}/categories）」節を
  追記（役割マッピング表・DefaultProjectResolver の read/write 契約・pivot 書き込み経路が
  OrganizationMembershipService / ProjectMemberController に閉じること
  （`ProjectMemberPivotWritePathTest` が強制）・**A+B 不可分の理由**（endpoint 契約変更と
  唯一 caller UI の同時更新。将来の分割 PR 事故防止））

### テスト計画
- [ ] `pnpm lint`（markdown への影響なし）・ドキュメントのみ

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | incremental（単一 worktree で施策 1→5（A）→ 6→7（B）→ 8→9（C）→ 10 の順に積み上げ、A+B 完了時点と C 完了時点でそれぞれテスト green を確認してコミット） |
| 判断根拠 | A の `inviteMember` シグネチャ変更・endpoint 契約変更は B の UI と不可分（概念設計の並走禁止判断）。C は独立だが Projects/Show と AdminMenuNav を共有するため同一 worktree の直列実装が最も競合が少ない |
| 競合リスク | `routes/web.php`・`Projects/Show.svelte` は他フィーチャと衝突しやすい（本フィーチャ内では施策 6/8 と 9 が触る）。標準の worktree 運用（`scripts/setup-worktree.sh`）で main とのドリフトを最小化 |

## 検証コマンド（完了条件）

`composer test` / `composer phpstan`（level 10）/ `vendor/bin/pint --test` /
`pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm build` 全 green。

## 使命・禁止事項セルフチェック

- 使命寄与: 現場ユーザーのオンボーディング（招待→撮影者/編集者）とカテゴリ運用を管理者が
  セルフサービス化 = 「専門知識ゼロの現場でも回る」運用面の完成（doc/04 §4.2）
- 禁止事項: 4（Inertia render + DTO のみ）、7（全操作 `back()->with(...)`）、8（disabled 不使用・
  押下時 error bag 表示）、1（全施策にテスト計画・不変条件は Feature/Vitest 固定）を遵守
- セキュリティ不変条件: tenant キー不信（project_role は forceFill 導出・ProhibitsProtectedKeys）、
  認可前 404（既存 guard 維持）、cross-org 不可（pivot 掃除は org relation 限定 + テスト固定）、
  laratrust_team_id 明示（changeRole 再利用）、PII CipherSweet（検索なし・可視性は 403 境界）
