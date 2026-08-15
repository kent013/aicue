【アプリの使命 (North Star)】
**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

【禁止事項】
1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する)
9. Artifact の使用(成果物はリポジトリ内のファイルとして出力する)

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

あなたは Laravel + Svelte アプリのコードレビュアーである。TODO T162「Stripe webhook の滞留回収経路を追加する」の実装差分をレビューせよ。

## レビュー観点
- 設計との一致性 (詳細設計書どおりか。逸脱があれば逸脱が妥当か)
- 正確性 (状態機械・競合・冪等性・境界条件。特に条件付き UPDATE (CAS) と滞留 claim の競合)
- PHPStan level 10 適合性
- DTO / JsonResource パターン
- テスト網羅性 (テストのない不変条件が残っていないか)
- セキュリティ (課金の冪等性、tenant キー不信、外部由来データのログ出力)
- DESIGN.md 準拠 / Atomic Design 準拠 (本差分にフロント変更は無いため該当しなければその旨だけ書く)

## 出力形式
- ファイルごとに判定を書く
- 指摘は [Critical] / [Warning] / [Suggestion] に分類する
- 最後に全体判定を **APPROVED** または **CHANGES_REQUESTED** で明記する

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
      (`checkout.session.completed` (ticket_purchase) / `invoice.paid` (subscription_cycle)) を
      **別の `event_id`・同一の session id / invoice id** で 2 回処理しても、
      台帳の付与行が 1 行だけであること。
      **同一 `event_id` で二重発火する形にしない** — それだと webhook 行の冪等性
      (`claim()` の skip) しか見ておらず、分類の根拠である下位の冪等キー
      (`purchase:{sessionId}` / `monthly:{invoiceId}`) を検証したことにならない
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

    public function label(): string
    {
        return match ($this) {
            self::OrderSensitive => '順序に依存するため再実行しない',
            self::AttemptsExhausted => '試行上限に到達',
        };
    }
}
```

> **`HandledStripeWebhookEvent` に無い種類 (`customer.updated` 等) は回収待ちにしない。**
> 通常経路では `process()` の `null` arm で受理のみ終わり `processed` になるので、
> 回収でも同じく再実行して `processed` にする (同じ事実に 2 通りの決着を与えない)。
> `null` arm は**構造的に no-op** である — 副作用を持たせるには
> `HandledStripeWebhookEvent` に case を足すしかなく、足せば `replaySafety()` の
> 網羅 match を必ず通るため、再実行の安全性は型の側で保証されている。

migration:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 滞留した webhook 記録を回収待ちへ置いた理由 (App\Enums\Billing\WebhookRecoveryReason)
     * と、滞留回収 cron が使う複合 index。
     *
     * 不変条件: recovery_reason が非 NULL ⟺ status = 'recovery_pending'。既存行はすべて NULL
     * (回収待ちの行はこの migration の時点で 1 件も存在しない)。
     * 自由文の failure_reason とは分ける (機械判定できる値と混ぜない)。
     *
     * index: billing:recover-stale-webhook-events が 5 分ごとに
     * `status='received' AND updated_at <= 閾値` を引く。本表は保持期限 (7 年) まで
     * 残るため単調に増える = 全表走査にしない。
     * 監視で使う status='recovery_pending' の件数も同じ index の先頭列で効く。
     */
    public function up(): void
    {
        Schema::table('stripe_webhook_events', function (Blueprint $table) {
            $table->string('recovery_reason')->nullable()->after('failure_reason');
            $table->index(['status', 'updated_at'], 'stripe_webhook_events_status_updated_at_index');
        });

        // CHECK は sqlite の ALTER TABLE ADD CONSTRAINT 非対応のため driver guard
        // (既存 ticket_auto_recharges の CHECK と同じ作法)。
        // 全 driver 共通の防御はアプリ層 (StripeWebhookProcessor の書き込み経路) が担う。
        if (in_array(DB::connection()->getDriverName(), ['pgsql', 'mysql'], true)) {
            DB::statement(
                'ALTER TABLE stripe_webhook_events ADD CONSTRAINT stripe_webhook_events_recovery_reason_state_check '
                ."CHECK ((recovery_reason IS NULL AND status <> 'recovery_pending') "
                ."OR (recovery_reason IS NOT NULL AND status = 'recovery_pending'))",
            );
        }
    }

    public function down(): void
    {
        // CHECK の削除構文は driver で違う (pgsql は DROP CONSTRAINT / mysql は DROP CHECK)。
        // 共通化できないので分けて書く。sqlite は up() で作っていないので何もしない。
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement(
                'ALTER TABLE stripe_webhook_events DROP CONSTRAINT IF EXISTS '
                .'stripe_webhook_events_recovery_reason_state_check',
            );
        }

        if ($driver === 'mysql') {
            DB::statement(
                'ALTER TABLE stripe_webhook_events DROP CHECK '
                .'stripe_webhook_events_recovery_reason_state_check',
            );
        }

        Schema::table('stripe_webhook_events', function (Blueprint $table) {
            $table->dropIndex('stripe_webhook_events_status_updated_at_index');
            $table->dropColumn('recovery_reason');
        });
    }
};
```

> **書き込み順序の注意**: CHECK 制約があるので、`recovery_pending` への遷移では
> `status` と `recovery_reason` を**同じ UPDATE で**書く必要がある
> (`claimStale()` は 1 回の `save()` で両方を書くので満たす)。
> 逆向き (終局書き込み) も `finalize()` が 1 回の UPDATE で `status` と
> `recovery_reason = NULL` を同時に書くので満たす。

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
- [ ] **不変条件 (DB 側) は両方向を固定する** (pgsql レーンでのみ意味を持つ。
      テストレーンは `DB_CONNECTION=pgsql` 固定なので driver 分岐は書かない):
      - `status='received'` の行に `recovery_reason` を入れる UPDATE が CHECK で失敗する
      - `status='recovery_pending'` の行の `recovery_reason` を NULL にする UPDATE が
        CHECK で失敗する
- [ ] **不変条件 (アプリ側)**: 回収を走らせたあと、`recovery_reason` が非 NULL の行は
      すべて `status = recovery_pending` であり、その逆も成り立つ (施策 D のテストで固定)
- [ ] **migration の rollback**: `down()` で CHECK 制約・index・列が落ちること
      (`Schema::hasColumn` / `hasIndex` で確認)
- [ ] 保持期限 purge (`BillingRetentionPurgeTest` 既存) が `recovery_pending` の行も
      `received` 滞留の行も消さないこと (`processed_at IS NULL` の fail-closed 計上に入る)

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
- [ ] 同じケースで **HTTP 経路は例外を投げずに完走する** こと
      (`finalize()` の戻り値 false は throw に変換しない = Stripe には 200 が返る。
      その行は既に別の世代が持っているので、こちらから再送を促す理由が無い)
- [ ] `finalize()` へ `RecoveryPending` を**渡さない**ことを固定する
      (型では閉じていない。回収失敗の据え置きで行が `recovery_pending` にならないことを
      最終状態で assert する)
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
 *
 * `payload` に中身が入るのは `ClaimedForReplay` のときだけである
 * (回収待ちへ置く場合は再実行しないので保持しない = 1 件分の payload を無用に持ち回らない)。
 */
