# 対応マトリクス: design-review Round 2

## [Warning] S2: UI 操作の回帰テストがない (送信 URL / method / 未選択エラー / 削除確認 / 暗黙メンバー非表示)
- 判断: 対応する (フロント vitest 操作テストを施策 S4 として追加)
- 根拠: 本リポジトリには vitest + @testing-library/svelte のフロントテスト基盤が実在し、`tests/js/pages/ProjectsShow.test.ts` も既存。`OrganizationsSettings.test.ts` は `vi.spyOn(router, "post"/"delete")` + `fireEvent` で送信 URL/payload を検証する確立パターンを持つ。「テストなしの実装完了報告」禁止 (禁止事項1) の観点でも UI テスト追加が正しい。
- 対応内容:
  - 施策 S3 を「バックエンド Feature テスト」に限定し、**新規に施策 S4「`tests/js/pages/ProjectsShow.test.ts` の拡張 (UI 操作テスト)」を追加**。
  - S4 で以下を固定:
    1. canManage=true でメンバー管理 Card / 一覧 / 追加フォーム / ロール select / 削除ボタンを描画。
    2. canManage=false でメンバー管理 UI 全体を非表示。
    3. `implicit=true` 行はロール select・削除ボタンを出さず「管理者（組織）」バッジを表示。
    4. 追加: 候補選択 → submit で `router.post('/projects/1/members', { user_id, role })` が発火 (spy)。
    5. 追加: 候補未選択で submit → `router.post` が呼ばれず、field error が表示される (disabled 不使用)。
    6. ロール変更: select onchange → `router.post('/projects/1/members', { user_id, role })` が発火。
    7. 削除: 削除ボタン → ConfirmDialog → 確定で `router.delete('/projects/1/members/{id}')` が発火。
    8. canViewMemberEmails=false で member email を実値表示しない。
  - **波及**: 既存 `ProjectsShow.test.ts` の `baseProps` に新規必須 prop (`members` / `canViewMemberEmails` /
    `assignableUsers`) を追加する (追加しないと型不整合で既存テストが落ちるため必須波及)。
