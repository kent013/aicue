<?php

declare(strict_types=1);

use App\Enums\Security\ExternalSeamClassification;
use App\Enums\Security\ExternalSeamDimension;
use App\Enums\Security\ExternalSeamKind;
use Tests\Support\ExternalClientBoundaryScanner;
use Tests\Support\ExternalSeam\ExternalSeamInventory;
use Tests\Support\ExternalSeam\ExternalSeamRule;
use Tests\Support\ExternalSeam\ExternalSeamScanner;
use Tests\Support\ExternalSeam\ExternalSeamScanResult;
use Tests\Support\ExternalSeam\ExternalSeamSite;
use Tests\Support\PestTestNameScanner;
use Tests\Support\Prompts\PrismDirectDispatchScanner;
use Tests\Support\ScanScopeKind;

/*
 * 外部到達点の既定拒否目録 (標準形 v1 / 検知 v1)。
 *
 * ★この gate が保証するもの:
 *   1. app/ の**コード到達点** (決済 client 取得・構築 / Socialite / Http / Mail・Notification facade) が
 *      全件、目録と `(クラス, 種別)` で双方向に一致している (未登録も残骸も赤)
 *   2. 走査母集団が 0 件なら赤 (走査条件を壊したまま緑にならない)
 *   3. site が名前付きクラス本体へ帰属している (匿名クラス / ファイルスコープの抜け道を作らせない)
 *   4. 規則ごとに名乗ってよい種別が固定されている (種別が登録者の言い値にならない)
 *   ほかに `->stripe()` の同一ファイル抑制 0 件 / SocialLogin の 1 クラス固定 /
 *   種別 × 次元の排他的被覆 / 委譲先の母集団生存と test 名同定を固定する。
 *
 * ★この gate が保証しないもの (誇張しない):
 *   **出口を塞がない**。目録は新経路の**検知**であり、実行時の外部通信は止めない。
 *   委譲先の assert の中身 / `app/` の外 (`routes/` / `config/`) / 次元そのものの数え落とし /
 *   文字列キーの container 解決だけの経路 / vendor 内部から出る通信 / 他種別の宛先集合は
 *   検出できない。
 *   **保証しないものの完全な一覧は `docs/architecture.md` §外部到達点の目録 (標準形 v1) が正本**
 *   (ここは実務上重要な項目の要約である)。
 *
 * ★同一クラスが本目録と T126 目録の**両方**に載ることは正当である。
 *   `AppServiceProvider` は AWS SNS クライアント構築 (T126) と `Cashier::stripe()->prices` (本目録) の
 *   **別々の到達事実**で登録されている。禁じているのは「同じ到達事実の二重宣言」であり、
 *   規則が分離しているので構造的に起きない。
 *
 * 運用契約は docs/architecture.md §外部到達点の目録 (標準形 v1)。
 */

const EXTERNAL_SEAM_MUTATION_COVERAGE = [
    'M1' => '目録から entry を 1 つ消すと対称差ゼロ (missing 側) が赤くなる',
    'M2' => '目録に走査で出ないクラスを足すと対称差ゼロ (残骸側) が赤くなる',
    'M3' => 'FACADE_RULES を空にすると対称差ゼロ (missing 側) が赤くなる',
    'M4' => '全規則を無効化すると空振り防止が赤くなる',
    'M5' => 'SocialAuthController 以外のクラスに Socialite::driver() を書くと名指し固定が赤くなる',
    'M6' => 'Cashier / Stripe を知らないクラスに ->stripe() を書くと抑制 0 件が赤くなる',
    'M7' => '規則→種別表の 1 行を書き換えると種別突合が赤くなる',
    'M8' => 'requiredDimensions から kind を 1 つ消すと exact-fit が赤くなる',
    'M9' => '委譲の gateTestName を 1 文字変えると同定が赤くなる',
    'M10' => 'config/template.php の social_providers を空にすると委譲の生存確認が赤くなる',
    'M11' => 'ruleSymbols に委譲済み名前空間を足すと混入検査が赤くなる',
    'M12' => 'entry の classification を Exempt にすると免除語彙未整備で赤くなる',
    'M13' => '同じ (class, kind) を重複登録すると双方向照合が赤くなる',
    'M14' => '委譲先 test をコメント化して同名をコメントに残すと test 名同定が赤くなる',
    'M15' => '対応する規則 site の無い kind を既存クラスへ足すと双方向照合 (残骸側) が赤くなる',
    'M16' => '目録が覆う対へ委譲を足すと二重被覆で赤くなる',
    'M17' => '同じ (kind, dimension) の委譲を重複登録すると排他的被覆が赤くなる',
    'M18' => '必須表に無い余剰委譲を足すと逆方向検査が赤くなる',
    'M19' => '規則が名乗れる種別を増やす (http_facade_reference へ Mail を足す) と値集合の pin が赤くなる',
];

