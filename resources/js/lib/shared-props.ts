import type { FlashPayload } from "@/lib/stores/flash-to-toast";

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

export interface CurrentOrganization {
    id: number;
    name: string;
    /** OrganizationRole の value (organization_owner / organization_admin / organization_member) */
    role: string | null;
}

export interface SharedProps {
    appName: string;
    auth: { user: AuthUser | null };
    organizations: OrganizationSummary[];
    currentOrganization: CurrentOrganization | null;
    flash: FlashPayload;
    /** サーバ描画 <title> と同一の完成タイトル (document-title.ts が SPA 遷移時に同期する) */
    title: string;
}
