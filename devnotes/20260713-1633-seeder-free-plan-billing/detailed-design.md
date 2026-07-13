# 詳細設計: seeder-free-plan-billing

## 使命・制約（絶対遵守）

### アプリの使命（North Star）
**AI-CUE** は、現場に既にある作業手順書(SOP)を起点に、AI が撮るべきカットを設計した動画シナリオを
生成し、そのシナリオをスマホ(PWA)でナビゲーション撮影することで、専門知識ゼロの現場作業者でも
標準化されたマニュアル動画を作れるようにする。「思考ゼロ・編集ゼロ」で台本作成・撮影判断・編集の
3 ハードルを AI とナビ撮影が肩代わりする。競合(tebiki)と異なり標準作業を起点に AI が教材設計し
撮影を指示する。熟練者の暗黙知を動画マニュアルへ変換する装置(SECI)。
v1 スコープ: 字幕のみ / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

### 禁止事項
1. テストなしの実装完了報告
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作をエージェント判断で実行
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う)
5. LLM 呼び出しの Prism 直呼び
6. prompt 文字列のコード直書き
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI

### コーディングルール
- **PHPStan level 10** 必須（`composer phpstan`）
- **Pest** テストフレームワーク（`composer test`）
- **RefreshDatabase** + `--parallel` 並列実行（`tests/Pest.php` でグローバル適用、個別 `DatabaseTransactions` 禁止）
- **テストデータは必ず Factory で生成**（`Model::create()` 手組み禁止）
- **DTO + JsonResource** パターン
- **アーリーリターン** 推奨
- **コードフォーマット**: `composer fix`（Pint）/ `pnpm lint:fix`
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript + Cashier
- **ドメイン規約**: コードにプラン名 (code) で分岐を書かない。能力は「値」で表現する。
  `organizations.plan_code` は Stripe Price を持つ有償プランの契約時のみ set される (null = 未契約 = free tier)。

## 概念設計リファレンス
- [conceptual-design.md](./conceptual-design.md)
- 概念設計レビュー: [conceptual-review-round-1.md](./conceptual-review-round-1.md) (APPROVED, Round 1)

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | ManualTestSeeder の plan_code 不変条件是正 (Free=null / 有償=code+subscription) | `database/seeders/ManualTestSeeder.php` | Critical |
| 2 | ManualTestSeederTest の plan_code / subscription 期待値更新 | `tests/Feature/Database/ManualTestSeederTest.php` | Critical |
| 3 | seeded Free 組織の課金ゲート素通り回帰テスト追加 | `tests/Feature/Billing/SeededFreePlanBillingAccessTest.php` (新規) | Critical |
| 4 | 有償プランの current base Price 不変条件を独立テストで固定 | `tests/Feature/Billing/PlanSeederPriceInvariantTest.php` (新規) | High |

**BillingAccess.php / RequireActiveSubscription.php は変更しない** (現行仕様が正しく、根本原因は
seeder の不変条件違反のため)。概念設計の判断を維持する。

---

## 施策1: ManualTestSeeder の plan_code 不変条件是正

### 変更箇所
- ファイル: `database/seeders/ManualTestSeeder.php`
  - `createOrganization()` (L124-131) の書き換え
  - private helper `attachFakeActiveSubscription()` の新設
  - import 追加: `App\Enums\Billing\PlanPriceKind`, `Laravel\Cashier\Subscription`, `Illuminate\Support\Str` (既存)

### 波及変更
- TypeScript 型定義: なし (seeder のみ。Inertia Props に影響しない)
- API Resource/DTO: なし
- テストファイル: 施策2 (ManualTestSeederTest 更新)・施策3 (新規回帰テスト) で対応

### 設計方針
1. `provision()` で組織を生成した後、**plan が current な base Price を持つ有償プランのときのみ**
   `plan_code` を forceFill し、あわせて fake active Cashier subscription を投入する。
2. Price を持たない (= Free) プランは `plan_code` を **null のまま** (forceFill しない)。
   → BillingAccess の free-tier 分岐 (`plan_code === null` 許可) で正しく素通しされる。
3. 有償判定は `$plan->currentPrice(PlanPriceKind::Base) !== null` という **Plan の値**で行う。
   **`$plan->code === 'free'` 等のプラン名文字列比較は一切しない** (AGENTS.md ドメイン規約)。
   これは StripeWebhookProcessor が plan_code を set する条件 (Stripe Price → Plan 解決成立) と
   同じ意味論であり、本番 webhook 経路と seeder 投入経路の「有償契約」概念を一致させる。
