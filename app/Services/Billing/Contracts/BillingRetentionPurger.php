<?php

declare(strict_types=1);

namespace App\Services\Billing\Contracts;

use App\DataTransferObjects\Billing\BillingRetentionPurgeResultDto;
use App\Enums\Billing\BillingRetentionTarget;
use Carbon\CarbonImmutable;

/**
 * 保持期間 (7 年) を超えた課金記録を target ごとに決着させる purger。
 *
 * 実装は `app/Services/Billing/Retention/` に置き、
 * `App\Services\Billing\Retention\BillingRetentionPurgerRegistry` へ**実行順で**登録する
 * (子 target は親より先。親を先に消すと FK cascade で子が件数報告を経由せず消える)。
 * target と実装の exact-fit は `BillingRetentionTargetInventoryTest` が機械強制する。
 *
 * **閾値 (`$threshold`) は呼び出し側が `BillingRetention::threshold()` で 1 回だけ解決して
 * 全 purger へ渡す**。purger が各自で now を読むと、実行中に日付が変わったときに
 * target ごとに違う閾値で判定されうる。
 */
interface BillingRetentionPurger
{
    public function target(): BillingRetentionTarget;

    /** 起算済み (起算列が非 null) かつ期限超過の件数。**horizon 検査の観測点**。 */
    public function countExpired(CarbonImmutable $threshold): int;

    /**
     * 安全のため残す件数。
     *
     * 内訳は 2 つ — (a) 起算列が null で補助時計が閾値より古い**異常** (判定できないものを
     * 消さない) と、(b) 期限超過だが他から参照されていて消せないもの。
     * どちらも「消さなかった」事実の報告であり、**規約を満たした証明ではない**。
     */
    public function countFailClosed(CarbonImmutable $threshold): int;

    /** 期限超過の記録を決着させる (dry-run では**呼ばない**)。 */
    public function purgeExpired(CarbonImmutable $threshold): BillingRetentionPurgeResultDto;
}
