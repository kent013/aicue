# Round 4: Round 3 指摘への対応と再レビュー依頼

Round 3 の Warning 3 件 + Suggestion 1 件に対応しました。1 件 (password_confirmation) は
リポジトリの実装事実に基づき反論しています。根拠となる現行コードも添付します。

## 対応マトリクス

# 対応マトリクス: design-review Round 3

Codex 判定: 施策 B **REQUEST_CHANGES** / 全体 **CHANGES_REQUESTED**（Warning 3 + Suggestion 1）。
施策 A は Round 2 で APPROVE 済み。

## [Warning] リセット payload に `password_confirmation` が明記されていない
- 判断: **反論する（ただし「成功 redirect + エラー不在の確認」は採用）**
- 根拠: 本リポジトリの `App\Actions\Fortify\ResetUserPassword::reset()` の rules は
  `['password' => ['required', 'string', Password::default()]]` のみで、
  docblock に「**確認入力 (confirmed) は使わない**（表示トグル + リセット導線 + SSO で代替）」と
  明記されている。画面 `resources/js/pages/Auth/ResetPassword.svelte` にも確認用フィールドが無い。
  `password_confirmation` を送るのは**存在しない仕様をテストに書き込む**ことになる。
- 対応内容: payload は `token` / `email` / `password` のままとし、
  Codex の有用な部分（結果検証の強化）を採用して
  `assertRedirect(route('login'))` + `assertSessionHasNoErrors()` を `hasPassword()` 検証の前に置く
  （`PasswordResetResponse` は login へ redirect + `success` flash）。反論の根拠も設計本文に残した。

## [Warning] ログアウト後に `Welcome` へ着地する契約がテストされていない
- 判断: **対応する**
- 根拠: 指摘のとおり。`Welcome.test.ts` は「Welcome を描画したとき」のリンクしか保証しない。
  B-2 は「ログアウトすると `/` に着地する」ことを前提にしているので、その前提自体を固定すべき。
- 対応内容: 回復テストの `post('/logout')` に `assertRedirect('/')` + `assertGuest()` を追加
  （Fortify 既定 `Fortify::redirects('logout', '/')` を裏取り済み。新規テストは増やさない）。

## [Warning] vitest 計画に旧ラベル「ログアウトしてパスワードを設定する」が残っている
- 判断: **対応する**
- 対応内容: `tests/js/pages/ConfirmRecentAuth.test.ts` の期待を「**ログアウトする**」に修正し、
  押下で `router.post("/logout")` が呼ばれることも assert する
  （`vi.mock("@inertiajs/svelte")` で router を差し替え。既存 `VerifyEmail.test.ts:5-10` と同作法）。

## [Suggestion] 「SSO 専用ユーザー」という呼称が不正確
- 判断: **対応する**
- 根拠: 実態は「password 未設定 かつ 利用可能な再認証 provider なし」という状態であり、
  provider が生きている通常の SSO ユーザー（`canSatisfy=true`）とは別物。
- 対応内容: テスト名・設計文言をその状態名に統一した。


---

## 反論の根拠 (現行コード)

### app/Actions/Fortify/ResetUserPassword.php

```php
<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

class ResetUserPassword implements ResetsUserPasswords
{
    /**
     * パスワードリセットの検証と反映。
     *
     * 強度の SSOT は PasswordPolicy (Password::defaults で配線済)。
     * 確認入力 (confirmed) は使わない (表示トグル + リセット導線 + SSO で代替)。
     *
     * @param  array<string, string>  $input
     *
     * @throws ValidationException
     */
    public function reset(User $user, array $input): void
    {
        Validator::make($input, [
            'password' => ['required', 'string', Password::default()],
        ])->validate();

        $user->forceFill([
            'password' => Hash::make($input['password']),
        ])->save();
    }
}

```

(画面 `resources/js/pages/Auth/ResetPassword.svelte` にも確認用フィールドは無い。
フォームは `token` / `email` / `password` の 3 フィールドのみ。)

---

## 修正後の施策 B テスト計画・DoD (全文)

### テスト計画（テストファースト）

