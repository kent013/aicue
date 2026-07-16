## Round 4: Round 3 指摘への対応（残 S1/S6 各 1）

Round 3 の 2 点に対応しました。全体判定の再評価をお願いします。

### S1 [Warning] ゲスト時の main 左空白
- 対応: `--app-sidebar-w` を `showAccountNav ? (sidebarOpen ? 256px : 64px) : 0px` にし、ゲスト時は 0px
  （左マージン空白を残さない。認証時のみオフセット付与）。

### S6 [Warning] SidebarUserMenu 二枚シェルの testId 重複
- 対応: desktop へ `nav-settings` / `logout-button`、mobile へ `nav-settings-mobile` /
  `logout-button-mobile` / `details-toggle-mobile` を渡す（aigenba と同パターン）。テストは各シェルを
  明示検証。

### S6 [Suggestion] NotificationBell 純表示テストの位置づけ
- 対応: 「現在の fetch/router 非発火を固定する回帰テスト」と明記（architecture テストではない）。

（該当箇所のみ抜粋）

- S1 main:
  「`--app-sidebar-w` は `showAccountNav ? (sidebarOpen ? 256px : 64px) : 0px`。ゲスト時（サイドバー
  非描画）は 0px にして左空白を残さない。」

- S1 下部メニュー testId 分離:
  「desktop へは `settingsTestId="nav-settings"` / `logoutTestId="logout-button"`、mobile へは
  `settingsTestId="nav-settings-mobile"` / `logoutTestId="logout-button-mobile"` /
  `detailsToggleTestId="details-toggle-mobile"` を渡す。テストは各シェルを明示検証。」

- S6 NotificationBell:
  「描画しても fetch/router を発火しないことを固定（現在の非発火を固定する回帰テスト。将来のあらゆる
  副作用を禁止する architecture テストではない）。user menu の testId は各シェル別値。」
