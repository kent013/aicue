【アプリの使命 (North Star) — AGENTS.md より】

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

【禁止事項 — AGENTS.md より】

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する)
9. Artifact の使用(成果物はリポジトリ内のファイルとして出力する)

【関連するセキュリティ不変条件・ドメイン規約 (AGENTS.md)】

- **課金の冪等性**: webhook は冪等マシン経由、チケットは reserve→commit/release の 2 フェーズ
- **tenant キー不信**: ownership/actor/tenant キーを payload から受け取らない
- **cross-org 不可**: クラス起点の主キー同一性クエリ (`Model::find($payloadId)` / `Model::query()->where('id', …)` / `DB::table('t')->where('id', …)`) は deny-by-default で `DirectFetchInventory` への分類登録が要る (`ModelDirectFetchInvariantTest`)
- **ドメイン規約 6 (ジョブの重複実行と結果の一回性)**: 入口の排他は best-effort で保証を担わない。結果の一回性は永続状態遷移 (条件付き UPDATE / 悲観ロック + status guard / 予約 CAS) と外部側の冪等キーが担う。terminal 化された後に旧ワーカーが状態を書き戻さないよう、更新は `where status=…` の条件付き UPDATE にする
- **ドメイン規約 11 (キュー投入の原子性)**: 業務状態の保存とキュー投入は同一トランザクション内。`afterCommit` 系の機構は 0 件で pin されている (`QueueDispatchAtomicityInventoryTest`)
- **実装規約**: `declare(strict_types=1)` + 日本語コメント。Controller は薄く (Service 委譲)、transaction は Service 内。月/年の加減算は `*NoOverflow` を明示

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

あなたは経験豊富なWebアプリケーションアーキテクトです。Laravel + Svelte アプリケーション改善の詳細設計をレビューしてください。

【前提環境】
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- PHPStan level 10
- Pestテストフレームワーク
- DTO + JsonResource パターン
- Laratrust RBAC（Organization → Team → Project階層）

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
10. DESIGN.md準拠（UI/frontend 変更を含む場合）
11. Atomic Design準拠（UI/frontend 変更を含む場合）

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

---

## 関連する現行コード (抜粋。実読で確認済み)

### `app/Services/Billing/StripeWebhookProcessor.php` — `handle()` / `claim()` / `process()`

```php
class StripeWebhookProcessor
{
    public const int MAX_PROCESSING_ATTEMPTS = 8;

    private const array GRANTING_BILLING_REASONS = ['subscription_create', 'subscription_cycle'];

    public function __construct(
        private readonly TicketLedgerService $tickets,
        private readonly BillingNotificationDispatcher $notifications,
        private readonly SubscriptionService $subscriptions,
        private readonly AutoRechargeService $autoRecharge,
    ) {}

    public function handle(WebhookReceived $event): void
    {
        /** @var array<mixed> $payload */
        $payload = $event->payload;
        $eventId = $this->stringAt($payload, 'id');
        $type = $this->stringAt($payload, 'type');
        if ($eventId === null || $type === null) {
            return; // 形式不正の payload は処理対象外 (署名検証は Cashier middleware 側)
        }

        $record = $this->claim($eventId, $type, $payload);
        if ($record === null) {
            return; // 同一 event_id を処理済み (冪等 skip)
        }

        try {
            $this->process($type, $payload);
        } catch (Throwable $exception) {
            $record->status = WebhookEventStatus::Failed;
            $record->failure_reason = $exception->getMessage();
            $record->save();
            report($exception);

            throw $exception;
        }

        $record->status = WebhookEventStatus::Processed;
        $record->failure_reason = null;
        $record->processed_at = CarbonImmutable::now();
        $record->save();
    }

    private function claim(string $eventId, string $type, array $payload): ?StripeWebhookEvent
    {
        return DB::transaction(function () use ($eventId, $type, $payload): ?StripeWebhookEvent {
            $existing = StripeWebhookEvent::query()
                ->where('event_id', $eventId)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                if ($existing->status !== WebhookEventStatus::Failed) {
                    return null;
                }
                if ($existing->attempts >= self::MAX_PROCESSING_ATTEMPTS) {
                    Log::warning('stripe webhook: terminal failure, acking to stop Stripe retries', [
                        'event_id' => $eventId, 'type' => $type, 'attempts' => $existing->attempts,
                    ]);
                    if (in_array($type, [
                        HandledStripeWebhookEvent::CheckoutSessionCompleted->value,
                        HandledStripeWebhookEvent::InvoicePaid->value,
                    ], true)) {
                        report(new RuntimeException("stripe webhook terminal failure (grant イベント): {$eventId} ({$type})"));
                    }

                    return null;
                }
                $existing->status = WebhookEventStatus::Received;
                $existing->attempts += 1;
                $existing->save();

                return $existing;
            }

            $record = new StripeWebhookEvent;
            $record->event_id = $eventId;
            $record->type = $type;
            $record->status = WebhookEventStatus::Received;
            $record->payload = $payload;
            $record->save();

            return $record;
        });
    }

    private function process(string $type, array $payload): void
    {
        match (HandledStripeWebhookEvent::tryFrom($type)) {
            HandledStripeWebhookEvent::SubscriptionCreated => $this->syncSubscriptionState($payload, HandledStripeWebhookEvent::SubscriptionCreated),
            HandledStripeWebhookEvent::SubscriptionUpdated => $this->syncSubscriptionState($payload, HandledStripeWebhookEvent::SubscriptionUpdated),
            HandledStripeWebhookEvent::SubscriptionDeleted => $this->syncSubscriptionState($payload, HandledStripeWebhookEvent::SubscriptionDeleted),
            HandledStripeWebhookEvent::InvoicePaid => $this->handleInvoicePaid($payload),
            HandledStripeWebhookEvent::ChargeRefunded => $this->clawbackRefundedTickets($payload),
            HandledStripeWebhookEvent::InvoicePaymentFailed => $this->handleInvoicePaymentFailed($payload),
            HandledStripeWebhookEvent::CheckoutSessionCompleted => $this->handleCheckoutSessionCompleted($payload),
            null => null, // 未対応 type は受理のみ (processed として記録)
        };
    }
```

`process()` の下位ハンドラの要点:
- `handleCheckoutSessionCompleted`: mode=setup → `completeAutoRechargeSetup` (自 DB 行を completed 化 + `SetDefaultPaymentMethodJob` を同一 tx で dispatch) / metadata.purpose=subscription_start → `settleSubscriptionCheckout` (`Completed` が終局で no-op。funding=auto_recharge なら打刻 + `ReuseSubscriptionPaymentMethodJob` を同一 tx で dispatch) / それ以外 → `grantPurchasedTickets` (`TicketLedgerService::grantPurchased` の `purchase:{sessionId}` UNIQUE + 行 completed 化を同一 tx)
- `handleInvoicePaid`: metadata.purpose=auto_recharge → `recordAutoRechargePaid` (`recharge:{invoiceId}` UNIQUE) / それ以外 → `grantMonthlyTickets` (`monthly:{invoiceId}` UNIQUE)
- `handleInvoicePaymentFailed`: auto_recharge は pending attempt があるときだけ `HandleAutoRechargeChargeFailureJob::dispatch` / それ以外は `BillingNotificationDispatcher::sendOnce` (台帳 dedup)
- `clawbackRefundedTickets`: `TicketLedgerService::clawbackPurchasedByPaymentIntent` (冪等キー `refund:{PI}:{target}`。実装コメントに「複数回の部分返金・順序逆転・再送に対して冪等」)
- `syncSubscriptionState`: `SubscriptionService::applySubscriptionSnapshot` (**後勝ちで上書きする。イベントの新旧を判定する列も条件も持たない**) + `grantSignupInitialTickets` (`signup_grant:{subId}` UNIQUE + `organizations.signup_tickets_granted_at` marker)

