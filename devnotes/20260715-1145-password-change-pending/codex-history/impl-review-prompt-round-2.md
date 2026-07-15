# Codex 実装レビュー Round 2: T060 password-change-pending

Round 1 の差し戻し（唯一の blocking Warning）に対応した。以下の対応マトリクスと修正差分を確認し、全体判定 **APPROVED / CHANGES_REQUESTED** を返せ。

## 対応マトリクス

### [Warning] `clearErrors()` 無引数 → スコープ限定（対応した）
- `resources/js/pages/Settings/Index.svelte`: `passwordForm.clearErrors()` を
  `passwordForm.clearErrors("current_password", "password")` に変更。
  passwordForm が実際に所有するフィールドは `current_password` / `password` の 2 つのみ。
  Fortify の password 更新はこのフォームから `password_confirmation` を送らない設計のため、
  幻フィールド `password_confirmation` は加えず、実在フィールドのみに限定した。
- 回帰テスト（test 1）に `expect(clearMock).toHaveBeenCalledWith("current_password", "password")` を
  追加し、「本フォーム所有フィールドのみ消す」意図をロックした。

### [Warning] reactiveUseForm の double 専用契約明示（見送り）
- 既存 helper 冒頭 docstring が「反応的 double」と用途を明示済み、追加拡張にも
  「既存 consumer は post のみ参照で後方互換」とコメント済み。Codex も blocking ではないと明記。
  型名変更等の追加抽象化はオーバーエンジニアリング（AGENTS.md 思考原則 2）に触れるため入れない。

## 修正差分（Round 1 からの追加分）

```diff
--- a/resources/js/pages/Settings/Index.svelte
+++ b/resources/js/pages/Settings/Index.svelte
@@ submitPassword
-        // 送信中の誤認防止のため、前回エラーを送信開始時に明示クリアする
-        // (Inertia useForm は送信ではクリアせず応答後にのみ errors を更新するため)。
-        passwordForm.clearErrors();
+        // 送信中の誤認防止のため、前回エラーを送信開始時に明示クリアする
+        // (Inertia useForm は送信ではクリアせず応答後にのみ errors を更新するため)。
+        // 本フォームが所有するフィールドに限定してクリアし、過剰クリアの余地を残さない。
+        passwordForm.clearErrors("current_password", "password");

--- a/tests/js/pages/SettingsIndex.test.ts
+++ b/tests/js/pages/SettingsIndex.test.ts
@@ test 1 (エラークリア DOM)
         const passwordForm = formHolder.password;
-        expect(passwordForm?.clearErrors as ReturnType<typeof vi.fn>).toHaveBeenCalledTimes(1);
+        const clearMock = passwordForm?.clearErrors as ReturnType<typeof vi.fn>;
+        expect(clearMock).toHaveBeenCalledTimes(1);
+        // 本フォームが所有するフィールドに限定してクリアする (過剰クリア防止の意図を固定)
+        expect(clearMock).toHaveBeenCalledWith("current_password", "password");
         expect(passwordForm?.put as ReturnType<typeof vi.fn>).toHaveBeenCalledTimes(1);
```

## 検証結果（修正後）

- `tests/js/pages/SettingsIndex.test.ts`: 17 passed（既存 13 + 新規 4、無改変維持）
- 全 frontend スイート: 80 test files / 735 tests passed（4 バッチ分割 foreground 実行、全 green）
- `pnpm typecheck`: OK / `pnpm lint`: OK / `pnpm build`: OK
- PHP 変更なし
