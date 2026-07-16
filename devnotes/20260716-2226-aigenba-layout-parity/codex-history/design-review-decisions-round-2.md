# 対応マトリクス: design-review Round 2（REQUEST_CHANGES; S4 Critical×2）

## S4 [Critical] 不要 prop を BE から渡し続ける = 後方互換並走
- 対応: 「完全 FE のみ」を撤回。同一 PR で OrganizationController@settings / CategoryController@index /
  Admin/UserManagementController@index の Inertia::render から不要な usersUrl/categoriesUrl を除去
  (Gate 評価ごと削除。認可ロジック・route・他 prop は不変)。当該 prop は AdminMenuNav 専用と確認済み。

## S4 [Critical] 「専用テストがあれば削除」= 既存テスト削除禁止に抵触
- 対応: AdminMenuNav 専用テストは存在せず、AdminUsers.test/AdminCategories.test が admin-nav testid を参照。
  これらを削除でなく後継契約へ更新(標準外枠描画・二次メニュー無し)。coverage は deprecated-imports テスト +
  Projects/Show リンクテスト + Admin 標準外枠テストへ相移し(消失させない)。

## S4 [Warning] update ⊆ viewAny を Policy 実装で確認
- 対応(コード確認済): CategoryPolicy::viewAny(user, project) は projectPolicy->update(user, project) を返す =
  **viewAny ≡ update(完全一致)**。canManage(=update) でリンクを出すのは厳密に正しく 403 皆無。Feature テストで
  「canManage ユーザーが categories に到達可」を回帰固定。

## S1 [Suggestion] maxWidth prop 不受理
- 対応: pnpm typecheck で保証と明記(ランタイム単体では不可)。

## S2 [Warning] app-main contract / data-testid 維持
- 対応: AppLayout.test で app-main ラッパに padding utility が付かないこと + data-testid="app-main" 維持を担保と明記。

## S3 [Warning] 施策一覧の旧名
- 対応: 施策一覧を page-content-usage→page-shell-structure リネーム + deprecated-imports 新設に修正。

## S3 [Suggestion] padding 検査の識別子も escapeRegExp
- 対応: escapeRegExp(PC) を通すよう明記。
