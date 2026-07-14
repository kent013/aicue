## 施策別判定

- セッション世代管理: **REQUEST_CHANGES**
- 一度限りfinalizer: **REQUEST_CHANGES**
- watchdog: **APPROVE**
- テスト追加方針: **REQUEST_CHANGES**

### [Critical] `onerror` / `track.onended` が世代分離されていない

次のハンドラは生成時sessionを検証せず、`handleAbort()`もグローバルな`recorder`と`sessionId`を参照します。

```ts
recorder.onerror = () => handleAbort();
t.onended = () => handleAbort();
```

旧recorderの遅延errorが新録画中に届くと、新セッションを`stopping`へ遷移させ、新recorderを停止します。

修正案:

```ts
function handleAbort(session: number, targetRecorder: MediaRecorder): void {
    if (session !== sessionId || finalizedSession === session) return;
    // targetRecorderのみを処理
}

const currentRecorder = recorder;
currentRecorder.onerror = () => handleAbort(session, currentRecorder);
track.onended = () => handleAbort(session, currentRecorder);
```

旧`onerror` / `onended`が新セッションを停止しないテストも追加してください。

### [Critical] `finalizeSession()`から`mimeType`を参照できない

現行設計では`mimeType`は`startRecording()`内のローカル変数です。一方、提示された`finalizeSession()`は外側の関数なので、そのままではTypeScriptコンパイルエラーになります。

引数を増やすより、以下を録画セッションオブジェクトへまとめる設計が安全です。

```ts
interface RecordingSession {
    id: number;
    recorder: MediaRecorder;
    mimeType: string;
    chunks: Blob[];
    accumulatedMs: number;
    segmentStart: number | null;
    finalized: boolean;
}
```

各イベントは生成時の`RecordingSession`をclosureで保持し、finalizerもそのオブジェクトだけを処理してください。これにより`chunks`とdurationも新旧セッション間で物理的に分離できます。

## 全体判定

**CHANGES_REQUESTED**

世代IDの方向性は正しいですが、異常イベントと録画データをセッションオブジェクト単位に閉じる必要があります。これを反映できればAPPROVED相当です。