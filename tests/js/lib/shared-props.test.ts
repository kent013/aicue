/**
 * Tests for resources/js/lib/shared-props.ts
 *
 * 描画世代 (共有 prop `sessionEpoch`) の読み取りは、**書式が違えば null に倒す**。
 * 「読めない」は bfcache guard 側で「開示しない (読み直す)」に写るため、
 * ここを緩めると同期判定の前置が意味を失う。
 */
import { describe, expect, it } from "vitest";

import { hasAuthenticatedUser, readSessionEpoch } from "@/lib/shared-props";

const EPOCH = "0123456789abcdef0123456789abcdef";

describe("readSessionEpoch", () => {
    it("正しい書式の値をそのまま返す", () => {
        expect(readSessionEpoch({ sessionEpoch: EPOCH })).toBe(EPOCH);
    });

    it("欠落・null・型違いは null", () => {
        expect(readSessionEpoch({})).toBeNull();
        expect(readSessionEpoch({ sessionEpoch: null })).toBeNull();
        expect(readSessionEpoch({ sessionEpoch: 12345 })).toBeNull();
        expect(readSessionEpoch(null)).toBeNull();
        expect(readSessionEpoch("string")).toBeNull();
        expect(readSessionEpoch(undefined)).toBeNull();
    });

    it("書式違い (大文字 / 33 文字 / 31 文字 / 非 16 進) は null", () => {
        expect(readSessionEpoch({ sessionEpoch: EPOCH.toUpperCase() })).toBeNull();
        expect(readSessionEpoch({ sessionEpoch: `${EPOCH}0` })).toBeNull();
        expect(readSessionEpoch({ sessionEpoch: EPOCH.slice(0, 31) })).toBeNull();
        expect(readSessionEpoch({ sessionEpoch: `${EPOCH.slice(0, 31)}g` })).toBeNull();
        expect(readSessionEpoch({ sessionEpoch: "" })).toBeNull();
    });
});

describe("hasAuthenticatedUser", () => {
    it("auth.user がオブジェクトのときだけ true", () => {
        expect(hasAuthenticatedUser({ auth: { user: { id: 1 } } })).toBe(true);
        expect(hasAuthenticatedUser({ auth: { user: null } })).toBe(false);
        expect(hasAuthenticatedUser({ auth: {} })).toBe(false);
        expect(hasAuthenticatedUser({})).toBe(false);
        expect(hasAuthenticatedUser(null)).toBe(false);
    });
});
