<?php

declare(strict_types=1);

namespace App\Console\Commands\Billing;

use App\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Webmozart\Assert\Assert;

/**
 * 決済事業者側 customer の redaction (非表示化) の**実施記録**。
 *
 * ★**決済事業者 API を呼ばない**。redaction そのものは運用者がダッシュボードで行い、
 *   本コマンドは「いつ・どの customer に対して実施したか」を自 DB に記録するだけである
 *   (退会経路から決済事業者 API を呼ばない原則 = T115 / 標準形 v1 の必須 (1))。
 *   手順の正本は `docs/account-deletion-runbook.md`。
 *
 * ★**記録は 2 列セット**。`stripe_customer_redacted_at` (実施日時) と
 *   `stripe_customer_redacted_id` (記録時点の `stripe_id` の写し) を同時に書く。
 *   日時だけだと「**どの** customer を redact 済みと記録したか」が事後に検証できない。
 *   両列同時の不変条件は DB の CHECK 制約でも担保している (アプリ層を迂回しても守られる)。
 *
 * ★**1 回限り (冪等)**。行ロック下で既記録を再確認し、既に記録済みなら実施日を表示して
 *   no-op で成功する (**上書きしない** — 最初の実施日が監査証跡だから)。
 *
 * ★`stripe_id` を持たない組織には記録できない (fail-closed)。写す値が無いため。
 */
class MarkStripeCustomerRedactedCommand extends Command
{
    protected $signature = 'billing:mark-stripe-customer-redacted
        {organization : 組織 ID}
        {--apply : 実記録する (未指定は dry-run)}';

    protected $description = '決済事業者側 customer の redaction 実施を記録する (既定 dry-run。API は呼ばない)';

    public function handle(): int
    {
        $organizationId = $this->argument('organization');
        Assert::stringNotEmpty($organizationId, '組織 ID を指定してください');

        if (! ctype_digit($organizationId)) {
            $this->error("組織 ID は整数で指定してください: {$organizationId}");

            return self::FAILURE;
        }

        return DB::transaction(function () use ($organizationId): int {
            // 運用者が CLI で名指しした 1 組織を主キーで解決する (DirectFetchInventory 登録済み)。
            // 判定と書き込みの間に別プロセスが割り込まないよう行ロック下で再評価する。
            $organization = Organization::query()->whereKey($organizationId)->lockForUpdate()->first();
            if (! $organization instanceof Organization) {
                $this->error("組織が見つかりません: {$organizationId}");

                return self::FAILURE;
            }

            $recordedAt = $organization->stripe_customer_redacted_at;
            if ($recordedAt !== null) {
                $this->info(
                    $recordedAt->toDateString().' に記録済みです'
                    .' (customer='.($organization->stripe_customer_redacted_id ?? '不明').')。何もしません。',
                );

                return self::SUCCESS;
            }

            $customerId = $organization->stripe_id;
            if (! is_string($customerId) || $customerId === '') {
                $this->error(
                    "組織 {$organizationId} は決済事業者 customer を持ちません (stripe_id が空)。"
                    .'記録すべき対象が無いため何もしません。',
                );

                return self::FAILURE;
            }

            if ($this->option('apply') !== true) {
                $this->info(
                    "[dry-run] 組織 {$organizationId} の customer={$customerId} を"
                    .' redaction 実施済みとして記録します (--apply で実記録)。',
                );

                return self::SUCCESS;
            }

            $organization->forceFill([
                'stripe_customer_redacted_at' => CarbonImmutable::now(),
                'stripe_customer_redacted_id' => $customerId,
            ])->save();

            $this->info("組織 {$organizationId} の customer={$customerId} の redaction 実施を記録しました。");

            return self::SUCCESS;
        });
    }
}
