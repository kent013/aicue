# 対応マトリクス: impl-review Round 1

Codex (`gpt-5.3-codex`, reasoning=high) 全体判定: **APPROVED**（Critical 0 / Warning 0 / Suggestion 1）

## [Suggestion] `verify-email-continue` は DoD の「リポジトリ全体から消える」に反して test に残っている

- 判断: **見送る（指摘の趣旨は受け入れ、実装は変えない）**
- 根拠:
  - 当該文字列が残る唯一の箇所は `tests/js/pages/VerifyEmail.test.ts` の
    `expect(screen.queryByTestId("verify-email-continue")).toBeNull()` で、
    **旧実装の直接的な回帰ガード**として意図的に置いている。ここから消すと
    「同じ testId で CTA が復活した」ケースの検出が弱くなる。
  - 本番コード (`resources/js` / `app`) からは完全に消えていることを grep で確認済み
    （残存する `continueUrl` は `OnboardingReturnResolver` 由来の Billing 側の別物のみ）。
  - 検出の一般化は同ファイルの「描画される button は再送信 / ログアウトの 2 つだけ・
    link は 0 個」という**許可集合との厳密比較**が担っており、testId 依存の回帰ガードは
    その補助に留まる（設計の意図どおり）。
- 対応内容: コード変更なし。DoD の意図は「**本番コードから**消える + テストは不在を assert する」
  であることを本マトリクスに明記して運用のブレを防ぐ。詳細設計書 (`detailed-design.md`) は
  4 ラウンドのレビューを経た成果物であり、事後の文言差し替えは履歴を濁すため行わない。

## 設計から外れた点（Codex への申告済み・指摘なし）

- `tests/js/architecture/logout-call-site-inventory.test.ts` への `pages/Auth/ConfirmRecentAuth.svelte`
  登録と `docs/supported-browsers.md` の「2 箇所 → 3 箇所」更新は**設計書に無い波及**。
  T089 が入れた deny-by-default inventory（Inertia history 暗号化の経路 C の保証条件）が
  施策 B-2 の新規 `router.post("/logout")` を検出したため。Codex も「T089 との整合性維持として妥当」と判定。
