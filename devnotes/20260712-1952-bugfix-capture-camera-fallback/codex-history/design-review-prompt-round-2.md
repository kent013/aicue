# 詳細設計レビュー Round 2: 指摘への対応と再レビュー依頼

Round 1 の指摘 ([Warning] 3 件) にすべて対応し、詳細設計書を改訂しました。
変更点の差分を示します。全体判定 (APPROVED / CHANGES_REQUESTED) をお願いします。
改訂後の全文は `/workspace/devnotes/20260712-1952-bugfix-capture-camera-fallback/detailed-design.md` を read-only で参照可能です。

## 対応 1: [Warning] startRecording の再入防止 (施策 2)

`starting` フラグによる再入ガードを追加 (UI disabled は使わず関数の早期 return。全 return 経路で解除されるよう try/finally で包む):

```ts
let starting = false; // 開始処理中の再入ガード (UI に出さないため $state にしない)

async function startRecording(): Promise<void> {
    if (starting || recording) return; // 再入防止 (アーリーリターン。規約: disabled 禁止)
    starting = true;
    try {
        error = null;
        const mimeType = preferredRecordingMimeType();
        if (mimeType === null) {
            onCameraUnavailable("mime_unsupported");
            return;
        }
        try {
            stream ??= await navigator.mediaDevices.getUserMedia({
                video: { facingMode: "environment" },
                audio: true,
            });
        } catch (cause) {
            const classified = classifyGetUserMediaError(cause);
            if (classified.kind === "transient") {
                error = "カメラを起動できませんでした。他のアプリがカメラを使用していないか確認し、もう一度お試しください。";
                return;
            }
            onCameraUnavailable(classified.reason);
            return;
        }
        if (video) {
            video.srcObject = stream;
            await video.play().catch(() => undefined);
        }
        chunks = [];
        try {
            recorder = new MediaRecorder(stream, { mimeType });
        } catch {
            releaseCamera();
            onCameraUnavailable("recorder_unsupported");
            return;
        }
        recorder.ondataavailable = (event) => {
            if (event.data.size > 0) chunks.push(event.data);
        };
        recorder.onstop = () => {
            const blob = new Blob(chunks, { type: mimeType });
            const durationMs = Date.now() - startedAt;
            recording = false;
            if (blob.size > 0) {
                onCaptured(blob, mimeType, durationMs);
            }
        };
        startedAt = Date.now();
        recorder.start();
        recording = true;
    } finally {
        starting = false;
    }
}
```

テスト計画にも再入ガードのケースを追加:
- `getUserMedia` が pending のまま解決しない Promise を返す状態で録画開始を 2 連打 → `getUserMedia` の呼び出しが 1 回であること

## 対応 2: [Warning] 成功パス (onCaptured) の契約テスト追加 (施策 4)

`CameraRecorder.test.ts` に成功契約テストを 1 本追加:

- `MediaRecorder` を最小クラス stub 化 (`start()` noop / `stop()` で `ondataavailable({ data: Blob })` → `onstop()` を手動発火、`static isTypeSupported`)
- `getUserMedia` は fake stream (`{ getTracks: () => [] }`) で resolve
- jsdom の HTMLMediaElement 未実装対策として `HTMLMediaElement.prototype.play` を `Promise.resolve()` 返却に stub
- 録画開始 → 録画停止で `onCaptured(blob, "video/webm", durationMs)` が呼ばれる (blob 非空・mimeType 一致・durationMs が number) こと、`onCameraUnavailable` が呼ばれないことを検証

## 対応 3: [Warning] contentType 正規化の回帰検証 (施策 4)

`CaptureShow.test.ts` を改訂:

- (c) に `contentType: "video/mp4"` の明示検証を追加
- (d) を新設: codecs 付き MIME (`File(["data"], "take.webm", { type: "video/webm;codecs=vp9" })`) を選択 → `enqueue` の `contentType` が `"video/webm"` (`mimeType.split(";")[0]` の正規化後) であることを検証

## その他

[Suggestion] 指摘 (施策 1 / 施策 3 / 横断) はすべて肯定的評価のため設計変更なし。
