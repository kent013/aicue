# 概念設計レビュー依頼 (Round 1)

【アプリの使命 (North Star)】
**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

v1 スコープ: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

【禁止事項】
1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

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
6. スコープの適切さ: 過大または過小になっていないか
7. **型安全性**: DTO/JsonResourceパターンに沿っているか。PHPStan level 10を通せるか

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

必要に応じて以下の関連ファイルを読んでよい (read-only):
- /workspace/resources/js/pages/Capture/Show.svelte
- /workspace/resources/js/components/features/capture/CameraRecorder.svelte
- /workspace/resources/js/components/features/capture/CaptureFileFallback.svelte
- /workspace/resources/js/lib/capture/camera.ts
- /workspace/resources/js/lib/capture/upload-queue.ts
- /workspace/devnotes/20260712-191243-bug-hunt/shard-0/shard-report.md (F-03 節)

---

## 概念設計

# 概念設計: capture-camera-fallback — 撮影カメラフォールバック到達不能の修正 (F-03)

## 背景・課題

bug-hunt 2回目レポート (`devnotes/20260712-191243-bug-hunt/shard-0/shard-report.md` F-03, **Critical**) で、
撮影 PWA のカメラフォールバックが実行時に到達不能であることが確認された。

- `resources/js/pages/Capture/Show.svelte` の `canRecord` は
  `supportsMediaRecorder()` (`resources/js/lib/capture/camera.ts`) による
  **API の「存在」チェックのみの静的フラグ** (`const canRecord = ...` で mount 時に一度だけ評価)。
- 実際に `getUserMedia()` を呼んだ結果の**実行時失敗**
  (`NotAllowedError`=権限拒否 / `NotFoundError`=カメラ無し / Permissions-Policy 拒否 等) を一切扱わない。
- そのため「API はあるがカメラは使えない」環境では `canRecord=true` のまま
  `CameraRecorder.svelte` が表示され続け、`startRecording()` の catch で
  「カメラを利用できません…」と赤字表示するだけで、
  **実装済みの `CaptureFileFallback.svelte` (`<input type="file" capture>`) へ到達する経路が存在しない**。
- 結果: カメラ権限を拒否した / カメラの無い端末 / 企業ポリシーでカメラが制限された撮影者は
  **テイクを 1 件もアップロードできず、動画マニュアル制作が完全に詰む**。
- これは `doc/10_実装仕様.md` §10.8-3「**カメラ非対応フォールバック（必須）**:
  `<input capture>` によるファイル選択アップロードに自動フォールバック。v1 の最小要件」への違反。

## 改善アイデア

**「カメラ利用可否」を静的な feature-detect から、実行時失敗で上書きされる reactive state に変える。**

1. `CameraRecorder.svelte` に callback prop `onCameraUnavailable: () => void` を追加する。
   `startRecording()` 内の録画不能な失敗、すなわち
   - `preferredRecordingMimeType()` が `null` (録画 MIME 不可)
   - `getUserMedia()` の reject (権限拒否・デバイス無し・Permissions-Policy 拒否 等)
   - `new MediaRecorder(...)` の throw (`NotSupportedError` 等)

   のいずれでも `onCameraUnavailable()` を呼んで親に通知する
   (従来はローカル `error` 表示のみで、ユーザーに代替手段が示されなかった)。

2. `Capture/Show.svelte` に `let cameraFailed = $state(false)` を追加し、
   表示分岐を `$derived` の `showRecorder = canRecord && !cameraFailed` に変更する。
   `onCameraUnavailable` で `cameraFailed = true` にすると、その場で
   `CameraRecorder` → `CaptureFileFallback` に**自動で切り替わる**
   (Svelte 5 runes による reactive 分岐。ページ再読み込み不要)。

3. フォールバックへ切り替わった理由をユーザーに伝えるため、実行時フォールバック時は
   `CaptureFileFallback` の上に説明文
   「カメラを利用できないため、ファイル選択でのアップロードに切り替えました。」
   を `role="status"` で表示する (DS token のみ・Lucide アイコン・disabled 禁止は不使用のまま)。

4. アップロード経路は**一切変更しない**。フォールバックの `onCaptured(file)` は
   既存どおり `handleCaptured(file, file.type, null)` →
   `UploadQueue.enqueue()` → `upload-url` → S3 presigned PUT → `POST takes` の
   冪等経路 (client_take_id ULID + checksum) をそのまま使う。

## 期待効果

- **使命への貢献**: 「スマホ (PWA) でナビゲーション撮影」は North Star フローの根幹。
  カメラ不可環境の撮影者 (権限拒否・カメラ無し PC・企業ポリシー制限) でも
  テイクをアップロードでき、動画マニュアル制作が詰まなくなる。
- doc/10 §10.8-3「カメラ非対応フォールバック（必須）」の v1 最小要件を実行時レベルで満たす。
- bug-hunt F-03 (Critical) の解消。次回 bug-hunt 走行で S3-7 以降
  (テイクアップロード → 採用 → sync) の探索が可能になる。

## 実装方針（概要）

変更はフロントエンド 2 ファイル + テストのみ。

| ファイル | 変更 |
|---------|------|
| `resources/js/components/features/capture/CameraRecorder.svelte` | prop `onCameraUnavailable` 追加。録画不能 3 分岐 (MIME null / getUserMedia reject / MediaRecorder construct throw) で呼び出し |
| `resources/js/pages/Capture/Show.svelte` | `cameraFailed` state 追加、`showRecorder` を `$derived` 化、フォールバック時の説明文表示 |
| `tests/js/components/features/capture/CameraRecorder.test.ts` (新規) | getUserMedia reject → `onCameraUnavailable` 呼び出しを検証 |
| `tests/js/pages/CaptureShow.test.ts` (新規) | getUserMedia reject → フォールバック UI 表示 → ファイル選択で upload-url→PUT→takes 経路が呼ばれることを検証 (fetch mock) |

- `supportsMediaRecorder()` / `preferredRecordingMimeType()` (`lib/capture/camera.ts`) は変更しない
  (静的 feature-detect としては引き続き正しく、既存テスト `camera.test.ts` も維持)。
- バックエンド (presigned / quota / 冪等登録) は既存のまま変更なし。

## 制約・前提

- Svelte 5 runes (`$state` / `$derived`) + TypeScript。DS token のみ・`@lucide/svelte` のみ・
  事前条件 disabled 禁止 (DESIGN.md / ds-purity・atomic-import-graph テストが強制)。
- component 階層: `features/capture` 内の兄弟コンポーネント間で callback prop を使い、
  親 (pages) が状態を持つ既存パターンを踏襲 (階層の逆流なし)。
- `CameraRecorder` は「一度失敗したら以後はフォールバック」とする
  (getUserMedia 失敗の大半 — 権限拒否・デバイス無し・ポリシー拒否 — は
  同一ページセッション内で自然回復しないため。再挑戦したい場合はリロードで足りる)。
- 既存テスト (`camera.test.ts` / `upload-queue.test.ts` / `TakeStrip.test.ts` /
  capture-sw / http) を壊さない。

## スコープ外

- バックエンド (upload-url / takes / quota / 予約 CAS) の変更。
- `CaptureFileFallback.svelte` 自体の UI 変更 (既存のまま到達可能にするのが目的)。
- F-02 (シナリオ保存 409 フィードバック欠落) など他 finding への対応。
- カメラ権限の再要求 UI (「もう一度カメラを試す」ボタン等) — v1 最小要件外。
- bughunt harness 側の課題 (F-01 queue worker / F-04 seeder) への対応。
