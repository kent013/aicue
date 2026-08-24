<?php

declare(strict_types=1);

use Tests\Support\RawEnv\RawEnvChannels;
use Tests\Support\RawEnv\RawEnvGuardStructure;
use Tests\Support\RawEnv\RawEnvSnapshot;

/*
 * `Tests\Support\RawEnv\RawEnvGuardStructure` の自己検査 (走査器の検出力の裏取り)。
 *
 * ★AGENTS.md「静的検査 (gate) と走査器の共通規約」(c) に従い、**正例と負例の両方向**を固定する。
 *   正例 (規定どおりの構造を通す) だけでは「常に真を返す判定」を検出できず、
 *   負例 (退行した構造を落とす) だけでは「常に偽を返す判定」を検出できない。
 * ★入力は**ナウドキュメント (`<<<'PHP'`) の合成ソース**である。fixture ファイルを置くと
 *   `RawEnvDirectWriteGateTest` の母集団に入ってしまうため置かない。
 *   (この判断が効くのは走査器の自己検査 `RawEnvDirectWriteScannerTest` の側だが、
 *    同じ理由でこちらも合成入力に揃える。)
 * ★**解決できない形は落とす** ((b))。fail-closed 群がその分岐を固定する。
 * ★**母集団が空でも例外にしない**のは、本走査器が「入力を受け取って候補を返す再利用可能な
 *   検出器」であり母集団の非空を契約としないためである。非空を要求するのは**使う側**
 *   (`RawEnvSnapshotContractTest` の h 群) である。
 */

/** 閉包の口と同形の合成入力 (正例 1)。 */
const RAW_ENV_STRUCTURE_WITH_SHAPE = <<<'PHP'
public static function with(array $changes, Closure $body): mixed
{
    self::assertChangesAllowed($changes);
    $keys = array_keys($changes);
    $snapshot = self::capture($keys);
    $bodyError = null;

    try {
        foreach ($changes as $key => $channels) {
            self::apply((string) $key, $channels);
        }

        return $body();
    } catch (Throwable $e) {
        $bodyError = $e;

        throw $e;
    } finally {
        $snapshot->restore($bodyError);
    }
}
PHP;

/** 持ち回りの口と同形の合成入力 (正例 2)。 */
const RAW_ENV_STRUCTURE_CAPTURE_SHAPE = <<<'PHP'
public static function captureAndClear(array $keys): self
{
    self::assertKeysAllowed($keys);
    $snapshot = self::capture($keys);

    try {
        foreach ($keys as $key) {
            self::apply($key, RawEnvChannels::none());
        }
    } catch (Throwable $e) {
        $snapshot->restore($e);

        throw $e;
    }

    return $snapshot;
}
PHP;

/** 復元と同形の合成入力 (正例 3)。 */
const RAW_ENV_STRUCTURE_RESTORE_SHAPE = <<<'PHP'
public function restore(?Throwable $previous = null): void
{
    $failed = [];

    foreach ($this->state as $saved) {
        $applied = putenv($saved['key']);

        if ($applied === false) {
            $failed[] = $saved['key'];
        }
    }

    if ($failed !== []) {
        throw new RuntimeException('boom', 0, $previous);
    }
}
PHP;

// ── 正例 ──

test('正例 1: 閉包の口と同形の構造をすべて期待どおりと判定する', function (): void {
    $tokens = RawEnvGuardStructure::tokenize(RAW_ENV_STRUCTURE_WITH_SHAPE);
    $try = RawEnvGuardStructure::soleBlockRange($tokens, T_TRY);
    $catch = RawEnvGuardStructure::soleBlockRange($tokens, T_CATCH);
    $finally = RawEnvGuardStructure::soleBlockRange($tokens, T_FINALLY);

    expect(RawEnvGuardStructure::applyLoopIsGuarded($tokens, ['$changes'], $try, 'apply'))->toBeTrue()
        ->and(RawEnvGuardStructure::methodCallArgumentMatches($tokens, $finally, '$snapshot', 'restore', 0, ['$bodyError']))->toBeTrue()
        ->and(RawEnvGuardStructure::variableAssignmentMatches($tokens, $catch, '$bodyError', ['$e']))->toBeTrue()
        ->and(RawEnvGuardStructure::soleThrowMatches($tokens, $catch, ['$e']))->toBeTrue();
});

