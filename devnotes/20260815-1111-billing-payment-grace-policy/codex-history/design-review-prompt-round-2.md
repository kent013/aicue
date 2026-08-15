# Round 2: 指摘への対応と再レビュー依頼

Round 1 の [Critical] 2 件・[Warning] 3 件・[Suggestion] 2 件はすべて設計側を修正した
(反論なし)。特に施策 9 は「規則を揃える」ではなく **写像そのものを 1 クラスへ統合する**
形に変えている (webhook と gateway が同じ mapper を呼ぶ)。

対応マトリクスと、修正後の詳細設計書の**変更した施策のみ**を再掲する。
再レビューでは、修正が指摘を満たしているか、新たな不整合 (特に mapper 抽出による
webhook 側の挙動変化、差分判定の拡張による過剰更新・見落とし) が無いかを見てほしい。
各施策の判定と全体判定を明示すること。

---

## 対応マトリクス

# 対応マトリクス: design-review Round 1

## [Critical] 施策 11: 正規表現が施策 1 の cast (`'past_due_since' => 'datetime'`) を誤検出する
- 判断: 対応する (指摘どおり、そのままでは 1 PR 内で必ず赤くなる)
- 根拠: `Subscription::casts()` に足す型宣言は「書き込み」ではないが、汎用の array key 検出は
  区別できない。model をまるごと allowlist に入れると model 内の将来の直書きを見逃す。
- 対応内容: 検査を 2 段にした。ファイル走査で候補を拾い、allowlist 外のファイルは
  **行ごとに判定**して「docblock 行」と「`'past_due_since' => 'datetime',` に完全一致する
  cast 行」だけを許す。これにより model の cast は通り、model 内の
  `forceFill(['past_due_since' => …])` は落ちる。負のコントロールを 2 本
  (単一 writer が検出されること / cast 以外の array key 代入が違反として拾われること) に増やした。

## [Critical] 施策 10: `$e->getPrevious()::class` が previous 無しで fatal になる
- 判断: 対応する
- 根拠: 指摘のとおり。gateway 自身が投げるケース (id 欠落) では previous が無い。
- 対応内容: `$previous = $e->getPrevious();` を取り、
  `$previous !== null ? $previous::class : $e::class` に直した (例外 message は引き続き載せない)。

## [Warning] 施策 10: `needsSnapshotConvergence()` が status / 起点 / PM しか見ていない
- 判断: 対応する
- 根拠: status が同じまま `current_period_end` だけが動いた webhook を落とすと、更新予告
  (`billing:send-billing-reminders`) の真実源がずれたまま永久に収束しない。
- 対応内容: 比較対象を `applySubscriptionSnapshot` が書く列すべて
  (`stripe_status` / `stripe_price` / `quantity` / `trial_ends_at` / `ends_at` /
  `current_period_end`) + 猶予起点 + PM に拡張した。`current_period_end` は
  **snapshot 側が null のときは比較しない** (「period 欠落 payload では既存値を維持する」
  という書込規則と同じ扱い)。日時比較は null 安全な `timesDiffer()` に切り出し、秒精度で見る。
  `organizations.plan_code` は同一トランザクションで同期されるため比較対象にしない旨も明記した
  (未知 Price のときだけ据え置かれることは docs の「保証しないもの」へ)。
  テスト計画に「status 以外の差分も収束する」「period 欠落は既存値を維持」を追加した。

## [Warning] 施策 9: snapshot の 7 フィールド抽出が「webhook と同じ規則」としか書かれていない
- 判断: 対応する (規則を書くだけでなく、**写像そのものを 1 か所に統合**する)
- 根拠: 規則を 2 か所に書き写す形では、指摘どおり突き合わせ経路だけ別挙動になる余地が残る。
- 対応内容: `app/Services/Billing/SubscriptionSnapshotMapper.php` を新設し、
  **Stripe の subscription オブジェクト (連想配列) → `SubscriptionSnapshot`** の写像を 1 本化した。
  webhook は `data.object` の配列を、gateway は SDK オブジェクトの `toArray()` を渡す
  (SDK 型は gateway の中に閉じたまま)。各フィールドの exact mapping を表で明記し、
  PM 観測も mapper の三値メソッドに寄せた (webhook 側は `$observed === true` を渡す = 現行と同値)。
  テストは mapper 単体 (7 フィールド + 三値 + 新旧 API 両系 + 優先順位) と、
  **同一配列から webhook 経路と gateway 経路で同一 snapshot になること**、および既存 webhook
  テストが緑のままであること (挙動を変えていない回帰) を必須にした。

