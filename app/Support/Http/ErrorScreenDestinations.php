<?php

declare(strict_types=1);

namespace App\Support\Http;

use App\Enums\Http\InertiaErrorScreenStatus;

/**
 * Error 画面の戻り先を決める唯一の点。裁定 (error-response-contract) の
 * 「戻り先はサーバ側に固定した許可一覧から出しリクエスト入力を混ぜない」を満たす。
 *
 * **入力は status と認証状態の 2 つだけ**。適用順序 (上が優先):
 *   D1: status が 419  → ログイン + トップ (**認証状態を問わない**)
 *   D2: 認証済み        → ダッシュボード + トップ
 *   D3: 未認証          → ログイン + トップ
 *
 * D1 が D2 より先である理由: 419 は CSRF token 不一致でも起きるため「認証済みのまま 419」が
 * ありうる。その状態でダッシュボードへ戻しても同じ token 不一致を踏み直すだけで詰みが
 * 再生産される。セッションと token を取り直せる導線が唯一の確実な脱出路である。
 *
 * href は **相対 path** で返す (route(..., absolute: false))。host を含めないことで、
 * proxy 構成に依らず同一オリジンに閉じ、応答が host によって変わらない。
 */
final class ErrorScreenDestinations
{
    /**
     * @return non-empty-list<ErrorScreenDestination>
     */
    public static function for(InertiaErrorScreenStatus $status, bool $authenticated): array
    {
        if ($status->forcesGuestDestinations()) {
            return self::guest();
        }

        if ($authenticated) {
            return [
                new ErrorScreenDestination('ダッシュボードへ', route('app.entry', absolute: false)),
                self::home(),
            ];
        }

        return self::guest();
    }

    /** @return non-empty-list<ErrorScreenDestination> */
    private static function guest(): array
    {
        return [
            new ErrorScreenDestination('ログインへ', route('login', absolute: false)),
            self::home(),
        ];
    }

    private static function home(): ErrorScreenDestination
    {
        return new ErrorScreenDestination('トップへ', '/');
    }
}
