<?php

declare(strict_types=1);

namespace Tests\Support\ExternalSeam;

/** 外部到達点の検出規則 (何を見て母集団に入れたか)。 */
enum ExternalSeamRule: string
{
    /** `Cashier::stripe()` / `$x->stripe()` — Stripe API client の取得 */
    case PaymentClientCall = 'payment_client_call';

    /** `new Stripe\StripeClient` — Stripe API client の構築 */
    case PaymentClientConstruction = 'payment_client_construction';

    /** `Laravel\Socialite\Facades\Socialite` の参照 */
    case SocialiteFacadeReference = 'socialite_facade_reference';

    /** `Illuminate\Support\Facades\Http` の参照 */
    case HttpFacadeReference = 'http_facade_reference';

    /** `Illuminate\Support\Facades\Mail` / `Illuminate\Support\Facades\Notification` の参照 */
    case MailFacadeReference = 'mail_facade_reference';
}
