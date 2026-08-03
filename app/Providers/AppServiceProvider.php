<?php

declare(strict_types=1);

namespace App\Providers;

use App\Auth\EncryptedUserProvider;
use App\Auth\Guards\ApiKeyGuard;
use App\Http\Routing\MembershipScopedOrganizationBinder;
use App\Http\Routing\RouteBindingTypes;
use App\Listeners\Audit\RejectNonCriticalAudit;
use App\Listeners\Auth\StampRecentAuthOnLogin;
use App\Listeners\Billing\MarkBillingNotificationDelivered;
use App\Listeners\Mail\FilterSuppressedRecipients;
use App\Listeners\RecordLlmCallCost;
use App\Listeners\RecordLlmCallFailure;
use App\Listeners\RecordSecurityEvent;
use App\Models\ApiKey;
use App\Models\Billing\Subscription;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\Channels\OrganizationScopedDatabaseChannel;
use App\Services\Billing\CashierAutoRechargeGateway;
use App\Services\Billing\CashierStripeGateway;
use App\Services\Billing\CashierTicketCheckoutGateway;
use App\Services\Billing\Contracts\AutoRechargeGatewayInterface;
use App\Services\Billing\Contracts\StripeGatewayInterface;
use App\Services\Billing\StripeWebhookProcessor;
use App\Services\Billing\TicketCheckoutGateway;
use App\Services\Mail\Sns\AwsSnsSignatureVerifier;
use App\Services\Mail\Sns\SnsSignatureVerifier;
use App\Services\Render\FfmpegVideoComposer;
use App\Services\Render\VideoComposer;
use App\Support\CriticalActionContext;
use App\Support\EmailNormalizer;
use App\Support\PasswordPolicy;
use App\Support\ProductionEnvGuard;
use Aws\Sns\SnsClient;
use Illuminate\Auth\Events\Login;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Application as FoundationApplication;
use Illuminate\Foundation\Support\Providers\EventServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Notifications\Channels\DatabaseChannel;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Kent013\PrismPrompt\Events\PromptExecutionCompleted;
use Kent013\PrismPrompt\Events\PromptExecutionFailed;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Events\WebhookReceived;
use OwenIt\Auditing\Events\Auditing;
use Stripe\Service\PriceService;
use Webmozart\Assert\Assert;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // イベントリスナは本 Provider の boot() で明示登録する (SSOT)。Laravel 13 の framework
        // 既定はイベント自動 discovery (app/Listeners 走査) が ON のため、明示登録と重複して
        // 各リスナが二重発火する (RecordSecurityEvent の二重記録・FilterSuppressedRecipients の
        // 二重適用等)。discovery を切って登録経路を明示登録一本に統一する。
        EventServiceProvider::disableEventDiscovery();

        // Stripe Price Catalog への read-only 境界 (StripePriceCatalogClient) の依存。
        // StripeClient 全体ではなく PriceService のみを束縛し、テストでのモックを容易にする
        $this->app->bind(
            PriceService::class,
            static fn (): PriceService => Cashier::stripe()->prices,
        );

        // SES/SNS バウンス・苦情処理。
        // - SnsClient: SubscriptionConfirmation の confirmSubscription に使う (services.ses の認証情報を流用)。
        // - SnsSignatureVerifier: SNS 署名検証 (暗号検証は AWS SDK MessageValidator に委譲)。
        $this->app->singleton(SnsClient::class, function (Application $app): SnsClient {
            /** @var array<string, mixed> $ses */
            $ses = (array) config('services.ses', []);
            $config = [
                'version' => 'latest',
                'region' => is_string($ses['region'] ?? null) ? $ses['region'] : 'us-east-1',
            ];
            $key = $ses['key'] ?? null;
            $secret = $ses['secret'] ?? null;
            if (is_string($key) && $key !== '' && is_string($secret) && $secret !== '') {
                $config['credentials'] = ['key' => $key, 'secret' => $secret];
            }

            return new SnsClient($config);
        });
        $this->app->bind(SnsSignatureVerifier::class, AwsSnsSignatureVerifier::class);

        // Critical Action 実行中フラグ。scoped() で HTTP request scope に閉じる
        // (queue worker / artisan は別 container のため context は継承されない)
        $this->app->scoped(CriticalActionContext::class);

        // 動画合成の抽象 (doc/09 §9.7)。v1 は ffmpeg 実装。テストは fake 実装へ swap する
        $this->app->bind(VideoComposer::class, FfmpegVideoComposer::class);

        // チケットスポット購入の Stripe Checkout 抽象 (T007)。テストは fake を bind する
        $this->app->bind(TicketCheckoutGateway::class, CashierTicketCheckoutGateway::class);

        // サブスク Checkout / Customer Portal の Stripe 抽象。fake_externals 時は
        // FakeExternalsServiceProvider が fake に rebind する (providers.php で後勝ち)
        $this->app->bind(StripeGatewayInterface::class, CashierStripeGateway::class);

        // オートリチャージ (P8a) の Stripe 抽象 (setup Checkout / off-session invoice)。
        // fake_externals 時は FakeExternalsServiceProvider が fake に rebind する
        $this->app->bind(AutoRechargeGatewayInterface::class, CashierAutoRechargeGateway::class);

        // アプリ内通知 (T008): database channel を薄い拡張へ差し替え、AppNotification の
        // organization_id を notifications テーブルの first-class 列として書き込む
        // (ChannelManager::createDatabaseDriver は container 解決のため binding が効く。
        // AppNotification 以外の通知は素通し = 後方互換)
        $this->app->bind(DatabaseChannel::class, OrganizationScopedDatabaseChannel::class);
    }

    public function boot(): void
    {
        // production 起動時の env baseline fail-fast (設定ミスを deploy 時点で表面化させる)。
        // 検査項目の SSOT は ProductionEnvGuard (production:preflight コマンドも同 guard に委譲)
        if ($this->app->environment('production')) {
            (new ProductionEnvGuard)->enforce();
        }

        // email が CipherSweet 暗号化カラムのため、credentials 検索を blind index 経由にする
        // (config/auth.php providers.users.driver = 'encrypted-eloquent')
        Auth::provider('encrypted-eloquent', function (Application $app, array $config) {
            $model = $config['model'];
            Assert::string($model);

            return new EncryptedUserProvider($app->make(Hasher::class), $model);
        });

        // REST API v1 / MCP の Bearer 認証 guard (config/auth.php guards.api-key)。
        // AuthManager は guard インスタンスをキャッシュするため、request の rebind に
        // 追随できるよう refresh を配線する (テスト等の連続リクエストで必須)
        Auth::extend('api-key', function (Application $app): ApiKeyGuard {
            $request = $app->make(Request::class);
            Assert::isInstanceOf($request, Request::class);

            $guard = new ApiKeyGuard($request);

            Assert::isInstanceOf($app, FoundationApplication::class);
            $app->refresh('request', $guard, 'setRequest');

            return $guard;
        });

        // web ルートの {organization} binding は membership スコープで解決する
        // (非メンバー・不在 slug/id は等しく 404 = テナント存在秘匿。
        // tests/Architecture/OrganizationRouteParamWebOnlyInvariantTest が適用境界を pin)
        Route::bind('organization', MembershipScopedOrganizationBinder::class);

        // route binding 型制約 (RouteBindingTypes が単一 SoT)。
        // 非適合セグメントは route にマッチしない = 404 になり、SubstituteBindings へ
        // 到達しないため pgsql 22P02 / 22003 (→ 生 500) が構造的に起きない。
        // CUSTOM_BINDER (= {organization}) は binder 側が正規化するため pattern を適用しない
        // ({organization:slug} を併用しており数値制約は掛けられない)。
        // 分類の網羅と適用は tests/Architecture/RouteBindingTypeConstraintInventoryTest が
        // deny-by-default で固定する。
        foreach (array_keys(RouteBindingTypes::BIGINT) as $param) {
            Route::pattern($param, RouteBindingTypes::BIGINT_PATTERN);
        }
        foreach (array_keys(RouteBindingTypes::UUID) as $param) {
            Route::pattern($param, RouteBindingTypes::UUID_PATTERN);
        }

        // パスワード強度ポリシーの SSOT は App\Support\PasswordPolicy (min12 + mixedCase +
        // numbers。HIBP 漏洩照合 uncompromised はテスト実行時のみ除外)。
        // 各 Action / FormRequest は Password::default() を参照し、min:8 等の散在を排除する
        Password::defaults(static fn (): Password => PasswordPolicy::rule());

        // $fillable 外属性の silent drop を非本番で例外化し、誤った mass-assign 経路
        // (所有権/actor キーを payload→fill) を開発/CI 時点で顕在化させる。本番は
        // silent drop 維持 (可用性優先)。出口側の dev 補助であり、入口契約
        // (ProhibitsProtectedKeys trait) の代替ではない (多層防御の一枚)
        Model::preventSilentlyDiscardingAttributes((bool) config('app.mass_assignment_strict'));

        // 認証系イベント → security_audit_events 記録 (監査 3 層の Layer 2)
        Event::subscribe(RecordSecurityEvent::class);

        // ログイン成功 → recent-auth スタンプ (機微操作 step-up の起点)
        Event::listen(Login::class, StampRecentAuthOnLogin::class);

        // 通知送信完了 → 課金通知の配信済みマーク (renewal reminder 等の再送抑止)
        Event::listen(NotificationSent::class, MarkBillingNotificationDelivered::class);

        // LLM 呼び出し → llm_call_logs 記録 (成功系 = コスト、失敗系 = failure_reason)
        Event::listen(PromptExecutionCompleted::class, RecordLlmCallCost::class);
        Event::listen(PromptExecutionFailed::class, RecordLlmCallFailure::class);

        // 課金主体は Organization (users ではなく organizations が Stripe customer)
        Cashier::useCustomerModel(Organization::class);

        // Subscription はテンプレート拡張モデル (current_period_end / schedule 列。
        // renewal reminder と billing:reconcile-schedules が参照する)
        Cashier::useSubscriptionModel(Subscription::class);

        // Stripe webhook → 冪等マシン (plan_code 同期・月次チケット付与)
        Event::listen(WebhookReceived::class, StripeWebhookProcessor::class);

        // モデル属性 diff 監査 (監査 3 層の Layer 3): CriticalActionContext が active な
        // Critical 操作中のみ model_audits に記録する (それ以外は warning ログ + 破棄)
        Event::listen(Auditing::class, RejectNonCriticalAudit::class);

        // 送信前チェック: 抑止リスト該当アドレスを宛先から除去する。
        // MessageSending は実送信直前 (キュー worker 内含む) に発火し、全 Mailable / Fortify
        // 経路を横断して一律適用される (返り値契約は FilterSuppressedRecipients docblock 参照)。
        Event::listen(MessageSending::class, FilterSuppressedRecipients::class);

        $this->configureApiRateLimiters();
        $this->configureInquiryRateLimiter();
        $this->configureRenderRateLimiter();
    }

    /**
     * レンダ/プレビュートリガー (POST .../render, .../preview) の RateLimiter。
     * preview はチケット非消費のため、この rate limit + org 同時 preview 上限
     * (RenderJobService::triggerPreview) の 2 段が無料 ffmpeg 実行の負荷上限を構造的に決める
     * (概念設計 §2 の abuse 耐性契約)。キーは user id + org id 単位。
     */
    private function configureRenderRateLimiter(): void
    {
        RateLimiter::for('render-trigger', function (Request $request): Limit {
            $user = $request->user();
            $userId = $user instanceof User ? (string) $user->id : 'guest';
            $orgId = $user instanceof User && $user->current_organization_id !== null
                ? (string) $user->current_organization_id
                : 'none';

            return Limit::perMinute(6)->by("render-trigger:{$userId}:{$orgId}");
        });
    }

    /**
     * 公開問い合わせフォーム (POST /contact) の RateLimiter。IP 単独 + IP+email の 2 系統。
     * email 正規化は保存・検索と同一の EmailNormalizer に集約 (大文字小文字での limiter 回避防止)。
     * email はキャッシュキーへの平文残存を避けるため sha256 でハッシュ化する。
     * limiter は validation 前に走るため email が非 string で来うる → is_string ガード必須。
     */
    private function configureInquiryRateLimiter(): void
    {
        RateLimiter::for('inquiry', function (Request $request): array {
            $rawEmail = $request->input('email', '');
            $email = is_string($rawEmail) && $rawEmail !== '' ? EmailNormalizer::normalize($rawEmail) : '';
            $emailKey = $email !== '' ? hash('sha256', $email) : 'anon';
            $ip = (string) $request->ip();

            return [
                Limit::perMinute(5)->by('inquiry:ip:'.$ip),
                Limit::perMinutes(60, 10)->by('inquiry:ip-email:'.$ip.':'.$emailKey),
            ];
        });
    }

    /**
     * REST API v1 / MCP / OAuth DCR の rate limit バケット (定義はここに集約、適用は route 側)。
     * キーは認証済みなら API キー id (MCP は user id)、未認証 (api-status の /version 等) は IP。
     * 新バケットの追加は要件に明示的根拠があるときだけ (docs/app-integration-guide.md §5)。
     */
    private function configureApiRateLimiters(): void
    {
        RateLimiter::for('api-read', fn (Request $request): Limit => Limit::perMinute(120)->by($this->apiRateKey($request)));
        RateLimiter::for('api-write', fn (Request $request): Limit => Limit::perMinute(60)->by($this->apiRateKey($request)));
        RateLimiter::for('api-status', fn (Request $request): Limit => Limit::perMinute(30)->by($this->apiRateKey($request)));
        RateLimiter::for('api-mcp', fn (Request $request): Limit => Limit::perMinute(60)->by($this->mcpRateKey($request)));

        // DCR (POST /oauth/register) 用 (WP23)。未認証で client 登録できる endpoint のため
        // IP 単位で絞る。正常 client は 1 回 / session なので 10 req/min で連打を十分弾ける。
        RateLimiter::for('oauth-register', fn (Request $request): Limit => Limit::perMinute(10)->by('oauth-register:ip:'.($request->ip() ?? 'unknown')));
    }

    private function apiRateKey(Request $request): string
    {
        $apiKey = $request->attributes->get('api_key');
        if ($apiKey instanceof ApiKey) {
            return 'api-key:'.$apiKey->id;
        }

        // dual guard の OAuth user-token 経路 (throttle は resolve.api-actor より前段の
        // ため guard から直接引く)。actor 単位で数える (IP 共有環境での巻き添え防止)
        $oauthUser = $request->user('api-oauth');
        if ($oauthUser instanceof User) {
            return 'oauth-user:'.$oauthUser->id;
        }

        return 'ip:'.($request->ip() ?? 'unknown');
    }

    /**
     * MCP (auth:mcp-oauth) は user 単位で bucket を分ける (1 token = 1 user 1 org)。
     * 未認証 (auth 前に throttle が走る) は IP fallback。
     */
    private function mcpRateKey(Request $request): string
    {
        $user = $request->user('mcp-oauth');
        if ($user instanceof User) {
            return 'mcp:user:'.$user->id;
        }

        return 'ip:mcp:'.($request->ip() ?? 'unknown');
    }
}
