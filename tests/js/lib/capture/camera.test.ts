import { afterEach, describe, expect, it, vi } from "vitest";
import {
    classifyGetUserMediaError,
    preferredRecordingMimeType,
    supportsMediaRecorder,
} from "@/lib/capture/camera";

/*
 * カメラ対応判定: MediaRecorder + getUserMedia + isTypeSupported の 3 条件が
 * 揃わない環境 (iOS Safari 旧版等) では file input フォールバックへ倒す。
 */

function stubMediaRecorder(supported: string[]): void {
    vi.stubGlobal("MediaRecorder", {
        isTypeSupported: (type: string) => supported.includes(type),
    });
}

function stubGetUserMedia(available: boolean): void {
    vi.stubGlobal("navigator", {
        ...navigator,
        mediaDevices: available ? { getUserMedia: vi.fn() } : undefined,
    });
}

afterEach(() => {
    vi.unstubAllGlobals();
});

describe("supportsMediaRecorder", () => {
    it("MediaRecorder + getUserMedia + 対応 MIME が揃えば true", () => {
        stubMediaRecorder(["video/webm"]);
        stubGetUserMedia(true);

        expect(supportsMediaRecorder()).toBe(true);
    });

    it("MediaRecorder が無ければ false (フォールバック)", () => {
        vi.stubGlobal("MediaRecorder", undefined);
        stubGetUserMedia(true);

        expect(supportsMediaRecorder()).toBe(false);
    });

    it("getUserMedia が無ければ false", () => {
        stubMediaRecorder(["video/mp4"]);
        stubGetUserMedia(false);

        expect(supportsMediaRecorder()).toBe(false);
    });

    it("mp4 / webm のどちらも録画不可なら false", () => {
        stubMediaRecorder([]);
        stubGetUserMedia(true);

        expect(supportsMediaRecorder()).toBe(false);
    });

    it("isTypeSupported が未実装 (undefined) でも false (?? false)", () => {
        vi.stubGlobal("MediaRecorder", {});
        stubGetUserMedia(true);

        expect(supportsMediaRecorder()).toBe(false);
    });
});

describe("preferredRecordingMimeType", () => {
    it("mp4 を優先し、無ければ webm、どちらも無ければ null", () => {
        stubMediaRecorder(["video/mp4", "video/webm"]);
        expect(preferredRecordingMimeType()).toBe("video/mp4");

        stubMediaRecorder(["video/webm"]);
        expect(preferredRecordingMimeType()).toBe("video/webm");

        stubMediaRecorder([]);
        expect(preferredRecordingMimeType()).toBeNull();
    });
});

describe("classifyGetUserMediaError", () => {
    it("NotAllowedError / SecurityError は permission_denied (unavailable)", () => {
        expect(classifyGetUserMediaError(new DOMException("denied", "NotAllowedError"))).toEqual({
            kind: "unavailable",
            reason: "permission_denied",
        });
        expect(classifyGetUserMediaError(new DOMException("", "SecurityError"))).toEqual({
            kind: "unavailable",
            reason: "permission_denied",
        });
    });

    it("NotFoundError / OverconstrainedError は device_missing (DOMException 非継承オブジェクトも)", () => {
        expect(classifyGetUserMediaError(new DOMException("", "NotFoundError"))).toEqual({
            kind: "unavailable",
            reason: "device_missing",
        });
        // OverconstrainedError は実装により DOMException を継承しないため name プロパティのみで判定
        expect(classifyGetUserMediaError({ name: "OverconstrainedError" })).toEqual({
            kind: "unavailable",
            reason: "device_missing",
        });
    });

    it("NotReadableError / AbortError は transient (再試行可能)", () => {
        expect(classifyGetUserMediaError(new DOMException("", "NotReadableError"))).toEqual({
            kind: "transient",
        });
        expect(classifyGetUserMediaError(new DOMException("", "AbortError"))).toEqual({
            kind: "transient",
        });
    });

    it("分類不能 (通常 Error / 文字列 / null) は unknown (フォールバック側へ倒す)", () => {
        expect(classifyGetUserMediaError(new Error("boom"))).toEqual({
            kind: "unavailable",
            reason: "unknown",
        });
        expect(classifyGetUserMediaError("boom")).toEqual({
            kind: "unavailable",
            reason: "unknown",
        });
        expect(classifyGetUserMediaError(null)).toEqual({
            kind: "unavailable",
            reason: "unknown",
        });
    });
});
