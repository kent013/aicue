<?php

declare(strict_types=1);

use App\Jobs\Billing\AutoRechargeTriggerJob;
use App\Jobs\Billing\ExecuteAutoRechargeAttemptJob;
use App\Jobs\Billing\HandleAutoRechargeChargeFailureJob;
use App\Jobs\Billing\ReuseSubscriptionPaymentMethodJob;
use App\Jobs\Billing\SetDefaultPaymentMethodJob;
use App\Jobs\Billing\SyncBillingCustomerDetails;
use App\Jobs\Capture\DeleteTakeObjectsJob;
use App\Jobs\Manual\DeleteRenderOutputsJob;
use App\Jobs\Manual\RunManualAnalysis;
use App\Jobs\Manual\RunManualRender;
use App\Mail\InquiryAcknowledgementMail;
use App\Mail\InquiryReceivedMail;
use App\Notifications\Billing\AutoRechargeActionRequiredNotification;
use App\Notifications\Billing\AutoRechargeDisabledNotification;
use App\Notifications\Billing\AutoRechargeEnabledNotification;
use App\Notifications\Billing\AutoRechargeFailedNotification;
use App\Notifications\Billing\PaymentFailedNotification;
use App\Notifications\Billing\RenewalReminderNotification;
use Tests\Support\QueuedJobPopulation;
use Tests\Support\QueueLeaseConfig;
use Webmozart\Assert\Assert;

/*
 * 規則 2 + 接続経路の網羅を deny-by-default で固定する。
 *
 * - **規則 2**: その接続で動くジョブの明示的な `$timeout` が、その接続の `retry_after` を下回る。
 * - **接続経路**: キューに載る (ShouldQueue を実装する) クラスは全数を目録に登録し、
 *   接続の指定は `$this->onConnection('リテラル')` に限る。動的に決まる接続は
 *   静的に retry_after と比較できず、規則 2 の検査そのものが空洞化するため。
 *
 * ★ `Queue::connection(...)` は検出対象に入れない。`connection` は汎用名で Eloquent の
 *   `->connection()` 等と衝突して偽陽性が大量に出る。ジョブの接続を実際に差し替えられる
 *   経路は `onConnection` / `viaConnections` / `$connection` プロパティの 3 つであり、
 *   `Queue::connection()->push()` は「どのキューへ push するか」であってジョブクラスの
 *   契約ではない (かつ本アプリに 1 件も無い)。
 *
 * 運用契約: docs/architecture.md §キューのリース期間とワーカー制限時間の規約
 */

/**
 * キューに載る全クラス (ShouldQueue 実装) の接続目録。
 *
 * value = `$this->onConnection('...')` で pin した接続名 / null = 既定接続。
 *
 * ★ deny-by-default: app/ の走査結果とこの目録の**対称差が空**であること。
 *   新しい Job / Mailable / Notification を足したら必ずここに登録する。
 * ★ null (既定接続) の entry は `$timeout` の宣言を禁止する
 *   (既定接続は QUEUE_CONNECTION 次第でどの接続にも化けるため、静的に retry_after と
 *    比較できない。`$timeout` が要るなら `onConnection()` で接続を pin する)。
 *
 * @var array<class-string, string|null>
 */
const QUEUED_JOB_LEASE_INVENTORY = [
    AutoRechargeTriggerJob::class => null,
    ExecuteAutoRechargeAttemptJob::class => null,
    HandleAutoRechargeChargeFailureJob::class => null,
    ReuseSubscriptionPaymentMethodJob::class => null,
    SetDefaultPaymentMethodJob::class => null,
    SyncBillingCustomerDetails::class => null,
    DeleteTakeObjectsJob::class => 'database-media',
    DeleteRenderOutputsJob::class => 'database-media',
    RunManualAnalysis::class => 'database-analysis',
    RunManualRender::class => 'database-render',
    InquiryAcknowledgementMail::class => null,
    InquiryReceivedMail::class => null,
    AutoRechargeActionRequiredNotification::class => null,
    AutoRechargeDisabledNotification::class => null,
    AutoRechargeEnabledNotification::class => null,
    AutoRechargeFailedNotification::class => null,
    PaymentFailedNotification::class => null,
    RenewalReminderNotification::class => null,
];

