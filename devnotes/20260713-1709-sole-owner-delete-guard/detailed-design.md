# 詳細設計: sole-owner-delete-guard

bug-hunt finding **F-H5 (High, broken_flow)**: 組織の唯一 Owner がアカウント削除しても
孤児化の警告・ブロックが皆無で即削除され、残存メンバーが管理者不在で取り残される。

> **レビュー状態**: 概念設計 = gpt-5.4 と 4 ラウンド合議し **APPROVED** /
> 詳細設計 = gpt-5.3-codex と 3 ラウンド合議し **APPROVED** (Round 3, 全施策 APPROVE)。

## 使命・制約（絶対遵守）

### アプリの使命（North Star）
**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した
**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、
専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。
- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(tebiki)と異なり、標準作業を起点に AI が教材設計し撮影を指示する。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置(SECI)。

本改善の使命への寄与: 組織 (現場) が Owner 不在で管理不能になると、メンバー招待・課金・
権限管理・2FA 方針といった運用の根幹が停止し、現場のマニュアル運用そのものが破綻する。
本修正は組織運用の可用性を守り「組織が使い続けられる」前提を保証する。

### 禁止事項
1. テストなしの実装完了報告 (不変条件は Architecture/Feature テスト登録まで含めて実装済み)
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作 (`migrate:fresh` 等)
4. `response()->json()` の直書き (DTO / JsonResource / Inertia を使う)
5. LLM 呼び出しの Prism 直呼び
6. prompt 文字列のコード直書き
7. 操作系 POST 応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI (押下時にエラー表示する)

### コーディングルール
- **PHPStan level 10** 必須 (`composer phpstan`)。Collection generics / `Assert` narrowing を明示。
- **Pest** (`composer test`)。**RefreshDatabase** + `--parallel` グローバル適用 (個別 `DatabaseTransactions` 禁止)。
- テストデータは必ず Factory / 既存ヘルパ (`createOrganizationWithOwner` 等) で生成。
- **DTO + JsonResource** は REST API 用。**Inertia props はプレーン配列** (本アプリの既存慣習)。
- アーリーリターン推奨。`composer fix` (Pint) / `pnpm lint:fix`。
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript。

## 概念設計リファレンス

- [conceptual-design.md](./conceptual-design.md) — 概念設計 (Round 4 APPROVED)
- 概念レビュー: [conceptual-review-round-4.md](./conceptual-review-round-4.md)
- 概念レビューで確定した設計判断 (詳細設計に反映):
  - 判定述語 = **Owner かつ 他 Owner 無し かつ 他に1人以上メンバーが残る**組織 (個人組織は
    「自分のみメンバー」で自動的に許可。`is_personal` を特別扱いしない)。
  - 並行性 = **canonical ロック順序 `users`(id昇順)→`organizations`(id昇順)** の共通境界。
  - サーバー側ブロックが最終権威。UI は事前警告 (スナップショット)。
  - 監査記録は削除と同一トランザクション内・削除直前 (`SecurityEventRecorder` は純 DB insert)。
  - **[R4 Warning] ロック取得後にモデル状態を DB から再取得して検証** (事前取得値を信用しない)。
  - 例外は `ValidationException::withMessages(['account' => '...'])`。

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | 削除ブロック判定 (read) `organizationsBlockingDeletion` | `app/Services/Organization/OrganizationMembershipService.php` | High |
| 2 | 共通ロック helper + ガード付き削除 `deleteAccount` | 同上 | High |
| 3 | 既存 mutating メソッドをロック規約へ統一 | 同上 | High |
| 4 | `AccountController::destroy` をサービス経由に置換 | `app/Http/Controllers/Settings/AccountController.php` | High |
| 5 | `GET /settings` を `ProfileController@index` へ移し `soleOwnedOrganizations` props を返す | `app/Http/Controllers/Settings/ProfileController.php` (新規), `routes/web.php` | High |
| 6 | `Settings/Index.svelte` の警告 + 移譲導線 + エラー表示 | `resources/js/pages/Settings/Index.svelte` | High |
| 7 | ロック規約 drift-guard Architecture テスト | `tests/Architecture/MembershipWriteLockInventoryTest.php` (新規) | High |
| 8 | Feature / JS テスト | `tests/Feature/Auth/AccountDeletionTest.php`, `tests/Feature/Settings/ProfileSettingsPropsTest.php` (新規), `tests/js/pages/SettingsIndex.test.ts` (新規) | High |

---

## 施策1: 削除ブロック判定 (read) `organizationsBlockingDeletion`

UI props 用の読み取り専用判定。ロックしない (表示スナップショット)。権威判定は施策2 が
ロック下で再評価する。

### 変更箇所
- ファイル: `app/Services/Organization/OrganizationMembershipService.php` (public メソッド追加)

### 波及変更
- TypeScript型定義: なし (このメソッド自体は PHP 内部。props 化は施策5/6)
- API Resource/DTO: なし (Inertia props はプレーン配列。施策5 で shape 変換)
- テストファイル: 施策8 (Feature) が挙動を検証

