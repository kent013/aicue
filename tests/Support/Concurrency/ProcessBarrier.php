<?php

declare(strict_types=1);

namespace Tests\Support\Concurrency;

use Closure;
use Webmozart\Assert\Assert;

/**
 * 実プロセス並行テストの合図の待ち合わせ (正典 v1 の要素 (1)(4)(5))。
 *
 * 規律 7 点:
 * 1. ready は**子ごと**に分ける (共有 ready だと片方だけ準備できた状態で go を出せてしまい、
 *    「全員の準備を確認してから同一の合図で解き放つ」という最重要前提が**緑のまま**壊れる)
 * 2. 存在だけでなく**中身を照合**する (空・別 child・誤 nonce を通さない。照合は呼び出し側が行う)
 * 3. 待ちのループでは**毎回 clearstatcache()** する — 捨てないと合図に気付くのが遅れ、
 *    2 本の実行が重ならず並行テストの意味が消える (正典が名指しする作法)
 * 4. 締切は**単調時計** (hrtime) で測る (壁時計は補正で戻りうる)
 * 5. 合図は書きかけ用ディレクトリへ書いてから `link()` で配置する (書きかけを相手に見せない)
 * 6. 名前は {@see SignalName} でしか作れない (このクラスは string の名前を受け取らないし、
 *    名前を作る二重入口も持たない)
 * 7. **同じ合図を 2 回置けない** (`rename()` は既存を上書きするので `link()` を使う。
 *    ready や out の二重送信が黙って隠れるのを塞ぐ)
 *
 * ★**置き場所を 2 つに分ける**: 完成合図は signals/、書きかけは partial/。
 *   同じディレクトリに置くと、完成ファイルの列挙が書きかけを拾って
 *   二重実行の判定が壊れる。列挙を安全にするための分離である。
 * ★読み取りは**注入可能な読み手**越しに行う。`file_get_contents() === false` を
 *   決定的に再現するためで、権限 (chmod 000) に依存する検査は root 実行で不安定になる。
 *
 * **保証しないもの**: 合図の順序関係だけを保証する。実際に処理が重なったかどうかは
 * 呼び出し側 ({@see ConcurrencyProbeRunner}) が entered / release の 3 段で構成する。
 */
final class ProcessBarrier
{
    /** 待ちのポーリング間隔 (マイクロ秒) */
    public const int POLL_INTERVAL_MICROSECONDS = 1_000;

    private readonly ?Closure $reader;

    /**
     * @param  (callable(string): string|false)|null  $reader  既定は file_get_contents
     */
    public function __construct(
        private readonly string $workspaceDirectory,
        ?callable $reader = null,
    ) {
        Assert::directory($workspaceDirectory);
        Assert::directory($this->signalDirectory());
        Assert::directory($this->partialDirectory());

        $this->reader = $reader === null ? null : Closure::fromCallable($reader);
    }

    /**
     * 合図の置き場所 (signals/ と partial/) を作る。既に在れば何もしない。
     */
    public static function prepareWorkspace(string $workspaceDirectory): void
    {
        foreach ([$workspaceDirectory.'/signals', $workspaceDirectory.'/partial'] as $directory) {
            if (is_dir($directory)) {
                continue;
            }

            Assert::true(mkdir($directory, 0700), "合図の置き場所を作れない: {$directory}");
        }
    }

