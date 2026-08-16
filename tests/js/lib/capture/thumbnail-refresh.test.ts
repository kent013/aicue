import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { ThumbnailRefreshScheduler } from "@/lib/capture/thumbnail-refresh";
import type { CaptureManualDetail, CaptureTake } from "@/types/capture";

/*
 * サムネイル反映の有界な再取得スケジューラ。
 * - watch() していないテイクは追わない (過去分で無駄なポーリングをしない)
 * - 2s → 4s → 8s → 15s の 4 回で止まる (試行上限)
 * - 監視集合が空になったら止まる / pause / stop
 * - single-flight: reload が解決するまで次を始めない
 */

function makeTake(overrides: Partial<CaptureTake> = {}): CaptureTake {
    return {
        id: 10,
        client_take_id: "take-a",
        status: "ready",
        size_bytes: 1024,
        duration_ms: 1000,
        comment: null,
        captured_at: null,
        sort_order: 0,
        downloaded: false,
        has_thumbnail: false,
        playback_url: null,
        download_ack_token: null,
        ...overrides,
    };
}

function makeManual(takes: CaptureTake[]): CaptureManualDetail {
    return {
        id: 1,
        title: "手順書",
        status: "ready",
        cuts: [
            {
                id: 3,
                type: "step",
                parent_cut_id: null,
                scene: "準備",
                shot_type: "hiki",
                shooting_point: null,
                narration: "準備します",
                subtitle_primary: null,
                subtitle_secondary: "準備",
                adopted_take_id: null,
                adopted_ready_take_id: null,
                takes,
            },
        ],
    };
}

beforeEach(() => {
    vi.useFakeTimers();
});

afterEach(() => {
    vi.useRealTimers();
});

