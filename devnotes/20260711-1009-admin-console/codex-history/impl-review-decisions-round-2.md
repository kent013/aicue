# 対応マトリクス: impl-review Round 2

総評: マージ可 / Critical なし。

## [Warning] StoreOrganizationInvitationRequest / UpdateOrganizationMemberRoleRequest の `'role.'.Enum::class` メッセージキーが解決されない可能性
- 判断: 反論する (false positive) + 回帰テスト追加で固定
- 根拠: Laravel の `Validator::validateUsingCustomRule()` (vendor/laravel/framework/src/Illuminate/Validation/Validator.php:945-951) は rule object 失敗時に `getFromLocalArray($attribute, get_class($rule))` でカスタムメッセージを引くため、`role.Illuminate\Validation\Rules\Enum` が正しいキー。`role.enum` は string ルール用で、rule object には一致しない。実行時検証 (php -r で Validator::make に同キーを渡す) でカスタムメッセージが返ることを確認済み。
- 対応内容: 挙動が Laravel 内部実装依存であることは事実なので、`tests/Feature/Organization/InvitationTest.php` に「role 不正値でカスタムメッセージが error bag に載る」回帰テストを追加してキー解決を固定した (UpdateOrganizationMemberRoleRequest も同一パターンのため代表 1 本で担保)。

## [Suggestion] Categories.svelte 編集モーダルの処理中 disabled と DESIGN.md 規約の解釈差
- 判断: 見送る
- 根拠: 禁止規約は「必須条件未充足を理由にした disabled」(AGENTS.md 禁止事項 8) であり、処理中 (in-flight) の二重送信防止 disabled は対象外。既存ページ (Organizations/Settings 等) も同パターンで統一されており、本タスクで DESIGN.md の文言整備までは行わない (スコープ外。必要なら別 TODO)。