final readonly class StaleWebhookClaimDto
{
    /**
     * @param  int  $attempts  受理**後**の値 (この世代を握っている印)
     * @param  array<mixed>  $payload  保存済み payload (Model の cast をそのまま渡す)。
     *                                 ClaimedForReplay 以外では空配列
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

    public static function movedToRecoveryPending(
        string $eventId,
        string $type,
        int $attempts,
        WebhookRecoveryReason $reason,
    ): self {
        return new self(
            WebhookStaleClaimOutcome::MovedToRecoveryPending,
            $eventId,
            $type,
            $attempts,
            [], // 再実行しないので payload は持たない
            $reason,
        );
    }

    /**
     * 通知・ログの構造化 context (payload 本体は載せない = 外部由来の可変データを運用ログへ流さない)。
     *
     * @return array{
     *     event_id: string,
     *     type: string,
     *     attempts: int,
     *     outcome: string,
     *     reason: string|null,
     * }
     */
    public function logContext(): array
    {
        return [
            'event_id' => $this->eventId,
            'type' => $this->type,
            'attempts' => $this->attempts,
            'outcome' => $this->outcome->value,
            'reason' => $this->reason?->value,
        ];
    }
}
```

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
     * DB の `type` 文字列は **`tryFrom()`** で境界変換する (`from()` は未知値で例外になり
     * cron 全体を止める)。`null` (本アプリが処理しない種類) は**再実行してよい側**に落ちる —
     * `process()` の `null` arm は構造的に no-op で、通常経路でも `processed` になるため
     * (同じ事実に 2 通りの決着を与えない)。
     */
    private function recoveryReasonFor(StripeWebhookEvent $record): ?WebhookRecoveryReason
    {
        $event = HandledStripeWebhookEvent::tryFrom($record->type);

        // 本アプリが処理しない種類は**必ず**通常経路と同じ決着にする (再実行 → no-op → processed)。
        // 試行上限より前に返すのが要点 — no-op に上限を適用して回収待ちへ置くと、
        // 「未対応 type は通常経路と同じ」という契約が上限到達時だけ破れる。
        if ($event === null) {
            return null;
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
6. [ ] **処理対象外の種類は通常経路と同じ決着**: `type='customer.updated'` の滞留行は
      再実行されて `processed` になる (`recovery_pending` に置かない)。
      副作用が何も起きない (台帳行が増えない・組織の状態が変わらない) ことも併せて固定する
6b. [ ] **同上を `attempts = MAX_PROCESSING_ATTEMPTS` でも固定する**:
      未対応 type は試行上限に到達していても `processed` になる
      (上限判定より前に通過することの回帰。既定 `attempts=0` だけでは見落とす)
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
  付与の一回性は台帳の UNIQUE が担う (二重防御)。
  ただし外部 API 遅延等で本当に 15 分を超えた生存ワーカーがいた場合、
  順序に依存する種類だとその行は `recovery_pending` に置かれ、
  **ワーカーが成功しても `finalize()` が 0 件になって行は回収待ちのまま残る**
  (業務側の副作用は正しく起きているのに、行だけ「要確認」に見える)。
  これは保証しないものとして `docs/architecture.md` に明記する。
  `processing_started_at` 相当の列は足さない — `updated_at` は claim の瞬間に更新されるので
  意味が同じで、列を増やしても誤検知の閾値問題そのものは変わらないから
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
| **監視対象 (必須)**: 本コマンドの report() と、次の 3 つの件数。
|   1. status='received' かつ updated_at が滞留の閾値より古い行の件数
|      (増え続ける = scheduler か本コマンドが動いていない)
|   2. 本コマンド出力の retry-scheduled 件数 (再実行が失敗し続けている)
|   3. status='recovery_pending' の件数 (自動再実行の対象外として置かれた行。
|      理由は recovery_reason 列)
| 詳細は docs/architecture.md の「Stripe webhook の滞留回収」が正本。
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
     * `attempts` を増やす経路は 2 つある — `claim()` (Stripe 再送による failed→received 復帰) と
     * `claimStale()` (滞留回収による受理)。上限は共通で、到達後は HTTP 経路なら処理せず
     * 200 ack、回収経路なら `recovery_pending` + `AttemptsExhausted` へ置いて止める。
     *
     * **`claim()` の直列化は本処理までは覆わない** (守るのは状態遷移だけで `process()` は
     * トランザクションの外で走る)。そこで落ちた行は `received` のまま残り、Stripe の再送も
     * `claim()` に弾かれて 200 で終わるため付与が無音で失われる。これを塞ぐのが
     * `recoverStale()` である。運用契約の正本は `docs/architecture.md`
     * の「Stripe webhook の滞留回収」。
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
- **処理対象外の種類**: `HandledStripeWebhookEvent` に無い type は通常経路と同じく
  再実行して `processed` にする (`process()` の `null` arm は構造的に no-op)。
  回収だけ別扱いにして運用ノイズを作らない
- **監視対象 (必須項目として登録する)**: **`php artisan billing:recover-stale-webhook-events`
  (scheduler で `*/5 * * * *`・`onOneServer()` + `withoutOverlapping()`)**。
  失敗は `onFailure` → `report()` で運用アラート経路に載る。観測点は 3 つ:
  1. `status='received'` かつ `updated_at <= now - 閾値` の件数
     (増え続ける = scheduler か本コマンドが動いていない)
  2. 本コマンド出力の `retry-scheduled` 件数 (再実行が失敗し続けている)
  3. `status='recovery_pending'` の件数 (理由は `recovery_reason`:
     `order_sensitive` / `attempts_exhausted`)
- **運用手順**: `recovery_reason` ごとに次の行動が違う。
  `order_sensitive` は Stripe ダッシュボードで現在の契約状態を確認する /
  `attempts_exhausted` は `failure_reason` があれば確認し、ログと Stripe 上の状態と
  合わせて手当てする (連続クラッシュでは NULL のことがある)
- **保証しないもの**: (1) 順序に依存する種類は自動復旧しない (契約状態は後続の
  `customer.subscription.updated` が追随する。初回無償付与だけは失われ得るので件数で拾う)。
  (2) 条件付き UPDATE が守るのは `stripe_webhook_events` 行の世代だけで、旧ワーカーと
  回収側の `process()` の**同時実行そのものは防がない** (付与の一回性は台帳の
  `idempotency_key` UNIQUE が担う)。(3) `report()` の配送は通知基盤の設定次第で、
  常設の観測点は件数のほうである。(4) HTTP 経路で `failed` になった行は回収 cron が拾わない。
  (5) 外部 API 遅延等で本当に閾値を超えた生存ワーカーがいた場合、順序に依存する種類の行は
  `recovery_pending` へ置かれ、そのワーカーが成功しても行は回収待ちのまま残る
  (業務側の副作用は正しく起きているのに、行だけ「要確認」に見える)
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

AGENTS.md の検証コマンドは**全 green でコミット**が要件なので、フロント変更の有無に
関わらず全レーンを実行する (「変わっていないはず」は確認ではない)。

```
composer test
composer phpstan
vendor/bin/pint --test
pnpm lint
pnpm typecheck
pnpm test
pnpm build
pnpm typecheck:packages
pnpm build:packages
pnpm test:packages
```

`composer test` / `pnpm test` / `pnpm test:packages` はホスト全体で 1 本ずつしか走らない
(T099 のグローバルテストロック)。待ちが出るのは正常で、30 秒ごとの heartbeat が
出ている間はハングではない。kill もロックファイルの手動削除もしない。

## 実装差分 (git diff)

```diff
diff --git a/app/DataTransferObjects/Billing/StaleWebhookClaimDto.php b/app/DataTransferObjects/Billing/StaleWebhookClaimDto.php
new file mode 100644
index 0000000..0b280fd
--- /dev/null
+++ b/app/DataTransferObjects/Billing/StaleWebhookClaimDto.php
@@ -0,0 +1,94 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\DataTransferObjects\Billing;
+
+use App\Enums\Billing\WebhookRecoveryReason;
+use App\Enums\Billing\WebhookStaleClaimOutcome;
+
+/**
+ * 滞留 webhook 1 件の受理結果 (読み取り専用スナップショット)。
+ *
+ * **Eloquent の Model をトランザクションの外へ持ち出さない**ための型
+ * (在メモリ状態と永続状態を混ぜない)。commit 後の処理 —— 再実行と通知 —— に要る値だけを持つ。
+ *
+ * 生成は名前付きコンストラクタ経由のみ。`reason` が非 NULL になるのは
+ * `MovedToRecoveryPending` のときだけで、その対応は型の側で閉じている。
+ *
+ * `payload` に中身が入るのは `ClaimedForReplay` のときだけである
+ * (回収待ちへ置く場合は再実行しないので保持しない = 1 件分の payload を無用に持ち回らない)。
+ */
+final readonly class StaleWebhookClaimDto
+{
+    /**
+     * @param  int  $attempts  受理**後**の値 (この世代を握っている印)
+     * @param  array<mixed>  $payload  保存済み payload (Model の cast をそのまま渡す)。
+     *                                 ClaimedForReplay 以外では空配列
+     */
+    private function __construct(
+        public WebhookStaleClaimOutcome $outcome,
+        public string $eventId,
+        public string $type,
+        public int $attempts,
+        public array $payload,
+        public ?WebhookRecoveryReason $reason,
+    ) {}
+
+    /**
+     * @param  array<mixed>  $payload
+     */
+    public static function claimedForReplay(
+        string $eventId,
+        string $type,
+        int $attempts,
+        array $payload,
+    ): self {
+        return new self(
+            WebhookStaleClaimOutcome::ClaimedForReplay,
+            $eventId,
+            $type,
+            $attempts,
+            $payload,
+            null,
+        );
+    }
+
+    public static function movedToRecoveryPending(
+        string $eventId,
+        string $type,
+        int $attempts,
+        WebhookRecoveryReason $reason,
+    ): self {
+        return new self(
+            WebhookStaleClaimOutcome::MovedToRecoveryPending,
+            $eventId,
+            $type,
+            $attempts,
+            [], // 再実行しないので payload は持たない
+            $reason,
+        );
+    }
+
+    /**
+     * 通知・ログの構造化 context (payload 本体は載せない = 外部由来の可変データを運用ログへ流さない)。
+     *
+     * @return array{
+     *     event_id: string,
+     *     type: string,
+     *     attempts: int,
+     *     outcome: string,
+     *     reason: string|null,
+     * }
+     */
+    public function logContext(): array
+    {
+        return [
+            'event_id' => $this->eventId,
+            'type' => $this->type,
+            'attempts' => $this->attempts,
+            'outcome' => $this->outcome->value,
+            'reason' => $this->reason?->value,
+        ];
+    }
+}
diff --git a/app/DataTransferObjects/Billing/WebhookRecoveryResultDto.php b/app/DataTransferObjects/Billing/WebhookRecoveryResultDto.php
new file mode 100644
index 0000000..97f5ac3
--- /dev/null
+++ b/app/DataTransferObjects/Billing/WebhookRecoveryResultDto.php
@@ -0,0 +1,28 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\DataTransferObjects\Billing;
+
+/**
+ * 滞留回収 1 実行分の結果。
+ *
+ * **任意メタデータ領域は持たせない** (`BillingRetentionPurgeResultDto` と同じ方針。
+ * 型で分からない領域を作ると organization id 等が運用ログへ漏れる)。
+ *
+ * 件数の意味:
+ *   replayed               = 再実行して processed まで終局した件数
+ *   retryScheduled         = 再実行が失敗し received のまま次回の回収へ回した件数
+ *   movedToRecoveryPending = 自動再実行の対象外として回収待ちへ置いた件数
+ *   skipped                = 何もしなかった件数 (受理条件を満たさない / 行が無い /
+ *                            書き込みが別の世代に追い越された)
+ */
+final readonly class WebhookRecoveryResultDto
+{
+    public function __construct(
+        public int $replayed,
+        public int $retryScheduled,
+        public int $movedToRecoveryPending,
+        public int $skipped,
+    ) {}
+}
diff --git a/app/Enums/Billing/HandledStripeWebhookEvent.php b/app/Enums/Billing/HandledStripeWebhookEvent.php
index 56cffd0..c3b002b 100644
--- a/app/Enums/Billing/HandledStripeWebhookEvent.php
+++ b/app/Enums/Billing/HandledStripeWebhookEvent.php
@@ -33,6 +33,31 @@ enum HandledStripeWebhookEvent: string
     // 返金 → 買い切りチケットの逆仕訳 (clawback)。「返金済みなのに消費可能」の整合性の穴を塞ぐ。
     case ChargeRefunded = 'charge.refunded';
 
+    /**
+     * 保存済み payload を再実行してよいか (滞留回収の判断材料。単一出典)。
+     *
+     * - `customer.subscription.*` は `SubscriptionService::applySubscriptionSnapshot` が
+     *   **後勝ちで上書きする** (イベントの新旧を判定する条件を持たない) ため、古い payload を
+     *   後から流すと `plan_code` / `current_period_end` / `stripe_status` が巻き戻る
+     * - それ以外は付与が台帳の `idempotency_key` UNIQUE (`monthly:` / `purchase:` /
+     *   `recharge:` / `refund:`) で冪等、`checkout` 行の遷移は `Completed` が終局で no-op、
+     *   `invoice.payment_failed` の通知は台帳 dedup、自動購入の失敗 Job は pending 限定
+     *
+     * **arm を足したら分類も足す** (網羅 match のため case 追加で必ず落ちる)。
+     */
+    public function replaySafety(): WebhookReplaySafety
+    {
+        return match ($this) {
+            self::SubscriptionCreated,
+            self::SubscriptionUpdated,
+            self::SubscriptionDeleted => WebhookReplaySafety::OrderSensitive,
+            self::InvoicePaid,
+            self::InvoicePaymentFailed,
+            self::CheckoutSessionCompleted,
+            self::ChargeRefunded => WebhookReplaySafety::SafeToReplay,
+        };
+    }
+
     /**
      * processor が明示処理する全イベント値。config 購読集合の導出と invariant に使う。
      *
diff --git a/app/Enums/Billing/WebhookEventStatus.php b/app/Enums/Billing/WebhookEventStatus.php
index 2e84e03..c7406a2 100644
--- a/app/Enums/Billing/WebhookEventStatus.php
+++ b/app/Enums/Billing/WebhookEventStatus.php
@@ -6,13 +6,20 @@
 
 /**
  * Stripe webhook イベントの処理状態。
- * processed は再処理しない (冪等 skip)。failed は Stripe の再送で再処理し得る。
+ *
+ * - `received`: 受理済み・未終局。**「処理中」と「次の回収待ち」を兼ねる**
+ *   (どちらかは `updated_at` が滞留の閾値を超えたかで区別する)
+ * - `processed`: 終局 (再処理しない)
+ * - `failed`: HTTP 経路での失敗。Stripe の再送で再処理し得る
+ * - `recovery_pending`: 滞留を検出したが**自動再実行の対象外**と判定して置いた静止状態。
+ *   理由は `stripe_webhook_events.recovery_reason` に残る。自動では二度と動かさない
  */
 enum WebhookEventStatus: string
 {
     case Received = 'received';
     case Processed = 'processed';
     case Failed = 'failed';
+    case RecoveryPending = 'recovery_pending';
 
     public function label(): string
     {
@@ -20,6 +27,7 @@ public function label(): string
             self::Received => '受信',
             self::Processed => '処理済',
             self::Failed => '失敗',
+            self::RecoveryPending => '回収待ち',
         };
     }
 }
