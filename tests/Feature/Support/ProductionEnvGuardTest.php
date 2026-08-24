<?php

declare(strict_types=1);

use App\Support\ExternalFakes\ExternalFakeDeclaration;
use App\Support\ProductionEnvGuard;
use Laravel\Fortify\Features;
use Tests\Support\RawEnv\RawEnvChannels;
use Tests\Support\RawEnv\RawEnvSnapshot;

beforeEach(function (): void {
    // ★実環境変数の二重判定 (T177) が入ったため、**テストの前提として 3 変数 × 3 経路を
    //   すべて未設定にする**。開発者の手元シェルや実行基盤に TESTING_FAKE_* が残っていると、
    //   本ファイルのほぼ全ケースが余分な violation で落ちる (ホスト環境依存になる)。
    //   原状復帰は afterEach が行う。
    productionEnvGuardStoreRawSnapshot(
        RawEnvSnapshot::captureAndClear(productionEnvGuardFakeFlagVariables()),
    );

    // production 必須項目の baseline (すべて有効値)。各テストで 1 項目ずつ崩す。
    config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    config(['ciphersweet.providers.string.key' => str_repeat('a', 64)]);
    config(['cashier.webhook.secret' => 'whsec_valid']);
    config(['session.secure' => true]);
    config(['app.debug' => false]);
    config(['security.hsts.enabled' => true]);
    config(['security.csp.enabled' => true]);
    config(['debug.login.user' => '']);
    config(['debug.login.password' => '']);
    config(['testing.fake_externals' => false]);
    config(['testing.fake_llm' => false]);
    config(['testing.fake_storage' => false]);
    config(['trusted_hosts.exact_hosts' => ['app.example.com']]);
    config(['trusted_hosts.wildcard_suffixes' => []]);
    config(['trusted_hosts.raw_wildcard_suffixes' => []]);
    config(['trustedproxy.proxies' => ['10.0.0.0/8']]);
    config(['trustedproxy.raw_proxies' => ['10.0.0.0/8']]);
    // パスキー設定 (T166)。**読み出し元が 2 系統に分かれる**ので取り違えないこと:
    // 実効値は passkeys.* (Fortify の上書き後)、検査専用キーは fortify.passkeys.*。
    config(['passkeys.relying_party_id' => 'app.example.com']);
    config(['passkeys.allowed_origins' => ['https://app.example.com']]);
    config(['passkeys.user_handle_secret' => str_repeat('a', 32)]);
    config(['fortify.passkeys.raw_allowed_origins' => ['https://app.example.com']]);
    config(['fortify.passkeys.user_handle_secret_declared' => true]);
});

afterEach(function (): void {
    productionEnvGuardTakeRawSnapshot()?->restore();
});

test('全 production 必須項目が埋まっていれば violations は空', function (): void {
    expect((new ProductionEnvGuard)->violations())->toBe([]);
});

test('APP_KEY が空なら violation', function (): void {
    config(['app.key' => '']);
    $errors = (new ProductionEnvGuard)->violations();
    expect($errors)->toHaveCount(1);
    expect($errors[0])->toContain('APP_KEY');
});

test('CIPHERSWEET_KEY が空なら violation', function (): void {
    config(['ciphersweet.providers.string.key' => '']);
    $errors = (new ProductionEnvGuard)->violations();
    expect($errors)->toHaveCount(1);
    expect($errors[0])->toContain('CIPHERSWEET_KEY');
});

test('STRIPE_WEBHOOK_SECRET が空なら violation', function (): void {
    config(['cashier.webhook.secret' => '']);
    $errors = (new ProductionEnvGuard)->violations();
    expect($errors)->toHaveCount(1);
    expect($errors[0])->toContain('STRIPE_WEBHOOK_SECRET');
});

