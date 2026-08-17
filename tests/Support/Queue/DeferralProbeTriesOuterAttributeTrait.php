<?php

declare(strict_types=1);

namespace Tests\Support\Queue;

/**
 * 負例 fixture の中間 trait: `#[Tries]` を持つ trait を**入れ子で**取り込む。
 *
 * framework の `ReadsClassAttributes::getAttributeInstance()` は direct trait しか見ないので
 * この位置の `#[Tries]` は実際には効かない。それでも C2 (禁止側) は fail-closed で落とす
 * — 効かない宣言でも「回数でも止まる」という誤読を生むためである。
 */
trait DeferralProbeTriesOuterAttributeTrait
{
    use DeferralProbeTriesAttributeTrait;
}
