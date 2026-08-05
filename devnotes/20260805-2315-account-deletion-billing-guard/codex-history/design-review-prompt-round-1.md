## 使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

## 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。
   招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(Artifact ツールでの成果物公開を行わない。成果物はリポジトリ内のファイルとして出力する)

## セキュリティ不変条件(アプリ都合で緩めない)

詳細と実装手順は `docs/app-integration-guide.md` §7。すべて Architecture テストで強制されている:

1. **tenant キー不信**: ownership/actor/tenant キーを payload から受け取らない
   (`ProhibitsProtectedKeys` + `MassAssignmentSafetyTest`)
2. **子は親に属する**: nested route の不整合は**認可より前に 404**
   (`NestedRouteIdorDefenseTest` の inventory に登録必須)
3. **cross-org 不可**: 組織を跨ぐ read/write をしない(relation / org-scoped 解決経由のみ)
4. **untrusted 文字列は UserInput 型経由でのみ prompt に入れる**
5. **権限判定は常に `laratrust_team_id` を明示**(strict_check=true)
6. **PII(email/name)は CipherSweet**。検索は `whereBlind()`(平文 where は hit しない)
7. **課金の冪等性**: webhook は冪等マシン経由、チケットは reserve→commit/release の 2 フェーズ
8. **外部 URL 取得は SSRF 検査経由**: 外部 URL(特にユーザ入力由来)を取得する機能は
   必ず `Kent013\SsrfPin\UrlSafetyInspector` / `PinnedHttpClient` を通す。
   安全境界は `config/ssrf-pin.php` に pin する(`SsrfPinBoundaryTest` が pin 値を固定)
9. **変更系 route は認可を通る**: POST/PUT/PATCH/DELETE は `Gate::authorize` を通すか、
   exemption inventory へ理由付きで登録する(deny-by-default)。
   **層 2(テナント境界 = 404)は層 3(認可 = 403)より前**(逆にすると存在が漏れる)
   (`ControllerAuthorizationGateTest`)
10. **層 2 は binding の直後・FormRequest より前で閉じる**: binding とテナント境界 404 の間に
    404 以外で短絡する middleware があると **1 bit の存在オラクル**になる。実行順の正本は
    `bootstrap/app.php` の **priority list**(route の宣言順ではない)
    (`ProjectRouteCurrentOrgGuardTest` / `NestedRouteIdorDefenseTest` /
    `TenantBoundaryOrderingTest`)

> **採番の注意**: 本節の番号と `docs/app-integration-guide.md` §7 の番号は **1:1 対応しない**
> (本節 6 = PII CipherSweet / guide 6 = 逆シリアライズ、本節 8 = SSRF / guide 8 = 認可 gate)。
> 相互参照するときは**番号ではなく項目名**で指すこと。既存の参照
> (`docs/app-integration-guide.md` の「§7 不変条件 8」/ stripe webhook migration の「不変条件 7」)
> を壊すため、どちらの側も renumber しない。

> **運用要件 (T108)**: production は `TRUSTED_PROXIES` の**明示宣言が必須**
> (未宣言 / `*` / `REMOTE_ADDR` / 書式不正は `ProductionEnvGuard` が起動時 fail-fast する
> = **初回デプロイ前に設定が要る破壊的変更**)。`trustProxies(at: '*')` はレート制限を
> 総当りに無効化するため復活させない。実 hop 一覧・CIDR の管理主体・変更手順は
> `docs/trusted-proxies-runbook.md` が正本。
【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。

データに真摯に向き合え。想定外のパターンも判断材料になる。

先人の知恵を探せ。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。

仕組みが機能していない段階で値を弄るな。設計の方向性が正しいと確認できてから行え。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

あなたは経験豊富なWebアプリケーションアーキテクトです。Laravel + Svelte アプリケーション改善の詳細設計をレビューしてください。

