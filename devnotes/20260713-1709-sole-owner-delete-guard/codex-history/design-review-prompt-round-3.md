Round 2 の指摘への対応です。全体判定を再度お願いします。

## 施策2 [Critical] deleteAccount ロック順 → 対応

ご提案どおり **User lock → 所属再取得 → Organization lock → 再評価** の厳密順序に修正しました。ロック呼び出しを 2 段に分割します。

```php
public function deleteAccount(User $user): void
{
    DB::transaction(function () use ($user): void {
        // 1. User 行を最初にロック
        $this->lockForMembershipWrite([(int) $user->getKey()], []);

        // 2. user ロック下で所属を列挙 → Organization 行をロック
        $organizationIds = $user->organizations()
            ->orderBy('organizations.id')
            ->pluck('organizations.id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();
        $this->lockForMembershipWrite([], $organizationIds);

        // 3. fresh 再取得 → 述語再評価
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
`lockForMembershipWrite` は空配列を受けたカテゴリをスキップする (users のみ / orgs のみのロックが可能)。メンバー追加 (`joinOrganization`) / 移譲 (`transferOwnership`) も user 行をロックするため、user ロック下での所属列挙は安定し、未列挙組織への並行 Owner 付与と直列化される。グローバルなロック順序は「users(昇順) → organizations(昇順)」を維持 (2 段呼び出しでもこの順序を崩さない)。

## 施策6 [Warning] SettingsPageProps の errors 継承衝突 → 確認の上、衝突なし

`resources/js/lib/shared-props.ts` の `SharedProps` を確認しました。フィールドは `appName / auth / organizations / currentOrganization / flash / notifications / title` のみで **`errors` は存在しません** (Inertia が errors を別途注入)。よって `SettingsPageProps extends SharedProps` に `errors?: Record<string, string | string[]>` を追加しても継承衝突は起きず、`Omit` は不要です。設計にこの確認結果をコメントで明記しました。

以上で Round 2 の指摘に対応しました。判定をお願いします。
