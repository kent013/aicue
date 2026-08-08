<?php

declare(strict_types=1);

use App\Mail\InquiryReceivedMail;
use App\Notifications\Billing\PaymentFailedNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Mail\Mailable;
use Symfony\Component\Finder\Finder;
use Tests\Support\Queue\QueueDispatchDeferralInventory;
use Tests\Support\QueuedJobPopulation;

/*
|--------------------------------------------------------------------------
| キュー投入の commit 後ずらしを deny-by-default で 0 件に固定する (AG-114 確定 1 / AG-126)
|--------------------------------------------------------------------------
|
| ★ **allow-list を持たない deny-by-default** である。免除目録 (enum) は**作っていない** —
|   確定 1 の queue dispatch 母集団における除外が 0 件だからで、case を 1 つも持たない
|   exemption enum は死んだ機構になる (思考原則 2)。将来除外が必要になったら
|   この gate が落ちるので、そのときに免除機構ごと設計し直すこと。
|
| ★ D1/D2/D5(代入) は **token 走査** (PhpTokenScan) で行い、コメント・docblock・
|   文字列リテラルは対象外にする。素の grep にすると契約の反転 docblock
|   (旧主張として `->afterCommit()` を引用する) で gate が落ちてしまう。
|
| ★ 母集団の**完全性**そのものは QueuedJobLeaseInventoryTest / JobExecutionDedupInventoryTest が
|   対称差 0 で既に固定しているため、本 gate では 0 件 fail のみとし二重実装しない。
|
| ★ 保証しないもの: token 走査でも**動的な迂回**には沈黙する —
|   `$m = 'afterCommit'; $job->$m();` / helper・facade alias で包んだ呼び出し /
|   `$this->afterCommit = $flag;` のような動的値 / vendor 内の afterCommit 使用。
|   また **「dispatch が業務 tx の内側にあること」の静的完全性は保証しない** —
|   固定するのは「commit 後ずらしの機構を使っていないこと」までである。
|   (D3 と D5(既定値) はリフレクション判定なので中間 interface・親クラス経由まで拾う)
*/

/**
 * 期待ルート集合を**テスト側でリテラルに独立固定**する。
 *
 * ★ これが要である。テスト 5 (対称差) と テスト 6 (ルート単位 0 件 fail) が両方とも
 *   `RUNTIME_ROOTS` を参照していると、**定数から `routes` を消したときに実装列挙と
 *   Finder 列挙が同時に狭まり、どちらも通ってしまう**。
 *
 * @var list<string>
 */
const QUEUE_DEFERRAL_EXPECTED_ROOTS = ['app', 'routes', 'bootstrap', 'database', 'config'];

/** base_path() からの相対パス表示 (失敗メッセージ用)。 */
function queueDeferralRelativePath(string $path): string
{
    $base = base_path().DIRECTORY_SEPARATOR;

    return str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
}

/** 検出結果を「相対パス:行」の list に整形する。 */
function queueDeferralFormatHits(array $hits): array
{
    return array_map(
        static fn (array $hit): string => queueDeferralRelativePath((string) $hit['path']).':'.$hit['line'],
        $hits,
    );
}

/** プロセスごとに一意な fixture ディレクトリを作る (--parallel での衝突回避)。 */
function queueDeferralFixtureRoot(): string
{
    $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'queue-deferral-'.getmypid().'-'.uniqid();
    mkdir($root.DIRECTORY_SEPARATOR.'nested', 0o777, true);

    return $root;
}

/** fixture ディレクトリを再帰削除する。 */
function queueDeferralRemoveFixture(string $root): void
{
    if (! is_dir($root)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $file) {
        $path = (string) $file;
        is_dir($path) ? rmdir($path) : unlink($path);
    }
    rmdir($root);
}

// ------------------------------------------------------------------
// ダミークラス (D3 / D5(既定値) の負のコントロール。リポジトリ内に fixture PHP を置かない)
// ------------------------------------------------------------------

/** D3 の負のコントロール: ShouldQueueAfterCommit を implement する job。 */
final class DeferralProbeAfterCommitJob implements ShouldQueue, ShouldQueueAfterCommit
{
    use Dispatchable;
    use Queueable;
}

