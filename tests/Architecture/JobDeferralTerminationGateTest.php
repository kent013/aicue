<?php

declare(strict_types=1);

use App\Jobs\Billing\AutoRechargeTriggerJob;
use App\Jobs\Billing\ExecuteAutoRechargeAttemptJob;
use App\Jobs\Billing\HandleAutoRechargeChargeFailureJob;
use App\Jobs\Billing\ReuseSubscriptionPaymentMethodJob;
use App\Jobs\Billing\SetDefaultPaymentMethodJob;
use App\Jobs\Billing\SyncBillingCustomerDetails;
use App\Jobs\Capture\DeleteTakeObjectsJob;
use App\Jobs\Capture\GenerateTakeThumbnailJob;
use App\Jobs\Manual\DeleteRenderOutputsJob;
use App\Jobs\Manual\RunManualAnalysis;
use App\Jobs\Manual\RunManualRender;
use App\Mail\EmailPromotionMail;
use App\Mail\InquiryAcknowledgementMail;
use App\Mail\InquiryReceivedMail;
use App\Notifications\Account\AccountDeletionRequestedNotification;
use App\Notifications\Auth\AuthMethodChangedNotification;
use App\Notifications\Billing\AutoRechargeActionRequiredNotification;
use App\Notifications\Billing\AutoRechargeDisabledNotification;
use App\Notifications\Billing\AutoRechargeEnabledNotification;
use App\Notifications\Billing\AutoRechargeFailedNotification;
use App\Notifications\Billing\PaymentFailedNotification;
use App\Notifications\Billing\RenewalReminderNotification;
use Tests\Support\Queue\DeferralProbeAliasedHorizon;
use Tests\Support\Queue\DeferralProbeInheritedHorizon;
use Tests\Support\Queue\DeferralProbeInheritedHorizonBase;
use Tests\Support\Queue\DeferralProbeInheritedHorizonZero;
use Tests\Support\Queue\DeferralProbeInheritedTries;
use Tests\Support\Queue\DeferralProbeInteractsOnly;
use Tests\Support\Queue\DeferralProbeMissingContract;
use Tests\Support\Queue\DeferralProbeNestedTraitJob;
use Tests\Support\Queue\DeferralProbeNullableHorizon;
use Tests\Support\Queue\DeferralProbePropertyHorizon;
use Tests\Support\Queue\DeferralProbeShadowedHorizon;
use Tests\Support\Queue\DeferralProbeTimestampHorizon;
use Tests\Support\Queue\DeferralProbeTriesMethod;
use Tests\Support\Queue\DeferralProbeTriesProperty;
use Tests\Support\Queue\DeferralProbeTriesUninitialized;
use Tests\Support\Queue\DeferralProbeTriesViaNestedTrait;
use Tests\Support\Queue\DeferralProbeTriesViaTrait;
use Tests\Support\Queue\DeferralProbeZeroMaxExceptions;
use Tests\Support\Queue\DeferringJobTemplate;
use Tests\Support\Queue\JobDeferralContract;
use Tests\Support\Queue\JobDeferralScanner;
use Tests\Support\QueuedJobPopulation;

/*
|--------------------------------------------------------------------------
| 退避 (release) を正常系に持つジョブの再試行終端 — 標準形 v1 の静的 gate
|--------------------------------------------------------------------------
|
| lctl 裁定 AG-081 の統合形 v1 を機械化する。必須 4 点:
|   1. 退避を正常系に持つジョブは**絶対時刻の期限** (retryUntil) を持ち、試行回数を終端条件にしない
|   2. 期限の基準時刻は **push 時刻**とする (親バッチ作成時刻にしない)
|   3. 未処理例外だけを $maxExceptions で別に数え、本当の失敗は期限を待たずに止める
|   4. 採った終端方式が実際に効いていることを検査で固定する
|
| 本 gate は (1)(2)(3) の**静的**な機械化と、配る雛形の非劣化検査を担う。
| (4) の振る舞い側は tests/Feature/Queue/DeferredRetryHorizonTest.php が持つ。
|
| ■ 構成 (適用対象 0 件でも素通りしない形)
|   (a) 現在の母集団 (キューに載る全クラス) に毎回掛かる**分類の裏取り** … E1-E4
|   (b) 分類が DEFERS になった瞬間に発火する**契約** C1-C5 … E5-E9
|   (c) 配る雛形そのものの非劣化 … E10
|   (d) 検出器が「常に緑を返す装置」でないことの毎回証明 … E11-E16
|
| ■ 保証しないこと (docblock が正本。概念設計 §4-6)
|   退避マーカーの全数検出は主張しない。射程は**目録エントリのクラスファイルと、
|   reflection で解決した祖先クラス・trait のファイル (推移閉包。vendor も含む)** であり、
|   次は検出できず **いずれも台帳へ載せるのは PR レビューの義務**である:
|     - service へ委譲した先で `$job->release()` 相当が呼ばれる形 (メソッド境界を越える伝播)
|     - 動的呼び出し (可変メソッド名・可変クラス名・call_user_func 系)
|     - first-party の自作 job middleware (release() を呼ぶ自作 middleware)
|     - factory / helper が middleware インスタンスを返す形 (使用サイトに現れない)
|     - dispatch サイトでの後付け (`(new Job)->through([new RateLimited(...)])`)。
|       マーカーがジョブクラスではなく投入側のファイルに現れるため走査根の外になる
|   さらに:
|     - `dontRelease()` の判定は**生成式に直接連結された形だけ**を非退避とし、変数経由・
|       条件分岐は追跡しない (保守的に退避ありへ倒す = fail-closed)。正当なコードを赤に
|       しうるが、修正経路 (生成式に直結して書く) が常に存在するので受け入れる
|     - C4 は「return 式が許可された時計起点から始まり、1 以上の加算だけで構成されること」
|       までを見る。地平線の長さが**妥当か**は人間の判断 (1 秒でも通る)
|     - 分類が NO_DEFERRAL のまま**運用で**退避が起きること (ワーカー側の再取得・リース満了) は
|       本 gate の射程外 (queue-lease-timeout-consistency / stuck-job-recovery の担当)
|     - `appDispatchableVendorJobs()` に登録されていない vendor ジョブの退避有無は
|       `vendorQueuedJobInventory()` の人手棚卸しに依存し、機械証明ではない
|
| ■ 契約表の棚卸し
|   退避 middleware 表と加算メソッド表 (tests/Support/Queue/JobDeferralContract.php) は
|   **Laravel / Carbon 更新時の棚卸しが PR レビューの義務**である。
|
*/