### `app/Models/Billing/StripeWebhookEvent.php`

```php
/**
 * @property int $id
 * @property string $event_id
 * @property string $type
 * @property WebhookEventStatus $status
 * @property array<mixed> $payload
 * @property int $attempts
 * @property string|null $failure_reason
 * @property CarbonImmutable|null $processed_at
 */
class StripeWebhookEvent extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => WebhookEventStatus::class,
            'payload' => 'array',
            'attempts' => 'integer',
            'processed_at' => 'immutable_datetime',
        ];
    }
}
```
`$fillable` は持たない (全カラムが processor の内部状態で明示代入のみ)。observer / booted フックは無い。

### migration (既存)

```php
Schema::create('stripe_webhook_events', function (Blueprint $table) {
    $table->id();
    $table->string('event_id')->unique();
    $table->string('type');
    $table->string('status')->default('received');
    $table->json('payload');
    $table->unsignedInteger('attempts')->default(0);
    $table->text('failure_reason')->nullable();
    $table->timestamp('processed_at')->nullable();
    $table->timestamps();
});
```

### 既存の滞留回収の作法 (`RenderJobService::recoverStale`)

```php
public function recoverStale(): int
{
    $staleIds = RenderJob::query()->where(/* status と経過時間 */)->pluck('id');

    $recovered = 0;
    foreach ($staleIds as $id) {
        $job = RenderJob::query()->whereKey($id)->first();
        if ($job === null) { continue; }
        if ($this->failJob($job, RenderErrorCode::Timeout, '...')) { $recovered++; }
    }

    return $recovered;
}
```
`DirectFetchInventory` に `'Services/Manual/RenderJobService.php#recoverStale#RenderJob.whereKey:$id#1' => DirectFetchJustificationEntry::sameMethodQuery('…')` として登録されている。

### 既存 DTO の作法 (`BillingRetentionPurgeResultDto`)

```php
final readonly class BillingRetentionPurgeResultDto
{
    public function __construct(
        public BillingRetentionTarget $target,
        public int $candidates,
        public int $processed,
        public int $failClosed,
        public int $unexpectedFailures,
        public int $expiredRemaining,
    ) {}
    // 「任意メタデータ領域 (array<string, mixed>) は持たせない」と docblock に明記
}
```

### `StripeWebhookEventPurger` (保持期限 purge)

```php
/**
 * 決済事業者の webhook 記録の purge (起算点 = processed_at)。
 *
 * 未処理 (processed_at IS NULL) のまま保持期限を超えた行は**異常**として計上するだけで
 * 消さない (取引が決着していない記録を、決着したことにして捨てない)。
 */
```

### listener 配線

`app/Providers/AppServiceProvider.php`: `Event::listen(WebhookReceived::class, StripeWebhookProcessor::class);`

### 既存テスト (回帰の観点)

- `tests/Feature/Billing/WebhookIdempotencyTest.php`: 同一 event_id の二重発火で付与 1 回 / 別 event_id の同一 invoice で冪等キーが効く / signup grant / plan_code 同期 / 失敗時に `failed` + `failure_reason` + `attempts=0` / 再送で `attempts` が増えて成功すると `failure_reason` が消える / 上限到達の terminal-ack
- `tests/Feature/Billing/TicketPurchaseWebhookTest.php`: 照合不一致で `failed`、再送で `processed`
- `tests/Feature/Billing/SubscriptionCheckoutWebhookRaceTest.php`: 行不在で `failed`
- `tests/Feature/Billing/WebhookEventSubscriptionInvariantTest.php`: handled ⊆ subscribed

---

## 詳細設計書
# 詳細設計: stripe-webhook-stuck-recovery

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した
**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、
専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**
  (撮影者・教える人のスキルに品質を依存させない)。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置(SECI)。

> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) /
> 動画合成は自前 ffmpeg / 単一 Default Project。

### 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて
   「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する)
9. Artifact の使用(成果物はリポジトリ内のファイルとして出力する)

### 特に関係するセキュリティ不変条件・ドメイン規約

- **課金の冪等性** (AGENTS.md §セキュリティ不変条件 7): webhook は冪等マシン経由
- **tenant キー不信** (同 1): ownership/actor/tenant キーを payload から受け取らない
- **クラス起点の主キー同一性クエリは deny-by-default** (同 3):
  `ModelDirectFetchInvariantTest` + `DirectFetchInventory`
- **ジョブの重複実行と結果の一回性** (§ドメイン固有規約 6): 結果の一回性は永続状態遷移
  (条件付き UPDATE / 悲観ロック + status guard) と外部側の冪等キーが担う。
  terminal 化された後に旧ワーカーが状態を書き戻さないよう、更新は `where status=…` の
  条件付き UPDATE にする
- **キュー投入の原子性** (§ドメイン固有規約 11): 業務状態の保存とキュー投入は同一トランザクション内。
  `afterCommit` 系の機構は 0 件で pin されている (本設計でも増やさない)

### コーディングルール

- **PHPStan level 10** 必須(`composer phpstan`)
- **Pest** テストフレームワーク(`composer test`)
- **RefreshDatabase** + `--parallel` 並列実行(`tests/Pest.php` でグローバル適用、
  個別 `DatabaseTransactions` 使用禁止)
- **テストデータは必ず Factory で生成**(`Model::create()` 手組み禁止)
- **DTO + JsonResource** パターン
- **アーリーリターン** 推奨
- **コードフォーマット**: `composer fix`(Pint)
- `declare(strict_types=1)` + 日本語コメント
- PHP 8.4 + Laravel 12

## 概念設計リファレンス

`devnotes/20260815-1109-stripe-webhook-stuck-recovery/conceptual-design.md`
(Codex 概念設計レビュー Round 4 で APPROVED)

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| A | 再実行安全性の分類を型で持つ | `app/Enums/Billing/WebhookReplaySafety.php` (新) / `app/Enums/Billing/HandledStripeWebhookEvent.php` | 高 |
| B | 回収待ちの状態と理由を足す | `app/Enums/Billing/WebhookEventStatus.php` / `app/Enums/Billing/WebhookRecoveryReason.php` (新) / migration (新) / `app/Models/Billing/StripeWebhookEvent.php` / `database/factories/Billing/StripeWebhookEventFactory.php` | 高 |
| C | 終局書き込みを世代付きの条件付き UPDATE にする | `app/Services/Billing/StripeWebhookProcessor.php` | 高 |
| D | 滞留回収を足す | `app/Services/Billing/StripeWebhookProcessor.php` / `app/Enums/Billing/WebhookStaleClaimOutcome.php` (新) / `app/DataTransferObjects/Billing/StaleWebhookClaimDto.php` (新) / `app/DataTransferObjects/Billing/WebhookRecoveryResultDto.php` (新) | 高 |
| E | cron を配線する | `config/billing.php` / `routes/console.php` | 高 |
| F | 誤った説明コメントとドキュメントを実態に合わせる | `app/Services/Billing/StripeWebhookProcessor.php` / `docs/architecture.md` | 高 |

**実装順序**: A → B → C → D → E → F(D が A/B/C に依存する)。
テストファーストなので、各施策のテストを先に書いて fail を確認してから実装に入る。

---

## A. 再実行安全性の分類を型で持つ

### 変更箇所

- 新規: `app/Enums/Billing/WebhookReplaySafety.php`
- 変更: `app/Enums/Billing/HandledStripeWebhookEvent.php` (メソッド追加のみ)

### 波及変更

