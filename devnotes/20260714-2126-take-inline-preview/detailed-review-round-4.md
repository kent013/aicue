## 施策別判定

### S1 — APPROVE

問題ありません。

### S2 — REQUEST_CHANGES

- [Warning] cleanup が可変なグローバル参照`video`を使うため、take差し替え時の実行順によっては旧要素ではなく新要素をteardownする可能性があります。また、DOM破棄で`bind:this`が解除された後ならno-opになります。

  修正案: effect実行時の要素をローカルに固定してください。

```ts
function teardownVideo(target: HTMLVideoElement): void {
    target.pause();
    target.removeAttribute("src");
    target.load();
}

$effect(() => {
    if (!open || take === null || video === undefined) return;

    const target = video;
    return () => teardownVideo(target);
});
```

`video`は`HTMLVideoElement | undefined`として型付けします。Svelte actionの`destroy`で対象nodeを直接teardownする方法でも構いません。

### S3 — APPROVE

問題ありません。

### S4 — REQUEST_CHANGES

- [Critical] `recording = phase === "recording"`という外部通知では、`recording → stopping`時点で`false`が通知されます。するとTakeStripはpreviewを開けますが、CameraRecorder側は`phase !== "idle"`なのでcamera解放を拒否します。結果として停止処理中にvideo playbackとMediaRecorderが同居します。

  修正案: 外部へ通知する排他状態は`phase !== "idle"`としてください。既存名を維持するなら`onRecordingChange(true)`をstopping中も維持し、idle遷移時だけfalseを通知します。より明確には`onCaptureActiveChange`などへ改名します。

```ts
function setPhase(next: Phase): void {
    const wasActive = phase !== "idle";
    phase = next;
    const isActive = phase !== "idle";

    if (wasActive !== isActive) {
        onRecordingChange?.(isActive);
    }
}
```

- [Warning] asyncな`onstop`内で`onCaptured`がrejectすると、finallyでidleには戻れても未処理Promise rejectionが残ります。

  修正案: `catch`で既存エラー表示経路へ渡し、`finally`でidle化してください。

### S5 — REQUEST_CHANGES

現在の追加内容に加えて次を検証してください。

- [Critical] stopping遷移では`onRecordingChange(false)`が発火せず、idle遷移時に初めて発火する。
- [Critical] stopping中はTakeStripがpreview dialogを開かない。
- [Warning] take差し替え時、旧videoだけがteardownされ、新videoの`src`が保持される。
- [Warning] `onCaptured` rejectが既存エラー処理へ渡り、未処理rejectにならない。

## 全体判定

**CHANGES_REQUESTED**

録画排他の内部phaseは良くなっています。残る主要点は、外部へ公開する排他状態にも`stopping`を含めることです。