/*
|--------------------------------------------------------------------------
| 移植元との差 (docs/template-divergence.md D25)
|--------------------------------------------------------------------------
|
| 本 gate はテンプレート正典 (laravel-claude-template) からの移植だが、母集団と目録の
| **置き場所だけ**を変えている。正典は 2 関数を tests/Pest.php に置き、母集団を
| `appShouldQueueClasses()` + `appDispatchableVendorJobs()` (正典でも空配列) で組むが、
| 本リポジトリは「キューに載るクラスの母集団」を決める実装を
| `Tests\Support\QueuedJobPopulation` **ただ 1 本**へ集約済みであり、
| 正典の形を持ち込むと母集団を数える実装が 2 本になって片方だけ更新される食い違いが復活する。
| よって 2 関数は本ファイル内に置き、母集団は既存の正本から取る
| (先例: JobExecutionDedupInventoryTest が目録関数を自ファイル内に持ち --parallel で緑)。
| 帰結として、上の「保証しないこと」にある `appDispatchableVendorJobs()` /
| `vendorQueuedJobInventory()` は**本リポジトリには存在しない**。読み替えは
| 「app/ の外 (vendor が登録するキュークラス) は母集団に入らない」であり、
| 実効は移植元と同じである (正典の拡張点も空配列を返す)。
|
| **ここでの「ジョブ」はキューの payload に載るもの全般**を指す (Mailable / Notification を含む)。
| どれも同じキューに載り同じ試行回数の勘定を受けるので、退避の有無を問う対象として同格である。
| 帰結として、メールや通知に退避する job middleware を付けると本 gate が赤くなる。
| それは誤検出ではなく設計どおりの動作である。
|
*/

/**
 * 退避終端 gate の母集団 = **キューに載るクラスの全数**。
 *
 * **母集団を数える実装を新しく作らない** (上の D25 の段落が理由の正本)。
 *
 * @return list<class-string>
 */
function jobDeferralTerminationPopulation(): array
{
    return QueuedJobPopulation::shouldQueueClasses();
}

/**
 * 退避 (release) の有無による全数申告台帳 (lctl 裁定 AG-081 標準形 v1)。
 *
 * **allowlist ではない**: 母集団との**完全一致**を要求するので、キューに載るクラスを足したら
 * 必ずここに追記しないと E1 が落ちる。first-party / vendor で分岐を持たない
 * (分岐は allowlist の口になる)。
 *
 * mode:
 *   NO_DEFERRAL … 退避が起きない (失敗は本当の失敗)。走査根に退避マーカーが 0 件であることを
 *                 E4 が毎回裏取りする (申告を信じない)
 *   DEFERS      … 退避が起きうる。C1-C5 を E5-E9 が課す
 *
 * @return list<array{class: class-string, mode: string, reason: string, coveredBy: list<string>}>
 */
