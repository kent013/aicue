<?php

declare(strict_types=1);

use App\ValueObjects\EnterpriseSso\ConnectionSecret;

/*
 * 接続の秘密の値型 (A2)。
 *
 * ★**保証しない範囲は検査しない**。`var_export` / `serialize` / Reflection から平文が
 *   見えることは docblock が明言しており、そこを検査すると**誤った安心**を与える。
 */

test('__toString() を持たない (うっかりの文字列連結が型で消えている)', function (): void {
    expect(method_exists(ConnectionSecret::class, '__toString'))->toBeFalse();
});

test('var_dump 系に平文が出ない (__debugInfo が伏せる)', function (): void {
    $secret = ConnectionSecret::fromPlaintext('super-secret-value');

    ob_start();
    var_dump($secret);
    $dumped = (string) ob_get_clean();

    expect($dumped)->not->toContain('super-secret-value');
    expect($dumped)->toContain('********');
});

test('json_encode に平文が出ない', function (): void {
    $secret = ConnectionSecret::fromPlaintext('super-secret-value');

    expect(json_encode($secret, JSON_THROW_ON_ERROR))->not->toContain('super-secret-value');
});

test('平文の取り出しは token 交換用の 1 メソッドだけである', function (): void {
    $secret = ConnectionSecret::fromPlaintext('super-secret-value');

    expect($secret->revealForTokenExchange())->toBe('super-secret-value');
    expect($secret->isPresent())->toBeTrue();
    expect(ConnectionSecret::fromPlaintext('')->isPresent())->toBeFalse();
});

test('公開メソッドの一覧が現在値ちょうどである (平文へ出る口を黙って増やせない)', function (): void {
    $methods = array_map(
        static fn (ReflectionMethod $method): string => $method->getName(),
        (new ReflectionClass(ConnectionSecret::class))->getMethods(ReflectionMethod::IS_PUBLIC),
    );
    sort($methods);

    // ★平文へ出る口は**用途ごとに 2 つ**だけである (外向きの交換 / 保存のための暗号化)。
    //   どちらも呼び出し元を G3 が exact-fit で pin している。
    //   ここを増やす差分は必ずこの一覧の書き換えとして現れる。
    expect($methods)->toBe([
        '__debugInfo',
        'fromPlaintext',
        'isPresent',
        'revealForEncryptionAtRest',
        'revealForTokenExchange',
    ]);
});

test('保存のための平文化も平文を返す (用途が違うだけで値は同じ)', function (): void {
    $secret = ConnectionSecret::fromPlaintext('super-secret-value');

    expect($secret->revealForEncryptionAtRest())->toBe('super-secret-value');
});
