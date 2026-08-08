<?php

declare(strict_types=1);

namespace App\Support\Idempotency;

use Carbon\CarbonImmutable;
use Webmozart\Assert\Assert;

/**
 * 冪等キーの保持期間 (config/idempotency.php) への型付き入口。
 *
 * REST (IdempotentRequest) と MCP (McpIdempotencyService) と prune コマンドが
 * **同じ 1 箇所**からしか保持期間を読まないようにするための Support。
 * クラス定数での二重管理へ戻さないこと (parity gate が定数の不在を固定する)。
 *
 * `cutoff()` は**作らない**。prune の cutoff は `CarbonImmutable::now()` そのものであり、
 * Support に別名を置くと「保持期間の SoT」と関係のない薄い委譲が増える。
 */
final class IdempotencyRetention
{
    /** 保持期間 (時間)。config の型崩れは Assert で fail-fast する */
    public static function hours(): int
    {
        /** @var mixed $hours */
        $hours = config('idempotency.retention_hours');
        Assert::integer($hours, 'config(idempotency.retention_hours) must be an int.');
        Assert::greaterThan($hours, 0, 'config(idempotency.retention_hours) must be positive.');

        return $hours;
    }

    /** 基準時刻からの失効時刻 (時単位のため *NoOverflow の対象外) */
    public static function expiresAt(?CarbonImmutable $now = null): CarbonImmutable
    {
        return ($now ?? CarbonImmutable::now())->addHours(self::hours());
    }
}
