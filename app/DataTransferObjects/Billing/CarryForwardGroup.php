<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Billing;

use App\Enums\Billing\TicketSource;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use stdClass;
use Webmozart\Assert\Assert;

/**
 * 畳み込みの集約キー 1 件ぶん (DB 集計結果の境界 DTO)。
 *
 * 集計は Eloquent ではなくクエリビルダで行い、**cast を通らない生値**を受け取ってから
 * ここで型を確定させる。モデル経由で `selectRaw` すると `source` が列挙型へ cast され、
 * その値をさらに `TicketSource::from()` へ渡す二重変換で実行時エラーになるためである。
 *
 * ★**範囲検査は PHP `int` へ変換する前に行う**。`delta` 列は int4 なので、
 *   合計が `[-2147483648, 2147483647]` を外れたら fail-closed で落とす。driver が
 *   数値文字列で返す場合に先にキャストすると、**PHP 整数範囲を超える値が壊れた後で**
 *   検査することになるため、**10 進文字列のまま**符号 + 桁数 + 辞書順で比較する。
 *
 * ★**列ごとの許容型** (bool / float / 指数表記 / 小数 / 空文字 / 前後空白つきは**すべて例外**):
 *   - `source`: `null` はそのまま保持する / **文字列だけ** `TicketSource::from()` へ渡す
 *     (未知の値は列挙型が例外にする) / それ以外の型は例外
 *   - `expires_at`: `null` / 文字列 / `DateTimeInterface`
 *   - `max_created_at`: **非 null** 必須。文字列 / `DateTimeInterface`
 *   - `delta_sum`: `int` または 10 進整数の文字列。**int4 の範囲を変換前に検査**する
 *   - `row_count` / `carry_forward_rows`: `int` または 10 進整数の文字列。
 *     **PHP 整数の範囲を変換前に検査**したうえで非負であること
 *
 * ★**集約結果どうしの不変条件**も境界で見る (壊れた集計が収束判定へ流れないように)。
 *     `rowCount >= 1` かつ `0 <= carryForwardRows <= rowCount`
 *
 * ★**引数は `stdClass` に狭める**。クエリビルダの `get()` が返すのは `stdClass` であり、
 *   任意 object を許すと「`propertyExists()` は true だが `get_object_vars()` には
 *   現れない private property」という穴が開く。読み出しは `get_object_vars()` +
 *   `Assert::keyExists()` の 2 段で行う (動的プロパティ参照 `$row->$name` は使わない —
 *   arch ベースラインの動的メンバ目録を太らせないため)。
 *
 * ★**想定外の余剰列は拒否しない**。集約 SQL は畳み込みサービスが組み立てるので余剰列は
 *   入らず、拒否すると driver が付ける内部列で偽赤になりうる。**列の欠落は例外**にする。
 */
