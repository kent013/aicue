# 対応マトリクス: design-review Round 2

## [Critical] 施策 3: `UpdateOrganizationRequest::attributes()` が未テスト実装になる
- 判断: 対応する
- 根拠: 禁止事項 1 (テストなしの実装完了報告) に照らし妥当な指摘。既存の organizations.update テストは
  `OrganizationBoundaryNotFoundTest` (404 境界検証専用) のみで文言検証の置き場所として不適切。
- 対応内容: 施策 5 に新規 Feature テストファイル
  `tests/Feature/Organization/OrganizationSettingsCopyTest.php` を追加。空 `name` の PATCH で
  「組織名は必須項目です。」の厳密一致を検証する (局所上書きの存在・優先・文言を担保)。
  施策一覧・変更箇所・実装手順 (fail 確認: 上書き前は「名前は必須項目です。」) を更新。

## [Suggestion] 施策 4: FQCN は Illuminate\Support\Facades\Validator との完全一致に限定
- 判断: 採用
- 対応内容: 検出仕様を「FQCN 直書きは解決結果が Illuminate\Support\Facades\Validator と完全一致する
  場合のみ」に修正 (同名独自クラスの過剰検出を回避)。
