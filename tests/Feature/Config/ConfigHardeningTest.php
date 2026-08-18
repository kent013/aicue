<?php

declare(strict_types=1);

/*
 * config 横断ハードニングの不変条件を固定する。
 *
 * env デフォルト分岐 ('fail-close' 等) は config() では検査できない
 * (phpunit.xml / .env の値が挿さるため)。$_SERVER / $_ENV / putenv を直接退避→復元して
 * config ファイルを再評価する (Laravel の env() は ServerConst / EnvConst / Putenv の
 * 3 adapter を live に読むため、いずれか 1 つでも残ると .env.testing 値が漏れる)。
 */

/**
 * 指定の env 変数を差し替えて config ファイルを再評価する。
 *
 * @param  array<string, string|null>  $env  null は unset
 * @return array<string, mixed>
 */
function evaluateConfigFileWithEnv(string $configFile, array $env): array
{
    $previous = [];
    foreach ($env as $key => $value) {
        $getenv = getenv($key);
        $previous[$key] = [$_SERVER[$key] ?? null, $_ENV[$key] ?? null, $getenv === false ? null : $getenv];
        if ($value === null) {
            unset($_SERVER[$key], $_ENV[$key]);
            putenv($key);
        } else {
            $_SERVER[$key] = $value;
            $_ENV[$key] = $value;
            putenv("{$key}={$value}");
        }
    }

    try {
        $config = require base_path("config/{$configFile}");
        expect($config)->toBeArray();

        /** @var array<string, mixed> $config */
        return $config;
    } finally {
        foreach ($previous as $key => [$serverValue, $envValue, $putenvValue]) {
            if ($serverValue === null) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $serverValue;
            }
            if ($envValue === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $envValue;
            }
            if ($putenvValue === null) {
                putenv($key);
            } else {
                putenv("{$key}={$putenvValue}");
            }
        }
    }
}

// ========== session: secure cookie の production fail-close ==========

test('production 模擬で SESSION_SECURE_COOKIE 未設定なら session.secure=true (fail-close)', function (): void {
    $config = evaluateConfigFileWithEnv('session.php', [
        'APP_ENV' => 'production',
        'SESSION_SECURE_COOKIE' => null,
    ]);

    expect($config['secure'])->toBeTrue();
});

test('local 模擬で SESSION_SECURE_COOKIE 未設定なら session.secure=false', function (): void {
    $config = evaluateConfigFileWithEnv('session.php', [
        'APP_ENV' => 'local',
        'SESSION_SECURE_COOKIE' => null,
    ]);

    expect($config['secure'])->toBeFalse();
});

// ========== session: serialization は json 固定 ==========

test('session.serialization は json 固定 (gadget-chain 攻撃面の排除)', function (): void {
    expect(config('session.serialization'))->toBe('json');
});

// ========== app: locale 既定は ja ==========

test('APP_LOCALE 未設定なら app.locale=ja', function (): void {
    $config = evaluateConfigFileWithEnv('app.php', [
        'APP_LOCALE' => null,
    ]);

    expect($config['locale'])->toBe('ja');
});

// ========== app: mass_assignment_strict の環境別デフォルト ==========

test('mass_assignment_strict は production では既定 OFF (silent drop 維持)', function (): void {
    $config = evaluateConfigFileWithEnv('app.php', [
        'APP_ENV' => 'production',
        'MASS_ASSIGNMENT_STRICT' => null,
    ]);

    expect($config['mass_assignment_strict'])->toBeFalse();
});

test('mass_assignment_strict は非 production では既定 ON', function (): void {
    $config = evaluateConfigFileWithEnv('app.php', [
        'APP_ENV' => 'local',
        'MASS_ASSIGNMENT_STRICT' => null,
    ]);

    expect($config['mass_assignment_strict'])->toBeTrue();
});

// ========== cache: カスタム storage store は持たない ==========

test('cache.stores にカスタム storage store が存在しない', function (): void {
    // 'storage' driver は Laravel 13 の framework base config (LoadConfiguration の
    // mergeableOptions['cache']=['stores']) が実行時にマージするため config() では常に出る。
    // ここで固定したいのは「テンプレートの config/cache.php が独自に storage store を
    // 宣言していない」という不変条件なので、他の assertion と同じく config ファイルを直接評価する。
    $config = evaluateConfigFileWithEnv('cache.php', []);

    expect($config['stores']['storage'] ?? null)->toBeNull();
});