- TypeScript 型定義: なし(フロントに露出しない)
- API Resource/DTO: なし
- テストファイル: `tests/Feature/Billing/WebhookReplaySafetyTest.php` (新規)

### 変更後コード

```php
<?php

declare(strict_types=1);

namespace App\Enums\Billing;

/**
 * 保存済み webhook payload を**再実行してよいか**の分類。
 *
 * **`SafeToReplay` の意味は「再実行しても追加の被害を生まない」に限定される。**
 * 「再実行すれば復旧する」ではない (復旧するかどうかは各ハンドラの事情による)。
 *
 * 分類の単一出典は `HandledStripeWebhookEvent::replaySafety()` の網羅 match で、
 * 滞留回収 (`StripeWebhookProcessor::recoverStale`) が自動再実行の可否に使う唯一の判断材料。
 * **ハンドラに副作用を足したら分類を再審査すること** (順序に依存する書き込みを足したら
 * `OrderSensitive` へ移す)。
 */
enum WebhookReplaySafety: string
{
    /** 再実行しても追加の被害を生まない (付与は台帳の idempotency_key UNIQUE で冪等)。 */
    case SafeToReplay = 'safe_to_replay';

    /** 順序に依存する (古い payload を後から流すと状態が巻き戻る)。 */
    case OrderSensitive = 'order_sensitive';
}
```

`HandledStripeWebhookEvent` への追加:

```php
    /**
     * 保存済み payload を再実行してよいか (滞留回収の判断材料。単一出典)。
     *
     * - `customer.subscription.*` は `SubscriptionService::applySubscriptionSnapshot` が
     *   **後勝ちで上書きする** (イベントの新旧を判定する条件を持たない) ため、古い payload を
     *   後から流すと `plan_code` / `current_period_end` / `stripe_status` が巻き戻る
     * - それ以外は付与が台帳の `idempotency_key` UNIQUE (`monthly:` / `purchase:` /
     *   `recharge:` / `refund:`) で冪等、`checkout` 行の遷移は `Completed` が終局で no-op、
     *   `invoice.payment_failed` の通知は台帳 dedup、自動購入の失敗 Job は pending 限定
     *
     * **arm を足したら分類も足す** (網羅 match のため case 追加で必ず落ちる)。
     */
    public function replaySafety(): WebhookReplaySafety
    {
        return match ($this) {
            self::SubscriptionCreated,
            self::SubscriptionUpdated,
            self::SubscriptionDeleted => WebhookReplaySafety::OrderSensitive,
            self::InvoicePaid,
            self::InvoicePaymentFailed,
            self::CheckoutSessionCompleted,
            self::ChargeRefunded => WebhookReplaySafety::SafeToReplay,
        };
    }
```

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている (`WebhookReplaySafety`)
- [x] null 安全 (enum インスタンスに対する網羅 match。`default` を書かない = case 追加で落ちる)
- [x] DTO を返している (enum。配列返却なし)
- [x] Generics の型パラメータ: 該当なし

### テスト計画

`tests/Feature/Billing/WebhookReplaySafetyTest.php` (新規)

- [ ] `HandledStripeWebhookEvent::cases()` を全件回して `replaySafety()` が例外なく値を返す
      (網羅性。case 追加時に落ちる fail-first)
- [ ] `customer.subscription.created` / `updated` / `deleted` が `OrderSensitive` である
- [ ] `invoice.paid` / `invoice.payment_failed` / `checkout.session.completed` /
      `charge.refunded` が `SafeToReplay` である
- [ ] **分類の前提の behavioral 固定**: `SafeToReplay` の付与系 2 種
      (`checkout.session.completed` (ticket_purchase) / `invoice.paid` (subscription_cycle)) は
      同じ payload を 2 回処理しても台帳の付与行が 1 行だけであること
      (= 分類の根拠である冪等キーが実際に効いていること)
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認

### リスク

- 分類が実態からずれる (ハンドラに順序依存の書き込みを足したのに `SafeToReplay` のまま)。
  → 網羅 match は case 追加を検出するが**既存 case の中身の変化は検出できない**。
  docblock に「副作用を足したら分類を再審査する」を明記し、
  `docs/architecture.md` にも同じ義務を書く (機械では担保できないことを誇張しない)

---

## B. 回収待ちの状態と理由を足す

### 変更箇所

- 変更: `app/Enums/Billing/WebhookEventStatus.php`
- 新規: `app/Enums/Billing/WebhookRecoveryReason.php`
- 新規: `database/migrations/2026_08_15_000100_add_recovery_reason_to_stripe_webhook_events_table.php`
- 変更: `app/Models/Billing/StripeWebhookEvent.php` (L19-27 の property / L36-44 の casts)
- 変更: `database/factories/Billing/StripeWebhookEventFactory.php`

### 波及変更

- TypeScript 型定義: なし
- API Resource/DTO: なし
- テストファイル: `tests/Feature/Billing/StripeWebhookStaleRecoveryTest.php` (新規。施策 D と共用)
- **保持期限 purge との関係**: `StripeWebhookEventPurger` は起算点 `processed_at` で判定し、
  `processed_at IS NULL` の行は「異常として計上するだけで消さない」ので、
  `recovery_pending` の行が purge で消えることはない (**変更不要**。設計上の確認のみ)

### 現行コード

```php
enum WebhookEventStatus: string
{
    case Received = 'received';
    case Processed = 'processed';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Received => '受信',
            self::Processed => '処理済',
            self::Failed => '失敗',
        };
    }
}
```

### 変更後コード

```php
/**
 * Stripe webhook イベントの処理状態。
 *
 * - `received`: 受理済み・未終局。**「処理中」と「次の回収待ち」を兼ねる**
 *   (どちらかは `updated_at` が滞留の閾値を超えたかで区別する)
 * - `processed`: 終局 (再処理しない)
 * - `failed`: HTTP 経路での失敗。Stripe の再送で再処理し得る
 * - `recovery_pending`: 滞留を検出したが**自動再実行の対象外**と判定して置いた静止状態。
 *   理由は `stripe_webhook_events.recovery_reason` に残る。自動では二度と動かさない
 */
enum WebhookEventStatus: string
{
    case Received = 'received';
    case Processed = 'processed';
    case Failed = 'failed';
    case RecoveryPending = 'recovery_pending';

    public function label(): string
    {
        return match ($this) {
            self::Received => '受信',
            self::Processed => '処理済',
            self::Failed => '失敗',
            self::RecoveryPending => '回収待ち',
        };
    }
}
```

```php
<?php

declare(strict_types=1);

namespace App\Enums\Billing;

/**
 * 滞留した webhook 記録を**回収待ちへ置いた理由**。
 *
 * 状態 (`WebhookEventStatus::RecoveryPending`) が「次にどう扱うか (自動では動かさない)」を、
 * 本 enum が「なぜそこに置かれたか」を表す。運用の次の行動が理由ごとに違うため、
 * 自由文の `failure_reason` とは列を分ける (機械判定できる値と混ぜない)。
 *
 * **不変条件**: `recovery_reason IS NOT NULL` ⟺ `status = recovery_pending`。
 */
enum WebhookRecoveryReason: string
{
    /** 順序に依存する種類なので再実行しない (再実行すると契約状態が巻き戻る)。 */
    case OrderSensitive = 'order_sensitive';

    /** 試行上限 (StripeWebhookProcessor::MAX_PROCESSING_ATTEMPTS) に到達済み。 */
    case AttemptsExhausted = 'attempts_exhausted';

    /**
     * 本アプリが処理しない種類 (`HandledStripeWebhookEvent` に無い)。
     *
     * 真に未知の種類と、Cashier の既定購読集合に入っている既知だが処理対象外の種類
     * (`customer.updated` 等) を**区別しない** — どちらも `process()` が何もせずに終わり、
     * 運用の次の行動 (確認のみ) が同じだからである。
     */
    case UnhandledEventType = 'unhandled_event_type';

    public function label(): string
    {
        return match ($this) {
            self::OrderSensitive => '順序に依存するため再実行しない',
            self::AttemptsExhausted => '試行上限に到達',
            self::UnhandledEventType => '処理対象外の種類',
        };
    }
}
```

