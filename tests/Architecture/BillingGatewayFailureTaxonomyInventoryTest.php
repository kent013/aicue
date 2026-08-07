<?php

declare(strict_types=1);

use App\Enums\Billing\GatewayFailureClass;
use App\Enums\Security\GatewayFailureObservationExemption;
use App\Jobs\Billing\HandleAutoRechargeChargeFailureJob;
use App\Jobs\Billing\ReuseSubscriptionPaymentMethodJob;
use App\Jobs\Billing\SetDefaultPaymentMethodJob;
use App\Services\Billing\AutoRechargeService;
use App\Services\Billing\CashierAutoRechargeGateway;
use App\Services\Billing\CashierStripeGateway;
use App\Services\Billing\StripeScheduleGateway;
use App\Support\Billing\GatewayFailureClassifier;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\QueryException;
use Laravel\Cashier\Exceptions\IncompletePayment;
use Stripe\Exception\ApiConnectionException;
use Stripe\Exception\UnknownApiErrorException;
use Tests\Support\Billing\GatewayConsumerPopulation;
use Tests\Support\Billing\GatewayFailureFixtures;
use Tests\Support\Billing\GatewayObservationEntry;
use Tests\Support\Billing\GatewayObservationExemptionEntry;
use Tests\Support\Billing\VendorExceptionPopulation;
use Tests\Support\FakeAutoRechargeGateway;
use Tests\Support\QueuedJobPopulation;
use Webmozart\Assert\Assert;
use Webmozart\Assert\InvalidArgumentException as AssertInvalidArgumentException;

/*
 * 決済 gateway 消費経路の「失敗分類の語彙」を deny-by-default で固定する。
 *
 * ★この gate が保証するもの:
 *   - gateway を注入される app クラスが全件「観測目録」か「免除」に分類されている
 *   - vendor (Stripe / Cashier) の例外クラスが全件、写像表か条件付き規則に属する
 *   - `unknown` が写像表の値に現れない (= unknown は写像の不在専用)
 *   - 条件付き規則のクラスがクラス同一性で 1 件に固定されている
 *   - fake の失敗注入が本物と同じ分類を返す (fixture 経由・実ライブラリ例外)
 *   - **fixture の message に外部生成文字列の目印が確かに入っている**
 *     (negative assertion が空虚に green にならないための前提保証)
 *   - 観測目録のクラスが例外 message をログへ載せない (getMessage() の cap)。
 *     ★これは gateway 観測点だけでなく**クラス全体**に掛かる設計制約である
 *       (対象クラスは gateway 以外の外部由来例外も受けうる。catch 近傍だけに限ると走査が脆い)。
 *       将来正当な必要が出たら rawMessageCap の変更が必ず差分に現れる
 *   - 旧 API (`failOnTerminate` 等) の残存が **本 gate ファイル自身 (= リテラルの正本) を除いて**
 *     0 件 (思考原則 3 の機械化)。★除外しないと**検査コード自身が hit して必ず失敗する**
 *   - **免除の前提が behavioral に固定されている**: `PropagatesToQueueFailure` を宣言した
 *     クラスに `catch` 節が 1 つも無いこと (tokenizer で計数。impl-review Round 1/2 反映)。件数と根拠長だけを
 *     見る gate は、後から catch を足して message をログへ載せても green になる抜け道を残す
 *     (`ThrottleExemptionPremiseTest` と同じ「免除の前提を検査する」作法)
 *
 * ★この gate が保証しないもの:
 *   - catch が「gateway 呼び出しを囲んでいる」こと (メソッド単位までは絞るが、
 *     catch の**中**で呼ばれているかは検査しない。配置の保証は Feature テスト =
 *     AutoRechargeServiceTest / AutoRechargeReconcileTest が
 *     「失敗時に分類が載る / 成功時にキーが null で載る」で担う)
 *   - **AST は使わない**。nikic/php-parser は vendor に存在するが直接依存ではなく
 *     transitive (phpstan / nette 経由) であり、composer の解決次第で消えうるものへ
 *     Architecture テストを依存させない (AGENTS.md 思考原則 1・2)。
 *     Reflection によるメソッド単位の切り出しで足りる
 *   - 期待値と目録を**同時に**消す変更 (宣言的 gate の性質。目的は
 *     「1 箇所の削除では通らない = レビューで必ず 2 箇所の差分が見える」こと)
 *
 * 運用契約: docs/architecture.md §オートリチャージの失敗分類
 */

