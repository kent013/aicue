<?php

declare(strict_types=1);

use App\Enums\Billing\PersonalPlanIneligibleReason;
use App\Enums\OrganizationRole;
use App\Exceptions\Billing\PersonalPlanNotEligibleException;
use App\Models\Organization;
use App\Models\User;
use App\Services\Billing\PersonalPlanService;
use App\Services\Billing\TicketLedgerService;
use App\Services\Organization\OrganizationProvisioningService;
use Illuminate\Database\QueryException;

/*
|--------------------------------------------------------------------------
| PersonalPlanService (パーソナルプラン = free entitlement)
|--------------------------------------------------------------------------
|
| free entitlement は organizations.free_plan_code で表現する (subscriptions は Stripe 実体
| のみを保持する invariant を守る)。初回無償チケットは organizations.signup_tickets_granted_at
| マーカーの条件付き先取で「org 単位で生涯 1 回」に閉じる。
*/

function personalPlanService(): PersonalPlanService
{
    return app(PersonalPlanService::class);
}

function personalPlanBalance(Organization $organization): int
{
    return app(TicketLedgerService::class)->balance($organization);
}

function signupGrantEntryCount(Organization $organization): int
{
    return $organization->ticketLedgerEntries()
        ->where('idempotency_key', 'like', 'signup_grant:%')
        ->count();
}

describe('eligibility', function (): void {
    test('有効な有償契約 (active/trialing) がある組織は HasEntitledSubscription で不可', function (): void {
        [$organization, $owner] = createOrganizationWithOwner();
        createFakeSubscription($organization, status: 'active');

        $eligibility = personalPlanService()->eligibility($organization, $owner);

        expect($eligibility->eligible)->toBeFalse();
        expect($eligibility->reason)->toBe(PersonalPlanIneligibleReason::HasEntitledSubscription);
    });

    test('canceled サブスク行が残る組織は選択できる (paid → free 経路)', function (): void {
        [$organization, $owner] = createOrganizationWithOwner();
        createFakeSubscription($organization, status: 'canceled');

        expect(personalPlanService()->eligibility($organization, $owner)->eligible)->toBeTrue();
    });

    test('在籍 4 名の組織は TooManyMembers で不可 (キャップ 3 名)', function (): void {
        [$organization, $owner] = createOrganizationWithOwner();
        attachOrganizationMember($organization, OrganizationRole::Member);
        attachOrganizationMember($organization, OrganizationRole::Member);

        // owner + member 2 = 3 名: 許可
        expect(personalPlanService()->eligibility($organization, $owner)->eligible)->toBeTrue();

        // 4 名目で不可
        attachOrganizationMember($organization, OrganizationRole::Member);
        $eligibility = personalPlanService()->eligibility($organization, $owner);

        expect($eligibility->eligible)->toBeFalse();
        expect($eligibility->reason)->toBe(PersonalPlanIneligibleReason::TooManyMembers);
    });

    test('同一 user が declarer の free personal 組織を既に持つ場合は AlreadyHasFreePersonalOrg で不可', function (): void {
        [$first, $owner] = createOrganizationWithOwner('1 つ目の組織');
        personalPlanService()->activate($first, $owner);

        $second = app(OrganizationProvisioningService::class)->provision($owner, '2 つ目の組織');
        $eligibility = personalPlanService()->eligibility($second, $owner);

        expect($eligibility->eligible)->toBeFalse();
        expect($eligibility->reason)->toBe(PersonalPlanIneligibleReason::AlreadyHasFreePersonalOrg);
    });

    test('declarer ではないが owner として在籍する free personal 組織があれば不可', function (): void {
        // 既存 free 組織: declarer は別 user、対象 user は owner として在籍する
        [$freeOrg, $declarer] = createOrganizationWithOwner('既存 free 組織');
        personalPlanService()->activate($freeOrg, $declarer);

        $otherOwner = attachOrganizationMember($freeOrg, OrganizationRole::Owner);

        $second = app(OrganizationProvisioningService::class)->provision($otherOwner, '別組織');
        $eligibility = personalPlanService()->eligibility($second, $otherOwner);

        expect($eligibility->eligible)->toBeFalse();
        expect($eligibility->reason)->toBe(PersonalPlanIneligibleReason::AlreadyHasFreePersonalOrg);
    });

    test('declarer NULL の grandfathered free 組織は declarer 枠を占有しない', function (): void {
        // 自己申告の記録より前から free だった組織 (partial unique index の対象外)
        $user = User::factory()->create();
        Organization::factory()->grandfathered()->create();

        $organization = app(OrganizationProvisioningService::class)->provision($user, '新しい組織');

        expect(personalPlanService()->eligibility($organization, $user)->eligible)->toBeTrue();
    });
});

