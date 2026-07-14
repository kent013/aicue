## Round 1 指摘への対応 (impl-review)

Round 1 全体判定は CHANGES_REQUESTED。唯一の Critical (+ 関連 Warning) に対応した。使命・禁止事項・思考原則は Round 1 と同一のため再掲しない。

### 対応内容

**[Critical] no-store 条件が `!== null` のみで S2↔S3 契約が暗黙・脆い**
→ 3 点で対応:

1. `FortifyServiceProvider`: no-store 付与条件を `$invitationEmail !== null && $invitationEmail !== ''` に変更。frontend の `isInvited`（`invitationEmail != null && invitationEmail !== ""`）と判定基準を対称化し、「PII 実在 = 非空 email 文字列」に意味論を揃えた。null 判定だけの暗黙契約への依存を解消。

2. `OrganizationMembershipService::resolveRegisterPrefillEmail` の PHPDoc に **戻り契約** を明文化: 「非 null を返す場合は必ず非空の email 文字列である (空文字は null に潰す)。呼び出し側 (Fortify registerView の no-store 判定 / frontend の isInvited) はこの契約に依存する」。resolver 本体は既存どおり `$email === '' → forget + null`。

3. `RegistrationInvitationPrefillTest` に契約テストを 1 件追加（計 9 テスト）: 「空 email の active 招待 → resolver は null を返し token を forget する (S2↔S3 契約: 非 null=非空)」。`ArraySessionHandler` backed の `Session\Store` に token を積んで `resolveRegisterPrefillEmail` を直接呼び、null + `!has('invitation_token')` を assert。

その他 Round 1 の OK 判定ファイルは変更なし。Suggestion「toResponse 明示・header 位置は適切」は現状維持。

### 品質ゲート (全て単独実行で green)
- `composer phpstan`: OK (level 10, No errors)
- `composer test` (--parallel, 全体・単独実行): 1766 passed / 2 skipped / **0 failed**
- `pnpm test` (JS 全体・単独実行): **653 passed / 0 failed** (76 files)
- `vendor/bin/pint --test` / `pnpm lint` / `pnpm typecheck` / `pnpm build`: すべて passed

### 変更差分 (Round 1 以降の該当 3 ファイル)