const BILLING_GATEWAY_TAXONOMY_MUTATION_COVERAGE = [
    'M1' => '写像表から entry を 1 つ削ると vendor 集合一致が赤くなる',
    'M2' => '写像表に実在しないクラスを足すと集合一致が赤くなる',
    'M3' => '写像表の値に Unknown を置くと赤くなる',
    'M4' => 'conditionalClasses を別クラスへ差し替えると赤くなる',
    'M5' => 'fixture の 1 case を独自 RuntimeException にすると分類一致 / 名前空間が赤くなる',
    'M6' => 'spy に fixture 経由でない throw を戻すと赤くなる',
    'M7' => 'AutoRechargeService に $e->getMessage() を戻すと赤くなる',
    'M8' => '観測目録から AutoRechargeService を消すと未分類で赤くなる',
    'M9' => '免除 cap を書き換えると赤くなる',
    'M10' => 'context() の呼び出しを 1 つ削ると出現回数の exact fit が赤くなる',
    'M11' => '免除クラス (伝播すると宣言) に catch を足すと前提検査が赤くなる',
];

/** @return array<class-string, GatewayObservationEntry> */
function billingGatewayObservers(): array
{
    return [
        AutoRechargeService::class => new GatewayObservationEntry(
            // ★メソッド名 => そのメソッド内で期待する context() 呼び出し回数。
            //   ファイル全体の出現回数ではなく**メソッド単位**で検査する
            //   (ファイル総数だとコメント / 別文脈でも数が合えば green になる)。
            catchSites: [
                'terminateInvoiceBestEffort' => 1,  // 所有権喪失後の後始末 (T131 新設)
                'tryTerminateInvoice' => 1,         // 停止側の invoice 終端
                'reconcile' => 2,                   // attempt 隔離 + 取りこぼし起票
            ],
            rawMessageCap: 0,
            rationale: 'gateway 例外を catch して観測へ落とす唯一のクラス。4 箇所すべてが '
                .'GatewayFailureClassifier::context() の 2 キーだけを載せ、例外 message は載せない。'
                .'rawMessageCap=0 は gateway 観測点だけでなく**クラス全体**に掛かる設計制約である '
                .'(本クラスが受ける例外は gateway 以外も外部由来を含みうるため。'
                .'catch の近傍だけに限定すると走査が脆くなる)。'
                .'通知送信失敗を受ける applySetupCompletion / applyReusedPaymentMethod の '
                .'catch は gateway を消費しないため catchSites の対象外。',
        ),
    ];
}

/** @return array<class-string, GatewayObservationExemptionEntry> */
function billingGatewayObservationExemptions(): array
{
    return [
        SetDefaultPaymentMethodJob::class => new GatewayObservationExemptionEntry(
            GatewayFailureObservationExemption::PropagatesToQueueFailure,
            'gateway 例外を catch せず伝播させる。失敗は queue の再試行と failed_jobs に載り、'
            .'そこには vendor 例外の message が残る (本設計の保証範囲は AutoRechargeService の'
            .'構造化ログと report 文言までであり、伝播先の redact は横断基盤の話でスコープ外)。',
        ),
        ReuseSubscriptionPaymentMethodJob::class => new GatewayObservationExemptionEntry(
            GatewayFailureObservationExemption::PropagatesToQueueFailure,
            'gateway 例外 (resolveSubscriptionPaymentMethod) を catch せず伝播させる。'
            .'失敗は queue の再試行と failed_jobs に載り、そこには vendor 例外の message が残る。'
            .'サブスク PM 再利用は失敗しても業務が止まらない補助経路であり、'
            .'観測点をここに増やすと語彙の集約点が割れる。',
        ),
        HandleAutoRechargeChargeFailureJob::class => new GatewayObservationExemptionEntry(
            GatewayFailureObservationExemption::PropagatesToQueueFailure,
            'gateway 例外 (retrieveInvoiceState / terminateInvoice) を catch せず伝播させる。'
            .'失敗は queue の再試行と failed_jobs に載り、そこには vendor 例外の message が残る。'
            .'終端の再試行はキューに委ね、本 Job 内で握り潰さない (fail-closed)。',
        ),
    ];
}

