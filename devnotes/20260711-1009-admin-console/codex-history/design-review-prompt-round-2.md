# Round 2: Round 1 指摘への対応（Critical 3 / Warning 7 / Suggestion 多数）

全 Critical と Warning に対応した（1 件は根拠付き見送り）。対応内容と改訂後の該当箇所を示す。再判定を依頼する。

## Critical への対応

1. **[Critical 施策4] joinOrganization の attach 固定は冪等でない** → org 参加を `$organization->users()->syncWithoutDetaching([$user->getKey()])` へ変更（再受諾・並行受諾の race で unique 違反にならない）。両受諾経路の事前「既メンバー」チェックは第 1 層として維持。「既 attach 状態での受諾到達が unique 違反にならない」テストを追加。

2. **[Critical 施策6] 異常行（orgRole null）の continue 除外は可観測性不足** → 非表示をやめ、「未割当」として可視化する方式に変更:
   - `MemberRoleState::derive()` の第 1 引数を `?OrganizationRole` にし、null → Unassigned（phpdoc に修復契約を明記）
   - `applyConsoleRole` に修復経路を追加: 対象が org attach 済み + Laratrust ロール未付与なら `addRole` で直接付与（非 attach は changeRole と同じ ValidationException 契約で拒否。第 1 層は Controller の URL 整合 guard = 404）
   - 「異常行が unassigned で表示される」「shooter コマンドで修復できる」テストを追加

3. **[Critical 横断] Architecture テストの明示不足** → 3 点を設計へ明示追加:
   - `tests/Architecture/ProjectMemberPivotWritePathTest.php`（新規）: `project_members` への書き込み（`DB::table('project_members')` / `members()->attach|detach|sync*`）が許可 inventory（OrganizationMembershipService / ProjectMemberController）外の app コードに現れたら fail（deny-by-default。ScenarioWritePathInventoryTest と同型）
   - `tests/Architecture/ManageRouteAuthGuardTest.php`（新規）: `/manage/` prefix の全 route が `auth`+`verified` middleware を持つことを deny-by-default で固定
   - 旧 Settings UI 非並走の Feature 面固定: settings() props に `invitations` キーが無い・`members` 行に `email` キーが無いことを assert（Vitest と両面）

## Warning への対応

- **[施策3] project_role の DB 制約** → migration に check 制約追加（`project_role is null or project_role in ('project_admin','project_member')`。down で drop）
- **[施策4] DB::table 直叩き** → 「pivot は Eloquent モデル・イベントを持たず、belongsToMany::detach も pivot イベント非発火 = 等価。意図的に素の delete」と phpdoc 明記 + ConsoleRoleTransitionTest で契約固定
- **[施策5] FormRequest の認可責務** → class doc に「認可は Controller の Gate::authorize('manageMembers') が唯一の責務（authorize(): true は入力検証のみ担当の宣言）」を明記
- **[施策6] categoriesUrl 文字列直組み立て** → `route('projects.categories.index', $project)` に変更（施策 8 の usersUrl も `route('manage.users.index')`）
- **[施策7] 二重送信抑止の境界** → 各 submit ハンドラ冒頭の冪等ガード `if (form.processing) return;` を明示（disabled に頼らない）
- **[施策9] Show 大規模撤去の回帰リスク** → カテゴリフィルタの「存続テスト」（`manual-filter-category` の描画 + categories 選択肢）を ProjectsShow.test.ts 更新項目へ追加
- **[横断] URL 直書き** → サーバ側は route() helper へ統一。フロント側 literal path は本リポジトリの既存規約（Settings.svelte / Show.svelte とも literal、ziggy 未導入）のため踏襲（本フィーチャだけ別方式を持ち込む方が drift）
- **[横断] Admin/Users 閲覧の監査ログ** → **見送り（根拠付き）**: SecurityEventRecorder は状態変更イベント用で画面閲覧監査の基盤はテンプレに無い。閲覧可能者は manageMembers 権限者のみで、既存 settings のメンバー email 表示より露出面は縮小している。閲覧監査は org 全体要件として別途設計すべきで、本フィーチャで単発導入しない（AGENTS.md 思考原則 2「今必要なものだけ作る」）。施策 6 リスク欄に判断根拠を明記した。

## Suggestion への対応

- stale pivot 無視のテスト名明示（施策 1 テスト計画へ）/ authorize 前 404 のテスト名明示（施策 8）/ AdminMenuNav props の strict null（`?` 不使用、`string | null` 必須）/ 招待 role エラーメッセージに「画面を再読み込みしてください」/ architecture.md へ A+B 不可分の理由明文化 — すべて採用
- resolveForUpdate の transactionLevel ランタイムガード — 見送り（呼び出し経路が Service 2 箇所 + ProjectMemberPivotWritePathTest で固定されるため過剰）

## 改訂後の主要スニペット

### joinOrganization（冪等化）

```php
DB::transaction(function () use ($organization, $user, $role, $invitation): void {
    $organization->users()->syncWithoutDetaching([$user->getKey()]);
    $user->addRole($role->value, $organization->laratrust_team_id);

    $projectRole = $invitation->project_role;
    if ($projectRole instanceof ProjectRole) {
        $project = $this->defaultProjects->resolveForUpdate($organization);
        $project?->members()->syncWithoutDetaching([
            $user->getKey() => ['role' => $projectRole->value],
        ]);
    }

    $invitation->forceFill(['accepted_at' => now()])->save();
});
```

### applyConsoleRole（org ロール正規化部。修復経路込み）

```php
// org ロール正規化。attach 済みかつ Laratrust ロール未付与の異常行 (表示状態は「未割当」) は
// changeRole が「非メンバー」として拒否するため、修復経路として addRole で直接付与する
if ($target->organizationRole($organization) === null) {
    if (! $organization->users()->whereKey($target->getKey())->exists()) {
        throw ValidationException::withMessages(['role' => ['このユーザーは組織のメンバーではありません。']]);
    }
    $target->addRole($role->organizationRole()->value, $organization->laratrust_team_id);
} else {
    // 同値なら changeRole 内で早期 return = 冪等。最終 Owner 保護も継承
    $this->changeRole($organization, $target, $role->organizationRole());
}
```

### UserManagementController（異常行の可視化）

```php
foreach ($organization->users()->get() as $member) {
    // organizationRole null の異常行も非表示にせず「未割当」として可視化する
    // (derive が null を Unassigned へ丸める。applyConsoleRole の修復経路で正規化できる)
    $members[] = MemberRowData::fromUser(
        $member,
        $member->organizationRole($organization),
        $pivotRoles[$member->id] ?? null,
        $user->id,
    );
}
```

### migration（check 制約）

```php
DB::statement(
    "alter table organization_invitations add constraint organization_invitations_project_role_check "
    ."check (project_role is null or project_role in ('project_admin', 'project_member'))",
);
```

必要なら /workspace/devnotes/20260711-1009-admin-console/detailed-design.md（改訂済み全文）を直接読んで確認すること。
