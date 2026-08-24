<?php

declare(strict_types=1);

use Tests\Support\RawEnv\RawEnvChannels;
use Tests\Support\RawEnv\RawEnvSnapshot;

/*
 * 宣言経路 (環境変数 → config/fortify.php) が「許可する接続元」を正規形へ寄せることの
 * 端から端までの固定 (T216 施策 B)。
 *
 * ★実効値だけを見る検査では**検出力が弱い** — 手元の APP_URL が既に正規形なら、
 *   config/fortify.php から正規化器の呼び出しを外しても緑のままになりうる。
 *   ソース文字列の包含で代用するのも不十分である (呼び出しを消してコメントに残す /
 *   戻り値を採用しない書き方でも通る)。
 *   そこで**宣言経路そのものを再評価し、返ってきた配列**を見る。
 */

/**
 * 環境変数を差し替えて config/fortify.php を評価し、返り値を得る。
 *
 * ★3 面 ($_SERVER / $_ENV / putenv) をそろえて埋めるのも、必ず元へ戻すのも
 *   `Tests\Support\RawEnv\RawEnvSnapshot` が担う (「元が未設定なら空文字ではなく未設定で戻す」
 *   = 未宣言の意味を変えない、も部品側の契約である)。ここは呼び出し側であって、
 *   同じ処理を書き直さない (家系の正典 raw-env-snapshot-restore v1 の i1)。
 *   Laravel の env() は 3 面を live に読むため 3 つとも埋める必要がある。
 * ★設定ファイルの評価は副作用として fortify-options を同じ値で書き直すだけで、
 *   他への影響を持たない (Features::* は options を config へ書いて識別子を返す builder)。
 *
 * @param  array<string, string>  $overrides
 * @return array<string, mixed>
 */
function evaluateFortifyConfigWithEnv(array $overrides): array
{
    $changes = array_map(
        static fn (string $value): RawEnvChannels => RawEnvChannels::sameOnAllSurfaces($value),
        $overrides,
    );

    return RawEnvSnapshot::with($changes, static function (): array {
        /** @var array<string, mixed> $config */
        $config = require base_path('config/fortify.php');

        return $config;
    });
}

test('宣言経路が正規形へ寄せる (末尾スラッシュと既定 port と大文字)', function (): void {
    $config = evaluateFortifyConfigWithEnv([
        'PASSKEYS_ALLOWED_ORIGINS' => 'HTTPS://App.Example.com:443/',
    ]);

    expect(data_get($config, 'passkeys.allowed_origins'))->toBe(['https://app.example.com'])
        ->and(data_get($config, 'passkeys.raw_allowed_origins'))->toBe(['https://app.example.com']);
});

test('宣言経路は複数件をそれぞれ正規形へ寄せる', function (): void {
    $config = evaluateFortifyConfigWithEnv([
        'PASSKEYS_ALLOWED_ORIGINS' => 'https://a.example.com/, https://b.example.com:443, https://c.example.com:8443',
    ]);

    expect(data_get($config, 'passkeys.allowed_origins'))->toBe([
        'https://a.example.com',
        'https://b.example.com',
        'https://c.example.com:8443',
    ]);
});

test('宣言経路は空要素を残す (書き損じを起動時に表面化させるため)', function (): void {
    $config = evaluateFortifyConfigWithEnv([
        'PASSKEYS_ALLOWED_ORIGINS' => 'https://app.example.com,',
    ]);

    expect(data_get($config, 'passkeys.raw_allowed_origins'))->toBe(['https://app.example.com', ''])
        ->and(data_get($config, 'passkeys.allowed_origins'))->toBe(['https://app.example.com']);
});

test('宣言が無ければ APP_URL から導出し、それも正規形になる', function (): void {
    $config = evaluateFortifyConfigWithEnv([
        'APP_URL' => 'https://App.Example.com:443/app',
        'PASSKEYS_ALLOWED_ORIGINS' => '',
        'PASSKEYS_RELYING_PARTY_ID' => '',
    ]);

    expect(data_get($config, 'passkeys.allowed_origins'))->toBe(['https://app.example.com'])
        ->and(data_get($config, 'passkeys.relying_party_id'))->toBe('app.example.com');
});

test('宣言経路は身元の識別子を正規化器へ通さない (パスキーの束縛先を書き換えない)', function (): void {
    // 身元の識別子は host 単独の識別子であり、scheme も port も持たない。
    // ここに「正規形へ寄せる」処理を足すと、登録済みパスキーが全件使えなくなる
    // 方向の事故を作りやすいため、前後空白の除去と小文字化だけに留める。
    $config = evaluateFortifyConfigWithEnv([
        'PASSKEYS_RELYING_PARTY_ID' => '  App.Example.com  ',
        'PASSKEYS_ALLOWED_ORIGINS' => 'https://app.example.com',
    ]);

    expect(data_get($config, 'passkeys.relying_party_id'))->toBe('app.example.com');
});
