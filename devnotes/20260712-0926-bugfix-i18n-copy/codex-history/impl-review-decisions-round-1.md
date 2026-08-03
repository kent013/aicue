# 対応マトリクス: impl-review Round 1

対象: T012 レビュー指摘修正 (組織作成/プロジェクト作成・更新の validation attribute 語彙ズレ解消)。
Codex 判定: Critical なし / Warning なし / Suggestion 2 件。

## [Suggestion] Pest ヘルパー import 方針の統一 (OrganizationCreateCopyTest.php:1)

- 判断: 見送る
- 根拠: 本リポジトリの既存 Feature テスト (OrganizationSettingsCopyTest / CategoryCrudTest 等) は
  `$this->actingAs(...)` スタイルで統一されており、新規テストも同スタイルに準拠済み。
  import スタイルの混在は発生していない (Codex 自身も「不具合ではない」と明記)。
- 対応内容: なし (既存規約準拠を維持)。

## [Suggestion] 期待文言の完全一致検証の二層化 (ProjectCopyTest.php:1)

- 判断: 見送る
- 根拠: 本タスクの目的は「表示文言そのものの検証」(語彙ズレ禁止) であり、完全一致が要件。
  Codex も「現状維持で問題なし」と明記。補助テストの追加は「あったら便利」の域で
  オーバーエンジニアリング禁止原則 (AGENTS.md 思考原則 2) に抵触する。
- 対応内容: なし。
