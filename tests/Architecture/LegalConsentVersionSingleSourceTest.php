<?php

declare(strict_types=1);

/*
 * Architecture invariant: 同意バージョン (legal.consent_version) の解決点は
 * App\Support\Legal\LegalConsent::version() の **1 箇所だけ**である。
 *
 * 背景: 版は users.consent_version / inquiries.consent_version へ「どの版に同意したか」の
 * 証跡として forceFill で記録される。記録側が config を個別に直読すると形が分岐し
 * (実際に 3 形へ分岐していた)、空版の fail-fast が 1 経路にしか掛からない状態が生まれる。
 * 空版で証跡を書くと「どの版に同意したか」が事後に決定不能になる = 機能の名前が果たすべき
 * 役割そのものの破れ。
 *
 * ★走査規則は **legal. 名前空間 / LEGAL_CONSENT_VERSION / LegalConsent::version / draft-1**
 *   の 4 語彙に限定する。素の 'consent_version' で走ると、実在する別 feature
 *   (課金の自動購入同意 config('billing.auto_recharge.consent_version')) を巻き込んで
 *   false positive になる。名前が似ているだけの別概念を統合しない (思考原則 4)。
 *   この非巻き込みは「名前空間の隔離」テストが実ファイルで behavioral に固定する。
 *
 * 検出方式: token_get_all ベース (CarbonOverflowArithmeticGateTest /
 * ScenarioWritePathInventoryTest と同じ流儀)。regex ではなく token を使うのは
 * コメント・docblock・heredoc 内の**記述**を誤検出しないため。
 * 文字列リテラルは引用符を trim して正規化するので 'x' と "x" を等価に扱う。
 *
 * 単一出典の検査は**空振りで green になりやすい**。そこで
 *   (1) 母集団 floor (ファイル数 / token 数 / inventory 件数)
 *   (2) 検出器の正の自己検証 (実ファイルで実際に点灯すること)
 *   (3) 負のコントロール (fixture ソースで点灯すること。実ファイルは書き換えない)
 * の 3 つを必ず同梱する。
 *
 * DB 不使用の静的検査 (Architecture lane の作法)。
 */

/** 設定キー: SSOT だけが読んでよい。 */
const LEGAL_CONSENT_CONFIG_KEY = 'legal.consent_version';

/** env 名: config/legal.php だけが読んでよい。 */
const LEGAL_CONSENT_ENV_NAME = 'LEGAL_CONSENT_VERSION';

/** プレースホルダ版: コード側に literal を残さない (config 既定値が唯一の出所)。 */
const LEGAL_CONSENT_PLACEHOLDER_VERSION = 'draft-1';

/** 単一出典クラス (repo ルート相対)。 */
const LEGAL_CONSENT_SOURCE_FILE = 'app/Support/Legal/LegalConsent.php';

/** 本 gate 自身 (G4 の唯一の除外対象)。 */
const LEGAL_CONSENT_GATE_FILE = 'tests/Architecture/LegalConsentVersionSingleSourceTest.php';

/**
 * G3 の exact-fit inventory: LegalConsent::version() を呼んでよい app/ 相対パス。
 * **allowlist ではない** — 増えても減っても fail する。新しい同意書き込み経路を
 * 足すときはここへ登録すること (= レビューの目に必ず入る)。
 *
 * @var list<string>
 */
const LEGAL_CONSENT_VERSION_CALLERS = [
    'Actions/Fortify/CreateNewUser.php',
    'Actions/Inquiry/CreateInquiryAction.php',
    'Services/Auth/SocialAccountService.php',
];

/** 文字列リテラルの生表記 ('x' / "x") を引用符抜きで比較する。 */
function legalConsentLiteralEquals(string $literal, string $expected): bool
{
    return trim($literal, "'\"") === $expected;
}

/**
 * 空白・コメントを飛ばして次の意味のあるトークン位置を返す。
 *
 * @param  array<int, array{0: int, 1: string, 2: int}|string>  $tokens
 */
function legalConsentNextMeaningful(array $tokens, int $index): ?int
{
    $count = count($tokens);
    for ($i = $index + 1; $i < $count; $i++) {
        $token = $tokens[$i];
        if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        return $i;
    }

    return null;
}

/**
 * 1 ソースを走査して各語彙の出現数を返す (純関数 = 負のコントロールから直接呼べる)。
 *
 * @return array{configKey: int, envName: int, placeholder: int, versionCall: int, tokens: int}
 */