4. fake subscription は Cashier の `subscriptions` 行を直接作成する (Stripe API 非到達)。
   テスト helper `createFakeSubscription` (tests/Pest.php) と同一の生成経路・最小カラムに寄せる
   (概念レビュー Round1 Warning への対応)。

### 現行コード
```php
/**
 * 組織生成は provisioning 経由 (Default Team パターンの不変条件を担保する唯一の窓口)。
 * plan_code は状態キー ($fillable 外) のため forceFill で明示代入する。
 */
private function createOrganization(User $owner, Plan $plan): Organization
{
    $organization = app(OrganizationProvisioningService::class)
        ->provision($owner, "{$plan->name}プラン組織");
    $organization->forceFill(['plan_code' => $plan->code])->save();

    return $organization;
}
```

### 変更後コード
```php
/**
 * 組織生成は provisioning 経由 (Default Team パターンの不変条件を担保する唯一の窓口)。
 *
 * plan_code の不変条件を尊重する: 「plan_code は Stripe Price を持つ有償プランの契約状態でのみ
 * set される」(Model/StripeWebhookProcessor/BillingAccess の docblock が定める)。
 * よって有償プラン (current base Price あり) のときのみ plan_code を forceFill し、あわせて
 * active な Cashier subscription 行を投入する (plan_code 非 null ⇔ 契約行あり を seed でも満たす)。
 * Free (Price 無し) は plan_code を null のまま = 未契約 = 支払い不要 tier として BillingAccess が許可する。
 *
 * 有償/Free の判定は Plan の「値」(current base Price の有無) からのみ導出し、プラン名 (code) の
 * 文字列比較はしない (AGENTS.md ドメイン規約)。
 */
private function createOrganization(User $owner, Plan $plan): Organization
{
    $organization = app(OrganizationProvisioningService::class)
        ->provision($owner, "{$plan->name}プラン組織");

    // current な base Price を持つ = Checkout 可能な有償プラン。plan_code は状態キー ($fillable 外)
    if ($plan->currentPrice(PlanPriceKind::Base) !== null) {
        $organization->forceFill(['plan_code' => $plan->code])->save();
        $this->attachFakeActiveSubscription($organization);
    }

    return $organization;
}

/**
 * 手動テスト用に active な Cashier subscription 行を直接投入する (Stripe API 非到達)。
 * BillingAccess は plan_code 非 null の組織に active/trialing subscription を要求するため、
 * plan_code を載せた有償組織は本行が無いと課金ゲートで締め出される。
 * subscription('default') が active を返すための最小カラムのみを設定する。
 *
 * メソッド単体で冪等: 既に default subscription があれば作らない (run() の冪等 guard に依存せず、
 * 部分実行・手動呼び出し・将来の guard 変更でも重複行を生まない = Codex Round1 Critical 対応)。
 */
private function attachFakeActiveSubscription(Organization $organization): void
{
    if ($organization->subscription('default') !== null) {
        return; // 冪等: 既存の default subscription を尊重する
    }

    $organization->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_seed_'.Str::random(24),
        'stripe_status' => 'active',
        'quantity' => 1,
    ]);
}
```

### PHPStan 適合チェック
- [x] 戻り値の型が明示されている (`createOrganization(): Organization`, `attachFakeActiveSubscription(): void`)
- [x] null 安全: `currentPrice()` の戻り値 `?PlanPrice` を `!== null` で判定 (Assert 不要な明示 null チェック)
- [x] DTO を返している: seeder は DTO 対象外 (配列返却なし)
- [x] Generics: `subscriptions()` は Cashier の `HasMany<Subscription>`。`create([...])` の配列は
      Cashier 側 stub で型付けされ、level 10 で問題なし (テスト helper `createFakeSubscription` と同一)
- 注意: `PlanPriceKind` import 追加。`Str` は既存 import。`Subscription` の型注釈は不要
  (`subscriptions()->create()` の戻り値を受けないため import 追加不要)。

### テスト計画
- 施策2・3 で担保 (下記)。seeder 単体は施策2、entitlement 挙動は施策3。

### リスク
- **冪等性**: subscription 投入は `createOrganization()` 内 = 既存の冪等 guard (Owner ユーザー存在チェック,
  L46-51) の内側で 1 回のみ実行される。再実行時は早期 return するため subscription も重複しない。
- **standard の base Price 前提 (seed 不変条件)**: 「有償プランは必ず current base Price を持つ」を
  seed データ不変条件として明文化する。PlanSeeder が standard に base Price を bootstrap 投入する
  (`seedPlanPrices`) ことでこれを保証し、**施策4 が判定式 (currentPrice) に依存しない独立テストで**
  standard の base Price 存在を固定して drift を検知する (施策2 は分岐選択と期待値がともに同じ
  currentPrice に依存するため単独では drift を検知できない = Codex Round2 Warning 対応)。seeder に
  「base Price 無しなら hard fail」を足すのは今回の Critical 修正の責務を超え over-engineering のため見送る。
