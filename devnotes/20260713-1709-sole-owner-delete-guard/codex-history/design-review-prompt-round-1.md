【アプリの使命 (North Star) — AGENTS.md より】
**AI-CUE** は、現場に既にある作業手順書(SOP)を起点に、AI が撮るべきカットを設計した動画シナリオを生成し、スマホ(PWA)でナビゲーション撮影することで、専門知識ゼロの現場作業者でも標準化されたマニュアル動画を作れるようにする。組織(現場)が管理不能になるとこの運用が破綻するため、組織運用の可用性保護は使命の前提。

【禁止事項 — AGENTS.md より】
1. テストなしの実装完了 2. PHPStan widen/baseline 3. dev DB 破壊操作 4. `response()->json()` 直書き(DTO/JsonResource/Inertia) 5. Prism 直呼び 6. prompt 文字列直書き 7. 操作系 POST 応答の `redirect()->intended()` 8. 必須条件未充足でボタン disabled(押下時にエラー表示)

【思考原則】仮説を立てる/データに真摯/先人の知恵(Laravel/Svelte)/名前に立ち返る/機能しない段階で値を弄らない。
【ツール使用制限】コマンド実行・ファイル書き込みは行わず、提供テキストの分析に集中。ファイル読み込みは許可。

---

あなたは経験豊富な Web アプリアーキテクトです。Laravel + Svelte 改善の詳細設計をレビューしてください。

【前提環境】PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript / PHPStan level 10 / Pest (RefreshDatabase グローバル + --parallel, 本番同等 PostgreSQL) / DTO+JsonResource は REST 用・Inertia props はプレーン配列 / Laratrust RBAC (Organization→Team→Project, strict_check=true, laratrust_team_id 明示)。

【レビュー観点】
1. コードの正確性(ロジック/エッジケース/null 安全)
2. 既存コードとの整合性(命名/パターン/API)
3. PHPStan level 10 適合(型/generics/Assert)
4. テスト計画の網羅性(各施策に Pest, RefreshDatabase 準拠)
5. DTO/JsonResource パターン遵守
6. Inertia Props vs API Response の使い分け
7. 副作用・後退リスク
8. 波及変更の網羅性(TypeScript 型定義/API Resource/テスト)
9. セキュリティ(認可/入力バリデーション/OWASP/テナント不変条件)
10. DESIGN.md 準拠(UI 変更: token 経由・hex 直書き禁止)
11. Atomic Design 準拠(atoms/molecules/organisms 責務・Lucide アイコン)

【出力形式】各施策ごとに APPROVE / REQUEST_CHANGES、指摘は [Critical][Warning][Suggestion]、Critical/Warning に必ず修正案、全体判定 APPROVED / CHANGES_REQUESTED、日本語。

【重要な背景 (概念設計は gpt-5.4 と 4 ラウンド合議し APPROVED 済み)】
- finding F-H5: 唯一 Owner がアカウント削除しても孤児化ブロックが無く即削除→残存メンバーが管理者不在で詰む。
- 全ユーザーは登録時に個人組織 (is_personal) の唯一 Owner になる。よって「唯一 Owner ならブロック」は全削除を不能にする。正しい述語 = Owner かつ他 Owner 無し かつ他に1人以上メンバーが残る組織。
- 並行性: concept 合議で「canonical ロック順序 users(id昇順)→organizations(id昇順) の共通境界」に確定。真の race テストは RefreshDatabase (単一 tx 包み) 下で決定的再現不可のため、構造 (lockForUpdate) + drift-guard Architecture テストで担保する方針も合議済み。
- 監査記録 (SecurityEventRecorder::record) は純 DB insert (event dispatch 無し) なので削除と同一 tx 内・削除直前で正しい。

---

## 詳細設計書

（以下は devnotes/20260713-1709-sole-owner-delete-guard/detailed-design.md 全文）

# 詳細設計: sole-owner-delete-guard

