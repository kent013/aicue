# アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。「思考ゼロ・編集ゼロ」。**v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA / 動画合成は自前 ffmpeg / 単一 Default Project。

# 禁止事項

1. テストなしの実装完了報告
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作
4. `response()->json()` の直書き(DTO / JsonResource / Inertia)
5. LLM 呼び出しの Prism 直呼び
6. prompt 文字列のコード直書き
7. 操作系 POST 応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示。DESIGN.md)

【思考原則】まず仮説を立てろ。データに真摯に向き合え。先人の知恵を探せ。機能の名前に立ち返れ。仕組みが機能していない段階で値を弄るな。
【ツール使用制限】コマンド実行・ファイル書き込みは一切行わず、提供テキストの分析に集中（ファイル読み込みは許可）。

---

あなたは経験豊富なWebアプリケーションアーキテクトです。Laravel + Svelte アプリケーション改善の詳細設計をレビューしてください。

【前提環境】
- PHP 8.4 + Laravel 12 + Svelte 5 (runes) + Inertia.js + TypeScript
- PHPStan level 10 / Pest / vitest
- DS token（DESIGN.md canonical / tokens.css）+ ds-purity 静的走査（raw palette 色・hex 直書き・box-shadow・任意 z-index・静的 inline style・raw text-size・素/任意 rounded を禁止）
- component 階層: atoms → molecules → organisms → features/{domain} → templates → pages の単方向 import。アイコンは @lucide/svelte のみ

【本設計の性質】
- **frontend のみ**の変更。PHP / DTO / JsonResource / API / Inertia props スキーマは無変更。字幕データ `subtitle_primary: string | null` / `subtitle_secondary: string` は既存 `CaptureCut` 型・props に既に存在。
- overlay は撮影ガイド表示であり焼込ではない（MediaRecorder が録る MediaStream に含まれない）。焼込は既存 `AssSubtitleWriter` の責務で無変更。
- 概念設計は Codex(gpt-5.4) レビューで APPROVED 済み。

【レビュー観点】
1. コードの正確性（ロジックエラー、エッジケース、null 安全性、Svelte 5 runes の使い方）
2. 既存コードとの整合性（命名規約、パターン、先例 PasswordInput の踏襲）
3. TypeScript 型安全性（indexed access 型、null 安全、props 既定値の後方互換）
4. テスト計画の網羅性（vitest。test-first。既存テスト非破壊）
5. DS token / ds-purity 適合（`bg-text/70`・`text-surface`・`max-w-[90%]`・`line-clamp-2/3`・`rounded-sm` が静的走査を通るか）
6. Atomic Design / import graph 適合（features/capture 配置、raw button の是非）
7. アクセシビリティ（icon-only トグルの `aria-pressed` + 状態連動 `aria-label`、`pointer-events-none`）
8. 副作用・後退リスク（既存録画フローへの非干渉、既存テストの後方互換）
9. 波及変更の網羅性（型・呼び出し元・テストが変更対象に含まれているか）
10. 表示契約の妥当性（長文・多数改行で中央領域を侵食しないか、primary/secondary の重なり防止、trim を空判定のみに使い描画は元文字列）

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

---

## 詳細設計書

（下記は detailed-design.md の全文）

# 詳細設計: capture-subtitle-overlay（撮影中カメラプレビューへの字幕オーバーレイ表示）

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

**v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

### 禁止事項

1. テストなしの実装完了報告
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(factory 経由のみ)
6. prompt 文字列のコード直書き
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)

### コーディングルール

- **PHPStan level 10** 必須 — 本件は **frontend のみ**の変更で PHP コードに触れないため PHP 型影響なし（後述）。
- **Pest** — 本件は PHP テスト追加なし。frontend は **vitest**（`pnpm test`）で担保。
- フロントは **Svelte 5 runes** + **DS token/ramp のみ**（`DESIGN.md` canonical、ds-purity テストが検出）。
- component 階層は `atoms → molecules → organisms → features/{domain} → templates → pages` の単方向 import のみ。アイコンは `@lucide/svelte` のみ。
- コードフォーマット: `pnpm lint:fix`。
- 検証コマンド: `pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm build`（全 green でコミット）。