describe('activate', function (): void {
    test('free_plan_code / 自己申告の監査列 / マーカーが立ち、初回チケットが 1 回だけ付与される', function (): void {
        [$organization, $owner] = createOrganizationWithOwner();
        $expected = config()->integer('billing.signup_grant_tickets');

        $result = personalPlanService()->activate($organization, $owner);

        expect($result->granted)->toBeTrue();

        $organization->refresh();
        expect($organization->free_plan_code)->toBe(PersonalPlanService::FREE_PLAN_CODE);
        expect($organization->free_plan_activated_at)->not->toBeNull();
        expect($organization->personal_declared_at)->not->toBeNull();
        expect($organization->personal_declared_by_user_id)->toBe($owner->id);
        expect($organization->signup_tickets_granted_at)->not->toBeNull();

        expect(personalPlanBalance($organization))->toBe($expected);
        $entry = $organization->ticketLedgerEntries()->firstOrFail();
        expect($entry->idempotency_key)->toBe("signup_grant:personal:{$organization->id}");
        expect($entry->expires_at)->not->toBeNull();
    });

    test('同一組織の再 activate は granted=false で残高不変 (マーカー先取が 0 件)', function (): void {
        [$organization, $owner] = createOrganizationWithOwner();
        $expected = config()->integer('billing.signup_grant_tickets');

        personalPlanService()->activate($organization, $owner);
        $second = personalPlanService()->activate($organization, $owner);

        expect($second->granted)->toBeFalse();
        expect(personalPlanBalance($organization))->toBe($expected);
        expect(signupGrantEntryCount($organization))->toBe(1);
    });

    test('マーカー済み (backfill / paid 経験) の組織は付与なしで有効化のみ', function (): void {
        $owner = User::factory()->create();
        $organization = app(OrganizationProvisioningService::class)->provision($owner, 'マーカー済み組織');
        $organization->forceFill(['signup_tickets_granted_at' => now()->subYear()])->save();

        $result = personalPlanService()->activate($organization, $owner);

        expect($result->granted)->toBeFalse();
        expect($organization->refresh()->free_plan_code)->toBe(PersonalPlanService::FREE_PLAN_CODE);
        expect($organization->ticketLedgerEntries()->count())->toBe(0);
    });

    test('eligibility 不可の組織は PersonalPlanNotEligibleException で拒否され、付与されない', function (): void {
        [$organization, $owner] = createOrganizationWithOwner();
        createFakeSubscription($organization, status: 'active');

        expect(fn () => personalPlanService()->activate($organization, $owner))
            ->toThrow(PersonalPlanNotEligibleException::class);

        $organization->refresh();
        expect($organization->free_plan_code)->toBeNull();
        expect($organization->signup_tickets_granted_at)->toBeNull();
        expect($organization->ticketLedgerEntries()->count())->toBe(0);
    });

    test('並行 activate の後着は QueryException を漏らさず AlreadyHasFreePersonalOrg になる (500 にしない)', function (): void {
        // 並行 activate の窓 = 「eligibility は通ったが DB の partial unique index が拒否する」状態。
        // 先着 org を soft delete することで、eligibility の Organization::query() からは
        // 見えない (default scope) が index は declarer 枠を握ったままの状態を決定論的に作る。
        [$first, $owner] = createOrganizationWithOwner('先着の組織');
        personalPlanService()->activate($first, $owner);
        $first->delete();

        $second = app(OrganizationProvisioningService::class)->provision($owner, '後着の組織');

        // 前提: eligibility は通る (= DB へ到達して初めて弾かれる経路であることの固定)
        expect(personalPlanService()->eligibility($second, $owner)->eligible)->toBeTrue();

        try {
            personalPlanService()->activate($second, $owner);
            $this->fail('PersonalPlanNotEligibleException が投げられませんでした');
        } catch (QueryException $e) {
            $this->fail('QueryException が漏れています (500 になる): '.$e->getMessage());
        } catch (PersonalPlanNotEligibleException $e) {
            expect($e->reason)->toBe(PersonalPlanIneligibleReason::AlreadyHasFreePersonalOrg);
            expect($e->userMessage())->toBe(PersonalPlanIneligibleReason::AlreadyHasFreePersonalOrg->label());
        }

        // 後着はマーカーも付与も残さない (transaction ごと rollback される)
        $second->refresh();
        expect($second->free_plan_code)->toBeNull();
        expect($second->signup_tickets_granted_at)->toBeNull();
        expect($second->ticketLedgerEntries()->count())->toBe(0);
    });
});

describe('retireForPaidSubscription', function (): void {
    test('free_plan_code を null 化し、自己申告の監査列は残す (冪等)', function (): void {
        [$organization, $owner] = createOrganizationWithOwner();
        personalPlanService()->activate($organization, $owner);

        personalPlanService()->retireForPaidSubscription($organization->refresh());

        $organization->refresh();
        expect($organization->free_plan_code)->toBeNull();
        expect($organization->free_plan_activated_at)->not->toBeNull();
        expect($organization->personal_declared_at)->not->toBeNull();
        expect($organization->personal_declared_by_user_id)->toBe($owner->id);

        // 2 回目は no-op
        personalPlanService()->retireForPaidSubscription($organization);
        expect($organization->refresh()->free_plan_code)->toBeNull();
    });

    test('退役で declarer 枠が解放され、同一 user が別組織を free 化できる', function (): void {
        [$first, $owner] = createOrganizationWithOwner('1 つ目の組織');
        personalPlanService()->activate($first, $owner);
        personalPlanService()->retireForPaidSubscription($first->refresh());

        $second = app(OrganizationProvisioningService::class)->provision($owner, '2 つ目の組織');

        // 付与マーカーは org ごとに閉じるため 2 つ目は初回付与あり
        expect(personalPlanService()->activate($second, $owner)->granted)->toBeTrue();
    });
});