migration:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 滞留した webhook 記録を回収待ちへ置いた理由 (App\Enums\Billing\WebhookRecoveryReason)。
     *
     * 不変条件: 非 NULL ⟺ status = 'recovery_pending'。既存行はすべて NULL
     * (回収待ちの行はこの migration の時点で 1 件も存在しない)。
     * 自由文の failure_reason とは分ける (機械判定できる値と混ぜない)。
     */
    public function up(): void
    {
        Schema::table('stripe_webhook_events', function (Blueprint $table) {
            $table->string('recovery_reason')->nullable()->after('failure_reason');
        });
    }

    public function down(): void
    {
        Schema::table('stripe_webhook_events', function (Blueprint $table) {
            $table->dropColumn('recovery_reason');
        });
    }
};
```

Model (差分):

```php
 * @property string|null $failure_reason
 * @property WebhookRecoveryReason|null $recovery_reason
 * @property CarbonImmutable|null $processed_at
```

```php
        return [
            'status' => WebhookEventStatus::class,
            'recovery_reason' => WebhookRecoveryReason::class,
            'payload' => 'array',
            'attempts' => 'integer',
            'processed_at' => 'immutable_datetime',
        ];
```

Factory (追加 state):

```php
    /** 自動再実行の対象外として回収待ちに置かれた行。 */
    public function recoveryPending(WebhookRecoveryReason $reason): static
    {
        return $this->state(fn (): array => [
            'status' => WebhookEventStatus::RecoveryPending,
            'recovery_reason' => $reason,
            'processed_at' => null,
        ]);
    }

    /** 受理済みのまま滞留している行 (updated_at を過去にずらす)。 */
    public function stale(int $minutesAgo = 60): static
    {
        return $this->state(fn (): array => [
            'status' => WebhookEventStatus::Received,
            'processed_at' => null,
            'updated_at' => CarbonImmutable::now()->subMinutes($minutesAgo),
        ]);
    }
```

> `updated_at` は Eloquent が保存時に自動で now に書き換えるため、`stale()` を使うテストでは
> **保存後に `StripeWebhookEvent::query()->where('event_id', …)->update(['updated_at' => …])` で
> 明示的に押し戻す**。Factory の state だけでは滞留行にならないので、
> テスト側のヘルパ (`staleWebhookRecord()`) にこの 2 段を閉じ込める。

### PHPStan 適合チェック

- [x] enum cast の宣言と `@property` の型が一致している (`WebhookRecoveryReason|null`)
- [x] Factory の state は `array<string, mixed>` を返す (既存と同型)
- [x] 網羅 match (`label()`) に `default` を置かない

### テスト計画

- [ ] `WebhookEventStatus::RecoveryPending` / `WebhookRecoveryReason` の `label()` が全 case で返る
- [ ] migration 適用後、既存行の `recovery_reason` が NULL であること
      (Factory 既定 = `received` で NULL)
- [ ] **不変条件**: 回収を走らせたあと、`recovery_reason` が非 NULL の行は
      すべて `status = recovery_pending` であり、その逆も成り立つ (施策 D のテストで固定)
- [ ] 保持期限 purge (`BillingRetentionPurgeTest` 既存) が `recovery_pending` の行を消さないこと
      (`processed_at IS NULL` の fail-closed 計上に入る)

### リスク

- 既存の `WebhookEventStatus` を `match` している箇所が case 追加で落ちる。
  → 現状 `match` しているのは `label()` のみ (grep 済み)。`StripeWebhookProcessor` は
  `!==` 比較で分岐しており網羅 match を持たないため影響しない

---

## C. 終局書き込みを世代付きの条件付き UPDATE にする

### 変更箇所

- `app/Services/Billing/StripeWebhookProcessor.php` (L85-115 の `handle()`)

### 波及変更

- TypeScript 型定義: なし / API Resource/DTO: なし
- テストファイル: 既存 `tests/Feature/Billing/WebhookIdempotencyTest.php` /
  `tests/Feature/Billing/TicketPurchaseWebhookTest.php` /
  `tests/Feature/Billing/SubscriptionCheckoutWebhookRaceTest.php` は
  **assert 内容が変わらない** (最終状態は同じ)。新規テストを 1 本足す

### 現行コード

```php
        try {
            $this->process($type, $payload);
        } catch (Throwable $exception) {
            $record->status = WebhookEventStatus::Failed;
            $record->failure_reason = $exception->getMessage();
            $record->save();
            report($exception);

            throw $exception; // 200 を返さず Stripe の再送を促す (failed は再送で再処理)
        }

        $record->status = WebhookEventStatus::Processed;
        $record->failure_reason = null;
        $record->processed_at = CarbonImmutable::now();
        $record->save();
```

### 変更後コード

```php
        // 受理したときの世代 (claim 直後の attempts)。以降の書き込みはこの世代を握っている
        // 実行だけが行える (滞留回収が attempts を進めた後の追い越し書き込みを防ぐ)。
        $claimedAttempts = $record->attempts;

        try {
            $this->process($type, $payload);
        } catch (Throwable $exception) {
            $this->finalize($eventId, $claimedAttempts, WebhookEventStatus::Failed, $exception->getMessage());
            report($exception);

            throw $exception; // 200 を返さず Stripe の再送を促す (failed は再送で再処理)
        }

        $this->finalize($eventId, $claimedAttempts, WebhookEventStatus::Processed, null);
```

```php
    /**
     * 受理した世代を握っている実行だけが行える条件付き書き込み (CAS)。
     *
     * `status='received'` かつ `attempts=受理時の値` の 1 行だけを更新する。
     * 0 件のときは**別の実行がその行を先に進めている** (滞留回収が claim し直した等) ので
     * 何も書かずに記録だけ残す — 旧ワーカーが新しい世代の結果を上書きしない
     * (ドメイン規約 6 の「条件付き UPDATE」)。
     *
     * `recovery_reason` は必ず NULL を置く
     * (不変条件: 非 NULL ⟺ status = recovery_pending)。
     *
     * **保証範囲を誇張しない**: これが守るのは `stripe_webhook_events` 行の世代だけである。
     * 旧ワーカーと回収側の `process()` は並行し得るので、付与の一回性は台帳の
     * `idempotency_key` UNIQUE と各ハンドラの終局 guard が担う。
     *
     * @param  WebhookEventStatus  $status  Processed (終局) / Failed (HTTP 経路の失敗) /
     *                                      Received (回収経路の失敗 = 終局させず次の回収へ回す)
     * @return bool 書き込めたら true
     */
    private function finalize(
        string $eventId,
        int $claimedAttempts,
        WebhookEventStatus $status,
        ?string $failureReason,
    ): bool {
        $updated = StripeWebhookEvent::query()
            ->where('event_id', $eventId)
            ->where('status', WebhookEventStatus::Received->value)
            ->where('attempts', $claimedAttempts)
            ->update([
                'status' => $status->value,
                'failure_reason' => $failureReason,
                'recovery_reason' => null,
                'processed_at' => $status === WebhookEventStatus::Processed
                    ? CarbonImmutable::now()
                    : null,
            ]);

        if ($updated !== 1) {
            Log::warning('stripe webhook: 別の実行が先に進めたため終局書き込みを見送った', [
                'event_id' => $eventId,
                'attempts' => $claimedAttempts,
                'status' => $status->value,
            ]);

            return false;
        }

        return true;
    }
