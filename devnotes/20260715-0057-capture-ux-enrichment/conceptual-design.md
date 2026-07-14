# 概念設計: capture-ux-enrichment（撮影UXの拡充 ※v1スコープ判定込み）

出典: ユースケース・カバレッジ監査ギャップ #8（Medium）。
doc/05 §5.2「撮影 UI（縦持ち / 横持ち）」が挙げる撮影補助機能のうち、
現状 `CameraRecorder.svelte` に無いものを **v1 スコープ判定した上で対象分のみ**追加する。

---

## 背景・課題

現状 `resources/js/components/features/capture/CameraRecorder.svelte` は
**録画開始 / 停止 + 字幕オーバーレイトグル（T047）** のみを持つ。
doc/05 §5.2 は撮影 UI の要件として次を挙げるが、これらが未実装:

1. **一時停止 / 再開（同一テイク継続）** — §5.2「録画中に中断し、再タップで同じテイクの録画を継続」
2. **グリッド表示切替** — §5.2 補助機能
3. **カメラ反転（イン / アウト）** — §5.2 補助機能
4. **録画時間タイマー（00:00）** — §5.2 補助機能
5. **横持ち（全画面）+ 左右スワイプで手順前後移動 / 下部サムネイル即再生** — §5.1・§5.2

使命（マニュアル動画を「思考ゼロ・編集ゼロ」で現場作業者が作れる）に対し、
撮影補助（構図確認のグリッド・経過時間・前後カメラ・中断再開）は
**撮影の失敗（撮り直し）を減らし現場作業者の負荷を下げる**直接的な寄与がある。

## v1 スコープ判定（設計の最初の作業）

doc/10 の v1 スコープ（§10 冒頭・§10.5〜§10.8）は「字幕のみ / PWA 同一オリジン /
自前 ffmpeg / 単一 Default Project」を確定事項とするが、撮影補助機能の個別採否には
言及がない。判定基準は **(a) 技術的容易性**（既存録画ロジックを壊さず追加できるか）と
**(b) v1 中核価値への寄与**（撮り直し削減・詰み回避）。doc/05 §5.2 は各機能を明示要件
として列挙している。

| # | 機能 | 技術的容易性 | v1 判定 | 根拠 |
|---|------|------------|--------|------|
| 1 | 一時停止 / 再開 | 高（`MediaRecorder.pause()/resume()`。単一 blob 継続） | **v1 採用** | §5.2 が録画制御の中核として明示。長手順の分割撮影で撮り直しを防ぐ |
| 2 | グリッド表示 | 高（CSS overlay。字幕 overlay と同居） | **v1 採用** | 構図（ヒキ / ヨリ）確認を助け撮影品質を底上げ。軽量 |
| 3 | カメラ反転 | 中（`getUserMedia({facingMode})` 再取得。**idle 時のみ**） | **v1 採用（idle 限定）** | 手元 / 対象で前後を使い分ける現場要件。録画中切替は take 分断＝§5.2「同一テイク継続」に反するため idle 限定 |
| 4 | 録画タイマー | 高（経過ms を mm:ss 表示。pause 中は停止） | **v1 採用** | 尺の把握。pause を含む実録画時間の可視化 |
| 5 | 横持ち全画面 + スワイプ撮影UI | 低（全画面レイアウト刷新 + ジェスチャ + 下部サムネイル再生の全面改修） | **out-of-scope（将来）** | §5.2 の中でも大掛かりな UI 刷新。brief も明示除外。既存の縦持ち詳細画面内撮影で v1 の撮影フローは成立済み |

→ **1〜4 を v1 採用、5 は out-of-scope（将来）**。全 out-of-scope ではないため設計・実装を行う。
いずれも「既に実装済み」ではない（現状 start/stop + 字幕トグルのみ）。

## 優先順位（実装密度の指針）

- **中核（v1 の主効果）**: 一時停止 / 再開、カメラ反転（idle 限定）。
- **補助（従効果）**: グリッド表示、録画タイマー。
UI 密度・実装順序ともこの優先度に従う（補助は後ろに倒しても価値毀損が小さい）。

## 改善アイデア

`CameraRecorder.svelte` に v1 採用の 4 機能を、既存の録画ロジック
（MediaRecorder / upload-queue / カメラ非対応フォールバック / preview 排他の
phase マシン）を壊さずに追加する。

