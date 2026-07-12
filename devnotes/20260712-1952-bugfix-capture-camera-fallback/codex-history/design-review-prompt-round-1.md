# 詳細設計レビュー依頼 (Round 1)

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

あなたは経験豊富なWebアプリケーションアーキテクトです。Laravel + Svelte アプリケーション改善の詳細設計をレビューしてください。

【前提環境】
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- PHPStan level 10
- Pestテストフレームワーク
- DTO + JsonResource パターン
- Laratrust RBAC（Organization → Team → Project階層）

【レビュー観点】
1. コードの正確性（ロジックエラー、エッジケース、null安全性）
2. 既存コードとの整合性（命名規約、パターン、API）
3. PHPStan level 10 適合性（型安全性、generics、Assert使用）— 本件はフロントのみのため TypeScript strict / eslint / svelte-check 適合で読み替え
4. テスト計画の網羅性（各施策にテスト、既存テストを壊さない）
5. DTO/JsonResource パターンの遵守（本件はバックエンド無変更）
6. Inertia Props vs API Responseの使い分け
7. 副作用・後退リスク
8. 波及変更の網羅性（TypeScript型定義、コンポーネント Props、テストが変更対象に含まれているか）
9. セキュリティ（認可チェック、入力バリデーション、OWASP Top 10、AGENTS.md のセキュリティ不変条件）
10. DESIGN.md準拠: design token 経由の参照か、hex 直書きを増やさないか
11. Atomic Design準拠: atoms/molecules/organisms/features/templates/pages の責務分離・単方向 import か。アイコンは Lucide 前提で SVG 直書きを新設していないか

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

必要に応じて以下の関連ファイルを read-only で読んでよい:
- /workspace/resources/js/pages/Capture/Show.svelte
- /workspace/resources/js/components/features/capture/CameraRecorder.svelte
- /workspace/resources/js/components/features/capture/CaptureFileFallback.svelte
- /workspace/resources/js/lib/capture/camera.ts
- /workspace/resources/js/lib/capture/upload-queue.ts
- /workspace/tests/js/lib/capture/camera.test.ts
- /workspace/tests/js/pages/PurchaseTickets.test.ts (ページテストのモックパターン)
- /workspace/devnotes/20260712-1952-bugfix-capture-camera-fallback/conceptual-design.md (APPROVED 済み概念設計)

---

## 詳細設計書

# 詳細設計: capture-camera-fallback — 撮影カメラフォールバック到達不能の修正 (F-03)

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

### 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)

### コーディングルール

- **PHPStan level 10** 必須（`composer phpstan`）— 本件はフロントのみだが規約として遵守
- **Pest**テストフレームワーク（`composer test`）— 本件は Vitest のみ (バックエンド変更なし)
- **RefreshDatabase** + `--parallel` 並列実行（個別 `DatabaseTransactions` 使用禁止）
- **テストデータは必ずFactoryで生成**（本件はフロント Vitest のため該当なし）
- **DTO + JsonResource** パターン（本件は既存 API を変更しない）
- **アーリーリターン** 推奨
- **コードフォーマット**: `composer fix`（Pint）/ `pnpm lint:fix`
- PHP 8.4 + Laravel 12 + Svelte 5 (runes) + Inertia.js + TypeScript (strict)
- フロント: DS token/ramp のみ (DESIGN.md canonical、ds-purity テスト)・`@lucide/svelte` のみ・
  atomic import 階層 (`atoms → molecules → organisms → features → templates → pages` 単方向)

## 概念設計リファレンス

- `devnotes/20260712-1952-bugfix-capture-camera-fallback/conceptual-design.md`（Round 2 で APPROVED）
- 対象 finding: `devnotes/20260712-191243-bug-hunt/shard-0/shard-report.md` F-03 (Critical)
- 根拠仕様: `doc/10_実装仕様.md` §10.8-3「カメラ非対応フォールバック（必須）」

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | カメラ失敗理由の型と分類ヘルパ | `resources/js/lib/capture/camera.ts` | High |
| 2 | CameraRecorder の失敗分類・親通知 | `resources/js/components/features/capture/CameraRecorder.svelte` | High |
| 3 | Show.svelte の実行時フォールバック切替 | `resources/js/pages/Capture/Show.svelte` | High |
| 4 | テスト (分類 / 通知 / 分岐表示 / enqueue 引き渡し) | `tests/js/lib/capture/camera.test.ts` (追記), `tests/js/components/features/capture/CameraRecorder.test.ts` (新規), `tests/js/pages/CaptureShow.test.ts` (新規) | High |