diff --git a/app/Enums/Billing/WebhookRecoveryReason.php b/app/Enums/Billing/WebhookRecoveryReason.php
new file mode 100644
index 0000000..32a976e
--- /dev/null
+++ b/app/Enums/Billing/WebhookRecoveryReason.php
@@ -0,0 +1,31 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Enums\Billing;
+
+/**
+ * 滞留した webhook 記録を**回収待ちへ置いた理由**。
+ *
+ * 状態 (`WebhookEventStatus::RecoveryPending`) が「次にどう扱うか (自動では動かさない)」を、
+ * 本 enum が「なぜそこに置かれたか」を表す。運用の次の行動が理由ごとに違うため、
+ * 自由文の `failure_reason` とは列を分ける (機械判定できる値と混ぜない)。
+ *
+ * **不変条件**: `recovery_reason IS NOT NULL` ⟺ `status = recovery_pending`。
+ */
+enum WebhookRecoveryReason: string
+{
+    /** 順序に依存する種類なので再実行しない (再実行すると契約状態が巻き戻る)。 */
+    case OrderSensitive = 'order_sensitive';
+
+    /** 試行上限 (StripeWebhookProcessor::MAX_PROCESSING_ATTEMPTS) に到達済み。 */
+    case AttemptsExhausted = 'attempts_exhausted';
+
+    public function label(): string
+    {
+        return match ($this) {
+            self::OrderSensitive => '順序に依存するため再実行しない',
+            self::AttemptsExhausted => '試行上限に到達',
+        };
+    }
+}
diff --git a/app/Enums/Billing/WebhookReplaySafety.php b/app/Enums/Billing/WebhookReplaySafety.php
new file mode 100644
index 0000000..9b90e16
--- /dev/null
+++ b/app/Enums/Billing/WebhookReplaySafety.php
@@ -0,0 +1,25 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Enums\Billing;
+
+/**
+ * 保存済み webhook payload を**再実行してよいか**の分類。
+ *
+ * **`SafeToReplay` の意味は「再実行しても追加の被害を生まない」に限定される。**
+ * 「再実行すれば復旧する」ではない (復旧するかどうかは各ハンドラの事情による)。
+ *
+ * 分類の単一出典は `HandledStripeWebhookEvent::replaySafety()` の網羅 match で、
+ * 滞留回収 (`StripeWebhookProcessor::recoverStale`) が自動再実行の可否に使う唯一の判断材料。
+ * **ハンドラに副作用を足したら分類を再審査すること** (順序に依存する書き込みを足したら
+ * `OrderSensitive` へ移す)。
+ */
+enum WebhookReplaySafety: string
+{
+    /** 再実行しても追加の被害を生まない (付与は台帳の idempotency_key UNIQUE で冪等)。 */
+    case SafeToReplay = 'safe_to_replay';
+
+    /** 順序に依存する (古い payload を後から流すと状態が巻き戻る)。 */
+    case OrderSensitive = 'order_sensitive';
+}
diff --git a/app/Enums/Billing/WebhookStaleClaimOutcome.php b/app/Enums/Billing/WebhookStaleClaimOutcome.php
new file mode 100644
index 0000000..58d5084
--- /dev/null
+++ b/app/Enums/Billing/WebhookStaleClaimOutcome.php
@@ -0,0 +1,22 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Enums\Billing;
+
+/**
+ * 滞留した webhook 記録に対して回収が行った処置。
+ *
+ * `Skipped` を持たない理由: 「受理条件を満たさなかった」場合は
+ * `StripeWebhookProcessor::claimStale()` が `null` を返す (行が消えていた場合も同じ)。
+ * 「何もしなかった」を DTO の 1 case としても持つと表現が 2 通りになるため、
+ * **処置をしたときだけ DTO を作る**。
+ */
+enum WebhookStaleClaimOutcome: string
+{
+    /** 再実行のために受理した (attempts を 1 進めた)。 */
+    case ClaimedForReplay = 'claimed_for_replay';
+
+    /** 自動再実行の対象外と判定して回収待ちへ置いた。 */
+    case MovedToRecoveryPending = 'moved_to_recovery_pending';
+}
diff --git a/app/Models/Billing/StripeWebhookEvent.php b/app/Models/Billing/StripeWebhookEvent.php
index 31a3e3f..c2e3e69 100644
--- a/app/Models/Billing/StripeWebhookEvent.php
+++ b/app/Models/Billing/StripeWebhookEvent.php
@@ -5,6 +5,7 @@
 namespace App\Models\Billing;
 
 use App\Enums\Billing\WebhookEventStatus;
+use App\Enums\Billing\WebhookRecoveryReason;
 use Carbon\CarbonImmutable;
 use Database\Factories\Billing\StripeWebhookEventFactory;
 use Illuminate\Database\Eloquent\Factories\HasFactory;
@@ -23,6 +24,7 @@
  * @property array<mixed> $payload
  * @property int $attempts
  * @property string|null $failure_reason
+ * @property WebhookRecoveryReason|null $recovery_reason
  * @property CarbonImmutable|null $processed_at
  */
 class StripeWebhookEvent extends Model