test('SESSION_SECURE_COOKIE が true でないなら violation', function (): void {
    config(['session.secure' => false]);
    $errors = (new ProductionEnvGuard)->violations();
    expect($errors)->toHaveCount(1);
    expect($errors[0])->toContain('SESSION_SECURE_COOKIE');
});

test('SESSION_SECURE_COOKIE が null なら violation (auto は許容しない)', function (): void {
    config(['session.secure' => null]);
    $errors = (new ProductionEnvGuard)->violations();
    expect($errors[0])->toContain('SESSION_SECURE_COOKIE');
});

test('APP_DEBUG が true なら violation', function (): void {
    config(['app.debug' => true]);
    $errors = (new ProductionEnvGuard)->violations();
    expect($errors)->toHaveCount(1);
    expect($errors[0])->toContain('APP_DEBUG must be false');
});

test('SECURITY_HSTS_ENABLED が true でないなら violation', function (): void {
    config(['security.hsts.enabled' => false]);
    $errors = (new ProductionEnvGuard)->violations();
    expect($errors)->toHaveCount(1);
    expect($errors[0])->toContain('SECURITY_HSTS_ENABLED');
});

test('SECURITY_CSP_ENABLED が true でないなら violation', function (): void {
    config(['security.csp.enabled' => false]);
    $errors = (new ProductionEnvGuard)->violations();
    expect($errors)->toHaveCount(1);
    expect($errors[0])->toContain('SECURITY_CSP_ENABLED');
});

test('DEBUG_LOGIN_USER が production で残っていたら violation', function (): void {
    config(['debug.login.user' => 'admin']);
    $errors = (new ProductionEnvGuard)->violations();
    expect($errors)->toHaveCount(1);
    expect($errors[0])->toContain('DEBUG_LOGIN_USER');
});

test('DEBUG_LOGIN_PASSWORD が production で残っていたら violation', function (): void {
    config(['debug.login.password' => 'plain-pass']);
    $errors = (new ProductionEnvGuard)->violations();
    expect($errors)->toHaveCount(1);
    expect($errors[0])->toContain('DEBUG_LOGIN');
});

test('TESTING_FAKE_EXTERNALS が true なら violation', function (): void {
    config(['testing.fake_externals' => true]);
    $errors = (new ProductionEnvGuard)->violations();
    expect($errors)->toHaveCount(1);
    expect($errors[0])->toContain('TESTING_FAKE_EXTERNALS');
});

test('TESTING_FAKE_LLM が true なら violation', function (): void {
    config(['testing.fake_llm' => true]);
    $errors = (new ProductionEnvGuard)->violations();
    expect($errors)->toHaveCount(1);
    expect($errors[0])->toContain('TESTING_FAKE_LLM');
});

test('TESTING_FAKE_STORAGE が true なら violation', function (): void {
    config(['testing.fake_storage' => true]);
    $errors = (new ProductionEnvGuard)->violations();
    expect($errors)->toHaveCount(1);
    expect($errors[0])->toContain('TESTING_FAKE_STORAGE');
});

test('TrustHosts allowlist が空なら violation', function (): void {
    config(['trusted_hosts.exact_hosts' => []]);
    config(['trusted_hosts.wildcard_suffixes' => []]);
    config(['trusted_hosts.raw_wildcard_suffixes' => []]);
    $errors = (new ProductionEnvGuard)->violations();
    expect($errors)->toHaveCount(1);
    expect($errors[0])->toContain('TrustHosts allowlist is empty');
});

test('TrustHosts の不正 wildcard 書式なら violation', function (): void {
    config(['trusted_hosts.exact_hosts' => ['app.example.com']]);
    config(['trusted_hosts.wildcard_suffixes' => []]);
    config(['trusted_hosts.raw_wildcard_suffixes' => ['preview.example.com']]);
    $errors = (new ProductionEnvGuard)->violations();
    expect($errors)->toHaveCount(1);
    expect($errors[0])->toContain('leading dot');
});

