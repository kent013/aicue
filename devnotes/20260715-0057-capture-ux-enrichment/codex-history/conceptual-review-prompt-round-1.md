# 概念設計レビュー依頼: capture-ux-enrichment

## アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(tebiki)と異なり、標準作業を起点に AI が教材設計し撮影を指示する。
- **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

## 禁止事項

1. テストなしの実装完了報告。
2. PHPStan エラーの widen・baseline 化。
3. dev DB への破壊操作。
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う)。
5. LLM 呼び出しの Prism 直呼び。
6. prompt 文字列のコード直書き。
7. 操作系 POST の応答での `redirect()->intended()`。
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)。

## 思考原則 — 全議論に適用
まず仮説を立てろ。データに真摯に向き合え。先人の知恵を探せ。機能の名前に立ち返れ。仕組みが機能していない段階で値を弄るな。

## ツール使用制限
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

## あなたの役割

あなたは Web アプリケーション（Laravel + Svelte）の改善に関する概念設計レビュアーです。

【レビュー観点】
1. 使命との整合性: この改善は使命（North Star）に本質的に貢献するか
2. 禁止事項違反: 上記に抵触していないか（特に #8 disabled UI）
3. 実現可能性: 技術的に実現可能か（Svelte 5 runes + MediaRecorder + getUserMedia）
4. 期待効果の妥当性
5. リスク: 既存録画ロジック（MediaRecorder / upload-queue / preview 排他 phase マシン / カメラ非対応フォールバック）の後退リスク
6. スコープの適切さ: v1 スコープ判定（1〜4 採用・5 除外）は妥当か。過大 / 過小になっていないか
7. 型安全性（TypeScript strict、後述の詳細設計フェーズで PHPStan 相当の型健全性）

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

---

## 補足（現行実装の要点）

`CameraRecorder.svelte` は現状:
- phase マシン `idle | recording | stopping`。外部公開 active = `starting || resuming || phase !== "idle"`（preview との排他制御に使う。取得中の grant 窓も active に含める）。
- `acquirePreviewStream()`: `stream ??= getUserMedia({video:{facingMode:"environment"},audio:true})`。録画開始と preview 復帰で共用。
- `recorder.onstop` が唯一の idle 遷移点。`durationMs = Date.now() - startedAt`。
- `export releaseForPreview()` / `resumeAfterPreview()`: 親 TakeStrip が preview を開く間だけ camera を解放・復帰。early-return 条件は `starting || resuming || phase !== "idle"`。
- 字幕トグル（T047）は raw button + aria-pressed（disabled にしない前例）。
- カメラ非対応フォールバック（§10.8-3）: 恒久失敗は `onCameraUnavailable(reason)` で親がファイル選択 UI へ切替。

本概念設計は上記を非破壊で拡張する。pause 導入に伴い durationMs はセグメント累積（pause 中を除外）へ変更する点、facingMode 切替は idle 限定でテイクを分断しない点が要検討ポイント。

---

## 概念設計

（以下、conceptual-design.md 全文）

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

## 改善アイデア

`CameraRecorder.svelte` に v1 採用の 4 機能を、既存の録画ロジック
（MediaRecorder / upload-queue / カメラ非対応フォールバック / preview 排他の
phase マシン）を壊さずに追加する。

- **一時停止 / 再開**: phase マシンに `paused` を追加。`recorder.pause()/resume()`。
  chunks は単一の録画に蓄積され `onstop` で 1 つの blob（＝同一テイク）になる。
  実録画時間はセグメント累積で計測（pause 中を除外）し、`onCaptured` の durationMs と
  タイマー表示の両方に使う。
- **グリッド表示**: 新規 presentational コンポーネント `GridOverlay.svelte`
  （features/capture、SubtitleOverlay と同階層・同パターン）。三分割ガイド線を
  DS token（`bg-surface` + 透過）で描画。トグル既定 OFF。字幕 overlay と共存
  （両者 `pointer-events-none absolute inset-0`）。
- **カメラ反転**: `facingMode` state（`"environment" | "user"`、既定 environment）。
  idle 時のみトグル可（録画中 / 一時停止中は反転ボタンを描画しない＝phase 別の
  コントロール切替。既存の「idle→録画開始 / recording→停止」と同じ設計で、
  disabled 化ではない＝禁止事項 8 に非抵触）。トグル時は現在の live stream を解放し
  新 facingMode で再取得（既存 `acquirePreviewStream` を facingMode 対応に拡張）。
- **録画タイマー**: recording 中のみ `setInterval` で経過 mm:ss を更新、pause で停止、
  stop / destroy でクリア。表示値は実録画セグメント累積に基づく。

## 期待効果

- 撮り直しの削減（構図グリッド・前後カメラ・中断再開）→ 現場作業者の撮影負荷軽減
  （使命「専門知識ゼロでもマニュアル動画」への寄与）。
- pause を含む実録画時間の正確な計測（現状 `Date.now()-startedAt` の壁時計では
  pause 導入時に過大計上になる。セグメント累積で take の `duration_ms` を正確化）。

## 実装方針（概要）

- 変更: `CameraRecorder.svelte`（phase 拡張 + facingMode + timer + grid 配線 + UI）。
- 追加: `GridOverlay.svelte`（features/capture、無状態 presentational）。
- 追加（任意・テスト容易化）: `camera.ts` に `FacingMode` 型と
  `oppositeFacingMode()` 純関数。
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
- **preview 排他**: `releaseForPreview` / `resumeAfterPreview` の early-return 条件は
  `paused` も撮影中として扱う（`phase !== "idle"` に含まれるため追加変更不要）。
- **safeStop**: `paused` からの停止を許可（guard を `recording | paused` に拡張）。
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
