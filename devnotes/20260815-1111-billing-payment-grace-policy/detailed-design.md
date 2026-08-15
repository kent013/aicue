# 詳細設計: billing-payment-grace-policy

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

### 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する)
9. Artifact の使用(成果物はリポジトリ内のファイルとして出力する)

### コーディングルール

- **PHPStan level 10** 必須（`composer phpstan`）
- **Pest**テストフレームワーク（`composer test`）
- **RefreshDatabase** + `--parallel` 並列実行（`tests/Pest.php` でグローバル適用、個別 `DatabaseTransactions` 使用禁止）
- **テストデータは必ず Factory で生成**（`Model::create()` 手組み禁止。既存の `createFakeSubscription` / `cohortSubscription` ヘルパーを使う）
- **DTO + JsonResource** パターン
- **アーリーリターン** 推奨
- **コードフォーマット**: `composer fix`（Pint）/ `pnpm lint:fix`
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- 月/年/四半期の加減算は `*NoOverflow` を明示（本設計は**日**加算のみなので該当しないが、
  `addDays()` を月換算に置き換えない）

## 概念設計リファレンス

`devnotes/20260815-1111-billing-payment-grace-policy/conceptual-design.md`（Codex 合議 Round 5 で APPROVED）

## 用語

| 語 | 意味 |
|---|---|
| 猶予 (payment grace) | 支払い失敗 (`stripe_status='past_due'`) を**観測してから**、利用を止めるまでの日数 |
| 猶予の起点 | `subscriptions.past_due_since`。**観測時刻**であり、Stripe 側で実際に失敗した時刻ではない |
| 支払い未解決 | 契約が終了しておらず支払いが未解決の状態 = `PastDue` / `Unpaid`。無料枠への読み替えと新規契約を禁じる唯一の条件 |
| 突き合わせ | `billing:reconcile-subscription-status`。Stripe の契約状態を読み、食い違うときだけ既存の単一 writer へ流す |

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | 猶予の起点列を追加する | `database/migrations/2026_08_15_000200_*`, `2026_08_15_000210_*`, `app/Models/Billing/Subscription.php` | High |
| 2 | 猶予日数の設定を置く | `config/billing.php` | High |
| 3 | 猶予期限の単一の正本を作る | `app/Support/Billing/PaymentGracePolicy.php` (新規) | High |
| 4 | 支払い未解決の状態を明示する | `app/Enums/Billing/SubscriptionState.php`, `app/Services/Billing/AccountDeletionBillingGuard.php` (docblock) | High |
| 5 | 猶予の起点を打刻する (単一 writer) | `app/Services/Billing/SubscriptionService.php` | High |
| 6 | 猶予切れで entitlement を否定する | `app/Services/Billing/SubscriptionService.php`, `app/Enums/Billing/EntitlementDeniedReason.php` | High |
| 7 | 無料枠へのすり抜けを塞ぐ | `app/Services/Billing/BillingAccess.php` | High |
| 8 | 支払い未解決の契約がある間は新規契約を拒否する | `app/Services/Billing/SubscriptionService.php`, `app/Http/Controllers/Onboarding/OnboardingController.php` | High |
| 9 | Stripe 契約状態の読み取り口を作る | `app/DataTransferObjects/Billing/RemoteSubscriptionState.php` (新規), `app/Exceptions/Billing/SubscriptionLookupFailedException.php` (新規), `app/Services/Billing/Contracts/StripeGatewayInterface.php`, `app/Services/Billing/CashierStripeGateway.php`, `app/Services/Billing/Fakes/FakeStripeGateway.php`, `tests/Support/FakeStripeGateway.php` | High |
| 10 | 日次の突き合わせコマンドと配線 | `app/Console/Commands/Billing/ReconcileSubscriptionStatus.php` (新規), `app/Services/Billing/SubscriptionService.php`, `routes/console.php` | High |
| 11 | 書込単一化の Architecture テスト | `tests/Architecture/PastDueSinceWriteInvariantTest.php` (新規) | High |
| 12 | ドキュメント | `docs/architecture.md`, `docs/billing-gate-inversion-runbook.md` | Medium |

**実装順序**: 1 → 2 → 3 → 4 → 5 → 6 → 7 → 8 → 9 → 10 → 11 → 12。
6 (遮断) だけを先に main へ入れない (7・8 が無い状態では猶予切れが無料枠へすり抜け、
かつ二重契約を作れる)。**1 PR で完結させる**。

---

## 施策 1: 猶予の起点列を追加する

### 変更箇所

- 新規: `database/migrations/2026_08_15_000200_add_past_due_since_to_subscriptions.php`
- 新規: `database/migrations/2026_08_15_000210_backfill_past_due_since_on_subscriptions.php`

> **実装時の訂正 (T163)**: 当初案の連番 `000100` / `000110` は、既に main にある
> `2026_08_15_000100_add_recovery_reason_to_stripe_webhook_events_table.php` と先頭が重なる。
> migration の実行順はファイル名順なので実害は無いが、同日同連番は読み手を迷わせるため
> `000200` / `000210` へずらした (内容は変えていない)。
- 変更: `app/Models/Billing/Subscription.php` (docblock の `@property` と `casts()`)

### 波及変更

- TypeScript 型定義: なし (props に出さない)
- API Resource/DTO: なし
- テストファイル: `tests/Feature/Billing/SubscriptionSnapshotSyncTest.php` に打刻の検証を追加 (施策 5)

### 変更後コード

```php
// 2026_08_15_000200_add_past_due_since_to_subscriptions.php
/**
 * past_due_since: 支払い失敗 (stripe_status='past_due') を**観測した**時刻。
 *
 * `PaymentGracePolicy` が猶予期限を計算する起点で、`SubscriptionService::deriveEntitlement`
 * が猶予切れの遮断に使う。**Stripe 側で実際に失敗した時刻ではない** (webhook 欠落時は
 * 日次突き合わせが観測した時刻になる)。書込は SubscriptionService に閉じる
 * (PastDueSinceWriteInvariantTest)。既存行は分離した data migration が埋める。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->timestamp('past_due_since')->nullable()->after('has_payment_method');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->dropColumn('past_due_since');
        });
    }
};
```

```php
// 2026_08_15_000210_backfill_past_due_since_on_subscriptions.php
/**
 * 既存の past_due 行の猶予起点を **migration 実行時刻** で埋める。
 *
 * 実際に失敗した時刻は復元できない (Stripe の請求履歴からの推定は移行のために外部 API を
 * 叩くことになり、得られるのは数日の厳密さだけなので採らない)。よって「猶予はこのデプロイ時点
 * から数え直す」という意味を持たせる = 移行と同時に既存利用者を遮断しない (遡って遮断すると
 * 告知なしに突然止まる)。
 *
 * 冪等 (whereNull ガード)。down() は「どの行が migration 起因か」を識別できないため意図的に no-op。
 * **手動 SQL / tinker でこの列を書かない** (書込の単一化は runbook にも明記する)。
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('subscriptions')
            ->where('stripe_status', 'past_due')
            ->whereNull('past_due_since')
            ->update(['past_due_since' => CarbonImmutable::now()]);
    }

    public function down(): void
    {
        // backfill の巻き戻しは「どの行が migration 起因か」を識別できないため意図的に no-op。
    }
};
```

```php
// app/Models/Billing/Subscription.php
 * @property Carbon|null $past_due_since
...
    protected function casts(): array
    {
        return [
            'current_period_end' => 'datetime',
            'past_due_since' => 'datetime',
            'has_payment_method' => 'boolean',
            'schedule_setup_status' => ScheduleSetupStatus::class,
        ];
    }
```

### PHPStan適合チェック

- [x] `@property Carbon|null $past_due_since` を宣言 (cast と一致)
- [x] migration の `CarbonImmutable::now()` は `DateTimeInterface` として受理される
- [x] 戻り値の型が明示されている

### テスト計画

- [ ] 新規 `tests/Feature/Billing/PaymentGraceMigrationTest.php`:
      **backfill migration を単体で再実行**しても `past_due_since` を上書きしないこと (冪等)
      — `past_due` 行を 2 件 (起点あり / 起点なし) 用意し、`Artisan::call('migrate')` ではなく
      backfill と同じ条件付き UPDATE を再現するのではなく、**migration クラスを直接 `up()` して**
      検証する (Pest から `require` して無名クラスを起動する形。既存の
      `tests/Feature/Billing/` にある migration 検証と同じ作法が無ければ本テストが初出になる)
- [ ] 列と cast の存在は施策 5・6 のテストが実質的に踏む (単独の schema assert は書かない)

### リスク

- `after('has_payment_method')` は PostgreSQL では無視される (MySQL 専用ヒント)。既存
  migration も同じ書き方をしているので合わせる (害はない)。
- backfill migration では `use Carbon\CarbonImmutable;` / `use Illuminate\Support\Facades\DB;` の
  import 漏れに注意する (migration は無名クラスで namespace を持たないため、
  import が無いと global 解決で落ちる)。

