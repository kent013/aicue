<?php

declare(strict_types=1);

namespace App\Enums\Http;

/**
 * Inertia XHR (X-Inertia 付き) の例外応答を Error 画面へ差し替える status の目録。
 *
 * **deny-by-default**: ここに無い status は差し替えず、既存の自己完結 Blade
 * (resources/views/errors/*.blade.php) をそのまま返す。追加は
 * tests/Architecture/InertiaErrorScreenContractTest.php の inventory へ
 * 30 文字以上の根拠を書くことと同時にしか行えない (exact-fit cap がある)。
 *
 * ★**401 を目録に入れない**: web 面の認証失敗は AuthenticationException として現れ、
 *   bootstrap/app.php の render callback が Inertia::clearHistory() を積んだうえで
 *   null を返して既定処理へ委譲する (= /login への 302)。これは AGENTS.md ドメイン規約 3
 *   (履歴復元 3 枚セット) の契約であり、Inertia 面に 401 が到達する経路が無い。
 *   401 は api 面の担当である。ここへ 401 を足すと既存の 3 枚セット契約と競合する。
 */
enum InertiaErrorScreenStatus: int
{
    case Forbidden = 403;
    case NotFound = 404;
    case PageExpired = 419;
    case TooManyRequests = 429;
    case ServerError = 500;
    case ServiceUnavailable = 503;

    /** 画面見出し (中立文言。存在や権限の詳細を漏らさない)。 */
    public function title(): string
    {
        return match ($this) {
            self::Forbidden => 'この操作を行う権限がありません',
            self::NotFound => 'ページが見つかりません',
            self::PageExpired => 'セッションの有効期限が切れました',
            self::TooManyRequests => 'しばらく時間をおいてください',
            self::ServerError => '問題が発生しました',
            self::ServiceUnavailable => 'ただいまメンテナンス中です',
        };
    }

    /** 本文 (次に何をすればよいかだけを書く)。 */
    public function message(): string
    {
        return match ($this) {
            self::Forbidden => 'アクセス権限が必要な画面です。別の画面からお試しください。',
            self::NotFound => 'お探しのページは存在しないか、移動された可能性があります。',
            self::PageExpired => 'お手数ですが、ログインし直してから操作をやり直してください。',
            self::TooManyRequests => 'リクエストが続けて行われました。少し時間をおいてからお試しください。',
            self::ServerError => '一時的な問題が発生しました。時間をおいてもう一度お試しください。',
            self::ServiceUnavailable => 'ただいま作業中です。時間をおいてもう一度お試しください。',
        };
    }

    /** 待ち時間 (Retry-After) を画面に出す status か。 */
    public function showsRetryAfter(): bool
    {
        return $this === self::TooManyRequests || $this === self::ServiceUnavailable;
    }

    /**
     * 認証状態にかかわらず「ログイン + トップ」を戻り先にする status か (戻り先規則 D1)。
     *
     * ★419 は CSRF token 不一致でも起きるため「認証済みのまま 419」がありうる。
     *   その状態でダッシュボードへ戻しても同じ token 不一致を踏み直すだけで詰みが再生産される。
     *   セッションと token を取り直せる導線が唯一の確実な脱出路である。
     */
    public function forcesGuestDestinations(): bool
    {
        return $this === self::PageExpired;
    }

    /** 5xx (app.debug 中は差し替えない判定に使う)。 */
    public function isServerError(): bool
    {
        return $this->value >= 500;
    }
}