```diff
diff --git a/app/Providers/FortifyServiceProvider.php b/app/Providers/FortifyServiceProvider.php
index 0e2d6d9..6424b96 100644
--- a/app/Providers/FortifyServiceProvider.php
+++ b/app/Providers/FortifyServiceProvider.php
@@ -17,6 +17,7 @@
 use App\Http\Responses\Fortify\RegisterResponse;
 use App\Http\Responses\Fortify\TwoFactorDisabledResponse;
 use App\Http\Responses\Fortify\VerificationNotificationSentResponse;
+use App\Services\Organization\OrganizationMembershipService;
 use Illuminate\Cache\RateLimiting\Limit;
 use Illuminate\Contracts\Foundation\Application;
 use Illuminate\Http\RedirectResponse;
@@ -40,6 +41,7 @@
 use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse as SuccessfulPasswordResetLinkRequestResponseContract;
 use Laravel\Fortify\Contracts\TwoFactorDisabledResponse as TwoFactorDisabledResponseContract;
 use Laravel\Fortify\Fortify;
+use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
 
 class FortifyServiceProvider extends ServiceProvider
 {
@@ -177,9 +179,28 @@ private function configureViews(): void
             'socialProviders' => array_keys(config()->array('template.social_providers')),
         ]));
 
-        Fortify::registerView(static fn (): InertiaResponse => Inertia::render('Auth/Register', [
-            'socialProviders' => array_keys(config()->array('template.social_providers')),
-        ]));
+        Fortify::registerView(static function (Request $request): SymfonyResponse {
+            // 招待リンク経由 (session に active token) の場合のみ招待先 email を prefill 用に解決する。
+            // resolver 内で stale/invalid token は session から破棄される (fail-secure)。
+            $invitationEmail = app(OrganizationMembershipService::class)
+                ->resolveRegisterPrefillEmail($request->session());
+
+            $response = Inertia::render('Auth/Register', [
+                'socialProviders' => array_keys(config()->array('template.social_providers')),
+                'invitationEmail' => $invitationEmail,
+            ])->toResponse($request);
+
+            // PII (招待先 email) を含む応答を HTTP キャッシュ (共有/中間プロキシ/ブラウザの
+            // HTTP キャッシュ) に保存させない (bearer token 由来 PII の運用 fail-safe)。
+            // email を含まない通常登録応答には付けない (不要なキャッシュ抑止を避ける)。
+            // 「PII 実在 = 非空 email 文字列」で判定する (resolver 契約と frontend の isInvited
+            //  = invitationEmail != null && !== "" に揃え、null 判定だけの暗黙契約に依存しない)。
+            if ($invitationEmail !== null && $invitationEmail !== '') {
+                $response->headers->set('Cache-Control', 'no-store');
+            }
+
+            return $response;
+        });
 
         Fortify::requestPasswordResetLinkView(
             static fn (): InertiaResponse => Inertia::render('Auth/ForgotPassword'),
diff --git a/app/Services/Organization/OrganizationMembershipService.php b/app/Services/Organization/OrganizationMembershipService.php
index ff2edc4..2552ebd 100644
--- a/app/Services/Organization/OrganizationMembershipService.php
+++ b/app/Services/Organization/OrganizationMembershipService.php
@@ -15,6 +15,7 @@
 use App\Services\Notification\NotificationCenterService;
 use App\Services\Project\DefaultProjectResolver;
 use App\Services\Security\SecurityEventRecorder;
+use Illuminate\Contracts\Session\Session;
 use Illuminate\Database\Eloquent\Model;
 use Illuminate\Support\Collection;
 use Illuminate\Support\Facades\DB;
@@ -146,12 +147,9 @@ public function acceptInvitation(string $plainToken, User $user): Organization
      */
     public function acceptInvitationIfValid(string $plainToken, User $user): ?Organization
     {
-        $invitation = OrganizationInvitation::query()
-            ->where('token_hash', OrganizationInvitation::hashToken($plainToken))
-            ->first();
-
-        // active (未受諾・未失効・期限内) でなければ join しない
-        if ($invitation === null || $invitation->isRevoked() || $invitation->isAccepted() || $invitation->isExpired()) {
+        // active (未受諾・未失効・期限内) 解決は findActiveByPlainToken に集約 (単一解決口)。
+        $invitation = OrganizationInvitation::findActiveByPlainToken($plainToken);
+        if ($invitation === null) {
             return null;
         }
 
@@ -184,6 +182,52 @@ public function acceptInvitationIfValid(string $plainToken, User $user): ?Organi
         return $organization;
     }
 
+    /**
+     * register 画面のメール prefill 用に、session の invitation_token から
+     * 「active な招待の招待先 email」を解決する。fail-secure:
+     *  - session 値が非文字列/空 → forget して null
+     *  - findActiveByPlainToken が null (不在/失効/取消/受諾済) → session から forget して null
+     *    (GET 時点で stale/invalid な token を破棄し「UI は通常登録・サーバは招待フロー」の
+     *    不整合を除去する)
+     *  - active → 招待先 email (CipherSweet 自動復号後は string) を返す
+     *
+     * 平文 email 検索は行わない (token_hash 照合のみ)。列挙面を広げない。
+     * 正常系 (active) では forget しない: 後続 POST の CreateNewUser が受諾に token を使う。
+     *
+     * **戻り契約**: 非 null を返す場合は必ず非空の email 文字列である (空文字は null に潰す)。
+     * 呼び出し側 (Fortify registerView の no-store 判定 / frontend の isInvited) はこの契約に依存する。
+     */
+    public function resolveRegisterPrefillEmail(Session $session): ?string
+    {
+        $raw = $session->get('invitation_token');
+
+        if (! is_string($raw) || $raw === '') {
+            if ($raw !== null) {
+                $session->forget('invitation_token'); // 汚染値を除去
+            }
+
+            return null;
+        }
+
+        $invitation = OrganizationInvitation::findActiveByPlainToken($raw);
+        if ($invitation === null) {
+            $session->forget('invitation_token'); // stale/invalid を GET 時点で破棄
+
+            return null;
+        }
+
+        // CipherSweet 復号後の email。空文字 (想定外の欠損) は fail-secure に握り、
+        // token を破棄して null 返却する (prefill しない)。
+        $email = $invitation->email;
+        if ($email === '') {
+            $session->forget('invitation_token');
+
+            return null;
+        }
+
+        return $email;
+    }
+
     /**
      * 招待の取り消し (論理失効)。行削除ではなく revoked_at を立てる (監査痕跡を残す)。
      * 既に失効/受諾済みなら冪等 no-op (二重取り消しを例外にしない)。
diff --git a/tests/Feature/Auth/RegistrationInvitationPrefillTest.php b/tests/Feature/Auth/RegistrationInvitationPrefillTest.php
new file mode 100644
index 0000000..d6e48b4
--- /dev/null
+++ b/tests/Feature/Auth/RegistrationInvitationPrefillTest.php
@@ -0,0 +1,186 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Models\Organization;
+use App\Models\OrganizationInvitation;
+use App\Models\User;
+use App\Services\Billing\TicketLedgerService;
+use App\Services\Organization\OrganizationMembershipService;
+use Illuminate\Session\ArraySessionHandler;
+use Illuminate\Session\Store as SessionStore;
+use Inertia\Testing\AssertableInertia;
+
+/**
+ * 招待経由の register 画面での招待 email prefill (T055)。
+ *
+ * - active token を session に持つ GET /register は招待先 email を prop `invitationEmail` に返し、
+ *   PII を含むため応答に Cache-Control: no-store を付ける。active token は session に維持される
+ *   (後続 POST の受諾に必要)。
+ * - stale/invalid token (失効/取消/受諾済/不在/非文字列) は GET 時点で null + session forget。
+ * - token 無し (通常登録) は prop null かつ no-store を付けない (非退行)。
+ */
+
+/**
+ * 招待先 email に固定した active 招待を作り、平文 token を session に載せた状態を作る。
+ *
+ * @return array{OrganizationInvitation, string, string, Organization}
+ */
+function makeInvitationWithToken(string $email = 'invitee@example.com'): array
+{
+    [$organization] = createOrganizationWithOwner();
+    /** @var OrganizationInvitation $invitation */
+    [$invitation, $token] = OrganizationInvitation::factory()
+        ->forOrganization($organization)
+        ->createWithPlainToken(['email' => $email]);
+
+    return [$invitation, $token, $email, $organization];
+}
+
+test('active token を session に持つ GET /register は招待 email を prefill し no-store を付け token を維持する', function (): void {
+    [, $token, $email] = makeInvitationWithToken();
+
+    $response = $this->withSession(['invitation_token' => $token])->get('/register');
+
+    $response->assertOk()
+        ->assertInertia(
+            fn (AssertableInertia $page) => $page
+                ->component('Auth/Register')
+                ->where('invitationEmail', $email)
+                ->has('socialProviders'),
+        );
+
+    // PII を含むため HTTP キャッシュへの保存を禁止する
+    expect($response->headers->get('Cache-Control'))->toContain('no-store');
+
+    // active token は POST 受諾のため session に維持される (GET で forget しない)
+    $response->assertSessionHas('invitation_token', $token);
+});
+
+test('expired token → invitationEmail null かつ session から forget', function (): void {
+    [$organization] = createOrganizationWithOwner();
+    [, $token] = OrganizationInvitation::factory()
+        ->forOrganization($organization)
+        ->expired()
+        ->createWithPlainToken(['email' => 'invitee@example.com']);
+
+    $response = $this->withSession(['invitation_token' => $token])->get('/register');
+
+    $response->assertOk()
+        ->assertInertia(fn (AssertableInertia $page) => $page->where('invitationEmail', null));
+    $response->assertSessionMissing('invitation_token');
+});
+
+test('revoked token → invitationEmail null かつ forget', function (): void {
+    [$organization] = createOrganizationWithOwner();
+    [, $token] = OrganizationInvitation::factory()
+        ->forOrganization($organization)
+        ->revoked()
+        ->createWithPlainToken(['email' => 'invitee@example.com']);
+
+    $response = $this->withSession(['invitation_token' => $token])->get('/register');
+
+    $response->assertOk()
+        ->assertInertia(fn (AssertableInertia $page) => $page->where('invitationEmail', null));
+    $response->assertSessionMissing('invitation_token');
+});
+
+test('accepted token → invitationEmail null かつ forget', function (): void {
+    [$organization] = createOrganizationWithOwner();
+    [, $token] = OrganizationInvitation::factory()
+        ->forOrganization($organization)
+        ->accepted()
+        ->createWithPlainToken(['email' => 'invitee@example.com']);
+
+    $response = $this->withSession(['invitation_token' => $token])->get('/register');
+
+    $response->assertOk()
+        ->assertInertia(fn (AssertableInertia $page) => $page->where('invitationEmail', null));
+    $response->assertSessionMissing('invitation_token');
+});
+
+test('存在しない token (DB 不在) → invitationEmail null かつ forget', function (): void {
+    $response = $this->withSession(['invitation_token' => 'nonexistent-token'])->get('/register');
+
+    $response->assertOk()
+        ->assertInertia(fn (AssertableInertia $page) => $page->where('invitationEmail', null));
+    $response->assertSessionMissing('invitation_token');
+});
+
+test('非文字列 session 値 (配列) → invitationEmail null かつ forget (fail-secure)', function (): void {
+    $response = $this->withSession(['invitation_token' => ['tampered']])->get('/register');
+
+    $response->assertOk()
+        ->assertInertia(fn (AssertableInertia $page) => $page->where('invitationEmail', null));
+    $response->assertSessionMissing('invitation_token');
+});
+
+test('token 無し GET /register は invitationEmail null・socialProviders あり・no-store を付けない', function (): void {
+    $response = $this->get('/register');
+
+    $response->assertOk()
+        ->assertInertia(
+            fn (AssertableInertia $page) => $page
+                ->component('Auth/Register')
+                ->where('invitationEmail', null)
+                ->has('socialProviders'),
+        );
+
+    // PII を含まない通常応答には no-store を付けない (不要なキャッシュ抑止を避ける)
+    expect((string) $response->headers->get('Cache-Control'))->not->toContain('no-store');
+});
+
+test('resolver は空 email の active 招待では null を返し token を forget する (S2↔S3 契約: 非null=非空)', function (): void {
+    [$organization] = createOrganizationWithOwner();
+    // 想定外の欠損 (空 email) を持つ active 招待でも prefill しない = 非空契約を固定する
+    [, $token] = OrganizationInvitation::factory()
+        ->forOrganization($organization)
+        ->createWithPlainToken(['email' => '']);
+
+    $session = new SessionStore('test-session', new ArraySessionHandler(60));
+    $session->put('invitation_token', $token);
+
+    $email = app(OrganizationMembershipService::class)->resolveRegisterPrefillEmail($session);
+
+    expect($email)->toBeNull();
+    expect($session->has('invitation_token'))->toBeFalse();
+});
+
+test('GET で active prefill 後 POST 前に revoke されても登録は成立し個人組織へ fallback する', function (): void {
+    [$invitation, $token, $email, $organization] = makeInvitationWithToken('fallback@example.com');
+
+    // GET: active なので prefill され token は維持される
+    $this->withSession(['invitation_token' => $token])->get('/register')
+        ->assertOk()
+        ->assertInertia(fn (AssertableInertia $page) => $page->where('invitationEmail', $email));
+
+    // POST 前に招待が取り消される
+    $invitation->forceFill(['revoked_at' => now()])->save();
+
+    // POST: MatchesInvitationEmail は no-op (active 不在) → 登録成立 → 招待受諾は null → 個人組織 fallback
+    $response = $this->withSession(['invitation_token' => $token])->post('/register', [
+        'name' => '山田 太郎',
+        'email' => $email,
+        'password' => 'SecurePass1234',
+        'terms_accepted' => '1',
+    ]);
+
+    $response->assertRedirect(route('verification.notice'));
+    $this->assertAuthenticated();
+
+    $user = User::whereBlind('email', 'email_index', $email)->firstOrFail();
+
+    // 招待組織のメンバーシップには含まれない
+    expect($organization->users()->whereKey($user->getKey())->exists())->toBeFalse();
+
+    // 個人組織が生成され signup grant 済み
+    $personalOrg = $user->organizations()->where('is_personal', true)->firstOrFail();
+    expect(app(TicketLedgerService::class)->balance($personalOrg))
+        ->toBe(config()->integer('billing.signup_grant_tickets'));
+
+    // current_organization_id は個人組織側 (招待組織側でない)
+    expect($user->current_organization_id)->toBe($personalOrg->id);
+
+    // session の invitation_token は登録確定で forget されている
+    $response->assertSessionMissing('invitation_token');
+});
```