- **一時停止 / 再開**: phase マシンに `paused` と過渡状態 `pausing` / `resuming` を追加
  （Codex R3。`recording → pausing → paused` / `paused → resuming → recording`）。
  `recorder.pause()/resume()` を呼び、`onpause` / `onresume` イベントで過渡→確定へ遷移する。
  同期例外時は遷移前 phase へ戻す。`pausing` / `resuming` 中は再操作・preview・カメラ切替を
  拒否（過渡状態を独立 boolean で散在させず phase union で表現）。`onstop` は全 phase から
  最終的に `idle` へ収束する。
  chunks は単一の録画に蓄積され `onstop` で 1 つの blob（＝同一テイク）になる。
  実録画時間は **`performance.now()` ベースのセグメント累積**で計測（pause 中を除外）し、
  これを唯一の source of truth として `onCaptured` の durationMs とタイマー表示の
  両方に使う。
  - **capability degrade**: `typeof MediaRecorder.prototype.pause === "function"`
    が false の端末では一時停止ボタン自体を出さない（録画→停止のみ）。判定は
    camera.ts の純関数 `supportsPauseResume()`（`pause` と `resume` の**両方**を確認）。
- **グリッド表示**: 新規 presentational コンポーネント `GridOverlay.svelte`
  （features/capture、SubtitleOverlay と同階層・同パターン）。三分割ガイド線を
  DS token（`bg-surface` + 透過）で描画。トグル既定 OFF。字幕 overlay と共存
  （両者 `pointer-events-none absolute inset-0`）。
- **カメラ反転**: `facingMode` state（`"environment" | "user"`、既定 environment）。
  idle 時のみトグル可（録画中 / 一時停止中は反転ボタンを描画しない＝phase 別の
  コントロール切替。既存の「idle→録画開始 / recording→停止」と同じ設計で、
  disabled 化ではない＝禁止事項 8 に非抵触）。
  - **切替リカバリの段階方式（非破壊 / Codex R1-Critical・R2-Critical）**: 単一カメラ占有端末で
    旧 stream 保持のまま新 getUserMedia が資源競合失敗するケースに備え、段階的に切替・復旧する:
    1. **acquire-then-swap**: 先に新 facingMode で `{ facingMode: { exact: target } }` を試行し、
       取得後に `track.getSettings().facingMode`（無ければ deviceId 変化）で**切替成立を検証**した上で、
       成功なら旧 stream を stop して差替える（同時利用可能端末はこれで完了）。切替不成立は取得失敗と
       同じリカバリ経路へ流す。初回取得は従来どおり緩い `environment` 指定のまま。
    2. **資源競合系失敗**（`NotReadableError` / `AbortError`）なら **旧 stream を stop してから**
       新 facingMode を取得する。
    3. 新 facingMode の取得も失敗したら **旧 facingMode を再取得**（現行カメラを復旧）し、
       facingMode を rollback + inline エラー表示（`CameraSwitchError`、recoverable）。
    4. **旧カメラの再取得にも失敗した場合に限り** `onCameraUnavailable` へ流す
       （ここまで来たら現行カメラも失われており、恒久フォールバックが正しい）。
    → 保証は「必ず保持」ではなく「可能なら保持・必要なら復旧」。recoverable な切替失敗は
    `onCameraUnavailable` に混ぜず `CameraSwitchError` としてローカル表示に閉じる。
  - **capability degrade（ヒントに留める / Codex R2）**: `enumerateDevices()` の `videoinput` が
    2 未満なら反転ボタンを出さない。ただしこれは **UI ヒント**であり切替可否の真実源にしない
    （権限取得前は enumerateDevices が不完全なため）。初回カメラ取得成功後に再評価 +
    `devicechange` イベントで更新。実行時の段階リカバリが最終防御。判定は `hasMultipleVideoInputs()`。
- **録画タイマー**: recording 中のみ `setInterval`（表示更新トリガーのみ）で mm:ss を更新、
  pause で停止、stop / destroy でクリア。**表示値は下記セグメント累積から派生**（setInterval を
  時間計測の真実源にしない＝バックグラウンド / 負荷でのズレを避ける）。
- **セグメント累積の境界（Codex R2）**: セグメント境界は **`recorder.onpause` / `recorder.onresume`
  イベント**で開閉する（ボタン押下時刻ではなく実 pause/resume 時刻を `performance.now()` で記録し
  遅延混入を避ける）。`onstop` では recording 状態の未確定セグメントのみ加算し、二重加算しない
  不変条件をテストで固定する。実行時の phase 確定は `MediaRecorder.state` と pause/resume
  イベントに基づき、同期例外は recoverable として扱う。過渡状態を独立 boolean で散在させず
  phase マシン + MediaRecorder イベントで遷移を確定する。

## 期待効果

