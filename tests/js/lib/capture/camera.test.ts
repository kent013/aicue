import { afterEach, describe, expect, it, vi } from "vitest";
import {
    classifyGetUserMediaError,
    formatElapsed,
    nextFacingMode,
    preferredRecordingMimeType,
    supportsMediaRecorder,
    supportsPauseResume,
    videoConstraints,
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

describe("nextFacingMode", () => {
    it("environment ⇄ user を双方向に反転する", () => {
        expect(nextFacingMode("environment")).toBe("user");
        expect(nextFacingMode("user")).toBe("environment");
    });
});

describe("formatElapsed", () => {
    it("経過ミリ秒を mm:ss へ整形する (秒切り捨て)", () => {
        expect(formatElapsed(0)).toBe("00:00");
        expect(formatElapsed(5000)).toBe("00:05");
        expect(formatElapsed(65000)).toBe("01:05");
        expect(formatElapsed(3599000)).toBe("59:59");
    });

    it("60 分以上は mm が桁溢れして連続表示される (分を切り捨てない)", () => {
        expect(formatElapsed(3600000)).toBe("60:00");
    });

    it("負値・NaN は 00:00 に丸める", () => {
        expect(formatElapsed(-1)).toBe("00:00");
        expect(formatElapsed(Number.NaN)).toBe("00:00");
        expect(formatElapsed(Number.POSITIVE_INFINITY)).toBe("00:00");
    });
});

describe("supportsPauseResume", () => {
    it("prototype に pause/resume を持つ MediaRecorder は true", () => {
        vi.stubGlobal("MediaRecorder", {
            prototype: { pause: () => undefined, resume: () => undefined },
        });
        expect(supportsPauseResume()).toBe(true);
    });

    it("pause/resume を持たない MediaRecorder は false", () => {
        vi.stubGlobal("MediaRecorder", { prototype: {} });
        expect(supportsPauseResume()).toBe(false);
    });

    it("MediaRecorder 自体が無ければ false", () => {
        vi.stubGlobal("MediaRecorder", undefined);
        expect(supportsPauseResume()).toBe(false);
    });
});

/*
 * videoConstraints: getUserMedia の video 制約を facingMode から組む純関数。
 * .svelte 側のクロージャ読みから .ts の引数受け取りへ移した際の仕様固定
 * (呼出時点の facingMode をそのまま反映する = キャッシュしない)。
 */
describe("videoConstraints", () => {
    it("environment をそのまま facingMode に載せる", () => {
        expect(videoConstraints("environment")).toEqual({ facingMode: "environment" });
    });

    it("user をそのまま facingMode に載せる", () => {
        expect(videoConstraints("user")).toEqual({ facingMode: "user" });
    });

    it("呼び出しごとに引数を評価する (結果をキャッシュしない)", () => {
        expect(videoConstraints("environment")).toEqual({ facingMode: "environment" });
        expect(videoConstraints("user")).toEqual({ facingMode: "user" });
        expect(videoConstraints("environment")).toEqual({ facingMode: "environment" });
    });
});