【前提環境】
- PHP 8.4 + Laravel 12 + Cashier (Stripe) + Svelte 5 + Inertia.js + TypeScript
- PHPStan level 10 / Pest (RefreshDatabase グローバル適用・--parallel)
- DTO + JsonResource パターン / Laratrust RBAC (Organization → Team → Project 階層)
- 本件は c2c 機能台帳で **オーナー裁定済み** の機能。概念設計は別途 Codex レビューで APPROVED 済み (要旨は詳細設計の冒頭リンク先。ここでは詳細設計の実装可能性を見てほしい)

【レビュー観点】
1. コードの正確性（ロジックエラー、エッジケース、null 安全性）
2. 既存コードとの整合性（命名規約、パターン、API）
3. PHPStan level 10 適合性（型安全性、generics、Assert 使用）
4. テスト計画の網羅性（各施策に Pest テスト。既存テストを壊さないか）
5. DTO パターンの遵守 / Inertia Props の使い分け
6. 副作用・後退リスク（既存ユーザーが退会できなくなる、既存テストが落ちる、N+1 等）
7. 波及変更の網羅性（TypeScript 型定義、Inertia props、テストが変更対象に含まれているか）
8. セキュリティ（認可、テナント境界、PII、AGENTS.md のセキュリティ不変条件）
9. Atomic Design / DESIGN.md 準拠（UI 変更部分）

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

---

## 詳細設計書

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
     * @param  \Illuminate\Support\Collection<int, Organization>  $ownerlessOrganizations
     * @return list<int>  organization id (PII を載せない)
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
 * 読み取り専用判定 (ロックしない。表示スナップショット用)。権威判定は deleteAccount が
 * ロック下で再評価する。課金状態の読み取りは組織行ロック取得**後**に行う
 * (webhook との競合の扱いは devnotes の概念設計 §4.4)。
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
        ->filter()
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