## 概念設計リファレンス

- [conceptual-design.md](./conceptual-design.md)（概念レビュー APPROVED, Round 3）

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| S1 | 字幕オーバーレイ表示コンポーネント新設 | `resources/js/components/features/capture/SubtitleOverlay.svelte`（新規） | High |
| S2 | CameraRecorder に字幕レイヤー + トグルを組込 | `resources/js/components/features/capture/CameraRecorder.svelte` | High |
| S3 | Capture/Show から selectedCut の字幕を配線 | `resources/js/pages/Capture/Show.svelte` | High |
| S4 | SubtitleOverlay の単体テスト（新規） | `tests/js/components/features/capture/SubtitleOverlay.test.ts`（新規） | High |
| S5 | CameraRecorder のトグル配線テスト（追記） | `tests/js/components/features/capture/CameraRecorder.test.ts` | High |

**設計不変条件**: overlay は DOM の別レイヤーであり `MediaRecorder` が録る `MediaStream` には含まれない = **焼込にならない**（撮影ガイド overlay の目的を逸脱しない）。バックエンド・DTO・API・Inertia props スキーマは一切変更しない。

---

## S1. 字幕オーバーレイ表示コンポーネント（新規 `SubtitleOverlay.svelte`）

### 変更箇所
- 新規ファイル: `resources/js/components/features/capture/SubtitleOverlay.svelte`

### 波及変更
- TypeScript 型定義: 既存 `resources/js/types/capture.ts` の `CaptureCut` を **参照するのみ**（indexed access `CaptureCut["subtitle_primary"]` / `["subtitle_secondary"]`）。型の新規追加・変更なし。
- API Resource/DTO: なし（frontend 表示のみ）。
- テストファイル: S4（新規テスト）。

### 設計（Svelte 5 runes・presentational・無状態）

Props:
- `primary: CaptureCut["subtitle_primary"]`（= `string | null`。ASS: 上部帯 = 名称・数値）
- `secondary: CaptureCut["subtitle_secondary"]`（= `string`。ASS: 下部メイン字幕）
- `visible: boolean`（親のトグル状態）

表示規則（概念設計「表示レイアウト契約」準拠）:
- 空判定は `trim()` 後で行う（`primary` は null/空/空白のみ → 空扱い、`secondary` も同様）。
- `visible === false`、または primary/secondary が両方空なら**何も描画しない**。
- overlay コンテナ `pointer-events-none absolute inset-0 flex flex-col justify-between p-3`（`p-3` がプレビュー端 inset。ASS MarginV 相当）。
- 上端スロット（primary）/ 下端スロット（secondary）を**常に 2 スロット構造**で置き、`justify-between` で上下に固定 → 片方のみでも位置が動かず、両帯は構造的に重ならない（中央領域は常に空く）。
- 帯: `max-w-[90%] bg-text/70 px-3 py-1 text-center text-body text-surface whitespace-pre-line`。primary は `line-clamp-2`、secondary は `line-clamp-3`（超過は省略記号 → 長文・多数改行でも高さ上限化）。
- **描画は元文字列**（`trim()` した値では描画しない。内容を書き換えない）。
- testid: overlay ルート `subtitle-overlay`、primary 帯 `subtitle-primary`、secondary 帯 `subtitle-secondary`。

### 実装コード（新規）

