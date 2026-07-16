# 対応マトリクス: conceptual-review Round 2（CHANGES_REQUESTED, 残 2 内部矛盾 + 文言）

## [Warning] 「既存 testid 不変」と PageContent.testId 撤去の矛盾
- 対応: 制約を「機能・操作対象の既存 testid は維持。T070 で追加した PageContent 外枠用 testid のみ撤去し
  依存テストを更新」に限定した。

## [Warning] Capture/Show の PageContent 採用有無が曖昧
- 対応: **Capture/Show は PageContent を使わない唯一の例外**に固定(外枠 PageContainer/PageHeader は適用、本文全幅)。
  Architecture テストは「PageContent 必須契約の除外(allowlist)」として定義(max-width 例外 prop は作らない)。

## [Warning] PageContainer padding={false} が負マージン契約を破壊
- 対応: prop は aigenba parity として残すが、Architecture テストで認証ページからの padding={false} を禁止。

## [Suggestion] 効果の過大主張(nav 構造含む完全一致)
- 対応: 「認証後ページ外枠の構造 parity」に限定表現(BrandLogo スコープ外のため)。

## [Suggestion] 詳細設計送り(actions Snippet / icon Component / カテゴリ導線 1 箇所)
- 対応: 概念設計に注記。詳細設計で確定: actions=children Snippet(旧 slot API 不使用)、icon=lucide Component、
  カテゴリ導線= Projects/Show の 1 箇所に確定。
