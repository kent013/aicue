<?php

declare(strict_types=1);

namespace App\Console\Commands\Operations;

use App\Contracts\Recovery\StuckWorkStream;
use App\DataTransferObjects\Recovery\StreamSweepResultDto;
use App\Enums\Recovery\RecoveryOutcome;
use App\Enums\Recovery\RecoveryStream;
use App\Services\Recovery\StuckWorkRecoverySweeper;
use App\Services\Recovery\StuckWorkStreamRegistry;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Webmozart\Assert\Assert;

/**
 * 滞留した処理・予約を回収する唯一の入口 (AG-083 標準形 v1)。
 *
 * --stream 省略時は全系列を掃引する。--apply が無ければ実行せず候補を数えるだけ。
 * --limit は**手動実行の試し打ち用**の総件数上限で、付けると先頭側しか見ない。
 */
class RecoverStuckWorkCommand extends Command
{
    /** @var string */
    protected $signature = 'work:recover-stuck
        {--stream= : 対象の系列 (省略時は全系列)}
        {--limit= : 1 系列あたりの処理件数上限 (手動実行用。既定は無制限)}
        {--apply : 実際に回収する (既定は数えるだけ)}';

    /** @var string */
    protected $description = '滞留した処理・予約を回収する (既定は数えるだけ。回収するには --apply)';

    public function handle(StuckWorkStreamRegistry $registry, StuckWorkRecoverySweeper $sweeper): int
    {
        // 引数の解釈は例外で失敗を表す。`?int` を返す形にすると「未指定」と「不正値」が
        // 同じ null になり、不正値が無制限の実行へ落ちる
        try {
            $streams = $this->resolveStreams($registry);
            $limit = $this->resolveLimit();
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');

        $failures = 0;
        foreach ($streams as $stream) {
            $result = $sweeper->sweep($stream, $apply, $limit);
            $failures += $result->failures;
            $this->line($this->format($result));
        }

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * --stream の解決 (未指定は全系列)。
     *
     * @return list<StuckWorkStream>
     */
    private function resolveStreams(StuckWorkStreamRegistry $registry): array
    {
        $option = $this->option('stream');
        if ($option === null || $option === '') {
            return $registry->all();
        }

        $stream = RecoveryStream::tryFrom($option);
        if ($stream === null) {
            throw new InvalidArgumentException('--stream の値が不正です: '.$option.'。'.self::validStreamsHint());
        }

        return [$registry->get($stream)];
    }

    /**
     * --limit の解決。**未指定のときだけ null** を返し、不正値は例外にする
     * (誤操作が「無制限で走る」に落ちないようにする)。
     *
     * @return positive-int|null
     */
    private function resolveLimit(): ?int
    {
        $option = $this->option('limit');
        if ($option === null) {
            return null;
        }
        if (preg_match('/^[1-9][0-9]*$/', $option) !== 1) {
            throw new InvalidArgumentException('--limit には 1 以上の整数を指定してください (指定値: '.$option.')');
        }

        $limit = (int) $option;
        Assert::positiveInteger($limit); // 上の照合が 1 以上を保証する。型としても正に閉じる

        return $limit;
    }

    /** 1 行 1 系列。数えるだけのときは候補が「実際に回収される件数の上界」であることを明示する */
    private function format(StreamSweepResultDto $result): string
    {
        return sprintf(
            '%s: mode=%s candidates=%d recovered=%d cleanup-failed=%d skipped=%d deferred=%d escalated=%d errors=%d limit-reached=%s',
            $result->stream->value,
            $result->applied ? 'apply' : 'dry-run (candidates は回収件数の上界)',
            $result->candidates,
            $result->count(RecoveryOutcome::Recovered),
            $result->count(RecoveryOutcome::RecoveredWithCleanupFailure),
            $result->count(RecoveryOutcome::Skipped),
            $result->count(RecoveryOutcome::Deferred),
            $result->count(RecoveryOutcome::Escalated),
            $result->failures,
            $result->limitReached ? 'yes' : 'no',
        );
    }

    private static function validStreamsHint(): string
    {
        return '有効な値: '.implode(' / ', array_map(
            static fn (RecoveryStream $stream): string => $stream->value,
            RecoveryStream::cases(),
        ));
    }
}
