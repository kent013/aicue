# 対応マトリクス: design-review Round 1

## [Critical] S1: assignableUsers を canManage でなく canManageMembers でゲートすべき
- 判断: **反論する** (canManage が正しい)
- 根拠: このアプリで「プロジェクトメンバー管理」の認可根拠は **`can('update', $project)` (= `$canManage`)** である。
  - `ProjectMemberController::store` / `destroy` はどちらも `Gate::authorize('update', $project)` で認可している (= canManage)。
  - `canViewMemberEmails` も `can('update', $project)` = canManage で、`ProjectShowEmailVisibilityTest` が「canViewMemberEmails === can('update')」を契約として固定済み。
  - `canManageMembers` (= `can('manageMembers', $organization)`) は**組織レベルのユーザー管理** (/manage/users への導線) を表す別概念。project メンバー管理には使えない。
  - もし assignableUsers を canManageMembers でゲートすると、**project_admin だが org admin でないユーザー** (canManage=true / canManageMembers=false) で「操作は可能なのに候補が [] で追加できない」という Codex が懸念する齟齬がまさに発生する。逆に org 内の別 project の項目にも影響し得る。
  - 現設計は「メンバー管理 Card 全体 (canManage gate) + store/destroy (update 認可) + assignableUsers (canManage gate) + email 可視性 (canManage)」を**単一根拠 `can('update', $project)` に統一**しており、表示と操作が完全に一致する。これは Codex が求める「開示ゲートを正しい権限に統一」を既に満たしている。
- 対応内容: 詳細設計に「project メンバー管理の単一認可根拠は `can('update', $project)`。canManageMembers は org レベル (/manage/users 導線) の別概念で流用しない」を明記して誤読を防ぐ。コード変更なし。

## [Warning] S1: array_column の戻り型が list<mixed> になり PHPStan L10 で推論が弱い
- 判断: 対応する
- 根拠: 明示型で L10 の安全側に倒す。
- 対応内容: `/** @var list<int> $memberIds */ $memberIds = array_column($memberRows, 'id');` を明示。

## [Suggestion] S1: memberRows と assignableUserRows の org->users() 重複クエリ
- 判断: 対応する (コメント追記)
- 対応内容: 「org メンバー数 N は通常小さく許容。将来 N が大きくなれば単一クエリ化を検討」というコメントを設計コードに残す。

## [Critical] S2: Select の disabled が禁止事項8 に抵触
- 判断: 対応する (disabled を外し handler ガードに変更)
- 根拠: 禁止事項8 は「必須条件未充足を理由に disabled」を禁じる。送信中ガードは別物だが、既存 `Admin/Users.svelte` の role 変更は select に disabled を付けず handler 早期 return (`if (role === "" || changingRole) return;`) で二重送信を防いでいる。この既存流儀に合わせれば規約解釈の揺れも消える。
- 対応内容: Select から `disabled={changingRoleId === member.id}` を削除。`changeMemberRole` 冒頭の `if (role === "" || changingRoleId !== null) return;` 早期 return を二重送信ガードとする (Admin/Users と同一)。

## [Warning] S2: ロール変更失敗時の表示ロールバック仕様が未記載
- 判断: 対応する
- 根拠: 非 optimistic だが「選択は変わったが保存失敗」で表示がサーバと乖離し得る。
- 対応内容: `changeMemberRole` に `onError` を追加し、`router.reload({ only: ['members', 'assignableUsers'] })` で表示を再同期 + flash/field error を表示する旨を設計に明記。

## [Suggestion] S2: roleLabel() が未使用
- 判断: 対応する (削除)
- 根拠: 明示メンバーは Select、暗黙メンバーは Badge「管理者（組織）」で表示し roleLabel を使わない。
- 対応内容: 設計スニペットから `roleLabel()` を削除。

## [Warning] S3: assignableUsers の inline クロージャ検証が可読性低い
- 判断: 対応する (テスト内ヘルパー分離)
- 対応内容: shape 検証 / 除外・包含 ID 検証 / PII キー不在検証をテスト内 helper (`assignableRows()` 正規化 + 個別 expect) に分離する方針をテスト計画に明記。

## [Suggestion] S3: canManageMembers=false ケースの追加
- 判断: 見送る (該当しない)
- 根拠: 本設計は canManageMembers でゲートしないため、この分離ケースは存在しない。代わりに canManage=false ケース (assignableUsers=[] かつ email null) を既に計画済み。S1 の反論で権限根拠を canManage に統一したことをテストコメントに残す。
