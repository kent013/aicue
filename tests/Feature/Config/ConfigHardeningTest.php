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
