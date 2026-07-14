【アプリの使命 (North Star) — AGENTS.md より】

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

**v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

【禁止事項 — AGENTS.md より】

1. テストなしの実装完了報告(不変条件は Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作をエージェント判断で実行
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` factory 経由のみ)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST 応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する)

【セキュリティ不変条件（抜粋）】
- tenant キー不信 / 子は親に属する（nested route 不整合は認可より前に 404）/ cross-org 不可 / 権限判定は `laratrust_team_id` 明示。

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功かを明確にしてから手を動かせ。
データに真摯に向き合え。想定外のパターンも判断材料になる。
先人の知恵を探せ（Laravel / Svelte エコシステムの既存解を使う）。
機能の名前に立ち返れ。
仕組みが機能していない段階で値を弄るな。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

あなたはWebアプリケーション（Laravel + Svelte）の改善に関する概念設計レビュアーです。

【レビュー観点】
1. 使命との整合性: この改善はアプリの使命（North Star）に本質的に貢献するか
2. 禁止事項違反: 上記禁止事項に抵触していないか
3. 実現可能性: 技術的に実現可能か（Laravel 12 + Svelte 5 + Inertia.js）
4. 期待効果の妥当性: 主張している効果は合理的に期待できるか
5. リスク: 重大な副作用・後退の可能性はないか
6. スコープの適切さ: 過大または過小になっていないか（特に v1 スコープ「字幕のみ/TTS 後回し」との整合）
7. 型安全性: DTO/JsonResource パターンに沿っているか。PHPStan level 10 を通せるか
8. セキュリティ: 新規 per-take playback エンドポイントの認可・IDOR 防御・署名 URL の扱いが妥当か

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

---

## 概念設計

（以下は devnotes/20260714-2126-take-inline-preview/conceptual-design.md の内容。関連する現行コードの要点も併記する）

### 現行コードの要点（レビュー補助）

- `resources/js/types/capture.ts`: `CaptureCut` は既に `subtitle_primary: string|null` / `subtitle_secondary: string` を持つ。`CaptureTake` は `status: "uploading"|"processing"|"ready"|"failed"`、`playback_url: string|null`（採用テイクのみ非 null）、`download_ack_token: string|null`。
- `app/DataTransferObjects/Capture/CaptureCutData::fromCut()`: 採用テイクのみ `playbackUrl` を付与（`$isAdopted ? $adoptedPlaybackUrl : null`）。詳細 GET 以外（store/adopt 応答）は全 take null。
- `app/Http/Controllers/Projects/ManualRenderController::playback()`: 既存の合成プレビュー再生。URL 整合 guard（認可前 404）→ `Gate::authorize('render', $manual)` → 状態チェック → `redirect()->away($storage->temporaryPlaybackUrl($path))` の 302 パターン。**本設計の per-take playback はこれを踏襲する**。
- `app/Services/Capture/TakeObjectStorage::temporaryPlaybackUrl(string $path)`: 署名 GET URL（TTL = `config capture.playback_url_ttl_minutes`）。
- `app/Policies/TakePolicy`: 全 ability を `ProjectPolicy::capture` へ委譲。撮影者(project_member)が upload/更新/削除/adopt/DL ACK 可能。**本設計は `preview` ability を同様に追加**。
- routes/web.php の `capture.` group（`scopeBindings`）に takes.{store,update,destroy,adopt,downloaded}。**本設計は `takes.playback`（GET）を追加**。
- `tests/Architecture/NestedRouteIdorDefenseTest.php`: `capture.takes.adopt` / `capture.takes.downloaded` が inventory 登録済み。**本設計は `capture.takes.playback` を追加登録**。
- `resources/js/pages/Capture/Show.svelte`: 撮影 PWA（doc/05）。lg:grid-cols-2 の右ペインに CameraRecorder + TakeStrip。

### doc の該当節（精読結果）

- doc/04「テイクのプレビュー/選択画面」L48-52: テイク一覧から選んで中央プレビューで再生確認。「このテイクを採用する」で確定。**表示制御: プレビューにナレーション/字幕を ON/OFF（初期は両方オフ）**。
- doc/05「個別再生」L56: テイクをタップ→再生画面へ。再生/一時停止、**再生中のナレーション音声・字幕の ON/OFF（デフォルト両方 ON）**。
- v1 は字幕のみ・TTS 後回しのため、合成ナレーション音声トラックが存在しない → **ナレーション音声トグルは out-of-scope**。字幕トグル + インライン再生に絞る。

---

（conceptual-design.md 全文）

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
| 初期表示状態 | **字幕 overlay = 初期 ON** | 本画面は撮影 PWA（doc/05）。doc/05 L56「デフォルト両方 ON」を採る（doc/04 は「初期オフ」だが PC 編集画面向けで対象外） |

## 改善アイデア

TakeStrip の各 **ready テイク**に「再生」ボタンを追加し、押下でインラインの
**プレビューモーダル（`<video controls>` + 字幕 overlay + 字幕トグル）**を開く。
モーダル内に「採用」ボタンを同居させ、「見て採用」を 1 画面で完結させる。

再生ソースは、非採用テイクでも取得できるよう **新規 per-take playback エンドポイント**
（`GET .../takes/{take}/playback` → 302 署名 URL）を追加する。これは既存の
`render-jobs/{renderJob}/playback`（`ManualRenderController::playback`）と同型で、
署名 URL を Inertia payload に埋め込まず、再生時にオンデマンドで発行する
（トークン表面の最小化・TTL 消費の局所化）。

## 期待効果

- 使命への貢献: 「編集ゼロ」の中核である**テイク選定を、別タブ遷移なく 1 画面で**行える。
  現場作業者が採用前に構図/字幕を確認でき、標準化マニュアルの品質担保に直結。
- 「見て採用」導線の完成（監査ギャップ #5 の解消）。
- 別タブ `window.open` への依存を**プレビュー用途では除去**（DL 用途の window.open は温存）。

## 実装方針（概要）

1. **バックエンド: per-take プレビュー再生エンドポイント（新規）**
   - `GET /app/projects/{project}/manuals/{manual}/cuts/{cut}/takes/{take}/playback`
     を `capture.` route group（`scopeBindings`）に追加。
   - `CaptureTakeController::playback()`: URL 整合 guard（認可前 404）→ `Gate::authorize`
     （TakePolicy に `preview` ability 追加、`capture` へ委譲）→ `status===ready` 以外は 404 →
     `redirect()->away($storage->temporaryPlaybackUrl($take->object_path))`。
   - **セキュリティ不変条件**: nested route IDOR 防御のため
     `NestedRouteIdorDefenseTest` の inventory に `capture.takes.playback` を登録。
   - payload（`CaptureCutData` / TS 型）は**変更しない**（URL は route から組み立て。
     `CaptureManualBrowsingTest` のキー契約に影響なし）。

2. **フロント: インラインプレビュー UI**
   - 新規 `TakePreviewDialog.svelte`（`components/features/capture/`）:
     `<video controls src={playbackUrl}>` + 字幕 overlay（primary=上、secondary=下帯）
     + 字幕トグルボタン + 採用ボタン。字幕 overlay は初期 ON。
   - `TakeStrip.svelte`: ready テイクに「再生」ボタン（Lucide `Play`）を追加し、
     押下で dialog を開く。playback URL は `takeUrl(take, "/playback")` で組み立て。
     採用は既存 `adopt()` を dialog から呼ぶ。
   - DL ボタン（`downloadAndAck` の window.open）は**据え置き**（端末保存 = doc/05 の別機能）。

3. **テスト**
   - vitest: 再生ボタン→player 表示 / 字幕トグルで overlay 表示・非表示 /
     preview が `window.open` 非依存（video element 使用）。
   - Pest: playback エンドポイントの 302 / cross-manual 404（IDOR）/ 非 capture 403 /
     非 ready 404。`NestedRouteIdorDefenseTest` inventory 追加。

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