function jobDeferralTerminationInventory(): array
{
    $common = '順番待ちのためにキューへ戻す (release) 経路を持たない。退避する job middleware も付けていない。'
        .'失敗は本当の失敗であり回数で終端してよい。';

    return [
        [
            'class' => AutoRechargeTriggerJob::class,
            'mode' => 'NO_DEFERRAL',
            'reason' => $common.'attempt の起票を 1 つのトランザクションで行うだけで、'
                .'起票できない条件 (opt-in 未設定 / pending が既にある) では退避ではなくその場で終了する。',
            'coveredBy' => [],
        ],
        [
            'class' => ExecuteAutoRechargeAttemptJob::class,
            'mode' => 'NO_DEFERRAL',
            'reason' => $common.'attempt の所有権は永続状態遷移で取ってから決済事業者を呼ぶ。'
                .'取れなければ退避ではなくその場で終了し、取りこぼしはリコンサイルが回収する。',
            'coveredBy' => [],
        ],
        [
            'class' => HandleAutoRechargeChargeFailureJob::class,
            'mode' => 'NO_DEFERRAL',
            'reason' => $common.'決済事業者へ請求の現在状態を問い合わせて短い決着トランザクションを書くだけで、'
                .'状態が既に変わっていれば退避ではなくその場で終了する。',
            'coveredBy' => [],
        ],
        [
            'class' => ReuseSubscriptionPaymentMethodJob::class,
            'mode' => 'NO_DEFERRAL',
            'reason' => $common.'支払方法の解決と保存に収束する片方向の同期だけで、順番待ちの概念を持たない。',
            'coveredBy' => [],
        ],
        [
            'class' => SetDefaultPaymentMethodJob::class,
            'mode' => 'NO_DEFERRAL',
            'reason' => $common.'既定の支払方法の設定と控えの更新に収束する片方向の同期だけで、順番待ちの概念を持たない。',
            'coveredBy' => [],
        ],
        [
            'class' => SyncBillingCustomerDetails::class,
            'mode' => 'NO_DEFERRAL',
            'reason' => $common.'組織名を決済事業者の顧客情報へ写す片方向の同期だけを行う。',
            'coveredBy' => [],
        ],
        [
            'class' => DeleteTakeObjectsJob::class,
            'mode' => 'NO_DEFERRAL',
            'reason' => $common.'payload はファイル置き場のキーの一覧だけで、既に無いキーの削除は何もしない。'
                .'再試行しても安全なので回数と待ち時間で終端する。',
            'coveredBy' => [],
        ],
        [
            'class' => GenerateTakeThumbnailJob::class,
            'mode' => 'NO_DEFERRAL',
            'reason' => $common.'一回性は条件付き更新が担い、他のワーカーに先を越されていれば退避ではなく終了する。'
                .'生成に失敗してもテイクは撮影済みのままで、表示は代替画像へ落ちる。',
            'coveredBy' => [],
        ],
        [
            'class' => DeleteRenderOutputsJob::class,
            'mode' => 'NO_DEFERRAL',
            'reason' => $common.'世代交代した出力の削除だけを行い、条件に合わなければ退避ではなく何もしない。'
                .'削除は何度実行しても同じ結果になる。',
            'coveredBy' => [],
        ],
        [
            'class' => RunManualAnalysis::class,
            'mode' => 'NO_DEFERRAL',
            'reason' => $common.'解析の開始は対象行の悲観ロックと状態の検査で実行中へ移す形であり、'
                .'取れなければ退避ではなくその場で終了する。再実行は解析の再要求だけが入口である。',
            'coveredBy' => [],
        ],
        [
            'class' => RunManualRender::class,
            'mode' => 'NO_DEFERRAL',
            'reason' => $common.'書き出しの開始も同じ形 (悲観ロックと状態の検査) で、'
                .'取れなければ退避ではなくその場で終了する。再実行は書き出しの再要求だけが入口である。',
            'coveredBy' => [],
        ],
        [
            'class' => EmailPromotionMail::class,
            'mode' => 'NO_DEFERRAL',
            'reason' => $common.'メールアドレス確認の案内を 1 通送るだけで、他の仕事と順番を争わない。',
            'coveredBy' => [],
        ],
        [
            'class' => InquiryAcknowledgementMail::class,
            'mode' => 'NO_DEFERRAL',
            'reason' => $common.'問い合わせ受付の控えを 1 通送るだけで、他の仕事と順番を争わない。',
            'coveredBy' => [],
        ],
        [
            'class' => InquiryReceivedMail::class,
            'mode' => 'NO_DEFERRAL',
            'reason' => $common.'問い合わせの内容を運営へ 1 通送るだけで、他の仕事と順番を争わない。',
            'coveredBy' => [],
        ],
        [
            'class' => AccountDeletionRequestedNotification::class,
            'mode' => 'NO_DEFERRAL',
            'reason' => $common.'退会の申し出を知らせるだけで、業務の状態を書かない。',
            'coveredBy' => [],
        ],
        [
            'class' => AutoRechargeActionRequiredNotification::class,
            'mode' => 'NO_DEFERRAL',
            'reason' => $common.'追加の手続きが要ることを知らせるだけで、業務の状態を書かない。',
            'coveredBy' => [],
        ],
        [
            'class' => AutoRechargeDisabledNotification::class,
            'mode' => 'NO_DEFERRAL',
            'reason' => $common.'自動購入が止まったことを知らせるだけで、業務の状態を書かない。',
            'coveredBy' => [],
        ],
        [
            'class' => AutoRechargeEnabledNotification::class,
            'mode' => 'NO_DEFERRAL',
            'reason' => $common.'自動購入が使えるようになったことを知らせるだけで、業務の状態を書かない。',
            'coveredBy' => [],
        ],
        [
            'class' => AutoRechargeFailedNotification::class,
            'mode' => 'NO_DEFERRAL',
            'reason' => $common.'自動購入が失敗したことを知らせるだけで、業務の状態を書かない。',
            'coveredBy' => [],
        ],
        [
            'class' => PaymentFailedNotification::class,
            'mode' => 'NO_DEFERRAL',
            'reason' => $common.'支払いが通らなかったことを知らせるだけで、業務の状態を書かない。',
            'coveredBy' => [],
        ],
        [
            'class' => RenewalReminderNotification::class,
            'mode' => 'NO_DEFERRAL',
            'reason' => $common.'契約更新が近いことを知らせるだけで、業務の状態を書かない。',
            'coveredBy' => [],
        ],
        [
            'class' => AuthMethodChangedNotification::class,
            'mode' => 'NO_DEFERRAL',
            'reason' => '認証手段変更のお知らせを 1 通送るだけで、他の仕事と順番を争わない。',
            'coveredBy' => [],
        ],
    ];
}

