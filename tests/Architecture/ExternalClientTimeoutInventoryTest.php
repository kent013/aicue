<?php

declare(strict_types=1);

use App\Enums\Storage\ExternalClientBoundaryExemption;
use App\Enums\Storage\S3OperationSurface;
use App\Http\Controllers\Webhooks\SesNotificationController;
use App\Http\Middleware\VerifySnsSignature;
use App\Providers\AppServiceProvider;
use App\Providers\ExternalClientTimeoutServiceProvider;
use App\Services\Capture\Fakes\FakeTakeObjectStorage;
use App\Services\Capture\TakeObjectStorage;
use App\Services\Mail\Sns\AwsSnsSignatureVerifier;
use App\Services\Mail\Sns\SnsSignatureVerifier;
use App\Services\Manual\AnalysisMediaValidator;
use App\Services\Manual\SopTextExtractor;
use App\Services\Manual\SourceDocumentService;
use App\Services\Render\Fakes\FakeRenderObjectStorage;
use App\Services\Render\RenderObjectStorage;
use App\Services\Storage\Fakes\FakeObjectStore;
use App\Support\ExternalClientTimeouts;
use Aws\CommandInterface;
use Aws\Exception\AwsException;
use Aws\MockHandler;
use Aws\Retry\ConfigurationProvider as AwsRetryConfigurationProvider;
use Aws\Sns\SnsClient;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Mail\Transport\SesV2Transport;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Stripe\HttpClient\CurlClient;
use Tests\Support\ExternalClientBoundaryScanner;
use Tests\Support\QueueLeaseConfig;
use Tests\Support\ScanScopeKind;
use Tests\Support\Storage\S3SurfaceInventory;
use Webmozart\Assert\Assert;

/*
 * 外部 SDK (Stripe / AWS) の待ち上限を pin する不変条件 (T126)。
 *
 * - **到達境界の目録**: AWS SDK / Flysystem へ到達しうる app/ のクラスは、S3 集約 adapter か
 *   免除 (enum + 30 文字以上の根拠) のどちらかで登録が必須 (deny-by-default)。
 * - **面分類の目録**: adapter の public メソッドは「転送量が有界か / per-command option を
 *   注入できるか」の 2 軸で分類し、対称差ゼロを機械強制する。
 * - **behavioral**: config を読むだけでは「Laravel / vendor が config を素通ししている」ことを
 *   検証できないため、構築済みクライアントの `getCommand()` を見る。
 * - **負のコントロール**: pin 値が SDK 既定値と異なること / per-command 上書きが client 既定を
 *   実際に上書きすること / 走査が空振りしないこと を明示的に固定する。
 *
 * 運用契約は docs/architecture.md §外部 SDK の待ち上限の規約。
 */

/**
 * 到達境界の目録 (deny-by-default)。
 *
 * value: S3 集約 adapter は `['surface' => 'adapter']`、それ以外は免除理由 (enum + 30 文字以上の根拠)。
 *
 * ★`surface: adapter` の意味は「**public method ごとの面分類を要求する本番集約**」に定める。
 *   fake は本番の外部到達を持たないため面を持たず、`exempt` 側で登録する
 *   (adapter に混ぜると検査 5b の対称差が構造的に成立しない)。
 *
 * @var array<class-string, array{surface: 'adapter'}|array{surface: 'exempt', reason: ExternalClientBoundaryExemption, rationale: string}>
 */