```svelte
<script lang="ts">
    import type { CaptureCut } from "@/types/capture";

    /**
     * 撮影中カメラプレビューへ重畳する字幕ガイド (doc/05 §5.2 の字幕重畳要件)。
     * 焼込ではなく撮影ガイド overlay: MediaRecorder が録る MediaStream には含まれない。
     * primary=上部帯 (名称・数値) / secondary=下部メイン。位置は AssSubtitleWriter (ASS) と一致。
     * 位置・占有領域の確認用であり全文確認用ではない (長文は line-clamp で省略)。
     */
    interface Props {
        primary: CaptureCut["subtitle_primary"];
        secondary: CaptureCut["subtitle_secondary"];
        visible: boolean;
    }

    let { primary, secondary, visible }: Props = $props();

    // trim は「空判定」のみに使う。描画には元文字列をそのまま使う (内容を書き換えない)。
    const hasPrimary = $derived((primary ?? "").trim() !== "");
    const hasSecondary = $derived(secondary.trim() !== "");
    const shown = $derived(visible && (hasPrimary || hasSecondary));
</script>

{#if shown}
    <div
        class="pointer-events-none absolute inset-0 flex flex-col justify-between p-3"
        data-testid="subtitle-overlay"
    >
        <div class="flex justify-center">
            {#if hasPrimary}
                <p
                    class="line-clamp-2 max-w-[90%] whitespace-pre-line rounded-sm bg-text/70 px-3 py-1 text-center text-body text-surface"
                    data-testid="subtitle-primary"
                >
                    {primary}
                </p>
            {/if}
        </div>
        <div class="flex justify-center">
            {#if hasSecondary}
                <p
                    class="line-clamp-3 max-w-[90%] whitespace-pre-line rounded-sm bg-text/70 px-3 py-1 text-center text-body text-surface"
                    data-testid="subtitle-secondary"
                >
                    {secondary}
                </p>
            {/if}
        </div>
    </div>
{/if}
```

### DS 適合チェック（ds-purity）
- [x] raw palette 色なし（`bg-text/70`・`text-surface` は DS token。opacity modifier は許容 = Modal overlay の `bg-text/50` に前例）
- [x] hex 直書きなし
- [x] box-shadow なし（帯 `bg-text/70` でコントラスト担保）
- [x] 任意 z-index なし（overlay は同一 stacking 内の後続要素 + `absolute`。z-ramp 不要。必要時のみ `z-10`）
- [x] 静的 inline style なし（`max-w-[90%]` は Tailwind 任意値 utility であり `style=""` ではない。ds-purity の禁止 arbitrary は hex/z/rounded/text のみで max-w は対象外）
- [x] typography は ramp（`text-body`）
- [x] rounded は `rounded-sm`（3 段 ramp 内）

### リスク
- **中央領域侵食**: `line-clamp-2/3` + `justify-between` の 2 スロット構造で上下帯の合計最大 5 行に上限化。極小プレビューでも中央が空く前提は S2 の最小寸法確認（下記）で担保。
- **line-clamp 未対応ブラウザ**: `-webkit-line-clamp` は主要モバイルブラウザで広くサポート。非対応時は clamp が効かず伸びるが、`whitespace-pre-line` の折返しと `max-w` は効くため致命的破綻はしない（ガイド用途として許容）。

---

## S2. CameraRecorder に字幕レイヤー + トグルを組込（`CameraRecorder.svelte`）

### 変更箇所
- `resources/js/components/features/capture/CameraRecorder.svelte`

### 波及変更
- TypeScript 型定義: Props に `subtitlePrimary` / `subtitleSecondary` を追加（型は `CaptureCut` の indexed access）。**既定値付き**（`subtitlePrimary = null`, `subtitleSecondary = ""`）にするため、既存の呼び出し（テスト含む）は**変更不要**で後方互換。
- API Resource/DTO: なし。
- テストファイル: S5（追記）。既存テストは既定値により壊れない（削除・上書きなし）。

### 現行コード（抜粋）

```svelte
interface Props {
    onCaptured: (blob: Blob, mimeType: string, durationMs: number) => void;
    onCameraUnavailable: (reason: CameraUnavailableReason) => void;
}
let { onCaptured, onCameraUnavailable }: Props = $props();
```
```svelte
<div class="flex flex-col gap-3">
    <!-- svelte-ignore a11y_media_has_caption -->
    <video bind:this={video} autoplay playsinline muted
        class="aspect-video w-full rounded-md bg-surface object-cover"
        data-testid="camera-preview"></video>
    <div class="flex items-center justify-center gap-3">
        {#if recording}
            <Button variant="danger" onclick={stopRecording} testId="stop-recording">…</Button>
        {:else}
            <Button variant="primary" onclick={startRecording} testId="start-recording">…</Button>
        {/if}
    </div>
    {#if error}…{/if}
</div>
```