// ---------------------------------------------------------------------------
// (a) 台帳と分類の裏取り
// ---------------------------------------------------------------------------

test('E1: 台帳と母集団 (キューに載る全クラス) が完全一致する', function (): void {
    $population = jobDeferralTerminationPopulation();
    sort($population);

    $registered = array_map(
        static fn (array $entry): string => $entry['class'],
        jobDeferralTerminationInventory(),
    );
    sort($registered);

    expect(array_values(array_diff($population, $registered)))
        ->toBe([], '台帳 jobDeferralTerminationInventory() に未登録のキュージョブがある');
    expect(array_values(array_diff($registered, $population)))
        ->toBe([], '台帳 jobDeferralTerminationInventory() に stale なエントリがある');
});

test('E2: 母集団が 0 件でない (検出器の故障検出)', function (): void {
    expect(jobDeferralTerminationPopulation())
        ->not->toBeEmpty('キューに載るクラスを 1 件も検出できなかった (検出器の故障を疑うこと)');
});

test('E3: 台帳の mode が値域内で reason が非空である', function (): void {
    foreach (jobDeferralTerminationInventory() as $entry) {
        expect(in_array($entry['mode'], JobDeferralContract::MODES, true))
            ->toBeTrue("mode が値域外: {$entry['class']} => {$entry['mode']}");
        expect(trim($entry['reason']))->not->toBe('', "reason が空: {$entry['class']}");
    }
});

test('E4: NO_DEFERRAL の走査根に退避マーカーが 0 件である (申告の裏取り)', function (): void {
    $offenders = [];

    foreach (jobDeferralTerminationInventory() as $entry) {
        if ($entry['mode'] !== 'NO_DEFERRAL') {
            continue;
        }

        $roots = JobDeferralScanner::scanRootsFor($entry['class']);
        expect($roots)->not->toBeEmpty("走査根を 1 件も解決できなかった: {$entry['class']}");

        foreach (JobDeferralScanner::deferralMarkersIn($roots) as $marker) {
            $offenders[] = $entry['class'].' <- '.$marker['path'].':'.$marker['line'].' ('.$marker['kind'].')';
        }
    }

    expect($offenders)->toBe(
        [],
        'NO_DEFERRAL と申告したジョブの走査根に退避マーカーがある。'
        .'標準形 v1 (retryUntil() + $maxExceptions) を宣言して DEFERS へ移すこと: '
        .implode(', ', $offenders),
    );
});

// ---------------------------------------------------------------------------
// (b) DEFERS 側の契約 C1-C5 / (c) 配る雛形の非劣化
// ---------------------------------------------------------------------------

/**
 * 台帳の DEFERS エントリ。
 *
 * @return list<array{class: class-string, mode: string, reason: string, coveredBy: list<string>}>
 */
function jobDeferralDeferringEntries(): array
{
    return array_values(array_filter(
        jobDeferralTerminationInventory(),
        static fn (array $entry): bool => $entry['mode'] === 'DEFERS',
    ));
}

test('E5: DEFERS は retryUntil() を DateTimeInterface 返しで宣言する (C1)', function (): void {
    foreach (jobDeferralDeferringEntries() as $entry) {
        expect(JobDeferralScanner::horizonDeclarationViolations($entry['class']))
            ->toBe([], "C1 違反: {$entry['class']}");
    }
});

test('E6: DEFERS は $tries / #[Tries] / tries() を宣言しない (C2)', function (): void {
    foreach (jobDeferralDeferringEntries() as $entry) {
        expect(JobDeferralScanner::triesDeclarationViolations($entry['class']))
            ->toBe([], "C2 違反: {$entry['class']}");
    }
});

test('E7: DEFERS は $maxExceptions を 1 以上の int で持つ (C3)', function (): void {
    foreach (jobDeferralDeferringEntries() as $entry) {
        expect(JobDeferralScanner::maxExceptionsViolations($entry['class']))
            ->toBe([], "C3 違反: {$entry['class']}");
    }
});

test('E8: DEFERS の retryUntil() は push 時刻起点の未来である (C4)', function (): void {
    foreach (jobDeferralDeferringEntries() as $entry) {
        expect(JobDeferralScanner::horizonExpressionViolationsFor($entry['class']))
            ->toBe([], "C4 違反: {$entry['class']}");
    }
});