/**
 * app/ 配下の ShouldQueue 実装クラスを列挙する (純関数)。
 *
 * ★母集団の走査実装は `Tests\Support\QueuedJobPopulation` に **1 本化**されている
 *   (T131)。JobExecutionDedupInventoryTest と同じ母集団を見ることを構造的に保証し、
 *   片方だけ更新される drift を根で断つため。判定の正本は
 *   `ReflectionClass::implementsInterface(ShouldQueue::class)` + `isInstantiable()` で不変。
 *
 * @return list<class-string>
 */
function jobLeaseShouldQueueClasses(): array
{
    return QueuedJobPopulation::shouldQueueClasses();
}

/**
 * app/ 配下の PHP ファイル絶対パス一覧 (純関数)。
 *
 * @return list<string>
 */
function jobLeaseAppPhpFiles(): array
{
    return QueuedJobPopulation::appPhpFiles();
}

/** app/ 配下のパスを PSR-4 でクラス名へ変換する (純関数)。 */
function jobLeaseClassNameForPath(string $path): string
{
    return QueuedJobPopulation::classNameForPath($path);
}

/**
 * PHP ソースをトークン解析し、接続 / timeout の決定に関わる site をすべて列挙する (純関数)。
 *
 * 検出対象:
 *   - `->onConnection(...)` / `?->onConnection(...)` / `::onConnection(...)`
 *   - `->viaConnections(...)` / `->viaConnection(...)`
 *   - **クラス直下**の `$connection` / `$timeout` プロパティ宣言 (デフォルト値の有無つき)
 *   - `$this->connection = ...` / `$this->timeout = ...` 代入
 *
 * `statementStart` は「メソッド本体の**直下**に置かれた実行文か」= 次の 3 条件をすべて満たすこと:
 *   - `$this->` 呼び出しである (receiver が `$this`)
 *   - 直前のトークンが文の境界 (`{` / `}` / `;`) である
 *     (= `$pin = fn () => $this->onConnection(...)` のような**評価されない**式や、
 *        代入式の一部ではない)
 *   - 括弧の外 (`parenDepth === 0`) かつ「クラス本体 + 1」の波括弧深さ
 *     (= `if (...) { ... }` などの条件分岐の内側ではない)
 * これにより「その呼び出しが**必ず実行される**か」を静的に読める形へ限定する。
 *
 * @return list<array{
 *     class: string|null,
 *     kind: 'onConnection'|'viaConnections'|'viaConnection'|'connectionProperty'|'connectionAssignment'|'timeoutProperty'|'timeoutAssignment',
 *     receiverIsThis: bool,
 *     statementStart: bool,
 *     literal: string|null,
 *     hasDefault: bool,
 *     line: int,
 * }>
 */