### 変更後コード

script 追加（import・props・トグル状態。既存の録画ロジックは一切変更しない）:

```svelte
import { Captions, CaptionsOff, Circle, Square } from "@lucide/svelte";
import SubtitleOverlay from "@/components/features/capture/SubtitleOverlay.svelte";
import type { CaptureCut } from "@/types/capture";

interface Props {
    onCaptured: (blob: Blob, mimeType: string, durationMs: number) => void;
    onCameraUnavailable: (reason: CameraUnavailableReason) => void;
    /** 選択中カットの字幕 (撮影ガイド overlay 用。焼込ではない)。既定は空 (字幕なし) */
    subtitlePrimary?: CaptureCut["subtitle_primary"];
    subtitleSecondary?: CaptureCut["subtitle_secondary"];
}

let {
    onCaptured,
    onCameraUnavailable,
    subtitlePrimary = null,
    subtitleSecondary = "",
}: Props = $props();

// 字幕オーバーレイの表示トグル (doc/05 §5.2)。v1 中核価値が字幕のため既定 ON。
let showSubtitles = $state(true);
const subtitleToggleLabel = $derived(showSubtitles ? "字幕を非表示" : "字幕を表示");
```

template（video を relative コンテナで包み overlay を重ねる。コントロール行右に字幕トグル）:

```svelte
<div class="flex flex-col gap-3">
    <div class="relative">
        <!-- svelte-ignore a11y_media_has_caption -->
        <video bind:this={video} autoplay playsinline muted
            class="aspect-video w-full rounded-md bg-surface object-cover"
            data-testid="camera-preview"></video>
        <SubtitleOverlay
            primary={subtitlePrimary}
            secondary={subtitleSecondary}
            visible={showSubtitles}
        />
    </div>
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
        <!-- 字幕トグル (録画ボタン右)。二値の pressed 状態は raw button + aria-pressed で表現
             (先例: molecules/PasswordInput.svelte)。字幕が空でも disabled にしない (禁止事項 8) -->
        <button
            type="button"
            class="flex items-center rounded-sm p-2 text-text-secondary transition-colors duration-150 hover:text-text focus-visible:ring-3 focus-visible:ring-primary/35 focus-visible:outline-none"
            aria-label={subtitleToggleLabel}
            aria-pressed={showSubtitles}
            onclick={() => (showSubtitles = !showSubtitles)}
            data-testid="toggle-subtitles"
        >
            {#if showSubtitles}
                <Captions class="size-5" aria-hidden="true" />
            {:else}
                <CaptionsOff class="size-5" aria-hidden="true" />
            {/if}
        </button>
    </div>
    {#if error}
        <p class="text-center text-caption text-danger" role="alert">{error}</p>
    {/if}
</div>
```

### DS 適合チェック
- [x] トグルボタンの class は PasswordInput と同一の DS token 系（`text-text-secondary`/`hover:text-text`/`focus-visible:ring-3 ring-primary/35`/`rounded-sm`/`p-2`）
- [x] アイコンは `@lucide/svelte`（`Captions`/`CaptionsOff`。実在確認済み）
- [x] disabled ガードなし（禁止事項 8 遵守）
- [x] 既存録画ロジック（getUserMedia/MediaRecorder/再入ガード/失敗ハンドリング）は無改変

### 最小プレビュー寸法の確認（概念レビュー Suggestion 対応）
- カード内 `aspect-video`。モバイル最小幅想定 ~320px → 高さ ~180px。`p-3`(12px×2) を除く ~156px に primary(line-clamp-2, `text-body` 行高 ~1.5em) + secondary(line-clamp-3) 合計最大 ~5 行が上下に分かれて配置され、中央が空くことを `verify` / 手動（実ブラウザ or 視覚確認）で確認する。

### リスク
- 既存 CameraRecorder テストは props 既定値により無変更で通る（後方互換）。overlay は録画状態と独立で録画フローに副作用なし。

