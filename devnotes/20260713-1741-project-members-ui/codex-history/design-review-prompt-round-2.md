# 詳細設計レビュー Round 2

Round 1 の指摘への対応を報告します。

## [Critical] S1: assignableUsers を canManage でなく canManageMembers でゲートすべき → **反論 (canManage が正しい)**
このアプリで「プロジェクトメンバー管理」の認可根拠は一貫して **`can('update', $project)` (= canManage)** です。canManageMembers は別概念であり流用できません。
- `ProjectMemberController::store` / `destroy` はどちらも `Gate::authorize('update', $project)` (= canManage) で認可している (レビュー時に提示した現行コード参照)。
- `canViewMemberEmails` も `can('update', $project)` で、`ProjectShowEmailVisibilityTest` が「canViewMemberEmails === can('update')」を契約として固定済み。
- `canManageMembers` (= `can('manageMembers', $organization)`) は**組織レベルのユーザー管理** (/manage/users 導線) を表す別概念で、project メンバー管理には使えない。
- もし assignableUsers を canManageMembers でゲートすると、**project_admin だが org admin でない**ユーザー (canManage=true / canManageMembers=false) で「操作は可能なのに候補が [] で追加できない」という、まさにご懸念の齟齬が発生します。
- 現設計は「メンバー管理 Card 表示 (canManage) + store/destroy 認可 (update) + assignableUsers ゲート (canManage) + email 可視性 (canManage)」を**単一根拠 `can('update', $project)` に統一**しており、表示・PII 開示・操作が完全一致します。これはご指摘の「開示ゲートを正しい権限に統一」を既に満たしています。
- 詳細設計に §「権限ゲートの単一根拠」を追記し、canManageMembers を流用しない理由を明記しました。

この反論で S1 の Critical は解消と考えますが、認可モデルの理解に相違があればご指摘ください。

## [Warning] S1: array_column の戻り型が list<mixed> → 対応
`/** @var list<int> $memberIds */` を明示しました。

## [Suggestion] S1: org->users() 重複クエリ → 対応
「N は通常小・許容。将来 N 増大で単一クエリ化検討」コメントを設計コードに追記。

## [Critical] S2: Select の disabled が禁止事項8 に抵触 → 対応
ロール select の `disabled` を**削除**し、二重送信ガードを handler 早期 return (`if (role === "" || changingRoleId !== null) return;`) に変更しました (`Admin/Users.svelte` の changeRole と同一流儀)。追加ボタンの `loading={memberForm.processing}` は transient state 表示で禁止事項8 対象外 (既存 Item 追加ボタンと同じ)。

## [Warning] S2: ロール変更失敗時の表示ロールバック → 対応
`changeMemberRole` に `onError: () => router.reload({ only: ['members', 'assignableUsers'] })` を追加し、保存失敗時に props を再取得して表示を再同期する旨を明記。

## [Suggestion] S2: roleLabel 未使用 → 対応
`roleLabel()` を削除しました。

## [Warning] S3: assignableUsers 検証の可読性 → 対応
テスト内 helper (`assignableRows()` 正規化 + shape/除外/PII を個別 expect に分離。既存 `emailVisibilityRows()` と同流儀) に切り出す方針をテスト計画に明記。

## [Suggestion] S3: canManageMembers=false ケース追加 → 見送り
本設計は canManageMembers でゲートしないため該当ケースが存在しません。代わりに canManage=false ケース (assignableUsers=[] かつ email null) を計画済み。権限根拠が canManage であることをテストコメントに残します。

残る Critical/Warning があればご指摘ください。なければ全体 APPROVED をお願いします。
