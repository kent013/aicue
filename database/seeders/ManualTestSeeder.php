<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Billing\PlanPriceKind;
use App\Enums\OrganizationRole;
use App\Models\Billing\Plan;
use App\Models\Organization;
use App\Models\User;
use App\Services\Billing\PersonalPlanService;
use App\Services\Organization\OrganizationProvisioningService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * 手動テスト用シーダー (ドメイン非依存の汎用版)。
 * プランごとに組織を 1 つ作り、各組織ロールのユーザーを総当たりで投入する。
 * さらに複数組織所属ユーザーとメール未認証ユーザーを 1 人ずつ作る。
 *
 * 実行: php artisan db:seed --class=ManualTestSeeder
 * 全ユーザーのパスワード: password123
 * email 規則: {role}-{plan_code}@example.com (例: owner-personal@example.com)
 */
class ManualTestSeeder extends Seeder
{
    private const PASSWORD = 'password123';

    public function run(): void
    {
        // 前提の参照データ (すべて冪等)
        $this->call([
            RoleSeeder::class,
            PermissionSeeder::class,
            RolePermissionSeeder::class,
            PlanSeeder::class,
        ]);

        $plans = Plan::query()->orderBy('sort_order')->get();
        if ($plans->isEmpty()) {
            $this->command->warn('ManualTestSeeder: plans が空のため skip しました。');

            return;
        }

        // 冪等チェック (最初のプランの Owner ユーザーが居れば投入済みとみなす)
        $firstEmail = $this->email(OrganizationRole::Owner, $plans->first());
        if (User::whereBlind('email', 'email_index', $firstEmail)->exists()) {
            $this->command->info('ManualTestSeeder: 投入済みのため skip しました。');

            return;
        }

        $rows = [];
        $organizations = [];

        foreach ($plans as $plan) {
            $organization = null;

            foreach (OrganizationRole::cases() as $role) {
                $email = $this->email($role, $plan);
                $user = $this->createUser($this->displayName($role, $plan), $email);

                if ($organization === null) {
                    // 最初のユーザー (Owner) を creator として組織を provisioning する
                    // (Default Team + Owner ロール + current_organization_id まで揃う)
                    $organization = $this->createOrganization($user, $plan);
                } else {
                    $this->addToOrganization($user, $organization, $role, current: true);
                }

                $rows[] = [$email, $organization->name, $role->label(), $plan->code];
            }

            $organizations[] = $organization;
        }

        // 複数組織所属ユーザー (全プラン組織に Member で所属。組織切替の手動テスト用)
        $multi = $this->createUser('Multi Org User', 'multi-org@example.com');
        foreach ($organizations as $index => $organization) {
            $this->addToOrganization($multi, $organization, OrganizationRole::Member, current: $index === 0);
        }
        $rows[] = ['multi-org@example.com', '全プラン組織', OrganizationRole::Member->label(), '-'];

        // メール未認証ユーザー (メール認証フローの手動テスト用)
        $unverified = $this->createUser('Unverified User', 'unverified@example.com', verified: false);
        $this->addToOrganization($unverified, $organizations[0], OrganizationRole::Member, current: true);
        $rows[] = ['unverified@example.com', $organizations[0]->name, OrganizationRole::Member->label().' (未認証)', $plans->first()->code];

        $this->command->info('ManualTestSeeder: 投入完了 (パスワードは全員 '.self::PASSWORD.')');
        $this->command->table(['Email', 'Organization', 'Role', 'Plan'], $rows);
    }

    /**
     * email 規則: {role}-{plan_code}@example.com (role は enum 値の末尾セグメント)。
     */
    private function email(OrganizationRole $role, Plan $plan): string
    {
        return Str::afterLast($role->value, '_')."-{$plan->code}@example.com";
    }

    private function displayName(OrganizationRole $role, Plan $plan): string
    {
        return "{$plan->name} ".Str::title(Str::afterLast($role->value, '_'));
    }

    private function createUser(string $name, string $email, bool $verified = true): User
    {
        $factory = User::factory();
        if (! $verified) {
            $factory = $factory->unverified();
        }

        return $factory->create([
            'name' => $name,
            'email' => $email,
            'password' => self::PASSWORD, // hashed cast が自動でハッシュ化する
        ]);
    }

    /**
     * 組織生成は provisioning 経由 (Default Team パターンの不変条件を担保する唯一の窓口)。
     *
     * plan_code の不変条件を尊重する: 「plan_code は Stripe Price を持つ有償プランの契約状態でのみ
     * set される」(Model/StripeWebhookProcessor の docblock が定める)。
     * よって有償プラン (current base Price あり) のときのみ plan_code を forceFill し、あわせて
     * active な Cashier subscription 行を投入する (plan_code 非 null ⇔ 契約行あり を seed でも満たす)。
     * 無料プラン (Price 無し) は plan_code を null のまま PersonalPlanService::activate() で
     * free entitlement (free_plan_code='personal' / declarer = owner) を立てる。課金ゲートは
     * plan_code を見ないため、activate しないと手動テスト環境の無料組織が締め出される。
     *
     * 有償/無料の判定は Plan の「値」(current base Price の有無) からのみ導出し、プラン名 (code) の
     * 文字列比較はしない (AGENTS.md ドメイン規約)。
     */
    private function createOrganization(User $owner, Plan $plan): Organization
    {
        $organization = app(OrganizationProvisioningService::class)
            ->provision($owner, "{$plan->name}プラン組織");

        // current な base Price を持つ = Checkout 可能な有償プラン。plan_code は状態キー ($fillable 外)
        if ($plan->currentPrice(PlanPriceKind::Base) !== null) {
            $organization->forceFill(['plan_code' => $plan->code])->save();
            $this->attachFakeActiveSubscription($organization);

            return $organization;
        }

        // 無料プラン: marker + 初回無償チケット付与も activate 内で org 生涯 1 回だけ走る
        app(PersonalPlanService::class)->activate($organization, $owner);

        return $organization->refresh();
    }

    /**
     * 手動テスト用に active な Cashier subscription 行を直接投入する (Stripe API 非到達)。
     * 課金ゲート (BillingAccess::state()) は entitled な subscription か free entitlement を
     * 要求するため、有償組織は本行が無いと締め出される。
     * subscription('default') が active を返すための最小カラムのみを設定する。
     *
     * メソッド単体で冪等: 既に default subscription があれば作らない (run() の冪等 guard に依存せず、
     * 部分実行・手動呼び出し・将来の guard 変更でも重複行を生まない)。
     */
    private function attachFakeActiveSubscription(Organization $organization): void
    {
        if ($organization->subscription('default') !== null) {
            return; // 冪等: 既存の default subscription を尊重する
        }

        $organization->subscriptions()->create([
            'type' => 'default',
            'stripe_id' => 'sub_seed_'.Str::random(24),
            'stripe_status' => 'active',
            'quantity' => 1,
        ]);
    }

    private function addToOrganization(
        User $user,
        Organization $organization,
        OrganizationRole $role,
        bool $current = false,
    ): void {
        $organization->users()->attach($user);
        $user->addRole($role->value, $organization->laratrust_team_id);

        if ($current && $user->current_organization_id === null) {
            $user->forceFill(['current_organization_id' => $organization->id])->save();
        }
    }
}