---

## S3. Capture/Show から字幕を配線（`Capture/Show.svelte`）

### 変更箇所
- `resources/js/pages/Capture/Show.svelte`（`<CameraRecorder>` 呼び出し L181-186 付近）

### 波及変更
- TypeScript 型定義: なし（`selectedCut` は既に `CaptureCut | null` 派生。`showRecorder` 分岐は `selectedCut !== null` の内側）。
- API/DTO: なし。
- テストファイル: 既存の Capture 系 Feature/JS テストへの影響なし（props 追加のみ）。

### 現行コード

```svelte
{#if showRecorder}
    <CameraRecorder
        onCaptured={(blob, mimeType, durationMs) =>
            handleCaptured(blob, mimeType, durationMs)}
        onCameraUnavailable={(reason) => (cameraUnavailableReason = reason)}
    />
{:else}
```

### 変更後コード

```svelte
{#if showRecorder}
    <CameraRecorder
        onCaptured={(blob, mimeType, durationMs) =>
            handleCaptured(blob, mimeType, durationMs)}
        onCameraUnavailable={(reason) => (cameraUnavailableReason = reason)}
        subtitlePrimary={selectedCut.subtitle_primary}
        subtitleSecondary={selectedCut.subtitle_secondary}
    />
{:else}
```

- この分岐は `{:else}`（`selectedCut === null` でない）配下のため `selectedCut` は non-null（現行 L170 の `{#if selectedCut === null}…{:else}` 構造に従う）。ファイル選択フォールバック側には overlay を渡さない（ライブプレビューが無いためスコープ外）。

### PHPStan 適合チェック
- N/A（PHP 変更なし）。TypeScript: `selectedCut.subtitle_primary`(string|null) / `subtitle_secondary`(string) は `CameraRecorder` の Props 型と一致。

### リスク
- なし（純粋な props 追加）。

---

## S4. SubtitleOverlay 単体テスト（新規 `SubtitleOverlay.test.ts`）

### 変更箇所
- 新規: `tests/js/components/features/capture/SubtitleOverlay.test.ts`

### テスト計画（test-first: 先に fail を確認してから S1 実装）
- [ ] `visible=true` + primary/secondary あり → `subtitle-overlay` / `subtitle-primary` / `subtitle-secondary` が表示される
- [ ] `visible=false` → overlay 非表示（`queryByTestId("subtitle-overlay")` が null）
- [ ] primary=null かつ secondary="" → 非表示
- [ ] primary/secondary が**空白のみ**（`"   "`, `"\n"`）→ trim 後空扱いで非表示
- [ ] primary のみ（secondary=""）→ `subtitle-primary` のみ表示、`subtitle-secondary` は非存在
- [ ] secondary のみ（primary=null）→ `subtitle-secondary` のみ表示
- [ ] **長文 JP + 多数改行**を primary/secondary 同時に与えても両要素が別々に存在し、`line-clamp-2`/`line-clamp-3` class が付与される（中央侵食しない構造の担保）
- [ ] **描画文字列が trim で書き換えられない**: 前後空白を含む文字列を与え、表示テキストが元のまま（`textContent` に前後空白が保持される。空判定にのみ trim を使う）
- [ ] 位置構造: overlay ルートが `flex-col justify-between` を持ち、primary が先頭スロット・secondary が末尾スロットに出る

### 個別 `DatabaseTransactions` 不使用: N/A（frontend テスト、DB 非依存）

---

## S5. CameraRecorder トグル配線テスト（追記 `CameraRecorder.test.ts`）

### 変更箇所
- `tests/js/components/features/capture/CameraRecorder.test.ts`（**追記のみ**。既存 8 ケースは削除・改変しない）