bug-hunt finding **F-H5 (High, broken_flow)**: 組織の唯一 Owner がアカウント削除しても
孤児化の警告・ブロックが皆無で即削除され、残存メンバーが管理者不在で取り残される。

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
| 5 | `GET /settings` に `soleOwnedOrganizations` props 追加 | `routes/web.php` | High |
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
        // 1. 対象 User 行を先にロック (未列挙組織への並行 Owner 移譲と直列化)。
        //    その後 organizations 行を昇順ロック → ロック下で最新の所属を再取得。
        $organizationIds = $user->organizations()->orderBy('organizations.id')->pluck('organizations.id')->all();
        /** @var list<int> $organizationIds */
        $this->lockForMembershipWrite([(int) $user->getKey()], $organizationIds);

        // 2. ロック下で述語を再評価 (fresh。事前取得値は使わない)
        $blockers = $this->organizationsBlockingDeletion($user->fresh() ?? $user);
        if ($blockers->isNotEmpty()) {
            $names = $blockers->pluck('name')->implode('、');
            throw ValidationException::withMessages([
                'account' => ["次の組織のオーナーであるため削除できません。先にオーナーを移譲してください: {$names}"],
            ]);
        }

        // 3. 監査記録 (純 DB insert。user_id は nullOnDelete で削除時に null 化される)
        $this->recorder->record(SecurityEventType::AccountDeleted, $user);
        $user->delete();
    });
}
```
- `$user->fresh()` は削除前の再取得 (ロール/所属の最新状態)。`?? $user` は PHPStan の
  `User|null` narrowing (fresh は理論上 null を返し得る型のため Assert 代替の合体演算子)。
- `record` を **トランザクション内・削除直前**に置く (概念設計の確定事項)。ロールバック時は
  監査行も巻き戻る。`SecurityEventRecorder::record` は event dispatch を持たない純 DB insert。

### PHPStan適合チェック
- [x] `pluck('organizations.id')->all()` の戻りに `@var list<int>` を付与し `whereIn` へ渡す。
- [x] `lockForMembershipWrite` 引数 `list<int>` を PHPDoc で明示。
- [x] `deleteAccount` 戻り値 `void`。`ValidationException` を throw で明示。
- [x] `$user->fresh() ?? $user` で `User` に narrowing (`fresh(): ?static`)。
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

    // ロック下で最新状態を再取得して検証 ([R4 Warning] 事前取得モデルを信用しない)
    $memberUserIds = DB::table('organization_user')
        ->where('organization_id', $organization->id)
        ->whereIn('user_id', [$from->getKey(), $to->getKey()])
        ->pluck('user_id')->all();
    if (count($memberUserIds) < 2) {
        throw ValidationException::withMessages(['user_id' => ['移譲先は組織のメンバーである必要があります。']]);
    }
    if ($from->fresh()?->organizationRole($organization) !== OrganizationRole::Owner) {
        throw ValidationException::withMessages(['user_id' => ['オーナーのみ移譲できます。']]);
    }
    // ... 既存の role 入れ替え (from→Admin / to→Owner) は不変
});
```
他メソッド (`changeRole` / `removeMember` / `joinOrganization` / `applyConsoleRole`) は
既存トランザクションの**先頭に** `$this->lockForMembershipWrite([...], [$organization->id]);`
を 1 行足すのみ (ロジック本体・例外・戻り値は不変)。`changeRole` の最終 Owner 再チェック
(`hasAnotherOwner`) はロック取得後に評価されるため直列化される。

### PHPStan適合チェック
- [x] `(int) $from->getKey()` 等で `mixed` を int 化して `list<int>` を満たす。
- [x] `->fresh()?->organizationRole(...)` の null-safe 呼び出し (`fresh(): ?static`)。
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

## 施策5: `GET /settings` に `soleOwnedOrganizations` props 追加

### 変更箇所
- ファイル: `routes/web.php` (L171-173 の closure)

### 波及変更
- TypeScript型定義: 施策6 (Svelte 側で page props の shape を型付け)
- API Resource/DTO: なし (Inertia props はプレーン配列 = 既存慣習)
- テストファイル: 施策8 `ProfileSettingsPropsTest` (新規)

### 現行コード
```php
Route::get('/settings', function () {
    return Inertia::render('Settings/Index');
})->name('settings');
```