test('正例 2: 持ち回りの口と同形の構造で復元と再送出が catch 内と判定される', function (): void {
    $tokens = RawEnvGuardStructure::tokenize(RAW_ENV_STRUCTURE_CAPTURE_SHAPE);
    $try = RawEnvGuardStructure::soleBlockRange($tokens, T_TRY);
    $catch = RawEnvGuardStructure::soleBlockRange($tokens, T_CATCH);

    expect(RawEnvGuardStructure::findTokens($tokens, T_FINALLY))->toBe([])
        ->and(RawEnvGuardStructure::applyLoopIsGuarded($tokens, ['$keys'], $try, 'apply'))->toBeTrue()
        ->and(RawEnvGuardStructure::methodCallArgumentMatches($tokens, $catch, '$snapshot', 'restore', 0, ['$e']))->toBeTrue()
        ->and(RawEnvGuardStructure::indexesWithin(RawEnvGuardStructure::controlFlowTokens($tokens, T_THROW), $catch))->toHaveCount(1);
});

test('正例 3: $this->state を直接回す foreach を 1 件見つける', function (): void {
    $tokens = RawEnvGuardStructure::tokenize(RAW_ENV_STRUCTURE_RESTORE_SHAPE);

    expect(RawEnvGuardStructure::foreachOverExpression($tokens, ['$this', '->', 'state']))->toHaveCount(1);
});

test('正例 4: 復元と同形の構造が「途中終了せず蓄積してから 1 か所で送出する」と判定される', function (): void {
    $tokens = RawEnvGuardStructure::tokenize(RAW_ENV_STRUCTURE_RESTORE_SHAPE);

    expect(RawEnvGuardStructure::restoreStructureIsDeferred($tokens, ['$this', '->', 'state'], '$failed', '$applied'))->toBeTrue()
        ->and(RawEnvGuardStructure::constructionArgumentMatches($tokens, RawEnvSnapshot::class, RuntimeException::class, 2, ['$previous']))->toBeTrue();
});

// ── 負例 ──

test('負例 1: 適用の foreach を try の外へ出すと判定が偽になる', function (): void {
    $tokens = RawEnvGuardStructure::tokenize(<<<'PHP'
    public static function with(array $changes, Closure $body): mixed
    {
        foreach ($changes as $key => $channels) {
            self::apply((string) $key, $channels);
        }

        try {
            return $body();
        } finally {
            $snapshot->restore($bodyError);
        }
    }
    PHP);

    $try = RawEnvGuardStructure::soleBlockRange($tokens, T_TRY);

    expect(RawEnvGuardStructure::applyLoopIsGuarded($tokens, ['$changes'], $try, 'apply'))->toBeFalse();
});

test('負例 2: 復元の呼び出しを finally の外へ出すと判定が偽になる', function (): void {
    $tokens = RawEnvGuardStructure::tokenize(<<<'PHP'
    public static function with(array $changes, Closure $body): mixed
    {
        try {
            foreach ($changes as $key => $channels) {
                self::apply((string) $key, $channels);
            }

            return $body();
        } finally {
        }

        $snapshot->restore($bodyError);
    }
    PHP);

    $finally = RawEnvGuardStructure::soleBlockRange($tokens, T_FINALLY);

    expect(RawEnvGuardStructure::methodCallArgumentMatches($tokens, $finally, '$snapshot', 'restore', 0, ['$bodyError']))->toBeFalse();
});

test('負例 3: 空のループを try に残して適用を外へ移すと判定が偽になる', function (): void {
    $tokens = RawEnvGuardStructure::tokenize(<<<'PHP'
    public static function with(array $changes, Closure $body): mixed
    {
        try {
            foreach ($changes as $key => $channels) {
            }

            self::apply('K', $channels);

            return $body();
        } finally {
            $snapshot->restore($bodyError);
        }
    }
    PHP);

    $try = RawEnvGuardStructure::soleBlockRange($tokens, T_TRY);

    expect(RawEnvGuardStructure::applyLoopIsGuarded($tokens, ['$changes'], $try, 'apply'))->toBeFalse();
});

test('負例 4: catch から throw を落とすと判定が偽になる', function (): void {
    $tokens = RawEnvGuardStructure::tokenize(<<<'PHP'
    public static function captureAndClear(array $keys): self
    {
        try {
            foreach ($keys as $key) {
                self::apply($key, RawEnvChannels::none());
            }
        } catch (Throwable $e) {
            $snapshot->restore($e);
        }

        return $snapshot;
    }
    PHP);

    $catch = RawEnvGuardStructure::soleBlockRange($tokens, T_CATCH);

    expect(RawEnvGuardStructure::indexesWithin(RawEnvGuardStructure::controlFlowTokens($tokens, T_THROW), $catch))->toBe([])
        ->and(RawEnvGuardStructure::soleThrowMatches($tokens, $catch, ['$e']))->toBeFalse();
});

