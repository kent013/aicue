import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import {
    AdoptedTakeAutoDownloader,
    type AutoDownloadOptions,
    type FetchOutcome,
} from "@/lib/capture/auto-download";
import type { CaptureCut, CaptureManualDetail, CaptureTake } from "@/types/capture";

/*
 * 採用済みテイクの入室時自動 DL オーケストレータ (T051 / D6):
 * - 対象選別 (採用 && ready && 未 DL && playback_url≠null && ack_token≠null のみ)
 * - fetch 完読成功時のみ ACK / 有界リトライ / 状態 2 分離 / 多重起動防止 / 墓石掃除
 * DI (videoFetcher/ackFetch/delay/isOnline) で HTTP を持ち込まずロジックのみ検証する。
 */

function makeTake(overrides: Partial<CaptureTake> = {}): CaptureTake {
    return {
        id: 11,
        client_take_id: "01J0AUTODL",
        status: "ready",
        size_bytes: 1024,
        duration_ms: 4200,
        comment: null,
        captured_at: "2026-07-11T00:00:00Z",
        sort_order: 0,
        downloaded: false,
        has_thumbnail: false,
        playback_url: "https://s3.example.test/take-11.mp4?sig=1",
        download_ack_token: "ack-token-11",
        ...overrides,
    };
}

function makeCut(overrides: Partial<CaptureCut> = {}): CaptureCut {
    const takes = overrides.takes ?? [makeTake()];
    return {
        id: 101,
        type: "step",
        parent_cut_id: null,
        scene: "ネジを締める",
        shot_type: "hiki",
        shooting_point: "手元",
        narration: "ドライバーでネジを締めます",
        subtitle_primary: null,
        subtitle_secondary: "",
        adopted_take_id: takes[0]?.id ?? null,
        takes,
        ...overrides,
    };
}

function makeManual(cuts: CaptureCut[] = [makeCut()]): CaptureManualDetail {
    return { id: 5, title: "ネジ締め作業", status: "ready", cuts };
}

function okResponse(): Response {
    return new Response(null, { status: 200 });
}

/** delay を即時解決に差し替えた既定オプション + spy を返す */
function makeDeps(overrides: Partial<AutoDownloadOptions> = {}) {
    const videoFetcher = vi.fn<(url: string) => Promise<FetchOutcome>>(async () => ({ ok: true }));
    const ackFetch = vi.fn(async () => okResponse());
    const delay = vi.fn(async () => {});
    const isOnline = vi.fn(() => true);
    const options: AutoDownloadOptions = {
        videoFetcher,
        ackFetch: ackFetch as unknown as AutoDownloadOptions["ackFetch"],
        delay,
        isOnline,
        ...overrides,
    };
    return { videoFetcher, ackFetch, delay, isOnline, options };
}

function makeDownloader(overrides: Partial<AutoDownloadOptions> = {}) {
    const deps = makeDeps(overrides);
    const downloader = new AdoptedTakeAutoDownloader(1, 5, deps.options);
    return { downloader, ...deps };
}

describe("AdoptedTakeAutoDownloader 対象選別", () => {
    it("採用 && ready && 未 DL && playback_url≠null && ack_token≠null のみ fetch+ACK する", async () => {
        const { downloader, videoFetcher, ackFetch } = makeDownloader();

        const result = await downloader.run(makeManual());

        expect(videoFetcher).toHaveBeenCalledTimes(1);
        expect(videoFetcher).toHaveBeenCalledWith("https://s3.example.test/take-11.mp4?sig=1");
        expect(ackFetch).toHaveBeenCalledTimes(1);
        expect(ackFetch).toHaveBeenCalledWith(
            "/app/projects/1/manuals/5/cuts/101/takes/11/downloaded",
            "POST",
            { ack_token: "ack-token-11" },
        );
        expect(result).toEqual({ changed: true, hasPendingAck: false });
    });

    it("非採用・DL 済み・非 ready・ack_token=null は対象外 (fetch も ACK もされない)", async () => {
        const cut = makeCut({
            adopted_take_id: 11,
            takes: [
                makeTake({ id: 11, downloaded: true }), // 採用だが DL 済み
                makeTake({ id: 12 }), // 非採用
                makeTake({ id: 13, status: "processing" }), // 非採用かつ非 ready
            ],
        });
        const nonReadyAdopted = makeCut({
            id: 102,
            adopted_take_id: 21,
            takes: [makeTake({ id: 21, status: "processing" })],
        });
        const nullToken = makeCut({
            id: 103,
            adopted_take_id: 31,
            takes: [makeTake({ id: 31, download_ack_token: null })],
        });
        const nullPlayback = makeCut({
            id: 104,
            adopted_take_id: 41,
            takes: [makeTake({ id: 41, playback_url: null })],
        });
        const { downloader, videoFetcher, ackFetch } = makeDownloader();

        const result = await downloader.run(makeManual([cut, nonReadyAdopted, nullToken, nullPlayback]));

        expect(videoFetcher).not.toHaveBeenCalled();
        expect(ackFetch).not.toHaveBeenCalled();
        expect(result).toEqual({ changed: false, hasPendingAck: false });
    });
});