describe("ThumbnailRefreshScheduler", () => {
    it("watch() していなければ has_thumbnail=false のテイクがあっても再取得しない", async () => {
        const reload = vi.fn(async () => {});
        const scheduler = new ThumbnailRefreshScheduler(reload);

        scheduler.sync(makeManual([makeTake({ client_take_id: "old-take" })]));
        await vi.advanceTimersByTimeAsync(60_000);

        expect(reload).not.toHaveBeenCalled();
    });

    it("watch() 後は 2s → 4s → 8s → 15s の計 4 回で止まる (試行上限)", async () => {
        const reload = vi.fn(async () => {});
        const scheduler = new ThumbnailRefreshScheduler(reload);
        scheduler.watch("take-a");

        await vi.advanceTimersByTimeAsync(1_999);
        expect(reload).toHaveBeenCalledTimes(0);
        await vi.advanceTimersByTimeAsync(1);
        expect(reload).toHaveBeenCalledTimes(1);
        await vi.advanceTimersByTimeAsync(4_000);
        expect(reload).toHaveBeenCalledTimes(2);
        await vi.advanceTimersByTimeAsync(8_000);
        expect(reload).toHaveBeenCalledTimes(3);
        await vi.advanceTimersByTimeAsync(15_000);
        expect(reload).toHaveBeenCalledTimes(4);

        // 予算を使い切ったら以後は発火しない
        await vi.advanceTimersByTimeAsync(120_000);
        expect(reload).toHaveBeenCalledTimes(4);
    });

    it("サムネイルが付いたら監視から外れ、空になったら再取得を止める", async () => {
        const reload = vi.fn(async () => {});
        const scheduler = new ThumbnailRefreshScheduler(reload);
        scheduler.watch("take-a");

        await vi.advanceTimersByTimeAsync(2_000);
        expect(reload).toHaveBeenCalledTimes(1);

        scheduler.sync(makeManual([makeTake({ client_take_id: "take-a", has_thumbnail: true })]));
        await vi.advanceTimersByTimeAsync(120_000);

        expect(reload).toHaveBeenCalledTimes(1);
    });

    it("監視中のテイクが manual から消えた (削除された) 場合も監視から外れる", async () => {
        const reload = vi.fn(async () => {});
        const scheduler = new ThumbnailRefreshScheduler(reload);
        scheduler.watch("take-a");

        await vi.advanceTimersByTimeAsync(2_000);
        scheduler.sync(makeManual([]));
        await vi.advanceTimersByTimeAsync(120_000);

        expect(reload).toHaveBeenCalledTimes(1);
    });

    it("merge: 2 本目の watch() が 1 本目の監視を消さず、試行予算がリセットされる", async () => {
        const reload = vi.fn(async () => {});
        const scheduler = new ThumbnailRefreshScheduler(reload);
        scheduler.watch("take-a");

        // 3 回発火させる (残り 1 回)
        await vi.advanceTimersByTimeAsync(2_000 + 4_000 + 8_000);
        expect(reload).toHaveBeenCalledTimes(3);

        // 新しい ID で予算が戻る = 最後に追加された ID から数えて**ちょうど 4 回**
        // (旧予算で予約済みだった発火は watch が持ち越さない)
        scheduler.watch("take-b");
        await vi.advanceTimersByTimeAsync(2_000 + 4_000 + 8_000 + 15_000);
        expect(reload).toHaveBeenCalledTimes(7);
        await vi.advanceTimersByTimeAsync(120_000);
        expect(reload).toHaveBeenCalledTimes(7);

        // 1 本目も監視され続けている (sync で両方が生き残る)
        scheduler.sync(
            makeManual([
                makeTake({ id: 10, client_take_id: "take-a" }),
                makeTake({ id: 11, client_take_id: "take-b", has_thumbnail: true }),
            ]),
        );
        expect(reload).toHaveBeenCalledTimes(7); // 予算切れのため即時発火はしない
    });

    it("既に監視中の ID を再度 watch しても試行予算は戻らない", async () => {
        const reload = vi.fn(async () => {});
        const scheduler = new ThumbnailRefreshScheduler(reload);
        scheduler.watch("take-a");

        await vi.advanceTimersByTimeAsync(2_000 + 4_000 + 8_000 + 15_000);
        expect(reload).toHaveBeenCalledTimes(4);

        scheduler.watch("take-a"); // 早期 return (予算は戻らない)
        await vi.advanceTimersByTimeAsync(120_000);
        expect(reload).toHaveBeenCalledTimes(4);
    });

    it("single-flight: 前回の reload が解決するまで次を始めない", async () => {
        let resolveReload: (() => void) | null = null;
        const reload = vi.fn(
            () =>
                new Promise<void>((resolve) => {
                    resolveReload = resolve;
                }),
        );
        const scheduler = new ThumbnailRefreshScheduler(reload);
        scheduler.watch("take-a");

        await vi.advanceTimersByTimeAsync(2_000);
        expect(reload).toHaveBeenCalledTimes(1);

        // 解決するまでは次の試行が始まらない
        await vi.advanceTimersByTimeAsync(120_000);
        expect(reload).toHaveBeenCalledTimes(1);

        expect(resolveReload).not.toBeNull();
        (resolveReload as unknown as () => void)();
        await vi.advanceTimersByTimeAsync(4_000);
        expect(reload).toHaveBeenCalledTimes(2);
    });

    it("pause() 中は発火せず、resume() で残り試行だけ再開する", async () => {
        const reload = vi.fn(async () => {});
        const scheduler = new ThumbnailRefreshScheduler(reload);
        scheduler.watch("take-a");

        scheduler.pause();
        await vi.advanceTimersByTimeAsync(120_000);
        expect(reload).toHaveBeenCalledTimes(0);

        scheduler.resume();
        // 予算は 1 回消費済みなので残りは 3 回
        await vi.advanceTimersByTimeAsync(4_000 + 8_000 + 15_000);
        expect(reload).toHaveBeenCalledTimes(3);
        await vi.advanceTimersByTimeAsync(120_000);
        expect(reload).toHaveBeenCalledTimes(3);
    });

    it("stop() 後に到着した reload の完了は再スケジュールしない", async () => {
        let resolveReload: (() => void) | null = null;
        const reload = vi.fn(
            () =>
                new Promise<void>((resolve) => {
                    resolveReload = resolve;
                }),
        );
        const scheduler = new ThumbnailRefreshScheduler(reload);
        scheduler.watch("take-a");

        await vi.advanceTimersByTimeAsync(2_000);
        expect(reload).toHaveBeenCalledTimes(1);

        scheduler.stop();
        (resolveReload as unknown as () => void)();
        await vi.advanceTimersByTimeAsync(120_000);

        expect(reload).toHaveBeenCalledTimes(1);
        // stop 後の watch / sync も無効
        scheduler.watch("take-c");
        await vi.advanceTimersByTimeAsync(120_000);
        expect(reload).toHaveBeenCalledTimes(1);
    });

    it("reload が reject しても監視対象を消さず、残り試行へ進む", async () => {
        const reload = vi.fn(() => Promise.reject(new Error("network")));
        const scheduler = new ThumbnailRefreshScheduler(reload);
        scheduler.watch("take-a");

        await vi.advanceTimersByTimeAsync(2_000 + 4_000 + 8_000 + 15_000);
        expect(reload).toHaveBeenCalledTimes(4);
    });
});
