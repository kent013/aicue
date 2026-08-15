<?php

declare(strict_types=1);

use App\Support\ExternalClientTimeouts;

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
        // SESv2 SendEmail にマージされる送信オプション。Configuration Set を結線して
        // バウンス/苦情イベントを SNS topic へ発行させる (未設定なら付与しない)。
        'options' => array_filter([
            'ConfigurationSetName' => env('SES_CONFIGURATION_SET'),
        ]),
        // SES/SNS 通知受信の TopicArn allowlist (カンマ区切り)。空のときは全通知を拒否。
        'sns_topic_arns' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('SES_SNS_TOPIC_ARNS', ''))
        ))),
        // ★**vendor 契約に依存する**: Illuminate\Mail\MailManager::createSesV2Transport() は
        //   array_merge(config('services.ses'), ['version' => 'latest'], $config) を
        //   Arr::except(…, ['transport']) して **そのまま new SesV2Client(...)** へ渡す。
        //   したがって AWS client option は**この配列の直下**に置く必要がある
        //   (`client_options` のようにネストすると AWS の ClientResolver から見て未知キーになり
        //    黙って無視される = pin が効かない)。アプリ設定 (options / sns_topic_arns) と
        //   同居するのは Laravel 側の契約であり、この前提は
        //   ExternalClientTimeoutInventoryTest の
        //   「vendor 契約: MailManager は services.ses を SesV2Client の構築引数へ素通しする」が
        //   behavioral に固定する (Laravel が strict 化した瞬間に赤くなる)。
        ...ExternalClientTimeouts::awsControlClientOptions(),
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI', '/auth/google/callback'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // reCAPTCHA v2 invisible。site_key 未設定時は captcha 無しで動く (縮退)。
    // secret_key は production では未設定 = fail-closed (App\Services\Captcha\RecaptchaVerifier)
    'recaptcha' => [
        'site_key' => env('RECAPTCHA_SITE_KEY'),
        'secret_key' => env('RECAPTCHA_SECRET_KEY'),
    ],

    // 問い合わせ CTA の宛先 (App\Services\Marketing\ContactUrl が解決)。
    // 未設定は内部フォーム /contact。外部フォームサービス URL や mailto: も設定可
    // (http/https/mailto と内部 path のみ許可、それ以外は内部フォームへ fail-close)。
    'marketing' => [
        'contact_url' => env('MARKETING_CONTACT_URL'),
    ],

    // Google Tag Manager コンテナ ID。GTM partial (resources/views/partials/gtm-*.blade.php)
    // は「production かつ container_id 非空」の二重ゲートでのみ描画する。未設定 = GTM 無効。
    'google_tag_manager' => [
        'container_id' => env('GTM_CONTAINER_ID'),
    ],

];
