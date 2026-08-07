<?php

declare(strict_types=1);

namespace Tests\Support\Billing;

use RuntimeException;

/**
 * 写像表に**載っていない**ことを目的とするテスト専用例外。
 *
 * ★`unknown` (写像の不在) の分類を固定するために使う。vendor 例外を未分類のまま
 *   fixture に使うと「vendor 全件分類」の gate と衝突するため、専用クラスを置く。
 */
final class UnmappedGatewayFailureForTest extends RuntimeException {}
