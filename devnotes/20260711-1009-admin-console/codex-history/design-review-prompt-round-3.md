# Round 3: Round 2 指摘（Critical 1 / Warning 1）への対応

## [Critical] 並行受諾の原子性

提示 3 案のうち「招待行ロック」+「原子的 INSERT」を**併用**する形で `joinOrganization` を改訂した。片方だけでは閉じない race があるため:
- 招待行ロックのみ → 別招待経由の同一 user × 同一 org の並行 join（login 経路は招待 email と user email の一致を要求しないため、同一ユーザーが別 email 宛の 2 招待を並行受諾しうる）を直列化できない
- insertOrIgnore のみ → 同一招待の accepted_at TOCTOU（二重受諾判定）が残る

改訂後:

```php
private function joinOrganization(OrganizationInvitation $invitation, Organization $organization, User $user, OrganizationRole $role): void
{
    DB::transaction(function () use ($organization, $user, $role, $invitation): void {
        // 1. 招待行ロック + 受諾可能状態のロック下再検証 (並行受諾に敗れた側は冪等 no-op)
        /** @var OrganizationInvitation $locked */
        $locked = OrganizationInvitation::query()->whereKey($invitation->id)->lockForUpdate()->firstOrFail();
        if ($locked->isAccepted() || $locked->isRevoked()) {
            return;
        }

        // 2. org 参加の原子的 INSERT ((organization_id, user_id) UNIQUE を利用)。
        //    0 行 = 別経路で join 済み (role/pivot は変更しない。非正規状態が残る場合も
        //    「未割当」として可視化され管理画面から修復できる)
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
```

- insertOrIgnore の値はすべてサーバ側 relation 解決済みモデル由来（payload 不信の保護キー規約に整合。「relation・保護キー明示代入の規約に沿った専用処理に閉じる」の条件を満たす）。organization_user は timestamps のみの pivot で `(organization_id, user_id)` UNIQUE を migration（2026_06_11_074000）で確認済み。
- テスト計画を更新: (a) 受諾済み招待での到達が no-op（ロック下再検証契約）、(b) 既 attach 状態の受諾が unique 違反にならず role/pivot 不変（affected-rows 分岐契約）。真の並行実行は並列 DB テストで flaky のため逐次で契約を固定し、INSERT の原子性は DB 保証（ON CONFLICT DO NOTHING）に委ねる旨を明記。

## [Warning] derive() の評価順

提示コードをそのまま採用（match 先頭に `$orgRole === null => self::Unassigned`）。phpdoc に「null 判定は project pivot 判定より必ず先（org ロールなし + stale pivot の Editor/Shooter 誤表示防止）」を明記し、ユニットテストの null×pivot 有無ケースが順序を固定する。

判定を依頼する。必要なら /workspace/devnotes/20260711-1009-admin-console/detailed-design.md（改訂済み全文）を直接読むこと。