describe("AdoptedTakeAutoDownloader fetch/ACK 条件", () => {
    it("fetch 失敗 (http 403) では ACK を送らず changed=false", async () => {
        const { downloader, ackFetch } = makeDownloader({
            videoFetcher: vi.fn<(url: string) => Promise<FetchOutcome>>(async () => ({
                ok: false,
                reason: "http",
                status: 403,
            })),
        });

        const result = await downloader.run(makeManual());

        expect(ackFetch).not.toHaveBeenCalled();
        expect(result).toEqual({ changed: false, hasPendingAck: false });
    });

    it("fetch 失敗 (network) でも ACK を送らない", async () => {
        const { downloader, ackFetch } = makeDownloader({
            videoFetcher: vi.fn<(url: string) => Promise<FetchOutcome>>(async () => ({
                ok: false,
                reason: "network",
            })),
        });

        const result = await downloader.run(makeManual());

        expect(ackFetch).not.toHaveBeenCalled();
        expect(result.changed).toBe(false);
    });
});

describe("AdoptedTakeAutoDownloader オフライン", () => {
    it("isOnline=false は videoFetcher も ackFetch も呼ばず changed=false", async () => {
        const { downloader, videoFetcher, ackFetch } = makeDownloader({ isOnline: () => false });

        const result = await downloader.run(makeManual());

        expect(videoFetcher).not.toHaveBeenCalled();
        expect(ackFetch).not.toHaveBeenCalled();
        expect(result).toEqual({ changed: false, hasPendingAck: false });
    });
});

describe("AdoptedTakeAutoDownloader 有界リトライ", () => {
    it("fetch 連続失敗は総 1 + maxRetries 回で打ち切る (既定 3 回)", async () => {
        const videoFetcher = vi.fn<(url: string) => Promise<FetchOutcome>>(async () => ({
            ok: false,
            reason: "network",
        }));
        const { downloader, delay } = makeDownloader({ videoFetcher });

        const result = await downloader.run(makeManual());

        expect(videoFetcher).toHaveBeenCalledTimes(3);
        expect(delay).toHaveBeenCalledTimes(2); // 試行間の待機は 2 回
        expect(result.changed).toBe(false);
    });

    it("maxRetries=0 は総 1 回で打ち切る", async () => {
        const videoFetcher = vi.fn<(url: string) => Promise<FetchOutcome>>(async () => ({
            ok: false,
            reason: "network",
        }));
        const { downloader } = makeDownloader({ videoFetcher, maxRetries: 0 });

        await downloader.run(makeManual());

        expect(videoFetcher).toHaveBeenCalledTimes(1);
    });

    it("ACK 連続失敗も総 1 + maxRetries 回で打ち切り、無限ループしない", async () => {
        const ackFetch = vi.fn(async () => new Response(null, { status: 500 }));
        const { downloader, delay } = makeDownloader({
            ackFetch: ackFetch as unknown as AutoDownloadOptions["ackFetch"],
        });

        const result = await downloader.run(makeManual());

        expect(ackFetch).toHaveBeenCalledTimes(3);
        expect(delay).toHaveBeenCalledTimes(2);
        expect(result).toEqual({ changed: false, hasPendingAck: true });
    });
});

describe("AdoptedTakeAutoDownloader 状態 2 分離", () => {
    it("fetch 成功済み take は 2 回目 run で再 fetch しない (fetchSucceeded)", async () => {
        const { downloader, videoFetcher, ackFetch } = makeDownloader();

        await downloader.run(makeManual());
        // reload されないケースを模し、同一 (未 DL) manual で再度 run しても再 fetch されない
        await downloader.run(makeManual());

        expect(videoFetcher).toHaveBeenCalledTimes(1);
        // 1 回目で ACK 成功済み → ackPending から除去済みなので再 ACK もしない
        expect(ackFetch).toHaveBeenCalledTimes(1);
    });

    it("fetch 成功後 ACK だけ失敗 → 2 回目 run は再 fetch せず ACK のみ再送する (ackPending)", async () => {
        const videoFetcher = vi.fn<(url: string) => Promise<FetchOutcome>>(async () => ({ ok: true }));
        let ackShouldFail = true;
        const ackFetch = vi.fn(async () =>
            ackShouldFail ? new Response(null, { status: 500 }) : okResponse(),
        );
        const { downloader } = makeDownloader({
            videoFetcher,
            ackFetch: ackFetch as unknown as AutoDownloadOptions["ackFetch"],
        });

        const first = await downloader.run(makeManual());
        expect(first).toEqual({ changed: false, hasPendingAck: true });
        expect(videoFetcher).toHaveBeenCalledTimes(1);
        const ackCallsAfterFirst = ackFetch.mock.calls.length; // 有界リトライ分

        ackShouldFail = false;
        const second = await downloader.run(makeManual());

        expect(videoFetcher).toHaveBeenCalledTimes(1); // 再 fetch しない
        expect(ackFetch.mock.calls.length).toBe(ackCallsAfterFirst + 1); // ACK のみ 1 回で成功
        expect(second).toEqual({ changed: true, hasPendingAck: false });
    });
});