```

> `Illuminate\Database\Eloquent\Builder::update()` は `updated_at` を自動で更新するため、
> 回収経路の据え置き (`status=Received`) でも `updated_at` が進む = 次の滞留判定まで待つ。

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている (`bool`)
- [x] `update()` の戻り値 `int` を `!== 1` で判定 (truthy 判定にしない)
- [x] `$record` の在メモリ状態に依存しない (永続状態だけで判定する)
- [x] 型を緩めた ignore を足さない

### テスト計画

`tests/Feature/Billing/StripeWebhookStaleRecoveryTest.php` (新規。施策 D と同居)

- [ ] **再現テストを先に書く**: `process()` の最中に別の実行が世代を進めた
      (`attempts` が変わった) 状況で `handle()` を完走させ、
      **行が `processed` にならず、進んだ世代の値のまま残る**こと。
      注入方法は `TicketLedgerService::grantMonthly` の mock 側で
      `StripeWebhookEvent::query()->where('event_id', …)->update(['attempts' => …])` を実行する
      (単一プロセスで「追い越し」だけを再現する)
- [ ] 通常経路の回帰: 既存テスト (`WebhookIdempotencyTest` の失敗記録 / 再送 / terminal-ack、
      `TicketPurchaseWebhookTest`) が緑のままであること
- [ ] `handle()` の失敗時に `failure_reason` が入り `attempts` が変わらないこと (既存 assert 維持)

### リスク

- **保証範囲の誇張**: 単一プロセスのテストは「追い越し」を再現するだけで、
  真の同時実行は検証しない。設計文書・docblock の両方に明記する
- `update()` を使うため Eloquent の model events が発火しない。
  → `StripeWebhookEvent` は observer / booted フックを持たない (実読で確認済み) ので影響なし

---

## D. 滞留回収を足す

### 変更箇所

- 新規: `app/Enums/Billing/WebhookStaleClaimOutcome.php`
- 新規: `app/DataTransferObjects/Billing/StaleWebhookClaimDto.php`
- 新規: `app/DataTransferObjects/Billing/WebhookRecoveryResultDto.php`
- 変更: `app/Services/Billing/StripeWebhookProcessor.php` (`recoverStale()` / `claimStale()` /
  `recoveryReasonFor()` / `reportRecoveryPending()` を追加)

### 波及変更

- TypeScript 型定義: なし / Inertia Props: なし / API Resource: なし
- テストファイル: `tests/Feature/Billing/StripeWebhookStaleRecoveryTest.php` (新規)
- **`DirectFetchInventory`**: 行の取り直しも条件付き UPDATE も **`event_id` (UNIQUE 列)** を
  handle にするため、主キー同一性クエリの母集団に入らない見込み。
  ただし実装時に `composer test -- --filter=ModelDirectFetchInvariantTest` を必ず実行し、
  検出されたら失敗メッセージのキーで `sameMethodQuery` として登録する (deny-by-default を迂回しない)

### 変更後コード

```php
<?php

declare(strict_types=1);

namespace App\Enums\Billing;

/**
 * 滞留した webhook 記録に対して回収が行った処置。
 *
 * `Skipped` を持たない理由: 「受理条件を満たさなかった」場合は
 * `StripeWebhookProcessor::claimStale()` が `null` を返す (行が消えていた場合も同じ)。
 * 「何もしなかった」を DTO の 1 case としても持つと表現が 2 通りになるため、
 * **処置をしたときだけ DTO を作る**。
 */
enum WebhookStaleClaimOutcome: string
{
    /** 再実行のために受理した (attempts を 1 進めた)。 */
    case ClaimedForReplay = 'claimed_for_replay';

    /** 自動再実行の対象外と判定して回収待ちへ置いた。 */
    case MovedToRecoveryPending = 'moved_to_recovery_pending';
}
```

```php
<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Billing;

use App\Enums\Billing\WebhookRecoveryReason;
use App\Enums\Billing\WebhookStaleClaimOutcome;

/**
 * 滞留 webhook 1 件の受理結果 (読み取り専用スナップショット)。
 *
 * **Eloquent の Model をトランザクションの外へ持ち出さない**ための型
 * (在メモリ状態と永続状態を混ぜない)。commit 後の処理 —— 再実行と通知 —— に要る値だけを持つ。
 *
 * 生成は名前付きコンストラクタ経由のみ。`reason` が非 NULL になるのは
 * `MovedToRecoveryPending` のときだけで、その対応は型の側で閉じている。
 */
final readonly class StaleWebhookClaimDto
{
    /**
     * @param  int  $attempts  受理**後**の値 (この世代を握っている印)
     * @param  array<mixed>  $payload  保存済み payload (Model の cast をそのまま渡す)
     */
    private function __construct(
        public WebhookStaleClaimOutcome $outcome,
        public string $eventId,
        public string $type,
        public int $attempts,
        public array $payload,
        public ?WebhookRecoveryReason $reason,
    ) {}

    /**
     * @param  array<mixed>  $payload
     */
    public static function claimedForReplay(
        string $eventId,
        string $type,
        int $attempts,
        array $payload,
    ): self {
        return new self(
            WebhookStaleClaimOutcome::ClaimedForReplay,
            $eventId,
            $type,
            $attempts,
            $payload,
            null,
        );
    }

    /**
     * @param  array<mixed>  $payload
     */
    public static function movedToRecoveryPending(
        string $eventId,
        string $type,
        int $attempts,
        array $payload,
        WebhookRecoveryReason $reason,
    ): self {
        return new self(
            WebhookStaleClaimOutcome::MovedToRecoveryPending,
            $eventId,
            $type,
            $attempts,
            $payload,
            $reason,
        );
    }

    /** 通知・ログの構造化 context (payload 本体は載せない)。 */
    public function logContext(): array
    {
        return [
            'event_id' => $this->eventId,
            'type' => $this->type,
            'attempts' => $this->attempts,
            'status' => $this->outcome->value,
            'reason' => $this->reason?->value,
        ];
    }
}
```

> `logContext()` の戻り値は `array<string, string|int|null>` を PHPDoc で明示する。

```php
<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Billing;

/**
 * 滞留回収 1 実行分の結果。
 *
 * **任意メタデータ領域は持たせない** (`BillingRetentionPurgeResultDto` と同じ方針。
 * 型で分からない領域を作ると organization id 等が運用ログへ漏れる)。
 *
 * 件数の意味:
 *   replayed               = 再実行して processed まで終局した件数
 *   retryScheduled         = 再実行が失敗し received のまま次回の回収へ回した件数
 *   movedToRecoveryPending = 自動再実行の対象外として回収待ちへ置いた件数
 *   skipped                = 何もしなかった件数 (受理条件を満たさない / 行が無い /
 *                            書き込みが別の世代に追い越された)
 */
