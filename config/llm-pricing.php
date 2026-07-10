<?php

declare(strict_types=1);

/**
 * LLM コスト記録の FX (為替) 設定。
 *
 * 価格テーブル本体は config/prism-prompt-pricing.php
 * (kent013/laravel-prism-prompt から publish) が持つ。ここには FX 関連キーのみ置く。
 */
return [
    'frankfurter_url' => env('FRANKFURTER_URL', 'https://api.frankfurter.dev/v1/latest'),
    'frankfurter_timeout' => (int) env('FRANKFURTER_TIMEOUT', 2),
    'frankfurter_connect_timeout' => (int) env('FRANKFURTER_CONNECT_TIMEOUT', 1),
];