const EXTERNAL_SEAM_MUTATION_IDS = [
    'M1', 'M2', 'M3', 'M4', 'M5', 'M6', 'M7', 'M8', 'M9', 'M10',
    'M11', 'M12', 'M13', 'M14', 'M15', 'M16', 'M17', 'M18', 'M19',
];

/**
 * 規則ごとに名乗ってよい種別 (種別が登録者の言い値にならないようにする突合表)。
 *
 * ★`http_facade_reference` が名乗れるのは `{Captcha, MarketData}` だけである。
 *   新しい `Http::` 直呼びは、このどちらでもなければ **enum に case を足す判断**を通る
 *   = 新しい外向きの種類が黙って増えない。
 *
 * @var array<string, list<ExternalSeamKind>>
 */
const EXTERNAL_SEAM_RULE_KINDS = [
    'payment_client_call' => [ExternalSeamKind::Payment],
    'payment_client_construction' => [ExternalSeamKind::Payment],
    'socialite_facade_reference' => [ExternalSeamKind::SocialLogin],
    'http_facade_reference' => [ExternalSeamKind::Captcha, ExternalSeamKind::MarketData],
    'mail_facade_reference' => [ExternalSeamKind::Mail],
];

function externalSeamScan(): ExternalSeamScanResult
{
    $root = dirname(__DIR__, 2);

    return ExternalSeamScanner::scanDirectory($root.'/app', 'app');
}

/**
 * site の規則が名乗ってよい種別。
 *
 * @return list<ExternalSeamKind>
 */
function externalSeamKindsForRule(ExternalSeamRule $rule): array
{
    return EXTERNAL_SEAM_RULE_KINDS[$rule->value] ?? [];
}

test('外部到達: 走査 site と目録は (クラス, 種別) で双方向に一致する', function (): void {
    $sites = externalSeamScan()->adopted;
    $entries = ExternalSeamInventory::entries();

    $violations = [];

    // (a) 各 site に一致する entry がちょうど 1 件 (0 件 = 未登録 / 2 件以上 = 帰属が曖昧)
    foreach ($sites as $site) {
        $kinds = externalSeamKindsForRule($site->rule);
        $matched = array_values(array_filter(
            $entries,
            static fn ($entry): bool => $entry->class === $site->class && in_array($entry->kind, $kinds, true),
        ));

        if (count($matched) !== 1) {
            $violations[] = '未登録 or 帰属が曖昧 (一致 entry '.count($matched).' 件): '.$site->describe()
                .' [class='.($site->class ?? 'null').']';
        }
    }

    // (b) 各 entry に対応する site が 1 件以上 (残骸検出)
    foreach ($entries as $entry) {
        $matched = array_filter(
            $sites,
            static fn (ExternalSeamSite $site): bool => $site->class === $entry->class
                && in_array($entry->kind, externalSeamKindsForRule($site->rule), true),
        );

        if ($matched === []) {
            $violations[] = "残骸 entry (対応する走査 site が 0 件): {$entry->class} [{$entry->kind->value}]";
        }
    }

    // (c) 同じ (class, kind) の重複登録は 0 件
    $seen = [];
    foreach ($entries as $entry) {
        $key = $entry->class.'|'.$entry->kind->value;
        $seen[$key] = ($seen[$key] ?? 0) + 1;
    }
    foreach ($seen as $key => $count) {
        if ($count > 1) {
            $violations[] = "重複登録 ({$count} 件): {$key}";
        }
    }

    expect($violations)->toBe([],
        '外部到達点は ExternalSeamInventory::entries() へ (クラス, 種別) で登録してください。'
        .PHP_EOL.implode(PHP_EOL, $violations));
});

test('外部到達: 走査母集団が空でない', function (): void {
    // 走査条件を壊したまま「違反 0 件」で緑にならないための空振り防止。
    expect(externalSeamScan()->adopted)->not->toBeEmpty('外部到達点の走査結果が 0 件です (規則が壊れている疑い)')
        ->and(ExternalSeamInventory::entries())->not->toBeEmpty();
});

