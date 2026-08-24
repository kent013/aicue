import { orgUrl } from "@/lib/org-url";

/**
 * テイク API (capture.takes.*) の URL 導出。**規則をここ 1 箇所に置く**。
 *
 * この API 面は撮影 PWA (Capture/Show の TakeStrip) と PC 編集面
 * (Manuals/Takes) の**両方が叩く**。URL prefix が /app なのは歴史的経緯であり、
 * テイク資源の唯一の API 面である (doc/10 / docs/architecture.md §撮影 PWA の運用契約)。
 *
 * ★組織は URL に載る (家系裁定 AG-037)。**名前付きフィールドで受ける**ので、
 *   位置引数のずれ (project が organization の位置に入る) が構造的に起きない。
 */
export interface TakeEndpointTarget {
    organizationSlug: string;
    projectId: number;
    manualId: number;
    cutId: number;
}

/** カット配下のテイクコレクション URL (POST = 登録) */
export function cutTakesUrl({
    organizationSlug,
    projectId,
    manualId,
    cutId,
}: TakeEndpointTarget): string {
    return orgUrl(
        organizationSlug,
        `/app/projects/${projectId}/manuals/${manualId}/cuts/${cutId}/takes`,
    );
}

/** テイク単体の URL (suffix で /adopt /playback 等を足す) */
export function takeUrl(target: TakeEndpointTarget, takeId: number, suffix = ""): string {
    return `${cutTakesUrl(target)}/${takeId}${suffix}`;
}

/** presigned upload-url 発行 URL */
export function takeUploadUrlEndpoint(target: TakeEndpointTarget): string {
    return `${cutTakesUrl(target)}/upload-url`;
}
