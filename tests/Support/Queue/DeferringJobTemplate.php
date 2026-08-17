<?php

declare(strict_types=1);

namespace Tests\Support\Queue;

use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * **配布する雛形**: 退避 (release) を正常系に持つジョブの標準形 v1 (lctl 裁定 AG-081)。
 *
 * 退避は「失敗」ではないのに `attempts` を 1 つ消費する。したがって終端条件を試行回数で
 * 持つと、失敗していないジョブが回数を使い切って落ちる。標準形 v1 はこれを
 * **絶対時刻の期限 (retryUntil) + 未処理例外だけを数える $maxExceptions** の 2 本で解く。
 *
 * ■ 使い方
 *   1. **`app/Jobs/` へコピーして使う**。このファイル自体は dispatch しない
 *      (誰も投入しない実装を app/ に置かないため tests/Support/ に住んでいる)。
 *   2. **`$tries` / `#[Tries]` / `tries()` を書かない**。
 *      `Worker::markJobAsFailedIfAlreadyExceedsMaxAttempts()` が
 *      `if ($retryUntil && Carbon::now()->getTimestamp() <= $retryUntil) { return; }` で
 *      **先に return する**ため、`retryUntil` があると `maxTries` は一切参照されない。
 *      書いても効かず「回数でも止まる」という誤読しか生まない。
 *   3. **地平線の長さは自ドメインの設定キーから読む**こと
 *      (`return now()->addMinutes(config('<自ドメイン>.retry_horizon_minutes'));` も許可形)。
 *      雛形が設定キーを持たないのは、誰も読まない dead config を家系へ配らないためである。
 *   4. **退避を正常系に持たないジョブには適用しない** (失敗が本当の失敗であるジョブ)。
 *      分類は `jobDeferralTerminationInventory()`
 *      (tests/Architecture/JobDeferralTerminationGateTest.php) へ全数申告する。
 *   5. **退避したジョブの回収経路を持たないまま本形を採らないこと** —
 *      終端しなかった仕事がどう回収されるかまで pin しないと、ジョブが黙って消える退行を
 *      検出できない。**本リポジトリには回収の入口が既にある** —
 *      `work:recover-stuck --stream=<key>` ただ 1 本である (AGENTS.md ドメイン規約 14)。
 *      退避を正常系に持つジョブを足すときは、そこへ系列を 1 つ足すかどうかを必ず判断すること
 *      (`App\Enums\Recovery\RecoveryStream` の case / registry / 目録 / Schedule の 4 つを同時に更新する)。
 *   6. **期限と `$maxExceptions` は push 時に payload へ焼き込まれる**ので、
 *      既に払い出されたジョブには効かない (デプロイ後に投入されたものから効く)。
 *
 * 契約の機械検査は `tests/Architecture/JobDeferralTerminationGateTest.php` (C1-C5)、
 * 振る舞いは `tests/Feature/Queue/DeferredRetryHorizonTest.php` (B0-B4)。
 */
final class DeferringJobTemplate implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * 未処理例外だけを数える上限。
     *
     * 退避 (正常系) はこのカウンタを増やさないので、期限で有界化しつつ
     * **本当の失敗は期限を待たずに止められる** (裁定の必須点 3)。
     */
    public int $maxExceptions = 3;

    /** 期限の地平線 (分)。自ドメインでは設定キーから読むこと (docblock 3.)。 */
    private const HORIZON_MINUTES = 30;

    /**
     * 絶対時刻の期限。**push 時に 1 度だけ**評価され payload へ焼き込まれるので、
     * 退避を繰り返しても延びない (裁定の必須点 1 / 2)。
     */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(self::HORIZON_MINUTES);
    }

    public function handle(): void
    {
        // 雛形なので何もしない (配布物に「何もしない handle()」以上のものを入れない)。
    }
}
