Round 1 の指摘への対応です。対応マトリクスと修正後の該当箇所を提示します。全体判定を再度お願いします。

## 対応マトリクス (Round 1)

- 施策2 [Critical] fresh() null フォールバック → **対応**: `$freshUser = $user->fresh(); Assert::isInstanceOf($freshUser, User::class);` で即中断、以降 `$freshUser` を使用。null フォールバック撤廃。
- 施策2 [Warning] pluck list<int> → **対応**: `->pluck('organizations.id')->map(fn ($id): int => (int) $id)->values()->all()`。
- 施策3 [Critical] changeRole/removeMember の TOCTOU → **対応**: 事前チェックを撤廃し、tx 内先頭で `lockForMembershipWrite` → `fresh()` 再取得 → 判定 → 変更へ全面再構成 (契約不変・評価位置のみロック下へ)。
- 施策3 [Warning] transferOwnership の stale org → **対応**: ロック後に `Organization::query()->whereKey()->firstOrFail()` + `User::query()->whereKey()->firstOrFail()` で fresh 同士判定。
- 施策5 [Warning] closure 肥大化 → **対応**: `GET /settings` を新規 `Settings\ProfileController@index` へ移設 (DI/型安全)。
- 施策6 [Critical] 多重キャスト → **対応**: ページ専用 `SettingsPageProps extends SharedProps` を定義し `page.props` を単一キャスト。
- 施策6 [Warning] errors.account 配列 → **対応**: `Array.isArray(err) ? err[0] : err` で正規化。
- 施策6 [Warning] ダイアログ挙動 → **対応**: `router.delete` の `onError` で `deleteDialogOpen = false`、Alert を DangerZone に表示。
- 施策7 [Critical] lock 呼び出し未保証 → **対応**: メソッドソースを切り出し `directLock` 群に `lockForMembershipWrite(`、`delegatedToLocked` 群に `joinOrganization(` の文字列存在を検査。
- 施策7 [Warning] 間接依存分類 → **対応**: `directLock`/`delegatedToLocked`/`exempt` の 3 配列に分離。
- 施策8 [Critical] ロック後再評価の回帰テスト → **対応**: 実 DB 遷移テスト 2 本追加 (ブロック→2人目Owner追加後は削除可 / 2Owner→片方降格後は削除ブロック)。
- 施策8 [Warning] addRole 直叩き → **対応**: `attachOrganizationMember($org, OrganizationRole::Owner)` (既存ヘルパ) で 2 人目 Owner を生成。
- 施策8 [Suggestion] JS toBeEnabled + 同時表示 → **対応**。

## 修正後の該当コード

### 施策2 deleteAccount (fresh 非 null / list<int>)
```php
public function deleteAccount(User $user): void
{
    DB::transaction(function () use ($user): void {
        $organizationIds = $user->organizations()->orderBy('organizations.id')
            ->pluck('organizations.id')->map(fn ($id): int => (int) $id)->values()->all();
        $this->lockForMembershipWrite([(int) $user->getKey()], $organizationIds);

        $freshUser = $user->fresh();
        Assert::isInstanceOf($freshUser, User::class);
        $blockers = $this->organizationsBlockingDeletion($freshUser);
        if ($blockers->isNotEmpty()) {
            $names = $blockers->pluck('name')->implode('、');
            throw ValidationException::withMessages(['account' => ["次の組織のオーナーであるため削除できません。先にオーナーを移譲してください: {$names}"]]);
        }
        $this->recorder->record(SecurityEventType::AccountDeleted, $freshUser);
        $freshUser->delete();
    });
}
```

### 施策3 changeRole / removeMember (lock→fresh→判定→変更)
```php
public function changeRole(Organization $organization, User $target, OrganizationRole $newRole): void
{
    DB::transaction(function () use ($organization, $target, $newRole): void {
        $this->lockForMembershipWrite([(int) $target->getKey()], [$organization->id]);
        $freshTarget = $target->fresh();
        Assert::isInstanceOf($freshTarget, User::class);
        $currentRole = $freshTarget->organizationRole($organization);
        if ($currentRole === null) { throw ValidationException::withMessages(['role' => ['このユーザーは組織のメンバーではありません。']]); }
        if ($currentRole === $newRole) { return; }
        if ($currentRole === OrganizationRole::Owner && ! $this->hasAnotherOwner($organization, $freshTarget)) {
            throw ValidationException::withMessages(['role' => ['最後のオーナーは降格できません。先にオーナーを移譲してください。']]);
        }
        $freshTarget->removeRole($currentRole->value, $organization->laratrust_team_id);
        $freshTarget->addRole($newRole->value, $organization->laratrust_team_id);
    });
}

public function removeMember(Organization $organization, User $target): void
{
    DB::transaction(function () use ($organization, $target): void {
        $this->lockForMembershipWrite([(int) $target->getKey()], [$organization->id]);
        if (! $organization->users()->whereKey($target->getKey())->exists()) {
            throw ValidationException::withMessages(['member' => ['このユーザーは組織のメンバーではありません。']]);
        }
        $freshTarget = $target->fresh();
        Assert::isInstanceOf($freshTarget, User::class);
        $role = $freshTarget->organizationRole($organization);
        if ($role === OrganizationRole::Owner) {
            throw ValidationException::withMessages(['member' => ['オーナーは削除できません。先にオーナーを移譲してください。']]);
        }
        $organization->users()->detach($freshTarget->getKey());
        if ($role !== null) { $freshTarget->removeRole($role->value, $organization->laratrust_team_id); }
        $this->detachProjectMemberships($organization, $freshTarget);
        if ($freshTarget->current_organization_id === $organization->id) {
            $freshTarget->forceFill(['current_organization_id' => null])->save();
        }
    });
}
```
`joinOrganization` / `applyConsoleRole` は既存 tx 先頭に `$this->lockForMembershipWrite([(int) $user->getKey()], [$organization->id]);` を挿入 (本体不変)。