実装順序はテストファースト: 施策 4 の再現テスト（getUserMedia reject → フォールバック UI 表示 /
`onCameraUnavailable("permission_denied")` 通知）を先に書いて fail を確認 → 施策 1→2→3 の順に実装。

---

## 施策 1: カメラ失敗理由の型と分類ヘルパ (`camera.ts`)

### 変更箇所
- ファイル: `resources/js/lib/capture/camera.ts`（末尾に追加。既存 2 関数は変更しない）

### 波及変更
- TypeScript型定義: 本ファイル内で完結（`CameraUnavailableReason` / `CameraErrorClassification` を export）
- API Resource/DTO: なし（バックエンド変更なし）
- テストファイル: `tests/js/lib/capture/camera.test.ts` に分類ヘルパのテストを追記

### 現行コード
```ts
// camera.ts は supportsMediaRecorder() / preferredRecordingMimeType() のみ。
// 実行時エラーの分類機構は存在しない。
```

### 変更後コード（追加分）
```ts
/**
 * カメラが実行時に使えない理由 (F-03 対応。判別可能 union で保持し、
 * UI 文言の出し分け・将来の計測に使う)。
 * Permissions-Policy 拒否は NotAllowedError として観測されユーザー拒否と
 * 機械的に区別できないため permission_denied に含める。
 */
export type CameraUnavailableReason =
    | "permission_denied" // NotAllowedError / SecurityError (ユーザー拒否・Permissions-Policy 拒否)
    | "device_missing" // NotFoundError / OverconstrainedError (カメラ無し・制約不一致)
    | "mime_unsupported" // preferredRecordingMimeType() === null
    | "recorder_unsupported" // new MediaRecorder() の失敗 (NotSupportedError 等)
    | "unknown"; // 分類不能 (詰み回避のためフォールバック側に倒す)

/** getUserMedia() 失敗の分類結果。transient は再試行で回復し得る失敗 */
export type CameraErrorClassification =
    | { kind: "unavailable"; reason: CameraUnavailableReason }
    | { kind: "transient" };

/** reject 値から DOMException 名を安全に取り出す (ブラウザは任意値を reject し得る) */
function errorName(error: unknown): string | null {
    if (error instanceof DOMException) return error.name;
    // OverconstrainedError 等、実装により DOMException を継承しないオブジェクトに備える
    if (typeof error === "object" && error !== null && "name" in error) {
        const name = (error as { name: unknown }).name;
        return typeof name === "string" ? name : null;
    }
    return null;
}

/**
 * getUserMedia() の reject 値を分類する (W3C Media Capture の DOMException name ベース)。
 * - 恒久系 (権限拒否・デバイス無し) → unavailable: フォールバックへ切替
 * - 一時系 (デバイス使用中・中断) → transient: エラー表示 + 再試行可能のまま
 * - 分類不能 → unavailable/unknown: §10.8-3 の「詰みを作らない」要件に従い
 *   フォールバック側に倒す (誤フォールバックでもテイク投入は継続できる)
 */
export function classifyGetUserMediaError(error: unknown): CameraErrorClassification {
    switch (errorName(error)) {
        case "NotAllowedError":
        case "SecurityError":
            return { kind: "unavailable", reason: "permission_denied" };
        case "NotFoundError":
        case "OverconstrainedError":
            return { kind: "unavailable", reason: "device_missing" };
        case "NotReadableError":
        case "AbortError":
            return { kind: "transient" };
        default:
            return { kind: "unavailable", reason: "unknown" };
    }
}
```

### PHPStan適合チェック（フロントは TS strict / eslint / typecheck で読み替え）
- [x] 戻り値の型が明示されている（`CameraErrorClassification`）
- [x] null安全（入力は `unknown` で受け、`instanceof` + プロパティ存在 + `typeof` で絞り込み。unsafe cast なし）
- [x] 判別可能 union（`kind` / `reason` で網羅的に分岐可能。`switch` の default で全ケース閉包）
- [x] Genericsの型パラメータ: 該当なし

### テスト計画
- [x] 再現テストを先に書く（Vitest）
- [ ] `tests/js/lib/capture/camera.test.ts` に追記: `classifyGetUserMediaError`
  - `new DOMException("denied", "NotAllowedError")` → `{ kind: "unavailable", reason: "permission_denied" }`
  - `new DOMException("", "SecurityError")` → `permission_denied`
  - `new DOMException("", "NotFoundError")` / name プロパティのみ持つ plain object `{ name: "OverconstrainedError" }` → `device_missing`
  - `new DOMException("", "NotReadableError")` / `new DOMException("", "AbortError")` → `{ kind: "transient" }`
  - `new Error("boom")`（name="Error"）・文字列・`null` → `{ kind: "unavailable", reason: "unknown" }`
