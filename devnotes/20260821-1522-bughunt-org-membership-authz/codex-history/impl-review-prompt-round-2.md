## Round 2: Round 1 の指摘への対応

Round 1 の [Warning] (recipientEmailMatches=false でも organizationName を payload 送信 = 組織名開示) を
対応した。加えて 2 件の [Suggestion] も対応した。対応マトリクスと修正差分を以下に示す。再判定せよ。

### 対応マトリクス

1. [Warning→実質 Critical] organizationName 開示: **対応**。
   - Controller: `organizationName => $recipientEmailMatches ? $organization->name : null` に変更し、
     不一致時は payload そのものから組織名を落とす。
   - Accept.svelte: Props を `organizationName: string | null` に変更。description の一致分岐でのみ参照。
   - Feature T3: `->where('organizationName', null)` を追加し payload 層での非開示を回帰固定。
2. [Suggestion] MemberRemovalAccessTest の表と assertion 不一致: **対応**。T7 (current=null) / T7b (stale current) に
   `get('/dashboard')->assertOk()` を追加し、表の dashboard=200 を実際に検証する範囲に含めた。
3. [Suggestion] InvitationsAccept.test.ts の責務: **対応**。DOM 表示契約である旨をコメントで明示し、
   payload 非開示は Feature T3 が担保することを分離記述。不一致テストの props も organizationName: null に更新。

### 検証結果 (修正後)
- `composer phpstan`: OK
- `pnpm typecheck`: OK
- `vendor/bin/pest InvitationTest MemberRemovalAccessTest`: 37 passed / 0 failed
- `pnpm test InvitationsAccept AdminUsers`: 28 passed

### 修正差分 (git diff HEAD の該当ファイルのみ)

