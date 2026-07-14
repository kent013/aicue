<script lang="ts">
    import { onDestroy } from "svelte";
    import { Captions, CaptionsOff, Circle, Square } from "@lucide/svelte";
    import Button from "@/components/atoms/Button.svelte";
    import SubtitleOverlay from "@/components/features/capture/SubtitleOverlay.svelte";
    import { classifyGetUserMediaError, preferredRecordingMimeType } from "@/lib/capture/camera";
    import type { CameraUnavailableReason } from "@/lib/capture/camera";
    import type { CaptureCut } from "@/types/capture";

    /**
     * MediaRecorder による録画 (概念設計 D9)。停止時に blob を親へ渡す。
     * 録画不能な恒久失敗 (権限拒否・デバイス無し・API 不適合) は onCameraUnavailable で
     * 親に通知し、親がファイル選択フォールバックへ切り替える (doc/10 §10.8-3、F-03)。
     * 一時的失敗 (デバイス使用中等) のみローカルにエラー表示し再試行可能のまま残す。
     *
     * 撮影 active の phase マシン (T050 / S4): idle / recording / stopping。
     * 外部へ公開する排他状態 active は **starting || resuming || phase !== "idle"**。
     * getUserMedia grant 待ちの 2 窓 (録画開始 = starting / preview 復帰 = resuming) も active に
     * 含めることで、取得中でも親の captureActive が true になり preview が開けない
     * (preview と MediaRecorder の同居・stream 二重取得を根本から防ぐ。Codex R2/R3-S4)。
     * これにより preview 解禁条件 (親: !captureActive) と camera 解放拒否条件が一致する。
     */
    interface Props {
        onCaptured: (blob: Blob, mimeType: string, durationMs: number) => void | Promise<void>;
        /** カメラが恒久的に使えないと判明したときの通知 (親がフォールバックへ切替) */
        onCameraUnavailable: (reason: CameraUnavailableReason) => void;
        /** 選択中カットの字幕 (撮影ガイド overlay 用。焼込ではない)。既定は空 (字幕なし) */
        subtitlePrimary?: CaptureCut["subtitle_primary"];
        subtitleSecondary?: CaptureCut["subtitle_secondary"];
        /** 撮影 active (starting || resuming || phase !== "idle") の変化通知。preview 排他制御に使う (T050) */
        onCaptureActiveChange?: (active: boolean) => void;
    }

    let {
        onCaptured,
        onCameraUnavailable,
        subtitlePrimary = null,
        subtitleSecondary = "",
        onCaptureActiveChange,
    }: Props = $props();

    type Phase = "idle" | "recording" | "stopping";

    // 字幕オーバーレイの表示トグル (doc/05 §5.2)。v1 中核価値が字幕のため既定 ON。
    let showSubtitles = $state(true);
    const subtitleToggleLabel = $derived(showSubtitles ? "字幕を非表示" : "字幕を表示");

    let video: HTMLVideoElement | null = $state(null);
    let stream: MediaStream | null = null;
    let recorder: MediaRecorder | null = null;
    let chunks: Blob[] = [];
    let startedAt = 0;
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

    // getUserMedia + video.srcObject 設定 (録画開始と preview 復帰で共用)。
    // 成功 = true。失敗時は既存の classify → onCameraUnavailable / transient error 表示を踏襲。
    async function acquirePreviewStream(): Promise<boolean> {
        try {
            stream ??= await navigator.mediaDevices.getUserMedia({
                video: { facingMode: "environment" },
                audio: true,
            });
        } catch (cause) {
            const classified = classifyGetUserMediaError(cause);
            if (classified.kind === "transient") {
                error =
                    "カメラを起動できませんでした。他のアプリがカメラを使用していないか確認し、もう一度お試しください。";
                return false;
            }
            onCameraUnavailable(classified.reason);
            return false;
        }
        if (video) {
            video.srcObject = stream;
            await video.play().catch(() => undefined);
        }
        return true;
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
            // 唯一の正常終了点 (idle への遷移)。onCaptured の reject/throw でも終了通知を保証する。
            recorder.onstop = async () => {
                try {
                    const blob = new Blob(chunks, { type: mimeType });
                    const durationMs = Date.now() - startedAt;
                    if (blob.size > 0) {
                        await onCaptured(blob, mimeType, durationMs);
                    }
                } catch {
                    // 既存のローカルエラー表示経路へ渡す (未処理 rejection にしない)
                    error = "撮影データの処理に失敗しました。もう一度お試しください。";
                } finally {
                    setPhase("idle");
                }
            };
            recorder.onerror = () => safeStop();
            stream.getTracks().forEach((track) => {
                track.onended = () => safeStop();
            });
            startedAt = Date.now();
            try {
                recorder.start();
            } catch {
                // start() の InvalidStateError 等 (UA 差異・状態競合)。構築成功後でも
                // 詰ませないため stream を解放してフォールバックへ倒す (§10.8-3)
                recorder = null;
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

    // 安全停止 (多重呼び出しガード)。recording 以外では no-op (stopping/idle で重複 stop しない)。
    function safeStop(): void {
        if (phase !== "recording") return;
        setPhase("stopping"); // active は true のまま維持 (idle 遷移で初めて false)
        if (recorder === null) {
            fatalStopCleanup(); // 不整合: stopping 固定を防ぐ
            return;
        }
        try {
            recorder.stop(); // → recorder.onstop へ
        } catch {
            fatalStopCleanup(); // 停止不能時: UI 復旧不能を防ぐ
        }
    }

    // stop() が投げた等の致命時: 資源解放 + idle へ (active=true 残置による復旧不能を防ぐ)
    function fatalStopCleanup(): void {
        setPhase("idle");
        releaseCamera();
        onCameraUnavailable("recorder_unsupported");
    }

    function releaseCamera(): void {
        stream?.getTracks().forEach((track) => track.stop());
        stream = null;
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

    onDestroy(releaseCamera);
</script>

<div class="flex flex-col gap-3">
    <div class="relative">
        <!-- svelte-ignore a11y_media_has_caption -->
        <video
            bind:this={video}
            autoplay
            playsinline
            muted
            class="aspect-video w-full rounded-md bg-surface object-cover"
            data-testid="camera-preview"
        ></video>
        <SubtitleOverlay
            primary={subtitlePrimary}
            secondary={subtitleSecondary}
            visible={showSubtitles}
        />
    </div>
    <div class="flex items-center justify-center gap-3">
        {#if phase === "idle"}
            <Button variant="primary" onclick={startRecording} testId="start-recording">
                <Circle class="size-4" aria-hidden="true" />
                録画開始
            </Button>
        {:else}
            <Button variant="danger" onclick={safeStop} testId="stop-recording">
                <Square class="size-4" aria-hidden="true" />
                録画停止
            </Button>
        {/if}
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
        <p class="text-center text-caption text-danger" role="alert">{error}</p>
    {/if}
</div>
