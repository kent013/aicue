<?php

declare(strict_types=1);

namespace App\Contracts\Recovery;

use App\Enums\Recovery\RecoveryOutcome;
use App\Enums\Recovery\RecoveryStream;
use Carbon\CarbonImmutable;

/**
 * 滞留回収の 1 系列。**候補は主キーだけを返し、回収は id しか受け取らない**。
 *
 * 行の内容を持ち回れないので、回収側は必ず行を取り直して述語を再評価することになる
 * (= 候補を集めた後に正常へ進んだものを誤って失敗にする事故が構造的に起きない)。
 *
 * $sweptAt は掃引の開始時刻で、候補列挙と再評価で**同じ現在時刻**を使うために渡す
 * (行の内容ではないので上の強制は壊れない)。
 */
interface StuckWorkStream
{
    public function stream(): RecoveryStream;

    /**
     * 候補の主キーを昇順で最大 $pageSize 件返す ($afterId より大きいものだけ)。
     *
     * @param  positive-int|null  $afterId  前ページの最後の主キー (先頭ページは null)
     * @param  positive-int  $pageSize
     * @return list<positive-int>
     */
    public function candidateIds(CarbonImmutable $sweptAt, ?int $afterId, int $pageSize): array;

    /**
     * 1 件を回収する。**行を取り直して述語を再評価する責務はこの実装側にある**。
     *
     * 競合・条件不成立は例外ではなく RecoveryOutcome::Skipped を返す。
     * 例外を投げてよいのは本当の不変条件違反だけで、掃引側が report して次へ進む。
     *
     * @param  positive-int  $id
     */
    public function recover(int $id, CarbonImmutable $sweptAt): RecoveryOutcome;

    /**
     * 1 回の掃引で処理する件数の上限 (null = 無制限)。
     * 撮影アップロードだけが 500 を申告する (S3 の入出力を有界にする既存の判断)。
     *
     * @return positive-int|null
     */
    public function sweepItemLimit(): ?int;
}
