# 対応マトリクス: design-review Round 1

## [Critical] S4 onerror + paused で safeStop が停止不能
- 判断: 対応する
- 根拠: `recorder.onerror = () => safeStop()`。paused 中 onerror で safeStop が paused 非対応だと停止不能。
- 対応内容: `safeStop` の条件を `phase !== "recording" && phase !== "paused"` に統一（既に設計後段に記載済みだが冒頭 safeStop 定義でも明示）。テストに「paused 中 onerror → 停止完了（onstop→onCaptured）」を必須追加。

## [Critical] S6 flip の完全喪失で onCameraUnavailable が段階2で早期発火するバグ
- 判断: 対応する（設計の欠陥を修正）
- 根拠: 現設計の `reacquireWithFacing` は `acquirePreviewStream()` を呼ぶが、同関数は内部で transient→error 表示 / unavailable→`onCameraUnavailable`(F-03) の**副作用**を持つ。新 facing が OverconstrainedError/NotFoundError（前面カメラ無し等）だと段階2で `onCameraUnavailable` が発火し、旧カメラが生きているのに F-03 に倒れる。これは Codex R2/R3 で合意した「flip 初回失敗の非 F-03」に反する実装バグ。
- 対応内容: 取得を副作用なしの低レベル関数に分離:
  - `acquireStream(): Promise<CameraErrorClassification | { kind: "ok" }>` — getUserMedia + video.srcObject 設定のみ。**onCameraUnavailable / error を呼ばず** classify 結果を返す。
  - `acquirePreviewStream()`（既存契約維持）は acquireStream をラップし、従来通り transient→error / unavailable→onCameraUnavailable の副作用を適用（startRecording / resumeAfterPreview の既存呼び出しは無改変）。
  - `reacquireWithFacing` は `acquireStream` を直接使い、副作用を段階4 まで遅延:
    - 段階2: releaseCamera → facingMode=target → `acquireStream()`。ok なら終了。
    - 段階3: facingMode=previous → `acquireStream()`。ok なら flip 断念（error「カメラを切り替えられませんでした」）で終了。
    - 段階4: 両方失敗（= 完全喪失）。段階3 の classify 結果に対してのみ副作用適用: transient→error 表示 / unavailable→`onCameraUnavailable(reason)`（F-03 委譲）。
  - これで「新 facing のみ不可（旧カメラ生存）」は段階3 で復旧して F-03 に倒さず、「両カメラ喪失」でのみ F-03 に委譲される。テストで OverconstrainedError（新 facing）→ 旧復旧 → onCameraUnavailable 呼ばれない、を必須化。

## [Critical] S7 stopping 表示方針の途中変更で既存テスト衝突
- 判断: 対応する（方針固定）
- 根拠: 停止ボタンを phase 別に分けると既存「safeStop 多重呼び出し」テスト（stop ボタン 2 回クリック）が壊れる。
- 対応内容: 操作行の停止ボタンは **recording / paused / stopping で常時可視**（stopping では safeStop が phase ガードで no-op）。設計の操作行分岐を「stopping でも停止ボタン可視」に固定し、テストに「stopping 中 stop ボタン可視 + safeStop no-op（stopCalls 不増）」を明示追加。

## [Warning] S4 inactive without onstop の収束保証（UA バグ）
- 判断: 対応する
- 根拠: `recoverPhaseFromRecorderState` が inactive を検出しても idle に倒さない方針は、onstop 永久未達 UA バグで復帰不能の余地。
- 対応内容: recover が `state === "inactive"` かつ phase が recording/paused（stopping/idle 以外）を検出したら**フェイルセーフとして `fatalStopCleanup()` 経由で idle 復帰 + 資源解放**する（recorder が死んでいる異常系のため F-03 委譲は妥当）。理由をコメント明記。通常の onstop 正規終了とは競合しない（onstop 到達時は既に stopping/idle）。

## [Warning] S6 getSettings().facingMode が undefined の端末
- 判断: 対応する
- 対応内容: `tryApplyFacing` は `getSettings().facingMode === target` 検証で、undefined は「未検証扱い→再取得へ倒す（安全側）」とコメント明記。テストで undefined→再取得を検証。

## [Warning] S7 遅延 pause/resume イベントの二重遷移なしテスト
- 判断: 対応する
- 対応内容: fake timer で pauseResumeTimeout 経過（recover で state 同期済み）後に遅延 onpause/onresume を発火 → phase 不変を検証するケースを追加。

## [Warning] S7 durationMs が pause 区間を含まない厳密検証
- 判断: 対応する
- 対応内容: fake timer で record→pause（区間A）→resume→stop の経過を組み、`onCaptured` の durationMs が pause 区間を除外した累積（区間A + 区間B のみ、pause 中の壁時計を含まない）であることを厳密検証するケースを追加。

## [Warning] S3 timer tick 遅延でも累積破綻しないテスト + recordedDurationMs クランプ
- 判断: 対応する
- 対応内容: `recordedDurationMs()` を `Math.max(0, …)` で明示クランプ。テストで interval tick を間引いても（performance.now 差分ベースのため）累積値が破綻しないことを 1 本検証。

## [Suggestion] 群
- supportsPauseResume にクライアント専用注記 / formatElapsed の hh:mm:ss 将来 TODO / z 順テスト名明示 / grid 連打で label 同期 / 実機コントラスト目視: いずれも軽微。命名・コメント・テスト名で反映（実機コントラストは実装時の目視確認事項として注記）。
