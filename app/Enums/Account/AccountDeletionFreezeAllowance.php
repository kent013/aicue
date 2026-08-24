<?php

declare(strict_types=1);

namespace App\Enums\Account;

/**
 * 退会予約中 (凍結) に**通してよい route 名**の目録。**deny-by-default**。
 *
 * ここに無い route は予約中に遮断され `/settings` (取消ボタンのある画面) へ 302 する。
 *
 * ★**wildcard を書かない** (route 名の exact case のみ)。`billing.*` のような namespace 指定を
 *   許すと購入・新規契約・自動チャージ有効化まで一緒に通り、凍結の意味が消える。
 * ★母集団 (`U` = 凍結 middleware が付いた全 route) との関係は **`A ⊆ U`**。
 *   `U` に無い route 名は書けない (死に登録の防止)。実装と宣言の一致・母集団の内外は
 *   `tests/Architecture/AccountDeletionFreezeRouteGateTest.php` が機械固定する。
 *
 * ★**`settings.account.destroy` (即時削除) は入れない**。予約中のユーザーが表明した意思は
 *   「30 日後に削除」であり、その状態で即時削除の口を開けておくと**猶予が守ろうとしているもの
 *   (誤操作) をそのまま通してしまう** (30 日猶予の迂回口になる)。「今すぐ消したい」なら
 *   **取消 → 即時削除**の 2 手を踏む (一貫した状態機械でありユーザーに説明できる)。
 * ★**`notifications.open` は入れない**。POST + 303 で通知の遷移先へ飛ばす route であり、
 *   入れると「通知経由なら業務 route / dashboard / checkout に到達できる」抜け道になる。
 *   通知は `notifications.index` で読めるので rescue surface の役割は満たされる
 *   (「遷移先ごとに判定する」分岐は作らない = 凍結の判定点を 2 箇所に増やさない)。
 * ★**`billing.auto-recharge.update` は入れない**。同じ更新 endpoint が有効化・閾値変更・
 *   数量変更を受けるため、通すと**新しい課金責務を作る入口**になる。凍結中に自動チャージが
 *   発火する経路は構造的に存在しない (`AutoRechargeTriggerJob` を dispatch するのは
 *   `TicketLedgerService::reserve()` だけで、それを呼ぶ業務 route は凍結で全部止まる)。
 */
enum AccountDeletionFreezeAllowance: string
{
    // --- 取消に到達するための step-up (satisfier) ---
    case RecentAuthConfirm = 'recent-auth.confirm';
    case RecentAuthStatus = 'recent-auth.status';
    case RecentAuthPassword = 'recent-auth.password';
    // --- 取消 UI と取消そのもの ---
    case Settings = 'settings';
    case SettingsSecurity = 'settings.security';
    case DeletionRequestDestroy = 'settings.account.deletion-request.destroy';
    // --- 退会ブロッカー (生きた課金責務) の解消 ---
    case BillingIndex = 'billing.index';
    case BillingPortal = 'billing.portal';
    // --- 退会ブロッカー (孤児メンバー) の解消 ---
    case OrganizationSettings = 'organizations.settings';
    case TransferOwnership = 'organizations.transfer-ownership';
    case MemberUpdate = 'organizations.members.update';
    case MemberDestroy = 'organizations.members.destroy';
    case InvitationRevoke = 'organizations.invitations.revoke';
    // --- 予約・執行不能を知る手段 (読むだけ) ---
    case NotificationsIndex = 'notifications.index';
    case NotificationsReadAll = 'notifications.read-all';
    case NotificationsRead = 'notifications.read';

