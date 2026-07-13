# impl-review Round 2: Round 1 指摘への対応

Round 1 の [Critical]×2 / [Suggestion]×3 に対応しました。対応マトリクスと差分を示します。再判定をお願いします。

## [Critical 1] 「直列化保証」とロック到達範囲のズレ → 対応 + エンフォース追加

あなたの懸念は「owner 判定は role_user を読むが、ロックは users/org 行に留まる」でした。本設計の直列化メカニズムを明確化し、機械的に守られるようにしました:

- **メカニズム**: organizations 行を「owner 集合変更の共通 mutex」にする集約ルート行ロック方式 (AGENTS.md ドメイン規約1 の VideoManual lockForUpdate と同型)。owner 集合を変える全経路 (changeRole / transferOwnership / removeMember / applyConsoleRole / joinOrganization) は自 tx 冒頭で対象 organizations 行を lockForMembershipWrite でロックする。deleteAccount は自所属組織行をすべてロックするため、それらの org の owner 数を変える並行書き込みは org 行で必ずブロックされる。role_user を直接ロックしなくても org 行が mutex になる。
- **前提のエンフォース (新規テスト)**: この直列化は「owner を付与し得るロール書き込みが全経路ロック済みサービス経由のみ」で初めて成立します。app/ 全体を静的走査し `->addRole/removeRole/syncRoles(` が `OrganizationMembershipService` (全経路ロック済み) と `OrganizationProvisioningService` (新規組織の creator への Owner 付与のみ = 既存 org の owner 集合を変えない bootstrap 例外) 以外に現れないことを強制する drift-guard を追加しました。未ロック経路の混入 = 直列化の破れを機械検出します。
- 調査: 現状 owner を付与し得る経路は上記2ファイルのみ (grep 済み)。docblock にメカニズムを明記しました。

## [Critical 2] 並行実証テストの不足 → infeasible ゆえ静的エンフォース + 再評価テストで担保

本アプリのテスト基盤は **RefreshDatabase グローバル + --parallel** (AGENTS.md / tests/Pest.php)。各テストは単一の共有トランザクション内で走るため、**真に並行な 2 つのコミット済みトランザクションで実ロック競合を再現することは構造的に不可能** です (別コネクションのコミット済みデータが共有 tx から見えない。個別 DatabaseTransactions は禁止事項5)。これは詳細設計レビュー (design-review Round 3 で APPROVED) で確定した検証方針でもあります。

その代わり、退行を確実に検知する 3 層で担保します:
1. **sole-gateway drift-guard (新規)**: 未ロックの owner 付与経路が将来混入したら fail。
2. **lockForMembershipWrite drift-guard (施策7)**: 新しい mutating メソッドがロックを呼ばなければ fail + deleteAccount のロック順序 (user→org) 退行を検出。
3. **fresh 状態再評価 Feature テスト**: 「ブロック→2人目オーナー追加後は削除できる (現在状態で再評価)」「2オーナー→片方降格後はブロック」が、ガードが事前取得値ではなくロック下の現在 DB 状態で判定する (TOCTOU 防御の observable な挙動) ことを固定。

タイミング依存の flaky な擬似並行テストを足すより、この静的エンフォース + 再評価テストの方が退行検知として確実と判断しました (思考原則「仕組みが機能しているかを見よ」)。より良い実証手段のご提案があれば伺います。

## [Suggestion] 3件 → すべて対応
- beforeDelete docblock/@param に「例外を投げてはならない (投げると削除全体 rollback)」を明記。
- Settings/Index.svelte の initialUser を統一 props 参照へ (多重キャスト排除)。
- JS に onError→ダイアログ closes テストを追加 (recent-auth precheck を fresh でスタブ、router.delete の onError を発火して確認ダイアログ消失を検証)。

## 品質ゲート (再実行)
- composer phpstan: No errors (L10) / vendor/bin/pint --test: passed / pnpm lint / typecheck: passed
- Architecture テスト: 2 tests passed (drift-guard 2種) / JS: 483 passed
- (full composer test / pnpm build はコミット前に最終再実行)

---

## 差分 (Round 1 からの delta。app サービス / svelte / architecture テスト / JS テスト)

