<?php

declare(strict_types=1);

/*
 * FxSnapshotDto の **配列往復**を固定する (lctl 標準形 v1「配列への変換と復元の往復が
 * 壊れないことを単体テストで固定する。キャッシュ経路を通す必要はない」)。
 *
 * この DTO は FxRateService が cache へ入れる唯一の payload の作り手であり、
 * tests/Architecture/CachePayloadPlainDataGateTest.php の目録が proof として本ファイルを指す
 * (proof のファイルが消えたら gate が落ちる)。
 *
 * DB 不使用。Factory を使うモデルは登場しない。
 */

use App\DataTransferObjects\FxSnapshotDto;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Webmozart\Assert\InvalidArgumentException;

/**
 * 正常系の素データ (cache に入る形そのもの)。
 *
 * @return array{rate: float, pair: string, source: string, fetched_at: string}
 */
function fxSnapshotPlainArray(): array
{
    return [
        'rate' => 151.23,
        'pair' => 'USDJPY',
        'source' => 'frankfurter',
        'fetched_at' => '2026-08-07T12:34:56+09:00',
    ];
}

test('toArray → fromArray の往復で値が一致する', function (): void {
    $original = new FxSnapshotDto(
        rate: 151.23,
        pair: 'USDJPY',
        source: 'frankfurter',
        fetchedAt: CarbonImmutable::parse('2026-08-07T12:34:56+09:00'),
    );

    $restored = FxSnapshotDto::fromArray($original->toArray());

    expect($restored->rate)->toBe($original->rate)
        ->and($restored->pair)->toBe($original->pair)
        ->and($restored->source)->toBe($original->source)
        // ISO8601 は秒精度。ミリ秒は仕様として落ちるため文字列で比較する
        ->and($restored->fetchedAt->toIso8601String())->toBe($original->fetchedAt->toIso8601String());
});

test('toArray は素のデータだけを返す (オブジェクトを含まない)', function (): void {
    $array = (new FxSnapshotDto(
        rate: 151.23,
        pair: 'USDJPY',
        source: 'frankfurter',
        fetchedAt: CarbonImmutable::parse('2026-08-07T12:34:56+09:00'),
    ))->toArray();

    // ★これが「キャッシュに入れてよいのは素のデータだけ」の DTO 側の表明。
    //   CarbonImmutable をそのまま載せる退行 (本番の database store でだけ壊れる) を落とす。
    foreach ($array as $key => $value) {
        expect(is_scalar($value))->toBeTrue("{$key} が素のデータではありません");
    }
    expect(array_keys($array))->toBe(['rate', 'pair', 'source', 'fetched_at']);
});

test('fromArray は必須キーの欠損を拒否する', function (string $missing): void {
    $data = fxSnapshotPlainArray();
    unset($data[$missing]);

    expect(fn () => FxSnapshotDto::fromArray($data))->toThrow(InvalidArgumentException::class);
})->with(['rate', 'pair', 'source', 'fetched_at']);

test('fromArray は不正値を拒否する', function (string $key, mixed $value): void {
    $data = fxSnapshotPlainArray();
    $data[$key] = $value;

    expect(fn () => FxSnapshotDto::fromArray($data))->toThrow(InvalidArgumentException::class);
})->with([
    'rate が非数値' => ['rate', 'abc'],
    'rate が 0' => ['rate', 0],
    'rate が負' => ['rate', -1.5],
    'pair が空' => ['pair', ''],
    'source が空' => ['source', ''],
    'fetched_at が空' => ['fetched_at', ''],
]);

test('fromArray は数値文字列の rate を float として復元する', function (): void {
    // 永続化済みの古い payload や外部入力由来で rate が文字列になっていても、
    // Assert::numeric を通したうえで float に正規化されることを固定する。
    $data = fxSnapshotPlainArray();
    $data['rate'] = '151.23';

    expect(FxSnapshotDto::fromArray($data)->rate)->toBe(151.23);
});

test('fromArray は解釈できない fetched_at を例外にする', function (): void {
    // ★空文字は Assert::stringNotEmpty が弾くが、'not-a-date' は Assert を通過して
    //   CarbonImmutable::parse() が InvalidFormatException を投げる (実測で確認済み)。
    //   壊れた cache payload の代表ケースなので、Assert 側とは別テストとして固定する。
    //   振る舞い上の契約は「FxRateService が Throwable を catch して Cache::forget する」なので、
    //   どちらの例外型でも安全側に倒れる。
    $data = fxSnapshotPlainArray();
    $data['fetched_at'] = 'not-a-date';

    expect(fn () => FxSnapshotDto::fromArray($data))
        ->toThrow(InvalidFormatException::class);
});
