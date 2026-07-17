<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\DataTransferObjects\Billing\PersonalPlanActivationResultDto;
use App\DataTransferObjects\Billing\PersonalPlanEligibilityDto;
use App\Enums\Billing\PersonalPlanIneligibleReason;
use App\Enums\OrganizationRole;
use App\Exceptions\Billing\PersonalPlanNotEligibleException;
use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Webmozart\Assert\Assert;

/**
 * Personal (free) プランの有効化・退役・選択可否判定を集約する。
 *
 * Personal 前提で閉じる (汎用 free plan framework にはしない)。`subscriptions` テーブルは
 * Stripe 実体のみを保持する invariant を守り、free entitlement は organizations 側の
 * `free_plan_code` で表現する。
 *
 * farming 防止の層別:
 * - hard invariant (DB): 「1 user が declarer である active free personal org は 1 つ」
 *   (partial unique index `organizations_personal_free_declarer_unique`)
 * - best-effort (UX/abuse 抑止): owner 条件 (declarer でない owner の別 org) は eligibility の
 *   事前判定のみ。残余があっても初回付与は org 単位 1 回マーカーで有限
 */
final class PersonalPlanService
{
    public const string FREE_PLAN_CODE = 'personal';

    /**
     * 在籍数ハードキャップ (= config('quota.plans.personal.max_members') と一致。
     * invariant test で固定する)。
     */
    public const int MAX_MEMBERS = 3;

    /** アクセスを許可する Stripe subscription status (BillingAccess::GRANTING_STATUSES と同値) */
    private const array GRANTING_STATUSES = ['active', 'trialing'];

    public function __construct(
        private readonly TicketLedgerService $tickets,
    ) {}

    /**
     * UI 表示用: この org/user で Personal (free) を選択できるか + 不可理由。
     *
     * activate() の事前判定 (親切なエラー) であり真実源ではない (真実源は DB 制約 +
     * activate() 内の再検証)。
     */
    public function eligibility(Organization $org, User $user): PersonalPlanEligibilityDto
    {
        if ($this->hasEntitledSubscription($org)) {
            return PersonalPlanEligibilityDto::ineligible(PersonalPlanIneligibleReason::HasEntitledSubscription);
        }

        if ($org->users()->count() > self::MAX_MEMBERS) {
            return PersonalPlanEligibilityDto::ineligible(PersonalPlanIneligibleReason::TooManyMembers);
        }

        if ($this->hasOtherActiveFreePersonalOrg($org, $user)) {
            return PersonalPlanEligibilityDto::ineligible(PersonalPlanIneligibleReason::AlreadyHasFreePersonalOrg);
        }

        return PersonalPlanEligibilityDto::eligible();
    }

    /**
     * Personal (free) を有効化する。自己申告 (declaration) の入力検証は FormRequest の責務で、
     * 本メソッドは business invariant を transaction 内で再検証して確定する。
     *
     * 初回無償チケットは「org 単位で生涯 1 回」: `signup_tickets_granted_at` を
     * 条件付き UPDATE で先取した経路のみ付与する (paid webhook 経路と対称、同一 TX 内なので
     * 付与失敗時はマーカーごと rollback される)。
     *
     * @throws PersonalPlanNotEligibleException 並行 activate の後着 (declarer unique 違反) を含む
     */
    public function activate(Organization $org, User $declarer): PersonalPlanActivationResultDto
    {
        try {
            return $this->activateWithinTransaction($org, $declarer);
        } catch (QueryException $e) {
            // partial unique index (declarer 単位) 違反 = 並行 activate の後着。500 にしない。
            if ($this->isDeclarerUniqueViolation($e)) {
                throw new PersonalPlanNotEligibleException(
                    PersonalPlanIneligibleReason::AlreadyHasFreePersonalOrg,
                    previous: $e,
                );
            }

            throw $e;
        }
    }

    /**
     * paid サブスク成立時の free 退役 (webhook 経由。冪等)。
     *
     * declared_at / declared_by / activated_at は監査証跡として残す。free_plan_code を null に
     * することで partial unique index の declarer 枠も解放される。
     */
    public function retireForPaidSubscription(Organization $org): void
    {
        if ($org->free_plan_code === null) {
            return;
        }

        $org->forceFill(['free_plan_code' => null])->save();
    }