- [x] 既存の `supportsMediaRecorder` / `preferredRecordingMimeType` テストは無変更で green を維持

### リスク
- なし（純関数の追加のみ。既存 export は不変）

---

## 施策 2: CameraRecorder の失敗分類・親通知

### 変更箇所
- ファイル: `resources/js/components/features/capture/CameraRecorder.svelte`（L11-15 Props、L25-61 `startRecording`）

### 波及変更
- TypeScript型定義: Props に `onCameraUnavailable: (reason: CameraUnavailableReason) => void` を追加。
  **必須 prop とする**（フォールバック到達性は §10.8-3 の必須要件であり、渡し忘れを型で防ぐ）。
  呼び出し元は `Capture/Show.svelte` のみ（`rg "CameraRecorder" resources/js` で確認済み）→ 施策 3 で対応
- API Resource/DTO: なし
- テストファイル: `tests/js/components/features/capture/CameraRecorder.test.ts`（新規）

### 現行コード
```svelte
<script lang="ts">
    import { onDestroy } from "svelte";
    import { Circle, Square } from "@lucide/svelte";
    import Button from "@/components/atoms/Button.svelte";
    import { preferredRecordingMimeType } from "@/lib/capture/camera";

    interface Props {
        onCaptured: (blob: Blob, mimeType: string, durationMs: number) => void;
    }

    let { onCaptured }: Props = $props();
    // ...
    async function startRecording(): Promise<void> {
        error = null;
        const mimeType = preferredRecordingMimeType();
        if (mimeType === null) {
            error = "この端末では録画できません。ファイル選択をご利用ください。";
            return;
        }
        try {
            stream ??= await navigator.mediaDevices.getUserMedia({
                video: { facingMode: "environment" },
                audio: true,
            });
        } catch {
            error = "カメラを利用できません。ブラウザのカメラ許可を確認してください。";
            return;
        }
        if (video) {
            video.srcObject = stream;
            await video.play().catch(() => undefined);
        }
        chunks = [];
        recorder = new MediaRecorder(stream, { mimeType });
        // ... ondataavailable / onstop / start()
    }
</script>
```

### 変更後コード
```svelte
<script lang="ts">
    import { onDestroy } from "svelte";
    import { Circle, Square } from "@lucide/svelte";
    import Button from "@/components/atoms/Button.svelte";
    import { classifyGetUserMediaError, preferredRecordingMimeType } from "@/lib/capture/camera";
    import type { CameraUnavailableReason } from "@/lib/capture/camera";

    /**
     * MediaRecorder による録画 (概念設計 D9)。停止時に blob を親へ渡す。
     * 録画不能な恒久失敗 (権限拒否・デバイス無し・API 不適合) は onCameraUnavailable で
     * 親に通知し、親がファイル選択フォールバックへ切り替える (doc/10 §10.8-3、F-03)。
     * 一時的失敗 (デバイス使用中等) のみローカルにエラー表示し再試行可能のまま残す。
     */
    interface Props {
        onCaptured: (blob: Blob, mimeType: string, durationMs: number) => void;
        /** カメラが恒久的に使えないと判明したときの通知 (親がフォールバックへ切替) */
        onCameraUnavailable: (reason: CameraUnavailableReason) => void;
    }

    let { onCaptured, onCameraUnavailable }: Props = $props();

    let video: HTMLVideoElement | null = $state(null);
    let stream: MediaStream | null = null;
    let recorder: MediaRecorder | null = null;
    let chunks: Blob[] = [];
    let startedAt = 0;
    let recording = $state(false);
    let error = $state<string | null>(null);

    async function startRecording(): Promise<void> {
        error = null;
        const mimeType = preferredRecordingMimeType();
        if (mimeType === null) {
            // 恒久系: ローカル表示はせず親へ委譲 (責務の二重化回避)
            onCameraUnavailable("mime_unsupported");
            return;
        }
        try {
            stream ??= await navigator.mediaDevices.getUserMedia({
                video: { facingMode: "environment" },
                audio: true,
            });
        } catch (cause) {
            const classified = classifyGetUserMediaError(cause);
            if (classified.kind === "transient") {
                // 一時系 (NotReadableError/AbortError): 再試行可能のままエラー表示
                error =
                    "カメラを起動できませんでした。他のアプリがカメラを使用していないか確認し、もう一度お試しください。";
                return;
            }
            onCameraUnavailable(classified.reason);
            return;
        }
        if (video) {
            video.srcObject = stream;
            await video.play().catch(() => undefined);
        }
        chunks = [];
        try {
            recorder = new MediaRecorder(stream, { mimeType });
        } catch {
            // NotSupportedError 等: 取得済み stream を解放してからフォールバックへ
            releaseCamera();
            onCameraUnavailable("recorder_unsupported");
            return;
        }
        recorder.ondataavailable = (event) => {
            if (event.data.size > 0) chunks.push(event.data);
        };
        recorder.onstop = () => {
            const blob = new Blob(chunks, { type: mimeType });
            const durationMs = Date.now() - startedAt;
            recording = false;
            if (blob.size > 0) {
                onCaptured(blob, mimeType, durationMs);
            }
        };
        startedAt = Date.now();
        recorder.start();
        recording = true;
    }

    function stopRecording(): void {
        recorder?.stop();
    }

    function releaseCamera(): void {
        stream?.getTracks().forEach((track) => track.stop());
        stream = null;
    }

    onDestroy(releaseCamera);
</script>
```
（template 部は無変更: `<video>` プレビュー、録画開始/停止 `Button`、`error` の `role="alert"` 表示）