### 変更後コード
```php
use Illuminate\Support\Collection;

/**
 * 削除するとその組織を Owner 不在で残す組織 (= 削除ブロック対象)。
 * 述語: $user が Owner かつ 他に Owner がいない かつ 他に 1 人以上メンバーが残る。
 * 個人組織のように $user が唯一メンバーの組織は「孤児化するメンバーが居ない」ため対象外。
 *
 * @return Collection<int, Organization>
 */
public function organizationsBlockingDeletion(User $user): Collection
{
    return $user->organizations()
        ->withCount('users')
        ->get()
        ->filter(fn (Organization $organization): bool =>
            $user->organizationRole($organization) === OrganizationRole::Owner
            && (int) $organization->getAttribute('users_count') > 1
            && ! $this->hasAnotherOwner($organization, $user))
        ->values();
}
```
- `hasAnotherOwner` (既存 private) をそのまま再利用。
- `users_count` は `withCount('users')` の派生属性 (`organizations` は BelongsToMany)。
- 「他に1人以上メンバー」= `users_count > 1` ($user 自身を含む総数)。

### PHPStan適合チェック
- [x] 戻り値の型 `Collection<int, Organization>` を PHPDoc で明示。
- [x] `getAttribute('users_count')` は `mixed` → `(int)` で narrowing (withCount の派生属性は
      PHPStan が型を知らないため明示キャスト。widen ではなく既知の集約結果の明示)。
- [x] DTO 返却不要 (内部ドメインの Collection。view へは施策5 が配列 shape へ変換)。
- [x] Generics `Collection<int, Organization>` の型パラメータ整合。

### テスト計画
- [x] 施策8 Feature: 唯一Owner+他メンバー → 非空 / 自分のみ → 空 / 複数Owner → 空 / 非Owner → 空。

### リスク
- `withCount` + per-row `organizationRole` (`hasRole`) で N+1 気味だが、1 ユーザーの所属組織数は
  小さく実用上問題ない。既存 `HandleInertiaRequests::organizationsProp` と同水準。

---

## 施策2: 共通ロック helper + ガード付き削除 `deleteAccount`

### 変更箇所
- ファイル: `app/Services/Organization/OrganizationMembershipService.php` (private helper + public メソッド追加)

### 波及変更
- TypeScript型定義: なし
- API Resource/DTO: なし
- テストファイル: 施策8 (Feature `AccountDeletionTest`) / 施策7 (Architecture)

### 変更後コード
```php
/**
 * メンバーシップ書き込みの共通ロック境界。canonical 順序で行ロックを取り、
 * デッドロックを構造的に排除する: **users(id 昇順) → organizations(id 昇順)**。
 * ロック取得後は呼び出し側が最新状態を DB から再取得して判定すること (事前取得値を信用しない)。
 *
 * @param  list<int>  $userIds
 * @param  list<int>  $organizationIds
 */
private function lockForMembershipWrite(array $userIds, array $organizationIds): void
{
    $sortedUserIds = collect($userIds)->unique()->sort()->values()->all();
    if ($sortedUserIds !== []) {
        DB::table('users')->whereIn('id', $sortedUserIds)->orderBy('id')->lockForUpdate()->get();
    }
    $sortedOrgIds = collect($organizationIds)->unique()->sort()->values()->all();
    if ($sortedOrgIds !== []) {
        DB::table('organizations')->whereIn('id', $sortedOrgIds)->orderBy('id')->lockForUpdate()->get();
    }
}

/**
 * アカウント削除。ガードと削除を同一トランザクション + 行ロックで直列化する。
 * 削除するとその組織を Owner 不在で残す組織があれば拒否する (孤児化防止・最終権威)。
 *
 * @throws ValidationException 唯一 Owner かつ他メンバーが残る組織がある
 */
public function deleteAccount(User $user): void
{
    DB::transaction(function () use ($user): void {
        // 1. **対象 User 行を最初にロック** (この後の所属列挙を安定させる。列挙前に user を
        //    ロックしないと、列挙〜user ロック取得の間に別 txn が新組織 B の Owner を user へ
        //    移譲し、B を未検査のまま削除する race が残る。[R2 Critical])
        $this->lockForMembershipWrite([(int) $user->getKey()], []);

        // 2. user ロック下で所属組織を列挙 → organizations 行を昇順ロック
        //    (メンバー追加/移譲経路も user 行をロックするため、ここで列挙は安定する)
        $organizationIds = $user->organizations()
            ->orderBy('organizations.id')
            ->pluck('organizations.id')
            ->map(fn ($id): int => (int) $id)   // 明示 list<int> 化 (PHPStan L10)
            ->values()
            ->all();
        $this->lockForMembershipWrite([], $organizationIds);

        // 3. ロック下で述語を再評価 (fresh。事前取得値は信用しない。null フォールバック禁止)
        $freshUser = $user->fresh();
        Assert::isInstanceOf($freshUser, User::class);
        $blockers = $this->organizationsBlockingDeletion($freshUser);
        if ($blockers->isNotEmpty()) {
            $names = $blockers->pluck('name')->implode('、');
            throw ValidationException::withMessages([
                'account' => ["次の組織のオーナーであるため削除できません。先にオーナーを移譲してください: {$names}"],
            ]);
        }

        // 4. 監査記録 (純 DB insert。user_id は nullOnDelete で削除時に null 化される)
        $this->recorder->record(SecurityEventType::AccountDeleted, $freshUser);
        $freshUser->delete();
    });
}
```
- `$user->fresh()` は削除前の再取得 (ロール/所属の最新状態)。**null フォールバックはしない**:
  `Assert::isInstanceOf($freshUser, User::class)` で即中断 (最終権威フローで想定外状態を飲まない)。