test('複数 violation を全件返す', function (): void {
    config(['cashier.webhook.secret' => '']);
    config(['session.secure' => false]);
    $errors = (new ProductionEnvGuard)->violations();
    expect($errors)->toHaveCount(2);
});

test('enforce() は violations あれば RuntimeException', function (): void {
    config(['cashier.webhook.secret' => '']);
    expect(fn () => (new ProductionEnvGuard)->enforce())
        ->toThrow(RuntimeException::class, 'Production env baseline violations');
});

test('enforce() は violations なしなら例外なし', function (): void {
    expect(fn () => (new ProductionEnvGuard)->enforce())->not->toThrow(RuntimeException::class);
});

// --- production:preflight コマンド (guard へ委譲 + --strict) ---

test('production:preflight は非 production では skip して成功する', function (): void {
    $this->artisan('production:preflight')->assertSuccessful();
});

test('production:preflight --strict は非 production で fail する', function (): void {
    $this->artisan('production:preflight', ['--strict' => true])->assertFailed();
});

test('production:preflight は production で violations があれば fail する', function (): void {
    config(['app.env' => 'production']);
    config(['cashier.webhook.secret' => '']);
    $this->artisan('production:preflight')->assertFailed();
});

test('production:preflight は production で全項目通過なら成功する', function (): void {
    config(['app.env' => 'production']);
    // preflight は operations:check-mail-config も呼ぶため mail baseline も有効値にする
    config(['mail.default' => 'ses']);
    config(['mail.from.address' => 'noreply@app.example.com']);
    config(['app.url' => 'https://app.example.com']);
    $this->artisan('production:preflight')->assertSuccessful();
});

test('production:preflight は production で mail 設定不備 (MAIL_MAILER=log) なら fail する', function (): void {
    config(['app.env' => 'production']);
    config(['mail.default' => 'log']);
    config(['mail.from.address' => 'noreply@app.example.com']);
    config(['app.url' => 'https://app.example.com']);
    $this->artisan('production:preflight')->assertFailed();
});

/*
 * TrustProxies allowlist (client IP の信頼境界。audit-cycle-2 High-2 / T108 S5)。
 * 未宣言のまま production を起動すると fail-fast するのは **意図した破壊的変更**。
 */

test('TRUSTED_PROXIES が未設定なら violation', function (): void {
    config(['trustedproxy.proxies' => []]);
    config(['trustedproxy.raw_proxies' => ['']]);
    $errors = (new ProductionEnvGuard)->violations();
    expect($errors)->toHaveCount(1);
    expect($errors[0])->toContain('TRUSTED_PROXIES is not set');
});

test('TRUSTED_PROXIES に * が含まれるなら violation (XFF 偽装が通る)', function (): void {
    config(['trustedproxy.proxies' => []]);
    config(['trustedproxy.raw_proxies' => ['*']]);
    $errors = (new ProductionEnvGuard)->violations();
    expect($errors)->toHaveCount(1);
    expect($errors[0])->toContain('Trusting every address');
});

test('TRUSTED_PROXIES に REMOTE_ADDR が含まれるなら violation', function (): void {
    config(['trustedproxy.proxies' => ['REMOTE_ADDR']]);
    config(['trustedproxy.raw_proxies' => ['REMOTE_ADDR']]);
    $errors = (new ProductionEnvGuard)->violations();
    expect($errors)->toHaveCount(1);
    expect($errors[0])->toContain('REMOTE_ADDR');
});

test('TRUSTED_PROXIES に書式不正が含まれるなら violation (config 段の silent drop を表面化)', function (): void {
    // config 段では落ちるので proxies には現れない = raw を見ないと検知できない
    config(['trustedproxy.proxies' => ['10.0.0.0/8']]);
    config(['trustedproxy.raw_proxies' => ['10.0.0.0/8', '999.999.999.999/99']]);
    $errors = (new ProductionEnvGuard)->violations();
    expect($errors)->toHaveCount(1);
    expect($errors[0])->toContain('invalid value');
});

