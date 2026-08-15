<?php

declare(strict_types=1);

namespace Tests\Support\Recovery;

use App\Enums\Recovery\RecoveryOutcome;
use App\Enums\Recovery\RecoveryStream;
use Webmozart\Assert\Assert;

/**
 * 滞留回収の系列 1 件分の申告。
 *
 * 「何を回収するのか」「1 掃引の上限はいくつか」「どの結果の種類を取りうるのか」を
 * **人が書いて宣言**し、`StuckWorkRecoveryInventoryTest` が実装・Schedule と突き合わせる。
 */
final readonly class RecoveryStreamEntry
{
    /** 説明文の最低文字数 (「同上」「N/A」を機械的に弾く) */
    public const int DESCRIPTION_MIN_LENGTH = 30;

    /**
     * @param  class-string  $implementation  この系列を実装するクラス
     * @param  positive-int|null  $sweepItemLimit  1 掃引で扱う件数の上限 (null = 無制限)
     * @param  list<RecoveryOutcome>  $possibleOutcomes  この系列が取りうる結果の種類
     */
    public function __construct(
        public RecoveryStream $stream,
        public string $implementation,
        public ?int $sweepItemLimit,
        public array $possibleOutcomes,
        public string $description,
    ) {
        Assert::classExists($this->implementation);
        Assert::minLength($this->description, self::DESCRIPTION_MIN_LENGTH);
        Assert::notEmpty($this->possibleOutcomes, '取りうる結果の種類を 1 つ以上申告してください');
        Assert::uniqueValues(array_map(
            static fn (RecoveryOutcome $outcome): string => $outcome->value,
            $this->possibleOutcomes,
        ));
    }
}