### 変更後コード
```php
Route::get('/settings', function (Request $request, OrganizationMembershipService $membership) {
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
})->name('settings');
```
- shape は `list<array{name: string, slug: string}>`。id は出さない (最小表示・移譲導線は slug)。
- `use App\Models\Organization; use App\Models\User; use App\Services\Organization\OrganizationMembershipService; use Illuminate\Http\Request; use Webmozart\Assert\Assert;` を web.php 冒頭に追加 (未 import 分のみ)。

### PHPStan適合チェック
- [x] closure 引数の型を明示 (`Request`, `OrganizationMembershipService`)。
- [x] `Assert::isInstanceOf` で narrowing。
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
```svelte
<script lang="ts">
    // ... 既存 import に追加
    import Alert from "@/components/molecules/Alert.svelte"; // 既存の警告表示 atom/molecule を使用

    interface SoleOwnedOrganization { name: string; slug: string; }
    const soleOwnedOrganizations = $derived(
        (page.props as unknown as { soleOwnedOrganizations?: SoleOwnedOrganization[] })
            .soleOwnedOrganizations ?? [],
    );
    // ブロック時にサーバーが返す errors.account (ValidationException)
    const accountError = $derived(
        (page.props as unknown as { errors?: Record<string, string> }).errors?.account ?? null,
    );
</script>

<DangerZone title="アカウント削除" description="...">
    {#if soleOwnedOrganizations.length > 0}
        <Alert variant="warning" class="mb-3">
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
        <Alert variant="error" class="mb-3">{accountError}</Alert>
    {/if}
    <Button variant="danger-outline" onclick={() => { deleteDialogOpen = true; }} testId="delete-account-button">
        アカウントを削除
    </Button>
</DangerZone>
```
- **削除ボタンは disabled にしない** (禁止事項8)。押下 → 確認 → `router.delete` → ブロック時
  `errors.account` を表示 (押下後に理由が見える)。
- 事前警告 (soleOwnedOrganizations) と移譲導線を常時表示し、詰みを回避させる。
- `Alert` は既存コンポーネントを流用 (無ければ既存の警告表示パターン/`Card`+token で代替。
  **DESIGN.md: color/spacing は token 経由**。hex 直書きしない。アイコンは Lucide)。

### DESIGN.md / Atomic Design 準拠
- [x] 警告表示は既存 molecule (`Alert` 等) を使用。新規 SVG アイコンは作らない (Lucide 前提)。
- [x] 色・角丸・余白は token 経由 (`text-*` / `bg-*` ユーティリティ = tokens.css 由来)。hex 直書き無し。
- [x] `TextLink` (atom) / `DangerZone` (molecule) の既存責務に沿う。

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
「ロック対象 inventory」への登録を強制する。未登録の mutating メソッドがあれば fail =
将来のロック忘れ (規約適用漏れ) を検出する (本リポジトリの inventory テスト慣習に準拠)。

```php
test('OrganizationMembershipService の書き込みメソッドは共通ロック規約 inventory に登録されている', function (): void {
    // 共通ロック境界 (lockForMembershipWrite) を経由すべき mutating public メソッド
    $lockedInventory = [
        'acceptInvitation', 'acceptInvitationIfValid', // → joinOrganization
        'applyConsoleRole', 'changeRole', 'removeMember', 'transferOwnership',
        'deleteAccount',
    ];
    // ロック不要と判断した書き込みメソッド (根拠付き exempt)
    $exempt = [
        'inviteMember',     // 招待レコード生成のみ (membership/role 不変)
        'revokeInvitation', // 招待の論理失効のみ (membership/role 不変)
    ];

    $reflection = new ReflectionClass(OrganizationMembershipService::class);
    $publicMethods = collect($reflection->getMethods(ReflectionMethod::IS_PUBLIC))
        ->reject(fn (ReflectionMethod $m): bool => $m->isConstructor() || $m->getDeclaringClass()->getName() !== OrganizationMembershipService::class)
        ->map(fn (ReflectionMethod $m): string => $m->getName())
        ->all();

    $classified = array_merge($lockedInventory, $exempt);
    $unclassified = array_diff($publicMethods, $classified);

    expect($unclassified)->toBe([], '新しい書き込みメソッドは lockedInventory か exempt に分類し、必要なら lockForMembershipWrite を通すこと');
});
```
- 静的解析で「実際にロックを呼んでいるか」までは強制せず、**分類漏れ**を検出する drift-guard。
  実挙動 (直列化) は施策2/3 のロック実装 + 既存 Feature テストで担保 (概念設計の検証方針)。