function jobLeaseConnectionDeclarationSites(string $phpSource): array
{
    $tokens = jobLeaseNormalizedTokens($phpSource);
    $count = count($tokens);

    $namespace = '';
    $braceDepth = 0;
    $parenDepth = 0;
    /** @var list<array{class: string|null, bodyDepth: int}> $scopes */
    $scopes = [];
    /** @var array{class: string|null}|null $pendingScope */
    $pendingScope = null;
    $sites = [];

    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];
        $id = $token['id'];
        $text = $token['text'];

        // namespace 宣言
        if ($id === T_NAMESPACE) {
            $next = $tokens[$i + 1] ?? null;
            if ($next !== null && ($next['id'] === T_NAME_QUALIFIED || $next['id'] === T_STRING)) {
                $namespace = $next['text'];
            }

            continue;
        }

        // クラス様宣言 (class / trait / interface / enum)。次に現れる `{` で scope を push する
        if ($id === T_CLASS || $id === T_TRAIT || $id === T_INTERFACE || $id === T_ENUM) {
            $previous = $tokens[$i - 1] ?? null;
            if ($previous !== null && $previous['id'] === T_DOUBLE_COLON) {
                continue; // `Foo::class`
            }

            $next = $tokens[$i + 1] ?? null;
            $isNamedClass = $id === T_CLASS && $next !== null && $next['id'] === T_STRING;
            // 匿名クラス / trait / interface / enum は class = null (内部の site を
            // 外側のクラスへ誤帰属させない)
            $pendingScope = [
                'class' => $isNamedClass && $next !== null
                    ? ($namespace === '' ? $next['text'] : $namespace.'\\'.$next['text'])
                    : null,
            ];

            continue;
        }

        // 深さ追跡。文字列補間の `{$…}` / `${…}` も対応する `}` を持つため開きとして数える
        if ($text === '{' || $id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) {
            $braceDepth++;
            if ($pendingScope !== null && $text === '{' && $parenDepth === 0) {
                $scopes[] = ['class' => $pendingScope['class'], 'bodyDepth' => $braceDepth];
                $pendingScope = null;
            }

            continue;
        }

        if ($text === '}') {
            // ★ pop の順序を固定する: 先に braceDepth-- するとメソッド終端の `}` で誤って pop する
            $top = $scopes === [] ? null : $scopes[count($scopes) - 1];
            if ($top !== null && $top['bodyDepth'] === $braceDepth) {
                array_pop($scopes);
            }
            $braceDepth--;

            continue;
        }

        if ($text === '(') {
            $parenDepth++;

            continue;
        }

        if ($text === ')') {
            $parenDepth--;

            continue;
        }

        $currentClass = $scopes === [] ? null : $scopes[count($scopes) - 1]['class'];
        $currentBodyDepth = $scopes === [] ? null : $scopes[count($scopes) - 1]['bodyDepth'];

        // メソッド呼び出し (onConnection / viaConnections / viaConnection)
        if ($id === T_OBJECT_OPERATOR || $id === T_NULLSAFE_OBJECT_OPERATOR || $id === T_DOUBLE_COLON) {
            $name = $tokens[$i + 1] ?? null;
            if ($name === null || $name['id'] !== T_STRING) {
                continue;
            }

            $previous = $tokens[$i - 1] ?? null;
            $receiverIsThis = $id === T_OBJECT_OPERATOR
                && $previous !== null
                && $previous['id'] === T_VARIABLE
                && $previous['text'] === '$this';

            if (in_array($name['text'], ['onConnection', 'viaConnections', 'viaConnection'], true)) {
                // 「必ず実行される文」か: 直前が文の境界 + 括弧の外 + メソッド本体の直下
                $boundary = $tokens[$i - 2] ?? null;
                $statementStart = $receiverIsThis
                    && $parenDepth === 0
                    && $currentBodyDepth !== null
                    && $braceDepth === $currentBodyDepth + 1
                    && $boundary !== null
                    && in_array($boundary['text'], ['{', '}', ';'], true);

                $sites[] = [
                    'class' => $currentClass,
                    'kind' => $name['text'] === 'onConnection' ? 'onConnection' : $name['text'],
                    'receiverIsThis' => $receiverIsThis,
                    'statementStart' => $statementStart,
                    'literal' => jobLeaseSingleStringArgument($tokens, $i + 1),
                    'hasDefault' => false,
                    'line' => $name['line'],
                ];

                continue;
            }

            // $this->connection = / $this->timeout = 代入
            if (in_array($name['text'], ['connection', 'timeout'], true) && $receiverIsThis) {
                $assign = $tokens[$i + 2] ?? null;
                if ($assign !== null && $assign['text'] === '=') {
                    $sites[] = [
                        'class' => $currentClass,
                        'kind' => $name['text'] === 'connection' ? 'connectionAssignment' : 'timeoutAssignment',
                        'receiverIsThis' => true,
                        'statementStart' => false,
                        'literal' => null,
                        'hasDefault' => false,
                        'line' => $name['line'],
                    ];
                }
            }

            continue;
        }

        // クラス直下のプロパティ宣言
        if ($id === T_VARIABLE && in_array($text, ['$connection', '$timeout'], true)) {
            if ($currentBodyDepth !== $braceDepth || $parenDepth !== 0) {
                continue; // メソッド本体のローカル変数 / 引数
            }

            $next = $tokens[$i + 1] ?? null;
            $sites[] = [
                'class' => $currentClass,
                'kind' => $text === '$connection' ? 'connectionProperty' : 'timeoutProperty',
                'receiverIsThis' => false,
                'statementStart' => false,
                'literal' => null,
                'hasDefault' => $next !== null && $next['text'] === '=',
                'line' => $token['line'],
            ];
        }
    }

    return $sites;
}

