<?php

declare(strict_types=1);

use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\Worker;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue as QueueFacade;
use Tests\Support\Queue\DeferringJobTemplate;
use Tests\Support\Queue\DeferringNoContractProbeJob;
use Tests\Support\Queue\DeferringReleaseProbeJob;
use Tests\Support\Queue\DeferringThrowProbeJob;
use Webmozart\Assert\Assert;

/*
|--------------------------------------------------------------------------
| 退避 (release) の終端 — 振る舞いの正本 (標準形 v1 / lctl 裁定 AG-081)
|--------------------------------------------------------------------------
|
| 本テストが pin しているのは**アプリのコードではなく laravel/framework の性質**である。
| 「期限は push 時に payload へ焼き込まれ、期限がある間は試行回数を参照しない」という
| 前提の上に標準形 v1 が成り立っており、テンプレートはこの前提を子アプリへ配っている。
| **framework の更新でここが変わったら赤くなるのが本テストの本体**である。
|
| pin している vendor 実装 (laravel/framework v13.18.0):
|   - Queue/Queue.php:170-186          createObjectPayload() が push 時に
|                                      maxTries / maxExceptions / retryUntil / createdAt を焼き込む
|   - Queue/Queue.php:268-280          getJobExpiration() が retryUntil() を **push 時に 1 度だけ**呼ぶ
|   - Queue/Jobs/DatabaseJob.php:49-54 release() が deleteAndRelease() を呼ぶ
|   - Queue/DatabaseQueue.php:328-331  release() が既存 payload をそのまま押し戻す
|                                      (createObjectPayload を通らない = 期限が延びない)
|   - Queue/Worker.php:637-654         markJobAsFailedIfAlreadyExceedsMaxAttempts() は
|                                      retryUntil があると maxTries を一切参照しない
|   - Queue/Worker.php:686-700         markJobAsFailedIfWillExceedMaxExceptions() は
|                                      job-exceptions:{uuid} のキャッシュカウンタで未処理例外だけを数える
|
*/

/**
 * jobs テーブルの単一行の payload を配列で読む。
 *
 * **unserialize しない** (vendor wrapper 越しでも読めるようにするため。
 * QueuedJobLeaseGuard の docblock と同じ根拠)。
 *
 * @return array<string, mixed>
 */
function deferredHorizonJobPayload(): array
{
    $row = DB::table('jobs')->orderBy('id')->first();
    Assert::notNull($row, 'jobs テーブルに行が無い');
    Assert::propertyExists($row, 'payload');
    Assert::string($row->payload);

    $decoded = json_decode($row->payload, true);
    Assert::isArray($decoded);

    return $decoded;
}

beforeEach(function (): void {
    // `job-exceptions:{uuid}` のカウンタ (B4) は同一プロセス内で完結すればよいので array store で足りる。
    // テストレーンは phpunit.xml が `CACHE_STORE=array` を force しているため**設定を書き換えず**、
    // 前提が崩れたら気づけるように読み取りで pin する
    // (`config()->set(...)` は CachePayloadPlainDataGateTest が「受け手を解決できない cache 書き込み」
    //  として落とす — 静的には config repository と cache repository を区別できないため)。
    expect(config('cache.default'))->toBe('array');
});

afterEach(function (): void {
    Carbon::setTestNow(null);
});

test('B1: push すると payload の retryUntil が push 時刻 + 地平線になる', function (): void {
    $first = Carbon::parse('2026-08-09 09:41:00');
    Carbon::setTestNow($first);

    QueueFacade::connection('database')->push(new DeferringJobTemplate);

    $payload = deferredHorizonJobPayload();
    expect($payload['retryUntil'])->toBe($first->copy()->addMinutes(30)->getTimestamp());

    // 対照: 基準は push 時刻であって固定値ではない (時刻を進めると期限も進む)。
    DB::table('jobs')->delete();
    $second = $first->copy()->addHours(3);
    Carbon::setTestNow($second);

    QueueFacade::connection('database')->push(new DeferringJobTemplate);

    $payload = deferredHorizonJobPayload();
    expect($payload['retryUntil'])->toBe($second->copy()->addMinutes(30)->getTimestamp());
});

