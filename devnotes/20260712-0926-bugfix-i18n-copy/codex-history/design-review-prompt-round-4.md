# design-review Round 4

Round 3 の残件 (Warning 1 件) に対応しました。再レビューし、全体判定を出してください。

## 対応マトリクス

- [Warning] 新規テストの配置ディレクトリ: **対応**。設計書全体で配置先を `tests/Feature/Organizations/OrganizationSettingsCopyTest.php`（複数形）に統一した。補足: リポジトリには単数形 `tests/Feature/Organization/`（OrganizationBoundaryNotFoundTest / MembershipTest 等）と複数形 `tests/Feature/Organizations/`（ApiKeyPageTest / TwoFactorEnforcementTest 等）が併存しているが、本設計が同時に触る `TwoFactorEnforcementTest` と同じ複数形側に揃えた。

変更はこのパス統一のみで、施策 1〜4 とテスト内容・実装手順に変更はありません。
