<?php

declare(strict_types=1);

/*
 * config/testing.php の系統別 fake フラグ (fake_externals / fake_llm / fake_storage) の
 * env 未設定前提 (config 既定) を固定する回帰テスト。
 *
 * phpunit.xml は TESTING_FAKE_LLM / TESTING_FAKE_STORAGE / TESTING_FAKE_EXTERNALS を
 * どれも設定しないため、testing 環境での実効値は config 既定 (すべて false) になる。
 * 将来 phpunit.xml に TESTING_FAKE_* が追加された場合は本テストが検知して落ちる
 * (= 既定変更の可視化)。bughunt の real-llm 既定 (fake_llm=false) はこの既定に依存する。
 */

test('fake_llm は testing 環境で既定 false (bool)', function (): void {
    expect(config('testing.fake_llm'))->toBeFalse();
    expect(config('testing.fake_llm'))->toBeBool();
});

test('fake_storage は testing 環境で既定 false (bool)', function (): void {
    expect(config('testing.fake_storage'))->toBeFalse();
    expect(config('testing.fake_storage'))->toBeBool();
});

test('fake_externals も既定 false のまま不変 (回帰防止)', function (): void {
    expect(config('testing.fake_externals'))->toBeFalse();
    expect(config('testing.fake_externals'))->toBeBool();
});