function billingGatewayObservationExemptionCap(): int
{
    return 3; // exact fit
}

/**
 * 非 vendor の明示宣言クラス (期待値の正本。分類器の写像表とは**独立した宣言**)。
 *
 * ★framework 由来に限定しない。`unknown` の運用契約 (出たクラスは必ず写像表へ足す) により、
 *   将来アプリ自身の例外クラスがここへ入りうる。
 *
 * @return list<class-string<Throwable>>
 */
function billingNonVendorExplicitClasses(): array
{
    return [
        QueryException::class,
        LockTimeoutException::class,           // Illuminate\Contracts\Cache\LockTimeoutException (具象クラス)
        AssertInvalidArgumentException::class,
    ];
}

function billingNonVendorExplicitCap(): int
{
    return 3; // exact fit
}

/**
 * `Stripe\Exception\` を**参照してよい** app クラス (集約点の allowlist)。
 *
 * ★import だけでなく完全修飾名・文字列リテラルでの参照も含む (検査 19 が tokenizer で走査する)。
 */
function billingStripeExceptionReferenceAllowlist(): array
{
    return [
        CashierAutoRechargeGateway::class,
        CashierStripeGateway::class,
        GatewayFailureClassifier::class,
        StripeScheduleGateway::class,
    ];
}

/** クラスのソースを読む (Reflection で実ファイルを特定する)。 */
function billingGatewaySourceOf(string $class): string
{
    $path = (new ReflectionClass($class))->getFileName();
    Assert::string($path, "{$class}: ソースファイルを特定できません");
    $source = file_get_contents($path);
    Assert::string($source, "{$class}: ソースを読み込めません");

    return $source;
}

/**
 * ソースが `Stripe\Exception\` を**コード上で**参照しているか。
 *
 * ★PHP 同梱の tokenizer で走査し、コメント / docblock を除外する
 *   (gateway interface の docblock が Stripe 例外型に言及するのは「知っている」ことにならない)。
 * ★名前トークンだけでなく文字列リテラルも対象にする
 *   (`class_exists('Stripe\Exception\X')` のような回避を許さない)。
 */
function billingSourceReferencesStripeException(string $source): bool
{
    foreach (token_get_all($source) as $token) {
        if (! is_array($token)) {
            continue;
        }
        if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
            continue;
        }
        if (str_contains($token[1], 'Stripe\\Exception\\')) {
            return true;
        }
    }

    return false;
}

/**
 * ソース中の `catch` 節の数を tokenizer で数える。
 *
 * ★文字列走査 (`substr_count($source, 'catch (')`) だと
 *   (a) コメント中の `catch (` に反応し (b) `catch(` のような非標準整形を取りこぼす。
 *   `T_CATCH` を数えれば両方とも起きない (impl-review Round 2 の Suggestion 反映)。
 */
function billingSourceCatchCount(string $source): int
{
    $count = 0;
    foreach (token_get_all($source) as $token) {
        if (is_array($token) && $token[0] === T_CATCH) {
            $count++;
        }
    }

    return $count;
}

/** メソッド本体のソースを行範囲で切り出す (AST を使わない割り切り)。 */
function billingGatewayMethodSource(string $class, string $method): string
{
    $reflection = new ReflectionMethod($class, $method);
    $start = $reflection->getStartLine();
    $end = $reflection->getEndLine();
    Assert::integer($start, "{$class}::{$method}: 開始行を特定できません");
    Assert::integer($end, "{$class}::{$method}: 終了行を特定できません");

    $lines = explode("\n", billingGatewaySourceOf($class));

    return implode("\n", array_slice($lines, $start - 1, $end - $start + 1));
}

