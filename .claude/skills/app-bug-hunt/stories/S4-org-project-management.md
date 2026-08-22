---
id: S4
title: 組織・プロジェクト・カテゴリ・ユーザー管理
surface: org_project_admin
lane: parallel_browser
priority: P2
applicability: applicable
depends_on: []
reseed_before: false
accounts: [owner, admin]
setup: []
covers_screens: [manage.users.index, organizations.api-keys.index, organizations.api-keys.sessions.index, organizations.create, organizations.onboarding.cli, organizations.onboarding.mcp, organizations.settings, projects.categories.index, projects.create, projects.edit, projects.index]
covers_operations: [organizations.api-keys.revoke, organizations.api-keys.sessions.revoke, organizations.api-keys.store, organizations.store, organizations.switch, organizations.transfer-ownership, organizations.two-factor-requirement.update, organizations.update, projects.categories.destroy, projects.categories.reorder, projects.categories.store, projects.categories.update, projects.destroy, projects.items.destroy, projects.items.store, projects.items.update, projects.members.destroy, projects.members.store, projects.store, projects.update]
covers_capabilities: [AK-01, AK-02, AK-03, MEM-07, ORG-01, ORG-02, ORG-03, ORG-04, ORG-05, PROJ-01, PROJ-02, PROJ-03, PROJ-04]
---

# S4: 組織・プロジェクト・カテゴリ・ユーザー管理

## 目的
組織オーナー/管理者(project_admin)による組織/プロジェクトの作成・編集・切替・削除、カテゴリ管理(専用画面)、管理者向けユーザー管理が反映され矛盾しないか。管理者専用機能が非管理者に漏れないか。

## 手順
1. `organizations.create` → `organizations.store` で組織作成、`organizations.switch` で切替(ヘッダーの組織スイッチャーで往復できるか)、`organizations.settings` で設定確認。オーナー移譲 `organizations.transfer-ownership`(移譲先 select で空値エラー後に有効値を選ぶとエラーが消えるか=stale invalid 解消, T044)、2FA 必須化 `organizations.two-factor-requirement.update`。
2. `projects.index` → `projects.create` → `projects.store`、`projects.edit` → `projects.update`、`projects.destroy`。プロジェクトメンバー追加/削除 `projects.members.store`/`destroy`。
3. カテゴリ管理(専用画面 `projects.categories.index`): 追加 `projects.categories.store`(名50字・同名不可・空値不可 → 押下時エラー)、名称編集 `update`、削除 `destroy`(そのカテゴリの動画が「未分類」になる)、並べ替え▲▼ `reorder`(動画一覧の並びに反映)。
4. 管理者ユーザー管理(`manage.users.index`): メンバー一覧・**招待によるユーザー追加**(パスワード直接発行ではなくメール招待一本化 = docs/template-divergence.md D8。招待は S2 の `organizations.invitations.store`)・ロール変更(`organizations.members.update`、編集者/撮影者はプロジェクト未作成時は 422+エラーで拒否)・除名(`organizations.members.destroy`、確認ダイアログ)・2FA リセット(`organizations.members.two-factor.reset`)。メール形式/空値/重複は押下時エラー。タブレット幅で名前が過剰 truncate されないか(T042)。
5. API キー/オンボーディング: `organizations.api-keys.index`/`store`/`revoke`、`organizations.api-keys.sessions.*`、`organizations.onboarding.cli`/`mcp`。
6. サンプルリソース Item(テンプレ見本): `projects.items.store`/`update`/`destroy`(存在する場合)。

## 逸脱アイデア (--deviate 時)
- 撮影者(project_member)/一般ユーザーで `manage.users.index` やカテゴリ管理に直アクセス → 403・サイドバー非表示になるか。
- カテゴリ reorder を二重送信/古い集合で送る → sort_order が壊れないか(Project 行ロックで直列化)。
- 組織切替直後に別組織の project id を叩く → 認可前 404 か。
- ユーザー削除・組織削除の確認ダイアログをスキップ(直 POST)→ 保護されるか。最後のオーナー削除で組織が孤児化しないか。