// ========== cache: 逆シリアライズの許可一覧を持たない ==========

test('config/cache.php は serializable_classes を false で宣言している', function (): void {
    // ★`false` と「キー欠落」は等価ではない。CacheManager は
    //   `config['cache.serializable_classes'] ?? null` を読み、各 store は
    //   `if ($this->serializableClasses !== null)` のときだけ allowed_classes を渡す。
    //   キーを消すと制限なしの unserialize() に戻る = fail-open。
    //   したがって「宣言が存在すること」と「値が false であること」を分けて固定する。
    $config = evaluateConfigFileWithEnv('cache.php', []);

    expect(array_key_exists('serializable_classes', $config))->toBeTrue(
        'serializable_classes の宣言が消えると Laravel は制限なしの unserialize() に戻る');
    expect($config['serializable_classes'])->toBeFalse(
        'クラス許可一覧は作らない (lctl 標準形 v1 / AGENTS.md セキュリティ不変条件 11)');
});

// ========== prism-prompt: テンプレートのオブジェクトキャッシュを持たない (T228) ==========

test('config/prism-prompt.php は cache.enabled を false で宣言している (env で開かない)', function (): void {
    // ★同梱パッケージの PromptTemplate::fromYaml() は PromptTemplate オブジェクトそのものを
    //   キャッシュへ入れる (AGENTS.md セキュリティ不変条件 11 に反する)。有効・無効を決める
    //   設定は本リポジトリが所有しているので、env で開け直せる形を残さない。
    $config = evaluateConfigFileWithEnv('prism-prompt.php', ['PRISM_PROMPT_CACHE' => 'true']);

    expect($config['cache'])->toBeArray();
    /** @var array<string, mixed> $cache */
    $cache = $config['cache'];
    expect($cache['enabled'])->toBeFalse(
        'PromptTemplate::fromYaml() がオブジェクトをキャッシュへ入れるため、env で開けられてはならない');
});

test('prism-prompt.cache.enabled は実行時にも false', function (): void {
    expect(config('prism-prompt.cache.enabled'))->toBeFalse();
});

// ========== fortify: passkeys ブロックの env 派生 (T166) ==========

/*
 * パスキーの宣言点は config/fortify.php の passkeys ブロックただ 1 つである
 * (FortifyServiceProvider::configurePasskeys() が passkeys.* を無条件に上書きするため)。
 * env からの導出規則を固定する。
 *
 * 注: config/fortify.php の features は Features::passkeys(['confirmPassword' => false]) を
 * 評価する = fortify-options.passkeys へ書き込む副作用がある。書き込まれる値は本番 config と
 * 同一なのでテストへの影響は無い。
 */

/**
 * config/fortify.php を env 指定で再評価し passkeys ブロックを返す。
 *
 * @param  array<string, string|null>  $env
 * @return array<string, mixed>
 */
function evaluateFortifyPasskeysWithEnv(array $env): array
{
    $config = evaluateConfigFileWithEnv('fortify.php', $env + [
        'APP_URL' => 'https://app.example.com',
        'PASSKEYS_RELYING_PARTY_ID' => null,
        'PASSKEYS_ALLOWED_ORIGINS' => null,
        'PASSKEYS_USER_HANDLE_SECRET' => null,
    ]);

    expect($config['passkeys'])->toBeArray();

    /** @var array<string, mixed> $passkeys */
    $passkeys = $config['passkeys'];

    return $passkeys;
}

test('PASSKEYS_* 未設定なら APP_URL から導出する (path は落ちる)', function (): void {
    $passkeys = evaluateFortifyPasskeysWithEnv([
        'APP_URL' => 'https://app.example.com/sub',
    ]);

    expect($passkeys['relying_party_id'])->toBe('app.example.com');
    expect($passkeys['allowed_origins'])->toBe(['https://app.example.com']);
    expect($passkeys['user_handle_secret_declared'])->toBeFalse();
});