- `record` を **トランザクション内・削除直前**に置く (概念設計の確定事項)。ロールバック時は
  監査行も巻き戻る。`SecurityEventRecorder::record` は event dispatch を持たない純 DB insert。

### PHPStan適合チェック
- [x] `pluck('organizations.id')->map(fn ($id): int => (int) $id)->values()->all()` で明示 `list<int>` 化。
- [x] `lockForMembershipWrite` 引数 `list<int>` を PHPDoc で明示。
- [x] `deleteAccount` 戻り値 `void`。`ValidationException` を throw で明示。
- [x] `Assert::isInstanceOf($freshUser, User::class)` で `User|null` → `User` に narrowing (null フォールバック無し)。
- [x] `(int) $user->getKey()` で `mixed` を int 化。

### テスト計画
- [x] 施策8 `AccountDeletionTest`: 唯一Owner+他メンバー → `ValidationException` (`errors.account`)・
      ユーザー残存 / 自分のみ → 削除成功 / 複数Owner → 削除成功。
- [x] 既存 `AccountDeletionTest` の 2 ケース (recent-auth / 掃除) が緑のまま (回帰なし)。
- [x] 個別 `DatabaseTransactions` を使わない (グローバル `RefreshDatabase`)。

### リスク
- ネストした `DB::transaction` (controller 側では張らない。service が単一の外側 tx)。
- ロック待ち時間: 対象は自ユーザー行 + 自分の所属組織行のみで軽微。

---

## 施策3: 既存 mutating メソッドをロック規約へ統一

`deleteAccount` の判定と直列化させるため、owner 数 / メンバー数を変える既存メソッドを
共通ロック境界に寄せる。**各メソッドの既存トランザクション冒頭に `lockForMembershipWrite`
呼び出しを 1 行挿入**し、ロジック本体は変えない (挙動不変・既存テスト緑)。

### 変更箇所 (すべて `OrganizationMembershipService.php`)
| メソッド | ロックする user / org | 目的 |
|---------|----------------------|------|
| `transferOwnership` (L382) | `[from, to]` + `[org]` | 未列挙組織への Owner 移譲を `deleteAccount` と直列化 (canonical 順序へ寄せる。既存 pivot ロックは置換) |
| `changeRole` (L300) | `[target]` + `[org]` | 別 Owner の並行降格 (a1) を `deleteAccount` と直列化 |
| `removeMember` (L328) | `[target]` + `[org]` | メンバー数変更を直列化 (regy 一貫性) |
| `joinOrganization` (L195) | `[user]` + `[org]` | 並行メンバー追加 (a2) を `deleteAccount` と直列化 |
| `applyConsoleRole` (L241) | `[target]` + `[org]` | `normalizeOrganizationRole` の直接 `addRole` 経路もロック下に |

### 波及変更
- TypeScript型定義 / API Resource / DTO: なし
- テストファイル: 既存 `OwnershipTransferTest` / `ConsoleRoleTransitionTest` /
  `InvitationTest` が緑のまま (挙動不変)。施策7 が規約適用を drift-guard。

### 現行コード (代表: transferOwnership の既存ロック)
```php
DB::transaction(function () use ($organization, $from, $to): void {
    $lockedUserIds = DB::table('organization_user')
        ->where('organization_id', $organization->id)
        ->whereIn('user_id', [$from->getKey(), $to->getKey()])
        ->lockForUpdate()
        ->pluck('user_id')->all();
    if (count($lockedUserIds) < 2) { /* throw */ }
    if ($from->organizationRole($organization) !== OrganizationRole::Owner) { /* throw */ }
    // ... role 入れ替え
});
```

### 変更後コード (transferOwnership 抜粋)
```php
DB::transaction(function () use ($organization, $from, $to): void {
    // canonical 共通ロック境界 (users 昇順 → organizations)。deleteAccount 等と直列化。
    $this->lockForMembershipWrite([(int) $from->getKey(), (int) $to->getKey()], [$organization->id]);

    // ロック下で最新インスタンスを再取得して検証 ([R4/R1 Warning] 事前取得モデル・stale org を信用しない)
    /** @var Organization $freshOrg */
    $freshOrg = Organization::query()->whereKey($organization->getKey())->firstOrFail();
    /** @var User $freshFrom */
    $freshFrom = User::query()->whereKey($from->getKey())->firstOrFail();
    /** @var User $freshTo */
    $freshTo = User::query()->whereKey($to->getKey())->firstOrFail();

    $memberUserIds = DB::table('organization_user')
        ->where('organization_id', $freshOrg->id)
        ->whereIn('user_id', [$freshFrom->getKey(), $freshTo->getKey()])
        ->pluck('user_id')->all();
    if (count($memberUserIds) < 2) {
        throw ValidationException::withMessages(['user_id' => ['移譲先は組織のメンバーである必要があります。']]);
    }
    if ($freshFrom->organizationRole($freshOrg) !== OrganizationRole::Owner) {
        throw ValidationException::withMessages(['user_id' => ['オーナーのみ移譲できます。']]);
    }
    // ... 既存の role 入れ替え (freshFrom→Admin / freshTo→Owner) は不変 (fresh インスタンスで実行)
});
```
**[R1 Critical 対応] 「事前チェック→transaction」構造は TOCTOU を残すため撤廃する。**
`changeRole` / `removeMember` は検証をすべてトランザクション内・ロック取得後に移し、
`fresh()` で最新状態を再取得してから判定する (契約は不変・評価位置のみロック下へ)。

