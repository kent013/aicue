<script lang="ts">
    import { onDestroy } from "svelte";
    import {
        Camera,
        Captions,
        CaptionsOff,
        Circle,
        Grid3x3,
        Pause,
        Play,
        Square,
        SwitchCamera,
        Timer,
    } from "@lucide/svelte";
    import Button from "@/components/atoms/Button.svelte";
    import GridOverlay from "@/components/features/capture/GridOverlay.svelte";
    import ShootingGuideOverlay from "@/components/features/capture/ShootingGuideOverlay.svelte";
    import SubtitleOverlay from "@/components/molecules/SubtitleOverlay.svelte";
    import {
        classifyGetUserMediaError,
        formatElapsed,
        nextFacingMode,
        preferredRecordingMimeType,
        supportsPauseResume,
        videoConstraints,
    } from "@/lib/capture/camera";
    import type {
        CameraErrorClassification,
        CameraUnavailableReason,
        FacingMode,
    } from "@/lib/capture/camera";
    import { encodeStillJpeg, STILL_CONTENT_TYPE } from "@/lib/capture/still-encode";
    import type { LayoutMode } from "@/lib/capture/landscape-capture";
    import type { CaptureCut } from "@/types/capture";

    /**
     * MediaRecorder による録画 (概念設計 D9)。停止時に blob を親へ渡す。
     * 録画不能な恒久失敗 (権限拒否・デバイス無し・API 不適合) は onCameraUnavailable で
     * 親に通知し、親がファイル選択フォールバックへ切り替える (doc/10 §10.8-3、F-03)。
     * 一時的失敗 (デバイス使用中等) のみローカルにエラー表示し再試行可能のまま残す。
     *
     * 撮影 active の phase マシン (T050 / S4): idle / recording / paused / stopping。
     * 外部へ公開する排他状態 active は **starting || resuming || phase !== "idle"**。
     * getUserMedia grant 待ちの 2 窓 (録画開始 = starting / preview 復帰 = resuming) も active に
     * 含めることで、取得中でも親の captureActive が true になり preview が開けない
     * (preview と MediaRecorder の同居・stream 二重取得を根本から防ぐ。Codex R2/R3-S4)。
     * これにより preview 解禁条件 (親: !captureActive) と camera 解放拒否条件が一致する。
     *
     * T056 (capture-ux-enrichment): 録画タイマー / 一時停止・再開 / グリッド / カメラ反転を追加。
     * paused も非 idle のため active=true を維持し preview 排他を保つ。
     */
    interface Props {
        /** 撮影データの引き渡し。静止画には尺が無いので durationMs は null になる */
        onCaptured: (blob: Blob, mimeType: string, durationMs: number | null) => void | Promise<void>;
        /** カメラが恒久的に使えないと判明したときの通知 (親がフォールバックへ切替) */
        onCameraUnavailable: (reason: CameraUnavailableReason) => void;
        /** 選択中カットの字幕 (撮影ガイド overlay 用。焼込ではない)。既定は空 (字幕なし) */
        subtitlePrimary?: CaptureCut["subtitle_primary"];
        subtitleSecondary?: CaptureCut["subtitle_secondary"];
        /** 撮影 active (starting || resuming || phase !== "idle") の変化通知。preview 排他制御に使う (T050) */
        onCaptureActiveChange?: (active: boolean) => void;
        /**
         * 表示レイアウト (T186: 横持ち全画面)。**既定は従来どおり inline** で、
         * 縦持ちの見た目は 1px も変わらない。
         * 本 props は class の切替にしか使わず、**phase マシン・stream 管理には一切触れない**。
         */
        layout?: LayoutMode;
        /**
         * 撮影ガイド (撮影方法)。上流の CaptureCut["shooting_point"] の nullable 契約に合わせる。
         * 非 null かつ非空へ絞る判定は本 component の内側 1 か所で行う。
         */
        shootingPoint?: CaptureCut["shooting_point"];
        /**
         * 撮影モード (静止画カット対応)。**既定は従来どおり "video"**。
         * "still" では MediaRecorder を一切使わず、phase は idle のまま
         * 「録画開始」の位置にシャッターを出す。
         * 本 props は表示分岐と shootStill の 1 経路だけを足し、
         * phase マシン・stream 管理・flip・grid・字幕 overlay・カメラ喪失時の
         * フォールバック委譲には**一切触れない** (layout props と同じ作法)。
         */
        mode?: "video" | "still";
    }

    let {
        onCaptured,
        onCameraUnavailable,
        subtitlePrimary = null,
        subtitleSecondary = "",
        onCaptureActiveChange,
        layout = "inline",
        shootingPoint = null,
        mode = "video",
    }: Props = $props();

    const isStillMode = $derived(mode === "still");

    // --- 全画面レイアウト (表示のみ。phase マシンとは独立) ---
    const isFullscreen = $derived(layout === "fullscreen");
    /**
     * trim は**空判定にのみ**使い、描画には元文字列をそのまま渡す
     * (SubtitleOverlay と同じ作法。内容を書き換えない)。
     */
    const hasShootingGuide = $derived((shootingPoint ?? "").trim() !== "");
    const showShootingGuide = $derived(isFullscreen && hasShootingGuide);

    // 単一ソース union (R2 反映: paused を追加)
    type Phase = "idle" | "recording" | "paused" | "stopping";

    // 字幕オーバーレイの表示トグル (doc/05 §5.2)。v1 中核価値が字幕のため既定 ON。
    let showSubtitles = $state(true);
    const subtitleToggleLabel = $derived(showSubtitles ? "字幕を非表示" : "字幕を表示");

    // グリッド overlay の表示トグル。字幕と違い構図補助は任意のため既定 OFF (doc/05 §5.2)。
    let showGrid = $state(false);
    const gridToggleLabel = $derived(showGrid ? "グリッドを非表示" : "グリッドを表示");

    let video: HTMLVideoElement | null = $state(null);
    let stream: MediaStream | null = null;
    let recorder: MediaRecorder | null = null;
    let chunks: Blob[] = [];
    let phase = $state<Phase>("idle");
    let error = $state<string | null>(null);
    /** 開始処理中の再入ガード (getUserMedia 待ち中の多重クリック防止。UI disabled は使わない) */
    let starting = false;
    /** 直近に外部通知した active 値 (starting || resuming || phase !== "idle" の変化検出用) */
    let lastActive = false;
    /** preview 解放前に live だったか (復帰要否) */
    let wasActiveBeforePreview = false;
    /** resumeAfterPreview の再入ガード (多重 close/open で getUserMedia を二重発火させない) */
    let resuming = false;
    let resumePromise: Promise<void> | null = null;

    // --- 一時停止/再開 (S4)。イベント基準・in-flight ガード・タイムアウト復旧 ---
    // pause/resume 要求の in-flight ガード。**boolean ではなく操作種別を保持** する (R3-2 反映):
    // stale な onpause が進行中の resume の pending を誤って解除する事故を防ぐため、
    // 一致する操作のイベント/タイムアウトのみが pending を解除する。
    type PauseResumeOperation = "pause" | "resume";
    let pendingOperation: PauseResumeOperation | null = null;
    // pause/resume イベント未到達検出のタイムアウト handle (R3-S)
    let pauseResumeTimeout: ReturnType<typeof setTimeout> | null = null;
    // 能力検査は module 初期化時に一度評価 (ボタンの出し分けに使う)
    const canPauseResume = supportsPauseResume();

    // --- 録画タイマー (S3)。performance.now() 累積・pause 対応 ---
    let elapsedMs = $state(0);
    let accumulatedMs = 0; // pause で確定した累積 (performance.now ベース)
    let segmentStart = 0; // 現 recording 区間の開始 (performance.now())
    let timerHandle: ReturnType<typeof setInterval> | null = null;
    const elapsedLabel = $derived(formatElapsed(elapsedMs));
    const showTimer = $derived(phase === "recording" || phase === "paused");

    // --- カメラ反転 (S6)。idle 時のみ・段階的縮退 ---
    let facingMode = $state<FacingMode>("environment");
    let flipping = false; // flip 再入ガード

    // 公開 active (starting || resuming || phase !== "idle") の変化時のみ 1 回通知する。
    // starting / resuming / phase を変えた箇所は必ず本関数を呼ぶ (通知の一元管理)。
    function syncActive(): void {
        const active = starting || resuming || phase !== "idle";
        if (active !== lastActive) {
            lastActive = active;
            onCaptureActiveChange?.(active);
        }
    }

    // phase 遷移は単一 setter を通す。active 通知は syncActive に一元化する。
    function setPhase(next: Phase): void {
        phase = next;
        syncActive();
    }

    // --- 録画タイマー関数群 (S3) ---
    // recording 区間の計測開始 (start / resume で呼ぶ)
    function startTimer(): void {
        if (timerHandle !== null) return; // 二重起動防止
        segmentStart = performance.now();
        timerHandle = setInterval(() => {
            elapsedMs = accumulatedMs + (performance.now() - segmentStart);
        }, 200);
    }
    // 計測停止 + 累積確定 (pause / stop / idle / destroy で呼ぶ)
    function stopTimer(): void {
        if (timerHandle !== null) {
            accumulatedMs += performance.now() - segmentStart;
            clearInterval(timerHandle);
            timerHandle = null;
        }
        elapsedMs = accumulatedMs;
    }
    function resetTimer(): void {
        if (timerHandle !== null) {
            clearInterval(timerHandle);
            timerHandle = null;
        }
        accumulatedMs = 0;
        segmentStart = 0;
        elapsedMs = 0;
    }
    // 実録画尺 (durationMs 用)。累積 + 現区間の経過 (recording 中に stop されたケース)。
    // R1-S: Math.max(0, …) で明示クランプ (防御的。performance.now 単調増加のため通常は非負)。
    function recordedDurationMs(): number {
        const raw =
            timerHandle !== null ? accumulatedMs + (performance.now() - segmentStart) : accumulatedMs;
        return Math.max(0, raw);
    }

    // 副作用なしの取得 (classify 結果を返すだけ。onCameraUnavailable/error を呼ばない)。
    // 呼び出し前に stream=null であること (reacquire 前は releaseCamera 済み)。stream ??= のため
    // 既存 stream があれば再取得しない = flip の reacquire では releaseCamera() 後に呼ぶ。
    async function acquireStream(): Promise<CameraErrorClassification | { kind: "ok" }> {
        try {
            stream ??= await navigator.mediaDevices.getUserMedia({
                // 呼出時点の facingMode を渡す (reacquireWithFacing が代入した直後の値を読む)。
                // キャッシュ禁止 — キャッシュすると flip 後も旧カメラで取得してしまう。
                video: videoConstraints(facingMode),
                audio: true,
            });
        } catch (cause) {
            return classifyGetUserMediaError(cause);
        }
        if (video) {
            video.srcObject = stream;
            await video.play().catch(() => undefined);
        }
        return { kind: "ok" };
    }

    // classify 失敗に既存の副作用ポリシーを適用 (transient→error / unavailable→F-03 委譲)。
    function applyAcquireFailure(result: CameraErrorClassification): void {
        if (result.kind === "transient") {
            error =
                "カメラを起動できませんでした。他のアプリがカメラを使用していないか確認し、もう一度お試しください。";
            return;
        }
        onCameraUnavailable(result.reason);
    }

    // getUserMedia + video.srcObject 設定 (録画開始と preview 復帰で共用)。
    // 成功 = true。失敗時は既存の classify → onCameraUnavailable / transient error 表示を踏襲。
    // 既存契約を維持するラッパ (startRecording / resumeAfterPreview は無改変で呼べる)。
    async function acquirePreviewStream(): Promise<boolean> {
        const result = await acquireStream();
        if (result.kind === "ok") return true;
        applyAcquireFailure(result);
        return false;
    }

    async function startRecording(): Promise<void> {
        // 再入防止 (アーリーリターン。規約: disabled 禁止)。preview 復帰の取得中 (resuming) も拒否
        // し getUserMedia 二重取得を防ぐ。
        if (starting || resuming || phase !== "idle") return;
        starting = true;
        syncActive(); // 開始押下時点で active=true (grant 窓でも preview を開けない)
        try {
            error = null;
            const mimeType = preferredRecordingMimeType();
            if (mimeType === null) {
                // 恒久系: ローカル表示はせず親へ委譲 (責務の二重化回避)
                onCameraUnavailable("mime_unsupported");
                return;
            }
            const acquired = await acquirePreviewStream();
            if (!acquired) return;
            if (stream === null) return; // 型絞り込み (acquired=true なら実質非 null)
            chunks = [];
            try {
                recorder = new MediaRecorder(stream, { mimeType });
            } catch {
                // NotSupportedError 等: 取得済み stream を解放してからフォールバックへ
                releaseCamera();
                onCameraUnavailable("recorder_unsupported");
                return;
            }
            recorder.ondataavailable = (event) => {
                if (event.data.size > 0) chunks.push(event.data);
            };
            // 一時停止/再開はイベント基準で phase を確定する (S4)。要求ハンドラは phase を
            // 同期で動かさず、MediaRecorder の onpause/onresume 到達で初めて遷移する。
            // R2-Critical: タイマー操作は phase 条件の内側で行う。stale な onpause/onresume が
            // stopping/idle で到着しても timer を触らない (durationMs 汚染・interval リークを防ぐ)。
            recorder.onpause = () => {
                if (pendingOperation === "pause") clearPauseResumePending(); // 一致操作のみ解除 (R3-2)
                if (phase !== "recording") return; // stale なら timer/phase を触らない
                stopTimer(); // 経過計測を止める (累積は保持)
                setPhase("paused");
            };
            recorder.onresume = () => {
                if (pendingOperation === "resume") clearPauseResumePending(); // 一致操作のみ解除 (R3-2)
                if (phase !== "paused") return; // stale なら timer/phase を触らない
                startTimer(); // 経過計測を再開 (累積へ加算)
                setPhase("recording");
            };
            // 唯一の正常終了点 (idle への遷移)。onCaptured の reject/throw でも終了通知を保証する。
            recorder.onstop = async () => {
                // 実録画尺 (pause 区間を除外)。resetTimer 前に確定する。
                const durationMs = recordedDurationMs();
                try {
                    const blob = new Blob(chunks, { type: mimeType });
                    if (blob.size > 0) {
                        await onCaptured(blob, mimeType, durationMs);
                    }
                } catch {
                    // 既存のローカルエラー表示経路へ渡す (未処理 rejection にしない)
                    error = "撮影データの処理に失敗しました。もう一度お試しください。";
                } finally {
                    resetTimer(); // idle 到達時の interval リーク防止
                    clearPauseResumePending();
                    setPhase("idle");
                }
            };
            recorder.onerror = () => safeStop();
            stream.getTracks().forEach((track) => {
                track.onended = () => safeStop();
            });
            resetTimer();
            startTimer(); // 従来の startedAt = Date.now() を置換 (R1: 実録画尺へ是正)
            try {
                recorder.start();
            } catch {
                // start() の InvalidStateError 等 (UA 差異・状態競合)。構築成功後でも
                // 詰ませないため stream を解放してフォールバックへ倒す (§10.8-3)
                recorder = null;
                resetTimer();
                releaseCamera();
                onCameraUnavailable("recorder_unsupported");
                return;
            }
            setPhase("recording");
        } finally {
            starting = false;
            // 開始成功時: phase=recording のため active は true 維持 (重複通知しない)。
            // 開始失敗/恒久失敗時: phase=idle のため active=false へ戻す。
            syncActive();
        }
    }

    /**
     * 静止画の撮影。live preview の現在フレームを 1 枚取り出して親へ渡す。
     * ImageCapture API は iOS Safari が未対応で、撮影 PWA の主戦場が iOS Safari のため
     * canvas 経路を採る。
     *
     * 再入ガードと active 通知は **startRecording() と完全に同じ形**にする (独自フラグを増やさない)。
     * `starting` は「grant 待ちの窓でも preview を開けない」ための公開 active の構成要素であり、
     * `acquirePreviewStream()` 側には starting の再入ガードが無いので、先に立てても stream 取得は
     * 塞がらない (startRecording が `starting = true` → `syncActive()` → `acquirePreviewStream()` の順)。
     */
    async function shootStill(): Promise<void> {
        if (starting || resuming || phase !== "idle") return;
        starting = true;
        syncActive(); // 押下時点で active=true (取得中に preview を開かせない)
        try {
            error = null;
            if (stream === null && !(await acquirePreviewStream())) return;
            if (video === null) return;
            // encodeStillJpeg は reject しない契約 (失敗は null)
            const blob = await encodeStillJpeg(video, video.videoWidth, video.videoHeight);
            if (blob === null || blob.size === 0) {
                error = "写真を取得できませんでした。もう一度お試しください。";
                return;
            }
            await onCaptured(blob, STILL_CONTENT_TYPE, null);
        } catch {
            // onCaptured (アップロード) 側の失敗を未処理 rejection にしない (録画経路の onstop と同じ)
            error = "撮影データの処理に失敗しました。もう一度お試しください。";
        } finally {
            starting = false;
            syncActive();
        }
    }

    // 一時停止要求 (recording のみ)。pending 中 (種別を問わず) は多重押下ガードで拒否。
    function requestPause(): void {
        if (phase !== "recording" || pendingOperation !== null || recorder === null) return;
        if (!canPauseResume) return; // 未対応端末はボタン非表示のため通常到達しない (ボタン出し分けと同一判定)
        pendingOperation = "pause";
        armPauseResumeTimeout("pause");
        try {
            recorder.pause();
        } catch {
            // InvalidStateError 等: pending を解除し recorder.state から phase を復旧
            clearPauseResumePending();
            recoverPhaseFromRecorderState();
        }
    }

    // 録画再開要求 (paused のみ)
    function requestResume(): void {
        if (phase !== "paused" || pendingOperation !== null || recorder === null) return;
        pendingOperation = "resume";
        armPauseResumeTimeout("resume");
        try {
            recorder.resume();
        } catch {
            clearPauseResumePending();
            recoverPhaseFromRecorderState();
        }
    }

    // イベント未到達の保険 (R3-S: 解除条件 = onpause/onresume/onerror/onstop/タイムアウト)。
    // 操作種別を渡し、**古いタイムアウトが後続操作の pending/handle を奪わない** よう二重防御する:
    //  (1) handle 自己同定: 遅延実行された古い callback は `pauseResumeTimeout !== handle` で
    //      早期 return し、新しい timeout の handle を null 化しない (R4-2 の handle 喪失防止)。
    //  (2) 操作種別一致: 自分の操作がまだ pending のときだけ pending を解除して復旧する。
    function armPauseResumeTimeout(op: PauseResumeOperation): void {
        clearPauseResumeTimeout();
        const handle: ReturnType<typeof setTimeout> = setTimeout(() => {
            if (pauseResumeTimeout !== handle) return; // 古い callback は新 handle を触らない (R4-2)
            pauseResumeTimeout = null;
            if (pendingOperation !== op) return; // 自分の操作が解決/交代済みなら何もしない
            pendingOperation = null;
            recoverPhaseFromRecorderState(); // 遅延イベントが来ても phase は state 同期のみ
        }, 2000);
        pauseResumeTimeout = handle;
    }
    function clearPauseResumeTimeout(): void {
        if (pauseResumeTimeout !== null) {
            clearTimeout(pauseResumeTimeout);
            pauseResumeTimeout = null;
        }
    }
    function clearPauseResumePending(): void {
        pendingOperation = null;
        clearPauseResumeTimeout();
    }

    // recorder.state を真実源に UI phase を同期 (stopping 中は onstop に委ねるため触らない)
    function recoverPhaseFromRecorderState(): void {
        if (recorder === null || phase === "stopping") return;
        const state = recorder.state; // "inactive" | "recording" | "paused"
        if (state === "inactive") {
            // R1-W フェイルセーフ: recording/paused 中に recorder が inactive (onstop 永久未達 UA
            // バグ等の異常系) を検出したら、復帰不能を防ぐため fatalStopCleanup で idle 復帰 + 資源解放。
            fatalStopCleanup();
            return;
        }
        const nextPhase: Phase = state === "paused" ? "paused" : "recording";
        if (state === "paused") stopTimer();
        else startTimer();
        if (phase !== nextPhase) setPhase(nextPhase);
    }

    // 安全停止 (多重呼び出しガード)。recording/paused 以外では no-op (stopping/idle で重複 stop しない)。
    // paused からも停止できる必要がある (recorder.onerror は paused 中にも発火し得るため。R1-Critical)。
    function safeStop(): void {
        if (phase !== "recording" && phase !== "paused") return;
        clearPauseResumePending();
        setPhase("stopping"); // active は true のまま維持 (idle 遷移で初めて false)
        if (recorder === null) {
            fatalStopCleanup(); // 不整合: stopping 固定を防ぐ
            return;
        }
        try {
            recorder.stop(); // paused 状態でも stop() は onstop を発火し blob 確定
        } catch {
            fatalStopCleanup(); // 停止不能時: UI 復旧不能を防ぐ
        }
    }

    // stop() が投げた等の致命時: 資源解放 + idle へ (active=true 残置による復旧不能を防ぐ)
    function fatalStopCleanup(): void {
        resetTimer();
        clearPauseResumePending();
        setPhase("idle");
        releaseCamera();
        onCameraUnavailable("recorder_unsupported");
    }

    function releaseCamera(): void {
        stream?.getTracks().forEach((track) => track.stop());
        stream = null;
    }

    // --- カメラ反転 (S6)。idle 時のみ機能。段階的縮退 (R2/R3) ---
    async function flipCamera(): Promise<void> {
        // idle 以外・取得中・flip 中は no-op (録画中の stream 再取得を避ける)
        if (starting || resuming || flipping || phase !== "idle") return;
        const target = nextFacingMode(facingMode);

        // live stream 未保持 (録画前): state 更新のみ、次回 getUserMedia に反映
        if (stream === null) {
            facingMode = target;
            return;
        }
        flipping = true;
        try {
            error = null;
            const track = stream.getVideoTracks()[0] ?? null;
            // 段階1: applyConstraints({exact}) + getSettings 検証 (同一 stream 維持)
            if (track !== null && (await tryApplyFacing(track, target))) {
                facingMode = target;
                return;
            }
            // 段階2〜4: 再取得 (旧停止 → 新取得 → 失敗時旧復旧 → 完全喪失で classify)
            await reacquireWithFacing(target);
        } finally {
            flipping = false;
        }
    }

    // 段階1: exact 制約を適用し getSettings で実切替を検証 (R3: resolve≠実切替)
    async function tryApplyFacing(track: MediaStreamTrack, target: FacingMode): Promise<boolean> {
        try {
            await track.applyConstraints({ facingMode: { exact: target } });
        } catch {
            return false;
        }
        // R1-W: getSettings().facingMode が undefined の端末は「未検証扱い」で false を返し
        // 再取得経路 (段階2〜) へ倒す (安全側。誤って同一 stream 維持で切替失敗を隠さない)。
        const applied = track.getSettings().facingMode;
        return applied === target;
    }

    // 段階2〜4: 旧 stream 停止 → 新取得 → 失敗時旧復旧 → 完全喪失で初めて副作用 (R3 + R1-critical)
    // 副作用なしの acquireStream() を使い、onCameraUnavailable(F-03)/error の発火を段階4 まで遅延する。
    async function reacquireWithFacing(target: FacingMode): Promise<void> {
        const previous = facingMode;
        releaseCamera(); // 旧 stream 停止 (二重取得不可端末に対応。stream=null になる)
        facingMode = target;
        const forward = await acquireStream(); // 段階2: 副作用なし取得
        if (forward.kind === "ok") return;
        // 段階3: 旧 facingMode で再取得して復旧 (flip 断念・元カメラ継続。onCameraUnavailable は呼ばない)
        facingMode = previous;
        const back = await acquireStream();
        if (back.kind === "ok") {
            error = "カメラを切り替えられませんでした。";
            return;
        }
        // 段階4: 両カメラ喪失。段階3 の classify(back) に対してのみ副作用を適用
        // (transient→error 表示 / unavailable→onCameraUnavailable(F-03) 委譲)。
        applyAcquireFailure(back);
    }

    // preview を開く間に呼ばれる。録画中/停止処理中は no-op (録画データを守る = 暗黙終了しない)。
    // 取得中 (starting: 録画開始 / resuming: preview 復帰) も拒否し、取得中の stream を横から
    // 解放しない (Codex R1/R3-S4)。
    export function releaseForPreview(): void {
        if (starting || resuming || phase !== "idle") return; // recording/stopping/取得中で解放拒否
        wasActiveBeforePreview = stream !== null; // 復帰要否を記録
        releaseCamera();
    }

    // preview close 後に呼ばれる。解放前に live だった時のみ再取得。多重 close/open を再入防止。
    export function resumeAfterPreview(): Promise<void> {
        if (resuming) return resumePromise ?? Promise.resolve(); // in-flight 共有
        if (!wasActiveBeforePreview || starting || phase !== "idle") return Promise.resolve();
        resuming = true;
        syncActive(); // 復帰取得中も active=true (grant 窓で preview 再オープン・録画開始を抑止)
        // 取得成功後にのみ wasActiveBeforePreview を false 化 (失敗時は true のまま=再試行可能)
        resumePromise = acquirePreviewStream()
            .then((ok) => {
                if (ok) wasActiveBeforePreview = false;
            })
            .finally(() => {
                resuming = false;
                resumePromise = null;
                syncActive(); // 取得完了で active=false へ戻す (phase は idle のまま)
            });
        return resumePromise;
    }

    onDestroy(() => {
        resetTimer();
        clearPauseResumeTimeout();
        releaseCamera();
    });