test('負例 5: throw を復元のループの中へ入れると判定が偽になる', function (): void {
    $tokens = RawEnvGuardStructure::tokenize(<<<'PHP'
    public function restore(?Throwable $previous = null): void
    {
        $failed = [];

        foreach ($this->state as $saved) {
            $applied = putenv($saved['key']);

            if ($applied === false) {
                $failed[] = $saved['key'];

                throw new RuntimeException('boom', 0, $previous);
            }
        }
    }
    PHP);

    expect(RawEnvGuardStructure::restoreStructureIsDeferred($tokens, ['$this', '->', 'state'], '$failed', '$applied'))->toBeFalse();
});

test('負例 6: 面を直接回していない foreach は候補に入らない', function (): void {
    $tokens = RawEnvGuardStructure::tokenize(<<<'PHP'
    public function run(array $changes): void
    {
        foreach (array_keys($changes) as $key) {
        }

        foreach (array_values($this->state) as $saved) {
        }
    }
    PHP);

    expect(RawEnvGuardStructure::foreachOverExpression($tokens, ['$changes']))->toBe([])
        ->and(RawEnvGuardStructure::foreachOverExpression($tokens, ['$this', '->', 'state']))->toBe([]);
});

test('負例 7: 復元のループの中で break して抜けると判定が偽になる', function (): void {
    $tokens = RawEnvGuardStructure::tokenize(<<<'PHP'
    public function restore(?Throwable $previous = null): void
    {
        $failed = [];

        foreach ($this->state as $saved) {
            $applied = putenv($saved['key']);

            if ($applied === false) {
                $failed[] = $saved['key'];

                break;
            }
        }

        if ($failed !== []) {
            throw new RuntimeException('boom', 0, $previous);
        }
    }
    PHP);

    expect(RawEnvGuardStructure::restoreStructureIsDeferred($tokens, ['$this', '->', 'state'], '$failed', '$applied'))->toBeFalse();
});

test('負例 8: 失敗を蓄積せず無条件に送出する形は判定が偽になる', function (): void {
    $tokens = RawEnvGuardStructure::tokenize(<<<'PHP'
    public function restore(?Throwable $previous = null): void
    {
        $failed = [];

        foreach ($this->state as $saved) {
            $applied = putenv($saved['key']);
        }

        if ($failed !== []) {
            throw new RuntimeException('boom', 0, $previous);
        }
    }
    PHP);

    expect(RawEnvGuardStructure::restoreStructureIsDeferred($tokens, ['$this', '->', 'state'], '$failed', '$applied'))->toBeFalse();
});

test('負例 9: 例外の連結の引数を落とすと判定が偽になる', function (): void {
    $noPrevious = RawEnvGuardStructure::tokenize(<<<'PHP'
    public function restore(?Throwable $previous = null): void
    {
        throw new RuntimeException('boom', 0);
    }
    PHP);

    $noArgument = RawEnvGuardStructure::tokenize(<<<'PHP'
    public static function with(array $changes, Closure $body): mixed
    {
        try {
            return $body();
        } finally {
            $snapshot->restore();
        }
    }
    PHP);

    $finally = RawEnvGuardStructure::soleBlockRange($noArgument, T_FINALLY);

    expect(RawEnvGuardStructure::constructionArgumentMatches($noPrevious, RawEnvSnapshot::class, RuntimeException::class, 2, ['$previous']))->toBeFalse()
        ->and(RawEnvGuardStructure::methodCallArgumentMatches($noArgument, $finally, '$snapshot', 'restore', 0, ['$bodyError']))->toBeFalse();
});

