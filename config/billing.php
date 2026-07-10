<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Billing (チケット供給) 設定
|--------------------------------------------------------------------------
|
| チケット台帳 (TicketLedgerService) の付与・傾斜単価パラメータ。
| Stripe Price のカタログ自体は stripe/fixtures + plan_prices /
| ticket_volume_prices (DB snapshot) が持ち、ここには持たない。
|
*/

return [

    /*
    | 初回 signup grant で付与する無償チケット枚数 (「まず触れる」導線)。
    | invoice.paid (billing_reason=subscription_create) 時に
    | TicketLedgerService::grantSignupGrant が冪等付与する。
    */
    'signup_grant_tickets' => (int) env('BILLING_SIGNUP_GRANT_TICKETS', 10),

    /*
    | 初回 signup grant の有効期限 (日)。期限到達で balance から外れる。
    */
    'signup_grant_expiry_days' => (int) env('BILLING_SIGNUP_GRANT_EXPIRY_DAYS', 30),

    /*
    | スポット購入の数量逐減 (volume tier) 単価の下限 (最小通貨単位)。
    | tier 解決 (TicketVolumePrice::currentTierFor) と seeder が、これを下回る
    | 単価を設定異常として fail-closed で弾く (誤設定による原価割れ販売を防ぐ)。
    */
    'ticket_unit_price_floor' => (int) env('BILLING_TICKET_UNIT_PRICE_FLOOR', 50),

];
