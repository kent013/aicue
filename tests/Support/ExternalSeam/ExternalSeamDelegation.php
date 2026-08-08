<?php

declare(strict_types=1);

namespace Tests\Support\ExternalSeam;

use App\Enums\Security\ExternalSeamDimension;
use App\Enums\Security\ExternalSeamKind;
use Closure;

/**
 * 「この種別 × 次元は別 gate が既に deny-by-default で見ている」という委譲の宣言。
 *
 * ★委譲の結線は 2 層:
 *   1. **母集団の生存確認 (behavioral・主要保証)**: `livenessProbe` を実行して空でないことを確認する
 *   2. **委譲先 gate の同定 (主要保証)**: `gateFile` の実在 + `gateTestName` の完全一致
 * ★**保証しないもの**: 委譲先の assert の中身を弱める改変 (必須宣言のうち 1 つを検査しなくする等) は
 *   本 gate では検出できない。
 */
final readonly class ExternalSeamDelegation
{
    /**
     * @param  string  $gateFile  repo ルート相対
     * @param  string  $gateTestName  委譲先の test 名 (完全一致)
     * @param  Closure(): array<mixed>  $livenessProbe  委譲先が見ている母集団の導出 (空なら fail)
     * @param  string  $rationale  30 文字以上
     */
    public function __construct(
        public ExternalSeamKind $kind,
        public ExternalSeamDimension $dimension,
        public string $gateFile,
        public string $gateTestName,
        public Closure $livenessProbe,
        public string $rationale,
    ) {}
}
