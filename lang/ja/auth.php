<?php

declare(strict_types=1);

/*
 * laravel-lang/lang (`php artisan lang:add ja`) で publish した認証系の日本語翻訳。
 * Laravel 標準の failed / password / throttle のみ。アプリ固有の文言はここに増やさず、
 * 各画面/Action 側で管理する。
 */

return [
    'failed' => '認証に失敗しました。',
    'password' => 'パスワードが正しくありません。',
    'throttle' => 'ログインの試行回数が多すぎます。:seconds 秒後にお試しください。',
];
