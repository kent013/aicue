【使命】AI-CUE: SOP 起点に AI が動画シナリオ生成、PWA 撮影で標準化マニュアル動画。思考ゼロ・編集ゼロ。
【禁止事項】1 テストなし完了禁止 / 2 PHPStan widen 禁止 / 4 response()->json 直書き禁止 / 8 必須条件未充足でボタン disabled 禁止。フロントは Svelte5 runes + DS token、アイコン Lucide。
【ツール制限】コマンド実行・書き込み禁止。読み込み可。
---
あなたは Laravel + Svelte アーキテクトです。詳細設計をレビューしてください。前提: PHP8.4/Laravel12/Svelte5 runes/Inertia/TS/PHPStan L10/Pest/Laratrust。観点: 1 正確性 2 既存整合 3 PHPStan L10 4 テスト網羅 5 DTO/JsonResource 6 Inertia vs API 7 副作用 8 波及変更網羅 9 セキュリティ 10 DESIGN.md(token/Lucide) 11 Atomic Design(単方向 import)。出力: 施策ごと APPROVE/REQUEST_CHANGES、[Critical]/[Warning]/[Suggestion]、Critical/Warning に修正案、全体判定、日本語。
---

## 詳細設計書
# 詳細設計: role-change-project-link

## 使命・制約（絶対遵守）

### アプリの使命（North Star）
AI-CUE は現場の作業手順書(SOP)を起点に AI が動画シナリオを生成し、PWA でナビゲーション
撮影して標準化マニュアル動画を作る。思考ゼロ・編集ゼロ。単一 Default Project。

### 禁止事項（関連）
- #1 テストなしの実装完了 / #2 PHPStan widen
- #8 必須条件未充足を理由にボタンを disabled にする UI (押下時にエラー表示 = 本件は非表示/リンク)
- フロントは Svelte 5 runes + DS token、アイコンは Lucide のみ

### コーディングルール
- PHPStan level 10 / Pest / RefreshDatabase グローバル / vitest
- 本番コードの変更は**純フロント** (Admin/Users.svelte のみ)。加えて権限同値の backend 不変条件テストを追加。
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript

## 概念設計リファレンス
`devnotes/20260715-1955-role-change-project-link/conceptual-design.md`(APPROVED)

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | 注記に projects.create リンクを追加 | `resources/js/pages/Admin/Users.svelte` | Low |
| 2 | vitest テスト追加 | `tests/js/pages/AdminUsers.test.ts` | Low |
| 3 | 権限同値の backend 不変条件テスト | `tests/Feature/Admin/UserManagementPageTest.php` | Low |

## 施策1: 注記に projects.create リンクを追加

### 変更箇所
- `resources/js/pages/Admin/Users.svelte` の `{#if !hasDefaultProject}` ブロック (L266-270)

### 波及変更
- TypeScript 型定義: なし (Props 不変)
- API Resource/DTO: なし (controller 不変)
- テストファイル: 施策2 (vitest)・施策3 (Feature)

### 現行コード
```svelte
{#if !hasDefaultProject}
    <p class="mt-2 text-caption text-text-secondary" data-testid="no-project-note">
        プロジェクトがまだありません。編集者・撮影者を割り当てるには、先にプロジェクトを作成してください。
    </p>
{/if}
```

### 変更後コード
```svelte
{#if !hasDefaultProject}
    <p class="mt-2 text-caption text-text-secondary" data-testid="no-project-note">
        プロジェクトがまだありません。編集者・撮影者を割り当てるには、先にプロジェクトを作成してください。
    </p>
    <!-- 詰まりの文脈から 1 ホップで作成画面へ。既存 CTA 流儀 (Button href+inertia)。
         disabled にはしない (禁止事項#8): 権限を持つ閲覧者のみ到達する導線を表示する -->
    <Button
        href="/projects/create"
        inertia
        variant="secondary"
        size="sm"
        class="mt-3"
        testId="create-project-link"
    >
        プロジェクトを作成
    </Button>
{/if}
```
- `Button` は既に import 済み (L5)。追加 import なし。
- `href` + `inertia` で Button atom は Inertia `Link` (`<a href>`) を描画 = SPA 遷移
  (`Projects/Index.svelte` L41 / `Dashboard.svelte` と同流儀)。
- `variant="secondary" size="sm"` は注記直下の副次アクションとして控えめに (DS token 委譲)。

### PHPStan 適合チェック
- PHP 変更なし。該当なし。

### テスト計画
施策2・3 参照。

### リスク
- `{#if}` に 2 要素 (p + Button) を置くが Svelte は複数ルート要素を許容。DOM 親は Card のまま
  (既存 no-project-note の親構造は不変)。
- CTA が指す projects.create に閲覧者が到達できない可能性 → 施策3 の権限同値テストで防止。