### テスト計画
- [ ] 既定（props 省略）で render → 既存テストが無変更で通る（後方互換の確認）
- [ ] `subtitlePrimary`/`subtitleSecondary` を渡し、既定 `showSubtitles=true` で `subtitle-overlay` が表示される
- [ ] `toggle-subtitles` ボタンをクリック → overlay が非表示になり、`aria-pressed="false"`・`aria-label="字幕を表示"`・`CaptionsOff` アイコンへ切替
- [ ] 再クリック → overlay 再表示・`aria-pressed="true"`・`aria-label="字幕を非表示"`
- [ ] 字幕が空でもトグルボタンは `disabled` 属性を持たない（禁止事項 8）

### 既存テストへの配慮
- 既存の `getUserMediaMock` / `FakeMediaRecorder` stub はそのまま使う。overlay 検証は録画状態に依存しないため、`render` 直後（録画開始前）に検証可能。

---

## 使命・禁止事項チェック（最終）

- **使命寄与**: 撮影者が字幕の占有領域を撮影時に把握でき、「思考ゼロ・編集ゼロ」で標準化動画を作る撮影判断を支援（doc/05 §5.2 字幕重畳要件を満たす）。
- **禁止事項**: (2) PHPStan 非該当（PHP 無変更）/ (8) disabled ガードなし ✅。テストなし完了なし（S4/S5）。既存テスト削除・上書きなし。
- **DS/Atomic**: DS token/ramp のみ、`features/capture` 配置、`@lucide/svelte` アイコン、raw button は先例（PasswordInput）準拠。

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | incremental |
| 判断根拠 | 既存 `CameraRecorder.svelte` / `Capture/Show.svelte` への追記的変更が中心で、他機能と独立。新規 `SubtitleOverlay.svelte` も孤立コンポーネント。段階的に S1(+S4)→S2(+S5)→S3 の順で積める |
| 競合リスク | 低。撮影フロー本体（録画・アップロードキュー）に触れず表示レイヤーのみ追加。ScenarioWritePath 等のバックエンド不変条件に無関係 |


---

## 関連する現行コード

