import { describe, expect, it } from "vitest";
import {
    cutTakesUrl,
    takeUploadUrlEndpoint,
    takeUrl,
} from "@/lib/capture/take-endpoints";

/*
 * テイク API の URL 導出。撮影 PWA (TakeStrip / UploadQueue) と PC 編集面
 * (Manuals/Takes) が**同じ 1 箇所**から URL を作ることを固定する。
 * prefix が /app なのは歴史的経緯であり、テイク資源の唯一の API 面である。
 */

const target = { organizationSlug: "test-org", projectId: 7, manualId: 12, cutId: 34 };

describe("take-endpoints", () => {
    it("cutTakesUrl はカット配下のテイクコレクション URL を返す", () => {
        expect(cutTakesUrl(target)).toBe("/organizations/test-org/app/projects/7/manuals/12/cuts/34/takes");
    });

    it("takeUrl は suffix なしでテイク単体 URL を返す", () => {
        expect(takeUrl(target, 56)).toBe("/organizations/test-org/app/projects/7/manuals/12/cuts/34/takes/56");
    });

    it("takeUrl は suffix (/adopt /playback /thumbnail) を末尾に足す", () => {
        expect(takeUrl(target, 56, "/adopt")).toBe(
            "/organizations/test-org/app/projects/7/manuals/12/cuts/34/takes/56/adopt",
        );
        expect(takeUrl(target, 56, "/playback")).toBe(
            "/organizations/test-org/app/projects/7/manuals/12/cuts/34/takes/56/playback",
        );
        expect(takeUrl(target, 56, "/thumbnail")).toBe(
            "/organizations/test-org/app/projects/7/manuals/12/cuts/34/takes/56/thumbnail",
        );
    });

    it("takeUploadUrlEndpoint は presigned 発行 URL を返す", () => {
        expect(takeUploadUrlEndpoint(target)).toBe(
            "/organizations/test-org/app/projects/7/manuals/12/cuts/34/takes/upload-url",
        );
    });
});
