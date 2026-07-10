/**
 * 同一オリジン XHR (fetch) 用の CSRF ヘルパ。
 * Laravel が発行する XSRF-TOKEN cookie (encrypted cookie 対応の URL エンコード済み値) を読み、
 * X-XSRF-TOKEN ヘッダ値へ変換する。RecentAuthModal / ScenarioEditor で共用する。
 */
export function csrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : "";
}
