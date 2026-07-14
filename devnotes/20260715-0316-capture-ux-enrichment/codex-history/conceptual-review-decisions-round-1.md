# 対応マトリクス: conceptual-review Round 1

## [Warning] MediaRecorder.pause()/resume() の能力差分を前提化せよ
- 判断: 対応する
- 根拠: iOS Safari 等で MediaRecorder は存在しても pause/resume が不安定/未実装のケースがある。`supportsMediaRecorder()` では足りない。
- 対応内容: `camera.ts` に `supportsPauseResume()`（`MediaRecorder.prototype.pause/resume` の typeof 検査）を追加。未対応端末では一時停止ボタンを**非表示**にし従来の start/stop のみに退行（disabled ではない=禁止事項8非該当）。pause()/resume() の `InvalidStateError` は try/catch し UI phase を元の recording/paused に戻す（握りつぶさない）。

## [Warning] カメラ反転は失敗時 rollback 付きにせよ
- 判断: 対応する
- 根拠: 「旧 stream 破棄 → 新取得」は前面カメラ非搭載・権限再確認・facingMode 無視時に現行プレビューまで失う。切替失敗を F-03（onCameraUnavailable）へ流すのも過剰。
- 対応内容: flip は「**新 stream を取得成功してから旧 stream を停止・差し替え**、失敗時は旧 stream を維持」に変更。取得失敗は transient エラー表示のみ（onCameraUnavailable を呼ばない=F-03 へ倒さない）。`acquirePreviewStream` は `stream ??=` を使うため flip 専用に「旧を残したまま新規取得する」経路を分離する。

## [Warning] durationMs の意味変更を「後方互換」と言い切るな
- 判断: 対応する（表現を訂正 + 消費側棚卸し）
- 根拠: 消費側を実確認。`onCaptured(_, _, durationMs)` の唯一の実消費は `Capture/Show.svelte#handleCaptured → upload-queue.enqueue({durationMs}) → POST body の duration_ms`（doc/10: `takes.duration_ms int NULL 派生`=テイクの**実録画尺メタ**）。wall-clock に依存する消費は無い。pause 中を除外した累積録画時間は MediaRecorder が実際に録った尺と一致し、duration_ms の意味（実録画尺）に**より正確**。
- 対応内容: 概念設計の「後方互換」表現を「**意味の訂正（実録画尺への一致）。単一消費（upload-queue→takes.duration_ms メタ）を棚卸し済みで wall-clock 依存なし。型は number 不変**」に改める。詳細設計で消費経路を明記し実装前提に格上げ。

## [Warning] v1 内でも優先度を分けよ（反転は重い）
- 判断: 対応する
- 根拠: 1/2/4 は端末差分・退行リスク小。3（反転）は端末差分と失敗時退行が相対的に重い。
- 対応内容: 優先度を「**core: 1 pause/resume・2 grid・4 timer / guarded: 3 camera flip**」と設計に明記。3 は rollback + transient 限定で guarded に格下げ（ただし v1 には残す。軽量な idle 限定切替のため）。

## [Warning] Phase union の網羅性を単一ソース化せよ
- 判断: 対応する
- 根拠: paused 追加で分岐見落としが出やすい。
- 対応内容: `type Phase = "idle" | "recording" | "paused" | "stopping"` を単一ソースに。UI 文言・ボタン表示・`active` 算出・ハンドラ条件をこの型に従属させる。`active = starting || resuming || phase !== "idle"`（paused も非 idle で active=true）。

## [Warning] timer/facingMode の型を string/number に落とすな
- 判断: 対応する
- 対応内容: `type FacingMode = "environment" | "user"` を `camera.ts` に定義し共通化。timer handle は `ReturnType<typeof setInterval>` 型で保持。`formatElapsed(ms): string` は pure function として `camera.ts` に切り出す（テスト対象）。

## [Suggestion] 累積計測は単調増加時計ベースにせよ
- 判断: 対応する（軽量なため採用）
- 対応内容: 経過計測は `performance.now()` ベースの累積（recording 区間の加算）で行う。system 時計の巻き戻し耐性を得る。

## [Suggestion] grid/字幕 overlay の可読性衝突（z-index/線濃さ/字幕帯）を規約化
- 判断: 対応する
- 対応内容: grid は最背面 overlay（字幕帯より下、映像の上）。罫線は DS token の半透明（`border-surface/40` 相当）で細線。字幕帯（`bg-text/70`）と重なっても字幕優先で可読。詳細設計で z 順・線仕様を明記。

## [Suggestion] 反転 toggle helper の helper 化価値が小さい
- 判断: 一部対応
- 根拠: 単純反転のみなら helper 価値小。ただし FacingMode 型は共通化する。
- 対応内容: `nextFacingMode(mode): FacingMode` は薄いが型の単一ソース + テスト容易性のため残す（1 ケースの純粋テスト）。過剰なら実装時に inline 化可と注記。

## [Suggestion] 効果を観測可能な指標に言い換え
- 判断: 見送り（設計トーンのみ反映）
- 根拠: v1 に計測基盤を追加するのはスコープ過大。期待効果の文言を「再撮影・分断の低減（観測可能な仮説）」に寄せるに留める。