#### changeRole 変更後
```php
public function changeRole(Organization $organization, User $target, OrganizationRole $newRole): void
{
    DB::transaction(function () use ($organization, $target, $newRole): void {
        $this->lockForMembershipWrite([(int) $target->getKey()], [$organization->id]);

        // ロック下で最新状態を再取得 (laratrust のロールキャッシュも fresh で破棄)
        $freshTarget = $target->fresh();
        Assert::isInstanceOf($freshTarget, User::class);

        $currentRole = $freshTarget->organizationRole($organization);
        if ($currentRole === null) {
            throw ValidationException::withMessages(['role' => ['このユーザーは組織のメンバーではありません。']]);
        }
        if ($currentRole === $newRole) {
            return; // 冪等
        }
        if ($currentRole === OrganizationRole::Owner && ! $this->hasAnotherOwner($organization, $freshTarget)) {
            throw ValidationException::withMessages(['role' => ['最後のオーナーは降格できません。先にオーナーを移譲してください。']]);
        }
        $freshTarget->removeRole($currentRole->value, $organization->laratrust_team_id);
        $freshTarget->addRole($newRole->value, $organization->laratrust_team_id);
    });
}
```

#### removeMember 変更後
```php
public function removeMember(Organization $organization, User $target): void
{
    DB::transaction(function () use ($organization, $target): void {
        $this->lockForMembershipWrite([(int) $target->getKey()], [$organization->id]);

        if (! $organization->users()->whereKey($target->getKey())->exists()) {
            throw ValidationException::withMessages(['member' => ['このユーザーは組織のメンバーではありません。']]);
        }
        $freshTarget = $target->fresh();
        Assert::isInstanceOf($freshTarget, User::class);
        $role = $freshTarget->organizationRole($organization);
        if ($role === OrganizationRole::Owner) {
            throw ValidationException::withMessages(['member' => ['オーナーは削除できません。先にオーナーを移譲してください。']]);
        }
        $organization->users()->detach($freshTarget->getKey());
        if ($role !== null) {
            $freshTarget->removeRole($role->value, $organization->laratrust_team_id);
        }
        $this->detachProjectMemberships($organization, $freshTarget);
        if ($freshTarget->current_organization_id === $organization->id) {
            $freshTarget->forceFill(['current_organization_id' => null])->save();
        }
    });
}
```

`joinOrganization` / `applyConsoleRole` は既存トランザクション**先頭**に
`$this->lockForMembershipWrite([(int) $user->getKey()], [$organization->id]);` を挿入する
(本体ロジックは不変。`joinOrganization` は既存の招待行ロック + `insertOrIgnore` を保持し、
その手前で org/user 行ロックを追加。`applyConsoleRole` は `normalizeOrganizationRole` の
直接 `addRole` 経路もロック下に入る)。

### PHPStan適合チェック
- [x] `(int) $from->getKey()` 等で `mixed` を int 化して `list<int>` を満たす。
- [x] `firstOrFail()` + `@var` で fresh インスタンスを非 null に narrowing (`fresh()?->` の null 迂回を排除)。
- [x] `changeRole`/`removeMember` は `$target->fresh()` を `Assert::isInstanceOf` で narrowing。
- [x] 既存メソッドの戻り値型・例外は不変 (型シグネチャ変更なし)。

### テスト計画
- [x] 既存 `OwnershipTransferTest` 全 5 ケースが緑 (挙動不変を保証)。
- [x] 既存 `ConsoleRoleTransitionTest` / `InvitationTest` が緑。
- [x] 施策7 の drift-guard が新規/既存 mutating メソッドのロック登録を強制。

### リスク
- **security-critical service の広域変更**。挙動不変 (ロック追加 + ロック下再取得のみ) を
  既存テストで保証する。デッドロックは canonical 順序 (users→orgs, 各昇順) で構造排除。
- `transferOwnership` の pivot ロック → users 行ロックへの置換は「移譲の直列化基点」を
  変えるため、`OwnershipTransferTest` の並行前提 (docblock) を users 行基点に更新する。

---

## 施策4: `AccountController::destroy` をサービス経由に置換

### 変更箇所
- ファイル: `app/Http/Controllers/Settings/AccountController.php` (L23-41)

### 波及変更
- TypeScript型定義 / API Resource / DTO: なし
- テストファイル: 施策8 `AccountDeletionTest`

### 現行コード
```php
public function destroy(Request $request, SecurityEventRecorder $recorder): RedirectResponse
{
    $user = $request->user();
    Assert::isInstanceOf($user, User::class);
    $recorder->record(SecurityEventType::AccountDeleted, $user);
    Auth::logout();
    DB::transaction(function () use ($user): void { $user->delete(); });
    $request->session()->invalidate();
    $request->session()->regenerateToken();
    return redirect()->route('home')->with('success', 'アカウントを削除しました');
}
```

