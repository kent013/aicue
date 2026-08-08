<?php

declare(strict_types=1);

namespace App\Console\Commands\Operations;

use App\Enums\Idempotency\IdempotencyState;
use App\Models\IdempotencyKey;
use App\Models\McpIdempotencyKey;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use RuntimeException;
use Webmozart\Assert\Assert;

/**
 * 保持期間を過ぎた冪等キーを物理削除する (REST / MCP の両テーブル)。
 *
 * lazy delete (claim 時の期限切れ行削除) だけでは「二度と再送されなかったキー」が
 * 残り続けて単調増加するため、日次で回収する。
 *
 * **集計の取り方**: 「先に COUNT して一括 DELETE」だと、その間の競合で
 * 「実際に削除した行」の集計にならない。state ごとに条件付き DELETE を発行し、
 * その affected rows を実績として使う。`cutoff` は開始時に 1 回だけ確定させ
 * 全 state / 両テーブルで共有する (ずれると集計の意味が壊れる)。
 *
 * **監視対象**: `processing` のまま期限切れになった行。これは
 * 「claim したのに確定できなかった要求」= プロセス強制終了か finalize 失敗の痕跡であり、
 * 1 件でもあれば report() する (AI-CUE の運用アラート経路は report() のみ)。
 */
class PruneIdempotencyKeysCommand extends Command
{
    /** @var string */
    protected $signature = 'idempotency:prune';

    /** @var string */
    protected $description = '保持期間を過ぎた冪等キー (REST / MCP) を物理削除する';

    public function handle(): int
    {
        // cutoff は開始時に 1 回だけ確定させ、全 state / 両テーブルで共有する
        $cutoff = CarbonImmutable::now();

        /** @var array<string, int> $deleted */
        $deleted = [];
        foreach (IdempotencyState::cases() as $state) {
            $deleted[$state->value] = self::deletedRows(
                IdempotencyKey::query()
                    ->where('state', $state->value)
                    ->where('expires_at', '<=', $cutoff)
                    ->delete(),
            );
        }

        // MCP テーブルは state 列を持たない (状態機械は据え置き) ため 1 本
        $deletedMcp = self::deletedRows(
            McpIdempotencyKey::query()
                ->where('expires_at', '<=', $cutoff)
                ->delete(),
        );

        foreach ($deleted as $state => $count) {
            $this->info("rest {$state}: {$count} 件削除");
        }
        $this->info("mcp: {$deletedMcp} 件削除");

        $stalled = $deleted[IdempotencyState::Processing->value];
        if ($stalled > 0) {
            // 確定できなかった claim が実在する。件数だけを報告する
            // (キー値・body は載せない)
            report(new RuntimeException(
                "確定できなかった冪等 claim を検出: processing のまま期限切れ count={$stalled}",
            ));
        }

        return self::SUCCESS;
    }

    /**
     * `Builder::delete()` の戻り値 (静的には mixed) を件数として narrow する。
     * 想定外の型は Assert で fail-fast させる (0 件へ黙って倒さない)。
     */
    private static function deletedRows(mixed $affected): int
    {
        Assert::integer($affected, 'delete() must return the affected row count.');

        return $affected;
    }
}
