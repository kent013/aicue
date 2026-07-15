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
    <!-- 詰まりの文脈から 1 ホップで作成画面へ (既存 CTA 流儀 = Button href+inertia) -->
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

## 施策3: CTA 導線の到達性 (reachability) backend テスト

Policy 内部式の同値を固定すると将来の正当な権限分離を阻害する (Codex 指摘)。代わりに
「/manage/users を見られる set (= manageMembers) が projects.create に**到達できる**」という
**振る舞い (reachability)** を HTTP レベルで固定する。実装詳細に過拘束せず、CTA が 403 で
詰まらない不変条件だけを守る。診断性のためロール別に分割する。

### 変更箇所
- `tests/Feature/Admin/UserManagementPageTest.php` にテスト 2 件追加
  (既存 import に不足があれば追加。現状 OrganizationRole / Project / User は import 済み)

### 新規テスト
```php
test('CTA 導線: manageMembers を持つ Owner/Admin は projects.create に到達できる (200)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner(); // 無償プラン (plan_code null) = 課金ゲート通過
    $admin = attachOrganizationMember($organization, OrganizationRole::Admin);
    $admin->forceFill(['current_organization_id' => $organization->id])->save();

    $this->actingAs($owner)->get('/projects/create')->assertOk();
    $this->actingAs($admin)->get('/projects/create')->assertOk();
});

test('CTA 導線: manageMembers を持たない Member は projects.create で 403 (権限境界が非退化)', function (): void {
    [$organization] = createOrganizationWithOwner();
    $member = attachOrganizationMember($organization, OrganizationRole::Member);
    $member->forceFill(['current_organization_id' => $organization->id])->save();

    $this->actingAs($member)->get('/projects/create')->assertForbidden();
});
```
- 2 テストで「/manage/users を見られる owner/admin は projects.create に到達でき、見られない
  member は到達できない」= CTA が指す先が閲覧者集合と一致することを behavioral に固定。
- `/projects/create` は `require-active-subscription` + `project.in-current-org` 配下。
  無償プラン (plan_code null) は課金ゲートを通過 (BillingAccess の free tier 許可)、
  `project.in-current-org` は {project} 無し route で no-op。member は ProjectController::create の
  `Gate::authorize('create')` で 403。

### PHPStan 適合チェック
- PHP テストのみ。型リスクなし。

### テスト計画
- [x] Factory 生成。個別 DatabaseTransactions 不使用 (グローバル RefreshDatabase)。
- [x] ロール別に分割し失敗時の診断性を確保 (Codex 指摘)。

### リスク・スコープ外
- **有償プランが支払い不健全 (past_due 等) かつ Default Project 不在**の稀な組み合わせでは、
  注記 (課金ゲート外) は CTA を出すが projects.create は require-active-subscription で遮断される。
  これは注記の既存アドバイス (「プロジェクトを作成してください」) にも元々内在する edge であり、
  本件 (リンク追加) の新規導入ではない = スコープ外。common path (無償/健全) を本テストで固定する。

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | standalone |
| 判断根拠 | 単一 page + そのテスト + 権限不変条件テストに閉じる小変更。 |
| 競合リスク | なし (他 2 件は別領域・マージ済み) |
