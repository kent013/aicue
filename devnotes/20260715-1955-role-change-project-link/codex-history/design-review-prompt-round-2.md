Round 1 の Critical/Warning に対応・一部反論します。再レビューし全体判定を返してください。

## [施策1 Suggestion] href 直書き → 反論
既存 projects.create CTA は全て href 直書き (Projects/Index.svelte L41、Dashboard.svelte)。Ziggy 慣行はコードベースに無く、この 1 箇所だけ導入は非一貫。既存流儀に揃えます。
## [施策1 Suggestion] コメント最小化 → 対応
コメントを 1 行に短縮。

## [施策2 Warning] Inertia Link 実体依存 → 反論
AdminUsers.test.ts は既に importOriginal で Link 実体を使う mock 方針。ここだけ stub は同ファイル内で方針二分。href は Link mount 時に描画され、本テストは click せず href 属性のみ検証で安定。
## [施策2 Suggestion] 1 ケース 1 責務 → 対応済み (新規 2 テストは責務分離、既存案内文テスト維持)

## [施策3 Critical] Policy 同値固定は結合度が高い → 対応 (reachability へ変更)
Policy 内部式の同値固定をやめ、HTTP レベルの reachability に変更:
```php
test('CTA 導線: manageMembers を持つ Owner/Admin は projects.create に到達できる (200)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
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
/projects/create は require-active-subscription + project.in-route-org 配下。無償プラン (plan_code null = createOrganizationWithOwner の既定) は課金ゲート通過、project.in-route-org は {project} 無し route で no-op。member は ProjectController::create の Gate::authorize('create') で 403。
## [施策3 Warning] 診断性 → 対応 (owner/admin と member の 2 テストに分割)

スコープ外として明記: 有償プラン支払い不健全 + Default Project 不在の稀な組合せでは注記(課金ゲート外)は CTA を出すが projects.create は課金ゲートで遮断される。これは注記の既存アドバイスに元々内在する edge で本件の新規導入ではない。common path (無償/健全) を本テストで固定。

これで残件は解消と考えます。APPROVED 可否を判定してください。
