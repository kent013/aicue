# 実装レビュー依頼: T011 confirm-password 直アクセス 500 の修正 (bug-hunt F-11)

## アプリの使命 (North Star)

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

## 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)

## 思考原則 — 全議論に適用

まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

## ツール使用制限

コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

## あなたの役割 (system)

あなたはシニア Laravel/Fortify エンジニアとして、以下の実装 diff の**最終実装レビュー**を行う。
既に概念設計レビュー・詳細設計レビュー (Round 1-2) を通過済みの実装であり、これはマージ前の最終確認である。

観点:
1. **正しさ**: 500 (BindingResolutionException) の根本原因に対して修正は正しいか。redirect 先・HTTP セマンティクスは妥当か
2. **セキュリティ**: この救済 redirect が `password.confirm` middleware 互換 (auth.password_confirmed_at) や recent-auth 鮮度 (recent_auth_at) を誤って付与しないこと。認可バイパス・open redirect が無いこと
3. **回帰リスク**: Fortify の他ルート (POST /user/confirm-password 等) や既存 recent-auth フローへの影響
4. **テスト充分性**: 追加された 4 テストがバグの再発と誤用 (stamp 付与) を検出できるか

出力形式: 指摘を [Critical] / [Warning] / [Suggestion] に分類して列挙。指摘が無い分類は「なし」と明記。最後に総合判定 (マージ可 / 要修正) を1行で書く。

必要ならリポジトリ `/workspace/.claude/worktrees/tasks/T011` 配下のファイルを読んでよい
(特に `app/Providers/FortifyServiceProvider.php`, `app/Http/Controllers/Auth/RecentAuthController.php`,
`tests/Feature/Auth/RecentAuthTest.php`, `routes/web.php`, `config/fortify.php`)。

---

## レビュー対象 (user)

### 背景

bug-hunt F-11: ログイン済みユーザが `GET /user/confirm-password` に直アクセスすると 500。
原因: `config/fortify.php` で `views=true` のため Fortify が該当 GET ルートを無条件登録するが、
本アプリは Fortify 生の password.confirm step-up を generic recent-auth (`recent-auth.confirm`,
画面 `Auth/ConfirmRecentAuth`) に置換済みで `confirmPasswordView` を未登録だったため、
`ConfirmPasswordViewResponse` の解決が `BindingResolutionException` になる。

### 修正 diff (main...todo/T011)

```diff
diff --git a/app/Providers/FortifyServiceProvider.php b/app/Providers/FortifyServiceProvider.php
index eb0244f..d0fbf69 100644
--- a/app/Providers/FortifyServiceProvider.php
+++ b/app/Providers/FortifyServiceProvider.php
@@ -14,6 +14,7 @@
 use App\Http\Responses\Fortify\RegisterResponse;
 use App\Http\Responses\Fortify\TwoFactorDisabledResponse;
 use Illuminate\Cache\RateLimiting\Limit;
+use Illuminate\Http\RedirectResponse;
 use Illuminate\Http\Request;
 use Illuminate\Support\Facades\RateLimiter;
 use Illuminate\Support\ServiceProvider;
@@ -104,8 +105,17 @@ private function configureViews(): void

         Fortify::verifyEmailView(static fn (): InertiaResponse => Inertia::render('Auth/VerifyEmail'));

-        // password.confirm (Fortify 生 step-up) は generic recent-auth に置換済みのため
-        // confirmPasswordView は登録しない (確認画面は Auth/ConfirmRecentAuth)。
+        // password.confirm (Fortify 生 step-up) は generic recent-auth に置換済み。
+        // ただし fortify.views=true の間は GET /user/confirm-password が Fortify により
+        // 無条件登録され、ConfirmPasswordViewResponse 未 bind だと直アクセスが
+        // BindingResolutionException で 500 になる (bug-hunt F-11)。正規の確認画面
+        // (recent-auth.confirm、password or 再SSO) へ 302 で誘導する。
+        // 注意: これは GET view の救済 redirect であり、`password.confirm` middleware 互換
+        // (auth.password_confirmed_at の充足) は提供しない。middleware 互換が必要になったら
+        // 別途設計すること (config/fortify.php の TODO(template) 参照)。
+        Fortify::confirmPasswordView(
+            static fn (): RedirectResponse => redirect()->route('recent-auth.confirm'),
+        );

         Fortify::twoFactorChallengeView(static fn (): InertiaResponse => Inertia::render('Auth/TwoFactorChallenge'));
     }
diff --git a/tests/Feature/Auth/RecentAuthTest.php b/tests/Feature/Auth/RecentAuthTest.php
index 3fc6f7c..5d3da9c 100644
--- a/tests/Feature/Auth/RecentAuthTest.php
+++ b/tests/Feature/Auth/RecentAuthTest.php
@@ -95,6 +95,45 @@ function linkGoogleAccount(User $user, string $providerUserId): void
     expect(session('url.intended'))->toBe(route('dashboard'));
 });

+/* ------------------------------------------- fortify password.confirm 救済 redirect */
+
+test('GET /user/confirm-password 直アクセスは recent-auth confirm へ 302 (500 にしない)', function (): void {
+    $user = User::factory()->create();
+
+    $response = $this->actingAs($user)->get('/user/confirm-password');
+
+    $response->assertRedirect(route('recent-auth.confirm'));
+});
+
+test('GET /user/confirm-password は追従すると 200 で ConfirmRecentAuth フォームが出る', function (): void {
+    $user = User::factory()->create();
+
+    $response = $this->actingAs($user)->followingRedirects()->get('/user/confirm-password');
+
+    $response->assertOk()
+        ->assertInertia(fn ($page) => $page
+            ->component('Auth/ConfirmRecentAuth')
+            ->where('passwordSet', true)
+            ->where('canSatisfy', true));
+});
+
+test('GET /user/confirm-password は未ログインなら login へ redirect (既存 auth ガード)', function (): void {
+    $this->get('/user/confirm-password')->assertRedirect(route('login'));
+});
+
+test('GET /user/confirm-password の救済 redirect は再認証の stamp をしない', function (): void {
+    // 誤用防止の回帰ガード: この redirect は「画面への誘導」であり、password.confirm
+    // middleware 互換 (auth.password_confirmed_at) も recent-auth 鮮度 (recent_auth_at) も
+    // 付与しない (Codex 詳細レビュー Round 1 Warning 対応)。
+    $user = User::factory()->create();
+
+    $response = $this->actingAs($user)->get('/user/confirm-password');
+
+    $response->assertRedirect(route('recent-auth.confirm'))
+        ->assertSessionMissing('auth.password_confirmed_at')
+        ->assertSessionMissing('recent_auth_at');
+});
+
 /* ---------------------------------------------------------------- confirm 画面 / status */

 test('confirm 画面は passwordSet / availableProviders / canSatisfy を返す', function (): void {
```

### 検証結果

- composer test: passed (1515 tests, 1513 passed, 2 skipped)
- composer phpstan: No errors (619 files)
- vendor/bin/pint --test: passed
- pnpm lint / pnpm typecheck: passed
- pnpm build: passed

上記 diff とリポジトリの関連ファイルを踏まえ、最終実装レビューを行え。