### 変更後コード
```php
public function destroy(Request $request, OrganizationMembershipService $membership): RedirectResponse
{
    $user = $request->user();
    Assert::isInstanceOf($user, User::class);

    // 唯一 Owner + 他メンバー有りの組織があれば ValidationException(['account'=>...]) で中断。
    // 記録(AccountDeleted) と削除は service の単一トランザクション内・行ロック下で直列化される。
    $membership->deleteAccount($user);

    // 削除成功後のみ後処理 (ブロック時は上で例外伝播し到達しない)。順序は現行踏襲。
    Auth::logout();
    $request->session()->invalidate();
    $request->session()->regenerateToken();

    return redirect()->route('home')->with('success', 'アカウントを削除しました');
}
```
- `SecurityEventRecorder` / `DB` の直接依存は service へ移譲され controller から除去 (import 整理)。
- ブロック時、`ValidationException` は Laravel が自動で `back()->withErrors(['account'=>...])` に
  変換し、Inertia が `$page.props.errors.account` として公開する (`response()->json()` 不使用)。

### PHPStan適合チェック
- [x] `Assert::isInstanceOf($user, User::class)` で `User|AdminUser|null` → `User` に narrowing。
- [x] 戻り値 `RedirectResponse`。
- [x] 不要な `use` (SecurityEventRecorder, DB, SecurityEventType) を削除。

### テスト計画
- [x] 施策8: ブロック時 302 + `assertSessionHasErrors('account')` + ユーザー残存。
- [x] 既存 2 ケース (recent-auth 無しで不可 / step-up 済みで削除+掃除) が緑。

### リスク
- ブロック時のレスポンスは delete → back (302 + errors)。JS 側 `router.delete` は成功リダイレクト
  (`/`) を期待しないため、エラー時は同一ページに留まり `errors.account` を表示 (施策6)。

---

## 施策5: `GET /settings` を `ProfileController@index` へ移し props を返す

[R1 Warning] closure 肥大化を避け、DI/型安全のため専用 controller に移す。

### 変更箇所
- ファイル: `app/Http/Controllers/Settings/ProfileController.php` (新規), `routes/web.php` (L171-173)

### 波及変更
- TypeScript型定義: 施策6 (Svelte 側で page props の shape を型付け)
- API Resource/DTO: なし (Inertia props はプレーン配列 = 既存慣習)
- テストファイル: 施策8 `ProfileSettingsPropsTest` (新規)

### 現行コード (routes/web.php)
```php
Route::get('/settings', function () {
    return Inertia::render('Settings/Index');
})->name('settings');
```

### 変更後コード
```php
// routes/web.php
Route::get('/settings', [ProfileController::class, 'index'])->name('settings');
```
```php
// app/Http/Controllers/Settings/ProfileController.php (新規)
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\User;
use App\Services\Organization\OrganizationMembershipService;
use Illuminate\Http\Request;
use Inertia\Response;
use Webmozart\Assert\Assert;

class ProfileController extends Controller
{
    public function index(Request $request, OrganizationMembershipService $membership): Response
    {
        $user = $request->user();
        Assert::isInstanceOf($user, User::class);

        return Inertia::render('Settings/Index', [
            // 削除前警告用。唯一 Owner で他メンバーが残る組織 (name + 各組織設定への導線 slug)。
            // 表示時点のスナップショット (最終判定は削除時にサーバーが再評価)。
            'soleOwnedOrganizations' => $membership->organizationsBlockingDeletion($user)
                ->map(fn (Organization $organization): array => [
                    'name' => $organization->name,
                    'slug' => $organization->slug,
                ])
                ->values()
                ->all(),
        ]);
    }
}
```
- shape は `list<array{name: string, slug: string}>`。id は出さない (最小表示・移譲導線は slug)。
- `Inertia\Inertia` facade を use (既存 controller と同様)。route の `use App\Http\Controllers\Settings\ProfileController;` を web.php に追加。

### PHPStan適合チェック
- [x] `index` 引数・戻り値 (`Inertia\Response`) を明示。
- [x] `Assert::isInstanceOf` で `User|AdminUser|null` → `User` に narrowing。
- [x] `map` の戻り shape を `array{name:string,slug:string}` で固定 (Organization の name/slug は
      非 null 列。PHPStan が Organization の property 型から解決)。

### テスト計画
- [x] 施策8 `ProfileSettingsPropsTest`: 唯一Owner+他メンバーの owner が `/settings` を開くと
      `soleOwnedOrganizations` に該当組織が含まれる / 個人組織のみのユーザーは空配列。

### リスク
- なし (読み取り専用 props 追加)。

---

## 施策6: `Settings/Index.svelte` の警告 + 移譲導線 + エラー表示

### 変更箇所
- ファイル: `resources/js/pages/Settings/Index.svelte` (DangerZone 周辺 L187-212, script 部)

### 波及変更
- TypeScript型定義: 本コンポーネント内に page prop shape を型定義
  (`Array<{ name: string; slug: string }>`)。`shared-props.ts` は変更不要 (ページ固有 prop)。
- API Resource/DTO: なし
- テストファイル: 施策8 `tests/js/pages/SettingsIndex.test.ts` (新規)