const EXTERNAL_CLIENT_BOUNDARY_INVENTORY = [
    TakeObjectStorage::class => ['surface' => 'adapter'],
    RenderObjectStorage::class => ['surface' => 'adapter'],

    FakeTakeObjectStorage::class => [
        'surface' => 'exempt',
        'reason' => ExternalClientBoundaryExemption::TestDoubleWithoutExternalEgress,
        'rationale' => 'client() が例外を投げ実 S3 経路へ落ちない fake。本番の外部到達を持たない',
    ],
    FakeRenderObjectStorage::class => [
        'surface' => 'exempt',
        'reason' => ExternalClientBoundaryExemption::TestDoubleWithoutExternalEgress,
        'rationale' => 'disk() を s3_fake (ローカル disk) に固定する fake。本番の外部到達を持たない',
    ],
    FakeObjectStore::class => [
        'surface' => 'exempt',
        'reason' => ExternalClientBoundaryExemption::TestDoubleWithoutExternalEgress,
        'rationale' => 's3_fake ローカル disk 上の emulation 基盤。AWS SDK をまったく構築しない',
    ],

    AppServiceProvider::class => [
        'surface' => 'exempt',
        'reason' => ExternalClientBoundaryExemption::PinnedControlClientConstruction,
        'rationale' => 'SNS 購読確認クライアントの構築点。制御系 pin を構築引数へ展開しており転送量も有界',
    ],
    ExternalClientTimeoutServiceProvider::class => [
        'surface' => 'exempt',
        'reason' => ExternalClientBoundaryExemption::GlobalSdkTimeoutPin,
        'rationale' => 'Stripe SDK のプロセス大域 timeout を pin する唯一の場所。他に副作用を持たない',
    ],

    SesNotificationController::class => [
        'surface' => 'exempt',
        'reason' => ExternalClientBoundaryExemption::InjectedPinnedControlClient,
        'rationale' => '構築点 (AppServiceProvider) が pin した SnsClient を DI で受け取るだけの消費点',
    ],
    AwsSnsSignatureVerifier::class => [
        'surface' => 'exempt',
        'reason' => ExternalClientBoundaryExemption::AwsValueObjectOnly,
        'rationale' => 'MessageValidator 自身は transport を構築せず送信もしない。証明書取得は SnsCertificateFetcher へ委譲する',
    ],
    SnsSignatureVerifier::class => [
        'surface' => 'exempt',
        'reason' => ExternalClientBoundaryExemption::AwsValueObjectOnly,
        'rationale' => 'Aws\Sns\Message を引数型に取るだけの interface。クライアントを構築も取得もしない',
    ],
    VerifySnsSignature::class => [
        'surface' => 'exempt',
        'reason' => ExternalClientBoundaryExemption::AwsValueObjectOnly,
        'rationale' => '検証済み Aws\Sns\Message を request attribute へ載せるだけ。SDK クライアントを持たない',
    ],

    SopTextExtractor::class => [
        'surface' => 'exempt',
        'reason' => ExternalClientBoundaryExemption::DefaultDiskWithoutAwsClient,
        'rationale' => 'SOP 原稿を既定 disk から読むだけで disk 選択も AWS client 構築も行わない (前提は gate が検査)',
    ],
    SourceDocumentService::class => [
        'surface' => 'exempt',
        'reason' => ExternalClientBoundaryExemption::DefaultDiskWithoutAwsClient,
        'rationale' => 'アップロード原本を既定 disk へ置くだけで disk 選択も AWS client 構築も行わない (前提は gate が検査)',
    ],
    AnalysisMediaValidator::class => [
        'surface' => 'exempt',
        'reason' => ExternalClientBoundaryExemption::DefaultDiskWithoutAwsClient,
        'rationale' => 'OCR 経路の媒体 (画像/PDF) を既定 disk から読むだけで disk 選択も AWS client 構築も行わない (前提は gate が検査)',
    ],
];

/**
 * 免除理由ごとの**適用条件**を走査結果で機械検査するための表 (impl-review Round 1 反映)。
 *
 * ★免除理由の適用条件が「enum の docblock に書いた約束」だけだと、gate は**偽陰性**を作る
 *   (書いてあるが誰も検査しない)。ここで「その理由を名乗るなら検出されてはいけない規則 /
 *   検出されなければならない規則」を宣言し、走査結果と突き合わせる。
 * ★`forbidden` は「この rule の site があったら免除の前提が崩れている」、
 *   `required` は「この rule の site が無かったら免除の前提が崩れている」。
 *
 * @var array<string, array{forbidden: list<string>, required: list<string>}>
 */
