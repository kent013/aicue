/**
 * SSO プロバイダの表示名解決。
 * プロバイダ追加時は config/template.php (social_providers) と合わせてここに表示名を足す。
 */

const PROVIDER_LABELS: Record<string, string> = {
    google: "Google",
    github: "GitHub",
};

/** Socialite driver 名 → 表示名 (未知のプロバイダは先頭大文字で fallback) */
export function providerLabel(provider: string): string {
    return PROVIDER_LABELS[provider] ?? provider.charAt(0).toUpperCase() + provider.slice(1);
}
