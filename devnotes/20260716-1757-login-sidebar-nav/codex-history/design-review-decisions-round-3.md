# 対応マトリクス: design-review Round 3（CHANGES_REQUESTED → 残 S1/S6 各 1）

## S1 [Warning] ゲスト時の main 左空白
- 判断: 対応する
- 対応: `--app-sidebar-w` を `showAccountNav ? (sidebarOpen ? 256px : 64px) : 0px` にし、ゲスト時
  （サイドバー非描画）は 0px にして左マージン空白を残さない（認証時のみオフセット付与）。

## S6 [Warning] SidebarUserMenu 二枚シェルの testId 重複
- 判断: 対応する
- 対応: desktop へ `settingsTestId="nav-settings"` / `logoutTestId="logout-button"`、mobile へ
  `settingsTestId="nav-settings-mobile"` / `logoutTestId="logout-button-mobile"` /
  `detailsToggleTestId="details-toggle-mobile"` を渡す（aigenba と同パターン）。テストは各シェルを
  明示して検証（同一 testId の DOM 重複を回避）。

## S6 [Suggestion] NotificationBell 純表示テストの位置づけ
- 判断: 対応する
- 対応: 「現在の fetch/router 非発火を固定する回帰テスト」と明記（将来のあらゆる副作用を禁止する
  architecture テストではない）。

## S3 [Suggestion] 二枚シェルの testId 別値前提の明記
- 判断: 対応する（S6 と同一対応で担保）
- 対応: SidebarUserMenu の testId は各シェルで別値を渡す前提を設計に明記済み。

## APPROVE 済み（S2/S4/S5/S7）
- 変更なし。
