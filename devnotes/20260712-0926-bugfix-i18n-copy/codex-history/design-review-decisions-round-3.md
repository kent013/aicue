# 対応マトリクス: design-review Round 3

## [Warning] 施策 5: 新規テストの配置ディレクトリ (`tests/Feature/Organization/` 単数形)
- 判断: 対応する
- 根拠: リポジトリには単数形 `tests/Feature/Organization/` (Boundary/Membership 等) と
  複数形 `tests/Feature/Organizations/` (ApiKey/TwoFactorEnforcement 等) が併存しているが、
  本設計が同時に触る `TwoFactorEnforcementTest` は複数形側にあり、揃える方が一貫する。
- 対応内容: 設計書全体で配置先を `tests/Feature/Organizations/OrganizationSettingsCopyTest.php` に統一。