test('E9: DEFERS の coveredBy が実在し当該クラスを使用している (C5)', function (): void {
    // **射程を誇張しない**: これは「そのケースが終端方式を実際に pin しているか」までは
    // 保証しない**追跡情報**である (被覆の証明ではない)。ファイル実在だけだと任意の既存
    // テストを書けば通ってしまうので、**当該クラスの使用サイト**が現れることまでを見る。
    // `use` 宣言だけでは通さない (import して使わないファイルを許すと stale 防止にならない)。
    // 現時点で DEFERS は 0 件であり本テストは空振りする。**雛形に対する C1-C4 の検証は
    // E10 が持つ**ので、本テストは将来 DEFERS が生まれたときのための台帳検査として残す。
    foreach (jobDeferralDeferringEntries() as $entry) {
        expect($entry['coveredBy'])->not->toBeEmpty("coveredBy が空: {$entry['class']}");

        $segments = explode('\\', $entry['class']);
        $short = (string) end($segments);

        foreach ($entry['coveredBy'] as $relative) {
            $path = base_path($relative);
            expect(file_exists($path))->toBeTrue("coveredBy のファイルが実在しない: {$relative}");

            $content = file_get_contents($path);
            expect($content)->toBeString();

            $usesClass = str_contains((string) $content, '\\'.$entry['class'])
                || str_contains((string) $content, $short.'::class')
                || str_contains((string) $content, 'new '.$short);

            expect($usesClass)->toBeTrue(
                "coveredBy が当該クラスを使用していない (use 宣言だけでは通さない): {$relative} / {$entry['class']}",
            );
        }
    }
});

test('E10: 配布雛形が C1-C4 をすべて満たす (配る雛形の非劣化)', function (): void {
    expect(JobDeferralScanner::horizonDeclarationViolations(DeferringJobTemplate::class))
        ->toBe([], '雛形が C1 (retryUntil の宣言形) を満たしていない');
    expect(JobDeferralScanner::triesDeclarationViolations(DeferringJobTemplate::class))
        ->toBe([], '雛形が C2 ($tries 不宣言) を満たしていない');
    expect(JobDeferralScanner::maxExceptionsViolations(DeferringJobTemplate::class))
        ->toBe([], '雛形が C3 ($maxExceptions) を満たしていない');
    expect(JobDeferralScanner::horizonExpressionViolationsFor(DeferringJobTemplate::class))
        ->toBe([], '雛形が C4 (push 時刻起点の未来) を満たしていない');
});

// ---------------------------------------------------------------------------
// (d) 検出器が「常に緑を返す装置」でないことの毎回証明 (マーカー層)
// ---------------------------------------------------------------------------

/**
 * 合成ソース 1 本を走査してマーカー kind の一覧を返す。
 *
 * @return list<string>
 */
function jobDeferralMarkerKinds(string $source): array
{
    return array_map(
        static fn (array $marker): string => $marker['kind'],
        JobDeferralScanner::deferralMarkersIn([['path' => 'synthetic/Probe.php', 'content' => $source]]),
    );
}

test('E11: マーカー検出器の正例 — 7 形すべてを拾う', function (): void {
    // 1. alias import + new
    $aliased = <<<'PHP'
    <?php

    namespace App\Synthetic;

    use Illuminate\Queue\Middleware\RateLimited as QueueRateLimited;

    class Job1
    {
        public function middleware(): array
        {
            return [new QueueRateLimited('mail')];
        }
    }
    PHP;
    expect(jobDeferralMarkerKinds($aliased))->toBe(['middleware-new']);

    // 2. FQCN 直書きの new
    $fqcn = <<<'PHP'
    <?php

    namespace App\Synthetic;

    class Job2
    {
        public function middleware(): array
        {
            return [new \Illuminate\Queue\Middleware\WithoutOverlapping($this->id)];
        }
    }
    PHP;
    expect(jobDeferralMarkerKinds($fqcn))->toBe(['middleware-new']);

    // 3. 変数への代入 (生成式が現れている以上マーカー)
    $assigned = <<<'PHP'
    <?php

    namespace App\Synthetic;

    use Illuminate\Queue\Middleware\RateLimited;

    class Job3
    {
        public function middleware(): array
        {
            $m = new RateLimited('x');

            return [$m];
        }
    }
    PHP;
    expect(jobDeferralMarkerKinds($assigned))->toBe(['middleware-new']);

    // 4. container 解決形の引数にある ::class
    $container = <<<'PHP'
    <?php

    namespace App\Synthetic;

    use Illuminate\Queue\Middleware\RateLimited;

    class Job4
    {
        public function middleware(): array
        {
            return [app(RateLimited::class, ['limiterName' => 'mail'])];
        }
    }
    PHP;
    expect(jobDeferralMarkerKinds($container))->toBe(['middleware-container']);

    // 5. 改行を挟む $this のチェーン
    $chained = <<<'PHP'
    <?php

    namespace App\Synthetic;

    class Job5
    {
        public function handle(): void
        {
            $this
                ->release(60);
        }
    }
    PHP;
    expect(jobDeferralMarkerKinds($chained))->toBe(['self-release']);

    // 6. InteractsWithQueue::$job 経由 (nullsafe)
    $viaJob = <<<'PHP'
    <?php

    namespace App\Synthetic;

    class Job6
    {
        public function handle(): void
        {
            $this->job?->release(60);
        }
    }
    PHP;
    expect(jobDeferralMarkerKinds($viaJob))->toBe(['job-release']);

    // 7. trait が trait を使う実 fixture (走査根の推移閉包を通した検出)
    $kinds = array_map(
        static fn (array $marker): string => $marker['kind'],
        JobDeferralScanner::deferralMarkersIn(
            JobDeferralScanner::scanRootsFor(DeferralProbeNestedTraitJob::class),
        ),
    );
    expect($kinds)->toContain('self-release');
});