### resources/js/components/features/capture/CameraRecorder.svelte（現行）

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
    /** 開始処理中の再入ガード (getUserMedia 待ち中の多重クリック防止。UI disabled は使わない) */
    let starting = false;

    async function startRecording(): Promise<void> {
        if (starting || recording) return; // 再入防止 (アーリーリターン。規約: disabled 禁止)
        starting = true;
        try {
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
            try {
                recorder.start();
            } catch {
                // start() の InvalidStateError 等 (UA 差異・状態競合)。構築成功後でも
                // 詰ませないため stream を解放してフォールバックへ倒す (§10.8-3)
                recorder = null;
                releaseCamera();
                onCameraUnavailable("recorder_unsupported");
                return;
            }
            recording = true;
        } finally {
            starting = false;
        }
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


### resources/js/pages/Capture/Show.svelte（現行・抜粋 L165-211）

        <section class="flex min-w-0 flex-col gap-4" data-testid="capture-right-pane">
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

### resources/js/types/capture.ts（CaptureCut 定義）

/**
 * 撮影 PWA の型定義。PHP 側 App\DataTransferObjects\Capture\* と対で保守する
 * (キー集合の契約は tests/Feature/Capture/CaptureManualBrowsingTest が固定する)。
 */

export type TakeStatus = "uploading" | "processing" | "ready" | "failed";

export interface CaptureTake {
    id: number;
    client_take_id: string;
    status: TakeStatus;
    size_bytes: number;
    duration_ms: number | null;
    comment: string | null;
    captured_at: string | null;
    sort_order: number;
    downloaded: boolean;
    /** 採用テイクのみ非 null (doc/10 §10.3) */
    playback_url: string | null;
    /** 採用テイクのみ非 null。DL 完了時に POST .../downloaded へ送る署名 ACK トークン (D6) */
    download_ack_token: string | null;
}

export interface CaptureCut {
    id: number;
    type: "step" | "point";
    parent_cut_id: number | null;
    scene: string;
    shot_type: "hiki" | "yori";
    shooting_point: string | null;
    narration: string;
    subtitle_primary: string | null;
    subtitle_secondary: string;
    adopted_take_id: number | null;
    takes: CaptureTake[];
}

export interface CaptureManualDetail {
    id: number;
    title: string;
    status: string;
    cuts: CaptureCut[];
}

export interface CaptureManualSummary {
    id: number;
    title: string;
    status: string;
    category_id: number | null;
    category_name: string | null;
    cuts_total: number;
    cuts_adopted: number;
    cuts_with_takes: number;
    updated_at: string | null;
}

/** POST .../takes/upload-url の応答 (TakeUploadTicketResource と対) */
export interface UploadTicket {
    upload_url: string;
    headers: Record<string, string>;
    ticket: string;
    client_take_id: string;
    expires_at: string;
}

/** POST .../sync の応答 (CaptureSyncResultResource と対) */
export interface SyncResult {
    pending_upload: { cut: number; client_take_id: string }[];
    manual: CaptureManualDetail;
}

/** 422 quota 超過ボディ (QuotaExceededResource と対) */
export interface QuotaExceededBody {
    code: "quota_exceeded";
    message: string;
}

/** 409 登録競合ボディ (CaptureConflictResource と対) */
export interface CaptureConflictBody {
    code: "capture_conflict";
    conflict_type: "registration_in_flight" | "reservation_inconsistent";
    message: string;
}


### 先例: resources/js/components/molecules/PasswordInput.svelte（raw button + aria-pressed トグルの前例）

<script lang="ts">
    import type { HTMLInputAttributes } from "svelte/elements";
    import { Eye, EyeOff } from "@lucide/svelte";
    import Input from "@/components/atoms/Input.svelte";

    /**
     * パスワード入力 molecule。Input atom を右端の Eye/EyeOff トグルと合成し、
     * `password` ↔ `text` を即時切替する。表示/非表示は即時反映の二値状態なので
     * button トグル (aria-pressed) で表現する。
     *
     * FormField の配線 (label の for / error / aria-describedby) は呼び出し側の
     * FormField が担い、本 molecule は id / aria-describedby 等を Input へ透過する。
     * トグルボタンを対象 input に aria-controls で結線するため id は必須 prop。
     */
    interface Props
        extends Omit<HTMLInputAttributes, "type" | "value" | "class" | "id" | "disabled"> {
        /** 入力要素の id (必須)。トグルボタンの aria-controls にも結線する */
        id: string;
        value?: string;
        /** エラー状態 (FormField の invalid を渡す) */
        error?: boolean;
        disabled?: boolean;
        /** 最外 wrapper への追加 class */
        class?: string;
        testId?: string;
    }

    let {
        id,
        value = $bindable(""),
        error = false,
        disabled = false,
        class: extraClass = "",
        testId,
        ...rest
    }: Props = $props();

    let visible = $state(false);

    const inputType = $derived(visible ? "text" : "password");
    const toggleLabel = $derived(visible ? "パスワードを非表示" : "パスワードを表示");
    const wrapperClass = $derived(["relative", extraClass].filter(Boolean).join(" "));
</script>

<div class={wrapperClass}>
    <!-- 右端トグル分の余白を pr-10 で確保する (Input 基底の px-3 より後勝ち) -->
    <Input {...rest} {id} type={inputType} bind:value {error} {disabled} {testId} class="pr-10" />
    <button
        type="button"
        class="absolute inset-y-0 right-0 flex items-center px-3 text-text-secondary transition-colors duration-150 hover:text-text focus-visible:ring-3 focus-visible:ring-primary/35 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-40"
        aria-label={toggleLabel}
        aria-pressed={visible}
        aria-controls={id}
        {disabled}
        onclick={() => (visible = !visible)}
    >
        {#if visible}
            <EyeOff class="size-5" aria-hidden="true" />
        {:else}
            <Eye class="size-5" aria-hidden="true" />
        {/if}
    </button>
</div>


### 参考: app/Services/Render/AssSubtitleWriter.php の primary/secondary 位置仕様（docblock 抜粋）

- スタイル: subtitle_secondary = 画面下部 (メイン。Alignment 2 = 下中央)、subtitle_primary = 上部帯 (名称・数値の強調。Alignment 8 = 上中央)。