final readonly class WebhookRecoveryResultDto
{
    public function __construct(
        public int $replayed,
        public int $retryScheduled,
        public int $movedToRecoveryPending,
        public int $skipped,
    ) {}
}
```

`StripeWebhookProcessor` への追加:

```php
    /**
     * 処理中に滞留した webhook 記録の回収 (cron: billing:recover-stale-webhook-events)。
     *
     * 対象は `status=received` かつ `updated_at` が滞留の閾値より古い行**だけ**。
     * `failed` は Stripe の再送が再試行の駆動者なので拾わない。
     *
     * 作法は既存の滞留回収 (`RenderJobService::recoverStale` /
     * `TicketLedgerService::releaseStale`) と同じ = 対象を列挙 → 1 件ずつ行ロックで
     * 取り直して再検証 → 件数を返す。**共通の回収基盤は作らない** (ドメインごとの個別実装)。
     *
     * 通知 (`Log::warning` / `report()`) は**トランザクションの外**で出す
     * (状態が保存されていないのに通知だけ出る / 同じ行に複数回出るのを避ける)。
     * ただし commit 後に落ちれば 0 回になる = 送信を 1 回試みるだけで、
     * 厳密な一回配送は保証しない (常設の観測点は `recovery_pending` の件数のほう)。
     */
    public function recoverStale(): WebhookRecoveryResultDto
    {
        $threshold = CarbonImmutable::now()
            ->subMinutes(config()->integer('billing.webhook_stale_after_minutes'));

        /** @var list<string> $staleEventIds */
        $staleEventIds = StripeWebhookEvent::query()
            ->where('status', WebhookEventStatus::Received->value)
            ->where('updated_at', '<=', $threshold)
            ->orderBy('id')
            ->pluck('event_id')
            ->all();

        $replayed = 0;
        $retryScheduled = 0;
        $movedToRecoveryPending = 0;
        $skipped = 0;

        foreach ($staleEventIds as $eventId) {
            $claim = $this->claimStale($eventId, $threshold);
            if ($claim === null) {
                $skipped++; // 行が消えた / 別の実行が先に進めた

                continue;
            }

            if ($claim->outcome === WebhookStaleClaimOutcome::MovedToRecoveryPending) {
                $movedToRecoveryPending++;
                $this->reportRecoveryPending($claim);

                continue;
            }

            try {
                $this->process($claim->type, $claim->payload);
            } catch (Throwable $exception) {
                report($exception);
                // **終局させない**: failed にすると回収対象 (received) から外れ、
                // Stripe も配信成功と認識しているため二度と再試行されない。
                // received のまま失敗理由だけ書いて次回の回収へ回す (attempts は消費済み)。
                $this->finalize($claim->eventId, $claim->attempts, WebhookEventStatus::Received, $exception->getMessage())
                    ? $retryScheduled++
                    : $skipped++;

                continue;
            }

            $this->finalize($claim->eventId, $claim->attempts, WebhookEventStatus::Processed, null)
                ? $replayed++
                : $skipped++;
        }

        return new WebhookRecoveryResultDto(
            replayed: $replayed,
            retryScheduled: $retryScheduled,
            movedToRecoveryPending: $movedToRecoveryPending,
            skipped: $skipped,
        );
    }

    /**
     * 滞留 1 件の受理。**状態遷移だけ**を 1 つのトランザクションで確定させ、
     * commit 後に要る値をスナップショットで返す (通知はここでは出さない)。
     *
     * `claim()` (Stripe 再送の受理) とは入口が別なので分けてある。
     * `claim()` は変更しない = `received` からの再受理は今までどおり起こらない。
     *
     * 滞留の再検証は**クエリの WHERE に入れる**(ロック取得後に PostgreSQL が述語を
     * 再評価するため、ロック待ちの間に他の実行が前進させた行は 1 行も返らない)。
     *
     * @return StaleWebhookClaimDto|null 処置をしなかったとき (行が無い / 条件を満たさない) は null
     */
    private function claimStale(string $eventId, CarbonImmutable $threshold): ?StaleWebhookClaimDto
    {
        return DB::transaction(function () use ($eventId, $threshold): ?StaleWebhookClaimDto {
            $record = StripeWebhookEvent::query()
                ->where('event_id', $eventId)
                ->where('status', WebhookEventStatus::Received->value)
                ->where('updated_at', '<=', $threshold)
                ->lockForUpdate()
                ->first();

            if (! $record instanceof StripeWebhookEvent) {
                return null;
            }

            $reason = $this->recoveryReasonFor($record);
            if ($reason !== null) {
                $record->status = WebhookEventStatus::RecoveryPending;
                $record->recovery_reason = $reason;
                $record->save();

                return StaleWebhookClaimDto::movedToRecoveryPending(
                    $record->event_id,
                    $record->type,
                    $record->attempts,
                    $record->payload,
                    $reason,
                );
            }

            // 世代を 1 つ進める (status は received のまま = 状態機械を増やさない)。
            // updated_at も進むので、次の実行は閾値を超えるまでこの行を拾わない。
            $record->attempts += 1;
            $record->save();

            return StaleWebhookClaimDto::claimedForReplay(
                $record->event_id,
                $record->type,
                $record->attempts,
                $record->payload,
            );
        });
    }

    /**
     * 自動再実行の対象外と判定する理由 (無ければ null = 再実行してよい)。
     *
     * 判定順は「本アプリが処理する種類か」→「再実行してよい種類か」→「上限に達していないか」。
     * DB の `type` 文字列は **`tryFrom()`** で境界変換する (`from()` は未知値で例外になり
     * cron 全体を止める)。
     */
    private function recoveryReasonFor(StripeWebhookEvent $record): ?WebhookRecoveryReason
    {
        $event = HandledStripeWebhookEvent::tryFrom($record->type);
        if ($event === null) {
            return WebhookRecoveryReason::UnhandledEventType;
        }

        if ($event->replaySafety() === WebhookReplaySafety::OrderSensitive) {
            return WebhookRecoveryReason::OrderSensitive;
        }

        if ($record->attempts >= self::MAX_PROCESSING_ATTEMPTS) {
            return WebhookRecoveryReason::AttemptsExhausted;
        }

        return null;
    }

    /**
     * 回収待ちへ置いたことの可観測化 (commit 後に 1 回だけ送信を試みる)。
     * payload 本体は載せない (外部由来の可変データを運用ログへ流さない)。
     */
    private function reportRecoveryPending(StaleWebhookClaimDto $claim): void
    {
        Log::warning('stripe webhook: 滞留を回収待ちへ移した (自動再実行しない)', $claim->logContext());

        report(new RuntimeException(sprintf(
            'stripe webhook 回収待ち: %s (%s) reason=%s attempts=%d',
            $claim->eventId,
            $claim->type,
            $claim->reason?->value ?? '',
            $claim->attempts,
        )));
    }