final readonly class CarryForwardGroup
{
    public function __construct(
        public ?TicketSource $source,
        public ?CarbonImmutable $expiresAt,
        public int $deltaSum,
        public CarbonImmutable $maxCreatedAt,
        public int $rowCount,
        public int $carryForwardRows,
    ) {}

    /** 生の集計行 (stdClass) を型の確定した DTO へ変換する (level 10 の narrowing はここ 1 箇所)。 */
    public static function fromRow(stdClass $row): self
    {
        $source = self::nullableString($row, 'source');
        $maxCreatedAt = self::nullableTimestamp($row, 'max_created_at');
        Assert::notNull($maxCreatedAt, '集約の基準時刻 (max_created_at) が取得できない');

        $rowCount = self::natural($row, 'row_count');
        $carryForwardRows = self::natural($row, 'carry_forward_rows');
        // 集約結果どうしの整合 (壊れた集計が収束判定へ流れないようにする)
        Assert::greaterThanEq($rowCount, 1, '集約キーの行数が 1 未満である (集計が壊れている)');
        Assert::lessThanEq($carryForwardRows, $rowCount, '繰越行の数が集約キーの行数を超えている');

        return new self(
            $source === null ? null : TicketSource::from($source),
            self::nullableTimestamp($row, 'expires_at'),
            self::int4($row, 'delta_sum'),
            $maxCreatedAt,
            $rowCount,
            $carryForwardRows,
        );
    }

    /** 列の読み出しの唯一の口 (存在しない列は表明で落とす)。 */
    private static function value(stdClass $row, string $property): mixed
    {
        /** @var array<string, mixed> $values */
        $values = get_object_vars($row);
        Assert::keyExists($values, $property, "集計行に列 {$property} が無い");

        return $values[$property];
    }

    /** 文字列列 (列挙値の生表現)。 */
    private static function nullableString(stdClass $row, string $property): ?string
    {
        $value = self::value($row, $property);
        if ($value === null) {
            return null;
        }
        Assert::string($value, "集計行の列 {$property} が文字列ではない");

        return $value;
    }

    /** 日時列 (driver によって文字列 / DateTimeInterface で返る)。 */
    private static function nullableTimestamp(stdClass $row, string $property): ?CarbonImmutable
    {
        $value = self::value($row, $property);
        if ($value === null) {
            return null;
        }
        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value);
        }
        Assert::stringNotEmpty($value, "集計行の列 {$property} が日時として解釈できない");

        return CarbonImmutable::parse($value);
    }

    /** int4 の範囲に収まる整数 (**変換前に**範囲を判定する)。 */
    private static function int4(stdClass $row, string $property): int
    {
        return self::decimalInt(
            self::value($row, $property),
            $property,
            '2147483647',
            '2147483648',
            "繰越行の {$property} が delta 列 (signed integer) の範囲を超えた (この組織の処理を巻き戻す)",
        );
    }

    /** 非負整数 (件数)。PHP 整数の範囲も**変換前に**判定する。 */
    private static function natural(stdClass $row, string $property): int
    {
        $number = self::decimalInt(
            self::value($row, $property),
            $property,
            (string) PHP_INT_MAX,
            ltrim((string) PHP_INT_MIN, '-'),
            "集計行の列 {$property} が PHP 整数の範囲を超えた",
        );
        Assert::natural($number, "集計行の列 {$property} が負である");

        return $number;
    }

    /**
     * `int` か 10 進整数の文字列だけを受け、**PHP `int` へ変換する前に**上下限を判定する。
     *
     * bool / float / 指数表記 / 小数 / 空文字 / 前後空白つきはすべて例外にする
     * (`is_numeric()` や `Assert::integerish()` はこれらの一部を受理するので使わない)。
     *
     * @param  string  $positiveLimit  正側の上限の絶対値 (10 進文字列)
     * @param  string  $negativeLimit  負側の下限の絶対値 (10 進文字列)
     */
    private static function decimalInt(
        mixed $value,
        string $property,
        string $positiveLimit,
        string $negativeLimit,
        string $rangeMessage,
    ): int {
        if (is_int($value)) {
            // `int` で来た値は PHP 整数の範囲内が保証されているので、絶対値の桁比較だけで足りる
            Assert::true(
                self::withinLimit((string) $value, $positiveLimit, $negativeLimit),
                $rangeMessage,
            );

            return $value;
        }

        Assert::string($value, "集計行の列 {$property} が int でも文字列でもない");
        Assert::regex($value, '/\A-?[0-9]+\z/', "集計行の列 {$property} が 10 進整数の表記ではない");
        Assert::true(self::withinLimit($value, $positiveLimit, $negativeLimit), $rangeMessage);

        return (int) $value;
    }

    /** 10 進文字列のまま上下限と比較する (符号 → 桁数 → 辞書順)。 */
    private static function withinLimit(string $decimal, string $positiveLimit, string $negativeLimit): bool
    {
        $negative = str_starts_with($decimal, '-');
        $digits = ltrim($negative ? substr($decimal, 1) : $decimal, '0');
        if ($digits === '') {
            return true; // 0 (`-0` / `000` を含む)
        }
        $limit = $negative ? $negativeLimit : $positiveLimit;
        if (strlen($digits) !== strlen($limit)) {
            return strlen($digits) < strlen($limit);
        }

        return strcmp($digits, $limit) <= 0;
    }
}