---

## 施策 2: 猶予日数の設定を置く

### 変更箇所

- `config/billing.php` (末尾に追加)

### 変更後コード

```php
    /*
    | 支払い失敗 (Stripe status=past_due) を**観測してから**利用を止めるまでの猶予日数。
    | 起点は subscriptions.past_due_since で、判定の唯一の読み口は
    | App\Support\Billing\PaymentGracePolicy (ここ以外で config を読まない)。
    |
    | 0 は「観測した瞬間に止める」を意味する有効な設定値である (負値は設定不備として
    | PaymentGracePolicy が例外で落とす)。
    |
    | **チケット残高切れには猶予を設けない** (残高 0 は予約時点で即拒否)。前払いチケットで
    | 猶予を作ると「借金して使わせる」ことになるため。これは未実装ではなく決定である
    | (docs/architecture.md にも記載)。
    */
    'payment_grace_days' => (int) env('BILLING_PAYMENT_GRACE_DAYS', 14),
```

### テスト計画

- [ ] 施策 3 のテストで `config()->set('billing.payment_grace_days', N)` を通して読まれることを固定する
      (config キーの単独テストは書かない)

### リスク

- 既定 14 日は家系の他リポジトリ (テンプレート / spirux) と同値。値を変えるときは
  `docs/architecture.md` の記述と揃える。

---

## 施策 3: 猶予期限の単一の正本を作る

### 変更箇所

- 新規: `app/Support/Billing/PaymentGracePolicy.php`

### 波及変更

- `SubscriptionService` のコンストラクタ引数が 1 つ増える (container 解決のため呼び出し側の
  変更は不要。`app(SubscriptionService::class)` / DI 注入のみで、`new SubscriptionService(...)` の
  直接生成は `app/` にも `tests/` にも無いことを実装時に grep で確認する)

### 変更後コード

```php
<?php

declare(strict_types=1);

namespace App\Support\Billing;

use Carbon\CarbonImmutable;
use Webmozart\Assert\Assert;

/**
 * 支払い失敗の猶予 (何日まで使わせるか) を判定する **唯一の正本**。
 *
 * 猶予日数を読む場所・期限を計算する場所をここ 1 つに閉じる (AG-035 (5))。
 * 画面文言・通知・運用スクリプトが日数を再計算して食い違うことを防ぐ。
 *
 * **利用可否そのものは答えない**。可否の確定は SubscriptionService::deriveEntitlement の
 * 一本道であり、本クラスは「起点からの期限が切れたか」だけを答える。
 *
 * **チケット残高切れの猶予は扱わない** (残高 0 は予約時点で即拒否 = 猶予 0 が標準形)。
 */
final class PaymentGracePolicy
{
    /** 猶予日数 (config の唯一の読み口)。負値は設定不備として落とす。 */
    public function graceDays(): int
    {
        $days = config()->integer('billing.payment_grace_days');
        Assert::greaterThanEq($days, 0, '支払い猶予日数には 0 以上を設定してください');

        return $days;
    }

    /** 猶予の期限 (この時刻までは利用継続)。 */
    public function expiresAt(CarbonImmutable $pastDueSince): CarbonImmutable
    {
        return $pastDueSince->addDays($this->graceDays());
    }

    /**
     * 猶予が切れているか。
     *
     * **境界 (ちょうど期限の瞬間) は切れていない扱い**にする (利用者に有利な側へ倒す)。
     */
    public function hasExpired(CarbonImmutable $pastDueSince, CarbonImmutable $now): bool
    {
        return $now->greaterThan($this->expiresAt($pastDueSince));
    }
}
```

### PHPStan適合チェック

- [x] `config()->integer()` を使い `mixed` を持ち込まない (既存 `DashboardService` と同作法)
- [x] 戻り値の型が明示されている
- [x] `Assert` で負値を排除 (null 安全)

### テスト計画

- [ ] 新規 `tests/Feature/Billing/PaymentGracePolicyTest.php`:
  - [ ] `graceDays()` が config を読む (`config()->set` で 7 → 7)
  - [ ] 負値の config は例外 (`InvalidArgumentException`)
  - [ ] `hasExpired()`: 起点 +14 日 **ちょうど**は false、1 秒後は true、起点当日は false
  - [ ] `graceDays()=0` の設定では起点の 1 秒後に true (即時遮断できる)

### リスク

- `addDays()` は日の加算なので月末 overflow の論点は無い (`*NoOverflow` 規約の対象外)。
  日数を月換算に変える改造をするときは `addMonthsNoOverflow` が必要になる。

---

## 施策 4: 支払い未解決の状態を明示する

### 変更箇所

- `app/Enums/Billing/SubscriptionState.php` (case 追加 + 述語追加 + docblock)
- `app/Services/Billing/AccountDeletionBillingGuard.php` (docblock の状態名のみ更新)

### 波及変更

- TypeScript 型定義: なし (`SubscriptionState` は props に出ない。画面が持つのは
  `OnboardingBillingState` で、そちらは変更しない)
- テストファイル: `tests/Feature/Billing/SubscriptionEntitlementTest.php` の
  「非 active 系 status は Inactive」データセットから `unpaid` を外し、`Unpaid` を期待する
  ケースを 1 本足す

### 現行コード

```php
enum SubscriptionState: string
{
    case Active = 'active';
    case UpgradeRecovery = 'upgrade_recovery';
    case PastDue = 'past_due';
    case Paused = 'paused';
    case Inactive = 'inactive';

    public static function fromSubscription(Subscription $sub): self
    {
        if ($sub->stripe_status === 'paused') { return self::Paused; }
        if ($sub->stripe_status === 'past_due') { return self::PastDue; }
        $activeStatuses = ['active', 'trialing'];
        if (! in_array($sub->stripe_status, $activeStatuses, true)) { return self::Inactive; }
        ...
    }

    public function grantsAccess(): bool
    {
        return match ($this) {
            self::Active, self::UpgradeRecovery, self::PastDue => true,
            self::Paused, self::Inactive => false,
        };
    }
}
```

### 変更後コード

```php
    case Active = 'active';
    case UpgradeRecovery = 'upgrade_recovery';
    case PastDue = 'past_due';
    case Paused = 'paused';
    /** Stripe status=unpaid。督促を終えても未払いのまま契約が残っている (canceled とは別)。 */
    case Unpaid = 'unpaid';
    case Inactive = 'inactive';

    public static function fromSubscription(Subscription $sub): self
    {
        if ($sub->stripe_status === 'paused') { return self::Paused; }
        if ($sub->stripe_status === 'past_due') { return self::PastDue; }
        // unpaid は Inactive から分離する: 遮断の可否 (grantsAccess) は同じ false だが、
        // 「支払いが未解決のまま契約が残っている」点で canceled と扱いが分かれる
        // (hasUnsettledPayment 参照)。
        if ($sub->stripe_status === 'unpaid') { return self::Unpaid; }
        ...
    }

    public function grantsAccess(): bool
    {
        return match ($this) {
            self::Active, self::UpgradeRecovery, self::PastDue => true,
            self::Paused, self::Unpaid, self::Inactive => false,
        };
    }

    /**
     * 契約が終了しておらず、支払いが未解決のまま残っているか。
     *
     * true のとき、次の 2 つを**同時に**禁じる (同じ問いなので述語を 2 つに割らない):
     *   1. 無料枠 (`free_plan_code='personal'`) への読み替え (BillingAccess::state)
     *   2. 新規契約の開始 (SubscriptionService::startCheckout)
     *
     * **「未回収の債権が 1 円も無いこと」は基準にしない**。督促の末に Stripe が解約した
     * (`canceled`) 契約には未払いの請求書が残りうるが、その回収は課金事業者側の債権管理であり、
     * アプリの利用可否とは切り離す (切り離さないと未払い請求書を追い続ける仕組みが要る)。
     * 判断の詳細は devnotes/20260815-1111-billing-payment-grace-policy/conceptual-design.md (e)。
     */
    public function hasUnsettledPayment(): bool
    {
        return match ($this) {
            self::PastDue, self::Unpaid => true,
            // Paused = trial 終了時にカードが無く read-only になった状態 (未払いの請求が無い)。
            // Inactive = 終了済み / 初回決済が通らず不成立 (incomplete 系を含む)。
            // Active / UpgradeRecovery = 支払い失敗が起きていない。
            self::Active, self::UpgradeRecovery, self::Paused, self::Inactive => false,
        };
    }
```

### PHPStan適合チェック

- [x] `match` は網羅 (case 追加時に PHPStan が未処理 arm を検出する)
- [x] 戻り値の型が明示されている

### テスト計画

- [ ] 新規 `tests/Feature/Billing/SubscriptionStateTableTest.php` (**テーブル駆動**):
  - [ ] Stripe の status 文字列 → `SubscriptionState` → `grantsAccess()` /
        `hasUnsettledPayment()` の期待値表を 1 つ持ち、
        `active / trialing / past_due / paused / unpaid / canceled / incomplete /
        incomplete_expired` の 8 値すべてを回す
  - [ ] `SubscriptionState::cases()` を回して `hasUnsettledPayment()` が全 case で
        例外なく評価できること (網羅 match の空振り防止)