```

### 保存済み payload の扱い (型の契約)

- `payload` 列は `json` NOT NULL で、**書き手は `StripeWebhookProcessor` だけ**である。
  Model の cast (`array`) の結果を `array<mixed>` として扱い、
  **無検査の別型キャストを足さない**(`array<string, mixed>` へ狭めない)
- payload の中身が想定と違う場合は、既存の `stringAt()` / `data_get()` が `null` を返し、
  各ハンドラの fail-closed (例外 throw / 早期 return) がそのまま受ける。
  回収経路はその例外を掴んで「終局させず次回へ回す」に落ちるので、
  最終的に上限到達で `recovery_pending` + `AttemptsExhausted` に止まる
- **DB を直接書き換えられて cast が壊れた場合は対象外**(保証しないもの)

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている (`WebhookRecoveryResultDto` / `?StaleWebhookClaimDto` /
      `?WebhookRecoveryReason` / `bool`)
- [x] null 安全 (`tryFrom()` の `null` を明示分岐。`first()` の `null` を `instanceof` で分岐)
- [x] DTO を返している (配列返却なし。`logContext()` だけは構造化ログ用の配列で PHPDoc 付き)
- [x] Generics: `pluck('event_id')->all()` を `list<string>` として `@var` で明示
- [x] `config()->integer()` を使う (`config()` の `mixed` を素で渡さない)

### テスト計画

`tests/Feature/Billing/StripeWebhookStaleRecoveryTest.php` (新規)。
**再現テストを先に書いて fail を確認してから実装する**。

1. [ ] **無音喪失の再現 (fail-first)**: `checkout.session.completed` (ticket_purchase) の
      滞留行があるとき、`recoverStale()` を実行するとチケットが付与され、行が `processed` になる。
      実装前は付与 0 枚で fail する
2. [ ] 同上を `invoice.paid` (billing_reason=subscription_cycle) で固定する (月次付与)
3. [ ] **付与は 1 回だけ**: 1 の後にもう一度 Stripe 再送 (`handle()`) が来ても、
      台帳の付与行が増えない (`purchase:` / `monthly:` の冪等キー)
4. [ ] **順序に依存する種類は再実行しない**: `customer.subscription.updated` の滞留行は
      `recovery_pending` + `recovery_reason=order_sensitive` になり、
      `organizations.plan_code` が書き換わらない。
      さらにその後 Stripe 再送が来ても `claim()` が受理せず、状態が巻き戻らない
5. [ ] **上限到達**: `attempts = MAX_PROCESSING_ATTEMPTS` の滞留行は再実行されず
      `recovery_pending` + `attempts_exhausted`
6. [ ] **処理対象外の種類**: `type='customer.updated'` の滞留行は再実行されず
      `recovery_pending` + `unhandled_event_type`
7. [ ] **閾値内は触らない**: `updated_at` が閾値より新しい `received` 行は
      状態も `attempts` も変わらない (処理中の行に触らない)
8. [ ] **回収の失敗は終局させない**: 再実行が例外になったとき、行は `received` のままで
      `failure_reason` が入り、`attempts` が 1 進んでいる。
      閾値を再び超えさせて `recoverStale()` を繰り返すと `attempts` が上限まで進み、
      最後は `recovery_pending` + `attempts_exhausted` になる (無限に回らない)
9. [ ] **再クラッシュの自己回復**: 回収で受理された行が終局せずに `received` のまま残った場合
      (= 8 と同じ形)、閾値経過後の次回実行で再び拾われる
10. [ ] **不変条件**: 上記すべての実行後、`recovery_reason` が非 NULL の行は
      すべて `status = recovery_pending` であり、`recovery_pending` の行はすべて
      `recovery_reason` が非 NULL である
11. [ ] **件数の契約**: `WebhookRecoveryResultDto` の 4 件数が実際の処置と一致する
      (`replayed` / `retryScheduled` / `movedToRecoveryPending` / `skipped`)。
      条件付き UPDATE が 0 件だったケースは `retryScheduled` ではなく `skipped` に計上する
12. [ ] 個別の `DatabaseTransactions` を使っていないことを確認
13. [ ] `composer test -- --filter=ModelDirectFetchInvariantTest` が緑
      (検出されたら `DirectFetchInventory` へ登録してから緑にする)

### リスク

- **滞留閾値が短すぎると生存中のワーカーを追い越す**。既定 15 分は webhook の HTTP 処理時間
  (実測で秒オーダー) より十分に長い。追い越しても施策 C の CAS が終局書き込みを 1 つに絞り、
  付与の一回性は台帳の UNIQUE が担う (二重防御)
- **`recovery_pending` の行が溜まり続ける**。自動では動かさない設計なので、
  運用が手当てしない限り件数は減らない。これは意図であり、件数を監視対象にする
  (`docs/architecture.md` に明記)
- **順序に依存する種類の滞留は自動復旧しない**。契約状態は後続の
  `customer.subscription.updated` で追随する。初回無償付与だけは滞留すると失われるため、
  `report()` と件数で運用へ渡す (スコープ外の順序判定列は本 TODO では作らない)

---

## E. cron を配線する

### 変更箇所

- `config/billing.php` (末尾の `auto_recharge` の前にキーを 1 つ追加)
- `routes/console.php` (課金 cron セクションへコマンドとスケジュールを追加)

### 波及変更

- TypeScript 型定義: なし / API Resource/DTO: なし
- テストファイル: `tests/Feature/Billing/StripeWebhookStaleRecoveryTest.php` で
  コマンド経由の実行 (`artisan('billing:recover-stale-webhook-events')`) を 1 本固定する

### 変更後コード

`config/billing.php`:

```php
    /*
    | webhook 処理の滞留判定 (分)。`stripe_webhook_events.status='received'` のまま
    | この時間を超えた行を「本処理中にプロセスが落ちた残留」とみなして回収する
    | (billing:recover-stale-webhook-events)。
    |
    | env で振らない (環境ごとに変えてよい運用値ではない)。webhook の HTTP 処理は
    | 秒オーダーで終わるため、生存中のワーカーを追い越さない十分な余裕を取ってある。
    | 短くすると処理中の行を追い越し、長くすると付与の回復が遅れる。
    */
    'webhook_stale_after_minutes' => 15,
```

`routes/console.php`:

```php
/*
|--------------------------------------------------------------------------
| Stripe webhook の滞留回収
|--------------------------------------------------------------------------
| 本処理中にプロセスが落ちて status='received' のまま残った記録を再処理へ戻す。
| 放置すると Stripe の再送は claim() に弾かれて 200 で終わり、Stripe 側も配信成功と
| 判断して再送を打ち切るため、決済済みチケットの付与が**無音で失われる**。
|
| **監視対象 (必須)**: 本コマンドの report() と、
| stripe_webhook_events の status='recovery_pending' の件数
| (= 自動再実行の対象外として置かれた行。理由は recovery_reason 列)。
*/
Artisan::command('billing:recover-stale-webhook-events', function (StripeWebhookProcessor $webhooks) {
    $result = $webhooks->recoverStale();
    $this->info(sprintf(
        'replayed %d / retry-scheduled %d / moved-to-recovery-pending %d / skipped %d',
        $result->replayed,
        $result->retryScheduled,
        $result->movedToRecoveryPending,
        $result->skipped,
    ));
})->purpose('処理中に滞留した Stripe webhook 記録を再処理へ戻す');

Schedule::command('billing:recover-stale-webhook-events')
    ->everyFiveMinutes()
    ->onOneServer()
    ->withoutOverlapping()
    ->onFailure(static fn () => report(new RuntimeException(
        'billing:recover-stale-webhook-events 失敗 — 決済済み・チケット未付与が滞留する可能性',
    )));
```

> `routes/console.php` は namespace 宣言が無く global 解決されるため、
> `RuntimeException` を `use` しない (`NoNonCompoundGlobalUseTest` が非複合 use を禁止する)。
> `App\Services\Billing\StripeWebhookProcessor` の `use` は複合なので追加してよい。

### PHPStan 適合チェック

- [x] closure の引数に型を書く (`StripeWebhookProcessor $webhooks`)
- [x] `sprintf` に渡すのは DTO の `int` プロパティ (mixed を渡さない)
- [x] `config('billing.webhook_stale_after_minutes')` は `config()->integer()` 経由で読む (施策 D)

### テスト計画

- [ ] `artisan('billing:recover-stale-webhook-events')` が滞留行を回収し、
      出力に 4 件数が出て終了コード 0 であること
- [ ] `config('billing.webhook_stale_after_minutes')` が整数で読めること
      (`config()->integer()` が例外にならない)
- [ ] スケジュール登録の存在確認は行わない (既存の cron 群も同様に確認していないため、
      ここだけ機構を足さない)

### リスク

- scheduler が動いていない環境では回収が走らない (既存の cron 群と同じ前提)。
  `onOneServer()` はロックを提供する cache driver が前提 —
  既存の `billing:reconcile-auto-recharge` 等と同じ前提に乗るだけで、新しい前提を作らない

---

## F. 誤った説明コメントとドキュメントを実態に合わせる

### 変更箇所

- `app/Services/Billing/StripeWebhookProcessor.php` (L33-63 のクラス docblock、
  L66-73 の `MAX_PROCESSING_ATTEMPTS` docblock)
- `docs/architecture.md`

### 現行コード (誤り)

```php
    /**
     * webhook 処理失敗の再送上限。attempts (failed→received 復帰回数) がこれに到達したら
     * terminal とみなし処理せず 200 ack して Stripe の自動再送を打ち切る。
     * claim() が transaction + lockForUpdate で状態遷移を直列化するため
     * "processing 残留 stale" は生じず、復帰 sweep は不要。
     * Stripe の自動再送窓 (~3 日) に対し 8 回で十分。
     */
    public const int MAX_PROCESSING_ATTEMPTS = 8;