- **他 seeder への波及なし**: `contractPaidPlan` helper (tests) は本 seeder を使わず独立。影響は
  ManualTestSeeder を seed する箇所 (手動テスト・bug-hunt 環境・ManualTestSeederTest) に限定。

---

## 施策2: ManualTestSeederTest の期待値更新

### 変更箇所
- ファイル: `tests/Feature/Database/ManualTestSeederTest.php`
  - 「ロール × プラン総当たり」テスト (L18-37) の `plan_code` アサーション更新

### 波及変更
- TypeScript 型定義: なし / API Resource/DTO: なし

### 現行コード (該当アサーション)
```php
foreach (Plan::query()->orderBy('sort_order')->get() as $plan) {
    $organization = Organization::query()->where('name', "{$plan->name}プラン組織")->first();
    expect($organization)->not->toBeNull();
    expect($organization?->plan_code)->toBe($plan->code); // ← バグ挙動を固定していた
    ...
}
```

### 変更後コード
```php
foreach (Plan::query()->orderBy('sort_order')->get() as $plan) {
    $organization = Organization::query()->where('name', "{$plan->name}プラン組織")->firstOrFail();

    // 有償判定はループ先頭で 1 回だけ算出 (Codex Round1 Warning: currentPrice の多重クエリを避け分岐を一元化)
    $isPaid = $plan->currentPrice(PlanPriceKind::Base) !== null;

    // plan_code の不変条件: Stripe Price を持つ有償プランのみ plan_code + active subscription を持つ。
    // Free (Price 無し) は未契約 = plan_code null (BillingAccess の free-tier 許可の前提)。
    if ($isPaid) {
        expect($organization->plan_code)->toBe($plan->code);
        expect($organization->subscription('default')?->stripe_status)->toBe('active');
    } else {
        expect($organization->plan_code)->toBeNull();
        expect($organization->subscription('default'))->toBeNull(); // free には契約行が無い (根本原因への回帰耐性)
    }
    // どちらの tier も業務 route を利用してよい (free tier / 有償 active はともに許可)
    expect(app(BillingAccess::class)->hasActiveAccess($organization))->toBeTrue();

    foreach (OrganizationRole::cases() as $role) {
        // (既存のロール/current_organization/パスワード検証はそのまま)
    }
}
```
- import 追加: `App\Enums\Billing\PlanPriceKind`, `App\Services\Billing\BillingAccess`。
- 既存の他テスト (複数組織所属・冪等) は変更不要 (plan_code に依存しないため)。

### PHPStan 適合チェック
- [x] `$organization` は `firstOrFail()` で取得し `Organization` (non-null) に narrow 済み
      (`first()` の `?Organization` を渡すと level 10 が null 警告を出すため。fixture 前提が崩れれば
      `firstOrFail()` が例外を投げてテストが fail するのが正しい挙動)
- [x] `hasActiveAccess(Organization)` に non-null な `$organization` を渡す (上記 narrow で保証)
- [x] `subscription('default')` は `?Subscription`。`?->stripe_status` で null 安全にアクセス

### テスト計画
- [x] バグ挙動を固定していた旧アサーションを、修正後の正しい不変条件へ更新 (既存テスト削除ではなく是正)
- [x] Free / 有償の両側不変条件を明示検証 (概念レビュー Round1 Warning 対応)
- [x] 個別 `DatabaseTransactions` を使わない (グローバル RefreshDatabase)

### リスク
- 既存テストの「上書き」に見えるが、旧アサーションはバグ挙動 (Free に plan_code='free') を固定して
  いたもの。**バグ修正に伴う期待値是正**であり AGENTS.md「既存テストの削除・上書き」禁止には抵触しない
  (テストの意図=「seeder が正しい entitlement 状態を作る」は維持・強化される)。

---

## 施策3: seeded Free 組織の課金ゲート素通り回帰テスト (新規)

### 変更箇所
- ファイル: `tests/Feature/Billing/SeededFreePlanBillingAccessTest.php` (新規)

### 波及変更
- TypeScript 型定義: なし / API Resource/DTO: なし / Factory: 新規モデル無しのため不要

