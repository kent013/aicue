# 対応マトリクス: design-review Round 1

Codex 判定: CHANGES_REQUESTED（S1/S4/S5 に Critical）

## [Critical] S1: TakePolicy の登録（auto-discovery）前提の明示 + 認可 Feature テスト
- 判断: 対応する
- 根拠: 既存 `Gate::authorize('adopt'/'update'/... , $take)` が稼働中 = Take→TakePolicy の auto-discovery は既に有効（Laravel 12 命名規約）。設計に明記し 403/200 テストを必須化。
- 対応内容: S1 に「Take→TakePolicy は auto-discovery 稼働（既存 adopt 等が実証）」を明記。S5 に 403（非 capture）/ 302（capture）を必須と再掲。

## [Warning] S1: render playback との Cache-Control 挙動差の明文化
- 判断: 対応する
- 対応内容: take 側を厳格化する理由（撮影 PWA の即時再生・署名 URL 再利用抑止）を設計に明文化。render 側追随は別 TODO 化（スコープ外）。

## [Suggestion] S1: failed→404 の秘匿理由コメント
- 判断: 対応する（コメント追記）

## [Warning] S2: take 差し替え時（open=true のまま別 take）の teardown
- 判断: 対応する
- 根拠: 端末差異で音声継続の恐れ。妥当。
- 対応内容: `take?.id` 変化時にも teardownVideo を実行する分岐を追加。

## [Suggestion] S2: 字幕 overlay の aria-live="off"
- 判断: 対応する（装飾テキスト扱い明記）

## [Warning] S3: non-ready 行に補助文言
- 判断: 対応する（「アップロード処理中は再生できません」等の補助文言/tooltip）

## [Suggestion] S3: 採用失敗時のフォーカス維持
- 判断: 対応する（軽微。実装時チェックに記載）

## [Critical] S4: recording 状態の失敗経路（onerror/track.onended/catch）で false 化
- 判断: 対応する
- 根拠: start成功/onstop のみだと初期化失敗・権限剥奪・track ended で不整合。妥当。
- 対応内容: `recorder.onerror` / `stream track.onended` / catch/finally で `setRecording(false)` を必ず通す。`recording` 更新を単一 setter に集約し `onRecordingChange` を発火。

## [Warning] S4: resumeAfterPreview の多重呼び出し（getUserMedia 競合）
- 判断: 対応する
- 対応内容: `resuming` フラグ + in-flight Promise 共有で再入防止。

## [Suggestion] S4: bind:this の型（any 回避）
- 判断: 対応する
- 対応内容: `let recorderRef = $state<CameraRecorder | null>(null)` の型注釈（Svelte 5 は component instance 型を `import type` で参照可）を明記。

## [Critical] S5: team 文脈（laratrust_team_id 明示）のセキュリティ検証不足
- 判断: 対応する
- 対応内容: Feature に「同一ユーザーが別 team 文脈では 403 / 正 team 文脈では 302」を追加。

## [Warning] S5: take mismatch（cut 配下でない take）の IDOR ケース
- 判断: 対応する
- 対応内容: `.../cuts/{cutA}/takes/{takeB}`（takeB は cutB 所属）で 404 を追加。

## [Suggestion] S5: vitest「close 時 onCameraResume が必ず1回」
- 判断: 対応する

## [Warning] 横断: playback URL を payload に戻さない理由の 1 行追記
- 判断: 対応する

## [Suggestion] 横断: Atomic 逆流 import チェック / token クラス例
- 判断: 対応する（実装時チェック + overlay の token クラス例を 1 つ提示）
