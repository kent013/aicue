# 詳細設計レビュー Round 3

Round 2 の残 Warning (S2 の UI 操作テスト欠如) に対応しました。

## [Warning] S2: UI 操作の回帰テストがない → 対応 (施策 S4 を新設)
本リポジトリには vitest + @testing-library/svelte のフロントテスト基盤が実在し、`tests/js/pages/ProjectsShow.test.ts` も既存です。`OrganizationsSettings.test.ts` が `vi.spyOn(router, "post"/"delete")` + `fireEvent` で送信 URL/payload を検証する確立パターンを持っています。これに倣い、施策 S4「`tests/js/pages/ProjectsShow.test.ts` の拡張 (UI 操作テスト)」を新設しました。

S4 で固定する契約:
1. canManage=true でメンバー管理 Card / 一覧 / 追加フォーム / role select / 削除ボタンを描画。
2. canManage=false でメンバー管理 UI 全体を非表示。
3. implicit=true 行は role select・削除ボタンを出さず「管理者（組織）」バッジを表示。
4. 追加(正常): 候補選択 → submit で `router.post('/projects/1/members', { user_id, role })` 発火 (spy)。
5. 追加(未選択): 候補未選択で submit → router.post 呼ばれず、field error 表示 (disabled 不使用の回帰も兼ねる)。
6. ロール変更: role select onchange → `router.post('/projects/1/members', { user_id, role })` 発火。
7. 削除: 削除ボタン → ConfirmDialog → 確定で `router.delete('/projects/1/members/{id}')` 発火。
8. canViewMemberEmails=false で member email を実値表示しない。
9. assignableUsers=[] で案内文 (canManageMembers=true なら /manage/users リンク併記) を表示。

波及: 既存 baseProps に新規必須 prop (members / canViewMemberEmails / assignableUsers) を追加 (型不整合で既存テストが落ちるのを防ぐ必須波及) と明記。施策一覧・実装モードの判断根拠も S4 を反映して更新済み。

これで S1=APPROVE, S3=APPROVE に続き S2 の残件も解消したと考えます。全体 APPROVED をお願いします。残る Critical/Warning があればご指摘ください。