### 設計方針
F-C3 の再現・回帰を **エンドツーエンド (HTTP + middleware 経由)** で固定する。ManualTestSeeder を
seed し、Free 組織の owner / admin / member が `/projects` に到達でき (200)、`/billing` へ
redirect されないことを検証する。BillingAccess 単体は既存 RequireActiveSubscriptionMiddlewareTest が
カバー済みのため、本テストは「seeder が生成した実データ」で middleware を通す統合視点に絞る (二重化回避)。

### 新規テストコード (骨子)
```php
<?php

declare(strict_types=1);

use App\Enums\Billing\PlanPriceKind;
use App\Enums\OrganizationRole;
use App\Models\Billing\Plan;
use App\Models\Organization;
use App\Models\User;
use Database\Seeders\ManualTestSeeder;
use Illuminate\Support\Str;

/*
 * F-C3 回帰: ManualTestSeeder が生成する Free (Stripe Price 無し) プラン組織の全ロールが、
 * 課金ゲート (require-active-subscription) を素通りして中核業務 route に到達できることを固定する。
 * 根本原因は seeder が Free にも plan_code='free' を載せ、BillingAccess が active subscription を
 * 要求して締め出していたこと (devnotes/20260713-1633-seeder-free-plan-billing)。
 */

/** current base Price を持たない Free プランを 1 つ取得する */
function seededFreePlan(): Plan
{
    return Plan::query()->get()
        ->first(fn (Plan $p): bool => $p->currentPrice(PlanPriceKind::Base) === null)
        ?? throw new RuntimeException('Free プラン (Price 無し) が seed されていない');
}

test('seeded Free 組織の全ロールが /projects に到達できる (F-C3 回帰)', function (OrganizationRole $role): void {
    $this->seed(ManualTestSeeder::class);

    $plan = seededFreePlan();
    $organization = Organization::query()->where('name', "{$plan->name}プラン組織")->firstOrFail();
    expect($organization->plan_code)->toBeNull(); // seeder が不変条件を守っている

    $email = Str::afterLast($role->value, '_')."-{$plan->code}@example.com";
    $user = User::whereBlind('email', 'email_index', $email)->firstOrFail();

    // assertOk() で 302→billing の redirect を検出。加えて Inertia コンポーネント名で
    // 「200 だが別画面」ケースも塞ぐ (Codex Round1 Warning。実際の Projects ページ component 名は
    // ProjectController@index の Inertia::render 第1引数を実装時に確認して合わせる)。
    $this->actingAs($user)->get('/projects')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Projects/Index'));
})->with([
    'owner' => OrganizationRole::Owner,
    'admin' => OrganizationRole::Admin,
    'member' => OrganizationRole::Member,
]);

test('seeded 有償組織は plan_code と active subscription を持ち課金ゲートを通過する', function (): void {
    $this->seed(ManualTestSeeder::class);

    $paid = Plan::query()->get()
        ->first(fn (Plan $p): bool => $p->currentPrice(PlanPriceKind::Base) !== null);
    expect($paid)->not->toBeNull();

    $organization = Organization::query()->where('name', "{$paid?->name}プラン組織")->firstOrFail();
    expect($organization->plan_code)->toBe($paid?->code);
    expect($organization->subscription('default')?->stripe_status)->toBe('active');

    $owner = User::whereBlind('email', 'email_index', "owner-{$paid?->code}@example.com")->firstOrFail();
    $this->actingAs($owner)->get('/projects')->assertOk();
});
```
- **注意**: `OrganizationRole` の enum case 名は実装で確認する (owner/admin/member の value プレフィックス)。
  `->with()` の dataset は enum case を直接渡す (Pest は enum を引数に取れる)。
- `assertDontSee(route('billing.index'))` は 200 応答の本文に billing への強制導線が無いことの補助検証。
  主検証は `assertOk()` (redirect 302 なら fail する)。

### PHPStan 適合チェック
- [x] `firstOrFail()` で `?Organization` / `?User` を non-null へ narrow (level 10 の null 警告回避)
- [x] クロージャに `Plan $p` 型注釈、戻り値 `: bool` を明示
- [x] `throw` 式で null 合体 (`?? throw`) して Plan を non-null に確定
- [x] `assertOk` / `assertDontSee` は TestResponse のメソッド (型付け済み)

### テスト計画
- [x] バグ再現の観点: 修正前コードでは Free owner が /projects で 302 → /billing となり `assertOk()` が fail
      (= 再現テストとして機能)。修正後 green。
- [x] 有償側の不変条件 (plan_code + active subscription) も固定
- [x] 個別 `DatabaseTransactions` 不使用 (グローバル RefreshDatabase)。`$seed` で PlanSeeder は自動実行され
      るが、本テストは `ManualTestSeeder` を明示 seed する