### PHPStan適合チェック（TS strict 読み替え）
- [x] Props の callback 型が明示されている（void 通知でなく reason を運ぶ）
- [x] `catch (cause)` は `unknown` として `classifyGetUserMediaError` に渡す（unsafe cast なし）
- [x] アーリーリターンで失敗分岐を早期終了
- [x] 事前条件 disabled なし（従来どおり押下時にエラー/フォールバックで応答）

### テスト計画（`tests/js/components/features/capture/CameraRecorder.test.ts` 新規）
- 共通 setup: `vi.stubGlobal("MediaRecorder", { isTypeSupported: (t: string) => t === "video/webm" })`、
  `vi.stubGlobal("navigator", { ...navigator, mediaDevices: { getUserMedia: getUserMediaMock } })`
- [ ] `getUserMedia` が `new DOMException("denied", "NotAllowedError")` で reject →
  録画開始押下で `onCameraUnavailable` が **`"permission_denied"` を引数に** 呼ばれ、
  ローカルエラー（`role="alert"`）は表示されない
- [ ] `NotFoundError` reject → `onCameraUnavailable("device_missing")`
- [ ] `NotReadableError` reject → `onCameraUnavailable` は**呼ばれず**、
  `role="alert"` に「カメラを起動できませんでした…もう一度お試しください」が表示され、
  録画開始ボタンが残る（再試行可能）
- [ ] `isTypeSupported` が全 MIME で false → 押下で `onCameraUnavailable("mime_unsupported")`
- 録画成功パス（MediaRecorder start/stop → onCaptured）は jsdom の HTMLMediaElement
  未実装（`video.play()`）に依存し brittle なため単体テスト対象外とする
  （現状もテスト無し。実機検証項目は doc/10 §10.7 の既存整理に従う）

### リスク
- `onCameraUnavailable` を必須 prop にするため、CameraRecorder の呼び出し元が増えた場合に
  コンパイルエラーで気づける（意図した破壊的変更。現呼び出し元は Show.svelte のみ）
- MediaRecorder 構築失敗時に `releaseCamera()` を呼ぶため、稀に他タブ等で
  再利用中の stream を止める副作用はない（stream はこのコンポーネント専有）

---

## 施策 3: Show.svelte の実行時フォールバック切替

### 変更箇所
- ファイル: `resources/js/pages/Capture/Show.svelte`（L36 `canRecord` 周辺、L168-177 表示分岐）

### 波及変更
- TypeScript型定義: `CameraUnavailableReason` の import 追加（`@/lib/capture/camera`）
- Inertia Props: 変更なし（`project` / `manual` のまま）
- API Resource/DTO: なし
- テストファイル: `tests/js/pages/CaptureShow.test.ts`（新規）

### 現行コード
```svelte
import { supportsMediaRecorder } from "@/lib/capture/camera";
// ...
const canRecord = typeof window !== "undefined" && supportsMediaRecorder();
```
```svelte
{#if canRecord}
    <CameraRecorder
        onCaptured={(blob, mimeType, durationMs) =>
            handleCaptured(blob, mimeType, durationMs)}
    />
{:else}
    <CaptureFileFallback
        onCaptured={(file) => handleCaptured(file, file.type, null)}
    />
{/if}
```

