<?php

declare(strict_types=1);

use App\Providers\BughuntFakesServiceProvider;
use App\Services\Captcha\RecaptchaVerifier;
use App\Services\Captcha\Testing\RecaptchaVerifierTestFake;
use App\Support\ExternalFakes\ExternalFakeDeclaration;
use Illuminate\Support\Facades\Http;

/*
 * captcha 到達点 (Google siteverify) の fake 配線を**外向き通信の有無**で固定する。
 *
 * ★負のコントロール (テスト 2) が本ファイルの要である。テスト 1 だけだと
 *   「そもそも外へ出ない状況」を検査しているだけになりうる。
 * ★環境 / flag の退避・復元は `ExternalFakeWiringInvariantTest` の 3-2 / 3-3 と同じ形
 *   (`$this->app['env']` と `config($flag)` を try/finally で戻す) を使う。
 *   共通 helper は新設しない。
 */

/** siteverify を模擬応答させる (実通信を発生させない)。 */
function recaptchaFakeSiteverify(): void
{
    Http::fake([
        'https://www.google.com/recaptcha/api/siteverify' => Http::response([
            'success' => true,
            'hostname' => (string) parse_url((string) config('app.url'), PHP_URL_HOST),
        ]),
    ]);
}

test('fake 配線時は secret があっても Google siteverify を叩かずに true を返す', function (): void {
    $flag = ExternalFakeDeclaration::EXTERNALS_FLAG;
    $originalFlag = config($flag);
    $originalEnvironment = $this->app['env'];
    $originalSecret = config('services.recaptcha.secret_key');

    try {
        recaptchaFakeSiteverify();

        $this->app['env'] = 'bughunt.local';
        config([$flag => true, 'services.recaptcha.secret_key' => 'dummy-secret']);

        (new BughuntFakesServiceProvider($this->app))->register();

        $verifier = app(RecaptchaVerifier::class);

        expect($verifier::class)->toBe(RecaptchaVerifierTestFake::class)
            ->and($verifier->verify('token', '203.0.113.1'))->toBeTrue();

        Http::assertNothingSent();
    } finally {
        config([$flag => $originalFlag, 'services.recaptcha.secret_key' => $originalSecret]);
        $this->app['env'] = $originalEnvironment;
    }
});

test('flag off では secret がある限り siteverify へ 1 回だけ出る (負のコントロール)', function (): void {
    $flag = ExternalFakeDeclaration::EXTERNALS_FLAG;
    $originalSecret = config('services.recaptcha.secret_key');

    try {
        recaptchaFakeSiteverify();

        // flag は既定 false のまま (fake を bind しない)。
        expect(config($flag))->toBeFalse();

        config(['services.recaptcha.secret_key' => 'dummy-secret']);

        $verifier = app(RecaptchaVerifier::class);

        expect($verifier::class)->toBe(RecaptchaVerifier::class)
            ->and($verifier->verify('token', '203.0.113.1'))->toBeTrue();

        Http::assertSentCount(1);
        Http::assertSent(static fn ($request): bool => $request->url() === 'https://www.google.com/recaptcha/api/siteverify');
    } finally {
        config(['services.recaptcha.secret_key' => $originalSecret]);
    }
});

test('secret 未設定なら fake の有無に関わらず外部へ出ない (現状の追認)', function (): void {
    $originalSecret = config('services.recaptcha.secret_key');

    try {
        recaptchaFakeSiteverify();

        config(['services.recaptcha.secret_key' => null]);

        // secret 未設定は非 production で fail-open。外部通信は発生しない
        // = 「bug-hunt から実 Google へ出る」のは secret が設定された環境に限る。
        expect(app(RecaptchaVerifier::class)->verify('token', '203.0.113.1'))->toBeTrue();

        Http::assertNothingSent();
    } finally {
        config(['services.recaptcha.secret_key' => $originalSecret]);
    }
});