### リスク
- ルート `/projects` に他の gate (認証・メール認証) が絡む場合、owner は verified・current_organization
  設定済みのため 200 を返す想定。unverified ユーザーは対象外 (seeder の unverified は Free 組織 Member だが
  本テストは verified な role ユーザーを対象にする)。

---

## 施策4: 有償プランの current base Price 不変条件を独立テストで固定 (新規)

### 変更箇所
- ファイル: `tests/Feature/Billing/PlanSeederPriceInvariantTest.php` (新規)

### 背景 (Codex Round2 Warning 対応)
施策2 は「有償か否か」の**分岐選択と期待値の両方**を同じ `currentPrice(Base)` 導出に依存する。
そのため standard の base Price が欠落すると施策2 は standard を free 扱いとして解釈し、
`plan_code === null` を期待して **silently pass** してしまう (drift を検知できない)。
これを塞ぐため、**同じ判定式に依存しない独立テスト**で「有償プラン standard は current base Price を
必ず持つ」という seed fixture 仕様を固定する。

### 設計方針
- プラン名 (`'standard'`) を直接参照するが、これは**本番コードの能力分岐ではなく seed fixture 仕様の検証**
  であり、AGENTS.md「コードにプラン名で分岐を書かない」(=本番ロジックの規約) には抵触しない
  (Codex が明示的に容認)。
- PlanSeeder は TestCase の `$seed=true` で自動実行されるため、明示 seed 不要。

### 新規テストコード (骨子)
```php
<?php

declare(strict_types=1);

use App\Enums\Billing\PlanPriceKind;
use App\Models\Billing\Plan;

/*
 * seed fixture 不変条件: 有償プラン (Checkout 対象) は current な base Price を必ず持つ。
 * ManualTestSeeder / BillingAccess の「plan_code 非 null ⇔ 有償契約」判定は「有償プランは
 * currentPrice(Base) を持つ」という前提に立つ。この前提が崩れると seeded 有償組織が free 扱いに
 * silently 退行するため、判定式 (currentPrice) に依存しない独立検証でここを固定する。
 * (本番コードのプラン名分岐ではなく fixture 仕様の検証。docs 07 §4 の規約には抵触しない)
 */

test('有償プラン standard は current base Price を持つ (seed 不変条件)', function (): void {
    $standard = Plan::query()->where('code', 'standard')->firstOrFail();

    expect($standard->currentPrice(PlanPriceKind::Base))->not->toBeNull();
});

test('free プランは Stripe Price を持たない (Checkout 対象外の未契約既定)', function (): void {
    $free = Plan::query()->where('code', 'free')->firstOrFail();

    expect($free->currentPrice(PlanPriceKind::Base))->toBeNull();
    expect($free->prices()->count())->toBe(0);
});
```

### 波及変更
- TypeScript 型定義: なし / API Resource/DTO: なし / Factory: 不要

### PHPStan 適合チェック
- [x] `firstOrFail()` で `Plan` を non-null に narrow
- [x] `currentPrice()` は `?PlanPrice` を返し `->not->toBeNull()` / `->toBeNull()` で検証
- [x] 型注釈済みクロージャ・戻り値 void

### テスト計画
- [x] standard の base Price 欠落を**独立して**検出 (施策2 の silently pass を塞ぐ)
- [x] free が Price を持たない前提も固定 (BillingAccess free-tier 判定の根拠)
- [x] 個別 `DatabaseTransactions` 不使用 (グローバル RefreshDatabase、$seed=true で PlanSeeder 自動実行)

### リスク
- 低。読み取り専用の fixture 検証。standard の Price 定義を変えた場合のみ意図的に更新する。

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | standalone |
| 判断根拠 | 3 施策すべて billing seeder/test の 1 論理単位。変更ファイルが seeder 1 + テスト 2 に閉じ、他 TODO と重ならない。段階分割の利得が無く、まとめて 1 worktree で完結させるのが最短。 |
| 競合リスク | 低。`ManualTestSeeder.php` / billing テストを触る他 Open TODO が無ければ競合しない。BillingAccess/Middleware を変更しないため課金ロジック系 TODO とも独立。 |

## 使命・禁止事項 最終チェック
- 使命寄与: Free 組織の中核導線 (/projects・撮影 PWA) 全損を解消し、手動テスト/bug-hunt での中核機能検証を回復。
- 禁止事項: PHPStan ignore なし / 全施策にテストあり / 既存テストは是正 (削除でない) / `response()->json()` 不使用 /
  プラン名分岐なし。抵触なし。
- コーディングルール: PHPStan level 10 適合方針を各施策に明記、Pest + RefreshDatabase、Factory 生成、
  forceFill で状態キー明示代入 — 反映済み。
