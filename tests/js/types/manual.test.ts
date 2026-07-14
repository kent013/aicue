import { describe, expect, it } from "vitest";
import {
    CAPTURE_NAVIGABLE_BY_STATUS,
    isCaptureNavigable,
    type VideoManualStatus,
} from "@/types/manual";

describe("isCaptureNavigable", () => {
    it.each<[VideoManualStatus, boolean]>([
        ["draft", false],
        ["analyzing", false],
        ["ready", true],
        ["rendering", false],
        ["published", true],
    ])("%s -> %s", (status, expected) => {
        expect(isCaptureNavigable(status)).toBe(expected);
    });

    it("全 VideoManualStatus のキーを持つ", () => {
        expect(Object.keys(CAPTURE_NAVIGABLE_BY_STATUS).sort()).toEqual([
            "analyzing",
            "draft",
            "published",
            "ready",
            "rendering",
        ]);
    });
});