test('E12: マーカー検出器の負例 — 6 形を拾わない', function (): void {
    // 1. use 宣言だけ (使用サイトが無い)
    $importOnly = <<<'PHP'
    <?php

    namespace App\Synthetic;

    use Illuminate\Queue\Middleware\RateLimited;
    use Illuminate\Queue\Middleware\WithoutOverlapping;

    class Job1
    {
    }
    PHP;
    expect(jobDeferralMarkerKinds($importOnly))->toBe([]);

    // 2. container 解決文脈でない ::class 参照
    $bareClassConst = <<<'PHP'
    <?php

    namespace App\Synthetic;

    use Illuminate\Queue\Middleware\RateLimited;

    class Job2
    {
        public function diagnostics(): array
        {
            return [RateLimited::class];
        }
    }
    PHP;
    expect(jobDeferralMarkerKinds($bareClassConst))->toBe([]);

    // 3. 生成式に直結した dontRelease()
    $dontRelease = <<<'PHP'
    <?php

    namespace App\Synthetic;

    use Illuminate\Queue\Middleware\RateLimited;

    class Job3
    {
        public function middleware(): array
        {
            return [(new RateLimited('mail'))->dontRelease()];
        }
    }
    PHP;
    expect(jobDeferralMarkerKinds($dontRelease))->toBe([]);

    // 3b. PHP 8.4 の括弧なし形も非退避
    $dontReleaseBare = <<<'PHP'
    <?php

    namespace App\Synthetic;

    use Illuminate\Queue\Middleware\RateLimited;

    class Job3b
    {
        public function middleware(): array
        {
            return [new RateLimited('mail')->dontRelease()];
        }
    }
    PHP;
    expect(jobDeferralMarkerKinds($dontReleaseBare))->toBe([]);

    // 3c. **対比 (fail-closed)**: dontRelease() が wrapper の戻り値に掛かる形は
    //     退避が無効化されていないので**マーカーを残す** (Codex 実装レビュー Round 1 [Warning])。
    $dontReleaseOnWrapper = <<<'PHP'
    <?php

    namespace App\Synthetic;

    use Illuminate\Queue\Middleware\RateLimited;

    class Job3c
    {
        public function middleware(): array
        {
            return [wrap(new RateLimited('mail'))->dontRelease()];
        }
    }
    PHP;
    expect(jobDeferralMarkerKinds($dontReleaseOnWrapper))->toBe(['middleware-new']);

    // 3d. **対比 (allowlist であることの証明)**: 呼び出し構文を列挙する blacklist では
    //     取りこぼす形でも grouping と誤認しないこと (Codex 実装レビュー Round 2 [Warning])。
    //     1 本目は **callable string** (`'wrap'(...)`) で、旧 blacklist 実装が
    //     `T_CONSTANT_ENCAPSED_STRING` を列挙していなかったため実際に素通りしていた形である。
    //     2 本目は **変数呼び出し** (`$wrapper(...)`)。
    $dontReleaseOnExoticWrapper = <<<'PHP'
    <?php

    namespace App\Synthetic;

    use Illuminate\Queue\Middleware\RateLimited;

    class Job3d
    {
        public function viaCallableString(): array
        {
            return ['wrap'(new RateLimited('mail'))->dontRelease()];
        }

        public function viaVariable(): array
        {
            $wrapper = $this->wrapper(...);

            return [$wrapper(new RateLimited('mail'))->dontRelease()];
        }
    }
    PHP;
    expect(jobDeferralMarkerKinds($dontReleaseOnExoticWrapper))->toBe(['middleware-new', 'middleware-new']);

    // 4. 退避しない job middleware
    $nonReleasing = <<<'PHP'
    <?php

    namespace App\Synthetic;

    use Illuminate\Queue\Middleware\FailOnException;
    use Illuminate\Queue\Middleware\Skip;
    use Illuminate\Queue\Middleware\SkipIfBatchCancelled;

    class Job4
    {
        public function middleware(): array
        {
            return [new Skip(true), new SkipIfBatchCancelled, new FailOnException([])];
        }
    }
    PHP;
    expect(jobDeferralMarkerKinds($nonReleasing))->toBe([]);

    // 5. release() の**宣言本体**にある自己退避は「能力の定義」なので数えない。
    //    ただし除外するのは自己退避 2 kind だけであり、宣言本体の middleware 生成は残す。
    $capabilityDefinition = <<<'PHP'
    <?php

    namespace App\Synthetic;

    trait Capability
    {
        public function release($delay = 0)
        {
            return $this->job->release($delay);
        }
    }
    PHP;
    expect(jobDeferralMarkerKinds($capabilityDefinition))->toBe([]);

    $capabilityWithMiddleware = <<<'PHP'
    <?php

    namespace App\Synthetic;

    use Illuminate\Queue\Middleware\RateLimited;

    trait Capability2
    {
        public function release($delay = 0)
        {
            return [new RateLimited('x')];
        }
    }
    PHP;
    expect(jobDeferralMarkerKinds($capabilityWithMiddleware))->toBe(['middleware-new']);

    // 6. 実 fixture 経由 (vendor trait を走査根に含んだうえで 0 件であること)
    $roots = JobDeferralScanner::scanRootsFor(DeferralProbeInteractsOnly::class);
    $paths = array_map(static fn (array $file): string => $file['path'], $roots);
    $hasVendorTrait = false;
    foreach ($paths as $path) {
        if (str_ends_with($path, 'Illuminate/Queue/InteractsWithQueue.php')) {
            $hasVendorTrait = true;
        }
    }
    expect($hasVendorTrait)->toBeTrue(
        'vendor の InteractsWithQueue が走査根に入っていない (検査が空振りする): '.implode(', ', $paths),
    );
    expect(JobDeferralScanner::deferralMarkersIn($roots))->toBe([]);
});

