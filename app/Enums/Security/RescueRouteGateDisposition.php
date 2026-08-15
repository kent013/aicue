<?php

declare(strict_types=1);

namespace App\Enums\Security;

use App\Enums\Account\AccountDeletionFreezeAllowance;
use App\Http\Middleware\RequireTwoFactorForEnforcedOrganizations;
use LogicException;

/**
 * 救済 route (退会予約の取消) の経路上にある middleware が、その救済 route をどう扱うかの宣言。
 * **deny-by-default** (`RescueRouteGateInventoryTest` が母集団と exact-fit で照合する)。
 *
 * 背景: bug-hunt F-4-01。凍結 middleware は取消を通していたのに、実行順が先の 2FA 強制ゲートが
 * 弾いていた = **ゲート同士で判断が食い違っていた**。人の記憶ではなく目録で守る。
 *
 * ★保証する不変条件は「**分類の網羅**」であって「route 上の全 middleware を通過できること」ではない。
 *   母集団は「自前 middleware 全件 + 名指しした認証系 vendor gate 3 本」であり、
 *   cookie / session 開始 / CSRF / binding / Inertia 履歴暗号化は**入れていない**
 *   (Laravel 側のクラス名変更・構成変更で偽赤にしないため。実際 CSRF は L12 で
 *   `PreventRequestForgery` に改名されている)。
 */
enum RescueRouteGateDisposition: string
{
    /** 救済経路そのもの (この route 名で照合する)。 */
    public const string RESCUE_ROUTE_NAME = 'settings.account.deletion-request.destroy';

    case Authenticate = 'Illuminate\Auth\Middleware\Authenticate';
    case AuthenticateSession = 'Illuminate\Session\Middleware\AuthenticateSession';
    case EnsureEmailIsVerified = 'Illuminate\Auth\Middleware\EnsureEmailIsVerified';
    case HandleInertiaRequests = 'App\Http\Middleware\HandleInertiaRequests';
    case SecurityHeaders = 'App\Http\Middleware\SecurityHeaders';
    case RequireTwoFactor = 'App\Http\Middleware\RequireTwoFactorForEnforcedOrganizations';
    case BlockTwoFactorDisable = 'App\Http\Middleware\BlockTwoFactorDisableForEnforcedOrganizations';
    case NoStoreCacheHeaders = 'App\Http\Middleware\NoStoreCacheHeadersForAuthenticatedPages';
    case IssueSessionEpochCookie = 'App\Http\Middleware\IssueSessionEpochCookie';
    case NotPendingDeletion = 'App\Http\Middleware\EnsureAccountNotPendingDeletion';
    case BughuntExecutedRoute = 'App\Http\Middleware\BughuntExecutedRouteMiddleware';

    /** この middleware が救済 route をどう扱うかの分類。 */
    public function disposition(): RescueRouteGateKind
    {
        return match ($this) {
            self::RequireTwoFactor, self::NotPendingDeletion => RescueRouteGateKind::PassesRescueRoute,
            self::Authenticate, self::AuthenticateSession, self::EnsureEmailIsVerified => RescueRouteGateKind::ShortCircuitsButEscapable,
            self::HandleInertiaRequests, self::SecurityHeaders, self::BlockTwoFactorDisable,
            self::NoStoreCacheHeaders, self::IssueSessionEpochCookie,
            self::BughuntExecutedRoute => RescueRouteGateKind::NeverShortCircuitsRescueRoute,
        };
    }

    /**
     * 分類の根拠 (**30 文字以上**。gate が長さを検査する)。
     *
     * 「なぜ救済 (誤操作した退会予約の取消) が失われないか」を 1 case ずつ書く。
     */
    public function rationale(): string
    {
        return match ($this) {
            self::Authenticate => '未認証セッションは login へ倒れる。ログイン面は凍結母集団の外かつ '
                .'2FA 強制ゲートの対象外 (未認証は素通し) なので、ログインし直せば救済 route へ戻れる。'
                .'救済の権利は失われず、遅延するだけである。',
            self::AuthenticateSession => 'パスワード変更等で他セッションが失効すると login へ倒れるが、'
                .'再ログインで救済 route に戻れる (予約は users 行に永続しており消えない)。'
                .'凍結方式は users 行の生死を変えないため、待っている間も取消の対象は残る。',
            self::EnsureEmailIsVerified => 'メール未検証なら verification.notice へ倒れる。同 route は'
                .'凍結 allowlist の対象外 (母集団に入っていない) かつ 2FA ゲートの allowlist 内なので、'
                .'検証を済ませれば救済 route へ到達できる。',
            self::HandleInertiaRequests => '共有 props の組み立てとバージョン整合のみ。asset 版不一致に'
                .'よる 409 強制再読込は **GET のみ**が対象で、救済 route は DELETE のため該当しない。'
                .'よってこの middleware が救済を短絡させる経路は存在しない。',
            self::SecurityHeaders => '応答ヘッダ (CSP 等) を付けるだけで、リクエストを短絡させる分岐を'
                .'持たない。$next の戻り値を加工する後段処理のみであり、救済 route の到達性に影響しない。',
            self::RequireTwoFactor => '2FA 必須組織の未準拠ユーザーを settings.security へ倒すゲート。'
                .'救済 route は ALLOWED_ROUTE_NAMES に明示登録して通す (F-4-01 の主措置)。'
                .'**non-exemptible** — ここを「通さない」に格下げすると finding が再発する。',
            self::BlockTwoFactorDisable => '判定対象は two-factor.disable 1 本のみで、他の route 名では'
                .'無条件に素通しする。救済 route の名前とは一致しないため短絡経路が構造的に無い。',
            self::NoStoreCacheHeaders => '認証済みページに Cache-Control: no-store を付けるだけの'
                .'応答加工であり、リクエストを短絡させる分岐を持たない。救済の到達性に影響しない。',
            self::IssueSessionEpochCookie => 'セッション世代の印を応答に cookie として載せるだけの'
                .'応答加工であり、リクエストを短絡させる分岐を持たない。印が導出できない要求 '
                .'(session を持たない) では cookie を付けずにそのまま返すため、救済の到達性に影響しない。',
            self::NotPendingDeletion => '退会予約中の凍結ゲート。救済 route は '
                .'AccountDeletionFreezeAllowance::DeletionRequestDestroy として登録済みで、'
                .'凍結中に必ず実行できなければ猶予期間の意味が消える。**non-exemptible**。',
            self::BughuntExecutedRoute => 'bug-hunt の実行済み route 記録器。必ず $next を呼び、'
                .'応答も加工しない観測器であり、リクエストを短絡させる分岐を持たない。'
                .'加えて既定 no-op (env 既定 false + production 除外) なので救済の到達性に影響しない。',
        };
    }

    /**
     * 実装側の allowlist を実際に引いて、この middleware が当該 route 名を通すかを返す。
     *
     * `PassesRescueRoute` の case だけが true/false を返せる (宣言だけで緑にならないための構造)。
     * それ以外の disposition では `LogicException` を投げる。
     */
    public function permitsRouteName(string $routeName): bool
    {
        return match ($this) {
            self::RequireTwoFactor => array_key_exists(
                $routeName,
                RequireTwoFactorForEnforcedOrganizations::ALLOWED_ROUTE_NAMES,
            ),
            self::NotPendingDeletion => AccountDeletionFreezeAllowance::tryFrom($routeName) !== null,
            default => throw new LogicException(
                'permitsRouteName は PassesRescueRoute の case でのみ使える: '.$this->value,
            ),
        };
    }

    /**
     * 目録に登録された middleware クラス名の集合。
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