/** D3 の負のコントロール: ShouldHandleEventsAfterCommit を implement する listener。 */
final class DeferralProbeAfterCommitListener implements ShouldHandleEventsAfterCommit, ShouldQueue
{
    use Queueable;
}

/** D5 (既定値) の負のコントロール: $afterCommit の既定値が true な job。 */
final class DeferralProbeAfterCommitPropertyJob implements ShouldQueue
{
    use Dispatchable;

    /**
     * ★ `Illuminate\Bus\Queueable` trait は使わない。同 trait が `public $afterCommit;`
     *   (型なし・既定値なし) を持つため、既定値付きで再宣言すると composition が fatal になる
     *   (= PHP の言語仕様上、Queueable を使うクラスではこの迂回路そのものが書けない)。
     *   既定値 true が現実に書けるのは Mailable のように trait を使わないクラスである。
     *
     * @var bool
     */
    public $afterCommit = true;
}

/** D5 (既定値) の偽陰性コントロール: 既定値が null / false のクラスは検出しない。 */
final class DeferralProbeNullAfterCommitJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;
}

/** D5 (既定値) の偽陰性コントロール: 明示 false。 */
final class DeferralProbeFalseAfterCommitJob implements ShouldQueue
{
    use Dispatchable;

    /** @var bool */
    public $afterCommit = false;
}

/** D5 の偽陰性コントロール: falsy な既定値 (0) は vendor でも発動しないので検出しない。 */
final class DeferralProbeZeroAfterCommitJob implements ShouldQueue
{
    use Dispatchable;

    /** @var int */
    public $afterCommit = 0;
}

/** D5 の負のコントロール: promoted parameter は既定値 false でも違反 (呼び出し側が渡せる)。 */
final class DeferralProbePromotedFalseAfterCommitJob implements ShouldQueue
{
    use Dispatchable;

    public function __construct(public bool $afterCommit = false) {}
}

/** D5 (既定値) の負のコントロール: truthy だが `true` ではない既定値 (vendor は真偽値文脈で見る)。 */
final class DeferralProbeTruthyAfterCommitJob implements ShouldQueue
{
    use Dispatchable;

    /** @var int */
    public $afterCommit = 1;
}

/** D5 (既定値) の負のコントロール: constructor promotion で既定値を持たせた形。 */
final class DeferralProbePromotedAfterCommitJob implements ShouldQueue
{
    use Dispatchable;

    public function __construct(public bool $afterCommit = true) {}
}

/** D6 の負のコントロール: event 側の commit 後ずらし。 */
final class DeferralProbeAfterCommitEvent implements ShouldDispatchAfterCommit {}

/** D5 (既定値) の負のコントロール: **ShouldQueue を実装しない** Mailable。 */
final class DeferralProbeMailable extends Mailable
{
    /** @var bool */
    public $afterCommit = true;
}

// ------------------------------------------------------------------
// 0 件 pin
// ------------------------------------------------------------------

test('D1: first-party ランタイム PHP に ->afterCommit() の呼び出しは 1 件も無い', function (): void {
    $hits = array_values(array_filter(
        QueueDispatchDeferralInventory::detectInFiles(QueueDispatchDeferralInventory::runtimePhpFiles()),
        static fn (array $hit): bool => $hit['kind'] === 'D1',
    ));

    expect(queueDeferralFormatHits($hits))->toBe(
        [],
        'キュー投入を commit 後へずらす ->afterCommit() が残っています。業務 tx の内側で'
        .'素直に dispatch してください (AG-114 確定 1)。',
    );
});

test('D2: first-party ランタイム PHP に静的 ::afterCommit() の呼び出しは 1 件も無い (DB:: を含む)', function (): void {
    $hits = array_values(array_filter(
        QueueDispatchDeferralInventory::detectInFiles(QueueDispatchDeferralInventory::runtimePhpFiles()),
        static fn (array $hit): bool => $hit['kind'] === 'D2',
    ));

    expect(queueDeferralFormatHits($hits))->toBe(
        [],
        '静的 ::afterCommit() (DB::afterCommit 等) への退避が残っています。付随的副作用 (AG-127) は'
        .'tx の外へ出し、キュー投入は業務 tx の内側で行ってください。',
    );
});

