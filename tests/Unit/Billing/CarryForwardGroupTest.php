<?php

declare(strict_types=1);

use App\DataTransferObjects\Billing\CarryForwardGroup;
use App\Enums\Billing\TicketSource;
use Carbon\CarbonImmutable;
use Webmozart\Assert\InvalidArgumentException;

/*
 * 畳み込みの集約結果を受ける境界 DTO (`CarryForwardGroup`) の型確定と fail-closed。
 *
 * ★**範囲検査は PHP `int` へ変換する前**に行う。driver が数値文字列で返す場合、
 *   先にキャストすると PHP 整数範囲を超えた値が**壊れた後で**検査することになる。
 */

/**
 * 集計行 (クエリビルダの `get()` が返す stdClass) を組み立てる。
 *
 * @param  array<string, mixed>  $overrides
 */
function carryForwardRow(array $overrides = []): stdClass
{
    return (object) array_merge([
        'source' => 'purchased',
        'expires_at' => null,
        'delta_sum' => 10,
        'max_created_at' => '2020-01-02 03:04:05',
        'row_count' => 2,
        'carry_forward_rows' => 0,
    ], $overrides);
}

test('1: delta_sum が int の正常値ならそのまま採る', function (): void {
    expect(CarryForwardGroup::fromRow(carryForwardRow(['delta_sum' => -42]))->deltaSum)->toBe(-42);
});

test('2: delta_sum が int4 の境界ちょうどなら通る', function (string $value, int $expected): void {
    expect(CarryForwardGroup::fromRow(carryForwardRow(['delta_sum' => $value]))->deltaSum)->toBe($expected);
})->with([
    ['2147483647', 2147483647],
    ['-2147483648', -2147483648],
]);

test('3: delta_sum が int4 の境界 +1 なら例外', function (string $value): void {
    CarryForwardGroup::fromRow(carryForwardRow(['delta_sum' => $value]));
})->with([['2147483648'], ['-2147483649']])->throws(InvalidArgumentException::class);

test('4: delta_sum が PHP 整数範囲を超える 10 進文字列ならキャスト前に落ちる', function (): void {
    CarryForwardGroup::fromRow(carryForwardRow(['delta_sum' => '9223372036854775808000']));
})->throws(InvalidArgumentException::class);

test('5: delta_sum が 10 進整数の表記でなければ例外', function (string $value): void {
    CarryForwardGroup::fromRow(carryForwardRow(['delta_sum' => $value]));
})->with([['1e5'], ['1.5'], [''], [' 1'], ['1 '], ['-'], ['+1'], ['0x10']])
    ->throws(InvalidArgumentException::class);

test('6: delta_sum が int でも文字列でもなければ例外', function (mixed $value): void {
    CarryForwardGroup::fromRow(carryForwardRow(['delta_sum' => $value]));
})->with([[true], [1.5], [null]])->throws(InvalidArgumentException::class);

test('7: delta_sum の -0 / 000 は 0 として通る', function (string $value): void {
    expect(CarryForwardGroup::fromRow(carryForwardRow(['delta_sum' => $value]))->deltaSum)->toBe(0);
})->with([['-0'], ['000'], ['0']]);

test('8: source が null なら null のまま保持する', function (): void {
    expect(CarryForwardGroup::fromRow(carryForwardRow(['source' => null]))->source)->toBeNull();
});

test('9: source の文字列は列挙型へ確定する', function (): void {
    expect(CarryForwardGroup::fromRow(carryForwardRow(['source' => 'monthly']))->source)
        ->toBe(TicketSource::Monthly);
});

test('10: source が未知の値なら例外', function (): void {
    CarryForwardGroup::fromRow(carryForwardRow(['source' => 'unknown']));
})->throws(ValueError::class);

test('11: source が非文字列なら例外', function (): void {
    CarryForwardGroup::fromRow(carryForwardRow(['source' => 1]));
})->throws(InvalidArgumentException::class);

test('12: expires_at が null なら expiresAt は null', function (): void {
    expect(CarryForwardGroup::fromRow(carryForwardRow(['expires_at' => null]))->expiresAt)->toBeNull();
});

