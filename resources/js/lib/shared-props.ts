import type { FlashPayload } from "@/lib/stores/flash-to-toast";
import type { InvitationSharedProps } from "@/types/invitation";
import type { NotificationSharedProps } from "@/types/notification";

/**
 * HandleInertiaRequests が共有する props の型 (backend が真実)。
 * ページ側は `page.props as unknown as SharedProps` で参照する。
 */

export interface AuthUser {
    id: number;
    name: string;
    email: string;
    emailVerified: boolean;
    twoFactorEnabled: boolean;
}

export interface OrganizationSummary {
    id: number;
    name: string;
    /** 別組織の URL を組み立てるための識別名 (組織文脈は URL だけで決まる = AG-037) */
    slug: string;
}

/** OrganizationRole enum の value と 1:1 のユニオン (型の網羅性を上げる) */
export type OrganizationRoleValue =
    | "organization_owner"
    | "organization_admin"
    | "organization_member";

/**
 * URL の binding から導出した組織文脈。**組織 route 以外では必ず null** である
 * (家系裁定 AG-037: 「いまどの組織か」は URL だけで決まる。保持列は撤去済み)。
 */
export interface CurrentOrganization {
    id: number;
    name: string;
    /** 組織 URL 配下の全 route ({organization:slug}) の組み立てに使う */
    slug: string;
    role: OrganizationRoleValue | null;
    /** メンバー管理 (manage.users.index) 導線の表示可否 (owner/admin) */
    canManageMembers: boolean;
    /** API キー画面 (organizations.api-keys.index) 導線の表示可否 */
    canManageApiKeys: boolean;
}

/**
 * 共有 props が認証済みユーザー (auth.user) を持つか。
 *
 * bfcache guard のように「認証済みページでのみ作動させたい」機構が、page.props を
 * 直接掘らずに済むようにする単一判定点。型は backend (HandleInertiaRequests) が真実だが、
 * 実行時は unknown として保守的に検査する。
 */
export function hasAuthenticatedUser(props: unknown): boolean {
    if (typeof props !== "object" || props === null) return false;
    const auth = (props as { auth?: unknown }).auth;
    if (typeof auth !== "object" || auth === null) return false;
    const user = (auth as { user?: unknown }).user;
    return typeof user === "object" && user !== null;
}

/** サーバが配る描画世代の書式 (PHP の SessionEpoch::VALUE_PATTERN と対)。 */
const SESSION_EPOCH_PATTERN = /^[0-9a-f]{32}$/;

/**
 * 共有 props から描画世代を読む。**書式が違えば null に倒す**
 * (「読めない」は bfcache guard 側で「開示しない」に写る)。
 */
export function readSessionEpoch(props: unknown): string | null {
    if (typeof props !== "object" || props === null) return null;
    const value = (props as { sessionEpoch?: unknown }).sessionEpoch;
    if (typeof value !== "string") return null;
    return SESSION_EPOCH_PATTERN.test(value) ? value : null;
}

export interface SharedProps {
    appName: string;
    auth: { user: AuthUser | null };
    organizations: OrganizationSummary[];
    currentOrganization: CurrentOrganization | null;
    flash: FlashPayload;
    /** 通知センターの未読数 (全 org 横断・自分宛のみ。未ログイン時は 0) */
    notifications: NotificationSharedProps;
    /**
     * 自分宛の受諾可能な招待の件数 (未ログイン / 未 verified / email 空は 0)。
     * キー名が `invitations` でないのは、ページ prop `invitations` (Admin/Users の招待一覧) と
     * 衝突して共有 prop が上書きされるのを避けるため。
     */
    invitationInbox: InvitationSharedProps;
    /** サーバ描画 <title> と同一の完成タイトル (document-title.ts が SPA 遷移時に同期する) */
    title: string;
    /**
     * この応答を作ったセッションの世代の印 (32 文字の 16 進)。bfcache 復元時の同期判定で
     * 世代 cookie と突き合わせる。session を持たない要求では null。
     */
    sessionEpoch: string | null;
}