```

### 変更後コード

```php
    /**
     * webhook 処理の試行上限。`attempts` がこれに到達したら terminal とみなす。
     *
     * `attempts` は「受理し直した回数」で、増やす経路は 2 つある —
     * `claim()` (Stripe 再送による failed→received 復帰) と
     * `claimStale()` (滞留回収による受理)。どちらの経路で消費しても上限は共通で、
     * 到達後は HTTP 経路なら処理せず 200 ack して Stripe の自動再送を打ち切り、
     * 回収経路なら `recovery_pending` + 理由 `AttemptsExhausted` へ置いて止める。
     *
     * **`claim()` の直列化は本処理までは覆わない**: `claim()` が守るのは状態遷移だけで、
     * `process()` はトランザクションの外で走る。そこでプロセスが落ちた行は `received` の
     * まま残り、Stripe の再送は `claim()` に弾かれて 200 で終わるため Stripe も再送を
     * 打ち切る = 決済済みチケットの付与が無音で失われる。
     * これを塞ぐのが `recoverStale()` である (回収しないと月次付与・購入付与が
     * 無音で失われる)。
     *
     * Stripe の自動再送窓 (~3 日) に対し 8 回で十分。
     */
    public const int MAX_PROCESSING_ATTEMPTS = 8;
```

クラス docblock には、既存の 1〜4 の説明に続けて次を足す:

```
 * 5. 滞留回収: 本処理中にプロセスが落ちて received のまま残った行を
 *    recoverStale() が拾い直す (cron: billing:recover-stale-webhook-events)。
 *    再実行してよい種類かは HandledStripeWebhookEvent::replaySafety() が決め、
 *    対象外・上限到達は recovery_pending + recovery_reason へ置いて止める。
 *    終局書き込みは受理した世代 (attempts) を握っている実行だけが行う条件付き UPDATE。
```

### `docs/architecture.md` への追記

「§チケットスポット購入 (T007) の運用契約」の直前に新しい節を置く。

```markdown
## Stripe webhook の滞留回収

- **状態の意味**: `received` = 受理済み・未終局 (**処理中と次の回収待ちを兼ねる**。
  どちらかは `updated_at` が `config('billing.webhook_stale_after_minutes')` (15 分) を
  超えたかで区別する) / `processed` = 終局 / `failed` = HTTP 経路の失敗 (Stripe の再送が
  再試行の駆動者) / `recovery_pending` = 自動再実行の対象外として置いた静止状態
- **なぜ回収が要るか**: `claim()` が直列化するのは状態遷移だけで `process()` は
  トランザクションの外にある。そこで落ちた行は `received` のまま残り、Stripe の再送は
  `claim()` に弾かれて 200 で終わる → Stripe も再送を打ち切る = **付与が無音で失われる**
- **回収してよい種類**: `HandledStripeWebhookEvent::replaySafety()` の 2 値分類が**唯一の**
  判断材料。`SafeToReplay` の意味は「再実行しても追加の被害を生まない」であって
  「再実行すれば復旧する」ではない。**ハンドラに副作用を足したら分類を再審査すること**
  (順序に依存する書き込みを足したら `OrderSensitive` へ移す。機械では検出できない)
- **回収の失敗は終局させない**: 再実行が例外になっても `received` のままにして
  `failure_reason` だけ書く (`failed` にすると回収対象から外れ、Stripe も再送しないため
  二度と再試行されない)。`attempts` は消費されるので上限 8 で必ず止まる
- **監視対象 (必須項目として登録する)**: **`php artisan billing:recover-stale-webhook-events`
  (scheduler で `*/5 * * * *`・`onOneServer()` + `withoutOverlapping()`)**。
  失敗は `onFailure` → `report()` で運用アラート経路に載る。
  **滞留の観測点**: `stripe_webhook_events` の `status='recovery_pending'` 件数
  (理由は `recovery_reason`: `order_sensitive` / `attempts_exhausted` / `unhandled_event_type`)
- **運用手順**: `recovery_reason` ごとに次の行動が違う。
  `order_sensitive` は Stripe ダッシュボードで現在の契約状態を確認する /
  `attempts_exhausted` は `failure_reason` があれば確認し、ログと Stripe 上の状態と
  合わせて手当てする (連続クラッシュでは NULL のことがある) /
  `unhandled_event_type` は処理対象外なので通常は確認のみ
- **保証しないもの**: (1) 順序に依存する種類は自動復旧しない (契約状態は後続の
  `customer.subscription.updated` が追随する。初回無償付与だけは失われ得るので件数で拾う)。
  (2) 条件付き UPDATE が守るのは `stripe_webhook_events` 行の世代だけで、旧ワーカーと
  回収側の `process()` の**同時実行そのものは防がない** (付与の一回性は台帳の
  `idempotency_key` UNIQUE が担う)。(3) `report()` の配送は通知基盤の設定次第で、
  常設の観測点は件数のほうである。(4) HTTP 経路で `failed` になった行は回収 cron が拾わない
```

併せて既存の 2 箇所を更新する:

- 「§チケットスポット購入 (T007) の運用契約」の **terminal failure の運用手順**に
  「滞留 (`received` のまま残った) 分は `billing:recover-stale-webhook-events` が回収する」を
  1 行足す (手動付与の前に確認する経路として)
- モデル一覧 (L151) の `Billing/StripeWebhookEvent` の説明に `recovery_reason` を足す

### PHPStan 適合チェック

- [x] コメント・ドキュメントのみ (コードの型に影響しない)

### テスト計画

- [ ] `docs/` の更新はテスト対象外だが、`DocumentTitleCoverageTest` 等の既存
      ドキュメント系 Architecture テストが緑のままであることを確認する
- [ ] コメント修正で挙動は変わらないため、既存テスト全体 (`composer test`) が緑であること

### リスク

- ドキュメントの二重管理。`docs/architecture.md` と docblock の両方に同じ説明を置くと
  片方だけ古くなる。→ **正本は `docs/architecture.md`** とし、docblock 側は短い要約に留める

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | standalone |
| 判断根拠 | `StripeWebhookProcessor` の中核 (`handle()` の終局書き込み) と `WebhookEventStatus` を触るため、同じファイルを触る他の課金系タスクと必ず衝突する。また migration を 1 本足すので、他タスクの migration と採番が近接すると順序が読みにくくなる。施策 A〜F は互いに強く依存しており (D が A/B/C 前提)、分割して段階マージすると中途半端な状態機械が main に乗る |
| 競合リスク | `app/Services/Billing/StripeWebhookProcessor.php` を触る他タスク / `app/Enums/Billing/WebhookEventStatus.php` を触る他タスク / `routes/console.php` の cron 追加 (行の近接のみで論理衝突はしない) |

## 実装後の確認コマンド

```
composer test
composer phpstan
vendor/bin/pint --test
```

(フロント変更が無いため `pnpm` 系は既存のまま緑であることの確認で足りる)