    /**
     * 通す根拠 (**30 文字以上**。gate が長さを検査する)。
     *
     * 「凍結中でもこれが無いと詰む」を 1 case ずつ書く。書けないなら通してはいけない。
     */
    public function rationale(): string
    {
        return match ($this) {
            self::RecentAuthConfirm => '取消自体に step-up は不要だが、ブロッカー解消経路である'
                .'オーナー移譲 (organizations.transfer-ownership) が recent-auth を持つため、'
                .'この確認画面に到達できないと移譲ができず退会も取消後の再削除もできず詰む。',
            self::RecentAuthStatus => 'クライアント主導 step-up の precheck (XHR)。これを塞ぐと '
                .'/settings 上の各操作が鮮度判定に失敗し、再認証モーダルを出せないまま'
                .'無反応になる (押したのに何も起きない詰み)。読み取りのみで状態を変えない。',
            self::RecentAuthPassword => 'step-up の password satisfier。確認画面に到達できても'
                .'ここが塞がると再認証が完了せず、オーナー移譲によるブロッカー解消が不可能になる。'
                .'認証手段の増減はせず、鮮度を更新するだけの経路である。',
            self::Settings => '退会予約バナーと**取消ボタン**が置かれた画面そのもの。凍結の着地先で'
                .'あり、ここを通さないと 302 が自分自身へ無限ループし、誤操作救済が成立しない。',
            self::SettingsSecurity => '2FA 必須組織の**未準拠**ユーザーにとって唯一の脱出口。'
                .'2FA 強制ゲートは凍結より前に走り、未準拠だと取消 DELETE を settings.security へ倒す。'
                .'ここを通さないと「取消は 2FA ゲートに阻まれ、2FA 設定は凍結に阻まれる」'
                .'相互ブロックの詰みになる (実測して発見)。ログイン手段の管理面であり課金責務を作らない。',
            self::DeletionRequestDestroy => '退会予約の取消そのもの。誤操作救済の本体であり、'
                .'凍結中に必ず実行できなければ猶予期間を設けた意味が消える (取り消せない詰み)。',
            self::BillingIndex => '退会ブロッカーのひとつ「生きた課金責務」を解消する導線の起点。'
                .'解約手段に到達できないと、ブロックされたまま 30 日後の執行も失敗し続ける。',
            self::BillingPortal => 'Customer Portal のセッション生成。PortalConfigurationSpec が '
                .'subscription_update=false / subscription_cancel=at_period_end を宣言しており、'
                .'Portal からは**解約と支払い方法更新だけ**ができる = 責務を減らす方向のみ。'
                .'**この spec が変われば通してよい前提が崩れる** (gate が spec を pin する)。',
            self::OrganizationSettings => 'オーナー移譲・メンバー整理の操作 UI が置かれた画面。'
                .'ブロッカー解消の入口であり、閲覧できないと「次の一手」が押せず詰む。',
            self::TransferOwnership => '退会ブロッカー「唯一 Owner かつ他メンバーが残る」の唯一の'
                .'解消手段。凍結中に実行できないとブロッカーが永久に残り、執行が毎日失敗し続ける。',
            self::MemberUpdate => 'メンバー整理 (ロール変更) によるブロッカー解消経路。'
                .'組織の owner 集合を正す操作であり、新しい課金責務も新しいデータも作らない。',
            self::MemberDestroy => '孤児化するメンバーを外すことでブロッカーを解消する経路。'
                .'退会条件を満たすための除去操作であり、責務を増やす方向には働かない。',
            self::InvitationRevoke => '送信済み招待の取り消し。予約中に新しいメンバーが増えると'
                .'ブロッカーが再発するため、**招待の送信は通さず取り消しだけ**を通す非対称にする。',
            self::NotificationsIndex => '予約内容 (いつ削除されるか) と執行結果を本人が読む手段。'
                .'メールが届かない環境でも状況を把握できる rescue surface として必要。',
            self::NotificationsReadAll => '通知一覧の一括既読化。既読フラグを進めるだけで業務状態も'
                .'課金も動かさず、一覧が読める以上ここを塞ぐ理由がない (未読が永久に残る)。',
            self::NotificationsRead => '通知 1 件の既読化。read-all と同じく既読フラグのみを'
                .'更新する読み取り面の操作で、遷移も伴わない (遷移する open は通さない)。',
        };
    }

    /**
     * 通してよい route 名の集合。
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