## [Warning] 施策 6: `EntitlementDeniedReason` の露出有無が文書内で矛盾
- 判断: 対応する (実読で確認し、非露出を機械固定する)
- 根拠: 実読の結果、`EntitlementDeniedReason` / `SubscriptionEntitlementDto` は `app/Http/` にも
  `resources/js/` にも 1 件も無い (露出していない)。矛盾しているのは現行 enum の docblock の
  「フロントは reason 別に状態説明を出し分ける」という記述のほうだった。
- 対応内容: enum の docblock を実装に合わせて直す (現時点では props に出さない / 出すときは
  TypeScript の union と表示テストを同時に足す) ことを波及変更に明記し、
  新規 `tests/Architecture/EntitlementReasonExposureTest.php` で非露出を固定する
  (負のコントロール付き)。

## [Suggestion] 施策 1: migration の import 漏れ / 施策 5: docs への順序保証の明記
- 判断: どちらも対応する
- 対応内容: 施策 1 のリスクに import 漏れの注意を追記。施策 5 の docs 反映 (最終収束であり
  即時の順序保証ではない) は施策 12 の記載項目に既に含まれている。

---

## 修正後の詳細設計 (施策 6 / 9 / 10 / 11 と施策 1 のリスク欄)

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
- backfill migration では `use Carbon\CarbonImmutable;` / `use Illuminate\Support\Facades\DB;` の
  import 漏れに注意する (migration は無名クラスで namespace を持たないため、
  import が無いと global 解決で落ちる)。

---

## 施策 2: 猶予日数の設定を置く

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
- [ ] 新規 `tests/Architecture/EntitlementReasonExposureTest.php` (**非露出の固定**):
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

    // 書き込みパターン: array key 代入 と プロパティ代入 (=== / !== 比較は除外)。
    $finder = Finder::create()
        ->in(base_path('app'))
        ->files()
        ->name('*.php')
        ->contains('/([\'"])past_due_since\1\s*=>|->past_due_since\s*=[^=]/');

    $violations = [];
    foreach ($finder as $file) {
        $relative = str_replace(base_path().'/', '', (string) $file->getRealPath());
        if (in_array($relative, $allowlist, true)) {
            continue;
        }
        // **型宣言 (casts) は書き込みではない**: Subscription model の
        // `'past_due_since' => 'datetime',` だけは許す。それ以外の array key 代入は違反。
        foreach (castOnlyViolations($file->getRealPath()) as $line) {
            $violations[] = $relative.':'.$line;
        }
    }

    expect($violations)->toBe([], 'past_due_since の書き込みは SubscriptionService 経由に限定してください: '.implode(', ', $violations));
});
```

`castOnlyViolations()` は当該ファイルの `past_due_since` を含む行を 1 行ずつ見て、
**次のいずれにも当てはまらない行**を違反として返す:

- `@property` 等の docblock 行 (行頭が `*`)
- cast 宣言そのもの (`'past_due_since' => 'datetime',` に完全一致。値が `'datetime'` 以外なら違反)

これにより「model の `casts()` は通るが、model 内の `forceFill(['past_due_since' => …])` は
落ちる」ようにする (Codex Round 1 の [Critical]: 汎用 array key 検出だと施策 1 の cast を
誤検出して常に赤くなる / model をまるごと allowlist に入れると将来の直書きを見逃す)。

負のコントロール (走査が空振りしていないこと) を 2 本置く:

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
```

### テスト計画

- 本施策自体がテストである。**負のコントロールを 2 本**必ず置く
  (正規表現や判定関数が空振りしていると検査が常に緑になるため。既存の
  `ExternalClientTimeoutInventoryTest` が同じ考え方を持つ)

### リスク

- ファイル粒度の検査であり、`SubscriptionService` 内でメソッドが増えても検出しない
  (メソッド単位の fail-first は施策 5 の behavioral テストが担う)。

---

## 施策 12: ドキュメント
