<?php

declare(strict_types=1);

namespace App\Console\Commands\Auth;

use App\Models\EmailPromotion;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Webmozart\Assert\Assert;

/**
 * 期限切れのメール昇格の確認待ちを物理削除する。
 *
 * ★`email_promotions` は利用者ごとに 1 行しか持てない (`email_promotions_user_unique`)。
 *   期限切れの行を消さないと、その利用者は**二度と昇格を始められない**
 *   (発行時に自分の古い行を消す経路はあるが、日次の掃除が最後の受け皿である)。
 */
class PruneEmailPromotionsCommand extends Command
{
    /** @var string */
    protected $signature = 'auth:prune-email-promotions';

    /** @var string */
    protected $description = '期限切れのメール昇格の確認待ちを物理削除する';

    public function handle(): int
    {
        $cutoff = CarbonImmutable::now();
        $chunk = Config::integer('enterprise-sso.login_attempt.prune_chunk');

        // ★**主キーを名指ししない**。期限だけを条件にして、pgsql の `ctid` 経由の
        //   限定つき DELETE (Laravel の Postgres grammar) で 1 回あたりの件数を抑える。
        //   id の一覧を先に引いて `whereIn('id', …)` する形にすると、
        //   テナントスコープ外の主キー同一性クエリになり分類が要る (AGENTS.md 不変条件 3)。
        $deleted = EmailPromotion::query()
            ->where('expires_at', '<=', $cutoff)
            ->limit($chunk)
            ->delete();

        Assert::integer($deleted, 'delete() must return the affected row count.');

        if ($deleted === 0) {
            $this->info('期限切れの昇格はありません');

            return self::SUCCESS;
        }

        $this->info("{$deleted} 件削除");

        return self::SUCCESS;
    }
}
