# Round 2: Round 1 指摘への対応と再レビュー依頼

Round 1 の全指摘に対応しました。**2 点は根拠を添えて反論しています** (既存コードの実査結果に基づく)。
更新後の詳細設計 (全文) を末尾に添付します。**全体判定 (APPROVED / CHANGES_REQUESTED) を出してください。**

## 対応サマリー

| 施策 | 指摘 | 対応 |
|---|---|---|
| 1 | [Critical] `Collection` import 漏れ | **対応**。`use Illuminate\Support\Collection;` を追加 |
| 1 | [Warning] N+1 | **対応**。入力は「Owner 不在の組織」= 通常 0 件の異常系集合であり全組織走査ではない旨を docblock に、判断記録を docs 追記項目に明記 |
| 2 | [Warning] action 導出仕様 / phpdoc | **対応**。導出規則 (理由→action、出力順 TransferOwnership→billing 系、重複なし) と `@param list<AccountDeletionBlockReason>` を固定 |
| 3 | [Critical] 「権威判定」表現が過剰 | **対応**。docblock と docs を「**通常のアプリ経路の**権威判定」に修正。「組織行ロック後に課金を読むのは membership race 対策であり subscription 作成 race の完全排他ではない」「漏れは daily 検知が second layer」を明記 |
| 3 | [Critical] Laratrust pivot 列名 | **対応 (実査で確定)**。`role_user` の team 列は **`team_id`** (migration `2026_06_11_073836_laratrust_setup_tables.php` / `config/laratrust.php` の `foreign_keys.team`)、`organizations` 側は **`laratrust_team_id`**。列名対応表を設計に明記し、先例 (`PersonalPlanService` L188-204) も参照 |
| 3 | [Warning] `filter()` の narrowing | **対応**。`->filter(fn (?Dto $b): bool => $b !== null)` に変更 |
| 3 | [Warning] `hasAnotherOwner()` の N+1 | **対応**。既存実装も同形で組織ごとに呼ぶ = 既存踏襲であることを明記 |
| 4 | [Suggestion] 未使用 import | **対応**。`use App\Models\Organization;` を削除すると明記 |
| 5 | [Warning] action の視認性 / 切替失敗の feedback / `errors.account` 複数行 | **すべて対応**。縦積みコンテナ + testId、`onError` で `switchError` を表示、派生値を `accountErrors: string[]` に変更しリスト表示。vitest ケースも追加 |
| 6 | [Critical] closure DI の前提 | **反論**。既存 `routes/console.php` の `billing:release-stale-reservations` が `function (TicketLedgerService $tickets)` で**型 hint DI をすでに使っている** (`Artisan::command` は `Container::call` でクロージャを解決)。`app()` 解決に書き換えると既存の書き方から乖離するため、既存踏襲を維持し、根拠をコメントに明記 |
| 6 | [Warning] `use RuntimeException;` を追加せよ | **反論**。`routes/console.php` は **namespace 宣言が無い**ため、非複合 use は無効な import になり `tests/Architecture/NoNonCompoundGlobalUseTest.php` が**禁止**している (allowlist 無し)。namespace 無しなので `new RuntimeException(...)` はそのまま global 解決される (既存 `billing:reconcile-auto-recharge` の `onFailure` が同じ書き方)。代わりに**複合名**の import 2 本を追加すると明記 |
| 6 | [Warning] schedule/console の architecture テスト | **対応 (実査: 該当テスト無し)**。`tests/Feature/Console/*` は個別コマンドの振る舞いテストのみで schedule inventory テストは存在しない。その旨を明記し、本コマンド専用の Feature テストを追加 |
| 7 | [Critical] Stripe 非呼び出しが成功経路だけ | **対応**。「課金中でブロックされる経路でも Stripe を呼ばない」を追加 (退会処理が解約を代行しないことの固定) |
| 7 | [Warning] console command のテスト無し | **対応**。`DetectOrphanBillingOrganizationsCommandTest` を新設 (検知なし / あり / report 1 回 / PII 非出力)。`report()` の観測は `ExceptionHandler` の Mockery spy (先例: `TicketPurchaseWebhookTest` L219 付近) |
| 7 | [Warning] cross-team 誤判定テスト | **対応**。「別 team の Owner ロール保持者がいても対象組織の Owner として数えない」を追加 |
| 8 | [Warning] docs の「権威」表現 / [Suggestion] Stripe 仕様の出典 | **対応**。前者は施策 3 と同一対応。後者は「参照元と確認日を併記し数値を鵜呑みで固定しない」と明記 |

## 判定してほしい点

1. 反論 2 点 (closure DI / `use RuntimeException` 不要) は既存コードの実査に照らして妥当か。
2. 残っている [Critical] は無いか。実装者がこの設計だけで着手できる粒度になっているか。

---

## 更新後の詳細設計 (全文)

# 詳細設計: 退会時の課金ガード (account-deletion-billing-guard)

概念設計: [`conceptual-design.md`](./conceptual-design.md) (Codex Round 3 で APPROVED)

---

## 使命・制約 (絶対遵守)

### アプリの使命 (North Star)

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を
生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも
**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置(SECI)。

### 禁止事項 (AGENTS.md)

