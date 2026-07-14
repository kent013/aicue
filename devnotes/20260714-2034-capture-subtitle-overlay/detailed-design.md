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
- overlay ルートには id を付与しない（条件付き描画のため `aria-controls` の IDREF 対象が不在になり得る + 固定 id は複数インスタンスで重複するため。トグルの状態・操作目的は `aria-pressed` + 状態連動 `aria-label` で十分表現する。design-review Round 2 の指摘反映）。
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
    // secondary は型上 string だが将来の props 契約変更に備え防御的に nullish 合体する。
    const hasPrimary = $derived((primary ?? "").trim() !== "");
    const hasSecondary = $derived((secondary ?? "").trim() !== "");
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
- [x] a11y: `aria-pressed`（二値トグル状態）+ 状態連動 `aria-label`（`aria-controls` は使わない — 条件付き描画で IDREF が不在になり得る + 固定 id 重複リスクのため。design-review Round 2）
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
- [ ] **描画文字列が trim で書き換えられない**: 前後空白を含む文字列（例 `"  a  "`）を与え、要素が**描画される**こと + `textContent` が `toContain("a")` を検証（DOM 正規化・レンダラ差異に弱い「空白完全一致」は避け、ノード存在 + 部分一致中心にする）
- [ ] `visible=false` 時は `subtitle-overlay` / `subtitle-primary` / `subtitle-secondary` すべて不在（回帰耐性）
- [ ] 位置構造: overlay ルートが `flex-col justify-between` を持ち、primary が先頭スロット・secondary が末尾スロットに出る

### 個別 `DatabaseTransactions` 不使用: N/A（frontend テスト、DB 非依存）

---

## S5. CameraRecorder トグル配線テスト（追記 `CameraRecorder.test.ts`）

### 変更箇所
- `tests/js/components/features/capture/CameraRecorder.test.ts`（**追記のみ**。既存 8 ケースは削除・改変しない）

### テスト計画
- [ ] 既定（props 省略）で render → 既存テストが無変更で通る（後方互換の確認）
- [ ] `subtitlePrimary`/`subtitleSecondary` を渡し、既定 `showSubtitles=true` で `subtitle-overlay` が表示される
- [ ] `toggle-subtitles` クリック → overlay 非表示 + **主アサーション** `aria-pressed="false"`・`aria-label="字幕を表示"`（アイコン切替は補助的に `CaptionsOff` の存在確認のみ。コンポーネント名依存の脆い検証にしない）
- [ ] 再クリック → overlay 再表示 + `aria-pressed="true"`・`aria-label="字幕を非表示"`
- [ ] 字幕が空（props 省略）でもトグルボタンは `disabled` 属性を持たず、**クリックで状態遷移する**（禁止事項 8 への適合証跡。disabled 不在 + 実クリック遷移を同一ケースで確認）

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
