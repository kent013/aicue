<?php

declare(strict_types=1);

use App\Support\Http\RetryAfterSeconds;

/*
 * Retry-After ヘッダ値 → 待ち時間 (秒) の唯一の解釈点の契約固定 (DB 不使用)。
 * 裁定: **非負整数のみ採り、解釈不能なら非表示**。
 */

test('非負整数と整数文字列を秒数として採る', function (mixed $value, ?int $expected): void {
    expect(RetryAfterSeconds::parse($value))->toBe($expected);
})->with([
    [60, 60],
    ['60', 60],
    ['0', 0],
    [0, 0],
]);

test('負数は解釈しない', function (mixed $value): void {
    expect(RetryAfterSeconds::parse($value))->toBeNull();
})->with([
    [-5],
    ['-5'],
]);

test('HTTP-date と任意文字列は解釈しない', function (mixed $value): void {
    expect(RetryAfterSeconds::parse($value))->toBeNull();
})->with([
    ['Wed, 21 Oct 2015 07:28:00 GMT'],
    ['soon'],
    ['1.5'],
    [''],
    [' 60'],
]);

test('int / string 以外の型は解釈しない', function (mixed $value): void {
    expect(RetryAfterSeconds::parse($value))->toBeNull();
})->with([
    [null],
    [[60]],
    [true],
    [1.5],
]);