const EXTERNAL_CLIENT_EXEMPTION_PRECONDITIONS = [
    // 値オブジェクトだけを扱う: disk も client も取らない (`new Aws\Sns\MessageValidator` は許す
    // — 送信しない検証器であり、これを禁じると「値オブジェクトのみ」の意味が壊れる)
    'aws_value_object_only' => [
        'forbidden' => ['disk_call', 'get_client_call', 'stripe_global_setter'],
        'required' => [],
    ],
    // 構築点: `new` で AWS クライアントを組み立てていること (組み立てていないなら別の理由である)
    'pinned_control_client_construction' => [
        'forbidden' => ['disk_call', 'get_client_call', 'stripe_global_setter'],
        'required' => ['new_external_object'],
    ],
    // 消費点: **自分では構築しない** (構築するなら pin の責任はこちらに移る)
    'injected_pinned_control_client' => [
        'forbidden' => ['disk_call', 'get_client_call', 'stripe_global_setter', 'new_external_object'],
        'required' => [],
    ],
    // 既定 disk のみ: disk を**選ばない** (`disk(...)` を呼んだ時点でこの理由は名乗れない)
    'default_disk_without_aws_client' => [
        'forbidden' => ['disk_call', 'get_client_call', 'new_external_object', 'stripe_global_setter'],
        'required' => [],
    ],
    // Stripe 大域 pin: setter を持つことが存在理由。AWS 側には触れない
    'global_sdk_timeout_pin' => [
        'forbidden' => ['disk_call', 'get_client_call', 'new_external_object'],
        'required' => ['stripe_global_setter'],
    ],
    // fake: 本番の外部到達を持たない = Stripe 大域状態も触らない
    'test_double_without_external_egress' => [
        'forbidden' => ['stripe_global_setter'],
        'required' => [],
    ],
];

/**
 * Stripe のプロセス大域 setter を呼んでよい箇所の期待表
 * (**相対パス × シンボル × site 件数**)。
 *
 * ★「許可ファイルなら何件でもよい」では exact-fit にならないため件数まで固定する。
 * ★provider は `CurlClient::instance()` を呼ばない (意図的に `new CurlClient` を使う。
 *   シングルトンを書き換えると「誰が設定したか」が追えず、テストの復元先も曖昧になるため)。
 * ★`Stripe\ApiRequestor::httpClient()` (getter) は状態を変えないため制限しない。
 *
 * @var array<string, array<string, int>>
 */
const STRIPE_GLOBAL_SETTER_EXPECTATION = [
    'app/Providers/ExternalClientTimeoutServiceProvider.php' => [
        'setHttpClient' => 1,
        'setMaxNetworkRetries' => 1,
    ],
    'tests/Feature/Providers/ExternalClientTimeoutServiceProviderTest.php' => [
        'setHttpClient' => 2,
        'setMaxNetworkRetries' => 2,
    ],
    'tests/Feature/Billing/AutoRechargeStripeCallBudgetTest.php' => [
        'setHttpClient' => 2,
    ],
];

/**
 * app/ 配下の到達境界 site を走査する。
 *
 * @return list<array{path: string, line: int, rule: string, name: string, scopeKind: ScanScopeKind, class: string|null, callable: string|null, diskArgument: 'none'|'static'|'dynamic'|null}>
 */
function externalClientBoundarySites(): array
{
    $root = dirname(__DIR__, 2);
    $sites = [];
    foreach (ExternalClientBoundaryScanner::phpFiles($root.'/app', 'app') as $relative => $source) {
        array_push($sites, ...ExternalClientBoundaryScanner::boundarySites($relative, $source));
    }

    return $sites;
}

/**
 * Stripe 大域 setter の site を app/ と tests/ の両方から走査する。
 *
 * 走査範囲に `tests/` を含めるのは、**無関係なテストが大域状態を書き換えて他テストを汚染する**
 * ことを防ぐためである (`--parallel` はファイル単位でプロセスを分けるが、
 * 同一プロセス内の実行順依存は残る)。
 *
 * @return list<array{path: string, line: int, rule: string, name: string, scopeKind: ScanScopeKind, class: string|null, callable: string|null, diskArgument: 'none'|'static'|'dynamic'|null}>
 */
function externalClientStripeGlobalSites(): array
{
    $root = dirname(__DIR__, 2);
    $sites = [];
    foreach (['app', 'tests'] as $directory) {
        foreach (ExternalClientBoundaryScanner::phpFiles($root.'/'.$directory, $directory) as $relative => $source) {
            array_push($sites, ...ExternalClientBoundaryScanner::stripeGlobalSites($relative, $source));
        }
    }

    return $sites;
}

