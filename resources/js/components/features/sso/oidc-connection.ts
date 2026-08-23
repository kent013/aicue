/**
 * 企業 OIDC 接続の状態の値域と表示語彙。
 *
 * PHP 側 `App\Enums\EnterpriseSso\OidcConnectionStatus` と対で保守する
 * (値集合の一致は `tests/js/architecture/enum-ts-sync.test.ts` が機械で固定する)。
 *
 * ★画面が値で分岐するのは**この 4 値だけ**である。`credentials_revision` は
 *   D1 の内部の比較子なので props にも本ファイルにも現れない。
 */

/** 接続の状態 (App\Enums\EnterpriseSso\OidcConnectionStatus と対)。 */
export type OidcConnectionStatus = "draft" | "verified" | "active" | "disabled";

/** 画面に出す日本語ラベル。 */
export const OIDC_CONNECTION_STATUS_LABELS: Record<OidcConnectionStatus, string> = {
    draft: "未確認",
    verified: "確認済み",
    active: "有効",
    disabled: "無効",
};

/** 状態バッジの色調 (Badge atom の tone 語彙)。 */
export const OIDC_CONNECTION_STATUS_TONES: Record<
    OidcConnectionStatus,
    "neutral" | "info" | "success" | "warning"
> = {
    draft: "neutral",
    verified: "info",
    active: "success",
    disabled: "warning",
};

/** 画面に出す 1 行の説明 (次に何をすればよいかを述べる)。 */
export const OIDC_CONNECTION_STATUS_HINTS: Record<OidcConnectionStatus, string> = {
    draft: "接続先情報をまだ確認していません。「確認」を押してください。",
    verified: "確認済みです。「有効化」を押すとこの IdP でログインできるようになります。",
    active: "この IdP でログインできます。",
    disabled: "停止中です。「有効化」で再開できます (登録済みの利用者はそのまま戻ります)。",
};

/** 企業 SSO の接続 1 件分 (PHP 側 SsoConnectionSummary と対で保守する)。 */
export interface SsoConnectionSummary {
    id: number;
    /** 公開のログイン導線で使う識別名。推測されてよい。 */
    loginSlug: string;
    displayName: string;
    issuer: string;
    clientId: string;
    status: OidcConnectionStatus;
    /** 秘密が保存されているか。★平文も伏字も渡らない (一覧の経路は復号しない)。 */
    hasClientSecret: boolean;
    /** ISO8601 (オフセット付き) / 一度も確認できていなければ null。 */
    verifiedAt: string | null;
    /** 身元が 1 件でもあるか (削除と issuer / client_id の変更ができるかの判断に使う)。 */
    hasIdentities: boolean;
}