- [ ] 既存 `SubscriptionEntitlementTest` の Inactive データセットから `unpaid` を外し、
      `unpaid` → `Unpaid` / `entitled=false` / `NoActiveSubscription` を別ケースで固定

### リスク

- `SubscriptionState` を読む既存箇所は `grantsAccess()` 経由のみ
  (`BillingAccess` / `AccountDeletionBillingGuard` / `SubscriptionService`) で、`unpaid` の
  可否は変わらないため挙動は不変。`AccountDeletionBillingGuard` の docblock だけ
  「unpaid は Inactive に写像」→「Unpaid に写像 (grantsAccess は同じく false)」に直す。

---

## 施策 5: 猶予の起点を打刻する (単一 writer)

### 変更箇所

- `app/Services/Billing/SubscriptionService.php` の `applySubscriptionSnapshot()` (L167-217)

### 現行コード

```php
            if ($sub instanceof Subscription) {
                $attrs = [
                    'stripe_status' => $snap->status,
                    'stripe_price' => $snap->basePriceId,
                    'quantity' => $snap->baseQuantity,
                    'trial_ends_at' => $snap->trialEndsAt,
                    'ends_at' => $snap->endsAt,
                ];
                if ($snap->currentPeriodEnd !== null) {
                    $attrs['current_period_end'] = $snap->currentPeriodEnd;
                }
                if ($terminated) {
                    $attrs['stripe_schedule_id'] = null;
                    $attrs['schedule_setup_status'] = ScheduleSetupStatus::None;
                }
                $sub->forceFill($attrs)->save();
            }
```

### 変更後コード

```php
            if ($sub instanceof Subscription) {
                $attrs = [
                    'stripe_status' => $snap->status,
                    'stripe_price' => $snap->basePriceId,
                    'quantity' => $snap->baseQuantity,
                    'trial_ends_at' => $snap->trialEndsAt,
                    'ends_at' => $snap->endsAt,
                    // 猶予の起点。**この 1 行が past_due_since の唯一の書込点**
                    // (PastDueSinceWriteInvariantTest が app/ 内の書込を本ファイルに固定する)。
                    'past_due_since' => $this->resolvePastDueSince($sub, $snap->status),
                ];
                ...
            }
```

```php
    /**
     * 猶予の起点を決める (打刻規則は 3 つだけ)。
     *
     *  - past_due を観測 かつ 既存値が NULL → **観測時刻を打つ**
     *  - past_due を観測 かつ 既存値あり   → **上書きしない** (再送のたびに猶予を先送りしない)
     *  - past_due 以外を観測               → **NULL に戻す** (復旧・終了で猶予は消える)
     *
     * 打刻するのは「観測時刻」であって Stripe 側で実際に失敗した時刻ではない
     * (webhook を落としていれば日次突き合わせが観測した時刻になる = 利用者に有利な側へずれる)。
     */
    private function resolvePastDueSince(Subscription $sub, string $status): ?CarbonImmutable
    {
        if ($status !== 'past_due') {
            return null;
        }

        $existing = $sub->past_due_since;

        return $existing !== null
            ? CarbonImmutable::instance($existing)
            : CarbonImmutable::now();
    }
```

### PHPStan適合チェック

- [x] 戻り値 `?CarbonImmutable` を明示
- [x] `$sub->past_due_since` は `Carbon|null` (model docblock) → `CarbonImmutable::instance()` で変換
- [x] `forceFill` の配列は状態キーの明示代入 (mass assignment しない)

### テスト計画

- [ ] 既存 `tests/Feature/Billing/SubscriptionSnapshotSyncTest.php` に追加:
  - [ ] `active` → `past_due` の webhook で `past_due_since` が現在時刻で打たれる
  - [ ] `past_due` → `past_due` の再送で **起点が変わらない** (travelTo で 3 日進めて確認)
  - [ ] `past_due` → `active` で `past_due_since` が NULL に戻る
  - [ ] `customer.subscription.deleted` (terminated) で NULL に戻る
  - [ ] subscription 行が無い間の `created` は no-op (既存契約どおり列も作られない)

### リスク

- 打刻は既存トランザクション (`lockForUpdate` 済み) の中なので、webhook 同時到着でも直列化される。
  ただし**観測の新旧は保証しない** (古い観測が後勝ちすると起点が作り直される)。日次突き合わせと
  Stripe の再送で収束する前提を docs に明記する。

---

## 施策 6: 猶予切れで entitlement を否定する

### 変更箇所

- `app/Services/Billing/SubscriptionService.php` の `deriveEntitlement()` (L115-144) と
  コンストラクタ (L56-59)
- `app/Enums/Billing/EntitlementDeniedReason.php` (case 追加)

### 波及変更

- TypeScript 型定義: なし。**実読で確認済み** — `EntitlementDeniedReason` /
  `SubscriptionEntitlementDto` は `app/Http/` にも `resources/js/` にも 1 件も現れない
  (`BillingAccess` が `->entitled` だけを読む)。画面文言は
  `RequireActiveSubscription::BLOCKED_MESSAGE` と着地ページが持つ。
  **ただし現行 enum の docblock は「フロントは reason 別に状態説明を出し分ける」と書いており、
  実装と食い違っている**。docblock を「現時点で props には露出しておらず、露出させるときは
  TypeScript の union と表示テストを同時に足すこと」へ直し、**非露出をテストで固定する**
  (下記テスト計画)
- API Resource/DTO: `SubscriptionEntitlementDto` は変更なし (reason の値域が増えるだけ)
- テストファイル: `tests/Feature/Billing/SubscriptionEntitlementTest.php` /
  `BillingAccessStateTest.php` / `RequireActiveSubscriptionMiddlewareTest.php` に猶予ケースを追加

### 現行コード

```php
    public function __construct(
        private readonly StripeGatewayInterface $gateway,
        private readonly TicketLedgerService $tickets,
    ) {}
...
        // status=paused は grantsAccess で既に弾かれているが、防御的に二重で確認する。
        if ($sub->stripe_status === 'paused') {
            return SubscriptionEntitlementDto::denied($state, EntitlementDeniedReason::Paused);
        }

        return SubscriptionEntitlementDto::granted($state);
```

### 変更後コード

```php
    public function __construct(
        private readonly StripeGatewayInterface $gateway,
        private readonly TicketLedgerService $tickets,
        private readonly PaymentGracePolicy $grace,
    ) {}
```

```php
        // status=paused は grantsAccess で既に弾かれているが、防御的に二重で確認する。
        if ($sub->stripe_status === 'paused') {
            return SubscriptionEntitlementDto::denied($state, EntitlementDeniedReason::Paused);
        }

        // 支払い失敗の猶予切れ (AG-035 (5))。**PastDue のときだけ**評価する
        // (他の状態はここに到達する時点で猶予の対象ではない)。
        //
        // 起点が NULL のときは遮断しない: 打刻漏れという自分側の不具合をそのまま
        // 支払い済み顧客の締め出しに変えないため。NULL が残る窓は
        // (i) 単一 writer の打刻 (ii) 日次突き合わせの修復 (iii) 移行の backfill で有限にする。
        if ($state === SubscriptionState::PastDue
            && $sub->past_due_since !== null
            && $this->grace->hasExpired(CarbonImmutable::instance($sub->past_due_since), $now)) {
            return SubscriptionEntitlementDto::denied(
                $state,
                EntitlementDeniedReason::PaymentGraceExpired,
            );
        }

        return SubscriptionEntitlementDto::granted($state);
```

```php
// EntitlementDeniedReason
    /** 支払い失敗 (past_due) の猶予期限が切れた (起点は past_due_since / 期限は PaymentGracePolicy)。 */
    case PaymentGraceExpired = 'payment_grace_expired';
```

`deriveEntitlement` の docblock も更新する (判定式に猶予の行を足す):

```
 *   entitled = state.grantsAccess()
 *              AND NOT (trial_ends_at <= now AND !has_payment_method)
 *              AND status != paused
 *              AND NOT (state = PastDue AND past_due_since != null AND 猶予期限切れ)
```

### PHPStan適合チェック

- [x] `$now` は既存の `CarbonImmutable::now()` を再利用 (同一判定内で時刻を 2 度取らない)
- [x] `$sub->past_due_since` の null 検査を先に置く (null 安全)
- [x] 戻り値は DTO (配列返却なし)

### テスト計画