### 変更後コード
```svelte
import { supportsMediaRecorder } from "@/lib/capture/camera";
import type { CameraUnavailableReason } from "@/lib/capture/camera";
// ...
// 静的 feature-detect (従来) + 実行時失敗による上書き (F-03: doc/10 §10.8-3)
const canRecord = typeof window !== "undefined" && supportsMediaRecorder();
let cameraUnavailableReason = $state<CameraUnavailableReason | null>(null);
const showRecorder = $derived(canRecord && cameraUnavailableReason === null);
// 実行時フォールバックの説明文 (reason で出し分け。静的 feature-detect 由来は
// CaptureFileFallback 既存の説明文だけで足りるため notice なし)
const fallbackNotice = $derived.by(() => {
    if (cameraUnavailableReason === null) return null;
    if (cameraUnavailableReason === "permission_denied") {
        return "カメラを利用できないため、ファイル選択でのアップロードに切り替えました。カメラで撮影する場合はブラウザまたは端末・組織のカメラ設定を確認して再読み込みしてください。";
    }
    return "この端末ではカメラ録画を利用できないため、ファイル選択でのアップロードに切り替えました。";
});
```
```svelte
{#if showRecorder}
    <CameraRecorder
        onCaptured={(blob, mimeType, durationMs) =>
            handleCaptured(blob, mimeType, durationMs)}
        onCameraUnavailable={(reason) => (cameraUnavailableReason = reason)}
    />
{:else}
    {#if fallbackNotice !== null}
        <p
            class="text-caption text-text-secondary"
            role="status"
            data-testid="camera-fallback-notice"
        >
            {fallbackNotice}
        </p>
    {/if}
    <CaptureFileFallback
        onCaptured={(file) => handleCaptured(file, file.type, null)}
    />
{/if}
```

### PHPStan適合チェック（TS strict 読み替え）
- [x] `cameraUnavailableReason` は `CameraUnavailableReason | null` の型付き `$state`
- [x] `$derived` / `$derived.by` で分岐を宣言的に保持（手続き的 flag 更新をしない）
- [x] null 安全（`fallbackNotice !== null` ガード）
- [x] DS token のみ（`text-caption` / `text-text-secondary` は既存 token クラス）、
  Lucide 以外のアイコン追加なし、disabled 不使用

### テスト計画（`tests/js/pages/CaptureShow.test.ts` 新規）
- モック方針（既存ページテストのパターン踏襲: `PurchaseTickets.test.ts` の
  `vi.mock("@inertiajs/svelte", importOriginal)` 方式）:
  - `@inertiajs/svelte`: `importOriginal` を spread し `router.reload` のみ mock（`page` store は本物）
  - `@/lib/capture/idb`: `createIdbPendingStore` を in-memory store に差し替え（jsdom に indexedDB が無いため）
  - `@/lib/capture/upload-queue`: `importOriginal` を spread し `UploadQueue` のみ
    `enqueue` spy 付き stub（`{ status: "uploaded", clientTakeId }` を返す。`quotaMessage: null` 保持）に差し替え。
    **ページテストの検証範囲は「`enqueue()` への引き渡し」まで**。enqueue 後の
    upload-url → S3 PUT → POST takes の HTTP 経路と登録完遂は既存 `upload-queue.test.ts` が担う
- ケース:
- [ ] (a) 回帰: `MediaRecorder` 未定義（静的 `canRecord=false`）でカット選択 →
  `capture-file-input` が表示され、`camera-fallback-notice` は**表示されない**（既存挙動の固定）
- [ ] (b) 実行時フォールバック: `MediaRecorder` stub + `getUserMedia` が `NotAllowedError` reject。
  カット行（`cut-row-{id}`）クリック → `start-recording` クリック →
  `camera-preview` が消え `capture-file-input` と `camera-fallback-notice`
  （「…カメラ設定を確認して再読み込み…」）が表示される
- [ ] (c) フォールバックからのアップロード: (b) の状態でファイル選択
  （`fireEvent.change` で `File(["data"], "take.mp4", { type: "video/mp4" })`）→
  `enqueue` spy が `cutId`=選択カット・`blob`=選択ファイル・`durationMs: null` で呼ばれ、
  `router.reload` が `{ only: ["manual"] }` で呼ばれる
- [ ] 既存テスト（`camera.test.ts` / `upload-queue.test.ts` / `TakeStrip.test.ts` /
  `capture-sw.test.ts` / `http.test.ts`）が無変更で green

### リスク
- 撮影 PWA の既存フロー（録画成功パス・アップロードキュー・sync・採用）は
  コード経路が不変（`handleCaptured` 共通）のため後退リスクは低い
