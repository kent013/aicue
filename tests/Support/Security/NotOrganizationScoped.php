<?php

declare(strict_types=1);

namespace Tests\Support\Security;

/**
 * 解決点が **0 件であることを検査した**入口 (家系裁定 AG-047)。
 *
 * ★理由の記載は必須である (30 文字以上を gate が強制する)。
 *   「組織を解決しない」と書くだけでは、検査したのか忘れたのか区別できない。
 */
final readonly class NotOrganizationScoped extends MachinePlaneEntryClassification
{
    public function __construct(public string $reason) {}
}