- [ ] `tests/Feature/Billing/SubscriptionEntitlementTest.php` に追加:
  - [ ] `past_due` + PM 有 + 起点が 13 日前 → entitled (猶予中は利用継続)
  - [ ] `past_due` + PM 有 + 起点が **ちょうど 14 日前** → entitled (境界は継続)
  - [ ] `past_due` + PM 有 + 起点が 15 日前 → denied / `PaymentGraceExpired`
  - [ ] `past_due` + PM 有 + 起点 NULL → entitled (起点不明は遮断しない)
  - [ ] `past_due` + trial 終了 + PM 無 + 起点 15 日前 → `TrialEndedWithoutPaymentMethod`
        (**既存理由の優先順位が変わらない**ことの固定)
  - [ ] `active` + 起点 15 日前 (異常データ) → entitled (猶予は PastDue 限定)
- [ ] `tests/Feature/Billing/BillingAccessStateTest.php`: 猶予切れ org の state が
      `ExpiredCheckout` になる
- [ ] `tests/Feature/Billing/RequireActiveSubscriptionMiddlewareTest.php`:
  - [ ] 猶予切れ org の業務 route が redirect される / XHR は 402 + 既存 `BLOCKED_MESSAGE`
  - [ ] 猶予中 org は通る (cohort D の既存挙動が猶予中は維持される)
- [ ] 新規 `tests/Architecture/EntitlementReasonExposureTest.php` (**非露出の固定**。
      テスト冒頭コメントに「これは恒久の禁止ではなく現時点の設計判断の固定である。
      露出させるときは本テストの契約を変え、TypeScript の union と表示テストを同時に足す」と
      1 文書き残す = 機械検査を単なる禁止規則と誤解させない):
  - [ ] `app/Http/` と `resources/js/` に `EntitlementDeniedReason` /
        `SubscriptionEntitlementDto` の参照が 0 件であること (Finder 走査)。
        露出させたくなったときに赤くなり、TypeScript union・表示テストの追加を促す
  - [ ] 負のコントロール: `app/Services/Billing/` では検出される (走査が空振りしていない)

### リスク

- **既存挙動の反転**: 「past_due + PM 有は無期限に許可」から「猶予日数まで許可」へ変わる。
  影響を受けるのは 14 日以上 past_due が続いた組織のみで、backfill により本番の既存行は
  デプロイ時刻から 14 日の猶予を持つ (即時締め出しは発生しない)。

---

## 施策 7: 無料枠へのすり抜けを塞ぐ

### 変更箇所

- `app/Services/Billing/BillingAccess.php` の `state()` (L58-116)

### 現行コード

```php
        if ($organization->free_plan_code === PersonalPlanService::FREE_PLAN_CODE) {
            return OnboardingBillingState::ActiveFreePlan;
        }

        if ($sub instanceof Subscription) {
            return OnboardingBillingState::ExpiredCheckout;
        }
```

### 変更後コード

```php
        // 支払いが未解決のまま契約が残っている間 (past_due / unpaid) は、無料枠の申告があっても
        // 読み替えない (AG-035 (3): 支払いに失敗した利用者が無料枠と同じ状態に落ちるのを防ぐ)。
        // 契約が終了したあとは (未払いが残っていても) 無料枠へ戻る = 現行の解約→無料枠と同じ。
        $unsettled = $sub instanceof Subscription
            && SubscriptionState::fromSubscription($sub)->hasUnsettledPayment();

        if (! $unsettled && $organization->free_plan_code === PersonalPlanService::FREE_PLAN_CODE) {
            return OnboardingBillingState::ActiveFreePlan;
        }

        if ($sub instanceof Subscription) {
            return OnboardingBillingState::ExpiredCheckout;
        }
```

### PHPStan適合チェック

- [x] `$sub` の narrowing (`instanceof`) 済み
- [x] 追加の DB 書き込みなし (読み取り経路の契約を維持)

### テスト計画

- [ ] `tests/Feature/Billing/BillingAccessStateTest.php` に追加:
  - [ ] free 申告あり + `past_due` 猶予切れ → `ExpiredCheckout` (遮断。すり抜けない)
  - [ ] free 申告あり + `past_due` 猶予中 → `Subscribed` (entitled が先に立つ)
  - [ ] free 申告あり + `unpaid` → `ExpiredCheckout`
  - [ ] free 申告あり + `canceled` → `ActiveFreePlan` (**既存の paid→free 経路が壊れない**)
  - [ ] free 申告あり + `incomplete` → `ActiveFreePlan` (カード認証待ちで締め出さない)
  - [ ] free 申告あり + `paused` → `ActiveFreePlan`
- [ ] 「有料の価値は支払い確定より前に渡さない」前提の固定 (概念設計 (e) の依存):
      新規 `tests/Feature/Billing/PaidValueNotGrantedBeforePaymentTest.php`
  - [ ] `incomplete` の契約は entitled にならない
  - [ ] `customer.subscription.created` (status=incomplete) で付くのは組織生涯 1 回の
        無償 signup grant だけで、月次付与は起きない (`invoice.paid` が唯一の契機)
  - [ ] 既に無料申告で signup grant 済みの org では、契約作成でチケットが増えない

### リスク

- free 申告と有償契約を同時に持つ組織は「無料申告 → 後から有償契約」の順でしか生まれない
  (`PersonalPlanService::eligibility` が entitled な契約中の申告を拒む)。母数は小さいが、
  猶予切れ時に無料枠へ落ちないことは**意図した遮断**であり、着地は施策 8 が用意する。

---

## 施策 8: 支払い未解決の契約がある間は新規契約を拒否する

### 変更箇所

- `app/Services/Billing/SubscriptionService.php` の `startCheckoutLocked()` 段 1 (L352-357)
- `app/Http/Controllers/Onboarding/OnboardingController.php` の `show()` (L61-68 の直後)

### 現行コード

```php
        // 段 1: 既存 subscription guard
        $existing = $org->subscription('default');
        Assert::true(
            ! $existing instanceof Subscription || ! $existing->valid(),
            '既に有効なサブスクリプションがあります。プラン変更をご利用ください。'
        );
```

### 変更後コード

```php
        // 段 1: 既存 subscription guard
        $existing = $org->subscription('default');
        Assert::true(
            ! $existing instanceof Subscription || ! $existing->valid(),
            '既に有効なサブスクリプションがあります。プラン変更をご利用ください。'
        );

        // 段 1b: 支払いが未解決のまま残っている契約 (past_due / unpaid) があるときは新規契約を作らない。
        // Cashier の valid() は past_due / unpaid を false と見るため段 1 を素通りするが、
        // Stripe 側の契約は生きており、ここで作ると **2 本目の契約 = 二重請求**になる。
        // 利用者の次の一手は「新規契約」ではなく「支払い方法の更新」なので、そこへ案内する。
        Assert::false(
            $existing instanceof Subscription
                && SubscriptionState::fromSubscription($existing)->hasUnsettledPayment(),
            'お支払いが確認できていないご契約があります。お支払い方法の更新をお願いします。'
        );
```

`BillingController` は既に `InvalidArgumentException` を捕まえて `back()->with('error', …)` に
変換するため、**Controller 側の変更は不要** (押下時にエラーを出す = 禁止事項 8 に適合)。

```php
// OnboardingController::show() — manageBilling 判定の直後に置く
        if (! Gate::allows('manageBilling', $organization)) {
            return new RedirectResponse(route('onboarding.billing-required'));
        }

        // 支払いが未解決のまま契約が残っている組織は、プラン選択ではなく
        // **支払い方法を更新できる画面** (課金画面 = Customer Portal への導線) へ逃がす。
        // 判定は BillingAccess と同じ述語 1 つだけを見る (可否の再判定はしない)。
        $subscription = $organization->subscription('default');
        if ($subscription instanceof Subscription
            && SubscriptionState::fromSubscription($subscription)->hasUnsettledPayment()) {
            return new RedirectResponse(route('billing.index'));
        }
```

### 波及変更

- TypeScript 型定義: なし
- API Resource/DTO: なし
- テストファイル: 下記

### PHPStan適合チェック

- [x] `Assert::false` の引数は bool 式 (mixed を渡さない)
- [x] `OnboardingController` で `Subscription` を import して narrowing
- [x] 追加の DB 書き込みなし

### テスト計画

- [ ] 新規/既存 `tests/Feature/Billing/SubscriptionCheckoutTest.php` (該当ファイルに追記):
  - [ ] `past_due` の契約がある org の契約 POST は Stripe を呼ばずに error flash で戻る
        (`FakeStripeGateway::$created` が空であること = 二重契約を作らない)
  - [ ] `unpaid` でも同じ
  - [ ] `canceled` の契約がある org は従来どおり新規契約を開始できる (回帰)
- [ ] `tests/Feature/Onboarding/*` (既存の onboarding テストに追記):
  - [ ] 猶予切れ (past_due) + manageBilling 保持者が `onboarding.checkout` を GET すると
        `billing.index` へ redirect する
  - [ ] manageBilling 非保持者は従来どおり `onboarding.billing-required` (順序が変わらない)
  - [ ] `billing.index` は課金ゲートの外なので**リダイレクトループにならない** (200 で描画)

### リスク

