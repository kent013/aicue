<?php

declare(strict_types=1);

namespace App\Console\Commands\Billing;

use App\DataTransferObjects\Billing\BillingRetentionPurgeResultDto;
use App\Enums\Billing\BillingRetentionTarget;
use App\Services\Billing\Contracts\BillingRetentionPurger;
use App\Services\Billing\Retention\BillingRetentionPurgerRegistry;
use App\Support\Legal\BillingRetention;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * 保持期限を超えた課金記録の**集計 (dry-run 専用)**。
 *
 * ★**`--apply` を持たない**。これは「規約が宣言していない削除を先に運用へ効かせない」の
 *   機械化である。/privacy には保持年数の宣言がまだ無く (PR-C3 の担当)、宣言より先に
 *   実削除を回すと、利用者が読める根拠のないままデータが消える。実処理の有効化は
 *   **PR-C2 で `--apply` を追加してから**行う (signature そのものが工程の順序を表している)。
 *
 * ★出力は **target 別の件数のみ**。organization id / メールアドレス / 金額を出さない
 *   (運用ログとチケットに課金の個別情報を写さない)。
 *
 * ★`ticket_ledger_entry` は C1 時点で purger 未実装 (append-only の畳み込みは PR-C2)。
 *   「対象だが未了」であることを出力に必ず出す — 黙って集計から外すと、対象を網羅したように
 *   見える出力になる。
 */
final class PurgeBillingRetentionCommand extends Command
{
    protected $signature = 'billing:purge-retention-expired
        {--target= : 対象を 1 つに絞る (BillingRetentionTarget の value)}';

    protected $description = '保持期限を超えた課金記録を target 別に集計する (dry-run 専用。実削除はしない)';

    public function handle(BillingRetentionPurgerRegistry $registry): int
    {
        $filter = $this->option('target');
        if ($filter !== null && BillingRetentionTarget::tryFrom($filter) === null) {
            $this->error("未知の target です: {$filter}");
            $this->line('指定できる値: '.implode(', ', array_map(
                static fn (BillingRetentionTarget $case): string => $case->value,
                BillingRetentionTarget::cases(),
            )));

            return self::FAILURE;
        }

        // 閾値は 1 回だけ解決して全 target へ渡す (実行中に日付が変わっても判定を揃える)。
        $threshold = BillingRetention::threshold();
        $this->info(sprintf(
            '[dry-run] 保持期間 %d 年 / 閾値 %s 以前の起算日時が期限超過 (実削除はしない)',
            BillingRetention::years(),
            $threshold->toDateTimeString(),
        ));

        $results = [];
        foreach ($registry->purgers() as $purger) {
            if ($filter !== null && $purger->target()->value !== $filter) {
                continue;
            }
            $results[] = $this->inspect($purger, $threshold);
        }

        foreach ($results as $result) {
            $this->line(sprintf(
                '  %s: expired=%d fail_closed=%d unexpected_failures=%d',
                $result->target->value,
                $result->candidates,
                $result->failClosed,
                $result->unexpectedFailures,
            ));
        }

        $this->reportPendingTargets($filter);

        $expired = array_sum(array_map(
            static fn (BillingRetentionPurgeResultDto $result): int => $result->expiredRemaining,
            $results,
        ));
        $failClosed = array_sum(array_map(
            static fn (BillingRetentionPurgeResultDto $result): int => $result->failClosed,
            $results,
        ));
        $this->info("合計: 期限超過 {$expired} 件 / fail-closed {$failClosed} 件");

        $failed = array_filter(
            $results,
            static fn (BillingRetentionPurgeResultDto $result): bool => $result->hasUnexpectedFailures(),
        );
        if ($failed !== []) {
            $this->error('集計に失敗した target があります (件数は不明として 0 で表示しています)。');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /** 1 target を集計する (実削除は行わない)。 */
    private function inspect(BillingRetentionPurger $purger, CarbonImmutable $threshold): BillingRetentionPurgeResultDto
    {
        try {
            return BillingRetentionPurgeResultDto::dryRun(
                target: $purger->target(),
                candidates: $purger->countExpired($threshold),
                failClosed: $purger->countFailClosed($threshold),
            );
        } catch (Throwable $e) {
            // 例外 message は載せない (外部生成の可変文字列)。target と例外クラスだけ。
            $this->warn(sprintf('集計失敗 target=%s (%s)', $purger->target()->value, $e::class));

            // 数えられなかったので件数は不明。0 と報告するが、unexpectedFailures が
            // 「この 0 は信用できない」ことを示す (終了コードもここから決まる)。
            return new BillingRetentionPurgeResultDto(
                target: $purger->target(),
                candidates: 0,
                processed: 0,
                failClosed: 0,
                unexpectedFailures: 1,
                expiredRemaining: 0,
            );
        }
    }

    /** purger 未実装 (C2 待ち) の target を明示する。 */
    private function reportPendingTargets(?string $filter): void
    {
        foreach (BillingRetentionTarget::cases() as $case) {
            if (! $case->isPendingCarryForward()) {
                continue;
            }
            if ($filter !== null && $case->value !== $filter) {
                continue;
            }
            $this->warn("  {$case->value}: purger 未実装 (append-only の畳み込みは PR-C2)");
        }
    }
}