test('負例 10: catch の中で本体の例外を握り潰すと判定が偽になる', function (): void {
    $nulled = RawEnvGuardStructure::tokenize(<<<'PHP'
    public static function with(array $changes, Closure $body): mixed
    {
        try {
            return $body();
        } catch (Throwable $e) {
            $bodyError = null;

            throw $e;
        } finally {
            $snapshot->restore($bodyError);
        }
    }
    PHP);

    $dropped = RawEnvGuardStructure::tokenize(<<<'PHP'
    public static function with(array $changes, Closure $body): mixed
    {
        try {
            return $body();
        } catch (Throwable $e) {
            throw new RuntimeException('replaced');
        } finally {
            $snapshot->restore($bodyError);
        }
    }
    PHP);

    $nulledCatch = RawEnvGuardStructure::soleBlockRange($nulled, T_CATCH);
    $droppedCatch = RawEnvGuardStructure::soleBlockRange($dropped, T_CATCH);

    expect(RawEnvGuardStructure::variableAssignmentMatches($nulled, $nulledCatch, '$bodyError', ['$e']))->toBeFalse()
        ->and(RawEnvGuardStructure::variableAssignmentMatches($dropped, $droppedCatch, '$bodyError', ['$e']))->toBeFalse()
        ->and(RawEnvGuardStructure::soleThrowMatches($dropped, $droppedCatch, ['$e']))->toBeFalse();
});

test('負例 11: 対象変数に結び付いていない条件は誤認しない', function (): void {
    $tokens = RawEnvGuardStructure::tokenize(<<<'PHP'
    public function restore(?Throwable $previous = null): void
    {
        $failed = [];

        foreach ($this->state as $saved) {
            $applied = putenv($saved['key']);

            if (! $applied && $other === false) {
                $failed[] = $saved['key'];
            }
        }

        if ($failed !== []) {
            throw new RuntimeException('boom', 0, $previous);
        }
    }
    PHP);

    // ★包含で見ると `$applied` / `===` / `false` の 3 つとも条件に在るので通ってしまう。
    //   完全一致で見れば偽になる (これが load-bearing である)。
    expect(RawEnvGuardStructure::restoreStructureIsDeferred($tokens, ['$this', '->', 'state'], '$failed', '$applied'))->toBeFalse();
});

test('負例 12: 同じ短名の別クラスを生成しても期待クラスとは一致しない', function (): void {
    $tokens = RawEnvGuardStructure::tokenize(<<<'PHP'
    public function restore(?Throwable $previous = null): void
    {
        throw new \Vendor\RuntimeException('boom', 0, $previous);
    }
    PHP);

    expect(RawEnvGuardStructure::constructions($tokens, RawEnvSnapshot::class, RuntimeException::class))->toBe([])
        ->and(RawEnvGuardStructure::constructionArgumentMatches($tokens, RawEnvSnapshot::class, RuntimeException::class, 2, ['$previous']))->toBeFalse();
});

test('正例 5: 条件の綴りの列を完全一致で取り出せる', function (): void {
    $tokens = RawEnvGuardStructure::tokenize(RAW_ENV_STRUCTURE_RESTORE_SHAPE);
    $blocks = RawEnvGuardStructure::ifBlocks($tokens);

    expect($blocks)->toHaveCount(2)
        ->and(RawEnvGuardStructure::conditionEquals($tokens, $blocks[0]['condition'], ['$applied', '===', 'false']))->toBeTrue()
        ->and(RawEnvGuardStructure::conditionEquals($tokens, $blocks[1]['condition'], ['$failed', '!==', '[', ']']))->toBeTrue()
        ->and(RawEnvGuardStructure::conditionEquals($tokens, $blocks[0]['condition'], ['$applied', '===', 'true']))->toBeFalse();
});