// ---------------------------------------------------------------------------
// 検査 1〜5: 観測目録 / 免除の deny-by-default
// ---------------------------------------------------------------------------

test('検査 1: gateway を注入される app クラスが全件分類されている (未分類は fail)', function (): void {
    $scanned = GatewayConsumerPopulation::classes();
    $classified = array_merge(
        array_keys(billingGatewayObservers()),
        array_keys(billingGatewayObservationExemptions()),
    );
    sort($classified);

    $missing = array_values(array_diff($scanned, $classified));
    $stale = array_values(array_diff($classified, $scanned));

    expect($missing)->toBe([], '未分類の gateway 消費クラスがある: '.implode(', ', $missing));
    expect($stale)->toBe([], '目録に実在しないクラスが残っている: '.implode(', ', $stale));

    // ★走査の縮み検出 (母集団が空に落ちても green にならない)
    expect($scanned)->toContain(AutoRechargeService::class);
    expect($scanned)->toContain(SetDefaultPaymentMethodJob::class);
});

test('検査 2: 観測目録と免除は排他 (同じクラスが両方に居ない)', function (): void {
    $both = array_intersect(
        array_keys(billingGatewayObservers()),
        array_keys(billingGatewayObservationExemptions()),
    );

    expect(array_values($both))->toBe([]);
});

test('検査 3: 免除件数が cap と一致する (形骸化ガード)', function (): void {
    expect(count(billingGatewayObservationExemptions()))->toBe(
        billingGatewayObservationExemptionCap(),
        '免除件数が宣言と一致しません。増減させたら billingGatewayObservationExemptionCap() も書き換えること',
    );
});

test('検査 4: 目録・免除の根拠が 30 文字以上 (constructor と gate の二重固定)', function (): void {
    foreach (billingGatewayObservers() as $class => $entry) {
        expect(mb_strlen($entry->rationale))->toBeGreaterThanOrEqual(30, "{$class}: 観測目録の根拠が短すぎます");
    }

    foreach (billingGatewayObservationExemptions() as $class => $entry) {
        expect(mb_strlen($entry->rationale))->toBeGreaterThanOrEqual(30, "{$class}: 免除の根拠が短すぎます");
    }
});

test('検査 5: catchSites のキーが実在メソッドで、期待回数が 1 以上', function (): void {
    foreach (billingGatewayObservers() as $class => $entry) {
        $reflection = new ReflectionClass($class);
        foreach ($entry->catchSites as $method => $expected) {
            expect($reflection->hasMethod($method))->toBeTrue("{$class}::{$method} が実在しません");
            expect($expected)->toBeGreaterThanOrEqual(1, "{$class}::{$method}: 期待回数は 1 以上で宣言すること");
        }
    }
});

// ---------------------------------------------------------------------------
// 検査 6〜7: 観測点の形 (message を載せない / 分類器を必ず通す)
// ---------------------------------------------------------------------------

test('検査 6: 観測目録のクラスは例外 message をログへ載せない (getMessage の cap と一致)', function (): void {
    foreach (billingGatewayObservers() as $class => $entry) {
        expect(substr_count(billingGatewaySourceOf($class), 'getMessage()'))->toBe(
            $entry->rawMessageCap,
            "{$class}: getMessage() の出現件数が rawMessageCap と一致しません",
        );
    }
});

test('検査 7a: catchSites の各メソッドが catch を持ち、context() の回数が宣言と一致する', function (): void {
    foreach (billingGatewayObservers() as $class => $entry) {
        foreach ($entry->catchSites as $method => $expected) {
            $source = billingGatewayMethodSource($class, $method);

            expect(str_contains($source, 'catch ('))->toBeTrue("{$class}::{$method}: catch がありません");
            expect(substr_count($source, 'GatewayFailureClassifier::context('))->toBe(
                $expected,
                "{$class}::{$method}: GatewayFailureClassifier::context() の回数が宣言と一致しません",
            );
        }
    }
});