function legalConsentScanSource(string $source): array
{
    $tokens = token_get_all($source);
    $count = count($tokens);
    $result = ['configKey' => 0, 'envName' => 0, 'placeholder' => 0, 'versionCall' => 0, 'tokens' => 0];

    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];
        if (! is_array($token)) {
            continue;
        }
        $result['tokens']++;
        [$id, $value] = $token;

        // 文字列リテラル 3 種 (引用符の種類を問わない)
        if ($id === T_CONSTANT_ENCAPSED_STRING) {
            if (legalConsentLiteralEquals($value, LEGAL_CONSENT_CONFIG_KEY)) {
                $result['configKey']++;
            }
            if (legalConsentLiteralEquals($value, LEGAL_CONSENT_ENV_NAME)) {
                $result['envName']++;
            }
            if (legalConsentLiteralEquals($value, LEGAL_CONSENT_PLACEHOLDER_VERSION)) {
                $result['placeholder']++;
            }

            continue;
        }

        // LegalConsent::version / \App\Support\Legal\LegalConsent::version
        if ($id !== T_STRING && $id !== T_NAME_QUALIFIED && $id !== T_NAME_FULLY_QUALIFIED) {
            continue;
        }
        $segments = explode('\\', $value);
        if (end($segments) !== 'LegalConsent') {
            continue;
        }
        $doubleColon = legalConsentNextMeaningful($tokens, $i);
        if ($doubleColon === null
            || ! is_array($tokens[$doubleColon])
            || $tokens[$doubleColon][0] !== T_DOUBLE_COLON) {
            continue; // `use App\Support\Legal\LegalConsent;` 等は呼び出しではない
        }
        $method = legalConsentNextMeaningful($tokens, $doubleColon);
        if ($method !== null
            && is_array($tokens[$method])
            && $tokens[$method][0] === T_STRING
            && $tokens[$method][1] === 'version') {
            $result['versionCall']++;
        }
    }

    return $result;
}

/**
 * repo ルート相対パス => 走査結果。
 *
 * @param  list<string>  $dirs  repo ルートからの相対ディレクトリ
 * @return array<string, array{configKey: int, envName: int, placeholder: int, versionCall: int, tokens: int}>
 */
function legalConsentScanTree(array $dirs): array
{
    $root = base_path();
    $scanned = [];

    foreach ($dirs as $dir) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root.'/'.$dir, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $absolute = $file->getRealPath();
            if (! is_string($absolute)) {
                continue;
            }
            $source = file_get_contents($absolute);
            if (! is_string($source)) {
                continue;
            }
            $scanned[substr($absolute, strlen($root) + 1)] = legalConsentScanSource($source);
        }
    }

    ksort($scanned);

    return $scanned;
}

/**
 * 実ファイルを 1 本走査する。
 *
 * @return array{configKey: int, envName: int, placeholder: int, versionCall: int, tokens: int}
 */
function legalConsentScanFile(string $relative): array
{
    $source = file_get_contents(base_path($relative));
    expect($source)->toBeString();

    return legalConsentScanSource((string) $source);
}

test('G1: 設定キー legal.consent_version を読むのは LegalConsent だけである', function (): void {
    $violations = [];
    foreach (legalConsentScanTree(['app']) as $relative => $scan) {
        if ($scan['configKey'] > 0 && $relative !== LEGAL_CONSENT_SOURCE_FILE) {
            $violations[] = $relative;
        }
    }

    expect($violations)->toBe([],
        'config キー legal.consent_version の直読を検出しました。'
        .'同意バージョンは App\Support\Legal\LegalConsent::version() 経由で取得してください '
        .'(空版 fail-fast がそこに集約されています)。'.PHP_EOL.implode(PHP_EOL, $violations));
});

test('G2: env LEGAL_CONSENT_VERSION を読むのは config/legal.php だけである', function (): void {
    $violations = [];
    foreach (legalConsentScanTree(['app', 'config']) as $relative => $scan) {
        if ($scan['envName'] > 0 && $relative !== 'config/legal.php') {
            $violations[] = $relative;
        }
    }

    expect($violations)->toBe([],
        'env(LEGAL_CONSENT_VERSION) の直読を検出しました。env を読むのは config/legal.php だけです。'
        .PHP_EOL.implode(PHP_EOL, $violations));

    // 正の側: env 口が実在する (口ごと消えて vacuous green になるのを防ぐ)
    expect(legalConsentScanFile('config/legal.php')['envName'])->toBe(1);
});

test('G3: LegalConsent::version() の呼び出し元が inventory と exact-fit である', function (): void {
    $callers = [];
    foreach (legalConsentScanTree(['app']) as $relative => $scan) {
        if ($scan['versionCall'] > 0 && $relative !== LEGAL_CONSENT_SOURCE_FILE) {
            $callers[] = substr($relative, strlen('app/'));
        }
    }
    sort($callers);

    expect($callers)->toBe(LEGAL_CONSENT_VERSION_CALLERS,
        '同意バージョンの書き込み経路が増減しました。新しい経路なら '
        .'LEGAL_CONSENT_VERSION_CALLERS へ登録し、消えたなら目録から外してください '
        .'(allowlist ではなく exact-fit の目録です)。実測: '.implode(', ', $callers));
});