/** テスト環境で構築できる s3 disk (ダミー資格情報。実 config の http / retries はそのまま持ち込む)。 */
function externalClientS3Adapter(): AwsS3V3Adapter
{
    $disk = Storage::build(array_merge(
        config()->array('filesystems.disks.s3'),
        ['region' => 'us-east-1', 'bucket' => 'gate', 'key' => 'k', 'secret' => 's'],
    ));
    Assert::isInstanceOf($disk, AwsS3V3Adapter::class, 's3 disk が AwsS3V3Adapter として構築されていません');

    return $disk;
}

// ---------------------------------------------------------------------------
// 到達境界の目録
// ---------------------------------------------------------------------------

test('到達境界: AWS / Flysystem へ到達するクラスは目録と対称差ゼロ', function (): void {
    $sites = externalClientBoundarySites();

    // R2〜R5 の実 site は app/ の**名前付きクラス**へ帰属していなければならない
    // (匿名クラスやファイルスコープで境界を跨ぐ抜け道を作らせない)。
    $scopeViolations = [];
    $scanned = [];
    foreach ($sites as $site) {
        if ($site['scopeKind'] !== ScanScopeKind::NamedClass || $site['class'] === null) {
            $scopeViolations[] = ExternalClientBoundaryScanner::describe($site)
                ." — scope={$site['scopeKind']->name} (名前付きクラス本体へ帰属していない)";

            continue;
        }
        $scanned[$site['class']] = true;
    }

    expect($scopeViolations)->toBe([], implode(PHP_EOL, $scopeViolations));

    $scannedClasses = array_keys($scanned);
    sort($scannedClasses);
    $registered = array_keys(EXTERNAL_CLIENT_BOUNDARY_INVENTORY);
    sort($registered);

    $missing = array_values(array_diff($scannedClasses, $registered));
    $stale = array_values(array_diff($registered, $scannedClasses));

    $diagnostics = [];
    foreach ($sites as $site) {
        if ($site['class'] !== null && in_array($site['class'], $missing, true)) {
            $diagnostics[] = ExternalClientBoundaryScanner::describe($site);
        }
    }

    expect($missing)->toBe(
        [],
        '到達境界: 目録へ未登録のクラスが AWS SDK / Flysystem へ到達しています。'
        .'S3 集約 adapter へ寄せるか、ExternalClientBoundaryExemption + 30 文字以上の根拠で登録してください。'
        .PHP_EOL.implode(PHP_EOL, $diagnostics),
    );
    expect($stale)->toBe(
        [],
        '到達境界: 目録に残骸があります (走査で検出されないクラスが登録されたままです)',
    );
});

test('到達境界: 走査母集団が空でない', function (): void {
    // 走査条件を壊して 0 件になったときに green にならないための空振り防止。
    expect(externalClientBoundarySites())->not->toBeEmpty(
        '到達境界: 走査結果が 0 件です (走査条件が壊れている疑い)',
    );
    expect(count(EXTERNAL_CLIENT_BOUNDARY_INVENTORY))->toBeGreaterThan(0);
});

test('到達境界: 免除には 30 文字以上の根拠がある', function (): void {
    $violations = [];
    foreach (EXTERNAL_CLIENT_BOUNDARY_INVENTORY as $class => $entry) {
        if ($entry['surface'] !== 'exempt') {
            continue;
        }
        if (mb_strlen($entry['rationale']) < 30) {
            $violations[] = "{$class}: 免除の根拠が 30 文字未満です ({$entry['rationale']})";
        }
    }

    expect($violations)->toBe([], implode(PHP_EOL, $violations));
});

