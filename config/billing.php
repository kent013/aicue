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

    /*
    | チケット残高低下のアプリ内通知閾値。reserve (実効残高が実際に減る唯一の消費起点) で
    | 「閾値以上 → 閾値未満」を跨いだときのみ owner/admin に 1 回通知する (クロス検知。
    | TicketLedgerService::reserve)。
    */
    'ticket_low_balance_threshold' => (int) env('BILLING_TICKET_LOW_BALANCE_THRESHOLD', 5),

    /*
    | 購入画面の resume / completed 表示窓 (分。P8b / tc-5)。
    | この窓内に「自分が開始した」live pending / 完了 session があれば、購入画面は
    | 新しい attempt_token を発行せず既存 session へ復帰導線を出す (ブラウザバック /
    | bfcache 復帰で既存 replay 冪等が効く = 二重課金しない)。既定 30 は移植元と同値。
    */
    'purchase_resume_window_minutes' => (int) env('BILLING_PURCHASE_RESUME_WINDOW_MINUTES', 30),

    /*
    | webhook 処理の滞留判定 (分)。`stripe_webhook_events.status='received'` のまま
    | この時間を超えた行を「本処理中にプロセスが落ちた残留」とみなして回収する
    | (billing:recover-stale-webhook-events)。
    |
    | env で振らない (環境ごとに変えてよい運用値ではない)。webhook の HTTP 処理は
    | 秒オーダーで終わるため、生存中のワーカーを追い越さない十分な余裕を取ってある。
    | 短くすると処理中の行を追い越し、長くすると付与の回復が遅れる。
    */
    'webhook_stale_after_minutes' => 15,

    /*
    |----------------------------------------------------------------------
    | オートリチャージ (裏チャージ。P8a)
    |----------------------------------------------------------------------
    |
    | **opt-in・既定 off**。ticket_auto_recharges に行が無い組織の挙動は完全に不変。
    | 値は移植元 (aigenba) の既定値をそのまま採る。
    |
    | 同意文言バージョン (consent_version) の改定履歴:
    |   v1 = 初版 (カード登録経路のみ = mode=setup Checkout で登録したカードを使う)
    |   v2 = P9 / T1004: 有償契約でサブスク決済カードをオートリチャージへ流用することを明示
    |
    | 提示条件の実質 (開始残高・補充枚数・上限額の提示形式・停止方法・即時課金可能性・
    | **カードの取得手段**) を変える改定では**必ず version を上げること**。
    | 版を上げると reconsentRequiredFor 経由で既存同意が自動失効し、再同意まで
    | 自動購入が停止する (fail-closed)。**救済 backfill は書かない** (版の意味が無効化されるため)。
    */
    'auto_recharge' => [
        /* 残高がこの枚数を下回ると補充する (既定値。org ごとに設定で上書き) */
        'default_threshold' => (int) env('BILLING_AUTO_RECHARGE_DEFAULT_THRESHOLD', 5),

        /* 補充後の目標残高 (既定値) */
        'default_max' => (int) env('BILLING_AUTO_RECHARGE_DEFAULT_MAX', 50),

        /*
        | max_count の上限。TicketVolumePrice::PURCHASE_MAX_COUNT と単一真実源で揃える
        | (超過設定は tier 解決の Assert で例外死するため入口で拘束する)。
        */
        'max_count' => (int) env('BILLING_AUTO_RECHARGE_MAX_COUNT', 1000),

        /* 連続課金失敗でオートリチャージを自動停止する回数 */
        'max_failures' => (int) env('BILLING_AUTO_RECHARGE_MAX_FAILURES', 3),

        /* pending attempt の期限 (時間)。超過でリコンサイルが終端する */
        'pending_expiry_hours' => (int) env('BILLING_AUTO_RECHARGE_PENDING_EXPIRY_HOURS', 24),

        /* setup Checkout 完了から PM snapshot 反映を待つ「処理中」表示の窓 (分) */
        'setup_pending_window_minutes' => (int) env('BILLING_AUTO_RECHARGE_SETUP_PENDING_WINDOW_MINUTES', 30),

        /* 現行の同意文言バージョン (上記の改定規約に従う) */
        'consent_version' => env('BILLING_AUTO_RECHARGE_CONSENT_VERSION', 'v2'),
    ],

];