@@ -37,6 +39,7 @@ protected function casts(): array
     {
         return [
             'status' => WebhookEventStatus::class,
+            'recovery_reason' => WebhookRecoveryReason::class,
             'payload' => 'array',
             'attempts' => 'integer',
             'processed_at' => 'immutable_datetime',
diff --git a/app/Services/Billing/StripeWebhookProcessor.php b/app/Services/Billing/StripeWebhookProcessor.php
index c4a8246..f277aff 100644
--- a/app/Services/Billing/StripeWebhookProcessor.php
+++ b/app/Services/Billing/StripeWebhookProcessor.php
@@ -4,11 +4,16 @@
 
 namespace App\Services\Billing;
 
+use App\DataTransferObjects\Billing\StaleWebhookClaimDto;
+use App\DataTransferObjects\Billing\WebhookRecoveryResultDto;
 use App\Enums\Billing\BillingNotificationType;
 use App\Enums\Billing\HandledStripeWebhookEvent;
 use App\Enums\Billing\SignupFundingChoice;
 use App\Enums\Billing\TicketCheckoutSessionStatus;
 use App\Enums\Billing\WebhookEventStatus;
+use App\Enums\Billing\WebhookRecoveryReason;
+use App\Enums\Billing\WebhookReplaySafety;
+use App\Enums\Billing\WebhookStaleClaimOutcome;
 use App\Enums\CheckoutIntent;
 use App\Enums\CheckoutSessionStatus;
 use App\Jobs\Billing\HandleAutoRechargeChargeFailureJob;
@@ -49,6 +54,12 @@
  * 4. 再送上限: failed→received 復帰のたびに attempts をインクリメントし、
  *    MAX_PROCESSING_ATTEMPTS 到達後は処理せず skip (= 200 terminal-ack) して
  *    恒久失敗イベントの無限 500 ストームを打ち切る (運用は failure_reason で調査する)
+ * 5. 滞留回収: 本処理中にプロセスが落ちて received のまま残った行を
+ *    recoverStale() が拾い直す (cron: billing:recover-stale-webhook-events)。
+ *    再実行してよい種類かは HandledStripeWebhookEvent::replaySafety() が決め、
+ *    対象外・上限到達は recovery_pending + recovery_reason へ置いて止める。
+ *    終局書き込みは受理した世代 (attempts) を握っている実行だけが行う条件付き UPDATE。
+ *    運用契約の正本は docs/architecture.md の「Stripe webhook の滞留回収」。
  *
  * subscriptions **行の作成** (updateOrCreate) は Cashier の WebhookController が唯一の
  * writer。本クラス (WebhookReceived listener) は Cashier のハンドラより先に走るため、
@@ -64,10 +75,18 @@
 class StripeWebhookProcessor
 {
     /**
-     * webhook 処理失敗の再送上限。attempts (failed→received 復帰回数) がこれに到達したら
-     * terminal とみなし処理せず 200 ack して Stripe の自動再送を打ち切る。
-     * claim() が transaction + lockForUpdate で状態遷移を直列化するため
-     * "processing 残留 stale" は生じず、復帰 sweep は不要。
+     * webhook 処理の試行上限。`attempts` がこれに到達したら terminal とみなす。
+     *
+     * `attempts` を増やす経路は 2 つある — `claim()` (Stripe 再送による failed→received 復帰) と
+     * `claimStale()` (滞留回収による受理)。上限は共通で、到達後は HTTP 経路なら処理せず
+     * 200 ack、回収経路なら `recovery_pending` + `AttemptsExhausted` へ置いて止める。
+     *
+     * **`claim()` の直列化は本処理までは覆わない** (守るのは状態遷移だけで `process()` は
+     * トランザクションの外で走る)。そこで落ちた行は `received` のまま残り、Stripe の再送も
+     * `claim()` に弾かれて 200 で終わるため付与が無音で失われる。これを塞ぐのが
+     * `recoverStale()` である。運用契約の正本は `docs/architecture.md`
+     * の「Stripe webhook の滞留回収」。
+     *
      * Stripe の自動再送窓 (~3 日) に対し 8 回で十分。
      */
     public const int MAX_PROCESSING_ATTEMPTS = 8;
@@ -97,21 +116,248 @@ public function handle(WebhookReceived $event): void
             return; // 同一 event_id を処理済み (冪等 skip)
         }
 
+        // 受理したときの世代 (claim 直後の attempts)。以降の書き込みはこの世代を握っている
+        // 実行だけが行える (滞留回収が attempts を進めた後の追い越し書き込みを防ぐ)。
+        $claimedAttempts = $record->attempts;
+
         try {
             $this->process($type, $payload);
         } catch (Throwable $exception) {
-            $record->status = WebhookEventStatus::Failed;
-            $record->failure_reason = $exception->getMessage();
-            $record->save();
+            $this->finalize($eventId, $claimedAttempts, WebhookEventStatus::Failed, $exception->getMessage());
             report($exception);
 
             throw $exception; // 200 を返さず Stripe の再送を促す (failed は再送で再処理)
         }
 
-        $record->status = WebhookEventStatus::Processed;
-        $record->failure_reason = null;
-        $record->processed_at = CarbonImmutable::now();
-        $record->save();
+        $this->finalize($eventId, $claimedAttempts, WebhookEventStatus::Processed, null);
+    }
+
+    /**
+     * 受理した世代を握っている実行だけが行える条件付き書き込み (CAS)。
+     *
+     * `status='received'` かつ `attempts=受理時の値` の 1 行だけを更新する。
+     * 0 件のときは**別の実行がその行を先に進めている** (滞留回収が claim し直した等) ので
+     * 何も書かずに記録だけ残す — 旧ワーカーが新しい世代の結果を上書きしない
+     * (ドメイン規約 6 の「条件付き UPDATE」)。
+     *
+     * `recovery_reason` は必ず NULL を置く
+     * (不変条件: 非 NULL ⟺ status = recovery_pending)。
+     *
+     * **保証範囲を誇張しない**: これが守るのは `stripe_webhook_events` 行の世代だけである。
+     * 旧ワーカーと回収側の `process()` は並行し得るので、付与の一回性は台帳の
+     * `idempotency_key` UNIQUE と各ハンドラの終局 guard が担う。
+     *
+     * @param  WebhookEventStatus  $status  Processed (終局) / Failed (HTTP 経路の失敗) /
+     *                                      Received (回収経路の失敗 = 終局させず次の回収へ回す)
+     * @return bool 書き込めたら true
+     */
+    private function finalize(
+        string $eventId,
+        int $claimedAttempts,
+        WebhookEventStatus $status,
+        ?string $failureReason,
+    ): bool {
+        $updated = StripeWebhookEvent::query()
+            ->where('event_id', $eventId)
+            ->where('status', WebhookEventStatus::Received->value)
+            ->where('attempts', $claimedAttempts)
+            ->update([
+                'status' => $status->value,
+                'failure_reason' => $failureReason,
+                'recovery_reason' => null,
+                'processed_at' => $status === WebhookEventStatus::Processed
+                    ? CarbonImmutable::now()
+                    : null,
+            ]);
+
+        if ($updated !== 1) {
+            Log::warning('stripe webhook: 別の実行が先に進めたため終局書き込みを見送った', [
+                'event_id' => $eventId,
+                'attempts' => $claimedAttempts,
+                'status' => $status->value,
+            ]);
+
+            return false;
+        }
+
+        return true;
+    }
+
+    /**
+     * 処理中に滞留した webhook 記録の回収 (cron: billing:recover-stale-webhook-events)。
+     *
+     * 対象は `status=received` かつ `updated_at` が滞留の閾値より古い行**だけ**。
+     * `failed` は Stripe の再送が再試行の駆動者なので拾わない。
+     *
+     * 作法は既存の滞留回収 (`RenderJobService::recoverStale` /
+     * `TicketLedgerService::releaseStale`) と同じ = 対象を列挙 → 1 件ずつ行ロックで
+     * 取り直して再検証 → 件数を返す。**共通の回収基盤は作らない** (ドメインごとの個別実装)。
+     *
+     * 通知 (`Log::warning` / `report()`) は**トランザクションの外**で出す
+     * (状態が保存されていないのに通知だけ出る / 同じ行に複数回出るのを避ける)。
+     * ただし commit 後に落ちれば 0 回になる = 送信を 1 回試みるだけで、
+     * 厳密な一回配送は保証しない (常設の観測点は `recovery_pending` の件数のほう)。
+     */
+    public function recoverStale(): WebhookRecoveryResultDto
+    {
+        $threshold = CarbonImmutable::now()
+            ->subMinutes(config()->integer('billing.webhook_stale_after_minutes'));
+
+        /** @var list<string> $staleEventIds */
+        $staleEventIds = StripeWebhookEvent::query()
+            ->where('status', WebhookEventStatus::Received->value)
+            ->where('updated_at', '<=', $threshold)
+            ->orderBy('id')
+            ->pluck('event_id')
+            ->all();
+
+        $replayed = 0;
+        $retryScheduled = 0;
+        $movedToRecoveryPending = 0;
+        $skipped = 0;
+
+        foreach ($staleEventIds as $eventId) {
+            $claim = $this->claimStale($eventId, $threshold);
+            if ($claim === null) {
+                $skipped++; // 行が消えた / 別の実行が先に進めた
+
+                continue;
+            }
+
+            if ($claim->outcome === WebhookStaleClaimOutcome::MovedToRecoveryPending) {
+                $movedToRecoveryPending++;
+                $this->reportRecoveryPending($claim);
+
+                continue;
+            }
+
+            try {
+                $this->process($claim->type, $claim->payload);
+            } catch (Throwable $exception) {
+                report($exception);
+                // **終局させない**: failed にすると回収対象 (received) から外れ、
+                // Stripe も配信成功と認識しているため二度と再試行されない。
+                // received のまま失敗理由だけ書いて次回の回収へ回す (attempts は消費済み)。
+                $this->finalize($claim->eventId, $claim->attempts, WebhookEventStatus::Received, $exception->getMessage())
+                    ? $retryScheduled++
+                    : $skipped++;
+
+                continue;
+            }
+
+            $this->finalize($claim->eventId, $claim->attempts, WebhookEventStatus::Processed, null)
+                ? $replayed++
+                : $skipped++;
+        }
+
+        return new WebhookRecoveryResultDto(
+            replayed: $replayed,
+            retryScheduled: $retryScheduled,
+            movedToRecoveryPending: $movedToRecoveryPending,
+            skipped: $skipped,
+        );
+    }
+
+    /**
+     * 滞留 1 件の受理。**状態遷移だけ**を 1 つのトランザクションで確定させ、
+     * commit 後に要る値をスナップショットで返す (通知はここでは出さない)。
+     *
+     * `claim()` (Stripe 再送の受理) とは入口が別なので分けてある。
+     * `claim()` は変更しない = `received` からの再受理は今までどおり起こらない。
+     *
+     * 滞留の再検証は**クエリの WHERE に入れる** (ロック取得後に PostgreSQL が述語を
+     * 再評価するため、ロック待ちの間に他の実行が前進させた行は 1 行も返らない)。
+     *
+     * @return StaleWebhookClaimDto|null 処置をしなかったとき (行が無い / 条件を満たさない) は null
+     */
+    private function claimStale(string $eventId, CarbonImmutable $threshold): ?StaleWebhookClaimDto
+    {
+        return DB::transaction(function () use ($eventId, $threshold): ?StaleWebhookClaimDto {
+            $record = StripeWebhookEvent::query()
+                ->where('event_id', $eventId)
+                ->where('status', WebhookEventStatus::Received->value)
+                ->where('updated_at', '<=', $threshold)
+                ->lockForUpdate()
+                ->first();
+
+            if (! $record instanceof StripeWebhookEvent) {
+                return null;
+            }
+
+            $reason = $this->recoveryReasonFor($record);
+            if ($reason !== null) {
+                $record->status = WebhookEventStatus::RecoveryPending;
+                $record->recovery_reason = $reason;
+                $record->save();
+
+                return StaleWebhookClaimDto::movedToRecoveryPending(
+                    $record->event_id,
+                    $record->type,
+                    $record->attempts,
+                    $reason,
+                );
+            }
+
+            // 世代を 1 つ進める (status は received のまま = 状態機械を増やさない)。
+            // updated_at も進むので、次の実行は閾値を超えるまでこの行を拾わない。
+            $record->attempts += 1;
+            $record->save();
+
+            return StaleWebhookClaimDto::claimedForReplay(
+                $record->event_id,
+                $record->type,
+                $record->attempts,
+                $record->payload,
+            );
+        });
+    }
+
+    /**
+     * 自動再実行の対象外と判定する理由 (無ければ null = 再実行してよい)。
+     *
+     * DB の `type` 文字列は **`tryFrom()`** で境界変換する (`from()` は未知値で例外になり
+     * cron 全体を止める)。`null` (本アプリが処理しない種類) は**再実行してよい側**に落ちる —
+     * `process()` の `null` arm は構造的に no-op で、通常経路でも `processed` になるため
+     * (同じ事実に 2 通りの決着を与えない)。
+     */
+    private function recoveryReasonFor(StripeWebhookEvent $record): ?WebhookRecoveryReason
+    {
+        $event = HandledStripeWebhookEvent::tryFrom($record->type);
+
+        // 本アプリが処理しない種類は**必ず**通常経路と同じ決着にする (再実行 → no-op → processed)。
+        // 試行上限より前に返すのが要点 — no-op に上限を適用して回収待ちへ置くと、
+        // 「未対応 type は通常経路と同じ」という契約が上限到達時だけ破れる。
+        if ($event === null) {
+            return null;
+        }
+
+        if ($event->replaySafety() === WebhookReplaySafety::OrderSensitive) {
+            return WebhookRecoveryReason::OrderSensitive;
+        }
+
+        if ($record->attempts >= self::MAX_PROCESSING_ATTEMPTS) {
+            return WebhookRecoveryReason::AttemptsExhausted;
+        }
+
+        return null;
+    }
+
+    /**
+     * 回収待ちへ置いたことの可観測化 (commit 後に 1 回だけ送信を試みる)。
+     * payload 本体は載せない (外部由来の可変データを運用ログへ流さない)。
+     */
+    private function reportRecoveryPending(StaleWebhookClaimDto $claim): void
+    {
+        Log::warning('stripe webhook: 滞留を回収待ちへ移した (自動再実行しない)', $claim->logContext());
+
+        report(new RuntimeException(sprintf(
+            'stripe webhook 回収待ち: %s (%s) reason=%s attempts=%d',
+            $claim->eventId,
+            $claim->type,
+            // 回収待ち以外の DTO では reason が無い (呼び出し側で絞っているが型では閉じていない)
+            $claim->reason->value ?? '',
+            $claim->attempts,
+        )));
     }
 
     /**
@@ -162,11 +408,15 @@ private function claim(string $eventId, string $type, array $payload): ?StripeWe
             }
 
             $record = new StripeWebhookEvent;
-            // 全カラム明示代入 (クライアント入力は入らない)
+            // 全カラム明示代入 (クライアント入力は入らない)。
+            // attempts は DB カラム default に依存せず INSERT 時に明示代入する —
+            // 受理直後の世代 (finalize の条件付き UPDATE が握る値) を
+            // 在メモリの instance から必ず読めるようにするため。
             $record->event_id = $eventId;
             $record->type = $type;
             $record->status = WebhookEventStatus::Received;
             $record->payload = $payload;
+            $record->attempts = 0;
             $record->save();
 
             return $record;
diff --git a/config/billing.php b/config/billing.php
index 153a9cc..5d4f934 100644
--- a/config/billing.php
+++ b/config/billing.php
@@ -49,6 +49,17 @@
     */
     'purchase_resume_window_minutes' => (int) env('BILLING_PURCHASE_RESUME_WINDOW_MINUTES', 30),
 
+    /*
+    | webhook 処理の滞留判定 (分)。`stripe_webhook_events.status='received'` のまま
+    | この時間を超えた行を「本処理中にプロセスが落ちた残留」とみなして回収する
+    | (billing:recover-stale-webhook-events)。
+    |
+    | env で振らない (環境ごとに変えてよい運用値ではない)。webhook の HTTP 処理は
+    | 秒オーダーで終わるため、生存中のワーカーを追い越さない十分な余裕を取ってある。
+    | 短くすると処理中の行を追い越し、長くすると付与の回復が遅れる。
+    */
+    'webhook_stale_after_minutes' => 15,
+
     /*
     |----------------------------------------------------------------------
     | オートリチャージ (裏チャージ。P8a)
diff --git a/database/factories/Billing/StripeWebhookEventFactory.php b/database/factories/Billing/StripeWebhookEventFactory.php
index 55e3add..8b7fbab 100644
--- a/database/factories/Billing/StripeWebhookEventFactory.php
+++ b/database/factories/Billing/StripeWebhookEventFactory.php
@@ -5,6 +5,7 @@
 namespace Database\Factories\Billing;
 
 use App\Enums\Billing\WebhookEventStatus;
+use App\Enums\Billing\WebhookRecoveryReason;
 use App\Models\Billing\StripeWebhookEvent;
 use Carbon\CarbonImmutable;
 use Illuminate\Database\Eloquent\Factories\Factory;
@@ -33,6 +34,7 @@ public function definition(): array
             'payload' => ['id' => 'evt_test', 'type' => 'customer.subscription.updated'],
             'attempts' => 0,
             'failure_reason' => null,
+            'recovery_reason' => null,
             'processed_at' => null,
         ];
     }
@@ -56,4 +58,30 @@ public function failed(): static
             'processed_at' => null,
         ]);
     }
+
+    /** 自動再実行の対象外として回収待ちに置かれた行。 */
+    public function recoveryPending(WebhookRecoveryReason $reason): static
+    {
+        return $this->state(fn (): array => [
+            'status' => WebhookEventStatus::RecoveryPending,
+            'recovery_reason' => $reason,
+            'processed_at' => null,
+        ]);
+    }
+
+    /**
+     * 受理済みのまま滞留している行 (updated_at を過去にずらす)。
+     *
+     * Eloquent は保存時に updated_at を now へ書き換えるため、**呼び出し側は保存後に
+     * updated_at を明示的に押し戻す**こと (この state だけでは滞留行にならない)。
+     */
+    public function stale(int $minutesAgo = 60): static
+    {
+        return $this->state(fn (): array => [
+            'status' => WebhookEventStatus::Received,
+            'recovery_reason' => null,
+            'processed_at' => null,
+            'updated_at' => CarbonImmutable::now()->subMinutes($minutesAgo),
+        ]);
+    }
 }
