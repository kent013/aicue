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
 * 保持期限を超えた課金記録の決着 (**既定は dry-run。実処理は `--apply`**)。
 *
 * ★決着の方式は target で違う — 削除で決着する 6 target と、**畳み込み**で決着する
 *   `ticket_ledger_entry` (台帳は残高の真実源なので消すと残高が変わる) がある。
 *   どちらも「保持期限を超えた個別取引の情報が残らない」という同じ結果に着地する。
 *
 * ★出力は **target 別の件数のみ**。organization id / メールアドレス / 金額を出さない
 *   (運用ログとチケットに課金の個別情報を写さない)。
 *
 * ★**horizon (期限超過が残っているか) を出力で観測できる**こと。規約文面の公開 (PR-C3) の
 *   前提条件は「分類を問わず期限超過 0 件」であり、その確認はこのコマンドの出力で行う。
 *   horizon は **OK / NG / 判定不能** の 3 値である。想定外失敗があった target の件数は
 *   「数えられなかった」ので 0 で報告されるため、その 0 を根拠に OK と言ってはならない。
 *
 * ★終了コードは 2 分類 — 想定外失敗があれば FAILURE。**`failClosed` が残っていても
 *   SUCCESS である** (安全に残した = 異常ではない)。ただしそれは「規約を満たした」ではない
 *   ので、件数を必ず出力する (docs/billing-retention-runbook.md)。
 */
final class PurgeBillingRetentionCommand extends Command
{
    protected $signature = 'billing:purge-retention-expired
        {--target= : 対象を 1 つに絞る (BillingRetentionTarget の value)}
        {--apply : 実際に決着させる (既定は dry-run で 1 行も変更しない)}';

    protected $description = '保持期限を超えた課金記録を決着させる (既定は dry-run。--apply で実処理)';

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

        $apply = $this->option('apply') === true;

        // 閾値は 1 回だけ解決して全 target へ渡す (実行中に日付が変わっても判定を揃える)。
        $threshold = BillingRetention::threshold();
        $this->info(sprintf(
            '%s 保持期間 %d 年 / 閾値 %s 以前の起算日時が期限超過',
            $apply ? '[apply]' : '[dry-run]',
            BillingRetention::years(),
            $threshold->toDateTimeString(),
        ));

        $results = [];
        foreach ($registry->purgers() as $purger) {
            if ($filter !== null && $purger->target()->value !== $filter) {
                continue;
            }
            $results[] = $apply
                ? $this->settle($purger, $threshold)
                : $this->inspect($purger, $threshold);
        }

        foreach ($results as $result) {
            $this->line(sprintf(
                '  %s: expired=%d processed=%d fail_closed=%d unexpected_failures=%d remaining=%d',
                $result->target->value,
                $result->candidates,
                $result->processed,
                $result->failClosed,
                $result->unexpectedFailures,
                $result->expiredRemaining,
            ));
        }

        return $this->report($results, $apply);
    }

    /**
     * 合計の報告と終了コードの決定。
     *
     * `remaining` は **PR-C3 (規約文面の公開) の前提条件そのもの**なので、
     * 0 かどうかを人が読める形で必ず出す。
     *
     * @param  list<BillingRetentionPurgeResultDto>  $results
     */
    private function report(array $results, bool $apply): int
    {
        $remaining = array_sum(array_map(
            static fn (BillingRetentionPurgeResultDto $result): int => $result->expiredRemaining,
            $results,
        ));
        $failClosed = array_sum(array_map(
            static fn (BillingRetentionPurgeResultDto $result): int => $result->failClosed,
            $results,
        ));
        $processed = array_sum(array_map(
            static fn (BillingRetentionPurgeResultDto $result): int => $result->processed,
            $results,
        ));

        $this->info(sprintf(
            '合計: 決着 %d 件 / 残存 (期限超過) %d 件 / fail-closed %d 件',
            $processed,
            $remaining,
            $failClosed,
        ));

        $failed = array_filter(
            $results,
            static fn (BillingRetentionPurgeResultDto $result): bool => $result->hasUnexpectedFailures(),
        );

        // horizon の観測点。C3 の前提条件は「分類を問わず 0 件」である。
        //
        // ★失敗した target が 1 つでもあれば **OK とも NG とも言えない**。失敗した target の
        //   件数は「数えられなかった」ので 0 で報告されており、その 0 を合計に足した
        //   `$remaining === 0` は「期限超過が無い」ことを意味しない (fail-open)。
        //   終了コードは FAILURE になるが、この行を読む人間 (C3 の唯一の歯止め) には
        //   「確認できていない」ことが伝わらなければならない。
        if ($failed !== []) {
            $this->error(sprintf(
                'horizon: 判定不能 (処理または集計に失敗した target が %d 件。観測できた期限超過は %d 件だが、実数は不明)',
                count($failed),
                $remaining,
            ));
        } else {
            $this->line($remaining === 0
                ? 'horizon: OK (期限超過 0 件)'
                : "horizon: NG (期限超過 {$remaining} 件が残存。fail-closed も残存に数える)");
        }

        if (! $apply) {
            $this->comment('dry-run のため 1 行も変更していません (--apply で実処理)。');
        }

        if ($failed !== []) {
            $this->error('想定外の失敗がある target があります (件数は不明として扱ってください)。');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /** 1 target を集計する (実処理は行わない)。 */
    private function inspect(BillingRetentionPurger $purger, CarbonImmutable $threshold): BillingRetentionPurgeResultDto
    {
        try {
            return BillingRetentionPurgeResultDto::dryRun(
                target: $purger->target(),
                candidates: $purger->countExpired($threshold),
                failClosed: $purger->countFailClosed($threshold),
            );
        } catch (Throwable $e) {
            return $this->unknown($purger, $e);
        }
    }

    /** 1 target を実際に決着させる。 */
    private function settle(BillingRetentionPurger $purger, CarbonImmutable $threshold): BillingRetentionPurgeResultDto
    {
        try {
            return $purger->purgeExpired($threshold);
        } catch (Throwable $e) {
            return $this->unknown($purger, $e);
        }
    }

    /** 集計・決着に失敗した target の結果 (件数は不明として 0 で報告する)。 */
    private function unknown(BillingRetentionPurger $purger, Throwable $e): BillingRetentionPurgeResultDto
    {
        // 例外 message は載せない (外部生成の可変文字列)。target と例外クラスだけ。
        $this->warn(sprintf('失敗 target=%s (%s)', $purger->target()->value, $e::class));

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
