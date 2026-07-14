# 詳細設計レビュー Round 4

Round 3 で残った Warning 1 件（S4: キャンセル契約の自動テスト未計画）を対応しました。

## [Warning] S4: destructive closure 破棄の自動テスト → 必須の component テストとして計画

S4 のテスト計画を「任意」→「必須」に格上げしました。既存 `tests/js/pages/SettingsSecurity.test.ts`（vitest + @testing-library/svelte、`@inertiajs/svelte` の `router` mock 済み、recent-auth の fresh/stale を切り替える `stubFetchRoutes` helper あり。regenerate/表示フローを同形式でテスト済み）に disable フローを追加します。

前提の小改修: 既存 mock の `router: { post: routerPostMock, delete: vi.fn() }` の delete を **`routerDeleteMock` へ hoist**（`afterEach` で `mockReset()`）して呼び出しを検証可能にする。

追加テスト（regenerate 既存テストと同じ helper/mock を流用）:

1. **fresh → 1 回だけ delete**: `stubFetchRoutes({ recent: true })` → disable 確定 → `routerDeleteMock` が `/user/two-factor-authentication` で exactly once。
2. **stale → modal 表示・delete しない**: `stubFetchRoutes({ recent: false })` → disable 確定 → `recent-auth-modal` 表示 + `routerDeleteMock` 未発火 + 確認ダイアログが閉じる（二重モーダル回避）。
3. **stale → キャンセル → pending 破棄**: stale で modal を開き閉じる → その後 別操作（再生成）で recent-auth 成功 → `routerDeleteMock` は一度も呼ばれない（破棄された disable closure が resume されない）。
4. **stale → password 確認成功 → resume で 1 回だけ delete**: stale の modal に password 入力 → `recent-auth-submit` → `resumePendingAction()` 経由で `routerDeleteMock` exactly once。

検証コマンドに `pnpm test`（vitest）を追加。変更ファイルに `tests/js/pages/SettingsSecurity.test.ts` を追加。

---

上記で Round 3 の Warning は解消されたと考えます。残る Critical/Warning があれば指摘してください。なければ全体 APPROVED をお願いします。