- 一度フォールバックすると同一ページ滞在中はカメラに戻れない（概念設計どおりの意図的判断。
  permission_denied 文言で「設定確認 + 再読み込み」の回復手順を案内）
- `role="status"`（polite live region）の追加は既存 a11y 構造に影響しない

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | standalone |
| 判断根拠 | フロント 3 ファイル + テスト 3 ファイルに閉じた独立バグ修正。バックエンド・DB・ルート変更なし。他 TODO (F-01 harness / F-02 409 フィードバック / F-04 seeder) とファイル重複がなく、単独 worktree で完結できる |
| 競合リスク | 低。`Capture/Show.svelte` を触る他の設計が並走しない限り競合しない（F-02 はマニュアル編集画面、F-01/F-04 は harness/seeder でファイル素集合が非交差） |

## 検証コマンド（実装完了条件）

- `pnpm test`（新規 3 テスト + 既存全 green）
- `pnpm typecheck` / `pnpm lint`
- `pnpm build`
- `composer test` / `composer phpstan` / `vendor/bin/pint --test`（バックエンド無変更の回帰確認）

---

## 関連する現行コード

### resources/js/pages/Capture/Show.svelte (現行・全文)
```svelte
<script lang="ts">
    import { onMount } from "svelte";
    import { page, router } from "@inertiajs/svelte";
    import { ArrowLeft } from "@lucide/svelte";
    import TextLink from "@/components/atoms/TextLink.svelte";
    import CameraRecorder from "@/components/features/capture/CameraRecorder.svelte";
    import CaptureFileFallback from "@/components/features/capture/CaptureFileFallback.svelte";
    import CutNavigator from "@/components/features/capture/CutNavigator.svelte";
    import TakeStrip from "@/components/features/capture/TakeStrip.svelte";
    import UploadQueueBar from "@/components/features/capture/UploadQueueBar.svelte";
    import AppLayout from "@/components/templates/AppLayout.svelte";
    import { supportsMediaRecorder } from "@/lib/capture/camera";
    import { createIdbPendingStore } from "@/lib/capture/idb";
    import { generateClientTakeId, UploadQueue } from "@/lib/capture/upload-queue";
    import type { PendingStore } from "@/lib/capture/upload-queue";
    import type { SharedProps } from "@/lib/shared-props";
    import type { CaptureManualDetail } from "@/types/capture";

    /**
     * 撮影ナビ (doc/05 / 概念設計 D9)。cut を選び、録画 (または ファイル選択) →
     * 即時アップロード (upload-url → S3 PUT → POST takes)。失敗/オフラインは IndexedDB に
     * 一時保持し、フォアグラウンド復帰 / online / SW message で再送する。
     */
    interface Props {
        project: { id: number; name: string };
        manual: CaptureManualDetail;
    }

    let { project, manual }: Props = $props();

    const shared = $derived(page.props as unknown as SharedProps);
    const appName = $derived(shared.appName ?? "");

    let selectedCutId = $state<number | null>(null);
    const selectedCut = $derived(manual.cuts.find((cut) => cut.id === selectedCutId) ?? null);
    const canRecord = typeof window !== "undefined" && supportsMediaRecorder();

    /* ---- アップロードキュー ---- */
    const store: PendingStore = createIdbPendingStore();
    const queue = new UploadQueue({ store });
    let pendingCount = $state(0);
    let pendingBytes = $state(0);
    let uploading = $state(false);
    let quotaMessage = $state<string | null>(null);

    async function refreshPending(): Promise<void> {
        const items = await store.list();
        pendingCount = items.length;
        pendingBytes = items.reduce((sum, item) => sum + item.blob.size, 0);
        quotaMessage = queue.quotaMessage;
    }

    function reloadManual(): void {
        router.reload({ only: ["manual"] });
    }

    async function handleCaptured(blob: Blob, mimeType: string, durationMs: number | null): Promise<void> {
        if (selectedCutId === null) return;
        uploading = true;
        try {
            const outcome = await queue.enqueue({
                clientTakeId: generateClientTakeId(),
                projectId: project.id,
                manualId: manual.id,
                cutId: selectedCutId,
                blob,
                contentType: mimeType.split(";")[0],
                durationMs,
                capturedAt: new Date().toISOString(),
            });
            if (outcome.status === "uploaded") {
                reloadManual();
            }
        } finally {
            uploading = false;
            await refreshPending();
        }
    }

    async function resumeUploads(): Promise<void> {
        uploading = true;
        try {
            const outcomes = await queue.resume();
            if (outcomes.some((outcome) => outcome.status === "uploaded")) {
                reloadManual();
            }
        } finally {
            uploading = false;
            await refreshPending();
        }
    }

    onMount(() => {
        void refreshPending();

        // SW 登録 (Capture ページ mount 時に限定。素の JS・/build/* のみキャッシュ)
        if ("serviceWorker" in navigator) {
            void navigator.serviceWorker.register("/capture-sw.js");
            navigator.serviceWorker.addEventListener("message", handleSwMessage);
        }
        // フォアグラウンド復帰 / online でキュー再開 (Background Sync 非依存。概念設計 D9)
        document.addEventListener("visibilitychange", handleVisibility);
        window.addEventListener("online", handleOnline);

        return () => {
            document.removeEventListener("visibilitychange", handleVisibility);
            window.removeEventListener("online", handleOnline);
            if ("serviceWorker" in navigator) {
                navigator.serviceWorker.removeEventListener("message", handleSwMessage);
            }
        };
    });

    function handleVisibility(): void {
        if (document.visibilityState === "visible") void resumeUploads();
    }

    function handleOnline(): void {
        void resumeUploads();
    }

    function handleSwMessage(event: MessageEvent): void {
        if (event.data === "resume-uploads") void resumeUploads();
    }
</script>

<AppLayout {appName}>
    <p class="text-caption text-text-secondary">
        <TextLink href={`/app/projects/${project.id}/manuals`}>
            <ArrowLeft class="inline size-3" aria-hidden="true" />
            一覧へ戻る
        </TextLink>
    </p>
    <h1 class="mt-1 truncate text-h2" data-testid="capture-manual-title">{manual.title}</h1>

    <div class="mt-3">
        <UploadQueueBar {pendingCount} {pendingBytes} {uploading} {quotaMessage} onResume={resumeUploads} />
    </div>

    <div class="mt-4 grid gap-4 lg:grid-cols-2">
        <section class="rounded-md border border-border bg-surface">
            <h2 class="border-b border-border px-3 py-2 text-caption text-text-secondary">
                シナリオ (タップして撮影)
            </h2>
            <CutNavigator
                cuts={manual.cuts}
                {selectedCutId}
                onSelect={(cutId) => (selectedCutId = cutId)}
            />
        </section>

        <section class="flex flex-col gap-4">
            {#if selectedCut === null}
                <p class="text-caption text-text-secondary">
                    左のシナリオからカットを選ぶと撮影パネルが開きます。
                </p>
            {:else}
                <div class="rounded-md border border-border bg-surface p-3">
                    <p class="text-caption text-text-secondary">ナレーション</p>
                    <p class="mt-1 text-body">{selectedCut.narration}</p>
                    {#if selectedCut.shooting_point}
                        <p class="mt-2 text-caption text-text-secondary">
                            撮影ポイント: {selectedCut.shooting_point}
                        </p>
                    {/if}
                </div>

                {#if canRecord}
                    <CameraRecorder
                        onCaptured={(blob, mimeType, durationMs) =>
                            handleCaptured(blob, mimeType, durationMs)}
                    />
                {:else}
                    <CaptureFileFallback
                        onCaptured={(file) => handleCaptured(file, file.type, null)}
                    />
                {/if}

                <TakeStrip
                    projectId={project.id}
                    manualId={manual.id}
                    cut={selectedCut}
                    onChanged={reloadManual}
                />
            {/if}
        </section>
    </div>
</AppLayout>
```

