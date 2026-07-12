# 概念設計レビュー Round 2: 指摘への対応と改訂版の再レビュー依頼

Round 1 の全指摘 ([Critical] 1 件・[Warning] 5 件・[Suggestion] 4 件) に対応し、概念設計を改訂しました。
対応方針と改訂版全文を示します。全体判定 (APPROVED / CHANGES_REQUESTED) をお願いします。

## Round 1 指摘への対応

1. **[Critical] void callback が失敗理由を捨てる** → 対応。判別可能 union
   `CameraUnavailableReason = "permission_denied" | "device_missing" | "mime_unsupported" | "recorder_unsupported" | "unknown"`
   を `lib/capture/camera.ts` に定義し、callback を `onCameraUnavailable(reason)`、
   親 state を `cameraUnavailableReason: CameraUnavailableReason | null` に変更。説明文も reason で出し分け。
   ご提案の `policy_blocked` のみ不採用: Permissions-Policy 拒否はブラウザ上 `NotAllowedError` として
   観測され、ユーザーの権限拒否と機械的に区別できないため `permission_denied` に統合 (設計に注記済み)。
2. **[Warning] 失敗分類が粗い** → 対応。`DOMException.name` ベースで恒久系
   (NotAllowedError/SecurityError/NotFoundError/OverconstrainedError + MIME/Recorder 不適合) は
   フォールバック、一時系 (NotReadableError/AbortError) はローカルエラー + 再試行可能のままに分類。
   分類不能 (unknown) はフォールバック側に倒す (§10.8-3 の必須要件は「詰みを作らない」こと。
   誤ってフォールバックに倒してもファイル選択でテイク投入は継続できるが、逆に倒すと再び詰む)。
3. **[Warning] 子エラー表示と親切替の二重責務** → 対応。恒久系では子はローカル error をセットせず
   通知のみ。説明表示は親に一本化。一時系のみ子がローカルエラー表示。
4. **[Warning] 期待効果の言い切りすぎ** → 対応。「録画 UI で詰まらず、ファイル選択アップロード経路へ
   到達できる」に修正し端末依存の注記を追加。成功判定「権限拒否時でも 1 テイクを
   `UploadQueue.enqueue()` まで到達させられる」を明文化。
5. **[Warning] 業務上の成功条件の明文化** → 対応。制作フロー継続性の回復・v1 必須要件の未達補正を
   タイトルと期待効果に明記。
6. **[Suggestion] テスト 2 段構成 / reason 検証 / テストファースト / 再試行 UI なしの理由明記** → すべて対応。

---

## 改訂版 概念設計 (全文)

# 概念設計: capture-camera-fallback — 撮影カメラフォールバック到達不能の修正 (F-03 / doc/10 §10.8-3 v1 必須要件の未達補正)

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
  **テイクを 1 件もアップロードできず、動画マニュアル制作 (制作フローの継続) が完全に詰む**。
- これは `doc/10_実装仕様.md` §10.8-3「**カメラ非対応フォールバック（必須）**:
  `<input capture>` によるファイル選択アップロードに自動フォールバック。v1 の最小要件」への違反
  = **v1 必須要件の未達**であり、bug-hunt finding の解消にとどまらない補正である。

## 改善アイデア

**「カメラ利用可否」を静的な feature-detect から、実行時失敗の「種別」で上書きされる reactive state に変える。**

### 1. 失敗理由の型を定義する (`lib/capture/camera.ts`)

失敗理由を判別可能 union として型で持つ (void 通知にしない):

```ts
export type CameraUnavailableReason =
    | "permission_denied"     // NotAllowedError / SecurityError (ユーザー拒否・Permissions-Policy 拒否)
    | "device_missing"        // NotFoundError / OverconstrainedError (カメラ無し・制約不一致)
    | "mime_unsupported"      // preferredRecordingMimeType() === null
    | "recorder_unsupported"  // new MediaRecorder() の NotSupportedError 等
    | "unknown";              // 上記以外の予期しない失敗
```

注: Permissions-Policy による拒否はブラウザ上 `NotAllowedError` として観測され、ユーザーの
権限拒否と機械的に区別できないため `permission_denied` に含める (別値を設けない)。

