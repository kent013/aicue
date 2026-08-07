<?php

declare(strict_types=1);

namespace Tests\Support\JobDedup;

/**
 * preflight (外部呼び出し直前の再検証) の要求。
 *
 * ★実装は `PreflightCheckpoint` と `NoExternalCall` の **2 つだけ**に閉じる。
 *   PHP には sealed type が無いため、実装集合の一致は
 *   JobExecutionDedupInventoryTest が deny-by-default で検査する
 *   (nullable にして「null = 外部呼び出しなし」とすると、新しい外部呼び出しを足しても
 *    目録が green のままになりうる)。
 */
interface PreflightRequirement {}
