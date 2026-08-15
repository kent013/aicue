<?php

declare(strict_types=1);

namespace Tests\Support\ExternalSeam;

use App\Actions\Fortify\UpdateUserProfileInformation;
use App\Actions\Inquiry\CreateInquiryAction;
use App\Console\Commands\Billing\EnsurePortalConfiguration;
use App\Enums\Security\ExternalSeamClassification;
use App\Enums\Security\ExternalSeamDimension;
use App\Enums\Security\ExternalSeamKind;
use App\Providers\AppServiceProvider;
use App\Services\Auth\SocialiteDriverResolver;
use App\Services\Billing\CashierAutoRechargeGateway;
use App\Services\Billing\CashierStripeGateway;
use App\Services\Billing\CashierTicketCheckoutGateway;
use App\Services\Billing\StripeScheduleGateway;
use App\Services\Captcha\RecaptchaVerifier;
use App\Services\FxRateService;
use App\Services\Organization\OrganizationMembershipService;
use Tests\Support\ExternalClientBoundaryScanner;
use Tests\Support\Prompts\PrismDirectDispatchScanner;

/**
 * 外部到達点の目録の**正本** (deny-by-default)。
 *
 * ★グローバル定数ではなく **static メソッド**に置く (`--parallel` 規律。
 *   Pest のファイル直下 const は他テストファイルから見えない)。
 * ★目録の実体は **「app/ のコード到達点 + 明示的に委譲した宛先集合」**である。
 *   `routes/` / `config/` に書かれた到達コードは見ない (`docs/architecture.md` に明記)。
 */
final class ExternalSeamInventory
{
    /**
     * 種別ごとに検知が必要な次元 (exact-fit。enum 全 case を覆うことを gate が検査する)。
     *
     * ★`Payment` に `DestinationSet` を要求しない: Stripe の宛先は API キーが指す account であり、
     *   設定面の走査対象にしていない (`docs/architecture.md` の「保証しないもの」に明記)。
     *
     * @return array<string, list<ExternalSeamDimension>> kind->value => 必要な次元
     */
    public static function requiredDimensions(): array
    {
        return [
            ExternalSeamKind::Payment->value => [ExternalSeamDimension::CodeReachPoint],
            ExternalSeamKind::SocialLogin->value => [
                ExternalSeamDimension::CodeReachPoint,
                ExternalSeamDimension::DestinationSet,
            ],
            ExternalSeamKind::Captcha->value => [ExternalSeamDimension::CodeReachPoint],
            ExternalSeamKind::Mail->value => [ExternalSeamDimension::CodeReachPoint],
            ExternalSeamKind::MarketData->value => [ExternalSeamDimension::CodeReachPoint],
            ExternalSeamKind::ObjectStorage->value => [ExternalSeamDimension::CodeReachPoint],
            ExternalSeamKind::Llm->value => [ExternalSeamDimension::CodeReachPoint],
        ];
    }

    /**
     * `SocialLogin` の正規経路 (**名指し固定**)。
     *
     * ★標準形 v1「正規経路へ集約し直呼びを構文解析で禁止」の機械化。
     *   この 1 クラス以外は `Guarded` でも `Exempt` でも登録できない。
     *   ★T153: 差し替え先 (SSO fake) を作るため、集約先を controller から
     *     `SocialiteDriverResolver` へ切り出した。container の差し替えキーになれるのは
     *     controller ではなくこの薄い解決点である。
     *
     * @return class-string
     */
    public static function socialLoginFunnel(): string
    {
        return SocialiteDriverResolver::class;
    }

