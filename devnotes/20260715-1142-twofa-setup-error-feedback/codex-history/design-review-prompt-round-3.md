## design-review Round 3

Round 2 の Warning (施策2 (b) の onError 駆動が実装と矛盾) を反映しました。

### 対応内容
- `reactiveUseForm` に **`respondWithErrors(next: Record<string,string>)`** を公開する。
  実装は `Object.assign(errors, next)` で、リアクティブな `errors` ($state) を更新する
  (Inertia がレスポンス受領後に form.errors を更新する挙動の模倣)。
- テスト (b) を変更:
  確認フォーム submit (errorBag 付き post が飛ぶ) → `respondWithErrors({ code: "認証コードが無効です" })`
  を呼ぶ → 入力直下 (#two-factor-code-error / screen.getByText) に文言が描画され、Input が
  error (aria-invalid/赤枠) になることを assert。
- 「options.onError を直接発火」する記述は削除 (confirmTwoFactor は post options に onError を
  渡さないため)。
- 責務分離を明文化: (a)=コンポーネント→Inertia の visit option (errorBag) / (b)=Inertia が反映した
  form errors → UI 表示 / (c)=成功 callback 後の状態遷移。
- reset: vi.fn()、processing の $state+getter 化、成功パス駆動は Round 2 で問題なしと確認済み。

これで施策2 の懸念は解消されていますか。他に Critical/Warning が無ければ全体 APPROVED を確認して
ください。
</content>