test('TRUSTED_PROXIES に none と他の値を併記したら violation (曖昧宣言)', function (): void {
    config(['trustedproxy.proxies' => ['10.0.0.0/8']]);
    config(['trustedproxy.raw_proxies' => ['none', '10.0.0.0/8']]);
    $errors = (new ProductionEnvGuard)->violations();
    expect($errors)->toHaveCount(1);
    expect($errors[0])->toContain('must be declared alone');
});

test('TRUSTED_PROXIES=none 単独なら violations は空 (プロキシ無し構成の明示宣言)', function (): void {
    config(['trustedproxy.proxies' => []]);
    config(['trustedproxy.raw_proxies' => ['none']]);
    expect((new ProductionEnvGuard)->violations())->toBe([]);
});

test('TRUSTED_PROXIES 未設定なら enforce() が起動を止める (production の fail-fast)', function (): void {
    config(['trustedproxy.proxies' => []]);
    config(['trustedproxy.raw_proxies' => ['']]);

    expect(fn () => (new ProductionEnvGuard)->enforce())
        ->toThrow(RuntimeException::class, 'TRUSTED_PROXIES is not set');
});

/*
 * パスキー (WebAuthn) 設定 (T166)。`PASSKEYS_USER_HANDLE_SECRET` 未宣言のまま production を
 * 起動すると fail-fast するのは **意図した破壊的変更**。検査は Features::passkeys() が
 * 有効なときだけ走る。
 */

test('passkeys feature が有効 (検査が空振りしていないことの前提固定)', function (): void {
    expect(Features::enabled(Features::passkeys()))->toBeTrue();
});

test('PASSKEYS_USER_HANDLE_SECRET が未宣言なら violation', function (): void {
    config(['fortify.passkeys.user_handle_secret_declared' => false]);
    $errors = (new ProductionEnvGuard)->violations();
    expect($errors)->toHaveCount(1);
    expect($errors[0])->toContain('PASSKEYS_USER_HANDLE_SECRET is not set');
});

test('導出鍵が 32 文字未満なら violation', function (): void {
    config(['passkeys.user_handle_secret' => str_repeat('a', 31)]);
    $errors = (new ProductionEnvGuard)->violations();
    expect($errors)->toHaveCount(1);
    expect($errors[0])->toContain('shorter than 32 characters');
});

test('接続元が身元の識別子に属さないなら violation', function (): void {
    config(['passkeys.allowed_origins' => ['https://evil.example.net']]);
    config(['fortify.passkeys.raw_allowed_origins' => ['https://evil.example.net']]);
    $errors = (new ProductionEnvGuard)->violations();
    expect($errors)->toHaveCount(1);
    expect($errors[0])->toContain('does not belong to');
});

test('接続元の宣言に空要素があれば violation (末尾カンマの表面化)', function (): void {
    config(['fortify.passkeys.raw_allowed_origins' => ['https://app.example.com', '']]);
    $errors = (new ProductionEnvGuard)->violations();
    expect($errors)->toHaveCount(1);
    // 例外文は位置 (1 始まり) で指す。設定の生値は載せない (T216 施策 B)。
    expect($errors[0])->toContain('entry #2 is empty');
});

test('passkeys を無効化すると不正設定でも violation は出ない (キルスイッチ)', function (): void {
    // Features::passkeys([...]) は options 付きで呼ぶと fortify-options を書き換えるため、
    // 無効化には使わない。Features::enabled() の実装 (fortify.features の in_array) に合わせて外す。
    /** @var array<int, mixed> $features */
    $features = (array) config('fortify.features');
    config(['fortify.features' => array_values(array_diff($features, ['passkeys']))]);

    config(['fortify.passkeys.user_handle_secret_declared' => false]);
    config(['passkeys.relying_party_id' => '']);

    expect((new ProductionEnvGuard)->violations())->toBe([]);
});