test('検査 7b: ファイル全体の context() 回数が catchSites の総和と一致する', function (): void {
    foreach (billingGatewayObservers() as $class => $entry) {
        expect(substr_count(billingGatewaySourceOf($class), 'GatewayFailureClassifier::context('))->toBe(
            array_sum($entry->catchSites),
            "{$class}: 宣言外のメソッドで context() を呼んでいます (catchSites を更新すること)",
        );
    }
});

// ---------------------------------------------------------------------------
// 検査 8〜13: 分類語彙の全域性 (vendor 走査 gate)
// ---------------------------------------------------------------------------

test('検査 8: 写像表と条件付き規則は排他', function (): void {
    $both = array_intersect(
        array_keys(GatewayFailureClassifier::directMap()),
        GatewayFailureClassifier::conditionalClasses(),
    );

    expect(array_values($both))->toBe([]);
});

test('検査 9: 分類対象の集合が vendor 母集団 + 非 vendor 明示宣言と一致する', function (): void {
    $classified = array_merge(
        array_keys(GatewayFailureClassifier::directMap()),
        GatewayFailureClassifier::conditionalClasses(),
    );
    sort($classified);

    $expected = array_merge(VendorExceptionPopulation::classes(), billingNonVendorExplicitClasses());
    sort($expected);

    $missing = array_values(array_diff($expected, $classified));
    $stale = array_values(array_diff($classified, $expected));

    expect($missing)->toBe(
        [],
        '未分類の例外クラスがある (composer update で vendor の語彙が増えた可能性がある。'
        .'復旧手順は docs/architecture.md §オートリチャージの失敗分類): '.implode(', ', $missing),
    );
    expect($stale)->toBe([], '実在しない / 母集団外のクラスが写像表に残っている: '.implode(', ', $stale));
});

test('検査 10: 条件付き規則のクラスがクラス同一性で固定されている', function (): void {
    expect(GatewayFailureClassifier::conditionalClasses())->toBe([UnknownApiErrorException::class]);
});

test('検査 11: 写像表の値に Unknown が現れない (unknown は写像の不在専用)', function (): void {
    $unknown = array_keys(array_filter(
        GatewayFailureClassifier::directMap(),
        static fn (GatewayFailureClass $case): bool => $case === GatewayFailureClass::Unknown,
    ));

    expect($unknown)->toBe(
        [],
        'unknown は「写像表に一致が無かった」ことの通知であり、表の値として使ってはならない: '
        .implode(', ', $unknown),
    );
});

test('検査 12: 非 vendor 明示宣言の件数が cap と一致する', function (): void {
    expect(count(billingNonVendorExplicitClasses()))->toBe(billingNonVendorExplicitCap());
});

test('検査 13: vendor 母集団の除外宣言がサブディレクトリ集合と一致し、母集団と交差しない', function (): void {
    $stripeDir = base_path('vendor/stripe/stripe-php/lib/Exception');

    // (a) 実サブディレクトリ集合 == 除外宣言のキー集合
    $declared = array_keys(VendorExceptionPopulation::EXCLUDED_STRIPE_SUBNAMESPACES);
    sort($declared);
    expect(VendorExceptionPopulation::subdirectories($stripeDir))->toBe(
        $declared,
        'Stripe SDK がサブ名前空間を増減させました。母集団定義 (EXCLUDED_STRIPE_SUBNAMESPACES) を再検討すること',
    );

    // (b) 除外理由は 30 文字以上
    foreach (VendorExceptionPopulation::EXCLUDED_STRIPE_SUBNAMESPACES as $sub => $reason) {
        expect(mb_strlen($reason))->toBeGreaterThanOrEqual(30, "{$sub}: 除外理由は 30 文字以上で書くこと");
    }

    // (c) 直下母集団の各クラスが除外名前空間に属さない (集合の非交差)
    foreach (VendorExceptionPopulation::stripeClasses() as $class) {
        foreach ($declared as $sub) {
            expect(str_starts_with($class, 'Stripe\\Exception\\'.$sub.'\\'))->toBeFalse(
                "{$class}: 除外宣言した名前空間のクラスが母集団へ混入しています",
            );
        }
    }

    // (d) 走査の縮み検出 (代表クラス)
    expect(VendorExceptionPopulation::stripeClasses())->toContain(ApiConnectionException::class);
    expect(VendorExceptionPopulation::cashierClasses())->toContain(IncompletePayment::class);
});