test('D3: 母集団に ShouldQueueAfterCommit / ShouldHandleEventsAfterCommit を implement するクラスは 1 件も無い', function (): void {
    $hits = QueueDispatchDeferralInventory::detectAfterCommitInterfaces(
        QueueDispatchDeferralInventory::deferralCandidateClasses(),
    );

    expect($hits)->toBe(
        [],
        '宣言的迂回 (grep afterCommit では見えない) が残っています: '.implode(', ', $hits),
    );
});

test('D4: after_commit=true を持ってよい接続は sync だけである', function (): void {
    $connections = config('queue.connections');
    expect($connections)->toBeArray();

    $hits = QueueDispatchDeferralInventory::detectAfterCommitEnabledConnections((array) $connections);

    expect($hits)->toBe([], 'sync 以外の接続で after_commit=true になっています: '.implode(', ', $hits));
});

test('D5: 母集団に commit 後ずらしを発動する $afterCommit を持つクラスは 1 件も無い', function (): void {
    $hits = QueueDispatchDeferralInventory::detectAfterCommitProperty(
        QueueDispatchDeferralInventory::deferralCandidateClasses(),
    );

    expect($hits)->toBe(
        [],
        'commit 後ずらしを発動する $afterCommit (truthy な既定値 / promoted parameter) を持つクラスがあります: '
        .implode(', ', $hits),
    );
});

test('D5: first-party ランタイム PHP に $afterCommit への truthy な単一リテラル代入は 1 件も無い', function (): void {
    $hits = QueueDispatchDeferralInventory::detectAfterCommitAssignments(
        QueueDispatchDeferralInventory::runtimePhpFiles(),
    );

    expect(queueDeferralFormatHits($hits))->toBe([], '$afterCommit への truthy な代入が残っています。');
});

test('D6: app/ の全クラスに ShouldDispatchAfterCommit を implement するものは 1 件も無い', function (): void {
    // ★ event クラス側の commit 後ずらし。Events\Dispatcher::dispatch() がこの interface を見て
    //   **イベント発火ごと** commit 後へ回すため、queued listener がぶら下がっていると
    //   enqueue も commit 後になる (D1〜D5 のどれにも映らない第 4 の迂回路)。
    //   event に marker interface は無いので、母集団は app/ の全クラス (超集合) で見る。
    $hits = QueueDispatchDeferralInventory::detectDispatchAfterCommitEvents(
        QueuedJobPopulation::appClasses(),
    );

    expect($hits)->toBe(
        [],
        'ShouldDispatchAfterCommit を implement するクラスがあります (event 発火ごと commit 後へ'
        .'ずれるため、queued listener の enqueue も commit 後になる): '.implode(', ', $hits),
    );
});

test('負のコントロール: ShouldDispatchAfterCommit 実装ダミークラスを D6 が検出する', function (): void {
    expect(QueueDispatchDeferralInventory::detectDispatchAfterCommitEvents([DeferralProbeAfterCommitEvent::class]))
        ->toBe([DeferralProbeAfterCommitEvent::class]);
});

test('母集団: appClasses() は 0 件でなく、ShouldQueue 実装も Mailable も含む超集合である', function (): void {
    $appClasses = QueuedJobPopulation::appClasses();

    expect(count($appClasses))->toBeGreaterThan(0);
    foreach (QueueDispatchDeferralInventory::deferralCandidateClasses() as $class) {
        expect($appClasses)->toContain($class);
    }
});

// ------------------------------------------------------------------
// 母集団の境界と 0 件 fail
// ------------------------------------------------------------------

test('母集団: runtimePhpFiles() は Finder による独立列挙と対称差が空である', function (): void {
    $finder = Finder::create()
        ->in(array_map(static fn (string $root): string => base_path($root), QUEUE_DEFERRAL_EXPECTED_ROOTS))
        ->files()
        ->name('*.php');

    $expected = [];
    foreach ($finder as $file) {
        $expected[] = (string) $file->getRealPath();
    }
    sort($expected);

    $actual = QueueDispatchDeferralInventory::runtimePhpFiles();

    expect(array_values(array_diff($expected, $actual)))->toBe([], '実装列挙から漏れているファイルがあります');
    expect(array_values(array_diff($actual, $expected)))->toBe([], '実装列挙に余分なファイルがあります');
});

