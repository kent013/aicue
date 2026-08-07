前提: コマンド実行は禁止条件どおり行っていません。提供 diff だけを対象にレビューしています。

**指摘**
- [Critical] `database/migrations/...drop_project_role...` / `database/factories/OrganizationInvitationFactory.php` がレビュー差分に含まれていません。施策 7 は「`project_role` を列ごと撤去」「`editorInvitation()` / `shooterInvitation()` を削除」までが必須です。アプリコードが読み書きしなくなっていても、schema と factory に旧概念が残るなら「役割付き招待の撤去」は未完了です。もし実装済みなら、次回レビュー対象に database diff を含めてください。
- [Warning] `tests/Feature/Organization/InvitationTest.php`: 設計で明記された `role=organization_owner` の 422 テストが見当たりません。実装は `Rule::enum(OrganizationRole::class)->except([Owner])` と Service 側 `Assert::notSame` で守っていますが、Owner 昇格は transferOwnership のみという権限不変条件なので回帰テストを追加してください。
- [Warning] `tests/Architecture/InvitationResolutionInventoryTest.php` / `app/Enums/Security/InvitationResolutionScope.php`: `LockedRowReload` の追加自体は妥当です。ただし gate は「分類された」ことだけを見ており、`whereKey($invitation->id)->lockForUpdate()` の再読取であることを機械検証していません。この case は外部入力 id の直引き逃げ道になりやすいので、`LockedRowReload` 分類には `lockForUpdate` と `$invitation->id` 由来を最低限チェックする body assertion を足すのが安全です。

**ファイル別判定**
- `app/DataTransferObjects/Admin/InvitationRowData.php`: OK。管理者視点 DTO を org role 表示へ切り替えており、設計どおりです。
- `app/DataTransferObjects/Invitations/PendingInvitationForUserDto.php`: OK。開示面は 4 key に閉じており、email/token を出していません。
- `app/Models/OrganizationInvitation.php`: OK。`scopeActivePendingForEmail` は active + `whereBlind` + organization existence で設計と一致します。
- `app/Services/Organization/OrganizationMembershipService.php`: OK。pending query の単一起点、`joinOrganization(): bool`、false 消費、`project_role` 撤去はいずれも設計どおりです。
- `app/Http/Controllers/Organizations/AcceptInvitationInAppController.php`: OK。implicit binding を避け、業務不能を 404 に畳んでいます。
- `routes/web.php`: OK。named limiter、auth/verified、手動解決 route として妥当です。
- `app/Providers/AppServiceProvider.php`: OK。`RateLimiterKeys::actorOrIp()` への逸脱は現行規約に寄せた改善です。
- `app/Http/Middleware/HandleInertiaRequests.php` / `resources/js/lib/shared-props.ts`: OK。`invitationInbox` への変更は `Admin/Users` の page prop 衝突回避として正当です。
- `resources/js/components/features/invitations/PendingInvitationList.svelte`: OK。初期 disabled なし、二重送信防止のみ loading、Lucide 使用で DESIGN/Atomic と整合しています。
- `resources/js/components/molecules/PendingInvitationsNotice.svelte`: OK。molecule として妥当です。
- `resources/js/pages/Admin/Users.svelte`: OK。招待 2 値とメンバー変更 3 値を分離できています。
- `docs/architecture.md` / `docs/template-divergence.md`: OK。非対称・deploy 順序・撤去理由が記録されています。
- `tests/*`: 概ね良好ですが、上記 Owner 422 と `LockedRowReload` の構造検査が不足です。

全体判定: **CHANGES_REQUESTED**