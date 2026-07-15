<?php

declare(strict_types=1);

use App\Support\PasswordPolicy;
use Illuminate\Validation\Rules\Password;

/*
 * パスワード強度ポリシーの SSOT (App\Support\PasswordPolicy) を固定する。
 * rules()/describe() の決定論と、HIBP 漏洩照合 (uncompromised) の env matrix を
 * 実 HIBP 照会を起こさず (public 述語 shouldCheckPwned() の振る舞い + reflection) 固定する。
 * あわせて Password::defaults() が本ポリシーへ配線されていることも検査する。
 *
 * production で fake_externals を有効化できない (= 本番で HIBP が外れない) 経路は
 * tests/Feature/Support/ProductionEnvGuardTest.php が既に担保しており、
 * 本ファイルは述語レベルの fail-secure 不変条件を固定する (二重固定)。
 */

/**
 * Password ルールの protected プロパティを HIBP 照会なしで読む。
 */
function readPasswordRuleProperty(Password $rule, string $property): mixed
{
    $ref = new ReflectionProperty(Password::class, $property);

    return $ref->getValue($rule);
}

/**
 * APP_ENV を一時的に差し替えて assertion を実行し、差し替え前の env へ必ず復元する。
 * 並列実行のプロセス env 汚染を防ぐ。名前衝突回避のためファイル固有名にする。
 */
function withPasswordPolicyAppEnv(string $env, Closure $assertion): void
{
    $original = app()->environment(); // ハードコードせず元の env を保存
    app()->instance('env', $env);
    try {
        $assertion();
    } finally {
        app()->instance('env', $original);
    }
}

test('describe() は日本語要件文を決定論的に返す', function (): void {
    expect(PasswordPolicy::describe())->toBe([
        '12文字以上',
        '大文字・小文字を含む',
        '数字を含む',
    ]);
});

test('rules() は min12 + mixedCase + numbers を満たす Password ルールを返す', function (): void {
    $rules = PasswordPolicy::rules();

    expect($rules)->toHaveCount(1);
    $rule = $rules[0];
    expect($rule)->toBeInstanceOf(Password::class);

    expect(readPasswordRuleProperty($rule, 'min'))->toBe(12);
    expect(readPasswordRuleProperty($rule, 'mixedCase'))->toBeTrue();
    expect(readPasswordRuleProperty($rule, 'numbers'))->toBeTrue();
});

test('rules() はテスト環境では uncompromised (HIBP) を含めない', function (): void {
    // runningUnitTests() が true の通常テスト実行では HIBP 照会を外す (flaky/外部依存回避)。
    $rule = PasswordPolicy::rules()[0];

    expect(readPasswordRuleProperty($rule, 'uncompromised'))->toBeFalse();
});

test('rules() は非テスト環境で uncompromised (HIBP) を含む', function (): void {
    // env を非 testing に差し替えて runningUnitTests() を false にし、本番相当の構成を検査する。
    // protected プロパティの reflection 読みなので実 HIBP 照会は発生しない。
    app()->instance('env', 'production');

    try {
        $rule = PasswordPolicy::rules()[0];
        expect(readPasswordRuleProperty($rule, 'uncompromised'))->toBeTrue();
        // 強度本体 (min12 + mixedCase + numbers) は環境に依らず不変。
        expect(readPasswordRuleProperty($rule, 'min'))->toBe(12);
        expect(readPasswordRuleProperty($rule, 'mixedCase'))->toBeTrue();
        expect(readPasswordRuleProperty($rule, 'numbers'))->toBeTrue();
    } finally {
        app()->instance('env', 'testing');
    }
});

test('shouldCheckPwned() は production で true (fail-secure 不変条件)', function (): void {
    withPasswordPolicyAppEnv('production', fn () => expect(PasswordPolicy::shouldCheckPwned())->toBeTrue());
});

test('shouldCheckPwned() は未知 env (staging 等の実運用ミラー) で既定 true (fail-secure denylist)', function (string $env): void {
    withPasswordPolicyAppEnv($env, fn () => expect(PasswordPolicy::shouldCheckPwned())->toBeTrue());
})->with(['staging', 'preprod', 'review']);

test('shouldCheckPwned() は既知の開発/テスト env で false', function (string $env): void {
    withPasswordPolicyAppEnv($env, fn () => expect(PasswordPolicy::shouldCheckPwned())->toBeFalse());
})->with(['local', 'testing', 'bughunt.local']);

test('shouldCheckPwned() は fake_externals=true の denylist env で false (brief 要件を推移的に固定)', function (): void {
    // fake が install され得る env (local) は denylist に含まれ、fake_externals に関係なく false。
    $original = config('testing.fake_externals');
    config(['testing.fake_externals' => true]);
    try {
        withPasswordPolicyAppEnv('local', fn () => expect(PasswordPolicy::shouldCheckPwned())->toBeFalse());
    } finally {
        config(['testing.fake_externals' => $original]);
    }
});

test('shouldCheckPwned() は PasswordPolicy が fake_externals に結合しないことを固定 (fail-secure decoupling)', function (): void {
    // denylist 非該当 env (staging) に stray fake_externals=true を設定しても HIBP は ON のまま。
    // (staging は fake allowlist 外で fake が install されないため、ここで OFF にするのは fail-open)
    $original = config('testing.fake_externals');
    config(['testing.fake_externals' => true]);
    try {
        withPasswordPolicyAppEnv('staging', fn () => expect(PasswordPolicy::shouldCheckPwned())->toBeTrue());
    } finally {
        config(['testing.fake_externals' => $original]);
    }
});

test('rule() は述語結果を uncompromised 付与へ配線している (reflection 最小 1 本・補助)', function (): void {
    // 既定 testing env では述語 false → uncompromised 非付与。production では付与。
    expect(readPasswordRuleProperty(PasswordPolicy::rule(), 'uncompromised'))->toBeFalse();
    withPasswordPolicyAppEnv('production', fn () => expect(
        readPasswordRuleProperty(PasswordPolicy::rule(), 'uncompromised')
    )->toBeTrue());
});

test('Password::defaults() は PasswordPolicy::rule() に配線されている', function (): void {
    $default = Password::default();

    expect($default)->toBeInstanceOf(Password::class);
    expect(readPasswordRuleProperty($default, 'min'))->toBe(PasswordPolicy::MIN_LENGTH);
    expect(readPasswordRuleProperty($default, 'mixedCase'))->toBeTrue();
    expect(readPasswordRuleProperty($default, 'numbers'))->toBeTrue();
});
