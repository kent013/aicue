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
    /** false のとき stop() は onstop を自動発火せず、テストが手動で駆動する (stopping 観測用) */
    static autoStop = true;

    ondataavailable: ((event: { data: Blob }) => void) | null = null;
    onstop: (() => void) | null = null;
    onerror: (() => void) | null = null;
    stopCalls = 0;

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
        this.stopCalls += 1;
        if (!FakeMediaRecorder.autoStop) return; // 手動駆動モード
        this.ondataavailable?.({ data: new Blob(["frame"], { type: this.options.mimeType }) });
        this.onstop?.();
    }

    /** 手動モードで onstop を駆動する (blob 生成 → onstop) */
    fireStop(): void {
        this.ondataavailable?.({ data: new Blob(["frame"], { type: this.options.mimeType }) });
        this.onstop?.();
    }
}

/** 直近に構築された FakeMediaRecorder を捕捉する (onerror/onstop 手動駆動用) */
let lastRecorder: FakeMediaRecorder | null = null;
class TrackingFakeMediaRecorder extends FakeMediaRecorder {
    constructor(stream: unknown, options: { mimeType: string }) {
        super(stream, options);
        lastRecorder = this;
    }
}

const getUserMediaMock = vi.fn<() => Promise<MediaStream>>();

/** getTracks() が stop spy 付き track を返す fake stream (解放検証用) */
function fakeStream(): {
    stream: MediaStream;
    stop: ReturnType<typeof vi.fn>;
    track: { stop: ReturnType<typeof vi.fn>; onended: (() => void) | null };
} {
    const stop = vi.fn();
    const track: { stop: ReturnType<typeof vi.fn>; onended: (() => void) | null } = {
        stop,
        onended: null,
    };
    const stream = { getTracks: () => [track] } as unknown as MediaStream;
    return { stream, stop, track };
}

