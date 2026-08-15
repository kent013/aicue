【アプリの使命 (North Star)】
## 使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。


【禁止事項】
## 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)。
   **実行経路を持つ prompt factory は `LlmCallContextData` を必須引数で受け、
   `->withMetadata($context->toMetadata())` で帰属 (organization / subject) を付ける** — 付け忘れは
   PHPStan level 10 が落とす。帰属の対象を持たない見本 (`ExampleSummaryPrompt`) は
   `PromptUntrustedInputContractTest` の inventory へ**帰属キーを空配列で exempt 登録**する
   (deny-by-default なので exempt にする操作がレビューで必ず見える)。
   欠けると `llm_call_logs.metadata_missing` になり組織別・対象別の費用が出せない
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。
   招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(Artifact ツールでの成果物公開を行わない。成果物はリポジトリ内のファイルとして出力する)


【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

【あなたの役割】
Laravel 12 + Svelte 5 + Inertia のアプリ (AI-CUE) のコードレビュアーとして、TODO T163
「支払い失敗の猶予期限化と Stripe 契約状態の日次突き合わせ」の実装差分をレビューせよ。

【レビュー観点】
1. 設計との一致性 (詳細設計書どおりに実装されているか。逸脱があるなら妥当か)
2. 正確性 (境界条件・null 安全・並行性・トランザクション境界・冪等性)
3. PHPStan level 10 適合性 (型の widen / ignore を使っていないか)
4. DTO / JsonResource パターン (`response()->json()` の直書きが無いか)
5. テスト網羅性 (施策ごとにテストがあるか。負のコントロールが空振りしていないか)
6. セキュリティ (テナント境界・認可・PII をログに載せていないか・二重請求)
7. 課金ドメインの不変条件 (単一 writer / 監視対象 / 保証範囲の誇張が無いか)

※ 本差分はバックエンドのみで `resources/js` / `resources/css` を 1 行も触っていないため、
   DESIGN.md 準拠 / Atomic Design 準拠の観点は該当しない (差分で確認可能)。

【出力形式】
- ファイルごとに判定を書く
- 指摘は [Critical] / [Warning] / [Suggestion] に分類する
- 最後に全体判定を **APPROVED** または **CHANGES_REQUESTED** の 1 語で明記する
- 「念のため」の追加実装を促す指摘はしない (オーバーエンジニアリング禁止)

---

## 詳細設計書

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

---

## 実装差分 (git diff)