test('外部到達: site は名前付きクラス本体へ帰属する', function (): void {
    $violations = [];
    foreach (externalSeamScan()->adopted as $site) {
        if ($site->scopeKind !== ScanScopeKind::NamedClass || $site->class === null) {
            $violations[] = $site->describe().' [scope='.$site->scopeKind->name.']';
        }
    }

    expect($violations)->toBe([],
        '外部到達点は名前付きクラス本体へ置いてください (匿名クラス / ファイルスコープは目録を迂回する抜け道になります)。'
        .PHP_EOL.implode(PHP_EOL, $violations));
});

test('外部到達: 規則→種別表は規則 enum を exact-fit で覆い、値集合も pin される', function (): void {
    // ★キー集合の exact-fit だけでは「規則が名乗れる種別を**増やす**」改変を止められない
    //   (例: http_facade_reference へ Mail を足すと FxRateService に kind: Mail の entry を
    //    登録できてしまい、テスト 1 の残骸検出もすり抜ける)。値集合も別宣言で pin し、
    //   規則の意味を広げるには**2 箇所**を触らせる (意図的な摩擦)。
    $expected = [
        'payment_client_call' => ['payment'],
        'payment_client_construction' => ['payment'],
        'socialite_facade_reference' => ['social_login'],
        'http_facade_reference' => ['captcha', 'market_data'],
        'mail_facade_reference' => ['mail'],
    ];

    $ruleValues = array_map(static fn (ExternalSeamRule $rule): string => $rule->value, ExternalSeamRule::cases());
    $tableKeys = array_keys(EXTERNAL_SEAM_RULE_KINDS);
    sort($ruleValues);
    sort($tableKeys);

    expect($tableKeys)->toBe($ruleValues, '規則を追加したら EXTERNAL_SEAM_RULE_KINDS へも登録してください。');

    $actual = [];
    foreach (EXTERNAL_SEAM_RULE_KINDS as $rule => $kinds) {
        expect($kinds)->not->toBe([], "規則 {$rule} が名乗れる種別が空です。");
        $actual[$rule] = array_map(static fn (ExternalSeamKind $kind): string => $kind->value, $kinds);
    }
    ksort($actual);
    ksort($expected);

    expect($actual)->toBe($expected,
        '規則が名乗れる種別を変えるときは、この pin 表も同時に更新してください'
        .' (種別を登録者の言い値にしないための二重宣言です)。');
});

test('外部到達: 各 entry の根拠は 30 文字以上', function (): void {
    $violations = [];
    foreach (ExternalSeamInventory::entries() as $entry) {
        if (mb_strlen($entry->rationale) < 30) {
            $violations[] = "{$entry->class}: 根拠が ".mb_strlen($entry->rationale).' 文字';
        }
    }

    expect($violations)->toBe([], implode(PHP_EOL, $violations));
});

test('外部到達: 決済の抑制 site は 0 件', function (): void {
    // `->stripe()` は receiver 非依存の名前一致で拾い、決済名前空間を知らないファイルでは
    // 抑制する。抑制が 1 件でも働いていれば偽陰性の口が開いているので赤くする
    // (静かに効くことがない = 抑制規則の効果を可視化する)。
    $suppressed = externalSeamScan()->suppressed;
    $described = array_map(static fn (ExternalSeamSite $site): string => $site->describe(), $suppressed);

    expect($suppressed)->toBe([],
        '決済 client 取得の抑制が働いています。抑制された site が本当に外部到達でないなら'
        .'規則側で分離してください (抑制のままにすると偽陰性になります)。'
        .PHP_EOL.implode(PHP_EOL, $described));
});

test('外部到達: SocialLogin は SocialAuthController 1 クラスに固定される', function (): void {
    $registered = array_values(array_map(
        static fn ($entry): string => $entry->class,
        array_filter(
            ExternalSeamInventory::entries(),
            static fn ($entry): bool => $entry->kind === ExternalSeamKind::SocialLogin,
        ),
    ));

    $scanned = [];
    foreach (externalSeamScan()->adopted as $site) {
        if ($site->rule === ExternalSeamRule::SocialiteFacadeReference && $site->class !== null) {
            $scanned[$site->class] = true;
        }
    }
    $scannedClasses = array_keys($scanned);
    sort($registered);
    sort($scannedClasses);

    $funnel = [ExternalSeamInventory::socialLoginFunnel()];

    expect($registered)->toBe($funnel,
        'SSO は '.ExternalSeamInventory::socialLoginFunnel().' 1 クラスへ集約します (他クラスは登録も免除もできません)。')
        ->and($scannedClasses)->toBe($funnel,
            'Socialite::driver() は '.ExternalSeamInventory::socialLoginFunnel().' の外に書けません。');
});