- 遮断された管理者の導線は `onboarding.checkout` → `billing.index` に変わる。
  ドメイン規約 4 (課金ゲートの着地は onboarding.checkout / onboarding.billing-required) は
  **middleware の着地契約**であり、その先で画面が適切な場所へ送り直すのは既存の
  `hasActiveAccess → billing.index` と同型なので規約と矛盾しない (docs にもこの読みを書く)。

---

## 施策 9: Stripe 契約状態の読み取り口を作る

### 変更箇所

- 新規: `app/Services/Billing/SubscriptionSnapshotMapper.php` (**webhook と突き合わせの共通写像**)
- 新規: `app/DataTransferObjects/Billing/RemoteSubscriptionState.php`
- 新規: `app/Exceptions/Billing/SubscriptionLookupFailedException.php`
- 変更: `app/Services/Billing/Contracts/StripeGatewayInterface.php`
- 変更: `app/Services/Billing/CashierStripeGateway.php`
- 変更: `app/Services/Billing/StripeWebhookProcessor.php` (写像を mapper へ委譲)
- 変更: `app/Services/Billing/Fakes/FakeStripeGateway.php` (bug-hunt / fake 環境)
- 変更: `tests/Support/FakeStripeGateway.php` (テスト spy)

### 写像を 1 か所にする (重要)

「webhook と同じ規則で取り出す」と書くだけでは、**突き合わせ経路だけ別挙動**になる余地が残る
(特に `current_period_end` の新旧 API 差、item の選び方、`endsAt` の優先順位)。
そこで **Stripe の subscription オブジェクト (連想配列) → `SubscriptionSnapshot`** の写像を
新クラス `SubscriptionSnapshotMapper` に 1 本化し、webhook と gateway の両方がそれを呼ぶ。

- webhook は `data_get($payload, 'data.object')` の配列を渡す
- gateway は SDK オブジェクトを `->toArray()` で配列にしてから渡す
  (**SDK 型は gateway の中に閉じたまま**。mapper は配列しか知らない)

写像の規則 (現行 `StripeWebhookProcessor` の実装をそのまま移す。**挙動を変えない**):

| SubscriptionSnapshot | 取り出し位置 |
|---|---|
| `stripeId` | `id` (取れなければ写像失敗として null を返し、呼び出し側が fail-closed) |
| `status` | `status` (欠落時は `'incomplete'`) |
| `basePriceId` | `items.data.0.price.id` |
| `baseQuantity` | `items.data.0.quantity` (int 以外は null) |
| `currentPeriodEnd` | `items.data.0.current_period_end` ?? `current_period_end` (epoch 秒 > 0 のみ) |
| `trialEndsAt` | `trial_end` |
| `endsAt` | `ended_at` ?? `cancel_at` |
| PM 観測 (三値) | `default_payment_method` / `default_source` のいずれかが id を持てば `true`、どちらも空なら `null` |

`StripeWebhookProcessor` 側は `subscriptionHasPaymentMethod()` を mapper の三値観測へ置き換え、
`recordPaymentMethodSnapshot($sub, $observed === true)` と呼ぶ (現行の bool 契約と同値)。

### 波及変更

- TypeScript 型定義: なし
- 外部到達点の目録: **変更不要**。到達点クラス `CashierStripeGateway` は
  `ExternalSeamInventory` に登録済みで、追加するのはメソッドだけ。待ち上限も既存の
  Stripe クライアント pin (`ExternalClientTimeoutServiceProvider`) をそのまま継承する
- fake 配線の目録 (`ExternalFakeWiringInventory`): 抽象は同じ `StripeGatewayInterface` のままで
  登録内容は変わらない。ただし **interface にメソッドを足すので 2 つの fake 実装を必ず更新する**
- 決済 gateway 失敗の観測語彙 (ドメイン規約 7): 対象は `AutoRechargeGatewayInterface` を
  注入されるクラスなので**本施策は母集団外**。ただし同じ規律に自主的に合わせ、
  失敗ログには例外クラス名だけを載せ、**例外 message は載せない**

### 変更後コード

```php
<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Billing;

use App\Services\Billing\SubscriptionSnapshot;

/**
 * Stripe から読んだ契約 1 件の観測結果 (日次突き合わせの入力)。
 *
 * webhook が payload から組むのと**同じ値オブジェクト** (`SubscriptionSnapshot`) を運ぶ。
 * これにより突き合わせは列を直接書かず、webhook と同じ単一 writer
 * (`SubscriptionService::applySubscriptionSnapshot`) を通れる。
 */
final readonly class RemoteSubscriptionState
{
    /**
     * @param  bool|null  $hasPaymentMethod  **null は「決済手段が無い」ではなく「観測できなかった」**
     *                                       (契約に決済手段が紐づかず顧客既定を使う場合を含む)。
     *                                       書込は `=== true` のときだけ行う (単調更新を壊さない)。
     */
    public function __construct(
        public SubscriptionSnapshot $snapshot,
        public ?bool $hasPaymentMethod,
    ) {}
}
```

```php
<?php

declare(strict_types=1);

namespace App\Exceptions\Billing;

use RuntimeException;

/**
 * Stripe の契約照会が失敗した (存在しないのではなく、確認できなかった)。
 *
 * gateway が Stripe SDK の例外を境界の外へ出さないための変換先。
 * 「契約が無い」(= 404) は `null` 返却で表し、本例外は使わない。
 */
final class SubscriptionLookupFailedException extends RuntimeException {}
```

```php
// StripeGatewayInterface
    /**
     * Stripe の契約 1 件を読み、突き合わせ用の観測結果を返す (日次リコンサイル専用の読み取り)。
     *
     * - 見つからない (404 / resource_missing) → **null** (状態を変えない材料として扱う)
     * - API 障害 → SubscriptionLookupFailedException (SDK 例外は外へ出さない)
     *
     * @throws SubscriptionLookupFailedException 照会に失敗したとき
     */
    public function retrieveSubscriptionState(string $stripeSubscriptionId): ?RemoteSubscriptionState;
```

```php
// CashierStripeGateway
    public function retrieveSubscriptionState(string $stripeSubscriptionId): ?RemoteSubscriptionState
    {
        Assert::stringNotEmpty($stripeSubscriptionId);

        try {
            $remote = $this->stripe()->subscriptions->retrieve(
                $stripeSubscriptionId,
                ['expand' => ['items.data']],
            );
        } catch (InvalidRequestException $e) {
            // resource_missing = Stripe 側に無い。API キーの環境取り違えでも同じ形になるため、
            // ここでは「無い」とだけ返し、状態変更するかどうかは呼び出し側が決める。
            if ($e->getStripeCode() === 'resource_missing') {
                return null;
            }

            throw new SubscriptionLookupFailedException('Stripe 契約の照会に失敗しました', previous: $e);
        } catch (ApiErrorException $e) {
            throw new SubscriptionLookupFailedException('Stripe 契約の照会に失敗しました', previous: $e);
        }

        // SDK 型はここで配列へ落とす (mapper へ SDK 型を漏らさない)。
        $object = $remote->toArray();
        $snapshot = $this->mapper->fromStripeSubscription($object);
        if ($snapshot === null) {
            // id が取れない応答は「確認できなかった」として扱う (状態を変える材料にしない)。
            throw new SubscriptionLookupFailedException('Stripe 契約の応答から契約 id を取得できません');
        }

        return new RemoteSubscriptionState(
            snapshot: $snapshot,
            hasPaymentMethod: $this->mapper->observePaymentMethod($object),
        );
    }
```

```php
// app/Services/Billing/SubscriptionSnapshotMapper.php (新規)
/**
 * Stripe の subscription オブジェクト (連想配列) → SubscriptionSnapshot の **唯一の写像**。
 *
 * webhook (payload の data.object) と日次突き合わせ (SDK オブジェクトの toArray()) が
 * 同じ規則で読むことを構造で保証する (写像が 2 つあると突き合わせ経路だけ別挙動になる)。
 * **Stripe SDK の型は受け取らない** (配列だけを知る)。
 */
final class SubscriptionSnapshotMapper
{
    /** @param array<mixed> $object subscription オブジェクト (data.object 相当) */
    public function fromStripeSubscription(array $object): ?SubscriptionSnapshot { ... }

    /**
     * 決済手段の観測 (三値)。**true と「観測できなかった」を潰さない**。
     *  - default_payment_method / default_source のどちらかから id が取れた → true
     *  - どちらも空 → null (顧客既定を使う契約もあるため false と断定しない)
     *
     * @param array<mixed> $object
     */
    public function observePaymentMethod(array $object): ?bool { ... }
}
```

fake 2 つは中立帰還にする (実 Stripe に到達しない):

```php
// app/Services/Billing/Fakes/FakeStripeGateway.php
    public function retrieveSubscriptionState(string $stripeSubscriptionId): ?RemoteSubscriptionState
    {
        // 中立帰還: 契約状態の正本は BughuntBillingSeeder。突き合わせは何も収束させない。
        return null;
    }
```