test('passkeys.allowed_origins に非 string が混ざったら violation (fail-closed)', function (): void {
    config(['passkeys.allowed_origins' => ['https://app.example.com', 123]]);
    $errors = (new ProductionEnvGuard)->violations();
    expect($errors)->toHaveCount(1);
    expect($errors[0])->toContain('must be lists of strings');
});

test('fortify.passkeys.raw_allowed_origins に非 string が混ざったら violation (読み出し元の取り違え検出)', function (): void {
    config(['fortify.passkeys.raw_allowed_origins' => ['https://app.example.com', 123]]);
    $errors = (new ProductionEnvGuard)->violations();
    expect($errors)->toHaveCount(1);
    expect($errors[0])->toContain('must be lists of strings');
});

test('passkeys.allowed_origins が配列ですらないなら violation', function (): void {
    config(['passkeys.allowed_origins' => 'https://app.example.com']);
    $errors = (new ProductionEnvGuard)->violations();
    expect($errors)->toHaveCount(1);
    expect($errors[0])->toContain('must be lists of strings');
});

test('passkeys.allowed_origins が null なら violation', function (): void {
    config(['passkeys.allowed_origins' => null]);
    $errors = (new ProductionEnvGuard)->violations();
    expect($errors)->toHaveCount(1);
    expect($errors[0])->toContain('must be lists of strings');
});

/*
 * 実環境変数の二重判定 (T177 施策 3)。
 *
 * 設定キャッシュを作った環境と出荷先が食い違うと、キャッシュ上は false でも、
 * キャッシュが失われた起動で環境変数が読み直されて本番で偽物が立ちうる。
 * そこで設定値とは独立に $_SERVER / $_ENV / getenv() の 3 経路を見る。
 *
 * ★原値の退避と復元は `Tests\Support\RawEnv\RawEnvSnapshot` が担う (本ファイルは
 *   3 面を直接触らない。正典 v1 の i1 = 1 つの部品への集約)。
 *   putenv は空文字と未設定の差が環境で揺れるため、部品側が存在と値を別に持って戻す。
 * ★**指定しなかった経路はテスト中だけ明示的に未設定化する** (`RawEnvChannels::none()` を
 *   起点に指定した面だけを足す)。実行環境に同じ変数が残っていると
 *   「経路ごとに独立に検査する」という前提が崩れ、違反件数がホスト依存になる。
 */

/**
 * 二重判定の対象になる環境変数 (宣言が正本)。
 *
 * @return list<string>
 */
function productionEnvGuardFakeFlagVariables(): array
{
    return array_values(ExternalFakeDeclaration::FLAG_ENVIRONMENT_VARIABLES);
}

/**
 * フックをまたぐ退避の預け入れ / 取り出し (Pest の TestCase へ動的プロパティを生やさない)。
 *
 * ★`take()` は**必ず保存スロットを空にして**返す。`beforeEach` が退避の途中で失敗したとき、
 *   `afterEach` が前ケースの退避を再利用する経路を構造的に消すためである。
 * ★ここが持つのは**部品が作った退避の値**だけで、退避・復元のロジックは持たない
 *   (3 面の操作は `Tests\Support\RawEnv\RawEnvSnapshot` へ集約してある。正典 v1 の i1)。
 */
function productionEnvGuardStoreRawSnapshot(RawEnvSnapshot $snapshot): void
{
    productionEnvGuardRawSnapshotSlot($snapshot, false);
}

function productionEnvGuardTakeRawSnapshot(): ?RawEnvSnapshot
{
    return productionEnvGuardRawSnapshotSlot(null, true);
}

function productionEnvGuardRawSnapshotSlot(?RawEnvSnapshot $store, bool $take): ?RawEnvSnapshot
{
    /** @var ?RawEnvSnapshot $slot */
    static $slot = null;

    if ($store !== null) {
        $slot = $store;

        return null;
    }

    if (! $take) {
        return $slot;
    }

    $taken = $slot;
    $slot = null;

    return $taken;
}