test('到達境界: 免除理由の適用条件が走査結果と矛盾しない', function (): void {
    // ★免除の適用条件を「enum の docblock に書いた約束」で終わらせない (impl-review Round 1)。
    //   約束を機械検査しないと、条件から外れたコードが免除に隠れて gate が偽陰性になる。
    $sitesByClass = [];
    foreach (externalClientBoundarySites() as $site) {
        if ($site['class'] === null) {
            continue;
        }
        $sitesByClass[$site['class']][] = $site;
    }

    // 前提表そのものの空振り防止: 使う免除理由はすべて前提が宣言されている
    foreach (ExternalClientBoundaryExemption::cases() as $case) {
        expect(EXTERNAL_CLIENT_EXEMPTION_PRECONDITIONS)->toHaveKey($case->value);
    }

    $violations = [];
    $checked = 0;
    foreach (EXTERNAL_CLIENT_BOUNDARY_INVENTORY as $class => $entry) {
        if ($entry['surface'] !== 'exempt') {
            continue;
        }
        $preconditions = EXTERNAL_CLIENT_EXEMPTION_PRECONDITIONS[$entry['reason']->value];
        $rules = array_column($sitesByClass[$class] ?? [], 'rule');
        $checked++;

        foreach ($preconditions['forbidden'] as $rule) {
            foreach ($sitesByClass[$class] ?? [] as $site) {
                if ($site['rule'] === $rule) {
                    $violations[] = "{$class}: 免除理由 {$entry['reason']->value} は [{$rule}] を許さない — "
                        .ExternalClientBoundaryScanner::describe($site);
                }
            }
        }
        foreach ($preconditions['required'] as $rule) {
            if (! in_array($rule, $rules, true)) {
                $violations[] = "{$class}: 免除理由 {$entry['reason']->value} は [{$rule}] を必要とするが検出されない";
            }
        }
    }

    expect($checked)->toBeGreaterThan(0, '免除 entry が 1 件も検査されていません');
    expect($violations)->toBe([], implode(PHP_EOL, $violations));
});

test('到達境界: disk 名は静的に決まる', function (): void {
    // 動的 disk 名 (`->disk($variable)`) は静的に面を決められないため禁止する。
    // 引数なしの `disk()` は集約 accessor (`$this->disk()`) であり disk 名を選ばない。
    $violations = [];
    foreach (externalClientBoundarySites() as $site) {
        if ($site['rule'] !== 'disk_call') {
            continue;
        }
        if ($site['diskArgument'] === 'dynamic') {
            $violations[] = ExternalClientBoundaryScanner::describe($site).' — disk 名が変数です';
        }
    }

    expect($violations)->toBe([], implode(PHP_EOL, $violations));
});

test('到達境界: Stripe の大域 setter はシンボルごとに許可箇所へ限定される', function (): void {
    $sites = externalClientStripeGlobalSites();

    /** @var array<string, array<string, int>> $actual */
    $actual = [];
    /** @var array<string, list<string>> $diagnostics */
    $diagnostics = [];
    foreach ($sites as $site) {
        $actual[$site['path']][$site['name']] = ($actual[$site['path']][$site['name']] ?? 0) + 1;
        $diagnostics[$site['path']][] = ExternalClientBoundaryScanner::describe($site);
    }

    // 行番号は期待値の識別子にしない (整形で動くため)。診断情報としてのみ出す。
    $flatten = static function (array $table): array {
        $flat = [];
        foreach ($table as $path => $symbols) {
            Assert::string($path);
            Assert::isArray($symbols);
            foreach ($symbols as $symbol => $count) {
                $flat["{$path} [{$symbol}]"] = $count;
            }
        }
        ksort($flat);

        return $flat;
    };

    $expectedFlat = $flatten(STRIPE_GLOBAL_SETTER_EXPECTATION);
    $actualFlat = $flatten($actual);

    $lines = [];
    foreach ($diagnostics as $path => $entries) {
        array_push($lines, ...$entries);
    }

    expect($actualFlat)->toBe(
        $expectedFlat,
        'Stripe のプロセス大域 setter (setHttpClient / setMaxNetworkRetries / CurlClient::instance) は'
        .'許可した相対パス × シンボル × 件数と完全一致していなければなりません。'
        .PHP_EOL.implode(PHP_EOL, $lines),
    );
});

test('到達境界: adapter 集合は面分類目録のクラスキーと一致する', function (): void {
    $adapters = [];
    foreach (EXTERNAL_CLIENT_BOUNDARY_INVENTORY as $class => $entry) {
        if ($entry['surface'] === 'adapter') {
            $adapters[] = $class;
        }
    }
    sort($adapters);

    $surfaceClasses = array_keys(S3SurfaceInventory::all());
    sort($surfaceClasses);

    expect($adapters)->toBe(
        $surfaceClasses,
        '到達境界の adapter 集合と面分類目録のクラスキーが割れています (2 目録の意味が結ばれていません)',
    );
});

