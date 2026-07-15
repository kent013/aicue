Round 1 の Critical/Warning に対応しました。再レビューし全体判定を返してください。

## [Critical] CTA のテスト計画欠落 → 対応
明示的テスト計画を追加。vitest (AdminUsers.test.ts): (1) !hasDefaultProject で create-project-link 表示 (2) href=/projects/create (3) hasDefaultProject=true で非表示 (4) 既存 no-project-note 文言維持。

## [Warning] 「manageMembers = projects.create 権限」の暗黙依存 → 対応 (option b)
実は両者は完全同一式: OrganizationPolicy::manageMembers も ProjectPolicy::create も `organizationRole($organization)?->canManage()` を呼ぶ (canManage = role !== Member)。よって現状は厳密に同値。UI は純フロント (href 直書き) を維持し、加えて backend 不変条件テストでこの同値を固定する: owner/admin/member について `Gate::allows('create',[Project::class,$org])` == `Gate::allows('manageMembers',$org)` (owner/admin=true, member=false)。将来どちらかの権限が乖離したらこのテストが fail し「CTA は見えるが遷移先 403」を検出する。projects.create route 自身も Gate::authorize('create') でサーバ認可。

## [Suggestion] CTA 文言をより文脈接続的に → 見送り
既存 projects.create CTA は全て「プロジェクトを作成」(Projects/Index, Dashboard)。文言を揃えアプリ一貫性を優先。注記文が直上で文脈を与える。

これで残件は解消と考えます。APPROVED 可否を判定してください。