describe("AdoptedTakeAutoDownloader 多重起動防止", () => {
    it("run 実行中に再度 run を呼んでも二重 fetch せず、再入は changed=false", async () => {
        let resolveFetch: (o: FetchOutcome) => void = () => {};
        const videoFetcher = vi.fn<(url: string) => Promise<FetchOutcome>>(
            () => new Promise<FetchOutcome>((resolve) => (resolveFetch = resolve)),
        );
        const { downloader, ackFetch } = makeDownloader({ videoFetcher });

        const firstPromise = downloader.run(makeManual());
        const reentrant = await downloader.run(makeManual()); // 実行中の再入

        expect(reentrant).toEqual({ changed: false, hasPendingAck: false });
        expect(videoFetcher).toHaveBeenCalledTimes(1);

        resolveFetch({ ok: true });
        const first = await firstPromise;
        expect(first.changed).toBe(true);
        expect(ackFetch).toHaveBeenCalledTimes(1);
    });
});

describe("AdoptedTakeAutoDownloader 墓石掃除", () => {
    it("2 回目 manual で対象外化した take id は状態から除去され、再採用時に誤って skip しない", async () => {
        const videoFetcher = vi.fn<(url: string) => Promise<FetchOutcome>>(async () => ({ ok: true }));
        // 1 回目: ACK は成功させ fetchSucceeded に残す
        const { downloader } = makeDownloader({ videoFetcher });

        await downloader.run(makeManual()); // take 11 を fetch 成功
        expect(videoFetcher).toHaveBeenCalledTimes(1);

        // 2 回目: take 11 が採用差し替え (別 take 採用) → 11 は対象外 = 墓石掃除
        const swapped = makeManual([
            makeCut({ adopted_take_id: 99, takes: [makeTake({ id: 99 })] }),
        ]);
        await downloader.run(swapped);
        expect(videoFetcher).toHaveBeenCalledTimes(2); // take 99 を新規 fetch

        // 3 回目: take 11 が再び採用・未 DL に戻る → 墓石掃除済みなので再 fetch される
        await downloader.run(makeManual());
        expect(videoFetcher).toHaveBeenCalledTimes(3);
    });
});

describe("AdoptedTakeAutoDownloader 戻り値", () => {
    it("複数採用テイクで 1 件 ACK 成功・1 件 ACK 失敗でも changed=true / hasPendingAck=true", async () => {
        const videoFetcher = vi.fn<(url: string) => Promise<FetchOutcome>>(async () => ({ ok: true }));
        const ackFetch = vi.fn(async (url: string) =>
            url.includes("/takes/11/") ? okResponse() : new Response(null, { status: 500 }),
        );
        const manual = makeManual([
            makeCut({ id: 101, adopted_take_id: 11, takes: [makeTake({ id: 11 })] }),
            makeCut({ id: 102, adopted_take_id: 12, takes: [makeTake({ id: 12 })] }),
        ]);
        const { downloader } = makeDownloader({
            videoFetcher,
            ackFetch: ackFetch as unknown as AutoDownloadOptions["ackFetch"],
        });

        const result = await downloader.run(manual);

        expect(result).toEqual({ changed: true, hasPendingAck: true });
    });
});