### 変更後コード (要点)
[R1 Critical] `(page.props as unknown as ...)` の多重キャストを排し、**ページ専用型を 1 箇所で
定義**して `page.props` を単一キャストする。`errors.account` は Inertia が `string | string[]`
のどちらでも渡し得るため正規化する。
```svelte
<script lang="ts">
    // ... 既存 import に追加 (Alert は atom。prop は type="success|warning|danger|info")
    import Alert from "@/components/atoms/Alert.svelte";

    // ページ専用 props 型 (SharedProps を継承しページ固有 prop を足す。多重キャスト排除)。
    // [R2 Warning] `SharedProps` に `errors` フィールドは存在しない (確認済み。Inertia が
    // errors を別途注入する) ため、ここでの `errors` 追加は継承衝突しない。
    interface SoleOwnedOrganization { name: string; slug: string; }
    interface SettingsPageProps extends SharedProps {
        soleOwnedOrganizations?: SoleOwnedOrganization[];
        errors?: Record<string, string | string[]>;
    }
    const props = $derived(page.props as unknown as SettingsPageProps);
    const soleOwnedOrganizations = $derived(props.soleOwnedOrganizations ?? []);

    // ブロック時にサーバーが返す errors.account を表示文字列へ正規化 (string | string[] 両対応)
    const accountError = $derived.by((): string | null => {
        const err = props.errors?.account;
        if (err === undefined) return null;
        return Array.isArray(err) ? (err[0] ?? null) : err;
    });
</script>

<DangerZone title="アカウント削除" description="...">
    {#if soleOwnedOrganizations.length > 0}
        <Alert type="warning" title="オーナー移譲が必要です" class="mb-3">
            以下の組織であなたが唯一のオーナーです。アカウントを削除する前に、各組織で
            オーナーを別のメンバーへ移譲してください（削除時にサーバーが再判定します）。
            <ul class="mt-2 list-disc pl-5">
                {#each soleOwnedOrganizations as org (org.slug)}
                    <li>
                        <TextLink href={`/organizations/${org.slug}/settings`}>{org.name} の設定へ</TextLink>
                    </li>
                {/each}
            </ul>
        </Alert>
    {/if}
    {#if accountError}
        <Alert type="danger" class="mb-3">{accountError}</Alert>
    {/if}
    <Button variant="danger-outline" onclick={() => { deleteDialogOpen = true; }} testId="delete-account-button">
        アカウントを削除
    </Button>
</DangerZone>
```
削除リクエストの `onError` でダイアログを閉じ、`errors.account` を DangerZone に露出する
(押下後に理由が見える。ダイアログは残さない):
```ts
function deleteAccount(): void {
    guardWithRecentAuth(() => {
        router.delete("/settings/account", {
            preserveScroll: true,
            onStart: () => { deleting = true; },
            onError: () => { deleteDialogOpen = false; }, // ブロック時: ダイアログを閉じ Alert を表示
            onFinish: () => { deleting = false; },
        });
    });
}
```
- **削除ボタンは disabled にしない** (禁止事項8)。押下 → 確認 → `router.delete` → ブロック時
  `errors.account` を DangerZone に表示 (押下後に理由が見える)。
- 事前警告 (soleOwnedOrganizations) と移譲導線を常時表示し、詰みを回避させる。
- `Alert` は既存コンポーネントを流用 (無ければ既存の警告表示パターン/`Card`+token で代替。
  **DESIGN.md: color/spacing は token 経由**。hex 直書きしない。アイコンは Lucide)。

### DESIGN.md / Atomic Design 準拠
- [x] 警告表示は既存 `Alert` atom (`type="warning"|"danger"`, children Snippet) を使用。新規 SVG は作らない (Lucide 前提)。
- [x] 色・角丸・余白は token 経由 (`text-*` / `bg-*` ユーティリティ = tokens.css 由来)。hex 直書き無し (Alert が DESIGN.md §Alert の配色規約を内包)。
- [x] `TextLink` (atom) / `DangerZone` (molecule) の既存責務に沿う。組織リンクは既存の
      `/organizations/{slug}/settings` route を使い、移譲 UI は新設しない (スコープ外)。

### テスト計画
- [x] 施策8 JS: `soleOwnedOrganizations` 非空で警告 + 各組織リンク (`/organizations/{slug}/settings`)
      が描画される / 空なら非表示 / 削除ボタンは常に enabled / `errors.account` があれば表示。

### リスク
- `Alert` コンポーネントの有無を実装時に確認 (無ければ token ベースで最小実装 or 既存流用)。

---

## 施策7: ロック規約 drift-guard Architecture テスト

### 変更箇所
- ファイル: `tests/Architecture/MembershipWriteLockInventoryTest.php` (新規)

### 波及変更
- なし (テスト追加のみ)

### 設計
`OrganizationMembershipService` の **mutating な public メソッド**を reflection で列挙し、
3 分類 (`directLock` / `delegatedToLocked` / `exempt`) への登録を強制する。加えて
[R1 Critical 対応] **メソッドソースを検査し、実際にロックを呼んでいることを保証**する:
- `directLock` 群: メソッドソースに `lockForMembershipWrite(` が現れること。
- `delegatedToLocked` 群: ロック済み内部メソッド (`joinOrganization(`) 呼び出しが現れること。
- 未分類メソッドがあれば fail (drift 検出)。

