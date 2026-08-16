import { afterEach, describe, expect, it, vi } from "vitest";
import {
    encodeStillJpeg,
    fitWithinMaxEdge,
    normalizeStillFile,
    STILL_CONTENT_TYPE,
    STILL_JPEG_QUALITY,
    STILL_MAX_EDGE,
} from "@/lib/capture/still-encode";

/*
 * 静止画テイクのエンコード規約。
 * 契約の要点は「失敗は必ず null で返す (reject しない)」で、呼び出し側に .catch() を
 * 配って回らずに済むようにここ 1 か所で閉じている。
 */

afterEach(() => {
    vi.restoreAllMocks();
});

/** canvas を差し替える (jsdom は toBlob / 2d context を持たない) */
function stubCanvas(options: {
    context: unknown;
    toBlob?: (callback: (blob: Blob | null) => void, type?: string, quality?: number) => void;
}): { calls: { type?: string; quality?: number }[] } {
    const calls: { type?: string; quality?: number }[] = [];
    vi.spyOn(document, "createElement").mockImplementation((tag: string) => {
        if (tag !== "canvas") {
            return document.createElementNS("http://www.w3.org/1999/xhtml", tag) as HTMLElement;
        }
        return {
            width: 0,
            height: 0,
            getContext: () => options.context,
            toBlob:
                options.toBlob ??
                ((callback: (blob: Blob | null) => void, type?: string, quality?: number) => {
                    calls.push({ type, quality });
                    callback(new Blob(["jpeg"], { type: STILL_CONTENT_TYPE }));
                }),
        } as unknown as HTMLElement;
    });
    return { calls };
}

describe("fitWithinMaxEdge", () => {
    it("長辺が上限以下なら等倍のまま (拡大しない)", () => {
        expect(fitWithinMaxEdge(640, 480)).toEqual({ width: 640, height: 480 });
    });

    it("長辺が上限を超えたら比率を保って縮小する", () => {
        expect(fitWithinMaxEdge(3840, 2160)).toEqual({
            width: STILL_MAX_EDGE,
            height: Math.round((2160 * STILL_MAX_EDGE) / 3840),
        });
    });

    it("縦長でも長辺基準で縮む", () => {
        expect(fitWithinMaxEdge(2160, 3840)).toEqual({
            width: Math.round((2160 * STILL_MAX_EDGE) / 3840),
            height: STILL_MAX_EDGE,
        });
    });

    it("寸法 0 でも 0 除算にならない", () => {
        expect(fitWithinMaxEdge(0, 0)).toEqual({ width: 0, height: 0 });
    });
});

describe("encodeStillJpeg", () => {
    it("JPEG blob を規約どおりの type / quality で返す", async () => {
        const { calls } = stubCanvas({ context: { drawImage: vi.fn() } });

        const blob = await encodeStillJpeg({} as CanvasImageSource, 640, 480);

        expect(blob).not.toBeNull();
        expect(calls[0]).toEqual({ type: STILL_CONTENT_TYPE, quality: STILL_JPEG_QUALITY });
    });

    it("寸法 0 (grant 前の video など) では null (原本を送らせない)", async () => {
        const blob = await encodeStillJpeg({} as CanvasImageSource, 0, 0);
        expect(blob).toBeNull();
    });

    it("2d context を取れなければ null", async () => {
        stubCanvas({ context: null });
        expect(await encodeStillJpeg({} as CanvasImageSource, 640, 480)).toBeNull();
    });

    it("drawImage が throw しても reject せず null", async () => {
        stubCanvas({
            context: {
                drawImage: () => {
                    throw new Error("tainted");
                },
            },
        });
        expect(await encodeStillJpeg({} as CanvasImageSource, 640, 480)).toBeNull();
    });

    it("toBlob が null を返したら null", async () => {
        stubCanvas({
            context: { drawImage: vi.fn() },
            toBlob: (callback) => callback(null),
        });
        expect(await encodeStillJpeg({} as CanvasImageSource, 640, 480)).toBeNull();
    });

    it("toBlob が throw しても reject せず null", async () => {
        stubCanvas({
            context: { drawImage: vi.fn() },
            toBlob: () => {
                throw new Error("unsupported");
            },
        });
        expect(await encodeStillJpeg({} as CanvasImageSource, 640, 480)).toBeNull();
    });
});

describe("normalizeStillFile", () => {
    it("デコードできなければ null (原本を送らない)", async () => {
        vi.stubGlobal("URL", {
            ...URL,
            createObjectURL: () => "blob:stub",
            revokeObjectURL: () => undefined,
        });
        class FailingImage {
            onload: (() => void) | null = null;
            onerror: (() => void) | null = null;
            naturalWidth = 0;
            naturalHeight = 0;
            set src(_value: string) {
                queueMicrotask(() => this.onerror?.());
            }
        }
        vi.stubGlobal("Image", FailingImage);

        const file = new File(["not-an-image"], "x.jpg", { type: "image/jpeg" });
        expect(await normalizeStillFile(file)).toBeNull();

        vi.unstubAllGlobals();
    });
});
