<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrganizationRole;
use App\Models\Billing\BillingCheckoutSession;
use App\Models\Billing\OrganizationQuota;
use App\Models\Billing\Plan;
use App\Models\Billing\TicketLedgerEntry;
use App\Models\Billing\TicketReservation;
use Carbon\CarbonImmutable;
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
use ParagonIE\CipherSweet\BlindIndex;
use ParagonIE\CipherSweet\EncryptedRow;
use ParagonIE\CipherSweet\Transformation\Lowercase;
use Spatie\LaravelCipherSweet\Concerns\UsesCipherSweet;
use Spatie\LaravelCipherSweet\Contracts\CipherSweetEncrypted;
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
 *
 * P9: 請求先連絡先 (billing_contact_email / billing_contact_name) は PII のため
 * **両列とも CipherSweet で暗号化**する (セキュリティ不変条件 #6)。平文 where は hit しないため
 * email の検索は `whereBlind('billing_contact_email', 'organization_billing_contact_email_index', …)`
 * のみ (保存値は EmailNormalizer 正規化済みのため検索入力も同一正規化を通すこと)。
 * 両列とも $fillable 外 (UpdateBillingContactAction が明示代入する)。
 *
 * T141: 決済事業者側 customer の redaction 実施記録 (stripe_customer_redacted_at /
 * stripe_customer_redacted_id) は**人手操作の記録専用**で $fillable 外
 * (MarkStripeCustomerRedactedCommand が forceFill で明示代入する)。両列は同時に埋まり
 * 同時に NULL で、この不変条件は DB の CHECK 制約でも担保している。
 *
 * @property string|null $billing_contact_email
 * @property string|null $billing_contact_name
 * @property CarbonImmutable|null $stripe_customer_redacted_at
 * @property string|null $stripe_customer_redacted_id
 */
class Organization extends Model implements CipherSweetEncrypted
{
    /** @use HasFactory<OrganizationFactory> */
    use Billable, HasFactory, RoutesNotifications, SoftDeletes, UsesCipherSweet;

    /**
     * billing_contact_* は含めない (UpdateBillingContactAction が明示代入する)。
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'slug',
    ];

    /**
     * 請求先連絡先の暗号化設定 (不変条件 #6)。
     *
     * 両列とも nullable のため `addOptionalTextField` を使う
     * (`addField` は null で fieldNotOptional 例外になる = Inquiry の先例)。
     */
    public static function configureCipherSweet(EncryptedRow $encryptedRow): void
    {
        $encryptedRow
            ->addOptionalTextField('billing_contact_email')
            ->addOptionalTextField('billing_contact_name')
            // 検索契約: 請求調査 (Stripe Dashboard の請求先メール → AI-CUE 組織の逆引き =
            // 返金・二重課金の一次対応で唯一の特定経路) のため email のみ blind index 化する。
            ->addBlindIndex(
                'billing_contact_email',
                new BlindIndex('organization_billing_contact_email_index', [new Lowercase]),
            );
        // billing_contact_name は blind index を張らない
        // (等値検索の要求が無い = 検索が必要な項目だけ whereBlind)。
    }

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
     * サブスク契約 / カード登録 Checkout の追跡行 (P2 で導入。P9 の着地 feedback /
     * T1004 の着地 flash が **org スコープ**で引くために必要)。
     *
     * @return HasMany<BillingCheckoutSession, $this>
     */
    public function checkoutSessions(): HasMany
    {
        return $this->hasMany(BillingCheckoutSession::class);
    }

    /**
     * 請求通知の宛先 (BillingNotificationDispatcher が組織宛に notify する)。
     *
     * P9: `billing_contact_email` が正本で、未設定なら Owner メンバーの email へ fallback する。
     * Owner も解決できない場合は null (dispatcher が failed(missing_billing_recipient)
     * として確定し queued 滞留を防ぐ)。
     */
    public function routeNotificationForMail(Notification $notification): ?string
    {
        return $this->billingContactEmail();
    }

    /**
     * Stripe customer に同期する請求先メール (Cashier の syncStripeCustomerDetails が読む)。
     *
     * Organization は `email` 列を持たないため Cashier 既定では null になる。請求先の正本を
     * 明示して「請求書が届く先」を Stripe 側と一致させる。**宛名 (billing_contact_name) は
     * Stripe へ送らない** (`stripeName()` は組織名のまま = 送信内容の境界を広げない)。
     */
    public function stripeEmail(): ?string
    {
        return $this->billingContactEmail();
    }

    /**
     * 請求関連の宛先メール (billing_contact_email 正本 → owner email fallback)。
     * 通知宛先と checkout 事前検証 (SubscriptionService::assertCheckoutReady) の共通出典。
     */
    public function billingContactEmail(): ?string
    {
        $contact = $this->billing_contact_email;
        if (is_string($contact) && trim($contact) !== '') {
            return $contact;
        }

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
            // T141: 決済事業者側 customer の redaction 実施記録。人手操作の記録専用で
            // $fillable 外 (MarkStripeCustomerRedactedCommand が forceFill で明示代入する)。
            // 両列は同時に埋まり同時に NULL (DB の CHECK 制約でも担保)。
            'stripe_customer_redacted_at' => 'immutable_datetime',
        ];
    }
}
