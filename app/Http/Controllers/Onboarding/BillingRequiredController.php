<?php

declare(strict_types=1);

namespace App\Http\Controllers\Onboarding;

use App\DataTransferObjects\Onboarding\BillingRequiredDto;
use App\Enums\Inquiry\InquirySource;
use App\Enums\OrganizationRole;
use App\Http\Concerns\ResolvesRouteOrganization;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\User;
use App\Services\Billing\BillingAccess;
use App\Services\Marketing\ContactUrl;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * 未契約 + manageBilling 権限なしの member 向け説明画面 (current org スコープ)。
 *
 * 「組織管理者が課金手続きを完了するのをお待ちください」と Owner 連絡先を表示する。
 * 403 ではなく専用 Inertia ページで「行き先のない無限ループ」を回避する。
 */
final class BillingRequiredController extends Controller
{
    use ResolvesRouteOrganization;

    public function __construct(
        private readonly BillingAccess $access,
        private readonly ContactUrl $contactUrl,
    ) {}

    public function show(Request $request, Organization $organization): Response|RedirectResponse
    {
        // IDOR 二重防御
        Gate::authorize('view', $organization);

        // 離脱ガード。billing-required は「未契約 かつ manageBilling なし member」専用の
        // 説明画面。それ以外がここに来たら本来の行き先へ逃がし「行き先のない詰み」を回避する。
        //   - 既に利用可 (有効 subscription / free personal) → 見せる理由なし → ダッシュボードへ
        //   - manageBilling 保持者 (owner / admin / 個別付与 member) → 自分で手続き可 → checkout へ
        if ($this->access->state($organization)->grantsAccess()) {
            return redirect()->route('dashboard', ['organization' => $organization->slug]);
        }
        if (Gate::allows('manageBilling', $organization)) {
            return redirect()->route('onboarding.checkout', ['organization' => $organization->slug]);
        }

        // Owner をロール経由で解決 (組織のメンバー数は通常 数〜数十なので filter で十分。
        // Organization::routeNotificationForMail() と同一パターン)。
        $owner = $organization->users()->get()
            ->first(static fn (User $u): bool => $u->organizationRole($organization) === OrganizationRole::Owner);

        $dto = new BillingRequiredDto(
            ownerName: $owner instanceof User ? $owner->name : null,
            ownerEmail: $owner instanceof User ? $owner->email : null,
            contactUrl: $this->contactUrl->resolveForSource(InquirySource::Onboarding),
        );

        return Inertia::render('Onboarding/BillingRequired', [
            'organization' => $this->organizationProps($organization),
            'pageData' => $dto->toArray(),
        ]);
    }

    /**
     * @return array{id: int, name: string, slug: string}
     */
    private function organizationProps(Organization $organization): array
    {
        return [
            'id' => $organization->id,
            'name' => $organization->name,
            'slug' => $organization->slug,
        ];
    }
}
