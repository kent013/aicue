# 対応マトリクス: impl-review Round 1

## [Warning] `retryEnrollmentAssets()` が `enrollmentAssetsFailed` をリセットしていない (Security.svelte)

- 判断: **対応する**
- 根拠: 指摘のとおり、`500 で取得失敗 → 再試行 → 409` の順に遷移すると、409 分岐は
  `enrollmentAssetsFailed` を触らないため「再認証が必要です」と
  「設定情報を取得できませんでした」が同時に出る。設計の「409 を取得失敗に畳まない」
  (原因と対処を一致させる) に反する経路であり、実在する。
- 対応内容: `retryEnrollmentAssets()` 側ではなく **`loadEnrollmentAssets()` 冒頭**へ
  `enrollmentAssetsFailed = false` / `enrollmentStepUpBlocked = false` を置いた
  (**結果表示の単一初期化点**にする。呼び出し側ごとにリセットを書くと、将来
  新しい呼び出し側が追加されたときに同じ漏れが再発するため)。
  `enrollmentStepUpRetried` (自動再開の上限) は**ここでは戻さない** — 戻すと
  409 → 自動再開 → 409 → 自動再開 … が無限に回る。上限を戻せるのは人間の操作
  (`retryEnrollmentAssets`) と enrollment の破棄 (`resetEnrollmentAssets`) だけ。

## [Warning] 上記の遷移テストが無い (tests/js/pages/SettingsSecurity.test.ts)

- 判断: **対応する**
- 根拠: 現行の 409 系テストは初回状態からの 409 のみで、既存の取得失敗状態を
  引きずるケースを検出できない (指摘のとおり)。
- 対応内容: `it('500 で取得失敗した後に再試行して 409 になったら取得失敗 Alert を残さない')`
  を追加した。500 → `enrollment-assets-error` 表示 → 再試行ボタン →
  409 かつ status 500 (blocked) へ遷移し、`enrollment-step-up-blocked` が出て
  `enrollment-assets-error` が**消えている**ことまで固定する。

## [Suggestion] "passkey-only" は User factory が password を持たないか未確認 (TwoFactorEnforcementTest)

- 判断: **対応する**
- 根拠: テスト名が主張する前提 (passkey 以外の satisfier を持たない) を
  Factory の既定に暗黙依存させると、Factory 変更でテストの意味が沈黙して薄れる。
- 対応内容: `$member->forceFill(['password' => null])->save()` で password を実際に外し、
  `expect($member->password)->toBeNull()` / `expect($member->socialAccounts()->count())->toBe(0)`
  で前提を**テスト内に明示固定**した。

## [未確認] `composer test:browser` の最終結果 / devnotes 実測ログの実在

- 判断: **対応する (証跡を提示する)**
- 対応内容: Round 2 プロンプトで全検証レーンの実測値を提示する。
  実測ログは `devnotes/20260807-2127-todo-T124/impl-step2-fail-observation.md`
  (Step A) と `devnotes/20260807-2127-todo-T124/mutation-evidence.md` (Step C m1〜m8) に実在する。

## (Round 1 以降に発生した外部要因) main の取り込みで設計の前提が 1 つ失効した

- 判断: **設計の意図を保つ形で実装側の根拠記述を実測へ合わせる**
- 根拠: 中断中に main へ T125 (inline throttle の 6 named レーン分離) がマージされ、
  「同一 actor の全 inline route が 1 bucket を共有するため enable/confirm の連打で
  `recent-auth.password` が 429 になる」という**設計が書いていた順序の根拠が失効**した。
  さらに実測 (`X-RateLimit-Remaining` の観測) で `ThrottleRequests` は
  `RequireRecentAuth` より**先**に走ることが確認できたため、
  「precheck が throttle 枠を守る」という説明も主従が逆だった。
- 対応内容: 施策 4 の**実装 (precheck を enable の前段に置く順序) は変えない**。
  根拠記述だけを実測どおりに書き換えた (Security.svelte の docblock と
  docs/architecture.md §クライアント側)。固定したい本命は
  「409 の全画面遷移で enrollment 途中の画面状態を失わないこと」であり、
  throttle 予算はその副次的効果にすぎない、と明記した。