test('APP_URL の port は接続元に残る', function (): void {
    $passkeys = evaluateFortifyPasskeysWithEnv([
        'APP_URL' => 'http://localhost:8000',
    ]);

    expect($passkeys['allowed_origins'])->toBe(['http://localhost:8000']);
});

test('APP_URL が空なら身元の識別子と接続元は空に倒れる (例外を投げない)', function (): void {
    $passkeys = evaluateFortifyPasskeysWithEnv([
        'APP_URL' => '',
    ]);

    expect($passkeys['relying_party_id'])->toBe('');
    expect($passkeys['allowed_origins'])->toBe([]);
});

test('APP_URL が URL でない文字列でも空に倒れる (production では起動時 fail-fast が拾う)', function (): void {
    $passkeys = evaluateFortifyPasskeysWithEnv([
        'APP_URL' => 'not-a-url',
    ]);

    expect($passkeys['relying_party_id'])->toBe('');
    expect($passkeys['allowed_origins'])->toBe([]);
});

test('PASSKEYS_RELYING_PARTY_ID は小文字化される', function (): void {
    $passkeys = evaluateFortifyPasskeysWithEnv([
        'PASSKEYS_RELYING_PARTY_ID' => 'App.Example.COM',
    ]);

    expect($passkeys['relying_party_id'])->toBe('app.example.com');
});

test('PASSKEYS_ALLOWED_ORIGINS の CSV は trim される', function (): void {
    $passkeys = evaluateFortifyPasskeysWithEnv([
        'PASSKEYS_ALLOWED_ORIGINS' => 'https://a.example.com, https://b.example.com',
    ]);

    expect($passkeys['allowed_origins'])->toBe(['https://a.example.com', 'https://b.example.com']);
});

test('PASSKEYS_ALLOWED_ORIGINS は小文字化される (webauthn-lib の strict 比較に一致させる)', function (): void {
    // ここを外すと運用者が大文字で書いた瞬間に全ての手続きが無言で失敗する。
    $passkeys = evaluateFortifyPasskeysWithEnv([
        'PASSKEYS_ALLOWED_ORIGINS' => 'HTTPS://App.Example.com',
    ]);

    expect($passkeys['allowed_origins'])->toBe(['https://app.example.com']);
});

test('PASSKEYS_ALLOWED_ORIGINS の末尾カンマは raw 側に空要素として残る', function (): void {
    $passkeys = evaluateFortifyPasskeysWithEnv([
        'PASSKEYS_ALLOWED_ORIGINS' => 'https://a.example.com,',
    ]);

    expect($passkeys['allowed_origins'])->toBe(['https://a.example.com']);
    expect($passkeys['raw_allowed_origins'])->toBe(['https://a.example.com', '']);
});

test('PASSKEYS_USER_HANDLE_SECRET を宣言すると declared=true になり値が入る', function (): void {
    $secret = str_repeat('z', 40);
    $passkeys = evaluateFortifyPasskeysWithEnv([
        'PASSKEYS_USER_HANDLE_SECRET' => $secret,
    ]);

    expect($passkeys['user_handle_secret_declared'])->toBeTrue();
    expect($passkeys['user_handle_secret'])->toBe($secret);
});

test('PASSKEYS_USER_HANDLE_SECRET が空白のみなら未宣言と同じ扱い', function (): void {
    $passkeys = evaluateFortifyPasskeysWithEnv([
        'PASSKEYS_USER_HANDLE_SECRET' => '   ',
    ]);

    expect($passkeys['user_handle_secret_declared'])->toBeFalse();
});

test('PASSKEYS_USER_HANDLE_SECRET の値は trim されない (既存パスキー維持の運用契約)', function (): void {
    // 現行 APP_KEY の値をそのまま宣言すれば既存パスキーを維持できる、という契約を守るため
    // 値は一切加工しない (trim すると別の鍵になり全件無効になる)。
    $secret = ' '.str_repeat('k', 40).' ';
    $passkeys = evaluateFortifyPasskeysWithEnv([
        'PASSKEYS_USER_HANDLE_SECRET' => $secret,
    ]);

    expect($passkeys['user_handle_secret_declared'])->toBeTrue();
    expect($passkeys['user_handle_secret'])->toBe($secret);
});