test('母集団: RUNTIME_ROOTS はテスト側で独立に固定した期待ルート集合と一致する', function (): void {
    expect(QueueDispatchDeferralInventory::RUNTIME_ROOTS)
        ->toEqualCanonicalizing(QUEUE_DEFERRAL_EXPECTED_ROOTS);
});

test('母集団: 期待ルート集合の各ルートについて 1 件以上のファイルが列挙される', function (): void {
    // ★ ループはテスト側リテラルを回す (定数を回すと定数を削るだけで空振りする)
    foreach (QUEUE_DEFERRAL_EXPECTED_ROOTS as $root) {
        $files = QueueDispatchDeferralInventory::phpFilesUnder([base_path($root)]);

        expect(count($files))->toBeGreaterThan(0, "ルート {$root} から 1 件も列挙されていません");
    }
});

test('母集団: ShouldQueue 実装クラスの列挙は 0 件でない', function (): void {
    expect(count(QueuedJobPopulation::shouldQueueClasses()))->toBeGreaterThan(0);
});

test('母集団: Mailable subclass の列挙は 0 件でない', function (): void {
    expect(QueuedJobPopulation::mailableClasses())->toContain(InquiryReceivedMail::class);
});

test('母集団: deferralCandidateClasses() は unique(shouldQueueClasses ∪ mailableClasses) と一致し、Mailable 全件を含む', function (): void {
    $expected = array_values(array_unique(array_merge(
        QueuedJobPopulation::shouldQueueClasses(),
        QueuedJobPopulation::mailableClasses(),
    )));
    sort($expected);

    $actual = QueueDispatchDeferralInventory::deferralCandidateClasses();

    expect($actual)->toBe($expected);
    foreach (QueuedJobPopulation::mailableClasses() as $mailable) {
        expect($actual)->toContain($mailable);
    }
    // ShouldQueue 側の代表 (Notification) も含まれている = 和集合が片側に潰れていない
    expect($actual)->toContain(PaymentFailedNotification::class);
});

test('母集団: Mailable が ShouldQueue を併記しているあいだ和集合は degenerate である (trip-wire)', function (): void {
    // ★ **これは「壊れたら直す」テストではなく、被覆の穴を可視化する trip-wire である。**
    //   現状 mailableClasses() ⊆ shouldQueueClasses() のため、deferralCandidateClasses() を
    //   shouldQueueClasses() 片側へ潰す変異は**結果が変わらず検出できない** (mutation #24 の実測)。
    //   この包含が崩れた瞬間 (= Mailable から implements ShouldQueue が外れた瞬間) に、
    //   和集合は実効を持ち D3 / D5 の 0 件 pin が Mailable を独立に見るようになる。
    //   本テストはその転換点を**赤で知らせる**ためのものである。
    $outside = array_values(array_diff(
        QueuedJobPopulation::mailableClasses(),
        QueuedJobPopulation::shouldQueueClasses(),
    ));

    expect($outside)->toBe(
        [],
        'ShouldQueue を実装しない Mailable が現れました: '.implode(', ', $outside).PHP_EOL
        .'これ自体は不具合ではありません。ただしこの時点から deferralCandidateClasses() の'
        .'和集合が実効を持つため、(1) 同関数が今も mergeCandidateClasses() 経由であること、'
        .'(2) D3 / D5 の 0 件 pin が当該 Mailable を含めて緑であること を確認し、'
        .'確認できたら本 trip-wire の期待値をその Mailable を含む形へ更新してください'
        .' (devnotes の mutation-evidence #24 参照)。',
    );
});

test('負のコントロール: mergeCandidateClasses() は disjoint な 2 集合の和を返す (片側に潰れていない)', function (): void {
    // ★ 現状 mailableClasses() ⊆ shouldQueueClasses() のため、deferralCandidateClasses() を
    //   片側へ潰す変異は**実結果が変わらず検出できない**。和集合を取る意図そのものは
    //   ここで disjoint な集合を食わせて固定する (併記が外れて集合が乖離した瞬間に効く)。
    $merged = QueueDispatchDeferralInventory::mergeCandidateClasses(
        [DeferralProbeAfterCommitJob::class],
        [DeferralProbeMailable::class],
    );

    expect($merged)->toContain(DeferralProbeAfterCommitJob::class);
    expect($merged)->toContain(DeferralProbeMailable::class);
    expect($merged)->toHaveCount(2);
});