/**
 * database 接続のワーカーを 1 回だけ回す。
 *
 * **ワーカー起動コマンドの文字列をソースへ書かない** (`QueueWorkerLeaseInvariantTest` 規則 1 が
 * git 追跡ファイル全数から起動定義を検出し、非実行と分類するのは devnotes 配下の Markdown だけ)。
 * したがって `Worker` を container から解決して `runNextJob()` を直接呼ぶ形にする
 * (既存 `tests/Feature/Queue/WorkerTimeoutTransitionTest.php` と同じ形)。
 *
 * **cache を明示的に渡す**のが要点である: `Worker::markJobAsFailedIfWillExceedMaxExceptions()` は
 * `if (! $this->cache || ...) { return; }` で始まるため、cache を渡し忘れたワーカーでは
 * `$maxExceptions` が**無言で効かない**。container の `queue.worker` は cache を持たず
 * (`Illuminate\Queue\Console\WorkCommand` が起動時に `setCache()` する)、
 * ここを省くと B4 が「終端しない」を観測して偽グリーンになる。
 */
function deferredHorizonRunWorker(int $maxTries): void
{
    /** @var Worker $worker */
    $worker = app('queue.worker');

    // ★ aicue 適合 (T215): `app('cache')->driver()` を 1 式で直結する。
    //   変数へ分けて渡すと `CachePayloadPlainDataGateTest` の受け手検出 (型宣言直後の変数だけを
    //   追跡する簡易ヒューリスティック) が `$cache` を拾えず、role=driver-handoff の裏取りが
    //   「呼び出し 0 件」で落ちる (docblock の `@var` 型注釈はトークン解析の対象外)。
    //   1 式に直結すれば `app()` 呼び出しへ直接連鎖するチェーンとして検出され、
    //   読み出し・書き込みを一切行わず driver を解決するだけであることを実測で裏取りできる。
    $worker->setCache(app('cache')->driver())
        ->runNextJob('database', 'default', new WorkerOptions(maxTries: $maxTries, sleep: 0));
}

/**
 * 終端 (失敗) の観測は **JobFailed イベント**で行う。
 *
 * `failed_jobs` テーブルへの記録はワーカー本体ではなく**起動コマンド側の listener**が行う
 * (`Illuminate\Queue\Console\WorkCommand::listenForEvents()` → `queue.failer`)。
 * `runNextJob()` を直接呼ぶ本テストではその listener が居ないので、行数を見ると
 * **常に 0 件 = 偽グリーン**になる (実測済み)。既存の
 * `tests/Feature/Queue/WorkerTimeoutTransitionTest.php` も同じ理由でイベントを数えている。
 *
 * 戻り値を **ArrayObject (参照型)** にしているのは、素の配列だと listener が書き込む先が
 * 関数ローカルの配列で、呼び出し側が受け取るのはその**コピー**になり、
 * 失敗を 1 件も観測できないまま緑になるためである (実測済み)。
 *
 * @return ArrayObject<int, Throwable>
 */
function deferredHorizonObserveFailures(): ArrayObject
{
    /** @var ArrayObject<int, Throwable> $observed */
    $observed = new ArrayObject;

    Event::listen(JobFailed::class, function (JobFailed $event) use ($observed): void {
        $observed->append($event->exception);
    });

    return $observed;
}

/** jobs を空にする (フェーズの切り替え用)。 */
function deferredHorizonResetQueue(): void
{
    DB::table('jobs')->delete();
}

test('B0: release したジョブは削除されず attempts を進めて再投入される (前提の pin)', function (): void {
    // B2 / B3 が成立する前提そのもの。framework がここを変えたら B2/B3 より先に本ケースが赤くなる。
    // 根拠 (vendor 実読): CallQueuedHandler.php:98-100 は `if (! $job->isDeletedOrReleased()) { $job->delete(); }`
    // であり、handle() 内の release() が `released = true` を立てるので delete() は走らない。
    // DatabaseQueue.php:328-331 の release() は `pushToDatabase($queue, $job->payload, $delay, $job->attempts)`
    // で**同じ payload を attempts 込みで押し戻す**。
    Carbon::setTestNow(Carbon::parse('2026-08-09 09:41:00'));
    $observed = deferredHorizonObserveFailures();

    QueueFacade::connection('database')->push(new DeferringReleaseProbeJob);
    expect(DB::table('jobs')->count())->toBe(1);

    deferredHorizonRunWorker(maxTries: 1);

    expect(DB::table('jobs')->count())->toBe(1, 'release したジョブが削除されている');
    expect($observed->getArrayCopy())->toBeEmpty();

    $row = DB::table('jobs')->orderBy('id')->first();
    Assert::notNull($row);
    Assert::propertyExists($row, 'attempts');
    expect((int) $row->attempts)->toBe(1, '退避が attempts を消費していない');
});