> 同型のネスト (`Organization` → `users` → `roles` + `whereColumn('role_user.team_id', ...)`) は
> `PersonalPlanService::hasOtherActiveFreePersonalOrg()` に先例がある。

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
  ただし対象は「唯一 Owner の組織」だけに絞られた後 (通常 1〜数件) で、
  呼ばれるのは `/settings` の描画と退会時のみ。**先に絞ってから課金を引く**順序を守ること
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
                    {#each blocker.actions as action (action)}
                        {#if action === "transfer_ownership"}
                            <TextLink href={`/organizations/${blocker.slug}/settings`}>
                                オーナーを移譲する
                            </TextLink>
                        {:else if action === "open_billing"}
                            <TextLink href="/billing">サブスクリプションを解約する</TextLink>
                        {:else if action === "switch_organization_then_open_billing"}
                            <Button variant="ghost" onclick={() => switchThenBilling(blocker.slug)}>
                                この組織に切り替えて解約する
                            </Button>
                        {/if}
                    {/each}
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
    router.post(
        `/organizations/${slug}/switch`,
        {},
        { onSuccess: () => router.visit("/billing") },
    );
}
```

- **削除ボタンは常に有効のまま** (禁止事項 8)。押下 → サーバ再判定 → `errors.account` を danger Alert 表示
- 既存の `errors.account` 正規化 (`string | string[]`) はそのまま。**複数行を出せるよう
  配列のときは全要素をリスト表示に変える** (現行は `err[0]` のみ表示 = 複数組織で情報が落ちる)
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

### リスク

- 全組織を走査する `whereDoesntHave` は 1 クエリ。daily・組織数規模から性能上の懸念はない
- `report()` はアプリの唯一の運用アラート経路 (`routes/console.php` の既存注記に準拠)

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

### 変更: `tests/Feature/Settings/ProfileSettingsPropsTest.php`

| # | テスト | 期待 |
|---|--------|------|
| 16 | 唯一 Owner + 他メンバー | `accountDeletionBlockers.0.actions` に `transfer_ownership` (V8) |
| 17 | 課金中の個人組織 (current org) | actions に `open_billing` |
| 18 | 課金中の組織が current org でない | actions に `switch_organization_then_open_billing` (V12) |
| 19 | blocker 無し | `accountDeletionBlockers` が空 |

### 新規: `tests/Feature/Organizations/OrganizationSwitchGuardTest.php` へ追加 (無ければ新規)

| # | テスト | 期待 |
|---|--------|------|
| 20 | 非所属 slug で `POST /organizations/{slug}/switch` | 404 (V13。binder の membership スコープ) |

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
| 21 | `accountDeletionBlockers` に `transfer_ownership` | 組織設定リンクを描画 |
| 22 | `open_billing` | `/billing` リンクを描画 |
| 23 | `switch_organization_then_open_billing` | 切替ボタンを描画し、クリックで `router.post('/organizations/{slug}/switch')` → `onSuccess` で `router.visit('/billing')` |
| 24 | blocker 空 | 警告非表示 |
| 25 | `errors.account` が配列 (複数行) | 全行を表示する |
| 26 | 削除ボタンは blocker 有無に関わらず有効 (禁止事項 8) | `disabled` にならない |

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
   退会をブロックし、次の一手を提示する」。判定の権威は
   `OrganizationMembershipService::deleteAccount()` のロック下再評価。
2. **退会処理から決済事業者 API を呼ばない原則** (二重書き込みを避ける)。
   固定しているテストは `tests/Feature/Auth/AccountDeletionTest.php` の #15。
3. **予防 + 検知の 2 枚構成**: webhook トランザクションとの競合は排他しない
   (subscription 行の作成は Cashier の `WebhookController` = vendor 側)。
   検知は daily の `billing:detect-orphan-billing-organizations`。
   **監視対象**: 本コマンドの `report()`。
4. **決済事業者側データの運用注記**: 顧客データの消去は削除ではなく非表示化 (redaction) で、
   非表示化は作成から 90 日後のみ・処理に最大 30 日を要する。**アプリからは自動化しない**
   (退会経路から事業者 API を呼ばない原則と整合)。必要時は運用手順で実施する。
5. **決済手段の前提**: subscription Checkout は `payment_method_types` を指定せず Stripe ダッシュボード
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

## 関連する現行コード (抜粋)

### app/Services/Organization/OrganizationMembershipService.php (関連部分)
    }

    /**
     * 削除するとその組織を Owner 不在で残す組織 (= 削除ブロック対象)。
     * 述語: $user が Owner かつ 他に Owner がいない かつ 他に 1 人以上メンバーが残る。
     * 個人組織のように $user が唯一メンバーの組織は「孤児化するメンバーが居ない」ため対象外。
     *
     * 読み取り専用判定 (ロックしない。表示スナップショット用)。権威判定は deleteAccount が
     * ロック下で再評価する。
     *
     * @return Collection<int, Organization>
     */
    public function organizationsBlockingDeletion(User $user): Collection
    {
        return $user->organizations()
            ->withCount('users')
            ->get()
            ->filter(function (Organization $organization) use ($user): bool {
                // withCount('users') 派生属性。PHPStan は型を知らないため integerish で narrowing。
                $usersCount = $organization->getAttribute('users_count');
                Assert::integerish($usersCount);

                return $user->organizationRole($organization) === OrganizationRole::Owner
                    && (int) $usersCount > 1
                    && ! $this->hasAnotherOwner($organization, $user);
            })
            ->values();
    }

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
        $key = $model->getKey();
        Assert::integer($key);

        return $key;
    }

    /**
     * アカウント削除。ガードと削除を同一トランザクション + 行ロックで直列化する。
     * 削除するとその組織を Owner 不在で残す組織があれば拒否する (孤児化防止・最終権威)。
     *
     * 直列化の仕組み (owner 判定は role_user を読むが role_user を直接ロックはしない):
     * 組織の owner 集合を変える書き込み経路 (changeRole / transferOwnership / removeMember /
     * applyConsoleRole / joinOrganization) はすべて自 tx 冒頭で `lockForMembershipWrite`
     * により対象 organizations 行をロックする (施策7 の drift-guard が新経路の登録を強制し、
     * 施策8b の role-grant sole-gateway テストが本サービス外の owner 付与を禁止する)。
     * よって「organizations 行」が owner 集合変更の共通 mutex となり、deleteAccount が自分の
     * 所属組織行をすべてロックしている間は、それらの組織の owner 数を変える並行書き込みは
     * ブロックされる (集約ルート行ロックで子テーブル書き込みを直列化する既存パターン。
     * cf. AGENTS.md ドメイン規約1 の VideoManual lockForUpdate)。step1 の user 行ロックは
     * 「新組織への owner 移譲で所属集合そのものが増える」race を封じる。
     *
     * $beforeDelete はガード通過後・削除直前 (user 行が存在するうち・ロック下) に実行する
     * フック。呼び出し側のセッション破棄 (Auth::logout) をここで行うことで、ログアウトが
     * 発火する監査イベント (logout) を user 行が存在する間に記録できる (削除後だと user_id の
     * FK 違反になり記録が失われる)。ブロック時はガードが先に例外を投げ、フックは実行されない
     * (ブロックされたユーザーはログアウトされない)。**フックは例外を投げてはならない**
     * (投げると削除トランザクション全体が rollback する)。
     *
     * @param  (\Closure(): void)|null  $beforeDelete  例外を投げないこと (投げると削除全体が rollback)
     *
     * @throws ValidationException 唯一 Owner かつ他メンバーが残る組織がある
     */
    public function deleteAccount(User $user, ?\Closure $beforeDelete = null): void
    {
        DB::transaction(function () use ($user, $beforeDelete): void {
            // 1. 対象 User 行を最初にロック (この後の所属列挙を安定させる。列挙前に user を
            //    ロックしないと、列挙〜user ロック取得の間に別 txn が新組織 B の Owner を user へ
            //    移譲し、B を未検査のまま削除する race が残る)。
            $this->lockForMembershipWrite([$this->keyOf($user)], []);

            // 2. user ロック下で所属組織を列挙 → organizations 行を昇順ロック
            //    (メンバー追加/移譲経路も user 行をロックするため、ここで列挙は安定する)
            /** @var list<int> $organizationIds */
            $organizationIds = $user->organizations()
                ->orderBy('organizations.id')
                ->pluck('organizations.id')
                ->map(function (mixed $id): int {
                    Assert::integer($id);

                    return $id;
                })
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

            // 4. ガード通過後・削除直前のフック (呼び出し側のセッション破棄等。user 行が
            //    存在するうちに認証イベントを発火させる)。
            if ($beforeDelete !== null) {
                $beforeDelete();
            }

            // 5. 監査記録 (純 DB insert。user_id は nullOnDelete で削除時に null 化される)
            $this->recorder->record(SecurityEventType::AccountDeleted, $freshUser);
            $freshUser->delete();
        });
    }

    /**
     * email がこの組織の既存メンバーのものか (blind index 照合)。
     */
### app/Http/Controllers/Settings/AccountController.php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Organization\OrganizationMembershipService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Webmozart\Assert\Assert;

/**
 * アカウント削除。password.confirm (step-up) を経由して到達する。
 * 関連データは FK の cascade / nullOnDelete で掃除される。
 */
class AccountController extends Controller
{
    public function destroy(Request $request, OrganizationMembershipService $membership): RedirectResponse
    {
        $user = $request->user();
        Assert::isInstanceOf($user, User::class);

        // 唯一 Owner + 他メンバー有りの組織があれば ValidationException(['account'=>...]) で中断。
        // 記録 (AccountDeleted) と削除は service の単一トランザクション内・行ロック下で直列化される。
        // Auth::logout はガード通過後・削除直前のフックで呼ぶ (logout 監査イベントを user 行が
        // 存在するうちに記録するため。ブロック時はフックが実行されずログアウトされない)。
        $membership->deleteAccount($user, static fn () => Auth::logout());

        // 削除成功後のみ後処理 (ブロック時は上で例外伝播し到達しない)。
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home')->with('success', 'アカウントを削除しました');
    }
}
### app/Http/Controllers/Settings/ProfileController.php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\User;
use App\Services\Organization\OrganizationMembershipService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Webmozart\Assert\Assert;

/**
 * プロフィール設定画面 (GET /settings)。
 * 削除前警告用に「唯一 Owner で他メンバーが残る組織」のスナップショットを props で返す。
 */
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
            // パスワードカードの出し分け。password 未設定ユーザーに current_password 必須の
            // 変更フォームを出すと必ず失敗する (踏破不能 UI) ため、初回設定フォームへ切り替える。
            'hasPassword' => $user->hasPassword(),
        ]);
    }
}
### app/Enums/Billing/SubscriptionState.php
<?php

declare(strict_types=1);

namespace App\Enums\Billing;

use App\Models\Billing\Subscription;

/**
 * Subscription の派生状態。
 *
 * `Active` / `UpgradeRecovery` は流入制御を通過させる。
 * `Inactive` は `canceled` / `unpaid` / `incomplete` / `incomplete_expired` を統合した拒否状態。
 * `incomplete` / `unpaid` を `Active` に含めない理由: いずれも支払いが完了していない
 * (= 顧客カードが未承認 or 失敗) 状態のため、流入制御の目的 (= LLM コスト負担確認) に反する。
 *
 *  - `PastDue` = 有料化後 (PM 登録済) の請求失敗・dunning 中。**回復余地あり**で利用は継続させる
 *    (grantsAccess=true)。PM **無し** past_due (= trial 後カード無し dunning) は entitlement gate
 *    (`SubscriptionService::deriveEntitlement`) で別途遮断する。
 *  - `Paused` = trial 終了後カード未登録で Stripe が paused にした read-only 状態 (grantsAccess=false)。
 *
 * **重要**: 利用可否の最終判定を state 単体で行ってはならない。`grantsAccess` は state のみの粗い
 * 判定であり、PM 有無 / trial_ends_at / Stripe status snapshot を加味した最終判定は
 * `SubscriptionService::deriveEntitlement` が唯一の経路。
 *
 * 移植元の `ScheduledForUpgrade` は入力列 (`subscriptions.pending_plan_code`) が AI-CUE に無いため
 * 非移植。`upgrade_recovery_required` 列も無いため、`UpgradeRecovery` は schedule 部分完了
 * (`stripe_schedule_id` + `schedule_setup_status=Created`) の分岐のみを持つ。
 */
enum SubscriptionState: string
{
    case Active = 'active';
    case UpgradeRecovery = 'upgrade_recovery';
    case PastDue = 'past_due';
    case Paused = 'paused';
    case Inactive = 'inactive';

    /**
     * Subscription model から派生状態を導出。
     *
     * 評価順は重要 (stripe_status を最優先に保つ):
     *   1. stripe_status を最初に評価 → terminal/拒否系は即返却 (schedule_id に関わらず)
     *   2. paused / past_due は専用 state へ
     *   3. schedule_setup_status === Created (部分完了) は UpgradeRecovery 扱い
     */
    public static function fromSubscription(Subscription $sub): self
    {
        // paused / past_due は固有 state に分離 (stripe_status 最優先・schedule 状態に依らない)。
        if ($sub->stripe_status === 'paused') {
            return self::Paused;
        }
        if ($sub->stripe_status === 'past_due') {
            return self::PastDue;
        }

        // trialing は試用期間として通す。それ以外の非 active 系 (canceled/unpaid/incomplete*) は Inactive。
        $activeStatuses = ['active', 'trialing'];
        if (! in_array($sub->stripe_status, $activeStatuses, true)) {
            return self::Inactive;
        }

        // 部分完了 schedule は recovery 扱い (Stripe phases 未設定 = phase transition 起きない)。
        // enum cast 経由なので instance 比較。
        if ($sub->stripe_schedule_id !== null
            && $sub->schedule_setup_status === ScheduleSetupStatus::Created) {
            return self::UpgradeRecovery;
        }

        return self::Active;
    }

    /**
     * state 単体の粗いアクセス判定。**最終判定には使わない**
     * (`SubscriptionService::deriveEntitlement` 経由が唯一の経路)。
     *
     * - `PastDue` = true: 請求失敗中でも利用継続 (PM 無し past_due の遮断は deriveEntitlement)。
     * - `Paused` = false: trial 後カード無し read-only。
     */
    public function grantsAccess(): bool
    {
        return match ($this) {
            self::Active, self::UpgradeRecovery, self::PastDue => true,
            self::Paused, self::Inactive => false,
        };
    }
}
### tests/Pest.php (helper 抜粋)

/**
 * Owner 付きの組織を provisioning 経由で生成する (Default Team 込み)。
 * owner の current_organization_id はこの組織になる。
 *
 * 既定では grandfathering backfill 相当 (`free_plan_code='personal'` / declarer NULL) を付与し、
 * 課金ゲート (require-active-subscription) を `ActiveFreePlan` で通る既存組織を再現する。
 * PersonalPlanService::activate() は**呼ばない** (signup grant が発火して残高期待が壊れ、
 * declarer の partial unique index にも触れるため)。
 *
 * `$grandfatherFreePlan: false` は真の未契約組織 (free_plan_code NULL = 業務 route が
 * onboarding へ遮断される) を作る。ゲート / onboarding のテストで使う。
 * 有償プラン契約状態を検証するテストは contractPaidPlan() を併用する。
 *
 * @return array{Organization, User} [organization, owner]
 */
function createOrganizationWithOwner(string $name = 'テスト組織', bool $grandfatherFreePlan = true): array
{
    $owner = User::factory()->create();
    $organization = app(OrganizationProvisioningService::class)->provision($owner, $name);

    if ($grandfatherFreePlan) {
        $organization->forceFill([
            'free_plan_code' => PersonalPlanService::FREE_PLAN_CODE,
            'free_plan_activated_at' => CarbonImmutable::now(),
        ])->save();
    }

    return [$organization, $owner];
}

/**
 * recent-auth (step-up) を確実に満たす fresh session 値。
 * 窓は config('auth.recent_auth_timeout')(既定 900s)。注入時点の elapsed≈0 で窓に対し十分 fresh。
 * recent-auth を要する route を「step-up 済み相当」で叩くテストは withSession() でこれを注入する。
 *
 * @return array{recent_auth_at: int}
 */
function freshRecentAuthSession(): array
{
    return ['recent_auth_at' => now()->timestamp];
}

/**
 * 組織を有償プラン契約状態にする (plan_code + Cashier subscription 行)。
 * plan_code は $fillable 外の状態キー (webhook 同期のみ) のため forceFill で明示代入。
 * BillingAccess は plan_code 非 null の組織にのみ active/trialing subscription を要求する。
 *
 * plan_code は PlanSeeder が投入する有償プラン code ('standard') を使う
 * (プラン名分岐ではなく seeded fixture の参照。アプリコードには入らない)。
 */
function contractPaidPlan(Organization $organization, string $status = 'active'): Subscription
{
    $organization->forceFill(['plan_code' => 'standard'])->save();

    return createFakeSubscription($organization, status: $status);
}

/**
 * テスト用の Cashier subscription 行を直接作成する (Stripe には到達しない)。
 * BillingAccess (課金ゲート) は plan_code 非 null の組織に対して stripe_status が
 * active / trialing のとき許可する (plan_code null = free tier は行の有無に依らず許可)。
 */
function createFakeSubscription(
    Organization $organization,
    string $status = 'active',
    string $type = 'default',
): Subscription {
    /** @var Subscription $subscription */
    $subscription = $organization->subscriptions()->create([
        'type' => $type,
        'stripe_id' => 'sub_test_'.Str::random(24),
        'stripe_status' => $status,
        'quantity' => 1,
    ]);

    return $subscription;
}

/**
 * 組織にメンバーを追加する (attach + laratrust_team_id 明示のロール付与)。
 */
function attachOrganizationMember(
    Organization $organization,
    OrganizationRole $role = OrganizationRole::Member,
): User {
    $user = User::factory()->create();
    $organization->users()->attach($user);
    $user->addRole($role->value, $organization->laratrust_team_id);

    return $user;
}

### tests/Feature/Auth/AccountDeletionTest.php
<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Models\SecurityAuditEvent;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\Organization\OrganizationMembershipService;

test('再認証 (step-up) なしではアカウント削除できない', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->delete('/settings/account');

    // recent-auth が確認画面へ redirect する
    $response->assertRedirect();
    expect(User::query()->whereKey($user->id)->exists())->toBeTrue();
});

test('step-up 済みならアカウントを削除でき、関連データが掃除される', function (): void {
    $user = User::factory()->create();
    $social = new SocialAccount(['provider' => 'google', 'provider_user_id' => 'g-123']);
    $social->user()->associate($user);
    $social->save();

    $response = $this->actingAs($user)
        ->withSession(['recent_auth_at' => time()])
        ->delete('/settings/account');

    $response->assertRedirect('/');
    // 破壊的操作の flash 規約: 着地先 (未認証面 = GuestLayout) で toast として表示される
    $response->assertSessionHas('success', 'アカウントを削除しました');
    $this->assertGuest();
    expect(User::query()->whereKey($user->id)->exists())->toBeFalse();
    expect(SocialAccount::query()->whereKey($social->id)->exists())->toBeFalse();

    // 削除イベントは user_id が null 化されて残る (nullOnDelete)
    expect(
        SecurityAuditEvent::query()->where('event_type', 'account_deleted')->exists(),
    )->toBeTrue();
});

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
    [$organization, $owner] = createOrganizationWithOwner(); // owner 1 人・他メンバー無し

    $response = $this->actingAs($owner)
        ->withSession(['recent_auth_at' => time()])
        ->delete('/settings/account');

    $response->assertRedirect('/');
    expect(User::query()->whereKey($owner->id)->exists())->toBeFalse();
});

test('複数オーナーがいれば削除できる', function (): void {
    [$organization, $owner] = createOrganizationWithOwner();
    $second = attachOrganizationMember($organization, OrganizationRole::Owner);

    $response = $this->actingAs($owner)
        ->withSession(['recent_auth_at' => time()])
        ->delete('/settings/account');

    $response->assertRedirect('/');
    expect(User::query()->whereKey($owner->id)->exists())->toBeFalse();
    expect($second->fresh()->organizationRole($organization))->toBe(OrganizationRole::Owner);
});

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
### resources/js/pages/Settings/Index.svelte (DangerZone 部分と errors 正規化)
    );

    // ブロック時にサーバーが返す errors.account を表示文字列へ正規化 (string | string[] 両対応)
    const accountError = $derived.by((): string | null => {
        const err = props.errors?.account;
        if (err === undefined) return null;
        return Array.isArray(err) ? (err[0] ?? null) : err;
    });

    const initialUser = props.auth?.user ?? null;

    /**
     * Fortify の PUT /user/profile-information は errorBag (updateProfileInformation)

            <DangerZone
                title="アカウント削除"
                description="アカウントを削除すると、すべてのデータが完全に失われます。この操作は取り消せません。"
            >
                {#if soleOwnedOrganizations.length > 0}
                    <Alert type="warning" title="オーナー移譲が必要です" class="mb-3">
                        以下の組織であなたが唯一のオーナーです。アカウントを削除する前に、各組織で
                        オーナーを別のメンバーへ移譲してください（削除時にサーバーが再判定します）。
                        <ul class="mt-2 list-disc pl-5">
                            {#each soleOwnedOrganizations as org (org.slug)}
                                <li>
                                    <TextLink href={`/organizations/${org.slug}/settings`}>
                                        {org.name} の設定へ
                                    </TextLink>
                                </li>
                            {/each}
                        </ul>
                    </Alert>
                {/if}
                {#if accountError}
                    <Alert type="danger" class="mb-3">{accountError}</Alert>
                {/if}
                <Button
                    variant="danger-outline"
                    onclick={() => {
                        deleteDialogOpen = true;
                    }}
                    testId="delete-account-button"
                >
                    アカウントを削除
                </Button>
            </DangerZone>
        </div>

        <ConfirmDialog