### resources/js/components/features/capture/CameraRecorder.svelte (現行・全文)
```svelte
<script lang="ts">
    import { onDestroy } from "svelte";
    import { Circle, Square } from "@lucide/svelte";
    import Button from "@/components/atoms/Button.svelte";
    import { preferredRecordingMimeType } from "@/lib/capture/camera";

    /**
     * MediaRecorder による録画 (概念設計 D9)。停止時に blob を親へ渡す。
     * カメラ不許可・録画失敗は押下時にエラー表示する (disabled 禁止)。
     */
    interface Props {
        onCaptured: (blob: Blob, mimeType: string, durationMs: number) => void;
    }

    let { onCaptured }: Props = $props();

    let video: HTMLVideoElement | null = $state(null);
    let stream: MediaStream | null = null;
    let recorder: MediaRecorder | null = null;
    let chunks: Blob[] = [];
    let startedAt = 0;
    let recording = $state(false);
    let error = $state<string | null>(null);

    async function startRecording(): Promise<void> {
        error = null;
        const mimeType = preferredRecordingMimeType();
        if (mimeType === null) {
            error = "この端末では録画できません。ファイル選択をご利用ください。";
            return;
        }
        try {
            stream ??= await navigator.mediaDevices.getUserMedia({
                video: { facingMode: "environment" },
                audio: true,
            });
        } catch {
            error = "カメラを利用できません。ブラウザのカメラ許可を確認してください。";
            return;
        }
        if (video) {
            video.srcObject = stream;
            await video.play().catch(() => undefined);
        }
        chunks = [];
        recorder = new MediaRecorder(stream, { mimeType });
        recorder.ondataavailable = (event) => {
            if (event.data.size > 0) chunks.push(event.data);
        };
        recorder.onstop = () => {
            const blob = new Blob(chunks, { type: mimeType });
            const durationMs = Date.now() - startedAt;
            recording = false;
            if (blob.size > 0) {
                onCaptured(blob, mimeType, durationMs);
            }
        };
        startedAt = Date.now();
        recorder.start();
        recording = true;
    }

    function stopRecording(): void {
        recorder?.stop();
    }

    function releaseCamera(): void {
        stream?.getTracks().forEach((track) => track.stop());
        stream = null;
    }

    onDestroy(releaseCamera);
</script>

<div class="flex flex-col gap-3">
    <!-- svelte-ignore a11y_media_has_caption -->
    <video
        bind:this={video}
        autoplay
        playsinline
        muted
        class="aspect-video w-full rounded-md bg-surface object-cover"
        data-testid="camera-preview"
    ></video>
    <div class="flex items-center justify-center gap-3">
        {#if recording}
            <Button variant="danger" onclick={stopRecording} testId="stop-recording">
                <Square class="size-4" aria-hidden="true" />
                録画停止
            </Button>
        {:else}
            <Button variant="primary" onclick={startRecording} testId="start-recording">
                <Circle class="size-4" aria-hidden="true" />
                録画開始
            </Button>
        {/if}
    </div>
    {#if error}
        <p class="text-center text-caption text-danger" role="alert">{error}</p>
    {/if}
</div>
```