// ---------------------------------------------------------------------------
// 検査 14〜18: fake / spy の parity
// ---------------------------------------------------------------------------

test('検査 14: fixture の case 集合が業務 4 case (cases() - Unknown) と一致する', function (): void {
    $expected = array_values(array_filter(
        GatewayFailureClass::cases(),
        static fn (GatewayFailureClass $case): bool => $case !== GatewayFailureClass::Unknown,
    ));

    expect(GatewayFailureFixtures::parityCases())->toBe($expected);
    expect(GatewayFailureFixtures::parityCases())->toHaveCount(count(GatewayFailureClass::cases()) - 1);
});

test('検査 15: fixture が返す例外の分類が宣言 case と一致する (fake/real parity)', function (): void {
    foreach (GatewayFailureFixtures::parityCases() as $case) {
        $throwable = GatewayFailureFixtures::throwableFor($case);

        expect(GatewayFailureClassifier::classify($throwable))->toBe(
            $case,
            "{$case->value}: fixture の例外が宣言と違う分類になります (".$throwable::class.')',
        );
    }
});

test('検査 16: fixture が返すクラスが実ライブラリ名前空間に属する', function (): void {
    foreach (GatewayFailureFixtures::parityCases() as $case) {
        $class = GatewayFailureFixtures::throwableFor($case)::class;

        $allowed = false;
        foreach (GatewayFailureFixtures::ALLOWED_NAMESPACE_PREFIXES as $prefix) {
            if (str_starts_with($class, $prefix)) {
                $allowed = true;

                break;
            }
        }

        expect($allowed)->toBeTrue(
            "{$case->value}: fixture が実ライブラリ以外のクラス ({$class}) を返しています。"
            .'独自例外を投げると本物との分類 parity が崩れる',
        );
    }
});

test('検査 17: spy の throw がすべて fixture 経由である', function (): void {
    $source = billingGatewaySourceOf(FakeAutoRechargeGateway::class);

    expect(substr_count($source, 'throw GatewayFailureFixtures::throwableFor('))->toBe(
        substr_count($source, 'throw '),
        'spy が fixture を経由しない throw を持っています (本物との分類 parity が崩れる)',
    );
});

test('検査 17b: 全 fixture の message に外部生成文字列の目印が含まれる', function (): void {
    // ★negative assertion (「ログにマーカーが含まれない」) が空虚に green にならないための前提保証。
    foreach (GatewayFailureFixtures::parityCases() as $case) {
        $message = GatewayFailureFixtures::throwableFor($case)->getMessage();

        expect(str_contains($message, GatewayFailureFixtures::EXTERNAL_MESSAGE_MARKER))->toBeTrue(
            "{$case->value}: fixture の message にマーカーが入っていません",
        );
    }
});

test('検査 17c: 旧 API 名が tests/ 配下に残っていない (後方互換の並走を残さない)', function (): void {
    // ★除外は文字列一致ではなく realpath で正規化して比較する (自己検出の回避)。
    $self = realpath(__FILE__);
    Assert::string($self, '自ファイルの realpath を解決できません');

    $legacyNames = ['failOnTerminate', 'failOnResolveSubscriptionPaymentMethod'];
    $violations = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(base_path('tests'), FilesystemIterator::SKIP_DOTS),
    );
    foreach ($iterator as $file) {
        Assert::isInstanceOf($file, SplFileInfo::class);
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $path = realpath($file->getPathname());
        Assert::string($path, 'テストファイルの realpath を解決できません');
        if ($path === $self) {
            continue; // 本ファイルはリテラルの正本
        }

        $source = file_get_contents($path);
        Assert::string($source, "ソースを読み込めません: {$path}");

        foreach ($legacyNames as $name) {
            if (str_contains($source, $name)) {
                $violations[] = $path.' => '.$name;
            }
        }
    }

    sort($violations);

    expect($violations)->toBe([], '旧 API 名が残っています: '.implode(', ', $violations));
});

