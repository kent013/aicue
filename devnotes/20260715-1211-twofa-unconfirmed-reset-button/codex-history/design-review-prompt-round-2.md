## Round 2: 対応内容

Round 1 の指摘への対応です。全体判定 CHANGES_REQUESTED の主因だった T1 の [Critical] を
含め、Warning もすべて反映しました。

### [Critical] T1 の脆い件数アサーション / 共有 fixture 変更 → 対応済み
- 共有 `membersFixture` は変更しない。pending テストは既存「admin 閲覧者」テストと同じく
  props にローカル members 配列を渡して自己完結で描画する。
- 検証は reset ボタンの id 付き testid（`reset-two-factor-{id}` = 行スコープ相当）で
  presence/absence を確認。バッジ非表示は対象行を `closest("li")` で取得し `within(row)` に
  スコープ。件数アサーション（`toHaveLength`）は撤廃。
- viewer=owner (id=1 isSelf)・対象 role=editor を arrange で明示（role 由来と 2FA 由来の
  失敗を分離）。

改訂後 T1（抜粋）:
```ts
it("2FA 未確認 (pending) メンバーには解除ボタン・2FA バッジを出さない (owner 閲覧)", () => {
    render(Users, { props: { ...baseProps, members: [
        { id: 1, name: "オーナー 太郎", email: "owner@example.com", roleState: "owner",
          roleLabel: "管理者（オーナー）", twoFactorStatus: "enabled", isSelf: true },
        { id: 2, name: "確定 花子", email: "enabled@example.com", roleState: "editor",
          roleLabel: "編集者", twoFactorStatus: "enabled", isSelf: false },
        { id: 5, name: "設定中 五郎", email: "pending@example.com", roleState: "editor",
          roleLabel: "編集者", twoFactorStatus: "pending", isSelf: false },
    ] satisfies MemberRow[] } });

    expect(screen.getByTestId("reset-two-factor-2")).toBeInTheDocument();
    expect(screen.queryByTestId("reset-two-factor-5")).toBeNull();

    const pendingRow = screen.getByText("pending@example.com").closest("li");
    expect(pendingRow).not.toBeNull();
    expect(within(pendingRow as HTMLElement).queryByText("2FA")).toBeNull();
    const enabledRow = screen.getByText("enabled@example.com").closest("li");
    expect(within(enabledRow as HTMLElement).getByText("2FA")).toBeInTheDocument();
});
```

### [Warning] T2 に「拒否時は通知・監査を発火しない」を仕様固定 → 対応済み
改訂後 T2:
```php
test('2FA 未確認 (pending) のメンバーへのリセットも明示拒否 (validation error / 通知・監査なし)', function (): void {
    Notification::fake();
    [$organization, $owner] = tfeCreateOrganization();
    $member = tfeAddMember($organization, 'pending');

    $this->actingAs($owner)
        ->withSession(['recent_auth_at' => time()])
        ->delete(tfeResetUrl($organization, $member), ['reason' => '未確認 secret への誤操作'])
        ->assertSessionHasErrors(['two_factor']);

    expect($member->fresh()->two_factor_secret)->not->toBeNull();
    expect($member->fresh()->two_factor_confirmed_at)->toBeNull();

    Notification::assertNothingSentTo($member);
    expect(SecurityAuditEvent::query()->where('event_type', 'org_member_two_factor_reset')->count())
        ->toBe(0);
});
```

### [Warning] pending を管理者がクリア不可になる仕様変更点の運用周知 → 対応済み
施策 2 の運用ノートに「pending メンバーの 2FA は管理画面から解除できない。本人が設定画面から
再生成して解消する」旨を追記（実装時にリリースノート/運用手順へ 1 行反映）。

### [Warning] T1 の viewer=owner 前提の明示 → 対応済み
上記のとおりローカル fixture で viewer=owner を明示配置。

施策 2 のサーバ側文言（`two_factor` / 「このメンバーは 2 段階認証を設定していません。」）は
Round 1 Suggestion どおり据え置きです（pending も 2FA 未有効のため状態不正が伝わる。403 とは
別経路のため運用上も区別可能）。

以上の対応で、残る指摘の解消可否と全体判定を再評価してください。