### resources/js/components/features/capture/CaptureFileFallback.svelte (現行・全文)
```svelte
<script lang="ts">
    import { Video } from "@lucide/svelte";
    import Button from "@/components/atoms/Button.svelte";

    /**
     * MediaRecorder 非対応環境 (iOS Safari 等) のフォールバック撮影 (概念設計 D9)。
     * OS ネイティブのカメラ/ファイル選択を capture 属性で起動する。
     */
    interface Props {
        onCaptured: (file: File) => void;
    }

    let { onCaptured }: Props = $props();
    let input: HTMLInputElement | null = $state(null);
    let error = $state<string | null>(null);

    function handleChange(): void {
        error = null;
        const file = input?.files?.[0];
        if (!file) return;
        if (!file.type.startsWith("video/")) {
            error = "動画ファイルを選択してください。";
            return;
        }
        onCaptured(file);
        if (input) input.value = "";
    }
</script>

<div class="flex flex-col items-center gap-3 py-6">
    <input
        bind:this={input}
        type="file"
        accept="video/*"
        capture="environment"
        class="hidden"
        onchange={handleChange}
        data-testid="capture-file-input"
    />
    <Button variant="primary" onclick={() => input?.click()} testId="capture-file-button">
        <Video class="size-5" aria-hidden="true" />
        カメラで撮影 / 動画を選択
    </Button>
    <p class="text-caption text-text-secondary">
        この端末ではカメラアプリで撮影し、動画を選択してアップロードします。
    </p>
    {#if error}
        <p class="text-caption text-danger" role="alert">{error}</p>
    {/if}
</div>
```

### resources/js/lib/capture/camera.ts (現行・全文)
```ts
/**
 * カメラ対応判定 (doc/10 §10.8-3: MediaRecorder 非対応環境では
 * <input type="file" capture> フォールバックを必ず提供する)。
 */
export function supportsMediaRecorder(): boolean {
    return (
        typeof window.MediaRecorder !== "undefined" &&
        typeof navigator.mediaDevices?.getUserMedia === "function" &&
        ["video/mp4", "video/webm"].some(
            (type) => window.MediaRecorder.isTypeSupported?.(type) ?? false,
        )
    );
}

/** 録画に使う MIME type (mp4 優先。どちらも不可なら null) */
export function preferredRecordingMimeType(): string | null {
    if (typeof window.MediaRecorder === "undefined") return null;
    for (const type of ["video/mp4", "video/webm"]) {
        if (window.MediaRecorder.isTypeSupported?.(type)) return type;
    }
    return null;
}
```