### 施策3 transferOwnership (fresh org/users)
```php
DB::transaction(function () use ($organization, $from, $to): void {
    $this->lockForMembershipWrite([(int) $from->getKey(), (int) $to->getKey()], [$organization->id]);
    $freshOrg = Organization::query()->whereKey($organization->getKey())->firstOrFail();
    $freshFrom = User::query()->whereKey($from->getKey())->firstOrFail();
    $freshTo = User::query()->whereKey($to->getKey())->firstOrFail();
    $memberUserIds = DB::table('organization_user')->where('organization_id', $freshOrg->id)
        ->whereIn('user_id', [$freshFrom->getKey(), $freshTo->getKey()])->pluck('user_id')->all();
    if (count($memberUserIds) < 2) { throw ValidationException::withMessages(['user_id' => ['移譲先は組織のメンバーである必要があります。']]); }
    if ($freshFrom->organizationRole($freshOrg) !== OrganizationRole::Owner) { throw ValidationException::withMessages(['user_id' => ['オーナーのみ移譲できます。']]); }
    // role 入れ替え (freshFrom→Admin / freshTo→Owner)
});
```

### 施策5 ProfileController@index (closure から移設)
```php
class ProfileController extends Controller {
    public function index(Request $request, OrganizationMembershipService $membership): Response {
        $user = $request->user();
        Assert::isInstanceOf($user, User::class);
        return Inertia::render('Settings/Index', [
            'soleOwnedOrganizations' => $membership->organizationsBlockingDeletion($user)
                ->map(fn (Organization $o): array => ['name' => $o->name, 'slug' => $o->slug])->values()->all(),
        ]);
    }
}
// routes/web.php: Route::get('/settings', [ProfileController::class, 'index'])->name('settings');
```

### 施策6 Svelte (単一キャスト型 + errors 正規化 + onError)
```svelte
interface SoleOwnedOrganization { name: string; slug: string; }
interface SettingsPageProps extends SharedProps {
    soleOwnedOrganizations?: SoleOwnedOrganization[];
    errors?: Record<string, string | string[]>;
}
const props = $derived(page.props as unknown as SettingsPageProps);
const soleOwnedOrganizations = $derived(props.soleOwnedOrganizations ?? []);
const accountError = $derived.by((): string | null => {
    const err = props.errors?.account;
    if (err === undefined) return null;
    return Array.isArray(err) ? (err[0] ?? null) : err;
});
// Alert は atom: <Alert type="warning" .../> <Alert type="danger">{accountError}</Alert>
// router.delete(..., { onError: () => { deleteDialogOpen = false; } })
```

### 施策7 drift-guard (lock 呼び出し検査)
```php
$directLock = ['applyConsoleRole', 'changeRole', 'removeMember', 'transferOwnership', 'deleteAccount'];
$delegatedToLocked = ['acceptInvitation', 'acceptInvitationIfValid'];
$exempt = ['inviteMember', 'revokeInvitation'];
// 1. 未分類検出: array_diff(ownPublicMethods, directLock+delegatedToLocked+exempt) === []
// 2. directLock 各メソッド本文に 'lockForMembershipWrite(' が存在
// 3. delegatedToLocked 各メソッド本文に 'joinOrganization(' が存在
```

### 施策8 回帰テスト追加 (抜粋)
```php
test('ブロック→2人目オーナー追加後は削除できる', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    attachOrganizationMember($organization, OrganizationRole::Admin);
    expect(app(OrganizationMembershipService::class)->organizationsBlockingDeletion($owner))->toHaveCount(1);
    attachOrganizationMember($organization, OrganizationRole::Owner);
    $this->actingAs($owner)->withSession(['recent_auth_at' => time()])->delete('/settings/account')->assertRedirect('/');
    expect(User::query()->whereKey($owner->id)->exists())->toBeFalse();
});
test('2オーナー→片方降格後は削除ブロック', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $second = attachOrganizationMember($organization, OrganizationRole::Owner);
    attachOrganizationMember($organization, OrganizationRole::Member);
    app(OrganizationMembershipService::class)->changeRole($organization, $second, OrganizationRole::Admin);
    $this->actingAs($owner)->withSession(['recent_auth_at' => time()])->from('/settings')->delete('/settings/account')->assertSessionHasErrors('account');
    expect(User::query()->whereKey($owner->id)->exists())->toBeTrue();
});
```

以上で Round 1 の全 Critical / Warning に対応しました。判定をお願いします。