// ---------------------------------------------------------------------------
// 面分類の目録
// ---------------------------------------------------------------------------

test('面分類: adapter の public メソッドは目録と対称差ゼロ', function (): void {
    foreach (S3SurfaceInventory::all() as $class => $methods) {
        $reflection = new ReflectionClass($class);
        $declared = [];
        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getDeclaringClass()->getName() !== $class || $method->isConstructor()) {
                continue;
            }
            $declared[] = $method->getName();
        }
        sort($declared);

        $registered = array_keys($methods);
        sort($registered);

        expect($declared)->toBe(
            $registered,
            "面分類: {$class} の public メソッドと面分類目録が一致しません "
            .'(tests/Support/Storage/S3SurfaceInventory.php を更新してください)',
        );
        expect($declared)->not->toBeEmpty();
    }
});

test('面分類: 各 entry に 30 文字以上の根拠がある', function (): void {
    $violations = [];
    foreach (S3SurfaceInventory::all() as $class => $methods) {
        foreach ($methods as $method => $entry) {
            if (mb_strlen($entry['rationale']) < 30) {
                $violations[] = "{$class}::{$method}: 根拠が 30 文字未満です ({$entry['rationale']})";
            }
        }
    }

    expect($violations)->toBe([], implode(PHP_EOL, $violations));
});

test('面分類: BoundedControl は 1 つ以上ある', function (): void {
    // 全部 Bulk にすれば通る、を防ぐ空振り防止。
    $bounded = 0;
    foreach (S3SurfaceInventory::all() as $methods) {
        foreach ($methods as $entry) {
            if ($entry['surface'] === S3OperationSurface::BoundedControl) {
                $bounded++;
            }
        }
    }

    expect($bounded)->toBeGreaterThan(0, '面分類: BoundedControl の entry が 1 件もありません');
});

// ---------------------------------------------------------------------------
// pin 値 / 配線
// ---------------------------------------------------------------------------

test('pin 値は SDK 既定値と異なる', function (): void {
    // 負のコントロール: 「pin していないのに green」を構造的に排除する。
    expect(ExternalClientTimeouts::STRIPE_TIMEOUT_SECONDS)
        ->not->toBe(CurlClient::DEFAULT_TIMEOUT, 'Stripe の timeout が SDK 既定 (80s) のままです');
    expect(ExternalClientTimeouts::STRIPE_CONNECT_TIMEOUT_SECONDS)
        ->not->toBe(CurlClient::DEFAULT_CONNECT_TIMEOUT, 'Stripe の connect_timeout が SDK 既定 (30s) のままです');
    expect(ExternalClientTimeouts::AWS_MAX_ATTEMPTS)
        ->not->toBe(AwsRetryConfigurationProvider::DEFAULT_MAX_ATTEMPTS, 'AWS の max_attempts が SDK 既定 (3) のままです');
});

test('AWS config: s3 / ses が http と retries を宣言する', function (): void {
    expect(config()->array('filesystems.disks.s3.http'))->toBe([
        'connect_timeout' => ExternalClientTimeouts::AWS_S3_CONNECT_TIMEOUT_SECONDS,
        'timeout' => ExternalClientTimeouts::AWS_S3_TIMEOUT_SECONDS,
    ]);
    expect(config()->array('filesystems.disks.s3.retries'))->toBe([
        'mode' => 'legacy',
        'max_attempts' => ExternalClientTimeouts::AWS_MAX_ATTEMPTS,
    ]);

    expect(config()->array('services.ses.http'))->toBe([
        'connect_timeout' => ExternalClientTimeouts::AWS_CONTROL_CONNECT_TIMEOUT_SECONDS,
        'timeout' => ExternalClientTimeouts::AWS_CONTROL_TIMEOUT_SECONDS,
    ]);
    expect(config()->array('services.ses.retries'))->toBe([
        'mode' => 'legacy',
        'max_attempts' => ExternalClientTimeouts::AWS_MAX_ATTEMPTS,
    ]);
});