    /**
     * 初回付与マーカー (`signup_tickets_granted_at`) を条件付き UPDATE で先取する。
     * 先取できた (= この呼び出しが org 生涯で初回) ときのみ true。
     *
     * **移行期専用の public API**: signup grant の付与契機が登録時 (CreateNewUser) のままの間、
     * 登録経路からも marker を立てる必要があるため public にしている。付与契機を activate へ
     * 移す P6 で private へ戻す (詳細設計 D13)。呼び出し側は org 行 lockForUpdate 下・付与と
     * 同一 transaction で使うこと (先取と付与が原子的でないと二重付与の窓ができる)。
     */
    public function claimSignupGrantMarker(Organization $org, ?CarbonImmutable $now = null): bool
    {
        $claimed = DB::table('organizations')
            ->where('id', $org->getKey())
            ->whereNull('signup_tickets_granted_at')
            ->update(['signup_tickets_granted_at' => $now ?? CarbonImmutable::now()]);

        return $claimed === 1;
    }

    private function activateWithinTransaction(Organization $org, User $declarer): PersonalPlanActivationResultDto
    {
        $organizationId = $org->getKey();
        Assert::integer($organizationId, 'Organization の主キーは整数を想定しています');

        return DB::transaction(function () use ($organizationId, $declarer): PersonalPlanActivationResultDto {
            // org 行 lock で同一 org への並行 activate / paid webhook の付与競合を直列化する
            // (TicketLedgerService::reserve と同じパターン)。
            $fresh = Organization::query()->lockForUpdate()->findOrFail($organizationId);

            $eligibility = $this->eligibility($fresh, $declarer);
            if (! $eligibility->eligible) {
                $reason = $eligibility->reason ?? PersonalPlanIneligibleReason::HasEntitledSubscription;

                throw new PersonalPlanNotEligibleException($reason);
            }

            $now = CarbonImmutable::now();
            $fresh->forceFill([
                'free_plan_code' => self::FREE_PLAN_CODE,
                'free_plan_activated_at' => $now,
                'personal_declared_at' => $now,
                'personal_declared_by_user_id' => $declarer->getKey(),
            ])->save();

            $granted = $this->claimSignupGrantMarker($fresh, $now);
            if ($granted) {
                $this->tickets->grantSignupGrant($fresh, "signup_grant:personal:{$organizationId}");
            }

            return new PersonalPlanActivationResultDto(granted: $granted);
        });
    }

    /**
     * 有効な (entitled) 有償サブスクを持つか。
     *
     * **P2 の seam**: 現状は `subscription('default')` の stripe_status が active / trialing か
     * で判定する (= 現行 `BillingAccess::GRANTING_STATUSES` と同値)。P2 で
     * `SubscriptionService::deriveEntitlement($sub)->entitled` へ差し替える
     * (支払い手段・trial 有無を含む aigenba の entitlement 判定に一致させる)。
     */
    private function hasEntitledSubscription(Organization $org): bool
    {
        $subscription = $org->subscription('default');

        return $subscription !== null
            && in_array($subscription->stripe_status, self::GRANTING_STATUSES, true);
    }

    /**
     * 同一 user が declarer または owner (OrganizationRole::Owner) である別の active free
     * personal org が存在するか。
     */
    private function hasOtherActiveFreePersonalOrg(Organization $org, User $user): bool
    {
        return Organization::query()
            ->where('free_plan_code', self::FREE_PLAN_CODE)
            ->whereKeyNot($org->getKey())
            ->where(function (Builder $q) use ($user): void {
                $q->where('personal_declared_by_user_id', $user->getKey())
                    ->orWhereHas('users', function (Builder $uq) use ($user): void {
                        $uq->whereKey($user->getKey())
                            ->whereHas('roles', function (Builder $rq): void {
                                $rq->where('name', OrganizationRole::Owner->value)
                                    ->whereColumn('role_user.team_id', 'organizations.laratrust_team_id');
                            });
                    });
            })
            ->exists();
    }

    /**
     * partial unique index `organizations_personal_free_declarer_unique` の違反か
     * (driver 差吸収: MySQL/SQLite=23000, PostgreSQL=23505。SQLite は index 名を含まない
     * 列名形式のため両方を見る)。
     *
     * getCode() の宣言型は int だが PDO 由来は SQLSTATE 文字列を返すため、比較前に string へ
     * 正規化する。
     */
    private function isDeclarerUniqueViolation(QueryException $e): bool
    {
        if (! in_array((string) $e->getCode(), ['23000', '23505'], true)) {
            return false;
        }

        $message = $e->getMessage();

        return str_contains($message, 'organizations_personal_free_declarer_unique')
            || str_contains($message, 'organizations.personal_declared_by_user_id');
    }
}