test('母集団: queue.connections は 0 件でない', function (): void {
    $connections = config('queue.connections');
    expect($connections)->toBeArray();
    expect(count((array) $connections))->toBeGreaterThan(0);
});

// ------------------------------------------------------------------
// 負のコントロール (検出器が生きていることの固定)
// ------------------------------------------------------------------

test('負のコントロール: fixture ツリーを列挙して D1 を検出する', function (): void {
    $root = queueDeferralFixtureRoot();

    try {
        file_put_contents(
            $root.'/nested/Probe.php',
            "<?php\n\nSomeJob::dispatch(1)->afterCommit();\n",
        );

        $hits = QueueDispatchDeferralInventory::detectInFiles(
            QueueDispatchDeferralInventory::phpFilesUnder([$root]),
        );

        expect(array_column($hits, 'kind'))->toContain('D1');
    } finally {
        queueDeferralRemoveFixture($root);
    }
});

test('負のコントロール: fixture ツリーを列挙して D2 を検出する', function (): void {
    $root = queueDeferralFixtureRoot();

    try {
        file_put_contents(
            $root.'/nested/Probe.php',
            "<?php\n\nDB::afterCommit(static fn () => null);\n",
        );

        $hits = QueueDispatchDeferralInventory::detectInFiles(
            QueueDispatchDeferralInventory::phpFilesUnder([$root]),
        );

        expect(array_column($hits, 'kind'))->toContain('D2');
    } finally {
        queueDeferralRemoveFixture($root);
    }
});

test('負のコントロール: ShouldQueueAfterCommit 実装ダミークラスを D3 が検出する', function (): void {
    expect(QueueDispatchDeferralInventory::detectAfterCommitInterfaces([DeferralProbeAfterCommitJob::class]))
        ->toBe([DeferralProbeAfterCommitJob::class]);
});

test('負のコントロール: ShouldHandleEventsAfterCommit 実装ダミークラスを D3 が検出する', function (): void {
    expect(QueueDispatchDeferralInventory::detectAfterCommitInterfaces([DeferralProbeAfterCommitListener::class]))
        ->toBe([DeferralProbeAfterCommitListener::class]);
});

test('負のコントロール: after_commit=true の非 sync 接続を D4 が検出する', function (): void {
    $hits = QueueDispatchDeferralInventory::detectAfterCommitEnabledConnections([
        'sync' => ['driver' => 'sync', 'after_commit' => true],
        'database' => ['driver' => 'database', 'after_commit' => false],
        'database-analysis' => ['driver' => 'database', 'after_commit' => true],
    ]);

    expect($hits)->toBe(['database-analysis']);
});

test('負のコントロール: $afterCommit = true を持つダミー job クラスを D5 (プロパティ) が検出する', function (): void {
    expect(QueueDispatchDeferralInventory::detectAfterCommitProperty([DeferralProbeAfterCommitPropertyJob::class]))
        ->toBe([DeferralProbeAfterCommitPropertyJob::class]);
});

test('負のコントロール: ShouldQueue を実装しないダミー Mailable の $afterCommit = true を D5 (既定値) が検出する', function (): void {
    // Mailable を母集団に含めない実装では、このクラスは検出器へ届かない
    expect(is_subclass_of(DeferralProbeMailable::class, ShouldQueue::class))->toBeFalse();
    expect(QueueDispatchDeferralInventory::detectAfterCommitProperty([DeferralProbeMailable::class]))
        ->toBe([DeferralProbeMailable::class]);
});

test('負のコントロール: $this->afterCommit = true; を含む fixture を D5 (代入) が検出する', function (): void {
    $root = queueDeferralFixtureRoot();

    try {
        file_put_contents(
            $root.'/nested/Probe.php',
            "<?php\n\nfinal class Probe\n{\n    public function __construct()\n    {\n        \$this->afterCommit = true;\n    }\n}\n",
        );

        $hits = QueueDispatchDeferralInventory::detectAfterCommitAssignments(
            QueueDispatchDeferralInventory::phpFilesUnder([$root]),
        );

        expect($hits)->toHaveCount(1);
    } finally {
        queueDeferralRemoveFixture($root);
    }
});