diff --git a/database/migrations/2026_08_15_000100_add_recovery_reason_to_stripe_webhook_events_table.php b/database/migrations/2026_08_15_000100_add_recovery_reason_to_stripe_webhook_events_table.php
new file mode 100644
index 0000000..128b9d4
--- /dev/null
+++ b/database/migrations/2026_08_15_000100_add_recovery_reason_to_stripe_webhook_events_table.php
@@ -0,0 +1,69 @@
+<?php
+
+declare(strict_types=1);
+
+use Illuminate\Database\Migrations\Migration;
+use Illuminate\Database\Schema\Blueprint;
+use Illuminate\Support\Facades\DB;
+use Illuminate\Support\Facades\Schema;
+
+return new class extends Migration
+{
+    /**
+     * 滞留した webhook 記録を回収待ちへ置いた理由 (App\Enums\Billing\WebhookRecoveryReason)
+     * と、滞留回収 cron が使う複合 index。
+     *
+     * 不変条件: recovery_reason が非 NULL ⟺ status = 'recovery_pending'。既存行はすべて NULL
+     * (回収待ちの行はこの migration の時点で 1 件も存在しない)。
+     * 自由文の failure_reason とは分ける (機械判定できる値と混ぜない)。
+     *
+     * index: billing:recover-stale-webhook-events が 5 分ごとに
+     * `status='received' AND updated_at <= 閾値` を引く。本表は保持期限 (7 年) まで
+     * 残るため単調に増える = 全表走査にしない。
+     * 監視で使う status='recovery_pending' の件数も同じ index の先頭列で効く。
+     */
+    public function up(): void
+    {
+        Schema::table('stripe_webhook_events', function (Blueprint $table) {
+            $table->string('recovery_reason')->nullable()->after('failure_reason');
+            $table->index(['status', 'updated_at'], 'stripe_webhook_events_status_updated_at_index');
+        });
+
+        // CHECK は sqlite の ALTER TABLE ADD CONSTRAINT 非対応のため driver guard
+        // (既存 ticket_auto_recharges の CHECK と同じ作法)。
+        // 全 driver 共通の防御はアプリ層 (StripeWebhookProcessor の書き込み経路) が担う。
+        if (in_array(DB::connection()->getDriverName(), ['pgsql', 'mysql'], true)) {
+            DB::statement(
+                'ALTER TABLE stripe_webhook_events ADD CONSTRAINT stripe_webhook_events_recovery_reason_state_check '
+                ."CHECK ((recovery_reason IS NULL AND status <> 'recovery_pending') "
+                ."OR (recovery_reason IS NOT NULL AND status = 'recovery_pending'))",
+            );
+        }
+    }
+
+    public function down(): void
+    {
+        // CHECK の削除構文は driver で違う (pgsql は DROP CONSTRAINT / mysql は DROP CHECK)。
+        // 共通化できないので分けて書く。sqlite は up() で作っていないので何もしない。
+        $driver = DB::connection()->getDriverName();
+
+        if ($driver === 'pgsql') {
+            DB::statement(
+                'ALTER TABLE stripe_webhook_events DROP CONSTRAINT IF EXISTS '
+                .'stripe_webhook_events_recovery_reason_state_check',
+            );
+        }
+
+        if ($driver === 'mysql') {
+            DB::statement(
+                'ALTER TABLE stripe_webhook_events DROP CHECK '
+                .'stripe_webhook_events_recovery_reason_state_check',
+            );
+        }
+
+        Schema::table('stripe_webhook_events', function (Blueprint $table) {
+            $table->dropIndex('stripe_webhook_events_status_updated_at_index');
+            $table->dropColumn('recovery_reason');
+        });
+    }
+};
diff --git a/docs/architecture.md b/docs/architecture.md
index 0857439..2f050f7 100644
--- a/docs/architecture.md
+++ b/docs/architecture.md
@@ -148,7 +148,7 @@ ## ドメインモデル (テンプレート同梱)
 | `ModelAudit` | Critical Action 中のモデル属性 diff 監査 (owen-it/laravel-auditing。CriticalActionContext active 時のみ記録) | tenant 外 |
 | `Billing/Plan` / `Billing/PlanPrice` | プラン定義と Stripe Price 対応 | tenant 外 (マスタ) |
 | `Billing/OrganizationQuota` | 組織ごとの利用上限 | Organization 従属 |
-| `Billing/StripeWebhookEvent` | Stripe webhook の冪等マシン | tenant 外 |
+| `Billing/StripeWebhookEvent` | Stripe webhook の冪等マシン (滞留を回収待ちへ置いた理由は `recovery_reason`) | tenant 外 |
 | `Billing/TicketLedgerEntry` / `Billing/TicketReservation` | チケット台帳 (reserve→commit/release の 2 フェーズ。期限付き付与・idempotency_key 冪等付与・返金 clawback) | Organization 従属 |
 | `Billing/TicketVolumePrice` | スポット購入の数量逐減 (volume tier) 単価の Stripe Price snapshot | tenant 外 (マスタ) |
 | `Billing/TicketCheckoutSession` | チケットスポット購入の Stripe Checkout Session 追跡 (attempt_token 冪等 + 単価 pin = webhook 金額照合の出典。status: pending/completed/expired) | Organization 従属 |
@@ -860,6 +860,47 @@ ## サブスク契約 Checkout とオンボーディング着地 (P7/P9) の運
     `expired_checkout` が「有償契約後の支払い不健全」と「checkout 期限切れの未契約」を
     ともに指す多義性は残る。
 
+## Stripe webhook の滞留回収
+
+- **状態の意味**: `received` = 受理済み・未終局 (**処理中と次の回収待ちを兼ねる**。
+  どちらかは `updated_at` が `config('billing.webhook_stale_after_minutes')` (15 分) を
+  超えたかで区別する) / `processed` = 終局 / `failed` = HTTP 経路の失敗 (Stripe の再送が
+  再試行の駆動者) / `recovery_pending` = 自動再実行の対象外として置いた静止状態
+- **なぜ回収が要るか**: `claim()` が直列化するのは状態遷移だけで `process()` は
+  トランザクションの外にある。そこで落ちた行は `received` のまま残り、Stripe の再送は
+  `claim()` に弾かれて 200 で終わる → Stripe も再送を打ち切る = **付与が無音で失われる**
+- **回収してよい種類**: `HandledStripeWebhookEvent::replaySafety()` の 2 値分類が**唯一の**
+  判断材料。`SafeToReplay` の意味は「再実行しても追加の被害を生まない」であって
+  「再実行すれば復旧する」ではない。**ハンドラに副作用を足したら分類を再審査すること**
+  (順序に依存する書き込みを足したら `OrderSensitive` へ移す。機械では検出できない)
+- **回収の失敗は終局させない**: 再実行が例外になっても `received` のままにして
+  `failure_reason` だけ書く (`failed` にすると回収対象から外れ、Stripe も再送しないため
+  二度と再試行されない)。`attempts` は消費されるので上限 8 で必ず止まる
+- **処理対象外の種類**: `HandledStripeWebhookEvent` に無い type は通常経路と同じく
+  再実行して `processed` にする (`process()` の `null` arm は構造的に no-op)。
+  回収だけ別扱いにして運用ノイズを作らない
+- **監視対象 (必須項目として登録する)**: **`php artisan billing:recover-stale-webhook-events`
+  (scheduler で 5 分ごと・`onOneServer()` + `withoutOverlapping()`)**。
+  失敗は `onFailure` → `report()` で運用アラート経路に載る。観測点は 3 つ:
+  1. `status='received'` かつ `updated_at <= now - 閾値` の件数
+     (増え続ける = scheduler か本コマンドが動いていない)
+  2. 本コマンド出力の `retry-scheduled` 件数 (再実行が失敗し続けている)
+  3. `status='recovery_pending'` の件数 (理由は `recovery_reason`:
+     `order_sensitive` / `attempts_exhausted`)
+- **運用手順**: `recovery_reason` ごとに次の行動が違う。
+  `order_sensitive` は Stripe ダッシュボードで現在の契約状態を確認する /
+  `attempts_exhausted` は `failure_reason` があれば確認し、ログと Stripe 上の状態と
+  合わせて手当てする (連続クラッシュでは NULL のことがある)
+- **保証しないもの**: (1) 順序に依存する種類は自動復旧しない (契約状態は後続の
+  `customer.subscription.updated` が追随する。初回無償付与だけは失われ得るので件数で拾う)。
+  (2) 条件付き UPDATE が守るのは `stripe_webhook_events` 行の世代だけで、旧ワーカーと
+  回収側の `process()` の**同時実行そのものは防がない** (付与の一回性は台帳の
+  `idempotency_key` UNIQUE が担う)。(3) `report()` の配送は通知基盤の設定次第で、
+  常設の観測点は件数のほうである。(4) HTTP 経路で `failed` になった行は回収 cron が拾わない。
+  (5) 外部 API 遅延等で本当に閾値を超えた生存ワーカーがいた場合、順序に依存する種類の行は
+  `recovery_pending` へ置かれ、そのワーカーが成功しても行は回収待ちのまま残る
+  (業務側の副作用は正しく起きているのに、行だけ「要確認」に見える)
+
 ## チケットスポット購入 (T007) の運用契約
 
 - **経路**: `GET /purchase-tickets` (閲覧 = 組織メンバー) / `POST /purchase-tickets/checkout`
@@ -880,7 +921,9 @@ ## チケットスポット購入 (T007) の運用契約
   対応: `stripe_webhook_events.failure_reason` を参照し、Stripe ダッシュボードで決済状態を確認 →
   決済済み・未付与が確定した場合のみ tinker 等で `TicketLedgerService::grantPurchased()` を
   手動実行する (idempotency_key `purchase:{sessionId}` により再実行しても二重付与しない)。
-  併せて `ticket_checkout_sessions` 行を completed 化する
+  併せて `ticket_checkout_sessions` 行を completed 化する。
+  **滞留 (`received` のまま残った) 分は `billing:recover-stale-webhook-events` が回収する**ので、
+  手動付与の前にその経路で決着していないかを確認する (§Stripe webhook の滞留回収)
 - **放棄 session の回収**: Stripe Checkout 自体の有効期限 (既定 24h) で Stripe 側が expire し、
   DB 行は checkout 開始時の期限切れ回収 (`status=pending AND expires_at <= now` → expired) で
   局所回収する (専用 cron は作らない)
diff --git a/routes/console.php b/routes/console.php
index 7595ab2..b57e8dc 100644
--- a/routes/console.php
+++ b/routes/console.php
@@ -3,6 +3,7 @@
 declare(strict_types=1);
 
 use App\Services\Billing\AccountDeletionBillingGuard;
+use App\Services\Billing\StripeWebhookProcessor;
 use App\Services\Billing\TicketLedgerService;
 use App\Services\Capture\StaleUploadReservationSweeper;
 use App\Services\Manual\AnalysisJobService;
@@ -29,6 +30,41 @@
 
 Schedule::command('billing:release-stale-reservations')->everyFiveMinutes();
 
