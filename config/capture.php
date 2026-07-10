<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| 撮影 PWA (presigned アップロード + テイク管理) の設定
|--------------------------------------------------------------------------
|
| doc/10 §10.3 / §10.8-4/-7。値の参照は config()->integer() / config()->array() 経由。
|
*/

return [

    // 予約 expires_at = 署名チケット TTL (§10.8-4 の「一定時間」)
    'upload_ticket_ttl_minutes' => 30,

    // 1 テイクの上限サイズ (バリデーション用)
    'max_take_bytes' => 500 * 1024 * 1024, // 500 MiB

    // presigned PUT を許可する動画 Content-Type
    'allowed_video_content_types' => ['video/mp4', 'video/webm', 'video/quicktime'],

    // 採用テイク署名 GET URL (と DL 済み ACK トークン) の TTL
    'playback_url_ttl_minutes' => 60,

    // released/completed 予約行の物理削除猶予 (stale 掃除 cron が削除する)
    'released_reservation_retention_days' => 30,

    // verifying 予約を stale とみなす閾値 (登録リクエストの異常終了の回収。概念設計 D7)
    'stale_verifying_minutes' => 15,

];