- **撮り直し率の低下・詰み回避・テイク継続性の維持**（構図グリッド・前後カメラ・
  中断再開）→ 現場作業者の撮影負荷軽減（使命「専門知識ゼロでもマニュアル動画」への寄与）。
- pause を含む実録画時間の正確な計測（現状 `Date.now()-startedAt` の壁時計では
  pause 導入時に過大計上になる。`performance.now()` セグメント累積で take の
  `duration_ms` を正確化）。

## 型安全性の方針（Codex R1）

- `camera.ts` に union を固定: `CapturePhase = "idle" | "recording" | "pausing" | "paused" |
  "resuming" | "stopping"`（過渡状態 pausing/resuming を含む・Codex R3）、
  `FacingMode = "environment" | "user"`。既存の preview 再取得 boolean `resuming` は
  phase の `resuming` と衝突するため **`previewResuming` にリネーム**し概念を分離
  （`active = starting || previewResuming || phase !== "idle"`）。
- phase 依存の可否判定を**純関数**へ集約しユニットテスト対象にする:
  `canPause(phase)` / `canResume(phase)` / `canStop(phase)` / `canSwitchCamera(phase)` /
  `oppositeFacingMode(mode)`。TypeScript strict + exhaustive switch を前提。
- 恒久失敗（`onCameraUnavailable(CameraUnavailableReason)`）と recoverable な
  切替失敗（`CameraSwitchError`）を**型で分離**し、同じ error shape に混ぜない。

## 実装方針（概要）

- 変更: `CameraRecorder.svelte`（phase 拡張 + facingMode + timer + grid 配線 + UI）。
- 追加: `GridOverlay.svelte`（features/capture、無状態 presentational）。
- 追加: `camera.ts` に `CapturePhase` / `FacingMode` union、`oppositeFacingMode()` /
  `canPause/canResume/canStop/canSwitchCamera()` 純関数、`supportsPauseResume()` /
  `hasMultipleVideoInputs()` capability 判定。
- アイコン: Lucide のみ（`Pause` / `Play` / `SwitchCamera` / `Grid3x3`）。
- 既存の props / export（`onCaptured` / `onCameraUnavailable` /
  `onCaptureActiveChange` / `releaseForPreview` / `resumeAfterPreview`）と
  親 `Capture/Show.svelte` の配線は非破壊（追加のみ）。
- `active = starting || resuming || phase !== "idle"` の不変条件は維持
  （`paused` も phase !== idle なので active=true → preview 排他が正しく効く）。

## 制約・前提

- **同一テイク継続**: pause/resume は単一 MediaRecorder セッション。onstop は 1 回・
  blob 1 つ。facingMode 切替は idle 限定でテイクを分断しない。
- **フォールバック非破壊**: `CaptureFileFallback` / upload-queue / idb には触れない。
- **preview 排他（非回帰観点・Codex R1）**: 以下 3 点を回帰項目として固定する。
  (a) **paused 中は preview を開けない**（`releaseForPreview` の early-return が
  `phase !== "idle"` で paused を含む＝解放拒否）。
  (b) **paused も captureActive=true**（`active = ... || phase !== "idle"` により親
  `TakeStrip` の preview 抑止が効く）。
  (c) **stop は idle にのみ遷移**（`onstop` が唯一の idle 遷移点）。
  → `releaseForPreview` / `resumeAfterPreview` の条件は追加変更不要（paused を包含済み）。
- **safeStop**: `paused` からの停止を許可（guard を `recording | paused` に拡張）。
  `pause → stop` と `pause → resume → stop` の両系統を phase テストの最重要ケースとして固定する。
- **DESIGN.md / Atomic Design**: DS token のみ・Lucide のみ・features/capture の
  責務分離順守（GridOverlay は atom/molecule を逆流しない presentational）。
- **禁止事項 8**: 必須条件未充足での disabled UI を作らない。字幕トグルの前例
  （raw button + aria-pressed）を踏襲。

## スコープ外

- **横持ち全画面 + 左右スワイプ手順移動 + 下部サムネイル即再生の撮影 UI 全面刷新**
  （doc/05 §5.1・§5.2）。将来対応。理由: 大掛かりな UI 改変で v1 中核価値
  （撮影して素材を集める）は既存縦持ちフローで成立済み。
- 録画中の facingMode 切替（テイク分断を伴うため）。idle 時のみ。
- 撮影ガイド（電球アイコンの構図指示テキスト）・ナレーション試聴（TTS は v1 対象外）。
- PC 側 / バックエンド API / DTO の変更（本件はフロント撮影 UI のみ）。
