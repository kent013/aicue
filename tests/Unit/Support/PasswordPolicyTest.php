<?php

declare(strict_types=1);

use App\Support\PasswordPolicy;
use Illuminate\Validation\Rules\Password;

/*
 * パスワード強度ポリシーの SSOT (App\Support\PasswordPolicy) を固定する。
 * rules()/describe() の決定論と、非テスト環境で uncompromised (HIBP) が有効な構成を
 * 実 HIBP 照会を起こさず (reflection で protected プロパティを検査して) 固定する。
 * あわせて Password::defaults() が本ポリシーへ配線されていることも検査する。
 */

/**
 * Password ルールの protected プロパティを HIBP 照会なしで読む。
 */
function readPasswordRuleProperty(Password $rule, string $property): mixed
{
    $ref = new ReflectionProperty(Password::class, $property);

    return $ref->getValue($rule);
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

test('Password::defaults() は PasswordPolicy::rule() に配線されている', function (): void {
    $default = Password::default();

    expect($default)->toBeInstanceOf(Password::class);
    expect(readPasswordRuleProperty($default, 'min'))->toBe(PasswordPolicy::MIN_LENGTH);
    expect(readPasswordRuleProperty($default, 'mixedCase'))->toBeTrue();
    expect(readPasswordRuleProperty($default, 'numbers'))->toBeTrue();
});
