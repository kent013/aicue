<?php

declare(strict_types=1);

namespace Tests\Support\Routing;

/**
 * 組織 URL 配下に**置かない** web route の目録 (家系裁定 AG-037 / 不変条件 I2)。
 *
 * ★deny-by-default である。`web` group と `auth` を宣言した named route は、ここに登録が
 *   無ければ `{organization}` param を持たなければならない
 *   (`OrganizationScopedRouteCoverageTest` が固定する)。
 * ★登録は**理由付き**で行う。理由は 30 文字以上を gate が検査する
 *   (「なんとなく除外」を書けなくする)。
 * ★接頭辞での除外は**明示した接頭辞だけ**を認める。`organizations.` のような広い接頭辞で
 *   母集団ごと消せないよう、接頭辞にも 1 件ずつ理由を書く。
 * ★**保証しないもの**: 本目録が守るのは「業務 route が組織 URL 配下に在ること」だけである。
 *   組織を跨いだ読み書きが起きないことは binder (`MembershipScopedOrganizationBinder`) と
 *   `EnsureProjectBelongsToRouteOrganization` の担当であり、ここでは何も主張しない。
 */
final class OrganizationlessWebRouteInventory
{
    /**
     * 完全一致で除外する route 名 => 理由 (30 文字以上)。
     *
     * @return array<string, string>
     */
    public static function exactNames(): array
    {
        return [
            'app.entry' => '組織文脈を持たない汎用入口そのもの。所属数で分岐する役目なので、'
                .'ここに組織を要求すると入口が成立しない (鶏と卵になる)。',
            'capture.entry' => 'PWA の start_url (manifest)。ホーム画面から起動した時点では'
                .'組織が決まっていないため、所属数で分岐する入口として組織を持たない。',
            'organizations.create' => '組織そのものを作る前の画面。作成対象がまだ存在しないので'
                .'URL に組織を載せられない (載せる対象が無い)。',
            'organizations.store' => '組織の作成 endpoint。作成前なので URL に組織を載せられない。'
                .'作成後の遷移先は作った組織の URL である。',
            'settings' => '個人設定の面。組織ではなく利用者に属する情報だけを扱うため、'
                .'組織文脈を持たない (持たせると「どの組織で見た個人設定か」という無意味な区別が生まれる)。',
            'settings.security' => '個人の認証手段 (2FA / パスキー / ソーシャル連携) の管理。'
                .'利用者に属する情報であり組織に属さない。',
            'settings.password.store' => '個人のパスワード初回設定。利用者の認証手段であり'
                .'組織には属さない (組織ごとにパスワードが違うことはない)。',
            'settings.account.destroy' => '退会 (即時)。利用者そのものを消す操作であり、'
                .'特定の組織の文脈では実行しない。',
            'settings.account.deletion-request.store' => '退会予約。利用者そのものに対する操作で'
                .'組織に属さない (この面から組織文脈を導出しないことが施策 7 の帰結である)。',
            'settings.account.deletion-request.destroy' => '退会予約の取消。予約と対称の'
                .'利用者単位の操作であり、組織文脈を持たない。',
            'recent-auth.confirm' => 'step-up 再認証の画面。認証は利用者に属し組織に属さない。'
                .'組織を要求すると再認証の入口が組織ごとに分裂する。',
            'recent-auth.status' => 'step-up の鮮度 precheck (XHR)。認証状態は利用者に属する。',
            'recent-auth.password' => 'step-up の password satisfier。認証は利用者に属する。',
            'invitations.accept.store' => '招待の受諾 (token 経路)。受諾するまで招待先組織の'
                .'メンバーではないため、組織 URL 配下に置くと binder が 404 に倒して受諾できない。',
            'invitations.accept-in-app' => 'アプリ内受諾。token 経路と同じ理由で、受諾前は'
                .'招待先組織のメンバーではないため組織 URL 配下に置けない。',
        ];
    }

    /**
     * 接頭辞で除外する route 名 => 理由 (30 文字以上)。
     *
     * @return array<string, string>
     */
    public static function prefixes(): array
    {
        return [
            'debug.' => 'local 専用のデバッグ route (LocalOnly middleware + 登録自体が local 限定)。'
                .'production には存在しないため業務 route の母集団に入れない。',
        ];
    }
}