test('G4: プレースホルダ literal draft-1 が app/ database/ tests/ に残っていない', function (): void {
    $violations = [];
    foreach (legalConsentScanTree(['app', 'database', 'tests']) as $relative => $scan) {
        if ($scan['placeholder'] > 0 && $relative !== LEGAL_CONSENT_GATE_FILE) {
            $violations[] = $relative;
        }
    }

    expect($violations)->toBe([],
        'プレースホルダ版 draft-1 の literal を検出しました。版の出所は config/legal.php の'
        .'既定値だけです (fixture でも LegalConsent::version() を使ってください)。'
        .PHP_EOL.implode(PHP_EOL, $violations));

    // 除外した gate 自身は実際に literal を持つ (除外が空振り = 無意味な例外になっていない)
    expect(legalConsentScanFile(LEGAL_CONSENT_GATE_FILE)['placeholder'])->toBeGreaterThan(0);
});

test('到達境界: 走査母集団が空でない (ファイル数 / token 数 / 目録件数)', function (): void {
    $scanned = legalConsentScanTree(['app', 'config', 'database', 'tests']);

    expect(count($scanned))->toBeGreaterThan(0);
    expect(array_sum(array_column($scanned, 'tokens')))->toBeGreaterThan(0);
    expect(LEGAL_CONSENT_VERSION_CALLERS)->toHaveCount(3);
});

test('到達境界: 検出器が実ファイルで実際に点灯する', function (): void {
    // 走査器が壊れて常に 0 を返すと G1/G2/G4 は vacuous green になる。ここだけは必ず赤くなる。
    $source = legalConsentScanFile(LEGAL_CONSENT_SOURCE_FILE);
    expect($source['configKey'])->toBe(1);
    expect($source['versionCall'])->toBe(0); // 宣言であって呼び出しではない

    $caller = legalConsentScanFile('app/'.LEGAL_CONSENT_VERSION_CALLERS[0]);
    expect($caller['versionCall'])->toBeGreaterThan(0);
    expect($caller['configKey'])->toBe(0);
});

test('負のコントロール: 引用符の種類を問わず設定キー / env 名 / 版 literal を検出する', function (): void {
    $fixture = <<<'PHP'
    <?php
    class Fixture {
        public function run(): void {
            $a = config('legal.consent_version');
            $b = config("legal.consent_version");
            $c = env('LEGAL_CONSENT_VERSION');
            $d = env("LEGAL_CONSENT_VERSION", "draft-1");
            $e = 'draft-1';
        }
    }
    PHP;

    $scan = legalConsentScanSource($fixture);
    expect($scan['configKey'])->toBe(2);
    expect($scan['envName'])->toBe(2);
    expect($scan['placeholder'])->toBe(2);
});

test('負のコントロール: コメント / docblock 中の表記は検出しない', function (): void {
    $fixture = <<<'PHP'
    <?php
    /**
     * config('legal.consent_version') を直読してはならない。env('LEGAL_CONSENT_VERSION') も同様。
     * 既定値は draft-1 である。
     */
    class Fixture {
        // config('legal.consent_version') / draft-1 / LEGAL_CONSENT_VERSION
        public function run(): void {}
    }
    PHP;

    $scan = legalConsentScanSource($fixture);
    expect($scan['configKey'])->toBe(0);
    expect($scan['envName'])->toBe(0);
    expect($scan['placeholder'])->toBe(0);
    expect($scan['tokens'])->toBeGreaterThan(0); // 走査自体は生きている
});

test('負のコントロール: 呼び出しは検出し、use 文だけは呼び出しに数えない', function (): void {
    $called = <<<'PHP'
    <?php
    use App\Support\Legal\LegalConsent;
    class Fixture {
        public function run(): void {
            $a = LegalConsent::version();
            $b = \App\Support\Legal\LegalConsent::version();
        }
    }
    PHP;

    $importOnly = <<<'PHP'
    <?php
    use App\Support\Legal\LegalConsent;
    class Fixture {
        public function run(LegalConsent $consent): void {}
    }
    PHP;

    expect(legalConsentScanSource($called)['versionCall'])->toBe(2);
    expect(legalConsentScanSource($importOnly)['versionCall'])->toBe(0);
});

test('名前空間の隔離: billing の consent_version を巻き込まない', function (): void {
    // fixture (形として巻き込まないこと)
    $fixture = <<<'PHP'
    <?php
    class Fixture {
        public function rules(): array {
            return [
                'consent_version' => config('billing.auto_recharge.consent_version'),
            ];
        }
    }
    PHP;
    $scan = legalConsentScanSource($fixture);
    expect($scan['configKey'])->toBe(0);
    expect($scan['envName'])->toBe(0);
    expect($scan['versionCall'])->toBe(0);

    // 実ファイル (現実の別 feature を巻き込まないこと)
    foreach ([
        'app/Http/Requests/Billing/BillingCheckoutRequest.php',
        'app/Http/Requests/Onboarding/ActivatePersonalRequest.php',
        'config/billing.php',
    ] as $relative) {
        $real = legalConsentScanFile($relative);
        expect($real['configKey'])->toBe(0, $relative);
        expect($real['envName'])->toBe(0, $relative);
        expect($real['versionCall'])->toBe(0, $relative);
    }
});
