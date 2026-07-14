Round 1 の指摘（S1/S4/S5 の Critical 含む全 Warning）に対応しました。再レビューをお願いします。各施策の APPROVE/REQUEST_CHANGES と全体判定を明示してください。

## 対応サマリー

- **S1-Critical（TakePolicy 登録前提）**: Take→TakePolicy は auto-discovery が既に有効（既存 `Gate::authorize('adopt'|...)` が稼働＝実証済み）と明記。`preview` 追加のみで解決。認可 Feature（403/302）を必須化。
- **S1-Warning（render との Cache-Control 挙動差）**: take 側厳格化の理由（撮影 PWA の即時・反復再生での署名 URL 再利用抑止）を明文化。render 追随は別 TODO（スコープ外）。
- **S1-Suggestion（failed→404 秘匿）**: コメント追記。
- **S2-Warning（take 差し替え時の teardown）**: `open===true` のまま `take?.id` 変化時も teardownVideo → 新 src 読み込みの分岐を `$effect` で追加。
- **S2-Suggestion**: 字幕 overlay を `aria-live="off"`（装飾扱い）。token クラス例（`bg-surface/80 text-text-primary`）を提示。
- **S3-Warning（非 ready 行の理由可視化）**: uploading/processing/failed 行に補助文言を caption 表示。
- **S3-Suggestion**: 採用失敗時はフォーカスを採用ボタンへ戻す（実装時チェック）。
- **S4-Critical（recording 失敗経路）**: `recording` 更新を単一 setter `setRecording()` に集約し、start 成功=true に加え `recorder.onstop` / `recorder.onerror` / track `onended` / start 例外 catch/finally の全経路で false に倒して `onRecordingChange` 発火。
- **S4-Warning（resume 多重呼び出し）**: `resuming` フラグ + in-flight Promise 共有で getUserMedia 二重取得を防止。
- **S4-Suggestion（bind:this 型）**: `import type CameraRecorder` して `$state<CameraRecorder | null>` で型付け（any 回避）。
- **S5-Critical（team 文脈）**: Feature に「別 team 文脈=403 / 正 team 文脈=302」を追加。
- **S5-Warning（take mismatch IDOR）**: `.../cuts/{cutA}/takes/{takeB}`（takeB は別 cut）→404 を追加。
- **S5-Suggestion**: close 時 `onCameraResume` がちょうど1回、`onRecordingChange(false)` の各失敗経路、resume 再入ガードの vitest を追加。
- **横断**: playback URL を payload に戻さない理由（都度発行）を明記。atomic 逆流 import・token・Lucide の実装時チェックを追加。

## 主要な修正後コード（抜粋）

### S1 playback（failed→404 秘匿コメント + no-store,private + 理由明文化）
```php
public function playback(Request $request, Project $project, VideoManual $manual, Cut $cut, Take $take, TakeObjectStorage $storage): RedirectResponse {
    $organization = $this->resolveCurrentOrganization($request);
    $this->resolveOrganizationProject($organization, $project);  // 認可前 404
    Gate::authorize('preview', $take);                            // TakePolicy::preview (auto-discovery)
    if ($take->status !== TakeStatus::Ready) { abort(404); }      // 状態秘匿
    return redirect()->away($storage->temporaryPlaybackUrl($take->video_path))
        ->withHeaders(['Cache-Control' => 'no-store, private']);
}
```

### S4 CameraRecorder（単一 setter + 失敗経路 + resume 再入ガード）
```ts
function setRecording(next: boolean): void { if (recording !== next) { recording = next; onRecordingChange?.(next); } }
// start 成功: setRecording(true)
// recorder.onstop / recorder.onerror / track.onended / start catch-finally: setRecording(false)

let resuming = false; let resumePromise: Promise<void> | null = null;
export function releaseForPreview(): void { if (recording) return; wasActiveBeforePreview = stream !== null; releaseCamera(); }
export function resumeAfterPreview(): Promise<void> {
    if (resuming) return resumePromise ?? Promise.resolve();
    if (!wasActiveBeforePreview || recording) return Promise.resolve();
    resuming = true; wasActiveBeforePreview = false;
    resumePromise = acquirePreviewStream().finally(() => { resuming = false; resumePromise = null; });
    return resumePromise;
}
```

### S2 TakePreviewDialog（teardown 単一関数 + take 差し替え）
```ts
function teardownVideo(): void { video?.pause(); video?.removeAttribute("src"); video?.load(); }
$effect(() => { if (!open) teardownVideo(); });          // close 経路 (採用成功含む)
$effect(() => { void take?.id; teardownVideo(); });      // take 差し替え時に旧再生を停止
```

判定をお願いします。残课題があれば施策単位で具体修正案を添えてください。