/**
 * `token_get_all()` を「空白・コメントを除いた添字連番のリスト」へ正規化する (純関数)。
 *
 * @return list<array{id: int|null, text: string, line: int}>
 */
function jobLeaseNormalizedTokens(string $phpSource): array
{
    $normalized = [];
    foreach (token_get_all($phpSource) as $token) {
        if (is_array($token)) {
            if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $normalized[] = ['id' => $token[0], 'text' => $token[1], 'line' => $token[2]];

            continue;
        }

        $line = $normalized === [] ? 0 : $normalized[count($normalized) - 1]['line'];
        $normalized[] = ['id' => null, 'text' => $token, 'line' => $line];
    }

    return $normalized;
}

/**
 * メソッド名トークンの直後が `('文字列')` のときだけリテラルを返す (純関数)。
 *
 * @param  list<array{id: int|null, text: string, line: int}>  $tokens
 * @param  int  $nameIndex  メソッド名トークンの添字
 */
function jobLeaseSingleStringArgument(array $tokens, int $nameIndex): ?string
{
    $open = $tokens[$nameIndex + 1] ?? null;
    $argument = $tokens[$nameIndex + 2] ?? null;
    $close = $tokens[$nameIndex + 3] ?? null;

    if ($open === null || $open['text'] !== '(') {
        return null;
    }
    if ($argument === null || $argument['id'] !== T_CONSTANT_ENCAPSED_STRING) {
        return null;
    }
    if ($close === null || $close['text'] !== ')') {
        return null;
    }

    return trim($argument['text'], "'\"");
}

/**
 * ReflectionClass の default properties から `$timeout` を int|null へ正規化する (純関数)。
 *
 * - `array_key_exists('timeout', $defaults)` が false のときだけ null を返す (未宣言 = 正常)
 * - 宣言されている値が null / 非 int / 0 以下 → fail
 *   (明示 `public ?int $timeout = null` を未宣言と同一視すると規則 2 を素通りする)
 *
 * @param  ReflectionClass<object>  $class
 */
function jobLeaseDeclaredJobTimeout(ReflectionClass $class): ?int
{
    $defaults = $class->getDefaultProperties();
    if (! array_key_exists('timeout', $defaults)) {
        return null;
    }

    $timeout = $defaults['timeout'];
    Assert::integer($timeout, "規則 2: {$class->getName()} の \$timeout は正の int デフォルト値を持つプロパティ宣言に限る (実行時に決まる \$timeout は静的検査できない)");
    Assert::greaterThan($timeout, 0, "規則 2: {$class->getName()} の \$timeout が正の整数ではない");

    return $timeout;
}

/**
 * app/ 全体の site を「ファイル絶対パス => site 一覧」で返す。
 *
 * @return array<string, list<array{class: string|null, kind: string, receiverIsThis: bool, statementStart: bool, literal: string|null, hasDefault: bool, line: int}>>
 */
function jobLeaseAllSites(): array
{
    $all = [];
    foreach (jobLeaseAppPhpFiles() as $path) {
        $source = file_get_contents($path);
        Assert::string($source, "ファイルを読み込めません: {$path}");

        $sites = jobLeaseConnectionDeclarationSites($source);
        if ($sites !== []) {
            $all[$path] = $sites;
        }
    }

    return $all;
}

/** base_path() からの相対パス表示 (失敗メッセージ用)。 */
function jobLeaseRelativePath(string $path): string
{
    $base = base_path().DIRECTORY_SEPARATOR;

    return str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
}