test('config が false でも $_SERVER に true が残っていれば violation', function (): void {
    RawEnvSnapshot::with(
        ['TESTING_FAKE_EXTERNALS' => RawEnvChannels::none()->withServer('true')],
        function (): void {
            $errors = (new ProductionEnvGuard)->violations();
            expect($errors)->toHaveCount(1);
            expect($errors[0])->toContain('TESTING_FAKE_EXTERNALS');
            expect($errors[0])->toContain('$_SERVER');
        });
});

test('config が false でも $_ENV に true が残っていれば violation', function (): void {
    RawEnvSnapshot::with(
        ['TESTING_FAKE_LLM' => RawEnvChannels::none()->withEnv('true')],
        function (): void {
            $errors = (new ProductionEnvGuard)->violations();
            expect($errors)->toHaveCount(1);
            expect($errors[0])->toContain('$_ENV');
        });
});

test('config が false でも getenv() に true が残っていれば violation', function (): void {
    RawEnvSnapshot::with(
        ['TESTING_FAKE_STORAGE' => RawEnvChannels::none()->withProcess('true')],
        function (): void {
            $errors = (new ProductionEnvGuard)->violations();
            expect($errors)->toHaveCount(1);
            expect($errors[0])->toContain('getenv()');
        });
});

test('3 経路とも未設定なら violation は出ない', function (): void {
    // beforeEach が 3 変数 × 3 経路を未設定にしている。ここでは明示的に 1 変数を
    // 「どの経路も指定しない」形で通し、未設定が判定対象にならないことを固定する。
    RawEnvSnapshot::with(
        ['TESTING_FAKE_EXTERNALS' => RawEnvChannels::none()],
        function (): void {
            expect((new ProductionEnvGuard)->violations())->toBe([]);
        });
});

test('無効と読める値 (false / 0 / 空文字) では violation は出ない', function (): void {
    foreach (['false', 'FALSE', '(false)', '0', 'off', 'no', 'null', ''] as $value) {
        RawEnvSnapshot::with(
            ['TESTING_FAKE_EXTERNALS' => RawEnvChannels::none()->withServer($value)],
            function () use ($value): void {
                expect((new ProductionEnvGuard)->violations())->toBe([], "無効と読めるはずの値: '{$value}'");
            });
    }
});

test('解釈できない値 (maybe / 非文字列) は安全側で violation', function (): void {
    RawEnvSnapshot::with(
        ['TESTING_FAKE_EXTERNALS' => RawEnvChannels::none()->withServer('maybe')],
        function (): void {
            expect((new ProductionEnvGuard)->violations())->toHaveCount(1);
        });

    // 非文字列 (配列) も黙って捨てず違反にする。
    // ★退避と復元は同じ部品に乗せる (原値があった場合の戻し漏れを作らない)。
    RawEnvSnapshot::with(
        ['TESTING_FAKE_EXTERNALS' => RawEnvChannels::none()->withServer(['true'])],
        function (): void {
            expect((new ProductionEnvGuard)->violations())->toHaveCount(1);
        });
});

test('未設定 / 空文字 / false を別ケースとして固定する', function (): void {
    $variable = 'TESTING_FAKE_STORAGE';

    // 未設定: 判定対象にしない
    expect((new ProductionEnvGuard)->violations())->toBe([]);

    // 空文字: 無効と読む
    RawEnvSnapshot::with(
        [$variable => RawEnvChannels::none()->withServer('')],
        function (): void {
            expect((new ProductionEnvGuard)->violations())->toBe([]);
        });

    // 'false': 無効と読む
    RawEnvSnapshot::with(
        [$variable => RawEnvChannels::none()->withServer('false')],
        function (): void {
            expect((new ProductionEnvGuard)->violations())->toBe([]);
        });
});
