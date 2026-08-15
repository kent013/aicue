<?php

declare(strict_types=1);

namespace App\Console\Commands\Billing;

use App\Exceptions\Billing\SubscriptionLookupFailedException;
use App\Models\Billing\Subscription;
use App\Models\Organization;
use App\Services\Billing\Contracts\StripeGatewayInterface;
use App\Services\Billing\SubscriptionService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Webmozart\Assert\Assert;

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

    protected $signature = 'billing:reconcile-subscription-status';

    protected $description = 'Stripe の契約状態とローカルの契約状態を突き合わせて収束させる (daily)';

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

    /** 走査本体 (ロックの内側)。 */
    private function reconcile(StripeGatewayInterface $gateway, SubscriptionService $subscriptions): int
    {
        // 走査状態は 1 実行に閉じたローカル値として持つ (同一プロセス内の再呼び出しで累積しない)。
        $tally = [
            'checked' => 0,
            'converged' => 0,
            'missing' => 0,
            'failed' => 0,
            'missingIds' => [],
            'failedIds' => [],
        ];
        $timedOut = false;
        $deadline = CarbonImmutable::now()->addSeconds(self::TIME_BUDGET_SECONDS);

        Subscription::query()
            ->where('type', 'default')
            // Stripe 側で終了は不可逆なので、ローカルが終了扱いの行は照会しない
            // (照会対象が単調増加しない)。**帰結**: 誤って終了と書かれた行は自動回復しない。
            ->whereNotIn('stripe_status', ['canceled', 'incomplete_expired'])
            ->with('organization')
            ->orderBy('id')
            ->chunkById(self::CHUNK_SIZE, function (Collection $subs) use (
                $gateway,
                $subscriptions,
                $deadline,
                &$tally,
                &$timedOut,
            ): bool {
                /** @var Collection<int, Subscription> $subs */
                foreach ($subs as $sub) {
                    // **1 件ごとに**残り時間を見る。chunk 開始時だけの検査では、1 chunk が
                    // 最大 100 回の外部呼び出しを含むため、遅い応答が続くと実行時間上限どころか
                    // ロックの有効期限まで跨ぎ、2 本目のプロセスが並走しうる。
                    if (CarbonImmutable::now()->greaterThan($deadline)) {
                        $timedOut = true;

                        return false; // chunk の途中でも即座に止める (残りは照会しない)
                    }

                    $this->reconcileOne($gateway, $subscriptions, $sub, $tally);
                }

                return true;
            });

        $this->info(sprintf(
            'reconcile-subscription-status: checked=%d converged=%d missing=%d failed=%d',
            $tally['checked'], $tally['converged'], $tally['missing'], $tally['failed'],
        ));

        // 1 実行につき 1 回だけ report する (件数 + organization id のみ = PII を載せない)。
        if ($tally['missing'] > 0 || $tally['failed'] > 0) {
            report(new RuntimeException(sprintf(
                'Stripe 契約の突き合わせ未完了: missing=%d ids=%s / failed=%d ids=%s',
                $tally['missing'],
                $this->formatIds($tally['missingIds']),
                $tally['failed'],
                $this->formatIds($tally['failedIds']),
            )));
        }

        return ($tally['failed'] > 0 || $timedOut) ? self::FAILURE : self::SUCCESS;
    }

    /**
     * 契約 1 件の突き合わせ。1 件失敗で走査を止めない (件数へ積んで次へ進む)。
     *
     * @param  array{checked: int, converged: int, missing: int, failed: int, missingIds: list<int>, failedIds: list<int>}  $tally
     */
    private function reconcileOne(
        StripeGatewayInterface $gateway,
        SubscriptionService $subscriptions,
        Subscription $sub,
        array &$tally,
    ): void {
        $tally['checked']++;

        try {
            $remote = $gateway->retrieveSubscriptionState($sub->stripe_id);
        } catch (SubscriptionLookupFailedException $e) {
            $tally['failed']++;
            $tally['failedIds'][] = $sub->organization_id;
            // 例外 message は載せない (外部生成の可変文字列)。クラス名だけ。
            // previous は無いことがある (id 欠落など gateway 自身が投げる場合) ため null 安全に落とす。
            $previous = $e->getPrevious();
            Log::warning('reconcile-subscription-status: lookup failed', [
                'organization_id' => $sub->organization_id,
                'error_class' => $previous !== null ? $previous::class : $e::class,
            ]);

            return;
        }

        if ($remote === null) {
            $tally['missing']++;
            $tally['missingIds'][] = $sub->organization_id;

            return; // 状態は変えない
        }

        if (! $subscriptions->needsSnapshotConvergence($sub, $remote->snapshot, $remote->hasPaymentMethod)) {
            return;
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
        $tally['converged']++;
    }

    /**
     * report 用の id 列 (上限を超えた分は件数だけ書く)。
     *
     * @param  list<int>  $ids
     */
    private function formatIds(array $ids): string
    {
        if ($ids === []) {
            return '-';
        }

        $shown = array_slice($ids, 0, self::REPORTED_ID_LIMIT);
        $rest = count($ids) - count($shown);

        return implode(',', $shown).($rest > 0 ? " (他 {$rest} 件)" : '');
    }
}