    /**
     * 合図を置く (partial/ へ書いてから signals/ へ配置)。
     *
     * ★配置に `rename()` を使わない。POSIX の `rename()` は**既存ファイルを上書きする**ので、
     *   同じ合図の 2 回目の送信が黙って隠れる (ready や out の二重送信を見逃す)。
     *   `link()` は **target が既に在れば失敗する**ので、TOCTOU のある `is_file()` 判定を
     *   挟まずに二重配置を弾ける。同一 FS 内なので hard link が使える。
     */
    public function signal(SignalName $name, #[\SensitiveParameter] string $payload): void
    {
        $temporary = $this->partialDirectory().'/'.bin2hex(random_bytes(8));

        if (file_put_contents($temporary, $payload) !== strlen($payload)) {
            @unlink($temporary);

            throw ConcurrencyProtocolException::signalNotWritten($name);
        }

        try {
            // 既に在れば false。原子的に「無ければ置く」を実現する。
            if (@link($temporary, $this->path($name))) {
                return;
            }

            // ★失敗の**分類**を target の存在で行う。すべてを二重配置に倒すと、
            //   権限・I/O 障害・hard link 非対応まで「二重送信を検出した」という
            //   嘘の診断になる。
            clearstatcache(true, $this->path($name));

            throw is_file($this->path($name))
                ? ConcurrencyProtocolException::duplicateSignal($name)
                : ConcurrencyProtocolException::signalNotPlaced($name);
        } finally {
            @unlink($temporary);
        }
    }

    /**
     * 合図が現れるまで待ち、その中身を返す。
     *
     * @param  float  $remainingSeconds  呼び出し側が持つ**絶対 deadline** からの残り時間
     * @param  (callable(): void)|null  $abortIf  待機中に毎周回呼ぶ中断条件
     *                                            (二重実行の検出・子の異常終了など。
     *                                            呼び先が例外を投げれば締切を待たずに抜ける)
     *
     * @throws BarrierTimeoutException 締切を超えた
     * @throws ConcurrencyProtocolException 合図はあるのに読めない
     */
    public function await(SignalName $name, float $remainingSeconds, ?callable $abortIf = null): string
    {
        Assert::greaterThan($remainingSeconds, 0.0);

        $deadline = hrtime(true) + (int) ($remainingSeconds * 1_000_000_000);

        while (true) {
            if ($abortIf !== null) {
                $abortIf();
            }

            // ★毎周回捨てる。捨てないと合図に気付くのが遅れ、2 本の実行が重ならない。
            clearstatcache(true, $this->path($name));

            if (is_file($this->path($name))) {
                return $this->read($name);
            }

            if (hrtime(true) >= $deadline) {
                throw BarrierTimeoutException::waitingFor($name, $remainingSeconds);
            }

            usleep(self::POLL_INTERVAL_MICROSECONDS);
        }
    }

    /**
     * 完成合図のディレクトリを**列挙**し、現れている名前を返す。
     *
     * ★prefix の glob は採らない。書きかけは別ディレクトリなので、ここでの列挙は
     *   完成ファイルだけを見る。
     * ★**許可集合に無い完成ファイルが 1 つでもあれば例外**にする
     *   (未知の child ID の合図を「無視」ではなく「拒否」にする)。
     *
     * @param  list<SignalName>  $allowed  許可される完成合図の全集合
     * @return list<SignalName> 現れている合図
     *
     * @throws ConcurrencyProtocolException 未知の完成ファイルがある
     */
    public function present(array $allowed): array
    {
        clearstatcache(true, $this->signalDirectory());

        $entries = scandir($this->signalDirectory());
        if ($entries === false) {
            throw ConcurrencyProtocolException::signalDirectoryUnreadable($this->signalDirectory());
        }

        $allowedValues = array_map(static fn (SignalName $name): string => $name->value, $allowed);

        $present = [];
        $unknown = [];

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $index = array_search($entry, $allowedValues, true);
            if ($index === false) {
                $unknown[] = $entry;

                continue;
            }

            $present[] = $allowed[$index];
        }

        if ($unknown !== []) {
            throw ConcurrencyProtocolException::unknownSignal($unknown);
        }

        return $present;
    }

    public function path(SignalName $name): string
    {
        return $this->signalDirectory().'/'.$name->value;
    }

    /**
     * 合図を読む。**読めない合図は空として通さず例外**にする (fail-closed)。
     *
     * 合図はあるのに読めない = 観測が成立していない。空として通すと後続の照合が
     * 別の理由で落ちて原因が隠れる。
     */
    private function read(SignalName $name): string
    {
        $reader = $this->reader ?? file_get_contents(...);
        $contents = $reader($this->path($name));

        if ($contents === false) {
            throw ConcurrencyProtocolException::signalUnreadable($name);
        }

        return $contents;
    }

    private function signalDirectory(): string
    {
        return $this->workspaceDirectory.'/signals';
    }

    private function partialDirectory(): string
    {
        return $this->workspaceDirectory.'/partial';
    }
}