beforeEach(() => {
    FakeMediaRecorder.supportedTypes = ["video/webm"];
    FakeMediaRecorder.shouldThrowOnConstruct = false;
    FakeMediaRecorder.shouldThrowOnStart = false;
    FakeMediaRecorder.autoStop = true;
    lastRecorder = null;
    getUserMediaMock.mockReset();
    vi.stubGlobal("MediaRecorder", TrackingFakeMediaRecorder);
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

    // ---- T050 / S4: 撮影 active phase マシン + preview 解放/復帰 ----

    it("onCaptureActiveChange は start で true / idle 到達で false を通知する", async () => {
        getUserMediaMock.mockResolvedValue(fakeStream().stream);
        const onCaptureActiveChange = vi.fn();

        render(CameraRecorder, {
            props: { onCaptured: vi.fn(), onCameraUnavailable: vi.fn(), onCaptureActiveChange },
        });
        await fireEvent.click(screen.getByTestId("start-recording"));
        await vi.waitFor(() => expect(onCaptureActiveChange).toHaveBeenCalledWith(true));
        // 録画確立 (stop ボタン出現) を待ってから停止する
        await vi.waitFor(() => expect(screen.getByTestId("stop-recording")).toBeInTheDocument());

        await fireEvent.click(screen.getByTestId("stop-recording"));
        await vi.waitFor(() => expect(onCaptureActiveChange).toHaveBeenCalledWith(false));
    });

    it("recording→stopping では false を発火せず、idle 到達で初めて false (stopping 中は active 維持)", async () => {
        FakeMediaRecorder.autoStop = false; // stop() で onstop を自動発火させず stopping を観測する
        getUserMediaMock.mockResolvedValue(fakeStream().stream);
        const onCaptureActiveChange = vi.fn();

        render(CameraRecorder, {
            props: { onCaptured: vi.fn(), onCameraUnavailable: vi.fn(), onCaptureActiveChange },
        });
        await fireEvent.click(screen.getByTestId("start-recording"));
        await vi.waitFor(() => expect(onCaptureActiveChange).toHaveBeenLastCalledWith(true));
        // 録画確立 (stop ボタン出現) を待つ
        await vi.waitFor(() => expect(screen.getByTestId("stop-recording")).toBeInTheDocument());

        // 停止要求 → stopping (まだ idle でない = false を出さない)
        await fireEvent.click(screen.getByTestId("stop-recording"));
        expect(lastRecorder?.stopCalls).toBe(1);
        expect(onCaptureActiveChange).not.toHaveBeenCalledWith(false);

        // onstop 到達で初めて idle → false
        lastRecorder?.fireStop();
        await vi.waitFor(() => expect(onCaptureActiveChange).toHaveBeenLastCalledWith(false));
    });

    it("track.onended は safeStop→recorder.stop() を呼び、onstop(idle) でのみ false を出す", async () => {
        FakeMediaRecorder.autoStop = false;
        const { stream, track } = fakeStream();
        getUserMediaMock.mockResolvedValue(stream);
        const onCaptureActiveChange = vi.fn();

        render(CameraRecorder, {
            props: { onCaptured: vi.fn(), onCameraUnavailable: vi.fn(), onCaptureActiveChange },
        });
        await fireEvent.click(screen.getByTestId("start-recording"));
        await vi.waitFor(() => expect(track.onended).not.toBeNull());

        track.onended?.(); // デバイス側で track 終了 → safeStop
        expect(lastRecorder?.stopCalls).toBe(1);
        expect(onCaptureActiveChange).not.toHaveBeenCalledWith(false); // stopping 中はまだ

        lastRecorder?.fireStop();
        await vi.waitFor(() => expect(onCaptureActiveChange).toHaveBeenLastCalledWith(false));
    });

    it("onstop の onCaptured が reject しても idle に戻り、未処理 rejection にせずローカルエラー表示する", async () => {
        getUserMediaMock.mockResolvedValue(fakeStream().stream);
        const onCaptured = vi.fn().mockRejectedValue(new Error("upload failed"));
        const onCaptureActiveChange = vi.fn();

        render(CameraRecorder, {
            props: { onCaptured, onCameraUnavailable: vi.fn(), onCaptureActiveChange },
        });
        await fireEvent.click(screen.getByTestId("start-recording"));
        await vi.waitFor(() => expect(screen.getByTestId("stop-recording")).toBeInTheDocument());
        await fireEvent.click(screen.getByTestId("stop-recording"));

        // idle へ戻り録画開始ボタンが復帰 (撮影状態が解除される)
        await vi.waitFor(() => expect(screen.getByTestId("start-recording")).toBeInTheDocument());
        expect(onCaptureActiveChange).toHaveBeenLastCalledWith(false);
        expect(screen.getByRole("alert")).toHaveTextContent("撮影データの処理に失敗しました");
    });

    it("safeStop 多重呼び出しで recorder.stop() が重複しない (phase ガード)", async () => {
        FakeMediaRecorder.autoStop = false;
        getUserMediaMock.mockResolvedValue(fakeStream().stream);

        render(CameraRecorder, { props: { onCaptured: vi.fn(), onCameraUnavailable: vi.fn() } });
        await fireEvent.click(screen.getByTestId("start-recording"));
        await vi.waitFor(() => expect(screen.getByTestId("stop-recording")).toBeInTheDocument());

        await fireEvent.click(screen.getByTestId("stop-recording"));
        await fireEvent.click(screen.getByTestId("stop-recording"));
        expect(lastRecorder?.stopCalls).toBe(1); // stopping 中の 2 度目は no-op
    });

    it("releaseForPreview: 待機中 (idle かつ stream あり) は stream を解放し、resumeAfterPreview で再取得", async () => {
        const first = fakeStream();
        const second = fakeStream();
        getUserMediaMock.mockResolvedValueOnce(first.stream).mockResolvedValueOnce(second.stream);

        const { component } = render(CameraRecorder, {
            props: { onCaptured: vi.fn(), onCameraUnavailable: vi.fn() },
        });
        // 待機 stream を得るため一度録画開始→停止で idle かつ stream 保持状態を作る
        await fireEvent.click(screen.getByTestId("start-recording"));
        await vi.waitFor(() => expect(screen.getByTestId("stop-recording")).toBeInTheDocument());
        await fireEvent.click(screen.getByTestId("stop-recording"));
        await vi.waitFor(() => expect(screen.getByTestId("start-recording")).toBeInTheDocument());

        // preview を開く: idle かつ stream 保持 → 解放される
        (component as unknown as { releaseForPreview: () => void }).releaseForPreview();
        expect(first.stop).toHaveBeenCalled();

        // preview close: 解放前 live だったので再取得する
        await (component as unknown as { resumeAfterPreview: () => Promise<void> }).resumeAfterPreview();
        expect(getUserMediaMock).toHaveBeenCalledTimes(2);
    });

    it("開始処理中 (getUserMedia grant 待ち) は active=true を通知し、preview 用解放を拒否する", async () => {
        let resolveStart: ((stream: MediaStream) => void) | undefined;
        const pending = fakeStream();
        getUserMediaMock.mockImplementation(
            () =>
                new Promise<MediaStream>((resolve) => {
                    resolveStart = resolve;
                }),
        );
        const onCaptureActiveChange = vi.fn();

        const { component } = render(CameraRecorder, {
            props: { onCaptured: vi.fn(), onCameraUnavailable: vi.fn(), onCaptureActiveChange },
        });
        // getUserMedia が pending の間 (starting=true, phase=idle)
        await fireEvent.click(screen.getByTestId("start-recording"));
        // active=true が即通知される → 親の captureActive=true → TakeStrip は preview を開かない
        await vi.waitFor(() => expect(onCaptureActiveChange).toHaveBeenCalledWith(true));

        // 万一 releaseForPreview が呼ばれても取得中 stream を横取り解放しない (二重防御)
        (component as unknown as { releaseForPreview: () => void }).releaseForPreview();
        resolveStart?.(pending.stream);
        await vi.waitFor(() => expect(screen.getByTestId("stop-recording")).toBeInTheDocument());
        expect(pending.stop).not.toHaveBeenCalled();
        // recording への遷移で true を重複通知しない (starting→recording は同一 active)
        expect(onCaptureActiveChange.mock.calls.filter(([a]) => a === true)).toHaveLength(1);
    });

    it("開始が失敗 (getUserMedia reject) すると active が true→false に戻る", async () => {
        getUserMediaMock.mockRejectedValue(new DOMException("denied", "NotAllowedError"));
        const onCaptureActiveChange = vi.fn();

        render(CameraRecorder, {
            props: { onCaptured: vi.fn(), onCameraUnavailable: vi.fn(), onCaptureActiveChange },
        });
        await fireEvent.click(screen.getByTestId("start-recording"));

        await vi.waitFor(() => expect(onCaptureActiveChange).toHaveBeenLastCalledWith(false));
        expect(onCaptureActiveChange).toHaveBeenNthCalledWith(1, true); // 開始押下で一旦 active
    });

    it("releaseForPreview: 録画中は no-op (録画データを暗黙終了しない)", async () => {
        FakeMediaRecorder.autoStop = false;
        const { stream, stop } = fakeStream();
        getUserMediaMock.mockResolvedValue(stream);

        const { component } = render(CameraRecorder, {
            props: { onCaptured: vi.fn(), onCameraUnavailable: vi.fn() },
        });
        await fireEvent.click(screen.getByTestId("start-recording"));
        await vi.waitFor(() => expect(screen.getByTestId("stop-recording")).toBeInTheDocument());

        (component as unknown as { releaseForPreview: () => void }).releaseForPreview();
        expect(stop).not.toHaveBeenCalled(); // 解放しない
        expect(lastRecorder?.stopCalls).toBe(0); // 録画終了処理も呼ばない
    });

    it("resume 取得中 (getUserMedia grant 待ち) は active=true で、preview 解放も録画開始も抑止する", async () => {
        const recordStream = fakeStream();
        let resolveResume: ((stream: MediaStream) => void) | undefined;
        const resumeStream = fakeStream();
        getUserMediaMock
            .mockResolvedValueOnce(recordStream.stream) // 初回録画
            .mockImplementationOnce(
                () =>
                    new Promise<MediaStream>((resolve) => {
                        resolveResume = resolve;
                    }),
            );
        const onCaptureActiveChange = vi.fn();

        const { component } = render(CameraRecorder, {
            props: { onCaptured: vi.fn(), onCameraUnavailable: vi.fn(), onCaptureActiveChange },
        });
        const ref = component as unknown as {
            releaseForPreview: () => void;
            resumeAfterPreview: () => Promise<void>;
        };
        // 録画→停止で idle かつ stream 保持状態を作る
        await fireEvent.click(screen.getByTestId("start-recording"));
        await vi.waitFor(() => expect(screen.getByTestId("stop-recording")).toBeInTheDocument());
        await fireEvent.click(screen.getByTestId("stop-recording"));
        await vi.waitFor(() => expect(screen.getByTestId("start-recording")).toBeInTheDocument());

        // preview open → release (idle, stream 保持 → wasActiveBeforePreview=true)
        ref.releaseForPreview();
        // preview close → resume (getUserMedia は pending)
        const resumePromise = ref.resumeAfterPreview();
        await vi.waitFor(() => expect(onCaptureActiveChange).toHaveBeenLastCalledWith(true));
        expect(getUserMediaMock).toHaveBeenCalledTimes(2); // 録画 + resume の 2 回

        // resume 取得中: 再度 releaseForPreview は no-op / 録画開始も getUserMedia を増やさない
        ref.releaseForPreview();
        await fireEvent.click(screen.getByTestId("start-recording"));
        expect(getUserMediaMock).toHaveBeenCalledTimes(2); // 二重取得しない

        // resume 完了で active=false へ戻る
        resolveResume?.(resumeStream.stream);
        await resumePromise;
        await vi.waitFor(() => expect(onCaptureActiveChange).toHaveBeenLastCalledWith(false));
    });

    it("resume が失敗しても active=false へ戻る (再試行可能)", async () => {
        const recordStream = fakeStream();
        getUserMediaMock
            .mockResolvedValueOnce(recordStream.stream)
            .mockRejectedValueOnce(new DOMException("busy", "NotReadableError"));
        const onCaptureActiveChange = vi.fn();

        const { component } = render(CameraRecorder, {
            props: { onCaptured: vi.fn(), onCameraUnavailable: vi.fn(), onCaptureActiveChange },
        });
        const ref = component as unknown as {
            releaseForPreview: () => void;
            resumeAfterPreview: () => Promise<void>;
        };
        await fireEvent.click(screen.getByTestId("start-recording"));
        await vi.waitFor(() => expect(screen.getByTestId("stop-recording")).toBeInTheDocument());
        await fireEvent.click(screen.getByTestId("stop-recording"));
        await vi.waitFor(() => expect(screen.getByTestId("start-recording")).toBeInTheDocument());

        onCaptureActiveChange.mockClear();
        ref.releaseForPreview();
        await ref.resumeAfterPreview(); // 取得失敗 (transient)

        // active は true→false を経て最終 false (取得失敗でも撮影状態を残さない)
        expect(onCaptureActiveChange).toHaveBeenCalledWith(true);
        expect(onCaptureActiveChange).toHaveBeenLastCalledWith(false);
    });

    it("resumeAfterPreview: 多重呼び出しで getUserMedia が二重発火しない / 失敗後は再試行できる", async () => {
        const first = fakeStream();
        getUserMediaMock.mockResolvedValueOnce(first.stream); // 初回録画で live に

        const { component } = render(CameraRecorder, {
            props: { onCaptured: vi.fn(), onCameraUnavailable: vi.fn() },
        });
        await fireEvent.click(screen.getByTestId("start-recording"));
        await vi.waitFor(() => expect(screen.getByTestId("stop-recording")).toBeInTheDocument());
        await fireEvent.click(screen.getByTestId("stop-recording"));
        await vi.waitFor(() => expect(screen.getByTestId("start-recording")).toBeInTheDocument());

        const ref = component as unknown as { releaseForPreview: () => void; resumeAfterPreview: () => Promise<void> };
        ref.releaseForPreview();
        expect(getUserMediaMock).toHaveBeenCalledTimes(1); // 録画開始の 1 回のみ

        // 復帰取得が一時失敗 → wasActiveBeforePreview を保持 (再試行可能)
        getUserMediaMock.mockRejectedValueOnce(new DOMException("busy", "NotReadableError"));
        // 多重 close/open を模して 2 連続呼び出し (再入ガードで getUserMedia は 1 回)
        await Promise.all([ref.resumeAfterPreview(), ref.resumeAfterPreview()]);
        expect(getUserMediaMock).toHaveBeenCalledTimes(2);

        // 再試行が成功する (wasActiveBeforePreview が false 化していない)
        getUserMediaMock.mockResolvedValueOnce(fakeStream().stream);
        await ref.resumeAfterPreview();
        expect(getUserMediaMock).toHaveBeenCalledTimes(3);
    });
});