</script>

<!--
  全画面と inline の切替は **class の差し替えだけ**で行う。
  {#if} で描き分けると <video> が unmount され、録画中の MediaStream / MediaRecorder が
  破棄されて録ったデータが消えるため。

  **操作行は全画面でも映像に重ねない**。映像を flex-1 で伸ばし、操作行は不透明な面の上に
  そのまま置く。半透明の帯を敷いてアイコンのコントラストを別途担保する道を採らないのは、
  「仕組みが機能していない段階で値 (色) を弄るな」という原則と、
  contrast-invariant の検査対象を無駄に増やさないためである。
-->
<div class={isFullscreen ? "flex h-full min-h-0 flex-col gap-2" : "flex flex-col gap-3"}>
    <div class={isFullscreen ? "relative min-h-0 flex-1 overflow-hidden rounded-md" : "relative"}>
        <!-- svelte-ignore a11y_media_has_caption -->
        <video
            bind:this={video}
            autoplay
            playsinline
            muted
            class={isFullscreen
                ? "size-full bg-surface object-cover"
                : "aspect-video w-full rounded-md bg-surface object-cover"}
            data-testid="camera-preview"
        ></video>
        <!-- overlay の z 順 (DOM 順で 映像 < grid < 撮影ガイド < 字幕帯) -->
        <GridOverlay visible={showGrid} />
        {#if showShootingGuide}
            <!-- 描画には元文字列を渡す (trim は showShootingGuide の空判定にだけ使う) -->
            <ShootingGuideOverlay text={shootingPoint ?? ""} />
        {/if}
        <SubtitleOverlay
            primary={subtitlePrimary}
            secondary={subtitleSecondary}
            visible={showSubtitles}
        />
        {#if showTimer}
            <!-- 録画タイマー (overlay 右上)。recording/paused 時のみ -->
            <div
                class="pointer-events-none absolute top-2 right-2 flex items-center gap-1 rounded-sm bg-text/70 px-2 py-1 text-caption text-surface"
                data-testid="record-timer"
            >
                <Timer class="size-3.5" aria-hidden="true" />
                <span aria-live="off">{elapsedLabel}</span>
                {#if phase === "paused"}<span class="sr-only">一時停止中</span>{/if}
            </div>
        {/if}
    </div>
    <div
        class={isFullscreen
            ? "flex shrink-0 items-center justify-center gap-3"
            : "flex items-center justify-center gap-3"}
    >
        {#if phase === "idle"}
            {#if isStillMode}
                <Button variant="primary" onclick={shootStill} testId="shoot-still">
                    <Camera class="size-4" aria-hidden="true" />
                    写真を撮る
                </Button>
            {:else}
                <Button variant="primary" onclick={startRecording} testId="start-recording">
                    <Circle class="size-4" aria-hidden="true" />
                    録画開始
                </Button>
            {/if}
            <!-- カメラ反転 (idle のみ表示 = 文脈非該当時は非表示。disabled ではない) -->
            <button
                type="button"
                class="flex items-center rounded-sm p-2 text-text-secondary transition-colors duration-150 hover:text-text focus-visible:ring-3 focus-visible:ring-primary/35 focus-visible:outline-none"
                aria-label="カメラを切り替え"
                onclick={flipCamera}
                data-testid="flip-camera"
            >
                <SwitchCamera class="size-5" aria-hidden="true" />
            </button>
        {:else}
            <!-- recording / paused / stopping 共通: 停止ボタンは常時可視 (stopping では no-op) -->
            {#if phase === "recording" && canPauseResume}
                <!-- 一時停止 (supportsPauseResume() 時のみ表示。未対応は非表示で start/stop のみ) -->
                <button
                    type="button"
                    class="flex items-center rounded-sm p-2 text-text-secondary transition-colors duration-150 hover:text-text focus-visible:ring-3 focus-visible:ring-primary/35 focus-visible:outline-none"
                    aria-label="一時停止"
                    onclick={requestPause}
                    data-testid="pause-recording"
                >
                    <Pause class="size-5" aria-hidden="true" />
                </button>
            {:else if phase === "paused"}
                <!-- 録画再開 -->
                <button
                    type="button"
                    class="flex items-center rounded-sm p-2 text-text-secondary transition-colors duration-150 hover:text-text focus-visible:ring-3 focus-visible:ring-primary/35 focus-visible:outline-none"
                    aria-label="録画を再開"
                    onclick={requestResume}
                    data-testid="resume-recording"
                >
                    <Play class="size-5" aria-hidden="true" />
                </button>
            {/if}
            <Button variant="danger" onclick={safeStop} testId="stop-recording">
                <Square class="size-4" aria-hidden="true" />
                録画停止
            </Button>
        {/if}
        <!-- グリッドトグル (常時表示・字幕トグルと並置。字幕が空でも disabled にしない = 禁止事項 8) -->
        <button
            type="button"
            class="flex items-center rounded-sm p-2 text-text-secondary transition-colors duration-150 hover:text-text focus-visible:ring-3 focus-visible:ring-primary/35 focus-visible:outline-none"
            aria-label={gridToggleLabel}
            aria-pressed={showGrid}
            onclick={() => (showGrid = !showGrid)}
            data-testid="toggle-grid"
        >
            <Grid3x3 class="size-5" aria-hidden="true" />
        </button>
        <!-- 字幕トグル (録画ボタン右)。二値の pressed 状態は raw button + aria-pressed で表現
             (先例: molecules/PasswordInput.svelte)。字幕が空でも disabled にしない (禁止事項 8) -->
        <button
            type="button"
            class="flex items-center rounded-sm p-2 text-text-secondary transition-colors duration-150 hover:text-text focus-visible:ring-3 focus-visible:ring-primary/35 focus-visible:outline-none"
            aria-label={subtitleToggleLabel}
            aria-pressed={showSubtitles}
            onclick={() => (showSubtitles = !showSubtitles)}
            data-testid="toggle-subtitles"
        >
            {#if showSubtitles}
                <Captions class="size-5" aria-hidden="true" />
            {:else}
                <CaptionsOff class="size-5" aria-hidden="true" />
            {/if}
        </button>
    </div>
    {#if error}
        <!-- 全画面でも重ねないので class は共通のまま (経験値の位置合わせが不要になった) -->
        <p class="shrink-0 text-center text-caption text-danger" role="alert">{error}</p>
    {/if}
</div>
