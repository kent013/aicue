import { vi } from "vitest";

/**
 * CameraRecorder を **本物のまま**録画状態へ駆動するための最小 stub 一式。
 *
 * 元は CameraRecorder.test.ts の中だけにあったが、撮影ページ (CaptureShow.test.ts) でも
 * 「録画中はカットを移動できない」というページ配線を実挙動で固定する必要が出たため、
 * ここへ移設して共有する。component を stub へ差し替える形は採らない —
 * 実際の onCaptureActiveChange 経路を通らないと配線ミスを検出できないため。
 *
 * **移設であって挙動の変更ではない**。CameraRecorder.test.ts の it ブロックは 1 行も
 * 書き換えていないので、緑のままであることが「移設だけで挙動を変えていない」証拠になる。
 */

/** 手動発火できる最小 MediaRecorder stub (start/stop → ondataavailable/onstop) */
export class FakeMediaRecorder {
    static supportedTypes: string[] = ["video/webm"];
    static isTypeSupported(type: string): boolean {
        return FakeMediaRecorder.supportedTypes.includes(type);
    }
    static shouldThrowOnConstruct = false;
    static shouldThrowOnStart = false;
    static shouldThrowOnPause = false;
    /** false のとき stop() は onstop を自動発火せず、テストが手動で駆動する (stopping 観測用) */
    static autoStop = true;
    /** false のとき pause()/resume() は onpause/onresume を自動発火せず、テストが手動で駆動する */
    static autoPauseResume = true;

    ondataavailable: ((event: { data: Blob }) => void) | null = null;
    onstop: (() => void) | null = null;
    onerror: (() => void) | null = null;
    onpause: (() => void) | null = null;
    onresume: (() => void) | null = null;
    stopCalls = 0;
    pauseCalls = 0;
    resumeCalls = 0;
    /** RecordingState 相当 (recoverPhaseFromRecorderState が参照する真実源) */
    state: "inactive" | "recording" | "paused" = "inactive";

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
        this.state = "recording";
        // no-op (テストは stop() で明示的に onstop を駆動する)
    }

    stop(): void {
        this.stopCalls += 1;
        this.state = "inactive";
        if (!FakeMediaRecorder.autoStop) return; // 手動駆動モード
        this.ondataavailable?.({ data: new Blob(["frame"], { type: this.options.mimeType }) });
        this.onstop?.();
    }

    pause(): void {
        if (FakeMediaRecorder.shouldThrowOnPause) {
            throw new DOMException("invalid state", "InvalidStateError");
        }
        this.pauseCalls += 1;
        this.state = "paused";
        if (FakeMediaRecorder.autoPauseResume) this.onpause?.();
    }

    resume(): void {
        this.resumeCalls += 1;
        this.state = "recording";
        if (FakeMediaRecorder.autoPauseResume) this.onresume?.();
    }

    /** 手動モードで onstop を駆動する (blob 生成 → onstop) */
    fireStop(): void {
        this.state = "inactive";
        this.ondataavailable?.({ data: new Blob(["frame"], { type: this.options.mimeType }) });
        this.onstop?.();
    }

    /** 手動モードで onpause/onresume を駆動する */
    firePause(): void {
        this.onpause?.();
    }
    fireResume(): void {
        this.onresume?.();
    }
}

/** 静的フラグを既定へ戻す (beforeEach 用。テストごとの持ち越しを断つ) */
export function resetFakeMediaRecorder(): void {
    FakeMediaRecorder.supportedTypes = ["video/webm"];
    FakeMediaRecorder.shouldThrowOnConstruct = false;
    FakeMediaRecorder.shouldThrowOnStart = false;
    FakeMediaRecorder.shouldThrowOnPause = false;
    FakeMediaRecorder.autoStop = true;
    FakeMediaRecorder.autoPauseResume = true;
}

/**
 * 構築されたインスタンスを呼び出し側へ渡す派生クラスを作る。
 * 捕捉先の変数はテストファイル側に置く (グローバルな可変状態をテスト間で共有しない)。
 */
export function createTrackingRecorderClass(
    onConstruct: (recorder: FakeMediaRecorder) => void,
): typeof FakeMediaRecorder {
    return class TrackingFakeMediaRecorder extends FakeMediaRecorder {
        constructor(stream: unknown, options: { mimeType: string }) {
            super(stream, options);
            onConstruct(this);
        }
    };
}

export interface FakeTrack {
    stop: ReturnType<typeof vi.fn>;
    onended: (() => void) | null;
    applyConstraints: ReturnType<typeof vi.fn>;
    getSettings: ReturnType<typeof vi.fn>;
}

/** getTracks()/getVideoTracks() が stop spy 付き track を返す fake stream (解放・flip 検証用) */
export function fakeStream(facing: "environment" | "user" = "environment"): {
    stream: MediaStream;
    stop: ReturnType<typeof vi.fn>;
    track: FakeTrack;
} {
    const stop = vi.fn();
    const track: FakeTrack = {
        stop,
        onended: null,
        // 既定は制約適用成功 + getSettings が要求 facingMode を返す (段階1 成功)
        applyConstraints: vi.fn().mockResolvedValue(undefined),
        getSettings: vi.fn(() => ({ facingMode: facing })),
    };
    const stream = {
        getTracks: () => [track],
        getVideoTracks: () => [track],
    } as unknown as MediaStream;

    return { stream, stop, track };
}