```diff
diff --git a/app/Http/Controllers/Organizations/InvitationAcceptanceController.php b/app/Http/Controllers/Organizations/InvitationAcceptanceController.php
index 615ba399..e1c012bb 100644
--- a/app/Http/Controllers/Organizations/InvitationAcceptanceController.php
+++ b/app/Http/Controllers/Organizations/InvitationAcceptanceController.php
@@ -20,7 +20,8 @@
 /**
  * 招待受諾。GET (確認画面) は guest 可 (未ログインは register へ誘導)。POST (受諾) は auth 必須。
  * verified は要求しない (招待された直後の未検証ユーザーも受諾できる)。
- * 招待 email とログインユーザーの email の一致はログイン後経路では要求しない仕様。
+ * 受諾には受諾者 email と招待の宛先 email の一致を要求する (権威は
+ * OrganizationMembershipService。GET は補助 UX として不一致を事前表示する)。
  */
 class InvitationAcceptanceController extends Controller
 {
@@ -70,9 +71,21 @@ public function show(Request $request, SeoManager $seo): Response|RedirectRespon
         $organization = $invitation->organization;
         Assert::isInstanceOf($organization, Organization::class);
 
+        // 宛先 email 照合 (補助 UX)。権威は Service (acceptInvitation + joinOrganization)。
+        // prop 名は「email が一致するか」だけを表す (受諾可否の全条件ではない)。
+        // 規則は OrganizationInvitation::isAddressedTo に集約 (Controller は独自比較式を持たない)。
+        // $request->user() は上の guest 分岐で早期 return 済みだが PHPStan L10 のため narrow する。
+        $user = $request->user();
+        Assert::isInstanceOf($user, User::class);
+        $recipientEmailMatches = $invitation->isAddressedTo($user);
+
+        // 不一致時は organizationName を渡さない (null)。DOM で隠すだけでは初期 Inertia payload /
+        // devtools から非受信者が組織名を読めてしまうため、payload そのものから組織名を落とす
+        // (非受信者への組織の実在・名称の開示面を増やさない)。
         return Inertia::render('Invitations/Accept', [
-            'organizationName' => $organization->name,
+            'organizationName' => $recipientEmailMatches ? $organization->name : null,
             'token' => $token,
+            'recipientEmailMatches' => $recipientEmailMatches,
         ]);
     }
 
diff --git a/resources/js/pages/Invitations/Accept.svelte b/resources/js/pages/Invitations/Accept.svelte
index 0d2fa6e5..048e2a54 100644
--- a/resources/js/pages/Invitations/Accept.svelte
+++ b/resources/js/pages/Invitations/Accept.svelte
@@ -10,15 +10,24 @@
     import type { SharedProps } from "@/lib/shared-props";
 
     interface Props {
-        organizationName: string;
+        // 不一致時はサーバが null で渡す (payload から組織名を落とす = 非受信者へ開示しない)
+        organizationName: string | null;
         token: string;
+        recipientEmailMatches: boolean;
     }
 
-    let { organizationName, token }: Props = $props();
+    let { organizationName, token, recipientEmailMatches }: Props = $props();
 
     const shared = $derived(page.props as unknown as SharedProps);
     const appName = $derived(shared.appName ?? "");
 
+    // 一致時のみ組織名を含む description。不一致時は組織名に触れない (payload でも null)
+    const description = $derived(
+        recipientEmailMatches
+            ? `「${organizationName}」に招待されています。受諾するとこの組織のメンバーになります。`
+            : "この招待は別のメールアドレス宛に送信されています。",
+    );
+
     const form = useForm({ token });
 
     function submit(event: SubmitEvent): void {
@@ -31,17 +40,24 @@
     <PageContainer>
         <PageHeader
             title="組織への招待"
-            description={`「${organizationName}」に招待されています。受諾するとこの組織のメンバーになります。`}
+            {description}
             icon={UserPlus}
             testId="accept-invitation-heading"
         />
         <PageContent>
             <Card padding="lg">
-                <form novalidate onsubmit={submit}>
-                    <Button type="submit" loading={form.processing} testId="accept-invitation-button">
-                        招待を受諾する
-                    </Button>
-                </form>
+                {#if recipientEmailMatches}
+                    <form novalidate onsubmit={submit}>
+                        <Button type="submit" loading={form.processing} testId="accept-invitation-button">
+                            招待を受諾する
+                        </Button>
+                    </form>
+                {:else}
+                    <p class="text-body" data-testid="accept-invitation-mismatch">
+                        招待メールを受け取ったアドレスでログインし直してください。画面右上のメニューから
+                        ログアウトし、招待メールのリンクをもう一度開いてください。
+                    </p>
+                {/if}
             </Card>
         </PageContent>
     </PageContainer>
diff --git a/tests/Feature/Organization/InvitationTest.php b/tests/Feature/Organization/InvitationTest.php
index 97aa81d9..bb335ed7 100644
--- a/tests/Feature/Organization/InvitationTest.php
+++ b/tests/Feature/Organization/InvitationTest.php
@@ -12,7 +12,9 @@
 use App\Services\Organization\OrganizationMembershipService;
 use Illuminate\Notifications\AnonymousNotifiable;
 use Illuminate\Support\Facades\Notification;
+use Illuminate\Validation\ValidationException;
 use Inertia\Testing\AssertableInertia;
+use Webmozart\Assert\Assert;
 
 /*
  * 組織招待 (送信 / 受諾 / 拒否系)。
@@ -75,8 +77,11 @@ function (OrganizationInvitationNotification $notification) use (&$plainToken):
     [$organization, $owner] = createOrganizationWithOwner();
     $token = inviteAndCaptureToken($organization, $owner, 'invitee@example.com', OrganizationRole::Admin);
 
-    // 受諾するユーザーは別組織を現在組織に持つ (POST 受諾が現在組織を切り替えないことを固定するため)
-    [$otherOrg, $invitee] = createOrganizationWithOwner('受諾者の既存組織');
+    // 受諾するユーザーは別組織を現在組織に持つ (POST 受諾が現在組織を切り替えないことを固定するため)。
+    // email 照合の追加により受諾者 email を招待宛先に揃える。
+    $invitee = User::factory()->create(['email' => 'invitee@example.com']);
+    [$otherOrg] = createOrganizationWithOwner('受諾者の既存組織');
+    $otherOrg->users()->attach($invitee);
     $invitee->forceFill(['current_organization_id' => $otherOrg->id])->save();
     $before = $invitee->current_organization_id;
 
@@ -97,14 +102,15 @@ function (OrganizationInvitationNotification $notification) use (&$plainToken):
     [$organization, $owner] = createOrganizationWithOwner('受諾テスト組織');
     $token = inviteAndCaptureToken($organization, $owner, 'invitee@example.com', OrganizationRole::Admin);
 
-    $invitee = User::factory()->create();
+    $invitee = User::factory()->create(['email' => 'invitee@example.com']);
     $response = $this->actingAs($invitee)->get('/invitations/accept?token='.$token);
 
     $response->assertOk();
     $response->assertInertia(
         fn ($page) => $page->component('Invitations/Accept')
             ->where('organizationName', '受諾テスト組織')
-            ->where('token', $token),
+            ->where('token', $token)
+            ->where('recipientEmailMatches', true),
     );
 });
 
@@ -347,7 +353,7 @@ function (OrganizationInvitationNotification $notification) use (&$plainToken):
 test('有効な招待リンクの受諾確認画面は route 既定タイトルのまま', function (): void {
     [$organization, $owner] = createOrganizationWithOwner();
     $token = inviteAndCaptureToken($organization, $owner, 'valid-title@example.com', OrganizationRole::Admin);
-    $invitee = User::factory()->create();
+    $invitee = User::factory()->create(['email' => 'valid-title@example.com']);
 
     $this->actingAs($invitee)
         ->get('/invitations/accept?token='.$token)
@@ -515,7 +521,7 @@ function (OrganizationInvitationNotification $notification) use (&$plainToken):
     $project = Project::factory()->forOrganization($organization)->create();
     $token = inviteAndCaptureToken($organization, $owner, 'member@example.com', OrganizationRole::Member);
 
-    $invitee = User::factory()->create();
+    $invitee = User::factory()->create(['email' => 'member@example.com']);
     $this->actingAs($invitee)->post('/invitations/accept', ['token' => $token])
         ->assertRedirect('/dashboard');
 
@@ -547,10 +553,12 @@ function (OrganizationInvitationNotification $notification) use (&$plainToken):
     [$organization, $owner] = createOrganizationWithOwner();
     $token = inviteAndCaptureToken($organization, $owner, 'idempotent@example.com', OrganizationRole::Admin);
 
-    $first = User::factory()->create();
+    // 1 人目は招待宛先 email に揃えて受諾成立させる
+    $first = User::factory()->create(['email' => 'idempotent@example.com']);
     $this->actingAs($first)->post('/invitations/accept', ['token' => $token]);
 
-    // 2 人目は事前検証 (isAccepted) で拒否される。受諾状態・membership が変化しないこと
+    // 2 人目は事前検証 (isAccepted) で拒否される (email 照合より前で弾かれる)。
+    // 受諾状態・membership が変化しないこと
     $second = User::factory()->create();
     $response = $this->actingAs($second)->post('/invitations/accept', ['token' => $token]);
 
@@ -564,8 +572,10 @@ function (OrganizationInvitationNotification $notification) use (&$plainToken):
     $project = Project::factory()->forOrganization($organization)->create();
     $token = inviteAndCaptureToken($organization, $owner, 'already@example.com', OrganizationRole::Member);
 
-    // 招待送信後に別経路で org へ参加済み (organization_user 行あり + Admin ロール)
-    $invitee = User::factory()->create();
+    // 招待送信後に別経路で org へ参加済み (organization_user 行あり + Admin ロール)。
+    // joinOrganization は共通コアで宛先 email をロック下再照合するため、受諾者 email を招待宛先に揃える
+    // (email 一致下で「既 attach は unique 違反にならず role を変えない」冪等契約を検証する)。
+    $invitee = User::factory()->create(['email' => 'already@example.com']);
     $organization->users()->attach($invitee);
     $invitee->addRole(OrganizationRole::Admin->value, $organization->laratrust_team_id);
 
@@ -588,3 +598,150 @@ function (OrganizationInvitationNotification $notification) use (&$plainToken):
     // 招待は受諾済みになる (再利用不能)
     expect($invitation->refresh()->isAccepted())->toBeTrue();
 });
+
+/*
+ * 宛先 email 照合 (F-2-02)。register 経路 / アプリ内受諾と同じ email 境界を token POST 経路へ適用する。
+ * 権威は Service (acceptInvitation の早期照合 + joinOrganization のロック下再照合)。
+ */
+
+test('T3: 別 email のログイン者の受諾確認画面は recipientEmailMatches=false (組織名を出さない)', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner('照合確認組織');
+    $token = inviteAndCaptureToken($organization, $owner, 'invited@example.com', OrganizationRole::Admin);
+
+    $intruder = User::factory()->create(['email' => 'intruder@example.com']);
+    $response = $this->actingAs($intruder)->get('/invitations/accept?token='.$token);
+
+    $response->assertOk();
+    $response->assertInertia(
+        fn ($page) => $page->component('Invitations/Accept')
+            ->where('recipientEmailMatches', false)
+            // payload 層でも組織名を出さない (DOM 非表示だけでは devtools/初期 payload から読めてしまう)
+            ->where('organizationName', null),
+    );
+});
+
+test('T4: 別 email の直 POST 受諾は拒否され副作用が一切残らない (権威境界)', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner('照合 POST 組織');
+    $project = Project::factory()->forOrganization($organization)->create();
+    $token = inviteAndCaptureToken($organization, $owner, 'invited@example.com', OrganizationRole::Admin);
+
+    $intruder = User::factory()->create(['email' => 'intruder@example.com']);
+    $before = $intruder->current_organization_id;
+
+    $response = $this->actingAs($intruder)->post('/invitations/accept', ['token' => $token]);
+
+    $response->assertRedirect('/dashboard');
+    $response->assertSessionHas('error');
+
+    // pivot 不在を DB assertion で直接確認する (organizationRole の null だけに依存しない)
+    $this->assertDatabaseMissing('organization_user', [
+        'organization_id' => $organization->id,
+        'user_id' => $intruder->id,
+    ]);
+    // 対象組織 laratrust_team_id の role_user に行が増えない (キャッシュ/relation リセット後に確認)
+    expect($intruder->fresh()?->organizationRole($organization))->toBeNull();
+    $this->assertDatabaseMissing('role_user', [
+        'user_id' => $intruder->id,
+        'team_id' => $organization->laratrust_team_id,
+    ]);
+    // 招待は未受諾のまま / project pivot / current_organization_id も不変
+    expect(OrganizationInvitation::query()->sole()->isAccepted())->toBeFalse();
+    expect($project->memberRole($intruder))->toBeNull();
+    expect($intruder->fresh()?->current_organization_id)->toBe($before);
+});
+
+test('T4b: 早期照合を stale 値で通過し、ロック読みの最新値で最終拒否する (TOCTOU)', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner('TOCTOU 組織');
+    $project = Project::factory()->forOrganization($organization)->create();
+    $token = inviteAndCaptureToken($organization, $owner, 'invitee@example.com', OrganizationRole::Admin);
+    $staleUser = User::factory()->create(['email' => 'invitee@example.com']);
+
+    // 別インスタンスを通常保存経路 (CipherSweet 経由) で更新。$staleUser は古い email のまま。
+    // 一括 update は暗号化・モデルイベントを迂回するため使わない。
+    $persisted = $staleUser->fresh();
+    Assert::isInstanceOf($persisted, User::class);
+    $persisted->email = 'changed@example.com';
+    $persisted->save();
+
+    // 早期照合は stale 値で通過し、最新の保存値では不一致になることを明示 assert (失敗理由の分離)
+    $invitation = OrganizationInvitation::query()->sole();
+    expect($invitation->isAddressedTo($staleUser))->toBeTrue();  // 古い email = 招待宛先
+    expect($invitation->isAddressedTo($persisted))->toBeFalse(); // 最新の保存値 = 不一致
+
+    // Service を直接呼ぶ (HTTP 経由だと認証ユーザーが DB から再解決され stale モデルを渡せない)
+    $thrown = null;
+    try {
+        app(OrganizationMembershipService::class)->acceptInvitation($token, $staleUser);
+    } catch (ValidationException $exception) {
+        $thrown = $exception;
+    }
+    expect($thrown)->not->toBeNull();
+    expect($thrown?->errors())->toHaveKey('token');
+
+    // 「早期照合が働いただけ」ではなく「最終照合がロック読みの最新値を使った」ことを DB 状態不変で分離証明する
+    $this->assertDatabaseMissing('organization_user', [
+        'organization_id' => $organization->id,
+        'user_id' => $staleUser->id,
+    ]);
+    $this->assertDatabaseMissing('role_user', [
+        'user_id' => $staleUser->id,
+        'team_id' => $organization->laratrust_team_id,
+    ]);
+    expect($invitation->refresh()->isAccepted())->toBeFalse();
+    expect($project->memberRole($staleUser))->toBeNull();
+    expect($staleUser->fresh()?->current_organization_id)->toBeNull();
+});
+
+test('T5: email 同一性規則は register 経路と token POST 経路で同一 (厳密比較・大小区別)', function (
+    string $relation,
+    bool $shouldJoin,
+): void {
+    $service = app(OrganizationMembershipService::class);
+
+    // 招待宛先 email から受諾者 email を導出する (email は全体で一意なので経路ごとに別の宛先を使う)。
+    //  - exact:    完全一致
+    //  - mismatch: 完全不一致
+    //  - case:     大文字小文字のみ相違 (先頭 1 文字を大文字化。case-sensitive fail-secure 規則の固定)
+    $userEmailFor = fn (string $invited): string => match ($relation) {
+        'exact' => $invited,
+        'mismatch' => 'different-'.$invited,
+        'case' => ucfirst($invited),
+    };
+
+    // register 経路 (acceptInvitationIfValid): 独立 fixture
+    [$orgRegister, $ownerRegister] = createOrganizationWithOwner('register 経路組織');
+    $invitedRegister = 'register-invited@example.com';
+    $tokenRegister = inviteAndCaptureToken($orgRegister, $ownerRegister, $invitedRegister, OrganizationRole::Member);
+    $userRegister = User::factory()->create(['email' => $userEmailFor($invitedRegister)]);
+    $resultRegister = $service->acceptInvitationIfValid($tokenRegister, $userRegister);
+
+    // token POST 経路 (acceptInvitation): 独立 fixture・別宛先 (同一招待を使い回さない)
+    [$orgToken, $ownerToken] = createOrganizationWithOwner('token 経路組織');
+    $invitedToken = 'token-invited@example.com';
+    $tokenToken = inviteAndCaptureToken($orgToken, $ownerToken, $invitedToken, OrganizationRole::Member);
+    $userToken = User::factory()->create(['email' => $userEmailFor($invitedToken)]);
+    $thrown = null;
+    $resultToken = null;
+    try {
+        $resultToken = $service->acceptInvitation($tokenToken, $userToken);
+    } catch (ValidationException $exception) {
+        $thrown = $exception;
+    }
+
+    if ($shouldJoin) {
+        expect($resultRegister)->not->toBeNull();
+        expect($orgRegister->users()->whereKey($userRegister->id)->exists())->toBeTrue();
+        expect($thrown)->toBeNull();
+        expect($resultToken)->not->toBeNull();
+        expect($orgToken->users()->whereKey($userToken->id)->exists())->toBeTrue();
+    } else {
+        expect($resultRegister)->toBeNull();
+        expect($orgRegister->users()->whereKey($userRegister->id)->exists())->toBeFalse();
+        expect($thrown)->not->toBeNull();
+        expect($orgToken->users()->whereKey($userToken->id)->exists())->toBeFalse();
+    }
+})->with([
+    '完全一致' => ['exact', true],
+    '完全不一致' => ['mismatch', false],
+    '大文字小文字のみ相違' => ['case', false],
+]);
diff --git a/tests/Feature/Organization/MemberRemovalAccessTest.php b/tests/Feature/Organization/MemberRemovalAccessTest.php
new file mode 100644
index 00000000..54c7cb8f
--- /dev/null
+++ b/tests/Feature/Organization/MemberRemovalAccessTest.php
@@ -0,0 +1,113 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\OrganizationRole;
+use App\Enums\ProjectRole;
+use App\Models\Project;
+use App\Models\User;
+use Inertia\Testing\AssertableInertia;
+
+/*
+ * 除名 / 未割当の fail-closed リグレッション (production コードは変更しない。既存の正しい挙動を不変条件化)。
+ *
+ * この family の層分け (本リポジトリの不変条件):
+ *  - 層 2 (テナント境界) = 404: current-org が解決できない / binding が通らない
+ *  - 層 3 (認可)        = 403: binding は通るが membership / role が無い
+ *
+ * | 状態                                   | dashboard | projects | billing | manage/users |
+ * | 自然除名 (current=null)                | 200       | 404      | 404     | 404          |
+ * | stale (current=除名済み org)           | 200       | 403      | 403     | 403          |
+ * | 未割当 (attach 済み・current=org・role 無し) | 403   | 403      | 403     | 403          |
+ *
+ * 除名の証明は projects/billing/manage の 404/403 で行う (dashboard は current 未解決時に
+ * no-org 設定画面 200 を出すため、除名済み org のデータでないことの確認に留める)。
+ */
+
+test('T7: 自然除名で membership/role/pivot/current が掃除され、被除名者は org 業務 route に到達できない (層2=404)', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner('除名テスト組織');
+    $project = Project::factory()->forOrganization($organization)->create();
+    $member = attachOrganizationMember($organization, OrganizationRole::Member);
+    attachProjectMember($project, $member, ProjectRole::Member);
+    $member->forceFill(['current_organization_id' => $organization->id])->save();
+
+    $this->actingAs($owner)
+        ->delete("/organizations/{$organization->slug}/members/{$member->id}")
+        ->assertSessionHas('success');
+
+    // (1) organization_user の pivot 行が不在 (organizationRole の null だけに依存しない)
+    $this->assertDatabaseMissing('organization_user', [
+        'organization_id' => $organization->id,
+        'user_id' => $member->id,
+    ]);
+    // (3) 対象組織 laratrust_team_id の role_user 行が不在 (Laratrust キャッシュ reset 後に確認)
+    expect($member->fresh()?->organizationRole($organization))->toBeNull();
+    $this->assertDatabaseMissing('role_user', [
+        'user_id' => $member->id,
+        'team_id' => $organization->laratrust_team_id,
+    ]);
+    // (4) project_members pivot から消滅
+    $this->assertDatabaseMissing('project_members', [
+        'project_id' => $project->id,
+        'user_id' => $member->id,
+    ]);
+    // (5) current_organization_id が null 化 (当該 org を current にしていたため)
+    expect($member->fresh()?->current_organization_id)->toBeNull();
+
+    // (2) /manage/users (owner 閲覧) の members prop に被除名者が含まれない
+    $this->actingAs($owner)
+        ->get('/manage/users')
+        ->assertInertia(function (AssertableInertia $page) use ($member): void {
+            $page->component('Admin/Users');
+            /** @var list<array{id: int}> $members */
+            $members = $page->toArray()['props']['members'];
+            expect(array_column($members, 'id'))->not->toContain($member->id);
+        });
+
+    // (6) 被除名者で org 業務 route が 404 (層 2。current=null で org context が解決できない)。
+    //     dashboard は current 未解決時に no-org 設定画面 (200) を出すため、除名の証明は projects/
+    //     billing/manage の 404 で行う (dashboard 200 は「除名済み org のデータではない」ことの確認に留める)。
+    $removed = $member->fresh();
+    $this->actingAs($removed)->get('/dashboard')->assertOk();
+    $this->actingAs($removed)->get('/projects')->assertNotFound();
+    $this->actingAs($removed)->get('/billing')->assertNotFound();
+    $this->actingAs($removed)->get('/manage/users')->assertNotFound();
+});
+
+test('T7b: 除名後に current-org を除名済み org へ戻しても membership 境界で拒否される (層3=403)', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner('stale current 組織');
+    $member = attachOrganizationMember($organization, OrganizationRole::Member);
+
+    $this->actingAs($owner)
+        ->delete("/organizations/{$organization->slug}/members/{$member->id}")
+        ->assertSessionHas('success');
+
+    // current-org を除名済み組織へ明示的に戻す (binding は通るが membership/role は不在)
+    $member->forceFill(['current_organization_id' => $organization->id])->save();
+    $stale = $member->fresh();
+
+    // 拒否が current-org 不在ではなく membership 境界で成立することを分離して固定する (層 3 = 403)。
+    // dashboard は stale current でも no-org 設定画面 (200) を出す (除名済み org のデータではない)。
+    $this->actingAs($stale)->get('/dashboard')->assertOk();
+    $this->actingAs($stale)->get('/projects')->assertForbidden();
+    $this->actingAs($stale)->get('/billing')->assertForbidden();
+    $this->actingAs($stale)->get('/manage/users')->assertForbidden();
+});
+
+test('T8: 未割当 (attach 済み・current=org・laratrust role 無し) は主要 route が fail-closed (層3=403)', function (): void {
+    // 検証した主要 route (dashboard/projects/billing/manage-users)。全 route 保証ではない。
+    [$organization] = createOrganizationWithOwner('未割当 fail-closed 組織');
+
+    // organization_user へ attach 済みだが Laratrust role を付与しない異常行 (並行受諾レースの自然な帰結)。
+    // current_organization_id は対象組織に設定する (拒否が current-org 不在ではなく role 不在で成立する)。
+    $unassigned = User::factory()->create();
+    $organization->users()->attach($unassigned);
+    $unassigned->forceFill(['current_organization_id' => $organization->id])->save();
+
+    expect($unassigned->fresh()?->organizationRole($organization))->toBeNull();
+
+    $this->actingAs($unassigned)->get('/dashboard')->assertForbidden();
+    $this->actingAs($unassigned)->get('/projects')->assertForbidden();
+    $this->actingAs($unassigned)->get('/billing')->assertForbidden();
+    $this->actingAs($unassigned)->get('/manage/users')->assertForbidden();
+});
diff --git a/tests/js/pages/InvitationsAccept.test.ts b/tests/js/pages/InvitationsAccept.test.ts
new file mode 100644
index 00000000..8c9e5646
--- /dev/null
+++ b/tests/js/pages/InvitationsAccept.test.ts
@@ -0,0 +1,53 @@
+import { afterEach, describe, expect, it, vi } from "vitest";
+import { cleanup, render, screen } from "@testing-library/svelte";
+
+/*
+ * 招待受諾画面の宛先 email 照合分岐 (F-2-02)。
+ *
+ * recipientEmailMatches prop で表示を切り替える:
+ *  - true:  受諾ボタン (accept-invitation-button) を出し、description に組織名を含める
+ *  - false: 受諾ボタン/フォームを出さず、案内文 (accept-invitation-mismatch) を出し、
+ *           description は「別のメールアドレス宛」で組織名を含めない (DOM 表示契約)
+ *
+ * ここが担保するのは **DOM 表示** の分岐のみ。payload 層の非開示 (不一致時は organizationName を
+ * サーバが null で渡す) は Feature テスト側 (InvitationTest T3) が担保する (責務分離)。
+ */
+
+vi.mock("@inertiajs/svelte", async (importOriginal) => ({
+    ...(await importOriginal<typeof import("@inertiajs/svelte")>()),
+    page: { props: { appName: "AI-CUE" } },
+    useForm: () => ({ token: "", processing: false, post: vi.fn() }),
+}));
+
+const { default: InvitationsAccept } = await import("@/pages/Invitations/Accept.svelte");
+
+const ORG_NAME = "秘匿対象組織";
+
+afterEach(() => {
+    cleanup();
+});
+
+describe("Invitations/Accept の宛先 email 照合", () => {
+    it("一致時: 受諾ボタンを表示し、不一致案内は出さず、description に組織名を含む", () => {
+        render(InvitationsAccept, {
+            props: { organizationName: ORG_NAME, token: "tok", recipientEmailMatches: true },
+        });
+
+        expect(screen.getByTestId("accept-invitation-button")).toBeInTheDocument();
+        expect(screen.queryByTestId("accept-invitation-mismatch")).toBeNull();
+        expect(screen.getByText(new RegExp(ORG_NAME))).toBeInTheDocument();
+    });
+
+    it("不一致時: 受諾ボタン/フォームを出さず案内文を表示し、description に組織名を含まない", () => {
+        // サーバは不一致時 organizationName を null で渡す (payload から組織名を落とす)
+        render(InvitationsAccept, {
+            props: { organizationName: null, token: "tok", recipientEmailMatches: false },
+        });
+
+        expect(screen.queryByTestId("accept-invitation-button")).toBeNull();
+        expect(screen.getByTestId("accept-invitation-mismatch")).toBeInTheDocument();
+        expect(screen.getByText("この招待は別のメールアドレス宛に送信されています。")).toBeInTheDocument();
+        // 非受信者への開示面を増やさない: 組織名を画面に出さない
+        expect(screen.queryByText(new RegExp(ORG_NAME))).toBeNull();
+    });
+});

```