    /** @return list<ExternalSeamEntry> */
    public static function entries(): array
    {
        return [
            // --- payment (6 クラス。fake 配線の有無は問わず「守る対象」として登録する) ---
            new ExternalSeamEntry(
                class: CashierStripeGateway::class,
                kind: ExternalSeamKind::Payment,
                classification: ExternalSeamClassification::Guarded,
                rationale: 'サブスク Checkout / Customer Portal の Stripe 到達点。StripeGatewayInterface 経由で fake へ差し替わる',
            ),
            new ExternalSeamEntry(
                class: CashierTicketCheckoutGateway::class,
                kind: ExternalSeamKind::Payment,
                classification: ExternalSeamClassification::Guarded,
                rationale: 'チケットスポット購入の Stripe Checkout 到達点。TicketCheckoutGateway 経由で fake へ差し替わる',
            ),
            new ExternalSeamEntry(
                class: CashierAutoRechargeGateway::class,
                kind: ExternalSeamKind::Payment,
                classification: ExternalSeamClassification::Guarded,
                rationale: 'オートリチャージの off-session invoice 到達点。AutoRechargeGatewayInterface 経由で fake へ差し替わる',
            ),
            new ExternalSeamEntry(
                class: StripeScheduleGateway::class,
                kind: ExternalSeamKind::Payment,
                classification: ExternalSeamClassification::Guarded,
                rationale: 'subscription schedule の Stripe 到達点。消費点は artisan コマンドのみで bug-hunt からは到達しない',
            ),
            new ExternalSeamEntry(
                class: EnsurePortalConfiguration::class,
                kind: ExternalSeamKind::Payment,
                classification: ExternalSeamClassification::Guarded,
                rationale: 'Customer Portal 設定を投入する保守コマンドの Stripe 到達点。人手実行のみで web 経路から呼ばれない',
            ),
            new ExternalSeamEntry(
                class: AppServiceProvider::class,
                kind: ExternalSeamKind::Payment,
                classification: ExternalSeamClassification::Guarded,
                rationale: 'PriceService を Cashier::stripe()->prices で束ねる container 配線点。差し替えはこの bind を経由する',
            ),

            // --- social_login (1 クラス。名指し固定) ---
            new ExternalSeamEntry(
                class: SocialiteDriverResolver::class,
                kind: ExternalSeamKind::SocialLogin,
                classification: ExternalSeamClassification::Guarded,
                rationale: 'SSO の唯一の正規経路。他クラスからの Socialite::driver() は本目録に登録できず必ず赤くなる。'
                    .'非本番は FakeSocialiteDriverResolver へ container bind で差し替わる',
            ),

            // --- captcha (1 クラス) ---
            new ExternalSeamEntry(
                class: RecaptchaVerifier::class,
                kind: ExternalSeamKind::Captcha,
                classification: ExternalSeamClassification::Guarded,
                rationale: 'Google siteverify の到達点。非本番は RecaptchaVerifierTestFake へ container bind で差し替わる',
            ),

            // --- mail (3 クラス) ---
            new ExternalSeamEntry(
                class: CreateInquiryAction::class,
                kind: ExternalSeamKind::Mail,
                classification: ExternalSeamClassification::Guarded,
                rationale: '問い合わせ受付の通知メール送信点。外部到達の有無は mailer driver 設定が決める (testing=array / bughunt=log)',
            ),
            new ExternalSeamEntry(
                class: OrganizationMembershipService::class,
                kind: ExternalSeamKind::Mail,
                classification: ExternalSeamClassification::Guarded,
                rationale: '組織招待メールの on-demand 送信点。外部到達の有無は mailer driver 設定が決める',
            ),
            new ExternalSeamEntry(
                class: UpdateUserProfileInformation::class,
                kind: ExternalSeamKind::Mail,
                classification: ExternalSeamClassification::Guarded,
                rationale: 'メールアドレス変更の旧アドレス宛て通知の送信点。外部到達の有無は mailer driver 設定が決める',
            ),

            // --- market_data (1 クラス) ---
            new ExternalSeamEntry(
                class: FxRateService::class,
                kind: ExternalSeamKind::MarketData,
                classification: ExternalSeamClassification::Guarded,
                rationale: '為替レート取得の到達点。標準形 v1 の 6 種に無い aicue 固有の外向き経路で cache 前段に置かれる',
            ),
        ];
    }

    /** @return list<ExternalSeamDelegation> */
    public static function delegations(): array
    {
        $repoRoot = dirname(__DIR__, 3);

        return [
            new ExternalSeamDelegation(
                kind: ExternalSeamKind::ObjectStorage,
                dimension: ExternalSeamDimension::CodeReachPoint,
                gateFile: 'tests/Architecture/ExternalClientTimeoutInventoryTest.php',
                gateTestName: '到達境界: AWS / Flysystem へ到達するクラスは目録と対称差ゼロ',
                livenessProbe: static function () use ($repoRoot): array {
                    $sites = [];
                    foreach (ExternalClientBoundaryScanner::phpFiles($repoRoot.'/app', 'app') as $relative => $source) {
                        array_push($sites, ...ExternalClientBoundaryScanner::boundarySites($relative, $source));
                    }

                    return $sites;
                },
                rationale: 'AWS / Flysystem 到達クラスの既定拒否目録は T126 が正本。同じ到達事実を本目録で再宣言しない',
            ),
            new ExternalSeamDelegation(
                kind: ExternalSeamKind::Llm,
                dimension: ExternalSeamDimension::CodeReachPoint,
                gateFile: 'tests/Architecture/PromptGuardrailTest.php',
                gateTestName: '5 走査根で Prism Facade の LLM 系メソッドを直接呼んでいない (Prompt 経由のみ)',
                livenessProbe: static fn (): array => PrismDirectDispatchScanner::scannedFiles(),
                rationale: 'Prism 直呼び禁止は ALLOWED_FILES 空の完全禁止で PromptGuardrailTest が正本。走査根は app/ routes/ database/ config/ bootstrap/ の 5 本で目録より強い形で閉じている',
            ),
            new ExternalSeamDelegation(
                kind: ExternalSeamKind::SocialLogin,
                dimension: ExternalSeamDimension::DestinationSet,
                gateFile: 'tests/Architecture/SocialProviderTrustPolicyTest.php',
                gateTestName: '全 SSO provider が capability / email_trust を明示宣言している',
                livenessProbe: static fn (): array => config()->array('template.social_providers'),
                rationale: 'SSO の宛先集合は config の social_providers が正本で、provider 追加時の宣言必須を既存 gate が強制する',
            ),
        ];
    }
}
