import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { cleanup, fireEvent, render, screen } from "@testing-library/svelte";
import CameraRecorder from "@/components/features/capture/CameraRecorder.svelte";

/*
 * CameraRecorder: 録画不能な恒久失敗 (権限拒否・デバイス無し・API 不適合) は
 * onCameraUnavailable(reason) で親へ委譲し、一時失敗のみローカルにエラー表示する (F-03)。
 * 成功パスは onCaptured(blob, mimeType, durationMs) の契約を保つ。
 */

/** 手動発火できる最小 MediaRecorder stub (start/stop → ondataavailable/onstop) */
class FakeMediaRecorder {
    static supportedTypes: string[] = ["video/webm"];
    static isTypeSupported(type: string): boolean {
        return FakeMediaRecorder.supportedTypes.includes(type);
    }
    static shouldThrowOnConstruct = false;
    static shouldThrowOnStart = false;

    ondataavailable: ((event: { data: Blob }) => void) | null = null;
    onstop: (() => void) | null = null;

    constructor(
        public stream: unknown,
        public options: { mimeType: string },
    ) {
        if (FakeMediaRecorder.shouldThrowOnConstruct) {
            throw new DOMException("unsupported", "NotSupportedError");
        }
    }

    start(): void {
        if (FakeMediaRecorder.shouldThrowOnStart) {
            throw new DOMException("invalid state", "InvalidStateError");
        }
        // no-op (テストは stop() で明示的に onstop を駆動する)
    }

    stop(): void {
        this.ondataavailable?.({ data: new Blob(["frame"], { type: this.options.mimeType }) });
        this.onstop?.();
    }
}

const getUserMediaMock = vi.fn<() => Promise<MediaStream>>();

/** getTracks() が stop spy 付き track を返す fake stream (解放検証用) */
function fakeStream(): { stream: MediaStream; stop: ReturnType<typeof vi.fn> } {
    const stop = vi.fn();
    const stream = { getTracks: () => [{ stop }] } as unknown as MediaStream;
    return { stream, stop };
}

beforeEach(() => {
    FakeMediaRecorder.supportedTypes = ["video/webm"];
    FakeMediaRecorder.shouldThrowOnConstruct = false;
    FakeMediaRecorder.shouldThrowOnStart = false;
    getUserMediaMock.mockReset();
    vi.stubGlobal("MediaRecorder", FakeMediaRecorder);
    vi.stubGlobal("navigator", {
        ...navigator,
        mediaDevices: { getUserMedia: getUserMediaMock },
    });
    // jsdom は HTMLMediaElement.play 未実装
    vi.spyOn(HTMLMediaElement.prototype, "play").mockResolvedValue(undefined);
});

afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
});

