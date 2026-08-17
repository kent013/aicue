<?php

declare(strict_types=1);

namespace Tests\Support\InitialState;

use InvalidArgumentException;

/**
 * 「nullable かつ DB 既定値を持たない列」1 本分の分類の宣言。
 *
 * **コンストラクタは private** で、区分ごとの名前付き生成子からしか作れない
 * (RetentionTableEntry と同じ形。不正な組み合わせを型で作らせない)。
 *
 * 根拠の長さは gate の規則 (NI-2) とは**別に**コンストラクタでも検査する。
 * 台帳を作った時点で落ちるので、短い根拠のまま集合比較まで進まない。
 */
final readonly class NullableStateColumnEntry
{
    /** 根拠の最低文字数 (「同上」「N/A」を機械的に弾く。gate 側の検査は NI-2)。 */
    public const int RATIONALE_MIN_LENGTH = 30;

    private function __construct(
        public string $table,
        public string $column,
        public NullInitialStateClass $class,
        public string $rationale,
    ) {
        if ($table === '' || $column === '') {
            throw new InvalidArgumentException('表名と列名は空にできません');
        }
        // 根拠は日本語で書くため、バイト数ではなく**文字数**で数える。
        if (mb_strlen($rationale) < self::RATIONALE_MIN_LENGTH) {
            throw new InvalidArgumentException(
                sprintf('%s.%s の根拠が %d 文字未満です', $table, $column, self::RATIONALE_MIN_LENGTH),
            );
        }
    }

    /** 生成時は必ず NULL で、NULL 自体が「まだその段階に達していない」を意味する列。 */
    public static function initialStateMarker(string $table, string $column, string $rationale): self
    {
        return new self($table, $column, NullInitialStateClass::InitialStateMarker, $rationale);
    }

    /** 行を作る時点で値が入りうる列 (期限 / 外部が決めた値の写し / 任意の属性)。 */
    public static function setAtCreation(string $table, string $column, string $rationale): self
    {
        return new self($table, $column, NullInitialStateClass::SetAtCreation, $rationale);
    }

    /** どちらとも決められていない列。$rationale には**何が決まっていないか**を書く。 */
    public static function undecided(string $table, string $column, string $rationale): self
    {
        return new self($table, $column, NullInitialStateClass::Undecided, $rationale);
    }

    /** 集合比較の正規化キー (gate 側で文字列連結を書かないための唯一の入口)。 */
    public function key(): string
    {
        return $this->table.'.'.$this->column;
    }
}
