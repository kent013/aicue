# 対応マトリクス: conceptual-review Round 1 (item3)

## [Critical] 新規 CTA のテスト計画欠落
- 判断: 対応
- 対応内容: 概念設計に明示的テスト計画を追加。vitest (AdminUsers.test.ts):
  (1) !hasDefaultProject で create-project-link 表示 (2) href=/projects/create
  (3) hasDefaultProject=true で非表示 (4) 既存 no-project-note 文言維持。

## [Warning] 「manageMembers = projects.create 権限」の暗黙依存
- 判断: 対応 (option b = 純フロント維持 + 不変条件テスト)
- 根拠: 実は両者は**完全同一式**: OrganizationPolicy::manageMembers も ProjectPolicy::create も
  `organizationRole($organization)?->canManage()` を呼ぶ (canManage = role !== Member)。
  よって現状は厳密に同値。ただし将来の乖離で「CTA は見えるが遷移先で 403」の破綻を防ぐため、
  この同値を Feature テストで固定する。
- 対応内容: UI は純フロント (href 直書き) を維持。加えて backend 不変条件テストを追加:
  owner/admin/member について `Gate::allows('create',[Project::class,$org])` ==
  `Gate::allows('manageMembers',$org)` (owner/admin=true, member=false) を固定。
  projects.create route 自身も Gate::authorize('create') でサーバ認可するためリンクは
  認可の代替ではない旨も明記。

## [Suggestion] CTA 文言をより文脈接続的に
- 判断: 見送り (既存 CTA との一貫性優先)
- 根拠: 既存の projects.create CTA は全て「プロジェクトを作成」(Projects/Index, Dashboard)。
  文言を揃えることでアプリ全体の一貫性を保つ。注記文が直上で文脈を与えるため迷いは少ない。