// ---------------------------------------------------------------------------
// (d) 検出器の毎回証明 (C4 / reflection 層)
// ---------------------------------------------------------------------------

/** C4 検出器へ渡す合成ソース (retryUntil() の宣言断片) を組み立てる。 */
function jobDeferralHorizonSource(string $body): string
{
    return "<?php\npublic function retryUntil(): DateTimeInterface\n{\n".$body."\n}\n";
}

/**
 * C4 検出器を合成ソースへ掛ける。
 *
 * @param  array<string, string>  $aliases
 * @return list<string>
 */
function jobDeferralHorizonViolations(
    string $body,
    ?string $selfClass = null,
    ?string $staticClass = null,
    array $aliases = [],
): array {
    return JobDeferralScanner::horizonExpressionViolationsIn(
        jobDeferralHorizonSource($body),
        $selfClass,
        $staticClass,
        $aliases,
        static fn (string $key): mixed => config($key),
    );
}

test('E13: C4 検出器の正例・負例', function (): void {
    // --- 許可形 5 種 ---------------------------------------------------------
    // 1. 整数リテラル
    expect(jobDeferralHorizonViolations('return now()->addMinutes(30);'))->toBe([]);

    // 2. 時計起点 4 種 (now / Carbon::now / CarbonImmutable::now / Date::now)
    expect(jobDeferralHorizonViolations(
        'return Carbon::now()->addMinutes(self::ALIASED_HORIZON_MINUTES);',
        DeferralProbeInheritedHorizonBase::class,
    ))->toBe([]);
    expect(jobDeferralHorizonViolations('return CarbonImmutable::now()->addHours(1);'))->toBe([]);
    expect(jobDeferralHorizonViolations('return Date::now()->addDays(1);'))->toBe([]);

    // 3. config リテラルキー (解決値が int かつ 1 以上)
    expect(config('queue.connections.database.retry_after'))->toBeInt();
    expect(jobDeferralHorizonViolations(
        "return now()->addMinutes(config('queue.connections.database.retry_after'));",
    ))->toBe([]);

    // 4. 加算の連鎖
    expect(jobDeferralHorizonViolations('return now()->addHours(1)->addMinutes(30);'))->toBe([]);

    // 5. 匿名クラスのスコープ除外 (内側の return を数えないこと)
    $anonymous = <<<'PHP'
    return now()->addMinutes(30);
    new class {
        public function x()
        {
            return now()->subHour();
        }
    };
    PHP;
    expect(jobDeferralHorizonViolations($anonymous))->toBe([]);

    // --- 禁止形 8 種 ---------------------------------------------------------
    // 1. 過去 (sub 系)
    expect(jobDeferralHorizonViolations('return now()->subHours(1);'))->not->toBeEmpty();

    // 2. 加算 0 回
    expect(jobDeferralHorizonViolations('return now();'))->not->toBeEmpty();

    // 3. 値が 0
    expect(jobDeferralHorizonViolations('return now()->addSeconds(0);'))->not->toBeEmpty();

    // 4. クラス定数が負 / 0
    expect(jobDeferralHorizonViolations(
        'return now()->addMinutes(self::NEGATIVE_HORIZON_MINUTES);',
        DeferralProbeInheritedHorizonBase::class,
    ))->not->toBeEmpty();
    expect(jobDeferralHorizonViolations(
        'return now()->addMinutes(static::STATIC_HORIZON_MINUTES);',
        DeferralProbeInheritedHorizonBase::class,
        DeferralProbeInheritedHorizonZero::class,
    ))->not->toBeEmpty();

    // 5. config が引けない
    expect(jobDeferralHorizonViolations("return now()->addMinutes(config('missing.key'));"))->not->toBeEmpty();

    // 6. 許可形は現れるが return 式が別 (出現検査では通ってしまう形)
    expect(jobDeferralHorizonViolations('now(); return $this->parentBatchCreatedAt->addHour();'))
        ->not->toBeEmpty();

    // 7. 三項演算子の不許可枝
    expect(jobDeferralHorizonViolations('return $cond ? now()->addHour() : $this->serializedDeadline;'))
        ->not->toBeEmpty();

    // 8. 自スコープの return が 0 件 (空虚に真にならないこと)
    expect(jobDeferralHorizonViolations('throw new LogicException();'))->not->toBeEmpty();
    expect(jobDeferralHorizonViolations(
        '$f = function () { return now()->addHour(); }; throw new LogicException();',
    ))->not->toBeEmpty();
});

