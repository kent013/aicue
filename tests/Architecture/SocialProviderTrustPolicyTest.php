<?php

declare(strict_types=1);

/*
 * SSO provider の信頼宣言に関する不変条件 (deny-by-default)。
 *
 * `config/template.php` の `social_providers` に並ぶ provider は、
 *   - capability   : recent-auth の step-up satisfier としての保証レベル
 *   - email_trust  : IdP の主張する email を検証済みとして扱ってよいか
 * の 2 つを **必ず明示宣言** する。宣言を書かないまま provider を足すと
 * fail-closed 側 (identity_only / unconfirmed) に倒れて機能が静かに劣化するため、
 * 「宣言漏れ」を CI で先に落とす (既存 SsrfPinBoundaryTest / RecentAuthRouteTest と同型)。
 *
 * google の email_trust = confirmed は現行挙動 (SSO 登録で email_verified_at が立つ) の pin。
 * 緩める / 変えるときはこのテストごと意図的に変更すること。
 *
 * DB 不要のため Architecture スイート (TestCase のみ) に置く。
 */

use App\Enums\EmailTrustLevel;
use App\Enums\ProviderCapability;
use App\Services\Auth\EmailTrust\ConfirmedEmailTrustPolicy;
use App\Services\Auth\EmailTrust\EmailTrustPolicyResolver;
use App\Services\Auth\EmailTrust\UnconfirmedEmailTrustPolicy;

/**
 * @return array<string, mixed>
 */
function socialProvidersConfig(): array
{
    return config()->array('template.social_providers');
}

test('全 SSO provider が capability / email_trust を明示宣言している', function (): void {
    $providers = socialProvidersConfig();

    expect($providers)->not->toBeEmpty('social_providers が空 (config の読み込み経路が壊れている?)');

    foreach ($providers as $provider => $definition) {
        expect($definition)->toBeArray("provider '{$provider}' の定義が配列でない");

        // capability: 未宣言なら step-up satisfier から静かに外れる (既存 fail-closed の明示化)
        expect(array_key_exists('capability', $definition))
            ->toBeTrue("provider '{$provider}' に capability 宣言が無い (config/template.php へ追記すること)");
        $capability = $definition['capability'];
        expect(is_string($capability) ? ProviderCapability::tryFrom($capability) : null)
            ->not->toBeNull("provider '{$provider}' の capability が ProviderCapability の値でない");

        // email_trust: 未宣言なら email_verified_at が立たなくなる (nOAuth 対策の fail-closed)
        expect(array_key_exists('email_trust', $definition))
            ->toBeTrue("provider '{$provider}' に email_trust 宣言が無い (config/template.php へ追記すること)");
        $emailTrust = $definition['email_trust'];
        expect(is_string($emailTrust) ? EmailTrustLevel::tryFrom($emailTrust) : null)
            ->not->toBeNull("provider '{$provider}' の email_trust が EmailTrustLevel の値でない");
    }
});

test('google の email_trust は confirmed に pin されている', function (): void {
    // 現行挙動 (SSO 登録時に email_verified_at が立つ) を固定する。
    expect(config('template.social_providers.google.email_trust'))->toBe('confirmed');
});

test('EmailTrustLevel::fromRaw は未宣言・解釈不能を Unconfirmed に倒す', function (mixed $raw): void {
    expect(EmailTrustLevel::fromRaw($raw))->toBe(EmailTrustLevel::Unconfirmed);
})->with([
    '未宣言 (null)' => [null],
    '未知の文字列' => ['nonsense'],
    '配列' => [['x']],
    '真偽値' => [true],
    '整数' => [1],
    '空文字' => [''],
]);

test('EmailTrustLevel::fromRaw は confirmed 宣言のみ Confirmed を返す', function (): void {
    expect(EmailTrustLevel::fromRaw('confirmed'))->toBe(EmailTrustLevel::Confirmed)
        ->and(EmailTrustLevel::fromRaw('unconfirmed'))->toBe(EmailTrustLevel::Unconfirmed);
});

test('EmailTrustPolicyResolver は宣言に従い policy を返す (未知 provider は Unconfirmed)', function (): void {
    $resolver = app(EmailTrustPolicyResolver::class);

    expect($resolver->for('google'))->toBeInstanceOf(ConfirmedEmailTrustPolicy::class)
        // config に存在しない provider は fail-closed
        ->and($resolver->for('unknown-provider'))->toBeInstanceOf(UnconfirmedEmailTrustPolicy::class);

    config(['template.social_providers.google.email_trust' => 'unconfirmed']);
    expect($resolver->for('google'))->toBeInstanceOf(UnconfirmedEmailTrustPolicy::class);
});