test('AWS config: driver=s3 の disk はすべて http / retries を宣言する', function (): void {
    // ★**「既定 disk が s3 に設定されたら pin を迂回できる」を構造的に潰す** (impl-review Round 1)。
    //   `Storage` facade を既定 disk のまま使うクラス (免除 DefaultDiskWithoutAwsClient) は
    //   `FILESYSTEM_DISK` 次第で s3 driver へ到達しうる。したがって「特定の disk 名」ではなく
    //   **driver=s3 の disk 全件**が pin を宣言していることを要求する。
    //   これで到達先がどの s3 disk であっても待ちは有界になる (無制限にはならない)。
    /** @var array<string, mixed> $disks */
    $disks = config()->array('filesystems.disks');

    $s3Disks = [];
    $violations = [];
    foreach ($disks as $name => $disk) {
        if (! is_array($disk) || ($disk['driver'] ?? null) !== 's3') {
            continue;
        }
        $s3Disks[] = $name;

        if (($disk['http'] ?? null) !== [
            'connect_timeout' => ExternalClientTimeouts::AWS_S3_CONNECT_TIMEOUT_SECONDS,
            'timeout' => ExternalClientTimeouts::AWS_S3_TIMEOUT_SECONDS,
        ]) {
            $violations[] = "filesystems.disks.{$name}: http が ExternalClientTimeouts の pin 値と一致しません";
        }
        if (($disk['retries'] ?? null) !== ['mode' => 'legacy', 'max_attempts' => ExternalClientTimeouts::AWS_MAX_ATTEMPTS]) {
            $violations[] = "filesystems.disks.{$name}: retries が ExternalClientTimeouts の pin 値と一致しません";
        }
    }

    expect($s3Disks)->not->toBeEmpty('driver=s3 の disk が 1 件もありません (走査条件が壊れている疑い)');
    expect($violations)->toBe([], implode(PHP_EOL, $violations));
});

test('AWS behavioral: s3 disk クライアントの @http が pin 値になる', function (): void {
    // config を読むだけでは「Laravel が config を S3Client へ素通ししている」ことを検証できない。
    // getCommand() は送信しない (ネットワークへ一切出ない)。
    $command = externalClientS3Adapter()->getClient()->getCommand('HeadObject', ['Bucket' => 'gate', 'Key' => 'k']);

    // ★SDK が既定で足す他キー (decode_content 等) には触れず、pin した 2 キーだけを固定する。
    expect($command['@http']['connect_timeout'])->toBe(ExternalClientTimeouts::AWS_S3_CONNECT_TIMEOUT_SECONDS);
    expect($command['@http']['timeout'])->toBe(ExternalClientTimeouts::AWS_S3_TIMEOUT_SECONDS);
});

test('AWS behavioral: vendor 契約: MailManager は services.ses を SesV2Client の構築引数へ素通しする', function (): void {
    // ★`new SesV2Client(...)` の直接構築へ fallback しない — それでは「素通しする」という
    //   vendor 契約自体を検証できず、gate が意味を失う。
    config(['mail.default' => 'ses']);
    $transport = Mail::mailer('ses')->getSymfonyTransport();
    Assert::isInstanceOf($transport, SesV2Transport::class, 'ses mailer が SesV2Transport で解決されていません');

    $command = $transport->ses()->getCommand('SendEmail', [
        'Content' => ['Raw' => ['Data' => 'x']],
    ]);

    expect($command['@http']['connect_timeout'])->toBe(ExternalClientTimeouts::AWS_CONTROL_CONNECT_TIMEOUT_SECONDS);
    expect($command['@http']['timeout'])->toBe(ExternalClientTimeouts::AWS_CONTROL_TIMEOUT_SECONDS);
});

test('AWS behavioral: SnsClient singleton の @http が pin 値になる', function (): void {
    $client = app(SnsClient::class);
    $command = $client->getCommand('ConfirmSubscription', [
        'TopicArn' => 'arn:aws:sns:us-east-1:000000000000:gate',
        'Token' => 'token',
    ]);

    expect($command['@http']['connect_timeout'])->toBe(ExternalClientTimeouts::AWS_CONTROL_CONNECT_TIMEOUT_SECONDS);
    expect($command['@http']['timeout'])->toBe(ExternalClientTimeouts::AWS_CONTROL_TIMEOUT_SECONDS);
});