```php
// tests/Support/FakeStripeGateway.php
    /** @var array<string, RemoteSubscriptionState|null> stripe_id => 観測結果 (未設定は null = 未検出) */
    public array $remoteStates = [];

    /** @var list<string> retrieveSubscriptionState を要求された stripe_id */
    public array $lookedUp = [];

    /** true にすると retrieveSubscriptionState が SubscriptionLookupFailedException を投げる */
    public bool $failOnLookup = false;

    public function retrieveSubscriptionState(string $stripeSubscriptionId): ?RemoteSubscriptionState
    {
        $this->lookedUp[] = $stripeSubscriptionId;
        if ($this->failOnLookup) {
            throw new SubscriptionLookupFailedException('fake stripe: lookup failed');
        }

        return $this->remoteStates[$stripeSubscriptionId] ?? null;
    }
```

### PHPStan適合チェック

- [x] 戻り値 `?RemoteSubscriptionState` を interface / 実装 / fake の 3 箇所で一致させる
- [x] Stripe SDK の型 (`StripeSubscription`) は gateway 内に閉じる (外へ出さない)
- [x] `getStripeCode()` の `?string` を `=== 'resource_missing'` で比較 (null 安全)
- [x] `Assert::stringNotEmpty` で空 id を fail-fast

### テスト計画

- [ ] 新規 `tests/Feature/Billing/SubscriptionSnapshotMapperTest.php`:
  - [ ] 表の 7 フィールド + PM 三値をすべて固定する (新旧 API の `current_period_end` 両系 /
        `ended_at` と `cancel_at` の優先順位 / epoch 0・非 int は null / `id` 欠落は null)
  - [ ] **同一の配列**から webhook 経路 (`data.object`) と gateway 経路 (`toArray()` 相当) の
        両方を通し、得られる `SubscriptionSnapshot` が等しいこと
- [ ] 既存 `tests/Feature/Billing/SubscriptionSnapshotSyncTest.php` /
      `WebhookIdempotencyTest.php` が緑のままであること (**mapper 抽出で webhook の挙動を
      変えていない**ことの回帰)
- [ ] 新規 `tests/Feature/Billing/SubscriptionLookupGatewayTest.php`
      (既存 `SubscriptionSwapGatewayTest` と同じ subclass による `stripe()` 差し替え):
  - [ ] 正常応答 → mapper 経由で `SubscriptionSnapshot` が組み立つ
  - [ ] `id` の無い応答 → `SubscriptionLookupFailedException` (状態を変えない)
  - [ ] `resource_missing` の `InvalidRequestException` → null (例外にしない)
  - [ ] 他の `ApiErrorException` → `SubscriptionLookupFailedException` に変換される
        (Stripe SDK の例外が外へ出ない)
  - [ ] `default_payment_method` あり → `hasPaymentMethod === true`
  - [ ] どちらも無い → `hasPaymentMethod === null` (**false にしない**)
- [ ] 既存 `tests/Architecture/ExternalSeamInventoryTest.php` / `ExternalFakeWiringInvariantTest`
      が緑のままであること (目録の更新が要らないことの確認)

### リスク

- interface にメソッドを足すため、実装漏れがあると起動時に fatal になる。実装 3 箇所
  (Cashier / app fake / test fake) を同一 PR で更新する。

---

## 施策 10: 日次の突き合わせコマンドと配線

### 変更箇所

- 新規: `app/Console/Commands/Billing/ReconcileSubscriptionStatus.php`
- 変更: `app/Services/Billing/SubscriptionService.php` (収束要否の述語を追加)
- 変更: `routes/console.php` (日次配線)

### 変更後コード (収束要否の述語)

```php
// SubscriptionService
    /**
     * 突き合わせで**書き込むべきか** (食い違いがあるか) を判定する。
     *
     * 差分が無いのに毎日 UPDATE すると、更新時刻だけが動き、webhook との競合窓も無駄に広がる。
     * 比較対象は **`applySubscriptionSnapshot` が書く列すべて**にする (status だけを見ると、
     * 更新日 `current_period_end` や解約予定 `ends_at` だけが変わった webhook を落としたとき
     * 永久に収束しない = 更新予告の真実源がずれたまま固まる)。
     *
     * 収束が要るのは次のいずれか:
     *   1. status が違う (両方向)
     *   2. stripe_price / quantity / trial_ends_at / ends_at が違う
     *   3. current_period_end が違う (**snapshot 側が null のときは比較しない** =
     *      「period 欠落 payload では既存値を維持する」書込規則と同じ扱いにする)
     *   4. past_due なのに猶予起点が NULL (打刻漏れの修復)
     *   5. Stripe 側で決済手段を観測できたのにローカルが false (**true 方向のみ**)
     *
     * **`organizations.plan_code` は比較対象にしない**: 同一トランザクションで同期されるため
     * subscriptions 行と食い違わない (未知 Price のときだけ据え置かれる = その回復は本経路の
     * 責務ではない。docs の「保証しないもの」に書く)。
     */
    public function needsSnapshotConvergence(
        Subscription $sub,
        SubscriptionSnapshot $snap,
        ?bool $hasPaymentMethod,
    ): bool {
        if ($sub->stripe_status !== $snap->status
            || $sub->stripe_price !== $snap->basePriceId
            || $sub->quantity !== $snap->baseQuantity) {
            return true;
        }
        if ($this->timesDiffer($sub->trial_ends_at, $snap->trialEndsAt)
            || $this->timesDiffer($sub->ends_at, $snap->endsAt)) {
            return true;
        }
        if ($snap->currentPeriodEnd !== null
            && $this->timesDiffer($sub->current_period_end, $snap->currentPeriodEnd)) {
            return true;
        }
        if ($snap->status === 'past_due' && $sub->past_due_since === null) {
            return true;
        }

        return $hasPaymentMethod === true && ! $sub->has_payment_method;
    }

    /** 日時の差分判定 (null 同士は一致。片方だけ null は差分)。秒精度で比較する。 */
    private function timesDiffer(?DateTimeInterface $local, ?CarbonImmutable $remote): bool
    {
        if ($local === null || $remote === null) {
            return $local !== $remote;
        }

        return $local->getTimestamp() !== $remote->getTimestamp();
    }
```

### 変更後コード (コマンド)

```php
<?php

declare(strict_types=1);

namespace App\Console\Commands\Billing;

/**
 * Stripe の契約状態とローカルを突き合わせる (日次。AG-035 (6))。
 *
 * webhook は「最大 3 日ずれうる」と Stripe 自身が明記しており、1 通落とすとローカルの
 * stripe_status は古いまま固まる。本コマンドは **Stripe を真実として** 食い違いを収束させる
 * 唯一の経路である。
 *
 * **責務の境界** (既存 2 本と重ねない):
 *  - billing:reconcile-auto-recharge (15 分) = チケット自動購入の未決金の回収 (台帳を書く)
 *  - billing:reconcile-schedules (日次)      = 予約 (Schedule) の作りかけの修復 (schedule 列を書く)
 *  - 本コマンド (日次)                        = 契約状態そのもの (applySubscriptionSnapshot の担当列)
 *
 * **金銭は動かさない** (チケットの付与・返金には触れない)。
 * **列を直接書かない** (書込は SubscriptionService の 2 メソッド経由のみ)。
 *
 * 終了コード: 失敗 1 件以上 / ロック取得失敗 / 実行時間上限超過 → FAILURE。
 * 未確認 (404) は状態を変えないので SUCCESS だが、**件数が 0 でなければ必ず report する**。
 *
 * **監視対象**: 本コマンドの終了コードと report()。
 */
final class ReconcileSubscriptionStatus extends Command
{
    protected $signature = 'billing:reconcile-subscription-status';

    protected $description = 'Stripe の契約状態とローカルの契約状態を突き合わせて収束させる (daily)';

    /**
     * 排他ロックの有効期限 (秒)。
     *
     * **実行時間上限 + Stripe 照会 1 回分の最大待ち時間 < 本値** を保つ
     * (下の TIME_BUDGET_SECONDS 参照)。走査中にロックが失効すると 2 本目のプロセスが並走し、
     * 古い観測が後勝ちして猶予起点を作り直す / 消すことが起きうる。
     */
    public const int LOCK_SECONDS = 900;

    /**
     * 走査の実行時間上限 (秒)。**各契約の照会の直前**に超過を検査して打ち切る。
     *
     * これは soft limit で、**最後に開始した照会 1 回分だけ超過しうる**。よって
     * ロック有効期限との関係は次を満たす必要がある (定数比較テストで固定する):
     *
     *   TIME_BUDGET_SECONDS + STRIPE_CONNECT_TIMEOUT_SECONDS + STRIPE_TIMEOUT_SECONDS
     *     < LOCK_SECONDS       (現行値: 600 + 5 + 20 = 625 < 900)
     *
     * **前提**: Stripe SDK の再試行は 0 回に pin されている
     * (`ExternalClientTimeouts::STRIPE_MAX_NETWORK_RETRIES`)。再試行を許すと SDK 側の
     * バックオフ待機が加わり、この式では上限を表せなくなるため、**再試行 0 回そのものを
     * テストで固定する**。将来再試行を許すときは、バックオフ待機を含む式へ契約を変更する。
     *
     * **保証範囲**: ここで抑えるのは **Stripe 照会による待機**であって、DB のロック待ち等
     * 照会後の処理時間まで含む絶対的な TTL 保証ではない (誇張しない)。
     */
    public const int TIME_BUDGET_SECONDS = 600;

    /** 1 chunk の件数。 */
    private const int CHUNK_SIZE = 100;

    /** report に載せる organization id の上限 (超過分は件数だけ書く)。 */
    private const int REPORTED_ID_LIMIT = 50;

    public function handle(StripeGatewayInterface $gateway, SubscriptionService $subscriptions): int
    {
        try {
            /** @var int $exitCode */
            $exitCode = Cache::lock('billing:reconcile-subscription-status', self::LOCK_SECONDS)
                ->block(5, fn (): int => $this->reconcile($gateway, $subscriptions));

            return $exitCode;
        } catch (LockTimeoutException $e) {
            $this->error('別プロセスが billing:reconcile-subscription-status を実行中。exit 1');
            Log::warning('ReconcileSubscriptionStatus: lock timeout');

            return self::FAILURE;
        }
    }
}
```

