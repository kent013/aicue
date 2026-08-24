<?php

declare(strict_types=1);

namespace App\Console\Commands\EnterpriseSso;

use App\Models\EnterpriseSsoLoginAttempt;
use App\Services\EnterpriseSso\EnterpriseLoginAttemptStore;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Webmozart\Assert\Assert;

/**
 * 期限切れの企業 SSO ログイン試行を物理削除する。
 *
 * 掃除は二段である — **日次の本コマンド**と、callback での**オンアクセス掃除**
 * ({@see EnterpriseLoginAttemptStore::consume()})。
 * 即時削除ではないので「期限が切れた瞬間に行が消える」とは主張しない。
 *
 * ★1 回あたりの削除件数に上限を置く (長いトランザクションを作らない)。
 *   上限に達したら次回の実行が続きを消す (単調増加はしない)。
 */
class PruneLoginAttemptsCommand extends Command
{
    /** @var string */
    protected $signature = 'enterprise-sso:prune-login-attempts';

    /** @var string */
    protected $description = '期限切れの企業 SSO ログイン試行を物理削除する';

    public function handle(): int
    {
        $cutoff = CarbonImmutable::now();
        $chunk = Config::integer('enterprise-sso.login_attempt.prune_chunk');

        // ★**主キーを名指ししない**。期限だけを条件にして、pgsql の `ctid` 経由の
        //   限定つき DELETE (Laravel の Postgres grammar) で 1 回あたりの件数を抑える。
        //   id の一覧を先に引いて `whereIn('id', …)` する形にすると、
        //   テナントスコープ外の主キー同一性クエリになり分類が要る (AGENTS.md 不変条件 3)。
        $deleted = EnterpriseSsoLoginAttempt::query()
            ->where('expires_at', '<=', $cutoff)
            ->limit($chunk)
            ->delete();

        Assert::integer($deleted, 'delete() must return the affected row count.');

        if ($deleted === 0) {
            $this->info('期限切れの試行はありません');

            return self::SUCCESS;
        }

        $this->info("{$deleted} 件削除");

        return self::SUCCESS;
    }
}
