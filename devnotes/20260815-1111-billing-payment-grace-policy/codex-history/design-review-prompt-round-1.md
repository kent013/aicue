## 使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

## 思考原則

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

## セキュリティ不変条件(アプリ都合で緩めない)

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

あなたは経験豊富なWebアプリケーションアーキテクトです。Laravel + Svelte アプリケーション改善の詳細設計をレビューしてください。

【前提環境】
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- PHPStan level 10
- Pestテストフレームワーク
- DTO + JsonResource パターン
- Laratrust RBAC（Organization → Team → Project階層）
- 課金は Laravel Cashier (Stripe)

【レビュー観点】
1. コードの正確性（ロジックエラー、エッジケース、null安全性）
2. 既存コードとの整合性（命名規約、パターン、API）
3. PHPStan level 10 適合性（型安全性、generics、Assert使用）
4. テスト計画の網羅性（各施策にPestテスト、RefreshDatabaseグローバル適用に従う）
5. DTO/JsonResource パターンの遵守
6. Inertia Props vs API Responseの使い分け
7. 副作用・後退リスク
8. 波及変更の網羅性（TypeScript型定義、API Resource、テストが変更対象に含まれているか）
9. セキュリティ（認可チェック、入力バリデーション、OWASP Top 10、AGENTS.md のセキュリティ不変条件）
10. DESIGN.md準拠 / 11. Atomic Design準拠 (本設計は UI 変更を含まないため該当なしなら「該当なし」と書く)

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

【本件の文脈】
家系共通の機能台帳 lctl の裁定 AG-035 の確定項目 (5) 支払い失敗の猶予を期限として持つ /
(6) 決済事業者との定期的な突き合わせ、の 2 点の欠落を埋める設計である。
概念設計は同じセッションで 5 ラウンドの合議を経て APPROVED になっている。

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
| 1 | 猶予の起点列を追加する | `database/migrations/2026_08_15_000100_*`, `2026_08_15_000110_*`, `app/Models/Billing/Subscription.php` | High |
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

- 新規: `database/migrations/2026_08_15_000100_add_past_due_since_to_subscriptions.php`
- 新規: `database/migrations/2026_08_15_000110_backfill_past_due_since_on_subscriptions.php`
- 変更: `app/Models/Billing/Subscription.php` (docblock の `@property` と `casts()`)

### 波及変更

- TypeScript 型定義: なし (props に出さない)
- API Resource/DTO: なし
- テストファイル: `tests/Feature/Billing/SubscriptionSnapshotSyncTest.php` に打刻の検証を追加 (施策 5)

### 変更後コード

```php
// 2026_08_15_000100_add_past_due_since_to_subscriptions.php
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
// 2026_08_15_000110_backfill_past_due_since_on_subscriptions.php
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

- TypeScript 型定義: なし (`EntitlementDeniedReason` は Inertia props に露出していない。
  `BillingAccess` は `->entitled` しか読まず、画面文言は
  `RequireActiveSubscription::BLOCKED_MESSAGE` と着地ページが持つ)
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

- 新規: `app/DataTransferObjects/Billing/RemoteSubscriptionState.php`
- 新規: `app/Exceptions/Billing/SubscriptionLookupFailedException.php`
- 変更: `app/Services/Billing/Contracts/StripeGatewayInterface.php`
- 変更: `app/Services/Billing/CashierStripeGateway.php`
- 変更: `app/Services/Billing/Fakes/FakeStripeGateway.php` (bug-hunt / fake 環境)
- 変更: `tests/Support/FakeStripeGateway.php` (テスト spy)

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

        return new RemoteSubscriptionState(
            snapshot: new SubscriptionSnapshot(
                stripeId: $remote->id,
                status: $remote->status,
                basePriceId: /* items.data.0.price.id (webhook と同じ位置から取る) */,
                baseQuantity: /* items.data.0.quantity */,
                currentPeriodEnd: /* items.data.0.current_period_end ?? top-level */,
                trialEndsAt: /* trial_end */,
                endsAt: /* ended_at ?? cancel_at */,
            ),
            hasPaymentMethod: $this->observePaymentMethod($remote),
        );
    }

    /**
     * 決済手段の観測 (三値)。**true と「観測できなかった」を潰さない**。
     *  - default_payment_method / default_source のどちらかが取れた → true
     *  - どちらも空 → null (顧客既定を使う契約もあるため false と断定しない)
     */
    private function observePaymentMethod(StripeSubscription $remote): ?bool
    {
        ...
        return $found ? true : null;
    }
```

> 実装メモ: payload からの取り出し位置 (`items.data.0.*` / 新旧 API の `current_period_end`) は
> `StripeWebhookProcessor::syncSubscriptionState` / `periodEnd()` と**同じ規則**にする。
> 両者がずれると「webhook では動くが突き合わせでは status しか合わない」状態になるため、
> 実装時は webhook 側の抽出規則をそのまま写す (テストで両経路の結果一致を固定する)。

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

