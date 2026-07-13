# Codex 実装レビュー: T025 唯一オーナーのアカウント削除ガード (impl-review round 1)

## アプリの使命 (North Star)

**AI-CUE** は、現場に既にある作業手順書(SOP)を起点に、AI が撮るべきカットを設計した動画シナリオを生成し、そのシナリオをスマホ(PWA)でナビゲーション撮影することで、専門知識ゼロの現場作業者でも標準化されたマニュアル動画を作れるようにする。
- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(tebiki)と異なり、標準作業を起点に AI が教材設計し撮影を指示する。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置(SECI)。
本改善の寄与: 組織が Owner 不在で管理不能になると運用の根幹が停止する。本修正は組織運用の可用性を守る。

## 禁止事項 (AGENTS.md)
1. テストなしの実装完了報告 (不変条件は Architecture/Feature テスト登録まで含めて実装済み)
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作
4. response()->json() の直書き (DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び
6. prompt 文字列のコード直書き
7. 操作系 POST 応答での redirect()->intended()
8. 必須条件未充足を理由にボタンを disabled にする UI (押下時にエラー表示する)

セキュリティ不変条件: tenant/actor/ownership キーを payload から受け取らない / 権限判定は laratrust_team_id 明示 / cross-org read/write 禁止。

## 思考原則 / ツール使用制限
まず仮説を立てろ。ユーザー視点で考えろ。先人の知恵を探せ。機能の名前に立ち返れ。
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

## system: レビュアーの役割

あなたは Laravel 12 + Svelte 5 + Inertia.js の実装レビュアー。以下の観点で厳密にレビューせよ:
- 詳細設計との一致性 / 正確性 (並行性・ロック順序・TOCTOU・トランザクション境界)
- PHPStan L10 適合 (mixed cast, generics, narrowing)
- DTO/JsonResource パターン (REST) / Inertia props はプレーン配列
- テスト網羅性 (正常系・異常系・回帰・並行前提)
- セキュリティ (認可・孤児化防止・監査記録の FK 整合)
- DESIGN.md 準拠 (token 経由・hex 直書き無し) / Atomic Design 準拠 (atoms/molecules 階層・Lucide)

出力形式: ファイルごとに判定、指摘を [Critical]/[Warning]/[Suggestion] で分類、末尾に全体判定を **APPROVED** または **CHANGES_REQUESTED** で明示せよ。

## 実装の要点 (設計からの差分・実装時の判断)

- 施策1-8 は detailed-design.md に沿って実装済み。
- **設計からの逸脱1 (重要)**: `deleteAccount(User $user, ?\Closure $beforeDelete = null)` に beforeDelete フックを追加した。
  理由: 元設計は controller で `deleteAccount()` → `Auth::logout()` の順だったが、`Auth::logout()` は `Logout` イベントを発火し、RecordSecurityEvent リスナが `security_audit_events` に user_id 付きで insert する。deleteAccount が user を削除した後に logout すると、この insert が FK 違反 (security_audit_events_user_id_foreign) となり、SecurityEventRecorder が例外を握り潰す (report のみ) 結果、pgsql トランザクションが aborted のまま残り、RefreshDatabase の共有トランザクション下の後続クエリが全滅する (実測で再現)。
  対策: ガード通過後・削除直前 (user 行が存在するうち・ロック下) に beforeDelete フックで `Auth::logout()` を実行する。controller は `deleteAccount($user, static fn () => Auth::logout())` を渡す。ブロック時はガードが先に throw しフックは実行されない (ブロックされたユーザーはログアウトされない)。旧実装も logout→delete の順で user 存在中に logout していた (挙動維持)。
- **設計からの逸脱2**: PHPStan L10 で `(int) $model->getKey()` が "Cannot cast mixed to int" になるため、private `keyOf(Model): int` helper (`Assert::integer` narrowing) を追加し全呼び出しを置換。org 列挙の map も `Assert::integer` で narrowing。`users_count` は `Assert::integerish`。
- 施策7 Architecture テストの `toContain` は message 引数を取らない (可変長 needle) ため `str_contains(...)->toBeTrue()` に変更。

## 品質ゲート結果 (worktree内)
- composer test: 1567 passed, 2 skipped, 0 failed (parallel + RefreshDatabase)
- composer phpstan: No errors (L10)
- vendor/bin/pint --test: passed / pnpm lint: passed / pnpm typecheck: passed
- pnpm test (JS): 482 passed / pnpm build: OK

---

## 実装差分 (git diff HEAD)

```diff
diff --git a/app/Http/Controllers/Settings/AccountController.php b/app/Http/Controllers/Settings/AccountController.php
index 813d3d6..11709f1 100644
--- a/app/Http/Controllers/Settings/AccountController.php
+++ b/app/Http/Controllers/Settings/AccountController.php
@@ -4,14 +4,12 @@
 
 namespace App\Http\Controllers\Settings;
 
-use App\Enums\SecurityEventType;
 use App\Http\Controllers\Controller;
 use App\Models\User;
-use App\Services\Security\SecurityEventRecorder;
+use App\Services\Organization\OrganizationMembershipService;
 use Illuminate\Http\RedirectResponse;
 use Illuminate\Http\Request;
 use Illuminate\Support\Facades\Auth;
-use Illuminate\Support\Facades\DB;
 use Webmozart\Assert\Assert;
 
 /**
@@ -20,20 +18,18 @@
  */
 class AccountController extends Controller
 {
-    public function destroy(Request $request, SecurityEventRecorder $recorder): RedirectResponse
+    public function destroy(Request $request, OrganizationMembershipService $membership): RedirectResponse
     {
         $user = $request->user();
         Assert::isInstanceOf($user, User::class);
 
-        // 削除前に記録 (user_id は削除後 nullOnDelete で null 化され、イベント自体は残る)
-        $recorder->record(SecurityEventType::AccountDeleted, $user);
-
-        Auth::logout();
-
-        DB::transaction(function () use ($user): void {
-            $user->delete();
-        });
+        // 唯一 Owner + 他メンバー有りの組織があれば ValidationException(['account'=>...]) で中断。
+        // 記録 (AccountDeleted) と削除は service の単一トランザクション内・行ロック下で直列化される。
+        // Auth::logout はガード通過後・削除直前のフックで呼ぶ (logout 監査イベントを user 行が
+        // 存在するうちに記録するため。ブロック時はフックが実行されずログアウトされない)。
+        $membership->deleteAccount($user, static fn () => Auth::logout());
 
+        // 削除成功後のみ後処理 (ブロック時は上で例外伝播し到達しない)。
         $request->session()->invalidate();
         $request->session()->regenerateToken();
 
diff --git a/app/Http/Controllers/Settings/ProfileController.php b/app/Http/Controllers/Settings/ProfileController.php
new file mode 100644
index 0000000..4e63a9e
--- /dev/null
+++ b/app/Http/Controllers/Settings/ProfileController.php
@@ -0,0 +1,39 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Http\Controllers\Settings;
+
+use App\Http\Controllers\Controller;
+use App\Models\Organization;
+use App\Models\User;
+use App\Services\Organization\OrganizationMembershipService;
+use Illuminate\Http\Request;
+use Inertia\Inertia;
+use Inertia\Response;
+use Webmozart\Assert\Assert;
+
+/**
+ * プロフィール設定画面 (GET /settings)。
+ * 削除前警告用に「唯一 Owner で他メンバーが残る組織」のスナップショットを props で返す。
+ */
+class ProfileController extends Controller
+{
+    public function index(Request $request, OrganizationMembershipService $membership): Response
+    {
+        $user = $request->user();
+        Assert::isInstanceOf($user, User::class);
+
+        return Inertia::render('Settings/Index', [
+            // 削除前警告用。唯一 Owner で他メンバーが残る組織 (name + 各組織設定への導線 slug)。
+            // 表示時点のスナップショット (最終判定は削除時にサーバーが再評価)。
+            'soleOwnedOrganizations' => $membership->organizationsBlockingDeletion($user)
+                ->map(fn (Organization $organization): array => [
+                    'name' => $organization->name,
+                    'slug' => $organization->slug,
+                ])
+                ->values()
+                ->all(),
+        ]);
+    }
+}
diff --git a/app/Services/Organization/OrganizationMembershipService.php b/app/Services/Organization/OrganizationMembershipService.php
index 16920a8..b0bce0a 100644
--- a/app/Services/Organization/OrganizationMembershipService.php
+++ b/app/Services/Organization/OrganizationMembershipService.php
@@ -15,6 +15,8 @@
 use App\Services\Notification\NotificationCenterService;
 use App\Services\Project\DefaultProjectResolver;
 use App\Services\Security\SecurityEventRecorder;
+use Illuminate\Database\Eloquent\Model;
+use Illuminate\Support\Collection;
 use Illuminate\Support\Facades\DB;
 use Illuminate\Support\Facades\Notification;
 use Illuminate\Validation\ValidationException;
@@ -195,6 +197,10 @@ public function revokeInvitation(OrganizationInvitation $invitation): void
     private function joinOrganization(OrganizationInvitation $invitation, Organization $organization, User $user, OrganizationRole $role): void
     {
         DB::transaction(function () use ($organization, $user, $role, $invitation): void {
+            // canonical 共通ロック境界 (users 昇順 → organizations)。並行メンバー追加を
+            // deleteAccount 等と直列化する (招待行ロックの手前で org/user 行ロックを取る)。
+            $this->lockForMembershipWrite([$this->keyOf($user)], [$this->keyOf($organization)]);
+
             // 1. 招待行ロック + 受諾可能状態のロック下再検証 (並行受諾に敗れた側は冪等 no-op)
             /** @var OrganizationInvitation $locked */
             $locked = OrganizationInvitation::query()->whereKey($invitation->id)->lockForUpdate()->firstOrFail();
@@ -241,6 +247,10 @@ private function joinOrganization(OrganizationInvitation $invitation, Organizati
     public function applyConsoleRole(Organization $organization, User $target, AdminConsoleRole $role): void
     {
         DB::transaction(function () use ($organization, $target, $role): void {
+            // canonical 共通ロック境界 (users 昇順 → organizations)。normalizeOrganizationRole の
+            // 直接 addRole 経路も含めロック下で直列化する。
+            $this->lockForMembershipWrite([$this->keyOf($target)], [$this->keyOf($organization)]);
+
             $projectRole = $role->projectRole();
 
             if ($projectRole === null) {
@@ -299,24 +309,30 @@ private function normalizeOrganizationRole(Organization $organization, User $tar
      */
     public function changeRole(Organization $organization, User $target, OrganizationRole $newRole): void
     {
-        $currentRole = $target->organizationRole($organization);
-        if ($currentRole === null) {
-            throw ValidationException::withMessages(['role' => ['このユーザーは組織のメンバーではありません。']]);
-        }
-        if ($currentRole === $newRole) {
-            return;
-        }
+        // [TOCTOU 封じ] 事前チェックを撤廃し、検証をすべてロック取得後・ロック下で行う。
+        DB::transaction(function () use ($organization, $target, $newRole): void {
+            // canonical 共通ロック境界 (users 昇順 → organizations)。deleteAccount 等と直列化。
+            $this->lockForMembershipWrite([$this->keyOf($target)], [$this->keyOf($organization)]);
 
-        // Owner を降格させる場合は他に Owner がいることを要求 (Owner 不在の組織を作らない)
-        if ($currentRole === OrganizationRole::Owner && ! $this->hasAnotherOwner($organization, $target)) {
-            throw ValidationException::withMessages([
-                'role' => ['最後のオーナーは降格できません。先にオーナーを移譲してください。'],
-            ]);
-        }
+            // ロック下で最新状態を再取得 (laratrust のロールキャッシュも fresh で破棄)
+            $freshTarget = $target->fresh();
+            Assert::isInstanceOf($freshTarget, User::class);
 
-        DB::transaction(function () use ($organization, $target, $currentRole, $newRole): void {
-            $target->removeRole($currentRole->value, $organization->laratrust_team_id);
-            $target->addRole($newRole->value, $organization->laratrust_team_id);
+            $currentRole = $freshTarget->organizationRole($organization);
+            if ($currentRole === null) {
+                throw ValidationException::withMessages(['role' => ['このユーザーは組織のメンバーではありません。']]);
+            }
+            if ($currentRole === $newRole) {
+                return; // 冪等
+            }
+            // Owner を降格させる場合は他に Owner がいることを要求 (Owner 不在の組織を作らない)
+            if ($currentRole === OrganizationRole::Owner && ! $this->hasAnotherOwner($organization, $freshTarget)) {
+                throw ValidationException::withMessages([
+                    'role' => ['最後のオーナーは降格できません。先にオーナーを移譲してください。'],
+                ]);
+            }
+            $freshTarget->removeRole($currentRole->value, $organization->laratrust_team_id);
+            $freshTarget->addRole($newRole->value, $organization->laratrust_team_id);
         });
     }
 
@@ -327,27 +343,31 @@ public function changeRole(Organization $organization, User $target, Organizatio
      */
     public function removeMember(Organization $organization, User $target): void
     {
-        if (! $organization->users()->whereKey($target->getKey())->exists()) {
-            throw ValidationException::withMessages(['member' => ['このユーザーは組織のメンバーではありません。']]);
-        }
-
-        $role = $target->organizationRole($organization);
-        if ($role === OrganizationRole::Owner) {
-            throw ValidationException::withMessages([
-                'member' => ['オーナーは削除できません。先にオーナーを移譲してください。'],
-            ]);
-        }
+        // [TOCTOU 封じ] 検証をロック取得後・ロック下で行う。
+        DB::transaction(function () use ($organization, $target): void {
+            // canonical 共通ロック境界 (users 昇順 → organizations)。deleteAccount 等と直列化。
+            $this->lockForMembershipWrite([$this->keyOf($target)], [$this->keyOf($organization)]);
 
-        DB::transaction(function () use ($organization, $target, $role): void {
-            $organization->users()->detach($target->getKey());
+            if (! $organization->users()->whereKey($target->getKey())->exists()) {
+                throw ValidationException::withMessages(['member' => ['このユーザーは組織のメンバーではありません。']]);
+            }
+            $freshTarget = $target->fresh();
+            Assert::isInstanceOf($freshTarget, User::class);
+            $role = $freshTarget->organizationRole($organization);
+            if ($role === OrganizationRole::Owner) {
+                throw ValidationException::withMessages([
+                    'member' => ['オーナーは削除できません。先にオーナーを移譲してください。'],
+                ]);
+            }
+            $organization->users()->detach($freshTarget->getKey());
             if ($role !== null) {
-                $target->removeRole($role->value, $organization->laratrust_team_id);
+                $freshTarget->removeRole($role->value, $organization->laratrust_team_id);
             }
             // project pivot 掃除 (org 配下 project に限定。別 org の pivot は維持)
-            $this->detachProjectMemberships($organization, $target);
+            $this->detachProjectMemberships($organization, $freshTarget);
             // 削除した組織を current にしていた場合は外す (次回アクセス時に選び直す)
-            if ($target->current_organization_id === $organization->id) {
-                $target->forceFill(['current_organization_id' => null])->save();
+            if ($freshTarget->current_organization_id === $organization->id) {
+                $freshTarget->forceFill(['current_organization_id' => null])->save();
             }
         });
     }
@@ -386,37 +406,46 @@ public function transferOwnership(Organization $organization, User $from, User $
         }
 
         DB::transaction(function () use ($organization, $from, $to): void {
-            // 両者のメンバーシップ行をロック (並行する移譲・削除を直列化)。
-            // count() + FOR UPDATE は pgsql が集約関数との併用を拒否するため、行を
-            // 取得してロードした上で PHP 側で件数を確認する (organization_user は
+            // canonical 共通ロック境界 (users 昇順 → organizations)。deleteAccount 等と直列化。
+            // 従来の pivot 行ロックを users 行ロックへ置換し、移譲の直列化基点を統一する。
+            $this->lockForMembershipWrite([$this->keyOf($from), $this->keyOf($to)], [$this->keyOf($organization)]);
+
+            // ロック下で最新インスタンスを再取得して検証 (事前取得モデル・stale org を信用しない)
+            /** @var Organization $freshOrg */
+            $freshOrg = Organization::query()->whereKey($organization->getKey())->firstOrFail();
+            /** @var User $freshFrom */
+            $freshFrom = User::query()->whereKey($from->getKey())->firstOrFail();
+            /** @var User $freshTo */
+            $freshTo = User::query()->whereKey($to->getKey())->firstOrFail();
+
+            // 両者が組織メンバーであることをロック下で確認 (organization_user は
             // (organization_id, user_id) UNIQUE のため最大 2 行)。
-            $lockedUserIds = DB::table('organization_user')
-                ->where('organization_id', $organization->id)
-                ->whereIn('user_id', [$from->getKey(), $to->getKey()])
-                ->lockForUpdate()
+            $memberUserIds = DB::table('organization_user')
+                ->where('organization_id', $freshOrg->id)
+                ->whereIn('user_id', [$freshFrom->getKey(), $freshTo->getKey()])
                 ->pluck('user_id')
                 ->all();
-            if (count($lockedUserIds) < 2) {
+            if (count($memberUserIds) < 2) {
                 throw ValidationException::withMessages([
                     'user_id' => ['移譲先は組織のメンバーである必要があります。'],
                 ]);
             }
 
             // ロック取得後に最新状態で Owner を再確認する (TOCTOU 防止)
-            if ($from->organizationRole($organization) !== OrganizationRole::Owner) {
+            if ($freshFrom->organizationRole($freshOrg) !== OrganizationRole::Owner) {
                 throw ValidationException::withMessages(['user_id' => ['オーナーのみ移譲できます。']]);
             }
 
-            $teamId = $organization->laratrust_team_id;
-            $toRole = $to->organizationRole($organization);
+            $teamId = $freshOrg->laratrust_team_id;
+            $toRole = $freshTo->organizationRole($freshOrg);
 
-            $from->removeRole(OrganizationRole::Owner->value, $teamId);
-            $from->addRole(OrganizationRole::Admin->value, $teamId);
+            $freshFrom->removeRole(OrganizationRole::Owner->value, $teamId);
+            $freshFrom->addRole(OrganizationRole::Admin->value, $teamId);
 
             if ($toRole !== null) {
-                $to->removeRole($toRole->value, $teamId);
+                $freshTo->removeRole($toRole->value, $teamId);
             }
-            $to->addRole(OrganizationRole::Owner->value, $teamId);
+            $freshTo->addRole(OrganizationRole::Owner->value, $teamId);
         });
 
         $this->recorder->record(SecurityEventType::OwnershipTransferred, $from, [
@@ -426,6 +455,125 @@ public function transferOwnership(Organization $organization, User $from, User $
         ]);
     }
 
+    /**
+     * 削除するとその組織を Owner 不在で残す組織 (= 削除ブロック対象)。
+     * 述語: $user が Owner かつ 他に Owner がいない かつ 他に 1 人以上メンバーが残る。
+     * 個人組織のように $user が唯一メンバーの組織は「孤児化するメンバーが居ない」ため対象外。
+     *
+     * 読み取り専用判定 (ロックしない。表示スナップショット用)。権威判定は deleteAccount が
+     * ロック下で再評価する。
+     *
+     * @return Collection<int, Organization>
+     */
+    public function organizationsBlockingDeletion(User $user): Collection
+    {
+        return $user->organizations()
+            ->withCount('users')
+            ->get()
+            ->filter(function (Organization $organization) use ($user): bool {
+                // withCount('users') 派生属性。PHPStan は型を知らないため integerish で narrowing。
+                $usersCount = $organization->getAttribute('users_count');
+                Assert::integerish($usersCount);
+
+                return $user->organizationRole($organization) === OrganizationRole::Owner
+                    && (int) $usersCount > 1
+                    && ! $this->hasAnotherOwner($organization, $user);
+            })
+            ->values();
+    }
+
+    /**
+     * メンバーシップ書き込みの共通ロック境界。canonical 順序で行ロックを取り、
+     * デッドロックを構造的に排除する: **users(id 昇順) → organizations(id 昇順)**。
+     * ロック取得後は呼び出し側が最新状態を DB から再取得して判定すること (事前取得値を信用しない)。
+     *
+     * @param  list<int>  $userIds
+     * @param  list<int>  $organizationIds
+     */
+    private function lockForMembershipWrite(array $userIds, array $organizationIds): void
+    {
+        $sortedUserIds = collect($userIds)->unique()->sort()->values()->all();
+        if ($sortedUserIds !== []) {
+            DB::table('users')->whereIn('id', $sortedUserIds)->orderBy('id')->lockForUpdate()->get();
+        }
+        $sortedOrgIds = collect($organizationIds)->unique()->sort()->values()->all();
+        if ($sortedOrgIds !== []) {
+            DB::table('organizations')->whereIn('id', $sortedOrgIds)->orderBy('id')->lockForUpdate()->get();
+        }
+    }
+
+    /**
+     * モデルの主キーを int として取得する (getKey() の mixed を PHPStan L10 で narrowing)。
+     * 本アプリのメンバーシップ関連モデル (User / Organization) は bigint auto-increment 主キー。
+     */
+    private function keyOf(Model $model): int
+    {
+        $key = $model->getKey();
+        Assert::integer($key);
+
+        return $key;
+    }
+
+    /**
+     * アカウント削除。ガードと削除を同一トランザクション + 行ロックで直列化する。
+     * 削除するとその組織を Owner 不在で残す組織があれば拒否する (孤児化防止・最終権威)。
+     *
+     * $beforeDelete はガード通過後・削除直前 (user 行が存在するうち・ロック下) に実行する
+     * フック。呼び出し側のセッション破棄 (Auth::logout) をここで行うことで、ログアウトが
+     * 発火する監査イベント (logout) を user 行が存在する間に記録できる (削除後だと user_id の
+     * FK 違反になり記録が失われる)。ブロック時はガードが先に例外を投げ、フックは実行されない
+     * (ブロックされたユーザーはログアウトされない)。
+     *
+     * @param  (\Closure(): void)|null  $beforeDelete
+     *
+     * @throws ValidationException 唯一 Owner かつ他メンバーが残る組織がある
+     */
+    public function deleteAccount(User $user, ?\Closure $beforeDelete = null): void
+    {
+        DB::transaction(function () use ($user, $beforeDelete): void {
+            // 1. 対象 User 行を最初にロック (この後の所属列挙を安定させる。列挙前に user を
+            //    ロックしないと、列挙〜user ロック取得の間に別 txn が新組織 B の Owner を user へ
+            //    移譲し、B を未検査のまま削除する race が残る)。
+            $this->lockForMembershipWrite([$this->keyOf($user)], []);
+
+            // 2. user ロック下で所属組織を列挙 → organizations 行を昇順ロック
+            //    (メンバー追加/移譲経路も user 行をロックするため、ここで列挙は安定する)
+            /** @var list<int> $organizationIds */
+            $organizationIds = $user->organizations()
+                ->orderBy('organizations.id')
+                ->pluck('organizations.id')
+                ->map(function (mixed $id): int {
+                    Assert::integer($id);
+
+                    return $id;
+                })
+                ->values()
+                ->all();
+            $this->lockForMembershipWrite([], $organizationIds);
+
+            // 3. ロック下で述語を再評価 (fresh。事前取得値は信用しない。null フォールバック禁止)
+            $freshUser = $user->fresh();
+            Assert::isInstanceOf($freshUser, User::class);
+            $blockers = $this->organizationsBlockingDeletion($freshUser);
+            if ($blockers->isNotEmpty()) {
+                $names = $blockers->pluck('name')->implode('、');
+                throw ValidationException::withMessages([
+                    'account' => ["次の組織のオーナーであるため削除できません。先にオーナーを移譲してください: {$names}"],
+                ]);
+            }
+
+            // 4. ガード通過後・削除直前のフック (呼び出し側のセッション破棄等。user 行が
+            //    存在するうちに認証イベントを発火させる)。
+            if ($beforeDelete !== null) {
+                $beforeDelete();
+            }
+
+            // 5. 監査記録 (純 DB insert。user_id は nullOnDelete で削除時に null 化される)
+            $this->recorder->record(SecurityEventType::AccountDeleted, $freshUser);
+            $freshUser->delete();
+        });
+    }
+
     /**
      * email がこの組織の既存メンバーのものか (blind index 照合)。
      */
diff --git a/resources/js/pages/Settings/Index.svelte b/resources/js/pages/Settings/Index.svelte
index fda6d65..6a2f7e8 100644
--- a/resources/js/pages/Settings/Index.svelte
+++ b/resources/js/pages/Settings/Index.svelte
@@ -1,5 +1,6 @@
 <script lang="ts">
     import { page, router, useForm } from "@inertiajs/svelte";
+    import Alert from "@/components/atoms/Alert.svelte";
     import Button from "@/components/atoms/Button.svelte";
     import Card from "@/components/atoms/Card.svelte";
     import Input from "@/components/atoms/Input.svelte";
@@ -12,8 +13,27 @@
     import { withRecentAuth, type RecentAuthStatus } from "@/lib/recent-auth";
     import type { SharedProps } from "@/lib/shared-props";
 
-    const shared = $derived(page.props as unknown as SharedProps);
-    const appName = $derived(shared.appName ?? "");
+    // ページ専用 props 型 (SharedProps を継承しページ固有 prop を足す。多重キャスト排除)。
+    // SharedProps に errors フィールドは無く、Inertia が別途注入するため継承衝突しない。
+    interface SoleOwnedOrganization {
+        name: string;
+        slug: string;
+    }
+    interface SettingsPageProps extends SharedProps {
+        soleOwnedOrganizations?: SoleOwnedOrganization[];
+        errors?: Record<string, string | string[]>;
+    }
+
+    const props = $derived(page.props as unknown as SettingsPageProps);
+    const appName = $derived(props.appName ?? "");
+    const soleOwnedOrganizations = $derived(props.soleOwnedOrganizations ?? []);
+
+    // ブロック時にサーバーが返す errors.account を表示文字列へ正規化 (string | string[] 両対応)
+    const accountError = $derived.by((): string | null => {
+        const err = props.errors?.account;
+        if (err === undefined) return null;
+        return Array.isArray(err) ? (err[0] ?? null) : err;
+    });
 
     const initialUser = (page.props as unknown as SharedProps).auth?.user ?? null;
 
@@ -80,9 +100,14 @@
     function deleteAccount(): void {
         guardWithRecentAuth(() => {
             router.delete("/settings/account", {
+                preserveScroll: true,
                 onStart: () => {
                     deleting = true;
                 },
+                // ブロック時 (errors.account): ダイアログを閉じ DangerZone の Alert を露出させる
+                onError: () => {
+                    deleteDialogOpen = false;
+                },
                 onFinish: () => {
                     deleting = false;
                 },
@@ -188,6 +213,24 @@
             title="アカウント削除"
             description="アカウントを削除すると、すべてのデータが完全に失われます。この操作は取り消せません。"
         >
+            {#if soleOwnedOrganizations.length > 0}
+                <Alert type="warning" title="オーナー移譲が必要です" class="mb-3">
+                    以下の組織であなたが唯一のオーナーです。アカウントを削除する前に、各組織で
+                    オーナーを別のメンバーへ移譲してください（削除時にサーバーが再判定します）。
+                    <ul class="mt-2 list-disc pl-5">
+                        {#each soleOwnedOrganizations as org (org.slug)}
+                            <li>
+                                <TextLink href={`/organizations/${org.slug}/settings`}>
+                                    {org.name} の設定へ
+                                </TextLink>
+                            </li>
+                        {/each}
+                    </ul>
+                </Alert>
+            {/if}
+            {#if accountError}
+                <Alert type="danger" class="mb-3">{accountError}</Alert>
+            {/if}
             <Button
                 variant="danger-outline"
                 onclick={() => {
diff --git a/routes/web.php b/routes/web.php
index b5f0f56..91e054e 100644
--- a/routes/web.php
+++ b/routes/web.php
@@ -41,6 +41,7 @@
 use App\Http\Controllers\Seo\RobotsController;
 use App\Http\Controllers\Seo\SitemapController;
 use App\Http\Controllers\Settings\AccountController;
+use App\Http\Controllers\Settings\ProfileController;
 use App\Http\Controllers\Webhooks\SesNotificationController;
 use App\Http\Middleware\HandleInertiaRequests;
 use App\Http\Middleware\LocalOnly;
@@ -168,9 +169,7 @@
         ->middleware('throttle:6,1')
         ->name('recent-auth.password');
 
-    Route::get('/settings', function () {
-        return Inertia::render('Settings/Index');
-    })->name('settings');
+    Route::get('/settings', [ProfileController::class, 'index'])->name('settings');
 
     Route::get('/settings/security', function () {
         // admin guard 追加で user() は User|AdminUser の union になるため narrowing する
diff --git a/tests/Architecture/MembershipWriteLockInventoryTest.php b/tests/Architecture/MembershipWriteLockInventoryTest.php
new file mode 100644
index 0000000..d3b42ee
--- /dev/null
+++ b/tests/Architecture/MembershipWriteLockInventoryTest.php
@@ -0,0 +1,72 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Services\Organization\OrganizationMembershipService;
+
+/*
+ * メンバーシップ書き込みの共通ロック規約 (canonical 順序 users→organizations) の drift-guard。
+ *
+ * OrganizationMembershipService の mutating な public メソッドを reflection で列挙し、
+ * 3 分類 (directLock / delegatedToLocked / exempt) への登録を強制する。加えてメソッドソースを
+ * 検査し、実際にロックを呼んでいることを保証する:
+ * - directLock 群: メソッドソースに `lockForMembershipWrite(` が現れること。
+ * - delegatedToLocked 群: ロック済み内部メソッド (`joinOrganization(`) 呼び出しが現れること。
+ * - 未分類メソッドがあれば fail (drift 検出)。
+ */
+
+test('OrganizationMembershipService の書き込みメソッドは共通ロック規約に準拠する', function (): void {
+    // 自身の tx 冒頭で直接ロックする mutating メソッド
+    $directLock = ['applyConsoleRole', 'changeRole', 'removeMember', 'transferOwnership', 'deleteAccount'];
+    // ロック済み内部メソッド (joinOrganization) 経由で間接的にロックされる受諾経路
+    $delegatedToLocked = ['acceptInvitation', 'acceptInvitationIfValid'];
+    // ロック不要 (membership/role を変えない) と判断した書き込みメソッド (根拠付き exempt)
+    $exempt = [
+        'inviteMember',     // 招待レコード生成のみ (membership/role 不変)
+        'revokeInvitation', // 招待の論理失効のみ (membership/role 不変)
+        // 読み取り専用判定 (ロック不要・表示スナップショット)。deleteAccount がロック下で権威判定する
+        'organizationsBlockingDeletion',
+    ];
+
+    $reflection = new ReflectionClass(OrganizationMembershipService::class);
+    $ownPublicMethods = collect($reflection->getMethods(ReflectionMethod::IS_PUBLIC))
+        ->reject(fn (ReflectionMethod $m): bool => $m->isConstructor()
+            || $m->getDeclaringClass()->getName() !== OrganizationMembershipService::class)
+        ->map(fn (ReflectionMethod $m): string => $m->getName())
+        ->all();
+
+    // 1. 分類漏れ検出
+    $classified = array_merge($directLock, $delegatedToLocked, $exempt);
+    expect(array_values(array_diff($ownPublicMethods, $classified)))
+        ->toBe([], '新しい書き込みメソッドは directLock / delegatedToLocked / exempt に分類すること');
+
+    // 2. 実ロック呼び出しの静的検査 (メソッド本文を切り出して文字列一致)
+    $source = file($reflection->getFileName() ?: '') ?: [];
+    $bodyOf = function (string $method) use ($reflection, $source): string {
+        $m = $reflection->getMethod($method);
+        $start = $m->getStartLine();
+        $end = $m->getEndLine();
+        if ($start === false || $end === false) {
+            return '';
+        }
+
+        return implode('', array_slice($source, $start - 1, $end - $start + 1));
+    };
+    foreach ($directLock as $method) {
+        // {$method} は lockForMembershipWrite を直接呼ぶこと (toContain は message 引数を取らない)
+        expect(str_contains($bodyOf($method), 'lockForMembershipWrite('))->toBeTrue();
+    }
+    foreach ($delegatedToLocked as $method) {
+        // {$method} はロック済み joinOrganization を経由すること
+        expect(str_contains($bodyOf($method), 'joinOrganization('))->toBeTrue();
+    }
+
+    // 3. [ロック順序 guard] deleteAccount 本文で最初の lockForMembershipWrite( が
+    //    organizations( 列挙より前に現れること (canonical 順序 users→organizations の退行検出)
+    $deleteBody = $bodyOf('deleteAccount');
+    $firstLock = strpos($deleteBody, 'lockForMembershipWrite(');
+    $orgEnumeration = strpos($deleteBody, "orderBy('organizations.id')");
+    expect($firstLock)->not->toBeFalse('deleteAccount は lockForMembershipWrite を呼ぶこと');
+    expect($orgEnumeration)->not->toBeFalse('deleteAccount は organizations を列挙すること');
+    expect($firstLock)->toBeLessThan($orgEnumeration, 'deleteAccount は組織列挙の前に user 行をロックすること');
+});
diff --git a/tests/Feature/Auth/AccountDeletionTest.php b/tests/Feature/Auth/AccountDeletionTest.php
index bb23e9a..ea38255 100644
--- a/tests/Feature/Auth/AccountDeletionTest.php
+++ b/tests/Feature/Auth/AccountDeletionTest.php
@@ -2,9 +2,11 @@
 
 declare(strict_types=1);
 
+use App\Enums\OrganizationRole;
 use App\Models\SecurityAuditEvent;
 use App\Models\SocialAccount;
 use App\Models\User;
+use App\Services\Organization\OrganizationMembershipService;
 
 test('再認証 (step-up) なしではアカウント削除できない', function (): void {
     $user = User::factory()->create();
@@ -36,3 +38,73 @@
         SecurityAuditEvent::query()->where('event_type', 'account_deleted')->exists(),
     )->toBeTrue();
 });
+
+test('唯一オーナーで他メンバーが残る場合はアカウント削除がブロックされる', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    attachOrganizationMember($organization, OrganizationRole::Admin); // 孤児化する残存メンバー
+
+    $response = $this->actingAs($owner)
+        ->withSession(['recent_auth_at' => time()])
+        ->from('/settings')
+        ->delete('/settings/account');
+
+    $response->assertRedirect('/settings');
+    $response->assertSessionHasErrors('account');
+    expect(User::query()->whereKey($owner->id)->exists())->toBeTrue(); // 残存
+});
+
+test('唯一オーナーだが自分のみメンバー (個人組織) なら削除できる', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner(); // owner 1 人・他メンバー無し
+
+    $response = $this->actingAs($owner)
+        ->withSession(['recent_auth_at' => time()])
+        ->delete('/settings/account');
+
+    $response->assertRedirect('/');
+    expect(User::query()->whereKey($owner->id)->exists())->toBeFalse();
+});
+
+test('複数オーナーがいれば削除できる', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $second = attachOrganizationMember($organization, OrganizationRole::Owner);
+
+    $response = $this->actingAs($owner)
+        ->withSession(['recent_auth_at' => time()])
+        ->delete('/settings/account');
+
+    $response->assertRedirect('/');
+    expect(User::query()->whereKey($owner->id)->exists())->toBeFalse();
+    expect($second->fresh()->organizationRole($organization))->toBe(OrganizationRole::Owner);
+});
+
+test('ブロック→2人目オーナー追加後は削除できる (現在状態で再評価)', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    attachOrganizationMember($organization, OrganizationRole::Admin);
+    // この時点では唯一 Owner + 他メンバー有り → ブロックされるはず
+    expect(app(OrganizationMembershipService::class)->organizationsBlockingDeletion($owner))->toHaveCount(1);
+
+    attachOrganizationMember($organization, OrganizationRole::Owner); // 2 人目 Owner を追加
+
+    $response = $this->actingAs($owner)
+        ->withSession(['recent_auth_at' => time()])
+        ->delete('/settings/account');
+
+    $response->assertRedirect('/');
+    expect(User::query()->whereKey($owner->id)->exists())->toBeFalse();
+});
+
+test('2オーナー→片方降格後は唯一オーナー+メンバーで削除がブロックされる', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $second = attachOrganizationMember($organization, OrganizationRole::Owner);
+    attachOrganizationMember($organization, OrganizationRole::Member); // 孤児化するメンバー
+    // service 正規経路で 2 人目 Owner を Admin へ降格 (owner を 1 人に戻す)
+    app(OrganizationMembershipService::class)->changeRole($organization, $second, OrganizationRole::Admin);
+
+    $response = $this->actingAs($owner)
+        ->withSession(['recent_auth_at' => time()])
+        ->from('/settings')
+        ->delete('/settings/account');
+
+    $response->assertSessionHasErrors('account');
+    expect(User::query()->whereKey($owner->id)->exists())->toBeTrue();
+});
diff --git a/tests/Feature/Settings/ProfileSettingsPropsTest.php b/tests/Feature/Settings/ProfileSettingsPropsTest.php
new file mode 100644
index 0000000..3711130
--- /dev/null
+++ b/tests/Feature/Settings/ProfileSettingsPropsTest.php
@@ -0,0 +1,26 @@
+<?php
+
+declare(strict_types=1);
+
+use Inertia\Testing\AssertableInertia as Assert;
+
+test('唯一オーナーは /settings で soleOwnedOrganizations に該当組織を受け取る', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    attachOrganizationMember($organization); // 孤児化する残存メンバー
+
+    $this->actingAs($owner)->get('/settings')
+        ->assertInertia(fn (Assert $page) => $page
+            ->component('Settings/Index')
+            ->has('soleOwnedOrganizations', 1)
+            ->where('soleOwnedOrganizations.0.slug', $organization->slug)
+            ->where('soleOwnedOrganizations.0.name', $organization->name));
+});
+
+test('孤児化リスクが無いユーザーは soleOwnedOrganizations が空', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner(); // owner 1 人・他メンバー無し
+
+    $this->actingAs($owner)->get('/settings')
+        ->assertInertia(fn (Assert $page) => $page
+            ->component('Settings/Index')
+            ->has('soleOwnedOrganizations', 0));
+});
diff --git a/tests/js/pages/SettingsIndex.test.ts b/tests/js/pages/SettingsIndex.test.ts
new file mode 100644
index 0000000..3e0588d
--- /dev/null
+++ b/tests/js/pages/SettingsIndex.test.ts
@@ -0,0 +1,105 @@
+import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
+import { cleanup, render, screen } from "@testing-library/svelte";
+
+/*
+ * プロフィール設定画面 (T025: 唯一オーナーのアカウント削除ガード)。
+ * - soleOwnedOrganizations 非空 → 警告 + 各組織の /organizations/{slug}/settings リンク描画
+ * - 空 → 警告非表示
+ * - errors.account (string / string[] 両対応) → danger Alert 表示
+ * - 警告と errors.account の同時表示 (両立)
+ * - 削除ボタンは常に有効 (AGENTS.md 禁止事項 8: disabled 不使用)
+ */
+
+const { pageState } = vi.hoisted(() => ({
+    pageState: {
+        props: {} as Record<string, unknown>,
+        url: "/settings",
+    },
+}));
+
+vi.mock("@inertiajs/svelte", async (importOriginal) => ({
+    ...(await importOriginal<typeof import("@inertiajs/svelte")>()),
+    router: { delete: vi.fn() },
+    page: pageState,
+}));
+
+// eslint-disable-next-line import/first
+import Index from "@/pages/Settings/Index.svelte";
+
+function setProps(extra: Record<string, unknown> = {}): void {
+    pageState.props = {
+        appName: "AI-CUE",
+        auth: { user: { id: 1, name: "テスト太郎", email: "taro@example.com" } },
+        ...extra,
+    };
+}
+
+beforeEach(() => {
+    setProps();
+});
+
+afterEach(() => {
+    cleanup();
+});
+
+describe("Settings/Index 唯一オーナー削除ガード", () => {
+    it("soleOwnedOrganizations 非空で警告と各組織の設定リンクを描画する", () => {
+        setProps({
+            soleOwnedOrganizations: [
+                { name: "現場A", slug: "genba-a" },
+                { name: "現場B", slug: "genba-b" },
+            ],
+        });
+        render(Index, { props: {} });
+
+        expect(screen.getByText("オーナー移譲が必要です")).toBeInTheDocument();
+        const linkA = screen.getByText("現場A の設定へ");
+        expect(linkA.getAttribute("href")).toMatch(/\/organizations\/genba-a\/settings$/);
+        const linkB = screen.getByText("現場B の設定へ");
+        expect(linkB.getAttribute("href")).toMatch(/\/organizations\/genba-b\/settings$/);
+    });
+
+    it("soleOwnedOrganizations が空なら警告を出さない", () => {
+        setProps({ soleOwnedOrganizations: [] });
+        render(Index, { props: {} });
+
+        expect(screen.queryByText("オーナー移譲が必要です")).toBeNull();
+    });
+
+    it("errors.account (string) を danger Alert で表示する", () => {
+        setProps({ errors: { account: "次の組織のオーナーであるため削除できません: 現場A" } });
+        render(Index, { props: {} });
+
+        expect(
+            screen.getByText("次の組織のオーナーであるため削除できません: 現場A"),
+        ).toBeInTheDocument();
+    });
+
+    it("errors.account (string[]) の先頭要素を表示する", () => {
+        setProps({ errors: { account: ["最初のエラー", "二番目"] } });
+        render(Index, { props: {} });
+
+        expect(screen.getByText("最初のエラー")).toBeInTheDocument();
+        expect(screen.queryByText("二番目")).toBeNull();
+    });
+
+    it("警告と errors.account を同時に表示できる", () => {
+        setProps({
+            soleOwnedOrganizations: [{ name: "現場A", slug: "genba-a" }],
+            errors: { account: "削除できません" },
+        });
+        render(Index, { props: {} });
+
+        expect(screen.getByText("オーナー移譲が必要です")).toBeInTheDocument();
+        expect(screen.getByText("削除できません")).toBeInTheDocument();
+    });
+
+    it("削除ボタンは常に有効 (disabled 不使用)", () => {
+        setProps({ soleOwnedOrganizations: [{ name: "現場A", slug: "genba-a" }] });
+        render(Index, { props: {} });
+
+        const button = screen.getByTestId("delete-account-button");
+        expect(button).toBeInTheDocument();
+        expect(button).not.toBeDisabled();
+    });
+});
```
