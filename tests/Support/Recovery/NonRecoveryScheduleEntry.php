<?php

declare(strict_types=1);

namespace Tests\Support\Recovery;

use App\Enums\Recovery\NonRecoveryScheduleReasonKind;
use Webmozart\Assert\Assert;

/**
 * 「滞留回収ではない定期実行」1 件分の申告 (区分 + 30 文字以上の理由)。
 *
 * Schedule に載っているのに未分類のコマンドがあると目録 gate が落ちるため、
 * 6 本目の独自回収を素通しで足すことができない。
 */
final readonly class NonRecoveryScheduleEntry
{
    /** 理由の最低文字数 */
    public const int REASON_MIN_LENGTH = 30;

    public function __construct(
        public string $commandName,
        public NonRecoveryScheduleReasonKind $kind,
        public string $reason,
    ) {
        Assert::stringNotEmpty($this->commandName);
        Assert::minLength($this->reason, self::REASON_MIN_LENGTH);
    }
}
