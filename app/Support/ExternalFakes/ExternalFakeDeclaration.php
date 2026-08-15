<?php

declare(strict_types=1);

namespace App\Support\ExternalFakes;

use App\Services\Auth\Fakes\FakeSocialiteDriverResolver;
use App\Services\Auth\SocialiteDriverResolver;
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
use App\Services\Mail\Sns\SnsSignatureVerifier;
use App\Services\Render\Fakes\FakeRenderObjectStorage;
use App\Services\Render\RenderObjectStorage;
use InvalidArgumentException;
use Kent013\SsrfPin\UrlSafetyInspector;

/**
 * 「どの外部到達点を、どのフラグと許可環境で、どの偽の実装へ差し替えるか」の唯一の正本。
 *
 * ★本番の読み込み対象 (app/) に置く。差し替えの配線 (FakeExternalsServiceProvider)・
 *   storage の有効化条件 (FakeStorageGate)・bug-hunt の投入データ (seeder)・
 *   本番混入防止 (ProductionEnvGuard) が**すべてここだけを読む** (同じ集合を 2 か所に書かない)。
 * ★本クラスは値を返すだけで判定を持たない。有効・無効の判定は
 *   FakeExternalsServiceProvider (container 差し替え) と FakeStorageGate (storage) が行う。
 *
 * 関連する目録との責務境界:
 * - 本番コードが偽の実装のクラス名を参照しないことの全走査は FakeClassReferenceInvariantTest
 * - 外部到達点そのものの目録は ExternalSeamInventory
 *   (同じ事実を 3 か所で宣言しない。AGENTS.md ドメイン規約 9)
 */
final class ExternalFakeDeclaration
{
    /** 外部サービス fake (決済 + 人間性確認 + 外部ログイン) の capability flag */
    public const string EXTERNALS_FLAG = 'testing.fake_externals';

    /** storage fake の capability flag */
    public const string STORAGE_FLAG = 'testing.fake_storage';

    /** LLM fake の capability flag (container 差し替えではないため swaps() には現れない) */
    public const string LLM_FLAG = 'testing.fake_llm';

    /**
     * capability flag の config キー => 対応する環境変数名。
     *
     * 本番混入防止 (ProductionEnvGuard) と bug-hunt の環境ひな型検査が読む。
     */
    public const array FLAG_ENVIRONMENT_VARIABLES = [
        self::EXTERNALS_FLAG => 'TESTING_FAKE_EXTERNALS',
        self::STORAGE_FLAG => 'TESTING_FAKE_STORAGE',
        self::LLM_FLAG => 'TESTING_FAKE_LLM',
    ];

    /**
     * 外部サービス fake の許可環境 (capability 全体。個々の差し替えはこれ以下に絞れる)。
     */
    public const array EXTERNAL_ENVIRONMENTS = ['local', 'testing', 'bughunt.local'];

    /**
     * 外部ログインの差し替えだけ `local` を外す。
     *
     * 未認証 GET 2 本で canned アカウントに入れる = 認証バイパスであり、かつ `local` は
     * 実 IdP 連携を確かめる唯一の環境である (無言で偽物が立つと本番 SSO の回帰を見逃す)。
     */
    public const array SSO_ENVIRONMENTS = ['testing', 'bughunt.local'];

    /** storage fake の許可環境 (testing での追加条件は FakeStorageGate が持つ) */
    public const array STORAGE_ENVIRONMENTS = ['testing', 'bughunt.local'];

    /** LLM fake の許可環境 (Prompt::$fake はプロセス大域の static のため testing/local を外す) */
    public const array LLM_ENVIRONMENTS = ['bughunt.local'];

    /**
     * capability flag ごとの許可環境。
     *
     * ★既定を例外にする (未知のフラグを黙って空集合へ倒さない)。空集合へ倒すと
     *   「許可環境が 1 つも無い = どこでも差し替わらない」という**無音の失効**になる。
     *
     * @return list<string>
     */
    public static function capabilityEnvironments(string $flag): array
    {
        return match ($flag) {
            self::EXTERNALS_FLAG => self::EXTERNAL_ENVIRONMENTS,
            self::STORAGE_FLAG => self::STORAGE_ENVIRONMENTS,
            self::LLM_FLAG => self::LLM_ENVIRONMENTS,
            default => throw new InvalidArgumentException("宣言されていない capability flag: {$flag}"),
        };
    }

    /**
     * bug-hunt レーンで偽物を外せないフラグ (安全下限集合)。
     *
     * 決済・人間性確認・外部ログインは、bug-hunt の走行そのものが実課金と実 IdP 遷移を
     * 起こすため、走行オプションで実物へ戻す口を持たない。
     * この不変条件を実際に固定するのは BughuntEnvExampleContractTest であり、
     * 本メソッドはその入力元 (集合の正本) である。
     *
     * @return list<string> config キー
     */
    public static function bughuntRequiredFlags(): array
    {
        return [self::EXTERNALS_FLAG];
    }

    /**
     * bug-hunt の環境ひな型が持たねばならない環境変数と値。
     *
     * @return array<string, string> 環境変数名 => 期待値
     */
    public static function bughuntRequiredEnvFlags(): array
    {
        $required = [];

        // 添字アクセスではなく写像そのものを走査する (キー不在の分岐を作らない)。
        foreach (self::FLAG_ENVIRONMENT_VARIABLES as $flag => $variable) {
            if (in_array($flag, self::bughuntRequiredFlags(), true)) {
                $required[$variable] = 'true';
            }
        }

        return $required;
    }

    /**
     * 差し替えてはいけない到達点 (偽物に落とすと検査そのものが無効になるもの)。
     *
     * ★責務分担: 本メソッドが止めるのは「この宣言へ足すこと」だけである。
     *   本番コードが偽物のクラス名を参照しないことの全走査は
     *   FakeClassReferenceInvariantTest が担い、外部到達点の目録は
     *   ExternalSeamInventory が担う。
     *
     * @return array<class-string, string> クラス => なぜ差し替えないか
     */
    public static function neverSwapped(): array
    {
        return [
            SnsSignatureVerifier::class => '受信通知の署名検証。偽物にすると差出人の詐称を検出できなくなる。',
            UrlSafetyInspector::class => '外部 URL の安全検査 (SSRF 防御)。偽物にすると内部宛ての取得が通る。',
        ];
    }

    /**
     * container 差し替えの全宣言。
     *
     * ⚠️ entry を足す実装者へ: Architecture レーンは RefreshDatabase を使わない。
     * abstract / real / fake のコンストラクタが DB に触れないことを必ず確認すること。
     *
     * @return list<ExternalFakeBinding>
     */
    public static function swaps(): array
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
            new ExternalFakeBinding(
                abstract: SocialiteDriverResolver::class,
                real: SocialiteDriverResolver::class,
                fake: FakeSocialiteDriverResolver::class,
                flag: self::EXTERNALS_FLAG,
                allowedEnvironments: self::SSO_ENVIRONMENTS,
                risk: 'SSO (Socialite) の driver 解決点。abstract が具象クラスのため、bind を消しても '
                    .'Laravel が本物を自動組み立てし、**無言で**実 IdP (accounts.google.com 等) への '
                    .'リダイレクトに戻る。bug-hunt のブラウザは別プロセスなので StrayHttpRequestGuard は効かない。',
            ),
        ];
    }
}
