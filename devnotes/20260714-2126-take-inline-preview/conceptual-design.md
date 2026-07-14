# 概念設計: take-inline-preview（テイクのインラインプレビュー再生 + 字幕トグル）

## 背景・課題

ユースケース・カバレッジ監査ギャップ #5（Medium）。

doc/04「テイクのプレビュー / 選択画面」・doc/05「個別再生」は、**採用前にテイクを中央でプレビュー再生**し、
表示制御（字幕等）を ON/OFF しながら「見て採用」を 1 画面で行うことを求める。

現状 `resources/js/components/features/capture/TakeStrip.svelte` には:

- **プレビュー再生手段が存在しない**。唯一の再生系動作は「ダウンロード」ボタンの
  `downloadAndAck()` で、これは `window.open(take.playback_url, "_blank")` により
  **採用テイクの署名 URL を別タブで開く**（= 端末保存目的の DL フロー、doc/05）。
- 非採用テイクは `playback_url` が `null`（`CaptureCutData::fromCut` が採用テイクのみ URL を付与）
  のため、**採用前のテイクは一切再生確認できない**。「見て採用」が成立しない。
- 字幕を重畳して構図確認するトグルが無い。

### 仕様の精読と「再生対象 / トグル対象」の確定

| 論点 | 確定 | 根拠 |
|------|------|------|
| 再生対象 | **(a) テイク単体の生映像** | doc/04 L49「テイク一覧から選んで中央プレビューで再生確認」/ doc/05 L56「テイクをタップ→再生画面へ」。合成プレビュー（全体連結）は別機能で既存（`render-jobs/{renderJob}/playback` 302 経路） |
| 字幕トグル | **v1 対象**。cut の `subtitle_primary` / `subtitle_secondary` を映像上に overlay 表示/非表示 | doc/04 L51「プレビューにナレーション/字幕を ON/OFF」/ doc/05 L56「字幕の ON/OFF」。字幕データは既に Inertia payload（`CaptureCut.subtitle_primary/secondary`）に供給済み |
| ナレーション音声トグル | **out-of-scope（v1）** | v1 スコープは「字幕のみ / TTS 後回し」（AGENTS.md, doc/10 §冒頭）。合成ナレーション音声トラックが存在せず、**切り替える音源が無い**。テイク生映像に含まれる録画時の環境音は native `<video controls>` の音量/ミュートに委ね、専用「ナレ ON/OFF」トグルは作らない（過剰実装回避） |
| 初期表示状態 | **字幕 overlay = 初期 ON（撮影 PWA の正式決定）** | 本画面は撮影 PWA（doc/05）。doc/05 L56「デフォルト両方 ON」を採る。doc/04 は「初期オフ」だが PC 編集画面向けで対象外。**source of truth 未整合（Codex W7）**: 「撮影 PWA は初期 ON」を本設計の正式決定とし、doc/04 と doc/05 の差分解消は別途 doc 更新 TODO（本実装スコープ外） |

## 改善アイデア

TakeStrip の各 **ready テイク**に「再生」ボタンを追加し、押下でインラインの
**フルスクリーン相当のプレビュー dialog（`<video controls>` + 字幕 overlay + 字幕トグル）**を開く。
dialog 内に「採用」ボタンを同居させ、「見て採用」を 1 画面で完結させる。
構図確認が目的のため、video は画面幅いっぱい（モバイルで最大表示）に置く（Codex W1）。

再生ソースは、非採用テイクでも取得できるよう **新規 per-take playback エンドポイント**
（`GET .../takes/{take}/playback` → 302 署名 URL）を追加する。これは既存の
`render-jobs/{renderJob}/playback`（`ManualRenderController::playback`）と同型で、
署名 URL を再生時にオンデマンドで発行する。

## 期待効果

- 使命への貢献: 「編集ゼロ」の中核である**テイク選定を、別タブ遷移なく 1 画面で**行える。
  現場作業者が採用前に構図/字幕を確認でき、標準化マニュアルの品質担保に直結。
- 「見て採用」導線の完成（監査ギャップ #5 の解消）。
- **非採用テイクの preview 用署名 URL を Inertia payload に増やさず**、再生時にのみ発行する
  （Codex W4: 採用テイクの DL 用 `playback_url` は現状維持のため露出削減は「非採用 preview に限る」）。

## 実装方針（概要）

1. **バックエンド: per-take プレビュー再生エンドポイント（新規）**
   - `GET /app/projects/{project}/manuals/{manual}/cuts/{cut}/takes/{take}/playback`
     を `capture.` route group（`scopeBindings`）に追加。
   - `CaptureTakeController::playback()`: URL 整合 guard（認可前 404）→ `Gate::authorize`
     （TakePolicy に `preview` ability 追加、`capture` へ委譲）→ **再生可能条件 `status===Ready`
     以外は early-return 404** → `redirect()->away($storage->temporaryPlaybackUrl($take->video_path))`。
   - 対象カラムは `Take::video_path`（`@property string $video_path` = 非 null string カラム。
     Round 1 の `object_path` は誤記）。非 null のため `temporaryPlaybackUrl(string)` に
     PHPStan L10 の型絞り込み問題は生じない。
   - 応答に **`Cache-Control: no-store, private` を付与**（Codex R2-W5: これが防ぐのは
     **アプリの 302 応答（署名 URL）の再利用**のみ。リダイレクト先ストレージの動画本体の
     cache までは保証しない — 動画本体の非キャッシュは v1 要件外でスコープ外）。
   - **セキュリティ不変条件**: nested route IDOR 防御のため
     `NestedRouteIdorDefenseTest` の inventory に `capture.takes.playback` を登録。
   - payload（`CaptureCutData` / TS 型）は**変更しない**（URL は route から組み立て。
     `CaptureManualBrowsingTest` のキー契約に影響なし）。