### 2. `CameraRecorder.svelte` — 失敗を種別分類して親へ通知する

callback prop `onCameraUnavailable: (reason: CameraUnavailableReason) => void` を追加し、
`startRecording()` 内の失敗を **恒久系 / 一時系** に分類する:

- **恒久系 → `onCameraUnavailable(reason)` を呼ぶ (ローカル `error` 表示はしない = 責務の二重化回避)**:
  - `preferredRecordingMimeType()` が `null` → `mime_unsupported`
  - `getUserMedia()` reject のうち `NotAllowedError`/`SecurityError` → `permission_denied`、
    `NotFoundError`/`OverconstrainedError` → `device_missing`
  - `new MediaRecorder(...)` の throw → `recorder_unsupported`
  - 分類不能な失敗 → `unknown`。**unknown もフォールバック側に倒す**
    (§10.8-3 の必須要件は「詰みを作らないこと」。誤ってフォールバックに倒しても
    ファイル選択でテイク投入は継続できるが、逆に倒すと再び詰みになるため)
- **一時系 → 従来どおりローカル `error` 表示のみ (録画開始の再試行が可能なまま残す)**:
  - `getUserMedia()` reject のうち `NotReadableError`/`AbortError`
    (他アプリがカメラ使用中・一時的なハード/OS 競合。自然回復し得る) →
    「カメラを起動できませんでした。他のアプリがカメラを使用していないか確認し、もう一度お試しください。」

### 3. `Capture/Show.svelte` — reason を state に持ち、reactive にフォールバックへ切替

- `let cameraUnavailableReason = $state<CameraUnavailableReason | null>(null)`
  (boolean にせず reason を保持 → 説明文の出し分け・将来の計測に使える)
- 表示分岐を `$derived` の `showRecorder = canRecord && cameraUnavailableReason === null` に変更。
  `onCameraUnavailable` で reason がセットされると、その場で
  `CameraRecorder` → `CaptureFileFallback` に**自動で切り替わる** (ページ再読み込み不要)。
- 実行時フォールバック時は `CaptureFileFallback` の上に説明文を `role="status"` で表示する
  (親だけが説明の責務を持つ)。文言は reason で出し分ける:
  - `permission_denied`: 「カメラを利用できないため、ファイル選択でのアップロードに切り替えました。カメラで撮影する場合はブラウザのカメラ許可を確認して再読み込みしてください。」
  - それ以外: 「この端末ではカメラ録画を利用できないため、ファイル選択でのアップロードに切り替えました。」
- **一度フォールバックしたら同一ページセッション中は戻さない**。恒久系失敗
  (権限拒否・デバイス無し・API 不適合) はセッション内で自然回復しないため、
  **意図的に再試行 UI (「もう一度カメラを試す」ボタン等) を持たない**
  (v1 最小要件外。permission_denied の文言で「許可 + 再読み込み」の回復手順は案内する)。

### 4. アップロード経路は一切変更しない

フォールバックの `onCaptured(file)` は既存どおり `handleCaptured(file, file.type, null)` →
`UploadQueue.enqueue()` → `upload-url` → S3 presigned PUT → `POST takes` の
冪等経路 (client_take_id ULID + checksum) をそのまま使う。

## 期待効果

- **使命への貢献 / 業務上の成功条件**: 「スマホ (PWA) でナビゲーション撮影」は North Star
  フローの根幹。カメラ実行時失敗 (権限拒否・カメラ無し端末・企業ポリシー制限) でも
  **録画 UI で詰まらず、既存のファイル選択アップロード経路へ到達でき、テイク投入
  (制作フロー) を継続できる**。
  - 成功判定: **カメラ権限拒否 (getUserMedia reject) の状態でも、ファイル選択により
    1 テイクを `UploadQueue.enqueue()` (upload-url→takes 経路) まで到達させられる**こと。
  - 注記: `<input capture="environment">` がカメラアプリを直接起動するかファイル選択に
    なるかは端末・ブラウザ依存。本設計が保証するのは「ファイル選択アップロード経路への
    到達」であり、直接撮影 UI の提供ではない。
