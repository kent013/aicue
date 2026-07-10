<?php

use App\Enums\Billing\HandledStripeWebhookEvent;
use Laravel\Cashier\Console\WebhookCommand;
use Laravel\Cashier\Invoices\DompdfInvoiceRenderer;

// use Laravel\Cashier\Invoices\LaravelPdfInvoiceRenderer;

return [

    /*
    |--------------------------------------------------------------------------
    | Stripe Keys
    |--------------------------------------------------------------------------
    |
    | The Stripe publishable key and secret key give you access to Stripe's
    | API. The "publishable" key is typically used when interacting with
    | Stripe.js while the "secret" key accesses private API endpoints.
    |
    */

    'key' => env('STRIPE_KEY'),

    'secret' => env('STRIPE_SECRET'),

    /*
    |--------------------------------------------------------------------------
    | Cashier Path
    |--------------------------------------------------------------------------
    |
    | This is the base URI path where Cashier's views, such as the payment
    | verification screen, will be available from. You're free to tweak
    | this path according to your preferences and application design.
    |
    */

    'path' => env('CASHIER_PATH', 'stripe'),

    /*
    |--------------------------------------------------------------------------
    | Stripe Webhooks
    |--------------------------------------------------------------------------
    |
    | Your Stripe webhook secret is used to prevent unauthorized requests to
    | your Stripe webhook handling controllers. The tolerance setting will
    | check the drift between the current time and the signed request's.
    |
    */

    'webhook' => [
        'secret' => env('STRIPE_WEBHOOK_SECRET'),
        'tolerance' => env('STRIPE_WEBHOOK_TOLERANCE', 300),
        // 購読集合は HandledStripeWebhookEvent (単一出典) と Cashier DEFAULT_EVENTS の合成で導出する。
        // processor が処理するイベントを enum に集約し、handled ⊆ subscribed を CI invariant で固定する
        // (checkout.session.completed / invoice.paid の購読漏れによる silent 取りこぼし事故の再発防止)。
        'events' => array_values(array_unique([...WebhookCommand::DEFAULT_EVENTS, ...HandledStripeWebhookEvent::values()])),
    ],

    /*
    |--------------------------------------------------------------------------
    | Currency
    |--------------------------------------------------------------------------
    |
    | This is the default currency that will be used when generating charges
    | from your application. Of course, you are welcome to use any of the
    | various world currencies that are currently supported via Stripe.
    |
    */

    // 既定通貨は jpy (国内向けテンプレート既定。env 未設定時のフォールバックも揃える)。
    'currency' => env('CASHIER_CURRENCY', 'jpy'),

    /*
    |--------------------------------------------------------------------------
    | Currency Locale
    |--------------------------------------------------------------------------
    |
    | This is the default locale in which your money values are formatted in
    | for display. To utilize other locales besides the default en locale
    | verify you have the "intl" PHP extension installed on the system.
    |
    */

    'currency_locale' => env('CASHIER_CURRENCY_LOCALE', 'ja'),

    /*
    |--------------------------------------------------------------------------
    | Payment Confirmation Notification
    |--------------------------------------------------------------------------
    |
    | If this setting is enabled, Cashier will automatically notify customers
    | whose payments require additional verification. You should listen to
    | Stripe's webhooks in order for this feature to function correctly.
    |
    */

    'payment_notification' => env('CASHIER_PAYMENT_NOTIFICATION'),

    /*
    |--------------------------------------------------------------------------
    | Invoice Settings
    |--------------------------------------------------------------------------
    |
    | The following options determine how Cashier invoices are converted from
    | HTML into PDFs. You're free to change the options based on the needs
    | of your application or your preferences regarding invoice styling.
    |
    */

    'invoices' => [
        // Supported: DompdfInvoiceRenderer::class, LaravelPdfInvoiceRenderer::class
        'renderer' => env('CASHIER_INVOICE_RENDERER', DompdfInvoiceRenderer::class),

        'options' => [
            // Supported: 'letter', 'legal', 'A4'
            'paper' => env('CASHIER_PAPER', 'letter'),

            'remote_enabled' => env('CASHIER_REMOTE_ENABLED', false),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Stripe Logger
    |--------------------------------------------------------------------------
    |
    | This setting defines which logging channel will be used by the Stripe
    | library to write log messages. You are free to specify any of your
    | logging channels listed inside the "logging" configuration file.
    |
    */

    'logger' => env('CASHIER_LOGGER'),

    /*
    |--------------------------------------------------------------------------
    | Customer Portal Configuration
    |--------------------------------------------------------------------------
    |
    | billing:ensure-portal-configuration で PortalConfigurationSpec (subscription_update
    | 無効化が核心) から生成した configuration の id。設定されていれば portal session に
    | 適用される。未設定なら Stripe Dashboard の既定 configuration に従う。
    |
    */

    'portal_configuration_id' => env('STRIPE_PORTAL_CONFIGURATION_ID'),

    /*
    |--------------------------------------------------------------------------
    | JCT 適格請求書 TaxRate
    |--------------------------------------------------------------------------
    |
    | Stripe で作成した inclusive 10% (税込) TaxRate の id。設定されていれば checkout に
    | default_tax_rates として適用し、invoice に税率・税額を明示して適格請求書要件
    | (インボイス制度) を満たす。未設定なら税分離なし (移行期 / 非本番)。
    | 価格は税込前提 (inclusive) で運用すること。
    |
    */

    'tax_rate_id' => env('STRIPE_TAX_RATE_ID'),

];