2. **フロント: インラインプレビュー UI**
   - 新規 `TakePreviewDialog.svelte`（`components/features/capture/`）:
     `<video controls src={playbackUrl}>` + 字幕 overlay（primary=上、secondary=下帯）
     + 字幕トグルボタン + 採用ボタン。字幕 overlay は初期 ON。
   - `TakeStrip.svelte`: ready テイクに「再生」ボタン（Lucide `Play`）を追加し、
     押下で dialog を開く。playback URL は既存 `takeUrl(take, suffix)` ヘルパ
     （adopt/downloaded/destroy と同一規約）で `takeUrl(take, "/playback")` として組み立てる
     （Codex W8: 既存確立済み規約を踏襲。新規 URL builder は導入しない = 過剰実装回避）。
     採用は既存 `adopt()` を dialog から呼ぶ。
   - **採用後の state teardown（Codex W5）**: 採用成功 → dialog close + video teardown +
     `onChanged()`（Inertia reload）。失敗 → dialog 内エラー表示（既存 `run()` の error 流用）。
     video teardown は `video.pause()` に加え `src` 除去 + `load()` で通信・デコーダ資源も解放
     （Codex R2 Suggestion）。
   - **録画との資源競合回避 — 概念契約（Codex W3 / R2-W3）**: preview と録画は排他とする。
     (a) **録画中に再生ボタンを押した場合はプレビューを開かず押下時にエラー表示**する
     （禁止事項8: disabled にしない）。(b) **録画待機中のみ** dialog open 時に recorder の
     live stream を停止/解放し、close 時に再取得する。(c) recorder の**録画データを暗黙に
     終了・破棄しない**（録画中は上記 (a) で保護）。停止/復帰の具体 API は詳細設計で
     CameraRecorder との結合として定義。
   - DL ボタン（`downloadAndAck` の window.open）は**据え置き**（端末保存 = doc/05 の別機能）。

3. **テスト（テストファースト: 失敗テスト → IDOR inventory 更新 → 実装 の順。Codex W2）**
   - vitest:
     - 再生ボタン→player 表示 / 字幕トグルで overlay 表示・非表示。
     - preview が `window.open` 非依存（video element 使用）。
     - **録画排他（Codex R3-W2）**: (a) 録画中の再生押下では dialog を開かずエラー表示、
       (b) recorder の録画終了/破棄処理を呼ばない、(c) 録画待機中の open では stream を解放し
       close 後に再取得する。
     - **video teardown（Codex R3-W5）**: dialog close 経路と採用成功経路の**両方**で
       video teardown（`pause()`+`src` 除去+`load()`）が呼ばれる。teardown は単一関数に集約し検証容易にする。
   - Pest: playback エンドポイントの 302 + **`Cache-Control` に `no-store` と `private` の両 directive**
     （Codex R3-W8）/ 非 capture 403 / 非 ready 404 /
     **IDOR: project mismatch・manual mismatch・cut mismatch を各 404 で個別固定**（Codex W9）/
     **署名 URL が対象 take の `video_path` から生成されること**（FakeTakeObjectStorage で別 take の
     path を使わないことを検証。Codex R2-W8: take とオブジェクトの取り違え防止）。
     `NestedRouteIdorDefenseTest` inventory に `capture.takes.playback` 追加。

## 制約・前提

- `<video controls>` を用いるため、字幕は timed track ではなく cut 固定字幕の**全編 overlay**
  （doc の「構図確認用の字幕重畳」と整合）。
- 署名 URL TTL は既存 `config capture.playback_url_ttl_minutes` を再利用。
- Atomic Design / import graph: dialog は `features/capture`（domain feature）に配置。
  アイコンは `@lucide/svelte` のみ。DS token 経由の配色（hex 直書きしない）。
- PHPStan L10 / DTO・JsonResource パターン / RefreshDatabase（グローバル）を遵守。

## スコープ外

- **ナレーション音声トグル / TTS 音声再生**（v1 は字幕のみ・TTS 後回し）。
- 合成（全体連結）プレビューの再生（既存 render-jobs playback が担当）。
- テイクごとの timed caption（VTT）や多言語字幕切替（doc/04 の多言語は PC 編集側の後続）。
- DL（端末保存）フローの window.open 置換（プレビューとは別用途のため温存）。
- doc/04（PC 編集画面）側の同等 UI（本件は撮影 PWA `Capture/Show` に限定）。
