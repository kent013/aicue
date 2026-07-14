# アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。v1 スコープ: 字幕のみ / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

# 禁止事項

1. テストなしの実装完了報告 2. PHPStan エラーの widen・baseline 化 3. dev DB への破壊操作 4. `response()->json()` の直書き(DTO/JsonResource/Inertia を使う。仕様固定 endpoint のみ例外) 5. LLM 呼び出しの Prism 直呼び 6. prompt 文字列のコード直書き 7. 操作系 POST の応答での `redirect()->intended()`(`back()->with(...)` で完結) 8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示。DESIGN.md)

# セキュリティ不変条件
tenant キー不信 / 子は親に属する(nested route の不整合は認可より前に 404、NestedRouteIdorDefenseTest 登録) / cross-org 不可 / 権限判定は laratrust_team_id 明示 / PII(email/name)は最小開示。

```
【思考原則】まず仮説を立てろ。データに真摯に向き合え。先人の知恵を探せ(Laravel/Svelte)。機能の名前に立ち返れ。仕組みが機能していない段階で値を弄るな。
【ツール使用制限】コマンド実行・ファイル書き込みは一切行わず、提供テキストの分析に集中。ファイル読み込みは許可。
```

---

あなたは経験豊富な Web アプリケーションアーキテクトです。Laravel + Svelte アプリの詳細設計をレビューしてください。

【前提環境】PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript / PHPStan level 10 / Pest / DTO + JsonResource / Laratrust RBAC (Organization → Team → Project 階層)。

【レビュー観点】
1. コードの正確性(ロジックエラー、エッジケース、null 安全性)
2. 既存コードとの整合性(命名規約、パターン、API)
3. PHPStan level 10 適合性(型安全性、generics、Assert 使用)
4. テスト計画の網羅性(各施策に Pest テスト、RefreshDatabase グローバル適用に従う)
5. DTO/JsonResource パターンの遵守
6. Inertia Props vs API Response の使い分け
7. 副作用・後退リスク
8. 波及変更の網羅性(TypeScript 型定義、API Resource、テストが変更対象に含まれているか)
9. セキュリティ(認可チェック、入力バリデーション、OWASP Top 10、セキュリティ不変条件)
10. DESIGN.md 準拠(token 経由か、hex 直書きを増やさないか)
11. Atomic Design 準拠(atoms/molecules/organisms の責務・単方向 import、アイコンは Lucide、SVG 直書き新設なし)

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

---

## 詳細設計書

（以下 detailed-design.md 全文）

# 詳細設計: project-members-ui

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した
**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、
専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。
- v1 スコープ: 字幕のみ / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg /
  単一 Default Project。

### 禁止事項