## 施策2: vitest テスト追加

### 変更箇所
- `tests/js/pages/AdminUsers.test.ts`

### 新規テスト
```ts
it("project 不在時は projects.create への作成リンクを出す (href 正しい・文言維持)", () => {
    render(Users, {
        props: { ...baseProps, hasDefaultProject: false, categoriesUrl: null },
    });

    const link = screen.getByTestId("create-project-link");
    expect(link).toHaveAttribute("href", "/projects/create");
    // 既存注記の文言は維持
    expect(screen.getByTestId("no-project-note")).toHaveTextContent(
        "編集者・撮影者を割り当てるには、先にプロジェクトを作成してください。",
    );
});

it("project 在時は作成リンクを出さない", () => {
    render(Users, { props: { ...baseProps, hasDefaultProject: true } });
    expect(screen.queryByTestId("create-project-link")).toBeNull();
});
```
- 既存 test「project 不在時は案内文を出し…」(L273) は維持。

### テスト計画
- [x] Inertia `Link` は AdminUsers.test.ts の mock で `...importOriginal` により実物が使われるため、
      `<a href>` を描画し `toHaveAttribute("href", ...)` で検証可能 (router.visit は未 mock だが
      本テストは click せず href 属性のみ検証)。

### リスク
- Inertia `Link` の render が context 依存で失敗する懸念 → 実装時に確認。失敗する場合は
  同 mock 方針の既存テストに倣い調整 (ただし href 属性描画は render のみで完結する想定)。

## 施策3: 権限同値の backend 不変条件テスト

### 変更箇所
- `tests/Feature/Admin/UserManagementPageTest.php` にテスト 1 件追加

### 新規テスト
```php
test('ユーザー管理を閲覧できる権限 (manageMembers) はプロジェクト作成権限 (create) と同値 (CTA 導線の権限整合を固定)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $admin = attachOrganizationMember($organization, OrganizationRole::Admin);
    $member = attachOrganizationMember($organization, OrganizationRole::Member);

    foreach ([$owner, $admin, $member] as $user) {
        // manageMembers を持つ閲覧者は必ず projects.create でき、持たない者は作成もできない
        expect(Gate::forUser($user)->allows('create', [Project::class, $organization]))
            ->toBe(Gate::forUser($user)->allows('manageMembers', $organization));
    }
    // 具体値も固定 (degenerate PASS 防止): owner/admin=true, member=false
    expect(Gate::forUser($owner)->allows('manageMembers', $organization))->toBeTrue();
    expect(Gate::forUser($admin)->allows('manageMembers', $organization))->toBeTrue();
    expect(Gate::forUser($member)->allows('manageMembers', $organization))->toBeFalse();
});
```
- import: `App\Enums\OrganizationRole`, `App\Models\Project`, `Illuminate\Support\Facades\Gate`
  (テストファイルの既存 import を確認し不足分を追加)。

### PHPStan 適合チェック
- [x] Gate::forUser/allows は bool 返却。expect アサートのみ。型リスクなし。

### テスト計画
- [x] Factory 生成 (createOrganizationWithOwner / attachOrganizationMember)。個別 DatabaseTransactions 不使用。

### リスク
- 同値が将来崩れたらこのテストが fail し CTA 導線の見直しを促す (それが目的)。

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | standalone |
| 判断根拠 | 単一 page + そのテスト + 権限不変条件テストに閉じる小変更。 |
| 競合リスク | なし (他 2 件は別領域・マージ済み) |

## 関連する現行コード（抜粋）

### Admin/Users.svelte 現行注記 (L266-270)
`{#if !hasDefaultProject}<p class="mt-2 text-caption text-text-secondary" data-testid="no-project-note">プロジェクトがまだありません。編集者・撮影者を割り当てるには、先にプロジェクトを作成してください。</p>{/if}`。Button は L5 で import 済み。

### 既存 CTA 流儀 (Projects/Index.svelte L41)
`<Button href="/projects/create" inertia testId="create-project-button">`。Button atom は href+inertia のとき Inertia Link (`<a href data-testid>`) を描画。

### 権限 (完全同一式)
OrganizationPolicy::manageMembers = `organizationRole($org)?->canManage()`。ProjectPolicy::create(User,Organization) = `organizationRole($org)?->canManage()`。canManage = role !== Member (Owner/Admin=true)。UserManagementController::index は Gate::authorize('manageMembers') 後に描画。ProjectController::create は Gate::authorize('create',[Project::class,$org])。

### AdminUsers.test.ts mock
`vi.mock("@inertiajs/svelte", ...importOriginal, router:{patch,delete,post}, page:pageState)` = Link は実物。baseProps に hasDefaultProject/categoriesUrl あり (createProjectUrl プロップは無い = 純フロント)。