test('E14: reflection 検出器の負例 fixture をすべて落とし、継承と行範囲の正例は落とさない', function (): void {
    // C1: retryUntil() の宣言形
    foreach ([
        DeferralProbeMissingContract::class,
        DeferralProbePropertyHorizon::class,
        DeferralProbeTimestampHorizon::class,
        DeferralProbeNullableHorizon::class,
    ] as $class) {
        expect(JobDeferralScanner::horizonDeclarationViolations($class))
            ->not->toBeEmpty("C1 の負例が落ちていない: {$class}");
    }

    // C2: $tries / #[Tries] / tries()
    foreach ([
        DeferralProbeTriesProperty::class,
        DeferralProbeInheritedTries::class,
        DeferralProbeTriesMethod::class,
        DeferralProbeTriesViaTrait::class,
        // 既定値なし typed property (getDefaultProperties では取りこぼしうる形)
        DeferralProbeTriesUninitialized::class,
        // trait が使う trait の #[Tries] (framework は読まないが禁止側は fail-closed で落とす)
        DeferralProbeTriesViaNestedTrait::class,
    ] as $class) {
        expect(JobDeferralScanner::triesDeclarationViolations($class))
            ->not->toBeEmpty("C2 の負例が落ちていない: {$class}");
    }

    // C3: $maxExceptions
    foreach ([
        DeferralProbeMissingContract::class,
        DeferralProbeZeroMaxExceptions::class,
    ] as $class) {
        expect(JobDeferralScanner::maxExceptionsViolations($class))
            ->not->toBeEmpty("C3 の負例が落ちていない: {$class}");
    }

    // C4 正例: 継承 (self:: は宣言クラス / ソースも宣言クラスのファイルから) /
    //          alias 解決 (alias 表はファイル全体から) / 行範囲切り出し (1 ファイル 2 クラス)
    foreach ([
        DeferralProbeInheritedHorizon::class,
        DeferralProbeAliasedHorizon::class,
        DeferralProbeShadowedHorizon::class,
    ] as $class) {
        expect(JobDeferralScanner::horizonExpressionViolationsFor($class))
            ->toBe([], "C4 の正例を落としている (偽レッド): {$class}");
        expect(JobDeferralScanner::horizonDeclarationViolations($class))
            ->toBe([], "C1 の正例を落としている (偽レッド): {$class}");
        expect(JobDeferralScanner::triesDeclarationViolations($class))
            ->toBe([], "C2 の正例を落としている (偽レッド): {$class}");
        expect(JobDeferralScanner::maxExceptionsViolations($class))
            ->toBe([], "C3 の正例を落としている (偽レッド): {$class}");
    }

    // C4 負例: static:: を検査対象クラスから解決していれば 0 分になり違反になる
    expect(JobDeferralScanner::horizonExpressionViolationsFor(DeferralProbeInheritedHorizonZero::class))
        ->not->toBeEmpty('static:: を検査対象クラスから解決していない (親の値を読んでいる)');
});

test('E15: 走査根は推移閉包である (trait が使う trait まで辿る)', function (): void {
    $paths = array_map(
        static fn (array $file): string => $file['path'],
        JobDeferralScanner::scanRootsFor(DeferralProbeNestedTraitJob::class),
    );

    $hasInner = false;
    foreach ($paths as $path) {
        if (str_ends_with($path, 'DeferralProbeInnerReleasingTrait.php')) {
            $hasInner = true;
        }
    }

    expect($hasInner)->toBeTrue('trait が使う trait のファイルが走査根に入っていない: '.implode(', ', $paths));
});

test('E16: namespace 前提が壊れるソースは fail-closed で落とす', function (): void {
    $braced = "<?php\n\nnamespace App\\Synthetic {\n    class Job\n    {\n    }\n}\n";
    expect(jobDeferralMarkerKinds($braced))->toContain('namespace-brace');

    $multiple = "<?php\n\nnamespace A;\n\nclass X\n{\n}\n\nnamespace B;\n\nclass Y\n{\n}\n";
    expect(jobDeferralMarkerKinds($multiple))->toContain('namespace-multiple');
});