test('負のコントロール: $job->afterCommit = true; (外部からの代入) も D5 (代入) が検出する', function (): void {
    $root = queueDeferralFixtureRoot();

    try {
        file_put_contents(
            $root.'/nested/Probe.php',
            "<?php\n\n\$job = new SomeJob;\n\$job->afterCommit = true;\n",
        );

        $hits = QueueDispatchDeferralInventory::detectAfterCommitAssignments(
            QueueDispatchDeferralInventory::phpFilesUnder([$root]),
        );

        expect($hits)->toHaveCount(1);
    } finally {
        queueDeferralRemoveFixture($root);
    }
});

test('負のコントロール: truthy だが true ではない既定値 (= 1) も D5 (既定値) が検出する', function (): void {
    // vendor の Queue::shouldDispatchAfterCommit() は isset() で拾った値を真偽値文脈で評価するため、
    // `=== true` だけを見る実装ではこの迂回路がすり抜ける
    expect(QueueDispatchDeferralInventory::detectAfterCommitProperty([DeferralProbeTruthyAfterCommitJob::class]))
        ->toBe([DeferralProbeTruthyAfterCommitJob::class]);
});

test('負のコントロール: constructor promotion の $afterCommit は既定値に依らず D5 (プロパティ) が検出する', function (): void {
    // promoted property の既定値は getDefaultProperties() に現れないため、
    // プロパティ宣言だけを見る実装ではすり抜ける。
    // ★ 既定値が false でも違反にする — 呼び出し側が `new Job(afterCommit: true)` で任意に渡せるため
    expect(QueueDispatchDeferralInventory::detectAfterCommitProperty([DeferralProbePromotedAfterCommitJob::class]))
        ->toBe([DeferralProbePromotedAfterCommitJob::class]);
    expect(QueueDispatchDeferralInventory::detectAfterCommitProperty([DeferralProbePromotedFalseAfterCommitJob::class]))
        ->toBe([DeferralProbePromotedFalseAfterCommitJob::class]);
});

test('偽陰性の負のコントロール: $afterCommit の既定値が falsy (null / false / 0) のクラスは D5 が検出しない', function (): void {
    // vendor も真偽値文脈で評価するため、falsy な既定値では commit 後ずらしは発動しない。
    // ここを違反にすると Queueable trait を使う全 job が偽陽性になる
    expect(QueueDispatchDeferralInventory::detectAfterCommitProperty([
        DeferralProbeNullAfterCommitJob::class,
        DeferralProbeFalseAfterCommitJob::class,
        DeferralProbeZeroAfterCommitJob::class,
    ]))->toBe([]);
});

test('負のコントロール: truthy リテラルの代入 (= 1 / = \'yes\') も D5 (代入) が検出する', function (): void {
    // vendor は代入値を真偽値文脈で見るため、`= true` だけを見る実装ではすり抜ける
    $root = queueDeferralFixtureRoot();

    try {
        file_put_contents(
            $root.'/nested/Probe.php',
            "<?php\n\n\$a->afterCommit = 1;\n\$b->afterCommit = 'yes';\n\$c->afterCommit = 2.5;\n",
        );

        $hits = QueueDispatchDeferralInventory::detectAfterCommitAssignments(
            QueueDispatchDeferralInventory::phpFilesUnder([$root]),
        );

        expect($hits)->toHaveCount(3);
    } finally {
        queueDeferralRemoveFixture($root);
    }
});