test('13: expires_at は文字列でも DateTimeInterface でも CarbonImmutable になる', function (): void {
    $fromString = CarryForwardGroup::fromRow(carryForwardRow(['expires_at' => '2021-05-06 07:08:09']));
    expect($fromString->expiresAt)->toBeInstanceOf(CarbonImmutable::class);
    expect($fromString->expiresAt?->toDateTimeString())->toBe('2021-05-06 07:08:09');

    $fromObject = CarryForwardGroup::fromRow(
        carryForwardRow(['expires_at' => new DateTimeImmutable('2021-05-06 07:08:09')]),
    );
    expect($fromObject->expiresAt)->toBeInstanceOf(CarbonImmutable::class);
    expect($fromObject->expiresAt?->toDateTimeString())->toBe('2021-05-06 07:08:09');
});

test('14: max_created_at が null なら例外 (集約の基準時刻は必須)', function (): void {
    CarryForwardGroup::fromRow(carryForwardRow(['max_created_at' => null]));
})->throws(InvalidArgumentException::class);

test('15: row_count / carry_forward_rows の数値文字列は整数へ確定する', function (): void {
    $group = CarryForwardGroup::fromRow(carryForwardRow(['row_count' => '3', 'carry_forward_rows' => '0']));
    expect($group->rowCount)->toBe(3);
    expect($group->carryForwardRows)->toBe(0);
});

test('16: row_count が負なら例外', function (): void {
    CarryForwardGroup::fromRow(carryForwardRow(['row_count' => -1]));
})->throws(InvalidArgumentException::class);

test('17: 列が欠けていたら例外', function (): void {
    $row = carryForwardRow();
    unset($row->delta_sum);
    CarryForwardGroup::fromRow($row);
})->throws(InvalidArgumentException::class);

test('18: 余剰列があっても拒否しない', function (): void {
    $group = CarryForwardGroup::fromRow(carryForwardRow(['driver_internal' => 'noise']));
    expect($group->deltaSum)->toBe(10);
});

test('19: row_count が float なら例外', function (): void {
    CarryForwardGroup::fromRow(carryForwardRow(['row_count' => 1.0]));
})->throws(InvalidArgumentException::class);

test('20: row_count が指数表記なら例外', function (): void {
    CarryForwardGroup::fromRow(carryForwardRow(['row_count' => '1e3']));
})->throws(InvalidArgumentException::class);

test('21: row_count が bool なら例外', function (): void {
    CarryForwardGroup::fromRow(carryForwardRow(['row_count' => true]));
})->throws(InvalidArgumentException::class);

test('22: row_count が PHP 整数範囲を超える 10 進文字列ならキャスト前に落ちる', function (): void {
    CarryForwardGroup::fromRow(carryForwardRow(['row_count' => '9223372036854775808']));
})->throws(InvalidArgumentException::class);

test('23: row_count が 0 なら例外 (集約キーは必ず 1 行以上ある)', function (): void {
    CarryForwardGroup::fromRow(carryForwardRow(['row_count' => 0]));
})->throws(InvalidArgumentException::class);

test('24: carry_forward_rows が row_count を超えたら例外', function (): void {
    CarryForwardGroup::fromRow(carryForwardRow(['row_count' => 1, 'carry_forward_rows' => 2]));
})->throws(InvalidArgumentException::class);

test('25: carry_forward_rows が負なら例外', function (): void {
    CarryForwardGroup::fromRow(carryForwardRow(['carry_forward_rows' => -1]));
})->throws(InvalidArgumentException::class);

test('26: 正常な行は全項目が型の確定した DTO になる', function (): void {
    $group = CarryForwardGroup::fromRow(carryForwardRow([
        'source' => 'monthly',
        'expires_at' => '2030-01-01 00:00:00',
        'delta_sum' => '123',
        'max_created_at' => new DateTimeImmutable('2019-12-31 23:59:59'),
        'row_count' => '4',
        'carry_forward_rows' => '1',
    ]));

    expect($group->source)->toBe(TicketSource::Monthly);
    expect($group->expiresAt?->toDateTimeString())->toBe('2030-01-01 00:00:00');
    expect($group->deltaSum)->toBe(123);
    expect($group->maxCreatedAt->toDateTimeString())->toBe('2019-12-31 23:59:59');
    expect($group->rowCount)->toBe(4);
    expect($group->carryForwardRows)->toBe(1);
});