```diff
diff --git a/app/Console/Commands/Billing/ReconcileSubscriptionStatus.php b/app/Console/Commands/Billing/ReconcileSubscriptionStatus.php
new file mode 100644
index 0000000..236c79d
--- /dev/null
+++ b/app/Console/Commands/Billing/ReconcileSubscriptionStatus.php
@@ -0,0 +1,232 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Console\Commands\Billing;
+
+use App\Exceptions\Billing\SubscriptionLookupFailedException;
+use App\Models\Billing\Subscription;
+use App\Models\Organization;
+use App\Services\Billing\Contracts\StripeGatewayInterface;
+use App\Services\Billing\SubscriptionService;
+use Carbon\CarbonImmutable;
+use Illuminate\Console\Command;
+use Illuminate\Contracts\Cache\LockTimeoutException;
+use Illuminate\Database\Eloquent\Collection;
+use Illuminate\Support\Facades\Cache;
+use Illuminate\Support\Facades\Log;
+use RuntimeException;
+use Webmozart\Assert\Assert;
+
+/**
+ * Stripe の契約状態とローカルを突き合わせる (日次。AG-035 (6))。
+ *
+ * webhook は「最大 3 日ずれうる」と Stripe 自身が明記しており、1 通落とすとローカルの
+ * stripe_status は古いまま固まる。本コマンドは **Stripe を真実として** 食い違いを収束させる
+ * 唯一の経路である。
+ *
+ * **責務の境界** (既存 2 本と重ねない):
+ *  - billing:reconcile-auto-recharge (15 分) = チケット自動購入の未決金の回収 (台帳を書く)
+ *  - billing:reconcile-schedules (日次)      = 予約 (Schedule) の作りかけの修復 (schedule 列を書く)
+ *  - 本コマンド (日次)                        = 契約状態そのもの (applySubscriptionSnapshot の担当列)
+ *
+ * **金銭は動かさない** (チケットの付与・返金には触れない)。
+ * **列を直接書かない** (書込は SubscriptionService の 2 メソッド経由のみ)。
+ *
+ * 終了コード: 失敗 1 件以上 / ロック取得失敗 / 実行時間上限超過 → FAILURE。
+ * 未確認 (404) は状態を変えないので SUCCESS だが、**件数が 0 でなければ必ず report する**。
+ *
+ * **監視対象**: 本コマンドの終了コードと report()。
+ */
+final class ReconcileSubscriptionStatus extends Command
+{
+    /**
+     * 排他ロックの有効期限 (秒)。
+     *
+     * **実行時間上限 + Stripe 照会 1 回分の最大待ち時間 < 本値** を保つ
+     * (下の TIME_BUDGET_SECONDS 参照)。走査中にロックが失効すると 2 本目のプロセスが並走し、
+     * 古い観測が後勝ちして猶予起点を作り直す / 消すことが起きうる。
+     */
+    public const int LOCK_SECONDS = 900;
+
+    /**
+     * 走査の実行時間上限 (秒)。**各契約の照会の直前**に超過を検査して打ち切る。
+     *
+     * これは soft limit で、**最後に開始した照会 1 回分だけ超過しうる**。よって
+     * ロック有効期限との関係は次を満たす必要がある (定数比較テストで固定する):
+     *
+     *   TIME_BUDGET_SECONDS + STRIPE_CONNECT_TIMEOUT_SECONDS + STRIPE_TIMEOUT_SECONDS
+     *     < LOCK_SECONDS       (現行値: 600 + 5 + 20 = 625 < 900)
+     *
+     * **前提**: Stripe SDK の再試行は 0 回に pin されている
+     * (`ExternalClientTimeouts::STRIPE_MAX_NETWORK_RETRIES`)。再試行を許すと SDK 側の
+     * バックオフ待機が加わり、この式では上限を表せなくなるため、**再試行 0 回そのものを
+     * テストで固定する**。将来再試行を許すときは、バックオフ待機を含む式へ契約を変更する。
+     *
+     * **保証範囲**: ここで抑えるのは **Stripe 照会による待機**であって、DB のロック待ち等
+     * 照会後の処理時間まで含む絶対的な TTL 保証ではない (誇張しない)。
+     */
+    public const int TIME_BUDGET_SECONDS = 600;
+
+    /** 1 chunk の件数。 */
+    private const int CHUNK_SIZE = 100;
+
+    /** report に載せる organization id の上限 (超過分は件数だけ書く)。 */
+    private const int REPORTED_ID_LIMIT = 50;
+
+    protected $signature = 'billing:reconcile-subscription-status';
+
+    protected $description = 'Stripe の契約状態とローカルの契約状態を突き合わせて収束させる (daily)';
+
+    public function handle(StripeGatewayInterface $gateway, SubscriptionService $subscriptions): int
+    {
+        try {
+            /** @var int $exitCode */
+            $exitCode = Cache::lock('billing:reconcile-subscription-status', self::LOCK_SECONDS)
+                ->block(5, fn (): int => $this->reconcile($gateway, $subscriptions));
+
+            return $exitCode;
+        } catch (LockTimeoutException $e) {
+            $this->error('別プロセスが billing:reconcile-subscription-status を実行中。exit 1');
+            Log::warning('ReconcileSubscriptionStatus: lock timeout');
+
+            return self::FAILURE;
+        }
+    }
+
+    /** 走査本体 (ロックの内側)。 */
+    private function reconcile(StripeGatewayInterface $gateway, SubscriptionService $subscriptions): int
+    {
+        // 走査状態は 1 実行に閉じたローカル値として持つ (同一プロセス内の再呼び出しで累積しない)。
+        $tally = [
+            'checked' => 0,
+            'converged' => 0,
+            'missing' => 0,
+            'failed' => 0,
+            'missingIds' => [],
+            'failedIds' => [],
+        ];
+        $timedOut = false;
+        $deadline = CarbonImmutable::now()->addSeconds(self::TIME_BUDGET_SECONDS);
+
+        Subscription::query()
+            ->where('type', 'default')
+            // Stripe 側で終了は不可逆なので、ローカルが終了扱いの行は照会しない
+            // (照会対象が単調増加しない)。**帰結**: 誤って終了と書かれた行は自動回復しない。
+            ->whereNotIn('stripe_status', ['canceled', 'incomplete_expired'])
+            ->with('organization')
+            ->orderBy('id')
+            ->chunkById(self::CHUNK_SIZE, function (Collection $subs) use (
+                $gateway,
+                $subscriptions,
+                $deadline,
+                &$tally,
+                &$timedOut,
+            ): bool {
+                /** @var Collection<int, Subscription> $subs */
+                foreach ($subs as $sub) {
+                    // **1 件ごとに**残り時間を見る。chunk 開始時だけの検査では、1 chunk が
+                    // 最大 100 回の外部呼び出しを含むため、遅い応答が続くと実行時間上限どころか
+                    // ロックの有効期限まで跨ぎ、2 本目のプロセスが並走しうる。
+                    if (CarbonImmutable::now()->greaterThan($deadline)) {
+                        $timedOut = true;
+
+                        return false; // chunk の途中でも即座に止める (残りは照会しない)
+                    }
+
+                    $this->reconcileOne($gateway, $subscriptions, $sub, $tally);
+                }
+
+                return true;
+            });
+
+        $this->info(sprintf(
+            'reconcile-subscription-status: checked=%d converged=%d missing=%d failed=%d',
+            $tally['checked'], $tally['converged'], $tally['missing'], $tally['failed'],
+        ));
+
+        // 1 実行につき 1 回だけ report する (件数 + organization id のみ = PII を載せない)。
+        if ($tally['missing'] > 0 || $tally['failed'] > 0) {
+            report(new RuntimeException(sprintf(
+                'Stripe 契約の突き合わせ未完了: missing=%d ids=%s / failed=%d ids=%s',
+                $tally['missing'],
+                $this->formatIds($tally['missingIds']),
+                $tally['failed'],
+                $this->formatIds($tally['failedIds']),
+            )));
+        }
+
+        return ($tally['failed'] > 0 || $timedOut) ? self::FAILURE : self::SUCCESS;
+    }
+
+    /**
+     * 契約 1 件の突き合わせ。1 件失敗で走査を止めない (件数へ積んで次へ進む)。
+     *
+     * @param  array{checked: int, converged: int, missing: int, failed: int, missingIds: list<int>, failedIds: list<int>}  $tally
+     */
+    private function reconcileOne(
+        StripeGatewayInterface $gateway,
+        SubscriptionService $subscriptions,
+        Subscription $sub,
+        array &$tally,
+    ): void {
+        $tally['checked']++;
+
+        try {
+            $remote = $gateway->retrieveSubscriptionState($sub->stripe_id);
+        } catch (SubscriptionLookupFailedException $e) {
+            $tally['failed']++;
+            $tally['failedIds'][] = $sub->organization_id;
+            // 例外 message は載せない (外部生成の可変文字列)。クラス名だけ。
+            // previous は無いことがある (id 欠落など gateway 自身が投げる場合) ため null 安全に落とす。
+            $previous = $e->getPrevious();
+            Log::warning('reconcile-subscription-status: lookup failed', [
+                'organization_id' => $sub->organization_id,
+                'error_class' => $previous !== null ? $previous::class : $e::class,
+            ]);
+
+            return;
+        }
+
+        if ($remote === null) {
+            $tally['missing']++;
+            $tally['missingIds'][] = $sub->organization_id;
+
+            return; // 状態は変えない
+        }
+
+        if (! $subscriptions->needsSnapshotConvergence($sub, $remote->snapshot, $remote->hasPaymentMethod)) {
+            return;
+        }
+
+        $organization = $sub->organization;
+        Assert::isInstanceOf($organization, Organization::class);
+
+        $subscriptions->applySubscriptionSnapshot(
+            $organization,
+            $remote->snapshot,
+            terminated: $remote->snapshot->status === 'canceled',
+        );
+        if ($remote->hasPaymentMethod === true) {
+            $subscriptions->recordPaymentMethodSnapshot($sub, true);
+        }
+        $tally['converged']++;
+    }
+
+    /**
+     * report 用の id 列 (上限を超えた分は件数だけ書く)。
+     *
+     * @param  list<int>  $ids
+     */
+    private function formatIds(array $ids): string
+    {
+        if ($ids === []) {
+            return '-';
+        }
+
+        $shown = array_slice($ids, 0, self::REPORTED_ID_LIMIT);
+        $rest = count($ids) - count($shown);
+
+        return implode(',', $shown).($rest > 0 ? " (他 {$rest} 件)" : '');
+    }
+}
diff --git a/app/DataTransferObjects/Billing/RemoteSubscriptionState.php b/app/DataTransferObjects/Billing/RemoteSubscriptionState.php
new file mode 100644
index 0000000..4c51da7
--- /dev/null
+++ b/app/DataTransferObjects/Billing/RemoteSubscriptionState.php
@@ -0,0 +1,27 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\DataTransferObjects\Billing;
+
+use App\Services\Billing\SubscriptionSnapshot;
+
+/**
+ * Stripe から読んだ契約 1 件の観測結果 (日次突き合わせの入力)。
+ *
+ * webhook が payload から組むのと**同じ値オブジェクト** (`SubscriptionSnapshot`) を運ぶ。
+ * これにより突き合わせは列を直接書かず、webhook と同じ単一 writer
+ * (`SubscriptionService::applySubscriptionSnapshot`) を通れる。
+ */
+final readonly class RemoteSubscriptionState
+{
+    /**
+     * @param  bool|null  $hasPaymentMethod  **null は「決済手段が無い」ではなく「観測できなかった」**
+     *                                       (契約に決済手段が紐づかず顧客既定を使う場合を含む)。
+     *                                       書込は `=== true` のときだけ行う (単調更新を壊さない)。
+     */
+    public function __construct(
+        public SubscriptionSnapshot $snapshot,
+        public ?bool $hasPaymentMethod,
+    ) {}
+}
diff --git a/app/Enums/Billing/EntitlementDeniedReason.php b/app/Enums/Billing/EntitlementDeniedReason.php
index f89076c..33c7e41 100644
--- a/app/Enums/Billing/EntitlementDeniedReason.php
+++ b/app/Enums/Billing/EntitlementDeniedReason.php
@@ -8,12 +8,16 @@
  * entitlement (利用可否) を否定する理由。
  *
  * `SubscriptionService::deriveEntitlement` が `entitled=false` のとき必ず付随させる。
- * フロントは reason 別に状態説明 (paused / trial 終了 & PM 無 / 請求失敗) を出し分ける。
  *
- * 注意: `PastDue` (state=PastDue) かつ PM 有りは entitled=true (請求失敗中も利用継続) のため、
- * ここに PastDue を「利用継続中」の理由としては置かない。past_due で entitled=false になる
- * のは PM 無し past_due のみで、それは trial 終了 & カード無しとして
- * `TrialEndedWithoutPaymentMethod` で表現する (trial 終了後の paused と区別)。
+ * **現時点では画面 props に露出していない** (`app/Http/` にも `resources/js/` にも参照が無く、
+ * 遮断時の文言は `RequireActiveSubscription::BLOCKED_MESSAGE` と着地ページが持つ)。
+ * 非露出は `EntitlementReasonExposureTest` が固定している。露出させるときは同テストの契約を
+ * 変え、TypeScript の union と表示テストを同時に足すこと。
+ *
+ * 注意: `PastDue` (state=PastDue) かつ PM 有りは**猶予の期限内なら** entitled=true
+ * (請求失敗中も利用継続) のため、ここに PastDue を「利用継続中」の理由としては置かない。
+ * past_due で entitled=false になるのは、PM 無し past_due (trial 終了 & カード無しとして
+ * `TrialEndedWithoutPaymentMethod` で表現する) と、猶予切れ (`PaymentGraceExpired`) の 2 つ。
  */
 enum EntitlementDeniedReason: string
 {
@@ -25,4 +29,7 @@ enum EntitlementDeniedReason: string
 
     /** Stripe status=paused (= 上記の確定状態)。 */
     case Paused = 'paused';
+
+    /** 支払い失敗 (past_due) の猶予期限が切れた (起点は past_due_since / 期限は PaymentGracePolicy)。 */
+    case PaymentGraceExpired = 'payment_grace_expired';
 }
diff --git a/app/Enums/Billing/SubscriptionState.php b/app/Enums/Billing/SubscriptionState.php
index b118db9..bd796f0 100644
--- a/app/Enums/Billing/SubscriptionState.php
+++ b/app/Enums/Billing/SubscriptionState.php
@@ -10,7 +10,7 @@
  * Subscription の派生状態。
  *
  * `Active` / `UpgradeRecovery` は流入制御を通過させる。
- * `Inactive` は `canceled` / `unpaid` / `incomplete` / `incomplete_expired` を統合した拒否状態。
+ * `Inactive` は `canceled` / `incomplete` / `incomplete_expired` を統合した拒否状態。
  * `incomplete` / `unpaid` を `Active` に含めない理由: いずれも支払いが完了していない
  * (= 顧客カードが未承認 or 失敗) 状態のため、流入制御の目的 (= LLM コスト負担確認) に反する。
  *
@@ -33,6 +33,8 @@ enum SubscriptionState: string
     case UpgradeRecovery = 'upgrade_recovery';
     case PastDue = 'past_due';
     case Paused = 'paused';
+    /** Stripe status=unpaid。督促を終えても未払いのまま契約が残っている (canceled とは別)。 */
+    case Unpaid = 'unpaid';
     case Inactive = 'inactive';
 
     /**
@@ -52,8 +54,14 @@ public static function fromSubscription(Subscription $sub): self
         if ($sub->stripe_status === 'past_due') {
             return self::PastDue;
         }
+        // unpaid は Inactive から分離する: 遮断の可否 (grantsAccess) は同じ false だが、
+        // 「支払いが未解決のまま契約が残っている」点で canceled と扱いが分かれる
+        // (hasUnsettledPayment 参照)。
+        if ($sub->stripe_status === 'unpaid') {
+            return self::Unpaid;
+        }
 
-        // trialing は試用期間として通す。それ以外の非 active 系 (canceled/unpaid/incomplete*) は Inactive。
+        // trialing は試用期間として通す。それ以外の非 active 系 (canceled/incomplete*) は Inactive。
         $activeStatuses = ['active', 'trialing'];
         if (! in_array($sub->stripe_status, $activeStatuses, true)) {
             return self::Inactive;
@@ -80,7 +88,29 @@ public function grantsAccess(): bool
     {
         return match ($this) {
             self::Active, self::UpgradeRecovery, self::PastDue => true,
-            self::Paused, self::Inactive => false,
+            self::Paused, self::Unpaid, self::Inactive => false,
+        };
+    }
+
+    /**
+     * 契約が終了しておらず、支払いが未解決のまま残っているか。
+     *
+     * true のとき、次の 2 つを**同時に**禁じる (同じ問いなので述語を 2 つに割らない):
+     *   1. 無料枠 (`free_plan_code='personal'`) への読み替え (BillingAccess::state)
+     *   2. 新規契約の開始 (SubscriptionService::startCheckout)
+     *
+     * **「未回収の債権が 1 円も無いこと」は基準にしない**。督促の末に Stripe が解約した
+     * (`canceled`) 契約には未払いの請求書が残りうるが、その回収は課金事業者側の債権管理であり、
+     * アプリの利用可否とは切り離す (切り離さないと未払い請求書を追い続ける仕組みが要る)。
+     */
+    public function hasUnsettledPayment(): bool
+    {
+        return match ($this) {
+            self::PastDue, self::Unpaid => true,
+            // Paused = trial 終了時にカードが無く read-only になった状態 (未払いの請求が無い)。
+            // Inactive = 終了済み / 初回決済が通らず不成立 (incomplete 系を含む)。
+            // Active / UpgradeRecovery = 支払い失敗が起きていない。
+            self::Active, self::UpgradeRecovery, self::Paused, self::Inactive => false,
         };
     }
 }
diff --git a/app/Exceptions/Billing/SubscriptionLookupFailedException.php b/app/Exceptions/Billing/SubscriptionLookupFailedException.php
new file mode 100644
index 0000000..797c58f
--- /dev/null
+++ b/app/Exceptions/Billing/SubscriptionLookupFailedException.php
@@ -0,0 +1,15 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Exceptions\Billing;
+
+use RuntimeException;
+
+/**
+ * Stripe の契約照会が失敗した (存在しないのではなく、確認できなかった)。
+ *
+ * gateway が Stripe SDK の例外を境界の外へ出さないための変換先。
+ * 「契約が無い」(= 404) は `null` 返却で表し、本例外は使わない。
+ */
+final class SubscriptionLookupFailedException extends RuntimeException {}
diff --git a/app/Http/Controllers/Onboarding/OnboardingController.php b/app/Http/Controllers/Onboarding/OnboardingController.php
index 6ce2401..cc13615 100644
--- a/app/Http/Controllers/Onboarding/OnboardingController.php
+++ b/app/Http/Controllers/Onboarding/OnboardingController.php
@@ -7,11 +7,13 @@
 use App\DataTransferObjects\Billing\PlanDto;
 use App\DataTransferObjects\Onboarding\OnboardingCheckoutDto;
 use App\Enums\Billing\SignupFundingChoice;
+use App\Enums\Billing\SubscriptionState;
 use App\Enums\Inquiry\InquirySource;
 use App\Enums\PlanCode;
 use App\Http\Concerns\ResolvesCurrentOrganization;
 use App\Http\Controllers\Controller;
 use App\Models\Billing\Plan;
+use App\Models\Billing\Subscription;
 use App\Models\Organization;
 use App\Models\User;
 use App\Services\Billing\AutoRechargeService;
@@ -67,6 +69,15 @@ public function show(Request $request): Response|RedirectResponse
             return new RedirectResponse(route('onboarding.billing-required'));
         }
 
+        // 支払いが未解決のまま契約が残っている組織は、プラン選択ではなく
+        // **支払い方法を更新できる画面** (課金画面 = Customer Portal への導線) へ逃がす。
+        // 判定は BillingAccess と同じ述語 1 つだけを見る (可否の再判定はしない)。
+        $subscription = $organization->subscription('default');
+        if ($subscription instanceof Subscription
+            && SubscriptionState::fromSubscription($subscription)->hasUnsettledPayment()) {
+            return new RedirectResponse(route('billing.index'));
+        }
+
         // ?plan= が来ていたら org-scoped に積み (Resolver 規約: 有効→put / 無効→forget)、
         // canonical URL へ 303 する (再読込・共有時に query が残らない)。
         // 不在なら session を破壊しない (= リロード耐性のため後段で peek する)。
diff --git a/app/Models/Billing/Subscription.php b/app/Models/Billing/Subscription.php
index 6ad2b9a..d0808ac 100644
--- a/app/Models/Billing/Subscription.php
+++ b/app/Models/Billing/Subscription.php
@@ -22,6 +22,9 @@
  *   (billing:reconcile-schedules が復旧する。ScheduleSetupStatus 参照)
  * - has_payment_method: 決済手段が登録済みか (monotonic snapshot。true から false へ戻さない)。
  *   SubscriptionService::deriveEntitlement が trial 終了後の遮断判定に使う
+ * - past_due_since: 支払い失敗 (stripe_status='past_due') を**観測した**時刻 =
+ *   支払い猶予の起点。期限の計算は PaymentGracePolicy が唯一の正本で、書込は
+ *   SubscriptionService に閉じる (PastDueSinceWriteInvariantTest)
  *
  * schedule 列は状態キーのため markSchedule* / clearSchedule 経由でのみ変更する。
  *
@@ -30,6 +33,7 @@
  * @property string $stripe_id
  * @property string $stripe_status
  * @property bool $has_payment_method
+ * @property Carbon|null $past_due_since
  * @property Carbon|null $current_period_end
  * @property string|null $stripe_schedule_id
  * @property ScheduleSetupStatus $schedule_setup_status
@@ -87,6 +91,7 @@ protected function casts(): array
     {
         return [
             'current_period_end' => 'datetime',
+            'past_due_since' => 'datetime',
             'has_payment_method' => 'boolean',
             'schedule_setup_status' => ScheduleSetupStatus::class,
         ];
diff --git a/app/Services/Billing/AccountDeletionBillingGuard.php b/app/Services/Billing/AccountDeletionBillingGuard.php
index 1170139..a873398 100644
--- a/app/Services/Billing/AccountDeletionBillingGuard.php
+++ b/app/Services/Billing/AccountDeletionBillingGuard.php
@@ -30,8 +30,8 @@ final class AccountDeletionBillingGuard
      *           (= Active / UpgradeRecovery / PastDue) かつ $sub->ends_at === null
      *           を満たす subscription 行が 1 つでも存在する
      *
-     * - `paused` / `canceled` / `unpaid` / `incomplete*` は Paused / Inactive に写像されて通過
-     *   (請求が発生しない or 終端)。
+     * - `paused` / `unpaid` / `canceled` / `incomplete*` は Paused / Unpaid / Inactive に
+     *   写像されて通過 (いずれも grantsAccess は false = 請求が発生しない or 終端)。
      * - `ends_at !== null` (= 期末解約予約済み / 終了済み) は通過。Stripe が自動終了させるため
      *   追加請求が発生せず、ここで止めると「解約したのに退会できない」詰みを作る。
      */
diff --git a/app/Services/Billing/BillingAccess.php b/app/Services/Billing/BillingAccess.php
index 72a8237..aa0efd1 100644
--- a/app/Services/Billing/BillingAccess.php
+++ b/app/Services/Billing/BillingAccess.php
@@ -5,6 +5,7 @@
 namespace App\Services\Billing;
 
 use App\Enums\Billing\OnboardingBillingState;
+use App\Enums\Billing\SubscriptionState;
 use App\Enums\CheckoutSessionStatus;
 use App\Models\Billing\BillingCheckoutSession;
 use App\Models\Billing\Subscription;
@@ -71,7 +72,13 @@ public function state(Organization $organization): OnboardingBillingState
         // canceled 等の過去行が残っていてもよい = paid→free 経路) とき free entitlement を見る。
         // 判定は定数比較 (未知値は fail-closed で通さない)。entitled subscription があれば上で
         // Subscribed 優先 (free と併存しない invariant)。
-        if ($organization->free_plan_code === PersonalPlanService::FREE_PLAN_CODE) {
+        // 支払いが未解決のまま契約が残っている間 (past_due / unpaid) は、無料枠の申告があっても
+        // 読み替えない (支払いに失敗した利用者が無料枠と同じ状態に落ちるのを防ぐ)。
+        // 契約が終了したあとは (未払いが残っていても) 無料枠へ戻る = 現行の解約→無料枠と同じ。
+        $unsettled = $sub instanceof Subscription
+            && SubscriptionState::fromSubscription($sub)->hasUnsettledPayment();
+
+        if (! $unsettled && $organization->free_plan_code === PersonalPlanService::FREE_PLAN_CODE) {
             return OnboardingBillingState::ActiveFreePlan;
         }
 
diff --git a/app/Services/Billing/CashierStripeGateway.php b/app/Services/Billing/CashierStripeGateway.php
index 56432aa..1948153 100644
--- a/app/Services/Billing/CashierStripeGateway.php
+++ b/app/Services/Billing/CashierStripeGateway.php
@@ -6,14 +6,17 @@
 
 use App\DataTransferObjects\Billing\CreatedCheckoutSession;
 use App\DataTransferObjects\Billing\ExternalBillingRedirect;
+use App\DataTransferObjects\Billing\RemoteSubscriptionState;
 use App\Enums\Billing\SubscriptionSwapOutcome;
 use App\Exceptions\Billing\PlanChangeFailedException;
+use App\Exceptions\Billing\SubscriptionLookupFailedException;
 use App\Models\Billing\Subscription;
 use App\Models\Organization;
 use App\Services\Billing\Contracts\StripeGatewayInterface;
 use Carbon\CarbonImmutable;
 use Laravel\Cashier\Cashier;
 use Stripe\Exception\ApiErrorException;
+use Stripe\Exception\InvalidRequestException;
 use Stripe\StripeClient;
 use Stripe\StripeObject;
 use Stripe\Subscription as StripeSubscription;
@@ -29,6 +32,10 @@
  */
 class CashierStripeGateway implements StripeGatewayInterface
 {
+    public function __construct(
+        private readonly SubscriptionSnapshotMapper $mapper,
+    ) {}
+
     /**
      * Stripe クライアント取得の seam (テストで差し替えるためだけに切り出す)。
      * 実装は Cashier の既定クライアントをそのまま返す。
@@ -195,6 +202,41 @@ public function createSubscriptionCheckout(
         );
     }
 
+    public function retrieveSubscriptionState(string $stripeSubscriptionId): ?RemoteSubscriptionState
+    {
+        Assert::stringNotEmpty($stripeSubscriptionId);
+
+        try {
+            $remote = $this->stripe()->subscriptions->retrieve(
+                $stripeSubscriptionId,
+                ['expand' => ['items.data']],
+            );
+        } catch (InvalidRequestException $e) {
+            // resource_missing = Stripe 側に無い。API キーの環境取り違えでも同じ形になるため、
+            // ここでは「無い」とだけ返し、状態変更するかどうかは呼び出し側が決める。
+            if ($e->getStripeCode() === 'resource_missing') {
+                return null;
+            }
+
+            throw new SubscriptionLookupFailedException('Stripe 契約の照会に失敗しました', previous: $e);
+        } catch (ApiErrorException $e) {
+            throw new SubscriptionLookupFailedException('Stripe 契約の照会に失敗しました', previous: $e);
+        }
+
+        // SDK 型はここで配列へ落とす (mapper へ SDK 型を漏らさない)。
+        $object = $remote->toArray();
+        $snapshot = $this->mapper->fromStripeSubscription($object);
+        if ($snapshot === null) {
+            // id が取れない応答は「確認できなかった」として扱う (状態を変える材料にしない)。
+            throw new SubscriptionLookupFailedException('Stripe 契約の応答から契約 id を取得できません');
+        }
+
+        return new RemoteSubscriptionState(
+            snapshot: $snapshot,
+            hasPaymentMethod: $this->mapper->observePaymentMethod($object),
+        );
+    }
+
     public function expireCheckoutSession(string $stripeSessionId): string
     {
         // 決済主体は organization だが expire は session id 単独で完結する
diff --git a/app/Services/Billing/Contracts/StripeGatewayInterface.php b/app/Services/Billing/Contracts/StripeGatewayInterface.php
index d4b7ba1..22a2f6f 100644
--- a/app/Services/Billing/Contracts/StripeGatewayInterface.php
+++ b/app/Services/Billing/Contracts/StripeGatewayInterface.php
@@ -6,8 +6,10 @@
 
 use App\DataTransferObjects\Billing\CreatedCheckoutSession;
 use App\DataTransferObjects\Billing\ExternalBillingRedirect;
+use App\DataTransferObjects\Billing\RemoteSubscriptionState;
 use App\Enums\Billing\SubscriptionSwapOutcome;
 use App\Exceptions\Billing\PlanChangeFailedException;
+use App\Exceptions\Billing\SubscriptionLookupFailedException;
 use App\Models\Organization;
 
 /**
@@ -63,6 +65,16 @@ public function swapSubscriptionPrices(
         string $idempotencyKey,
     ): SubscriptionSwapOutcome;
 
+    /**
+     * Stripe の契約 1 件を読み、突き合わせ用の観測結果を返す (日次リコンサイル専用の読み取り)。
+     *
+     * - 見つからない (404 / resource_missing) → **null** (状態を変えない材料として扱う)
+     * - API 障害 → SubscriptionLookupFailedException (SDK 例外は外へ出さない)
+     *
+     * @throws SubscriptionLookupFailedException 照会に失敗したとき
+     */
+    public function retrieveSubscriptionState(string $stripeSubscriptionId): ?RemoteSubscriptionState;
+
     /**
      * Stripe 側 Checkout Session を expire する (別 plan の live pending 整理)。
      *
diff --git a/app/Services/Billing/Fakes/FakeStripeGateway.php b/app/Services/Billing/Fakes/FakeStripeGateway.php
index e522bbe..e1a7054 100644
--- a/app/Services/Billing/Fakes/FakeStripeGateway.php
+++ b/app/Services/Billing/Fakes/FakeStripeGateway.php
@@ -6,6 +6,7 @@
 
 use App\DataTransferObjects\Billing\CreatedCheckoutSession;
 use App\DataTransferObjects\Billing\ExternalBillingRedirect;
+use App\DataTransferObjects\Billing\RemoteSubscriptionState;
 use App\Enums\Billing\SubscriptionSwapOutcome;
 use App\Models\Organization;
 use App\Services\Billing\Contracts\StripeGatewayInterface;
@@ -49,6 +50,12 @@ public function swapSubscriptionPrices(
         return SubscriptionSwapOutcome::Applied;
     }
 
+    public function retrieveSubscriptionState(string $stripeSubscriptionId): ?RemoteSubscriptionState
+    {
+        // 中立帰還: 契約状態の正本は BughuntBillingSeeder。突き合わせは何も収束させない。
+        return null;
+    }
+
     public function expireCheckoutSession(string $stripeSessionId): string
     {
         return 'expired';
diff --git a/app/Services/Billing/StripeWebhookProcessor.php b/app/Services/Billing/StripeWebhookProcessor.php
index fe5e5ff..23c6a0e 100644
--- a/app/Services/Billing/StripeWebhookProcessor.php
+++ b/app/Services/Billing/StripeWebhookProcessor.php
@@ -99,6 +99,7 @@ public function __construct(
         private readonly BillingNotificationDispatcher $notifications,
         private readonly SubscriptionService $subscriptions,
         private readonly AutoRechargeService $autoRecharge,
+        private readonly SubscriptionSnapshotMapper $snapshotMapper,
     ) {}
 
     public function handle(WebhookReceived $event): void
@@ -488,24 +489,15 @@ private function syncSubscriptionState(array $payload, HandledStripeWebhookEvent
             return;
         }
 
-        // sub id は subscription object 本体の必須フィールド。取れない payload は fail-closed
-        // (状態同期も signup grant も行わない)。
-        $stripeId = $this->stringAt($payload, 'data.object.id');
-        if ($stripeId === null) {
+        // 写像は SubscriptionSnapshotMapper が唯一の正本 (日次突き合わせと同じ規則で読む)。
+        // sub id は subscription object 本体の必須フィールドで、取れない payload では
+        // mapper が null を返す = fail-closed (状態同期も signup grant も行わない)。
+        $object = $this->subscriptionObject($payload);
+        $snapshot = $this->snapshotMapper->fromStripeSubscription($object);
+        if ($snapshot === null) {
             return;
         }
-
-        $snapshot = new SubscriptionSnapshot(
-            stripeId: $stripeId,
-            status: $this->stringAt($payload, 'data.object.status') ?? 'incomplete',
-            basePriceId: $this->stringAt($payload, 'data.object.items.data.0.price.id'),
-            baseQuantity: $this->intAt($payload, 'data.object.items.data.0.quantity'),
-            currentPeriodEnd: $this->periodEnd($payload),
-            trialEndsAt: $this->timestampToCarbon(data_get($payload, 'data.object.trial_end')),
-            endsAt: $this->timestampToCarbon(
-                data_get($payload, 'data.object.ended_at') ?? data_get($payload, 'data.object.cancel_at'),
-            ),
-        );
+        $stripeId = $snapshot->stripeId;
 
         $this->subscriptions->applySubscriptionSnapshot($organization, $snapshot, terminated: $terminated);
 
@@ -520,23 +512,26 @@ private function syncSubscriptionState(array $payload, HandledStripeWebhookEvent
 
         $subscription = Subscription::query()->where('stripe_id', $stripeId)->first();
         if ($subscription instanceof Subscription) {
+            // mapper の三値観測 (true / null = 観測できず) を現行の bool 契約へ落とす。
+            // monotonic writer 側が false を無視するため、`=== true` との合成で意味は同値。
             $this->subscriptions->recordPaymentMethodSnapshot(
                 $subscription,
-                $this->subscriptionHasPaymentMethod($payload),
+                $this->snapshotMapper->observePaymentMethod($object) === true,
             );
         }
     }
 
     /**
-     * subscription object が決済手段を持つか (default_payment_method / default_source)。
-     * Stripe は string id か expanded object のいずれも取り得るため union helper で抽出する。
+     * webhook payload から subscription object (data.object) を取り出す。
      *
      * @param  array<mixed>  $payload
+     * @return array<mixed>
      */
-    private function subscriptionHasPaymentMethod(array $payload): bool
+    private function subscriptionObject(array $payload): array
     {
-        return $this->resolveStripeIdField(data_get($payload, 'data.object.default_payment_method')) !== null
-            || $this->resolveStripeIdField(data_get($payload, 'data.object.default_source')) !== null;
+        $object = data_get($payload, 'data.object');
+
+        return is_array($object) ? $object : [];
     }
 
     /**
@@ -556,38 +551,6 @@ private function resolveStripeIdField(mixed $value): ?string
         return null;
     }
 
-    /**
-     * 次回更新日時 (renewal reminder = billing:send-billing-reminders の真実源)。
-     * 新 API (basil) は item 配下、旧 API は subscription top-level に持つため両系を fallback で拾う。
-     *
-     * @param  array<mixed>  $payload
-     */
-    private function periodEnd(array $payload): ?CarbonImmutable
-    {
-        return $this->timestampToCarbon(
-            data_get($payload, 'data.object.items.data.0.current_period_end')
-                ?? data_get($payload, 'data.object.current_period_end'),
-        );
-    }
-
-    /** Stripe の epoch 秒を CarbonImmutable にする (非 int / 非正数は null)。 */
-    private function timestampToCarbon(mixed $value): ?CarbonImmutable
-    {
-        return is_int($value) && $value > 0 ? CarbonImmutable::createFromTimestamp($value) : null;
-    }
-
-    /**
-     * payload から int 値を安全に取り出す (それ以外の型は null)。
-     *
-     * @param  array<mixed>  $payload
-     */
-    private function intAt(array $payload, string $path): ?int
-    {
-        $value = data_get($payload, $path);
-
-        return is_int($value) ? $value : null;
-    }
-
     /**
      * invoice.paid の振り分け。
      *
diff --git a/app/Services/Billing/SubscriptionService.php b/app/Services/Billing/SubscriptionService.php
index abd9c2f..4206122 100644
--- a/app/Services/Billing/SubscriptionService.php
+++ b/app/Services/Billing/SubscriptionService.php
@@ -30,7 +30,9 @@
 use App\Models\Organization;
 use App\Models\User;
 use App\Services\Billing\Contracts\StripeGatewayInterface;
+use App\Support\Billing\PaymentGracePolicy;
 use Carbon\CarbonImmutable;
+use DateTimeInterface;
 use Illuminate\Contracts\Cache\LockTimeoutException;
 use Illuminate\Database\Eloquent\Builder;
 use Illuminate\Database\QueryException;
@@ -56,6 +58,7 @@ class SubscriptionService
     public function __construct(
         private readonly StripeGatewayInterface $gateway,
         private readonly TicketLedgerService $tickets,
+        private readonly PaymentGracePolicy $grace,
     ) {}
 
     /**
@@ -105,11 +108,14 @@ public function grantSignupInitialTickets(Organization $org, string $stripeSubId
      *   entitled = state.grantsAccess()
      *              AND NOT (trial_ends_at <= now AND !has_payment_method)   // trial 終了 & カード無し
      *              AND status != paused                                     // Stripe 確定の read-only
+     *              AND NOT (state = PastDue AND past_due_since != null AND 猶予期限切れ)
      *
      * - Paused: grantsAccess=false で否定 (reason=Paused)。
      * - trial 終了 & PM 無し: webhook 前 (Stripe がまだ paused 化していない) でも先回りで否定する
      *   (reason=TrialEndedWithoutPaymentMethod)。
-     * - PastDue (PM 有): grantsAccess=true かつ trial 条件に該当しないため entitled=true (請求失敗中も利用継続)。
+     * - PastDue (PM 有): grantsAccess=true かつ trial 条件に該当しないため、**猶予の期限内は**
+     *   entitled=true (請求失敗中も利用継続)。猶予が切れたら否定する
+     *   (reason=PaymentGraceExpired。期限の正本は PaymentGracePolicy)。
      * - PM 無し past_due (= trial 後カード無し dunning): trial_ends_at<=now & !has_payment_method で否定。
      */
     public function deriveEntitlement(Subscription $sub): SubscriptionEntitlementDto
@@ -140,6 +146,21 @@ public function deriveEntitlement(Subscription $sub): SubscriptionEntitlementDto
             return SubscriptionEntitlementDto::denied($state, EntitlementDeniedReason::Paused);
         }
 
+        // 支払い失敗の猶予切れ (AG-035 (5))。**PastDue のときだけ**評価する
+        // (他の状態はここに到達する時点で猶予の対象ではない)。
+        //
+        // 起点が NULL のときは遮断しない: 打刻漏れという自分側の不具合をそのまま
+        // 支払い済み顧客の締め出しに変えないため。NULL が残る窓は
+        // (i) 単一 writer の打刻 (ii) 日次突き合わせの修復 (iii) 移行の backfill で有限にする。
+        if ($state === SubscriptionState::PastDue
+            && $sub->past_due_since !== null
+            && $this->grace->hasExpired(CarbonImmutable::instance($sub->past_due_since), $now)) {
+            return SubscriptionEntitlementDto::denied(
+                $state,
+                EntitlementDeniedReason::PaymentGraceExpired,
+            );
+        }
+
         return SubscriptionEntitlementDto::granted($state);
     }
 
@@ -182,6 +203,9 @@ public function applySubscriptionSnapshot(
                     'quantity' => $snap->baseQuantity,
                     'trial_ends_at' => $snap->trialEndsAt,
                     'ends_at' => $snap->endsAt,
+                    // 猶予の起点。**この 1 行が past_due_since の唯一の書込点**
+                    // (PastDueSinceWriteInvariantTest が app/ 内の書込を本ファイルに固定する)。
+                    'past_due_since' => $this->resolvePastDueSince($sub, $snap->status),
                 ];
 
                 // period 欠落 payload では既存の current_period_end を維持する (renewal reminder の
@@ -216,6 +240,86 @@ public function applySubscriptionSnapshot(
         });
     }
 
+    /**
+     * 猶予の起点を決める (打刻規則は 3 つだけ)。
+     *
+     *  - past_due を観測 かつ 既存値が NULL → **観測時刻を打つ**
+     *  - past_due を観測 かつ 既存値あり   → **上書きしない** (再送のたびに猶予を先送りしない)
+     *  - past_due 以外を観測               → **NULL に戻す** (復旧・終了で猶予は消える)
+     *
+     * 打刻するのは「観測時刻」であって Stripe 側で実際に失敗した時刻ではない
+     * (webhook を落としていれば日次突き合わせが観測した時刻になる = 利用者に有利な側へずれる)。
+     */
+    private function resolvePastDueSince(Subscription $sub, string $status): ?CarbonImmutable
+    {
+        if ($status !== 'past_due') {
+            return null;
+        }
+
+        $existing = $sub->past_due_since;
+
+        return $existing !== null
+            ? CarbonImmutable::instance($existing)
+            : CarbonImmutable::now();
+    }
+
+    /**
+     * 突き合わせで**書き込むべきか** (食い違いがあるか) を判定する。
+     *
+     * 差分が無いのに毎日 UPDATE すると、更新時刻だけが動き、webhook との競合窓も無駄に広がる。
+     * 比較対象は **`applySubscriptionSnapshot` が書く列すべて**にする (status だけを見ると、
+     * 更新日 `current_period_end` や解約予定 `ends_at` だけが変わった webhook を落としたとき
+     * 永久に収束しない = 更新予告の真実源がずれたまま固まる)。
+     *
+     * 収束が要るのは次のいずれか:
+     *   1. status が違う (両方向)
+     *   2. stripe_price / quantity / trial_ends_at / ends_at が違う
+     *   3. current_period_end が違う (**snapshot 側が null のときは比較しない** =
+     *      「period 欠落 payload では既存値を維持する」書込規則と同じ扱いにする)
+     *   4. past_due なのに猶予起点が NULL (打刻漏れの修復)
+     *   5. Stripe 側で決済手段を観測できたのにローカルが false (**true 方向のみ**)
+     *
+     * **`organizations.plan_code` は比較対象にしない**: 同一トランザクションで同期されるため
+     * subscriptions 行と食い違わない (未知 Price のときだけ据え置かれる = その回復は本経路の
+     * 責務ではない)。
+     *
+     * @param  bool|null  $hasPaymentMethod  null は「観測できなかった」(false と区別する)
+     */
+    public function needsSnapshotConvergence(
+        Subscription $sub,
+        SubscriptionSnapshot $snap,
+        ?bool $hasPaymentMethod,
+    ): bool {
+        if ($sub->stripe_status !== $snap->status
+            || $sub->stripe_price !== $snap->basePriceId
+            || $sub->quantity !== $snap->baseQuantity) {
+            return true;
+        }
+        if ($this->timesDiffer($sub->trial_ends_at, $snap->trialEndsAt)
+            || $this->timesDiffer($sub->ends_at, $snap->endsAt)) {
+            return true;
+        }
+        if ($snap->currentPeriodEnd !== null
+            && $this->timesDiffer($sub->current_period_end, $snap->currentPeriodEnd)) {
+            return true;
+        }
+        if ($snap->status === 'past_due' && $sub->past_due_since === null) {
+            return true;
+        }
+
+        return $hasPaymentMethod === true && ! $sub->has_payment_method;
+    }
+
+    /** 日時の差分判定 (null 同士は一致。片方だけ null は差分)。秒精度で比較する。 */
+    private function timesDiffer(?DateTimeInterface $local, ?CarbonImmutable $remote): bool
+    {
+        if ($local === null || $remote === null) {
+            return $local !== $remote;
+        }
+
+        return $local->getTimestamp() !== $remote->getTimestamp();
+    }
+
     /**
      * has_payment_method を subscription に記録する **独立 monotonic writer**。
      *
@@ -356,6 +460,16 @@ private function startCheckoutLocked(
             '既に有効なサブスクリプションがあります。プラン変更をご利用ください。'
         );
 
+        // 段 1b: 支払いが未解決のまま残っている契約 (past_due / unpaid) があるときは新規契約を作らない。
+        // Cashier の valid() は past_due / unpaid を false と見るため段 1 を素通りするが、
+        // Stripe 側の契約は生きており、ここで作ると **2 本目の契約 = 二重請求**になる。
+        // 利用者の次の一手は「新規契約」ではなく「支払い方法の更新」なので、そこへ案内する。
+        Assert::false(
+            $existing instanceof Subscription
+                && SubscriptionState::fromSubscription($existing)->hasUnsettledPayment(),
+            'お支払いが確認できていないご契約があります。お支払い方法の更新をお願いします。'
+        );
+
         // 段 2: 同 token 行 (intent=subscription_start スコープ)
         $sameAttempt = $this->subscriptionAttemptQuery($org)
             ->where('attempt_token', $attemptToken)
diff --git a/app/Services/Billing/SubscriptionSnapshotMapper.php b/app/Services/Billing/SubscriptionSnapshotMapper.php
new file mode 100644
index 0000000..e2fe775
--- /dev/null
+++ b/app/Services/Billing/SubscriptionSnapshotMapper.php
@@ -0,0 +1,102 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Services\Billing;
+
+use Carbon\CarbonImmutable;
+
+/**
+ * Stripe の subscription オブジェクト (連想配列) → SubscriptionSnapshot の **唯一の写像**。
+ *
+ * webhook (payload の data.object) と日次突き合わせ (SDK オブジェクトの toArray()) が
+ * 同じ規則で読むことを構造で保証する (写像が 2 つあると突き合わせ経路だけ別挙動になる)。
+ * **Stripe SDK の型は受け取らない** (配列だけを知る)。
+ */
+final class SubscriptionSnapshotMapper
+{
+    /**
+     * subscription オブジェクトから snapshot を組む。
+     *
+     * `id` が取れない応答は写像失敗として **null** を返す (呼び出し側が fail-closed に倒す)。
+     *
+     * @param  array<mixed>  $object  subscription オブジェクト (data.object 相当)
+     */
+    public function fromStripeSubscription(array $object): ?SubscriptionSnapshot
+    {
+        $stripeId = $this->stringAt($object, 'id');
+        if ($stripeId === null || $stripeId === '') {
+            return null;
+        }
+
+        return new SubscriptionSnapshot(
+            stripeId: $stripeId,
+            status: $this->stringAt($object, 'status') ?? 'incomplete',
+            basePriceId: $this->stringAt($object, 'items.data.0.price.id'),
+            baseQuantity: $this->intAt($object, 'items.data.0.quantity'),
+            // 次回更新日時: 新 API (basil) は item 配下、旧 API は top-level に持つため両系を拾う。
+            currentPeriodEnd: $this->timestampToCarbon(
+                data_get($object, 'items.data.0.current_period_end')
+                    ?? data_get($object, 'current_period_end'),
+            ),
+            trialEndsAt: $this->timestampToCarbon(data_get($object, 'trial_end')),
+            endsAt: $this->timestampToCarbon(
+                data_get($object, 'ended_at') ?? data_get($object, 'cancel_at'),
+            ),
+        );
+    }
+
+    /**
+     * 決済手段の観測 (三値)。**true と「観測できなかった」を潰さない**。
+     *  - default_payment_method / default_source のどちらかから id が取れた → true
+     *  - どちらも空 → null (顧客既定を使う契約もあるため false と断定しない)
+     *
+     * @param  array<mixed>  $object
+     */
+    public function observePaymentMethod(array $object): ?bool
+    {
+        $observed = $this->resolveStripeIdField(data_get($object, 'default_payment_method')) !== null
+            || $this->resolveStripeIdField(data_get($object, 'default_source')) !== null;
+
+        return $observed ? true : null;
+    }
+
+    /**
+     * Stripe の id フィールド (string id または expanded object) から id を取り出す。
+     */
+    private function resolveStripeIdField(mixed $value): ?string
+    {
+        if (is_string($value)) {
+            return $value !== '' ? $value : null;
+        }
+        if (is_array($value)) {
+            $id = $value['id'] ?? null;
+
+            return is_string($id) && $id !== '' ? $id : null;
+        }
+
+        return null;
+    }
+
+    /** Stripe の epoch 秒を CarbonImmutable にする (非 int / 非正数は null)。 */
+    private function timestampToCarbon(mixed $value): ?CarbonImmutable
+    {
+        return is_int($value) && $value > 0 ? CarbonImmutable::createFromTimestamp($value) : null;
+    }
+
+    /** @param array<mixed> $object */
+    private function stringAt(array $object, string $path): ?string
+    {
+        $value = data_get($object, $path);
+
+        return is_string($value) ? $value : null;
+    }
+
+    /** @param array<mixed> $object */
+    private function intAt(array $object, string $path): ?int
+    {
+        $value = data_get($object, $path);
+
+        return is_int($value) ? $value : null;
+    }
+}
diff --git a/app/Support/Billing/PaymentGracePolicy.php b/app/Support/Billing/PaymentGracePolicy.php
new file mode 100644
index 0000000..5e59ba5
--- /dev/null
+++ b/app/Support/Billing/PaymentGracePolicy.php
@@ -0,0 +1,47 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Support\Billing;
+
+use Carbon\CarbonImmutable;
+use Webmozart\Assert\Assert;
+
+/**
+ * 支払い失敗の猶予 (何日まで使わせるか) を判定する **唯一の正本**。
+ *
+ * 猶予日数を読む場所・期限を計算する場所をここ 1 つに閉じる (AG-035 (5))。
+ * 画面文言・通知・運用スクリプトが日数を再計算して食い違うことを防ぐ。
+ *
+ * **利用可否そのものは答えない**。可否の確定は SubscriptionService::deriveEntitlement の
+ * 一本道であり、本クラスは「起点からの期限が切れたか」だけを答える。
+ *
+ * **チケット残高切れの猶予は扱わない** (残高 0 は予約時点で即拒否 = 猶予 0 が標準形)。
+ */
+final class PaymentGracePolicy
+{
+    /** 猶予日数 (config の唯一の読み口)。負値は設定不備として落とす。 */
+    public function graceDays(): int
+    {
+        $days = config()->integer('billing.payment_grace_days');
+        Assert::greaterThanEq($days, 0, '支払い猶予日数には 0 以上を設定してください');
+
+        return $days;
+    }
+
+    /** 猶予の期限 (この時刻までは利用継続)。 */
+    public function expiresAt(CarbonImmutable $pastDueSince): CarbonImmutable
+    {
+        return $pastDueSince->addDays($this->graceDays());
+    }
+
+    /**
+     * 猶予が切れているか。
+     *
+     * **境界 (ちょうど期限の瞬間) は切れていない扱い**にする (利用者に有利な側へ倒す)。
+     */
+    public function hasExpired(CarbonImmutable $pastDueSince, CarbonImmutable $now): bool
+    {
+        return $now->greaterThan($this->expiresAt($pastDueSince));
+    }
+}
diff --git a/config/billing.php b/config/billing.php
index 5d4f934..df575ca 100644
--- a/config/billing.php
+++ b/config/billing.php
@@ -103,4 +103,22 @@
         'consent_version' => env('BILLING_AUTO_RECHARGE_CONSENT_VERSION', 'v2'),
     ],
 
+    /*
+    |----------------------------------------------------------------------
+    | 支払い失敗の猶予 (AG-035 (5))
+    |----------------------------------------------------------------------
+    |
+    | 支払い失敗 (Stripe status=past_due) を**観測してから**利用を止めるまでの猶予日数。
+    | 起点は subscriptions.past_due_since で、判定の唯一の読み口は
+    | App\Support\Billing\PaymentGracePolicy (ここ以外で config を読まない)。
+    |
+    | 0 は「観測した瞬間に止める」を意味する有効な設定値である (負値は設定不備として
+    | PaymentGracePolicy が例外で落とす)。
+    |
+    | **チケット残高切れには猶予を設けない** (残高 0 は予約時点で即拒否)。前払いチケットで
+    | 猶予を作ると「借金して使わせる」ことになるため。これは未実装ではなく決定である
+    | (docs/architecture.md にも記載)。
+    */
+    'payment_grace_days' => (int) env('BILLING_PAYMENT_GRACE_DAYS', 14),
+
 ];
diff --git a/database/migrations/2026_08_15_000200_add_past_due_since_to_subscriptions.php b/database/migrations/2026_08_15_000200_add_past_due_since_to_subscriptions.php
new file mode 100644
index 0000000..ad93099
--- /dev/null
+++ b/database/migrations/2026_08_15_000200_add_past_due_since_to_subscriptions.php
@@ -0,0 +1,32 @@
+<?php
+
+declare(strict_types=1);
+
+use Illuminate\Database\Migrations\Migration;
+use Illuminate\Database\Schema\Blueprint;
+use Illuminate\Support\Facades\Schema;
+
+/**
+ * past_due_since: 支払い失敗 (stripe_status='past_due') を**観測した**時刻。
+ *
+ * `PaymentGracePolicy` が猶予期限を計算する起点で、`SubscriptionService::deriveEntitlement`
+ * が猶予切れの遮断に使う。**Stripe 側で実際に失敗した時刻ではない** (webhook 欠落時は
+ * 日次突き合わせが観測した時刻になる)。書込は SubscriptionService に閉じる
+ * (PastDueSinceWriteInvariantTest)。既存行は分離した data migration が埋める。
+ */
+return new class extends Migration
+{
+    public function up(): void
+    {
+        Schema::table('subscriptions', function (Blueprint $table): void {
+            $table->timestamp('past_due_since')->nullable()->after('has_payment_method');
+        });
+    }
+
+    public function down(): void
+    {
+        Schema::table('subscriptions', function (Blueprint $table): void {
+            $table->dropColumn('past_due_since');
+        });
+    }
+};
diff --git a/database/migrations/2026_08_15_000210_backfill_past_due_since_on_subscriptions.php b/database/migrations/2026_08_15_000210_backfill_past_due_since_on_subscriptions.php
new file mode 100644
index 0000000..fb069b9
--- /dev/null
+++ b/database/migrations/2026_08_15_000210_backfill_past_due_since_on_subscriptions.php
@@ -0,0 +1,34 @@
+<?php
+
+declare(strict_types=1);
+
+use Carbon\CarbonImmutable;
+use Illuminate\Database\Migrations\Migration;
+use Illuminate\Support\Facades\DB;
+
+/**
+ * 既存の past_due 行の猶予起点を **migration 実行時刻** で埋める。
+ *
+ * 実際に失敗した時刻は復元できない (Stripe の請求履歴からの推定は移行のために外部 API を
+ * 叩くことになり、得られるのは数日の厳密さだけなので採らない)。よって「猶予はこのデプロイ時点
+ * から数え直す」という意味を持たせる = 移行と同時に既存利用者を遮断しない (遡って遮断すると
+ * 告知なしに突然止まる)。
+ *
+ * 冪等 (whereNull ガード)。down() は「どの行が migration 起因か」を識別できないため意図的に no-op。
+ * **手動 SQL / tinker でこの列を書かない** (書込の単一化は runbook にも明記する)。
+ */
+return new class extends Migration
+{
+    public function up(): void
+    {
+        DB::table('subscriptions')
+            ->where('stripe_status', 'past_due')
+            ->whereNull('past_due_since')
+            ->update(['past_due_since' => CarbonImmutable::now()]);
+    }
+
+    public function down(): void
+    {
+        // backfill の巻き戻しは「どの行が migration 起因か」を識別できないため意図的に no-op。
+    }
+};
diff --git a/routes/console.php b/routes/console.php
index b57e8dc..b146a98 100644
--- a/routes/console.php
+++ b/routes/console.php
@@ -75,6 +75,27 @@
 Schedule::command('billing:send-billing-reminders')->daily()->onOneServer()->withoutOverlapping();
 Schedule::command('billing:reconcile-schedules')->daily();
 
+/*
+|--------------------------------------------------------------------------
+| Stripe 契約状態の突き合わせ (AG-035 (6))
+|--------------------------------------------------------------------------
+| webhook 欠落でローカルの契約状態が固まると、支払い失敗の遮断も復旧も起きない。
+| 日次で Stripe を真実として収束させる。**チケット (金銭) には触れない**。
+|
+| 既存の 2 本とは書く列が重ならない (相乗りさせない):
+|   - billing:reconcile-auto-recharge (15 分) = チケット自動購入の未決金
+|   - billing:reconcile-schedules (日次)      = 予約 (Schedule) の作りかけ
+|
+| **監視対象**: 終了コードと report() (未確認・失敗はここにしか出ない)。
+*/
+Schedule::command('billing:reconcile-subscription-status')
+    ->daily()
+    ->onOneServer()
+    ->withoutOverlapping()
+    ->onFailure(static fn () => report(new RuntimeException(
+        'billing:reconcile-subscription-status 失敗 — Stripe と契約状態が突き合わせられていない',
+    )));
+
 /*
 |--------------------------------------------------------------------------
 | 課金孤児の検知 (退会ガードの second layer)
diff --git a/tests/Architecture/CachePayloadPlainDataGateTest.php b/tests/Architecture/CachePayloadPlainDataGateTest.php
index bfb06a2..ce0fd69 100644
--- a/tests/Architecture/CachePayloadPlainDataGateTest.php
+++ b/tests/Architecture/CachePayloadPlainDataGateTest.php
@@ -190,6 +190,10 @@
         'role' => 'lock-only',
         'rationale' => '突合コマンドの多重起動を Cache::lock で抑止するのみ。payload は書かない',
     ],
+    'app/Console/Commands/Billing/ReconcileSubscriptionStatus.php' => [
+        'role' => 'lock-only',
+        'rationale' => 'Stripe 契約状態の突き合わせの多重起動を Cache::lock で抑止するのみ。payload は書かない',
+    ],
     'app/Services/Billing/AutoRechargeService.php' => [
         'role' => 'lock-only',
         'rationale' => 'org 単位のオートリチャージ排他に Cache::lock を使うのみ。payload は一切書かない',
@@ -206,6 +210,10 @@
         'role' => 'write',
         'rationale' => 'FX レートの当日 cache。素の配列を put し、読み戻しで DTO へ組み立て直す唯一の経路',
     ],
+    'tests/Feature/Billing/ReconcileSubscriptionStatusTest.php' => [
+        'role' => 'lock-only',
+        'rationale' => '突き合わせコマンドの多重起動を再現するため Cache::lock を先取するのみ。payload は書かない',
+    ],
 ];
 
 /**
diff --git a/tests/Architecture/EntitlementReasonExposureTest.php b/tests/Architecture/EntitlementReasonExposureTest.php
new file mode 100644
index 0000000..6abd23b
--- /dev/null
+++ b/tests/Architecture/EntitlementReasonExposureTest.php
@@ -0,0 +1,70 @@
+<?php
+
+declare(strict_types=1);
+
+use Symfony\Component\Finder\Finder;
+
+/*
+|--------------------------------------------------------------------------
+| entitlement の否定理由が画面 props に露出していないことの固定
+|--------------------------------------------------------------------------
+|
+| **これは恒久の禁止ではなく現時点の設計判断の固定である**。露出させるときは本テストの契約を
+| 変え、TypeScript の union と表示テストを同時に足すこと。
+|
+| 現状、遮断時の文言は RequireActiveSubscription::BLOCKED_MESSAGE と着地ページが持っており、
+| EntitlementDeniedReason / SubscriptionEntitlementDto は app/Services/Billing 配下だけで
+| 使われている。理由を props に足すと画面が理由別の出し分けを持つことになり、
+| TypeScript の union と表示の網羅が要る (足さないまま露出すると未知値で画面が壊れる)。
+|
+| **保証範囲を誇張しない**: 走査するのは app/Http/ と resources/js/ の 2 根だけで、
+| 別経路 (Console command の出力 / 通知本文) には沈黙する。
+*/
+
+/**
+ * 指定ディレクトリ配下で語を含むファイルの相対パス一覧。
+ *
+ * @param  list<string>  $needles
+ * @return list<string>
+ */
+function entitlementReasonHits(string $relativeRoot, array $needles): array
+{
+    $absolute = base_path($relativeRoot);
+    if (! is_dir($absolute)) {
+        return [];
+    }
+
+    $finder = Finder::create()->in($absolute)->files();
+    foreach ($needles as $needle) {
+        $finder->contains($needle);
+    }
+
+    $hits = [];
+    foreach ($finder as $file) {
+        $hits[] = str_replace(base_path().'/', '', $file->getPathname());
+    }
+    sort($hits);
+
+    return $hits;
+}
+
+test('EntitlementDeniedReason は app/Http/ に現れない (props へ出していない)', function (): void {
+    expect(entitlementReasonHits('app/Http', ['EntitlementDeniedReason']))->toBe([]);
+});
+
+test('SubscriptionEntitlementDto は app/Http/ に現れない (DTO をそのまま props にしていない)', function (): void {
+    expect(entitlementReasonHits('app/Http', ['SubscriptionEntitlementDto']))->toBe([]);
+});
+
+test('否定理由の値は resources/js/ に現れない (画面が理由別の出し分けを持っていない)', function (string $needle): void {
+    expect(entitlementReasonHits('resources/js', [$needle]))->toBe([]);
+})->with([
+    'EntitlementDeniedReason',
+    'payment_grace_expired',
+    'trial_ended_without_payment_method',
+]);
+
+test('負のコントロール: app/Services/Billing/ では検出される (走査が空振りしていない)', function (): void {
+    expect(entitlementReasonHits('app/Services/Billing', ['EntitlementDeniedReason']))
+        ->toContain('app/Services/Billing/SubscriptionService.php');
+});
diff --git a/tests/Architecture/PastDueSinceWriteInvariantTest.php b/tests/Architecture/PastDueSinceWriteInvariantTest.php
new file mode 100644
index 0000000..ab50e98
--- /dev/null
+++ b/tests/Architecture/PastDueSinceWriteInvariantTest.php
@@ -0,0 +1,193 @@
+<?php
+
+declare(strict_types=1);
+
+use Tests\Support\PhpReferenceScanner;
+
+/*
+|--------------------------------------------------------------------------
+| past_due_since 書き込み経路の invariant
+|--------------------------------------------------------------------------
+|
+| `subscriptions.past_due_since` は猶予の起点 = 遮断の期日を決める状態キーのため、
+| 書き込み (array key 代入 / プロパティ代入) は SubscriptionService に閉じる。
+| 読み取り (`->past_due_since` の比較・null 検査) は対象外。
+|
+| model の `casts()` にある `'past_due_since' => 'datetime',` は**型宣言であって書き込みではない**
+| ため免除するが、免除は「casts() メソッドの本体の行範囲に入っている cast 宣言」に限る
+| (文字列一致だけで免除すると model 内の forceFill(['past_due_since' => …]) を見逃す)。
+|
+| **保証範囲を誇張しない**: 走査根は app/ のみで、database/migrations/ の backfill と
+| 生 SQL は母集団に入らない (移行は 1 本きりで、手動 SQL の禁止は runbook が担う)。
+| ファイル粒度の検査であり、許可ファイル内でのメソッド追加は検出しない
+| (メソッド単位の fail-first は SubscriptionSnapshotSyncTest が担う)。
+*/
+
+/**
+ * 1 ファイル分の `past_due_since` 書き込み違反行を返す。
+ *
+ * 違反ではないのは次の 2 つだけ:
+ *   - docblock 行 (行頭が `*`)
+ *   - `casts()` メソッド本体の行範囲にある cast 宣言 (`'past_due_since' => 'datetime',`)
+ *
+ * @return list<int> 違反した行番号
+ */
+function pastDueSinceWriteViolations(string $phpSource): array
+{
+    $castLines = pastDueSinceCastsBodyLines($phpSource);
+
+    $violations = [];
+    foreach (explode("\n", $phpSource) as $index => $line) {
+        $lineNumber = $index + 1;
+        if (! str_contains($line, 'past_due_since')) {
+            continue;
+        }
+        // 書き込みの形 (array key 代入 / プロパティ代入)。比較 (=== / !==) は対象外。
+        if (preg_match('/([\'"])past_due_since\1\s*=>|->past_due_since\s*=[^=]/', $line) !== 1) {
+            continue;
+        }
+        if (str_starts_with(ltrim($line), '*')) {
+            continue;
+        }
+        if (in_array($lineNumber, $castLines, true)
+            && preg_match('/^([\'"])past_due_since\1\s*=>\s*([\'"])datetime\2,?$/', trim($line)) === 1) {
+            continue;
+        }
+
+        $violations[] = $lineNumber;
+    }
+
+    return $violations;
+}
+
+/**
+ * `casts()` メソッド本体の行範囲 (波括弧の深さで確定する。文字列一致で判定しない)。
+ *
+ * @return list<int>
+ */
+function pastDueSinceCastsBodyLines(string $phpSource): array
+{
+    $tokens = PhpReferenceScanner::tokens($phpSource);
+    $count = count($tokens);
+
+    for ($i = 0; $i < $count; $i++) {
+        if ($tokens[$i]['id'] !== T_FUNCTION) {
+            continue;
+        }
+        $name = $tokens[$i + 1] ?? null;
+        if ($name === null || $name['text'] !== 'casts') {
+            continue;
+        }
+
+        // 宣言の後、本体の開き `{` を探す (戻り値型・引数リストの括弧は素通しする)。
+        $depth = 0;
+        for ($j = $i + 2; $j < $count; $j++) {
+            $text = $tokens[$j]['text'];
+            if ($text === '{') {
+                $depth++;
+
+                continue;
+            }
+            if ($text !== '}') {
+                continue;
+            }
+            $depth--;
+            if ($depth === 0) {
+                return range($tokens[$i]['line'], $tokens[$j]['line']);
+            }
+        }
+    }
+
+    return [];
+}
+
+test('app/ 内の past_due_since 書き込みは SubscriptionService に閉じる', function (): void {
+    $allowlist = [
+        'app/Services/Billing/SubscriptionService.php',
+    ];
+
+    $violations = [];
+    foreach (PhpReferenceScanner::phpFiles(base_path('app'), 'app') as $relative => $source) {
+        if (in_array($relative, $allowlist, true)) {
+            continue;
+        }
+        foreach (pastDueSinceWriteViolations($source) as $line) {
+            $violations[] = $relative.':'.$line;
+        }
+    }
+
+    expect($violations)->toBe([], 'past_due_since の書き込みは SubscriptionService 経由に限定してください: '.implode(', ', $violations));
+});
+
+test('負のコントロール: 単一 writer 自身は書き込みとして検出される', function (): void {
+    $source = (string) file_get_contents(base_path('app/Services/Billing/SubscriptionService.php'));
+
+    expect(pastDueSinceWriteViolations($source))->not->toBe([]);
+});
+
+test('負のコントロール: cast 以外の array key 代入は違反として拾われる', function (): void {
+    $source = <<<'PHP'
+    <?php
+    class Example
+    {
+        public function write(): void
+        {
+            $this->sub->forceFill(['past_due_since' => CarbonImmutable::now()])->save();
+        }
+    }
+    PHP;
+
+    expect(pastDueSinceWriteViolations($source))->toHaveCount(1);
+});
+
+test('負のコントロール: casts() の外にある cast と同じ文字列は免除されない', function (): void {
+    $source = <<<'PHP'
+    <?php
+    class Example
+    {
+        public function write(): void
+        {
+            $this->sub->forceFill(['past_due_since' => 'datetime']);
+        }
+
+        protected function casts(): array
+        {
+            return ['current_period_end' => 'datetime'];
+        }
+    }
+    PHP;
+
+    expect(pastDueSinceWriteViolations($source))->toHaveCount(1);
+});
+
+test('負のコントロール: casts() の中の cast 宣言は免除される', function (): void {
+    $source = <<<'PHP'
+    <?php
+    class Example
+    {
+        protected function casts(): array
+        {
+            return [
+                'past_due_since' => 'datetime',
+            ];
+        }
+    }
+    PHP;
+
+    expect(pastDueSinceWriteViolations($source))->toBe([]);
+});
+
+test('読み取り (比較・null 検査) は違反にならない', function (): void {
+    $source = <<<'PHP'
+    <?php
+    class Example
+    {
+        public function read(): bool
+        {
+            return $this->sub->past_due_since !== null && $this->sub->past_due_since === $other;
+        }
+    }
+    PHP;
+
+    expect(pastDueSinceWriteViolations($source))->toBe([]);
+});
diff --git a/tests/Feature/Billing/BillingAccessStateTest.php b/tests/Feature/Billing/BillingAccessStateTest.php
index b941007..4230b71 100644
--- a/tests/Feature/Billing/BillingAccessStateTest.php
+++ b/tests/Feature/Billing/BillingAccessStateTest.php
@@ -291,3 +291,53 @@ function cohortBillingAccess(): BillingAccess
         ->and($after->status)->toBe($before->status)
         ->and($after->updated_at?->toIso8601String())->toBe($before->updated_at?->toIso8601String());
 });
+
+// ── 支払い未解決の間は無料枠へ読み替えない (AG-035 (3)) ──
+//
+// 無料枠の申告 (free_plan_code='personal') があっても、契約が終了しておらず支払いが
+// 未解決 (past_due / unpaid) の間は ActiveFreePlan に落とさない。契約が終了したあとは
+// 未払いが残っていても無料枠へ戻る (現行の解約 → 無料枠と同じ)。
+
+/** 無料枠の申告を持つ組織 (grandfather 相当)。 */
+function freeDeclaredOrganization(): Organization
+{
+    [$organization] = createOrganizationWithOwner();
+
+    return $organization;
+}
+
+test('無料枠申告 + past_due 猶予切れは ExpiredCheckout (無料枠へすり抜けない)', function (): void {
+    config()->set('billing.payment_grace_days', 14);
+    $organization = freeDeclaredOrganization();
+    $subscription = cohortSubscription($organization, status: 'past_due');
+    $subscription->forceFill(['past_due_since' => CarbonImmutable::now()->subDays(15)])->save();
+
+    expect(cohortBillingAccess()->state($organization))->toBe(OnboardingBillingState::ExpiredCheckout)
+        ->and(cohortBillingAccess()->hasActiveAccess($organization))->toBeFalse();
+});
+
+test('無料枠申告 + past_due 猶予中は Subscribed (entitled が先に立つ)', function (): void {
+    config()->set('billing.payment_grace_days', 14);
+    $organization = freeDeclaredOrganization();
+    $subscription = cohortSubscription($organization, status: 'past_due');
+    $subscription->forceFill(['past_due_since' => CarbonImmutable::now()->subDays(3)])->save();
+
+    expect(cohortBillingAccess()->state($organization))->toBe(OnboardingBillingState::Subscribed)
+        ->and(cohortBillingAccess()->hasActiveAccess($organization))->toBeTrue();
+});
+
+test('無料枠申告 + unpaid は ExpiredCheckout', function (): void {
+    $organization = freeDeclaredOrganization();
+    cohortSubscription($organization, status: 'unpaid');
+
+    expect(cohortBillingAccess()->state($organization))->toBe(OnboardingBillingState::ExpiredCheckout)
+        ->and(cohortBillingAccess()->hasActiveAccess($organization))->toBeFalse();
+});
+
+test('無料枠申告 + 契約終了・不成立・paused は ActiveFreePlan のまま (既存の paid→free 経路)', function (string $status): void {
+    $organization = freeDeclaredOrganization();
+    cohortSubscription($organization, status: $status);
+
+    expect(cohortBillingAccess()->state($organization))->toBe(OnboardingBillingState::ActiveFreePlan)
+        ->and(cohortBillingAccess()->hasActiveAccess($organization))->toBeTrue();
+})->with(['canceled', 'incomplete', 'incomplete_expired', 'paused']);
diff --git a/tests/Feature/Billing/PaidValueNotGrantedBeforePaymentTest.php b/tests/Feature/Billing/PaidValueNotGrantedBeforePaymentTest.php
new file mode 100644
index 0000000..9d0cd6d
--- /dev/null
+++ b/tests/Feature/Billing/PaidValueNotGrantedBeforePaymentTest.php
@@ -0,0 +1,103 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Models\Billing\TicketLedgerEntry;
+use App\Models\Organization;
+use App\Services\Billing\PersonalPlanService;
+use App\Services\Billing\SubscriptionService;
+use Carbon\CarbonImmutable;
+use Laravel\Cashier\Events\WebhookReceived;
+
+/*
+ * 「有料の価値は支払いが確定する前に渡さない」という前提の固定。
+ *
+ * 支払い未解決 (past_due / unpaid) の間だけ無料枠への読み替えと新規契約を禁じる設計は、
+ * **契約が成立しただけでは価値が出ない**ことに依っている。ここが崩れると
+ * 「incomplete のまま価値だけ取る」経路が生まれるため、次の 3 つを固定する:
+ *   - incomplete (カード認証待ち) の契約は entitled にならない
+ *   - 契約作成 (customer.subscription.created) で付くのは組織生涯 1 回の無償 signup grant だけで、
+ *     月次付与は起きない (月次付与の契機は invoice.paid のみ)
+ *   - 既に無料申告で signup grant 済みの組織では、契約作成でチケットが増えない
+ */
+
+/** Stripe customer を持つ未契約組織。 */
+function paidValueOrganization(string $stripeId): Organization
+{
+    [$organization] = createOrganizationWithOwner('テスト組織', grandfatherFreePlan: false);
+    $organization->stripe_id = $stripeId;
+    $organization->save();
+
+    return $organization;
+}
+
+/**
+ * @return array<string, mixed>
+ */
+function paidValueSubscriptionCreatedPayload(string $eventId, string $stripeId, string $stripeSubId): array
+{
+    return [
+        'id' => $eventId,
+        'type' => 'customer.subscription.created',
+        'data' => [
+            'object' => [
+                'id' => $stripeSubId,
+                'customer' => $stripeId,
+                'status' => 'incomplete',
+            ],
+        ],
+    ];
+}
+
+test('incomplete の契約は entitled にならない (カード認証待ちで価値を渡さない)', function (): void {
+    $organization = paidValueOrganization('cus_paid_value_1');
+    $subscription = createFakeSubscription($organization, status: 'incomplete');
+
+    expect(app(SubscriptionService::class)->deriveEntitlement($subscription)->entitled)->toBeFalse();
+});
+
+test('契約作成で付くのは signup grant 1 件だけ (月次付与は起きない)', function (): void {
+    $organization = paidValueOrganization('cus_paid_value_2');
+
+    event(new WebhookReceived(paidValueSubscriptionCreatedPayload(
+        'evt_paid_value_2', 'cus_paid_value_2', 'sub_paid_value_2',
+    )));
+
+    $entries = TicketLedgerEntry::query()->where('organization_id', $organization->getKey())->get();
+    expect($entries)->toHaveCount(1)
+        ->and($entries->firstOrFail()->idempotency_key)->toBe('signup_grant:sub_paid_value_2');
+});
+
+test('無料申告で signup grant 済みの組織は契約作成でチケットが増えない', function (): void {
+    $organization = paidValueOrganization('cus_paid_value_3');
+    $owner = $organization->users()->firstOrFail();
+    app(PersonalPlanService::class)->activate($organization, $owner);
+
+    $before = TicketLedgerEntry::query()->where('organization_id', $organization->getKey())->count();
+    expect($before)->toBe(1);
+
+    event(new WebhookReceived(paidValueSubscriptionCreatedPayload(
+        'evt_paid_value_3', 'cus_paid_value_3', 'sub_paid_value_3',
+    )));
+
+    expect(TicketLedgerEntry::query()->where('organization_id', $organization->getKey())->count())
+        ->toBe($before)
+        ->and($organization->fresh()?->signup_tickets_granted_at)->not->toBeNull();
+});
+
+test('signup grant の marker は再契約でも再付与を許さない', function (): void {
+    $organization = paidValueOrganization('cus_paid_value_4');
+
+    event(new WebhookReceived(paidValueSubscriptionCreatedPayload(
+        'evt_paid_value_4a', 'cus_paid_value_4', 'sub_paid_value_4a',
+    )));
+    $granted = $organization->fresh()?->signup_tickets_granted_at;
+    expect($granted)->not->toBeNull();
+
+    $this->travelTo(CarbonImmutable::now()->addMonthNoOverflow());
+    event(new WebhookReceived(paidValueSubscriptionCreatedPayload(
+        'evt_paid_value_4b', 'cus_paid_value_4', 'sub_paid_value_4b',
+    )));
+
+    expect(TicketLedgerEntry::query()->where('organization_id', $organization->getKey())->count())->toBe(1);
+});
diff --git a/tests/Feature/Billing/PaymentGraceMigrationTest.php b/tests/Feature/Billing/PaymentGraceMigrationTest.php
new file mode 100644
index 0000000..b41c7a6
--- /dev/null
+++ b/tests/Feature/Billing/PaymentGraceMigrationTest.php
@@ -0,0 +1,75 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Models\Organization;
+use Carbon\CarbonImmutable;
+use Illuminate\Database\Migrations\Migration;
+use Illuminate\Support\Facades\DB;
+
+/*
+ * past_due_since の移行安全性。
+ *
+ * 既存の past_due 行は「実際に失敗した時刻」を復元できないため、backfill は migration 実行時刻を
+ * 起点として打つ = 移行と同時に既存利用者を遮断しない (遡って遮断すると告知なしに突然止まる)。
+ * 既に起点がある行は上書きしない (再実行で猶予が先送りされない)。
+ */
+
+function runPastDueSinceBackfill(): void
+{
+    $migration = require database_path(
+        'migrations/2026_08_15_000210_backfill_past_due_since_on_subscriptions.php'
+    );
+    expect($migration)->toBeInstanceOf(Migration::class);
+    $migration->up();
+}
+
+/** past_due_since を直接指定した subscription 行 (列既定は NULL)。 */
+function subscriptionWithPastDueSince(string $status, ?CarbonImmutable $since): int
+{
+    $organization = Organization::factory()->create();
+    $subscription = createFakeSubscription($organization, status: $status);
+    DB::table('subscriptions')->where('id', $subscription->getKey())->update([
+        'past_due_since' => $since,
+    ]);
+
+    return $subscription->getKey();
+}
+
+test('past_due_since の列既定は NULL', function (): void {
+    $organization = Organization::factory()->create();
+    $subscription = createFakeSubscription($organization, status: 'past_due');
+
+    expect($subscription->fresh()?->past_due_since)->toBeNull();
+});
+
+test('backfill は起点なしの past_due 行を実行時刻で埋める', function (): void {
+    $id = subscriptionWithPastDueSince('past_due', null);
+
+    $this->travelTo(CarbonImmutable::parse('2026-08-15 12:00:00'));
+    runPastDueSinceBackfill();
+
+    $filled = DB::table('subscriptions')->where('id', $id)->value('past_due_since');
+    expect($filled)->not->toBeNull()
+        ->and(CarbonImmutable::parse((string) $filled)->toDateTimeString())->toBe('2026-08-15 12:00:00');
+});
+
+test('backfill は past_due 以外の行には触れない', function (string $status): void {
+    $id = subscriptionWithPastDueSince($status, null);
+
+    runPastDueSinceBackfill();
+
+    expect(DB::table('subscriptions')->where('id', $id)->value('past_due_since'))->toBeNull();
+})->with(['active', 'trialing', 'unpaid', 'canceled', 'paused']);
+
+test('backfill は既に起点がある行を上書きしない (再実行で猶予が先送りされない)', function (): void {
+    $existing = CarbonImmutable::parse('2026-08-01 09:00:00');
+    $id = subscriptionWithPastDueSince('past_due', $existing);
+
+    $this->travelTo(CarbonImmutable::parse('2026-08-15 12:00:00'));
+    runPastDueSinceBackfill();
+    runPastDueSinceBackfill();
+
+    $value = DB::table('subscriptions')->where('id', $id)->value('past_due_since');
+    expect(CarbonImmutable::parse((string) $value)->toDateTimeString())->toBe('2026-08-01 09:00:00');
+});
diff --git a/tests/Feature/Billing/PaymentGracePolicyTest.php b/tests/Feature/Billing/PaymentGracePolicyTest.php
new file mode 100644
index 0000000..0909fb1
--- /dev/null
+++ b/tests/Feature/Billing/PaymentGracePolicyTest.php
@@ -0,0 +1,67 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Support\Billing\PaymentGracePolicy;
+use Carbon\CarbonImmutable;
+use Webmozart\Assert\InvalidArgumentException;
+
+/*
+ * PaymentGracePolicy — 支払い失敗の猶予期限を決める唯一の正本。
+ *
+ * 猶予日数は config('billing.payment_grace_days') だけを読み、境界 (期限ちょうど) は
+ * 「切れていない」側に倒す (利用者に有利な側)。
+ */
+
+function gracePolicy(): PaymentGracePolicy
+{
+    return app(PaymentGracePolicy::class);
+}
+
+test('graceDays は config を読む (再計算しない)', function (): void {
+    config()->set('billing.payment_grace_days', 7);
+
+    expect(gracePolicy()->graceDays())->toBe(7);
+});
+
+test('負の猶予日数は設定不備として例外で落とす', function (): void {
+    config()->set('billing.payment_grace_days', -1);
+
+    gracePolicy()->graceDays();
+})->throws(InvalidArgumentException::class);
+
+test('expiresAt は起点 + 猶予日数', function (): void {
+    config()->set('billing.payment_grace_days', 14);
+    $since = CarbonImmutable::parse('2026-08-01 09:00:00');
+
+    expect(gracePolicy()->expiresAt($since)->toDateTimeString())->toBe('2026-08-15 09:00:00');
+});
+
+test('猶予中 (起点当日 / 13 日後) は切れていない', function (int $days): void {
+    config()->set('billing.payment_grace_days', 14);
+    $since = CarbonImmutable::parse('2026-08-01 09:00:00');
+
+    expect(gracePolicy()->hasExpired($since, $since->addDays($days)))->toBeFalse();
+})->with([0, 13]);
+
+test('期限ちょうどは切れていない扱い (境界は利用者に有利な側へ倒す)', function (): void {
+    config()->set('billing.payment_grace_days', 14);
+    $since = CarbonImmutable::parse('2026-08-01 09:00:00');
+
+    expect(gracePolicy()->hasExpired($since, $since->addDays(14)))->toBeFalse();
+});
+
+test('期限の 1 秒後から切れている', function (): void {
+    config()->set('billing.payment_grace_days', 14);
+    $since = CarbonImmutable::parse('2026-08-01 09:00:00');
+
+    expect(gracePolicy()->hasExpired($since, $since->addDays(14)->addSecond()))->toBeTrue();
+});
+
+test('猶予 0 日の設定では起点の 1 秒後に切れる (即時遮断できる)', function (): void {
+    config()->set('billing.payment_grace_days', 0);
+    $since = CarbonImmutable::parse('2026-08-01 09:00:00');
+
+    expect(gracePolicy()->hasExpired($since, $since))->toBeFalse()
+        ->and(gracePolicy()->hasExpired($since, $since->addSecond()))->toBeTrue();
+});
diff --git a/tests/Feature/Billing/ReconcileSubscriptionStatusTest.php b/tests/Feature/Billing/ReconcileSubscriptionStatusTest.php
new file mode 100644
index 0000000..8cd6e79
--- /dev/null
+++ b/tests/Feature/Billing/ReconcileSubscriptionStatusTest.php
@@ -0,0 +1,316 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Console\Commands\Billing\ReconcileSubscriptionStatus;
+use App\DataTransferObjects\Billing\RemoteSubscriptionState;
+use App\Models\Billing\Subscription;
+use App\Models\Billing\TicketLedgerEntry;
+use App\Models\Organization;
+use App\Services\Billing\Contracts\StripeGatewayInterface;
+use App\Services\Billing\SubscriptionSnapshot;
+use App\Support\ExternalClientTimeouts;
+use Carbon\CarbonImmutable;
+use Illuminate\Console\Scheduling\Event;
+use Illuminate\Console\Scheduling\Schedule;
+use Illuminate\Contracts\Debug\ExceptionHandler;
+use Illuminate\Support\Facades\Cache;
+use Illuminate\Support\Facades\DB;
+use Mockery\MockInterface;
+use Tests\Support\FakeStripeGateway;
+
+/*
+ * billing:reconcile-subscription-status — Stripe を真実として契約状態を収束させる日次バッチ。
+ *
+ * 責務は「applySubscriptionSnapshot が書く列」だけで、金銭 (チケット) には触れない。
+ * 未確認 (404) は状態を変えずに報告し、照会失敗は FAILURE で終わる。
+ */
+
+/** fake gateway を bind し、spy を返す。 */
+function reconcileGateway(): FakeStripeGateway
+{
+    $gateway = new FakeStripeGateway;
+    app()->instance(StripeGatewayInterface::class, $gateway);
+
+    return $gateway;
+}
+
+/** report() 経路 (運用アラート) を観測する spy を差し込む。 */
+function reconcileHandlerSpy(): MockInterface
+{
+    $handler = Mockery::spy(ExceptionHandler::class);
+    app()->instance(ExceptionHandler::class, $handler);
+
+    return $handler;
+}
+
+/** 突き合わせ対象の契約 1 件 (Stripe には到達しない)。 */
+function reconcileSubscription(string $status = 'active', ?CarbonImmutable $pastDueSince = null): Subscription
+{
+    $organization = Organization::factory()->create();
+    $subscription = createFakeSubscription($organization, status: $status);
+    $subscription->forceFill(['past_due_since' => $pastDueSince])->save();
+
+    return $subscription;
+}
+
+/** ローカル行と同じ形の remote 観測 (差分なし) を作る。 */
+function reconcileRemote(
+    Subscription $sub,
+    ?string $status = null,
+    ?string $basePriceId = null,
+    ?int $quantity = 1,
+    ?CarbonImmutable $currentPeriodEnd = null,
+    ?CarbonImmutable $trialEndsAt = null,
+    ?CarbonImmutable $endsAt = null,
+    ?bool $hasPaymentMethod = null,
+): RemoteSubscriptionState {
+    return new RemoteSubscriptionState(
+        snapshot: new SubscriptionSnapshot(
+            stripeId: $sub->stripe_id,
+            status: $status ?? $sub->stripe_status,
+            basePriceId: $basePriceId,
+            baseQuantity: $quantity,
+            currentPeriodEnd: $currentPeriodEnd,
+            trialEndsAt: $trialEndsAt,
+            endsAt: $endsAt,
+        ),
+        hasPaymentMethod: $hasPaymentMethod,
+    );
+}
+
+test('remote が past_due ならローカルを past_due にし猶予起点を打つ', function (): void {
+    $gateway = reconcileGateway();
+    $sub = reconcileSubscription(status: 'active');
+    $gateway->remoteStates[$sub->stripe_id] = reconcileRemote($sub, status: 'past_due');
+
+    $this->travelTo(CarbonImmutable::parse('2026-08-15 10:00:00'));
+    $this->artisan('billing:reconcile-subscription-status')->assertExitCode(0);
+
+    $fresh = $sub->fresh();
+    expect($fresh?->stripe_status)->toBe('past_due')
+        ->and($fresh?->past_due_since?->toDateTimeString())->toBe('2026-08-15 10:00:00');
+});
+
+test('remote が active に戻っていればローカルも戻り猶予起点が消える', function (): void {
+    $gateway = reconcileGateway();
+    $sub = reconcileSubscription(status: 'past_due', pastDueSince: CarbonImmutable::now()->subDays(3));
+    $gateway->remoteStates[$sub->stripe_id] = reconcileRemote($sub, status: 'active');
+
+    $this->artisan('billing:reconcile-subscription-status')->assertExitCode(0);
+
+    $fresh = $sub->fresh();
+    expect($fresh?->stripe_status)->toBe('active')
+        ->and($fresh?->past_due_since)->toBeNull();
+});
+
+test('past_due のまま起点が NULL の行は打刻漏れとして修復される', function (): void {
+    $gateway = reconcileGateway();
+    $sub = reconcileSubscription(status: 'past_due', pastDueSince: null);
+    $gateway->remoteStates[$sub->stripe_id] = reconcileRemote($sub, status: 'past_due');
+
+    $this->travelTo(CarbonImmutable::parse('2026-08-20 08:00:00'));
+    $this->artisan('billing:reconcile-subscription-status')->assertExitCode(0);
+
+    expect($sub->fresh()?->past_due_since?->toDateTimeString())->toBe('2026-08-20 08:00:00');
+});
+
+test('差分が無ければ 1 列も書かない (無駄な UPDATE をしない)', function (): void {
+    $gateway = reconcileGateway();
+    $sub = reconcileSubscription(status: 'active');
+    $gateway->remoteStates[$sub->stripe_id] = reconcileRemote($sub);
+
+    $before = DB::table('subscriptions')->where('id', $sub->getKey())->value('updated_at');
+    $this->travelTo(CarbonImmutable::now()->addHour());
+    $this->artisan('billing:reconcile-subscription-status')
+        ->expectsOutputToContain('checked=1 converged=0')
+        ->assertExitCode(0);
+
+    expect(DB::table('subscriptions')->where('id', $sub->getKey())->value('updated_at'))->toBe($before);
+});
+
+test('status 以外の差分も収束する (更新予告の真実源がずれたまま固まらない)', function (): void {
+    $gateway = reconcileGateway();
+    $sub = reconcileSubscription(status: 'active');
+    $periodEnd = CarbonImmutable::parse('2026-09-01 00:00:00');
+    $gateway->remoteStates[$sub->stripe_id] = reconcileRemote($sub, currentPeriodEnd: $periodEnd);
+
+    $this->artisan('billing:reconcile-subscription-status')
+        ->expectsOutputToContain('converged=1')
+        ->assertExitCode(0);
+
+    expect($sub->fresh()?->current_period_end?->toDateTimeString())->toBe('2026-09-01 00:00:00');
+});
+
+test('quantity / ends_at の差分も収束する', function (): void {
+    $gateway = reconcileGateway();
+    $sub = reconcileSubscription(status: 'active');
+    $endsAt = CarbonImmutable::parse('2026-09-30 00:00:00');
+    $gateway->remoteStates[$sub->stripe_id] = reconcileRemote($sub, quantity: 3, endsAt: $endsAt);
+
+    $this->artisan('billing:reconcile-subscription-status')->assertExitCode(0);
+
+    $fresh = $sub->fresh();
+    expect($fresh?->quantity)->toBe(3)
+        ->and($fresh?->ends_at?->toDateTimeString())->toBe('2026-09-30 00:00:00');
+});
+
+test('remote の period 欠落ではローカルの current_period_end を消さない', function (): void {
+    $gateway = reconcileGateway();
+    $sub = reconcileSubscription(status: 'active');
+    $sub->forceFill(['current_period_end' => CarbonImmutable::parse('2026-09-01 00:00:00')])->save();
+    $gateway->remoteStates[$sub->stripe_id] = reconcileRemote($sub, currentPeriodEnd: null);
+
+    $this->artisan('billing:reconcile-subscription-status')
+        ->expectsOutputToContain('converged=0')
+        ->assertExitCode(0);
+
+    expect($sub->fresh()?->current_period_end?->toDateTimeString())->toBe('2026-09-01 00:00:00');
+});
+
+test('決済手段の観測は true 方向だけ書く (null 観測で false へ戻さない)', function (): void {
+    $gateway = reconcileGateway();
+    $sub = reconcileSubscription(status: 'active');
+    expect($sub->fresh()?->has_payment_method)->toBeFalse();
+
+    // 観測できなかった (null) → 書かない
+    $gateway->remoteStates[$sub->stripe_id] = reconcileRemote($sub, hasPaymentMethod: null);
+    $this->artisan('billing:reconcile-subscription-status')->assertExitCode(0);
+    expect($sub->fresh()?->has_payment_method)->toBeFalse();
+
+    // 観測できた (true) → 書く
+    $gateway->remoteStates[$sub->stripe_id] = reconcileRemote($sub, hasPaymentMethod: true);
+    $this->artisan('billing:reconcile-subscription-status')->assertExitCode(0);
+    expect($sub->fresh()?->has_payment_method)->toBeTrue();
+
+    // 一度 true になった行は null 観測で false に戻らない
+    $gateway->remoteStates[$sub->stripe_id] = reconcileRemote($sub, hasPaymentMethod: null);
+    $this->artisan('billing:reconcile-subscription-status')->assertExitCode(0);
+    expect($sub->fresh()?->has_payment_method)->toBeTrue();
+});
+
+test('Stripe に無い契約 (404) は状態を変えず report され、終了コードは成功', function (): void {
+    reconcileGateway(); // remoteStates を仕込まない = 未検出
+    $sub = reconcileSubscription(status: 'past_due', pastDueSince: null);
+    $handler = reconcileHandlerSpy();
+
+    $this->artisan('billing:reconcile-subscription-status')
+        ->expectsOutputToContain('missing=1')
+        ->assertExitCode(0);
+
+    $fresh = $sub->fresh();
+    expect($fresh?->stripe_status)->toBe('past_due')
+        ->and($fresh?->past_due_since)->toBeNull();
+    $handler->shouldHaveReceived('report')->once();
+});
+
+test('照会失敗は走査を止めず FAILURE で終わる', function (): void {
+    $gateway = reconcileGateway();
+    $gateway->failOnLookup = true;
+    $first = reconcileSubscription(status: 'active');
+    $second = reconcileSubscription(status: 'active');
+    $handler = reconcileHandlerSpy();
+
+    $this->artisan('billing:reconcile-subscription-status')
+        ->expectsOutputToContain('checked=2 converged=0 missing=0 failed=2')
+        ->assertExitCode(1);
+
+    // 1 件目で止まらず 2 件目も照会している
+    expect($gateway->lookedUp)->toBe([$first->stripe_id, $second->stripe_id]);
+    $handler->shouldHaveReceived('report')->once();
+});
+
+test('report は 1 実行 1 回で、内容は件数と organization id のみ (PII なし)', function (): void {
+    $gateway = reconcileGateway();
+    $gateway->failOnLookup = true;
+    $organization = Organization::factory()->create(['name' => '秘密の現場']);
+    createFakeSubscription($organization, status: 'active');
+    $handler = reconcileHandlerSpy();
+
+    $this->artisan('billing:reconcile-subscription-status')->assertExitCode(1);
+
+    $handler->shouldHaveReceived('report')->once()->withArgs(function (Throwable $e) use ($organization): bool {
+        return str_contains($e->getMessage(), 'failed=1')
+            && str_contains($e->getMessage(), (string) $organization->getKey())
+            && ! str_contains($e->getMessage(), '秘密の現場');
+    });
+});
+
+test('ローカルが終了扱いの行は照会しない (照会対象が単調増加しない)', function (string $status): void {
+    $gateway = reconcileGateway();
+    reconcileSubscription(status: $status);
+
+    $this->artisan('billing:reconcile-subscription-status')
+        ->expectsOutputToContain('checked=0')
+        ->assertExitCode(0);
+
+    expect($gateway->lookedUp)->toBe([]);
+})->with(['canceled', 'incomplete_expired']);
+
+test('金銭は動かさない (チケット台帳の件数が変わらない)', function (): void {
+    $gateway = reconcileGateway();
+    $sub = reconcileSubscription(status: 'active');
+    $gateway->remoteStates[$sub->stripe_id] = reconcileRemote($sub, status: 'past_due');
+    $before = TicketLedgerEntry::query()->count();
+
+    $this->artisan('billing:reconcile-subscription-status')->assertExitCode(0);
+
+    expect(TicketLedgerEntry::query()->count())->toBe($before)
+        ->and($sub->fresh()?->stripe_status)->toBe('past_due');
+});
+
+test('ロック保持中の実行は照会せず FAILURE で終わる (多重起動の防止)', function (): void {
+    $gateway = reconcileGateway();
+    reconcileSubscription(status: 'active');
+
+    $lock = Cache::lock('billing:reconcile-subscription-status', ReconcileSubscriptionStatus::LOCK_SECONDS);
+    expect($lock->get())->toBeTrue();
+
+    try {
+        $this->artisan('billing:reconcile-subscription-status')->assertExitCode(1);
+        expect($gateway->lookedUp)->toBe([]);
+    } finally {
+        $lock->release();
+    }
+});
+
+// ── 実行時間上限とロック有効期限の関係 ──
+
+test('実行時間上限を超えたら残りを照会せず FAILURE で終わる', function (): void {
+    $gateway = reconcileGateway();
+    // 1 件目の照会で予算を丸ごと使い切らせる (実際には待たず時計だけ進める)。
+    $gateway->lookupElapsedSeconds = ReconcileSubscriptionStatus::TIME_BUDGET_SECONDS + 1;
+    $first = reconcileSubscription(status: 'active');
+    $second = reconcileSubscription(status: 'active');
+    $gateway->remoteStates[$first->stripe_id] = reconcileRemote($first);
+    $gateway->remoteStates[$second->stripe_id] = reconcileRemote($second);
+
+    $this->travelTo(CarbonImmutable::parse('2026-08-15 03:00:00'));
+
+    $this->artisan('billing:reconcile-subscription-status')
+        ->expectsOutputToContain('checked=1')
+        ->assertExitCode(1);
+
+    expect($gateway->lookedUp)->toBe([$first->stripe_id]);
+});
+
+test('実行時間上限は Stripe の待ち上限と合わせてロック有効期限に収まる', function (): void {
+    // 再試行 0 回が前提 (再試行を許すと SDK のバックオフ待機が式に入らなくなる)
+    expect(ExternalClientTimeouts::STRIPE_MAX_NETWORK_RETRIES)->toBe(0);
+    expect(
+        ReconcileSubscriptionStatus::TIME_BUDGET_SECONDS
+        + ExternalClientTimeouts::STRIPE_CONNECT_TIMEOUT_SECONDS
+        + ExternalClientTimeouts::STRIPE_TIMEOUT_SECONDS
+    )->toBeLessThan(ReconcileSubscriptionStatus::LOCK_SECONDS);
+});
+
+test('scheduler に daily + onOneServer + withoutOverlapping で登録されている', function (): void {
+    $events = collect(app(Schedule::class)->events())
+        ->filter(fn (Event $event): bool => str_contains((string) $event->command, 'billing:reconcile-subscription-status'));
+
+    expect($events)->toHaveCount(1);
+    $event = $events->firstOrFail();
+    expect($event->getExpression())->toBe('0 0 * * *')
+        ->and($event->onOneServer)->toBeTrue()
+        ->and($event->withoutOverlapping)->toBeTrue();
+});
diff --git a/tests/Feature/Billing/RequireActiveSubscriptionMiddlewareTest.php b/tests/Feature/Billing/RequireActiveSubscriptionMiddlewareTest.php
index 7d9e40f..453c826 100644
--- a/tests/Feature/Billing/RequireActiveSubscriptionMiddlewareTest.php
+++ b/tests/Feature/Billing/RequireActiveSubscriptionMiddlewareTest.php
@@ -80,6 +80,37 @@
     $this->actingAs($owner)->get('/projects')->assertOk();
 });
 
+test('past_due の猶予中は業務 route に到達できる (cohort D は猶予の期限内で維持)', function (): void {
+    config()->set('billing.payment_grace_days', 14);
+    [$organization, $owner] = createOrganizationWithOwner(grandfatherFreePlan: false);
+    $subscription = contractPaidPlan($organization, status: 'past_due');
+    $subscription->forceFill(['past_due_since' => CarbonImmutable::now()->subDays(13)])->save();
+
+    $this->actingAs($owner)->get('/projects')->assertOk();
+});
+
+test('past_due の猶予切れは遮断される (AG-035 (5))', function (): void {
+    config()->set('billing.payment_grace_days', 14);
+    [$organization, $owner] = createOrganizationWithOwner(grandfatherFreePlan: false);
+    $subscription = contractPaidPlan($organization, status: 'past_due');
+    $subscription->forceFill(['past_due_since' => CarbonImmutable::now()->subDays(15)])->save();
+
+    $this->actingAs($owner)->get('/projects')
+        ->assertRedirect(route('onboarding.checkout'))
+        ->assertSessionMissing('error');
+});
+
+test('past_due の猶予切れの JSON は 402 + 既存文言 (遮断理由の文言は増やさない)', function (): void {
+    config()->set('billing.payment_grace_days', 14);
+    [$organization, $owner] = createOrganizationWithOwner(grandfatherFreePlan: false);
+    $subscription = contractPaidPlan($organization, status: 'past_due');
+    $subscription->forceFill(['past_due_since' => CarbonImmutable::now()->subDays(15)])->save();
+
+    $this->actingAs($owner)->getJson('/projects')
+        ->assertStatus(402)
+        ->assertJsonPath('message', BILLING_BLOCKED_MESSAGE);
+});
+
 test('有償契約 + 支払い不健全は billing へ redirect + 理由 flash', function (string $status): void {
     [$organization, $owner] = createOrganizationWithOwner(grandfatherFreePlan: false);
     contractPaidPlan($organization, status: $status);
diff --git a/tests/Feature/Billing/SubscriptionCheckoutGuardTest.php b/tests/Feature/Billing/SubscriptionCheckoutGuardTest.php
index f5709e4..087885d 100644
--- a/tests/Feature/Billing/SubscriptionCheckoutGuardTest.php
+++ b/tests/Feature/Billing/SubscriptionCheckoutGuardTest.php
@@ -14,6 +14,7 @@
 use Carbon\CarbonImmutable;
 use Illuminate\Support\Str;
 use Illuminate\Validation\ValidationException;
+use Tests\Support\FakeStripeGateway as TestFakeStripeGateway;
 use Webmozart\Assert\Assert;
 
 /*
@@ -137,3 +138,48 @@ function startGuardCheckout(Organization $organization, User $user, ?Plan $plan
         ->assertRedirect('/billing')
         ->assertSessionHas('error', '既に有効なサブスクリプションがあります。プラン変更をご利用ください。');
 });
+
+// ── 支払い未解決の契約がある間は新規契約を作らない (二重請求の防止) ──
+//
+// Cashier の valid() は past_due / unpaid を false と見るため段 1 を素通りするが、
+// Stripe 側の契約は生きている。ここで作ると 2 本目の契約 = 二重請求になる。
+
+test('支払い未解決の契約がある組織の checkout は Stripe を呼ばずに落ちる', function (string $status): void {
+    // 「Stripe に出ていない」ことを見るため spy の fake に差し替える。
+    $spy = new TestFakeStripeGateway;
+    $this->app->instance(StripeGatewayInterface::class, $spy);
+
+    [$organization, $owner] = createOrganizationWithOwner();
+    createFakeSubscription($organization, status: $status);
+
+    try {
+        startGuardCheckout($organization, $owner);
+        $this->fail('支払い未解決の契約があるのに checkout が通ってしまった');
+    } catch (InvalidArgumentException $e) {
+        expect($e->getMessage())->toBe('お支払いが確認できていないご契約があります。お支払い方法の更新をお願いします。');
+    }
+
+    expect($spy->created)->toBe([]); // 2 本目の契約を作っていない
+})->with(['past_due', 'unpaid']);
+
+test('支払い未解決の /billing/checkout は error flash で差し戻す (押下時にエラーを出す)', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    createFakeSubscription($organization, status: 'past_due');
+
+    $this->actingAs($owner)
+        ->from('/billing')
+        ->post('/billing/checkout', [
+            'plan_code' => 'standard',
+            'subscription_attempt_token' => (string) Str::ulid(),
+        ])
+        ->assertRedirect('/billing')
+        ->assertSessionHas('error', 'お支払いが確認できていないご契約があります。お支払い方法の更新をお願いします。');
+});
+
+test('解約済み (canceled) の契約がある組織は従来どおり新規契約を開始できる', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    createFakeSubscription($organization, status: 'canceled')
+        ->forceFill(['ends_at' => CarbonImmutable::now()->subDay()])->save();
+
+    expect(startGuardCheckout($organization, $owner))->toContain('fake_external=stripe');
+});
diff --git a/tests/Feature/Billing/SubscriptionCheckoutIdempotencyTest.php b/tests/Feature/Billing/SubscriptionCheckoutIdempotencyTest.php
index 2591b19..929f84b 100644
--- a/tests/Feature/Billing/SubscriptionCheckoutIdempotencyTest.php
+++ b/tests/Feature/Billing/SubscriptionCheckoutIdempotencyTest.php
@@ -4,6 +4,7 @@
 
 use App\DataTransferObjects\Billing\CreatedCheckoutSession;
 use App\DataTransferObjects\Billing\ExternalBillingRedirect;
+use App\DataTransferObjects\Billing\RemoteSubscriptionState;
 use App\Enums\Billing\BillingFeedbackKind;
 use App\Enums\Billing\SubscriptionSwapOutcome;
 use App\Enums\CheckoutIntent;
@@ -385,6 +386,11 @@ public function swapSubscriptionPrices(
             return $this->inner->swapSubscriptionPrices($organization, $basePriceId, $idempotencyKey);
         }
 
+        public function retrieveSubscriptionState(string $stripeSubscriptionId): ?RemoteSubscriptionState
+        {
+            return $this->inner->retrieveSubscriptionState($stripeSubscriptionId);
+        }
+
         public function expireCheckoutSession(string $stripeSessionId): string
         {
             return $this->inner->expireCheckoutSession($stripeSessionId);
@@ -465,6 +471,11 @@ public function swapSubscriptionPrices(
             return $this->inner->swapSubscriptionPrices($organization, $basePriceId, $idempotencyKey);
         }
 
+        public function retrieveSubscriptionState(string $stripeSubscriptionId): ?RemoteSubscriptionState
+        {
+            return $this->inner->retrieveSubscriptionState($stripeSubscriptionId);
+        }
+
         public function expireCheckoutSession(string $stripeSessionId): string
         {
             return $this->inner->expireCheckoutSession($stripeSessionId);
diff --git a/tests/Feature/Billing/SubscriptionEntitlementTest.php b/tests/Feature/Billing/SubscriptionEntitlementTest.php
index d861186..da83073 100644
--- a/tests/Feature/Billing/SubscriptionEntitlementTest.php
+++ b/tests/Feature/Billing/SubscriptionEntitlementTest.php
@@ -17,6 +17,7 @@
  *   entitled = state.grantsAccess()
  *              AND NOT (trial_ends_at <= now AND !has_payment_method)
  *              AND status != paused
+ *              AND NOT (state = PastDue AND past_due_since != null AND 猶予期限切れ)
  */
 
 function entitlementSubscription(
@@ -25,6 +26,7 @@ function entitlementSubscription(
     ?CarbonImmutable $trialEndsAt = null,
     ?string $scheduleId = null,
     ScheduleSetupStatus $scheduleSetupStatus = ScheduleSetupStatus::None,
+    ?CarbonImmutable $pastDueSince = null,
 ): Subscription {
     $organization = Organization::factory()->create();
     $subscription = createFakeSubscription($organization, status: $status);
@@ -33,6 +35,7 @@ function entitlementSubscription(
         'trial_ends_at' => $trialEndsAt,
         'stripe_schedule_id' => $scheduleId,
         'schedule_setup_status' => $scheduleSetupStatus,
+        'past_due_since' => $pastDueSince,
     ])->save();
 
     return $subscription;
@@ -149,7 +152,94 @@ function entitlementService(): SubscriptionService
     expect($entitlement->entitled)->toBeFalse()
         ->and($entitlement->state)->toBe(SubscriptionState::Inactive)
         ->and($entitlement->reason)->toBe(EntitlementDeniedReason::NoActiveSubscription);
-})->with(['canceled', 'unpaid', 'incomplete', 'incomplete_expired']);
+})->with(['canceled', 'incomplete', 'incomplete_expired']);
+
+test('unpaid は Unpaid state / NoActiveSubscription で否定 (Inactive から分離しても可否は同じ)', function (): void {
+    $entitlement = entitlementService()->deriveEntitlement(entitlementSubscription(status: 'unpaid'));
+
+    expect($entitlement->entitled)->toBeFalse()
+        ->and($entitlement->state)->toBe(SubscriptionState::Unpaid)
+        ->and($entitlement->reason)->toBe(EntitlementDeniedReason::NoActiveSubscription);
+});
+
+// ── 支払い失敗の猶予 (AG-035 (5)) ──
+
+test('past_due + 猶予中 (起点 13 日前) は entitled', function (): void {
+    config()->set('billing.payment_grace_days', 14);
+    $entitlement = entitlementService()->deriveEntitlement(entitlementSubscription(
+        status: 'past_due',
+        trialEndsAt: CarbonImmutable::now()->subDay(),
+        pastDueSince: CarbonImmutable::now()->subDays(13),
+    ));
+
+    expect($entitlement->entitled)->toBeTrue()
+        ->and($entitlement->state)->toBe(SubscriptionState::PastDue)
+        ->and($entitlement->reason)->toBeNull();
+});
+
+test('past_due + 起点ちょうど 14 日前は entitled (境界は継続)', function (): void {
+    config()->set('billing.payment_grace_days', 14);
+    // 境界そのものを見るので時計を止める (経過マイクロ秒で結論が揺れないようにする)。
+    $this->travelTo(CarbonImmutable::parse('2026-08-15 09:00:00'));
+    $entitlement = entitlementService()->deriveEntitlement(entitlementSubscription(
+        status: 'past_due',
+        trialEndsAt: CarbonImmutable::now()->subDay(),
+        pastDueSince: CarbonImmutable::now()->subDays(14),
+    ));
+
+    expect($entitlement->entitled)->toBeTrue()
+        ->and($entitlement->reason)->toBeNull();
+});
+
+test('past_due + 起点 15 日前は PaymentGraceExpired で否定', function (): void {
+    config()->set('billing.payment_grace_days', 14);
+    $entitlement = entitlementService()->deriveEntitlement(entitlementSubscription(
+        status: 'past_due',
+        trialEndsAt: CarbonImmutable::now()->subDay(),
+        pastDueSince: CarbonImmutable::now()->subDays(15),
+    ));
+
+    expect($entitlement->entitled)->toBeFalse()
+        ->and($entitlement->state)->toBe(SubscriptionState::PastDue)
+        ->and($entitlement->reason)->toBe(EntitlementDeniedReason::PaymentGraceExpired);
+});
+
+test('past_due + 起点 NULL は遮断しない (打刻漏れを締め出しに変えない)', function (): void {
+    config()->set('billing.payment_grace_days', 14);
+    $entitlement = entitlementService()->deriveEntitlement(entitlementSubscription(
+        status: 'past_due',
+        trialEndsAt: CarbonImmutable::now()->subDays(90),
+        pastDueSince: null,
+    ));
+
+    expect($entitlement->entitled)->toBeTrue()
+        ->and($entitlement->reason)->toBeNull();
+});
+
+test('猶予切れでも trial 終了 + PM 無しの理由が優先される (既存の優先順位が変わらない)', function (): void {
+    config()->set('billing.payment_grace_days', 14);
+    $entitlement = entitlementService()->deriveEntitlement(entitlementSubscription(
+        status: 'past_due',
+        hasPaymentMethod: false,
+        trialEndsAt: CarbonImmutable::now()->subDay(),
+        pastDueSince: CarbonImmutable::now()->subDays(15),
+    ));
+
+    expect($entitlement->entitled)->toBeFalse()
+        ->and($entitlement->reason)->toBe(EntitlementDeniedReason::TrialEndedWithoutPaymentMethod);
+});
+
+test('猶予は PastDue 限定 (active に古い起点が残っていても遮断しない)', function (): void {
+    config()->set('billing.payment_grace_days', 14);
+    $entitlement = entitlementService()->deriveEntitlement(entitlementSubscription(
+        status: 'active',
+        pastDueSince: CarbonImmutable::now()->subDays(15),
+    ));
+
+    expect($entitlement->entitled)->toBeTrue()
+        ->and($entitlement->state)->toBe(SubscriptionState::Active)
+        ->and($entitlement->reason)->toBeNull();
+});
 
 test('DTO の toArray は entitled / state / reason を value で返す', function (): void {
     $granted = entitlementService()->deriveEntitlement(entitlementSubscription());
diff --git a/tests/Feature/Billing/SubscriptionLookupGatewayTest.php b/tests/Feature/Billing/SubscriptionLookupGatewayTest.php
new file mode 100644
index 0000000..57104a4
--- /dev/null
+++ b/tests/Feature/Billing/SubscriptionLookupGatewayTest.php
@@ -0,0 +1,139 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Exceptions\Billing\SubscriptionLookupFailedException;
+use App\Services\Billing\CashierStripeGateway;
+use App\Services\Billing\SubscriptionSnapshotMapper;
+use Stripe\Exception\ApiConnectionException;
+use Stripe\Exception\InvalidRequestException;
+use Stripe\Service\SubscriptionService as StripeSubscriptionService;
+use Stripe\StripeClient;
+use Stripe\Subscription as StripeSubscription;
+
+/*
+ * CashierStripeGateway::retrieveSubscriptionState() の**制御フロー**を固定する
+ * (SubscriptionSwapGatewayTest と同じ protected seam 差し替えで実ネットワークに出ない)。
+ *
+ * 固定する契約:
+ *  - 正常応答 → mapper 経由で SubscriptionSnapshot が組み上がる
+ *  - resource_missing → **null** (「無い」は例外にしない = 状態を変えない材料)
+ *  - それ以外の Stripe SDK 例外 → SubscriptionLookupFailedException に変換 (SDK 例外を外へ出さない)
+ *  - id の取れない応答 → SubscriptionLookupFailedException (壊れた応答で状態を変えない)
+ *  - 決済手段は三値 (観測できなければ null。false と断定しない)
+ */
+
+/** seam を差し替えた gateway (Stripe client を注入できる本番実装)。 */
+function lookupGateway(StripeClient $client): CashierStripeGateway
+{
+    return new class($client, app(SubscriptionSnapshotMapper::class)) extends CashierStripeGateway
+    {
+        public function __construct(
+            private readonly StripeClient $client,
+            SubscriptionSnapshotMapper $mapper,
+        ) {
+            parent::__construct($mapper);
+        }
+
+        protected function stripe(): StripeClient
+        {
+            return $this->client;
+        }
+    };
+}
+
+/**
+ * retrieve が $result を返す (または throw する) mock client。
+ */
+function lookupClient(mixed $result): StripeClient
+{
+    $subscriptions = Mockery::mock(StripeSubscriptionService::class);
+    if ($result instanceof Throwable) {
+        $subscriptions->shouldReceive('retrieve')->andThrow($result);
+    } else {
+        $subscriptions->shouldReceive('retrieve')->andReturn($result);
+    }
+
+    $client = Mockery::mock(StripeClient::class);
+    $client->subscriptions = $subscriptions;
+
+    return $client;
+}
+
+test('正常応答は mapper 経由で snapshot になる', function (): void {
+    $remote = StripeSubscription::constructFrom([
+        'id' => 'sub_lookup_1',
+        'object' => 'subscription',
+        'status' => 'past_due',
+        'items' => ['object' => 'list', 'data' => [[
+            'id' => 'si_1',
+            'object' => 'subscription_item',
+            'price' => ['id' => 'price_lookup_1', 'object' => 'price'],
+            'quantity' => 1,
+            'current_period_end' => 1_800_000_000,
+        ]]],
+    ]);
+
+    $state = lookupGateway(lookupClient($remote))->retrieveSubscriptionState('sub_lookup_1');
+
+    expect($state)->not->toBeNull()
+        ->and($state?->snapshot->stripeId)->toBe('sub_lookup_1')
+        ->and($state?->snapshot->status)->toBe('past_due')
+        ->and($state?->snapshot->basePriceId)->toBe('price_lookup_1')
+        ->and($state?->snapshot->baseQuantity)->toBe(1)
+        ->and($state?->snapshot->currentPeriodEnd?->getTimestamp())->toBe(1_800_000_000);
+});
+
+test('resource_missing は null (契約が無いことは例外にしない)', function (): void {
+    $missing = new InvalidRequestException('No such subscription');
+    $missing->setStripeCode('resource_missing');
+
+    expect(lookupGateway(lookupClient($missing))->retrieveSubscriptionState('sub_gone'))->toBeNull();
+});
+
+test('resource_missing 以外の InvalidRequestException は変換される', function (): void {
+    $invalid = new InvalidRequestException('bad parameter');
+    $invalid->setStripeCode('parameter_invalid_empty');
+
+    lookupGateway(lookupClient($invalid))->retrieveSubscriptionState('sub_lookup_1');
+})->throws(SubscriptionLookupFailedException::class);
+
+test('その他の Stripe SDK 例外も変換される (SDK 例外を境界の外へ出さない)', function (): void {
+    lookupGateway(lookupClient(new ApiConnectionException('network down')))
+        ->retrieveSubscriptionState('sub_lookup_1');
+})->throws(SubscriptionLookupFailedException::class);
+
+test('id の取れない応答は「確認できなかった」として例外 (状態を変える材料にしない)', function (): void {
+    $broken = StripeSubscription::constructFrom(['object' => 'subscription', 'status' => 'active']);
+
+    lookupGateway(lookupClient($broken))->retrieveSubscriptionState('sub_lookup_1');
+})->throws(SubscriptionLookupFailedException::class);
+
+test('default_payment_method があれば hasPaymentMethod = true', function (): void {
+    $remote = StripeSubscription::constructFrom([
+        'id' => 'sub_lookup_2',
+        'object' => 'subscription',
+        'status' => 'active',
+        'default_payment_method' => 'pm_lookup_1',
+        'items' => ['object' => 'list', 'data' => []],
+    ]);
+
+    expect(lookupGateway(lookupClient($remote))->retrieveSubscriptionState('sub_lookup_2')?->hasPaymentMethod)
+        ->toBeTrue();
+});
+
+test('決済手段が観測できないときは null (false にしない)', function (): void {
+    $remote = StripeSubscription::constructFrom([
+        'id' => 'sub_lookup_3',
+        'object' => 'subscription',
+        'status' => 'active',
+        'items' => ['object' => 'list', 'data' => []],
+    ]);
+
+    expect(lookupGateway(lookupClient($remote))->retrieveSubscriptionState('sub_lookup_3')?->hasPaymentMethod)
+        ->toBeNull();
+});
+
+test('空の subscription id は fail-fast (照会に出さない)', function (): void {
+    lookupGateway(lookupClient(null))->retrieveSubscriptionState('');
+})->throws(InvalidArgumentException::class);
diff --git a/tests/Feature/Billing/SubscriptionSnapshotMapperTest.php b/tests/Feature/Billing/SubscriptionSnapshotMapperTest.php
new file mode 100644
index 0000000..c6fbc93
--- /dev/null
+++ b/tests/Feature/Billing/SubscriptionSnapshotMapperTest.php
@@ -0,0 +1,138 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Services\Billing\SubscriptionSnapshotMapper;
+
+/*
+ * SubscriptionSnapshotMapper — Stripe の subscription オブジェクト (配列) から
+ * SubscriptionSnapshot を組む **唯一の写像**。
+ *
+ * webhook (payload の data.object) と日次突き合わせ (SDK オブジェクトの toArray()) は
+ * どちらもここを通る。同じ配列を渡せば同じ snapshot が出ることを固定し、
+ * 「突き合わせ経路だけ別挙動」の余地を消す。
+ */
+
+function snapshotMapper(): SubscriptionSnapshotMapper
+{
+    return app(SubscriptionSnapshotMapper::class);
+}
+
+/**
+ * @param  array<string, mixed>  $overrides
+ * @return array<string, mixed>
+ */
+function stripeSubscriptionObject(array $overrides = []): array
+{
+    return array_replace([
+        'id' => 'sub_map_1',
+        'object' => 'subscription',
+        'status' => 'active',
+        'items' => ['object' => 'list', 'data' => [[
+            'id' => 'si_map_1',
+            'price' => ['id' => 'price_map_1'],
+            'quantity' => 2,
+            'current_period_end' => 1_800_000_000,
+        ]]],
+        'trial_end' => 1_700_000_000,
+    ], $overrides);
+}
+
+test('7 フィールドを取り出す', function (): void {
+    $snapshot = snapshotMapper()->fromStripeSubscription(stripeSubscriptionObject([
+        'ended_at' => 1_750_000_000,
+    ]));
+
+    expect($snapshot)->not->toBeNull()
+        ->and($snapshot?->stripeId)->toBe('sub_map_1')
+        ->and($snapshot?->status)->toBe('active')
+        ->and($snapshot?->basePriceId)->toBe('price_map_1')
+        ->and($snapshot?->baseQuantity)->toBe(2)
+        ->and($snapshot?->currentPeriodEnd?->getTimestamp())->toBe(1_800_000_000)
+        ->and($snapshot?->trialEndsAt?->getTimestamp())->toBe(1_700_000_000)
+        ->and($snapshot?->endsAt?->getTimestamp())->toBe(1_750_000_000);
+});
+
+test('id が無い応答は写像失敗 (null。呼び出し側が fail-closed に倒す)', function (mixed $id): void {
+    $object = stripeSubscriptionObject();
+    $object['id'] = $id;
+
+    expect(snapshotMapper()->fromStripeSubscription($object))->toBeNull();
+})->with([
+    'null' => [null],
+    '空文字' => [''],
+    '非文字列' => [123],
+]);
+
+test('status 欠落は incomplete に倒す (未知状態を active と誤読しない)', function (): void {
+    $object = stripeSubscriptionObject();
+    unset($object['status']);
+
+    expect(snapshotMapper()->fromStripeSubscription($object)?->status)->toBe('incomplete');
+});
+
+test('current_period_end は新 API (item 配下) を優先し、無ければ旧 API (top-level) を拾う', function (): void {
+    $newApi = snapshotMapper()->fromStripeSubscription(stripeSubscriptionObject([
+        'current_period_end' => 1_600_000_000,
+    ]));
+    expect($newApi?->currentPeriodEnd?->getTimestamp())->toBe(1_800_000_000);
+
+    $object = stripeSubscriptionObject(['current_period_end' => 1_600_000_000]);
+    unset($object['items']['data'][0]['current_period_end']);
+
+    expect(snapshotMapper()->fromStripeSubscription($object)?->currentPeriodEnd?->getTimestamp())
+        ->toBe(1_600_000_000);
+});
+
+test('epoch 0 / 非 int の時刻は null (0 を 1970 年として書き込まない)', function (mixed $value): void {
+    $object = stripeSubscriptionObject(['trial_end' => $value]);
+
+    expect(snapshotMapper()->fromStripeSubscription($object)?->trialEndsAt)->toBeNull();
+})->with([
+    'epoch 0' => [0],
+    '負値' => [-1],
+    '文字列' => ['1700000000'],
+    'null' => [null],
+]);
+
+test('endsAt は ended_at を優先し、無ければ cancel_at を拾う', function (): void {
+    $both = snapshotMapper()->fromStripeSubscription(stripeSubscriptionObject([
+        'ended_at' => 1_750_000_000,
+        'cancel_at' => 1_760_000_000,
+    ]));
+    expect($both?->endsAt?->getTimestamp())->toBe(1_750_000_000);
+
+    $cancelOnly = snapshotMapper()->fromStripeSubscription(stripeSubscriptionObject([
+        'cancel_at' => 1_760_000_000,
+    ]));
+    expect($cancelOnly?->endsAt?->getTimestamp())->toBe(1_760_000_000);
+});
+
+test('quantity が int でなければ null (欠落を 0 と読まない)', function (): void {
+    $object = stripeSubscriptionObject();
+    $object['items']['data'][0]['quantity'] = '2';
+
+    expect(snapshotMapper()->fromStripeSubscription($object)?->baseQuantity)->toBeNull();
+});
+
+test('決済手段の観測は三値 (true / 観測できず null。false と断定しない)', function (array $overrides, ?bool $expected): void {
+    expect(snapshotMapper()->observePaymentMethod(stripeSubscriptionObject($overrides)))->toBe($expected);
+})->with([
+    'default_payment_method が id 文字列' => [['default_payment_method' => 'pm_1'], true],
+    'default_payment_method が expanded' => [['default_payment_method' => ['id' => 'pm_1']], true],
+    'default_source のみ' => [['default_source' => 'card_1'], true],
+    'どちらも無い' => [[], null],
+    'どちらも空文字' => [['default_payment_method' => '', 'default_source' => ''], null],
+]);
+
+test('webhook 経路 (data.object) と gateway 経路 (toArray 相当) は同じ snapshot を生む', function (): void {
+    $object = stripeSubscriptionObject(['ended_at' => 1_750_000_000]);
+
+    // webhook は payload の data.object を取り出して渡す。gateway は SDK の toArray() を渡す。
+    $payload = ['data' => ['object' => $object]];
+    /** @var array<string, mixed> $fromPayload */
+    $fromPayload = data_get($payload, 'data.object');
+
+    expect(snapshotMapper()->fromStripeSubscription($fromPayload))
+        ->toEqual(snapshotMapper()->fromStripeSubscription($object));
+});
diff --git a/tests/Feature/Billing/SubscriptionSnapshotSyncTest.php b/tests/Feature/Billing/SubscriptionSnapshotSyncTest.php
index e19aae1..41852bc 100644
--- a/tests/Feature/Billing/SubscriptionSnapshotSyncTest.php
+++ b/tests/Feature/Billing/SubscriptionSnapshotSyncTest.php
@@ -357,3 +357,68 @@ function snapshotSyncSnapshot(
     expect(Subscription::query()->count())->toBe(0)
         ->and($organization->fresh()?->plan_code)->toBe('standard');
 });
+
+// ── 猶予の起点 (past_due_since) の打刻 — 唯一の writer は applySubscriptionSnapshot ──
+
+test('active → past_due の観測で past_due_since が観測時刻で打たれる', function (): void {
+    $organization = snapshotSyncOrganization();
+    $subscription = createFakeSubscription($organization, status: 'active');
+    $subscription->forceFill(['stripe_id' => 'sub_snapshot_1'])->save();
+
+    $this->travelTo(CarbonImmutable::parse('2026-08-15 10:00:00'));
+    snapshotSyncService()->applySubscriptionSnapshot($organization, snapshotSyncSnapshot(status: 'past_due'));
+
+    expect($subscription->fresh()?->past_due_since?->toDateTimeString())->toBe('2026-08-15 10:00:00');
+});
+
+test('past_due の再送では起点を上書きしない (猶予を先送りしない)', function (): void {
+    $organization = snapshotSyncOrganization();
+    $subscription = createFakeSubscription($organization, status: 'active');
+    $subscription->forceFill(['stripe_id' => 'sub_snapshot_1'])->save();
+
+    $this->travelTo(CarbonImmutable::parse('2026-08-15 10:00:00'));
+    snapshotSyncService()->applySubscriptionSnapshot($organization, snapshotSyncSnapshot(status: 'past_due'));
+
+    $this->travelTo(CarbonImmutable::parse('2026-08-18 10:00:00'));
+    snapshotSyncService()->applySubscriptionSnapshot($organization, snapshotSyncSnapshot(status: 'past_due'));
+
+    expect($subscription->fresh()?->past_due_since?->toDateTimeString())->toBe('2026-08-15 10:00:00');
+});
+
+test('past_due → active の復旧で起点が NULL に戻る', function (): void {
+    $organization = snapshotSyncOrganization();
+    $subscription = createFakeSubscription($organization, status: 'past_due');
+    $subscription->forceFill([
+        'stripe_id' => 'sub_snapshot_1',
+        'past_due_since' => CarbonImmutable::parse('2026-08-01 10:00:00'),
+    ])->save();
+
+    snapshotSyncService()->applySubscriptionSnapshot($organization, snapshotSyncSnapshot(status: 'active'));
+
+    expect($subscription->fresh()?->past_due_since)->toBeNull();
+});
+
+test('契約終了 (terminated) でも起点が NULL に戻る', function (): void {
+    $organization = snapshotSyncOrganization();
+    $subscription = createFakeSubscription($organization, status: 'past_due');
+    $subscription->forceFill([
+        'stripe_id' => 'sub_snapshot_1',
+        'past_due_since' => CarbonImmutable::parse('2026-08-01 10:00:00'),
+    ])->save();
+
+    snapshotSyncService()->applySubscriptionSnapshot(
+        $organization,
+        snapshotSyncSnapshot(status: 'canceled'),
+        terminated: true,
+    );
+
+    expect($subscription->fresh()?->past_due_since)->toBeNull();
+});
+
+test('subscription 行が無い間の past_due 観測は no-op (行を作らない)', function (): void {
+    $organization = snapshotSyncOrganization();
+
+    snapshotSyncService()->applySubscriptionSnapshot($organization, snapshotSyncSnapshot(status: 'past_due'));
+
+    expect(Subscription::query()->count())->toBe(0);
+});
diff --git a/tests/Feature/Billing/SubscriptionStateTableTest.php b/tests/Feature/Billing/SubscriptionStateTableTest.php
new file mode 100644
index 0000000..64fbf99
--- /dev/null
+++ b/tests/Feature/Billing/SubscriptionStateTableTest.php
@@ -0,0 +1,72 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\Billing\SubscriptionState;
+use App\Models\Organization;
+
+/*
+ * Stripe の status 文字列 → SubscriptionState → grantsAccess() / hasUnsettledPayment() の
+ * 期待値表を 1 つ持ち、Stripe が subscription に取り得る status を全部回す。
+ *
+ * hasUnsettledPayment = 「契約が終了しておらず支払いが未解決」。これが true の間だけ
+ * 無料枠への読み替え (BillingAccess) と新規契約 (SubscriptionService) を禁じる。
+ * canceled は未払いの請求書が残りうるが false = 債権回収は課金事業者側の仕事として切り離す。
+ */
+
+/**
+ * @return list<array{string, SubscriptionState, bool, bool}>
+ */
+function subscriptionStateTable(): array
+{
+    return [
+        // [stripe_status, 期待 state, grantsAccess, hasUnsettledPayment]
+        ['active', SubscriptionState::Active, true, false],
+        ['trialing', SubscriptionState::Active, true, false],
+        ['past_due', SubscriptionState::PastDue, true, true],
+        ['paused', SubscriptionState::Paused, false, false],
+        ['unpaid', SubscriptionState::Unpaid, false, true],
+        ['canceled', SubscriptionState::Inactive, false, false],
+        ['incomplete', SubscriptionState::Inactive, false, false],
+        ['incomplete_expired', SubscriptionState::Inactive, false, false],
+    ];
+}
+
+test('status → state → grantsAccess / hasUnsettledPayment の表', function (
+    string $status,
+    SubscriptionState $expectedState,
+    bool $grantsAccess,
+    bool $unsettled,
+): void {
+    $organization = Organization::factory()->create();
+    $subscription = createFakeSubscription($organization, status: $status);
+
+    $state = SubscriptionState::fromSubscription($subscription);
+
+    expect($state)->toBe($expectedState)
+        ->and($state->grantsAccess())->toBe($grantsAccess)
+        ->and($state->hasUnsettledPayment())->toBe($unsettled);
+})->with(subscriptionStateTable());
+
+test('表は Stripe の subscription status を網羅している (取りこぼしを作らない)', function (): void {
+    $covered = array_map(static fn (array $row): string => $row[0], subscriptionStateTable());
+
+    expect($covered)->toBe([
+        'active', 'trialing', 'past_due', 'paused',
+        'unpaid', 'canceled', 'incomplete', 'incomplete_expired',
+    ]);
+});
+
+test('hasUnsettledPayment は全 case で例外なく評価できる (網羅 match の空振り防止)', function (): void {
+    foreach (SubscriptionState::cases() as $case) {
+        expect($case->hasUnsettledPayment())->toBeBool();
+    }
+
+    // 支払い未解決なのは PastDue / Unpaid の 2 つだけ。
+    $unsettled = array_values(array_filter(
+        SubscriptionState::cases(),
+        static fn (SubscriptionState $case): bool => $case->hasUnsettledPayment(),
+    ));
+
+    expect($unsettled)->toBe([SubscriptionState::PastDue, SubscriptionState::Unpaid]);
+});
diff --git a/tests/Feature/Billing/SubscriptionSwapGatewayTest.php b/tests/Feature/Billing/SubscriptionSwapGatewayTest.php
index 25ef359..6777d79 100644
--- a/tests/Feature/Billing/SubscriptionSwapGatewayTest.php
+++ b/tests/Feature/Billing/SubscriptionSwapGatewayTest.php
@@ -6,6 +6,7 @@
 use App\Exceptions\Billing\PlanChangeFailedException;
 use App\Models\Organization;
 use App\Services\Billing\CashierStripeGateway;
+use App\Services\Billing\SubscriptionSnapshotMapper;
 use Mockery\MockInterface;
 use Stripe\Exception\InvalidRequestException;
 use Stripe\Service\SubscriptionService as StripeSubscriptionService;
@@ -34,7 +35,10 @@ function swapGateway(StripeClient $client): CashierStripeGateway
 {
     return new class($client) extends CashierStripeGateway
     {
-        public function __construct(private readonly StripeClient $client) {}
+        public function __construct(private readonly StripeClient $client)
+        {
+            parent::__construct(new SubscriptionSnapshotMapper);
+        }
 
         protected function stripe(): StripeClient
         {
@@ -117,7 +121,7 @@ function swapGatewayFixture(): array
         ->once()
         ->with(
             'sub_swap_1',
-            (new CashierStripeGateway)->buildSwapPayload('si_1', 'price_target'),
+            (new CashierStripeGateway(app(SubscriptionSnapshotMapper::class)))->buildSwapPayload('si_1', 'price_target'),
             ['idempotency_key' => 'change-plan:tok:standard'],
         )
         ->andReturn(swapRemoteSubscription('sub_swap_1', [swapRemoteItem('si_1', 'price_target')]));
diff --git a/tests/Feature/Onboarding/OnboardingCheckoutTest.php b/tests/Feature/Onboarding/OnboardingCheckoutTest.php
index 70df116..21ec0cb 100644
--- a/tests/Feature/Onboarding/OnboardingCheckoutTest.php
+++ b/tests/Feature/Onboarding/OnboardingCheckoutTest.php
@@ -7,6 +7,7 @@
 use App\Models\Organization;
 use App\Models\User;
 use App\Services\Billing\TicketPricingService;
+use Carbon\CarbonImmutable;
 use Inertia\Testing\AssertableInertia as Assert;
 
 /*
@@ -155,3 +156,43 @@ function expiredCheckoutOrganizationWithOwner(): array
 test('未認証は login へ', function (): void {
     $this->get('/onboarding/checkout')->assertRedirect('/login');
 });
+
+// ── 支払い未解決の契約がある組織はプラン選択ではなく課金画面へ逃がす ──
+//
+// 新規契約を作らせても二重請求になるだけで、利用者の次の一手は「支払い方法の更新」である。
+// 判定は BillingAccess と同じ述語 (SubscriptionState::hasUnsettledPayment) 1 つだけを見る。
+
+/** 支払い未解決 (猶予切れ past_due) の組織 + owner。 */
+function unsettledPaymentOrganizationWithOwner(string $status = 'past_due'): array
+{
+    config()->set('billing.payment_grace_days', 14);
+    [$organization, $owner] = createOrganizationWithOwner(grandfatherFreePlan: false);
+    $subscription = contractPaidPlan($organization, status: $status);
+    $subscription->forceFill([
+        'past_due_since' => CarbonImmutable::now()->subDays(15),
+    ])->save();
+
+    return [$organization->fresh(), $owner];
+}
+
+test('支払い未解決 + manageBilling は billing.index へ redirect (プラン選択を出さない)', function (string $status): void {
+    [, $owner] = unsettledPaymentOrganizationWithOwner($status);
+
+    $this->actingAs($owner)->get('/onboarding/checkout')
+        ->assertRedirect(route('billing.index'));
+})->with(['past_due', 'unpaid']);
+
+test('支払い未解決 + manageBilling なし member は従来どおり billing-required へ (判定順序が変わらない)', function (): void {
+    [$organization] = unsettledPaymentOrganizationWithOwner();
+    $member = attachOrganizationMember($organization);
+    $member->forceFill(['current_organization_id' => $organization->id])->save();
+
+    $this->actingAs($member)->get('/onboarding/checkout')
+        ->assertRedirect(route('onboarding.billing-required'));
+});
+
+test('逃がし先の billing.index は課金ゲートの外なので詰まない (200 で描画される)', function (): void {
+    [, $owner] = unsettledPaymentOrganizationWithOwner();
+
+    $this->actingAs($owner)->get(route('billing.index'))->assertOk();
+});
diff --git a/tests/Support/FakeStripeGateway.php b/tests/Support/FakeStripeGateway.php
index de35af7..a5e8870 100644
--- a/tests/Support/FakeStripeGateway.php
+++ b/tests/Support/FakeStripeGateway.php
@@ -6,7 +6,9 @@
 
 use App\DataTransferObjects\Billing\CreatedCheckoutSession;
 use App\DataTransferObjects\Billing\ExternalBillingRedirect;
+use App\DataTransferObjects\Billing\RemoteSubscriptionState;
 use App\Enums\Billing\SubscriptionSwapOutcome;
+use App\Exceptions\Billing\SubscriptionLookupFailedException;
 use App\Models\Organization;
 use App\Services\Billing\Contracts\StripeGatewayInterface;
 use Carbon\CarbonImmutable;
@@ -19,6 +21,8 @@
  *   session id / URL を返す (Stripe の idempotency replay と同じ収束特性を再現)
  * - expireCheckoutSession: 呼び出しを記録し、$expireResult を返す ($failOnExpire で throw)
  * - swapSubscriptionPrices: 呼び出しを記録し、$swapOutcome を返す (プラン変更 = 実 Stripe に出ない)
+ * - retrieveSubscriptionState: 照会を記録し、$remoteStates に仕込んだ観測結果を返す
+ *   (未設定は null = 未検出。$failOnLookup で照会失敗を再現する)
  */
 final class FakeStripeGateway implements StripeGatewayInterface
 {
@@ -90,6 +94,32 @@ public function swapSubscriptionPrices(
         return $this->swapOutcome;
     }
 
+    /** @var array<string, RemoteSubscriptionState|null> stripe_id => 観測結果 (未設定は null = 未検出) */
+    public array $remoteStates = [];
+
+    /** @var list<string> retrieveSubscriptionState を要求された stripe_id */
+    public array $lookedUp = [];
+
+    /** true にすると retrieveSubscriptionState が SubscriptionLookupFailedException を投げる */
+    public bool $failOnLookup = false;
+
+    /** 照会 1 回あたりに進める時計 (秒)。実行時間上限の検証で使う (0 = 進めない)。 */
+    public int $lookupElapsedSeconds = 0;
+
+    public function retrieveSubscriptionState(string $stripeSubscriptionId): ?RemoteSubscriptionState
+    {
+        $this->lookedUp[] = $stripeSubscriptionId;
+        if ($this->lookupElapsedSeconds > 0) {
+            // 実 Stripe の応答待ちを時計の進行として再現する (実際には待たない)。
+            CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds($this->lookupElapsedSeconds));
+        }
+        if ($this->failOnLookup) {
+            throw new SubscriptionLookupFailedException('fake stripe: lookup failed');
+        }
+
+        return $this->remoteStates[$stripeSubscriptionId] ?? null;
+    }
+
     public function expireCheckoutSession(string $stripeSessionId): string
     {
         if ($this->failOnExpire) {
diff --git a/tests/Unit/Billing/SubscriptionCheckoutPayloadInvariantTest.php b/tests/Unit/Billing/SubscriptionCheckoutPayloadInvariantTest.php
index 4522d86..e879ce9 100644
--- a/tests/Unit/Billing/SubscriptionCheckoutPayloadInvariantTest.php
+++ b/tests/Unit/Billing/SubscriptionCheckoutPayloadInvariantTest.php
@@ -4,6 +4,7 @@
 
 use App\Models\Organization;
 use App\Services\Billing\CashierStripeGateway;
+use App\Services\Billing\SubscriptionSnapshotMapper;
 
 /*
  * P9: subscription Checkout Session payload の invariant。**payload 変更の唯一の入口**。
@@ -17,11 +18,17 @@
  * - promo / automatic tax を含まない (金額照合の前提を壊さない = チケット側と同一方針)。
  */
 
+/** payload builder は純関数だが gateway のメソッドなので、依存 (写像) を渡して素で組む。 */
+function checkoutPayloadGateway(): CashierStripeGateway
+{
+    return new CashierStripeGateway(new SubscriptionSnapshotMapper);
+}
+
 test('payload は mode=subscription で customer / line_items / metadata を含む', function (): void {
     $organization = Organization::factory()->make();
     $organization->stripe_id = 'cus_payload_1';
 
-    $payload = (new CashierStripeGateway)->buildSubscriptionSessionPayload(
+    $payload = checkoutPayloadGateway()->buildSubscriptionSessionPayload(
         $organization,
         'price_standard',
         'https://app.test/billing?session_id={CHECKOUT_SESSION_ID}',
@@ -41,7 +48,7 @@
     $organization = Organization::factory()->make();
     $organization->stripe_id = 'cus_payload_1';
 
-    $payload = (new CashierStripeGateway)->buildSubscriptionSessionPayload(
+    $payload = checkoutPayloadGateway()->buildSubscriptionSessionPayload(
         $organization, 'price_standard', 'https://a.test', 'https://b.test', [],
     );
 
@@ -55,7 +62,7 @@
     $organization = Organization::factory()->make();
     $organization->stripe_id = 'cus_payload_1';
 
-    $payload = (new CashierStripeGateway)->buildSubscriptionSessionPayload(
+    $payload = checkoutPayloadGateway()->buildSubscriptionSessionPayload(
         $organization, 'price_standard', 'https://a.test', 'https://b.test', [],
     );
 
@@ -64,7 +71,7 @@
 });
 
 test('Stripe customer 未作成の組織では fail-fast する', function (): void {
-    (new CashierStripeGateway)->buildSubscriptionSessionPayload(
+    checkoutPayloadGateway()->buildSubscriptionSessionPayload(
         Organization::factory()->make(), 'price_standard', 'https://a.test', 'https://b.test', [],
     );
 })->throws(InvalidArgumentException::class);
diff --git a/tests/Unit/Billing/SubscriptionSwapPayloadInvariantTest.php b/tests/Unit/Billing/SubscriptionSwapPayloadInvariantTest.php
index ff092d8..81bb454 100644
--- a/tests/Unit/Billing/SubscriptionSwapPayloadInvariantTest.php
+++ b/tests/Unit/Billing/SubscriptionSwapPayloadInvariantTest.php
@@ -3,6 +3,7 @@
 declare(strict_types=1);
 
 use App\Services\Billing\CashierStripeGateway;
+use App\Services\Billing\SubscriptionSnapshotMapper;
 
 /*
  * F-3-01: subscription swap (プラン変更) payload の invariant。**payload 変更の唯一の入口**。
@@ -14,8 +15,14 @@
  *   (即時請求・trial 再開の誘発を構造的に避ける)。
  */
 
+/** payload builder は純関数だが gateway のメソッドなので、依存 (写像) を渡して素で組む。 */
+function swapPayloadGateway(): CashierStripeGateway
+{
+    return new CashierStripeGateway(new SubscriptionSnapshotMapper);
+}
+
 test('payload は既存 item id と price / quantity=1 と create_prorations だけを返す', function (): void {
-    $payload = (new CashierStripeGateway)->buildSwapPayload('si_existing_1', 'price_standard');
+    $payload = swapPayloadGateway()->buildSwapPayload('si_existing_1', 'price_standard');
 
     expect($payload)->toBe([
         'items' => [
@@ -28,7 +35,7 @@
 });
 
 test('payload に即時請求・trial 再開を誘発するパラメータを含めない', function (): void {
-    $payload = (new CashierStripeGateway)->buildSwapPayload('si_existing_1', 'price_standard');
+    $payload = swapPayloadGateway()->buildSwapPayload('si_existing_1', 'price_standard');
 
     expect($payload)->not->toHaveKey('billing_cycle_anchor');
     expect($payload)->not->toHaveKey('trial_end');
@@ -38,9 +45,9 @@
 });
 
 test('空の item id は fail-fast する', function (): void {
-    (new CashierStripeGateway)->buildSwapPayload('', 'price_standard');
+    swapPayloadGateway()->buildSwapPayload('', 'price_standard');
 })->throws(InvalidArgumentException::class);
 
 test('空の price id は fail-fast する', function (): void {
-    (new CashierStripeGateway)->buildSwapPayload('si_existing_1', '');
+    swapPayloadGateway()->buildSwapPayload('si_existing_1', '');
 })->throws(InvalidArgumentException::class);
```

---

## テスト結果

- `composer test`: 4705 tests, 4703 passed, 2 skipped, 0 failed (assertions 20013)
- `composer phpstan`: No errors (level 10)
- `vendor/bin/pint --test`: passed
- `pnpm lint` / `pnpm typecheck` / `pnpm test` (1450 passed) / `pnpm build`: 全 green
- `pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages` (106 passed): 全 green

## 補足 (レビュー時に知っておくべき文脈)

- `docs/architecture.md` と `docs/billing-gate-inversion-runbook.md` にも節を追加しているが、
  diff が長くなるため上の diff からは docs/ を除いてある (内容は施策 12 の記載どおり)。
- 詳細設計との差異は 1 点だけ: migration の連番を `000100/000110` から `000200/000210` へ
  ずらした (同日同連番の既存 migration があるため)。設計書側も同じコミットで訂正済み。