1. **[新規] `tests/js/architecture/page-shell-structure.test.ts` の追加 it 2 本**
   — 実装前に走らせると `ResetPassword` / `TwoFactorChallenge` / `ConfirmRecentAuth` の 3 件で fail する
   （= 現状の欠落をテストが正しく検出することの確認）。
2. **[新規] `tests/js/pages/ResetPassword.test.ts`**
   - フォーム（メールアドレス / 新しいパスワード / 送信ボタン）を描画する
   - **`/forgot-password` と `/login` への離脱リンクを描画する**（`new URL(link.href).pathname` で比較。
     既存 `Login.test.ts` と同作法）
   - `errors.email`（トークン無効）が渡ってもリンクが消えない
     ＝ *bug-hunt F-2-02 の再現シナリオそのもの*
3. **[新規] `tests/js/pages/TwoFactorChallenge.test.ts`**
   - タブ（認証コード / リカバリコード）切替の既存挙動 + `/login` への離脱リンク
4. **[新規] `tests/js/pages/ConfirmRecentAuth.test.ts`**
   - `passwordSet=true` / `canSatisfy=false` の双方で `/dashboard` への離脱リンクが出る
   - **`canSatisfy=false` のとき `/forgot-password` へのリンクが存在しない**（B-2 の回帰。
     `screen.queryAllByRole("link")` の href に `/forgot-password` を含まないことを assert）
   - `canSatisfy=false` のとき「**ログアウトする**」ボタンが出て、押下で `router.post("/logout")` が
     呼ばれる（`vi.mock("@inertiajs/svelte")` で router を差し替える。既存
     `tests/js/pages/VerifyEmail.test.ts:5-10` と同作法。Codex R3 Warning）
5. **[追記] `tests/Feature/Auth/RecentAuthTest.php`** — B-2 の根拠を仕様として固定する
   - `test('ログイン済みユーザーは GET /forgot-password のフォームに到達できない (guest ゲート)')`:
     `actingAs($user)->get('/forgot-password')` が **redirect であり 200 ではない**ことを assert
     （redirect 先は `RedirectIfAuthenticated::defaultRedirectUri()` 依存のため pin しない）。
     = 「認証済み画面から `/forgot-password` へリンクしてはならない」根拠。
   - `test('password 未設定かつ利用可能な再認証 provider が無いユーザーは canSatisfy=false')`:
     `User::factory()->ssoOnly()->create()`（social account を紐付けない）→
     `/recent-auth/confirm` の props が `passwordSet=false` / `availableProviders=[]` / `canSatisfy=false`。
     ※ **「SSO 専用ユーザー」とは呼ばない**（Codex R3 Suggestion）。実態は
     「password 未設定 かつ 利用可能な再認証 provider なし」という**状態**であり、
     provider が生きている通常の SSO ユーザー（`canSatisfy=true`）と混同しない。
6. **[新規] `tests/Feature/Auth/RecentAuthPasswordRecoveryTest.php`** — B-2 が案内する回復手順が
   **端まで成立する**ことを固定する（Codex R2 施策 B Warning。「案内はあるが実際にはできない」の再発防止）
   - `test('再認証手段が無いユーザーはログアウト後にパスワードを設定でき、再認証可能になる')`:
     1. `Http::fake(['https://api.pwnedpasswords.com/range/*' => Http::response('', 200)])`
        （`PasswordPolicy` の HIBP 照会を止める。既存 `RegisterVerifyFlowTest:20` と同作法）
        + `Notification::fake()`
     2. `$user = User::factory()->ssoOnly()->create();`（password null / social account なし）
     3. `actingAs($user)->get('/recent-auth/confirm')` → `canSatisfy=false` を確認
     4. `post('/logout')` → **`assertRedirect('/')` + `assertGuest()`**
        （B-2 が前提にしている「ログアウトで Welcome に着地し guest になる」契約の固定。
        Fortify 既定 `Fortify::redirects('logout', '/')` = `/`。Codex R3 Warning）
     5. `post('/forgot-password', ['email' => $email])` →
        `Notification::assertSentTo($user, ResetPassword::class)` で token を取り出す
        （email は CipherSweet 暗号化だが `App\Auth\EncryptedUserProvider` が
        `whereBlind` 経由で解決する = 平文 where に依存しない）
     6. `post('/reset-password', ['token' => $token, 'email' => $email, 'password' => $new])` →
        **`assertRedirect(route('login'))` + `assertSessionHasNoErrors()`**
        （`PasswordResetResponse` は login へ redirect + `success` flash）。
        **`password_confirmation` は送らない**: `App\Actions\Fortify\ResetUserPassword::reset()` の
        rules は `['password' => ['required','string', Password::default()]]` のみで
        `confirmed` を**意図的に使っていない**（docblock「確認入力 (confirmed) は使わない」）。
        画面 `ResetPassword.svelte` にも確認用フィールドは無い。
        → Codex R3 Warning 1 は本リポジトリの構成には当てはまらないため、
        payload には足さず「成功 redirect + エラー不在」の確認だけを採用する。
     7. `expect($user->fresh()->hasPassword())->toBeTrue()` かつ
        `actingAs($user->fresh())->get('/recent-auth/confirm')` の props が
        **`passwordSet=true` / `canSatisfy=true`**
   - この 1 本が「回復手順の終端」（ログアウト → Welcome → login → reset → password 取得 →
     `canSatisfy=true`）を保証する。