test('AWS behavioral: retries.max_attempts は「初回を含む試行回数」として効く', function (): void {
    // ★語彙の確認 (vendor 実査を CI に固定する): `retries` を array で渡すと
    //   `max_attempts` は **初回を含む試行回数**として解釈される
    //   (per-command の `@retries` = retry 回数 とは意味が違う)。
    //   `getCommand()` には現れない設定なので、MockHandler で**実際の試行回数**を数える。
    //   ネットワークへは一切出ない (handler が差し替わっている)。
    $attempts = 0;
    $handler = new MockHandler;
    for ($i = 0; $i < ExternalClientTimeouts::AWS_MAX_ATTEMPTS + 3; $i++) {
        $handler->append(function (CommandInterface $command) use (&$attempts): AwsException {
            $attempts++;

            return new AwsException('gate', $command, ['connection_error' => true]);
        });
    }

    $client = new SnsClient(array_merge(
        [
            'version' => 'latest',
            'region' => 'us-east-1',
            'credentials' => ['key' => 'k', 'secret' => 's'],
            'handler' => $handler,
        ],
        ExternalClientTimeouts::awsControlClientOptions(),
    ));

    try {
        $client->confirmSubscription(['TopicArn' => 'arn:aws:sns:us-east-1:000000000000:gate', 'Token' => 'token']);
    } catch (AwsException) {
        // 全試行が失敗して例外になるのが期待動作
    }

    expect($attempts)->toBe(
        ExternalClientTimeouts::AWS_MAX_ATTEMPTS,
        'AWS_MAX_ATTEMPTS は初回を含む試行回数として効く必要があります',
    );
});

// ---------------------------------------------------------------------------
// 時間予算の序列
// ---------------------------------------------------------------------------

test('時間予算: 外部予算 + 局所予算 < worker --timeout < retry_after', function (): void {
    $connections = QueueLeaseConfig::databaseConnections();
    expect($connections)->toHaveKey('database');
    $retryAfter = $connections['database'];

    // 外部予算は「Stripe の 1 リクエスト上限 × 呼び出し予算」で定義する (定義の割れ防止)。
    expect(ExternalClientTimeouts::DEFAULT_CONNECTION_EXTERNAL_BUDGET_SECONDS)->toBe(
        ExternalClientTimeouts::STRIPE_TIMEOUT_SECONDS * ExternalClientTimeouts::DEFAULT_CONNECTION_STRIPE_CALL_BUDGET,
    );

    $budget = ExternalClientTimeouts::DEFAULT_CONNECTION_EXTERNAL_BUDGET_SECONDS
        + ExternalClientTimeouts::DEFAULT_CONNECTION_LOCAL_BUDGET_SECONDS;

    // 厳密不等号 (等号で「使い切ったら間に合わない」状態を通さない)。
    expect($budget)->toBeLessThan(ExternalClientTimeouts::DEFAULT_CONNECTION_WORKER_TIMEOUT_SECONDS);
    expect(ExternalClientTimeouts::DEFAULT_CONNECTION_WORKER_TIMEOUT_SECONDS)->toBeLessThan($retryAfter);
});

test('時間予算: mprocs の database worker --timeout が定数と一致する', function (): void {
    // docs / config / ワーカー定義の 3 者が割れないようにする
    // (既存 QueueWorkerLeaseInvariantTest は「retry_after 未満」しか見ていない)。
    $mprocs = file_get_contents(dirname(__DIR__, 2).'/mprocs.yaml');
    Assert::string($mprocs, 'mprocs.yaml を読めません');

    $matched = preg_match(
        '/queue:listen\s+database\s+--tries=1\s+--timeout=(\d+)/',
        $mprocs,
        $matches,
    );

    expect($matched)->toBe(1, 'mprocs.yaml に database 接続の queue:listen 行が見つかりません');
    expect((int) $matches[1])->toBe(
        ExternalClientTimeouts::DEFAULT_CONNECTION_WORKER_TIMEOUT_SECONDS,
        'mprocs.yaml の database worker --timeout が ExternalClientTimeouts の宣言値と一致しません',
    );
});
