**ファイル別判定**

- `resources/js/lib/capture/camera.ts` — **APPROVE**
  - 施策1どおり `CameraUnavailableReason` と `classifyGetUserMediaError` を追加し、既存 `supportsMediaRecorder` / `preferredRecordingMimeType` の契約を壊していません。
  - [Suggestion] `errorName()` の `name in error` 分岐は適切ですが、将来の可読性のため `isNamedErrorLike` 的な小さな type guard 名を付けてもよいです（現状でも strict 的には問題なし）。

- `resources/js/components/features/capture/CameraRecorder.svelte` — **APPROVE**
  - 施策2に整合。恒久失敗は `onCameraUnavailable` 委譲、一時失敗のみローカル `alert`、`starting` 再入ガード、`MediaRecorder` 構築失敗時 `releaseCamera()` 実施が入っています。
  - [Warning] `recorder.start()` が例外を投げるケース（UA差異・状態競合）が未捕捉で、ここで throw すると「分類不能は fallback 側へ倒す」要件を満たせない余地があります。  
    修正案: `recorder.start()` を `try/catch` で包み、失敗時は `releaseCamera(); onCameraUnavailable("unknown")`（または `"recorder_unsupported"`）へ倒す。
  - [Suggestion] `starting` を `let starting = $state(false)` にしておくと、将来UIに「開始中」表示を足す場合の拡張性が上がります（現状要件では必須ではない）。

- `resources/js/pages/Capture/Show.svelte` — **APPROVE**
  - 施策3に整合。`showRecorder = canRecord && reason===null`、`fallbackNotice` の reason 分岐、実行時切替が明確です。
  - DESIGN.md 観点も `text-caption` / `text-text-secondary` の既存 token 利用で逸脱なし、`role="status"` も要件適合。
  - Atomic Design 観点も既存 features/atoms 組み合わせで逆流なし。

- `tests/js/lib/capture/camera.test.ts` — **APPROVE**
  - 分類ロジックの主要分岐（恒久/一時/unknown、DOMException非継承相当）を押さえており、施策4の camera 分類テストとして十分です。

- `tests/js/components/features/capture/CameraRecorder.test.ts` — **APPROVE**
  - 親通知、ローカル表示分岐、成功契約、再入ガードまで網羅できています。
  - [Warning] 「MediaRecorder 構築失敗時に stream を解放」の要件に対して、現テストは `onCameraUnavailable("recorder_unsupported")` しか検証していません。  
    修正案: `fakeStream()` で `track.stop` を spy 化し、`releaseCamera()` 経由で `stop` が呼ばれることを assert するケースを追加。

- `tests/js/pages/CaptureShow.test.ts` — **APPROVE**
  - 静的非対応時の回帰（noticeなし）、実行時失敗での切替、enqueue引き渡し、contentType正規化まで施策4要求を満たしています。
  - [Suggestion] `permission_denied` 以外（例: `device_missing`）の notice 文言分岐も1ケース足すと、UI出し分け退行に強くなります。

**全体判定**

- **CHANGES_REQUESTED**

理由は主に Warning 2点です。どちらも「詰みを作らない」要件をより堅く満たすための修正で、特に `recorder.start()` 例外時のフォールバック保証は実運用での安全弁として先に入れておく価値が高いです。