### PHPStan適合チェック
- [x] reflection の戻りに型注釈。`array_diff` 結果を `expect`。

### テスト計画
- [x] 現状の public メソッド集合で緑。新規 public メソッド追加時に赤 (drift 検出)。

### リスク
- 分類の網羅性はレビューで担保。過検出時は inventory/exempt を更新。

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
    $second = attachOrganizationMember($organization, OrganizationRole::Admin);
    // 2 人目を Owner に (transferOwnership は from→Admin/to→Owner なので、ここでは
    // owner をもう一段用意するため addRole で Owner を直接付与する Factory 経路を使う)
    $second->addRole(OrganizationRole::Owner->value, $organization->laratrust_team_id);

    $response = $this->actingAs($owner)
        ->withSession(['recent_auth_at' => time()])
        ->delete('/settings/account');

    $response->assertRedirect('/');
    expect(User::query()->whereKey($owner->id)->exists())->toBeFalse();
    expect($second->fresh()->organizationRole($organization))->toBe(OrganizationRole::Owner);
});
```
- 既存 2 ケース (recent-auth 無し / step-up 済み削除+掃除) は変更しない (回帰保証)。
- 「自分のみメンバー」ケースは `createOrganizationWithOwner` (owner 1 人) でも成立するので、
  実装時はより単純に `[$org,$owner]=createOrganizationWithOwner(); ...delete` でも可。

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
- 削除ボタン (`testId="delete-account-button"`) は常に enabled (禁止事項8)。
- `errors.account` を props に与えると error Alert が表示される。
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


---

## 関連する現行コード (抜粋)

### app/Http/Controllers/Settings/AccountController.php (全文)
```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Enums\SecurityEventType;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Security\SecurityEventRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Webmozart\Assert\Assert;

/**
 * アカウント削除。password.confirm (step-up) を経由して到達する。
 * 関連データは FK の cascade / nullOnDelete で掃除される。
 */
class AccountController extends Controller
{
    public function destroy(Request $request, SecurityEventRecorder $recorder): RedirectResponse
    {
        $user = $request->user();
        Assert::isInstanceOf($user, User::class);

        // 削除前に記録 (user_id は削除後 nullOnDelete で null 化され、イベント自体は残る)
        $recorder->record(SecurityEventType::AccountDeleted, $user);

        Auth::logout();

        DB::transaction(function () use ($user): void {
            $user->delete();
        });

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home')->with('success', 'アカウントを削除しました');
    }
}

