# 件1: パスワードリセット完了後の成功フィードバック (F-2-02) — 調査結果: 既に実装済み / スキップ

- run: bug-hunt 20260715-213842 / finding F-2-02 (Medium, H7)
- topic: password-reset-success-feedback
- 結論: **既に実装済み。実装せずスキップ**（AGENTS.md 思考原則2「今必要なものだけ作る」/ 指示の skip 条項に従う）。

## finding 概要

パスワードリセット (`password.update`, `/reset-password` POST) 成功後に `/login` へ
リダイレクトされるが、ログイン画面に成功トーストが出ない、という Medium 指摘。
forgot-password / verification.send が緑トーストを出すのと非一貫、という主張。

## 調査 (「設計の最初にやること」(a)(b) を実施)

### (a) リセット成功の応答経路

- `app/Http/Responses/Fortify/PasswordResetResponse.php`（Fortify contract bind、
  `FortifyServiceProvider` L92 で bind 済み）は **web 分岐で
  `redirect(route('login'))->with('success', 'パスワードを変更しました。ログインしてください。')`**
  を返す。すなわちリセット成功→/login リダイレクト時に既に `success` flash を積んでいる。
- これは **T026 (commit 89a7b00, 2026-07-13)** 「保存成功フィードバック統一と二重トースト解消」で
  導入済み。**本 bug-hunt run (2026-07-15 21:38) は T026 を含む main に対して走っている**
  （T026 は 2 日前・HEAD の祖先であることを確認）。
- サーバ挙動は Feature テストで固定済み: `tests/Feature/Auth/FortifyResponseTest.php`
  「パスワードリセットは success flash + login redirect を返す (web)」
  = `assertRedirect(route('login'))` + `assertSessionHas('success', 'パスワードを変更しました。ログインしてください。')`。
  不正/期限切れ token では success を出さない非回帰テストも存在。→ **PASS 済み**。

### (b) /login 側の flash→toast 機構

- `resources/js/pages/Auth/Login.svelte` は `AuthLayout` を使用。
- `resources/js/components/templates/AuthLayout.svelte` は mount 時 `$effect` で
  `consumeFlash(page.props.flash)` を呼び、`<ToastContainer />` を常設。forgot-password の
  緑トーストと**完全に同一の経路**（forgot-password は `EnumerationSafePasswordResetLinkResponse`
  が `back()->with('success', ...)`、reset は redirect(login) で success key は同じ）。
- flash prop は `HandleInertiaRequests::share()` が
  `flash.success = session()->get('success')` + `flash.visitKey = Str::uuid()`（毎リクエスト新規）で
  共有する。visitKey は常に付与されるため consumeFlash の de-dup gate（visitKey 必須）を通過する。
- `consumeFlash` (`resources/js/lib/stores/flash-to-toast.ts`) は visitKey が新規なら
  success を `addToast('success', message)` する。単体テスト `tests/js/lib/flash-to-toast.test.ts` で固定済み。

### ランタイム検証 (frontend integration probe)

AuthLayout を `page.props.flash = { success: 'パスワードを変更しました。ログインしてください。',
visitKey: 'reset-visit-1' }` で render し、トースト文言が DOM に現れるかを vitest で検証 → **GREEN**。
（throwaway probe。main を汚さないため検証後に削除済み。）

→ サーバ（success flash + login redirect、Feature テスト green）＋ フロント
（AuthLayout consumeFlash→ToastContainer、probe green・既存単体テスト green）の**全経路が実装済みで機能する**。

## 判断根拠 (false positive と判定)

1. 同一 report 内で**姉妹 finding F-2-01（forgot-password の同一トースト機構）は
   「誤検知確定 — 1.5秒待てば緑トースト表示」と明記**されている。F-2-02 は宛先が別画面
   (/login) なだけで機構は同一。トーストは自動 dismiss するため、観測ウィンドウ次第で
   「出ていない」と誤認しうる（screenshot が dismiss 後）。
2. サーバ挙動は Feature テストで、フロント描画は probe + 既存単体テストで機能が確認できる。
3. AGENTS.md 思考原則2 / 指示の「既に実装済みなら実装せずスキップ」に該当。

## 追加対応の要否

- 新規実装: 不要（機能・サーバテスト・フロント単体テストが既存）。
- 追加の回帰テスト: `AuthLayout` レベルの flash→toast integration テストは現状不在だが、
  経路は server Feature テスト + flash-to-toast/ToastContainer 単体テストで実質担保されており、
  「今必要なものだけ作る」観点で本 finding のためだけの新規テスト追加は見送る。将来
  flash→toast 経路を触る変更時に AuthLayout integration テストを足すのが自然。

## 結論

**F-2-02 は既に実装済み（T026 で対応済み）＝ false positive。実装せずスキップ。**
TODO 登録・worktree 実装は行わない。