test('外部到達: 免除分類は語彙が未整備のため使用できない', function (): void {
    $violations = [];
    foreach (ExternalSeamInventory::entries() as $entry) {
        if ($entry->classification === ExternalSeamClassification::Exempt) {
            $violations[] = $entry->class;
        }
    }

    expect($violations)->toBe([],
        'ExternalSeamClassification::Exempt は現時点で使用できません。免除が必要になったら'
        .' ExternalSeamExemption enum + 免除前提表 + 30 文字根拠検査 + 空振り防止をセットで新設してください'
        .' (母集団 0 件の免除語彙を先に作ると「1 件も検査せずに緑」な gate が増えます)。'
        .PHP_EOL.implode(PHP_EOL, $violations));
});

test('外部到達: 種別 × 次元の必須表は enum 全 case を覆う', function (): void {
    $kindValues = array_map(static fn (ExternalSeamKind $kind): string => $kind->value, ExternalSeamKind::cases());
    $tableKeys = array_keys(ExternalSeamInventory::requiredDimensions());
    sort($kindValues);
    sort($tableKeys);

    expect($tableKeys)->toBe($kindValues, '種別を追加したら requiredDimensions() へも必要な次元を宣言してください。');

    foreach (ExternalSeamInventory::requiredDimensions() as $kind => $dimensions) {
        expect($dimensions)->not->toBe([], "種別 {$kind} の必要次元が空です。");
    }
});

test('外部到達: 種別 × 次元は目録か委譲のちょうど一方で覆われる', function (): void {
    $entriesByKind = [];
    foreach (ExternalSeamInventory::entries() as $entry) {
        $entriesByKind[$entry->kind->value] = true;
    }

    /** @var array<string, int> $delegationCount "kind|dimension" => 件数 */
    $delegationCount = [];
    foreach (ExternalSeamInventory::delegations() as $delegation) {
        $key = $delegation->kind->value.'|'.$delegation->dimension->value;
        $delegationCount[$key] = ($delegationCount[$key] ?? 0) + 1;
    }

    $violations = [];

    // (a)(b)(c)(e): 必須対ごとに coverage source をちょうど 1 つに固定する
    foreach (ExternalSeamInventory::requiredDimensions() as $kind => $dimensions) {
        foreach ($dimensions as $dimension) {
            $key = $kind.'|'.$dimension->value;
            $sources = [];
            if ($dimension === ExternalSeamDimension::CodeReachPoint && ($entriesByKind[$kind] ?? false)) {
                $sources[] = '目録 (ExternalSeamInventory::entries)';
            }
            for ($i = 0; $i < ($delegationCount[$key] ?? 0); $i++) {
                $sources[] = '委譲 (ExternalSeamInventory::delegations)';
            }

            if (count($sources) !== 1) {
                $violations[] = "{$key}: coverage source が ".count($sources).' 件 ('
                    .(count($sources) === 0 ? '覆われていない' : '二重宣言: '.implode(' / ', $sources)).')';
            }
        }
    }

    // (d): 逆方向 — 必須表に無い委譲を拒否する
    foreach (ExternalSeamInventory::delegations() as $delegation) {
        $dimensions = ExternalSeamInventory::requiredDimensions()[$delegation->kind->value] ?? [];
        if (! in_array($delegation->dimension, $dimensions, true)) {
            $violations[] = "{$delegation->kind->value}|{$delegation->dimension->value}: "
                .'必須表に無い余剰委譲です';
        }
    }

    expect($violations)->toBe([], implode(PHP_EOL, $violations));
});

