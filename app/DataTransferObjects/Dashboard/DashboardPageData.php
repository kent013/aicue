<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Dashboard;

use App\Enums\Billing\OnboardingBillingState;
use App\Enums\Dashboard\DashboardRole;
use App\Enums\Dashboard\DashboardState;

/**
 * ダッシュボード props の頂点 DTO。state で 3 状態を明示:
 * - no_organization: 所属組織 0 件 (organization/project/billing すべて null)
 * - no_project: org はあるが project なし (billing のみ非 null)
 * - ready: 通常表示
 *
 * TS 側 types/dashboard.ts の DashboardData と対で保守する。
 */
final readonly class DashboardPageData
{
    /**
     * @param  list<InProgressManualData>  $inProgress
     * @param  list<RecentManualData>  $recentManuals
     * @param  list<ShootingTargetData>  $shootingTargets
     */
    public function __construct(
        public DashboardState $state,
        public ?DashboardRole $role,
        public bool $canCreateProject,
        public ?string $organizationName, // no_project の依頼先表示等 (org null のとき null)
        public ?int $projectId,
        public ?string $projectName,
        public array $inProgress,
        public array $recentManuals,
        public array $shootingTargets,
        public ?BillingSummaryData $billing,
    ) {}

    /**
     * @return array{state: 'no_organization'|'no_project'|'ready', role: string|null,
     *   can_create_project: bool, organization_name: string|null,
     *   project: array{id: int, name: string}|null,
     *   in_progress: list<array{manual_id: int, title: string, manual_status: string,
     *     job_status: string|null, progress: int|null, job_updated_at: string|null}>,
     *   recent_manuals: list<array{id: int, title: string, status: string,
     *     category_name: string|null, updated_at: string}>,
     *   shooting_targets: list<array{manual_id: int, title: string, cuts_count: int,
     *     pending_cuts_count: int}>,
     *   billing: array{ticket_balance: int, is_low_balance: bool, storage_used_bytes: int,
     *     storage_limit_bytes: int|null, storage_usage_percent: int|null,
     *     billing_state: value-of<OnboardingBillingState>}|null}
     */
    public function toArray(): array
    {
        return [
            'state' => $this->state->value,
            'role' => $this->role?->value,
            'can_create_project' => $this->canCreateProject,
            'organization_name' => $this->organizationName,
            'project' => ($this->projectId !== null && $this->projectName !== null)
                ? ['id' => $this->projectId, 'name' => $this->projectName]
                : null,
            'in_progress' => array_map(
                static fn (InProgressManualData $row): array => $row->toArray(),
                $this->inProgress,
            ),
            'recent_manuals' => array_map(
                static fn (RecentManualData $row): array => $row->toArray(),
                $this->recentManuals,
            ),
            'shooting_targets' => array_map(
                static fn (ShootingTargetData $row): array => $row->toArray(),
                $this->shootingTargets,
            ),
            'billing' => $this->billing?->toArray(),
        ];
    }
}
