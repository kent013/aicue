// OAuth 接続セッション一覧 (Web UI) の 1 行。
// サーバ側 App\DataTransferObjects\OAuth\OAuthSessionListItemDto::toArray() と shape を揃える。
// CLI セッション (revocable) と legacy MCP token (session 無し = 棚卸し表示のみ) を同一 shape に
// 正規化する。isLegacy=true の行は id が access token id で、セッション単位の失効経路を持たない。
export interface OAuthSession {
    id: string;
    userId: number;
    userName: string | null;
    clientKind: string;
    scopes: string[];
    lastUsedAt: string | null;
    revokedAt: string | null;
    createdAt: string;
    isLegacy: boolean;
}