test('B2: 退避を繰り返しても payload の retryUntil が延びない', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-08-09 09:41:00'));

    QueueFacade::connection('database')->push(new DeferringReleaseProbeJob);

    $initial = deferredHorizonJobPayload();
    $expectedRetryUntil = $initial['retryUntil'];
    $expectedUuid = $initial['uuid'];

    for ($attempt = 1; $attempt <= 3; $attempt++) {
        // 時刻を進めても期限が延びないこと (payload は作り直されない) を見る
        Carbon::setTestNow(Carbon::parse('2026-08-09 09:41:00')->addMinutes($attempt));
        deferredHorizonRunWorker(maxTries: 1);

        $payload = deferredHorizonJobPayload();
        expect($payload['retryUntil'])->toBe($expectedRetryUntil, "退避 {$attempt} 回目で期限が延びた");
        // B4 の cache キー (job-exceptions:{uuid}) が積み上がる前提でもある
        expect($payload['uuid'])->toBe($expectedUuid, "退避 {$attempt} 回目で uuid が変わった");
    }
});

test('B3: 期限内なら attempts が maxTries を超えても失敗しない / 期限後は失敗する', function (): void {
    $start = Carbon::parse('2026-08-09 09:41:00');
    Carbon::setTestNow($start);
    $observed = deferredHorizonObserveFailures();

    QueueFacade::connection('database')->push(new DeferringReleaseProbeJob);

    // 期限内: maxTries=1 でも退避 3 回で失敗しない (retryUntil があると maxTries は参照されない)
    for ($attempt = 1; $attempt <= 3; $attempt++) {
        deferredHorizonRunWorker(maxTries: 1);
        expect($observed->getArrayCopy())->toBeEmpty("期限内なのに {$attempt} 回目で失敗した");
        expect(DB::table('jobs')->count())->toBe(1);
    }

    // 期限後: 次の実行で失敗する
    Carbon::setTestNow($start->copy()->addMinutes(31));
    deferredHorizonRunWorker(maxTries: 1);

    expect($observed->getArrayCopy())->toHaveCount(1, '期限を過ぎても終端しない');
    expect($observed->getArrayCopy()[0])->toBeInstanceOf(MaxAttemptsExceededException::class);
    expect(DB::table('jobs')->count())->toBe(0);

    // 対照: retryUntil を持たないジョブは回数で終端する (期限方式が効いていることの裏返し)
    deferredHorizonResetQueue();
    $observed->exchangeArray([]);
    Carbon::setTestNow($start);
    QueueFacade::connection('database')->push(new DeferringNoContractProbeJob);

    deferredHorizonRunWorker(maxTries: 1);
    expect($observed->getArrayCopy())->toBeEmpty();

    deferredHorizonRunWorker(maxTries: 1);
    expect($observed->getArrayCopy())->toHaveCount(1, '期限を持たないジョブが回数で終端していない');
});

test('B4: 未処理例外が $maxExceptions に達したところで期限より前に終端する', function (): void {
    // 時刻は期限内に固定する (期限切れによる終端と混ざらないようにするため)。
    Carbon::setTestNow(Carbon::parse('2026-08-09 09:41:00'));
    $observed = deferredHorizonObserveFailures();

    QueueFacade::connection('database')->push(new DeferringThrowProbeJob);
    $uuid = deferredHorizonJobPayload()['uuid'];

    // maxTries=0 は無制限 (Worker::markJobAsFailedIfAlreadyExceedsMaxAttempts の $maxTries === 0 分岐)。
    // 回数で終端させないことで、終端理由を $maxExceptions に限定する。
    for ($attempt = 1; $attempt <= 2; $attempt++) {
        deferredHorizonRunWorker(maxTries: 0);
        expect($observed->getArrayCopy())->toBeEmpty("\$maxExceptions=3 なのに例外 {$attempt} 回で終端した");
        expect(deferredHorizonJobPayload()['uuid'])->toBe($uuid, 'uuid が変わり例外カウンタが積み上がらない');
    }

    deferredHorizonRunWorker(maxTries: 0);

    expect($observed->getArrayCopy())->toHaveCount(1, '$maxExceptions に達しても終端しない');
    expect($observed->getArrayCopy()[0]->getMessage())->toBe(
        DeferringThrowProbeJob::FAILURE_MARKER,
        '終端理由が未処理例外ではない (期限切れ等の別条件で終端している)',
    );

    // 対照: $maxExceptions を宣言しないジョブは同じ回数の例外でも終端しない
    deferredHorizonResetQueue();
    $observed->exchangeArray([]);
    QueueFacade::connection('database')->push(new DeferringNoContractProbeJob(throws: true));

    for ($attempt = 1; $attempt <= 3; $attempt++) {
        deferredHorizonRunWorker(maxTries: 0);
    }

    expect($observed->getArrayCopy())->toBeEmpty('対照が終端している (検出が $maxExceptions 由来でない可能性)');
});
