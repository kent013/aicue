<?php

declare(strict_types=1);

namespace Tests\Support\Concurrency;

use RuntimeException;
use Throwable;

/**
 * 実プロセス並行テストの**プロトコルが破られた**。
 *
 * ★{@see BarrierTimeoutException} と型を分けている。とくに {@see self::doubleExecution()} は
 *   本ハーネスが探している退行そのものなので、締切超過という紛らわしい形では出さない。
 */
final class ConcurrencyProtocolException extends RuntimeException
{
    /**
     * 既に伏せ字にしたメッセージで同じ型を作り直す (診断の choke point 専用)。
     *
     * ★`new ($e::class)(…)` のような**動的 new を使わない**。生成されるクラスが静的に決まらない形は
     *   走査器から見えなくなり、実際に `CachePayloadPlainDataGateTest` の検査 L4h が
     *   deny-by-default で拒否する。型ごとに明示の入口を持つ。
     */
    public static function withRedactedMessage(string $message): self
    {
        return new self($message);
    }

    /**
     * 探している退行そのもの: 本処理へ 2 本とも入った。
     *
     * @param  list<string>  $enteredSignals
     */
    public static function doubleExecution(array $enteredSignals): self
    {
        return new self(
            '本処理へ 2 本とも入った (二重実行を検出): '.implode(',', $enteredSignals)
        );
    }

    public static function childDiedEarly(string $childId, ?int $exitCode, #[\SensitiveParameter] string $stderr): self
    {
        return new self(sprintf(
            '子 "%s" が観測を出さずに終了した (exit=%s)。stderr: %s',
            $childId,
            $exitCode === null ? 'unknown' : (string) $exitCode,
            $stderr === '' ? '(なし)' : $stderr,
        ));
    }

    public static function identityMismatch(
        string $childId,
        string $field,
        string $expected,
        #[\SensitiveParameter] string $actual,
    ): self {
        return new self(sprintf(
            '子 "%s" の同一性が一致しない (%s: 期待 "%s" / 実際 "%s")',
            $childId,
            $field,
            $expected,
            $actual,
        ));
    }

    public static function goTokenMismatch(string $childId, string $expected, #[\SensitiveParameter] string $actual): self
    {
        return new self(sprintf(
            '子 "%s" の go token が一致しない (期待 "%s" / 実際 "%s")。'
            .'go を読まずに走った可能性がある',
            $childId,
            $expected,
            $actual,
        ));
    }

    public static function unexpectedObservation(#[\SensitiveParameter] string $reason): self
    {
        return new self('子の観測が受理条件を満たさない: '.$reason);
    }

    /**
     * 許可集合に無い完成合図が現れた (無視ではなく拒否する)。
     *
     * @param  list<string>  $names
     */
    public static function unknownSignal(#[\SensitiveParameter] array $names): self
    {
        return new self(
            '許可集合に無い完成合図がある: '.implode(',', $names)
        );
    }

    public static function signalUnreadable(SignalName $name): self
    {
        return new self("合図 \"{$name->value}\" は在るのに読めない (観測が成立していない)");
    }

    public static function signalNotWritten(SignalName $name): self
    {
        return new self("合図 \"{$name->value}\" の書きかけを書き切れなかった");
    }

    public static function signalNotPlaced(SignalName $name): self
    {
        return new self(
            "合図 \"{$name->value}\" を配置できなかった (target は不在。権限 / I/O 障害 / "
            .'hard link 非対応のいずれか)'
        );
    }

    public static function duplicateSignal(SignalName $name): self
    {
        return new self("合図 \"{$name->value}\" を 2 回置こうとした (二重送信)");
    }

    public static function signalDirectoryUnreadable(string $directory): self
    {
        return new self("完成合図のディレクトリを列挙できない: {$directory}");
    }

    /**
     * 回収に失敗した (問題が複数あればすべて 1 つの例外へ載せる)。
     *
     * ★**先に見つかった 1 つで打ち切らない**。秘密を消せなかったことと停止を確認できない子が
     *   残っていることは**別々の危険**であり、片方だけを報告すると残りが診断から消える。
     * ★元の失敗 ($previous) は畳んで捨てない (回収の失敗が原因を隠さないようにする)。
     *
     * @param  list<string>  $problems
     */
    public static function reapFailed(array $problems, ?Throwable $previous = null): self
    {
        return new self('回収に失敗した: '.implode(' / ', $problems), previous: $previous);
    }
}