- doc/10 §10.8-3「カメラ非対応フォールバック（必須）」の v1 最小要件を実行時レベルで満たす。
- bug-hunt F-03 (Critical) の解消。次回 bug-hunt 走行で S3-7 以降
  (テイクアップロード → 採用 → sync) の探索が可能になる。

## 実装方針（概要）

変更はフロントエンド 3 ファイル + テストのみ。

| ファイル | 変更 |
|---------|------|
| `resources/js/lib/capture/camera.ts` | `CameraUnavailableReason` union 型と `getUserMedia` エラー分類ヘルパを追加 |
| `resources/js/components/features/capture/CameraRecorder.svelte` | prop `onCameraUnavailable(reason)` 追加。恒久系失敗で通知、一時系はローカルエラー表示のまま |
| `resources/js/pages/Capture/Show.svelte` | `cameraUnavailableReason` state 追加、`showRecorder` を `$derived` 化、フォールバック時の説明文 (reason 別) 表示 |
| `tests/js/lib/capture/camera.test.ts` (追記) | エラー分類ヘルパの単体テスト (DOMException name → reason) |
| `tests/js/components/features/capture/CameraRecorder.test.ts` (新規) | getUserMedia reject (NotAllowedError 等) → **どの reason で** `onCameraUnavailable` が呼ばれるか、一時系 (NotReadableError) では呼ばれずローカルエラー表示になることを検証 |
| `tests/js/pages/CaptureShow.test.ts` (新規) | 分岐表示の検証: (a) 静的 canRecord=false でフォールバック表示 (既存挙動の回帰確認)、(b) getUserMedia reject → フォールバック UI + 説明文表示、(c) ファイル選択で `handleCaptured` → enqueue まで到達すること。upload-url→PUT→takes の HTTP 詳細は既存 `upload-queue.test.ts` の資産に寄せ、ページテストでは brittle にしない |

- テストは 2 段構成: `CameraRecorder` 単体 = 失敗分類と通知、`Show` 単体 = 分岐表示。
  アップロード HTTP 経路の網羅は既存 `upload-queue.test.ts` が担う。
- **テストファースト**: フォールバック到達の再現テスト (getUserMedia reject → フォールバック
  UI 表示) を先に書き fail を確認してから実装する。テスト追加が完了条件
  (テストなしの実装完了報告は禁止事項)。
- `supportsMediaRecorder()` / `preferredRecordingMimeType()` の既存ロジックは変更しない
  (静的 feature-detect としては引き続き正しく、既存テスト `camera.test.ts` も維持)。
- バックエンド (presigned / quota / 冪等登録) は既存のまま変更なし。

## 制約・前提

- Svelte 5 runes (`$state` / `$derived`) + TypeScript。DS token のみ・`@lucide/svelte` のみ・
  事前条件 disabled 禁止 (DESIGN.md / ds-purity・atomic-import-graph テストが強制)。
- component 階層: `features/capture` 内の兄弟コンポーネント間で callback prop を使い、
  親 (pages) が状態を持つ既存パターンを踏襲 (階層の逆流なし)。
- 失敗分類は `DOMException.name` ベース (ブラウザ標準の error name。W3C Media Capture 仕様)。
- 既存テスト (`camera.test.ts` / `upload-queue.test.ts` / `TakeStrip.test.ts` /
  capture-sw / http) を壊さない。

## スコープ外

- バックエンド (upload-url / takes / quota / 予約 CAS) の変更。
- `CaptureFileFallback.svelte` 自体の UI 変更 (既存のまま到達可能にするのが目的)。
- F-02 (シナリオ保存 409 フィードバック欠落) など他 finding への対応。
- カメラ権限の再要求 UI (「もう一度カメラを試す」ボタン等) — 恒久系失敗はセッション内で
  自然回復せず、v1 最小要件 (詰みの解消) には不要のため意図的に持たない
  (permission_denied 文言で回復手順は案内する)。
- reason の計測・分析基盤への送信 (型として保持するに留め、将来の拡張点とする)。
- bughunt harness 側の課題 (F-01 queue worker / F-04 seeder) への対応。
