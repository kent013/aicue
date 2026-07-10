import { afterEach, describe, expect, it, vi } from "vitest";
import { preferredRecordingMimeType, supportsMediaRecorder } from "@/lib/capture/camera";

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
