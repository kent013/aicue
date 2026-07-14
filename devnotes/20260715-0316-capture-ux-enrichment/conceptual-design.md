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

### v1 内優先度（Codex R1 反映）

1〜4 を一律「軽量」とせず、端末差分・退行リスクで層別する:

- **core（退行リスク小・使命寄与大）**: 1 一時停止/再開・2 グリッド・4 タイマー。
- **guarded（端末差分あり・失敗時 rollback 必須）**: 3 カメラ反転。idle 時のみ・新 stream 取得成功後に差し替え・失敗時は旧 stream 維持・失敗を F-03 に流さない（transient 表示のみ）。v1 には残すが実装は guarded に切る。

## 改善アイデア（v1 採用分）

`CameraRecorder.svelte` に、既存録画ロジック（MediaRecorder・upload-queue・フォールバック F-03・字幕 overlay T047・preview 排他 T050）を壊さずに 4 機能を追加する:

- **一時停止/再開（core）**: `type Phase = "idle" | "recording" | "paused" | "stopping"` を単一ソース union 化。`recording` 中に「一時停止」→ `recorder.pause()`、「録画再開」→ `recorder.resume()`。停止すると同一テイクとして 1 本の blob に確定（pause 中の時間は MediaRecorder が録画に含めない）。**phase 確定はイベント基準（R2 反映）**: 押下は「要求」に留め、phase の paused/recording 確定は `recorder.onpause` / `recorder.onresume` イベント到達で行う。操作要求中の in-flight フラグ（pausing/resuming 相当）で多重押下をガード。`onerror` / 予期しない `onstop` / イベント未到達時は `recorder.state`（inactive→idle / paused→paused / recording→recording）から UI phase を復旧する。**能力差分の前提化**: `camera.ts` に `supportsPauseResume()`（`MediaRecorder.prototype.pause/resume` の typeof 検査。**API 存在確認であって正常動作保証ではない**旨を明記、実行時失敗への退行が最終防御）を追加。未対応端末では一時停止ボタンを**非表示**（従来の start/stop のみに退行、disabled にしない=禁止事項8非該当）。実行時に pause/resume が失敗（InvalidStateError 等）したら phase を `recorder.state` から復旧し、以降その take は従来 start/stop 挙動に倒す。
- **グリッド overlay（core）**: 新規 `GridOverlay.svelte`（features/capture、SubtitleOverlay と同層・同居）。三分割罫線を `pointer-events-none absolute inset-0` で重畳。トグルボタン（既定 OFF）。DS token のみ使用。**overlay z 順規約**: 映像 < grid < 字幕帯。罫線は DS token の半透明細線（`border-surface/40` 相当）。字幕帯（`bg-text/70`）と重なっても字幕優先で可読。
- **カメラ反転（guarded）**: `type FacingMode = "environment" | "user"` を `camera.ts` に定義、`facingMode` を `$state<FacingMode>`（既定 environment）。トグルは **idle 時のみ**機能。**3 段の段階的縮退（R2 反映。カメラ二重取得不可端末に対応）**:
  1. まず既存 video track の `applyConstraints({ facingMode: { exact: target } })` を試し、resolve 後に `track.getSettings().facingMode` が target と一致するか**検証**（applyConstraints の resolve は実切替を保証しないため。R3 反映）。一致=同一 stream 維持で終了。
  2. 不一致/確認不能時のみ旧 stream を停止 → 新 facingMode で再取得。成功で差し替え終了。
  3. 新取得失敗 → **旧 facingMode で再取得して復旧**。成功なら flip 断念（元カメラで撮影継続、transient 表示のみ）。
  4. 旧 facingMode 再取得も失敗（= stream 完全喪失）→ その reject を `classifyGetUserMediaError()` で分類し、**恒久失敗なら `onCameraUnavailable(reason)`（F-03 委譲。撮影不能の詰みを防ぐ）、一時失敗なら transient 表示 + idle（再試行可能）**。
  - 要点（R3 反映）: 「flip 自体の不成立（元カメラ生存）」は local に留め、「カメラ完全喪失」は既存 classify 経由で F-03/transient に正しく振り分ける（flip 初回失敗の非 F-03 と、最終カメラ喪失時の F-03 委譲を分離）。
  - idle で live stream が無い場合（まだ録画前）は `facingMode` state のみ更新し次回 `getUserMedia` に反映。「旧 stream を維持したまま新 stream を必ず取得できる」前提は撤回。
- **録画タイマー（core）**: 累積経過時間（pause 対応）を MM:SS で overlay 右上に表示（recording/paused 時）。累積計測は **`performance.now()` ベース**（system 時計巻き戻し耐性）で recording 区間を加算。表示更新の interval handle は `ReturnType<typeof setInterval>` 型で保持し recording 中のみ稼働、pause/idle/onDestroy で必ず clear。`camera.ts` に純粋関数 `formatElapsed(ms): string` を追加してユニットテスト可能にする。

## 期待効果

- **使命への貢献**: 「撮影者スキルに品質を依存させない」— グリッドで構図、タイマーで尺、pause/resume で分断のない継続撮影、カメラ反転で対象に応じた撮り分け。観測可能な仮説としては**再撮影率の低下・1 テイクの分断減少・撮影途中離脱の低下**（v1 では計測基盤は追加せず、効果は支援であって保証ではない）。
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
- **durationMs の意味（訂正: 後方互換ではなく意味の是正）**: pause 対応後、`onstop` の durationMs は wall-clock（Date.now() 差）ではなく **`performance.now()` ベースの累積録画時間**（pause 中を除外）。これは意味変更だが、**単一消費経路を棚卸し済み**: `onCaptured(_,_,durationMs)` の唯一の実消費は `Capture/Show.svelte#handleCaptured → upload-queue.enqueue({durationMs}) → POST body の duration_ms`（doc/10: `takes.duration_ms int NULL 派生`=テイクの**実録画尺メタ**）。wall-clock に依存する消費は無く、累積録画時間の方が「実録画尺」の意味に**より正確**（=是正）。型は `number` 不変、既存テストは `typeof === "number"` のみ検証。
- **timer の interval**: recording 中のみ稼働、pause/idle/onDestroy で必ず clear（リーク防止）。
- Svelte 5 runes + DS token/ramp のみ（ds-purity テスト）。component 階層は features/{domain} 単方向 import。

## スコープ外

- **横持ち全画面撮影 + スワイプ手順前後 + 下部サムネイル即再生**（doc/05 §5.2 の 5）: 全画面・向き検知・スワイプ・レイアウト全面刷新の大掛かりな UI 再設計。v1 は縦持ち撮影で成立するため将来送り。
- 録画中のカメラ反転（stream 再取得を伴う mid-take 切替）。idle 時切替のみ v1。
- TTS・音声ナレーション試聴（doc/05 の別項目、v1 は字幕のみ）。
- サーバ側・API・DTO の変更（本施策はフロントエンド完結。録画データ契約 `onCaptured(blob, mimeType, durationMs)` は不変）。