test('外部到達: 委譲先の母集団が生きている', function (): void {
    foreach (ExternalSeamInventory::delegations() as $delegation) {
        $population = ($delegation->livenessProbe)();

        expect($population)->not->toBeEmpty(
            "委譲: {$delegation->kind->value} × {$delegation->dimension->value} の母集団が空です "
            ."(委譲先 {$delegation->gateFile} の走査条件 / config が壊れている疑い)",
        );
    }

    // 負のコントロール: 委譲先の検出器そのものが生きていることを合成ソースで確認する。
    // 「母集団が非空」だけだと、検出器が何も検出しなくなっても緑になる。
    $awsSource = <<<'PHP'
    <?php
    namespace App\X;
    use Aws\S3\S3Client;
    class Probe { public function make(): S3Client { return new S3Client([]); } }
    PHP;
    expect(ExternalClientBoundaryScanner::boundarySites('probe.php', $awsSource))->not->toBeEmpty();

    $prismSource = <<<'PHP'
    <?php
    namespace App\X;
    use Prism\Prism\Facades\Prism;
    class Probe { public function run(): void { Prism::text(); } }
    PHP;
    expect(PrismDirectDispatchScanner::containsPrismDirectCall($prismSource))->toBeTrue();
});

test('外部到達: 委譲先 gate のファイルと test 名が実在する', function (): void {
    $violations = [];
    foreach (ExternalSeamInventory::delegations() as $delegation) {
        $path = base_path($delegation->gateFile);
        if (! is_file($path)) {
            $violations[] = "委譲先ファイルがありません: {$delegation->gateFile}";

            continue;
        }

        $source = file_get_contents($path);
        if ($source === false) {
            $violations[] = "委譲先ファイルを読めません: {$delegation->gateFile}";

            continue;
        }

        if (! in_array($delegation->gateTestName, PestTestNameScanner::names($source), true)) {
            $violations[] = "委譲先 test が実在しません: {$delegation->gateFile} :: {$delegation->gateTestName}";
        }
    }

    expect($violations)->toBe([],
        '委譲先の test 名を変えたら ExternalSeamInventory::delegations() の gateTestName も同時に更新してください。'
        .PHP_EOL.implode(PHP_EOL, $violations));
});

test('外部到達: 委譲した種別は本目録の母集団に現れない', function (): void {
    $delegatedKinds = [ExternalSeamKind::ObjectStorage, ExternalSeamKind::Llm];

    $violations = [];
    foreach (ExternalSeamInventory::entries() as $entry) {
        if (in_array($entry->kind, $delegatedKinds, true)) {
            $violations[] = "委譲済み種別を目録へ登録しています: {$entry->class} [{$entry->kind->value}]";
        }
    }

    // 走査結果ではなく**規則そのもの**を検査する (走査結果は常に 0 件で自明な緑になるため)。
    $delegatedPrefixes = ['Aws\\', 'League\\Flysystem\\', 'Illuminate\\Filesystem\\', 'Prism\\'];
    foreach (ExternalSeamScanner::ruleSymbols() as $rule => $symbols) {
        foreach ($symbols as $symbol) {
            foreach ($delegatedPrefixes as $prefix) {
                if (str_starts_with($symbol, $prefix)) {
                    $violations[] = "規則 {$rule} が委譲済み名前空間を走査しています: {$symbol}";
                }
            }
        }
    }

    $ruleValues = array_map(static fn (ExternalSeamRule $rule): string => $rule->value, ExternalSeamRule::cases());
    $symbolKeys = array_keys(ExternalSeamScanner::ruleSymbols());
    sort($ruleValues);
    sort($symbolKeys);

    expect($violations)->toBe([],
        'ファイル保存 (T126) と LLM (PromptGuardrailTest) は委譲先が正本です。同じ到達事実を 2 箇所で宣言しないでください。'
        .PHP_EOL.implode(PHP_EOL, $violations))
        ->and($symbolKeys)->toBe($ruleValues, '規則を追加したら ruleSymbols() へも載せてください。');
});

test('外部到達: 委譲の根拠は 30 文字以上', function (): void {
    $violations = [];
    foreach (ExternalSeamInventory::delegations() as $delegation) {
        if (mb_strlen($delegation->rationale) < 30) {
            $violations[] = "{$delegation->kind->value}|{$delegation->dimension->value}: 根拠が "
                .mb_strlen($delegation->rationale).' 文字';
        }
    }

    expect($violations)->toBe([], implode(PHP_EOL, $violations));
});

test('外部到達: mutation 被覆表のキー集合が想定 mutation ID と一致する', function (): void {
    expect(array_keys(EXTERNAL_SEAM_MUTATION_COVERAGE))->toBe(EXTERNAL_SEAM_MUTATION_IDS);
});