```diff
diff --git a/app/Services/Organization/OrganizationMembershipService.php b/app/Services/Organization/OrganizationMembershipService.php
index 16920a8..2cb5e04 100644
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
@@ -426,6 +455,137 @@ public function transferOwnership(Organization $organization, User $from, User $
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
+     * 直列化の仕組み (owner 判定は role_user を読むが role_user を直接ロックはしない):
+     * 組織の owner 集合を変える書き込み経路 (changeRole / transferOwnership / removeMember /
+     * applyConsoleRole / joinOrganization) はすべて自 tx 冒頭で `lockForMembershipWrite`
+     * により対象 organizations 行をロックする (施策7 の drift-guard が新経路の登録を強制し、
+     * 施策8b の role-grant sole-gateway テストが本サービス外の owner 付与を禁止する)。
+     * よって「organizations 行」が owner 集合変更の共通 mutex となり、deleteAccount が自分の
+     * 所属組織行をすべてロックしている間は、それらの組織の owner 数を変える並行書き込みは
+     * ブロックされる (集約ルート行ロックで子テーブル書き込みを直列化する既存パターン。
+     * cf. AGENTS.md ドメイン規約1 の VideoManual lockForUpdate)。step1 の user 行ロックは
+     * 「新組織への owner 移譲で所属集合そのものが増える」race を封じる。
+     *
+     * $beforeDelete はガード通過後・削除直前 (user 行が存在するうち・ロック下) に実行する
+     * フック。呼び出し側のセッション破棄 (Auth::logout) をここで行うことで、ログアウトが
+     * 発火する監査イベント (logout) を user 行が存在する間に記録できる (削除後だと user_id の
+     * FK 違反になり記録が失われる)。ブロック時はガードが先に例外を投げ、フックは実行されない
+     * (ブロックされたユーザーはログアウトされない)。**フックは例外を投げてはならない**
+     * (投げると削除トランザクション全体が rollback する)。
+     *
+     * @param  (\Closure(): void)|null  $beforeDelete  例外を投げないこと (投げると削除全体が rollback)
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
index fda6d65..234583c 100644
--- a/resources/js/pages/Settings/Index.svelte
+++ b/resources/js/pages/Settings/Index.svelte
@@ -1,5 +1,6 @@
 <script lang="ts">
     import { page, router, useForm } from "@inertiajs/svelte";
+    import Alert from "@/components/atoms/Alert.svelte";
     import Button from "@/components/atoms/Button.svelte";
     import Card from "@/components/atoms/Card.svelte";
     import Input from "@/components/atoms/Input.svelte";
@@ -12,10 +13,29 @@
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
 
-    const initialUser = (page.props as unknown as SharedProps).auth?.user ?? null;
+    // ブロック時にサーバーが返す errors.account を表示文字列へ正規化 (string | string[] 両対応)
+    const accountError = $derived.by((): string | null => {
+        const err = props.errors?.account;
+        if (err === undefined) return null;
+        return Array.isArray(err) ? (err[0] ?? null) : err;
+    });
+
+    const initialUser = props.auth?.user ?? null;
 
     /**
      * Fortify の PUT /user/profile-information は errorBag (updateProfileInformation)
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
diff --git a/tests/Architecture/MembershipWriteLockInventoryTest.php b/tests/Architecture/MembershipWriteLockInventoryTest.php
new file mode 100644
index 0000000..df90afe
--- /dev/null
+++ b/tests/Architecture/MembershipWriteLockInventoryTest.php
@@ -0,0 +1,116 @@
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
+
+/*
+ * role-grant sole-gateway drift-guard。
+ *
+ * deleteAccount の孤児化ガードは「組織の owner 集合を変える書き込みは必ず lockForMembershipWrite で
+ * その組織行をロックする」ことを直列化の前提にしている (organizations 行が owner 変更の共通 mutex)。
+ * この前提は Laratrust ロール付与 (addRole/removeRole/syncRoles) が **ロック済みサービスメソッド経由
+ * のみ** で行われて初めて成立する。本テストは owner を付与し得るロール書き込みが
+ * OrganizationMembershipService (全経路ロック済み) と OrganizationProvisioningService
+ * (新規組織生成時の creator への Owner 付与のみ = 既存組織の owner 集合は変えない bootstrap 例外)
+ * 以外に現れないことを静的に強制し、未ロック経路の混入 (直列化の破れ) を検出する。
+ */
+test('Laratrust ロール付与は既知のロック済みサービス経由のみ (owner 変更の直列化前提を守る)', function (): void {
+    $appDir = dirname(__DIR__, 2).'/app';
+    $allowed = [
+        'Services/Organization/OrganizationMembershipService.php', // 全経路 lockForMembershipWrite 済み
+        'Services/Organization/OrganizationProvisioningService.php', // 新規組織の creator への Owner 付与のみ
+    ];
+
+    $iterator = new RecursiveIteratorIterator(
+        new RecursiveDirectoryIterator($appDir, FilesystemIterator::SKIP_DOTS),
+    );
+    $offenders = [];
+    /** @var SplFileInfo $file */
+    foreach ($iterator as $file) {
+        if ($file->getExtension() !== 'php') {
+            continue;
+        }
+        $relative = str_replace($appDir.'/', '', $file->getPathname());
+        if (in_array($relative, $allowed, true)) {
+            continue;
+        }
+        $contents = file_get_contents($file->getPathname()) ?: '';
+        if (preg_match('/->(addRole|removeRole|syncRoles)\(/', $contents) === 1) {
+            $offenders[] = $relative;
+        }
+    }
+
+    expect($offenders)->toBe(
+        [],
+        'Laratrust ロール書き込みは lockForMembershipWrite 済みのサービス経由のみに限定すること '
+        .'(未ロック経路は deleteAccount の孤児化ガードの直列化前提を破る)。',
+    );
+});
diff --git a/tests/js/pages/SettingsIndex.test.ts b/tests/js/pages/SettingsIndex.test.ts
new file mode 100644
index 0000000..8d9c4ab
--- /dev/null
+++ b/tests/js/pages/SettingsIndex.test.ts
@@ -0,0 +1,160 @@
+import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
+import { cleanup, fireEvent, render, screen, waitFor } from "@testing-library/svelte";
+
+/*
+ * プロフィール設定画面 (T025: 唯一オーナーのアカウント削除ガード)。
+ * - soleOwnedOrganizations 非空 → 警告 + 各組織の /organizations/{slug}/settings リンク描画
+ * - 空 → 警告非表示
+ * - errors.account (string / string[] 両対応) → danger Alert 表示
+ * - 警告と errors.account の同時表示 (両立)
+ * - 削除ボタンは常に有効 (AGENTS.md 禁止事項 8: disabled 不使用)
+ * - 削除 (router.delete) の onError はダイアログを閉じる (押下後に理由が見える)
+ */
+
+const { pageState, routerDeleteMock } = vi.hoisted(() => ({
+    pageState: {
+        props: {} as Record<string, unknown>,
+        url: "/settings",
+    },
+    routerDeleteMock: vi.fn(),
+}));
+
+vi.mock("@inertiajs/svelte", async (importOriginal) => ({
+    ...(await importOriginal<typeof import("@inertiajs/svelte")>()),
+    router: { delete: routerDeleteMock },
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
+/** recent-auth precheck (/recent-auth/status) を fresh 応答でスタブする */
+function stubRecentAuthFresh(): void {
+    vi.stubGlobal(
+        "fetch",
+        vi.fn(() =>
+            Promise.resolve({
+                ok: true,
+                status: 200,
+                json: () =>
+                    Promise.resolve({
+                        recent: true,
+                        passwordSet: true,
+                        availableProviders: [],
+                        canSatisfy: true,
+                        confirmedAt: 1,
+                    }),
+            }),
+        ),
+    );
+}
+
+/** router.delete 第2引数 (visit options) の onError を取り出す */
+interface DeleteVisitOptions {
+    onError?: () => void;
+    onStart?: () => void;
+    onFinish?: () => void;
+}
+
+beforeEach(() => {
+    setProps();
+});
+
+afterEach(() => {
+    cleanup();
+    vi.unstubAllGlobals();
+    routerDeleteMock.mockReset();
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
+
+    it("削除の onError はダイアログを閉じ (ブロック時に Alert を露出できる)", async () => {
+        stubRecentAuthFresh();
+        render(Index, { props: {} });
+
+        // 削除ボタン → 確認ダイアログ → 確定 (recent-auth precheck fresh → router.delete)
+        await fireEvent.click(screen.getByTestId("delete-account-button"));
+        expect(screen.getByTestId("delete-account-dialog")).toBeInTheDocument();
+        await fireEvent.click(screen.getByRole("button", { name: "削除する" }));
+
+        // router.delete が /settings/account に onError 付きで呼ばれる
+        await waitFor(() => expect(routerDeleteMock).toHaveBeenCalled());
+        const call = routerDeleteMock.mock.calls.at(-1);
+        expect(call?.[0]).toBe("/settings/account");
+        const options = call?.[1] as DeleteVisitOptions;
+        expect(typeof options.onError).toBe("function");
+
+        // onError 発火 → 確認ダイアログが閉じる
+        options.onError?.();
+        await waitFor(() =>
+            expect(screen.queryByTestId("delete-account-dialog")).toBeNull(),
+        );
+    });
+});
```
