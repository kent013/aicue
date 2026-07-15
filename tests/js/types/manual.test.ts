import { describe, expect, it } from "vitest";
import {
    CAPTURE_NAVIGABLE_BY_STATUS,
    isAnalyzable,
    isCaptureNavigable,
    isScenarioEstablished,
    SCENARIO_ANALYZABLE_BY_STATUS,
    SCENARIO_ESTABLISHED_BY_STATUS,
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

describe("isScenarioEstablished", () => {
    it.each<[VideoManualStatus, boolean]>([
        ["draft", false],
        ["analyzing", false],
        ["ready", true],
        ["rendering", true],
        ["published", true],
    ])("%s -> %s", (status, expected) => {
        expect(isScenarioEstablished(status)).toBe(expected);
    });

    it("全 VideoManualStatus のキーを持つ", () => {
        expect(Object.keys(SCENARIO_ESTABLISHED_BY_STATUS).sort()).toEqual([
            "analyzing",
            "draft",
            "published",
            "ready",
            "rendering",
        ]);
    });
});

describe("isAnalyzable", () => {
    it.each<[VideoManualStatus, boolean]>([
        ["draft", true],
        ["analyzing", false],
        ["ready", true],
        ["rendering", false],
        ["published", false],
    ])("%s -> %s", (status, expected) => {
        expect(isAnalyzable(status)).toBe(expected);
    });

    it("全 VideoManualStatus のキーを持つ", () => {
        expect(Object.keys(SCENARIO_ANALYZABLE_BY_STATUS).sort()).toEqual([
            "analyzing",
            "draft",
            "published",
            "ready",
            "rendering",
        ]);
    });
});
