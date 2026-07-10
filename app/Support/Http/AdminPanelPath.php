<?php

declare(strict_types=1);

namespace App\Support\Http;

use Filament\Facades\Filament;
use Throwable;

/**
 * admin panel パス解決の単一 SoT。
 *
 * bootstrap/app.php の admin error 差別化 (顧客向けと運用者向けの error ページ分離) が
 * 参照する。Filament panel の path を権威とし、panel 取得不能 (テスト初期化順 / 未登録)
 * 時は config フォールバックへ退避する。いずれも trim し、空文字に潰れた場合は 'admin'
 * に戻す二重 fail-safe (fail-closed)。
 */
final class AdminPanelPath
{
    public static function resolve(): string
    {
        $trimmed = trim(self::rawPath(), '/');

        return $trimmed === '' ? 'admin' : $trimmed;
    }

    private static function rawPath(): string
    {
        try {
            // getPanel は契約上 Panel を返すが、未登録 / 初期化順では例外 or null 化し得る。
            // どちらも Throwable として捕捉し config フォールバックへ退避する。
            return Filament::getPanel('admin')->getPath();
        } catch (Throwable) {
            $configured = config('filament.admin_path', 'admin');

            return is_string($configured) ? $configured : 'admin';
        }
    }
}
