Round 4 の指摘（S4 の外部排他状態に stopping を含める / onstop の未処理 rejection / S2 の cleanup 要素固定）に対応しました。再レビューをお願いします。各施策の判定と全体判定を明示してください。

## 対応サマリー

- **S4-Critical（外部通知に stopping を含める）**: 採用。プロップを `onCaptureActiveChange(active)` に改名し、`active = phase !== "idle"`（recording と stopping の両方）。`setPhase` は active の変化時のみ発火。recording→stopping では false を発火せず、**idle 遷移で初めて false**。TakeStrip 側は `captureActive`（旧 recordingInProgress）で解禁判定するため、CameraRecorder の解放拒否（phase!==idle）と一致し stopping 中に同居しない。
- **S4-Warning（onstop 内 onCaptured reject の未処理 rejection）**: 採用。`onstop` を `try { await onCaptured } catch { 既存ローカルエラー表示へ } finally { setPhase("idle") }`。
- **S2-Warning（cleanup が可変 video 参照）**: 採用。effect 実行時の要素を `const target = video` に固定し `teardownVideo(target)`。`video: HTMLVideoElement | undefined`。
- **S5**: 追加テスト — (a) recording→stopping で false 非発火・idle で false、(b) stopping 中 preview 非オープン、(c) take 差し替え時 旧 video のみ teardown・新 src 保持、(d) onCaptured reject が既存エラー処理へ・未処理 rejection なし。

## 修正後コード（抜粋）

### S4 CameraRecorder
```ts
type Phase = "idle" | "recording" | "stopping";
let phase: Phase = "idle";
function setPhase(next: Phase): void { const was = phase!=="idle"; phase = next; const now = phase!=="idle"; if (was!==now) onCaptureActiveChange?.(now); }
function safeStop(): void { if (phase !== "recording") return; setPhase("stopping"); try { recorder?.stop(); } catch { fatalStopCleanup(); } }
recorder.onstop = async () => { try { /* blob→await onCaptured */ } catch { /* 既存ローカルエラー表示へ */ } finally { setPhase("idle"); } };
recorder.onerror = () => safeStop();  // track.onended も safeStop
function fatalStopCleanup(): void { setPhase("idle"); releaseCamera(); onCameraUnavailable("recorder_unsupported"); }
export function releaseForPreview(): void { if (phase !== "idle") return; wasActiveBeforePreview = stream !== null; releaseCamera(); }
```

### S2 TakePreviewDialog
```ts
let video: HTMLVideoElement | undefined = $state();
function teardownVideo(target: HTMLVideoElement): void { target.pause(); target.removeAttribute("src"); target.load(); }
$effect(() => { if (!open || take === null || video === undefined) return; const target = video; return () => teardownVideo(target); });
```
```svelte
{#if open && take !== null}
    {#key take.id}
        <video bind:this={video} controls playsinline src={playbackUrl ?? undefined} class="w-full ..."></video>
    {/key}
{/if}
```

TakeStrip 側は `captureActive`（recording|stopping）で `openPreview` をガードし、撮影中は押下時エラー（disabled にしない）。

判定をお願いします。
