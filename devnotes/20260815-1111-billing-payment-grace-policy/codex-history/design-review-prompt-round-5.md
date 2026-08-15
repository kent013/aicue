# Round 5: 指摘への対応と再レビュー依頼

Round 4 の [Warning] 1 件 (施策 10 のみ) を修正した (反論なし)。提案どおり
「再試行 0 回を前提として固定する」形に単純化し、保証範囲も Stripe 照会の待機に限定した。

対応マトリクスと施策 10 の定数・テスト計画の該当箇所を再掲する。
これが詳細設計フェーズの最終ラウンドの想定である。全体判定を明示すること。

---

## 対応マトリクス

# 対応マトリクス: design-review Round 4

## [Warning] 施策 10: 安全余白の式が再試行時のバックオフ待機を含まない
- 判断: 対応する (提案どおり「再試行 0 回を前提として固定する」形を採る)
- 根拠: 指摘のとおり、`× (1 + 再試行回数)` の式は SDK 側のバックオフ待機を表せず、
  再試行を 1 回に増やしても式が通ってしまうため「緩めたら赤くなる」という説明と一致しない。
  現行要件では再試行は 0 回に pin されており、将来の再試行モデルまで一般化する必要はない。
- 対応内容: 式を
  `TIME_BUDGET_SECONDS + STRIPE_CONNECT_TIMEOUT_SECONDS + STRIPE_TIMEOUT_SECONDS < LOCK_SECONDS`
  (現行値 600 + 5 + 20 = 625 < 900) に単純化し、**前提として
  `STRIPE_MAX_NETWORK_RETRIES === 0` をテストで固定**した (再試行を許した時点で赤くなり、
  バックオフ待機を含む式へ契約を変更する必要があることが分かる)。
  テストから読むため 2 定数は `public const` にする。
- 併せて保証範囲の限定も明記した: ここで抑えるのは **Stripe 照会による待機**であって、
  DB のロック待ち等を含む絶対的な TTL 保証ではない。

---

## 修正後の詳細設計 (施策 10 の全文)

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

        (待ち上限・再試行回数・実行時間上限のいずれを緩めても赤くなる。
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
