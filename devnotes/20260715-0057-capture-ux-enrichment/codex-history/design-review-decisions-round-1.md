# 対応マトリクス: design-review Round 1

## [Critical] S2: onpause/onresume/onstop の phase 競合（stop 後の onresume で recording 復帰）
- 判断: 対応する
- 根拠: UA によりイベント順が前後し得る。過渡イベントは想定 phase のときのみ有効にする防波堤が要る。
- 対応内容: イベントハンドラに厳格な phase ガード。`onpause` は `phase==="pausing"||"recording"` のみ有効、`onresume` は `phase==="resuming"` のみ有効、`stopping`/`idle` では onpause/onresume を無視（`stopping` 優先で終端固定）。`onstop` は全 phase から idle へ収束。遷移を `transition(phase, event)` 純関数へ集約しテスト対象に（Suggestion 併合）。

## [Critical] S3: 段階3の facingMode 復旧が文脈依存（previousMode を固定していない）
- 判断: 対応する
- 対応内容: `switchCamera()` 先頭で `const previousMode = facingMode` を固定し、復旧は必ず `previousMode` を使う。`target = oppositeFacingMode(previousMode)` と明示分離。成功時のみ `facingMode = target`。

## [Critical] S3: classifyGetUserMediaError の二重評価
- 判断: 対応する
- 対応内容: `const classified = classifyGetUserMediaError(recoverCause)` を 1 回だけ評価して分岐。`switchCamera()` の戻り値を `CameraSwitchOutcome` にし recoverable/unavailable の責務を明確化（Suggestion 併合）。

## [Critical] S6: テスト期待が phase 混在で曖昧（paused stop可 / pausing・resuming stop不可）
- 判断: 対応する
- 対応内容: テスト表を phase ごとに明示。`paused: stop 可`、`pausing/resuming: pause/resume/stop 不可(no-op)`、`recording: pause 可・stop 可`。ケース名を期待と一致させる。

## [Warning] S2/S4: performance.now のタブサスペンド復帰ジャンプ
- 判断: 一部対応（仕様明文化）
- 根拠: MediaRecorder が背景でも録画継続する場合、performance.now 差は実録画壁時計と一致するため duration は正しい。過剰な再基準化はオーバーエンジニアリング（思考原則2）。
- 対応内容: duration の定義を「active セグメントの壁時計和（performance.now 差）」と明文化。表示 tick のみの問題であり duration 正確性には影響しない旨を設計に追記。再基準化は導入しない（複雑化回避）。

## [Warning] S3: hasMultipleVideoInputs をボタン描画条件にすると誤非表示リスク
- 判断: 対応する
- 対応内容: 反転ボタンの描画条件を `canSwitchCamera(phase) && stream !== null && canFlipHint` に限定（そもそも live preview が無い時は flip 対象が無い）。canFlipHint は初回取得成功後 + `devicechange` で再評価し、その再評価をテストで固定。禁止事項8 は「描画される時は常に押下可能（disabled にしない）」で整合。

## [Warning] S5: bg-surface/60 は背景動画次第で視認困難
- 判断: 対応する
- 対応内容: グリッド線を既存 `SubtitleOverlay` と同じ scrim 系トークン `bg-text/40` に寄せる（SubtitleOverlay は `bg-text/70` を採用済み＝カメラ上 overlay の確立パターン）。存在しない contrast トークンを新設せず、既存の overlay トークン選択に整合。

## [Suggestion] transition 純関数化 / CameraSwitchOutcome を戻り値に / FakeMediaRecorder のイベント順注入
- 判断: 対応する（Critical 対応に併合）
- 対応内容: 上記のとおり `transition()` 純関数化・`CameraSwitchOutcome` 戻り値化を採用。テストの `FakeMediaRecorder` に onpause/onresume/onstop の手動発火 API を追加し UA 差の順序を再現可能にする。
