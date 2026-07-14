# 使命・禁止事項・思考原則（全レビューに適用）

## アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

v1 スコープ: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

## 禁止事項

1. テストなしの実装完了報告
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作をエージェント判断で実行
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う)
5. LLM 呼び出しの Prism 直呼び
6. prompt 文字列のコード直書き
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)

## 思考原則

まず仮説を立てろ。ユーザー視点で考えろ。先人の知恵（Laravel/Svelte エコシステム）を探せ。機能の名前に立ち返れ。今必要なものだけ作れ（オーバーエンジニアリング禁止）。

## ツール使用制限

コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

# system: レビュアー役割

あなたはWebアプリケーション（Laravel + Svelte）の改善に関する概念設計レビュアーです。

【レビュー観点】
1. 使命との整合性: この改善はアプリの使命（North Star）に本質的に貢献するか
2. 禁止事項違反: 上記禁止事項に抵触していないか
3. 実現可能性: 技術的に実現可能か（Laravel 12 + Svelte 5 + Inertia.js。本件はフロント完結）
4. 期待効果の妥当性: 主張している効果は合理的に期待できるか
5. リスク: 重大な副作用・後退の可能性はないか（既存 MediaRecorder/preview 排他/字幕/フォールバックを壊さないか）
6. スコープの適切さ: 過大または過小になっていないか。**特に v1 スコープ判定（doc/05 の各補助機能を v1 採用 / 将来送りに切り分けた判断）が妥当か**を重点的に評価せよ
7. 型安全性: TypeScript / Svelte 5 runes の観点で破綻がないか

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

---

# user: 概念設計

本件はフロントエンド（Svelte 5 + TypeScript）完結の撮影 UX 拡充。既存の関連実装は次の通り:

- `CameraRecorder.svelte`: MediaRecorder で録画。phase マシン `idle | recording | stopping`。公開 `active = starting || resuming || phase !== "idle"` を親へ通知し preview（TakePreviewDialog）と排他制御（T050）。恒久失敗は `onCameraUnavailable(reason)` で親のファイル選択フォールバックへ委譲（F-03）。字幕 overlay トグルを内包（T047）。`onCaptured(blob, mimeType, durationMs)` で親へ blob を渡す。`getUserMedia({ video: { facingMode: "environment" }, audio: true })`。
- `SubtitleOverlay.svelte`: `pointer-events-none absolute inset-0` の字幕ガイド overlay。
- `camera.ts`: `supportsMediaRecorder()` / `preferredRecordingMimeType()` / `classifyGetUserMediaError()`。

以下が概念設計の全文:

（別添 conceptual-design.md を参照。以下に全文を貼付）

---
# 概念設計: capture-ux-enrichment（撮影UXの拡充 ※v1スコープ判定込み）

## 背景・課題

ユースケース・カバレッジ監査ギャップ #8（Medium）。`doc/05 §5.2 撮影 UI` は撮影補助として次を挙げる:

1. 録画の**一時停止/再開**（同一テイク継続）
2. **グリッド表示**切替
3. **カメラ反転**（イン/アウト）
4. **録画時間タイマー**（00:00）
5. 横持ち全画面撮影 + スワイプで手順前後 + 下部サムネイル即再生

現状 `resources/js/components/features/capture/CameraRecorder.svelte` は録画開始/停止（+ 字幕オーバーレイ T047・preview 排他 T050・フォールバック F-03）のみで、上記 1〜5 が無い。使命（「思考ゼロ・編集ゼロ」で標準化されたマニュアル動画を作る）に対し、撮影補助は「良い構図・適切な尺の素材を、迷わず撮れる」ことに寄与する中核 UX の一部。

## v1 スコープ判定（doc/05 §5.2 × doc/10 v1 スコープ）

`doc/10` の v1 スコープは「字幕のみ / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project」。§10.8-3 は MediaRecorder + フォールバックを v1 必須と明記。撮影補助機能そのものは doc/10 で明示的に列挙されないが、`doc/05 §5.2` は撮影 UI の確定仕様であり、下記のうち軽量なものは v1 中核 UX（撮影の迷いを減らす）に直接寄与する。各機能を1つずつ判定する:

| # | 補助機能 | 技術容易性 | v1中核価値への寄与 | 判定 |
|---|---------|-----------|------------------|------|
| 1 | 一時停止/再開（同一テイク継続） | 高（`MediaRecorder.pause()/resume()` は標準 API） | 高（doc/05 が撮影 UI の**主要挙動**として明記。中断してテイクを分断せず継続撮影できる） | **v1 採用** |
| 2 | グリッド表示 overlay | 高（純 CSS overlay。字幕 overlay と同居可能） | 中〜高（三分割構図の補助 → 素材品質の底上げ。使命の「撮影者スキルに品質を依存させない」に合致） | **v1 採用** |
| 3 | カメラ反転（in/out = facingMode） | 中（`getUserMedia({facingMode})` トグル。録画中の切替は stream 再取得が必要で複雑 → **idle 時のみ**に限定して軽量化） | 中（撮影対象に応じ前後カメラを選ぶ。手元/対面の撮り分け） | **v1 採用（idle 時のみ切替）** |
| 4 | 録画時間タイマー（00:00） | 高（`startedAt` は既存。経過を MM:SS 表示。pause で停止） | 中（尺の見当をつけて撮る。長すぎ/短すぎの抑止） | **v1 採用** |
| 5 | 横持ち全画面 + スワイプ手順前後 + 下部サムネイル即再生 | 低（全画面・向き検知・スワイプ・レイアウト全面刷新の大掛かりな UI 再設計。cut 遷移は既存 `CutNavigator` と重複領域） | 中（あれば便利だが縦持ち撮影で v1 の撮影は成立する） | **out-of-scope（将来）** |