```

### OrganizationMembershipService: 既存 mutating メソッド抜粋 (changeRole / removeMember / transferOwnership / hasAnotherOwner / joinOrganization)
```php
    private function joinOrganization(OrganizationInvitation $invitation, Organization $organization, User $user, OrganizationRole $role): void
    {
        DB::transaction(function () use ($organization, $user, $role, $invitation): void {
            // 1. 招待行ロック + 受諾可能状態のロック下再検証 (並行受諾に敗れた側は冪等 no-op)
            /** @var OrganizationInvitation $locked */
            $locked = OrganizationInvitation::query()->whereKey($invitation->id)->lockForUpdate()->firstOrFail();
            if ($locked->isAccepted() || $locked->isRevoked() || $locked->isExpired()) {
                return; // 期限境界の TOCTOU も含めロック下で完全再検証 (敗者は冪等 no-op)
            }

            // 2. org 参加の原子的 INSERT。0 行 = 別経路で join 済み (role/pivot は変更しない。
            //    非正規状態が残る場合も「未割当」として可視化され管理画面から修復できる)
            $joined = DB::table('organization_user')->insertOrIgnore([
                'organization_id' => $organization->id,
                'user_id' => $user->getKey(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($joined === 1) {
                $user->addRole($role->value, $organization->laratrust_team_id);

                $projectRole = $locked->project_role;
                if ($projectRole instanceof ProjectRole) {
                    $project = $this->defaultProjects->resolveForUpdate($organization);
                    $project?->members()->syncWithoutDetaching([
                        $user->id => ['role' => $projectRole->value],
                    ]);
                }
            }

            $locked->forceFill(['accepted_at' => now()])->save();
        });
    }
// ...
    /**
     * ロール変更。Owner への昇格は transferOwnership のみが正規経路
     * (Controller 側のバリデーションが Owner 指定を拒否する)。
     *
     * @throws ValidationException 非メンバー / 最後の Owner の降格
     */
    public function changeRole(Organization $organization, User $target, OrganizationRole $newRole): void
    {
        $currentRole = $target->organizationRole($organization);
        if ($currentRole === null) {
            throw ValidationException::withMessages(['role' => ['このユーザーは組織のメンバーではありません。']]);
        }
        if ($currentRole === $newRole) {
            return;
        }

        // Owner を降格させる場合は他に Owner がいることを要求 (Owner 不在の組織を作らない)
        if ($currentRole === OrganizationRole::Owner && ! $this->hasAnotherOwner($organization, $target)) {
            throw ValidationException::withMessages([
                'role' => ['最後のオーナーは降格できません。先にオーナーを移譲してください。'],
            ]);
        }

        DB::transaction(function () use ($organization, $target, $currentRole, $newRole): void {
            $target->removeRole($currentRole->value, $organization->laratrust_team_id);
            $target->addRole($newRole->value, $organization->laratrust_team_id);
        });
    }

    /**
     * メンバー削除。Owner は削除不可 (先に transferOwnership が必要)。
     *
     * @throws ValidationException 非メンバー / Owner
     */
    public function removeMember(Organization $organization, User $target): void
    {
        if (! $organization->users()->whereKey($target->getKey())->exists()) {
            throw ValidationException::withMessages(['member' => ['このユーザーは組織のメンバーではありません。']]);
        }

        $role = $target->organizationRole($organization);
        if ($role === OrganizationRole::Owner) {
            throw ValidationException::withMessages([
                'member' => ['オーナーは削除できません。先にオーナーを移譲してください。'],
            ]);
        }

        DB::transaction(function () use ($organization, $target, $role): void {
            $organization->users()->detach($target->getKey());
            if ($role !== null) {
                $target->removeRole($role->value, $organization->laratrust_team_id);
            }
            // project pivot 掃除 (org 配下 project に限定。別 org の pivot は維持)
            $this->detachProjectMemberships($organization, $target);
            // 削除した組織を current にしていた場合は外す (次回アクセス時に選び直す)
            if ($target->current_organization_id === $organization->id) {
                $target->forceFill(['current_organization_id' => null])->save();
            }
        });
    }

    /**
     * org 配下 project の pivot を一括 detach する。対象 project id は必ず
     * $organization->projects() (org-scoped relation) から解決する (cross-org 不変条件)。
     * project_members は pivot テーブルで対応する Eloquent モデル・モデルイベントを持たないため、
     * 意図的に素の delete を使う (belongsToMany::detach も pivot イベントは発火しない = 等価)。
     * 挙動契約は ConsoleRoleTransitionTest が固定する。
     */
    private function detachProjectMemberships(Organization $organization, User $target): void
    {
        /** @var list<int> $projectIds */
        $projectIds = $organization->projects()->pluck('projects.id')->all();
        if ($projectIds === []) {
            return;
        }

        DB::table('project_members')
            ->whereIn('project_id', $projectIds)
            ->where('user_id', $target->getKey())
            ->delete();
    }

    /**
     * オーナー移譲。organization_user の両者の行を lockForUpdate で直列化し、
     * 並行移譲による Owner 0 人 / 2 人の中間状態を防ぐ (spirux 方式)。
     *
     * @throws ValidationException from が Owner でない / to が非メンバー / 自己移譲
     */
    public function transferOwnership(Organization $organization, User $from, User $to): void
    {
        if ($from->getKey() === $to->getKey()) {
            throw ValidationException::withMessages(['user_id' => ['自分自身には移譲できません。']]);
        }

        DB::transaction(function () use ($organization, $from, $to): void {
            // 両者のメンバーシップ行をロック (並行する移譲・削除を直列化)。
            // count() + FOR UPDATE は pgsql が集約関数との併用を拒否するため、行を
            // 取得してロードした上で PHP 側で件数を確認する (organization_user は
            // (organization_id, user_id) UNIQUE のため最大 2 行)。
            $lockedUserIds = DB::table('organization_user')
                ->where('organization_id', $organization->id)
                ->whereIn('user_id', [$from->getKey(), $to->getKey()])
                ->lockForUpdate()
                ->pluck('user_id')
                ->all();
            if (count($lockedUserIds) < 2) {
                throw ValidationException::withMessages([
                    'user_id' => ['移譲先は組織のメンバーである必要があります。'],
                ]);
            }

            // ロック取得後に最新状態で Owner を再確認する (TOCTOU 防止)
            if ($from->organizationRole($organization) !== OrganizationRole::Owner) {
                throw ValidationException::withMessages(['user_id' => ['オーナーのみ移譲できます。']]);
            }

            $teamId = $organization->laratrust_team_id;
            $toRole = $to->organizationRole($organization);

            $from->removeRole(OrganizationRole::Owner->value, $teamId);
            $from->addRole(OrganizationRole::Admin->value, $teamId);

            if ($toRole !== null) {
                $to->removeRole($toRole->value, $teamId);
            }
            $to->addRole(OrganizationRole::Owner->value, $teamId);
        });

        $this->recorder->record(SecurityEventType::OwnershipTransferred, $from, [
            'organization_id' => $organization->id,
            'from_user_id' => $from->getKey(),
            'to_user_id' => $to->getKey(),
        ]);
    }

    /**
     * email がこの組織の既存メンバーのものか (blind index 照合)。
     */
    private function emailBelongsToMember(Organization $organization, string $email): bool
    {
        /** @var User|null $user */
        $user = User::whereBlind('email', 'email_index', $email)->first();
        if ($user === null) {
            return false;
        }

        return $organization->users()->whereKey($user->getKey())->exists();
    }

    /**
     * 有効な (未失効・未受諾の) 既存招待があるか。
     */
    private function hasPendingInvitation(Organization $organization, string $email): bool
    {
        return $organization->invitations()
            ->whereBlind('email', 'email_index', $email)
            ->whereNull('accepted_at')
            ->where('expires_at', '>', now())
            ->exists();
    }

    /**
     * target 以外に Owner がいるか。
     */
    private function hasAnotherOwner(Organization $organization, User $target): bool
    {
        return $organization->users()
            ->whereKeyNot($target->getKey())
            ->get()
            ->contains(
                fn (User $member): bool => $member->organizationRole($organization) === OrganizationRole::Owner,
            );
    }
}
```

### routes/web.php (settings closure + transfer-ownership)
```php
Route::get('/settings', function () {
    return Inertia::render('Settings/Index');
})->name('settings');
// ...
Route::post('/organizations/{organization:slug}/transfer-ownership', [OrganizationOwnershipController::class, 'store'])
    ->middleware('recent-auth')->name('organizations.transfer-ownership');
```

### User モデル抜粋
```php
public function organizations(): BelongsToMany { return $this->belongsToMany(Organization::class)->withTimestamps(); }
public function organizationRole(Organization $organization): ?OrganizationRole {
    foreach (OrganizationRole::cases() as $role) {
        if ($this->hasRole($role->value, $organization->laratrust_team_id)) { return $role; }
    }
    return null;
}
```

### Settings/Index.svelte 削除 UI 抜粋
```svelte
function deleteAccount(): void {
    guardWithRecentAuth(() => {
        router.delete("/settings/account", { onStart: () => { deleting = true; }, onFinish: () => { deleting = false; } });
    });
}
// DangerZone > Button(variant="danger-outline", testId="delete-account-button") → ConfirmDialog(onConfirm={deleteAccount})
```
