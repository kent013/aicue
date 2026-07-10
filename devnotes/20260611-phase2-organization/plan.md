# Phase 2: 組織・Team・Project(M3)実装メモ

> ドナー: aigenba(オーナー移譲の行ロックは spirux)。★Default Team パターン
> (docs 化済み仕様: devnotes/20260611-template-extraction/06)の実装フェーズ。

## スコープ

1. Laratrust(teams=true, **strict_check=true**=Q6)+ Role/Permission シーダー
   (organization_owner / organization_admin / organization_member、project_admin / project_member=Q2)
2. スキーマ: organizations(slug unique, laratrust_team_id unique+restrict, SoftDeletes)
   → custom_teams(organization_id cascade, **is_default + 部分 unique**)
   → projects(custom_team_id cascade)+ project_members pivot
3. OrganizationProvisioningService:
   - provisionPersonalOrganization(登録 transaction 内から呼ぶ。冪等)
   - 組織作成のあらゆる経路で Default Team を自動生成(06 の不変条件)
4. メンバー管理: 招待(**back+flash で完結** — フィードバック #4)、ロール変更、削除、
   オーナー移譲(行ロック)
5. 組織切替(users.current_organization_id)
6. teams_visible=false の UI(組織→プロジェクト 2 階層)+ Team 管理ルートの条件登録
7. Policies(getOrganizationRole helper、owner/admin 判定)
8. 画面: Organizations(Create/Settings)/ Members(Index)/ Projects(Index/Create/Show/Edit)
9. テスト: 06 記載の不変条件 4 種 + 招待/移譲/ロール/切替 + 権限判定(strict_check=true で
   team 明示が必要なことの確認)

## 不変条件(06 より)

- どの Organization にも Default Team がちょうど 1 つ(部分 unique index)
- Default Team は削除不可
- teams_visible=false の間、プロジェクトは Default Team に強制所属
- custom_team_id / organization_id / laratrust_team_id は mass-assignment 不可