```php
test('OrganizationMembershipService の書き込みメソッドは共通ロック規約に準拠する', function (): void {
    // 自身の tx 冒頭で直接ロックする mutating メソッド
    $directLock = ['applyConsoleRole', 'changeRole', 'removeMember', 'transferOwnership', 'deleteAccount'];
    // ロック済み内部メソッド (joinOrganization) 経由で間接的にロックされる受諾経路
    $delegatedToLocked = ['acceptInvitation', 'acceptInvitationIfValid'];
    // ロック不要 (membership/role を変えない) と判断した書き込みメソッド (根拠付き exempt)
    $exempt = [
        'inviteMember',     // 招待レコード生成のみ (membership/role 不変)
        'revokeInvitation', // 招待の論理失効のみ (membership/role 不変)
    ];

    $reflection = new ReflectionClass(OrganizationMembershipService::class);
    $ownPublicMethods = collect($reflection->getMethods(ReflectionMethod::IS_PUBLIC))
        ->reject(fn (ReflectionMethod $m): bool => $m->isConstructor()
            || $m->getDeclaringClass()->getName() !== OrganizationMembershipService::class)
        ->map(fn (ReflectionMethod $m): string => $m->getName())
        ->all();

    // 1. 分類漏れ検出
    $classified = array_merge($directLock, $delegatedToLocked, $exempt);
    expect(array_values(array_diff($ownPublicMethods, $classified)))
        ->toBe([], '新しい書き込みメソッドは directLock / delegatedToLocked / exempt に分類すること');

    // 2. 実ロック呼び出しの静的検査 (メソッド本文を切り出して文字列一致)
    $source = file($reflection->getFileName() ?: '');
    $bodyOf = function (string $method) use ($reflection, $source): string {
        $m = $reflection->getMethod($method);
        return implode('', array_slice($source, $m->getStartLine() - 1, $m->getEndLine() - $m->getStartLine() + 1));
    };
    foreach ($directLock as $method) {
        expect($bodyOf($method))->toContain('lockForMembershipWrite(', "{$method} は lockForMembershipWrite を直接呼ぶこと");
    }
    foreach ($delegatedToLocked as $method) {
        expect($bodyOf($method))->toContain('joinOrganization(', "{$method} はロック済み joinOrganization を経由すること");
    }
});
```
- 文字列検査は AST ほど厳密でないが、**ロック呼び出しの物理的欠落**を確実に検出する軽量 guard。
  実挙動 (直列化) は施策2/3 のロック実装 + 施策8 Feature テストで担保 (概念設計の検証方針)。
- [R3 Suggestion・任意] `deleteAccount` 本文で **最初の `lockForMembershipWrite(` が
  `organizations(` 列挙より前に現れる**ことを追加検査し、ロック順序の将来退行を検出する
  (本文の各トークン出現位置を `strpos` で比較する 1 assertion。承認必須ではないが安価なので同梱)。

### PHPStan適合チェック
- [x] `$reflection->getFileName()` が `string|false` のため `?: ''` で string 化。
- [x] `array_slice` / `implode` の型。`ReflectionMethod::getStartLine()` は `int|false` → 実在メソッドで int。

### テスト計画
- [x] 現状の public メソッド集合 + 各ロック呼び出しで緑。新規 public メソッド追加や
      ロック呼び出し削除時に赤 (drift 検出)。

### リスク
- リファクタで内部呼び出し名が変わると文字列検査を更新する必要 (意図的な脆さ = 規約の可視化)。

---

## 施策8: Feature / JS テスト

### 変更箇所
- `tests/Feature/Auth/AccountDeletionTest.php` (追記)
- `tests/Feature/Settings/ProfileSettingsPropsTest.php` (新規)
- `tests/js/pages/SettingsIndex.test.ts` (新規)

### テスト計画 (Feature: AccountDeletionTest)
```php
test('唯一オーナーで他メンバーが残る場合はアカウント削除がブロックされる', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    attachOrganizationMember($organization, OrganizationRole::Admin); // 孤児化する残存メンバー

    $response = $this->actingAs($owner)
        ->withSession(['recent_auth_at' => time()])
        ->from('/settings')
        ->delete('/settings/account');

    $response->assertRedirect('/settings');
    $response->assertSessionHasErrors('account');
    expect(User::query()->whereKey($owner->id)->exists())->toBeTrue(); // 残存
});

test('唯一オーナーだが自分のみメンバー (個人組織) なら削除できる', function (): void {
    $user = User::factory()->create(); // 登録経路と別に個人組織を明示付与
    $org = app(OrganizationProvisioningService::class)->provisionPersonalOrganization($user);

    $response = $this->actingAs($user)
        ->withSession(['recent_auth_at' => time()])
        ->delete('/settings/account');

    $response->assertRedirect('/');
    expect(User::query()->whereKey($user->id)->exists())->toBeFalse();
});

test('複数オーナーがいれば削除できる', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    // 2 人目の Owner は既存ヘルパで生成 (addRole 直叩きを避ける。owner を増やす
    // ドメイン正規経路は存在しないため attach ヘルパ経路に統一)
    $second = attachOrganizationMember($organization, OrganizationRole::Owner);

    $response = $this->actingAs($owner)
        ->withSession(['recent_auth_at' => time()])
        ->delete('/settings/account');

    $response->assertRedirect('/');
    expect(User::query()->whereKey($owner->id)->exists())->toBeFalse();
    expect($second->fresh()->organizationRole($organization))->toBe(OrganizationRole::Owner);
});

// [R1 Critical] ロック下の再評価が「現在の DB 状態」で行われることの回帰テスト
test('ブロック→2人目オーナー追加後は削除できる (現在状態で再評価)', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    attachOrganizationMember($organization, OrganizationRole::Admin);
    // この時点では唯一 Owner + 他メンバー有り → ブロックされるはず
    expect(app(OrganizationMembershipService::class)->organizationsBlockingDeletion($owner))->toHaveCount(1);

    attachOrganizationMember($organization, OrganizationRole::Owner); // 2 人目 Owner を追加

    $response = $this->actingAs($owner)
        ->withSession(['recent_auth_at' => time()])
        ->delete('/settings/account');

    $response->assertRedirect('/');
    expect(User::query()->whereKey($owner->id)->exists())->toBeFalse();
});

test('2オーナー→片方降格後は唯一オーナー+メンバーで削除がブロックされる', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $second = attachOrganizationMember($organization, OrganizationRole::Owner);
    attachOrganizationMember($organization, OrganizationRole::Member); // 孤児化するメンバー
    // service 正規経路で 2 人目 Owner を Admin へ降格 (owner を 1 人に戻す)
    app(OrganizationMembershipService::class)->changeRole($organization, $second, OrganizationRole::Admin);

    $response = $this->actingAs($owner)
        ->withSession(['recent_auth_at' => time()])
        ->from('/settings')
        ->delete('/settings/account');

    $response->assertSessionHasErrors('account');
    expect(User::query()->whereKey($owner->id)->exists())->toBeTrue();
});
```
- 既存 2 ケース (recent-auth 無し / step-up 済み削除+掃除) は変更しない (回帰保証)。
- 「自分のみメンバー」ケースは `createOrganizationWithOwner` (owner 1 人・他メンバー無し) でも
  成立するため、実装時はより単純に `[$org,$owner]=createOrganizationWithOwner(); ...delete` で可。