1. テストなしの実装完了報告 (不変条件は Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作をエージェント判断で実行
4. `response()->json()` の直書き (DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び (`app/Prompts/` の factory 経由のみ)
6. prompt 文字列のコード直書き
7. 操作系 POST の応答での `redirect()->intended()` (`back()->with(...)` で完結)
8. 必須条件未充足を理由にボタンを disabled にする UI (押下時にエラー表示。DESIGN.md)

### セキュリティ不変条件 (本設計に関係する項)

- tenant キー不信 (payload から ownership/actor キーを受けない)。
- 子は親に属する (nested route の不整合は認可より前に 404、`NestedRouteIdorDefenseTest` 登録済み)。
- cross-org 不可 (relation / org-scoped 解決経由のみ)。
- PII (email/name) は最小開示。`assignableUsers` は `can('update')` ゲート下でのみ実データ。

### コーディングルール

- **PHPStan level 10** 必須 (`composer phpstan`)。
- **Pest** (`composer test`)。**RefreshDatabase** + `--parallel` グローバル適用
  (`tests/Pest.php`。個別 `DatabaseTransactions` 禁止)。
- テストデータは **Factory** で生成。
- **DTO + JsonResource** パターン (本設計は Inertia props のみで JSON API を足さない)。
- アーリーリターン推奨。`composer fix` (Pint) / `pnpm lint:fix`。
- PHP 8.4 + Laravel 12 + Svelte 5 runes + Inertia.js + TypeScript。
- フロントは DS token / atom 経由のみ (`DESIGN.md` canonical、ds-purity テストが検出)。
  component 階層は単方向 import (`atomic-import-graph.test.ts` が強制)。アイコンは `@lucide/svelte`。

## 概念設計リファレンス

- [conceptual-design.md](./conceptual-design.md)（Codex `gpt-5.4` レビュー Round 4 で APPROVED）

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| S1 | `ProjectController::show()` に `assignableUsers` prop を追加 (canManage ゲート・非メンバー絞り込み・id/name のみ) | `app/Http/Controllers/Projects/ProjectController.php` | High |
| S2 | `Projects/Show.svelte` にメンバー管理 UI (一覧 + ロール変更 + 追加フォーム + 削除) を追加 | `resources/js/pages/Projects/Show.svelte` | High |
| S3 | Feature テスト: `assignableUsers` prop 契約 (shape / 絞り込み 4 ケース / email 可視性) を追加 | `tests/Feature/Projects/ProjectShowMemberManagementTest.php` (新規) | High |

> **既存資産 (変更しない)**: `ProjectMemberController::store/destroy`・route `projects.members.*`・
> `ProjectPolicy`・`project_members` pivot 書き込み経路・既存テスト
> `tests/Feature/Projects/ProjectMemberTest.php` / `ProjectShowEmailVisibilityTest.php`。
> これらは既に完成・テスト済みで、本設計は「UI から到達可能にする」ことに限定する。

---

## S1: `ProjectController::show()` に `assignableUsers` prop を追加

### 変更箇所

- ファイル: `app/Http/Controllers/Projects/ProjectController.php`
  - `show()` メソッド (L88-133): `Inertia::render('Projects/Show', [...])` に `assignableUsers` を追加。
  - 新規 private メソッド `assignableUserRows()` を追加 (`memberRows()` の下、L275 付近)。

### 波及変更

- TypeScript 型定義: `Projects/Show.svelte` の inline `Props` に `members` / `canViewMemberEmails` /
  `assignableUsers` を追加 (S2 で実施)。
- API Resource/DTO: なし (Inertia props のみ。JSON API は足さない)。
- テストファイル: `ProjectShowMemberManagementTest.php` (新規、S3)。既存
  `ProjectShowEmailVisibilityTest` の `members` 契約は不変 (assignableUsers を足すだけで既存 assertion は
  影響を受けない)。

### 現行コード

```php
// show() の Inertia::render 部 (L114-132)
return Inertia::render('Projects/Show', [
    'project' => [ 'id' => $project->id, 'name' => $project->name, 'description' => $project->description ],
    'items' => $items,
    'members' => $this->memberRows($organization, $project, $canManage),
    'canManage' => $canManage,
    'canViewMemberEmails' => $canManage,
    'manuals' => $this->manualRows($project, $filters),
    'categories' => $this->categoryRows($project),
    'manualFilters' => $filters,
    'canManageMembers' => $user->can('manageMembers', $organization),
]);
```

### 変更後コード

```php
// show() 内: memberRows を 1 度だけ算出し、assignableUsers 導出に再利用する
$memberRows = $this->memberRows($organization, $project, $canManage);

return Inertia::render('Projects/Show', [
    'project' => [ 'id' => $project->id, 'name' => $project->name, 'description' => $project->description ],
    'items' => $items,
    'members' => $memberRows,
    'canManage' => $canManage,
    'canViewMemberEmails' => $canManage,
    // 追加フォームの候補。canManage=false のときは [] (name も PII のため payload 生成時点で絞る)。
    // 候補 = current org メンバーのうち members に含まれない者 (明示・暗黙とも除外)。
    'assignableUsers' => $this->assignableUserRows($organization, $memberRows, $canManage),
    'manuals' => $this->manualRows($project, $filters),
    'categories' => $this->categoryRows($project),
    'manualFilters' => $filters,
    'canManageMembers' => $user->can('manageMembers', $organization),
]);
```

```php
/**
 * メンバー追加フォームの候補 (id/name のみ・PII 最小)。
 *
 * - $canManage=false のときは [] (name も PII。UI 非表示に頼らず payload 生成時点で絞る =
 *   canViewMemberEmails と同じ流儀)。
 * - 候補 = current org のメンバーのうち $memberRows に含まれない者
 *   (明示メンバーも暗黙メンバー = org owner/admin も除外)。暗黙メンバーは既に管理アクセスを
 *   持つため追加が無意味 (概念設計 §暗黙メンバーのサーバ側セマンティクス)。
 *
 * @param  list<array{id: int, name: string, email: string|null, role: string|null, implicit: bool}>  $memberRows
 * @return list<array{id: int, name: string}>
 */
private function assignableUserRows(Organization $organization, array $memberRows, bool $canManage): array
{
    if (! $canManage) {
        return [];
    }

    $memberIds = array_column($memberRows, 'id');

    return array_values(
        $organization->users()->orderBy('users.name')->get()
            ->reject(fn (User $user): bool => in_array($user->id, $memberIds, true))
            ->map(fn (User $user): array => ['id' => $user->id, 'name' => $user->name])
            ->all()
    );
}
```

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている (`list<array{id: int, name: string}>`)。
- [x] `$memberRows` の shape を PHPDoc で受ける (`array_column` の型推論が通る)。
- [x] 配列返却は typed shape 固定 (DTO 不要な read-only の Inertia prop。既存 `memberRows` /
  `categoryRows` と同一流儀)。
- [x] `reject`/`map` のクロージャは `User` を型注釈 (Collection generics)。
- [x] null 安全: `$user->id` / `$user->name` は非 null (users テーブル NOT NULL)。

### テスト計画

- [x] 新規 `ProjectShowMemberManagementTest.php` (S3) で契約を固定。
- [x] 既存 `ProjectShowEmailVisibilityTest` は不変 (members 契約に影響なし。実行して green を確認)。
- [x] 個別 `DatabaseTransactions` を使わない (グローバル RefreshDatabase)。

### リスク

- `$organization->users()->get()` を members 用と assignableUsers 用で 2 回叩く点
  (memberRows 内で 1 回、assignableUserRows で 1 回)。**N は org メンバー数** (通常小)で許容。
  過度な最適化 (単一クエリ化) は可読性を下げるため見送る (AGENTS.md 原則2)。
- `assignableUsers` は canManage=false で `[]` を返すため、閲覧専用ユーザーへ PII (name) が漏れない。

---

## S2: `Projects/Show.svelte` にメンバー管理 UI を追加

### 変更箇所

- ファイル: `resources/js/pages/Projects/Show.svelte`
  - `<script>` の `Props` interface に `members` / `canViewMemberEmails` / `assignableUsers` を追加。
  - メンバー管理用の state / form / ハンドラを追加 (Item 追加・削除の既存流儀に倣う)。
  - テンプレートに「プロジェクトメンバー」Card を追加 (`canManage` gate。管理メニュー Card の直後)。
  - メンバー削除用 `ConfirmDialog` を追加。

### 波及変更

- TypeScript 型定義: 下記 inline interface を追加 (既存 Show.svelte が inline `Item` interface を
  持つのと同じ流儀。共有 `types/*.ts` は新設しない = 単一ページ専用のため)。
- API Resource/DTO: なし。
- テストファイル: フロント単体テストは追加しない (このページに既存の .test.ts はなく、ds-purity /
  atomic-import / typecheck / build のアーキ・型テストで担保。挙動契約は S3 の Feature で固定)。

### 変更後コード (script 部 抜粋)

```ts
// Props interface に追加
interface Member {
    id: number;
    name: string;
    email: string | null;   // canViewMemberEmails=false のときは常に null (キー常在契約)
    role: string | null;    // ProjectRole の value。暗黙メンバーは null
    implicit: boolean;      // true = org owner/admin の管理継承 (pivot なし = 削除/ロール変更不可)
}
interface AssignableUser {
    id: number;
    name: string;
}

interface Props {
    project: { id: number; name: string; description: string | null };
    items: Item[];
    canManage: boolean;
    canManageMembers: boolean;
    members: Member[];
    canViewMemberEmails: boolean;
    assignableUsers: AssignableUser[];
    manuals: { data: ManualListItem[]; meta: PaginationMeta };
    categories: CategoryOption[];
    manualFilters: ManualFilters;
}
// let { ... } = $props(); に members / canViewMemberEmails / assignableUsers を追加

// ProjectRole 表示ラベル (サーバ enum の value → 日本語ラベル。option 定数)
const PROJECT_ROLE_OPTIONS: { value: string; label: string }[] = [
    { value: "project_admin", label: "編集者" },
    { value: "project_member", label: "撮影者" },
];
function roleLabel(role: string | null): string {
    return PROJECT_ROLE_OPTIONS.find((o) => o.value === role)?.label ?? "";
}

/* ---- メンバー追加 (store。assignableUsers から選択) ---- */
const memberForm = useForm({ user_id: "", role: "project_member" });

function submitAddMember(event: SubmitEvent): void {
    event.preventDefault();
    if (memberForm.processing) return; // 二重送信ガード
    // 候補未選択なら押下時エラー (disabled にしない = 禁止事項8)
    if (memberForm.user_id === "") {
        memberForm.setError("user_id", "追加するメンバーを選択してください。");
        return;
    }
    memberForm.post(`/projects/${project.id}/members`, {
        preserveScroll: true,
        onSuccess: () => {
            memberForm.reset();
        },
    });
}

/* ---- ロール変更 (store 再実行 = upsert。Admin/Users の 1 セレクト流儀) ---- */
let changingRoleId = $state<number | null>(null);

function changeMemberRole(member: Member, role: string): void {
    if (role === "" || changingRoleId !== null) return; // 二重送信ガード
    changingRoleId = member.id;
    router.post(
        `/projects/${project.id}/members`,
        { user_id: member.id, role },
        {
            preserveScroll: true,
            onFinish: () => {
                changingRoleId = null;
            },
        },
    );
}

/* ---- メンバー削除 (destroy。ConfirmDialog) ---- */
let removeMemberTarget = $state<Member | null>(null);
let removeMemberDialogOpen = $state(false);
let removingMember = $state(false);

function openRemoveMemberDialog(member: Member): void {
    removeMemberTarget = member;
    removeMemberDialogOpen = true;
}

function removeMember(): void {
    if (removeMemberTarget === null || removingMember) return;
    router.delete(`/projects/${project.id}/members/${removeMemberTarget.id}`, {
        preserveScroll: true,
        onStart: () => { removingMember = true; },
        onFinish: () => {
            removingMember = false;
            removeMemberDialogOpen = false;
        },
    });
}
```

### 変更後コード (template 部 抜粋)

```svelte
{#if canManage}
    <Card padding="lg">
        <h2 class="text-h3">プロジェクトメンバー</h2>
        <p class="mt-1 text-caption text-text-secondary">
            編集者・撮影者を割り当てます。組織の管理者はプロジェクト未所属でも管理できます。
        </p>

        {#if members.length === 0}
            <EmptyState title="メンバーはまだいません"
                description="組織メンバーをこのプロジェクトに割り当てると、ここに表示されます。"
                testId="project-members-empty" />
        {:else}
            <ul class="mt-4 flex flex-col divide-y divide-border" data-testid="project-member-list">
                {#each members as member (member.id)}
                    <li class="flex items-center justify-between gap-4 py-3"
                        data-testid={`project-member-${member.id}`}>
                        <div class="min-w-0">
                            <p class="truncate text-body">{member.name}</p>
                            {#if canViewMemberEmails && member.email}
                                <p class="truncate text-caption text-text-secondary">{member.email}</p>
                            {/if}
                        </div>
                        <div class="flex shrink-0 items-center gap-2">
                            {#if member.implicit}
                                <!-- 暗黙メンバー: org 管理継承。pivot なし = ロール変更/削除不可 -->
                                <Badge tone="neutral" testId={`project-member-implicit-${member.id}`}>
                                    管理者（組織）
                                </Badge>
                            {:else}
                                <Select value={member.role ?? ""}
                                    onchange={(e) => changeMemberRole(member, e.currentTarget.value)}
                                    disabled={changingRoleId === member.id}
                                    testId={`project-member-role-${member.id}`}>
                                    {#each PROJECT_ROLE_OPTIONS as option (option.value)}
                                        <option value={option.value}>{option.label}</option>
                                    {/each}
                                </Select>
                                <Button variant="danger-ghost" size="sm"
                                    onclick={() => openRemoveMemberDialog(member)}
                                    testId={`project-member-remove-${member.id}`}>
                                    削除
                                </Button>
                            {/if}
                        </div>
                    </li>
                {/each}
            </ul>
        {/if}

        <!-- 追加フォーム -->
        <form onsubmit={submitAddMember} class="mt-6 flex flex-col gap-4" data-testid="project-member-add-form">
            {#if assignableUsers.length === 0}
                <p class="text-caption text-text-secondary" data-testid="project-member-no-candidates">
                    アサインできる組織メンバーがいません。
                    {#if canManageMembers}
                        <TextLink href="/manage/users">ユーザー管理</TextLink>から招待・確認できます。
                    {/if}
                </p>
            {/if}
            <FormField label="メンバー" id="project-member-user" error={memberForm.errors.user_id}>
                {#snippet children({ id, describedBy, invalid })}
                    <Select {id} bind:value={memberForm.user_id} error={invalid} aria-describedby={describedBy}>
                        <option value="">選択してください</option>
                        {#each assignableUsers as candidate (candidate.id)}
                            <option value={String(candidate.id)}>{candidate.name}</option>
                        {/each}
                    </Select>
                {/snippet}
            </FormField>
            <FormField label="ロール" id="project-member-role" error={memberForm.errors.role}>
                {#snippet children({ id, describedBy, invalid })}
                    <Select {id} bind:value={memberForm.role} error={invalid} aria-describedby={describedBy}>
                        {#each PROJECT_ROLE_OPTIONS as option (option.value)}
                            <option value={option.value}>{option.label}</option>
                        {/each}
                    </Select>
                {/snippet}
            </FormField>
            <div>
                <Button type="submit" loading={memberForm.processing} testId="project-member-submit">
                    メンバーを追加
                </Button>
            </div>
        </form>
    </Card>
{/if}
```

```svelte
<!-- 削除確認 (AppLayout 直下、既存 ConfirmDialog 群と同列に配置) -->
<ConfirmDialog bind:open={removeMemberDialogOpen} title="メンバー削除"
    message={`「${removeMemberTarget?.name ?? ""}」をこのプロジェクトから外しますか？ 組織のメンバーシップは維持されます。`}
    confirmLabel="削除する" confirmVariant="danger" processing={removingMember}
    onConfirm={removeMember} testId="project-member-remove-dialog" />
```

### PHPStan 適合チェック

- N/A (フロント TS。`pnpm typecheck` で担保)。
- [x] `Props` の全 prop が型宣言され `$props()` で受ける (既存デッドデータ `members` /
  `canViewMemberEmails` の解消も兼ねる)。
- [x] `Select` の `onchange` は `e.currentTarget.value` を string で受ける (既存 `Select` atom の
  イベント型に準拠。既存 Admin/Users は `router.patch` 経由だが本ページは `router.post`)。

### DESIGN.md / Atomic Design 準拠チェック

- [x] 使用コンポーネントは全て既存 atom/molecule/organism (`Card` / `Button` / `Select` /
  `Badge` / `FormField` / `EmptyState` / `TextLink` / `ConfirmDialog`)。新規 component を作らない。
- [x] hex 直書き・新規 SVG なし。色は DS token (`text-text-secondary` / `divide-border` /
  Badge tone) 経由。
- [x] import 方向は pages → organisms/molecules/atoms のみ (単方向。既存 Show.svelte と同じ)。
- [x] 禁止事項8: 追加ボタンは disabled にせず、候補未選択は押下時に form error 表示。
  ロール select の `disabled={changingRoleId === member.id}` は「送信中の当該行の二重送信ガード」で
  あり「必須条件未充足による無効化」ではない (Admin/Users の `changingRole` ガードと同種)。

### テスト計画

- [x] S3 の Feature テストで prop 契約を固定 (canManage / email 可視性 / assignableUsers 絞り込み)。
- [x] `pnpm typecheck` / `pnpm build` green。
- [x] `pnpm test` (ds-purity `ds-purity.test.ts` / atomic-import `atomic-import-graph.test.ts`) green。

### リスク

- ロール select の即時 onchange 送信は誤操作リスクがあるが、Admin/Users の既存流儀と一致させ、
  二重送信ガード (`changingRoleId`) を持たせる。確認ダイアログは追加でロールを重くしないため付けない
  (概念設計の判断。削除のみ ConfirmDialog)。
- flash `success` は既存のグローバル flash 表示機構 (AppLayout / SharedProps) に載る
  (store/destroy は `back()->with('success', ...)`。既存 Item 追加・削除と同経路)。

---

## S3: Feature テスト `ProjectShowMemberManagementTest.php` (新規)

### 変更箇所

- 新規ファイル: `tests/Feature/Projects/ProjectShowMemberManagementTest.php`
- 既存ヘルパー `createOrganizationWithOwner` / `attachOrganizationMember` /
  `attachProjectMember` / `Project::factory()->forOrganization()` を流用 (ProjectMemberTest と同じ)。

### 波及変更

- なし (新規テストファイルのみ)。

### テスト計画 (Inertia assertion で prop 契約を固定)

- [x] **assignableUsers の shape**: 各要素が `id` / `name` のみを持ち、`email` 等の余剰キーを
  含まない (PII 最小)。
- [x] **絞り込み 4 ケース**:
  - 明示メンバー (project pivot 保有) は候補から**除外**される。
  - 暗黙メンバー (org owner/admin) は候補から**除外**される。
  - 他組織ユーザーは候補に**含まれない** (cross-org。current org の users のみ)。
  - `canManage=false` (閲覧のみの project_member 等) では `assignableUsers === []`
    (PII ゲート。canManage=true の org member/admin では非空)。
- [x] **email 可視性の連動** (既存 EmailVisibilityTest と重複しない範囲): canManage=false の
  閲覧者では `members[].email` が全行 null、かつ `assignableUsers === []` を同時に確認
  (PII 二重ゲートの回帰検知)。
- [x] **members に暗黙 + 明示が併存する状況で assignableUsers が両方を除外**していること。
- [x] 個別 `DatabaseTransactions` を使わない (グローバル RefreshDatabase)。
- [x] `store` / `destroy` の挙動は既存 `ProjectMemberTest` が網羅済みのため**重複追加しない**。

### テストケース草案

```php
it('assignableUsers は id/name のみで既存メンバー(明示・暗黙)と他組織を除外する', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();          // owner = 暗黙メンバー
    $assigned = attachOrganizationMember($organization);             // 明示メンバーにする
    $free = attachOrganizationMember($organization);                 // 候補に出るべき
    [$otherOrg, $outsider] = createOrganizationWithOwner('組織B');    // 他組織 = 出ない
    $project = Project::factory()->forOrganization($organization)->create();
    attachProjectMember($project, $assigned, ProjectRole::Member);

    $this->actingAs($owner)->get("/projects/{$project->id}")
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Projects/Show')
            ->where('assignableUsers', function (mixed $rows) use ($free, $assigned, $owner, $outsider): bool {
                $list = $rows instanceof Collection ? $rows->toArray() : (array) $rows;
                $ids = array_column($list, 'id');
                foreach ($list as $row) {
                    expect(array_keys($row))->toEqualCanonicalizing(['id', 'name']); // shape 固定
                }
                return in_array($free->id, $ids, true)
                    && ! in_array($assigned->id, $ids, true)   // 明示メンバー除外
                    && ! in_array($owner->id, $ids, true)      // 暗黙メンバー除外
                    && ! in_array($outsider->id, $ids, true);  // 他組織除外
            }));
});

it('canManage=false の閲覧者には assignableUsers=[] かつ member email も null', function (): void {
    // ProjectShowEmailVisibilityTest の context を流用 (project_member 閲覧者)
    // → where('canViewMemberEmails', false) / where('assignableUsers', []) / members[].email 全行 null
});
```

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | incremental |
| 判断根拠 | 既存 `ProjectController::show()` / `Projects/Show.svelte` への追記が主で、新規ファイルは Feature テスト 1 本のみ。既存資産 (store/destroy/route/Policy) は不変。単一 PR で完結し、他 in-flight 施策との構造的衝突が小さい。 |
| 競合リスク | `Projects/Show.svelte` と `ProjectController.php` を同時編集する他施策があれば行衝突の可能性。現状 in-flight にプロジェクト詳細を触る施策は見当たらない。 |

## 使命・禁止事項 最終チェック

- [x] 使命寄与: プロジェクト単位の撮影者/編集者アサインをブラウザから可能にし、ナビ撮影ワークフローの
  担当分担運用の穴を塞ぐ。
- [x] 禁止事項4: `response()->json()` 直書きなし (Inertia props + `back()->with()`)。
- [x] 禁止事項7: `redirect()->intended()` を使わない (既存 store/destroy は `back()->with()`)。
- [x] 禁止事項8: disabled 依存の UI なし (候補未選択は押下時エラー)。
- [x] 禁止事項1/2: 各施策に Pest テスト (S3)、PHPStan L10 適合 (typed shape / PHPDoc)。
- [x] セキュリティ: cross-org 防御・URL {user} 404・PII ゲート (assignableUsers は canManage 下のみ) を維持。


## 関連する現行コード

### app/Http/Controllers/Projects/ProjectMemberController.php (store/destroy。変更しない)

```php
public function store(Request $request, Project $project): RedirectResponse
{
    $organization = $this->resolveCurrentOrganization($request);
    $this->resolveOrganizationProject($organization, $project); // 認可より前に 404
    Gate::authorize('update', $project);
    $request->validate([
        'user_id' => ['required', 'integer', 'exists:users,id'],
        'role' => ['required', 'string', Rule::enum(ProjectRole::class)],
    ]);
    $userId = $request->input('user_id'); Assert::integerish($userId);
    $role = $request->input('role'); Assert::string($role);
    $target = User::query()->findOrFail((int) $userId);
    if ($target->organizationRole($organization) === null) {
        throw new AuthorizationException('Target user is not a member of this organization.'); // cross-org 403
    }
    $project->members()->syncWithoutDetaching([$target->id => ['role' => ProjectRole::from($role)->value]]);
    return back()->with('success', 'プロジェクトメンバーを追加しました');
}

public function destroy(Request $request, Project $project, User $user): RedirectResponse
{
    $organization = $this->resolveCurrentOrganization($request);
    $this->resolveOrganizationProject($organization, $project);
    abort_unless($organization->users()->whereKey($user->getKey())->exists(), 404); // URL {user} 404
    Gate::authorize('update', $project);
    $project->members()->detach($user->getKey());
    return back()->with('success', 'プロジェクトメンバーを削除しました');
}
```

### app/Http/Controllers/Projects/ProjectController.php show() の memberRows (既存・変更しない)

```php
// show(): $canManage = $user->can('update', $project); 'canViewMemberEmails' => $canManage;
/**
 * @return list<array{id: int, name: string, email: string|null, role: string|null, implicit: bool}>
 */
private function memberRows(Organization $organization, Project $project, bool $canViewEmails): array
{
    $rows = [];
    foreach ($project->members()->get() as $member) {
        $rows[$member->id] = [
            'id' => $member->id, 'name' => $member->name,
            'email' => $canViewEmails ? $member->email : null,
            'role' => $project->memberRole($member)?->value, 'implicit' => false,
        ];
    }
    foreach ($organization->users()->get() as $orgUser) {
        if (isset($rows[$orgUser->id])) { continue; }
        if (! ($orgUser->organizationRole($organization)?->canManage() ?? false)) { continue; }
        $rows[$orgUser->id] = [
            'id' => $orgUser->id, 'name' => $orgUser->name,
            'email' => $canViewEmails ? $orgUser->email : null,
            'role' => null, 'implicit' => true,
        ];
    }
    return array_values($rows);
}
```

### Project モデル (members / memberRole。変更しない)

```php
public function members(): BelongsToMany
{
    return $this->belongsToMany(User::class, 'project_members')->withPivot('role')->withTimestamps();
}
```

### routes/web.php (登録済み・変更しない)

```php
Route::post('/projects/{project}/members', [ProjectMemberController::class, 'store'])->name('projects.members.store');
Route::delete('/projects/{project}/members/{user}', [ProjectMemberController::class, 'destroy'])->name('projects.members.destroy');
```

### ProjectRole enum (変更しない)

```php
enum ProjectRole: string {
    case Admin = 'project_admin';  // label() => '編集者'
    case Member = 'project_member'; // label() => '撮影者'
}
```

### 補足
- 既存テスト `tests/Feature/Projects/ProjectMemberTest.php` (store/destroy/cross-org 403/URL {user} 404) と `ProjectShowEmailVisibilityTest.php` (members[].email PII 最小化契約) は緑で、本設計では変更しない。
- `Projects/Show.svelte` は現状 `members` / `canViewMemberEmails` prop を Props に宣言しておらず無視している (デッドデータ)。本設計でこれを解消する。
- `Select` atom は既存。`onchange={(e) => ...}` で `e.currentTarget.value` を取得できる (Admin/Users.svelte で使用実績あり)。
