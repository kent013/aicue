# 概念設計レビュー Round 2: capture-ux-enrichment

Round 1 の指摘 4 点（Critical 1 + Warning 3）+ 型安全性 Warning に対応し、概念設計を修正しました。対応マトリクスと修正後の設計を提示します。

## Round 1 指摘への対応サマリー

1. **[Critical] facingMode 切替失敗を恒久フォールバックに流さない** → 対応。
   flip を **acquire-then-swap**（新 facingMode で先に取得成功 → 旧 stream stop → 差替え）に変更。失敗時は旧 stream 保持のまま facingMode rollback + inline エラーのみ。`onCameraUnavailable` へは流さず、recoverable な `CameraSwitchError` として型分離。

2. **[Warning] capability detection と degrade** → 対応。
   pause/resume は `supportsPauseResume()`（`typeof MediaRecorder.prototype.pause === "function"`）非対応で一時停止ボタンを出さない。前後カメラは `hasMultipleVideoInputs()`（`enumerateDevices()` の videoinput < 2）で反転ボタンを出さない + 実行時 rollback の二重防御。判定は camera.ts の関数へ。

3. **[Warning] durationMs の source of truth** → 対応。
   `performance.now()` ベースのセグメント累積を唯一の真実源にし、onCaptured.durationMs もタイマー表示もそこから派生。setInterval は表示更新トリガーのみ（時間計測に使わない）。

4. **[Warning] paused 追加の非回帰観点** → 対応。
   (a) paused 中は preview を開けない (b) paused も captureActive=true (c) stop は idle にのみ遷移 の 3 点を制約・テスト計画に明示。releaseForPreview/resumeAfterPreview の early-return は `phase !== "idle"` で paused を包含済み（追加変更不要）。

5. **[Warning] 型安全性** → 対応。
   `CapturePhase` / `FacingMode` union を camera.ts に固定。`canPause/canResume/canStop/canSwitchCamera(phase)` / `oppositeFacingMode(mode)` を純関数化しユニットテスト対象に。恒久失敗と切替失敗を型分離。

6. **[Suggestion]** 期待効果を「撮り直し率低下・詰み回避・テイク継続性維持」に修正。中核（pause/resume・facingMode）と補助（grid・timer）の優先度を明記。

## 確認したい点

- Critical への acquire-then-swap 設計で「非破壊」要件を満たせているか。
- capability degrade（ボタン非表示）の方針が禁止事項 #8（disabled UI 禁止）と矛盾しないか（＝機能非対応で「そもそも出さない」のは disabled とは別、という理解でよいか）。

問題なければ APPROVED をお願いします。残る懸念があれば Critical/Warning で指摘してください。

---

## 修正後の概念設計（全文）

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

- **一時停止 / 再開**: phase マシンに `paused` を追加。`recorder.pause()/resume()`。
  chunks は単一の録画に蓄積され `onstop` で 1 つの blob（＝同一テイク）になる。
  実録画時間は **`performance.now()` ベースのセグメント累積**で計測（pause 中を除外）し、
  これを唯一の source of truth として `onCaptured` の durationMs とタイマー表示の
  両方に使う。
  - **capability degrade**: `typeof MediaRecorder.prototype.pause === "function"`
    が false の端末では一時停止ボタン自体を出さない（録画→停止のみ）。判定は
    camera.ts の純関数 `supportsPauseResume()`。
- **グリッド表示**: 新規 presentational コンポーネント `GridOverlay.svelte`
  （features/capture、SubtitleOverlay と同階層・同パターン）。三分割ガイド線を
  DS token（`bg-surface` + 透過）で描画。トグル既定 OFF。字幕 overlay と共存
  （両者 `pointer-events-none absolute inset-0`）。
- **カメラ反転**: `facingMode` state（`"environment" | "user"`、既定 environment）。
  idle 時のみトグル可（録画中 / 一時停止中は反転ボタンを描画しない＝phase 別の
  コントロール切替。既存の「idle→録画開始 / recording→停止」と同じ設計で、
  disabled 化ではない＝禁止事項 8 に非抵触）。
  - **acquire-then-swap（非破壊 / Codex R1-Critical）**: トグル時は **先に新 facingMode で
    getUserMedia を試行**し、成功したら旧 stream を stop して差替える。**失敗時は旧 stream を
    保持したまま facingMode を rollback し、inline エラー表示のみ**（現行カメラで撮影継続）。
    この recoverable 失敗は `onCameraUnavailable` へ流さない（恒久フォールバックへ落とさない）。
    失敗分類は既存の `classifyGetUserMediaError` を再利用しつつ、切替失敗は
    `CameraSwitchError`（recoverable）としてローカル error 表示に閉じる。
  - **capability degrade**: `enumerateDevices()` の `videoinput` が 2 未満なら反転ボタンを
    出さない（単一カメラ端末で無意味な UI を出さない）。実行時 acquire 失敗の rollback と
    合わせ二重防御。判定は `hasMultipleVideoInputs()`（非同期・onMount で 1 回評価）。
- **録画タイマー**: recording 中のみ `setInterval`（表示更新トリガーのみ）で mm:ss を更新、
  pause で停止、stop / destroy でクリア。**表示値は上記セグメント累積から派生**（setInterval を
  時間計測の真実源にしない＝バックグラウンド / 負荷でのズレを避ける）。

## 期待効果

- **撮り直し率の低下・詰み回避・テイク継続性の維持**（構図グリッド・前後カメラ・
  中断再開）→ 現場作業者の撮影負荷軽減（使命「専門知識ゼロでもマニュアル動画」への寄与）。
- pause を含む実録画時間の正確な計測（現状 `Date.now()-startedAt` の壁時計では
  pause 導入時に過大計上になる。`performance.now()` セグメント累積で take の
  `duration_ms` を正確化）。

## 型安全性の方針（Codex R1）

- `camera.ts` に union を固定: `CapturePhase = "idle" | "recording" | "paused" | "stopping"`、
  `FacingMode = "environment" | "user"`。
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
