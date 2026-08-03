<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrganizationRole;
use App\Models\Billing\OrganizationQuota;
use App\Models\Billing\Plan;
use App\Models\Billing\TicketLedgerEntry;
use App\Models\Billing\TicketReservation;
use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\RoutesNotifications;
use Laravel\Cashier\Billable;
use Webmozart\Assert\Assert;

/**
 * 組織 (テナント) = 課金主体 (Cashier Billable。AppServiceProvider の
 * Cashier::useCustomerModel 参照)。Laratrust Team と 1:1 で、権限判定は常に
 * laratrust_team_id を明示して行う (strict_check=true)。
 *
 * laratrust_team_id / is_personal は所有権・状態キーのため $fillable 外
 * (OrganizationProvisioningService が明示代入する)。
 * plan_code は現在プランの状態キーのため $fillable 外 (StripeWebhookProcessor が
 * webhook から同期する。クライアント入力では変更できない)。
 * plan_code は Stripe Price を持つ有償プランの契約 (active/trialing) 時のみ set され、
 * subscription.deleted で null に戻る。**null = 未契約 = 支払い不要の free tier**
 * (config/quota.php の fallback_plan が適用され、BillingAccess は業務 route を許可する)。
 *
 * free entitlement (パーソナルプラン) は plan_code ではなく free_plan_code 側で表現する
 * (`subscriptions` テーブルは Stripe 実体のみを保持する invariant を守るため)。
 * free_plan_code / free_plan_activated_at / personal_declared_* / signup_tickets_granted_at は
 * いずれも状態キーのため $fillable 外 (PersonalPlanService の forceFill 経由でのみ書き込む)。
 */
class Organization extends Model
{
    /** @use HasFactory<OrganizationFactory> */
    use Billable, HasFactory, RoutesNotifications, SoftDeletes;

    /** @var list<string> */
    protected $fillable = [
        'name',
        'slug',
    ];

    /**
     * @return BelongsTo<Team, $this>
     */
    public function laratrustTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'laratrust_team_id');
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    /**
     * @return HasMany<CustomTeam, $this>
     */
    public function customTeams(): HasMany
    {
        return $this->hasMany(CustomTeam::class);
    }

    /**
     * @return HasManyThrough<Project, CustomTeam, $this>
     */
    public function projects(): HasManyThrough
    {
        return $this->hasManyThrough(Project::class, CustomTeam::class);
    }

    /**
     * @return HasMany<OrganizationInvitation, $this>
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(OrganizationInvitation::class);
    }

    /**
     * @return HasMany<ApiKey, $this>
     */
    public function apiKeys(): HasMany
    {
        return $this->hasMany(ApiKey::class);
    }

    /**
     * OAuth セッション (CLI ログイン)。組織管理経路の一覧/失効と scopeBindings の解決に使う。
     *
     * @return HasMany<OauthSession, $this>
     */
    public function oauthSessions(): HasMany
    {
        return $this->hasMany(OauthSession::class);
    }

    /**
     * 現在の契約プラン (plan_code → plans.code)。
     *
     * plan_code は **quota 解決キー** であり利用可否 (entitlement) には使わない
     * (null = config/quota.php の fallback_plan が効く、それだけの意味)。業務 route の
     * 利用可否は BillingAccess::state() が決める (無料枠は free_plan_code='personal')。
     *
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'plan_code', 'code');
    }

    /**
     * Quota override (1:1。無ければ config/quota.php のプラン既定値のみが効く)。
     *
     * @return HasOne<OrganizationQuota, $this>
     */
    public function quota(): HasOne
    {
        return $this->hasOne(OrganizationQuota::class);
    }

    /**
     * @return HasMany<TicketLedgerEntry, $this>
     */
    public function ticketLedgerEntries(): HasMany
    {
        return $this->hasMany(TicketLedgerEntry::class);
    }

    /**
     * @return HasMany<TicketReservation, $this>
     */
    public function ticketReservations(): HasMany
    {
        return $this->hasMany(TicketReservation::class);
    }

    /**
     * Default Team (docs/default-team-pattern.md)。
     * provisioning が必ず生成するため、存在しないのは不変条件違反。
     */
    public function defaultTeam(): CustomTeam
    {
        $team = $this->customTeams()->where('is_default', true)->first();
        Assert::isInstanceOf($team, CustomTeam::class, "Organization {$this->id} に Default Team がありません (不変条件違反)");

        return $team;
    }

    /**
     * 請求通知の宛先 (BillingNotificationDispatcher が組織宛に notify する)。
     * テンプレートは請求先メール列を持たないため Owner メンバーの email に送る。
     * Owner を解決できない場合は null (dispatcher が failed(missing_billing_recipient)
     * として確定し queued 滞留を防ぐ)。
     * 派生アプリは billing_contact_email 等の正本列を追加して本メソッドを上書きする。
     */
    public function routeNotificationForMail(Notification $notification): ?string
    {
        /** @var User|null $owner */
        $owner = $this->users()
            ->get()
            ->first(fn (User $member): bool => $member->organizationRole($this) === OrganizationRole::Owner);

        return $owner?->email;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_personal' => 'boolean',
            // 2FA 必須方針。セキュリティ方針キーのため $fillable 外
            // (OrganizationController::updateTwoFactorRequirement が forceFill で明示代入する)
            'two_factor_required' => 'boolean',
            // free entitlement (パーソナルプラン) と初回付与マーカー。いずれも状態キーのため
            // $fillable 外 (PersonalPlanService が forceFill で明示代入する)
            'free_plan_activated_at' => 'immutable_datetime',
            'personal_declared_at' => 'immutable_datetime',
            'personal_declared_by_user_id' => 'integer',
            'signup_tickets_granted_at' => 'immutable_datetime',
        ];
    }
}
