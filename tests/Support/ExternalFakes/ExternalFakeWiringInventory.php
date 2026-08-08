<?php

declare(strict_types=1);

namespace Tests\Support\ExternalFakes;

use App\Http\Controllers\Testing\GetFakeStorageObjectController;
use App\Http\Controllers\Testing\PutFakeStorageObjectController;
use App\Services\AI\Testing\CannedPromptFakeRegistrar;
use App\Services\Billing\CashierAutoRechargeGateway;
use App\Services\Billing\CashierStripeGateway;
use App\Services\Billing\CashierTicketCheckoutGateway;
use App\Services\Billing\Contracts\AutoRechargeGatewayInterface;
use App\Services\Billing\Contracts\StripeGatewayInterface;
use App\Services\Billing\Fakes\FakeAutoRechargeGateway;
use App\Services\Billing\Fakes\FakeStripeGateway;
use App\Services\Billing\Fakes\FakeTicketCheckoutGateway;
use App\Services\Billing\TicketCheckoutGateway;
use App\Services\Captcha\RecaptchaVerifier;
use App\Services\Captcha\Testing\RecaptchaVerifierTestFake;
use App\Services\Capture\Fakes\FakeTakeObjectStorage;
use App\Services\Capture\TakeObjectStorage;
use App\Services\Render\Fakes\FakeRenderObjectStorage;
use App\Services\Render\RenderObjectStorage;
use App\Support\FakeStorageGate;

/**
 * 外部 fake の container 差し替え inventory (deny-by-default の正本)。
 *
 * 責務境界:
 * - 本 inventory と ExternalFakeWiringInvariantTest が見るのは **非本番側の配線**だけ。
 * - **本番混入防止の正本は `App\Support\ProductionEnvGuard`** (配備前 = production:preflight /
 *   起動時 = AppServiceProvider::boot の 2 経路) + `tests/Feature/Support/ProductionEnvGuardTest`。
 *   ここで二重実装しない。
 * - LLM (Prism) fake は container ではなく `Prompt::$fake` (プロセスグローバル static) を書き換える
 *   ため inventory の対象外 (ExternalFakeWiringInvariantTest の 3-11 が別枠で見る)。
 */
final class ExternalFakeWiringInventory
{
    /** 外部サービス fake (Stripe 課金 + captcha) の capability flag */
    public const string EXTERNALS_FLAG = 'testing.fake_externals';

    /** storage fake の capability flag */
    public const string STORAGE_FLAG = 'testing.fake_storage';

    /** LLM fake の capability flag (container 差し替えではないため bindings() には現れない) */
    public const string LLM_FLAG = 'testing.fake_llm';

    /** 外部サービス fake の env allowlist (FakeExternalsServiceProvider::EXTERNAL_FAKE_ENVIRONMENTS と対) */
    private const array EXTERNAL_ENVIRONMENTS = ['local', 'testing', 'bughunt.local'];

    /** storage fake の env allowlist (FakeStorageGate の predicate と対。testing は runningUnitTests 前提) */
    private const array STORAGE_ENVIRONMENTS = ['testing', 'bughunt.local'];

    /**
     * fake の実体ではないが FakeExternalsServiceProvider が参照してよい配線基盤クラス。
     *
     * 「provider が参照する fake 系クラス = bindings() の fake ∪ 本集合」を集合一致で検査するため、
     * ここに載っていないクラスを provider が参照した時点で gate が赤くなる。
     *
     * @return list<class-string>
     */
    public static function providerReferenceExceptions(): array
    {
        return [
            // LLM static fake の install 窓口 (container 配線を行わない)
            CannedPromptFakeRegistrar::class,
            // storage fake の有効化 predicate (SSOT。container 配線を行わない)
            FakeStorageGate::class,
            // fake storage signed route の受け口 (route action。container 配線を行わない)
            PutFakeStorageObjectController::class,
            GetFakeStorageObjectController::class,
        ];
    }

    /**
     * container 差し替えの全宣言。
     *
     * ここに entry を足すと、ExternalFakeWiringInvariantTest の data-driven 検査
     * (対照 / 実証 / allowlist 外) が自動的に増える = 書き忘れが構造的に起きない。
     *
     * ⚠️ 新 entry を足す実装者へ: Architecture lane は RefreshDatabase を使わない。
     * abstract / real / fake の constructor が DB に触れないことを必ず確認すること
     * (現行 5 本は確認済み)。
     *
     * @return list<ExternalFakeBinding>
     */
    public static function bindings(): array
    {
        return [
            new ExternalFakeBinding(
                abstract: TicketCheckoutGateway::class,
                real: CashierTicketCheckoutGateway::class,
                fake: FakeTicketCheckoutGateway::class,
                flag: self::EXTERNALS_FLAG,
                allowedEnvironments: self::EXTERNAL_ENVIRONMENTS,
                risk: 'チケットスポット購入の Stripe Checkout。配線が外れると実 Stripe に実課金セッションを作る。',
            ),
            new ExternalFakeBinding(
                abstract: StripeGatewayInterface::class,
                real: CashierStripeGateway::class,
                fake: FakeStripeGateway::class,
                flag: self::EXTERNALS_FLAG,
                allowedEnvironments: self::EXTERNAL_ENVIRONMENTS,
                risk: 'サブスク Checkout / Customer Portal。配線が外れると実 Stripe に契約を作る。',
            ),
            new ExternalFakeBinding(
                abstract: AutoRechargeGatewayInterface::class,
                real: CashierAutoRechargeGateway::class,
                fake: FakeAutoRechargeGateway::class,
                flag: self::EXTERNALS_FLAG,
                allowedEnvironments: self::EXTERNAL_ENVIRONMENTS,
                risk: 'オートリチャージの off-session invoice。配線が外れると実カードへ請求が飛ぶ。',
            ),
            new ExternalFakeBinding(
                abstract: TakeObjectStorage::class,
                real: TakeObjectStorage::class,
                fake: FakeTakeObjectStorage::class,
                flag: self::STORAGE_FLAG,
                allowedEnvironments: self::STORAGE_ENVIRONMENTS,
                risk: '撮影テイクの S3 presign / HeadObject。abstract が具象クラスのため、'
                    .'bind を消しても Laravel が本物を自動組み立てして無音で実 S3 を叩く。',
            ),
            new ExternalFakeBinding(
                abstract: RenderObjectStorage::class,
                real: RenderObjectStorage::class,
                fake: FakeRenderObjectStorage::class,
                flag: self::STORAGE_FLAG,
                allowedEnvironments: self::STORAGE_ENVIRONMENTS,
                risk: 'レンダ出力の S3 read/write。TakeObjectStorage と同じく具象クラス起点で無音になる。',
            ),
            new ExternalFakeBinding(
                abstract: RecaptchaVerifier::class,
                real: RecaptchaVerifier::class,
                fake: RecaptchaVerifierTestFake::class,
                flag: self::EXTERNALS_FLAG,
                allowedEnvironments: self::EXTERNAL_ENVIRONMENTS,
                risk: 'Google reCAPTCHA siteverify への外向き POST。abstract が具象クラスのため、'
                    .'bind を消しても Laravel が本物を自動組み立てし、RECAPTCHA_SECRET_KEY が'
                    .'設定された環境では無言で実 Google を叩く (bug-hunt の別プロセスには '
                    .'StrayHttpRequestGuard が効かない)。',
            ),
        ];
    }
}