test('接続経路: キューに載る全クラスが目録に登録されている', function (): void {
    $scanned = jobLeaseShouldQueueClasses();
    $registered = array_keys(QUEUED_JOB_LEASE_INVENTORY);
    sort($registered);

    $missing = array_values(array_diff($scanned, $registered));
    $stale = array_values(array_diff($registered, $scanned));

    expect($missing)->toBe(
        [],
        '接続経路: 目録 (QUEUED_JOB_LEASE_INVENTORY) 未登録の ShouldQueue 実装がある: '
        .implode(', ', $missing),
    );
    expect($stale)->toBe(
        [],
        '接続経路: 目録に実在しないクラスが残っている: '.implode(', ', $stale),
    );
});

test('接続経路: Job / Mailable / Notification の 3 系統が母集団に入っている', function (): void {
    $scanned = jobLeaseShouldQueueClasses();

    // 母集団判定が Job ディレクトリだけに縮んでいないことの behavioral 固定
    expect($scanned)->toContain(RunManualAnalysis::class);
    expect($scanned)->toContain(InquiryReceivedMail::class);
    expect($scanned)->toContain(PaymentFailedNotification::class);
});

test("接続経路: 接続の指定は \$this->onConnection('リテラル') に限る", function (): void {
    $connectionKinds = ['onConnection', 'viaConnections', 'viaConnection', 'connectionProperty', 'connectionAssignment'];
    $violations = [];

    foreach (jobLeaseAllSites() as $path => $sites) {
        foreach ($sites as $site) {
            if (! in_array($site['kind'], $connectionKinds, true)) {
                continue; // $timeout 関連は規則 2 のケースが担当する
            }

            $allowed = $site['class'] !== null
                && array_key_exists($site['class'], QUEUED_JOB_LEASE_INVENTORY)
                && $site['kind'] === 'onConnection'
                && $site['receiverIsThis']
                && $site['statementStart']
                && $site['literal'] !== null;

            if (! $allowed) {
                $violations[] = jobLeaseRelativePath($path).':'.$site['line'].' ('.$site['kind'].')';
            }
        }
    }

    expect($violations)->toBe(
        [],
        "接続経路: 接続の指定は目録登録済みクラス内の \$this->onConnection('リテラル') に限り、"
        .'かつメソッド本体の直下に置かれた実行文でなければならない '
        .'(条件分岐やクロージャの中に置くと「必ず実行される」ことを静的に読めない)。'
        .'動的に決まる接続は静的検査できない (規則 2 の検査が空洞化する)。'
        ."ジョブ側で \$this->onConnection('リテラル') に寄せるか、実行時 fail-fast の対象として個別に扱うこと: "
        .implode(', ', $violations),
    );
});

test('接続経路: 目録の接続宣言がソースと一致する', function (): void {
    foreach (QUEUED_JOB_LEASE_INVENTORY as $class => $expectedConnection) {
        $reflection = new ReflectionClass($class);
        $file = $reflection->getFileName();
        Assert::string($file, "{$class} のファイルパスを取得できません");

        $source = file_get_contents($file);
        Assert::string($source, "{$class} のソースを読み込めません");

        $pins = [];
        foreach (jobLeaseConnectionDeclarationSites($source) as $site) {
            if ($site['class'] === $class && $site['kind'] === 'onConnection') {
                $pins[] = $site;
            }
        }

        expect(count($pins))->toBeLessThanOrEqual(
            1,
            "接続経路: {$class} が onConnection() を複数回呼んでいる (どちらが効くか読めない)",
        );

        if ($pins === []) {
            expect($expectedConnection)->toBeNull(
                "接続経路: 目録は {$class} を接続 {$expectedConnection} と記録しているが、ソースに onConnection() が無い",
            );

            continue;
        }

        expect($pins[0]['literal'])->toBe(
            $expectedConnection,
            "接続経路: {$class} の onConnection() リテラルが目録と一致しない",
        );

        // ★ pin は **constructor 直下の実行文**でなければならない。任意のメソッドや
        //   条件分岐 / クロージャの中に置かれていると「dispatch 前に必ず実行される」保証が無く、
        //   目録と literal が一致していても実行時は既定接続へ流れうる
        //   (= 規則 2 の比較対象が静かに空洞化する)。
        expect($pins[0]['statementStart'])->toBeTrue(
            "接続経路: {$class} の onConnection() が「必ず実行される文」の形になっていない "
            .'(条件分岐・クロージャ・代入式の中では dispatch 前に実行される保証が無い)',
        );
        $constructor = $reflection->getConstructor();
        expect($constructor)->not->toBeNull(
            "接続経路: {$class} は接続を pin しているが constructor が無い。"
            .'dispatch 前に必ず実行される場所 (constructor) で pin すること',
        );
        Assert::isInstanceOf($constructor, ReflectionMethod::class);
        expect($constructor->getDeclaringClass()->getName())->toBe(
            $class,
            "接続経路: {$class} の constructor が親クラス由来で、pin の実行が静的に読めない",
        );

        $line = $pins[0]['line'];
        expect($line >= $constructor->getStartLine() && $line <= $constructor->getEndLine())->toBeTrue(
            "接続経路: {$class} の onConnection() (L{$line}) が constructor の外にある。"
            .'dispatch 前に必ず実行されるとは限らないため、constructor 内で pin すること',
        );
    }
});