1. テストなしの実装完了報告 (不変条件は Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作をエージェント判断で実行すること
4. `response()->json()` の直書き (DTO / JsonResource / Inertia を使う)
5. LLM 呼び出しの Prism 直呼び
6. prompt 文字列のコード直書き
7. 操作系 POST の応答での `redirect()->intended()`
8. **必須条件未充足を理由にボタンを disabled にする UI** (押下時にエラー表示する)
9. Artifact の使用

### 本設計で特に効く不変条件

- **課金の冪等性**: webhook は冪等マシン経由。**退会経路から決済事業者 API を呼ばない** (本設計の原則)
- **権限判定は常に `laratrust_team_id` を明示** (strict_check=true)
- **PII は CipherSweet**。検知バッチのログに組織名・メールを載せない (id と件数のみ)
- 課金による判定は `App\Services\Billing` 配下に閉じる (Controller / 他ドメイン service から
  `subscriptions` を直接読まない)

### コーディングルール

- `declare(strict_types=1)` + 日本語コメント。Controller は薄く (Service 委譲)、transaction は Service 内
- PHPStan level 10 (`composer phpstan`) / Pest (`composer test`, `RefreshDatabase` はグローバル適用・`--parallel`)
- テストデータは Factory / `tests/Pest.php` の helper で生成する
- フロントは Svelte 5 runes + DS token のみ。component 階層は単方向 import。アイコンは `@lucide/svelte`

---

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | 課金責務 guard (Billing 層) の新設 | `app/Services/Billing/AccountDeletionBillingGuard.php` (新規) | 必須 |
| 2 | blocker の理由/action 語彙と DTO | `app/Enums/AccountDeletionBlockReason.php` / `app/Enums/AccountDeletionBlockerAction.php` / `app/DataTransferObjects/Organizations/AccountDeletionBlockerDto.php` (すべて新規) | 必須 |
| 3 | `OrganizationMembershipService` の述語拡張 | `app/Services/Organization/OrganizationMembershipService.php` (変更) | 必須 |
| 4 | 表示 props の差し替え | `app/Http/Controllers/Settings/ProfileController.php` (変更) | 必須 |
| 5 | `/settings` の削除前警告を action 別に | `resources/js/pages/Settings/Index.svelte` (変更) / `resources/js/types/account.ts` (新規) | 必須 |
| 6 | 孤児組織の検知バッチ | `routes/console.php` (変更) | 必須 |
| 7 | テスト (Feature / Architecture / vitest) | 下記 §テスト計画 | 必須 |
| 8 | ドキュメント | `docs/architecture.md` (変更) | 必須 |

---

## 施策 1: 課金責務 guard (Billing 層) の新設

### 変更箇所

- 新規: `app/Services/Billing/AccountDeletionBillingGuard.php`

### 設計

```php
<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\Billing\SubscriptionState;
use App\Models\Billing\Subscription;
use App\Models\Organization;
use Illuminate\Support\Collection;   // orphanBillingOrganizationIds の引数型

/**
 * 退会 (アカウント削除) ガードのための **課金責務** 判定。
 *
 * **これは entitlement (利用可否) の判定ではない**。利用可否の唯一の窓口は BillingAccess /
 * SubscriptionService::deriveEntitlement であり、本クラスはそれとは別の問い
 * 「**この組織に、将来の請求を発生させうる subscription が残っているか**」に答える。
 * 両者は一致しない (例: PastDue かつ PM 無しは entitlement 上 denied だが請求責務は残りうる)。
 *
 * 判定は subscriptions 行のみを入力にする **読み取り専用**。決済事業者 API は呼ばない
 * (退会処理から Stripe を呼ばない原則。自 DB と外部サービスの二重書き込みを避ける)。
 */
final class AccountDeletionBillingGuard
{
    /**
     * 生きた課金責務があるか。
     *
     *   ある := SubscriptionState::fromSubscription($sub)->grantsAccess()
     *           (= Active / UpgradeRecovery / PastDue) かつ $sub->ends_at === null
     *           を満たす subscription 行が 1 つでも存在する
     *
     * - `paused` / `canceled` / `unpaid` / `incomplete*` は Inactive / Paused に写像されて通過
     *   (請求が発生しない or 終端)。
     * - `ends_at !== null` (= 期末解約予約済み / 終了済み) は通過。Stripe が自動終了させるため
     *   追加請求が発生せず、ここで止めると「解約したのに退会できない」詰みを作る。
     */
    public function hasLiveBillingObligation(Organization $organization): bool
    {
        return $organization->subscriptions()
            ->whereNull('ends_at')
            ->get()
            ->contains(fn (Subscription $sub): bool => SubscriptionState::fromSubscription($sub)->grantsAccess());
    }

    /**
     * Owner が 1 人も居ないのに生きた課金責務が残っている組織 (= 課金孤児)。
     * 検知バッチ専用の読み取り経路 (§施策 6)。
     *
     * 入力は「Owner 不在の組織」だけ (通常 0 件の異常系集合) なので、組織ごとに
     * subscription を引く N+1 を許容する。件数が増えたら exists subquery 化する
     * (判断の記録は docs/architecture.md)。
     *
     * @param  Collection<int, Organization>  $ownerlessOrganizations
     * @return list<int>  organization id のみ (組織名・メール等の PII を載せない)
     */
    public function orphanBillingOrganizationIds(Collection $ownerlessOrganizations): array
    {
        return $ownerlessOrganizations
            ->filter(fn (Organization $org): bool => $this->hasLiveBillingObligation($org))
            ->map(fn (Organization $org): int => (int) $org->getKey())
            ->values()
            ->all();
    }
}
```

### 波及変更

- TypeScript 型定義: なし
- API Resource/DTO: なし (施策 2 で別途)
- テストファイル: 新規 `tests/Feature/Billing/AccountDeletionBillingGuardTest.php`

### PHPStan 適合チェック

- [x] 戻り値型を明示 (`bool` / `list<int>`)
- [x] `Organization::subscriptions()` は Cashier の `HasMany<Subscription>` (モデル差し替え済み)。
      `Subscription` は `App\Models\Billing\Subscription` で解決される (`Cashier::useSubscriptionModel`)
- [x] 配列返却は `list<int>` を phpdoc で明示

---

## 施策 2: blocker の理由 / action 語彙と DTO

### 変更箇所 (すべて新規)

- `app/Enums/AccountDeletionBlockReason.php`
- `app/Enums/AccountDeletionBlockerAction.php`
- `app/DataTransferObjects/Organizations/AccountDeletionBlockerDto.php`
  (`app/DataTransferObjects/Organizations/` ディレクトリを新設)

### 設計

```php
/**
 * 退会がブロックされる理由 (**サーバ内部の語彙**)。
 * 画面へは載せない (wire に載せるのは AccountDeletionBlockerAction)。
 * ValidationException の文言生成にだけ使う。
 */
enum AccountDeletionBlockReason: string
{
    /** 唯一 Owner のまま退会すると、他のメンバーが Owner 不在の組織に取り残される */
    case OwnerlessMembers = 'ownerless_members';
    /** 唯一 Owner のまま退会すると、生きた課金責務が引受先不在で残る */
    case ActiveBilling = 'active_billing';
}
```

```php
/**
 * ブロックされたユーザーが取るべき「次の一手」。**表示時点のヒント**であり権威ではない
 * (削除時にサーバがロック下で再評価する)。
 * 値集合は resources/js/types/account.ts の TS union と同期する
 * (AccountDeletionBlockerActionTsSyncInvariantTest が固定)。
 */
enum AccountDeletionBlockerAction: string
{
    /** 別メンバーへオーナーを移譲する (/organizations/{slug}/settings) */
    case TransferOwnership = 'transfer_ownership';
    /** サブスクリプションを解約する (/billing。blocker が current org のとき) */
    case OpenBilling = 'open_billing';
    /** 組織を切り替えてから請求設定を開く (blocker が current org でないとき) */
    case SwitchOrganizationThenOpenBilling = 'switch_organization_then_open_billing';
}
```

```php
/**
 * 退会をブロックしている組織 1 件分。
 *
 * 算出は OrganizationMembershipService::organizationsBlockingDeletion() の 1 本だけ。
 * 「削除前の予告 (Inertia props)」と「ブロック時の応答 (ValidationException)」の両方が
 * この DTO を入力にする (文言の二重管理を作らない)。
 *
 * @phpstan-type AccountDeletionBlockerShape array{
 *   name: string,
 *   slug: string,
 *   actions: list<string>
 * }
 */
final readonly class AccountDeletionBlockerDto
{
    /**
     * @param  list<AccountDeletionBlockReason>  $reasons  サーバ内部語彙 (wire に載せない)
     * @param  list<AccountDeletionBlockerAction>  $actions  表示用の次の一手
     */
    public function __construct(
        public string $name,
        public string $slug,
        public array $reasons,
        public array $actions,
    ) {}

    /**
     * 理由集合と「blocker が current org か」から action 列を導出して組み立てる。
     * $isCurrentOrganization は**呼び出し時点の派生値**で、DTO は結果 (action) だけを持つ。
     *
     * action 導出規則 (実装者依存にしない):
     *   1. OwnerlessMembers        → TransferOwnership
     *   2. ActiveBilling かつ current org      → OpenBilling
     *   3. ActiveBilling かつ current org でない → SwitchOrganizationThenOpenBilling
     *   - 出力順は **TransferOwnership → billing 系** で固定 (画面の並びを安定させる)
     *   - 同じ action は重複させない (理由 1 つにつき action 1 つ・billing 系は排他)
     *
     * @param  list<AccountDeletionBlockReason>  $reasons
     */
    public static function build(
        Organization $organization,
        array $reasons,
        bool $isCurrentOrganization,
    ): self;

    /** ブロック時のエラーメッセージ 1 行 (「〜のため削除できません。〜してください」)。 */
    public function message(): string;

    /** @return AccountDeletionBlockerShape */
    public function toArray(): array;   // reasons は載せない
}
```

**文言 (サーバ側 = `message()`)**:

| 理由集合 | メッセージ |
|---|---|
| `OwnerlessMembers` のみ | `「{name}」は他のメンバーが残るため、オーナーを移譲してから退会してください。` |
| `ActiveBilling` のみ | `「{name}」に有効なサブスクリプションが残っています。解約してから退会してください。` |
| 両方 | `「{name}」はオーナーの移譲と、有効なサブスクリプションの解約が必要です。` |

`ValidationException` は `'account' => [各 blocker の message(), ...]` (複数行) を投げる。
Inertia は `errors.account` を配列で渡すため、フロント既存の `string | string[]` 正規化がそのまま効く。

### 波及変更

- TypeScript 型定義: `resources/js/types/account.ts` (施策 5)
- テストファイル: `tests/Feature/Auth/AccountDeletionTest.php` (文言アサーション),
  `tests/Feature/Settings/ProfileSettingsPropsTest.php` (props 形状),
  `tests/Architecture/AccountDeletionBlockerActionTsSyncInvariantTest.php` (新規)

---

## 施策 3: `OrganizationMembershipService` の述語拡張

### 変更箇所

- `app/Services/Organization/OrganizationMembershipService.php`
  - コンストラクタに `AccountDeletionBillingGuard $billingGuard` を追加
  - `organizationsBlockingDeletion(User $user): Collection` の**戻り値型を変更**
  - `deleteAccount()` のメッセージ生成を DTO 経由に変更
  - `organizationsWithoutOwner(): Collection<int, Organization>` を新設 (検知バッチ用・読み取り専用)

### 現行コード (抜粋)

```php
/** @return Collection<int, Organization> */
public function organizationsBlockingDeletion(User $user): Collection
{
    return $user->organizations()
        ->withCount('users')
        ->get()
        ->filter(function (Organization $organization) use ($user): bool {
            $usersCount = $organization->getAttribute('users_count');
            Assert::integerish($usersCount);

            return $user->organizationRole($organization) === OrganizationRole::Owner
                && (int) $usersCount > 1
                && ! $this->hasAnotherOwner($organization, $user);
        })
        ->values();
}
```

```php
$blockers = $this->organizationsBlockingDeletion($freshUser);
if ($blockers->isNotEmpty()) {
    $names = $blockers->pluck('name')->implode('、');
    throw ValidationException::withMessages([
        'account' => ["次の組織のオーナーであるため削除できません。先にオーナーを移譲してください: {$names}"],
    ]);
}
```

### 変更後コード

```php
/**
 * 退会をブロックしている組織と理由。
 *
 * 述語:
 *   soleOwned(user, org) := user が Owner かつ 他に Owner がいない
 *   reasons:
 *     - OwnerlessMembers : 他メンバーが 1 人以上残る (孤児化するメンバーが居る)
 *     - ActiveBilling    : 生きた課金責務が残る (AccountDeletionBillingGuard)
 *   blocked := soleOwned かつ reasons が非空
 *
 * 個人組織 (自分だけがメンバー) でも **課金責務があれば blocker になる**。
 * 退会後の組織は Owner 不在で存続し (User 削除では organizations 行は消えない)、
 * アプリには組織削除も解約の主体も無いため、課金が宙づりになるため。
 *
 * 読み取り専用判定 (ロックしない。表示スナップショット用)。**通常のアプリ経路の**権威判定は
 * deleteAccount がロック下で再評価する。課金状態の読み取りを組織行ロック取得**後**に行うのは
 * membership 側の race を封じるためであり、**Cashier (vendor) の WebhookController が
 * subscription 行を作る経路との完全排他ではない**。漏れは daily の
 * billing:detect-orphan-billing-organizations が second layer として拾う
 * (devnotes の概念設計 §4.4)。
 *
 * @return Collection<int, AccountDeletionBlockerDto>
 */
public function organizationsBlockingDeletion(User $user): Collection
{
    $currentOrganizationId = $user->current_organization_id;

    return $user->organizations()
        ->withCount('users')
        ->get()
        ->filter(fn (Organization $organization): bool => $user->organizationRole($organization) === OrganizationRole::Owner
            && ! $this->hasAnotherOwner($organization, $user))
        ->map(function (Organization $organization) use ($currentOrganizationId): ?AccountDeletionBlockerDto {
            $usersCount = $organization->getAttribute('users_count');
            Assert::integerish($usersCount);

            $reasons = [];
            if ((int) $usersCount > 1) {
                $reasons[] = AccountDeletionBlockReason::OwnerlessMembers;
            }
            if ($this->billingGuard->hasLiveBillingObligation($organization)) {
                $reasons[] = AccountDeletionBlockReason::ActiveBilling;
            }
            if ($reasons === []) {
                return null;
            }

            return AccountDeletionBlockerDto::build(
                $organization,
                $reasons,
                $organization->getKey() === $currentOrganizationId,
            );
        })
        // PHPStan level 10 では引数無し filter() が ?Dto → Dto に narrow しきらないため明示する
        ->filter(fn (?AccountDeletionBlockerDto $blocker): bool => $blocker !== null)
        ->values();
}
```

`deleteAccount()` 内 (ロック下の再評価部分):

```php
$blockers = $this->organizationsBlockingDeletion($freshUser);
if ($blockers->isNotEmpty()) {
    throw ValidationException::withMessages([
        'account' => $blockers
            ->map(fn (AccountDeletionBlockerDto $blocker): string => $blocker->message())
            ->all(),
    ]);
}
```

検知バッチ用 (読み取り専用・新設):

```php
/**
 * Owner が 1 人も居ない組織 (通常は 0 件。異常系の検知用)。
 * 読み取り専用でロックしない。role_user は laratrust_team_id で突き合わせる
 * (権限判定は常に team を明示する不変条件)。
 *
 * @return Collection<int, Organization>
 */
public function organizationsWithoutOwner(): Collection
{
    return Organization::query()
        ->whereDoesntHave('users', function (Builder $query): void {
            $query->whereHas('roles', function (Builder $roleQuery): void {
                $roleQuery->where('name', OrganizationRole::Owner->value)
                    ->whereColumn('role_user.team_id', 'organizations.laratrust_team_id');
            });
        })
        ->get();
}
```

> **列名の対応 (混同しやすいので明記)**: Laratrust の pivot は `role_user`、その team 列は
> **`team_id`** (`database/migrations/2026_06_11_073836_laratrust_setup_tables.php` /
> `config/laratrust.php` の `foreign_keys.team = 'team_id'`)。一方 `organizations` 側の
> 列名は **`laratrust_team_id`**。よって突き合わせは
> `whereColumn('role_user.team_id', 'organizations.laratrust_team_id')` が正しい。
> 同型のネスト (`Organization` → `users` → `roles` + 同 `whereColumn`) は
> `PersonalPlanService::hasOtherActiveFreePersonalOrg()` (L188-204) に先例がある。
> **team を明示しない role 照合を書かない** (セキュリティ不変条件「権限判定は常に
> `laratrust_team_id` を明示」)。

### 波及変更

- `app/Http/Controllers/Settings/ProfileController.php`: 戻り値型変更に追随 (施策 4)
- `tests/Architecture/MembershipWriteLockInventoryTest.php`: `$exempt` に
  `organizationsWithoutOwner` を追加 (公開メソッド未分類で fail するため**必須**)
- `tests/Feature/Auth/AccountDeletionTest.php`: `organizationsBlockingDeletion()` の
  戻り値を使う既存アサーション (L86) は `toHaveCount(1)` のままで通る
- ロック順序 guard (同テストの 3.) は `deleteAccount` 本文を静的検査するため、
  **`lockForMembershipWrite(` と `orderBy('organizations.id')` の出現順を変えない**こと

### リスク

- `organizationsBlockingDeletion()` が組織ごとに subscription を引くため N+1。
  ただし (a) 対象は「唯一 Owner の組織」だけに絞られた後 (通常 1〜数件)、
  (b) 呼ばれるのは `/settings` の描画と退会時のみ、(c) 既存実装も同じ形で
  `hasAnotherOwner()` を組織ごとに呼んでおり**既存踏襲**である。
  **先に絞ってから課金を引く**順序を守ること (逆にすると全所属組織で課金クエリが走る)
- `current_organization_id` が null (組織未所属直後) の場合は
  `$organization->getKey() === null` が常に false になり `SwitchOrganizationThenOpenBilling` に倒れる
  = 安全側 (切替導線を出す)

---

## 施策 4: 表示 props の差し替え

### 変更箇所

- `app/Http/Controllers/Settings/ProfileController.php` (L28-36)

### 変更後コード

```php
return Inertia::render('Settings/Index', [
    // 削除前警告用。退会をブロックしている組織と「次の一手」(表示時点のスナップショット。
    // 最終判定は削除時にサーバーが再評価する)。
    'accountDeletionBlockers' => $membership->organizationsBlockingDeletion($user)
        ->map(fn (AccountDeletionBlockerDto $blocker): array => $blocker->toArray())
        ->all(),
    'hasPassword' => $user->hasPassword(),
]);
```

`use App\Models\Organization;` は未使用になるので**削除する** (Pint / PHPStan 対象)。

**prop 名を `soleOwnedOrganizations` → `accountDeletionBlockers` にリネームする**。
意味が「唯一 Owner の組織」から「退会をブロックしている組織」へ変わるため
(AGENTS.md 思考原則 3「後方互換の並走を残さない」= 旧 prop を残さない)。

### 波及変更

- `resources/js/pages/Settings/Index.svelte` (施策 5)
- `tests/Feature/Settings/ProfileSettingsPropsTest.php` (prop 名・形状)
- `tests/js/pages/SettingsIndex.test.ts` (prop 名・描画)

---

## 施策 5: `/settings` の削除前警告を action 別に

### 変更箇所

- 新規: `resources/js/types/account.ts`
- 変更: `resources/js/pages/Settings/Index.svelte` (L24-37 の型 / L315-329 の Alert)

### 新規 TS 型

```ts
/**
 * 退会ガードの Inertia props 型。
 * PHP 側 App\Enums\AccountDeletionBlockerAction /
 * App\DataTransferObjects\Organizations\AccountDeletionBlockerDto::toArray() と対で保守する
 * (値集合の一致は tests/Architecture/AccountDeletionBlockerActionTsSyncInvariantTest が固定する)。
 */

/** PHP: App\Enums\AccountDeletionBlockerAction と対 (値集合を一致させる) */
export type AccountDeletionBlockerAction =
    | "transfer_ownership"
    | "open_billing"
    | "switch_organization_then_open_billing";

export interface AccountDeletionBlocker {
    name: string;
    slug: string;
    actions: AccountDeletionBlockerAction[];
}
```

### Svelte 変更 (要点のみ)

```svelte
{#if blockers.length > 0}
    <Alert type="warning" title="退会するには先に対応が必要です" class="mb-3">
        以下の組織で対応が必要です（削除時にサーバーが再判定します）。
        <ul class="mt-2 list-disc pl-5">
            {#each blockers as blocker (blocker.slug)}
                <li>
                    {blocker.name}
                    <!-- action は 1 件ずつ独立した操作に見えるよう縦積みにする
                         (リンクとボタンが区切りなく連続すると押し間違える) -->
                    <div class="mt-1 flex flex-col items-start gap-1">
                        {#each blocker.actions as action (action)}
                            {#if action === "transfer_ownership"}
                                <TextLink href={`/organizations/${blocker.slug}/settings`}>
                                    オーナーを移譲する
                                </TextLink>
                            {:else if action === "open_billing"}
                                <TextLink href="/billing">サブスクリプションを解約する</TextLink>
                            {:else if action === "switch_organization_then_open_billing"}
                                <Button
                                    variant="ghost"
                                    onclick={() => switchThenBilling(blocker.slug)}
                                    testId="switch-then-billing-button"
                                >
                                    この組織に切り替えて解約する
                                </Button>
                            {/if}
                        {/each}
                    </div>
                </li>
            {/each}
        </ul>
    </Alert>
{/if}
```

```ts
/**
 * 別組織の課金導線。/billing は current org スコープ (route parameter を持たない) のため、
 * 先に組織を切り替える。**成功時のみ** /billing へ進む (失敗時はその場に留まる)。
 * 所属・存在の検査はサーバが権威 (MembershipScopedOrganizationBinder が非所属を 404)。
 */
function switchThenBilling(slug: string): void {
    switchError = null;
    router.post(
        `/organizations/${slug}/switch`,
        {},
        {
            preserveScroll: true,
            onSuccess: () => router.visit("/billing"),
            // 404 / 検証エラー時に無反応に見せない (押したのに何も起きない = 詰みの一種)
            onError: () => {
                switchError = "組織を切り替えられませんでした。時間をおいて再度お試しください。";
            },
        },
    );
}
```

- **削除ボタンは常に有効のまま** (禁止事項 8)。押下 → サーバ再判定 → `errors.account` を danger Alert 表示
- 切替失敗は `switchError` (`$state<string | null>`) を warning Alert 内に表示する。
  Inertia の 404 は例外ページに遷移するため `onError` に来ないケースもあるが、
  「押したのに無反応」を作らないためのフォールバックとして置く
- `errors.account` は**複数行**になった (組織ごとに 1 行) ため、派生値の型を変える:

```ts
/** ブロック時にサーバーが返す errors.account を行の配列へ正規化 (string | string[] 両対応) */
const accountErrors = $derived.by((): string[] => {
    const err = props.errors?.account;
    if (err === undefined) return [];
    return Array.isArray(err) ? err : [err];
});
```

  表示は `{#if accountErrors.length > 0}` の danger Alert 内で `<ul>` として全行を出す
  (現行の `err[0]` のみ表示だと複数組織で情報が落ちる)
- 新規アイコンは使わない (Lucide 以外の SVG を増やさない)。DS token 以外の色・角丸を書かない

### 波及変更

- `tests/js/pages/SettingsIndex.test.ts` (描画・分岐・switch→visit の呼び出し順)

---

## 施策 6: 孤児組織の検知バッチ

### 変更箇所

- `routes/console.php` (既存の「課金 daily バッチ」節に追加)

### 変更後コード

```php
/*
| 課金孤児の検知 (退会ガードの second layer)。
| 退会ガード (AccountDeletionBillingGuard) は通常経路を止めるが、webhook トランザクションと
| 同時刻に退会が commit される競合までは排他しない (subscription 行を作るのは Cashier の
| WebhookController = vendor 側で、自前 listener の排他では覆えないため)。
| 予防で漏れた分と、本機能より前から存在する孤児組織を daily で検知する。
|
| 報告契約 (通知洪水を作らない):
|   - 1 実行につき **集約して 1 回だけ** report() する
|   - 内容は **件数と organization id のみ** (組織名・メール等の PII を載せない)
|   - 未解消なら翌日も同じ内容で再報告する (抑制状態を持たない = 冪等な観測)
*/
// closure の型 hint による DI は既存 `billing:release-stale-reservations`
// (`function (TicketLedgerService $tickets)`) と同じ作法 (Artisan::command は
// Container::call でクロージャを解決する)。新規の書き方を持ち込まない。
// RuntimeException は **import しない**: routes/console.php は namespace 宣言が無く、
// 非複合 use は無効な import になる (NoNonCompoundGlobalUseTest が禁止)。
// namespace 無しファイルなので `new RuntimeException(...)` はそのまま global 解決される
// (既存 `billing:reconcile-auto-recharge` の onFailure と同じ)。
Artisan::command('billing:detect-orphan-billing-organizations', function (
    OrganizationMembershipService $membership,
    AccountDeletionBillingGuard $guard,
) {
    $ids = $guard->orphanBillingOrganizationIds($membership->organizationsWithoutOwner());
    if ($ids === []) {
        $this->info('課金孤児なし');

        return;
    }

    $this->warn(count($ids).' 件の課金孤児組織を検出しました');
    report(new RuntimeException(
        'Owner 不在かつ課金中の組織を検出: count='.count($ids).' ids='.implode(',', $ids),
    ));
})->purpose('Owner 不在かつ生きた課金責務がある組織 (課金孤児) を検知して報告する');

Schedule::command('billing:detect-orphan-billing-organizations')->daily()->onOneServer();
```

`routes/console.php` の import に
`use App\Services\Billing\AccountDeletionBillingGuard;` と
`use App\Services\Organization\OrganizationMembershipService;` を追加する
(いずれも複合名なので上記 invariant に抵触しない)。

### リスク

- 全組織を走査する `whereDoesntHave` は 1 クエリ。daily・組織数規模から性能上の懸念はない
- `report()` はアプリの唯一の運用アラート経路 (`routes/console.php` の既存注記に準拠)
- schedule/console の inventory テストは本リポジトリに存在しない
  (`tests/Feature/Console/*` は個別コマンドの振る舞いテストのみ) ため、
  登録更新は不要。代わりに本コマンド専用の Feature テストを追加する (§テスト計画)

---

## テスト計画

> AGENTS.md 禁止事項 1: 不変条件は Architecture/Feature テストへの登録まで含めて「実装済み」。
> テストファースト (思考原則 5): まず fail を確認してから実装する。

### 新規: `tests/Feature/Billing/AccountDeletionBillingGuardTest.php`

| # | テスト | 期待 |
|---|--------|------|
| 1 | `active` + `ends_at=null` | `hasLiveBillingObligation` = true |
| 2 | `trialing` + `ends_at=null` | true (V10) |
| 3 | `past_due` + `ends_at=null` | true |
| 4 | `paused` | false |
| 5 | `canceled` / `unpaid` / `incomplete` | false (V3 / V11) |
| 6 | `active` + `ends_at` セット (期末解約予約済み) | false (V2) |
| 7 | subscription 行なし (無料枠 personal) | false (V6) |
| 8 | `orphanBillingOrganizationIds` | Owner 不在 + 課金中のみを id で返す (V14) |
| 9 | `organizationsWithoutOwner` の team 境界 | **別組織 (別 team) で Owner ロールを持つユーザー**が所属していても、対象組織の Owner としては数えない (cross-team 誤判定の防止 = 権限判定に team を明示する不変条件) |

`createFakeSubscription($organization, status: ...)` (`tests/Pest.php`) を使う。
`ends_at` は Cashier 列のため `forceFill(['ends_at' => now()->addDays(10)])->save()` で設定する。

### 変更: `tests/Feature/Auth/AccountDeletionTest.php` (既存テストは消さない = 禁止事項 3)

既存 7 本はそのまま維持し (「個人組織なら削除できる」は**課金なし前提**のテストとして残る)、以下を追加:

| # | テスト | 期待 |
|---|--------|------|
| 9 | 課金中 (`active`) の個人組織の唯一 Owner が退会 | 403 ではなく `errors.account`。user 行は残る。文言に「解約」と組織名を含む (V1) |
| 10 | 解約予約済み (`ends_at` セット) の個人組織の唯一 Owner | 削除成功 (V2) |
| 11 | `paused` / `canceled` の個人組織 | 削除成功 (V3) |
| 12 | 課金中組織に 2 人目 Owner がいる | 削除成功 (V4) |
| 13 | 課金中 + 他メンバー有りの唯一 Owner | ブロック。`errors.account` に移譲と解約の**両方**が現れる (V5) |
| 14 | `trialing` の個人組織 | ブロック (V10) |
| 15 | **退会成功経路で Stripe を呼ばない** (V7) | `$this->mock(StripeGatewayInterface::class)` (期待未設定 = 呼ばれたら fail) を bind した状態で削除が成功する |
| 16 | **課金中でブロックされる経路でも Stripe を呼ばない** (V7') | 同じ mock を bind した状態で #9 のブロックが起き、gateway が 1 度も呼ばれない。**「解約を代行しようとしない」= 退会経路から事業者 API を呼ばない原則の本丸**なので、成功経路と両方固定する |

### 変更: `tests/Feature/Settings/ProfileSettingsPropsTest.php`

| # | テスト | 期待 |
|---|--------|------|
| 17 | 唯一 Owner + 他メンバー | `accountDeletionBlockers.0.actions` に `transfer_ownership` (V8) |
| 18 | 課金中の個人組織 (current org) | actions に `open_billing` |
| 19 | 課金中の組織が current org でない | actions に `switch_organization_then_open_billing` (V12) |
| 20 | blocker 無し | `accountDeletionBlockers` が空 |

### 新規: `tests/Feature/Billing/DetectOrphanBillingOrganizationsCommandTest.php`

| # | テスト | 期待 |
|---|--------|------|
| 21 | 孤児なし | exit 0。`report()` が呼ばれない |
| 22 | Owner 不在 + 課金中の組織あり | `report()` が **1 回だけ** 呼ばれる (集約報告)。`ExceptionHandler` の spy で観測する (先例: `tests/Feature/Billing/TicketPurchaseWebhookTest.php` L219 付近) |
| 23 | 報告内容 | メッセージに organization id と件数を含み、**組織名・ユーザー email を含まない** (PII 非出力) |
| 24 | Owner 不在だが課金なし / Owner 在籍で課金中 | 報告されない |

### 組織切替の 404 境界 (V13)

`POST /organizations/{slug}/switch` の非所属 404 は既存の switch 系テストの担当。
実装前に `rg -l "organizations/.*switch|organizations.switch" tests/Feature` で既存を確認し、
**カバーされていれば追加しない** (重複テストを作らない)。無ければ
`tests/Feature/Organizations/` 配下の既存 switch テストに 1 本追加する。

> 既存の switch 系テストがあればそちらに追加する (重複テストを作らない)。
> 実装前に `rg -l "organizations.switch|/switch" tests/Feature` で確認すること。

### 新規: `tests/Architecture/AccountDeletionBlockerActionTsSyncInvariantTest.php`

```php
test('AccountDeletionBlockerAction の PHP enum ⇔ TS union 値集合が一致する', function (): void {
    expect(TsUnionValues::extract('resources/js/types/account.ts', 'AccountDeletionBlockerAction'))
        ->toBe(TsUnionValues::enumStringValues(AccountDeletionBlockerAction::cases()));
});

test('account.ts の抽出不能な union 名は fail する (degenerate PASS 防止の自己検証)', function (): void {
    expect(fn (): array => TsUnionValues::extract('resources/js/types/account.ts', 'NoSuchUnionName'))
        ->toThrow(RuntimeException::class, 'degenerate PASS');
});
```

### 変更: `tests/Architecture/MembershipWriteLockInventoryTest.php`

- `$exempt` に `'organizationsWithoutOwner'` を追加 (読み取り専用・ロック不要の根拠コメント付き)。
  **追加しないと「未分類メソッド」で fail する** (drift-guard が効いている証拠)

### 変更: `tests/js/pages/SettingsIndex.test.ts`

| # | テスト | 期待 |
|---|--------|------|
| 25 | `accountDeletionBlockers` に `transfer_ownership` | 組織設定リンクを描画 |
| 26 | `open_billing` | `/billing` リンクを描画 |
| 27 | `switch_organization_then_open_billing` | 切替ボタンを描画し、クリックで `router.post('/organizations/{slug}/switch')` → `onSuccess` で `router.visit('/billing')` |
| 28 | 切替失敗 (`onError`) | `/billing` へ遷移せず、失敗メッセージを表示する |
| 29 | blocker 空 | 警告非表示 |
| 30 | `errors.account` が配列 (複数行) | **全行**を表示する (string 単体も従来どおり表示) |
| 31 | 削除ボタンは blocker 有無に関わらず有効 (禁止事項 8) | `disabled` にならない |

### 個別 `DatabaseTransactions` を使っていないこと

- 使わない (`tests/Pest.php` で `RefreshDatabase` がグローバル適用済み)

---

## 検証コマンドと期待結果

| コマンド | 期待 |
|---|---|
| `composer test` | 全 green (新規テスト含む) |
| `composer phpstan` | level 10 でエラー 0 |
| `vendor/bin/pint --test` | 差分なし |
| `pnpm lint` / `pnpm typecheck` / `pnpm test` | 全 green |
| `pnpm build` | 成功 |
| `pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages` | 全 green |

> テストレーンはホスト全体で 1 本ずつのグローバルロック配下。待たされるのは正常
> (30 秒ごとの heartbeat が出ている間はハングではない)。kill しない / ロックファイルを消さない。

---

## ドキュメント (施策 8)

`docs/architecture.md` に追記する内容:

1. **退会ガードの不変条件**: 「唯一 Owner かつ (他メンバーが残る ∨ 生きた課金責務がある) 組織があれば
   退会をブロックし、次の一手を提示する」。**通常のアプリ経路の**判定の権威は
   `OrganizationMembershipService::deleteAccount()` のロック下再評価であり、
   **Cashier (vendor) の webhook が subscription 行を作る経路との完全排他ではない** (下記 3)。
2. **退会処理から決済事業者 API を呼ばない原則** (二重書き込みを避ける)。
   固定しているテストは `tests/Feature/Auth/AccountDeletionTest.php` の #15。
3. **予防 + 検知の 2 枚構成**: webhook トランザクションとの競合は排他しない
   (subscription 行の作成は Cashier の `WebhookController` = vendor 側)。
   検知は daily の `billing:detect-orphan-billing-organizations`。
   **監視対象**: 本コマンドの `report()`。
4. **決済事業者側データの運用注記**: 顧客データの消去は削除ではなく非表示化 (redaction) で、
   非表示化は作成から 90 日後のみ・処理に最大 30 日を要する。**アプリからは自動化しない**
   (退会経路から事業者 API を呼ばない原則と整合)。必要時は運用手順で実施する。
   **外部仕様のため、参照元 URL と確認日 (2026-08-05 時点の c2c 台帳経由) を併記し、
   数値を鵜呑みで固定しない** (事業者仕様変更時に更新する対象であることを明示する)。
5. **検知バッチの N+1 の判断記録**: `orphanBillingOrganizationIds()` は組織ごとに
   subscription を引くが、入力が「Owner 不在の組織」= 通常 0 件の異常系集合のため許容。
   件数が増えたら exists subquery 化する。
6. **決済手段の前提**: subscription Checkout は `payment_method_types` を指定せず Stripe ダッシュボード
   設定に委ねている。**非同期決済 (コンビニ払い等) を有効化する場合、`incomplete` を退会ガードで
   通過させている判断を再確認すること** (滞留時間が伸びるため)。

---

## 段階分け

### このタスクでやる

施策 1〜8 (上記すべて)。1 つの TODO として **incremental** で実施する。

### 後続 TODO 候補 (このタスクに含めない)

| 候補 | 前提 / 理由 |
|---|---|
| 退会の猶予期間つき削除 (誤操作救済 + 即時削除の併存) | `users` の物理削除前提 (FK cascade / CipherSweet / 監査 null 化) を作り替える大工事 |
| 規約の保持期間宣言に対応する匿名化処理 | **利用規約の正式文面確定が前提** (`/terms` は現在プレースホルダで保持年数の記述なし) |
| 検知された孤児組織の回収手順 (運用 runbook) | 回収は組織削除 (boundary 外) と事業者 API の話。まず検知を運用に載せてから |
| チケット残高の失効警告 (退会前の情報提示) | 退会を止める理由にならない UX 改善 |

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **incremental** |
| 判断根拠 | 既存の `deleteAccount` ガードと `/settings` 画面を拡張する変更で、新規サブシステムではない。変更点が Billing service・Organization service・Controller・Svelte 1 画面・console 1 コマンドに閉じており、段階的にテスト green を保ちながら進められる |
| 競合リスク | `OrganizationMembershipService` と `Settings/Index.svelte` は他タスクも触りやすいファイル。prop 名リネーム (`soleOwnedOrganizations` → `accountDeletionBlockers`) を含むため、並行タスクがあれば先に取り込む |

---

## 追加の実査結果 (反論の根拠となる現行コード)

### routes/console.php (冒頭と既存コマンド)
<?php

declare(strict_types=1);

use App\Services\Billing\TicketLedgerService;
use App\Services\Capture\StaleUploadReservationSweeper;
use App\Services\Manual\AnalysisJobService;
use App\Services\Manual\RenderJobService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| 課金 cron
|--------------------------------------------------------------------------
| reserve TTL 超過のチケット予約を解放する (2 フェーズ消費の前提となる stale 解放)。
*/
Artisan::command('billing:release-stale-reservations', function (TicketLedgerService $tickets) {
    $released = $tickets->releaseStale();
    $this->info("released {$released} stale reservation(s)");
})->purpose('期限切れ (expires_at 超過) のチケット予約を解放する');

Schedule::command('billing:release-stale-reservations')->everyFiveMinutes();

/*
### tests/Architecture/NoNonCompoundGlobalUseTest.php (冒頭の規約説明)
<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

/*
 * Architecture invariant: **namespace 宣言の無い PHP ファイル**の global スコープに
 * 非複合名の `use` を書かない。
 *
 * SoT = PHP の言語仕様。namespace 無しファイルでの非複合 use は
 *   Warning: The use statement with non-compound name 'X' has no effect
 * を出し、**import として何の効果も持たない** (参照は global にフォールバックする)。
 * `use function` / `use const` でも **まったく同じ warning** が出る (実測):
 *   use RuntimeException;   → Warning ...'RuntimeException'...
 *   use function strlen;    → Warning ...'strlen'...
 *   use const PHP_VERSION;  → Warning ...'PHP_VERSION'...
 *   use function Foo\bar;   → (複合名なので正常)
 *
 * なぜ「出力が汚れるだけ」で済ませないか (実測):
 *   - この warning が set_error_handler に届くかは **環境依存** (opcache 状態 /
 *     ファイルの初回コンパイル時点)。同一 devcontainer で「届く」「届かない」両方を観測した
 *   - 届いた場合、Laravel の HandleExceptions::handleError は
 *     `error_reporting() & $level` (本アプリは -1) で **ErrorException を throw する**
 *   - migration は Migrator が実行時に require する = そこで throw されれば
 *     RefreshDatabase が死に **全テストが全滅する**
 * つまり「今日は raw output 汚染で済んでいるが、いつ全滅へ化けてもおかしくない非決定的な地雷」。
 *
 * 走査対象: git 追跡下の *.php (ただし *.blade.php を除く)。
 * git 管理下に限ることで vendor/ node_modules/ .claude/worktrees/ storage/ を
 * **自動的に**除外できる (明示 exclude リストを保守しなくてよい)。
 * **既知の限界**: 未追跡 (git add 前) のファイルは走査されない。gate が守る境界は
 * commit / CI であり、そこでは必ず追跡下にあるため実効性は損なわれない。
 * git 不在は環境不備として silent skip せず fail させる
 * (tests/js/architecture/pages-path-case-invariant.test.ts と同じ作法)。
 *
 * allowlist は設けない: 非複合 global use に正当な用途は存在しない (常に無効な import)。
 */

/**
### database/migrations/2026_06_11_073836_laratrust_setup_tables.php (role_user)
            $table->timestamps();
        });

        // Create table for associating roles to users and teams (Many To Many Polymorphic)
        Schema::create('role_user', function (Blueprint $table) {
            $table->unsignedBigInteger('role_id');
            $table->unsignedBigInteger('user_id');
            $table->string('user_type');
            $table->unsignedBigInteger('team_id')->nullable();

            $table->foreign('role_id')->references('id')->on('roles')
                ->onUpdate('cascade')->onDelete('cascade');
            $table->foreign('team_id')->references('id')->on('teams')
                ->onUpdate('cascade')->onDelete('cascade');

            $table->unique(['user_id', 'role_id', 'user_type', 'team_id']);
        });

        // Create table for associating permissions to users (Many To Many Polymorphic)
### app/Services/Billing/PersonalPlanService.php (owner role 照合の先例)
    /**
     * 同一 user が declarer または owner (OrganizationRole::Owner) である別の active free
     * personal org が存在するか。
     */
    private function hasOtherActiveFreePersonalOrg(Organization $org, User $user): bool
    {
        return Organization::query()
            ->where('free_plan_code', self::FREE_PLAN_CODE)
            ->whereKeyNot($org->getKey())
            ->where(function (Builder $q) use ($user): void {
                $q->where('personal_declared_by_user_id', $user->getKey())
                    ->orWhereHas('users', function (Builder $uq) use ($user): void {
                        $uq->whereKey($user->getKey())
                            ->whereHas('roles', function (Builder $rq): void {
                                $rq->where('name', OrganizationRole::Owner->value)
                                    ->whereColumn('role_user.team_id', 'organizations.laratrust_team_id');
                            });
                    });
            })
            ->exists();
    }
