# 実装レビュー依頼: T026 保存成功フィードバック統一と二重トースト解消

## アプリの使命 (North Star)

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

本施策の位置づけ: 差別化機能ではなく「思考ゼロで安心して操作できる土台の補強」。設定変更の成否が即座に伝わることは操作不安・二重送信を減らす基礎 UX。

## 禁止事項 (AGENTS.md)

1. テストなしの実装完了報告
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作をエージェント判断で実行
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び / 6. prompt 文字列のコード直書き
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用)
8. 必須条件未充足を理由にボタンを disabled にする UI

> 補足: 本施策の Fortify Response 実装は `JsonResponse`/`RedirectResponse` を返す Fortify contract 実装であり、既存の `TwoFactorDisabledResponse`/`RecoveryCodesGeneratedResponse` と同型。`response()->json()` の直書きには当たらない (DTO/JsonResource 不要)。

【思考原則 — 全議論に適用】
まず仮説を立てろ。ユーザー視点で考えろ。先人の知恵(Laravel/Fortify の作法)を探せ。機能の名前に立ち返れ。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

## あなたの役割

Laravel + Svelte の改善実装をレビューするコードレビュアー。以下の観点で厳密にレビューし、ファイルごとに判定、指摘を Critical / Warning / Suggestion に分類、最後に全体判定 (APPROVED / CHANGES_REQUESTED) を出せ。

レビュー観点:
- 設計との一致性 (下記詳細設計との整合)
- 正確性 (エッジケース、Fortify の分岐・redirect 契約、enumeration 安全性の非回帰)
- PHPStan level 10 適合性 (型明示、`__()`/`trans()` の array|string narrowing)
- DTO/JsonResource パターン (Fortify contract 実装のため対象外だが逸脱がないか)
- テスト網羅性 (web success flash + JSON 契約 + reset 失敗系の非回帰)
- セキュリティ (パスワードリセットの token 検証、success flash が失敗時に漏れないか)
- Atomic Design 準拠 (Svelte 変更は toast 発火の削除のみ。階層逆流なし)

## 詳細設計書 (要点)

施策一覧:
1. プロフィール更新の success flash 化: `ProfileUpdatedResponse`(新規) + FortifyServiceProvider に singleton bind
2. パスワード変更の success flash 化: `PasswordUpdatedResponse`(新規) + singleton bind
3. パスワードリセットの success flash 化: `PasswordResetResponse`(新規, constructor に status を取るため **bind (非 singleton)**) + bind
4. 再生成 toast の正本一本化: `Security.svelte` の client success `addToast` 削除 (サーバ flash `RecoveryCodesGeneratedResponse` を単一の源に) + サーバ文言に「新しいコードを保管してください。」を集約
5. Feature テスト (3 操作の web success flash + JSON 契約 + reset 失敗系 非回帰 2 ケース)
6. vitest 更新 (再生成 happy path で client success toast 非発火 / GET 失敗文言)

方針:
- web 向け操作成功 flash は `success` キーに統一する。`status` キーは flash-to-toast が意図的に gating (toast 化しない) ため使わない。
- `expectsJson()` 採用は既存 custom Response family (`TwoFactorDisabledResponse`/`RecoveryCodesGeneratedResponse`) と揃える意図。Inertia PUT (Accept: text/html) は `expectsJson()`=false で `back()->with('success')` 分岐に入り AppLayout の consumeFlash が toast 化する。
- 全施策とも Controller は変更しない (処理は Response contract に閉じる)。
- password reset の JSON 分岐は Fortify 既定 (`['message' => trans(status)]`, 200) を維持し API 契約を壊さない。

## テスト結果

- Feature `FortifyResponseTest`: 11 passed (36 assertions) — profile/password/reset の web success flash + JSON + reset 失敗系(不正token/期限切れ)を含む
- vitest `SettingsSecurity.test.ts`: 11 passed — happy path で client success toast 非発火、GET 失敗で error 文言
- 全体: composer test 1567 passed / 2 skipped / 0 failed, pnpm test 476 passed, PHPStan OK, pint OK, lint OK, typecheck OK, build OK

