<?php

declare(strict_types=1);

use App\Contracts\Recovery\StuckWorkStream;
use App\Enums\Recovery\RecoveryOutcome;
use App\Enums\Recovery\RecoveryStream;
use App\Services\Recovery\StuckWorkRecoverySweeper;
use Carbon\CarbonImmutable;

/*
 * 掃引 (走査枠) の契約。作用は差し替え可能な系列に閉じてあるので、DB に触れずに
 * ページ送り・上限・例外の扱い・打ち切りの区別を直接固定できる。
 */

/** 主キーの列を持つだけの試験用の系列 */
function fakeRecoveryStream(
    array $ids,
    ?int $sweepItemLimit = null,
    ?Closure $onRecover = null,
    ?int $overReturn = null,
): StuckWorkStream {
    return new class($ids, $sweepItemLimit, $onRecover, $overReturn) implements StuckWorkStream
    {
        /** @var list<array{int, CarbonImmutable}> */
        public array $recovered = [];

        /** @var list<CarbonImmutable> */
        public array $sweptAtSeen = [];

        public function __construct(
            private array $ids,
            private ?int $sweepItemLimit,
            private ?Closure $onRecover,
            private ?int $overReturn,
        ) {}

        public function stream(): RecoveryStream
        {
            return RecoveryStream::AnalysisJob;
        }

        public function candidateIds(CarbonImmutable $sweptAt, ?int $afterId, int $pageSize): array
        {
            $this->sweptAtSeen[] = $sweptAt;
            $remaining = array_values(array_filter(
                $this->ids,
                static fn (int $id): bool => $afterId === null || $id > $afterId,
            ));

            // 契約違反 (要求より多く返す) を再現するための細工
            return array_slice($remaining, 0, $this->overReturn ?? $pageSize);
        }

        public function recover(int $id, CarbonImmutable $sweptAt): RecoveryOutcome
        {
            $this->recovered[] = [$id, $sweptAt];
            $this->sweptAtSeen[] = $sweptAt;

            if ($this->onRecover !== null) {
                return ($this->onRecover)($id);
            }

            return RecoveryOutcome::Recovered;
        }

        public function sweepItemLimit(): ?int
        {
            return $this->sweepItemLimit;
        }
    };
}

test('公平性: 先頭が毎回例外でも後続の全件が同じ掃引で回収される', function (): void {
    // ページサイズ (200) を超える候補を作り、先頭の 1 件だけが必ず例外を投げる
    $ids = range(1, 250);
    $stream = fakeRecoveryStream($ids, onRecover: function (int $id): RecoveryOutcome {
        if ($id === 1) {
            throw new RuntimeException('この行は毎回失敗する');
        }

        return RecoveryOutcome::Recovered;
    });

    $result = app(StuckWorkRecoverySweeper::class)->sweep($stream, apply: true);

    expect($result->candidates)->toBe(250);
    expect($result->failures)->toBe(1);
    expect($result->count(RecoveryOutcome::Recovered))->toBe(249);
    expect(array_map(static fn (array $call): int => $call[0], $stream->recovered))->toBe($ids);
});

test('実行しない指定は recover を 1 度も呼ばず候補件数だけを数える', function (): void {
    $stream = fakeRecoveryStream([1, 2, 3]);

    $result = app(StuckWorkRecoverySweeper::class)->sweep($stream, apply: false);

    expect($result->applied)->toBeFalse();
    expect($result->candidates)->toBe(3);
    expect($stream->recovered)->toBe([]);
    expect($result->outcomes)->toBe([]);
});

test('1 件の例外は掃引を止めず failures に数えられる', function (): void {
    $stream = fakeRecoveryStream([1, 2, 3], onRecover: function (int $id): RecoveryOutcome {
        if ($id === 2) {
            throw new RuntimeException('一時的な失敗');
        }

        return RecoveryOutcome::Recovered;
    });

    $result = app(StuckWorkRecoverySweeper::class)->sweep($stream, apply: true);

    expect($result->failures)->toBe(1);
    expect($result->count(RecoveryOutcome::Recovered))->toBe(2);
});

test('実効上限は min(--limit, 系列の申告)。両方無指定なら全件', function (): void {
    $sweeper = app(StuckWorkRecoverySweeper::class);

    expect($sweeper->sweep(fakeRecoveryStream(range(1, 10)), apply: true)->candidates)->toBe(10);
    expect($sweeper->sweep(fakeRecoveryStream(range(1, 10)), apply: true, limitOverride: 4)->candidates)->toBe(4);
    expect($sweeper->sweep(fakeRecoveryStream(range(1, 10), sweepItemLimit: 3), apply: true)->candidates)->toBe(3);
    expect($sweeper->sweep(fakeRecoveryStream(range(1, 10), sweepItemLimit: 3), apply: true, limitOverride: 7)->candidates)->toBe(3);
    expect($sweeper->sweep(fakeRecoveryStream(range(1, 10), sweepItemLimit: 8), apply: true, limitOverride: 2)->candidates)->toBe(2);
});

test('打ち切りの区別: 候補がちょうど上限件数なら limitReached は false', function (): void {
    $sweeper = app(StuckWorkRecoverySweeper::class);

    $exact = $sweeper->sweep(fakeRecoveryStream(range(1, 5)), apply: true, limitOverride: 5);
    expect($exact->candidates)->toBe(5);
    expect($exact->limitReached)->toBeFalse();

    $overflow = $sweeper->sweep(fakeRecoveryStream(range(1, 6)), apply: true, limitOverride: 5);
    expect($overflow->candidates)->toBe(5);
    expect($overflow->limitReached)->toBeTrue();
});

test('候補列挙と回収に渡る掃引開始時刻が同一である', function (): void {
    $stream = fakeRecoveryStream([1, 2]);

    app(StuckWorkRecoverySweeper::class)->sweep($stream, apply: true);

    expect($stream->sweptAtSeen)->not->toBe([]);
    $first = $stream->sweptAtSeen[0];
    foreach ($stream->sweptAtSeen as $seen) {
        expect($seen->equalTo($first))->toBeTrue();
    }
});

test('契約違反 (要求より多く返す系列) でも実効上限を超えない', function (): void {
    // 要求は pageSize だが、系列が 10 件返してくる細工
    $stream = fakeRecoveryStream(range(1, 10), overReturn: 10);

    $result = app(StuckWorkRecoverySweeper::class)->sweep($stream, apply: true, limitOverride: 3);

    // これは黙って切るだけの防御であり、契約そのものの固定は各系列のテストが担う
    expect($result->candidates)->toBe(3);
    expect($stream->recovered)->toHaveCount(3);
});