describe("AdoptedTakeAutoDownloader 既定 videoFetcher (fetch + 完読)", () => {
    const fetchMock = vi.fn();

    beforeEach(() => {
        vi.stubGlobal("fetch", fetchMock);
        fetchMock.mockReset();
    });

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    /** credentials:omit で fetch し、body を完読・ACK する既定経路を通す downloader */
    function realFetchDownloader(ackFetch = vi.fn(async () => okResponse())) {
        const downloader = new AdoptedTakeAutoDownloader(1, 5, {
            ackFetch: ackFetch as unknown as AutoDownloadOptions["ackFetch"],
            delay: async () => {},
            isOnline: () => true,
        });
        return { downloader, ackFetch };
    }

    it("body を最後まで drain して完読成功したら ACK する (credentials:omit)", async () => {
        fetchMock.mockResolvedValue(
            new Response(new Blob(["video-bytes"]), { status: 200 }),
        );
        const { downloader, ackFetch } = realFetchDownloader();

        const result = await downloader.run(makeManual());

        expect(fetchMock).toHaveBeenCalledWith(
            "https://s3.example.test/take-11.mp4?sig=1",
            { credentials: "omit" },
        );
        expect(ackFetch).toHaveBeenCalledTimes(1);
        expect(result.changed).toBe(true);
    });

    it("response.ok=false (404) は http 失敗として ACK しない", async () => {
        fetchMock.mockResolvedValue(new Response(null, { status: 404 }));
        const { downloader, ackFetch } = realFetchDownloader();

        const result = await downloader.run(makeManual());

        expect(ackFetch).not.toHaveBeenCalled();
        expect(result.changed).toBe(false);
    });

    it("読取中に error stream が投げると network 失敗として ACK しない", async () => {
        const body = new ReadableStream({
            start(controller) {
                controller.error(new Error("stream broke"));
            },
        });
        fetchMock.mockResolvedValue(new Response(body, { status: 200 }));
        const { downloader, ackFetch } = realFetchDownloader();

        const result = await downloader.run(makeManual());

        expect(ackFetch).not.toHaveBeenCalled();
        expect(result.changed).toBe(false);
    });

    it("Content-Encoding 無し + Content-Length 不一致は size_mismatch で ACK しない", async () => {
        fetchMock.mockResolvedValue(
            new Response(new Blob(["short"]), {
                status: 200,
                headers: { "Content-Length": "9999" },
            }),
        );
        const { downloader, ackFetch } = realFetchDownloader();

        const result = await downloader.run(makeManual());

        expect(ackFetch).not.toHaveBeenCalled();
        expect(result.changed).toBe(false);
    });

    it("Content-Encoding: gzip 付きは size 検査せず完読で ACK する", async () => {
        fetchMock.mockResolvedValue(
            new Response(new Blob(["short"]), {
                status: 200,
                headers: { "Content-Length": "9999", "Content-Encoding": "gzip" },
            }),
        );
        const { downloader, ackFetch } = realFetchDownloader();

        const result = await downloader.run(makeManual());

        expect(ackFetch).toHaveBeenCalledTimes(1);
        expect(result.changed).toBe(true);
    });

    it("Content-Length 非安全整数 (>MAX_SAFE_INTEGER) は size 検査をスキップし ACK する", async () => {
        // /^\d+$/ は通るが Number.isSafeInteger=false なので検査せず完読成功で判定する
        fetchMock.mockResolvedValue(
            new Response(new Blob(["short"]), {
                status: 200,
                headers: { "Content-Length": "99999999999999999999" },
            }),
        );
        const { downloader, ackFetch } = realFetchDownloader();

        const result = await downloader.run(makeManual());

        expect(ackFetch).toHaveBeenCalledTimes(1);
        expect(result.changed).toBe(true);
    });

    it("Content-Length 非数値は size 検査をスキップし完読で ACK する", async () => {
        fetchMock.mockResolvedValue(
            new Response(new Blob(["short"]), {
                status: 200,
                headers: { "Content-Length": "not-a-number" },
            }),
        );
        const { downloader, ackFetch } = realFetchDownloader();

        const result = await downloader.run(makeManual());

        expect(ackFetch).toHaveBeenCalledTimes(1);
        expect(result.changed).toBe(true);
    });

    it("Content-Length が実読取量に一致すれば完読成功で ACK する", async () => {
        const bytes = new Uint8Array([1, 2, 3, 4, 5]);
        fetchMock.mockResolvedValue(
            new Response(bytes, { status: 200, headers: { "Content-Length": "5" } }),
        );
        const { downloader, ackFetch } = realFetchDownloader();

        const result = await downloader.run(makeManual());

        expect(ackFetch).toHaveBeenCalledTimes(1);
        expect(result.changed).toBe(true);
    });

    it("body=null + 空応答 (Content-Length: 0) は arrayBuffer フォールバックで完読成功し ACK する", async () => {
        // body=null (stream 非提供環境相当) → arrayBuffer() フォールバックで received=0。
        // received===0 && Content-Length===0 を network 失敗と誤判定しない。
        fetchMock.mockResolvedValue(
            new Response(null, { status: 200, headers: { "Content-Length": "0" } }),
        );
        const { downloader, ackFetch } = realFetchDownloader();

        const result = await downloader.run(makeManual());

        expect(ackFetch).toHaveBeenCalledTimes(1);
        expect(result.changed).toBe(true);
    });

    it("fetch が AbortError を投げると aborted (= network 系失敗) として ACK しない", async () => {
        fetchMock.mockRejectedValue(new DOMException("aborted", "AbortError"));
        const { downloader, ackFetch } = realFetchDownloader();

        const result = await downloader.run(makeManual());

        expect(ackFetch).not.toHaveBeenCalled();
        expect(result.changed).toBe(false);
    });
});