走査本体 (`reconcile`) の骨子:

```php
        $deadline = CarbonImmutable::now()->addSeconds(self::TIME_BUDGET_SECONDS);
        $checked = $converged = $missing = $failed = 0;
        $missingIds = $failedIds = [];
        $timedOut = false;

        Subscription::query()
            ->where('type', 'default')
            // Stripe 側で終了は不可逆なので、ローカルが終了扱いの行は照会しない
            // (照会対象が単調増加しない)。**帰結**: 誤って終了と書かれた行は自動回復しない。
            ->whereNotIn('stripe_status', ['canceled', 'incomplete_expired'])
            ->orderBy('id')
            ->chunkById(self::CHUNK_SIZE, function (Collection $subs) use (...): bool {

                foreach ($subs as $sub) {
                    // **1 件ごとに**残り時間を見る。chunk 開始時だけの検査では、1 chunk が
                    // 最大 100 回の外部呼び出しを含むため、遅い応答が続くと実行時間上限どころか
                    // ロックの有効期限まで跨ぎ、2 本目のプロセスが並走しうる。
                    if (CarbonImmutable::now()->greaterThan($deadline)) {
                        $timedOut = true;

                        return false; // chunk の途中でも即座に止める (残りは照会しない)
                    }

                    $checked++;
                    try {
                        $remote = $gateway->retrieveSubscriptionState($sub->stripe_id);
                    } catch (SubscriptionLookupFailedException $e) {
                        $failed++;
                        $failedIds[] = $sub->organization_id;
                        // 例外 message は載せない (外部生成の可変文字列)。クラス名だけ。
                        // previous は無いことがある (id 欠落など gateway 自身が投げる場合) ため
                        // null 安全に落とす。
                        $previous = $e->getPrevious();
                        Log::warning('reconcile-subscription-status: lookup failed', [
                            'organization_id' => $sub->organization_id,
                            'error_class' => $previous !== null ? $previous::class : $e::class,
                        ]);

                        continue;
                    }

                    if ($remote === null) {
                        $missing++;
                        $missingIds[] = $sub->organization_id;

                        continue; // 状態は変えない
                    }

                    if (! $subscriptions->needsSnapshotConvergence($sub, $remote->snapshot, $remote->hasPaymentMethod)) {
                        continue;
                    }

                    $organization = $sub->organization;
                    Assert::isInstanceOf($organization, Organization::class);

                    $subscriptions->applySubscriptionSnapshot(
                        $organization,
                        $remote->snapshot,
                        terminated: $remote->snapshot->status === 'canceled',
                    );
                    if ($remote->hasPaymentMethod === true) {
                        $subscriptions->recordPaymentMethodSnapshot($sub, true);
                    }
                    $converged++;
                }

                return true;
            });
```

集約報告と終了コード:

```php
        $this->info(sprintf(
            'reconcile-subscription-status: checked=%d converged=%d missing=%d failed=%d',
            $checked, $converged, $missing, $failed,
        ));

        // 1 実行につき 1 回だけ report する (件数 + organization id のみ = PII を載せない)。
        if ($missing > 0 || $failed > 0) {
            report(new RuntimeException(sprintf(
                'Stripe 契約の突き合わせ未完了: missing=%d ids=%s / failed=%d ids=%s',
                $missing, $this->formatIds($missingIds), $failed, $this->formatIds($failedIds),
            )));
        }

        return ($failed > 0 || $timedOut) ? self::FAILURE : self::SUCCESS;
```

`routes/console.php` への配線:

```php
/*
|--------------------------------------------------------------------------
| Stripe 契約状態の突き合わせ (AG-035 (6))
|--------------------------------------------------------------------------
| webhook 欠落でローカルの契約状態が固まると、支払い失敗の遮断も復旧も起きない。
| 日次で Stripe を真実として収束させる。**チケット (金銭) には触れない**。
|
| 既存の 2 本とは書く列が重ならない (相乗りさせない):
|   - billing:reconcile-auto-recharge (15 分) = チケット自動購入の未決金
|   - billing:reconcile-schedules (日次)      = 予約 (Schedule) の作りかけ
|
| **監視対象**: 終了コードと report() (未確認・失敗はここにしか出ない)。
*/
Schedule::command('billing:reconcile-subscription-status')
    ->daily()
    ->onOneServer()
    ->withoutOverlapping()
    ->onFailure(static fn () => report(new RuntimeException(
        'billing:reconcile-subscription-status 失敗 — Stripe と契約状態が突き合わせられていない',
    )));
```

### PHPStan適合チェック

- [x] `chunkById` のクロージャは `Collection<int, Subscription>` を型注釈
- [x] `Cache::lock()->block()` の `mixed` を `@var int` + 戻り型で絞る (既存 2 箇所と同作法)
- [x] `$sub->organization` の narrowing に `Assert::isInstanceOf`
- [x] `report()` に渡すのは例外オブジェクト (文字列を渡さない)

### テスト計画

- [ ] 新規 `tests/Feature/Billing/ReconcileSubscriptionStatusTest.php`
      (`StripeGatewayInterface` に `tests/Support/FakeStripeGateway` を bind して駆動):
  - [ ] **状態の収束**: ローカル `active` / remote `past_due` → ローカルが `past_due` になり
        `past_due_since` が打たれる
  - [ ] **逆向きの収束**: ローカル `past_due` / remote `active` → `active` + 起点が NULL に戻る
  - [ ] **打刻漏れの修復**: ローカル `past_due` + 起点 NULL / remote も `past_due` →
        起点が観測時刻で埋まる
  - [ ] **差分なしでは書かない**: すべて一致 → `updated_at` が変わらない (無駄な UPDATE をしない)
  - [ ] **status 以外の差分も収束する**: status は同じで `current_period_end` /
        `ends_at` / `trial_ends_at` / `stripe_price` / `quantity` だけが違う場合も収束する
        (更新予告の真実源がずれたまま固まらない)
  - [ ] **period 欠落は既存値を維持**: snapshot の `currentPeriodEnd` が null のときは
        差分と見なさず、ローカルの `current_period_end` を消さない
  - [ ] **PM の三値**: remote `hasPaymentMethod=null` ではローカル false のまま /
        `true` では true になる / 一度 true になった行は `null` 観測で false に戻らない
        (`=== true` の厳密比較。truthy 判定でないこと)
  - [ ] **未確認 (404)**: remote が null → 状態は 1 列も変わらず、`missing` として report される /
        終了コードは SUCCESS
  - [ ] **失敗**: `SubscriptionLookupFailedException` → 走査は次の行へ進み、
        report + 終了コード FAILURE
  - [ ] **report は 1 実行 1 回**・内容は件数と organization id のみ (PII なし)。
        `DetectOrphanBillingOrganizationsCommandTest` と同じ handler spy を使う
  - [ ] **終了済みは照会しない**: ローカル `canceled` / `incomplete_expired` の行は
        `FakeStripeGateway::$lookedUp` に現れない
  - [ ] **金銭を動かさない**: 収束の前後で `ticket_ledger_entries` の件数が変わらない
  - [ ] **多重起動**: ロック保持中の実行は FAILURE で即終了する
  - [ ] **配線**: `Schedule` の登録に `billing:reconcile-subscription-status` が daily で在り、
        `onOneServer` / `withoutOverlapping` が付いている (`AutoRechargeReconcileTest` と同型)