test('fail-closed 6: 丸括弧の対応が取れない引数リストは例外になる', function (): void {
    $tokens = RawEnvGuardStructure::tokenize(<<<'PHP'
    public function run(): void
    {
        $snapshot->restore($bodyError
    PHP);

    $calls = RawEnvGuardStructure::methodCalls($tokens, '$snapshot', 'restore');

    expect($calls)->toHaveCount(1)
        ->and(fn (): array => RawEnvGuardStructure::callArguments($tokens, $calls[0]))
        ->toThrow(RuntimeException::class);
});

// ── 解決できない形は落とす (fail-closed) ──

test('fail-closed 1: try が 2 件ある入力は例外になる', function (): void {
    $tokens = RawEnvGuardStructure::tokenize(<<<'PHP'
    public function run(): void
    {
        try {
        } finally {
        }

        try {
        } finally {
        }
    }
    PHP);

    expect(fn (): array => RawEnvGuardStructure::soleBlockRange($tokens, T_TRY))
        ->toThrow(RuntimeException::class);
});

test('fail-closed 2: 波括弧が閉じていない入力は例外になる', function (): void {
    $tokens = RawEnvGuardStructure::tokenize(<<<'PHP'
    public function run(): void
    {
        try {
    PHP);

    $tryIndexes = RawEnvGuardStructure::findTokens($tokens, T_TRY);

    expect(fn (): array => RawEnvGuardStructure::blockRange($tokens, $tryIndexes[0]))
        ->toThrow(RuntimeException::class);
});

test('fail-closed 3: 対象メソッドが存在しなければ例外になる', function (): void {
    expect(fn (): array => RawEnvGuardStructure::methodTokens(RawEnvGuardStructure::class, 'noSuchMethod'))
        ->toThrow(RuntimeException::class);
});

test('fail-closed 4: 制御フロー以外の token id を渡すと例外になる', function (): void {
    $tokens = RawEnvGuardStructure::tokenize(RAW_ENV_STRUCTURE_RESTORE_SHAPE);

    expect(fn (): array => RawEnvGuardStructure::controlFlowTokens($tokens, T_IF))
        ->toThrow(InvalidArgumentException::class);
});

test('fail-closed 5: 文の終端が見つからない入力は例外になる', function (): void {
    $tokens = RawEnvGuardStructure::tokenize(<<<'PHP'
    public function run(): void
    {
        throw $e
    PHP);

    $throwIndexes = RawEnvGuardStructure::findTokens($tokens, T_THROW);

    expect(fn (): array => RawEnvGuardStructure::statementTokens($tokens, $throwIndexes[0]))
        ->toThrow(RuntimeException::class);
});

test('正例 6: 名前空間のトップレベルの取り込みだけを解く', function (): void {
    $imports = RawEnvGuardStructure::classImports(<<<'PHP'
    <?php

    namespace Tests\Probe;

    use RuntimeException;
    use Vendor\Thing as Alias;
    use function Vendor\helper;
    use const Vendor\LIMIT;

    class Probe
    {
        use SomeTrait;

        public function run(): void
        {
            $f = function () use ($x): void {};
        }
    }
    PHP, 'Tests\Probe');

    // trait の取り込み (`use SomeTrait;`) はクラス本体の中なので数えない。
    expect($imports)->toBe([
        'runtimeexception' => 'RuntimeException',
        'alias' => 'Vendor\Thing',
    ]);
});

test('fail-closed 7: 取り込みを解けない形はすべて例外になる', function (string $source, string $namespace): void {
    expect(fn (): array => RawEnvGuardStructure::classImports($source, $namespace))
        ->toThrow(RuntimeException::class);
})->with([
    'two namespaces' => [<<<'PHP'
    <?php
    namespace A;
    use Vendor\RuntimeException;
    namespace B;
    use RuntimeException;
    PHP, 'A'],
    'braced namespace' => [<<<'PHP'
    <?php
    namespace A {
        use RuntimeException;
    }
    PHP, 'A'],
    'namespace mismatch' => [<<<'PHP'
    <?php
    namespace A;
    use RuntimeException;
    PHP, 'B'],
    'group use with a function entry' => [<<<'PHP'
    <?php
    namespace A;
    use Vendor\{Thing, function helper};
    PHP, 'A'],
    'same short name imported twice' => [<<<'PHP'
    <?php
    namespace A;
    use Vendor\RuntimeException;
    use Other\RuntimeException;
    PHP, 'A'],
]);

test('正例 7: namespace\ で始まる相対参照を現在の名前空間から解く', function (): void {
    $relative = RawEnvGuardStructure::tokenize(<<<'PHP'
    public function run(): void
    {
        $x = new namespace\RawEnvChannels();
    }
    PHP);

    $other = RawEnvGuardStructure::tokenize(<<<'PHP'
    public function run(): void
    {
        $x = new namespace\RawEnvWriteSite();
    }
    PHP);

    expect(RawEnvGuardStructure::constructions($relative, RawEnvSnapshot::class, RawEnvChannels::class))->toHaveCount(1)
        ->and(RawEnvGuardStructure::constructions($other, RawEnvSnapshot::class, RawEnvChannels::class))->toBe([]);
});

// ── 母集団 ──

test('母集団: foreach が 1 件も無い入力は例外にせず空を返す', function (): void {
    $tokens = RawEnvGuardStructure::tokenize(<<<'PHP'
    public function run(): void
    {
        $x = 1;
    }
    PHP);

    expect(RawEnvGuardStructure::foreachOverExpression($tokens, ['$changes']))->toBe([])
        ->and(RawEnvGuardStructure::staticCalls($tokens, 'apply'))->toBe([])
        ->and(RawEnvGuardStructure::variableAppends($tokens, '$failed'))->toBe([]);
});