describe("CameraRecorder", () => {
    it("権限拒否 (NotAllowedError) は onCameraUnavailable('permission_denied') を呼びローカルエラーを出さない", async () => {
        getUserMediaMock.mockRejectedValue(new DOMException("denied", "NotAllowedError"));
        const onCaptured = vi.fn();
        const onCameraUnavailable = vi.fn();

        render(CameraRecorder, { props: { onCaptured, onCameraUnavailable } });
        await fireEvent.click(screen.getByTestId("start-recording"));

        await vi.waitFor(() => {
            expect(onCameraUnavailable).toHaveBeenCalledWith("permission_denied");
        });
        expect(screen.queryByRole("alert")).not.toBeInTheDocument();
        expect(onCaptured).not.toHaveBeenCalled();
    });

    it("デバイス無し (NotFoundError) は onCameraUnavailable('device_missing')", async () => {
        getUserMediaMock.mockRejectedValue(new DOMException("no cam", "NotFoundError"));
        const onCameraUnavailable = vi.fn();

        render(CameraRecorder, { props: { onCaptured: vi.fn(), onCameraUnavailable } });
        await fireEvent.click(screen.getByTestId("start-recording"));

        await vi.waitFor(() => {
            expect(onCameraUnavailable).toHaveBeenCalledWith("device_missing");
        });
    });

    it("一時失敗 (NotReadableError) は親へ委譲せず、再試行可能なエラー表示を残す", async () => {
        getUserMediaMock.mockRejectedValue(new DOMException("busy", "NotReadableError"));
        const onCameraUnavailable = vi.fn();

        render(CameraRecorder, { props: { onCaptured: vi.fn(), onCameraUnavailable } });
        await fireEvent.click(screen.getByTestId("start-recording"));

        await vi.waitFor(() => {
            expect(screen.getByRole("alert")).toHaveTextContent(
                "カメラを起動できませんでした。他のアプリがカメラを使用していないか確認し、もう一度お試しください。",
            );
        });
        expect(onCameraUnavailable).not.toHaveBeenCalled();
        // 再試行可能: 録画開始ボタンが残る
        expect(screen.getByTestId("start-recording")).toBeInTheDocument();
    });

    it("録画 MIME 非対応は onCameraUnavailable('mime_unsupported')", async () => {
        FakeMediaRecorder.supportedTypes = [];
        const onCameraUnavailable = vi.fn();

        render(CameraRecorder, { props: { onCaptured: vi.fn(), onCameraUnavailable } });
        await fireEvent.click(screen.getByTestId("start-recording"));

        await vi.waitFor(() => {
            expect(onCameraUnavailable).toHaveBeenCalledWith("mime_unsupported");
        });
        expect(getUserMediaMock).not.toHaveBeenCalled();
    });

    it("MediaRecorder 構築失敗は stream を解放し onCameraUnavailable('recorder_unsupported')", async () => {
        const { stream, stop } = fakeStream();
        getUserMediaMock.mockResolvedValue(stream);
        FakeMediaRecorder.shouldThrowOnConstruct = true;
        const onCameraUnavailable = vi.fn();

        render(CameraRecorder, { props: { onCaptured: vi.fn(), onCameraUnavailable } });
        await fireEvent.click(screen.getByTestId("start-recording"));

        await vi.waitFor(() => {
            expect(onCameraUnavailable).toHaveBeenCalledWith("recorder_unsupported");
        });
        // 取得済み stream の track が解放されている (他タブ等でカメラを掴んだままにしない)
        expect(stop).toHaveBeenCalledTimes(1);
    });

    it("recorder.start() 例外も stream を解放しフォールバックへ倒す (詰みを作らない)", async () => {
        const { stream, stop } = fakeStream();
        getUserMediaMock.mockResolvedValue(stream);
        FakeMediaRecorder.shouldThrowOnStart = true;
        const onCameraUnavailable = vi.fn();

        render(CameraRecorder, { props: { onCaptured: vi.fn(), onCameraUnavailable } });
        await fireEvent.click(screen.getByTestId("start-recording"));

        await vi.waitFor(() => {
            expect(onCameraUnavailable).toHaveBeenCalledWith("recorder_unsupported");
        });
        expect(stop).toHaveBeenCalledTimes(1);
        // 録画状態には遷移しない (録画開始ボタンのまま)
        expect(screen.getByTestId("start-recording")).toBeInTheDocument();
    });

    it("成功パス: 録画開始→停止で onCaptured(blob, 'video/webm', durationMs) を呼ぶ", async () => {
        getUserMediaMock.mockResolvedValue(fakeStream().stream);
        const onCaptured = vi.fn();
        const onCameraUnavailable = vi.fn();

        render(CameraRecorder, { props: { onCaptured, onCameraUnavailable } });
        await fireEvent.click(screen.getByTestId("start-recording"));

        // 録画中に切り替わり、停止ボタンが出る
        await vi.waitFor(() => {
            expect(screen.getByTestId("stop-recording")).toBeInTheDocument();
        });
        await fireEvent.click(screen.getByTestId("stop-recording"));

        await vi.waitFor(() => {
            expect(onCaptured).toHaveBeenCalledTimes(1);
        });
        const [blob, mimeType, durationMs] = onCaptured.mock.calls[0];
        expect(blob).toBeInstanceOf(Blob);
        expect((blob as Blob).size).toBeGreaterThan(0);
        expect(mimeType).toBe("video/webm");
        expect(typeof durationMs).toBe("number");
        expect(onCameraUnavailable).not.toHaveBeenCalled();
    });

    it("開始処理中の 2 連打は再入せず getUserMedia を 1 回だけ呼ぶ", async () => {
        let rejectStart: ((reason: unknown) => void) | undefined;
        getUserMediaMock.mockImplementation(
            () =>
                new Promise<MediaStream>((_resolve, reject) => {
                    rejectStart = reject;
                }),
        );
        const onCameraUnavailable = vi.fn();

        render(CameraRecorder, { props: { onCaptured: vi.fn(), onCameraUnavailable } });
        // getUserMedia が pending の間に 2 連打
        await fireEvent.click(screen.getByTestId("start-recording"));
        await fireEvent.click(screen.getByTestId("start-recording"));

        expect(getUserMediaMock).toHaveBeenCalledTimes(1);

        // 未解決 Promise をテスト間に残さないよう reject して処理を完了させる
        rejectStart?.(new DOMException("denied", "NotAllowedError"));
        await vi.waitFor(() => {
            expect(onCameraUnavailable).toHaveBeenCalledWith("permission_denied");
        });
    });

    // --- T047: 字幕オーバーレイのトグル配線 (追記。既存ケースは無改変) ---

    it("字幕 props 既定 (省略) でも既存フローは無変更で render できる (後方互換)", () => {
        render(CameraRecorder, {
            props: { onCaptured: vi.fn(), onCameraUnavailable: vi.fn() },
        });
        // 既定 ON でも字幕なしなら overlay は描画されない
        expect(screen.queryByTestId("subtitle-overlay")).not.toBeInTheDocument();
        // トグルは常に存在する
        expect(screen.getByTestId("toggle-subtitles")).toBeInTheDocument();
    });

    it("字幕 props を渡すと既定 showSubtitles=true で overlay が表示される", () => {
        render(CameraRecorder, {
            props: {
                onCaptured: vi.fn(),
                onCameraUnavailable: vi.fn(),
                subtitlePrimary: "名称A",
                subtitleSecondary: "メイン字幕",
            },
        });
        expect(screen.getByTestId("subtitle-overlay")).toBeInTheDocument();
        const toggle = screen.getByTestId("toggle-subtitles");
        expect(toggle).toHaveAttribute("aria-pressed", "true");
        expect(toggle).toHaveAttribute("aria-label", "字幕を非表示");
    });

    it("トグルクリックで overlay 非表示 + aria-pressed=false / aria-label='字幕を表示'", async () => {
        render(CameraRecorder, {
            props: {
                onCaptured: vi.fn(),
                onCameraUnavailable: vi.fn(),
                subtitlePrimary: "名称A",
                subtitleSecondary: "メイン字幕",
            },
        });
        await fireEvent.click(screen.getByTestId("toggle-subtitles"));

        expect(screen.queryByTestId("subtitle-overlay")).not.toBeInTheDocument();
        const toggle = screen.getByTestId("toggle-subtitles");
        expect(toggle).toHaveAttribute("aria-pressed", "false");
        expect(toggle).toHaveAttribute("aria-label", "字幕を表示");
    });

    it("再クリックで overlay 再表示 + aria-pressed=true / aria-label='字幕を非表示'", async () => {
        render(CameraRecorder, {
            props: {
                onCaptured: vi.fn(),
                onCameraUnavailable: vi.fn(),
                subtitlePrimary: "名称A",
                subtitleSecondary: "メイン字幕",
            },
        });
        const toggle = screen.getByTestId("toggle-subtitles");
        await fireEvent.click(toggle);
        await fireEvent.click(toggle);

        expect(screen.getByTestId("subtitle-overlay")).toBeInTheDocument();
        expect(toggle).toHaveAttribute("aria-pressed", "true");
        expect(toggle).toHaveAttribute("aria-label", "字幕を非表示");
    });

    it("字幕が空でもトグルは disabled にならず、クリックで状態遷移する (禁止事項 8)", async () => {
        render(CameraRecorder, {
            props: { onCaptured: vi.fn(), onCameraUnavailable: vi.fn() },
        });
        const toggle = screen.getByTestId("toggle-subtitles");
        // disabled 属性を持たない
        expect(toggle).not.toBeDisabled();
        expect(toggle).toHaveAttribute("aria-pressed", "true");
        // 実クリックで状態遷移する (押下不能=詰みにしない)
        await fireEvent.click(toggle);
        expect(toggle).toHaveAttribute("aria-pressed", "false");
    });
});
