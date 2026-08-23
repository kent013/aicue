<?php

declare(strict_types=1);

use App\DataTransferObjects\Organizations\SsoConnectionSummary;
use App\Enums\EnterpriseSso\RejectionReason;
use App\Exceptions\EnterpriseSso\EnterpriseSsoAttemptRejectedException;
use App\Services\EnterpriseSso\EnterpriseLoginAttemptStore;
use App\Services\EnterpriseSso\OidcTokenExchanger;
use App\Support\EnterpriseSso\BasicCredentials;
use App\ValueObjects\EnterpriseSso\ConnectionSecret;
use Tests\Support\EnterpriseSso\EnterpriseSsoSourceScanner;

/*
 * G3: 接続の秘密が受け渡しの型・例外・記録に存在しない。
 *
 * ## 三層で守る (本 gate は 2 層目である)
 *
 *  1. **型** — {@see ConnectionSecret} が暗黙の文字列化を持たない (うっかりの連結を消す)
 *  2. **gate (ここ)** — 値型をログ・dump・直列化の関数へ渡す記法を禁じ、
 *     平文化の呼び出し元を exact-fit で pin し、例外の構築子の形を固定する
 *  3. **主たる証明** — 実挙動の漏洩テスト
 *     (`tests/Feature/EnterpriseSso/OidcTokenExchangerTest.php` の
 *      「ログに秘密・認可コード・検証子が残らない」「例外の中身に出ない」/
 *      `tests/Feature/Organizations/OrganizationSsoConnectionTest.php` の
 *      「一覧の生成が秘密を一度も復号しない」「dontFlash」)
 *
 * ★**主たる証明は 3 にある**。本 gate は「うっかり書けてしまう形」を消すだけで、
 *   秘密が出ないことそのものを静的に証明はしない。
 *
 * ## 保証しないもの (誇張しない)
 *
 * - `var_export` / `serialize` / Reflection からは平文が見える (値型の docblock が明言している)
 * - 走査根の外から値型を受け取って出力する形は見ない (値型を渡せる経路が
 *   `revealForTokenExchange()` の exact-fit で閉じていることが根拠である)
 */

function enterpriseSsoSecretRoots(): array
{
    return [
        'app/Services/EnterpriseSso',
        'app/Http/Controllers/Organizations/OrganizationSsoConnectionController.php',
        'app/Http/Requests/Organizations/StoreSsoConnectionRequest.php',
        'app/Http/Requests/Organizations/UpdateSsoConnectionRequest.php',
        'app/DataTransferObjects/Organizations/SsoConnectionSummary.php',
        'app/ValueObjects/EnterpriseSso',
    ];
}

test('G3-1: 秘密を出力・直列化する関数の記法が走査根に無い', function (): void {
    $sources = EnterpriseSsoSourceScanner::sources(enterpriseSsoSecretRoots());

    expect(EnterpriseSsoSourceScanner::forbiddenTokens($sources, [
        'var_dump', 'var_export', 'print_r', 'serialize', 'dump', 'dd', 'ray',
    ]))->toBe([], '秘密を扱う経路で出力・直列化の関数を使わないこと');
});

test('G3-2: 平文化の呼び出し元が用途ごとに 1 本ずつである (exact-fit)', function (string $method, string $caller): void {
    // ★走査根は `app/` 全数である (**どこからでも呼べてはいけない**ため)。
    $sources = EnterpriseSsoSourceScanner::sources(['app']);

    expect(EnterpriseSsoSourceScanner::filesCalling($sources, $method))->toBe([$caller]);
})->with([
    // 外向きへ出す平文は token 交換だけが取り出す
    ['revealForTokenExchange', 'app/Services/EnterpriseSso/OidcTokenExchanger.php'],
    // 保存のための暗号化だけが取り出す (用途を分けているので相互に流用できない)
    ['revealForEncryptionAtRest', 'app/Casts/EncryptedSecretCast.php'],
]);

test('G3-3: 値型が暗黙の文字列化を持たない', function (): void {
    expect(method_exists(ConnectionSecret::class, '__toString'))->toBeFalse();
});

test('G3-4: 拒否の例外が理由の enum だけを受け取り、previous を受け取れない', function (): void {
    $constructor = (new ReflectionClass(EnterpriseSsoAttemptRejectedException::class))->getConstructor();

    expect($constructor)->not->toBeNull();
    expect($constructor?->isPrivate())->toBeTrue('外から任意の値で作れないこと');
    expect($constructor?->getNumberOfParameters())->toBe(1, '理由の enum だけを受け取ること');
    expect((string) $constructor?->getParameters()[0]->getType())->toBe(RejectionReason::class);
});

test('G3-5: 画面へ返す要約が秘密の項目を持たない (伏字すら持たない)', function (): void {
    $properties = array_map(
        static fn (ReflectionProperty $property): string => $property->getName(),
        (new ReflectionClass(SsoConnectionSummary::class))
            ->getProperties(ReflectionProperty::IS_PUBLIC),
    );

    expect($properties)->not->toContain('clientSecret');
    expect($properties)->not->toContain('clientSecretMasked');
    // ★版番号も出さない (D1 の内部の比較子であって画面が使う値ではない)
    expect($properties)->not->toContain('credentialsRevision');
});

test('G3-6: 秘密を平文で受ける引数に SensitiveParameter が付いている', function (string $class, string $method, string $parameter): void {
    $reflection = (new ReflectionClass($class))->getMethod($method);

    $target = null;
    foreach ($reflection->getParameters() as $candidate) {
        if ($candidate->getName() === $parameter) {
            $target = $candidate;
        }
    }

    expect($target)->not->toBeNull();
    expect($target?->getAttributes(SensitiveParameter::class))->not->toBe([]);
})->with([
    [OidcTokenExchanger::class, 'exchange', 'code'],
    [OidcTokenExchanger::class, 'exchange', 'codeVerifier'],
    [BasicCredentials::class, 'header', 'clientSecret'],
    [ConnectionSecret::class, 'fromPlaintext', 'plaintext'],
    [EnterpriseLoginAttemptStore::class, 'consume', 'browserBindingSecret'],
]);

test('G3-7: 走査が空振りしていない', function (): void {
    expect(EnterpriseSsoSourceScanner::sources(enterpriseSsoSecretRoots()))->not->toBe([]);
    expect(EnterpriseSsoSourceScanner::sources(['app']))->not->toBe([]);
});
