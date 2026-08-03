import type { FlashPayload } from "@/lib/stores/flash-to-toast";
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
    isPersonal: boolean;
}

/** OrganizationRole enum の value と 1:1 のユニオン (型の網羅性を上げる) */
export type OrganizationRoleValue =
    | "organization_owner"
    | "organization_admin"
    | "organization_member";

export interface CurrentOrganization {
    id: number;
    name: string;
    /** organizations.settings / api-keys.index ({organization:slug}) 用 */
    slug: string;
    role: OrganizationRoleValue | null;
    /** メンバー管理 (/manage/users) 導線の表示可否 (owner/admin) */
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

export interface SharedProps {
    appName: string;
    auth: { user: AuthUser | null };
    organizations: OrganizationSummary[];
    currentOrganization: CurrentOrganization | null;
    flash: FlashPayload;
    /** 通知センターの未読数 (全 org 横断・自分宛のみ。未ログイン時は 0) */
    notifications: NotificationSharedProps;
    /** サーバ描画 <title> と同一の完成タイトル (document-title.ts が SPA 遷移時に同期する) */
    title: string;
}
