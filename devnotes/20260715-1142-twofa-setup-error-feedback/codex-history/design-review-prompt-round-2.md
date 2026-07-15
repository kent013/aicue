## design-review Round 2

Round 1 の 2 件の Warning (施策2 テスト実装詳細) を反映しました。施策1 は APPROVE 済みのため
施策2 の再確認をお願いします。

### 対応内容

**[Warning 1] reactiveUseForm の processing がリアクティブでない → 対応**
- `tests/js/support/reactiveUseForm.svelte.ts` を後方互換に拡張する:
  - `reset: vi.fn()` を追加 (confirm onSuccess が confirmForm.reset() を呼ぶため)
  - `processing` を `$state` + getter 化し `setProcessing(bool)` を公開
    (loading={confirmForm.processing} の onStart→onFinish 遷移を将来検証可能に。
     既存利用箇所は processing を読むだけなので getter 化は後方互換)
  - post は vi.fn() のまま呼び出し引数を保持 (options.onError / onSuccess を test から駆動)

**[Warning 2] 誤コード表示テストが errors.code 直接代入のみ → submit→onError 経路を追加**
- テスト計画 (b) を変更:
  確認フォーム submit で捕捉した confirmForm.post の
  `options.onError?.({ code: "認証コードが無効です" })` を発火 → フェイクの onError 実装で
  errors.code に反映 (実運用の submit→onError 経路を再現) → 入力直下
  (#two-factor-code-error / screen.getByText) に文言が描画され Input が error(aria-invalid/赤枠)
  になることを assert。直接代入は補助アサートに降格。
- (補) setProcessing(true/false) で「確認して有効化」ボタンの aria-busy 遷移を確認するテストを追加可能。

### 更新後のテスト計画 (施策2)

- (a) errorBag 指定の固定: 2FA 無効で render → 有効化ボタン → router.post の onSuccess を呼び
  confirming=true → コード入力 → 確認フォーム submit → confirmForm.post が
  "/user/confirmed-two-factor-authentication" と objectContaining({ errorBag:
  "confirmTwoFactorAuthentication" }) で呼ばれることを assert。
- (b) 誤コード submit → onError 駆動: 上記のとおり onError({ code }) 発火で errors.code 反映 →
  getByText + aria-invalid を検証。
- (c) 正コードで成功: confirmForm.post の onSuccess 実行 → confirming 解除で確認フォームが消える
  (showRecoveryCodes の fetch は stub) → confirmForm.reset() 呼び出しも確認可能。
- (補) processing 遷移: setProcessing(true/false) で aria-busy の on/off。
- 既存 F-10 テスト群は非改変で green 維持。pnpm typecheck/lint/test/build green。

これらの反映で施策2 の懸念は解消されていますか。他に Critical/Warning が無ければ全体 APPROVED を
確認してください。
</content>