test('規則 2: キューに載るクラスの $timeout は正の int デフォルト値を持つプロパティ宣言に限る', function (): void {
    $violations = [];

    foreach (jobLeaseAllSites() as $path => $sites) {
        foreach ($sites as $site) {
            if ($site['class'] === null || ! array_key_exists($site['class'], QUEUED_JOB_LEASE_INVENTORY)) {
                continue; // キューと無関係なクラスの $timeout は本不変条件の対象外
            }

            if ($site['kind'] === 'timeoutAssignment') {
                $violations[] = jobLeaseRelativePath($path).':'.$site['line'].' ($this->timeout への代入)';

                continue;
            }

            if ($site['kind'] === 'timeoutProperty' && ! $site['hasDefault']) {
                $violations[] = jobLeaseRelativePath($path).':'.$site['line'].' (デフォルト値なしの $timeout 宣言)';
            }
        }
    }

    expect($violations)->toBe(
        [],
        '規則 2: 実行時に決まる $timeout は静的検査できない。正の int デフォルト値を持つプロパティ宣言に限ること: '
        .implode(', ', $violations),
    );
});

test('規則 2: 接続を pin したジョブの $timeout は retry_after を下回る', function (): void {
    $retryAfters = QueueLeaseConfig::databaseConnections();

    foreach (QUEUED_JOB_LEASE_INVENTORY as $class => $connection) {
        if ($connection === null) {
            continue;
        }

        $timeout = jobLeaseDeclaredJobTimeout(new ReflectionClass($class));
        if ($timeout === null) {
            continue;
        }

        expect(array_key_exists($connection, $retryAfters))->toBeTrue(
            "規則 2: {$class} が pin した接続 {$connection} が config/queue.php の driver=database 接続に存在しない",
        );
        expect($timeout)->toBeLessThan(
            $retryAfters[$connection],
            "規則 2: {$class} の \$timeout ({$timeout}) が接続 {$connection} の retry_after"
            ." ({$retryAfters[$connection]}) 以上",
        );
    }
});

test('規則 2: 既定接続のジョブは $timeout を宣言しない', function (): void {
    foreach (QUEUED_JOB_LEASE_INVENTORY as $class => $connection) {
        if ($connection !== null) {
            continue;
        }

        expect(jobLeaseDeclaredJobTimeout(new ReflectionClass($class)))->toBeNull(
            "規則 2: {$class} は既定接続だが \$timeout を宣言している。既定接続は QUEUE_CONNECTION 次第で"
            .'接続が変わるため静的に retry_after と比較できない。$this->onConnection() で接続を pin すること',
        );
    }
});

test('規則 2: 目録の接続名が config/queue.php に実在する', function (): void {
    $connections = QueueLeaseConfig::databaseConnections();

    foreach (QUEUED_JOB_LEASE_INVENTORY as $class => $connection) {
        if ($connection === null) {
            continue;
        }

        expect(array_key_exists($connection, $connections))->toBeTrue(
            "規則 2: {$class} が pin した接続 {$connection} が config/queue.php の driver=database 接続に存在しない",
        );
    }
});
