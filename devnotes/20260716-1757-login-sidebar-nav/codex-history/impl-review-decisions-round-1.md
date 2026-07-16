# 対応マトリクス: impl-review Round 1（APPROVED）

全体判定 APPROVED（Critical/Warning なし、Suggestion のみ）。ノーリスクで有益な 2 件のみ適用、残りは見送り。

## [Suggestion] AppLayout.toggleSidebar の意図明確化
- 判断: 対応する（可読性向上・ノーリスク）
- 対応: `const next = !sidebarOpen; sidebarOpen = next; localStorage...; if (!next) closeUserMenu();` に書き換え。

## [Suggestion] AppLayout.test.ts のコメント内ファイル名不整合
- 判断: 対応する（追跡性向上・ノーリスク）
- 対応: コメントの `tests/Feature/SidebarVisibilityContractTest.php` を実ファイル
  `tests/Feature/Organizations/OrganizationNavSharedPropsTest.php` に修正。

## [Suggestion] nav-item testid の encodeURIComponent 化
- 判断: 見送る
- 根拠: Codex も「既存テスト資産との兼ね合いで今すぐ必須ではない」と明記。現行 testid（`nav-item-/manage/users`）で
  テストは安定通過しており、変更は churn のみ。

## [Suggestion] SidebarUserMenu の ARIA menu パターン厳密化（menuitem）
- 判断: 見送る
- 根拠: Codex も「現状維持（実運用優先）」を許容。a11y 改善は別施策で扱う。

## [Suggestion] 既存 Feature ケース名に (sidebar visibility contract) を統一付与
- 判断: 見送る（docblock で契約を明示済み）
- 根拠: ファイル冒頭 docblock に「sidebar visibility contract」を明記済みで検索可能。全ケース改名は churn。