- [ ] **実行時間上限**は 3 項目を固定する (`travelTo` で時計を進める fake gateway を使う):
  - [ ] chunk の**途中**で上限を超えたら、残りの契約を**照会せず** (`$lookedUp` に現れない)
        FAILURE で終わる
  - [ ] 2 chunk 目に入らないこと (chunk 境界でも止まる)
  - [ ] **安全余白の関係**を定数比較で固定する (単なる `600 < 900` にしない):

        ```php
        // 再試行 0 回が前提 (再試行を許すと SDK のバックオフ待機が式に入らなくなる)
        expect(ExternalClientTimeouts::STRIPE_MAX_NETWORK_RETRIES)->toBe(0);
        expect(
            ReconcileSubscriptionStatus::TIME_BUDGET_SECONDS
            + ExternalClientTimeouts::STRIPE_CONNECT_TIMEOUT_SECONDS
            + ExternalClientTimeouts::STRIPE_TIMEOUT_SECONDS
        )->toBeLessThan(ReconcileSubscriptionStatus::LOCK_SECONDS);
        ```

        (**再試行を許可するか、安全余白の式を破るまで待ち上限・実行時間上限を緩めると赤くなる**。
        timeout を 20→21 秒のように余白の範囲で変えても式は成立する = そこは検出しない。
        テストから読むため 2 定数は `public const` にする)

### リスク

- 契約数に比例して Stripe API 呼び出しが増える (1 契約 1 回)。日次かつ chunk 分割で、
  現在の契約数 (数十規模) では実行時間上限に届かない。上限に触れ始めたら
  「前回確認時刻の古い順に上限件数だけ処理する」形へ変えるが、**今は作らない**
  (今必要なものだけ作る)。触れたことは終了コードと report で分かる。
- ローカルが終了扱い (`canceled` / `incomplete_expired`) の行は照会対象外なので、
  誤って終了と書かれた行は自動回復しない (**保証しない**ことを docs に明記)。

---

## 施策 11: 書込単一化の Architecture テスト

### 変更箇所

- 新規: `tests/Architecture/PastDueSinceWriteInvariantTest.php`

### 変更後コード

```php
<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/*
|--------------------------------------------------------------------------
| past_due_since 書き込み経路の invariant
|--------------------------------------------------------------------------
|
| `subscriptions.past_due_since` は猶予の起点 = 遮断の期日を決める状態キーのため、
| 書き込み (array key 代入 / プロパティ代入) は SubscriptionService に閉じる。
| 読み取り (`->past_due_since` の比較・null 検査) は対象外。
|
| **保証範囲を誇張しない**: 走査根は app/ のみで、database/migrations/ の backfill と
| 生 SQL は母集団に入らない (移行は 1 本きりで、手動 SQL の禁止は runbook が担う)。
*/

test('app/ 内の past_due_since 書き込みは SubscriptionService に閉じる', function (): void {
    $allowlist = [
        'app/Services/Billing/SubscriptionService.php',
    ];

    // 書き込みパターン: array key 代入 と プロパティ代入 (=== / !== 比較は除外)。
    $finder = Finder::create()
        ->in(base_path('app'))
        ->files()
        ->name('*.php')
        ->contains('/([\'"])past_due_since\1\s*=>|->past_due_since\s*=[^=]/');

    $violations = [];
    foreach ($finder as $file) {
        $relative = str_replace(base_path().'/', '', $file->getPathname());
        if (in_array($relative, $allowlist, true)) {
            continue;
        }
        // **型宣言 (casts) は書き込みではない**: Subscription model の
        // `'past_due_since' => 'datetime',` だけは許す。それ以外の array key 代入は違反。
        // getRealPath() は string|false のため使わない (getPathname() は必ず string)。
        foreach (castOnlyViolations($file->getPathname()) as $line) {
            $violations[] = $relative.':'.$line;
        }
    }

    expect($violations)->toBe([], 'past_due_since の書き込みは SubscriptionService 経由に限定してください: '.implode(', ', $violations));
});
```

`castOnlyViolations()` は当該ファイルの `past_due_since` を含む行を 1 行ずつ見て、
**次のいずれにも当てはまらない行**を違反として返す:

- `@property` 等の docblock 行 (行頭が `*`)
- **`casts()` メソッドの本体の行範囲に入っており、かつ** cast 宣言そのもの
  (`'past_due_since' => 'datetime',` に完全一致) である行

行範囲は文字列一致ではなく **トークンで確定する**: 既存の
`Tests\Support\PhpReferenceScanner::tokens()` (行番号つきの正規化トークン列) を使い、
`function casts` の本体の `{` から対応する `}` までの行範囲を波括弧の深さで求める。
**文脈を見ずに「文字列が一致したら免除」にしない** — それだと
`forceFill(['past_due_since' => 'datetime'])` のような書込も通ってしまい、
「model 内の将来の直書きを検出する」という保証を満たさない (Codex Round 2 の [Warning])。

これにより「model の `casts()` は通るが、model 内の `forceFill(['past_due_since' => …])` は
落ちる」ようにする (Codex Round 1 の [Critical]: 汎用 array key 検出だと施策 1 の cast を
誤検出して常に赤くなる / model をまるごと allowlist に入れると将来の直書きを見逃す)。

負のコントロール (走査・免除が空振りしていないこと) を 3 本置く:

```php
test('負のコントロール: 単一 writer 自身は書き込みとして検出される', function (): void {
    $finder = Finder::create()
        ->in(base_path('app/Services/Billing'))
        ->files()
        ->name('SubscriptionService.php')
        ->contains('/([\'"])past_due_since\1\s*=>/');

    expect(iterator_count($finder))->toBe(1);
});

test('負のコントロール: cast 以外の array key 代入は違反として拾われる', function (): void {
    // 一時ファイルに `'past_due_since' => CarbonImmutable::now(),` を書いて判定関数へ通し、
    // 1 件返ることを確認する (判定関数が常に空配列を返す実装に退化していないこと)。
});

test('負のコントロール: casts() の外にある cast と同じ文字列は免除されない', function (): void {
    // `forceFill(['past_due_since' => 'datetime'])` を **casts() の外**に持つ一時ファイルを
    // 判定関数へ通し、違反として 1 件返ることを確認する
    // (免除が「文字列一致」ではなく「casts() の行範囲」で効いていること)。
});
```

### テスト計画

- 本施策自体がテストである。**負のコントロールを 3 本**必ず置く
  (正規表現や判定関数が空振りしていると検査が常に緑になるため。既存の
  `ExternalClientTimeoutInventoryTest` が同じ考え方を持つ)

### リスク

- ファイル粒度の検査であり、`SubscriptionService` 内でメソッドが増えても検出しない
  (メソッド単位の fail-first は施策 5 の behavioral テストが担う)。

---

## 施策 12: ドキュメント

### 変更箇所

- `docs/architecture.md`: 「支払い失敗の猶予と契約状態の突き合わせ」節を新設
- `docs/billing-gate-inversion-runbook.md`: 移行手順の節を追記

### 書く内容 (要点)

- 猶予の定義と唯一の正本 (`PaymentGracePolicy`)、起点は**観測時刻**であること
- **チケット残高切れの猶予は 0** (予約時点で即拒否) — これは未実装ではなく決定である
- 支払い未解決 (`PastDue` / `Unpaid`) の間だけ、無料枠への読み替えと新規契約を禁じること。
  契約終了後の債権回収は課金事業者側の仕事として entitlement と切り離すこと
- 突き合わせコマンドの責務境界 (既存 2 本との表) と**監視対象** (終了コードと report)
- **保証しないもの**: 実際の失敗時刻ではなく観測時刻であること / 未確認・失敗が続く契約では
  猶予も遮断も動かないこと / webhook との観測順序は保証しないこと (収束は最終的) /
  ローカルが終了扱いの行は照会対象外であること / PM は true 方向のみ修復すること
- runbook: backfill は migration の中だけで完結し、**手動 SQL / tinker で `past_due_since` を
  書かない**こと。デプロイ直後は全既存 past_due 行の猶予がデプロイ時刻起点になること

### テスト計画

- [ ] ドキュメントに機械検査は付けない (既存の `docs/` も同様)。ただし施策 2 のコメントと
      `docs/architecture.md` の日数記述が食い違わないよう、**日数の数値は config を正本**と書き、
      docs には既定値の出典として `config/billing.php` を参照させる

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone** |
| 判断根拠 | 課金ゲートの判定 (entitlement)・状態同期の単一 writer・console 配線・enum を同時に触るため、他の TODO と並行すると衝突する。また施策 6 だけ先に入れると猶予切れが無料枠へすり抜け、二重契約も作れる状態が残るので、6・7・8 を分割できない |
| 競合リスク | `SubscriptionService` / `BillingAccess` / `routes/console.php` を触る他 TODO と衝突しうる。課金領域の別 TODO とは同時実装しない |

## 実装完了の条件 (DoD)

- [ ] `composer test` / `composer phpstan` / `vendor/bin/pint --test` が緑
- [ ] 施策ごとのテストがすべて存在する (テストのない施策を残さない)
- [ ] `past_due_since` の書込が `SubscriptionService` 1 ファイルに閉じている (Architecture テスト)
- [ ] `docs/architecture.md` の監視対象リストに新コマンドが載っている
- [ ] 実 Stripe への通信はテストからも実装からも増えていない (gateway 越しのみ)