## 実装差分 (git diff)

```diff
diff --git a/app/Http/Responses/Fortify/PasswordResetResponse.php b/app/Http/Responses/Fortify/PasswordResetResponse.php
new file mode 100644
index 0000000..91377b3
--- /dev/null
+++ b/app/Http/Responses/Fortify/PasswordResetResponse.php
@@ -0,0 +1,46 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Http\Responses\Fortify;
+
+use Illuminate\Http\JsonResponse;
+use Illuminate\Http\RedirectResponse;
+use Illuminate\Http\Request;
+use Laravel\Fortify\Contracts\PasswordResetResponse as PasswordResetResponseContract;
+use Laravel\Fortify\Fortify;
+
+/**
+ * パスワードリセット完了後のレスポンス (Fortify contract bind)。
+ *
+ * Fortify 既定は login へ redirect し `status` を flash するが、flash-to-toast は
+ * status を意図的に gating する。リセット完了を login 画面で toast 表示するため
+ * web のみ `success` キーへ寄せる (AuthLayout も consumeFlash を持つ)。
+ * JSON 分岐は Fortify 既定 (trans(status) メッセージ) を維持し API 契約を壊さない。
+ */
+final class PasswordResetResponse implements PasswordResetResponseContract
+{
+    private const string SUCCESS_MESSAGE = 'パスワードを変更しました。ログインしてください。';
+
+    /**
+     * Fortify は status 言語キー (passwords.reset) を constructor で渡す。
+     * JSON 応答では既定どおり localize した status を返し、web では汎用 success へ寄せる。
+     */
+    public function __construct(private readonly string $status) {}
+
+    /**
+     * @param  Request  $request
+     */
+    public function toResponse($request): JsonResponse|RedirectResponse
+    {
+        if ($request->expectsJson()) {
+            // __() は key に配列を渡すと array を返しうるため PHPStan Lv10 で array|string 推論。
+            // status は必ず単一言語キーのため (string) で明示 narrow する。
+            return new JsonResponse(['message' => (string) __($this->status)], 200);
+        }
+
+        // Fortify 既定式に完全準拠 (views 無効=API 専用構成でも login 未定義で落ちない)
+        return redirect(Fortify::redirects('password-reset', config('fortify.views', true) ? route('login') : null))
+            ->with('success', self::SUCCESS_MESSAGE);
+    }
+}
diff --git a/app/Http/Responses/Fortify/PasswordUpdatedResponse.php b/app/Http/Responses/Fortify/PasswordUpdatedResponse.php
new file mode 100644
index 0000000..59bff29
--- /dev/null
+++ b/app/Http/Responses/Fortify/PasswordUpdatedResponse.php
@@ -0,0 +1,35 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Http\Responses\Fortify;
+
+use Illuminate\Http\JsonResponse;
+use Illuminate\Http\RedirectResponse;
+use Illuminate\Http\Request;
+use Laravel\Fortify\Contracts\PasswordUpdateResponse as PasswordUpdateResponseContract;
+
+/**
+ * パスワード変更後のレスポンス (Fortify contract bind)。
+ *
+ * Fortify 既定は `back()->with('status', ...)` を返すが、flash-to-toast は
+ * status を意図的に gating (toast 化しない)。変更完了を toast でフィードバック
+ * するため web のみ `success` キーへ寄せる。expectsJson (XHR / API) は
+ * Fortify 既定どおり JSON 200 を維持する。
+ */
+final class PasswordUpdatedResponse implements PasswordUpdateResponseContract
+{
+    private const string SUCCESS_MESSAGE = 'パスワードを変更しました。';
+
+    /**
+     * @param  Request  $request
+     */
+    public function toResponse($request): JsonResponse|RedirectResponse
+    {
+        if ($request->expectsJson()) {
+            return new JsonResponse('', 200);
+        }
+
+        return back()->with('success', self::SUCCESS_MESSAGE);
+    }
+}
diff --git a/app/Http/Responses/Fortify/ProfileUpdatedResponse.php b/app/Http/Responses/Fortify/ProfileUpdatedResponse.php
new file mode 100644
index 0000000..2cbe554
--- /dev/null
+++ b/app/Http/Responses/Fortify/ProfileUpdatedResponse.php
@@ -0,0 +1,35 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Http\Responses\Fortify;
+
+use Illuminate\Http\JsonResponse;
+use Illuminate\Http\RedirectResponse;
+use Illuminate\Http\Request;
+use Laravel\Fortify\Contracts\ProfileInformationUpdatedResponse as ProfileInformationUpdatedResponseContract;
+
+/**
+ * プロフィール更新後のレスポンス (Fortify contract bind)。
+ *
+ * Fortify 既定は `back()->with('status', ...)` を返すが、flash-to-toast は
+ * status を意図的に gating (toast 化しない)。更新完了を toast でフィードバック
+ * するため web のみ `success` キーへ寄せる。expectsJson (XHR / API) は
+ * Fortify 既定どおり JSON 200 を維持する。
+ */
+final class ProfileUpdatedResponse implements ProfileInformationUpdatedResponseContract
+{
+    private const string SUCCESS_MESSAGE = 'プロフィールを更新しました。';
+
+    /**
+     * @param  Request  $request
+     */
+    public function toResponse($request): JsonResponse|RedirectResponse
+    {
+        if ($request->expectsJson()) {
+            return new JsonResponse('', 200);
+        }
+
+        return back()->with('success', self::SUCCESS_MESSAGE);
+    }
+}
diff --git a/app/Http/Responses/Fortify/RecoveryCodesGeneratedResponse.php b/app/Http/Responses/Fortify/RecoveryCodesGeneratedResponse.php
index 39708fa..ced9c7b 100644
--- a/app/Http/Responses/Fortify/RecoveryCodesGeneratedResponse.php
+++ b/app/Http/Responses/Fortify/RecoveryCodesGeneratedResponse.php
@@ -19,7 +19,7 @@
  */
 final class RecoveryCodesGeneratedResponse implements RecoveryCodesGeneratedResponseContract
 {
-    private const string SUCCESS_MESSAGE = 'リカバリコードを再生成しました。';
+    private const string SUCCESS_MESSAGE = 'リカバリコードを再生成しました。新しいコードを保管してください。';
 
     /**
      * @param  Request  $request
diff --git a/app/Providers/FortifyServiceProvider.php b/app/Providers/FortifyServiceProvider.php
index e8853c2..b773635 100644
--- a/app/Providers/FortifyServiceProvider.php
+++ b/app/Providers/FortifyServiceProvider.php
@@ -10,6 +10,9 @@
 use App\Actions\Fortify\UpdateUserProfileInformation;
 use App\Http\Responses\Fortify\EnumerationSafePasswordResetLinkResponse;
 use App\Http\Responses\Fortify\LoginResponse;
+use App\Http\Responses\Fortify\PasswordResetResponse;
+use App\Http\Responses\Fortify\PasswordUpdatedResponse;
+use App\Http\Responses\Fortify\ProfileUpdatedResponse;
 use App\Http\Responses\Fortify\RecoveryCodesGeneratedResponse;
 use App\Http\Responses\Fortify\RegisterResponse;
 use App\Http\Responses\Fortify\TwoFactorDisabledResponse;
@@ -28,6 +31,9 @@
 use Laravel\Fortify\Contracts\EmailVerificationNotificationSentResponse as EmailVerificationNotificationSentResponseContract;
 use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse as FailedPasswordResetLinkRequestResponseContract;
 use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
+use Laravel\Fortify\Contracts\PasswordResetResponse as PasswordResetResponseContract;
+use Laravel\Fortify\Contracts\PasswordUpdateResponse as PasswordUpdateResponseContract;
+use Laravel\Fortify\Contracts\ProfileInformationUpdatedResponse as ProfileInformationUpdatedResponseContract;
 use Laravel\Fortify\Contracts\RecoveryCodesGeneratedResponse as RecoveryCodesGeneratedResponseContract;
 use Laravel\Fortify\Contracts\RegisterResponse as RegisterResponseContract;
 use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse as SuccessfulPasswordResetLinkRequestResponseContract;
@@ -59,6 +65,12 @@ public function register(): void
         $this->app->singleton(TwoFactorDisabledResponseContract::class, TwoFactorDisabledResponse::class);
         $this->app->singleton(RecoveryCodesGeneratedResponseContract::class, RecoveryCodesGeneratedResponse::class);
         $this->app->singleton(EmailVerificationNotificationSentResponseContract::class, VerificationNotificationSentResponse::class);
+        // profile / password 更新は success flash に統一し保存完了を toast 化する
+        // (status キーは flash-to-toast が gating するため toast にならない)。
+        $this->app->singleton(ProfileInformationUpdatedResponseContract::class, ProfileUpdatedResponse::class);
+        $this->app->singleton(PasswordUpdateResponseContract::class, PasswordUpdatedResponse::class);
+        // password reset は Fortify が constructor に status を渡して make するため bind (非 singleton)
+        $this->app->bind(PasswordResetResponseContract::class, PasswordResetResponse::class);
         // forgot-password は成功/失敗の両契約を enumeration-safe な同一応答へ差し替える。
         // Fortify は constructor に status を渡して make するため bind (非 singleton)
         $this->app->bind(SuccessfulPasswordResetLinkRequestResponseContract::class, EnumerationSafePasswordResetLinkResponse::class);
diff --git a/resources/js/pages/Settings/Security.svelte b/resources/js/pages/Settings/Security.svelte
index f87b6ef..0daf0a8 100644
--- a/resources/js/pages/Settings/Security.svelte
+++ b/resources/js/pages/Settings/Security.svelte
@@ -124,29 +124,30 @@
 
     /* ---- リカバリコード再生成 (F-10) ----
        POST 成功 = 旧コードは既に失効。表示中の旧コードを即クリアしてから GET で
-       新コードを取得し、成功時のみ成功トースト + 一覧へフォーカス (再保管を促す)。
-       GET 失敗時は「旧コードは無効」を明示し、既存の「リカバリコードを表示」ボタンが
-       再試行導線になる (recoveryCodes が空に戻るため自然に表示される)。 */
+       新コードを取得し、成功時は一覧へフォーカスする (再保管を促す)。成功 toast は
+       サーバ flash (RecoveryCodesGeneratedResponse) を単一の源とし client では出さない
+       (二重発火 F-L1 の解消)。GET 失敗時は「再生成は成功／表示取得が失敗」を明示し、
+       既存の「リカバリコードを表示」ボタンが再試行導線になる (recoveryCodes が空に戻る)。 */
     let regenerateDialogOpen = $state(false);
     let regenerating = $state(false);
 
     /** POST 成功後の後処理 (旧コードは既に失効している前提)。 */
     async function handleRegenerateSuccess(): Promise<void> {
         regenerateDialogOpen = false;
-        // 旧コードは失効済み。誤保管を防ぐため画面から即クリアする
+        // 旧コードは失効済み。誤保管を防ぐため画面から即クリアする。
+        // 成功 toast はサーバ flash (RecoveryCodesGeneratedResponse) が単一の源として出す
+        // (二重発火 F-L1 の解消)。ここでは client 楽観 toast を出さない。
         recoveryCodes = [];
         if (await loadRecoveryCodes()) {
-            addToast(
-                "success",
-                "リカバリコードを再生成しました。新しいコードを保管してください。",
-            );
             await tick();
             recoveryCodesPanel?.focus();
             return;
         }
+        // GET 失敗は「表示取得の失敗」= 再生成成功とは別事象。成功 toast と並んでも
+        // 矛盾しないよう対象を明示する。
         addToast(
             "error",
-            "新しいコードの取得に失敗しました。以前のコードは既に無効です。「リカバリコードを表示」から再取得してください。",
+            "リカバリコードは再生成されましたが、新しいコードの表示取得に失敗しました。旧コードは既に無効です。「リカバリコードを表示」から再取得してください。",
         );
     }
 
diff --git a/tests/Feature/Auth/FortifyResponseTest.php b/tests/Feature/Auth/FortifyResponseTest.php
index b9acd37..631fd4a 100644
--- a/tests/Feature/Auth/FortifyResponseTest.php
+++ b/tests/Feature/Auth/FortifyResponseTest.php
@@ -5,6 +5,7 @@
 use App\Models\User;
 use Illuminate\Auth\Notifications\VerifyEmail;
 use Illuminate\Support\Facades\Notification;
+use Illuminate\Support\Facades\Password;
 
 /*
  * Fortify Response contract bind (app/Http/Responses/Fortify/) の応答契約の正本。
@@ -72,3 +73,123 @@
     $response->assertStatus(202);
     Notification::assertSentTo($user, VerifyEmail::class);
 });
+
+test('プロフィール更新は success flash を返す (web)', function (): void {
+    $user = User::factory()->create();
+
+    // email は現状維持 (同一 email 分岐で通知/再検証を発火させず flash 契約に集中)
+    $response = $this->actingAs($user)
+        ->from('/settings')
+        ->put('/user/profile-information', [
+            'name' => '更新後の名前',
+            'email' => $user->email,
+        ]);
+
+    $response->assertRedirect('/settings');
+    $response->assertSessionHas('success', 'プロフィールを更新しました。');
+    $response->assertSessionMissing('status');
+});
+
+test('プロフィール更新は JSON リクエストに 200 を返す', function (): void {
+    $user = User::factory()->create();
+
+    $response = $this->actingAs($user)
+        ->putJson('/user/profile-information', [
+            'name' => '更新後の名前',
+            'email' => $user->email,
+        ]);
+
+    $response->assertStatus(200);
+});
+
+test('パスワード変更は success flash を返す (web)', function (): void {
+    $user = User::factory()->create();
+
+    $response = $this->actingAs($user)
+        ->from('/settings')
+        ->put('/user/password', [
+            'current_password' => 'password',
+            'password' => 'NewPassword123',
+        ]);
+
+    $response->assertRedirect('/settings');
+    $response->assertSessionHas('success', 'パスワードを変更しました。');
+    $response->assertSessionMissing('status');
+});
+
+test('パスワード変更は JSON リクエストに 200 を返す', function (): void {
+    $user = User::factory()->create();
+
+    $response = $this->actingAs($user)
+        ->putJson('/user/password', [
+            'current_password' => 'password',
+            'password' => 'NewPassword123',
+        ]);
+
+    $response->assertStatus(200);
+});
+
+test('パスワードリセットは success flash + login redirect を返す (web)', function (): void {
+    $user = User::factory()->create();
+    $token = Password::createToken($user);
+
+    $response = $this->from('/reset-password')->post('/reset-password', [
+        'token' => $token,
+        'email' => $user->email,
+        'password' => 'NewPassword123',
+        'password_confirmation' => 'NewPassword123',
+    ]);
+
+    $response->assertRedirect(route('login'));
+    $response->assertSessionHas('success', 'パスワードを変更しました。ログインしてください。');
+    $response->assertSessionMissing('status');
+});
+
+test('パスワードリセットは JSON リクエストに 200 + message を返す', function (): void {
+    $user = User::factory()->create();
+    $token = Password::createToken($user);
+
+    $response = $this->postJson('/reset-password', [
+        'token' => $token,
+        'email' => $user->email,
+        'password' => 'NewPassword123',
+        'password_confirmation' => 'NewPassword123',
+    ]);
+
+    $response->assertOk();
+    $response->assertJsonPath('message', __('passwords.reset'));
+});
+
+test('パスワードリセットは不正 token では success flash を出さない (非回帰)', function (): void {
+    $user = User::factory()->create();
+
+    $response = $this->from('/reset-password')->post('/reset-password', [
+        'token' => 'invalid-token-string',
+        'email' => $user->email,
+        'password' => 'NewPassword123',
+        'password_confirmation' => 'NewPassword123',
+    ]);
+
+    $response->assertSessionHasErrors();
+    $response->assertSessionMissing('success');
+});
+
+test('パスワードリセットは期限切れ token では success flash を出さない (非回帰)', function (): void {
+    $user = User::factory()->create();
+    $token = Password::createToken($user);
+
+    // token 有効期限 (config auth.passwords.users.expire=60分) を超過させる
+    $this->travel(61)->minutes();
+
+    $response = $this->from('/reset-password')->post('/reset-password', [
+        'token' => $token,
+        'email' => $user->email,
+        'password' => 'NewPassword123',
+        'password_confirmation' => 'NewPassword123',
+    ]);
+
+    $this->travelBack();
+
+    $response->assertSessionHasErrors();
+    $response->assertSessionMissing('success');
+});
diff --git a/tests/js/pages/SettingsSecurity.test.ts b/tests/js/pages/SettingsSecurity.test.ts
index cc332d3..3100a90 100644
--- a/tests/js/pages/SettingsSecurity.test.ts
+++ b/tests/js/pages/SettingsSecurity.test.ts
@@ -7,7 +7,7 @@ import Security from "@/pages/Settings/Security.svelte";
  * - 2FA 有効時のみ再生成ボタンが出る (非権限者非表示)
  * - ConfirmDialog 経由でのみ POST される
  * - 再生成 / 表示は recent-auth precheck 込み (stale なら再認証モーダル、POST しない)
- * - POST 成功 → GET 成功: 新コード表示 + success トースト
+ * - POST 成功 → GET 成功: 新コード表示 (success トーストはサーバ flash 委譲。client では出さない)
  * - POST 成功 → GET 失敗: 旧コード非表示のまま error トースト + 再試行導線
  * - disabled 不使用 (AGENTS.md 禁止事項 8)
  */
@@ -201,7 +201,7 @@ describe("Settings/Security リカバリコード再生成 (F-10)", () => {
         });
     });
 
-    it("POST 成功 → GET 成功で新コードを表示し success トーストを出す", async () => {
+    it("POST 成功 → GET 成功で新コードを表示する (success トーストはサーバ flash 委譲。client では出さない)", async () => {
         stubFetchRoutes({ recent: true, codes: ["new-code-1", "new-code-2"], codesOk: true });
         render(Security, { props: {} });
 
@@ -214,10 +214,9 @@ describe("Settings/Security リカバリコード再生成 (F-10)", () => {
         await waitFor(() => {
             expect(screen.getByTestId("recovery-codes")).toHaveTextContent("new-code-1");
         });
-        expect(addToastMock).toHaveBeenCalledWith(
-            "success",
-            expect.stringContaining("再生成しました"),
-        );
+        // 成功 toast はサーバ flash (RecoveryCodesGeneratedResponse) が単一の源。
+        // client 楽観 toast は出さない (二重発火 F-L1 の解消)。
+        expect(addToastMock).not.toHaveBeenCalledWith("success", expect.anything());
     });
 
     it("POST 成功 → GET 失敗では旧コードを残さず error トースト + 再試行導線に戻る", async () => {
@@ -233,9 +232,13 @@ describe("Settings/Security リカバリコード再生成 (F-10)", () => {
         await waitFor(() => {
             expect(addToastMock).toHaveBeenCalledWith(
                 "error",
-                expect.stringContaining("以前のコードは既に無効です"),
+                expect.stringContaining("再生成されました"),
             );
         });
+        expect(addToastMock).toHaveBeenCalledWith(
+            "error",
+            expect.stringContaining("表示取得に失敗"),
+        );
         expect(screen.queryByTestId("recovery-codes")).toBeNull();
         expect(screen.getByTestId("show-recovery-codes-button")).toBeInTheDocument();
     });

```

上記を踏まえ、ファイルごとの判定と全体判定 (APPROVED / CHANGES_REQUESTED) を出せ。