- [ ] 新規 `tests/Feature/Billing/SubscriptionLookupGatewayTest.php`
      (既存 `SubscriptionSwapGatewayTest` と同じ subclass による `stripe()` 差し替え):
  - [ ] 正常応答 → `SubscriptionSnapshot` の 7 フィールドが webhook 経路と同じ値になる
        (同じ payload 形から作った webhook 側の結果と突き合わせる)
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
     * 収束が要るのは次の 3 つのいずれか:
     *   1. status がローカルと違う (両方向)
     *   2. past_due なのに猶予起点が NULL (打刻漏れの修復)
     *   3. Stripe 側で決済手段を観測できたのにローカルが false (**true 方向のみ**)
     */
    public function needsSnapshotConvergence(
        Subscription $sub,
        SubscriptionSnapshot $snap,
        ?bool $hasPaymentMethod,
    ): bool {
        if ($sub->stripe_status !== $snap->status) {
            return true;
        }
        if ($snap->status === 'past_due' && $sub->past_due_since === null) {
            return true;
        }

        return $hasPaymentMethod === true && ! $sub->has_payment_method;
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

    /** 排他ロックの有効期限 (秒)。実行時間上限より必ず長くする。 */
    private const int LOCK_SECONDS = 900;

    /** 走査の実行時間上限 (秒)。chunk の切れ目で超過を検査して打ち切る。 */
    private const int TIME_BUDGET_SECONDS = 600;

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
                if (CarbonImmutable::now()->greaterThan($deadline)) {
                    $timedOut = true;

                    return false; // chunk の切れ目で打ち切る (ロック期限を跨がない)
                }

                foreach ($subs as $sub) {
                    $checked++;
                    try {
                        $remote = $gateway->retrieveSubscriptionState($sub->stripe_id);
                    } catch (SubscriptionLookupFailedException $e) {
                        $failed++;
                        $failedIds[] = $sub->organization_id;
                        // 例外 message は載せない (外部生成の可変文字列)。クラス名だけ。
                        Log::warning('reconcile-subscription-status: lookup failed', [
                            'organization_id' => $sub->organization_id,
                            'error_class' => $e->getPrevious()::class,
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
- [ ] 実行時間上限の検査は `travelTo` で `deadline` を跨がせ、2 chunk 目に入らず FAILURE に
      なることを固定する

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

    $finder = Finder::create()
        ->in(base_path('app'))
        ->files()
        ->name('*.php')
        ->contains('/([\'"])past_due_since\1\s*=>|->past_due_since\s*=[^=]/');

    $violations = [];
    foreach ($finder as $file) {
        $relative = str_replace(base_path().'/', '', (string) $file->getRealPath());
        if (! in_array($relative, $allowlist, true)) {
            $violations[] = $relative;
        }
    }

    expect($violations)->toBe([], 'past_due_since の書き込みは SubscriptionService 経由に限定してください: '.implode(', ', $violations));
});

test('走査が空振りしていない (単一 writer 自身は検出される)', function (): void {
    $finder = Finder::create()
        ->in(base_path('app/Services/Billing'))
        ->files()
        ->name('SubscriptionService.php')
        ->contains('/([\'"])past_due_since\1\s*=>/');

    expect(iterator_count($finder))->toBe(1);
});
```

### テスト計画

- 本施策自体がテストである。**負のコントロール** (2 本目) を必ず置く
  (正規表現が空振りしていると検査が常に緑になるため。既存の
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

## 関連する現行コード

### app/Enums/Billing/SubscriptionState.php
```php
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
```
### app/Enums/Billing/EntitlementDeniedReason.php
```php
<?php

declare(strict_types=1);

namespace App\Enums\Billing;

/**
 * entitlement (利用可否) を否定する理由。
 *
 * `SubscriptionService::deriveEntitlement` が `entitled=false` のとき必ず付随させる。
 * フロントは reason 別に状態説明 (paused / trial 終了 & PM 無 / 請求失敗) を出し分ける。
 *
 * 注意: `PastDue` (state=PastDue) かつ PM 有りは entitled=true (請求失敗中も利用継続) のため、
 * ここに PastDue を「利用継続中」の理由としては置かない。past_due で entitled=false になる
 * のは PM 無し past_due のみで、それは trial 終了 & カード無しとして
 * `TrialEndedWithoutPaymentMethod` で表現する (trial 終了後の paused と区別)。
 */
enum EntitlementDeniedReason: string
{
    /** subscription が無い / Inactive (canceled・unpaid・incomplete 等)。 */
    case NoActiveSubscription = 'no_active_subscription';

    /** trial 終了後カード未登録で Stripe が paused にした (read-only)。 */
    case TrialEndedWithoutPaymentMethod = 'trial_ended_without_payment_method';

    /** Stripe status=paused (= 上記の確定状態)。 */
    case Paused = 'paused';
}
```
### app/Services/Billing/SubscriptionService.php (抜粋: L45-244 / L338-400)
```php
/**
 * Subscription (契約) の状態管理サービス。
 *
 * Stripe への I/O は Gateway 経由のみで、本クラスは entitlement の導出・webhook 受信時の
 * 状態同期・checkout の前処理に責務を絞る。
 */
class SubscriptionService
{
    /** organizations.plan_code を同期する subscription status (それ以外では既存値を維持する) */
    private const array ACTIVE_SUBSCRIPTION_STATUSES = ['active', 'trialing'];

    public function __construct(
        private readonly StripeGatewayInterface $gateway,
        private readonly TicketLedgerService $tickets,
    ) {}

    /**
     * paid サブスク成立 (customer.subscription.created) 時の初回無償チケット付与。
     *
     * 付与は「org 単位で生涯 1 回」: 真実源は `organizations.signup_tickets_granted_at` で、
     * org 行 lock 下の条件付き UPDATE を先取できた経路のみ grant する
     * (free 有効化経路 PersonalPlanService::activate と共用の真実源・同型の claim パターン)。
     * 解約→再契約 (別 subscription id) でも marker が立っているため再付与されない。
     *
     * claim と grant は同一 transaction に閉じる。grant が失敗したら marker ごと rollback され、
     * 「marker だけ立って永久に付与されない org」を作らない。
     *
     * 冪等キー `signup_grant:{stripeSubId}` は監査上の由来表現であり、二重付与の防波堤は
     * marker (主) と ticket_ledger_entries の部分 UNIQUE index
     * (organization_id WHERE idempotency_key LIKE 'signup_grant:%') (保険) の二重防御。
     *
     * subscription 行側の marker は持たない (D30): AI-CUE では subscriptions 行の作成は Cashier の
     * WebhookController が担い、本経路 (WebhookReceived listener) はそれより先に走るため
     * created 時点で行が存在せず、列を足しても恒久 NULL にしかならない。
     */
    public function grantSignupInitialTickets(Organization $org, string $stripeSubId): void
    {
        Assert::stringNotEmpty($stripeSubId);

        DB::transaction(function () use ($org, $stripeSubId): void {
            // org 行 lock で free 有効化経路 (PersonalPlanService::activate) との付与競合を直列化。
            DB::table('organizations')->where('id', $org->getKey())->lockForUpdate()->get();

            $claimed = DB::table('organizations')
                ->where('id', $org->getKey())
                ->whereNull('signup_tickets_granted_at')
                ->update(['signup_tickets_granted_at' => CarbonImmutable::now()]);

            if ($claimed === 1) {
                $this->tickets->grantSignupGrant($org, 'signup_grant:'.$stripeSubId);
            }
        });
    }

    /**
     * subscription の利用可否 (entitlement) を確定する **唯一の経路**。
     *
     * `SubscriptionState::fromSubscription`/`grantsAccess` を直接参照して可否を決めてはならない。
     * 本メソッドが state + PM 有無 + trial_ends_at + Stripe status snapshot を合成して最終確定する。
     *
     *   entitled = state.grantsAccess()
     *              AND NOT (trial_ends_at <= now AND !has_payment_method)   // trial 終了 & カード無し
     *              AND status != paused                                     // Stripe 確定の read-only
     *
     * - Paused: grantsAccess=false で否定 (reason=Paused)。
     * - trial 終了 & PM 無し: webhook 前 (Stripe がまだ paused 化していない) でも先回りで否定する
     *   (reason=TrialEndedWithoutPaymentMethod)。
     * - PastDue (PM 有): grantsAccess=true かつ trial 条件に該当しないため entitled=true (請求失敗中も利用継続)。
     * - PM 無し past_due (= trial 後カード無し dunning): trial_ends_at<=now & !has_payment_method で否定。
     */
    public function deriveEntitlement(Subscription $sub): SubscriptionEntitlementDto
    {
        $state = SubscriptionState::fromSubscription($sub);

        if (! $state->grantsAccess()) {
            $reason = $state === SubscriptionState::Paused
                ? EntitlementDeniedReason::Paused
                : EntitlementDeniedReason::NoActiveSubscription;

            return SubscriptionEntitlementDto::denied($state, $reason);
        }

        // trial 終了後カード未登録 → 利用不可 (webhook の paused 化前でも先回り遮断)。
        $now = CarbonImmutable::now();
        $trialEnded = $sub->trial_ends_at !== null
            && CarbonImmutable::instance($sub->trial_ends_at)->lessThanOrEqualTo($now);
        if ($trialEnded && ! $sub->has_payment_method) {
            return SubscriptionEntitlementDto::denied(
                $state,
                EntitlementDeniedReason::TrialEndedWithoutPaymentMethod,
            );
        }

        // status=paused は grantsAccess で既に弾かれているが、防御的に二重で確認する。
        if ($sub->stripe_status === 'paused') {
            return SubscriptionEntitlementDto::denied($state, EntitlementDeniedReason::Paused);
        }

        return SubscriptionEntitlementDto::granted($state);
    }

    /**
     * Webhook (customer.subscription.created/updated/deleted) 受信時、Stripe サブスクの
     * 最新スナップショットをローカル状態へ反映する **唯一の書込経路**。
     *
     * 列の所在差の吸収 (aigenba は subscriptions.plan_code に書くが、本アプリの権威は
     * organizations.plan_code):
     * - (a) base Price から plan が解決でき **かつ** status が active/trialing のときだけ
     *   `organizations.plan_code` を同期する (未知 Price は受理のみ)。
     * - (b) `subscriptions` 行が存在すれば lockForUpdate の上で Stripe 由来の列を更新する。
     *   **行の作成は行わない** (作成の権威は Cashier の WebhookController。WebhookReceived は
     *   Cashier のハンドラより先に発火するため created 時点では行が無いことがあり、ここで
     *   先に作ると Cashier 側の subscription_items 生成が永久に skip される)。
     * - (c) `$terminated` (customer.subscription.deleted) では `organizations.plan_code` を
     *   null に戻し、schedule ライフサイクル列を同一トランザクションで明示クリアする
     *   (「移行」ではなく「終了」。status だけ更新・schedule 残存の一時不整合を防ぐ)。
     *
     * seat drift / schedule out-of-band drift / period 巻き戻し guard は対象列
     * (additional_seats / pending_plan_code / current_period_start) が無いため移植しない。
     *
     * @param  bool  $terminated  終了系 (deleted) のとき true。
     */
    public function applySubscriptionSnapshot(
        Organization $org,
        SubscriptionSnapshot $snap,
        bool $terminated = false,
    ): void {
        DB::transaction(function () use ($org, $snap, $terminated): void {
            $sub = Subscription::query()
                ->where('stripe_id', $snap->stripeId)
                ->lockForUpdate()
                ->first();

            if ($sub instanceof Subscription) {
                $attrs = [
                    'stripe_status' => $snap->status,
                    'stripe_price' => $snap->basePriceId,
                    'quantity' => $snap->baseQuantity,
                    'trial_ends_at' => $snap->trialEndsAt,
                    'ends_at' => $snap->endsAt,
                ];

                // period 欠落 payload では既存の current_period_end を維持する (renewal reminder の
                // 真実源を null で塗り潰さない = 現行 syncSubscriptionPeriod の早期 return と同値)。
                if ($snap->currentPeriodEnd !== null) {
                    $attrs['current_period_end'] = $snap->currentPeriodEnd;
                }

                if ($terminated) {
                    $attrs['stripe_schedule_id'] = null;
                    $attrs['schedule_setup_status'] = ScheduleSetupStatus::None;
                }

                $sub->forceFill($attrs)->save();
            }

            if ($terminated) {
                // plan_code は状態キー: webhook 同期でのみ明示代入する
                $org->plan_code = null;
                $org->save();

                return;
            }

            $planCode = $this->resolvePlanCodeFromPriceId($snap->basePriceId);
            if ($planCode === null || ! in_array($snap->status, self::ACTIVE_SUBSCRIPTION_STATUSES, true)) {
                return; // 未知 Price / 非 active 系は受理のみ (既存 plan_code を維持)
            }

            $org->plan_code = $planCode->value;
            $org->save();
        });
    }

    /**
     * has_payment_method を subscription に記録する **独立 monotonic writer**。
     *
     * `applySubscriptionSnapshot` の中に置かない理由: 早期 return 経路 (行不在等) と無関係に
     * 「決済手段の有無」だけを独立した契約として書くため。
     *
     * - has_payment_method: monotonic (true から false に戻さない)。Stripe の payload は
     *   default_payment_method を expand しない周期があり、false 側を信じると trial 終了後の
     *   遮断判定 (deriveEntitlement) が誤発火するため。
     * - 行不在 (Cashier の WebhookController が行を作る前の customer.subscription.created 等) は
     *   早期 return で no-op。最初の権威 PM 書込は最初の customer.subscription.updated に載る。
     */
    public function recordPaymentMethodSnapshot(Subscription $sub, bool $hasPaymentMethod): void
    {
        DB::transaction(function () use ($sub, $hasPaymentMethod): void {
            $fresh = Subscription::query()->lockForUpdate()->find($sub->id);
            if (! $fresh instanceof Subscription) {
                return;
            }

            // PM 有無 (monotonic: 一度 true になったら下げない)。
            if ($hasPaymentMethod && ! $fresh->has_payment_method) {
                $fresh->forceFill(['has_payment_method' => true])->save();
            }
        });
    }
...
    private function startCheckoutLocked(
        Organization $org,
        User $user,
        Plan $plan,
        PlanPrice $basePrice,
        string $successUrl,
        string $cancelUrl,
        string $attemptToken,
        ?SignupFundingChoice $funding,
    ): CheckoutSessionDto {
        // lock closure 先頭で基準時刻を 1 回だけ取り、段 2/3/4 の live 判定を共有述語へ通す (C-1)。
        $now = CarbonImmutable::now();
        $threshold = BillingCheckoutSession::staleThresholdAt($now);

        // 段 1: 既存 subscription guard
        $existing = $org->subscription('default');
        Assert::true(
            ! $existing instanceof Subscription || ! $existing->valid(),
            '既に有効なサブスクリプションがあります。プラン変更をご利用ください。'
        );

        // 段 2: 同 token 行 (intent=subscription_start スコープ)
        $sameAttempt = $this->subscriptionAttemptQuery($org)
            ->where('attempt_token', $attemptToken)
            ->latest('id')
            ->first();

        if ($sameAttempt instanceof BillingCheckoutSession) {
            // 要件 6 (N-1): plan 不一致は replay より **前** に判定する。
            if ($sameAttempt->plan_code !== $plan->code) {
                throw new SubscriptionAttemptPlanMismatchException(
                    'お手続きの内容が変わりました。画面を再読み込みして選び直してください。',
                );
            }
            if ($this->isReplayableCheckout($sameAttempt, $now)) {
                return $this->replayCheckout($sameAttempt);
            }

            throw new StaleCheckoutAttemptException(
                '契約手続きの有効期限が切れました。画面を再読み込みして再試行してください。',
            );
        }

        // 段 3: 同 plan の live pending dedup (**org-wide**。subscription は org 単位の singleton
        // であり、actor scope にすると同 org の 2 人が同時に live Checkout を持てて二重契約を許す)。
        $pending = $this->subscriptionAttemptQuery($org)
            ->where('plan_code', $plan->code)
            ->where('status', CheckoutSessionStatus::Pending->value)
            ->where('created_at', '>=', $threshold)
            ->latest('id')
            ->first();

        if ($pending instanceof BillingCheckoutSession) {
            return new CheckoutSessionDto(
                stripeSessionId: $pending->stripe_session_id,
                url: null,
                intent: CheckoutIntent::SubscriptionStart->value,
                planCode: $plan->code,
            );
        }

        // 段 4: 別 plan の live pending を expire する (stale な別 plan 行は Stripe 側で既に
        // expire 済みのため照会せず放置する = 無駄な外部 API を撃たない)。
```
### app/Services/Billing/BillingAccess.php
```php
<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\Billing\OnboardingBillingState;
use App\Enums\CheckoutSessionStatus;
use App\Models\Billing\BillingCheckoutSession;
use App\Models\Billing\Subscription;
use App\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * Organization の課金状態を「流入制御目線」で判定する責務。
 *
 * **課金による利用可否の判定は必ず本クラスを経由する** (middleware / controller /
 * service での subscription 直参照は禁止)。OrganizationPolicy 等の user action 認可とは
 * 責務分離する (Policy は user × organization、本 service は organization × subscription state)。
 *
 * 利用可否は `SubscriptionState` 単体ではなく `SubscriptionService::deriveEntitlement` で
 * 確定する (PM 有無 / trial 終了 / paused / past_due を合成)。
 *
 * **`plan_code` は entitlement 判定に一切使わない** (quota の解決キーでしかない)。かつては
 * 「plan_code null = fallback free プラン = 支払い不要 tier として許可」していたが
 * (devnotes/20260712-0927-bugfix-billing-free-access。歴史として保持する)、ゲート反転で
 * 無料枠は `organizations.free_plan_code = 'personal'` の明示申告 (`ActiveFreePlan`) として
 * 表現するようになった。plan_code が null であること自体は許可の理由にならない。
 */
class BillingAccess
{
    public function __construct(
        private readonly SubscriptionService $subscriptions,
    ) {}

    /**
     * 組織が業務機能を利用してよいか (billing entitlement)。
     *
     * 判定は `state()->grantsAccess()` の一本 (= 無料枠は `ActiveFreePlan`、有償は
     * `Subscribed` でのみ許可)。移行 OR (`plan_code === null` を通す 1 行) はゲート反転で
     * 削除済み — 既存の未契約組織は grandfathering backfill が `free_plan_code = 'personal'`
     * を書いて `ActiveFreePlan` として許可されるため、締め出しは発生しない。
     */
    public function hasActiveAccess(Organization $organization): bool
    {
        return $this->state($organization)->grantsAccess();
    }

    /**
     * 流入制御目線の課金状態。**`plan_code` を一切見ない** (entitlement は subscription /
     * free_plan_code / checkout session から導出する)。
     *
     * 読み取り経路のため **DB 書き込みをしない**。stale な pending checkout は in-memory で
     * expired 扱いにしてアクセス判定の整合性を保ち、実 DB の expired 化は sweeper に委ねる
     * (require.subscription が付く多数の GET 経路で毎回 UPDATE が走る副作用を排除する)。
     */
    public function state(Organization $organization): OnboardingBillingState
    {
        $sub = $organization->subscription('default');
        $entitled = $sub instanceof Subscription
            && $this->subscriptions->deriveEntitlement($sub)->entitled;

        // 利用可否は SubscriptionState 単体ではなく deriveEntitlement で確定する
        // (SubscriptionState::grantsAccess を直接参照しない)。
        if ($entitled) {
            return OnboardingBillingState::Subscribed;
        }

        // 現在 entitled な Stripe subscription が「ない」(行の不在ではなく entitlement で判定。
        // canceled 等の過去行が残っていてもよい = paid→free 経路) とき free entitlement を見る。
        // 判定は定数比較 (未知値は fail-closed で通さない)。entitled subscription があれば上で
        // Subscribed 優先 (free と併存しない invariant)。
        if ($organization->free_plan_code === PersonalPlanService::FREE_PLAN_CODE) {
            return OnboardingBillingState::ActiveFreePlan;
        }

        if ($sub instanceof Subscription) {
            // 利用不可 (Inactive / Paused / trial 終了 & PM 無 / PM 無 past_due) は gate を通さない
            // → ExpiredCheckout 扱い (未契約導線へ)。
            return OnboardingBillingState::ExpiredCheckout;
        }

        // live/stale の判定は BillingCheckoutSession の述語だけが定義する (P9 C-1)。
        // 閾値 literal をここに再発明しない (CheckoutLiveThresholdSingleSourceTest が機械検出)。
        $now = CarbonImmutable::now();
        /** @var Collection<int, BillingCheckoutSession> $pendingRows */
        $pendingRows = BillingCheckoutSession::query()
            ->where('organization_id', $organization->id)
            ->where('status', CheckoutSessionStatus::Pending->value)
            ->get(['id', 'status', 'created_at']);

        $hasLivePending = false;
        $hasStalePending = false;
        foreach ($pendingRows as $row) {
            if ($row->isLivePending($now)) {
                $hasLivePending = true;
            } else {
                $hasStalePending = true;
            }
        }

        if ($hasLivePending) {
            return OnboardingBillingState::PendingCheckout;
        }

        $hasExpired = $hasStalePending || BillingCheckoutSession::query()
            ->where('organization_id', $organization->id)
            ->whereIn('status', [
                CheckoutSessionStatus::Expired->value,
                CheckoutSessionStatus::Failed->value,
            ])
            ->exists();

        return $hasExpired ? OnboardingBillingState::ExpiredCheckout : OnboardingBillingState::NoSubscription;
    }
}
```
### app/Models/Billing/Subscription.php
```php
<?php

declare(strict_types=1);

namespace App\Models\Billing;

use App\Enums\Billing\ScheduleSetupStatus;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Laravel\Cashier\Subscription as CashierSubscription;

/**
 * Cashier Subscription のテンプレート拡張 (AppServiceProvider の
 * Cashier::useSubscriptionModel で差し替え登録)。
 *
 * 追加列:
 * - current_period_end: 次回更新日時 (renewal reminder の真実源。
 *   StripeWebhookProcessor が customer.subscription.created/updated から同期する)
 * - stripe_schedule_id / schedule_setup_status: Subscription Schedule の
 *   2 段 API call (create → update phases) の部分完了追跡
 *   (billing:reconcile-schedules が復旧する。ScheduleSetupStatus 参照)
 * - has_payment_method: 決済手段が登録済みか (monotonic snapshot。true から false へ戻さない)。
 *   SubscriptionService::deriveEntitlement が trial 終了後の遮断判定に使う
 *
 * schedule 列は状態キーのため markSchedule* / clearSchedule 経由でのみ変更する。
 *
 * @property int $id
 * @property int $organization_id
 * @property string $stripe_id
 * @property string $stripe_status
 * @property bool $has_payment_method
 * @property Carbon|null $current_period_end
 * @property string|null $stripe_schedule_id
 * @property ScheduleSetupStatus $schedule_setup_status
 */
class Subscription extends CashierSubscription
{
    /**
     * Cashier 既定は $guarded=[] (全開放) だが、テナント/所有権キーを payload から
     * 信頼しない不変条件 (MassAssignmentSafetyTest) に合わせて id / organization_id を
     * guard する。organization_id は billable relation (Organization->subscriptions()) が
     * FK として自動設定し、Cashier は mass-assign しないため課金経路は不変。
     *
     * @var list<string>
     */
    protected $guarded = ['id', 'organization_id'];

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** schedule 生成 (create API 成功) を記録する。phases 未設定の部分完了状態。 */
    public function markScheduleCreated(string $scheduleId): void
    {
        $this->forceFill([
            'stripe_schedule_id' => $scheduleId,
            'schedule_setup_status' => ScheduleSetupStatus::Created,
        ])->save();
    }

    /** phases 設定完了 (update API 成功) を記録する。 */
    public function markScheduleConfigured(): void
    {
        $this->forceFill([
            'schedule_setup_status' => ScheduleSetupStatus::Configured,
        ])->save();
    }

    /** schedule の解除 (release / remote 消失) を記録する。 */
    public function clearSchedule(): void
    {
        $this->forceFill([
            'stripe_schedule_id' => null,
            'schedule_setup_status' => ScheduleSetupStatus::None,
        ])->save();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'current_period_end' => 'datetime',
            'has_payment_method' => 'boolean',
            'schedule_setup_status' => ScheduleSetupStatus::class,
        ];
    }
}
```
### app/Services/Billing/SubscriptionSnapshot.php
```php
<?php

declare(strict_types=1);

namespace App\Services\Billing;

use Carbon\CarbonImmutable;

/**
 * Stripe サブスクリプションの値オブジェクト。Webhook ハンドラから SubscriptionService に渡す。
 *
 * T666 (C2): schedule ライフサイクル状態 (`stripe_schedule_id` / `schedule_setup_status`) は
 * ここに含めない。これらは Stripe subscription object に存在しない / 順序逆転 webhook で
 * 破壊的なドメインローカル状態であり、書込権威は SubscriptionService の schedule lifecycle
 * メソッド + ReconcileSubscriptionSchedules に限定する。汎用 webhook 同期
 * (`applySubscriptionSnapshot`) はこれらを触らない。
 */
final readonly class SubscriptionSnapshot
{
    public function __construct(
        public string $stripeId,
        public string $status,
        public ?string $basePriceId,
        public ?int $baseQuantity,
        public ?CarbonImmutable $currentPeriodEnd,
        public ?CarbonImmutable $trialEndsAt,
        public ?CarbonImmutable $endsAt,
    ) {}
}
```
### app/Http/Controllers/Onboarding/OnboardingController.php (抜粋 L50-105)
```php
    public function show(Request $request): Response|RedirectResponse
    {
        $organization = $this->resolveMemberCurrentOrganization($request);
        // IDOR 二重防御 (member 認可を最優先)
        Gate::authorize('view', $organization);

        $user = $request->user();
        Assert::isInstanceOf($user, User::class);

        // 判定順序は hasActiveAccess → manageBilling。契約済み non-manager が誤って
        // billing-required に飛ばないよう、先に契約状態を判定する。
        if ($this->access->hasActiveAccess($organization)) {
            return new RedirectResponse(route('billing.index'));
        }

        // 未契約 + manageBilling 権限なし → billing-required へ
        if (! Gate::allows('manageBilling', $organization)) {
            return new RedirectResponse(route('onboarding.billing-required'));
        }

        // ?plan= が来ていたら org-scoped に積み (Resolver 規約: 有効→put / 無効→forget)、
        // canonical URL へ 303 する (再読込・共有時に query が残らない)。
        // 不在なら session を破壊しない (= リロード耐性のため後段で peek する)。
        if ($request->has('plan')) {
            $this->intendedPlanResolver->rememberForOrganizationFromQuery($request, $organization);

            return new RedirectResponse(route('onboarding.checkout'), 303);
        }

        $dto = new OnboardingCheckoutDto(
            plans: $this->selectablePlans(),
            recommendedPlanCode: PlanCode::Standard->value,
            defaultPlanCode: PlanCode::Starter->value,
            contactUrl: $this->contactUrl->resolveForSource(InquirySource::Onboarding),
            personalEligibility: $this->personalPlan->eligibility($organization, $user),
            signupGrantTickets: $this->ticketPricing->signupGrantTickets(),
            // peek = 残す (リロード耐性)。Enterprise / 未知値は正規化で null に倒れる。
            intendedPlanCode: $this->intendedPlanResolver->peekForOrganization($organization)?->value,
            // P8a (D29(i)): 事前同意の提示条件。画面表示値と recordPreConsent の記録値は
            // consentTermsFor() の単一計算源から出る (表示と記録の一致をテストで固定)。
            consentTerms: $this->autoRecharge->consentTermsFor(),
            // UI に出す資金選択は 2 択 (auto_recharge 既定 / later)。
            // `tickets` は UI から出さない (enum・validation では受理継続)。
            fundingChoices: [
                SignupFundingChoice::AutoRecharge->value,
                SignupFundingChoice::Later->value,
            ],
            // P9: 有償プラン契約 POST の冪等 token (render 単位の ULID)。
            subscriptionAttemptToken: (string) Str::ulid(),
        );

        return Inertia::render('Onboarding/Checkout', [
            'organization' => $this->organizationProps($organization),
            'pageData' => $dto->toArray(),
        ]);
    }
```
### tests/Architecture/FreePlanCodeWriteInvariantTest.php (新テストの雛形)
```php
<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/*
|--------------------------------------------------------------------------
| free_plan_code 書き込み経路の invariant
|--------------------------------------------------------------------------
|
| `organizations.free_plan_code` は課金状態 (free entitlement) を確定させる状態キーのため、
| 書き込み (array key 代入 / プロパティ代入) は PersonalPlanService に閉じる。値域
| ('personal' のみ) を DB check constraint ではなくアプリ側定数
| (PersonalPlanService::FREE_PLAN_CODE) で守る前提の機械的補助。
| 読み取り (`->free_plan_code` の比較) は対象外。
*/

test('app/ 内の free_plan_code 書き込みは PersonalPlanService に閉じる', function (): void {
    $allowlist = [
        'app/Services/Billing/PersonalPlanService.php',
    ];

    // 書き込みパターン: array key 代入 ('free_plan_code' => / "free_plan_code" =>) と
    // プロパティ代入 (->free_plan_code = 値。=== / !== 比較は除外)。
    $finder = Finder::create()
        ->in(base_path('app'))
        ->files()
        ->name('*.php')
        ->contains('/([\'"])free_plan_code\1\s*=>|->free_plan_code\s*=[^=]/');

    $violations = [];
    foreach ($finder as $file) {
        $relative = str_replace(base_path().'/', '', (string) $file->getRealPath());
        if (! in_array($relative, $allowlist, true)) {
            $violations[] = $relative;
        }
    }

    expect($violations)->toBe([], 'free_plan_code の書き込みは PersonalPlanService 経由に限定してください: '.implode(', ', $violations));
});
```
### routes/console.php (抜粋: 既存の課金 cron 配線)
```php
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
|--------------------------------------------------------------------------
| 課金 daily バッチ
|--------------------------------------------------------------------------
| - send-billing-reminders: 更新予告 (renewal 3 日前)。冪等は通知台帳の dedup_key。
| - reconcile-schedules: Subscription Schedule の部分完了 / local-remote 差分の復旧。
*/
Schedule::command('billing:send-billing-reminders')->daily()->onOneServer()->withoutOverlapping();
Schedule::command('billing:reconcile-schedules')->daily();

/*
|--------------------------------------------------------------------------
| 課金孤児の検知 (退会ガードの second layer)
|--------------------------------------------------------------------------
| 退会ガード (AccountDeletionBillingGuard) は通常経路を止めるが、webhook トランザクションと
| 同時刻に退会が commit される競合までは排他しない (subscription 行を作るのは Cashier の
| WebhookController = vendor 側で、自前 listener の排他では覆えないため)。
| 予防で漏れた分と、本機能より前から存在する孤児組織を daily で検知する。
|
| 報告契約 (通知洪水を作らない):
|   - 1 実行につき **集約して 1 回だけ** report() する
|   - 内容は **件数と organization id のみ** (組織名・メール等の PII を載せない)
|   - 未解消なら翌日も同じ内容で再報告する (抑制状態を持たない = 冪等な観測)
|
| **監視対象**: 本コマンドの report()。
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
    // RuntimeException は import しない (本ファイルは namespace 宣言が無く global 解決される。
    // 非複合 use は NoNonCompoundGlobalUseTest が禁止する)。
    report(new RuntimeException(
        'Owner 不在かつ課金中の組織を検出: count='.count($ids).' ids='.implode(',', $ids),
    ));
})->purpose('Owner 不在かつ生きた課金責務がある組織 (課金孤児) を検知して報告する');

Schedule::command('billing:detect-orphan-billing-organizations')->daily()->onOneServer();

/*
|--------------------------------------------------------------------------
| 課金 cron (オートリチャージ / P8a)
|--------------------------------------------------------------------------
| reconcile-auto-recharge: pending attempt の回収 (課金済み回収 / 再実行 / SCA リマインド /
| 期限切れ終端 / 取りこぼし起票)。
|
| **監視対象 (必須)**: webhook が MAX_PROCESSING_ATTEMPTS=8 で恒久 drop した
| 「課金済み・付与なし」を回収する**唯一の**経路であり、停止すると資金回収済み・チケット
| 未付与が滞留する。AI-CUE の運用アラート経路は report() のみのため、onFailure をそこへ繋ぐ。
| 滞留の観測点は ticket_auto_recharge_attempts.status='pending' の件数
| (docs/architecture.md の監視対象リストを参照)。
*/
Schedule::command('billing:reconcile-auto-recharge')
    ->everyFifteenMinutes()
    ->onOneServer()
    ->withoutOverlapping()
    ->onFailure(static fn () => report(new RuntimeException(
        'billing:reconcile-auto-recharge 失敗 — 資金回収済み・チケット未付与が滞留する可能性',
    )));
```
### app/Console/Commands/Billing/ReconcileAutoRechargeAttempts.php (既存コマンドの形)
```php
<?php

declare(strict_types=1);

namespace App\Console\Commands\Billing;

use App\Services\Billing\AutoRechargeService;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * P8a: オートリチャージ pending attempt のリコンサイル (5 分岐)。
 *
 * webhook の terminal ack (MAX_PROCESSING_ATTEMPTS=8 で再送打ち切り) により恒久 drop した
 * 「課金済み・付与なし」の**唯一のセーフティネット**。scheduler で 15 分毎に実行する
 * (routes/console.php。失敗は onFailure → report() で運用アラートへ載る)。
 */
final class ReconcileAutoRechargeAttempts extends Command
{
    protected $signature = 'billing:reconcile-auto-recharge';

    protected $description = 'オートリチャージの pending attempt を回収する (課金済み回収 / 再実行 / 期限切れ終端 / 取りこぼし起票)';

    public function handle(AutoRechargeService $autoRecharge): int
    {
        try {
            /** @var array{recovered_paid: int, retried: int, sca_reminded: int, expired: int, triggered: int} $stats */
            $stats = Cache::lock('billing:auto-recharge-reconcile', 300)
                ->block(5, fn (): array => $autoRecharge->reconcile());
        } catch (LockTimeoutException $e) {
            $this->error('別プロセスが billing:reconcile-auto-recharge を実行中。exit 1');
            Log::warning('ReconcileAutoRechargeAttempts: lock timeout', ['error' => $e->getMessage()]);

            return self::FAILURE;
        }

        $this->info(sprintf(
            'auto-recharge reconcile: recovered_paid=%d retried=%d sca_reminded=%d expired=%d triggered=%d',
            $stats['recovered_paid'],
            $stats['retried'],
            $stats['sca_reminded'],
            $stats['expired'],
            $stats['triggered'],
        ));

        return self::SUCCESS;
    }
}
```
