<?php

declare(strict_types=1);

use App\Support\TrustedHostsConfigValidator;

/**
 * TrustHosts allowlist の起動時 fail-fast を unit test で網羅。
 *
 * Service Provider / Guard の boot 内 inline では Feature test で再現困難なため、
 * 純粋クラスとして切り出した validator を直接検証する設計。
 */
test('production で allowlist 空なら throws', function (): void {
    expect(fn () => (new TrustedHostsConfigValidator)->validateForProduction([], [], []))
        ->toThrow(RuntimeException::class, 'allowlist is empty');
});

test('不正 wildcard suffix (先頭 dot 不在) で throws', function (): void {
    expect(fn () => (new TrustedHostsConfigValidator)->validateForProduction(
        ['example.com'],
        [],
        ['preview.example.com'],
    ))->toThrow(RuntimeException::class, 'leading dot');
});

test('不正 wildcard suffix (port 混入) で throws', function (): void {
    expect(fn () => (new TrustedHostsConfigValidator)->validateForProduction(
        ['example.com'],
        [],
        ['.preview.example.com:8080'],
    ))->toThrow(RuntimeException::class, 'leading dot');
});

test('exact host が scheme 含むと throws', function (): void {
    expect(fn () => (new TrustedHostsConfigValidator)->validateForProduction(
        ['https://example.com'],
        [],
        [],
    ))->toThrow(RuntimeException::class, 'PRIMARY_HOST');
});

test('exact host が port 含むと throws', function (): void {
    expect(fn () => (new TrustedHostsConfigValidator)->validateForProduction(
        ['example.com:8080'],
        [],
        [],
    ))->toThrow(RuntimeException::class, 'PRIMARY_HOST');
});

test('exact host が path 含むと throws', function (): void {
    expect(fn () => (new TrustedHostsConfigValidator)->validateForProduction(
        ['example.com/path'],
        [],
        [],
    ))->toThrow(RuntimeException::class, 'PRIMARY_HOST');
});

test('単一ラベル wildcard suffix (.com) は過大許可として throws', function (): void {
    expect(fn () => (new TrustedHostsConfigValidator)->validateForProduction(
        ['example.com'],
        [],
        ['.com'],
    ))->toThrow(RuntimeException::class, 'too broad');
});

test('所有ドメイン配下の wildcard suffix は許可 (.example.co.jp)', function (): void {
    // 注: format validator は label 数しか見ないため `.co.jp` 等の public suffix 単体は
    // 通過してしまう (PSL 判定は scope 外、運用者が所有ドメイン配下の suffix を設定する前提)。
    // 推奨は `.preview.example.com` / `.example.co.jp` のような所有ドメイン配下。
    (new TrustedHostsConfigValidator)->validateForProduction(
        ['app.example.co.jp'],
        ['.staging.example.co.jp'],
        ['.staging.example.co.jp'],
    );

    expect(true)->toBeTrue();
});

test('正常 host + wildcard で例外なし', function (): void {
    (new TrustedHostsConfigValidator)->validateForProduction(
        ['example.com', 'app.example.com'],
        ['.preview.example.com'],
        ['.preview.example.com'],
    );

    expect(true)->toBeTrue();
});

test('空文字列 raw wildcard は skip される', function (): void {
    (new TrustedHostsConfigValidator)->validateForProduction(
        ['example.com'],
        [],
        ['', '  ', '.preview.example.com'],
    );

    expect(true)->toBeTrue();
});

test('wildcard のみで exact 空でも例外なし', function (): void {
    (new TrustedHostsConfigValidator)->validateForProduction(
        [],
        ['.preview.example.com'],
        ['.preview.example.com'],
    );

    expect(true)->toBeTrue();
});