+/*
+|--------------------------------------------------------------------------
+| Stripe webhook の滞留回収
+|--------------------------------------------------------------------------
+| 本処理中にプロセスが落ちて status='received' のまま残った記録を再処理へ戻す。
+| 放置すると Stripe の再送は claim() に弾かれて 200 で終わり、Stripe 側も配信成功と
+| 判断して再送を打ち切るため、決済済みチケットの付与が**無音で失われる**。
+|
+| **監視対象 (必須)**: 本コマンドの report() と、次の 3 つの件数。
+|   1. status='received' かつ updated_at が滞留の閾値より古い行の件数
+|      (増え続ける = scheduler か本コマンドが動いていない)
+|   2. 本コマンド出力の retry-scheduled 件数 (再実行が失敗し続けている)
+|   3. status='recovery_pending' の件数 (自動再実行の対象外として置かれた行。
+|      理由は recovery_reason 列)
+| 詳細は docs/architecture.md の「Stripe webhook の滞留回収」が正本。
+*/
+Artisan::command('billing:recover-stale-webhook-events', function (StripeWebhookProcessor $webhooks) {
+    $result = $webhooks->recoverStale();
+    $this->info(sprintf(
+        'replayed %d / retry-scheduled %d / moved-to-recovery-pending %d / skipped %d',
+        $result->replayed,
+        $result->retryScheduled,
+        $result->movedToRecoveryPending,
+        $result->skipped,
+    ));
+})->purpose('処理中に滞留した Stripe webhook 記録を再処理へ戻す');
+
+Schedule::command('billing:recover-stale-webhook-events')
+    ->everyFiveMinutes()
+    ->onOneServer()
+    ->withoutOverlapping()
+    ->onFailure(static fn () => report(new RuntimeException(
+        'billing:recover-stale-webhook-events 失敗 — 決済済み・チケット未付与が滞留する可能性',
+    )));
+
 /*
 |--------------------------------------------------------------------------
 | 課金 daily バッチ
diff --git a/tests/Architecture/BillingRetentionConfigSingleSourceTest.php b/tests/Architecture/BillingRetentionConfigSingleSourceTest.php
index 9094d08..57c5c54 100644
--- a/tests/Architecture/BillingRetentionConfigSingleSourceTest.php
+++ b/tests/Architecture/BillingRetentionConfigSingleSourceTest.php
@@ -125,6 +125,9 @@
     'tests/Architecture/BillingRetentionConfigSingleSourceTest.php',
     'tests/Feature/Billing/BillingRetentionHorizonTest.php',
     'tests/Feature/Billing/BillingRetentionPurgeTest.php',
+    // 滞留回収の行 (recovery_pending / received) が保持期限を超えても消えないことの固定。
+    // 期限超過の起点を SSOT から作るため threshold() に依存する。
+    'tests/Feature/Billing/StripeWebhookStaleRecoveryTest.php',
     'tests/Feature/Billing/TicketLedgerCarryForwardTest.php',
     'tests/Feature/Legal/PrivacyRetentionDeclarationTest.php',
 ];
diff --git a/tests/Feature/Billing/StripeWebhookStaleRecoveryTest.php b/tests/Feature/Billing/StripeWebhookStaleRecoveryTest.php
new file mode 100644
index 0000000..7c87793
--- /dev/null
+++ b/tests/Feature/Billing/StripeWebhookStaleRecoveryTest.php
@@ -0,0 +1,544 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\Billing\PlanPriceKind;
+use App\Enums\Billing\WebhookEventStatus;
+use App\Enums\Billing\WebhookRecoveryReason;
+use App\Models\Billing\Plan;
+use App\Models\Billing\StripeWebhookEvent;
+use App\Models\Billing\TicketCheckoutSession;
+use App\Models\Organization;
+use App\Models\User;
+use App\Services\Billing\StripeWebhookProcessor;
+use App\Services\Billing\TicketLedgerService;
+use App\Support\Legal\BillingRetention;
+use Carbon\CarbonImmutable;
+use Illuminate\Database\Migrations\Migration;
+use Illuminate\Database\QueryException;
+use Illuminate\Support\Facades\DB;
+use Illuminate\Support\Facades\Schema;
+use Laravel\Cashier\Events\WebhookReceived;
+use Webmozart\Assert\Assert;
+
+/*
+ * 滞留 webhook の回収 (StripeWebhookProcessor::recoverStale) と、
+ * 受理した世代を握っている実行だけが行う終局書き込み (finalize の条件付き UPDATE)。
+ *
+ * 背景: claim() が直列化するのは状態遷移だけで process() はトランザクションの外にある。
+ * そこで落ちた行は received のまま残り、Stripe の再送は claim() に弾かれて 200 で終わるため、
+ * 決済済みチケットの付与が無音で失われる。
+ */
+
+/**
+ * stripe_id を持つ組織と owner を作る。
+ *
+ * @return array{Organization, User}
+ */
+function staleRecoveryFixture(): array
+{
+    [$organization, $owner] = createOrganizationWithOwner();
+    $organization->stripe_id = 'cus_stale_recovery_1';
+    $organization->save();
+
+    return [$organization, $owner];
+}
+
+/** standard プランの現行 base Price の Stripe Price ID。 */
+function staleRecoveryBasePriceId(): string
+{
+    $price = Plan::query()->where('code', 'standard')->firstOrFail()
+        ->currentPrice(PlanPriceKind::Base);
+    Assert::notNull($price, 'standard プランの current base price が未 seed');
+
+    return $price->stripe_price_id;
+}
+
+/**
+ * @return array<string, mixed>
+ */
+function staleRecoveryInvoicePaidPayload(string $eventId, string $invoiceId = 'in_stale_1'): array
+{
+    return [
+        'id' => $eventId,
+        'type' => 'invoice.paid',
+        'data' => [
+            'object' => [
+                'id' => $invoiceId,
+                'customer' => 'cus_stale_recovery_1',
+                'billing_reason' => 'subscription_cycle',
+                'lines' => [
+                    'data' => [
+                        ['price' => ['id' => staleRecoveryBasePriceId()]],
+                    ],
+                ],
+            ],
+        ],
+    ];
+}
+
+/**
+ * @return array<string, mixed>
+ */
+function staleRecoveryTicketPurchasePayload(string $eventId, Organization $organization): array
+{
+    return [
+        'id' => $eventId,
+        'type' => 'checkout.session.completed',
+        'data' => [
+            'object' => [
+                'id' => 'cs_stale_1',
+                'mode' => 'payment',
+                'customer' => 'cus_stale_recovery_1',
+                'payment_status' => 'paid',
+                'payment_intent' => 'pi_stale_1',
+                'amount_subtotal' => 30 * 80,
+                'currency' => 'jpy',
+                'metadata' => [
+                    'purpose' => 'ticket_purchase',
+                    'org_ref' => (string) $organization->id,
+                    'count' => '30',
+                ],
+            ],
+        ],
+    ];
+}
+
+/**
+ * @return array<string, mixed>
+ */
+function staleRecoverySubscriptionPayload(string $eventId, string $type = 'customer.subscription.updated'): array
+{
+    return [
+        'id' => $eventId,
+        'type' => $type,
+        'data' => [
+            'object' => [
+                'id' => 'sub_stale_1',
+                'customer' => 'cus_stale_recovery_1',
+                'status' => 'active',
+                'items' => [
+                    'data' => [
+                        ['price' => ['id' => staleRecoveryBasePriceId()]],
+                    ],
+                ],
+            ],
+        ],
+    ];
+}
+
+/**
+ * received のまま滞留している記録を作る。
+ *
+ * Eloquent は保存時に updated_at を now へ書き換えるため、保存後に明示的に押し戻す
+ * (Factory の state だけでは滞留行にならない)。
+ *
+ * @param  array<mixed>  $payload
+ */
+function staleWebhookRecord(
+    string $eventId,
+    string $type,
+    array $payload,
+    int $attempts = 0,
+    int $minutesAgo = 60,
+): StripeWebhookEvent {
+    $record = StripeWebhookEvent::factory()->stale($minutesAgo)->create([
+        'event_id' => $eventId,
+        'type' => $type,
+        'payload' => $payload,
+        'attempts' => $attempts,
+    ]);
+
+    pushBackWebhookUpdatedAt($eventId, $minutesAgo);
+
+    return $record->refresh();
+}
+
+/** updated_at を過去へ押し戻す (滞留判定を跨がせる)。 */
+function pushBackWebhookUpdatedAt(string $eventId, int $minutesAgo): void
+{
+    StripeWebhookEvent::query()
+        ->where('event_id', $eventId)
+        ->update(['updated_at' => CarbonImmutable::now()->subMinutes($minutesAgo)]);
+}
+
+/** 台帳の不変条件: recovery_reason が非 NULL ⟺ status = recovery_pending。 */
+function assertRecoveryReasonInvariant(): void
+{
+    expect(StripeWebhookEvent::query()
+        ->whereNotNull('recovery_reason')
+        ->where('status', '!=', WebhookEventStatus::RecoveryPending->value)
+        ->count())->toBe(0);
+    expect(StripeWebhookEvent::query()
+        ->where('status', WebhookEventStatus::RecoveryPending->value)
+        ->whereNull('recovery_reason')
+        ->count())->toBe(0);
+}
+
+test('滞留した checkout.session.completed は回収で付与され processed になる', function (): void {
+    [$organization, $owner] = staleRecoveryFixture();
+    TicketCheckoutSession::factory()
+        ->forOrganization($organization)
+        ->initiatedBy($owner)
+        ->create([
+            'ticket_count' => 30,
+            'unit_amount' => 80,
+            'currency' => 'jpy',
+            'stripe_session_id' => 'cs_stale_1',
+        ]);
+    staleWebhookRecord(
+        'evt_stale_purchase',
+        'checkout.session.completed',
+        staleRecoveryTicketPurchasePayload('evt_stale_purchase', $organization),
+    );
+
+    $result = app(StripeWebhookProcessor::class)->recoverStale();
+
+    expect($result->replayed)->toBe(1);
+    expect(app(TicketLedgerService::class)->balance($organization)->totalAvailable())->toBe(30);
+
+    $record = StripeWebhookEvent::query()->where('event_id', 'evt_stale_purchase')->firstOrFail();
+    expect($record->status)->toBe(WebhookEventStatus::Processed);
+    expect($record->processed_at)->not->toBeNull();
+    expect($record->recovery_reason)->toBeNull();
+    assertRecoveryReasonInvariant();
+});
+
+test('滞留した invoice.paid は回収で月次付与される', function (): void {
+    [$organization] = staleRecoveryFixture();
+    Plan::query()->where('code', 'standard')->update(['monthly_ticket_grant' => 100]);
+    staleWebhookRecord(
+        'evt_stale_invoice',
+        'invoice.paid',
+        staleRecoveryInvoicePaidPayload('evt_stale_invoice'),
+    );
+
+    $result = app(StripeWebhookProcessor::class)->recoverStale();
+
+    expect($result->replayed)->toBe(1);
+    expect(app(TicketLedgerService::class)->balance($organization)->totalAvailable())->toBe(100);
+    expect(StripeWebhookEvent::query()->where('event_id', 'evt_stale_invoice')->firstOrFail()->status)
+        ->toBe(WebhookEventStatus::Processed);
+});
+
+test('回収で付与した後に Stripe 再送が来ても二重付与しない', function (): void {
+    [$organization, $owner] = staleRecoveryFixture();
+    TicketCheckoutSession::factory()
+        ->forOrganization($organization)
+        ->initiatedBy($owner)
+        ->create([
+            'ticket_count' => 30,
+            'unit_amount' => 80,
+            'currency' => 'jpy',
+            'stripe_session_id' => 'cs_stale_1',
+        ]);
+    staleWebhookRecord(
+        'evt_stale_purchase',
+        'checkout.session.completed',
+        staleRecoveryTicketPurchasePayload('evt_stale_purchase', $organization),
+    );
+
+    app(StripeWebhookProcessor::class)->recoverStale();
+    // 別 event_id での再通知 (event_id 冪等では防げない経路)
+    event(new WebhookReceived(staleRecoveryTicketPurchasePayload('evt_resend_purchase', $organization)));
+
+    expect($organization->ticketLedgerEntries()->where('idempotency_key', 'purchase:cs_stale_1')->count())
+        ->toBe(1);
+    expect(app(TicketLedgerService::class)->balance($organization)->totalAvailable())->toBe(30);
+});
+
+test('順序に依存する種類の滞留は再実行せず回収待ちへ置く', function (): void {
+    [$organization] = staleRecoveryFixture();
+    expect($organization->plan_code)->toBeNull();
+    staleWebhookRecord(
+        'evt_stale_sub',
+        'customer.subscription.updated',
+        staleRecoverySubscriptionPayload('evt_stale_sub'),
+    );
+
+    $result = app(StripeWebhookProcessor::class)->recoverStale();
+
+    expect($result->movedToRecoveryPending)->toBe(1);
+    expect($result->replayed)->toBe(0);
+
+    $record = StripeWebhookEvent::query()->where('event_id', 'evt_stale_sub')->firstOrFail();
+    expect($record->status)->toBe(WebhookEventStatus::RecoveryPending);
+    expect($record->recovery_reason)->toBe(WebhookRecoveryReason::OrderSensitive);
+    // 状態は書き換わっていない (再実行していない)
+    expect($organization->refresh()->plan_code)->toBeNull();
+
+    // 回収待ちの行に Stripe 再送が来ても claim() が受理しない (状態が巻き戻らない)
+    event(new WebhookReceived(staleRecoverySubscriptionPayload('evt_stale_sub')));
+
+    expect($organization->refresh()->plan_code)->toBeNull();
+    expect(StripeWebhookEvent::query()->where('event_id', 'evt_stale_sub')->firstOrFail()->status)
+        ->toBe(WebhookEventStatus::RecoveryPending);
+    assertRecoveryReasonInvariant();
+});
+
+test('試行上限に到達した滞留は再実行せず回収待ちへ置く', function (): void {
+    [$organization] = staleRecoveryFixture();
+    Plan::query()->where('code', 'standard')->update(['monthly_ticket_grant' => 100]);
+    staleWebhookRecord(
+        'evt_stale_exhausted',
+        'invoice.paid',
+        staleRecoveryInvoicePaidPayload('evt_stale_exhausted'),
+        attempts: StripeWebhookProcessor::MAX_PROCESSING_ATTEMPTS,
+    );
+
+    $result = app(StripeWebhookProcessor::class)->recoverStale();
+
+    expect($result->movedToRecoveryPending)->toBe(1);
+    $record = StripeWebhookEvent::query()->where('event_id', 'evt_stale_exhausted')->firstOrFail();
+    expect($record->status)->toBe(WebhookEventStatus::RecoveryPending);
+    expect($record->recovery_reason)->toBe(WebhookRecoveryReason::AttemptsExhausted);
+    expect(app(TicketLedgerService::class)->balance($organization)->totalAvailable())->toBe(0);
+    assertRecoveryReasonInvariant();
+});
+
+test('本アプリが処理しない種類の滞留は通常経路と同じく processed になる', function (): void {
+    [$organization] = staleRecoveryFixture();
+    staleWebhookRecord('evt_stale_unhandled', 'customer.updated', [
+        'id' => 'evt_stale_unhandled',
+        'type' => 'customer.updated',
+        'data' => ['object' => ['id' => 'cus_stale_recovery_1']],
+    ]);
+
+    $result = app(StripeWebhookProcessor::class)->recoverStale();
+
+    expect($result->replayed)->toBe(1);
+    expect($result->movedToRecoveryPending)->toBe(0);
+    $record = StripeWebhookEvent::query()->where('event_id', 'evt_stale_unhandled')->firstOrFail();
+    expect($record->status)->toBe(WebhookEventStatus::Processed);
+    expect($record->recovery_reason)->toBeNull();
+    // 副作用が何も起きない
+    expect($organization->ticketLedgerEntries()->count())->toBe(0);
+    expect($organization->refresh()->plan_code)->toBeNull();
+});
+
+test('本アプリが処理しない種類は試行上限に到達していても processed になる', function (): void {
+    staleRecoveryFixture();
+    staleWebhookRecord('evt_stale_unhandled_max', 'customer.updated', [
+        'id' => 'evt_stale_unhandled_max',
+        'type' => 'customer.updated',
+        'data' => ['object' => ['id' => 'cus_stale_recovery_1']],
+    ], attempts: StripeWebhookProcessor::MAX_PROCESSING_ATTEMPTS);
+
+    app(StripeWebhookProcessor::class)->recoverStale();
+
+    expect(StripeWebhookEvent::query()->where('event_id', 'evt_stale_unhandled_max')->firstOrFail()->status)
+        ->toBe(WebhookEventStatus::Processed);
+});
+
+test('滞留の閾値内の received 行には触らない', function (): void {
+    [$organization] = staleRecoveryFixture();
+    Plan::query()->where('code', 'standard')->update(['monthly_ticket_grant' => 100]);
+    staleWebhookRecord(
+        'evt_fresh',
+        'invoice.paid',
+        staleRecoveryInvoicePaidPayload('evt_fresh'),
+        minutesAgo: 5,
+    );
+
+    $result = app(StripeWebhookProcessor::class)->recoverStale();
+
+    expect($result->replayed)->toBe(0);
+    expect($result->skipped)->toBe(0);
+    $record = StripeWebhookEvent::query()->where('event_id', 'evt_fresh')->firstOrFail();
+    expect($record->status)->toBe(WebhookEventStatus::Received);
+    expect($record->attempts)->toBe(0);
+    expect(app(TicketLedgerService::class)->balance($organization)->totalAvailable())->toBe(0);
+});
+
+test('回収の再実行が失敗しても終局させず次回の回収へ回す (最後は試行上限で止まる)', function (): void {
+    staleRecoveryFixture();
+    Plan::query()->where('code', 'standard')->update(['monthly_ticket_grant' => 100]);
+    $this->mock(TicketLedgerService::class)
+        ->shouldReceive('grantMonthly')
+        ->andThrow(new RuntimeException('付与処理の一時故障'));
+
+    staleWebhookRecord(
+        'evt_stale_retry',
+        'invoice.paid',
+        staleRecoveryInvoicePaidPayload('evt_stale_retry'),
+    );
+
+    $result = app(StripeWebhookProcessor::class)->recoverStale();
+
+    expect($result->retryScheduled)->toBe(1);
+    $record = StripeWebhookEvent::query()->where('event_id', 'evt_stale_retry')->firstOrFail();
+    expect($record->status)->toBe(WebhookEventStatus::Received); // 終局させない
+    expect($record->failure_reason)->toBe('付与処理の一時故障');
+    expect($record->attempts)->toBe(1);
+
+    // 閾値を再び超えさせて繰り返すと attempts が上限まで進み、最後は回収待ちで止まる
+    for ($i = 0; $i < StripeWebhookProcessor::MAX_PROCESSING_ATTEMPTS + 1; $i++) {
+        pushBackWebhookUpdatedAt('evt_stale_retry', 60);
+        app(StripeWebhookProcessor::class)->recoverStale();
+    }
+
+    $record = StripeWebhookEvent::query()->where('event_id', 'evt_stale_retry')->firstOrFail();
+    expect($record->status)->toBe(WebhookEventStatus::RecoveryPending);
+    expect($record->recovery_reason)->toBe(WebhookRecoveryReason::AttemptsExhausted);
+    expect($record->attempts)->toBe(StripeWebhookProcessor::MAX_PROCESSING_ATTEMPTS);
+    assertRecoveryReasonInvariant();
+});
+
+test('回収中に別の実行が世代を進めたら件数は skipped に計上する', function (): void {
+    staleRecoveryFixture();
+    Plan::query()->where('code', 'standard')->update(['monthly_ticket_grant' => 100]);
+    $this->mock(TicketLedgerService::class)
+        ->shouldReceive('grantMonthly')
+        ->andReturnUsing(function (): void {
+            // 別の実行が世代を進めた状況 (単一プロセスで追い越しだけを再現する)
+            StripeWebhookEvent::query()->where('event_id', 'evt_stale_overtaken')->update(['attempts' => 5]);
+
+            throw new RuntimeException('付与処理の一時故障');
+        });
+
+    staleWebhookRecord(
+        'evt_stale_overtaken',
+        'invoice.paid',
+        staleRecoveryInvoicePaidPayload('evt_stale_overtaken'),
+    );
+
+    $result = app(StripeWebhookProcessor::class)->recoverStale();
+
+    expect($result->skipped)->toBe(1);
+    expect($result->retryScheduled)->toBe(0);
+    $record = StripeWebhookEvent::query()->where('event_id', 'evt_stale_overtaken')->firstOrFail();
+    expect($record->attempts)->toBe(5); // 追い越した側の値が残る
+    expect($record->failure_reason)->toBeNull(); // 旧世代は何も書かない
+});
+
+test('HTTP 経路で世代を追い越されたら終局書き込みを見送り例外も投げない', function (): void {
+    [$organization] = staleRecoveryFixture();
+    Plan::query()->where('code', 'standard')->update(['monthly_ticket_grant' => 100]);
+    $this->mock(TicketLedgerService::class)
+        ->shouldReceive('grantMonthly')
+        ->andReturnUsing(function (): void {
+            StripeWebhookEvent::query()->where('event_id', 'evt_overtaken_http')->update(['attempts' => 5]);
+        });
+
+    // 例外を投げない = Cashier が 200 を返す (行は既に別の世代が持っている)
+    event(new WebhookReceived(staleRecoveryInvoicePaidPayload('evt_overtaken_http')));
+
+    $record = StripeWebhookEvent::query()->where('event_id', 'evt_overtaken_http')->firstOrFail();
+    expect($record->status)->toBe(WebhookEventStatus::Received); // processed にならない
+    expect($record->attempts)->toBe(5);
+    expect($record->processed_at)->toBeNull();
+    // 回収経路の据え置きと違い recovery_pending にはしない
+    expect($record->recovery_reason)->toBeNull();
+    expect($organization->refresh()->plan_code)->toBeNull();
+});
+
+test('回収の件数は処置と一致する (replayed / movedToRecoveryPending / skipped)', function (): void {
+    [$organization] = staleRecoveryFixture();
+    Plan::query()->where('code', 'standard')->update(['monthly_ticket_grant' => 100]);
+
+    staleWebhookRecord('evt_count_replay', 'invoice.paid', staleRecoveryInvoicePaidPayload('evt_count_replay'));
+    staleWebhookRecord(
+        'evt_count_pending',
+        'customer.subscription.updated',
+        staleRecoverySubscriptionPayload('evt_count_pending'),
+    );
+    staleWebhookRecord('evt_count_fresh', 'invoice.paid', staleRecoveryInvoicePaidPayload('evt_count_fresh', 'in_fresh'), minutesAgo: 5);
+
+    $result = app(StripeWebhookProcessor::class)->recoverStale();
+
+    expect($result->replayed)->toBe(1);
+    expect($result->movedToRecoveryPending)->toBe(1);
+    expect($result->retryScheduled)->toBe(0);
+    expect($result->skipped)->toBe(0);
+    expect($organization->ticketLedgerEntries()->where('idempotency_key', 'monthly:in_stale_1')->count())->toBe(1);
+    assertRecoveryReasonInvariant();
+});
+
+test('cron コマンドが滞留を回収し 4 件数を出力する', function (): void {
+    [$organization] = staleRecoveryFixture();
+    Plan::query()->where('code', 'standard')->update(['monthly_ticket_grant' => 100]);
+    staleWebhookRecord('evt_cron', 'invoice.paid', staleRecoveryInvoicePaidPayload('evt_cron'));
+
+    $this->artisan('billing:recover-stale-webhook-events')
+        ->expectsOutputToContain('replayed 1 / retry-scheduled 0 / moved-to-recovery-pending 0 / skipped 0')
+        ->assertExitCode(0);
+
+    expect(app(TicketLedgerService::class)->balance($organization)->totalAvailable())->toBe(100);
+});
+
+test('滞留判定の閾値は整数で設定されている', function (): void {
+    expect(config()->integer('billing.webhook_stale_after_minutes'))->toBeGreaterThan(0);
+});
+
+test('recovery_reason は recovery_pending 以外の行に入れられない (DB CHECK)', function (): void {
+    if (! in_array(DB::connection()->getDriverName(), ['pgsql', 'mysql'], true)) {
+        $this->markTestSkipped('CHECK 制約は pgsql/mysql のみ (sqlite は ALTER ADD CONSTRAINT 非対応)');
+    }
+
+    StripeWebhookEvent::factory()->create(['event_id' => 'evt_check_1']);
+
+    expect(fn () => StripeWebhookEvent::query()
+        ->where('event_id', 'evt_check_1')
+        ->update(['recovery_reason' => WebhookRecoveryReason::OrderSensitive->value]))
+        ->toThrow(QueryException::class);
+});
+
+test('recovery_pending の行から recovery_reason を外せない (DB CHECK)', function (): void {
+    if (! in_array(DB::connection()->getDriverName(), ['pgsql', 'mysql'], true)) {
+        $this->markTestSkipped('CHECK 制約は pgsql/mysql のみ (sqlite は ALTER ADD CONSTRAINT 非対応)');
+    }
+
+    StripeWebhookEvent::factory()
+        ->recoveryPending(WebhookRecoveryReason::AttemptsExhausted)
+        ->create(['event_id' => 'evt_check_2']);
+
+    expect(fn () => StripeWebhookEvent::query()
+        ->where('event_id', 'evt_check_2')
+        ->update(['recovery_reason' => null]))
+        ->toThrow(QueryException::class);
+});
+
+test('保持期限を超えても回収待ち・滞留 received の行は purge が消さない', function (): void {
+    // 起算点 (processed_at) が NULL の行は「異常として計上するだけで消さない」契約
+    $expired = BillingRetention::threshold()->subSecond();
+    StripeWebhookEvent::factory()
+        ->recoveryPending(WebhookRecoveryReason::OrderSensitive)
+        ->create(['event_id' => 'evt_purge_pending', 'created_at' => $expired]);
+    StripeWebhookEvent::factory()
+        ->stale()
+        ->create(['event_id' => 'evt_purge_stale', 'created_at' => $expired]);
+
+    $this->artisan('billing:purge-retention-expired', ['--apply' => true])
+        ->expectsOutputToContain('stripe_webhook_event: expired=0 processed=0 fail_closed=2')
+        ->assertExitCode(0);
+
+    expect(StripeWebhookEvent::query()->where('event_id', 'evt_purge_pending')->exists())->toBeTrue();
+    expect(StripeWebhookEvent::query()->where('event_id', 'evt_purge_stale')->exists())->toBeTrue();
+});
+
+test('新しい状態と理由は表示名を持つ', function (): void {
+    expect(WebhookEventStatus::RecoveryPending->label())->toBe('回収待ち');
+    foreach (WebhookRecoveryReason::cases() as $reason) {
+        expect($reason->label())->not->toBe('');
+    }
+});
+
+test('migration の down() で CHECK 制約・index・列が落ち、再適用できる', function (): void {
+    $migration = require database_path(
+        'migrations/2026_08_15_000100_add_recovery_reason_to_stripe_webhook_events_table.php'
+    );
+    expect($migration)->toBeInstanceOf(Migration::class);
+
+    $migration->down();
+
+    expect(Schema::hasColumn('stripe_webhook_events', 'recovery_reason'))->toBeFalse();
+    expect(Schema::hasIndex('stripe_webhook_events', 'stripe_webhook_events_status_updated_at_index'))
+        ->toBeFalse();
+
+    // 再適用が通る = CHECK 制約 (同名) も確かに落ちている
+    $migration->up();
+
+    expect(Schema::hasColumn('stripe_webhook_events', 'recovery_reason'))->toBeTrue();
+    expect(Schema::hasIndex('stripe_webhook_events', 'stripe_webhook_events_status_updated_at_index'))
+        ->toBeTrue();
+});
diff --git a/tests/Feature/Billing/WebhookReplaySafetyTest.php b/tests/Feature/Billing/WebhookReplaySafetyTest.php
new file mode 100644
index 0000000..2698135
--- /dev/null
+++ b/tests/Feature/Billing/WebhookReplaySafetyTest.php
@@ -0,0 +1,164 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\Billing\HandledStripeWebhookEvent;
+use App\Enums\Billing\PlanPriceKind;
+use App\Enums\Billing\WebhookReplaySafety;
+use App\Models\Billing\Plan;
+use App\Models\Billing\TicketCheckoutSession;
+use App\Models\Organization;
+use App\Models\User;
+use App\Services\Billing\TicketLedgerService;
+use Laravel\Cashier\Events\WebhookReceived;
+use Webmozart\Assert\Assert;
+
+/*
+ * 保存済み payload を再実行してよいかの分類 (HandledStripeWebhookEvent::replaySafety)。
+ *
+ * 分類は滞留回収 (StripeWebhookProcessor::recoverStale) が自動再実行の可否に使う唯一の判断材料
+ * なので、網羅性と個々の値に加えて **SafeToReplay の前提** (付与が下位の冪等キーで冪等であること)
+ * も behavioral に固定する。
+ */
+
+/**
+ * stripe_id を持つ組織と owner を作る (回収分類テスト用)。
+ *
+ * @return array{Organization, User}
+ */
+function replaySafetyFixture(): array
+{
+    [$organization, $owner] = createOrganizationWithOwner();
+    $organization->stripe_id = 'cus_replay_safety_1';
+    $organization->save();
+
+    return [$organization, $owner];
+}
+
+/** standard プランの現行 base Price の Stripe Price ID。 */
+function replaySafetyBasePriceId(): string
+{
+    $price = Plan::query()->where('code', 'standard')->firstOrFail()
+        ->currentPrice(PlanPriceKind::Base);
+    Assert::notNull($price, 'standard プランの current base price が未 seed');
+
+    return $price->stripe_price_id;
+}
+
+/**
+ * @return array<string, mixed>
+ */
+function replaySafetyInvoicePaidPayload(string $eventId, string $invoiceId): array
+{
+    return [
+        'id' => $eventId,
+        'type' => 'invoice.paid',
+        'data' => [
+            'object' => [
+                'id' => $invoiceId,
+                'customer' => 'cus_replay_safety_1',
+                'billing_reason' => 'subscription_cycle',
+                'lines' => [
+                    'data' => [
+                        ['price' => ['id' => replaySafetyBasePriceId()]],
+                    ],
+                ],
+            ],
+        ],
+    ];
+}
+
+/**
+ * @return array<string, mixed>
+ */
+function replaySafetyTicketPurchasePayload(
+    string $eventId,
+    string $sessionId,
+    Organization $organization,
+): array {
+    return [
+        'id' => $eventId,
+        'type' => 'checkout.session.completed',
+        'data' => [
+            'object' => [
+                'id' => $sessionId,
+                'mode' => 'payment',
+                'customer' => 'cus_replay_safety_1',
+                'payment_status' => 'paid',
+                'payment_intent' => 'pi_replay_safety_1',
+                'amount_subtotal' => 30 * 80,
+                'currency' => 'jpy',
+                'metadata' => [
+                    'purpose' => 'ticket_purchase',
+                    'org_ref' => (string) $organization->id,
+                    'count' => '30',
+                ],
+            ],
+        ],
+    ];
+}
+
+test('全 case が replaySafety() を返す (case 追加時に網羅 match が落ちる)', function (): void {
+    foreach (HandledStripeWebhookEvent::cases() as $case) {
+        expect($case->replaySafety())->toBeInstanceOf(WebhookReplaySafety::class);
+    }
+
+    expect(HandledStripeWebhookEvent::cases())->not->toBeEmpty();
+});
+
+test('customer.subscription.* は順序に依存する (OrderSensitive)', function (): void {
+    expect(HandledStripeWebhookEvent::SubscriptionCreated->replaySafety())
+        ->toBe(WebhookReplaySafety::OrderSensitive);
+    expect(HandledStripeWebhookEvent::SubscriptionUpdated->replaySafety())
+        ->toBe(WebhookReplaySafety::OrderSensitive);
+    expect(HandledStripeWebhookEvent::SubscriptionDeleted->replaySafety())
+        ->toBe(WebhookReplaySafety::OrderSensitive);
+});
+
+test('付与・通知・返金の 4 種は再実行しても追加の被害を生まない (SafeToReplay)', function (): void {
+    expect(HandledStripeWebhookEvent::InvoicePaid->replaySafety())
+        ->toBe(WebhookReplaySafety::SafeToReplay);
+    expect(HandledStripeWebhookEvent::InvoicePaymentFailed->replaySafety())
+        ->toBe(WebhookReplaySafety::SafeToReplay);
+    expect(HandledStripeWebhookEvent::CheckoutSessionCompleted->replaySafety())
+        ->toBe(WebhookReplaySafety::SafeToReplay);
+    expect(HandledStripeWebhookEvent::ChargeRefunded->replaySafety())
+        ->toBe(WebhookReplaySafety::SafeToReplay);
+});
+
+test('SafeToReplay の前提: 同一 invoice を別 event_id で 2 回処理しても月次付与は 1 行だけ', function (): void {
+    [$organization] = replaySafetyFixture();
+    Plan::query()->where('code', 'standard')->update(['monthly_ticket_grant' => 100]);
+
+    event(new WebhookReceived(replaySafetyInvoicePaidPayload('evt_replay_a', 'in_replay_1')));
+    event(new WebhookReceived(replaySafetyInvoicePaidPayload('evt_replay_b', 'in_replay_1')));
+
+    expect($organization->ticketLedgerEntries()->where('idempotency_key', 'monthly:in_replay_1')->count())
+        ->toBe(1);
+    expect(app(TicketLedgerService::class)->balance($organization)->totalAvailable())->toBe(100);
+});
+
+test('SafeToReplay の前提: 同一 session を別 event_id で 2 回処理しても購入付与は 1 行だけ', function (): void {
+    [$organization, $owner] = replaySafetyFixture();
+
+    TicketCheckoutSession::factory()
+        ->forOrganization($organization)
+        ->initiatedBy($owner)
+        ->create([
+            'ticket_count' => 30,
+            'unit_amount' => 80,
+            'currency' => 'jpy',
+            'stripe_session_id' => 'cs_replay_1',
+        ]);
+
+    event(new WebhookReceived(
+        replaySafetyTicketPurchasePayload('evt_purchase_a', 'cs_replay_1', $organization),
+    ));
+    event(new WebhookReceived(
+        replaySafetyTicketPurchasePayload('evt_purchase_b', 'cs_replay_1', $organization),
+    ));
+
+    expect($organization->ticketLedgerEntries()->where('idempotency_key', 'purchase:cs_replay_1')->count())
+        ->toBe(1);
+    expect(app(TicketLedgerService::class)->balance($organization)->totalAvailable())->toBe(30);
+});
```

## テスト結果

- `composer test`: 4587 tests / 4585 passed / 0 failed / 2 skipped (skip は CHECK 制約テストの driver guard ではなく既存の skip)
- `composer phpstan` (level 10): No errors
- `vendor/bin/pint --test`: passed
- `pnpm lint` / `pnpm typecheck` / `pnpm test` (1450) / `pnpm build`: すべて green
- `pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages` (106): すべて green

### 設計から逸脱した点 (実装時に判明したもの)

1. `claim()` の新規 INSERT で `attempts = 0` を明示代入した。設計には無いが、
   DB カラム default に依存すると `save()` 直後の in-memory instance に `attempts` が
   入らず、`finalize()` へ渡す「受理した世代」が null になって TypeError で落ちる
   (実測。ドメイン規約 1(ii) / 2 の「初期状態は INSERT 時に明示代入する」と同じ理由)。
2. `reportRecoveryPending()` の `$claim->reason?->value ?? ''` は PHPStan の
   `nullsafe.neverNull` で落ちたため `$claim->reason->value ?? ''` にした
   (`??` の左辺なので null 安全は保たれる)。
3. 新規テストが `BillingRetention::threshold()` を呼ぶため、exact-fit 目録
   `BILLING_RETENTION_CALLERS` へ登録した (deny-by-default を迂回していない)。
