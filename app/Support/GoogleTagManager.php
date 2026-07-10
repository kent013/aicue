<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Google Tag Manager の有効判定を一元化する。
 *
 * GTM snippet (resources/views/partials/gtm-*.blade.php) の描画と、CSP
 * (App\Http\Middleware\SecurityHeaders) の GTM/GA4 用 host-source 緩和は、この
 * 同一ゲート (production かつ container_id 非空の二重ゲート) を共有する。これにより
 * 「GTM を実際に読み込むときだけ CSP を緩める」不変条件を保ち、既定テンプレ
 * (GTM off) では script-src に 'unsafe-inline' を持ち込まない厳格な XSS baseline を維持する。
 */
class GoogleTagManager
{
    /**
     * 設定された container ID を返す (未設定・空文字なら null)。
     */
    public static function containerId(): ?string
    {
        $id = config('services.google_tag_manager.container_id');

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * GTM を実際に描画・許可するか (production かつ container_id 非空の二重ゲート)。
     */
    public static function isEnabled(): bool
    {
        return Environment::isProduction() && self::containerId() !== null;
    }
}