**結論**: 軽量かつ中核 UX に寄与する 1〜4 を v1 で実装。5（横持ち全画面 UI の全面刷新）は思考原則「今必要なものだけ作る（オーバーエンジニアリング禁止）」に従い out-of-scope とし、本設計では明示的に切り出す。→ **implement_needed = true**（4 施策を実装対象として残す）。

## 改善アイデア（v1 採用分）

`CameraRecorder.svelte` に、既存録画ロジック（MediaRecorder・upload-queue・フォールバック F-03・字幕 overlay T047・preview 排他 T050）を壊さずに 4 機能を追加する:

- **一時停止/再開**: phase マシンに `paused` を追加。`recording` 中に「一時停止」→ `recorder.pause()` で phase=`paused`、「録画再開」→ `recorder.resume()` で phase=`recording`。停止すると同一テイクとして 1 本の blob に確定（pause 中の時間は MediaRecorder が録画に含めない）。
- **グリッド overlay**: 新規 `GridOverlay.svelte`（features/capture、SubtitleOverlay と同層・同居）。三分割罫線を `pointer-events-none absolute inset-0` で重畳。トグルボタン（既定 OFF）。DS token のみ使用。
- **カメラ反転**: `facingMode` を `$state<"environment"|"user">`（既定 environment）。トグルボタンは **idle 時のみ**機能（録画中の stream 再取得を避ける）。idle で live preview stream があれば release → 新 facingMode で再取得。
- **録画タイマー**: 累積経過時間（pause 対応）を MM:SS で overlay 右上に表示（recording/paused 時）。`camera.ts` に純粋関数 `formatElapsed(ms)` を追加してユニットテスト可能にする。

## 期待効果

- **使命への貢献**: 「撮影者スキルに品質を依存させない」— グリッドで構図、タイマーで尺、pause/resume で分断のない継続撮影、カメラ反転で対象に応じた撮り分け。素材の質と撮影体験の底上げ。
- **doc/05 §5.2 の撮影 UI 確定仕様への準拠度向上**（監査ギャップ #8 の解消。横持ち全画面のみ将来送り）。
- 既存の字幕 overlay・フォールバック・preview 排他・upload-queue を**一切壊さない**（追加のみ、後方互換）。

## 実装方針（概要）

- 変更: `resources/js/components/features/capture/CameraRecorder.svelte`（phase に paused 追加、facingMode state、grid トグル、timer、pause/resume ボタン）。
- 変更: `resources/js/lib/capture/camera.ts`（`formatElapsed(ms)`・facingMode 型/トグルの純粋ヘルパ追加）。
- 新規: `resources/js/components/features/capture/GridOverlay.svelte`（三分割 overlay。SubtitleOverlay を先例に踏襲）。
- テスト: `tests/js/lib/capture/camera.test.ts`（formatElapsed / facingMode トグル）、`tests/js/components/features/capture/CameraRecorder.test.ts`（pause/resume 状態遷移、grid トグル、facingMode 切替、timer 表示。既存ケースは無改変）、新規 `GridOverlay.test.ts`。
- アイコン: `@lucide/svelte` のみ（Pause / Play / Grid3x3 / SwitchCamera / Timer）。存在確認済み。
- DESIGN.md / Atomic Design 準拠（features/capture 層内、DS token、SVG 直書きなし）。

## 制約・前提

- **禁止事項8（必須条件未充足での disabled 禁止）**: グリッド/字幕トグルは字幕が空でも disabled にしない（押下で状態遷移）。カメラ反転は録画中に機能しないが、これは「必須条件未充足でボタンを押せなくする」ではなく**文脈非該当コントロールの非表示**で扱う（idle 時のみ描画）。
- **preview 排他（T050）不変条件を保持**: 公開 `active = starting || resuming || phase !== "idle"`。paused は非 idle のため active=true → preview を開けない（正しい）。
- **durationMs の意味**: pause 対応後、`onstop` の durationMs は wall-clock（Date.now() 差）ではなく**累積録画時間**を使う（pause 中の時間を除外）。既存テストは `typeof durationMs === "number"` のみ検証のため後方互換。
- **timer の interval**: recording 中のみ稼働、pause/idle/onDestroy で必ず clear（リーク防止）。
- Svelte 5 runes + DS token/ramp のみ（ds-purity テスト）。component 階層は features/{domain} 単方向 import。

## スコープ外

- **横持ち全画面撮影 + スワイプ手順前後 + 下部サムネイル即再生**（doc/05 §5.2 の 5）: 全画面・向き検知・スワイプ・レイアウト全面刷新の大掛かりな UI 再設計。v1 は縦持ち撮影で成立するため将来送り。
- 録画中のカメラ反転（stream 再取得を伴う mid-take 切替）。idle 時切替のみ v1。
- TTS・音声ナレーション試聴（doc/05 の別項目、v1 は字幕のみ）。
- サーバ側・API・DTO の変更（本施策はフロントエンド完結。録画データ契約 `onCaptured(blob, mimeType, durationMs)` は不変）。
