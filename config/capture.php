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

    // サムネイル生成 (テイク登録後に media queue の GenerateTakeThumbnailJob が 1 フレーム抽出する)
    // 抽出位置。0 だと黒画面になりやすいので既定 1 秒。尺が足りなければ実装が 0 で 1 回だけ再試行する
    'thumbnail_seek_ms' => 1000,
    // 出力の長辺上限 (両辺に効く。巨大入力から巨大 JPEG を作らない)
    'thumbnail_max_edge' => 640,
    // JPEG 品質 (ffmpeg -q:v。小さいほど高品質・大きいほど低容量)
    'thumbnail_jpeg_quality' => 5,
    // ffmpeg 1 回の実行上限 (秒)。ジョブの $timeout=180 より十分短く取る
    'thumbnail_ffmpeg_timeout_seconds' => 60,

];