### テスト計画 (Feature: ProfileSettingsPropsTest 新規)
```php
test('唯一オーナーは /settings で soleOwnedOrganizations に該当組織を受け取る', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    attachOrganizationMember($organization);

    $this->actingAs($owner)->get('/settings')
        ->assertInertia(fn (Assert $page) => $page
            ->component('Settings/Index')
            ->has('soleOwnedOrganizations', 1)
            ->where('soleOwnedOrganizations.0.slug', $organization->slug));
});

test('孤児化リスクが無いユーザーは soleOwnedOrganizations が空', function (): void {
    [$organization, $owner] = createOrganizationWithOwner(); // owner 1 人・他メンバー無し
    $this->actingAs($owner)->get('/settings')
        ->assertInertia(fn (Assert $page) => $page->has('soleOwnedOrganizations', 0));
});
```
- `Inertia\Testing\AssertableInertia as Assert` を使用 (既存 Inertia テストと同型)。

### テスト計画 (JS: SettingsIndex.test.ts 新規)
- `soleOwnedOrganizations` 非空 → 警告文 + 各組織の `/organizations/{slug}/settings` リンク描画。
- 空 → 警告非表示。
- 削除ボタン (`testId="delete-account-button"`) は常に有効 (`expect(...).toBeEnabled()`。禁止事項8)。
- `errors.account` を props に与えると danger Alert が表示される (string / string[] 両ケース)。
- 警告 (soleOwnedOrganizations) と `errors.account` が **同時に表示**されるケースを 1 本 (両立確認)。
- 既存 `OrganizationsSettings.test.ts` / `SettingsSecurity.test.ts` のレンダリング手法に倣う。

### リスク
- Inertia テストの `assertRedirect('/settings')` は `->from('/settings')` 前提 (ValidationException の
  back 先)。テストで `from` を明示する。

---

## 使命・禁止事項 最終チェック

- [x] 使命寄与: 組織運用の可用性を守り現場のマニュアル運用継続を担保 (North Star の前提)。
- [x] 禁止事項1: 全施策に Pest/JS/Architecture テスト。不変条件は施策7 drift-guard + 施策8 Feature。
- [x] 禁止事項2: PHPStan L10 を widen/baseline せず narrowing (`Assert`/`(int)`/`?->`) で通す。
- [x] 禁止事項4: `response()->json()` 不使用。Inertia render + `ValidationException`→back+errors。
- [x] 禁止事項5 (DatabaseTransactions): 使わない。グローバル `RefreshDatabase`。
- [x] 禁止事項7: 操作系応答は `back()`(自動) / 成功時のみ `redirect()->route('home')` (intended 不使用)。
- [x] 禁止事項8: 削除ボタンを disabled にしない。押下後に `errors.account` を表示。
- [x] セキュリティ不変条件: 権限判定は `organizationRole` (laratrust_team_id 明示) 経由。tenant/actor
      キーを payload から受け取らない (user は `$request->user()` / org は自ユーザーの relation 解決)。

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **incremental** |
| 判断根拠 | 既存の `OrganizationMembershipService` / `AccountController` / `Settings/Index.svelte` / `routes/web.php` への内挿変更が主で、新規モデル・マイグレーション・大規模再構成を伴わない。既存テスト (Ownership/Invitation/ConsoleRole/AccountDeletion) を緑に保ちながら段階的に足せる。 |
| 競合リスク | `OrganizationMembershipService` は他 finding の修正と競合し得るホットスポット。ロック helper 追加は局所的だが、施策3 の既存メソッド改修は同ファイルの並行変更とコンフリクトし得るため、単一ブランチで一括実装する。 |
