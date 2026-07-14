Round 3 の Critical（S2 完全 teardown / S4 onstop 例外保証・停止処理中の解放禁止）に対応しました。再レビューをお願いします。各施策の判定と全体判定を明示してください。

## 対応サマリー

- **S2-Critical（cleanup が pause のみ→資源残置）**: 採用。`<video>` を `{#if open && take !== null}{#key take.id}` 下でのみ生成。cleanup で完全 teardown（`pause()` + `removeAttribute("src")` + `load()`）。要素は open 中のみ存在＝再 open は新要素で宣言的 src と競合しない。close/take 差し替え/採用成功/破棄の全経路を同一 cleanup でカバー。
- **S4-Critical（onstop 内例外で永久録画中）**: 採用。`recorder.onstop` を `try { finalize/onCaptured } finally { setPhase("idle") }` にし終了通知を保証。
- **S4-Warning（safeStop の状態明確化・停止失敗の残置）**: 採用。内部 phase マシン `idle | recording | stopping` を導入。外部通知 `recording = phase==="recording"`。`safeStop()` は phase==="recording" のときのみ stop()（多重呼び出しガード）。stop() 例外時は `fatalStopCleanup()`（idle + releaseCamera + onCameraUnavailable）で復旧不能を防止。camera 解放（releaseForPreview / resumeAfterPreview）は **phase !== "idle" で拒否**（recording と stopping の両方で禁止）。
- **S5**: 追加テスト — (a) close 後に src 除去/load()・video 破棄、(b) onstop 内 onCaptured reject/throw でも phase idle へ、(c) recording/stopping 中は releaseForPreview 拒否、(d) safeStop 多重で stop() 重複しない、(e) 取得失敗後 再試行可、(f) start 成功時 終了前に onRecordingChange(false) 非発火。

## 修正後コード（抜粋）

### S2 TakePreviewDialog
```svelte
{#if open && take !== null}
    {#key take.id}
        <video bind:this={video} controls playsinline src={playbackUrl ?? undefined} class="w-full ..."></video>
    {/key}
{/if}
```
```ts
function teardownVideo(): void { video?.pause(); video?.removeAttribute("src"); video?.load(); }
$effect(() => { if (!open || take === null) return; return () => teardownVideo(); });
```

### S4 CameraRecorder（phase マシン）
```ts
let phase: "idle" | "recording" | "stopping" = "idle";
function setPhase(next: typeof phase): void { const was = phase==="recording"; phase = next; const now = phase==="recording"; if (was!==now) onRecordingChange?.(now); }
function safeStop(): void { if (phase !== "recording") return; setPhase("stopping"); try { recorder?.stop(); } catch { fatalStopCleanup(); } }
recorder.onstop = async () => { try { /* blob→onCaptured */ } finally { setPhase("idle"); } };
recorder.onerror = () => safeStop();  // track.onended も safeStop
function fatalStopCleanup(): void { setPhase("idle"); releaseCamera(); onCameraUnavailable("recorder_unsupported"); }

export function releaseForPreview(): void { if (phase !== "idle") return; wasActiveBeforePreview = stream !== null; releaseCamera(); }
export function resumeAfterPreview(): Promise<void> {
    if (resuming) return resumePromise ?? Promise.resolve();
    if (!wasActiveBeforePreview || phase !== "idle") return Promise.resolve();
    resuming = true;
    resumePromise = acquirePreviewStream().then(() => { wasActiveBeforePreview = false; }).finally(() => { resuming = false; resumePromise = null; });
    return resumePromise;
}
```

判定をお願いします。残课題があれば施策単位で具体修正案を添えてください。