test('負のコントロール: 基数付き数値リテラル (0x / 0b / 0o / 先頭 0 の 8 進) の非ゼロも D5 (代入) が検出する', function (): void {
    // 素の `(float) '0x1'` は 0.0 になるため、基数を解さない実装ではすり抜ける
    $root = queueDeferralFixtureRoot();

    try {
        file_put_contents(
            $root.'/nested/Probe.php',
            "<?php\n\n\$a->afterCommit = 0x1;\n\$b->afterCommit = 0b1;\n\$c->afterCommit = 0o1;\n"
            ."\$d->afterCommit = 010;\n\$e->afterCommit = 1_000;\n",
        );

        $hits = QueueDispatchDeferralInventory::detectAfterCommitAssignments(
            QueueDispatchDeferralInventory::phpFilesUnder([$root]),
        );

        expect($hits)->toHaveCount(5);
    } finally {
        queueDeferralRemoveFixture($root);
    }
});

test('偽陰性の負のコントロール: 基数付きのゼロ / エスケープ入り文字列は D5 (代入) が検出しない', function (): void {
    // 基数付きゼロは実行時も falsy。エスケープ入り文字列は評価不能へ倒す
    // ("\x30" の実行時値は "0" = falsy であり、ソース表記のまま truthy 判定すると偽陽性になる)
    $root = queueDeferralFixtureRoot();

    try {
        file_put_contents(
            $root.'/nested/Probe.php',
            "<?php\n\n\$a->afterCommit = 0x0;\n\$b->afterCommit = 0b0;\n\$c->afterCommit = 0o0;\n"
            ."\$d->afterCommit = 000;\n\$e->afterCommit = 0e5;\n\$f->afterCommit = \"\\x30\";\n",
        );

        $hits = QueueDispatchDeferralInventory::detectAfterCommitAssignments(
            QueueDispatchDeferralInventory::phpFilesUnder([$root]),
        );

        expect($hits)->toBe([]);
    } finally {
        queueDeferralRemoveFixture($root);
    }
});

test('偽陰性の負のコントロール: falsy リテラル / 評価不能な式の代入は D5 (代入) が検出しない', function (): void {
    // falsy は vendor でも発動しない。変数・式は静的に真偽値を決められないため検出しない
    // (0 件 pin の既知の穴。docblock と docs/architecture.md に明記済み)
    $root = queueDeferralFixtureRoot();

    try {
        file_put_contents(
            $root.'/nested/Probe.php',
            "<?php\n\n\$a->afterCommit = false;\n\$b->afterCommit = null;\n\$c->afterCommit = 0;\n"
            ."\$d->afterCommit = '';\n\$e->afterCommit = '0';\n\$f->afterCommit = \$flag;\n",
        );

        $hits = QueueDispatchDeferralInventory::detectAfterCommitAssignments(
            QueueDispatchDeferralInventory::phpFilesUnder([$root]),
        );

        expect($hits)->toBe([]);
    } finally {
        queueDeferralRemoveFixture($root);
    }
});

test('偽陽性の負のコントロール: コメント / docblock / 文字列リテラル中の ->afterCommit() は検出しない', function (): void {
    $root = queueDeferralFixtureRoot();

    try {
        file_put_contents(
            $root.'/nested/Probe.php',
            "<?php\n\n/**\n * 旧主張: SomeJob::dispatch()->afterCommit() で commit 後に発火する\n */\n"
            ."// DB::afterCommit(fn () => null);\n"
            ."\$sql = 'SomeJob::dispatch()->afterCommit()';\n"
            ."\$assignment = '\$this->afterCommit = true;';\n",
        );

        $paths = QueueDispatchDeferralInventory::phpFilesUnder([$root]);

        expect(QueueDispatchDeferralInventory::detectInFiles($paths))->toBe([]);
        expect(QueueDispatchDeferralInventory::detectAfterCommitAssignments($paths))->toBe([]);
    } finally {
        queueDeferralRemoveFixture($root);
    }
});

// ------------------------------------------------------------------
// 契約の固定 (黙って 0 件を返さない)
// ------------------------------------------------------------------

test('phpFilesUnder(): 相対パスを渡すと例外になる', function (): void {
    expect(fn () => QueueDispatchDeferralInventory::phpFilesUnder(['app']))
        ->toThrow(InvalidArgumentException::class);
});

test('phpFilesUnder(): 存在しないディレクトリを渡すと例外になる (黙って 0 件を返さない)', function (): void {
    expect(fn () => QueueDispatchDeferralInventory::phpFilesUnder([base_path('no-such-directory')]))
        ->toThrow(InvalidArgumentException::class);
});