test('検査 18: runtime fake は例外を 1 つも投げない', function (): void {
    $source = billingGatewaySourceOf(App\Services\Billing\Fakes\FakeAutoRechargeGateway::class);

    expect(substr_count($source, 'throw '))->toBe(
        0,
        'runtime fake (fake_externals 環境) は例外を投げない契約である',
    );
});

test('検査 21: 免除クラスは宣言どおり例外を伝播させる (catch を持たない)', function (): void {
    // ★件数と根拠長だけを見る gate は、後から catch (Throwable) を足して getMessage() を
    //   ログへ載せても green のままになる。**免除の前提そのもの**を機械で固定する
    //   (AGENTS.md の ThrottleExemptionPremiseTest と同じ作法。impl-review Round 1 反映)。
    // ★保守的な近似である: gateway 呼び出しを囲む catch かどうかは判定せず、
    //   クラス全体で `catch` 節が 0 件であることを要求する (tokenizer で計数)。catch を足したくなったら
    //   観測目録へ移すか、新しい免除 case を根拠付きで足すこと (どちらも差分に必ず現れる)。
    foreach (billingGatewayObservationExemptions() as $class => $entry) {
        if ($entry->exemption !== GatewayFailureObservationExemption::PropagatesToQueueFailure) {
            continue;
        }

        expect(billingSourceCatchCount(billingGatewaySourceOf($class)))->toBe(
            0,
            "{$class}: 「伝播させる」と免除宣言しているのに catch があります。"
            .'観測目録へ移すか、免除の分類を見直すこと',
        );
    }
});

// ---------------------------------------------------------------------------
// 検査 19〜20: 集約点と mutation coverage
// ---------------------------------------------------------------------------

test('検査 19: Stripe 例外型を参照する app クラスが allowlist と一致する', function (): void {
    // ★`use` 文だけを見ると、完全修飾名 (`\Stripe\Exception\InvalidRequestException::class`) や
    //   文字列リテラルでの参照が allowlist を回避できる (impl-review Round 1 反映)。
    //   PHP 同梱の `token_get_all()` (tokenizer。vendor 依存を増やさない) で
    //   **コメント / docblock を除いた**トークンだけを走査する。
    $found = [];
    foreach (QueuedJobPopulation::appPhpFiles() as $path) {
        $source = file_get_contents($path);
        Assert::string($source, "ソースを読み込めません: {$path}");

        if (! billingSourceReferencesStripeException($source)) {
            continue;
        }

        $found[] = QueuedJobPopulation::classNameForPath($path);
    }
    sort($found);

    $allowlist = billingStripeExceptionReferenceAllowlist();
    sort($allowlist);

    expect($found)->toBe(
        $allowlist,
        'Stripe 例外型を知るクラスは gateway 実装 + GatewayFailureClassifier に閉じる '
        .'(集約点が増えると観測語彙が割れる)',
    );
});

test('検査 20: mutation coverage 表のキー集合が想定 ID 集合と一致する', function (): void {
    expect(array_keys(BILLING_GATEWAY_TAXONOMY_MUTATION_COVERAGE))
        ->toBe(['M1', 'M2', 'M3', 'M4', 'M5', 'M6', 'M7', 'M8', 'M9', 'M10', 'M11']);

    foreach (BILLING_GATEWAY_TAXONOMY_MUTATION_COVERAGE as $id => $description) {
        expect(mb_strlen($description))->toBeGreaterThanOrEqual(10, "{$id}: mutation の説明が短すぎます");
    }
});
