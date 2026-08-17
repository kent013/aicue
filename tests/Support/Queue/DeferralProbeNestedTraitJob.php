<?php

declare(strict_types=1);

namespace Tests\Support\Queue;

/**
 * マーカー正例 fixture: **trait が trait を使う**形で退避が持ち込まれるジョブ。
 *
 * このクラス自身のファイルには退避マーカーが 1 つも無い。
 * 走査根を推移閉包 (親クラス / trait を再帰的に辿る) にしていないと検出できない。
 */
final class DeferralProbeNestedTraitJob
{
    use DeferralProbeOuterTrait;
}