7. 既存 `tests/js/pages/Login.test.ts` の「register / forgot-password への導線」は**変更しない**（回帰の基準。
   `/login` は guest 画面なので `/forgot-password` へのリンクは正しい）
8. 既存 `tests/js/pages/Welcome.test.ts:120`（guest nav の「ログイン」リンク）を**依存契約として維持**
   （B-2 のログアウト後着地から `/login` へ辿れることの担保。変更しない）

### 受け入れ条件 (DoD)

- `AuthLayout` を import する全ページ（allowlist の `Auth/VerifyEmail` を除く）が footer に
  `TextLink` の離脱導線を持ち、architecture テストが green。
- allowlist の健全性（reason 非空 / 実在 / AuthLayout ページであること）がテストで固定されている。
- `ResetPassword` は `errors.email` があるときも `/forgot-password` `/login` への導線を出す。
- `ConfirmRecentAuth` の `canSatisfy=false` 分岐に `/forgot-password` へのリンクが**無く**、
  代わりに実際に踏破できる回復手順（ログアウト）が提示されている。
- 案内した回復手順が**端まで成立する**ことが Feature テストで固定されている
  （SSO 専用ユーザーがログアウト → リセットリンク → パスワード設定 → `canSatisfy=true`）。
- CTA のラベルが実際の着地と一致している（「ログアウトする」= ログアウトのみを行う）。
- `DESIGN.md` に 2 規約が記載されている。
- 新しい行き止まり・新しい踏破不能リンクを増やしていない（各リンク先の到達可能性を上表の根拠で説明できる）。

### リスク

| リスク | 緩和 |
|---|---|
| footer リンク先が別の罠になる（例: 未検証ユーザーを `/dashboard` へ送る） | リンク先は「その画面のユーザーの認証状態で到達できる先」に限定し、根拠を設計に明記。`VerifyEmail`（未検証状態）には**リンクを足さない**（allowlist で本文のログアウトを離脱導線と認める） |
| architecture テストの正規表現が footer を誤検出する | 既存ヘルパ（コメント除去 + import 識別子解決）を再利用し、footer 本体を抽出してから `TextLink` を探す。alias import にも対応 |
| allowlist が将来の抜け道になる | `reason` 非空を別 it で強制（既存 `PAGECONTENT_ALLOWLIST` と同方式）。エントリは現時点で 1 件のみ |
| Debug/Login.svelte が対象に入る | 既に footer（`/login`）を持つため追加変更不要。local 専用画面だが規約対象のままにする（例外を作らない） |

---



---

## 再レビュー依頼

1. `password_confirmation` に関する反論が妥当か (rules に `confirmed` が無いため送っても検証されない)
2. 追加した logout の着地固定 (`assertRedirect('/')` + `assertGuest()`) と
   reset 結果の固定 (`assertRedirect(route('login'))` + `assertSessionHasNoErrors()`) で十分か
3. 施策 B の判定と全体判定 (APPROVED / CHANGES_REQUESTED)
